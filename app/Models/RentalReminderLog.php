<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RentalReminderLog extends Model
{
    protected $fillable = [
        'organization_id',
        'rental_id',
        'customer_id',
        'reminder_type',
        'sent_via',
        'sent_to_number',
        'message_preview',
        'sent_by_user_id',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function rental()
    {
        return $this->belongsTo(Rental::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function sentBy()
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }
}
