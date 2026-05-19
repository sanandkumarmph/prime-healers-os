<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartnerClient extends Model
{
    protected $fillable = [
        'organization_id',
        'business_partner_id',
        'client_name',
        'phone',
        'alternate_phone',
        'address',
        'city',
        'state',
        'pincode',
        'location',
        'latitude',
        'longitude',
        'delivery_notes',
        'status',
    ];

    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function businessPartner()
    {
        return $this->belongsTo(BusinessPartner::class);
    }

    public function rentals()
    {
        return $this->hasMany(Rental::class)->latest('id');
    }

    public function sales()
    {
        return $this->hasMany(Sale::class)->latest('id');
    }

    public function displayName(): string
    {
        return $this->client_name ?: 'Actual Client';
    }

    public function primaryPhone(): ?string
    {
        return $this->phone ?: $this->alternate_phone;
    }

    public function openMapUrl(): ?string
    {
        if (!empty($this->location)) {
            return $this->location;
        }

        if ($this->latitude !== null && $this->longitude !== null) {
            return 'https://maps.google.com/?q=' . $this->latitude . ',' . $this->longitude;
        }

        return null;
    }
}
