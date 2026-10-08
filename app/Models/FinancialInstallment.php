<?php

namespace App\Models;

use App\Support\ClinicTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialInstallment extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'amount' => 'decimal:2',
            'due_date' => 'immutable_date',
            'paid_at' => 'immutable_date',
        ];
    }

    /**
     * @return BelongsTo<FinancialEntry, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(FinancialEntry::class, 'financial_entry_id');
    }

    public function isPaid(): bool
    {
        return $this->paid_at !== null;
    }

    public function isOverdue(): bool
    {
        return ! $this->isPaid() && $this->due_date->format('Y-m-d') < ClinicTime::today();
    }

    /**
     * Texto e cores do selo de situação, usados nas listagens.
     *
     * @return array{label: string, classes: string}
     */
    public function statusBadge(): array
    {
        return match (true) {
            $this->isPaid() => ['label' => 'Pago', 'classes' => 'bg-emerald-100 text-emerald-800'],
            $this->isOverdue() => ['label' => 'Vencido', 'classes' => 'bg-red-100 text-red-800'],
            default => ['label' => 'Pendente', 'classes' => 'bg-amber-100 text-amber-800'],
        };
    }
}
