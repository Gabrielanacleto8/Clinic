<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Fechamento do caixa de um dia: o esperado e o conferido em cada forma de
 * pagamento. Gravado só por CashRegister. Dia fechado não recebe pagamento
 * novo nem desfeito até o administrador reabrir.
 */
class CashClosing extends Model
{
    protected $fillable = ['day', 'notes'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['day' => 'immutable_date'];
    }

    /**
     * @return HasMany<CashClosingLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(CashClosingLine::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * Soma das diferenças (conferido menos esperado). Espera as linhas carregadas.
     */
    public function difference(): float
    {
        return round($this->lines->sum(fn (CashClosingLine $line) => $line->difference()), 2);
    }
}
