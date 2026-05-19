<?php

namespace App\Http\Controllers;

use App\Models\BusinessPartner;
use App\Models\PartnerClient;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PartnerClientController extends Controller
{
    private function orgId(): int
    {
        return (int) auth()->user()->organization_id;
    }

    private function scopedPartner(BusinessPartner $businessPartner): BusinessPartner
    {
        abort_if((int) $businessPartner->organization_id !== $this->orgId(), 403);

        return $businessPartner;
    }

    private function scopedClient(PartnerClient $partnerClient): PartnerClient
    {
        abort_if((int) $partnerClient->organization_id !== $this->orgId(), 403);

        return $partnerClient;
    }

    public function create(BusinessPartner $businessPartner)
    {
        $businessPartner = $this->scopedPartner($businessPartner);
        $this->authorize('create', PartnerClient::class);

        return view('business-partners.clients.create', compact('businessPartner'));
    }

    public function store(Request $request, BusinessPartner $businessPartner)
    {
        $businessPartner = $this->scopedPartner($businessPartner);
        $this->authorize('create', PartnerClient::class);

        $validated = $request->validate($this->validationRules($businessPartner));
        $validated['organization_id'] = $this->orgId();
        $validated['business_partner_id'] = $businessPartner->id;
        $validated['status'] = $validated['status'] ?? 'active';

        $partnerClient = PartnerClient::create($validated);

        return redirect()
            ->route('business-partners.show', $businessPartner)
            ->with('success', $partnerClient->displayName() . ' added successfully.');
    }

    public function edit(BusinessPartner $businessPartner, PartnerClient $partnerClient)
    {
        $businessPartner = $this->scopedPartner($businessPartner);
        $partnerClient = $this->scopedClient($partnerClient);
        abort_if((int) $partnerClient->business_partner_id !== (int) $businessPartner->id, 404);
        $this->authorize('update', $partnerClient);

        return view('business-partners.clients.edit', compact('businessPartner', 'partnerClient'));
    }

    public function update(Request $request, BusinessPartner $businessPartner, PartnerClient $partnerClient)
    {
        $businessPartner = $this->scopedPartner($businessPartner);
        $partnerClient = $this->scopedClient($partnerClient);
        abort_if((int) $partnerClient->business_partner_id !== (int) $businessPartner->id, 404);
        $this->authorize('update', $partnerClient);

        $validated = $request->validate($this->validationRules($businessPartner, $partnerClient));
        $partnerClient->update($validated);

        return redirect()
            ->route('business-partners.show', $businessPartner)
            ->with('success', $partnerClient->displayName() . ' updated successfully.');
    }

    public function destroy(BusinessPartner $businessPartner, PartnerClient $partnerClient)
    {
        $businessPartner = $this->scopedPartner($businessPartner);
        $partnerClient = $this->scopedClient($partnerClient);
        abort_if((int) $partnerClient->business_partner_id !== (int) $businessPartner->id, 404);
        $this->authorize('delete', $partnerClient);

        if ($partnerClient->rentals()->exists() || $partnerClient->sales()->exists()) {
            return redirect()
                ->route('business-partners.show', $businessPartner)
                ->with('error', 'This actual client cannot be deleted because it has linked rentals or sales.');
        }

        $partnerClient->delete();

        return redirect()
            ->route('business-partners.show', $businessPartner)
            ->with('success', 'Actual client deleted successfully.');
    }

    private function validationRules(BusinessPartner $businessPartner, ?PartnerClient $partnerClient = null): array
    {
        return [
            'client_name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('partner_clients', 'client_name')
                    ->where(fn ($query) => $query
                        ->where('organization_id', $this->orgId())
                        ->where('business_partner_id', $businessPartner->id))
                    ->ignore($partnerClient?->id),
            ],
            'phone' => 'nullable|string|max:30',
            'alternate_phone' => 'nullable|string|max:30',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:120',
            'state' => 'nullable|string|max:120',
            'pincode' => 'nullable|string|max:20',
            'location' => 'nullable|string|max:1000',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'delivery_notes' => 'nullable|string',
            'status' => 'nullable|in:active,inactive',
        ];
    }
}
