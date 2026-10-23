<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Enums\ProductCategory;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\StaleStockException;
use App\Exceptions\UnusableStockException;
use App\Http\Requests\StockInventoryRequest;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Services\AuditLogger;
use App\Services\StockLedger;
use App\Support\ClinicTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Inventário: conferência do saldo físico de vários lotes de uma vez. Cada
 * diferença vira uma movimentação de ajuste no livro de estoque.
 */
class StockInventoryController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function edit(Request $request): View
    {
        Gate::authorize('adjust', Product::class);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'categoria' => ['nullable', Rule::enum(ProductCategory::class)],
        ]);

        // Lotes com saldo no sistema e dentro da validade, agrupados por
        // produto na ordem de uso. Lote vencido não se ajusta: sai como perda.
        $batches = ProductBatch::query()
            ->select('product_batches.*')
            ->join('products', 'products.id', '=', 'product_batches.product_id')
            ->with('product:id,name,code,unit,unit_content')
            ->withOpenQuantity()
            ->where('product_batches.quantity', '>', 0)
            ->where(fn ($query) => $query->whereNull('product_batches.expires_on')->orWhere('product_batches.expires_on', '>=', ClinicTime::today()))
            ->when($filters['q'] ?? null, fn ($query, string $term) => $query->search($term))
            ->when($filters['categoria'] ?? null, fn ($query, string $category) => $query->where('products.category', $category))
            ->orderBy('products.name')
            ->orderByRaw('product_batches.expires_on is null')
            ->orderBy('product_batches.expires_on')
            ->orderBy('product_batches.id')
            ->paginate(StockInventoryRequest::PER_PAGE)
            ->withQueryString();

        return view('stock.inventory', [
            'batches' => $batches,
            'filters' => ['q' => $filters['q'] ?? '', 'categoria' => $filters['categoria'] ?? ''],
            'countedToday' => $this->countedToday(),
        ]);
    }

    /**
     * Lotes já conferidos hoje, pela auditoria das conferências do dia: quem
     * conta em várias páginas vê o que já fez, sem coluna nova no lote.
     *
     * @return array<int, true>
     */
    private function countedToday(): array
    {
        $ids = AuditLog::query()
            ->where('action', AuditAction::StockCounted->value)
            ->where('created_at', '>=', ClinicTime::dayBounds(ClinicTime::today())[0])
            ->pluck('metadata')
            ->flatMap(fn (?array $metadata) => $metadata['batch_ids'] ?? []);

        return array_fill_keys($ids->map(fn ($id) => (int) $id)->all(), true);
    }

    public function update(StockInventoryRequest $request, StockLedger $ledger): RedirectResponse
    {
        $counts = $request->counts();

        if ($counts === []) {
            throw ValidationException::withMessages(['counted' => 'Informe a quantidade contada de pelo menos um lote.']);
        }

        // Lote que se moveu entre abrir a tela e enviar a contagem é recusado
        // sozinho: os demais são gravados, e só ele precisa ser recontado.
        $stale = [];

        [$adjusted, $counted] = DB::transaction(function () use ($request, $ledger, $counts, &$stale): array {
            $batches = ProductBatch::query()->whereKey(array_keys($counts))->get();

            if ($batches->count() !== count($counts)) {
                throw ValidationException::withMessages(['counted' => 'Um dos lotes informados não existe mais. Recarregue a página.']);
            }

            $adjusted = [];
            $counted = [];

            foreach ($batches as $batch) {
                try {
                    $movement = $ledger->adjustTo($batch, $counts[$batch->id], (string) $request->validated('reason'), $request->user(), $request->expectedFor($batch->id));
                } catch (StaleStockException $exception) {
                    $stale["counted.{$batch->id}"] = $exception->getMessage();

                    continue;
                } catch (UnusableStockException $exception) {
                    // O lote venceu entre abrir a tela e enviar a contagem.
                    throw ValidationException::withMessages(["counted.{$batch->id}" => $exception->getMessage()]);
                } catch (InsufficientStockException) {
                    throw ValidationException::withMessages(["counted.{$batch->id}" => 'A contagem deixaria o saldo do lote negativo.']);
                }

                $counted[] = $batch->id;

                if ($movement !== null) {
                    $adjusted[] = $batch->id;
                }
            }

            // A conferência fica registrada mesmo quando tudo confere: é a prova de que o lote foi contado.
            if ($counted !== []) {
                $this->audit->log(AuditAction::StockCounted, null, ['batch_ids' => $counted, 'adjusted_batch_ids' => $adjusted]);
            }

            return [count($adjusted), count($counted)];
        });

        $matched = $counted - $adjusted;
        $response = redirect()->back(fallback: route('stock.inventory.edit'));

        if ($stale !== []) {
            $response->withErrors($stale)->withInput();
        }

        if ($counted === 0) {
            return $response->with('warning', 'Nenhuma contagem foi gravada: os lotes se moveram depois que a tela foi aberta. Reconte-os.');
        }

        return $response->with($stale === [] ? 'success' : 'warning', match (true) {
            $adjusted === 0 => 'Inventário conferido: todas as contagens batem com o saldo.',
            default => "Inventário registrado: {$adjusted} ".($adjusted === 1 ? 'lote ajustado' : 'lotes ajustados')
                .($matched > 0 ? " e {$matched} ".($matched === 1 ? 'confere' : 'conferem').' com o saldo.' : '.'),
        }.($stale === [] ? '' : ' '.count($stale).' '.(count($stale) === 1 ? 'lote se moveu' : 'lotes se moveram').' enquanto você contava: reconte o que está marcado.'));
    }
}
