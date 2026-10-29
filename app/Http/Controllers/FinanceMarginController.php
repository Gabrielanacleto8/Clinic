<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Http\Requests\FinanceFilterRequest;
use App\Models\Treatment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ProcedureMargin;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Aba Receitas e margem: por procedimento ou por profissional, no período do financeiro.
 */
class FinanceMarginController extends Controller
{
    public function __invoke(FinanceFilterRequest $request, ProcedureMargin $margin, AuditLogger $audit): View
    {
        [$start, $end] = $request->period();
        $extra = $request->validate([
            'profissional' => ['nullable', 'integer', Rule::exists(User::class, 'id')],
            'agrupar' => ['nullable', Rule::in(array_keys(ProcedureMargin::GROUPS))],
        ]);
        $professionalId = isset($extra['profissional']) ? (int) $extra['profissional'] : null;
        $group = $extra['agrupar'] ?? 'procedimento';

        $audit->log(AuditAction::FinanceMarginViewed, null, ['from' => $start, 'to' => $end, 'professional_id' => $professionalId, 'view' => $group]);

        return view('finance.margin', [
            'start' => $start,
            'end' => $end,
            'rows' => $margin->between($start, $end, $professionalId, $group),
            'group' => $group,
            'groups' => ProcedureMargin::GROUPS,
            'professionalId' => $professionalId,
            'professionals' => User::query()->whereIn('id', Treatment::query()->select('professional_id'))->orderBy('name')->pluck('name', 'id'),
        ]);
    }
}
