<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;
use App\Policies\Concerns\HandlesModuleAuthorization;

class CustomerPolicy
{
    use HandlesModuleAuthorization;

    public function viewAny(User $user): bool
    {
        return $this->allowsModule($user, 'customers', 'read');
    }

    public function view(User $user, Customer $customer): bool
    {
        return $this->sameOrganization($user, $customer)
            && $this->allowsModule($user, 'customers', 'read');
    }

    public function create(User $user): bool
    {
        return $this->allowsModule($user, 'customers', 'create');
    }

    public function update(User $user, Customer $customer): bool
    {
        return $this->sameOrganization($user, $customer)
            && $this->allowsModule($user, 'customers', 'update');
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $this->sameOrganization($user, $customer)
            && $this->allowsModule($user, 'customers', 'delete');
    }

    public function export(User $user): bool
    {
        return $user->hasPermission('customers.export');
    }

    public function downloadProof(User $user, Customer $customer): bool
    {
        return $this->sameOrganization($user, $customer)
            && $user->hasPermission('customers.proof.download');
    }
}
