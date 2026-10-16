<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\StockMovementType;
use App\Exceptions\InsufficientStockException;
use App\Models\ProductBatch;
use App\Models\StockMovement;
use App\Models\Treatment;
use App\Models\User;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;

/**
 * Único ponto que altera saldo de estoque. Cada alteração grava, na mesma
 * transação, a movimentação, o novo saldo do lote e o registro de auditoria.
 */
class StockLedger
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{unit_cost?: ?string, supplier?: ?string, invoice_number?: ?string}  $receipt  Dados da compra, quando informados.
     */
    public function enter(ProductBatch $batch, string $quantity, ?string $reason, ?User $user, array $receipt = []): StockMovement
    {
        return $this->apply($batch, StockMovementType::Entry, Quantity::toThousandths($quantity), $reason, $user, extra: [
            'unit_cost' => $receipt['unit_cost'] ?? null,
            'supplier' => $receipt['supplier'] ?? null,
            'invoice_number' => $receipt['invoice_number'] ?? null,
        ]);
    }

    /**
     * Saída manual que não é perda nem consumo: devolução, uso interno, transferência.
     *
     * @throws InsufficientStockException
     */
    public function withdraw(ProductBatch $batch, string $quantity, string $reason, ?User $user): StockMovement
    {
        return $this->apply($batch, StockMovementType::Exit, -Quantity::toThousandths($quantity), $reason, $user);
    }

    /**
     * Perda: quebra, extravio ou descarte por vencimento.
     *
     * @throws InsufficientStockException
     */
    public function lose(ProductBatch $batch, string $quantity, string $reason, ?User $user): StockMovement
    {
        return $this->apply($batch, StockMovementType::Loss, -Quantity::toThousandths($quantity), $reason, $user);
    }

    /**
     * Consumo em um atendimento. O profissional é o do atendimento.
     *
     * @throws InsufficientStockException
     */
    public function consume(ProductBatch $batch, string $quantity, Treatment $treatment, ?User $user): StockMovement
    {
        return $this->apply($batch, StockMovementType::Consumption, -Quantity::toThousandths($quantity), null, $user, $treatment);
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
     * @param  array<string, mixed>  $extra  Colunas próprias do tipo de movimentação.
     *
     * @throws InsufficientStockException
     */
    private function apply(ProductBatch $batch, StockMovementType $type, int $delta, ?string $reason, ?User $user, ?Treatment $treatment = null, array $extra = []): StockMovement
    {
        return DB::transaction(function () use ($batch, $type, $delta, $reason, $user, $treatment, $extra): StockMovement {
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

            $movement = StockMovement::create([
                'product_batch_id' => $batch->getKey(),
                'type' => $type,
                'quantity' => Quantity::fromThousandths($delta),
                'reason' => $reason,
                'treatment_id' => $treatment?->getKey(),
                'user_id' => $user?->getKey(),
                ...$extra,
            ]);

            $this->audit->log(AuditAction::StockMoved, $movement, array_filter([
                'type' => $type->value,
                'product_id' => $batch->product_id,
                'product_batch_id' => $batch->getKey(),
                'treatment_id' => $treatment?->getKey(),
            ]), $user);

            return $movement;
        });
    }

    private function lockedQuantity(ProductBatch $batch): string
    {
        return (string) DB::table('product_batches')->where('id', $batch->getKey())->lockForUpdate()->value('quantity');
    }
}
