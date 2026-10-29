<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\LocalizedDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Desconto máximo, em % do preço de tabela, que cada perfil pode conceder.
 * O administrador não tem limite. Lido uma vez por requisição ("scoped").
 */
class DiscountLimits
{
    /** Limite de um perfil sem linha na tabela: nenhum desconto. */
    public const DEFAULT_PERCENT = 0.0;

    /** @var array<string, float>|null */
    private ?array $limits = null;

    /**
     * @return float|null Nulo: sem limite.
     */
    public function for(UserRole $role): ?float
    {
        if ($role === UserRole::Admin) {
            return null;
        }

        return $this->limits()[$role->value] ?? self::DEFAULT_PERCENT;
    }

    /**
     * Mensagem de erro quando o desconto passa do limite de quem registra; nula se cabe.
     */
    public function violation(User $user, float $listTotal, float $chargedTotal): ?string
    {
        $limit = $this->for($user->role);

        if ($limit === null || $listTotal <= 0 || $chargedTotal >= $listTotal) {
            return null;
        }

        $percent = ($listTotal - $chargedTotal) / $listTotal * 100;

        // Meio centavo de tolerância para o arredondamento do valor digitado.
        if ($percent <= $limit + 0.005 || $listTotal - $chargedTotal < 0.01) {
            return null;
        }

        return 'Desconto de '.number_format($percent, 1, ',', '.').'% sobre o preço de tabela ('.LocalizedDecimal::money($listTotal).'). '
            .'O seu limite é '.number_format($limit, 1, ',', '.').'%: o mínimo é '.LocalizedDecimal::money(ceil($listTotal * (100 - $limit)) / 100).'. Um administrador pode conceder mais.';
    }

    /**
     * Substitui os limites dos perfis configuráveis e devolve os que mudaram.
     *
     * @param  array<string, float>  $limits  Perfil (valor do enum) => %.
     * @return array<string, array{from: float, to: float}>
     */
    public function sync(array $limits): array
    {
        $changes = [];

        foreach (UserRole::configurable() as $role) {
            if (! array_key_exists($role->value, $limits)) {
                continue;
            }

            $from = $this->for($role);
            $to = round($limits[$role->value], 2);

            if ($from !== $to) {
                DB::table('role_discount_limits')->updateOrInsert(['role' => $role->value], ['max_percent' => $to]);
                $changes[$role->value] = ['from' => $from, 'to' => $to];
            }
        }

        $this->limits = null;

        return $changes;
    }

    /**
     * @return array<string, float>
     */
    private function limits(): array
    {
        return $this->limits ??= DB::table('role_discount_limits')->pluck('max_percent', 'role')->map(fn ($value) => (float) $value)->all();
    }
}
