<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RentalSaleItem extends Model
{
    protected $fillable = [
        'organization_id',
        'rental_id',
        'product_id',
        'asset_id',
        'warehouse_id',
        'quantity',
        'unit_price',
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
        'asset_id' => 'integer',
        'warehouse_id' => 'integer',
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
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

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
