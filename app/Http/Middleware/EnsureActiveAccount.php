<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (!$user) {
            return $next($request);
        }
        $legacySession = config('session.driver') !== 'database'
            && $request->hasSession()
            && $request->session()->has(Auth::guard('web')->getName())
            && !$request->session()->has('password_hash_web');
        if ($user->hasActiveAccess() && !$legacySession) {
            return $next($request);
        }
        $message = $legacySession
            ? 'Please sign in again following the security update.'
            : 'This account no longer has access. Contact your administrator.';

        Auth::guard('web')->logoutCurrentDevice();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return $request->expectsJson()
            ? response()->json(['message' => $message], 403)
            : redirect()->guest(route('login'))->with('status', $message);
    }
}
