<?php

namespace App\Policies;

use App\Models\Rental;
use App\Models\User;
use App\Policies\Concerns\HandlesModuleAuthorization;
use App\Services\Deliveries\VendorWorkScope;

class RentalPolicy
{
    use HandlesModuleAuthorization;

    public function __construct(private VendorWorkScope $vendorWork)
    {
    }

    public function viewAny(User $user): bool
    {
        return $this->allowsModule($user, 'rentals', 'read');
    }

    public function view(User $user, Rental $rental): bool
    {
        return $this->sameOrganization($user, $rental)
            && $this->allowsModule($user, 'rentals', 'read')
            && (!$user->isVendor() || $this->vendorWork->canViewRental($user, $rental));
    }

    public function create(User $user): bool
    {
        return !$user->isVendor() && $this->allowsModule($user, 'rentals', 'create');
    }

    public function update(User $user, Rental $rental): bool
    {
        return !$user->isVendor() && $this->sameOrganization($user, $rental)
            && $this->allowsModule($user, 'rentals', 'update');
    }

    public function delete(User $user, Rental $rental): bool
    {
        return !$user->isVendor() && $this->sameOrganization($user, $rental)
            && $this->allowsModule($user, 'rentals', 'delete');
    }

    public function export(User $user): bool
    {
        return !$user->isVendor() && $this->allowsModule($user, 'rentals', 'read');
    }
}
