<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Grava o que foi conferido na importação de uma nota fiscal: cadastra os
 * produtos novos e lança as entradas. Tudo em uma transação: se um item
 * falha, nada da nota é gravado.
 */
class StockInvoiceImporter
{
    public function __construct(
        private readonly StockReceiver $receiver,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{number: string, supplier: string}  $invoice
     * @param  array<int, array<string, mixed>>  $items  Itens conferidos, indexados pela posição no formulário.
     * @return array{products: int, entries: int}
     *
     * @throws ValidationException
     */
    public function import(array $invoice, array $items, User $user): array
    {
        return DB::transaction(function () use ($invoice, $items, $user): array {
            // Dois itens da nota com o mesmo nome são o mesmo produto: cadastra uma vez só.
            $created = [];
            $entries = 0;

            foreach ($items as $index => $item) {
                $productId = $item['product_id'] ?? null;

                if ($productId === null) {
                    $key = mb_strtolower(trim((string) $item['name']));
                    $created[$key] ??= $this->createProduct($item, $invoice['supplier']);
                    $productId = $created[$key]->getKey();
                }

                foreach ($item['enter'] ? $item['lots'] : [] as $lotIndex => $lot) {
                    try {
                        $this->receiver->receive((int) $productId, [
                            ...$lot,
                            'unit_cost' => $item['unit_cost'] ?? null,
                            'supplier' => $invoice['supplier'] ?: null,
                            'invoice_number' => $invoice['number'] ?: null,
                        ], $user);
                    } catch (ValidationException $exception) {
                        // O erro volta para o campo do lote daquele item no formulário de conferência.
                        throw ValidationException::withMessages(
                            collect($exception->errors())->mapWithKeys(fn (array $messages, string $field) => ["items.{$index}.lots.{$lotIndex}.{$field}" => $messages])->all(),
                        );
                    }

                    $entries++;
                }
            }

            return ['products' => count($created), 'entries' => $entries];
        });
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function createProduct(array $item, string $supplier): Product
    {
        $product = Product::create([
            'name' => trim((string) $item['name']),
            'code' => filled($item['code'] ?? null) ? trim((string) $item['code']) : null,
            'category' => $item['category'],
            'unit' => $item['unit'],
            'supplier' => $supplier ?: null,
            'cost' => $item['unit_cost'] ?? null,
            'minimum_stock' => 0,
            'is_active' => true,
        ]);

        $this->audit->log(AuditAction::ProductCreated, $product, ['source' => 'invoice']);

        return $product;
    }
}
