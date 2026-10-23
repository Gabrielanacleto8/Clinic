<?php

namespace App\Models;

use App\Enums\ProductCategory;
use App\Enums\ProductUnit;
use App\Support\Like;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

#[Fillable(['name', 'code', 'category', 'brand', 'manufacturer', 'supplier', 'unit', 'unit_content', 'hours_after_opening', 'multi_patient_use', 'minimum_stock', 'cost', 'price', 'is_active'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => ProductCategory::class,
            'unit' => ProductUnit::class,
            'unit_content' => 'decimal:3',
            'hours_after_opening' => 'integer',
            'multi_patient_use' => 'boolean',
            'minimum_stock' => 'decimal:3',
            'cost' => 'decimal:4',
            'price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<ProductBatch, $this>
     */
    public function batches(): HasMany
    {
        return $this->hasMany(ProductBatch::class);
    }

    /**
     * Unidades físicas abertas, de qualquer lote do produto.
     *
     * @return HasManyThrough<StockUnit, ProductBatch, $this>
     */
    public function units(): HasManyThrough
    {
        return $this->hasManyThrough(StockUnit::class, ProductBatch::class);
    }

    /**
     * Fracionado: usado aos poucos, por unidade física aberta (frasco,
     * seringa, ampola), e não direto do lote.
     */
    public function isFractioned(): bool
    {
        return $this->unit_content !== null;
    }

    /**
     * Busca por nome ou código.
     *
     * @param  Builder<Product>  $query
     */
    public function scopeSearch(Builder $query, string $term): void
    {
        $like = Like::contains($term);

        $query->where(fn (Builder $inner) => $inner->where('name', 'like', $like)->orWhere('code', 'like', $like));
    }

    /**
     * Acrescenta "available_quantity", o que pode ir para paciente: a soma
     * dos lotes dentro da validade menos o que resta em unidades abertas que
     * aguardam descarte ("discard_quantity", também acrescentada).
     *
     * @param  Builder<Product>  $query
     */
    public function scopeWithAvailableQuantity(Builder $query): void
    {
        if ($query->getQuery()->columns === null) {
            $query->select('products.*');
        }

        [$available, $discard] = self::quantitySubqueries();

        $query
            ->selectRaw("(({$available->toSql()}) - ({$discard->toSql()})) as available_quantity", [...$available->getBindings(), ...$discard->getBindings()])
            ->selectRaw("({$discard->toSql()}) as discard_quantity", $discard->getBindings());
    }

    /**
     * Produtos cujo saldo utilizável chegou ao estoque mínimo configurado.
     *
     * @param  Builder<Product>  $query
     */
    public function scopeLowStock(Builder $query): void
    {
        [$available, $discard] = self::quantitySubqueries();

        $query->where('is_active', true)
            ->where('minimum_stock', '>', 0)
            ->whereRaw("(({$available->toSql()}) - ({$discard->toSql()})) <= minimum_stock", [...$available->getBindings(), ...$discard->getBindings()]);
    }

    /**
     * Subconsultas, correlacionadas a "products", do saldo dentro da validade
     * e do que aguarda descarte em unidades abertas de lotes ainda válidos
     * (as de lote vencido já ficam fora do saldo). Uma regra só para a
     * listagem, o alerta de estoque baixo e o Início.
     *
     * @return array{0: \Illuminate\Database\Query\Builder, 1: \Illuminate\Database\Query\Builder}
     */
    private static function quantitySubqueries(): array
    {
        $available = ProductBatch::query()->usable()
            ->whereColumn('product_batches.product_id', 'products.id')
            ->selectRaw('coalesce(sum(product_batches.quantity), 0)')
            ->toBase();

        $discard = StockUnit::query()->awaitingDiscard()
            ->whereHas('batch', fn (Builder $batch) => $batch->usable()->whereColumn('product_batches.product_id', 'products.id'))
            ->selectRaw('coalesce(sum(stock_units.quantity), 0)')
            ->toBase();

        return [$available, $discard];
    }

    public function isLowOnStock(): bool
    {
        return $this->is_active
            && (float) $this->minimum_stock > 0
            && (float) ($this->available_quantity ?? 0) <= (float) $this->minimum_stock;
    }
}
