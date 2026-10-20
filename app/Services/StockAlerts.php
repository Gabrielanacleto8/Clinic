<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockUnit;
use Illuminate\Support\Facades\DB;

/**
 * Alertas exibidos no topo do módulo de estoque.
 */
class StockAlerts
{
    /**
     * As contagens em uma única consulta.
     *
     * @return array{low_stock: int, expiring: int, expired: int, units_to_discard: int}
     */
    public function summary(): array
    {
        $counts = DB::query()
            ->selectSub(Product::query()->lowStock()->selectRaw('count(*)'), 'low_stock')
            ->selectSub(ProductBatch::query()->expiringSoon()->selectRaw('count(*)'), 'expiring')
            ->selectSub(ProductBatch::query()->expiredWithStock()->selectRaw('count(*)'), 'expired')
            ->selectSub(StockUnit::query()->awaitingDiscard()->selectRaw('count(*)'), 'units_to_discard')
            ->first();

        return [
            'low_stock' => (int) $counts->low_stock,
            'expiring' => (int) $counts->expiring,
            'expired' => (int) $counts->expired,
            'units_to_discard' => (int) $counts->units_to_discard,
        ];
    }
}
