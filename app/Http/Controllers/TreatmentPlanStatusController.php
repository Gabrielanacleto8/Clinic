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
use Illuminate\Validation\Rule;

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

        // Convertido pela lista, o usuário fica na lista, com os filtros que tinha: a
        // URL anterior vem da sessão (gravada pelo servidor a cada página), nunca do
        // formulário nem do cabeçalho Referer. Pela página do orçamento, fica nela.
        $target = $request->backToList()
            ? redirect()->to($this->listUrl($request))
            : redirect()->route('patients.plans.show', [$patient, $plan]);

        if ($request->backToList()) {
            array_unshift($links, ['url' => route('patients.plans.show', [$patient, $plan]), 'label' => 'Ver o pacote']);
        }

        return $target
            ->with('success', 'Orçamento nº '.$plan->id.' convertido em venda. O pacote está ativo e a cobrança foi lançada no financeiro.')
            ->with('success_links', $links);
    }

    public function decline(Request $request, Patient $patient, TreatmentPlan $treatmentPlan): RedirectResponse
    {
        Gate::authorize('decline', $treatmentPlan);
        $backToList = $this->cameFromList($request);

        $this->approval->decline($treatmentPlan, $request->user());

        return ($backToList ? redirect()->to($this->listUrl($request)) : redirect()->route('patients.plans.show', [$patient, $treatmentPlan]))
            ->with('success', 'Orçamento nº '.$treatmentPlan->id.' marcado como recusado.');
    }

    public function close(Request $request, Patient $patient, TreatmentPlan $treatmentPlan): RedirectResponse
    {
        Gate::authorize('close', $treatmentPlan);
        $backToList = $this->cameFromList($request);

        $this->approval->close($treatmentPlan, $request->user());

        return ($backToList ? redirect()->to($this->listUrl($request)) : redirect()->route('patients.plans.show', [$patient, $treatmentPlan]))
            ->with('success', 'Pacote nº '.$treatmentPlan->id.' encerrado: as sessões restantes não podem mais ser usadas. Se houver devolução, lance-a no financeiro.');
    }

    /**
     * Recusar ou encerrar pela janela da lista: volta para ela. Só a lista é destino válido.
     */
    private function cameFromList(Request $request): bool
    {
        $validated = $request->validate(['voltar' => ['nullable', Rule::in(['lista'])]]);

        return ($validated['voltar'] ?? null) === 'lista';
    }

    /**
     * A lista de orçamentos como estava (filtros e página), se foi dela que o
     * usuário veio; senão, a lista limpa. Só o mesmo host e o mesmo caminho.
     */
    private function listUrl(Request $request): string
    {
        $previous = (string) session()->previousUrl();
        $list = route('plans.index');

        $sameHost = (parse_url($previous, PHP_URL_HOST) ?? $request->getHost()) === $request->getHost();
        $samePath = parse_url($previous, PHP_URL_PATH) === parse_url($list, PHP_URL_PATH);

        return $sameHost && $samePath ? $previous : $list;
    }
}
