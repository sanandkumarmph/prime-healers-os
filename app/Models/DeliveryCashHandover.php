<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryCashHandover extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['submitted_amount' => 'decimal:2', 'received_amount' => 'decimal:2',
        'submitted_at' => 'datetime', 'confirmed_at' => 'datetime', 'corrected_at' => 'datetime'];

    public function collection() { return $this->belongsTo(DeliveryCollection::class, 'collection_id'); }
    public function collector() { return $this->belongsTo(User::class, 'collector_user_id'); }
    public function receiver() { return $this->belongsTo(User::class, 'receiver_user_id'); }
}
