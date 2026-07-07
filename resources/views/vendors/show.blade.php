@extends('layouts.app')

@section('content')
@php
    $currentUser = auth()->user();
    $canUpdateVendors = $currentUser?->canAccessModule('vendors', 'update') ?? false;
    $canDeleteVendors = $currentUser?->canAccessModule('vendors', 'delete') ?? false;
    $canExportVendors = $currentUser?->hasPermission('vendors.export') ?? false;
    $vendorInitials = collect(preg_split('/\s+/', trim((string) $vendor->name)) ?: [])->filter()->take(2)->map(fn ($part) => strtoupper(substr($part, 0, 1)))->implode('') ?: 'VN';
    $cityLabel = $vendor->cityRecord?->name ?? $vendor->city ?? 'City not set';
@endphp
<style>
    .vendor-profile-shell { max-width:1160px; margin:0 auto; }
    .vendor-profile-mobile { display:none; }
    .vendor-profile-desktop { display:block; }
    @media (max-width: 768px) {
        .vendor-profile-desktop { display:none !important; }
        .vendor-profile-mobile {
            display:grid;
            gap:12px;
            padding-bottom:calc(92px + env(safe-area-inset-bottom, 0px));
        }
        .vendor-mobile-hero {
            display:grid;
            gap:12px;
            padding:14px;
            border:1px solid #dbe3ef;
            border-radius:18px;
            background:#fff;
            box-shadow:0 10px 28px rgba(15,23,42,.05);
        }
        .vendor-mobile-top {
            display:grid;
            grid-template-columns:auto 1fr;
            gap:12px;
            align-items:start;
        }
        .vendor-mobile-avatar {
            width:52px;
            height:52px;
            border-radius:16px;
            display:grid;
            place-items:center;
            background:#eff6ff;
            border:1px solid #bfdbfe;
            color:#1d4ed8;
            font-size:17px;
            font-weight:900;
        }
        .vendor-mobile-headline h1 {
            margin:0;
            font-size:22px;
            line-height:1.08;
            color:#0f172a;
        }
        .vendor-mobile-copy {
            margin-top:4px;
            color:#64748b;
            font-size:12px;
            line-height:1.4;
        }
        .vendor-mobile-chips,
        .vendor-mobile-actions {
            display:flex;
            gap:8px;
            overflow-x:auto;
            padding-bottom:2px;
            scrollbar-width:none;
            -webkit-overflow-scrolling:touch;
        }
        .vendor-mobile-chips::-webkit-scrollbar,
        .vendor-mobile-actions::-webkit-scrollbar { display:none; }
        .vendor-mobile-chip {
            flex:0 0 auto;
            display:inline-flex;
            align-items:center;
            min-height:28px;
            padding:6px 10px;
            border-radius:999px;
            border:1px solid #dbe3ef;
            background:#fff;
            color:#475569;
            font-size:11px;
            font-weight:800;
            white-space:nowrap;
        }
        .vendor-mobile-chip.is-active {
            background:#ecfdf5;
            border-color:#bbf7d0;
            color:#166534;
        }
        .vendor-mobile-action {
            flex:0 0 auto;
            min-width:44px;
            height:44px;
            padding:0 14px;
            border-radius:14px;
            border:1px solid #dbe3ef;
            background:#fff;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            color:#334155;
            text-decoration:none;
            font-size:13px;
            font-weight:800;
            white-space:nowrap;
        }
        .vendor-mobile-action.is-primary {
            background:#4f46e5;
            border-color:#4f46e5;
            color:#fff;
        }
        .vendor-mobile-kpis {
            display:grid;
            grid-template-columns:repeat(2, minmax(0, 1fr));
            gap:8px;
        }
        .vendor-mobile-kpi {
            padding:10px 12px;
            border-radius:14px;
            border:1px solid #dbe3ef;
            background:#f8fafc;
        }
        .vendor-mobile-kpi span {
            display:block;
            color:#64748b;
            font-size:10px;
            font-weight:800;
            letter-spacing:.06em;
            text-transform:uppercase;
        }
        .vendor-mobile-kpi strong {
            display:block;
            margin-top:4px;
            color:#0f172a;
            font-size:15px;
            line-height:1.25;
        }
        .vendor-mobile-section {
            border:1px solid #dbe3ef;
            border-radius:18px;
            background:#fff;
            overflow:hidden;
        }
        .vendor-mobile-section summary {
            list-style:none;
            cursor:pointer;
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:10px;
            padding:14px;
            font-weight:800;
            color:#0f172a;
        }
        .vendor-mobile-section summary::-webkit-details-marker { display:none; }
        .vendor-mobile-section-body {
            padding:0 14px 14px;
            display:grid;
            gap:10px;
        }
        .vendor-mobile-grid {
            display:grid;
            grid-template-columns:repeat(2, minmax(0, 1fr));
            gap:8px;
        }
        .vendor-mobile-item {
            padding:10px 12px;
            border-radius:14px;
            border:1px solid #e2e8f0;
            background:#f8fafc;
            min-width:0;
        }
        .vendor-mobile-item b {
            display:block;
            color:#64748b;
            font-size:10px;
            font-weight:800;
            letter-spacing:.06em;
            text-transform:uppercase;
        }
        .vendor-mobile-item span {
            display:block;
            margin-top:4px;
            color:#0f172a;
            font-size:14px;
            line-height:1.4;
            overflow-wrap:anywhere;
        }
    }
</style>

<div class="vendor-profile-shell">
    <div class="vendor-profile-mobile">
        <section class="vendor-mobile-hero">
            <div class="vendor-mobile-top">
                <div class="vendor-mobile-avatar" aria-hidden="true">{{ $vendorInitials }}</div>
                <div class="vendor-mobile-headline">
                    <h1>{{ $vendor->name }}</h1>
                    <div class="vendor-mobile-copy">{{ $vendor->contact_person ?: 'No contact person' }} • {{ $cityLabel }}</div>
                </div>
            </div>

            <div class="vendor-mobile-chips">
                <span class="vendor-mobile-chip {{ $vendor->is_active ? 'is-active' : '' }}">{{ $vendor->is_active ? 'Active' : 'Inactive' }}</span>
                <span class="vendor-mobile-chip">{{ $vendor->vendor_type ?: 'General' }}</span>
                <span class="vendor-mobile-chip">Orders {{ $vendor->vendorOrderDetails->count() }}</span>
            </div>

            <div class="vendor-mobile-actions">
                <a href="{{ route('vendors.index') }}" class="vendor-mobile-action">Back</a>
                @if($vendor->phone)
                    <a href="tel:{{ preg_replace('/\s+/', '', $vendor->phone) }}" class="vendor-mobile-action">Call</a>
                @endif
                @if($vendor->whatsapp)
                    <a href="https://wa.me/{{ preg_replace('/\D+/', '', $vendor->whatsapp) }}" target="_blank" rel="noopener" class="vendor-mobile-action">WhatsApp</a>
                @endif
                @if($canUpdateVendors)
                    <a href="{{ route('vendors.edit', $vendor) }}" class="vendor-mobile-action is-primary">Edit</a>
                @endif
            </div>

            <div class="vendor-mobile-kpis">
                <div class="vendor-mobile-kpi"><span>Phone</span><strong>{{ $vendor->phone ?: 'Not added' }}</strong></div>
                <div class="vendor-mobile-kpi"><span>Email</span><strong>{{ $vendor->email ?: 'Not added' }}</strong></div>
                <div class="vendor-mobile-kpi"><span>GST</span><strong>{{ $vendor->gst_number ?: 'Not added' }}</strong></div>
                <div class="vendor-mobile-kpi"><span>Terms</span><strong>{{ $vendor->payment_terms ?: 'Not added' }}</strong></div>
            </div>
        </section>

        <details class="vendor-mobile-section" open>
            <summary>Overview <span class="vendor-mobile-chip">Open</span></summary>
            <div class="vendor-mobile-section-body">
                <div class="vendor-mobile-grid">
                    <div class="vendor-mobile-item"><b>Contact</b><span>{{ $vendor->contact_person ?: 'Not added' }}</span></div>
                    <div class="vendor-mobile-item"><b>WhatsApp</b><span>{{ $vendor->whatsapp ?: 'Not added' }}</span></div>
                    <div class="vendor-mobile-item"><b>Type</b><span>{{ $vendor->vendor_type ?: 'Not added' }}</span></div>
                    <div class="vendor-mobile-item"><b>GST Registration</b><span>{{ $vendor->gst_registration_type ?: 'Not added' }}</span></div>
                    <div class="vendor-mobile-item"><b>City</b><span>{{ $cityLabel }}</span></div>
                    <div class="vendor-mobile-item"><b>State / Pin</b><span>{{ $vendor->state ?: '—' }} @if($vendor->pincode) • {{ $vendor->pincode }} @endif</span></div>
                </div>
            </div>
        </details>

        <details class="vendor-mobile-section">
            <summary>Address <span class="vendor-mobile-chip">{{ $vendor->address ? 'Saved' : 'Empty' }}</span></summary>
            <div class="vendor-mobile-section-body">
                <div class="vendor-mobile-item"><b>Address</b><span>{{ $vendor->address ?: 'No address added.' }}</span></div>
            </div>
        </details>

        <details class="vendor-mobile-section">
            <summary>Notes <span class="vendor-mobile-chip">{{ $vendor->notes ? 'Saved' : 'Empty' }}</span></summary>
            <div class="vendor-mobile-section-body">
                <div class="vendor-mobile-item"><b>Notes</b><span>{{ $vendor->notes ?: 'No notes added.' }}</span></div>
            </div>
        </details>
    </div>

    <div class="vendor-profile-desktop">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; margin-bottom:24px;">
            <div>
                <div style="display:inline-flex; padding:6px 10px; border-radius:999px; background:#eff6ff; color:#1d4ed8; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.08em;">Company Settings</div>
                <h1 style="margin:12px 0 8px; font-size:34px; letter-spacing:-0.03em;">{{ $vendor->name }}</h1>
                <p style="margin:0; color:#64748b;">Vendor profile for delivery and third-party assignment references.</p>
            </div>
            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                @if($canExportVendors)
                    <a href="{{ route('vendors.export.csv', ['search' => $vendor->name]) }}" style="display:inline-flex; align-items:center; justify-content:center; padding:11px 16px; border-radius:12px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a; text-decoration:none; font-weight:600;">Export CSV</a>
                @endif
                @if($canUpdateVendors)
                    <a href="{{ route('vendors.edit', $vendor) }}" style="display:inline-flex; align-items:center; justify-content:center; padding:11px 16px; border-radius:12px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a; text-decoration:none; font-weight:600;">Edit</a>
                @endif
                @if($canDeleteVendors)
                    <form method="POST" action="{{ route('vendors.destroy', $vendor) }}" style="margin:0;" onsubmit="return confirm('Delete this vendor? Linked records will cause a safe deactivation instead of hard delete.');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" style="display:inline-flex; align-items:center; justify-content:center; padding:11px 16px; border:none; border-radius:12px; background:#fff1f2; color:#be123c; font-weight:700; cursor:pointer;">Delete / Deactivate</button>
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
                <div><div style="font-size:12px; color:#64748b; text-transform:uppercase; font-weight:700;">WhatsApp</div><div style="margin-top:6px;">{{ $vendor->whatsapp ?: 'Not added' }}</div></div>
                <div><div style="font-size:12px; color:#64748b; text-transform:uppercase; font-weight:700;">Email</div><div style="margin-top:6px;">{{ $vendor->email ?: 'Not added' }}</div></div>
                <div><div style="font-size:12px; color:#64748b; text-transform:uppercase; font-weight:700;">Vendor Type</div><div style="margin-top:6px;">{{ $vendor->vendor_type ?: 'Not added' }}</div></div>
                <div><div style="font-size:12px; color:#64748b; text-transform:uppercase; font-weight:700;">GST Number</div><div style="margin-top:6px;">{{ $vendor->gst_number ?: 'Not added' }}</div></div>
                <div><div style="font-size:12px; color:#64748b; text-transform:uppercase; font-weight:700;">GST Registration</div><div style="margin-top:6px;">{{ $vendor->gst_registration_type ?: 'Not added' }}</div></div>
                <div><div style="font-size:12px; color:#64748b; text-transform:uppercase; font-weight:700;">City</div><div style="margin-top:6px;">{{ $cityLabel }}</div></div>
                <div><div style="font-size:12px; color:#64748b; text-transform:uppercase; font-weight:700;">State</div><div style="margin-top:6px;">{{ $vendor->state ?: 'Not added' }}</div></div>
                <div><div style="font-size:12px; color:#64748b; text-transform:uppercase; font-weight:700;">Pincode</div><div style="margin-top:6px;">{{ $vendor->pincode ?: 'Not added' }}</div></div>
                <div><div style="font-size:12px; color:#64748b; text-transform:uppercase; font-weight:700;">Payment Terms</div><div style="margin-top:6px;">{{ $vendor->payment_terms ?: 'Not added' }}</div></div>
                <div><div style="font-size:12px; color:#64748b; text-transform:uppercase; font-weight:700;">Status</div><div style="margin-top:6px;">{{ $vendor->is_active ? 'Active' : 'Inactive' }}</div></div>
                <div><div style="font-size:12px; color:#64748b; text-transform:uppercase; font-weight:700;">Linked Orders</div><div style="margin-top:6px;">{{ $vendor->vendorOrderDetails->count() }}</div></div>
                <div style="grid-column:1 / -1;"><div style="font-size:12px; color:#64748b; text-transform:uppercase; font-weight:700;">Address</div><div style="margin-top:6px; color:#334155;">{{ $vendor->address ?: 'No address added.' }}</div></div>
                <div style="grid-column:1 / -1;"><div style="font-size:12px; color:#64748b; text-transform:uppercase; font-weight:700;">Notes</div><div style="margin-top:6px; color:#334155;">{{ $vendor->notes ?: 'No notes added.' }}</div></div>
            </div>
        </div>
    </div>
</div>
@endsection
