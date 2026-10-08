<?php

namespace App\Models;

use App\Support\ClinicTime;
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
     * Lotes dentro da validade (ou sem validade).
     *
     * @param  Builder<ProductBatch>  $query
     */
    public function scopeUsable(Builder $query): void
    {
        $query->where(fn (Builder $inner) => $inner->whereNull('expires_on')->orWhere('expires_on', '>=', ClinicTime::today()));
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
