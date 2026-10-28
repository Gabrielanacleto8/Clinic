<?php

namespace App\Services;

use App\Enums\StockMovementType;
use App\Support\ClinicTime;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Margem de cada procedimento num período: o que os atendimentos renderam
 * menos o custo dos produtos que eles baixaram.
 *
 * Receita de um atendimento: a cobrança dele ou, se usou sessão de pacote,
 * o valor da sessão no pacote. Custo: o custo médio gravado em cada baixa do
 * atendimento, consumo e sobra descartada. Anulados ficam de fora.
 */
class ProcedureMargin
{
    /**
     * @return Collection<int, object{procedure_id: int, name: string, treatments: int, revenue: float, cost: float, uncosted: int}>
     */
    public function between(string $start, string $end, ?int $professionalId): Collection
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
            ->groupBy('e.treatment_id')
            ->selectRaw('e.treatment_id, sum(i.amount) as total');

        $costs = DB::table('stock_movements')
            ->whereIn('treatment_id', $treatments)
            ->whereIn('type', [StockMovementType::Consumption->value, StockMovementType::Loss->value])
            ->groupBy('treatment_id')
            ->selectRaw('treatment_id, sum(abs(quantity) * coalesce(unit_cost, 0)) as total, sum(unit_cost is null) as uncosted');

        return DB::table('treatments as t')
            ->join('procedures as p', 'p.id', '=', 't.procedure_id')
            ->leftJoinSub($charges, 'charge', 'charge.treatment_id', '=', 't.id')
            ->leftJoinSub($costs, 'cost', 'cost.treatment_id', '=', 't.id')
            ->leftJoin('treatment_plan_items as item', 'item.id', '=', 't.treatment_plan_item_id')
            ->whereIn('t.id', $treatments)
            ->groupBy('t.procedure_id', 'p.name')
            ->orderBy('p.name')
            ->selectRaw('t.procedure_id, p.name, count(*) as treatments')
            ->selectRaw('sum(coalesce(charge.total, item.unit_price, 0)) as revenue')
            ->selectRaw('sum(coalesce(cost.total, 0)) as cost')
            ->selectRaw('sum(coalesce(cost.uncosted, 0)) as uncosted')
            ->get()
            ->map(fn (object $row) => (object) [
                'procedure_id' => (int) $row->procedure_id,
                'name' => $row->name,
                'treatments' => (int) $row->treatments,
                'revenue' => (float) $row->revenue,
                'cost' => (float) $row->cost,
                'uncosted' => (int) $row->uncosted,
            ]);
    }
}
