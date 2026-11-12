<?php

namespace App\Services;

use App\Models\Role;
use App\Models\Organization;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class RoleGrantGuard
{
    public function mayManage(User $actor, User|Role $target): bool
    {
        try {
            $target instanceof User
                ? $this->authorizeUserMutation($actor, $target)
                : $this->authorizeRoleMutation($actor, $target);
            return true;
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            if ($exception->getStatusCode() !== 403) {
                throw $exception;
            }
            return false;
        }
    }

    public function mutate(User $actor, string $module, string $action, Closure $change): mixed
    {
        $organizationId = (int) $actor->organization_id;

        return DB::transaction(function () use ($actor, $organizationId, $module, $action, $change) {
            Organization::query()->whereKey($organizationId)->lockForUpdate()->firstOrFail();
            $actor->refresh()->unsetRelation('assignedRole');
            abort_unless((int) $actor->organization_id === $organizationId && $actor->canAccessModule($module, $action), 403);

            return $change();
        });
    }

    public function authorizeRoleMutation(User $actor, Role $role): void
    {
        abort_unless($actor->hasActiveAccess(), 403);
        abort_unless((int) $actor->organization_id === (int) $role->organization_id, 403);
        abort_if(! $actor->isSuperAdmin() && $role->slug === User::ROLE_SUPER_ADMIN, 403, 'Only a Super Admin can change this role.');
        if (! $actor->isSuperAdmin()) {
            $declared = clone $role;
            $declared->is_active = true;
            $this->authorizeCapabilities($actor, $this->capabilities($this->userForRole($declared)));
        }
    }

    public function authorizeRoleChange(User $actor, Role $proposed, ?Role $current = null): void
    {
        $this->authorizeRoleMutation($actor, $proposed);
        if ($current) {
            $this->authorizeRoleMutation($actor, $current);
        }
        if ($actor->isSuperAdmin()) {
            return;
        }

        $added = array_diff($this->capabilities($this->userForRole($proposed)), $current
            ? $this->capabilities($this->userForRole($current))
            : []);
        $this->authorizeCapabilities($actor, $added);
    }

    public function authorizeUserMutation(User $actor, User $target): void
    {
        abort_unless((int) $actor->organization_id === (int) $target->organization_id, 403);
        if ($actor->isSuperAdmin()) {
            return;
        }

        abort_if($target->effective_role === User::ROLE_SUPER_ADMIN, 403, 'Only a Super Admin can change this account.');
        $declared = clone $target;
        $declared->is_active = true;
        if ($target->assignedRole) {
            $role = clone $target->assignedRole;
            $role->is_active = true;
            $declared->setRelation('assignedRole', $role);
        }
        $this->authorizeCapabilities($actor, $this->capabilities($declared));
    }

    public function authorizeUserRole(User $actor, Role $role): void
    {
        abort_unless($this->canGrantRole($actor, $role), 403, 'Choose an active role within your own access.');
    }

    public function canGrantRole(User $actor, Role $role): bool
    {
        if (! $actor->hasActiveAccess() || ! $role->is_active || (int) $actor->organization_id !== (int) $role->organization_id) {
            return false;
        }

        return $actor->isSuperAdmin() || ($role->slug !== User::ROLE_SUPER_ADMIN
            && array_diff($this->capabilities($this->userForRole($role)), $this->capabilities($actor)) === []);
    }

    public function protectLastOwnerUser(User $current, ?User $proposed): void
    {
        if ($current->isSuperAdmin() && ! ($proposed?->isSuperAdmin() ?? false)) {
            abort_unless($this->activeOwners((int) $current->organization_id)->whereKeyNot($current->id)->exists(), 403,
                'Keep at least one active Super Admin in this organization.');
        }
    }

    public function protectLastOwnerRole(Role $current, ?Role $proposed): void
    {
        if ($current->slug !== User::ROLE_SUPER_ADMIN || ! $current->is_active
            || ($proposed && $proposed->slug === User::ROLE_SUPER_ADMIN && $proposed->is_active)) {
            return;
        }

        $owners = $this->activeOwners((int) $current->organization_id);
        if ((clone $owners)->where('role_id', $current->id)->exists()) {
            abort_unless((clone $owners)->where(fn (Builder $query) => $query->whereNull('role_id')->orWhere('role_id', '!=', $current->id))->exists(), 403,
                'Keep at least one active Super Admin in this organization.');
        }
    }

    private function activeOwners(int $organizationId): Builder
    {
        return User::query()->where('organization_id', $organizationId)->where('is_active', true)
            ->where(function (Builder $query) use ($organizationId) {
                $query->where(fn (Builder $legacy) => $legacy->whereNull('role_id')->where('role', User::ROLE_SUPER_ADMIN))
                    ->orWhereHas('assignedRole', fn (Builder $assigned) => $assigned->where('organization_id', $organizationId)
                        ->where('slug', User::ROLE_SUPER_ADMIN)->where('is_active', true));
            });
    }

    private function userForRole(Role $role): User
    {
        $projected = clone $role;
        $projected->id ??= -1;
        $user = new User([
            'organization_id' => $projected->organization_id,
            'role_id' => $projected->id,
            'role' => $projected->slug,
            'is_active' => true,
        ]);
        $user->setRelation('assignedRole', $projected);

        return $user;
    }

    private function authorizeCapabilities(User $actor, array $requested): void
    {
        abort_unless($actor->hasActiveAccess(), 403);
        abort_if(array_diff($requested, $this->capabilities($actor)) !== [], 403,
            'You cannot grant or change access beyond your own permissions.');
    }

    private function capabilities(User $user): array
    {
        if (! $user->hasActiveAccess()) {
            return [];
        }

        $capabilities = [];
        foreach (Role::moduleOptions() as $module => $label) {
            foreach (Role::actionOptions() as $action) {
                if ($user->canAccessModule($module, $action)) {
                    $capabilities[] = $module . '.' . $action;
                }
            }
            if ($user->canAccessModule($module, 'read')) {
                $scope = $user->scopeType($module);
                foreach ($scope === 'all' ? ['all', 'assigned', 'self_created'] : [$scope] as $allowedScope) {
                    $capabilities[] = 'scope.' . $module . '.' . $allowedScope;
                }
            }
        }
        foreach (Role::specialPermissionOptions() as $permission => $label) {
            if ($user->hasPermission($permission)) {
                $capabilities[] = $permission;
            }
        }
        foreach ([
            'owner' => 'isSuperAdmin',
            'dashboard' => 'canAccessDashboard',
            'management' => 'canViewManagementAnalytics',
            'business_analytics' => 'canViewSensitiveBusinessAnalytics',
            'record_finance' => 'canViewRecordFinance',
            'finance_dashboard' => 'canViewFinanceDashboard',
            'profitability' => 'canViewProfitability',
            'general_reports' => 'canAccessGeneralReports',
        ] as $capability => $method) {
            if ($user->{$method}()) {
                $capabilities[] = 'profile.' . $capability;
            }
        }

        return array_values(array_unique($capabilities));
    }
}
