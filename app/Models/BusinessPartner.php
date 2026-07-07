<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

class BusinessPartner extends Model
{
    protected static ?array $tableColumns = null;

    protected $fillable = [
        'organization_id',
        'business_name',
        'partner_code',
        'contact_person',
        'phone',
        'whatsapp',
        'email',
        'gst_registered',
        'gstin',
        'legal_name',
        'billing_state',
        'billing_address',
        'billing_city',
        'billing_pincode',
        'address',
        'city',
        'state',
        'pincode',
        'credit_terms',
        'referral_percentage',
        'account_manager',
        'notes',
        'location',
        'latitude',
        'longitude',
        'status',
    ];

    protected $casts = [
        'gst_registered' => 'boolean',
        'referral_percentage' => 'decimal:2',
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

    public function billingDisplayName(): string
    {
        return $this->legal_name ?: $this->displayName();
    }

    public function billingAddressLine(): ?string
    {
        return $this->billing_address ?: $this->address;
    }

    public function billingCityValue(): ?string
    {
        return $this->billing_city ?: $this->city;
    }

    public function billingStateValue(): ?string
    {
        return $this->billing_state ?: $this->state;
    }

    public function billingPincodeValue(): ?string
    {
        return $this->billing_pincode ?: $this->pincode;
    }

    public function defaultTaxTypeForState(?string $organizationState): string
    {
        $billingState = strtolower(trim((string) $this->billingStateValue()));
        $orgState = strtolower(trim((string) $organizationState));

        if ($billingState !== '' && $orgState !== '' && $billingState !== $orgState) {
            return Product::GST_TAX_TYPE_IGST;
        }

        return Product::GST_TAX_TYPE_CGST_SGST;
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

    public static function relationSelectColumns(array $extra = []): array
    {
        $columns = ['id'];
        $availableColumns = static::tableColumns();

        foreach ([
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
        return static::$tableColumns ??= Schema::getColumnListing('business_partners');
    }
}
