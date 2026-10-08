<?php

namespace App\Services;

use App\Enums\StockMovementType;
use App\Exceptions\InsufficientStockException;
use App\Models\ProductBatch;
use App\Models\StockMovement;
use App\Models\Treatment;
use App\Models\User;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;

/**
 * Único ponto que altera saldo de estoque. Cada alteração grava a
 * movimentação e o novo saldo do lote na mesma transação.
 */
class StockLedger
{
    public function enter(ProductBatch $batch, string $quantity, ?string $reason, ?User $user): StockMovement
    {
        return $this->apply($batch, StockMovementType::Entry, Quantity::toThousandths($quantity), $reason, $user);
    }

    /**
     * @throws InsufficientStockException
     */
    public function withdraw(ProductBatch $batch, string $quantity, ?string $reason, ?User $user, ?Treatment $treatment = null): StockMovement
    {
        return $this->apply($batch, StockMovementType::Exit, -Quantity::toThousandths($quantity), $reason, $user, $treatment);
    }

    /**
     * Ajusta o saldo do lote para a quantidade contada. Devolve nulo quando
     * a contagem confere com o saldo e não há o que registrar.
     */
    public function adjustTo(ProductBatch $batch, string $countedQuantity, string $reason, ?User $user): ?StockMovement
    {
        return DB::transaction(function () use ($batch, $countedQuantity, $reason, $user): ?StockMovement {
            $current = Quantity::toThousandths($this->lockedQuantity($batch));
            $delta = Quantity::toThousandths($countedQuantity) - $current;

            return $delta === 0 ? null : $this->apply($batch, StockMovementType::Adjustment, $delta, $reason, $user);
        });
    }

    /**
     * @param  int  $delta  Variação em milésimos, com sinal.
     *
     * @throws InsufficientStockException
     */
    private function apply(ProductBatch $batch, StockMovementType $type, int $delta, ?string $reason, ?User $user, ?Treatment $treatment = null): StockMovement
    {
        return DB::transaction(function () use ($batch, $type, $delta, $reason, $user, $treatment): StockMovement {
            // A trava na linha do lote serializa baixas concorrentes: duas
            // pessoas não consomem o mesmo saldo ao mesmo tempo.
            $current = $this->lockedQuantity($batch);
            $balance = Quantity::toThousandths($current) + $delta;

            if ($balance < 0) {
                throw new InsufficientStockException($batch, $current);
            }

            $newQuantity = Quantity::fromThousandths($balance);

            DB::table('product_batches')->where('id', $batch->getKey())->update(['quantity' => $newQuantity, 'updated_at' => now()]);
            $batch->setRawAttributes([...$batch->getAttributes(), 'quantity' => $newQuantity], true);

            return StockMovement::create([
                'product_batch_id' => $batch->getKey(),
                'type' => $type,
                'quantity' => Quantity::fromThousandths($delta),
                'reason' => $reason,
                'treatment_id' => $treatment?->getKey(),
                'user_id' => $user?->getKey(),
            ]);
        });
    }

    private function lockedQuantity(ProductBatch $batch): string
    {
        return (string) DB::table('product_batches')->where('id', $batch->getKey())->lockForUpdate()->value('quantity');
    }
}
