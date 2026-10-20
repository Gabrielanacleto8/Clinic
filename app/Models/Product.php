<?php

namespace App\Models;

use App\Enums\ProductCategory;
use App\Enums\ProductUnit;
use App\Support\ClinicTime;
use App\Support\Like;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
            'cost' => 'decimal:2',
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
     * Acrescenta "available_quantity": a soma dos lotes ainda dentro da validade.
     *
     * @param  Builder<Product>  $query
     */
    public function scopeWithAvailableQuantity(Builder $query): void
    {
        $query->withSum(['batches as available_quantity' => fn (Builder $batches) => $batches->usable()], 'quantity');
    }

    /**
     * Produtos cujo saldo utilizável chegou ao estoque mínimo configurado.
     *
     * @param  Builder<Product>  $query
     */
    public function scopeLowStock(Builder $query): void
    {
        $query->where('is_active', true)
            ->where('minimum_stock', '>', 0)
            ->whereRaw(
                '(select coalesce(sum(quantity), 0) from product_batches'
                .' where product_batches.product_id = products.id and (expires_on is null or expires_on >= ?)) <= minimum_stock',
                [ClinicTime::today()],
            );
    }

    public function isLowOnStock(): bool
    {
        return $this->is_active
            && (float) $this->minimum_stock > 0
            && (float) ($this->available_quantity ?? 0) <= (float) $this->minimum_stock;
    }
}
