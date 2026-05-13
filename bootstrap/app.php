<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(function ($request) {
            $user = $request->user();

            return $user ? $user->defaultRedirectPath() : route('login');
        });

        $middleware->append(\App\Http\Middleware\SecureHeaders::class);
        $middleware->append(\App\Http\Middleware\EnsureInternalOrganizationContext::class);

        $middleware->alias([
            'module' => \App\Http\Middleware\EnsureModulePermission::class,
            'permission' => \App\Http\Middleware\EnsureUserPermission::class,
            'data_import' => \App\Http\Middleware\EnsureDataImportAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $handleExpiredSession = function ($request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Your session expired. Please login again.',
                ], 419);
            }

            Auth::guard('web')->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return redirect()
                ->guest(route('login'))
                ->with('status', 'Your session expired. Please login again.');
        };

        $exceptions->render(function (TokenMismatchException $exception, $request) use ($handleExpiredSession) {
            return $handleExpiredSession($request);
        });

        $exceptions->render(function (HttpExceptionInterface $exception, $request) use ($handleExpiredSession) {
            if ($exception->getStatusCode() !== 419) {
                return null;
            }

            return $handleExpiredSession($request);
        });
    })->create();
