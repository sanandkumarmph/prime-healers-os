<?php

namespace App\Policies;

use App\Models\Delivery;
use App\Models\User;
use App\Policies\Concerns\HandlesModuleAuthorization;

class DeliveryPolicy
{
    use HandlesModuleAuthorization;

    public function viewAny(User $user): bool
    {
        return $this->allowsModule($user, 'deliveries', 'read');
    }

    public function view(User $user, Delivery $delivery): bool
    {
        return $this->sameOrganization($user, $delivery)
            && $this->allowsModule($user, 'deliveries', 'read');
    }

    public function create(User $user): bool
    {
        return $this->allowsModule($user, 'deliveries', 'create');
    }

    public function update(User $user, Delivery $delivery): bool
    {
        return $this->sameOrganization($user, $delivery)
            && $this->allowsDeliveryAction($user, $delivery, 'update');
    }

    public function delete(User $user, Delivery $delivery): bool
    {
        return $this->sameOrganization($user, $delivery)
            && $this->allowsDeliveryAction($user, $delivery, 'delete');
    }

    private function allowsDeliveryAction(User $user, Delivery $delivery, string $action): bool
    {
        if (! $this->allowsModule($user, 'deliveries', $action)) {
            return false;
        }

        if ($user->hasScope('assigned', 'deliveries') && array_key_exists('assigned_user_id', $delivery->getAttributes())) {
            return (int) ($delivery->assigned_user_id ?? 0) === (int) $user->id;
        }

        return true;
    }
}
