<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class RevokeAccountSessions
{
    public function forUser(User $user): void
    {
        // This reset-only rollout revokes the target account's database sessions.
        // Other accounts and non-database session stores are unchanged.
        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))
                ->table(config('session.table', 'sessions'))
                ->where('user_id', $user->getAuthIdentifier())
                ->delete();
        }
    }
}
