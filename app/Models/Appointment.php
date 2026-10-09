<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use Carbon\CarbonImmutable;
use Database\Factories\AppointmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Agendamento. Paciente, profissional, horário e situação ficam fora do mass
 * assignment: são definidos pelo controller, depois das checagens.
 */
#[Fillable(['notes'])]
class Appointment extends Model
{
    /** @use HasFactory<AppointmentFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $hidden = ['notes'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'duration_minutes' => 'integer',
            'notes' => 'encrypted',
            'status' => AppointmentStatus::class,
        ];
    }

    /**
     * Define início e duração juntos, mantendo o fim sempre coerente.
     */
    public function schedule(CarbonImmutable $start, int $durationMinutes): static
    {
        $this->scheduled_at = $start;
        $this->duration_minutes = $durationMinutes;
        $this->ends_at = $start->addMinutes($durationMinutes);

        return $this;
    }

    /**
     * Em aberto: o paciente ainda é esperado e o horário segue reservado.
     */
    public function isOpen(): bool
    {
        return in_array($this->status, AppointmentStatus::open(), true);
    }

    /**
     * Pronto para virar atendimento: a chegada do paciente foi confirmada e
     * nenhum atendimento foi iniciado ainda.
     */
    public function isReadyForTreatment(): bool
    {
        return $this->status === AppointmentStatus::Confirmed && $this->treatment_id === null;
    }

    /**
     * @param  Builder<Appointment>  $query
     */
    public function scopeReadyForTreatment(Builder $query): void
    {
        $query->where('status', AppointmentStatus::Confirmed)->whereNull('treatment_id');
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
     * @return BelongsTo<Treatment, $this>
     */
    public function treatment(): BelongsTo
    {
        return $this->belongsTo(Treatment::class);
    }
}
