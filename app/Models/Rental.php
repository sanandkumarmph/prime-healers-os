<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class Rental extends Model
{
    protected static ?bool $hasReminderLogsTable = null;
    protected static ?bool $hasRenewalsTable = null;
    protected static ?bool $hasSaleItemsTable = null;
    protected static ?bool $hasRentalItemsTable = null;

    protected $fillable = [
        'customer_id',
        'customer_type',
        'business_partner_id',
        'partner_client_id',
        'created_by_user_id',
        'customer_name',
        'phone',
        'product_id',
        'dispatch_warehouse_id',
        'vendor_id',
        'fulfilment_source',
        'delivery_responsibility',
        'pickup_responsibility',
        'delivery_staff_id',
        'pickup_staff_id',
        'quantity',
        'start_date',
        'end_date',
        'rental_amount',
        'deposit_amount',
        'transport_amount',
        'other_amount',
        'status',
        'returned_at',
        'return_condition',
        'return_notes',
        'organization_id',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'returned_at' => 'datetime',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

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

    public function deliveries()
    {
        return $this->hasMany(Delivery::class);
    }

    public function deliveryRecord()
    {
        return $this->hasOne(Delivery::class)
            ->where('type', 'delivery')
            ->latest('id');
    }

    public function pickupRecord()
    {
        return $this->hasOne(Delivery::class)
            ->where('type', 'pickup')
            ->latest('id');
    }

    public function rentalAssets()
    {
        return $this->hasMany(RentalAsset::class);
    }

    public function assets()
    {
        return $this->belongsToMany(Asset::class, 'rental_assets')
            ->withPivot(['organization_id', 'assigned_at', 'returned_at', 'return_condition', 'notes'])
            ->withTimestamps();
    }

    public function activeRentalAssets()
    {
        return $this->hasMany(RentalAsset::class)->whereNull('returned_at');
    }

    public function dispatchWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'dispatch_warehouse_id');
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function vendorOrderDetail()
    {
        return $this->hasOne(VendorOrderDetail::class);
    }

    public function deliveryStaff()
    {
        return $this->belongsTo(Staff::class, 'delivery_staff_id');
    }

    public function pickupStaff()
    {
        return $this->belongsTo(Staff::class, 'pickup_staff_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
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

    public function isVendorSupplied(): bool
    {
        return ($this->fulfilment_source ?? VendorOrderDetail::FULFILMENT_SOURCE_IN_HOUSE) === VendorOrderDetail::FULFILMENT_SOURCE_VENDOR_SUPPLIED;
    }

    public function billingContactName(): string
    {
        return $this->usesBusinessPartnerFlow()
            ? ($this->businessPartner?->billingDisplayName() ?: 'Business Partner')
            : ($this->customer?->displayName() ?: ($this->customer_name ?: 'Customer'));
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
            : ($this->customer?->phone ?: $this->phone);
    }

    public function reminderContactPhone(): ?string
    {
        return $this->usesBusinessPartnerFlow()
            ? ($this->businessPartner?->preferredReminderNumber() ?: $this->businessPartner?->phone)
            : ($this->customer?->preferredWhatsAppNumber() ?: ($this->customer?->phone ?: $this->phone));
    }

    public function reminderContactName(): string
    {
        return $this->usesBusinessPartnerFlow()
            ? ($this->businessPartner?->displayName() ?: 'Business Partner')
            : ($this->customer?->displayName() ?: ($this->customer_name ?: 'Customer'));
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
            : ($this->customer_name ?: ($this->customer?->displayName() ?: 'Customer'));
    }

    public function deliveryContactPhone(): ?string
    {
        return $this->usesBusinessPartnerFlow()
            ? ($this->partnerClient?->primaryPhone() ?: null)
            : ($this->phone ?: $this->customer?->phone);
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

    public function reminderLogs(): HasMany
    {
        return $this->hasMany(RentalReminderLog::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    public function renewals(): HasMany
    {
        return $this->hasMany(RentalRenewal::class)->latest('created_at');
    }

    public function saleItems(): HasMany
    {
        return $this->hasMany(RentalSaleItem::class)->latest('id');
    }

    public function rentalItems(): HasMany
    {
        return $this->hasMany(RentalItem::class)->latest('id');
    }

    public static function hasReminderLogsTable(): bool
    {
        return static::$hasReminderLogsTable ??= Schema::hasTable('rental_reminder_logs');
    }

    public static function hasRenewalsTable(): bool
    {
        return static::$hasRenewalsTable ??= Schema::hasTable('rental_renewals');
    }

    public static function hasSaleItemsTable(): bool
    {
        return static::$hasSaleItemsTable ??= Schema::hasTable('rental_sale_items');
    }

    public static function hasRentalItemsTable(): bool
    {
        return static::$hasRentalItemsTable ??= Schema::hasTable('rental_items');
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeLifecycleStarted(Builder $query): Builder
    {
        return $query->whereHas('deliveryRecord', function (Builder $deliveryQuery) {
            $deliveryQuery->where('status', 'completed');
        });
    }

    public function scopeEffectivelyActive(Builder $query, ?Carbon $today = null): Builder
    {
        $today = ($today ?? Carbon::today())->copy()->startOfDay();

        return $query->where('status', 'active')
            ->whereDate('end_date', '>=', $today)
            ->lifecycleStarted();
    }

    public function scopeEndingSoon(Builder $query, ?Carbon $today = null, int $days = 2): Builder
    {
        $today = ($today ?? Carbon::today())->copy()->startOfDay();

        return $query->where('status', 'active')
            ->whereDate('end_date', '>=', $today)
            ->whereDate('end_date', '<=', $today->copy()->addDays($days))
            ->lifecycleStarted();
    }

    public function scopeOverdue(Builder $query, ?Carbon $today = null): Builder
    {
        $today = ($today ?? Carbon::today())->copy()->startOfDay();

        return $query->where('status', 'active')
            ->whereDate('end_date', '<', $today)
            ->lifecycleStarted();
    }

    public function deliveryStatus(): ?string
    {
        $itemProgress = $this->rentalItemDeliveryProgressStatus();

        if ($itemProgress === 'completed') {
            return 'completed';
        }

        if ($itemProgress === 'partial') {
            return 'partially_delivered';
        }

        $deliveryRecord = $this->relationLoaded('deliveryRecord')
            ? $this->getRelation('deliveryRecord')
            : $this->deliveryRecord()->first();

        if ($deliveryRecord?->status) {
            return $deliveryRecord->status;
        }

        if ($this->delivery_staff_id && !in_array($this->status, ['returned', 'cancelled'], true)) {
            return 'assigned';
        }

        return null;
    }

    public function pickupStatus(): ?string
    {
        $itemProgress = $this->rentalItemPickupProgressStatus();

        if ($itemProgress === 'completed') {
            return 'completed';
        }

        if ($itemProgress === 'partial') {
            return 'partial_return';
        }

        $pickupRecord = $this->relationLoaded('pickupRecord')
            ? $this->getRelation('pickupRecord')
            : $this->pickupRecord()->first();

        return $pickupRecord?->status;
    }

    public function hasDeliveryStarted(): bool
    {
        return $this->deliveryStatus() === 'completed';
    }

    public function hasDeliveryPending(): bool
    {
        return !$this->hasDeliveryStarted() && !in_array($this->status, ['returned', 'cancelled'], true);
    }

    public function operationalStatus(?Carbon $today = null): string
    {
        if ($this->status === 'returned') {
            return 'returned';
        }

        if ($this->status === 'cancelled') {
            return 'cancelled';
        }

        if ($this->hasDeliveryPending()) {
            return 'delivery_pending';
        }

        if ($this->isOverdue($today)) {
            return 'overdue';
        }

        return $this->status ?: 'active';
    }

    public function overdueDays(?Carbon $today = null): int
    {
        $today = ($today ?? Carbon::today())->copy()->startOfDay();

        if (!$this->end_date || !$this->end_date->lt($today)) {
            return 0;
        }

        return (int) $this->end_date->diffInDays($today);
    }

    public function remainingDaysInclusive(?Carbon $today = null): ?int
    {
        if (!$this->end_date) {
            return null;
        }

        $today = ($today ?? Carbon::today())->copy()->startOfDay();
        $endDate = $this->end_date->copy()->startOfDay();

        if ($endDate->lt($today)) {
            return -1 * (int) $endDate->diffInDays($today);
        }

        return (int) $today->diffInDays($endDate) + 1;
    }

    public function customerFacingRemainingLabel(?Carbon $today = null): string
    {
        $remainingDays = $this->remainingDaysInclusive($today);

        if ($remainingDays === null) {
            return 'Schedule pending';
        }

        if ($remainingDays < 0) {
            $overdueDays = abs($remainingDays);

            return $overdueDays . ' day' . ($overdueDays === 1 ? '' : 's') . ' overdue';
        }

        return $remainingDays . ' day' . ($remainingDays === 1 ? '' : 's') . ' remaining';
    }

    public function isRenewed(): bool
    {
        return $this->renewalCount() > 0;
    }

    public function renewalCount(): int
    {
        if (!static::hasRenewalsTable()) {
            return 0;
        }

        if ($this->relationLoaded('renewals')) {
            return $this->renewals->count();
        }

        return $this->renewals()->count();
    }

    public function displayRentalItems()
    {
        if (static::hasRentalItemsTable()) {
            if ($this->relationLoaded('rentalItems') && $this->rentalItems->isNotEmpty()) {
                return $this->rentalItems;
            }

            if ($this->exists && $this->rentalItems()->exists()) {
                return $this->rentalItems()->with('product')->get();
            }
        }

        return collect([(object) [
            'product_id' => $this->product_id,
            'product' => $this->relationLoaded('product') ? $this->product : null,
            'quantity' => $this->quantity ?? 1,
            'ordered_quantity' => (int) ($this->quantity ?? 1),
            'delivered_quantity_value' => $this->deliveryStatus() === 'completed' ? (int) ($this->quantity ?? 1) : 0,
            'returned_quantity_value' => in_array($this->pickupStatus(), ['completed', 'partial_return'], true) && $this->status === 'returned'
                ? (int) ($this->quantity ?? 1)
                : 0,
            'pending_delivery_quantity' => $this->deliveryStatus() === 'completed' ? 0 : (int) ($this->quantity ?? 1),
            'pending_pickup_quantity' => $this->status === 'returned' ? 0 : ($this->deliveryStatus() === 'completed' ? (int) ($this->quantity ?? 1) : 0),
            'delivery_progress_status' => $this->deliveryStatus() === 'completed' ? 'delivered' : 'pending',
            'pickup_progress_status' => $this->status === 'returned' ? 'picked_up' : 'pending',
            'unit_rental_amount' => (float) ($this->quantity ? ((float) ($this->rental_amount ?? 0) / max((int) $this->quantity, 1)) : (float) ($this->rental_amount ?? 0)),
            'gst_rate' => 0.0,
            'gst_mode' => 'exclusive',
            'tax_type' => Product::GST_TAX_TYPE_CGST_SGST,
            'taxable_amount' => (float) ($this->rental_amount ?? 0),
            'cgst_amount' => 0.0,
            'sgst_amount' => 0.0,
            'igst_amount' => 0.0,
            'line_total' => (float) ($this->rental_amount ?? 0),
            'notes' => null,
        ]]);
    }

    public function deliveredQuantityTotal(): int
    {
        return (int) $this->rentalProgressItems()->sum(fn ($item) => (int) ($item->delivered_quantity_value ?? 0));
    }

    public function returnedQuantityTotal(): int
    {
        return (int) $this->rentalProgressItems()->sum(fn ($item) => (int) ($item->returned_quantity_value ?? 0));
    }

    public function pendingDeliveryQuantityTotal(): int
    {
        return (int) $this->rentalProgressItems()->sum(fn ($item) => (int) ($item->pending_delivery_quantity ?? 0));
    }

    public function pendingPickupQuantityTotal(): int
    {
        return (int) $this->rentalProgressItems()->sum(fn ($item) => (int) ($item->pending_pickup_quantity ?? 0));
    }

    public function rentalItemDeliveryProgressStatus(): ?string
    {
        $items = $this->rentalProgressItems();

        if ($items->isEmpty()) {
            return null;
        }

        $ordered = (int) $items->sum(fn ($item) => (int) ($item->ordered_quantity ?? $item->quantity ?? 0));
        $delivered = (int) $items->sum(fn ($item) => (int) ($item->delivered_quantity_value ?? 0));

        if ($ordered <= 0 || $delivered <= 0) {
            return 'pending';
        }

        if ($delivered < $ordered) {
            return 'partial';
        }

        return 'completed';
    }

    public function rentalItemPickupProgressStatus(): ?string
    {
        $items = $this->rentalProgressItems();

        if ($items->isEmpty()) {
            return null;
        }

        $delivered = (int) $items->sum(fn ($item) => (int) ($item->delivered_quantity_value ?? 0));
        $returned = (int) $items->sum(fn ($item) => (int) ($item->returned_quantity_value ?? 0));

        if ($delivered <= 0 || $returned <= 0) {
            return 'pending';
        }

        if ($returned < $delivered) {
            return 'partial';
        }

        return 'completed';
    }

    public function baseDurationDays(): int
    {
        if (!$this->start_date || !$this->end_date) {
            return 1;
        }

        return max((int) $this->start_date->diffInDays($this->end_date) + 1, 1);
    }

    public function suggestedRenewalDays(): int
    {
        return max($this->baseDurationDays(), 1);
    }

    public function suggestedRenewalAmount(?int $days = null): float
    {
        $days = max((int) ($days ?? $this->suggestedRenewalDays()), 1);
        $baseDays = max($this->baseDurationDays(), 1);
        $baseAmount = (float) ($this->rental_amount ?? 0);

        if ($baseAmount <= 0) {
            return 0.0;
        }

        return round(($baseAmount / $baseDays) * $days, 2);
    }

    public function outstandingBalance(): float
    {
        if (array_key_exists('outstanding_invoice_balance', $this->attributes)) {
            return (float) $this->attributes['outstanding_invoice_balance'];
        }

        return 0.0;
    }

    public function latestReminderSentAt(): ?Carbon
    {
        if (array_key_exists('latest_reminder_sent_at', $this->attributes) && filled($this->attributes['latest_reminder_sent_at'])) {
            return Carbon::parse($this->attributes['latest_reminder_sent_at']);
        }

        return null;
    }

    public function shouldHotlist(?Carbon $today = null): bool
    {
        $today = ($today ?? Carbon::today())->copy()->startOfDay();

        return $this->status === 'active'
            && !$this->hasDeliveryPending()
            && $this->isOverdue($today)
            && $this->overdueDays($today) > 5
            && $this->outstandingBalance() > 0;
    }

    public function isOverdue(?Carbon $today = null): bool
    {
        $today = ($today ?? Carbon::today())->copy()->startOfDay();

        if ($this->status !== 'active') {
            return false;
        }

        if (!$this->hasDeliveryStarted()) {
            return false;
        }

        return optional($this->end_date)?->lt($today) ?? false;
    }

    public function isEndingSoon(?Carbon $today = null, int $days = 2): bool
    {
        $today = ($today ?? Carbon::today())->copy()->startOfDay();

        if ($this->status !== 'active') {
            return false;
        }

        if (!$this->hasDeliveryStarted()) {
            return false;
        }

        if (!$this->end_date) {
            return false;
        }

        return $this->end_date->gte($today) && $this->end_date->lte($today->copy()->addDays($days));
    }

    public function canBeReturned(): bool
    {
        if (in_array($this->status, ['returned', 'cancelled'], true)) {
            return false;
        }

        return $this->deliveryStatus() === 'completed';
    }

    public function canRenew(): bool
    {
        return !in_array($this->status, ['returned', 'cancelled'], true);
    }

    private function rentalProgressItems(): Collection
    {
        if (!static::hasRentalItemsTable()) {
            return collect();
        }

        if ($this->relationLoaded('rentalItems')) {
            return $this->rentalItems;
        }

        if (!$this->exists) {
            return collect();
        }

        return $this->rentalItems()->get();
    }
}
