<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecureHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        if (!$response->headers->has('Referrer-Policy')) {
            $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');
        if (!$response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', "base-uri 'self'; object-src 'none'; frame-ancestors 'self'; form-action 'self'");
        }
        if (!$response->headers->has('Permissions-Policy')) {
            $response->headers->set('Permissions-Policy', 'camera=(self), geolocation=(self), microphone=()');
        }
        if ($request->isSecure() && !$response->headers->has('Strict-Transport-Security')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=15552000');
        }
        if ($request->routeIs('password.*', 'login')) {
            $response->headers->set('Referrer-Policy', 'no-referrer');
        }
        if ($request->user() || $request->routeIs('password.*', 'login')) {
            $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        }

        return $response;
    }
}
