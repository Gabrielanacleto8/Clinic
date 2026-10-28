<?php

namespace App\Http\Requests;

use App\Support\UploadLimits;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Logo da clínica. A checagem que vale é a do PhotoProcessor, que decodifica a imagem.
 */
class UpdateClinicLogoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-clinic');
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'logo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:'.config('clinic.photos.max_upload_kilobytes')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'logo.required' => 'Selecione a imagem do logo.',
            'logo.mimes' => 'Envie o logo em JPEG, PNG ou WebP.',
            'logo.max' => 'O logo pode ter no máximo '.(config('clinic.photos.max_upload_kilobytes') / 1024).' MB.',
            'logo.uploaded' => 'O arquivo passa do limite de '.UploadLimits::megabytes().' MB aceito por este servidor.',
        ];
    }
}
