<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Enums\ProductCategory;
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

    private const FILTERS = ['ativos' => 'Ativos', 'baixo' => 'Estoque baixo', 'vencendo' => 'Vencendo ou vencidos', 'inativos' => 'Inativos', 'todos' => 'Todos'];

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

        $product->loadSum(['batches as available_quantity' => fn ($query) => $query->usable()], 'quantity');

        return view('products.show', [
            'product' => $product,
            // Lotes com saldo primeiro, do vencimento mais próximo ao mais distante.
            'batches' => $product->batches()->orderByRaw('quantity = 0')->firstToExpire()->get(),
            'movements' => StockMovement::query()
                ->whereIn('product_batch_id', $product->batches()->select('id'))
                ->with(['batch:id,batch_code', 'user:id,name', 'treatment:id,patient_id,professional_id', 'treatment.professional:id,name'])
                ->latest('id')
                ->paginate(15),
        ]);
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
