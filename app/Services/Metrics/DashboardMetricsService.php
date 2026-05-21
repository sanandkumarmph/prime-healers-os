<?php

namespace App\Services\Metrics;

use App\Models\Delivery;
use App\Models\FollowUp;
use App\Models\Payment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class DashboardMetricsService
{
    public function __construct(
        private readonly RentalMetricsService $rentalMetrics,
        private readonly SalesMetricsService $salesMetrics,
        private readonly InvoiceMetricsService $invoiceMetrics,
        private readonly FinanceMetricsService $financeMetrics,
        private readonly CollectionMetricsService $collectionMetrics,
        private readonly LogisticsMetricsService $logisticsMetrics,
        private readonly InventoryMetricsService $inventoryMetrics,
    ) {
    }

    public function rentalHeadline(Builder $summaryQuery, ?Carbon $today = null): array
    {
        return $this->rentalMetrics->headlineSnapshot($summaryQuery, $today);
    }

    public function rentalLifecycle(Collection $rentals, ?Carbon $today = null): array
    {
        return $this->rentalMetrics->dashboardLifecycleSnapshot($rentals, $today);
    }

    public function salesSummary(Builder $salesQuery, int $organizationId, bool $hasInvoiceSaleColumn = false): array
    {
        return $this->salesMetrics->summary($salesQuery, $organizationId, $hasInvoiceSaleColumn);
    }

    public function invoiceSummary(Builder $invoiceQuery, ?Carbon $today = null): array
    {
        return $this->invoiceMetrics->summary($invoiceQuery, $today);
    }

    public function financeSummary(
        Builder $rentalQuery,
        array $salesSummary,
        array $invoiceSummary,
        array $pendingReceivables = [],
        array $renewalFinance = []
    ): array {
        return $this->financeMetrics->summary(
            $rentalQuery,
            $salesSummary,
            $invoiceSummary,
            $pendingReceivables,
            $renewalFinance
        );
    }

    public function collectionSummary(Builder $paymentQuery, ?Carbon $today = null): array
    {
        return $this->collectionMetrics->summary($paymentQuery, $today);
    }

    public function logisticsSummary(Collection $deliveries, ?Carbon $today = null): array
    {
        return $this->logisticsMetrics->summary($deliveries, $today);
    }

    public function inventorySummary(int $organizationId): array
    {
        return $this->inventoryMetrics->summary($organizationId);
    }

    public function renewalCounts(Builder $baseQuery, ?Carbon $today = null): array
    {
        $today = ($today ?? Carbon::today())->copy()->startOfDay();
        $tabs = ['due_today', 'next_7_days', 'overdue', 'awaiting_confirmation', 'pickup_requested', 'renewed', 'all'];
        $counts = [];

        foreach ($tabs as $tab) {
            $counts[$tab] = (clone $this->applyRenewalTab(clone $baseQuery, $tab, $today))->count();
        }

        return $counts;
    }

    public function pickupCounts(Builder $baseQuery, ?Carbon $today = null): array
    {
        $today = ($today ?? Carbon::today())->copy()->startOfDay();
        $tabs = ['pickup_requested', 'scheduled_today', 'upcoming', 'overdue', 'failed_attempt', 'picked_up', 'all'];
        $counts = [];

        foreach ($tabs as $tab) {
            $counts[$tab] = (clone $this->applyPickupTab(clone $baseQuery, $tab, $today))->count();
        }

        return $counts;
    }

    public function followUpCounts(Builder $baseQuery, ?Carbon $today = null): array
    {
        $today = ($today ?? Carbon::today())->copy()->startOfDay();
        $tabs = ['pending', 'today', 'overdue', 'renewals', 'payments', 'pickups', 'delivery', 'notes', 'all'];
        $counts = [];

        foreach ($tabs as $tab) {
            $counts[$tab] = (clone $this->applyFollowUpTab(clone $baseQuery, $tab, $today))->count();
        }

        return $counts;
    }

    public function scopePickupVisibility(Builder $query, ?User $user): Builder
    {
        if (!$user || !$user->hasScope('assigned', 'deliveries') || !Schema::hasColumn('deliveries', 'assigned_user_id')) {
            return $query;
        }

        return $query->where(function (Builder $scope) use ($user): void {
            $scope->where('assigned_user_id', $user->id)
                ->orWhere(function (Builder $fallback): void {
                    $fallback->whereNull('assigned_user_id');

                    if (Schema::hasColumn('deliveries', 'assigned_staff_id')) {
                        $fallback->whereNull('assigned_staff_id');
                    }

                    if (Schema::hasColumn('deliveries', 'assignment_type')) {
                        $fallback->where(function (Builder $assignmentQuery): void {
                            $assignmentQuery->whereNull('assignment_type')
                                ->orWhereIn('assignment_type', ['delivery_team', 'third_party', '']);
                        });
                    }
                });
        });
    }

    public function scopeFollowUpVisibility(Builder $query, ?User $user): Builder
    {
        if (!$user || !$user->hasScope('assigned', 'deliveries')) {
            return $query;
        }

        return $query->where(function (Builder $scoped) use ($user): void {
            $scoped->where('assigned_user_id', $user->id)
                ->orWhere(function (Builder $deliveryQuery) use ($user): void {
                    $deliveryQuery
                        ->whereIn('followup_type', [FollowUp::TYPE_PICKUP, FollowUp::TYPE_DELIVERY, FollowUp::TYPE_SERVICE])
                        ->whereHas('delivery', function (Builder $taskQuery) use ($user): void {
                            $taskQuery->where('assigned_user_id', $user->id);
                        });
                });
        });
    }

    public function integritySummary(int $organizationId): array
    {
        return [
            'rentals_without_party' => (int) \App\Models\Rental::query()
                ->where('organization_id', $organizationId)
                ->whereNull('customer_id')
                ->where(function (Builder $query): void {
                    if (Schema::hasColumn('rentals', 'business_partner_id')) {
                        $query->whereNull('business_partner_id');
                    }
                })
                ->count(),
            'partner_rentals_without_actual_client' => (int) (Schema::hasColumn('rentals', 'business_partner_id') && Schema::hasColumn('rentals', 'partner_client_id')
                ? \App\Models\Rental::query()
                    ->where('organization_id', $organizationId)
                    ->whereNotNull('business_partner_id')
                    ->whereNull('partner_client_id')
                    ->count()
                : 0),
            'deliveries_without_order_link' => (int) Delivery::query()
                ->where('organization_id', $organizationId)
                ->whereNull('rental_id')
                ->when(Schema::hasColumn('deliveries', 'sale_id'), fn (Builder $query) => $query->whereNull('sale_id'))
                ->count(),
            'invoices_without_business_link' => (int) \App\Models\Invoice::query()
                ->where('organization_id', $organizationId)
                ->whereNull('customer_id')
                ->where(function (Builder $query): void {
                    if (Schema::hasColumn('invoices', 'rental_id')) {
                        $query->whereNull('rental_id');
                    }

                    if (Schema::hasColumn('invoices', 'sale_id')) {
                        $query->whereNull('sale_id');
                    }
                })
                ->count(),
            'orphaned_payments' => (int) Payment::query()
                ->when(Schema::hasColumn('payments', 'organization_id'), fn (Builder $query) => $query->where('organization_id', $organizationId))
                ->where(function (Builder $query): void {
                    if (Schema::hasColumn('payments', 'invoice_id')) {
                        $query->whereNull('invoice_id');
                    }

                    if (Schema::hasColumn('payments', 'rental_id')) {
                        $query->whereNull('rental_id');
                    }

                    if (Schema::hasColumn('payments', 'customer_id')) {
                        $query->whereNull('customer_id');
                    }
                })
                ->count(),
        ];
    }

    private function applyRenewalTab(Builder $query, string $tab, Carbon $today): Builder
    {
        return match ($tab) {
            'due_today' => $query->whereDate('end_date', $today),
            'next_7_days' => $query->whereBetween('end_date', [$today->copy()->addDay(), $today->copy()->addDays(7)]),
            'overdue' => $query->whereDate('end_date', '<', $today),
            'awaiting_confirmation' => $query
                ->whereDate('end_date', '<=', $today->copy()->addDays(7))
                ->whereDoesntHave('pickupRecord', fn (Builder $pickupQuery) => $pickupQuery->whereIn('status', ['pending', 'in_progress']))
                ->when(
                    Schema::hasTable('rental_reminder_logs'),
                    fn (Builder $reminderQuery) => $reminderQuery->whereHas('reminderLogs')
                ),
            'pickup_requested' => $query->whereHas('pickupRecord', fn (Builder $pickupQuery) => $pickupQuery->whereIn('status', ['pending', 'in_progress'])),
            'renewed' => $query->when(
                Schema::hasTable('rental_renewals'),
                fn (Builder $renewedQuery) => $renewedQuery->whereHas('renewals', fn (Builder $renewalQuery) => $renewalQuery->whereDate('created_at', '>=', $today->copy()->subDays(30))),
                fn (Builder $renewedQuery) => $renewedQuery->whereRaw('1 = 0')
            ),
            default => $query,
        };
    }

    private function applyPickupTab(Builder $query, string $tab, Carbon $today): Builder
    {
        $openScheduled = function (Builder $openQuery): void {
            $openQuery->whereIn('status', ['pending', 'in_progress'])
                ->where(function (Builder $statusQuery): void {
                    $statusQuery->whereNull('pickup_status')
                        ->orWhereNotIn('pickup_status', ['failed_attempt', 'cancelled', 'picked_up']);
                });
        };

        return match ($tab) {
            'pickup_requested' => $query->where('status', 'pending')->whereIn('pickup_status', ['requested', 'scheduled', 'assigned', 'rescheduled']),
            'scheduled_today' => $query->where($openScheduled)->whereDate('scheduled_at', $today),
            'upcoming' => $query->where($openScheduled)->whereDate('scheduled_at', '>', $today),
            'overdue' => $query->where($openScheduled)->whereDate('scheduled_at', '<', $today),
            'failed_attempt' => $query->where('pickup_status', 'failed_attempt'),
            'picked_up' => $query->where(function (Builder $pickedUpQuery): void {
                $pickedUpQuery->where('status', 'completed')
                    ->orWhere('pickup_status', 'picked_up');
            }),
            default => $query,
        };
    }

    private function applyFollowUpTab(Builder $query, string $tab, Carbon $today): Builder
    {
        return match ($tab) {
            'pending' => $query->whereNotIn('status', [FollowUp::STATUS_COMPLETED, FollowUp::STATUS_CANCELLED]),
            'today' => $query->whereNotIn('status', [FollowUp::STATUS_COMPLETED, FollowUp::STATUS_CANCELLED])->whereDate('due_at', $today),
            'overdue' => $query->whereNotIn('status', [FollowUp::STATUS_COMPLETED, FollowUp::STATUS_CANCELLED])->where('due_at', '<', now()),
            'renewals' => $query->where('followup_type', FollowUp::TYPE_RENEWAL),
            'payments' => $query->where('followup_type', FollowUp::TYPE_PAYMENT),
            'pickups' => $query->where('followup_type', FollowUp::TYPE_PICKUP),
            'delivery' => $query->whereIn('followup_type', [FollowUp::TYPE_DELIVERY, FollowUp::TYPE_SERVICE]),
            'notes' => $query->whereIn('followup_type', [FollowUp::TYPE_GENERAL, FollowUp::TYPE_CALLBACK, FollowUp::TYPE_COMPLAINT, FollowUp::TYPE_ESCALATION]),
            default => $query,
        };
    }
}
