<?php

namespace App\Services;

use App\Models\Delivery;
use App\Models\FollowUp;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\OperationalInAppNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class NotificationCenterService
{
    private ?bool $hasNotificationsTable = null;

    public function available(): bool
    {
        return $this->hasNotificationsTable ??= Schema::hasTable('notifications');
    }

    public function unreadCountFor(User $user): int
    {
        if (!$this->available()) {
            return 0;
        }

        return (int) $user->unreadNotifications()->count();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function latestFor(User $user, int $limit = 8): Collection
    {
        if (!$this->available()) {
            return collect();
        }

        return $user->notifications()
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (DatabaseNotification $notification) => $this->transform($notification))
            ->values();
    }

    public function paginateFor(User $user, int $perPage = 20): LengthAwarePaginator
    {
        if (!$this->available()) {
            return new Paginator([], 0, $perPage);
        }

        return $user->notifications()
            ->latest()
            ->paginate($perPage)
            ->through(fn (DatabaseNotification $notification) => $this->transform($notification));
    }

    public function markAsRead(User $user, string $notificationId): bool
    {
        if (!$this->available()) {
            return false;
        }

        $notification = $user->notifications()->whereKey($notificationId)->first();

        if (!$notification) {
            return false;
        }

        if ($notification->read_at === null) {
            $notification->markAsRead();
        }

        return true;
    }

    public function markAllAsRead(User $user): int
    {
        if (!$this->available()) {
            return 0;
        }

        return $user->unreadNotifications()->update([
            'read_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<int, string>  $notificationIds
     */
    public function markManyAsRead(User $user, array $notificationIds): int
    {
        if (!$this->available()) {
            return 0;
        }

        $ids = collect($notificationIds)
            ->filter(fn ($id) => is_string($id) && $id !== '')
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return 0;
        }

        return $user->unreadNotifications()
            ->whereIn('id', $ids->all())
            ->update([
                'read_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function notifyUser(User $user, array $payload): void
    {
        if (!$this->available() || !$user->is_active) {
            return;
        }

        $payload['organization_id'] = $payload['organization_id'] ?? $user->organization_id;
        $user->notify(new OperationalInAppNotification($payload));
    }

    /**
     * @param  iterable<User>  $users
     * @param  array<string, mixed>  $payload
     */
    public function notifyUsers(iterable $users, array $payload): void
    {
        $notified = [];

        foreach ($users as $user) {
            if (!$user instanceof User) {
                continue;
            }

            if (isset($notified[$user->id])) {
                continue;
            }

            $notified[$user->id] = true;
            $this->notifyUser($user, $payload);
        }
    }

    public function notifyDeliveryAssignment(Delivery $delivery, User $recipient): void
    {
        $contactName = $delivery->linkedCustomerName();
        $city = $delivery->linkedCustomerCity();
        $typeLabel = $delivery->isPickup() ? 'pickup task' : 'delivery task';
        $message = trim($contactName . ($city ? ', ' . $city : ''));

        $this->notifyUser($recipient, [
            'type' => $delivery->isPickup() ? 'pickup_assigned' : 'delivery_assigned',
            'title' => 'New ' . $typeLabel . ' assigned',
            'message' => $message !== '' ? $message : 'A new ' . $typeLabel . ' has been assigned to you.',
            'action_url' => route('deliveries.show', $delivery),
            'priority' => 'high',
            'related_type' => Delivery::class,
            'related_id' => $delivery->id,
            'meta' => [
                'task_type' => $delivery->type,
                'contact_name' => $contactName,
                'contact_phone' => $delivery->linkedCustomerPhone(),
                'address' => $delivery->linkedCustomerAddress(),
                'map_url' => $delivery->linkedCustomerMapUrl(),
            ],
        ]);
    }

    public function notifyFollowUpAssignment(FollowUp $followUp, User $recipient): void
    {
        $contactName = $followUp->isServiceContactType()
            ? $followUp->serviceContactName()
            : $followUp->reminderContactName();

        $this->notifyUser($recipient, [
            'type' => 'followup_assigned',
            'title' => 'New follow-up assigned',
            'message' => trim($followUp->typeLabel() . ' · ' . ($contactName ?: $followUp->title)),
            'action_url' => route('communication-center.index'),
            'priority' => $followUp->priority ?: 'medium',
            'related_type' => FollowUp::class,
            'related_id' => $followUp->id,
            'meta' => [
                'followup_type' => $followUp->followup_type,
                'due_at' => optional($followUp->due_at)->toDateTimeString(),
                'contact_name' => $contactName,
                'contact_phone' => $followUp->isServiceContactType()
                    ? $followUp->serviceContactPhone()
                    : $followUp->reminderContactPhone(),
            ],
        ]);
    }

    public function notifyDeliveryStatusChange(Delivery $delivery, iterable $recipients, string $eventType, string $title, string $message, string $priority = 'medium'): void
    {
        $this->notifyUsers($recipients, [
            'type' => $eventType,
            'title' => $title,
            'message' => $message,
            'action_url' => route('deliveries.show', $delivery),
            'priority' => $priority,
            'related_type' => Delivery::class,
            'related_id' => $delivery->id,
            'meta' => [
                'task_type' => $delivery->type,
                'contact_name' => $delivery->linkedCustomerName(),
                'contact_phone' => $delivery->linkedCustomerPhone(),
                'address' => $delivery->linkedCustomerAddress(),
            ],
        ]);
    }

    public function notifyPaymentRecorded(Payment $payment, iterable $recipients): void
    {
        $invoice = $payment->invoice;
        $this->notifyUsers($recipients, [
            'type' => 'payment_recorded',
            'title' => 'Payment recorded',
            'message' => trim(($invoice?->invoice_number ? $invoice->invoice_number . ' · ' : '') . ($payment->customer?->displayName() ?: ($invoice?->bill_to_name ?: 'Customer payment updated'))),
            'action_url' => $invoice ? route('invoices.show', $invoice) : ($payment->rental_id ? route('rentals.show', $payment->rental_id) : null),
            'priority' => 'medium',
            'related_type' => Payment::class,
            'related_id' => $payment->id,
            'meta' => [
                'invoice_id' => $invoice?->id,
                'invoice_number' => $invoice?->invoice_number,
                'payment_date' => optional($payment->payment_date)->toDateString(),
            ],
        ]);
    }

    /**
     * @return Collection<int, User>
     */
    public function organizationManagers(int $organizationId): Collection
    {
        return User::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->with('assignedRole')
            ->get()
            ->filter(fn (User $user) => $user->isSuperAdmin() || $user->isAdminOperations() || $user->hasPermission('dashboard.main'))
            ->values();
    }

    /**
     * @return Collection<int, User>
     */
    public function organizationFinanceUsers(int $organizationId): Collection
    {
        return User::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->with('assignedRole')
            ->get()
            ->filter(fn (User $user) => $user->canAccessModule('payments', 'read') || $user->canAccessModule('invoices', 'read'))
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    public function transform(DatabaseNotification $notification): array
    {
        $data = $notification->data ?? [];
        $createdAt = $notification->created_at instanceof Carbon
            ? $notification->created_at
            : Carbon::parse($notification->created_at);

        return [
            'id' => $notification->id,
            'type' => $data['type'] ?? class_basename($notification->type),
            'title' => $data['title'] ?? 'Operational update',
            'message' => $data['message'] ?? 'A new update is available.',
            'action_url' => $data['action_url'] ?? null,
            'priority' => $data['priority'] ?? 'medium',
            'related_type' => $data['related_type'] ?? null,
            'related_id' => $data['related_id'] ?? null,
            'meta' => $data['meta'] ?? [],
            'read_at' => optional($notification->read_at)?->toISOString(),
            'is_unread' => $notification->read_at === null,
            'created_at' => $createdAt->toISOString(),
            'time_ago' => $createdAt->diffForHumans(),
        ];
    }
}
