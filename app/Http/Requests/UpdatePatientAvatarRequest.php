<?php

namespace App\Http\Requests;

use App\Support\UploadLimits;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Foto de identificação do paciente. Como nas demais fotos, a checagem que
 * vale é a do PhotoProcessor, que decodifica a imagem de fato.
 */
class UpdatePatientAvatarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('patient'));
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'avatar' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:'.config('clinic.photos.max_upload_kilobytes')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['avatar' => 'foto'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'avatar.required' => 'Selecione uma foto.',
            'avatar.mimes' => 'Envie uma foto em JPEG, PNG ou WebP.',
            'avatar.max' => 'A foto pode ter no máximo '.(config('clinic.photos.max_upload_kilobytes') / 1024).' MB.',
            'avatar.uploaded' => 'A foto passa do limite de '.UploadLimits::megabytes().' MB por arquivo aceito por este servidor.',
        ];
    }
}
