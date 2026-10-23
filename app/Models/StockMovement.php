<?php

namespace App\Models;

use App\Enums\StockMovementType;
use App\Support\ClinicTime;
use App\Support\Like;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use LogicException;

/**
 * Linha do livro de estoque. Só é inserida, nunca alterada: um erro se
 * corrige com uma nova movimentação.
 */
class StockMovement extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => StockMovementType::class,
            'quantity' => 'decimal:3',
            'unit_cost' => 'decimal:4',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Movimentações de estoque são imutáveis.'));
        static::deleting(fn () => throw new LogicException('Movimentações de estoque são imutáveis.'));
    }

    /**
     * Busca por produto, lote, fornecedor ou nota fiscal.
     *
     * @param  Builder<StockMovement>  $query
     */
    public function scopeSearch(Builder $query, string $term): void
    {
        $like = Like::contains($term);

        $query->where(fn (Builder $inner) => $inner
            ->where('supplier', 'like', $like)
            ->orWhere('invoice_number', 'like', $like)
            ->orWhereHas('batch', fn (Builder $batch) => $batch->search($term)));
    }

    /**
     * Consumos que não foram estornados pela anulação do atendimento.
     *
     * @param  Builder<StockMovement>  $query
     */
    public function scopeUnreversedConsumption(Builder $query): void
    {
        self::whereUnreversedConsumption($query->getQuery());
    }

    /**
     * Mesma regra de scopeUnreversedConsumption() para uma consulta sobre
     * "stock_movements" montada sem o modelo.
     */
    public static function whereUnreversedConsumption(QueryBuilder $query): void
    {
        $query->where('stock_movements.type', StockMovementType::Consumption->value)
            ->whereNotExists(fn ($reversal) => $reversal->selectRaw('1')->from('stock_movements as reversals')
                ->where('reversals.type', StockMovementType::Reversal->value)
                ->whereColumn('reversals.treatment_id', 'stock_movements.treatment_id')
                ->whereColumn('reversals.product_batch_id', 'stock_movements.product_batch_id')
                ->whereColumn('reversals.stock_unit_key', 'stock_movements.stock_unit_key'));
    }

    /**
     * Movimentações lançadas entre dois dias do calendário da clínica (Y-m-d), inclusive.
     *
     * @param  Builder<StockMovement>  $query
     */
    public function scopeInPeriod(Builder $query, ?string $from, ?string $until): void
    {
        $query
            ->when($from, fn (Builder $inner, string $day) => $inner->where('created_at', '>=', ClinicTime::dayBounds($day)[0]))
            ->when($until, fn (Builder $inner, string $day) => $inner->where('created_at', '<=', ClinicTime::dayBounds($day)[1]));
    }

    /**
     * @return BelongsTo<ProductBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class, 'product_batch_id');
    }

    /**
     * Unidade física de onde saiu, no produto fracionado.
     *
     * @return BelongsTo<StockUnit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(StockUnit::class, 'stock_unit_id');
    }

    /**
     * Valor do que saiu, pelo custo médio do lote no momento da baixa.
     */
    public function value(): ?string
    {
        return $this->unit_cost === null ? null : number_format(abs((float) $this->quantity) * (float) $this->unit_cost, 2, '.', '');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Treatment, $this>
     */
    public function treatment(): BelongsTo
    {
        return $this->belongsTo(Treatment::class);
    }
}
