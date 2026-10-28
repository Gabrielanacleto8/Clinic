<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Conteúdo que a ficha de saúde tinha antes de uma alteração. Só é criado,
 * nunca alterado.
 */
#[Fillable(['content', 'recorded_by', 'recorded_at'])]
class PatientAnamnesisVersion extends Model
{
    public const UPDATED_AT = null;

    /**
     * Os campos da ficha guardados em cada versão.
     */
    public const FIELDS = ['allergies', 'medications', 'health_conditions', 'previous_procedures', 'pregnant_or_lactating', 'notes'];

    /**
     * Nunca serializar dados de saúde por acidente.
     *
     * @var list<string>
     */
    protected $hidden = ['content'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'content' => 'encrypted:array',
            'recorded_at' => 'datetime',
        ];
    }

    /**
     * Valor de um campo nesta versão, ou nulo.
     */
    public function field(string $name): mixed
    {
        return $this->content[$name] ?? null;
    }

    /**
     * @return BelongsTo<PatientAnamnesis, $this>
     */
    public function anamnesis(): BelongsTo
    {
        return $this->belongsTo(PatientAnamnesis::class, 'patient_anamnesis_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
