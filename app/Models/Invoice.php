<?php

namespace App\Models;

use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Invoice extends Model
{
    private function coerceDateValue(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy();
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value);
            } catch (\Throwable $exception) {
                return null;
            }
        }

        return null;
    }

    private function formatDateValue(mixed $value, string $format = 'd M Y'): string
    {
        return $this->coerceDateValue($value)?->format($format) ?? '-';
    }

    protected $fillable = [
        'organization_id',
        'invoice_number',
        'invoice_date',
        'due_date',
        'purchase_order_number',
        'purchase_order_date',
        'reference_number',
        'customer_id',
        'rental_id',
        'sale_id',

        'bill_to_name',
        'bill_to_phone',
        'bill_to_email',
        'bill_to_address',
        'bill_to_city',
        'bill_to_state',
        'bill_to_state_code',
        'bill_to_pincode',
        'bill_to_gstin',

        'ship_to_name',
        'ship_to_phone',
        'ship_to_address',
        'ship_to_city',
        'ship_to_state',
        'ship_to_state_code',
        'ship_to_pincode',

        'place_of_supply_state',
        'place_of_supply_code',
        'tax_type',
        'tax_calculation_mode',

        'status',
        'payment_status',

        'subtotal',
        'discount_amount',
        'deposit_amount',
        'shipping_charges',
        'taxable_amount',
        'cgst_amount',
        'sgst_amount',
        'igst_amount',
        'total_tax_amount',
        'total_amount',
        'paid_amount',
        'balance_amount',

        'notes',
        'terms_conditions',
        'created_by',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'due_date' => 'date',
        'purchase_order_date' => 'date',
        'subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'deposit_amount' => 'decimal:2',
        'shipping_charges' => 'decimal:2',
        'taxable_amount' => 'decimal:2',
        'cgst_amount' => 'decimal:2',
        'sgst_amount' => 'decimal:2',
        'igst_amount' => 'decimal:2',
        'total_tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'balance_amount' => 'decimal:2',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function rental(): BelongsTo
    {
        return $this->belongsTo(Rental::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function rentalRenewal(): HasOne
    {
        return $this->hasOne(RentalRenewal::class);
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeOverdue(Builder $query, ?Carbon $today = null): Builder
    {
        $today = ($today ?? now())->startOfDay();

        return $query
            ->whereDate('due_date', '<', $today)
            ->whereNotIn('payment_status', ['paid', 'cancelled']);
    }

    public static function determineFinancialStatus(
        float $totalAmount,
        float $paidAmount,
        ?Carbon $dueDate = null,
        ?string $currentStatus = null
    ): string {
        if ($currentStatus === 'cancelled') {
            return 'cancelled';
        }

        if ($totalAmount <= 0) {
            return 'draft';
        }

        if ($paidAmount >= $totalAmount) {
            return 'paid';
        }

        if ($paidAmount > 0) {
            return 'partial';
        }

        if ($dueDate && $dueDate->copy()->startOfDay()->lt(now()->startOfDay())) {
            return 'overdue';
        }

        return 'unpaid';
    }

    public function syncFinancialStatus(bool $save = true): self
    {
        $paymentsTotal = (float) $this->payments()->sum('amount');
        $totalAmount = (float) ($this->total_amount ?? 0);
        $paidAmount = min($paymentsTotal, $totalAmount);
        $balanceAmount = max($totalAmount - $paidAmount, 0);

        $financialStatus = self::determineFinancialStatus(
            $totalAmount,
            $paidAmount,
            $this->due_date,
            $this->status
        );

        $this->forceFill([
            'paid_amount' => $paidAmount,
            'balance_amount' => $balanceAmount,
            'payment_status' => $financialStatus,
            'status' => $financialStatus,
        ]);

        if ($save) {
            $this->save();
        }

        return $this;
    }

    public function linkedRentalId(): ?int
    {
        if (!empty($this->rental_id)) {
            return (int) $this->rental_id;
        }

        $rentalItem = $this->relationLoaded('items')
            ? $this->items->first(fn ($item) => $item->source_type === 'rental' && !empty($item->source_id))
            : $this->items()->where('source_type', 'rental')->whereNotNull('source_id')->first();

        if ($rentalItem?->source_id) {
            return (int) $rentalItem->source_id;
        }

        $renewalRentalId = $this->relationLoaded('rentalRenewal')
            ? $this->rentalRenewal?->rental_id
            : $this->rentalRenewal()->value('rental_id');

        if ($renewalRentalId) {
            return (int) $renewalRentalId;
        }

        return $this->resolvedRental()?->id;
    }

    public function resolvedRental(): ?Rental
    {
        if (!empty($this->rental_id)) {
            return Rental::query()
                ->where('organization_id', $this->organization_id)
                ->find($this->rental_id);
        }

        if ($this->relationLoaded('rentalRenewal') && $this->rentalRenewal?->rental_id) {
            return Rental::query()
                ->where('organization_id', $this->organization_id)
                ->find($this->rentalRenewal->rental_id);
        }

        if (!$this->relationLoaded('rentalRenewal')) {
            $renewalRentalId = $this->rentalRenewal()->value('rental_id');

            if ($renewalRentalId) {
                return Rental::query()
                    ->where('organization_id', $this->organization_id)
                    ->find($renewalRentalId);
            }
        }

        $rentalItem = $this->relationLoaded('items')
            ? $this->items->first(fn ($item) => $item->source_type === 'rental')
            : $this->items()->where('source_type', 'rental')->first();

        if (!$rentalItem) {
            return null;
        }

        if (!empty($rentalItem->source_id)) {
            return Rental::query()
                ->where('organization_id', $this->organization_id)
                ->find($rentalItem->source_id);
        }

        if (empty($this->customer_id) || empty($rentalItem->product_id)) {
            return null;
        }

        $invoiceDate = $this->invoice_date?->copy()?->startOfDay();

        return Rental::query()
            ->where('organization_id', $this->organization_id)
            ->where('customer_id', $this->customer_id)
            ->where('product_id', $rentalItem->product_id)
            ->get()
            ->sortBy(function (Rental $rental) use ($invoiceDate) {
                if (!$invoiceDate || !$rental->start_date) {
                    return PHP_INT_MAX;
                }

                return abs($rental->start_date->copy()->startOfDay()->diffInDays($invoiceDate, false));
            })
            ->first();
    }

    public function inferredRentalPeriod(): ?array
    {
        if ($rental = $this->resolvedRental()) {
            return [
                'start_date' => $rental->start_date,
                'end_date' => $rental->end_date,
                'source' => 'rental',
            ];
        }

        $rentalItem = $this->relationLoaded('items')
            ? $this->items->first(fn ($item) => $item->source_type === 'rental' && !empty($item->days))
            : $this->items()->where('source_type', 'rental')->whereNotNull('days')->first();

        if (!$rentalItem || !$this->invoice_date) {
            return null;
        }

        $startDate = $this->invoice_date->copy()->startOfDay();
        $billableDays = max((int) round((float) $rentalItem->days), 1);
        $endDate = $startDate->copy()->addDays($billableDays);

        return [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'source' => 'invoice_fallback',
        ];
    }

    public function nearestRentalReference(): ?Rental
    {
        if (empty($this->customer_id)) {
            return null;
        }

        $invoiceDate = $this->invoice_date?->copy()?->startOfDay();

        return Rental::query()
            ->with('product')
            ->where('organization_id', $this->organization_id)
            ->where('customer_id', $this->customer_id)
            ->get()
            ->sortBy(function (Rental $rental) use ($invoiceDate) {
                if (!$invoiceDate) {
                    return PHP_INT_MAX;
                }

                $startDistance = $rental->start_date
                    ? abs($rental->start_date->copy()->startOfDay()->diffInDays($invoiceDate, false))
                    : PHP_INT_MAX;

                $endDistance = $rental->end_date
                    ? abs($rental->end_date->copy()->startOfDay()->diffInDays($invoiceDate, false))
                    : PHP_INT_MAX;

                return min($startDistance, $endDistance);
            })
            ->first();
    }

    public function editableFallbackItem(): ?array
    {
        if ($this->rentalRenewal && ($rental = $this->resolvedRental())) {
            return [
                'product_id' => $rental->product_id,
                'source_type' => 'rental',
                'source_id' => $rental->id,
                'hsn_sac_code' => null,
                'custom_name' => '',
                'description' => $rental->product?->name ?: ('Rental #' . $rental->id),
                'quantity' => (float) ($rental->quantity ?: 1),
                'unit' => 'renewal',
                'days' => $this->rentalRenewal->renewal_days,
                'rate' => (float) ($this->rentalRenewal->rental_amount_added ?: 0),
                'discount_amount' => 0,
                'tax_percentage' => 0,
                'meta_note' => 'Renewal period: '
                    . $this->formatDateValue($this->rentalRenewal->previous_end_date)
                    . ' to '
                    . $this->formatDateValue($this->rentalRenewal->renewed_end_date),
            ];
        }

        if ($rental = $this->nearestRentalReference()) {
            return [
                'product_id' => $rental->product_id,
                'source_type' => 'rental',
                'source_id' => $rental->id,
                'hsn_sac_code' => null,
                'custom_name' => '',
                'description' => $rental->product?->name ?: ('Rental #' . $rental->id),
                'quantity' => (float) ($rental->quantity ?: 1),
                'unit' => 'rental',
                'days' => $rental->start_date && $rental->end_date
                    ? max($rental->start_date->diffInDays($rental->end_date), 1)
                    : null,
                'rate' => (float) ($rental->rental_amount ?: 0),
                'discount_amount' => 0,
                'tax_percentage' => 0,
                'meta_note' => 'Recovered from rental #' . $rental->id,
            ];
        }

        if ($legacyItem = $this->legacyReferenceItem()) {
            $period = $this->inferredRentalPeriod();
            $periodMeta = $period
                ? 'Rental period: '
                    . $this->formatDateValue($period['start_date'] ?? null)
                    . ' to '
                    . $this->formatDateValue($period['end_date'] ?? null)
                : 'Recovered from earlier invoice history';

            return [
                'product_id' => $legacyItem->product_id,
                'source_type' => $legacyItem->source_type,
                'source_id' => $legacyItem->source_id,
                'hsn_sac_code' => $legacyItem->hsn_sac_code,
                'custom_name' => $legacyItem->product_id ? '' : $legacyItem->display_description,
                'description' => $legacyItem->display_description,
                'quantity' => $legacyItem->quantity ?: 1,
                'unit' => $legacyItem->unit,
                'days' => $legacyItem->days,
                'rate' => $legacyItem->rate,
                'discount_amount' => $legacyItem->discount_amount,
                'tax_percentage' => $legacyItem->tax_percentage,
                'meta_note' => $periodMeta,
            ];
        }

        return null;
    }

    public function legacyReferenceItem(): ?InvoiceItem
    {
        if ($this->relationLoaded('items') && $this->items->isNotEmpty()) {
            return $this->items->first();
        }

        $currentItem = $this->items()->with('product')->first();
        if ($currentItem) {
            return $currentItem;
        }

        $candidateInvoices = self::query()
            ->where('organization_id', $this->organization_id)
            ->whereKeyNot($this->getKey())
            ->where(function (Builder $query) {
                $matched = false;

                if (!empty($this->customer_id)) {
                    $query->where('customer_id', $this->customer_id);
                    $matched = true;
                }

                if (!empty($this->bill_to_phone)) {
                    $phoneMatch = function (Builder $phoneQuery) {
                        $phoneQuery->where('bill_to_phone', $this->bill_to_phone);
                    };

                    if ($matched) {
                        $query->orWhere($phoneMatch);
                    } else {
                        $query->where($phoneMatch);
                        $matched = true;
                    }
                }

                if (!empty($this->bill_to_name)) {
                    $nameMatch = function (Builder $nameQuery) {
                        $nameQuery->where('bill_to_name', $this->bill_to_name);
                    };

                    if ($matched) {
                        $query->orWhere($nameMatch);
                    } else {
                        $query->where($nameMatch);
                    }
                }
            })
            ->with(['items.product'])
            ->latest('invoice_date')
            ->latest('id')
            ->get();

        foreach ($candidateInvoices as $invoice) {
            if ($invoice->items->isNotEmpty()) {
                return $invoice->items->first();
            }
        }

        return null;
    }
}
