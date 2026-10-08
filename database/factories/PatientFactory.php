<?php

namespace Database\Factories;

use App\Enums\Sex;
use App\Models\Patient;
use App\Support\BrazilianStates;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Patient>
 */
class PatientFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'cpf' => fake()->unique()->cpf(false),
            'birth_date' => fake()->dateTimeBetween('-90 years', '-1 year')->format('Y-m-d'),
            'sex' => fake()->randomElement(Sex::cases()),
            'phone' => fake()->numerify('119########'),
            'email' => fake()->optional()->safeEmail(),
            'zip_code' => fake()->numerify('########'),
            'street' => fake()->streetName(),
            'number' => (string) fake()->buildingNumber(),
            'district' => fake()->citySuffix(),
            'city' => fake()->city(),
            'state' => fake()->randomElement(BrazilianStates::CODES),
            'notes' => null,
        ];
    }
}
