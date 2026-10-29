<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\FinancialEntryType;
use App\Enums\PaymentMethod;
use App\Models\CashClosing;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Caixa do dia: o que entrou e saiu em cada forma de pagamento, o fechamento
 * com a conferência e a trava que impede mexer em pagamento de dia fechado.
 *
 * A trava e o fechamento se coordenam pela linha do dia em cash_closings: o
 * pagamento lê essa linha com trava compartilhada; o fechamento a insere
 * antes de somar o esperado. Um pagamento em andamento faz o fechamento
 * esperar e entra na soma; um pagamento depois do fechamento é recusado.
 */
class CashRegister
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Recusa a alteração de pagamento em dia fechado. Chamado dentro da
     * transação que grava o pagamento.
     *
     * @throws ValidationException
     */
    public function assertOpen(string $day, string $field = 'paid_at'): void
    {
        $closed = CashClosing::query()->where('day', $day)->sharedLock()->exists();

        if ($closed) {
            throw ValidationException::withMessages([
                $field => 'O caixa de '.CarbonImmutable::createFromFormat('!Y-m-d', $day)->format('d/m/Y').' já foi fechado. Use outra data ou peça ao administrador para reabrir o dia.',
            ]);
        }
    }

    /**
     * Entradas e saídas pagas no dia, por forma de pagamento. Receitas
     * entram; despesas, estornos e repasses saem.
     *
     * @return array<string, array{in: float, out: float}> Forma (valor do enum) => totais, só as que tiveram movimento.
     */
    public function movement(string $day): array
    {
        $rows = DB::table('financial_installments as i')
            ->join('financial_entries as e', 'e.id', '=', 'i.financial_entry_id')
            ->where('i.paid_at', $day)
            ->groupBy('e.payment_method')
            ->selectRaw('e.payment_method')
            ->selectRaw('coalesce(sum(case when e.type = ? then i.amount end), 0) as total_in', [FinancialEntryType::Income->value])
            ->selectRaw('coalesce(sum(case when e.type = ? then i.amount end), 0) as total_out', [FinancialEntryType::Expense->value])
            ->get();

        $movement = [];

        // Na ordem do cadastro das formas de pagamento, para a tela não mudar de um dia para outro.
        foreach (PaymentMethod::cases() as $method) {
            $row = $rows->firstWhere('payment_method', $method->value);

            if ($row !== null) {
                $movement[$method->value] = ['in' => (float) $row->total_in, 'out' => (float) $row->total_out];
            }
        }

        return $movement;
    }

    /**
     * Formas que a conferência pede: as que tiveram movimento e sempre o dinheiro.
     *
     * @param  array<string, array{in: float, out: float}>  $movement
     * @return list<PaymentMethod>
     */
    public function methodsToCount(array $movement): array
    {
        return array_values(array_filter(
            PaymentMethod::cases(),
            fn (PaymentMethod $method) => $method === PaymentMethod::Cash || isset($movement[$method->value]),
        ));
    }

    /**
     * Fecha o dia com o conferido em cada forma. O esperado é recalculado
     * aqui, dentro da trava, e gravado ao lado do conferido.
     *
     * @param  array<string, string>  $counted  Forma (valor do enum) => valor conferido.
     *
     * @throws ValidationException
     */
    public function close(string $day, array $counted, ?string $notes, User $user): CashClosing
    {
        return DB::transaction(function () use ($day, $counted, $notes, $user): CashClosing {
            $closing = new CashClosing(['day' => $day, 'notes' => $notes]);
            $closing->closed_by = $user->getKey();

            try {
                $closing->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['day' => 'Este dia já foi fechado por outra pessoa. Recarregue a página.']);
            }

            $movement = $this->movement($day);
            $lines = [];

            foreach ($this->methodsToCount($movement) as $method) {
                if (! array_key_exists($method->value, $counted)) {
                    throw ValidationException::withMessages(["counted.{$method->value}" => 'Houve movimento em '.$method->label().' depois que a tela foi aberta. Recarregue a página e confira de novo.']);
                }

                $expected = ($movement[$method->value]['in'] ?? 0) - ($movement[$method->value]['out'] ?? 0);
                $lines[] = [
                    'payment_method' => $method->value,
                    'expected' => number_format($expected, 2, '.', ''),
                    'counted' => number_format((float) $counted[$method->value], 2, '.', ''),
                ];
            }

            $closing->lines()->createMany($lines);

            $this->audit->log(AuditAction::CashClosed, $closing, [
                'day' => $day,
                'lines' => array_map(fn (array $line) => $line['payment_method'].': '.$line['expected'].' / '.$line['counted'], $lines),
            ]);

            return $closing;
        });
    }

    /**
     * Desfaz o fechamento: o dia volta a aceitar pagamentos. O que tinha sido
     * conferido fica na auditoria.
     */
    public function reopen(CashClosing $closing): void
    {
        DB::transaction(function () use ($closing): void {
            $closing->load('lines');

            $this->audit->log(AuditAction::CashReopened, $closing, [
                'day' => $closing->day->format('Y-m-d'),
                'lines' => $closing->lines->map(fn ($line) => $line->payment_method->value.': '.$line->expected.' / '.$line->counted)->all(),
                'difference' => number_format($closing->difference(), 2, '.', ''),
            ]);

            $closing->delete();
        });
    }
}
