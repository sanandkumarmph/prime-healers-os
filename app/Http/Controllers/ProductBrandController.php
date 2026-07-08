<?php

namespace App\Http\Controllers;

use App\Models\ProductBrand;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

class ProductBrandController extends Controller
{
    private function orgId(): int
    {
        return (int) auth()->user()->organization_id;
    }

    private function scoped(ProductBrand $productBrand): ProductBrand
    {
        abort_if((int) $productBrand->organization_id !== $this->orgId(), 403);

        return $productBrand;
    }

    private function rules(?ProductBrand $productBrand = null): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
            ],
            'manufacturer' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:2000',
            'is_active' => 'nullable|boolean',
        ];
    }

    private function ensureTableReady(): void
    {
        if (! Schema::hasTable('product_brands')) {
            throw ValidationException::withMessages([
                'name' => 'Product brand master is not ready. Please run php artisan migrate, then try again.',
            ]);
        }
    }

    private function emptyPaginator(Request $request): LengthAwarePaginator
    {
        return new LengthAwarePaginator(
            [],
            0,
            20,
            LengthAwarePaginator::resolveCurrentPage(),
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ]
        );
    }

    private function seedDefaultBrands(): void
    {
        if (! Schema::hasTable('product_brands')) {
            return;
        }

        $organizationId = $this->orgId();

        if (ProductBrand::query()->forOrganization($organizationId)->exists()) {
            return;
        }

        foreach ([
            'Philips',
            'ResMed',
            'BMC',
            'Yuwell',
            'Omron',
            'Dr Trust',
            'KareMed',
            'Prime Healers',
            'Generic',
        ] as $name) {
            ProductBrand::query()->create([
                'organization_id' => $organizationId,
                'name' => $name,
                'is_active' => true,
            ]);
        }
    }

    private function normalizeName(string $name): string
    {
        return preg_replace('/\s+/', ' ', trim($name)) ?: '';
    }

    private function ensureUniqueName(string $name, ?ProductBrand $productBrand = null): void
    {
        $normalizedName = Str::lower($this->normalizeName($name));
        $exists = ProductBrand::query()
            ->forOrganization($this->orgId())
            ->where('normalized_name', $normalizedName)
            ->when($productBrand, fn ($query) => $query->where('id', '!=', $productBrand->id))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => 'This brand already exists. Please select it from the list.',
            ]);
        }
    }

    private function masterPayload(array $validated): array
    {
        $payload = [
            'name' => $this->normalizeName($validated['name']),
            'organization_id' => $this->orgId(),
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ];

        if (Schema::hasColumn('product_brands', 'manufacturer')) {
            $payload['manufacturer'] = $validated['manufacturer'] ?? null;
        }

        if (Schema::hasColumn('product_brands', 'description')) {
            $payload['description'] = $validated['description'] ?? null;
        }

        return $payload;
    }

    public function index(Request $request)
    {
        $search = trim((string) $request->get('search', ''));

        if (! Schema::hasTable('product_brands')) {
            $productBrands = $this->emptyPaginator($request);

            return view('product-brands.index', compact('productBrands', 'search'));
        }

        $this->seedDefaultBrands();

        $productBrands = ProductBrand::query()
            ->forOrganization($this->orgId())
            ->when($search !== '', fn ($query) => $query->where('name', 'like', "%{$search}%"))
            ->withCount('products')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('product-brands.index', compact('productBrands', 'search'));
    }

    public function create()
    {
        $this->seedDefaultBrands();

        return view('product-brands.create', [
            'productBrand' => new ProductBrand(['is_active' => true]),
        ]);
    }

    public function store(Request $request)
    {
        $this->ensureTableReady();
        $validated = $request->validate($this->rules());
        $this->ensureUniqueName($validated['name']);

        $productBrand = ProductBrand::create($this->masterPayload($validated));

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Brand added.',
                'brand' => [
                    'id' => $productBrand->id,
                    'name' => $productBrand->name,
                ],
            ], 201);
        }

        return redirect()->route('product-brands.index')->with('success', 'Product brand added.');
    }

    public function edit(ProductBrand $productBrand)
    {
        return view('product-brands.edit', [
            'productBrand' => $this->scoped($productBrand),
        ]);
    }

    public function update(Request $request, ProductBrand $productBrand)
    {
        $productBrand = $this->scoped($productBrand);
        $validated = $request->validate($this->rules($productBrand));
        $this->ensureUniqueName($validated['name'], $productBrand);

        $payload = $this->masterPayload($validated);
        unset($payload['organization_id']);

        $productBrand->update($payload);

        $productBrand->products()->update(['brand' => $productBrand->name]);

        return redirect()->route('product-brands.index')->with('success', 'Product brand updated.');
    }

    public function destroy(ProductBrand $productBrand)
    {
        $productBrand = $this->scoped($productBrand);
        $productBrand->update(['is_active' => false]);

        return redirect()->route('product-brands.index')->with('success', 'Product brand deactivated.');
    }
}
