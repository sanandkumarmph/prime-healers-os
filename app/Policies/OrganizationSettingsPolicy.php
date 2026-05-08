<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;
use App\Policies\Concerns\HandlesModuleAuthorization;

class OrganizationSettingsPolicy
{
    use HandlesModuleAuthorization;

    public function view(User $user, Organization $organization): bool
    {
        return $this->sameOrganization($user, $organization)
            && $this->allowsModule($user, 'settings', 'read');
    }

    public function update(User $user, Organization $organization): bool
    {
        return $this->sameOrganization($user, $organization)
            && $this->allowsModule($user, 'settings', 'update');
    }
}
