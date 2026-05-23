<?php

namespace App\Models;

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    public const TYPE_SELLABLE = 'sellable';
    public const TYPE_RENTABLE = 'rentable';
    public const STOCK_MODE_UNTRACKED = 'untracked';
    public const STOCK_MODE_TRACKED_SALE = 'tracked_sale';
    public const STOCK_MODE_TRACKED_RENTAL = 'tracked_rental';
    public const STOCK_MODE_TRACKED_BOTH = 'tracked_both';

    public const PRODUCT_TYPES = [
        self::TYPE_SELLABLE,
        self::TYPE_RENTABLE,
    ];

    public const STOCK_MODES = [
        self::STOCK_MODE_UNTRACKED,
        self::STOCK_MODE_TRACKED_SALE,
        self::STOCK_MODE_TRACKED_RENTAL,
        self::STOCK_MODE_TRACKED_BOTH,
    ];

    public const GST_TAX_TYPE_CGST_SGST = 'cgst_sgst';
    public const GST_TAX_TYPE_IGST = 'igst';
    public const GST_TAX_TYPES = [
        self::GST_TAX_TYPE_CGST_SGST,
        self::GST_TAX_TYPE_IGST,
    ];
    public const GST_CALCULATION_MODES = [
        'exclusive',
        'inclusive',
    ];

    protected $fillable = [
        'name',
        'category',
        'brand',
        'model_name',
        'product_code',
        'sku',
        'product_type',
        'stock_mode',
        'total_quantity',
        'available_quantity',
        'price_per_day',
        'rental_price_15_days',
        'rental_price_30_days',
        'rental_price_3_months',
        'sale_price',
        'rental_price',
        'gst_tax_type',
        'gst_calculation_mode',
        'cgst_rate',
        'sgst_rate',
        'igst_rate',
        'is_sellable',
        'is_rentable',
        'organization_id',
    ];

    protected $casts = [
        'price_per_day' => 'decimal:2',
        'rental_price_15_days' => 'decimal:2',
        'rental_price_30_days' => 'decimal:2',
        'rental_price_3_months' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'rental_price' => 'decimal:2',
        'cgst_rate' => 'decimal:2',
        'sgst_rate' => 'decimal:2',
        'igst_rate' => 'decimal:2',
        'is_sellable' => 'boolean',
        'is_rentable' => 'boolean',
    ];

    public function getDisplayModelAttribute(): ?string
    {
        $modelName = trim((string) ($this->model_name ?? ''));
        if ($modelName !== '') {
            return $modelName;
        }

        $legacyModel = trim((string) ($this->getAttributeFromArray('model') ?? ''));

        return $legacyModel !== '' ? $legacyModel : null;
    }

    protected static function booted(): void
    {
        static::saving(function (Product $product) {
            $type = $product->product_type ?: ($product->is_rentable ? self::TYPE_RENTABLE : self::TYPE_SELLABLE);
            $product->product_type = in_array($type, self::PRODUCT_TYPES, true) ? $type : self::TYPE_SELLABLE;
            $product->is_sellable = $product->product_type === self::TYPE_SELLABLE;
            $product->is_rentable = $product->product_type === self::TYPE_RENTABLE;
            $stockMode = $product->stock_mode ?: self::STOCK_MODE_UNTRACKED;
            $product->stock_mode = in_array($stockMode, self::STOCK_MODES, true) ? $stockMode : self::STOCK_MODE_UNTRACKED;
            $product->gst_tax_type = in_array($product->gst_tax_type, self::GST_TAX_TYPES, true)
                ? $product->gst_tax_type
                : null;
            $product->gst_calculation_mode = in_array($product->gst_calculation_mode, self::GST_CALCULATION_MODES, true)
                ? $product->gst_calculation_mode
                : 'exclusive';
        });
    }

    public function rentals()
    {
        return $this->hasMany(Rental::class);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function assets()
    {
        return $this->hasMany(Asset::class);
    }

    public function saleInventories(): HasMany
    {
        return $this->hasMany(SaleInventory::class);
    }

    public function saleUnits(): HasMany
    {
        return $this->hasMany(Asset::class)
            ->where('asset_stage', Asset::STAGE_NEW_STOCK);
    }

    public function rentalUnits(): HasMany
    {
        return $this->hasMany(Asset::class)
            ->where('asset_stage', Asset::STAGE_RENTAL_STOCK);
    }

    public function inventoryConversions(): HasMany
    {
        return $this->hasMany(InventoryConversion::class);
    }

    public function isSellableProduct(): bool
    {
        return $this->product_type === self::TYPE_SELLABLE;
    }

    public function isRentableProduct(): bool
    {
        return $this->product_type === self::TYPE_RENTABLE;
    }

    public function canSell(): bool
    {
        if ((bool) ($this->is_sellable ?? false) || $this->product_type === self::TYPE_SELLABLE) {
            return true;
        }

        if ($this->tracksSaleStock()) {
            return true;
        }

        if ($this->relationLoaded('saleUnits')) {
            return $this->saleUnits->isNotEmpty();
        }

        return $this->saleUnits()
            ->where('organization_id', $this->organization_id)
            ->exists();
    }

    public function canRent(): bool
    {
        if ((bool) ($this->is_rentable ?? false) || $this->product_type === self::TYPE_RENTABLE) {
            return true;
        }

        if ($this->tracksRentalStock()) {
            return true;
        }

        if ($this->usesUntrackedStock() && (int) ($this->available_quantity ?? 0) > 0) {
            return true;
        }

        if ($this->relationLoaded('rentalUnits')) {
            return $this->rentalUnits->isNotEmpty();
        }

        return $this->rentalUnits()
            ->where('organization_id', $this->organization_id)
            ->exists();
    }

    public function isRentalEligibleForSelection(): bool
    {
        return $this->product_type === self::TYPE_RENTABLE || $this->tracksRentalStock();
    }

    public function usesUntrackedStock(): bool
    {
        return $this->stock_mode === self::STOCK_MODE_UNTRACKED;
    }

    public function tracksSaleStock(): bool
    {
        return in_array($this->stock_mode, [
            self::STOCK_MODE_TRACKED_SALE,
            self::STOCK_MODE_TRACKED_BOTH,
        ], true);
    }

    public function tracksRentalStock(): bool
    {
        return in_array($this->stock_mode, [
            self::STOCK_MODE_TRACKED_RENTAL,
            self::STOCK_MODE_TRACKED_BOTH,
        ], true);
    }

    public function hasTrackedStock(): bool
    {
        return $this->stock_mode !== self::STOCK_MODE_UNTRACKED;
    }

    public function stockModeLabel(): string
    {
        return match ($this->stock_mode) {
            self::STOCK_MODE_TRACKED_SALE => 'Tracked Sale',
            self::STOCK_MODE_TRACKED_RENTAL => 'Tracked Rental',
            self::STOCK_MODE_TRACKED_BOTH => 'Tracked Both',
            default => 'Untracked',
        };
    }

    public function gstTaxTypeLabel(): ?string
    {
        return match ($this->gst_tax_type) {
            self::GST_TAX_TYPE_CGST_SGST => 'CGST + SGST',
            self::GST_TAX_TYPE_IGST => 'IGST',
            default => null,
        };
    }

    public function gstRateSummary(): ?string
    {
        return match ($this->gst_tax_type) {
            self::GST_TAX_TYPE_CGST_SGST => 'CGST '.number_format((float) ($this->cgst_rate ?? 0), 2).'%' .
                ' + SGST '.number_format((float) ($this->sgst_rate ?? 0), 2).'%',
            self::GST_TAX_TYPE_IGST => 'IGST '.number_format((float) ($this->igst_rate ?? 0), 2).'%',
            default => null,
        };
    }

    public static function resolveStockModeFromInventoryCounts(int $saleUnitCount, int $rentalUnitCount, ?string $fallback = null): string
    {
        if ($saleUnitCount > 0 && $rentalUnitCount > 0) {
            return self::STOCK_MODE_TRACKED_BOTH;
        }

        if ($saleUnitCount > 0) {
            return self::STOCK_MODE_TRACKED_SALE;
        }

        if ($rentalUnitCount > 0) {
            return self::STOCK_MODE_TRACKED_RENTAL;
        }

        if (in_array($fallback, self::STOCK_MODES, true)) {
            return $fallback;
        }

        return self::STOCK_MODE_UNTRACKED;
    }

    public function syncLegacyStockFields(): void
    {
        SaleInventory::syncFromSaleUnits($this->organization_id, $this->id);

        [$saleSummaryTotal, $saleAvailable] = $this->saleStockSummary();
        [$rentalTotal, $rentalAvailable] = $this->rentalStockSummary();
        $resolvedStockMode = in_array($this->stock_mode, self::STOCK_MODES, true)
            ? $this->stock_mode
            : self::resolveStockModeFromInventoryCounts(
                (int) $this->saleUnits()->where('organization_id', $this->organization_id)->count(),
                (int) $this->rentalUnits()->where('organization_id', $this->organization_id)->count(),
                $this->stock_mode
            );

        [$resolvedTotal, $resolvedAvailable] = match ($resolvedStockMode) {
            self::STOCK_MODE_TRACKED_SALE => [
                $saleSummaryTotal,
                $saleAvailable,
            ],
            self::STOCK_MODE_TRACKED_RENTAL => [
                $rentalTotal,
                $rentalAvailable,
            ],
            self::STOCK_MODE_TRACKED_BOTH => [
                $rentalTotal,
                $rentalAvailable,
            ],
            default => [
                max((int) ($this->total_quantity ?? 0), 0),
                min(max((int) ($this->available_quantity ?? 0), 0), max((int) ($this->total_quantity ?? 0), 0)),
            ],
        };

        $this->forceFill([
            'stock_mode' => $resolvedStockMode,
            'total_quantity' => $resolvedTotal,
            'available_quantity' => $resolvedAvailable,
        ])->saveQuietly();
    }

    public function saleStockSummary(): array
    {
        $total = (int) $this->saleUnits()
            ->where('organization_id', $this->organization_id)
            ->count();
        $available = (int) $this->saleUnits()
            ->where('organization_id', $this->organization_id)
            ->where('asset_status', Asset::STATUS_AVAILABLE_FOR_SALE)
            ->count();

        return [
            max($total, 0),
            max($available, 0),
        ];
    }

    public function rentalStockSummary(): array
    {
        $total = (int) $this->rentalUnits()
            ->where('organization_id', $this->organization_id)
            ->count();
        $available = (int) $this->rentalUnits()
            ->where('organization_id', $this->organization_id)
            ->rentalReady()
            ->count();

        return [
            max($total, 0),
            max($available, 0),
        ];
    }

    public function nextUnitCodePrefix(): string
    {
        return $this->sku
            ?: $this->product_code
            ?: strtoupper(preg_replace('/[^A-Z0-9]+/', '-', substr($this->name, 0, 18)));
    }

    public function nextSaleUnitCode(int $sequence): string
    {
        return rtrim($this->nextUnitCodePrefix(), '-') . '-' . str_pad((string) $sequence, 3, '0', STR_PAD_LEFT);
    }

    public function nextAvailableSaleUnitSequence(): int
    {
        $prefix = rtrim($this->nextUnitCodePrefix(), '-') . '-';

        $maxSequence = (int) $this->saleUnits()
            ->where('organization_id', $this->organization_id)
            ->where('serial_number', 'like', $prefix . '%')
            ->selectRaw("MAX(CAST(SUBSTRING_INDEX(serial_number, '-', -1) AS UNSIGNED)) as seq")
            ->value('seq');

        return $maxSequence + 1;
    }
}
