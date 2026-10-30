<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class RevokeAccountSessions
{
    public function forUser(User $user): void
    {
        // AuthenticateSession validates password hashes for other session stores.
        // Also remove pre-existing database sessions which predate that middleware.
        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))
                ->table(config('session.table', 'sessions'))
                ->where('user_id', $user->getAuthIdentifier())
                ->delete();
        }
    }
}
