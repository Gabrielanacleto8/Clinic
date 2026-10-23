<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use App\Support\ClinicTime;
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
     * Dia do agendamento no calendário da clínica (Y-m-d).
     */
    public function localDay(): string
    {
        return ClinicTime::format($this->scheduled_at, 'Y-m-d');
    }

    /**
     * Falta só a partir do dia do agendamento: antes disso o paciente ainda pode vir.
     */
    public function canBeMarkedMissed(): bool
    {
        return $this->isOpen() && $this->localDay() <= ClinicTime::today();
    }

    /**
     * Reaberto, o agendamento volta a ocupar o horário e a aceitar registro:
     * só de hoje em diante e com o profissional ainda ativo (relação carregada).
     */
    public function canBeReopened(): bool
    {
        return ! $this->isOpen()
            && $this->status !== AppointmentStatus::Completed
            && $this->localDay() >= ClinicTime::today()
            && (bool) ($this->professional?->is_active ?? false);
    }

    /**
     * Pode receber o registro do atendimento: segue em aberto, ainda sem
     * atendimento e não é de um dia futuro.
     */
    public function isOpenForTreatment(): bool
    {
        return $this->isOpen()
            && $this->treatment_id === null
            && $this->scheduled_at->lessThanOrEqualTo(ClinicTime::dayBounds(ClinicTime::today())[1]);
    }

    /**
     * @param  Builder<Appointment>  $query
     */
    public function scopeOpenForTreatment(Builder $query): void
    {
        $query->whereIn('status', AppointmentStatus::open())
            ->whereNull('treatment_id')
            ->where('scheduled_at', '<=', ClinicTime::dayBounds(ClinicTime::today())[1]);
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
