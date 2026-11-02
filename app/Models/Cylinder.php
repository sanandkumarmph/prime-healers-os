<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class Cylinder extends Model
{
    protected $fillable = ['organization_id', 'asset_id', 'contents', 'custody', 'reserved_for_rental_id'];
    public static function hasTable(): bool { return Schema::hasTable('cylinders'); }
    public function asset() { return $this->belongsTo(Asset::class); }
    public function reservedRental() { return $this->belongsTo(Rental::class, 'reserved_for_rental_id'); }
    public function events() { return $this->hasMany(CylinderEvent::class); }
}
