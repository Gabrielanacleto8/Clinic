<?php

namespace App\Http\Controllers;

use App\Http\Requests\ApproveTreatmentPlanRequest;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\TreatmentPlan;
use App\Services\TreatmentPlanApproval;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Aprovar (vira pacote e gera a cobrança), recusar ou encerrar um orçamento.
 */
class TreatmentPlanStatusController extends Controller
{
    public function __construct(private readonly TreatmentPlanApproval $approval) {}

    public function approve(ApproveTreatmentPlanRequest $request, Patient $patient, TreatmentPlan $treatmentPlan): RedirectResponse
    {
        $plan = $this->approval->approve($treatmentPlan, $request->payment(), $request->user());

        // A primeira sessão já pode ir para a agenda, com o procedimento do primeiro item.
        $links = [];

        if ($request->user()->can('create', Appointment::class)) {
            $links[] = ['url' => route('appointments.create', ['paciente' => $patient->getKey(), 'procedimento' => $plan->items->first()?->procedure_id]), 'label' => 'Agendar a primeira sessão'];
        }

        return redirect()->route('patients.plans.show', [$patient, $plan])
            ->with('success', 'Orçamento aprovado. O pacote está ativo e a cobrança foi lançada no financeiro.')
            ->with('success_links', $links);
    }

    public function decline(Request $request, Patient $patient, TreatmentPlan $treatmentPlan): RedirectResponse
    {
        Gate::authorize('decline', $treatmentPlan);

        $this->approval->decline($treatmentPlan, $request->user());

        return redirect()->route('patients.plans.show', [$patient, $treatmentPlan])->with('success', 'Orçamento marcado como recusado.');
    }

    public function close(Request $request, Patient $patient, TreatmentPlan $treatmentPlan): RedirectResponse
    {
        Gate::authorize('close', $treatmentPlan);

        $this->approval->close($treatmentPlan, $request->user());

        return redirect()->route('patients.plans.show', [$patient, $treatmentPlan])
            ->with('success', 'Pacote encerrado: as sessões restantes não podem mais ser usadas. Se houver devolução, lance-a no financeiro.');
    }
}
