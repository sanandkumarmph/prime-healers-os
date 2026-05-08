@extends('layouts.app')

@section('content')
<div style="max-width:1180px; margin:0 auto;">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; margin-bottom:24px;">
        <div>
            <div style="display:inline-flex; padding:6px 10px; border-radius:999px; background:#eff6ff; color:#1d4ed8; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.08em;">Organization &amp; Settings</div>
            <h1 style="margin:12px 0 8px; font-size:34px; letter-spacing:-0.03em;">{{ $city->name }}</h1>
            <p style="margin:0; color:#64748b;">Shared city master used across users, warehouses, and vendors.</p>
        </div>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <a href="{{ route('cities.edit', $city) }}" style="display:inline-flex; align-items:center; justify-content:center; padding:10px 14px; border-radius:11px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a; text-decoration:none; font-weight:600; font-size:13px;">Edit</a>
            <form method="POST" action="{{ route('cities.destroy', $city) }}" onsubmit="return confirm('Delete this city?');">
                @csrf
                @method('DELETE')
                <button type="submit" style="padding:10px 14px; border:none; border-radius:11px; background:#fff1f2; color:#be123c; font-weight:700; font-size:13px; cursor:pointer;">
                    Delete
                </button>
            </form>
            <a href="{{ route('cities.index') }}" style="display:inline-flex; align-items:center; justify-content:center; padding:10px 14px; border-radius:11px; background:#1d4ed8; color:#ffffff; text-decoration:none; font-weight:700; font-size:13px;">Back to Cities</a>
        </div>
    </div>

    <div style="display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:18px;">
        <div style="padding:18px 20px; border-radius:20px; border:1px solid #e2e8f0; background:#ffffff;"><div style="font-size:12px; color:#64748b; font-weight:700; text-transform:uppercase;">State</div><div style="margin-top:8px; font-size:24px; font-weight:700;">{{ $city->state ?: '—' }}</div></div>
        <div style="padding:18px 20px; border-radius:20px; border:1px solid #e2e8f0; background:#ffffff;"><div style="font-size:12px; color:#64748b; font-weight:700; text-transform:uppercase;">Country</div><div style="margin-top:8px; font-size:24px; font-weight:700;">{{ $city->country ?: '—' }}</div></div>
        <div style="padding:18px 20px; border-radius:20px; border:1px solid #e2e8f0; background:#ffffff;"><div style="font-size:12px; color:#64748b; font-weight:700; text-transform:uppercase;">Status</div><div style="margin-top:8px; font-size:24px; font-weight:700; color:{{ $city->is_active ? '#166534' : '#475569' }};">{{ $city->is_active ? 'Active' : 'Inactive' }}</div></div>
    </div>

    <div style="margin-top:18px; display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:18px;">
        <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:22px; overflow:hidden;">
            <div style="padding:18px 22px; border-bottom:1px solid #e2e8f0;"><h2 style="margin:0; font-size:20px;">Users</h2></div>
            <div style="padding:18px 22px; display:grid; gap:10px;">
                @forelse($city->users as $user)
                    <a href="{{ route('users.show', $user) }}" style="display:block; padding:12px 14px; border-radius:14px; background:#f8fafc; border:1px solid #e2e8f0; color:#0f172a; text-decoration:none;">
                        <div style="font-weight:700;">{{ $user->name }}</div>
                        <div style="margin-top:4px; color:#64748b; font-size:13px;">{{ $user->assignedRole?->name ?? ucfirst(str_replace('_', ' ', $user->role)) }}</div>
                    </a>
                @empty
                    <div style="color:#64748b;">No users mapped.</div>
                @endforelse
            </div>
        </div>
        <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:22px; overflow:hidden;">
            <div style="padding:18px 22px; border-bottom:1px solid #e2e8f0;"><h2 style="margin:0; font-size:20px;">Warehouses</h2></div>
            <div style="padding:18px 22px; display:grid; gap:10px;">
                @forelse($city->warehouses as $warehouse)
                    <a href="{{ route('warehouses.show', $warehouse) }}" style="display:block; padding:12px 14px; border-radius:14px; background:#f8fafc; border:1px solid #e2e8f0; color:#0f172a; text-decoration:none; font-weight:700;">{{ $warehouse->name }}</a>
                @empty
                    <div style="color:#64748b;">No warehouses mapped.</div>
                @endforelse
            </div>
        </div>
        <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:22px; overflow:hidden;">
            <div style="padding:18px 22px; border-bottom:1px solid #e2e8f0;"><h2 style="margin:0; font-size:20px;">Vendors</h2></div>
            <div style="padding:18px 22px; display:grid; gap:10px;">
                @forelse($city->vendors as $vendor)
                    <a href="{{ route('vendors.show', $vendor) }}" style="display:block; padding:12px 14px; border-radius:14px; background:#f8fafc; border:1px solid #e2e8f0; color:#0f172a; text-decoration:none;">
                        <div style="font-weight:700;">{{ $vendor->name }}</div>
                        <div style="margin-top:4px; color:#64748b; font-size:13px;">{{ $vendor->phone ?: 'No phone added' }}</div>
                    </a>
                @empty
                    <div style="color:#64748b;">No vendors mapped.</div>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection
