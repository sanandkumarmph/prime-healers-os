<?php

namespace App\Http\Controllers;

use App\Models\BusinessPartner;
use App\Models\PartnerClient;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BusinessPartnerController extends Controller
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

    public function index(Request $request)
    {
        $this->authorize('viewAny', BusinessPartner::class);

        $search = trim((string) $request->query('search', ''));
        $status = trim((string) $request->query('status', ''));

        $query = BusinessPartner::query()
            ->where('organization_id', $this->orgId())
            ->withCount('partnerClients')
            ->orderBy('business_name');

        if ($search !== '') {
            $query->where(function ($innerQuery) use ($search) {
                $innerQuery
                    ->where('business_name', 'like', '%' . $search . '%')
                    ->orWhere('contact_person', 'like', '%' . $search . '%')
                    ->orWhere('phone', 'like', '%' . $search . '%')
                    ->orWhere('email', 'like', '%' . $search . '%')
                    ->orWhere('city', 'like', '%' . $search . '%')
                    ->orWhere('state', 'like', '%' . $search . '%');
            });
        }

        if ($status !== '') {
            $query->where('status', $status);
        }

        $businessPartners = $query->paginate(20)->withQueryString();
        $summaryBaseQuery = BusinessPartner::query()->where('organization_id', $this->orgId());
        $totalPartners = (clone $summaryBaseQuery)->count();
        $activePartners = (clone $summaryBaseQuery)->where('status', 'active')->count();
        $totalClients = PartnerClient::query()->where('organization_id', $this->orgId())->count();
        $activeClients = PartnerClient::query()->where('organization_id', $this->orgId())->where('status', 'active')->count();

        return view('business-partners.index', compact(
            'businessPartners',
            'search',
            'status',
            'totalPartners',
            'activePartners',
            'totalClients',
            'activeClients',
        ));
    }

    public function create()
    {
        $this->authorize('create', BusinessPartner::class);

        return view('business-partners.create');
    }

    public function store(Request $request)
    {
        $this->authorize('create', BusinessPartner::class);

        $validated = $request->validate($this->validationRules());
        $validated['organization_id'] = $this->orgId();
        $validated['status'] = $validated['status'] ?? 'active';

        $businessPartner = BusinessPartner::create($validated);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Business partner added successfully.',
                'business_partner' => $this->quickPartnerPayload($businessPartner->fresh('partnerClients')),
            ]);
        }

        return redirect()
            ->route('business-partners.show', $businessPartner)
            ->with('success', 'Business partner added successfully.');
    }

    public function show(Request $request, BusinessPartner $businessPartner)
    {
        $businessPartner = $this->scopedPartner($businessPartner);
        $this->authorize('view', $businessPartner);

        $clientSearch = trim((string) $request->query('client_search', ''));
        $clientStatus = trim((string) $request->query('client_status', ''));

        $clientsQuery = $businessPartner->partnerClients()->where('organization_id', $this->orgId());

        if ($clientSearch !== '') {
            $clientsQuery->where(function ($innerQuery) use ($clientSearch) {
                $innerQuery
                    ->where('client_name', 'like', '%' . $clientSearch . '%')
                    ->orWhere('phone', 'like', '%' . $clientSearch . '%')
                    ->orWhere('address', 'like', '%' . $clientSearch . '%')
                    ->orWhere('city', 'like', '%' . $clientSearch . '%')
                    ->orWhere('state', 'like', '%' . $clientSearch . '%');
            });
        }

        if ($clientStatus !== '') {
            $clientsQuery->where('status', $clientStatus);
        }

        $clients = $clientsQuery->paginate(15, ['*'], 'clients_page')->withQueryString();

        return view('business-partners.show', compact(
            'businessPartner',
            'clients',
            'clientSearch',
            'clientStatus',
        ));
    }

    public function edit(BusinessPartner $businessPartner)
    {
        $businessPartner = $this->scopedPartner($businessPartner);
        $this->authorize('update', $businessPartner);

        return view('business-partners.edit', compact('businessPartner'));
    }

    public function update(Request $request, BusinessPartner $businessPartner)
    {
        $businessPartner = $this->scopedPartner($businessPartner);
        $this->authorize('update', $businessPartner);

        $validated = $request->validate($this->validationRules($businessPartner));
        $businessPartner->update($validated);

        return redirect()
            ->route('business-partners.show', $businessPartner)
            ->with('success', 'Business partner updated successfully.');
    }

    public function destroy(BusinessPartner $businessPartner)
    {
        $businessPartner = $this->scopedPartner($businessPartner);
        $this->authorize('delete', $businessPartner);

        $hasLinkedOrders = $businessPartner->rentals()->exists() || $businessPartner->sales()->exists();
        $hasLinkedClients = $businessPartner->partnerClients()->exists();

        if ($hasLinkedOrders || $hasLinkedClients) {
            return redirect()
                ->route('business-partners.show', $businessPartner)
                ->with('error', 'This business partner cannot be deleted because it has linked clients or orders.');
        }

        $businessPartner->delete();

        return redirect()
            ->route('business-partners.index')
            ->with('success', 'Business partner deleted successfully.');
    }

    private function validationRules(?BusinessPartner $businessPartner = null): array
    {
        return [
            'business_name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('business_partners', 'business_name')
                    ->where(fn ($query) => $query->where('organization_id', $this->orgId()))
                    ->ignore($businessPartner?->id),
            ],
            'contact_person' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:30',
            'whatsapp' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:120',
            'state' => 'nullable|string|max:120',
            'pincode' => 'nullable|string|max:20',
            'location' => 'nullable|string|max:1000',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'status' => 'nullable|in:active,inactive',
        ];
    }

    private function quickPartnerPayload(BusinessPartner $businessPartner): array
    {
        return [
            'id' => $businessPartner->id,
            'name' => $businessPartner->displayName(),
            'contact_person' => $businessPartner->contact_person,
            'phone' => $businessPartner->phone,
            'whatsapp' => $businessPartner->whatsapp,
            'email' => $businessPartner->email,
            'address' => $businessPartner->address,
            'city' => $businessPartner->city,
            'state' => $businessPartner->state,
            'location' => $businessPartner->openMapUrl(),
            'clients' => [],
        ];
    }
}
