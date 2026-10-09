<?php

namespace App\Services;

use App\Models\ProductBatch;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Recebimento de mercadoria: encontra ou cria o lote informado e lança a
 * entrada no livro de estoque. Usado pela entrada manual e pela importação de nota.
 */
class StockReceiver
{
    public function __construct(private readonly StockLedger $ledger) {}

    /**
     * @param  array{batch_code: string, quantity: string, manufactured_on?: ?string, expires_on?: ?string, unit_cost?: ?string, supplier?: ?string, invoice_number?: ?string, reason?: ?string}  $entry
     *
     * @throws ValidationException Quando fabricação ou validade divergem das do lote já existente.
     */
    public function receive(int $productId, array $entry, ?User $user): StockMovement
    {
        return DB::transaction(fn (): StockMovement => $this->ledger->enter(
            $this->batchFor($productId, $entry),
            (string) $entry['quantity'],
            $entry['reason'] ?? null,
            $user,
            Arr::only($entry, ['unit_cost', 'supplier', 'invoice_number']),
        ));
    }

    /**
     * Lote informado na entrada: o existente com aquele código, ou um novo.
     * Fabricação e validade de um lote existente não são trocadas por uma entrada.
     *
     * @param  array<string, mixed>  $entry
     */
    private function batchFor(int $productId, array $entry): ProductBatch
    {
        $code = trim((string) $entry['batch_code']);
        $dates = Arr::only($entry, ['manufactured_on', 'expires_on']);

        $batch = ProductBatch::query()->where('product_id', $productId)->where('batch_code', $code)->lockForUpdate()->first();

        if ($batch === null) {
            return ProductBatch::create(['product_id' => $productId, 'batch_code' => $code, ...$dates]);
        }

        foreach (['manufactured_on' => 'fabricação', 'expires_on' => 'validade'] as $field => $label) {
            $informed = $dates[$field] ?? null;

            if ($informed !== null && $batch->{$field}?->format('Y-m-d') !== $informed) {
                throw ValidationException::withMessages([
                    $field => 'O lote '.$code.' já existe com '.($batch->{$field} ? $label.' '.$batch->{$field}->format('d/m/Y') : $label.' não informada').'.',
                ]);
            }
        }

        return $batch;
    }
}
