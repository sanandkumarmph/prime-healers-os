<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CylinderJob extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['scheduled_at' => 'datetime', 'collected_at' => 'datetime', 'warehouse_received_at' => 'datetime',
        'refill_sent_at' => 'datetime', 'refilled_at' => 'datetime', 'delivered_at' => 'datetime', 'completed_at' => 'datetime',
        'refill_charge' => 'decimal:2', 'transport_charge' => 'decimal:2', 'tax_rate' => 'decimal:2'];
    public function rental() { return $this->belongsTo(Rental::class); }
    public function incoming() { return $this->belongsTo(Cylinder::class, 'incoming_cylinder_id'); }
    public function outgoing() { return $this->belongsTo(Cylinder::class, 'outgoing_cylinder_id'); }
    public function assignee() { return $this->belongsTo(User::class, 'assigned_user_id'); }
    public function invoice() { return $this->belongsTo(Invoice::class); }
    public function events() { return $this->hasMany(CylinderEvent::class); }
}
