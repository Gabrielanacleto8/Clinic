<?php

namespace App\Http\Requests;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Support\ClinicTime;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/**
 * Confirmar presença, cancelar, marcar falta ou reabrir um agendamento.
 */
class AppointmentStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $appointment = $this->appointment();

        // Agendamento de paciente arquivado fica inacessível, como o resto do cadastro.
        abort_unless($appointment->patient()->exists(), 404);

        return $this->user()->can('changeStatus', $appointment);
    }

    private function appointment(): Appointment
    {
        return $this->route('appointment');
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(array_map(
                fn (AppointmentStatus $status) => $status->value,
                $this->appointment()->status->manualTransitions(),
            ))],
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('status')) {
                    return;
                }

                $appointment = $this->appointment();
                $next = AppointmentStatus::from($this->input('status'));

                if ($next === AppointmentStatus::NoShow && ! $appointment->canBeMarkedMissed()) {
                    $validator->errors()->add('status', 'Falta só pode ser marcada a partir do dia do agendamento. Para desmarcar antes, cancele.');
                }

                if ($next === AppointmentStatus::Scheduled && ! $appointment->isOpen()) {
                    $appointment->loadMissing('professional:id,is_active');

                    if ($appointment->localDay() < ClinicTime::today()) {
                        $validator->errors()->add('status', 'Agendamento de dia que já passou não pode ser reaberto. Crie um novo agendamento.');
                    } elseif (! $appointment->canBeReopened()) {
                        $validator->errors()->add('status', 'O profissional deste agendamento está inativo. Crie um novo agendamento com outro profissional.');
                    }
                }
            },
        ];
    }

    /**
     * A lista de agendamentos é longa: o erro vai para o aviso no topo da
     * página, com o horário do agendamento, em vez de ficar no fim da lista.
     */
    protected function failedValidation(ValidatorContract $validator): void
    {
        // Sem nome de paciente na mensagem: o horário e o procedimento bastam para achar a linha.
        $appointment = $this->appointment()->loadMissing('procedure:id,name');
        $where = ClinicTime::format($appointment->scheduled_at, 'd/m H:i').($appointment->procedure ? ', '.$appointment->procedure->name : '');

        throw ValidationException::withMessages(['status' => $validator->errors()->first('status')." ({$where})"])->redirectTo(url()->previous());
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['status.in' => 'Esta mudança de situação não é permitida.'];
    }
}
