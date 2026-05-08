@extends('layouts.app')

@section('content')
@php
    $currentUser = auth()->user();
    $canCreateUsers = $currentUser?->canAccessModule('users', 'create') ?? false;
    $canUpdateUsers = $currentUser?->canAccessModule('users', 'update') ?? false;
    $canDeleteUsers = $currentUser?->canAccessModule('users', 'delete') ?? false;
@endphp
<div style="max-width:1280px; margin:0 auto;">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; margin-bottom:24px;">
        <div>
            <div style="display:inline-flex; padding:6px 10px; border-radius:999px; background:#eff6ff; color:#1d4ed8; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.08em;">Organization &amp; Settings</div>
            <h1 style="margin:12px 0 8px; font-size:34px; letter-spacing:-0.03em;">Users</h1>
            <p style="margin:0; color:#64748b;">Manage login accounts, role mapping, city mapping, and who is active in the organization.</p>
        </div>
        @if($canCreateUsers)
            <a href="{{ route('users.create') }}" style="display:inline-flex; align-items:center; justify-content:center; padding:11px 16px; border-radius:12px; background:#1d4ed8; color:#ffffff; text-decoration:none; font-weight:700;">+ Add User</a>
        @endif
    </div>

    @if(session('success'))
        <div style="margin-bottom:18px; padding:14px 16px; border-radius:16px; background:#ecfdf5; border:1px solid #bbf7d0; color:#166534;">{{ session('success') }}</div>
    @endif

    @if(session('error'))
        <div style="margin-bottom:18px; padding:14px 16px; border-radius:16px; background:#fff1f2; border:1px solid #fecaca; color:#991b1b;">{{ session('error') }}</div>
    @endif

    <details style="margin-bottom:18px; border:1px solid #e2e8f0; border-radius:20px; background:#ffffff;">
        <summary style="cursor:pointer; list-style:none; padding:16px 18px; font-weight:800; color:#0f172a;">Filter / Sort <span style="color:#64748b; font-size:12px;">{{ $search || $roleId || $cityId || $status ? 'Active' : 'Expand' }}</span></summary>
        <form method="GET" action="{{ route('users.index') }}" style="display:grid; grid-template-columns:2fr 1fr 1fr 1fr auto; gap:12px; padding:0 18px 18px;">
        <input type="text" name="search" value="{{ $search }}" placeholder="Search name, email, phone" style="width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px;">
        <select name="role_id" style="width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff;">
            <option value="">All Roles</option>
            @foreach($roles as $role)
                <option value="{{ $role->id }}" @selected((string) $roleId === (string) $role->id)>{{ $role->name }}</option>
            @endforeach
        </select>
        <select name="city_id" style="width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff;">
            <option value="">All Cities</option>
            @foreach($cities as $city)
                <option value="{{ $city->id }}" @selected((string) $cityId === (string) $city->id)>{{ $city->name }}</option>
            @endforeach
        </select>
        <select name="status" style="width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff;">
            <option value="">All Status</option>
            <option value="active" @selected($status === 'active')>Active</option>
            <option value="inactive" @selected($status === 'inactive')>Inactive</option>
        </select>
        <div style="display:flex; gap:10px;">
            <button type="submit" style="padding:12px 16px; border:none; border-radius:14px; background:#0f172a; color:#ffffff; font-weight:700; cursor:pointer;">Apply</button>
            <a href="{{ route('users.index') }}" style="display:inline-flex; align-items:center; justify-content:center; padding:12px 14px; border-radius:14px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a; text-decoration:none; font-weight:600;">Reset</a>
        </div>
        </form>
    </details>

    <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:22px; overflow:hidden;">
        <div style="padding:18px 22px; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; gap:12px;">
            <div>
                <h2 style="margin:0; font-size:20px;">Team Directory</h2>
                <p style="margin:6px 0 0; color:#64748b; font-size:14px;">Operational users grouped under one admin family.</p>
            </div>
            <div style="color:#64748b; font-size:13px;">{{ $users->total() }} users</div>
        </div>
        <div style="overflow:auto;">
            <table style="width:100%; border-collapse:collapse;">
                <thead style="background:#f8fafc;">
                    <tr>
                        <th style="text-align:left; padding:14px 18px; font-size:12px; color:#64748b; text-transform:uppercase;">User</th>
                        <th style="text-align:left; padding:14px 18px; font-size:12px; color:#64748b; text-transform:uppercase;">Role</th>
                        <th style="text-align:left; padding:14px 18px; font-size:12px; color:#64748b; text-transform:uppercase;">City</th>
                        <th style="text-align:left; padding:14px 18px; font-size:12px; color:#64748b; text-transform:uppercase;">Phone</th>
                        <th style="text-align:left; padding:14px 18px; font-size:12px; color:#64748b; text-transform:uppercase;">Status</th>
                        <th style="text-align:right; padding:14px 18px; font-size:12px; color:#64748b; text-transform:uppercase;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($users as $user)
                        <tr style="border-top:1px solid #e2e8f0;">
                            <td style="padding:16px 18px;">
                                <div style="font-weight:700; color:#0f172a;">{{ $user->name }}</div>
                                <div style="margin-top:4px; color:#64748b; font-size:13px;">{{ $user->email }}</div>
                            </td>
                            <td style="padding:16px 18px;">{{ $user->assignedRole?->name ?? ucfirst(str_replace('_', ' ', $user->role)) }}</td>
                            <td style="padding:16px 18px;">{{ $user->cityRecord?->name ?? 'Not mapped' }}</td>
                            <td style="padding:16px 18px;">{{ $user->phone ?: '—' }}</td>
                            <td style="padding:16px 18px;">
                                <span style="display:inline-flex; padding:6px 10px; border-radius:999px; background:{{ $user->is_active ? '#ecfdf5' : '#f8fafc' }}; color:{{ $user->is_active ? '#166534' : '#475569' }}; font-size:11px; font-weight:700; text-transform:uppercase;">
                                    {{ $user->is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td style="padding:16px 18px; text-align:right;">
                                <div style="display:inline-flex; gap:10px; flex-wrap:wrap;">
                                    <a href="{{ route('users.show', $user) }}" style="color:#0f766e; font-weight:700; text-decoration:none;">View</a>
                                    @if($canUpdateUsers)
                                        <a href="{{ route('users.edit', $user) }}" style="color:#1d4ed8; font-weight:700; text-decoration:none;">Edit</a>
                                    @endif
                                    @if($canDeleteUsers && (int) $user->id !== (int) auth()->id())
                                        <form method="POST" action="{{ route('users.destroy', $user) }}" style="margin:0;" onsubmit="return confirm('Delete this user? This will be blocked if dependencies exist.');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" style="border:0; background:transparent; padding:0; color:#be123c; font-weight:700; cursor:pointer;">Delete</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" style="padding:24px 18px; color:#64748b;">No users found yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div style="padding:18px 22px;">
            {{ $users->links() }}
        </div>
    </div>
</div>
@endsection
