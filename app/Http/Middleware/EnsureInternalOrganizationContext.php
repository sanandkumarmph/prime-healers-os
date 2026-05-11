<?php

namespace App\Http\Middleware;

use App\Support\InternalOrganization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureInternalOrganizationContext
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!InternalOrganization::enabled()) {
            return $next($request);
        }

        $user = $request->user();

        if ($user) {
            InternalOrganization::ensureUserAssigned($user);
            $user->loadMissing(['assignedRole', 'organization']);
        }

        return $next($request);
    }
}
