<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class PartnerClient extends Model
{
    protected static ?array $tableColumns = null;

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
        'account_manager',
        'notes',
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

    public static function relationSelectColumns(array $extra = []): array
    {
        $columns = ['id'];
        $availableColumns = static::tableColumns();

        foreach ([
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
        ] as $column) {
            if (in_array($column, $availableColumns, true)) {
                $columns[] = $column;
            }
        }

        foreach ($extra as $column) {
            if ($column !== '' && !in_array($column, $columns, true) && in_array($column, $availableColumns, true)) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    protected static function tableColumns(): array
    {
        return static::$tableColumns ??= Schema::getColumnListing('partner_clients');
    }
}
