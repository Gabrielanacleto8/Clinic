<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Models\FinancialEntry;
use App\Models\FinancialInstallment;
use App\Services\AuditLogger;
use App\Support\ClinicTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InstallmentPaymentController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Registra o pagamento de uma parcela em aberto.
     */
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('settle', FinancialEntry::class);

        $validated = $request->validate([
            // Só parcelas ainda em aberto: pagar duas vezes não é possível.
            'installment_id' => ['required', 'integer', Rule::exists(FinancialInstallment::class, 'id')->whereNull('paid_at')],
            'paid_at' => ['required', 'date_format:Y-m-d', 'after:2000-01-01', 'before_or_equal:'.ClinicTime::today()],
        ], [
            'installment_id.exists' => 'Esta parcela não está mais em aberto.',
            'paid_at.before_or_equal' => 'A data do pagamento não pode estar no futuro.',
        ], ['installment_id' => 'parcela', 'paid_at' => 'data do pagamento']);

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

                $this->audit->log(AuditAction::InstallmentPaid, $each, ['financial_entry_id' => $each->financial_entry_id]);
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

                $this->audit->log(AuditAction::InstallmentReopened, $each, ['financial_entry_id' => $each->financial_entry_id]);
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
        $entry = FinancialEntry::query()->select(['id', 'type', 'payment_method'])->lockForUpdate()->findOrFail($installment->financial_entry_id);

        $query = $entry->payment_method->settlesAllInstallments($entry->type)
            ? FinancialInstallment::query()->where('financial_entry_id', $entry->id)->orderBy('number')
            : FinancialInstallment::query()->whereKey($installment->getKey());

        return $query->lockForUpdate();
    }
}
