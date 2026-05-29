<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class VendorOrderDetail extends Model
{
    public const DERIVED_RECONCILIATION_STATES = [
        'profitable',
        'low_margin',
        'negative_margin',
        'unpaid_vendor',
        'customer_unpaid',
        'vendor_fulfilled_pending_invoice',
        'customer_paid_vendor_unpaid',
        'vendor_invoice_missing',
    ];

    public const ORDER_TYPE_RENTAL = 'rental';
    public const ORDER_TYPE_SALE = 'sale';
    public const ORDER_TYPES = [
        self::ORDER_TYPE_RENTAL,
        self::ORDER_TYPE_SALE,
    ];

    public const FULFILMENT_SOURCE_IN_HOUSE = 'in_house';
    public const FULFILMENT_SOURCE_VENDOR_SUPPLIED = 'vendor_supplied';
    public const FULFILMENT_SOURCES = [
        self::FULFILMENT_SOURCE_IN_HOUSE,
        self::FULFILMENT_SOURCE_VENDOR_SUPPLIED,
    ];

    public const DELIVERY_RESPONSIBILITIES = [
        'vendor_delivery',
        'customer_pickup',
        'ph_internal_delivery',
    ];

    public const PICKUP_RESPONSIBILITIES = [
        'vendor_pickup',
        'customer_return',
        'ph_internal_pickup',
    ];

    public const VENDOR_ORDER_STATUSES = [
        'draft',
        'requested',
        'confirmed',
        'in_progress',
        'completed',
        'cancelled',
    ];

    public const VENDOR_PAYMENT_STATUSES = [
        'pending',
        'partial',
        'paid',
        'not_required',
    ];

    protected $fillable = [
        'organization_id',
        'vendor_id',
        'rental_id',
        'sale_id',
        'order_type',
        'fulfilment_source',
        'delivery_responsibility',
        'pickup_responsibility',
        'vendor_order_status',
        'procurement_cost',
        'vendor_delivery_cost',
        'vendor_pickup_cost',
        'other_vendor_cost',
        'vendor_invoice_number',
        'vendor_payment_status',
        'vendor_paid_at',
        'notes',
    ];

    protected $casts = [
        'procurement_cost' => 'decimal:2',
        'vendor_delivery_cost' => 'decimal:2',
        'vendor_pickup_cost' => 'decimal:2',
        'other_vendor_cost' => 'decimal:2',
        'vendor_paid_at' => 'datetime',
    ];

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function rental()
    {
        return $this->belongsTo(Rental::class);
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function totalVendorCost(): float
    {
        return round(
            (float) ($this->procurement_cost ?? 0)
            + (float) ($this->vendor_delivery_cost ?? 0)
            + (float) ($this->vendor_pickup_cost ?? 0)
            + (float) ($this->other_vendor_cost ?? 0),
            2
        );
    }

    public function customerRevenue(): float
    {
        if ($this->order_type === self::ORDER_TYPE_RENTAL) {
            return round(
                (float) ($this->rental?->rental_amount ?? 0)
                + (float) ($this->rental?->deposit_amount ?? 0)
                + (float) ($this->rental?->transport_amount ?? 0)
                + (float) ($this->rental?->other_amount ?? 0),
                2
            );
        }

        return round((float) ($this->sale?->sale_amount ?? 0), 2);
    }

    public function grossMargin(): float
    {
        return round($this->customerRevenue() - $this->totalVendorCost(), 2);
    }

    public function grossMarginPercent(): float
    {
        $revenue = $this->customerRevenue();

        if ($revenue <= 0) {
            return 0.0;
        }

        return round(($this->grossMargin() / $revenue) * 100, 2);
    }

    public function customerPaymentStatus(): string
    {
        return $this->order_type === self::ORDER_TYPE_RENTAL
            ? (string) ($this->rental?->payment_status ?? 'pending')
            : (string) ($this->sale?->payment_status ?? 'pending');
    }

    public function operationalFulfilmentStatus(): string
    {
        if ($this->order_type === self::ORDER_TYPE_RENTAL) {
            $delivery = $this->rental?->deliveries?->firstWhere('type', 'delivery');
            $pickup = $this->rental?->deliveries?->firstWhere('type', 'pickup');

            if ($pickup && $pickup->status === 'completed') {
                return 'pickup_completed';
            }

            if ($delivery && $delivery->status === 'completed') {
                return 'delivery_completed';
            }

            if ($delivery && in_array((string) $delivery->status, ['in_progress', 'assigned', 'pending'], true)) {
                return 'delivery_scheduled';
            }
        }

        if ($this->order_type === self::ORDER_TYPE_SALE) {
            if (($this->sale?->delivery_status ?? null) === 'completed') {
                return 'delivery_completed';
            }

            if (($this->sale?->delivery_status ?? null) === 'pending') {
                return 'delivery_scheduled';
            }
        }

        return $this->vendor_order_status ?: 'draft';
    }

    public function vendorPayable(): float
    {
        return $this->totalVendorCost();
    }

    public function vendorPaidAmount(): float
    {
        return $this->vendor_payment_status === 'paid' ? $this->vendorPayable() : 0.0;
    }

    public function vendorBalancePayable(): float
    {
        return round(max($this->vendorPayable() - $this->vendorPaidAmount(), 0), 2);
    }

    public function vendorPaymentDueDays(): ?int
    {
        if ($this->vendor_payment_status === 'paid') {
            return 0;
        }

        $date = $this->order_type === self::ORDER_TYPE_RENTAL
            ? $this->rental?->start_date
            : $this->sale?->sale_date;

        if (!$date) {
            return null;
        }

        return $date->diffInDays(now());
    }

    public function derivedStates(): array
    {
        $states = [];

        if ($this->grossMargin() < 0) {
            $states[] = 'negative_margin';
        } elseif ($this->grossMarginPercent() > 0 && $this->grossMarginPercent() <= 10) {
            $states[] = 'low_margin';
        } elseif ($this->grossMargin() > 0) {
            $states[] = 'profitable';
        }

        if (($this->vendor_payment_status ?? 'pending') !== 'paid' && $this->vendorPayable() > 0) {
            $states[] = 'unpaid_vendor';
        }

        if (!in_array($this->customerPaymentStatus(), ['paid', 'partial'], true)) {
            $states[] = 'customer_unpaid';
        }

        if (($this->vendor_order_status ?? '') === 'completed' && blank($this->vendor_invoice_number)) {
            $states[] = 'vendor_fulfilled_pending_invoice';
            $states[] = 'vendor_invoice_missing';
        }

        if ($this->customerPaymentStatus() === 'paid' && ($this->vendor_payment_status ?? 'pending') !== 'paid' && $this->vendorPayable() > 0) {
            $states[] = 'customer_paid_vendor_unpaid';
        }

        return array_values(array_unique($states));
    }

    public function deliveryResponsibilityLabel(): string
    {
        return match ($this->delivery_responsibility) {
            'vendor_delivery' => 'Vendor Delivery',
            'customer_pickup' => 'Customer Pickup',
            default => 'PH Internal Delivery',
        };
    }

    public function pickupResponsibilityLabel(): ?string
    {
        return match ($this->pickup_responsibility) {
            'vendor_pickup' => 'Vendor Pickup',
            'customer_return' => 'Customer Return',
            'ph_internal_pickup' => 'PH Internal Pickup',
            default => null,
        };
    }

    public function derivedStateLabels(): array
    {
        return collect($this->derivedStates())
            ->map(fn (string $state) => match ($state) {
                'profitable' => 'Profitable',
                'low_margin' => 'Low Margin',
                'negative_margin' => 'Negative Margin',
                'unpaid_vendor' => 'Unpaid Vendor',
                'customer_unpaid' => 'Customer Unpaid',
                'vendor_fulfilled_pending_invoice' => 'Vendor Fulfilled Pending Invoice',
                'customer_paid_vendor_unpaid' => 'Customer Paid Vendor Unpaid',
                'vendor_invoice_missing' => 'Vendor Invoice Missing',
                default => ucfirst(str_replace('_', ' ', $state)),
            })
            ->values()
            ->all();
    }
}
