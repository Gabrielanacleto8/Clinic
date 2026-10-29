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
            'discount' => 'decimal:2',
        ];
    }

    /**
     * Gerado pelo sistema (atendimento, aprovação de orçamento, estorno ou
     * repasse de comissão): faz parte do histórico de outro registro e não é
     * excluído nem editado à mão.
     */
    public function isGenerated(): bool
    {
        return $this->treatment_id !== null || $this->treatment_plan_id !== null
            || $this->refund_of_id !== null || $this->commission_payout_id !== null;
    }

    /**
     * Cobrança de paciente (atendimento, pacote ou receita manual com
     * paciente): a que pode receber estorno.
     */
    public function isPatientCharge(): bool
    {
        return $this->type === FinancialEntryType::Income && $this->patient_id !== null;
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

    /**
     * @return BelongsTo<TreatmentPlan, $this>
     */
    public function treatmentPlan(): BelongsTo
    {
        return $this->belongsTo(TreatmentPlan::class);
    }

    /**
     * Estornos feitos sobre esta cobrança.
     *
     * @return HasMany<FinancialEntry, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(FinancialEntry::class, 'refund_of_id');
    }

    /**
     * Cobrança que este estorno devolve.
     *
     * @return BelongsTo<FinancialEntry, $this>
     */
    public function refundOf(): BelongsTo
    {
        return $this->belongsTo(FinancialEntry::class, 'refund_of_id');
    }

    /**
     * @return BelongsTo<CommissionPayout, $this>
     */
    public function commissionPayout(): BelongsTo
    {
        return $this->belongsTo(CommissionPayout::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
