<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\FinancialCategory;
use App\Enums\PaymentMethod;
use App\Models\CommissionPayout;
use App\Models\User;
use App\Support\ClinicTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Repasse de comissão: paga ao profissional o que está a repassar nos
 * atendimentos até uma data e lança a despesa no financeiro.
 */
class CommissionPayouts
{
    public function __construct(
        private readonly CommissionLedger $ledger,
        private readonly FinancialRecorder $finance,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function pay(User $professional, string $until, PaymentMethod $method, bool $paidNow, User $user): CommissionPayout
    {
        return DB::transaction(function () use ($professional, $until, $method, $paidNow, $user): CommissionPayout {
            // A trava no profissional impede dois repasses simultâneos dos mesmos atendimentos.
            User::query()->whereKey($professional->getKey())->lockForUpdate()->first(['id']);

            $rows = $this->ledger->rows($until, $professional->getKey())->filter(fn (object $row) => $row->pending != 0.0);
            $total = round($rows->sum('pending'), 2);

            if ($total <= 0) {
                throw ValidationException::withMessages(['until' => 'Não há comissão a repassar até esta data.']);
            }

            $payout = new CommissionPayout(['until' => $until]);
            $payout->professional_id = $professional->getKey();
            $payout->created_by = $user->getKey();
            $payout->save();

            $payout->items()->createMany($rows->map(fn (object $row) => [
                'treatment_id' => $row->treatment_id,
                'base' => number_format($row->base, 2, '.', ''),
                'rate' => number_format($row->rate, 2, '.', ''),
                'amount' => number_format($row->pending, 2, '.', ''),
            ])->values()->all());

            $today = ClinicTime::today();

            $this->finance->record(
                category: FinancialCategory::Commissions,
                description: 'Repasse de comissão: '.$professional->name.' (até '.CarbonImmutable::createFromFormat('!Y-m-d', $until)->format('d/m/Y').')',
                total: number_format($total, 2, '.', ''),
                paymentMethod: $method,
                installments: 1,
                firstDueDate: $today,
                firstInstallmentPaid: $paidNow,
                createdBy: $user,
                issuedOn: $today,
                links: ['commission_payout_id' => $payout->getKey()],
            );

            $this->audit->log(AuditAction::CommissionPaid, $payout, [
                'professional_id' => $professional->getKey(),
                'until' => $until,
                'amount' => number_format($total, 2, '.', ''),
                'treatments' => $rows->count(),
            ]);

            return $payout;
        });
    }

    /**
     * Exclui um repasse lançado por engano, enquanto a despesa dele não foi paga.
     *
     * @throws ValidationException
     */
    public function delete(CommissionPayout $payout): void
    {
        DB::transaction(function () use ($payout): void {
            $locked = CommissionPayout::query()->lockForUpdate()->with('entry')->findOrFail($payout->getKey());

            if ($locked->entry?->hasPaidInstallment()) {
                throw ValidationException::withMessages(['payout' => 'Este repasse já foi pago. Desfaça o pagamento no financeiro antes de excluir.']);
            }

            $this->audit->log(AuditAction::CommissionPayoutDeleted, $locked, [
                'professional_id' => $locked->professional_id,
                'until' => $locked->until->format('Y-m-d'),
                'amount' => $locked->entry?->total(),
            ]);

            $locked->entry?->delete();
            $locked->delete();
        });
    }
}
