@extends('layouts.app')

@section('content')
@php
    use App\Support\WhatsAppHelper;

    $currentUser = auth()->user();
    $canCreateDeliveries = $currentUser?->canAccessModule('deliveries', 'create') ?? false;
    $canUpdateDeliveries = $currentUser?->canAccessModule('deliveries', 'update') ?? false;
    $canDeleteDeliveries = $currentUser?->canAccessModule('deliveries', 'delete') ?? false;
    $assignedScopedDeliveryUser = $currentUser?->hasScope('assigned', 'deliveries') ?? false;
    $deliveryFocusedBoard = in_array($currentUser?->effective_role, [
        \App\Models\User::ROLE_DELIVERY,
        \App\Models\User::ROLE_DELIVERY_EXECUTIVE,
        \App\Models\User::ROLE_VENDOR,
        \App\Models\User::ROLE_THIRD_PARTY,
    ], true);

    $tab = $tab ?? 'all';
    $search = $search ?? '';
    $selectedDate = $selectedDate ?? '';
    $taskType = $taskType ?? '';
    $staffFilter = $staffFilter ?? '';
    $areaFilter = $areaFilter ?? '';
    $statusFilter = $statusFilter ?? '';
    $workflowFilter = $workflowFilter ?? '';
    $ownershipFilter = $ownershipFilter ?? 'all';
    $sortBy = $sortBy ?? 'action_priority';
    $sortDirection = $sortDirection ?? 'asc';

    $sortOptions = [
        'action_priority' => 'Action Priority',
        'schedule_date' => 'Schedule Date',
        'status' => 'Status',
        'type' => 'Type',
        'staff' => 'Staff',
        'customer' => 'Customer / Rental',
        'recently_updated' => 'Recently Updated',
    ];

    $sortDirectionOptions = [
        'asc' => 'Ascending',
        'desc' => 'Descending',
    ];
    $hasActiveFilters = filled($search) || filled($selectedDate) || filled($taskType) || filled($staffFilter) || filled($areaFilter) || filled($statusFilter) || filled($workflowFilter) || $ownershipFilter !== 'all' || $sortBy !== 'action_priority' || $sortDirection !== 'asc';
    $activeFilterChips = collect([
        filled($search) ? 'Search: ' . $search : null,
        filled($selectedDate) ? 'Date: ' . $selectedDate : null,
        filled($taskType) ? 'Type: ' . ucfirst($taskType) : null,
        filled($staffFilter) ? 'Staff filter active' : null,
        filled($areaFilter) ? 'Area filter active' : null,
        filled($statusFilter) ? 'Status: ' . ucfirst(str_replace('_', ' ', $statusFilter)) : null,
        filled($workflowFilter) ? 'Workflow: ' . ucfirst(str_replace('_', ' ', $workflowFilter)) : null,
        $ownershipFilter !== 'all' ? 'View: My Assigned Tasks' : null,
        $sortBy !== 'action_priority' ? 'Sort: ' . ($sortOptions[$sortBy] ?? 'Custom') : null,
        $sortDirection !== 'asc' ? 'Direction: Descending' : null,
    ])->filter()->values();

    $boardHref = function (array $overrides = [], array $forget = []) {
        $query = request()->query();

        foreach ($forget as $key) {
            unset($query[$key]);
        }

        foreach ($overrides as $key => $value) {
            if ($value === null || $value === '') {
                unset($query[$key]);
            } else {
                $query[$key] = $value;
            }
        }

        return route('deliveries.index', $query);
    };

    $sortFormQuery = collect(request()->query())
        ->except(['sort_by', 'sort_dir', 'page'])
        ->all();

    $tabs = [
        ['key' => 'all', 'label' => 'All Tasks'],
        ['key' => 'deliveries', 'label' => 'Deliveries'],
        ['key' => 'pickups', 'label' => 'Pickups'],
        ['key' => 'completed', 'label' => 'Completed'],
        ['key' => 'today', 'label' => 'Today'],
        ['key' => 'overdue', 'label' => 'Overdue'],
    ];

    $ownershipTabs = [
        ['key' => 'all', 'label' => 'All Tasks'],
        ['key' => 'my', 'label' => 'My Assigned Tasks'],
    ];

    $statCards = $deliveryFocusedBoard
        ? [
            [
                'label' => 'Assigned Tasks',
                'value' => $totalTasksCount ?? 0,
                'copy' => 'Open field work',
                'href' => $boardHref(['tab' => 'all', 'task_type' => null, 'status' => null, 'workflow' => null], ['board']),
                'tone' => 'info',
                'icon' => 'tasks',
            ],
            [
                'label' => 'My Deliveries Today',
                'value' => $deliveryTasksCount ?? 0,
                'copy' => 'Delivery tasks to run',
                'href' => $boardHref(['tab' => 'deliveries', 'task_type' => 'delivery', 'workflow' => 'delivery_workload'], ['board', 'status']),
                'tone' => 'delivery',
                'icon' => 'delivery',
            ],
            [
                'label' => 'My Pickups Today',
                'value' => $pickupTasksCount ?? 0,
                'copy' => 'Pickup tasks to run',
                'href' => $boardHref(['tab' => 'pickups', 'task_type' => 'pickup', 'workflow' => 'pickup_workload'], ['board', 'status']),
                'tone' => 'pickup',
                'icon' => 'pickup',
            ],
            [
                'label' => 'Overdue',
                'value' => $overdueTasksCount ?? 0,
                'copy' => 'Needs action now',
                'href' => $boardHref(['tab' => 'overdue', 'status' => null], ['board', 'workflow']),
                'tone' => 'danger',
                'icon' => 'overdue',
            ],
            [
                'label' => 'Failed',
                'value' => $failedTasksCount ?? 0,
                'copy' => 'Failed or cancelled',
                'href' => $boardHref(['status' => 'cancelled', 'tab' => 'all'], ['board', 'workflow']),
                'tone' => 'danger',
                'icon' => 'overdue',
            ],
        ]
        : [
            [
                'label' => 'Total Tasks',
                'value' => $totalTasksCount ?? 0,
                'copy' => 'All tasks visible in this board',
                'href' => $boardHref(['tab' => 'all', 'task_type' => null, 'status' => null, 'workflow' => null], ['board']),
                'tone' => 'info',
                'icon' => 'tasks',
            ],
            [
                'label' => 'Deliveries Pending',
                'value' => $deliveryTasksCount ?? 0,
                'copy' => 'Open delivery tasks in this board',
                'href' => $boardHref(['tab' => 'deliveries', 'task_type' => 'delivery', 'workflow' => 'delivery_workload'], ['board', 'status']),
                'tone' => 'delivery',
                'icon' => 'delivery',
            ],
            [
                'label' => 'Pickups Pending',
                'value' => $pickupTasksCount ?? 0,
                'copy' => 'Open pickup tasks in this board',
                'href' => $boardHref(['tab' => 'pickups', 'task_type' => 'pickup', 'workflow' => 'pickup_workload'], ['board', 'status']),
                'tone' => 'pickup',
                'icon' => 'pickup',
            ],
            [
                'label' => 'Deliveries Completed',
                'value' => $completedDeliveryCount ?? 0,
                'copy' => 'Completed delivery tasks in this board',
                'href' => $boardHref(['tab' => 'completed', 'task_type' => 'delivery', 'status' => 'completed', 'workflow' => 'completed_delivery'], ['board']),
                'tone' => 'success',
                'icon' => 'delivery',
            ],
            [
                'label' => 'Pickups Completed',
                'value' => $completedPickupCount ?? 0,
                'copy' => 'Completed pickup tasks in this board',
                'href' => $boardHref(['tab' => 'completed', 'task_type' => 'pickup', 'status' => 'completed', 'workflow' => 'completed_pickup'], ['board']),
                'tone' => 'success',
                'icon' => 'pickup',
            ],
            [
                'label' => 'Completed Today',
                'value' => $completedTodayCount ?? 0,
                'copy' => 'Tasks closed today',
                'href' => $boardHref(['tab' => 'completed', 'task_type' => null, 'status' => 'completed', 'workflow' => 'completed_today'], ['board']),
                'tone' => 'success',
                'icon' => 'completed',
            ],
        ];

    $taskTypeTabs = [
        ['label' => 'All', 'href' => $boardHref(['tab' => 'all', 'task_type' => null], ['board', 'workflow', 'status']) , 'active' => blank($taskType)],
        ['label' => 'Deliveries', 'href' => $boardHref(['tab' => 'deliveries', 'task_type' => 'delivery', 'workflow' => 'delivery_workload'], ['board', 'status']), 'active' => $taskType === 'delivery'],
        ['label' => 'Pickups', 'href' => $boardHref(['tab' => 'pickups', 'task_type' => 'pickup', 'workflow' => 'pickup_workload'], ['board', 'status']), 'active' => $taskType === 'pickup'],
    ];

    $statusTabs = [
        ['label' => 'Today', 'href' => $boardHref(['tab' => 'today', 'status' => null, 'workflow' => null], ['board']), 'active' => $tab === 'today'],
        ['label' => 'Overdue', 'href' => $boardHref(['tab' => 'overdue', 'status' => null, 'workflow' => null], ['board']), 'active' => $tab === 'overdue'],
        ['label' => 'Completed', 'href' => $boardHref(['tab' => 'completed', 'status' => 'completed', 'workflow' => null], ['board']), 'active' => $tab === 'completed' || $statusFilter === 'completed'],
        ['label' => 'Failed', 'href' => $boardHref(['tab' => 'all', 'status' => 'cancelled', 'workflow' => null], ['board']), 'active' => $statusFilter === 'cancelled'],
    ];

    $scopeTabs = $currentUser?->hasScope('assigned', 'deliveries')
        ? [
            ['label' => 'My Tasks', 'href' => $boardHref(['ownership' => 'my'], ['board']), 'active' => $ownershipFilter === 'my'],
            ['label' => 'All Tasks', 'href' => $boardHref(['ownership' => 'all'], ['board']), 'active' => $ownershipFilter === 'all'],
        ]
        : [];

    $taskTypeBadge = fn (string $type) => $type === 'pickup' ? 'rn-badge-maintenance' : 'rn-badge-active';

    $progressBadgeClass = function (?string $status) {
        return match ($status) {
            'partially_delivered', 'partial_return' => 'rn-badge-active',
            'completed' => 'rn-badge-success',
            'cancelled' => 'rn-badge-muted',
            default => 'rn-badge-warning',
        };
    };

    $progressLabel = fn (?string $status) => match ($status) {
        'partially_delivered' => 'Partial Delivery',
        'partial_return' => 'Partial Pickup',
        default => ucfirst(str_replace('_', ' ', $status ?: 'pending')),
    };

    $taskStatusBadgeClass = function (?string $status, bool $isOverdue = false) {
        if ($isOverdue) {
            return 'rn-badge-danger';
        }

        return match ($status) {
            'completed', 'delivered', 'picked_up', 'returned' => 'rn-badge-success',
            'in_progress', 'partially_delivered', 'partial_return' => 'rn-badge-active',
            'cancelled' => 'rn-badge-muted',
            default => 'rn-badge-warning',
        };
    };

    $taskStatusLabel = fn (?string $status) => match ($status) {
        'partially_delivered' => 'Partial Delivery',
        'partial_return' => 'Partial Pickup',
        'picked_up' => 'Picked Up',
        default => ucfirst(str_replace('_', ' ', $status ?: 'pending')),
    };

    $navIcon = function (string $icon): string {
        $attrs = 'width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"';

        return match ($icon) {
            'tasks' => '<svg '.$attrs.'><rect x="3" y="4" width="18" height="16" rx="3"/><path d="M7 8h10"/><path d="M7 12h10"/><path d="M7 16h6"/></svg>',
            'delivery' => '<svg '.$attrs.'><path d="M3 7h11v9H3z"/><path d="M14 10h4l3 3v3h-7z"/><circle cx="7" cy="18" r="2"/><circle cx="18" cy="18" r="2"/></svg>',
            'pickup' => '<svg '.$attrs.'><path d="M12 3v11"/><path d="m8 10 4 4 4-4"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/></svg>',
            'overdue' => '<svg '.$attrs.'><circle cx="12" cy="12" r="9"/><path d="M12 7v6"/><path d="M12 16h.01"/></svg>',
            'completed' => '<svg '.$attrs.'><circle cx="12" cy="12" r="9"/><path d="m8.5 12 2.2 2.2 4.8-4.8"/></svg>',
            'call' => '<svg '.$attrs.'><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.2 19.2 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7l.5 3a2 2 0 0 1-.6 1.8l-1.3 1.3a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 1.8-.6l3 .5A2 2 0 0 1 22 16.9Z"/></svg>',
            'whatsapp' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M20 11.4c0 4.7-3.9 8.6-8.8 8.6-1.5 0-3-.4-4.2-1.1L3 20l1.2-3.7A8.4 8.4 0 0 1 2.4 11.4C2.4 6.7 6.3 3 11.2 3 16.1 3 20 6.7 20 11.4Zm-4.8 2.2c-.2-.1-1.2-.6-1.4-.7s-.3-.1-.4.1-.5.7-.7.9-.3.2-.5.1a5.9 5.9 0 0 1-1.7-1c-.6-.5-1-1.2-1.1-1.4-.1-.2 0-.3.1-.4l.3-.4.2-.3v-.4c0-.1-.4-1.1-.6-1.6-.2-.4-.3-.4-.4-.4h-.4c-.1 0-.4 0-.6.3-.2.2-.8.8-.8 1.9s.8 2.1 1 2.3c.1.1 1.5 2.3 3.8 3.2.5.2 1 .4 1.3.5.6.2 1.2.2 1.7.1.5-.1 1.2-.5 1.4-1 .2-.5.2-1 .1-1Z"/></svg>',
            'map' => '<svg '.$attrs.'><path d="M12 21s7-5.1 7-11a7 7 0 1 0-14 0c0 5.9 7 11 7 11Z"/><circle cx="12" cy="10" r="2.5"/></svg>',
            'start' => '<svg '.$attrs.'><path d="m8 5 11 7-11 7V5Z"/></svg>',
            'view' => '<svg '.$attrs.'><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6-10-6-10-6Z"/><circle cx="12" cy="12" r="2.5"/></svg>',
            'menu' => '<svg '.$attrs.'><circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/></svg>',
            default => '<svg '.$attrs.'><path d="M12 5v14"/><path d="M5 12h14"/></svg>',
        };
    };

    $formatTaskProgress = function ($delivery) {
        if ($delivery->sale_id || !$delivery->rental) {
            return null;
        }

        if ($delivery->type === 'delivery') {
            $ordered = max($delivery->rental->displayRentalItems()->sum(fn ($item) => (int) ($item->ordered_quantity ?? $item->quantity ?? 0)), 0);
            return $ordered > 0 ? $delivery->rental->deliveredQuantityTotal() . '/' . $ordered . ' delivered' : null;
        }

        $delivered = max($delivery->rental->deliveredQuantityTotal(), 0);

        return $delivered > 0 ? $delivery->rental->returnedQuantityTotal() . '/' . $delivered . ' picked up' : null;
    };

    $formatTaskItems = function ($delivery) {
        if ($delivery->sale_id && $delivery->sale?->product) {
            return collect([$delivery->sale->product->name]);
        }

        if (!$delivery->rental) {
            return collect();
        }

        $items = $delivery->rental->displayRentalItems()
            ->map(function ($item) {
                $name = $item->product?->name ?? 'Rental item';
                $quantity = (int) ($item->ordered_quantity ?? $item->quantity ?? 0);

                return $quantity > 1 ? $name . ' x ' . $quantity : $name;
            })
            ->filter()
            ->values();

        return $items->isNotEmpty() ? $items : collect([$delivery->rental->product?->name ?? 'Rental item']);
    };

    $todayLabel = now()->format('d M Y');
@endphp

<style>
    .ops-board { display:grid; gap:16px; max-width:100%; min-width:0; overflow-x:hidden; }
    .ops-board-header { display:flex; align-items:flex-start; justify-content:space-between; gap:14px; flex-wrap:wrap; }
    .ops-board-title { display:grid; gap:8px; max-width:760px; }
    .ops-board-title h1 { margin:0; font-size:28px; line-height:1.08; letter-spacing:-0.03em; color:var(--ph-color-text); font-family: var(--ph-font-heading); }
    .ops-board-title p { margin:0; color:var(--ph-color-text-soft); font-size:13px; line-height:1.6; }
    .ops-board-actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
    .ops-board-actions .rn-btn,
    .ops-board-actions .rn-btn-primary { min-height:40px; padding:0 14px; border-radius:12px; }
    .ops-stats { display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px; }
    .ops-stat-card {
        display:grid; gap:10px; padding:14px 15px; text-decoration:none; color:inherit;
        border:1px solid var(--ph-color-border); border-radius:18px; background:#fff;
        box-shadow:var(--ph-shadow-soft);
        transition:transform .18s ease, box-shadow .18s ease, border-color .18s ease;
        min-height:132px;
    }
    .ops-stat-card:hover { transform:translateY(-2px); box-shadow:0 16px 34px rgba(15,23,42,.08); }
    .ops-stat-top { display:flex; align-items:flex-start; justify-content:space-between; gap:10px; }
    .ops-stat-top span { display:block; font-size:12px; font-weight:800; letter-spacing:.06em; text-transform:uppercase; color:var(--ph-color-text-soft); line-height:1.3; }
    .ops-stat-value { font-size:29px; font-weight:800; line-height:1.05; color:var(--ph-color-text); }
    .ops-stat-copy { font-size:12px; line-height:1.45; color:var(--ph-color-text-soft); }
    .ops-stat-icon {
        width:40px; height:40px; border-radius:14px; display:grid; place-items:center; flex:0 0 40px;
        border:1px solid rgba(23,119,189,.18); background:var(--ph-color-info-soft); color:var(--ph-color-primary);
    }
    .ops-stat-card.is-pickup .ops-stat-icon { background:var(--ph-color-warning-soft); border-color:rgba(183,121,31,.18); color:var(--ph-color-warning); }
    .ops-stat-card.is-danger .ops-stat-icon { background:var(--ph-color-danger-soft); border-color:rgba(179,13,35,.18); color:var(--ph-color-danger); }
    .ops-stat-card.is-success .ops-stat-icon { background:var(--ph-color-success-soft); border-color:rgba(14,159,75,.18); color:var(--ph-color-success); }
    .ops-filters-shell,
    .ops-filters-card,
    .ops-task-shell,
    .ops-widget-card {
        background:#fff; border:1px solid var(--ph-color-border); border-radius:18px; box-shadow:var(--ph-shadow-soft);
    }
    .ops-filters-shell { padding:12px 14px; display:grid; gap:12px; }
    .ops-filters-card { display:grid; gap:12px; }
    .ops-search-shell {
        display:grid; gap:10px; padding:12px 14px; border:1px solid var(--ph-color-border); border-radius:16px;
        background:linear-gradient(180deg, #f8fbff 0%, #ffffff 100%); box-shadow:var(--ph-shadow-soft);
    }
    .ops-search-form {
        display:grid; grid-template-columns:minmax(0, 1fr) auto auto; gap:8px; align-items:end;
    }
    .ops-search-field { display:grid; gap:6px; min-width:0; }
    .ops-filter-chip-row { display:flex; gap:8px; flex-wrap:wrap; }
    .ops-filter-chip {
        display:inline-flex; align-items:center; min-height:30px; padding:6px 10px;
        border-radius:999px; border:1px solid var(--ph-color-border); background:#fff; color:var(--ph-color-text); font-size:12px; font-weight:700;
    }
    .ops-mobile-command,
    .ops-mobile-chip-groups { display:none; }
    .ops-filter-toggle summary {
        list-style:none; cursor:pointer; display:flex; justify-content:space-between; align-items:center; gap:10px;
        padding:12px 14px; border-radius:14px; border:1px solid var(--ph-color-border); background:var(--ph-color-surface-soft);
    }
    .ops-filter-toggle summary::-webkit-details-marker { display:none; }
    .ops-filter-toggle summary h2 { margin:0; font-size:14px; line-height:1.3; color:var(--ph-color-text); font-family: var(--ph-font-heading); }
    .ops-filter-toggle summary span { color:var(--ph-color-text-soft); font-size:12px; font-weight:700; }
    .ops-filter-body { padding:12px 2px 0; }
    .ops-tabs { display:flex; gap:8px; flex-wrap:wrap; }
    .ops-tab {
        display:inline-flex; align-items:center; justify-content:center; min-height:36px; padding:0 13px;
        border-radius:999px; border:1px solid var(--ph-color-border); background:#fff; color:var(--ph-color-text-soft);
        text-decoration:none; font-size:12px; font-weight:800; letter-spacing:.02em;
    }
    .ops-tab.is-active { background:var(--ph-color-sidebar); border-color:var(--ph-color-sidebar); color:#fff; box-shadow:0 8px 22px rgba(11,35,66,.18); }
    .ops-filter-grid {
        display:grid;
        grid-template-columns:minmax(0, 1.2fr) repeat(5, minmax(150px, 1fr)) auto;
        gap:10px;
        align-items:end;
    }
    .ops-filter-field { display:grid; gap:6px; min-width:0; }
    .ops-filter-field label { color:var(--ph-color-text-soft); font-size:11px; font-weight:800; letter-spacing:.05em; text-transform:uppercase; }
    .ops-filter-field input,
    .ops-filter-field select {
        width:100%; min-width:0; min-height:42px; padding:0 12px; border-radius:12px;
        border:1px solid var(--ph-color-border-strong); background:#fff; color:var(--ph-color-text); font-size:14px; box-sizing:border-box;
    }
    .ops-filter-actions { display:flex; gap:8px; flex-wrap:wrap; justify-content:flex-end; }
    .ops-board-grid { display:grid; grid-template-columns:minmax(0, 1.9fr) minmax(280px, 0.9fr); gap:16px; align-items:start; }
    .ops-task-shell { overflow:hidden; }
    .ops-task-head {
        display:flex; align-items:flex-start; justify-content:space-between; gap:10px;
        padding:14px 16px; border-bottom:1px solid var(--ph-color-border); flex-wrap:wrap;
    }
    .ops-task-head h2 { margin:0; font-size:16px; line-height:1.3; color:var(--ph-color-text); font-family: var(--ph-font-heading); }
    .ops-task-head p { margin:4px 0 0; font-size:12px; line-height:1.5; color:var(--ph-color-text-soft); }
    .ops-task-count { font-size:12px; font-weight:800; color:var(--ph-color-text-soft); padding:8px 10px; border-radius:999px; background:var(--ph-color-surface-soft); border:1px solid var(--ph-color-border); }
    .ops-task-meta { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
    .ops-sort-form { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
    .ops-sort-label { font-size:12px; font-weight:800; color:var(--ph-color-text-soft); }
    .ops-sort-select {
        min-height:38px;
        padding:0 12px;
        border-radius:12px;
        border:1px solid var(--ph-color-border-strong);
        background:#fff;
        color:var(--ph-color-text);
        font-size:13px;
        font-weight:700;
        box-sizing:border-box;
    }
    .ops-selected-count { display:none; font-size:12px; font-weight:800; color:var(--ph-color-primary); padding:8px 10px; border-radius:999px; background:var(--ph-color-info-soft); border:1px solid rgba(23,119,189,.18); }
    .ops-selected-count.is-visible { display:inline-flex; }
    .ops-table-wrap { width:100%; overflow-x:auto; }
    .ops-table { width:100%; min-width:1230px; border-collapse:separate; border-spacing:0; table-layout:auto; }
    .ops-table th,
    .ops-table td { padding:12px 14px; border-bottom:1px solid #eef2f7; text-align:left; vertical-align:top; word-break:normal; overflow-wrap:break-word; }
    .ops-table th {
        position:sticky; top:0; z-index:1; background:var(--ph-color-surface-soft); color:var(--ph-color-text-soft);
        font-size:11px; font-weight:800; letter-spacing:.06em; text-transform:uppercase;
    }
    .ops-table tbody tr { transition:background .16s ease; }
    .ops-table tbody tr:hover { background:#fbfdff; }
    .ops-table tbody tr.is-overdue { background:var(--ph-color-danger-soft); }
    .ops-table td { font-size:13px; color:var(--ph-color-text); line-height:1.5; }
    .ops-col-serial { width:56px; min-width:56px; text-align:center !important; }
    .ops-col-select { width:58px; min-width:58px; text-align:center !important; }
    .ops-col-type { width:96px; }
    .ops-col-order { width:164px; }
    .ops-col-customer { width:26%; min-width:220px; }
    .ops-col-schedule { width:168px; }
    .ops-col-staff { width:156px; }
    .ops-col-status { width:156px; }
    .ops-col-actions { width:252px; }
    .ops-serial-cell { font-weight:800; color:#475569; }
    .ops-checkbox-cell,
    .ops-checkbox-head { text-align:center !important; }
    .ops-task-checkbox,
    .ops-select-all {
        width:18px; height:18px; accent-color:var(--ph-color-primary); cursor:pointer;
    }
    .ops-type-badge {
        display:inline-flex; align-items:center; gap:6px; padding:5px 9px; border-radius:999px;
        font-size:11px; font-weight:800; letter-spacing:.04em; text-transform:uppercase;
    }
    .ops-type-badge svg { width:14px; height:14px; }
    .ops-type-delivery { background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; }
    .ops-type-pickup { background:#fff7ed; color:#c2410c; border:1px solid #fdba74; }
    .ops-stack { display:grid; gap:4px; min-width:0; }
    .ops-inline { display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
    .ops-muted { color:#64748b; font-size:12px; line-height:1.45; }
    .ops-record-link { color:#0f172a; text-decoration:none; font-weight:800; }
    .ops-record-link:hover { color:#2563eb; }
    .ops-record-subtle { color:#475569; text-decoration:none; font-weight:600; }
    .ops-record-subtle:hover { color:#2563eb; }
    .ops-items-list { display:grid; gap:2px; min-width:0; }
    .ops-item-pill { font-size:12px; color:#475569; line-height:1.45; white-space:normal; }
    .ops-status-stack { display:grid; gap:6px; }
    .ops-progress-copy { font-size:11px; color:#64748b; line-height:1.4; }
    .ops-schedule-danger { color:#b91c1c; font-weight:700; }
    .ops-actions {
        display:flex; flex-wrap:wrap; gap:6px; align-items:flex-start; justify-content:flex-end;
    }
    .ops-action-btn,
    .ops-action-btn-primary {
        display:inline-flex; align-items:center; justify-content:center; gap:6px;
        min-height:34px; padding:0 10px; border-radius:10px; font-size:12px; font-weight:800; text-decoration:none;
        border:1px solid #dbe3ef; background:#fff; color:#334155; cursor:pointer;
    }
    .ops-action-btn svg,
    .ops-action-btn-primary svg { width:14px; height:14px; }
    .ops-action-btn-primary { background:#0f172a; border-color:#0f172a; color:#fff; }
    .ops-action-btn-wa { color:#0f766e; border-color:#99f6e4; background:#f0fdfa; }
    .ops-action-btn-wa svg { width:14px; height:14px; flex:0 0 14px; }
    .ops-action-btn-map { color:#1d4ed8; border-color:#bfdbfe; background:#eff6ff; }
    .ops-action-btn-ghost { width:36px; padding:0; }
    .ops-action-menu { position:relative; display:inline-block; }
    .ops-action-menu summary {
        list-style:none; width:36px; height:34px; display:grid; place-items:center;
        border-radius:10px; border:1px solid #dbe3ef; background:#fff; color:#334155; cursor:pointer;
    }
    .ops-action-menu summary::-webkit-details-marker { display:none; }
    .ops-action-menu[open] summary { background:#eff6ff; border-color:#bfdbfe; color:#1d4ed8; }
    .ops-action-panel {
        position:absolute; right:0; top:40px; z-index:30; min-width:170px; padding:8px; display:grid; gap:6px;
        border-radius:14px; border:1px solid #dbe3ef; background:#fff; box-shadow:0 18px 42px rgba(15,23,42,.14);
    }
    .ops-action-panel a,
    .ops-action-panel button {
        display:flex; align-items:center; justify-content:flex-start; min-height:36px; padding:0 10px;
        border-radius:10px; border:1px solid #edf2f7; background:#fff; color:#334155;
        text-decoration:none; font-size:12px; font-weight:700; cursor:pointer;
    }
    .ops-action-panel .danger { color:#be123c; background:#fff1f2; border-color:#fecdd3; }
    .ops-action-panel form { margin:0; }
    .ops-mobile-list { display:none; }
    .ops-mobile-card {
        position:relative;
        display:grid;
        gap:12px;
        padding:14px;
        border-bottom:1px solid #eef2f7;
        cursor:pointer;
    }
    .ops-mobile-card:last-child { border-bottom:none; }
    .ops-mobile-card:focus-visible {
        outline:none;
        box-shadow:0 0 0 3px rgba(37,99,235,.12);
    }
    .ops-mobile-card.is-overdue {
        background:linear-gradient(180deg, #fff8f8 0%, #ffffff 100%);
    }
    .ops-mobile-top {
        display:flex;
        align-items:flex-start;
        justify-content:space-between;
        gap:10px;
    }
    .ops-mobile-card-head {
        display:grid;
        gap:8px;
        min-width:0;
        flex:1 1 auto;
    }
    .ops-mobile-topline {
        display:flex;
        align-items:center;
        gap:6px;
        flex-wrap:wrap;
    }
    .ops-mobile-serial {
        font-size:11px;
        font-weight:800;
        color:#475569;
        letter-spacing:.04em;
        text-transform:uppercase;
    }
    .ops-mobile-order {
        color:#0f172a;
        font-size:14px;
        font-weight:800;
        line-height:1.35;
    }
    .ops-mobile-order a {
        color:inherit;
        text-decoration:none;
    }
    .ops-mobile-contact {
        display:grid;
        gap:4px;
    }
    .ops-mobile-customer {
        color:#0f172a;
        font-size:14px;
        font-weight:700;
        line-height:1.35;
    }
    .ops-mobile-phone,
    .ops-mobile-product {
        color:#475569;
        font-size:12px;
        line-height:1.4;
    }
    .ops-mobile-grid {
        display:grid;
        grid-template-columns:repeat(2, minmax(0, 1fr));
        gap:10px;
    }
    .ops-mobile-meta {
        display:grid;
        gap:3px;
        min-width:0;
    }
    .ops-mobile-meta span:first-child {
        color:#64748b;
        font-size:10px;
        font-weight:800;
        letter-spacing:.05em;
        text-transform:uppercase;
    }
    .ops-mobile-meta span:last-child {
        color:#0f172a;
        font-size:13px;
        line-height:1.4;
        overflow-wrap:anywhere;
    }
    .ops-mobile-actions {
        display:grid;
        grid-template-columns:repeat(4, minmax(0, 1fr));
        gap:8px;
        align-items:stretch;
    }
    .ops-mobile-actions .ops-action-btn,
    .ops-mobile-actions .ops-action-btn-primary { min-height:40px; padding:0 8px; }
    .ops-mobile-actions .ops-action-btn-primary {
        grid-column:span 2;
    }
    .ops-mobile-more summary {
        min-height:42px;
        border-radius:12px;
    }
    .ops-widget-stack { display:grid; gap:14px; }
    .ops-widget-card { padding:14px; display:grid; gap:12px; }
    .ops-widget-head { display:flex; align-items:flex-start; justify-content:space-between; gap:10px; }
    .ops-widget-head h3 { margin:0; font-size:15px; line-height:1.3; color:#0f172a; }
    .ops-widget-head p { margin:4px 0 0; color:#64748b; font-size:12px; line-height:1.5; }
    .ops-widget-list { display:grid; gap:9px; }
    .ops-widget-item {
        display:grid; gap:3px; padding:10px 11px; border-radius:14px; border:1px solid #eef2f7; background:#f8fafc;
    }
    .ops-widget-item strong { color:#0f172a; font-size:13px; line-height:1.4; }
    .ops-widget-item span { color:#64748b; font-size:12px; line-height:1.45; }
    .ops-sop-list { display:grid; gap:8px; }
    .ops-sop-item { display:flex; gap:8px; align-items:flex-start; color:#475569; font-size:12px; line-height:1.5; }
    .ops-sop-dot {
        width:20px; height:20px; flex:0 0 20px; border-radius:999px; display:grid; place-items:center;
        background:#eff6ff; color:#1d4ed8; font-size:11px; font-weight:800;
    }
    .ops-empty {
        padding:24px 16px; text-align:center; color:#64748b; font-size:13px; line-height:1.6;
    }
    .ops-flash-success {
        padding:12px 14px; border-radius:14px; background:#ecfdf5; border:1px solid #bbf7d0; color:#166534; font-size:13px; font-weight:700;
    }

    @media (max-width: 1360px) {
        .ops-filter-grid { grid-template-columns:minmax(0, 1fr) repeat(3, minmax(150px, 1fr)); }
        .ops-filter-grid .ops-filter-field:nth-child(5),
        .ops-filter-grid .ops-filter-field:nth-child(6),
        .ops-filter-actions { grid-column:span 2; }
        .ops-stats { grid-template-columns:repeat(3, minmax(0, 1fr)); }
    }

    @media (max-width: 1080px) {
        .ops-board-grid { grid-template-columns:1fr; }
        .ops-widget-stack { grid-template-columns:repeat(2, minmax(0, 1fr)); }
        .ops-table th,
        .ops-table td { padding:11px 10px; }
        .ops-table { min-width:1080px; }
        .ops-col-order { width:146px; }
        .ops-col-customer { min-width:200px; }
        .ops-col-schedule { width:150px; }
        .ops-col-staff { width:144px; }
        .ops-col-status { width:144px; }
        .ops-col-actions { width:230px; }
    }

    @media (max-width: 900px) {
        .ops-filter-grid { grid-template-columns:repeat(2, minmax(0, 1fr)); }
        .ops-filter-actions { grid-column:auto; justify-content:stretch; }
        .ops-filter-actions .rn-btn,
        .ops-filter-actions .rn-btn-primary { flex:1 1 auto; }
        .ops-table-wrap { display:none; }
        .ops-mobile-list { display:block; }
        .ops-widget-stack { grid-template-columns:1fr; }
    }

    @media (max-width: 767px) {
        .ops-search-shell { display:none; }
        .ops-board { gap:12px; }
        .ops-board-title h1 { font-size:22px; }
        .ops-board-title p { font-size:12px; }
        .ops-board-title p,
        .ops-task-head p { display:none; }
        .ops-stats { grid-template-columns:repeat(2, minmax(0, 1fr)); gap:10px; }
        .ops-stat-card { min-height:92px; padding:10px 11px; gap:8px; border-radius:16px; }
        .ops-stat-top span { font-size:10px; }
        .ops-stat-copy { display:none; }
        .ops-stat-value { font-size:22px; }
        .ops-stat-icon { width:34px; height:34px; flex-basis:34px; border-radius:12px; }
        .ops-tabs,
        .ops-filters-card { display:none; }
        .ops-mobile-command {
            display:grid;
            gap:10px;
            padding:12px 14px;
            border:1px solid var(--ph-color-border);
            border-radius:18px;
            background:#fff;
            box-shadow:var(--ph-shadow-soft);
        }
        .ops-mobile-search-row {
            display:grid;
            grid-template-columns:minmax(0, 1fr) auto;
            gap:8px;
        }
        .ops-mobile-search-row input {
            min-height:40px;
            border-radius:12px;
            border:1px solid var(--ph-color-border-strong);
            padding:0 12px;
            font-size:14px;
            min-width:0;
        }
        .ops-mobile-chip-groups {
            display:grid;
            gap:10px;
        }
        .ops-mobile-chip-group {
            display:grid;
            gap:6px;
        }
        .ops-mobile-chip-group label {
            color:var(--ph-color-text-soft);
            font-size:10px;
            font-weight:800;
            letter-spacing:.05em;
            text-transform:uppercase;
        }
        .ops-mobile-chip-row {
            display:flex;
            gap:8px;
            overflow-x:auto;
            padding-bottom:2px;
            scrollbar-width:none;
        }
        .ops-mobile-chip-row::-webkit-scrollbar { display:none; }
        .ops-mobile-chip {
            display:inline-flex;
            align-items:center;
            justify-content:center;
            min-height:34px;
            padding:0 12px;
            border-radius:999px;
            border:1px solid var(--ph-color-border);
            background:#fff;
            color:var(--ph-color-text-soft);
            text-decoration:none;
            font-size:12px;
            font-weight:800;
            white-space:nowrap;
        }
        .ops-mobile-chip.is-active {
            background:var(--ph-color-sidebar);
            border-color:var(--ph-color-sidebar);
            color:#fff;
        }
        .ops-mobile-toolbar {
            display:grid;
            grid-template-columns:repeat(4, minmax(0, 1fr));
            gap:8px;
        }
        .ops-mobile-toolbar .mobile-toolbar-btn,
        .ops-mobile-toolbar .mobile-sort-trigger,
        .ops-mobile-toolbar .rn-btn {
            min-height:40px;
            width:100%;
            justify-content:center;
        }
        .ops-mobile-sort-anchor { position:relative; }
        .ops-mobile-sort-menu {
            position:absolute;
            right:0;
            top:calc(100% + 8px);
            z-index:40;
            display:grid;
            gap:6px;
            min-width:180px;
            padding:8px;
            border-radius:14px;
            border:1px solid #dbe3ef;
            background:#fff;
            box-shadow:0 18px 42px rgba(15,23,42,.14);
        }
        .ops-mobile-sort-menu a {
            display:flex;
            align-items:center;
            min-height:36px;
            padding:0 10px;
            border-radius:10px;
            color:#334155;
            text-decoration:none;
            font-size:12px;
            font-weight:700;
        }
        .ops-mobile-sort-menu a.is-active {
            background:#eff6ff;
            color:#1d4ed8;
        }
        .ops-filter-grid,
        .ops-mobile-grid { grid-template-columns:1fr; }
        .ops-mobile-actions {
            grid-template-columns:repeat(4, minmax(0, 1fr));
            align-items:stretch;
        }
        .ops-mobile-actions .ops-action-btn,
        .ops-mobile-actions .ops-action-btn-primary {
            width:100%;
            min-height:44px;
            padding:0 8px;
            border-radius:12px;
            justify-content:center;
        }
        .ops-mobile-actions .mobile-utility-btn {
            padding:0;
            aspect-ratio:1 / 1;
        }
        .ops-mobile-actions .mobile-utility-btn span {
            display:none;
        }
        .ops-mobile-actions .mobile-utility-btn svg {
            width:18px;
            height:18px;
        }
        .ops-board-actions { width:100%; }
        .ops-board-actions .rn-btn,
        .ops-board-actions .rn-btn-primary {
            flex:1 1 100%;
            width:100%;
            max-width:100%;
            justify-content:center;
        }
        .ops-task-meta,
        .ops-sort-form {
            width:100%;
        }
        .ops-action-panel { position:static; min-width:0; box-shadow:none; margin-top:8px; }
    }

    @media (max-width: 420px) {
        .ops-stats { grid-template-columns:repeat(2, minmax(0, 1fr)); }
        .ops-stat-card { min-height:108px; padding:10px; }
        .ops-stat-value { font-size:22px; }
    }
</style>

<div class="ops-board rn-list-page">
    <div class="ops-board-header">
        <div class="ops-board-title">
            <div class="rx-eyebrow">Operations</div>
            <h1>Tasks Board</h1>
            <p>Unified delivery and pickup control for the day. Keep field staff moving, collections visible, and partial work easier to follow without bouncing between separate pages.</p>
        </div>
        <div class="ops-board-actions">
            @if($canCreateDeliveries && \Illuminate\Support\Facades\Route::has('deliveries.create'))
                <a href="{{ route('deliveries.create') }}" class="rn-btn-primary">+ Add Assignment</a>
            @endif
        </div>
    </div>

    @if(session('success'))
        <div class="ops-flash-success">{{ session('success') }}</div>
    @endif

    <div class="ops-stats">
        @foreach($statCards as $card)
            <a href="{{ $card['href'] }}" class="ops-stat-card {{ $tab === strtolower(str_replace(' ', '_', $card['label'])) ? 'is-active' : '' }} {{ $card['tone'] === 'pickup' ? 'is-pickup' : ($card['tone'] === 'danger' ? 'is-danger' : ($card['tone'] === 'success' ? 'is-success' : '')) }}">
                <div class="ops-stat-top">
                    <span>{{ $card['label'] }}</span>
                    <div class="ops-stat-icon">{!! $navIcon($card['icon']) !!}</div>
                </div>
                <strong class="ops-stat-value">{{ $card['value'] }}</strong>
                <div class="ops-stat-copy">{{ $card['copy'] }}</div>
            </a>
        @endforeach
    </div>

    <div class="ops-mobile-command" aria-label="Mobile task controls">
        <form method="GET" action="{{ route('deliveries.index') }}" class="ops-mobile-search-row">
            <input type="hidden" name="ownership" value="{{ $ownershipFilter }}">
            <input type="hidden" name="tab" value="{{ $tab }}">
            @foreach($sortFormQuery as $queryKey => $queryValue)
                @if(!in_array($queryKey, ['search', 'ownership', 'tab'], true))
                    <input type="hidden" name="{{ $queryKey }}" value="{{ $queryValue }}">
                @endif
            @endforeach
            <input type="search" name="search" value="{{ $search }}" placeholder="Search customer, phone, product">
            <button type="submit" class="rn-btn-primary">Search</button>
        </form>

        <div class="ops-mobile-chip-groups">
            <div class="ops-mobile-chip-group">
                <label>Task Type</label>
                <div class="ops-mobile-chip-row">
                    @foreach($taskTypeTabs as $typeTab)
                        <a href="{{ $typeTab['href'] }}" class="ops-mobile-chip {{ $typeTab['active'] ? 'is-active' : '' }}">{{ $typeTab['label'] }}</a>
                    @endforeach
                </div>
            </div>
            <div class="ops-mobile-chip-group">
                <label>Status</label>
                <div class="ops-mobile-chip-row">
                    @foreach($statusTabs as $statusTab)
                        <a href="{{ $statusTab['href'] }}" class="ops-mobile-chip {{ $statusTab['active'] ? 'is-active' : '' }}">{{ $statusTab['label'] }}</a>
                    @endforeach
                </div>
            </div>
            @if(!empty($scopeTabs))
                <div class="ops-mobile-chip-group">
                    <label>Scope</label>
                    <div class="ops-mobile-chip-row">
                        @foreach($scopeTabs as $scopeTab)
                            <a href="{{ $scopeTab['href'] }}" class="ops-mobile-chip {{ $scopeTab['active'] ? 'is-active' : '' }}">{{ $scopeTab['label'] }}</a>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        <div class="ops-mobile-toolbar" aria-label="Mobile task tools">
            <button type="button" class="mobile-toolbar-btn" data-mobile-filter-open="deliveries-mobile-filters">Filter</button>
            <div class="ops-mobile-sort-anchor" data-mobile-sort-root>
                <button type="button" class="mobile-toolbar-btn mobile-sort-trigger" data-mobile-sort-trigger>Sort</button>
                <div class="ops-mobile-sort-menu" data-mobile-sort-menu hidden>
                    @foreach($sortOptions as $sortKey => $sortLabel)
                        <a href="{{ $boardHref(['sort_by' => $sortKey, 'sort_dir' => $sortDirection], ['page']) }}" class="{{ $sortBy === $sortKey ? 'is-active' : '' }}">{{ $sortLabel }}</a>
                    @endforeach
                </div>
            </div>
            <a href="{{ route('deliveries.index') }}" class="rn-btn">Refresh</a>
            <a href="{{ route('deliveries.index') }}" class="rn-btn" data-filter-clear="deliveries-index">Clear</a>
        </div>
    </div>

    <div class="ops-filters-shell">
        <div class="ops-tabs" aria-label="Tasks board tabs">
            @foreach($tabs as $boardTab)
                <a href="{{ $boardHref(['tab' => $boardTab['key']], ['board']) }}" class="ops-tab {{ $tab === $boardTab['key'] ? 'is-active' : '' }}">
                    {{ $boardTab['label'] }}
                </a>
            @endforeach
        </div>
        @if($currentUser?->hasScope('assigned', 'deliveries'))
            <div class="ops-tabs" aria-label="Ownership tabs">
                @foreach($ownershipTabs as $ownershipTab)
                    <a href="{{ $boardHref(['ownership' => $ownershipTab['key']], ['board']) }}" class="ops-tab {{ $ownershipFilter === $ownershipTab['key'] ? 'is-active' : '' }}">
                        {{ $ownershipTab['label'] }}
                    </a>
                @endforeach
            </div>
        @endif

        <div class="ops-search-shell">
            <form method="GET" action="{{ route('deliveries.index') }}" class="ops-search-form">
                <input type="hidden" name="ownership" value="{{ $ownershipFilter }}">
                <input type="hidden" name="tab" value="{{ $tab }}">
                @foreach($sortFormQuery as $queryKey => $queryValue)
                    @if(!in_array($queryKey, ['search', 'ownership', 'tab'], true))
                        <input type="hidden" name="{{ $queryKey }}" value="{{ $queryValue }}">
                    @endif
                @endforeach
                <div class="ops-search-field">
                    <label for="desktop_delivery_search" class="sr-only">Search tasks</label>
                    <input
                        id="desktop_delivery_search"
                        type="search"
                        name="search"
                        value="{{ $search }}"
                        placeholder="Search customer, mobile, product, sale order"
                    >
                </div>
                <button type="submit" class="rn-btn-primary">Search</button>
                <a href="{{ route('deliveries.index') }}" class="rn-btn" data-filter-clear="deliveries-index">Clear Filters</a>
            </form>
            @if(!empty($activeFilterChips))
                <div class="ops-filter-chip-row" aria-label="Active task filters">
                    @foreach($activeFilterChips as $chip)
                        <span class="ops-filter-chip">{{ $chip }}</span>
                    @endforeach
                </div>
            @endif
        </div>

        <details class="ops-filters-card ops-filter-toggle" data-filter-panel data-filter-panel-key="deliveries-index" data-filter-active="{{ $hasActiveFilters ? 'true' : 'false' }}" @if($hasActiveFilters) open @endif>
            <summary>
                <h2>Search &amp; Filters</h2>
                <span>{{ $hasActiveFilters ? 'Filters Active · ' . count($activeFilterChips) : 'Expand advanced filters' }}</span>
            </summary>
            <div class="ops-filter-body">
                <form method="GET" action="{{ route('deliveries.index') }}" class="ops-filter-grid">
                    <input type="hidden" name="ownership" value="{{ $ownershipFilter }}">
                    <div class="ops-filter-field">
                        <label for="ops_search">Search</label>
                        <input id="ops_search" type="search" name="search" value="{{ $search }}" placeholder="Customer, mobile, product, sale order">
                    </div>
                    <div class="ops-filter-field">
                        <label for="ops_date">Date</label>
                        <input id="ops_date" type="date" name="date" value="{{ $selectedDate }}">
                    </div>
                    <div class="ops-filter-field">
                        <label for="ops_task_type">Task Type</label>
                        <select id="ops_task_type" name="task_type">
                            <option value="">All types</option>
                            <option value="delivery" @selected($taskType === 'delivery')>Deliveries</option>
                            <option value="pickup" @selected($taskType === 'pickup')>Pickups</option>
                        </select>
                    </div>
                    <div class="ops-filter-field">
                        <label for="ops_staff">Staff</label>
                        <select id="ops_staff" name="staff">
                            <option value="">All assignees</option>
                            <option value="unassigned" @selected($staffFilter === 'unassigned')>Unassigned</option>
                            @if($assignableUsers->isNotEmpty())
                                <optgroup label="Delivery Team">
                                    @foreach($assignableUsers as $user)
                                        <option value="user:{{ $user->id }}" @selected($staffFilter === 'user:' . $user->id)>
                                            {{ $user->name }}
                                        </option>
                                    @endforeach
                                </optgroup>
                            @endif
                            @if($assignableStaffMembers->isNotEmpty())
                                <optgroup label="Vendor Staff">
                                    @foreach($assignableStaffMembers as $staffMember)
                                        <option value="staff:{{ $staffMember->id }}" @selected($staffFilter === 'staff:' . $staffMember->id)>
                                            {{ $staffMember->name }}
                                        </option>
                                    @endforeach
                                </optgroup>
                            @endif
                            <option value="third_party" @selected($staffFilter === 'third_party')>Third party</option>
                        </select>
                    </div>
                    <div class="ops-filter-field">
                        <label for="ops_area">Area / Warehouse</label>
                        <select id="ops_area" name="area">
                            <option value="">All areas</option>
                            @if($warehouseOptions->isNotEmpty())
                                <optgroup label="Warehouses">
                                    @foreach($warehouseOptions as $warehouse)
                                        <option value="warehouse:{{ $warehouse->id }}" @selected($areaFilter === 'warehouse:' . $warehouse->id)>
                                            {{ $warehouse->name }}
                                        </option>
                                    @endforeach
                                </optgroup>
                            @endif
                            @if($areaOptions->isNotEmpty())
                                <optgroup label="Customer Areas">
                                    @foreach($areaOptions as $city)
                                        <option value="city:{{ $city }}" @selected($areaFilter === 'city:' . $city)>
                                            {{ $city }}
                                        </option>
                                    @endforeach
                                </optgroup>
                            @endif
                        </select>
                    </div>
                    <div class="ops-filter-field">
                        <label for="ops_status">Status</label>
                        <select id="ops_status" name="status">
                            <option value="">All statuses</option>
                            <option value="pending" @selected($statusFilter === 'pending')>Pending</option>
                            <option value="in_progress" @selected($statusFilter === 'in_progress')>In Progress</option>
                            <option value="completed" @selected($statusFilter === 'completed')>Completed</option>
                            <option value="cancelled" @selected($statusFilter === 'cancelled')>Cancelled</option>
                        </select>
                    </div>
                    <div class="ops-filter-actions">
                        <input type="hidden" name="tab" value="{{ $tab }}">
                        <input type="hidden" name="sort_by" value="{{ $sortBy }}">
                        <input type="hidden" name="sort_dir" value="{{ $sortDirection }}">
                        <button type="submit" class="rn-btn-primary">Apply</button>
                        <a href="{{ route('deliveries.index') }}" class="rn-btn" data-filter-clear="deliveries-index">Clear Filters</a>
                    </div>
                </form>
            </div>
        </details>
    </div>

    <div id="deliveries-mobile-filters" class="mobile-filter-sheet" data-mobile-filter-sheet hidden>
        <div class="mobile-filter-sheet-panel">
            <div class="mobile-filter-sheet-header">
                <div>
                    <h3>Task Filters</h3>
                    <p>Keep task type, assignee, area, and status within thumb reach.</p>
                </div>
                <button type="button" class="mobile-filter-sheet-close" data-mobile-sheet-close="deliveries-mobile-filters" aria-label="Close filters">×</button>
            </div>
            <div class="mobile-filter-sheet-body">
                <form method="GET" action="{{ route('deliveries.index') }}" class="mobile-sheet-form">
                    <input type="hidden" name="tab" value="{{ $tab }}">
                    <input type="hidden" name="sort_by" value="{{ $sortBy }}">
                    <input type="hidden" name="sort_dir" value="{{ $sortDirection }}">
                    <div class="mobile-sheet-grid">
                        <div class="mobile-sheet-field">
                            <label for="mobile_task_search">Search</label>
                            <input id="mobile_task_search" type="search" name="search" value="{{ $search }}" placeholder="Customer, phone, product">
                        </div>
                        <div class="mobile-sheet-field">
                            <label for="mobile_task_date">Date</label>
                            <input id="mobile_task_date" type="date" name="date" value="{{ $selectedDate }}">
                        </div>
                        <div class="mobile-sheet-field">
                            <label for="mobile_task_type">Task Type</label>
                            <select id="mobile_task_type" name="task_type">
                                <option value="">All</option>
                                <option value="delivery" @selected($taskType === 'delivery')>Deliveries</option>
                                <option value="pickup" @selected($taskType === 'pickup')>Pickups</option>
                            </select>
                        </div>
                        <div class="mobile-sheet-field">
                            <label for="mobile_task_status">Status</label>
                            <select id="mobile_task_status" name="status">
                                <option value="">All</option>
                                <option value="pending" @selected($statusFilter === 'pending')>Pending</option>
                                <option value="in_progress" @selected($statusFilter === 'in_progress')>In Progress</option>
                                <option value="completed" @selected($statusFilter === 'completed')>Completed</option>
                                <option value="cancelled" @selected($statusFilter === 'cancelled')>Failed / Cancelled</option>
                            </select>
                        </div>
                        @if($currentUser?->hasScope('assigned', 'deliveries'))
                            <div class="mobile-sheet-field">
                                <label for="mobile_task_ownership">Scope</label>
                                <select id="mobile_task_ownership" name="ownership">
                                    <option value="my" @selected($ownershipFilter === 'my')>My Tasks</option>
                                    <option value="all" @selected($ownershipFilter === 'all')>All Tasks</option>
                                </select>
                            </div>
                        @endif
                        <div class="mobile-sheet-field">
                            <label for="mobile_task_staff">Staff</label>
                            <select id="mobile_task_staff" name="staff">
                                <option value="">All assignees</option>
                                <option value="unassigned" @selected($staffFilter === 'unassigned')>Unassigned</option>
                                @if($assignableUsers->isNotEmpty())
                                    <optgroup label="Delivery Team">
                                        @foreach($assignableUsers as $user)
                                            <option value="user:{{ $user->id }}" @selected($staffFilter === 'user:' . $user->id)>{{ $user->name }}</option>
                                        @endforeach
                                    </optgroup>
                                @endif
                                @if($assignableStaffMembers->isNotEmpty())
                                    <optgroup label="Vendor Staff">
                                        @foreach($assignableStaffMembers as $staffMember)
                                            <option value="staff:{{ $staffMember->id }}" @selected($staffFilter === 'staff:' . $staffMember->id)>{{ $staffMember->name }}</option>
                                        @endforeach
                                    </optgroup>
                                @endif
                                <option value="third_party" @selected($staffFilter === 'third_party')>Third party</option>
                            </select>
                        </div>
                        <div class="mobile-sheet-field">
                            <label for="mobile_task_area">Area / Warehouse</label>
                            <select id="mobile_task_area" name="area">
                                <option value="">All areas</option>
                                @if($warehouseOptions->isNotEmpty())
                                    <optgroup label="Warehouses">
                                        @foreach($warehouseOptions as $warehouse)
                                            <option value="warehouse:{{ $warehouse->id }}" @selected($areaFilter === 'warehouse:' . $warehouse->id)>{{ $warehouse->name }}</option>
                                        @endforeach
                                    </optgroup>
                                @endif
                                @if($areaOptions->isNotEmpty())
                                    <optgroup label="Customer Areas">
                                        @foreach($areaOptions as $city)
                                            <option value="city:{{ $city }}" @selected($areaFilter === 'city:' . $city)>{{ $city }}</option>
                                        @endforeach
                                    </optgroup>
                                @endif
                            </select>
                        </div>
                    </div>
                    <div class="mobile-sheet-actions">
                        <button type="submit" class="rn-btn-primary">Apply Filters</button>
                        <a href="{{ route('deliveries.index') }}" class="rn-btn">Reset</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="ops-board-grid">
        <div class="ops-task-shell">
            <div class="ops-task-head">
                <div>
                    <h2>{{ collect($tabs)->firstWhere('key', $tab)['label'] ?? 'All Tasks' }}</h2>
                    <p>Direct actions stay visible here, so staff can call, map, start, complete, and open tasks without hopping through separate modules.</p>
                </div>
                <div class="ops-task-meta">
                    <form method="GET" action="{{ route('deliveries.index') }}" class="ops-sort-form">
                        @foreach($sortFormQuery as $queryKey => $queryValue)
                            <input type="hidden" name="{{ $queryKey }}" value="{{ $queryValue }}">
                        @endforeach
                        <label for="ops_sort_by" class="ops-sort-label">Sort:</label>
                        <select id="ops_sort_by" name="sort_by" class="ops-sort-select" onchange="this.form.submit()">
                            @foreach($sortOptions as $sortKey => $sortLabel)
                                <option value="{{ $sortKey }}" @selected($sortBy === $sortKey)>{{ $sortLabel }}</option>
                            @endforeach
                        </select>
                        <select id="ops_sort_dir" name="sort_dir" class="ops-sort-select" onchange="this.form.submit()" aria-label="Sort direction">
                            @foreach($sortDirectionOptions as $directionKey => $directionLabel)
                                <option value="{{ $directionKey }}" @selected($sortDirection === $directionKey)>{{ $directionLabel }}</option>
                            @endforeach
                        </select>
                    </form>
                    <span id="deliverySelectedCount" class="ops-selected-count" aria-live="polite">0 selected</span>
                    <div class="ops-task-count">{{ $taskResultsCount ?? $tasks->count() }} tasks</div>
                </div>
            </div>

            @if($tasks->isEmpty())
                <div class="ops-empty">No delivery or pickup tasks match the current board and filters right now.</div>
            @else
                @php
                    $taskStartIndex = method_exists($tasks, 'firstItem')
                        ? ((int) ($tasks->firstItem() ?? 1))
                        : 1;
                @endphp
                <div class="ops-table-wrap">
                    <table class="ops-table">
                        <thead>
                            <tr>
                                <th class="ops-col-serial">#</th>
                                <th class="ops-col-select ops-checkbox-head">
                                    <input type="checkbox" id="deliverySelectAll" class="ops-select-all" aria-label="Select all visible tasks">
                                </th>
                                <th class="ops-col-type">Type</th>
                                <th class="ops-col-order">Order / Rental</th>
                                <th class="ops-col-customer">Customer</th>
                                <th class="ops-col-schedule">Schedule</th>
                                <th class="ops-col-staff">Staff</th>
                                <th class="ops-col-status">Status</th>
                                <th class="ops-col-actions">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($tasks as $delivery)
                                @php
                                    $serialNumber = $taskStartIndex + $loop->index;
                                    $isSaleTask = (bool) $delivery->sale_id;
                                    $customerRecord = $isSaleTask ? $delivery->sale?->customer : $delivery->rental?->customer;
                                    $customerName = $delivery->linkedCustomerName();
                                    $customerPhone = $delivery->linkedCustomerPhone();
                                    $customerCity = $delivery->linkedCustomerCity();
                                    $callHref = $customerPhone ? 'tel:' . preg_replace('/\s+/', '', $customerPhone) : null;
                                    $whatsAppNumber = \App\Support\WhatsAppHelper::normalizeNumber($customerPhone);
                                    $whatsAppUrl = $whatsAppNumber
                                        ? (
                                            $isSaleTask && $delivery->sale
                                                ? WhatsAppHelper::chatUrl($whatsAppNumber, WhatsAppHelper::saleFollowUp($delivery->sale))
                                                : (
                                                    $delivery->rental
                                                        ? WhatsAppHelper::chatUrl(
                                                            $whatsAppNumber,
                                                            $delivery->type === 'pickup'
                                                                ? WhatsAppHelper::rentalPickupReminder($delivery->rental)
                                                                : WhatsAppHelper::rentalDeliveryConfirmation($delivery->rental)
                                                        )
                                                        : WhatsAppHelper::chatUrl($whatsAppNumber)
                                                )
                                        )
                                        : null;
                                    $mapUrl = $delivery->linkedCustomerMapUrl();
                                    $items = $formatTaskItems($delivery);
                                    $progressStatus = $isSaleTask
                                        ? $delivery->status
                                        : ($delivery->type === 'delivery' ? $delivery->rental?->deliveryStatus() : $delivery->rental?->pickupStatus());
                                    $displayStatus = $delivery->status;
                                    if (!$isSaleTask && $progressStatus) {
                                        $displayStatus = match ($delivery->type) {
                                            'delivery' => match ($progressStatus) {
                                                'completed' => 'delivered',
                                                'partially_delivered' => 'partially_delivered',
                                                default => $delivery->status,
                                            },
                                            'pickup' => match ($progressStatus) {
                                                'completed' => 'picked_up',
                                                'partial_return' => 'partial_return',
                                                default => $delivery->status,
                                            },
                                            default => $delivery->status,
                                        };
                                    }
                                    $progressCopy = $formatTaskProgress($delivery);
                                    $isOverdue = in_array($delivery->status, ['pending', 'in_progress'], true)
                                        && $delivery->scheduled_at?->isPast()
                                        && $delivery->scheduled_at?->toDateString() < now()->toDateString();
                                    $schedulePrimary = $delivery->scheduled_at ? $delivery->scheduled_at->format('d M Y h:i A') : 'Not scheduled';
                                    $scheduleCopy = $delivery->status === 'completed'
                                        ? 'Completed trip'
                                        : ($isOverdue ? 'Needs attention' : ($delivery->scheduled_at && $delivery->scheduled_at->isToday() ? 'Due today' : 'Field task'));
                                    $assignedName = $delivery->assignedUser->name
                                        ?? $delivery->assignedStaff->name
                                        ?? $delivery->third_party_name
                                        ?? 'Unassigned';
                                    $assignedRole = $delivery->assignedUser
                                        ? 'Delivery team'
                                        : ($delivery->assignedStaff
                                            ? ucwords(str_replace('_', ' ', $delivery->assignedStaff->assignment_role ?? 'vendor'))
                                            : ($delivery->assignment_type === 'third_party' ? 'Third party' : 'Unassigned'));
                                    $completePartial = !$isSaleTask
                                        && (
                                            ($delivery->type === 'delivery' && $delivery->rental && $delivery->rental->pendingDeliveryQuantityTotal() > 0)
                                            || ($delivery->type === 'pickup' && $delivery->rental && $delivery->rental->pendingPickupQuantityTotal() > 0)
                                        );
                                    $taskEffectivelyCompleted = !$isSaleTask && $progressStatus === 'completed';
                                    $canViewTask = auth()->user()?->can('view', $delivery) ?? false;
                                    $canUpdateTask = auth()->user()?->can('update', $delivery) ?? false;
                                    $canDeleteTask = auth()->user()?->can('delete', $delivery) ?? false;
                                    $startActionLabel = $delivery->type === 'pickup' ? 'Start Pickup' : 'Start Delivery';
                                    $completeActionLabel = $delivery->type === 'pickup' ? 'Complete Pickup' : 'Complete Delivery';
                                    $showProofHistory = $canViewTask && (int) ($delivery->proofs_count ?? 0) > 0;
                                    $proofHistoryHref = $showProofHistory ? route('deliveries.show', $delivery) . '#delivery-proof-history' : null;
                                @endphp
                                <tr class="{{ $isOverdue ? 'is-overdue' : '' }}">
                                    <td class="ops-col-serial ops-serial-cell" data-label="No.">{{ $serialNumber }}</td>
                                    <td class="ops-col-select ops-checkbox-cell" data-label="Select">
                                        <input
                                            type="checkbox"
                                            class="ops-task-checkbox"
                                            value="{{ $delivery->id }}"
                                            data-task-id="{{ $delivery->id }}"
                                            aria-label="Select task {{ $serialNumber }}"
                                        >
                                    </td>
                                    <td class="ops-col-type">
                                        <span class="ops-type-badge {{ $delivery->type === 'pickup' ? 'ops-type-pickup' : 'ops-type-delivery' }}">
                                            {!! $navIcon($delivery->type === 'pickup' ? 'pickup' : 'delivery') !!}
                                            {{ ucfirst($delivery->type) }}
                                        </span>
                                    </td>
                                    <td class="ops-col-order">
                                        <div class="ops-stack">
                                            @if($isSaleTask && \Illuminate\Support\Facades\Route::has('sales.show') && $delivery->sale)
                                                <a href="{{ route('sales.show', $delivery->sale) }}" class="ops-record-link">Sale #{{ $delivery->sale->id }}</a>
                                            @elseif(!$isSaleTask && \Illuminate\Support\Facades\Route::has('rentals.show') && $delivery->rental)
                                                <a href="{{ route('rentals.show', $delivery->rental) }}" class="ops-record-link">Rental #{{ $delivery->rental->id }}</a>
                                            @else
                                                <strong>Task #{{ $delivery->id }}</strong>
                                            @endif

                                            <div class="ops-items-list">
                                                @foreach($items->take(3) as $itemLabel)
                                                    <span class="ops-item-pill">{{ $itemLabel }}</span>
                                                @endforeach
                                                @if($items->count() > 3)
                                                    <span class="ops-muted">+{{ $items->count() - 3 }} more items</span>
                                                @endif
                                            </div>
                                        </div>
                                    </td>
                                    <td class="ops-col-customer">
                                        <div class="ops-stack">
                                            @if(\Illuminate\Support\Facades\Route::has('customers.show') && $customerRecord)
                                                <a href="{{ route('customers.show', $customerRecord) }}" class="ops-record-link">{{ $customerName }}</a>
                                            @else
                                                <strong>{{ $customerName }}</strong>
                                            @endif
                                            @if($customerPhone)
                                                <span class="ops-muted">{{ $customerPhone }}</span>
                                            @endif
                                            @if($customerCity)
                                                <span class="ops-muted">{{ $customerCity }}</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="ops-col-schedule">
                                        <div class="ops-stack">
                                            <strong class="{{ $isOverdue ? 'ops-schedule-danger' : '' }}">{{ $schedulePrimary }}</strong>
                                            <span class="ops-muted">{{ $scheduleCopy }}</span>
                                            @if($delivery->completed_at && $delivery->status === 'completed')
                                                <span class="ops-muted">Closed {{ $delivery->completed_at->format('d M h:i A') }}</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="ops-col-staff">
                                        <div class="ops-stack">
                                            <strong>{{ $assignedName }}</strong>
                                            <span class="ops-muted">{{ $assignedRole }}</span>
                                            @if(!$isSaleTask && $delivery->rental?->dispatchWarehouse)
                                                <span class="ops-muted">{{ $delivery->rental->dispatchWarehouse->name }}</span>
                                            @elseif($isSaleTask && $delivery->sale?->asset?->warehouse)
                                                <span class="ops-muted">{{ $delivery->sale->asset->warehouse->name }}</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="ops-col-status">
                                        <div class="ops-status-stack">
                                            <span class="rn-badge {{ $taskStatusBadgeClass($displayStatus, $isOverdue) }}">
                                                {{ $taskStatusLabel($displayStatus) }}
                                            </span>
                                            @if(!$isSaleTask && $progressStatus && $progressStatus !== $delivery->status && $progressStatus !== $displayStatus)
                                                <span class="rn-badge {{ $progressBadgeClass($progressStatus) }}">{{ $progressLabel($progressStatus) }}</span>
                                            @endif
                                            @if($progressCopy)
                                                <span class="ops-progress-copy">{{ $progressCopy }}</span>
                                            @endif
                                            @if($delivery->status === 'cancelled' && $delivery->cancellation_reason)
                                                <span class="ops-progress-copy">Reason: {{ \App\Models\Delivery::cancellationReasonLabel($delivery->cancellation_reason) }}</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="ops-col-actions">
                                        <div class="ops-actions">
                                            @if($callHref)
                                                <a href="{{ $callHref }}" class="ops-action-btn" title="Call customer" aria-label="Call customer">
                                                    {!! $navIcon('call') !!}
                                                    <span>Call</span>
                                                </a>
                                            @endif
                                            @if($whatsAppUrl)
                                                <a href="{{ $whatsAppUrl }}" target="_blank" rel="noopener" class="ops-action-btn ops-action-btn-wa" title="WhatsApp customer" aria-label="WhatsApp customer">
                                                    {!! $navIcon('whatsapp') !!}
                                                    <span>WhatsApp</span>
                                                </a>
                                            @endif
                                            @if($mapUrl)
                                                <a href="{{ $mapUrl }}" target="_blank" rel="noopener" class="ops-action-btn ops-action-btn-map" title="Open map" aria-label="Open map">
                                                    {!! $navIcon('map') !!}
                                                    <span>Map</span>
                                                </a>
                                            @endif
                                            @if($canViewTask && \Illuminate\Support\Facades\Route::has('deliveries.show'))
                                                <a href="{{ route('deliveries.show', $delivery) }}" class="ops-action-btn" title="View task" aria-label="View task">
                                                    {!! $navIcon('view') !!}
                                                    <span>View</span>
                                                </a>
                                            @endif
                                            @if($canUpdateTask && $delivery->status === 'pending' && !$taskEffectivelyCompleted)
                                                <a href="{{ route('deliveries.show', $delivery) }}#workflow-proof-section" class="ops-action-btn-primary" title="{{ $startActionLabel }}" aria-label="{{ $startActionLabel }}">
                                                    {!! $navIcon('start') !!}
                                                    <span>{{ $startActionLabel }}</span>
                                                </a>
                                            @endif
                                            @if($canUpdateTask && $delivery->status === 'in_progress' && !$taskEffectivelyCompleted)
                                                <a href="{{ route('deliveries.show', $delivery) }}#workflow-proof-section" class="ops-action-btn-primary" title="{{ $completePartial ? $completeActionLabel . ' (partial allowed)' : $completeActionLabel }}" aria-label="{{ $completeActionLabel }}">
                                                    {!! $navIcon('completed') !!}
                                                    <span>{{ $completeActionLabel }}</span>
                                                </a>
                                            @endif
                                            <details class="ops-action-menu">
                                                <summary aria-label="More actions for task {{ $delivery->id }}">{!! $navIcon('menu') !!}</summary>
                                                <div class="ops-action-panel">
                                                    @if($canViewTask && \Illuminate\Support\Facades\Route::has('deliveries.show'))
                                                        <a href="{{ route('deliveries.show', $delivery) }}">View</a>
                                                    @endif
                                                    @if($canUpdateTask && $delivery->status === 'pending' && !$taskEffectivelyCompleted)
                                                        <a href="{{ route('deliveries.show', $delivery) }}#workflow-proof-section">{{ $startActionLabel }}</a>
                                                    @endif
                                                    @if($canUpdateTask && $delivery->status === 'in_progress' && !$taskEffectivelyCompleted)
                                                        <a href="{{ route('deliveries.show', $delivery) }}#workflow-proof-section">{{ $completeActionLabel }}</a>
                                                    @endif
                                                    @if($callHref)
                                                        <a href="{{ $callHref }}">Call Customer</a>
                                                    @endif
                                                    @if($whatsAppUrl)
                                                        <a href="{{ $whatsAppUrl }}" target="_blank" rel="noopener">WhatsApp Customer</a>
                                                    @endif
                                                    @if($proofHistoryHref)
                                                        <a href="{{ $proofHistoryHref }}">View Proof</a>
                                                    @endif
                                                    @if($canUpdateTask && !in_array($delivery->status, ['completed', 'cancelled'], true))
                                                        <a href="{{ route('deliveries.show', $delivery) }}#delivery-cancellation-section">Cancel Task</a>
                                                    @endif
                                                    @if(!$assignedScopedDeliveryUser && $canUpdateTask && \Illuminate\Support\Facades\Route::has('deliveries.edit'))
                                                        <a href="{{ route('deliveries.edit', $delivery) }}">Edit</a>
                                                    @endif
                                                    @if(!$assignedScopedDeliveryUser && $canDeleteTask && \Illuminate\Support\Facades\Route::has('deliveries.destroy'))
                                                        <form action="{{ route('deliveries.destroy', $delivery) }}" method="POST">
                                                            @csrf
                                                            @method('DELETE')
                                                            <button type="submit" class="danger" onclick="return confirm('Delete this task record?')">Delete</button>
                                                        </form>
                                                    @endif
                                                </div>
                                            </details>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="ops-mobile-list">
                    @foreach($tasks as $delivery)
                        @php
                            $serialNumber = $taskStartIndex + $loop->index;
                            $isSaleTask = (bool) $delivery->sale_id;
                            $customerName = $delivery->linkedCustomerName();
                            $customerPhone = $delivery->linkedCustomerPhone();
                            $customerCity = $delivery->linkedCustomerCity();
                            $callHref = $customerPhone ? 'tel:' . preg_replace('/\s+/', '', $customerPhone) : null;
                            $whatsAppNumber = \App\Support\WhatsAppHelper::normalizeNumber($customerPhone);
                            $whatsAppUrl = $whatsAppNumber
                                ? (
                                    $isSaleTask && $delivery->sale
                                        ? WhatsAppHelper::chatUrl($whatsAppNumber, WhatsAppHelper::saleFollowUp($delivery->sale))
                                        : (
                                            $delivery->rental
                                                ? WhatsAppHelper::chatUrl(
                                                    $whatsAppNumber,
                                                    $delivery->type === 'pickup'
                                                        ? WhatsAppHelper::rentalPickupReminder($delivery->rental)
                                                        : WhatsAppHelper::rentalDeliveryConfirmation($delivery->rental)
                                                )
                                                : WhatsAppHelper::chatUrl($whatsAppNumber)
                                        )
                                )
                                : null;
                            $mapUrl = $delivery->linkedCustomerMapUrl();
                            $items = $formatTaskItems($delivery);
                            $progressStatus = $isSaleTask
                                ? $delivery->status
                                : ($delivery->type === 'delivery' ? $delivery->rental?->deliveryStatus() : $delivery->rental?->pickupStatus());
                            $displayStatus = $delivery->status;
                            if (!$isSaleTask && $progressStatus) {
                                $displayStatus = match ($delivery->type) {
                                    'delivery' => match ($progressStatus) {
                                        'completed' => 'delivered',
                                        'partially_delivered' => 'partially_delivered',
                                        default => $delivery->status,
                                    },
                                    'pickup' => match ($progressStatus) {
                                        'completed' => 'picked_up',
                                        'partial_return' => 'partial_return',
                                        default => $delivery->status,
                                    },
                                    default => $delivery->status,
                                };
                            }
                            $progressCopy = $formatTaskProgress($delivery);
                            $isOverdue = in_array($delivery->status, ['pending', 'in_progress'], true)
                                && $delivery->scheduled_at?->isPast()
                                && $delivery->scheduled_at?->toDateString() < now()->toDateString();
                            $assignedName = $delivery->assignedUser->name
                                ?? $delivery->assignedStaff->name
                                ?? $delivery->third_party_name
                                ?? 'Unassigned';
                            $completePartial = !$isSaleTask
                                && (
                                    ($delivery->type === 'delivery' && $delivery->rental && $delivery->rental->pendingDeliveryQuantityTotal() > 0)
                                    || ($delivery->type === 'pickup' && $delivery->rental && $delivery->rental->pendingPickupQuantityTotal() > 0)
                                );
                            $taskEffectivelyCompleted = !$isSaleTask && $progressStatus === 'completed';
                            $canViewTask = auth()->user()?->can('view', $delivery) ?? false;
                            $canUpdateTask = auth()->user()?->can('update', $delivery) ?? false;
                            $canDeleteTask = auth()->user()?->can('delete', $delivery) ?? false;
                            $startActionLabel = $delivery->type === 'pickup' ? 'Start Pickup' : 'Start Delivery';
                            $completeActionLabel = $delivery->type === 'pickup' ? 'Complete Pickup' : 'Complete Delivery';
                            $showProofHistory = $canViewTask && (int) ($delivery->proofs_count ?? 0) > 0;
                            $proofHistoryHref = $showProofHistory ? route('deliveries.show', $delivery) . '#delivery-proof-history' : null;
                            $taskDetailHref = $canViewTask && \Illuminate\Support\Facades\Route::has('deliveries.show')
                                ? route('deliveries.show', $delivery)
                                : null;
                        @endphp
                        <article
                            class="ops-mobile-card {{ $isOverdue ? 'is-overdue' : '' }}"
                            data-task-card
                            data-task-href="{{ $taskDetailHref }}"
                            tabindex="0"
                            role="link"
                            aria-label="Open {{ $delivery->type }} task {{ $delivery->id }}"
                        >
                            <div class="ops-mobile-top">
                                <div class="ops-mobile-card-head">
                                    <div class="ops-mobile-topline">
                                        <input
                                            type="checkbox"
                                            class="ops-task-checkbox"
                                            value="{{ $delivery->id }}"
                                            data-task-id="{{ $delivery->id }}"
                                            aria-label="Select task {{ $serialNumber }}"
                                        >
                                        <span class="ops-mobile-serial">Task #{{ $serialNumber }}</span>
                                        <span class="ops-type-badge {{ $delivery->type === 'pickup' ? 'ops-type-pickup' : 'ops-type-delivery' }}">
                                            {!! $navIcon($delivery->type === 'pickup' ? 'pickup' : 'delivery') !!}
                                            {{ ucfirst($delivery->type) }}
                                        </span>
                                        <span class="rn-badge {{ $taskStatusBadgeClass($displayStatus, $isOverdue) }}">{{ $taskStatusLabel($displayStatus) }}</span>
                                    </div>
                                    <div class="ops-mobile-order">
                                        @if($isSaleTask && $delivery->sale)
                                            Sale #{{ $delivery->sale->id }}
                                        @elseif(!$isSaleTask && $delivery->rental)
                                            Rental #{{ $delivery->rental->id }}
                                        @else
                                            Task #{{ $delivery->id }}
                                        @endif
                                    </div>
                                    <div class="ops-mobile-contact">
                                        <span class="ops-mobile-customer">{{ $customerName }}</span>
                                        @if($customerPhone)
                                            <span class="ops-mobile-phone">{{ $customerPhone }}</span>
                                        @endif
                                        <span class="ops-mobile-product">{{ $items->take(1)->implode(', ') ?: 'No linked items yet' }}</span>
                                    </div>
                                </div>
                                <details class="ops-action-menu ops-mobile-more">
                                    <summary aria-label="More actions for task {{ $delivery->id }}">{!! $navIcon('menu') !!}</summary>
                                    <div class="ops-action-panel">
                                        @if($taskDetailHref)
                                            <a href="{{ $taskDetailHref }}">View Details</a>
                                        @endif
                                        @if($canUpdateTask && $delivery->status === 'pending' && !$taskEffectivelyCompleted)
                                            <a href="{{ route('deliveries.show', $delivery) }}#workflow-proof-section">{{ $startActionLabel }}</a>
                                        @endif
                                        @if($canUpdateTask && $delivery->status === 'in_progress' && !$taskEffectivelyCompleted)
                                            <a href="{{ route('deliveries.show', $delivery) }}#workflow-proof-section">{{ $completeActionLabel }}</a>
                                        @endif
                                        @if($proofHistoryHref)
                                            <a href="{{ $proofHistoryHref }}">Proof History</a>
                                        @endif
                                        @if($canUpdateTask && !in_array($delivery->status, ['completed', 'cancelled'], true))
                                            <a href="{{ route('deliveries.show', $delivery) }}#delivery-cancellation-section">Cancel Task</a>
                                        @endif
                                        @if(!$assignedScopedDeliveryUser && $canUpdateTask && \Illuminate\Support\Facades\Route::has('deliveries.edit'))
                                            <a href="{{ route('deliveries.edit', $delivery) }}">Edit Assignment</a>
                                        @endif
                                        @if(!$assignedScopedDeliveryUser && $canDeleteTask && \Illuminate\Support\Facades\Route::has('deliveries.destroy'))
                                            <form action="{{ route('deliveries.destroy', $delivery) }}" method="POST">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="danger" onclick="return confirm('Delete this task record?')">Delete</button>
                                            </form>
                                        @endif
                                    </div>
                                </details>
                            </div>

                            <div class="ops-mobile-grid">
                                <div class="ops-mobile-meta">
                                    <span>Schedule</span>
                                    <span>{{ $delivery->scheduled_at ? $delivery->scheduled_at->format('d M h:i A') : 'Not scheduled' }}</span>
                                </div>
                                <div class="ops-mobile-meta">
                                    <span>Staff</span>
                                    <span>{{ $assignedName }}</span>
                                </div>
                                <div class="ops-mobile-meta">
                                    <span>Phone</span>
                                    <span>{{ $customerPhone ?: 'No phone saved' }}</span>
                                </div>
                                <div class="ops-mobile-meta">
                                    <span>Location</span>
                                    <span>{{ $customerCity ?: 'No city saved' }}</span>
                                </div>
                            </div>

                            @if($progressCopy || (!$isSaleTask && $progressStatus && $progressStatus !== $delivery->status && $progressStatus !== $displayStatus))
                                <div class="ops-stack">
                                    @if(!$isSaleTask && $progressStatus && $progressStatus !== $delivery->status && $progressStatus !== $displayStatus)
                                        <span class="rn-badge {{ $progressBadgeClass($progressStatus) }}">{{ $progressLabel($progressStatus) }}</span>
                                    @endif
                                    @if($progressCopy)
                                        <span class="ops-progress-copy">{{ $progressCopy }}</span>
                                    @endif
                                    @if($delivery->status === 'cancelled' && $delivery->cancellation_reason)
                                        <span class="ops-progress-copy">Reason: {{ \App\Models\Delivery::cancellationReasonLabel($delivery->cancellation_reason) }}</span>
                                    @endif
                                </div>
                            @endif

                            <div class="ops-mobile-actions">
                                @if($callHref)
                                    <a href="{{ $callHref }}" class="ops-action-btn mobile-utility-btn" title="Call customer" aria-label="Call customer">
                                        {!! $navIcon('call') !!}
                                        <span>Call</span>
                                    </a>
                                @endif
                                @if($whatsAppUrl)
                                    <a href="{{ $whatsAppUrl }}" target="_blank" rel="noopener" class="ops-action-btn ops-action-btn-wa mobile-utility-btn" title="WhatsApp customer" aria-label="WhatsApp customer">
                                        {!! $navIcon('whatsapp') !!}
                                        <span>WhatsApp</span>
                                    </a>
                                @endif
                                @if($mapUrl)
                                    <a href="{{ $mapUrl }}" target="_blank" rel="noopener" class="ops-action-btn ops-action-btn-map mobile-utility-btn" title="Open map" aria-label="Open map">
                                        {!! $navIcon('map') !!}
                                        <span>Map</span>
                                    </a>
                                @endif
                                @if($taskDetailHref)
                                    <a href="{{ $taskDetailHref }}" class="ops-action-btn mobile-utility-btn" title="View task" aria-label="View task">
                                        {!! $navIcon('view') !!}
                                        <span>View</span>
                                    </a>
                                @endif
                                @if($canUpdateTask && $delivery->status === 'pending' && !$taskEffectivelyCompleted)
                                    <a href="{{ route('deliveries.show', $delivery) }}#workflow-proof-section" class="ops-action-btn-primary" title="{{ $startActionLabel }}" aria-label="{{ $startActionLabel }}">
                                        {!! $navIcon('start') !!}
                                        <span>{{ $startActionLabel }}</span>
                                    </a>
                                @endif
                                @if($canUpdateTask && $delivery->status === 'in_progress' && !$taskEffectivelyCompleted)
                                    <a href="{{ route('deliveries.show', $delivery) }}#workflow-proof-section" class="ops-action-btn-primary" title="{{ $completePartial ? $completeActionLabel . ' (partial allowed)' : $completeActionLabel }}" aria-label="{{ $completeActionLabel }}">
                                        {!! $navIcon('completed') !!}
                                        <span>{{ $completeActionLabel }}</span>
                                    </a>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>

                @if(method_exists($tasks, 'links'))
                    <div class="ph-card" style="margin:12px 12px 0; padding:14px 16px;">
                        {{ $tasks->links() }}
                    </div>
                @endif
            @endif
        </div>

        <div class="ops-widget-stack">
            <div class="ops-widget-card">
                <div class="ops-widget-head">
                    <div>
                        <h3>Today’s Overview</h3>
                        <p>{{ $todayLabel }} operational pulse.</p>
                    </div>
                    <span class="rn-badge rn-badge-active">{{ $todayTaskCount }} today</span>
                </div>
                <div class="ops-widget-list">
                    <div class="ops-widget-item">
                        <strong>{{ $todayDeliveryCount }} delivery tasks</strong>
                        <span>Scheduled for today across the field team.</span>
                    </div>
                    <div class="ops-widget-item">
                        <strong>{{ $todayPickupCount }} pickup tasks</strong>
                        <span>Collections and returns due today.</span>
                    </div>
                    @forelse($todayOverviewTasks as $overviewTask)
                        <div class="ops-widget-item">
                            <strong>{{ ucfirst($overviewTask->type) }} #{{ $overviewTask->id }}</strong>
                            <span>{{ $overviewTask->scheduled_at ? $overviewTask->scheduled_at->format('d M h:i A') : 'No schedule' }} • {{ $overviewTask->assignedUser->name ?? $overviewTask->assignedStaff->name ?? $overviewTask->third_party_name ?? 'Unassigned' }}</span>
                        </div>
                    @empty
                        <div class="ops-widget-item">
                            <strong>No tasks scheduled today</strong>
                            <span>The board is clear for today so far.</span>
                        </div>
                    @endforelse
                </div>
            </div>

            <div class="ops-widget-card">
                <div class="ops-widget-head">
                    <div>
                        <h3>Overdue Tasks</h3>
                        <p>Included in total tasks; still open past the scheduled window.</p>
                    </div>
                    <span class="rn-badge rn-badge-danger">{{ $overdueTasksCount }}</span>
                </div>
                <div class="ops-widget-list">
                    @forelse($overdueTasks as $overdueTask)
                        <div class="ops-widget-item">
                            <strong>{{ ucfirst($overdueTask->type) }} #{{ $overdueTask->id }}</strong>
                            <span>{{ $overdueTask->scheduled_at ? $overdueTask->scheduled_at->format('d M h:i A') : 'No schedule' }} • {{ $overdueTask->assignedUser->name ?? $overdueTask->assignedStaff->name ?? $overdueTask->third_party_name ?? 'Unassigned' }}</span>
                        </div>
                    @empty
                        <div class="ops-widget-item">
                            <strong>No overdue tasks</strong>
                            <span>Nothing has slipped beyond its scheduled day right now.</span>
                        </div>
                    @endforelse
                </div>
            </div>

            <div class="ops-widget-card">
                <div class="ops-widget-head">
                    <div>
                        <h3>Pending Pickups</h3>
                        <p>Open pickup tasks that still need field action.</p>
                    </div>
                    <span class="rn-badge rn-badge-warning">{{ $pendingCollectionsCount }}</span>
                </div>
                <div class="ops-widget-list">
                    @forelse($pendingCollections as $collectionTask)
                        <div class="ops-widget-item">
                            <strong>Pickup #{{ $collectionTask->id }}</strong>
                            <span>{{ $collectionTask->scheduled_at ? $collectionTask->scheduled_at->format('d M h:i A') : 'No schedule' }} • {{ $collectionTask->assignedUser->name ?? $collectionTask->assignedStaff->name ?? $collectionTask->third_party_name ?? 'Unassigned' }}</span>
                        </div>
                    @empty
                        <div class="ops-widget-item">
                            <strong>No pending pickups</strong>
                            <span>Pickup workload is clear at the moment.</span>
                        </div>
                    @endforelse
                </div>
            </div>

            <div class="ops-widget-card">
                <div class="ops-widget-head">
                    <div>
                        <h3>Help / SOP</h3>
                        <p>Small reminders for smoother handoffs in the field.</p>
                    </div>
                </div>
                <div class="ops-sop-list">
                    <div class="ops-sop-item">
                        <span class="ops-sop-dot">1</span>
                        <span>Start the task before leaving so the board reflects live field movement.</span>
                    </div>
                    <div class="ops-sop-item">
                        <span class="ops-sop-dot">2</span>
                        <span>Use partial completion when only some rental items reach the customer or return to stock.</span>
                    </div>
                    <div class="ops-sop-item">
                        <span class="ops-sop-dot">3</span>
                        <span>Call first, then open the map, so customer communication stays ahead of travel.</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

<script>
document.addEventListener('DOMContentLoaded', function () {
    const selectAll = document.getElementById('deliverySelectAll');
    const selectedCount = document.getElementById('deliverySelectedCount');
    const taskCards = Array.from(document.querySelectorAll('[data-task-card][data-task-href]'));

    const isInteractiveTarget = (target) => Boolean(
        target.closest('a, button, input, select, textarea, summary, details, form, label')
    );

    taskCards.forEach((card) => {
        const href = card.getAttribute('data-task-href');

        if (!href) {
            return;
        }

        card.addEventListener('click', function (event) {
            if (isInteractiveTarget(event.target)) {
                return;
            }

            window.location.href = href;
        });

        card.addEventListener('keydown', function (event) {
            if (!['Enter', ' '].includes(event.key) || isInteractiveTarget(event.target)) {
                return;
            }

            event.preventDefault();
            window.location.href = href;
        });
    });

    if (!selectAll || !selectedCount) {
        return;
    }

    const rowChecks = Array.from(document.querySelectorAll('.ops-task-checkbox'));

    const syncSelectionState = () => {
        const uniqueSelected = new Set(
            rowChecks
                .filter((checkbox) => checkbox.checked)
                .map((checkbox) => checkbox.dataset.taskId)
        );
        const selectedVisibleCount = uniqueSelected.size;
        const allSelected = rowChecks.length > 0 && rowChecks.every((checkbox) => checkbox.checked);

        selectAll.checked = allSelected;
        selectAll.indeterminate = !allSelected && selectedVisibleCount > 0;
        selectedCount.textContent = selectedVisibleCount + ' selected';
        selectedCount.classList.toggle('is-visible', selectedVisibleCount > 0);
    };

    const syncMatchingCheckboxes = (changedCheckbox) => {
        rowChecks.forEach((checkbox) => {
            if (checkbox !== changedCheckbox && checkbox.dataset.taskId === changedCheckbox.dataset.taskId) {
                checkbox.checked = changedCheckbox.checked;
            }
        });
    };

    selectAll.addEventListener('change', function () {
        rowChecks.forEach((checkbox) => {
            checkbox.checked = selectAll.checked;
        });

        syncSelectionState();
    });

    rowChecks.forEach((checkbox) => {
        checkbox.addEventListener('change', function () {
            syncMatchingCheckboxes(checkbox);
            syncSelectionState();
        });
    });

    syncSelectionState();
});
</script>
