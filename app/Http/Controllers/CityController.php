<?php

namespace App\Http\Controllers;

use App\Models\City;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CityController extends Controller
{
    private function orgId(): int
    {
        return (int) auth()->user()->organization_id;
    }

    private function scopedCity(City $city): City
    {
        abort_if($city->organization_id !== $this->orgId(), 403);

        return $city;
    }

    private function validationRules(?City $city = null): array
    {
        $organizationId = $this->orgId();

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('cities', 'name')
                    ->where(fn ($query) => $query->where('organization_id', $organizationId)->where('state', request('state')))
                    ->ignore($city?->id),
            ],
            'state' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:255',
            'is_active' => 'nullable|boolean',
        ];
    }

    public function index(Request $request)
    {
        $search = trim((string) $request->get('search', ''));

        $cities = City::query()
            ->forOrganization($this->orgId())
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($innerQuery) use ($search) {
                    $innerQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('state', 'like', "%{$search}%")
                        ->orWhere('country', 'like', "%{$search}%");
                });
            })
            ->withCount(['users', 'warehouses', 'vendors'])
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('cities.index', compact('cities', 'search'));
    }

    public function create()
    {
        return view('cities.create', ['city' => new City(['is_active' => true])]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->validationRules());

        $city = City::create([
            ...$validated,
            'organization_id' => $this->orgId(),
            'is_active' => (bool) ($validated['is_active'] ?? false),
        ]);

        return redirect()->route('cities.show', $city)->with('success', 'City created successfully.');
    }

    public function show(City $city)
    {
        $city = $this->scopedCity($city);
        $city->load(['users.assignedRole', 'warehouses', 'vendors']);

        return view('cities.show', compact('city'));
    }

    public function edit(City $city)
    {
        $city = $this->scopedCity($city);

        return view('cities.edit', compact('city'));
    }

    public function update(Request $request, City $city)
    {
        $city = $this->scopedCity($city);
        $validated = $request->validate($this->validationRules($city));

        $city->update([
            ...$validated,
            'is_active' => (bool) ($validated['is_active'] ?? false),
        ]);

        return redirect()->route('cities.show', $city)->with('success', 'City updated successfully.');
    }

    public function destroy(City $city)
    {
        $city = $this->scopedCity($city);

        if ($city->users()->exists() || $city->warehouses()->exists() || $city->vendors()->exists()) {
            return redirect()->route('cities.index')->with('error', 'This city is in use and cannot be deleted.');
        }

        $city->delete();

        return redirect()->route('cities.index')->with('success', 'City deleted successfully.');
    }
}
