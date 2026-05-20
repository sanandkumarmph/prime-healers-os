<?php

namespace App\Models;

use App\Support\WhatsAppHelper;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class FollowUp extends Model
{
    public const TYPE_RENEWAL = 'renewal';
    public const TYPE_PAYMENT = 'payment';
    public const TYPE_PICKUP = 'pickup';
    public const TYPE_DELIVERY = 'delivery';
    public const TYPE_SERVICE = 'service';
    public const TYPE_CALLBACK = 'callback';
    public const TYPE_COMPLAINT = 'complaint';
    public const TYPE_GENERAL = 'general';
    public const TYPE_ESCALATION = 'escalation';

    public const STATUS_PENDING = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_OVERDUE = 'overdue';

    public const PRIORITY_LOW = 'low';
    public const PRIORITY_MEDIUM = 'medium';
    public const PRIORITY_HIGH = 'high';
    public const PRIORITY_URGENT = 'urgent';

    protected $fillable = [
        'organization_id',
        'customer_id',
        'business_partner_id',
        'partner_client_id',
        'rental_id',
        'sale_id',
        'invoice_id',
        'delivery_id',
        'assigned_user_id',
        'followup_type',
        'title',
        'note',
        'due_at',
        'status',
        'priority',
        'completed_at',
        'created_by_user_id',
        'is_system_generated',
        'source',
    ];

    protected $casts = [
        'due_at' => 'datetime',
        'completed_at' => 'datetime',
        'is_system_generated' => 'boolean',
    ];

    public const TYPES = [
        self::TYPE_RENEWAL => 'Renewal',
        self::TYPE_PAYMENT => 'Payment',
        self::TYPE_PICKUP => 'Pickup',
        self::TYPE_DELIVERY => 'Delivery',
        self::TYPE_SERVICE => 'Service',
        self::TYPE_CALLBACK => 'Callback',
        self::TYPE_COMPLAINT => 'Complaint',
        self::TYPE_GENERAL => 'General',
        self::TYPE_ESCALATION => 'Escalation',
    ];

    public const STATUSES = [
        self::STATUS_PENDING => 'Pending',
        self::STATUS_COMPLETED => 'Completed',
        self::STATUS_CANCELLED => 'Cancelled',
        self::STATUS_OVERDUE => 'Overdue',
    ];

    public const PRIORITIES = [
        self::PRIORITY_LOW => 'Low',
        self::PRIORITY_MEDIUM => 'Medium',
        self::PRIORITY_HIGH => 'High',
        self::PRIORITY_URGENT => 'Urgent',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function businessPartner(): BelongsTo
    {
        return $this->belongsTo(BusinessPartner::class);
    }

    public function partnerClient(): BelongsTo
    {
        return $this->belongsTo(PartnerClient::class);
    }

    public function rental(): BelongsTo
    {
        return $this->belongsTo(Rental::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function effectiveStatus(): string
    {
        if ($this->status === self::STATUS_PENDING && $this->due_at instanceof CarbonInterface && $this->due_at->isPast()) {
            return self::STATUS_OVERDUE;
        }

        return $this->status ?: self::STATUS_PENDING;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->effectiveStatus()] ?? ucfirst(str_replace('_', ' ', (string) $this->effectiveStatus()));
    }

    public function priorityLabel(): string
    {
        return self::PRIORITIES[$this->priority] ?? ucfirst((string) $this->priority);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->followup_type] ?? ucfirst(str_replace('_', ' ', (string) $this->followup_type));
    }

    public function isServiceContactType(): bool
    {
        return in_array($this->followup_type, [
            self::TYPE_PICKUP,
            self::TYPE_DELIVERY,
            self::TYPE_SERVICE,
        ], true);
    }

    public function reminderContactName(): ?string
    {
        if ($this->rental) {
            return $this->rental->reminderContactName();
        }

        if ($this->sale) {
            return $this->sale->reminderContactName();
        }

        if ($this->invoice) {
            return $this->invoice->bill_to_name ?: $this->invoice->customer?->displayName();
        }

        if ($this->delivery) {
            return $this->delivery->reminderContactName();
        }

        if ($this->businessPartner) {
            return $this->businessPartner->displayName();
        }

        if ($this->customer) {
            return $this->customer->displayName();
        }

        return $this->partnerClient?->displayName();
    }

    public function reminderContactPhone(): ?string
    {
        if ($this->rental) {
            return $this->rental->reminderContactPhone();
        }

        if ($this->sale) {
            return $this->sale->reminderContactPhone();
        }

        if ($this->invoice) {
            return $this->invoice->bill_to_phone ?: $this->invoice->customer?->phone;
        }

        if ($this->delivery) {
            return $this->delivery->reminderContactPhone();
        }

        if ($this->businessPartner) {
            return $this->businessPartner->preferredReminderNumber();
        }

        if ($this->customer) {
            return $this->customer->preferredWhatsAppNumber() ?: $this->customer->phone;
        }

        return $this->partnerClient?->primaryPhone();
    }

    public function serviceContactName(): ?string
    {
        if ($this->rental) {
            return $this->rental->deliveryContactName();
        }

        if ($this->sale) {
            return $this->sale->deliveryContactName();
        }

        if ($this->invoice) {
            return $this->invoice->ship_to_name ?: ($this->invoice->bill_to_name ?: $this->invoice->customer?->displayName());
        }

        if ($this->delivery) {
            return $this->delivery->linkedCustomerName();
        }

        if ($this->partnerClient) {
            return $this->partnerClient->displayName();
        }

        if ($this->customer) {
            return $this->customer->displayName();
        }

        return $this->businessPartner?->displayName();
    }

    public function serviceContactPhone(): ?string
    {
        if ($this->rental) {
            return $this->rental->deliveryContactPhone();
        }

        if ($this->sale) {
            return $this->sale->deliveryContactPhone();
        }

        if ($this->invoice) {
            return $this->invoice->ship_to_phone ?: ($this->invoice->bill_to_phone ?: $this->invoice->customer?->phone);
        }

        if ($this->delivery) {
            return $this->delivery->linkedCustomerPhone();
        }

        if ($this->partnerClient) {
            return $this->partnerClient->primaryPhone();
        }

        if ($this->customer) {
            return $this->customer->phone;
        }

        return $this->businessPartner?->preferredReminderNumber();
    }

    public function serviceContactAddress(): ?string
    {
        if ($this->rental) {
            return $this->rental->deliveryContactAddress();
        }

        if ($this->sale) {
            return $this->sale->deliveryContactAddress();
        }

        if ($this->invoice) {
            return $this->invoice->ship_to_address ?: $this->invoice->bill_to_address;
        }

        if ($this->delivery) {
            return $this->delivery->linkedCustomerAddress();
        }

        if ($this->partnerClient) {
            return $this->partnerClient->address;
        }

        if ($this->customer) {
            return $this->customer->address;
        }

        return $this->businessPartner?->address;
    }

    public function serviceContactMapUrl(): ?string
    {
        if ($this->rental) {
            return $this->rental->deliveryContactMapUrl();
        }

        if ($this->sale) {
            return $this->sale->deliveryContactMapUrl();
        }

        if ($this->delivery) {
            return $this->delivery->linkedCustomerMapUrl();
        }

        if ($this->partnerClient) {
            return $this->partnerClient->openMapUrl();
        }

        if ($this->customer) {
            return $this->customer->openMapUrl();
        }

        return $this->businessPartner?->openMapUrl();
    }

    public function callTargetName(): ?string
    {
        return $this->isServiceContactType()
            ? ($this->serviceContactName() ?: $this->reminderContactName())
            : ($this->reminderContactName() ?: $this->serviceContactName());
    }

    public function callTargetPhone(): ?string
    {
        return $this->isServiceContactType()
            ? ($this->serviceContactPhone() ?: $this->reminderContactPhone())
            : ($this->reminderContactPhone() ?: $this->serviceContactPhone());
    }

    public function whatsappUrl(): ?string
    {
        $number = WhatsAppHelper::normalizeNumber($this->callTargetPhone());

        return WhatsAppHelper::chatUrl($number, $this->messagePreview());
    }

    public function messagePreview(): string
    {
        if ($this->followup_type === self::TYPE_RENEWAL && $this->rental) {
            return WhatsAppHelper::rentalRenewalReminder($this->rental);
        }

        if ($this->followup_type === self::TYPE_PAYMENT && $this->invoice) {
            return WhatsAppHelper::paymentReminderForInvoice($this->invoice);
        }

        if ($this->followup_type === self::TYPE_PICKUP && $this->rental) {
            return WhatsAppHelper::rentalPickupReminder($this->rental);
        }

        if ($this->followup_type === self::TYPE_DELIVERY && $this->rental) {
            return WhatsAppHelper::rentalDeliveryConfirmation($this->rental);
        }

        if ($this->sale) {
            return WhatsAppHelper::saleFollowUp($this->sale);
        }

        $name = $this->callTargetName() ?: 'there';
        $reference = $this->referenceLabel();

        return trim("Hello {$name}, this is a follow-up from Prime Healers regarding {$reference}. Please get back to us when convenient.");
    }

    public function dueLabel(): string
    {
        if (!$this->due_at) {
            return 'No due time';
        }

        return $this->due_at->timezone(config('app.timezone'))->format('d M Y, h:i A');
    }

    public function overdueLabel(): ?string
    {
        if (!$this->due_at instanceof CarbonInterface || !$this->due_at->isPast() || $this->status !== self::STATUS_PENDING) {
            return null;
        }

        $minutes = $this->due_at->diffInMinutes(Carbon::now());

        if ($minutes < 60) {
            return $minutes . ' min overdue';
        }

        $hours = $this->due_at->diffInHours(Carbon::now());

        if ($hours < 24) {
            return $hours . ' hr overdue';
        }

        return $this->due_at->diffInDays(Carbon::now()) . ' day(s) overdue';
    }

    public function referenceLabel(): string
    {
        if ($this->rental_id) {
            return 'Rental #' . $this->rental_id;
        }

        if ($this->sale_id) {
            return 'Sale #' . $this->sale_id;
        }

        if ($this->invoice) {
            return 'Invoice ' . ($this->invoice->invoice_number ?: '#' . $this->invoice_id);
        }

        if ($this->delivery_id) {
            $prefix = $this->delivery?->type === 'pickup' ? 'Pickup #' : 'Task #';
            return $prefix . $this->delivery_id;
        }

        return 'General Follow-up';
    }

    public function productLabel(): ?string
    {
        return $this->rental?->product?->name
            ?? $this->sale?->product?->name
            ?? $this->delivery?->rental?->product?->name
            ?? $this->delivery?->sale?->product?->name;
    }
}
