<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Anexo de tarefa: PDF, imagem ou documento do Office, até o limite dos documentos.
 */
class TaskAttachmentRequest extends FormRequest
{
    public const EXTENSIONS = ['pdf', 'png', 'jpg', 'jpeg', 'docx', 'xlsx'];

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('task'));
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'attachment' => ['required', 'file', 'extensions:'.implode(',', self::EXTENSIONS), 'max:'.config('clinic.documents.max_upload_kilobytes')],
            'name' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['attachment' => 'arquivo', 'name' => 'nome do anexo'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['attachment.extensions' => 'Envie PDF, imagem (PNG ou JPG) ou documento do Office (DOCX ou XLSX).'];
    }
}
