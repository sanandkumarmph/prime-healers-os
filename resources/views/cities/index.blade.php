@extends('layouts.app')

@section('content')
<div style="max-width:1220px; margin:0 auto;">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; margin-bottom:24px;">
        <div>
            <div style="display:inline-flex; padding:6px 10px; border-radius:999px; background:#eff6ff; color:#1d4ed8; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.08em;">Organization &amp; Settings</div>
            <h1 style="margin:12px 0 8px; font-size:34px; letter-spacing:-0.03em;">Cities</h1>
            <p style="margin:0; color:#64748b;">Maintain a reusable city master for users, warehouses, vendors, and future filters.</p>
        </div>
        <a href="{{ route('cities.create') }}" style="display:inline-flex; align-items:center; justify-content:center; padding:10px 14px; border-radius:11px; background:#1d4ed8; color:#ffffff; text-decoration:none; font-weight:700; font-size:13px;">+ Add City</a>
    </div>

    <details style="margin-bottom:18px; border:1px solid #e2e8f0; border-radius:20px; background:#ffffff;">
        <summary style="cursor:pointer; list-style:none; padding:16px 18px; font-weight:800; color:#0f172a;">Filter / Sort <span style="color:#64748b; font-size:12px;">{{ $search ? 'Active' : 'Expand' }}</span></summary>
        <form method="GET" action="{{ route('cities.index') }}" style="display:flex; gap:12px; padding:0 18px 18px;">
            <input type="text" name="search" value="{{ $search }}" placeholder="Search city, state, country" style="flex:1; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px;">
            <button type="submit" style="padding:10px 14px; border:none; border-radius:12px; background:#0f172a; color:#ffffff; font-weight:700; font-size:13px; cursor:pointer;">Apply</button>
            <a href="{{ route('cities.index') }}" style="display:inline-flex; align-items:center; justify-content:center; padding:10px 12px; border-radius:12px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a; text-decoration:none; font-weight:600; font-size:13px;">Reset</a>
        </form>
    </details>

    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(250px, 1fr)); gap:14px;">
        @forelse($cities as $city)
            <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:18px; padding:16px;">
                <div style="display:flex; justify-content:space-between; gap:12px; align-items:flex-start;">
                    <div>
                        <h2 style="margin:0; font-size:18px; line-height:1.2; letter-spacing:-0.02em;">{{ $city->name }}</h2>
                        <p style="margin:4px 0 0; color:#64748b; font-size:12px;">{{ collect([$city->state, $city->country])->filter()->join(', ') ?: 'No region data' }}</p>
                    </div>
                    <span style="display:inline-flex; padding:5px 9px; border-radius:999px; background:{{ $city->is_active ? '#ecfdf5' : '#f8fafc' }}; color:{{ $city->is_active ? '#166534' : '#475569' }}; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.05em;">
                        {{ $city->is_active ? 'Active' : 'Inactive' }}
                    </span>
                </div>

                <div style="display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:8px; margin-top:14px;">
                    <div style="padding:11px 10px; border-radius:14px; background:#f8fafc; border:1px solid #e2e8f0;"><div style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase; letter-spacing:0.04em;">Users</div><div style="margin-top:4px; font-size:20px; font-weight:700; line-height:1.1;">{{ $city->users_count }}</div></div>
                    <div style="padding:11px 10px; border-radius:14px; background:#f8fafc; border:1px solid #e2e8f0;"><div style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase; letter-spacing:0.04em;">Warehouses</div><div style="margin-top:4px; font-size:20px; font-weight:700; line-height:1.1;">{{ $city->warehouses_count }}</div></div>
                    <div style="padding:11px 10px; border-radius:14px; background:#f8fafc; border:1px solid #e2e8f0;"><div style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase; letter-spacing:0.04em;">Vendors</div><div style="margin-top:4px; font-size:20px; font-weight:700; line-height:1.1;">{{ $city->vendors_count }}</div></div>
                </div>

                <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:14px;">
                    <a href="{{ route('cities.show', $city) }}" style="display:inline-flex; align-items:center; justify-content:center; padding:8px 12px; border-radius:10px; background:#0f172a; color:#ffffff; text-decoration:none; font-weight:600; font-size:13px;">View</a>
                    <a href="{{ route('cities.edit', $city) }}" style="display:inline-flex; align-items:center; justify-content:center; padding:8px 12px; border-radius:10px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a; text-decoration:none; font-weight:600; font-size:13px;">Edit</a>
                    <form method="POST" action="{{ route('cities.destroy', $city) }}" onsubmit="return confirm('Delete this city?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" style="padding:8px 12px; border:none; border-radius:10px; background:#fff1f2; color:#be123c; font-weight:700; font-size:13px; cursor:pointer;">
                            Delete
                        </button>
                    </form>
                </div>
            </div>
        @empty
            <div style="grid-column:1 / -1; padding:28px; border-radius:22px; border:1px dashed #cbd5e1; background:#ffffff; color:#64748b;">No cities found yet.</div>
        @endforelse
    </div>

    <div style="margin-top:20px;">{{ $cities->links() }}</div>
</div>
@endsection
