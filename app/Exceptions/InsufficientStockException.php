<?php

namespace App\Exceptions;

use App\Models\ProductBatch;
use App\Models\StockUnit;
use RuntimeException;

/**
 * Saldo insuficiente no lote ou, quando informada, na unidade física.
 * "available" é o que podia sair: o saldo da unidade ou a parte lacrada do lote.
 */
class InsufficientStockException extends RuntimeException
{
    public function __construct(public readonly ProductBatch $batch, public readonly string $available, public readonly ?StockUnit $unit = null)
    {
        parent::__construct($unit ? 'Saldo insuficiente na '.mb_strtolower($unit->label()).'.' : 'Saldo insuficiente no lote '.$batch->batch_code.'.');
    }
}
