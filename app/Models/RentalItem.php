<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class RentalItem extends Model
{
    protected $fillable = [
        'organization_id',
        'rental_id',
        'product_id',
        'asset_ids',
        'quantity',
        'start_date',
        'end_date',
        'duration_days',
        'ordered_quantity',
        'delivered_quantity',
        'returned_quantity',
        'unit_rental_amount',
        'gst_rate',
        'gst_mode',
        'tax_type',
        'taxable_amount',
        'cgst_amount',
        'sgst_amount',
        'igst_amount',
        'line_total',
        'notes',
    ];

    protected $casts = [
        'asset_ids' => 'array',
        'quantity' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
        'duration_days' => 'integer',
        'ordered_quantity' => 'integer',
        'delivered_quantity' => 'integer',
        'returned_quantity' => 'integer',
        'unit_rental_amount' => 'decimal:2',
        'gst_rate' => 'decimal:2',
        'taxable_amount' => 'decimal:2',
        'cgst_amount' => 'decimal:2',
        'sgst_amount' => 'decimal:2',
        'igst_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    public function rental(): BelongsTo
    {
        return $this->belongsTo(Rental::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function linkedAssets(): Collection
    {
        $assetIds = collect($this->asset_ids ?? [])
            ->filter(fn ($assetId) => filled($assetId))
            ->map(fn ($assetId) => (int) $assetId)
            ->filter(fn ($assetId) => $assetId > 0)
            ->values();

        if ($assetIds->isEmpty()) {
            return collect();
        }

        return Asset::query()
            ->with('warehouse')
            ->where('organization_id', $this->organization_id)
            ->whereIn('id', $assetIds->all())
            ->orderBy('serial_number')
            ->get();
    }

    public function getOrderedQuantityAttribute(): int
    {
        return max((int) ($this->attributes['ordered_quantity'] ?? $this->quantity ?? 0), 0);
    }

    public function getDeliveredQuantityValueAttribute(): int
    {
        return min(max((int) ($this->delivered_quantity ?? 0), 0), $this->ordered_quantity);
    }

    public function getReturnedQuantityValueAttribute(): int
    {
        return min(max((int) ($this->returned_quantity ?? 0), 0), $this->delivered_quantity_value);
    }

    public function getPendingDeliveryQuantityAttribute(): int
    {
        return max($this->ordered_quantity - $this->delivered_quantity_value, 0);
    }

    public function getPendingPickupQuantityAttribute(): int
    {
        return max($this->delivered_quantity_value - $this->returned_quantity_value, 0);
    }

    public function getDeliveryProgressStatusAttribute(): string
    {
        if ($this->delivered_quantity_value <= 0) {
            return 'pending';
        }

        if ($this->pending_delivery_quantity > 0) {
            return 'partial';
        }

        return 'delivered';
    }

    public function getPickupProgressStatusAttribute(): string
    {
        if ($this->returned_quantity_value <= 0) {
            return 'pending';
        }

        if ($this->pending_pickup_quantity > 0) {
            return 'partial';
        }

        return 'picked_up';
    }
}
