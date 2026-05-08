<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\City;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WarehouseController extends Controller
{
    private function orgId(): int
    {
        return (int) auth()->user()->organization_id;
    }

    private function scopedWarehouse(Warehouse $warehouse): Warehouse
    {
        abort_if($warehouse->organization_id !== $this->orgId(), 403);

        return $warehouse;
    }

    private function validationRules(?Warehouse $warehouse = null): array
    {
        $organizationId = $this->orgId();

        return [
            'name' => 'required|string|max:255',
            'code' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('warehouses', 'code')
                    ->where(fn ($query) => $query->where('organization_id', $organizationId))
                    ->ignore($warehouse?->id),
            ],
            'address' => 'nullable|string',
            'city_id' => [
                'nullable',
                Rule::exists('cities', 'id')->where(fn ($query) => $query->where('organization_id', $organizationId)),
            ],
            'city' => 'nullable|string|max:255',
            'state' => 'nullable|string|max:255',
            'pincode' => 'nullable|string|max:20',
            'is_active' => 'nullable|boolean',
        ];
    }

    public function index()
    {
        $warehouses = Warehouse::withCount([
            'assets',
            'assets as available_assets_count' => fn ($query) => $query->where('asset_status', 'available'),
            'assets as rented_assets_count' => fn ($query) => $query->where('asset_status', 'rented'),
        ])
            ->with('cityRecord')
            ->where('organization_id', $this->orgId())
            ->orderBy('name')
            ->paginate(12);

        return view('warehouses.index', compact('warehouses'));
    }

    public function create()
    {
        return view('warehouses.create', [
            'warehouse' => new Warehouse(['is_active' => true]),
            'cities' => City::forOrganization($this->orgId())->active()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->validationRules());
        $city = filled($validated['city_id'] ?? null)
            ? City::forOrganization($this->orgId())->find($validated['city_id'])
            : null;

        $warehouse = Warehouse::create([
            ...$validated,
            'organization_id' => $this->orgId(),
            'city' => $city?->name ?? ($validated['city'] ?? null),
            'state' => $city?->state ?? ($validated['state'] ?? null),
            'is_active' => (bool) ($validated['is_active'] ?? false),
        ]);

        return redirect()->route('warehouses.show', $warehouse)->with('success', 'Warehouse created successfully.');
    }

    public function show(Warehouse $warehouse)
    {
        $warehouse = $this->scopedWarehouse($warehouse);
        $warehouse->load('cityRecord')->loadCount([
            'assets',
            'assets as available_assets_count' => fn ($query) => $query->where('asset_status', 'available'),
            'assets as rented_assets_count' => fn ($query) => $query->where('asset_status', 'rented'),
            'assets as maintenance_assets_count' => fn ($query) => $query->where('asset_status', 'maintenance'),
        ]);

        $assets = Asset::with('product')
            ->where('organization_id', $this->orgId())
            ->where('warehouse_id', $warehouse->id)
            ->latest()
            ->paginate(10);

        return view('warehouses.show', compact('warehouse', 'assets'));
    }

    public function edit(Warehouse $warehouse)
    {
        $warehouse = $this->scopedWarehouse($warehouse);

        return view('warehouses.edit', [
            'warehouse' => $warehouse,
            'cities' => City::forOrganization($this->orgId())->active()->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, Warehouse $warehouse)
    {
        $warehouse = $this->scopedWarehouse($warehouse);
        $validated = $request->validate($this->validationRules($warehouse));
        $city = filled($validated['city_id'] ?? null)
            ? City::forOrganization($this->orgId())->find($validated['city_id'])
            : null;

        $warehouse->update([
            ...$validated,
            'city' => $city?->name ?? ($validated['city'] ?? null),
            'state' => $city?->state ?? ($validated['state'] ?? null),
            'is_active' => (bool) ($validated['is_active'] ?? false),
        ]);

        return redirect()->route('warehouses.show', $warehouse)->with('success', 'Warehouse updated successfully.');
    }

    public function destroy(Warehouse $warehouse)
    {
        $warehouse = $this->scopedWarehouse($warehouse);

        if ($warehouse->assets()->exists()) {
            return redirect()->route('warehouses.index')->with('error', 'Move assets out of this warehouse before deleting it.');
        }

        $warehouse->delete();

        return redirect()->route('warehouses.index')->with('success', 'Warehouse deleted successfully.');
    }
}
