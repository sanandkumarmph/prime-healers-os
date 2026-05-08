@extends('layouts.app')

@section('content')
    <div style="max-width:760px; margin:40px auto 0; padding:28px; border-radius:24px; border:1px solid #e2e8f0; background:#ffffff;">
        <div style="display:inline-flex; padding:6px 10px; border-radius:999px; background:#fff7ed; color:#c2410c; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.08em;">Asset Not Found</div>
        <h1 style="margin:14px 0 10px; font-size:34px; letter-spacing:-0.03em;">No asset matched "{{ $lookup }}"</h1>
        <p style="margin:0; color:#64748b;">We could not find any asset with this serial number or barcode. You can try another scan or create a new asset now.</p>

        <div style="margin-top:24px; display:flex; gap:12px; flex-wrap:wrap;">
            <a href="{{ route('assets.create', ['lookup' => $lookup]) }}" style="display:inline-flex; align-items:center; justify-content:center; padding:11px 16px; border-radius:12px; background:#0f766e; color:#ffffff; text-decoration:none; font-weight:700;">
                + Create Asset
            </a>
            <a href="{{ route('inventory.dashboard') }}" style="display:inline-flex; align-items:center; justify-content:center; padding:11px 16px; border-radius:12px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a; text-decoration:none; font-weight:600;">
                Back to Inventory Overview
            </a>
        </div>
    </div>
@endsection
