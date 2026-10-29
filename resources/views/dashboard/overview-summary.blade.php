@php
    // The former Business overview figures retain their exact widget/module gates.
    $overviewCards = [
        ['kpi_active_rentals', $dashboardOrderScope === 'mine' ? 'My active rentals' : 'Active rentals', number_format($activeRentals ?? 0), 'rentals.index', true],
        ['primary_pending_deliveries', $dashboardTaskScope === 'assigned' ? 'My pending deliveries' : 'Pending deliveries', number_format($pendingDeliveryCount ?? 0), 'deliveries.index', $canReadDeliveries],
        ['primary_pending_pickups', $dashboardTaskScope === 'assigned' ? 'My pending pickups' : 'Pending pickups', number_format($pendingPickupCount ?? 0), 'pickup-center.index', $canReadDeliveries],
        ['kpi_collections_this_month', 'Collections this month', $currency($paymentsReceivedThisMonth ?? 0), 'invoices.index', $canViewFinance && $currentUser->canAccessModule('payments', 'read') && $canReadInvoices],
        ['primary_outstanding_dues', 'Outstanding invoices', $currency($outstandingDueAmount ?? 0), 'invoices.index', $canViewFinance && $canReadInvoices],
        ['kpi_rental_available', 'Rental units available', number_format($availableRentalAssets ?? 0), 'inventory.dashboard', $currentUser->hasPermission('dashboard.inventory')],
    ];
    $overviewQueues = [
        ['primary_overdue_rentals', 'Review overdue rentals', $overdueCount ?? 0, 'renewal-center.index', 'Arrange a renewal or pickup.', $currentUser->canAccessModule('rentals', 'read')],
        ['widget_today_renewals', 'Renewals due today', $renewalsDueTodayCount ?? 0, 'renewal-center.index', 'Review the current period before renewing.', $currentUser->canAccessModule('rentals', 'read')],
        ['snapshot_asset_alerts', 'Verify returned equipment', $awaitingReturnVerificationCount ?? 0, 'assets.pending-verification', 'Inspect condition and accessories before allocation.', $currentUser->canAccessModule('assets', 'update')],
        ['widget_today_followups', 'Follow-ups due today', $followUpsDueTodayCount ?? 0, 'communication-center.index', 'Keep the existing communication queues up to date.', $currentUser->canAccessAnyModule(['rentals', 'sales', 'customers', 'deliveries', 'invoices'], 'read')],
    ];
@endphp
<details class="phos-overview-figures rx-card">
    <summary class="rx-card-header"><strong>Business overview figures</strong><span>Receipts, dues, queues and availability</span></summary>
    <div class="rx-card-body phos-overview-metrics">
        @foreach($overviewCards as [$key, $label, $value, $route, $allowed])
            @if($dashboardWidgetEnabled($key) && $allowed)
                <a href="{{ $mergeDashboardQuery($route) }}" class="phos-overview-metric" data-overview-widget="{{ $key }}">
                    <span>{{ $label }}</span><strong>{{ $value }}</strong>
                </a>
            @endif
        @endforeach
    </div>
    <div class="rx-card-body phos-overview-queues">
        <h3>What needs attention</h3>
        @forelse(collect($overviewQueues)->filter(fn ($queue) => $dashboardWidgetEnabled($queue[0]) && $queue[5]) as [$key, $label, $count, $route, $copy])
            <a href="{{ $mergeDashboardQuery($route) }}" class="phos-overview-queue" data-overview-queue="{{ $key }}">
                <span><strong>{{ $label }}</strong><small>{{ $copy }}</small></span><span>{{ number_format($count) }}</span>
            </a>
        @empty
            <p>No action widgets are enabled for your role.</p>
        @endforelse
    </div>
</details>
@if($canViewFinance && $dashboardWidgetEnabled('section_finance_summary'))
    <section class="phos-dashboard-deposits rx-card" aria-label="Deposit charges and tracking">
        <div><h2>Deposits</h2><p>Refundable deposits are separate from business income. Payments may be included in customer receipts.</p></div>
        <div><span>Deposit charges · matching rental orders</span><strong>{{ $currency($totalDepositValue ?? 0) }}</strong></div>
        <div><span>Deposits received</span><strong>Not separately tracked</strong></div>
        <div><span>Refunded / still held</span><strong>Needs reconciliation</strong></div>
    </section>
    <details class="phos-overview-aging rx-card">
        <summary class="rx-card-header"><strong>Invoice aging</strong><span>Existing open-balance age bands</span></summary>
        <div class="rx-card-body">
            @foreach(data_get($invoiceAging ?? [], 'buckets', []) as $bucket)
                <div class="phos-overview-aging-row"><span>{{ $bucket['label'] ?? '' }}</span><strong>{{ $currency($bucket['amount'] ?? 0) }}</strong></div>
            @endforeach
        </div>
    </details>
@endif
