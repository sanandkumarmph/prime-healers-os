<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected static function booted(): void
    {
        $guard = function (self $payment) {
            app(\App\Services\CustomerPayments\PaymentLinkGuard::class)->assertEditable((int) $payment->organization_id, $payment->invoice_id, $payment->rental_id);
            if ($payment->exists && $payment->isDirty(['organization_id', 'invoice_id', 'rental_id'])) {
                app(\App\Services\CustomerPayments\PaymentLinkGuard::class)->assertEditable((int) $payment->getOriginal('organization_id'), $payment->getOriginal('invoice_id'), $payment->getOriginal('rental_id'));
            }
            if (\Illuminate\Support\Facades\Schema::hasTable('customer_payment_receipts')
                && CustomerPaymentReceipt::whereIn('payment_id', array_filter([$payment->id, $payment->reversal_of_payment_id]))->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages(['payment_link' => 'Gateway payments are retained for reconciliation. Review refunds in Razorpay with Accounts.']);
            }
        };
        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    public const METHODS = [
        'cash',
        'bank_transfer',
        'upi',
        'card',
        'cheque',
        'other',
    ];

    protected $fillable = [
        'organization_id',
        'customer_id',
        'rental_id',
        'invoice_id',
        'reversal_of_payment_id',
        'payment_date',
        'amount',
        'payment_method',
        'notes',
        'collection_recipient',
        'collection_vendor_key',
        'collection_vendor_name',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public static function normalizeMethod(?string $paymentMethod): ?string
    {
        $normalized = strtolower(trim((string) $paymentMethod));

        return match ($normalized) {
            '', null => null,
            'bank', 'bank transfer', 'bank_transfer' => 'bank_transfer',
            'upi' => 'upi',
            'cash' => 'cash',
            'card' => 'card',
            'cheque', 'check' => 'cheque',
            default => in_array($normalized, self::METHODS, true) ? $normalized : 'other',
        };
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function rental(): BelongsTo
    {
        return $this->belongsTo(Rental::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function originalPayment(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_payment_id');
    }

    public function reversal(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_payment_id');
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    public function paymentMethodLabel(): string
    {
        return match ($this->payment_method) {
            'bank_transfer' => 'Bank Transfer',
            'upi' => 'UPI',
            'card' => 'Card',
            'cheque' => 'Cheque',
            'cash' => 'Cash',
            default => ucfirst(str_replace('_', ' ', (string) ($this->payment_method ?: 'other'))),
        };
    }
}
