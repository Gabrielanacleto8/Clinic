<?php

namespace App\Services;

use App\Models\Patient;
use App\Models\Procedure;
use App\Models\ProductBatch;
use App\Models\StockUnit;
use App\Models\Treatment;
use App\Models\TreatmentPlanItem;
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
     * @return array{professionals: Collection<int, User>, procedures: Collection<int, Procedure>, batches: Collection<int, ProductBatch>, units: Collection<int, StockUnit>, procedureDefaults: array<int, array{amount: string|null, products: list<array{product_batch_id: int, stock_unit_id: int|null, quantity: string}>, missing: list<string>}>}
     */
    public function for(User $user, ?int $currentProcedureId = null): array
    {
        // O procedimento do agendamento continua na lista mesmo que tenha sido desativado.
        $procedures = Procedure::query()->selectable($currentProcedureId)->orderBy('name')->get(['id', 'name', 'price']);
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

        $patient = is_numeric($patientId) ? Patient::find((int) $patientId) : null;

        return [
            'scope' => self::DIALOG,
            'treatment' => new Treatment,
            'patient' => $patient,
            'planSessions' => $patient ? $this->planSessions($patient) : new Collection,
            ...$this->for($request->user()),
        ];
    }

    /**
     * Sessões de pacote que o paciente ainda pode usar.
     *
     * @return Collection<int, TreatmentPlanItem>
     */
    public function planSessions(Patient $patient): Collection
    {
        return TreatmentPlanItem::query()
            ->availableFor($patient->getKey())
            ->with('procedure:id,name')
            ->orderBy('treatment_plan_id')
            ->orderBy('id')
            ->get(['id', 'treatment_plan_id', 'procedure_id', 'sessions']);
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
     * @return array<int, array{amount: string|null, products: list<array{product_batch_id: int, stock_unit_id: int|null, quantity: string}>, missing: list<string>}>
     */
    private function procedureDefaults(Collection $procedures, Collection $batches, Collection $units): array
    {
        $firstBatchIds = $batches->unique('product_id')->pluck('id', 'product_id');
        $oldestUnitIds = $units->unique('product_batch_id')->pluck('id', 'product_batch_id');
        // Produto com frasco já aberto em algum lote: esse lote vem antes do que
        // vence primeiro, para não abrir outro frasco à toa.
        $batchProducts = $batches->pluck('product_id', 'id');
        $openBatchIds = $units->mapWithKeys(fn (StockUnit $unit) => [$batchProducts[$unit->product_batch_id] => $unit->product_batch_id])
            ->filter(fn ($batchId, $productId) => $batchProducts->search($batchId) !== false);
        $typical = DB::table('procedure_product')
            ->join('products', 'products.id', '=', 'procedure_product.product_id')
            ->whereIn('procedure_id', $procedures->modelKeys())
            ->orderBy('products.name')
            ->get(['procedure_id', 'product_id', 'procedure_product.quantity', 'products.name'])
            ->groupBy('procedure_id');

        return $procedures->mapWithKeys(function (Procedure $procedure) use ($typical, $firstBatchIds, $openBatchIds, $oldestUnitIds) {
            $rows = collect($typical->get($procedure->id));

            return [$procedure->id => [
                'amount' => LocalizedDecimal::moneyInput($procedure->price),
                'products' => $rows
                    ->filter(fn (object $row) => $firstBatchIds->has($row->product_id))
                    ->map(function (object $row) use ($firstBatchIds, $openBatchIds, $oldestUnitIds) {
                        $batchId = $openBatchIds[$row->product_id] ?? $firstBatchIds[$row->product_id];

                        return [
                            'product_batch_id' => $batchId,
                            'stock_unit_id' => $oldestUnitIds->get($batchId),
                            'quantity' => LocalizedDecimal::quantityInput($row->quantity),
                        ];
                    })
                    ->values()
                    ->all(),
                // Produtos típicos sem lote utilizável: o usuário precisa saber que não foram sugeridos.
                'missing' => $rows->reject(fn (object $row) => $firstBatchIds->has($row->product_id))->pluck('name')->values()->all(),
            ]];
        })->all();
    }
}
