<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Enums\FinancialCategory;
use App\Enums\FinancialEntryType;
use App\Enums\PaymentMethod;
use App\Http\Requests\FinancialEntryRequest;
use App\Http\Requests\UpdateFinancialEntryRequest;
use App\Models\FinancialEntry;
use App\Models\Patient;
use App\Services\AuditLogger;
use App\Services\FinancialRecorder;
use App\Support\LocalizedDecimal;
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

        $query = $request->validate(['repetir' => ['nullable', 'integer']]);

        // Em caso de erro, mantém o tipo que o usuário tinha escolhido.
        $type = FinancialEntryType::tryFrom((string) $request->old('type', $request->query('tipo')))
            ?? FinancialEntryType::Expense;

        // "Repetir" uma despesa (conta de luz, aluguel): o formulário já vem
        // preenchido com a anterior, na data de hoje. Nada é gravado sozinho.
        $defaults = [];

        if (isset($query['repetir'])) {
            $previous = FinancialEntry::query()->withCount('installments')->withSum('installments as total_amount', 'amount')->find((int) $query['repetir']);

            if ($previous) {
                $type = $previous->type;
                $defaults = [
                    'description' => $previous->description,
                    'category' => $previous->category->value,
                    'total' => LocalizedDecimal::moneyInput($previous->total()),
                    'payment_method' => $previous->payment_method->value,
                    'installments' => $previous->installments_count,
                ];
            }
        }

        return view('finance.create', ['type' => $type, 'defaults' => $defaults, 'patient' => FinancialEntryRequest::oldPatient($request)]);
    }

    public function store(FinancialEntryRequest $request, FinancialRecorder $recorder): RedirectResponse
    {
        $entry = DB::transaction(function () use ($request, $recorder): FinancialEntry {
            $patient = $request->filled('patient_id') ? Patient::query()->find((int) $request->validated('patient_id')) : null;

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
                patient: $patient,
            );

            $this->audit->log(AuditAction::FinancialEntryCreated, $entry, array_filter(['type' => $entry->type->value, 'patient_id' => $entry->patient_id]));

            return $entry;
        });

        // Pela janela, o usuário continua na tela de onde abriu (visão geral ou contas a pagar).
        $redirect = $request->filled('_dialog')
            ? redirect()->back()
            : redirect()->route('finance.index', ['mes' => $entry->issued_on->format('Y-m')]);

        return $redirect->with('success', $entry->type->label().' lançada.');
    }

    public function edit(FinancialEntry $entry): View
    {
        Gate::authorize('update', $entry);

        return view('finance.edit', [
            'entry' => $entry->load(['installments' => fn ($query) => $query->orderBy('number')]),
        ]);
    }

    /**
     * Só descrição, categoria e vencimento das parcelas em aberto. Valor e
     * número de parcelas não mudam: para isso, exclui e lança de novo.
     */
    public function update(UpdateFinancialEntryRequest $request, FinancialEntry $entry): RedirectResponse
    {
        DB::transaction(function () use ($request, $entry): void {
            $entry->fill(['description' => $request->validated('description'), 'category' => FinancialCategory::from($request->validated('category'))]);
            $fields = array_keys($entry->getDirty());
            $entry->save();

            foreach ($entry->installments()->whereNull('paid_at')->get() as $installment) {
                $dueDate = $request->validated("due_dates.{$installment->id}");

                if ($dueDate !== null && $dueDate !== $installment->due_date->format('Y-m-d')) {
                    $installment->update(['due_date' => $dueDate]);
                    $fields[] = 'due_date';
                }
            }

            if ($fields !== []) {
                $this->audit->log(AuditAction::FinancialEntryUpdated, $entry, array_filter(['fields' => array_values(array_unique($fields)), 'patient_id' => $entry->patient_id]));
            }
        });

        return redirect()->route('finance.index', ['mes' => $entry->issued_on->format('Y-m')])->with('success', 'Lançamento atualizado.');
    }

    public function destroy(FinancialEntry $entry): RedirectResponse
    {
        if (Gate::denies('delete', $entry)) {
            // Quem poderia excluir, mas há pagamento registrado, recebe a orientação em vez de 403.
            abort_unless(! $entry->isGenerated() && Gate::allows('update', $entry) && $entry->hasPaidInstallment(), 403);

            return redirect()->back(fallback: route('finance.index'))->with('error', 'Este lançamento tem pagamento registrado. Desfaça os pagamentos antes de excluir.');
        }

        DB::transaction(function () use ($entry): void {
            // O que foi apagado fica na trilha: valores do lançamento, não dados pessoais.
            $this->audit->log(AuditAction::FinancialEntryDeleted, $entry, array_filter([
                'type' => $entry->type->value,
                'category' => $entry->category->value,
                'issued_on' => $entry->issued_on->format('Y-m-d'),
                'total' => $entry->total(),
                'installments' => $entry->installments()->count(),
                'payment_method' => $entry->payment_method->value,
                'patient_id' => $entry->patient_id,
            ], fn ($value) => $value !== null));

            // As parcelas saem junto, pela chave estrangeira em cascata.
            $entry->delete();
        });

        return redirect()->back(fallback: route('finance.index'))->with('success', 'Lançamento excluído.');
    }
}
