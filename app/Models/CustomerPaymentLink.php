<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerPaymentLink extends Model
{
    public const OPEN = ['creating', 'unknown', 'active', 'needs_review'];

    protected $guarded = ['id'];

    protected $casts = ['amount_minor' => 'integer', 'reminders' => 'boolean', 'expires_at' => 'immutable_datetime', 'checked_at' => 'immutable_datetime'];

    public function account()
    {
        return $this->belongsTo(CustomerPaymentAccount::class, 'account_id');
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function receipts()
    {
        return $this->hasMany(CustomerPaymentReceipt::class, 'link_id');
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', self::OPEN);
    }
}
