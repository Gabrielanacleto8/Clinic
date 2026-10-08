<?php

namespace App\Exceptions;

use App\Models\ProductBatch;
use RuntimeException;

class InsufficientStockException extends RuntimeException
{
    public function __construct(public readonly ProductBatch $batch, public readonly string $available)
    {
        parent::__construct('Saldo insuficiente no lote '.$batch->batch_code.'.');
    }
}
