<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Enums\FinancialEntryType;
use App\Models\FinancialEntry;
use App\Models\Patient;
use App\Services\AuditLogger;
use App\Support\ClinicTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Aba Inadimplência: pacientes com cobrança vencida e não paga, pelo tempo de atraso.
 */
class FinanceDelinquencyController extends Controller
{
    /** Faixas de atraso em dias: rótulo => [de, até]. */
    public const AGING = ['1 a 30 dias' => [1, 30], '31 a 60 dias' => [31, 60], '61 a 90 dias' => [61, 90], 'Mais de 90 dias' => [91, null]];

    public function __invoke(Request $request, AuditLogger $audit): View
    {
        Gate::authorize('viewAny', FinancialEntry::class);

        $audit->log(AuditAction::FinanceViewed, null, ['view' => 'delinquency']);

        $today = ClinicTime::today();
        $overdue = DB::table('financial_installments as i')
            ->join('financial_entries as e', 'e.id', '=', 'i.financial_entry_id')
            ->where('e.type', FinancialEntryType::Income->value)
            ->whereNull('i.paid_at')
            ->where('i.due_date', '<', $today);

        $aging = (clone $overdue)->selectRaw('datediff(?, i.due_date) as days, i.amount, e.patient_id', [$today]);

        $rows = DB::query()->fromSub($aging, 'late')
            ->whereNotNull('patient_id')
            ->groupBy('patient_id')
            ->selectRaw('patient_id, count(*) as installments, sum(amount) as total, max(days) as oldest');

        foreach (array_values(self::AGING) as $index => [$from, $until]) {
            $rows->selectRaw(
                'coalesce(sum(case when days >= ?'.($until === null ? '' : ' and days <= ?')." then amount end), 0) as aging_{$index}",
                $until === null ? [$from] : [$from, $until],
            );
        }

        $patients = $rows->orderByDesc('total')->paginate(25)->withQueryString();
        $names = Patient::withTrashed()->whereKey($patients->pluck('patient_id'))->pluck('name', 'id');

        $summary = DB::query()->fromSub($aging, 'late')->selectRaw('count(distinct patient_id) as patients, coalesce(sum(amount), 0) as total, coalesce(sum(case when patient_id is null then amount end), 0) as without_patient');

        foreach (array_values(self::AGING) as $index => [$from, $until]) {
            $summary->selectRaw(
                'coalesce(sum(case when days >= ?'.($until === null ? '' : ' and days <= ?')." then amount end), 0) as aging_{$index}",
                $until === null ? [$from] : [$from, $until],
            );
        }

        return view('finance.delinquency', [
            'patients' => $patients,
            'names' => $names,
            'summary' => $summary->first(),
            'aging' => array_keys(self::AGING),
        ]);
    }
}
