<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\Organization;
use App\Services\RoleGrantGuard;
use App\Services\Auth\RevokeAccountSessions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        // Use the same organization lock as user/role administration so two
        // concurrent owner deletions cannot both pass the last-owner check.
        DB::transaction(function () use ($user) {
            Organization::query()->whereKey($user->organization_id)->lockForUpdate()->firstOrFail();
            $user->refresh()->unsetRelation('assignedRole');
            app(RoleGrantGuard::class)->protectLastOwnerUser($user, null);
            app(RevokeAccountSessions::class)->forUser($user);
            // Logout rotates the remember token; do it before deletion so the
            // guard cannot save (and reinsert) an already-deleted user model.
            Auth::logout();
            $user->delete();
        });

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
