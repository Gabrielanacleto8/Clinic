<?php

namespace App\Exceptions;

use App\Models\ProductBatch;
use App\Support\LocalizedDecimal;
use RuntimeException;

/**
 * O saldo do lote mudou entre a abertura da tela e o envio da contagem:
 * aplicar a contagem desfaria em silêncio o que foi movimentado nesse meio-tempo.
 */
class StaleStockException extends RuntimeException
{
    public function __construct(public readonly ProductBatch $batch, public readonly string $currentSealed)
    {
        $unit = $batch->product->unit->symbol();

        parent::__construct('O lote '.$batch->batch_code.' teve movimentação depois que a tela foi aberta: reconte. Saldo '
            .($batch->product->isFractioned() ? 'fechado' : '').' agora: '.LocalizedDecimal::quantity($currentSealed).' '.$unit.'.');
    }
}
