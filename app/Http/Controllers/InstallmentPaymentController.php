<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Http\Requests\InstallmentPaymentRequest;
use App\Models\FinancialEntry;
use App\Models\FinancialInstallment;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class InstallmentPaymentController extends Controller
{
    /** Paciente do lançamento travado, para a auditoria por paciente. */
    private ?int $patientId = null;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return array<string, int>
     */
    private function auditData(FinancialInstallment $installment): array
    {
        return array_filter(['financial_entry_id' => $installment->financial_entry_id, 'patient_id' => $this->patientId], fn ($value) => $value !== null);
    }

    /**
     * Registra o pagamento de uma parcela em aberto.
     */
    public function store(InstallmentPaymentRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $installment = FinancialInstallment::query()->findOrFail($validated['installment_id']);

        $count = DB::transaction(function () use ($installment, $validated): int {
            // Receita no crédito: recebida uma parcela, todas as em aberto do lançamento são recebidas.
            $settled = $this->siblingsSettledTogether($installment)->whereNull('paid_at')->get();

            // Outra pessoa registrou este pagamento enquanto a tela estava aberta.
            if ($settled->doesntContain('id', $installment->id)) {
                throw ValidationException::withMessages(['installment_id' => 'Esta parcela não está mais em aberto.']);
            }

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

            foreach ($reopened as $each) {
                $each->update(['paid_at' => null]);

                $this->audit->log(AuditAction::InstallmentReopened, $each, $this->auditData($each));
            }

            return $reopened->count();
        });

        return redirect()->back(fallback: route('finance.index'))->with('success', $count > 1
            ? "Pagamento desfeito. As {$count} parcelas do cartão de crédito voltaram a ficar em aberto."
            : 'Pagamento desfeito. A parcela voltou a ficar em aberto.');
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
        $entry = FinancialEntry::query()->select(['id', 'type', 'payment_method', 'patient_id'])->lockForUpdate()->findOrFail($installment->financial_entry_id);
        $this->patientId = $entry->patient_id;

        $query = $entry->payment_method->settlesAllInstallments($entry->type)
            ? FinancialInstallment::query()->where('financial_entry_id', $entry->id)->orderBy('number')
            : FinancialInstallment::query()->whereKey($installment->getKey());

        return $query->lockForUpdate();
    }
}
