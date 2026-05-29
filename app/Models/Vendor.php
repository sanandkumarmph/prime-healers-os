<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vendor extends Model
{
    protected $fillable = [
        'organization_id',
        'city_id',
        'name',
        'contact_person',
        'phone',
        'whatsapp',
        'email',
        'vendor_type',
        'gst_number',
        'gst_registration_type',
        'state',
        'pincode',
        'payment_terms',
        'address',
        'city',
        'is_active',
        'deactivated_at',
        'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'deactivated_at' => 'datetime',
    ];

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        $search = trim((string) $search);

        if ($search === '') {
            return $query;
        }

        return $query->where(function (Builder $innerQuery) use ($search) {
            $innerQuery->where('name', 'like', "%{$search}%")
                ->orWhere('contact_person', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->orWhere('whatsapp', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('city', 'like', "%{$search}%")
                ->orWhere('vendor_type', 'like', "%{$search}%")
                ->orWhere('gst_number', 'like', "%{$search}%");
        });
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function cityRecord()
    {
        return $this->belongsTo(City::class, 'city_id');
    }

    public function vendorOrderDetails(): HasMany
    {
        return $this->hasMany(VendorOrderDetail::class);
    }
}
