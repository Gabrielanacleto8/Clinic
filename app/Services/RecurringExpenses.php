<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\RecurringExpense;
use App\Support\ClinicTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Gera as despesas do mês de cada recorrência ativa: um lançamento em
 * aberto, com vencimento no dia escolhido. Roda todo dia pelo agendador e
 * logo depois de cadastrar uma recorrência; um mês nunca é gerado duas vezes.
 */
class RecurringExpenses
{
    public function __construct(private readonly FinancialRecorder $finance, private readonly AuditLogger $audit) {}

    /**
     * @return int Lançamentos criados.
     */
    public function generate(?RecurringExpense $only = null): int
    {
        $today = CarbonImmutable::createFromFormat('!Y-m-d', ClinicTime::today());
        $created = 0;

        $ids = RecurringExpense::query()
            ->where('is_active', true)
            ->when($only, fn ($query, RecurringExpense $expense) => $query->whereKey($expense->getKey()))
            ->pluck('id');

        foreach ($ids as $id) {
            $created += DB::transaction(function () use ($id, $today): int {
                // A trava impede que o agendador e um cadastro simultâneo gerem o mesmo mês.
                $expense = RecurringExpense::query()->lockForUpdate()->find($id);

                if ($expense === null || ! $expense->is_active) {
                    return 0;
                }

                $months = 0;

                foreach ($this->pendingMonths($expense, $today) as $month) {
                    $dueDate = $month->setDay($expense->due_day)->format('Y-m-d');

                    $this->finance->record(
                        category: $expense->category,
                        description: $expense->description,
                        total: (string) $expense->amount,
                        paymentMethod: $expense->payment_method,
                        installments: 1,
                        firstDueDate: $dueDate,
                        firstInstallmentPaid: false,
                        createdBy: null,
                        issuedOn: $dueDate,
                        links: ['recurring_expense_id' => $expense->getKey()],
                    );

                    $expense->generated_until = $month;
                    $months++;
                }

                if ($months > 0) {
                    $expense->save();
                    $this->audit->log(AuditAction::RecurringExpenseGenerated, $expense, ['months' => $months]);
                }

                return $months;
            });
        }

        return $created;
    }

    /**
     * Meses ainda sem despesa gerada, até o mês corrente. Começa no mês do
     * início ou do cadastro, o que vier depois: cadastrar hoje um aluguel que
     * começou em janeiro não lança os meses passados.
     *
     * @return list<CarbonImmutable> Primeiro dia de cada mês.
     */
    private function pendingMonths(RecurringExpense $expense, CarbonImmutable $today): array
    {
        // Só datas, sem horário nem fuso: o mês do cadastro é o do calendário da clínica.
        $month = fn (string $date) => CarbonImmutable::createFromFormat('!Y-m-d', substr($date, 0, 7).'-01');

        $first = max($month($expense->starts_on->format('Y-m-d')), $month(ClinicTime::format($expense->created_at, 'Y-m-d')));
        $from = $expense->generated_until ? max($first, $month($expense->generated_until->format('Y-m-d'))->addMonthNoOverflow()) : $first;
        $last = $month($today->format('Y-m-d'));

        if ($expense->ends_on !== null) {
            $last = min($last, $month($expense->ends_on->format('Y-m-d')));
        }

        $months = [];

        for ($current = $from; $current->lessThanOrEqualTo($last); $current = $current->addMonthNoOverflow()) {
            $months[] = $current;
        }

        return $months;
    }
}
