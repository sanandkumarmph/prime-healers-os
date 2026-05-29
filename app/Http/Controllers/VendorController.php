<?php

namespace App\Http\Controllers;

use App\Models\City;
use App\Models\Delivery;
use App\Models\Rental;
use App\Models\Sale;
use App\Models\Vendor;
use App\Models\VendorOrderDetail;
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
            'whatsapp' => PhoneNumber::validationRules(false),
            'whatsapp_country_code' => 'nullable|string|max:8',
            'email' => 'nullable|email|max:255',
            'vendor_type' => 'nullable|string|max:80',
            'gst_number' => 'nullable|string|max:32',
            'gst_registration_type' => 'nullable|string|max:80',
            'city_id' => ['nullable', Rule::exists('cities', 'id')->where(fn ($query) => $query->where('organization_id', $organizationId))],
            'address' => 'nullable|string',
            'state' => 'nullable|string|max:120',
            'pincode' => 'nullable|string|max:20',
            'payment_terms' => 'nullable|string|max:120',
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
        $validated = PhoneNumber::normalizeFields($validated, ['phone', 'whatsapp']);
        $city = filled($validated['city_id'] ?? null)
            ? City::forOrganization($this->orgId())->find($validated['city_id'])
            : null;

        $vendor = Vendor::create([
            ...$validated,
            'organization_id' => $this->orgId(),
            'city' => $city?->name,
            'is_active' => (bool) ($validated['is_active'] ?? false),
            'deactivated_at' => (bool) ($validated['is_active'] ?? false) ? null : now(),
        ]);

        return redirect()->route('vendors.show', $vendor)->with('success', 'Vendor created successfully.');
    }

    public function show(Vendor $vendor)
    {
        $vendor = $this->scopedVendor($vendor);
        $vendor->load(['cityRecord', 'vendorOrderDetails']);

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
        $validated = PhoneNumber::normalizeFields($validated, ['phone', 'whatsapp']);
        $city = filled($validated['city_id'] ?? null)
            ? City::forOrganization($this->orgId())->find($validated['city_id'])
            : null;

        $vendor->update([
            ...$validated,
            'city' => $city?->name,
            'is_active' => (bool) ($validated['is_active'] ?? false),
            'deactivated_at' => (bool) ($validated['is_active'] ?? false) ? null : now(),
        ]);

        return redirect()->route('vendors.show', $vendor)->with('success', 'Vendor updated successfully.');
    }

    public function exportCsv(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('vendors.export') ?? false, 403);

        $search = trim((string) $request->get('search', ''));
        $status = trim((string) $request->get('status', ''));

        $vendors = Vendor::query()
            ->forOrganization($this->orgId())
            ->with('cityRecord')
            ->search($search)
            ->when($status !== '', fn ($query) => $query->where('is_active', $status === 'active'))
            ->orderBy('name')
            ->get();

        $fileName = 'vendors-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($vendors) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'Name',
                'Contact Person',
                'Phone',
                'WhatsApp',
                'Email',
                'Vendor Type',
                'GST Number',
                'GST Registration Type',
                'Address',
                'City',
                'State',
                'Pincode',
                'Payment Terms',
                'Status',
                'Notes',
            ]);

            foreach ($vendors as $vendor) {
                fputcsv($handle, [
                    $vendor->name,
                    $vendor->contact_person,
                    $vendor->phone,
                    $vendor->whatsapp,
                    $vendor->email,
                    $vendor->vendor_type,
                    $vendor->gst_number,
                    $vendor->gst_registration_type,
                    $vendor->address,
                    $vendor->cityRecord?->name ?? $vendor->city,
                    $vendor->state,
                    $vendor->pincode,
                    $vendor->payment_terms,
                    $vendor->is_active ? 'Active' : 'Inactive',
                    $vendor->notes,
                ]);
            }

            fclose($handle);
        }, $fileName, ['Content-Type' => 'text/csv']);
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

        if (Rental::query()->where('organization_id', $this->orgId())->where('vendor_id', $vendor->id)->exists()) {
            $dependencyLabels[] = 'rentals';
        }

        if (Sale::query()->where('organization_id', $this->orgId())->where('vendor_id', $vendor->id)->exists()) {
            $dependencyLabels[] = 'sales';
        }

        if (VendorOrderDetail::query()->where('organization_id', $this->orgId())->where('vendor_id', $vendor->id)->exists()) {
            $dependencyLabels[] = 'vendor fulfilment records';
        }

        if (!empty($dependencyLabels)) {
            $vendor->update([
                'is_active' => false,
                'deactivated_at' => now(),
            ]);

            return redirect()
                ->route('vendors.show', $vendor)
                ->with('success', 'Vendor was safely deactivated because it is linked to ' . implode(', ', array_unique($dependencyLabels)) . '.');
        }

        $vendor->delete();

        return redirect()->route('vendors.index')->with('success', 'Vendor deleted successfully.');
    }
}
