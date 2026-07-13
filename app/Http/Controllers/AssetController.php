<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\AssetMovement;
use App\Models\AccessoryTemplate;
use App\Models\FollowUp;
use App\Models\Product;
use App\Models\RentalItem;
use App\Models\ReturnVerification;
use App\Models\ReturnVerificationAccessory;
use App\Models\Sale;
use App\Models\SaleInventory;
use App\Models\Warehouse;
use App\Models\StockMovement;
use App\Services\Inventory\StockMovementRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

class AssetController extends Controller
{
    private const VERIFICATION_OUTCOME_TO_STATE = [
        'return_to_stock' => ['condition_status' => null, 'asset_status' => Asset::STATUS_AVAILABLE],
        'repair' => ['condition_status' => null, 'asset_status' => Asset::STATUS_MAINTENANCE],
        'damaged' => ['condition_status' => Asset::CONDITION_STATUS_DAMAGED, 'asset_status' => Asset::STATUS_DAMAGED],
        'retire' => ['condition_status' => Asset::CONDITION_STATUS_RETIRED, 'asset_status' => Asset::STATUS_RETIRED],
        'missing_components' => ['condition_status' => null, 'asset_status' => Asset::STATUS_AWAITING_RESOLUTION],
        'review' => ['condition_status' => null, 'asset_status' => Asset::STATUS_AWAITING_RESOLUTION],
        'good' => ['condition_status' => Asset::CONDITION_STATUS_GOOD, 'asset_status' => Asset::STATUS_AVAILABLE],
        'scrap' => ['condition_status' => Asset::CONDITION_STATUS_INACTIVE_LEGACY, 'asset_status' => Asset::STATUS_RETIRED],
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

    private function validateProductStageCompatibility(Product $product, string $assetStage): void
    {
        if ($assetStage === Asset::STAGE_RENTAL_STOCK && !$product->canRent()) {
            throw ValidationException::withMessages([
                'product_id' => ['Rental assets can only be added for rentable products.'],
            ]);
        }

        if ($assetStage === Asset::STAGE_NEW_STOCK && !$product->canSell()) {
            throw ValidationException::withMessages([
                'product_id' => ['Sale units can only be added for sellable products.'],
            ]);
        }
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
            'model_variant' => 'nullable|string|max:255',
            'condition_status' => ['nullable', Rule::in(Asset::CONDITION_STATUSES)],
            'verification_outcome' => ['required', Rule::in(array_keys(self::VERIFICATION_OUTCOME_TO_STATE))],
            'remarks' => 'nullable|string|max:2000',
            'accessories' => ['nullable', 'array'],
            'accessories.*.name' => ['required_with:accessories', 'string', 'max:255'],
            'accessories.*.status' => ['required_with:accessories', Rule::in(ReturnVerificationAccessory::STATUSES)],
            'accessories.*.remarks' => ['nullable', 'string', 'max:1000'],
            'custom_accessories' => ['nullable', 'array'],
            'custom_accessories.*.name' => ['nullable', 'string', 'max:255'],
            'custom_accessories.*.status' => ['nullable', Rule::in(ReturnVerificationAccessory::STATUSES)],
            'custom_accessories.*.remarks' => ['nullable', 'string', 'max:1000'],
            'photos' => ['nullable', 'array'],
            'photos.*' => ['nullable', 'image', 'max:5120'],
        ];
    }

    private function validationError(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private function normalizedVerificationAccessories(array $validated): array
    {
        $rows = collect($validated['accessories'] ?? [])
            ->merge(collect($validated['custom_accessories'] ?? [])->filter(fn ($row) => filled($row['name'] ?? null)))
            ->map(function (array $row) {
                return [
                    'name' => trim((string) ($row['name'] ?? '')),
                    'status' => (string) ($row['status'] ?? ReturnVerificationAccessory::STATUS_RETURNED),
                    'remarks' => filled($row['remarks'] ?? null) ? trim((string) $row['remarks']) : null,
                ];
            })
            ->filter(fn (array $row) => $row['name'] !== '')
            ->values()
            ->all();

        return $rows;
    }

    private function hasAccessoryIssue(array $accessories): bool
    {
        return collect($accessories)->contains(fn (array $row) => in_array($row['status'], [
            ReturnVerificationAccessory::STATUS_MISSING,
            ReturnVerificationAccessory::STATUS_DAMAGED,
        ], true));
    }

    private function validateReturnVerificationBusinessRules(Request $request, array $validated, array $accessories): void
    {
        $outcome = (string) $validated['verification_outcome'];
        $condition = (string) $validated['condition_status'];
        $remarks = trim((string) ($validated['remarks'] ?? ''));

        if (in_array($outcome, ['damaged', 'retire'], true) && $remarks === '') {
            $this->validationError('remarks', 'Remarks are required for damaged or retired assets.');
        }

        if ($this->hasAccessoryIssue($accessories) && $remarks === '') {
            $this->validationError('remarks', 'Remarks are required when any accessory is missing or damaged.');
        }

        if (in_array($condition, [Asset::CONDITION_STATUS_DAMAGED, Asset::CONDITION_STATUS_NEEDS_REPAIR], true)
            && !$this->requestHasVerificationPhoto($request)) {
            $this->validationError('photos.overall', 'Upload at least one photo for damaged or repair-needed assets.');
        }
    }

    private function requestHasVerificationPhoto(Request $request): bool
    {
        foreach ((array) $request->file('photos', []) as $file) {
            if ($file) {
                return true;
            }
        }

        return false;
    }

    private function verificationNotes(?string $existingNotes, ?int $manufacturingYear, ?string $remarks, ?string $modelVariant = null, array $accessories = []): string
    {
        $segments = collect([
            filled($manufacturingYear) ? 'Manufacturing year: ' . $manufacturingYear : null,
            filled($modelVariant) ? 'Model / variant: ' . trim((string) $modelVariant) : null,
            filled($remarks) ? 'Verification remarks: ' . trim((string) $remarks) : null,
            $this->hasAccessoryIssue($accessories)
                ? 'Accessory issues: ' . collect($accessories)
                    ->filter(fn (array $row) => in_array($row['status'], [
                        ReturnVerificationAccessory::STATUS_MISSING,
                        ReturnVerificationAccessory::STATUS_DAMAGED,
                    ], true))
                    ->map(fn (array $row) => $row['name'] . ' (' . str_replace('_', ' ', $row['status']) . ')')
                    ->implode(', ')
                : null,
        ])->filter()->values();

        $existing = trim((string) $existingNotes);

        if ($segments->isEmpty()) {
            return $existing;
        }

        return trim(collect([$existing, $segments->implode(' | ')])->filter()->implode(' | '));
    }

    private function latestReturnedRentalAssignment(Asset $asset)
    {
        return $asset->rentalAssignments
            ->filter(fn ($assignment) => filled($assignment->returned_at))
            ->sortByDesc(fn ($assignment) => optional($assignment->returned_at)->timestamp ?? 0)
            ->first()
            ?: $asset->rentalAssignments->sortByDesc('id')->first();
    }

    private function accessoryChecklistForProduct(?Product $product): array
    {
        if (!$product) {
            return [];
        }

        if (Schema::hasTable('accessory_template_items') && $product->relationLoaded('accessoryTemplate') && $product->accessoryTemplate) {
            $items = $product->accessoryTemplate->items
                ->map(fn ($item) => [
                    'name' => $item->name,
                    'required' => (bool) $item->is_required,
                    'source' => 'template',
                ])
                ->values()
                ->all();

            if ($items !== []) {
                return $items;
            }
        }

        if (Schema::hasTable('accessory_templates') && Schema::hasTable('accessory_template_items')) {
            $template = $this->inferAccessoryTemplate($product);
            if ($template) {
                return $template->items
                    ->map(fn ($item) => [
                        'name' => $item->name,
                        'required' => (bool) $item->is_required,
                        'source' => 'inferred',
                    ])
                    ->values()
                    ->all();
            }
        }

        return $this->fallbackAccessoryChecklist($product);
    }

    private function inferAccessoryTemplate(Product $product): ?AccessoryTemplate
    {
        $haystack = Str::lower(collect([
            $product->name,
            $product->category,
            $product->product_type,
            $product->brand,
            $product->model_name,
            $product->sku,
        ])->filter()->implode(' '));

        $templateName = match (true) {
            str_contains($haystack, 'oxygen') || str_contains($haystack, 'concentrator') => 'Oxygen Concentrator',
            str_contains($haystack, 'bipap') || str_contains($haystack, 'cpap') => 'BiPAP / CPAP',
            str_contains($haystack, 'hospital bed') || str_contains($haystack, 'bed') => 'Hospital Bed',
            str_contains($haystack, 'wheelchair') => 'Wheelchair',
            str_contains($haystack, 'suction') => 'Suction Machine',
            str_contains($haystack, 'dvt') => 'DVT Pump',
            str_contains($haystack, 'nebulizer') => 'Nebulizer',
            str_contains($haystack, 'monitor') => 'ICU Monitor',
            str_contains($haystack, 'ventilator') => 'Ventilator',
            default => null,
        };

        if (!$templateName) {
            return null;
        }

        return AccessoryTemplate::query()
            ->with('items')
            ->where('is_active', true)
            ->where('name', $templateName)
            ->first();
    }

    private function fallbackAccessoryChecklist(Product $product): array
    {
        $haystack = Str::lower(collect([
            $product->name,
            $product->category,
            $product->brand,
            $product->model_name,
        ])->filter()->implode(' '));

        $items = match (true) {
            str_contains($haystack, 'oxygen') || str_contains($haystack, 'concentrator') => ['Power cable', 'Humidifier bottle', 'Nasal cannula', 'Oxygen tube', 'Filter', 'User manual'],
            str_contains($haystack, 'bipap') || str_contains($haystack, 'cpap') => ['Machine unit', 'Power adapter', 'Mask', 'Tubing', 'Humidifier chamber', 'Filter', 'Carry bag'],
            str_contains($haystack, 'hospital bed') || str_contains($haystack, 'bed') => ['Mattress', 'Side rails', 'Remote', 'Power cable', 'Wheels / castors', 'IV pole if included'],
            str_contains($haystack, 'wheelchair') => ['Footrests', 'Armrests', 'Seat cushion', 'Brakes', 'Seat belt if included'],
            str_contains($haystack, 'suction') => ['Power cable', 'Suction jar', 'Suction tube', 'Filter', 'Manual / documents'],
            str_contains($haystack, 'dvt') => ['Power adapter', 'Cuffs', 'Tubes', 'Carry bag'],
            str_contains($haystack, 'nebulizer') => ['Power cable', 'Medication cup', 'Mask', 'Tubing', 'Filter'],
            str_contains($haystack, 'monitor') => ['Power cable', 'ECG leads', 'SpO2 probe', 'NIBP cuff', 'Temperature probe'],
            str_contains($haystack, 'ventilator') => ['Power cable', 'Circuit', 'Humidifier chamber', 'Filter', 'Oxygen hose', 'Manual'],
            default => ['Power cable', 'Manual / documents'],
        };

        return collect($items)
            ->map(fn (string $name) => ['name' => $name, 'required' => true, 'source' => 'fallback'])
            ->values()
            ->all();
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
            'movements.movedBy',
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
            ->when($request->filled('warehouse_id'), function ($query) use ($request) {
                return $request->warehouse_id === '__missing'
                    ? $query->whereNull('warehouse_id')
                    : $query->where('warehouse_id', $request->warehouse_id);
            })
            ->when($request->filled('city'), function ($query) use ($request) {
                $city = trim((string) $request->city);

                return $query->whereHas('warehouse', fn ($warehouseQuery) => $warehouseQuery->where('city', $city));
            })
            ->when($request->filled('asset_status'), fn ($query) => $query->where('asset_status', $request->asset_status))
            ->when($request->filled('condition_status'), fn ($query) => $query->where('condition_status', $request->condition_status))
            ->when($request->filled('custody'), function ($query) use ($request) {
                return match ((string) $request->custody) {
                    'customer' => $query->whereHas('activeRentalAssignments'),
                    'sale' => $query->whereHas('sales'),
                    'warehouse' => $query->whereNotNull('warehouse_id')->whereDoesntHave('activeRentalAssignments')->whereDoesntHave('sales'),
                    'verification' => $query->where('asset_status', Asset::STATUS_AWAITING_VERIFICATION),
                    'missing' => $query->whereNull('warehouse_id')->whereDoesntHave('activeRentalAssignments')->whereDoesntHave('sales'),
                    default => $query,
                };
            })
            ->when($request->filled('risk'), function ($query) use ($request) {
                return match ((string) $request->risk) {
                    'verification' => $query->where('asset_status', 'awaiting_verification'),
                    'repair_delay' => $query->where('asset_status', 'maintenance')->where('updated_at', '<=', now()->subDays(30)),
                    'missing_custody' => $query->whereNull('warehouse_id')->whereDoesntHave('activeRentalAssignments'),
                    'no_movement' => $query->where('updated_at', '<=', now()->subDays(90)),
                    'overdue_return' => $query->whereHas('activeRentalAssignments.rental', function ($rentalQuery) {
                        $rentalQuery->whereDate('end_date', '<', now()->toDateString())
                            ->whereNotIn('status', ['returned', 'cancelled']);
                    }),
                    default => $query,
                };
            });
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
            'available_assets' => Asset::where('organization_id', $organizationId)->whereIn('asset_status', ['available', 'available_for_sale'])->count(),
            'awaiting_verification_assets' => Asset::where('organization_id', $organizationId)->where('asset_stage', Asset::STAGE_RENTAL_STOCK)->where('asset_status', 'awaiting_verification')->count(),
            'rented_assets' => Asset::where('organization_id', $organizationId)->where('asset_stage', Asset::STAGE_RENTAL_STOCK)->where('asset_status', 'rented')->count(),
            'maintenance_assets' => Asset::where('organization_id', $organizationId)->where('asset_stage', Asset::STAGE_RENTAL_STOCK)->where('asset_status', 'maintenance')->count(),
            'reserved_assets' => Asset::where('organization_id', $organizationId)->whereIn('asset_status', ['reserved', 'reserved_for_sale'])->count(),
            'retired_assets' => Asset::where('organization_id', $organizationId)->where('asset_status', 'retired')->count(),
            'missing_risk_assets' => Asset::where('organization_id', $organizationId)->whereNull('warehouse_id')->count(),
            'attention_awaiting_3_days' => Asset::where('organization_id', $organizationId)->where('asset_status', 'awaiting_verification')->where('updated_at', '<=', now()->subDays(3))->count(),
            'attention_repair_30_days' => Asset::where('organization_id', $organizationId)->where('asset_status', 'maintenance')->where('updated_at', '<=', now()->subDays(30))->count(),
            'attention_no_location' => Asset::where('organization_id', $organizationId)->whereNull('warehouse_id')->count(),
            'attention_no_movement_60_days' => Asset::where('organization_id', $organizationId)->where('updated_at', '<=', now()->subDays(60))->count(),
            'attention_no_movement_90_days' => Asset::where('organization_id', $organizationId)->where('updated_at', '<=', now()->subDays(90))->count(),
            'attention_overdue_returns' => Asset::where('organization_id', $organizationId)
                ->whereHas('activeRentalAssignments.rental', function ($query) {
                    $query->whereDate('end_date', '<', now()->toDateString())
                        ->whereNotIn('status', ['returned', 'cancelled']);
                })
                ->count(),
        ];
        $summary['risk_assets'] = Asset::where('organization_id', $organizationId)
            ->where(function ($query) {
                $query->where('asset_status', 'awaiting_verification')
                    ->orWhere(function ($repairQuery) {
                        $repairQuery->where('asset_status', 'maintenance')
                            ->where('updated_at', '<=', now()->subDays(30));
                    })
                    ->orWhereNull('warehouse_id')
                    ->orWhere('updated_at', '<=', now()->subDays(90))
                    ->orWhereHas('activeRentalAssignments.rental', function ($rentalQuery) {
                        $rentalQuery->whereDate('end_date', '<', now()->toDateString())
                            ->whereNotIn('status', ['returned', 'cancelled']);
                    });
            })
            ->count();

        $perPage = (int) $request->integer('per_page', 25);
        $perPage = in_array($perPage, [10, 25, 50, 100], true) ? $perPage : 25;

        $assets = $this->applyAssetIndexFilters($this->assetIndexQuery($organizationId), $request)
            ->latest()
            ->paginate($perPage)
            ->withQueryString();

        $assets->getCollection()->transform(function (Asset $asset) use ($duplicateProductNameKeys) {
            $asset->variant_identity_warning = $this->assetVariantIdentityWarning($asset, $duplicateProductNameKeys);

            return $asset;
        });

        $warehouses = Warehouse::where('organization_id', $organizationId)->orderBy('name')->get();
        $cityOptions = Warehouse::where('organization_id', $organizationId)
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->distinct()
            ->orderBy('city')
            ->pluck('city');

        return view('assets.index', [
            'assets' => $assets,
            'warehouses' => $warehouses,
            'cityOptions' => $cityOptions,
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
          $this->reconcileCompletedPickupAssetsForVerification();

          $assets = Asset::query()
              ->with(['product', 'warehouse', 'activeRentalAssignments.rental.customer', 'rentalAssignments.rental.customer'])
            ->where('organization_id', $this->orgId())
            ->where('asset_stage', Asset::STAGE_RENTAL_STOCK)
            ->where('asset_status', 'awaiting_verification')
            ->latest('updated_at')
            ->paginate(12);

        return view('assets.pending-verification', compact('assets'));
    }

    private function reconcileCompletedPickupAssetsForVerification(): void
    {
        if (!Schema::hasTable('rental_items') || !Schema::hasTable('deliveries')) {
            return;
        }

        RentalItem::query()
            ->with('rental')
            ->where('organization_id', $this->orgId())
            ->whereNotNull('asset_ids')
            ->whereRaw('(COALESCE(returned_quantity, 0) > 0 OR COALESCE(delivered_quantity, 0) > 0)')
            ->whereHas('rental', function ($query) {
                $query->where('organization_id', $this->orgId())
                    ->where(function ($rentalQuery) {
                        $rentalQuery->where('status', 'returned')
                            ->orWhereHas('deliveries', function ($deliveryQuery) {
                                $deliveryQuery->where('type', 'pickup')
                                    ->where('status', 'completed');
                            });
                    });
            })
            ->orderByDesc('updated_at')
            ->limit(250)
            ->get()
            ->each(function (RentalItem $item) {
                $assetIds = collect($item->asset_ids ?? [])
                    ->filter(fn ($assetId) => filled($assetId))
                    ->map(fn ($assetId) => (int) $assetId)
                    ->unique()
                    ->values()
                    ->all();

                if ($assetIds === []) {
                    return;
                }

                Asset::query()
                    ->where('organization_id', $this->orgId())
                    ->where('asset_stage', Asset::STAGE_RENTAL_STOCK)
                    ->whereIn('id', $assetIds)
                    ->whereIn('asset_status', [Asset::STATUS_RENTED, Asset::STATUS_RESERVED])
                    ->update(['asset_status' => Asset::STATUS_AWAITING_VERIFICATION]);
            });
    }

    public function create(Request $request)
    {
        return view('assets.create', array_merge($this->formData(), [
            'asset' => new Asset([
                'product_id' => $request->integer('product_id') ?: null,
                'serial_number' => (string) $request->input('serial_number', $request->input('lookup', '')),
                'barcode_value' => (string) $request->input('barcode_value', ''),
                'asset_stage' => (string) $request->input('asset_stage', Asset::STAGE_RENTAL_STOCK),
                'condition_status' => Asset::CONDITION_STATUS_NEW,
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
        $this->validateProductStageCompatibility($product, (string) $validated['asset_stage']);

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

        if ($request->expectsJson()) {
            $createdAsset->loadMissing(['product', 'warehouse']);

            $saleStockAssets = Asset::query()
                ->where('organization_id', $this->orgId())
                ->where('product_id', $createdAsset->product_id)
                ->where('asset_stage', Asset::STAGE_NEW_STOCK)
                ->whereIn('asset_status', Asset::NEW_STOCK_ASSET_STATUSES)
                ->get(['id', 'asset_status']);

            $available = $saleStockAssets->where('asset_status', Asset::STATUS_AVAILABLE_FOR_SALE)->count();
            $reserved = $saleStockAssets->whereIn('asset_status', [Asset::STATUS_RESERVED_FOR_SALE, Asset::STATUS_RESERVED])->count();
            $sold = $saleStockAssets->where('asset_status', Asset::STATUS_SOLD)->count();
            $inTransit = $saleStockAssets->where('asset_status', Asset::STATUS_CONVERTED_TO_RENTAL)->count();

            return response()->json([
                'message' => 'Sale stock added successfully.',
                'asset' => [
                    'id' => $createdAsset->id,
                    'product_id' => $createdAsset->product_id,
                    'serial_number' => $createdAsset->serial_number,
                    'barcode_value' => $createdAsset->barcode_value,
                    'asset_name' => $createdAsset->asset_name,
                    'warehouse_id' => $createdAsset->warehouse_id,
                    'warehouse_name' => $createdAsset->warehouse?->name,
                    'label' => trim(implode(' - ', array_filter([
                        $createdAsset->serial_number ?: $createdAsset->asset_name ?: ('Asset #' . $createdAsset->id),
                        $createdAsset->barcode_value,
                        $createdAsset->product?->name,
                        $createdAsset->warehouse?->name,
                    ]))),
                ],
                'sale_stock_summary' => [
                    'available' => $available,
                    'sold' => $sold,
                    'reserved' => $reserved,
                    'in_transit' => $inTransit,
                    'total' => $available + $sold + $reserved + $inTransit,
                ],
            ], 201);
        }
        if ($request->input('save_action') === 'add_another') {
            return redirect()
                ->route('assets.create', [
                    'asset_stage' => $createdAsset->asset_stage,
                    'product_id' => $createdAsset->product_id,
                ])
                ->with('success', 'Asset created successfully. Add another unit.');
        }

        return redirect()->route('assets.show', $createdAsset)->with('success', 'Asset created successfully.');
    }

    public function bulkStore(Request $request)
    {
        $organizationId = $this->orgId();

        $validated = $request->validate([
            'product_id' => [
                'required',
                Rule::exists('products', 'id')->where(fn ($query) => $query->where('organization_id', $organizationId)),
            ],
            'warehouse_id' => [
                'required',
                Rule::exists('warehouses', 'id')->where(fn ($query) => $query->where('organization_id', $organizationId)),
            ],
            'asset_stage' => ['required', Rule::in(Asset::ASSET_STAGES)],
            'condition_status' => ['required', Rule::in(Asset::CONDITION_STATUSES)],
            'purchase_date' => 'nullable|date',
            'purchase_cost' => 'nullable|numeric|min:0',
            'last_service_date' => 'nullable|date',
            'next_service_date' => 'nullable|date|after_or_equal:last_service_date',
            'notes' => 'nullable|string',
            'serial_numbers' => ['required', 'string'],
        ]);

        $serials = collect(preg_split('/[\s,]+/', (string) $validated['serial_numbers']))
            ->map(fn ($serial) => trim((string) $serial))
            ->filter()
            ->values();

        if ($serials->isEmpty()) {
            throw ValidationException::withMessages([
                'serial_numbers' => ['Add at least one valid serial number.'],
            ]);
        }

        $tooLong = $serials->first(fn ($serial) => mb_strlen($serial) > 255);
        if ($tooLong) {
            throw ValidationException::withMessages([
                'serial_numbers' => ['Serial number "' . $tooLong . '" is too long.'],
            ]);
        }

        $duplicates = $serials
            ->map(fn ($serial) => Str::lower($serial))
            ->duplicates()
            ->unique()
            ->values();

        if ($duplicates->isNotEmpty()) {
            throw ValidationException::withMessages([
                'serial_numbers' => ['Remove duplicate serials in this batch: ' . $duplicates->implode(', ') . '.'],
            ]);
        }

        $existingSerials = Asset::query()
            ->where('organization_id', $organizationId)
            ->whereIn('serial_number', $serials->all())
            ->pluck('serial_number')
            ->all();

        if (!empty($existingSerials)) {
            throw ValidationException::withMessages([
                'serial_numbers' => ['These serial numbers already exist: ' . implode(', ', $existingSerials) . '.'],
            ]);
        }

        $product = Product::query()
            ->where('organization_id', $organizationId)
            ->findOrFail((int) $validated['product_id']);
        $this->validateProductStageCompatibility($product, (string) $validated['asset_stage']);

        $assetStatus = Asset::defaultStatusForStage((string) $validated['asset_stage']);
        $createdCount = DB::transaction(function () use ($serials, $validated, $product, $assetStatus, $organizationId) {
            foreach ($serials as $serial) {
                $asset = Asset::create([
                    'organization_id' => $organizationId,
                    'product_id' => (int) $validated['product_id'],
                    'warehouse_id' => (int) $validated['warehouse_id'],
                    'asset_name' => $product->name,
                    'serial_number' => $serial,
                    'barcode_value' => $serial,
                    'asset_stage' => (string) $validated['asset_stage'],
                    'condition_status' => (string) $validated['condition_status'],
                    'asset_status' => $assetStatus,
                    'purchase_date' => $validated['purchase_date'] ?? null,
                    'purchase_cost' => $validated['purchase_cost'] ?? null,
                    'last_service_date' => $validated['last_service_date'] ?? null,
                    'next_service_date' => $validated['next_service_date'] ?? null,
                    'notes' => $validated['notes'] ?? null,
                ]);

                $this->recordMovement(
                    $asset,
                    null,
                    $asset->warehouse_id,
                    StockMovement::TYPE_ADD_STOCK,
                    'Asset created by bulk stock scan.',
                    null,
                    $asset->asset_status
                );
            }

            SaleInventory::syncFromSaleUnits($organizationId, $product->id);
            $product->syncLegacyStockFields();

            return $serials->count();
        });

        if ($request->input('save_action') === 'add_more') {
            return redirect()
                ->route('assets.create', [
                    'mode' => 'bulk',
                    'asset_stage' => $validated['asset_stage'],
                    'product_id' => $validated['product_id'],
                ])
                ->with('success', $createdCount . ' assets added successfully. Add more serials.');
        }

        return redirect()->route('assets.index')->with('success', $createdCount . ' assets added successfully.');
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

        $asset->load([
            'product.accessoryTemplate.items',
            'warehouse',
            'rentalAssignments.rental.customer',
            'rentalAssignments.rental.businessPartner',
            'rentalAssignments.rental.partnerClient',
            'movements.movedBy',
        ]);

        $rentalAssignment = $this->latestReturnedRentalAssignment($asset);
        $accessoryChecklist = $this->accessoryChecklistForProduct($asset->product);

        return view('assets.verify-return', compact('asset', 'rentalAssignment', 'accessoryChecklist'));
    }

    public function storeReturnVerification(Request $request, Asset $asset)
    {
        $asset = $this->scopedAsset($asset);
        abort_if($asset->asset_stage !== Asset::STAGE_RENTAL_STOCK, 404);
        abort_if($asset->asset_status !== 'awaiting_verification', 404);

        $validated = $request->validate($this->verificationValidationRules($asset));
        $conditionWasProvided = $request->filled('condition_status');
        $validated['condition_status'] = $validated['condition_status']
            ?? $asset->condition_status
            ?? Asset::CONDITION_STATUS_GOOD;
        $accessories = $this->normalizedVerificationAccessories($validated);
        $this->validateReturnVerificationBusinessRules($request, $validated, $accessories);

        if ($request->boolean('save_draft')) {
            $this->storeReturnVerificationDraft($request, $asset, $validated, $accessories);

            return back()->with('success', 'Return verification draft saved.');
        }

        $state = self::VERIFICATION_OUTCOME_TO_STATE[$validated['verification_outcome']];
        $conditionAfter = $state['condition_status'] ?: $validated['condition_status'];

        if (!$conditionWasProvided && $validated['verification_outcome'] === 'repair') {
            $conditionAfter = Asset::CONDITION_STATUS_REPAIR_LEGACY;
        }

        DB::transaction(function () use ($request, $asset, $validated, $state, $conditionAfter, $accessories) {
            $previousStatus = $asset->asset_status;
            $previousCondition = $asset->condition_status;
            $rentalAssignment = $this->latestReturnedRentalAssignment($asset->loadMissing([
                'rentalAssignments.rental',
            ]));

            $asset->update([
                'serial_number' => trim((string) $validated['serial_number']),
                'barcode_value' => filled($validated['barcode_value'] ?? null) ? trim((string) $validated['barcode_value']) : null,
                'condition_status' => $conditionAfter,
                'asset_status' => $state['asset_status'],
                'notes' => $this->verificationNotes(
                    $asset->notes,
                    filled($validated['manufacturing_year'] ?? null) ? (int) $validated['manufacturing_year'] : null,
                    $validated['remarks'] ?? null,
                    $validated['model_variant'] ?? null,
                    $accessories
                ),
            ]);

            $asset->refresh();

            $verification = null;
            if (Schema::hasTable('return_verifications')) {
                $verification = ReturnVerification::create([
                    'asset_id' => $asset->id,
                    'rental_id' => $rentalAssignment?->rental_id,
                    'verified_by' => auth()->id(),
                    'condition_before' => $previousCondition,
                    'condition_after' => $conditionAfter,
                    'outcome' => (string) $validated['verification_outcome'],
                    'remarks' => $validated['remarks'] ?? null,
                    'verified_at' => now(),
                ]);

                if (Schema::hasTable('return_verification_accessories')) {
                    foreach ($accessories as $row) {
                        $verification->accessories()->create([
                            'accessory_name' => $row['name'],
                            'status' => $row['status'],
                            'remarks' => $row['remarks'],
                        ]);
                    }
                }

                if (Schema::hasTable('return_verification_photos')) {
                    foreach ((array) $request->file('photos', []) as $type => $file) {
                        if (!$file) {
                            continue;
                        }

                        $verification->photos()->create([
                            'photo_type' => (string) $type,
                            'path' => $file->store('return-verifications/' . $verification->id, 'public'),
                        ]);
                    }
                }
            }

            $this->recordMovement(
                $asset,
                $asset->warehouse_id,
                $asset->warehouse_id,
                match ((string) $validated['verification_outcome']) {
                    'repair' => StockMovement::TYPE_REPAIR,
                    'scrap', 'retire' => StockMovement::TYPE_SCRAP,
                    default => StockMovement::TYPE_RETURN_VERIFICATION,
                },
                trim(collect([
                    'Returned asset verified as ' . str_replace('_', ' ', (string) $validated['verification_outcome']) . '.',
                    filled($validated['manufacturing_year'] ?? null) ? 'Manufacturing year: ' . $validated['manufacturing_year'] . '.' : null,
                    filled($validated['model_variant'] ?? null) ? 'Model / variant: ' . trim((string) $validated['model_variant']) . '.' : null,
                    $this->hasAccessoryIssue($accessories) ? 'Accessory issue recorded.' : null,
                    filled($validated['remarks'] ?? null) ? 'Remarks: ' . trim((string) $validated['remarks']) : null,
                ])->filter()->implode(' ')),
                $previousStatus,
                $state['asset_status']
            );

            SaleInventory::syncFromSaleUnits($this->orgId(), $asset->product_id);
            $asset->product?->syncLegacyStockFields();

            if ($validated['verification_outcome'] === 'missing_components') {
                $this->createMissingComponentsFollowUp($asset, $rentalAssignment?->rental, $accessories, $validated['remarks'] ?? null);
            }
        });

        return redirect()->route('assets.pending-verification')->with('success', 'Returned asset verified successfully.');
    }

    private function createMissingComponentsFollowUp(Asset $asset, $rental, array $accessories, ?string $remarks): void
    {
        if (!Schema::hasTable('follow_ups')) {
            return;
        }

        $missing = collect($accessories)
            ->filter(fn (array $row) => in_array($row['status'], [
                ReturnVerificationAccessory::STATUS_MISSING,
                ReturnVerificationAccessory::STATUS_DAMAGED,
            ], true))
            ->map(fn (array $row) => $row['name'] . ' (' . str_replace('_', ' ', $row['status']) . ')')
            ->implode(', ');

        FollowUp::create([
            'organization_id' => $asset->organization_id,
            'customer_id' => $rental?->customer_id,
            'business_partner_id' => $rental?->business_partner_id,
            'partner_client_id' => $rental?->partner_client_id,
            'rental_id' => $rental?->id,
            'assigned_user_id' => null,
            'followup_type' => FollowUp::TYPE_ESCALATION,
            'title' => 'Resolve missing return components for ' . ($asset->serial_number ?: ('Asset #' . $asset->id)),
            'note' => trim(collect([
                $missing !== '' ? 'Accessory issues: ' . $missing . '.' : null,
                filled($remarks) ? 'Verification remarks: ' . trim((string) $remarks) : null,
            ])->filter()->implode(' ')),
            'due_at' => now()->addDay(),
            'status' => FollowUp::STATUS_PENDING,
            'priority' => FollowUp::PRIORITY_HIGH,
            'created_by_user_id' => auth()->id(),
            'is_system_generated' => true,
            'source' => 'return_verification',
        ]);
    }

    private function storeReturnVerificationDraft(Request $request, Asset $asset, array $validated, array $accessories): void
    {
        if (!Schema::hasTable('return_verifications')) {
            return;
        }

        $asset->loadMissing(['rentalAssignments.rental']);
        $rentalAssignment = $this->latestReturnedRentalAssignment($asset);

        DB::transaction(function () use ($request, $asset, $validated, $accessories, $rentalAssignment) {
            $verification = ReturnVerification::create([
                'asset_id' => $asset->id,
                'rental_id' => $rentalAssignment?->rental_id,
                'verified_by' => auth()->id(),
                'condition_before' => $asset->condition_status,
                'condition_after' => $validated['condition_status'],
                'outcome' => 'draft',
                'remarks' => $validated['remarks'] ?? null,
                'verified_at' => null,
            ]);

            if (Schema::hasTable('return_verification_accessories')) {
                foreach ($accessories as $row) {
                    $verification->accessories()->create([
                        'accessory_name' => $row['name'],
                        'status' => $row['status'],
                        'remarks' => $row['remarks'],
                    ]);
                }
            }

            if (Schema::hasTable('return_verification_photos')) {
                foreach ((array) $request->file('photos', []) as $type => $file) {
                    if ($file) {
                        $verification->photos()->create([
                            'photo_type' => (string) $type,
                            'path' => $file->store('return-verifications/' . $verification->id, 'public'),
                        ]);
                    }
                }
            }
        });
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
        $product = Product::query()
            ->where('organization_id', $this->orgId())
            ->findOrFail((int) $validated['product_id']);
        $this->validateProductStageCompatibility($product, (string) $validated['asset_stage']);
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
