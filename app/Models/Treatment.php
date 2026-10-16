<?php

namespace App\Models;

use App\Enums\AttachmentType;
use App\Support\ClinicTime;
use Database\Factories\TreatmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Registro de um atendimento: o procedimento feito, por quem, quando e com
 * quais produtos. É gravado por TreatmentRecorder já com a baixa de estoque e
 * o financeiro, e desde então é histórico permanente: informação nova entra
 * em TreatmentNote. Paciente, profissional, procedimento e autor ficam fora
 * do mass assignment: são definidos pelo controller, nunca pelo formulário.
 */
#[Fillable(['performed_at', 'treated_areas', 'assessment', 'description', 'post_care', 'complications', 'return_date'])]
class Treatment extends Model
{
    /** @use HasFactory<TreatmentFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $hidden = ['assessment', 'description', 'post_care', 'complications'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'performed_at' => 'immutable_datetime',
            'return_date' => 'immutable_date',
            'assessment' => 'encrypted',
            'description' => 'encrypted',
            'post_care' => 'encrypted',
            'complications' => 'encrypted',
        ];
    }

    /**
     * Atendimentos feitos entre dois dias do calendário da clínica. Qualquer dos limites pode faltar.
     *
     * @param  Builder<Treatment>  $query
     */
    public function scopePerformedBetween(Builder $query, ?string $from, ?string $until): void
    {
        $query
            ->when($from, fn (Builder $inner, string $day) => $inner->where('performed_at', '>=', ClinicTime::dayBounds($day)[0]))
            ->when($until, fn (Builder $inner, string $day) => $inner->where('performed_at', '<=', ClinicTime::dayBounds($day)[1]));
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professional_id');
    }

    /**
     * @return BelongsTo<Procedure, $this>
     */
    public function procedure(): BelongsTo
    {
        return $this->belongsTo(Procedure::class);
    }

    /**
     * @return HasMany<TreatmentProduct, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(TreatmentProduct::class);
    }

    /**
     * @return HasMany<TreatmentAttachment, $this>
     */
    public function photos(): HasMany
    {
        return $this->hasMany(TreatmentAttachment::class)->where('type', AttachmentType::Photo);
    }

    /**
     * @return HasMany<TreatmentAttachment, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(TreatmentAttachment::class)->where('type', AttachmentType::Document);
    }

    /**
     * Evoluções e adendos, do mais antigo ao mais recente.
     *
     * @return HasMany<TreatmentNote, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(TreatmentNote::class)->orderBy('id');
    }

    /**
     * Lançamento financeiro gerado no registro, quando houve cobrança.
     *
     * @return HasOne<FinancialEntry, $this>
     */
    public function financialEntry(): HasOne
    {
        return $this->hasOne(FinancialEntry::class);
    }

    /**
     * Agendamento que este atendimento encerrou, se houver.
     *
     * @return HasOne<Appointment, $this>
     */
    public function appointment(): HasOne
    {
        return $this->hasOne(Appointment::class);
    }
}
