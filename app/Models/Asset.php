<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class Asset extends Model
{
    public const SERIAL_PENDING_PREFIX = 'PENDING-';
    public const STAGE_NEW_STOCK = 'new_stock';
    public const STAGE_RENTAL_STOCK = 'rental_stock';
    public const STATUS_AVAILABLE = 'available';
    public const STATUS_AWAITING_VERIFICATION = 'awaiting_verification';
    public const STATUS_RENTED = 'rented';
    public const STATUS_RESERVED = 'reserved';
    public const STATUS_MAINTENANCE = 'maintenance';
    public const STATUS_DAMAGED = 'damaged';
    public const STATUS_AWAITING_RESOLUTION = 'awaiting_resolution';
    public const STATUS_RETIRED = 'retired';
    public const STATUS_AVAILABLE_FOR_SALE = 'available_for_sale';
    public const STATUS_RESERVED_FOR_SALE = 'reserved_for_sale';
    public const STATUS_SOLD = 'sold';
    public const STATUS_CONVERTED_TO_RENTAL = 'converted_to_rental';

    public const ASSET_STAGES = [
        self::STAGE_NEW_STOCK,
        self::STAGE_RENTAL_STOCK,
    ];

    public const CONDITION_STATUS_NEW = 'new';
    public const CONDITION_STATUS_GOOD = 'good';
    public const CONDITION_STATUS_FAIR = 'fair';
    public const CONDITION_STATUS_NEEDS_REPAIR = 'needs_repair';
    public const CONDITION_STATUS_DAMAGED = 'damaged';
    public const CONDITION_STATUS_RETIRED = 'retired';
    public const CONDITION_STATUS_REPAIR_LEGACY = 'repair';
    public const CONDITION_STATUS_INACTIVE_LEGACY = 'inactive';

    public const CONDITION_STATUSES = [
        self::CONDITION_STATUS_NEW,
        self::CONDITION_STATUS_GOOD,
        self::CONDITION_STATUS_FAIR,
        self::CONDITION_STATUS_NEEDS_REPAIR,
        self::CONDITION_STATUS_DAMAGED,
        self::CONDITION_STATUS_RETIRED,
        self::CONDITION_STATUS_REPAIR_LEGACY,
        self::CONDITION_STATUS_INACTIVE_LEGACY,
    ];

    public const RENTAL_ASSET_STATUSES = [
        self::STATUS_AVAILABLE,
        self::STATUS_AWAITING_VERIFICATION,
        self::STATUS_RENTED,
        self::STATUS_RESERVED,
        self::STATUS_MAINTENANCE,
        self::STATUS_DAMAGED,
        self::STATUS_AWAITING_RESOLUTION,
        self::STATUS_RETIRED,
    ];

    public const NEW_STOCK_ASSET_STATUSES = [
        self::STATUS_AVAILABLE_FOR_SALE,
        self::STATUS_RESERVED_FOR_SALE,
        self::STATUS_SOLD,
        self::STATUS_CONVERTED_TO_RENTAL,
    ];

    public const ASSET_STATUSES = [
        ...self::RENTAL_ASSET_STATUSES,
        ...self::NEW_STOCK_ASSET_STATUSES,
    ];

    protected $fillable = [
        'organization_id',
        'product_id',
        'warehouse_id',
        'asset_name',
        'serial_number',
        'barcode_value',
        'batch_number',
        'asset_stage',
        'purchase_date',
        'purchase_cost',
        'condition_status',
        'asset_status',
        'notes',
        'last_service_date',
        'next_service_date',
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'purchase_cost' => 'decimal:2',
        'last_service_date' => 'date',
        'next_service_date' => 'date',
    ];

    public static function statusesForStage(string $stage): array
    {
        return $stage === self::STAGE_NEW_STOCK
            ? self::NEW_STOCK_ASSET_STATUSES
            : self::RENTAL_ASSET_STATUSES;
    }

    public static function defaultStatusForStage(string $stage): string
    {
        return $stage === self::STAGE_NEW_STOCK ? self::STATUS_AVAILABLE_FOR_SALE : self::STATUS_AVAILABLE;
    }

    public function isNewStock(): bool
    {
        return $this->asset_stage === self::STAGE_NEW_STOCK;
    }

    public function isRentalStock(): bool
    {
        return $this->asset_stage === self::STAGE_RENTAL_STOCK;
    }

    public function rentalWorkflowControlsStatus(): bool
    {
        return $this->isRentalStock()
            && in_array($this->asset_status, [
                self::STATUS_RESERVED,
                self::STATUS_RENTED,
                self::STATUS_AWAITING_VERIFICATION,
            ], true);
    }

    public function saleWorkflowControlsStatus(): bool
    {
        return $this->isNewStock()
            && in_array($this->asset_status, [
                self::STATUS_RESERVED_FOR_SALE,
                self::STATUS_SOLD,
            ], true);
    }

    public function isSerialPending(): bool
    {
        $serial = strtoupper(trim((string) $this->serial_number));

        return $serial !== '' && str_starts_with($serial, self::SERIAL_PENDING_PREFIX);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function movements()
    {
        return $this->hasMany(AssetMovement::class)->latest();
    }

    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class)->latest('movement_at');
    }

    public function rentalAssignments()
    {
        return $this->hasMany(RentalAsset::class);
    }

    public function activeRentalAssignments()
    {
        return $this->hasMany(RentalAsset::class)->whereNull('returned_at');
    }

    public function rentals()
    {
        return $this->belongsToMany(Rental::class, 'rental_assets')
            ->withPivot(['organization_id', 'assigned_at', 'returned_at', 'return_condition', 'notes'])
            ->withTimestamps();
    }

    public function sales(): BelongsToMany
    {
        return $this->belongsToMany(Sale::class, 'sale_assets')
            ->withPivot(['organization_id'])
            ->withTimestamps();
    }

    public function scopeRentalReady(Builder $query): Builder
    {
        $query
            ->where('asset_stage', self::STAGE_RENTAL_STOCK)
            ->where('asset_status', self::STATUS_AVAILABLE)
            ->where(function (Builder $conditionQuery) {
                $conditionQuery
                    ->whereNull('condition_status')
                    ->orWhereNotIn('condition_status', [
                        self::CONDITION_STATUS_REPAIR_LEGACY,
                        self::CONDITION_STATUS_NEEDS_REPAIR,
                        self::CONDITION_STATUS_DAMAGED,
                        self::CONDITION_STATUS_INACTIVE_LEGACY,
                        self::CONDITION_STATUS_RETIRED,
                    ]);
            });

        if (RentalAsset::hasTable()) {
            $query->whereDoesntHave('activeRentalAssignments');
        }

        return $query;
    }
}
