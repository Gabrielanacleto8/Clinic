<?php

namespace App\Http\Requests;

use App\Support\ClinicTime;

/**
 * Fotos avulsas, enviadas pela ficha do paciente: além do que vale para as
 * fotos de atendimento, exigem a data em que foram feitas.
 */
class StorePatientPhotosRequest extends StorePhotosRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('attachPhotos', $this->route('patient'));
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'taken_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.ClinicTime::today()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [...parent::attributes(), 'taken_on' => 'data da foto'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [...parent::messages(), 'taken_on.before_or_equal' => 'A data da foto não pode ser futura.'];
    }
}
