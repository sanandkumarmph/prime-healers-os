<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Organization extends Model
{
    protected $fillable = [
        'name',
        'logo',
        'address',
        'city',
        'state',
        'state_code',
        'pincode',
        'country',
        'gst_number',
        'bank_account_name',
        'bank_account_number',
        'bank_ifsc',
        'bank_name',
        'bank_branch',
        'upi_id',
        'payment_qr_code',
        'digital_signature',
        'phone',
        'email',
        'default_terms',
        'plan',
        'is_internal',
        'is_active',
    ];

    protected $casts = [
        'is_internal' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function customers()
    {
        return $this->hasMany(Customer::class);
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function warehouses()
    {
        return $this->hasMany(Warehouse::class);
    }

    public function roles()
    {
        return $this->hasMany(Role::class);
    }

    public function cities()
    {
        return $this->hasMany(City::class);
    }

    public function vendors()
    {
        return $this->hasMany(Vendor::class);
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function assets()
    {
        return $this->hasMany(Asset::class);
    }

    public function assetMovements()
    {
        return $this->hasMany(AssetMovement::class);
    }

    public function rentals()
    {
        return $this->hasMany(Rental::class);
    }

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }

    public function staff()
    {
        return $this->hasMany(Staff::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function deliveries()
    {
        return $this->hasMany(Delivery::class);
    }

    public function isInternalOrganization(): bool
    {
        return $this->is_internal === true;
    }
}
