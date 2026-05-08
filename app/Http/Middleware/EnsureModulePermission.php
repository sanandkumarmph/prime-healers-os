<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureModulePermission
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

    public function handle(Request $request, Closure $next, string $modules, string $action = 'read'): Response
    {
        $user = $request->user();

        if (!$user) {
            return $this->deny($request, 'Unauthorized.');
        }

        $moduleList = collect(explode('|', $modules))
            ->map(fn (string $module) => trim($module))
            ->filter()
            ->values()
            ->all();

        if (empty($moduleList) || !$user->canAccessAnyModule($moduleList, $action)) {
            return $this->deny($request, 'You are not authorized to access this section.');
        }

        return $next($request);
    }
}
