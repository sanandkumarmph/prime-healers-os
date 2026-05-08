<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserPermission
{
    private function deny(Request $request, string $message): Response
    {
        if ($request->expectsJson()) {
            abort(403, $message);
        }

        $user = $request->user();
        $fallbackPath = $user && method_exists($user, 'defaultRedirectPath')
            ? $user->defaultRedirectPath()
            : route('login');

        return redirect()
            ->to($fallbackPath)
            ->with('error', $message);
    }

    public function handle(Request $request, Closure $next, string $permissions): Response
    {
        $user = $request->user();

        if (!$user) {
            return $this->deny($request, 'Unauthorized.');
        }

        $permissionList = collect(explode('|', $permissions))
            ->map(fn (string $permission) => trim($permission))
            ->filter()
            ->values()
            ->all();

        if (empty($permissionList) || !$user->hasAnyPermission($permissionList)) {
            return $this->deny($request, 'You are not authorized to access this section.');
        }

        return $next($request);
    }
}
