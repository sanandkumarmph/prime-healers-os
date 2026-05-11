@extends('layouts.app')

@section('content')
@php
    $currentUser = auth()->user();
    $canUpdateVendors = $currentUser?->canAccessModule('vendors', 'update') ?? false;
    $canDeleteVendors = $currentUser?->canAccessModule('vendors', 'delete') ?? false;
@endphp
<div style="max-width:1160px; margin:0 auto;">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; margin-bottom:24px;">
        <div>
            <div style="display:inline-flex; padding:6px 10px; border-radius:999px; background:#eff6ff; color:#1d4ed8; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.08em;">Company Settings</div>
            <h1 style="margin:12px 0 8px; font-size:34px; letter-spacing:-0.03em;">{{ $vendor->name }}</h1>
            <p style="margin:0; color:#64748b;">Vendor profile for delivery and third-party assignment references.</p>
        </div>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            @if($canUpdateVendors)
                <a href="{{ route('vendors.edit', $vendor) }}" style="display:inline-flex; align-items:center; justify-content:center; padding:11px 16px; border-radius:12px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a; text-decoration:none; font-weight:600;">Edit</a>
            @endif
            @if($canDeleteVendors)
                <form method="POST" action="{{ route('vendors.destroy', $vendor) }}" style="margin:0;" onsubmit="return confirm('Delete this vendor? This will be blocked if dependencies exist.');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" style="display:inline-flex; align-items:center; justify-content:center; padding:11px 16px; border:none; border-radius:12px; background:#fff1f2; color:#be123c; font-weight:700; cursor:pointer;">Delete</button>
                </form>
            @endif
            <a href="{{ route('vendors.index') }}" style="display:inline-flex; align-items:center; justify-content:center; padding:11px 16px; border-radius:12px; background:#1d4ed8; color:#ffffff; text-decoration:none; font-weight:700;">Back to Vendors</a>
        </div>
    </div>

    @if(session('success'))
        <div style="margin-bottom:18px; padding:14px 16px; border-radius:16px; background:#ecfdf5; border:1px solid #bbf7d0; color:#166534;">{{ session('success') }}</div>
    @endif

    @if(session('error'))
        <div style="margin-bottom:18px; padding:14px 16px; border-radius:16px; background:#fff1f2; border:1px solid #fecaca; color:#991b1b;">{{ session('error') }}</div>
    @endif

    <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:22px; padding:22px;">
        <div style="display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:16px;">
            <div><div style="font-size:12px; color:#64748b; text-transform:uppercase; font-weight:700;">Contact Person</div><div style="margin-top:6px;">{{ $vendor->contact_person ?: 'Not added' }}</div></div>
            <div><div style="font-size:12px; color:#64748b; text-transform:uppercase; font-weight:700;">Phone</div><div style="margin-top:6px;">{{ $vendor->phone ?: 'Not added' }}</div></div>
            <div><div style="font-size:12px; color:#64748b; text-transform:uppercase; font-weight:700;">Email</div><div style="margin-top:6px;">{{ $vendor->email ?: 'Not added' }}</div></div>
            <div><div style="font-size:12px; color:#64748b; text-transform:uppercase; font-weight:700;">City</div><div style="margin-top:6px;">{{ $vendor->cityRecord?->name ?? $vendor->city ?? 'Not mapped' }}</div></div>
            <div><div style="font-size:12px; color:#64748b; text-transform:uppercase; font-weight:700;">Status</div><div style="margin-top:6px;">{{ $vendor->is_active ? 'Active' : 'Inactive' }}</div></div>
            <div style="grid-column:1 / -1;"><div style="font-size:12px; color:#64748b; text-transform:uppercase; font-weight:700;">Address</div><div style="margin-top:6px; color:#334155;">{{ $vendor->address ?: 'No address added.' }}</div></div>
            <div style="grid-column:1 / -1;"><div style="font-size:12px; color:#64748b; text-transform:uppercase; font-weight:700;">Notes</div><div style="margin-top:6px; color:#334155;">{{ $vendor->notes ?: 'No notes added.' }}</div></div>
        </div>
    </div>
</div>
@endsection
