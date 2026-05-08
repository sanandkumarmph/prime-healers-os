@extends('layouts.app')

@section('content')
    <div style="max-width:760px; margin:0 auto;">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; margin-bottom:24px;">
            <div>
                <div style="display:inline-flex; padding:6px 10px; border-radius:999px; background:#eff6ff; color:#1d4ed8; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.08em;">Asset Transfer</div>
                <h1 style="margin:12px 0 8px; font-size:32px; letter-spacing:-0.03em;">Transfer Asset</h1>
                <p style="margin:0; color:#64748b;">Move this asset to another warehouse and automatically save movement history.</p>
            </div>
            <a href="{{ route('assets.show', $asset) }}" style="display:inline-flex; align-items:center; justify-content:center; padding:10px 14px; border-radius:12px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a; text-decoration:none; font-weight:600;">
                Back to Asset
            </a>
        </div>

        @if ($errors->any())
            <div style="margin-bottom:18px; padding:14px 16px; border-radius:16px; border:1px solid #fecaca; background:#fff1f2; color:#991b1b;">
                <strong>Please fix the following:</strong>
                <ul style="margin:10px 0 0 18px;">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('assets.transfer.store', $asset) }}" style="display:grid; gap:20px;">
            @csrf
            <input type="hidden" name="current_warehouse_id" value="{{ $asset->warehouse_id }}">

            <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:22px; padding:24px;">
                <div style="display:grid; gap:18px;">
                    <div style="padding:16px; border-radius:16px; background:#f8fafc; border:1px solid #e2e8f0;">
                        <div style="font-size:12px; color:#64748b; font-weight:700; text-transform:uppercase;">Asset</div>
                        <div style="margin-top:8px; font-size:20px; font-weight:700;">{{ $asset->asset_name ?: optional($asset->product)->name ?: 'Asset' }}</div>
                        <div style="margin-top:4px; color:#64748b;">Serial: {{ $asset->serial_number }}</div>
                    </div>

                    <div>
                        <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">From Warehouse</label>
                        <input type="text" value="{{ optional($asset->warehouse)->name }}" disabled
                               style="width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#f8fafc;">
                    </div>

                    <div>
                        <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">To Warehouse</label>
                        <select name="warehouse_id" required style="width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff;">
                            <option value="">Select warehouse</option>
                            @foreach($warehouses as $warehouse)
                                <option value="{{ $warehouse->id }}" @selected(old('warehouse_id') == $warehouse->id)>{{ $warehouse->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Remarks</label>
                        <textarea name="remarks" rows="4" style="width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff; resize:vertical;">{{ old('remarks') }}</textarea>
                    </div>
                </div>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:12px;">
                <a href="{{ route('assets.show', $asset) }}" style="display:inline-flex; align-items:center; justify-content:center; padding:11px 16px; border-radius:12px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a; text-decoration:none; font-weight:600;">Cancel</a>
                <button type="submit" style="display:inline-flex; align-items:center; justify-content:center; padding:11px 18px; border:none; border-radius:12px; background:#1d4ed8; color:#ffffff; font-weight:700; cursor:pointer;">
                    Transfer Asset
                </button>
            </div>
        </form>
    </div>
@endsection
