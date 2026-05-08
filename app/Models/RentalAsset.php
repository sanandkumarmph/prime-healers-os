<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class RentalAsset extends Model
{
    protected static ?bool $hasTable = null;

    protected $fillable = [
        'organization_id',
        'rental_id',
        'asset_id',
        'assigned_at',
        'returned_at',
        'return_condition',
        'notes',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'returned_at' => 'datetime',
    ];

    public static function hasTable(): bool
    {
        return static::$hasTable ??= Schema::hasTable('rental_assets');
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function rental()
    {
        return $this->belongsTo(Rental::class);
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }
}
