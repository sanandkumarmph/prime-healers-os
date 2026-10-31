<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\PasswordRecovery;
use App\Services\Auth\RevokeAccountSessions;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class NewPasswordController extends Controller
{
    /**
     * Display the password reset view.
     */
    public function create(Request $request): View
    {
        return view('auth.reset-password', ['request' => $request]);
    }

    /**
     * Handle an incoming new password request.
     *
     * @throws ValidationException
     */
    public function store(Request $request, PasswordRecovery $recovery): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string', 'max:256'],
            'email' => ['required', 'string', 'email', 'max:254'],
            'password' => ['required', 'string', 'max:255', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = $recovery->eligibleUser($request->string('email')->toString());
        if (!$user) {
            return back()->withInput($request->only('email'))->withErrors(['email' => __(Password::INVALID_TOKEN)]);
        }

        $status = DB::transaction(function () use ($request, $user, $recovery) {
            // One account lock prevents two submissions from consuming one token.
            $current = User::query()->whereKey($user->id)->lockForUpdate()->first();
            $eligible = $current ? $recovery->eligibleUser($current->email) : null;
            if (!$eligible || !$eligible->is($current) || $current->email !== $user->email) {
                return Password::INVALID_TOKEN;
            }

            return Password::reset(
                array_merge($request->only('password', 'password_confirmation', 'token'), ['email' => $current->getEmailForPasswordReset()]),
                function (User $user) use ($request) {
                    $user->forceFill([
                        'password' => Hash::make($request->password),
                        'remember_token' => Str::random(60),
                    ])->save();

                    app(RevokeAccountSessions::class)->forUser($user);
                    event(new PasswordReset($user));
                }
            );
        });

        // If the password was successfully reset, we will redirect the user back to
        // the application's home authenticated view. If there is an error we can
        // redirect them back to where they came from with their error message.
        return $status == Password::PASSWORD_RESET
                    ? redirect()->route('login')->with('status', __($status))
                    : back()->withInput($request->only('email'))
                        ->withErrors(['email' => __($status)]);
    }
}
