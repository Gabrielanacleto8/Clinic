<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Enums\CostBehavior;
use App\Enums\FinancialCategory;
use App\Enums\FinancialEntryType;
use App\Http\Requests\FinanceFilterRequest;
use App\Http\Requests\RecurringExpenseRequest;
use App\Models\RecurringExpense;
use App\Services\AuditLogger;
use App\Services\RecurringExpenses;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Aba Despesas fixas: as despesas que se repetem todo mês e a divisão das
 * despesas do período entre fixas e variáveis.
 */
class RecurringExpenseController extends Controller
{
    public function __construct(private readonly AuditLogger $audit, private readonly RecurringExpenses $generator) {}

    public function index(FinanceFilterRequest $request): View
    {
        [$start, $end] = $request->period();

        return view('finance.recurring', [
            'start' => $start,
            'end' => $end,
            'expenses' => RecurringExpense::query()->orderByDesc('is_active')->orderBy('description')->get(),
            'byCategory' => $this->expensesByCategory($start, $end),
            'cardFees' => (float) DB::table('financial_installments')->whereBetween('reconciled_on', [$start, $end])->sum('fee'),
            'editing' => $this->editing(),
        ]);
    }

    public function store(RecurringExpenseRequest $request): RedirectResponse
    {
        $expense = DB::transaction(function () use ($request): RecurringExpense {
            $expense = new RecurringExpense($request->expenseData());
            $expense->created_by = $request->user()->getKey();
            $expense->save();

            $this->audit->log(AuditAction::RecurringExpenseCreated, $expense, ['category' => $expense->category->value, 'amount' => (string) $expense->amount]);

            return $expense;
        });

        $created = $this->generator->generate($expense);

        return redirect()->route('finance.recurring.index')->with('success', $created > 0
            ? 'Despesa recorrente cadastrada. A deste mês já está em Contas a pagar.'
            : 'Despesa recorrente cadastrada. Ela será lançada no mês do início.');
    }

    public function update(RecurringExpenseRequest $request, RecurringExpense $recurringExpense): RedirectResponse
    {
        DB::transaction(function () use ($request, $recurringExpense): void {
            $recurringExpense->fill($request->expenseData());
            $fields = array_keys($recurringExpense->getDirty());

            if ($fields !== []) {
                $recurringExpense->save();
                $this->audit->log(AuditAction::RecurringExpenseUpdated, $recurringExpense, ['fields' => $fields]);
            }
        });

        $this->generator->generate($recurringExpense);

        return redirect()->route('finance.recurring.index')->with('success', 'Despesa recorrente atualizada. Vale para os próximos meses; as já lançadas ficam como estão.');
    }

    /**
     * Despesas lançadas no período (competência), por categoria, com o comportamento de cada uma.
     *
     * @return list<array{category: FinancialCategory, behavior: CostBehavior|null, total: float}>
     */
    private function expensesByCategory(string $start, string $end): array
    {
        return DB::table('financial_entries as e')
            ->join('financial_installments as i', 'i.financial_entry_id', '=', 'e.id')
            ->where('e.type', FinancialEntryType::Expense->value)
            ->whereBetween('e.issued_on', [$start, $end])
            ->groupBy('e.category')
            ->selectRaw('e.category, sum(i.amount) as total')
            ->orderByDesc('total')
            ->get()
            ->map(fn (object $row) => [
                'category' => FinancialCategory::from($row->category),
                'behavior' => FinancialCategory::from($row->category)->costBehavior(),
                'total' => (float) $row->total,
            ])
            ->all();
    }

    /**
     * Recorrência do diálogo de edição, quando o envio volta com erro.
     */
    private function editing(): ?RecurringExpense
    {
        $id = old('_dialog') === 'recurring-expense-edit' ? old('_recurring') : null;

        return is_numeric($id) ? RecurringExpense::query()->find((int) $id) : null;
    }
}
