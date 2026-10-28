<?php

namespace App\Models;

use App\Enums\TreatmentPlanStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Procedimento de um orçamento: quantas sessões e quanto vale cada uma.
 */
class TreatmentPlanItem extends Model
{
    public $timestamps = false;

    protected $fillable = ['procedure_id', 'sessions', 'unit_price'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sessions' => 'integer',
            'unit_price' => 'decimal:2',
        ];
    }

    /**
     * Acrescenta "used_sessions": atendimentos não anulados que usaram este item.
     *
     * @param  Builder<TreatmentPlanItem>  $query
     */
    public function scopeWithUsedSessions(Builder $query): void
    {
        $query->withCount(['treatments as used_sessions' => fn ($treatments) => $treatments->whereNull('voided_at')]);
    }

    /**
     * Itens de pacotes aprovados do paciente que ainda têm sessão, com "used_sessions".
     *
     * @param  Builder<TreatmentPlanItem>  $query
     */
    public function scopeAvailableFor(Builder $query, int $patientId): void
    {
        $query->withUsedSessions()
            ->whereHas('plan', fn ($plan) => $plan->where('patient_id', $patientId)->where('status', TreatmentPlanStatus::Approved))
            ->whereRaw('sessions > (select count(*) from treatments where treatments.treatment_plan_item_id = treatment_plan_items.id and treatments.voided_at is null and treatments.deleted_at is null)');
    }

    public function remainingSessions(): int
    {
        return max(0, $this->sessions - (int) $this->used_sessions);
    }

    /**
     * Como o item aparece na escolha de sessão do registro do atendimento.
     */
    public function sessionLabel(): string
    {
        return sprintf('%s · pacote nº %d · restam %d de %d', $this->procedure->name, $this->treatment_plan_id, $this->remainingSessions(), $this->sessions);
    }

    /**
     * @return BelongsTo<TreatmentPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(TreatmentPlan::class, 'treatment_plan_id');
    }

    /**
     * @return BelongsTo<Procedure, $this>
     */
    public function procedure(): BelongsTo
    {
        return $this->belongsTo(Procedure::class);
    }

    /**
     * @return HasMany<Treatment, $this>
     */
    public function treatments(): HasMany
    {
        return $this->hasMany(Treatment::class);
    }
}
