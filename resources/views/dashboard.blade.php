@extends('layouts.app')

@section('content')
@php
    $currentUser = auth()->user();
    $safeRoute = function (string $routeName, array $parameters = []) {
        return \Illuminate\Support\Facades\Route::has($routeName) ? route($routeName, $parameters) : null;
    };

    $canCreateRentals = $currentUser?->canAccessModule('rentals', 'create') ?? false;
    $canCreateSales = $currentUser?->canAccessModule('sales', 'create') ?? false;
    $canCreateCustomers = $currentUser?->canAccessModule('customers', 'create') ?? false;
    $canUpdateRentals = $currentUser?->canAccessModule('rentals', 'update') ?? false;
    $canReadSales = $currentUser?->canAccessModule('sales', 'read') ?? false;
    $canReadDeliveries = $currentUser?->canAccessModule('deliveries', 'read') ?? false;
    $canReadInvoices = $currentUser?->canAccessModule('invoices', 'read') ?? false;
    $canReadReports = $currentUser?->canAccessModule('reports', 'read') ?? false;
    $canViewFinance = $currentUser?->canViewFinance() ?? false;

    $currency = fn ($value) => "\u{20B9}" . number_format((float) $value, 2);
    $welcomeName = trim((string) ($currentUser?->name ?? 'Team'));
    $welcomeName = explode(' ', $welcomeName)[0] ?: 'Team';

    $baseFilters = collect(request()->query())->filter(fn ($value) => filled($value))->all();
    $mergeDashboardQuery = function (?string $routeName, array $overrides = []) use ($baseFilters, $safeRoute) {
        $href = $safeRoute($routeName);

        if (!$href) {
            return null;
        }

        $query = array_merge($baseFilters, $overrides);

        foreach ($query as $key => $value) {
            if ($value === null || $value === '') {
                unset($query[$key]);
            }
        }

        return !empty($query) ? $href . '?' . http_build_query($query) : $href;
    };

    $dashboardUrl = $mergeDashboardQuery('dashboard') ?? url('/dashboard');
    $rentalIndexUrl = $mergeDashboardQuery('rentals.index');
    $salesIndexUrl = $mergeDashboardQuery('sales.index');
    $invoiceIndexUrl = $mergeDashboardQuery('invoices.index');
    $reportsIndexUrl = $mergeDashboardQuery('reports.index');
    $renewalCenterUrl = $safeRoute('renewal-center.index');
    $inventoryUrl = $safeRoute('inventory.dashboard');
    $availableRentalAssetsUrl = $safeRoute('assets.index', ['asset_stage' => 'rental_stock', 'asset_status' => 'available']);
    $availableSaleUnitsUrl = $safeRoute('assets.index', ['asset_stage' => 'new_stock', 'asset_status' => 'available_for_sale']);
    $productsIndexUrl = $safeRoute('products.index');
    $customersIndexUrl = $safeRoute('customers.index');
    $deliveriesIndexUrl = $safeRoute('deliveries.index');
    $newRentalUrl = $canCreateRentals ? $safeRoute('rentals.create') : null;
    $newCustomerUrl = $canCreateCustomers ? $safeRoute('customers.create') : null;
    $newSaleUrl = $canCreateSales ? $safeRoute('sales.create') : null;

    $safePercent = function ($value, $total) {
        return $total > 0 ? round((((float) $value) / ((float) $total)) * 100, 1) : 0;
    };
    $dashboardDateLabel = now()->format('l, d M Y');

    $statusBadgeClass = function (?string $status) {
        return match ($status) {
            'active', 'completed', 'paid', 'success' => 'is-success',
            'returned', 'delivered', 'info' => 'is-info',
            'pending', 'assigned', 'partial', 'warning', 'in_progress' => 'is-warning',
            'overdue', 'danger', 'unpaid', 'cancelled' => 'is-danger',
            default => '',
        };
    };

    $toneCardClass = function (?string $tone) {
        return match ($tone) {
            'green' => 'is-success',
            'amber' => 'is-warning',
            'red' => 'is-danger',
            'blue' => 'is-info',
            default => '',
        };
    };

    $dashboardIcon = function (string $key): string {
        $attrs = 'width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"';

        return match ($key) {
            'rental' => '<svg '.$attrs.'><path d="M7 3v4"/><path d="M17 3v4"/><path d="M4 8h16"/><rect x="4" y="5" width="16" height="16" rx="2"/><path d="M8 12h8"/><path d="M8 16h5"/></svg>',
            'delivery' => '<svg '.$attrs.'><path d="M3 7h11v9H3z"/><path d="M14 10h4l3 3v3h-7z"/><circle cx="7" cy="18" r="2"/><circle cx="18" cy="18" r="2"/></svg>',
            'pickup' => '<svg '.$attrs.'><path d="M20 7H9"/><path d="M14 3 9 8l5 5"/><path d="M4 17h11"/><path d="M9 13 4 18l5 5"/></svg>',
            'payment' => '<svg '.$attrs.'><path d="M3 7h18v10H3z"/><path d="M7 15h5"/><path d="M17 11h.01"/></svg>',
            'overdue' => '<svg '.$attrs.'><circle cx="12" cy="12" r="9"/><path d="M12 7v6"/><path d="m12 13 3 3"/></svg>',
            'revenue' => '<svg '.$attrs.'><path d="M4 19h16"/><path d="M7 15V9"/><path d="M12 15V5"/><path d="M17 15v-3"/></svg>',
            'asset' => '<svg '.$attrs.'><path d="M21 8 12 3 3 8l9 5 9-5Z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/></svg>',
            'customer' => '<svg '.$attrs.'><path d="M16 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"/><circle cx="9.5" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/></svg>',
            'low-stock' => '<svg '.$attrs.'><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/></svg>',
            'sales' => '<svg '.$attrs.'><path d="M6 6h15l-2 8H8L6 6Z"/><path d="M6 6 5 3H2"/><circle cx="9" cy="20" r="1"/><circle cx="18" cy="20" r="1"/></svg>',
            'trend' => '<svg '.$attrs.'><path d="M4 19h16"/><path d="m5 15 4-4 4 3 6-8"/></svg>',
            'warehouse' => '<svg '.$attrs.'><path d="M3 21h18"/><path d="M4 21V8l8-5 8 5v13"/><path d="M9 21v-8h6v8"/></svg>',
            'vendor' => '<svg '.$attrs.'><path d="M8 12h8"/><path d="M7 7h.01"/><path d="M17 7h.01"/><path d="M5 4h14a2 2 0 0 1 2 2v8a5 5 0 0 1-5 5H8a5 5 0 0 1-5-5V6a2 2 0 0 1 2-2Z"/></svg>',
            'city' => '<svg '.$attrs.'><path d="M12 21s7-5.1 7-11a7 7 0 1 0-14 0c0 5.9 7 11 7 11Z"/><circle cx="12" cy="10" r="2"/></svg>',
            default => '<svg '.$attrs.'><path d="M12 5v14"/><path d="M5 12h14"/></svg>',
        };
    };

    $totalRentalsValue = (int) ($totalRentals ?? 0);
    $activeRentalsCount = (int) ($activeRentals ?? 0);
    $pendingDeliveryCountValue = (int) ($pendingDeliveryCount ?? 0);
    $pendingPickupCountValue = (int) ($pendingPickupCount ?? 0);
    $totalTasksCountValue = (int) ($totalTasksCount ?? 0);
    $deliveryTasksCountValue = (int) ($deliveryTasksCount ?? 0);
    $pickupTasksCountValue = (int) ($pickupTasksCount ?? 0);
    $completedTodayCountValue = (int) ($completedTodayCount ?? 0);
    $scheduledDeliveryCountValue = (int) ($scheduledDeliveryCount ?? 0);
    $scheduledPickupCountValue = (int) ($scheduledPickupCount ?? 0);
    $outForDeliveryCountValue = (int) ($outForDeliveryCount ?? 0);
    $outForPickupCountValue = (int) ($outForPickupCount ?? 0);
    $overdueDeliveryCountValue = (int) ($overdueDeliveryCount ?? 0);
    $overduePickupCountValue = (int) ($overduePickupCount ?? 0);
    $overdueReturnsCount = (int) ($overdueCount ?? 0);
    $returnsDueTodayCountValue = (int) ($returnsDueTodayCount ?? 0);
    $returnedRentalsCount = (int) ($returnedRentals ?? 0);
    $deliveredRentalsCount = (int) ($deliveredRentals ?? 0);
    $completedDeliveryCountValue = (int) ($completedDeliveryCount ?? 0);
    $deliveriesTodayCount = (int) ($deliveredTodayCount ?? 0);
    $completedPickupCountValue = (int) ($completedPickupCount ?? 0);
    $pickedUpTodayCountValue = (int) ($pickedUpTodayCount ?? 0);
    $todaySalesCount = (int) ($todaySales ?? 0);
    $salesThisMonthCountValue = (int) ($salesThisMonthCount ?? 0);
    $openInvoiceCountValue = (int) ($openInvoiceCount ?? 0);
    $overdueInvoiceCountValue = (int) ($overdueInvoiceCount ?? 0);
    $unpaidInvoiceCountValue = (int) ($unpaidInvoiceCount ?? 0);
    $outstandingInvoiceCountValue = (int) ($unpaidInvoiceCount ?? 0);
    $outstandingInvoiceOverdueCountValue = (int) ($overdueInvoiceCount ?? 0);
    $unbilledRentalReceivableCountValue = (int) ($unbilledRentalReceivableCount ?? 0);
    $unbilledSaleReceivableCountValue = (int) ($unbilledSaleReceivableCount ?? 0);
    $availableRentalAssetsCount = (int) ($availableRentalAssets ?? 0);
    $availableSaleUnitsCount = (int) ($availableSaleUnits ?? 0);
    $maintenanceAlertCountValue = (int) ($maintenanceAlertCount ?? 0);
    $monthlyRevenueAmount = (float) ($paymentsReceivedThisMonth ?? 0);
    $outstandingDueAmountValue = (float) ($outstandingDueAmount ?? 0);
    $outstandingInvoiceAmountValue = (float) ($unpaidInvoiceAmount ?? 0);
    $unbilledRentalReceivableAmountValue = (float) ($unbilledRentalReceivableAmount ?? 0);
    $unbilledSaleReceivableAmountValue = (float) ($unbilledSaleReceivableAmount ?? 0);
    $pendingSalesAmountValue = (float) ($pendingSalesAmount ?? 0);
    $salesOutstandingInvoiceCountValue = (int) ($salesOutstandingInvoiceCount ?? 0);
    $salesOutstandingInvoiceAmountValue = (float) ($salesOutstandingInvoiceAmount ?? 0);
    $salesUnbilledCountValue = (int) ($salesUnbilledCount ?? 0);
    $salesUnbilledAmountValue = (float) ($salesUnbilledAmount ?? 0);
    $salesTotalPendingAmountValue = (float) ($salesTotalPendingAmount ?? 0);
    $paidSalesAmountValue = (float) ($paidSalesAmount ?? 0);
    $salesThisMonthAmountValue = (float) ($salesThisMonthAmount ?? 0);
    $totalSalesAmountValue = (float) ($totalSalesAmount ?? 0);
    $totalRentalValueAmount = (float) ($totalRentalValue ?? 0);
    $totalDepositValueAmount = (float) ($totalDepositValue ?? 0);
    $totalTransportValueAmount = (float) ($totalTransportValue ?? 0);
    $totalOtherValueAmount = (float) ($totalOtherValue ?? 0);
    $paymentsReceivedTodayAmount = (float) ($paymentsReceivedToday ?? 0);
    $paymentsReceivedThisMonthAmount = (float) ($paymentsReceivedThisMonth ?? 0);
    $totalBilledAmountValue = (float) ($totalBilledAmount ?? 0);
    $grossBilledAmountValue = (float) ($grossBilledAmount ?? 0);
    $knownUnbilledGapAmountValue = (float) ($knownUnbilledGapAmount ?? 0);
    $reconciliationGapAmountValue = (float) ($reconciliationGapAmount ?? 0);
    $adjustmentGapAmountValue = (float) ($adjustmentGapAmount ?? 0);

    $activePercent = $safePercent($activeRentalsCount, max(1, $totalRentalsValue));
    $pendingDeliveryPercent = $safePercent($pendingDeliveryCountValue, max(1, $totalRentalsValue));
    $overduePercent = $safePercent($overdueReturnsCount, max(1, $totalRentalsValue));
    $returnedPercent = $safePercent($returnedRentalsCount, max(1, $totalRentalsValue));
    $deliveredPercent = $safePercent($deliveredRentalsCount, max(1, $totalRentalsValue));
    $lifecycleTotal = max(1, $totalRentalsValue);
    $lifecycleActivePercent = $safePercent((int) ($lifecycleActiveCount ?? 0), $lifecycleTotal);
    $lifecyclePendingDeliveryPercent = $safePercent((int) ($lifecyclePendingDeliveryCount ?? 0), $lifecycleTotal);
    $lifecyclePendingPickupPercent = $safePercent((int) ($lifecyclePendingPickupCount ?? 0), $lifecycleTotal);
    $lifecycleOverduePercent = $safePercent((int) ($lifecycleOverdueCount ?? 0), $lifecycleTotal);
    $lifecycleReturnedPercent = $safePercent((int) ($lifecycleReturnedCount ?? 0), $lifecycleTotal);

    $opsFlowMax = max(1, $pendingDeliveryCountValue, $scheduledDeliveryCountValue, $outForDeliveryCountValue, $overdueDeliveryCountValue, $pendingPickupCountValue, $scheduledPickupCountValue, $outForPickupCountValue, $overduePickupCountValue, $deliveriesTodayCount, $completedPickupCountValue);

    $citySummaryRows = collect($citySummary ?? collect())->values();
    $vendorSummaryRows = collect($vendorSummary ?? collect())->values();
    $warehouseSummaryRows = collect($warehouseSummary ?? collect())->values();
    $monthlyTrendRows = collect($monthlyTrend ?? collect())->values();

    $cityBreakdownMax = max(array_merge([1], $citySummaryRows->pluck('total_amount')->map(fn ($value) => (float) $value)->all()));
    $vendorBreakdownMax = max(array_merge([1], $vendorSummaryRows->pluck('total_amount')->map(fn ($value) => (float) $value)->all()));
    $warehouseBreakdownMax = max(array_merge([1], $warehouseSummaryRows->pluck('total_amount')->map(fn ($value) => (float) $value)->all()));
    $trendMax = max(array_merge([1], $monthlyTrendRows->map(fn ($row) => max((float) ($row['rental_total'] ?? 0), (float) ($row['sales_total'] ?? 0)))->all()));

    $criticalQueue = collect($overdueRentals ?? collect())->unique('id')->take(5)->values();
    $todayQueue = collect($returnsDueToday ?? collect())
        ->merge(collect($endingSoonRentals ?? collect())->filter(fn ($rental) => optional($rental->end_date)?->isToday()))
        ->unique('id')
        ->reject(fn ($rental) => $criticalQueue->contains('id', $rental->id))
        ->take(5)
        ->values();
    $weekQueue = collect($endingSoonRentals ?? collect())
        ->unique('id')
        ->reject(fn ($rental) => $criticalQueue->contains('id', $rental->id) || $todayQueue->contains('id', $rental->id))
        ->take(5)
        ->values();

    $primaryPriorityCards = collect([
        [
            'label' => 'Deliveries Pending',
            'value' => $deliveryTasksCountValue,
            'subtitle' => $overdueDeliveryCountValue . ' overdue task(s)',
            'note' => 'Open delivery tasks visible in Task Board',
            'href' => $deliveriesIndexUrl ? route('deliveries.index', ['board' => 'delivery_workload']) : null,
            'tone' => 'amber',
            'icon' => 'delivery',
        ],
        [
            'label' => 'Overdue Rentals',
            'value' => $overdueReturnsCount,
            'subtitle' => 'Delivered and past due',
            'note' => $returnsDueTodayCountValue . ' returns due today',
            'href' => $mergeDashboardQuery('rentals.index', ['filter' => 'overdue', 'status' => null]),
            'tone' => 'red',
            'icon' => 'overdue',
        ],
        [
            'label' => 'Outstanding Dues',
            'value' => $currency($outstandingDueAmountValue),
            'subtitle' => $openInvoiceCountValue . ' open invoices',
            'note' => $overdueInvoiceCountValue . ' overdue now',
            'href' => $mergeDashboardQuery('invoices.index', ['status' => 'open']),
            'tone' => 'blue',
            'icon' => 'payment',
            'visible' => $canViewFinance,
        ],
        [
            'label' => 'Pickups Pending',
            'value' => $pickupTasksCountValue,
            'subtitle' => $overduePickupCountValue . ' overdue task(s)',
            'note' => 'Open pickup tasks visible in Task Board',
            'href' => $deliveriesIndexUrl ? route('deliveries.index', ['board' => 'pickup_workload']) : null,
            'tone' => 'amber',
            'icon' => 'pickup',
        ],
        [
            'label' => 'Completed Today',
            'value' => $completedTodayCountValue,
            'subtitle' => $completedDeliveryCountValue . ' deliveries + ' . $completedPickupCountValue . ' pickups completed',
            'note' => 'Tasks closed today only',
            'href' => $deliveriesIndexUrl ? route('deliveries.index', ['board' => 'completed_today']) : null,
            'tone' => 'green',
            'icon' => 'completed',
        ],
    ])->filter(fn ($card) => $card['visible'] ?? true)->values();

    $salesCards = collect([
        ['label' => 'Sales Today', 'value' => $todaySalesCount, 'subtitle' => 'Orders created today', 'note' => $currency($paidSalesAmountValue) . ' paid value', 'href' => $mergeDashboardQuery('sales.index', ['date' => now()->toDateString()]), 'tone' => 'green', 'icon' => 'sales'],
        ['label' => 'Sales This Month', 'value' => $salesThisMonthCountValue, 'subtitle' => 'Month-to-date sales volume', 'note' => $currency($salesThisMonthAmountValue), 'href' => $salesIndexUrl, 'tone' => 'blue', 'icon' => 'trend'],
        ['label' => 'Outstanding Sales Invoices', 'value' => $currency($salesOutstandingInvoiceAmountValue), 'subtitle' => 'Invoice raised, payment pending', 'note' => $salesOutstandingInvoiceCountValue . ' open sales invoices', 'href' => $salesIndexUrl, 'tone' => 'amber', 'icon' => 'payment'],
        ['label' => 'Unbilled Sales', 'value' => $currency($salesUnbilledAmountValue), 'subtitle' => 'Orders without invoice', 'note' => $salesUnbilledCountValue . ' sales not yet invoiced', 'href' => $salesIndexUrl, 'tone' => 'amber', 'icon' => 'sales'],
        ['label' => 'Total Pending Sales', 'value' => $currency($salesTotalPendingAmountValue), 'subtitle' => 'Total value not fully collected', 'note' => $currency($salesOutstandingInvoiceAmountValue) . ' invoiced + ' . $currency($salesUnbilledAmountValue) . ' unbilled', 'href' => $salesIndexUrl, 'tone' => 'red', 'icon' => 'trend'],
        ['label' => 'Paid Sales Value', 'value' => $currency($paidSalesAmountValue), 'subtitle' => 'Collected sales amount', 'note' => $currency($totalSalesAmountValue) . ' total sales', 'href' => $mergeDashboardQuery('sales.index', ['payment_status' => 'paid']), 'tone' => 'green', 'icon' => 'revenue'],
    ]);

    $financeCards = collect([
        ['label' => 'Gross Components', 'value' => $currency($grossBilledAmountValue), 'href' => $dashboardUrl, 'tone' => 'blue', 'icon' => 'trend', 'note' => 'Rental + sales + deposit + transport + other'],
        ['label' => 'Rental Order Value', 'value' => $currency($totalRentalValueAmount), 'href' => $rentalIndexUrl, 'tone' => 'blue', 'icon' => 'rental'],
        ['label' => 'Sales Order Value', 'value' => $currency($totalSalesAmountValue), 'href' => $salesIndexUrl, 'tone' => 'green', 'icon' => 'sales'],
        ['label' => 'Deposits', 'value' => $currency($totalDepositValueAmount), 'href' => $rentalIndexUrl, 'tone' => null, 'icon' => 'payment'],
        ['label' => 'Transport', 'value' => $currency($totalTransportValueAmount), 'href' => $rentalIndexUrl, 'tone' => null, 'icon' => 'delivery'],
        ['label' => 'Other Charges', 'value' => $currency($totalOtherValueAmount), 'href' => $rentalIndexUrl, 'tone' => null, 'icon' => 'payment'],
        ['label' => 'Unbilled Renewals', 'value' => $currency((float) ($unbilledRenewalAmount ?? 0)), 'href' => $rentalIndexUrl, 'tone' => 'amber', 'icon' => 'rental'],
        ['label' => 'Unpaid Renewal Invoices', 'value' => $currency((float) ($unpaidRenewalAmount ?? 0)), 'href' => $mergeDashboardQuery('invoices.index', ['status' => 'open']), 'tone' => 'red', 'icon' => 'payment'],
        ['label' => 'Collections This Month', 'value' => $currency($paymentsReceivedThisMonthAmount), 'href' => $reportsIndexUrl, 'tone' => 'green', 'icon' => 'revenue'],
        ['label' => 'Outstanding Dues', 'value' => $currency($outstandingDueAmountValue), 'href' => $mergeDashboardQuery('invoices.index', ['status' => 'open']), 'tone' => 'red', 'icon' => 'payment'],
        ['label' => 'Net Billed (Invoices)', 'value' => $currency($totalBilledAmountValue), 'href' => $invoiceIndexUrl, 'tone' => 'blue', 'icon' => 'trend', 'note' => $reconciliationGapAmountValue > 0 ? $currency($reconciliationGapAmountValue) . ' still outside invoiced total' : 'Fully invoiced against visible components'],
        ['label' => 'Unbilled / Gap', 'value' => $currency($reconciliationGapAmountValue), 'href' => $invoiceIndexUrl, 'tone' => $reconciliationGapAmountValue > 0 ? 'amber' : 'green', 'icon' => 'payment', 'note' => $adjustmentGapAmountValue !== 0.0 ? $currency($knownUnbilledGapAmountValue) . ' known unbilled, ' . $currency($adjustmentGapAmountValue) . ' adjustment gap' : $currency($knownUnbilledGapAmountValue) . ' explained by uninvoiced exposure'],
    ]);

    $deliveryMiniTiles = collect([
        ['label' => 'Total Tasks', 'value' => $totalTasksCountValue, 'href' => $deliveriesIndexUrl ? route('deliveries.index') : null, 'tone' => 'blue', 'icon' => 'tasks'],
        ['label' => 'Deliveries Pending', 'value' => $deliveryTasksCountValue, 'href' => $deliveriesIndexUrl ? route('deliveries.index', ['board' => 'delivery_workload']) : null, 'tone' => 'amber', 'icon' => 'delivery'],
        ['label' => 'Pickups Pending', 'value' => $pickupTasksCountValue, 'href' => $deliveriesIndexUrl ? route('deliveries.index', ['board' => 'pickup_workload']) : null, 'tone' => 'amber', 'icon' => 'pickup'],
        ['label' => 'Deliveries Completed', 'value' => $completedDeliveryCountValue, 'href' => $deliveriesIndexUrl ? route('deliveries.index', ['board' => 'completed_delivery']) : null, 'tone' => 'green', 'icon' => 'delivery'],
        ['label' => 'Pickups Completed', 'value' => $completedPickupCountValue, 'href' => $deliveriesIndexUrl ? route('deliveries.index', ['board' => 'completed_pickup']) : null, 'tone' => 'green', 'icon' => 'pickup'],
        ['label' => 'Completed Today', 'value' => $completedTodayCountValue, 'href' => $deliveriesIndexUrl ? route('deliveries.index', ['board' => 'completed_today']) : null, 'tone' => 'green', 'icon' => 'completed'],
    ]);

    $renewalMiniTiles = collect([
        ['label' => 'Renewals Due Today', 'value' => (int) ($renewalsDueTodayCount ?? 0), 'href' => $renewalCenterUrl ? route('renewal-center.index', ['tab' => 'due_today']) : null, 'tone' => 'amber', 'icon' => 'rental'],
        ['label' => 'Renewals Due This Week', 'value' => (int) ($renewalsDueThisWeekCount ?? 0), 'href' => $renewalCenterUrl ? route('renewal-center.index', ['tab' => 'next_7_days']) : null, 'tone' => 'blue', 'icon' => 'trend'],
        ['label' => 'Overdue Renewals', 'value' => (int) ($overdueRenewalsCount ?? 0), 'href' => $renewalCenterUrl ? route('renewal-center.index', ['tab' => 'overdue']) : null, 'tone' => 'red', 'icon' => 'overdue'],
        ['label' => 'Pickup Requested', 'value' => (int) ($pickupRequestedRenewalCount ?? 0), 'href' => $renewalCenterUrl ? route('renewal-center.index', ['tab' => 'pickup_requested']) : null, 'tone' => 'green', 'icon' => 'pickup'],
    ])->filter(fn ($tile) => $canUpdateRentals && !empty($tile['href']))->values();

    $kpiCards = collect([
        [
            'label' => 'Active Rentals',
            'value' => number_format($activeRentalsCount),
            'note' => 'Live rental orders on field',
            'subtitle' => $activePercent . '% of rental base',
            'icon' => 'rental',
            'href' => $mergeDashboardQuery('rentals.index', ['status' => 'live']),
            'tone' => 'green',
        ],
        [
            'label' => 'Deliveries Today',
            'value' => number_format($deliveriesTodayCount),
            'note' => 'Completed deliveries today',
            'subtitle' => $pendingDeliveryCountValue . ' still pending',
            'icon' => 'delivery',
            'href' => $deliveriesIndexUrl ? route('deliveries.index', ['board' => 'completed_delivery']) : null,
            'tone' => 'blue',
            'visible' => $canReadDeliveries,
        ],
        [
            'label' => 'Outstanding Invoices (All)',
            'value' => number_format($outstandingInvoiceCountValue),
            'note' => $canViewFinance ? $currency($outstandingInvoiceAmountValue) . ' unpaid balance' : 'Open invoices awaiting collection',
            'subtitle' => $outstandingInvoiceOverdueCountValue . ' overdue now',
            'icon' => 'payment',
            'href' => $mergeDashboardQuery('invoices.index', ['status' => 'open']),
            'tone' => 'amber',
            'visible' => $canReadInvoices,
        ],
        [
            'label' => 'Unbilled Rentals',
            'value' => number_format($unbilledRentalReceivableCountValue),
            'note' => $canViewFinance ? $currency($unbilledRentalReceivableAmountValue) . ' not yet invoiced' : 'Delivered rentals awaiting invoice',
            'subtitle' => 'Delivered rentals without invoice',
            'icon' => 'rental',
            'href' => $mergeDashboardQuery('rentals.index', ['status' => 'live']),
            'tone' => 'amber',
            'visible' => $canReadInvoices,
        ],
        [
            'label' => 'Unpaid Renewal Invoices',
            'value' => number_format((int) ($unpaidRenewalCount ?? 0)),
            'note' => $canViewFinance ? $currency((float) ($unpaidRenewalAmount ?? 0)) . ' pending renewal collection' : 'Renewal invoices awaiting payment',
            'subtitle' => number_format((int) ($unbilledRenewalCount ?? 0)) . ' renewal(s) still unbilled',
            'icon' => 'payment',
            'href' => $mergeDashboardQuery('invoices.index', ['status' => 'open']),
            'tone' => 'red',
            'visible' => $canReadInvoices,
        ],
        [
            'label' => 'Unbilled Sales',
            'value' => number_format($unbilledSaleReceivableCountValue),
            'note' => $canViewFinance ? $currency($unbilledSaleReceivableAmountValue) . ' not yet invoiced' : 'Sales awaiting invoice',
            'subtitle' => 'Sale orders without invoice',
            'icon' => 'sales',
            'href' => $salesIndexUrl,
            'tone' => 'amber',
            'visible' => $canReadInvoices,
        ],
        [
            'label' => 'Overdue Rentals',
            'value' => number_format($overdueReturnsCount),
            'note' => number_format($returnsDueTodayCountValue) . ' due today',
            'subtitle' => 'Past promised return date',
            'icon' => 'overdue',
            'href' => $mergeDashboardQuery('rentals.index', ['filter' => 'overdue', 'status' => null]),
            'tone' => 'red',
        ],
        [
            'label' => 'Collections This Month',
            'value' => $currency($paymentsReceivedThisMonthAmount),
            'note' => 'Payments collected this month',
            'subtitle' => $currency($paymentsReceivedTodayAmount) . ' received today',
            'icon' => 'revenue',
            'href' => $reportsIndexUrl ?: $invoiceIndexUrl,
            'tone' => 'blue',
            'visible' => $canViewFinance && ($canReadReports || $canReadInvoices),
        ],
        [
            'label' => 'Rental Available',
            'value' => number_format($availableRentalAssetsCount),
            'note' => 'Tracked rental assets ready to dispatch',
            'subtitle' => $maintenanceAlertCountValue . ' maintenance alerts',
            'icon' => 'asset',
            'href' => $availableRentalAssetsUrl ?: $inventoryUrl,
            'tone' => null,
            'visible' => !empty($inventoryUrl),
        ],
        [
            'label' => 'Sale Stock Available',
            'value' => number_format($availableSaleUnitsCount),
            'note' => 'Quantity-based sellable stock',
            'subtitle' => 'Product Master / warehouse stock',
            'icon' => 'sales',
            'href' => $productsIndexUrl ?: ($availableSaleUnitsUrl ?: $inventoryUrl),
            'tone' => null,
            'visible' => !empty($productsIndexUrl) || !empty($inventoryUrl),
        ],
    ])->filter(fn ($card) => $card['visible'] ?? true)->values();

    $actionItems = collect([
        [
            'label' => 'Deliveries Pending',
            'count' => $deliveryTasksCountValue,
            'copy' => $overdueDeliveryCountValue > 0 ? $overdueDeliveryCountValue . ' overdue task(s)' : 'Open delivery tasks',
            'href' => $deliveriesIndexUrl ? route('deliveries.index', ['board' => 'delivery_workload']) : null,
            'icon' => 'delivery',
            'tone' => 'amber',
        ],
        [
            'label' => 'Pickups Pending',
            'count' => $pickupTasksCountValue,
            'copy' => $overduePickupCountValue > 0 ? $overduePickupCountValue . ' overdue task(s)' : 'Open pickup tasks',
            'href' => $deliveriesIndexUrl ? route('deliveries.index', ['board' => 'pickup_workload']) : null,
            'icon' => 'pickup',
            'tone' => 'blue',
        ],
        [
            'label' => 'Overdue Payments',
            'count' => $overdueInvoiceCountValue,
            'copy' => 'Invoices needing finance follow-up',
            'href' => $mergeDashboardQuery('invoices.index', ['status' => 'overdue']),
            'icon' => 'payment',
            'tone' => 'red',
        ],
        [
            'label' => 'Low Stock / Asset Alerts',
            'count' => $maintenanceAlertCountValue,
            'copy' => 'Assets in maintenance or unavailable',
            'href' => $inventoryUrl,
            'icon' => 'low-stock',
            'tone' => null,
        ],
    ]);

    $snapshotItems = collect([
        [
            'label' => 'Collections Today',
            'value' => $currency($paymentsReceivedTodayAmount),
            'copy' => 'Cash received so far',
            'icon' => 'revenue',
            'tone' => 'green',
            'visible' => $canViewFinance,
        ],
        [
            'label' => 'Deliveries Pending',
            'value' => number_format($pendingDeliveryCountValue),
            'copy' => 'Tasks waiting to leave',
            'icon' => 'delivery',
            'tone' => 'blue',
            'visible' => $canReadDeliveries,
        ],
        [
            'label' => 'Returns Expected',
            'value' => number_format($returnsDueTodayCountValue),
            'copy' => 'Due back today',
            'icon' => 'pickup',
            'tone' => 'amber',
        ],
        [
            'label' => 'Open Invoices',
            'value' => number_format($openInvoiceCountValue),
            'copy' => 'Awaiting closure',
            'icon' => 'payment',
            'tone' => 'amber',
            'visible' => $canReadInvoices,
        ],
        [
            'label' => 'Asset Alerts',
            'value' => number_format($maintenanceAlertCountValue),
            'copy' => 'Maintenance or unavailable',
            'icon' => 'low-stock',
            'tone' => $maintenanceAlertCountValue > 0 ? 'red' : 'blue',
            'visible' => !empty($inventoryUrl),
        ],
    ])->filter(fn ($item) => $item['visible'] ?? true)->values();

    $recentSalesSummary = collect($recentSales ?? collect())->take(5);
    $recentRentalsSummary = collect($recentRentals ?? collect())->take(6);
    $recentCustomersSummary = collect($recentCustomers ?? collect())->take(5);
    $recentPaymentsSummary = collect($recentPayments ?? collect())->take(5);
    $cities = $cities ?? collect();
    $vendors = $vendors ?? collect();
    $warehouses = $warehouses ?? collect();
@endphp

<style>
    .dashboard-shell .rx-page-title,
    .dashboard-shell .rx-card-title {
        letter-spacing: -0.035em;
    }
    .dashboard-shell {
        display: grid;
        gap: 14px;
        width: 100%;
        max-width: 1320px;
        margin: 0 auto;
    }
    .dashboard-hero {
        display: grid;
        gap: 10px;
        padding: 16px 18px;
        border: 1px solid var(--ph-color-border);
        border-radius: var(--ph-radius-xl);
        background: linear-gradient(180deg, #ffffff 0%, var(--ph-color-surface-soft) 100%);
        box-shadow: var(--ph-shadow-card);
    }
    .dashboard-hero-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
        flex-wrap: wrap;
    }
    .dashboard-hero-copy {
        display: grid;
        gap: 8px;
        min-width: 0;
    }
    .dashboard-hero-meta {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }
    .dashboard-hero-date {
        display: inline-flex;
        align-items: center;
        min-height: 26px;
        padding: 5px 10px;
        border-radius: 999px;
        background: var(--ph-color-info-soft);
        color: var(--ph-color-primary);
        border: 1px solid rgba(23, 119, 189, 0.18);
        font-size: 11px;
        font-weight: 700;
        line-height: 1;
    }
    .dashboard-hero-summary {
        margin: 0;
        max-width: 760px;
        color: var(--ph-color-text-soft);
        font-size: 13px;
        line-height: 1.5;
    }
    .dashboard-hero .rx-page-title {
        font-size: 28px;
        line-height: 1.04;
    }
    .dashboard-hero-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        align-items: center;
    }
    .dashboard-hero-actions .rx-btn,
    .dashboard-hero-actions .rx-btn-secondary {
        min-width: 122px;
    }
    .dashboard-hero-actions .rx-btn {
        box-shadow: 0 16px 34px rgba(23, 119, 189, 0.16);
    }
    .dashboard-hero-actions .rx-btn-secondary {
        background: rgba(255, 255, 255, 0.95);
    }
    .dashboard-kpi-grid,
    .dashboard-priority-grid,
    .dashboard-sales-grid,
    .dashboard-finance-grid,
    .dashboard-logistics-grid,
    .dashboard-snapshot-grid,
    .dashboard-overview-grid,
    .dashboard-rank-grid,
    .dashboard-queue-grid {
        display: grid;
        gap: 10px;
    }
    .dashboard-kpi-grid {
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    }
    .dashboard-priority-grid,
    .dashboard-sales-grid {
        grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
    }
    .dashboard-sales-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        align-items: stretch;
    }
    .dashboard-finance-grid {
        grid-template-columns: repeat(auto-fit, minmax(148px, 1fr));
    }
    .dashboard-logistics-grid {
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    }
    .dashboard-snapshot-grid {
        grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    }
    .dashboard-overview-grid,
    .dashboard-rank-grid,
    .dashboard-queue-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
    .dashboard-main-grid {
        display: grid;
        grid-template-columns: minmax(0, 1.1fr) minmax(0, .9fr);
        gap: 14px;
    }
    .dashboard-kpi-card,
    .dashboard-priority-card,
    .dashboard-sales-card,
    .dashboard-finance-card,
    .dashboard-logistics-card,
    .dashboard-action-card,
    .dashboard-rank-card,
    .dashboard-trend-card,
    .dashboard-queue-card {
        position: relative;
        overflow: hidden;
        display: grid;
        gap: 5px;
        min-height: 82px;
        padding: 10px 12px;
        border-radius: 16px;
        border: 1px solid var(--ph-color-border);
        background: #ffffff;
        box-shadow: var(--ph-shadow-soft);
        text-decoration: none;
        color: inherit;
    }
    .dashboard-kpi-card {
        gap: 4px;
        min-height: 92px;
        padding: 13px 14px;
        border-radius: 18px;
        background: #ffffff;
        box-shadow: 0 14px 28px rgba(11, 35, 66, 0.08);
    }
    .dashboard-kpi-card:hover,
    .dashboard-priority-card:hover,
    .dashboard-sales-card:hover,
    .dashboard-finance-card:hover,
    .dashboard-logistics-card:hover,
    .dashboard-action-card:hover,
    .dashboard-rank-link:hover,
    .dashboard-queue-card:hover {
        transform: translateY(-1px);
        box-shadow: 0 16px 30px rgba(11, 35, 66, 0.1);
    }
    .dashboard-kpi-head,
    .dashboard-card-head,
    .dashboard-action-top,
    .dashboard-rank-head,
    .dashboard-overview-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 12px;
    }
    .dashboard-kpi-label,
    .dashboard-card-label,
    .dashboard-logistics-label {
        color: var(--ph-color-text-soft);
        display: block;
        max-width: calc(100% - 46px);
        font-size: 10px;
        font-weight: 800;
        letter-spacing: .08em;
        text-transform: uppercase;
        line-height: 1.25;
        overflow-wrap: anywhere;
        text-wrap: balance;
    }
    .dashboard-kpi-subtitle {
        color: var(--ph-color-text-soft);
        font-size: 12px;
        line-height: 1.35;
        font-weight: 700;
        overflow-wrap: anywhere;
    }
    .dashboard-kpi-icon,
    .dashboard-card-icon {
        width: 28px;
        height: 28px;
        border-radius: 9px;
        display: grid;
        place-items: center;
        background: var(--ph-color-surface-soft);
        color: var(--ph-color-primary);
        border: 1px solid var(--ph-color-border);
        flex: 0 0 28px;
    }
    .dashboard-kpi-icon svg,
    .dashboard-card-icon svg {
        width: 14px;
        height: 14px;
    }
    .dashboard-kpi-value,
    .dashboard-card-value {
        color: var(--ph-color-text);
        max-width: 100%;
        font-size: 24px;
        font-weight: 800;
        line-height: 1.04;
        letter-spacing: -.04em;
        overflow-wrap: anywhere;
    }
    .dashboard-card-subtitle,
    .dashboard-card-note,
    .dashboard-card-subcopy,
    .dashboard-kpi-note,
    .dashboard-overview-copy,
    .dashboard-rank-copy,
    .dashboard-queue-copy,
    .dashboard-trend-copy {
        color: var(--ph-color-text-soft);
        font-size: 11px;
        line-height: 1.4;
        overflow-wrap: anywhere;
    }
    .dashboard-kpi-card.is-success .dashboard-kpi-icon,
    .dashboard-priority-card.is-success .dashboard-card-icon,
    .dashboard-sales-card.is-success .dashboard-card-icon,
    .dashboard-finance-card.is-success .dashboard-card-icon,
    .dashboard-logistics-card.is-success .dashboard-card-icon {
        color: var(--ph-color-success);
        background: var(--ph-color-success-soft);
        border-color: rgba(14, 159, 75, 0.18);
    }
    .dashboard-kpi-card.is-warning .dashboard-kpi-icon,
    .dashboard-priority-card.is-warning .dashboard-card-icon,
    .dashboard-sales-card.is-warning .dashboard-card-icon,
    .dashboard-finance-card.is-warning .dashboard-card-icon,
    .dashboard-logistics-card.is-warning .dashboard-card-icon,
    .dashboard-action-card.is-warning .dashboard-card-icon {
        color: var(--ph-color-warning);
        background: var(--ph-color-warning-soft);
        border-color: rgba(183, 121, 31, 0.18);
    }
    .dashboard-kpi-card.is-danger .dashboard-kpi-icon,
    .dashboard-priority-card.is-danger .dashboard-card-icon,
    .dashboard-sales-card.is-danger .dashboard-card-icon,
    .dashboard-finance-card.is-danger .dashboard-card-icon,
    .dashboard-logistics-card.is-danger .dashboard-card-icon,
    .dashboard-action-card.is-danger .dashboard-card-icon {
        color: var(--ph-color-danger);
        background: var(--ph-color-danger-soft);
        border-color: rgba(179, 13, 35, 0.18);
    }
    .dashboard-kpi-card.is-info .dashboard-kpi-icon,
    .dashboard-priority-card.is-info .dashboard-card-icon,
    .dashboard-sales-card.is-info .dashboard-card-icon,
    .dashboard-finance-card.is-info .dashboard-card-icon,
    .dashboard-logistics-card.is-info .dashboard-card-icon,
    .dashboard-action-card.is-info .dashboard-card-icon {
        color: var(--ph-color-primary);
        background: var(--ph-color-info-soft);
        border-color: rgba(23, 119, 189, 0.18);
    }
    .dashboard-finance-card {
        min-height: 78px;
        padding: 9px 10px;
    }
    .dashboard-finance-card .dashboard-card-icon {
        width: 24px;
        height: 24px;
        border-radius: 9px;
    }
    .dashboard-finance-card .dashboard-card-icon svg {
        width: 12px;
        height: 12px;
    }
    .dashboard-finance-card .dashboard-card-value {
        font-size: 14px;
        line-height: 1.06;
    }
    .dashboard-finance-card .dashboard-card-label {
        font-size: 9px;
        line-height: 1.28;
        max-width: calc(100% - 32px);
    }
    .dashboard-finance-card .dashboard-card-head {
        gap: 8px;
    }
    .dashboard-finance-card .dashboard-card-subcopy {
        margin-top: 4px;
    }
    .dashboard-compact-chip {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 32px;
        height: 28px;
        padding: 0 9px;
        border-radius: 9px;
        background: var(--ph-color-surface-soft);
        border: 1px solid var(--ph-color-border);
        color: var(--ph-color-text);
        font-size: 11px;
        font-weight: 800;
    }
    .dashboard-filter-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 10px;
    }
    .dashboard-filters-card .rx-card-header {
        padding-bottom: 8px;
    }
    .dashboard-filters-card summary.rx-card-header {
        list-style: none;
        cursor: pointer;
    }
    .dashboard-filters-card summary.rx-card-header::-webkit-details-marker {
        display: none;
    }
    .dashboard-filters-card .rx-card-copy {
        margin-top: 4px;
    }
    .dashboard-filters-card .rx-card-body {
        padding-top: 8px;
    }
    .dashboard-filter-toggle {
        display: none;
        align-items: center;
        gap: 6px;
        color: var(--ph-color-text-soft);
        font-size: 11px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: .05em;
        white-space: nowrap;
    }
    .dashboard-filter-toggle::after {
        content: "Collapse";
    }
    .dashboard-filters-card:not([open]) .dashboard-filter-toggle::after {
        content: "Expand";
    }
    .dashboard-action-layout,
    .dashboard-trend-layout {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 14px;
    }
    .dashboard-snapshot-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        min-height: 68px;
        padding: 12px 14px;
        border-radius: 16px;
        border: 1px solid var(--ph-color-border);
        background: #ffffff;
        box-shadow: var(--ph-shadow-soft);
    }
    .dashboard-snapshot-item strong {
        display: block;
        color: var(--ph-color-text);
        font-size: 18px;
        line-height: 1.05;
        letter-spacing: -0.03em;
    }
    .dashboard-snapshot-item span {
        display: block;
        color: var(--ph-color-text-soft);
        font-size: 10px;
        font-weight: 800;
        letter-spacing: .08em;
        line-height: 1.25;
        text-transform: uppercase;
    }
    .dashboard-snapshot-item small {
        display: block;
        margin-top: 4px;
        color: #64748b;
        font-size: 11px;
        line-height: 1.35;
    }
    .dashboard-snapshot-icon {
        width: 34px;
        height: 34px;
        flex: 0 0 34px;
        border-radius: 12px;
        display: grid;
        place-items: center;
        border: 1px solid var(--ph-color-border);
        background: var(--ph-color-surface-soft);
        color: var(--ph-color-primary);
    }
    .dashboard-snapshot-item.is-success .dashboard-snapshot-icon {
        color: var(--ph-color-success);
        background: var(--ph-color-success-soft);
        border-color: rgba(14, 159, 75, 0.18);
    }
    .dashboard-snapshot-item.is-warning .dashboard-snapshot-icon {
        color: var(--ph-color-warning);
        background: var(--ph-color-warning-soft);
        border-color: rgba(183, 121, 31, 0.18);
    }
    .dashboard-snapshot-item.is-danger .dashboard-snapshot-icon {
        color: var(--ph-color-danger);
        background: var(--ph-color-danger-soft);
        border-color: rgba(179, 13, 35, 0.18);
    }
    .dashboard-snapshot-item.is-info .dashboard-snapshot-icon {
        color: var(--ph-color-primary);
        background: var(--ph-color-info-soft);
        border-color: rgba(23, 119, 189, 0.18);
    }
    .dashboard-action-card {
        min-height: 108px;
    }
    .dashboard-overview-list,
    .dashboard-rank-list,
    .dashboard-trend-list,
    .dashboard-queue-list {
        display: grid;
        gap: 10px;
    }
    .dashboard-overview-item,
    .dashboard-inline-item,
    .dashboard-queue-item {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 12px;
        padding: 12px 0;
        border-top: 1px solid #e2e8f0;
    }
    .dashboard-overview-item:first-child,
    .dashboard-inline-item:first-child,
    .dashboard-queue-item:first-child {
        border-top: 0;
        padding-top: 0;
    }
    .dashboard-overview-item strong,
    .dashboard-inline-item strong,
    .dashboard-queue-item strong,
    .dashboard-rank-title,
    .dashboard-trend-label {
        display: block;
        color: #0f172a;
        font-size: 12px;
        line-height: 1.35;
        overflow-wrap: anywhere;
    }
    .dashboard-overview-item span,
    .dashboard-overview-item small,
    .dashboard-inline-item small,
    .dashboard-queue-item small,
    .dashboard-rank-item span {
        display: block;
        color: #64748b;
        font-size: 11px;
        line-height: 1.45;
        overflow-wrap: anywhere;
    }
    .dashboard-rank-link {
        display: grid;
        gap: 8px;
        padding: 11px;
        border-radius: 14px;
        border: 1px solid #e2e8f0;
        background: #f8fafc;
        text-decoration: none;
        color: inherit;
    }
    .dashboard-bar-track {
        width: 100%;
        height: 8px;
        border-radius: 999px;
        background: #e2e8f0;
        overflow: hidden;
    }
    .dashboard-bar-fill {
        height: 100%;
        border-radius: 999px;
    }
    .dashboard-empty {
        min-height: 128px;
    }
    .dashboard-queue-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 8px;
    }
    .dashboard-queue-actions a {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 32px;
        padding: 6px 10px;
        border-radius: 10px;
        border: 1px solid #dbe3ef;
        background: #ffffff;
        color: #0f172a;
        text-decoration: none;
        font-size: 12px;
        font-weight: 700;
    }
    .dashboard-trend-row {
        display: grid;
        gap: 10px;
        padding: 12px 0;
        border-top: 1px solid #e2e8f0;
    }
    .dashboard-trend-row:first-child {
        border-top: 0;
        padding-top: 0;
    }
    .dashboard-trend-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        color: #0f172a;
        font-size: 12px;
        font-weight: 700;
    }
    .dashboard-trend-pair {
        display: grid;
        gap: 8px;
    }
    @media (max-width: 1080px) {
        .dashboard-main-grid,
        .dashboard-action-layout,
        .dashboard-trend-layout,
        .dashboard-overview-grid,
        .dashboard-rank-grid,
        .dashboard-queue-grid {
            grid-template-columns: 1fr;
        }
    }
    @media (max-width: 767px) {
        .dashboard-shell {
            gap: 10px;
        }
        .dashboard-hero {
            padding: 12px;
            border-radius: 16px;
        }
        .dashboard-hero-header {
            gap: 10px;
        }
        .dashboard-hero .rx-page-title {
            font-size: 22px;
        }
        .dashboard-hero-summary {
            font-size: 11px;
            line-height: 1.45;
        }
        .dashboard-hero-actions {
            width: 100%;
            gap: 6px;
        }
        .dashboard-hero-actions > * {
            flex: 1 1 calc(50% - 6px);
            min-width: 0;
        }
        .dashboard-kpi-grid,
        .dashboard-priority-grid,
        .dashboard-sales-grid,
        .dashboard-finance-grid,
        .dashboard-logistics-grid,
        .dashboard-snapshot-grid {
            grid-template-columns: 1fr;
            gap: 10px;
        }
        .dashboard-kpi-card,
        .dashboard-priority-card,
        .dashboard-sales-card,
        .dashboard-finance-card,
        .dashboard-logistics-card,
        .dashboard-action-card,
        .dashboard-rank-card,
        .dashboard-trend-card,
        .dashboard-queue-card {
            min-height: auto;
            padding: 8px 9px;
            border-radius: 14px;
        }
        .dashboard-kpi-value,
        .dashboard-card-value {
            font-size: 16px;
        }
        .dashboard-kpi-card {
            min-height: 74px;
            padding: 9px 10px;
        }
        .dashboard-kpi-subtitle {
            font-size: 10px;
        }
        .dashboard-finance-card {
            min-height: 70px;
            padding: 8px 9px;
        }
        .dashboard-finance-card .dashboard-card-value {
            font-size: clamp(11px, 3.8vw, 12px);
            line-height: 1.18;
            letter-spacing: -.02em;
        }
        .dashboard-finance-card .dashboard-card-label,
        .dashboard-card-label,
        .dashboard-kpi-label,
        .dashboard-logistics-label {
            max-width: calc(100% - 28px);
            font-size: 9px;
        }
        .dashboard-kpi-icon,
        .dashboard-card-icon,
        .dashboard-compact-chip {
            width: 26px;
            height: 26px;
            min-width: 26px;
            border-radius: 9px;
            flex-basis: 26px;
            font-size: 10px;
        }
        .dashboard-kpi-icon svg,
        .dashboard-card-icon svg {
            width: 12px;
            height: 12px;
        }
        .dashboard-kpi-note,
        .dashboard-card-note,
        .dashboard-card-subtitle,
        .dashboard-overview-copy,
        .dashboard-rank-copy,
        .dashboard-queue-copy,
        .dashboard-trend-copy {
            font-size: 11px;
        }
        .dashboard-snapshot-item {
            min-height: 58px;
            padding: 9px 10px;
        }
        .dashboard-snapshot-item strong {
            font-size: 14px;
        }
        .dashboard-snapshot-icon {
            width: 28px;
            height: 28px;
            flex-basis: 28px;
        }
        .dashboard-filter-grid {
            grid-template-columns: 1fr;
            gap: 10px;
        }
        .dashboard-filters-card .rx-card-header {
            padding-bottom: 0;
        }
        .dashboard-filters-card[open] .rx-card-header {
            padding-bottom: 8px;
        }
        .dashboard-filters-card .rx-card-body {
            padding-top: 12px;
        }
        .dashboard-filter-toggle {
            display: inline-flex;
        }
    }
    @media (max-width: 420px) {
        .dashboard-kpi-grid,
        .dashboard-priority-grid,
        .dashboard-sales-grid,
        .dashboard-finance-grid,
        .dashboard-logistics-grid,
        .dashboard-snapshot-grid {
            grid-template-columns: 1fr;
            gap: 8px;
        }
        .dashboard-kpi-value,
        .dashboard-card-value {
            font-size: 15px;
        }
        .dashboard-finance-card {
            min-height: auto;
            padding: 7px 8px;
        }
        .dashboard-finance-card .dashboard-card-value {
            font-size: clamp(10px, 3.4vw, 11px);
        }
        .dashboard-finance-card .dashboard-card-label {
            font-size: 8.5px;
        }
        .dashboard-kpi-label,
        .dashboard-card-label,
        .dashboard-logistics-label {
            font-size: 8.5px;
        }
    }
</style>

<div class="dashboard-shell rx-page">
    <section class="dashboard-hero">
        <div class="dashboard-hero-header">
            <div class="dashboard-hero-copy">
                <div class="dashboard-hero-meta">
                    <span class="rx-eyebrow">Prime Healers Control</span>
                    <span class="dashboard-hero-date">{{ $dashboardDateLabel }}</span>
                </div>
                <div>
                    <h1 class="rx-page-title">Welcome back, {{ $welcomeName }}</h1>
                    <p class="rx-page-subtitle">Today's rental operations snapshot</p>
                </div>
                <p class="dashboard-hero-summary">Monitor field movement, collections, and return risk from one calm control surface without losing the operational details that matter.</p>
            </div>

            <div class="dashboard-hero-actions">
                @if($newCustomerUrl)
                    <a href="{{ $newCustomerUrl }}" class="rx-btn-secondary">Add Customer</a>
                @endif
                @if($newSaleUrl)
                    <a href="{{ $newSaleUrl }}" class="rx-btn-secondary">New Sale</a>
                @endif
                @if($newRentalUrl)
                    <a href="{{ $newRentalUrl }}" class="rx-btn">New Rental</a>
                @endif
            </div>
        </div>
    </section>

    <section class="dashboard-kpi-grid">
        @foreach($kpiCards as $card)
            @php $tag = !empty($card['href']) ? 'a' : 'div'; @endphp
            <{{ $tag }} @if(!empty($card['href'])) href="{{ $card['href'] }}" @endif class="dashboard-kpi-card {{ $toneCardClass($card['tone'] ?? null) }}">
                <div class="dashboard-kpi-head">
                    <span class="dashboard-kpi-label">{{ $card['label'] }}</span>
                    <span class="dashboard-kpi-icon">{!! $dashboardIcon($card['icon']) !!}</span>
                </div>
                <div class="dashboard-kpi-value">{{ $card['value'] }}</div>
                <div class="dashboard-kpi-subtitle">{{ $card['subtitle'] ?? '' }}</div>
                <div class="dashboard-kpi-note">{{ $card['note'] }}</div>
            </{{ $tag }}>
        @endforeach
    </section>

    @if($snapshotItems->isNotEmpty())
        <section class="dashboard-snapshot-grid">
            @foreach($snapshotItems as $item)
                <div class="dashboard-snapshot-item {{ $toneCardClass($item['tone'] ?? null) }}">
                    <div>
                        <span>{{ $item['label'] }}</span>
                        <strong>{{ $item['value'] }}</strong>
                        <small>{{ $item['copy'] }}</small>
                    </div>
                    <span class="dashboard-snapshot-icon">{!! $dashboardIcon($item['icon']) !!}</span>
                </div>
            @endforeach
        </section>
    @endif

    <details class="rx-card dashboard-filters-card" open>
        <summary class="rx-card-header">
            <div>
                <h2 class="rx-card-title">Filters</h2>
                <p class="rx-card-copy">Keep dashboard links and drilldowns aligned to one operational view.</p>
            </div>
            <span class="dashboard-filter-toggle" aria-hidden="true"></span>
        </summary>
        <div class="rx-card-body">
            <form method="GET" action="{{ $dashboardUrl }}" class="rx-form-grid">
                <div class="dashboard-filter-grid">
                    <label class="rx-field">
                        <span class="rx-label">Search</span>
                        <input type="text" name="search" value="{{ $search ?? '' }}" placeholder="Customer, rental, phone" class="rn-input" />
                    </label>
                    <label class="rx-field">
                        <span class="rx-label">City</span>
                        <select name="city" class="rn-input">
                            <option value="">All Cities</option>
                            @foreach($cities as $cityOption)
                                <option value="{{ $cityOption }}" @selected(($city ?? null) === $cityOption)>{{ $cityOption }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="rx-field">
                        <span class="rx-label">Vendor</span>
                        <select name="vendor_id" class="rn-input">
                            <option value="">All Vendors</option>
                            @foreach($vendors as $vendor)
                                <option value="{{ $vendor->id }}" @selected((string) ($vendorId ?? '') === (string) $vendor->id)>{{ $vendor->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="rx-field">
                        <span class="rx-label">Warehouse</span>
                        <select name="warehouse_id" class="rn-input">
                            <option value="">All Warehouses</option>
                            @foreach($warehouses as $warehouse)
                                <option value="{{ $warehouse->id }}" @selected((string) ($warehouseId ?? '') === (string) $warehouse->id)>{{ $warehouse->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="rx-field">
                        <span class="rx-label">From Date</span>
                        <input type="date" name="from_date" value="{{ $fromDate ?? '' }}" class="rn-input" />
                    </label>
                    <label class="rx-field">
                        <span class="rx-label">To Date</span>
                        <input type="date" name="to_date" value="{{ $toDate ?? '' }}" class="rn-input" />
                    </label>
                    <label class="rx-field">
                        <span class="rx-label">Sort</span>
                        <select name="sort_by" class="rn-input">
                            <option value="priority" @selected(($sortBy ?? 'priority') === 'priority')>Priority</option>
                            <option value="latest" @selected(($sortBy ?? '') === 'latest')>Newest First</option>
                            <option value="oldest" @selected(($sortBy ?? '') === 'oldest')>Oldest First</option>
                            <option value="amount_desc" @selected(($sortBy ?? '') === 'amount_desc')>Amount High-Low</option>
                            <option value="amount_asc" @selected(($sortBy ?? '') === 'amount_asc')>Amount Low-High</option>
                        </select>
                    </label>
                </div>

                <div class="rx-actions">
                    <button type="submit" class="rx-btn">Apply Filters</button>
                    <a href="{{ $safeRoute('dashboard') ?? $dashboardUrl }}" class="rx-btn-secondary">Reset</a>
                </div>
            </form>
        </div>
    </details>

    <section class="dashboard-main-grid">
        <div class="rx-card">
            <div class="rx-card-header">
                <div>
                    <h2 class="rx-card-title">Top Priority</h2>
                    <p class="rx-card-copy">Immediate risks and action queues.</p>
                </div>
            </div>
            <div class="rx-card-body">
                <div class="dashboard-priority-grid">
                    @foreach($primaryPriorityCards as $card)
                        @php $tag = !empty($card['href']) ? 'a' : 'div'; @endphp
                        <{{ $tag }} @if(!empty($card['href'])) href="{{ $card['href'] }}" @endif class="dashboard-priority-card {{ $toneCardClass($card['tone'] ?? null) }}">
                            <div class="dashboard-card-head">
                                <span class="dashboard-card-label">{{ $card['label'] }}</span>
                                <span class="dashboard-card-icon">{!! $dashboardIcon($card['icon']) !!}</span>
                            </div>
                            <div class="dashboard-card-value">{{ $card['value'] }}</div>
                            <div class="dashboard-card-subtitle">{{ $card['subtitle'] }}</div>
                            <div class="dashboard-card-note">{{ $card['note'] }}</div>
                        </{{ $tag }}>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="rx-card">
            <div class="rx-card-header">
                <div>
                    <h2 class="rx-card-title">Sales Pulse</h2>
                    <p class="rx-card-copy">Revenue, paid value, and pending collections.</p>
                </div>
            </div>
            <div class="rx-card-body">
                <div class="dashboard-sales-grid">
                    @foreach($salesCards as $card)
                        @php $tag = !empty($card['href']) ? 'a' : 'div'; @endphp
                        <{{ $tag }} @if(!empty($card['href'])) href="{{ $card['href'] }}" @endif class="dashboard-sales-card {{ $toneCardClass($card['tone'] ?? null) }}">
                            <div class="dashboard-card-head">
                                <span class="dashboard-card-label">{{ $card['label'] }}</span>
                                <span class="dashboard-card-icon">{!! $dashboardIcon($card['icon']) !!}</span>
                            </div>
                            <div class="dashboard-card-value">{{ $card['value'] }}</div>
                            <div class="dashboard-card-subtitle">{{ $card['subtitle'] }}</div>
                            <div class="dashboard-card-note">{{ $card['note'] }}</div>
                        </{{ $tag }}>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    <section class="dashboard-action-layout">
        <div class="rx-card">
            <div class="rx-card-header">
                <div>
                    <h2 class="rx-card-title">Today Action Panel</h2>
                    <p class="rx-card-copy">Keep the field and finance queues visible without jumping across modules.</p>
                </div>
            </div>
            <div class="rx-card-body">
                <div class="dashboard-priority-grid">
                    @foreach($actionItems as $item)
                        @php $tag = !empty($item['href']) ? 'a' : 'div'; @endphp
                        <{{ $tag }} @if(!empty($item['href'])) href="{{ $item['href'] }}" @endif class="dashboard-action-card {{ $toneCardClass($item['tone'] ?? null) }}">
                            <div class="dashboard-action-top">
                                <div>
                                    <strong class="dashboard-overview-head">{{ $item['label'] }}</strong>
                                    <p class="dashboard-overview-copy">{{ $item['copy'] }}</p>
                                </div>
                                <span class="dashboard-compact-chip">{{ number_format((int) $item['count']) }}</span>
                            </div>
                            <span class="dashboard-card-icon">{!! $dashboardIcon($item['icon']) !!}</span>
                        </{{ $tag }}>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="rx-card">
            <div class="rx-card-header">
                <div>
                    <h2 class="rx-card-title">Logistics Board</h2>
                    <p class="rx-card-copy">Delivery and pickup flow in one compact row.</p>
                </div>
            </div>
            <div class="rx-card-body">
                <div class="dashboard-logistics-grid">
                    @foreach($deliveryMiniTiles as $tile)
                        @php $tag = !empty($tile['href']) ? 'a' : 'div'; @endphp
                        <{{ $tag }} @if(!empty($tile['href'])) href="{{ $tile['href'] }}" @endif class="dashboard-logistics-card {{ $toneCardClass($tile['tone'] ?? null) }}">
                            <div class="dashboard-card-head">
                                <span class="dashboard-logistics-label">{{ $tile['label'] }}</span>
                                <span class="dashboard-card-icon">{!! $dashboardIcon($tile['icon']) !!}</span>
                            </div>
                            <div class="dashboard-card-value">{{ $tile['value'] }}</div>
                        </{{ $tag }}>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    @if($renewalMiniTiles->isNotEmpty())
        <section class="rx-card">
            <div class="rx-card-header">
                <div>
                    <h2 class="rx-card-title">Renewal Center</h2>
                    <p class="rx-card-copy">Due, overdue, and pickup-follow-up renewals in one operational queue.</p>
                </div>
                <a href="{{ route('renewal-center.index') }}" class="rx-btn-secondary">Open Renewal Center</a>
            </div>
            <div class="rx-card-body">
                <div class="dashboard-logistics-grid">
                    @foreach($renewalMiniTiles as $tile)
                        @php $tag = !empty($tile['href']) ? 'a' : 'div'; @endphp
                        <{{ $tag }} @if(!empty($tile['href'])) href="{{ $tile['href'] }}" @endif class="dashboard-logistics-card {{ $toneCardClass($tile['tone'] ?? null) }}">
                            <div class="dashboard-card-head">
                                <span class="dashboard-logistics-label">{{ $tile['label'] }}</span>
                                <span class="dashboard-card-icon">{!! $dashboardIcon($tile['icon']) !!}</span>
                            </div>
                            <div class="dashboard-card-value">{{ $tile['value'] }}</div>
                        </{{ $tag }}>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    @if($canViewFinance)
        <section class="rx-card">
            <div class="rx-card-header">
                <div>
                    <h2 class="rx-card-title">Finance Summary</h2>
                    <p class="rx-card-copy">Rental value, collections, and invoice exposure in one glance.</p>
                </div>
            </div>
            <div class="rx-card-body">
                <div class="dashboard-finance-grid">
                    @foreach($financeCards as $card)
                        @php $tag = !empty($card['href']) ? 'a' : 'div'; @endphp
                        <{{ $tag }} @if(!empty($card['href'])) href="{{ $card['href'] }}" @endif class="dashboard-finance-card {{ $toneCardClass($card['tone'] ?? null) }}">
                            <div class="dashboard-card-head">
                                <span class="dashboard-card-label">{{ $card['label'] }}</span>
                                <span class="dashboard-card-icon">{!! $dashboardIcon($card['icon']) !!}</span>
                            </div>
                            <div class="dashboard-card-value">{{ $card['value'] }}</div>
                            @if(!empty($card['note']))
                                <div class="dashboard-card-subcopy">{{ $card['note'] }}</div>
                            @endif
                        </{{ $tag }}>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    <section class="dashboard-overview-grid">
        <div class="rx-card">
            <div class="rx-card-header">
                <div>
                    <h2 class="rx-card-title">Recent Rentals</h2>
                    <p class="rx-card-copy">Latest rental orders already available in the controller feed.</p>
                </div>
                @if($rentalIndexUrl)
                    <a href="{{ $rentalIndexUrl }}" class="rx-btn-secondary">View All</a>
                @endif
            </div>
            <div class="rx-card-body">
                @if($recentRentalsSummary->isNotEmpty())
                    <div class="dashboard-overview-list">
                        @foreach($recentRentalsSummary as $rental)
                            <div class="dashboard-overview-item">
                                <div>
                                    <strong>Rental #{{ $rental->id }}</strong>
                                    <span>{{ $rental->customer_name ?? optional($rental->customer)->name ?? 'Customer' }}</span>
                                    <small>{{ optional($rental->product)->name ?? 'Product N/A' }} | {{ optional($rental->start_date)->format('d M Y') ?? 'Date N/A' }}</small>
                                </div>
                                <div style="text-align:right;">
                                    <span class="rx-badge {{ $statusBadgeClass($rental->status ?? null) }}">{{ \Illuminate\Support\Str::headline((string) ($rental->status ?? 'open')) }}</span>
                                    <small>{{ $currency($rental->rental_amount ?? 0) }}</small>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="rx-empty dashboard-empty">
                        <div class="rx-empty-icon">{!! $dashboardIcon('rental') !!}</div>
                        <strong>No recent rentals</strong>
                        <span>The dashboard did not receive a recent rental feed for this view.</span>
                    </div>
                @endif
            </div>
        </div>

        <div class="rx-card">
            <div class="rx-card-header">
                <div>
                    <h2 class="rx-card-title">Recent Customers</h2>
                    <p class="rx-card-copy">Customer activity appears here when the controller sends it.</p>
                </div>
                @if($customersIndexUrl)
                    <a href="{{ $customersIndexUrl }}" class="rx-btn-secondary">Open Customers</a>
                @endif
            </div>
            <div class="rx-card-body">
                @if($recentCustomersSummary->isNotEmpty())
                    <div class="dashboard-overview-list">
                        @foreach($recentCustomersSummary as $customer)
                            <div class="dashboard-overview-item">
                                <div>
                                    <strong>{{ $customer->name }}</strong>
                                    <span>{{ $customer->phone ?? 'No phone' }}</span>
                                    <small>{{ $customer->city ?? 'No city' }}</small>
                                </div>
                                <span class="rx-badge">Customer</span>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="rx-empty dashboard-empty">
                        <div class="rx-empty-icon">{!! $dashboardIcon('customer') !!}</div>
                        <strong>No recent customers feed yet</strong>
                        <span>This section stays clean until a recent customer collection is passed into the dashboard.</span>
                    </div>
                @endif
            </div>
        </div>

        <div class="rx-card">
            <div class="rx-card-header">
                <div>
                    <h2 class="rx-card-title">Recent Payments</h2>
                    <p class="rx-card-copy">Collections feed appears here when available. Until then, the latest sales still help signal commercial activity.</p>
                </div>
                @if($invoiceIndexUrl && $canReadInvoices)
                    <a href="{{ $invoiceIndexUrl }}" class="rx-btn-secondary">Open Invoices</a>
                @endif
            </div>
            <div class="rx-card-body">
                @if($recentPaymentsSummary->isNotEmpty())
                    <div class="dashboard-overview-list">
                        @foreach($recentPaymentsSummary as $payment)
                            <div class="dashboard-overview-item">
                                <div>
                                    <strong>{{ optional($payment->customer)->name ?? 'Customer' }}</strong>
                                    <span>{{ optional($payment->invoice)->invoice_number ?? 'Payment entry' }}</span>
                                    <small>{{ optional($payment->payment_date)->format('d M Y') ?? 'Date N/A' }}</small>
                                </div>
                                <small>{{ $currency($payment->amount ?? 0) }}</small>
                            </div>
                        @endforeach
                    </div>
                @elseif($recentSalesSummary->isNotEmpty())
                    <div class="dashboard-overview-list">
                        @foreach($recentSalesSummary as $sale)
                            <div class="dashboard-overview-item">
                                <div>
                                    <strong>{{ optional($sale->customer)->name ?? 'Customer' }}</strong>
                                    <span>{{ optional($sale->product)->name ?? 'Product N/A' }}</span>
                                    <small>{{ optional($sale->sale_date)->format('d M Y') ?? 'Date N/A' }}</small>
                                </div>
                                <div style="text-align:right;">
                                    <small>{{ $currency($sale->sale_amount ?? 0) }}</small>
                                    <span class="rx-badge {{ $statusBadgeClass($sale->payment_status ?? null) }}">{{ \Illuminate\Support\Str::headline((string) ($sale->payment_status ?? 'pending')) }}</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="rx-empty dashboard-empty">
                        <div class="rx-empty-icon">{!! $dashboardIcon('payment') !!}</div>
                        <strong>No recent payments</strong>
                        <span>The dashboard does not yet receive a dedicated recent payments collection for this role or filter.</span>
                    </div>
                @endif
            </div>
        </div>
    </section>

    <section class="dashboard-rank-grid">
        <div class="rx-card dashboard-rank-card">
            <div class="rx-card-header">
                <div>
                    <h2 class="rx-card-title">Top Cities</h2>
                    <p class="rx-card-copy">Best rental value concentration by city.</p>
                </div>
            </div>
            <div class="rx-card-body">
                @if($citySummaryRows->isNotEmpty())
                    <div class="dashboard-rank-list">
                        @foreach($citySummaryRows as $row)
                            <a href="{{ $mergeDashboardQuery('rentals.index', ['city' => ($row['label'] ?? null) !== 'Unspecified' ? ($row['label'] ?? null) : null]) }}" class="dashboard-rank-link">
                                <div class="dashboard-rank-head">
                                    <div>
                                        <strong class="dashboard-rank-title">{{ $row['label'] ?? 'Unknown' }}</strong>
                                        <span>{{ (int) ($row['count'] ?? 0) }} rentals</span>
                                    </div>
                                    <span class="dashboard-rank-title">{{ $currency($row['total_amount'] ?? 0) }}</span>
                                </div>
                                <div class="dashboard-bar-track"><div class="dashboard-bar-fill" style="width:{{ round((((float) ($row['total_amount'] ?? 0)) / $cityBreakdownMax) * 100, 1) }}%;background:var(--ph-color-primary);"></div></div>
                            </a>
                        @endforeach
                    </div>
                @else
                    <div class="rx-empty dashboard-empty">
                        <div class="rx-empty-icon">{!! $dashboardIcon('city') !!}</div>
                        <strong>No city performance data</strong>
                        <span>This view does not currently surface city rankings.</span>
                    </div>
                @endif
            </div>
        </div>

        <div class="rx-card dashboard-rank-card">
            <div class="rx-card-header">
                <div>
                    <h2 class="rx-card-title">Top Vendors</h2>
                    <p class="rx-card-copy">Assigned vendor or field staff impact.</p>
                </div>
            </div>
            <div class="rx-card-body">
                @if($vendorSummaryRows->isNotEmpty())
                    <div class="dashboard-rank-list">
                        @foreach($vendorSummaryRows as $row)
                            <a href="{{ $mergeDashboardQuery('rentals.index', ['vendor_id' => $row['vendor_id'] ?? null]) }}" class="dashboard-rank-link">
                                <div class="dashboard-rank-head">
                                    <div>
                                        <strong class="dashboard-rank-title">{{ $row['label'] ?? 'Unknown' }}</strong>
                                        <span>{{ (int) ($row['count'] ?? 0) }} rentals</span>
                                    </div>
                                    <span class="dashboard-rank-title">{{ $currency($row['total_amount'] ?? 0) }}</span>
                                </div>
                                <div class="dashboard-bar-track"><div class="dashboard-bar-fill" style="width:{{ round((((float) ($row['total_amount'] ?? 0)) / $vendorBreakdownMax) * 100, 1) }}%;background:var(--ph-color-success);"></div></div>
                            </a>
                        @endforeach
                    </div>
                @else
                    <div class="rx-empty dashboard-empty">
                        <div class="rx-empty-icon">{!! $dashboardIcon('vendor') !!}</div>
                        <strong>No vendor performance data</strong>
                        <span>This role or filter currently has no vendor ranking data.</span>
                    </div>
                @endif
            </div>
        </div>

        <div class="rx-card dashboard-rank-card">
            <div class="rx-card-header">
                <div>
                    <h2 class="rx-card-title">Top Warehouses</h2>
                    <p class="rx-card-copy">Dispatch source strength by contribution.</p>
                </div>
            </div>
            <div class="rx-card-body">
                @if($warehouseSummaryRows->isNotEmpty())
                    <div class="dashboard-rank-list">
                        @foreach($warehouseSummaryRows as $row)
                            <a href="{{ $mergeDashboardQuery('rentals.index', ['dispatch_warehouse_id' => $row['warehouse_id'] ?? null]) }}" class="dashboard-rank-link">
                                <div class="dashboard-rank-head">
                                    <div>
                                        <strong class="dashboard-rank-title">{{ $row['label'] ?? 'Unknown' }}</strong>
                                        <span>{{ (int) ($row['count'] ?? 0) }} rentals</span>
                                    </div>
                                    <span class="dashboard-rank-title">{{ $currency($row['total_amount'] ?? 0) }}</span>
                                </div>
                                <div class="dashboard-bar-track"><div class="dashboard-bar-fill" style="width:{{ round((((float) ($row['total_amount'] ?? 0)) / $warehouseBreakdownMax) * 100, 1) }}%;background:var(--ph-color-warning);"></div></div>
                            </a>
                        @endforeach
                    </div>
                @else
                    <div class="rx-empty dashboard-empty">
                        <div class="rx-empty-icon">{!! $dashboardIcon('warehouse') !!}</div>
                        <strong>No warehouse-linked data</strong>
                        <span>No warehouse performance information is available for this window.</span>
                    </div>
                @endif
            </div>
        </div>
    </section>

    <section class="dashboard-queue-grid">
        <div class="rx-card dashboard-queue-card">
            <div class="rx-card-header">
                <div>
                    <h2 class="rx-card-title">Critical Queue</h2>
                    <p class="rx-card-copy">Overdue delivered rentals that need immediate recovery.</p>
                </div>
                <span class="rx-badge is-danger">{{ $criticalQueue->count() }}</span>
            </div>
            <div class="rx-card-body">
                @if($criticalQueue->isNotEmpty())
                    <div class="dashboard-queue-list">
                        @foreach($criticalQueue as $rental)
                            <div class="dashboard-queue-item">
                                <div>
                                    <strong>#{{ $rental->id }} - {{ $rental->customer_name }}</strong>
                                    <small>{{ $rental->product->name ?? 'Product N/A' }} | Due {{ optional($rental->end_date)->format('d M Y') }}</small>
                                    <small>{{ $rental->phone ?: 'No phone' }}</small>
                                    <div class="dashboard-queue-actions">
                                        <a href="{{ route('rentals.show', $rental) }}">Open</a>
                                        @if($rental->phone)
                                            <a href="tel:{{ preg_replace('/\s+/', '', $rental->phone) }}">Call</a>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="rx-empty dashboard-empty">
                        <div class="rx-empty-icon">{!! $dashboardIcon('overdue') !!}</div>
                        <strong>No critical follow-ups</strong>
                        <span>No critical rentals are waiting in this filter set.</span>
                    </div>
                @endif
            </div>
        </div>

        <div class="rx-card dashboard-queue-card">
            <div class="rx-card-header">
                <div>
                    <h2 class="rx-card-title">Today Queue</h2>
                    <p class="rx-card-copy">Returns due today and rentals closing today.</p>
                </div>
                <span class="rx-badge is-warning">{{ $todayQueue->count() }}</span>
            </div>
            <div class="rx-card-body">
                @if($todayQueue->isNotEmpty())
                    <div class="dashboard-queue-list">
                        @foreach($todayQueue as $rental)
                            <div class="dashboard-queue-item">
                                <div>
                                    <strong>#{{ $rental->id }} - {{ $rental->customer_name }}</strong>
                                    <small>{{ $rental->product->name ?? 'Product N/A' }} | Due {{ optional($rental->end_date)->format('d M Y') }}</small>
                                    <small>{{ $rental->phone ?: 'No phone' }}</small>
                                    <div class="dashboard-queue-actions">
                                        <a href="{{ route('rentals.show', $rental) }}">Open</a>
                                        @if($rental->phone)
                                            <a href="tel:{{ preg_replace('/\s+/', '', $rental->phone) }}">Call</a>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="rx-empty dashboard-empty">
                        <div class="rx-empty-icon">{!! $dashboardIcon('pickup') !!}</div>
                        <strong>No same-day follow-ups</strong>
                        <span>No returns or same-day closures are waiting in this view.</span>
                    </div>
                @endif
            </div>
        </div>

        <div class="rx-card dashboard-queue-card">
            <div class="rx-card-header">
                <div>
                    <h2 class="rx-card-title">This Week</h2>
                    <p class="rx-card-copy">Upcoming rental closures that should not age into risk.</p>
                </div>
                <span class="rx-badge is-info">{{ $weekQueue->count() }}</span>
            </div>
            <div class="rx-card-body">
                @if($weekQueue->isNotEmpty())
                    <div class="dashboard-queue-list">
                        @foreach($weekQueue as $rental)
                            <div class="dashboard-queue-item">
                                <div>
                                    <strong>#{{ $rental->id }} - {{ $rental->customer_name }}</strong>
                                    <small>{{ $rental->product->name ?? 'Product N/A' }} | Due {{ optional($rental->end_date)->format('d M Y') }}</small>
                                    <small>{{ $rental->phone ?: 'No phone' }}</small>
                                    <div class="dashboard-queue-actions">
                                        <a href="{{ route('rentals.show', $rental) }}">Open</a>
                                        @if($rental->phone)
                                            <a href="tel:{{ preg_replace('/\s+/', '', $rental->phone) }}">Call</a>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="rx-empty dashboard-empty">
                        <div class="rx-empty-icon">{!! $dashboardIcon('trend') !!}</div>
                        <strong>No week-ahead queue</strong>
                        <span>No upcoming closures need attention in this filter set.</span>
                    </div>
                @endif
            </div>
        </div>
    </section>

    <section class="dashboard-trend-layout">
        <div class="rx-card dashboard-trend-card">
            <div class="rx-card-header">
                <div>
                    <h2 class="rx-card-title">Sales vs Rentals Last 6 Months</h2>
                    <p class="rx-card-copy">Compact comparative trend for commercial and rental direction.</p>
                </div>
            </div>
            <div class="rx-card-body">
                @if($monthlyTrendRows->isNotEmpty())
                    <div class="dashboard-trend-list">
                        @foreach($monthlyTrendRows as $row)
                            <div class="dashboard-trend-row">
                                <div class="dashboard-trend-head">
                                    <span>{{ $row['label'] ?? '-' }}</span>
                                    <span>{{ $currency($row['rental_total'] ?? 0) }} rental | {{ $currency($row['sales_total'] ?? 0) }} sales</span>
                                </div>
                                <div class="dashboard-trend-pair">
                                    <small class="dashboard-trend-copy">Rentals</small>
                                    <div class="dashboard-bar-track"><div class="dashboard-bar-fill" style="width:{{ round((((float) ($row['rental_total'] ?? 0)) / $trendMax) * 100, 1) }}%;background:var(--ph-color-primary);"></div></div>
                                    <small class="dashboard-trend-copy">Sales</small>
                                    <div class="dashboard-bar-track"><div class="dashboard-bar-fill" style="width:{{ round((((float) ($row['sales_total'] ?? 0)) / $trendMax) * 100, 1) }}%;background:var(--ph-color-success);"></div></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="rx-empty dashboard-empty">
                        <div class="rx-empty-icon">{!! $dashboardIcon('trend') !!}</div>
                        <strong>No trend data</strong>
                        <span>The dashboard does not have six-month trend data for this filter window.</span>
                    </div>
                @endif
            </div>
        </div>

        <div class="rx-card dashboard-trend-card">
            <div class="rx-card-header">
                <div>
                    <h2 class="rx-card-title">Business Direction</h2>
                    <p class="rx-card-copy">Supporting signals around stock, service pressure, and collections.</p>
                </div>
            </div>
            <div class="rx-card-body">
                <div class="dashboard-overview-list">
                    <div class="dashboard-inline-item">
                        <div>
                            <strong>Rental Available</strong>
                            <small>Tracked rental assets ready to dispatch</small>
                        </div>
                        <div style="text-align:right;">
                            <strong>{{ $availableRentalAssetsCount }}</strong>
                            @if($availableRentalAssetsUrl)
                                <small><a href="{{ $availableRentalAssetsUrl }}" style="color:var(--ph-color-primary);text-decoration:none;">Open rental assets</a></small>
                            @elseif($inventoryUrl)
                                <small><a href="{{ $inventoryUrl }}" style="color:var(--ph-color-primary);text-decoration:none;">Inventory dashboard</a></small>
                            @endif
                        </div>
                    </div>
                    <div class="dashboard-inline-item">
                        <div>
                            <strong>Sale Stock Available</strong>
                            <small>Quantity-based sellable stock</small>
                        </div>
                        <div style="text-align:right;">
                            <strong>{{ $availableSaleUnitsCount }}</strong>
                            @if($productsIndexUrl)
                                <small><a href="{{ $productsIndexUrl }}" style="color:var(--ph-color-primary);text-decoration:none;">Open Product Master</a></small>
                            @elseif($availableSaleUnitsUrl)
                                <small><a href="{{ $availableSaleUnitsUrl }}" style="color:var(--ph-color-primary);text-decoration:none;">Open serialized sale units</a></small>
                            @elseif($inventoryUrl)
                                <small><a href="{{ $inventoryUrl }}" style="color:var(--ph-color-primary);text-decoration:none;">Inventory dashboard</a></small>
                            @endif
                        </div>
                    </div>
                    <div class="dashboard-inline-item">
                        <div>
                            <strong>Maintenance Assets</strong>
                            <small>Assets needing service attention</small>
                        </div>
                        <div style="text-align:right;">
                            <strong>{{ $maintenanceAlertCountValue }}</strong>
                            @if($inventoryUrl)
                                <small><a href="{{ $inventoryUrl }}" style="color:var(--ph-color-primary);text-decoration:none;">Inventory dashboard</a></small>
                            @endif
                        </div>
                    </div>
                    <div class="dashboard-inline-item">
                        <div>
                            <strong>Payments Today</strong>
                            <small>Collection pulse inside this filter window</small>
                        </div>
                        <div style="text-align:right;">
                            <strong>{{ $currency($paymentsReceivedTodayAmount) }}</strong>
                            @if($reportsIndexUrl)
                                <small><a href="{{ $reportsIndexUrl }}" style="color:var(--ph-color-primary);text-decoration:none;">Open reports</a></small>
                            @endif
                        </div>
                    </div>
                    <div class="dashboard-inline-item">
                        <div>
                            <strong>Invoice Pressure</strong>
                            <small>Open unpaid and overdue invoice mix</small>
                        </div>
                        <div style="text-align:right;">
                            <strong>{{ $openInvoiceCountValue }}</strong>
                            <small>{{ $overdueInvoiceCountValue }} overdue now</small>
                        </div>
                    </div>
                    <div class="dashboard-inline-item">
                        <div>
                            <strong>Rental Lifecycle</strong>
                            <small>Active {{ $lifecycleActivePercent }}% | Pending delivery {{ $lifecyclePendingDeliveryPercent }}%</small>
                        </div>
                        <div style="text-align:right;">
                            <strong>{{ $returnedPercent }}%</strong>
                            <small>Returned mix</small>
                        </div>
                    </div>
                    <div class="dashboard-inline-item">
                        <div>
                            <strong>Delivered Base</strong>
                            <small>Delivered {{ $deliveredPercent }}% | Overdue {{ $overduePercent }}%</small>
                        </div>
                        <div style="text-align:right;">
                            <strong>{{ $deliveriesTodayCount }}</strong>
                            <small>today</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        var panel = document.querySelector('.dashboard-filters-card');

        if (!panel) {
            return;
        }

        if (window.innerWidth <= 640) {
            panel.removeAttribute('open');
        }
    });
</script>
@endsection
