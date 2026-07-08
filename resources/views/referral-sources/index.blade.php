@extends('layouts.app')

@section('content')
<div style="max-width:1180px; margin:0 auto;">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; margin-bottom:20px;">
        <div>
            <div style="display:inline-flex; padding:6px 10px; border-radius:999px; background:#eff6ff; color:#1d4ed8; font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.08em;">Growth Master</div>
            <h1 style="margin:10px 0 6px; font-size:30px;">Referral Sources</h1>
            <p style="margin:0; color:#64748b;">Add doctors, hospitals, partners, employees, and other referrers for order linking and incentive analysis.</p>
        </div>
        @if(empty($migrationMissing))
            <a href="{{ route('referral-sources.create') }}" style="display:inline-flex; align-items:center; justify-content:center; padding:11px 16px; border-radius:12px; background:#3150ff; color:#fff; text-decoration:none; font-weight:800;">+ Add Referral Source</a>
        @else
            <button type="button" disabled style="display:inline-flex; align-items:center; justify-content:center; padding:11px 16px; border-radius:12px; border:0; background:#94a3b8; color:#fff; font-weight:800; cursor:not-allowed;" title="Run php artisan migrate --force to enable referral source creation.">+ Add Referral Source</button>
        @endif
    </div>

    @if(session('success'))
        <div style="margin-bottom:16px; padding:12px 14px; border-radius:14px; background:#ecfdf5; border:1px solid #bbf7d0; color:#166534;">{{ session('success') }}</div>
    @endif

    @if(!empty($migrationMissing))
        <div style="margin-bottom:16px; padding:16px; border-radius:16px; background:#fff7ed; border:1px solid #fed7aa; color:#9a3412;">
            <strong style="display:block; color:#7c2d12; margin-bottom:4px;">Referral Sources setup is pending.</strong>
            Run <code style="font-weight:800;">php artisan migrate --force</code> once on this environment to create the referral source master table.
        </div>
    @endif

    <form method="GET" action="{{ route('referral-sources.index') }}" style="display:grid; grid-template-columns:minmax(0, 1fr) 220px auto; gap:10px; margin-bottom:16px; background:#fff; border:1px solid #e2e8f0; border-radius:16px; padding:12px;">
        <input type="text" name="search" value="{{ $search }}" placeholder="Search name, contact, or city" style="padding:11px 13px; border:1px solid #cbd5e1; border-radius:12px;">
        <select name="source_type" style="padding:11px 13px; border:1px solid #cbd5e1; border-radius:12px; background:#fff;">
            <option value="">All Types</option>
            @foreach($types as $value => $label)
                <option value="{{ $value }}" @selected($type === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <button type="submit" style="border:0; border-radius:12px; background:#0f172a; color:#fff; font-weight:800; padding:0 16px;">Apply</button>
    </form>

    <div style="display:grid; gap:10px;">
        @forelse($referralSources as $source)
            <div style="display:grid; grid-template-columns:minmax(220px, 1.4fr) 160px 170px 140px auto; gap:12px; align-items:center; background:#fff; border:1px solid #e2e8f0; border-radius:16px; padding:14px;">
                <div>
                    <strong style="display:block; color:#0f172a;">{{ $source->name }}</strong>
                    <span style="color:#64748b; font-size:13px;">{{ $source->notes ?: 'No notes' }}</span>
                </div>
                <span style="color:#334155;">{{ $types[$source->source_type] ?? 'Other' }}</span>
                <span style="color:#334155;">{{ $source->contact ?: 'No contact' }}</span>
                <span style="color:#334155;">{{ $source->city ?: 'No city' }}</span>
                <div style="display:flex; gap:8px; justify-content:flex-end;">
                    <span style="padding:6px 9px; border-radius:999px; background:{{ $source->is_active ? '#ecfdf5' : '#f8fafc' }}; color:{{ $source->is_active ? '#166534' : '#64748b' }}; font-size:11px; font-weight:800;">{{ $source->is_active ? 'Active' : 'Inactive' }}</span>
                    <a href="{{ route('referral-sources.edit', $source) }}" style="padding:8px 12px; border-radius:10px; border:1px solid #cbd5e1; color:#0f172a; text-decoration:none; font-weight:700;">Edit</a>
                </div>
            </div>
        @empty
            <div style="padding:24px; border-radius:16px; border:1px dashed #cbd5e1; background:#fff; color:#64748b;">No referral sources added yet.</div>
        @endforelse
    </div>

    <div style="margin-top:16px;">{{ $referralSources->links() }}</div>
</div>
@endsection
