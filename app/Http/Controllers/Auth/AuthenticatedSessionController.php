<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Support\InternalOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();
        InternalOrganization::ensureUserAssigned($request->user());

        // A bookmarked dashboard can survive in the guest's intended URL even
        // after an administrator removes dashboard access from this role.
        if (! $request->user()->canAccessDashboard()) {
            $intended = $request->session()->get('url.intended');
            $path = is_string($intended) ? parse_url($intended, PHP_URL_PATH) : null;
            if (in_array(rtrim((string) $path, '/'), ['/dashboard', '/business-overview'], true)) {
                $request->session()->forget('url.intended');
            }
        }

        return redirect()->intended($request->user()->defaultRedirectPath());
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
