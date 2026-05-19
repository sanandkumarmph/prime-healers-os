<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Model;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Organization;
use App\Models\Asset;
use App\Models\Rental;
use App\Models\SaleItem;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class Sale extends Model
{
    protected static ?bool $hasSaleItemsTable = null;

    protected $fillable = [
        'customer_id',
        'customer_type',
        'business_partner_id',
        'partner_client_id',
        'product_id',
        'asset_id',
        'rental_id',
        'auto_generated_from_rental',
        'warehouse_id',
        'quantity',
        'unit_price',
        'discount_amount',
        'shipping_charges',
        'tax_percentage',
        'tax_calculation_mode',
        'sale_date',
        'sale_amount',
        'payment_status',
        'notes',
        'organization_id',
        'stock_applied',
        'created_by',
        'created_by_user_id',
    ];

    protected $casts = [
        'sale_date' => 'date',
        'auto_generated_from_rental' => 'boolean',
        'warehouse_id' => 'integer',
        'unit_price' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'shipping_charges' => 'decimal:2',
        'tax_percentage' => 'decimal:2',
        'sale_amount' => 'decimal:2',
        'stock_applied' => 'boolean',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function businessPartner()
    {
        return $this->belongsTo(BusinessPartner::class);
    }

    public function partnerClient()
    {
        return $this->belongsTo(PartnerClient::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function saleUnits(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class, 'sale_assets')
            ->withPivot(['organization_id'])
            ->withTimestamps();
    }

    public function saleItems(): HasMany
    {
        return $this->hasMany(SaleItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function rental()
    {
        return $this->belongsTo(Rental::class);
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function deliveries()
    {
        return $this->hasMany(Delivery::class);
    }

    public function deliveryRecord()
    {
        return $this->hasOne(Delivery::class)->where('type', 'delivery')->latestOfMany();
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function customerTypeValue(): string
    {
        return $this->customer_type === 'business_partner'
            ? 'business_partner'
            : 'direct_customer';
    }

    public function usesBusinessPartnerFlow(): bool
    {
        return $this->customerTypeValue() === 'business_partner'
            && (int) ($this->business_partner_id ?? 0) > 0
            && (int) ($this->partner_client_id ?? 0) > 0;
    }

    public function billingContactName(): string
    {
        return $this->usesBusinessPartnerFlow()
            ? ($this->businessPartner?->billingDisplayName() ?: 'Business Partner')
            : ($this->customer?->displayName() ?: 'Customer');
    }

    public function billingContactPerson(): ?string
    {
        return $this->usesBusinessPartnerFlow()
            ? ($this->businessPartner?->contact_person ?: null)
            : ($this->customer?->contactPersonName() ?? null);
    }

    public function billingContactPhone(): ?string
    {
        return $this->usesBusinessPartnerFlow()
            ? ($this->businessPartner?->phone ?: null)
            : ($this->customer?->phone ?: null);
    }

    public function reminderContactPhone(): ?string
    {
        return $this->usesBusinessPartnerFlow()
            ? ($this->businessPartner?->preferredReminderNumber() ?: $this->businessPartner?->phone)
            : ($this->customer?->preferredWhatsAppNumber() ?: $this->customer?->phone);
    }

    public function reminderContactName(): string
    {
        return $this->usesBusinessPartnerFlow()
            ? ($this->businessPartner?->displayName() ?: 'Business Partner')
            : ($this->customer?->displayName() ?: 'Customer');
    }

    public function billingContactEmail(): ?string
    {
        return $this->usesBusinessPartnerFlow()
            ? ($this->businessPartner?->email ?: null)
            : ($this->customer?->email ?: null);
    }

    public function billingContactAddress(): ?string
    {
        return $this->usesBusinessPartnerFlow()
            ? ($this->businessPartner?->billingAddressLine() ?: null)
            : ($this->customer?->address ?: null);
    }

    public function billingContactCity(): ?string
    {
        return $this->usesBusinessPartnerFlow()
            ? ($this->businessPartner?->billingCityValue() ?: null)
            : ($this->customer?->city ?: null);
    }

    public function billingContactState(): ?string
    {
        return $this->usesBusinessPartnerFlow()
            ? ($this->businessPartner?->billingStateValue() ?: null)
            : ($this->customer?->state ?: null);
    }

    public function billingContactPincode(): ?string
    {
        return $this->usesBusinessPartnerFlow()
            ? ($this->businessPartner?->billingPincodeValue() ?: null)
            : ($this->customer?->pincode ?: null);
    }

    public function deliveryContactName(): string
    {
        return $this->usesBusinessPartnerFlow()
            ? ($this->partnerClient?->displayName() ?: 'Actual Client')
            : ($this->customer?->displayName() ?: 'Customer');
    }

    public function deliveryContactPhone(): ?string
    {
        return $this->usesBusinessPartnerFlow()
            ? ($this->partnerClient?->primaryPhone() ?: null)
            : ($this->customer?->phone ?: null);
    }

    public function deliveryContactAddress(): ?string
    {
        return $this->usesBusinessPartnerFlow()
            ? ($this->partnerClient?->address ?: null)
            : ($this->customer?->address ?: null);
    }

    public function deliveryContactCity(): ?string
    {
        return $this->usesBusinessPartnerFlow()
            ? ($this->partnerClient?->city ?: null)
            : ($this->customer?->city ?: null);
    }

    public function deliveryContactState(): ?string
    {
        return $this->usesBusinessPartnerFlow()
            ? ($this->partnerClient?->state ?: null)
            : ($this->customer?->state ?: null);
    }

    public function deliveryContactPincode(): ?string
    {
        return $this->usesBusinessPartnerFlow()
            ? ($this->partnerClient?->pincode ?: null)
            : ($this->customer?->pincode ?: null);
    }

    public function deliveryContactMapUrl(): ?string
    {
        return $this->usesBusinessPartnerFlow()
            ? ($this->partnerClient?->openMapUrl() ?: null)
            : ($this->customer?->openMapUrl() ?: null);
    }

    public function deliveryContactNotes(): ?string
    {
        return $this->usesBusinessPartnerFlow()
            ? ($this->partnerClient?->delivery_notes ?: null)
            : null;
    }

    public function reminderContactEmail(): ?string
    {
        return $this->usesBusinessPartnerFlow()
            ? ($this->businessPartner?->email ?: null)
            : ($this->customer?->email ?: null);
    }

    public function primaryTaxState(): ?string
    {
        if ($this->usesBusinessPartnerFlow()) {
            return $this->billingContactState() ?: $this->deliveryContactState();
        }

        return $this->deliveryContactState() ?: $this->billingContactState();
    }

    public function gstLabel(): string
    {
        return ($this->tax_calculation_mode ?? 'exclusive') === 'inclusive'
            ? 'GST Inclusive'
            : 'GST Exclusive';
    }

    public static function hasSaleItemsTable(): bool
    {
        return static::$hasSaleItemsTable ??= Schema::hasTable('sale_items');
    }

    public function displaySaleItems(): Collection
    {
        if (self::hasSaleItemsTable()) {
            if (!$this->relationLoaded('saleItems')) {
                $this->load('saleItems.product', 'saleItems.asset', 'saleItems.warehouse');
            }

            if ($this->saleItems->isNotEmpty()) {
                return $this->saleItems->sortBy(fn ($item) => [$item->sort_order ?? 0, $item->id])->values();
            }
        }

        $fallbackItem = new SaleItem([
            'organization_id' => $this->organization_id,
            'sale_id' => $this->id,
            'product_id' => $this->product_id,
            'asset_id' => $this->asset_id,
            'warehouse_id' => $this->warehouse_id,
            'quantity' => max((int) ($this->quantity ?? 1), 1),
            'unit_price' => (float) ($this->unit_price ?? 0),
            'discount_amount' => (float) ($this->discount_amount ?? 0),
            'shipping_charges' => (float) ($this->shipping_charges ?? 0),
            'tax_percentage' => (float) ($this->tax_percentage ?? 0),
            'tax_calculation_mode' => $this->tax_calculation_mode ?? 'exclusive',
            'tax_type' => Product::GST_TAX_TYPE_CGST_SGST,
            'taxable_amount' => max((float) (($this->quantity ?? 1) * ($this->unit_price ?? 0)) - (float) ($this->discount_amount ?? 0), 0),
            'total_tax_amount' => 0,
            'line_total' => (float) ($this->sale_amount ?? 0),
            'sort_order' => 0,
            'asset_ids' => $this->asset_id ? [(int) $this->asset_id] : [],
            'notes' => $this->notes,
        ]);

        $fallbackItem->setRelation('product', $this->product);
        $fallbackItem->setRelation('asset', $this->asset);
        $fallbackItem->setRelation('warehouse', $this->warehouse);

        return collect([$fallbackItem]);
    }

    public function resolvedShippingCharges(): float
    {
        $headerShipping = round((float) ($this->shipping_charges ?? 0), 2);

        if ($headerShipping > 0) {
            return $headerShipping;
        }

        if (self::hasSaleItemsTable()) {
            if ($this->relationLoaded('saleItems')) {
                return round((float) $this->saleItems->sum(fn ($item) => (float) ($item->shipping_charges ?? 0)), 2);
            }

            return round((float) $this->saleItems()->sum('shipping_charges'), 2);
        }

        return 0.0;
    }
}
