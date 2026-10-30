<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\Permission;
use App\Models\Appointment;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockUnit;
use App\Models\Treatment;
use App\Models\User;
use App\Support\ClinicTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Números e listas do Início. Cada usuário só recebe, e o banco só é
 * consultado, pelo que o seu perfil pode ver.
 */
class DashboardMetrics
{
    private const LIST_LIMIT = 5;

    /**
     * Contagens do dia em uma única consulta, montada com as partes que o
     * usuário pode ver. O Início é a visão operacional: nenhum valor em dinheiro.
     *
     * @return array<string, int> Chaves possíveis: treatments, low_stock, expiring_batches, units_to_discard.
     */
    public function totals(User $user, ?int $professionalId = null): array
    {
        $selects = [];
        $bindings = [];

        if ($user->hasPermission(Permission::RecordsView)) {
            // Atendimentos registrados hoje, sem os anulados; com o mesmo filtro de profissional da agenda do dia.
            [$start, $end] = ClinicTime::dayBounds(ClinicTime::today());
            $selects[] = '(select count(*) from treatments where deleted_at is null and voided_at is null and performed_at between ? and ?'
                .($professionalId ? ' and professional_id = ?' : '').') as treatments';
            array_push($bindings, $start, $end, ...($professionalId ? [$professionalId] : []));
        }

        if ($user->hasPermission(Permission::StockView)) {
            // Mesmas regras dos alertas do módulo de estoque (StockAlerts).
            $lowStock = Product::query()->lowStock()->selectRaw('count(*)')->toBase();
            $selects[] = '('.$lowStock->toSql().') as low_stock';
            array_push($bindings, ...$lowStock->getBindings());
            $expiring = ProductBatch::query()->where(fn ($query) => $query->expiringSoon()->orWhere(fn ($expired) => $expired->expiredWithStock()))
                ->selectRaw('count(*)')->toBase();
            $selects[] = '('.$expiring->toSql().') as expiring_batches';
            array_push($bindings, ...$expiring->getBindings());
            $toDiscard = StockUnit::query()->awaitingDiscard()->selectRaw('count(*)')->toBase();
            $selects[] = '('.$toDiscard->toSql().') as units_to_discard';
            array_push($bindings, ...$toDiscard->getBindings());
        }

        if ($selects === []) {
            return [];
        }

        return array_map(intval(...), (array) DB::selectOne('select '.implode(', ', $selects), $bindings));
    }

    /**
     * Agendamentos de hoje, com tudo o que a lista de ações precisa.
     *
     * @return Collection<int, Appointment>
     */
    public function todayAppointments(?int $professionalId = null): Collection
    {
        [$start, $end] = ClinicTime::dayBounds(ClinicTime::today());

        return Appointment::query()
            ->with(['patient:id,name,phone', 'professional:id,name,is_active', 'procedure:id,name', 'treatment:id,patient_id'])
            ->whereHas('patient')
            ->when($professionalId, fn ($query, int $id) => $query->where('professional_id', $id))
            ->whereBetween('scheduled_at', [$start, $end])
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Próximos agendamentos em aberto, a partir de amanhã.
     *
     * @return Collection<int, Appointment>
     */
    public function upcomingAppointments(): Collection
    {
        return Appointment::query()
            ->with(['patient:id,name,phone', 'professional:id,name', 'procedure:id,name'])
            ->whereHas('patient')
            ->whereIn('status', AppointmentStatus::open())
            ->where('scheduled_at', '>', ClinicTime::dayBounds(ClinicTime::today())[1])
            ->orderBy('scheduled_at')
            ->limit(self::LIST_LIMIT)
            ->get();
    }

    /**
     * Agendamentos de dias anteriores ainda em aberto: o atendimento não foi
     * registrado (ou a falta não foi marcada), e o estoque e a cobrança
     * ficaram sem lançar. Os últimos 30 dias, do mais antigo ao mais recente.
     *
     * @return array{list: Collection<int, Appointment>, total: int}
     */
    public function pendingRegistrations(?int $professionalId = null): array
    {
        [$todayStart] = ClinicTime::dayBounds(ClinicTime::today());

        $query = Appointment::query()
            ->whereHas('patient')
            ->openForTreatment()
            ->where('scheduled_at', '<', $todayStart)
            ->where('scheduled_at', '>=', $todayStart->subDays(30))
            ->when($professionalId, fn ($query, int $id) => $query->where('professional_id', $id));

        return [
            'list' => (clone $query)
                ->with(['patient:id,name,phone', 'professional:id,name,is_active', 'procedure:id,name', 'treatment:id,patient_id'])
                ->orderBy('scheduled_at')
                ->limit(self::LIST_LIMIT)
                ->get(),
            'total' => $query->count(),
        ];
    }

    /**
     * Retornos previstos em atendimentos que ainda não viraram agendamento:
     * o paciente não tem agendamento depois do atendimento. Dos últimos 60
     * dias até os próximos 60, do mais próximo de hoje ao mais distante.
     *
     * @return array{list: Collection<int, Treatment>, total: int}
     */
    public function returnsToSchedule(): array
    {
        $today = CarbonImmutable::parse(ClinicTime::today());

        $query = Treatment::query()
            ->notVoided()
            ->whereHas('patient')
            ->whereBetween('return_date', [$today->subDays(60)->format('Y-m-d'), $today->addDays(60)->format('Y-m-d')])
            ->whereNotExists(fn ($exists) => $exists->selectRaw('1')->from('appointments')
                ->whereColumn('appointments.patient_id', 'treatments.patient_id')
                ->whereColumn('appointments.scheduled_at', '>', 'treatments.performed_at')
                ->where('appointments.status', '!=', AppointmentStatus::Cancelled->value));

        return [
            'list' => (clone $query)
                ->with(['patient:id,name,phone', 'procedure:id,name'])
                ->orderByRaw('abs(datediff(return_date, ?))', [$today->format('Y-m-d')])
                ->limit(self::LIST_LIMIT)
                ->get(['id', 'patient_id', 'procedure_id', 'performed_at', 'return_date']),
            'total' => $query->count(),
        ];
    }

    /**
     * @return Collection<int, Product>
     */
    public function lowStockProducts(): Collection
    {
        return Product::query()->lowStock()->withAvailableQuantity()->orderBy('name')->limit(self::LIST_LIMIT)->get();
    }

    /**
     * Lotes com saldo já vencidos ou perto de vencer, do mais urgente ao menos.
     *
     * @return Collection<int, ProductBatch>
     */
    public function expiringBatches(): Collection
    {
        return ProductBatch::query()
            ->with('product:id,name,unit')
            ->where(fn ($query) => $query->where(fn ($soon) => $soon->expiringSoon())->orWhere(fn ($expired) => $expired->expiredWithStock()))
            ->orderBy('expires_on')
            ->limit(self::LIST_LIMIT)
            ->get();
    }

    /**
     * Unidades abertas que não podem mais ir para paciente, das abertas há mais tempo.
     *
     * @return Collection<int, StockUnit>
     */
    public function unitsToDiscard(): Collection
    {
        return StockUnit::query()
            ->awaitingDiscard()
            ->withConsumption()
            ->with(['batch:id,product_id,batch_code,expires_on', 'batch.product:id,name,hours_after_opening,multi_patient_use,is_active'])
            ->orderBy('opened_at')
            ->orderBy('id')
            ->limit(self::LIST_LIMIT)
            ->get();
    }
}
