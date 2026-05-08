<?php

namespace App\Policies\Concerns;

use App\Models\Organization;
use App\Models\User;

trait HandlesModuleAuthorization
{
    protected function allowsModule(User $user, string $module, string $action = 'read'): bool
    {
        return $user->canAccessModule($module, $action);
    }

    protected function sameOrganization(User $user, mixed $model): bool
    {
        $modelOrganizationId = 0;

        if ($model instanceof Organization) {
            $modelOrganizationId = (int) ($model->getKey() ?? 0);
        } elseif (isset($model->organization_id)) {
            $modelOrganizationId = (int) $model->organization_id;
        }

        return (int) ($user->organization_id ?? 0) > 0
            && $modelOrganizationId > 0
            && $modelOrganizationId === (int) $user->organization_id;
    }
}
