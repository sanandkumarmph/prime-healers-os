<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleItem extends Model
{
    protected $fillable = [
        'organization_id',
        'sale_id',
        'product_id',
        'asset_id',
        'warehouse_id',
        'quantity',
        'unit_price',
        'discount_amount',
        'shipping_charges',
        'tax_percentage',
        'tax_calculation_mode',
        'taxable_amount',
        'total_tax_amount',
        'line_total',
        'sort_order',
        'asset_ids',
        'notes',
    ];

    protected $casts = [
        'asset_id' => 'integer',
        'warehouse_id' => 'integer',
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'shipping_charges' => 'decimal:2',
        'tax_percentage' => 'decimal:2',
        'taxable_amount' => 'decimal:2',
        'total_tax_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
        'sort_order' => 'integer',
        'asset_ids' => 'array',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
