<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CylinderRefillEvent extends Model {
    protected $guarded=['id'];protected $casts=['details'=>'array'];
    protected static function booted(): void {
        static::updating(fn()=>throw new \LogicException('Refill history cannot be edited.'));
        static::deleting(fn()=>throw new \LogicException('Refill history cannot be deleted.'));
    }
    public function refill(){return $this->belongsTo(CylinderRefill::class,'cylinder_refill_id');}
    public function user(){return $this->belongsTo(User::class);}
}
