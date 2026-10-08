<?php

namespace App\Http\Requests;

use App\Enums\PhotoStage;
use App\Support\UploadLimits;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePhotosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('attach', $this->route('treatment'));
    }

    /**
     * A extensão e o tipo declarado são só a primeira barreira. A checagem
     * que vale é a do PhotoProcessor, que decodifica a imagem de fato.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'photos' => ['required', 'array', 'min:1', 'max:'.config('clinic.photos.max_per_upload')],
            'photos.*' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:'.config('clinic.photos.max_upload_kilobytes')],
            'stage' => ['required', Rule::enum(PhotoStage::class)],
            'caption' => ['nullable', 'string', 'max:150'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['photos' => 'fotos', 'photos.*' => 'foto', 'stage' => 'momento', 'caption' => 'legenda'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'photos.required' => 'Selecione ao menos uma foto.',
            'photos.max' => 'Envie no máximo :max fotos por vez.',
            'photos.*.mimes' => 'Envie apenas fotos em JPEG, PNG ou WebP.',
            'photos.*.max' => 'Cada foto pode ter no máximo '.(config('clinic.photos.max_upload_kilobytes') / 1024).' MB.',
            'photos.*.uploaded' => 'Uma das fotos passa do limite de '.UploadLimits::megabytes().' MB por arquivo aceito por este servidor.',
        ];
    }
}
