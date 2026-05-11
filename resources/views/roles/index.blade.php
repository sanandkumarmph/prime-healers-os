@extends('layouts.app')

@section('content')
@php
    $currentUser = auth()->user();
    $canCreateRoles = $currentUser?->canAccessModule('roles', 'create') ?? false;
    $canUpdateRoles = $currentUser?->canAccessModule('roles', 'update') ?? false;
    $canDeleteRoles = $currentUser?->canAccessModule('roles', 'delete') ?? false;
@endphp
<div style="max-width:1280px; margin:0 auto;">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; margin-bottom:24px;">
        <div>
            <div style="display:inline-flex; padding:6px 10px; border-radius:999px; background:#eff6ff; color:#1d4ed8; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.08em;">Company Settings</div>
            <h1 style="margin:12px 0 8px; font-size:34px; letter-spacing:-0.03em;">Roles</h1>
            <p style="margin:0; color:#64748b;">Manage reusable access templates for users, operations, reports, and settings.</p>
        </div>
        @if($canCreateRoles)
            <a href="{{ route('roles.create') }}" style="display:inline-flex; align-items:center; justify-content:center; padding:11px 16px; border-radius:12px; background:#1d4ed8; color:#ffffff; text-decoration:none; font-weight:700;">+ Add Role</a>
        @endif
    </div>

    <details style="margin-bottom:18px; border:1px solid #e2e8f0; border-radius:20px; background:#ffffff;">
        <summary style="cursor:pointer; list-style:none; padding:16px 18px; font-weight:800; color:#0f172a;">Filter / Sort <span style="color:#64748b; font-size:12px;">{{ $search ? 'Active' : 'Expand' }}</span></summary>
        <form method="GET" action="{{ route('roles.index') }}" style="display:flex; gap:12px; padding:0 18px 18px;">
            <input type="text" name="search" value="{{ $search }}" placeholder="Search role name or slug" style="flex:1; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px;">
            <button type="submit" style="padding:12px 16px; border:none; border-radius:14px; background:#0f172a; color:#ffffff; font-weight:700; cursor:pointer;">Apply</button>
            <a href="{{ route('roles.index') }}" style="display:inline-flex; align-items:center; justify-content:center; padding:12px 14px; border-radius:14px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a; text-decoration:none; font-weight:600;">Reset</a>
        </form>
    </details>

    @if(session('success'))
        <div style="margin-bottom:18px; padding:14px 16px; border-radius:16px; background:#ecfdf5; border:1px solid #bbf7d0; color:#166534;">{{ session('success') }}</div>
    @endif

    @if(session('error'))
        <div style="margin-bottom:18px; padding:14px 16px; border-radius:16px; background:#fff1f2; border:1px solid #fecaca; color:#991b1b;">{{ session('error') }}</div>
    @endif

    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:14px;">
        @forelse($roles as $role)
            <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:18px; padding:16px;">
                <div style="display:flex; justify-content:space-between; gap:12px; align-items:flex-start;">
                    <div>
                        <h2 style="margin:0; font-size:18px; line-height:1.2; letter-spacing:-0.02em;">{{ $role->name }}</h2>
                        <p style="margin:4px 0 0; color:#64748b; font-size:12px;">{{ $role->slug }}</p>
                    </div>
                    <span style="display:inline-flex; padding:5px 9px; border-radius:999px; background:{{ $role->is_system ? '#eff6ff' : '#f8fafc' }}; color:{{ $role->is_system ? '#1d4ed8' : '#475569' }}; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.05em;">
                        {{ $role->is_system ? 'System' : 'Custom' }}
                    </span>
                </div>

                <div style="margin-top:10px; color:#475569; min-height:34px; font-size:13px; line-height:1.45;">
                    {{ $role->description ?: 'No description added.' }}
                </div>

                <div style="display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:10px; margin-top:14px;">
                    <div style="padding:11px 12px; border-radius:14px; background:#f8fafc; border:1px solid #e2e8f0;">
                        <div style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase; letter-spacing:0.04em;">Users</div>
                        <div style="margin-top:4px; font-size:20px; font-weight:700; line-height:1.1;">{{ $role->users_count }}</div>
                    </div>
                    <div style="padding:11px 12px; border-radius:14px; background:#f8fafc; border:1px solid #e2e8f0;">
                        <div style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase; letter-spacing:0.04em;">Status</div>
                        <div style="margin-top:4px; font-size:15px; font-weight:700; line-height:1.2; color:{{ $role->is_active ? '#166534' : '#475569' }};">{{ $role->is_active ? 'Active' : 'Inactive' }}</div>
                    </div>
                </div>

                <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:14px;">
                    <a href="{{ route('roles.show', $role) }}" style="display:inline-flex; align-items:center; justify-content:center; padding:8px 12px; border-radius:10px; background:#0f172a; color:#ffffff; text-decoration:none; font-weight:600; font-size:13px;">View</a>
                    @if($canUpdateRoles)
                        <a href="{{ route('roles.edit', $role) }}" style="display:inline-flex; align-items:center; justify-content:center; padding:8px 12px; border-radius:10px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a; text-decoration:none; font-weight:600; font-size:13px;">Edit</a>
                    @endif
                    @if($canDeleteRoles)
                        @php($canDeleteThisRole = (int) $role->users_count === 0)
                        @if($canDeleteThisRole)
                            <form method="POST" action="{{ route('roles.destroy', $role) }}" style="margin:0;" onsubmit="return confirm('Delete this role? This will be blocked if users are assigned.');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" style="display:inline-flex; align-items:center; justify-content:center; padding:8px 12px; border:0; border-radius:10px; background:#fff1f2; color:#be123c; font-weight:700; font-size:13px; cursor:pointer;">Delete</button>
                            </form>
                        @else
                            <button type="button" disabled title="Remove assigned users before deleting this role." style="display:inline-flex; align-items:center; justify-content:center; padding:8px 12px; border:0; border-radius:10px; background:#f1f5f9; color:#94a3b8; font-weight:700; font-size:13px; cursor:not-allowed;">Delete</button>
                        @endif
                    @endif
                </div>
            </div>
        @empty
            <div style="grid-column:1 / -1; padding:28px; border-radius:22px; border:1px dashed #cbd5e1; background:#ffffff; color:#64748b;">
                No roles found yet.
            </div>
        @endforelse
    </div>

    <div style="margin-top:20px;">
        {{ $roles->links() }}
    </div>
</div>
@endsection
