@extends('layouts.app')

@section('content')
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; margin-bottom:24px;">
        <div>
            <div style="display:inline-flex; padding:6px 10px; border-radius:999px; background:#eff6ff; color:#1d4ed8; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.08em;">Organization &amp; Settings</div>
            <h1 style="margin:12px 0 8px; font-size:34px; letter-spacing:-0.03em;">Warehouses</h1>
            <p style="margin:0; color:#64748b;">Manage warehouse masters with city mapping, activity state, and quick asset visibility.</p>
        </div>
        <a href="{{ route('warehouses.create') }}" style="display:inline-flex; align-items:center; justify-content:center; padding:11px 16px; border-radius:12px; background:#1d4ed8; color:#ffffff; text-decoration:none; font-weight:700;">+ Add Warehouse</a>
    </div>

    @if(session('success'))
        <div style="margin-bottom:18px; padding:14px 16px; border-radius:16px; background:#ecfdf5; border:1px solid #bbf7d0; color:#166534;">{{ session('success') }}</div>
    @endif

    @if(session('error'))
        <div style="margin-bottom:18px; padding:14px 16px; border-radius:16px; background:#fff1f2; border:1px solid #fecaca; color:#991b1b;">{{ session('error') }}</div>
    @endif

    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(300px, 1fr)); gap:18px;">
        @forelse($warehouses as $warehouse)
            <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:22px; padding:22px;">
                <div style="display:flex; justify-content:space-between; gap:12px; align-items:flex-start;">
                    <div>
                        <h2 style="margin:0; font-size:22px; letter-spacing:-0.02em;">{{ $warehouse->name }}</h2>
                        <p style="margin:8px 0 0; color:#64748b; font-size:13px;">{{ $warehouse->code ?: 'No code assigned' }}</p>
                    </div>
                    <span style="display:inline-flex; padding:6px 10px; border-radius:999px; background:{{ $warehouse->is_active ? '#ecfdf5' : '#f8fafc' }}; color:{{ $warehouse->is_active ? '#166534' : '#475569' }}; font-size:11px; font-weight:700; text-transform:uppercase;">
                        {{ $warehouse->is_active ? 'Active' : 'Inactive' }}
                    </span>
                </div>

                <div style="display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:12px; margin-top:18px;">
                    <div style="padding:14px; border-radius:16px; background:#f8fafc; border:1px solid #e2e8f0;">
                        <div style="font-size:12px; color:#64748b; font-weight:700; text-transform:uppercase;">Assets</div>
                        <div style="margin-top:6px; font-size:24px; font-weight:700;">{{ $warehouse->assets_count }}</div>
                    </div>
                    <div style="padding:14px; border-radius:16px; background:#f8fafc; border:1px solid #e2e8f0;">
                        <div style="font-size:12px; color:#64748b; font-weight:700; text-transform:uppercase;">Available</div>
                        <div style="margin-top:6px; font-size:24px; font-weight:700; color:#166534;">{{ $warehouse->available_assets_count }}</div>
                    </div>
                    <div style="padding:14px; border-radius:16px; background:#f8fafc; border:1px solid #e2e8f0;">
                        <div style="font-size:12px; color:#64748b; font-weight:700; text-transform:uppercase;">Rented</div>
                        <div style="margin-top:6px; font-size:24px; font-weight:700; color:#1d4ed8;">{{ $warehouse->rented_assets_count }}</div>
                    </div>
                </div>

                <div style="margin-top:16px; color:#64748b; font-size:14px;">
                    {{ collect([$warehouse->cityRecord?->name ?? $warehouse->city, $warehouse->state, $warehouse->pincode])->filter()->join(', ') ?: 'No location details added.' }}
                </div>

                <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:18px;">
                    <a href="{{ route('warehouses.show', $warehouse) }}" style="display:inline-flex; align-items:center; justify-content:center; padding:10px 14px; border-radius:12px; background:#0f172a; color:#ffffff; text-decoration:none; font-weight:600;">View</a>
                    <a href="{{ route('warehouses.edit', $warehouse) }}" style="display:inline-flex; align-items:center; justify-content:center; padding:10px 14px; border-radius:12px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a; text-decoration:none; font-weight:600;">Edit</a>
                    <form method="POST" action="{{ route('warehouses.destroy', $warehouse) }}" onsubmit="return confirm('Delete this warehouse?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" style="padding:10px 14px; border:none; border-radius:12px; background:#fff1f2; color:#be123c; font-weight:700; cursor:pointer;">Delete</button>
                    </form>
                </div>
            </div>
        @empty
            <div style="grid-column:1 / -1; padding:28px; border-radius:22px; border:1px dashed #cbd5e1; background:#ffffff; color:#64748b;">
                No warehouses found yet.
            </div>
        @endforelse
    </div>

    <div style="margin-top:20px;">
        {{ $warehouses->links() }}
    </div>
@endsection
