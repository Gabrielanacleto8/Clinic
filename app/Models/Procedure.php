<?php

namespace App\Models;

use App\Enums\ProcedureCategory;
use Database\Factories\ProcedureFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Item do catálogo de procedimentos da clínica.
 */
#[Fillable(['name', 'description', 'consent_template', 'category', 'price', 'duration_minutes', 'is_active'])]
class Procedure extends Model
{
    /** @use HasFactory<ProcedureFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => ProcedureCategory::class,
            'price' => 'decimal:2',
            'duration_minutes' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Produtos normalmente usados neste procedimento, com a quantidade estimada no pivô.
     *
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class)->withPivot('quantity');
    }

    /**
     * Procedimentos que podem ser escolhidos em um formulário: os ativos e,
     * ao editar um registro antigo, também o que ele já usa.
     *
     * @param  Builder<Procedure>  $query
     */
    public function scopeSelectable(Builder $query, ?int $currentId = null): void
    {
        $query->where(fn (Builder $inner) => $inner->where('is_active', true)->when($currentId, fn (Builder $q) => $q->orWhere('id', $currentId)));
    }
}
