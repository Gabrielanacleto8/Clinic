<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Enums\FinancialCategory;
use App\Enums\FinancialEntryType;
use App\Enums\PaymentMethod;
use App\Http\Requests\FinancialEntryRequest;
use App\Models\FinancialEntry;
use App\Services\AuditLogger;
use App\Services\FinancialRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class FinancialEntryController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function create(Request $request): View
    {
        Gate::authorize('create', FinancialEntry::class);

        // Em caso de erro, mantém o tipo que o usuário tinha escolhido.
        $type = FinancialEntryType::tryFrom((string) $request->old('type', $request->query('tipo')))
            ?? FinancialEntryType::Expense;

        return view('finance.create', ['type' => $type]);
    }

    public function store(FinancialEntryRequest $request, FinancialRecorder $recorder): RedirectResponse
    {
        $entry = DB::transaction(function () use ($request, $recorder): FinancialEntry {
            $entry = $recorder->record(
                category: FinancialCategory::from($request->validated('category')),
                description: $request->validated('description'),
                total: (string) $request->validated('total'),
                paymentMethod: PaymentMethod::from($request->validated('payment_method')),
                installments: (int) $request->validated('installments'),
                firstDueDate: $request->validated('first_due_date'),
                firstInstallmentPaid: $request->boolean('first_installment_paid'),
                createdBy: $request->user(),
                issuedOn: $request->validated('issued_on'),
            );

            $this->audit->log(AuditAction::FinancialEntryCreated, $entry, ['type' => $entry->type->value]);

            return $entry;
        });

        return redirect()
            ->route('finance.index', ['mes' => $entry->issued_on->format('Y-m')])
            ->with('success', $entry->type->label().' lançada.');
    }

    public function destroy(FinancialEntry $entry): RedirectResponse
    {
        Gate::authorize('delete', $entry);

        DB::transaction(function () use ($entry): void {
            $this->audit->log(AuditAction::FinancialEntryDeleted, $entry, ['type' => $entry->type->value]);

            // As parcelas saem junto, pela chave estrangeira em cascata.
            $entry->delete();
        });

        return redirect()->back(fallback: route('finance.index'))->with('success', 'Lançamento excluído.');
    }
}
