<?php

namespace Database\Factories;

use App\Enums\FinancialCategory;
use App\Enums\FinancialEntryType;
use App\Enums\PaymentMethod;
use App\Models\FinancialEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinancialEntry>
 */
class FinancialEntryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => FinancialEntryType::Income,
            'category' => FinancialCategory::OtherIncome,
            'description' => 'Lançamento de teste',
            'issued_on' => now()->format('Y-m-d'),
            'payment_method' => PaymentMethod::Pix,
        ];
    }

    public function expense(FinancialCategory $category = FinancialCategory::OtherExpense): static
    {
        return $this->state(['type' => FinancialEntryType::Expense, 'category' => $category]);
    }

    /**
     * Acrescenta uma parcela ao lançamento.
     */
    public function withInstallment(string $amount, string $dueDate, ?string $paidAt = null, int $number = 1): static
    {
        return $this->afterCreating(fn (FinancialEntry $entry) => $entry->installments()->create([
            'number' => $number, 'amount' => $amount, 'due_date' => $dueDate, 'paid_at' => $paidAt,
        ]));
    }
}
