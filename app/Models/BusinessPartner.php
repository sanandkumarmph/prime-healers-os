<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BusinessPartner extends Model
{
    protected $fillable = [
        'organization_id',
        'business_name',
        'contact_person',
        'phone',
        'whatsapp',
        'email',
        'address',
        'city',
        'state',
        'pincode',
        'location',
        'latitude',
        'longitude',
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

    public function partnerClients(): HasMany
    {
        return $this->hasMany(PartnerClient::class)->orderBy('client_name');
    }

    public function rentals(): HasMany
    {
        return $this->hasMany(Rental::class)->latest('id');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class)->latest('id');
    }

    public function displayName(): string
    {
        return $this->business_name ?: 'Business Partner';
    }

    public function preferredReminderNumber(): ?string
    {
        return $this->whatsapp ?: $this->phone;
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
