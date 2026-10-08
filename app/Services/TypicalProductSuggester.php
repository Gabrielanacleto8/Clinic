<?php

namespace App\Services;

use App\Models\ProductBatch;
use Illuminate\Support\Facades\DB;

/**
 * Sugere os produtos de um atendimento a partir do que o procedimento
 * normalmente usa, escolhendo para cada produto o lote que vence primeiro.
 */
class TypicalProductSuggester
{
    /**
     * @return list<array{product_batch_id: int, quantity: string}>
     */
    public function suggestFor(int $procedureId): array
    {
        $quantities = DB::table('procedure_product')->where('procedure_id', $procedureId)->pluck('quantity', 'product_id');

        if ($quantities->isEmpty()) {
            return [];
        }

        // Lotes utilizáveis com saldo, do vencimento mais próximo ao mais
        // distante; os sem validade ficam por último.
        $batches = ProductBatch::query()
            ->whereIn('product_id', $quantities->keys())
            ->usable()
            ->where('quantity', '>', 0)
            ->orderByRaw('expires_on is null')
            ->orderBy('expires_on')
            ->orderBy('id')
            ->get(['id', 'product_id']);

        return $batches
            ->unique('product_id')
            ->map(fn (ProductBatch $batch) => [
                'product_batch_id' => $batch->id,
                'quantity' => (string) $quantities[$batch->product_id],
            ])
            ->values()
            ->all();
    }
}
