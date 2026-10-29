<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\FinancialEntry;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\Product;
use App\Models\Treatment;
use App\Models\TreatmentPlan;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Módulos do sistema, agrupados como no menu lateral. A pesquisa do topo
 * também os oferece como atalho. Para incluir um módulo, acrescente um item
 * aqui: ele só aparece para quem tem a permissão indicada em "can".
 */
final class Navigation
{
    /**
     * @return array<string, list<array{label: string, route: string, active: string|list<string>, icon: string, can: list<mixed>|null}>>
     */
    private static function all(): array
    {
        return [
            'Módulos' => [
                ['label' => 'Início', 'route' => 'home', 'active' => 'home', 'icon' => 'home', 'can' => null],
                ['label' => 'Agenda', 'route' => 'agenda.index', 'active' => ['agenda.*', 'appointments.*'], 'icon' => 'calendar', 'can' => ['viewAny', Appointment::class]],
                ['label' => 'Atendimentos', 'route' => 'treatments.index', 'active' => 'treatments.index', 'icon' => 'activity', 'can' => ['viewAny', Treatment::class]],
                ['label' => 'Pacientes', 'route' => 'patients.index', 'active' => 'patients.*', 'icon' => 'patients', 'can' => ['viewAny', Patient::class]],
                ['label' => 'Orçamentos', 'route' => 'plans.index', 'active' => ['plans.*', 'patients.plans.*'], 'icon' => 'file-text', 'can' => ['viewAny', TreatmentPlan::class]],
                ['label' => 'Procedimentos', 'route' => 'procedures.index', 'active' => 'procedures.*', 'icon' => 'clipboard', 'can' => ['viewAny', Procedure::class]],
                ['label' => 'Estoque', 'route' => 'products.index', 'active' => ['products.*', 'stock.*'], 'icon' => 'box', 'can' => ['viewAny', Product::class]],
                ['label' => 'Financeiro', 'route' => 'finance.index', 'active' => 'finance.*', 'icon' => 'dollar', 'can' => ['viewAny', FinancialEntry::class]],
                // Quem só fecha o caixa (recepção) não vê o financeiro: o item leva direto ao caixa do dia.
                ['label' => 'Caixa do dia', 'route' => 'finance.cash.show', 'active' => 'finance.cash.*', 'icon' => 'dollar', 'can' => ['cashOnly', FinancialEntry::class]],
            ],
            'Administração' => [
                // As permissões ficam numa aba dentro de usuários.
                ['label' => 'Usuários', 'route' => 'users.index', 'active' => 'users.*', 'icon' => 'shield', 'can' => ['viewAny', User::class]],
                ['label' => 'Auditoria', 'route' => 'audit.index', 'active' => 'audit.*', 'icon' => 'eye', 'can' => ['viewAny', AuditLog::class]],
                ['label' => 'Clínica', 'route' => 'clinic.edit', 'active' => 'clinic.*', 'icon' => 'sliders', 'can' => ['manage-clinic']],
            ],
        ];
    }

    /**
     * Os grupos que o usuário pode ver, sem grupos vazios. Com senha
     * provisória nenhum módulo é acessível, então nenhum é listado.
     *
     * @return array<string, list<array{label: string, route: string, active: string|list<string>, icon: string, can: list<mixed>|null}>>
     */
    public static function for(User $user): array
    {
        if ($user->mustChangePassword()) {
            return [];
        }

        return array_filter(array_map(
            // "can" nulo: item visível a qualquer usuário autenticado (o Início).
            fn (array $items) => array_values(array_filter($items, fn (array $item) => $item['can'] === null || $user->can(...$item['can']))),
            self::all(),
        ));
    }

    /**
     * Módulos cujo nome contém o termo, sem diferença de acento ou maiúscula.
     *
     * @return list<array{label: string, route: string, active: string|list<string>, icon: string, can: list<mixed>|null}>
     */
    public static function matching(User $user, string $term): array
    {
        $needle = mb_strtolower(Str::ascii($term));

        return array_values(array_filter(
            array_merge(...array_values(self::for($user))),
            fn (array $item) => str_contains(mb_strtolower(Str::ascii($item['label'])), $needle),
        ));
    }
}
