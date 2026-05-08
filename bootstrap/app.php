<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Session\TokenMismatchException;

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

        $middleware->alias([
            'module' => \App\Http\Middleware\EnsureModulePermission::class,
            'permission' => \App\Http\Middleware\EnsureUserPermission::class,
            'data_import' => \App\Http\Middleware\EnsureDataImportAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (TokenMismatchException $exception, $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Your session expired. Please refresh the page and try again.',
                ], 419);
            }

            return redirect()
                ->route('login')
                ->with('status', 'Your session expired. Please sign in again.');
        });
    })->create();
