<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class OrganizationSettingsController extends Controller
{
    public function edit()
    {
        $organization = $this->organization();
        $this->authorize('view', $organization);

        return view('organization.settings', compact('organization'));
    }

    public function update(Request $request)
    {
        $organization = $this->organization();
        $this->authorize('update', $organization);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:255',
            'state' => 'nullable|string|max:255',
            'state_code' => 'nullable|string|max:50',
            'pincode' => 'nullable|string|max:20',
            'country' => 'nullable|string|max:255',
            'gst_number' => 'nullable|string|max:255',
            'phone' => PhoneNumber::validationRules(),
            'phone_country_code' => 'nullable|string|max:8',
            'email' => 'nullable|email|max:255',
            'bank_account_name' => 'nullable|string|max:255',
            'bank_account_number' => 'nullable|string|max:255',
            'bank_ifsc' => 'nullable|string|max:255',
            'bank_name' => 'nullable|string|max:255',
            'bank_branch' => 'nullable|string|max:255',
            'upi_id' => 'nullable|string|max:255',
            'default_terms' => 'nullable|string',
            'logo' => 'nullable|image|max:2048',
            'payment_qr_code' => 'nullable|image|max:4096',
            'digital_signature' => 'nullable|image|max:4096',
        ]);

        foreach (['logo', 'payment_qr_code', 'digital_signature'] as $fileField) {
            if ($request->hasFile($fileField)) {
                if (!empty($organization->{$fileField})) {
                    Storage::disk('public')->delete($organization->{$fileField});
                }

                $validated[$fileField] = $request->file($fileField)->store('organization-assets', 'public');
            } else {
                unset($validated[$fileField]);
            }
        }

        $validated = PhoneNumber::normalizeFields($validated, ['phone']);

        $organization->update($validated);

        return redirect()
            ->route('organization.settings.edit')
            ->with('success', 'Organization invoice settings updated successfully.');
    }

    private function organization(): Organization
    {
        $user = Auth::user();
        $organizationId = (int) ($user?->organization_id ?? 0);

        if ($organizationId > 0) {
            $organization = Organization::find($organizationId);

            if ($organization) {
                return $organization;
            }
        }

        $fallbackOrganization = Organization::query()->orderBy('id')->get();

        if ($fallbackOrganization->count() === 1) {
            $organization = $fallbackOrganization->first();

            if ($user && (int) ($user->organization_id ?? 0) !== (int) $organization->id) {
                $user->forceFill([
                    'organization_id' => $organization->id,
                ])->save();
            }

            return $organization;
        }

        abort(404);
    }
}
