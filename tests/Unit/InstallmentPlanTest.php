<?php

namespace Tests\Unit;

use App\Support\InstallmentPlan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InstallmentPlanTest extends TestCase
{
    public function test_single_installment_keeps_the_total_and_the_date(): void
    {
        $this->assertSame(
            [['number' => 1, 'amount' => '850.00', 'due_date' => '2026-10-08']],
            InstallmentPlan::split('850', 1, '2026-10-08'),
        );
    }

    public function test_remainder_goes_to_the_first_installment(): void
    {
        $this->assertSame(['33.34', '33.33', '33.33'], array_column(InstallmentPlan::split('100.00', 3, '2026-10-08'), 'amount'));
    }

    public function test_due_dates_advance_monthly_without_overflowing_short_months(): void
    {
        $this->assertSame(
            ['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30'],
            array_column(InstallmentPlan::split('400', 4, '2026-01-31'), 'due_date'),
        );
    }

    #[DataProvider('totals')]
    public function test_installments_always_add_up_to_the_exact_total(string $total, int $count): void
    {
        $cents = array_sum(array_map(fn (array $installment) => (int) round(((float) $installment['amount']) * 100), InstallmentPlan::split($total, $count, '2026-10-08')));

        $this->assertSame((int) round(((float) $total) * 100), $cents);
    }

    /**
     * @return list<array{string, int}>
     */
    public static function totals(): array
    {
        return [['0.01', 1], ['0.05', 3], ['100.00', 7], ['999.99', 12], ['1234.56', 11], ['0.10', 12], ['9999999.99', 12], ['19.90', 2]];
    }
}
