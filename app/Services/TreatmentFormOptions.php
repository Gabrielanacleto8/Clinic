<?php

namespace App\Services;

use App\Models\Patient;
use App\Models\Procedure;
use App\Models\ProductBatch;
use App\Models\StockUnit;
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
     * @return array{professionals: Collection<int, User>, procedures: Collection<int, Procedure>, batches: Collection<int, ProductBatch>, units: Collection<int, StockUnit>, procedureDefaults: array<int, array{amount: string|null, products: list<array{product_batch_id: int, stock_unit_id: int|null, quantity: string}>}>}
     */
    public function for(User $user): array
    {
        $procedures = Procedure::query()->selectable()->orderBy('name')->get(['id', 'name', 'price']);
        // Lotes que podem ser usados. Dentro de cada produto, o que vence primeiro aparece primeiro.
        $batches = ProductBatch::query()
            ->with('product:id,name,unit,unit_content,hours_after_opening,multi_patient_use,is_active')
            ->availableForUse()
            ->orderBy('product_id')
            ->firstToExpire()
            ->get(['id', 'product_id', 'batch_code', 'expires_on', 'quantity']);

        $units = $this->openUnits($batches);

        return [
            // Só o administrador escolhe o profissional; para os demais a lista nem é consultada.
            'professionals' => $user->isAdmin() ? User::query()->attending()->orderBy('name')->get(['id', 'name']) : new Collection,
            'procedures' => $procedures,
            'batches' => $batches,
            'units' => $units,
            'procedureDefaults' => $this->procedureDefaults($procedures, $batches, $units),
        ];
    }

    /**
     * Unidades abertas e ainda usáveis dos lotes fracionados, da aberta há
     * mais tempo à mais recente. Também deixa em cada lote "open_quantity",
     * para o formulário mostrar quantas unidades seguem lacradas.
     *
     * @param  Collection<int, ProductBatch>  $batches
     * @return Collection<int, StockUnit>
     */
    private function openUnits(Collection $batches): Collection
    {
        $fractioned = $batches->filter(fn (ProductBatch $batch) => $batch->product->isFractioned())->keyBy('id');

        if ($fractioned->isEmpty()) {
            return new Collection;
        }

        $units = StockUnit::query()
            ->withBalance()
            ->withConsumption()
            ->whereIn('product_batch_id', $fractioned->keys())
            ->orderBy('opened_at')
            ->orderBy('id')
            ->get(['id', 'product_batch_id', 'quantity', 'opened_at']);

        foreach ($fractioned as $batch) {
            $batch->setAttribute('open_quantity', $units->where('product_batch_id', $batch->id)->sum('quantity'));
        }

        return $units
            ->each(fn (StockUnit $unit) => $unit->setRelation('batch', $fractioned[$unit->product_batch_id]))
            ->filter(fn (StockUnit $unit) => $unit->isUsable())
            ->values();
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
     * vence primeiro. No fracionado, a unidade aberta há mais tempo daquele
     * lote, para não abrir outra à toa. É uma sugestão: o usuário confere
     * antes de registrar.
     *
     * @param  Collection<int, Procedure>  $procedures
     * @param  Collection<int, ProductBatch>  $batches  Já na ordem de uso dentro de cada produto.
     * @param  Collection<int, StockUnit>  $units  Unidades usáveis, da aberta há mais tempo à mais recente.
     * @return array<int, array{amount: string|null, products: list<array{product_batch_id: int, stock_unit_id: int|null, quantity: string}>}>
     */
    private function procedureDefaults(Collection $procedures, Collection $batches, Collection $units): array
    {
        $firstBatchIds = $batches->unique('product_id')->pluck('id', 'product_id');
        $oldestUnitIds = $units->unique('product_batch_id')->pluck('id', 'product_batch_id');
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
                    'stock_unit_id' => $oldestUnitIds->get($firstBatchIds[$row->product_id]),
                    'quantity' => LocalizedDecimal::quantityInput($row->quantity),
                ])
                ->values()
                ->all(),
        ]])->all();
    }
}
