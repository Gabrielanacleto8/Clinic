<?php

namespace App\Models;

use App\Enums\FinancialCategory;
use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Despesa que se repete todo mês (aluguel, salários, sistemas). O
 * RecurringExpenses gera um lançamento por mês, em aberto, no dia do
 * vencimento; o lançamento gerado é uma despesa como as outras.
 */
class RecurringExpense extends Model
{
    protected $fillable = ['category', 'description', 'amount', 'payment_method', 'due_day', 'starts_on', 'ends_on', 'is_active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => FinancialCategory::class,
            'payment_method' => PaymentMethod::class,
            'amount' => 'decimal:2',
            'due_day' => 'integer',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'generated_until' => 'immutable_date',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<FinancialEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(FinancialEntry::class);
    }
}
