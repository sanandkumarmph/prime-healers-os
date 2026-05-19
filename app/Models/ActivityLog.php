<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    protected $fillable = [
        'organization_id',
        'user_id',
        'action',
        'subject_type',
        'subject_id',
        'customer_id',
        'rental_id',
        'sale_id',
        'invoice_id',
        'payment_id',
        'delivery_id',
        'business_partner_id',
        'partner_client_id',
        'description',
        'properties',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'properties' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function rental()
    {
        return $this->belongsTo(Rental::class);
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function delivery()
    {
        return $this->belongsTo(Delivery::class);
    }

    public function businessPartner()
    {
        return $this->belongsTo(BusinessPartner::class);
    }

    public function partnerClient()
    {
        return $this->belongsTo(PartnerClient::class);
    }
}
