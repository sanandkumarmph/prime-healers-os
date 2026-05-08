<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssetMovement extends Model
{
    public const MOVEMENT_TYPES = ['inward', 'outward', 'transfer', 'assigned', 'returned', 'maintenance'];

    protected $fillable = [
        'organization_id',
        'asset_id',
        'from_warehouse_id',
        'to_warehouse_id',
        'movement_type',
        'remarks',
        'moved_by',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function fromWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function movedBy()
    {
        return $this->belongsTo(User::class, 'moved_by');
    }
}
