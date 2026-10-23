<?php

namespace App\Models;

use App\Support\ClinicTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Unidade física aberta de um produto fracionado (frasco, seringa, ampola).
 * Nasce só por StockLedger::openUnit() e o saldo só muda por StockLedger.
 */
class StockUnit extends Model
{
    public const CREATED_AT = 'opened_at';

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'initial_quantity' => 'decimal:3',
            'quantity' => 'decimal:3',
            'opened_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ProductBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class, 'product_batch_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    /**
     * @return HasMany<StockMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * Unidades que ainda têm conteúdo, usáveis ou aguardando descarte.
     *
     * @param  Builder<StockUnit>  $query
     */
    public function scopeWithBalance(Builder $query): void
    {
        $query->where($query->qualifyColumn('quantity'), '>', 0);
    }

    /**
     * Acrescenta "was_consumed": se a unidade já foi usada em algum
     * atendimento que não foi anulado.
     *
     * @param  Builder<StockUnit>  $query
     */
    public function scopeWithConsumption(Builder $query): void
    {
        $query->withExists(['movements as was_consumed' => fn (Builder $movements) => $movements->unreversedConsumption()]);
    }

    /**
     * Já foi usada em algum atendimento que não foi anulado. Usa
     * "was_consumed" (withConsumption) ou consulta na hora.
     */
    public function wasConsumed(): bool
    {
        return array_key_exists('was_consumed', $this->getAttributes())
            ? (bool) $this->was_consumed
            : $this->movements()->unreversedConsumption()->exists();
    }

    /**
     * Unidades com conteúdo que não pode mais ir para paciente: lote vencido,
     * produto inativo, prazo depois de aberta esgotado ou uso em um só
     * paciente que já foi usada. Mesma regra de unusableReason(), em SQL, para os alertas.
     *
     * @param  Builder<StockUnit>  $query
     */
    public function scopeAwaitingDiscard(Builder $query): void
    {
        $query->withBalance()->whereHas('batch', fn (Builder $batch) => $batch->where(fn (Builder $inner) => $inner
            ->where('expires_on', '<', ClinicTime::today())
            ->orWhereHas('product', fn (Builder $product) => $product->where(fn (Builder $rule) => $rule
                ->where('is_active', false)
                ->orWhereRaw('date_add(stock_units.opened_at, interval products.hours_after_opening hour) <= ?', [now()])
                ->orWhere(fn (Builder $singleUse) => $singleUse
                    ->where('multi_patient_use', false)
                    ->whereExists(function ($consumed): void {
                        $consumed->selectRaw('1')->from('stock_movements')->whereColumn('stock_movements.stock_unit_id', 'stock_units.id');
                        StockMovement::whereUnreversedConsumption($consumed);
                    }))))));
    }

    /**
     * Fim do prazo de uso depois de aberta, pela instrução do fabricante
     * cadastrada no produto. Nulo quando o produto não tem esse prazo.
     */
    public function usableUntil(): ?CarbonImmutable
    {
        $hours = $this->batch->product->hours_after_opening;

        return $hours === null ? null : $this->opened_at->addHours($hours);
    }

    public function isPastOpeningLimit(): bool
    {
        return $this->usableUntil()?->isPast() ?? false;
    }

    /**
     * Motivo pelo qual a unidade não pode ir para um paciente, ou nulo se pode.
     */
    public function unusableReason(): ?string
    {
        $batch = $this->batch;

        return match (true) {
            (float) $this->quantity <= 0 => 'está esgotada',
            ! $batch->product->is_active => 'é de um produto inativo',
            $batch->isExpired() => 'é de um lote vencido',
            $this->isPastOpeningLimit() => 'passou do prazo de uso depois de aberta ('.ClinicTime::format($this->usableUntil()).')',
            // A sobra de produto de uso em um só paciente não vai para outro,
            // mesmo que o cadastro tenha mudado depois que a unidade foi usada.
            ! $batch->product->multi_patient_use && $this->wasConsumed() => 'já foi usada em um atendimento e o produto é de uso em um só paciente',
            default => null,
        };
    }

    public function isUsable(): bool
    {
        return $this->unusableReason() === null;
    }

    /**
     * Ainda tem conteúdo, mas não pode mais ser usada: precisa ser descartada.
     */
    public function awaitsDiscard(): bool
    {
        return (float) $this->quantity > 0 && ! $this->isUsable();
    }

    public function label(): string
    {
        return 'Unidade nº '.$this->id;
    }
}
