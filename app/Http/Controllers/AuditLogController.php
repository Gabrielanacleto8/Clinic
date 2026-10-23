<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\ClinicTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    /**
     * Trilha de auditoria: quem fez o quê, quando e de onde. Filtrar por
     * paciente reúne tudo o que tocou nele (cadastro, prontuário, agenda,
     * financeiro), para responder a um pedido do titular dos dados.
     */
    public function index(Request $request, AuditLogger $audit): View
    {
        Gate::authorize('viewAny', AuditLog::class);

        $filters = $request->validate([
            'usuario' => ['nullable', 'integer'],
            'acao' => ['nullable', Rule::enum(AuditAction::class)],
            'paciente' => ['nullable', 'integer'],
            'de' => ['nullable', 'date_format:Y-m-d'],
            'ate' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:de'],
        ], [], ['usuario' => 'usuário', 'acao' => 'ação', 'paciente' => 'paciente', 'de' => 'data inicial', 'ate' => 'data final']);

        $logs = AuditLog::query()
            ->with('user:id,name')
            ->when($filters['usuario'] ?? null, fn (Builder $query, int|string $userId) => $query->where('user_id', $userId))
            ->when($filters['acao'] ?? null, fn (Builder $query, string $action) => $query->where('action', $action))
            ->when($filters['paciente'] ?? null, fn (Builder $query, int|string $patientId) => $query->where(
                fn (Builder $inner) => $inner
                    ->where(fn (Builder $own) => $own->where('auditable_type', (new Patient)->getMorphClass())->where('auditable_id', $patientId))
                    ->orWhere('metadata->patient_id', (int) $patientId),
            ))
            ->when($filters['de'] ?? null, fn (Builder $query, string $from) => $query->where('created_at', '>=', ClinicTime::dayBounds($from)[0]))
            ->when($filters['ate'] ?? null, fn (Builder $query, string $until) => $query->where('created_at', '<=', ClinicTime::dayBounds($until)[1]))
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        // Consultar a trilha também fica na trilha. Só os filtros usados, nunca o que foi buscado.
        $audit->log(AuditAction::AuditViewed, null, ['filters' => array_keys(array_filter($filters, filled(...)))]);

        $patientId = $filters['paciente'] ?? null;

        return view('audit.index', [
            'logs' => $logs,
            'filters' => [
                'usuario' => (string) ($filters['usuario'] ?? ''),
                'acao' => $filters['acao'] ?? '',
                'paciente' => (string) ($patientId ?? ''),
                'de' => $filters['de'] ?? '',
                'ate' => $filters['ate'] ?? '',
            ],
            'users' => User::query()->orderBy('name')->pluck('name', 'id'),
            'actions' => AuditAction::options(),
            // Com o filtro por paciente, o nome dele no título (arquivado também).
            'patient' => $patientId ? Patient::withTrashed()->find((int) $patientId, ['id', 'name']) : null,
        ]);
    }
}
