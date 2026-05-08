<?php

namespace App\Http\Controllers;

use App\Models\City;
use App\Models\Delivery;
use App\Models\Vendor;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class VendorController extends Controller
{
    private function orgId(): int
    {
        return (int) auth()->user()->organization_id;
    }

    private function scopedVendor(Vendor $vendor): Vendor
    {
        abort_if($vendor->organization_id !== $this->orgId(), 403);

        return $vendor;
    }

    private function validationRules(?Vendor $vendor = null): array
    {
        $organizationId = $this->orgId();

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('vendors', 'name')
                    ->where(fn ($query) => $query->where('organization_id', $organizationId))
                    ->ignore($vendor?->id),
            ],
            'contact_person' => 'nullable|string|max:255',
            'phone' => PhoneNumber::validationRules(),
            'phone_country_code' => 'nullable|string|max:8',
            'email' => 'nullable|email|max:255',
            'city_id' => ['nullable', Rule::exists('cities', 'id')->where(fn ($query) => $query->where('organization_id', $organizationId))],
            'address' => 'nullable|string',
            'notes' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ];
    }

    public function index(Request $request)
    {
        $search = trim((string) $request->get('search', ''));
        $status = trim((string) $request->get('status', ''));

        $vendors = Vendor::query()
            ->forOrganization($this->orgId())
            ->with('cityRecord')
            ->search($search)
            ->when($status !== '', fn ($query) => $query->where('is_active', $status === 'active'))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('vendors.index', [
            'vendors' => $vendors,
            'search' => $search,
            'status' => $status,
        ]);
    }

    public function create()
    {
        return view('vendors.create', [
            'vendor' => new Vendor(['is_active' => true]),
            'cities' => City::forOrganization($this->orgId())->active()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->validationRules());
        $validated = PhoneNumber::normalizeFields($validated, ['phone']);
        $city = filled($validated['city_id'] ?? null)
            ? City::forOrganization($this->orgId())->find($validated['city_id'])
            : null;

        $vendor = Vendor::create([
            ...$validated,
            'organization_id' => $this->orgId(),
            'city' => $city?->name,
            'is_active' => (bool) ($validated['is_active'] ?? false),
        ]);

        return redirect()->route('vendors.show', $vendor)->with('success', 'Vendor created successfully.');
    }

    public function show(Vendor $vendor)
    {
        $vendor = $this->scopedVendor($vendor);
        $vendor->load('cityRecord');

        return view('vendors.show', compact('vendor'));
    }

    public function edit(Vendor $vendor)
    {
        $vendor = $this->scopedVendor($vendor);

        return view('vendors.edit', [
            'vendor' => $vendor,
            'cities' => City::forOrganization($this->orgId())->active()->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, Vendor $vendor)
    {
        $vendor = $this->scopedVendor($vendor);
        $validated = $request->validate($this->validationRules($vendor));
        $validated = PhoneNumber::normalizeFields($validated, ['phone']);
        $city = filled($validated['city_id'] ?? null)
            ? City::forOrganization($this->orgId())->find($validated['city_id'])
            : null;

        $vendor->update([
            ...$validated,
            'city' => $city?->name,
            'is_active' => (bool) ($validated['is_active'] ?? false),
        ]);

        return redirect()->route('vendors.show', $vendor)->with('success', 'Vendor updated successfully.');
    }

    public function destroy(Vendor $vendor)
    {
        $vendor = $this->scopedVendor($vendor);

        $dependencyLabels = [];

        if (
            Schema::hasColumn('deliveries', 'assigned_to')
            && Delivery::where('organization_id', $this->orgId())
                ->where('assigned_to', $vendor->name)
                ->exists()
        ) {
            $dependencyLabels[] = 'delivery assignments';
        }

        if (
            Schema::hasColumn('deliveries', 'third_party_name')
            && Delivery::where('organization_id', $this->orgId())
                ->where('third_party_name', $vendor->name)
                ->exists()
        ) {
            $dependencyLabels[] = 'third-party delivery tasks';
        }

        if (!empty($dependencyLabels)) {
            return redirect()
                ->back()
                ->with('error', 'Cannot delete this vendor because it is linked to ' . implode(', ', array_unique($dependencyLabels)) . '.');
        }

        $vendor->delete();

        return redirect()->route('vendors.index')->with('success', 'Vendor deleted successfully.');
    }
}
