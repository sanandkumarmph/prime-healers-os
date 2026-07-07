@extends('layouts.app')

@section('content')
@php
    $currentUser = auth()->user();
    $canCreateVendors = $currentUser?->canAccessModule('vendors', 'create') ?? false;
    $canUpdateVendors = $currentUser?->canAccessModule('vendors', 'update') ?? false;
    $canDeleteVendors = $currentUser?->canAccessModule('vendors', 'delete') ?? false;
    $canExportVendors = $currentUser?->hasPermission('vendors.export') ?? false;
    $hasActiveFilters = filled($search) || filled($status);
    $vendorInitials = function (?string $name): string {
        $parts = preg_split('/\s+/', trim((string) $name)) ?: [];
        $initials = collect($parts)->filter()->take(2)->map(fn ($part) => strtoupper(substr($part, 0, 1)))->implode('');
        return $initials !== '' ? $initials : 'VN';
    };
@endphp
<style>
    .vendor-page-shell { max-width:1260px; margin:0 auto; }
    .vendor-mobile-view { display:none; }
    .vendor-desktop-view { display:block; }
    .vendor-mobile-card {
        display:grid;
        grid-template-columns:auto 1fr auto;
        gap:12px;
        align-items:start;
        padding:14px;
        border:1px solid #dbe3ef;
        border-radius:18px;
        background:#fff;
        box-shadow:0 10px 28px rgba(15, 23, 42, 0.05);
        text-decoration:none;
        color:inherit;
    }
    .vendor-mobile-avatar {
        width:44px;
        height:44px;
        border-radius:14px;
        display:grid;
        place-items:center;
        font-size:15px;
        font-weight:800;
        color:#1d4ed8;
        background:#eff6ff;
        border:1px solid #bfdbfe;
    }
    .vendor-mobile-main {
        min-width:0;
        display:grid;
        gap:6px;
    }
    .vendor-mobile-main strong {
        display:block;
        font-size:16px;
        line-height:1.2;
        color:#0f172a;
    }
    .vendor-mobile-meta,
    .vendor-mobile-metrics,
    .vendor-mobile-secondary {
        font-size:12px;
        line-height:1.45;
        color:#64748b;
    }
    .vendor-mobile-metrics {
        color:#475569;
        font-weight:700;
    }
    .vendor-mobile-actions {
        display:flex;
        align-items:center;
        gap:8px;
    }
    .vendor-mobile-action {
        width:38px;
        height:38px;
        border-radius:12px;
        border:1px solid #dbe3ef;
        background:#fff;
        display:grid;
        place-items:center;
        color:#334155;
        text-decoration:none;
        font-size:16px;
        font-weight:800;
        cursor:pointer;
    }
    .vendor-mobile-action.is-primary {
        background:#1d4ed8;
        border-color:#1d4ed8;
        color:#fff;
    }
    .vendor-mobile-action.is-whatsapp {
        background:#ecfdf5;
        border-color:#bbf7d0;
        color:#15803d;
    }
    .vendor-mobile-pill {
        display:inline-flex;
        align-items:center;
        min-height:24px;
        padding:4px 10px;
        border-radius:999px;
        font-size:11px;
        font-weight:800;
        border:1px solid #dbe3ef;
        background:#f8fafc;
        color:#475569;
    }
    .vendor-mobile-pill.is-active {
        background:#ecfdf5;
        border-color:#bbf7d0;
        color:#166534;
    }
    .vendor-mobile-pill.is-inactive {
        background:#f8fafc;
        border-color:#cbd5e1;
        color:#475569;
    }
    .vendor-mobile-empty {
        padding:18px 16px;
        border:1px dashed #cbd5e1;
        border-radius:18px;
        background:#fff;
        color:#64748b;
        font-size:13px;
        line-height:1.5;
    }
    @media (max-width: 768px) {
        .vendor-desktop-view { display:none !important; }
        .vendor-mobile-view {
            display:grid;
            gap:12px;
        }
        .vendor-page-shell {
            max-width:100%;
            padding-bottom:calc(92px + env(safe-area-inset-bottom, 0px));
        }
        .vendor-mobile-command {
            display:grid;
            gap:10px;
            padding:12px;
            border:1px solid #dbe3ef;
            border-radius:18px;
            background:#fff;
            box-shadow:0 10px 28px rgba(15, 23, 42, 0.05);
        }
        .vendor-mobile-head {
            display:grid;
            grid-template-columns:minmax(0, 1fr) auto;
            gap:10px;
            align-items:start;
        }
        .vendor-mobile-head h1 {
            margin:0;
            font-size:22px;
            line-height:1.05;
            color:#0f172a;
        }
        .vendor-mobile-head p {
            margin:4px 0 0;
            color:#64748b;
            font-size:12px;
            line-height:1.4;
        }
        .vendor-mobile-add {
            min-width:44px;
            height:44px;
            border-radius:14px;
            display:grid;
            place-items:center;
            background:#4f46e5;
            color:#fff;
            text-decoration:none;
            font-size:26px;
            line-height:1;
            font-weight:700;
            box-shadow:0 12px 30px rgba(79, 70, 229, 0.24);
        }
        .vendor-mobile-search-tools {
            display:grid;
            grid-template-columns:minmax(0, 1fr) auto;
            gap:8px;
            align-items:center;
        }
        .vendor-mobile-search {
            width:100%;
            min-height:42px;
            padding:10px 12px;
            border:1px solid #cbd5e1;
            border-radius:14px;
            background:#fff;
            color:#0f172a;
            font-size:15px;
            box-sizing:border-box;
        }
        .vendor-mobile-chip-row {
            display:flex;
            gap:8px;
            overflow-x:auto;
            padding:2px 1px 4px;
            scrollbar-width:none;
            -webkit-overflow-scrolling:touch;
        }
        .vendor-mobile-chip-row::-webkit-scrollbar { display:none; }
        .vendor-mobile-chip {
            flex:0 0 auto;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            min-height:32px;
            padding:6px 12px;
            border-radius:999px;
            border:1px solid #dbe3ef;
            background:#fff;
            color:#334155;
            text-decoration:none;
            font-size:12px;
            font-weight:800;
            white-space:nowrap;
        }
        .vendor-mobile-chip.is-active {
            background:#1d4ed8;
            border-color:#1d4ed8;
            color:#fff;
        }
        .vendor-mobile-list {
            display:grid;
            gap:10px;
        }
    }
</style>

<div class="vendor-page-shell">
    <div class="vendor-mobile-view" aria-label="Mobile vendors view">
        <div class="vendor-mobile-command">
            <div class="vendor-mobile-head">
                <div>
                    <h1>Vendors</h1>
                    <p>{{ $vendors->total() }} vendors{{ $status ? ' • ' . ucfirst($status) : '' }}</p>
                </div>
                @if($canCreateVendors)
                    <a href="{{ route('vendors.create') }}" class="vendor-mobile-add" aria-label="Add vendor">+</a>
                @endif
            </div>

            <div class="vendor-mobile-search-tools">
                <form method="GET" action="{{ route('vendors.index') }}" style="display:contents;">
                    <input type="search" name="search" value="{{ $search }}" placeholder="Search vendor, phone, city" class="vendor-mobile-search">
                    <div class="mobile-action-toolbar {{ $hasActiveFilters ? 'has-active-filters' : '' }}" aria-label="Vendor mobile filters">
                        <button type="button" class="mobile-toolbar-btn" data-mobile-filter-open="vendors-mobile-filters" data-filter-active="{{ $hasActiveFilters ? 'true' : 'false' }}" aria-label="Open vendor filters">
                            <span>Filters</span>
                        </button>
                    </div>
                </form>
            </div>

            <div class="vendor-mobile-chip-row" aria-label="Vendor quick filters">
                <a href="{{ route('vendors.index') }}" class="vendor-mobile-chip {{ blank($status) && blank($search) ? 'is-active' : '' }}">All</a>
                <a href="{{ route('vendors.index', array_filter(['status' => 'active', 'search' => $search ?: null])) }}" class="vendor-mobile-chip {{ $status === 'active' ? 'is-active' : '' }}">Active</a>
                <a href="{{ route('vendors.index', array_filter(['status' => 'inactive', 'search' => $search ?: null])) }}" class="vendor-mobile-chip {{ $status === 'inactive' ? 'is-active' : '' }}">Inactive</a>
                @if($canExportVendors)
                    <a href="{{ route('vendors.export.csv', request()->query()) }}" class="vendor-mobile-chip">Export</a>
                @endif
            </div>
        </div>

        <div id="vendors-mobile-filters" class="mobile-filter-sheet" data-mobile-filter-sheet hidden>
            <div class="mobile-filter-sheet-panel">
                <div class="mobile-filter-sheet-header">
                    <div>
                        <h3>Vendor Filters</h3>
                        <p>Keep search and status filters easy to reach on mobile.</p>
                    </div>
                    <button type="button" class="mobile-filter-sheet-close" data-mobile-sheet-close="vendors-mobile-filters" aria-label="Close filters">×</button>
                </div>
                <div class="mobile-filter-sheet-body">
                    <form method="GET" action="{{ route('vendors.index') }}" class="mobile-sheet-form">
                        <div class="mobile-sheet-grid">
                            <div class="mobile-sheet-field">
                                <label for="vendor_mobile_search">Search</label>
                                <input id="vendor_mobile_search" type="search" name="search" value="{{ $search }}" placeholder="Vendor, contact, phone, email">
                            </div>
                            <div class="mobile-sheet-field">
                                <label for="vendor_mobile_status">Status</label>
                                <select id="vendor_mobile_status" name="status">
                                    <option value="">All Status</option>
                                    <option value="active" @selected($status === 'active')>Active</option>
                                    <option value="inactive" @selected($status === 'inactive')>Inactive</option>
                                </select>
                            </div>
                        </div>
                        <div class="mobile-sheet-actions">
                            <a href="{{ route('vendors.index') }}" class="ops-btn-light">Reset</a>
                            <button type="submit" class="ops-btn">Apply Filters</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="vendor-mobile-list">
            @forelse($vendors as $vendor)
                @php
                    $cityLabel = $vendor->cityRecord?->name ?? $vendor->city ?? 'City not set';
                    $typeLabel = $vendor->vendor_type ?: 'General';
                @endphp
                <article class="vendor-mobile-card" data-href="{{ route('vendors.show', $vendor) }}" tabindex="0" role="link" aria-label="Open {{ $vendor->name }}">
                    <div class="vendor-mobile-avatar" aria-hidden="true">{{ $vendorInitials($vendor->name) }}</div>
                    <div class="vendor-mobile-main">
                        <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                            <strong>{{ $vendor->name }}</strong>
                            <span class="vendor-mobile-pill {{ $vendor->is_active ? 'is-active' : 'is-inactive' }}">{{ $vendor->is_active ? 'Active' : 'Inactive' }}</span>
                        </div>
                        <div class="vendor-mobile-meta">{{ $vendor->contact_person ?: 'No contact person' }}</div>
                        <div class="vendor-mobile-secondary">{{ $vendor->phone ?: 'No phone' }} • {{ $cityLabel }}</div>
                        <div class="vendor-mobile-metrics">Type {{ $typeLabel }} • Orders {{ $vendor->vendorOrderDetails->count() }}</div>
                    </div>
                    <div class="vendor-mobile-actions">
                        @if($vendor->phone)
                            <a href="tel:{{ preg_replace('/\s+/', '', $vendor->phone) }}" class="vendor-mobile-action is-primary" aria-label="Call {{ $vendor->name }}">☎</a>
                        @endif
                        @if($vendor->whatsapp)
                            <a href="https://wa.me/{{ preg_replace('/\D+/', '', $vendor->whatsapp) }}" target="_blank" rel="noopener" class="vendor-mobile-action is-whatsapp" aria-label="WhatsApp {{ $vendor->name }}">◔</a>
                        @endif
                    </div>
                </article>
            @empty
                <div class="vendor-mobile-empty">No vendors found right now. Try a broader filter or add a new vendor.</div>
            @endforelse
        </div>

        <div>{{ $vendors->links() }}</div>
    </div>

    <div class="vendor-desktop-view">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; margin-bottom:24px;">
            <div>
                <div style="display:inline-flex; padding:6px 10px; border-radius:999px; background:#eff6ff; color:#1d4ed8; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.08em;">Company Settings</div>
                <h1 style="margin:12px 0 8px; font-size:34px; letter-spacing:-0.03em;">Vendors</h1>
                <p style="margin:0; color:#64748b;">Manage delivery vendors and third-party partners from a clean master list.</p>
            </div>
            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                @if($canExportVendors)
                    <a href="{{ route('vendors.export.csv', request()->query()) }}" style="display:inline-flex; align-items:center; justify-content:center; padding:11px 16px; border-radius:12px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a; text-decoration:none; font-weight:700;">Export CSV</a>
                @endif
                @if($canCreateVendors)
                    <a href="{{ route('vendors.create') }}" style="display:inline-flex; align-items:center; justify-content:center; padding:11px 16px; border-radius:12px; background:#1d4ed8; color:#ffffff; text-decoration:none; font-weight:700;">+ Add Vendor</a>
                @endif
            </div>
        </div>

        @if(session('success'))
            <div style="margin-bottom:18px; padding:14px 16px; border-radius:16px; background:#ecfdf5; border:1px solid #bbf7d0; color:#166534;">{{ session('success') }}</div>
        @endif

        @if(session('error'))
            <div style="margin-bottom:18px; padding:14px 16px; border-radius:16px; background:#fff1f2; border:1px solid #fecaca; color:#991b1b;">{{ session('error') }}</div>
        @endif

        <details style="margin-bottom:18px; border:1px solid #e2e8f0; border-radius:20px; background:#ffffff;">
            <summary style="cursor:pointer; list-style:none; padding:16px 18px; font-weight:800; color:#0f172a;">Filter / Sort <span style="color:#64748b; font-size:12px;">{{ $search || $status ? 'Active' : 'Expand' }}</span></summary>
            <form method="GET" action="{{ route('vendors.index') }}" style="display:grid; grid-template-columns:2fr 1fr auto; gap:12px; padding:0 18px 18px;">
                <input type="text" name="search" value="{{ $search }}" placeholder="Search vendor, contact, phone, email" style="width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px;">
                <select name="status" style="width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff;">
                    <option value="">All Status</option>
                    <option value="active" @selected($status === 'active')>Active</option>
                    <option value="inactive" @selected($status === 'inactive')>Inactive</option>
                </select>
                <div style="display:flex; gap:10px;">
                    <button type="submit" style="padding:12px 16px; border:none; border-radius:14px; background:#0f172a; color:#ffffff; font-weight:700; cursor:pointer;">Apply</button>
                    <a href="{{ route('vendors.index') }}" style="display:inline-flex; align-items:center; justify-content:center; padding:12px 14px; border-radius:14px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a; text-decoration:none; font-weight:600;">Reset</a>
                </div>
            </form>
        </details>

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(300px, 1fr)); gap:18px;">
            @forelse($vendors as $vendor)
                <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:22px; padding:22px;">
                    <div style="display:flex; justify-content:space-between; gap:12px; align-items:flex-start;">
                        <div>
                            <h2 style="margin:0; font-size:22px; letter-spacing:-0.02em;">{{ $vendor->name }}</h2>
                            <p style="margin:8px 0 0; color:#64748b; font-size:13px;">{{ $vendor->contact_person ?: 'No contact person' }}</p>
                        </div>
                        <span style="display:inline-flex; padding:6px 10px; border-radius:999px; background:{{ $vendor->is_active ? '#ecfdf5' : '#f8fafc' }}; color:{{ $vendor->is_active ? '#166534' : '#475569' }}; font-size:11px; font-weight:700; text-transform:uppercase;">
                            {{ $vendor->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </div>

                    <div style="margin-top:16px; display:grid; gap:8px; color:#475569; font-size:14px;">
                        <div><strong>Phone:</strong> {{ $vendor->phone ?: '—' }}</div>
                        <div><strong>WhatsApp:</strong> {{ $vendor->whatsapp ?: '—' }}</div>
                        <div><strong>Email:</strong> {{ $vendor->email ?: '—' }}</div>
                        <div><strong>Type:</strong> {{ $vendor->vendor_type ?: 'General' }}</div>
                        <div><strong>City:</strong> {{ $vendor->cityRecord?->name ?? $vendor->city ?? 'Not mapped' }}</div>
                    </div>

                    <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:18px;">
                        <a href="{{ route('vendors.show', $vendor) }}" style="display:inline-flex; align-items:center; justify-content:center; padding:10px 14px; border-radius:12px; background:#0f172a; color:#ffffff; text-decoration:none; font-weight:600;">View</a>
                        @if($canUpdateVendors)
                            <a href="{{ route('vendors.edit', $vendor) }}" style="display:inline-flex; align-items:center; justify-content:center; padding:10px 14px; border-radius:12px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a; text-decoration:none; font-weight:600;">Edit</a>
                        @endif
                        @if($canDeleteVendors)
                            <form method="POST" action="{{ route('vendors.destroy', $vendor) }}" style="margin:0;" onsubmit="return confirm('Delete this vendor? Linked vendors will be safely deactivated instead of hard deleted.');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" style="display:inline-flex; align-items:center; justify-content:center; padding:10px 14px; border:0; border-radius:12px; background:#fff1f2; color:#be123c; font-weight:700; cursor:pointer;">Delete / Deactivate</button>
                            </form>
                        @endif
                    </div>
                </div>
            @empty
                <div style="grid-column:1 / -1; padding:28px; border-radius:22px; border:1px dashed #cbd5e1; background:#ffffff; color:#64748b;">No vendors found yet.</div>
            @endforelse
        </div>

        <div style="margin-top:20px;">{{ $vendors->links() }}</div>
    </div>
</div>

@if($canCreateVendors)
    @include('partials.mobile-fab', ['href' => route('vendors.create'), 'label' => 'Add Vendor'])
@endif

<script>
    (function () {
        const cards = Array.from(document.querySelectorAll('.vendor-mobile-card[data-href]'));
        cards.forEach(function (card) {
            const url = card.getAttribute('data-href');
            if (!url) {
                return;
            }

            card.addEventListener('click', function (event) {
                if (event.target.closest('a, button, form, input, select, textarea, summary, details')) {
                    return;
                }

                window.location.href = url;
            });

            card.addEventListener('keydown', function (event) {
                if ((event.key === 'Enter' || event.key === ' ') && !event.target.closest('a, button, form, input, select, textarea, summary, details')) {
                    event.preventDefault();
                    window.location.href = url;
                }
            });
        });
    })();
</script>
@endsection
