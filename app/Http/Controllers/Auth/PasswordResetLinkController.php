<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\PasswordRecovery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Log;
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
            // Finish the HTTP response before SMTP so mailbox eligibility does not
            // expose the network delivery time. This requires no queue worker.
            defer(function () use ($user): void {
                try {
                    Password::sendResetLink(['email' => $user->getEmailForPasswordReset()]);
                } catch (\Throwable $exception) {
                    // Do not log tokens, message bodies, recipient addresses or transport credentials.
                    Log::error('Password recovery delivery failed.', ['exception_type' => $exception::class]);
                    try {
                        Password::deleteToken($user);
                    } catch (\Throwable $cleanupException) {
                        Log::error('Password recovery token cleanup failed.', ['exception_type' => $cleanupException::class]);
                    }
                }
            })->always();
        }

        // An unknown, disabled or throttled account gets the same public response.
        return back()->with('status', PasswordRecovery::RECEIVED);
    }
}
