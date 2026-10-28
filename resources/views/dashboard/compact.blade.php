@extends('layouts.app')
@section('content')
@include('sales-workspace.style')
@php
    $visibility = $dashboardVisibility ?? [];
    $enabled = fn ($key) => in_array($key, $visibility['widget_keys'] ?? [], true);
    $money = fn ($amount) => '₹'.number_format((float) $amount, 2);
    $finance = (bool) ($visibility['finance_widgets'] ?? false);
    $taskScope = ($dashboardTaskMetricsScope ?? '') === 'assigned' ? 'My ' : '';
    $orderScope = ($dashboardOrderMetricsScope ?? '') === 'mine' ? 'My ' : '';
    $cards = [
        ['kpi_active_rentals', $orderScope.'Active rentals', number_format($activeRentals ?? 0), 'rentals.index', true],
        ['primary_pending_deliveries', $taskScope.'Pending deliveries', number_format($pendingDeliveryCount ?? 0), 'deliveries.index', auth()->user()->canAccessModule('deliveries', 'read')],
        ['primary_pending_pickups', $taskScope.'Pending pickups', number_format($pendingPickupCount ?? 0), 'pickup-center.index', auth()->user()->canAccessModule('deliveries', 'read')],
        ['kpi_collections_this_month', 'Collections this month', $money($paymentsReceivedThisMonth ?? 0), 'invoices.index', $finance && auth()->user()->canAccessModule('payments','read') && auth()->user()->canAccessModule('invoices','read')],
        ['primary_outstanding_dues', 'Outstanding invoices', $money($outstandingDueAmount ?? 0), 'invoices.index', $finance && auth()->user()->canAccessModule('invoices','read')],
        ['kpi_rental_available', 'Rental units available', number_format($availableRentalAssets ?? 0), 'inventory.dashboard', auth()->user()->hasPermission('dashboard.inventory')],
    ];
    $queues = [
        ['primary_overdue_rentals', 'Review overdue rentals', $overdueCount ?? 0, 'renewal-center.index', 'Arrange a renewal or pickup.', auth()->user()->canAccessModule('rentals', 'read')],
        ['widget_today_renewals', 'Renewals due today', $renewalsDueTodayCount ?? 0, 'renewal-center.index', 'Review the current period before renewing.', auth()->user()->canAccessModule('rentals', 'read')],
        ['snapshot_asset_alerts', 'Verify returned equipment', $awaitingReturnVerificationCount ?? 0, 'assets.pending-verification', 'Inspect condition and accessories before allocation.', auth()->user()->canAccessModule('assets', 'update')],
        ['widget_today_followups', 'Follow-ups due today', $followUpsDueTodayCount ?? 0, 'communication-center.index', 'Keep the existing communication queues up to date.', auth()->user()->canAccessAnyModule(['rentals','sales','customers','deliveries','invoices'], 'read')],
    ];
@endphp
<main class="sw" data-business-overview>
    <header class="sw-head"><div><div class="sw-eyebrow">{{ now()->format('l, d M Y') }}</div><h1>Your business, at a glance.</h1><p class="sw-muted">The same PHOS permissions, filters and figures in a compact view.</p></div><div class="sw-actions"><a class="sw-btn" href="{{ route('dashboard',request()->query()) }}">Full dashboard</a><a class="sw-btn" href="{{ route('workflow-guides.index') }}">Workflow guides</a></div></header>
    <form class="sw-panel sw-fields" method="get" action="{{ route('dashboard.compact') }}"><div class="sw-field"><label for="overview-from">From date</label><input id="overview-from" type="date" name="from_date" value="{{ request('from_date') }}"></div><div class="sw-field"><label for="overview-to">To date</label><input id="overview-to" type="date" name="to_date" value="{{ request('to_date') }}"></div><div class="sw-actions"><button class="sw-btn">Apply filters</button><a class="sw-btn" href="{{ route('dashboard.compact') }}">Clear</a></div></form>
    <div class="sw-fields" style="margin-bottom:24px">
        @foreach($cards as [$key,$label,$value,$route,$allowed])@if($enabled($key) && $allowed)
        <a class="sw-panel" data-overview-widget="{{ $key }}" href="{{ route($route) }}" style="text-decoration:none;color:inherit;border-top:3px solid #087e83"><p class="sw-muted">{{ $label }}</p><strong style="font-size:30px;display:block;margin-top:12px;overflow-wrap:anywhere">{{ $value }}</strong></a>
        @endif
@endforeach
    </div>
    <section class="sw-panel"><h2>What needs attention</h2>
        @forelse(collect($queues)->filter(fn($q) => $enabled($q[0]) && $q[5]) as [$key,$label,$count,$route,$copy])
        <a class="sw-row" href="{{ route($route) }}" style="color:inherit;text-decoration:none"><div><strong>{{ $label }}</strong><p class="sw-muted">{{ $copy }}</p></div><span class="sw-badge">{{ number_format($count) }}</span></a>
        @empty<p class="sw-muted">No action widgets are enabled for your role.</p>@endforelse
    </section>
    @if($finance && $enabled('section_finance_summary'))
    <section class="sw-panel"><h2>Invoice aging</h2><p class="sw-muted">Use the full dashboard for detailed customer dues, vendor performance and team reports.</p>
        @forelse(data_get($invoiceAging ?? [],'buckets',[]) as $bucket)<div class="sw-row"><span>{{ $bucket['label'] ?? '' }}</span><strong>{{ $money($bucket['amount'] ?? 0) }}</strong></div>@empty<p class="sw-muted">No aging figures to show.</p>@endforelse
    </section>
    @endif
    <p class="sw-muted">Collections are customer receipts. Deposits charged are not the same as cash held. Stock figures remain subject to allocation and return inspection.</p>
</main>
@endsection
