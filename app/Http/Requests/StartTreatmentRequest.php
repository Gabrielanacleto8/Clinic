<?php

namespace App\Http\Requests;

use App\Models\Patient;
use Illuminate\Validation\Rule;

/**
 * Atendimento registrado pelo "Novo atendimento" do Início: o paciente é
 * escolhido no próprio formulário e o registro não parte de um agendamento.
 */
class StartTreatmentRequest extends TreatmentRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'patient_id' => ['required', 'integer', Rule::exists(Patient::class, 'id')->withoutTrashed()],
            ...parent::rules(),
        ];
    }

    /**
     * @return list<mixed>
     */
    protected function appointmentRules(): array
    {
        return ['prohibited'];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [...parent::attributes(), 'patient_id' => 'paciente'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $pickFromList = 'Busque e selecione um paciente na lista.';

        return [
            ...parent::messages(),
            'patient_id.required' => $pickFromList,
            'patient_id.integer' => $pickFromList,
            'patient_id.exists' => $pickFromList,
        ];
    }
}
