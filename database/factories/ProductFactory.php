<?php

namespace Database\Factories;

use App\Enums\ProductCategory;
use App\Enums\ProductUnit;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Produto '.fake()->unique()->numerify('####'),
            'category' => ProductCategory::Injectable,
            'brand' => null,
            'manufacturer' => null,
            'supplier' => null,
            'unit' => ProductUnit::Unit,
            'minimum_stock' => 0,
            'cost' => null,
            'price' => null,
            'is_active' => true,
        ];
    }
}
