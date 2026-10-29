<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Enums\PaymentMethod;
use App\Http\Requests\InstallmentPaymentRequest;
use App\Models\FinancialEntry;
use App\Models\FinancialInstallment;
use App\Services\AuditLogger;
use App\Services\CashRegister;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class InstallmentPaymentController extends Controller
{
    public const CARD_SWITCH_MESSAGE = 'Receita parcelada no cartão de crédito é recebida de uma vez só: para trocar a forma de pagamento, ajuste a cobrança.';

    /** Lançamento travado na transação, para a auditoria e as regras de recebimento. */
    private ?FinancialEntry $entry = null;

    public function __construct(private readonly AuditLogger $audit, private readonly CashRegister $cash) {}

    /**
     * Só identificadores e valores do pagamento, nunca dados pessoais.
     *
     * @return array<string, int|string>
     */
    private function auditData(FinancialInstallment $installment): array
    {
        return array_filter([
            'financial_entry_id' => $installment->financial_entry_id,
            'patient_id' => $this->entry?->patient_id,
            'amount' => $installment->amount,
            'paid_at' => $installment->paid_at?->format('Y-m-d'),
        ], fn ($value) => $value !== null);
    }

    /**
     * Registra o pagamento de uma parcela em aberto.
     */
    public function store(InstallmentPaymentRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $installment = FinancialInstallment::query()->with('entry:id,type')->findOrFail($validated['installment_id']);

        Gate::authorize('receive', $installment->entry);

        $count = DB::transaction(function () use ($installment, $validated): int {
            // Receita no crédito: recebida uma parcela, todas as em aberto do lançamento são recebidas.
            $settled = $this->siblingsSettledTogether($installment)->whereNull('paid_at')->get();

            // Outra pessoa registrou este pagamento enquanto a tela estava aberta.
            if ($settled->doesntContain('id', $installment->id)) {
                throw ValidationException::withMessages(['installment_id' => 'Esta parcela não está mais em aberto.']);
            }

            $this->cash->assertOpen($validated['paid_at']);

            $this->confirmPaymentMethod($validated['payment_method'] ?? null);

            foreach ($settled as $each) {
                $each->update(['paid_at' => $validated['paid_at']]);

                $this->audit->log(AuditAction::InstallmentPaid, $each, $this->auditData($each));
            }

            return $settled->count();
        });

        return redirect()->back(fallback: route('finance.index'))
            ->with('success', $count > 1 ? "Pagamento registrado nas {$count} parcelas do cartão de crédito." : 'Pagamento registrado.');
    }

    /**
     * Desfaz um pagamento registrado por engano.
     */
    public function destroy(FinancialInstallment $installment): RedirectResponse
    {
        Gate::authorize('settle', FinancialEntry::class);

        abort_unless($installment->isPaid(), 404);

        $count = DB::transaction(function () use ($installment): int {
            // Receita no crédito: as parcelas foram recebidas juntas e são reabertas juntas.
            $reopened = $this->siblingsSettledTogether($installment)->whereNotNull('paid_at')->get();

            // Outra pessoa já desfez este pagamento enquanto a tela estava aberta.
            if ($reopened->doesntContain('id', $installment->id)) {
                throw ValidationException::withMessages(['installment_id' => 'Esta parcela já está em aberto.']);
            }

            // Com estorno, o dinheiro devolvido sairia de um pagamento que deixou de existir.
            if ($this->entry->refunds()->exists()) {
                throw ValidationException::withMessages(['installment_id' => 'Esta cobrança tem estorno registrado. Desfaça o estorno antes de desfazer o pagamento.']);
            }

            foreach ($reopened as $each) {
                $this->cash->assertOpen($each->paid_at->format('Y-m-d'), 'installment_id');
            }

            foreach ($reopened as $each) {
                // A data desfeita entra na auditoria antes de ser apagada.
                $data = $this->auditData($each);
                $each->update(['paid_at' => null]);

                $this->audit->log(AuditAction::InstallmentReopened, $each, $data);
            }

            return $reopened->count();
        });

        return redirect()->back(fallback: route('finance.index'))->with('success', $count > 1
            ? "Pagamento desfeito. As {$count} parcelas do cartão de crédito voltaram a ficar em aberto."
            : 'Pagamento desfeito. A parcela voltou a ficar em aberto.');
    }

    /**
     * Grava a forma de pagamento confirmada no recebimento, quando difere da
     * lançada e nenhuma parcela foi paga ainda. Receita parcelada no cartão de
     * crédito segue outra regra de quitação, então não entra nem sai dela por aqui.
     *
     * @throws ValidationException
     */
    private function confirmPaymentMethod(?string $method): void
    {
        $entry = $this->entry;

        if ($method === null || $entry === null || $entry->payment_method->value === $method) {
            return;
        }

        $confirmed = PaymentMethod::from($method);

        if ($entry->installments_count > 1
            && ($confirmed->settlesAllInstallments($entry->type) || $entry->payment_method->settlesAllInstallments($entry->type))) {
            throw ValidationException::withMessages(['payment_method' => self::CARD_SWITCH_MESSAGE]);
        }

        if ($entry->paid_installments_count > 0) {
            throw ValidationException::withMessages(['payment_method' => 'Este lançamento já tem parcela paga: a forma de pagamento não muda mais.']);
        }

        $from = $entry->payment_method->value;
        $entry->update(['payment_method' => $confirmed]);

        $this->audit->log(AuditAction::FinancialEntryUpdated, $entry, array_filter([
            'fields' => ['payment_method'],
            'from' => $from,
            'to' => $confirmed->value,
            'patient_id' => $entry->patient_id,
        ], fn ($value) => $value !== null));
    }

    /**
     * Parcelas que mudam junto com esta: todas as do lançamento, na receita no
     * cartão de crédito; só ela mesma, nos demais casos. Chamado dentro da
     * transação: a trava fica na linha do lançamento, ponto único que
     * serializa pagamentos e estornos simultâneos das parcelas dele, e as
     * parcelas são lidas já com o valor atual.
     *
     * @return Builder<FinancialInstallment>
     */
    private function siblingsSettledTogether(FinancialInstallment $installment): Builder
    {
        $this->entry = FinancialEntry::query()
            ->select(['id', 'type', 'payment_method', 'patient_id'])
            ->withCount(['installments', 'installments as paid_installments_count' => fn ($query) => $query->whereNotNull('paid_at')])
            ->lockForUpdate()
            ->findOrFail($installment->financial_entry_id);

        $query = $this->entry->payment_method->settlesAllInstallments($this->entry->type)
            ? FinancialInstallment::query()->where('financial_entry_id', $this->entry->id)->orderBy('number')
            : FinancialInstallment::query()->whereKey($installment->getKey());

        return $query->lockForUpdate();
    }
}
