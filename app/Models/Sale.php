<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Model;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Organization;
use App\Models\Asset;
use App\Models\Rental;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Sale extends Model
{
    protected $fillable = [
        'customer_id',
        'product_id',
        'asset_id',
        'rental_id',
        'auto_generated_from_rental',
        'warehouse_id',
        'quantity',
        'unit_price',
        'discount_amount',
        'shipping_charges',
        'tax_percentage',
        'tax_calculation_mode',
        'sale_date',
        'sale_amount',
        'payment_status',
        'notes',
        'organization_id',
        'stock_applied',
        'created_by',
        'created_by_user_id',
    ];

    protected $casts = [
        'sale_date' => 'date',
        'auto_generated_from_rental' => 'boolean',
        'warehouse_id' => 'integer',
        'unit_price' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'shipping_charges' => 'decimal:2',
        'tax_percentage' => 'decimal:2',
        'sale_amount' => 'decimal:2',
        'stock_applied' => 'boolean',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function saleUnits(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class, 'sale_assets')
            ->withPivot(['organization_id'])
            ->withTimestamps();
    }

    public function rental()
    {
        return $this->belongsTo(Rental::class);
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function deliveries()
    {
        return $this->hasMany(Delivery::class);
    }

    public function deliveryRecord()
    {
        return $this->hasOne(Delivery::class)->where('type', 'delivery')->latestOfMany();
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function gstLabel(): string
    {
        return ($this->tax_calculation_mode ?? 'exclusive') === 'inclusive'
            ? 'GST Inclusive'
            : 'GST Exclusive';
    }
}
