<?php

namespace App\Policies;

use App\Models\BusinessPartner;
use App\Models\User;
use App\Policies\Concerns\HandlesModuleAuthorization;

class BusinessPartnerPolicy
{
    use HandlesModuleAuthorization;

    public function viewAny(User $user): bool
    {
        return $this->allowsModule($user, 'customers', 'read');
    }

    public function view(User $user, BusinessPartner $businessPartner): bool
    {
        return $this->sameOrganization($user, $businessPartner)
            && $this->allowsModule($user, 'customers', 'read');
    }

    public function create(User $user): bool
    {
        return $this->allowsModule($user, 'customers', 'create');
    }

    public function update(User $user, BusinessPartner $businessPartner): bool
    {
        return $this->sameOrganization($user, $businessPartner)
            && $this->allowsModule($user, 'customers', 'update');
    }

    public function delete(User $user, BusinessPartner $businessPartner): bool
    {
        return $this->sameOrganization($user, $businessPartner)
            && $this->allowsModule($user, 'customers', 'delete');
    }
}
