<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\StockMovementType;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\StaleStockException;
use App\Exceptions\UnusableStockException;
use App\Models\ProductBatch;
use App\Models\StockMovement;
use App\Models\StockUnit;
use App\Models\Treatment;
use App\Models\User;
use App\Support\LocalizedDecimal;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Único ponto que altera saldo de estoque. Cada alteração grava, na mesma
 * transação, a movimentação, o novo saldo do lote (e da unidade física, no
 * produto fracionado) e o registro de auditoria.
 *
 * Toda operação trava primeiro a linha do lote e só depois as unidades dele:
 * a ordem fixa evita impasse entre operações simultâneas no mesmo lote.
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
     * No produto fracionado, sai só da parte fechada. Lote vencido não sai
     * por aqui: produto vencido não circula, só é baixado como perda.
     *
     * @throws InsufficientStockException
     * @throws UnusableStockException
     */
    public function withdraw(ProductBatch $batch, string $quantity, string $reason, ?User $user): StockMovement
    {
        $this->refuseExpired($batch);

        return $this->apply($batch, StockMovementType::Exit, -Quantity::toThousandths($quantity), $reason, $user);
    }

    /**
     * Perda: quebra, extravio ou descarte por vencimento. No produto
     * fracionado, sai só da parte fechada; a sobra de unidade aberta sai por discard().
     *
     * @throws InsufficientStockException
     */
    public function lose(ProductBatch $batch, string $quantity, string $reason, ?User $user): StockMovement
    {
        return $this->apply($batch, StockMovementType::Loss, -Quantity::toThousandths($quantity), $reason, $user);
    }

    /**
     * Consumo em um atendimento, de um lote de produto não fracionado.
     *
     * @throws InsufficientStockException
     */
    public function consume(ProductBatch $batch, string $quantity, Treatment $treatment, ?User $user): StockMovement
    {
        if ($batch->loadMissing('product')->product->isFractioned()) {
            throw new LogicException('Produto fracionado é consumido por unidade física.');
        }

        return $this->apply($batch, StockMovementType::Consumption, -Quantity::toThousandths($quantity), null, $user, $treatment);
    }

    /**
     * Consumo em um atendimento, de uma unidade física aberta.
     *
     * @throws InsufficientStockException
     * @throws UnusableStockException
     */
    public function consumeFromUnit(StockUnit $unit, string $quantity, Treatment $treatment, ?User $user): StockMovement
    {
        return DB::transaction(function () use ($unit, $quantity, $treatment, $user): StockMovement {
            $batch = $this->lockBatch($unit->loadMissing('batch')->batch);
            $this->lockUnit($unit);

            if ($reason = $unit->unusableReason()) {
                throw new UnusableStockException(ucfirst(mb_strtolower($unit->label())).' '.$reason.'.');
            }

            return $this->apply($batch, StockMovementType::Consumption, -Quantity::toThousandths($quantity), null, $user, $treatment, unit: $unit);
        });
    }

    /**
     * Abre uma unidade fechada do lote. Não muda o saldo do lote: só separa
     * o conteúdo da unidade para ser usado aos poucos.
     *
     * @throws UnusableStockException
     */
    public function openUnit(ProductBatch $batch, ?User $user): StockUnit
    {
        return DB::transaction(function () use ($batch, $user): StockUnit {
            $batch = $this->lockBatch($batch);
            $product = $batch->product;
            $name = $product->name.', lote '.$batch->batch_code;

            if (! $product->isFractioned()) {
                throw new LogicException('Só produto fracionado tem unidade física.');
            }

            match (true) {
                ! $product->is_active => throw new UnusableStockException("O produto {$product->name} está inativo."),
                $batch->isExpired() => throw new UnusableStockException("O lote de {$name} está vencido. Não abra unidades dele."),
                Quantity::toThousandths($this->sealedQuantity($batch)) < Quantity::toThousandths($product->unit_content) => throw new UnusableStockException(
                    "Não há unidade fechada de {$name} para abrir. Fechado: ".LocalizedDecimal::quantity($this->sealedQuantity($batch)).' '.$product->unit->symbol().'.',
                ),
                default => null,
            };

            $unit = new StockUnit;
            $unit->forceFill([
                'product_batch_id' => $batch->getKey(),
                'initial_quantity' => $product->unit_content,
                'quantity' => $product->unit_content,
                'opened_by' => $user?->getKey(),
            ])->save();
            $unit->setRelation('batch', $batch);

            $this->audit->log(AuditAction::StockUnitOpened, $unit, ['product_id' => $product->getKey(), 'product_batch_id' => $batch->getKey()], $user);

            return $unit;
        });
    }

    /**
     * Descarta o que resta em uma unidade aberta, como perda. Devolve nulo se
     * ela já estava vazia. Com atendimento, é a sobra descartada ao fim dele.
     */
    public function discard(StockUnit $unit, string $reason, ?User $user, ?Treatment $treatment = null): ?StockMovement
    {
        return DB::transaction(function () use ($unit, $reason, $user, $treatment): ?StockMovement {
            $batch = $this->lockBatch($unit->loadMissing('batch')->batch);
            $remaining = Quantity::toThousandths($this->lockUnit($unit));

            return $remaining === 0 ? null : $this->apply($batch, StockMovementType::Loss, -$remaining, $reason, $user, $treatment, unit: $unit);
        });
    }

    /**
     * Devolve ao estoque tudo o que um atendimento baixou: o consumo e, no
     * fracionado de uso em um só paciente, a sobra descartada. Cada lote (ou
     * unidade) recebe uma movimentação de estorno com o total devolvido,
     * ligada ao atendimento e ao custo médio das baixas originais. Usado
     * na anulação do atendimento; um atendimento só é estornado uma vez.
     *
     * @return list<StockMovement>
     *
     * @throws LogicException Quando o atendimento já foi estornado.
     */
    public function reverseTreatment(Treatment $treatment, string $reason, ?User $user): array
    {
        return DB::transaction(function () use ($treatment, $reason, $user): array {
            $outgoing = StockMovement::query()
                ->where('treatment_id', $treatment->getKey())
                ->whereIn('type', [StockMovementType::Consumption->value, StockMovementType::Loss->value])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if (StockMovement::query()->where('treatment_id', $treatment->getKey())->where('type', StockMovementType::Reversal->value)->exists()) {
                throw new LogicException('O estoque deste atendimento já foi estornado.');
            }

            $reversals = [];
            $units = StockUnit::query()->with('batch.product:id,multi_patient_use')->whereKey($outgoing->pluck('stock_unit_id')->filter()->unique()->all())->get()->keyBy('id');

            // Unidade de uso em um só paciente foi aberta e descartada: ela não
            // volta a ser usável. O que ela tinha volta ao lote como lacrado,
            // como se não tivesse sido aberta; o registro certo abre outra.
            $returnsToUnit = fn (StockMovement $movement) => $movement->stock_unit_id !== null
                && $units->get($movement->stock_unit_id)?->batch->product->multi_patient_use;

            // Um estorno por lote e unidade: o índice único do atendimento
            // também impede estornar duas vezes o mesmo lote ou unidade.
            foreach ($outgoing->groupBy(fn (StockMovement $movement) => $movement->product_batch_id.'-'.($returnsToUnit($movement) ? $movement->stock_unit_id : 0)) as $movements) {
                $first = $movements->first();
                $quantity = -$movements->sum(fn (StockMovement $movement) => Quantity::toThousandths($movement->quantity));
                $value = $movements->sum(fn (StockMovement $movement) => abs((float) $movement->quantity) * (float) ($movement->unit_cost ?? 0));
                $hasCost = $movements->contains(fn (StockMovement $movement) => $movement->unit_cost !== null);

                $batch = $this->lockBatch(ProductBatch::query()->findOrFail($first->product_batch_id));
                $unit = $returnsToUnit($first) ? $units->get($first->stock_unit_id) : null;

                $reversals[] = $this->apply($batch, StockMovementType::Reversal, $quantity, $reason, $user, $treatment, extra: [
                    'unit_cost' => $hasCost ? number_format($value / ($quantity / Quantity::SCALE), 4, '.', '') : null,
                ], unit: $unit);
            }

            return $reversals;
        });
    }

    /**
     * Ajusta o saldo do lote para a quantidade contada. No produto
     * fracionado, a contagem é só do que está fechado: o que resta nas
     * unidades abertas fica como está e sai por discard(). Devolve nulo
     * quando a contagem confere com o saldo e não há o que registrar. Lote
     * vencido não é ajustado: o que há nele é baixado como perda.
     *
     * @param  string|null  $expectedSealed  Saldo (fechado) que a tela mostrava ao contar. Se ele mudou
     *                                       nesse meio-tempo, a contagem é recusada: aplicá-la desfaria
     *                                       um consumo ou uma entrada feitos enquanto se contava.
     *
     * @throws UnusableStockException
     * @throws StaleStockException
     */
    public function adjustTo(ProductBatch $batch, string $countedQuantity, string $reason, ?User $user, ?string $expectedSealed = null): ?StockMovement
    {
        $this->refuseExpired($batch);

        return DB::transaction(function () use ($batch, $countedQuantity, $reason, $user, $expectedSealed): ?StockMovement {
            $batch = $this->lockBatch($batch);
            $open = $batch->product->isFractioned() ? Quantity::toThousandths($this->lockedOpenQuantity($batch)) : 0;
            $sealed = Quantity::toThousandths($batch->quantity) - $open;

            if ($expectedSealed !== null && Quantity::toThousandths($expectedSealed) !== $sealed) {
                throw new StaleStockException($batch, Quantity::fromThousandths($sealed));
            }

            $delta = Quantity::toThousandths($countedQuantity) - $sealed;

            return $delta === 0 ? null : $this->apply($batch, StockMovementType::Adjustment, $delta, $reason, $user);
        });
    }

    /**
     * Baixa como perda tudo o que resta em um lote vencido: o que está
     * fechado e, no fracionado, o que resta em cada unidade aberta. Tudo em
     * uma transação, cada parte com a própria movimentação.
     *
     * @return list<StockMovement>
     *
     * @throws LogicException Quando o lote não está vencido.
     */
    public function writeOffExpired(ProductBatch $batch, string $reason, ?User $user): array
    {
        return DB::transaction(function () use ($batch, $reason, $user): array {
            $batch = $this->lockBatch($batch);

            if (! $batch->isExpired()) {
                throw new LogicException('Só lote vencido é baixado por aqui.');
            }

            $movements = [];

            foreach ($batch->units()->withBalance()->orderBy('id')->get() as $unit) {
                $unit->setRelation('batch', $batch);
                $movements[] = $this->discard($unit, $reason, $user);
            }

            $sealed = Quantity::toThousandths($this->sealedQuantity($batch));

            if ($sealed > 0) {
                $movements[] = $this->apply($batch, StockMovementType::Loss, -$sealed, $reason, $user);
            }

            return array_values(array_filter($movements));
        });
    }

    /**
     * Corrige o custo unitário de uma entrada digitado errado. É a única
     * alteração aceita em uma linha do livro: não mexe em saldo, mas o custo
     * médio do lote, que vale para todo consumo e perda, sai dela. Fica na
     * auditoria com o valor antigo e o novo.
     *
     * @throws LogicException Quando a movimentação não é uma entrada.
     */
    public function correctEntryCost(StockMovement $entry, ?string $unitCost, ?User $user): bool
    {
        if ($entry->type !== StockMovementType::Entry) {
            throw new LogicException('Só o custo de uma entrada pode ser corrigido.');
        }

        return DB::transaction(function () use ($entry, $unitCost, $user): bool {
            // Trava o lote antes da entrada, na mesma ordem das baixas: uma baixa
            // simultânea não lê o custo médio antigo no meio da correção.
            $this->lockBatch(ProductBatch::query()->findOrFail($entry->product_batch_id));
            $current = DB::table('stock_movements')->where('id', $entry->getKey())->lockForUpdate()->value('unit_cost');
            $new = $unitCost === null ? null : number_format((float) $unitCost, 4, '.', '');

            if ($current === null ? $new === null : ($new !== null && number_format((float) $current, 4, '.', '') === $new)) {
                return false;
            }

            DB::table('stock_movements')->where('id', $entry->getKey())->update(['unit_cost' => $new]);
            $entry->setRawAttributes([...$entry->getAttributes(), 'unit_cost' => $new], true);

            $this->audit->log(AuditAction::StockEntryCostCorrected, $entry, [
                'product_batch_id' => $entry->product_batch_id,
                'from' => $current === null ? null : number_format((float) $current, 4, '.', ''),
                'to' => $new,
            ], $user);

            return true;
        });
    }

    /**
     * @param  int  $delta  Variação em milésimos, com sinal.
     * @param  array<string, mixed>  $extra  Colunas próprias do tipo de movimentação.
     *
     * @throws InsufficientStockException
     */
    private function apply(ProductBatch $batch, StockMovementType $type, int $delta, ?string $reason, ?User $user, ?Treatment $treatment = null, array $extra = [], ?StockUnit $unit = null): StockMovement
    {
        $batch->loadMissing('product');

        return DB::transaction(function () use ($batch, $type, $delta, $reason, $user, $treatment, $extra, $unit): StockMovement {
            // A trava na linha do lote serializa baixas concorrentes: duas
            // pessoas não consomem o mesmo saldo ao mesmo tempo.
            $current = $this->lockedQuantity($batch);
            $balance = Quantity::toThousandths($current) + $delta;

            if ($balance < 0) {
                throw new InsufficientStockException($batch, $current, $unit);
            }

            if ($unit !== null) {
                $this->moveUnit($batch, $unit, $delta);
            } elseif ($delta < 0 && $batch->product->isFractioned()) {
                // Sem unidade, a baixa só pode sair do que está fechado: o
                // conteúdo das unidades abertas é delas.
                $sealed = Quantity::toThousandths($current) - Quantity::toThousandths($this->lockedOpenQuantity($batch));

                if ($sealed + $delta < 0) {
                    throw new InsufficientStockException($batch, Quantity::fromThousandths(max(0, $sealed)));
                }
            }

            $newQuantity = Quantity::fromThousandths($balance);

            DB::table('product_batches')->where('id', $batch->getKey())->update(['quantity' => $newQuantity, 'updated_at' => now()]);
            $batch->setRawAttributes([...$batch->getAttributes(), 'quantity' => $newQuantity], true);

            $movement = StockMovement::create([
                'product_batch_id' => $batch->getKey(),
                'stock_unit_id' => $unit?->getKey(),
                'type' => $type,
                'quantity' => Quantity::fromThousandths($delta),
                // Na baixa, o custo médio do lote naquele momento: é o que dá o
                // custo do consumo e o valor do que foi perdido.
                'unit_cost' => $delta < 0 ? $this->averageCost($batch) : null,
                'reason' => $reason,
                'treatment_id' => $treatment?->getKey(),
                'user_id' => $user?->getKey(),
                ...$extra,
            ]);

            $this->audit->log(AuditAction::StockMoved, $movement, array_filter([
                'type' => $type->value,
                'product_id' => $batch->product_id,
                'product_batch_id' => $batch->getKey(),
                'stock_unit_id' => $unit?->getKey(),
                'treatment_id' => $treatment?->getKey(),
                // Para a auditoria por paciente reunir também o que foi aplicado nele.
                'patient_id' => $treatment?->patient_id,
            ]), $user);

            return $movement;
        });
    }

    /**
     * @throws InsufficientStockException
     */
    private function moveUnit(ProductBatch $batch, StockUnit $unit, int $delta): void
    {
        if ($unit->product_batch_id !== $batch->getKey()) {
            throw new LogicException('A unidade não pertence a este lote.');
        }

        $current = $this->lockUnit($unit);
        $balance = Quantity::toThousandths($current) + $delta;

        if ($balance < 0) {
            throw new InsufficientStockException($batch, $current, $unit);
        }

        $newQuantity = Quantity::fromThousandths($balance);

        DB::table('stock_units')->where('id', $unit->getKey())->update(['quantity' => $newQuantity, 'updated_at' => now()]);
        $unit->setRawAttributes([...$unit->getAttributes(), 'quantity' => $newQuantity], true);
    }

    /**
     * Custo médio por unidade de medida das entradas do lote que informaram
     * custo; sem nenhuma, o custo do cadastro do produto.
     */
    private function averageCost(ProductBatch $batch): ?string
    {
        $average = DB::table('stock_movements')
            ->where('product_batch_id', $batch->getKey())
            ->where('type', StockMovementType::Entry->value)
            ->whereNotNull('unit_cost')
            ->where('quantity', '>', 0)
            ->selectRaw('sum(quantity * unit_cost) / sum(quantity) as average')
            ->value('average');

        $cost = $average ?? $batch->product->cost;

        return $cost === null ? null : number_format((float) $cost, 4, '.', '');
    }

    /**
     * @throws UnusableStockException
     */
    private function refuseExpired(ProductBatch $batch): void
    {
        if ($batch->isExpired()) {
            throw new UnusableStockException('O lote '.$batch->batch_code.' está vencido. Registre a baixa como perda.');
        }
    }

    /**
     * Trava o lote e devolve o modelo com o saldo atual e o produto carregado.
     */
    private function lockBatch(ProductBatch $batch): ProductBatch
    {
        $batch->setRawAttributes([...$batch->getAttributes(), 'quantity' => $this->lockedQuantity($batch)], true);
        $batch->loadMissing('product');

        return $batch;
    }

    private function lockedQuantity(ProductBatch $batch): string
    {
        return (string) DB::table('product_batches')->where('id', $batch->getKey())->lockForUpdate()->value('quantity');
    }

    /**
     * Saldo atual da unidade, com a linha travada. O modelo recebe o saldo
     * lido, para as regras de uso olharem o valor de agora e não o da tela.
     */
    private function lockUnit(StockUnit $unit): string
    {
        $quantity = (string) DB::table('stock_units')->where('id', $unit->getKey())->lockForUpdate()->value('quantity');
        $unit->setRawAttributes([...$unit->getAttributes(), 'quantity' => $quantity], true);

        return $quantity;
    }

    /**
     * O que resta nas unidades abertas do lote. Leitura com trava: devolve o
     * valor gravado agora, e não o da fotografia da transação.
     */
    private function lockedOpenQuantity(ProductBatch $batch): string
    {
        return (string) (DB::table('stock_units')->where('product_batch_id', $batch->getKey())->where('quantity', '>', 0)->lockForUpdate()->sum('quantity') ?? 0);
    }

    private function sealedQuantity(ProductBatch $batch): string
    {
        return Quantity::fromThousandths(Quantity::toThousandths($batch->quantity) - Quantity::toThousandths($this->lockedOpenQuantity($batch)));
    }
}
