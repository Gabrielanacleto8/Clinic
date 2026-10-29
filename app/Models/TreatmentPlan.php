<?php

namespace App\Models;

use App\Enums\TreatmentPlanStatus;
use App\Support\ClinicTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Orçamento de um paciente. Aprovado, vira o pacote: as sessões de cada item
 * são usadas no registro dos atendimentos, sem nova cobrança. Situação,
 * datas e autores são definidos só pelo TreatmentPlanApproval.
 */
class TreatmentPlan extends Model
{
    protected $fillable = ['valid_until', 'notes'];

    /**
     * Observações podem citar dado de saúde.
     *
     * @var list<string>
     */
    protected $hidden = ['notes'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TreatmentPlanStatus::class,
            'valid_until' => 'immutable_date',
            'notes' => 'encrypted',
            'approved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return $this->status === TreatmentPlanStatus::Open;
    }

    public function isApproved(): bool
    {
        return $this->status === TreatmentPlanStatus::Approved;
    }

    /**
     * Virou venda: pacote ativo ou encerrado. Em aberto e recusado nunca foram vendidos.
     */
    public function wasSold(): bool
    {
        return in_array($this->status, [TreatmentPlanStatus::Approved, TreatmentPlanStatus::Closed], true);
    }

    /**
     * Como o registro é chamado na tela: o orçamento vendido é o pacote.
     */
    public function kindLabel(): string
    {
        return $this->wasSold() ? 'Pacote' : 'Orçamento';
    }

    /**
     * Orçamento em aberto cuja validade passou: precisa de nova data antes de aprovar.
     */
    public function isExpired(): bool
    {
        return $this->isOpen() && $this->valid_until !== null && $this->valid_until->format('Y-m-d') < ClinicTime::today();
    }

    /**
     * Soma dos itens. Espera os itens carregados.
     */
    public function total(): string
    {
        $cents = $this->items->sum(fn (TreatmentPlanItem $item) => $item->sessions * (int) round((float) $item->unit_price * 100));

        return number_format($cents / 100, 2, '.', '');
    }

    /**
     * Total pelo preço de tabela de cada item. Item sem preço de tabela conta pelo valor vendido.
     */
    public function listTotal(): string
    {
        $cents = $this->items->sum(fn (TreatmentPlanItem $item) => $item->sessions * (int) round((float) ($item->list_price ?? $item->unit_price) * 100));

        return number_format($cents / 100, 2, '.', '');
    }

    /**
     * Desconto do orçamento: o total de tabela menos o total vendido, nunca negativo.
     */
    public function discount(): string
    {
        return number_format(max(0, (float) $this->listTotal() - (float) $this->total()), 2, '.', '');
    }

    /**
     * Sessões ainda por usar, somando os itens. Espera os itens com "used_sessions".
     */
    public function remainingSessions(): int
    {
        return $this->items->sum(fn (TreatmentPlanItem $item) => $item->remainingSessions());
    }

    /**
     * @param  Builder<TreatmentPlan>  $query
     */
    public function scopeWithUsage(Builder $query): void
    {
        $query->with(['items' => fn ($items) => $items->withUsedSessions()->with('procedure:id,name')]);
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * @return HasMany<TreatmentPlanItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(TreatmentPlanItem::class)->orderBy('id');
    }

    /**
     * @return HasOne<FinancialEntry, $this>
     */
    public function financialEntry(): HasOne
    {
        return $this->hasOne(FinancialEntry::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
