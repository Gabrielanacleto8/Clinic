<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Models\Patient;
use App\Models\Treatment;
use App\Services\DashboardMetrics;
use App\Services\TreatmentFormOptions;
use App\Support\ClinicTime;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HomeController extends Controller
{
    private const TREATMENT_DIALOG = 'new-treatment';

    /**
     * Início: o dia da clínica em resumo. Cada bloco só é montado, e
     * consultado no banco, para quem tem a permissão correspondente.
     */
    public function index(Request $request, DashboardMetrics $metrics, TreatmentFormOptions $options): View
    {
        $user = $request->user();
        $seesAgenda = $user->hasPermission(Permission::AgendaView);
        $seesStock = $user->hasPermission(Permission::StockView);

        return view('home', [
            'today' => ClinicTime::local(now()),
            'totals' => $metrics->totals($user),
            'todayAppointments' => $seesAgenda ? $metrics->todayAppointments() : null,
            'upcomingAppointments' => $seesAgenda ? $metrics->upcomingAppointments() : null,
            'lowStockProducts' => $seesStock ? $metrics->lowStockProducts() : null,
            'expiringBatches' => $seesStock ? $metrics->expiringBatches() : null,
            'treatmentForm' => $user->can('create', Treatment::class) ? $this->treatmentForm($request, $options) : null,
        ]);
    }

    /**
     * Dados do diálogo "Novo atendimento". O paciente só vem preenchido
     * quando o formulário volta com erro de validação.
     *
     * @return array<string, mixed>
     */
    private function treatmentForm(Request $request, TreatmentFormOptions $options): array
    {
        $treatment = new Treatment;
        $patientId = $request->old('_dialog') === self::TREATMENT_DIALOG ? $request->old('patient_id') : null;

        return [
            'scope' => self::TREATMENT_DIALOG,
            'treatment' => $treatment,
            'patient' => is_numeric($patientId) ? Patient::find((int) $patientId) : null,
            ...$options->for($treatment, $request->user()),
        ];
    }
}
