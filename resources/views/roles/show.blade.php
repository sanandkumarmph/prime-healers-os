@extends('layouts.app')

@section('content')
@php
    $currentUser = auth()->user();
    $canUpdateRoles = $currentUser?->canAccessModule('roles', 'update') ?? false;
    $canDeleteRoles = $currentUser?->canAccessModule('roles', 'delete') ?? false;
@endphp
<style>
    @media (max-width: 767px) {
        .role-show-header,
        .role-show-layout {
            grid-template-columns: 1fr !important;
        }
        .role-show-header {
            flex-direction: column;
            margin-bottom: 18px !important;
        }
        .role-show-header h1 {
            font-size: 26px !important;
            overflow-wrap: anywhere;
        }
        .role-show-actions {
            width: 100%;
        }
        .role-show-actions > * {
            flex: 1 1 calc(50% - 10px);
            min-width: 0;
        }
        .role-summary-grid {
            grid-template-columns: 1fr !important;
        }
        .role-users-table {
            display: none;
        }
        .role-users-mobile {
            display: grid !important;
            gap: 12px;
            padding: 16px;
        }
    }
</style>
<div style="max-width:1240px; margin:0 auto;">
    <div class="role-show-header" style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; margin-bottom:24px;">
        <div>
            <div style="display:inline-flex; padding:6px 10px; border-radius:999px; background:#eff6ff; color:#1d4ed8; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.08em;">Company Settings</div>
            <h1 style="margin:12px 0 8px; font-size:34px; letter-spacing:-0.03em;">{{ $role->name }}</h1>
            <p style="margin:0; color:#64748b;">Permission matrix and users currently mapped to this role.</p>
        </div>
        <div class="role-show-actions page-header-actions" style="display:flex; gap:10px; flex-wrap:wrap;">
            @if($canUpdateRoles)
                <a href="{{ route('roles.edit', $role) }}" style="display:inline-flex; align-items:center; justify-content:center; padding:11px 16px; border-radius:12px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a; text-decoration:none; font-weight:600;">Edit</a>
            @endif
            @if($canDeleteRoles)
                @php($canDeleteThisRole = $role->users->isEmpty())
                @if($canDeleteThisRole)
                    <form method="POST" action="{{ route('roles.destroy', $role) }}" style="margin:0;" onsubmit="return confirm('Delete this role? This will be blocked if users are assigned.');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" style="display:inline-flex; align-items:center; justify-content:center; padding:11px 16px; border:none; border-radius:12px; background:#fff1f2; color:#be123c; font-weight:700; cursor:pointer;">Delete</button>
                    </form>
                @else
                    <button type="button" disabled title="Remove assigned users before deleting this role." style="display:inline-flex; align-items:center; justify-content:center; padding:11px 16px; border:none; border-radius:12px; background:#f1f5f9; color:#94a3b8; font-weight:700; cursor:not-allowed;">Delete</button>
                @endif
            @endif
            <a href="{{ route('roles.index') }}" style="display:inline-flex; align-items:center; justify-content:center; padding:11px 16px; border-radius:12px; background:#1d4ed8; color:#ffffff; text-decoration:none; font-weight:700;">Back to Roles</a>
        </div>
    </div>

    @if(session('success'))
        <div style="margin-bottom:18px; padding:14px 16px; border-radius:16px; background:#ecfdf5; border:1px solid #bbf7d0; color:#166534;">{{ session('success') }}</div>
    @endif

    @if(session('error'))
        <div style="margin-bottom:18px; padding:14px 16px; border-radius:16px; background:#fff1f2; border:1px solid #fecaca; color:#991b1b;">{{ session('error') }}</div>
    @endif

    <div class="role-show-layout" style="display:grid; grid-template-columns:0.95fr 1.05fr; gap:18px;">
        <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:22px; padding:22px;">
            <h2 style="margin:0 0 16px; font-size:20px;">Role Summary</h2>
            <div class="role-summary-grid" style="display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:14px;">
                <div><div style="font-size:12px; color:#64748b; text-transform:uppercase; font-weight:700;">Slug</div><div style="margin-top:6px;">{{ $role->slug }}</div></div>
                <div><div style="font-size:12px; color:#64748b; text-transform:uppercase; font-weight:700;">Status</div><div style="margin-top:6px;">{{ $role->is_active ? 'Active' : 'Inactive' }}</div></div>
                <div><div style="font-size:12px; color:#64748b; text-transform:uppercase; font-weight:700;">System Role</div><div style="margin-top:6px;">{{ $role->is_system ? 'Yes' : 'No' }}</div></div>
                <div><div style="font-size:12px; color:#64748b; text-transform:uppercase; font-weight:700;">Users</div><div style="margin-top:6px;">{{ $role->users->count() }}</div></div>
                <div style="grid-column:1 / -1;"><div style="font-size:12px; color:#64748b; text-transform:uppercase; font-weight:700;">Description</div><div style="margin-top:6px; color:#334155;">{{ $role->description ?: 'No description added.' }}</div></div>
            </div>
        </div>

        <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:22px; padding:22px;">
            <h2 style="margin:0 0 16px; font-size:20px;">Permissions</h2>
            <div style="display:grid; gap:12px;">
                @foreach($permissionModules as $moduleKey => $moduleLabel)
                    @php($actions = $role->permissions[$moduleKey] ?? [])
                    <div style="padding:14px 16px; border:1px solid #e2e8f0; border-radius:16px; background:#f8fafc;">
                        <div style="font-weight:700; color:#0f172a;">{{ $moduleLabel }}</div>
                        <div style="margin-top:8px; display:flex; gap:8px; flex-wrap:wrap;">
                            @forelse($actions as $action)
                                <span style="display:inline-flex; padding:6px 10px; border-radius:999px; background:#dbeafe; color:#1d4ed8; font-size:11px; font-weight:700; text-transform:uppercase;">{{ $action }}</span>
                            @empty
                                <span style="color:#64748b; font-size:13px;">No access assigned</span>
                            @endforelse
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <div style="margin-top:18px; background:#ffffff; border:1px solid #e2e8f0; border-radius:22px; overflow:hidden;" class="rn-table-shell responsive-table-shell">
        <div style="padding:18px 22px; border-bottom:1px solid #e2e8f0;">
            <h2 style="margin:0; font-size:20px;">Users Mapped to This Role</h2>
        </div>
        <div class="role-users-table responsive-table-scroll" style="overflow:auto;">
            <table style="width:100%; border-collapse:collapse;">
                <thead style="background:#f8fafc;">
                    <tr>
                        <th style="text-align:left; padding:14px 18px; font-size:12px; color:#64748b; text-transform:uppercase;">User</th>
                        <th style="text-align:left; padding:14px 18px; font-size:12px; color:#64748b; text-transform:uppercase;">City</th>
                        <th style="text-align:left; padding:14px 18px; font-size:12px; color:#64748b; text-transform:uppercase;">Email</th>
                        <th style="text-align:right; padding:14px 18px; font-size:12px; color:#64748b; text-transform:uppercase;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($role->users as $user)
                        <tr style="border-top:1px solid #e2e8f0;">
                            <td style="padding:16px 18px;">{{ $user->name }}</td>
                            <td style="padding:16px 18px;">{{ $user->cityRecord?->name ?? 'Not mapped' }}</td>
                            <td style="padding:16px 18px;">{{ $user->email }}</td>
                            <td style="padding:16px 18px; text-align:right;"><a href="{{ route('users.show', $user) }}" style="color:#0f766e; font-weight:700; text-decoration:none;">View User</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="4" style="padding:24px 18px; color:#64748b;">No users mapped to this role yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="role-users-mobile" style="display:none;">
            @forelse($role->users as $user)
                <div class="mobile-card-item">
                    <div class="mobile-field-row"><span>User</span><div>{{ $user->name }}</div></div>
                    <div class="mobile-field-row"><span>City</span><div>{{ $user->cityRecord?->name ?? 'Not mapped' }}</div></div>
                    <div class="mobile-field-row"><span>Email</span><div>{{ $user->email }}</div></div>
                    <a href="{{ route('users.show', $user) }}" class="rn-btn">View User</a>
                </div>
            @empty
                <div class="mobile-card-item">
                    <div style="color:#64748b; font-size:13px;">No users mapped to this role yet.</div>
                </div>
            @endforelse
        </div>
    </div>
</div>
@endsection
