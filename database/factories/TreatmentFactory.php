<?php

namespace Database\Factories;

use App\Models\Patient;
use App\Models\Procedure;
use App\Models\Treatment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Treatment>
 */
class TreatmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(),
            'professional_id' => User::factory()->professional(),
            'created_by' => fn (array $attributes) => $attributes['professional_id'],
            'performed_at' => fake()->dateTimeBetween('-1 year', '-1 day'),
            'procedure_id' => Procedure::factory(),
            'treated_areas' => fake()->randomElement(['Glabela e frontal', 'Lábios', 'Malar', 'Face completa', null]),
            'description' => fake()->sentence(12),
            'post_care' => 'Evitar exposição solar e atividade física por 24 horas.',
            'complications' => null,
            'return_date' => null,
        ];
    }
}
