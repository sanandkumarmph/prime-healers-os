@extends('layouts.app')

@section('content')
@php
    $currentUser = auth()->user();
    $canUpdateUsers = $currentUser?->canAccessModule('users', 'update') ?? false;
    $canDeleteUsers = $currentUser?->canAccessModule('users', 'delete') ?? false;
@endphp
<style>
    @media (max-width: 767px) {
        .user-show-header,
        .user-show-layout,
        .user-summary-grid,
        .user-quick-actions {
            grid-template-columns: 1fr !important;
        }
        .user-show-header {
            flex-direction: column;
            margin-bottom: 18px !important;
        }
        .user-show-header h1 {
            font-size: 26px !important;
            overflow-wrap: anywhere;
        }
        .user-show-actions {
            width: 100%;
        }
        .user-show-actions > * {
            flex: 1 1 calc(50% - 10px);
            min-width: 0;
        }
        .user-summary-grid > div {
            min-width: 0;
        }
        .user-summary-grid > div > div:last-child {
            overflow-wrap: anywhere;
            word-break: break-word;
        }
    }
</style>
<div style="max-width:1180px; margin:0 auto;">
    <div class="user-show-header" style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; margin-bottom:24px;">
        <div>
            <div style="display:inline-flex; padding:6px 10px; border-radius:999px; background:#eff6ff; color:#1d4ed8; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.08em;">Organization &amp; Settings</div>
            <h1 style="margin:12px 0 8px; font-size:34px; letter-spacing:-0.03em;">{{ $user->name }}</h1>
            <p style="margin:0; color:#64748b;">User profile, access mapping, and organization metadata.</p>
        </div>
        <div class="user-show-actions" style="display:flex; gap:10px; flex-wrap:wrap;">
            @if($canUpdateUsers)
                <a href="{{ route('users.edit', $user) }}" style="display:inline-flex; align-items:center; justify-content:center; padding:11px 16px; border-radius:12px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a; text-decoration:none; font-weight:600;">Edit</a>
            @endif
            @if($canDeleteUsers && (int) $user->id !== (int) auth()->id())
                <form method="POST" action="{{ route('users.destroy', $user) }}" style="margin:0;" onsubmit="return confirm('Delete this user? This will be blocked if dependencies exist.');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" style="display:inline-flex; align-items:center; justify-content:center; padding:11px 16px; border:none; border-radius:12px; background:#fff1f2; color:#be123c; font-weight:700; cursor:pointer;">Delete</button>
                </form>
            @endif
            <a href="{{ route('users.index') }}" style="display:inline-flex; align-items:center; justify-content:center; padding:11px 16px; border-radius:12px; background:#1d4ed8; color:#ffffff; text-decoration:none; font-weight:700;">Back to Users</a>
        </div>
    </div>

    @if(session('success'))
        <div style="margin-bottom:18px; padding:14px 16px; border-radius:16px; background:#ecfdf5; border:1px solid #bbf7d0; color:#166534;">{{ session('success') }}</div>
    @endif

    @if(session('error'))
        <div style="margin-bottom:18px; padding:14px 16px; border-radius:16px; background:#fff1f2; border:1px solid #fecaca; color:#991b1b;">{{ session('error') }}</div>
    @endif

    <div class="user-show-layout" style="display:grid; grid-template-columns:1.2fr 0.8fr; gap:18px;">
        <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:22px; padding:22px;">
            <h2 style="margin:0 0 16px; font-size:20px;">Profile Summary</h2>
            <div class="user-summary-grid" style="display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:14px;">
                <div><div style="font-size:12px; color:#64748b; text-transform:uppercase; font-weight:700;">Email</div><div style="margin-top:6px;">{{ $user->email }}</div></div>
                <div><div style="font-size:12px; color:#64748b; text-transform:uppercase; font-weight:700;">Phone</div><div style="margin-top:6px;">{{ $user->phone ?: 'Not added' }}</div></div>
                <div><div style="font-size:12px; color:#64748b; text-transform:uppercase; font-weight:700;">Role</div><div style="margin-top:6px;">{{ $user->assignedRole?->name ?? ucfirst(str_replace('_', ' ', $user->role)) }}</div></div>
                <div><div style="font-size:12px; color:#64748b; text-transform:uppercase; font-weight:700;">City</div><div style="margin-top:6px;">{{ $user->cityRecord?->name ?? 'Not mapped' }}</div></div>
                <div><div style="font-size:12px; color:#64748b; text-transform:uppercase; font-weight:700;">Status</div><div style="margin-top:6px;">{{ $user->is_active ? 'Active' : 'Inactive' }}</div></div>
                <div><div style="font-size:12px; color:#64748b; text-transform:uppercase; font-weight:700;">Legacy Role Key</div><div style="margin-top:6px;">{{ $user->role }}</div></div>
                <div style="grid-column:1 / -1;"><div style="font-size:12px; color:#64748b; text-transform:uppercase; font-weight:700;">Address</div><div style="margin-top:6px; color:#334155;">{{ $user->address ?: 'No address added.' }}</div></div>
            </div>
        </div>

        <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:22px; padding:22px;">
            <h2 style="margin:0 0 16px; font-size:20px;">Quick Actions</h2>
            <div class="user-quick-actions" style="display:grid; gap:10px;">
                @if($user->assignedRole)
                    <a href="{{ route('roles.show', $user->assignedRole) }}" style="display:block; padding:12px 14px; border-radius:14px; background:#f8fafc; border:1px solid #e2e8f0; color:#0f172a; text-decoration:none; font-weight:600;">Open Role</a>
                @endif
                @if($user->cityRecord)
                    <a href="{{ route('cities.show', $user->cityRecord) }}" style="display:block; padding:12px 14px; border-radius:14px; background:#f8fafc; border:1px solid #e2e8f0; color:#0f172a; text-decoration:none; font-weight:600;">Open City</a>
                @endif
                @if($canUpdateUsers)
                    <a href="{{ route('users.edit', $user) }}" style="display:block; padding:12px 14px; border-radius:14px; background:#eff6ff; border:1px solid #bfdbfe; color:#1d4ed8; text-decoration:none; font-weight:700;">Edit User</a>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
