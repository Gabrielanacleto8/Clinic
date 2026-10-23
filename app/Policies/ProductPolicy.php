<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::StockView);
    }

    public function view(User $user, Product $product): bool
    {
        return $user->hasPermission(Permission::StockView);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::StockManage);
    }

    public function update(User $user, Product $product): bool
    {
        return $user->hasPermission(Permission::StockManage);
    }

    /**
     * Lançar entrada, saída ou perda de estoque.
     */
    public function move(User $user, ?Product $product = null): bool
    {
        return $user->hasPermission(Permission::StockManage);
    }

    /**
     * Descartar o que resta em uma unidade aberta ou baixar lote vencido como
     * perda. É tarefa de quem usa o produto, não de quem compra.
     */
    public function discard(User $user, ?Product $product = null): bool
    {
        return $user->hasPermission(Permission::StockDiscard) || $user->hasPermission(Permission::StockManage);
    }

    /**
     * Inventário e ajuste de saldo mexem no estoque sem documento de origem:
     * exigem autorização própria, além da de movimentar.
     */
    public function adjust(User $user, ?Product $product = null): bool
    {
        return $user->hasPermission(Permission::StockAdjust);
    }
}
