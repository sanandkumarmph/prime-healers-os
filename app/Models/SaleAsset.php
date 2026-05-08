<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleAsset extends Model
{
    protected $fillable = [
        'organization_id',
        'sale_id',
        'asset_id',
    ];

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }
}
