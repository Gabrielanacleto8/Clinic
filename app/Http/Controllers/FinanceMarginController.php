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
 * Relatório de margem por procedimento, no período do financeiro.
 */
class FinanceMarginController extends Controller
{
    public function __invoke(FinanceFilterRequest $request, ProcedureMargin $margin, AuditLogger $audit): View
    {
        [$start, $end] = $request->period();
        $professionalId = $request->validate(['profissional' => ['nullable', 'integer', Rule::exists(User::class, 'id')]])['profissional'] ?? null;
        $professionalId = $professionalId === null ? null : (int) $professionalId;

        $audit->log(AuditAction::FinanceMarginViewed, null, ['from' => $start, 'to' => $end, 'professional_id' => $professionalId]);

        $rows = $margin->between($start, $end, $professionalId);

        return view('finance.margin', [
            'start' => $start,
            'end' => $end,
            'rows' => $rows,
            'professionalId' => $professionalId,
            'professionals' => User::query()->whereIn('id', Treatment::query()->select('professional_id'))->orderBy('name')->pluck('name', 'id'),
        ]);
    }
}
