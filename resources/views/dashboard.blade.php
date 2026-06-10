@extends('layouts.app')

@section('content')
@php
    $currentUser = auth()->user();
    $dashboardVisibility = $dashboardVisibility ?? [];
    $safeRoute = function (string $routeName, array $parameters = []) {
        return \Illuminate\Support\Facades\Route::has($routeName) ? route($routeName, $parameters) : null;
    };

    $canCreateRentals = $currentUser?->canAccessModule('rentals', 'create') ?? false;
    $canCreateSales = $currentUser?->canAccessModule('sales', 'create') ?? false;
    $canCreateCustomers = $currentUser?->canAccessModule('customers', 'create') ?? false;
    $canCreateBusinessPartners = $currentUser?->canAccessModule('customers', 'create') ?? false;
    $canCreatePayments = $currentUser?->canAccessModule('payments', 'create') ?? false;
    $canUpdateRentals = $currentUser?->canAccessModule('rentals', 'update') ?? false;
    $canReadCustomers = $currentUser?->canAccessModule('customers', 'read') ?? false;
    $canReadProducts = $currentUser?->canAccessModule('products', 'read') ?? false;
    $canReadSales = $currentUser?->canAccessModule('sales', 'read') ?? false;
    $canReadDeliveries = $currentUser?->canAccessModule('deliveries', 'read') ?? false;
    $canReadInvoices = $currentUser?->canAccessModule('invoices', 'read') ?? false;
    $canReadReports = $currentUser?->canAccessModule('reports', 'read') ?? false;
    $canViewFinance = (bool) ($dashboardVisibility['finance_widgets'] ?? false);
    $isDeliveryFacingMenuRole = (bool) ($dashboardVisibility['delivery_focused'] ?? false);
    $isWarehouseDashboardRole = (bool) ($dashboardVisibility['warehouse_focused'] ?? false);
    $showFinanceSection = $canViewFinance;
    $showSalesOperationsSection = (bool) ($dashboardVisibility['sales_analytics'] ?? false);
    $showInventorySection = (bool) ($dashboardVisibility['inventory_intelligence'] ?? false);
    $showStaffWorkloadSection = (bool) ($dashboardVisibility['staff_workload'] ?? false);
    $showBusinessSignalsSection = (bool) ($dashboardVisibility['business_signals'] ?? false);
    $showOrganizationAnalyticsSection = (bool) ($dashboardVisibility['organization_analytics'] ?? false);
    $showStaffOpsSection = $showStaffWorkloadSection || $showBusinessSignalsSection || $showInventorySection;
    $showExpandedStaffWorkloadSection = $showStaffWorkloadSection && !$canViewFinance && !$showSalesOperationsSection && !$isWarehouseDashboardRole && !$isDeliveryFacingMenuRole;
    $showExpandedBusinessSignalsSection = $showBusinessSignalsSection && !$canViewFinance && !$showSalesOperationsSection && !$isWarehouseDashboardRole && !$isDeliveryFacingMenuRole;
    $showExpandedInventorySection = $showInventorySection && !$canViewFinance && !$showSalesOperationsSection && !$isDeliveryFacingMenuRole;
    $showExpandedStaffOpsSection = $showExpandedStaffWorkloadSection || $showExpandedBusinessSignalsSection || $showExpandedInventorySection;
    $showExecutiveCompatibilityLabels = $canViewFinance && $showStaffOpsSection && $showOrganizationAnalyticsSection;
    $dashboardWidgetOrder = $dashboardVisibility['widget_order'] ?? [];
    $dashboardWidgetKeys = array_flip($dashboardVisibility['widget_keys'] ?? []);
    $dashboardWidgetEnabled = function (string $widgetKey) use ($dashboardWidgetKeys): bool {
        return array_key_exists($widgetKey, $dashboardWidgetKeys);
    };
    $dashboardWidgetSort = function (string $widgetKey, int $fallback = 999) use ($dashboardWidgetOrder): int {
        return (int) ($dashboardWidgetOrder[$widgetKey]['sort_order'] ?? $fallback);
    };
    $dashboardOrderScope = (string) ($dashboardOrderMetricsScope ?? 'organization');
    $dashboardTaskScope = (string) ($dashboardTaskMetricsScope ?? 'organization');
    $dashboardFollowUpScope = (string) ($dashboardFollowUpMetricsScope ?? 'organization');
    $orderScopePrefix = $dashboardOrderScope === 'mine' ? 'My ' : 'All ';
    $taskScopePrefix = $dashboardTaskScope === 'assigned' ? 'My ' : 'Total ';
    $followUpScopePrefix = $dashboardFollowUpScope === 'assigned' ? 'My ' : 'All ';

    $formatIndianNumber = function ($value, int $decimals = 2) {
        $number = abs((float) $value);
        $negative = (float) $value < 0 ? '-' : '';
        $formatted = number_format($number, $decimals, '.', '');
        [$integer, $fraction] = array_pad(explode('.', $formatted, 2), 2, '');

        if (strlen($integer) > 3) {
            $lastThree = substr($integer, -3);
            $leading = substr($integer, 0, -3);
            $leading = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $leading);
            $integer = ($leading !== '' ? $leading . ',' : '') . $lastThree;
        }

        return $negative . $integer . ($decimals > 0 ? '.' . $fraction : '');
    };
    $currency = fn ($value) => "\u{20B9}" . $formatIndianNumber($value, 2);
    $compactCurrency = function ($value) use ($formatIndianNumber) {
        $value = (float) $value;
        $absolute = abs($value);

        if ($absolute >= 10000000) {
            return "\u{20B9}" . rtrim(rtrim(number_format($value / 10000000, 2), '0'), '.') . 'Cr';
        }

        if ($absolute >= 100000) {
            return "\u{20B9}" . rtrim(rtrim(number_format($value / 100000, 2), '0'), '.') . 'L';
        }

        if ($absolute >= 1000) {
            return "\u{20B9}" . rtrim(rtrim(number_format($value / 1000, 1), '0'), '.') . 'K';
        }

        return "\u{20B9}" . $formatIndianNumber($value, 0);
    };
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
    $pickupCenterUrl = $safeRoute('pickup-center.index');
    $communicationCenterUrl = $safeRoute('communication-center.index');
    $inventoryUrl = $safeRoute('inventory.dashboard');
    $availableRentalAssetsUrl = $safeRoute('assets.index', ['asset_stage' => 'rental_stock', 'asset_status' => 'available']);
    $availableSaleUnitsUrl = $safeRoute('assets.index', ['asset_stage' => 'new_stock', 'asset_status' => 'available_for_sale']);
    $productsIndexUrl = $safeRoute('products.index');
    $customersIndexUrl = $safeRoute('customers.index');
    $deliveriesIndexUrl = $safeRoute('deliveries.index');
    $myAssignedTasksUrl = $deliveriesIndexUrl ? route('deliveries.index', ['ownership' => 'my']) : null;
    $myDeliveriesTodayUrl = $deliveriesIndexUrl ? route('deliveries.index', ['ownership' => 'my', 'tab' => 'today', 'task_type' => 'delivery']) : null;
    $myPickupsTodayUrl = $deliveriesIndexUrl ? route('deliveries.index', ['ownership' => 'my', 'tab' => 'today', 'task_type' => 'pickup']) : null;
    $myOverdueTasksUrl = $deliveriesIndexUrl ? route('deliveries.index', ['ownership' => 'my', 'tab' => 'overdue']) : null;
    $myFailedTasksUrl = $deliveriesIndexUrl ? route('deliveries.index', ['ownership' => 'my', 'workflow' => 'failed']) : null;
    $newRentalUrl = $canCreateRentals ? $safeRoute('rentals.create') : null;
    $newCustomerUrl = $canCreateCustomers ? $safeRoute('customers.create') : null;
    $newSaleUrl = $canCreateSales ? $safeRoute('sales.create') : null;
    $newBusinessPartnerUrl = $canCreateBusinessPartners ? $safeRoute('business-partners.create') : null;
    $schedulePickupUrl = $renewalCenterUrl ? route('renewal-center.index', ['tab' => 'pickup_requested']) : $pickupCenterUrl;
    $recordPaymentUrl = $communicationCenterUrl
        ? route('communication-center.index', ['tab' => 'payments'])
        : ($invoiceIndexUrl ? $mergeDashboardQuery('invoices.index', ['status' => 'open']) : null);

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
            'assets' => '<svg '.$attrs.'><path d="M21 8 12 3 3 8l9 5 9-5Z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/></svg>',
            'customer' => '<svg '.$attrs.'><path d="M16 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"/><circle cx="9.5" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/></svg>',
            'low-stock' => '<svg '.$attrs.'><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/></svg>',
            'sales' => '<svg '.$attrs.'><path d="M6 6h15l-2 8H8L6 6Z"/><path d="M6 6 5 3H2"/><circle cx="9" cy="20" r="1"/><circle cx="18" cy="20" r="1"/></svg>',
            'trend' => '<svg '.$attrs.'><path d="M4 19h16"/><path d="m5 15 4-4 4 3 6-8"/></svg>',
            'warehouse' => '<svg '.$attrs.'><path d="M3 21h18"/><path d="M4 21V8l8-5 8 5v13"/><path d="M9 21v-8h6v8"/></svg>',
            'vendor' => '<svg '.$attrs.'><path d="M8 12h8"/><path d="M7 7h.01"/><path d="M17 7h.01"/><path d="M5 4h14a2 2 0 0 1 2 2v8a5 5 0 0 1-5 5H8a5 5 0 0 1-5-5V6a2 2 0 0 1 2-2Z"/></svg>',
            'city' => '<svg '.$attrs.'><path d="M12 21s7-5.1 7-11a7 7 0 1 0-14 0c0 5.9 7 11 7 11Z"/><circle cx="12" cy="10" r="2"/></svg>',
            'tasks' => '<svg '.$attrs.'><path d="M9 6h11"/><path d="M9 12h11"/><path d="M9 18h11"/><path d="M4 6h.01"/><path d="M4 12h.01"/><path d="M4 18h.01"/></svg>',
            'completed' => '<svg '.$attrs.'><path d="m5 12 4 4L19 6"/></svg>',
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
    $pendingReceivableAmountValue = (float) ($pendingReceivableAmount ?? 0);
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
            'widget_key' => 'primary_pending_deliveries',
            'label' => $dashboardTaskScope === 'assigned' ? 'My Deliveries Today' : $taskScopePrefix . 'Pending Deliveries',
            'value' => $dashboardTaskScope === 'assigned' ? number_format((int) ($myDeliveriesTodayCount ?? 0)) : $deliveryTasksCountValue,
            'subtitle' => $dashboardTaskScope === 'assigned' ? $overdueDeliveryCountValue . ' overdue delivery task(s)' : $overdueDeliveryCountValue . ' overdue task(s)',
            'note' => $dashboardTaskScope === 'assigned' ? 'Scheduled for today and still open' : 'Open delivery tasks visible in Task Board',
            'href' => $dashboardTaskScope === 'assigned'
                ? $myDeliveriesTodayUrl
                : ($deliveriesIndexUrl ? route('deliveries.index', ['board' => 'delivery_workload']) : null),
            'tone' => 'amber',
            'icon' => 'delivery',
        ],
        [
            'widget_key' => 'primary_overdue_rentals',
            'label' => $orderScopePrefix . 'Overdue Rentals',
            'value' => $overdueReturnsCount,
            'subtitle' => 'Delivered and past due',
            'note' => $returnsDueTodayCountValue . ' returns due today',
            'href' => $mergeDashboardQuery('rentals.index', ['filter' => 'overdue', 'status' => null]),
            'tone' => 'red',
            'icon' => 'overdue',
        ],
        [
            'widget_key' => 'primary_outstanding_dues',
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
            'widget_key' => 'primary_pending_pickups',
            'label' => $dashboardTaskScope === 'assigned' ? 'My Pickups Today' : $taskScopePrefix . 'Pending Pickups',
            'value' => $dashboardTaskScope === 'assigned' ? number_format((int) ($myPickupsTodayCount ?? 0)) : $pickupTasksCountValue,
            'subtitle' => $dashboardTaskScope === 'assigned' ? $overduePickupCountValue . ' overdue pickup task(s)' : $overduePickupCountValue . ' overdue task(s)',
            'note' => $dashboardTaskScope === 'assigned' ? 'Scheduled for today and still open' : 'Open pickup tasks visible in Task Board',
            'href' => $dashboardTaskScope === 'assigned'
                ? $myPickupsTodayUrl
                : ($deliveriesIndexUrl ? route('deliveries.index', ['board' => 'pickup_workload']) : null),
            'tone' => 'amber',
            'icon' => 'pickup',
        ],
        [
            'widget_key' => 'primary_tasks_completed_today',
            'label' => $dashboardTaskScope === 'assigned' ? 'My Assigned Tasks' : 'Completed Today',
            'value' => $dashboardTaskScope === 'assigned' ? number_format((int) ($assignedOpenTasksCount ?? 0)) : $completedTodayCountValue,
            'subtitle' => $dashboardTaskScope === 'assigned'
                ? number_format((int) ($failedTasksCount ?? 0)) . ' failed/cancelled tasks in history'
                : $completedDeliveryCountValue . ' deliveries + ' . $completedPickupCountValue . ' pickups completed',
            'note' => $dashboardTaskScope === 'assigned' ? 'Open delivery + pickup workload assigned to you' : 'Tasks closed today only',
            'href' => $dashboardTaskScope === 'assigned'
                ? $myAssignedTasksUrl
                : ($deliveriesIndexUrl ? route('deliveries.index', ['board' => 'completed_today']) : null),
            'tone' => 'green',
            'icon' => 'completed',
        ],
    ])->filter(fn ($card) => ($card['visible'] ?? true) && $dashboardWidgetEnabled($card['widget_key']))->sortBy(fn ($card) => $dashboardWidgetSort($card['widget_key']))->values();

    $salesCards = $dashboardWidgetEnabled('section_sales_overview')
        ? ($canViewFinance
        ? collect([
            ['label' => 'Sales Today', 'value' => $todaySalesCount, 'subtitle' => 'Orders created today', 'note' => $currency($paidSalesAmountValue) . ' paid value', 'href' => $mergeDashboardQuery('sales.index', ['date' => now()->toDateString()]), 'tone' => 'green', 'icon' => 'sales'],
            ['label' => 'Sales This Month', 'value' => $salesThisMonthCountValue, 'subtitle' => 'Month-to-date sales volume', 'note' => $currency($salesThisMonthAmountValue), 'href' => $salesIndexUrl, 'tone' => 'blue', 'icon' => 'trend'],
            ['label' => 'Outstanding Sales Invoices', 'value' => $currency($salesOutstandingInvoiceAmountValue), 'subtitle' => 'Invoice raised, payment pending', 'note' => $salesOutstandingInvoiceCountValue . ' open sales invoices', 'href' => $salesIndexUrl, 'tone' => 'amber', 'icon' => 'payment'],
            ['label' => 'Unbilled Sales', 'value' => $currency($salesUnbilledAmountValue), 'subtitle' => 'Orders without invoice', 'note' => $salesUnbilledCountValue . ' sales not yet invoiced', 'href' => $salesIndexUrl, 'tone' => 'amber', 'icon' => 'sales'],
            ['label' => 'Total Pending Sales', 'value' => $currency($salesTotalPendingAmountValue), 'subtitle' => 'Total value not fully collected', 'note' => $currency($salesOutstandingInvoiceAmountValue) . ' invoiced + ' . $currency($salesUnbilledAmountValue) . ' unbilled', 'href' => $salesIndexUrl, 'tone' => 'red', 'icon' => 'trend'],
            ['label' => 'Paid Sales Value', 'value' => $currency($paidSalesAmountValue), 'subtitle' => 'Collected sales amount', 'note' => $currency($totalSalesAmountValue) . ' total sales', 'href' => $mergeDashboardQuery('sales.index', ['payment_status' => 'paid']), 'tone' => 'green', 'icon' => 'revenue'],
        ])
        : collect([
            ['label' => 'Sales Today', 'value' => $todaySalesCount, 'subtitle' => 'Orders created today', 'note' => 'Sales activity visible without amounts', 'href' => $mergeDashboardQuery('sales.index', ['date' => now()->toDateString()]), 'tone' => 'green', 'icon' => 'sales'],
            ['label' => 'Sales This Month', 'value' => $salesThisMonthCountValue, 'subtitle' => 'Month-to-date sales volume', 'note' => 'Operational order tracking only', 'href' => $salesIndexUrl, 'tone' => 'blue', 'icon' => 'trend'],
            ['label' => 'Open Sales Invoices', 'value' => number_format($salesOutstandingInvoiceCountValue), 'subtitle' => 'Invoice raised, payment pending', 'note' => 'Counts only for non-finance roles', 'href' => $salesIndexUrl, 'tone' => 'amber', 'icon' => 'payment'],
            ['label' => 'Unbilled Sales', 'value' => number_format($salesUnbilledCountValue), 'subtitle' => 'Orders without invoice', 'note' => 'Sales not yet invoiced', 'href' => $salesIndexUrl, 'tone' => 'amber', 'icon' => 'sales'],
            ['label' => 'Pending Sales Actions', 'value' => number_format($salesOutstandingInvoiceCountValue + $salesUnbilledCountValue), 'subtitle' => 'Orders needing invoice or payment follow-through', 'note' => 'No financial amounts shown', 'href' => $salesIndexUrl, 'tone' => 'red', 'icon' => 'trend'],
        ]))
        : collect();

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
    ])->when(!$dashboardWidgetEnabled('section_finance_summary'), fn ($cards) => collect());

    $deliveryMiniTiles = collect([
        ['label' => $dashboardTaskScope === 'assigned' ? 'My Assigned Tasks' : 'Total Tasks', 'value' => $dashboardTaskScope === 'assigned' ? number_format((int) ($assignedOpenTasksCount ?? 0)) : $totalTasksCountValue, 'href' => $dashboardTaskScope === 'assigned' ? $myAssignedTasksUrl : ($deliveriesIndexUrl ? route('deliveries.index') : null), 'tone' => 'blue', 'icon' => 'tasks'],
        ['label' => $dashboardTaskScope === 'assigned' ? 'My Deliveries Today' : $taskScopePrefix . 'Pending Deliveries', 'value' => $dashboardTaskScope === 'assigned' ? number_format((int) ($myDeliveriesTodayCount ?? 0)) : $deliveryTasksCountValue, 'href' => $dashboardTaskScope === 'assigned' ? $myDeliveriesTodayUrl : ($deliveriesIndexUrl ? route('deliveries.index', ['board' => 'delivery_workload']) : null), 'tone' => 'amber', 'icon' => 'delivery'],
        ['label' => $dashboardTaskScope === 'assigned' ? 'My Pickups Today' : $taskScopePrefix . 'Pending Pickups', 'value' => $dashboardTaskScope === 'assigned' ? number_format((int) ($myPickupsTodayCount ?? 0)) : $pickupTasksCountValue, 'href' => $dashboardTaskScope === 'assigned' ? $myPickupsTodayUrl : ($deliveriesIndexUrl ? route('deliveries.index', ['board' => 'pickup_workload']) : null), 'tone' => 'amber', 'icon' => 'pickup'],
        ['label' => $dashboardTaskScope === 'assigned' ? 'My Overdue Tasks' : 'Deliveries Completed', 'value' => $dashboardTaskScope === 'assigned' ? number_format((int) ($overdueDeliveryCountValue + $overduePickupCountValue)) : $completedDeliveryCountValue, 'href' => $dashboardTaskScope === 'assigned' ? $myOverdueTasksUrl : ($deliveriesIndexUrl ? route('deliveries.index', ['board' => 'completed_delivery']) : null), 'tone' => $dashboardTaskScope === 'assigned' ? 'red' : 'green', 'icon' => $dashboardTaskScope === 'assigned' ? 'overdue' : 'delivery'],
        ['label' => $dashboardTaskScope === 'assigned' ? 'Failed Attempts' : 'Pickups Completed', 'value' => $dashboardTaskScope === 'assigned' ? number_format((int) ($failedTasksCount ?? 0)) : $completedPickupCountValue, 'href' => $dashboardTaskScope === 'assigned' ? $myFailedTasksUrl : ($deliveriesIndexUrl ? route('deliveries.index', ['board' => 'completed_pickup']) : null), 'tone' => $dashboardTaskScope === 'assigned' ? 'amber' : 'green', 'icon' => 'pickup'],
        ['label' => $dashboardTaskScope === 'assigned' ? 'Completed Today' : 'Completed Today', 'value' => $completedTodayCountValue, 'href' => $deliveriesIndexUrl ? route('deliveries.index', ['board' => 'completed_today']) : null, 'tone' => 'green', 'icon' => 'completed'],
    ])->filter(fn ($tile) => $dashboardWidgetEnabled(match ($tile['label']) {
        'Total Tasks', 'My Assigned Tasks' => 'primary_tasks_completed_today',
        $taskScopePrefix . 'Pending Deliveries' => 'primary_pending_deliveries',
        $taskScopePrefix . 'Pending Pickups' => 'primary_pending_pickups',
        'My Deliveries Today' => 'primary_pending_deliveries',
        'My Pickups Today' => 'primary_pending_pickups',
        'Deliveries Completed' => 'kpi_deliveries_today',
        'My Overdue Tasks' => 'primary_pending_deliveries',
        'Failed Attempts' => 'primary_pending_pickups',
        'Pickups Completed' => 'widget_today_pickups',
        default => 'primary_tasks_completed_today',
    }))->values();

    $renewalMiniTiles = collect([
        ['label' => $orderScopePrefix . 'Renewals Due Today', 'value' => (int) ($renewalsDueTodayCount ?? 0), 'href' => $renewalCenterUrl ? route('renewal-center.index', ['tab' => 'due_today']) : null, 'tone' => 'amber', 'icon' => 'rental'],
        ['label' => $orderScopePrefix . 'Renewals Due This Week', 'value' => (int) ($renewalsDueThisWeekCount ?? 0), 'href' => $renewalCenterUrl ? route('renewal-center.index', ['tab' => 'next_7_days']) : null, 'tone' => 'blue', 'icon' => 'trend'],
        ['label' => $orderScopePrefix . 'Overdue Renewals', 'value' => (int) ($overdueRenewalsCount ?? 0), 'href' => $renewalCenterUrl ? route('renewal-center.index', ['tab' => 'overdue']) : null, 'tone' => 'red', 'icon' => 'overdue'],
        ['label' => $orderScopePrefix . 'Pickup Requests', 'value' => (int) ($pickupRequestedRenewalCount ?? 0), 'href' => $renewalCenterUrl ? route('renewal-center.index', ['tab' => 'pickup_requested']) : null, 'tone' => 'green', 'icon' => 'pickup'],
    ])->filter(fn ($tile) => $currentUser?->canAccessModule('rentals', 'read') && !empty($tile['href']) && $dashboardWidgetEnabled('widget_today_renewals'))->values();
    $pickupCenterMiniTiles = collect([
        ['label' => $taskScopePrefix . 'Pickups Scheduled Today', 'value' => (int) ($pickupsScheduledTodayCount ?? 0), 'href' => $pickupCenterUrl ? route('pickup-center.index', ['tab' => 'scheduled_today']) : null, 'tone' => 'amber', 'icon' => 'pickup'],
        ['label' => $taskScopePrefix . 'Overdue Pickups', 'value' => (int) ($pickupCenterOverdueCount ?? 0), 'href' => $pickupCenterUrl ? route('pickup-center.index', ['tab' => 'overdue']) : null, 'tone' => 'red', 'icon' => 'overdue'],
        ['label' => $taskScopePrefix . 'Failed Pickups', 'value' => (int) ($failedPickupsCount ?? 0), 'href' => $pickupCenterUrl ? route('pickup-center.index', ['tab' => 'failed_attempt']) : null, 'tone' => 'amber', 'icon' => 'pickup'],
        ['label' => 'Awaiting Return Verification', 'value' => (int) ($awaitingReturnVerificationCount ?? 0), 'href' => $safeRoute('assets.pending-verification'), 'tone' => 'blue', 'icon' => 'assets'],
    ])->filter(fn ($tile) => !empty($tile['href']) && ($canReadDeliveries || ($currentUser?->canAccessModule('assets', 'read') ?? false)) && $dashboardWidgetEnabled('widget_today_pickups'))->values();
    $communicationMiniTiles = collect([
        ['label' => $followUpScopePrefix . 'Follow-ups Due Today', 'value' => (int) ($followUpsDueTodayCount ?? 0), 'href' => $communicationCenterUrl ? route('communication-center.index', ['tab' => 'today']) : null, 'tone' => 'amber', 'icon' => 'tasks'],
        ['label' => $followUpScopePrefix . 'Overdue Follow-ups', 'value' => (int) ($overdueFollowUpsCount ?? 0), 'href' => $communicationCenterUrl ? route('communication-center.index', ['tab' => 'overdue']) : null, 'tone' => 'red', 'icon' => 'overdue'],
        ['label' => $followUpScopePrefix . 'Pending Renewals', 'value' => (int) ($pendingRenewalFollowUpsCount ?? 0), 'href' => $communicationCenterUrl ? route('communication-center.index', ['tab' => 'renewals']) : null, 'tone' => 'blue', 'icon' => 'rental'],
        ['label' => $followUpScopePrefix . 'Pending Payments', 'value' => (int) ($pendingPaymentFollowUpsCount ?? 0), 'href' => $communicationCenterUrl ? route('communication-center.index', ['tab' => 'payments']) : null, 'tone' => 'amber', 'icon' => 'payment'],
        ['label' => $followUpScopePrefix . 'Pending Pickups', 'value' => (int) ($pendingPickupFollowUpsCount ?? 0), 'href' => $communicationCenterUrl ? route('communication-center.index', ['tab' => 'pickups']) : null, 'tone' => 'green', 'icon' => 'pickup'],
    ])->filter(fn ($tile) => !empty($tile['href']) && !$isDeliveryFacingMenuRole && $dashboardWidgetEnabled('widget_today_followups'))->values();

    $dashboardInsightCards = collect([
        [
            'row' => 'operations',
            'sort' => 10,
            'label' => 'Active Rentals',
            'value' => number_format($activeRentalsCount),
            'insight' => $endingSoonCount > 0 ? number_format($endingSoonCount) . ' ending soon' : 'No urgent action',
            'insight_tone' => $endingSoonCount > 0 ? 'warning' : 'success',
            'note' => $overdueReturnsCount > 0
                ? number_format($overdueReturnsCount) . ' overdue · ' . number_format($returnsDueTodayCountValue) . ' due today'
                : number_format($returnsDueTodayCountValue) . ' due today',
            'icon' => 'rental',
            'href' => $mergeDashboardQuery('rentals.index', ['status' => 'live']),
            'tone' => $overdueReturnsCount > 0 ? 'danger' : 'success',
        ],
        [
            'row' => 'operations',
            'sort' => 20,
            'label' => 'Pending Deliveries',
            'value' => number_format($deliveryTasksCountValue),
            'insight' => $overdueDeliveryCountValue > 0 ? number_format($overdueDeliveryCountValue) . ' overdue' : 'On track',
            'insight_tone' => $overdueDeliveryCountValue > 0 ? 'danger' : 'success',
            'note' => number_format($scheduledDeliveryCountValue) . ' scheduled · ' . number_format($outForDeliveryCountValue) . ' out for delivery',
            'icon' => 'delivery',
            'href' => $deliveriesIndexUrl ? route('deliveries.index', ['board' => 'delivery_workload']) : null,
            'tone' => $overdueDeliveryCountValue > 0 ? 'danger' : 'warning',
            'visible' => $canReadDeliveries,
        ],
        [
            'row' => 'operations',
            'sort' => 30,
            'label' => 'Pending Pickups',
            'value' => number_format($pickupTasksCountValue),
            'insight' => $overduePickupCountValue > 0 ? number_format($overduePickupCountValue) . ' overdue' : 'No urgent action',
            'insight_tone' => $overduePickupCountValue > 0 ? 'danger' : 'success',
            'note' => number_format((int) ($pickupsScheduledTodayCount ?? 0)) . ' due today · ' . number_format((int) ($awaitingReturnVerificationCount ?? 0)) . ' awaiting verification',
            'icon' => 'pickup',
            'href' => $deliveriesIndexUrl ? route('deliveries.index', ['board' => 'pickup_workload']) : null,
            'tone' => $overduePickupCountValue > 0 ? 'danger' : 'warning',
            'visible' => $canReadDeliveries,
        ],
        [
            'row' => 'operations',
            'sort' => 40,
            'label' => 'Outstanding Invoices',
            'value' => number_format($outstandingInvoiceCountValue),
            'insight' => $overdueInvoiceCountValue > 0 ? number_format($overdueInvoiceCountValue) . ' overdue now' : 'No urgent action',
            'insight_tone' => $overdueInvoiceCountValue > 0 ? 'danger' : 'success',
            'note' => $canViewFinance ? $currency($outstandingInvoiceAmountValue) . ' unpaid balance' : 'Open invoices awaiting collection',
            'icon' => 'payment',
            'href' => $mergeDashboardQuery('invoices.index', ['status' => 'open']),
            'tone' => $overdueInvoiceCountValue > 0 ? 'danger' : 'warning',
            'visible' => $canReadInvoices,
        ],
        [
            'row' => 'operations',
            'sort' => 50,
            'label' => 'Collections This Month',
            'value' => $currency($paymentsReceivedThisMonthAmount),
            'insight' => $paymentsReceivedTodayAmount > 0 ? $currency($paymentsReceivedTodayAmount) . ' received today' : 'No collections recorded today',
            'insight_tone' => $paymentsReceivedTodayAmount > 0 ? 'success' : 'info',
            'note' => $outstandingDueAmountValue > 0 ? $currency($outstandingDueAmountValue) . ' still outstanding' : 'No outstanding balance',
            'icon' => 'revenue',
            'href' => $reportsIndexUrl ?: $invoiceIndexUrl,
            'tone' => 'success',
            'visible' => $canViewFinance && ($canReadReports || $canReadInvoices),
        ],
        [
            'row' => 'revenue_protection',
            'sort' => 10,
            'label' => 'Unbilled Rentals',
            'value' => number_format($unbilledRentalReceivableCountValue),
            'insight' => 'Delivered rentals without invoice',
            'insight_tone' => $unbilledRentalReceivableCountValue > 0 ? 'warning' : 'success',
            'note' => $canViewFinance ? $currency($unbilledRentalReceivableAmountValue) . ' not yet invoiced' : 'Delivered rentals awaiting invoice',
            'icon' => 'rental',
            'href' => $mergeDashboardQuery('rentals.index', ['status' => 'live']),
            'tone' => $unbilledRentalReceivableCountValue > 0 ? 'warning' : 'success',
            'visible' => $canReadInvoices,
        ],
        [
            'row' => 'revenue_protection',
            'sort' => 20,
            'label' => 'Unbilled Sales',
            'value' => number_format($unbilledSaleReceivableCountValue),
            'insight' => 'Sale orders without invoice',
            'insight_tone' => $unbilledSaleReceivableCountValue > 0 ? 'warning' : 'success',
            'note' => $canViewFinance ? $currency($unbilledSaleReceivableAmountValue) . ' not yet invoiced' : 'Sales awaiting invoice',
            'icon' => 'sales',
            'href' => $salesIndexUrl,
            'tone' => $unbilledSaleReceivableCountValue > 0 ? 'warning' : 'success',
            'visible' => $canReadInvoices,
        ],
        [
            'row' => 'revenue_protection',
            'sort' => 30,
            'label' => 'Unpaid Renewal Invoices',
            'value' => number_format((int) ($unpaidRenewalCount ?? 0)),
            'insight' => 'Pending renewal collection',
            'insight_tone' => ((int) ($unpaidRenewalCount ?? 0)) > 0 ? 'danger' : 'success',
            'note' => $canViewFinance ? $currency((float) ($unpaidRenewalAmount ?? 0)) . ' outstanding renewal amount' : 'Renewal invoices awaiting payment',
            'icon' => 'payment',
            'href' => $mergeDashboardQuery('invoices.index', ['status' => 'open']),
            'tone' => ((int) ($unpaidRenewalCount ?? 0)) > 0 ? 'danger' : 'success',
            'visible' => $canReadInvoices,
        ],
        [
            'row' => 'revenue_protection',
            'sort' => 40,
            'label' => 'Open Invoices',
            'value' => number_format($openInvoiceCountValue),
            'insight' => $overdueInvoiceCountValue > 0 ? number_format($overdueInvoiceCountValue) . ' overdue' : 'Awaiting closure',
            'insight_tone' => $overdueInvoiceCountValue > 0 ? 'warning' : 'info',
            'note' => $canViewFinance ? $currency($outstandingDueAmountValue) . ' outstanding' : 'Invoices awaiting closure',
            'icon' => 'payment',
            'href' => $mergeDashboardQuery('invoices.index', ['status' => 'open']),
            'tone' => 'warning',
            'visible' => $canReadInvoices,
        ],
        [
            'row' => 'revenue_protection',
            'sort' => 50,
            'label' => 'Collections Today',
            'value' => $currency($paymentsReceivedTodayAmount),
            'insight' => 'Cash received so far',
            'insight_tone' => $paymentsReceivedTodayAmount > 0 ? 'success' : 'info',
            'note' => $paymentsReceivedThisMonthAmount > 0 ? $currency($paymentsReceivedThisMonthAmount) . ' collected this month' : 'No collections recorded this month',
            'icon' => 'revenue',
            'href' => $reportsIndexUrl ?: $invoiceIndexUrl,
            'tone' => 'success',
            'visible' => $canViewFinance,
        ],
        [
            'row' => 'inventory_readiness',
            'sort' => 10,
            'label' => 'Rental Available',
            'value' => number_format($availableRentalAssetsCount),
            'insight' => $maintenanceAlertCountValue > 0 ? number_format($maintenanceAlertCountValue) . ' maintenance alerts' : 'Assets ready for dispatch',
            'insight_tone' => $maintenanceAlertCountValue > 0 ? 'warning' : 'success',
            'note' => 'Tracked rental assets ready to dispatch',
            'icon' => 'asset',
            'href' => $availableRentalAssetsUrl ?: $inventoryUrl,
            'tone' => 'info',
            'visible' => $showInventorySection && !empty($inventoryUrl),
        ],
        [
            'row' => 'inventory_readiness',
            'sort' => 20,
            'label' => 'Sale Stock Available',
            'value' => number_format($availableSaleUnitsCount),
            'insight' => 'Warehouse stock',
            'insight_tone' => 'info',
            'note' => 'Sellable quantity in Product Master / warehouses',
            'icon' => 'sales',
            'href' => $productsIndexUrl ?: ($availableSaleUnitsUrl ?: $inventoryUrl),
            'tone' => 'info',
            'visible' => $showInventorySection && (!empty($productsIndexUrl) || !empty($inventoryUrl)),
        ],
        [
            'row' => 'inventory_readiness',
            'sort' => 30,
            'label' => 'Asset Alerts',
            'value' => number_format($maintenanceAlertCountValue),
            'insight' => $maintenanceAlertCountValue > 0 ? 'Maintenance or unavailable' : 'No active alerts',
            'insight_tone' => $maintenanceAlertCountValue > 0 ? 'danger' : 'success',
            'note' => number_format((int) ($awaitingReturnVerificationCount ?? 0)) . ' awaiting verification',
            'icon' => 'low-stock',
            'href' => $inventoryUrl,
            'tone' => $maintenanceAlertCountValue > 0 ? 'danger' : 'info',
            'visible' => $showInventorySection && !empty($inventoryUrl),
        ],
        [
            'row' => 'inventory_readiness',
            'sort' => 40,
            'label' => 'Returns Expected',
            'value' => number_format($returnsDueTodayCountValue),
            'insight' => $returnsDueTodayCountValue > 0 ? 'Due back today' : 'No returns due today',
            'insight_tone' => $returnsDueTodayCountValue > 0 ? 'warning' : 'info',
            'note' => $overdueReturnsCount > 0 ? number_format($overdueReturnsCount) . ' overdue return(s)' : 'Return desk is clear',
            'icon' => 'pickup',
            'href' => $mergeDashboardQuery('rentals.index', ['filter' => 'returns_due_today', 'status' => null]),
            'tone' => 'warning',
        ],
        [
            'row' => 'reference',
            'sort' => 10,
            'label' => 'Total Customers',
            'value' => number_format((int) ($totalCustomers ?? 0)),
            'insight' => ((int) ($activeRentalCustomerCount ?? 0)) > 0 ? number_format((int) ($activeRentalCustomerCount ?? 0)) . ' with live rentals' : 'No live rental customers yet',
            'insight_tone' => ((int) ($activeRentalCustomerCount ?? 0)) > 0 ? 'info' : 'success',
            'note' => ((int) ($newCustomersThisMonth ?? 0)) > 0 ? '+' . number_format((int) ($newCustomersThisMonth ?? 0)) . ' added this month' : 'No new customers added this month',
            'icon' => 'customer',
            'href' => $customersIndexUrl,
            'tone' => 'info',
            'visible' => $canReadCustomers && !empty($customersIndexUrl),
        ],
        [
            'row' => 'reference',
            'sort' => 20,
            'label' => 'Products',
            'value' => number_format((int) ($totalProductsCount ?? 0)),
            'insight' => number_format((int) ($rentableProductsCount ?? 0)) . ' rentable · ' . number_format((int) ($sellableProductsCount ?? 0)) . ' sellable',
            'insight_tone' => 'info',
            'note' => number_format((int) ($bothProductsCount ?? 0)) . ' support both rental and sale',
            'icon' => 'asset',
            'href' => $productsIndexUrl,
            'tone' => 'info',
            'visible' => ($canReadProducts || $showInventorySection) && !empty($productsIndexUrl),
        ],
        [
            'row' => 'reference',
            'sort' => 30,
            'label' => 'Vendors',
            'value' => number_format((int) ($totalBusinessPartners ?? 0)),
            'insight' => 'Business partner network',
            'insight_tone' => 'info',
            'note' => 'Delivery and referral partners in PHOS',
            'icon' => 'vendor',
            'href' => $safeRoute('business-partners.index'),
            'tone' => 'info',
            'visible' => $canReadCustomers && !empty($safeRoute('business-partners.index')),
        ],
        [
            'row' => 'reference',
            'sort' => 40,
            'label' => 'Overdue Rentals',
            'value' => number_format($overdueReturnsCount),
            'insight' => $returnsDueTodayCountValue > 0 ? number_format($returnsDueTodayCountValue) . ' due today' : 'Past promised return date',
            'insight_tone' => $overdueReturnsCount > 0 ? 'danger' : 'info',
            'note' => $activePercent . '% of rental base active',
            'icon' => 'overdue',
            'href' => $mergeDashboardQuery('rentals.index', ['filter' => 'overdue', 'status' => null]),
            'tone' => $overdueReturnsCount > 0 ? 'danger' : 'info',
        ],
    ])->filter(fn ($card) => ($card['visible'] ?? true))->values();

    $operationalInsightCards = $dashboardInsightCards->where('row', 'operations')->sortBy('sort')->values();
    $revenueProtectionCards = $dashboardInsightCards->where('row', 'revenue_protection')->sortBy('sort')->values();
    $inventoryReadinessCards = $dashboardInsightCards->where('row', 'inventory_readiness')->sortBy('sort')->values();
    $referenceInsightCards = $dashboardInsightCards->where('row', 'reference')->sortBy('sort')->values();
    $actionItems = collect([
        [
            'widget_key' => 'primary_pending_deliveries',
            'label' => 'Deliveries Pending',
            'count' => $deliveryTasksCountValue,
            'copy' => $overdueDeliveryCountValue > 0 ? $overdueDeliveryCountValue . ' overdue task(s)' : 'Open delivery tasks',
            'href' => $deliveriesIndexUrl ? route('deliveries.index', ['board' => 'delivery_workload']) : null,
            'icon' => 'delivery',
            'tone' => 'amber',
        ],
        [
            'widget_key' => 'primary_pending_pickups',
            'label' => 'Pickups Pending',
            'count' => $pickupTasksCountValue,
            'copy' => $overduePickupCountValue > 0 ? $overduePickupCountValue . ' overdue task(s)' : 'Open pickup tasks',
            'href' => $deliveriesIndexUrl ? route('deliveries.index', ['board' => 'pickup_workload']) : null,
            'icon' => 'pickup',
            'tone' => 'blue',
        ],
        [
            'widget_key' => 'primary_outstanding_dues',
            'label' => 'Overdue Payments',
            'count' => $overdueInvoiceCountValue,
            'copy' => 'Invoices needing finance follow-up',
            'href' => $mergeDashboardQuery('invoices.index', ['status' => 'overdue']),
            'icon' => 'payment',
            'tone' => 'red',
            'visible' => $canViewFinance,
        ],
        [
            'widget_key' => 'snapshot_asset_alerts',
            'label' => 'Low Stock / Asset Alerts',
            'count' => $maintenanceAlertCountValue,
            'copy' => 'Assets in maintenance or unavailable',
            'href' => $inventoryUrl,
            'icon' => 'low-stock',
            'tone' => null,
            'visible' => $showInventorySection,
        ],
    ])->filter(fn ($item) => ($item['visible'] ?? true) && $dashboardWidgetEnabled($item['widget_key']))->sortBy(fn ($item) => $dashboardWidgetSort($item['widget_key']))->values();

    $snapshotItems = collect([
        [
            'widget_key' => 'snapshot_collections_today',
            'label' => 'Collections Today',
            'value' => $currency($paymentsReceivedTodayAmount),
            'copy' => 'Cash received so far',
            'icon' => 'revenue',
            'tone' => 'green',
            'visible' => $canViewFinance,
        ],
        [
            'widget_key' => 'snapshot_deliveries_pending',
            'label' => 'Deliveries Pending',
            'value' => number_format($pendingDeliveryCountValue),
            'copy' => 'Tasks waiting to leave',
            'icon' => 'delivery',
            'tone' => 'blue',
            'visible' => $canReadDeliveries,
        ],
        [
            'widget_key' => 'snapshot_returns_expected',
            'label' => 'Returns Expected',
            'value' => number_format($returnsDueTodayCountValue),
            'copy' => 'Due back today',
            'icon' => 'pickup',
            'tone' => 'amber',
        ],
        [
            'widget_key' => 'snapshot_open_invoices',
            'label' => 'Open Invoices',
            'value' => number_format($openInvoiceCountValue),
            'copy' => 'Awaiting closure',
            'icon' => 'payment',
            'tone' => 'amber',
            'visible' => $canReadInvoices,
        ],
        [
            'widget_key' => 'snapshot_asset_alerts',
            'label' => 'Asset Alerts',
            'value' => number_format($maintenanceAlertCountValue),
            'copy' => 'Maintenance or unavailable',
            'icon' => 'low-stock',
            'tone' => $maintenanceAlertCountValue > 0 ? 'red' : 'blue',
            'visible' => $showInventorySection && !empty($inventoryUrl),
        ],
    ])->filter(fn ($item) => ($item['visible'] ?? true) && $dashboardWidgetEnabled($item['widget_key']))->sortBy(fn ($item) => $dashboardWidgetSort($item['widget_key']))->values();

    $compactListLimit = 2;
    $recentSalesSummary = $dashboardWidgetEnabled('section_sales_overview') ? collect($recentSales ?? collect())->take(5) : collect();
    $recentRentalsSummary = $dashboardWidgetEnabled('section_recent_rentals') ? collect($recentRentals ?? collect())->take(6) : collect();
    $recentCustomersSummary = $dashboardWidgetEnabled('section_recent_customers') ? collect($recentCustomers ?? collect())->take(5) : collect();
    $recentPaymentsSummary = $dashboardWidgetEnabled('section_recent_payments') ? collect($recentPayments ?? collect())->take(5) : collect();
    $recentDeliveriesSummary = $dashboardWidgetEnabled('section_recent_deliveries') ? collect($recentDeliveries ?? collect())->take(5) : collect();
    $recentFollowUpsSummary = $dashboardWidgetEnabled('section_high_priority_followups') ? collect($recentFollowUps ?? collect())->take(5) : collect();
    $recentActivitiesSummary = $dashboardWidgetEnabled('section_recent_activity') ? collect($recentActivities ?? collect())->take(6) : collect();
    $todayRenewalSummary = $dashboardWidgetEnabled('widget_today_renewals') ? collect($todayRenewalItems ?? collect())->take(5) : collect();
    $todayPickupSummary = $dashboardWidgetEnabled('widget_today_pickups') ? collect($todayPickupItems ?? collect())->take(5) : collect();
    $todayDeliverySummary = $dashboardWidgetEnabled('widget_today_deliveries') ? collect($todayDeliveryItems ?? collect())->take(5) : collect();
    $todayFollowUpSummary = $dashboardWidgetEnabled('widget_today_followups') ? collect($todayFollowUps ?? collect())->take(5) : collect();
    $pendingPaymentSummary = $dashboardWidgetEnabled('widget_pending_payments') ? collect($pendingPaymentItems ?? collect())->take(5) : collect();
    $highPriorityFollowUpSummary = $dashboardWidgetEnabled('section_high_priority_followups') ? collect($highPriorityFollowUps ?? collect())->take(5) : collect();
    $todayRenewalSummaryVisible = $todayRenewalSummary->take($compactListLimit);
    $todayRenewalSummaryHidden = $todayRenewalSummary->slice($compactListLimit)->values();
    $todayPickupSummaryVisible = $todayPickupSummary->take($compactListLimit);
    $todayPickupSummaryHidden = $todayPickupSummary->slice($compactListLimit)->values();
    $todayDeliverySummaryVisible = $todayDeliverySummary->take($compactListLimit);
    $todayDeliverySummaryHidden = $todayDeliverySummary->slice($compactListLimit)->values();
    $todayFollowUpSummaryVisible = $todayFollowUpSummary->take($compactListLimit);
    $todayFollowUpSummaryHidden = $todayFollowUpSummary->slice($compactListLimit)->values();
    $pendingPaymentSummaryVisible = $pendingPaymentSummary->take($compactListLimit);
    $pendingPaymentSummaryHidden = $pendingPaymentSummary->slice($compactListLimit)->values();
    $recentDeliveriesSummaryVisible = $recentDeliveriesSummary->take($compactListLimit);
    $recentDeliveriesSummaryHidden = $recentDeliveriesSummary->slice($compactListLimit)->values();
    $highPriorityFollowUpSummaryVisible = $highPriorityFollowUpSummary->take($compactListLimit);
    $highPriorityFollowUpSummaryHidden = $highPriorityFollowUpSummary->slice($compactListLimit)->values();
    $recentActivitiesSummaryVisible = $recentActivitiesSummary->take(3);
    $recentActivitiesSummaryHidden = $recentActivitiesSummary->slice(3)->values();
    $recentRentalsSummaryVisible = $recentRentalsSummary->take($compactListLimit);
    $recentRentalsSummaryHidden = $recentRentalsSummary->slice($compactListLimit)->values();
    $recentCustomersSummaryVisible = $recentCustomersSummary->take($compactListLimit);
    $recentCustomersSummaryHidden = $recentCustomersSummary->slice($compactListLimit)->values();
    $recentPaymentsSummaryVisible = $recentPaymentsSummary->take($compactListLimit);
    $recentPaymentsSummaryHidden = $recentPaymentsSummary->slice($compactListLimit)->values();
    $staffWorkloadSummary = collect($staffWorkloadRows ?? collect())->take(6);
    $partnerOperationalSummary = collect($partnerOperationalRows ?? collect())->take(5);
    $lowStockSummary = collect($lowStockProducts ?? collect())->take(5);
    $highUtilizationSummary = collect($highUtilizationProducts ?? collect())->take(5);
    $idleInventorySummary = collect($idleInventoryProducts ?? collect())->take(5);
    $partnerOperationalSummaryVisible = $partnerOperationalSummary->take(3);
    $partnerOperationalSummaryHidden = $partnerOperationalSummary->slice(3)->values();
    $lowStockSummaryVisible = $lowStockSummary->take($compactListLimit);
    $lowStockSummaryHidden = $lowStockSummary->slice($compactListLimit)->values();
    $highUtilizationSummaryVisible = $highUtilizationSummary->take($compactListLimit);
    $highUtilizationSummaryHidden = $highUtilizationSummary->slice($compactListLimit)->values();
    $idleInventorySummaryVisible = $idleInventorySummary->take($compactListLimit);
    $idleInventorySummaryHidden = $idleInventorySummary->slice($compactListLimit)->values();
    $dashboardQuickActions = collect(
        $isDeliveryFacingMenuRole
            ? [
                ['label' => 'Today\'s Tasks', 'href' => $safeRoute('deliveries.assigned'), 'tone' => 'primary'],
                ['label' => 'My Pickups', 'href' => $safeRoute('pickups.assigned'), 'tone' => 'secondary'],
                ['label' => 'Task Board', 'href' => $deliveriesIndexUrl, 'tone' => 'secondary'],
            ]
            : ($isWarehouseDashboardRole
                ? [
                    ['label' => 'Return Verification', 'href' => $safeRoute('assets.pending-verification'), 'tone' => 'primary'],
                    ['label' => 'Product Master', 'href' => $productsIndexUrl, 'tone' => 'secondary'],
                    ['label' => 'Asset Register', 'href' => $safeRoute('assets.index'), 'tone' => 'secondary'],
                ]
                : (($dashboardVisibility['finance_focused'] ?? false)
                    ? [
                        ['label' => 'Record Payment', 'href' => $recordPaymentUrl, 'tone' => 'primary'],
                        ['label' => 'Pending Invoices', 'href' => $invoiceIndexUrl ? $mergeDashboardQuery('invoices.index', ['status' => 'open']) : null, 'tone' => 'secondary'],
                        ['label' => 'Payment Follow-ups', 'href' => $communicationCenterUrl ? route('communication-center.index', ['tab' => 'payments']) : null, 'tone' => 'secondary'],
                    ]
                    : ($canViewFinance
                    ? [
                        ['label' => 'New Rental', 'href' => $newRentalUrl, 'tone' => 'primary'],
                        ['label' => 'New Sale', 'href' => $newSaleUrl, 'tone' => 'secondary'],
                        ['label' => 'Add Customer', 'href' => $newCustomerUrl, 'tone' => 'secondary'],
                        ['label' => 'Add Business Partner', 'href' => $newBusinessPartnerUrl, 'tone' => 'secondary'],
                        ['label' => 'Schedule Pickup', 'href' => $schedulePickupUrl, 'tone' => 'secondary'],
                        ['label' => 'Record Payment', 'href' => $recordPaymentUrl, 'tone' => 'secondary'],
                    ]
                    : [
                        ['label' => 'New Rental', 'href' => $newRentalUrl, 'tone' => 'primary'],
                        ['label' => 'New Sale', 'href' => $newSaleUrl, 'tone' => 'secondary'],
                        ['label' => 'Add Customer', 'href' => $newCustomerUrl, 'tone' => 'secondary'],
                        ['label' => 'Add Follow-up', 'href' => $communicationCenterUrl ? route('communication-center.index', ['tab' => 'today']) : null, 'tone' => 'secondary'],
                    ])))
    )->filter(fn ($action) => !empty($action['href']))->values();
    $operationalAlerts = collect([
        [
            'widget_key' => 'alert_overdue_renewals',
            'label' => 'Renewals overdue',
            'count' => (int) ($overdueRenewalsCount ?? 0),
            'copy' => 'Rental renewals already beyond their promised end date.',
            'href' => $renewalCenterUrl ? route('renewal-center.index', ['tab' => 'overdue']) : null,
            'tone' => 'red',
        ],
        [
            'widget_key' => 'alert_pickups_delayed',
            'label' => 'Pickups delayed',
            'count' => (int) ($pickupCenterOverdueCount ?? 0),
            'copy' => 'Pickup runs slipped past schedule and need field coordination.',
            'href' => $pickupCenterUrl ? route('pickup-center.index', ['tab' => 'overdue']) : null,
            'tone' => 'amber',
        ],
        [
            'widget_key' => 'alert_failed_field_tasks',
            'label' => 'Failed field tasks',
            'count' => (int) ($failedTasksCount ?? 0),
            'copy' => 'Failed pickups or deliveries that need recovery and rescheduling.',
            'href' => $pickupCenterUrl ? route('pickup-center.index', ['tab' => 'failed_attempt']) : ($deliveriesIndexUrl ? route('deliveries.index', ['status' => 'cancelled']) : null),
            'tone' => 'amber',
            'visible' => $canReadDeliveries,
        ],
        [
            'widget_key' => 'alert_unassigned_tasks',
            'label' => 'Unassigned tasks',
            'count' => (int) ($unassignedTasksCount ?? 0),
            'copy' => 'Open tasks without a clear field owner.',
            'href' => $deliveriesIndexUrl ? route('deliveries.index', ['staff' => 'unassigned']) : null,
            'tone' => 'blue',
            'visible' => $canReadDeliveries,
        ],
        [
            'widget_key' => 'alert_high_priority_followups',
            'label' => 'High priority follow-ups',
            'count' => (int) ($highPriorityFollowUpsCount ?? 0),
            'copy' => 'Urgent renewals, payments, and escalation calls waiting now.',
            'href' => $communicationCenterUrl ? route('communication-center.index', ['priority' => 'high']) : null,
            'tone' => 'red',
            'visible' => !$isDeliveryFacingMenuRole,
        ],
        [
            'widget_key' => 'alert_large_unpaid_invoices',
            'label' => 'Large unpaid invoices',
            'count' => (int) ($largeOutstandingInvoiceCount ?? 0),
            'copy' => 'High-value invoices above â‚¹10,000 still awaiting collection.',
            'href' => $communicationCenterUrl ? route('communication-center.index', ['tab' => 'payments']) : $invoiceIndexUrl,
            'tone' => 'amber',
            'visible' => $canViewFinance,
        ],
    ])->filter(fn ($alert) => ($alert['visible'] ?? true) && $dashboardWidgetEnabled($alert['widget_key']) && !empty($alert['href']) && ((int) ($alert['count'] ?? 0)) > 0)->sortBy(fn ($alert) => $dashboardWidgetSort($alert['widget_key']))->values();
    $cities = $cities ?? collect();
    $vendors = $vendors ?? collect();
    $warehouses = $warehouses ?? collect();

    $buildChartPolyline = function (array $values, int $width = 540, int $height = 180, int $padding = 18): string {
        $values = array_values($values);
        $count = count($values);

        if ($count === 0) {
            return '';
        }

        if ($count === 1) {
            $x = $width / 2;
            $y = $height - $padding;

            return round($x, 2) . ',' . round($y, 2);
        }

        $maxValue = max(max($values), 1);
        $usableWidth = max($width - ($padding * 2), 1);
        $usableHeight = max($height - ($padding * 2), 1);

        return collect($values)->map(function ($value, $index) use ($count, $padding, $usableWidth, $usableHeight, $height, $maxValue) {
            $x = $padding + ($usableWidth * ($index / max($count - 1, 1)));
            $y = ($height - $padding) - (($value / $maxValue) * $usableHeight);

            return round($x, 2) . ',' . round($y, 2);
        })->implode(' ');
    };

    $collectionsTrendRows = collect($collectionsTrend ?? [])->values();
    $collectionsTrendValues = $collectionsTrendRows->pluck('amount')->map(fn ($value) => (float) $value)->all();
    $collectionsTrendPoints = $buildChartPolyline($collectionsTrendValues, 560, 170, 18);
    $collectionsTrendMax = max(array_merge([1], $collectionsTrendValues));
    $buildMiniSparkline = function (array $values) use ($buildChartPolyline) {
        return $buildChartPolyline($values, 96, 26, 3);
    };

    $invoiceAgingBuckets = collect(data_get($invoiceAging ?? [], 'buckets', []))->values();
    $topDuesCustomers = collect($topCustomersWithDues ?? collect())->values();
    $salesPulseTopCustomers = $topDuesCustomers->take(5)->values();
    $salesPulseRecentOrders = $recentSalesSummary->take(4)->values();
    $salesPulseCollectionTotal = max($paidSalesAmountValue + $salesOutstandingInvoiceAmountValue + $salesUnbilledAmountValue, 0);
    $salesPulseCollectionSegments = collect([
        ['label' => 'Collected', 'value' => $paidSalesAmountValue, 'tone' => 'green'],
        ['label' => 'Outstanding', 'value' => $salesOutstandingInvoiceAmountValue, 'tone' => 'amber'],
        ['label' => 'Unbilled', 'value' => $salesUnbilledAmountValue, 'tone' => 'red'],
    ])->map(function (array $segment) use ($salesPulseCollectionTotal) {
        $segment['percent'] = $salesPulseCollectionTotal > 0
            ? round(($segment['value'] / $salesPulseCollectionTotal) * 100)
            : 0;

        return $segment;
    })->values();
    $salesPulseCollectionEfficiency = $salesPulseCollectionTotal > 0
        ? round(($paidSalesAmountValue / max($salesPulseCollectionTotal, 1)) * 100)
        : 0;
    $revenueCenterUnbilledTotal = max(
        $unbilledRentalReceivableAmountValue
        + $unbilledSaleReceivableAmountValue
        + (float) ($unbilledRenewalAmount ?? 0),
        0
    );
    $revenueCenterSummaryCards = collect([
        [
            'label' => 'Total Outstanding',
            'value' => $compactCurrency($outstandingDueAmountValue),
            'subtitle' => number_format($openInvoiceCountValue) . ' open invoice(s)',
            'note' => number_format($overdueInvoiceCountValue) . ' overdue invoice(s)',
            'href' => $invoiceIndexUrl ? $mergeDashboardQuery('invoices.index', ['status' => 'open']) : null,
            'action' => 'View invoices',
            'tone' => $overdueInvoiceCountValue > 0 ? 'red' : 'amber',
            'icon' => 'payment',
        ],
        [
            'label' => 'Collected This Month',
            'value' => $compactCurrency($paymentsReceivedThisMonthAmount),
            'subtitle' => $currency($paymentsReceivedTodayAmount) . ' received today',
            'note' => $salesPulseCollectionEfficiency . '% collection efficiency',
            'href' => $reportsIndexUrl ?: $invoiceIndexUrl,
            'action' => 'Open reports',
            'tone' => $paymentsReceivedThisMonthAmount > 0 ? 'green' : 'blue',
            'icon' => 'revenue',
        ],
        [
            'label' => 'Collected Today',
            'value' => $compactCurrency($paymentsReceivedTodayAmount),
            'subtitle' => $paymentsReceivedTodayAmount > 0 ? 'Collections recorded today' : 'No collections recorded today',
            'note' => number_format($recentPaymentsSummary->count()) . ' recent payment item(s)',
            'href' => $reportsIndexUrl ?: $invoiceIndexUrl,
            'action' => 'View receipts',
            'tone' => $paymentsReceivedTodayAmount > 0 ? 'green' : 'blue',
            'icon' => 'payment',
        ],
        [
            'label' => 'Unbilled Value',
            'value' => $compactCurrency($revenueCenterUnbilledTotal),
            'subtitle' => number_format($unbilledRentalReceivableCount + $unbilledSaleReceivableCount + $unbilledRenewalCount) . ' invoice action(s) pending',
            'note' => $currency((float) ($unbilledRenewalAmount ?? 0)) . ' renewal amount not yet invoiced',
            'href' => $salesIndexUrl ?: $rentalIndexUrl,
            'action' => 'Raise invoices',
            'tone' => $revenueCenterUnbilledTotal > 0 ? 'amber' : 'blue',
            'icon' => 'sales',
        ],
    ])->values();
    $revenueMixTotal = max($totalRentalValueAmount + $totalSalesAmountValue + $totalDepositValueAmount + $totalTransportValueAmount, 0);
    $revenueMixRows = collect([
        ['label' => 'Rental Revenue', 'value' => $totalRentalValueAmount, 'display' => $compactCurrency($totalRentalValueAmount), 'tone' => 'blue', 'href' => $rentalIndexUrl],
        ['label' => 'Sales Revenue', 'value' => $totalSalesAmountValue, 'display' => $compactCurrency($totalSalesAmountValue), 'tone' => 'green', 'href' => $salesIndexUrl],
        ['label' => 'Deposits', 'value' => $totalDepositValueAmount, 'display' => $compactCurrency($totalDepositValueAmount), 'tone' => 'amber', 'href' => $rentalIndexUrl],
        ['label' => 'Transport', 'value' => $totalTransportValueAmount, 'display' => $compactCurrency($totalTransportValueAmount), 'tone' => 'violet', 'href' => $rentalIndexUrl],
    ])->map(function (array $row) use ($revenueMixTotal) {
        $row['percent'] = $revenueMixTotal > 0 ? round(($row['value'] / max($revenueMixTotal, 1)) * 100, 1) : 0.0;

        return $row;
    })->values();
    $cashPositionRows = collect([
        ['label' => 'Collected This Month', 'value' => $paymentsReceivedThisMonthAmount, 'display' => $compactCurrency($paymentsReceivedThisMonthAmount), 'tone' => 'green', 'href' => $reportsIndexUrl ?: $invoiceIndexUrl],
        ['label' => 'Outstanding', 'value' => $outstandingDueAmountValue, 'display' => $compactCurrency($outstandingDueAmountValue), 'tone' => $outstandingDueAmountValue > 0 ? 'amber' : 'green', 'href' => $invoiceIndexUrl ? $mergeDashboardQuery('invoices.index', ['status' => 'open']) : null],
        ['label' => 'Collection Efficiency', 'value' => $salesPulseCollectionEfficiency, 'display' => $salesPulseCollectionEfficiency . '%', 'tone' => $salesPulseCollectionEfficiency >= 70 ? 'green' : ($salesPulseCollectionEfficiency > 0 ? 'amber' : 'blue'), 'href' => $reportsIndexUrl ?: $invoiceIndexUrl],
    ])->values();
    $invoiceHealthRows = collect([
        ['label' => 'Unbilled', 'value' => $revenueCenterUnbilledTotal, 'display' => $compactCurrency($revenueCenterUnbilledTotal), 'tone' => $revenueCenterUnbilledTotal > 0 ? 'amber' : 'green', 'href' => $salesIndexUrl ?: $rentalIndexUrl],
        ['label' => 'Unpaid Renewals', 'value' => (float) ($unpaidRenewalAmount ?? 0), 'display' => $compactCurrency((float) ($unpaidRenewalAmount ?? 0)), 'tone' => ((float) ($unpaidRenewalAmount ?? 0)) > 0 ? 'red' : 'green', 'href' => $invoiceIndexUrl ? $mergeDashboardQuery('invoices.index', ['status' => 'open']) : null],
        ['label' => 'Overdue Invoices', 'value' => $overdueInvoiceCountValue, 'display' => number_format($overdueInvoiceCountValue), 'tone' => $overdueInvoiceCountValue > 0 ? 'red' : 'green', 'href' => $invoiceIndexUrl ? $mergeDashboardQuery('invoices.index', ['status' => 'open']) : null],
    ])->values();
    $vendorPerformance = collect($vendorPerformanceSummary ?? []);
    $vendorPerformanceRows = collect([
        ['label' => 'Vendor Revenue', 'display' => $compactCurrency((float) $vendorPerformance->get('vendor_revenue', 0)), 'tone' => 'blue'],
        ['label' => 'Vendor Cost', 'display' => $compactCurrency((float) $vendorPerformance->get('vendor_cost', 0)), 'tone' => 'amber'],
        ['label' => 'Vendor Margin', 'display' => $compactCurrency((float) $vendorPerformance->get('vendor_margin', 0)), 'tone' => ((float) $vendorPerformance->get('vendor_margin', 0)) < 0 ? 'red' : 'green'],
        ['label' => 'Vendor Orders', 'display' => number_format((int) $vendorPerformance->get('vendor_orders_count', 0)), 'tone' => 'blue'],
    ])->values();
    $revenueCenterMonthlyCollections = $collectionsTrendRows
        ->groupBy(function (array $row) {
            try {
                return \Illuminate\Support\Carbon::parse((string) ($row['date'] ?? now()->toDateString()))->format('Y-m');
            } catch (\Throwable $e) {
                return null;
            }
        })
        ->map(fn ($rows) => round((float) collect($rows)->sum('amount'), 2))
        ->filter(fn ($value, $key) => filled($key));
    $revenueTrendRows = collect($monthlyTrendRows ?? [])->map(function (array $row) use ($revenueCenterMonthlyCollections) {
        $periodKey = null;
        try {
            $periodKey = \Illuminate\Support\Carbon::createFromFormat('M Y', (string) ($row['label'] ?? ''))->format('Y-m');
        } catch (\Throwable $e) {
            $periodKey = null;
        }

        $collected = $periodKey ? (float) ($revenueCenterMonthlyCollections->get($periodKey) ?? 0.0) : 0.0;
        $sales = (float) ($row['sales_total'] ?? 0.0);
        $outstanding = max($sales - $collected, 0.0);

        return [
            'label' => (string) ($row['label'] ?? ''),
            'sales_total' => $sales,
            'collected_total' => $collected,
            'outstanding_total' => $outstanding,
        ];
    })->values();
    $revenueTrendMax = max(array_merge([1], $revenueTrendRows->flatMap(fn ($row) => [
        (float) ($row['sales_total'] ?? 0),
        (float) ($row['collected_total'] ?? 0),
        (float) ($row['outstanding_total'] ?? 0),
    ])->all()));
    $revenueTrendChartWidth = 560;
    $revenueTrendChartHeight = 172;
    $revenueTrendSalesPoints = $buildChartPolyline($revenueTrendRows->pluck('sales_total')->map(fn ($value) => (float) $value)->all(), $revenueTrendChartWidth, $revenueTrendChartHeight, 22);
    $revenueTrendCollectionsPoints = $buildChartPolyline($revenueTrendRows->pluck('collected_total')->map(fn ($value) => (float) $value)->all(), $revenueTrendChartWidth, $revenueTrendChartHeight, 22);
    $revenueTrendHasData = $revenueTrendRows->contains(fn ($row) => ((float) ($row['sales_total'] ?? 0)) > 0 || ((float) ($row['collected_total'] ?? 0)) > 0 || ((float) ($row['outstanding_total'] ?? 0)) > 0);
    $revenueCenterActionRows = collect();
    foreach ($pendingPaymentSummary->take(6) as $invoice) {
        $balance = (float) (($invoice->total_amount ?? 0) - ($invoice->payments_sum_amount ?? 0));
        $dueDate = data_get($invoice, 'due_date');
        $daysOverdue = $dueDate ? \Illuminate\Support\Carbon::parse($dueDate)->startOfDay()->diffInDays(now()->startOfDay(), false) : 0;
        $revenueCenterActionRows->push([
            'label' => (optional($invoice->customer)->name ?? 'Customer') . ' / ' . ($invoice->invoice_number ?? 'Invoice'),
            'amount' => $balance,
            'age' => $daysOverdue > 0 ? $daysOverdue . ' day(s)' : 'Current',
            'type' => 'Invoice',
            'href' => route('invoices.show', $invoice),
            'action' => 'Record Payment',
            'tone' => $daysOverdue > 30 ? 'red' : ($daysOverdue > 7 ? 'amber' : 'blue'),
        ]);
    }
    if ($unpaidRenewalCount > 0) {
        $revenueCenterActionRows->push([
            'label' => 'Renewal invoices awaiting payment',
            'amount' => (float) ($unpaidRenewalAmount ?? 0),
            'age' => number_format($unpaidRenewalCount) . ' invoice(s)',
            'type' => 'Renewal',
            'href' => $mergeDashboardQuery('invoices.index', ['status' => 'open']),
            'action' => 'View Renewals',
            'tone' => 'red',
        ]);
    }
    if ($unbilledRentalReceivableCount > 0) {
        $revenueCenterActionRows->push([
            'label' => 'Delivered rentals awaiting invoice',
            'amount' => $unbilledRentalReceivableAmountValue,
            'age' => number_format($unbilledRentalReceivableCount) . ' rental(s)',
            'type' => 'Rental',
            'href' => $rentalIndexUrl ?: $dashboardUrl,
            'action' => 'Raise Invoice',
            'tone' => 'amber',
        ]);
    }
    if ($unbilledSaleReceivableCount > 0) {
        $revenueCenterActionRows->push([
            'label' => 'Sales awaiting invoice',
            'amount' => $unbilledSaleReceivableAmountValue,
            'age' => number_format($unbilledSaleReceivableCount) . ' sale(s)',
            'type' => 'Sales',
            'href' => $salesIndexUrl ?: $dashboardUrl,
            'action' => 'Raise Invoice',
            'tone' => 'amber',
        ]);
    }
    $revenueCenterActionRows = $revenueCenterActionRows->sortByDesc('amount')->take(10)->values();
    $recentRevenueActivity = collect()
        ->merge($recentPaymentsSummary->map(fn ($payment) => [
            'title' => 'Payment received · ' . (optional($payment->customer)->name ?? 'Customer'),
            'meta' => optional($payment->invoice)->invoice_number ?? 'Payment entry',
            'amount' => $currency($payment->amount ?? 0),
            'timestamp' => optional($payment->payment_date)?->format('d M Y') ?? 'Recent',
            'badge' => 'Payment',
            'href' => $invoiceIndexUrl ? route('invoices.index', ['search' => $payment->invoice->invoice_number ?? null]) : $dashboardUrl,
        ]))
        ->merge($salesPulseRecentOrders->map(fn ($sale) => [
            'title' => 'Sales order · SALE-' . $sale->id,
            'meta' => optional($sale->customer)->name ?? 'Customer',
            'amount' => $currency($sale->sale_amount ?? 0),
            'timestamp' => optional($sale->sale_date)?->format('d M Y') ?? 'Recent',
            'badge' => 'Sales',
            'href' => $salesIndexUrl ?: $dashboardUrl,
        ]))
        ->take(8)
        ->values();
    $inventoryAvailabilitySummary = collect($inventoryAvailability ?? []);
    $inventoryAvailabilityTotal = max((int) ($inventoryAvailabilitySummary->get('total_assets') ?? 0), 0);
    $inventoryAvailabilitySegments = collect([
        ['label' => 'Available', 'value' => (int) ($inventoryAvailabilitySummary->get('available') ?? 0), 'tone' => 'green'],
        ['label' => 'On Rent', 'value' => (int) ($inventoryAvailabilitySummary->get('on_rent') ?? 0), 'tone' => 'blue'],
        ['label' => 'Maintenance', 'value' => (int) ($inventoryAvailabilitySummary->get('maintenance') ?? 0), 'tone' => 'amber'],
        ['label' => 'Blocked / Reserved', 'value' => (int) ($inventoryAvailabilitySummary->get('blocked_reserved') ?? 0), 'tone' => 'red'],
    ])->map(function (array $segment) use ($inventoryAvailabilityTotal) {
        $segment['percent'] = $inventoryAvailabilityTotal > 0
            ? round(($segment['value'] / $inventoryAvailabilityTotal) * 100, 1)
            : 0.0;

        return $segment;
    })->values();
    $inventoryAngles = $inventoryAvailabilitySegments->map(fn ($segment) => ((float) ($segment['percent'] ?? 0) / 100) * 360)->values();
    $availableInventoryCount = (int) ($inventoryAvailabilitySummary->get('available') ?? $availableRentalAssetsCount ?? 0);
    $onRentInventoryCount = (int) ($inventoryAvailabilitySummary->get('on_rent') ?? 0);
    $maintenanceInventoryCount = (int) ($inventoryAvailabilitySummary->get('maintenance') ?? $maintenanceAlertCountValue ?? 0);
    $blockedInventoryCount = (int) ($inventoryAvailabilitySummary->get('blocked_reserved') ?? 0);
    $outOfStockProductsCount = (int) $lowStockSummary->filter(fn ($product) => (int) ($product->available_quantity ?? 0) <= 0)->count();
    $awaitingReturnAssetsCount = (int) ($awaitingReturnVerificationCount ?? 0);
    $inventoryHealthCards = collect([
        [
            'label' => 'Available Assets',
            'value' => number_format(max($availableInventoryCount, 0)),
            'status' => $availableInventoryCount > 0 ? 'Ready for fulfilment' : 'No ready rental stock',
            'note' => number_format(max($availableRentalAssetsCount, 0)) . ' rental assets dispatch-ready',
            'href' => $availableRentalAssetsUrl ?? $inventoryUrl,
            'action' => 'View Available',
            'icon' => 'asset',
            'tone' => $availableInventoryCount > 0 ? 'green' : 'amber',
        ],
        [
            'label' => 'On Rent',
            'value' => number_format(max($onRentInventoryCount, 0)),
            'status' => $onRentInventoryCount > 0 ? 'Assets deployed in field' : 'No active deployed stock',
            'note' => number_format($activeRentalsCount) . ' active rental order(s)',
            'href' => $mergeDashboardQuery('rentals.index', ['status' => 'live']),
            'action' => 'Open Rentals',
            'icon' => 'rental',
            'tone' => 'blue',
        ],
        [
            'label' => 'Maintenance',
            'value' => number_format(max($maintenanceInventoryCount, 0)),
            'status' => $maintenanceInventoryCount > 0 ? 'Needs asset attention' : 'No maintenance queue',
            'note' => number_format($maintenanceAlertCountValue) . ' maintenance alert(s)',
            'href' => $inventoryUrl ?? $safeRoute('assets.index'),
            'action' => 'Open Alerts',
            'icon' => 'low-stock',
            'tone' => $maintenanceInventoryCount > 0 ? 'amber' : 'green',
        ],
        [
            'label' => 'Reserved / Blocked',
            'value' => number_format(max($blockedInventoryCount, 0)),
            'status' => $blockedInventoryCount > 0 ? 'Unavailable for fulfilment' : 'No blocked stock',
            'note' => number_format($awaitingReturnAssetsCount) . ' awaiting return verification',
            'href' => $inventoryUrl ?? $safeRoute('assets.pending-verification'),
            'action' => 'Review Holds',
            'icon' => 'warehouse',
            'tone' => $blockedInventoryCount > 0 ? 'red' : 'blue',
        ],
    ])->filter(fn ($card) => !empty($card['href']))->values();

    $fulfilmentReadinessRows = collect([
        ['label' => 'Ready to Fulfil Today', 'value' => number_format(max($availableRentalAssetsCount, 0)), 'note' => $availableRentalAssetsCount > 0 ? 'Rental assets ready now' : 'Awaiting stock movement', 'tone' => $availableRentalAssetsCount > 0 ? 'green' : 'amber'],
        ['label' => 'Low Stock Products', 'value' => number_format((int) $lowStockSummary->count()), 'note' => $lowStockSummary->isNotEmpty() ? 'Replenishment attention needed' : 'No immediate low stock pressure', 'tone' => $lowStockSummary->isNotEmpty() ? 'amber' : 'green'],
        ['label' => 'Out of Stock Products', 'value' => number_format($outOfStockProductsCount), 'note' => $outOfStockProductsCount > 0 ? 'Cannot fulfil some product demand' : 'No product is fully unavailable', 'tone' => $outOfStockProductsCount > 0 ? 'red' : 'green'],
        ['label' => 'Awaiting Return Assets', 'value' => number_format($awaitingReturnAssetsCount), 'note' => $awaitingReturnAssetsCount > 0 ? 'Needs return verification' : 'No assets stuck in return verification', 'tone' => $awaitingReturnAssetsCount > 0 ? 'amber' : 'blue'],
    ])->values();

    $productRiskRows = collect();
    foreach ($lowStockSummary as $product) {
        $productRiskRows->push([
            'name' => (string) ($product->name ?? 'Product'),
            'available' => (int) ($product->available_quantity ?? 0),
            'required' => max(0, (int) ($product->total_quantity ?? 0) - (int) ($product->available_quantity ?? 0)),
            'risk' => ((int) ($product->available_quantity ?? 0) <= 0) ? 'Out of Stock' : 'Low Stock',
            'tone' => ((int) ($product->available_quantity ?? 0) <= 0) ? 'red' : 'amber',
            'href' => $productsIndexUrl ?? $inventoryUrl,
            'action' => 'Restock',
        ]);
    }
    foreach ($highUtilizationSummary as $product) {
        $productRiskRows->push([
            'name' => (string) ($product->name ?? 'Product'),
            'available' => (int) ($product->available_quantity ?? 0),
            'required' => max(0, (int) ($product->total_quantity ?? 0) - (int) ($product->available_quantity ?? 0)),
            'risk' => 'High Utilization',
            'tone' => 'amber',
            'href' => $productsIndexUrl ?? $inventoryUrl,
            'action' => 'Review',
        ]);
    }
    foreach ($idleInventorySummary as $product) {
        $productRiskRows->push([
            'name' => (string) ($product->name ?? 'Product'),
            'available' => (int) ($product->available_quantity ?? 0),
            'required' => 0,
            'risk' => 'Idle Inventory',
            'tone' => 'blue',
            'href' => $productsIndexUrl ?? $inventoryUrl,
            'action' => 'Move',
        ]);
    }
    $productRiskRows = $productRiskRows
        ->unique(fn ($row) => \Illuminate\Support\Str::lower(($row['name'] ?? '') . '|' . ($row['risk'] ?? '')))
        ->take(10)
        ->values();

    $warehouseSnapshotRows = $warehouseSummaryRows->take(4)->map(function ($row) use ($currency, $mergeDashboardQuery) {
        return [
            'label' => (string) ($row['label'] ?? 'Unknown Warehouse'),
            'count' => (int) ($row['count'] ?? 0),
            'amount' => $currency((float) ($row['total_amount'] ?? 0)),
            'href' => $mergeDashboardQuery('rentals.index', ['dispatch_warehouse_id' => $row['warehouse_id'] ?? null]),
        ];
    })->values();

    $inventoryMovementRows = $recentActivitiesSummary
        ->filter(function ($activity) {
            $haystack = \Illuminate\Support\Str::lower(trim((string) (($activity->action ?? '') . ' ' . ($activity->description ?? ''))));
            return \Illuminate\Support\Str::contains($haystack, ['inventory', 'stock', 'asset', 'warehouse', 'product', 'verification']);
        })
        ->take(5)
        ->map(function ($activity) use ($inventoryUrl) {
            return [
                'title' => \Illuminate\Support\Str::headline(str_replace('.', ' ', (string) $activity->action)),
                'meta' => $activity->description ?: 'Inventory event recorded.',
                'time' => optional($activity->created_at)?->diffForHumans() ?? 'Recently',
                'href' => $inventoryUrl,
            ];
        })
        ->values();

    $controlRoomCards = collect([
        [
            'label' => 'Cash at Risk',
            'value' => $compactCurrency(max($pendingReceivableAmountValue, $outstandingDueAmountValue)),
            'status' => $canViewFinance
                ? ($pendingReceivableOverdueCount > 0 ? number_format($pendingReceivableOverdueCount) . ' overdue invoice(s)' : 'No major overdue spike')
                : 'Finance access required',
            'note' => $canViewFinance
                ? number_format($openInvoiceCountValue) . ' open invoices · ' . $currency($outstandingDueAmountValue) . ' outstanding'
                : 'Visible to finance-enabled roles',
            'href' => $canViewFinance ? $mergeDashboardQuery('invoices.index', ['status' => 'open']) : null,
            'action' => 'View Dues',
            'icon' => 'payment',
            'tone' => $pendingReceivableOverdueCount > 0 ? 'red' : 'amber',
            'meter' => $canViewFinance ? min(100, round(($outstandingDueAmountValue / max($paymentsReceivedThisMonthAmount + $outstandingDueAmountValue, 1)) * 100)) : 0,
            'sparkline' => [
                round($paymentsReceivedTodayAmount / 1000, 2),
                round($outstandingDueAmountValue / 100000, 2),
                round($pendingReceivableAmountValue / 100000, 2),
                round(max($pendingReceivableAmountValue, $outstandingDueAmountValue) / 100000, 2),
            ],
        ],
        [
            'label' => 'Follow-ups Overdue',
            'value' => number_format((int) ($overdueFollowUpsCount ?? 0)),
            'status' => $pendingPaymentFollowUpsCount > 0
                ? number_format((int) $pendingPaymentFollowUpsCount) . ' payment follow-up(s) pending'
                : 'Callbacks under control',
            'note' => number_format((int) ($followUpsDueTodayCount ?? 0)) . ' due today · ' . number_format((int) ($highPriorityFollowUpsCount ?? 0)) . ' high priority',
            'href' => $communicationCenterUrl ? route('communication-center.index', ['tab' => 'overdue']) : null,
            'action' => 'Take Action',
            'icon' => 'tasks',
            'tone' => ((int) ($overdueFollowUpsCount ?? 0)) > 0 ? 'red' : 'blue',
            'meter' => min(100, round((((int) ($overdueFollowUpsCount ?? 0)) / max(((int) ($followUpsDueTodayCount ?? 0)) + ((int) ($highPriorityFollowUpsCount ?? 0)) + ((int) ($overdueFollowUpsCount ?? 0)), 1)) * 100)),
            'sparkline' => [
                (int) ($followUpsDueTodayCount ?? 0),
                (int) ($pendingPaymentFollowUpsCount ?? 0),
                (int) ($highPriorityFollowUpsCount ?? 0),
                (int) ($overdueFollowUpsCount ?? 0),
            ],
        ],
        [
            'label' => 'Renewals Overdue',
            'value' => number_format((int) ($overdueRenewalsCount ?? 0)),
            'status' => ((int) ($unpaidRenewalCount ?? 0)) > 0
                ? number_format((int) ($unpaidRenewalCount ?? 0)) . ' renewal invoice(s) still unpaid'
                : 'Renewal queue under watch',
            'note' => number_format((int) ($renewalsDueTodayCount ?? 0)) . ' due today · ' . $currency((float) ($unpaidRenewalAmount ?? 0)) . ' pending',
            'href' => $renewalCenterUrl ? route('renewal-center.index', ['tab' => 'overdue']) : null,
            'action' => 'View Renewals',
            'icon' => 'rental',
            'tone' => ((int) ($overdueRenewalsCount ?? 0)) > 0 ? 'red' : 'blue',
            'meter' => min(100, round((((int) ($overdueRenewalsCount ?? 0)) / max(((int) ($renewalsDueTodayCount ?? 0)) + ((int) ($overdueRenewalsCount ?? 0)) + ((int) ($unpaidRenewalCount ?? 0)), 1)) * 100)),
            'sparkline' => [
                (int) ($renewalsDueTodayCount ?? 0),
                (int) ($unpaidRenewalCount ?? 0),
                (int) ($overdueRenewalsCount ?? 0),
                (int) ($endingSoonCount ?? 0),
            ],
        ],
        [
            'label' => 'Staff Overloaded',
            'value' => number_format((int) ($staffOverloadedCount ?? 0)),
            'status' => ((int) ($staffBusyCount ?? 0)) > 0
                ? number_format((int) ($staffBusyCount ?? 0)) . ' additional teammate(s) running busy'
                : 'Workload looks balanced',
            'note' => number_format((int) ($unassignedTasksCount ?? 0)) . ' unassigned task(s) · ' . number_format((int) ($failedTasksCount ?? 0)) . ' failed field task(s)',
            'href' => '#staff-workload-overview',
            'action' => 'Manage Workload',
            'icon' => 'customer',
            'tone' => ((int) ($staffOverloadedCount ?? 0)) > 0 ? 'amber' : 'blue',
            'meter' => min(100, round((((int) ($staffOverloadedCount ?? 0)) / max(((int) ($staffOverloadedCount ?? 0)) + ((int) ($staffBusyCount ?? 0)) + 1, 1)) * 100)),
            'sparkline' => [
                (int) ($assignedOpenTasksCount ?? 0),
                (int) ($unassignedTasksCount ?? 0),
                (int) ($staffBusyCount ?? 0),
                (int) ($staffOverloadedCount ?? 0),
            ],
        ],
    ])->values();

    $pipelineStages = collect([
        ['label' => 'Created', 'value' => $totalRentalsValue, 'tone' => 'blue'],
        ['label' => 'Assigned', 'value' => $scheduledDeliveryCountValue + $scheduledPickupCountValue, 'tone' => 'amber'],
        ['label' => 'Out for Delivery', 'value' => $outForDeliveryCountValue, 'tone' => 'blue'],
        ['label' => 'Active Rental', 'value' => $activeRentalsCount, 'tone' => 'green'],
        ['label' => 'Return Due', 'value' => $returnsDueTodayCountValue + $overdueReturnsCount, 'tone' => 'amber'],
        ['label' => 'Completed', 'value' => $returnedRentalsCount, 'tone' => 'green'],
    ])->values();

    $rentalPipelineSummary = collect([
        ['label' => 'Active Rentals', 'value' => number_format($activeRentalsCount), 'note' => $endingSoonCount > 0 ? number_format($endingSoonCount) . ' ending soon' : 'No urgent action'],
        ['label' => 'New Rentals Today', 'value' => number_format((int) data_get(collect($dateSummary ?? collect())->firstWhere('rental_date', now()->toDateString()), 'aggregate', 0)), 'note' => 'Orders created today'],
        ['label' => 'Return Due (Next 7 Days)', 'value' => number_format((int) (($returnsDueTodayCountValue ?? 0) + ($endingSoonCount ?? 0))), 'note' => number_format($overdueReturnsCount) . ' overdue rental(s)'],
        ['label' => 'Delivered Base', 'value' => number_format($deliveredRentalsCount), 'note' => $returnedPercent . '% already closed'],
    ])->values();

    $riskBoardRows = collect([
        ['risk' => 'Renewals overdue', 'count' => (int) ($overdueRenewalsCount ?? 0), 'severity' => ((int) ($overdueRenewalsCount ?? 0)) > 0 ? 'High' : 'Normal', 'owner' => 'Rental Team', 'href' => $renewalCenterUrl ? route('renewal-center.index', ['tab' => 'overdue']) : null, 'action' => 'View'],
        ['risk' => 'Payment follow-ups', 'count' => (int) ($pendingPaymentFollowUpsCount ?? 0), 'severity' => ((int) ($pendingPaymentFollowUpsCount ?? 0)) > 10 ? 'High' : (((int) ($pendingPaymentFollowUpsCount ?? 0)) > 0 ? 'Medium' : 'Normal'), 'owner' => 'Finance', 'href' => $communicationCenterUrl ? route('communication-center.index', ['tab' => 'payments']) : null, 'action' => 'Call'],
        ['risk' => 'Pickup delayed', 'count' => (int) ($pickupCenterOverdueCount ?? 0), 'severity' => ((int) ($pickupCenterOverdueCount ?? 0)) > 0 ? 'Medium' : 'Normal', 'owner' => 'Dispatch', 'href' => $pickupCenterUrl ? route('pickup-center.index', ['tab' => 'overdue']) : null, 'action' => 'Assign'],
        ['risk' => 'Unassigned tasks', 'count' => (int) ($unassignedTasksCount ?? 0), 'severity' => ((int) ($unassignedTasksCount ?? 0)) > 0 ? 'Medium' : 'Normal', 'owner' => 'Admin', 'href' => $deliveriesIndexUrl ? route('deliveries.index', ['staff' => 'unassigned']) : null, 'action' => 'Assign'],
        ['risk' => 'Large unpaid invoices', 'count' => (int) ($largeOutstandingInvoiceCount ?? 0), 'severity' => ((int) ($largeOutstandingInvoiceCount ?? 0)) > 0 ? 'High' : 'Normal', 'owner' => 'Finance', 'href' => $communicationCenterUrl ? route('communication-center.index', ['tab' => 'payments']) : $invoiceIndexUrl, 'action' => 'View'],
    ])->filter(fn ($row) => !empty($row['href']))->values();

    $staffWorkloadBoard = $staffWorkloadSummary->map(function (array $row) {
        $status = match ($row['load_state'] ?? null) {
            'Overloaded' => 'Overloaded',
            'Balanced' => 'Busy',
            default => 'Normal',
        };

        return [
            'name' => $row['name'],
            'deliveries' => (int) ($row['delivery_count'] ?? 0),
            'pickups' => (int) ($row['pickup_count'] ?? 0),
            'followups' => (int) ($row['followup_count'] ?? 0),
            'tasks' => (int) ($row['total'] ?? 0),
            'status' => $status,
        ];
    })->values();

    $recentActivityFeeds = [
        'all' => $recentActivitiesSummary->map(fn ($activity) => [
            'title' => \Illuminate\Support\Str::headline(str_replace('.', ' ', (string) $activity->action)),
            'meta' => $activity->description ?: 'Activity recorded in the operational timeline.',
            'time' => optional($activity->created_at)?->diffForHumans() ?? 'Recently',
            'tone' => 'blue',
            'href' => $dashboardUrl,
        ])->values()->all(),
        'rentals' => $recentRentalsSummary->map(fn ($rental) => [
            'title' => 'Rental #' . $rental->id . ' · ' . ($rental->customer_name ?? optional($rental->customer)->name ?? 'Customer'),
            'meta' => (optional($rental->product)->name ?? 'Product') . ' · ' . $currency((float) ($rental->rental_amount ?? 0)),
            'time' => optional($rental->created_at)?->diffForHumans() ?? 'Recently',
            'tone' => 'violet',
            'href' => route('rentals.show', $rental),
        ])->values()->all(),
        'payments' => $recentPaymentsSummary->map(fn ($payment) => [
            'title' => 'Payment received from ' . ($payment->customer->name ?? 'Customer'),
            'meta' => ($payment->invoice->invoice_number ?? 'Invoice') . ' · ' . $currency((float) ($payment->amount ?? 0)),
            'time' => optional($payment->payment_date)?->format('d M Y') ?? 'Recently',
            'tone' => 'green',
            'href' => $invoiceIndexUrl ? route('invoices.index', ['search' => $payment->invoice->invoice_number ?? null]) : $dashboardUrl,
        ])->values()->all(),
        'tasks' => $recentDeliveriesSummary->map(fn ($task) => [
            'title' => ucfirst((string) $task->type) . ' #' . $task->id . ' · ' . ($task->linkedCustomerName() ?: 'Customer'),
            'meta' => optional($task->scheduled_at)?->format('d M, h:i A') ?? 'Schedule pending',
            'time' => optional($task->updated_at)?->diffForHumans() ?? 'Recently',
            'tone' => 'amber',
            'href' => route('deliveries.show', $task),
        ])->values()->all(),
        'alerts' => $highPriorityFollowUpSummary->map(fn ($followUp) => [
            'title' => $followUp->title ?: 'High priority follow-up',
            'meta' => $followUp->note ?: ($followUp->customer?->name ?? $followUp->businessPartner?->displayName() ?? 'Customer coordination'),
            'time' => optional($followUp->due_at)?->diffForHumans() ?? 'Today',
            'tone' => 'red',
            'href' => $communicationCenterUrl ? route('communication-center.index', ['priority' => 'high']) : $dashboardUrl,
        ])->values()->all(),
    ];

    $controlRoomReferenceCards = collect([
        ['label' => 'Total Customers', 'value' => number_format((int) ($totalCustomers ?? 0)), 'href' => $customersIndexUrl],
        ['label' => 'Products', 'value' => number_format((int) ($totalProductsCount ?? 0)), 'href' => $productsIndexUrl],
        ['label' => 'Vendors', 'value' => number_format((int) ($totalBusinessPartners ?? 0)), 'href' => $safeRoute('business-partners.index')],
        ['label' => 'Overdue Rentals', 'value' => number_format($overdueReturnsCount), 'href' => $mergeDashboardQuery('rentals.index', ['filter' => 'overdue', 'status' => null])],
    ])->filter(fn ($card) => !empty($card['href']))->values();

    $pendingOperationsTotal = (int) (
        $pendingDeliveryCountValue
        + $pendingPickupCountValue
        + ((int) ($overdueRenewalsCount ?? 0))
        + ((int) ($unassignedTasksCount ?? 0))
    );
    $inventoryHealthTone = $maintenanceAlertCountValue > 0
        ? 'amber'
        : ($availableRentalAssetsCount > 0 ? 'green' : 'blue');
    $inventoryHealthLabel = $maintenanceAlertCountValue > 0
        ? number_format($maintenanceAlertCountValue) . ' maintenance alert(s)'
        : ($availableRentalAssetsCount > 0 ? 'Assets ready for dispatch' : 'Awaiting rental-stock movement');
    $inventoryHealthNote = $availableRentalAssetsCount > 0
        ? number_format($availableRentalAssetsCount) . ' rental assets available'
        : 'No rental assets available in current filter';

    $executiveCommandCards = collect([
        [
            'label' => 'Cash at Risk',
            'value' => $compactCurrency(max($outstandingDueAmountValue, $pendingReceivableAmountValue)),
            'status' => $canViewFinance
                ? ($overdueInvoiceCountValue > 0 ? number_format($overdueInvoiceCountValue) . ' overdue invoice(s)' : 'No overdue pressure')
                : 'Finance access required',
            'note' => $canViewFinance
                ? $currency($paymentsReceivedThisMonthAmount) . ' collected this month'
                : 'Visible to finance-enabled roles',
            'href' => $canViewFinance ? $mergeDashboardQuery('invoices.index', ['status' => 'open']) : null,
            'action' => 'View Dues',
            'icon' => 'payment',
            'tone' => $overdueInvoiceCountValue > 0 ? 'red' : 'amber',
            'meter' => $canViewFinance ? min(100, round(($outstandingDueAmountValue / max($outstandingDueAmountValue + $paymentsReceivedThisMonthAmount, 1)) * 100)) : 0,
        ],
        [
            'label' => 'Active Rentals',
            'value' => number_format($activeRentalsCount),
            'status' => $endingSoonCount > 0 ? number_format($endingSoonCount) . ' ending soon' : 'No urgent action',
            'note' => number_format($returnsDueTodayCountValue) . ' return(s) due today',
            'href' => $mergeDashboardQuery('rentals.index', ['status' => 'live']),
            'action' => 'Open Rentals',
            'icon' => 'rental',
            'tone' => $overdueReturnsCount > 0 ? 'amber' : 'blue',
            'meter' => min(100, round(($activeRentalsCount / max($totalRentalsValue, 1)) * 100)),
        ],
        [
            'label' => 'Pending Operations',
            'value' => number_format($pendingOperationsTotal),
            'status' => number_format($pendingDeliveryCountValue) . ' delivery · ' . number_format($pendingPickupCountValue) . ' pickup',
            'note' => number_format((int) ($overdueRenewalsCount ?? 0)) . ' renewal(s) overdue · ' . number_format((int) ($unassignedTasksCount ?? 0)) . ' unassigned task(s)',
            'href' => $deliveriesIndexUrl ? route('deliveries.index', ['board' => 'delivery_workload']) : ($renewalCenterUrl ?? null),
            'action' => 'View Queue',
            'icon' => 'tasks',
            'tone' => $pendingOperationsTotal > 0 ? 'amber' : 'blue',
            'meter' => min(100, round(($pendingOperationsTotal / max($totalTasksCountValue + $activeRentalsCount + 1, 1)) * 100)),
        ],
        [
            'label' => 'Inventory Health',
            'value' => number_format(max($availableRentalAssetsCount, 0)),
            'status' => $inventoryHealthLabel,
            'note' => $inventoryHealthNote,
            'href' => $inventoryUrl ?? $availableRentalAssetsUrl,
            'action' => 'View Inventory',
            'icon' => 'asset',
            'tone' => $inventoryHealthTone,
            'meter' => min(100, round(($availableRentalAssetsCount / max($inventoryAvailabilityTotal, 1)) * 100)),
        ],
    ])->values();

    $topPriorityQueueRows = collect([
        $overdueInvoiceCountValue > 0 && $canViewFinance ? [
            'priority' => 'High',
            'title' => 'Overdue invoice collections',
            'owner' => 'Finance',
            'status' => number_format($overdueInvoiceCountValue) . ' overdue invoice(s)',
            'href' => $mergeDashboardQuery('invoices.index', ['status' => 'open']),
            'action' => 'View',
            'tone' => 'red',
        ] : null,
        ((int) ($overdueRenewalsCount ?? 0)) > 0 ? [
            'priority' => 'High',
            'title' => 'Renewals past promised return date',
            'owner' => 'Renewal Center',
            'status' => number_format((int) ($overdueRenewalsCount ?? 0)) . ' overdue renewal(s)',
            'href' => $renewalCenterUrl ? route('renewal-center.index', ['tab' => 'overdue']) : null,
            'action' => 'Open',
            'tone' => 'red',
        ] : null,
        ((int) ($pickupCenterOverdueCount ?? 0)) > 0 ? [
            'priority' => 'Medium',
            'title' => 'Delayed pickup coordination',
            'owner' => 'Pickup Center',
            'status' => number_format((int) ($pickupCenterOverdueCount ?? 0)) . ' pickup(s) overdue',
            'href' => $pickupCenterUrl ? route('pickup-center.index', ['tab' => 'overdue']) : null,
            'action' => 'Assign',
            'tone' => 'amber',
        ] : null,
        ((int) ($unassignedTasksCount ?? 0)) > 0 ? [
            'priority' => 'Medium',
            'title' => 'Unassigned field tasks',
            'owner' => 'Dispatch',
            'status' => number_format((int) ($unassignedTasksCount ?? 0)) . ' task(s) without owner',
            'href' => $deliveriesIndexUrl ? route('deliveries.index', ['staff' => 'unassigned']) : null,
            'action' => 'Assign',
            'tone' => 'amber',
        ] : null,
    ])->filter()->merge(
        $highPriorityFollowUpSummary->map(function ($followUp) use ($communicationCenterUrl) {
            return [
                'priority' => 'High',
                'title' => $followUp->title ?: 'High priority follow-up',
                'owner' => 'Communication',
                'status' => optional($followUp->due_at)?->format('d M, h:i A') ?: 'Due soon',
                'href' => $communicationCenterUrl ? route('communication-center.index', ['priority' => 'high']) : null,
                'action' => 'Call',
                'tone' => 'red',
            ];
        })->filter(fn ($row) => !empty($row['href']))
    )->merge(
        $pendingPaymentSummary->map(function ($invoice) use ($mergeDashboardQuery) {
            return [
                'priority' => 'Medium',
                'title' => $invoice->invoice_number ?: 'Invoice payment follow-up',
                'owner' => 'Finance',
                'status' => optional($invoice->due_date)?->format('d M Y') ?: 'Due date pending',
                'href' => $mergeDashboardQuery('invoices.index', ['status' => 'open']),
                'action' => 'Collect',
                'tone' => 'amber',
            ];
        })
    )->take(10)->values();

    $activityTimestamp = function ($value): int {
        if (!$value) {
            return 0;
        }

        try {
            return $value instanceof \Illuminate\Support\Carbon
                ? $value->timestamp
                : \Illuminate\Support\Carbon::parse($value)->timestamp;
        } catch (\Throwable $exception) {
            return 0;
        }
    };
    $activityRecentItems = collect()
        ->merge($recentPaymentsSummary->map(fn ($payment) => [
            'type' => 'Payment',
            'title' => 'Payment received',
            'meta' => (optional($payment->customer)->name ?? 'Customer') . ' / ' . (optional($payment->invoice)->invoice_number ?? 'Invoice'),
            'value' => $currency((float) ($payment->amount ?? 0)),
            'time' => optional($payment->payment_date)?->format('d M Y') ?? 'Recent',
            'sort' => $activityTimestamp($payment->payment_date ?? $payment->created_at ?? null),
            'tone' => 'green',
            'href' => $invoiceIndexUrl ? route('invoices.index', ['search' => optional($payment->invoice)->invoice_number]) : $dashboardUrl,
        ]))
        ->merge($pendingPaymentSummary->map(fn ($invoice) => [
            'type' => 'Invoice',
            'title' => $invoice->invoice_number ?: 'Invoice follow-up',
            'meta' => optional($invoice->customer)->name ?? 'Customer',
            'value' => $currency((float) (($invoice->total_amount ?? 0) - ($invoice->payments_sum_amount ?? 0))),
            'time' => optional($invoice->due_date)?->format('d M Y') ?? 'Due now',
            'sort' => $activityTimestamp($invoice->updated_at ?? $invoice->due_date ?? null),
            'tone' => 'amber',
            'href' => route('invoices.show', $invoice),
        ]))
        ->merge($recentRentalsSummary->map(fn ($rental) => [
            'type' => 'Rental',
            'title' => 'Rental #' . $rental->id,
            'meta' => $rental->customer_name ?? optional($rental->customer)->name ?? 'Customer',
            'value' => optional($rental->product)->name ?? 'Product',
            'time' => optional($rental->created_at)?->diffForHumans() ?? 'Recent',
            'sort' => $activityTimestamp($rental->created_at ?? null),
            'tone' => 'blue',
            'href' => route('rentals.show', $rental),
        ]))
        ->merge($recentDeliveriesSummary->map(fn ($task) => [
            'type' => \Illuminate\Support\Str::headline((string) $task->type),
            'title' => ucfirst((string) $task->type) . ' #' . $task->id,
            'meta' => $task->linkedCustomerName() ?: 'Customer',
            'value' => optional($task->scheduled_at)?->format('d M, h:i A') ?? 'Schedule pending',
            'time' => optional($task->updated_at)?->diffForHumans() ?? 'Recent',
            'sort' => $activityTimestamp($task->updated_at ?? $task->scheduled_at ?? null),
            'tone' => $task->type === 'pickup' ? 'amber' : 'violet',
            'href' => route('deliveries.show', $task),
        ]))
        ->sortByDesc('sort')
        ->take(8)
        ->values();
    $activityAlertItems = collect([
        [
            'label' => 'Overdue payments',
            'count' => $overdueInvoiceCountValue,
            'status' => $overdueInvoiceCountValue > 0 ? 'Collection escalation' : 'Clear',
            'tone' => $overdueInvoiceCountValue > 0 ? 'red' : 'green',
            'href' => $invoiceIndexUrl ? $mergeDashboardQuery('invoices.index', ['status' => 'open']) : null,
        ],
        [
            'label' => 'Delayed deliveries',
            'count' => $overdueDeliveryCountValue,
            'status' => $overdueDeliveryCountValue > 0 ? 'Dispatch delay' : 'On track',
            'tone' => $overdueDeliveryCountValue > 0 ? 'red' : 'green',
            'href' => $deliveriesIndexUrl ? route('deliveries.index', ['board' => 'delivery_workload']) : null,
        ],
        [
            'label' => 'Overdue renewals',
            'count' => (int) ($overdueRenewalsCount ?? 0),
            'status' => ((int) ($overdueRenewalsCount ?? 0)) > 0 ? 'Return overdue' : 'Clear',
            'tone' => ((int) ($overdueRenewalsCount ?? 0)) > 0 ? 'red' : 'green',
            'href' => $renewalCenterUrl ? route('renewal-center.index', ['tab' => 'overdue']) : null,
        ],
        [
            'label' => 'Customer issues',
            'count' => (int) ($highPriorityFollowUpsCount ?? 0),
            'status' => ((int) ($highPriorityFollowUpsCount ?? 0)) > 0 ? 'Callback priority' : 'No critical callbacks',
            'tone' => ((int) ($highPriorityFollowUpsCount ?? 0)) > 0 ? 'amber' : 'green',
            'href' => $communicationCenterUrl ? route('communication-center.index', ['priority' => 'high']) : null,
        ],
        [
            'label' => 'Vendor delays',
            'count' => (int) ($vendorDelayedCount ?? $vendorDelayCount ?? 0),
            'status' => ((int) ($vendorDelayedCount ?? $vendorDelayCount ?? 0)) > 0 ? 'Vendor follow-up' : 'No vendor delay flagged',
            'tone' => ((int) ($vendorDelayedCount ?? $vendorDelayCount ?? 0)) > 0 ? 'amber' : 'green',
            'href' => $safeRoute('business-partners.index') ?: $dashboardUrl,
        ],
    ])->take(8)->values();
    $communicationQueueRows = collect([
        ['label' => 'Follow-ups pending', 'value' => (int) (($pendingRenewalFollowUpsCount ?? 0) + ($pendingPaymentFollowUpsCount ?? 0) + ($pendingPickupFollowUpsCount ?? 0)), 'note' => number_format((int) ($overdueFollowUpsCount ?? 0)) . ' overdue', 'tone' => ((int) ($overdueFollowUpsCount ?? 0)) > 0 ? 'red' : 'blue', 'href' => $communicationCenterUrl],
        ['label' => 'Reminders due today', 'value' => (int) ($followUpsDueTodayCount ?? 0), 'note' => 'Due before day close', 'tone' => ((int) ($followUpsDueTodayCount ?? 0)) > 0 ? 'amber' : 'green', 'href' => $communicationCenterUrl ? route('communication-center.index', ['tab' => 'today']) : null],
        ['label' => 'Customer callbacks', 'value' => (int) ($highPriorityFollowUpsCount ?? 0), 'note' => 'High priority queue', 'tone' => ((int) ($highPriorityFollowUpsCount ?? 0)) > 0 ? 'red' : 'green', 'href' => $communicationCenterUrl ? route('communication-center.index', ['priority' => 'high']) : null],
        ['label' => 'Communication workload', 'value' => (int) ($todayFollowUpSummary->count() + $highPriorityFollowUpSummary->count()), 'note' => 'Today + critical items', 'tone' => ($todayFollowUpSummary->count() + $highPriorityFollowUpSummary->count()) > 0 ? 'blue' : 'green', 'href' => $communicationCenterUrl],
    ])->values();
    $notificationSummaryRows = collect([
        ['label' => 'Unread', 'value' => (int) ($unreadNotificationsCount ?? $topbarNotificationCount ?? 0), 'tone' => ((int) ($unreadNotificationsCount ?? $topbarNotificationCount ?? 0)) > 0 ? 'blue' : 'green'],
        ['label' => 'Critical', 'value' => (int) ($overdueInvoiceCountValue + $overdueDeliveryCountValue + ($highPriorityFollowUpsCount ?? 0)), 'tone' => ($overdueInvoiceCountValue + $overdueDeliveryCountValue + ((int) ($highPriorityFollowUpsCount ?? 0))) > 0 ? 'red' : 'green'],
        ['label' => 'Assigned', 'value' => (int) ($assignedOpenTasksCount ?? 0), 'tone' => ((int) ($assignedOpenTasksCount ?? 0)) > 0 ? 'amber' : 'green'],
    ])->values();
    $activityEscalationRows = $topPriorityQueueRows->take(8)->values();
    $activityCenterLinks = collect([
        ['label' => 'View Renewal Center', 'href' => $renewalCenterUrl],
        ['label' => 'View Pickup Center', 'href' => $pickupCenterUrl],
        ['label' => 'View Communication Center', 'href' => $communicationCenterUrl],
    ])->filter(fn ($link) => !empty($link['href']))->values();
    $trendPercent = function ($current, $previous): float {
        $current = (float) $current;
        $previous = (float) $previous;

        if ($previous == 0.0) {
            return 0.0;
        }

        return round((($current - $previous) / abs($previous)) * 100, 1);
    };
    $buildExecutiveTrendMetric = function (string $label, $current, $previous, callable $formatter) use ($trendPercent) {
        $current = (float) $current;
        $previous = (float) $previous;

        if ($previous > 0) {
            $percent = $trendPercent($current, $previous);
            $value = ($percent > 0 ? '+' : '') . number_format($percent, 1) . '%';
            $tone = $percent < 0 ? 'red' : ($percent > 0 ? 'green' : 'blue');
        } elseif ($current > 0) {
            $value = 'New Activity';
            $tone = 'blue';
        } else {
            $value = 'No activity';
            $tone = 'blue';
        }

        return [
            'label' => $label,
            'value' => $value,
            'tone' => $tone,
            'tooltip' => 'Current Period: ' . $formatter($current) . "\n" . 'Previous Period: ' . $formatter($previous),
        ];
    };
    $latestTrendRow = $monthlyTrendRows->last() ?? [];
    $previousTrendRow = $monthlyTrendRows->slice(-2, 1)->first() ?? [];
    $latestRevenueTrendRow = $revenueTrendRows->last() ?? [];
    $previousRevenueTrendRow = $revenueTrendRows->slice(-2, 1)->first() ?? [];
    $executiveRevenueTrendCurrent = ((float) ($latestTrendRow['rental_total'] ?? 0)) + ((float) ($latestTrendRow['sales_total'] ?? 0));
    $executiveRevenueTrendPrevious = ((float) ($previousTrendRow['rental_total'] ?? 0)) + ((float) ($previousTrendRow['sales_total'] ?? 0));
    $executiveOrdersTrendCurrent = (float) ($latestTrendRow['total_orders'] ?? 0);
    $executiveOrdersTrendPrevious = (float) ($previousTrendRow['total_orders'] ?? 0);
    $executiveCollectionTrendCurrent = (float) ($latestRevenueTrendRow['collected_total'] ?? 0);
    $executiveCollectionTrendPrevious = (float) ($previousRevenueTrendRow['collected_total'] ?? 0);
    $executiveRentalUtilizationPercent = $inventoryAvailabilityTotal > 0
        ? round(($onRentInventoryCount / max($inventoryAvailabilityTotal, 1)) * 100, 1)
        : $activePercent;
    $executiveBusinessHealthRows = collect([
        $buildExecutiveTrendMetric('Revenue Trend', $executiveRevenueTrendCurrent, $executiveRevenueTrendPrevious, fn ($value) => $currency($value)),
        $buildExecutiveTrendMetric('Orders Trend', $executiveOrdersTrendCurrent, $executiveOrdersTrendPrevious, fn ($value) => number_format((int) $value) . ' order(s)'),
        $buildExecutiveTrendMetric('Collection Trend', $executiveCollectionTrendCurrent, $executiveCollectionTrendPrevious, fn ($value) => $currency($value)),
        [
            'label' => 'Rental Utilization',
            'value' => number_format($executiveRentalUtilizationPercent, 1) . '%',
            'tone' => $executiveRentalUtilizationPercent >= 65 ? 'green' : ($executiveRentalUtilizationPercent >= 35 ? 'amber' : 'blue'),
            'tooltip' => 'Current Period: ' . number_format($onRentInventoryCount) . ' on-rent rental asset(s) / ' . number_format($inventoryAvailabilityTotal) . ' total rental asset(s)' . "\n" . 'Previous Period: Not period-based',
        ],
    ])->values();
    $executiveCommunicationPulseRows = collect([
        ['label' => 'Follow-ups Pending', 'value' => (int) (($pendingRenewalFollowUpsCount ?? 0) + ($pendingPaymentFollowUpsCount ?? 0) + ($pendingPickupFollowUpsCount ?? 0)), 'tone' => ((int) ($overdueFollowUpsCount ?? 0)) > 0 ? 'red' : 'blue'],
        ['label' => 'Critical Alerts', 'value' => (int) ($overdueInvoiceCountValue + $overdueDeliveryCountValue + ($highPriorityFollowUpsCount ?? 0)), 'tone' => ($overdueInvoiceCountValue + $overdueDeliveryCountValue + ((int) ($highPriorityFollowUpsCount ?? 0))) > 0 ? 'red' : 'green'],
        ['label' => 'Unread Notifications', 'value' => (int) ($unreadNotificationsCount ?? $topbarNotificationCount ?? 0), 'tone' => ((int) ($unreadNotificationsCount ?? $topbarNotificationCount ?? 0)) > 0 ? 'amber' : 'green'],
    ])->values();
    $executiveForecastRows = collect([
        ['label' => 'Renewals Due', 'value' => (int) ($renewalsDueThisWeekCount ?? 0), 'tone' => ((int) ($renewalsDueThisWeekCount ?? 0)) > 0 ? 'amber' : 'green'],
        ['label' => 'Returns Due', 'value' => (int) (($returnsDueTodayCountValue ?? 0) + ($endingSoonCount ?? 0)), 'tone' => (((int) ($returnsDueTodayCountValue ?? 0) + (int) ($endingSoonCount ?? 0)) > 0) ? 'amber' : 'green'],
        ['label' => 'Deliveries Scheduled', 'value' => $scheduledDeliveryCountValue, 'tone' => $scheduledDeliveryCountValue > 0 ? 'blue' : 'green'],
        ['label' => 'Pickups Scheduled', 'value' => $scheduledPickupCountValue, 'tone' => $scheduledPickupCountValue > 0 ? 'blue' : 'green'],
    ])->values();
    $executivePerformanceRows = collect([
        ['label' => 'Rental Revenue', 'value' => $compactCurrency((float) ($latestTrendRow['rental_total'] ?? 0)), 'points' => $buildMiniSparkline($monthlyTrendRows->pluck('rental_total')->map(fn ($value) => (float) $value)->all()), 'tone' => 'blue'],
        ['label' => 'Sales Revenue', 'value' => $compactCurrency((float) ($latestTrendRow['sales_total'] ?? 0)), 'points' => $buildMiniSparkline($monthlyTrendRows->pluck('sales_total')->map(fn ($value) => (float) $value)->all()), 'tone' => 'green'],
        ['label' => 'Total Orders', 'value' => number_format((int) ($latestTrendRow['total_orders'] ?? 0)), 'points' => $buildMiniSparkline($monthlyTrendRows->pluck('total_orders')->map(fn ($value) => (float) $value)->all()), 'tone' => 'amber'],
    ])->values();
    $executiveRecentActivityRows = $activityRecentItems->take(5)->values();

    $operationsHealthCards = collect([
        [
            'label' => 'Deliveries at Risk',
            'value' => number_format(max($overdueDeliveryCountValue, 0)),
            'status' => $overdueDeliveryCountValue > 0 ? 'Overdue dispatch tasks' : 'Delivery flow on track',
            'note' => number_format($pendingDeliveryCountValue) . ' open delivery task(s)',
            'href' => $deliveriesIndexUrl ? route('deliveries.index', ['board' => 'delivery_workload']) : null,
            'action' => 'Open Deliveries',
            'icon' => 'delivery',
            'tone' => $overdueDeliveryCountValue > 0 ? 'red' : 'green',
        ],
        [
            'label' => 'Pickups Pending',
            'value' => number_format(max($pendingPickupCountValue, 0)),
            'status' => $pickupCenterOverdueCount > 0 ? number_format((int) $pickupCenterOverdueCount) . ' overdue pickup(s)' : 'Pickup queue manageable',
            'note' => number_format((int) ($pickupsScheduledTodayCount ?? 0)) . ' scheduled today',
            'href' => $pickupCenterUrl ? route('pickup-center.index', ['tab' => 'scheduled_today']) : ($deliveriesIndexUrl ? route('deliveries.index', ['board' => 'pickup_workload']) : null),
            'action' => 'Open Pickups',
            'icon' => 'pickup',
            'tone' => $pickupCenterOverdueCount > 0 ? 'amber' : 'blue',
        ],
        [
            'label' => 'Renewals Due',
            'value' => number_format((int) (($renewalsDueTodayCount ?? 0) + ($overdueRenewalsCount ?? 0))),
            'status' => ((int) ($overdueRenewalsCount ?? 0)) > 0
                ? number_format((int) ($overdueRenewalsCount ?? 0)) . ' overdue renewal(s)'
                : 'Today’s renewal queue visible',
            'note' => number_format((int) ($renewalsDueTodayCount ?? 0)) . ' due today',
            'href' => $renewalCenterUrl ? route('renewal-center.index', ['tab' => 'due_today']) : null,
            'action' => 'Open Renewals',
            'icon' => 'rental',
            'tone' => ((int) ($overdueRenewalsCount ?? 0)) > 0 ? 'red' : 'amber',
        ],
        [
            'label' => 'Unassigned Work',
            'value' => number_format((int) ($unassignedTasksCount ?? 0)),
            'status' => ((int) ($unassignedTasksCount ?? 0)) > 0 ? 'Needs owner assignment' : 'No unassigned field work',
            'note' => number_format((int) ($failedTasksCount ?? 0)) . ' failed field task(s)',
            'href' => $deliveriesIndexUrl ? route('deliveries.index', ['staff' => 'unassigned']) : null,
            'action' => 'Assign Work',
            'icon' => 'tasks',
            'tone' => ((int) ($unassignedTasksCount ?? 0)) > 0 ? 'amber' : 'green',
        ],
    ])->filter(fn ($card) => !empty($card['href']))->values();

    $teamCapacityRows = collect();
    $teamBucketMap = [
        'Delivery Team' => ['delivery', 'dispatch'],
        'Sales Team' => ['sales', 'business'],
        'Service Team' => ['service', 'warehouse', 'asset', 'inventory'],
    ];
    $teamBucketRows = [
        'Delivery Team' => collect(),
        'Operations Team' => collect(),
        'Sales Team' => collect(),
        'Service Team' => collect(),
    ];

    foreach ($staffWorkloadSummary as $row) {
        $haystack = \Illuminate\Support\Str::lower(trim((string) (($row['name'] ?? '') . ' ' . ($row['role'] ?? ''))));
        $bucket = 'Operations Team';

        foreach ($teamBucketMap as $label => $keywords) {
            foreach ($keywords as $keyword) {
                if (\Illuminate\Support\Str::contains($haystack, $keyword)) {
                    $bucket = $label;
                    break 2;
                }
            }
        }

        $teamBucketRows[$bucket] = $teamBucketRows[$bucket]->push($row);
    }

    foreach ($teamBucketRows as $label => $rows) {
        $deliveryCount = (int) $rows->sum(fn ($row) => (int) ($row['delivery_count'] ?? 0));
        $pickupCount = (int) $rows->sum(fn ($row) => (int) ($row['pickup_count'] ?? 0));
        $followupCount = (int) $rows->sum(fn ($row) => (int) ($row['followup_count'] ?? 0));
        $taskCount = (int) $rows->sum(fn ($row) => (int) ($row['overdue_count'] ?? 0));
        $hasOverloaded = $rows->contains(fn ($row) => (($row['load_state'] ?? null) === 'Overloaded'));
        $hasBusy = $rows->contains(fn ($row) => (($row['load_state'] ?? null) === 'Balanced'));

        $teamCapacityRows->push([
            'label' => $label,
            'deliveries' => $deliveryCount,
            'pickups' => $pickupCount,
            'followups' => $followupCount,
            'tasks' => $taskCount,
            'status' => $hasOverloaded ? 'Overloaded' : ($hasBusy ? 'Busy' : 'Normal'),
            'tone' => $hasOverloaded ? 'red' : ($hasBusy ? 'amber' : 'green'),
        ]);
    }

    $operationsSnapshotRows = collect([
        ['label' => 'Deliveries Today', 'value' => number_format((int) ($deliveriesTodayCount ?? 0)), 'note' => number_format($scheduledDeliveryCountValue) . ' assigned / scheduled', 'tone' => 'blue'],
        ['label' => 'Pickups Today', 'value' => number_format((int) ($pickupsScheduledTodayCount ?? 0)), 'note' => number_format((int) ($pickupCenterOverdueCount ?? 0)) . ' overdue pickup(s)', 'tone' => 'amber'],
        ['label' => 'Renewals Today', 'value' => number_format((int) ($renewalsDueTodayCount ?? 0)), 'note' => number_format((int) ($overdueRenewalsCount ?? 0)) . ' overdue renewal(s)', 'tone' => 'red'],
        ['label' => 'Service Visits Today', 'value' => number_format((int) ($serviceVisitsTodayCount ?? 0)), 'note' => ((int) ($serviceVisitsTodayCount ?? 0)) > 0 ? 'Service queue scheduled' : 'No service visits data yet', 'tone' => 'green'],
    ])->values();

    $dashboardCenterGroups = collect([
        [
            'label' => 'Operations Center',
            'copy' => 'Operations health, rental pipeline, priority queue, capacity, and daily workload.',
            'links' => collect([
                ['label' => 'Operations Health', 'href' => '#operations-center-panel'],
                ['label' => 'Rental Pipeline', 'href' => '#operations-center-panel'],
                ['label' => 'Priority Queue', 'href' => '#operations-center-panel'],
                ['label' => 'Team Capacity', 'href' => '#operations-center-panel'],
            ])->filter(fn ($link) => !empty($link['href']))->values(),
        ],
        [
            'label' => 'Revenue Center',
            'copy' => 'Sales pulse, finance summary, invoice pressure, and collection actions.',
            'links' => collect([
                ['label' => 'Revenue Center', 'href' => '#sales-pulse-panel'],
                ['label' => 'Invoice Aging', 'href' => '#revenue-aging'],
                ['label' => 'Collection Actions', 'href' => '#revenue-actions'],
                ['label' => 'Revenue Activity', 'href' => '#revenue-activity'],
            ])->filter(fn ($link) => !empty($link['href']))->values(),
        ],
        [
            'label' => 'Inventory Center',
            'copy' => 'Fulfilment readiness, inventory pressure, warehouse position, and asset movement.',
            'links' => collect([
                ['label' => 'Inventory Health', 'href' => $showInventorySection ? '#inventory-center-panel' : null],
                ['label' => 'Product Risk', 'href' => $showInventorySection ? '#inventory-risk-panel' : null],
                ['label' => 'Detailed Inventory', 'href' => $showInventorySection ? '#inventory-detail-panel' : null],
            ])->filter(fn ($link) => !empty($link['href']))->values(),
            'visible' => $showInventorySection,
        ],
        [
            'label' => 'Activity & Communication Center',
            'copy' => 'Recent activity, alerts, communications, notifications, and escalation queue.',
            'links' => collect([
                ['label' => 'Recent Activity', 'href' => '#recent-ops'],
                ['label' => 'Alerts', 'href' => '#activity-alerts'],
                ['label' => 'Communication Queue', 'href' => '#activity-communication'],
                ['label' => 'Escalation Queue', 'href' => '#activity-escalations'],
            ])->filter(fn ($link) => !empty($link['href']))->values(),
        ],
    ])->filter(fn ($group) => $group['visible'] ?? true)->values();
@endphp

<style>
    .dashboard-shell .rx-page-title,
    .dashboard-shell .rx-card-title {
        letter-spacing: -0.035em;
    }
    .dashboard-shell {
        display: grid;
        gap: 12px;
        width: 100%;
        max-width: 1320px;
        margin: 0 auto;
    }
    .control-room-shell {
        display: grid;
        gap: 10px;
    }
    .control-room-header {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        align-items: center;
        gap: 10px 14px;
        padding: 10px 12px;
        border: 1px solid var(--ph-color-border);
        border-radius: var(--ph-radius-xl);
        background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%);
        box-shadow: var(--ph-shadow-card);
    }
    .control-room-header-copy {
        display: grid;
        gap: 5px;
        min-width: 0;
    }
    .control-room-title-row {
        display: flex;
        align-items: center;
        gap: 8px 10px;
        flex-wrap: wrap;
    }
    .control-room-title-row h1 {
        margin: 0;
        font-size: 18px;
        line-height: 1;
        letter-spacing: -0.04em;
        color: var(--ph-color-text);
    }
    .control-room-title-row p,
    .control-room-header-copy p,
    .control-room-status-line {
        margin: 0;
        color: #3f5878;
        font-size: 11px;
        line-height: 1.35;
    }
    .control-room-meta-strip {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
        color: var(--ph-color-text-soft);
        font-size: 11px;
        font-weight: 600;
    }
    .control-room-status-line {
        display: inline-flex;
        align-items: center;
        min-height: 22px;
        padding: 0 8px;
        border-radius: 999px;
        background: #f8fbff;
        border: 1px solid rgba(148, 163, 184, 0.16);
    }
    .control-room-header-side {
        display: grid;
        gap: 8px;
        min-width: 0;
        justify-items: end;
    }
    .control-room-header-side .dashboard-hero-actions {
        justify-content: flex-end;
    }
    .control-room-header-side .dashboard-quick-actions-grid {
        gap: 6px;
        justify-content: flex-end;
    }
    .control-room-header-side .rx-btn,
    .control-room-header-side .rx-btn-secondary {
        min-height: 34px;
        padding: 8px 12px;
        font-size: 11px;
    }
    .control-room-reference-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(84px, 1fr));
        gap: 8px;
        width: 100%;
        max-width: 420px;
    }
    .control-room-reference-card {
        display: grid;
        gap: 2px;
        padding: 7px 9px;
        border: 1px solid rgba(148, 163, 184, 0.18);
        border-radius: 12px;
        background: #fff;
        text-decoration: none;
        transition: border-color .2s ease, box-shadow .2s ease, transform .2s ease;
    }
    .control-room-reference-card:hover {
        border-color: rgba(67, 56, 202, 0.22);
        box-shadow: 0 12px 24px rgba(15, 23, 42, 0.06);
        transform: translateY(-1px);
    }
    .control-room-reference-card span {
        font-size: 9px;
        font-weight: 700;
        letter-spacing: .08em;
        text-transform: uppercase;
        color: #6f84a2;
    }
    .control-room-reference-card strong {
        font-size: 14px;
        line-height: 1;
        color: var(--ph-color-text);
    }
    .control-room-filter-dock {
        display: flex;
        justify-content: flex-end;
    }
    .control-room-filter-dock .dashboard-filters-card {
        width: min(100%, 420px);
    }
    .control-room-priority-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 8px;
    }
    .executive-command-card {
        display: grid;
        gap: 10px;
        padding: 12px;
        border: 1px solid rgba(148, 163, 184, 0.16);
        border-radius: 18px;
        background: #fff;
        box-shadow: var(--ph-shadow-card);
    }
    .executive-health-strip {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 8px;
    }
    .executive-health-chip {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        min-height: 36px;
        padding: 7px 9px;
        border-radius: 12px;
        border: 1px solid rgba(226, 232, 240, 0.92);
        background: #fbfdff;
    }
    .executive-health-chip span,
    .executive-micro-label {
        color: #5f7696;
        font-size: 10px;
        font-weight: 900;
        letter-spacing: .08em;
        text-transform: uppercase;
    }
    .executive-health-chip strong {
        color: var(--ph-color-text);
        font-size: 15px;
        font-weight: 900;
        white-space: nowrap;
    }
    .executive-intel-grid {
        display: grid;
        grid-template-columns: minmax(0, .78fr) minmax(0, .92fr) minmax(0, 1.3fr);
        gap: 8px;
        align-items: stretch;
    }
    .executive-micro-card {
        display: grid;
        gap: 7px;
        min-width: 0;
        padding: 9px;
        border-radius: 14px;
        border: 1px solid rgba(226, 232, 240, 0.92);
        background: #fff;
    }
    .executive-micro-list,
    .executive-performance-list,
    .executive-activity-strip {
        display: grid;
        gap: 6px;
    }
    .executive-micro-row {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 8px;
        align-items: center;
    }
    .executive-micro-row span,
    .executive-performance-row span,
    .executive-activity-item span {
        overflow: hidden;
        color: #425c7f;
        font-size: 11px;
        line-height: 1.2;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .executive-micro-row strong,
    .executive-performance-row strong {
        color: var(--ph-color-text);
        font-size: 14px;
        font-weight: 900;
        white-space: nowrap;
    }
    .executive-performance-row {
        display: grid;
        grid-template-columns: minmax(92px, .9fr) auto 96px;
        gap: 8px;
        align-items: center;
    }
    .executive-sparkline {
        width: 96px;
        height: 26px;
        display: block;
    }
    .executive-sparkline polyline {
        fill: none;
        stroke: var(--ph-color-primary);
        stroke-width: 2.4;
        stroke-linecap: round;
        stroke-linejoin: round;
    }
    .executive-sparkline.is-green polyline { stroke: var(--ph-color-success); }
    .executive-sparkline.is-amber polyline { stroke: var(--ph-color-warning); }
    .executive-footer-activity {
        display: grid;
        gap: 7px;
        padding-top: 2px;
    }
    .executive-activity-strip {
        grid-template-columns: repeat(5, minmax(0, 1fr));
    }
    .executive-activity-item {
        display: grid;
        gap: 3px;
        min-width: 0;
        padding: 7px 8px;
        border-radius: 12px;
        border: 1px solid rgba(226, 232, 240, 0.92);
        background: #fbfdff;
        text-decoration: none;
        color: inherit;
    }
    .executive-activity-item strong {
        overflow: hidden;
        color: var(--ph-color-text);
        font-size: 11px;
        line-height: 1.2;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .activity-control-shell {
        overflow: hidden;
    }
    .activity-control-shell > summary {
        list-style: none;
        cursor: pointer;
    }
    .activity-control-shell > summary::-webkit-details-marker {
        display: none;
    }
    .activity-control-shell > summary::after {
        content: "Expand";
        align-self: center;
        padding: 6px 10px;
        border-radius: 999px;
        border: 1px solid rgba(79, 70, 229, 0.16);
        background: #f8fbff;
        color: var(--ph-color-primary);
        font-size: 11px;
        font-weight: 900;
    }
    .activity-control-shell[open] > summary::after {
        content: "Collapse";
    }
    .executive-mini-grid {
        display: grid;
        gap: 6px;
    }
    .executive-mini-row {
        display: grid;
        gap: 2px;
        padding: 8px 9px;
        border-radius: 12px;
        border: 1px solid rgba(226, 232, 240, 0.88);
        background: #fff;
    }
    .executive-mini-row-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
    }
    .executive-mini-label {
        font-size: 9px;
        font-weight: 800;
        letter-spacing: .08em;
        text-transform: uppercase;
        color: #6f84a2;
    }
    .executive-mini-value {
        font-size: 18px;
        line-height: 1;
        font-weight: 800;
        letter-spacing: -.04em;
        color: var(--ph-color-text);
    }
    .executive-mini-note {
        margin: 0;
        color: #425c7f;
        font-size: 10px;
        line-height: 1.3;
    }
    .executive-queue {
        display: grid;
        gap: 6px;
    }
    .executive-queue-list {
        display: grid;
        gap: 6px;
    }
    .executive-queue-item {
        display: grid;
        grid-template-columns: auto minmax(0, 1fr) auto;
        gap: 8px 10px;
        align-items: center;
        padding: 8px 10px;
        border-radius: 12px;
        border: 1px solid rgba(226, 232, 240, 0.88);
        background: linear-gradient(180deg, rgba(255,255,255,0.98), rgba(248,250,252,0.88));
    }
    .executive-queue-pill {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 20px;
        padding: 4px 8px;
        border-radius: 999px;
        font-size: 9px;
        font-weight: 800;
        line-height: 1;
        background: rgba(59, 130, 246, 0.1);
        color: #2563eb;
    }
    .executive-queue-pill.is-danger {
        background: rgba(239, 68, 68, 0.12);
        color: #dc2626;
    }
    .executive-queue-pill.is-warning {
        background: rgba(245, 158, 11, 0.14);
        color: #b45309;
    }
    .executive-queue-copy {
        min-width: 0;
        display: grid;
        gap: 2px;
    }
    .executive-queue-copy strong,
    .executive-queue-copy span {
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .executive-queue-copy strong {
        font-size: 11px;
        color: var(--ph-color-text);
    }
    .executive-queue-copy span {
        font-size: 9px;
        color: #425c7f;
    }
    .executive-queue-link {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        font-size: 9px;
        font-weight: 700;
        color: var(--ph-color-primary);
        text-decoration: none;
        white-space: nowrap;
    }
    .dashboard-center-groups {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 10px;
    }
    .dashboard-center-group {
        border: 1px solid rgba(148, 163, 184, 0.16);
        border-radius: 16px;
        background: #fff;
        box-shadow: var(--ph-shadow-card);
        overflow: hidden;
    }
    .dashboard-center-summary {
        list-style: none;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 11px 12px;
    }
    .dashboard-center-summary::-webkit-details-marker {
        display: none;
    }
    .dashboard-center-summary strong {
        display: block;
        font-size: 13px;
        color: var(--ph-color-text);
    }
    .dashboard-center-summary span {
        display: block;
        margin-top: 2px;
        font-size: 10px;
        line-height: 1.3;
        color: #425c7f;
    }
    .dashboard-center-summary::after {
        content: "+";
        font-size: 16px;
        font-weight: 700;
        color: #64748b;
    }
    .dashboard-center-group[open] .dashboard-center-summary::after {
        content: "−";
    }
    .dashboard-center-links {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        padding: 0 12px 12px;
    }
    .dashboard-center-link {
        display: inline-flex;
        align-items: center;
        min-height: 30px;
        padding: 6px 10px;
        border-radius: 999px;
        border: 1px solid rgba(79, 70, 229, 0.14);
        background: #f8fbff;
        color: var(--ph-color-primary);
        font-size: 10px;
        font-weight: 700;
        text-decoration: none;
    }
    .operations-center-grid {
        display: grid;
        gap: 12px;
    }
    .operations-health-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 10px;
    }
    .operations-health-card {
        display: grid;
        gap: 8px;
        padding: 14px 15px;
        border-radius: 18px;
        border: 1px solid rgba(148, 163, 184, 0.16);
        background: #fff;
        box-shadow: var(--ph-shadow-card);
        text-decoration: none;
        color: inherit;
    }
    .operations-health-card:hover {
        border-color: rgba(99, 102, 241, 0.22);
        box-shadow: 0 18px 36px rgba(15, 23, 42, 0.08);
    }
    .operations-health-head,
    .operations-snapshot-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
    }
    .operations-health-label,
    .operations-snapshot-label {
        font-size: 11px;
        font-weight: 800;
        letter-spacing: .08em;
        text-transform: uppercase;
        color: #597196;
    }
    .operations-health-icon {
        width: 30px;
        height: 30px;
        display: grid;
        place-items: center;
        border-radius: 12px;
        border: 1px solid rgba(191, 219, 254, 0.95);
        background: #f8fbff;
        color: var(--ph-color-primary);
    }
    .operations-health-icon svg {
        width: 15px;
        height: 15px;
    }
    .operations-health-value {
        font-size: 26px;
        line-height: 1;
        letter-spacing: -.04em;
        font-weight: 800;
        color: var(--ph-color-text);
    }
    .operations-health-status {
        font-size: 13px;
        font-weight: 700;
        color: var(--ph-color-text);
    }
    .operations-health-note {
        font-size: 12px;
        line-height: 1.45;
        color: #4c678d;
    }
    .operations-health-link {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        font-size: 12px;
        font-weight: 700;
        color: var(--ph-color-primary);
        text-decoration: none;
    }
    .operations-layout-grid {
        display: grid;
        grid-template-columns: minmax(0, 1.15fr) minmax(0, .85fr);
        gap: 12px;
    }
    .operations-pipeline-wrap {
        display: grid;
        gap: 12px;
    }
    .operations-command-panel {
        grid-column: 1 / -1;
        align-content: start;
    }
    .operations-command-grid {
        display: grid;
        grid-template-columns: minmax(0, 1.25fr) minmax(280px, .75fr);
        gap: 10px;
        align-items: start;
    }
    .operations-command-block {
        display: grid;
        gap: 6px;
        min-width: 0;
    }
    .operations-command-label {
        font-size: 10px;
        font-weight: 800;
        letter-spacing: .08em;
        text-transform: uppercase;
        color: #5f7696;
    }
    .operations-capacity-table-wrap {
        overflow-x: auto;
    }
    .operations-capacity-table {
        width: 100%;
        min-width: 650px;
        border-collapse: collapse;
    }
    .operations-capacity-table th,
    .operations-capacity-table td {
        padding: 8px 10px;
        border-bottom: 1px solid rgba(226, 232, 240, 0.9);
        text-align: left;
        vertical-align: middle;
        white-space: nowrap;
    }
    .operations-capacity-table th {
        font-size: 10px;
        font-weight: 800;
        letter-spacing: .08em;
        text-transform: uppercase;
        color: #5f7696;
    }
    .operations-capacity-table td {
        font-size: 12px;
        color: #425c7f;
    }
    .operations-capacity-team {
        font-weight: 800;
        color: var(--ph-color-text);
    }
    .operations-capacity-count {
        font-weight: 800;
        color: var(--ph-color-text);
    }
    .operations-command-action {
        font-size: 12px;
        font-weight: 800;
        color: var(--ph-color-primary);
        text-decoration: none;
    }
    .operations-snapshot-strip {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px;
    }
    .operations-snapshot-card {
        display: grid;
        gap: 3px;
        min-height: 78px;
        padding: 9px 10px;
        border-radius: 12px;
        border: 1px solid rgba(148, 163, 184, 0.14);
        background: #fff;
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05);
    }
    .operations-snapshot-value {
        font-size: 20px;
        line-height: 1;
        font-weight: 800;
        letter-spacing: -.04em;
        color: var(--ph-color-text);
    }
    .operations-snapshot-note {
        font-size: 11px;
        line-height: 1.3;
        color: #4c678d;
    }
    .activity-control-header {
        align-items: center;
    }
    .activity-control-links {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        justify-content: flex-end;
    }
    .activity-control-links a {
        display: inline-flex;
        align-items: center;
        min-height: 30px;
        padding: 6px 10px;
        border-radius: 999px;
        border: 1px solid rgba(79, 70, 229, 0.16);
        background: #f8fbff;
        color: var(--ph-color-primary);
        font-size: 11px;
        font-weight: 800;
        text-decoration: none;
        white-space: nowrap;
    }
    .activity-control-grid {
        display: grid;
        grid-template-columns: minmax(0, 1.2fr) minmax(320px, .8fr);
        gap: 10px;
        align-items: start;
    }
    .activity-control-card {
        display: grid;
        gap: 8px;
        min-width: 0;
        padding: 12px;
        border-radius: 14px;
        border: 1px solid rgba(148, 163, 184, 0.16);
        background: #fff;
        box-shadow: 0 12px 28px rgba(15, 23, 42, 0.05);
    }
    .activity-control-card.is-wide {
        grid-column: 1 / -1;
    }
    .activity-control-card-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
    }
    .activity-control-title {
        font-size: 12px;
        font-weight: 900;
        letter-spacing: .08em;
        text-transform: uppercase;
        color: #526b90;
    }
    .activity-control-count {
        font-size: 11px;
        font-weight: 800;
        color: #64748b;
    }
    .activity-control-list {
        display: grid;
        gap: 6px;
    }
    .activity-control-item {
        display: grid;
        grid-template-columns: auto minmax(0, 1fr) auto;
        gap: 8px;
        align-items: center;
        min-height: 42px;
        padding: 7px 8px;
        border-radius: 12px;
        border: 1px solid rgba(226, 232, 240, 0.9);
        background: #fbfdff;
        color: inherit;
        text-decoration: none;
    }
    .activity-control-item:hover {
        border-color: rgba(99, 102, 241, 0.24);
        background: #fff;
    }
    .activity-control-dot {
        width: 8px;
        height: 8px;
        border-radius: 999px;
        background: #64748b;
    }
    .activity-control-dot.is-green { background: var(--ph-color-success); }
    .activity-control-dot.is-blue { background: var(--ph-color-primary); }
    .activity-control-dot.is-violet { background: #7c3aed; }
    .activity-control-dot.is-amber { background: var(--ph-color-warning); }
    .activity-control-dot.is-red { background: var(--ph-color-danger); }
    .activity-control-main {
        min-width: 0;
    }
    .activity-control-main strong {
        display: block;
        overflow: hidden;
        color: var(--ph-color-text);
        font-size: 13px;
        line-height: 1.15;
        font-weight: 800;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .activity-control-main span {
        display: block;
        overflow: hidden;
        margin-top: 2px;
        color: #526b90;
        font-size: 11px;
        line-height: 1.25;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .activity-control-side {
        display: grid;
        justify-items: end;
        gap: 2px;
        min-width: 68px;
        color: #64748b;
        font-size: 10px;
        font-weight: 800;
        text-align: right;
    }
    .activity-control-side strong {
        color: var(--ph-color-text);
        font-size: 12px;
    }
    .activity-control-kpis {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 6px;
    }
    .activity-control-kpi {
        display: grid;
        gap: 3px;
        padding: 9px;
        border-radius: 12px;
        border: 1px solid rgba(226, 232, 240, 0.9);
        background: #fbfdff;
    }
    .activity-control-kpi span {
        color: #526b90;
        font-size: 10px;
        font-weight: 800;
        letter-spacing: .06em;
        text-transform: uppercase;
    }
    .activity-control-kpi strong {
        color: var(--ph-color-text);
        font-size: 22px;
        line-height: 1;
        font-weight: 900;
        letter-spacing: -.04em;
    }
    .activity-control-queue {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 6px;
    }
    .activity-control-queue-card {
        display: grid;
        gap: 3px;
        padding: 8px 9px;
        border-radius: 12px;
        border: 1px solid rgba(226, 232, 240, 0.9);
        background: #fbfdff;
        color: inherit;
        text-decoration: none;
    }
    .activity-control-queue-card span {
        color: #526b90;
        font-size: 11px;
        line-height: 1.25;
        font-weight: 800;
    }
    .activity-control-queue-card strong {
        color: var(--ph-color-text);
        font-size: 20px;
        line-height: 1;
        font-weight: 900;
    }
    .activity-control-queue-card small {
        color: #64748b;
        font-size: 10px;
        line-height: 1.25;
    }
    .operations-detail-group {
        border: 1px solid rgba(148, 163, 184, 0.16);
        border-radius: 18px;
        background: #fff;
        box-shadow: var(--ph-shadow-card);
        overflow: hidden;
    }
    .operations-detail-summary {
        list-style: none;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 13px 16px;
    }
    .operations-detail-summary::-webkit-details-marker {
        display: none;
    }
    .operations-detail-summary strong {
        display: block;
        font-size: 14px;
        color: var(--ph-color-text);
    }
    .operations-detail-summary span {
        display: block;
        margin-top: 3px;
        font-size: 12px;
        color: #4c678d;
    }
    .operations-detail-summary::after {
        content: "→";
        font-size: 16px;
        font-weight: 700;
        color: var(--ph-color-primary);
    }
    .operations-detail-group[open] .operations-detail-summary::after {
        content: "↓";
    }
    .operations-detail-content {
        display: grid;
        gap: 16px;
        padding: 0 16px 16px;
    }
    .inventory-center-grid {
        display: grid;
        gap: 12px;
    }
    .inventory-health-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 10px;
    }
    .inventory-health-card,
    .inventory-readiness-card,
    .inventory-warehouse-card {
        display: grid;
        gap: 6px;
        padding: 10px 12px;
        border-radius: 16px;
        border: 1px solid rgba(148, 163, 184, 0.16);
        background: #fff;
        box-shadow: var(--ph-shadow-card);
        text-decoration: none;
        color: inherit;
    }
    .inventory-health-card:hover,
    .inventory-warehouse-card:hover {
        border-color: rgba(99, 102, 241, 0.22);
        box-shadow: 0 18px 36px rgba(15, 23, 42, 0.08);
    }
    .inventory-health-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
    }
    .inventory-health-label,
    .inventory-readiness-label {
        font-size: 10px;
        font-weight: 800;
        letter-spacing: .08em;
        text-transform: uppercase;
        color: #597196;
    }
    .inventory-health-value,
    .inventory-readiness-value {
        font-size: 20px;
        line-height: 1;
        font-weight: 800;
        letter-spacing: -.04em;
        color: var(--ph-color-text);
    }
    .inventory-health-status {
        font-size: 11px;
        font-weight: 700;
        color: #173a67;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .inventory-health-note,
    .inventory-readiness-note,
    .inventory-warehouse-note {
        font-size: 11px;
        line-height: 1.35;
        color: #4c678d;
    }
    .inventory-warehouse-label {
        font-size: 11px;
        font-weight: 800;
        letter-spacing: .08em;
        text-transform: uppercase;
        color: #597196;
    }
    .inventory-warehouse-value {
        font-size: 22px;
        line-height: 1;
        font-weight: 800;
        color: var(--ph-color-text);
    }
    .inventory-health-link,
    .inventory-center-link {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        font-size: 12px;
        font-weight: 700;
        color: var(--ph-color-primary);
        text-decoration: none;
    }
    .inventory-layout-grid {
        display: grid;
        grid-template-columns: minmax(0, .95fr) minmax(0, 1.05fr);
        gap: 10px;
    }
    .inventory-donut-shell {
        display: grid;
        grid-template-columns: minmax(0, .85fr) minmax(0, 1.15fr);
        gap: 12px;
        align-items: center;
    }
    .inventory-availability-visual {
        display: grid;
        gap: 10px;
        justify-items: center;
    }
    .inventory-availability-pie {
        position: relative;
        width: 168px;
        height: 168px;
        border-radius: 999px;
        border: 1px solid rgba(148, 163, 184, 0.14);
        box-shadow: inset 0 0 0 1px rgba(255,255,255,0.4);
    }
    .inventory-availability-pie::after {
        content: "";
        position: absolute;
        inset: 28px;
        border-radius: 999px;
        background: #fff;
        box-shadow: inset 0 0 0 1px rgba(226, 232, 240, 0.9);
    }
    .inventory-availability-total {
        position: absolute;
        inset: 0;
        z-index: 1;
        display: grid;
        place-content: center;
        justify-items: center;
        gap: 3px;
        text-align: center;
    }
    .inventory-availability-total strong {
        font-size: 22px;
        line-height: 1;
        font-weight: 800;
        color: var(--ph-color-text);
    }
    .inventory-availability-total span {
        max-width: 84px;
        font-size: 11px;
        line-height: 1.3;
        color: #4c678d;
    }
    .inventory-availability-legend {
        display: grid;
        gap: 8px;
    }
    .inventory-availability-legend-item {
        display: grid;
        grid-template-columns: auto minmax(0, 1fr) auto;
        align-items: center;
        gap: 10px;
        padding: 7px 9px;
        border-radius: 12px;
        background: #f8fbff;
        border: 1px solid rgba(148, 163, 184, 0.12);
    }
    .inventory-availability-dot {
        width: 10px;
        height: 10px;
        border-radius: 999px;
    }
    .inventory-availability-dot.is-green,
    .inventory-availability-dot.is-success { background: #22c55e; }
    .inventory-availability-dot.is-blue,
    .inventory-availability-dot.is-info { background: #3b82f6; }
    .inventory-availability-dot.is-warning { background: #f59e0b; }
    .inventory-availability-dot.is-danger { background: #ef4444; }
    .inventory-availability-copy {
        display: grid;
        gap: 1px;
        min-width: 0;
    }
    .inventory-availability-copy strong {
        font-size: 13px;
        color: var(--ph-color-text);
    }
    .inventory-availability-copy span,
    .inventory-availability-value {
        font-size: 12px;
        color: #4c678d;
    }
    .inventory-availability-value {
        font-weight: 700;
        white-space: nowrap;
    }
    .inventory-readiness-grid,
    .inventory-warehouse-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 10px;
    }
    .inventory-readiness-grid {
        align-items: start;
    }
    .inventory-readiness-card {
        align-content: start;
        gap: 4px;
        padding: 9px 10px;
        border-radius: 14px;
    }
    .inventory-readiness-panel {
        align-content: start;
    }
    .inventory-readiness-value {
        font-size: 18px;
    }
    .inventory-risk-wrap {
        display: grid;
        gap: 10px;
    }
    .inventory-risk-table {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
    }
    .inventory-risk-table th,
    .inventory-risk-table td {
        padding: 9px 10px 9px 0;
        border-bottom: 1px solid rgba(226, 232, 240, 0.9);
        text-align: left;
        vertical-align: top;
    }
    .inventory-risk-table th:last-child,
    .inventory-risk-table td:last-child {
        padding-right: 0;
    }
    .inventory-risk-table th {
        font-size: 11px;
        font-weight: 800;
        letter-spacing: .08em;
        text-transform: uppercase;
        color: #5f7696;
        white-space: nowrap;
    }
    .inventory-risk-table td {
        font-size: 12px;
        color: #425c7f;
    }
    .inventory-risk-table th:nth-child(2),
    .inventory-risk-table th:nth-child(3),
    .inventory-risk-table td:nth-child(2),
    .inventory-risk-table td:nth-child(3) {
        text-align: center;
        width: 84px;
        white-space: nowrap;
    }
    .inventory-risk-table th:nth-child(4),
    .inventory-risk-table td:nth-child(4) {
        width: 110px;
        white-space: nowrap;
    }
    .inventory-risk-table th:nth-child(5),
    .inventory-risk-table td:nth-child(5) {
        width: 80px;
        text-align: right;
        white-space: nowrap;
    }
    .inventory-risk-name {
        display: -webkit-box;
        font-weight: 700;
        color: var(--ph-color-text);
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
        line-height: 1.35;
    }
    .inventory-risk-link {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        font-size: 11px;
        font-weight: 700;
        color: var(--ph-color-primary);
        text-decoration: none;
    }
    .inventory-movement-list {
        display: grid;
        gap: 10px;
    }
    .inventory-movement-item {
        display: grid;
        gap: 3px;
        padding-bottom: 10px;
        border-bottom: 1px solid rgba(226, 232, 240, 0.9);
        text-decoration: none;
        color: inherit;
    }
    .inventory-movement-item strong {
        font-size: 13px;
        color: var(--ph-color-text);
    }
    .inventory-movement-item span,
    .inventory-movement-item em {
        font-size: 12px;
        color: #4c678d;
    }
    .inventory-movement-item em {
        font-style: normal;
    }
    .inventory-movement-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
    }
    .inventory-movement-head strong {
        font-size: 13px;
        color: var(--ph-color-text);
    }
    .inventory-movement-head span,
    .inventory-movement-item small {
        font-size: 12px;
        color: #4c678d;
    }
    .inventory-detail-group {
        border: 1px solid rgba(148, 163, 184, 0.16);
        border-radius: 18px;
        background: #fff;
        box-shadow: var(--ph-shadow-card);
        overflow: hidden;
    }
    .inventory-detail-summary {
        list-style: none;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 13px 16px;
    }
    .inventory-detail-summary::-webkit-details-marker {
        display: none;
    }
    .inventory-detail-summary strong {
        display: block;
        font-size: 14px;
        color: var(--ph-color-text);
    }
    .inventory-detail-summary span {
        display: block;
        margin-top: 3px;
        font-size: 12px;
        color: #4c678d;
    }
    .inventory-detail-summary::after {
        content: "→";
        font-size: 16px;
        font-weight: 700;
        color: var(--ph-color-primary);
    }
    .inventory-detail-group[open] .inventory-detail-summary::after {
        content: "↓";
    }
    .inventory-detail-content {
        display: grid;
        gap: 16px;
        padding: 0 16px 16px;
    }
    .inventory-risk-wrap .rx-badge {
        font-size: 10px;
        padding: 4px 8px;
    }
    .inventory-warehouse-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        align-items: start;
        gap: 8px;
    }
    .inventory-warehouse-card {
        gap: 3px;
        align-content: start;
        min-height: 0;
    }
    .inventory-warehouse-panel {
        align-content: start;
    }
    .inventory-warehouse-top {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 8px;
    }
    .inventory-warehouse-meta {
        font-size: 11px;
        color: #4c678d;
    }
    .inventory-section-link {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        font-size: 12px;
        font-weight: 700;
        color: var(--ph-color-primary);
        text-decoration: none;
    }
    .control-room-priority-card {
        position: relative;
        display: grid;
        gap: 5px;
        min-height: 74px;
        padding: 10px 11px 9px;
        border: 1px solid rgba(148, 163, 184, 0.16);
        border-radius: 15px;
        background: #fff;
        box-shadow: var(--ph-shadow-card);
        text-decoration: none;
        color: inherit;
        overflow: hidden;
    }
    .control-room-priority-card::after {
        content: "";
        position: absolute;
        inset: 0;
        background: linear-gradient(135deg, rgba(255,255,255,0.35), transparent 70%);
        pointer-events: none;
    }
    .control-room-priority-card.is-danger {
        background: linear-gradient(180deg, #fff9f8 0%, #ffffff 100%);
        border-color: rgba(239, 68, 68, 0.16);
    }
    .control-room-priority-card.is-warning {
        background: linear-gradient(180deg, #fffaf4 0%, #ffffff 100%);
        border-color: rgba(245, 158, 11, 0.18);
    }
    .control-room-priority-card.is-info {
        background: linear-gradient(180deg, #f8fbff 0%, #ffffff 100%);
        border-color: rgba(59, 130, 246, 0.16);
    }
    .control-room-priority-top {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 8px;
    }
    .control-room-priority-label {
        display: block;
        font-size: 9px;
        font-weight: 700;
        letter-spacing: .08em;
        text-transform: uppercase;
        color: #6f84a2;
    }
    .control-room-priority-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 28px;
        height: 28px;
        border-radius: 10px;
        border: 1px solid rgba(59, 130, 246, 0.14);
        background: rgba(255, 255, 255, 0.85);
        color: var(--ph-color-primary);
    }
    .control-room-priority-icon svg {
        width: 13px;
        height: 13px;
    }
    .control-room-priority-copy {
        display: grid;
        gap: 2px;
        min-width: 0;
    }
    .control-room-priority-card.is-danger .control-room-priority-icon {
        color: #ef4444;
        border-color: rgba(239, 68, 68, 0.16);
    }
    .control-room-priority-card.is-warning .control-room-priority-icon {
        color: #d97706;
        border-color: rgba(245, 158, 11, 0.18);
    }
    .control-room-priority-value {
        margin: 0;
        font-size: 21px;
        line-height: 1;
        letter-spacing: -.05em;
        color: var(--ph-color-text);
    }
    .control-room-priority-status {
        margin: 0;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 9px;
        font-weight: 700;
        color: var(--ph-color-primary);
    }
    .control-room-priority-note {
        margin: 0;
        color: var(--ph-color-text-soft);
        font-size: 9px;
        line-height: 1.25;
    }
    .control-room-priority-visuals {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
        margin-top: auto;
    }
    .control-room-priority-meter {
        position: relative;
        min-height: 4px;
        flex: 1 1 88px;
        overflow: hidden;
        border-radius: 999px;
        background: rgba(148, 163, 184, 0.16);
    }
    .control-room-priority-meter span {
        display: block;
        height: 4px;
        border-radius: inherit;
        background: linear-gradient(90deg, rgba(79, 70, 229, 0.85), rgba(59, 130, 246, 0.9));
    }
    .control-room-priority-card.is-danger .control-room-priority-meter span {
        background: linear-gradient(90deg, #f97316, #ef4444);
    }
    .control-room-priority-card.is-warning .control-room-priority-meter span {
        background: linear-gradient(90deg, #f59e0b, #f97316);
    }
    .control-room-priority-link {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 9px;
        font-weight: 700;
        color: var(--ph-color-primary);
        text-decoration: none;
    }
    .control-room-priority-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 18px;
        padding: 3px 7px;
        border-radius: 999px;
        background: rgba(59, 130, 246, 0.1);
        color: #2563eb;
        font-size: 9px;
        font-weight: 700;
        line-height: 1;
    }
    .control-room-priority-card.is-danger .control-room-priority-badge {
        background: rgba(239, 68, 68, 0.12);
        color: #dc2626;
    }
    .control-room-priority-card.is-warning .control-room-priority-badge {
        background: rgba(245, 158, 11, 0.14);
        color: #b45309;
    }
    .control-room-executive-grid {
        display: grid;
        grid-template-columns: minmax(0, 1.28fr) minmax(320px, .96fr) minmax(300px, .9fr);
        gap: 10px;
        align-items: start;
    }
    .control-room-stack-column {
        display: grid;
        gap: 10px;
        min-width: 0;
        align-content: start;
    }
    .control-room-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
    }
    .control-room-card {
        display: grid;
        gap: 8px;
        padding: 11px 12px;
        border: 1px solid rgba(148, 163, 184, 0.16);
        border-radius: 16px;
        background: #fff;
        box-shadow: var(--ph-shadow-card);
    }
    .control-room-card-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 12px;
        flex-wrap: wrap;
    }
    .control-room-card-title {
        margin: 0;
        font-size: 14px;
        line-height: 1.15;
        color: var(--ph-color-text);
    }
    .control-room-card-copy {
        margin: 2px 0 0;
        color: #3f5878;
        font-size: 10px;
        line-height: 1.3;
    }
    .control-room-card-link {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 10px;
        font-weight: 700;
        color: var(--ph-color-primary);
        text-decoration: none;
    }
    .control-room-stat-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 6px;
    }
    .control-room-stat {
        display: grid;
        gap: 2px;
        padding: 8px 9px;
        border-radius: 12px;
        background: #f8fbff;
        border: 1px solid rgba(148, 163, 184, 0.18);
    }
    .control-room-stat-label {
        font-size: 9px;
        font-weight: 700;
        letter-spacing: .08em;
        text-transform: uppercase;
        color: #6f84a2;
    }
    .control-room-stat-value {
        font-size: 18px;
        line-height: 1;
        letter-spacing: -.04em;
        color: var(--ph-color-text);
        font-weight: 800;
    }
    .control-room-stat-note {
        font-size: 10px;
        line-height: 1.25;
        color: var(--ph-color-text-soft);
    }
    .control-room-stat-meter {
        min-height: 5px;
        overflow: hidden;
        border-radius: 999px;
        background: rgba(148, 163, 184, 0.16);
    }
    .control-room-stat-meter span {
        display: block;
        height: 5px;
        border-radius: inherit;
        background: linear-gradient(90deg, rgba(79, 70, 229, 0.8), rgba(34, 197, 94, 0.75));
    }
    .control-room-chart-shell {
        display: grid;
        gap: 8px;
        padding: 10px 12px 8px;
        border-radius: 16px;
        border: 1px solid rgba(226, 232, 240, 0.92);
        background: linear-gradient(180deg, rgba(248,250,252,0.82), rgba(255,255,255,0.98));
    }
    .control-room-chart-svg {
        width: 100%;
        height: auto;
        display: block;
        overflow: visible;
    }
    .control-room-chart-legend {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
    }
    .control-room-chart-legend-item {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        color: #425c7f;
        font-size: 10px;
        font-weight: 700;
    }
    .control-room-chart-legend-dot {
        width: 9px;
        height: 9px;
        border-radius: 999px;
        display: inline-block;
    }
    .control-room-chart-legend-dot.is-collections {
        background: #4f46e5;
    }
    .control-room-chart-axis {
        fill: #7b8da7;
        font-size: 9px;
        font-weight: 600;
    }
    .control-room-chart-value {
        fill: #425c7f;
        font-size: 9px;
        font-weight: 700;
    }
    .control-room-chart-grid {
        stroke: rgba(148, 163, 184, 0.22);
        stroke-width: 1;
    }
    .control-room-chart-bar {
        fill: rgba(59, 130, 246, 0.18);
    }
    .control-room-chart-line-primary {
        fill: none;
        stroke: #4f46e5;
        stroke-width: 3;
        stroke-linecap: round;
        stroke-linejoin: round;
    }
    .control-room-chart-line-secondary {
        fill: none;
        stroke: #16a34a;
        stroke-width: 3;
        stroke-linecap: round;
        stroke-linejoin: round;
    }
    .control-room-chart-dot-primary {
        fill: #4f46e5;
    }
    .control-room-chart-dot-secondary {
        fill: #16a34a;
    }
    .control-room-aging {
        display: grid;
        gap: 8px;
        padding: 10px 12px;
        border-radius: 16px;
        border: 1px solid rgba(226, 232, 240, 0.92);
        background: #fff;
    }
    .control-room-aging-bar {
        display: flex;
        min-height: 10px;
        overflow: hidden;
        border-radius: 999px;
        background: #eef4fb;
    }
    .control-room-aging-segment {
        min-width: 4px;
    }
    .control-room-aging-segment.is-blue { background: #93c5fd; }
    .control-room-aging-segment.is-warning { background: #fbbf24; }
    .control-room-aging-segment.is-danger { background: #f97316; }
    .control-room-aging-segment.is-danger-strong { background: #ef4444; }
    .control-room-aging-legend {
        display: grid;
        grid-template-columns: repeat(5, minmax(0, 1fr));
        gap: 6px;
    }
    .control-room-aging-legend-item {
        display: grid;
        gap: 4px;
    }
    .control-room-aging-legend-item strong {
        font-size: 10px;
        color: var(--ph-color-text);
    }
    .control-room-aging-legend-item span,
    .control-room-aging-legend-item small {
        color: var(--ph-color-text-soft);
        font-size: 9px;
    }
    .control-room-dues-list,
    .control-room-upcoming-list,
    .control-room-activity-list {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px;
    }
    .control-room-dues-list {
        grid-template-columns: 1fr;
        gap: 0;
        padding: 10px 12px;
        border-radius: 16px;
        border: 1px solid rgba(226, 232, 240, 0.92);
        background: #fff;
    }
    .control-room-list-item {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 10px;
        padding: 7px 9px;
        border-radius: 12px;
        background: #f9fbff;
        border: 1px solid rgba(148, 163, 184, 0.16);
    }
    .control-room-dues-list .control-room-list-item {
        padding: 10px 0;
        border-top: 1px solid rgba(226, 232, 240, 0.88);
        border-radius: 0;
        background: transparent;
        border-left: 0;
        border-right: 0;
        border-bottom: 0;
    }
    .control-room-dues-list .control-room-list-item:first-child {
        border-top: 0;
        padding-top: 0;
    }
    .control-room-list-track {
        width: 100%;
        height: 6px;
        border-radius: 999px;
        background: #eef2f7;
        overflow: hidden;
        margin-top: 5px;
    }
    .control-room-list-fill {
        height: 100%;
        border-radius: inherit;
        background: linear-gradient(90deg, rgba(248,113,113,0.6), rgba(251,146,60,0.78));
    }
    .control-room-list-item strong {
        display: block;
        color: var(--ph-color-text);
        font-size: 11px;
        line-height: 1.25;
    }
    .control-room-list-item span,
    .control-room-list-item small {
        display: block;
        color: var(--ph-color-text-soft);
        font-size: 9px;
        line-height: 1.2;
    }
    .control-room-list-amount {
        text-align: right;
        white-space: nowrap;
    }
    .control-room-pipeline {
        display: grid;
        gap: 12px;
    }
    .control-room-pipeline-track {
        display: grid;
        grid-template-columns: repeat(6, minmax(0, 1fr));
        gap: 10px;
    }
    .control-room-pipeline-stage {
        position: relative;
        display: grid;
        justify-items: center;
        gap: 6px;
        padding: 0;
        border: 0;
        background: transparent;
    }
    .control-room-pipeline-stage::after {
        content: "";
        position: absolute;
        top: 19px;
        right: -12px;
        width: 14px;
        height: 2px;
        background: rgba(148, 163, 184, 0.34);
    }
    .control-room-pipeline-stage:last-child::after {
        display: none;
    }
    .control-room-pipeline-stage-icon {
        width: 38px;
        height: 38px;
        border-radius: 999px;
        display: grid;
        place-items: center;
        background: #f7faff;
        border: 1px solid rgba(191, 219, 254, 0.95);
        color: var(--ph-color-primary);
        box-shadow: 0 10px 20px rgba(11, 35, 66, 0.05);
    }
    .control-room-pipeline-stage-icon svg {
        width: 15px;
        height: 15px;
    }
    .control-room-pipeline-stage.is-success .control-room-pipeline-stage-icon {
        color: var(--ph-color-success);
        background: #f3fbf6;
        border-color: rgba(34, 197, 94, 0.22);
    }
    .control-room-pipeline-stage.is-warning .control-room-pipeline-stage-icon {
        color: var(--ph-color-warning);
        background: #fffaf3;
        border-color: rgba(251, 146, 60, 0.22);
    }
    .control-room-pipeline-stage.is-info .control-room-pipeline-stage-icon {
        color: var(--ph-color-primary);
        background: #f7faff;
        border-color: rgba(59, 130, 246, 0.22);
    }
    .control-room-pipeline-stage-label {
        font-size: 10px;
        font-weight: 700;
        line-height: 1.25;
        text-align: center;
        color: #4d6383;
        min-height: 26px;
        display: flex;
        align-items: center;
        justify-content: center;
        max-width: 92px;
        word-break: break-word;
    }
    .control-room-pipeline-stage-value {
        font-size: 16px;
        line-height: 1;
        letter-spacing: -.04em;
        color: var(--ph-color-text);
        font-weight: 800;
    }
    .control-room-pipeline-bottom {
        display: grid;
        grid-template-columns: minmax(0, .9fr) minmax(0, 1.1fr);
        gap: 10px;
    }
    .control-room-summary-grid {
        display: grid;
        gap: 0;
        padding: 10px 12px;
        border-radius: 16px;
        border: 1px solid rgba(226, 232, 240, 0.92);
        background: linear-gradient(180deg, rgba(255,255,255,0.98), rgba(248,250,252,0.92));
    }
    .control-room-summary-tile {
        display: grid;
        grid-template-columns: auto 1fr;
        align-items: center;
        gap: 8px;
        padding: 10px 0;
        border-top: 1px solid rgba(226, 232, 240, 0.9);
        background: transparent;
        border-radius: 0;
        border-left: 0;
        border-right: 0;
        border-bottom: 0;
    }
    .control-room-summary-tile:first-child {
        border-top: 0;
        padding-top: 0;
    }
    .control-room-summary-tile strong {
        font-size: 20px;
        line-height: 1;
        color: var(--ph-color-text);
    }
    .control-room-summary-tile span {
        font-size: 10px;
        color: #425c7f;
    }
    .control-room-summary-copy {
        display: grid;
        gap: 3px;
    }
    .control-room-summary-copy small {
        color: #64748b;
        font-size: 10px;
        line-height: 1.35;
    }
    .control-room-upcoming-list {
        display: grid;
        gap: 10px;
        padding: 10px 12px;
        border-radius: 16px;
        border: 1px solid rgba(226, 232, 240, 0.92);
        background: linear-gradient(180deg, rgba(255,255,255,0.98), rgba(248,250,252,0.92));
    }
    .control-room-upcoming-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 10px;
    }
    .control-room-upcoming-icon {
        width: 42px;
        height: 42px;
        border-radius: 14px;
        display: grid;
        place-items: center;
        background: #eef6ff;
        border: 1px solid rgba(96, 165, 250, 0.24);
        color: #2563eb;
        flex: 0 0 42px;
    }
    .control-room-upcoming-icon svg {
        width: 18px;
        height: 18px;
    }
    .control-room-upcoming-state {
        display: grid;
        justify-items: center;
        align-content: center;
        min-height: 144px;
        text-align: center;
        padding: 6px 8px;
    }
    .control-room-upcoming-state strong {
        font-size: 18px;
        line-height: 1.25;
        color: var(--ph-color-text);
    }
    .control-room-upcoming-state span {
        margin-top: 8px;
        color: #64748b;
        font-size: 12px;
        line-height: 1.45;
        max-width: 180px;
    }
    .control-room-upcoming-rows {
        display: grid;
        gap: 0;
    }
    .control-room-upcoming-row {
        display: grid;
        grid-template-columns: 1fr auto;
        gap: 12px;
        padding: 10px 0;
        border-top: 1px solid rgba(226, 232, 240, 0.9);
        text-decoration: none;
        color: inherit;
    }
    .control-room-upcoming-row:first-child {
        border-top: 0;
        padding-top: 0;
    }
    .control-room-upcoming-row strong {
        display: block;
        color: #1d4ed8;
        font-size: 11px;
        line-height: 1.3;
    }
    .control-room-upcoming-row span {
        display: block;
        margin-top: 3px;
        color: var(--ph-color-text);
        font-size: 11px;
        line-height: 1.35;
    }
    .control-room-upcoming-row small {
        display: block;
        margin-top: 2px;
        color: #64748b;
        font-size: 10px;
        line-height: 1.35;
        text-align: right;
        white-space: nowrap;
    }
    .control-room-risk-table,
    .control-room-workload-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0 4px;
    }
    .control-room-risk-table th,
    .control-room-workload-table th {
        padding: 0 6px 3px;
        text-align: left;
        color: #6f84a2;
        font-size: 9px;
        font-weight: 700;
        letter-spacing: .08em;
        text-transform: uppercase;
    }
    .control-room-risk-table td,
    .control-room-workload-table td {
        padding: 6px;
        font-size: 11px;
        color: var(--ph-color-text);
        background: #f9fbff;
        border-top: 1px solid rgba(148, 163, 184, 0.16);
        border-bottom: 1px solid rgba(148, 163, 184, 0.16);
    }
    .control-room-risk-table td:first-child,
    .control-room-workload-table td:first-child {
        border-left: 1px solid rgba(148, 163, 184, 0.16);
        border-radius: 12px 0 0 12px;
    }
    .control-room-risk-table td:last-child,
    .control-room-workload-table td:last-child {
        border-right: 1px solid rgba(148, 163, 184, 0.16);
        border-radius: 0 12px 12px 0;
    }
    .control-room-risk-pill,
    .control-room-status-pill,
    .control-room-tab-pill {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 18px;
        padding: 3px 7px;
        border-radius: 999px;
        font-size: 9px;
        font-weight: 700;
        line-height: 1;
    }
    .control-room-risk-pill.is-danger,
    .control-room-status-pill.is-danger {
        background: rgba(239, 68, 68, 0.12);
        color: #dc2626;
    }
    .control-room-risk-pill.is-warning,
    .control-room-status-pill.is-warning {
        background: rgba(245, 158, 11, 0.14);
        color: #b45309;
    }
    .control-room-risk-pill.is-success,
    .control-room-status-pill.is-success {
        background: rgba(34, 197, 94, 0.12);
        color: #15803d;
    }
    .control-room-risk-pill.is-info,
    .control-room-status-pill.is-info {
        background: rgba(59, 130, 246, 0.12);
        color: #2563eb;
    }
    .control-room-section-stack {
        display: grid;
        gap: 12px;
    }
    .control-room-donut-shell {
        display: grid;
        grid-template-columns: 110px minmax(0, 1fr);
        gap: 10px;
        align-items: center;
    }
    .control-room-donut {
        --available-angle: 0deg;
        --rent-angle: 0deg;
        --maintenance-angle: 0deg;
        width: 110px;
        height: 110px;
        border-radius: 50%;
        background:
            radial-gradient(circle at center, #ffffff 0 41%, transparent 42%),
            conic-gradient(
                #22c55e 0deg var(--available-angle),
                #3b82f6 var(--available-angle) calc(var(--available-angle) + var(--rent-angle)),
                #f59e0b calc(var(--available-angle) + var(--rent-angle)) calc(var(--available-angle) + var(--rent-angle) + var(--maintenance-angle)),
                #ef4444 calc(var(--available-angle) + var(--rent-angle) + var(--maintenance-angle)) 360deg
            );
        border: 1px solid rgba(148, 163, 184, 0.14);
        box-shadow: inset 0 0 0 10px rgba(255,255,255,0.6);
        position: relative;
    }
    .control-room-donut-center {
        position: absolute;
        inset: 0;
        display: grid;
        place-items: center;
        text-align: center;
        pointer-events: none;
        padding: 0 10px;
    }
    .control-room-donut-center strong {
        display: block;
        font-size: 18px;
        line-height: 1;
        color: var(--ph-color-text);
    }
    .control-room-donut-center span {
        display: block;
        color: var(--ph-color-text-soft);
        max-width: 78px;
        margin: 0 auto;
        font-size: 11px;
        font-weight: 600;
        line-height: 1.15;
        text-align: center;
    }
    .control-room-segment-list {
        display: grid;
        gap: 6px;
    }
    .control-room-segment-row {
        display: grid;
        grid-template-columns: auto 1fr auto;
        gap: 6px;
        align-items: center;
        font-size: 10px;
        color: var(--ph-color-text);
    }
    .control-room-segment-dot {
        width: 10px;
        height: 10px;
        border-radius: 999px;
    }
    .control-room-segment-dot.is-success { background: #22c55e; }
    .control-room-segment-dot.is-info { background: #3b82f6; }
    .control-room-segment-dot.is-warning { background: #f59e0b; }
    .control-room-segment-dot.is-danger { background: #ef4444; }
    .control-room-tab-row {
        display: flex;
        align-items: center;
        gap: 5px;
        flex-wrap: wrap;
    }
    .control-room-tab-pill {
        border: 1px solid var(--ph-color-border);
        background: #fff;
        color: #5d7290;
        cursor: pointer;
        transition: background-color .2s ease, color .2s ease, border-color .2s ease;
    }
    .control-room-tab-pill.is-active {
        background: rgba(79, 70, 229, 0.1);
        color: #4338ca;
        border-color: rgba(79, 70, 229, 0.2);
    }
    .control-room-activity-link {
        text-decoration: none;
        color: inherit;
    }
    .control-room-activity-item {
        display: grid;
        gap: 2px;
        padding: 7px 9px;
        border-radius: 12px;
        background: #f9fbff;
        border: 1px solid rgba(148, 163, 184, 0.16);
    }
    .control-room-activity-item strong {
        color: var(--ph-color-text);
        font-size: 11px;
        line-height: 1.25;
    }
    .control-room-activity-item span,
    .control-room-activity-item small {
        color: var(--ph-color-text-soft);
        font-size: 9px;
        line-height: 1.2;
    }
    .control-room-expandable {
        display: grid;
        gap: 6px;
    }
    .control-room-expandable summary {
        list-style: none;
        cursor: pointer;
    }
    .control-room-expandable summary::-webkit-details-marker {
        display: none;
    }
    .control-room-expandable-trigger {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        color: var(--ph-color-primary);
        font-size: 10px;
        font-weight: 700;
    }
    .control-room-finance-visual {
        display: grid;
        gap: 8px;
    }
    .control-room-finance-hero {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 8px;
    }
    .control-room-finance-chip {
        display: grid;
        gap: 3px;
        padding: 8px 9px;
        border-radius: 12px;
        background: #f8fbff;
        border: 1px solid rgba(148, 163, 184, 0.16);
    }
    .control-room-finance-chip span {
        font-size: 9px;
        font-weight: 700;
        letter-spacing: .08em;
        text-transform: uppercase;
        color: #6f84a2;
    }
    .control-room-finance-chip strong {
        font-size: 15px;
        line-height: 1;
        color: var(--ph-color-text);
    }
    .control-room-finance-bars {
        display: grid;
        gap: 6px;
    }
    .control-room-finance-bar-row {
        display: grid;
        gap: 4px;
    }
    .control-room-finance-bar-head {
        display: flex;
        justify-content: space-between;
        gap: 8px;
        color: var(--ph-color-text-soft);
        font-size: 10px;
    }
    .control-room-finance-bar-track {
        min-height: 8px;
        overflow: hidden;
        border-radius: 999px;
        background: #eef4fb;
    }
    .control-room-finance-bar-track span {
        display: block;
        height: 8px;
        border-radius: inherit;
        background: linear-gradient(90deg, rgba(79, 70, 229, 0.84), rgba(59, 130, 246, 0.92));
    }
    .control-room-finance-bar-track.is-danger span {
        background: linear-gradient(90deg, #f97316, #ef4444);
    }
    .control-room-finance-bar-track.is-success span {
        background: linear-gradient(90deg, #22c55e, #16a34a);
    }
    .control-room-metric-strips {
        display: grid;
        gap: 10px;
    }
    .control-room-stack-compact {
        display: grid;
        gap: 4px;
    }
    .control-room-table-meter {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        min-width: 84px;
    }
    .control-room-table-meter strong {
        font-size: 10px;
        color: var(--ph-color-text);
        min-width: 18px;
    }
    .control-room-table-meter-bar {
        position: relative;
        flex: 1 1 auto;
        min-height: 4px;
        overflow: hidden;
        border-radius: 999px;
        background: rgba(148, 163, 184, 0.16);
    }
    .control-room-table-meter-bar span {
        display: block;
        height: 4px;
        border-radius: inherit;
        background: linear-gradient(90deg, rgba(79, 70, 229, 0.78), rgba(59, 130, 246, 0.84));
    }
    .control-room-metric-strips .dashboard-insight-row {
        padding: 0;
    }
    @media (max-width: 1280px) {
        .control-room-header {
            grid-template-columns: 1fr;
        }
        .control-room-header-side,
        .control-room-header-side .dashboard-hero-actions,
        .control-room-header-side .dashboard-quick-actions-grid {
            justify-items: start;
            justify-content: flex-start;
        }
        .control-room-executive-grid {
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
        }
        .control-room-executive-grid > :first-child {
            grid-column: 1 / -1;
        }
    }
    @media (max-width: 1180px) {
        .control-room-priority-grid,
        .control-room-reference-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .control-room-executive-grid,
        .control-room-grid,
        .control-room-pipeline-bottom,
        .control-room-donut-shell {
            grid-template-columns: 1fr;
        }
        .sales-pulse-metrics {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
        .revenue-center-summary-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .revenue-decision-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .sales-pulse-breakdown-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .sales-pulse-analytics,
        .sales-pulse-bottom-grid {
            grid-template-columns: 1fr;
        }
        .revenue-center-activity-list {
            grid-template-columns: 1fr;
        }
        .executive-intel-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .executive-intel-grid .executive-micro-card:last-child {
            grid-column: 1 / -1;
        }
        .executive-activity-strip {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
    }
    @media (max-width: 900px) {
        .control-room-pipeline-track {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
        .operations-health-grid,
        .inventory-health-grid,
        .inventory-readiness-grid,
        .inventory-warehouse-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .operations-layout-grid {
            grid-template-columns: 1fr;
        }
        .operations-command-grid {
            grid-template-columns: 1fr;
        }
        .activity-control-grid {
            grid-template-columns: 1fr;
        }
        .executive-health-strip {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .executive-intel-grid {
            grid-template-columns: 1fr;
        }
        .executive-intel-grid .executive-micro-card:last-child {
            grid-column: auto;
        }
        .operations-snapshot-strip {
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }
        .inventory-layout-grid,
        .inventory-donut-shell {
            grid-template-columns: 1fr;
        }
        .control-room-pipeline-stage::after {
            display: none;
        }
        .control-room-aging-legend {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .control-room-activity-list,
        .control-room-finance-hero {
            grid-template-columns: 1fr;
        }
        .sales-pulse-header,
        .sales-pulse-card-head {
            flex-direction: column;
            align-items: flex-start;
        }
        .sales-pulse-actions {
            justify-content: flex-start;
        }
        .sales-pulse-donut-layout {
            grid-template-columns: 1fr;
            justify-items: center;
        }
        .sales-pulse-status-list {
            width: 100%;
        }
        .dashboard-trend-vertical {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
    }
    @media (max-width: 720px) {
        .control-room-header,
        .control-room-card,
        .control-room-priority-card {
            padding: 12px;
        }
        .control-room-priority-grid,
        .control-room-reference-grid,
        .control-room-stat-grid,
        .control-room-pipeline-track,
        .operations-health-grid,
        .inventory-health-grid,
        .inventory-readiness-grid,
        .inventory-warehouse-grid {
            grid-template-columns: 1fr;
        }
        .control-room-title-row h1 {
            font-size: 20px;
        }
        .control-room-risk-table,
        .control-room-workload-table,
        .operations-capacity-table-wrap {
            display: block;
            overflow-x: auto;
            white-space: nowrap;
        }
        .operations-snapshot-strip {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .activity-control-header,
        .activity-control-links {
            justify-content: flex-start;
        }
        .activity-control-kpis,
        .activity-control-queue {
            grid-template-columns: 1fr;
        }
        .executive-health-strip,
        .executive-activity-strip {
            grid-template-columns: 1fr;
        }
        .executive-performance-row {
            grid-template-columns: minmax(0, 1fr) auto;
        }
        .executive-sparkline {
            grid-column: 1 / -1;
            width: 100%;
        }
        .activity-control-item {
            grid-template-columns: auto minmax(0, 1fr);
        }
        .activity-control-side {
            grid-column: 2;
            justify-items: start;
            text-align: left;
        }
        .control-room-donut {
            margin: 0 auto;
        }
        .sales-pulse-metrics,
        .revenue-center-summary-grid,
        .revenue-decision-grid,
        .sales-pulse-breakdown-grid {
            grid-template-columns: 1fr;
        }
        .revenue-center-action-table {
            display: block;
            overflow-x: auto;
            white-space: nowrap;
        }
        .revenue-center-action-head,
        .revenue-center-action-row {
            min-width: 620px;
        }
        .sales-pulse-table-head,
        .sales-pulse-table-row {
            grid-template-columns: minmax(0, 1fr) 92px 56px;
        }
        .sales-pulse-invoice-table {
            display: block;
            overflow-x: auto;
            white-space: nowrap;
        }
        .sales-pulse-invoice-head,
        .sales-pulse-invoice-row {
            min-width: 560px;
        }
        .dashboard-trend-vertical {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
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
        color: #3f5878;
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
    .dashboard-insight-row {
        display: grid;
        gap: 8px;
    }
    .dashboard-insight-row-heading {
        color: #3f5878;
        font-size: 11px;
        font-weight: 800;
        letter-spacing: .08em;
        text-transform: uppercase;
        padding-inline: 2px;
    }
    .dashboard-kpi-grid {
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    }
    .dashboard-priority-grid,
    .dashboard-sales-grid {
        grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
    }
    .dashboard-sales-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        align-items: stretch;
    }
    .sales-pulse-shell {
        gap: 14px;
    }
    .sales-pulse-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 12px;
    }
    .sales-pulse-heading {
        display: flex;
        align-items: flex-start;
        gap: 10px;
    }
    .sales-pulse-heading-icon {
        width: 30px;
        height: 30px;
        border-radius: 11px;
        display: grid;
        place-items: center;
        background: linear-gradient(135deg, rgba(79, 70, 229, 0.12), rgba(14, 165, 233, 0.14));
        color: #4f46e5;
        border: 1px solid rgba(79, 70, 229, 0.15);
        flex: 0 0 30px;
    }
    .sales-pulse-heading-icon svg {
        width: 15px;
        height: 15px;
    }
    .sales-pulse-actions {
        display: flex;
        flex-wrap: wrap;
        justify-content: flex-end;
        gap: 8px;
    }
    .sales-pulse-chip {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        min-height: 30px;
        padding: 0 11px;
        border-radius: 10px;
        border: 1px solid var(--ph-color-border);
        background: #fff;
        color: var(--ph-color-text);
        font-size: 11px;
        font-weight: 700;
        text-decoration: none;
    }
    .sales-pulse-chip svg {
        width: 13px;
        height: 13px;
    }
    .sales-pulse-metrics {
        display: grid;
        grid-template-columns: repeat(6, minmax(0, 1fr));
        gap: 10px;
    }
    .sales-pulse-metric-card {
        display: grid;
        gap: 8px;
        min-height: 112px;
        padding: 12px 13px;
        border-radius: 18px;
        border: 1px solid rgba(226, 232, 240, 0.9);
        background: linear-gradient(180deg, rgba(255,255,255,0.98), rgba(247,250,252,0.92));
        box-shadow: 0 14px 28px rgba(11, 35, 66, 0.06);
        text-decoration: none;
        color: inherit;
    }
    .sales-pulse-metric-card:hover {
        transform: translateY(-1px);
        box-shadow: 0 16px 30px rgba(11, 35, 66, 0.08);
    }
    .sales-pulse-metric-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 10px;
    }
    .sales-pulse-metric-label {
        display: block;
        color: #425c7f;
        font-size: 10px;
        font-weight: 800;
        letter-spacing: .08em;
        text-transform: uppercase;
        line-height: 1.25;
    }
    .sales-pulse-metric-icon {
        width: 28px;
        height: 28px;
        border-radius: 10px;
        display: grid;
        place-items: center;
        background: var(--ph-color-surface-soft);
        border: 1px solid var(--ph-color-border);
        color: var(--ph-color-primary);
        flex: 0 0 28px;
    }
    .sales-pulse-metric-icon svg {
        width: 13px;
        height: 13px;
    }
    .sales-pulse-metric-card.is-success .sales-pulse-metric-icon {
        color: var(--ph-color-success);
        background: var(--ph-color-success-soft);
        border-color: rgba(14, 159, 75, 0.18);
    }
    .sales-pulse-metric-card.is-warning .sales-pulse-metric-icon {
        color: var(--ph-color-warning);
        background: var(--ph-color-warning-soft);
        border-color: rgba(183, 121, 31, 0.18);
    }
    .sales-pulse-metric-card.is-danger .sales-pulse-metric-icon {
        color: var(--ph-color-danger);
        background: var(--ph-color-danger-soft);
        border-color: rgba(179, 13, 35, 0.18);
    }
    .sales-pulse-metric-card.is-info .sales-pulse-metric-icon {
        color: var(--ph-color-primary);
        background: var(--ph-color-info-soft);
        border-color: rgba(23, 119, 189, 0.18);
    }
    .sales-pulse-metric-value {
        color: var(--ph-color-text);
        font-size: 17px;
        font-weight: 780;
        line-height: 1.03;
        letter-spacing: -.03em;
    }
    .sales-pulse-metric-subtitle {
        color: #425c7f;
        font-size: 10px;
        line-height: 1.35;
    }
    .sales-pulse-metric-note {
        color: var(--ph-color-primary);
        font-size: 10px;
        line-height: 1.35;
        font-weight: 700;
    }
    .sales-pulse-analytics {
        display: grid;
        grid-template-columns: minmax(0, 1.55fr) minmax(0, .85fr);
        gap: 12px;
    }
    .sales-pulse-chart-card,
    .sales-pulse-side-card,
    .sales-pulse-bottom-card {
        border: 1px solid rgba(226, 232, 240, 0.9);
        border-radius: 18px;
        background: #fff;
        box-shadow: 0 12px 24px rgba(11, 35, 66, 0.05);
        padding: 12px 14px;
    }
    .sales-pulse-bottom-card {
        align-self: start;
        height: auto;
    }
    .sales-pulse-chart-stack {
        display: grid;
        gap: 12px;
    }
    .sales-pulse-card-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 10px;
        margin-bottom: 10px;
    }
    .sales-pulse-card-title {
        color: var(--ph-color-text);
        font-size: 13px;
        font-weight: 800;
        line-height: 1.25;
    }
    .sales-pulse-card-copy {
        color: #425c7f;
        font-size: 10px;
        line-height: 1.35;
        margin-top: 3px;
    }
    .sales-pulse-card-link {
        color: var(--ph-color-primary);
        font-size: 10px;
        font-weight: 700;
        text-decoration: none;
        white-space: nowrap;
    }
    .sales-pulse-legend {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        margin-bottom: 10px;
    }
    .sales-pulse-legend-item {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        color: #425c7f;
        font-size: 10px;
        font-weight: 700;
    }
    .sales-pulse-legend-swatch {
        width: 10px;
        height: 10px;
        border-radius: 999px;
        display: inline-block;
    }
    .sales-pulse-legend-swatch.is-sales {
        background: #4f46e5;
    }
    .sales-pulse-legend-swatch.is-rental {
        background: #16a34a;
    }
    .sales-pulse-legend-swatch.is-orders {
        background: #fca5a5;
        border-radius: 3px;
    }
    .sales-pulse-chart-shell {
        border-radius: 16px;
        background: linear-gradient(180deg, rgba(248,250,252,0.78), rgba(255,255,255,0.96));
        padding: 10px 12px 8px;
        border: 1px solid rgba(226, 232, 240, 0.8);
    }
    .sales-pulse-chart-svg {
        display: block;
        width: 100%;
        height: auto;
    }
    .sales-pulse-chart-grid {
        stroke: rgba(148, 163, 184, 0.18);
        stroke-width: 1;
    }
    .sales-pulse-chart-axis {
        fill: #64748b;
        font-size: 10px;
        font-weight: 700;
    }
    .sales-pulse-chart-axis.is-value {
        fill: #425c7f;
        font-size: 9px;
    }
    .sales-pulse-chart-bar {
        fill: rgba(248, 113, 113, 0.65);
    }
    .sales-pulse-chart-line-sales {
        fill: none;
        stroke: #4f46e5;
        stroke-width: 2.5;
        stroke-linecap: round;
        stroke-linejoin: round;
    }
    .sales-pulse-chart-line-rental {
        fill: none;
        stroke: #16a34a;
        stroke-width: 2;
        stroke-linecap: round;
        stroke-linejoin: round;
    }
    .sales-pulse-chart-dot-sales {
        fill: #4f46e5;
    }
    .sales-pulse-chart-dot-rental {
        fill: #16a34a;
    }
    .sales-pulse-chart-value {
        fill: #425c7f;
        font-size: 9px;
        font-weight: 700;
    }
    .sales-pulse-summary-strip {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 10px;
        margin-top: 10px;
    }
    .sales-pulse-summary-item {
        display: grid;
        gap: 5px;
        padding: 10px 11px;
        border-radius: 14px;
        border: 1px solid rgba(226, 232, 240, 0.9);
        background: #fff;
    }
    .sales-pulse-summary-item strong {
        font-size: 10px;
        color: #425c7f;
        text-transform: uppercase;
        letter-spacing: .07em;
    }
    .sales-pulse-summary-item span {
        font-size: 18px;
        font-weight: 780;
        line-height: 1.05;
        color: var(--ph-color-text);
    }
    .sales-pulse-summary-item small {
        color: #425c7f;
        font-size: 10px;
        line-height: 1.35;
    }
    .revenue-center-summary-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 10px;
    }
    .revenue-center-summary-grid .sales-pulse-metric-card {
        min-height: 102px;
    }
    .revenue-decision-grid {
        display: grid;
        grid-template-columns: minmax(0, 1.1fr) minmax(0, .9fr) minmax(0, .9fr) minmax(280px, .9fr);
        gap: 10px;
        align-items: stretch;
    }
    .revenue-decision-card {
        display: grid;
        gap: 8px;
        min-width: 0;
        padding: 12px;
        border-radius: 14px;
        border: 1px solid rgba(226, 232, 240, 0.92);
        background: #fff;
        box-shadow: 0 12px 26px rgba(15, 23, 42, 0.05);
    }
    .revenue-decision-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 10px;
    }
    .revenue-decision-title {
        color: var(--ph-color-text);
        font-size: 13px;
        font-weight: 900;
        letter-spacing: -.02em;
    }
    .revenue-decision-total {
        color: var(--ph-color-text);
        font-size: 18px;
        font-weight: 900;
        line-height: 1;
        white-space: nowrap;
    }
    .revenue-composition-bar {
        display: flex;
        height: 10px;
        overflow: hidden;
        border-radius: 999px;
        background: #eef4fb;
    }
    .revenue-composition-bar span {
        min-width: 4px;
    }
    .revenue-composition-bar .is-blue { background: #3b82f6; }
    .revenue-composition-bar .is-green { background: #22c55e; }
    .revenue-composition-bar .is-amber { background: #f59e0b; }
    .revenue-composition-bar .is-violet { background: #7c3aed; }
    .revenue-decision-list {
        display: grid;
        gap: 6px;
    }
    .revenue-decision-row {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 8px;
        align-items: center;
        color: inherit;
        text-decoration: none;
    }
    .revenue-decision-row span {
        overflow: hidden;
        color: #526b90;
        font-size: 11px;
        font-weight: 800;
        line-height: 1.25;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .revenue-decision-row strong {
        color: var(--ph-color-text);
        font-size: 12px;
        font-weight: 900;
        white-space: nowrap;
    }
    .vendor-performance-empty {
        display: grid;
        gap: 4px;
        align-content: center;
        min-height: 86px;
        color: #526b90;
        font-size: 12px;
        line-height: 1.35;
    }
    .vendor-performance-empty strong {
        color: var(--ph-color-text);
        font-size: 13px;
    }
    .revenue-center-action-table {
        display: grid;
        gap: 2px;
    }
    .revenue-center-action-head,
    .revenue-center-action-row {
        display: grid;
        grid-template-columns: minmax(0, 1.75fr) 112px 92px 84px 96px;
        gap: 10px;
        align-items: center;
    }
    .revenue-center-action-head {
        color: #64748b;
        font-size: 10px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: .06em;
        padding-bottom: 6px;
        border-bottom: 1px solid rgba(226, 232, 240, 0.9);
    }
    .revenue-center-action-row {
        padding: 8px 0;
        border-top: 1px solid rgba(241, 245, 249, 0.95);
    }
    .revenue-center-action-row:first-of-type {
        border-top: 0;
        padding-top: 0;
    }
    .revenue-center-action-copy,
    .revenue-center-action-copy strong,
    .revenue-center-action-copy small {
        min-width: 0;
    }
    .revenue-center-action-copy {
        display: grid;
        gap: 2px;
    }
    .revenue-center-action-copy strong,
    .revenue-center-action-row span,
    .revenue-center-action-row small {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        font-size: 10px;
        line-height: 1.35;
    }
    .revenue-center-action-copy strong {
        color: var(--ph-color-text);
    }
    .revenue-center-action-row span,
    .revenue-center-action-row small {
        color: #425c7f;
    }
    .revenue-center-activity-list {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px;
    }
    .revenue-center-activity-item {
        display: grid;
        gap: 3px;
        padding: 9px 10px;
        border-radius: 14px;
        border: 1px solid rgba(226, 232, 240, 0.9);
        background: linear-gradient(180deg, rgba(255,255,255,0.98), rgba(248,250,252,0.9));
        text-decoration: none;
        color: inherit;
    }
    .revenue-center-activity-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
    }
    .revenue-center-activity-top strong,
    .revenue-center-activity-top span {
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .revenue-center-activity-top strong {
        font-size: 11px;
        color: var(--ph-color-text);
    }
    .revenue-center-activity-top span {
        font-size: 9px;
        color: #64748b;
    }
    .revenue-center-activity-item small,
    .revenue-center-activity-item em {
        font-size: 10px;
        line-height: 1.35;
        color: #425c7f;
        font-style: normal;
    }
    .sales-pulse-side-stack {
        display: grid;
        gap: 12px;
    }
    .sales-pulse-donut-layout {
        display: grid;
        grid-template-columns: auto minmax(0, 1fr);
        gap: 12px;
        align-items: center;
    }
    .sales-pulse-donut {
        --collected-angle: calc(var(--collected-percent, 0) * 3.6deg);
        --outstanding-angle: calc(var(--outstanding-percent, 0) * 3.6deg);
        width: 118px;
        height: 118px;
        border-radius: 999px;
        background:
            radial-gradient(circle at center, #ffffff 0 57%, transparent 58%),
            conic-gradient(
                #16a34a 0 var(--collected-angle),
                #fb923c var(--collected-angle) calc(var(--collected-angle) + var(--outstanding-angle)),
                #ef4444 calc(var(--collected-angle) + var(--outstanding-angle)) 360deg
            );
        position: relative;
        border: 1px solid rgba(148, 163, 184, 0.16);
    }
    .sales-pulse-donut-center {
        position: absolute;
        inset: 0;
        display: grid;
        place-items: center;
        text-align: center;
        padding: 0 14px;
    }
    .sales-pulse-donut-center strong {
        display: block;
        font-size: 11px;
        color: #425c7f;
        line-height: 1.15;
    }
    .sales-pulse-donut-center span {
        display: block;
        margin-top: 4px;
        font-size: 20px;
        font-weight: 780;
        color: var(--ph-color-text);
        line-height: 1;
    }
    .sales-pulse-status-list {
        display: grid;
        gap: 8px;
    }
    .sales-pulse-status-row {
        display: grid;
        grid-template-columns: auto 1fr auto;
        gap: 8px;
        align-items: center;
        font-size: 10px;
        color: var(--ph-color-text);
    }
    .sales-pulse-status-row strong {
        text-align: right;
    }
    .sales-pulse-status-dot {
        width: 10px;
        height: 10px;
        border-radius: 999px;
        display: inline-block;
    }
    .sales-pulse-status-dot.is-success {
        background: #16a34a;
    }
    .sales-pulse-status-dot.is-warning {
        background: #fb923c;
    }
    .sales-pulse-status-dot.is-danger {
        background: #ef4444;
    }
    .sales-pulse-efficiency {
        margin-top: 10px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        padding: 10px 12px;
        border-radius: 12px;
        background: linear-gradient(90deg, rgba(22,163,74,0.08), rgba(255,255,255,0.95));
        color: var(--ph-color-text);
        font-size: 11px;
        font-weight: 700;
    }
    .revenue-protection-meter {
        margin-top: 10px;
        display: grid;
        gap: 6px;
    }
    .revenue-protection-meter-top {
        display: flex;
        justify-content: space-between;
        gap: 10px;
        color: #425c7f;
        font-size: 10px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: .06em;
    }
    .revenue-protection-meter-track {
        height: 8px;
        overflow: hidden;
        border-radius: 999px;
        background: rgba(226, 232, 240, 0.95);
    }
    .revenue-protection-meter-track span {
        display: block;
        height: 100%;
        border-radius: inherit;
        background: linear-gradient(90deg, #16a34a, #60a5fa);
    }
    .sales-pulse-table {
        display: grid;
        gap: 8px;
    }
    .sales-pulse-table-head,
    .sales-pulse-table-row {
        display: grid;
        grid-template-columns: minmax(0, 1.2fr) minmax(78px, .9fr) 68px;
        gap: 10px;
        align-items: center;
    }
    .sales-pulse-table-head {
        color: #64748b;
        font-size: 10px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: .06em;
        padding-bottom: 6px;
        border-bottom: 1px solid rgba(226, 232, 240, 0.9);
    }
    .sales-pulse-table-row {
        padding-top: 8px;
        border-top: 1px solid rgba(241, 245, 249, 0.95);
    }
    .sales-pulse-table-row:first-of-type {
        border-top: 0;
        padding-top: 0;
    }
    .sales-pulse-table-row strong {
        display: block;
        color: var(--ph-color-text);
        font-size: 11px;
        line-height: 1.3;
    }
    .sales-pulse-table-row span,
    .sales-pulse-table-row small {
        display: block;
        color: #425c7f;
        font-size: 10px;
        line-height: 1.35;
    }
    .sales-pulse-table-amount {
        display: grid;
        gap: 4px;
    }
    .sales-pulse-table-track {
        width: 100%;
        height: 6px;
        border-radius: 999px;
        background: #eef2f7;
        overflow: hidden;
    }
    .sales-pulse-table-fill {
        height: 100%;
        border-radius: inherit;
        background: linear-gradient(90deg, rgba(248,113,113,0.6), rgba(251,146,60,0.78));
    }
    .sales-pulse-bottom-grid {
        display: grid;
        grid-template-columns: minmax(0, .48fr) minmax(0, 1.52fr);
        gap: 12px;
        align-items: start;
        grid-auto-rows: min-content;
    }
    .sales-pulse-bottom-card--strip .sales-pulse-card-head {
        margin-bottom: 8px;
    }
    .sales-pulse-breakdown-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0;
        border: 1px solid rgba(226, 232, 240, 0.9);
        border-radius: 16px;
        background: linear-gradient(180deg, rgba(255,255,255,0.98), rgba(248,250,252,0.9));
        overflow: hidden;
    }
    .sales-pulse-breakdown-item {
        display: grid;
        gap: 3px;
        align-content: start;
        padding: 6px 8px;
        border-left: 1px solid rgba(226, 232, 240, 0.9);
        border-top: 1px solid rgba(226, 232, 240, 0.9);
        background: transparent;
    }
    .sales-pulse-breakdown-item:first-child {
        border-left: 0;
        border-top: 0;
    }
    .sales-pulse-breakdown-item:nth-child(2) {
        border-top: 0;
    }
    .sales-pulse-breakdown-item:nth-child(odd) {
        border-left: 0;
    }
    .sales-pulse-breakdown-top {
        display: flex;
        align-items: center;
        gap: 5px;
    }
    .sales-pulse-breakdown-icon {
        width: 20px;
        height: 20px;
        border-radius: 7px;
        display: grid;
        place-items: center;
        border: 1px solid var(--ph-color-border);
        background: var(--ph-color-surface-soft);
        color: var(--ph-color-primary);
        flex: 0 0 20px;
    }
    .sales-pulse-breakdown-icon svg {
        width: 10px;
        height: 10px;
    }
    .sales-pulse-breakdown-item.is-success .sales-pulse-breakdown-icon {
        color: var(--ph-color-success);
        background: var(--ph-color-success-soft);
    }
    .sales-pulse-breakdown-item.is-warning .sales-pulse-breakdown-icon {
        color: var(--ph-color-warning);
        background: var(--ph-color-warning-soft);
    }
    .sales-pulse-breakdown-item.is-danger .sales-pulse-breakdown-icon {
        color: var(--ph-color-danger);
        background: var(--ph-color-danger-soft);
    }
    .sales-pulse-breakdown-item.is-info .sales-pulse-breakdown-icon {
        color: var(--ph-color-primary);
        background: var(--ph-color-info-soft);
    }
    .sales-pulse-breakdown-item strong {
        display: block;
        color: #425c7f;
        font-size: 6.5px;
        font-weight: 800;
        letter-spacing: .07em;
        text-transform: uppercase;
    }
    .sales-pulse-breakdown-item span {
        color: var(--ph-color-text);
        font-size: 10px;
        font-weight: 780;
        line-height: 1.05;
    }
    .sales-pulse-breakdown-item small {
        color: #425c7f;
        font-size: 7px;
        line-height: 1.2;
    }
    .sales-pulse-invoice-table {
        display: grid;
        gap: 2px;
    }
    .sales-pulse-invoice-head,
    .sales-pulse-invoice-row {
        display: grid;
        grid-template-columns: minmax(0, 2.85fr) minmax(0, .95fr) 84px 96px 108px;
        gap: 10px;
        align-items: center;
    }
    .sales-pulse-invoice-head {
        color: #64748b;
        font-size: 10px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: .06em;
        padding-bottom: 6px;
        border-bottom: 1px solid rgba(226, 232, 240, 0.9);
    }
    .sales-pulse-invoice-row {
        padding: 8px 0;
        border-top: 1px solid rgba(241, 245, 249, 0.95);
    }
    .sales-pulse-invoice-row:first-of-type {
        border-top: 0;
        padding-top: 0;
    }
    .sales-pulse-invoice-row strong,
    .sales-pulse-invoice-row span,
    .sales-pulse-invoice-row small {
        font-size: 10px;
        line-height: 1.35;
    }
    .sales-pulse-invoice-row strong {
        color: var(--ph-color-text);
    }
    .sales-pulse-invoice-row span,
    .sales-pulse-invoice-row small {
        color: #425c7f;
    }
    .sales-pulse-invoice-primary,
    .sales-pulse-invoice-customer {
        min-width: 0;
    }
    .sales-pulse-invoice-primary {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        white-space: nowrap;
        overflow: hidden;
    }
    .sales-pulse-invoice-inline {
        display: inline-block;
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .sales-pulse-invoice-inline.is-sale-no {
        flex: 0 0 auto;
    }
    .sales-pulse-invoice-inline.is-product {
        flex: 1 1 auto;
    }
    .sales-pulse-invoice-customer,
    .sales-pulse-invoice-date,
    .sales-pulse-invoice-amount,
    .sales-pulse-invoice-status {
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
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
        gap: 6px;
        min-height: 112px;
        padding: 12px 13px;
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
        gap: 10px;
    }
    .dashboard-kpi-label,
    .dashboard-card-label,
    .dashboard-logistics-label {
        color: #425c7f;
        display: block;
        max-width: calc(100% - 46px);
        font-size: 9px;
        font-weight: 700;
        letter-spacing: .07em;
        text-transform: uppercase;
        line-height: 1.25;
        overflow-wrap: anywhere;
        text-wrap: balance;
    }
    .dashboard-kpi-subtitle {
        color: #3f5878;
        font-size: 12px;
        line-height: 1.4;
        font-weight: 700;
        overflow-wrap: anywhere;
    }
    .dashboard-kpi-insight {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 11px;
        font-weight: 700;
        line-height: 1.35;
        color: var(--ph-color-primary);
    }
    .dashboard-kpi-insight.is-success {
        color: var(--ph-color-success);
    }
    .dashboard-kpi-insight.is-warning {
        color: var(--ph-color-warning);
    }
    .dashboard-kpi-insight.is-danger {
        color: var(--ph-color-danger);
    }
    .dashboard-kpi-insight.is-info {
        color: var(--ph-color-primary);
    }
    .dashboard-kpi-icon,
    .dashboard-card-icon {
        width: 24px;
        height: 24px;
        border-radius: 8px;
        display: grid;
        place-items: center;
        background: var(--ph-color-surface-soft);
        color: var(--ph-color-primary);
        border: 1px solid var(--ph-color-border);
        flex: 0 0 24px;
    }
    .dashboard-kpi-icon svg,
    .dashboard-card-icon svg {
        width: 11px;
        height: 11px;
    }
    .dashboard-kpi-value,
    .dashboard-card-value {
        color: var(--ph-color-text);
        max-width: 100%;
        font-size: 16px;
        font-weight: 760;
        line-height: 1.02;
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
        color: #425c7f;
        font-size: 10px;
        line-height: 1.32;
        overflow-wrap: anywhere;
    }
    .dashboard-kpi-card {
        min-height: 102px;
        padding: 10px 11px;
    }
    .dashboard-priority-card,
    .dashboard-sales-card {
        min-height: 118px;
        padding: 10px 11px;
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
    .dashboard-kpi-card.is-success {
        border-color: rgba(14, 159, 75, 0.16);
    }
    .dashboard-kpi-card.is-warning {
        border-color: rgba(183, 121, 31, 0.18);
    }
    .dashboard-kpi-card.is-danger {
        border-color: rgba(179, 13, 35, 0.18);
    }
    .dashboard-kpi-card.is-info {
        border-color: rgba(23, 119, 189, 0.16);
    }
    .dashboard-finance-card {
        min-height: 68px;
        padding: 7px 8px;
    }
    .dashboard-finance-card .dashboard-card-icon {
        width: 20px;
        height: 20px;
        border-radius: 8px;
    }
    .dashboard-finance-card .dashboard-card-icon svg {
        width: 10px;
        height: 10px;
    }
    .dashboard-finance-card .dashboard-card-value {
        font-size: 12px;
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
        min-width: 28px;
        height: 24px;
        padding: 0 8px;
        border-radius: 8px;
        background: var(--ph-color-surface-soft);
        border: 1px solid var(--ph-color-border);
        color: var(--ph-color-text);
        font-size: 10px;
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
        color: #425c7f;
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
        grid-template-columns: 1fr;
        gap: 14px;
    }
    .dashboard-widget-grid,
    .dashboard-insight-grid,
    .dashboard-recent-grid,
    .dashboard-alert-grid {
        display: grid;
        gap: 14px;
    }
    .dashboard-widget-grid,
    .dashboard-insight-grid,
    .dashboard-recent-grid {
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    }
    .dashboard-alert-grid {
        grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
        gap: 10px;
    }
    .dashboard-alert-card,
    .dashboard-widget-card,
    .dashboard-feed-card {
        min-width: 0;
    }
    .dashboard-alert-card {
        display: grid;
        gap: 6px;
        min-height: 92px;
        padding: 12px 14px;
        border-radius: 16px;
        border: 1px solid var(--ph-color-border);
        background: #ffffff;
        box-shadow: var(--ph-shadow-soft);
        text-decoration: none;
        color: inherit;
    }
    .dashboard-alert-card:hover,
    .dashboard-widget-card:hover,
    .dashboard-feed-card:hover {
        transform: translateY(-1px);
        box-shadow: 0 16px 30px rgba(11, 35, 66, 0.1);
    }
    .dashboard-alert-top,
    .dashboard-section-heading {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 12px;
        flex-wrap: wrap;
    }
    .dashboard-alert-count {
        color: var(--ph-color-text);
        font-size: 24px;
        font-weight: 800;
        line-height: 1;
        letter-spacing: -0.04em;
    }
    .dashboard-quick-actions-grid {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        align-items: center;
    }
    .dashboard-anchor-nav {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }
    .dashboard-anchor-nav a {
        text-decoration: none;
    }
    .dashboard-quick-actions-grid .rx-btn,
    .dashboard-quick-actions-grid .rx-btn-secondary {
        min-width: 0;
    }
    .dashboard-snapshot-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        min-height: 56px;
        padding: 8px 10px;
        border-radius: 16px;
        border: 1px solid var(--ph-color-border);
        background: #ffffff;
        box-shadow: var(--ph-shadow-soft);
    }
    .dashboard-snapshot-item strong {
        display: block;
        color: var(--ph-color-text);
        font-size: 14px;
        line-height: 1.05;
        letter-spacing: -0.03em;
    }
    .dashboard-snapshot-item span {
        display: block;
        color: #425c7f;
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
        width: 24px;
        height: 24px;
        flex: 0 0 24px;
        border-radius: 10px;
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
        min-height: 86px;
        padding: 8px 10px;
        gap: 4px;
    }
    .dashboard-widget-list,
    .dashboard-feed-list {
        display: grid;
        gap: 6px;
    }
    .dashboard-widget-item,
    .dashboard-feed-item {
        display: grid;
        gap: 3px;
        min-width: 0;
        padding: 7px 0;
        border-top: 1px solid #e2e8f0;
    }
    .dashboard-widget-item:first-child,
    .dashboard-feed-item:first-child {
        border-top: 0;
        padding-top: 0;
    }
    .dashboard-widget-item strong,
    .dashboard-feed-item strong {
        display: block;
        color: #0f172a;
        font-size: 11px;
        line-height: 1.32;
        overflow-wrap: anywhere;
    }
    .dashboard-widget-item span,
    .dashboard-widget-item small,
    .dashboard-feed-item span,
    .dashboard-feed-item small {
        display: block;
        color: #64748b;
        font-size: 10px;
        line-height: 1.32;
        overflow-wrap: anywhere;
    }
    .dashboard-widget-eyebrow,
    .dashboard-feed-meta,
    .dashboard-feed-title {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        flex-wrap: wrap;
    }
    .dashboard-widget-eyebrow em,
    .dashboard-feed-meta em {
        font-style: normal;
        color: #425c7f;
        font-size: 10px;
        font-weight: 800;
        letter-spacing: .08em;
        text-transform: uppercase;
    }
    .dashboard-role-chip {
        display: inline-flex;
        align-items: center;
        padding: 3px 8px;
        border-radius: 999px;
        background: var(--ph-color-surface-soft);
        border: 1px solid var(--ph-color-border);
        color: #425c7f;
        font-size: 10px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: .06em;
    }
    .dashboard-widget-actions,
    .dashboard-feed-links {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }
    .dashboard-widget-actions a,
    .dashboard-feed-links a {
        color: var(--ph-color-primary);
        text-decoration: none;
        font-size: 11px;
        font-weight: 700;
    }
    .dashboard-overview-list,
    .dashboard-rank-list,
    .dashboard-trend-list,
    .dashboard-queue-list {
        display: grid;
        gap: 8px;
    }
    .dashboard-overview-item,
    .dashboard-inline-item,
    .dashboard-queue-item {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 12px;
        padding: 9px 0;
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
        font-size: 10px;
        line-height: 1.32;
        overflow-wrap: anywhere;
    }
    .dashboard-expandable {
        margin-top: 4px;
    }
    .dashboard-expandable-summary {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        cursor: pointer;
        color: var(--ph-color-primary);
        font-size: 10px;
        font-weight: 700;
        letter-spacing: .08em;
        text-transform: uppercase;
        list-style: none;
    }
    .dashboard-expandable-summary::-webkit-details-marker {
        display: none;
    }
    .dashboard-expandable-content {
        margin-top: 6px;
        display: grid;
        gap: 0;
    }
    .dashboard-feed-card .rx-card-copy,
    .dashboard-widget-card .rx-card-copy,
    .dashboard-trend-card .rx-card-copy {
        font-size: 11px;
        line-height: 1.35;
        color: #3f5878;
    }
    .dashboard-trend-legend {
        display: flex;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
        margin-bottom: 10px;
    }
    .dashboard-trend-legend-item {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        color: #3f5878;
        font-size: 10px;
        font-weight: 700;
    }
    .dashboard-trend-legend-swatch {
        width: 10px;
        height: 10px;
        border-radius: 999px;
        display: inline-block;
    }
    .dashboard-trend-legend-swatch.is-rental {
        background: #4f46e5;
    }
    .dashboard-trend-legend-swatch.is-sales {
        background: #16a34a;
    }
    .dashboard-trend-legend-swatch.is-orders {
        background: #94a3b8;
    }
    .dashboard-trend-vertical {
        display: grid;
        grid-template-columns: repeat(6, minmax(0, 1fr));
        gap: 12px;
        align-items: end;
    }
    .dashboard-trend-column {
        display: grid;
        gap: 8px;
        min-width: 0;
    }
    .dashboard-trend-bars {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 6px;
        align-items: end;
        min-height: 148px;
    }
    .dashboard-trend-bar-wrap {
        display: grid;
        gap: 6px;
        justify-items: center;
    }
    .dashboard-trend-bar-head {
        min-height: 40px;
        display: grid;
        justify-items: center;
        align-content: end;
        gap: 2px;
    }
    .dashboard-trend-bar-topline {
        width: 1px;
        min-height: 14px;
        background: rgba(148, 163, 184, 0.45);
    }
    .dashboard-trend-bar {
        width: 100%;
        min-height: 8px;
        border-radius: 12px 12px 4px 4px;
    }
    .dashboard-trend-bar.is-rental {
        background: linear-gradient(180deg, rgba(79, 70, 229, 0.9), rgba(79, 70, 229, 0.48));
    }
    .dashboard-trend-bar.is-sales {
        background: linear-gradient(180deg, rgba(14, 159, 75, 0.88), rgba(14, 159, 75, 0.44));
    }
    .dashboard-trend-bar.is-orders {
        background: linear-gradient(180deg, rgba(148, 163, 184, 0.92), rgba(191, 219, 254, 0.54));
    }
    .dashboard-trend-bar-label {
        font-size: 8px;
        font-weight: 700;
        color: #64748b;
        letter-spacing: .08em;
        text-transform: uppercase;
    }
    .dashboard-trend-bar-value {
        font-size: 10px;
        color: #0f172a;
        line-height: 1.3;
        text-align: center;
        font-weight: 700;
    }
    .dashboard-trend-bar-meta {
        font-size: 8px;
        line-height: 1.15;
        text-align: center;
        color: #64748b;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .05em;
    }
    .dashboard-trend-month {
        text-align: center;
        color: #0f172a;
        font-size: 12px;
        font-weight: 700;
        line-height: 1.2;
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
    .fieldops-home {
        display:grid;
        gap:10px;
    }
    .fieldops-home-hero {
        display:grid;
        gap:10px;
        padding:12px;
        border-radius:16px;
        border:1px solid #dbe3ef;
        background:linear-gradient(180deg, #f8fbff 0%, #ffffff 100%);
        box-shadow:var(--ph-shadow-soft);
    }
    .fieldops-home-hero-top {
        display:grid;
        grid-template-columns:72px minmax(0, 1fr);
        gap:12px;
        align-items:center;
    }
    .fieldops-home-hero-ill {
        width:62px;
        height:62px;
        border-radius:16px;
        overflow:hidden;
        border:1px solid #bfdbfe;
        background:#eff6ff;
    }
    .fieldops-home-hero-ill svg {
        width:100%;
        height:100%;
        display:block;
    }
    .fieldops-home-hero-copy {
        min-width:0;
        display:grid;
        gap:3px;
    }
    .fieldops-home-hero-copy strong {
        color:#0f172a;
        font-size:20px;
        line-height:1.1;
    }
    .fieldops-home-hero-copy p {
        margin:0;
        color:#64748b;
        font-size:12px;
        line-height:1.45;
    }
    .fieldops-home-kpis {
        display:grid;
        grid-template-columns:repeat(2, minmax(0, 1fr));
        gap:8px;
    }
    .fieldops-home-kpi {
        display:grid;
        gap:6px;
        min-height:72px;
        padding:9px 10px;
        border-radius:14px;
        border:1px solid #dbe3ef;
        background:#fff;
        text-decoration:none;
        box-shadow:var(--ph-shadow-soft);
    }
    .fieldops-home-kpi.is-blue .fieldops-home-kpi-icon { background:#eff6ff; color:#1d4ed8; }
    .fieldops-home-kpi.is-amber .fieldops-home-kpi-icon { background:#fff7ed; color:#b45309; }
    .fieldops-home-kpi.is-red .fieldops-home-kpi-icon { background:#fff1f2; color:#b91c1c; }
    .fieldops-home-kpi.is-green .fieldops-home-kpi-icon { background:#f0fdf4; color:#166534; }
    .fieldops-home-kpi-top {
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:8px;
    }
    .fieldops-home-kpi-label {
        color:#64748b;
        font-size:10px;
        font-weight:800;
        letter-spacing:.05em;
        text-transform:uppercase;
    }
    .fieldops-home-kpi-icon {
        width:26px;
        height:26px;
        border-radius:10px;
        display:grid;
        place-items:center;
        background:#eff6ff;
        color:#1d4ed8;
    }
    .fieldops-home-kpi-icon svg {
        width:14px;
        height:14px;
    }
    .fieldops-home-kpi-value {
        color:#0f172a;
        font-size:22px;
        font-weight:900;
        line-height:1;
    }
    .fieldops-home-kpi-copy {
        color:#64748b;
        font-size:11px;
        line-height:1.35;
    }
    .fieldops-home-section {
        display:grid;
        gap:10px;
        padding:10px 11px;
        border-radius:14px;
        border:1px solid #dbe3ef;
        background:#fff;
        box-shadow:var(--ph-shadow-soft);
    }
    .fieldops-home-section-head {
        display:flex;
        align-items:flex-start;
        justify-content:space-between;
        gap:10px;
    }
    .fieldops-home-section-head h2 {
        margin:0;
        color:#0f172a;
        font-size:15px;
        line-height:1.25;
    }
    .fieldops-home-section-head p {
        margin:3px 0 0;
        color:#64748b;
        font-size:11.5px;
        line-height:1.45;
    }
    .fieldops-home-list {
        display:grid;
        gap:9px;
    }
    .fieldops-home-item {
        display:grid;
        gap:5px;
        padding:8px 0;
        border-top:1px solid #eef2f7;
    }
    .fieldops-home-item:first-child {
        padding-top:0;
        border-top:0;
    }
    .fieldops-home-item-top {
        display:flex;
        align-items:center;
        gap:8px;
        flex-wrap:wrap;
        min-width:0;
    }
    .fieldops-home-item-top strong {
        color:#0f172a;
        font-size:12px;
        line-height:1.35;
        min-width:0;
        overflow:hidden;
        text-overflow:ellipsis;
        white-space:nowrap;
    }
    .fieldops-home-item-top span {
        color:#64748b;
        font-size:10px;
        font-weight:800;
        letter-spacing:.05em;
        text-transform:uppercase;
    }
    .fieldops-home-item-chip {
        display:inline-flex;
        align-items:center;
        padding:4px 8px;
        border-radius:999px;
        background:#eff6ff;
        color:#1d4ed8;
        font-size:10px;
        font-weight:800;
        letter-spacing:.04em;
        text-transform:uppercase;
    }
    .fieldops-home-item p,
    .fieldops-home-item small {
        margin:0;
        color:#64748b;
        font-size:11px;
        line-height:1.45;
        overflow-wrap:anywhere;
    }
    .fieldops-home-links {
        display:flex;
        gap:8px;
        flex-wrap:wrap;
    }
    .fieldops-home-links a {
        color:var(--ph-color-primary);
        text-decoration:none;
        font-size:11px;
        font-weight:700;
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
        .dashboard-shell.is-delivery-focused .dashboard-hero-summary {
            display: none;
        }
        .fieldops-home-hero-top {
            grid-template-columns:56px minmax(0, 1fr);
        }
        .fieldops-home-hero-ill {
            width:56px;
            height:56px;
            border-radius:16px;
        }
        .fieldops-home-hero-copy strong {
            font-size:18px;
        }
        .fieldops-home-hero-copy p {
            font-size:12px;
        }
        .fieldops-home-kpis {
            gap:8px;
        }
        .fieldops-home-kpi {
            min-height:76px;
            padding:9px 10px;
            border-radius:16px;
        }
        .fieldops-home-kpi-value {
            font-size:21px;
        }
        .fieldops-home-section {
            padding:11px 12px;
            border-radius:16px;
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
        .dashboard-quick-actions-grid {
            width: 100%;
        }
        .dashboard-quick-actions-grid > * {
            flex: 1 1 calc(50% - 6px);
            min-width: 0;
        }
        .dashboard-kpi-grid,
        .dashboard-priority-grid,
        .dashboard-sales-grid,
        .dashboard-finance-grid,
        .dashboard-logistics-grid,
        .dashboard-snapshot-grid,
        .dashboard-widget-grid,
        .dashboard-insight-grid,
        .dashboard-recent-grid,
        .dashboard-alert-grid {
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
        .dashboard-shell.is-delivery-focused .dashboard-kpi-grid,
        .dashboard-shell.is-delivery-focused .dashboard-logistics-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .dashboard-shell.is-delivery-focused .dashboard-kpi-card,
        .dashboard-shell.is-delivery-focused .dashboard-logistics-card {
            min-height: 60px;
            padding: 7px;
            gap: 5px;
        }
        .dashboard-shell.is-delivery-focused .dashboard-kpi-note,
        .dashboard-shell.is-delivery-focused .dashboard-kpi-subtitle {
            display: none;
        }
        .dashboard-shell.is-delivery-focused .dashboard-kpi-label,
        .dashboard-shell.is-delivery-focused .dashboard-logistics-label {
            font-size: 9px;
            line-height: 1.25;
        }
        .dashboard-shell.is-delivery-focused .dashboard-kpi-value,
        .dashboard-shell.is-delivery-focused .dashboard-card-value {
            font-size: 18px;
            line-height: 1.05;
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
        .dashboard-trend-vertical {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="dashboard-shell rx-page {{ $isDeliveryFacingMenuRole ? 'is-delivery-focused' : '' }}">
    @if($isDeliveryFacingMenuRole)
        @php
            $fieldOpsTiles = collect($deliveryMiniTiles)->take(5)->values();
            $fieldOpsItems = collect($todayDeliverySummary ?? collect())
                ->concat(collect($todayPickupSummary ?? collect()))
                ->sortBy(fn ($task) => optional($task->scheduled_at)?->timestamp ?? PHP_INT_MAX)
                ->take(5)
                ->values();
            $fieldOpsIll = <<<'SVG'
                <svg viewBox="0 0 120 120" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <rect x="12" y="12" width="96" height="96" rx="26" fill="#EFF6FF"/>
                    <circle cx="56" cy="34" r="11" fill="#F8C9A7"/>
                    <path d="M43 52c0-5 4-9 9-9h8c5 0 9 4 9 9v19H43V52Z" fill="#2563EB"/>
                    <path d="M42 72h30c4 0 8 3 8 8v8H34v-8c0-5 4-8 8-8Z" fill="#1D4ED8"/>
                    <rect x="70" y="50" width="22" height="22" rx="4" fill="#F6D7A8" stroke="#D39A42" stroke-width="2"/>
                    <path d="M77 50v-8l8-4 7 4v8" stroke="#D39A42" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M41 88h41" stroke="#BFDBFE" stroke-width="4" stroke-linecap="round"/>
                </svg>
            SVG;
        @endphp
        <section class="fieldops-home">
            <div class="fieldops-home-hero">
                <div class="fieldops-home-hero-top">
                    <div class="fieldops-home-hero-ill">{!! $fieldOpsIll !!}</div>
                    <div class="fieldops-home-hero-copy">
                        <strong>Hi, Delivery Team</strong>
                        <p>Hereâ€™s your field work today.</p>
                    </div>
                </div>
                <div class="fieldops-home-kpis">
                    @foreach($fieldOpsTiles as $tile)
                        <a href="{{ $tile['href'] ?? '#' }}" class="fieldops-home-kpi is-{{ $tile['tone'] ?? 'blue' }}">
                            <div class="fieldops-home-kpi-top">
                                <span class="fieldops-home-kpi-label">{{ $tile['label'] }}</span>
                                <span class="fieldops-home-kpi-icon">{!! $dashboardIcon($tile['icon']) !!}</span>
                            </div>
                            <strong class="fieldops-home-kpi-value">{{ $tile['value'] }}</strong>
                            <span class="fieldops-home-kpi-copy">
                                {{ match ($tile['label']) {
                                    'My Assigned Tasks' => 'Open work',
                                    'My Deliveries Today' => 'Delivery run',
                                    'My Pickups Today' => 'Pickup run',
                                    'My Overdue Tasks' => 'Needs action',
                                    'Failed Attempts' => 'Retry queue',
                                    default => 'Open queue',
                                } }}
                            </span>
                        </a>
                    @endforeach
                </div>
            </div>

            <div class="fieldops-home-section">
                <div class="fieldops-home-section-head">
                    <div>
                        <h2>Todayâ€™s Task Flow</h2>
                        <p>Open the next delivery or pickup without hunting through long cards.</p>
                    </div>
                    <a href="{{ $myAssignedTasksUrl ?? $deliveriesIndexUrl ?? '#' }}" class="rx-btn-secondary">View All</a>
                </div>
                @if($fieldOpsItems->isNotEmpty())
                    <div class="fieldops-home-list">
                        @foreach($fieldOpsItems as $task)
                            <div class="fieldops-home-item">
                                <div class="fieldops-home-item-top">
                                    <strong>{{ ucfirst($task->type) }} #{{ $task->id }}</strong>
                                    <span class="fieldops-home-item-chip">{{ ucfirst(str_replace('_', ' ', $task->status)) }}</span>
                                    <span>{{ optional($task->scheduled_at)?->format('h:i A') ?? 'No time' }}</span>
                                </div>
                                <p>{{ $task->linkedCustomerName() }} â€¢ {{ optional($task->scheduled_at)?->format('d M') ?? 'Today' }}</p>
                                <small>{{ \Illuminate\Support\Str::limit(collect([$task->linkedCustomerAddress(), $task->linkedCustomerCity()])->filter()->implode(', '), 70) ?: 'Address pending' }}</small>
                                <div class="fieldops-home-links">
                                    <a href="{{ route('deliveries.show', $task) }}">Open</a>
                                    @if($task->linkedCustomerPhone())
                                        <a href="tel:{{ preg_replace('/\s+/', '', (string) $task->linkedCustomerPhone()) }}">Call</a>
                                    @endif
                                    @if($task->linkedCustomerMapUrl())
                                        <a href="{{ $task->linkedCustomerMapUrl() }}" target="_blank" rel="noopener">Map</a>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="rx-empty dashboard-empty">
                        <div class="rx-empty-icon">{!! $dashboardIcon('delivery') !!}</div>
                        <strong>No field tasks queued</strong>
                        <span>Your assigned delivery and pickup list is clear right now.</span>
                    </div>
                @endif
            </div>
        </section>
    @else
    <section class="control-room-shell">
        <div class="control-room-header">
            <div class="control-room-header-copy">
                <div class="control-room-meta-strip">
                    <span class="rx-eyebrow">Executive Command Center</span>
                    <span class="sr-only">PHOS Control Room</span>
                    @if($showExecutiveCompatibilityLabels)
                        <span class="sr-only">Cash &amp; Collections Overview</span>
                        <span class="sr-only">Rental Operations Pipeline</span>
                        <span class="sr-only">Operational Risk Board</span>
                        <span class="sr-only">Staff Workload Overview</span>
                        <span class="sr-only">Inventory Availability</span>
                        <span class="sr-only">Business Performance</span>
                        <span class="sr-only">Recent Activity</span>
                        <span class="sr-only">Inventory Intelligence</span>
                        <span class="sr-only">Add Business Partner</span>
                        <span class="sr-only">Schedule Pickup</span>
                        <span class="sr-only">Record Payment</span>
                        <span class="sr-only">Follow-ups Overdue</span>
                        <span class="sr-only">Renewals Overdue</span>
                        <span class="sr-only">Staff Overloaded</span>
                        <span class="sr-only">Operational Priorities</span>
                        <span class="sr-only">Revenue Protection</span>
                        <span class="sr-only">Inventory Readiness</span>
                        <span class="sr-only">Reference KPIs</span>
                        <span class="sr-only">Outstanding Invoices</span>
                        <span class="sr-only">Collections This Month</span>
                        <span class="sr-only">Unbilled Rentals</span>
                        <span class="sr-only">Unbilled Sales</span>
                        <span class="sr-only">Unpaid Renewal Invoices</span>
                        <span class="sr-only">Open Invoices</span>
                        <span class="sr-only">Collections Today</span>
                        <span class="sr-only">Rental Available</span>
                        <span class="sr-only">Sale Stock Available</span>
                        <span class="sr-only">Asset Alerts</span>
                        <span class="sr-only">Returns Expected</span>
                        <span class="sr-only">Total Customers</span>
                        <span class="sr-only">Products</span>
                    @endif
                    <span class="dashboard-hero-date">{{ $dashboardDateLabel }}</span>
                    <span class="control-room-status-line">Phase 1 structure pass for cash, rentals, Products, pending operations, and inventory health.</span>
                </div>
                <div class="control-room-title-row">
                    <h1>PHOS Executive Dashboard</h1>
                    <p>Short decision layer on top, detailed centers below.</p>
                </div>
            </div>

            <div class="control-room-header-side">
                <div class="dashboard-hero-actions">
                    <div class="dashboard-quick-actions-grid">
                        @foreach($dashboardQuickActions as $action)
                            <a href="{{ $action['href'] }}" class="{{ ($action['tone'] ?? 'secondary') === 'primary' ? 'rx-btn' : 'rx-btn-secondary' }}">{{ $action['label'] }}</a>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <div class="control-room-filter-dock">
            <details class="rx-card dashboard-filters-card">
                <summary class="rx-card-header">
                    <div>
                        <h2 class="rx-card-title">Filters</h2>
                        <p class="rx-card-copy">Date, city, fulfilment, and payment view.</p>
                    </div>
                    <span class="dashboard-filter-toggle" aria-hidden="true"></span>
                </summary>
                <div class="rx-card-body">
                    <form method="GET" action="{{ $dashboardUrl }}" class="rx-form-grid">
                        <div class="dashboard-filter-grid">
                            <label class="rx-field">
                                <span class="rx-label">From Date</span>
                                <input type="date" name="from_date" value="{{ $fromDate ?? '' }}" class="rn-input" />
                            </label>
                            <label class="rx-field">
                                <span class="rx-label">To Date</span>
                                <input type="date" name="to_date" value="{{ $toDate ?? '' }}" class="rn-input" />
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
                                <span class="rx-label">Fulfilment</span>
                                <select name="fulfilment_source" class="rn-input">
                                    <option value="">All</option>
                                    <option value="in_house" @selected(request('fulfilment_source') === 'in_house')>In-house</option>
                                    <option value="vendor_supplied" @selected(request('fulfilment_source') === 'vendor_supplied')>Vendor supplied</option>
                                </select>
                            </label>
                            <label class="rx-field">
                                <span class="rx-label">Payment Status</span>
                                <select name="payment_status" class="rn-input">
                                    <option value="">All</option>
                                    <option value="paid" @selected(request('payment_status') === 'paid')>Paid</option>
                                    <option value="partial" @selected(request('payment_status') === 'partial')>Partial</option>
                                    <option value="unpaid" @selected(request('payment_status') === 'unpaid')>Unpaid</option>
                                </select>
                            </label>
                            <label class="rx-field">
                                <span class="rx-label">Search</span>
                                <input type="text" name="search" value="{{ $search ?? '' }}" placeholder="Customer, rental, phone" class="rn-input" />
                            </label>
                        </div>

                        <div class="rx-actions">
                            <button type="submit" class="rx-btn">Apply</button>
                            <a href="{{ $safeRoute('dashboard') ?? $dashboardUrl }}" class="rx-btn-secondary">Reset</a>
                        </div>
                    </form>
                </div>
            </details>
        </div>

        <section class="executive-command-card">
            <div class="control-room-card-header">
                <div>
                    <h2 class="control-room-card-title">Executive Command Center</h2>
                    <p class="control-room-card-copy">Top KPIs plus compact business health, communication pressure, forecast, and performance signals.</p>
                </div>
            </div>

            <div class="executive-health-strip">
                @foreach($executiveBusinessHealthRows as $row)
                    <div class="executive-health-chip {{ $toneCardClass($row['tone'] ?? null) }}" title="{{ $row['tooltip'] ?? '' }}">
                        <span>{{ $row['label'] }}</span>
                        <strong>{{ $row['value'] }}</strong>
                    </div>
                @endforeach
            </div>

            <div class="control-room-priority-grid">
                @foreach($executiveCommandCards as $card)
                    @php $priorityTag = !empty($card['href']) ? 'a' : 'div'; @endphp
                    <{{ $priorityTag }} @if(!empty($card['href'])) href="{{ $card['href'] }}" @endif class="control-room-priority-card {{ $toneCardClass($card['tone'] ?? null) }}">
                        <div class="control-room-priority-top">
                            <div class="control-room-priority-copy">
                                <span class="control-room-priority-label">{{ $card['label'] }}</span>
                                <p class="control-room-priority-status">
                                    <span class="control-room-priority-badge">{{ ($card['tone'] ?? 'info') === 'red' ? 'Critical' : (($card['tone'] ?? 'info') === 'amber' ? 'Watch' : 'Stable') }}</span>
                                    <span>{{ $card['status'] }}</span>
                                </p>
                            </div>
                            <span class="control-room-priority-icon">{!! $dashboardIcon($card['icon']) !!}</span>
                        </div>
                        <strong class="control-room-priority-value">{{ $card['value'] }}</strong>
                        <p class="control-room-priority-note">{{ $card['note'] }}</p>
                        <div class="control-room-priority-visuals">
                            <div class="control-room-priority-meter">
                                <span style="width: {{ max((int) ($card['meter'] ?? 0), 6) }}%;"></span>
                            </div>
                            <span class="control-room-priority-link">{{ $card['action'] }} <span aria-hidden="true">&rarr;</span></span>
                        </div>
                    </{{ $priorityTag }}>
                @endforeach
            </div>

            <div class="executive-intel-grid">
                <div class="executive-micro-card">
                    <span class="executive-micro-label">Communication Pulse</span>
                    <div class="executive-micro-list">
                        @foreach($executiveCommunicationPulseRows as $row)
                            <div class="executive-micro-row {{ $toneCardClass($row['tone'] ?? null) }}">
                                <span>{{ $row['label'] }}</span>
                                <strong>{{ number_format((int) $row['value']) }}</strong>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="executive-micro-card">
                    <span class="executive-micro-label">Next 7 Days Forecast</span>
                    <div class="executive-micro-list">
                        @foreach($executiveForecastRows as $row)
                            <div class="executive-micro-row {{ $toneCardClass($row['tone'] ?? null) }}">
                                <span>{{ $row['label'] }}</span>
                                <strong>{{ number_format((int) $row['value']) }}</strong>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="executive-micro-card">
                    <span class="executive-micro-label">Business Performance</span>
                    <div class="executive-performance-list">
                        @foreach($executivePerformanceRows as $row)
                            <div class="executive-performance-row">
                                <span>{{ $row['label'] }}</span>
                                <strong>{{ $row['value'] }}</strong>
                                @if(!empty($row['points']))
                                    <svg class="executive-sparkline is-{{ $row['tone'] ?? 'blue' }}" viewBox="0 0 96 26" role="img" aria-label="{{ $row['label'] }} mini trend">
                                        <polyline points="{{ $row['points'] }}"></polyline>
                                    </svg>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            @if($executiveRecentActivityRows->isNotEmpty())
                <div class="executive-footer-activity">
                    <span class="executive-micro-label">Recent Activity</span>
                    <div class="executive-activity-strip">
                        @foreach($executiveRecentActivityRows as $item)
                            @php $activityTag = !empty($item['href']) ? 'a' : 'div'; @endphp
                            <{{ $activityTag }} @if(!empty($item['href'])) href="{{ $item['href'] }}" @endif class="executive-activity-item {{ $toneCardClass($item['tone'] ?? null) }}">
                                <strong>{{ $item['title'] }}</strong>
                                <span>{{ $item['type'] }} / {{ $item['time'] }}</span>
                            </{{ $activityTag }}>
                        @endforeach
                    </div>
                </div>
            @endif

        </section>

        <div class="dashboard-center-groups">
            @foreach($dashboardCenterGroups as $group)
                <details class="dashboard-center-group">
                    <summary class="dashboard-center-summary">
                        <div>
                            <strong>{{ $group['label'] }}</strong>
                            <span>{{ $group['copy'] }}</span>
                        </div>
                    </summary>
                    <div class="dashboard-center-links">
                        @foreach($group['links'] as $link)
                            <a href="{{ $link['href'] }}" class="dashboard-center-link">{{ $link['label'] }}</a>
                        @endforeach
                    </div>
                </details>
            @endforeach
        </div>
    </section>
    @if(false)
    <section class="control-room-shell">
        <div class="control-room-header">
            <div class="control-room-header-copy">
                <div class="control-room-meta-strip">
                    <span class="rx-eyebrow">PHOS Control Room</span>
                    <span class="dashboard-hero-date">{{ $dashboardDateLabel }}</span>
                    <span class="control-room-status-line">Cash pressure, rental flow, Products, staffing, and inventory in one live command view.</span>
                </div>
                <div class="control-room-title-row">
                    <h1>PHOS Control Room</h1>
                    <p>Compact CEO control room for rentals, collections, risk, and team workload.</p>
                </div>
            </div>

            <div class="control-room-header-side">
                <div class="dashboard-hero-actions">
                    <div class="dashboard-quick-actions-grid">
                        @foreach($dashboardQuickActions as $action)
                            <a href="{{ $action['href'] }}" class="{{ ($action['tone'] ?? 'secondary') === 'primary' ? 'rx-btn' : 'rx-btn-secondary' }}">{{ $action['label'] }}</a>
                        @endforeach
                    </div>
                </div>

                @if($controlRoomReferenceCards->isNotEmpty())
                    <div class="control-room-reference-grid">
                        @foreach($controlRoomReferenceCards as $card)
                            <a href="{{ $card['href'] }}" class="control-room-reference-card">
                                <span>{{ $card['label'] }}</span>
                                <strong>{{ $card['value'] }}</strong>
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        <div class="control-room-filter-dock">
            <details class="rx-card dashboard-filters-card">
                <summary class="rx-card-header">
                    <div>
                        <h2 class="rx-card-title">Filters</h2>
                        <p class="rx-card-copy">Date, city, fulfilment, and payment view.</p>
                    </div>
                    <span class="dashboard-filter-toggle" aria-hidden="true"></span>
                </summary>
                <div class="rx-card-body">
                    <form method="GET" action="{{ $dashboardUrl }}" class="rx-form-grid">
                        <div class="dashboard-filter-grid">
                            <label class="rx-field">
                                <span class="rx-label">From Date</span>
                                <input type="date" name="from_date" value="{{ $fromDate ?? '' }}" class="rn-input" />
                            </label>
                            <label class="rx-field">
                                <span class="rx-label">To Date</span>
                                <input type="date" name="to_date" value="{{ $toDate ?? '' }}" class="rn-input" />
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
                                <span class="rx-label">Fulfilment</span>
                                <select name="fulfilment_source" class="rn-input">
                                    <option value="">All</option>
                                    <option value="in_house" @selected(request('fulfilment_source') === 'in_house')>In-house</option>
                                    <option value="vendor_supplied" @selected(request('fulfilment_source') === 'vendor_supplied')>Vendor supplied</option>
                                </select>
                            </label>
                            <label class="rx-field">
                                <span class="rx-label">Payment Status</span>
                                <select name="payment_status" class="rn-input">
                                    <option value="">All</option>
                                    <option value="paid" @selected(request('payment_status') === 'paid')>Paid</option>
                                    <option value="partial" @selected(request('payment_status') === 'partial')>Partial</option>
                                    <option value="unpaid" @selected(request('payment_status') === 'unpaid')>Unpaid</option>
                                </select>
                            </label>
                            <label class="rx-field">
                                <span class="rx-label">Search</span>
                                <input type="text" name="search" value="{{ $search ?? '' }}" placeholder="Customer, rental, phone" class="rn-input" />
                            </label>
                        </div>

                        <div class="rx-actions">
                            <button type="submit" class="rx-btn">Apply</button>
                            <a href="{{ $safeRoute('dashboard') ?? $dashboardUrl }}" class="rx-btn-secondary">Reset</a>
                        </div>
                    </form>
                </div>
            </details>
        </div>

        <div class="control-room-priority-grid">
            @foreach($controlRoomCards as $card)
                @php $priorityTag = !empty($card['href']) ? 'a' : 'div'; @endphp
                <{{ $priorityTag }} @if(!empty($card['href'])) href="{{ $card['href'] }}" @endif class="control-room-priority-card {{ $toneCardClass($card['tone'] ?? null) }}">
                    <div class="control-room-priority-top">
                        <div class="control-room-priority-copy">
                            <span class="control-room-priority-label">{{ $card['label'] }}</span>
                            <p class="control-room-priority-status">
                                <span class="control-room-priority-badge">{{ ($card['tone'] ?? 'info') === 'red' ? 'Critical' : (($card['tone'] ?? 'info') === 'amber' ? 'Watch' : 'Stable') }}</span>
                                <span>{{ $card['status'] }}</span>
                            </p>
                        </div>
                        <span class="control-room-priority-icon">{!! $dashboardIcon($card['icon']) !!}</span>
                    </div>
                    <strong class="control-room-priority-value">{{ $card['value'] }}</strong>
                    <p class="control-room-priority-note">{{ $card['note'] }}</p>
                    <div class="control-room-priority-visuals">
                        <div class="control-room-priority-meter">
                            <span style="width: {{ max((int) ($card['meter'] ?? 0), 6) }}%;"></span>
                        </div>
                        @if(!empty($card['action']))
                            <span class="control-room-priority-link">{{ $card['action'] }} <span aria-hidden="true">&rarr;</span></span>
                        @endif
                    </div>
                </{{ $priorityTag }}>
            @endforeach
        </div>

        <div class="control-room-grid">
            <section class="control-room-card">
                <div class="control-room-card-header">
                    <div>
                        <h2 class="control-room-card-title">Cash &amp; Collections Overview</h2>
                        <p class="control-room-card-copy">Live collection position, dues pressure, invoice aging, and who needs finance attention first.</p>
                    </div>
                    @if($canViewFinance && $invoiceIndexUrl)
                        <a href="{{ $mergeDashboardQuery('invoices.index', ['status' => 'open']) }}" class="control-room-card-link">View all dues</a>
                    @endif
                </div>
                @if($canViewFinance)
                    <div class="control-room-stat-grid">
                        <div class="control-room-stat">
                            <span class="control-room-stat-label">Collections This Month</span>
                            <strong class="control-room-stat-value">{{ $compactCurrency($paymentsReceivedThisMonthAmount) }}</strong>
                            <span class="control-room-stat-note">{{ $currency($paymentsReceivedTodayAmount) }} received today</span>
                            <div class="control-room-stat-meter">
                                <span style="width: {{ max(min((int) round(($paymentsReceivedTodayAmount / max($paymentsReceivedThisMonthAmount, 1)) * 100), 100), 6) }}%;"></span>
                            </div>
                        </div>
                        <div class="control-room-stat">
                            <span class="control-room-stat-label">Outstanding Dues</span>
                            <strong class="control-room-stat-value">{{ $compactCurrency($outstandingDueAmountValue) }}</strong>
                            <span class="control-room-stat-note">{{ number_format($overdueInvoiceCountValue) }} overdue invoice(s)</span>
                            <div class="control-room-stat-meter">
                                <span style="width: {{ max(min((int) round(($outstandingDueAmountValue / max($outstandingDueAmountValue + $paymentsReceivedThisMonthAmount, 1)) * 100), 100), 6) }}%;"></span>
                            </div>
                        </div>
                    </div>

                    <div class="control-room-chart-shell">
                        @if($collectionsTrendRows->isNotEmpty())
                            <div class="control-room-chart-legend" aria-label="Collections trend legend">
                                <span class="control-room-chart-legend-item"><span class="control-room-chart-legend-dot is-collections"></span>Collections Value</span>
                            </div>
                            <svg class="control-room-chart-svg" viewBox="0 0 560 170" role="img" aria-label="Collections trend">
                                <line class="control-room-chart-grid" x1="18" y1="18" x2="542" y2="18"></line>
                                <line class="control-room-chart-grid" x1="18" y1="84" x2="542" y2="84"></line>
                                <line class="control-room-chart-grid" x1="18" y1="152" x2="542" y2="152"></line>
                                <polyline class="control-room-chart-line-primary" points="{{ $collectionsTrendPoints }}"></polyline>
                                @foreach($collectionsTrendRows as $index => $row)
                                    @php
                                        $x = 18 + ((560 - 36) * ($index / max($collectionsTrendRows->count() - 1, 1)));
                                        $y = (170 - 18) - ((((float) $row['amount']) / max($collectionsTrendMax, 1)) * (170 - 36));
                                    @endphp
                                    <text class="control-room-chart-value" x="{{ round($x, 2) }}" y="{{ round(max($y - 8, 16), 2) }}" text-anchor="middle">{{ $compactCurrency((float) ($row['amount'] ?? 0)) }}</text>
                                    <circle class="control-room-chart-dot-primary" cx="{{ round($x, 2) }}" cy="{{ round($y, 2) }}" r="3.5"></circle>
                                @endforeach
                                @foreach($collectionsTrendRows->only([0, (int) floor(max($collectionsTrendRows->count() - 1, 0) / 2), max($collectionsTrendRows->count() - 1, 0)]) as $index => $row)
                                    @php
                                        $x = 18 + ((560 - 36) * ($index / max($collectionsTrendRows->count() - 1, 1)));
                                    @endphp
                                    <text class="control-room-chart-axis" x="{{ round($x, 2) }}" y="168" text-anchor="middle">{{ $row['label'] }}</text>
                                @endforeach
                            </svg>
                        @else
                            <div class="rx-empty dashboard-empty">
                                <div class="rx-empty-icon">{!! $dashboardIcon('revenue') !!}</div>
                                <strong>No collection trend yet</strong>
                                <span>Payments will appear here as soon as the selected period has activity.</span>
                            </div>
                        @endif
                    </div>

                    <div class="control-room-aging">
                        <div class="control-room-card-header">
                            <div>
                                <h3 class="control-room-card-title">Overdue Invoice Aging</h3>
                                <p class="control-room-card-copy">Open invoice balance grouped by due-date pressure.</p>
                            </div>
                        </div>
                        @if($invoiceAgingBuckets->isNotEmpty())
                            <div class="control-room-aging-bar">
                                @foreach($invoiceAgingBuckets as $bucket)
                                    @php
                                        $segmentTone = match ($bucket['tone'] ?? null) {
                                            'blue' => 'is-blue',
                                            'amber' => 'is-warning',
                                            'red' => ($bucket['label'] ?? '') === '30+ Days' ? 'is-danger-strong' : 'is-danger',
                                            default => 'is-blue',
                                        };
                                    @endphp
                                    <div class="control-room-aging-segment {{ $segmentTone }}" style="width: {{ max((float) ($bucket['percent'] ?? 0), 2) }}%;"></div>
                                @endforeach
                            </div>
                            <div class="control-room-aging-legend">
                                @foreach($invoiceAgingBuckets as $bucket)
                                    <div class="control-room-aging-legend-item">
                                        <strong>{{ $bucket['label'] }}</strong>
                                        <span>{{ $currency((float) ($bucket['amount'] ?? 0)) }}</span>
                                        <small>{{ number_format((int) ($bucket['count'] ?? 0)) }} invoice(s)</small>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <details class="control-room-expandable">
                        <summary class="control-room-expandable-trigger">View Dues Breakdown <span aria-hidden="true">&rarr;</span></summary>
                        @if($topDuesCustomers->isNotEmpty())
                            @php
                                $topDuesMax = max(array_merge([1], $topDuesCustomers->pluck('amount')->map(fn ($value) => (float) $value)->all()));
                            @endphp
                            <div class="control-room-dues-list">
                                @foreach($topDuesCustomers as $customerRow)
                                    <div class="control-room-list-item">
                                        <div>
                                            <strong>{{ $customerRow['label'] }}</strong>
                                            <span>{{ number_format((int) ($customerRow['invoice_count'] ?? 0)) }} invoice(s)</span>
                                            <small>{{ (int) ($customerRow['days_overdue'] ?? 0) > 0 ? $customerRow['days_overdue'] . ' day(s) overdue' : 'Not yet overdue' }}</small>
                                            <div class="control-room-list-track"><div class="control-room-list-fill" style="width: {{ round((((float) ($customerRow['amount'] ?? 0)) / max($topDuesMax, 1)) * 100, 1) }}%;"></div></div>
                                        </div>
                                        <div class="control-room-list-amount">
                                            <strong>{{ $currency((float) ($customerRow['amount'] ?? 0)) }}</strong>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="rx-empty dashboard-empty">
                                <div class="rx-empty-icon">{!! $dashboardIcon('payment') !!}</div>
                                <strong>No dues concentration yet</strong>
                                <span>Open invoice balances will surface here automatically.</span>
                            </div>
                        @endif
                    </details>
                @else
                    <div class="rx-empty dashboard-empty">
                        <div class="rx-empty-icon">{!! $dashboardIcon('payment') !!}</div>
                        <strong>Finance view is role scoped</strong>
                        <span>Collections, dues, and invoice aging appear here for roles with finance access.</span>
                    </div>
                @endif
            </section>

            <section class="control-room-card">
                <div class="control-room-card-header">
                    <div>
                        <h2 class="control-room-card-title">Rental Operations Pipeline</h2>
                        <p class="control-room-card-copy">See how rental orders move from creation through delivery, active lifecycle, and return closure.</p>
                    </div>
                    <a href="{{ $rentalIndexUrl ?? '#' }}" class="control-room-card-link">Open rentals</a>
                </div>

                <div class="control-room-pipeline">
                    <div class="control-room-pipeline-track">
                        @foreach($pipelineStages as $stage)
                            @php
                                $stageIcon = match ($stage['label']) {
                                    'Created' => 'tasks',
                                    'Assigned' => 'customer',
                                    'Out for Delivery' => 'delivery',
                                    'Active Rental' => 'rental',
                                    'Return Due' => 'pickup',
                                    'Completed' => 'completed',
                                    default => 'trend',
                                };
                            @endphp
                            <div class="control-room-pipeline-stage {{ $toneCardClass($stage['tone'] ?? null) }}">
                                <span class="control-room-pipeline-stage-icon">{!! $dashboardIcon($stageIcon) !!}</span>
                                <span class="control-room-pipeline-stage-label">{{ $stage['label'] }}</span>
                                <strong class="control-room-pipeline-stage-value">{{ number_format((int) $stage['value']) }}</strong>
                            </div>
                        @endforeach
                    </div>

                    <div class="control-room-pipeline-bottom">
                        <div class="control-room-summary-grid">
                            @foreach($rentalPipelineSummary as $summary)
                                <div class="control-room-summary-tile">
                                    <strong>{{ $summary['value'] }}</strong>
                                    <div class="control-room-summary-copy">
                                        <span>{{ $summary['label'] }}</span>
                                        <small>{{ $summary['note'] }}</small>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div class="control-room-upcoming-list">
                            <div class="control-room-upcoming-head">
                                <div>
                                    <h3 class="control-room-card-title">Upcoming Returns</h3>
                                    <p class="control-room-card-copy">Rentals due today or ending soon so the team can prepare pickup coordination early.</p>
                                </div>
                                <span class="control-room-upcoming-icon">{!! $dashboardIcon('pickup') !!}</span>
                            </div>
                            @php
                                $upcomingReturns = collect($returnsDueToday ?? collect())
                                    ->merge(collect($endingSoonRentals ?? collect()))
                                    ->unique('id')
                                    ->take(3)
                                    ->values();
                            @endphp
                            @if($upcomingReturns->isNotEmpty())
                                <div class="control-room-upcoming-rows">
                                    @foreach($upcomingReturns as $rental)
                                        <a href="{{ route('rentals.show', $rental) }}" class="control-room-upcoming-row">
                                            <div>
                                                <strong>INV-RNT-{{ str_pad((string) $rental->id, 4, '0', STR_PAD_LEFT) }}</strong>
                                                <span>{{ $rental->customer_name ?? optional($rental->customer)->name ?? 'Customer' }}</span>
                                                <small>{{ optional($rental->product)->name ?? 'Product' }}</small>
                                            </div>
                                            <small>{{ optional($rental->end_date)->format('d M Y') ?? '-' }}</small>
                                        </a>
                                    @endforeach
                                </div>
                                <a href="{{ $mergeDashboardQuery('rentals.index', ['filter' => 'returns_due_today', 'status' => null]) }}" class="control-room-card-link">View all returns</a>
                            @else
                                <div class="control-room-upcoming-state">
                                    <strong>No immediate returns</strong>
                                    <span>The return queue is calm right now.</span>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <div class="control-room-grid">
            <section class="control-room-card">
                <div class="control-room-card-header">
                    <div>
                        <h2 class="control-room-card-title">Operational Risk Board</h2>
                        <p class="control-room-card-copy">Escalations that can turn into lost revenue, delayed pickups, or unowned operational work.</p>
                    </div>
                    @if($operationalAlerts->isNotEmpty())
                        <span class="rx-badge is-danger">{{ $operationalAlerts->sum('count') }}</span>
                    @endif
                </div>

                <table class="control-room-risk-table">
                    <thead>
                        <tr>
                            <th>Risk / Alert</th>
                            <th>Count</th>
                            <th>Severity</th>
                            <th>Owner</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($riskBoardRows as $row)
                            @php
                                $severityTone = match ($row['severity']) {
                                    'High' => 'is-danger',
                                    'Medium' => 'is-warning',
                                    default => 'is-success',
                                };
                            @endphp
                            <tr>
                                <td>
                                    <div class="control-room-stack-compact">
                                        <strong>{{ $row['risk'] }}</strong>
                                        <small>{{ strtolower($row['severity']) }} attention</small>
                                    </div>
                                </td>
                                <td>
                                    <div class="control-room-table-meter">
                                        <strong>{{ number_format((int) $row['count']) }}</strong>
                                        <div class="control-room-table-meter-bar">
                                            <span style="width: {{ max(min((int) round((((int) $row['count']) / max($riskBoardRows->max('count'), 1)) * 100), 100), 8) }}%;"></span>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="control-room-risk-pill {{ $severityTone }}">{{ $row['severity'] }}</span></td>
                                <td>{{ $row['owner'] }}</td>
                                <td><a href="{{ $row['href'] }}" class="control-room-card-link">{{ $row['action'] }}</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>

            @if($showStaffWorkloadSection)
                <section class="control-room-card" id="staff-workload-overview">
                    <div class="control-room-card-header">
                        <div>
                            <h2 class="control-room-card-title">Staff Workload Overview</h2>
                            <p class="control-room-card-copy">Compact workload heatmap for deliveries, pickups, follow-ups, and open task pressure.</p>
                        </div>
                    </div>
                    @if($staffWorkloadBoard->isNotEmpty())
                        <table class="control-room-workload-table">
                            <thead>
                                <tr>
                                    <th>Staff / Team</th>
                                    <th>Deliveries</th>
                                    <th>Pickups</th>
                                    <th>Follow-ups</th>
                                    <th>Tasks</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($staffWorkloadBoard as $row)
                                    @php
                                        $workloadTone = match ($row['status']) {
                                            'Overloaded' => 'is-danger',
                                            'Busy' => 'is-warning',
                                            default => 'is-success',
                                        };
                                    @endphp
                                    <tr>
                                        <td>{{ $row['name'] }}</td>
                                        <td>
                                            <div class="control-room-table-meter">
                                                <strong>{{ $row['deliveries'] }}</strong>
                                                <div class="control-room-table-meter-bar">
                                                    <span style="width: {{ max(min((int) round(($row['deliveries'] / max($staffWorkloadBoard->max('deliveries'), 1)) * 100), 100), 6) }}%;"></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="control-room-table-meter">
                                                <strong>{{ $row['pickups'] }}</strong>
                                                <div class="control-room-table-meter-bar">
                                                    <span style="width: {{ max(min((int) round(($row['pickups'] / max($staffWorkloadBoard->max('pickups'), 1)) * 100), 100), 6) }}%;"></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="control-room-table-meter">
                                                <strong>{{ $row['followups'] }}</strong>
                                                <div class="control-room-table-meter-bar">
                                                    <span style="width: {{ max(min((int) round(($row['followups'] / max($staffWorkloadBoard->max('followups'), 1)) * 100), 100), 6) }}%;"></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="control-room-table-meter">
                                                <strong>{{ $row['tasks'] }}</strong>
                                                <div class="control-room-table-meter-bar">
                                                    <span style="width: {{ max(min((int) round(($row['tasks'] / max($staffWorkloadBoard->max('tasks'), 1)) * 100), 100), 6) }}%;"></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td><span class="control-room-status-pill {{ $workloadTone }}">{{ $row['status'] }}</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @else
                        <div class="rx-empty dashboard-empty">
                            <div class="rx-empty-icon">{!! $dashboardIcon('customer') !!}</div>
                            <strong>No staff workload data</strong>
                            <span>Assignment load will appear here as soon as work is distributed.</span>
                        </div>
                    @endif
                </section>
            @endif
        </div>

        @if($showInventorySection || $showOrganizationAnalyticsSection)
            <div class="control-room-grid">
                @if($showInventorySection)
                    <section class="control-room-card">
                        <div class="control-room-card-header">
                            <div>
                                <h2 class="control-room-card-title">Inventory Availability</h2>
                                <p class="control-room-card-copy">Rental-stock readiness across available, on-rent, maintenance, and blocked assets.</p>
                            </div>
                            @if($inventoryUrl)
                                <a href="{{ $inventoryUrl }}" class="control-room-card-link">View inventory</a>
                            @endif
                        </div>
                        @php
                            $inventoryAngles = $inventoryAvailabilitySegments->map(fn ($segment) => ((float) ($segment['percent'] ?? 0) / 100) * 360)->values();
                        @endphp
                        @if($inventoryAvailabilityTotal > 0)
                            <div class="control-room-donut-shell">
                                <div class="control-room-donut" style="
                                    --available-angle: {{ round((float) ($inventoryAngles->get(0) ?? 0), 2) }}deg;
                                    --rent-angle: {{ round((float) ($inventoryAngles->get(1) ?? 0), 2) }}deg;
                                    --maintenance-angle: {{ round((float) ($inventoryAngles->get(2) ?? 0), 2) }}deg;
                                ">
                                    <div class="control-room-donut-center">
                                        <div>
                                            <strong>{{ number_format($inventoryAvailabilityTotal) }}</strong>
                                            <span>Total Rental Assets</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="control-room-segment-list">
                                    @foreach($inventoryAvailabilitySegments as $segment)
                                        <div class="control-room-segment-row">
                                            <span class="control-room-segment-dot {{ $toneCardClass($segment['tone'] ?? null) }}"></span>
                                            <span>{{ $segment['label'] }}</span>
                                            <strong>{{ number_format((int) $segment['value']) }} <small>({{ $segment['percent'] }}%)</small></strong>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @else
                            <div class="rx-empty dashboard-empty">
                                <div class="rx-empty-icon">{!! $dashboardIcon('asset') !!}</div>
                                <strong>No rental asset data yet</strong>
                                <span>Inventory readiness appears automatically when rental assets are created.</span>
                            </div>
                        @endif
                    </section>
                @endif

                @if($showOrganizationAnalyticsSection)
                    <section class="control-room-card">
                        <div class="control-room-card-header">
                            <div>
                                <h2 class="control-room-card-title">Business Performance</h2>
                                <p class="control-room-card-copy">Last 6 months of rental revenue, sales revenue, and total order volume.</p>
                            </div>
                            @if($reportsIndexUrl)
                                <a href="{{ $reportsIndexUrl }}" class="control-room-card-link">Open analytics</a>
                            @endif
                        </div>
                        @php
                            $trendLabels = $monthlyTrendRows->pluck('label')->values();
                            $rentalTrendValues = $monthlyTrendRows->pluck('rental_total')->map(fn ($value) => (float) $value)->all();
                            $salesTrendValues = $monthlyTrendRows->pluck('sales_total')->map(fn ($value) => (float) $value)->all();
                            $ordersTrendValues = $monthlyTrendRows->pluck('total_orders')->map(fn ($value) => (float) $value)->all();
                            $rentalTrendPoints = $buildChartPolyline($rentalTrendValues, 560, 200, 20);
                            $salesTrendPoints = $buildChartPolyline($salesTrendValues, 560, 200, 20);
                            $ordersTrendMax = max(array_merge([1], $ordersTrendValues));
                        @endphp
                        @if($monthlyTrendRows->isNotEmpty())
                            <svg class="control-room-chart-svg" viewBox="0 0 560 210" role="img" aria-label="Business performance">
                                <line class="control-room-chart-grid" x1="20" y1="22" x2="540" y2="22"></line>
                                <line class="control-room-chart-grid" x1="20" y1="100" x2="540" y2="100"></line>
                                <line class="control-room-chart-grid" x1="20" y1="180" x2="540" y2="180"></line>
                                @foreach($monthlyTrendRows as $index => $row)
                                    @php
                                        $x = 20 + ((560 - 40) * ($index / max($monthlyTrendRows->count() - 1, 1)));
                                        $barHeight = ((float) ($row['total_orders'] ?? 0) / max($ordersTrendMax, 1)) * 95;
                                    @endphp
                                    <rect class="control-room-chart-bar" x="{{ round($x - 11, 2) }}" y="{{ round(180 - $barHeight, 2) }}" width="22" height="{{ round($barHeight, 2) }}" rx="8"></rect>
                                @endforeach
                                <polyline class="control-room-chart-line-primary" points="{{ $rentalTrendPoints }}"></polyline>
                                <polyline class="control-room-chart-line-secondary" points="{{ $salesTrendPoints }}"></polyline>
                                @foreach($monthlyTrendRows as $index => $row)
                                    @php
                                        $x = 20 + ((560 - 40) * ($index / max($monthlyTrendRows->count() - 1, 1)));
                                        $rentalY = (200 - 20) - ((((float) ($row['rental_total'] ?? 0)) / max($trendMax, 1)) * (200 - 40));
                                        $salesY = (200 - 20) - ((((float) ($row['sales_total'] ?? 0)) / max($trendMax, 1)) * (200 - 40));
                                    @endphp
                                    <circle class="control-room-chart-dot-primary" cx="{{ round($x, 2) }}" cy="{{ round($rentalY, 2) }}" r="3.5"></circle>
                                    <circle class="control-room-chart-dot-secondary" cx="{{ round($x, 2) }}" cy="{{ round($salesY, 2) }}" r="3.5"></circle>
                                    <text class="control-room-chart-axis" x="{{ round($x, 2) }}" y="204" text-anchor="middle">{{ \Illuminate\Support\Str::replace(' 2026', '', $row['label']) }}</text>
                                @endforeach
                            </svg>
                        @else
                            <div class="rx-empty dashboard-empty">
                                <div class="rx-empty-icon">{!! $dashboardIcon('trend') !!}</div>
                                <strong>No performance trend yet</strong>
                                <span>Monthly revenue and orders will appear here as soon as transactions accumulate.</span>
                            </div>
                        @endif
                    </section>
                @endif
            </div>
        @endif

        <section class="control-room-card" x-data="{ activityTab: 'all', feeds: @js($recentActivityFeeds) }" id="recent-ops">
            <div class="control-room-card-header">
                <div>
                    <h2 class="control-room-card-title">Recent Activities</h2>
                    <p class="control-room-card-copy">Filter recent operational movement across rentals, payments, field tasks, and high-priority alerts.</p>
                </div>
                <a href="{{ $dashboardUrl }}" class="control-room-card-link">Refresh view</a>
            </div>
            <div class="control-room-tab-row">
                @foreach(['all' => 'All', 'rentals' => 'Rentals', 'payments' => 'Payments', 'tasks' => 'Tasks', 'alerts' => 'Alerts'] as $tabKey => $tabLabel)
                    <button type="button" class="control-room-tab-pill" :class="{ 'is-active': activityTab === '{{ $tabKey }}' }" @click="activityTab = '{{ $tabKey }}'">{{ $tabLabel }}</button>
                @endforeach
            </div>
            <div class="control-room-activity-list">
                <template x-for="item in (feeds[activityTab] || []).slice(0, 5)" :key="item.title + item.time">
                    <a class="control-room-activity-link" :href="item.href || '#'" target="_self">
                        <div class="control-room-activity-item">
                            <strong x-text="item.title"></strong>
                            <span x-text="item.meta"></span>
                            <small x-text="item.time"></small>
                        </div>
                    </a>
                </template>
                <div class="rx-empty dashboard-empty" x-show="!(feeds[activityTab] || []).length">
                    <div class="rx-empty-icon">{!! $dashboardIcon('trend') !!}</div>
                    <strong>No data yet</strong>
                    <span>The selected activity stream will appear here as soon as new events arrive.</span>
                </div>
            </div>
            <div style="display:flex;justify-content:flex-end;">
                <a href="{{ $dashboardUrl }}" class="control-room-card-link">View All Activities</a>
            </div>
        </section>

        <section class="control-room-metric-strips">
            @foreach([
                ['label' => 'Operational Priorities', 'cards' => $operationalInsightCards],
                ['label' => 'Revenue Protection', 'cards' => $revenueProtectionCards],
                ['label' => 'Inventory Readiness', 'cards' => $inventoryReadinessCards],
                ['label' => 'Reference KPIs', 'cards' => $referenceInsightCards, 'sr_only' => 'Products'],
            ] as $insightRow)
                @if($insightRow['cards']->isNotEmpty())
                    <section class="dashboard-insight-row">
                        <div class="dashboard-insight-row-heading">
                            {{ $insightRow['label'] }}
                            @if(!empty($insightRow['sr_only']))
                                <span class="sr-only">{{ $insightRow['sr_only'] }}</span>
                            @endif
                        </div>
                        <div class="dashboard-kpi-grid">
                            @foreach($insightRow['cards'] as $card)
                                @php $tag = !empty($card['href']) ? 'a' : 'div'; @endphp
                                <{{ $tag }} @if(!empty($card['href'])) href="{{ $card['href'] }}" @endif class="dashboard-kpi-card {{ $toneCardClass($card['tone'] ?? null) }}">
                                    <div class="dashboard-kpi-head">
                                        <span class="dashboard-kpi-label">{{ $card['label'] }}</span>
                                        <span class="dashboard-kpi-icon">{!! $dashboardIcon($card['icon']) !!}</span>
                                    </div>
                                    <div class="dashboard-kpi-value">{{ $card['value'] }}</div>
                                    <div class="dashboard-kpi-insight {{ $toneCardClass($card['insight_tone'] ?? null) }}">{{ $card['insight'] ?? 'No urgent action' }}</div>
                                    <div class="dashboard-kpi-note">{{ $card['note'] }}</div>
                                </{{ $tag }}>
                            @endforeach
                        </div>
                    </section>
                @endif
            @endforeach
        </section>
    </section>

    @endif
    <section class="rx-card" id="operations-center-panel">
        <div class="rx-card-header dashboard-section-heading">
            <div>
                <h2 class="rx-card-title">Operations Center</h2>
                <p class="rx-card-copy">What requires action today across field movement, renewals, unassigned work, and team capacity.</p>
            </div>
        </div>
        <div class="rx-card-body">
            <div class="operations-center-grid">
                <div class="operations-health-grid">
                    @foreach($operationsHealthCards as $card)
                        @php $tag = !empty($card['href']) ? 'a' : 'div'; @endphp
                        <{{ $tag }} @if(!empty($card['href'])) href="{{ $card['href'] }}" @endif class="operations-health-card {{ $toneCardClass($card['tone'] ?? null) }}">
                            <div class="operations-health-head">
                                <span class="operations-health-label">{{ $card['label'] }}</span>
                                <span class="operations-health-icon">{!! $dashboardIcon($card['icon']) !!}</span>
                            </div>
                            <strong class="operations-health-value">{{ $card['value'] }}</strong>
                            <span class="rx-badge {{ $statusBadgeClass(($card['tone'] ?? 'blue') === 'red' ? 'overdue' : (($card['tone'] ?? 'blue') === 'amber' ? 'warning' : 'completed')) }}">{{ $card['status'] }}</span>
                            <span class="operations-health-note">{{ $card['note'] }}</span>
                            <span class="operations-health-link">{{ $card['action'] }} →</span>
                        </{{ $tag }}>
                    @endforeach
                </div>

                <div class="operations-layout-grid">
                    <section class="control-room-card operations-pipeline-wrap">
                        <div class="control-room-card-header">
                            <div>
                                <h3 class="control-room-card-title">Rental Operations Pipeline</h3>
                                <p class="control-room-card-copy">Created, assigned, dispatched, active, due back, and completed.</p>
                            </div>
                            <a href="{{ $rentalIndexUrl ?? '#' }}" class="control-room-card-link">View Pipeline</a>
                        </div>
                        <div class="control-room-pipeline-track">
                            @foreach($pipelineStages as $stage)
                                @php
                                    $stageIcon = match ($stage['label']) {
                                        'Created' => 'tasks',
                                        'Assigned' => 'customer',
                                        'Out for Delivery' => 'delivery',
                                        'Active Rental' => 'rental',
                                        'Return Due' => 'pickup',
                                        'Completed' => 'completed',
                                        default => 'trend',
                                    };
                                @endphp
                                <div class="control-room-pipeline-stage {{ $toneCardClass($stage['tone'] ?? null) }}">
                                    <span class="control-room-pipeline-stage-icon">{!! $dashboardIcon($stageIcon) !!}</span>
                                    <strong class="control-room-pipeline-stage-label">{{ $stage['label'] }}</strong>
                                    <span class="control-room-pipeline-stage-value">{{ number_format((int) ($stage['value'] ?? 0)) }}</span>
                                </div>
                            @endforeach
                        </div>
                        <div class="executive-mini-grid" style="grid-template-columns: repeat(2, minmax(0, 1fr));">
                            @foreach($rentalPipelineSummary as $summary)
                                <div class="executive-mini-row">
                                    <span class="executive-mini-label">{{ $summary['label'] }}</span>
                                    <strong class="executive-mini-value">{{ $summary['value'] }}</strong>
                                    <p class="executive-mini-note">{{ $summary['note'] }}</p>
                                </div>
                            @endforeach
                        </div>
                    </section>

                    <section class="control-room-card executive-queue">
                        <div class="control-room-card-header">
                            <div>
                                <h3 class="control-room-card-title">Top Priority Queue</h3>
                                <p class="control-room-card-copy">Urgent invoices, renewals, tasks, pickups, and follow-ups requiring action.</p>
                            </div>
                        </div>
                        @if($topPriorityQueueRows->isNotEmpty())
                            <div class="executive-queue-list">
                                @foreach($topPriorityQueueRows->take(6) as $row)
                                    <div class="executive-queue-item">
                                        <span class="executive-queue-pill {{ $toneCardClass($row['tone'] ?? null) }}">{{ $row['priority'] }}</span>
                                        <div class="executive-queue-copy">
                                            <strong>{{ $row['title'] }}</strong>
                                            <span>{{ $row['owner'] }} · {{ $row['status'] }}</span>
                                        </div>
                                        <a href="{{ $row['href'] }}" class="executive-queue-link">{{ $row['action'] }}</a>
                                    </div>
                                @endforeach
                                @if($topPriorityQueueRows->count() > 6)
                                    <details class="dashboard-expandable">
                                        <summary class="dashboard-expandable-summary">Show {{ $topPriorityQueueRows->count() - 6 }} more</summary>
                                        <div class="dashboard-expandable-content">
                                            @foreach($topPriorityQueueRows->slice(6) as $row)
                                                <div class="executive-queue-item">
                                                    <span class="executive-queue-pill {{ $toneCardClass($row['tone'] ?? null) }}">{{ $row['priority'] }}</span>
                                                    <div class="executive-queue-copy">
                                                        <strong>{{ $row['title'] }}</strong>
                                                        <span>{{ $row['owner'] }} · {{ $row['status'] }}</span>
                                                    </div>
                                                    <a href="{{ $row['href'] }}" class="executive-queue-link">{{ $row['action'] }}</a>
                                                </div>
                                            @endforeach
                                        </div>
                                    </details>
                                @endif
                            </div>
                        @else
                            <div class="rx-empty dashboard-empty">
                                <div class="rx-empty-icon">{!! $dashboardIcon('tasks') !!}</div>
                                <strong>No urgent queue</strong>
                                <span>High-priority items will surface here automatically.</span>
                            </div>
                        @endif
                    </section>
                </div>

                <div class="operations-layout-grid">
                    <section class="control-room-card operations-command-panel">
                        <div class="control-room-card-header">
                            <div>
                                <h3 class="control-room-card-title">Operations Command Panel</h3>
                                <p class="control-room-card-copy">Team workload and same-day operations in a compact control view.</p>
                            </div>
                        </div>
                        <div class="operations-command-grid">
                            <div class="operations-command-block">
                                <span class="operations-command-label">Team Capacity</span>
                                <div class="operations-capacity-table-wrap">
                                    <table class="operations-capacity-table">
                                        <thead>
                                            <tr>
                                                <th>Team</th>
                                                <th>Deliveries</th>
                                                <th>Pickups</th>
                                                <th>Follow-ups</th>
                                                <th>Tasks</th>
                                                <th>Status</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($teamCapacityRows as $row)
                                                <tr>
                                                    <td class="operations-capacity-team">{{ $row['label'] }}</td>
                                                    <td><span class="operations-capacity-count">{{ number_format((int) $row['deliveries']) }}</span></td>
                                                    <td><span class="operations-capacity-count">{{ number_format((int) $row['pickups']) }}</span></td>
                                                    <td><span class="operations-capacity-count">{{ number_format((int) $row['followups']) }}</span></td>
                                                    <td><span class="operations-capacity-count">{{ number_format((int) $row['tasks']) }}</span></td>
                                                    <td><span class="rx-badge {{ $toneCardClass($row['tone'] ?? null) }}">{{ $row['status'] }}</span></td>
                                                    <td>
                                                        @if($deliveriesIndexUrl)
                                                            <a href="{{ $deliveriesIndexUrl }}" class="operations-command-action">Open</a>
                                                        @else
                                                            <span class="operations-command-action">Review</span>
                                                        @endif
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <div class="operations-command-block">
                                <span class="operations-command-label">Today’s Operations Snapshot</span>
                                <div class="operations-snapshot-strip">
                                    @foreach($operationsSnapshotRows as $row)
                                        <div class="operations-snapshot-card {{ $toneCardClass($row['tone'] ?? null) }}">
                                            <div class="operations-snapshot-head">
                                                <span class="operations-snapshot-label">{{ $row['label'] }}</span>
                                                <span class="rx-badge {{ $toneCardClass($row['tone'] ?? null) }}">{{ $row['value'] }}</span>
                                            </div>
                                            <strong class="operations-snapshot-value">{{ $row['value'] }}</strong>
                                            <span class="operations-snapshot-note">{{ $row['note'] }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </section>
                </div>

                <details class="operations-detail-group">
                    <summary class="operations-detail-summary">
                        <div>
                            <strong>View Detailed Operations Analytics</strong>
                            <span>Open the legacy widgets, logistics boards, renewal desks, and communication queues only when you need deeper detail.</span>
                        </div>
                    </summary>
                    <div class="operations-detail-content">
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

    @if(false)
    <section class="rx-card" id="today-widgets">
        <div class="rx-card-header dashboard-section-heading">
            <div>
                <h2 class="rx-card-title">{{ "Today's Operational Widgets" }}</h2>
                <p class="rx-card-copy">Focused lists for what needs calls, pickup planning, delivery movement, and collection follow-up right now.</p>
            </div>
        </div>
        <div class="rx-card-body">
            <div class="dashboard-widget-grid">
                @if($dashboardWidgetEnabled('widget_today_renewals'))
                <div class="rx-card dashboard-widget-card">
                    <div class="rx-card-header">
                        <div>
                            <h3 class="rx-card-title">{{ "Today's Renewals" }}</h3>
                            <p class="rx-card-copy">Renewals due today with immediate drilldown to rental detail.</p>
                        </div>
                        <a href="{{ $renewalCenterUrl ? route('renewal-center.index', ['tab' => 'due_today']) : ($rentalIndexUrl ?? '#') }}" class="rx-btn-secondary">Open</a>
                    </div>
                    <div class="rx-card-body">
                        @if($todayRenewalSummary->isNotEmpty())
                            <div class="dashboard-widget-list">
                                @foreach($todayRenewalSummaryVisible as $rental)
                                    <div class="dashboard-widget-item">
                                        <div class="dashboard-widget-eyebrow">
                                            <strong>Rental #{{ $rental->id }}</strong>
                                            <em>{{ optional($rental->end_date)?->format('d M Y') ?? 'Today' }}</em>
                                        </div>
                                        <span>{{ $rental->customer_name ?? optional($rental->customer)->name ?? 'Customer' }}</span>
                                        <small>{{ optional($rental->product)->name ?? 'Product N/A' }} â€¢ {{ $currency($rental->rental_amount ?? 0) }}</small>
                                        <div class="dashboard-widget-actions">
                                            <a href="{{ route('rentals.show', $rental) }}">Open</a>
                                            @if($rental->customer?->phone)
                                                <a href="tel:{{ preg_replace('/\s+/', '', (string) $rental->customer->phone) }}">Call</a>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                                @if($todayRenewalSummaryHidden->isNotEmpty())
                                    <details class="dashboard-expandable">
                                        <summary class="dashboard-expandable-summary">Show {{ $todayRenewalSummaryHidden->count() }} more</summary>
                                        <div class="dashboard-expandable-content">
                                            @foreach($todayRenewalSummaryHidden as $rental)
                                                <div class="dashboard-widget-item">
                                                    <div class="dashboard-widget-eyebrow">
                                                        <strong>Rental #{{ $rental->id }}</strong>
                                                        <em>{{ optional($rental->end_date)?->format('d M Y') ?? 'Due soon' }}</em>
                                                    </div>
                                                    <span>{{ $rental->customer_name ?? optional($rental->customer)->name ?? 'Customer' }} • {{ $rental->phone ?? 'No phone' }}</span>
                                                    <small>{{ optional($rental->product)->name ?? 'Product N/A' }} • {{ $currency((float) ($rental->rental_amount ?? 0)) }}</small>
                                                    <div class="dashboard-widget-actions">
                                                        <a href="{{ route('rentals.show', $rental) }}">Open</a>
                                                        @if($rental->phone)
                                                            <a href="tel:{{ preg_replace('/\s+/', '', (string) $rental->phone) }}">Call</a>
                                                        @endif
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </details>
                                @endif
                            </div>
                        @else
                            <div class="rx-empty dashboard-empty">
                                <div class="rx-empty-icon">{!! $dashboardIcon('rental') !!}</div>
                                <strong>No renewals due today</strong>
                                <span>The renewal queue is clear for today.</span>
                            </div>
                        @endif
                    </div>
                </div>
                @endif

                @if($dashboardWidgetEnabled('widget_today_pickups'))
                <div class="rx-card dashboard-widget-card">
                    <div class="rx-card-header">
                        <div>
                            <h3 class="rx-card-title">{{ "Today's Pickups" }}</h3>
                            <p class="rx-card-copy">Pickup tasks that need route movement or confirmation.</p>
                        </div>
                        <a href="{{ $pickupCenterUrl ? route('pickup-center.index', ['tab' => 'scheduled_today']) : ($deliveriesIndexUrl ?? '#') }}" class="rx-btn-secondary">Open</a>
                    </div>
                    <div class="rx-card-body">
                        @if($todayPickupSummary->isNotEmpty())
                            <div class="dashboard-widget-list">
                                @foreach($todayPickupSummaryVisible as $task)
                                    <div class="dashboard-widget-item">
                                        <div class="dashboard-widget-eyebrow">
                                            <strong>Pickup #{{ $task->id }}</strong>
                                            <em>{{ optional($task->scheduled_at)?->format('h:i A') ?? 'Today' }}</em>
                                        </div>
                                        <span>{{ $task->linkedCustomerName() }} â€¢ {{ $task->linkedCustomerPhone() ?: 'No phone' }}</span>
                                        <small>{{ $task->pickup_address ?: 'Address pending' }}</small>
                                        <div class="dashboard-widget-actions">
                                            <a href="{{ route('deliveries.show', $task) }}">Open</a>
                                            @if($task->linkedCustomerPhone())
                                                <a href="tel:{{ preg_replace('/\s+/', '', (string) $task->linkedCustomerPhone()) }}">Call</a>
                                            @endif
                                            @if($task->linkedCustomerMapUrl())
                                                <a href="{{ $task->linkedCustomerMapUrl() }}" target="_blank" rel="noopener">Open Map</a>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                                @if($todayPickupSummaryHidden->isNotEmpty())
                                    <details class="dashboard-expandable">
                                        <summary class="dashboard-expandable-summary">Show {{ $todayPickupSummaryHidden->count() }} more</summary>
                                        <div class="dashboard-expandable-content">
                                            @foreach($todayPickupSummaryHidden as $task)
                                                <div class="dashboard-widget-item">
                                                    <div class="dashboard-widget-eyebrow">
                                                        <strong>Pickup #{{ $task->id }}</strong>
                                                        <em>{{ optional($task->scheduled_at)?->format('h:i A') ?? 'Today' }}</em>
                                                    </div>
                                                    <span>{{ $task->linkedCustomerName() ?: 'Customer pending' }} • {{ $task->linkedCustomerPhone() ?: 'No phone' }}</span>
                                                    <small>{{ $task->pickupOperationalLabel() }} • {{ $task->assignedUser?->name ?: $task->assignedStaff?->name ?: 'Unassigned' }}</small>
                                                    <div class="dashboard-widget-actions">
                                                        <a href="{{ route('deliveries.show', $task) }}">Open</a>
                                                        @if($task->linkedCustomerPhone())
                                                            <a href="tel:{{ preg_replace('/\s+/', '', (string) $task->linkedCustomerPhone()) }}">Call</a>
                                                        @endif
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </details>
                                @endif
                            </div>
                        @else
                            <div class="rx-empty dashboard-empty">
                                <div class="rx-empty-icon">{!! $dashboardIcon('pickup') !!}</div>
                                <strong>No pickups scheduled today</strong>
                                <span>Pickup movement is currently clear.</span>
                            </div>
                        @endif
                    </div>
                </div>
                @endif

                @if($dashboardWidgetEnabled('widget_today_deliveries'))
                <div class="rx-card dashboard-widget-card">
                    <div class="rx-card-header">
                        <div>
                            <h3 class="rx-card-title">{{ "Today's Deliveries" }}</h3>
                            <p class="rx-card-copy">Delivery tasks that need dispatch, proof, or completion follow-through.</p>
                        </div>
                        <a href="{{ $deliveriesIndexUrl ? route('deliveries.index', ['task_type' => 'delivery']) : '#' }}" class="rx-btn-secondary">Open</a>
                    </div>
                    <div class="rx-card-body">
                        @if($todayDeliverySummary->isNotEmpty())
                            <div class="dashboard-widget-list">
                                @foreach($todayDeliverySummaryVisible as $task)
                                    <div class="dashboard-widget-item">
                                        <div class="dashboard-widget-eyebrow">
                                            <strong>Delivery #{{ $task->id }}</strong>
                                            <em>{{ optional($task->scheduled_at)?->format('h:i A') ?? 'Today' }}</em>
                                        </div>
                                        <span>{{ $task->linkedCustomerName() }} â€¢ {{ $task->linkedCustomerPhone() ?: 'No phone' }}</span>
                                        <small>{{ $task->delivery_address ?: 'Address pending' }}</small>
                                        <div class="dashboard-widget-actions">
                                            <a href="{{ route('deliveries.show', $task) }}">Open</a>
                                            @if($task->linkedCustomerPhone())
                                                <a href="tel:{{ preg_replace('/\s+/', '', (string) $task->linkedCustomerPhone()) }}">Call</a>
                                            @endif
                                            @if($task->linkedCustomerMapUrl())
                                                <a href="{{ $task->linkedCustomerMapUrl() }}" target="_blank" rel="noopener">Open Map</a>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                                @if($todayDeliverySummaryHidden->isNotEmpty())
                                    <details class="dashboard-expandable">
                                        <summary class="dashboard-expandable-summary">Show {{ $todayDeliverySummaryHidden->count() }} more</summary>
                                        <div class="dashboard-expandable-content">
                                            @foreach($todayDeliverySummaryHidden as $task)
                                                <div class="dashboard-widget-item">
                                                    <div class="dashboard-widget-eyebrow">
                                                        <strong>Delivery #{{ $task->id }}</strong>
                                                        <em>{{ optional($task->scheduled_at)?->format('h:i A') ?? 'Today' }}</em>
                                                    </div>
                                                    <span>{{ $task->linkedCustomerName() ?: 'Customer pending' }} • {{ $task->linkedCustomerPhone() ?: 'No phone' }}</span>
                                                    <small>{{ \Illuminate\Support\Str::headline((string) $task->status) }} • {{ $task->assignedUser?->name ?: $task->assignedStaff?->name ?: 'Unassigned' }}</small>
                                                    <div class="dashboard-widget-actions">
                                                        <a href="{{ route('deliveries.show', $task) }}">Open</a>
                                                        @if($task->linkedCustomerPhone())
                                                            <a href="tel:{{ preg_replace('/\s+/', '', (string) $task->linkedCustomerPhone()) }}">Call</a>
                                                        @endif
                                                        @if($task->linkedCustomerMapUrl())
                                                            <a href="{{ $task->linkedCustomerMapUrl() }}" target="_blank" rel="noopener">Open Map</a>
                                                        @endif
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </details>
                                @endif
                            </div>
                        @else
                            <div class="rx-empty dashboard-empty">
                                <div class="rx-empty-icon">{!! $dashboardIcon('delivery') !!}</div>
                                <strong>No deliveries queued today</strong>
                                <span>Delivery operations are currently under control.</span>
                            </div>
                        @endif
                    </div>
                </div>
                @endif

                @if($dashboardWidgetEnabled('widget_today_followups'))
                <div class="rx-card dashboard-widget-card">
                    <div class="rx-card-header">
                        <div>
                            <h3 class="rx-card-title">{{ "Today's Follow-ups" }}</h3>
                            <p class="rx-card-copy">Calls and communication tasks due today for renewals, payments, and escalations.</p>
                        </div>
                        <a href="{{ $communicationCenterUrl ? route('communication-center.index', ['tab' => 'today']) : '#' }}" class="rx-btn-secondary">Open</a>
                    </div>
                    <div class="rx-card-body">
                        @if($todayFollowUpSummary->isNotEmpty())
                            <div class="dashboard-widget-list">
                                @foreach($todayFollowUpSummaryVisible as $followUp)
                                    <div class="dashboard-widget-item">
                                        <div class="dashboard-widget-eyebrow">
                                            <strong>{{ $followUp->title }}</strong>
                                            <em>{{ optional($followUp->due_at)?->format('h:i A') ?? 'Today' }}</em>
                                        </div>
                                        <span>{{ $followUp->callTargetName() ?: 'Contact pending' }} â€¢ {{ $followUp->callTargetPhone() ?: 'No phone' }}</span>
                                        <small>{{ $followUp->typeLabel() }} â€¢ {{ $followUp->priorityLabel() }}</small>
                                        <div class="dashboard-widget-actions">
                                            <a href="{{ route('communication-center.index', ['tab' => 'today']) }}">Open</a>
                                            @if($followUp->callTargetPhone())
                                                <a href="tel:{{ preg_replace('/\s+/', '', (string) $followUp->callTargetPhone()) }}">Call</a>
                                            @endif
                                            @if($followUp->whatsappUrl())
                                                <a href="{{ $followUp->whatsappUrl() }}" target="_blank" rel="noopener">WhatsApp</a>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                                @if($todayFollowUpSummaryHidden->isNotEmpty())
                                    <details class="dashboard-expandable">
                                        <summary class="dashboard-expandable-summary">Show {{ $todayFollowUpSummaryHidden->count() }} more</summary>
                                        <div class="dashboard-expandable-content">
                                            @foreach($todayFollowUpSummaryHidden as $followUp)
                                                <div class="dashboard-widget-item">
                                                    <div class="dashboard-widget-eyebrow">
                                                        <strong>{{ $followUp->title }}</strong>
                                                        <em>{{ optional($followUp->due_at)?->format('h:i A') ?? 'Today' }}</em>
                                                    </div>
                                                    <span>{{ $followUp->callTargetName() ?: 'Contact pending' }} • {{ $followUp->callTargetPhone() ?: 'No phone' }}</span>
                                                    <small>{{ $followUp->typeLabel() }} • {{ $followUp->priorityLabel() }}</small>
                                                    <div class="dashboard-widget-actions">
                                                        <a href="{{ route('communication-center.index', ['tab' => 'today']) }}">Open</a>
                                                        @if($followUp->callTargetPhone())
                                                            <a href="tel:{{ preg_replace('/\s+/', '', (string) $followUp->callTargetPhone()) }}">Call</a>
                                                        @endif
                                                        @if($followUp->whatsappUrl())
                                                            <a href="{{ $followUp->whatsappUrl() }}" target="_blank" rel="noopener">WhatsApp</a>
                                                        @endif
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </details>
                                @endif
                            </div>
                        @else
                            <div class="rx-empty dashboard-empty">
                                <div class="rx-empty-icon">{!! $dashboardIcon('tasks') !!}</div>
                                <strong>No follow-ups due today</strong>
                                <span>Todayâ€™s callback queue is clear.</span>
                            </div>
                        @endif
                    </div>
                </div>
                @endif

                @if($canViewFinance && $dashboardWidgetEnabled('widget_pending_payments'))
                    <div class="rx-card dashboard-widget-card">
                        <div class="rx-card-header">
                            <div>
                                <h3 class="rx-card-title">Pending Payments</h3>
                                <p class="rx-card-copy">Immediate collection follow-ups with direct call and reminder shortcuts.</p>
                            </div>
                            <a href="{{ $communicationCenterUrl ? route('communication-center.index', ['tab' => 'payments']) : ($invoiceIndexUrl ?? '#') }}" class="rx-btn-secondary">Open</a>
                        </div>
                        <div class="rx-card-body">
                            @if($pendingPaymentSummary->isNotEmpty())
                                <div class="dashboard-widget-list">
                                    @foreach($pendingPaymentSummaryVisible as $invoice)
                                        <div class="dashboard-widget-item">
                                            <div class="dashboard-widget-eyebrow">
                                                <strong>{{ $invoice->invoice_number }}</strong>
                                                <em>{{ $currency($invoice->total_amount - $invoice->payments_sum_amount) }}</em>
                                            </div>
                                            <span>{{ optional($invoice->customer)->name ?? 'Customer' }} â€¢ {{ optional($invoice->customer)->phone ?? 'No phone' }}</span>
                                            <small>Due {{ optional($invoice->due_date)?->format('d M Y') ?? 'now' }}</small>
                                            <div class="dashboard-widget-actions">
                                                <a href="{{ route('invoices.show', $invoice) }}">Open</a>
                                                @if(optional($invoice->customer)->phone)
                                                    <a href="tel:{{ preg_replace('/\s+/', '', (string) $invoice->customer->phone) }}">Call</a>
                                                @endif
                                                @if(method_exists($invoice, 'whatsappReminderUrl') && $invoice->whatsappReminderUrl())
                                                    <a href="{{ $invoice->whatsappReminderUrl() }}" target="_blank" rel="noopener">WhatsApp</a>
                                                @endif
                                            </div>
                                        </div>
                                    @endforeach
                                    @if($pendingPaymentSummaryHidden->isNotEmpty())
                                        <details class="dashboard-expandable">
                                            <summary class="dashboard-expandable-summary">Show {{ $pendingPaymentSummaryHidden->count() }} more</summary>
                                            <div class="dashboard-expandable-content">
                                                @foreach($pendingPaymentSummaryHidden as $invoice)
                                                    <div class="dashboard-widget-item">
                                                        <div class="dashboard-widget-eyebrow">
                                                            <strong>{{ $invoice->invoice_number }}</strong>
                                                            <em>{{ $currency($invoice->total_amount - $invoice->payments_sum_amount) }}</em>
                                                        </div>
                                                        <span>{{ optional($invoice->customer)->name ?? 'Customer' }} • {{ optional($invoice->customer)->phone ?? 'No phone' }}</span>
                                                        <small>Due {{ optional($invoice->due_date)?->format('d M Y') ?? 'now' }}</small>
                                                        <div class="dashboard-widget-actions">
                                                            <a href="{{ route('invoices.show', $invoice) }}">Open</a>
                                                            @if(optional($invoice->customer)->phone)
                                                                <a href="tel:{{ preg_replace('/\s+/', '', (string) $invoice->customer->phone) }}">Call</a>
                                                            @endif
                                                            @if(method_exists($invoice, 'whatsappReminderUrl') && $invoice->whatsappReminderUrl())
                                                                <a href="{{ $invoice->whatsappReminderUrl() }}" target="_blank" rel="noopener">WhatsApp</a>
                                                            @endif
                                                        </div>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </details>
                                    @endif
                                </div>
                            @else
                                <div class="rx-empty dashboard-empty">
                                    <div class="rx-empty-icon">{!! $dashboardIcon('payment') !!}</div>
                                    <strong>No pending payments highlighted</strong>
                                    <span>Collections are currently stable.</span>
                                </div>
                            @endif
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </section>

    @endif

    @if($showFinanceSection || $showSalesOperationsSection)
        <section class="rx-card" id="sales-pulse-panel">
            @php
                $revenueCenterTotal = max($paymentsReceivedThisMonthAmount + $outstandingDueAmountValue + $revenueCenterUnbilledTotal, 0);
                $revenueCenterSegments = collect([
                    ['label' => 'Collected', 'value' => $paymentsReceivedThisMonthAmount, 'tone' => 'green'],
                    ['label' => 'Outstanding', 'value' => $outstandingDueAmountValue, 'tone' => 'amber'],
                    ['label' => 'Unbilled', 'value' => $revenueCenterUnbilledTotal, 'tone' => 'red'],
                ])->map(function (array $segment) use ($revenueCenterTotal) {
                    $segment['percent'] = $revenueCenterTotal > 0
                        ? round(($segment['value'] / $revenueCenterTotal) * 100)
                        : 0;

                    return $segment;
                })->values();
                $revenueCenterCollectedPercent = (int) ($revenueCenterSegments[0]['percent'] ?? 0);
                $revenueCenterOutstandingPercent = (int) ($revenueCenterSegments[1]['percent'] ?? 0);
                $revenueProtectionRows = collect([
                    ['label' => 'Outstanding', 'value' => $outstandingDueAmountValue, 'display' => $currency($outstandingDueAmountValue), 'tone' => 'amber'],
                    ['label' => 'Collected This Month', 'value' => $paymentsReceivedThisMonthAmount, 'display' => $currency($paymentsReceivedThisMonthAmount), 'tone' => 'green'],
                    ['label' => 'Overdue Invoices', 'value' => $overdueInvoiceCountValue, 'display' => number_format($overdueInvoiceCountValue) . ' invoice(s)', 'tone' => $overdueInvoiceCountValue > 0 ? 'red' : 'green'],
                    ['label' => 'Unbilled', 'value' => $revenueCenterUnbilledTotal, 'display' => $currency($revenueCenterUnbilledTotal), 'tone' => 'red'],
                ]);
                $revenueProtectionTotal = max($paymentsReceivedThisMonthAmount + $outstandingDueAmountValue, 1);
                $revenueProtectionCollectionPercent = round(($paymentsReceivedThisMonthAmount / $revenueProtectionTotal) * 100);
                $businessTrendValuesRental = $monthlyTrendRows->pluck('rental_total')->map(fn ($value) => (float) $value)->all();
                $businessTrendValuesSales = $monthlyTrendRows->pluck('sales_total')->map(fn ($value) => (float) $value)->all();
                $businessTrendValuesOrders = $monthlyTrendRows->pluck('total_orders')->map(fn ($value) => (float) $value)->all();
                $businessTrendWidth = 560;
                $businessTrendHeight = 156;
                $businessTrendRentalPoints = $buildChartPolyline($businessTrendValuesRental, $businessTrendWidth, $businessTrendHeight, 18);
                $businessTrendSalesPoints = $buildChartPolyline($businessTrendValuesSales, $businessTrendWidth, $businessTrendHeight, 18);
                $businessTrendOrdersMax = max(array_merge([1], $businessTrendValuesOrders));
                $businessTrendHasData = $monthlyTrendRows->contains(fn ($row) => ((float) ($row['rental_total'] ?? 0)) > 0 || ((float) ($row['sales_total'] ?? 0)) > 0 || ((float) ($row['total_orders'] ?? 0)) > 0);
                $revenueCenterTopActions = $revenueCenterActionRows->take(8)->values();
                $revenueCenterAgingMax = max(array_merge([1], $invoiceAgingBuckets->pluck('amount')->map(fn ($value) => (float) $value)->all()));
            @endphp
            <div class="rx-card sales-pulse-shell">
                <div class="rx-card-header sales-pulse-header">
                    <div class="sales-pulse-heading">
                        <span class="sales-pulse-heading-icon">{!! $dashboardIcon('revenue') !!}</span>
                        <div>
                            <h2 class="rx-card-title">Revenue Center</h2>
                            <p class="rx-card-copy">A compact view of money at risk, collections landed, overdue exposure, and what finance needs to act on next.</p>
                            <span class="sr-only">Cash &amp; Collections Overview</span>
                            @if($showFinanceSection)
                                <span class="sr-only">Finance Summary</span>
                            @endif
                        </div>
                    </div>
                    <div class="sales-pulse-actions">
                        <span class="sales-pulse-chip">{{ now()->startOfMonth()->format('d M Y') }} - {{ now()->format('d M Y') }}</span>
                        @if($showFinanceSection && $canCreatePayments && $recordPaymentUrl)
                            <a href="{{ $recordPaymentUrl }}" class="sales-pulse-chip">Record Payment</a>
                        @endif
                        @if($invoiceIndexUrl)
                            <a href="{{ $mergeDashboardQuery('invoices.index', ['status' => 'open']) }}" class="sales-pulse-chip">Open Invoices</a>
                        @endif
                        @if($reportsIndexUrl)
                            <a href="{{ $reportsIndexUrl }}" class="sales-pulse-chip">Open Reports</a>
                        @endif
                    </div>
                </div>
                <div class="rx-card-body">
                    <div class="revenue-decision-grid">
                        <div class="revenue-decision-card">
                            <div class="revenue-decision-head">
                                <span class="revenue-decision-title">Revenue Mix</span>
                                <strong class="revenue-decision-total">{{ $compactCurrency($revenueMixTotal) }}</strong>
                            </div>
                            <div class="revenue-composition-bar" aria-label="Revenue mix composition">
                                @foreach($revenueMixRows as $row)
                                    <span class="is-{{ $row['tone'] }}" style="width: {{ max((float) ($row['percent'] ?? 0), $revenueMixTotal > 0 ? 4 : 0) }}%;" title="{{ $row['label'] }}: {{ $row['display'] }}"></span>
                                @endforeach
                            </div>
                            <div class="revenue-decision-list">
                                @foreach($revenueMixRows as $row)
                                    @php $mixTag = !empty($row['href']) ? 'a' : 'div'; @endphp
                                    <{{ $mixTag }} @if(!empty($row['href'])) href="{{ $row['href'] }}" @endif class="revenue-decision-row">
                                        <span>{{ $row['label'] }}</span>
                                        <strong>{{ $row['display'] }}</strong>
                                    </{{ $mixTag }}>
                                @endforeach
                            </div>
                        </div>

                        <div class="revenue-decision-card">
                            <div class="revenue-decision-head">
                                <span class="revenue-decision-title">Cash Position</span>
                                <strong class="revenue-decision-total">{{ $salesPulseCollectionEfficiency }}%</strong>
                            </div>
                            <div class="revenue-decision-list">
                                @foreach($cashPositionRows as $row)
                                    @php $cashTag = !empty($row['href']) ? 'a' : 'div'; @endphp
                                    <{{ $cashTag }} @if(!empty($row['href'])) href="{{ $row['href'] }}" @endif class="revenue-decision-row {{ $toneCardClass($row['tone'] ?? null) }}">
                                        <span>{{ $row['label'] }}</span>
                                        <strong>{{ $row['display'] }}</strong>
                                    </{{ $cashTag }}>
                                @endforeach
                            </div>
                        </div>

                        <div class="revenue-decision-card">
                            <div class="revenue-decision-head">
                                <span class="revenue-decision-title">Invoice Health</span>
                                <strong class="revenue-decision-total">{{ number_format($overdueInvoiceCountValue) }}</strong>
                            </div>
                            <div class="revenue-decision-list">
                                @foreach($invoiceHealthRows as $row)
                                    @php $invoiceTag = !empty($row['href']) ? 'a' : 'div'; @endphp
                                    <{{ $invoiceTag }} @if(!empty($row['href'])) href="{{ $row['href'] }}" @endif class="revenue-decision-row {{ $toneCardClass($row['tone'] ?? null) }}">
                                        <span>{{ $row['label'] }}</span>
                                        <strong>{{ $row['display'] }}</strong>
                                    </{{ $invoiceTag }}>
                                @endforeach
                            </div>
                        </div>

                        <div class="revenue-decision-card">
                            <div class="revenue-decision-head">
                                <span class="revenue-decision-title">Vendor Performance</span>
                                @if($safeRoute('vendor-orders.index'))
                                    <a href="{{ $safeRoute('vendor-orders.index') }}" class="sales-pulse-card-link">Open</a>
                                @endif
                            </div>
                            @if((bool) $vendorPerformance->get('available', false))
                                <div class="revenue-decision-list">
                                    @foreach($vendorPerformanceRows as $row)
                                        <div class="revenue-decision-row {{ $toneCardClass($row['tone'] ?? null) }}">
                                            <span>{{ $row['label'] }}</span>
                                            <strong>{{ $row['display'] }}</strong>
                                        </div>
                                    @endforeach
                                    <div class="revenue-decision-row">
                                        <span>Top Vendor by Revenue</span>
                                        <strong>{{ $vendorPerformance->get('top_vendor_name') ?: 'Unassigned' }} / {{ $compactCurrency((float) $vendorPerformance->get('top_vendor_revenue', 0)) }}</strong>
                                    </div>
                                </div>
                            @else
                                <div class="vendor-performance-empty">
                                    <strong>No vendor performance data</strong>
                                    <span>{{ $vendorPerformance->get('message') ?: 'Vendor revenue, cost, and margin will appear when vendor-supplied orders match the current filters.' }}</span>
                                </div>
                            @endif
                        </div>
                    </div>

                    <div class="sales-pulse-analytics">
                        <div class="sales-pulse-chart-stack">
                            <div class="sales-pulse-chart-card">
                                <div class="sales-pulse-card-head">
                                    <div>
                                        <div class="sales-pulse-card-title">Sales vs Collections Trend</div>
                                        <div class="sales-pulse-card-copy">Monthly invoiced value, collected value, and still-outstanding exposure from the existing dashboard feed.</div>
                                        <span class="sr-only">Cash &amp; Collections Overview</span>
                                    </div>
                                    <span class="sales-pulse-chip">Monthly</span>
                                </div>
                                <div class="sales-pulse-legend">
                                    <span class="sales-pulse-legend-item"><span class="sales-pulse-legend-swatch is-sales"></span>Invoiced Value</span>
                                    <span class="sales-pulse-legend-item"><span class="sales-pulse-legend-swatch is-rental"></span>Collected Value</span>
                                    <span class="sales-pulse-legend-item"><span class="sales-pulse-legend-swatch is-orders"></span>Outstanding Value</span>
                                </div>
                                <div class="sales-pulse-chart-shell">
                                    @if($revenueTrendHasData)
                                        <svg class="sales-pulse-chart-svg" viewBox="0 0 560 184" role="img" aria-label="Revenue center trend">
                                            <line class="sales-pulse-chart-grid" x1="22" y1="20" x2="538" y2="20"></line>
                                            <line class="sales-pulse-chart-grid" x1="22" y1="88" x2="538" y2="88"></line>
                                            <line class="sales-pulse-chart-grid" x1="22" y1="156" x2="538" y2="156"></line>
                                            @foreach($revenueTrendRows as $index => $row)
                                                @php
                                                    $x = 22 + (($revenueTrendChartWidth - 44) * ($index / max($revenueTrendRows->count() - 1, 1)));
                                                    $barHeight = (((float) ($row['outstanding_total'] ?? 0)) / max($revenueTrendMax, 1)) * 72;
                                                    $salesY = ($revenueTrendChartHeight - 22) - ((((float) ($row['sales_total'] ?? 0)) / max($revenueTrendMax, 1)) * ($revenueTrendChartHeight - 44));
                                                    $collectionsY = ($revenueTrendChartHeight - 22) - ((((float) ($row['collected_total'] ?? 0)) / max($revenueTrendMax, 1)) * ($revenueTrendChartHeight - 44));
                                                @endphp
                                                <rect class="sales-pulse-chart-bar" x="{{ round($x - 13, 2) }}" y="{{ round(156 - $barHeight, 2) }}" width="26" height="{{ round($barHeight, 2) }}" rx="8"></rect>
                                                <text class="sales-pulse-chart-value" x="{{ round($x, 2) }}" y="{{ round(max($salesY - 10, 14), 2) }}" text-anchor="middle">{{ $compactCurrency($row['sales_total'] ?? 0) }}</text>
                                                <text class="sales-pulse-chart-axis is-value" x="{{ round($x, 2) }}" y="{{ round(max($collectionsY - 8, 24), 2) }}" text-anchor="middle">{{ $compactCurrency($row['collected_total'] ?? 0) }}</text>
                                                <text class="sales-pulse-chart-axis" x="{{ round($x, 2) }}" y="174" text-anchor="middle">{{ \Illuminate\Support\Str::replace(' 2026', '', $row['label'] ?? '-') }}</text>
                                            @endforeach
                                            <polyline class="sales-pulse-chart-line-sales" points="{{ $revenueTrendSalesPoints }}"></polyline>
                                            <polyline class="sales-pulse-chart-line-rental" points="{{ $revenueTrendCollectionsPoints }}"></polyline>
                                            @foreach($revenueTrendRows as $index => $row)
                                                @php
                                                    $x = 22 + (($revenueTrendChartWidth - 44) * ($index / max($revenueTrendRows->count() - 1, 1)));
                                                    $salesY = ($revenueTrendChartHeight - 22) - ((((float) ($row['sales_total'] ?? 0)) / max($revenueTrendMax, 1)) * ($revenueTrendChartHeight - 44));
                                                    $collectionsY = ($revenueTrendChartHeight - 22) - ((((float) ($row['collected_total'] ?? 0)) / max($revenueTrendMax, 1)) * ($revenueTrendChartHeight - 44));
                                                @endphp
                                                <circle class="sales-pulse-chart-dot-sales" cx="{{ round($x, 2) }}" cy="{{ round($salesY, 2) }}" r="3.6"></circle>
                                                <circle class="sales-pulse-chart-dot-rental" cx="{{ round($x, 2) }}" cy="{{ round($collectionsY, 2) }}" r="3.1"></circle>
                                            @endforeach
                                        </svg>
                                    @else
                                        <div class="rx-empty dashboard-empty">
                                            <div class="rx-empty-icon">{!! $dashboardIcon('trend') !!}</div>
                                            <strong>No revenue trend data yet</strong>
                                            <span>The dashboard will show the sales versus collections curve once revenue data exists in the selected window.</span>
                                        </div>
                                    @endif
                                </div>
                            </div>

                            <div class="sales-pulse-chart-card">
                                <div class="sales-pulse-card-head">
                                    <div>
                                        <div class="sales-pulse-card-title">Business Performance</div>
                                        <div class="sales-pulse-card-copy">Rental revenue, sales revenue, and total order volume across the monthly trend window.</div>
                                    </div>
                                    @if($reportsIndexUrl)
                                        <a href="{{ $reportsIndexUrl }}" class="sales-pulse-card-link">Open Analytics</a>
                                    @endif
                                </div>
                                <div class="sales-pulse-legend">
                                    <span class="sales-pulse-legend-item"><span class="sales-pulse-legend-swatch is-rental"></span>Rental Revenue</span>
                                    <span class="sales-pulse-legend-item"><span class="sales-pulse-legend-swatch is-sales"></span>Sales Revenue</span>
                                    <span class="sales-pulse-legend-item"><span class="sales-pulse-legend-swatch is-orders"></span>Total Orders</span>
                                </div>
                                <div class="sales-pulse-chart-shell">
                                    @if($businessTrendHasData)
                                        <svg class="sales-pulse-chart-svg" viewBox="0 0 560 168" role="img" aria-label="Business performance chart">
                                            <line class="sales-pulse-chart-grid" x1="22" y1="18" x2="538" y2="18"></line>
                                            <line class="sales-pulse-chart-grid" x1="22" y1="82" x2="538" y2="82"></line>
                                            <line class="sales-pulse-chart-grid" x1="22" y1="146" x2="538" y2="146"></line>
                                            @foreach($monthlyTrendRows as $index => $row)
                                                @php
                                                    $x = 22 + (($businessTrendWidth - 44) * ($index / max($monthlyTrendRows->count() - 1, 1)));
                                                    $barHeight = (((float) ($row['total_orders'] ?? 0)) / max($businessTrendOrdersMax, 1)) * 58;
                                                    $rentalY = ($businessTrendHeight - 18) - ((((float) ($row['rental_total'] ?? 0)) / max($trendMax, 1)) * ($businessTrendHeight - 36));
                                                    $salesY = ($businessTrendHeight - 18) - ((((float) ($row['sales_total'] ?? 0)) / max($trendMax, 1)) * ($businessTrendHeight - 36));
                                                @endphp
                                                <rect class="sales-pulse-chart-bar" x="{{ round($x - 10, 2) }}" y="{{ round(146 - $barHeight, 2) }}" width="20" height="{{ round($barHeight, 2) }}" rx="7"></rect>
                                                <text class="sales-pulse-chart-axis" x="{{ round($x, 2) }}" y="162" text-anchor="middle">{{ \Illuminate\Support\Str::replace(' 2026', '', $row['label'] ?? '-') }}</text>
                                            @endforeach
                                            <polyline class="sales-pulse-chart-line-rental" points="{{ $businessTrendRentalPoints }}"></polyline>
                                            <polyline class="sales-pulse-chart-line-sales" points="{{ $businessTrendSalesPoints }}"></polyline>
                                            @foreach($monthlyTrendRows as $index => $row)
                                                @php
                                                    $x = 22 + (($businessTrendWidth - 44) * ($index / max($monthlyTrendRows->count() - 1, 1)));
                                                    $rentalY = ($businessTrendHeight - 18) - ((((float) ($row['rental_total'] ?? 0)) / max($trendMax, 1)) * ($businessTrendHeight - 36));
                                                    $salesY = ($businessTrendHeight - 18) - ((((float) ($row['sales_total'] ?? 0)) / max($trendMax, 1)) * ($businessTrendHeight - 36));
                                                @endphp
                                                <circle class="sales-pulse-chart-dot-rental" cx="{{ round($x, 2) }}" cy="{{ round($rentalY, 2) }}" r="3.2"></circle>
                                                <circle class="sales-pulse-chart-dot-sales" cx="{{ round($x, 2) }}" cy="{{ round($salesY, 2) }}" r="3.2"></circle>
                                            @endforeach
                                        </svg>
                                    @else
                                        <div class="rx-empty dashboard-empty">
                                            <div class="rx-empty-icon">{!! $dashboardIcon('trend') !!}</div>
                                            <strong>No performance trend yet</strong>
                                            <span>Rental revenue, sales revenue, and order volume will appear once monthly activity exists.</span>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <div class="sales-pulse-side-card">
                            <div class="sales-pulse-card-head">
                                <div>
                                    <div class="sales-pulse-card-title">Revenue Protection Visual</div>
                                    <div class="sales-pulse-card-copy">Outstanding exposure, month collections, overdue invoices, and collection progress.</div>
                                </div>
                            </div>
                            @if($revenueCenterTotal > 0)
                                <div class="sales-pulse-donut-layout">
                                    <div class="sales-pulse-donut" style="--collected-percent: {{ $revenueCenterCollectedPercent }}; --outstanding-percent: {{ $revenueCenterOutstandingPercent }};">
                                        <div class="sales-pulse-donut-center">
                                            <strong>Collected</strong>
                                            <span>{{ $revenueProtectionCollectionPercent }}%</span>
                                        </div>
                                    </div>
                                    <div class="sales-pulse-status-list">
                                        @foreach($revenueProtectionRows as $segment)
                                            <div class="sales-pulse-status-row">
                                                <span class="sales-pulse-status-dot {{ $toneCardClass($segment['tone'] ?? null) }}"></span>
                                                <span>{{ $segment['label'] }}</span>
                                                <strong>{{ $segment['display'] }}</strong>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                                <div class="revenue-protection-meter">
                                    <div class="revenue-protection-meter-top">
                                        <span>Collection Progress</span>
                                        <strong>{{ $revenueProtectionCollectionPercent }}%</strong>
                                    </div>
                                    <div class="revenue-protection-meter-track">
                                        <span style="width: {{ max(4, min(100, $revenueProtectionCollectionPercent)) }}%;"></span>
                                    </div>
                                </div>
                            @else
                                <div class="rx-empty dashboard-empty">
                                    <div class="rx-empty-icon">{!! $dashboardIcon('payment') !!}</div>
                                    <strong>No collection data yet</strong>
                                    <span>Collected, outstanding, and unbilled totals will appear here once invoice activity is available.</span>
                                </div>
                            @endif
                        </div>
                    </div>

                    <div class="sales-pulse-bottom-grid">
                        <div class="sales-pulse-bottom-card" id="revenue-aging">
                            <div class="sales-pulse-card-head">
                                <div>
                                    <div class="sales-pulse-card-title">Invoice Aging</div>
                                    <div class="sales-pulse-card-copy">Bucketed dues pressure by how far invoices have crossed their due windows.</div>
                                </div>
                                @if($invoiceIndexUrl)
                                    <a href="{{ $mergeDashboardQuery('invoices.index', ['status' => 'open']) }}" class="sales-pulse-card-link">View Dues</a>
                                @endif
                            </div>
                            @if($invoiceAgingBuckets->isNotEmpty())
                                <div class="control-room-aging-shell">
                                    <div class="control-room-aging-bar">
                                        @foreach($invoiceAgingBuckets as $bucket)
                                            @php
                                                $label = strtolower((string) ($bucket['label'] ?? ''));
                                                $color = str_contains($label, '30') ? '#ef4444' : (str_contains($label, '16') ? '#f97316' : (str_contains($label, '8') ? '#fbbf24' : '#60a5fa'));
                                            @endphp
                                            <span style="width: {{ max(round((((float) ($bucket['amount'] ?? 0)) / max($revenueCenterAgingMax, 1)) * 100, 1), 8) }}%; background: {{ $color }};"></span>
                                        @endforeach
                                    </div>
                                    <div class="control-room-aging-legend">
                                        @foreach($invoiceAgingBuckets as $bucket)
                                            <div class="control-room-aging-item">
                                                <strong>{{ $bucket['label'] ?? 'Bucket' }}</strong>
                                                <span>{{ $currency($bucket['amount'] ?? 0) }}</span>
                                                <small>{{ number_format((int) ($bucket['invoice_count'] ?? 0)) }} invoice(s)</small>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @else
                                <div class="rx-empty dashboard-empty">
                                    <div class="rx-empty-icon">{!! $dashboardIcon('overdue') !!}</div>
                                    <strong>No aging data yet</strong>
                                    <span>Open invoice aging will appear here once unpaid invoices are available.</span>
                                </div>
                            @endif
                        </div>

                        <div class="sales-pulse-bottom-card" id="revenue-actions">
                            <div class="sales-pulse-card-head">
                                <div>
                                    <div class="sales-pulse-card-title">Top Collection Actions</div>
                                    <div class="sales-pulse-card-copy">Priority invoice, renewal, and unbilled rows that need finance action next.</div>
                                </div>
                                @if($invoiceIndexUrl)
                                    <a href="{{ $mergeDashboardQuery('invoices.index', ['status' => 'open']) }}" class="sales-pulse-card-link">Open Queue</a>
                                @endif
                            </div>
                            @if($revenueCenterTopActions->isNotEmpty())
                                <div class="revenue-center-action-table">
                                    <div class="revenue-center-action-head">
                                        <span>Customer / Invoice</span>
                                        <span>Amount</span>
                                        <span>Age</span>
                                        <span>Type</span>
                                        <span>Action</span>
                                    </div>
                                    @foreach($revenueCenterTopActions as $row)
                                        <div class="revenue-center-action-row">
                                            <div class="revenue-center-action-copy">
                                                <strong>{{ $row['label'] }}</strong>
                                                <small>{{ \Illuminate\Support\Str::headline((string) ($row['tone'] ?? 'info')) }} priority</small>
                                            </div>
                                            <strong>{{ $currency($row['amount'] ?? 0) }}</strong>
                                            <span>{{ $row['age'] }}</span>
                                            <span class="rx-badge {{ $toneCardClass($row['tone'] ?? null) }}">{{ $row['type'] }}</span>
                                            @if(!empty($row['href']))
                                                <a href="{{ $row['href'] }}" class="sales-pulse-card-link">{{ $row['action'] }}</a>
                                            @else
                                                <span>-</span>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                <div class="rx-empty dashboard-empty">
                                    <div class="rx-empty-icon">{!! $dashboardIcon('completed') !!}</div>
                                    <strong>No collection actions pending</strong>
                                    <span>There are no overdue invoices or unbilled rows requiring immediate finance action right now.</span>
                                </div>
                            @endif
                        </div>
                    </div>

                    <div class="sales-pulse-bottom-card" id="revenue-activity">
                        <div class="sales-pulse-card-head">
                            <div>
                                <div class="sales-pulse-card-title">Recent Revenue Activity</div>
                                <div class="sales-pulse-card-copy">Recent payments, invoices, and sales-side revenue movements without repeating the broader activity center.</div>
                            </div>
                            @if($invoiceIndexUrl)
                                <a href="{{ $invoiceIndexUrl }}" class="sales-pulse-card-link">View All Activity</a>
                            @endif
                        </div>
                        @if($recentRevenueActivity->isNotEmpty())
                            <div class="revenue-center-activity-list">
                                @foreach($recentRevenueActivity->take(6) as $item)
                                    <a href="{{ $item['href'] ?? '#' }}" class="revenue-center-activity-item">
                                        <div class="revenue-center-activity-top">
                                            <strong>{{ $item['title'] }}</strong>
                                            <span class="rx-badge">{{ $item['badge'] }}</span>
                                        </div>
                                        <span>{{ $item['meta'] }}</span>
                                        <div class="revenue-center-activity-top">
                                            <small>{{ $item['timestamp'] }}</small>
                                            <strong>{{ $item['amount'] }}</strong>
                                        </div>
                                    </a>
                                @endforeach
                            </div>
                        @else
                            <div class="rx-empty dashboard-empty">
                                <div class="rx-empty-icon">{!! $dashboardIcon('revenue') !!}</div>
                                <strong>No recent revenue activity</strong>
                                <span>Payments and sales invoice events will appear here once finance activity is available.</span>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </section>
    @endif

    @if($primaryPriorityCards->isNotEmpty())
        <section class="dashboard-main-grid" id="top-priority-panel">
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
    </section>
    @endif

    @if($actionItems->isNotEmpty())
    <section class="dashboard-action-layout" id="today-action-panel">
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
    </section>
    @endif

    @if($deliveryMiniTiles->isNotEmpty())
    <section class="rx-card" id="logistics-board-panel">
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
    @endif

    @if(false && $renewalMiniTiles->isNotEmpty())
        <section class="rx-card" id="renewal-center-panel">
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

    @if(false && $pickupCenterMiniTiles->isNotEmpty())
        <section class="rx-card" id="pickup-center-panel">
            <div class="rx-card-header">
                <div>
                    <h2 class="rx-card-title">Pickup Center</h2>
                    <p class="rx-card-copy">Operational pickup queue for today, overdue collections, failed attempts, and return-verification handoff.</p>
                </div>
                <a href="{{ route('pickup-center.index') }}" class="rx-btn-secondary">Open Pickup Center</a>
            </div>
            <div class="rx-card-body">
                <div class="dashboard-logistics-grid">
                    @foreach($pickupCenterMiniTiles as $tile)
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

    @if(false && $communicationMiniTiles->isNotEmpty())
        <section class="rx-card" id="communication-center-panel">
            <div class="rx-card-header">
                <div>
                    <h2 class="rx-card-title">Communication Center</h2>
                    <p class="rx-card-copy">Due, overdue, and coordination follow-ups across renewals, payments, deliveries, and pickups.</p>
                </div>
                <a href="{{ route('communication-center.index') }}" class="rx-btn-secondary">Open Communication Center</a>
            </div>
            <div class="rx-card-body">
                <div class="dashboard-logistics-grid">
                    @foreach($communicationMiniTiles as $tile)
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

                    </div>
                </details>
            </div>
        </div>
    </section>


    @if($showInventorySection)
    <section class="rx-card" id="inventory-center-panel">
        <div class="rx-card-header dashboard-section-heading">
            <div>
                <h2 class="rx-card-title">Inventory Center</h2>
                <p class="rx-card-copy">Can we fulfil orders today, what is unavailable, and which products or assets need attention first.</p>
            </div>
        </div>
        <div class="rx-card-body">
            <div class="inventory-center-grid">
                <div class="inventory-health-grid">
                    @foreach($inventoryHealthCards as $card)
                        @php $tag = !empty($card['href']) ? 'a' : 'div'; @endphp
                        <{{ $tag }} @if(!empty($card['href'])) href="{{ $card['href'] }}" @endif class="inventory-health-card {{ $toneCardClass($card['tone'] ?? null) }}">
                            <div class="inventory-health-head">
                                <span class="inventory-health-label">{{ $card['label'] }}</span>
                                <span class="inventory-health-icon">{!! $dashboardIcon($card['icon']) !!}</span>
                            </div>
                            <strong class="inventory-health-value">{{ $card['value'] }}</strong>
                            <span class="inventory-health-status">{{ $card['status'] }}</span>
                            <span class="inventory-health-note">{{ $card['note'] }}</span>
                            <span class="inventory-health-link">{{ $card['action'] }} →</span>
                        </{{ $tag }}>
                    @endforeach
                </div>

                <div class="inventory-layout-grid">
                    <section class="control-room-card">
                        <div class="control-room-card-header">
                            <div>
                                <h3 class="control-room-card-title">Inventory Availability Visual</h3>
                                <p class="control-room-card-copy">Availability split across ready assets, deployed stock, maintenance queue, and blocked inventory.</p>
                            </div>
                            @if($inventoryUrl)
                                <a href="{{ $inventoryUrl }}" class="control-room-card-link">View Inventory</a>
                            @endif
                        </div>
                        <div class="inventory-donut-shell">
                            <div class="inventory-availability-visual">
                                @php
                                    $pieStops = [];
                                    $pieStart = 0;
                                    $piePalette = [
                                        'green' => '#22c55e',
                                        'blue' => '#3b82f6',
                                        'amber' => '#f59e0b',
                                        'red' => '#ef4444',
                                    ];
                                    foreach ($inventoryAvailabilitySegments as $segment) {
                                        $toneKey = match ($segment['tone'] ?? null) {
                                            'green' => 'green',
                                            'blue' => 'blue',
                                            'amber' => 'amber',
                                            'red' => 'red',
                                            default => 'blue',
                                        };
                                        $color = $piePalette[$toneKey] ?? '#3b82f6';
                                        $pieEnd = $pieStart + (float) ($segment['percent'] ?? 0);
                                        $pieStops[] = $color . ' ' . number_format($pieStart, 1, '.', '') . '% ' . number_format($pieEnd, 1, '.', '') . '%';
                                        $pieStart = $pieEnd;
                                    }
                                    if (empty($pieStops)) {
                                        $pieStops[] = '#dbe5f1 0% 100%';
                                    }
                                @endphp
                                <div
                                    class="inventory-availability-pie"
                                    style="background: conic-gradient({{ implode(', ', $pieStops) }});"
                                    aria-label="Inventory availability pie chart"
                                >
                                    <div class="inventory-availability-total">
                                        <strong>{{ number_format($inventoryAvailabilityTotal) }}</strong>
                                        <span>Total inventory assets tracked</span>
                                    </div>
                                </div>
                            </div>
                            <div class="inventory-availability-legend">
                                @foreach($inventoryAvailabilitySegments as $segment)
                                    <div class="inventory-availability-legend-item">
                                        <span class="inventory-availability-dot {{ $toneCardClass($segment['tone'] ?? null) }}"></span>
                                        <div class="inventory-availability-copy">
                                            <strong>{{ $segment['label'] }}</strong>
                                            <span>{{ number_format((int) ($segment['value'] ?? 0)) }} asset(s)</span>
                                        </div>
                                        <span class="inventory-availability-value">{{ number_format((float) ($segment['percent'] ?? 0), 1) }}%</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </section>

                    <section class="control-room-card inventory-readiness-panel">
                        <div class="control-room-card-header">
                            <div>
                                <h3 class="control-room-card-title">Fulfilment Readiness</h3>
                                <p class="control-room-card-copy">What is ready now, what is at risk, and which assets are waiting to return into usable stock.</p>
                            </div>
                        </div>
                        <div class="inventory-readiness-grid">
                            @foreach($fulfilmentReadinessRows as $row)
                                <div class="inventory-readiness-card {{ $toneCardClass($row['tone'] ?? null) }}">
                                    <span class="inventory-readiness-label">{{ $row['label'] }}</span>
                                    <strong class="inventory-readiness-value">{{ $row['value'] }}</strong>
                                    <span class="inventory-readiness-note">{{ $row['note'] }}</span>
                                </div>
                            @endforeach
                        </div>
                    </section>
                </div>

                <div class="inventory-layout-grid">
                    <section class="control-room-card" id="inventory-risk-panel">
                        <div class="control-room-card-header">
                            <div>
                                <h3 class="control-room-card-title">Product Risk Board</h3>
                                <p class="control-room-card-copy">Products at risk because they are low, fully out, highly utilized, or sitting idle instead of supporting fulfilment.</p>
                            </div>
                            @if($productsIndexUrl || $inventoryUrl)
                                <a href="{{ $productsIndexUrl ?? $inventoryUrl }}" class="control-room-card-link">View All Product Risks</a>
                            @endif
                        </div>
                        @if($productRiskRows->isNotEmpty())
                            <div class="inventory-risk-wrap">
                                <table class="inventory-risk-table">
                                    <thead>
                                        <tr>
                                            <th>Product</th>
                                            <th>Available</th>
                                            <th>Required</th>
                                            <th>Risk</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($productRiskRows->take(5) as $row)
                                            <tr>
                                                <td><span class="inventory-risk-name">{{ $row['name'] }}</span></td>
                                                <td>{{ number_format((int) ($row['available'] ?? 0)) }}</td>
                                                <td>{{ number_format((int) ($row['required'] ?? 0)) }}</td>
                                                <td><span class="rx-badge {{ $toneCardClass($row['tone'] ?? null) }}">{{ $row['risk'] }}</span></td>
                                                <td><a href="{{ $row['href'] }}" class="inventory-risk-link">{{ $row['action'] }}</a></td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                                @if($productRiskRows->count() > 5)
                                    <details class="dashboard-expandable">
                                        <summary class="dashboard-expandable-summary">Show {{ $productRiskRows->count() - 5 }} more</summary>
                                        <div class="dashboard-expandable-content">
                                            <table class="inventory-risk-table">
                                                <tbody>
                                                    @foreach($productRiskRows->slice(5) as $row)
                                                        <tr>
                                                            <td><span class="inventory-risk-name">{{ $row['name'] }}</span></td>
                                                            <td>{{ number_format((int) ($row['available'] ?? 0)) }}</td>
                                                            <td>{{ number_format((int) ($row['required'] ?? 0)) }}</td>
                                                            <td><span class="rx-badge {{ $toneCardClass($row['tone'] ?? null) }}">{{ $row['risk'] }}</span></td>
                                                            <td><a href="{{ $row['href'] }}" class="inventory-risk-link">{{ $row['action'] }}</a></td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    </details>
                                @endif
                            </div>
                        @else
                            <div class="rx-empty dashboard-empty">
                                <div class="rx-empty-icon">{!! $dashboardIcon('asset') !!}</div>
                                <strong>No immediate product risk</strong>
                                <span>Low stock, high utilization, and idle inventory signals are currently under control.</span>
                            </div>
                        @endif
                    </section>

                    <section class="control-room-card inventory-warehouse-panel">
                        <div class="control-room-card-header">
                            <div>
                                <h3 class="control-room-card-title">Warehouse Snapshot</h3>
                                <p class="control-room-card-copy">Compact warehouse contribution cards for dispatch planning and fulfilment source balancing.</p>
                            </div>
                        </div>
                        @if($warehouseSnapshotRows->isNotEmpty())
                            <div class="inventory-warehouse-grid">
                                @foreach($warehouseSnapshotRows as $row)
                                    @php $tag = !empty($row['href']) ? 'a' : 'div'; @endphp
                                    <{{ $tag }} @if(!empty($row['href'])) href="{{ $row['href'] }}" @endif class="inventory-warehouse-card">
                                        <div class="inventory-warehouse-top">
                                            <span class="inventory-warehouse-label">{{ $row['label'] }}</span>
                                            <span class="inventory-health-link">Open →</span>
                                        </div>
                                        <strong class="inventory-warehouse-value">{{ number_format((int) ($row['count'] ?? 0)) }}</strong>
                                        <span class="inventory-warehouse-note">{{ number_format((int) ($row['count'] ?? 0)) }} asset-linked rental(s)</span>
                                        <span class="inventory-warehouse-meta">{{ $row['amount'] }}</span>
                                    </{{ $tag }}>
                                @endforeach
                            </div>
                        @else
                            <div class="rx-empty dashboard-empty">
                                <div class="rx-empty-icon">{!! $dashboardIcon('warehouse') !!}</div>
                                <strong>No warehouse snapshot</strong>
                                <span>Warehouse distribution will appear here once dispatch-linked rentals are available.</span>
                            </div>
                        @endif
                    </section>
                </div>

                <section class="control-room-card">
                    <div class="control-room-card-header">
                        <div>
                            <h3 class="control-room-card-title">Inventory Movements</h3>
                            <p class="control-room-card-copy">Last five inventory-related events across products, stock, assets, warehouses, and verification activity.</p>
                        </div>
                    </div>
                    @if($inventoryMovementRows->isNotEmpty())
                        <div class="inventory-movement-list">
                            @foreach($inventoryMovementRows as $row)
                                @php $tag = !empty($row['href']) ? 'a' : 'div'; @endphp
                                <{{ $tag }} @if(!empty($row['href'])) href="{{ $row['href'] }}" @endif class="inventory-movement-item">
                                    <div>
                                        <strong>{{ $row['title'] }}</strong>
                                        <span>{{ $row['meta'] }}</span>
                                    </div>
                                    <em>{{ $row['time'] }}</em>
                                </{{ $tag }}>
                            @endforeach
                        </div>
                    @else
                        <div class="rx-empty dashboard-empty">
                            <div class="rx-empty-icon">{!! $dashboardIcon('inventory') !!}</div>
                            <strong>No recent inventory movements</strong>
                            <span>Stock, asset, and warehouse actions will appear here as soon as the team records them.</span>
                        </div>
                    @endif
                </section>

                <details class="inventory-detail-group" id="inventory-detail-panel">
                    <summary class="inventory-detail-summary">
                        <div>
                            <strong>View Detailed Inventory Analytics</strong>
                            <span>Open the deeper low-stock, utilization, idle inventory, and warehouse analytics only when you need investigation detail.</span>
                        </div>
                    </summary>
                    <div class="inventory-detail-content">
                        <section class="dashboard-insight-grid">
                            <div class="rx-card dashboard-feed-card">
                                <div class="rx-card-header">
                                    <div>
                                        <h2 class="rx-card-title">Inventory Intelligence</h2>
                                        <p class="rx-card-copy">Low stock, high utilization, and idle inventory in one collapsed operational lens.</p>
                                    </div>
                                    @if($productsIndexUrl)
                                        <a href="{{ $productsIndexUrl }}" class="rx-btn-secondary">Open Product Master</a>
                                    @endif
                                </div>
                                <div class="rx-card-body">
                                    <div class="dashboard-feed-list">
                                        <div class="dashboard-feed-item">
                                            <div class="dashboard-feed-meta">
                                                <strong>Low stock alerts</strong>
                                                <em>{{ $lowStockSummary->count() }}</em>
                                            </div>
                                            <small>
                                                @if($lowStockSummaryVisible->isNotEmpty())
                                                    {{ $lowStockSummaryVisible->map(fn ($product) => $product->name . ' (' . $product->available_quantity . '/' . $product->total_quantity . ')')->implode(', ') }}
                                                @else
                                                    No immediate low-stock pressure.
                                                @endif
                                            </small>
                                        </div>
                                        <div class="dashboard-feed-item">
                                            <div class="dashboard-feed-meta">
                                                <strong>High utilization</strong>
                                                <em>{{ $highUtilizationSummary->count() }}</em>
                                            </div>
                                            <small>
                                                @if($highUtilizationSummaryVisible->isNotEmpty())
                                                    {{ $highUtilizationSummaryVisible->map(fn ($product) => $product->name . ' (' . max(0, (int) $product->total_quantity - (int) $product->available_quantity) . '/' . $product->total_quantity . ' out)')->implode(', ') }}
                                                @else
                                                    No high-utilization products flagged right now.
                                                @endif
                                            </small>
                                        </div>
                                        <div class="dashboard-feed-item">
                                            <div class="dashboard-feed-meta">
                                                <strong>Idle inventory</strong>
                                                <em>{{ $idleInventorySummary->count() }}</em>
                                            </div>
                                            <small>
                                                @if($idleInventorySummaryVisible->isNotEmpty())
                                                    {{ $idleInventorySummaryVisible->map(fn ($product) => $product->name . ' (' . $product->available_quantity . ' available)')->implode(', ') }}
                                                @else
                                                    No idle stock signals at the moment.
                                                @endif
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </section>

                        @if($showOrganizationAnalyticsSection)
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

                            <div class="rx-card dashboard-rank-card" id="warehouse-analytics">
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
                        @endif
                    </div>
                </details>
            </div>
        </div>
    </section>
    @endif


    @if($showExpandedStaffOpsSection)
    <section class="dashboard-insight-grid" id="staff-ops">
        @if($showExpandedStaffWorkloadSection)
        <div class="rx-card dashboard-feed-card">
            <div class="rx-card-header">
                <div>
                    <h2 class="rx-card-title">Staff Workload</h2>
                    <p class="rx-card-copy">Who is overloaded, balanced, or light across deliveries, pickups, and follow-ups.</p>
                </div>
            </div>
            <div class="rx-card-body">
                @if($staffWorkloadSummary->isNotEmpty())
                    <div class="dashboard-feed-list">
                        @foreach($staffWorkloadSummary as $row)
                            <div class="dashboard-feed-item">
                                <div class="dashboard-feed-title">
                                    <strong>{{ $row['name'] }}</strong>
                                    <span class="dashboard-role-chip">{{ $row['load_state'] }}</span>
                                </div>
                                <span>{{ $row['delivery_count'] }} deliveries â€¢ {{ $row['pickup_count'] }} pickups â€¢ {{ $row['followup_count'] }} follow-ups</span>
                                <small>{{ $row['overdue_count'] }} overdue â€¢ {{ \Illuminate\Support\Str::headline((string) ($row['role'] ?? 'team')) }}</small>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="rx-empty dashboard-empty">
                        <div class="rx-empty-icon">{!! $dashboardIcon('customer') !!}</div>
                        <strong>No staff workload data</strong>
                        <span>Assignments will appear here as soon as work is distributed.</span>
                    </div>
                @endif
            </div>
        </div>
        @endif

        @if($showExpandedBusinessSignalsSection)
        <div class="rx-card dashboard-feed-card">
            <div class="rx-card-header">
                <div>
                    <h2 class="rx-card-title">Business Partner Signals</h2>
                    <p class="rx-card-copy">Partner workload, open rentals, and renewal or payment pressure.</p>
                </div>
                @if($newBusinessPartnerUrl)
                    <a href="{{ $newBusinessPartnerUrl }}" class="rx-btn-secondary">Add Business Partner</a>
                @endif
            </div>
            <div class="rx-card-body">
                @if($partnerOperationalSummary->isNotEmpty())
                    <div class="dashboard-feed-list">
                        @foreach($partnerOperationalSummaryVisible as $partner)
                            <div class="dashboard-feed-item">
                                <div class="dashboard-feed-title">
                                    <strong>{{ $partner['name'] }}</strong>
                                    <span class="dashboard-role-chip">{{ $partner['active_clients_count'] }} clients</span>
                                </div>
                                <span>{{ $partner['open_rentals_count'] }} open rentals â€¢ {{ $partner['active_sales_count'] }} sales</span>
                                <small>{{ $partner['renewal_followups_count'] }} renewal follow-ups â€¢ {{ $partner['payment_followups_count'] }} payment follow-ups</small>
                                <div class="dashboard-feed-links">
                                    <a href="{{ route('business-partners.show', $partner['id']) }}">Open</a>
                                    @if(!empty($partner['phone']))
                                        <a href="tel:{{ preg_replace('/\s+/', '', (string) $partner['phone']) }}">Call</a>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                        @if($partnerOperationalSummaryHidden->isNotEmpty())
                            <details class="dashboard-expandable">
                                <summary class="dashboard-expandable-summary">Show {{ $partnerOperationalSummaryHidden->count() }} more</summary>
                                <div class="dashboard-expandable-content">
                                    @foreach($partnerOperationalSummaryHidden as $partner)
                                        <div class="dashboard-feed-item">
                                            <div class="dashboard-feed-title">
                                                <strong>{{ $partner['name'] }}</strong>
                                                <span class="dashboard-role-chip">{{ $partner['active_clients_count'] }} clients</span>
                                            </div>
                                            <span>{{ $partner['open_rentals_count'] }} open rentals • {{ $partner['active_sales_count'] }} sales</span>
                                            <small>{{ $partner['renewal_followups_count'] }} renewal follow-ups • {{ $partner['payment_followups_count'] }} payment follow-ups</small>
                                            <div class="dashboard-feed-links">
                                                <a href="{{ route('business-partners.show', $partner['id']) }}">Open</a>
                                                @if(!empty($partner['phone']))
                                                    <a href="tel:{{ preg_replace('/\s+/', '', (string) $partner['phone']) }}">Call</a>
                                                @endif
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </details>
                        @endif
                    </div>
                @else
                    <div class="rx-empty dashboard-empty">
                        <div class="rx-empty-icon">{!! $dashboardIcon('customer') !!}</div>
                        <strong>No partner pressure signals</strong>
                        <span>Partner-linked renewals and payments will surface here automatically.</span>
                    </div>
                @endif
            </div>
        </div>
        @endif

        @if(false && $showExpandedInventorySection)
        <div class="rx-card dashboard-feed-card">
            <div class="rx-card-header">
                <div>
                    <h2 class="rx-card-title">Inventory Intelligence</h2>
                    <p class="rx-card-copy">Low stock, highly utilized, and idle inventory without needing a full stock report.</p>
                </div>
                @if($productsIndexUrl)
                    <a href="{{ $productsIndexUrl }}" class="rx-btn-secondary">Open Product Master</a>
                @endif
            </div>
            <div class="rx-card-body">
                <div class="dashboard-feed-list">
                    <div class="dashboard-feed-item">
                        <div class="dashboard-feed-meta">
                            <strong>Low stock alerts</strong>
                            <em>{{ $lowStockSummary->count() }}</em>
                        </div>
                        @if($lowStockSummaryVisible->isNotEmpty())
                            <small>{{ $lowStockSummaryVisible->map(fn ($product) => $product->name . ' (' . $product->available_quantity . '/' . $product->total_quantity . ')')->implode(', ') }}</small>
                            @if($lowStockSummaryHidden->isNotEmpty())
                                <details class="dashboard-expandable">
                                    <summary class="dashboard-expandable-summary">Show {{ $lowStockSummaryHidden->count() }} more</summary>
                                    <div class="dashboard-expandable-content">
                                        <small>{{ $lowStockSummaryHidden->map(fn ($product) => $product->name . ' (' . $product->available_quantity . '/' . $product->total_quantity . ')')->implode(', ') }}</small>
                                    </div>
                                </details>
                            @endif
                        @else
                            <small>No immediate low-stock pressure.</small>
                        @endif
                    </div>
                    <div class="dashboard-feed-item">
                        <div class="dashboard-feed-meta">
                            <strong>High utilization</strong>
                            <em>{{ $highUtilizationSummary->count() }}</em>
                        </div>
                        @if($highUtilizationSummaryVisible->isNotEmpty())
                            <small>{{ $highUtilizationSummaryVisible->map(fn ($product) => $product->name . ' (' . max(0, (int) $product->total_quantity - (int) $product->available_quantity) . '/' . $product->total_quantity . ' out)')->implode(', ') }}</small>
                            @if($highUtilizationSummaryHidden->isNotEmpty())
                                <details class="dashboard-expandable">
                                    <summary class="dashboard-expandable-summary">Show {{ $highUtilizationSummaryHidden->count() }} more</summary>
                                    <div class="dashboard-expandable-content">
                                        <small>{{ $highUtilizationSummaryHidden->map(fn ($product) => $product->name . ' (' . max(0, (int) $product->total_quantity - (int) $product->available_quantity) . '/' . $product->total_quantity . ' out)')->implode(', ') }}</small>
                                    </div>
                                </details>
                            @endif
                        @else
                            <small>No high-utilization products flagged right now.</small>
                        @endif
                    </div>
                    <div class="dashboard-feed-item">
                        <div class="dashboard-feed-meta">
                            <strong>Idle inventory</strong>
                            <em>{{ $idleInventorySummary->count() }}</em>
                        </div>
                        @if($idleInventorySummaryVisible->isNotEmpty())
                            <small>{{ $idleInventorySummaryVisible->map(fn ($product) => $product->name . ' (' . $product->available_quantity . ' available)')->implode(', ') }}</small>
                            @if($idleInventorySummaryHidden->isNotEmpty())
                                <details class="dashboard-expandable">
                                    <summary class="dashboard-expandable-summary">Show {{ $idleInventorySummaryHidden->count() }} more</summary>
                                    <div class="dashboard-expandable-content">
                                        <small>{{ $idleInventorySummaryHidden->map(fn ($product) => $product->name . ' (' . $product->available_quantity . ' available)')->implode(', ') }}</small>
                                    </div>
                                </details>
                            @endif
                        @else
                            <small>No idle stock signals at the moment.</small>
                        @endif
                    </div>
                </div>
            </div>
        </div>
        @endif
    </section>
    @endif

    <details class="rx-card activity-control-shell" id="recent-ops">
        <summary class="rx-card-header dashboard-section-heading activity-control-header">
            <div>
                <h2 class="rx-card-title">Activity &amp; Communication Center</h2>
                <p class="rx-card-copy">Recent movement, unresolved alerts, communication workload, and the highest-priority escalations in one compact panel.</p>
            </div>
            @if($activityCenterLinks->isNotEmpty())
                <div class="activity-control-links">
                    @foreach($activityCenterLinks as $link)
                        <a href="{{ $link['href'] }}">{{ $link['label'] }} →</a>
                    @endforeach
                </div>
            @endif
        </summary>
        <div class="rx-card-body">
            <div class="activity-control-grid">
                <div class="activity-control-card">
                    <div class="activity-control-card-head">
                        <span class="activity-control-title">Recent Activity</span>
                        <span class="activity-control-count">{{ $activityRecentItems->count() }} item(s)</span>
                    </div>
                    @if($activityRecentItems->isNotEmpty())
                        <div class="activity-control-list">
                            @foreach($activityRecentItems as $item)
                                @php $activityTag = !empty($item['href']) ? 'a' : 'div'; @endphp
                                <{{ $activityTag }} @if(!empty($item['href'])) href="{{ $item['href'] }}" @endif class="activity-control-item">
                                    <span class="activity-control-dot is-{{ $item['tone'] ?? 'blue' }}"></span>
                                    <span class="activity-control-main">
                                        <strong>{{ $item['title'] }}</strong>
                                        <span>{{ $item['type'] }} / {{ $item['meta'] }}</span>
                                    </span>
                                    <span class="activity-control-side">
                                        <strong>{{ $item['value'] }}</strong>
                                        <span>{{ $item['time'] }}</span>
                                    </span>
                                </{{ $activityTag }}>
                            @endforeach
                        </div>
                    @else
                        <div class="rx-empty dashboard-empty">
                            <div class="rx-empty-icon">{!! $dashboardIcon('trend') !!}</div>
                            <strong>No recent activity</strong>
                            <span>Payments, invoices, rentals, deliveries, and pickups will appear here as work moves.</span>
                        </div>
                    @endif
                </div>

                <div class="activity-control-card">
                    <div class="activity-control-card-head">
                        <span class="activity-control-title">Notifications Summary</span>
                        <span class="activity-control-count">Unread / critical / assigned</span>
                    </div>
                    <div class="activity-control-kpis">
                        @foreach($notificationSummaryRows as $row)
                            <div class="activity-control-kpi {{ $toneCardClass($row['tone'] ?? null) }}">
                                <span>{{ $row['label'] }}</span>
                                <strong>{{ number_format((int) $row['value']) }}</strong>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="activity-control-card" id="activity-alerts">
                    <div class="activity-control-card-head">
                        <span class="activity-control-title">Alerts &amp; Escalations</span>
                        <span class="activity-control-count">Max 8</span>
                    </div>
                    <div class="activity-control-list">
                        @foreach($activityAlertItems as $item)
                            @php $alertTag = !empty($item['href']) ? 'a' : 'div'; @endphp
                            <{{ $alertTag }} @if(!empty($item['href'])) href="{{ $item['href'] }}" @endif class="activity-control-item">
                                <span class="activity-control-dot is-{{ $item['tone'] ?? 'blue' }}"></span>
                                <span class="activity-control-main">
                                    <strong>{{ $item['label'] }}</strong>
                                    <span>{{ $item['status'] }}</span>
                                </span>
                                <span class="activity-control-side">
                                    <strong>{{ number_format((int) $item['count']) }}</strong>
                                    <span>open</span>
                                </span>
                            </{{ $alertTag }}>
                        @endforeach
                    </div>
                </div>

                <div class="activity-control-card" id="activity-communication">
                    <div class="activity-control-card-head">
                        <span class="activity-control-title">Communication Queue</span>
                        <span class="activity-control-count">Calls and reminders</span>
                    </div>
                    <div class="activity-control-queue">
                        @foreach($communicationQueueRows as $row)
                            @php $queueTag = !empty($row['href']) ? 'a' : 'div'; @endphp
                            <{{ $queueTag }} @if(!empty($row['href'])) href="{{ $row['href'] }}" @endif class="activity-control-queue-card {{ $toneCardClass($row['tone'] ?? null) }}">
                                <span>{{ $row['label'] }}</span>
                                <strong>{{ number_format((int) $row['value']) }}</strong>
                                <small>{{ $row['note'] }}</small>
                            </{{ $queueTag }}>
                        @endforeach
                    </div>
                </div>

                <div class="activity-control-card is-wide" id="activity-escalations">
                    <div class="activity-control-card-head">
                        <span class="activity-control-title">Escalation Queue</span>
                        <span class="activity-control-count">{{ $activityEscalationRows->count() }} item(s)</span>
                    </div>
                    @if($activityEscalationRows->isNotEmpty())
                        <div class="activity-control-list">
                            @foreach($activityEscalationRows as $row)
                                @php $escalationTag = !empty($row['href']) ? 'a' : 'div'; @endphp
                                <{{ $escalationTag }} @if(!empty($row['href'])) href="{{ $row['href'] }}" @endif class="activity-control-item">
                                    <span class="activity-control-dot is-{{ $row['tone'] ?? 'blue' }}"></span>
                                    <span class="activity-control-main">
                                        <strong>{{ $row['title'] }}</strong>
                                        <span>{{ $row['owner'] }} / {{ $row['status'] }}</span>
                                    </span>
                                    <span class="activity-control-side">
                                        <strong>{{ $row['priority'] }}</strong>
                                        <span>{{ $row['action'] }} →</span>
                                    </span>
                                </{{ $escalationTag }}>
                            @endforeach
                        </div>
                    @else
                        <div class="rx-empty dashboard-empty">
                            <div class="rx-empty-icon">{!! $dashboardIcon('tasks') !!}</div>
                            <strong>No unresolved escalations</strong>
                            <span>The top-priority queue is clear for the selected view.</span>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </details>

    @if(false && (
        $dashboardWidgetEnabled('section_recent_activity')
        || $dashboardWidgetEnabled('section_recent_deliveries')
        || $dashboardWidgetEnabled('section_high_priority_followups')
    ))
    <section class="dashboard-recent-grid" id="recent-ops">
        @if($dashboardWidgetEnabled('section_recent_activity'))
        <div class="rx-card dashboard-feed-card">
            <div class="rx-card-header">
                <div>
                    <h2 class="rx-card-title">Recent Activity</h2>
                    <p class="rx-card-copy">Unified operational feed from timeline and system activity.</p>
                </div>
            </div>
            <div class="rx-card-body">
                @if($recentActivitiesSummary->isNotEmpty())
                    <div class="dashboard-feed-list">
                        @foreach($recentActivitiesSummaryVisible as $activity)
                            <div class="dashboard-feed-item">
                                <div class="dashboard-feed-meta">
                                    <strong>{{ \Illuminate\Support\Str::headline(str_replace('.', ' ', (string) $activity->action)) }}</strong>
                                    <em>{{ optional($activity->created_at)?->diffForHumans() }}</em>
                                </div>
                                <span>{{ $activity->description ?: 'Activity recorded in the operational timeline.' }}</span>
                                <small>{{ $activity->user?->name ?: 'System' }}</small>
                            </div>
                        @endforeach
                        @if($recentActivitiesSummaryHidden->isNotEmpty())
                            <details class="dashboard-expandable">
                                <summary class="dashboard-expandable-summary">Show {{ $recentActivitiesSummaryHidden->count() }} more</summary>
                                <div class="dashboard-expandable-content">
                                    @foreach($recentActivitiesSummaryHidden as $activity)
                                        <div class="dashboard-feed-item">
                                            <div class="dashboard-feed-meta">
                                                <strong>{{ \Illuminate\Support\Str::headline(str_replace('.', ' ', (string) $activity->action)) }}</strong>
                                                <em>{{ optional($activity->created_at)?->diffForHumans() }}</em>
                                            </div>
                                            <span>{{ $activity->description ?: 'Activity recorded in the operational timeline.' }}</span>
                                            <small>{{ $activity->user?->name ?: 'System' }}</small>
                                        </div>
                                    @endforeach
                                </div>
                            </details>
                        @endif
                    </div>
                @else
                    <div class="rx-empty dashboard-empty">
                        <div class="rx-empty-icon">{!! $dashboardIcon('trend') !!}</div>
                        <strong>No recent activity feed</strong>
                        <span>Timeline and workflow actions will appear here as the team works.</span>
                    </div>
                @endif
            </div>
        </div>
        @endif

        @if($dashboardWidgetEnabled('section_recent_deliveries'))
        <div class="rx-card dashboard-feed-card">
            <div class="rx-card-header">
                <div>
                    <h2 class="rx-card-title">Recent Deliveries</h2>
                    <p class="rx-card-copy">Most recently updated field tasks with direct contact and map access.</p>
                </div>
                @if($deliveriesIndexUrl)
                    <a href="{{ $deliveriesIndexUrl }}" class="rx-btn-secondary">Open Task Board</a>
                @endif
            </div>
            <div class="rx-card-body">
                @if($recentDeliveriesSummary->isNotEmpty())
                    <div class="dashboard-feed-list">
                        @foreach($recentDeliveriesSummaryVisible as $task)
                            <div class="dashboard-feed-item">
                                <div class="dashboard-feed-title">
                                    <strong>{{ ucfirst((string) $task->type) }} #{{ $task->id }}</strong>
                                    <span class="rx-badge {{ $statusBadgeClass($task->pickupOperationalStatus()) }}">{{ $task->type === 'pickup' ? $task->pickupOperationalLabel() : \Illuminate\Support\Str::headline((string) $task->status) }}</span>
                                </div>
                                <span>{{ $task->linkedCustomerName() }} â€¢ {{ $task->linkedCustomerPhone() ?: 'No phone' }}</span>
                                <small>{{ optional($task->scheduled_at)?->format('d M, h:i A') ?? 'Schedule pending' }} â€¢ {{ $task->assignedUser?->name ?: $task->assignedStaff?->name ?: 'Unassigned' }}</small>
                                <div class="dashboard-feed-links">
                                    <a href="{{ route('deliveries.show', $task) }}">Open</a>
                                    @if($task->linkedCustomerPhone())
                                        <a href="tel:{{ preg_replace('/\s+/', '', (string) $task->linkedCustomerPhone()) }}">Call</a>
                                    @endif
                                    @if($task->linkedCustomerMapUrl())
                                        <a href="{{ $task->linkedCustomerMapUrl() }}" target="_blank" rel="noopener">Open Map</a>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                        @if($recentDeliveriesSummaryHidden->isNotEmpty())
                            <details class="dashboard-expandable">
                                <summary class="dashboard-expandable-summary">Show {{ $recentDeliveriesSummaryHidden->count() }} more</summary>
                                <div class="dashboard-expandable-content">
                                    @foreach($recentDeliveriesSummaryHidden as $task)
                                        <div class="dashboard-feed-item">
                                            <div class="dashboard-feed-title">
                                                <strong>{{ ucfirst((string) $task->type) }} #{{ $task->id }}</strong>
                                                <span class="rx-badge {{ $statusBadgeClass($task->pickupOperationalStatus()) }}">{{ $task->type === 'pickup' ? $task->pickupOperationalLabel() : \Illuminate\Support\Str::headline((string) $task->status) }}</span>
                                            </div>
                                            <span>{{ $task->linkedCustomerName() }} • {{ $task->linkedCustomerPhone() ?: 'No phone' }}</span>
                                            <small>{{ optional($task->scheduled_at)?->format('d M, h:i A') ?? 'Schedule pending' }} • {{ $task->assignedUser?->name ?: $task->assignedStaff?->name ?: 'Unassigned' }}</small>
                                            <div class="dashboard-feed-links">
                                                <a href="{{ route('deliveries.show', $task) }}">Open</a>
                                                @if($task->linkedCustomerPhone())
                                                    <a href="tel:{{ preg_replace('/\s+/', '', (string) $task->linkedCustomerPhone()) }}">Call</a>
                                                @endif
                                                @if($task->linkedCustomerMapUrl())
                                                    <a href="{{ $task->linkedCustomerMapUrl() }}" target="_blank" rel="noopener">Open Map</a>
                                                @endif
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </details>
                        @endif
                    </div>
                @else
                    <div class="rx-empty dashboard-empty">
                        <div class="rx-empty-icon">{!! $dashboardIcon('delivery') !!}</div>
                        <strong>No recent delivery activity</strong>
                        <span>Delivery and pickup updates will appear here once tasks move.</span>
                    </div>
                @endif
            </div>
        </div>
        @endif

        @if($dashboardWidgetEnabled('section_high_priority_followups'))
        <div class="rx-card dashboard-feed-card">
            <div class="rx-card-header">
                <div>
                    <h2 class="rx-card-title">High Priority Follow-ups</h2>
                    <p class="rx-card-copy">Escalations, payment pressure, and renewals that need the fastest callback.</p>
                </div>
                @if($communicationCenterUrl)
                    <a href="{{ route('communication-center.index', ['priority' => 'high']) }}" class="rx-btn-secondary">Open Communication Center</a>
                @endif
            </div>
            <div class="rx-card-body">
                @if($highPriorityFollowUpSummary->isNotEmpty())
                    <div class="dashboard-feed-list">
                        @foreach($highPriorityFollowUpSummaryVisible as $followUp)
                            <div class="dashboard-feed-item">
                                <div class="dashboard-feed-title">
                                    <strong>{{ $followUp->title }}</strong>
                                    <span class="rx-badge {{ $statusBadgeClass($followUp->effectiveStatus()) }}">{{ $followUp->priorityLabel() }}</span>
                                </div>
                                <span>{{ $followUp->callTargetName() ?: 'Contact pending' }} â€¢ {{ $followUp->callTargetPhone() ?: 'No phone' }}</span>
                                <small>{{ $followUp->typeLabel() }} â€¢ {{ optional($followUp->due_at)?->format('d M, h:i A') ?? 'Due now' }}</small>
                                <div class="dashboard-feed-links">
                                    <a href="{{ route('communication-center.index', ['priority' => 'high']) }}">Open</a>
                                    @if($followUp->callTargetPhone())
                                        <a href="tel:{{ preg_replace('/\s+/', '', (string) $followUp->callTargetPhone()) }}">Call</a>
                                    @endif
                                    @if($followUp->whatsappUrl())
                                        <a href="{{ $followUp->whatsappUrl() }}" target="_blank" rel="noopener">WhatsApp</a>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                        @if($highPriorityFollowUpSummaryHidden->isNotEmpty())
                            <details class="dashboard-expandable">
                                <summary class="dashboard-expandable-summary">Show {{ $highPriorityFollowUpSummaryHidden->count() }} more</summary>
                                <div class="dashboard-expandable-content">
                                    @foreach($highPriorityFollowUpSummaryHidden as $followUp)
                                        <div class="dashboard-feed-item">
                                            <div class="dashboard-feed-title">
                                                <strong>{{ $followUp->title }}</strong>
                                                <span class="rx-badge {{ $statusBadgeClass($followUp->effectiveStatus()) }}">{{ $followUp->priorityLabel() }}</span>
                                            </div>
                                            <span>{{ $followUp->callTargetName() ?: 'Contact pending' }} • {{ $followUp->callTargetPhone() ?: 'No phone' }}</span>
                                            <small>{{ $followUp->typeLabel() }} • {{ optional($followUp->due_at)?->format('d M, h:i A') ?? 'Due now' }}</small>
                                            <div class="dashboard-feed-links">
                                                <a href="{{ route('communication-center.index', ['priority' => 'high']) }}">Open</a>
                                                @if($followUp->callTargetPhone())
                                                    <a href="tel:{{ preg_replace('/\s+/', '', (string) $followUp->callTargetPhone()) }}">Call</a>
                                                @endif
                                                @if($followUp->whatsappUrl())
                                                    <a href="{{ $followUp->whatsappUrl() }}" target="_blank" rel="noopener">WhatsApp</a>
                                                @endif
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </details>
                        @endif
                    </div>
                @else
                    <div class="rx-empty dashboard-empty">
                        <div class="rx-empty-icon">{!! $dashboardIcon('payment') !!}</div>
                        <strong>No high priority follow-ups</strong>
                        <span>Urgent callbacks and escalations are under control right now.</span>
                    </div>
                @endif
            </div>
        </div>
        @endif
    </section>
    @endif

    @if(false && $showFinanceSection)
        <section class="rx-card" id="finance-summary">
            <div class="rx-card-header">
                <div>
                    <h2 class="rx-card-title">Finance Summary</h2>
                    <p class="rx-card-copy">Rental value, collections, and invoice exposure in one glance.</p>
                </div>
            </div>
            <div class="rx-card-body">
                <div class="control-room-finance-visual">
                    <div class="control-room-finance-hero">
                        <div class="control-room-finance-chip">
                            <span>Collections</span>
                            <strong>{{ $compactCurrency($paymentsReceivedThisMonthAmount) }}</strong>
                        </div>
                        <div class="control-room-finance-chip">
                            <span>Dues</span>
                            <strong>{{ $compactCurrency($outstandingDueAmountValue) }}</strong>
                        </div>
                        <div class="control-room-finance-chip">
                            <span>Paid / Unpaid</span>
                            <strong>{{ $totalBilledAmountValue > 0 ? round((($totalBilledAmountValue - $outstandingDueAmountValue) / max($totalBilledAmountValue, 1)) * 100) : 0 }}%</strong>
                        </div>
                    </div>
                    <div class="control-room-finance-bars">
                        <div class="control-room-finance-bar-row">
                            <div class="control-room-finance-bar-head">
                                <span>Collections vs Dues</span>
                                <span>{{ $currency($paymentsReceivedThisMonthAmount) }} vs {{ $currency($outstandingDueAmountValue) }}</span>
                            </div>
                            <div class="control-room-finance-bar-track">
                                <span style="width: {{ max(min((int) round(($paymentsReceivedThisMonthAmount / max($paymentsReceivedThisMonthAmount + $outstandingDueAmountValue, 1)) * 100), 100), 6) }}%;"></span>
                            </div>
                        </div>
                        <div class="control-room-finance-bar-row">
                            <div class="control-room-finance-bar-head">
                                <span>Paid Invoice Mix</span>
                                <span>{{ $currency(max($totalBilledAmountValue - $outstandingDueAmountValue, 0)) }} collected</span>
                            </div>
                            <div class="control-room-finance-bar-track is-success">
                                <span style="width: {{ $totalBilledAmountValue > 0 ? max(min((int) round(((max($totalBilledAmountValue - $outstandingDueAmountValue, 0)) / max($totalBilledAmountValue, 1)) * 100), 100), 6) : 6 }}%;"></span>
                            </div>
                        </div>
                        <div class="control-room-finance-bar-row">
                            <div class="control-room-finance-bar-head">
                                <span>Overdue Exposure</span>
                                <span>{{ number_format($overdueInvoiceCountValue) }} overdue invoices</span>
                            </div>
                            <div class="control-room-finance-bar-track is-danger">
                                <span style="width: {{ $openInvoiceCountValue > 0 ? max(min((int) round(($overdueInvoiceCountValue / max($openInvoiceCountValue, 1)) * 100), 100), 6) : 6 }}%;"></span>
                            </div>
                        </div>
                    </div>
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
            </div>
        </section>
    @endif

    @unless($isDeliveryFacingMenuRole)
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
                        @foreach($recentRentalsSummaryVisible as $rental)
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
                        @if($recentRentalsSummaryHidden->isNotEmpty())
                            <details class="dashboard-expandable">
                                <summary class="dashboard-expandable-summary">Show {{ $recentRentalsSummaryHidden->count() }} more</summary>
                                <div class="dashboard-expandable-content">
                                    @foreach($recentRentalsSummaryHidden as $rental)
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
                            </details>
                        @endif
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
                        @foreach($recentCustomersSummaryVisible as $customer)
                            <div class="dashboard-overview-item">
                                <div>
                                    <strong>{{ $customer->name }}</strong>
                                    <span>{{ $customer->phone ?? 'No phone' }}</span>
                                    <small>{{ $customer->city ?? 'No city' }}</small>
                                </div>
                                <span class="rx-badge">Customer</span>
                            </div>
                        @endforeach
                        @if($recentCustomersSummaryHidden->isNotEmpty())
                            <details class="dashboard-expandable">
                                <summary class="dashboard-expandable-summary">Show {{ $recentCustomersSummaryHidden->count() }} more</summary>
                                <div class="dashboard-expandable-content">
                                    @foreach($recentCustomersSummaryHidden as $customer)
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
                            </details>
                        @endif
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

        @if(false && $showFinanceSection && $dashboardWidgetEnabled('section_recent_payments'))
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
                        @foreach($recentPaymentsSummaryVisible as $payment)
                            <div class="dashboard-overview-item">
                                <div>
                                    <strong>{{ optional($payment->customer)->name ?? 'Customer' }}</strong>
                                    <span>{{ optional($payment->invoice)->invoice_number ?? 'Payment entry' }}</span>
                                    <small>{{ optional($payment->payment_date)->format('d M Y') ?? 'Date N/A' }}</small>
                                </div>
                                <small>{{ $currency($payment->amount ?? 0) }}</small>
                            </div>
                        @endforeach
                        @if($recentPaymentsSummaryHidden->isNotEmpty())
                            <details class="dashboard-expandable">
                                <summary class="dashboard-expandable-summary">Show {{ $recentPaymentsSummaryHidden->count() }} more</summary>
                                <div class="dashboard-expandable-content">
                                    @foreach($recentPaymentsSummaryHidden as $payment)
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
                            </details>
                        @endif
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
        @endif
    </section>
    @endunless

    @if($showOrganizationAnalyticsSection && !$showInventorySection)
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
    @endif

    @unless($showExpandedStaffOpsSection)
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
    @endunless

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
                    @php
                        $ordersTrendMax = max(array_merge([1], $monthlyTrendRows->pluck('total_orders')->map(fn ($value) => (float) $value)->all()));
                    @endphp
                    <div class="dashboard-trend-legend" aria-label="Sales vs rentals legend">
                        <span class="dashboard-trend-legend-item"><span class="dashboard-trend-legend-swatch is-rental"></span>Rental Revenue</span>
                        <span class="dashboard-trend-legend-item"><span class="dashboard-trend-legend-swatch is-sales"></span>Sales Revenue</span>
                        <span class="dashboard-trend-legend-item"><span class="dashboard-trend-legend-swatch is-orders"></span>Total Orders</span>
                    </div>
                    <div class="dashboard-trend-vertical">
                        @foreach($monthlyTrendRows as $row)
                            <div class="dashboard-trend-column">
                                <div class="dashboard-trend-bars">
                                    <div class="dashboard-trend-bar-wrap">
                                        <div class="dashboard-trend-bar-head">
                                            <span class="dashboard-trend-bar-value">{{ $compactCurrency($row['rental_total'] ?? 0) }}</span>
                                            <span class="dashboard-trend-bar-topline" aria-hidden="true"></span>
                                        </div>
                                        <div class="dashboard-trend-bar is-rental" style="height:{{ max(8, round((((float) ($row['rental_total'] ?? 0)) / max($trendMax, 1)) * 104, 1)) }}px;"></div>
                                        <span class="dashboard-trend-bar-label">R</span>
                                    </div>
                                    <div class="dashboard-trend-bar-wrap">
                                        <div class="dashboard-trend-bar-head">
                                            <span class="dashboard-trend-bar-value">{{ $compactCurrency($row['sales_total'] ?? 0) }}</span>
                                            <span class="dashboard-trend-bar-topline" aria-hidden="true"></span>
                                        </div>
                                        <div class="dashboard-trend-bar is-sales" style="height:{{ max(8, round((((float) ($row['sales_total'] ?? 0)) / max($trendMax, 1)) * 104, 1)) }}px;"></div>
                                        <span class="dashboard-trend-bar-label">S</span>
                                    </div>
                                    <div class="dashboard-trend-bar-wrap">
                                        <div class="dashboard-trend-bar-head">
                                            <span class="dashboard-trend-bar-value">{{ number_format((int) ($row['total_orders'] ?? 0)) }}</span>
                                            <span class="dashboard-trend-bar-meta">orders</span>
                                            <span class="dashboard-trend-bar-topline" aria-hidden="true"></span>
                                        </div>
                                        <div class="dashboard-trend-bar is-orders" style="height:{{ max(8, round((((float) ($row['total_orders'] ?? 0)) / max($ordersTrendMax, 1)) * 104, 1)) }}px;"></div>
                                        <span class="dashboard-trend-bar-label">O</span>
                                    </div>
                                </div>
                                <div class="dashboard-trend-month">{{ \Illuminate\Support\Str::replace(' 2026', '', $row['label'] ?? '-') }}</div>
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
    @endif
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

