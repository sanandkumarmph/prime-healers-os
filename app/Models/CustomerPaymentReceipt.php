<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerPaymentReceipt extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['amount_minor' => 'integer', 'paid_at' => 'immutable_datetime'];

    public function link()
    {
        return $this->belongsTo(CustomerPaymentLink::class, 'link_id');
    }
}
