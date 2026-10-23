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
     * Ficha sem revisão há mais tempo do que a clínica aceita: o profissional
     * confere com o paciente antes do procedimento.
     */
    public function isStale(): bool
    {
        return $this->updated_at !== null
            && $this->updated_at->lessThan(now()->subMonths(config('clinic.anamnesis_review_months')));
    }

    /**
     * Os avisos que o profissional precisa ver antes de qualquer procedimento,
     * na ordem em que aparecem. Os textos são do próprio paciente.
     *
     * @return list<array{label: string|null, text: string}>
     */
    public function alerts(): array
    {
        $alerts = [];

        if ($this->pregnant_or_lactating) {
            $alerts[] = ['label' => null, 'text' => 'Gestante ou lactante.'];
        }

        foreach (['allergies' => 'Alergias', 'medications' => 'Medicamentos em uso', 'health_conditions' => 'Condições de saúde'] as $field => $label) {
            if (filled($this->{$field})) {
                $alerts[] = ['label' => $label, 'text' => $this->{$field}];
            }
        }

        return $alerts;
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
