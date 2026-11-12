<?php

namespace App\Support;

use Carbon\CarbonInterface;
use App\Models\Delivery;
use App\Models\FollowUp;
use App\Models\Invoice;
use App\Models\Rental;
use App\Models\Sale;
use App\Models\User;
use App\Services\NotificationCenterService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class FollowUpManager
{
    public function available(): bool
    {
        return Schema::hasTable('follow_ups');
    }

    public const COLLECTION_MODES = [
        'cash' => 'Cash collection',
        'pay_on_delivery' => 'Payment on delivery',
    ];

    public function collectionAssignees(int $organizationId): Collection
    {
        if (!$this->available()) {
            return collect();
        }

        return User::query()->with('assignedRole')->where('organization_id', $organizationId)
            ->where('is_active', true)->orderBy('name')->get()
            ->filter(fn (User $user) => $user->isSales() || $user->isSalesRenewals());
    }

    public function collectionAssignment(Request $request): ?User
    {
        $request->validate([
            'collection_mode' => ['nullable', 'required_with:collection_assignee_id', Rule::in(array_keys(self::COLLECTION_MODES))],
            'collection_assignee_id' => ['nullable', 'required_with:collection_mode', 'integer'],
        ]);
        if (!$request->filled('collection_assignee_id')) {
            return null;
        }

        $assignee = $this->collectionAssignees((int) $request->user()->organization_id)
            ->firstWhere('id', $request->integer('collection_assignee_id'));
        if (!$assignee) {
            throw ValidationException::withMessages(['collection_assignee_id' => 'Select an active Sales team member.']);
        }

        return $assignee;
    }

    public function assignOrderCollection(Rental|Sale $order, Invoice $invoice, User $assignee, string $mode, User $actor): void
    {
        if ((float) $invoice->balance_amount <= 0) {
            throw ValidationException::withMessages(['collection_mode' => 'A collection can only be assigned when the invoice has an outstanding balance.']);
        }

        $followUp = FollowUp::create([
            'organization_id' => $order->organization_id,
            'customer_id' => $order->customer_id,
            'business_partner_id' => $order->business_partner_id,
            'partner_client_id' => $order->partner_client_id,
            'rental_id' => $order instanceof Rental ? $order->id : null,
            'sale_id' => $order instanceof Sale ? $order->id : null,
            'invoice_id' => $invoice->id,
            'assigned_user_id' => $assignee->id,
            'followup_type' => FollowUp::TYPE_PAYMENT,
            'title' => self::COLLECTION_MODES[$mode] . ' for ' . $invoice->invoice_number,
            'note' => 'Coordinate collection of the outstanding invoice balance. Record the payment once funds are received.',
            'due_at' => ($order instanceof Rental ? $order->start_date : $order->sale_date)->copy()->setTime(10, 0),
            'status' => FollowUp::STATUS_PENDING,
            'priority' => FollowUp::PRIORITY_HIGH,
            'created_by_user_id' => $actor->id,
            'is_system_generated' => false,
            'source' => 'order_collection:' . $mode,
        ]);

        app(NotificationCenterService::class)->notifyFollowUpAssignment($followUp, $assignee);
    }

    public function syncOperationalFollowUps(int $organizationId): void
    {
        if (!$this->available()) {
            return;
        }

        $this->ensureOverdueRenewalFollowUps($organizationId);
        $this->ensureOverduePaymentFollowUps($organizationId);
        $this->ensureOpenFailedPickupFollowUps($organizationId);
        $this->ensureOpenFailedDeliveryFollowUps($organizationId);
    }

    public function ensureOverdueRenewalFollowUps(int $organizationId): void
    {
        if (!$this->available()) {
            return;
        }

        $this->withoutRecordedSourceFollowUp(Rental::query(), 'auto:renewal_overdue', 'rental_id')
            ->where('organization_id', $organizationId)
            ->whereNotIn('status', ['returned', 'cancelled'])
            ->whereDate('end_date', '<', Carbon::today())
            ->with(['customer', 'businessPartner', 'partnerClient', 'product'])
            ->chunkById(100, function ($rentals): void {
                foreach ($rentals as $rental) {
                    if ($this->hasOpenSourceFollowUp('auto:renewal_overdue', [
                        'organization_id' => $rental->organization_id,
                        'rental_id' => $rental->id,
                    ])) {
                        continue;
                    }

                    if ($this->hasCompletedSourceFollowUp('auto:renewal_overdue', [
                        'organization_id' => $rental->organization_id,
                        'rental_id' => $rental->id,
                    ])) {
                        continue;
                    }

                    FollowUp::create([
                        'organization_id' => $rental->organization_id,
                        'customer_id' => $rental->customer_id,
                        'business_partner_id' => $rental->business_partner_id,
                        'partner_client_id' => $rental->partner_client_id,
                        'rental_id' => $rental->id,
                        'followup_type' => FollowUp::TYPE_RENEWAL,
                        'title' => 'Overdue renewal for Rental #' . $rental->id,
                        'note' => 'Rental renewal is overdue for ' . ($rental->product->name ?? 'rental item') . '.',
                        'due_at' => optional($rental->end_date)->copy()?->endOfDay() ?? Carbon::now(),
                        'status' => FollowUp::STATUS_PENDING,
                        'priority' => FollowUp::PRIORITY_HIGH,
                        'is_system_generated' => true,
                        'source' => 'auto:renewal_overdue',
                    ]);
                }
            });
    }

    public function ensureOverduePaymentFollowUps(int $organizationId): void
    {
        if (!$this->available()) {
            return;
        }

        $this->withoutRecordedSourceFollowUp(Invoice::query(), 'auto:payment_overdue', 'invoice_id')
            ->where('organization_id', $organizationId)
            ->whereNotIn('status', ['cancelled', 'void'])
            ->whereNotIn('payment_status', ['paid', 'cancelled'])
            ->where('balance_amount', '>', 0)
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', Carbon::today())
            ->with([
                'customer',
                'rental.customer',
                'rental.businessPartner',
                'rental.partnerClient',
                'sale.customer',
                'sale.businessPartner',
                'sale.partnerClient',
            ])
            ->chunkById(100, function ($invoices): void {
                foreach ($invoices as $invoice) {
                    if ($this->hasOpenSourceFollowUp('auto:payment_overdue', [
                        'organization_id' => $invoice->organization_id,
                        'invoice_id' => $invoice->id,
                    ])) {
                        continue;
                    }

                    if ($this->hasCompletedSourceFollowUp('auto:payment_overdue', [
                        'organization_id' => $invoice->organization_id,
                        'invoice_id' => $invoice->id,
                    ])) {
                        continue;
                    }

                    FollowUp::create([
                        'organization_id' => $invoice->organization_id,
                        'customer_id' => $invoice->customer_id ?: ($invoice->rental?->customer_id ?? $invoice->sale?->customer_id),
                        'business_partner_id' => $invoice->rental?->business_partner_id ?? $invoice->sale?->business_partner_id,
                        'partner_client_id' => $invoice->rental?->partner_client_id ?? $invoice->sale?->partner_client_id,
                        'rental_id' => $invoice->linkedRentalId(),
                        'sale_id' => $invoice->sale_id,
                        'invoice_id' => $invoice->id,
                        'followup_type' => FollowUp::TYPE_PAYMENT,
                        'title' => 'Overdue payment for ' . ($invoice->invoice_number ?: 'Invoice #' . $invoice->id),
                        'note' => 'Outstanding invoice balance requires payment follow-up.',
                        'due_at' => optional($invoice->due_date)->copy()?->endOfDay() ?? Carbon::now(),
                        'status' => FollowUp::STATUS_PENDING,
                        'priority' => FollowUp::PRIORITY_HIGH,
                        'is_system_generated' => true,
                        'source' => 'auto:payment_overdue',
                    ]);
                }
            });
    }

    public function ensureFailedPickupFollowUp(Delivery $delivery, ?string $note = null, ?CarbonInterface $dueAt = null): ?FollowUp
    {
        if (!$this->available() || $delivery->type !== 'pickup') {
            return null;
        }

        return $this->createEventFollowUp($delivery, FollowUp::TYPE_PICKUP, 'auto:pickup_failed', 'Pickup follow-up needed', $note, $dueAt);
    }

    public function ensureFailedDeliveryFollowUp(Delivery $delivery, ?string $note = null, ?CarbonInterface $dueAt = null): ?FollowUp
    {
        if (!$this->available() || $delivery->type !== 'delivery') {
            return null;
        }

        return $this->createEventFollowUp($delivery, FollowUp::TYPE_DELIVERY, 'auto:delivery_failed', 'Delivery follow-up needed', $note, $dueAt);
    }

    public function ensureOpenFailedPickupFollowUps(int $organizationId): void
    {
        if (!$this->available()) {
            return;
        }

        $this->withoutRecordedSourceFollowUp(Delivery::query(), 'auto:pickup_failed', 'delivery_id')
            ->where('organization_id', $organizationId)
            ->where('type', 'pickup')
            ->where('pickup_status', 'failed_attempt')
            ->with(['rental.customer', 'rental.businessPartner', 'rental.partnerClient', 'rental.product'])
            ->chunkById(100, function ($deliveries): void {
                foreach ($deliveries as $delivery) {
                    $this->ensureFailedPickupFollowUp(
                        $delivery,
                        $delivery->failed_attempt_note ?: ('Reason: ' . Delivery::failedAttemptReasonLabel($delivery->failed_attempt_reason)),
                        optional($delivery->scheduled_at)->copy()->addDay() ?? Carbon::now()->addHours(2)
                    );
                }
            });
    }

    public function ensureOpenFailedDeliveryFollowUps(int $organizationId): void
    {
        if (!$this->available()) {
            return;
        }

        $this->withoutRecordedSourceFollowUp(Delivery::query(), 'auto:delivery_failed', 'delivery_id')
            ->where('organization_id', $organizationId)
            ->where('type', 'delivery')
            ->where('status', 'cancelled')
            ->whereNotNull('cancellation_reason')
            ->with(['rental.customer', 'rental.businessPartner', 'rental.partnerClient', 'rental.product', 'sale.customer', 'sale.businessPartner', 'sale.partnerClient', 'sale.product'])
            ->chunkById(100, function ($deliveries): void {
                foreach ($deliveries as $delivery) {
                    $this->ensureFailedDeliveryFollowUp(
                        $delivery,
                        $delivery->cancellation_notes ?: ('Reason: ' . Delivery::cancellationReasonLabel($delivery->cancellation_reason)),
                        Carbon::now()->addHours(2)
                    );
                }
            });
    }

    private function createEventFollowUp(Delivery $delivery, string $type, string $source, string $prefix, ?string $note = null, ?CarbonInterface $dueAt = null): ?FollowUp
    {
        if ($this->hasOpenSourceFollowUp($source, [
            'organization_id' => $delivery->organization_id,
            'delivery_id' => $delivery->id,
        ])) {
            return FollowUp::query()
                ->where('organization_id', $delivery->organization_id)
                ->where('delivery_id', $delivery->id)
                ->where('source', $source)
                ->where('status', FollowUp::STATUS_PENDING)
                ->latest('id')
                ->first();
        }

        if ($this->hasCompletedSourceFollowUp($source, [
            'organization_id' => $delivery->organization_id,
            'delivery_id' => $delivery->id,
        ])) {
            return null;
        }

        return FollowUp::create([
            'organization_id' => $delivery->organization_id,
            'customer_id' => $delivery->rental?->customer_id ?? $delivery->sale?->customer_id,
            'business_partner_id' => $delivery->rental?->business_partner_id ?? $delivery->sale?->business_partner_id,
            'partner_client_id' => $delivery->rental?->partner_client_id ?? $delivery->sale?->partner_client_id,
            'rental_id' => $delivery->rental_id,
            'sale_id' => $delivery->sale_id,
            'invoice_id' => $delivery->rental?->invoice?->id ?? $delivery->sale?->linked_invoice_id,
            'delivery_id' => $delivery->id,
            'followup_type' => $type,
            'title' => $prefix . ' for ' . ($delivery->type === 'pickup' ? 'Pickup #' : 'Task #') . $delivery->id,
            'note' => $note,
            'due_at' => $dueAt ?? Carbon::now()->addHours(2),
            'status' => FollowUp::STATUS_PENDING,
            'priority' => FollowUp::PRIORITY_HIGH,
            'is_system_generated' => true,
            'source' => $source,
        ]);
    }

    private function withoutRecordedSourceFollowUp(Builder $records, string $source, string $foreignKey): Builder
    {
        $record = $records->getModel();

        return $records->whereNotExists(
            FollowUp::query()->selectRaw('1')
                ->whereColumn('follow_ups.organization_id', $record->qualifyColumn('organization_id'))
                ->whereColumn('follow_ups.'.$foreignKey, $record->getQualifiedKeyName())
                ->where('source', $source)
                ->whereIn('status', [FollowUp::STATUS_PENDING, FollowUp::STATUS_COMPLETED, FollowUp::STATUS_CANCELLED])
        );
    }

    private function hasOpenSourceFollowUp(string $source, array $match): bool
    {
        return FollowUp::query()
            ->where($match)
            ->where('source', $source)
            ->where('status', FollowUp::STATUS_PENDING)
            ->exists();
    }

    private function hasCompletedSourceFollowUp(string $source, array $match): bool
    {
        return FollowUp::query()
            ->where($match)
            ->where('source', $source)
            ->whereIn('status', [FollowUp::STATUS_COMPLETED, FollowUp::STATUS_CANCELLED])
            ->exists();
    }
}
