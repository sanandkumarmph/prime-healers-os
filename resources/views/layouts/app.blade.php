<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @php
        $releaseEnvForTitle = strtolower((string) config('version.environment', config('app.env', 'production')));
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
</head>
<body style="margin:0; width:100%; overflow-x:hidden; background:var(--ph-color-bg); color:var(--ph-color-text); font-family:var(--ph-font-body);">
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
    $reportsIndexHref = ($currentUser?->canAccessModule('reports', 'read') ?? false) ? $safeRoute('reports.index') : null;
    $isDeliveryFacingMenuRole = in_array($currentUser?->effective_role, [
        \App\Models\User::ROLE_DELIVERY,
        \App\Models\User::ROLE_DELIVERY_EXECUTIVE,
        \App\Models\User::ROLE_VENDOR,
        \App\Models\User::ROLE_THIRD_PARTY,
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
                ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'dashboard', 'href' => $safeRoute('dashboard'), 'active' => request()->routeIs('dashboard'), 'visible' => $currentUser?->canAccessDashboard() ?? false],
                ['key' => 'customers', 'label' => 'Customers', 'icon' => 'customers', 'href' => $safeRoute('customers.index'), 'active' => request()->routeIs('customers.*'), 'visible' => !$isDeliveryFacingMenuRole && ($currentUser?->canAccessModule('customers', 'read') ?? false)],
                ['key' => 'business_partners', 'label' => 'Business Partners', 'icon' => 'customers', 'href' => $safeRoute('business-partners.index'), 'active' => request()->routeIs('business-partners.*'), 'visible' => !$isDeliveryFacingMenuRole && ($currentUser?->canAccessModule('customers', 'read') ?? false)],
                ['key' => 'rentals', 'label' => 'Rentals', 'icon' => 'rentals', 'href' => $safeRoute('rentals.index'), 'active' => request()->routeIs('rentals.*'), 'visible' => !$isDeliveryFacingMenuRole && ($currentUser?->canAccessModule('rentals', 'read') ?? false)],
                ['key' => 'sales', 'label' => 'Sales', 'icon' => 'sales', 'href' => $safeRoute('sales.index'), 'active' => request()->routeIs('sales.*'), 'visible' => $currentUser?->canAccessModule('sales', 'read') ?? false],
            ],
        ],
        [
            'label' => 'Operations',
            'items' => [
                ['key' => 'renewal_center', 'label' => 'Renewal Center', 'icon' => 'rentals', 'href' => $safeRoute('renewal-center.index'), 'active' => request()->routeIs('renewal-center.*'), 'visible' => !$isDeliveryFacingMenuRole && ($currentUser?->canAccessModule('rentals', 'read') ?? false)],
                ['key' => 'pickup_center', 'label' => 'Pickup Center', 'icon' => 'pickup', 'href' => $safeRoute('pickup-center.index'), 'active' => request()->routeIs('pickup-center.*'), 'visible' => $currentUser?->canAccessModule('deliveries', 'read') ?? false],
                ['key' => 'communication_center', 'label' => 'Communication Center', 'icon' => 'customers', 'href' => $safeRoute('communication-center.index'), 'active' => request()->routeIs('communication-center.*'), 'visible' => !$isDeliveryFacingMenuRole && ($currentUser?->canAccessAnyModule(['rentals', 'sales', 'customers', 'deliveries', 'invoices'], 'read') ?? false)],
                ['key' => 'tasks_board', 'label' => 'Tasks Board', 'icon' => 'deliveries', 'href' => $safeRoute('deliveries.index'), 'active' => request()->routeIs('deliveries.*') || request()->routeIs('pickups.*'), 'visible' => $currentUser?->canAccessModule('deliveries', 'read') ?? false],
            ],
        ],
        [
            'label' => 'Inventory',
            'items' => [
                ['key' => 'inventory', 'label' => 'Inventory Overview', 'icon' => 'inventory', 'href' => $safeRoute('inventory.dashboard'), 'active' => request()->routeIs('inventory.dashboard'), 'visible' => $currentUser?->hasPermission('dashboard.inventory') ?? false],
                ['key' => 'products', 'label' => 'Product Master', 'icon' => 'products', 'href' => $safeRoute('products.index'), 'active' => request()->routeIs('products.*'), 'visible' => $currentUser?->canAccessModule('products', 'read') ?? false],
                ['key' => 'product_categories', 'label' => 'Category Master', 'icon' => 'products', 'href' => $safeRoute('product-categories.index'), 'active' => request()->routeIs('product-categories.*'), 'visible' => $currentUser?->canAccessModule('products', 'read') ?? false],
                ['key' => 'product_brands', 'label' => 'Brand Master', 'icon' => 'products', 'href' => $safeRoute('product-brands.index'), 'active' => request()->routeIs('product-brands.*'), 'visible' => $currentUser?->canAccessModule('products', 'read') ?? false],
                ['key' => 'assets', 'label' => 'Asset Register', 'icon' => 'assets', 'href' => $safeRoute('assets.index'), 'active' => request()->routeIs('assets.*') && !request()->routeIs('assets.pending-verification') && !request()->routeIs('assets.verify-return') && !request()->routeIs('assets.verify-return.store'), 'visible' => $currentUser?->canAccessModule('assets', 'read') ?? false],
                ['key' => 'return_verification', 'label' => 'Return Verification', 'icon' => 'assets', 'href' => $safeRoute('assets.pending-verification'), 'active' => request()->routeIs('assets.pending-verification') || request()->routeIs('assets.verify-return') || request()->routeIs('assets.verify-return.store'), 'visible' => $currentUser?->canAccessModule('assets', 'read') ?? false],
                ['key' => 'warehouses', 'label' => 'Warehouses', 'icon' => 'warehouses', 'href' => $safeRoute('warehouses.index'), 'active' => request()->routeIs('warehouses.*'), 'visible' => $currentUser?->canAccessModule('warehouses', 'read') ?? false],
                ['key' => 'stock_history', 'label' => 'Stock History', 'icon' => 'inventory', 'href' => $safeRoute('stock-history.index'), 'active' => request()->routeIs('stock-history.*'), 'visible' => $currentUser?->hasPermission('stock_history.view') ?? false],
                ['key' => 'inventory_intelligence', 'label' => 'Inventory Intelligence', 'icon' => 'inventory', 'href' => $safeRoute('inventory-intelligence.index'), 'active' => request()->routeIs('inventory-intelligence.*'), 'visible' => $currentUser?->hasPermission('stock_history.view') ?? false],
            ],
        ],
        [
            'label' => 'Finance',
            'items' => [
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
            ['label' => 'Dashboard', 'icon' => 'dashboard', 'href' => ($currentUser?->canAccessDashboard() ?? false) ? $safeRoute('dashboard') : null, 'active' => request()->routeIs('dashboard')],
            ['label' => 'Tasks', 'icon' => 'deliveries', 'href' => ($currentUser?->canAccessModule('deliveries', 'read') ?? false) ? $safeRoute('deliveries.index') : null, 'active' => request()->routeIs('deliveries.index') || request()->routeIs('deliveries.show') || request()->routeIs('deliveries.edit')],
            ['label' => 'Notifications', 'icon' => 'invoices', 'href' => $topbarNotificationsViewAllHref, 'active' => request()->routeIs('notifications.*')],
        ])
        : collect([
            ['label' => 'Dashboard', 'icon' => 'dashboard', 'href' => ($currentUser?->canAccessDashboard() ?? false) ? $safeRoute('dashboard') : null, 'active' => request()->routeIs('dashboard')],
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
    $isDashboardRoute = request()->routeIs('dashboard');
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

    if ($routeName && !in_array($routeName, ['dashboard', 'login', 'register'], true)) {
        $breadcrumbItems[] = ['label' => 'Home', 'href' => $dashboardHref];
    }

    if ($routeName === 'dashboard') {
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

<style>
    .app-shell {
        --ph-sidebar-expanded-width:280px;
        --ph-sidebar-collapsed-width:76px;
        --ph-sidebar-width:var(--ph-sidebar-expanded-width);
        display:flex; min-height:100vh; width:100%; max-width:100%; overflow-x:hidden;
        transition:background .18s ease;
    }
    .app-shell.is-sidebar-collapsed {
        --ph-sidebar-width:var(--ph-sidebar-collapsed-width);
    }
    .app-shell-sidebar {
        position:relative;
        flex:0 0 var(--ph-sidebar-width); width:var(--ph-sidebar-width); max-width:var(--ph-sidebar-width); color:#334155; padding:10px 10px;
        display:flex; flex-direction:column; gap:8px; box-sizing:border-box; overflow:hidden;
        height:100vh;
        max-height:100vh;
        color:#0f172a;
        background:#ffffff;
        border-right:1px solid #e2e8f0;
        box-shadow:none;
        transition:flex-basis .24s ease, width .24s ease, max-width .24s ease, padding .24s ease, box-shadow .24s ease;
    }
    .brand-panel { flex:0 0 auto; padding:2px 4px 8px; border-bottom:1px solid #e2e8f0; }
    .brand-mark {
        display:flex;
        flex-direction:row;
        align-items:center;
        justify-content:flex-start;
        gap:8px;
        min-width:0;
        text-align:left;
    }
    .brand-badge {
        display:inline-flex;
        align-items:center;
        justify-content:center;
        flex:0 0 auto;
        width:100%;
        max-width:210px;
        padding:16px 18px;
        border-radius:24px;
        border:1px solid rgba(223,231,243,.92);
        background:rgba(255,255,255,.98);
        box-shadow:0 10px 24px rgba(18,29,74,.10), inset 0 1px 0 rgba(255,255,255,.88);
    }
    .brand-logo {
        width:auto;
        height:auto;
        max-width:190px;
        max-height:60px;
        object-fit:contain;
        display:block;
        flex:0 0 auto;
        filter:none;
    }
    .brand-copy { min-width:0; display:grid; gap:4px; justify-items:start; }
    .brand-title-lockup {
        display:flex;
        align-items:center;
        justify-content:flex-start;
        gap:6px;
        flex-wrap:wrap;
    }
    .brand-title {
        font-family:var(--ph-font-heading);
        font-size:15px;
        font-weight:800;
        letter-spacing:-.04em;
        color:#0f172a;
        line-height:1.02;
    }
    .brand-title-tag {
        display:inline-flex;
        align-items:center;
        justify-content:center;
        min-height:21px;
        padding:0 8px;
        border-radius:999px;
        border:1px solid #c7d2fe;
        background:#eef2ff;
        color:#4f46e5;
        font-family:var(--ph-font-heading);
        font-size:10px;
        font-weight:800;
        letter-spacing:.12em;
        text-transform:uppercase;
        line-height:1;
    }
    .brand-subtitle { max-width:156px; font-size:10px; line-height:1.3; color:#64748b; }
    .sidebar-shell-toggle {
        display:flex;
        align-items:center;
        justify-content:center;
        gap:8px;
        width:100%;
        min-height:30px;
        margin-top:10px;
        padding:6px 9px;
        border-radius:10px;
        border:1px solid #e2e8f0;
        background:#f8fafc;
        color:#475569;
        font-size:10.5px;
        font-weight:800;
        letter-spacing:.05em;
        text-transform:uppercase;
        cursor:pointer;
        transition:background .16s ease, border-color .16s ease, transform .16s ease, box-shadow .16s ease;
    }
    .sidebar-shell-toggle:hover {
        background:#ffffff;
        border-color:#cbd5e1;
        transform:translateY(-1px);
    }
    .sidebar-shell-toggle:focus-visible {
        outline:none;
        border-color:#c7d2fe;
        box-shadow:0 0 0 4px rgba(199,210,254,.28);
    }
    .sidebar-shell-toggle svg {
        width:16px;
        height:16px;
        flex:0 0 16px;
        transition:transform .2s ease;
    }
    .app-shell.is-sidebar-collapsed .sidebar-shell-toggle svg {
        transform:rotate(180deg);
    }
    .sidebar-shell-toggle-label {
        white-space:nowrap;
    }
    .sidebar-scroll {
        flex:1 1 auto;
        min-height:0;
        overflow-y:auto;
        overflow-x:hidden;
        padding-right:8px;
        scrollbar-width:thin;
        scrollbar-color:#c8d7ea transparent;
    }
    .sidebar-scroll::-webkit-scrollbar {
        width:5px;
    }
    .sidebar-scroll::-webkit-scrollbar-thumb {
        background:#c8d7ea;
        border-radius:999px;
    }
    .sidebar-scroll::-webkit-scrollbar-track {
        background:transparent;
    }
    .sidebar-section-title {
        font-size:10px; letter-spacing:.14em; text-transform:uppercase; font-weight:800;
    }
    .sidebar-nav { display:flex; flex-direction:column; gap:3px; }
    .sidebar-group {
        display:grid;
        gap:6px;
    }
    .sidebar-section-panel {
        display:grid;
        gap:6px;
    }
    .sidebar-link {
        position:relative; display:flex; align-items:center; gap:10px; padding:6px 10px 6px 10px; border-radius:10px;
        text-decoration:none; font-size:12px; font-weight:700; color:#475569;
        border:1px solid transparent; transition:background-color .16s ease, color .16s ease, border-color .16s ease, box-shadow .16s ease;
        overflow:hidden;
    }
    .sidebar-link:hover { background:#f8fafc; color:#0f172a; border-color:#e2e8f0; }
    .sidebar-link.is-active {
        color:#312e81; background:#eef2ff;
        border-color:#c7d2fe; box-shadow:none;
    }
    .sidebar-link.is-admin-active {
        color:#312e81; background:#f5f3ff;
        border-color:#ddd6fe;
    }
    .sidebar-link.is-active::before,
    .sidebar-link.is-admin-active::before {
        content:"";
        position:absolute;
        left:0;
        top:6px;
        bottom:6px;
        width:3px;
        border-radius:999px;
        background:#4f46e5;
        opacity:1;
        visibility:visible;
    }
    .sidebar-icon {
        width:22px; height:22px; border-radius:7px; display:grid; place-items:center; flex:0 0 22px;
        color:var(--ph-icon-tone, #64748b); background:var(--ph-icon-tone-soft, #f8fafc); border:1px solid var(--ph-icon-tone-border, #e2e8f0);
    }
    .sidebar-icon svg { width:14px; height:14px; }
    .sidebar-link.is-active .sidebar-icon,
    .sidebar-link.is-admin-active .sidebar-icon { color:#4f46e5; background:#ffffff; border-color:#c7d2fe; }
    .sidebar-label {
        flex:1 1 auto;
        min-width:0;
        overflow:hidden;
        text-overflow:ellipsis;
        white-space:nowrap;
        transition:opacity .16s ease;
    }
    .sidebar-link-badge {
        margin-left:auto;
        min-width:20px;
        height:18px;
        padding:0 6px;
        border-radius:999px;
        display:inline-flex;
        align-items:center;
        justify-content:center;
        background:#fee2e2;
        color:#b91c1c;
        font-size:10px;
        font-weight:800;
        line-height:1;
        box-shadow:0 8px 16px rgba(179,13,35,.18);
        transform:none;
        flex:0 0 auto;
        align-self:center;
    }
    .sidebar-link.is-active .sidebar-link-badge,
    .sidebar-link.is-admin-active .sidebar-link-badge {
        background:#ffffff;
        color:#4f46e5;
        box-shadow:none;
    }
    .sidebar-link::after,
    .sidebar-link::before {
        opacity:0;
        visibility:hidden;
        pointer-events:none;
        transition:opacity .16s ease, transform .16s ease, visibility .16s ease;
    }
    .sidebar-link::after {
        content:attr(data-sidebar-tooltip);
        position:absolute;
        left:calc(100% + 14px);
        top:50%;
        transform:translateY(-50%) translateX(-4px);
        padding:8px 10px;
        border-radius:12px;
        border:1px solid #dbe3ef;
        background:rgba(255,255,255,.98);
        color:#0f172a;
        box-shadow:0 18px 32px rgba(15,23,42,.18);
        white-space:nowrap;
        font-size:12px;
        font-weight:700;
        line-height:1.2;
        z-index:260;
    }
    .sidebar-link::before {
        content:"";
        position:absolute;
        left:calc(100% + 8px);
        top:50%;
        width:10px;
        height:10px;
        transform:translateY(-50%) rotate(45deg);
        border-left:1px solid #dbe3ef;
        border-top:1px solid #dbe3ef;
        background:rgba(255,255,255,.98);
        z-index:259;
    }
    .app-shell.is-sidebar-collapsed .sidebar-link:hover::after,
    .app-shell.is-sidebar-collapsed .sidebar-link:hover::before,
    .app-shell.is-sidebar-collapsed .sidebar-link:focus-visible::after,
    .app-shell.is-sidebar-collapsed .sidebar-link:focus-visible::before {
        opacity:1;
        visibility:visible;
    }
    .app-shell.is-sidebar-collapsed .sidebar-link:hover::after,
    .app-shell.is-sidebar-collapsed .sidebar-link:focus-visible::after {
        transform:translateY(-50%) translateX(0);
    }
    .sidebar-section {
        display:flex; flex-direction:column; gap:4px; margin-top:4px; padding-top:10px;
        border-top:1px solid #eef2f7;
    }
    .sidebar-section-title { padding:0 10px; color:#94a3b8; font-size:10px; }
    .sidebar-section-toggle {
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:8px;
        width:100%;
        padding:6px 9px;
        border-radius:10px;
        color:#1e293b;
        background:#f8fafc;
        border:1px solid #e2e8f0;
        font-size:10.5px;
        font-weight:800;
        letter-spacing:.05em;
        text-transform:uppercase;
        cursor:pointer;
        list-style:none;
        transition:background .16s ease, border-color .16s ease, color .16s ease;
    }
    .sidebar-section-toggle:hover {
        background:#ffffff;
        border-color:#cbd5e1;
    }
    .sidebar-section-toggle::-webkit-details-marker { display:none; }
    .sidebar-section-toggle svg {
        width:14px;
        height:14px;
        flex:0 0 14px;
        transition:transform .16s ease;
    }
    .sidebar-section-toggle[aria-expanded="true"] svg {
        transform:rotate(180deg);
    }
    .sidebar-section-toggle span {
        min-width:0;
    }
    .sidebar-panel {
        display:grid;
        gap:4px;
        overflow:hidden;
        max-height:none;
        opacity:1;
        transition:opacity .12s ease;
    }
    .sidebar-panel[hidden] {
        display:none;
    }
    .sidebar-group.is-collapsed .sidebar-panel {
        opacity:.4;
    }
    .app-shell.is-sidebar-collapsed .app-shell-sidebar {
        padding-inline:8px;
        box-shadow:none;
    }
    .app-shell.is-sidebar-collapsed .brand-panel {
        padding-inline:2px;
        padding-bottom:8px;
    }
    .app-shell.is-sidebar-collapsed .brand-mark {
        gap:8px;
    }
    .app-shell.is-sidebar-collapsed .brand-badge {
        max-width:56px;
        padding:11px;
        border-radius:18px;
    }
    .app-shell.is-sidebar-collapsed .brand-logo {
        max-width:34px;
        max-height:34px;
    }
    .app-shell.is-sidebar-collapsed .brand-copy,
    .app-shell.is-sidebar-collapsed .sidebar-section-toggle,
    .app-shell.is-sidebar-collapsed .sidebar-section-title {
        display:none;
    }
    .app-shell.is-sidebar-collapsed .sidebar-shell-toggle {
        width:36px;
        min-height:36px;
        margin:8px auto 0;
        padding:0;
        border-radius:12px;
    }
    .app-shell.is-sidebar-collapsed .sidebar-shell-toggle-label {
        display:none;
    }
    .app-shell.is-sidebar-collapsed .sidebar-section {
        gap:6px;
        margin-top:6px;
        padding-top:10px;
    }
    .app-shell.is-sidebar-collapsed .sidebar-group .sidebar-panel,
    .app-shell.is-sidebar-collapsed .sidebar-group .sidebar-panel[hidden] {
        display:grid !important;
        opacity:1 !important;
        max-height:none !important;
        overflow:visible;
    }
    .app-shell.is-sidebar-collapsed .sidebar-nav {
        gap:6px;
    }
    .app-shell.is-sidebar-collapsed .sidebar-link {
        justify-content:center;
        gap:0;
        min-height:38px;
        padding:7px 0;
    }
    .app-shell.is-sidebar-collapsed .sidebar-link:hover {
        transform:none;
    }
    .app-shell.is-sidebar-collapsed .sidebar-label {
        opacity:0;
        width:0;
        max-width:0;
        margin:0;
    }
    .app-shell.is-sidebar-collapsed .sidebar-link-badge {
        position:absolute;
        top:3px;
        right:5px;
        margin-left:0;
        min-width:16px;
        height:16px;
        padding:0 4px;
        font-size:9px;
        transform:none;
    }
    .app-shell-main {
        flex:1 1 0;
        min-width:0;
        width:auto;
        max-width:calc(100vw - var(--ph-sidebar-width));
        padding:20px 22px;
        overflow-x:hidden;
        box-sizing:border-box;
        transition:none;
    }
    .app-shell-main.is-focused-form {
        padding-top:10px;
    }
    .app-shell-topbar {
        position:sticky;
        top:14px;
        z-index:120;
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:12px;
        flex-wrap:nowrap;
        margin-bottom:12px;
        padding:10px 12px;
        border:1px solid #e2e8f0;
        border-radius:14px;
        background:rgba(255,255,255,.96);
        box-shadow:0 8px 18px rgba(15,23,42,.04);
        backdrop-filter:blur(18px);
    }
    .app-shell-topbar-left {
        display:flex;
        align-items:center;
        gap:14px;
        flex:1 1 0%;
        min-width:0;
    }
    .app-shell-topbar-right {
        display:flex;
        align-items:center;
        justify-content:flex-end;
        gap:10px;
        flex:0 1 auto;
        flex-wrap:nowrap;
        min-width:0;
        white-space:nowrap;
    }
    .app-shell-search {
        flex:1 1 auto;
        min-width:220px;
        max-width:none;
        display:flex;
        align-items:center;
        gap:10px;
        min-height:42px;
        padding:0 13px;
        border:1px solid #dbe3ef;
        border-radius:14px;
        background:#f8fafc;
        color:#64748b;
    }
    .app-shell-search:focus-within {
        border-color:#93c5fd;
        background:#ffffff;
        box-shadow:0 0 0 4px rgba(37,99,235,.10);
    }
    .app-shell-search svg {
        width:16px;
        height:16px;
        color:#94a3b8;
        flex:0 0 16px;
    }
    .app-shell-search input {
        appearance:none;
        -webkit-appearance:none;
        width:100%;
        border:0;
        background:transparent;
        color:#0f172a;
        font-size:12px;
        line-height:1.4;
        padding:0;
        outline:none;
        min-width:0;
        overflow:hidden;
        text-overflow:ellipsis;
        outline:none;
        box-shadow:none;
        padding:0;
        caret-color:#2563eb;
    }
    .app-shell-search input::placeholder {
        color:#94a3b8;
    }
    .app-shell-search input:focus {
        outline:none;
        box-shadow:none;
        border:0;
    }
    .topbar-chip {
        display:inline-flex;
        align-items:center;
        gap:8px;
        min-height:34px;
        padding:7px 10px;
        border-radius:10px;
        border:1px solid #e2e8f0;
        background:#fff;
        color:#334155;
        font-size:10.5px;
        font-weight:800;
        line-height:1;
        flex:0 0 auto;
        white-space:nowrap;
    }
    .topbar-chip.is-role {
        background:#eef2ff;
        color:#4338ca;
        border-color:#c7d2fe;
        max-width:140px;
        overflow:hidden;
        text-overflow:ellipsis;
    }
    .quick-add-menu {
        position:relative;
        flex:0 0 auto;
    }
    .quick-add-trigger {
        min-width:108px;
        list-style:none;
        cursor:pointer;
    }
    .quick-add-trigger span {
        overflow:hidden;
        text-overflow:ellipsis;
        white-space:nowrap;
    }
    .quick-add-trigger::-webkit-details-marker {
        display:none;
    }
    .quick-add-panel {
        position:absolute;
        top:calc(100% + 8px);
        right:0;
        z-index:var(--ph-z-dropdown, 60);
        width:min(240px, 92vw);
        padding:8px;
        border-radius:16px;
        border:1px solid #dbe3ef;
        background:#fff;
        box-shadow:0 20px 44px rgba(15,23,42,.14);
        display:grid;
        gap:4px;
    }
    .quick-add-link {
        display:flex;
        align-items:center;
        gap:10px;
        min-height:40px;
        padding:9px 11px;
        border-radius:12px;
        color:#0f172a;
        text-decoration:none;
        font-size:12px;
        font-weight:700;
    }
    .quick-add-link:hover {
        background:#eff6ff;
        color:#1d4ed8;
    }
    .quick-add-icon {
        width:26px;
        height:26px;
        flex:0 0 26px;
        display:grid;
        place-items:center;
        border-radius:10px;
        background:var(--ph-icon-tone-soft, #eff6ff);
        color:var(--ph-icon-tone, #1d4ed8);
        border:1px solid var(--ph-icon-tone-border, #bfdbfe);
    }
    .quick-add-icon svg {
        width:15px;
        height:15px;
    }
    .topbar-notification-menu,
    .topbar-bell-menu {
        position:relative;
        flex:0 0 auto;
    }
    .topbar-action-link {
        width:34px;
        height:34px;
        border-radius:10px;
        display:grid;
        place-items:center;
        border:1px solid #e2e8f0;
        background:#fff;
        color:var(--ph-icon-tone, #334155);
        text-decoration:none;
        box-shadow:0 6px 16px rgba(15,23,42,.03);
        transition:border-color .16s ease, color .16s ease, background .16s ease, transform .16s ease, box-shadow .16s ease;
        flex:0 0 auto;
    }
    .topbar-action-link:hover {
        border-color:#bfdbfe;
        background:#eff6ff;
        color:#1d4ed8;
        transform:translateY(-1px);
        box-shadow:0 14px 28px rgba(37,99,235,.12);
    }
    .topbar-action-link:focus-visible {
        outline:none;
        border-color:#93c5fd;
        box-shadow:0 0 0 4px rgba(37,99,235,.12);
    }
    .topbar-action-link.is-active {
        border-color:#bfdbfe;
        background:#eff6ff;
        color:#1d4ed8;
    }
    .topbar-action-link svg {
        width:17px;
        height:17px;
    }
    .topbar-bell-trigger {
        width:36px;
        height:36px;
        border-radius:12px;
        display:grid;
        place-items:center;
        border:1px solid #dbe3ef;
        background:#fff;
        color:var(--ph-icon-tone, #334155);
        cursor:pointer;
        list-style:none;
        position:relative;
        overflow:visible;
    }
    .topbar-bell-trigger::-webkit-details-marker {
        display:none;
    }
    .topbar-bell-badge {
        position:absolute;
        top:-7px;
        right:-7px;
        min-width:18px;
        height:18px;
        padding:0 5px;
        border-radius:999px;
        display:inline-flex;
        align-items:center;
        justify-content:center;
        background:#dc2626;
        color:#fff;
        font-size:10px;
        font-weight:800;
        line-height:1;
        box-shadow:0 8px 18px rgba(220,38,38,.22);
        z-index:2;
    }
    .topbar-bell-panel {
        position:absolute;
        top:calc(100% + 10px);
        right:0;
        z-index:var(--ph-z-dropdown, 60);
        width:min(340px, calc(100vw - 28px));
        padding:10px;
        border-radius:16px;
        border:1px solid #dbe3ef;
        background:#fff;
        box-shadow:0 24px 52px rgba(15,23,42,.16);
        display:grid;
        gap:8px;
    }
    .topbar-bell-head {
        display:flex;
        align-items:flex-start;
        justify-content:space-between;
        gap:10px;
        padding:4px 6px 8px;
        border-bottom:1px solid #eef2f7;
    }
    .topbar-bell-head strong {
        color:#0f172a;
        font-size:14px;
    }
    .topbar-bell-head span {
        color:#64748b;
        font-size:12px;
    }
    .topbar-bell-head-actions {
        display:flex;
        align-items:center;
        gap:8px;
        flex-wrap:wrap;
        justify-content:flex-end;
    }
    .topbar-bell-mark-all {
        min-height:30px;
        padding:6px 10px;
        border-radius:999px;
        border:1px solid #dbe3ef;
        background:#fff;
        color:#1d4ed8;
        font-size:11px;
        font-weight:800;
        cursor:pointer;
    }
    .topbar-bell-list {
        display:grid;
        gap:6px;
    }
    .topbar-bell-item {
        display:flex;
        align-items:flex-start;
        justify-content:space-between;
        gap:10px;
        padding:9px 11px;
        border-radius:12px;
        border:1px solid #e2e8f0;
        background:#f8fafc;
        text-decoration:none;
        color:#0f172a;
        transition:background .16s ease, border-color .16s ease, transform .16s ease;
    }
    .topbar-bell-item.is-unread {
        border-color:#bfdbfe;
        background:#eff6ff;
    }
    .topbar-bell-item:hover {
        background:#ffffff;
        border-color:#bfdbfe;
        transform:translateY(-1px);
    }
    .topbar-bell-item strong {
        display:block;
        font-size:12px;
        line-height:1.35;
        color:#0f172a;
    }
    .topbar-bell-item small {
        display:block;
        margin-top:3px;
        font-size:11px;
        line-height:1.45;
        color:#64748b;
    }
    .topbar-bell-meta {
        display:flex;
        align-items:center;
        gap:6px;
        flex-wrap:wrap;
        margin-top:6px;
    }
    .topbar-bell-time {
        color:#64748b;
        font-size:11px;
        font-weight:700;
    }
    .topbar-bell-priority {
        display:inline-flex;
        align-items:center;
        min-height:20px;
        padding:0 7px;
        border-radius:999px;
        font-size:10px;
        font-weight:800;
        letter-spacing:.04em;
        text-transform:uppercase;
        background:#eff6ff;
        color:#1d4ed8;
    }
    .topbar-bell-priority.is-medium {
        background:#fffbeb;
        color:#b45309;
    }
    .topbar-bell-priority.is-high,
    .topbar-bell-priority.is-urgent {
        background:#fff1f2;
        color:#b91c1c;
    }
    .topbar-bell-count {
        display:inline-flex;
        align-items:center;
        justify-content:center;
        min-width:28px;
        height:28px;
        padding:0 8px;
        border-radius:999px;
        font-size:12px;
        font-weight:800;
        line-height:1;
        background:#eff6ff;
        color:#1d4ed8;
        flex:0 0 auto;
    }
    .topbar-bell-count.is-warning { background:#fffbeb; color:#b45309; }
    .topbar-bell-count.is-danger { background:#fff1f2; color:#b91c1c; }
    .topbar-bell-count.is-muted { background:#f8fafc; color:#475569; }
    .topbar-bell-empty {
        padding:14px 12px;
        border-radius:14px;
        border:1px dashed #dbe3ef;
        color:#64748b;
        font-size:12px;
        line-height:1.5;
        background:#f8fafc;
    }
    .topbar-bell-footer {
        padding-top:4px;
        border-top:1px solid #eef2f7;
    }
    .topbar-bell-footer a {
        display:inline-flex;
        align-items:center;
        gap:8px;
        min-height:38px;
        padding:9px 12px;
        border-radius:12px;
        color:#1d4ed8;
        background:#eff6ff;
        text-decoration:none;
        font-size:12px;
        font-weight:800;
    }
    .topbar-bell-settings {
        display:grid;
        gap:10px;
        padding:10px 12px;
        border-radius:14px;
        border:1px solid #e2e8f0;
        background:#fff;
    }
    .topbar-bell-settings-head {
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:10px;
    }
    .topbar-bell-settings-head strong {
        color:#0f172a;
        font-size:13px;
    }
    .topbar-bell-settings-head span {
        color:#64748b;
        font-size:11px;
    }
    .topbar-bell-switches {
        display:grid;
        gap:8px;
    }
    .topbar-bell-switch {
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:10px;
    }
    .topbar-bell-switch-copy {
        display:grid;
        gap:2px;
        min-width:0;
    }
    .topbar-bell-switch-copy strong {
        color:#0f172a;
        font-size:12px;
    }
    .topbar-bell-switch-copy span {
        color:#64748b;
        font-size:11px;
        line-height:1.4;
    }
    .topbar-bell-toggle {
        position:relative;
        width:44px;
        height:26px;
        flex:0 0 44px;
    }
    .topbar-bell-toggle input {
        position:absolute;
        inset:0;
        opacity:0;
        cursor:pointer;
    }
    .topbar-bell-toggle-track {
        position:absolute;
        inset:0;
        border-radius:999px;
        background:#cbd5e1;
        transition:background .16s ease;
    }
    .topbar-bell-toggle-track::after {
        content:"";
        position:absolute;
        top:3px;
        left:3px;
        width:20px;
        height:20px;
        border-radius:50%;
        background:#fff;
        box-shadow:0 3px 8px rgba(15,23,42,.14);
        transition:transform .16s ease;
    }
    .topbar-bell-toggle input:checked + .topbar-bell-toggle-track {
        background:#2563eb;
    }
    .topbar-bell-toggle input:checked + .topbar-bell-toggle-track::after {
        transform:translateX(18px);
    }
    .topbar-bell-tools {
        display:flex;
        align-items:center;
        gap:8px;
        flex-wrap:wrap;
    }
    .topbar-bell-tool-button {
        min-height:32px;
        padding:7px 10px;
        border-radius:10px;
        border:1px solid #dbe3ef;
        background:#fff;
        color:#1d4ed8;
        font-size:11px;
        font-weight:800;
        cursor:pointer;
    }
    .topbar-bell-settings-hint {
        color:#64748b;
        font-size:11px;
        line-height:1.45;
    }
    .topbar-bell-tone-row {
        margin-top:10px;
    }
    .topbar-bell-tone-label {
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:12px;
        width:100%;
        color:#475569;
        font-size:12px;
        font-weight:700;
    }
    .topbar-bell-tone-label span {
        white-space:nowrap;
    }
    .topbar-bell-select {
        min-width:140px;
        border:1px solid rgba(148,163,184,.35);
        border-radius:12px;
        background:#fff;
        color:#0f172a;
        font:inherit;
        font-size:12px;
        font-weight:700;
        padding:8px 12px;
    }
    .topbar-bell-list-page {
        gap:12px;
    }
    .topbar-toast-stack {
        position:fixed;
        top:86px;
        right:18px;
        z-index:var(--ph-z-toast, 90);
        display:grid;
        gap:10px;
        width:min(340px, calc(100vw - 28px));
        pointer-events:none;
    }
    .topbar-toast {
        display:grid;
        gap:8px;
        padding:14px 16px;
        border-radius:18px;
        border:1px solid #dbe3ef;
        background:rgba(255,255,255,.98);
        box-shadow:0 24px 52px rgba(15,23,42,.16);
        pointer-events:auto;
    }
    .topbar-toast strong {
        color:#0f172a;
        font-size:13px;
        line-height:1.3;
    }
    .topbar-toast p {
        margin:0;
        color:#64748b;
        font-size:12px;
        line-height:1.5;
    }
    .topbar-toast a {
        display:inline-flex;
        align-items:center;
        justify-content:center;
        width:max-content;
        min-height:34px;
        padding:7px 12px;
        border-radius:10px;
        background:#0f172a;
        color:#fff;
        text-decoration:none;
        font-size:12px;
        font-weight:800;
    }
    .topbar-user-menu {
        position:relative;
        flex:0 1 220px;
        min-width:0;
    }
    .topbar-user-trigger {
        display:flex;
        align-items:center;
        gap:10px;
        width:100%;
        min-width:0;
        max-width:220px;
        padding:4px;
        border-radius:11px;
        border:1px solid #e2e8f0;
        background:#fff;
        cursor:pointer;
        list-style:none;
        box-shadow:0 10px 24px rgba(15,23,42,.04);
    }
    .topbar-user-trigger::-webkit-details-marker {
        display:none;
    }
    .topbar-user-trigger::after {
        content:"";
        width:8px;
        height:8px;
        border-right:2px solid #94a3b8;
        border-bottom:2px solid #94a3b8;
        transform:rotate(45deg);
        margin-right:4px;
        flex:0 0 8px;
        transition:transform .16s ease, border-color .16s ease;
    }
    .topbar-user-menu[open] .topbar-user-trigger::after {
        transform:rotate(-135deg);
        border-color:#2563eb;
    }
    .topbar-user-avatar {
        width:32px;
        height:32px;
        border-radius:11px;
        display:grid;
        place-items:center;
        background:linear-gradient(135deg, #0f766e 0%, #38bdf8 100%);
        color:#fff;
        font-size:12px;
        font-weight:900;
        letter-spacing:.04em;
    }
    .topbar-user-meta strong {
        display:block;
        color:#0f172a;
        font-size:11px;
        line-height:1.1;
        overflow:hidden;
        text-overflow:ellipsis;
        white-space:nowrap;
    }
    .topbar-user-meta span {
        display:block;
        margin-top:3px;
        color:#64748b;
        font-size:10px;
        line-height:1;
        overflow:hidden;
        text-overflow:ellipsis;
        white-space:nowrap;
    }
    .topbar-user-meta {
        min-width:0;
        overflow:hidden;
    }
    .topbar-user-panel {
        position:absolute;
        top:calc(100% + 10px);
        right:0;
        z-index:var(--ph-z-dropdown, 60);
        width:min(272px, calc(100vw - 28px));
        max-width:calc(100vw - 28px);
        padding:10px;
        box-sizing:border-box;
        border-radius:16px;
        border:1px solid #dbe3ef;
        background:#fff;
        box-shadow:0 24px 52px rgba(15,23,42,.16);
        display:grid;
        gap:8px;
        overflow:hidden;
    }
    @media (max-width: 1480px) {
        .app-shell-topbar {
            gap:10px;
            padding:10px 12px;
        }
        .app-shell-topbar-right {
            gap:8px;
        }
        .app-shell-search {
            min-width:180px;
        }
        .quick-add-trigger {
            min-width:96px;
        }
        .topbar-chip {
            padding:8px 10px;
        }
        .topbar-chip.is-role {
            max-width:120px;
        }
        .topbar-user-menu {
            flex-basis:190px;
        }
        .topbar-user-trigger {
            max-width:190px;
        }
    }
    @media (max-width: 1320px) {
        .app-shell-search {
            min-width:160px;
        }
        .topbar-chip.is-role {
            max-width:100px;
        }
        .topbar-user-menu {
            flex-basis:172px;
        }
        .topbar-user-trigger {
            gap:8px;
            max-width:172px;
        }
        .topbar-user-trigger::after {
            margin-right:2px;
        }
    }
    @media (max-width: 1180px) {
        .app-shell-topbar {
            flex-wrap:wrap;
            align-items:stretch;
        }
        .app-shell-topbar-left,
        .app-shell-topbar-right {
            width:100%;
        }
        .app-shell-topbar-right {
            justify-content:space-between;
            flex-wrap:wrap;
        }
        .app-shell-search {
            flex:1 1 100%;
            min-width:0;
            max-width:none;
        }
        .topbar-bell-panel,
        .topbar-user-panel {
            max-width:min(360px, calc(100vw - 44px));
        }
    }
    @media (max-width: 768px) {
        .topbar-toast-stack {
            top:auto;
            right:12px;
            left:12px;
            bottom:86px;
            width:auto;
        }
    }
    .topbar-user-head {
        display:grid;
        gap:3px;
        padding:8px 10px 10px;
        border-bottom:1px solid #eef2f7;
    }
    .topbar-user-head strong {
        display:block;
        min-width:0;
        color:#0f172a;
        font-size:14px;
        line-height:1.25;
        overflow:hidden;
        text-overflow:ellipsis;
        white-space:nowrap;
    }
    .topbar-user-head span {
        display:-webkit-box;
        min-width:0;
        color:#64748b;
        font-size:12px;
        line-height:1.35;
        overflow:hidden;
        word-break:break-word;
        -webkit-line-clamp:2;
        -webkit-box-orient:vertical;
    }
    .topbar-user-head {
        min-width:0;
        overflow:hidden;
    }
    .topbar-user-role {
        margin-top:4px;
        width:max-content;
        max-width:100%;
    }
    .topbar-user-link,
    .topbar-user-logout {
        display:flex;
        align-items:center;
        gap:10px;
        width:100%;
        min-height:42px;
        padding:10px 12px;
        box-sizing:border-box;
        border:none;
        border-radius:12px;
        background:#fff;
        color:#0f172a;
        text-decoration:none;
        text-align:left;
        font-size:13px;
        font-weight:700;
        cursor:pointer;
    }
    .topbar-user-link span:last-child,
    .topbar-user-logout span:last-child {
        min-width:0;
        overflow:hidden;
        text-overflow:ellipsis;
        white-space:nowrap;
    }
    .topbar-user-action-icon {
        width:32px;
        height:32px;
        flex:0 0 32px;
        display:inline-flex;
        align-items:center;
        justify-content:center;
        border-radius:11px;
        border:1px solid #dbe3ef;
    }
    .topbar-user-action-icon svg {
        width:20px;
        height:20px;
        flex:0 0 20px;
        display:block;
    }
    .topbar-user-link .topbar-user-action-icon {
        background:#f1f5f9;
        color:#475569;
        border-color:#e2e8f0;
    }
    .topbar-user-link:hover,
    .topbar-user-logout:hover {
        background:#f8fafc;
    }
    .topbar-user-link:hover .topbar-user-action-icon {
        color:#334155;
        background:#e2e8f0;
    }
    .topbar-user-logout {
        color:#b91c1c;
        background:#fff5f5;
    }
    .topbar-user-logout .topbar-user-action-icon {
        background:#fff1f2;
        color:#e11d48;
        border-color:#fecdd3;
    }
    .topbar-user-logout:hover {
        background:#fee2e2;
    }
    .topbar-user-logout:hover .topbar-user-action-icon {
        color:#be123c;
        background:#ffe4e6;
    }
    @media (max-width: 1280px) {
        .app-shell {
            --ph-sidebar-expanded-width:222px;
            --ph-sidebar-collapsed-width:80px;
        }
        .app-shell-sidebar { padding:14px 10px; }
        .app-shell-topbar { gap:10px; padding:10px 12px; }
        .app-shell-topbar-right { gap:8px; }
        .brand-badge { max-width:188px; padding:14px 14px; }
        .brand-logo { max-width:170px; max-height:52px; }
        .brand-title { font-size:19px; }
        .brand-title-tag { min-height:20px; padding:0 7px; font-size:9px; }
        .sidebar-link { padding:8px 9px; }
    }
    @media (max-width: 1024px) {
        .app-shell { flex-direction:column; min-height:100vh; overflow-x:hidden; }
        .app-shell-sidebar { display:none; }
        .app-shell-topbar { display:none; }
        .app-shell-main {
            padding:96px 12px calc(188px + env(safe-area-inset-bottom, 0px)) !important;
            min-height:100vh;
            max-width:100vw !important;
        }
        .desktop-breadcrumb { display:none !important; }
    }
    .mobile-topbar,
    .mobile-back-row,
    .mobile-bottom-nav,
    .mobile-more-backdrop {
        display:none;
    }
    .mobile-fab,
    .mobile-sticky-actions {
        display:none;
    }
    @media (max-width: 767px) {
        .mobile-topbar {
            position:fixed;
            top:0;
            left:0;
            right:0;
            z-index:900;
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:10px;
            min-height:58px;
            padding:9px 12px;
            box-sizing:border-box;
            background:rgba(255,255,255,.96);
            border-bottom:1px solid #dbe3ef;
            backdrop-filter:blur(14px);
        }
        .mobile-topbar-actions {
            display:flex;
            align-items:center;
            gap:8px;
            flex:0 0 auto;
        }
        .mobile-back-row {
            position:fixed;
            top:58px;
            left:0;
            right:0;
            z-index:911;
            display:flex;
            justify-content:flex-start;
            padding:6px 12px 0;
            pointer-events:none;
        }
        .mobile-back-link {
            pointer-events:auto;
            display:inline-flex;
            align-items:center;
            gap:6px;
            min-height:30px;
            max-width:100%;
            padding:6px 10px;
            border-radius:999px;
            border:1px solid rgba(226,232,240,.96);
            background:rgba(255,255,255,.96);
            color:#334155;
            text-decoration:none;
            font-size:12px;
            font-weight:800;
            box-shadow:0 10px 26px rgba(15,23,42,.08);
        }
        .mobile-back-link svg {
            width:16px;
            height:16px;
            flex:0 0 16px;
        }
        .mobile-brand {
            min-width:0;
            display:flex;
            align-items:center;
            gap:12px;
            text-decoration:none;
            color:#0f172a;
            flex:0 1 auto;
        }
        .mobile-brand-badge {
            display:inline-flex;
            align-items:center;
            justify-content:center;
            flex:0 0 auto;
            padding:8px 10px;
            border-radius:16px;
            border:1px solid #dbe3ef;
            background:#ffffff;
            box-shadow:0 12px 28px rgba(15,23,42,.08);
        }
        .mobile-brand-logo {
            width:auto;
            height:auto;
            max-width:126px;
            max-height:36px;
            object-fit:contain;
            flex:0 0 auto;
        }
        .mobile-brand-copy {
            min-width:0;
            display:grid;
            gap:3px;
        }
        .mobile-brand-copy strong {
            font-size:15px;
            line-height:1.05;
            letter-spacing:-.03em;
            color:#0f172a;
        }
        .mobile-brand-copy small {
            font-size:10.5px;
            line-height:1.2;
            color:#64748b;
            white-space:nowrap;
            overflow:hidden;
            text-overflow:ellipsis;
        }
        .mobile-topbar-title {
            min-width:0;
            display:grid;
            gap:1px;
            flex:1 1 auto;
        }
        .mobile-topbar-title strong {
            color:#0f172a;
            font-size:15px;
            line-height:1.2;
            overflow:hidden;
            text-overflow:ellipsis;
            white-space:nowrap;
        }
        .mobile-topbar-title span {
            color:#64748b;
            font-size:11px;
            overflow:hidden;
            text-overflow:ellipsis;
            white-space:nowrap;
        }
        .mobile-icon-button {
            width:40px;
            height:40px;
            border-radius:14px;
            border:1px solid #cbd5e1;
            background:#fff;
            color:#0f172a;
            display:inline-grid;
            place-items:center;
            cursor:pointer;
            box-shadow:0 8px 18px rgba(15,23,42,.06);
        }
        .mobile-notification-menu {
            position:relative;
            flex:0 0 auto;
        }
        .mobile-notification-trigger {
            width:40px;
            height:40px;
            border-radius:14px;
            border:1px solid #cbd5e1;
            box-shadow:0 8px 18px rgba(15,23,42,.06);
        }
        .mobile-notification-backdrop {
            display:none;
        }
        .mobile-notification-panel {
            position:fixed;
            inset:0;
            width:100vw;
            max-width:none;
            height:100dvh;
            max-height:100dvh;
            display:flex;
            flex-direction:column;
            overflow:hidden;
            border-radius:0;
            padding:0;
            border:none;
            box-shadow:none;
            background:#fff;
            z-index:1090;
        }
        .mobile-notification-menu[open] .mobile-notification-backdrop {
            display:block;
            position:fixed;
            inset:0;
            background:rgba(15,23,42,.46);
            z-index:1080;
        }
        .mobile-notification-panel .topbar-bell-head {
            position:sticky;
            top:0;
            z-index:2;
            background:#fff;
            padding:calc(12px + env(safe-area-inset-top, 0px)) 12px 10px;
            border-bottom:1px solid #e2e8f0;
        }
        .mobile-notification-panel .topbar-bell-head-actions {
            display:flex;
            align-items:center;
            gap:8px;
            flex:0 0 auto;
        }
        .mobile-notification-scroll {
            flex:1 1 auto;
            min-height:0;
            overflow-y:auto;
            overflow-x:hidden;
            padding:10px 12px 14px;
            display:grid;
            gap:8px;
            -webkit-overflow-scrolling:touch;
        }
        .mobile-notification-panel .topbar-bell-list {
            max-height:none;
            overflow:visible;
            padding-right:0;
            gap:8px;
        }
        .mobile-notification-panel .topbar-bell-item {
            min-width:0;
            padding:10px 11px;
            gap:8px;
            align-items:flex-start;
        }
        .mobile-notification-panel .topbar-bell-item > div {
            min-width:0;
        }
        .mobile-notification-panel .topbar-bell-item strong {
            display:block;
            font-size:13px;
            line-height:1.35;
        }
        .mobile-notification-panel .topbar-bell-item small {
            margin-top:3px;
            font-size:12px;
            line-height:1.45;
            word-break:break-word;
        }
        .mobile-notification-panel .topbar-bell-meta {
            margin-top:8px;
            gap:8px;
            flex-wrap:wrap;
        }
        .mobile-notification-panel .topbar-bell-count {
            margin-left:auto;
            flex:0 0 auto;
        }
        .mobile-notification-panel .topbar-bell-empty {
            margin:0;
            font-size:12px;
            line-height:1.5;
        }
        .mobile-notification-close {
            width:34px;
            height:34px;
            min-height:34px;
            border-radius:12px;
            box-shadow:none;
            flex:0 0 34px;
        }
        .mobile-notification-helper {
            color:#64748b;
            font-size:10.5px;
            line-height:1.5;
            padding:9px 10px;
            border-radius:12px;
            background:#f8fafc;
            border:1px solid #e2e8f0;
        }
        .mobile-notification-settings {
            margin-top:0;
        }
        .mobile-notification-panel .topbar-bell-settings {
            padding:9px 10px;
            gap:8px;
        }
        .mobile-notification-panel .topbar-bell-switch {
            align-items:flex-start;
            gap:12px;
        }
        .mobile-notification-panel .topbar-bell-switch-copy {
            min-width:0;
        }
        .mobile-notification-panel .topbar-bell-switch-copy strong {
            font-size:12px;
        }
        .mobile-notification-panel .topbar-bell-switch-copy span {
            font-size:11px;
            line-height:1.45;
        }
        .mobile-notification-panel .topbar-bell-tools {
            grid-template-columns:repeat(2, minmax(0, 1fr));
        }
        .mobile-notification-panel .topbar-bell-tool-button,
        .mobile-notification-panel .topbar-bell-footer a,
        .mobile-notification-footer .topbar-bell-mark-all {
            min-height:38px;
            justify-content:center;
            font-size:12px;
        }
        .mobile-notification-panel .topbar-bell-tone-row {
            margin-top:0;
        }
        .mobile-notification-panel .topbar-bell-tone-label {
            gap:6px;
        }
        .mobile-notification-panel .topbar-bell-select {
            width:100%;
            min-height:38px;
        }
        .mobile-notification-footer {
            flex:0 0 auto;
            display:grid;
            grid-template-columns:repeat(2, minmax(0, 1fr));
            gap:8px;
            padding:10px 12px calc(10px + env(safe-area-inset-bottom, 0px));
            border-top:1px solid #e2e8f0;
            background:#fff;
        }
        .mobile-notification-footer > * {
            min-width:0;
        }
        .mobile-notification-footer .topbar-bell-mark-all {
            width:100%;
            margin:0;
        }
        .mobile-notification-footer .topbar-bell-footer {
            padding:0;
            border-top:none;
        }
        .mobile-notification-footer .topbar-bell-footer a {
            width:100%;
        }
        .mobile-topbar-search {
            min-width:0;
            flex:1 1 140px;
            display:flex;
            align-items:center;
            gap:8px;
            min-height:40px;
            padding:0 12px;
            border-radius:14px;
            border:1px solid #dbe3ef;
            background:#fff;
            color:#94a3b8;
            font-size:12px;
            font-weight:700;
            white-space:nowrap;
            overflow:hidden;
            text-overflow:ellipsis;
            cursor:pointer;
        }
        .mobile-topbar-search svg {
            width:14px;
            height:14px;
            flex:0 0 14px;
        }
        .mobile-bottom-nav {
            position:fixed;
            left:10px;
            right:10px;
            bottom:10px;
            z-index:910;
            display:grid;
            grid-template-columns:repeat(5, minmax(0, 1fr));
            gap:4px;
            padding:5px;
            border-radius:18px;
            background:rgba(255,255,255,.96);
            border:1px solid #e2e8f0;
            box-shadow:0 14px 34px rgba(15,23,42,.16);
            backdrop-filter:blur(16px);
            pointer-events:auto;
            max-width:calc(100vw - 20px);
            padding-bottom:calc(6px + env(safe-area-inset-bottom, 0px));
        }
        .mobile-nav-item {
            min-width:0;
            border:0;
            background:transparent;
            color:#64748b;
            text-decoration:none;
            display:grid;
            place-items:center;
            gap:2px;
            padding:6px 3px;
            border-radius:12px;
            font-size:9px;
            font-weight:800;
            line-height:1.1;
            cursor:pointer;
        }
        .mobile-nav-item svg {
            width:17px;
            height:17px;
        }
        .mobile-nav-item.is-active {
            background:#eef2ff;
            color:#4338ca;
        }
        .mobile-nav-item.is-disabled {
            opacity:.45;
            cursor:not-allowed;
        }
        .mobile-more-backdrop {
            position:fixed;
            inset:0;
            z-index:1000;
            background:rgba(15,23,42,.46);
            align-items:flex-end;
            padding:12px;
            box-sizing:border-box;
            pointer-events:none;
        }
        .mobile-more-backdrop.is-open {
            display:flex;
            pointer-events:auto;
        }
        .mobile-more-sheet {
            width:100%;
            max-height:78vh;
            overflow:auto;
            border-radius:24px;
            background:#fff;
            border:1px solid #dbe3ef;
            box-shadow:0 28px 70px rgba(15,23,42,.32);
            padding:14px;
            box-sizing:border-box;
        }
        .mobile-more-header {
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:10px;
            padding:2px 2px 12px;
            border-bottom:1px solid #edf2f7;
            margin-bottom:10px;
        }
        .mobile-more-header strong {
            color:#0f172a;
            font-size:16px;
        }
        .mobile-more-grid {
            display:grid;
            grid-template-columns:repeat(2, minmax(0, 1fr));
            gap:8px;
        }
        .mobile-more-footer {
            display:grid;
            gap:10px;
            margin-top:14px;
            padding-top:14px;
            border-top:1px solid #e2e8f0;
        }
        .mobile-more-user {
            display:flex;
            align-items:center;
            gap:10px;
            padding:10px 12px;
            border-radius:16px;
            background:#f8fafc;
            border:1px solid #e2e8f0;
        }
        .mobile-more-user strong {
            display:block;
            color:#0f172a;
            font-size:13px;
        }
        .mobile-more-user span {
            display:block;
            margin-top:2px;
            color:#64748b;
            font-size:11px;
        }
        .mobile-more-user-avatar {
            width:38px;
            height:38px;
            flex:0 0 38px;
            border-radius:14px;
            display:grid;
            place-items:center;
            background:linear-gradient(135deg, #0f766e 0%, #38bdf8 100%);
            color:#fff;
            font-size:12px;
            font-weight:900;
            letter-spacing:.04em;
        }
        .mobile-more-actions {
            display:grid;
            gap:8px;
        }
        .mobile-more-action,
        .mobile-more-logout {
            display:flex;
            align-items:center;
            gap:10px;
            width:100%;
            min-height:46px;
            padding:10px 12px;
            border-radius:14px;
            border:1px solid #e2e8f0;
            background:#fff;
            color:#0f172a;
            text-decoration:none;
            font-size:13px;
            font-weight:800;
        }
        .mobile-more-logout {
            color:#b91c1c;
            background:#fff5f5;
            border-color:#fecaca;
        }
        .mobile-more-link {
            display:flex;
            align-items:center;
            gap:10px;
            min-height:48px;
            padding:10px;
            border-radius:16px;
            text-decoration:none;
            color:#0f172a;
            background:#f8fafc;
            border:1px solid #e2e8f0;
            font-size:13px;
            font-weight:800;
        }
        .mobile-more-link.is-active {
            color:#fff;
            background:linear-gradient(135deg, #4f46e5, #5b4ce0);
            border-color:#4f46e5;
        }
        .mobile-more-link.is-disabled {
            color:#94a3b8;
            cursor:not-allowed;
        }
    .mobile-more-icon {
        width:30px;
        height:30px;
        display:grid;
        place-items:center;
        border-radius:12px;
        background:var(--ph-icon-tone-soft, #fff);
        color:var(--ph-icon-tone, #0f766e);
        border:1px solid var(--ph-icon-tone-border, #e2e8f0);
        flex:0 0 30px;
    }
        .mobile-more-icon svg {
            width:16px;
            height:16px;
            flex:0 0 16px;
        }
    .mobile-more-action .mobile-more-icon {
        color:var(--ph-icon-tone, #1d4ed8);
        background:var(--ph-icon-tone-soft, #dbeafe);
        border-color:var(--ph-icon-tone-border, #93c5fd);
    }
    .mobile-more-logout .mobile-more-icon {
        color:var(--ph-icon-tone, #b91c1c);
        background:var(--ph-icon-tone-soft, #fee2e2);
        border-color:var(--ph-icon-tone-border, #fca5a5);
    }
        .mobile-more-action .mobile-more-icon svg,
        .mobile-more-logout .mobile-more-icon svg {
            width:18px;
            height:18px;
            flex:0 0 18px;
        }
        .mobile-more-link.is-active .mobile-more-icon {
            color:#fff;
            background:rgba(255,255,255,.14);
            border-color:rgba(255,255,255,.18);
        }
        .mobile-fab {
            position:fixed;
            right:16px;
            bottom:92px;
            z-index:920;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:7px;
            min-height:46px;
            padding:0 16px;
            border-radius:999px;
            background:#0f172a;
            color:#fff;
            text-decoration:none;
            box-shadow:0 18px 42px rgba(15,23,42,.28);
            border:1px solid rgba(255,255,255,.16);
            font-size:13px;
            font-weight:800;
        }
        .mobile-fab.is-compact {
            width:52px;
            height:52px;
            min-height:52px;
            padding:0;
            border-radius:999px;
            justify-content:center;
            box-shadow:0 18px 36px rgba(79,70,229,.28);
        }
        .mobile-fab.is-compact strong {
            display:none;
        }
        .mobile-fab span {
            width:22px;
            height:22px;
            display:grid;
            place-items:center;
            border-radius:999px;
            background:rgba(255,255,255,.14);
            font-size:18px;
            line-height:1;
        }
        .mobile-sticky-actions {
            position:fixed;
            left:10px;
            right:10px;
            bottom:92px;
            z-index:904;
            display:flex;
            gap:7px;
            padding:8px;
            border-radius:18px;
            background:rgba(255,255,255,.97);
            border:1px solid #dbe3ef;
            box-shadow:0 16px 38px rgba(15,23,42,.18);
            backdrop-filter:blur(14px);
            overflow:visible;
            max-width:calc(100vw - 20px);
            -webkit-overflow-scrolling:touch;
            scrollbar-width:none;
            padding-bottom:calc(8px + env(safe-area-inset-bottom, 0px));
        }
        .mobile-sticky-actions::-webkit-scrollbar { display:none; }
        .mobile-sticky-actions form { margin:0; flex:1 0 auto; }
        .mobile-sticky-actions details {
            margin:0;
            flex:1 0 auto;
            min-width:0;
            position:relative;
        }
        .mobile-sticky-actions a,
        .mobile-sticky-actions button,
        .mobile-sticky-actions summary {
            flex:1 0 auto;
            min-height:44px;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:6px;
            padding:8px 11px;
            border-radius:13px;
            border:1px solid #cbd5e1;
            background:#fff;
            color:#0f172a;
            text-decoration:none;
            font-size:12px;
            font-weight:800;
            font-family:inherit;
            white-space:nowrap;
            list-style:none;
        }
        .mobile-sticky-actions summary::-webkit-details-marker { display:none; }
        .mobile-sticky-actions form button {
            width:100%;
        }
        .mobile-sticky-actions .mobile-actions-panel {
            position:absolute;
            right:0;
            bottom:calc(100% + 8px);
            width:min(220px, calc(100vw - 44px));
            margin-top:0;
            z-index:930;
        }
        .mobile-sticky-actions .is-primary {
            background:#0f172a;
            color:#fff;
            border-color:#0f172a;
        }
        .mobile-sticky-actions .is-danger {
            background:#fee2e2;
            color:#991b1b;
            border-color:#fecaca;
        }
        input,
        select,
        textarea,
        button {
            font-size:16px;
        }
        .app-shell-main .ops-card,
        .app-shell-main .detail-card,
        .app-shell-main .sales-card,
        .app-shell-main .rental-card {
            border-radius:14px !important;
            box-shadow:0 6px 18px rgba(15,23,42,.05) !important;
        }
        .app-shell-main .ops-card-head,
        .app-shell-main .ops-card-body,
        .app-shell-main .detail-card,
        .app-shell-main .sales-card,
        .app-shell-main .rental-card {
            padding-left:12px !important;
            padding-right:12px !important;
        }
        .app-shell-main .summary-tile,
        .app-shell-main .metric-box,
        .app-shell-main .sales-metric,
        .app-shell-main .rental-metric {
            padding:10px !important;
            border-radius:12px !important;
        }
        .app-shell-main .sale-show-header h1,
        .app-shell-main .detail-header h1,
        .app-shell-main .sales-header h1,
        .app-shell-main .rental-title h1 {
            font-size:24px !important;
        }
        .app-shell-main .sale-show-header p,
        .app-shell-main .detail-header p,
        .app-shell-main .sales-header p,
        .app-shell-main .rental-title p,
        .app-shell-main .ops-muted {
            font-size:12px !important;
            line-height:1.45 !important;
        }
        .app-shell-main .summary-tile strong,
        .app-shell-main .metric-box strong {
            font-size:18px !important;
        }
        textarea { min-height:88px; }
        .mobile-collapsible {
            border:1px solid #e2e8f0;
            border-radius:14px;
            background:#fff;
            overflow:hidden;
        }
        .mobile-collapsible summary {
            min-height:42px;
            display:flex;
            align-items:center;
            justify-content:space-between;
            padding:10px 12px;
            cursor:pointer;
            font-size:13px;
            font-weight:800;
            color:#0f172a;
        }
        .mobile-collapsible-body {
            padding:0 12px 12px;
            color:#475569;
            font-size:13px;
            line-height:1.55;
        }
        .mobile-action-toolbar,
        .ops-mobile-toolbar {
            position:sticky;
            top:10px;
            z-index:45;
            display:flex;
            justify-content:flex-end;
            align-items:center;
            gap:8px;
        }
        .mobile-search-tools {
            display:grid;
            grid-template-columns:minmax(0, 1fr) auto;
            align-items:center;
            gap:8px;
        }
        .mobile-search-tools .mobile-action-toolbar {
            position:static;
            padding:0;
            overflow:visible;
        }
        .mobile-toolbar-btn,
        .ops-mobile-toolbar .mobile-toolbar-btn,
        .ops-mobile-toolbar .mobile-sort-trigger {
            position:relative;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:0;
            width:40px;
            min-width:40px;
            max-width:40px;
            height:40px;
            min-height:40px;
            padding:0;
            border:1px solid #dbe3ef;
            border-radius:13px;
            background:#fff;
            color:#0f172a;
            font-size:0;
            font-weight:800;
            line-height:1;
            box-shadow:0 10px 24px rgba(15,23,42,.08);
            cursor:pointer;
            overflow:visible;
        }
        .mobile-toolbar-btn span,
        .ops-mobile-toolbar .mobile-toolbar-btn span {
            position:absolute;
            width:1px;
            height:1px;
            margin:-1px;
            padding:0;
            overflow:hidden;
            clip:rect(0, 0, 0, 0);
            white-space:nowrap;
            border:0;
        }
        .mobile-toolbar-btn::before {
            content:"⌕";
            display:grid;
            place-items:center;
            width:18px;
            height:18px;
            color:currentColor;
            font-size:19px;
            font-weight:900;
            line-height:1;
        }
        .mobile-toolbar-btn[data-mobile-filter-open]::before {
            content:"⌯";
            font-size:21px;
        }
        .mobile-toolbar-btn[data-mobile-sort-trigger]::before,
        .mobile-sort-trigger::before {
            content:"⇅";
            font-size:18px;
        }
        .mobile-toolbar-btn:has(svg)::before { display:none; }
        .mobile-toolbar-btn svg,
        .ops-mobile-toolbar .mobile-toolbar-btn svg {
            width:17px;
            height:17px;
            flex:0 0 17px;
        }
        .mobile-toolbar-btn.is-active::after,
        .mobile-toolbar-btn[aria-pressed="true"]::after,
        .mobile-toolbar-btn[data-filter-active="true"]::after,
        .mobile-action-toolbar.has-active-filters [data-mobile-filter-open]::after,
        .ops-mobile-toolbar.has-active-filters [data-mobile-filter-open]::after {
            content:"";
            position:absolute;
            top:7px;
            right:7px;
            width:7px;
            height:7px;
            border-radius:999px;
            background:#2563eb;
            box-shadow:0 0 0 2px #fff;
        }
        .mobile-sort-anchor {
            position:relative;
        }
        .mobile-sort-popover {
            display:none;
            position:absolute;
            top:calc(100% + 8px);
            right:0;
            width:min(240px, calc(100vw - 32px));
            padding:8px;
            border:1px solid #dbe3ef;
            border-radius:16px;
            background:#fff;
            box-shadow:0 18px 42px rgba(15,23,42,.14);
        }
        .mobile-sort-popover.is-open {
            display:grid;
            gap:4px;
        }
        .mobile-sort-option {
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:10px;
            min-height:44px;
            padding:10px 12px;
            border-radius:12px;
            color:#0f172a;
            font-size:13px;
            font-weight:700;
            text-decoration:none;
        }
        .mobile-sort-option.is-active {
            background:#eff6ff;
            color:#1d4ed8;
        }
        .mobile-filter-sheet {
            display:none;
            position:fixed;
            inset:0;
            z-index:1200;
            align-items:flex-start;
            justify-content:center;
            padding:12px;
            background:rgba(15,23,42,.38);
        }
        .mobile-filter-sheet.is-open {
            display:flex;
        }
        .mobile-filter-sheet-panel {
            width:min(100%, 600px);
            max-height:90vh;
            overflow:hidden;
            border:1px solid #dbe3ef;
            border-radius:20px;
            background:#fff;
            box-shadow:0 24px 60px rgba(15,23,42,.22);
            transform:translateY(-18px);
            opacity:0;
            transition:transform .18s ease, opacity .18s ease;
        }
        .mobile-filter-sheet.is-open .mobile-filter-sheet-panel {
            transform:translateY(0);
            opacity:1;
        }
        .mobile-filter-sheet-header {
            position:sticky;
            top:0;
            z-index:2;
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            padding:14px 16px;
            border-bottom:1px solid #e2e8f0;
            background:#fff;
        }
        .mobile-filter-sheet-header h3 {
            margin:0;
            font-size:16px;
            color:#0f172a;
        }
        .mobile-filter-sheet-header p {
            margin:3px 0 0;
            font-size:12px;
            color:#64748b;
        }
        .mobile-filter-sheet-close {
            display:inline-flex;
            align-items:center;
            justify-content:center;
            width:44px;
            height:44px;
            flex:0 0 44px;
            border:1px solid #dbe3ef;
            border-radius:999px;
            background:#fff;
            color:#0f172a;
            font-size:18px;
            font-weight:900;
            cursor:pointer;
        }
        .mobile-filter-sheet-body {
            max-height:calc(90vh - 74px);
            overflow-y:auto;
            overflow-x:hidden;
            padding:14px 16px 18px;
        }
        .mobile-sheet-form {
            display:grid;
            gap:12px;
        }
        .mobile-sheet-grid {
            display:grid;
            gap:10px;
        }
        .mobile-sheet-field {
            display:grid;
            gap:6px;
        }
        .mobile-sheet-field label {
            color:#64748b;
            font-size:11px;
            font-weight:800;
            letter-spacing:.05em;
            text-transform:uppercase;
        }
        .mobile-sheet-actions {
            position:sticky;
            bottom:0;
            display:grid;
            grid-template-columns:repeat(2, minmax(0, 1fr));
            gap:8px;
            padding-top:10px;
            background:linear-gradient(180deg, rgba(255,255,255,0) 0%, #fff 18px, #fff 100%);
        }
    }
    @media (max-width: 640px) {
        .app-shell-main {
            padding-left:10px !important;
            padding-right:10px !important;
            padding-top:128px !important;
            padding-bottom:calc(176px + env(safe-area-inset-bottom, 0px)) !important;
        }
        .mobile-topbar {
            flex-wrap:wrap;
            gap:8px;
            min-height:auto;
            padding:10px 10px 8px;
        }
        .mobile-brand {
            flex:1 1 calc(100% - 98px);
            max-width:calc(100% - 98px);
        }
        .mobile-brand-copy small {
            display:none;
        }
        .mobile-topbar-actions {
            gap:6px;
        }
        .mobile-topbar-search {
            order:3;
            flex:1 1 100%;
        }
        .mobile-back-row {
            top:94px;
            padding-inline:10px;
        }
        .mobile-bottom-nav,
        .mobile-sticky-actions {
            left:8px;
            right:8px;
            max-width:calc(100vw - 16px);
        }
        [data-action-toast] {
            left:10px !important;
            right:10px !important;
            bottom:calc(var(--ph-mobile-nav-height, 74px) + 20px + env(safe-area-inset-bottom, 0px)) !important;
            max-width:calc(100vw - 20px) !important;
            pointer-events:none;
        }
        .mobile-fab {
            right:12px;
        }
    }
    .app-shell {
        --ph-header-height:72px;
        padding-top:var(--ph-header-height);
        height:100dvh;
        overflow:hidden;
    }
    .brand-panel {
        display:none !important;
    }
    .app-shell-sidebar {
        padding:8px 10px 10px;
        height:calc(100dvh - var(--ph-header-height));
        max-height:calc(100dvh - var(--ph-header-height));
        background:var(--ph-color-sidebar);
        border-color:var(--ph-color-border-strong);
        box-shadow:0 16px 36px rgba(15,23,42,.05);
        overflow:visible;
    }
    .sidebar-scroll {
        padding-right:12px;
        padding-bottom:10px;
        scrollbar-gutter:stable;
    }
    .sidebar-nav,
    .sidebar-panel {
        min-width:0;
    }
    .sidebar-section {
        gap:3px;
        margin-top:3px;
        padding-top:5px;
        border-top:1px solid #dde7f4;
    }
    .sidebar-section-toggle {
        min-height:24px;
        padding:3px 6px;
        border:none;
        border-radius:8px;
        background:transparent;
        color:#5b6f88;
        box-shadow:none;
    }
    .sidebar-section-toggle:hover {
        background:#edf4ff;
        border-color:transparent;
        transform:none;
    }
    .sidebar-section-toggle span,
    .sidebar-section-title {
        font-size:11px;
        letter-spacing:.08em;
        text-transform:uppercase;
        font-weight:600;
        color:#334a68;
    }
    .sidebar-nav {
        gap:1px;
    }
    .sidebar-link {
        width:100%;
        max-width:100%;
        min-height:30px;
        gap:10px;
        padding:4px 10px;
        font-weight:500;
        color:#20324d;
        overflow:visible;
        box-sizing:border-box;
        transition:background-color .16s ease, color .16s ease, border-color .16s ease, box-shadow .16s ease;
    }
    .sidebar-link:hover {
        color:#0f172a;
        background:#edf4ff;
        border-color:#d8e5f6;
        transform:none;
    }
    .sidebar-link .sidebar-label {
        color:inherit;
        flex:1 1 auto;
        min-width:0;
        max-width:100%;
        overflow:hidden;
        text-overflow:ellipsis;
        white-space:nowrap;
        transition:opacity .16s ease;
    }
    .sidebar-link.is-active,
    .sidebar-link.is-admin-active,
    .sidebar-link.is-secondary-active {
        color:#ffffff;
        background:linear-gradient(135deg, #4f46e5 0%, #5b4ce0 100%);
        border-color:#4f46e5;
        box-shadow:0 8px 16px rgba(79,70,229,.16);
    }
    .sidebar-icon {
        width:20px;
        height:20px;
        flex:0 0 20px;
        color:var(--ph-icon-tone, #334155);
        background:var(--ph-icon-tone-soft, #edf4ff);
        border-color:var(--ph-icon-tone-border, #d8e5f6);
    }
    .sidebar-icon svg {
        width:12px;
        height:12px;
    }
    .sidebar-link.is-active .sidebar-icon,
    .sidebar-link.is-admin-active .sidebar-icon,
    .sidebar-link.is-secondary-active .sidebar-icon {
        color:#ffffff;
        border-color:rgba(255,255,255,.22);
        background:rgba(255,255,255,.12);
    }
    .sidebar-link-badge {
        position:static;
        right:auto;
        top:auto;
        margin-left:auto;
        transform:none;
        box-shadow:0 2px 8px rgba(79,70,229,.10);
        min-width:26px;
        height:17px;
        padding:0 6px;
        flex:0 0 auto;
        align-self:center;
        text-align:center;
        font-size:9.5px;
    }
    .sidebar-link.is-active .sidebar-link-badge,
    .sidebar-link.is-admin-active .sidebar-link-badge,
    .sidebar-link.is-secondary-active .sidebar-link-badge {
        background:rgba(255,255,255,.18);
        color:#ffffff;
        box-shadow:none;
    }
    .app-shell-topbar {
        position:fixed;
        top:0;
        left:0;
        right:0;
        z-index:140;
        min-height:var(--ph-header-height);
        margin:0;
        padding:12px 20px;
        border-bottom:1px solid #e2e8f0;
        border-radius:0;
        background:#ffffff;
        box-shadow:0 10px 28px rgba(15,23,42,.05);
        backdrop-filter:none;
    }
    .app-shell-topbar-left {
        gap:16px;
    }
    .app-shell-topbar-right {
        gap:10px;
        align-items:center;
    }
    .desktop-header-brand-wrap {
        display:flex;
        align-items:center;
        gap:10px;
        flex:0 0 auto;
        min-width:0;
    }
    .desktop-header-brand {
        display:flex;
        align-items:center;
        gap:10px;
        min-width:0;
        text-decoration:none;
        color:#0f172a;
    }
    .shell-brand-badge {
        display:inline-flex;
        align-items:center;
        justify-content:center;
        width:38px;
        height:38px;
        padding:5px;
        border-radius:12px;
        border:1px solid #dbe3ef;
        background:#ffffff;
        box-shadow:0 6px 16px rgba(15,23,42,.05);
        flex:0 0 38px;
    }
    .shell-brand-logo-wrap {
        display:inline-flex;
        align-items:center;
        justify-content:center;
        flex:0 0 auto;
    }
    .shell-brand-logo {
        width:auto;
        height:auto;
        max-width:182px;
        max-height:42px;
        object-fit:contain;
        display:block;
    }
    .shell-brand-divider {
        width:1px;
        height:30px;
        background:#dbe3ef;
        flex:0 0 1px;
    }
    .shell-brand-pill {
        display:inline-flex;
        align-items:center;
        justify-content:center;
        min-height:32px;
        padding:0 11px;
        border-radius:12px;
        border:1px solid #c7d2fe;
        background:#eef2ff;
        color:#4338ca;
        font-size:12px;
        font-weight:800;
        letter-spacing:.08em;
        flex:0 0 auto;
    }
    .desktop-header-meta {
        display:none;
    }
    .desktop-header-meta strong {
        display:block;
        font-size:14px;
        line-height:1.05;
        font-weight:900;
        letter-spacing:.02em;
        white-space:nowrap;
        color:#111827;
    }
    .desktop-header-meta span {
        display:block;
        font-size:10px;
        line-height:1.2;
        color:#64748b;
        white-space:nowrap;
    }
    .desktop-header-sidebar-toggle {
        position:fixed;
        top:82px;
        left:calc(var(--ph-sidebar-width) - 12px);
        z-index:141;
        width:24px;
        min-height:24px;
        border-radius:999px;
        background:#ffffff;
        border:1px solid #dbe3ef;
        box-shadow:0 6px 14px rgba(15,23,42,.08);
        padding:0;
    }
    .desktop-header-sidebar-toggle .sidebar-shell-toggle-label {
        display:none;
    }
    .app-shell.is-sidebar-collapsed .desktop-header-sidebar-toggle {
        left:calc(var(--ph-sidebar-width) - 12px);
    }
    .app-shell-search {
        flex:0 1 400px;
        width:min(400px, 34vw);
        min-width:300px;
        max-width:430px;
        min-height:42px;
        padding:0 12px;
        border-radius:12px;
        border-color:#e2e8f0;
        background:#f8fafc;
        box-shadow:inset 0 1px 0 rgba(255,255,255,.9);
    }
    .app-shell-search svg {
        width:15px;
        height:15px;
        color:#94a3b8;
        flex:0 0 15px;
    }
    .app-shell-search input {
        font-size:12px;
        line-height:1.35;
    }
    .app-shell-search input::placeholder {
        color:#9ca3af;
        font-size:11px;
    }
    .topbar-chip,
    .topbar-action-link,
    .topbar-bell-trigger,
    .topbar-user-trigger {
        min-height:42px;
    }
    .quick-add-trigger {
        min-width:auto;
        padding:8px 14px;
        border-radius:12px;
        font-size:12px;
        font-weight:800;
        box-shadow:none;
    }
    .quick-add-trigger span {
        line-height:1;
    }
    .topbar-chip.is-role {
        min-height:32px;
        padding:6px 10px;
        border-radius:999px;
        font-size:10px;
        font-weight:800;
        letter-spacing:.08em;
        background:#eef2ff;
        color:#4338ca;
        border:1px solid #c7d2fe;
        box-shadow:none;
    }
    .topbar-action-link,
    .topbar-bell-trigger {
        width:40px;
        min-width:40px;
        padding:0;
        border-radius:12px;
        background:#ffffff;
        border:1px solid #e2e8f0;
        box-shadow:none;
    }
    .topbar-action-link:hover,
    .topbar-bell-trigger:hover {
        background:#f8fafc;
        border-color:#cbd5e1;
    }
    .topbar-user-menu {
        flex:0 1 188px;
    }
    .topbar-user-trigger {
        max-width:188px;
        padding:4px 6px 4px 4px;
        gap:8px;
        border-radius:12px;
        border:1px solid #e2e8f0;
        box-shadow:none;
        background:#ffffff;
    }
    .topbar-user-trigger::after {
        width:7px;
        height:7px;
        margin-right:2px;
    }
    .topbar-user-avatar {
        width:30px;
        height:30px;
        border-radius:10px;
        font-size:11px;
    }
    .topbar-user-meta strong {
        font-size:11px;
        line-height:1.1;
        color:#0f172a;
    }
    .topbar-user-meta span {
        margin-top:2px;
        font-size:10px;
        line-height:1.15;
        color:#64748b;
    }
    .app-shell-topbar-right {
        gap:10px;
    }
    .app-shell-main {
        height:calc(100dvh - var(--ph-header-height));
        max-width:calc(100vw - var(--ph-sidebar-width));
        padding:18px 22px 22px;
        overflow-y:auto;
        overscroll-behavior:contain;
        -webkit-overflow-scrolling:touch;
    }
    .sidebar-panel {
        transition:none;
    }
    @media (max-width: 1280px) {
        .desktop-header-sidebar-toggle {
            top:84px;
        }
        .topbar-user-menu {
            flex-basis:176px;
        }
        .topbar-user-trigger {
            max-width:176px;
        }
        .app-shell-search {
            flex-basis:400px;
            width:min(400px, 34vw);
            min-width:240px;
        }
        .desktop-header-meta span {
            display:none;
        }
        .shell-brand-logo {
            max-width:152px;
            max-height:34px;
        }
    }
    @media (max-width: 1180px) {
        .desktop-header-sidebar-toggle {
            position:static;
            left:auto;
            top:auto;
            width:32px;
            min-height:32px;
            margin-left:8px;
        }
        .desktop-header-brand-wrap {
            gap:8px;
        }
        .app-shell-search {
            flex:1 1 220px;
            width:auto;
            max-width:340px;
            min-width:0;
        }
        .topbar-chip.is-role {
            padding-inline:8px;
            font-size:9px;
        }
    }
    @media (max-width: 1024px) {
        .app-shell {
            padding-top:0;
            height:auto;
        }
        .app-shell-topbar,
        .desktop-header-brand-wrap {
            display:none;
        }
        .app-shell-sidebar {
            display:none;
        }
        .app-shell-main {
            height:auto;
            max-width:100vw !important;
        }
    }
    /* PHOS premium sidebar refresh */
    .app-shell-sidebar.rn-sidebar {
        background:linear-gradient(180deg, #3150FF 0%, #2742D8 48%, #1F338E 100%) !important;
        color:#ffffff;
        border-right:0;
        box-shadow:10px 0 30px rgba(29,51,140,.22);
    }
    .app-shell-sidebar.rn-sidebar::before {
        content:"";
        position:absolute;
        inset:0;
        pointer-events:none;
        background:linear-gradient(180deg, rgba(255,255,255,.12), rgba(255,255,255,0) 22%, rgba(0,0,0,.08) 100%);
    }
    .app-shell-sidebar .brand-panel {
        background:rgba(255,255,255,.94);
        border-color:rgba(255,255,255,.34);
        box-shadow:0 16px 34px rgba(12,28,104,.22);
    }
    .app-shell-sidebar .sidebar-scroll::-webkit-scrollbar-thumb {
        background:rgba(255,255,255,.42);
    }
    .app-shell-sidebar .sidebar-scroll::-webkit-scrollbar-track {
        background:rgba(255,255,255,.12);
    }
    .app-shell-sidebar .sidebar-section {
        border-color:rgba(255,255,255,.16);
    }
    .app-shell-sidebar .sidebar-section-title,
    .app-shell-sidebar .sidebar-section-toggle span {
        color:#ffffff !important;
        letter-spacing:.14em;
        text-transform:uppercase;
        font-weight:900;
    }
    .app-shell-sidebar .sidebar-section-title {
        font-size:10.5px;
        font-weight:800;
    }
    .app-shell-sidebar .sidebar-section-toggle {
        color:#ffffff;
        background:transparent;
        border-color:transparent;
    }
    .app-shell-sidebar .sidebar-section-toggle:hover {
        color:#ffffff;
        background:rgba(255,255,255,.08);
        border-color:rgba(255,255,255,.14);
    }
    .app-shell-sidebar .sidebar-section-toggle svg {
        color:#ffffff;
    }
    .app-shell-sidebar .sidebar-link {
        color:#ffffff;
        background:transparent;
        border-color:transparent;
        min-height:38px;
        padding-top:7px;
        padding-bottom:7px;
    }
    .app-shell-sidebar .sidebar-link .sidebar-label {
        color:inherit;
        font-size:13px;
        font-weight:600;
    }
    .app-shell-sidebar .sidebar-link:hover {
        color:#ffffff;
        background:rgba(255,255,255,.1);
        border-color:rgba(255,255,255,.16);
    }
    .app-shell-sidebar .sidebar-link.is-active,
    .app-shell-sidebar .sidebar-link.is-admin-active,
    .app-shell-sidebar .sidebar-link.is-secondary-active {
        position:relative;
        color:#2440D8 !important;
        background:#ffffff !important;
        border-color:rgba(255,255,255,.9) !important;
        min-height:36px;
        box-shadow:0 12px 24px rgba(13,27,93,.2);
    }
    .app-shell-sidebar .sidebar-link.is-active::before,
    .app-shell-sidebar .sidebar-link.is-admin-active::before,
    .app-shell-sidebar .sidebar-link.is-secondary-active::before {
        content:"";
        position:absolute;
        left:5px;
        top:9px;
        bottom:9px;
        width:3px;
        border-radius:999px;
        background:#3150FF;
    }
    .app-shell-sidebar .sidebar-link.is-active .sidebar-label,
    .app-shell-sidebar .sidebar-link.is-admin-active .sidebar-label,
    .app-shell-sidebar .sidebar-link.is-secondary-active .sidebar-label {
        color:#2440D8 !important;
    }
    .app-shell-sidebar .sidebar-icon {
        color:#ffffff;
        background:rgba(255,255,255,.18);
        border-color:rgba(255,255,255,.34);
        box-shadow:inset 0 1px 0 rgba(255,255,255,.18);
    }
    .app-shell-sidebar .sidebar-link:hover .sidebar-icon {
        background:rgba(255,255,255,.2);
        border-color:rgba(255,255,255,.34);
    }
    .app-shell-sidebar .sidebar-link.is-active .sidebar-icon,
    .app-shell-sidebar .sidebar-link.is-admin-active .sidebar-icon,
    .app-shell-sidebar .sidebar-link.is-secondary-active .sidebar-icon {
        color:#2440D8 !important;
        background:#eef2ff !important;
        border-color:#c7d2fe !important;
    }
    .app-shell-sidebar .sidebar-link-badge {
        min-width:28px;
        height:22px;
        padding:3px 8px;
        display:inline-flex;
        align-items:center;
        justify-content:center;
        font-size:11.5px;
        font-weight:900;
        box-shadow:0 8px 18px rgba(11,23,62,.22);
        border:1px solid rgba(255,255,255,.72);
    }
    .app-shell-sidebar .sidebar-link.is-active .sidebar-link-badge,
    .app-shell-sidebar .sidebar-link.is-admin-active .sidebar-link-badge,
    .app-shell-sidebar .sidebar-link.is-secondary-active .sidebar-link-badge {
        border-color:#ffffff;
    }
    .app-shell-sidebar .sidebar-shell-toggle {
        color:#ffffff;
        background:rgba(255,255,255,.12);
        border-color:rgba(255,255,255,.24);
        box-shadow:0 12px 28px rgba(9,22,81,.24);
    }
    .app-shell-sidebar .sidebar-shell-toggle:hover {
        background:rgba(255,255,255,.2);
        border-color:rgba(255,255,255,.38);
    }
    .app-shell.is-sidebar-collapsed .app-shell-sidebar .sidebar-link {
        justify-content:center;
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
                    <input type="text" name="q" value="{{ $globalSearchValue }}" placeholder="Search customers, rentals, invoices, serial no..." autocomplete="off" spellcheck="false" aria-label="Search customers, rentals, invoices, serial no" />
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
                        class="topbar-action-link icon-ink icon-knowledge {{ request()->routeIs('knowledge.index') ? 'is-active' : '' }}"
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
                                        <span>Speak short safe labels like “New pickup assigned”.</span>
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
                    <summary class="topbar-user-trigger" aria-label="Open user menu">
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
        style="position:fixed; right:18px; bottom:18px; z-index:1200; max-width:min(420px, calc(100vw - 36px)); padding:12px 14px; border-radius:14px; box-shadow:0 18px 42px rgba(15,23,42,0.22); font-size:13px; font-weight:700; line-height:1.4; color:{{ session('error') ? '#991b1b' : '#14532d' }}; background:{{ session('error') ? '#fef2f2' : '#ecfdf5' }}; border:1px solid {{ session('error') ? '#fecaca' : '#bbf7d0' }};">
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
            const filterPanels = Array.from(document.querySelectorAll('details[data-filter-panel][data-filter-panel-key]'));

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
@stack('scripts')
</body>
</html>
