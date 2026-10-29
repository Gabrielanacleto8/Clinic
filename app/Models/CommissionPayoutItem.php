<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommissionPayoutItem extends Model
{
    public $timestamps = false;

    protected $fillable = ['treatment_id', 'base', 'rate', 'amount'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['base' => 'decimal:2', 'rate' => 'decimal:2', 'amount' => 'decimal:2'];
    }

    /**
     * @return BelongsTo<CommissionPayout, $this>
     */
    public function payout(): BelongsTo
    {
        return $this->belongsTo(CommissionPayout::class, 'commission_payout_id');
    }
}
