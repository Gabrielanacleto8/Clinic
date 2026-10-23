<?php

namespace App\Http\Requests;

use App\Enums\FinancialCategory;
use App\Models\FinancialEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edição de lançamento manual: descrição, categoria (do mesmo tipo) e
 * vencimento das parcelas ainda em aberto.
 */
class UpdateFinancialEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->entry());
    }

    private function entry(): FinancialEntry
    {
        return $this->route('entry');
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $openIds = $this->entry()->installments()->whereNull('paid_at')->pluck('id')->all();

        return [
            'description' => ['required', 'string', 'min:2', 'max:200'],
            'category' => ['required', Rule::in(array_keys(FinancialCategory::optionsFor($this->entry()->type)))],
            'due_dates' => ['nullable', 'array'],
            // Só parcelas em aberto deste lançamento: as chaves são os ids delas.
            'due_dates.*' => ['required', 'date_format:Y-m-d', 'after:2000-01-01', 'before:2100-01-01'],
            ...array_combine(
                array_map(fn (int $id) => "due_dates.$id", $openIds),
                array_fill(0, count($openIds), ['nullable', 'date_format:Y-m-d', 'after:2000-01-01', 'before:2100-01-01']),
            ),
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function ($validator): void {
                $allowed = $this->entry()->installments()->whereNull('paid_at')->pluck('id')->map(fn ($id) => (string) $id)->all();

                foreach (array_keys((array) $this->input('due_dates', [])) as $key) {
                    if (! in_array((string) $key, $allowed, true)) {
                        $validator->errors()->add('due_dates', 'Só o vencimento de parcelas em aberto pode ser alterado.');

                        return;
                    }
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['description' => 'descrição', 'category' => 'categoria', 'due_dates.*' => 'vencimento'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['category.in' => 'Escolha uma categoria compatível com o tipo do lançamento.'];
    }
}
