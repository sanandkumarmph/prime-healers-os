<?php

namespace App\Policies;

use App\Models\Rental;
use App\Models\User;
use App\Policies\Concerns\HandlesModuleAuthorization;

class RentalPolicy
{
    use HandlesModuleAuthorization;

    public function viewAny(User $user): bool
    {
        return $this->allowsModule($user, 'rentals', 'read');
    }

    public function view(User $user, Rental $rental): bool
    {
        return $this->sameOrganization($user, $rental)
            && $this->allowsModule($user, 'rentals', 'read');
    }

    public function create(User $user): bool
    {
        return $this->allowsModule($user, 'rentals', 'create');
    }

    public function update(User $user, Rental $rental): bool
    {
        return $this->sameOrganization($user, $rental)
            && $this->allowsModule($user, 'rentals', 'update');
    }

    public function delete(User $user, Rental $rental): bool
    {
        return $this->sameOrganization($user, $rental)
            && $this->allowsModule($user, 'rentals', 'delete');
    }

    public function export(User $user): bool
    {
        return $this->allowsModule($user, 'rentals', 'read');
    }
}
