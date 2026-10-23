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
            // Adulto por padrão: menor de idade exige responsável legal (ver minor()).
            // As datas partem do now() do Carbon, que respeita o travelTo dos testes.
            'birth_date' => fake()->dateTimeBetween(now()->subYears(90), now()->subYears(18)->subDay())->format('Y-m-d'),
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

    /**
     * Menor de idade, com o responsável legal que o cadastro exige.
     */
    public function minor(): static
    {
        return $this->state(fn () => [
            'birth_date' => fake()->dateTimeBetween(now()->subYears(17), now()->subYear())->format('Y-m-d'),
            'guardian_name' => fake()->name(),
            'guardian_relationship' => 'Mãe',
            'guardian_phone' => fake()->numerify('119########'),
        ]);
    }
}
