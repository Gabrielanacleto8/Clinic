<?php

namespace App\Models;

use App\Enums\AttachmentType;
use App\Enums\PhotoStage;
use App\Support\ClinicTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Foto ou documento de um paciente. O arquivo fica criptografado no disco
 * privado "patient_photos"; aqui ficam apenas os metadados.
 *
 * Uma foto pode ser de um atendimento registrado ou avulsa (retroativa, com
 * data própria em taken_on). Documento é sempre de um atendimento.
 */
#[Fillable(['stage', 'caption', 'taken_on'])]
class PatientAttachment extends Model
{
    use SoftDeletes;

    /**
     * Caminhos internos não saem em serializações.
     *
     * @var list<string>
     */
    protected $hidden = ['path', 'thumbnail_path'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AttachmentType::class,
            'stage' => PhotoStage::class,
            'taken_on' => 'immutable_date',
        ];
    }

    /**
     * @param  Builder<PatientAttachment>  $query
     */
    public function scopePhotos(Builder $query): void
    {
        $query->where('patient_attachments.type', AttachmentType::Photo);
    }

    /**
     * @param  Builder<PatientAttachment>  $query
     */
    public function scopeDocuments(Builder $query): void
    {
        $query->where('patient_attachments.type', AttachmentType::Document);
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
     * Dia em que a foto foi feita, no calendário da clínica: o do atendimento
     * ou, sem ele, o informado no envio.
     */
    public function takenOn(): CarbonImmutable
    {
        return $this->treatment ? ClinicTime::local($this->treatment->performed_at)->startOfDay() : $this->taken_on;
    }
}
