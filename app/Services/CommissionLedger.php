<?php

namespace App\Services;

use App\Enums\FinancialEntryType;
use App\Support\ClinicTime;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Comissão devida por atendimento, calculada na hora.
 *
 * Base: o que o paciente efetivamente pagou do atendimento, menos estornos.
 * Na sessão de pacote, o valor da sessão na proporção do pacote já pago.
 * Comissão: base × % do atendimento (o gravado no registro ou, nos
 * registrados antes das regras, a regra atual). A repassar: a comissão menos
 * o que os repasses anteriores já pagaram por ele. Atendimento anulado depois
 * de repassado fica com comissão zero e saldo negativo, descontado no próximo.
 */
class CommissionLedger
{
    /**
     * Atendimentos com comissão (ou com repasse) até a data, de um profissional ou de todos.
     *
     * @return Collection<int, object{treatment_id: int, professional_id: int, procedure_id: int, performed_at: string, procedure: string, value: float, base: float, rate: float, earned: float, paid: float, pending: float, voided: bool}>
     */
    public function rows(string $until, ?int $professionalId = null): Collection
    {
        [, $end] = ClinicTime::dayBounds($until);

        return $this->query($end, $professionalId)->get()->map(function (object $row): object {
            $voided = $row->voided_at !== null;
            $rate = (float) ($row->rate ?? 0);

            if ($row->charge_total !== null) {
                $value = (float) $row->charge_total;
                $base = max(0, (float) $row->charge_paid - (float) $row->charge_refunded);
            } elseif ($row->unit_price !== null) {
                $value = (float) $row->unit_price;
                $ratio = (float) $row->plan_total > 0 ? max(0, (float) $row->plan_paid - (float) $row->plan_refunded) / (float) $row->plan_total : 0;
                $base = round($value * min(1, $ratio), 2);
            } else {
                $value = 0.0;
                $base = 0.0;
            }

            if ($voided) {
                $base = 0.0;
            }

            $earned = round($base * $rate / 100, 2);
            $paid = (float) $row->already_paid;

            return (object) [
                'treatment_id' => (int) $row->id,
                'professional_id' => (int) $row->professional_id,
                'procedure_id' => (int) $row->procedure_id,
                'performed_at' => $row->performed_at,
                'procedure' => $row->procedure_name,
                'value' => $value,
                'base' => $base,
                'rate' => $rate,
                'earned' => $earned,
                'paid' => $paid,
                'pending' => round($earned - $paid, 2),
                'voided' => $voided,
            ];
        });
    }

    /**
     * Totais por profissional: comissão, já repassado e a repassar.
     *
     * @return Collection<int, object{professional_id: int, treatments: int, earned: float, paid: float, pending: float}>
     */
    public function summary(string $until): Collection
    {
        return $this->rows($until)
            ->groupBy('professional_id')
            ->map(fn (Collection $rows, int $professionalId) => (object) [
                'professional_id' => $professionalId,
                'treatments' => $rows->count(),
                'earned' => round($rows->sum('earned'), 2),
                'paid' => round($rows->sum('paid'), 2),
                'pending' => round($rows->sum('pending'), 2),
            ])
            ->values();
    }

    private function query(string $end, ?int $professionalId): Builder
    {
        // Cada lançamento com o total, o pago e o estornado.
        $entries = DB::table('financial_entries as e')
            ->join('financial_installments as i', 'i.financial_entry_id', '=', 'e.id')
            ->where('e.type', FinancialEntryType::Income->value)
            ->where(fn ($query) => $query->whereNotNull('e.treatment_id')->orWhereNotNull('e.treatment_plan_id'))
            ->groupBy('e.id', 'e.treatment_id', 'e.treatment_plan_id')
            ->selectRaw('e.id, e.treatment_id, e.treatment_plan_id, sum(i.amount) as total, coalesce(sum(case when i.paid_at is not null then i.amount end), 0) as paid');

        $refunds = DB::table('financial_entries as r')
            ->join('financial_installments as i', 'i.financial_entry_id', '=', 'r.id')
            ->whereNotNull('r.refund_of_id')
            ->groupBy('r.refund_of_id')
            ->selectRaw('r.refund_of_id, sum(i.amount) as refunded');

        $payouts = DB::table('commission_payout_items')
            ->groupBy('treatment_id')
            ->selectRaw('treatment_id, sum(amount) as amount');

        return DB::table('treatments as t')
            ->join('procedures as p', 'p.id', '=', 't.procedure_id')
            ->leftJoinSub($entries, 'charge', 'charge.treatment_id', '=', 't.id')
            ->leftJoinSub($refunds, 'charge_refund', 'charge_refund.refund_of_id', '=', 'charge.id')
            ->leftJoin('treatment_plan_items as item', 'item.id', '=', 't.treatment_plan_item_id')
            ->leftJoinSub($entries, 'plan', 'plan.treatment_plan_id', '=', 'item.treatment_plan_id')
            ->leftJoinSub($refunds, 'plan_refund', 'plan_refund.refund_of_id', '=', 'plan.id')
            ->leftJoinSub($payouts, 'payout', 'payout.treatment_id', '=', 't.id')
            ->leftJoin('commission_rates as rate_exception', fn ($join) => $join->on('rate_exception.user_id', '=', 't.professional_id')->on('rate_exception.procedure_id', '=', 't.procedure_id'))
            ->leftJoin('commission_rates as rate_standard', fn ($join) => $join->on('rate_standard.user_id', '=', 't.professional_id')->whereNull('rate_standard.procedure_id'))
            ->whereNull('t.deleted_at')
            ->where('t.performed_at', '<=', $end)
            ->when($professionalId, fn ($query, int $id) => $query->where('t.professional_id', $id))
            ->selectRaw('t.id, t.professional_id, t.procedure_id, t.performed_at, t.voided_at, p.name as procedure_name')
            ->selectRaw('coalesce(t.commission_rate, rate_exception.rate, rate_standard.rate) as rate')
            ->selectRaw('charge.total as charge_total, charge.paid as charge_paid, coalesce(charge_refund.refunded, 0) as charge_refunded')
            ->selectRaw('item.unit_price, plan.total as plan_total, plan.paid as plan_paid, coalesce(plan_refund.refunded, 0) as plan_refunded')
            ->selectRaw('coalesce(payout.amount, 0) as already_paid')
            // Com comissão a calcular, ou com repasse a acertar (anulado depois de repassado).
            ->where(fn ($query) => $query
                ->where(fn ($withRate) => $withRate->whereNull('t.voided_at')->whereRaw('coalesce(t.commission_rate, rate_exception.rate, rate_standard.rate) > 0'))
                ->orWhereNotNull('payout.amount'))
            ->orderBy('t.performed_at')
            ->orderBy('t.id');
    }
}
