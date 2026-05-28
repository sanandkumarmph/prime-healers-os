<?php

namespace App\Policies;

use App\Models\StockMovement;
use App\Models\User;

class StockMovementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission([
            'stock_history.view',
            'stock_history.product',
            'stock_history.asset',
        ]);
    }

    public function view(User $user, StockMovement $stockMovement): bool
    {
        if ((int) $user->organization_id !== (int) $stockMovement->organization_id) {
            return false;
        }

        if ($user->hasPermission('stock_history.view')) {
            return true;
        }

        if ($stockMovement->product_id && $user->hasPermission('stock_history.product')) {
            return true;
        }

        return (bool) ($stockMovement->asset_id && $user->hasPermission('stock_history.asset'));
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, StockMovement $stockMovement): bool
    {
        return false;
    }

    public function delete(User $user, StockMovement $stockMovement): bool
    {
        return false;
    }
}
