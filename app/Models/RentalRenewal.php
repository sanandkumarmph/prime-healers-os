<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RentalRenewal extends Model
{
    protected $fillable = [
        'organization_id',
        'rental_id',
        'previous_end_date',
        'renewed_end_date',
        'renewal_days',
        'renewal_type',
        'rental_amount_added',
        'deposit_amount_added',
        'transport_amount_added',
        'other_amount_added',
        'payment_id',
        'invoice_id',
        'payment_amount',
        'payment_method',
        'reminder_sent_at',
        'reminder_channel',
        'notes',
        'renewed_by_user_id',
    ];

    protected $casts = [
        'previous_end_date' => 'date',
        'renewed_end_date' => 'date',
        'renewal_days' => 'integer',
        'rental_amount_added' => 'decimal:2',
        'deposit_amount_added' => 'decimal:2',
        'transport_amount_added' => 'decimal:2',
        'other_amount_added' => 'decimal:2',
        'payment_amount' => 'decimal:2',
        'reminder_sent_at' => 'datetime',
    ];

    public function rental(): BelongsTo
    {
        return $this->belongsTo(Rental::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function renewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'renewed_by_user_id');
    }

    public function totalAdded(): float
    {
        return round(
            (float) ($this->rental_amount_added ?? 0)
            + (float) ($this->deposit_amount_added ?? 0)
            + (float) ($this->transport_amount_added ?? 0)
            + (float) ($this->other_amount_added ?? 0),
            2
        );
    }

    public function directPaidAmount(): float
    {
        if ($this->relationLoaded('payment') && $this->payment) {
            return round((float) ($this->payment->amount ?? 0), 2);
        }

        return round((float) ($this->payment_amount ?? 0), 2);
    }

    public function outstandingAmount(): float
    {
        if ($this->relationLoaded('invoice') && $this->invoice) {
            return round((float) ($this->invoice->balance_amount ?? 0), 2);
        }

        return round(max($this->totalAdded() - $this->directPaidAmount(), 0), 2);
    }

    public function invoiceWorkflowStatus(): string
    {
        if ($this->relationLoaded('invoice') && $this->invoice) {
            return (string) ($this->invoice->payment_status ?: $this->invoice->status ?: 'generated');
        }

        return $this->invoice_id ? 'generated' : 'not_generated';
    }

    public function paymentWorkflowStatus(): string
    {
        if ($this->relationLoaded('invoice') && $this->invoice) {
            return (string) ($this->invoice->payment_status ?: 'pending');
        }

        $total = $this->totalAdded();
        $paid = $this->directPaidAmount();

        if ($total <= 0) {
            return 'pending';
        }

        if ($paid >= $total) {
            return 'paid';
        }

        if ($paid > 0) {
            return 'partial';
        }

        return 'pending';
    }

    public function canBeEdited(): bool
    {
        if (!$this->exists || !$this->rental_id || !$this->organization_id) {
            return false;
        }

        return (int) static::query()
            ->where('organization_id', $this->organization_id)
            ->where('rental_id', $this->rental_id)
            ->max('id') === (int) $this->id;
    }
}
