<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
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
        'payment_date',
        'amount',
        'payment_method',
        'notes',
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
