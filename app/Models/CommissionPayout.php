<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Repasse de comissão a um profissional: o que foi pago por atendimento e a
 * despesa lançada no financeiro. Gravado só por CommissionPayouts.
 */
class CommissionPayout extends Model
{
    protected $fillable = ['until'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['until' => 'immutable_date'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professional_id');
    }

    /**
     * @return HasMany<CommissionPayoutItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CommissionPayoutItem::class);
    }

    /**
     * @return HasOne<FinancialEntry, $this>
     */
    public function entry(): HasOne
    {
        return $this->hasOne(FinancialEntry::class);
    }
}
