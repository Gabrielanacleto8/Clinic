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
     * Lançar entrada, saída ou ajuste de estoque.
     */
    public function move(User $user, Product $product): bool
    {
        return $user->hasPermission(Permission::StockManage);
    }
}
