<?php

namespace App\Http\Controllers;

use App\Models\ProductCategory;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

class ProductCategoryController extends Controller
{
    private function orgId(): int
    {
        return (int) auth()->user()->organization_id;
    }

    private function scoped(ProductCategory $productCategory): ProductCategory
    {
        abort_if((int) $productCategory->organization_id !== $this->orgId(), 403);

        return $productCategory;
    }

    private function rules(?ProductCategory $productCategory = null): array
    {
        $rules = [
            'name' => [
                'required',
                'string',
                'max:255',
            ],
            'description' => 'nullable|string|max:2000',
            'is_active' => 'nullable|boolean',
        ];

        if (Schema::hasTable('product_categories')) {
            $rules['parent_id'] = [
                'nullable',
                'integer',
                Rule::exists('product_categories', 'id')
                    ->where(fn ($query) => $query->where('organization_id', $this->orgId())),
            ];
        }

        return $rules;
    }

    private function ensureTableReady(): void
    {
        if (! Schema::hasTable('product_categories')) {
            throw ValidationException::withMessages([
                'name' => 'Product category master is not ready. Please run php artisan migrate, then try again.',
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

    private function seedDefaultCategories(): void
    {
        if (! Schema::hasTable('product_categories')) {
            return;
        }

        $organizationId = $this->orgId();

        if (ProductCategory::query()->forOrganization($organizationId)->exists()) {
            return;
        }

        foreach ([
            'Respiratory Care',
            'Sleep Therapy',
            'Mobility Aids',
            'Patient Care',
            'Monitoring Equipment',
            'ICU Equipment',
            'Rehabilitation',
            'Consumables',
            'Accessories',
            'Furniture',
        ] as $name) {
            ProductCategory::query()->create([
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

    private function ensureUniqueName(string $name, ?ProductCategory $productCategory = null): void
    {
        $normalizedName = Str::lower($this->normalizeName($name));
        $exists = ProductCategory::query()
            ->forOrganization($this->orgId())
            ->where('normalized_name', $normalizedName)
            ->when($productCategory, fn ($query) => $query->where('id', '!=', $productCategory->id))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => 'This category already exists. Please select it from the list.',
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

        if (Schema::hasColumn('product_categories', 'parent_id')) {
            $payload['parent_id'] = $validated['parent_id'] ?? null;
        }

        if (Schema::hasColumn('product_categories', 'description')) {
            $payload['description'] = $validated['description'] ?? null;
        }

        return $payload;
    }

    public function index(Request $request)
    {
        $search = trim((string) $request->get('search', ''));

        if (! Schema::hasTable('product_categories')) {
            $productCategories = $this->emptyPaginator($request);

            return view('product-categories.index', compact('productCategories', 'search'));
        }

        $this->seedDefaultCategories();

        $productCategories = ProductCategory::query()
            ->forOrganization($this->orgId())
            ->when($search !== '', fn ($query) => $query->where('name', 'like', "%{$search}%"))
            ->withCount('products')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('product-categories.index', compact('productCategories', 'search'));
    }

    public function create()
    {
        $this->seedDefaultCategories();

        return view('product-categories.create', [
            'productCategory' => new ProductCategory(['is_active' => true]),
            'parentCategories' => Schema::hasTable('product_categories')
                ? ProductCategory::query()
                    ->forOrganization($this->orgId())
                    ->active()
                    ->orderBy('name')
                    ->get(['id', 'name'])
                : collect(),
        ]);
    }

    public function store(Request $request)
    {
        $this->ensureTableReady();
        $validated = $request->validate($this->rules());
        $this->ensureUniqueName($validated['name']);

        $productCategory = ProductCategory::create($this->masterPayload($validated));

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Category added.',
                'category' => [
                    'id' => $productCategory->id,
                    'name' => $productCategory->name,
                ],
            ], 201);
        }

        return redirect()->route('product-categories.index')->with('success', 'Product category added.');
    }

    public function edit(ProductCategory $productCategory)
    {
        return view('product-categories.edit', [
            'productCategory' => $this->scoped($productCategory),
            'parentCategories' => ProductCategory::query()
                ->forOrganization($this->orgId())
                ->active()
                ->whereKeyNot($productCategory->id)
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, ProductCategory $productCategory)
    {
        $productCategory = $this->scoped($productCategory);
        $validated = $request->validate($this->rules($productCategory));
        $this->ensureUniqueName($validated['name'], $productCategory);

        $payload = $this->masterPayload($validated);
        unset($payload['organization_id']);

        $productCategory->update($payload);

        $productCategory->products()->update(['category' => $productCategory->name]);

        return redirect()->route('product-categories.index')->with('success', 'Product category updated.');
    }

    public function destroy(ProductCategory $productCategory)
    {
        $productCategory = $this->scoped($productCategory);
        $productCategory->update(['is_active' => false]);

        return redirect()->route('product-categories.index')->with('success', 'Product category deactivated.');
    }
}
