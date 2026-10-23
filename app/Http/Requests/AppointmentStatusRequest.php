<?php

namespace App\Http\Requests;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Support\ClinicTime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
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

                // Falta é marcada no dia ou depois; antes disso o paciente ainda pode vir.
                if ($next === AppointmentStatus::NoShow && ClinicTime::format($appointment->scheduled_at, 'Y-m-d') > ClinicTime::today()) {
                    $validator->errors()->add('status', 'Falta só pode ser marcada a partir do dia do agendamento. Para desmarcar antes, cancele.');
                }

                if ($next === AppointmentStatus::Scheduled && ! $appointment->isOpen()) {
                    // Reaberto, o agendamento volta a aceitar registro de atendimento e a ocupar
                    // o horário: um de dia que já passou deixaria um registro retroativo em aberto.
                    if (ClinicTime::format($appointment->scheduled_at, 'Y-m-d') < ClinicTime::today()) {
                        $validator->errors()->add('status', 'Agendamento de dia que já passou não pode ser reaberto. Crie um novo agendamento.');
                    }

                    if (! $appointment->professional()->where('is_active', true)->exists()) {
                        $validator->errors()->add('status', 'O profissional deste agendamento está inativo. Crie um novo agendamento com outro profissional.');
                    }
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['status.in' => 'Esta mudança de situação não é permitida.'];
    }
}
