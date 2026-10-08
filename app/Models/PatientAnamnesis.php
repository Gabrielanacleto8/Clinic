<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ficha de saúde do paciente (anamnese). Uma por paciente.
 */
#[Fillable(['allergies', 'medications', 'health_conditions', 'previous_procedures', 'pregnant_or_lactating', 'notes'])]
class PatientAnamnesis extends Model
{
    protected $table = 'patient_anamneses';

    /**
     * Nunca serializar dados de saúde por acidente (JSON, logs, filas).
     *
     * @var list<string>
     */
    protected $hidden = ['allergies', 'medications', 'health_conditions', 'previous_procedures', 'pregnant_or_lactating', 'notes'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'allergies' => 'encrypted',
            'medications' => 'encrypted',
            'health_conditions' => 'encrypted',
            'previous_procedures' => 'encrypted',
            'notes' => 'encrypted',
            'pregnant_or_lactating' => 'boolean',
        ];
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
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
