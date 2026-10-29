<?php

namespace App\Services;

use App\Enums\FinancialEntryType;
use App\Enums\StockMovementType;
use App\Support\ClinicTime;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Receitas e margem num período, por procedimento ou por profissional: o que
 * os atendimentos renderam, os descontos e estornos, o custo dos produtos
 * que eles baixaram e a comissão.
 *
 * Receita de um atendimento: a cobrança dele ou, se usou sessão de pacote,
 * o valor da sessão no pacote. Desconto: o gravado na cobrança ou, na sessão,
 * a diferença para o preço de tabela do item. Estorno: o devolvido da
 * cobrança do atendimento. Custo: o custo médio gravado em cada baixa do
 * atendimento, consumo e sobra descartada. Comissão: a devida hoje pelo
 * CommissionLedger. Anulados ficam de fora.
 */
class ProcedureMargin
{
    public const GROUPS = ['procedimento' => 'Por procedimento', 'profissional' => 'Por profissional'];

    public function __construct(private readonly CommissionLedger $commissions) {}

    /**
     * @param  string  $group  "procedimento" ou "profissional".
     * @return Collection<int, object{id: int, name: string, treatments: int, revenue: float, discount: float, refunded: float, cost: float, commission: float, uncosted: int}>
     */
    public function between(string $start, string $end, ?int $professionalId, string $group = 'procedimento'): Collection
    {
        [$from] = ClinicTime::dayBounds($start);
        [, $until] = ClinicTime::dayBounds($end);

        $treatments = DB::table('treatments')
            ->whereNull('voided_at')
            ->whereNull('deleted_at')
            ->whereBetween('performed_at', [$from, $until])
            ->when($professionalId, fn ($query, int $id) => $query->where('professional_id', $id))
            ->select('id');

        $charges = DB::table('financial_entries as e')
            ->join('financial_installments as i', 'i.financial_entry_id', '=', 'e.id')
            ->whereIn('e.treatment_id', $treatments)
            ->groupBy('e.id', 'e.treatment_id', 'e.discount')
            ->selectRaw('e.id, e.treatment_id, e.discount, sum(i.amount) as total');

        $refunds = DB::table('financial_entries as r')
            ->join('financial_installments as i', 'i.financial_entry_id', '=', 'r.id')
            ->whereNotNull('r.refund_of_id')
            ->where('r.type', FinancialEntryType::Expense->value)
            ->groupBy('r.refund_of_id')
            ->selectRaw('r.refund_of_id, sum(i.amount) as total');

        $costs = DB::table('stock_movements')
            ->whereIn('treatment_id', $treatments)
            ->whereIn('type', [StockMovementType::Consumption->value, StockMovementType::Loss->value])
            ->groupBy('treatment_id')
            ->selectRaw('treatment_id, sum(abs(quantity) * coalesce(unit_cost, 0)) as total, sum(unit_cost is null) as uncosted');

        [$key, $name, $join] = $group === 'profissional'
            ? ['t.professional_id', 'u.name', ['users as u', 'u.id', '=', 't.professional_id']]
            : ['t.procedure_id', 'p.name', ['procedures as p', 'p.id', '=', 't.procedure_id']];

        $rows = DB::table('treatments as t')
            ->join(...$join)
            ->leftJoinSub($charges, 'charge', 'charge.treatment_id', '=', 't.id')
            ->leftJoinSub($refunds, 'refund', 'refund.refund_of_id', '=', 'charge.id')
            ->leftJoinSub($costs, 'cost', 'cost.treatment_id', '=', 't.id')
            ->leftJoin('treatment_plan_items as item', 'item.id', '=', 't.treatment_plan_item_id')
            ->whereIn('t.id', $treatments)
            ->groupBy($key, $name)
            ->orderBy($name)
            ->selectRaw("{$key} as id, {$name} as name, count(*) as treatments")
            ->selectRaw('sum(coalesce(charge.total, item.unit_price, 0)) as revenue')
            ->selectRaw('sum(coalesce(charge.discount, greatest(coalesce(item.list_price, item.unit_price) - item.unit_price, 0), 0)) as discount')
            ->selectRaw('sum(coalesce(refund.total, 0)) as refunded')
            ->selectRaw('sum(coalesce(cost.total, 0)) as cost')
            ->selectRaw('sum(coalesce(cost.uncosted, 0)) as uncosted')
            ->get();

        $commission = $this->commission($from->format('Y-m-d H:i:s'), $end, $professionalId, $group);

        return $rows->map(fn (object $row) => (object) [
            'id' => (int) $row->id,
            'name' => $row->name,
            'treatments' => (int) $row->treatments,
            'revenue' => (float) $row->revenue,
            'discount' => (float) $row->discount,
            'refunded' => (float) $row->refunded,
            'cost' => (float) $row->cost,
            'commission' => $commission[(int) $row->id] ?? 0.0,
            'uncosted' => (int) $row->uncosted,
        ]);
    }

    /**
     * Comissão devida pelos atendimentos do período, somada por grupo.
     *
     * @return array<int, float>
     */
    private function commission(string $from, string $end, ?int $professionalId, string $group): array
    {
        return $this->commissions->rows($end, $professionalId)
            ->filter(fn (object $row) => ! $row->voided && $row->performed_at >= $from)
            ->groupBy($group === 'profissional' ? 'professional_id' : 'procedure_id')
            ->map(fn (Collection $rows) => round($rows->sum('earned'), 2))
            ->all();
    }
}
