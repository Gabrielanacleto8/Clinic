<?php

namespace App\Http\Controllers;

use App\Enums\ProcedureCategory;
use App\Http\Requests\ProcedureRequest;
use App\Models\Procedure;
use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProcedureController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Procedure::class);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'categoria' => ['nullable', Rule::enum(ProcedureCategory::class)],
            'situacao' => ['nullable', Rule::in(['ativos', 'inativos', 'todos'])],
        ]);

        $status = $filters['situacao'] ?? 'ativos';

        $procedures = Procedure::query()
            ->when($filters['q'] ?? null, fn ($query, string $term) => $query->where('name', 'like', '%'.addcslashes($term, '\\%_').'%'))
            ->when($filters['categoria'] ?? null, fn ($query, string $category) => $query->where('category', $category))
            ->when($status !== 'todos', fn ($query) => $query->where('is_active', $status === 'ativos'))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('procedures.index', [
            'procedures' => $procedures,
            'filters' => ['q' => $filters['q'] ?? '', 'categoria' => $filters['categoria'] ?? '', 'situacao' => $status],
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', Procedure::class);

        return view('procedures.create', [
            'procedure' => new Procedure(['is_active' => true, 'duration_minutes' => 30]),
            'products' => $this->selectableProducts(),
        ]);
    }

    public function store(ProcedureRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request): void {
            Procedure::create($request->procedureData())->products()->sync($request->typicalProducts());
        });

        return redirect()->route('procedures.index')->with('success', 'Procedimento cadastrado.');
    }

    public function edit(Procedure $procedure): View
    {
        Gate::authorize('update', $procedure);

        return view('procedures.edit', [
            'procedure' => $procedure->load('products:id'),
            'products' => $this->selectableProducts(),
        ]);
    }

    public function update(ProcedureRequest $request, Procedure $procedure): RedirectResponse
    {
        DB::transaction(function () use ($request, $procedure): void {
            $procedure->update($request->procedureData());
            $procedure->products()->sync($request->typicalProducts());
        });

        return redirect()->route('procedures.index')->with('success', 'Procedimento atualizado.');
    }

    /**
     * @return Collection<int, Product>
     */
    private function selectableProducts(): Collection
    {
        return Product::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'unit']);
    }
}
