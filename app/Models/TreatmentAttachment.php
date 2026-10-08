<?php

namespace App\Models;

use App\Enums\AttachmentType;
use App\Enums\PhotoStage;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Foto ou documento de um atendimento. O arquivo fica criptografado no disco
 * privado "patient_photos"; aqui ficam apenas os metadados.
 */
#[Fillable(['stage', 'caption'])]
class TreatmentAttachment extends Model
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
        ];
    }

    /**
     * @return BelongsTo<Treatment, $this>
     */
    public function treatment(): BelongsTo
    {
        return $this->belongsTo(Treatment::class);
    }
}
