<?php

namespace Database\Factories;

use App\Enums\ProcedureCategory;
use App\Models\Procedure;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Procedure>
 */
class ProcedureFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Procedimento '.fake()->unique()->numerify('####'),
            'description' => null,
            'category' => ProcedureCategory::Injectable,
            'price' => 800,
            'duration_minutes' => 30,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
