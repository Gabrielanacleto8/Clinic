<?php

namespace App\Models;

use App\Enums\FinancialCategory;
use App\Enums\FinancialEntryType;
use App\Enums\PaymentMethod;
use Database\Factories\FinancialEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lançamento financeiro (receita ou despesa). Criado só pelo FinancialRecorder,
 * que grava o lançamento e as parcelas juntos.
 */
class FinancialEntry extends Model
{
    /** @use HasFactory<FinancialEntryFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => FinancialEntryType::class,
            'category' => FinancialCategory::class,
            'payment_method' => PaymentMethod::class,
            'issued_on' => 'immutable_date',
        ];
    }

    /**
     * @return HasMany<FinancialInstallment, $this>
     */
    public function installments(): HasMany
    {
        return $this->hasMany(FinancialInstallment::class);
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * @return BelongsTo<Treatment, $this>
     */
    public function treatment(): BelongsTo
    {
        return $this->belongsTo(Treatment::class);
    }
}
