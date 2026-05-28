<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\AssetMovement;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleInventory;
use App\Models\Warehouse;
use App\Models\StockMovement;
use App\Services\Inventory\StockMovementRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

class AssetController extends Controller
{
    private const VERIFICATION_OUTCOME_TO_STATE = [
        'good' => ['condition_status' => 'good', 'asset_status' => 'available'],
        'repair' => ['condition_status' => 'repair', 'asset_status' => 'maintenance'],
        'scrap' => ['condition_status' => 'inactive', 'asset_status' => 'retired'],
    ];

    private function orgId(): int
    {
        return (int) auth()->user()->organization_id;
    }

    private function scopedAsset(Asset $asset): Asset
    {
        abort_if($asset->organization_id !== $this->orgId(), 403);

        return $asset;
    }

    private function assetValidationRules(?Asset $asset = null): array
    {
        $organizationId = $this->orgId();
        $assetId = $asset?->id;
        $assetStage = (string) request()->input('asset_stage', $asset?->asset_stage ?: Asset::STAGE_RENTAL_STOCK);
        $allowPendingSerial = !$asset && request()->boolean('serial_pending');
        $allowedStatuses = Asset::statusesForStage($assetStage);
        $conditionRule = $assetStage === Asset::STAGE_NEW_STOCK
            ? 'nullable|string|max:255'
            : ['required', Rule::in(Asset::CONDITION_STATUSES)];
        $serialRules = $asset
            ? [
                'required',
                'string',
                'max:255',
                Rule::unique('assets', 'serial_number')
                    ->where(fn ($query) => $query->where('organization_id', $organizationId))
                    ->ignore($assetId),
            ]
            : [
                Rule::requiredIf(!$allowPendingSerial),
                'nullable',
                'string',
                'max:255',
                Rule::unique('assets', 'serial_number')
                    ->where(fn ($query) => $query->where('organization_id', $organizationId)),
            ];

        return [
            'product_id' => [
                'required',
                Rule::exists('products', 'id')->where(fn ($query) => $query->where('organization_id', $organizationId)),
            ],
            'warehouse_id' => [
                'required',
                Rule::exists('warehouses', 'id')->where(fn ($query) => $query->where('organization_id', $organizationId)),
            ],
            'asset_name' => 'nullable|string|max:255',
            'serial_pending' => ['nullable', 'boolean'],
            'serial_number' => $serialRules,
            'barcode_value' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('assets', 'barcode_value')
                    ->where(fn ($query) => $query->where('organization_id', $organizationId))
                    ->ignore($assetId),
            ],
            'batch_number' => 'nullable|string|max:255',
            'purchase_date' => 'nullable|date',
            'purchase_cost' => 'nullable|numeric|min:0',
            'asset_stage' => ['required', Rule::in(Asset::ASSET_STAGES)],
            'condition_status' => $conditionRule,
            'asset_status' => ['required', Rule::in($allowedStatuses)],
            'notes' => 'nullable|string',
            'last_service_date' => 'nullable|date',
            'next_service_date' => 'nullable|date|after_or_equal:last_service_date',
        ];
    }

    private function normalizeAssetPayload(array $validated): array
    {
        $assetStage = (string) ($validated['asset_stage'] ?? Asset::STAGE_RENTAL_STOCK);

        $validated['asset_stage'] = $assetStage;
        $validated['asset_status'] = $validated['asset_status'] ?? Asset::defaultStatusForStage($assetStage);

        if ($assetStage === Asset::STAGE_NEW_STOCK) {
            $validated['condition_status'] = $validated['condition_status'] ?: 'good';
            $validated['last_service_date'] = $validated['last_service_date'] ?? null;
            $validated['next_service_date'] = $validated['next_service_date'] ?? null;
        } else {
            $validated['condition_status'] = $validated['condition_status'] ?: 'good';
        }

        return $validated;
    }

    private function nextSaleUnitSerial(Product $product, int &$sequence): string
    {
        $serial = $product->nextSaleUnitCode($sequence);
        $sequence++;

        return $serial;
    }

    private function saleUnitStartingSequence(Product $product): int
    {
        return $product->nextAvailableSaleUnitSequence();
    }

    private function formData(): array
    {
        $organizationId = $this->orgId();

        return [
            'products' => Product::where('organization_id', $organizationId)->orderBy('name')->get(),
            'warehouses' => Warehouse::where('organization_id', $organizationId)->where('is_active', true)->orderBy('name')->get(),
            'assetStages' => Asset::ASSET_STAGES,
            'assetStatuses' => Asset::ASSET_STATUSES,
            'newStockStatuses' => Asset::NEW_STOCK_ASSET_STATUSES,
            'rentalAssetStatuses' => Asset::RENTAL_ASSET_STATUSES,
            'conditionStatuses' => Asset::CONDITION_STATUSES,
        ];
    }

    private function recordMovement(
        Asset $asset,
        ?int $fromWarehouseId,
        ?int $toWarehouseId,
        string $movementType,
        ?string $remarks = null,
        ?string $fromStatus = null,
        ?string $toStatus = null
    ): void
    {
        AssetMovement::create([
            'organization_id' => $asset->organization_id,
            'asset_id' => $asset->id,
            'from_warehouse_id' => $fromWarehouseId,
            'to_warehouse_id' => $toWarehouseId,
            'movement_type' => $movementType,
            'remarks' => $remarks,
            'moved_by' => auth()->id(),
        ]);

        app(StockMovementRecorder::class)->recordForAsset(
            $asset,
            $movementType,
            1,
            [
                'from_status' => $fromStatus,
                'to_status' => $toStatus ?? $asset->asset_status,
                'from_warehouse_id' => $fromWarehouseId,
                'to_warehouse_id' => $toWarehouseId,
                'notes' => $remarks,
            ]
        );
    }

    private function verificationValidationRules(Asset $asset): array
    {
        $organizationId = $this->orgId();

        return [
            'serial_number' => [
                'required',
                'string',
                'max:255',
                Rule::unique('assets', 'serial_number')
                    ->where(fn ($query) => $query->where('organization_id', $organizationId))
                    ->ignore($asset->id),
            ],
            'barcode_value' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('assets', 'barcode_value')
                    ->where(fn ($query) => $query->where('organization_id', $organizationId))
                    ->ignore($asset->id),
            ],
            'manufacturing_year' => 'nullable|integer|min:1900|max:2100',
            'verification_outcome' => ['required', Rule::in(array_keys(self::VERIFICATION_OUTCOME_TO_STATE))],
            'remarks' => 'nullable|string|max:2000',
        ];
    }

    private function verificationNotes(?string $existingNotes, ?int $manufacturingYear, ?string $remarks): string
    {
        $segments = collect([
            filled($manufacturingYear) ? 'Manufacturing year: ' . $manufacturingYear : null,
            filled($remarks) ? 'Verification remarks: ' . trim((string) $remarks) : null,
        ])->filter()->values();

        $existing = trim((string) $existingNotes);

        if ($segments->isEmpty()) {
            return $existing;
        }

        return trim(collect([$existing, $segments->implode(' | ')])->filter()->implode(' | '));
    }

    private function pendingSerialPrefix(Product $product): string
    {
        $code = trim((string) ($product->product_code ?: $product->sku ?: ('PRODUCT' . $product->id)));
        $code = strtoupper(preg_replace('/[^A-Z0-9]+/', '-', $code) ?: ('PRODUCT' . $product->id));

        return Asset::SERIAL_PENDING_PREFIX . trim($code, '-');
    }

    private function generatePendingSerial(Product $product): string
    {
        $prefix = $this->pendingSerialPrefix($product);

        do {
            $candidate = $prefix . '-' . strtoupper(Str::random(8));
        } while (Asset::query()
            ->where('organization_id', $this->orgId())
            ->where('serial_number', $candidate)
            ->exists());

        return $candidate;
    }

    private function duplicateProductNameGroups(int $organizationId)
    {
        $products = Product::query()
            ->where('organization_id', $organizationId)
            ->get(['id', 'name', 'brand', 'model_name']);

        return $products
            ->groupBy(fn (Product $product) => Str::lower(trim((string) $product->name)))
            ->filter(fn ($group, $normalizedName) => $normalizedName !== '' && $group->count() > 1)
            ->map(fn ($group) => $group->values());
    }

    private function assetVariantIdentityWarning(Asset $asset, array $duplicateNameKeys): ?array
    {
        $product = $asset->product;

        if (!$product) {
            return null;
        }

        $normalizedName = Str::lower(trim((string) $product->name));
        $legacyAssetName = trim((string) ($asset->asset_name ?? ''));
        $linkedBrandModel = trim(collect([$product->brand, $product->model_name])->filter()->implode(' '));

        if (!in_array($normalizedName, $duplicateNameKeys, true)) {
            return null;
        }

        if ($legacyAssetName !== '' && Str::lower($legacyAssetName) !== Str::lower((string) $product->name) && Str::lower($legacyAssetName) !== Str::lower($linkedBrandModel)) {
            return [
                'type' => 'variant_conflict',
                'message' => 'Legacy asset label differs from linked Product Master variant. Review the linked product.',
            ];
        }

        return [
            'type' => 'duplicate_name',
            'message' => 'This product name exists on multiple Product Master variants. Verify the linked brand and model.',
        ];
    }

    private function activeSaleForAsset(Asset $asset): ?Sale
    {
        return Sale::query()
            ->with(['customer', 'rental'])
            ->where('organization_id', $this->orgId())
            ->where('payment_status', '!=', 'void')
            ->where(function ($query) use ($asset) {
                $query->where('asset_id', $asset->id)
                    ->orWhereHas('saleUnits', fn ($saleUnitsQuery) => $saleUnitsQuery->where('assets.id', $asset->id));
            })
            ->latest('sale_date')
            ->latest('id')
            ->first();
    }

    private function workflowControlledEditContext(Asset $asset): array
    {
        $activeRentalAssignment = $asset->activeRentalAssignments()
            ->with('rental.customer')
            ->latest('assigned_at')
            ->first();

        $activeRental = $activeRentalAssignment?->rental;
        $activeSale = $this->activeSaleForAsset($asset);
        $convertUrl = $asset->product_id ? route('products.show', $asset->product_id) . '#conversion-history' : null;

        if ($asset->isRentalStock() && ($activeRental || $asset->rentalWorkflowControlsStatus())) {
            return [
                'locked' => true,
                'type' => 'rental',
                'locks_condition' => true,
                'message' => 'This asset status is controlled by rental/sale workflow. Use the appropriate return verification, sale void, or conversion action.',
                'action_label' => $asset->asset_status === Asset::STATUS_AWAITING_VERIFICATION ? 'Verify Return' : ($activeRental ? 'View Rental' : null),
                'action_url' => $asset->asset_status === Asset::STATUS_AWAITING_VERIFICATION
                    ? route('assets.verify-return', $asset)
                    : ($activeRental ? route('rentals.show', $activeRental) : null),
                'convert_url' => null,
                'active_rental' => $activeRental,
                'active_sale' => null,
            ];
        }

        if ($asset->isNewStock() && ($activeSale || $asset->saleWorkflowControlsStatus())) {
            return [
                'locked' => true,
                'type' => 'sale',
                'locks_condition' => false,
                'message' => 'This asset status is controlled by rental/sale workflow. Use the appropriate return verification, sale void, or conversion action.',
                'action_label' => $activeSale ? 'View Sale' : null,
                'action_url' => $activeSale ? route('sales.show', $activeSale) : null,
                'convert_url' => null,
                'active_rental' => null,
                'active_sale' => $activeSale,
            ];
        }

        return [
            'locked' => false,
            'type' => null,
            'locks_condition' => false,
            'message' => null,
            'action_label' => null,
            'action_url' => null,
            'convert_url' => $convertUrl,
            'active_rental' => null,
            'active_sale' => null,
        ];
    }

    private function validateWorkflowControlledAssetEdit(Asset $asset, array $validated): void
    {
        $workflowControl = $this->workflowControlledEditContext($asset);

        if (!$workflowControl['locked']) {
            return;
        }

        $attemptedStage = (string) ($validated['asset_stage'] ?? $asset->asset_stage);
        $attemptedStatus = (string) ($validated['asset_status'] ?? $asset->asset_status);
        $attemptedCondition = (string) ($validated['condition_status'] ?? $asset->condition_status);

        $blocked = $attemptedStage !== (string) $asset->asset_stage
            || $attemptedStatus !== (string) $asset->asset_status
            || (($workflowControl['locks_condition'] ?? false) && $attemptedCondition !== (string) $asset->condition_status);

        if (!$blocked) {
            return;
        }

        throw ValidationException::withMessages([
            'asset_status' => [$workflowControl['message']],
        ]);
    }

    private function assetIndexQuery(int $organizationId)
    {
        return Asset::with([
            'product:id,name,brand,model_name,product_code,sku',
            'warehouse',
            'activeRentalAssignments.rental.customer',
            'rentalAssignments.rental.customer',
            'sales.customer',
        ])->where('organization_id', $organizationId);
    }

    private function applyAssetIndexFilters($query, Request $request)
    {
        return $query
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = trim((string) $request->search);

                $query->where(function ($innerQuery) use ($search) {
                    $innerQuery->where('serial_number', 'like', '%' . $search . '%')
                        ->orWhere('barcode_value', 'like', '%' . $search . '%')
                        ->orWhere('asset_name', 'like', '%' . $search . '%')
                        ->orWhereHas('product', fn ($productQuery) => $productQuery->where('name', 'like', '%' . $search . '%'));
                });
            })
            ->when($request->filled('product_id'), fn ($query) => $query->where('product_id', $request->product_id))
            ->when($request->filled('asset_stage'), fn ($query) => $query->where('asset_stage', $request->asset_stage))
            ->when($request->filled('warehouse_id'), fn ($query) => $query->where('warehouse_id', $request->warehouse_id))
            ->when($request->filled('asset_status'), fn ($query) => $query->where('asset_status', $request->asset_status))
            ->when($request->filled('condition_status'), fn ($query) => $query->where('condition_status', $request->condition_status));
    }

    public function index(Request $request)
    {
        $organizationId = $this->orgId();
        $duplicateProductNameGroups = $this->duplicateProductNameGroups($organizationId);
        $duplicateProductNameKeys = $duplicateProductNameGroups->keys()->values()->all();
        $mixedAssetProductGroups = DB::table('assets')
            ->join('products', 'products.id', '=', 'assets.product_id')
            ->where('assets.organization_id', $organizationId)
            ->selectRaw('LOWER(products.name) as normalized_name, COUNT(DISTINCT assets.product_id) as product_ids, COUNT(*) as asset_count')
            ->groupByRaw('LOWER(products.name)')
            ->havingRaw('COUNT(DISTINCT assets.product_id) > 1')
            ->get();
        $summary = [
            'total_assets' => Asset::where('organization_id', $organizationId)->count(),
            'sale_stock' => (int) Asset::where('organization_id', $organizationId)->where('asset_stage', Asset::STAGE_NEW_STOCK)->where('asset_status', 'available_for_sale')->count(),
            'rental_stock_assets' => Asset::where('organization_id', $organizationId)->where('asset_stage', Asset::STAGE_RENTAL_STOCK)->count(),
            'available_assets' => Asset::where('organization_id', $organizationId)->where('asset_stage', Asset::STAGE_RENTAL_STOCK)->where('asset_status', 'available')->count(),
            'awaiting_verification_assets' => Asset::where('organization_id', $organizationId)->where('asset_stage', Asset::STAGE_RENTAL_STOCK)->where('asset_status', 'awaiting_verification')->count(),
            'rented_assets' => Asset::where('organization_id', $organizationId)->where('asset_stage', Asset::STAGE_RENTAL_STOCK)->where('asset_status', 'rented')->count(),
            'maintenance_assets' => Asset::where('organization_id', $organizationId)->where('asset_stage', Asset::STAGE_RENTAL_STOCK)->where('asset_status', 'maintenance')->count(),
        ];

        $assets = $this->applyAssetIndexFilters($this->assetIndexQuery($organizationId), $request)
            ->latest()
            ->paginate(12)
            ->withQueryString();

        $assets->getCollection()->transform(function (Asset $asset) use ($duplicateProductNameKeys) {
            $asset->variant_identity_warning = $this->assetVariantIdentityWarning($asset, $duplicateProductNameKeys);

            return $asset;
        });

        $warehouses = Warehouse::where('organization_id', $organizationId)->orderBy('name')->get();

        return view('assets.index', [
            'assets' => $assets,
            'warehouses' => $warehouses,
            'assetStages' => Asset::ASSET_STAGES,
            'assetStatuses' => Asset::ASSET_STATUSES,
            'newStockStatuses' => Asset::NEW_STOCK_ASSET_STATUSES,
            'rentalAssetStatuses' => Asset::RENTAL_ASSET_STATUSES,
            'conditionStatuses' => Asset::CONDITION_STATUSES,
            'summary' => $summary,
            'duplicateProductNameGroups' => $duplicateProductNameGroups,
            'mixedAssetProductGroups' => $mixedAssetProductGroups,
        ]);
    }

    public function exportCsv(Request $request)
    {
        $assets = $this->applyAssetIndexFilters($this->assetIndexQuery($this->orgId()), $request)
            ->latest()
            ->get();

        return response()->streamDownload(function () use ($assets) {
            $output = fopen('php://output', 'w');

            fputcsv($output, [
                '#',
                'Product',
                'Brand',
                'Model',
                'Unit Type',
                'Serial Number',
                'Barcode',
                'Warehouse',
                'Asset Status',
                'Condition',
                'Asset Name',
                'Product Code',
                'SKU',
                'Created Date',
            ]);

            foreach ($assets as $index => $asset) {
                fputcsv($output, [
                    $index + 1,
                    $asset->product?->name,
                    $asset->product?->brand,
                    $asset->product?->model_name,
                    $asset->asset_stage,
                    $asset->serial_number,
                    $asset->barcode_value,
                    $asset->warehouse?->name,
                    $asset->asset_status,
                    $asset->condition_status,
                    $asset->asset_name,
                    $asset->product?->product_code,
                    $asset->product?->sku,
                    optional($asset->created_at)->format('Y-m-d H:i:s'),
                ]);
            }

            fclose($output);
        }, 'asset-register-' . now()->format('Ymd-His') . '.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function pendingVerification()
    {
          $assets = Asset::query()
              ->with(['product', 'warehouse', 'activeRentalAssignments.rental.customer', 'rentalAssignments.rental.customer'])
            ->where('organization_id', $this->orgId())
            ->where('asset_stage', Asset::STAGE_RENTAL_STOCK)
            ->where('asset_status', 'awaiting_verification')
            ->latest('updated_at')
            ->paginate(12);

        return view('assets.pending-verification', compact('assets'));
    }

    public function create(Request $request)
    {
        return view('assets.create', array_merge($this->formData(), [
            'asset' => new Asset([
                'product_id' => $request->integer('product_id') ?: null,
                'serial_number' => (string) $request->input('serial_number', $request->input('lookup', '')),
                'barcode_value' => (string) $request->input('barcode_value', ''),
                'asset_stage' => (string) $request->input('asset_stage', Asset::STAGE_RENTAL_STOCK),
                'condition_status' => 'good',
                'asset_status' => 'available',
            ]),
            'prefillLookup' => (string) $request->input('lookup', ''),
            'serialPendingEnabled' => (bool) old('serial_pending', false),
        ]));
    }

    public function store(Request $request)
    {
        $validated = $this->normalizeAssetPayload(
            $request->validate($this->assetValidationRules())
        );
        $serialPending = $request->boolean('serial_pending') && blank($validated['serial_number'] ?? null);
        $product = Product::query()
            ->where('organization_id', $this->orgId())
            ->findOrFail((int) $validated['product_id']);

        $createdAsset = DB::transaction(function () use ($validated, $product, $serialPending) {
            $resolvedSerial = $serialPending
                ? $this->generatePendingSerial($product)
                : trim((string) $validated['serial_number']);
            $asset = Asset::create([
                ...$validated,
                'organization_id' => $this->orgId(),
                'asset_name' => $validated['asset_name'] ?: $product->name,
                'serial_number' => $resolvedSerial,
                'barcode_value' => filled($validated['barcode_value'] ?? null) ? trim((string) $validated['barcode_value']) : null,
            ]);

            $this->recordMovement(
                $asset,
                null,
                $asset->warehouse_id,
                StockMovement::TYPE_ADD_STOCK,
                'Asset created and added to warehouse.',
                null,
                $asset->asset_status
            );

            SaleInventory::syncFromSaleUnits($this->orgId(), $product->id);
            $product->syncLegacyStockFields();

            return $asset;
        });

        return redirect()->route('assets.show', $createdAsset)->with('success', 'Asset created successfully.');
    }

    public function show(Asset $asset)
    {
        $asset = $this->scopedAsset($asset);
          $asset->load([
              'product',
              'warehouse',
              'activeRentalAssignments.rental.customer',
              'rentalAssignments.rental.customer',
              'sales.customer',
              'movements.fromWarehouse',
              'movements.toWarehouse',
              'movements.movedBy',
        ]);

        $workflowControl = $this->workflowControlledEditContext($asset);

        return view('assets.show', compact('asset', 'workflowControl'));
    }

    public function verifyReturn(Asset $asset)
    {
        $asset = $this->scopedAsset($asset);
        abort_if($asset->asset_stage !== Asset::STAGE_RENTAL_STOCK, 404);
        abort_if($asset->asset_status !== 'awaiting_verification', 404);

          $asset->load(['product', 'warehouse']);

          return view('assets.verify-return', compact('asset'));
    }

    public function storeReturnVerification(Request $request, Asset $asset)
    {
        $asset = $this->scopedAsset($asset);
        abort_if($asset->asset_stage !== Asset::STAGE_RENTAL_STOCK, 404);
        abort_if($asset->asset_status !== 'awaiting_verification', 404);

        $validated = $request->validate($this->verificationValidationRules($asset));
        $state = self::VERIFICATION_OUTCOME_TO_STATE[$validated['verification_outcome']];

        DB::transaction(function () use ($asset, $validated, $state) {
            $previousStatus = $asset->asset_status;
            $asset->update([
                'serial_number' => trim((string) $validated['serial_number']),
                'barcode_value' => filled($validated['barcode_value'] ?? null) ? trim((string) $validated['barcode_value']) : null,
                'condition_status' => $state['condition_status'],
                'asset_status' => $state['asset_status'],
                'notes' => $this->verificationNotes(
                    $asset->notes,
                    filled($validated['manufacturing_year'] ?? null) ? (int) $validated['manufacturing_year'] : null,
                    $validated['remarks'] ?? null
                ),
            ]);

            $asset->refresh();

            $this->recordMovement(
                $asset,
                $asset->warehouse_id,
                $asset->warehouse_id,
                match ((string) $validated['verification_outcome']) {
                    'repair' => StockMovement::TYPE_REPAIR,
                    'scrap' => StockMovement::TYPE_SCRAP,
                    default => StockMovement::TYPE_RETURN_VERIFICATION,
                },
                trim(collect([
                    'Returned asset verified as ' . ucfirst((string) $validated['verification_outcome']) . '.',
                    filled($validated['manufacturing_year'] ?? null) ? 'Manufacturing year: ' . $validated['manufacturing_year'] . '.' : null,
                    filled($validated['remarks'] ?? null) ? 'Remarks: ' . trim((string) $validated['remarks']) : null,
                ])->filter()->implode(' ')),
                $previousStatus,
                $state['asset_status']
            );

            SaleInventory::syncFromSaleUnits($this->orgId(), $asset->product_id);
            $asset->product?->syncLegacyStockFields();
        });

        return redirect()->route('assets.pending-verification')->with('success', 'Returned asset verified successfully.');
    }

    public function edit(Asset $asset)
    {
        $asset = $this->scopedAsset($asset);
        $workflowControl = $this->workflowControlledEditContext($asset);

        return view('assets.edit', array_merge($this->formData(), compact('asset', 'workflowControl')));
    }

    public function update(Request $request, Asset $asset)
    {
        $asset = $this->scopedAsset($asset);
        $validated = $this->normalizeAssetPayload(
            $request->validate($this->assetValidationRules($asset))
        );
        $this->validateWorkflowControlledAssetEdit($asset, $validated);
        $fromWarehouseId = $asset->warehouse_id;
        $fromStatus = $asset->asset_status;

        $asset->update([
            ...$validated,
            'serial_number' => trim((string) $validated['serial_number']),
            'barcode_value' => $validated['barcode_value'] ? trim((string) $validated['barcode_value']) : null,
        ]);

        SaleInventory::syncFromSaleUnits($this->orgId(), $asset->product_id);
        $asset->product?->syncLegacyStockFields();

        if ($fromWarehouseId !== (int) $asset->warehouse_id) {
            $this->recordMovement(
                $asset,
                $fromWarehouseId,
                $asset->warehouse_id,
                StockMovement::TYPE_WAREHOUSE_TRANSFER,
                'Warehouse updated from asset edit screen.',
                $fromStatus,
                $asset->asset_status
            );
        }

        return redirect()->route('assets.show', $asset)->with('success', 'Asset updated successfully.');
    }

    public function destroy(Asset $asset)
    {
        $asset = $this->scopedAsset($asset);

        $dependencyLabels = [];

        if ($asset->rentalAssignments()->exists()) {
            $dependencyLabels[] = 'rental history';
        }

        if ($asset->activeRentalAssignments()->exists() || in_array($asset->asset_status, ['rented', 'reserved', 'awaiting_verification', 'reserved_for_sale', 'sold', 'converted_to_rental'], true)) {
            $dependencyLabels[] = 'active stock movement';
        }

        if (Sale::where('organization_id', $this->orgId())->where('asset_id', $asset->id)->exists()) {
            $dependencyLabels[] = 'sales';
        }

        if (!empty($dependencyLabels)) {
            return redirect()
                ->back()
                ->with('error', 'Cannot delete this asset because it is linked to ' . implode(', ', array_unique($dependencyLabels)) . '.');
        }

        $asset->delete();

        SaleInventory::syncFromSaleUnits($this->orgId(), $asset->product_id);

        return redirect()->route('assets.index')->with('success', 'Asset deleted successfully.');
    }

    public function transfer(Asset $asset)
    {
        $asset = $this->scopedAsset($asset);
        $asset->load(['product', 'warehouse']);

        $warehouses = Warehouse::where('organization_id', $this->orgId())
            ->where('is_active', true)
            ->where('id', '!=', $asset->warehouse_id)
            ->orderBy('name')
            ->get();

        return view('assets.transfer', compact('asset', 'warehouses'));
    }

    public function storeTransfer(Request $request, Asset $asset)
    {
        $asset = $this->scopedAsset($asset);
        $validated = $request->validate([
            'warehouse_id' => [
                'required',
                'different:current_warehouse_id',
                Rule::exists('warehouses', 'id')->where(fn ($query) => $query->where('organization_id', $this->orgId())),
            ],
            'remarks' => 'nullable|string|max:1000',
        ]);

        $fromWarehouseId = $asset->warehouse_id;
        $fromStatus = $asset->asset_status;
        $asset->update(['warehouse_id' => $validated['warehouse_id']]);

        $this->recordMovement(
            $asset,
            $fromWarehouseId,
            (int) $validated['warehouse_id'],
            StockMovement::TYPE_WAREHOUSE_TRANSFER,
            $validated['remarks'] ?? 'Asset transferred between warehouses.',
            $fromStatus,
            $asset->asset_status
        );

        SaleInventory::syncFromSaleUnits($this->orgId(), $asset->product_id);
        $asset->product?->syncLegacyStockFields();

        return redirect()->route('assets.show', $asset)->with('success', 'Asset transferred successfully.');
    }

    public function scanLookup(Request $request)
    {
        $lookup = trim((string) $request->input('lookup', ''));

        if ($lookup === '') {
            return redirect()->route('inventory.dashboard')->with('error', 'Please scan or enter a serial number or barcode.');
        }

        $asset = Asset::where('organization_id', $this->orgId())
            ->where(function ($query) use ($lookup) {
                $query->where('serial_number', $lookup)
                    ->orWhere('barcode_value', $lookup);
            })
            ->first();

        if ($asset) {
            return redirect()->route('assets.show', $asset)->with('success', 'Asset found successfully.');
        }

        return view('assets.not-found', ['lookup' => $lookup]);
    }
}
