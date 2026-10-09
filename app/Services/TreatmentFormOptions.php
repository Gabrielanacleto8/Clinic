<?php

namespace App\Services;

use App\Models\Procedure;
use App\Models\ProductBatch;
use App\Models\Treatment;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Listas dos campos de seleção do formulário de atendimento.
 */
class TreatmentFormOptions
{
    /**
     * @return array{professionals: Collection<int, User>, procedures: Collection<int, Procedure>, batches: Collection<int, ProductBatch>}
     */
    public function for(Treatment $treatment, User $user): array
    {
        $current = $treatment->exists ? $treatment->products->pluck('product_batch_id')->all() : [];

        return [
            // Só o administrador escolhe o profissional; para os demais a lista nem é consultada.
            'professionals' => $user->isAdmin() ? User::query()->attending()->orderBy('name')->get(['id', 'name']) : new Collection,
            'procedures' => Procedure::query()->selectable($treatment->procedure_id)->orderBy('name')->get(['id', 'name']),
            // Lotes que podem ser usados, mais os que este atendimento já usa.
            // Dentro de cada produto, o que vence primeiro aparece primeiro.
            'batches' => ProductBatch::query()
                ->with('product:id,name,unit')
                ->where(fn ($query) => $query
                    ->where(fn ($usable) => $usable->availableForUse())
                    ->when($current !== [], fn ($inner) => $inner->orWhereIn('id', $current)))
                ->orderBy('product_id')
                ->firstToExpire()
                ->get(['id', 'product_id', 'batch_code', 'expires_on', 'quantity']),
        ];
    }
}
