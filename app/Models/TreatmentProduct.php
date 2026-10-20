<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Produto usado em um atendimento, identificado pelo lote do estoque e, no
 * fracionado, pela unidade física. Nome, unidade e validade vêm do lote e do
 * produto: não são copiados para cá.
 */
#[Fillable(['product_batch_id', 'stock_unit_id', 'quantity'])]
class TreatmentProduct extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['quantity' => 'decimal:3'];
    }

    /**
     * @return BelongsTo<Treatment, $this>
     */
    public function treatment(): BelongsTo
    {
        return $this->belongsTo(Treatment::class);
    }

    /**
     * @return BelongsTo<ProductBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class, 'product_batch_id');
    }

    /**
     * Unidade física usada, no produto fracionado.
     *
     * @return BelongsTo<StockUnit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(StockUnit::class, 'stock_unit_id');
    }
}
