<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @php
        $releaseEnvForTitle = strtolower((string) config('app.env', 'production'));
        $releaseTitlePrefix = match ($releaseEnvForTitle) {
            'local' => '[LOCAL] ',
            'uat', 'staging' => '[UAT] ',
            default => '',
        };
    @endphp
    <title>{{ $releaseTitlePrefix }}{{ config('app.name', 'Prime Healers OS') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('images/prime-healers-favicon.png') }}">
    <link rel="shortcut icon" href="{{ asset('favicon.ico') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/prime-healers-favicon.png') }}">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800&family=manrope:600,700,800&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="stylesheet" href="{{ asset('css/phos-operations.css') }}?v={{ hash_file('sha256', public_path('css/phos-operations.css')) }}">
    <script src="{{ asset('js/phos-operations.js') }}?v={{ hash_file('sha256', public_path('js/phos-operations.js')) }}" defer></script>
    <style>
        .phos-predictive-field { position:relative; min-width:0; }
        .phos-predictive-panel {
            position:fixed;
            z-index:1000000;
            display:none;
            width:min(420px, calc(100vw - 24px));
            max-height:min(340px, calc(100vh - 96px));
            overflow:auto;
            padding:6px;
            border:1px solid rgba(148, 163, 184, .32);
            border-radius:14px;
            background:rgba(255,255,255,.98);
            box-shadow:0 18px 48px rgba(15, 23, 42, .16);
        }
        .phos-predictive-panel.is-open { display:block; }
        .phos-predictive-option,
        .phos-predictive-state {
            width:100%;
            border:0;
            background:transparent;
            text-align:left;
            padding:9px 10px;
            border-radius:10px;
            color:#0f172a;
            display:grid;
            gap:3px;
            cursor:pointer;
        }
        .phos-predictive-option:hover,
        .phos-predictive-option.is-active { background:#eef4ff; }
        .phos-predictive-option strong { font-size:13px; line-height:1.25; color:#0f172a; font-weight:800; }
        .phos-predictive-option span { font-size:11px; line-height:1.35; color:#64748b; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .phos-predictive-option em { justify-self:start; margin-top:2px; padding:3px 7px; border-radius:999px; background:#f1f5f9; color:#475569; font-size:10px; font-style:normal; font-weight:800; text-transform:uppercase; letter-spacing:.04em; }
        .phos-predictive-state { cursor:default; color:#64748b; font-size:12px; }
        @media (max-width:767px) {
            .phos-predictive-panel { left:10px !important; right:10px; width:auto !important; max-height:min(310px, 54vh); border-radius:16px; }
        }
    </style>
    <link rel="stylesheet" href="{{ asset('css/phos-shell.css') }}?v={{ hash_file('sha256', public_path('css/phos-shell.css')) }}">
</head>
<body @class(['phos-navigation-refresh', 'phos-list-page' => request()->routeIs('rentals.index', 'sales.index', 'customers.index', 'business-partners.index', 'renewal-center.index', 'pickup-center.index', 'communication-center.index', 'deliveries.index'), 'phos-executive-page' => request()->routeIs('dashboard', 'dashboard.compact')]) style="margin:0; width:100%; overflow-x:hidden; background:var(--ph-color-bg); color:var(--ph-color-text); font-family:var(--ph-font-body);">
@php
    $currentUser = auth()->user();
    $safeRoute = function (string $routeName, array $parameters = []) {
        return \Illuminate\Support\Facades\Route::has($routeName) ? route($routeName, $parameters) : null;
    };
    $makeInitials = function (?string $name): string {
        $parts = collect(preg_split('/\s+/', trim((string) $name)))->filter()->take(2)->map(fn ($part) => strtoupper(mb_substr($part, 0, 1)));
        return $parts->isNotEmpty() ? $parts->implode('') : 'RX';
    };
    $paymentsIndexHref = (($currentUser?->canAccessModule('payments', 'read') ?? false) && \Illuminate\Support\Facades\Route::has('payments.index'))
        ? route('payments.index')
        : null;
    $reportsIndexHref = ($currentUser?->canAccessGeneralReports() ?? false) ? $safeRoute('reports.index') : null;
    $isDeliveryFacingMenuRole = in_array($currentUser?->effective_role, [
        \App\Models\User::ROLE_DELIVERY,
        \App\Models\User::ROLE_DELIVERY_EXECUTIVE,
        \App\Models\User::ROLE_VENDOR,
        \App\Models\User::ROLE_THIRD_PARTY,
    ], true);
    $hideDeliveryInventoryLinks = in_array($currentUser?->effective_role, [
        \App\Models\User::ROLE_DELIVERY, \App\Models\User::ROLE_DELIVERY_EXECUTIVE,
    ], true);
    $userRoleLabel = str_replace('_', ' ', (string) ($currentUser?->effective_role ?: $currentUser?->role ?: 'User'));
    $userRoleLabel = \Illuminate\Support\Str::title($userRoleLabel);
    $userInitials = $makeInitials($currentUser?->name);
    $pickupsHref = ($currentUser?->canAccessModule('deliveries', 'read') ?? false) ? $safeRoute('pickups.assigned') : null;
    $depositsHref = (($currentUser?->canAccessModule('deposits', 'read') ?? false) && \Illuminate\Support\Facades\Route::has('deposits.index'))
        ? route('deposits.index')
        : null;
    $organizationSettingsHref = ($currentUser?->canAccessModule('settings', 'read') ?? false) ? $safeRoute('organization.settings.edit') : null;
    $companyHref = $organizationSettingsHref ? $organizationSettingsHref . '#company' : null;
    $preferencesHref = $organizationSettingsHref ? $organizationSettingsHref . '#preferences' : null;
    $profileHref = $safeRoute('profile.edit');
    $logoutHref = \Illuminate\Support\Facades\Route::has('logout') ? route('logout') : null;
    $topbarNotifications = collect($topbarNotifications ?? []);
    $topbarNotificationCount = (int) ($topbarNotificationCount ?? 0);
    $topbarNotificationsViewAllHref = $topbarNotificationsViewAllHref ?? ($safeRoute('notifications.index') ?: $safeRoute('dashboard'));
    $topbarNotificationsLatestHref = $safeRoute('notifications.latest');
    $topbarNotificationsUnreadCountHref = $safeRoute('notifications.unread-count');
    $topbarNotificationsReadAllHref = $safeRoute('notifications.read-all');
    $topbarNotificationsReadVisibleHref = $safeRoute('notifications.read-visible');
    $topbarNotificationsReadHrefTemplate = Route::has('notifications.read')
        ? url('/notifications/__NOTIFICATION__/read')
        : null;
    $topbarNotificationsPreferencesHref = $safeRoute('notifications.preferences');
    $notificationSoundEnabled = (bool) ($currentUser?->notification_sound_enabled ?? false);
    $notificationVoiceEnabled = (bool) ($currentUser?->notification_voice_enabled ?? false);
    $notificationSoundVariant = in_array((string) ($currentUser?->notification_sound_variant ?? 'default'), ['default', 'soft', 'chime'], true)
        ? (string) ($currentUser?->notification_sound_variant ?? 'default')
        : 'default';
    $notificationSoundOptions = [
        ['value' => 'default', 'label' => 'Default tone'],
        ['value' => 'soft', 'label' => 'Soft tone'],
        ['value' => 'chime', 'label' => 'Chime tone'],
    ];
    $notificationSoundAsset = $notificationSoundVariant === 'default'
        ? asset('sounds/notification.wav')
        : asset('sounds/notification-' . $notificationSoundVariant . '.wav');
    $knowledgeHubHref = $safeRoute('knowledge.index');
    $globalSearchHref = $safeRoute('search.global');
    $globalSearchValue = request()->routeIs('search.global')
        ? trim((string) request('q', ''))
        : '';
    $sidebarPendingCounts = $sidebarPendingCounts ?? [];
    $formatSidebarBadge = function ($value): ?string {
        $count = max((int) $value, 0);

        if ($count <= 0) {
            return null;
        }

        return $count > 99 ? '99+' : (string) $count;
    };
    $iconSemanticClass = function (?string $icon = null, ?string $key = null, ?string $label = null): string {
        $pool = strtolower(trim(implode(' ', array_filter([
            (string) $icon,
            (string) $key,
            (string) $label,
        ]))));

        return match (true) {
            str_contains($pool, 'rental') || str_contains($pool, 'renewal') => 'icon-rental',
            str_contains($pool, 'deliver') || str_contains($pool, 'pickup') || str_contains($pool, 'task') || str_contains($pool, 'vendor') => 'icon-delivery',
            str_contains($pool, 'invent') || str_contains($pool, 'asset') || str_contains($pool, 'stock') || str_contains($pool, 'warehouse') || str_contains($pool, 'product') || str_contains($pool, 'city') => 'icon-inventory',
            str_contains($pool, 'invoice') || str_contains($pool, 'payment') || str_contains($pool, 'deposit') || str_contains($pool, 'sale') || str_contains($pool, 'report') => 'icon-finance',
            str_contains($pool, 'customer') || str_contains($pool, 'crm') || str_contains($pool, 'communication') || str_contains($pool, 'business partner') => 'icon-customer',
            str_contains($pool, 'user') || str_contains($pool, 'role') || str_contains($pool, 'staff') || str_contains($pool, 'team') => 'icon-team',
            str_contains($pool, 'knowledge') || str_contains($pool, 'help') => 'icon-knowledge',
            str_contains($pool, 'setting') || str_contains($pool, 'company') || str_contains($pool, 'dashboard') || str_contains($pool, 'profile') => 'icon-admin',
            default => 'icon-admin',
        };
    };

    $sidebarSections = [
        [
            'label' => 'Main',
            'items' => [
                ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'dashboard', 'href' => $safeRoute('dashboard'), 'active' => request()->routeIs('dashboard', 'dashboard.compact'), 'visible' => $currentUser?->canAccessDashboard() ?? false],
                ['key' => 'rentals', 'label' => $currentUser?->isVendor() ? 'Linked rentals' : 'Rentals', 'icon' => 'rentals', 'href' => $safeRoute('rentals.index'), 'active' => request()->routeIs('rentals.*'), 'visible' => (!$isDeliveryFacingMenuRole || $currentUser?->isVendor()) && ($currentUser?->canAccessModule('rentals', 'read') ?? false)],
                ['key' => 'sales', 'label' => 'Sales', 'icon' => 'sales', 'href' => $safeRoute('sales.index'), 'active' => request()->routeIs('sales.*'), 'visible' => $currentUser?->canAccessModule('sales', 'read') ?? false],
                ['key' => 'sales_workspace', 'label' => 'Sales workspace', 'icon' => 'sales', 'href' => $safeRoute('enquiries.index'), 'active' => request()->routeIs('enquiries.*', 'quotations.*'), 'visible' => !$isDeliveryFacingMenuRole && ($currentUser?->canAccessModule('enquiries', 'read') ?? false)],
                ['key' => 'customers', 'label' => 'Customers', 'icon' => 'customers', 'href' => $safeRoute('customers.index'), 'active' => request()->routeIs('customers.*'), 'visible' => !$isDeliveryFacingMenuRole && ($currentUser?->canAccessModule('customers', 'read') ?? false)],
                ['key' => 'business_partners', 'label' => 'Business Partners', 'icon' => 'customers', 'href' => $safeRoute('business-partners.index'), 'active' => request()->routeIs('business-partners.*'), 'visible' => !$isDeliveryFacingMenuRole && ($currentUser?->canAccessModule('customers', 'read') ?? false)],
                ['key' => 'cash_handovers', 'label' => 'Collections & cash', 'icon' => 'payments', 'href' => $safeRoute('cash-handovers.index'), 'active' => request()->routeIs('cash-handovers.*', 'delivery-collections.*'), 'visible' => ($currentUser?->canAccessModule('deliveries', 'update') ?? false) || ((in_array($currentUser?->effective_role, ['super_admin', 'admin_operations', 'finance', 'admin'], true) || ($currentUser?->hasPermission('cash.receive') ?? false)) && ($currentUser?->canAccessModule('payments', 'create') ?? false))],
                ['key' => 'rental_availability', 'label' => 'Rental availability', 'icon' => 'inventory', 'href' => $safeRoute('availability.index'), 'active' => request()->routeIs('availability.*'), 'visible' => !$isDeliveryFacingMenuRole && ($currentUser?->canAccessModule('rentals', 'read') ?? false)],
            ],
        ],
        [
            'label' => 'Operations',
            'items' => [
                ['key' => 'renewal_center', 'label' => 'Renewal Center', 'icon' => 'rentals', 'href' => $safeRoute('renewal-center.index'), 'active' => request()->routeIs('renewal-center.*'), 'visible' => !$isDeliveryFacingMenuRole && ($currentUser?->canAccessModule('rentals', 'read') ?? false)],
                ['key' => 'pickup_center', 'label' => 'Pickup Center', 'icon' => 'pickup', 'href' => $safeRoute('pickup-center.index'), 'active' => request()->routeIs('pickup-center.*'), 'visible' => !$currentUser?->isVendor() && ($currentUser?->canAccessModule('deliveries', 'read') ?? false)],
                ['key' => 'communication_center', 'label' => 'Communication Center', 'icon' => 'customers', 'href' => $safeRoute('communication-center.index'), 'active' => request()->routeIs('communication-center.*'), 'visible' => (!$isDeliveryFacingMenuRole || $currentUser?->isDelivery()) && !$currentUser?->isFinance() && ($currentUser?->canAccessAnyModule(['rentals', 'sales', 'customers', 'deliveries', 'invoices'], 'read') ?? false)],
                ['key' => 'delivery_asset_entry', 'label' => 'Add an asset', 'icon' => 'assets', 'href' => $safeRoute('deliveries.assets.create'), 'active' => request()->routeIs('deliveries.assets.*'), 'visible' => ($currentUser?->isDelivery() ?? false) && $currentUser->canAccessModule('deliveries', 'update')],
                ['key' => 'tasks_board', 'label' => 'Tasks Board', 'icon' => 'deliveries', 'href' => $safeRoute('deliveries.index'), 'active' => request()->routeIs('deliveries.*') || request()->routeIs('pickups.*'), 'visible' => $currentUser?->canAccessModule('deliveries', 'read') ?? false],
                ['key' => 'route_plan', 'label' => 'Route planner', 'icon' => 'deliveries', 'href' => $safeRoute('deliveries.route-plan'), 'active' => request()->routeIs('deliveries.route-plan'), 'visible' => $currentUser?->canPlanDeliveryRoutes() ?? false],
            ],
        ],
        [
            'label' => 'Inventory',
            'items' => [
                ['key' => 'inventory', 'label' => 'Inventory Overview', 'icon' => 'inventory', 'href' => $safeRoute('inventory.dashboard'), 'active' => request()->routeIs('inventory.dashboard'), 'visible' => $currentUser?->hasPermission('dashboard.inventory') ?? false],
                ['key' => 'products', 'label' => 'Product Master', 'icon' => 'products', 'href' => $safeRoute('products.index'), 'active' => request()->routeIs('products.*'), 'visible' => !$hideDeliveryInventoryLinks && ($currentUser?->canAccessModule('products', 'read') ?? false)],
                ['key' => 'product_categories', 'label' => 'Category Master', 'icon' => 'products', 'href' => $safeRoute('product-categories.index'), 'active' => request()->routeIs('product-categories.*'), 'visible' => !$hideDeliveryInventoryLinks && ($currentUser?->canAccessModule('products', 'read') ?? false)],
                ['key' => 'product_brands', 'label' => 'Brand Master', 'icon' => 'products', 'href' => $safeRoute('product-brands.index'), 'active' => request()->routeIs('product-brands.*'), 'visible' => !$hideDeliveryInventoryLinks && ($currentUser?->canAccessModule('products', 'read') ?? false)],
                ['key' => 'assets', 'label' => 'Asset Register', 'icon' => 'assets', 'href' => $safeRoute('assets.index'), 'active' => request()->routeIs('assets.*') && !request()->routeIs('assets.pending-verification') && !request()->routeIs('assets.verify-return') && !request()->routeIs('assets.verify-return.store'), 'visible' => !$hideDeliveryInventoryLinks && ($currentUser?->canAccessModule('assets', 'read') ?? false)],
                ['key' => 'return_verification', 'label' => 'Return Verification', 'icon' => 'assets', 'href' => $safeRoute('assets.pending-verification'), 'active' => request()->routeIs('assets.pending-verification') || request()->routeIs('assets.verify-return') || request()->routeIs('assets.verify-return.store'), 'visible' => $currentUser?->canAccessModule('assets', 'read') ?? false],
                ['key' => 'warehouses', 'label' => 'Warehouses', 'icon' => 'warehouses', 'href' => $safeRoute('warehouses.index'), 'active' => request()->routeIs('warehouses.*'), 'visible' => $currentUser?->canAccessModule('warehouses', 'read') ?? false],
                ['key' => 'stock_history', 'label' => 'Stock History', 'icon' => 'inventory', 'href' => $safeRoute('stock-history.index'), 'active' => request()->routeIs('stock-history.*'), 'visible' => $currentUser?->hasPermission('stock_history.view') ?? false],
                ['key' => 'inventory_intelligence', 'label' => 'Inventory Intelligence', 'icon' => 'inventory', 'href' => $safeRoute('inventory-intelligence.index'), 'active' => request()->routeIs('inventory-intelligence.*'), 'visible' => $currentUser?->hasPermission('stock_history.view') ?? false],
            ],
        ],
        [
            'label' => 'Finance',
            'items' => [
                ['key' => 'ledger', 'label' => 'Ledger', 'icon' => 'invoices', 'href' => $safeRoute('ledger.index'), 'active' => request()->routeIs('ledger.*'), 'visible' => ($currentUser?->isSuperAdmin() ?? false) || ($currentUser?->canViewFinance() ?? false)],
                ['key' => 'invoices', 'label' => 'Invoices', 'icon' => 'invoices', 'href' => $safeRoute('invoices.index'), 'active' => request()->routeIs('invoices.*'), 'visible' => $currentUser?->canAccessModule('invoices', 'read') ?? false],
                ['key' => 'payments', 'label' => 'Payments', 'icon' => 'invoices', 'href' => $paymentsIndexHref, 'active' => request()->routeIs('payments.*'), 'visible' => !empty($paymentsIndexHref)],
                ['key' => 'deposits', 'label' => 'Deposits', 'icon' => 'invoices', 'href' => $depositsHref, 'active' => request()->routeIs('deposits.*'), 'visible' => !empty($depositsHref)],
            ],
        ],
        [
            'label' => 'Team',
            'items' => [
                ['label' => 'Staff', 'icon' => 'users', 'href' => $safeRoute('staff.index'), 'active' => request()->routeIs('staff.*'), 'visible' => false],
                ['label' => 'Users', 'icon' => 'users', 'href' => $safeRoute('users.index'), 'active' => request()->routeIs('users.*'), 'visible' => $currentUser?->canAccessModule('users', 'read') ?? false],
                ['label' => 'Roles & Permissions', 'icon' => 'roles', 'href' => $safeRoute('roles.index'), 'active' => request()->routeIs('roles.*'), 'visible' => $currentUser?->canAccessModule('roles', 'read') ?? false],
            ],
        ],
        [
            'label' => 'Analytics',
            'items' => [
                ['label' => 'Reports', 'icon' => 'invoices', 'href' => $reportsIndexHref, 'active' => request()->routeIs('reports.*'), 'visible' => !empty($reportsIndexHref)],
                ['label' => 'Staff incentives', 'icon' => 'users', 'href' => $safeRoute('reports.staff-incentives'), 'active' => request()->routeIs('reports.staff-incentives'), 'visible' => $currentUser?->canViewStaffIncentives() ?? false],
                ['label' => 'Vendor Orders', 'icon' => 'vendors', 'href' => $safeRoute('vendor-orders.index'), 'active' => request()->routeIs('vendor-orders.*'), 'visible' => $currentUser?->hasPermission('vendor_reports.view') ?? false],
            ],
        ],
        [
            'label' => 'More',
            'items' => [
                ['label' => 'Cities', 'icon' => 'cities', 'href' => $safeRoute('cities.index'), 'active' => request()->routeIs('cities.*'), 'visible' => $currentUser?->canAccessModule('cities', 'read') ?? false],
                ['label' => 'Vendors', 'icon' => 'vendors', 'href' => $safeRoute('vendors.index'), 'active' => request()->routeIs('vendors.*'), 'visible' => $currentUser?->canAccessModule('vendors', 'read') ?? false],
                ['label' => 'Referral Sources', 'icon' => 'customers', 'href' => $safeRoute('referral-sources.index'), 'active' => request()->routeIs('referral-sources.*'), 'visible' => ($currentUser?->canAccessModule('vendors', 'read') ?? false) || ($currentUser?->canAccessModule('settings', 'read') ?? false)],
            ],
        ],
        [
            'label' => 'Help',
            'items' => [
                ['key' => 'knowledge_hub', 'label' => 'Knowledge Hub', 'icon' => 'knowledge', 'href' => $knowledgeHubHref, 'active' => request()->routeIs('knowledge.*', 'workflow-guides.*'), 'visible' => true],
            ],
        ],
    ];

    $dashboardSettingsHref = ($currentUser?->canAccessModule('settings', 'read') ?? false) ? $safeRoute('organization.dashboard-settings.edit') : null;

    $organizationItems = [
        ['label' => 'Company Profile', 'icon' => 'settings', 'href' => $companyHref, 'active' => request()->routeIs('organization.settings.*'), 'visible' => !empty($companyHref)],
        ['label' => 'Dashboard Settings', 'icon' => 'dashboard', 'href' => $dashboardSettingsHref, 'active' => request()->routeIs('organization.dashboard-settings.*'), 'visible' => !empty($dashboardSettingsHref)],
        ['label' => 'Preferences', 'icon' => 'settings', 'href' => $preferencesHref, 'active' => request()->routeIs('organization.settings.*'), 'visible' => false],
        ['label' => 'Data Import', 'icon' => 'products', 'href' => $safeRoute('imports.index'), 'active' => request()->routeIs('imports.*'), 'visible' => $currentUser?->isSuperAdmin() ?? false],
    ];

    $organizationItems = collect($organizationItems)->map(function ($item) use ($iconSemanticClass) {
        $item['icon_class'] = $iconSemanticClass($item['icon'] ?? null, $item['key'] ?? null, $item['label'] ?? null);
        return $item;
    })->all();

    $organizationMenuOpen = collect($organizationItems)->contains(fn ($item) => !empty($item['active']));
    $visibleSidebarSections = collect($sidebarSections)->map(function ($section) {
        $section['items'] = collect($section['items'])->filter(fn ($item) => !empty($item['visible']) && !empty($item['href']))->values()->all();
        return $section;
    })->filter(fn ($section) => !empty($section['items']))->values();

    $visibleSidebarSections = $visibleSidebarSections->map(function ($section) use ($iconSemanticClass) {
        $section['items'] = collect($section['items'])->map(function ($item) use ($iconSemanticClass) {
            $item['icon_class'] = $iconSemanticClass($item['icon'] ?? null, $item['key'] ?? null, $item['label'] ?? null);
            return $item;
        })->all();

        return $section;
    });

    $mobilePrimaryItems = $isDeliveryFacingMenuRole
        ? collect([
            ['label' => 'Dashboard', 'icon' => 'dashboard', 'href' => ($currentUser?->canAccessDashboard() ?? false) ? $safeRoute('dashboard') : null, 'active' => request()->routeIs('dashboard', 'dashboard.compact')],
            ['label' => 'Tasks', 'icon' => 'deliveries', 'href' => ($currentUser?->canAccessModule('deliveries', 'read') ?? false) ? $safeRoute('deliveries.index') : null, 'active' => request()->routeIs('deliveries.index') || request()->routeIs('deliveries.show') || request()->routeIs('deliveries.edit')],
            ['label' => 'Notifications', 'icon' => 'invoices', 'href' => $topbarNotificationsViewAllHref, 'active' => request()->routeIs('notifications.*')],
        ])
        : collect([
            ['label' => 'Dashboard', 'icon' => 'dashboard', 'href' => ($currentUser?->canAccessDashboard() ?? false) ? $safeRoute('dashboard') : null, 'active' => request()->routeIs('dashboard', 'dashboard.compact')],
            ['label' => 'Customers', 'icon' => 'customers', 'href' => (!$isDeliveryFacingMenuRole && ($currentUser?->canAccessModule('customers', 'read') ?? false)) ? $safeRoute('customers.index') : null, 'active' => request()->routeIs('customers.*')],
            ['label' => 'Rentals', 'icon' => 'rentals', 'href' => (!$isDeliveryFacingMenuRole && ($currentUser?->canAccessModule('rentals', 'read') ?? false)) ? $safeRoute('rentals.index') : null, 'active' => request()->routeIs('rentals.*')],
            ['label' => 'Sales', 'icon' => 'sales', 'href' => ($currentUser?->canAccessModule('sales', 'read') ?? false) ? $safeRoute('sales.index') : null, 'active' => request()->routeIs('sales.*')],
        ]);

    $mobilePrimaryItems = $mobilePrimaryItems
        ->map(function ($item) use ($iconSemanticClass) {
            $item['icon_class'] = $iconSemanticClass($item['icon'] ?? null, $item['key'] ?? null, $item['label'] ?? null);
            return $item;
        })
        ->filter(fn ($item) => !empty($item['href']))
        ->unique('label')
        ->values();

    $quickAddItems = collect([
        ['label' => 'New Customer', 'icon' => 'customers', 'href' => ($currentUser?->canAccessModule('customers', 'create') ?? false) ? $safeRoute('customers.create') : null],
        ['label' => 'New Rental', 'icon' => 'rentals', 'href' => ($currentUser?->canAccessModule('rentals', 'create') ?? false) ? $safeRoute('rentals.create') : null],
        ['label' => 'New Sale', 'icon' => 'sales', 'href' => ($currentUser?->canAccessModule('sales', 'create') ?? false) ? $safeRoute('sales.create') : null],
        ['label' => 'New Invoice', 'icon' => 'invoices', 'href' => ($currentUser?->canAccessModule('invoices', 'create') ?? false) ? $safeRoute('invoices.create') : null],
        ['label' => 'New Product', 'icon' => 'products', 'href' => ($currentUser?->canAccessModule('products', 'create') ?? false) ? $safeRoute('products.create') : null],
    ])->filter(fn ($item) => !empty($item['href']))->map(function ($item) use ($iconSemanticClass) {
        $item['icon_class'] = $iconSemanticClass($item['icon'] ?? null, $item['key'] ?? null, $item['label'] ?? null);
        return $item;
    })->values();

    $mobileMoreItems = $visibleSidebarSections
        ->flatMap(fn ($section) => $section['items'])
        ->merge(collect($organizationItems)->filter(fn ($item) => !empty($item['visible']) && !empty($item['href'])))
        ->map(function ($item) use ($iconSemanticClass) {
            $item['icon_class'] = $item['icon_class'] ?? $iconSemanticClass($item['icon'] ?? null, $item['key'] ?? null, $item['label'] ?? null);
            return $item;
        })
        ->filter(fn ($item) => !empty($item['href']))
        ->reject(fn ($item) => in_array($item['label'], ['Dashboard', 'Rentals', 'Sales', 'Customers', 'Tasks', 'Pickups', 'Notifications'], true))
        ->values()
        ->all();

    $navIcon = function (string $icon): string {
        $attrs = 'width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"';

        return match ($icon) {
            'dashboard' => '<svg '.$attrs.'><path d="M4 13h6V4H4v9Z"/><path d="M14 20h6V4h-6v16Z"/><path d="M4 20h6v-3H4v3Z"/></svg>',
            'inventory' => '<svg '.$attrs.'><path d="M21 8 12 3 3 8l9 5 9-5Z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/></svg>',
            'rentals' => '<svg '.$attrs.'><path d="M7 3v4"/><path d="M17 3v4"/><path d="M4 8h16"/><path d="M5 5h14a1 1 0 0 1 1 1v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a1 1 0 0 1 1-1Z"/><path d="M8 12h4"/><path d="M8 16h8"/></svg>',
            'sales' => '<svg '.$attrs.'><path d="M6 6h15l-2 8H8L6 6Z"/><path d="M6 6 5 3H2"/><path d="M9 20a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z"/><path d="M18 20a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z"/></svg>',
            'customers', 'users' => '<svg '.$attrs.'><path d="M16 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"/><path d="M9.5 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
            'products' => '<svg '.$attrs.'><path d="M20.5 7.5 12 3 3.5 7.5 12 12l8.5-4.5Z"/><path d="M3.5 7.5V16L12 21l8.5-5V7.5"/><path d="M12 12v9"/></svg>',
            'assets' => '<svg '.$attrs.'><path d="M20 12v7a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-7"/><path d="M2 7h20v5H2z"/><path d="M12 7v14"/><path d="M12 7H7.5a2.5 2.5 0 1 1 0-5C11 2 12 7 12 7Z"/><path d="M12 7h4.5a2.5 2.5 0 1 0 0-5C13 2 12 7 12 7Z"/></svg>',
            'deliveries' => '<svg '.$attrs.'><path d="M3 7h11v9H3z"/><path d="M14 10h4l3 3v3h-7z"/><path d="M7 20a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z"/><path d="M18 20a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z"/></svg>',
            'pickup' => '<svg '.$attrs.'><path d="M12 3v11"/><path d="m8 10 4 4 4-4"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/></svg>',
            'invoices' => '<svg '.$attrs.'><path d="M6 2h12v20l-3-2-3 2-3-2-3 2V2Z"/><path d="M9 8h6"/><path d="M9 12h6"/><path d="M9 16h3"/></svg>',
            'roles' => '<svg '.$attrs.'><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-5"/></svg>',
            'cities' => '<svg '.$attrs.'><path d="M12 21s7-5.1 7-11a7 7 0 1 0-14 0c0 5.9 7 11 7 11Z"/><path d="M12 10.5a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z"/></svg>',
            'warehouses' => '<svg '.$attrs.'><path d="M3 21h18"/><path d="M4 21V8l8-5 8 5v13"/><path d="M9 21v-8h6v8"/><path d="M7 10h10"/></svg>',
            'vendors' => '<svg '.$attrs.'><path d="M8 12h8"/><path d="M7 7h.01"/><path d="M17 7h.01"/><path d="M5 4h14a2 2 0 0 1 2 2v8a5 5 0 0 1-5 5H8a5 5 0 0 1-5-5V6a2 2 0 0 1 2-2Z"/></svg>',
            'settings' => '<svg '.$attrs.'><path d="M12 15.5A3.5 3.5 0 1 0 12 8a3.5 3.5 0 0 0 0 7.5Z"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06A1.7 1.7 0 0 0 15 19.4a1.7 1.7 0 0 0-1 .6 1.7 1.7 0 0 0-.4 1.1V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-.4-1.1 1.7 1.7 0 0 0-1-.6 1.7 1.7 0 0 0-1.88.34l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-.6-1 1.7 1.7 0 0 0-1.1-.4H2.8a2 2 0 1 1 0-4h.1A1.7 1.7 0 0 0 4 9.2a1.7 1.7 0 0 0 .6-1 1.7 1.7 0 0 0-.34-1.88l-.06-.06A2 2 0 1 1 7.03 3.4l.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-.6 1.7 1.7 0 0 0 .4-1.1V2.8a2 2 0 1 1 4 0v.1A1.7 1.7 0 0 0 15 4a1.7 1.7 0 0 0 1 .6 1.7 1.7 0 0 0 1.88-.34l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.7 1.7 0 0 0 19.4 9c.2.34.33.72.4 1.1h.1a2 2 0 1 1 0 4h-.1c-.07.38-.2.76-.4 1Z"/></svg>',
            'profile' => '<svg '.$attrs.'><path d="M20 21a8 8 0 1 0-16 0"/><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"/></svg>',
            'logout' => '<svg '.$attrs.'><path d="M10 17 15 12l-5-5"/><path d="M15 12H3"/><path d="M21 3v18"/></svg>',
            default => '<svg '.$attrs.'><path d="M12 5v14"/><path d="M5 12h14"/></svg>',
        };
    };

    $routeName = request()->route()?->getName();
    $routeParts = $routeName ? explode('.', $routeName) : [];
    $routeModule = $routeParts[0] ?? null;
    $routeAction = $routeParts[1] ?? null;
    $isDashboardRoute = request()->routeIs('dashboard', 'dashboard.compact');
    $moduleLabels = [
        'assets' => 'Asset Register',
        'cities' => 'Cities',
        'customers' => 'Customers',
        'deliveries' => 'Deliveries',
        'invoices' => 'Invoices',
        'payments' => 'Payments',
        'products' => 'Product Master',
        'product-categories' => 'Category Master',
        'product-brands' => 'Brand Master',
        'knowledge' => 'Knowledge Hub',
        'rentals' => 'Rentals',
        'reports' => 'Reports',
        'roles' => 'Roles',
        'sales' => 'Sales',
        'staff' => 'Staff',
        'users' => 'Users',
        'vendors' => 'Vendors',
        'warehouses' => 'Warehouses',
    ];
    $singularLabels = [
        'assets' => 'Asset',
        'cities' => 'City',
        'customers' => 'Customer',
        'deliveries' => 'Delivery',
        'invoices' => 'Invoice',
        'payments' => 'Payment',
        'products' => 'Product',
        'rentals' => 'Rental',
        'reports' => 'Report',
        'roles' => 'Role',
        'sales' => 'Sale',
        'staff' => 'Staff',
        'users' => 'User',
        'vendors' => 'Vendor',
        'warehouses' => 'Warehouse',
    ];
    $adminModules = ['users', 'roles', 'cities', 'warehouses', 'vendors'];
    $breadcrumbItems = [];
    $dashboardHref = ($currentUser?->canAccessDashboard() ?? false)
        ? route('dashboard')
        : ($visibleSidebarSections->flatMap(fn ($section) => $section['items'])->first()['href'] ?? url('/'));

    if ($routeName && !in_array($routeName, ['dashboard', 'dashboard.compact', 'login', 'register'], true)) {
        $breadcrumbItems[] = ['label' => 'Home', 'href' => $dashboardHref];
    }

    if ($isDashboardRoute) {
        $breadcrumbItems[] = ['label' => 'Dashboard', 'href' => null];
    } elseif ($routeName === 'inventory.dashboard') {
        $breadcrumbItems[] = ['label' => 'Inventory Overview', 'href' => null];
    } elseif ($routeName === 'profile.edit') {
        $breadcrumbItems[] = ['label' => 'Profile', 'href' => null];
    } elseif (str_starts_with((string) $routeName, 'organization.settings.')) {
        $breadcrumbItems[] = ['label' => 'Company Settings', 'href' => null];
        $breadcrumbItems[] = ['label' => 'Settings', 'href' => null];
    } elseif ($routeName === 'deliveries.assigned') {
        $breadcrumbItems[] = ['label' => 'Tasks Board', 'href' => route('deliveries.index')];
        $breadcrumbItems[] = ['label' => 'My Deliveries', 'href' => null];
    } elseif ($routeName === 'pickups.assigned') {
        $breadcrumbItems[] = ['label' => 'Tasks Board', 'href' => route('deliveries.index', ['tab' => 'pickups', 'task_type' => 'pickup'])];
        $breadcrumbItems[] = ['label' => 'My Pickups', 'href' => null];
    } elseif ($routeModule && isset($moduleLabels[$routeModule])) {
        if (in_array($routeModule, $adminModules, true)) {
            $breadcrumbItems[] = ['label' => 'Company Settings', 'href' => null];
        }

        $indexRoute = $routeModule . '.index';
        $moduleHref = \Illuminate\Support\Facades\Route::has($indexRoute) ? route($indexRoute) : null;
        $breadcrumbItems[] = [
            'label' => $moduleLabels[$routeModule],
            'href' => $routeAction && $routeAction !== 'index' ? $moduleHref : null,
        ];

        if ($routeAction && $routeAction !== 'index') {
            $routeParameters = request()->route()?->parameters() ?? [];
            $firstParameter = collect($routeParameters)->first();
            $resourceId = is_object($firstParameter) && isset($firstParameter->id)
                ? $firstParameter->id
                : (is_scalar($firstParameter) ? $firstParameter : null);
            $resourceLabel = $resourceId ? ' #' . $resourceId : '';
            $currentLabel = match ($routeAction) {
                'create' => 'Create ' . ($singularLabels[$routeModule] ?? 'Record'),
                'edit' => 'Edit ' . ($singularLabels[$routeModule] ?? 'Record') . $resourceLabel,
                'show' => ($singularLabels[$routeModule] ?? 'Record') . $resourceLabel,
                'transfer' => 'Transfer ' . ($singularLabels[$routeModule] ?? 'Record') . $resourceLabel,
                'payments' => 'Payments',
                'pending-verification' => 'Return Verification',
                'verify-return' => 'Verify Return',
                default => ucwords(str_replace(['-', '_'], ' ', $routeAction)),
            };
            $breadcrumbItems[] = ['label' => $currentLabel, 'href' => null];
        }
    }
@endphp


<style id="phos-global-navigation-styles">
    /* Shared navigation only. Each module keeps its existing content palette and layout. */
    body.phos-navigation-refresh {
        --ph-nav-surface:#ffffff;
        --ph-nav-text:#31485c;
        --ph-nav-muted:#627589;
        --ph-nav-border:#dce5ec;
        --ph-nav-link:#0b5e99;
        --ph-nav-blue:#0f75bd;
        --ph-nav-soft:#f3f7fa;
        --ph-nav-selected:#eaf3fb;
        --ph-nav-badge:#9e1423;
    }
    body.phos-navigation-refresh .app-shell {
        --ph-sidebar-expanded-width:228px;
        --ph-sidebar-collapsed-width:68px;
    }
    body.phos-navigation-refresh .app-shell-sidebar.rn-sidebar {
        background:var(--ph-nav-surface) !important;
        color:var(--ph-nav-text);
        border-right:1px solid var(--ph-nav-border);
        box-shadow:none;
        padding:10px 9px;
    }
    body.phos-navigation-refresh .app-shell-sidebar.rn-sidebar::before { background:none; }
    body.phos-navigation-refresh .app-shell-sidebar .sidebar-scroll {
        padding-right:5px;
        scrollbar-color:#c4d1dc transparent;
    }
    body.phos-navigation-refresh .app-shell-sidebar .sidebar-scroll::-webkit-scrollbar-thumb { background:#c4d1dc; }
    body.phos-navigation-refresh .app-shell-sidebar .sidebar-scroll::-webkit-scrollbar-track { background:transparent; }
    body.phos-navigation-refresh .app-shell-sidebar .sidebar-section {
        border-color:#e8eef3;
        padding-top:8px;
        margin-top:8px;
    }
    body.phos-navigation-refresh .app-shell-sidebar .sidebar-section-toggle,
    body.phos-navigation-refresh .app-shell-sidebar .sidebar-section-toggle svg { color:var(--ph-nav-muted); }
    body.phos-navigation-refresh .app-shell-sidebar .sidebar-section-title,
    body.phos-navigation-refresh .app-shell-sidebar .sidebar-section-toggle span {
        color:var(--ph-nav-muted) !important;
        font-size:10px;
        font-weight:600;
        letter-spacing:.09em;
    }
    body.phos-navigation-refresh .app-shell-sidebar .sidebar-section-toggle:hover { background:var(--ph-nav-soft); }
    body.phos-navigation-refresh .app-shell-sidebar .sidebar-link {
        min-height:37px;
        padding:8px 9px;
        gap:8px;
        color:var(--ph-nav-text);
        background:transparent;
        border:1px solid transparent;
        border-radius:7px;
        box-shadow:none;
    }
    body.phos-navigation-refresh .app-shell-sidebar .sidebar-link .sidebar-label {
        font-size:12px;
        font-weight:500;
    }
    body.phos-navigation-refresh .app-shell-sidebar .sidebar-link:hover {
        color:var(--ph-nav-link);
        background:var(--ph-nav-soft);
        border-color:transparent;
        box-shadow:none;
    }
    body.phos-navigation-refresh .app-shell-sidebar .sidebar-link.is-active,
    body.phos-navigation-refresh .app-shell-sidebar .sidebar-link.is-admin-active,
    body.phos-navigation-refresh .app-shell-sidebar .sidebar-link.is-secondary-active {
        color:var(--ph-nav-link) !important;
        background:var(--ph-nav-selected) !important;
        border-color:var(--ph-nav-selected) !important;
        min-height:37px;
        box-shadow:none;
    }
    body.phos-navigation-refresh .app-shell-sidebar .sidebar-link.is-active .sidebar-label,
    body.phos-navigation-refresh .app-shell-sidebar .sidebar-link.is-admin-active .sidebar-label,
    body.phos-navigation-refresh .app-shell-sidebar .sidebar-link.is-secondary-active .sidebar-label {
        color:var(--ph-nav-link) !important;
        font-weight:600;
    }
    body.phos-navigation-refresh .app-shell-sidebar .sidebar-link.is-active::before,
    body.phos-navigation-refresh .app-shell-sidebar .sidebar-link.is-admin-active::before,
    body.phos-navigation-refresh .app-shell-sidebar .sidebar-link.is-secondary-active::before { display:none; }
    body.phos-navigation-refresh .app-shell-sidebar .sidebar-icon,
    body.phos-navigation-refresh .app-shell-sidebar .sidebar-link:hover .sidebar-icon {
        color:var(--ph-nav-muted);
        background:transparent;
        border-color:transparent;
        box-shadow:none;
        width:20px;
        height:20px;
        flex-basis:20px;
    }
    body.phos-navigation-refresh .app-shell-sidebar .sidebar-icon svg { width:16px; height:16px; }
    body.phos-navigation-refresh .app-shell-sidebar .sidebar-link.is-active .sidebar-icon,
    body.phos-navigation-refresh .app-shell-sidebar .sidebar-link.is-admin-active .sidebar-icon,
    body.phos-navigation-refresh .app-shell-sidebar .sidebar-link.is-secondary-active .sidebar-icon {
        color:var(--ph-nav-blue) !important;
        background:transparent !important;
        border-color:transparent !important;
    }
    body.phos-navigation-refresh .app-shell-sidebar .sidebar-link-badge {
        min-width:22px;
        height:19px;
        padding:1px 5px;
        font-size:10px;
        font-weight:600;
        color:var(--ph-nav-badge);
        background:#f9ecee;
        border:0;
        box-shadow:none;
    }
    body.phos-navigation-refresh .app-shell-sidebar .sidebar-link.is-active .sidebar-link-badge {
        color:var(--ph-nav-badge);
        background:#f9ecee;
    }
    body.phos-navigation-refresh .app-shell-sidebar .sidebar-shell-toggle {
        color:#526a7d;
        background:var(--ph-nav-surface);
        border-color:var(--ph-nav-border);
        box-shadow:none;
    }
    body.phos-navigation-refresh .app-shell-sidebar .sidebar-shell-toggle:hover {
        color:var(--ph-nav-link);
        background:var(--ph-nav-soft);
        border-color:#cbd8e2;
    }
    body.phos-navigation-refresh .app-shell-topbar {
        background:var(--ph-nav-surface);
        border-color:var(--ph-nav-border);
        box-shadow:none;
    }
    body.phos-navigation-refresh :is(.shell-brand-pill, .topbar-chip.is-role) {
        color:var(--ph-nav-link);
        background:#edf5fc;
        border-color:#d2e5f4;
    }
    body.phos-navigation-refresh .app-shell-sidebar :is(a, button):focus-visible {
        outline:2px solid var(--ph-nav-blue);
        outline-offset:3px;
    }
    @media (max-width:1024px) {
        body.phos-navigation-refresh .mobile-bottom-nav .is-active {
            color:var(--ph-nav-link);
            background:var(--ph-nav-selected);
        }
    }
    @media (prefers-reduced-motion:reduce) {
        body.phos-navigation-refresh .app-shell-sidebar * { scroll-behavior:auto !important; transition:none !important; }
    }
</style>

<div class="app-shell rn-shell" data-sidebar-shell>
    @include('partials.mobile-topbar', ['breadcrumbItems' => $breadcrumbItems ?? [], 'currentUser' => $currentUser])

    <aside class="app-shell-sidebar rn-sidebar" aria-label="Primary navigation">
        <div class="brand-panel">
            <div class="brand-mark">
                <span class="brand-badge" aria-hidden="true">
                    <x-application-logo class="brand-logo" />
                </span>
                <div class="brand-copy">
                    <div class="brand-title-lockup">
                        <div class="brand-title">Prime Healers</div>
                        <span class="brand-title-tag">OS</span>
                    </div>
                    <div class="brand-subtitle">Rental, sales, dispatch &amp; care operations</div>
                </div>
            </div>
            <button
                type="button"
                class="sidebar-shell-toggle"
                data-sidebar-shell-toggle-legacy
                aria-label="Collapse sidebar"
                aria-pressed="false"
                title="Collapse sidebar"
            >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="m15 18-6-6 6-6"></path>
                </svg>
                <span class="sidebar-shell-toggle-label">Collapse</span>
            </button>
        </div>

        <div class="sidebar-scroll">
            @foreach($visibleSidebarSections as $section)
                @php
                    $groupKey = \Illuminate\Support\Str::slug($section['label']);
                    $groupHasActive = collect($section['items'])->contains(fn ($item) => !empty($item['active']));
                    $groupDefaultOpen = $section['label'] === 'Main' || $groupHasActive;
                @endphp
                <section
                    class="sidebar-section sidebar-group {{ $groupDefaultOpen ? 'is-expanded' : 'is-collapsed' }}"
                    data-sidebar-group="{{ $groupKey }}"
                    data-default-open="{{ $groupDefaultOpen ? 'true' : 'false' }}"
                    data-has-active="{{ $groupHasActive ? 'true' : 'false' }}"
                >
                    <button
                        type="button"
                        class="sidebar-section-toggle"
                        data-sidebar-toggle="{{ $groupKey }}"
                        aria-expanded="{{ $groupDefaultOpen ? 'true' : 'false' }}"
                        aria-controls="sidebar-panel-{{ $groupKey }}"
                    >
                        <span>{{ $section['label'] }}</span>
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                    </button>
                    <div
                        id="sidebar-panel-{{ $groupKey }}"
                        class="sidebar-panel"
                        data-sidebar-panel="{{ $groupKey }}"
                        @if(!$groupDefaultOpen) hidden @endif
                    >
                        <nav class="sidebar-nav">
                            @foreach($section['items'] as $item)
                                @php
                                    $sidebarBadge = isset($item['key'])
                                        ? $formatSidebarBadge($sidebarPendingCounts[$item['key']] ?? 0)
                                        : null;
                                @endphp
                                <a href="{{ $item['href'] }}"
                                   class="sidebar-link rn-sidebar-item {{ !empty($item['active']) ? 'is-active' : '' }}"
                                   data-sidebar-link
                                   data-sidebar-tooltip="{{ $item['label'] }}"
                                   aria-label="{{ $item['label'] }}">
                                    <span class="sidebar-icon icon-chip {{ $item['icon_class'] ?? 'icon-admin' }}">{!! $navIcon($item['icon'] ?? 'default') !!}</span>
                                    <span class="sidebar-label">{{ $item['label'] }}</span>
                                    @if($sidebarBadge)
                                        <span class="sidebar-link-badge">{{ $sidebarBadge }}</span>
                                    @endif
                                </a>
                            @endforeach
                        </nav>
                    </div>
                </section>
            @endforeach
        @if(collect($organizationItems)->contains(fn ($item) => !empty($item['visible']) && !empty($item['href'])))
            <section
                class="sidebar-section sidebar-group {{ $organizationMenuOpen ? 'is-expanded' : 'is-collapsed' }}"
                data-sidebar-group="organization-settings"
                data-default-open="{{ $organizationMenuOpen ? 'true' : 'false' }}"
                data-has-active="{{ $organizationMenuOpen ? 'true' : 'false' }}"
            >
                <button
                    type="button"
                    class="sidebar-section-toggle"
                    data-sidebar-toggle="organization-settings"
                    aria-expanded="{{ $organizationMenuOpen ? 'true' : 'false' }}"
                    aria-controls="sidebar-panel-organization-settings"
                >
                    <span>Company Settings</span>
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                </button>
                <div
                    id="sidebar-panel-organization-settings"
                    class="sidebar-panel"
                    data-sidebar-panel="organization-settings"
                    @if(!$organizationMenuOpen) hidden @endif
                >
                    <nav class="sidebar-nav">
                        @foreach($organizationItems as $item)
                            @continue(empty($item['visible']) || empty($item['href']))
                            <a href="{{ $item['href'] }}"
                               class="sidebar-link rn-sidebar-item {{ !empty($item['active']) ? 'is-admin-active is-secondary-active' : '' }}"
                               data-sidebar-link
                               data-sidebar-tooltip="{{ $item['label'] }}"
                               aria-label="{{ $item['label'] }}">
                                <span class="sidebar-icon icon-chip {{ $item['icon_class'] ?? 'icon-admin' }}">{!! $navIcon($item['icon'] ?? 'default') !!}</span>
                                <span class="sidebar-label">{{ $item['label'] }}</span>
                            </a>
                        @endforeach
                    </nav>
                </div>
            </section>
        @endif
        </div>

    </aside>

    <main class="app-shell-main{{ View::hasSection('focused_form') ? ' is-focused-form' : '' }}">
        <header class="app-shell-topbar">
            <div class="app-shell-topbar-left">
                <div class="desktop-header-brand-wrap">
                    <a href="{{ $safeRoute('dashboard') ?: url('/') }}" class="desktop-header-brand" aria-label="Prime Healers OS home">
                        <span class="shell-brand-logo-wrap" aria-hidden="true">
                            <x-application-logo class="shell-brand-logo" />
                        </span>
                        <span class="shell-brand-divider" aria-hidden="true"></span>
                        <span class="shell-brand-pill">OS</span>
                        <span class="desktop-header-meta">
                            <span>Operations Suite</span>
                        </span>
                    </a>
                    <button
                        type="button"
                        class="sidebar-shell-toggle desktop-header-sidebar-toggle"
                        data-sidebar-shell-toggle
                        aria-label="Collapse sidebar"
                        aria-pressed="false"
                        title="Collapse sidebar"
                    >
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="m15 18-6-6 6-6"></path>
                        </svg>
                        <span class="sidebar-shell-toggle-label">Collapse</span>
                    </button>
                </div>
                <form class="app-shell-search" role="search" aria-label="Universal search shell" method="GET" action="{{ $globalSearchHref ?: url('/search') }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path></svg>
                    <input type="text" name="q" value="{{ $globalSearchValue }}" placeholder="Search customers, rentals, invoices, serial no..." autocomplete="off" spellcheck="false" aria-label="Search customers, rentals, invoices, serial no" data-predictive-search data-predictive-type="all" />
                </form>
            </div>

            <div class="app-shell-topbar-right">
                @if(!empty($quickAddItems) && $quickAddItems->isNotEmpty())
                    <details class="quick-add-menu">
                        <summary class="topbar-chip rn-btn rn-btn-primary quick-add-trigger">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14"></path><path d="M5 12h14"></path></svg>
                            <span>Quick Add</span>
                        </summary>
                        <div class="quick-add-panel">
                            @foreach($quickAddItems as $item)
                                <a href="{{ $item['href'] }}" class="quick-add-link">
                                    <span class="quick-add-icon icon-chip {{ $item['icon_class'] ?? 'icon-admin' }}">{!! $navIcon($item['icon'] ?? 'default') !!}</span>
                                    <span>{{ $item['label'] }}</span>
                                </a>
                            @endforeach
                        </div>
                    </details>
                @endif
                @if(!empty($userRoleLabel))
                    <span class="topbar-chip is-role rn-badge">{{ $userRoleLabel }}</span>
                @endif
                @if(!empty($knowledgeHubHref))
                    <a
                        href="{{ $knowledgeHubHref }}"
                        class="topbar-action-link icon-ink icon-knowledge {{ request()->routeIs('knowledge.*', 'workflow-guides.*') ? 'is-active' : '' }}"
                        title="Knowledge Hub"
                        aria-label="Knowledge Hub"
                    >
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v16.5a1.5 1.5 0 0 0-1.5-1.5H6.5A2.5 2.5 0 0 0 4 20.5v-15Z"></path>
                            <path d="M8 7h8"></path>
                            <path d="M8 11h8"></path>
                            <path d="M8 15h5"></path>
                        </svg>
                    </a>
                @endif
                <details
                    class="topbar-notification-menu topbar-bell-menu"
                    data-in-app-notifications
                    @if($topbarNotificationsLatestHref) data-notifications-latest-url="{{ $topbarNotificationsLatestHref }}" @endif
                    @if($topbarNotificationsUnreadCountHref) data-notifications-count-url="{{ $topbarNotificationsUnreadCountHref }}" @endif
                    @if($topbarNotificationsReadAllHref) data-notifications-read-all-url="{{ $topbarNotificationsReadAllHref }}" @endif
                    @if($topbarNotificationsReadVisibleHref) data-notifications-read-visible-url="{{ $topbarNotificationsReadVisibleHref }}" @endif
                    @if($topbarNotificationsReadHrefTemplate) data-notifications-read-url-template="{{ $topbarNotificationsReadHrefTemplate }}" @endif
                    @if($topbarNotificationsPreferencesHref) data-notifications-preferences-url="{{ $topbarNotificationsPreferencesHref }}" @endif
                    data-notification-sound-enabled="{{ $notificationSoundEnabled ? 'true' : 'false' }}"
                    data-notification-voice-enabled="{{ $notificationVoiceEnabled ? 'true' : 'false' }}"
                    data-notification-sound-variant="{{ $notificationSoundVariant }}"
                    data-notification-sound-src="{{ $notificationSoundAsset }}"
                >
                    <summary class="topbar-bell-trigger icon-ink {{ $topbarNotificationCount > 0 ? 'icon-alert' : 'icon-admin' }}" aria-label="Open notifications">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 17h5l-1.4-1.4A2 2 0 0 1 18 14.2V11a6 6 0 1 0-12 0v3.2a2 2 0 0 1-.6 1.4L4 17h5"/><path d="M10 21a2 2 0 0 0 4 0"/></svg>
                        @if($topbarNotificationCount > 0)
                            <span class="topbar-bell-badge" data-notification-badge>{{ $topbarNotificationCount > 99 ? '99+' : $topbarNotificationCount }}</span>
                        @endif
                    </summary>
                    <div class="topbar-bell-panel">
                        <div class="topbar-bell-head">
                            <div>
                                <strong>Notifications</strong>
                                <span data-notification-subtitle>{{ $topbarNotificationCount > 0 ? 'Live operational updates for you' : 'No unread notifications right now' }}</span>
                            </div>
                            <div class="topbar-bell-head-actions">
                                <button
                                    type="button"
                                    class="topbar-bell-mark-all"
                                    data-notification-mark-all
                                    @if($topbarNotificationCount <= 0) hidden @endif
                                >Mark all as read</button>
                                @if($topbarNotificationCount > 0)
                                    <span class="rn-badge rn-badge-warning" data-notification-head-count>{{ $topbarNotificationCount }}</span>
                                @endif
                            </div>
                        </div>
                        @if($topbarNotifications->isEmpty())
                            <div class="topbar-bell-empty" data-notification-empty>No unread notifications right now. New assignments and operational updates will appear here automatically.</div>
                        @else
                            <div class="topbar-bell-list" data-notification-list>
                                @foreach($topbarNotifications as $notification)
                                    @php($priority = $notification['priority'] ?? 'medium')
                                    <a
                                        href="{{ $notification['action_url'] ?? '#' }}"
                                        class="topbar-bell-item {{ !empty($notification['is_unread']) ? 'is-unread' : '' }}"
                                        data-notification-item
                                        data-notification-id="{{ $notification['id'] ?? '' }}"
                                        data-notification-priority="{{ $priority }}"
                                    >
                                        <div>
                                            <strong>{{ $notification['title'] ?? 'Operational update' }}</strong>
                                            <small>{{ $notification['message'] ?? 'Open for details.' }}</small>
                                            <div class="topbar-bell-meta">
                                                <span class="topbar-bell-priority {{ in_array($priority, ['high', 'urgent'], true) ? 'is-high' : ($priority === 'medium' ? 'is-medium' : '') }}">{{ strtoupper($priority) }}</span>
                                                <span class="topbar-bell-time">{{ $notification['time_ago'] ?? '' }}</span>
                                            </div>
                                        </div>
                                        @if(!empty($notification['is_unread']))
                                            <span class="topbar-bell-count">New</span>
                                        @endif
                                    </a>
                                @endforeach
                            </div>
                        @endif
                        @if($topbarNotifications->isNotEmpty())
                            <div class="topbar-bell-empty" data-notification-empty hidden>No unread notifications right now. New assignments and operational updates will appear here automatically.</div>
                        @endif
                        <div class="topbar-bell-settings">
                            <div class="topbar-bell-settings-head">
                                <div>
                                    <strong>Alert settings</strong>
                                    <span>Optional and user-controlled</span>
                                </div>
                            </div>
                            <div class="topbar-bell-switches">
                                <label class="topbar-bell-switch">
                                    <span class="topbar-bell-switch-copy">
                                        <strong>Sound alerts</strong>
                                        <span>Play a short sound for important new tasks.</span>
                                    </span>
                                    <span class="topbar-bell-toggle">
                                        <input type="checkbox" data-notification-sound-toggle {{ $notificationSoundEnabled ? 'checked' : '' }}>
                                        <span class="topbar-bell-toggle-track"></span>
                                    </span>
                                </label>
                                <label class="topbar-bell-switch">
                                    <span class="topbar-bell-switch-copy">
                                        <strong>Voice alerts</strong>
                                        <span>Speak short safe labels like &ldquo;New pickup assigned&rdquo;.</span>
                                    </span>
                                    <span class="topbar-bell-toggle">
                                        <input type="checkbox" data-notification-voice-toggle {{ $notificationVoiceEnabled ? 'checked' : '' }}>
                                        <span class="topbar-bell-toggle-track"></span>
                                    </span>
                                </label>
                            </div>
                            <div class="topbar-bell-tools">
                                <button type="button" class="topbar-bell-tool-button" data-notification-test-sound>Test Sound</button>
                                <button type="button" class="topbar-bell-tool-button" data-notification-test-voice>Test Voice</button>
                            </div>
                            <div class="topbar-bell-tone-row">
                                <label class="topbar-bell-tone-label" for="notification-sound-variant">
                                    <span>Alert tone</span>
                                    <select id="notification-sound-variant" class="topbar-bell-select" data-notification-sound-variant>
                                        @foreach($notificationSoundOptions as $option)
                                            <option value="{{ $option['value'] }}" @selected($notificationSoundVariant === $option['value'])>{{ $option['label'] }}</option>
                                        @endforeach
                                    </select>
                                </label>
                            </div>
                            <div class="topbar-bell-settings-hint" data-notification-settings-hint hidden></div>
                        </div>
                        @if(!empty($topbarNotificationsViewAllHref))
                            <div class="topbar-bell-footer">
                                <a href="{{ $topbarNotificationsViewAllHref }}">View all</a>
                            </div>
                        @endif
                    </div>
                </details>
                <details class="topbar-user-menu">
                    <summary class="topbar-user-trigger" aria-label="Open user menu" title="{{ $currentUser?->name }} · {{ $userRoleLabel }} · {{ $currentUser?->organization?->name ?: $internalCompanyName }}">
                        <div class="topbar-user-avatar">{{ $userInitials }}</div>
                        <div class="topbar-user-meta">
                            <strong>{{ $currentUser?->name ?: 'User' }}</strong>
                            <span>{{ $currentUser?->organization?->name ?: $internalCompanyName }}</span>
                        </div>
                    </summary>
                    <div class="topbar-user-panel">
                        <div class="topbar-user-head">
                            <strong>{{ $currentUser?->name ?: 'Prime Healers OS User' }}</strong>
                            <span>{{ $currentUser?->organization?->name ?: $internalCompanyName }}</span>
                            @if(!empty($userRoleLabel))
                                <span class="rn-badge topbar-user-role">{{ $userRoleLabel }}</span>
                            @endif
                        </div>
                        @if(!empty($profileHref))
                            <a href="{{ $profileHref }}" class="topbar-user-link">
                                <span class="topbar-user-action-icon" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M20 21a8 8 0 0 0-16 0"></path>
                                        <circle cx="12" cy="8" r="4"></circle>
                                    </svg>
                                </span>
                                <span>Profile</span>
                            </a>
                        @endif
                        @if(!empty($logoutHref))
                            <form method="POST" action="{{ $logoutHref }}" id="topbarLogoutForm">
                                @csrf
                                <button type="button" class="topbar-user-logout" onclick="document.getElementById('topbarLogoutForm')?.requestSubmit();">
                                    <span class="topbar-user-action-icon" aria-hidden="true">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                                            <path d="M16 17l5-5-5-5"></path>
                                            <path d="M21 12H9"></path>
                                        </svg>
                                    </span>
                                    <span>Logout</span>
                                </button>
                            </form>
                        @endif
                    </div>
                </details>
            </div>
        </header>

        @hasSection('breadcrumbs')
            @yield('breadcrumbs')
        @elseif(!empty($breadcrumbItems) && !View::hasSection('focused_form'))
            <nav class="desktop-breadcrumb" aria-label="Breadcrumb" style="display:flex; align-items:center; gap:7px; flex-wrap:wrap; margin:0 0 14px; padding:10px 14px; border:1px solid #e2e8f0; border-radius:20px; background:#ffffff; box-shadow:0 14px 34px rgba(15,23,42,0.04); font-size:12px; color:#64748b;">
                @foreach($breadcrumbItems as $index => $crumb)
                    @if($index > 0)
                        <span style="color:#94a3b8;">/</span>
                    @endif

                    @if(!empty($crumb['href']) && $index < count($breadcrumbItems) - 1)
                        <a href="{{ $crumb['href'] }}" style="color:#475569; text-decoration:none; font-weight:700;">{{ $crumb['label'] }}</a>
                    @else
                        <span style="color:{{ $index === count($breadcrumbItems) - 1 ? '#0f172a' : '#64748b' }}; font-weight:{{ $index === count($breadcrumbItems) - 1 ? '800' : '700' }};">{{ $crumb['label'] }}</span>
                    @endif
                @endforeach
            </nav>
        @endif
        @yield('content')
    </main>
</div>
@include('partials.mobile-bottom-nav', ['mobilePrimaryItems' => $mobilePrimaryItems, 'navIcon' => $navIcon])
@include('partials.mobile-more-menu', ['mobileMoreItems' => $mobileMoreItems, 'navIcon' => $navIcon, 'currentUser' => $currentUser, 'userInitials' => $userInitials, 'userRoleLabel' => $userRoleLabel, 'profileHref' => $profileHref, 'logoutHref' => $logoutHref])
<div class="topbar-toast-stack" data-notification-toast-stack aria-live="polite" aria-atomic="true"></div>
@if(session('success') || session('error') || session('status'))
    <div
        data-action-toast
        style="position:fixed; right:18px; top:var(--phos-action-toast-top, auto); bottom:var(--phos-action-toast-bottom, 18px); z-index:1200; pointer-events:none; max-width:min(420px, calc(100vw - 36px)); padding:12px 14px; border-radius:14px; box-shadow:0 18px 42px rgba(15,23,42,0.22); font-size:13px; font-weight:700; line-height:1.4; color:{{ session('error') ? '#991b1b' : '#14532d' }}; background:{{ session('error') ? '#fef2f2' : '#ecfdf5' }}; border:1px solid {{ session('error') ? '#fecaca' : '#bbf7d0' }};">
        {{ session('error') ?: (session('success') ?: session('status')) }}
    </div>
@endif
<x-release-badge />
<script>
    (function () {
        if (!window.rentnexisModalLock) {
            window.rentnexisModalLock = (function () {
                let lockCount = 0;

                function lock() {
                    const docBody = document.body;

                    if (!docBody) {
                        return;
                    }

                    if (lockCount === 0) {
                        const scrollY = window.scrollY || document.documentElement.scrollTop || 0;
                        docBody.dataset.modalScrollY = String(scrollY);
                        docBody.style.position = 'fixed';
                        docBody.style.top = `-${scrollY}px`;
                        docBody.style.left = '0';
                        docBody.style.right = '0';
                        docBody.style.width = '100%';
                        docBody.style.overflow = 'hidden';
                        docBody.classList.add('modal-open');
                    }

                    lockCount += 1;
                }

                function unlock() {
                    const docBody = document.body;

                    if (!docBody || lockCount === 0) {
                        return;
                    }

                    lockCount -= 1;

                    if (lockCount > 0) {
                        return;
                    }

                    const savedScroll = parseInt(docBody.dataset.modalScrollY || '0', 10) || 0;
                    docBody.style.position = '';
                    docBody.style.top = '';
                    docBody.style.left = '';
                    docBody.style.right = '';
                    docBody.style.width = '';
                    docBody.style.overflow = '';
                    docBody.classList.remove('modal-open');
                    delete docBody.dataset.modalScrollY;
                    window.scrollTo({ top: savedScroll, behavior: 'auto' });
                }

                return { lock, unlock };
            })();
        }

        if (!window.rentnexisModalScrollFieldIntoView) {
            window.rentnexisModalScrollFieldIntoView = function (field, scrollContainer) {
                if (!field) {
                    return;
                }

                window.setTimeout(function () {
                    try {
                        field.scrollIntoView({ block: 'center', inline: 'nearest', behavior: 'smooth' });
                    } catch (error) {
                        field.scrollIntoView();
                    }

                    if (scrollContainer && typeof scrollContainer.scrollTop === 'number') {
                        const rect = field.getBoundingClientRect();
                        const containerRect = scrollContainer.getBoundingClientRect();

                        if (rect.bottom > containerRect.bottom || rect.top < containerRect.top) {
                            scrollContainer.scrollTop += rect.top - containerRect.top - 24;
                        }
                    }
                }, 180);
            };
        }

        const scrollKey = 'rentnexis:action-scroll:' + window.location.pathname;
        const getShellScrollContainer = function () {
            const shellMain = document.querySelector('.app-shell-main');

            if (shellMain && shellMain.scrollHeight > shellMain.clientHeight + 1) {
                return shellMain;
            }

            return document.scrollingElement || document.documentElement;
        };
        const getCurrentScrollTop = function () {
            const container = getShellScrollContainer();

            return container === document.scrollingElement || container === document.documentElement
                ? (window.scrollY || document.documentElement.scrollTop || 0)
                : container.scrollTop;
        };
        const restoreScrollTop = function (top) {
            const parsedTop = Number.parseInt(top, 10);
            const nextTop = Math.max((Number.isFinite(parsedTop) ? parsedTop : 0) - 80, 0);
            const container = getShellScrollContainer();

            if (container === document.scrollingElement || container === document.documentElement) {
                window.scrollTo({ top: nextTop, behavior: 'auto' });
                return;
            }

            container.scrollTop = nextTop;
        };

        document.addEventListener('submit', function (event) {
            const form = event.target;

            if (!(form instanceof HTMLFormElement)) {
                return;
            }

            const method = (form.getAttribute('method') || 'GET').toUpperCase();

            if (method === 'GET' || form.hasAttribute('data-no-scroll-restore')) {
                return;
            }

            try {
                sessionStorage.setItem(scrollKey, String(getCurrentScrollTop()));
            } catch (error) {
                // Storage can be disabled in strict browsers; action should still submit normally.
            }
        }, true);

        window.addEventListener('DOMContentLoaded', function () {
            const isDeleteForm = function (form) {
                if (!(form instanceof HTMLFormElement)) {
                    return false;
                }

                const method = (form.getAttribute('method') || 'GET').toUpperCase();

                if (method === 'DELETE') {
                    return true;
                }

                const overrideField = form.querySelector('input[name="_method"]');

                return method === 'POST'
                    && overrideField
                    && String(overrideField.value || '').toUpperCase() === 'DELETE';
            };

            const extractConfirmMessage = function (source) {
                if (!source) {
                    return null;
                }

                const match = source.match(/confirm\((['"`])([\s\S]*?)\1\)/i);

                return match ? match[2] : null;
            };

            const registerDeleteConfirmMessage = function (form, message) {
                if (!isDeleteForm(form) || !message || form.dataset.deleteConfirmPrimary) {
                    return;
                }

                form.dataset.deleteConfirmPrimary = message;
            };

            Array.from(document.querySelectorAll('form')).forEach(function (form) {
                if (!isDeleteForm(form)) {
                    return;
                }

                registerDeleteConfirmMessage(form, extractConfirmMessage(form.getAttribute('onsubmit')));

                if (form.hasAttribute('onsubmit')) {
                    form.removeAttribute('onsubmit');
                }
            });

            Array.from(document.querySelectorAll('button[onclick], input[type="submit"][onclick], input[type="button"][onclick]')).forEach(function (trigger) {
                const confirmMessage = extractConfirmMessage(trigger.getAttribute('onclick'));

                if (!confirmMessage) {
                    return;
                }

                let targetForm = trigger.form;

                if (!targetForm && trigger instanceof HTMLElement) {
                    const formId = trigger.getAttribute('form');

                    if (formId) {
                        targetForm = document.getElementById(formId);
                    }
                }

                if (!isDeleteForm(targetForm)) {
                    return;
                }

                registerDeleteConfirmMessage(targetForm, confirmMessage);
                trigger.removeAttribute('onclick');
            });

            document.addEventListener('submit', function (event) {
                const form = event.target;

                if (!isDeleteForm(form) || form.dataset.deleteConfirmAccepted === 'true') {
                    return;
                }

                const primaryMessage = form.dataset.deleteConfirmPrimary || 'Delete this record?';
                const secondaryMessage = form.dataset.deleteConfirmSecondary || 'Please confirm again. This action is permanent and cannot be undone.';

                if (!window.confirm(primaryMessage)) {
                    event.preventDefault();
                    return;
                }

                if (!window.confirm(secondaryMessage)) {
                    event.preventDefault();
                    return;
                }

                form.dataset.deleteConfirmAccepted = 'true';
            }, true);

            try {
                const savedScroll = sessionStorage.getItem(scrollKey);

                if (savedScroll !== null) {
                    sessionStorage.removeItem(scrollKey);
                    window.requestAnimationFrame(function () {
                        restoreScrollTop(savedScroll);
                    });
                }
            } catch (error) {
                // Nothing to restore.
            }

            const toast = document.querySelector('[data-action-toast]');
            if (toast) {
                window.setTimeout(function () {
                    toast.style.transition = 'opacity .25s ease, transform .25s ease';
                    toast.style.opacity = '0';
                    toast.style.transform = 'translateY(8px)';
                }, 4500);
            }

            const sidebarGroups = Array.from(document.querySelectorAll('[data-sidebar-group]'));
            const sidebarStateKey = 'rentnexis:sidebar-groups:v1';
            const sidebarShell = document.querySelector('[data-sidebar-shell]');
            const sidebarShellToggle = document.querySelector('[data-sidebar-shell-toggle]');
            const sidebarShellPreferenceKey = 'rentnexis:sidebar-shell-collapsed:v1';
            let savedSidebarState = {};
            let savedSidebarCollapsed = false;

            try {
                savedSidebarState = JSON.parse(localStorage.getItem(sidebarStateKey) || '{}') || {};
            } catch (error) {
                savedSidebarState = {};
            }

            try {
                savedSidebarCollapsed = localStorage.getItem(sidebarShellPreferenceKey) === 'true';
            } catch (error) {
                savedSidebarCollapsed = false;
            }

            const persistSidebarState = function () {
                try {
                    localStorage.setItem(sidebarStateKey, JSON.stringify(savedSidebarState));
                } catch (error) {
                    // Storage can be unavailable; sidebar should still work for the session.
                }
            };

            const applySidebarShellState = function (collapsed, options) {
                const config = options || {};

                if (!sidebarShell || !sidebarShellToggle) {
                    return;
                }

                sidebarShell.classList.toggle('is-sidebar-collapsed', collapsed);
                sidebarShellToggle.setAttribute('aria-pressed', collapsed ? 'true' : 'false');
                sidebarShellToggle.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
                sidebarShellToggle.setAttribute('title', collapsed ? 'Expand sidebar' : 'Collapse sidebar');

                const toggleLabel = sidebarShellToggle.querySelector('.sidebar-shell-toggle-label');
                if (toggleLabel) {
                    toggleLabel.textContent = collapsed ? 'Expand' : 'Collapse';
                }

                if (!config.skipSave) {
                    try {
                        localStorage.setItem(sidebarShellPreferenceKey, collapsed ? 'true' : 'false');
                    } catch (error) {
                        // Storage can be unavailable; sidebar should still work for the session.
                    }
                }
            };

            const setSidebarGroupState = function (group, expand, options) {
                const config = options || {};
                const groupKey = group.getAttribute('data-sidebar-group');
                const panel = group.querySelector('[data-sidebar-panel]');
                const toggle = group.querySelector('[data-sidebar-toggle]');

                if (!groupKey || !panel || !toggle) {
                    return;
                }

                group.classList.toggle('is-expanded', expand);
                group.classList.toggle('is-collapsed', !expand);
                toggle.setAttribute('aria-expanded', expand ? 'true' : 'false');

                if (config.immediate) {
                    panel.hidden = !expand;
                    panel.style.opacity = expand ? '1' : '0';
                    panel.style.maxHeight = expand ? 'none' : '0px';

                    if (!config.skipSave) {
                        savedSidebarState[groupKey] = expand;
                        persistSidebarState();
                    }

                    return;
                }

                if (expand) {
                    panel.hidden = false;
                    panel.style.maxHeight = '0px';
                    panel.style.opacity = '1';
                    requestAnimationFrame(function () {
                        panel.style.maxHeight = panel.scrollHeight + 'px';
                    });
                } else {
                    panel.style.maxHeight = panel.scrollHeight + 'px';
                    panel.style.opacity = '0';
                    requestAnimationFrame(function () {
                        panel.style.maxHeight = '0px';
                    });
                    window.setTimeout(function () {
                        if (group.classList.contains('is-collapsed')) {
                            panel.hidden = true;
                        }
                    }, 220);
                }

                if (!config.skipSave) {
                    savedSidebarState[groupKey] = expand;
                    persistSidebarState();
                }
            };

            sidebarGroups.forEach(function (group) {
                const groupKey = group.getAttribute('data-sidebar-group');
                const toggle = group.querySelector('[data-sidebar-toggle]');
                const panel = group.querySelector('[data-sidebar-panel]');
                const hasActive = group.getAttribute('data-has-active') === 'true';
                const defaultOpen = group.getAttribute('data-default-open') === 'true';
                const hasSavedState = Object.prototype.hasOwnProperty.call(savedSidebarState, groupKey);
                const shouldOpen = hasActive ? true : (hasSavedState ? !!savedSidebarState[groupKey] : defaultOpen);

                if (!panel || !toggle) {
                    return;
                }

                setSidebarGroupState(group, shouldOpen, { skipSave: true, immediate: true });

                toggle.addEventListener('click', function (event) {
                    event.preventDefault();
                    const isExpanded = toggle.getAttribute('aria-expanded') === 'true';
                    setSidebarGroupState(group, !isExpanded);
                });
            });

            applySidebarShellState(savedSidebarCollapsed, { skipSave: true });

            if (sidebarShellToggle) {
                sidebarShellToggle.addEventListener('click', function (event) {
                    event.preventDefault();

                    const isCollapsed = sidebarShell.classList.contains('is-sidebar-collapsed');
                    applySidebarShellState(!isCollapsed);
                });
            }

            const mobileFilterSheets = Array.from(document.querySelectorAll('[data-mobile-filter-sheet]'));
            const sortRoots = Array.from(document.querySelectorAll('[data-mobile-sort-root]'));
            const bodyLock = window.rentnexisModalLock;
            const filterPanels = Array.from(document.querySelectorAll('details[data-filter-panel][data-filter-panel-key]:not([data-phos-list-filter])'));

            filterPanels.forEach(function (panel) {
                const panelKey = panel.getAttribute('data-filter-panel-key');
                const isActive = panel.getAttribute('data-filter-active') === 'true';
                const hasErrors = panel.getAttribute('data-filter-errors') === 'true';
                const storageKey = panelKey ? `rentnexis:filter-panel:${panelKey}` : null;
                const clearLinks = document.querySelectorAll(`[data-filter-clear="${panelKey}"]`);

                if (!storageKey) {
                    return;
                }

                let savedState = null;

                try {
                    savedState = localStorage.getItem(storageKey);
                } catch (error) {
                    savedState = null;
                }

                if (hasErrors || isActive) {
                    panel.open = true;
                } else if (savedState === 'open') {
                    panel.open = true;
                } else if (savedState === 'closed') {
                    panel.open = false;
                }

                panel.addEventListener('toggle', function () {
                    try {
                        localStorage.setItem(storageKey, panel.open ? 'open' : 'closed');
                    } catch (error) {
                        // Storage can be unavailable; filter panels should still toggle normally.
                    }
                });

                clearLinks.forEach(function (link) {
                    link.addEventListener('click', function () {
                        try {
                            localStorage.removeItem(storageKey);
                        } catch (error) {
                            // Nothing to clear.
                        }
                    });
                });
            });

            const closeAllSortMenus = function () {
                sortRoots.forEach(function (root) {
                    const menu = root.querySelector('[data-mobile-sort-menu]');
                    if (menu) {
                        menu.hidden = true;
                        menu.classList.remove('is-open');
                    }
                });
            };

            const closeFilterSheet = function (sheet) {
                if (!sheet || sheet.hidden) {
                    return;
                }

                sheet.classList.remove('is-open');
                window.setTimeout(function () {
                    sheet.hidden = true;
                }, 180);

                if (bodyLock && typeof bodyLock.unlock === 'function') {
                    bodyLock.unlock();
                } else {
                    document.body.style.overflow = '';
                }
            };

            document.querySelectorAll('[data-mobile-filter-open]').forEach(function (trigger) {
                trigger.addEventListener('click', function () {
                    const targetId = trigger.getAttribute('data-mobile-filter-open');
                    const sheet = targetId ? document.getElementById(targetId) : null;

                    if (!sheet) {
                        return;
                    }

                    closeAllSortMenus();
                    sheet.hidden = false;
                    requestAnimationFrame(function () {
                        sheet.classList.add('is-open');
                    });

                    if (bodyLock && typeof bodyLock.lock === 'function') {
                        bodyLock.lock();
                    } else {
                        document.body.style.overflow = 'hidden';
                    }
                });
            });

            document.querySelectorAll('[data-mobile-sheet-close]').forEach(function (trigger) {
                trigger.addEventListener('click', function () {
                    const targetId = trigger.getAttribute('data-mobile-sheet-close');
                    const sheet = targetId ? document.getElementById(targetId) : trigger.closest('[data-mobile-filter-sheet]');
                    closeFilterSheet(sheet);
                });
            });

            mobileFilterSheets.forEach(function (sheet) {
                sheet.addEventListener('click', function (event) {
                    if (event.target === sheet) {
                        closeFilterSheet(sheet);
                    }
                });
            });

            sortRoots.forEach(function (root) {
                const trigger = root.querySelector('[data-mobile-sort-trigger]');
                const menu = root.querySelector('[data-mobile-sort-menu]');

                if (!trigger || !menu) {
                    return;
                }

                menu.hidden = true;

                trigger.addEventListener('click', function (event) {
                    event.preventDefault();
                    const willOpen = menu.hidden;

                    closeAllSortMenus();

                    if (willOpen) {
                        menu.hidden = false;
                        requestAnimationFrame(function () {
                            menu.classList.add('is-open');
                        });
                    }
                });
            });

            const managedMenuSelector = [
                '.customer-action-menu',
                '.ops-action-menu',
                '.delivery-action-menu',
                '.product-action-menu',
                '.asset-action-menu',
                '.invoice-action-menu',
                '.mobile-actions-menu',
                '.quick-add-menu',
                '.topbar-notification-menu',
                '.topbar-user-menu'
            ].join(', ');

            const closeManagedMenus = function (except) {
                document.querySelectorAll(managedMenuSelector + '[open]').forEach(function (menu) {
                    if (menu !== except) {
                        menu.removeAttribute('open');
                        menu.closest('tr')?.classList.remove('is-action-open');
                    }
                });
            };

            document.querySelectorAll(managedMenuSelector).forEach(function (menu) {
                menu.addEventListener('toggle', function () {
                    if (menu.open) {
                        closeManagedMenus(menu);
                        menu.closest('tr')?.classList.add('is-action-open');
                    } else {
                        menu.closest('tr')?.classList.remove('is-action-open');
                    }
                });
            });

            document.addEventListener('click', function (event) {
                const placeholderLink = event.target.closest('a[href="#"], a[href=""]');
                if (placeholderLink) {
                    event.preventDefault();
                    return;
                }

                if (!event.target.closest(managedMenuSelector)) {
                    closeManagedMenus();
                }
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    closeManagedMenus();
                }
            });

            document.addEventListener('click', function (event) {
                if (!event.target.closest('[data-mobile-sort-root]')) {
                    closeAllSortMenus();
                }
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    closeAllSortMenus();
                    mobileFilterSheets.forEach(function (sheet) {
                        closeFilterSheet(sheet);
                    });
                }
            });
        });
    })();
</script>
<div class="delivery-edit-modal" data-delivery-edit-modal aria-hidden="true">
    <div class="delivery-edit-modal__backdrop" data-delivery-edit-close></div>
    <section class="delivery-edit-modal__panel" role="dialog" aria-modal="true" aria-label="Edit assignment">
        <header class="delivery-edit-modal__header">
            <div>
                <p>Edit Assignment</p>
                <span>Update delivery or pickup without leaving this page.</span>
            </div>
            <button type="button" class="delivery-edit-modal__close" data-delivery-edit-close aria-label="Close assignment editor">
                Close
            </button>
        </header>
        <iframe class="delivery-edit-modal__frame" title="Edit assignment" data-delivery-edit-frame src="about:blank"></iframe>
    </section>
</div>
<style>
    .delivery-edit-modal {
        position:fixed;
        inset:0;
        z-index:1300;
        display:none;
        align-items:flex-start;
        justify-content:center;
        padding:92px 14px 18px;
        pointer-events:none;
    }

    .delivery-edit-modal.is-open {
        display:flex;
        pointer-events:auto;
    }

    .delivery-edit-modal__backdrop {
        position:absolute;
        inset:0;
        background:rgba(15,23,42,.45);
        backdrop-filter:blur(5px);
    }

    .delivery-edit-modal__panel {
        position:relative;
        width:min(820px, 100%);
        max-height:calc(100dvh - 116px);
        display:flex;
        flex-direction:column;
        overflow:hidden;
        border:1px solid #dbe3ef;
        border-radius:20px;
        background:#fff;
        box-shadow:0 28px 90px rgba(15,23,42,.3);
    }

    .delivery-edit-modal__header {
        flex:0 0 auto;
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:12px;
        padding:10px 12px;
        border-bottom:1px solid #e2e8f0;
        background:#fff;
    }

    .delivery-edit-modal__header p {
        margin:0;
        color:#0f172a;
        font-size:16px;
        font-weight:800;
        line-height:1.1;
    }

    .delivery-edit-modal__header span {
        display:block;
        margin-top:2px;
        color:#64748b;
        font-size:11.5px;
        line-height:1.2;
    }

    .delivery-edit-modal__close {
        min-height:34px;
        border:1px solid #cbd5e1;
        border-radius:10px;
        padding:7px 11px;
        background:#fff;
        color:#334155;
        font-size:12.5px;
        font-weight:800;
        cursor:pointer;
    }

    .delivery-edit-modal__frame {
        flex:1 1 auto;
        width:100%;
        height:520px;
        min-height:0;
        border:0;
        background:#f8fafc;
    }

    @media (max-width: 767px) {
        .delivery-edit-modal {
            align-items:flex-end;
            padding:10px 8px;
        }

        .delivery-edit-modal__panel {
            max-height:92dvh;
            border-radius:18px 18px 0 0;
        }
    }
</style>
<script>
    (function () {
        document.addEventListener('DOMContentLoaded', function () {
            const modal = document.querySelector('[data-delivery-edit-modal]');
            const frame = document.querySelector('[data-delivery-edit-frame]');
            const panel = modal?.querySelector('.delivery-edit-modal__panel');

            if (!modal || !frame) {
                return;
            }

            let activeEditPath = '';
            let isClosing = false;
            let resizeTimer = null;

            const isDeliveryEditLink = function (link) {
                if (!link || !link.href) {
                    return false;
                }

                try {
                    const url = new URL(link.href, window.location.href);
                    return url.origin === window.location.origin && /^\/deliveries\/\d+\/edit\/?$/.test(url.pathname);
                } catch (error) {
                    return false;
                }
            };

            const openModal = function (href) {
                const url = new URL(href, window.location.href);
                activeEditPath = url.pathname.replace(/\/$/, '');
                url.searchParams.set('embedded', '1');
                isClosing = false;
                frame.style.height = window.matchMedia('(max-width: 767px)').matches ? '74dvh' : '520px';
                frame.src = url.toString();
                modal.classList.add('is-open');
                modal.setAttribute('aria-hidden', 'false');
                document.body.style.overflow = 'hidden';
            };

            const resizeFrameToContent = function () {
                if (!modal.classList.contains('is-open') || !frame.contentWindow || !panel) {
                    return;
                }

                try {
                    const doc = frame.contentWindow.document;
                    const contentHeight = Math.ceil(Math.max(
                        doc.body?.scrollHeight || 0,
                        doc.documentElement?.scrollHeight || 0
                    ));
                    const reservedSpace = window.matchMedia('(max-width: 767px)').matches ? 74 : 128;
                    const minHeight = window.matchMedia('(max-width: 767px)').matches ? 420 : 360;
                    const maxHeight = Math.max(minHeight, window.innerHeight - reservedSpace);
                    const nextHeight = Math.min(Math.max(contentHeight, minHeight), maxHeight);

                    frame.style.height = nextHeight + 'px';
                } catch (error) {
                    // Same-origin edit pages are expected; keep the default frame size if measurement fails.
                }
            };

            const scheduleResize = function () {
                window.clearTimeout(resizeTimer);
                resizeTimer = window.setTimeout(resizeFrameToContent, 60);
            };

            const closeModal = function (shouldRefresh) {
                if (isClosing) {
                    return;
                }

                isClosing = true;
                modal.classList.remove('is-open');
                modal.setAttribute('aria-hidden', 'true');
                document.body.style.overflow = '';
                frame.src = 'about:blank';
                activeEditPath = '';

                if (shouldRefresh) {
                    window.location.reload();
                }
            };

            document.addEventListener('click', function (event) {
                const link = event.target.closest('a[href]');

                if (!isDeliveryEditLink(link)) {
                    return;
                }

                if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || link.target === '_blank') {
                    return;
                }

                event.preventDefault();
                openModal(link.href);
            });

            document.querySelectorAll('[data-delivery-edit-close]').forEach(function (button) {
                button.addEventListener('click', function () {
                    closeModal(false);
                });
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && modal.classList.contains('is-open')) {
                    closeModal(false);
                }
            });

            frame.addEventListener('load', function () {
                if (!modal.classList.contains('is-open') || !activeEditPath || isClosing) {
                    return;
                }

                try {
                    const current = new URL(frame.contentWindow.location.href);
                    const currentPath = current.pathname.replace(/\/$/, '');

                    if (currentPath !== activeEditPath && current.protocol !== 'about:') {
                        closeModal(true);
                        return;
                    }

                    scheduleResize();
                } catch (error) {
                    // Same-origin edit pages are expected; keep the modal open if the frame cannot be inspected.
                }
            });

            window.addEventListener('resize', scheduleResize);
        });
    })();
</script>
<script>
    window.PHOS_PREDICTIVE_SEARCH_ENDPOINT = @json(Route::has('search.suggestions') ? route('search.suggestions') : url('/search/suggestions'));

    (function () {
        const endpoint = window.PHOS_PREDICTIVE_SEARCH_ENDPOINT;
        const minCharsDefault = 2;
        const debounceMs = 240;
        let activeController = null;
        let openInstance = null;

        function escapeHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function inferType(input) {
            const explicit = input.dataset.predictiveType;
            if (explicit) return explicit;

            const haystack = [input.name, input.id, input.placeholder, input.getAttribute('aria-label')]
                .filter(Boolean)
                .join(' ')
                .toLowerCase();

            if (haystack.includes('invoice')) return 'invoice';
            if (haystack.includes('payment')) return 'payment';
            if (haystack.includes('rental')) return 'rental';
            if (haystack.includes('sale')) return 'sale';
            if (haystack.includes('asset') || haystack.includes('serial') || haystack.includes('barcode')) return 'asset';
            if (haystack.includes('product') || haystack.includes('sku')) return 'product';
            if (haystack.includes('vendor')) return 'vendor';
            if (haystack.includes('partner')) return 'business_partner';
            if (haystack.includes('customer') || haystack.includes('client') || haystack.includes('phone')) return 'customer';
            if (haystack.includes('pickup')) return 'pickup';
            if (haystack.includes('delivery') || haystack.includes('dispatch')) return 'delivery';

            return input.name === 'q' ? 'all' : 'all';
        }

        function shouldEnhance(input) {
            if (!input || input.dataset.predictiveEnhanced === 'true') return false;
            if (input.closest('.searchable-select-panel')) return false;
            if (input.matches('[data-no-predictive-search], .searchable-select-search')) return false;
            if (input.type === 'hidden' || input.disabled || input.readOnly) return false;
            if (input.hasAttribute('data-predictive-search')) return true;
            if (input.name === 'q' && input.closest('.app-shell-search')) return true;
            return input.name === 'search' && (input.type === 'search' || input.type === 'text');
        }

        function placePanel(input, panel) {
            const rect = input.getBoundingClientRect();
            panel.style.left = Math.max(10, rect.left) + 'px';
            panel.style.top = Math.min(window.innerHeight - 72, rect.bottom + 6) + 'px';
            panel.style.width = Math.max(220, rect.width) + 'px';
        }

        function makeState(text) {
            return '<div class="phos-predictive-state">' + escapeHtml(text) + '</div>';
        }

        function enhance(input) {
            input.dataset.predictiveEnhanced = 'true';
            input.setAttribute('autocomplete', 'off');

            const panel = document.createElement('div');
            panel.className = 'phos-predictive-panel';
            panel.setAttribute('role', 'listbox');
            panel.setAttribute('aria-label', 'Search suggestions');
            document.body.appendChild(panel);

            const state = {
                results: [],
                activeIndex: -1,
                timer: null,
                lastQuery: '',
            };

            function hiddenInput() {
                return input.closest('.phos-predictive-field, form, .ph-card, section, div')?.querySelector('[data-predictive-hidden-id]') || null;
            }

            function close() {
                panel.classList.remove('is-open');
                panel.innerHTML = '';
                state.activeIndex = -1;
                if (openInstance === api) openInstance = null;
            }

            function open() {
                if (openInstance && openInstance !== api) openInstance.close();
                openInstance = api;
                placePanel(input, panel);
                panel.classList.add('is-open');
            }

            function render() {
                if (!state.results.length) {
                    panel.innerHTML = makeState('No suggestions found');
                    open();
                    return;
                }

                panel.innerHTML = state.results.map(function (item, index) {
                    const active = index === state.activeIndex ? ' is-active' : '';
                    const subtitle = item.subtitle ? '<span>' + escapeHtml(item.subtitle) + '</span>' : '';
                    const meta = item.meta ? '<em>' + escapeHtml(item.meta) + '</em>' : '';
                    return '<button type="button" class="phos-predictive-option' + active + '" role="option" data-index="' + index + '"><strong>' + escapeHtml(item.title || item.label || item.value) + '</strong>' + subtitle + meta + '</button>';
                }).join('');
                open();
            }

            function selectResult(item) {
                if (!item) return;

                input.value = item.value || item.label || item.title || '';
                const hidden = hiddenInput();
                if (hidden && item.id) {
                    hidden.value = item.id;
                    hidden.dispatchEvent(new Event('change', { bubbles: true }));
                }
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
                close();

                const isGlobalHeader = input.name === 'q' && input.closest('.app-shell-search');
                if (isGlobalHeader && item.href) {
                    window.location.href = item.href;
                }
            }

            function fetchSuggestions() {
                const query = input.value.trim();
                const minChars = parseInt(input.dataset.predictiveMinChars || minCharsDefault, 10);
                if (query.length < minChars) {
                    close();
                    return;
                }

                if (query === state.lastQuery && state.results.length) {
                    render();
                    return;
                }

                if (activeController) activeController.abort();
                activeController = new AbortController();
                state.lastQuery = query;
                panel.innerHTML = makeState('Searching...');
                open();

                const url = new URL(input.dataset.predictiveEndpoint || endpoint, window.location.origin);
                url.searchParams.set('q', query);
                url.searchParams.set('type', inferType(input));
                url.searchParams.set('limit', input.dataset.predictiveLimit || '8');

                Object.keys(input.dataset).forEach(function (key) {
                    if (key.startsWith('context') && input.dataset[key] !== '') {
                        url.searchParams.set(key.replace(/^context/, '').replace(/[A-Z]/g, m => '_' + m.toLowerCase()).replace(/^_/, ''), input.dataset[key]);
                    }
                });

                fetch(url.toString(), {
                    headers: { 'Accept': 'application/json' },
                    signal: activeController.signal,
                })
                    .then(response => response.ok ? response.json() : Promise.reject(response))
                    .then(data => {
                        state.results = Array.isArray(data.results) ? data.results : [];
                        state.activeIndex = state.results.length ? 0 : -1;
                        render();
                    })
                    .catch(error => {
                        if (error.name === 'AbortError') return;
                        panel.innerHTML = makeState('Suggestions unavailable');
                        open();
                    });
            }

            input.addEventListener('input', function () {
                window.clearTimeout(state.timer);
                state.timer = window.setTimeout(fetchSuggestions, debounceMs);
            });

            input.addEventListener('focus', function () {
                if (input.value.trim().length >= parseInt(input.dataset.predictiveMinChars || minCharsDefault, 10)) {
                    fetchSuggestions();
                }
            });

            input.addEventListener('keydown', function (event) {
                if (!panel.classList.contains('is-open')) return;
                if (event.key === 'Escape') {
                    event.preventDefault();
                    close();
                    return;
                }
                if (event.key === 'ArrowDown') {
                    event.preventDefault();
                    state.activeIndex = Math.min(state.results.length - 1, state.activeIndex + 1);
                    render();
                    return;
                }
                if (event.key === 'ArrowUp') {
                    event.preventDefault();
                    state.activeIndex = Math.max(0, state.activeIndex - 1);
                    render();
                    return;
                }
                if (event.key === 'Enter' && state.activeIndex >= 0 && state.results[state.activeIndex]) {
                    event.preventDefault();
                    selectResult(state.results[state.activeIndex]);
                }
            });

            panel.addEventListener('mousedown', function (event) {
                event.preventDefault();
            });

            panel.addEventListener('click', function (event) {
                const button = event.target.closest('.phos-predictive-option');
                if (!button) return;
                selectResult(state.results[parseInt(button.dataset.index || '-1', 10)]);
            });

            const api = { close, reposition: () => placePanel(input, panel) };
        }

        function boot() {
            document.querySelectorAll('input[type="search"], input[type="text"], input[data-predictive-search]').forEach(function (input) {
                if (shouldEnhance(input)) enhance(input);
            });
        }

        document.addEventListener('click', function (event) {
            if (!openInstance) return;
            if (event.target.closest('.phos-predictive-panel') || event.target.closest('[data-predictive-search], .app-shell-search input')) return;
            openInstance.close();
        });

        ['scroll', 'resize'].forEach(function (eventName) {
            window.addEventListener(eventName, function () {
                openInstance?.reposition?.();
            }, true);
        });

        document.addEventListener('DOMContentLoaded', boot);
        document.addEventListener('phos:content-updated', boot);
    })();
</script>
@stack('scripts')
</body>
</html>
