<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Prime Healers OS') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('images/rentnexis-favicon.png') }}">
    <link rel="shortcut icon" href="{{ asset('favicon.ico') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/rentnexis-favicon.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body style="margin:0; width:100%; overflow-x:hidden; background:#f8fafc; color:#0f172a; font-family:Arial, sans-serif;">
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
    $topbarNotificationsViewAllHref = $topbarNotificationsViewAllHref ?? ($safeRoute('dashboard'));
    $knowledgeHubHref = $safeRoute('knowledge.index');
    $sidebarPendingCounts = $sidebarPendingCounts ?? [];
    $formatSidebarBadge = function ($value): ?string {
        $count = max((int) $value, 0);

        if ($count <= 0) {
            return null;
        }

        return $count > 99 ? '99+' : (string) $count;
    };

    $sidebarSections = [
        [
            'label' => 'Main',
            'items' => [
                ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'dashboard', 'href' => $safeRoute('dashboard'), 'active' => request()->routeIs('dashboard'), 'visible' => $currentUser?->hasPermission('dashboard.main') ?? false],
                ['key' => 'customers', 'label' => 'Customers', 'icon' => 'customers', 'href' => $safeRoute('customers.index'), 'active' => request()->routeIs('customers.*'), 'visible' => !$isDeliveryFacingMenuRole && ($currentUser?->canAccessModule('customers', 'read') ?? false)],
                ['key' => 'rentals', 'label' => 'Rentals', 'icon' => 'rentals', 'href' => $safeRoute('rentals.index'), 'active' => request()->routeIs('rentals.*'), 'visible' => !$isDeliveryFacingMenuRole && ($currentUser?->canAccessModule('rentals', 'read') ?? false)],
                ['key' => 'sales', 'label' => 'Sales', 'icon' => 'sales', 'href' => $safeRoute('sales.index'), 'active' => request()->routeIs('sales.*'), 'visible' => $currentUser?->canAccessModule('sales', 'read') ?? false],
            ],
        ],
        [
            'label' => 'Operations',
            'items' => [
                ['key' => 'tasks_board', 'label' => 'Tasks Board', 'icon' => 'deliveries', 'href' => $safeRoute('deliveries.index'), 'active' => request()->routeIs('deliveries.*') || request()->routeIs('pickups.*'), 'visible' => $currentUser?->canAccessModule('deliveries', 'read') ?? false],
            ],
        ],
        [
            'label' => 'Inventory',
            'items' => [
                ['key' => 'inventory', 'label' => 'Inventory Overview', 'icon' => 'inventory', 'href' => $safeRoute('inventory.dashboard'), 'active' => request()->routeIs('inventory.dashboard'), 'visible' => $currentUser?->hasPermission('dashboard.inventory') ?? false],
                ['key' => 'products', 'label' => 'Product Master', 'icon' => 'products', 'href' => $safeRoute('products.index'), 'active' => request()->routeIs('products.*'), 'visible' => $currentUser?->canAccessModule('products', 'read') ?? false],
                ['key' => 'assets', 'label' => 'Asset Register', 'icon' => 'assets', 'href' => $safeRoute('assets.index'), 'active' => request()->routeIs('assets.*') && !request()->routeIs('assets.pending-verification') && !request()->routeIs('assets.verify-return') && !request()->routeIs('assets.verify-return.store'), 'visible' => $currentUser?->canAccessModule('assets', 'read') ?? false],
                ['key' => 'return_verification', 'label' => 'Return Verification', 'icon' => 'assets', 'href' => $safeRoute('assets.pending-verification'), 'active' => request()->routeIs('assets.pending-verification') || request()->routeIs('assets.verify-return') || request()->routeIs('assets.verify-return.store'), 'visible' => $currentUser?->canAccessModule('assets', 'read') ?? false],
                ['key' => 'warehouses', 'label' => 'Warehouses', 'icon' => 'warehouses', 'href' => $safeRoute('warehouses.index'), 'active' => request()->routeIs('warehouses.*'), 'visible' => $currentUser?->canAccessModule('warehouses', 'read') ?? false],
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
            ],
        ],
        [
            'label' => 'More',
            'items' => [
                ['label' => 'Cities', 'icon' => 'cities', 'href' => $safeRoute('cities.index'), 'active' => request()->routeIs('cities.*'), 'visible' => $currentUser?->canAccessModule('cities', 'read') ?? false],
                ['label' => 'Vendors', 'icon' => 'vendors', 'href' => $safeRoute('vendors.index'), 'active' => request()->routeIs('vendors.*'), 'visible' => $currentUser?->canAccessModule('vendors', 'read') ?? false],
            ],
        ],
    ];

    $organizationItems = [
        ['label' => 'Company', 'icon' => 'settings', 'href' => $companyHref, 'active' => request()->routeIs('organization.settings.*'), 'visible' => !empty($companyHref)],
        ['label' => 'Preferences', 'icon' => 'settings', 'href' => $preferencesHref, 'active' => request()->routeIs('organization.settings.*'), 'visible' => false],
        ['label' => 'Data Import', 'icon' => 'products', 'href' => $safeRoute('imports.index'), 'active' => request()->routeIs('imports.*'), 'visible' => $currentUser?->isSuperAdmin() ?? false],
    ];

    $organizationMenuOpen = collect($organizationItems)->contains(fn ($item) => !empty($item['active']));
    $visibleSidebarSections = collect($sidebarSections)->map(function ($section) {
        $section['items'] = collect($section['items'])->filter(fn ($item) => !empty($item['visible']) && !empty($item['href']))->values()->all();
        return $section;
    })->filter(fn ($section) => !empty($section['items']))->values();

    $mobilePrimaryItems = [
        ['label' => 'Dashboard', 'icon' => 'dashboard', 'href' => ($currentUser?->hasPermission('dashboard.main') ?? false) ? $safeRoute('dashboard') : null, 'active' => request()->routeIs('dashboard')],
        ['label' => 'Customers', 'icon' => 'customers', 'href' => (!$isDeliveryFacingMenuRole && ($currentUser?->canAccessModule('customers', 'read') ?? false)) ? $safeRoute('customers.index') : null, 'active' => request()->routeIs('customers.*')],
        ['label' => 'Rentals', 'icon' => 'rentals', 'href' => (!$isDeliveryFacingMenuRole && ($currentUser?->canAccessModule('rentals', 'read') ?? false)) ? $safeRoute('rentals.index') : null, 'active' => request()->routeIs('rentals.*')],
        ['label' => 'Sales', 'icon' => 'sales', 'href' => ($currentUser?->canAccessModule('sales', 'read') ?? false) ? $safeRoute('sales.index') : null, 'active' => request()->routeIs('sales.*')],
    ];

    $quickAddItems = collect([
        ['label' => 'New Customer', 'icon' => 'customers', 'href' => ($currentUser?->canAccessModule('customers', 'create') ?? false) ? $safeRoute('customers.create') : null],
        ['label' => 'New Rental', 'icon' => 'rentals', 'href' => ($currentUser?->canAccessModule('rentals', 'create') ?? false) ? $safeRoute('rentals.create') : null],
        ['label' => 'New Sale', 'icon' => 'sales', 'href' => ($currentUser?->canAccessModule('sales', 'create') ?? false) ? $safeRoute('sales.create') : null],
        ['label' => 'New Invoice', 'icon' => 'invoices', 'href' => ($currentUser?->canAccessModule('invoices', 'create') ?? false) ? $safeRoute('invoices.create') : null],
        ['label' => 'New Product', 'icon' => 'products', 'href' => ($currentUser?->canAccessModule('products', 'create') ?? false) ? $safeRoute('products.create') : null],
    ])->filter(fn ($item) => !empty($item['href']))->values();

    $mobileMoreItems = $visibleSidebarSections
        ->flatMap(fn ($section) => $section['items'])
        ->merge(collect($organizationItems)->filter(fn ($item) => !empty($item['visible']) && !empty($item['href'])))
        ->reject(fn ($item) => in_array($item['label'], ['Dashboard', 'Rentals', 'Sales', 'Customers'], true))
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
    $dashboardHref = ($currentUser?->hasPermission('dashboard.main') ?? false)
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
        $breadcrumbItems[] = ['label' => 'Organization & Settings', 'href' => null];
        $breadcrumbItems[] = ['label' => 'Settings', 'href' => null];
    } elseif ($routeName === 'deliveries.assigned') {
        $breadcrumbItems[] = ['label' => 'Tasks Board', 'href' => route('deliveries.index')];
        $breadcrumbItems[] = ['label' => 'My Deliveries', 'href' => null];
    } elseif ($routeName === 'pickups.assigned') {
        $breadcrumbItems[] = ['label' => 'Tasks Board', 'href' => route('deliveries.index', ['tab' => 'pickups', 'task_type' => 'pickup'])];
        $breadcrumbItems[] = ['label' => 'My Pickups', 'href' => null];
    } elseif ($routeModule && isset($moduleLabels[$routeModule])) {
        if (in_array($routeModule, $adminModules, true)) {
            $breadcrumbItems[] = ['label' => 'Organization & Settings', 'href' => null];
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
        display:flex; min-height:100vh; width:100%; max-width:100%; overflow-x:hidden;
    }
    .app-shell-sidebar {
        flex:0 0 254px; width:254px; max-width:254px; color:#334155; padding:18px 14px;
        display:flex; flex-direction:column; gap:14px; box-sizing:border-box; overflow:hidden;
        color:#dbeafe;
        background:
            radial-gradient(circle at 14% 0%, rgba(56,189,248,.12), transparent 32%),
            radial-gradient(circle at 84% 12%, rgba(37,99,235,.12), transparent 24%),
            linear-gradient(180deg, #07162f 0%, #0b1528 56%, #08101d 100%);
        border-right:1px solid rgba(148,163,184,.16);
        box-shadow:14px 0 40px rgba(2,6,23,.18);
    }
    .brand-panel { padding:4px 8px 14px; border-bottom:1px solid rgba(148,163,184,.14); }
    .brand-mark { display:flex; align-items:center; gap:10px; }
    .brand-logo {
        width:44px;
        height:44px;
        max-width:44px;
        max-height:44px;
        object-fit:contain;
        display:block;
        flex:0 0 44px;
        filter:drop-shadow(0 10px 24px rgba(56,189,248,.18));
    }
    .brand-title { font-size:22px; font-weight:800; letter-spacing:-.04em; color:#ffffff; line-height:1; }
    .brand-subtitle { margin-top:4px; font-size:11px; color:#93c5fd; }
    .org-card {
        margin-top:14px; padding:11px 12px; border-radius:15px;
        background:linear-gradient(135deg, rgba(15,23,42,.18) 0%, rgba(37,99,235,.14) 100%);
        border:1px solid rgba(147,197,253,.14);
    }
    .org-card small, .sidebar-section-title {
        font-size:10px; letter-spacing:.12em; text-transform:uppercase; font-weight:800;
    }
    .org-card small { color:#7dd3fc; }
    .org-card strong { display:block; margin-top:6px; font-size:13px; line-height:1.3; color:#eff6ff; }
    .sidebar-nav { display:flex; flex-direction:column; gap:4px; }
    .sidebar-group {
        display:grid;
        gap:6px;
    }
    .sidebar-section-panel {
        display:grid;
        gap:6px;
    }
    .sidebar-link {
        position:relative; display:flex; align-items:center; gap:10px; padding:8px 10px; border-radius:12px;
        text-decoration:none; font-size:13px; font-weight:700; color:#d7e3fa;
        border:1px solid transparent; transition:background .16s ease, color .16s ease, transform .16s ease, border-color .16s ease;
    }
    .sidebar-link:hover { background:rgba(148,163,184,.10); color:#fff; transform:translateX(2px); border-color:rgba(148,163,184,.14); }
    .sidebar-link.is-active {
        color:#fff; background:linear-gradient(135deg, rgba(37,99,235,.78), rgba(59,130,246,.64));
        border-color:rgba(147,197,253,.32); box-shadow:0 10px 22px rgba(37,99,235,.18);
    }
    .sidebar-link.is-admin-active {
        color:#e0f2fe; background:rgba(37,99,235,.14);
        border-color:rgba(96,165,250,.18);
    }
    .sidebar-icon {
        width:28px; height:28px; border-radius:10px; display:grid; place-items:center; flex:0 0 28px;
        color:#7dd3fc; background:rgba(15,23,42,.22); border:1px solid rgba(147,197,253,.12);
    }
    .sidebar-icon svg { width:16px; height:16px; }
    .sidebar-link.is-active .sidebar-icon,
    .sidebar-link.is-admin-active .sidebar-icon { color:inherit; background:rgba(255,255,255,.18); border-color:rgba(255,255,255,.24); }
    .sidebar-label { min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .sidebar-link-badge {
        margin-left:auto;
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
        transform:translateY(-6px);
        flex:0 0 auto;
    }
    .sidebar-link.is-active .sidebar-link-badge,
    .sidebar-link.is-admin-active .sidebar-link-badge {
        background:#fff;
        color:#1d4ed8;
        box-shadow:none;
    }
    .sidebar-section {
        display:flex; flex-direction:column; gap:6px; margin-top:8px; padding-top:14px;
        border-top:1px solid rgba(148,163,184,.14);
    }
    .sidebar-section-title { padding:0 12px; color:#7c93bf; font-size:10px; }
    .sidebar-section-toggle {
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:10px;
        width:100%;
        padding:9px 12px;
        border-radius:13px;
        color:#dbeafe;
        background:rgba(148,163,184,.07);
        border:1px solid rgba(148,163,184,.12);
        font-size:11px;
        font-weight:800;
        letter-spacing:.05em;
        text-transform:uppercase;
        cursor:pointer;
        list-style:none;
        transition:background .16s ease, border-color .16s ease, color .16s ease;
    }
    .sidebar-section-toggle:hover {
        background:rgba(148,163,184,.11);
        border-color:rgba(148,163,184,.16);
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
        transition:max-height .22s ease, opacity .18s ease, margin-top .18s ease;
    }
    .sidebar-panel[hidden] {
        display:none;
    }
    .sidebar-group.is-collapsed .sidebar-panel {
        opacity:.4;
    }
    .app-shell-topbar {
        position:sticky;
        top:14px;
        z-index:120;
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:14px;
        margin-bottom:14px;
        padding:12px 14px;
        border:1px solid #dbe3ef;
        border-radius:16px;
        background:rgba(255,255,255,.96);
        box-shadow:0 14px 34px rgba(15,23,42,.05);
        backdrop-filter:blur(18px);
    }
    .app-shell-topbar-left {
        display:flex;
        align-items:center;
        gap:14px;
        flex:1 1 auto;
        min-width:0;
    }
    .app-shell-topbar-right {
        display:flex;
        align-items:center;
        justify-content:flex-end;
        gap:10px;
        flex-wrap:wrap;
    }
    .app-shell-search {
        flex:1 1 560px;
        min-width:300px;
        max-width:660px;
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
        min-height:36px;
        padding:8px 11px;
        border-radius:12px;
        border:1px solid #dbe3ef;
        background:#fff;
        color:#334155;
        font-size:11px;
        font-weight:800;
        line-height:1;
    }
    .topbar-chip.is-role {
        background:#ecfeff;
        color:#0f766e;
        border-color:#bae6fd;
    }
    .quick-add-menu {
        position:relative;
    }
    .quick-add-trigger {
        min-width:108px;
        list-style:none;
        cursor:pointer;
    }
    .quick-add-trigger::-webkit-details-marker {
        display:none;
    }
    .quick-add-panel {
        position:absolute;
        top:calc(100% + 8px);
        right:0;
        z-index:180;
        width:min(240px, 92vw);
        padding:8px;
        border-radius:18px;
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
        background:#eff6ff;
        color:#1d4ed8;
        border:1px solid #bfdbfe;
    }
    .quick-add-icon svg {
        width:15px;
        height:15px;
    }
    .topbar-notification-menu,
    .topbar-bell-menu {
        position:relative;
    }
    .topbar-action-link {
        width:36px;
        height:36px;
        border-radius:12px;
        display:grid;
        place-items:center;
        border:1px solid #dbe3ef;
        background:#fff;
        color:#334155;
        text-decoration:none;
        box-shadow:0 10px 24px rgba(15,23,42,.04);
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
        color:#334155;
        cursor:pointer;
        list-style:none;
        position:relative;
    }
    .topbar-bell-trigger::-webkit-details-marker {
        display:none;
    }
    .topbar-bell-badge {
        position:absolute;
        top:-5px;
        right:-5px;
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
    }
    .topbar-bell-panel {
        position:absolute;
        top:calc(100% + 10px);
        right:0;
        z-index:190;
        width:min(340px, calc(100vw - 28px));
        padding:10px;
        border-radius:18px;
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
    .topbar-bell-list {
        display:grid;
        gap:6px;
    }
    .topbar-bell-item {
        display:flex;
        align-items:flex-start;
        justify-content:space-between;
        gap:10px;
        padding:10px 12px;
        border-radius:14px;
        border:1px solid #e2e8f0;
        background:#f8fafc;
        text-decoration:none;
        color:#0f172a;
        transition:background .16s ease, border-color .16s ease, transform .16s ease;
    }
    .topbar-bell-item:hover {
        background:#ffffff;
        border-color:#bfdbfe;
        transform:translateY(-1px);
    }
    .topbar-bell-item strong {
        display:block;
        font-size:13px;
        line-height:1.35;
        color:#0f172a;
    }
    .topbar-bell-item small {
        display:block;
        margin-top:3px;
        font-size:12px;
        line-height:1.45;
        color:#64748b;
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
    .topbar-user-menu {
        position:relative;
    }
    .topbar-user-trigger {
        display:flex;
        align-items:center;
        gap:10px;
        padding:5px;
        border-radius:13px;
        border:1px solid #dbe3ef;
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
    }
    .topbar-user-meta span {
        display:block;
        margin-top:3px;
        color:#64748b;
        font-size:10px;
        line-height:1;
    }
    .topbar-user-panel {
        position:absolute;
        top:calc(100% + 10px);
        right:0;
        z-index:190;
        width:min(272px, calc(100vw - 28px));
        padding:10px;
        border-radius:18px;
        border:1px solid #dbe3ef;
        background:#fff;
        box-shadow:0 24px 52px rgba(15,23,42,.16);
        display:grid;
        gap:8px;
    }
    .topbar-user-head {
        display:grid;
        gap:3px;
        padding:8px 10px 10px;
        border-bottom:1px solid #eef2f7;
    }
    .topbar-user-head strong {
        color:#0f172a;
        font-size:14px;
    }
    .topbar-user-head span {
        color:#64748b;
        font-size:12px;
    }
    .topbar-user-role {
        margin-top:4px;
        width:max-content;
    }
    .topbar-user-link,
    .topbar-user-logout {
        display:flex;
        align-items:center;
        gap:10px;
        width:100%;
        min-height:42px;
        padding:10px 12px;
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
    @media (max-width: 900px) {
        .app-shell-sidebar { flex-basis:222px; width:222px; max-width:222px; padding:14px 10px; }
        .app-shell-main { max-width:calc(100vw - 222px) !important; }
        .brand-title { font-size:20px; }
        .sidebar-link { padding:8px 9px; }
    }
    @media (max-width: 767px) {
        .app-shell { flex-direction:column; min-height:100vh; overflow-x:hidden; }
        .app-shell-sidebar { display:none; }
        .app-shell-topbar { display:none; }
        .app-shell-main {
            padding:96px 12px 168px !important;
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
            gap:10px;
            text-decoration:none;
            color:#0f172a;
            flex:0 1 auto;
        }
        .mobile-brand-logo {
            width:36px;
            height:36px;
            max-width:36px;
            max-height:36px;
            object-fit:contain;
            flex:0 0 36px;
        }
        .mobile-brand-copy {
            min-width:0;
            display:grid;
            gap:1px;
        }
        .mobile-brand-copy strong {
            font-size:14px;
            line-height:1.1;
            color:#0f172a;
        }
        .mobile-brand-copy small {
            font-size:10px;
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
            padding:7px;
            border-radius:22px;
            background:rgba(255,255,255,.96);
            border:1px solid #dbe3ef;
            box-shadow:0 18px 46px rgba(15,23,42,.20);
            backdrop-filter:blur(16px);
            pointer-events:auto;
            max-width:calc(100vw - 20px);
            padding-bottom:calc(7px + env(safe-area-inset-bottom, 0px));
        }
        .mobile-nav-item {
            min-width:0;
            border:0;
            background:transparent;
            color:#64748b;
            text-decoration:none;
            display:grid;
            place-items:center;
            gap:3px;
            padding:7px 3px;
            border-radius:16px;
            font-size:10px;
            font-weight:800;
            line-height:1.1;
            cursor:pointer;
        }
        .mobile-nav-item svg {
            width:18px;
            height:18px;
        }
        .mobile-nav-item.is-active {
            background:linear-gradient(135deg, #2563eb, #38bdf8);
            color:#fff;
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
            background:linear-gradient(135deg, #2563eb, #38bdf8);
            border-color:#2563eb;
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
            background:#fff;
            color:#0f766e;
            border:1px solid #e2e8f0;
            flex:0 0 30px;
        }
        .mobile-more-icon svg {
            width:16px;
            height:16px;
            flex:0 0 16px;
        }
        .mobile-more-action .mobile-more-icon {
            color:#1d4ed8;
            background:#dbeafe;
            border-color:#93c5fd;
        }
        .mobile-more-logout .mobile-more-icon {
            color:#b91c1c;
            background:#fee2e2;
            border-color:#fca5a5;
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
        .mobile-action-toolbar {
            position:sticky;
            top:10px;
            z-index:45;
            display:grid;
            grid-template-columns:repeat(2, minmax(0, 1fr));
            gap:8px;
        }
        .mobile-toolbar-btn {
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:8px;
            min-height:44px;
            padding:10px 14px;
            border:1px solid #dbe3ef;
            border-radius:14px;
            background:#fff;
            color:#0f172a;
            font-size:13px;
            font-weight:800;
            line-height:1;
            box-shadow:0 10px 24px rgba(15,23,42,.08);
            cursor:pointer;
        }
        .mobile-toolbar-btn svg {
            width:16px;
            height:16px;
            flex:0 0 16px;
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
</style>

<div class="app-shell rn-shell">
    @include('partials.mobile-topbar', ['breadcrumbItems' => $breadcrumbItems ?? [], 'currentUser' => $currentUser])

    <aside class="app-shell-sidebar rn-sidebar">
        <div class="brand-panel">
            <div class="brand-mark">
                <x-application-logo class="brand-logo" />
                <div>
                    <div class="brand-title">Prime Healers OS</div>
                    <div class="brand-subtitle">Rental, sales, and care operations</div>
                </div>
            </div>
            @if(auth()->check() && auth()->user()->organization)
                <div class="org-card">
                    <small>Organization</small>
                    <strong>{{ auth()->user()->organization->name }}</strong>
                </div>
            @endif
        </div>

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
                               class="sidebar-link rn-sidebar-item {{ !empty($item['active']) ? 'is-active' : '' }}">
                                <span class="sidebar-icon">{!! $navIcon($item['icon'] ?? 'default') !!}</span>
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
                    <span>Organization &amp; Settings</span>
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
                               class="sidebar-link rn-sidebar-item {{ !empty($item['active']) ? 'is-admin-active is-secondary-active' : '' }}">
                                <span class="sidebar-icon">{!! $navIcon($item['icon'] ?? 'default') !!}</span>
                                <span class="sidebar-label">{{ $item['label'] }}</span>
                            </a>
                        @endforeach
                    </nav>
                </div>
            </section>
        @endif

    </aside>

    <main class="app-shell-main" style="flex:1 1 0; min-width:0; width:auto; max-width:calc(100vw - 254px); padding:20px 22px; overflow-x:hidden; box-sizing:border-box;">
        <header class="app-shell-topbar">
            <div class="app-shell-topbar-left">
                <div class="app-shell-search" role="search" aria-label="Universal search shell">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path></svg>
                    <input type="text" value="" placeholder="Search customers, rentals, invoices, serial no..." autocomplete="off" spellcheck="false" aria-label="Search customers, rentals, invoices, serial no" />
                </div>
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
                                    <span class="quick-add-icon">{!! $navIcon($item['icon'] ?? 'default') !!}</span>
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
                        class="topbar-action-link {{ request()->routeIs('knowledge.index') ? 'is-active' : '' }}"
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
                <details class="topbar-notification-menu topbar-bell-menu">
                    <summary class="topbar-bell-trigger" aria-label="Open notifications">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 17h5l-1.4-1.4A2 2 0 0 1 18 14.2V11a6 6 0 1 0-12 0v3.2a2 2 0 0 1-.6 1.4L4 17h5"/><path d="M10 21a2 2 0 0 0 4 0"/></svg>
                        @if($topbarNotificationCount > 0)
                            <span class="topbar-bell-badge">{{ $topbarNotificationCount > 99 ? '99+' : $topbarNotificationCount }}</span>
                        @endif
                    </summary>
                    <div class="topbar-bell-panel">
                        <div class="topbar-bell-head">
                            <div>
                                <strong>Notifications</strong>
                                <span>{{ $topbarNotificationCount > 0 ? 'Live operational attention points' : 'No urgent operational alerts right now' }}</span>
                            </div>
                            @if($topbarNotificationCount > 0)
                                <span class="rn-badge rn-badge-warning">{{ $topbarNotificationCount }}</span>
                            @endif
                        </div>
                        @if($topbarNotifications->isEmpty())
                            <div class="topbar-bell-empty">No overdue rentals, scheduled delivery alerts, due pickups, pending payment alerts, or maintenance asset warnings are active right now.</div>
                        @else
                            <div class="topbar-bell-list">
                                @foreach($topbarNotifications as $notification)
                                    @php
                                        $tone = $notification['tone'] ?? 'info';
                                        $countClass = match ($tone) {
                                            'danger' => 'is-danger',
                                            'warning' => 'is-warning',
                                            'muted' => 'is-muted',
                                            default => '',
                                        };
                                        $notificationHref = $notification['href'] ?? null;
                                    @endphp
                                    @if(!empty($notificationHref))
                                        <a href="{{ $notificationHref }}" class="topbar-bell-item">
                                            <div>
                                                <strong>{{ $notification['label'] ?? 'Alert' }}</strong>
                                                <small>{{ $notification['copy'] ?? 'Open for details.' }}</small>
                                            </div>
                                            <span class="topbar-bell-count {{ $countClass }}">{{ $notification['count'] ?? 0 }}</span>
                                        </a>
                                    @else
                                        <div class="topbar-bell-item">
                                            <div>
                                                <strong>{{ $notification['label'] ?? 'Alert' }}</strong>
                                                <small>{{ $notification['copy'] ?? 'Open for details.' }}</small>
                                            </div>
                                            <span class="topbar-bell-count {{ $countClass }}">{{ $notification['count'] ?? 0 }}</span>
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                        @endif
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
                            <span>{{ $currentUser?->organization?->name ?: 'Prime Healers OS' }}</span>
                        </div>
                    </summary>
                    <div class="topbar-user-panel">
                        <div class="topbar-user-head">
                            <strong>{{ $currentUser?->name ?: 'Prime Healers OS User' }}</strong>
                            <span>{{ $currentUser?->organization?->name ?: 'Prime Healers OS' }}</span>
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

        <div class="rn-trust-strip" data-mobile-trust="{{ $isDashboardRoute ? 'dashboard' : 'standard' }}" aria-label="Workspace trust indicators">
            <span class="rn-trust-pill">
                <svg class="rn-trust-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-5"/></svg>
                <strong>Secure cloud workspace</strong>
            </span>
            <span class="rn-trust-pill">
                <svg class="rn-trust-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2v20"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7H14.5a3.5 3.5 0 0 1 0 7H6"/></svg>
                <strong>GST-ready invoicing</strong>
            </span>
            <span class="rn-trust-pill">
                <svg class="rn-trust-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"/><path d="M9.5 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/></svg>
                <strong>Role-based access</strong>
            </span>
        </div>

        @hasSection('breadcrumbs')
            @yield('breadcrumbs')
        @elseif(!empty($breadcrumbItems))
            <nav class="desktop-breadcrumb" aria-label="Breadcrumb" style="display:flex; align-items:center; gap:7px; flex-wrap:wrap; margin:0 0 12px; padding:8px 10px; border:1px solid #e2e8f0; border-radius:12px; background:rgba(255,255,255,0.78); box-shadow:0 8px 22px rgba(15,23,42,0.035); font-size:12px; color:#64748b;">
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
@if(session('success') || session('error') || session('status'))
    <div
        data-action-toast
        style="position:fixed; right:18px; bottom:18px; z-index:1200; max-width:min(420px, calc(100vw - 36px)); padding:12px 14px; border-radius:14px; box-shadow:0 18px 42px rgba(15,23,42,0.22); font-size:13px; font-weight:700; line-height:1.4; color:{{ session('error') ? '#991b1b' : '#14532d' }}; background:{{ session('error') ? '#fef2f2' : '#ecfdf5' }}; border:1px solid {{ session('error') ? '#fecaca' : '#bbf7d0' }};">
        {{ session('error') ?: (session('success') ?: session('status')) }}
    </div>
@endif
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
                sessionStorage.setItem(scrollKey, String(window.scrollY || document.documentElement.scrollTop || 0));
            } catch (error) {
                // Storage can be disabled in strict browsers; action should still submit normally.
            }
        }, true);

        window.addEventListener('DOMContentLoaded', function () {
            try {
                const savedScroll = sessionStorage.getItem(scrollKey);

                if (savedScroll !== null) {
                    sessionStorage.removeItem(scrollKey);
                    window.requestAnimationFrame(function () {
                        window.scrollTo({ top: Math.max(parseInt(savedScroll, 10) - 80, 0), behavior: 'auto' });
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
            let savedSidebarState = {};

            try {
                savedSidebarState = JSON.parse(localStorage.getItem(sidebarStateKey) || '{}') || {};
            } catch (error) {
                savedSidebarState = {};
            }

            const persistSidebarState = function () {
                try {
                    localStorage.setItem(sidebarStateKey, JSON.stringify(savedSidebarState));
                } catch (error) {
                    // Storage can be unavailable; sidebar should still work for the session.
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

                panel.style.maxHeight = '';
                panel.style.opacity = shouldOpen ? '1' : '0';
                panel.hidden = !shouldOpen;
                group.classList.toggle('is-expanded', shouldOpen);
                group.classList.toggle('is-collapsed', !shouldOpen);
                toggle.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');

                if (shouldOpen) {
                    panel.style.maxHeight = panel.scrollHeight + 'px';
                } else {
                    panel.style.maxHeight = '0px';
                }

                toggle.addEventListener('click', function (event) {
                    event.preventDefault();
                    const isExpanded = toggle.getAttribute('aria-expanded') === 'true';
                    setSidebarGroupState(group, !isExpanded);
                });
            });

            const mobileFilterSheets = Array.from(document.querySelectorAll('[data-mobile-filter-sheet]'));
            const sortRoots = Array.from(document.querySelectorAll('[data-mobile-sort-root]'));
            const bodyLock = window.rentnexisModalLock;

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
@stack('scripts')
</body>
</html>
