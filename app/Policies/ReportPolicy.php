<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\HandlesModuleAuthorization;

class ReportPolicy
{
    use HandlesModuleAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->canAccessGeneralReports();
    }

    public function export(User $user): bool
    {
        return $user->canAccessGeneralReports();
    }
}
