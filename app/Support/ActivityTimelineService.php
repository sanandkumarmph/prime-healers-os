<?php

namespace App\Support;

use App\Models\ActivityLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ActivityTimelineService
{
    public const FILTERS = [
        'all' => 'All',
        'notes' => 'Notes',
        'payments' => 'Payments',
        'delivery' => 'Delivery/Pickup',
        'renewals' => 'Renewals',
        'invoices' => 'Invoices',
        'system' => 'System',
    ];

    public function forSubject(Model $subject, ?string $filter = 'all', int $perPage = 25, string $pageName = 'timeline_page'): LengthAwarePaginator
    {
        $filter = self::normalizeFilter($filter);
        $query = ActivityLogger::queryFor($subject)->with('user');

        $this->applyPermissionScope($query);
        $this->applyFilter($query, $filter);

        return $query
            ->latest()
            ->paginate($perPage, ['*'], $pageName)
            ->appends(['timeline_filter' => $filter]);
    }

    public static function normalizeFilter(?string $filter): string
    {
        $normalized = strtolower(trim((string) $filter));

        return array_key_exists($normalized, self::FILTERS) ? $normalized : 'all';
    }

    public static function filterOptions(): array
    {
        return self::FILTERS;
    }

    public static function actionCategory(ActivityLog|string $activity): string
    {
        $action = $activity instanceof ActivityLog ? $activity->action : (string) $activity;

        return match (true) {
            str_contains($action, 'note_added') || str_contains($action, 'followup') => 'notes',
            str_starts_with($action, 'payment.')
                || str_contains($action, 'marked_paid')
                || str_contains($action, 'partial_payment') => 'payments',
            str_starts_with($action, 'delivery.')
                || str_starts_with($action, 'pickup.') => 'delivery',
            str_starts_with($action, 'rental.renew')
                || str_contains($action, 'renewal')
                || $action === 'rental.pickup_scheduled' => 'renewals',
            str_starts_with($action, 'invoice.')
                || str_contains($action, 'invoice.generated') => 'invoices',
            default => 'system',
        };
    }

    public static function actionLabel(ActivityLog $log): string
    {
        return match ($log->action) {
            'customer.created' => 'Customer created',
            'customer.updated' => 'Customer updated',
            'customer.note_added' => 'Customer note added',
            'business_partner.created' => 'Business partner created',
            'business_partner.updated' => 'Business partner updated',
            'business_partner.note_added' => 'Business partner note added',
            'business_partner.actual_client_added' => 'Actual client added',
            'rental.created' => 'Rental created',
            'rental.updated' => 'Rental updated',
            'rental.note_added' => 'Rental note added',
            'rental.reminder.marked_sent' => 'Renewal reminder sent',
            'rental.pickup_scheduled' => 'Pickup scheduled',
            'rental.renewed' => 'Rental renewed',
            'rental.returned' => 'Rental closed',
            'sale.created' => 'Sale created',
            'sale.updated' => 'Sale updated',
            'sale.note_added' => 'Sale note added',
            'sale.invoice.generated' => 'Sale invoice generated',
            'invoice.created' => 'Invoice created',
            'invoice.updated' => 'Invoice updated',
            'invoice.marked_paid' => 'Invoice marked paid',
            'payment.recorded' => 'Payment received',
            'payment.deleted' => 'Payment deleted',
            'delivery.assigned' => 'Delivery assigned',
            'delivery.started' => 'Delivery started',
            'delivery.completed' => 'Delivered',
            'delivery.cancelled' => 'Delivery cancelled',
            'pickup.assigned' => 'Pickup assigned',
            'pickup.failed_attempt' => 'Pickup failed',
            'pickup.rescheduled' => 'Pickup rescheduled',
            'pickup.note_added' => 'Pickup note added',
            default => str($log->action)->replace('.', ' ')->replace('_', ' ')->title()->toString(),
        };
    }

    public static function categoryLabel(string $category): string
    {
        return self::FILTERS[self::normalizeFilter($category)] ?? 'Activity';
    }

    public static function relatedLinks(ActivityLog $log): array
    {
        $user = auth()->user();
        $links = [];

        if ($log->rental_id && $user?->canAccessModule('rentals', 'read')) {
            $links[] = ['label' => 'Rental', 'url' => route('rentals.show', $log->rental_id)];
        }

        if ($log->sale_id && $user?->canAccessModule('sales', 'read')) {
            $links[] = ['label' => 'Sale', 'url' => route('sales.show', $log->sale_id)];
        }

        if ($log->invoice_id && $user?->canAccessModule('invoices', 'read')) {
            $links[] = ['label' => 'Invoice', 'url' => route('invoices.show', $log->invoice_id)];
        }

        if ($log->delivery_id && $user?->canAccessModule('deliveries', 'read')) {
            $links[] = ['label' => 'Task', 'url' => route('deliveries.show', $log->delivery_id)];
        }

        if ($log->customer_id && $user?->canAccessModule('customers', 'read')) {
            $links[] = ['label' => 'Customer', 'url' => route('customers.show', $log->customer_id)];
        }

        if ($log->business_partner_id && $user?->canAccessModule('customers', 'read')) {
            $links[] = ['label' => 'Business Partner', 'url' => route('business-partners.show', $log->business_partner_id)];
        }

        return $links;
    }

    public static function timelineTags(ActivityLog $log): array
    {
        $properties = collect($log->properties ?? []);
        $tags = [];

        foreach ([
            'status' => 'Status',
            'payment_status' => 'Payment',
            'invoice_payment_status' => 'Invoice',
            'note_type' => 'Note',
            'reminder_type' => 'Reminder',
            'task_type' => 'Task',
            'failed_attempt_reason' => 'Failed',
            'cancellation_reason' => 'Cancelled',
            'invoice_number' => 'Invoice',
        ] as $key => $label) {
            $value = $properties->get($key);

            if (filled($value)) {
                $tags[] = [
                    'label' => $label,
                    'value' => str((string) $value)->replace('_', ' ')->title()->toString(),
                ];
            }
        }

        foreach ([
            'amount' => 'Amount',
            'sale_amount' => 'Amount',
            'rental_amount' => 'Amount',
            'total_amount' => 'Total',
            'balance_amount' => 'Balance',
        ] as $key => $label) {
            $value = $properties->get($key);

            if (is_numeric($value)) {
                $tags[] = [
                    'label' => $label,
                    'value' => CurrencyFormatter::format((float) $value),
                ];
            }
        }

        return $tags;
    }

    public static function contactSummary(ActivityLog $log): array
    {
        $properties = collect($log->properties ?? []);
        $reminderName = trim((string) $properties->get('reminder_contact_name', ''));
        $reminderPhone = trim((string) $properties->get('reminder_contact_phone', ''));
        $deliveryName = trim((string) $properties->get('delivery_contact_name', ''));
        $deliveryPhone = trim((string) $properties->get('delivery_contact_phone', ''));
        $deliveryAddress = trim((string) $properties->get('delivery_contact_address', ''));
        $deliveryMapUrl = trim((string) $properties->get('delivery_contact_map_url', ''));

        return [
            'reminder' => $reminderName !== '' || $reminderPhone !== ''
                ? trim($reminderName . ($reminderPhone !== '' ? ' • ' . $reminderPhone : ''))
                : null,
            'delivery' => $deliveryName !== '' || $deliveryPhone !== ''
                ? trim($deliveryName . ($deliveryPhone !== '' ? ' • ' . $deliveryPhone : ''))
                : null,
            'address' => $deliveryAddress !== '' ? $deliveryAddress : null,
            'map_url' => $deliveryMapUrl !== '' ? $deliveryMapUrl : null,
        ];
    }

    private function applyPermissionScope(Builder $query): void
    {
        $user = auth()->user();

        if (!$user?->canAccessModule('payments', 'read')) {
            $query->where('action', 'not like', 'payment.%');
        }

        if (!$user?->canAccessModule('invoices', 'read')) {
            $query->where('action', 'not like', 'invoice.%')
                ->where('action', 'not like', '%.invoice.%');
        }
    }

    private function applyFilter(Builder $query, string $filter): void
    {
        if ($filter === 'all') {
            return;
        }

        $query->where(function (Builder $innerQuery) use ($filter) {
            match ($filter) {
                'notes' => $innerQuery
                    ->where('action', 'like', '%note_added%')
                    ->orWhere('action', 'like', '%followup%'),
                'payments' => $innerQuery
                    ->where('action', 'like', 'payment.%')
                    ->orWhere('action', 'like', '%marked_paid%')
                    ->orWhere('action', 'like', '%partial_payment%'),
                'delivery' => $innerQuery
                    ->where('action', 'like', 'delivery.%')
                    ->orWhere('action', 'like', 'pickup.%'),
                'renewals' => $innerQuery
                    ->where('action', 'like', 'rental.renew%')
                    ->orWhere('action', 'like', '%renewal%')
                    ->orWhere('action', 'like', 'rental.pickup_scheduled'),
                'invoices' => $innerQuery
                    ->where('action', 'like', 'invoice.%')
                    ->orWhere('action', 'like', '%invoice.generated%'),
                'system' => $innerQuery
                    ->where('action', 'like', 'customer.%')
                    ->orWhere('action', 'like', 'business_partner.%')
                    ->orWhere('action', 'like', 'sale.updated')
                    ->orWhere('action', 'like', 'rental.updated')
                    ->orWhere('action', 'like', '%status_changed%'),
                default => null,
            };
        });
    }
}
