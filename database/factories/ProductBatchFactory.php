<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductBatch>
 */
class ProductBatchFactory extends Factory
{
    /**
     * Saldo inicial direto, só para montar cenários de teste. Em produção
     * todo saldo nasce de uma movimentação do StockLedger.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'batch_code' => strtoupper(fake()->unique()->bothify('L####??')),
            'expires_on' => now()->addYear()->format('Y-m-d'),
            'quantity' => 10,
        ];
    }
}
