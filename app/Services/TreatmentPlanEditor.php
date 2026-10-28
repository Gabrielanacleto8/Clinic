<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\TreatmentPlanStatus;
use App\Models\Patient;
use App\Models\TreatmentPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cria e altera orçamentos em aberto: os dados e a lista de itens, juntos.
 */
class TreatmentPlanEditor
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{valid_until: ?string, notes: ?string}  $data
     * @param  list<array{procedure_id: int, sessions: int, unit_price: string}>  $items
     */
    public function create(Patient $patient, array $data, array $items, User $user): TreatmentPlan
    {
        return DB::transaction(function () use ($patient, $data, $items, $user): TreatmentPlan {
            $plan = new TreatmentPlan($data);
            $plan->patient_id = $patient->getKey();
            $plan->status = TreatmentPlanStatus::Open;
            $plan->created_by = $user->getKey();
            $plan->save();

            $plan->items()->createMany($items);

            $this->audit->log(AuditAction::TreatmentPlanCreated, $plan, ['patient_id' => $patient->getKey(), 'items' => count($items)]);

            return $plan;
        });
    }

    /**
     * @param  array{valid_until: ?string, notes: ?string}  $data
     * @param  list<array{procedure_id: int, sessions: int, unit_price: string}>  $items
     *
     * @throws ValidationException
     */
    public function update(TreatmentPlan $plan, array $data, array $items): void
    {
        DB::transaction(function () use ($plan, $data, $items): void {
            // Trava: uma aprovação simultânea não pode valer para itens que estão mudando.
            $locked = TreatmentPlan::query()->lockForUpdate()->findOrFail($plan->getKey());

            if (! $locked->isOpen()) {
                throw ValidationException::withMessages(['items' => 'Este orçamento não está mais em aberto e não pode ser alterado.']);
            }

            $locked->fill($data)->save();
            $locked->items()->delete();
            $locked->items()->createMany($items);

            $this->audit->log(AuditAction::TreatmentPlanUpdated, $locked, ['patient_id' => $locked->patient_id, 'items' => count($items)]);
        });
    }
}
