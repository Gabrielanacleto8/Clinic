<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Http\Requests\FinanceFilterRequest;
use App\Models\FinancialInstallment;
use App\Services\AuditLogger;
use App\Services\FinanceListing;
use App\Support\CsvRow;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Planilha das parcelas filtradas, para o contador. Sai sem dado de paciente:
 * a contabilidade precisa do valor, da data e da categoria, não de quem foi
 * atendido. Lançamento ligado a paciente sai sem a descrição, que é texto
 * livre e pode citar o nome ou o procedimento.
 */
class FinanceExportController extends Controller
{
    private const HEADER = ['Vencimento', 'Pagamento', 'Tipo', 'Categoria', 'Descrição', 'Forma de pagamento', 'Parcela', 'Valor', 'Situação'];

    public function __invoke(FinanceFilterRequest $request, FinanceListing $listing, AuditLogger $audit): StreamedResponse
    {
        [$start, $end] = $request->period();
        $query = $listing->query($start, $end, $request->type(), $request->status(), $request->patientTerm())
            ->with(['entry' => fn ($entry) => $entry->select(['id', 'type', 'category', 'description', 'payment_method', 'patient_id'])->withCount('installments')]);

        $audit->log(AuditAction::FinanceExported, null, [
            'from' => $start,
            'to' => $end,
            'type' => $request->type(),
            'status' => $request->status(),
            'patient_filter' => $request->patientTerm() !== '',
            'rows' => (clone $query)->count(),
        ]);

        return response()->streamDownload(function () use ($query): void {
            // BOM: sem ele o Excel lê os acentos errado.
            echo "\u{FEFF}".CsvRow::make(self::HEADER);

            $query->chunk(500, function ($installments): void {
                foreach ($installments as $installment) {
                    echo CsvRow::make($this->row($installment));
                }
            });
        }, "financeiro-{$start}-a-{$end}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return list<string>
     */
    private function row(FinancialInstallment $installment): array
    {
        $entry = $installment->entry;

        return [
            $installment->due_date->format('d/m/Y'),
            $installment->paid_at?->format('d/m/Y') ?? '',
            $entry->type->label(),
            $entry->category->label(),
            $entry->patient_id === null ? $entry->description : 'Receita de paciente',
            $entry->payment_method->label(),
            $installment->number.'/'.$entry->installments_count,
            number_format((float) $installment->amount, 2, ',', ''),
            match (true) {
                $installment->isPaid() => 'Pago',
                $installment->isOverdue() => 'Vencido',
                default => 'A vencer',
            },
        ];
    }
}
