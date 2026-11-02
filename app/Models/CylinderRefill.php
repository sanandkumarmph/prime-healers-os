<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
class CylinderRefill extends Model {
    protected $guarded=['id'];
    protected $casts=['incoming_asset_ids'=>'array','outgoing_asset_ids'=>'array','scheduled_at'=>'datetime','completed_at'=>'datetime',
        'quantity'=>'integer','delivered_quantity'=>'integer','collected_quantity'=>'integer','received_quantity'=>'integer','refilled_quantity'=>'integer',
        'lock_version'=>'integer','refill_charge'=>'decimal:2','transport_charge'=>'decimal:2','tax_rate'=>'decimal:2'];
    public static function available(): bool { return Schema::hasTable('cylinder_refills'); }
    public function rental(){return $this->belongsTo(Rental::class);}
    public function product(){return $this->belongsTo(Product::class);}
    public function assignee(){return $this->belongsTo(User::class,'assigned_user_id');}
    public function invoice(){return $this->belongsTo(Invoice::class);}
    public function events(){return $this->hasMany(CylinderRefillEvent::class);}
    public function getPendingEmptyQuantityAttribute(): int {return max(0,$this->quantity-$this->received_quantity);}
}
