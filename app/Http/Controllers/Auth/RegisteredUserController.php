<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\InternalOrganization;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    private function ensurePublicRegistrationEnabled(): void
    {
        abort_unless(config('auth.allow_public_registration'), 404);
    }

    /**
     * Display the registration view.
     */
    public function create(): View
    {
        $this->ensurePublicRegistrationEnabled();

        return view('auth.register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $this->ensurePublicRegistrationEnabled();

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'organization_id' => InternalOrganization::id(),
            'is_internal' => InternalOrganization::enabled(),
        ]);

        event(new Registered($user));

        Auth::login($user);

        return redirect($user->defaultRedirectPath());
    }
}
