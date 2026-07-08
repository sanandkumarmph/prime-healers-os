@extends('layouts.app')

@section('content')
@php
    $currentUser = auth()->user();
    $canCreateRoles = $currentUser?->canAccessModule('roles', 'create') ?? false;
    $canUpdateRoles = $currentUser?->canAccessModule('roles', 'update') ?? false;
    $canDeleteRoles = $currentUser?->canAccessModule('roles', 'delete') ?? false;
    $summary = $summary ?? ['total_roles' => $roles->total(), 'system_roles' => 0, 'custom_roles' => 0, 'assigned_users' => 0];
    $displayModules = ['dashboard' => 'Dashboard'] + $permissionModules;
    $moduleOrder = ['dashboard', 'rentals', 'sales', 'deliveries', 'assets', 'products', 'warehouses', 'invoices', 'payments', 'reports', 'settings', 'users', 'roles', 'cities', 'vendors'];
@endphp

<style>
.roles-page{max-width:1480px;margin:0 auto;padding-bottom:42px;color:#111827}.roles-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:14px}.roles-crumb{display:flex;gap:8px;color:#64748b;font-size:12px;font-weight:800;margin-bottom:8px}.roles-title{margin:0;font-size:28px;line-height:1.1;letter-spacing:-.03em}.roles-sub{margin:6px 0 0;color:#64748b;font-size:14px}.roles-primary{display:inline-flex;align-items:center;gap:8px;min-height:40px;padding:0 16px;border-radius:12px;background:#4f46e5;color:#fff;text-decoration:none;font-weight:900;box-shadow:0 12px 24px rgba(79,70,229,.18)}.roles-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:14px}.roles-summary-card{display:flex;align-items:center;gap:12px;min-height:76px;padding:14px;background:#fff;border:1px solid #dbe4f0;border-radius:14px;box-shadow:0 10px 24px rgba(15,23,42,.04)}.roles-icon,.role-card-icon{display:inline-flex;align-items:center;justify-content:center;font-weight:900;color:#4338ca;background:#eef2ff}.roles-icon{width:42px;height:42px;border-radius:14px}.roles-icon.green{color:#047857;background:#dcfce7}.roles-icon.blue{color:#1d4ed8;background:#dbeafe}.roles-icon.amber{color:#b45309;background:#fef3c7}.roles-value{font-size:24px;line-height:1;font-weight:900}.roles-label{margin-top:4px;color:#64748b;font-size:12px;font-weight:800}.roles-toolbar{display:grid;grid-template-columns:minmax(220px,1fr) auto;gap:12px;align-items:center;padding:12px;background:#fff;border:1px solid #dbe4f0;border-radius:16px;margin-bottom:14px}.roles-search{height:42px;border:1px solid #cbd5e1;border-radius:12px;padding:0 14px;font-size:14px;width:100%}.roles-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:flex-end}.roles-chip,.roles-sort,.roles-view{height:38px;border:1px solid #d6e0ee;background:#fff;color:#334155;border-radius:12px;padding:0 12px;font-size:13px;font-weight:900;cursor:pointer}.roles-chip.is-active,.roles-view.is-active{background:#eef2ff;color:#4338ca;border-color:#a5b4fc}.roles-sort{min-width:160px}.role-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.role-grid.is-list{grid-template-columns:1fr}.role-card{background:#fff;border:1px solid #dbe4f0;border-radius:16px;padding:14px;box-shadow:0 10px 24px rgba(15,23,42,.04);cursor:pointer;transition:.16s}.role-card:hover{border-color:#a5b4fc;transform:translateY(-1px);box-shadow:0 14px 30px rgba(15,23,42,.07)}.role-top{display:grid;grid-template-columns:52px minmax(0,1fr) auto;gap:12px;align-items:start}.role-card-icon{width:50px;height:50px;border-radius:16px;font-size:18px}.role-name-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.role-name{margin:0;font-size:17px;line-height:1.25;font-weight:900}.role-desc{margin:6px 0 0;color:#64748b;font-size:13px;line-height:1.4;min-height:36px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}.role-badge{display:inline-flex;align-items:center;height:24px;padding:0 9px;border-radius:999px;font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.02em}.role-badge.system{background:#f1f5f9;color:#475569}.role-badge.custom{background:#dbeafe;color:#1d4ed8}.role-badge.active{background:#dcfce7;color:#047857}.role-badge.inactive{background:#fee2e2;color:#b91c1c}.role-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:0;margin-top:12px;border:1px solid #edf2f7;border-radius:14px;overflow:hidden;background:#f8fafc}.role-stat{padding:10px;text-align:center;border-right:1px solid #e5edf6}.role-stat:last-child{border-right:0}.role-stat-label{color:#64748b;font-size:11px;font-weight:900;text-transform:uppercase}.role-stat-value{margin-top:3px;font-size:18px;line-height:1;font-weight:900}.role-access{display:flex;gap:6px;flex-wrap:wrap;margin-top:12px}.role-access-chip{display:inline-flex;align-items:center;min-height:24px;padding:0 9px;border-radius:999px;background:#f1f5f9;color:#475569;font-size:11px;font-weight:800}.role-actions-row{display:flex;gap:8px;align-items:center;margin-top:12px}.role-action{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:36px;padding:0 12px;border:1px solid #d6e0ee;border-radius:11px;background:#fff;color:#334155;font-weight:900;font-size:13px;text-decoration:none;cursor:pointer}.role-action.icon{width:38px;padding:0}.role-action.danger{color:#dc2626;background:#fff1f2;border-color:#fecaca}.role-menu{position:relative}.role-menu[open] .role-menu-panel{display:grid}.role-menu summary{list-style:none}.role-menu summary::-webkit-details-marker{display:none}.role-menu-panel{display:none;position:absolute;right:0;top:44px;z-index:30;width:190px;padding:8px;border:1px solid #dbe4f0;border-radius:14px;background:#fff;box-shadow:0 18px 36px rgba(15,23,42,.14);gap:6px}.role-menu-item{display:flex;width:100%;align-items:center;min-height:36px;padding:0 10px;border:0;border-radius:10px;background:transparent;color:#334155;text-decoration:none;font-weight:900;font-size:13px;cursor:pointer}.role-menu-item:hover{background:#f8fafc}.roles-empty{padding:28px;border-radius:18px;border:1px dashed #cbd5e1;background:#fff;color:#64748b}.roles-pager{margin-top:18px}.drawer-backdrop{position:fixed;inset:0;z-index:80;background:rgba(15,23,42,.34);opacity:0;pointer-events:none;transition:.18s}.role-drawer{position:fixed;top:0;right:0;bottom:0;z-index:81;width:min(520px,100vw);background:#fff;border-left:1px solid #dbe4f0;box-shadow:-24px 0 60px rgba(15,23,42,.18);transform:translateX(102%);transition:.2s;display:flex;flex-direction:column}.role-drawer-open .drawer-backdrop{opacity:1;pointer-events:auto}.role-drawer-open .role-drawer{transform:translateX(0)}.drawer-scroll{overflow:auto;padding:18px}.drawer-close{width:38px;height:38px;border-radius:12px;border:1px solid #d6e0ee;background:#fff;color:#334155;font-size:20px;cursor:pointer}.drawer-head{display:flex;align-items:start;justify-content:space-between;gap:12px;margin-bottom:14px}.drawer-title-row{display:flex;gap:12px;align-items:center}.drawer-title{margin:0;font-size:22px;font-weight:900;letter-spacing:-.02em}.drawer-tabs{display:flex;gap:6px;border-bottom:1px solid #e5edf6;margin:14px -18px 14px;padding:0 18px;overflow-x:auto}.tab-btn{border:0;background:transparent;color:#475569;padding:10px 8px;font-size:13px;font-weight:900;cursor:pointer;border-bottom:2px solid transparent;white-space:nowrap}.tab-btn.is-active{color:#4338ca;border-color:#4f46e5}.tab-panel[hidden]{display:none}.drawer-card{border:1px solid #dbe4f0;border-radius:14px;padding:12px;background:#fff;margin-bottom:10px}.metric-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}.metric{padding:12px;background:#f8fafc;border:1px solid #e5edf6;border-radius:12px}.metric-label{color:#64748b;font-size:11px;font-weight:900;text-transform:uppercase}.metric-value{margin-top:4px;font-size:22px;font-weight:900}.module-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.module-cell{display:flex;justify-content:space-between;gap:8px;align-items:center;min-height:38px;padding:0 10px;border:1px solid #e5edf6;border-radius:11px;color:#334155;font-size:13px;font-weight:900}.module-cell.enabled{background:#f0fdf4;border-color:#bbf7d0;color:#166534}.user-row{display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid #edf2f7}.user-row:last-child{border-bottom:0}.user-avatar{width:34px;height:34px;border-radius:999px;background:#eef2ff;color:#4338ca;display:inline-flex;align-items:center;justify-content:center;font-weight:900;font-size:12px}.permission-group{border:1px solid #e5edf6;border-radius:12px;margin-bottom:8px;overflow:hidden}.permission-group summary{cursor:pointer;padding:11px 12px;font-weight:900;list-style:none;background:#f8fafc}.permission-group summary::-webkit-details-marker{display:none}.permission-list{display:flex;flex-wrap:wrap;gap:6px;padding:10px 12px}.permission-pill{display:inline-flex;padding:4px 8px;border-radius:999px;background:#eef2ff;color:#4338ca;font-size:11px;font-weight:900;text-transform:uppercase}.drawer-footer{margin-top:14px;padding-top:14px;border-top:1px solid #e5edf6;display:flex;gap:8px;flex-wrap:wrap}@media(max-width:1100px){.roles-summary{grid-template-columns:repeat(2,minmax(0,1fr))}.role-grid{grid-template-columns:1fr}}@media(max-width:720px){.roles-page{padding-bottom:96px}.roles-head{align-items:stretch;flex-direction:column}.roles-title{font-size:24px}.roles-summary{grid-template-columns:repeat(2,minmax(0,1fr))}.roles-summary-card{min-height:68px;padding:10px}.roles-icon{width:36px;height:36px;border-radius:12px}.roles-toolbar{grid-template-columns:1fr}.roles-actions{justify-content:flex-start;overflow-x:auto;flex-wrap:nowrap;padding-bottom:3px}.roles-chip,.roles-sort,.roles-view{flex:0 0 auto}.role-card{padding:12px}.role-top{grid-template-columns:44px minmax(0,1fr)}.role-top>.role-badge{grid-column:1/-1;justify-self:start}.role-card-icon{width:42px;height:42px;border-radius:14px}.role-drawer{width:100vw;top:auto;max-height:92vh;border-radius:20px 20px 0 0;border-left:0;transform:translateY(105%)}.role-drawer-open .role-drawer{transform:translateY(0)}.metric-grid,.module-grid{grid-template-columns:1fr}}
</style>
<style id="roles-drawer-viewport-fix">
    .drawer-backdrop {
        z-index: 99980 !important;
    }

    .role-drawer {
        top: max(16px, env(safe-area-inset-top)) !important;
        right: 16px !important;
        bottom: 16px !important;
        width: min(500px, calc(100vw - 32px)) !important;
        max-width: calc(100vw - 32px) !important;
        max-height: calc(100dvh - 32px) !important;
        box-sizing: border-box !important;
        border: 1px solid #dbe4f0 !important;
        border-radius: 18px !important;
        overflow: hidden !important;
        z-index: 99990 !important;
        transform: translateX(calc(100% + 32px)) !important;
    }

    .role-drawer-open .role-drawer {
        transform: translateX(0) !important;
    }

    .drawer-scroll {
        flex: 1 1 auto !important;
        min-height: 0 !important;
        max-height: 100% !important;
        overflow: auto !important;
        overscroll-behavior: contain !important;
        box-sizing: border-box !important;
    }

    @media (max-width: 900px) {
        .role-drawer {
            left: 10px !important;
            right: 10px !important;
            top: auto !important;
            bottom: max(10px, env(safe-area-inset-bottom)) !important;
            width: auto !important;
            max-width: none !important;
            max-height: min(86dvh, 720px) !important;
            border-radius: 18px 18px 0 0 !important;
            transform: translateY(calc(100% + 18px)) !important;
        }

        .role-drawer-open .role-drawer {
            transform: translateY(0) !important;
        }
    }

    @media (max-width: 480px) {
        .drawer-scroll {
            padding: 14px !important;
        }

        .drawer-tabs {
            margin-left: -14px !important;
            margin-right: -14px !important;
            padding-inline: 14px !important;
        }
    }
</style>
<div class="roles-page" data-roles-page>
    <div class="roles-head">
        <div>
            <div class="roles-crumb"><span>Home</span><span>/</span><span>Company Settings</span><span>/</span><strong>Roles</strong></div>
            <h1 class="roles-title">Roles</h1>
            <p class="roles-sub">Manage reusable access templates for users, operations, reports, and settings.</p>
        </div>
        @if($canCreateRoles)
            <a class="roles-primary" href="{{ route('roles.create') }}" aria-label="Add role"><span aria-hidden="true">+</span><span>Add Role</span></a>
        @endif
    </div>
    @if(session('success'))
        <div style="margin-bottom:12px; padding:12px 14px; border-radius:14px; background:#ecfdf5; border:1px solid #bbf7d0; color:#166534; font-weight:700;">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div style="margin-bottom:12px; padding:12px 14px; border-radius:14px; background:#fff1f2; border:1px solid #fecaca; color:#991b1b; font-weight:700;">{{ session('error') }}</div>
    @endif

    <section class="roles-summary" aria-label="Role summary">
        <div class="roles-summary-card"><span class="roles-icon">R</span><div><div class="roles-value">{{ $summary['total_roles'] }}</div><div class="roles-label">Total Roles</div></div></div>
        <div class="roles-summary-card"><span class="roles-icon green">S</span><div><div class="roles-value">{{ $summary['system_roles'] }}</div><div class="roles-label">System Roles</div></div></div>
        <div class="roles-summary-card"><span class="roles-icon blue">C</span><div><div class="roles-value">{{ $summary['custom_roles'] }}</div><div class="roles-label">Custom Roles</div></div></div>
        <div class="roles-summary-card"><span class="roles-icon amber">U</span><div><div class="roles-value">{{ $summary['assigned_users'] }}</div><div class="roles-label">Total Assigned Users</div></div></div>
    </section>

    <section class="roles-toolbar" aria-label="Role filters">
        <input class="roles-search" type="search" value="{{ $search }}" placeholder="Search roles by name or description..." data-role-search aria-label="Search roles by name or description">
        <div class="roles-actions">
            <button type="button" class="roles-chip is-active" data-role-filter="all">All</button>
            <button type="button" class="roles-chip" data-role-filter="system">System</button>
            <button type="button" class="roles-chip" data-role-filter="custom">Custom</button>
            <button type="button" class="roles-chip" data-role-filter="active">Active</button>
            <button type="button" class="roles-chip" data-role-filter="inactive">Inactive</button>
            <select class="roles-sort" data-role-sort aria-label="Sort roles">
                <option value="name">Name A-Z</option>
                <option value="users">Users</option>
                <option value="modules">Modules</option>
                <option value="permissions">Permissions</option>
            </select>
            <button type="button" class="roles-view is-active" data-role-view="grid" aria-label="Grid view">Grid</button>
            <button type="button" class="roles-view" data-role-view="list" aria-label="List view">List</button>
        </div>
    </section>

    <section>
        <div class="role-grid" data-role-grid>
            @forelse($roles as $role)
                @php
                    $permissions = collect($role->permissions ?? []);
                    $modulePermissions = $permissions->except('__special');
                    $enabledModuleKeys = collect($moduleOrder)
                        ->filter(fn ($key) => $modulePermissions->has($key) && collect($modulePermissions->get($key))->isNotEmpty())
                        ->merge($modulePermissions->keys()->diff($moduleOrder))
                        ->values();
                    $moduleCount = $enabledModuleKeys->count();
                    $permissionCount = $modulePermissions->sum(fn ($actions) => collect($actions)->count()) + collect($permissions->get('__special', []))->count();
                    $topModules = $enabledModuleKeys->take(4);
                    $extraModuleCount = max(0, $moduleCount - $topModules->count());
                    $assignedUsers = $role->relationLoaded('users') ? $role->users : collect();
                    $roleInitial = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $role->name) ?: 'R', 0, 1));
                    $roleSearchText = strtolower($role->name.' '.$role->slug.' '.$role->description.' '.$enabledModuleKeys->implode(' '));
                @endphp

                <article class="role-card" tabindex="0" data-role-card data-role-id="{{ $role->id }}" data-role-name="{{ strtolower($role->name) }}" data-role-users="{{ $role->users_count }}" data-role-modules="{{ $moduleCount }}" data-role-permissions="{{ $permissionCount }}" data-role-system="{{ $role->is_system ? '1' : '0' }}" data-role-active="{{ $role->is_active ? '1' : '0' }}" data-role-search-text="{{ e($roleSearchText) }}">
                    <div class="role-top">
                        <div class="role-card-icon" aria-hidden="true">{{ $roleInitial }}</div>
                        <div>
                            <div class="role-name-row">
                                <h2 class="role-name">{{ $role->name }}</h2>
                                <span class="role-badge {{ $role->is_system ? 'system' : 'custom' }}">{{ $role->is_system ? 'System' : 'Custom' }}</span>
                            </div>
                            <p class="role-desc">{{ $role->description ?: 'No description added.' }}</p>
                        </div>
                        <span class="role-badge {{ $role->is_active ? 'active' : 'inactive' }}">{{ $role->is_active ? 'Active' : 'Inactive' }}</span>
                    </div>

                    <div class="role-stats" aria-label="{{ $role->name }} statistics">
                        <div class="role-stat"><div class="role-stat-label">Users</div><div class="role-stat-value">{{ $role->users_count }}</div></div>
                        <div class="role-stat"><div class="role-stat-label">Modules</div><div class="role-stat-value">{{ $moduleCount }}</div></div>
                        <div class="role-stat"><div class="role-stat-label">Permissions</div><div class="role-stat-value">{{ $permissionCount }}</div></div>
                    </div>

                    <div class="role-access" aria-label="Top access modules">
                        @forelse($topModules as $moduleKey)
                            <span class="role-access-chip">{{ $displayModules[$moduleKey] ?? str($moduleKey)->replace('_', ' ')->title() }}</span>
                        @empty
                            <span class="role-access-chip">No module access</span>
                        @endforelse
                        @if($extraModuleCount > 0)
                            <span class="role-access-chip">+{{ $extraModuleCount }} more</span>
                        @endif
                    </div>

                    <div class="role-actions-row" data-role-actions>
                        <button type="button" class="role-action" data-open-role-drawer="{{ $role->id }}" aria-label="Preview {{ $role->name }}">View</button>
                        @if($canUpdateRoles)
                            <a class="role-action" href="{{ route('roles.edit', $role) }}" aria-label="Edit {{ $role->name }}">Edit</a>
                        @endif
                        <details class="role-menu">
                            <summary class="role-action icon" aria-label="More actions for {{ $role->name }}" title="More actions">...</summary>
                            <div class="role-menu-panel">
                                <a class="role-menu-item" href="{{ route('roles.show', $role) }}">Open full page</a>
                                @if($canUpdateRoles)
                                    <a class="role-menu-item" href="{{ route('roles.edit', $role) }}">Edit role</a>
                                @endif
                                @if($canDeleteRoles && (int) $role->users_count === 0)
                                    <form method="POST" action="{{ route('roles.destroy', $role) }}" onsubmit="return confirm('Delete this role?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="role-menu-item danger">Delete role</button>
                                    </form>
                                @endif
                            </div>
                        </details>
                    </div>
                </article>
                <template id="role-preview-{{ $role->id }}">
                    <div class="drawer-head">
                        <div class="drawer-title-row">
                            <div class="role-card-icon" aria-hidden="true">{{ $roleInitial }}</div>
                            <div>
                                <h2 class="drawer-title">{{ $role->name }}</h2>
                                <div style="display:flex; gap:6px; flex-wrap:wrap; margin-top:8px;">
                                    <span class="role-badge {{ $role->is_active ? 'active' : 'inactive' }}">{{ $role->is_active ? 'Active' : 'Inactive' }}</span>
                                    <span class="role-badge {{ $role->is_system ? 'system' : 'custom' }}">{{ $role->is_system ? 'System' : 'Custom' }}</span>
                                </div>
                            </div>
                        </div>
                        <button type="button" class="drawer-close" data-close-role-drawer aria-label="Close role preview">x</button>
                    </div>
                    <p style="margin:0 0 12px; color:#64748b; line-height:1.5;">{{ $role->description ?: 'No description added.' }}</p>

                    <div class="drawer-tabs" role="tablist">
                        <button type="button" class="tab-btn is-active" data-role-tab="overview">Overview</button>
                        <button type="button" class="tab-btn" data-role-tab="users">Users ({{ $role->users_count }})</button>
                        <button type="button" class="tab-btn" data-role-tab="modules">Modules ({{ $moduleCount }})</button>
                        <button type="button" class="tab-btn" data-role-tab="permissions">Permissions ({{ $permissionCount }})</button>
                    </div>

                    <section class="tab-panel" data-role-tab-panel="overview">
                        <div class="metric-grid">
                            <div class="metric"><div class="metric-label">Users</div><div class="metric-value">{{ $role->users_count }}</div></div>
                            <div class="metric"><div class="metric-label">Modules</div><div class="metric-value">{{ $moduleCount }}</div></div>
                            <div class="metric"><div class="metric-label">Permissions</div><div class="metric-value">{{ $permissionCount }}</div></div>
                        </div>
                        <div class="drawer-card" style="margin-top:10px;">
                            <strong>Top access</strong>
                            <div class="role-access">
                                @forelse($enabledModuleKeys->take(8) as $moduleKey)
                                    <span class="role-access-chip">{{ $displayModules[$moduleKey] ?? str($moduleKey)->replace('_', ' ')->title() }}</span>
                                @empty
                                    <span class="role-access-chip">No module access</span>
                                @endforelse
                            </div>
                        </div>
                    </section>

                    <section class="tab-panel" data-role-tab-panel="users" hidden>
                        <div class="drawer-card">
                            @forelse($assignedUsers->take(8) as $assignedUser)
                                @php($userInitials = collect(explode(' ', $assignedUser->name))->filter()->map(fn ($part) => strtoupper(substr($part, 0, 1)))->take(2)->implode('') ?: 'U')
                                <div class="user-row">
                                    <span class="user-avatar">{{ $userInitials }}</span>
                                    <div style="min-width:0; flex:1;">
                                        <div style="font-weight:900;">{{ $assignedUser->name }}</div>
                                        <div style="color:#64748b; font-size:12px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">{{ $assignedUser->email ?: $assignedUser->phone ?: 'No contact set' }}</div>
                                    </div>
                                    <span class="role-badge {{ $assignedUser->is_active ? 'active' : 'inactive' }}">{{ $assignedUser->is_active ? 'Active' : 'Inactive' }}</span>
                                </div>
                            @empty
                                <div style="color:#64748b;">No users assigned to this role.</div>
                            @endforelse
                        </div>
                        <a class="role-action" href="{{ route('users.index', ['role_id' => $role->id]) }}">View all users</a>
                    </section>

                    <section class="tab-panel" data-role-tab-panel="modules" hidden>
                        <div class="module-grid">
                            @foreach($displayModules as $moduleKey => $moduleLabel)
                                @php($enabled = $modulePermissions->has($moduleKey) && collect($modulePermissions->get($moduleKey))->isNotEmpty())
                                <div class="module-cell {{ $enabled ? 'enabled' : '' }}">
                                    <span>{{ $moduleLabel }}</span>
                                    <span>{{ $enabled ? 'On' : 'Off' }}</span>
                                </div>
                            @endforeach
                        </div>
                    </section>

                    <section class="tab-panel" data-role-tab-panel="permissions" hidden>
                        @forelse($modulePermissions as $moduleKey => $actions)
                            <details class="permission-group">
                                <summary>{{ $displayModules[$moduleKey] ?? str($moduleKey)->replace('_', ' ')->title() }} ({{ collect($actions)->count() }})</summary>
                                <div class="permission-list">
                                    @foreach(collect($actions) as $action)
                                        <span class="permission-pill">{{ $action }}</span>
                                    @endforeach
                                </div>
                            </details>
                        @empty
                            <div class="drawer-card" style="color:#64748b;">No module permissions configured.</div>
                        @endforelse
                        @if(collect($permissions->get('__special', []))->isNotEmpty())
                            <details class="permission-group">
                                <summary>Special Permissions ({{ collect($permissions->get('__special', []))->count() }})</summary>
                                <div class="permission-list">
                                    @foreach(collect($permissions->get('__special', [])) as $specialPermission)
                                        <span class="permission-pill">{{ $specialPermissions[$specialPermission] ?? $specialPermission }}</span>
                                    @endforeach
                                </div>
                            </details>
                        @endif
                    </section>

                    <div class="drawer-footer">
                        @if($canUpdateRoles)
                            <a class="role-action" href="{{ route('roles.edit', $role) }}">Edit Role</a>
                        @endif
                        <a class="role-action" href="{{ route('roles.show', $role) }}">Open Full Page</a>
                        @if($canDeleteRoles && (int) $role->users_count === 0)
                            <form method="POST" action="{{ route('roles.destroy', $role) }}" onsubmit="return confirm('Delete this role?');">
                                @csrf
                                @method('DELETE')
                                <button class="role-action danger" type="submit">Delete Role</button>
                            </form>
                        @endif
                    </div>
                </template>
            @empty
                <div class="roles-empty">No roles found yet.</div>
            @endforelse
        </div>
    </section>

    <div class="roles-pager">{{ $roles->links() }}</div>
</div>

<div class="drawer-backdrop" data-close-role-drawer></div>
<aside class="role-drawer" data-role-drawer aria-label="Role preview" aria-hidden="true">
    <div class="drawer-scroll" data-role-drawer-content></div>
</aside>
<script>
(function () {
    const page = document.querySelector('[data-roles-page]');
    if (!page) return;

    const grid = page.querySelector('[data-role-grid]');
    const search = page.querySelector('[data-role-search]');
    const chips = Array.from(page.querySelectorAll('[data-role-filter]'));
    const sort = page.querySelector('[data-role-sort]');
    const viewToggles = Array.from(page.querySelectorAll('[data-role-view]'));
    const drawer = document.querySelector('[data-role-drawer]');
    const drawerContent = document.querySelector('[data-role-drawer-content]');
    let activeFilter = 'all';
    let lastTrigger = null;

    function cards() {
        return Array.from(grid.querySelectorAll('[data-role-card]'));
    }

    function statKey(mode) {
        return 'role' + mode.charAt(0).toUpperCase() + mode.slice(1);
    }

    function applyFilters() {
        const query = (search?.value || '').trim().toLowerCase();
        cards().forEach(card => {
            const matchesSearch = !query || (card.dataset.roleSearchText || '').includes(query);
            const matchesFilter = activeFilter === 'all'
                || (activeFilter === 'system' && card.dataset.roleSystem === '1')
                || (activeFilter === 'custom' && card.dataset.roleSystem === '0')
                || (activeFilter === 'active' && card.dataset.roleActive === '1')
                || (activeFilter === 'inactive' && card.dataset.roleActive === '0');
            card.hidden = !(matchesSearch && matchesFilter);
        });
    }

    function applySort() {
        const mode = sort?.value || 'name';
        const sorted = cards().sort((a, b) => {
            if (mode === 'name') return (a.dataset.roleName || '').localeCompare(b.dataset.roleName || '');
            return Number(b.dataset[statKey(mode)] || 0) - Number(a.dataset[statKey(mode)] || 0);
        });
        sorted.forEach(card => grid.appendChild(card));
    }

    function closeMenus() {
        page.querySelectorAll('.role-menu[open]').forEach(menu => menu.removeAttribute('open'));
    }

    function openDrawer(roleId, trigger) {
        const template = document.getElementById('role-preview-' + roleId);
        if (!template || !drawer || !drawerContent) return;
        closeMenus();
        lastTrigger = trigger || document.activeElement;
        drawerContent.innerHTML = template.innerHTML;
        drawerContent.scrollTop = 0;
        drawer.scrollTop = 0;
        drawer.setAttribute('aria-hidden', 'false');
        document.body.classList.add('role-drawer-open');
        const closeButton = drawerContent.querySelector('[data-close-role-drawer]');
        if (closeButton) closeButton.focus({ preventScroll: true });
    }

    function closeDrawer() {
        if (!drawer) return;
        drawer.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('role-drawer-open');
        drawerContent.innerHTML = '';
        if (lastTrigger && typeof lastTrigger.focus === 'function') lastTrigger.focus({ preventScroll: true });
    }

    search?.addEventListener('input', applyFilters);
    chips.forEach(chip => chip.addEventListener('click', () => {
        activeFilter = chip.dataset.roleFilter || 'all';
        chips.forEach(item => item.classList.toggle('is-active', item === chip));
        applyFilters();
    }));
    sort?.addEventListener('change', () => { applySort(); applyFilters(); });
    viewToggles.forEach(toggle => toggle.addEventListener('click', () => {
        const view = toggle.dataset.roleView;
        viewToggles.forEach(item => item.classList.toggle('is-active', item === toggle));
        grid.classList.toggle('is-list', view === 'list');
    }));

    document.addEventListener('click', event => {
        const openButton = event.target.closest('[data-open-role-drawer]');
        if (openButton) {
            event.preventDefault();
            event.stopPropagation();
            openDrawer(openButton.dataset.openRoleDrawer, openButton);
            return;
        }

        const card = event.target.closest('[data-role-card]');
        if (card && !event.target.closest('[data-role-actions]')) {
            openDrawer(card.dataset.roleId, card);
            return;
        }

        if (event.target.closest('[data-close-role-drawer]')) {
            event.preventDefault();
            closeDrawer();
            return;
        }

        if (!event.target.closest('.role-menu')) closeMenus();
    });

    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') {
            closeMenus();
            closeDrawer();
        }
        if ((event.key === 'Enter' || event.key === ' ') && event.target.matches('[data-role-card]')) {
            event.preventDefault();
            openDrawer(event.target.dataset.roleId, event.target);
        }
    });

    document.addEventListener('click', event => {
        const tab = event.target.closest('.tab-btn');
        if (!tab || !drawer?.contains(tab)) return;
        const target = tab.dataset.roleTab;
        drawer.querySelectorAll('.tab-btn').forEach(button => button.classList.toggle('is-active', button === tab));
        drawer.querySelectorAll('[data-role-tab-panel]').forEach(panel => {
            panel.hidden = panel.dataset.roleTabPanel !== target;
        });
    });

    applySort();
    applyFilters();
})();
</script>
@endsection
