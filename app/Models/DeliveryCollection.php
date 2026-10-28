<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryCollection extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['amount' => 'decimal:2', 'received_at' => 'datetime', 'reviewed_at' => 'datetime', 'reversed_at' => 'datetime'];

    public function delivery() { return $this->belongsTo(Delivery::class); }
    public function invoice() { return $this->belongsTo(Invoice::class); }
    public function collector() { return $this->belongsTo(User::class, 'collector_user_id'); }
    public function reviewer() { return $this->belongsTo(User::class, 'reviewed_by_user_id'); }
    public function payment() { return $this->belongsTo(Payment::class); }
    public function proof() { return $this->belongsTo(DeliveryProof::class); }
    public function handovers() { return $this->hasMany(DeliveryCashHandover::class, 'collection_id'); }

    /** Integer minor units. Mutations must lock the collection and handovers. */
    public function cashAmounts(): array
    {
        $cents = fn ($value) => \App\Services\Deliveries\DeliveryCollectionService::cents($value);
        $collected = $this->status === 'cash_received' ? $cents($this->amount) : 0;
        $confirmed = $this->handovers->where('status', 'confirmed')->sum(fn ($row) => $cents($row->received_amount));
        $pending = $this->handovers->where('status', 'pending')->sum(fn ($row) => $cents($row->submitted_amount));

        return ['collected' => $collected, 'confirmed' => $confirmed, 'pending' => $pending,
            'outstanding' => max(0, $collected - $confirmed), 'available' => max(0, $collected - $confirmed - $pending)];
    }
}
