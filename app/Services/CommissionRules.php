<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\CommissionRate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Regras de comissão: o % padrão de cada profissional e as exceções por
 * procedimento. O atendimento grava o % que valia quando foi registrado.
 */
class CommissionRules
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * % do profissional para o procedimento: a exceção, senão o padrão, senão nenhum.
     */
    public function rateFor(int $professionalId, int $procedureId): ?string
    {
        $rates = CommissionRate::query()
            ->where('user_id', $professionalId)
            ->where(fn ($query) => $query->whereNull('procedure_id')->orWhere('procedure_id', $procedureId))
            ->pluck('rate', 'procedure_key');

        $rate = $rates[$procedureId] ?? $rates[0] ?? null;

        return $rate === null ? null : (string) $rate;
    }

    /**
     * @return Collection<int, Collection<int, CommissionRate>> Profissional => regras.
     */
    public function all(): Collection
    {
        return CommissionRate::query()->with('procedure:id,name')->orderBy('procedure_key')->get()->groupBy('user_id');
    }

    /**
     * Substitui todas as regras. Padrão zero apaga a regra; exceção zero tira o procedimento da comissão.
     *
     * @param  list<array{user_id: int, procedure_id: int|null, rate: float}>  $rules
     */
    public function sync(array $rules): int
    {
        return DB::transaction(function () use ($rules): int {
            $current = CommissionRate::query()->lockForUpdate()->get()
                ->mapWithKeys(fn (CommissionRate $rate) => [$rate->user_id.'-'.($rate->procedure_id ?? 0) => (float) $rate->rate]);

            $wanted = collect($rules)
                // Padrão zero é o mesmo que nenhum; exceção zero vale (o procedimento não comissiona).
                ->filter(fn (array $rule) => $rule['rate'] > 0 || $rule['procedure_id'] !== null)
                ->mapWithKeys(fn (array $rule) => [$rule['user_id'].'-'.($rule['procedure_id'] ?? 0) => round($rule['rate'], 2)]);

            if ($current->sortKeys()->all() == $wanted->sortKeys()->all()) {
                return 0;
            }

            CommissionRate::query()->delete();

            foreach ($wanted as $key => $rate) {
                [$userId, $procedureId] = array_map('intval', explode('-', $key));
                $row = new CommissionRate(['procedure_id' => $procedureId === 0 ? null : $procedureId, 'rate' => $rate]);
                $row->user_id = $userId;
                $row->save();
            }

            // Só os pares profissional-procedimento e as taxas: nada de dado pessoal.
            $this->audit->log(AuditAction::CommissionRatesUpdated, null, [
                'rates' => $wanted->map(fn (float $rate, string $key) => $key.': '.$rate.'%')->values()->all(),
            ]);

            return $wanted->count();
        });
    }
}
