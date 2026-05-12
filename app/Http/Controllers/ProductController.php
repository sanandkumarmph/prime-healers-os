<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\InventoryConversion;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\RentalItem;
use App\Models\Sale;
use App\Models\SaleInventory;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    private function productCatalogQuery()
    {
        return Product::query()
            ->withCount([
                'assets as assets_count' => fn ($query) => $query->where('asset_stage', Asset::STAGE_RENTAL_STOCK),
                'assets as available_assets_count' => fn ($query) => $query->where('asset_stage', Asset::STAGE_RENTAL_STOCK)->where('asset_status', 'available'),
                'assets as rented_assets_count' => fn ($query) => $query->where('asset_stage', Asset::STAGE_RENTAL_STOCK)->where('asset_status', 'rented'),
                'assets as maintenance_assets_count' => fn ($query) => $query->where('asset_stage', Asset::STAGE_RENTAL_STOCK)->where('asset_status', 'maintenance'),
                'saleUnits as sale_stock_quantity' => fn ($query) => $query->where('asset_status', 'available_for_sale'),
                'saleUnits as sold_units_count' => fn ($query) => $query->where('asset_status', 'sold'),
                'saleUnits as converted_sale_units_count' => fn ($query) => $query->where('asset_status', 'converted_to_rental'),
            ])
            ->with([
                'assets' => fn ($query) => $query
                    ->select(['id', 'product_id', 'warehouse_id', 'asset_status', 'asset_stage'])
                    ->where('asset_stage', Asset::STAGE_RENTAL_STOCK)
                    ->with('warehouse:id,name'),
                'saleUnits' => fn ($query) => $query
                    ->select(['id', 'product_id', 'warehouse_id', 'asset_status', 'asset_stage'])
                    ->where('asset_stage', Asset::STAGE_NEW_STOCK)
                    ->whereIn('asset_status', ['available_for_sale', 'sold', 'converted_to_rental'])
                    ->with('warehouse:id,name'),
            ])
            ->where('organization_id', $this->orgId());
    }

    private function applyProductCatalogFilters($query, string $search, string $category, string $typeFilter, string $stockStatus, string $brand)
    {
        return $query
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($innerQuery) use ($search) {
                    $like = '%' . $search . '%';

                    $innerQuery->where('name', 'like', $like)
                        ->orWhere('brand', 'like', $like)
                        ->orWhere('model_name', 'like', $like)
                        ->orWhere('sku', 'like', $like)
                        ->orWhere('product_code', 'like', $like);
                });
            })
            ->when($category !== '', fn ($query) => $query->where('category', $category))
            ->when($brand !== '', fn ($query) => $query->where('brand', $brand))
            ->when($typeFilter !== '', function ($query) use ($typeFilter) {
                match ($typeFilter) {
                    'rentable' => $query->where('product_type', Product::TYPE_RENTABLE),
                    'sale_only' => $query
                        ->where('product_type', Product::TYPE_SELLABLE)
                        ->where('stock_mode', '!=', Product::STOCK_MODE_TRACKED_BOTH),
                    'both' => $query->where('stock_mode', Product::STOCK_MODE_TRACKED_BOTH),
                    'untracked' => $query->where('stock_mode', Product::STOCK_MODE_UNTRACKED),
                    default => null,
                };
            })
            ->when($stockStatus !== '', function ($query) use ($stockStatus) {
                match ($stockStatus) {
                    'available_to_rent' => $query->whereHas('assets', fn ($assetQuery) => $assetQuery
                        ->where('asset_stage', Asset::STAGE_RENTAL_STOCK)
                        ->where('asset_status', Asset::STATUS_AVAILABLE)),
                    'rented_out' => $query->whereHas('assets', fn ($assetQuery) => $assetQuery
                        ->where('asset_stage', Asset::STAGE_RENTAL_STOCK)
                        ->where('asset_status', Asset::STATUS_RENTED)),
                    'maintenance' => $query->whereHas('assets', fn ($assetQuery) => $assetQuery
                        ->where('asset_stage', Asset::STAGE_RENTAL_STOCK)
                        ->where('asset_status', Asset::STATUS_MAINTENANCE)),
                    'out_of_stock' => $query
                        ->whereDoesntHave('assets', fn ($assetQuery) => $assetQuery
                            ->where('asset_stage', Asset::STAGE_RENTAL_STOCK)
                            ->where('asset_status', Asset::STATUS_AVAILABLE))
                        ->whereDoesntHave('saleUnits', fn ($assetQuery) => $assetQuery
                            ->where('asset_status', Asset::STATUS_AVAILABLE_FOR_SALE))
                        ->where(function ($innerQuery) {
                            $innerQuery->where('stock_mode', '!=', Product::STOCK_MODE_UNTRACKED)
                                ->orWhere('available_quantity', '<=', 0);
                        }),
                    default => null,
                };
            });
    }

    private function orgId(): int
    {
        return (int) auth()->user()->organization_id;
    }

    private function scopedProduct(Product $product): Product
    {
        abort_if($product->organization_id !== $this->orgId(), 403);

        return $product;
    }

    private function productValidationRules(?Product $product = null): array
    {
        $organizationId = $this->orgId();

        return [
            'name' => 'required|string|max:255',
            'category' => 'nullable|string|max:255',
            'brand' => 'nullable|string|max:255',
            'model_name' => 'nullable|string|max:255',
            'product_code' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('products', 'product_code')
                    ->where(fn ($query) => $query->where('organization_id', $organizationId))
                    ->ignore($product?->id),
            ],
            'sku' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('products', 'sku')
                    ->where(fn ($query) => $query->where('organization_id', $organizationId))
                    ->ignore($product?->id),
            ],
            'product_type' => ['required', Rule::in(Product::PRODUCT_TYPES)],
            'price_per_day' => 'nullable|numeric|min:0',
            'rental_price_15_days' => 'nullable|numeric|min:0',
            'rental_price_30_days' => 'nullable|numeric|min:0',
            'rental_price_3_months' => 'nullable|numeric|min:0',
            'sale_price' => 'nullable|numeric|min:0',
            'rental_price' => 'nullable|numeric|min:0',
            'quantity' => 'nullable|integer|min:0',
            'gst_tax_type' => ['nullable', Rule::in(Product::GST_TAX_TYPES)],
            'gst_calculation_mode' => ['nullable', Rule::in(Product::GST_CALCULATION_MODES)],
            'cgst_rate' => 'nullable|numeric|min:0|max:100',
            'sgst_rate' => 'nullable|numeric|min:0|max:100',
            'igst_rate' => 'nullable|numeric|min:0|max:100',
        ];
    }

    private function validateProductModePayload(array $validated, ?Product $product = null): array
    {
        $productType = (string) ($validated['product_type'] ?? Product::TYPE_SELLABLE);
        $saleStockCount = $product ? (int) $product->saleUnits()->where('asset_status', 'available_for_sale')->count() : 0;
        $reservedSaleStockCount = $product ? (int) $product->saleUnits()->where('asset_status', 'reserved_for_sale')->count() : 0;
        $saleUnitCount = $product ? (int) $product->saleUnits()->count() : 0;
        $rentalAssetCount = $product
            ? (int) Asset::query()
                ->where('organization_id', $this->orgId())
                ->where('product_id', $product->id)
                ->where('asset_stage', Asset::STAGE_RENTAL_STOCK)
                ->count()
            : 0;
        $currentType = $product?->product_type ?: ($product?->isRentableProduct() ? Product::TYPE_RENTABLE : Product::TYPE_SELLABLE);
        $manualQuantity = max((int) ($validated['quantity'] ?? ($product?->total_quantity ?? 0)), 0);
        $trackedStockExists = ($saleUnitCount + $rentalAssetCount) > 0 || $product?->hasTrackedStock();
        $validated['gst_tax_type'] = in_array(($validated['gst_tax_type'] ?? null), Product::GST_TAX_TYPES, true)
            ? $validated['gst_tax_type']
            : null;
        $validated['gst_calculation_mode'] = ($validated['gst_calculation_mode'] ?? 'exclusive') === 'inclusive'
            ? 'inclusive'
            : 'exclusive';
        $validated['cgst_rate'] = (float) ($validated['cgst_rate'] ?? 0);
        $validated['sgst_rate'] = (float) ($validated['sgst_rate'] ?? 0);
        $validated['igst_rate'] = (float) ($validated['igst_rate'] ?? 0);

        if ($validated['gst_tax_type'] === Product::GST_TAX_TYPE_CGST_SGST) {
            $validated['igst_rate'] = 0;
        } elseif ($validated['gst_tax_type'] === Product::GST_TAX_TYPE_IGST) {
            $validated['cgst_rate'] = 0;
            $validated['sgst_rate'] = 0;
        } else {
            $validated['cgst_rate'] = 0;
            $validated['sgst_rate'] = 0;
            $validated['igst_rate'] = 0;
            $validated['gst_calculation_mode'] = 'exclusive';
        }

        if ($productType === Product::TYPE_SELLABLE) {
            $validated['price_per_day'] = (float) ($validated['price_per_day'] ?? 0);

            if ($product && $currentType !== Product::TYPE_SELLABLE && $rentalAssetCount > 0) {
                throw ValidationException::withMessages([
                    'product_type' => 'Convert rental assets to sellable stock before switching this product to Sellable.',
                ]);
            }
        }

        if ($productType === Product::TYPE_RENTABLE) {
            if (filled($validated['price_per_day'] ?? null) === false) {
                throw ValidationException::withMessages([
                    'price_per_day' => 'Price per day is required for rentable products.',
                ]);
            }

            if ($product && $currentType !== Product::TYPE_RENTABLE && $saleStockCount > 0) {
                throw ValidationException::withMessages([
                    'product_type' => 'Convert sellable stock to rental assets before switching this product to Rentable.',
                ]);
            }
        }

        if (!$trackedStockExists) {
            $validated['total_quantity'] = $manualQuantity;
            $validated['available_quantity'] = $manualQuantity;
            $validated['stock_mode'] = Product::STOCK_MODE_UNTRACKED;
        } else {
            $validated['total_quantity'] = (int) ($product?->total_quantity ?? 0);
            $validated['available_quantity'] = (int) ($product?->available_quantity ?? 0);
            $validated['stock_mode'] = Product::resolveStockModeFromInventoryCounts(
                $saleUnitCount,
                $rentalAssetCount,
                $product?->stock_mode
            );
        }

        return $validated;
    }

    private function resolvedRentalPrice(array $validated): ?float
    {
        return $validated['rental_price_30_days']
            ?? $validated['rental_price']
            ?? ($validated['price_per_day'] ?? null);
    }

    private function activeWarehouses()
    {
        return Warehouse::where('organization_id', $this->orgId())
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    private function duplicateProductNameGroups()
    {
        $products = Product::query()
            ->where('organization_id', $this->orgId())
            ->get(['id', 'name', 'brand', 'model_name', 'product_code', 'sku']);

        return $products
            ->groupBy(fn (Product $product) => Str::lower(trim((string) $product->name)))
            ->filter(fn ($group, $normalizedName) => $normalizedName !== '' && $group->count() > 1)
            ->map(fn ($group) => $group->values());
    }

    private function validateUniqueProductIdentity(array $validated, ?Product $product = null): void
    {
        $name = trim((string) ($validated['name'] ?? ''));
        $brand = trim((string) ($validated['brand'] ?? ''));
        $modelName = trim((string) ($validated['model_name'] ?? ''));

        if ($name === '') {
            return;
        }

        $existing = Product::query()
            ->where('organization_id', $this->orgId())
            ->whereRaw('LOWER(name) = ?', [Str::lower($name)])
            ->whereRaw('LOWER(COALESCE(brand, \'\')) = ?', [Str::lower($brand)])
            ->whereRaw('LOWER(COALESCE(model_name, \'\')) = ?', [Str::lower($modelName)])
            ->when($product, fn ($query) => $query->where('id', '!=', $product->id))
            ->exists();

        if ($existing) {
            throw ValidationException::withMessages([
                'name' => 'A product with the same Name, Brand, and Model already exists.',
            ]);
        }
    }

    private function rebuildProductStock(Product $product): void
    {
        $product->refresh();
        $product->syncLegacyStockFields();

        $this->syncProductTypeFromInventory($product);
    }

    private function syncProductTypeFromInventory(Product $product, ?string $preferredType = null): void
    {
        $saleStock = (int) $product->saleUnits()->where('asset_status', 'available_for_sale')->count();
        $rentalAssets = (int) Asset::query()
            ->where('organization_id', $this->orgId())
            ->where('product_id', $product->id)
            ->where('asset_stage', Asset::STAGE_RENTAL_STOCK)
            ->count();

        $resolvedType = match (true) {
            $saleStock > 0 && $rentalAssets === 0 => Product::TYPE_SELLABLE,
            $rentalAssets > 0 && $saleStock === 0 => Product::TYPE_RENTABLE,
            default => $preferredType ?: ($product->product_type ?: Product::TYPE_SELLABLE),
        };

        $product->forceFill([
            'product_type' => $resolvedType,
            'is_sellable' => $resolvedType === Product::TYPE_SELLABLE,
            'is_rentable' => $resolvedType === Product::TYPE_RENTABLE,
        ])->saveQuietly();
    }

    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $category = trim((string) $request->query('category', ''));
        $typeFilter = trim((string) $request->query('type', ''));
        $stockStatus = trim((string) $request->query('stock_status', ''));
        $brand = trim((string) $request->query('brand', ''));
        $allowedSorts = [
            'name' => 'products.name',
            'category' => 'products.category',
            'brand' => 'products.brand',
            'model' => 'products.model_name',
            'available_units' => 'available_assets_count',
            'rented_units' => 'rented_assets_count',
            'sale_price' => 'products.sale_price',
            'daily_rent' => 'products.price_per_day',
            'created_at' => 'products.created_at',
        ];
        $sort = (string) $request->query('sort', 'created_at');

        if (!array_key_exists($sort, $allowedSorts)) {
            $sort = 'created_at';
        }

        $defaultDirection = $sort === 'created_at' ? 'desc' : 'asc';
        $direction = strtolower((string) $request->query('direction', $defaultDirection));

        if (!in_array($direction, ['asc', 'desc'], true)) {
            $direction = $defaultDirection;
        }

        $duplicateProductNameGroups = $this->duplicateProductNameGroups();
        $filteredProductsQuery = $this->applyProductCatalogFilters(
            $this->productCatalogQuery(),
            $search,
            $category,
            $typeFilter,
            $stockStatus,
            $brand
        );

        $catalogProducts = (clone $filteredProductsQuery)->get();
        $catalogTotals = [
            'products' => $catalogProducts->count(),
            'sellable' => $catalogProducts->sum(function (Product $product): int {
                return (
                    $product->product_type === Product::TYPE_SELLABLE
                    || in_array($product->stock_mode, [Product::STOCK_MODE_TRACKED_SALE, Product::STOCK_MODE_TRACKED_BOTH], true)
                ) ? 1 : 0;
            }),
            'rentable' => $catalogProducts->sum(function (Product $product): int {
                return (
                    $product->product_type === Product::TYPE_RENTABLE
                    || in_array($product->stock_mode, [Product::STOCK_MODE_TRACKED_RENTAL, Product::STOCK_MODE_TRACKED_BOTH], true)
                ) ? 1 : 0;
            }),
            'sale_stock' => $catalogProducts->sum(function (Product $product): int {
                if ($product->usesUntrackedStock()) {
                    return $product->product_type === Product::TYPE_SELLABLE
                        ? max((int) ($product->available_quantity ?? 0), 0)
                        : 0;
                }

                return (int) ($product->sale_stock_quantity ?? 0);
            }),
            'rental_assets' => $catalogProducts->sum(function (Product $product): int {
                if ($product->usesUntrackedStock()) {
                    return $product->product_type === Product::TYPE_RENTABLE
                        ? max((int) ($product->total_quantity ?? 0), 0)
                        : 0;
                }

                return (int) ($product->assets_count ?? 0);
            }),
            'rental_available' => $catalogProducts->sum(function (Product $product): int {
                if ($product->usesUntrackedStock()) {
                    return $product->product_type === Product::TYPE_RENTABLE
                        ? max((int) ($product->available_quantity ?? 0), 0)
                        : 0;
                }

                return (int) ($product->available_assets_count ?? 0);
            }),
        ];

        $products = (clone $filteredProductsQuery)
            ->orderBy($allowedSorts[$sort], $direction)
            ->orderBy('products.id', $direction === 'asc' ? 'asc' : 'desc')
            ->paginate(12)
            ->withQueryString();

        $categoryOptions = Product::query()
            ->where('organization_id', $this->orgId())
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->orderBy('category')
            ->pluck('category');

        $brandOptions = Product::query()
            ->where('organization_id', $this->orgId())
            ->whereNotNull('brand')
            ->where('brand', '!=', '')
            ->distinct()
            ->orderBy('brand')
            ->pluck('brand');

        return view('products.index', compact(
            'products',
            'catalogTotals',
            'duplicateProductNameGroups',
            'search',
            'category',
            'typeFilter',
            'stockStatus',
            'brand',
            'sort',
            'direction',
            'categoryOptions',
            'brandOptions'
        ));
    }

    public function exportCsv(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $category = trim((string) $request->query('category', ''));
        $typeFilter = trim((string) $request->query('type', ''));
        $stockStatus = trim((string) $request->query('stock_status', ''));
        $brand = trim((string) $request->query('brand', ''));
        $allowedSorts = [
            'name' => 'products.name',
            'category' => 'products.category',
            'brand' => 'products.brand',
            'model' => 'products.model_name',
            'available_units' => 'available_assets_count',
            'rented_units' => 'rented_assets_count',
            'sale_price' => 'products.sale_price',
            'daily_rent' => 'products.price_per_day',
            'created_at' => 'products.created_at',
        ];
        $sort = (string) $request->query('sort', 'created_at');

        if (!array_key_exists($sort, $allowedSorts)) {
            $sort = 'created_at';
        }

        $defaultDirection = $sort === 'created_at' ? 'desc' : 'asc';
        $direction = strtolower((string) $request->query('direction', $defaultDirection));

        if (!in_array($direction, ['asc', 'desc'], true)) {
            $direction = $defaultDirection;
        }

        $products = $this->applyProductCatalogFilters(
            $this->productCatalogQuery(),
            $search,
            $category,
            $typeFilter,
            $stockStatus,
            $brand
        )
            ->orderBy($allowedSorts[$sort], $direction)
            ->orderBy('products.id', $direction === 'asc' ? 'asc' : 'desc')
            ->get();

        return response()->streamDownload(function () use ($products) {
            $output = fopen('php://output', 'w');

            fputcsv($output, [
                '#',
                'Product',
                'Category',
                'Brand',
                'Model',
                'SKU',
                'Product Code',
                'Type',
                'Stock Mode',
                'Available Rental Units',
                'Rented Units',
                'Maintenance Units',
                'Available Sale Units',
                'Sale Price',
                'Daily Rental Price',
                'Created Date',
            ]);

            foreach ($products as $index => $product) {
                fputcsv($output, [
                    $index + 1,
                    $product->name,
                    $product->category,
                    $product->brand,
                    $product->model_name,
                    $product->sku,
                    $product->product_code,
                    $product->product_type,
                    $product->stockModeLabel(),
                    (int) ($product->available_assets_count ?? 0),
                    (int) ($product->rented_assets_count ?? 0),
                    (int) ($product->maintenance_assets_count ?? 0),
                    (int) ($product->sale_stock_quantity ?? 0),
                    $product->sale_price,
                    $product->price_per_day,
                    optional($product->created_at)->format('Y-m-d H:i:s'),
                ]);
            }

            fclose($output);
        }, 'product-master-' . now()->format('Ymd-His') . '.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function create()
    {
        return view('products.create', ['product' => new Product([
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
        ])]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateProductModePayload(
            $request->validate($this->productValidationRules())
        );
        $this->validateUniqueProductIdentity($validated);

        $productType = (string) $validated['product_type'];

        $product = Product::create([
            ...$validated,
            'organization_id' => $this->orgId(),
            'product_type' => $productType,
            'is_sellable' => $productType === Product::TYPE_SELLABLE,
            'is_rentable' => $productType === Product::TYPE_RENTABLE,
            'sale_price' => $validated['sale_price'] ?? null,
            'rental_price' => $this->resolvedRentalPrice($validated),
        ]);

        $this->rebuildProductStock($product);

        return redirect()->route('products.show', $product)->with('success', 'Product added successfully.');
    }

    public function show(Product $product)
    {
        $product = $this->scopedProduct($product);
        $this->rebuildProductStock($product);
        $product->load([
            'saleInventories.warehouse',
            'saleUnits.warehouse',
            'inventoryConversions' => fn ($query) => $query->with(['warehouse', 'convertedBy'])->latest()->limit(10),
        ]);

        $rentalAssetQuery = Asset::query()
            ->where('organization_id', $this->orgId())
            ->where('product_id', $product->id)
            ->where('asset_stage', Asset::STAGE_RENTAL_STOCK);

        $assetStats = [
            'total_assets' => (clone $rentalAssetQuery)->count(),
            'available_assets' => (clone $rentalAssetQuery)->where('asset_status', 'available')->count(),
            'rented_assets' => (clone $rentalAssetQuery)->where('asset_status', 'rented')->count(),
            'maintenance_assets' => (clone $rentalAssetQuery)->where('asset_status', 'maintenance')->count(),
            'reserved_assets' => (clone $rentalAssetQuery)->where('asset_status', 'reserved')->count(),
        ];

        $assets = $product->assets()
            ->with('warehouse')
            ->where('organization_id', $this->orgId())
            ->where('asset_stage', Asset::STAGE_RENTAL_STOCK)
            ->latest()
            ->paginate(10);

        $saleInventorySummary = [
            'total_new_stock' => (int) $product->saleUnits->count(),
            'reserved_new_stock' => 0,
            'available_new_stock' => (int) $product->saleUnits->where('asset_status', 'available_for_sale')->count(),
            'sold_new_stock' => (int) $product->saleUnits->where('asset_status', 'sold')->count(),
            'converted_new_stock' => (int) $product->saleUnits->where('asset_status', 'converted_to_rental')->count(),
        ];
        $convertibleRentalAssets = Asset::query()
            ->where('organization_id', $this->orgId())
            ->where('product_id', $product->id)
            ->where('asset_stage', Asset::STAGE_RENTAL_STOCK)
            ->where('asset_status', 'available')
            ->with('warehouse')
            ->orderBy('serial_number')
            ->get();

        return view('products.show', compact('product', 'assetStats', 'assets', 'saleInventorySummary', 'convertibleRentalAssets'));
    }

    public function edit(Product $product)
    {
        $product = $this->scopedProduct($product);

        return view('products.edit', compact('product'));
    }

    public function update(Request $request, Product $product)
    {
        $product = $this->scopedProduct($product);

        $validated = $this->validateProductModePayload(
            $request->validate($this->productValidationRules($product)),
            $product
        );
        $this->validateUniqueProductIdentity($validated, $product);
        $productType = (string) $validated['product_type'];

        $product->update([
            ...$validated,
            'product_type' => $productType,
            'is_sellable' => $productType === Product::TYPE_SELLABLE,
            'is_rentable' => $productType === Product::TYPE_RENTABLE,
            'sale_price' => $validated['sale_price'] ?? null,
            'rental_price' => $this->resolvedRentalPrice($validated),
        ]);

        $this->rebuildProductStock($product);

        return redirect()->route('products.show', $product)->with('success', 'Product updated successfully.');
    }

    public function convertToRental(Request $request, Product $product)
    {
        $product = $this->scopedProduct($product);

        $validated = $request->validate([
            'warehouse_id' => [
                'required',
                Rule::exists('warehouses', 'id')->where(fn ($query) => $query->where('organization_id', $this->orgId())),
            ],
            'quantity' => 'required|integer|min:1',
            'remarks' => 'nullable|string|max:1000',
            'serial_numbers' => 'nullable|array',
            'serial_numbers.*' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('assets', 'serial_number')->where(fn ($query) => $query->where('organization_id', $this->orgId())),
            ],
            'barcode_values' => 'nullable|array',
            'barcode_values.*' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('assets', 'barcode_value')->where(fn ($query) => $query->where('organization_id', $this->orgId())),
            ],
            'purchase_cost' => 'nullable|numeric|min:0',
        ]);

        $warehouseId = (int) $validated['warehouse_id'];
        $quantity = (int) $validated['quantity'];

        DB::transaction(function () use ($product, $warehouseId, $quantity, $validated) {
            $convertibleAssets = Asset::query()
                ->where('organization_id', $this->orgId())
                ->where('product_id', $product->id)
                ->where('warehouse_id', $warehouseId)
                ->where('asset_stage', Asset::STAGE_NEW_STOCK)
                ->where('asset_status', 'available_for_sale')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $availableToConvert = $convertibleAssets->count();

            abort_if($availableToConvert < $quantity, 422, 'Conversion quantity exceeds available sale stock.');

            $serialNumbers = collect($validated['serial_numbers'] ?? [])
                ->map(fn ($value) => trim((string) $value))
                ->filter()
                ->values();

            $barcodeValues = collect($validated['barcode_values'] ?? [])
                ->map(fn ($value) => trim((string) $value))
                ->values();

            $reusableNewStockAssets = $convertibleAssets->take($quantity);

            $convertedCount = 0;

            foreach ($reusableNewStockAssets as $asset) {
                $asset->update([
                    'asset_stage' => Asset::STAGE_RENTAL_STOCK,
                    'asset_status' => 'available',
                    'condition_status' => 'good',
                    'notes' => trim(($asset->notes ? $asset->notes . "\n" : '') . ($validated['remarks'] ?? 'Converted from sale inventory to rental asset.')),
                ]);
                $convertedCount++;
            }

            for ($index = $convertedCount; $index < $quantity; $index++) {
                $serialNumber = $serialNumbers[$index] ?? $product->nextSaleUnitCode($product->nextAvailableSaleUnitSequence() + $index) . '-R';
                $barcodeValue = $barcodeValues[$index] ?? null;

                Asset::create([
                    'organization_id' => $this->orgId(),
                    'product_id' => $product->id,
                    'warehouse_id' => $warehouseId,
                    'asset_name' => $product->name,
                    'serial_number' => $serialNumber,
                    'barcode_value' => filled($barcodeValue) ? $barcodeValue : null,
                    'asset_stage' => Asset::STAGE_RENTAL_STOCK,
                    'purchase_cost' => $validated['purchase_cost'] ?? $inventory->purchase_cost,
                    'condition_status' => 'good',
                    'asset_status' => 'available',
                    'notes' => $validated['remarks'] ?? 'Converted from sale inventory to rental asset.',
                ]);
            }

            InventoryConversion::create([
                'organization_id' => $this->orgId(),
                'product_id' => $product->id,
                'warehouse_id' => $warehouseId,
                'conversion_type' => InventoryConversion::TYPE_SALE_TO_RENTAL,
                'quantity_converted' => $quantity,
                'sale_stock_before' => $availableToConvert,
                'sale_stock_after' => max($availableToConvert - $quantity, 0),
                'remarks' => $validated['remarks'] ?? null,
                'converted_by' => auth()->id(),
            ]);

            $this->rebuildProductStock($product);
            $this->syncProductTypeFromInventory($product, Product::TYPE_RENTABLE);
        });

        return redirect()->route('products.show', $product)->with('success', 'Sale stock converted to rental assets successfully.');
    }

    public function convertToSellable(Request $request, Product $product)
    {
        $product = $this->scopedProduct($product);

        $validated = $request->validate([
            'asset_ids' => 'required|array|min:1',
            'asset_ids.*' => 'integer',
            'remarks' => 'nullable|string|max:1000',
        ]);

        DB::transaction(function () use ($product, $validated) {
            $assets = Asset::query()
                ->where('organization_id', $this->orgId())
                ->where('product_id', $product->id)
                ->where('asset_stage', Asset::STAGE_RENTAL_STOCK)
                ->where('asset_status', 'available')
                ->whereIn('id', $validated['asset_ids'])
                ->with('warehouse')
                ->lockForUpdate()
                ->get();

            if ($assets->count() !== count($validated['asset_ids'])) {
                throw ValidationException::withMessages([
                    'asset_ids' => 'Only available rental assets can be converted to sellable stock.',
                ]);
            }

            $groupedAssets = $assets->groupBy('warehouse_id');

            foreach ($groupedAssets as $warehouseId => $warehouseAssets) {
                $saleStockBefore = (int) Asset::query()
                    ->where('organization_id', $this->orgId())
                    ->where('product_id', $product->id)
                    ->where('warehouse_id', $warehouseId)
                    ->where('asset_stage', Asset::STAGE_NEW_STOCK)
                    ->where('asset_status', 'available_for_sale')
                    ->count();
                $quantity = $warehouseAssets->count();

                foreach ($warehouseAssets as $asset) {
                    $asset->update([
                        'asset_stage' => Asset::STAGE_NEW_STOCK,
                        'asset_status' => 'available_for_sale',
                        'notes' => trim(($asset->notes ? $asset->notes . "\n" : '') . ($validated['remarks'] ?? 'Converted from rental asset to sellable stock.')),
                    ]);
                }

                InventoryConversion::create([
                    'organization_id' => $this->orgId(),
                    'product_id' => $product->id,
                    'warehouse_id' => (int) $warehouseId,
                    'conversion_type' => InventoryConversion::TYPE_RENTAL_TO_SALE,
                    'quantity_converted' => $quantity,
                    'sale_stock_before' => $saleStockBefore,
                    'sale_stock_after' => $saleStockBefore + $quantity,
                    'remarks' => $validated['remarks'] ?? null,
                    'converted_by' => auth()->id(),
                ]);
            }

            $this->rebuildProductStock($product);
            $this->syncProductTypeFromInventory($product, Product::TYPE_SELLABLE);
        });

        return redirect()->route('products.show', $product)->with('success', 'Rental assets converted to sellable stock successfully.');
    }

    public function destroy(Product $product)
    {
        $product = $this->scopedProduct($product);

        $dependencyLabels = [];

        if ($product->assets()->exists()) {
            $dependencyLabels[] = 'assets';
        }

        if ($product->rentals()->exists() || RentalItem::where('organization_id', $this->orgId())->where('product_id', $product->id)->exists()) {
            $dependencyLabels[] = 'rentals';
        }

        if (Sale::where('organization_id', $this->orgId())->where('product_id', $product->id)->exists()) {
            $dependencyLabels[] = 'sales';
        }

        if (InvoiceItem::where('product_id', $product->id)->exists()) {
            $dependencyLabels[] = 'invoices';
        }

        if (!empty($dependencyLabels)) {
            return redirect()
                ->back()
                ->with('error', 'Cannot delete this product because it is linked to ' . implode(', ', array_unique($dependencyLabels)) . '.');
        }

        DB::transaction(function () use ($product) {
            $product->saleInventories()->delete();
            $product->delete();
        });

        return redirect()->route('products.index')->with('success', 'Product deleted successfully.');
    }
}
