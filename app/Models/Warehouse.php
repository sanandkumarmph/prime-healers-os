<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class Warehouse extends Model
{
    protected $fillable = [
        'organization_id',
        'name',
        'code',
        'address',
        'city_id',
        'city',
        'state',
        'pincode',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function cityRecord()
    {
        return $this->belongsTo(City::class, 'city_id');
    }

    public function assets()
    {
        return $this->hasMany(Asset::class);
    }

    public function saleInventories(): HasMany
    {
        return $this->hasMany(SaleInventory::class);
    }

    public function inventoryConversions(): HasMany
    {
        return $this->hasMany(InventoryConversion::class);
    }

    public function incomingMovements()
    {
        return $this->hasMany(AssetMovement::class, 'to_warehouse_id');
    }

    public function outgoingMovements()
    {
        return $this->hasMany(AssetMovement::class, 'from_warehouse_id');
    }

    public function dispatchedRentals()
    {
        return $this->hasMany(Rental::class, 'dispatch_warehouse_id');
    }
}
