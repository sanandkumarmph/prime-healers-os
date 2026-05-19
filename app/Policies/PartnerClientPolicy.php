<?php

namespace App\Policies;

use App\Models\PartnerClient;
use App\Models\User;
use App\Policies\Concerns\HandlesModuleAuthorization;

class PartnerClientPolicy
{
    use HandlesModuleAuthorization;

    public function viewAny(User $user): bool
    {
        return $this->allowsModule($user, 'customers', 'read');
    }

    public function view(User $user, PartnerClient $partnerClient): bool
    {
        return $this->sameOrganization($user, $partnerClient)
            && $this->allowsModule($user, 'customers', 'read');
    }

    public function create(User $user): bool
    {
        return $this->allowsModule($user, 'customers', 'create');
    }

    public function update(User $user, PartnerClient $partnerClient): bool
    {
        return $this->sameOrganization($user, $partnerClient)
            && $this->allowsModule($user, 'customers', 'update');
    }

    public function delete(User $user, PartnerClient $partnerClient): bool
    {
        return $this->sameOrganization($user, $partnerClient)
            && $this->allowsModule($user, 'customers', 'delete');
    }
}
