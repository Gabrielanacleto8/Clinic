<?php

namespace App\Services;

use App\Models\Patient;
use App\Models\Procedure;
use App\Models\ProductBatch;
use App\Models\Treatment;
use App\Models\User;
use App\Support\LocalizedDecimal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * O que o formulário de registro de atendimento precisa: as listas dos campos
 * de seleção e o que cada procedimento preenche sozinho.
 */
class TreatmentFormOptions
{
    public const DIALOG = 'new-treatment';

    public const CONFIRMATION = 'Registrar o atendimento? O estoque será baixado e o lançamento financeiro será gerado.';

    /**
     * @return array{professionals: Collection<int, User>, procedures: Collection<int, Procedure>, batches: Collection<int, ProductBatch>, procedureDefaults: array<int, array{amount: string|null, products: list<array{product_batch_id: int, quantity: string}>}>}
     */
    public function for(User $user): array
    {
        $procedures = Procedure::query()->selectable()->orderBy('name')->get(['id', 'name', 'price']);
        // Lotes que podem ser usados. Dentro de cada produto, o que vence primeiro aparece primeiro.
        $batches = ProductBatch::query()
            ->with('product:id,name,unit')
            ->availableForUse()
            ->orderBy('product_id')
            ->firstToExpire()
            ->get(['id', 'product_id', 'batch_code', 'expires_on', 'quantity']);

        return [
            // Só o administrador escolhe o profissional; para os demais a lista nem é consultada.
            'professionals' => $user->isAdmin() ? User::query()->attending()->orderBy('name')->get(['id', 'name']) : new Collection,
            'procedures' => $procedures,
            'batches' => $batches,
            'procedureDefaults' => $this->procedureDefaults($procedures, $batches),
        ];
    }

    /**
     * Dados do diálogo "Registrar atendimento", em que o paciente é escolhido
     * no próprio formulário. Ele só vem preenchido quando o formulário volta
     * com erro de validação.
     *
     * @return array<string, mixed>
     */
    public function forDialog(Request $request): array
    {
        $patientId = $request->old('_dialog') === self::DIALOG ? $request->old('patient_id') : null;

        return [
            'scope' => self::DIALOG,
            'treatment' => new Treatment,
            'patient' => is_numeric($patientId) ? Patient::find((int) $patientId) : null,
            ...$this->for($request->user()),
        ];
    }

    /**
     * O que a escolha de cada procedimento preenche no formulário: o valor do
     * catálogo e os produtos que ele normalmente usa, cada um no lote que
     * vence primeiro. É uma sugestão: o usuário confere antes de registrar.
     *
     * @param  Collection<int, Procedure>  $procedures
     * @param  Collection<int, ProductBatch>  $batches  Já na ordem de uso dentro de cada produto.
     * @return array<int, array{amount: string|null, products: list<array{product_batch_id: int, quantity: string}>}>
     */
    private function procedureDefaults(Collection $procedures, Collection $batches): array
    {
        $firstBatchIds = $batches->unique('product_id')->pluck('id', 'product_id');
        $typical = DB::table('procedure_product')
            ->whereIn('procedure_id', $procedures->modelKeys())
            ->orderBy('product_id')
            ->get(['procedure_id', 'product_id', 'quantity'])
            ->groupBy('procedure_id');

        return $procedures->mapWithKeys(fn (Procedure $procedure) => [$procedure->id => [
            'amount' => LocalizedDecimal::moneyInput($procedure->price),
            'products' => collect($typical->get($procedure->id))
                ->filter(fn (object $row) => $firstBatchIds->has($row->product_id))
                ->map(fn (object $row) => [
                    'product_batch_id' => $firstBatchIds[$row->product_id],
                    'quantity' => LocalizedDecimal::quantityInput($row->quantity),
                ])
                ->values()
                ->all(),
        ]])->all();
    }
}
