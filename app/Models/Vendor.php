<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Vendor extends Model
{
    protected $fillable = [
        'organization_id',
        'city_id',
        'name',
        'contact_person',
        'phone',
        'email',
        'address',
        'city',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
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
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('city', 'like', "%{$search}%");
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
}
