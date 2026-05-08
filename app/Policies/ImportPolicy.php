<?php

namespace App\Policies;

use App\Models\User;

class ImportPolicy
{
    public function access(User $user): bool
    {
        return $user->isSuperAdmin();
    }
}
