<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\FinancialEntryType;
use App\Enums\Permission;
use App\Models\Appointment;
use App\Models\Product;
use App\Models\ProductBatch;
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
     * Totais do mês em uma única consulta, montada com as partes que o usuário pode ver.
     *
     * @return array<string, float|int> Chaves possíveis: patients, treatments, revenue, low_stock, expiring_batches.
     */
    public function totals(User $user): array
    {
        $month = CarbonImmutable::createFromFormat('!Y-m-d', ClinicTime::today(), ClinicTime::timezone())->startOfMonth();
        $selects = [];
        $bindings = [];

        if ($user->hasPermission(Permission::PatientsView)) {
            $selects[] = '(select count(*) from patients where deleted_at is null) as patients';
        }

        if ($user->hasPermission(Permission::RecordsView)) {
            $selects[] = '(select count(*) from treatments where deleted_at is null and voided_at is null and performed_at between ? and ?) as treatments';
            array_push($bindings, $month->utc(), $month->endOfMonth()->utc());
        }

        if ($user->hasPermission(Permission::FinanceView)) {
            $selects[] = '(select coalesce(sum(i.amount), 0) from financial_installments i join financial_entries e on e.id = i.financial_entry_id'
                .' where e.type = ? and e.issued_on between ? and ?) as revenue';
            array_push($bindings, FinancialEntryType::Income->value, $month->format('Y-m-d'), $month->endOfMonth()->format('Y-m-d'));
        }

        if ($user->hasPermission(Permission::StockView)) {
            $alertLimit = ClinicTime::local(now())->addDays((int) config('clinic.stock.expiry_alert_days'))->format('Y-m-d');

            // Mesma regra de estoque baixo da aba do estoque (Product::lowStock()).
            $lowStock = Product::query()->lowStock()->selectRaw('count(*)')->toBase();
            $selects[] = '('.$lowStock->toSql().') as low_stock';
            array_push($bindings, ...$lowStock->getBindings());
            // Lotes com saldo já vencidos ou que vencem dentro do prazo de alerta.
            $selects[] = '(select count(*) from product_batches where quantity > 0 and expires_on <= ?) as expiring_batches';
            $bindings[] = $alertLimit;
        }

        if ($selects === []) {
            return [];
        }

        return array_map(fn ($value) => $value + 0, (array) DB::selectOne('select '.implode(', ', $selects), $bindings));
    }

    /**
     * Agendamentos de hoje, com tudo o que a lista de ações precisa.
     *
     * @return Collection<int, Appointment>
     */
    public function todayAppointments(): Collection
    {
        [$start, $end] = ClinicTime::dayBounds(ClinicTime::today());

        return Appointment::query()
            ->with(['patient:id,name', 'professional:id,name', 'procedure:id,name', 'treatment:id,patient_id'])
            ->whereHas('patient')
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
            ->with(['patient:id,name', 'professional:id,name', 'procedure:id,name'])
            ->whereHas('patient')
            ->whereIn('status', AppointmentStatus::open())
            ->where('scheduled_at', '>', ClinicTime::dayBounds(ClinicTime::today())[1])
            ->orderBy('scheduled_at')
            ->limit(self::LIST_LIMIT)
            ->get();
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
}
