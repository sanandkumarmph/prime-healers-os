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

        if ($user->hasScope('assigned', 'deliveries')) {
            return $this->allowsAssignedScopeWorkflowAction($user, $delivery);
        }

        return true;
    }

    private function allowsAssignedScopeWorkflowAction(User $user, Delivery $delivery): bool
    {
        $attributes = $delivery->getAttributes();
        $assignedUserId = array_key_exists('assigned_user_id', $attributes)
            ? (int) ($delivery->assigned_user_id ?? 0)
            : 0;

        if ($assignedUserId > 0) {
            return $assignedUserId === (int) $user->id;
        }

        $assignmentType = strtolower((string) ($delivery->assignment_type ?? 'delivery_team'));
        $assignedStaffId = array_key_exists('assigned_staff_id', $attributes)
            ? (int) ($delivery->assigned_staff_id ?? 0)
            : 0;

        if ($assignmentType === 'vendor' && $assignedStaffId > 0) {
            return false;
        }

        return in_array($assignmentType, ['', 'delivery_team', 'third_party'], true);
    }
}
