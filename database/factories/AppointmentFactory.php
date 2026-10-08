<?php

namespace Database\Factories;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(),
            'professional_id' => User::factory()->professional(),
            'scheduled_at' => now()->addHour()->startOfHour(),
            'duration_minutes' => 30,
            'ends_at' => fn (array $attributes) => CarbonImmutable::parse($attributes['scheduled_at'])->addMinutes($attributes['duration_minutes']),
            'procedure_id' => Procedure::factory(),
            'notes' => null,
            'status' => AppointmentStatus::Scheduled,
        ];
    }

    /**
     * Horário informado no fuso da clínica, como o usuário digitaria.
     */
    public function at(string $localDateTime, int $minutes = 30): static
    {
        return $this->state([
            'scheduled_at' => CarbonImmutable::parse($localDateTime, config('clinic.timezone'))->utc(),
            'duration_minutes' => $minutes,
        ]);
    }

    public function status(AppointmentStatus $status): static
    {
        return $this->state(['status' => $status]);
    }
}
