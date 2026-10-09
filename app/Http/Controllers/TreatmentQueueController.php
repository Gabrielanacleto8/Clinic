<?php

namespace App\Http\Controllers;

use App\Enums\AppointmentStatus;
use App\Enums\TreatmentStatus;
use App\Models\Appointment;
use App\Models\Treatment;
use App\Support\ClinicTime;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class TreatmentQueueController extends Controller
{
    /**
     * Atendimentos: quem está sendo atendido e quem espera a vez. Nada disso
     * é gravado: sai da situação do atendimento e do agendamento.
     */
    public function index(): View
    {
        Gate::authorize('viewQueue', Treatment::class);

        // Presença confirmada é a chegada do paciente. Os demais agendados
        // de hoje ainda são esperados e entram só na contagem.
        [$waiting, $expected] = $this->todayAppointmentsNotStarted()
            ->partition(fn (Appointment $appointment) => $appointment->status === AppointmentStatus::Confirmed);

        return view('treatments.index', [
            'today' => ClinicTime::today(),
            'inProgress' => $this->treatmentsInProgress(),
            'waiting' => $waiting,
            'expectedCount' => $expected->count(),
        ]);
    }

    /**
     * Atendimentos em andamento, do mais antigo ao mais recente. Inclui os de
     * dias anteriores que ficaram sem finalizar, para não serem esquecidos.
     *
     * @return Collection<int, Treatment>
     */
    private function treatmentsInProgress(): Collection
    {
        return Treatment::query()
            ->with(['patient:id,name', 'professional:id,name', 'procedure:id,name'])
            // Atendimentos de pacientes arquivados não aparecem.
            ->whereHas('patient')
            ->where('status', TreatmentStatus::Draft)
            ->orderBy('performed_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Agendamentos de hoje em aberto e ainda sem atendimento iniciado.
     *
     * @return Collection<int, Appointment>
     */
    private function todayAppointmentsNotStarted(): Collection
    {
        return Appointment::query()
            ->with(['patient:id,name', 'professional:id,name', 'procedure:id,name'])
            ->whereHas('patient')
            ->whereIn('status', AppointmentStatus::open())
            ->whereNull('treatment_id')
            ->whereBetween('scheduled_at', ClinicTime::dayBounds(ClinicTime::today()))
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->get();
    }
}
