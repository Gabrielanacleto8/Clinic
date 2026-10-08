<?php

namespace App\Http\Requests;

use App\Support\UploadLimits;
use Illuminate\Foundation\Http\FormRequest;

class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('attach', $this->route('treatment'));
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'document' => ['required', 'file', 'mimes:pdf', 'max:'.config('clinic.documents.max_upload_kilobytes')],
            'title' => ['required', 'string', 'min:2', 'max:150'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['document' => 'documento', 'title' => 'título'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'document.mimes' => 'Envie o documento em PDF.',
            'document.max' => 'O documento pode ter no máximo '.(config('clinic.documents.max_upload_kilobytes') / 1024).' MB.',
            'document.uploaded' => 'O documento passa do limite de '.UploadLimits::megabytes().' MB por arquivo aceito por este servidor.',
        ];
    }
}
