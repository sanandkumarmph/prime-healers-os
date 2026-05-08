<?php

namespace App\Policies;

use App\Models\Sale;
use App\Models\User;
use App\Policies\Concerns\HandlesModuleAuthorization;

class SalePolicy
{
    use HandlesModuleAuthorization;

    public function viewAny(User $user): bool
    {
        return $this->allowsModule($user, 'sales', 'read');
    }

    public function view(User $user, Sale $sale): bool
    {
        return $this->sameOrganization($user, $sale)
            && $this->allowsModule($user, 'sales', 'read');
    }

    public function create(User $user): bool
    {
        return $this->allowsModule($user, 'sales', 'create');
    }

    public function update(User $user, Sale $sale): bool
    {
        return $this->sameOrganization($user, $sale)
            && $this->allowsModule($user, 'sales', 'update');
    }

    public function delete(User $user, Sale $sale): bool
    {
        return $this->sameOrganization($user, $sale)
            && $this->allowsModule($user, 'sales', 'delete');
    }
}
