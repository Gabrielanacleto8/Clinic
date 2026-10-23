<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Models\Treatment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DashboardMetrics;
use App\Services\TreatmentFormOptions;
use App\Support\ClinicTime;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HomeController extends Controller
{
    /**
     * Início: o dia da clínica em resumo. Cada bloco só é montado, e
     * consultado no banco, para quem tem a permissão correspondente.
     */
    public function index(Request $request, DashboardMetrics $metrics, TreatmentFormOptions $options, AuditLogger $audit): View
    {
        $user = $request->user();
        $seesAgenda = $user->hasPermission(Permission::AgendaView);
        $seesStock = $user->hasPermission(Permission::StockView);
        $showAll = $request->boolean('todos');

        // Quem atende abre o dia com a própria agenda; "Ver todos" mostra a de todos.
        $attends = $seesAgenda && User::query()->attending()->whereKey($user->getKey())->exists();
        $professionalId = $attends && ! $showAll ? $user->getKey() : null;

        // Os agendamentos de hoje e dos próximos dias são leitura da agenda.
        if ($seesAgenda) {
            $audit->log(AuditAction::AgendaViewed, null, ['view' => 'home', 'professional_id' => $professionalId]);
        }

        return view('home', [
            'today' => ClinicTime::local(now()),
            'totals' => $metrics->totals($user),
            'attends' => $attends,
            'showAll' => $showAll,
            'todayAppointments' => $seesAgenda ? $metrics->todayAppointments($professionalId) : null,
            'pending' => $seesAgenda ? $metrics->pendingRegistrations($professionalId) : null,
            'upcomingAppointments' => $seesAgenda ? $metrics->upcomingAppointments() : null,
            'returns' => $seesAgenda && $user->hasPermission(Permission::RecordsView) ? $metrics->returnsToSchedule() : null,
            'lowStockProducts' => $seesStock ? $metrics->lowStockProducts() : null,
            'expiringBatches' => $seesStock ? $metrics->expiringBatches() : null,
            'treatmentForm' => $user->can('create', Treatment::class) ? $options->forDialog($request) : null,
        ]);
    }
}
