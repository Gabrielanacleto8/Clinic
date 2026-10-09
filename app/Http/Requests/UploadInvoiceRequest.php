<?php

namespace App\Http\Requests;

use App\Models\Product;
use App\Support\UploadLimits;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Envio do XML de uma nota fiscal para importação. O conteúdo é conferido por InvoiceXmlReader.
 */
class UploadInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Product::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'invoice' => ['required', 'file', 'extensions:xml', 'mimetypes:text/xml,application/xml,text/plain', 'max:'.config('clinic.stock.invoice_max_upload_kilobytes')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['invoice' => 'arquivo da nota fiscal'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $xmlOnly = 'Envie o arquivo XML da nota fiscal eletrônica (NF-e), não o PDF.';

        return [
            'invoice.extensions' => $xmlOnly,
            'invoice.mimetypes' => $xmlOnly,
            'invoice.max' => 'O XML da nota pode ter no máximo '.(config('clinic.stock.invoice_max_upload_kilobytes') / 1024).' MB.',
            'invoice.uploaded' => 'O arquivo passa do limite de '.UploadLimits::megabytes().' MB aceito por este servidor.',
        ];
    }
}
