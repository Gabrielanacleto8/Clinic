<?php

namespace App\Http\Controllers;

use App\Enums\AppointmentStatus;
use App\Enums\AuditAction;
use App\Enums\ProcedureCategory;
use App\Http\Requests\ProcedureRequest;
use App\Models\Appointment;
use App\Models\Procedure;
use App\Models\Product;
use App\Services\AuditLogger;
use App\Support\ClinicTime;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProcedureController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

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
            // Produtos de uso típico, exibidos nos detalhes de cada procedimento.
            ->with('products:id,name,unit')
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
            $procedure = Procedure::create($request->procedureData());
            $procedure->products()->sync($request->typicalProducts());

            $this->audit->log(AuditAction::ProcedureCreated, $procedure, ['price' => $procedure->price]);
        });

        return redirect()->route('procedures.index')->with('success', 'Procedimento cadastrado.');
    }

    public function edit(Procedure $procedure): View
    {
        Gate::authorize('update', $procedure);

        return view('procedures.edit', [
            'procedure' => $procedure->load('products:id'),
            'products' => $this->selectableProducts($procedure),
            'openAppointments' => $this->openAppointments($procedure),
        ]);
    }

    public function update(ProcedureRequest $request, Procedure $procedure): RedirectResponse
    {
        $deactivated = DB::transaction(function () use ($request, $procedure): bool {
            $wasActive = $procedure->is_active;
            $oldPrice = $procedure->price;
            $oldProducts = $procedure->products()->pluck('products.id')->sort()->values()->all();

            $procedure->fill($request->procedureData());
            $fields = array_keys($procedure->getDirty());
            $procedure->save();
            $procedure->products()->sync($request->typicalProducts());

            if (array_keys($request->typicalProducts()) !== [] || $oldProducts !== []) {
                $newProducts = collect(array_keys($request->typicalProducts()))->sort()->values()->all();

                if ($newProducts !== $oldProducts) {
                    $fields[] = 'products';
                }
            }

            if ($fields !== []) {
                // Preço antigo e novo entram na trilha: é o valor sugerido na cobrança, não dado pessoal.
                $this->audit->log(AuditAction::ProcedureUpdated, $procedure, array_filter([
                    'fields' => $fields,
                    'price_from' => in_array('price', $fields, true) ? $oldPrice : null,
                    'price_to' => in_array('price', $fields, true) ? $procedure->price : null,
                ], fn ($value) => $value !== null));
            }

            return $wasActive && ! $procedure->is_active;
        });

        $message = 'Procedimento atualizado.';

        // Desativar não cancela nada: os agendamentos já marcados seguem válidos e registráveis.
        if ($deactivated && ($open = $this->openAppointments($procedure)) > 0) {
            $message .= " Há {$open} ".($open === 1 ? 'agendamento em aberto' : 'agendamentos em aberto').' com este procedimento: eles continuam válidos e podem ser registrados.';
        }

        return redirect()->route('procedures.index')->with('success', $message);
    }

    /**
     * Agendamentos em aberto, de hoje em diante, marcados com o procedimento.
     */
    private function openAppointments(Procedure $procedure): int
    {
        return Appointment::query()
            ->where('procedure_id', $procedure->getKey())
            ->whereIn('status', AppointmentStatus::open())
            ->where('scheduled_at', '>=', ClinicTime::dayBounds(ClinicTime::today())[0])
            ->count();
    }

    /**
     * Produtos ativos, mais os que o procedimento já usa mesmo que inativos:
     * fora da lista, eles seriam apagados do procedimento ao salvar.
     *
     * @return Collection<int, Product>
     */
    private function selectableProducts(?Procedure $procedure = null): Collection
    {
        $current = $procedure?->products->modelKeys() ?? [];

        return Product::query()
            ->where(fn ($query) => $query->where('is_active', true)->when($current !== [], fn ($inner) => $inner->orWhereIn('id', $current)))
            ->orderBy('name')
            ->get(['id', 'name', 'unit', 'is_active']);
    }
}
