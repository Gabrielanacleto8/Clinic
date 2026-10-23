<?php

namespace App\Http\Requests;

use App\Models\ProductBatch;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Baixa de um lote vencido como perda, com as unidades abertas dele.
 */
class WriteOffExpiredBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        $batch = $this->route('batch');

        return $batch instanceof ProductBatch && $this->user()->can('discard', $batch->product);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:255']];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['reason' => 'motivo'];
    }
}
