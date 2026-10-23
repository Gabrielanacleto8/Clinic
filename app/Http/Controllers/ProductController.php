<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Enums\ProductCategory;
use App\Enums\StockMovementType;
use App\Http\Requests\ProductRequest;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\AuditLogger;
use App\Services\StockAlerts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProductController extends Controller
{
    private const EDIT_DIALOG = 'product-edit';

    public function __construct(private readonly AuditLogger $audit) {}

    private const FILTERS = ['ativos' => 'Ativos', 'baixo' => 'Estoque baixo', 'vencendo' => 'Vencendo ou vencidos', 'parados' => 'Sem movimento há 90 dias', 'inativos' => 'Inativos', 'todos' => 'Todos'];

    /** Dias sem movimentação para um produto com saldo contar como parado. */
    public const IDLE_DAYS = 90;

    /**
     * Aba Produtos: o cadastro, com o saldo disponível e os alertas de cada item.
     */
    public function index(Request $request, StockAlerts $alerts): View
    {
        Gate::authorize('viewAny', Product::class);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'categoria' => ['nullable', Rule::enum(ProductCategory::class)],
            'situacao' => ['nullable', Rule::in(array_keys(self::FILTERS))],
        ]);

        $status = $filters['situacao'] ?? 'ativos';

        $products = Product::query()
            ->withAvailableQuantity()
            // Contagens de lotes em alerta, na mesma consulta da listagem.
            ->withCount([
                'batches as expiring_batches_count' => fn ($query) => $query->expiringSoon(),
                'batches as expired_batches_count' => fn ($query) => $query->expiredWithStock(),
            ])
            ->when($filters['q'] ?? null, fn ($query, string $term) => $query->search($term))
            ->when($filters['categoria'] ?? null, fn ($query, string $category) => $query->where('category', $category))
            ->when($status === 'ativos', fn ($query) => $query->where('is_active', true))
            ->when($status === 'inativos', fn ($query) => $query->where('is_active', false))
            ->when($status === 'baixo', fn ($query) => $query->lowStock())
            ->when($status === 'vencendo', fn ($query) => $query->where(
                fn ($inner) => $inner->whereHas('batches', fn ($batches) => $batches->expiringSoon())
                    ->orWhereHas('batches', fn ($batches) => $batches->expiredWithStock()),
            ))
            // Parado: ativo, com saldo e sem nenhuma movimentação nos últimos dias. Capital imobilizado.
            ->when($status === 'parados', fn ($query) => $query
                ->where('is_active', true)
                ->whereHas('batches', fn ($batches) => $batches->where('quantity', '>', 0))
                ->whereDoesntHave('batches.movements', fn ($movements) => $movements->where('stock_movements.created_at', '>=', now()->subDays(self::IDLE_DAYS))))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('products.index', [
            'products' => $products,
            'statusOptions' => self::FILTERS,
            'filters' => ['q' => $filters['q'] ?? '', 'categoria' => $filters['categoria'] ?? '', 'situacao' => $status],
            'editing' => $this->productBeingEdited($request),
            'alerts' => $alerts->summary(),
        ]);
    }

    /**
     * Produto do diálogo de edição da listagem, quando ele volta com erro de validação.
     */
    private function productBeingEdited(Request $request): ?Product
    {
        $id = $request->old('_dialog') === self::EDIT_DIALOG ? $request->old('product_id') : null;

        return is_numeric($id) ? Product::find((int) $id) : null;
    }

    public function create(): View
    {
        Gate::authorize('create', Product::class);

        return view('products.create', ['product' => new Product(['is_active' => true, 'minimum_stock' => 0])]);
    }

    public function store(ProductRequest $request): RedirectResponse
    {
        $product = DB::transaction(function () use ($request): Product {
            $product = Product::create($request->productData());

            $this->audit->log(AuditAction::ProductCreated, $product);

            return $product;
        });

        return redirect()->route('products.show', $product)->with('success', 'Produto cadastrado. Lance a primeira entrada de estoque.');
    }

    public function show(Product $product): View
    {
        Gate::authorize('view', $product);

        // Saldo disponível e a descartar, pela mesma regra da listagem.
        $quantities = Product::query()->withAvailableQuantity()->whereKey($product->getKey())->first(['products.id']);
        $product->setAttribute('available_quantity', $quantities->available_quantity);
        $product->setAttribute('discard_quantity', $quantities->discard_quantity);
        $product->loadCount(['units as open_units_count' => fn ($query) => $query->withBalance()]);

        return view('products.show', [
            'product' => $product,
            'averageCost' => $this->averageCost($product),
            // Lotes com saldo primeiro, do vencimento mais próximo ao mais distante.
            'batches' => $product->batches()->withOpenQuantity()->orderByRaw('quantity = 0')->firstToExpire()->get(),
            'movements' => StockMovement::query()
                ->whereIn('product_batch_id', $product->batches()->select('id'))
                ->with(['batch:id,batch_code', 'user:id,name', 'treatment:id,patient_id,professional_id', 'treatment.professional:id,name'])
                ->latest('id')
                ->paginate(15),
        ]);
    }

    /**
     * Custo médio por unidade de medida do que está em estoque: a média das
     * entradas de cada lote com saldo, ponderada pelo saldo. Calculado na
     * hora, em uma consulta; nulo sem entrada com custo.
     */
    private function averageCost(Product $product): ?string
    {
        $rows = DB::table('stock_movements')
            ->join('product_batches', 'product_batches.id', '=', 'stock_movements.product_batch_id')
            ->where('product_batches.product_id', $product->getKey())
            ->where('product_batches.quantity', '>', 0)
            ->where('stock_movements.type', StockMovementType::Entry->value)
            ->whereNotNull('stock_movements.unit_cost')
            ->where('stock_movements.quantity', '>', 0)
            ->groupBy('product_batches.id', 'product_batches.quantity')
            ->selectRaw('product_batches.quantity as balance, sum(stock_movements.quantity * stock_movements.unit_cost) / sum(stock_movements.quantity) as average')
            ->get();

        $balance = (float) $rows->sum('balance');

        return $balance > 0 ? number_format($rows->sum(fn ($row) => (float) $row->balance * (float) $row->average) / $balance, 4, '.', '') : null;
    }

    public function edit(Product $product): View
    {
        Gate::authorize('update', $product);

        return view('products.edit', ['product' => $product]);
    }

    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        DB::transaction(function () use ($request, $product): void {
            $product->fill($request->productData());

            // Só os nomes dos campos entram na auditoria, nunca os valores.
            $changed = array_keys($product->getDirty());

            if ($changed !== []) {
                $product->save();

                $this->audit->log(AuditAction::ProductUpdated, $product, ['fields' => $changed]);
            }
        });

        // Editado pelo diálogo da listagem, volta para ela com os filtros que estavam aplicados.
        $destination = $request->input('_dialog') === self::EDIT_DIALOG ? back() : redirect()->route('products.show', $product);

        return $destination->with('success', 'Produto atualizado.');
    }
}
