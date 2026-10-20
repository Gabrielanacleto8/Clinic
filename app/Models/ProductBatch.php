<?php

namespace App\Models;

use App\Support\ClinicTime;
use App\Support\Like;
use App\Support\Quantity;
use Carbon\CarbonImmutable;
use Database\Factories\ProductBatchFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lote de um produto. O saldo só muda por StockLedger, nunca por atribuição direta.
 */
class ProductBatch extends Model
{
    /** @use HasFactory<ProductBatchFactory> */
    use HasFactory;

    protected $guarded = ['quantity'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'manufactured_on' => 'immutable_date',
            'expires_on' => 'immutable_date',
            'quantity' => 'decimal:3',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return HasMany<StockMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * Unidades físicas abertas deste lote.
     *
     * @return HasMany<StockUnit, $this>
     */
    public function units(): HasMany
    {
        return $this->hasMany(StockUnit::class);
    }

    /**
     * Parte do saldo que ainda está lacrada: o saldo do lote menos o que
     * resta nas unidades abertas. Espera "open_quantity" (withOpenQuantity)
     * ou soma as unidades na hora.
     */
    public function sealedQuantity(): string
    {
        $open = array_key_exists('open_quantity', $this->getAttributes())
            ? ($this->open_quantity ?? 0)
            : $this->units()->withBalance()->sum('quantity');

        return Quantity::fromThousandths(Quantity::toThousandths($this->quantity) - Quantity::toThousandths($open));
    }

    /**
     * Acrescenta "open_quantity": o que resta nas unidades abertas do lote.
     *
     * @param  Builder<ProductBatch>  $query
     */
    public function scopeWithOpenQuantity(Builder $query): void
    {
        $query->withSum(['units as open_quantity' => fn (Builder $units) => $units->withBalance()], 'quantity');
    }

    /**
     * Lotes dentro da validade (ou sem validade).
     *
     * @param  Builder<ProductBatch>  $query
     */
    public function scopeUsable(Builder $query): void
    {
        $query->where(fn (Builder $inner) => $inner->whereNull('expires_on')->orWhere('expires_on', '>=', ClinicTime::today()));
    }

    /**
     * Busca pelo código do lote ou pelo nome ou código do produto.
     *
     * @param  Builder<ProductBatch>  $query
     */
    public function scopeSearch(Builder $query, string $term): void
    {
        $query->where(fn (Builder $inner) => $inner
            ->where('batch_code', 'like', Like::contains($term))
            ->orWhereHas('product', fn (Builder $product) => $product->search($term)));
    }

    /**
     * Ordem de uso: vence primeiro, sai primeiro. Lotes sem validade ficam por último.
     *
     * @param  Builder<ProductBatch>  $query
     */
    public function scopeFirstToExpire(Builder $query): void
    {
        $query->orderByRaw('expires_on is null')->orderBy('expires_on')->orderBy('id');
    }

    /**
     * Lotes que podem ser aplicados em um atendimento: dentro da validade,
     * com saldo e de produto ativo.
     *
     * @param  Builder<ProductBatch>  $query
     */
    public function scopeAvailableForUse(Builder $query): void
    {
        $query->usable()->where('quantity', '>', 0)->whereHas('product', fn (Builder $product) => $product->where('is_active', true));
    }

    /**
     * Lotes com saldo que vencem dentro do prazo de alerta configurado.
     *
     * @param  Builder<ProductBatch>  $query
     */
    public function scopeExpiringSoon(Builder $query): void
    {
        $today = ClinicTime::today();
        $limit = ClinicTime::local(now())->addDays((int) config('clinic.stock.expiry_alert_days'))->format('Y-m-d');

        $query->where('quantity', '>', 0)->whereBetween('expires_on', [$today, $limit]);
    }

    /**
     * Lotes vencidos que ainda têm saldo e precisam ser descartados.
     *
     * @param  Builder<ProductBatch>  $query
     */
    public function scopeExpiredWithStock(Builder $query): void
    {
        $query->where('quantity', '>', 0)->where('expires_on', '<', ClinicTime::today());
    }

    public function isExpired(): bool
    {
        return $this->expires_on !== null && $this->expires_on->format('Y-m-d') < ClinicTime::today();
    }

    /**
     * Dias até o vencimento; negativo se já venceu, nulo se o lote não tem validade.
     */
    public function daysToExpire(): ?int
    {
        if ($this->expires_on === null) {
            return null;
        }

        // As duas datas são comparadas como dias do calendário da clínica, sem horário.
        return (int) CarbonImmutable::parse(ClinicTime::today())->diffInDays(CarbonImmutable::parse($this->expires_on->format('Y-m-d')), false);
    }

    public function expiresSoon(): bool
    {
        $days = $this->daysToExpire();

        return $days !== null && $days >= 0 && $days <= (int) config('clinic.stock.expiry_alert_days');
    }
}
