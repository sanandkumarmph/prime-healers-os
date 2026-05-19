<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Delivery extends Model
{
    public const TYPES = ['delivery', 'pickup'];

    public const STATUSES = ['pending', 'in_progress', 'completed', 'cancelled'];

    public const PICKUP_STATUSES = [
        'requested',
        'scheduled',
        'assigned',
        'in_progress',
        'picked_up',
        'failed_attempt',
        'rescheduled',
        'cancelled',
    ];

    public const FAILED_ATTEMPT_REASONS = [
        'customer_not_available',
        'address_not_found',
        'phone_not_reachable',
        'pickup_refused',
        'product_not_ready',
        'other',
    ];

    public const CANCELLATION_REASONS = [
        'customer_unavailable',
        'customer_requested_reschedule',
        'wrong_address',
        'product_unavailable',
        'payment_issue',
        'other',
    ];

    public const ASSIGNMENT_TYPES = ['delivery_team', 'vendor', 'third_party'];

    protected $fillable = [
        'rental_id',
        'sale_id',
        'type',
        'assigned_to',
        'scheduled_at',
        'status',
        'pickup_status',
        'pickup_time_slot',
        'notes',
        'cancellation_reason',
        'cancellation_notes',
        'failed_attempt_reason',
        'failed_attempt_note',
        'failed_attempt_at',
        'last_pickup_note',
        'last_pickup_note_at',
        'rescheduled_from_at',
        'completed_at',
        'organization_id',
        'assignment_type',
        'assigned_user_id',
        'assigned_staff_id',
        'third_party_name',
        'third_party_contact',
        'third_party_phone',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_attempt_at' => 'datetime',
        'last_pickup_note_at' => 'datetime',
        'rescheduled_from_at' => 'datetime',
    ];

    public function rental()
    {
        return $this->belongsTo(Rental::class);
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function assignedStaff()
    {
        return $this->belongsTo(Staff::class, 'assigned_staff_id');
    }

    public function proofs()
    {
        return $this->hasMany(DeliveryProof::class);
    }

    public function linkedCustomerName(): string
    {
        if ($this->sale) {
            return $this->sale->deliveryContactName();
        }

        if ($this->rental) {
            return $this->rental->deliveryContactName();
        }

        return 'Customer';
    }

    public function linkedCustomerPhone(): ?string
    {
        if ($this->sale) {
            return $this->sale->deliveryContactPhone();
        }

        if ($this->rental) {
            return $this->rental->deliveryContactPhone();
        }

        return null;
    }

    public function linkedCustomerAddress(): ?string
    {
        if ($this->sale) {
            return $this->sale->deliveryContactAddress();
        }

        if ($this->rental) {
            return $this->rental->deliveryContactAddress();
        }

        return null;
    }

    public function linkedCustomerCity(): ?string
    {
        if ($this->sale) {
            return $this->sale->deliveryContactCity();
        }

        if ($this->rental) {
            return $this->rental->deliveryContactCity();
        }

        return null;
    }

    public function linkedCustomerMapUrl(): ?string
    {
        if ($this->sale) {
            return $this->sale->deliveryContactMapUrl();
        }

        if ($this->rental) {
            return $this->rental->deliveryContactMapUrl();
        }

        return null;
    }

    public function linkedCustomerNotes(): ?string
    {
        if ($this->sale) {
            return $this->sale->deliveryContactNotes();
        }

        if ($this->rental) {
            return $this->rental->deliveryContactNotes();
        }

        return null;
    }

    public static function cancellationReasonLabel(?string $reason): string
    {
        return match ($reason) {
            'customer_unavailable' => 'Customer unavailable',
            'customer_requested_reschedule' => 'Customer requested reschedule',
            'wrong_address' => 'Wrong address',
            'product_unavailable' => 'Product unavailable',
            'payment_issue' => 'Payment issue',
            'other' => 'Other',
            default => ucfirst(str_replace('_', ' ', (string) $reason)),
        };
    }

    public static function failedAttemptReasonLabel(?string $reason): string
    {
        return match ($reason) {
            'customer_not_available' => 'Customer not available',
            'address_not_found' => 'Address not found',
            'phone_not_reachable' => 'Phone not reachable',
            'pickup_refused' => 'Pickup refused',
            'product_not_ready' => 'Product not ready',
            'other' => 'Other',
            default => ucfirst(str_replace('_', ' ', (string) $reason)),
        };
    }

    public function isPickup(): bool
    {
        return $this->type === 'pickup';
    }

    public function pickupOperationalStatus(): ?string
    {
        if (!$this->isPickup()) {
            return null;
        }

        if (filled($this->pickup_status)) {
            return (string) $this->pickup_status;
        }

        return match ($this->status) {
            'completed' => 'picked_up',
            'cancelled' => 'cancelled',
            'in_progress' => 'in_progress',
            default => ($this->assigned_user_id || $this->assigned_staff_id || filled($this->third_party_name))
                ? 'assigned'
                : 'requested',
        };
    }

    public function pickupOperationalLabel(): string
    {
        return match ($this->pickupOperationalStatus()) {
            'requested' => 'Requested',
            'scheduled' => 'Scheduled',
            'assigned' => 'Assigned',
            'in_progress' => 'In Progress',
            'picked_up' => 'Picked Up',
            'failed_attempt' => 'Failed Attempt',
            'rescheduled' => 'Rescheduled',
            'cancelled' => 'Cancelled',
            default => ucfirst(str_replace('_', ' ', (string) $this->pickupOperationalStatus())),
        };
    }

    public function reminderContactName(): string
    {
        if ($this->sale) {
            return $this->sale->reminderContactName();
        }

        if ($this->rental) {
            return $this->rental->reminderContactName();
        }

        return $this->linkedCustomerName();
    }

    public function reminderContactPhone(): ?string
    {
        if ($this->sale) {
            return $this->sale->reminderContactPhone();
        }

        if ($this->rental) {
            return $this->rental->reminderContactPhone();
        }

        return $this->linkedCustomerPhone();
    }

    public function paymentPending(): bool
    {
        $invoice = $this->rental?->invoice ?? $this->sale?->invoice;

        if (!$invoice) {
            return false;
        }

        return !in_array((string) $invoice->payment_status, ['paid', 'cancelled'], true)
            && (float) ($invoice->balance_amount ?? 0) > 0;
    }

    public function lastPickupNote(): ?string
    {
        return trim((string) (
            $this->last_pickup_note
            ?: $this->failed_attempt_note
            ?: $this->notes
            ?: $this->cancellation_notes
            ?: ''
        )) ?: null;
    }
}
