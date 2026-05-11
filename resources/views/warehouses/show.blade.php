@extends('layouts.app')

@php
    $statusBadge = fn ($status) => match($status) {
        'available' => ['#ecfdf5', '#166534'],
        'rented' => ['#eff6ff', '#1d4ed8'],
        'maintenance' => ['#fff7ed', '#c2410c'],
        'reserved' => ['#f5f3ff', '#6d28d9'],
        'retired' => ['#f8fafc', '#475569'],
        default => ['#f8fafc', '#334155'],
    };
@endphp

@section('content')
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; margin-bottom:24px;">
        <div>
            <div style="display:inline-flex; padding:6px 10px; border-radius:999px; background:#eff6ff; color:#1d4ed8; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.08em;">Company Settings</div>
            <h1 style="margin:12px 0 8px; font-size:34px; letter-spacing:-0.03em;">{{ $warehouse->name }}</h1>
            <p style="margin:0; color:#64748b;">{{ collect([$warehouse->address, $warehouse->cityRecord?->name ?? $warehouse->city, $warehouse->state, $warehouse->pincode])->filter()->join(', ') ?: 'No address added.' }}</p>
        </div>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <a href="{{ route('warehouses.edit', $warehouse) }}" style="display:inline-flex; align-items:center; justify-content:center; padding:11px 16px; border-radius:12px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a; text-decoration:none; font-weight:600;">Edit</a>
            <a href="{{ route('assets.index', ['warehouse_id' => $warehouse->id]) }}" style="display:inline-flex; align-items:center; justify-content:center; padding:11px 16px; border-radius:12px; background:#6d28d9; color:#ffffff; text-decoration:none; font-weight:700;">View Assets</a>
        </div>
    </div>

    @if(session('success'))
        <div style="margin-bottom:18px; padding:14px 16px; border-radius:16px; background:#ecfdf5; border:1px solid #bbf7d0; color:#166534;">{{ session('success') }}</div>
    @endif

    <div style="display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:16px; margin-bottom:22px;">
        <div style="padding:18px 20px; border-radius:20px; border:1px solid #e2e8f0; background:#ffffff;">
            <div style="font-size:12px; color:#64748b; font-weight:700; text-transform:uppercase;">Code</div>
            <div style="margin-top:8px; font-size:24px; font-weight:700;">{{ $warehouse->code ?: 'N/A' }}</div>
        </div>
        <div style="padding:18px 20px; border-radius:20px; border:1px solid #e2e8f0; background:#ffffff;">
            <div style="font-size:12px; color:#64748b; font-weight:700; text-transform:uppercase;">Total Assets</div>
            <div style="margin-top:8px; font-size:24px; font-weight:700;">{{ $warehouse->assets_count }}</div>
        </div>
        <div style="padding:18px 20px; border-radius:20px; border:1px solid #e2e8f0; background:#ffffff;">
            <div style="font-size:12px; color:#64748b; font-weight:700; text-transform:uppercase;">Available</div>
            <div style="margin-top:8px; font-size:24px; font-weight:700; color:#166534;">{{ $warehouse->available_assets_count }}</div>
        </div>
        <div style="padding:18px 20px; border-radius:20px; border:1px solid #e2e8f0; background:#ffffff;">
            <div style="font-size:12px; color:#64748b; font-weight:700; text-transform:uppercase;">Maintenance</div>
            <div style="margin-top:8px; font-size:24px; font-weight:700; color:#c2410c;">{{ $warehouse->maintenance_assets_count }}</div>
        </div>
    </div>

    <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:22px; overflow:hidden;">
        <div style="padding:20px 22px; border-bottom:1px solid #e2e8f0;">
            <h2 style="margin:0; font-size:22px;">Assets in this Warehouse</h2>
            <p style="margin:8px 0 0; color:#64748b;">Warehouse-wise asset listing for quick staff reference.</p>
        </div>
        <div style="overflow:auto;">
            <table style="width:100%; border-collapse:collapse;">
                <thead style="background:#f8fafc;">
                    <tr>
                        <th style="text-align:left; padding:14px 18px; font-size:12px; color:#64748b; text-transform:uppercase;">Asset</th>
                        <th style="text-align:left; padding:14px 18px; font-size:12px; color:#64748b; text-transform:uppercase;">Product</th>
                        <th style="text-align:left; padding:14px 18px; font-size:12px; color:#64748b; text-transform:uppercase;">Serial</th>
                        <th style="text-align:left; padding:14px 18px; font-size:12px; color:#64748b; text-transform:uppercase;">Status</th>
                        <th style="text-align:right; padding:14px 18px; font-size:12px; color:#64748b; text-transform:uppercase;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($assets as $asset)
                    @php($badge = $statusBadge($asset->asset_status))
                    <tr style="border-top:1px solid #e2e8f0;">
                        <td style="padding:16px 18px;">{{ $asset->asset_name ?: optional($asset->product)->name ?: 'Asset' }}</td>
                        <td style="padding:16px 18px;">{{ optional($asset->product)->name ?: 'N/A' }}</td>
                        <td style="padding:16px 18px;">{{ $asset->serial_number }}</td>
                        <td style="padding:16px 18px;">
                            <span style="display:inline-flex; padding:6px 10px; border-radius:999px; background:{{ $badge[0] }}; color:{{ $badge[1] }}; font-size:11px; font-weight:700; text-transform:uppercase;">
                                {{ str_replace('_', ' ', $asset->asset_status) }}
                            </span>
                        </td>
                        <td style="padding:16px 18px; text-align:right;">
                            <a href="{{ route('assets.show', $asset) }}" style="color:#0f766e; font-weight:700; text-decoration:none;">View</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" style="padding:24px 18px; color:#64748b;">No assets found in this warehouse.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div style="padding:18px 22px;">
            {{ $assets->links() }}
        </div>
    </div>
@endsection
