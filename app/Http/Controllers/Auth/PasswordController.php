<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\RevokeAccountSessions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rules\Password;

class PasswordController extends Controller
{
    /**
     * Update the user's password.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag('updatePassword', [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'string', 'max:255', Password::defaults(), 'confirmed'],
        ]);

        DB::transaction(function () use ($request, $validated) {
            $user = User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            if (!$user->hasActiveAccess() || !Hash::check($validated['current_password'], $user->password)) {
                throw ValidationException::withMessages(['current_password' => __('auth.password')])->errorBag('updatePassword');
            }
            $user->forceFill(['password' => Hash::make($validated['password']), 'remember_token' => Str::random(60)])->save();
            app(RevokeAccountSessions::class)->forUser($user);
            $request->user()->setRawAttributes($user->getAttributes(), true);
        });
        // Preserve this freshly authorized session while invalidating old cookies
        // and sessions on other devices, including non-database session stores.
        $request->session()->regenerate();
        $request->session()->put('password_hash_web', Auth::guard('web')->hashPasswordForCookie($request->user()->getAuthPassword()));

        return back()->with('status', 'password-updated');
    }
}
