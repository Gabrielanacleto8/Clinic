<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Regra de comissão de um profissional: sem procedimento, o % padrão; com
 * procedimento, a exceção para ele. Gravada só por CommissionRules.
 */
class CommissionRate extends Model
{
    protected $fillable = ['procedure_id', 'rate'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['rate' => 'decimal:2'];
    }

    /**
     * @return BelongsTo<Procedure, $this>
     */
    public function procedure(): BelongsTo
    {
        return $this->belongsTo(Procedure::class);
    }
}
