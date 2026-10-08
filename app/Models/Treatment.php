<?php

namespace App\Models;

use App\Enums\AttachmentType;
use App\Enums\TreatmentStatus;
use Database\Factories\TreatmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Registro de um atendimento: o procedimento feito, por quem, quando e com
 * quais produtos. Nasce como rascunho e, ao ser finalizado, vira histórico
 * permanente. Paciente, profissional, procedimento, autor e situação ficam
 * fora do mass assignment: são definidos pelo controller, nunca pelo formulário.
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
            'status' => TreatmentStatus::class,
            'performed_at' => 'immutable_datetime',
            'finalized_at' => 'immutable_datetime',
            'return_date' => 'immutable_date',
            'assessment' => 'encrypted',
            'description' => 'encrypted',
            'post_care' => 'encrypted',
            'complications' => 'encrypted',
        ];
    }

    public function isDraft(): bool
    {
        return $this->status === TreatmentStatus::Draft;
    }

    public function isFinalized(): bool
    {
        return $this->status === TreatmentStatus::Finalized;
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
     * Lançamento financeiro gerado na finalização, quando houve cobrança.
     *
     * @return HasOne<FinancialEntry, $this>
     */
    public function financialEntry(): HasOne
    {
        return $this->hasOne(FinancialEntry::class);
    }

    /**
     * Agendamento que deu origem a este atendimento, se houver.
     *
     * @return HasOne<Appointment, $this>
     */
    public function appointment(): HasOne
    {
        return $this->hasOne(Appointment::class);
    }
}
