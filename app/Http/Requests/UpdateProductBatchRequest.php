<?php

namespace App\Http\Requests;

use App\Models\ProductBatch;
use App\Support\ClinicTime;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Correção da fabricação e da validade de um lote digitadas errado na
 * entrada. Mexe na rastreabilidade, por isso exige a permissão de ajuste.
 */
class UpdateProductBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        $batch = $this->route('batch');

        return $batch instanceof ProductBatch && $this->user()->can('adjust', $batch->product);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'manufactured_on' => ['nullable', 'date_format:Y-m-d', 'after:2000-01-01', 'before_or_equal:'.ClinicTime::today()],
            // Pode ficar no passado: corrigir para a data real pode revelar que o lote venceu.
            'expires_on' => [
                'nullable', 'date_format:Y-m-d', 'after:2000-01-01', 'before:2100-01-01',
                ...($this->filled('manufactured_on') ? ['after:manufactured_on'] : []),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['manufactured_on' => 'fabricação', 'expires_on' => 'validade'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'manufactured_on.before_or_equal' => 'A fabricação não pode estar no futuro.',
            'expires_on.after' => 'A validade deve ser posterior à fabricação.',
        ];
    }
}
