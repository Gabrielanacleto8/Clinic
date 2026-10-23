<?php

namespace App\Http\Requests;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\User;
use App\Services\RolePermissions;
use App\Support\ClinicTime;
use App\Support\Duration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Validator;

/**
 * Usado tanto ao agendar quanto ao remarcar.
 */
class AppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $appointment = $this->route('appointment');

        return $appointment instanceof Appointment
            ? $this->user()->can('update', $appointment)
            : $this->user()->can('create', Appointment::class);
    }

    private function isUpdate(): bool
    {
        return $this->route('appointment') instanceof Appointment;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            // O paciente é escolhido só ao agendar; remarcar não troca o paciente.
            'patient_id' => $this->isUpdate()
                ? ['prohibited']
                : ['required', 'integer', Rule::exists(Patient::class, 'id')->withoutTrashed()],
            'professional_id' => [
                'required',
                'integer',
                Rule::exists(User::class, 'id')
                    ->where('is_active', true)
                    ->whereIn('role', array_map(
                        fn (UserRole $role) => $role->value,
                        app(RolePermissions::class)->rolesWith(Permission::RecordsManage),
                    )),
            ],
            'scheduled_at' => ['required', 'date_format:'.ClinicTime::INPUT_FORMAT],
            'duration_minutes' => ['required', 'integer', Rule::in(Duration::OPTIONS)],
            'procedure_id' => ['nullable', 'integer', $this->selectableProcedure()],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Só procedimentos ativos, ou o que o agendamento já usa.
     */
    private function selectableProcedure(): Exists
    {
        $current = $this->route('appointment')?->procedure_id;

        return Rule::exists(Procedure::class, 'id')->where(
            fn ($query) => $query->where(fn ($inner) => $inner->where('is_active', true)->when($current, fn ($q) => $q->orWhere('id', $current))),
        );
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('scheduled_at')) {
                    return;
                }

                $scheduledAt = ClinicTime::fromInput($this->input('scheduled_at'));
                [$todayStart] = ClinicTime::dayBounds(ClinicTime::today());

                // Remarcar sem mudar o horário (só observações, procedimento) vale mesmo
                // para agendamento em aberto de dia que já passou.
                $unchanged = $this->isUpdate()
                    && $this->input('scheduled_at') === ClinicTime::toInput($this->route('appointment')->scheduled_at);

                // Encaixes de hoje são aceitos, mesmo em horário que já passou.
                if ($scheduledAt->isBefore($todayStart) && ! $unchanged) {
                    $validator->errors()->add('scheduled_at', 'Não é possível agendar em uma data que já passou.');
                }

                if ($scheduledAt->isAfter(now()->addYears(2))) {
                    $validator->errors()->add('scheduled_at', 'Informe uma data dentro dos próximos dois anos.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'patient_id' => 'paciente',
            'professional_id' => 'profissional',
            'scheduled_at' => 'data e hora',
            'duration_minutes' => 'duração',
            'procedure_id' => 'procedimento',
            'notes' => 'observações',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'patient_id.required' => 'Busque e selecione um paciente na lista.',
            'patient_id.exists' => 'Busque e selecione um paciente na lista.',
            'scheduled_at.date_format' => 'Informe uma data e hora válidas.',
        ];
    }
}
