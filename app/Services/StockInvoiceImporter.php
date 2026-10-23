<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\Product;
use App\Models\StockInvoice;
use App\Models\User;
use App\Support\Quantity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Grava o que foi conferido na importação de uma nota fiscal: cadastra os
 * produtos novos e lança as entradas. Tudo em uma transação: se um item
 * falha, nada da nota é gravado.
 */
class StockInvoiceImporter
{
    public const ALREADY_IMPORTED = 'Esta nota fiscal já foi importada. Nada foi lançado de novo.';

    public function __construct(
        private readonly StockReceiver $receiver,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{access_key?: ?string, number: string, supplier: string, items: list<array<string, mixed>>}  $invoice  Nota lida do XML.
     * @param  array<int, array<string, mixed>>  $items  Itens conferidos, indexados pela posição no formulário.
     * @return array{products: int, entries: int}
     *
     * @throws ValidationException
     */
    public function import(array $invoice, array $items, User $user): array
    {
        return DB::transaction(function () use ($invoice, $items, $user): array {
            $this->register($invoice, $user);

            // Dois itens da nota com o mesmo nome são o mesmo produto: cadastra uma vez só.
            $created = [];
            $entries = 0;

            foreach ($items as $index => $item) {
                $lots = $item['enter'] ? $item['lots'] : [];
                $unitCost = $this->unitCost($item, $lots, $invoice['items'][$index]['quantity'] ?? null);
                $productId = $item['product_id'] ?? null;

                if ($productId === null) {
                    $key = mb_strtolower(trim((string) $item['name']));
                    $created[$key] ??= $this->createProduct($item, $index, $unitCost, $invoice['supplier']);
                    $productId = $created[$key]->getKey();
                }

                foreach ($lots as $lotIndex => $lot) {
                    try {
                        $this->receiver->receive((int) $productId, [
                            ...$lot,
                            'unit_cost' => $unitCost,
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
     * Registra a nota pela chave de acesso. O índice único barra a mesma nota
     * importada de novo, inclusive em dois envios simultâneos.
     *
     * @param  array<string, mixed>  $invoice
     *
     * @throws ValidationException
     */
    private function register(array $invoice, User $user): void
    {
        if (($invoice['access_key'] ?? null) === null) {
            return;
        }

        try {
            $record = new StockInvoice;
            $record->forceFill(['access_key' => $invoice['access_key'], 'user_id' => $user->getKey()])->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['items' => self::ALREADY_IMPORTED]);
        }
    }

    /**
     * Custo por unidade de medida do estoque: o valor total do item dividido
     * pela quantidade lançada. Assim a conversão da unidade da nota (frasco)
     * para a do estoque (U) não distorce o custo. Sem entrada, divide pela
     * quantidade da nota, só para o cadastro do produto novo.
     *
     * @param  array<string, mixed>  $item
     * @param  array<int, array<string, mixed>>  $lots
     */
    private function unitCost(array $item, array $lots, mixed $invoiceQuantity): ?string
    {
        if (($item['total_cost'] ?? null) === null) {
            return null;
        }

        $quantity = $lots !== []
            ? array_sum(array_map(fn (array $lot) => Quantity::toThousandths((string) $lot['quantity']), $lots)) / 1000
            : (float) $invoiceQuantity;

        return $quantity > 0 ? number_format((float) $item['total_cost'] / $quantity, 4, '.', '') : null;
    }

    /**
     * @param  array<string, mixed>  $item
     *
     * @throws ValidationException
     */
    private function createProduct(array $item, int|string $index, ?string $unitCost, string $supplier): Product
    {
        try {
            $product = Product::create([
                'name' => trim((string) $item['name']),
                'code' => filled($item['code'] ?? null) ? trim((string) $item['code']) : null,
                'category' => $item['category'],
                'unit' => $item['unit'],
                'supplier' => $supplier ?: null,
                'cost' => $unitCost === null ? null : number_format((float) $unitCost, 2, '.', ''),
                'minimum_stock' => 0,
                'is_active' => true,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Outro cadastro com o mesmo nome ou código entrou entre a conferência e a gravação.
            throw ValidationException::withMessages(["items.{$index}.name" => 'Já existe um produto com este nome ou código. Escolha-o na lista de produtos.']);
        }

        $this->audit->log(AuditAction::ProductCreated, $product, ['source' => 'invoice']);

        return $product;
    }
}
