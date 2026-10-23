<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Descarte do que resta em uma unidade física aberta. Sempre com motivo.
 */
class DiscardStockUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('discard', $this->route('unit')->batch->product);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['reason' => 'motivo'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['reason.required' => 'Informe o motivo do descarte.'];
    }
}
