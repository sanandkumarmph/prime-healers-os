<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\PasswordRecovery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use function Illuminate\Support\defer;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(PasswordRecovery $recovery): View
    {
        return view('auth.forgot-password', ['passwordRecoveryAvailable' => $recovery->available()]);
    }

    /**
     * Handle an incoming password reset link request.
     *
     * @throws ValidationException
     */
    public function store(Request $request, PasswordRecovery $recovery): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
        ]);

        if (!$recovery->available()) {
            return back()->withInput($request->only('email'))->withErrors(['email' => PasswordRecovery::UNAVAILABLE]);
        }
        $user = $recovery->eligibleUser($request->string('email')->toString());
        if ($user) {
            // SMTP runs after the response so delivery timing does not reveal
            // whether an address belongs to an eligible account. No worker is needed.
            defer(function () use ($user, $recovery): void {
                $current = null;
                try {
                    $current = $recovery->eligibleUser($user->getEmailForPasswordReset());
                    if (!$current || !$current->is($user)) {
                        return;
                    }
                    Password::sendResetLink(['email' => $current->getEmailForPasswordReset()]);
                } catch (\Throwable $exception) {
                    Log::error('Password recovery delivery failed.', ['exception_type' => $exception::class]);
                    if ($current) {
                        try {
                            Password::deleteToken($current);
                        } catch (\Throwable $cleanupException) {
                            Log::error('Password recovery token cleanup failed.', ['exception_type' => $cleanupException::class]);
                        }
                    }
                }
            })->always();
        }

        return back()->with('status', PasswordRecovery::RECEIVED);
    }
}
