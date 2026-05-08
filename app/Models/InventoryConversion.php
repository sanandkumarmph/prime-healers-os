<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryConversion extends Model
{
    public const TYPE_SALE_TO_RENTAL = 'sale_to_rental';
    public const TYPE_RENTAL_TO_SALE = 'rental_to_sale';

    protected $fillable = [
        'organization_id',
        'product_id',
        'warehouse_id',
        'conversion_type',
        'quantity_converted',
        'sale_stock_before',
        'sale_stock_after',
        'remarks',
        'converted_by',
    ];

    protected $casts = [
        'quantity_converted' => 'integer',
        'sale_stock_before' => 'integer',
        'sale_stock_after' => 'integer',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function convertedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'converted_by');
    }
}
