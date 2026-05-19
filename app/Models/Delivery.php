<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Delivery extends Model
{
    public const TYPES = ['delivery', 'pickup'];

    public const STATUSES = ['pending', 'in_progress', 'completed', 'cancelled'];

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
        'notes',
        'cancellation_reason',
        'cancellation_notes',
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
}
