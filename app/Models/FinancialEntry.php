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
     * Alguma parcela já foi paga. Usa o atributo "has_paid_installment"
     * quando a listagem o trouxe por withExists(), para não consultar por linha.
     */
    public function hasPaidInstallment(): bool
    {
        if (array_key_exists('has_paid_installment', $this->getAttributes())) {
            return (bool) $this->getAttributes()['has_paid_installment'];
        }

        return $this->installments()->whereNotNull('paid_at')->exists();
    }

    /**
     * Soma das parcelas: o valor total do lançamento. Usa "total_amount" da
     * listagem (withSum) quando houver.
     */
    public function total(): string
    {
        $total = array_key_exists('total_amount', $this->getAttributes())
            ? $this->getAttributes()['total_amount']
            : $this->installments()->sum('amount');

        return number_format((float) $total, 2, '.', '');
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
