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
     * Fabricação e validade de um lote existente não são trocadas por uma
     * entrada, e lote vencido não recebe entrada: produto vencido não circula.
     *
     * @param  array<string, mixed>  $entry
     *
     * @throws ValidationException
     */
    private function batchFor(int $productId, array $entry): ProductBatch
    {
        $code = trim((string) $entry['batch_code']);
        $dates = array_filter(Arr::only($entry, ['manufactured_on', 'expires_on']), fn ($date) => $date !== null);

        $batch = ProductBatch::query()->where('product_id', $productId)->where('batch_code', $code)->lockForUpdate()->first();

        if ($batch === null) {
            // Duas entradas simultâneas do mesmo lote novo: a segunda encontra
            // o lote criado pela primeira, em vez de esbarrar no índice único.
            $batch = ProductBatch::createOrFirst(['product_id' => $productId, 'batch_code' => $code], $dates);

            if ($batch->wasRecentlyCreated) {
                return $batch;
            }

            $batch = ProductBatch::query()->whereKey($batch->getKey())->lockForUpdate()->firstOrFail();
        }

        if ($batch->isExpired()) {
            throw ValidationException::withMessages([
                'batch_code' => 'O lote '.$code.' venceu em '.$batch->expires_on->format('d/m/Y').'. Não é possível dar entrada em lote vencido.',
            ]);
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
