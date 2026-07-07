@extends('layouts.app')

@section('breadcrumbs')
    <span class="sr-only">Business Partners</span>
@endsection

@section('content')
@php
    $money = fn ($amount) => 'Rs. ' . number_format((float) $amount, 2);
    $partnerRows = $businessPartners->getCollection();
    $inactivePartners = max(0, (int) $totalPartners - (int) $activePartners);
    $topRentalPartners = $partnerRows->sortByDesc(fn ($partner) => (int) ($partner->rentals_count ?? 0))->take(5);
    $recentPartners = $partnerRows->sortByDesc('updated_at')->take(4);
@endphp

<style>
    .bp-page { display:grid; gap:12px; padding:6px 18px 30px; max-width:1460px; margin:0 auto; color:#111827; overflow-x:hidden; }
    .bp-header { display:flex; justify-content:space-between; gap:16px; align-items:flex-start; }
    .bp-breadcrumb { display:none; }
    .bp-title { margin:0; font-size:26px; line-height:1.08; letter-spacing:0; }
    .bp-subtitle { margin:5px 0 0; color:#5b6b84; font-size:14px; }
    .bp-actions { display:flex; gap:10px; align-items:center; flex-wrap:wrap; justify-content:flex-end; }
    .bp-btn { display:inline-flex; align-items:center; justify-content:center; gap:8px; min-height:38px; padding:9px 13px; border:1px solid #cbd7ea; border-radius:12px; background:#fff; color:#243246; font-size:13px; font-weight:800; text-decoration:none; cursor:pointer; white-space:nowrap; }
    .bp-btn-primary { border-color:#4338ca; background:#4f46e5; color:#fff; box-shadow:0 10px 22px rgba(79,70,229,.18); }
    .bp-btn-icon { width:36px; min-width:36px; padding:0; }
    .bp-btn-icon svg { width:17px; height:17px; stroke:currentColor; }
    .bp-card { background:#fff; border:1px solid #dce5f2; border-radius:15px; box-shadow:0 8px 22px rgba(15,23,42,.04); }
    .bp-kpis { display:grid; grid-template-columns:repeat(5, minmax(0,1fr)); gap:10px; }
    .bp-kpi { display:flex; align-items:center; gap:9px; padding:10px 12px; min-height:58px; min-width:0; }
    .bp-icon { display:inline-flex; align-items:center; justify-content:center; width:34px; height:34px; border-radius:12px; background:#eef2ff; color:#4f46e5; font-size:11px; font-weight:900; flex:0 0 auto; }
    .bp-icon.green { background:#dcfce7; color:#047857; }
    .bp-icon.blue { background:#dbeafe; color:#1d4ed8; }
    .bp-icon.amber { background:#fff7ed; color:#c2410c; }
    .bp-icon.red { background:#fee2e2; color:#b91c1c; }
    .bp-kpi > div { min-width:0; }
    .bp-kpi-label { color:#5b6b84; font-size:11px; font-weight:800; }
    .bp-kpi-value { margin-top:1px; color:#111827; font-size:clamp(17px,1.35vw,21px); font-weight:900; line-height:1.05; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:100%; }
    .bp-kpi-note { margin-top:3px; color:#64748b; font-size:11px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .bp-layout { display:grid; grid-template-columns:minmax(0,1fr) minmax(260px,280px); gap:12px; align-items:start; }
    .bp-layout > main { min-width:0; overflow:hidden; }
    .bp-filter-card { padding:0; overflow:hidden; }
    .bp-filter-summary { display:flex; justify-content:flex-end; align-items:center; min-height:46px; padding:7px 10px; cursor:pointer; list-style:none; }
    .bp-filter-summary::-webkit-details-marker { display:none; }
    .bp-filter-toggle { width:38px; height:38px; border:1px solid #cbd7ea; border-radius:12px; display:inline-flex; align-items:center; justify-content:center; color:#4338ca; background:#fff; }
    .bp-filter-toggle svg { width:18px; height:18px; stroke:currentColor; }
    .bp-filter-body { padding:0 10px 10px; }
    .bp-filter-row { display:grid; grid-template-columns:minmax(220px,1fr) minmax(135px,.6fr) minmax(120px,.5fr) minmax(130px,.55fr) auto auto; gap:9px; align-items:end; }
    .bp-field label { display:block; color:#475569; font-size:11px; font-weight:900; letter-spacing:.04em; text-transform:uppercase; margin-bottom:6px; }
    .bp-field input, .bp-field select { width:100%; height:40px; box-sizing:border-box; border:1px solid #cbd7ea; border-radius:12px; padding:0 12px; color:#111827; background:#fff; font-size:14px; }
    .bp-chips { display:flex; gap:8px; flex-wrap:wrap; margin-top:10px; }
    .bp-chip { display:inline-flex; align-items:center; gap:6px; min-height:30px; padding:6px 11px; border:1px solid #d7e0ee; border-radius:999px; background:#fff; color:#34445b; font-size:12px; font-weight:800; text-decoration:none; }
    .bp-chip.active { background:#eef2ff; border-color:#a5b4fc; color:#4338ca; }
    .bp-table-card { overflow:hidden; }
    .bp-table-top { display:flex; justify-content:space-between; gap:12px; align-items:center; padding:14px 16px; border-bottom:1px solid #e7edf6; }
    .bp-table-title { margin:0; font-size:18px; }
    .bp-table-sub { margin-top:3px; color:#64748b; font-size:12px; }
    .bp-table-wrap { overflow:auto; }
    .bp-table { width:100%; min-width:880px; border-collapse:separate; border-spacing:0; }
    .bp-table th { padding:10px 12px; background:#f8fafc; border-bottom:1px solid #e4ebf5; color:#64748b; font-size:11px; letter-spacing:.05em; text-transform:uppercase; text-align:left; }
    .bp-table td { padding:12px; border-bottom:1px solid #edf2f8; vertical-align:top; font-size:13px; }
    .bp-partner { display:flex; gap:10px; align-items:flex-start; min-width:250px; }
    .bp-avatar { width:44px; height:44px; border-radius:14px; display:inline-flex; align-items:center; justify-content:center; flex:0 0 auto; background:#eef2ff; color:#4f46e5; font-weight:900; border:1px solid #d9e2f1; }
    .bp-name { font-weight:900; color:#111827; font-size:14px; }
    .bp-muted { color:#64748b; font-size:12px; line-height:1.45; }
    .bp-link { color:#2563eb; font-weight:800; text-decoration:none; }
    .bp-status { display:inline-flex; align-items:center; justify-content:center; gap:6px; padding:5px 9px; border-radius:999px; font-size:11px; font-weight:900; line-height:1; text-transform:uppercase; white-space:nowrap; word-break:normal; overflow-wrap:normal; writing-mode:horizontal-tb; min-width:max-content; flex:0 0 auto; }
    .bp-status.active { background:#dcfce7; color:#166534; }
    .bp-status.inactive { background:#e2e8f0; color:#475569; }
    .bp-type { display:inline-flex; padding:5px 9px; border-radius:999px; background:#eef2ff; color:#4f46e5; font-size:11px; font-weight:900; text-transform:uppercase; }
    .bp-money { color:#c2410c; font-weight:900; }
    .bp-actions-cell { display:flex; gap:7px; align-items:center; }
    .bp-more { position:relative; }
    .bp-more summary { list-style:none; }
    .bp-more summary::-webkit-details-marker { display:none; }
    .bp-menu { position:absolute; right:0; top:42px; z-index:20; min-width:170px; padding:8px; border:1px solid #dbe4f1; border-radius:14px; background:#fff; box-shadow:0 18px 36px rgba(15,23,42,.16); display:grid; gap:6px; }
    .bp-menu a, .bp-menu button { display:flex; width:100%; box-sizing:border-box; padding:9px 10px; border:0; border-radius:10px; background:#f8fafc; color:#243246; text-decoration:none; font-size:13px; font-weight:800; text-align:left; cursor:pointer; }
    .bp-clients { margin-top:8px; }
    .bp-clients summary { cursor:pointer; color:#2563eb; font-weight:900; font-size:12px; list-style:none; }
    .bp-clients summary::-webkit-details-marker { display:none; }
    .bp-client-grid { display:grid; gap:8px; margin-top:9px; }
    .bp-client { display:flex; justify-content:space-between; gap:10px; padding:9px 10px; border:1px solid #dce5f2; border-radius:12px; background:#f8fafc; }
    .bp-side { display:grid; gap:10px; position:sticky; top:82px; min-width:0; width:100%; }
    .bp-side-card { padding:12px; min-width:0; }
    .bp-side-title { margin:0 0 8px; font-size:15px; }
    .bp-health { display:grid; grid-template-columns:76px 1fr; gap:10px; align-items:center; }
    .bp-ring { width:68px; height:68px; border-radius:50%; background:conic-gradient(#22c55e var(--pct), #e2e8f0 0); display:grid; place-items:center; }
    .bp-ring-inner { width:50px; height:50px; border-radius:50%; background:#fff; display:grid; place-items:center; font-size:15px; font-weight:900; }
    .bp-side-row { display:flex; justify-content:space-between; gap:10px; padding:7px 0; border-bottom:1px solid #eef2f7; color:#475569; font-size:12px; }
    .bp-side-row strong { color:#111827; }
    .bp-mobile-list { display:none; }
    .bp-mobile-card { padding:14px; display:grid; gap:10px; }
    .bp-mobile-head { display:flex; justify-content:space-between; gap:12px; align-items:flex-start; }
    .bp-mobile-head .bp-partner { min-width:0; }
    .bp-mobile-head .bp-status { margin-left:auto; align-self:flex-start; }
    .bp-mobile-metrics { display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:8px; }
    .bp-mini { padding:9px; border:1px solid #e1e8f3; border-radius:12px; background:#f8fafc; }
    .bp-mini span { display:block; color:#64748b; font-size:11px; font-weight:800; text-transform:uppercase; }
    .bp-mini strong { display:block; margin-top:2px; font-size:15px; }
    @media (max-width: 1320px) {
        .bp-layout { grid-template-columns:1fr; }
        .bp-side { position:static; grid-template-columns:repeat(3, minmax(0,1fr)); }
        .bp-kpis { grid-template-columns:repeat(3, minmax(0,1fr)); }
    }
    @media (max-width: 900px) {
        .bp-header { display:grid; }
        .bp-actions { justify-content:flex-start; }
        .bp-filter-row { grid-template-columns:1fr 1fr; }
        .bp-side { grid-template-columns:1fr; }
        .bp-kpis { grid-template-columns:repeat(2, minmax(0,1fr)); }
        .bp-table-wrap { display:none; }
        .bp-mobile-list { display:grid; gap:10px; padding:12px; }
    }
    @media (max-width: 560px) {
        .bp-page { padding:12px 10px 92px; gap:12px; }
        .bp-title { font-size:24px; }
        .bp-subtitle { font-size:13px; }
        .bp-kpis { grid-template-columns:1fr 1fr; gap:8px; }
        .bp-kpi { padding:11px; min-height:64px; }
        .bp-kpi-value { font-size:20px; }
        .bp-icon { width:36px; height:36px; border-radius:12px; }
        .bp-filter-row { grid-template-columns:1fr; }
        .bp-mobile-metrics { grid-template-columns:1fr 1fr; }
        .bp-btn { min-height:36px; padding:8px 11px; }
    }
</style>

<div class="bp-page">
    <header class="bp-header">
        <div>
            <div class="bp-breadcrumb">Home / Business Partners</div>
            <h1 class="bp-title">Business Partners</h1>
            <p class="bp-subtitle">Manage tie-up partners and their actual clients.</p>
        </div>
        <div class="bp-actions">
            @if(Route::has('business-partners.export'))
                <a href="{{ route('business-partners.export', request()->query()) }}" class="bp-btn" title="Export partners">Export</a>
            @endif
            @if(Route::has('import.index'))
                <a href="{{ route('import.index') }}" class="bp-btn" title="Import partners">Import Partners</a>
            @endif
            @can('create', App\Models\BusinessPartner::class)
                <a href="{{ route('business-partners.create') }}" class="bp-btn bp-btn-primary">+ Add Partner</a>
            @endcan
        </div>
    </header>

    <section class="bp-kpis" aria-label="Business partner summary">
        <div class="bp-card bp-kpi"><span class="bp-icon">BP</span><div><div class="bp-kpi-label">Partners</div><div class="bp-kpi-value">{{ $totalPartners }}</div><div class="bp-kpi-note">{{ $activePartners }} active</div></div></div>
        <div class="bp-card bp-kpi"><span class="bp-icon green">AC</span><div><div class="bp-kpi-label">Actual Clients</div><div class="bp-kpi-value">{{ $totalClients }}</div><div class="bp-kpi-note">{{ $activeClients }} active</div></div></div>
        <div class="bp-card bp-kpi"><span class="bp-icon blue">RT</span><div><div class="bp-kpi-label">Active Rentals</div><div class="bp-kpi-value">{{ $totalRentals }}</div><div class="bp-kpi-note">Linked rentals</div></div></div>
        <div class="bp-card bp-kpi"><span class="bp-icon amber">Rs</span><div><div class="bp-kpi-label">Outstanding</div><div class="bp-kpi-value">{{ $money($totalOutstandingAmount) }}</div><div class="bp-kpi-note">Linked invoice balance</div></div></div>
        <div class="bp-card bp-kpi"><span class="bp-icon red">PI</span><div><div class="bp-kpi-label">Partner Invoices</div><div class="bp-kpi-value">{{ $totalPartnerInvoices }}</div><div class="bp-kpi-note">Linked invoices</div></div></div>
    </section>

    <div class="bp-layout">
        <main style="display:grid;gap:12px;min-width:0;">
            <details class="bp-card bp-filter-card">
                <summary class="bp-filter-summary" aria-label="Open filters" title="Filters"><span class="bp-filter-toggle"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 6h16M7 12h10M10 18h4" stroke-width="2" stroke-linecap="round"/></svg></span></summary>
                <div class="bp-filter-body">
                    <form method="GET" class="bp-filter-row">
                        <div class="bp-field">
                            <label for="bp-search">Search</label>
                            <input id="bp-search" type="search" name="search" value="{{ $search }}" placeholder="Partner name, contact, phone, email, city">
                        </div>
                        <div class="bp-field">
                            <label for="bp-type">Partner Type</label>
                            <select id="bp-type" name="partner_type">
                                <option value="">All types</option>
                                <option value="gst_registered" {{ $partnerType === 'gst_registered' ? 'selected' : '' }}>GST registered</option>
                                <option value="general" {{ $partnerType === 'general' ? 'selected' : '' }}>General</option>
                            </select>
                        </div>
                        <div class="bp-field">
                            <label for="bp-status">Status</label>
                            <select id="bp-status" name="status">
                                <option value="">All</option>
                                <option value="active" {{ $status === 'active' ? 'selected' : '' }}>Active</option>
                                <option value="inactive" {{ $status === 'inactive' ? 'selected' : '' }}>Inactive</option>
                            </select>
                        </div>
                        <div class="bp-field">
                            <label for="bp-city">City</label>
                            <select id="bp-city" name="city">
                                <option value="">All cities</option>
                                @foreach($cityOptions as $cityOption)
                                    <option value="{{ $cityOption }}" {{ $city === $cityOption ? 'selected' : '' }}>{{ $cityOption }}</option>
                                @endforeach
                            </select>
                        </div>
                        <button type="submit" class="bp-btn bp-btn-primary">Apply</button>
                        <a href="{{ route('business-partners.index') }}" class="bp-btn">Clear</a>
                    </form>
                    <div class="bp-chips" aria-label="Quick partner filters">
                        <a class="bp-chip {{ $status === '' && $partnerType === '' ? 'active' : '' }}" href="{{ route('business-partners.index', array_filter(['search' => $search, 'city' => $city])) }}">All</a>
                        <a class="bp-chip {{ $partnerType === 'gst_registered' ? 'active' : '' }}" href="{{ route('business-partners.index', array_filter(['search' => $search, 'city' => $city, 'status' => $status, 'partner_type' => 'gst_registered'])) }}">GST</a>
                        <a class="bp-chip {{ $partnerType === 'general' ? 'active' : '' }}" href="{{ route('business-partners.index', array_filter(['search' => $search, 'city' => $city, 'status' => $status, 'partner_type' => 'general'])) }}">General</a>
                        <a class="bp-chip {{ $status === 'active' ? 'active' : '' }}" href="{{ route('business-partners.index', array_filter(['search' => $search, 'city' => $city, 'partner_type' => $partnerType, 'status' => 'active'])) }}">Active</a>
                        <a class="bp-chip {{ $status === 'inactive' ? 'active' : '' }}" href="{{ route('business-partners.index', array_filter(['search' => $search, 'city' => $city, 'partner_type' => $partnerType, 'status' => 'inactive'])) }}">Inactive</a>
                    </div>
                </div>
            </details>
            <section class="bp-card bp-table-card">
                <div class="bp-table-top">
                    <div>
                        <h2 class="bp-table-title">Partner Operations</h2>
                        <div class="bp-table-sub">{{ $businessPartners->total() }} partner{{ $businessPartners->total() === 1 ? '' : 's' }} matched</div>
                    </div>
                    <span class="bp-chip active">{{ $businessPartners->count() }} shown</span>
                </div>

                <div class="bp-table-wrap">
                    <table class="bp-table">
                        <thead>
                            <tr>
                                <th>Partner Details</th>
                                <th>Type</th>
                                <th>Location</th>
                                <th>Clients & Rentals</th>
                                <th>Outstanding</th>
                                <th>Status</th>
                                <th>Last Activity</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($businessPartners as $partner)
                                @php
                                    $initials = collect(explode(' ', $partner->displayName()))->filter()->map(fn ($part) => mb_substr($part, 0, 1))->take(2)->implode('') ?: 'BP';
                                    $clientsPreview = $partner->partnerClients->take(3);
                                    $statusClass = $partner->status === 'inactive' ? 'inactive' : 'active';
                                    $typeLabel = $partner->gst_registered ? 'GST Partner' : 'General';
                                @endphp
                                <tr>
                                    <td>
                                        <div class="bp-partner">
                                            <span class="bp-avatar">{{ $initials }}</span>
                                            <div>
                                                <div class="bp-name">{{ $partner->displayName() }}</div>
                                                <div class="bp-muted">{{ $partner->contact_person ?: 'No contact person' }}</div>
                                                <div class="bp-muted">{{ $partner->phone ?: 'No phone' }} @if($partner->email) <span aria-hidden="true">&middot;</span> <a class="bp-link" href="mailto:{{ $partner->email }}" title="Email {{ $partner->email }}">Email</a> @endif</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td><span class="bp-type">{{ $typeLabel }}</span></td>
                                    <td>
                                        <strong>{{ collect([$partner->city, $partner->state])->filter()->implode(', ') ?: 'Not set' }}</strong>
                                        <div class="bp-muted">{{ $partner->pincode ?: 'No pincode' }}</div>
                                    </td>
                                    <td>
                                        <strong>{{ $partner->partner_clients_count }} client{{ $partner->partner_clients_count === 1 ? '' : 's' }}</strong>
                                        <div class="bp-muted">{{ $partner->rentals_count }} rentals <span aria-hidden="true">&middot;</span> {{ $partner->sales_count }} sales</div>
                                        <details class="bp-clients">
                                            <summary>Actual clients</summary>
                                            <div class="bp-client-grid">
                                                @forelse($clientsPreview as $client)
                                                    <div class="bp-client">
                                                        <div>
                                                            <strong>{{ $client->displayName() }}</strong>
                                                            <div class="bp-muted">{{ $client->primaryPhone() ?: 'No phone' }}</div>
                                                        </div>
                                                        <span class="bp-status {{ $client->status === 'inactive' ? 'inactive' : 'active' }}">{{ ucfirst($client->status ?: 'active') }}</span>
                                                    </div>
                                                @empty
                                                    <div class="bp-muted">No actual clients linked.</div>
                                                @endforelse
                                                @if($partner->partner_clients_count > $clientsPreview->count())
                                                    <a class="bp-link" href="{{ route('business-partners.show', $partner) }}">View all {{ $partner->partner_clients_count }}</a>
                                                @endif
                                            </div>
                                        </details>
                                    </td>
                                    <td>
                                        <div class="bp-money">{{ $money($partner->outstanding_amount ?? 0) }}</div>
                                        <div class="bp-muted">{{ $partner->linked_invoices_count }} invoice{{ (int) $partner->linked_invoices_count === 1 ? '' : 's' }}</div>
                                        @if((int) $partner->linked_invoices_count > 0)
                                            <a class="bp-link" href="{{ route('business-partners.show', $partner) }}">View invoices</a>
                                        @endif
                                    </td>
                                    <td><span class="bp-status {{ $statusClass }}">{{ ucfirst($partner->status ?: 'active') }}</span></td>
                                    <td>
                                        <strong>Updated</strong>
                                        <div class="bp-muted">{{ optional($partner->updated_at)->diffForHumans() ?: 'No activity' }}</div>
                                    </td>
                                    <td>
                                        <div class="bp-actions-cell">
                                            <a class="bp-btn bp-btn-icon" href="{{ route('business-partners.show', $partner) }}" title="View partner" aria-label="View {{ $partner->displayName() }}"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><circle cx="12" cy="12" r="2.7" stroke-width="1.8"/></svg></a>
                                            @can('update', $partner)
                                                <a class="bp-btn bp-btn-icon" href="{{ route('business-partners.edit', $partner) }}" title="Edit partner" aria-label="Edit {{ $partner->displayName() }}">Edit</a>
                                            @endcan
                                            <details class="bp-more">
                                                <summary class="bp-btn bp-btn-icon" title="More actions" aria-label="More actions">...</summary>
                                                <div class="bp-menu">
                                                    <a href="{{ route('business-partners.show', $partner) }}">Open profile</a>
                                                    <a href="{{ route('business-partners.clients.create', $partner) }}">Add actual client</a>
                                                    @can('update', $partner)
                                                        <a href="{{ route('business-partners.edit', $partner) }}">Edit partner</a>
                                                    @endcan
                                                </div>
                                            </details>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="bp-muted">No business partners found.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="bp-mobile-list">
                    @forelse($businessPartners as $partner)
                        @php
                            $initials = collect(explode(' ', $partner->displayName()))->filter()->map(fn ($part) => mb_substr($part, 0, 1))->take(2)->implode('') ?: 'BP';
                            $clientsPreview = $partner->partnerClients->take(3);
                        @endphp
                        <article class="bp-card bp-mobile-card">
                            <div class="bp-mobile-head">
                                <div class="bp-partner">
                                    <span class="bp-avatar">{{ $initials }}</span>
                                    <div>
                                        <div class="bp-name">{{ $partner->displayName() }}</div>
                                        <div class="bp-muted">{{ collect([$partner->city, $partner->state])->filter()->implode(', ') ?: 'Location not set' }}</div>
                                        <div class="bp-muted">{{ $partner->phone ?: 'No phone' }}</div>
                                    </div>
                                </div>
                                <span class="bp-status {{ $partner->status === 'inactive' ? 'inactive' : 'active' }}">{{ ucfirst($partner->status ?: 'active') }}</span>
                            </div>
                            <div class="bp-mobile-metrics">
                                <div class="bp-mini"><span>Clients</span><strong>{{ $partner->partner_clients_count }}</strong></div>
                                <div class="bp-mini"><span>Rentals</span><strong>{{ $partner->rentals_count }}</strong></div>
                                <div class="bp-mini"><span>Due</span><strong>{{ $money($partner->outstanding_amount ?? 0) }}</strong></div>
                            </div>
                            <details class="bp-clients">
                                <summary>Actual clients</summary>
                                <div class="bp-client-grid">
                                    @forelse($clientsPreview as $client)
                                        <div class="bp-client">
                                            <div><strong>{{ $client->displayName() }}</strong><div class="bp-muted">{{ $client->primaryPhone() ?: 'No phone' }}</div></div>
                                            <span class="bp-status {{ $client->status === 'inactive' ? 'inactive' : 'active' }}">{{ ucfirst($client->status ?: 'active') }}</span>
                                        </div>
                                    @empty
                                        <div class="bp-muted">No actual clients linked.</div>
                                    @endforelse
                                </div>
                            </details>
                            <div class="bp-actions-cell">
                                <a class="bp-btn bp-btn-icon" href="{{ route('business-partners.show', $partner) }}" title="View partner" aria-label="View {{ $partner->displayName() }}"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><circle cx="12" cy="12" r="2.7" stroke-width="1.8"/></svg></a>
                                @can('update', $partner)<a class="bp-btn" href="{{ route('business-partners.edit', $partner) }}">Edit</a>@endcan
                                <a class="bp-btn" href="{{ route('business-partners.clients.create', $partner) }}">Add Client</a>
                            </div>
                        </article>
                    @empty
                        <div class="bp-muted">No business partners found.</div>
                    @endforelse
                </div>

                <div style="padding:12px 16px;">{{ $businessPartners->links() }}</div>
            </section>
        </main>

        <aside class="bp-side" aria-label="Business partner insights">
            <section class="bp-card bp-side-card">
                <h3 class="bp-side-title">Partner Health</h3>
                @php $activePercent = $totalPartners > 0 ? round(($activePartners / max(1, $totalPartners)) * 100) : 0; @endphp
                <div class="bp-health">
                    <div class="bp-ring" style="--pct: {{ $activePercent }}%"><div class="bp-ring-inner">{{ $activePercent }}%</div></div>
                    <div>
                        <div class="bp-side-row"><span>Active</span><strong>{{ $activePartners }}</strong></div>
                        <div class="bp-side-row"><span>Inactive</span><strong>{{ $inactivePartners }}</strong></div>
                        <div class="bp-side-row"><span>Clients</span><strong>{{ $totalClients }}</strong></div>
                    </div>
                </div>
            </section>

            <section class="bp-card bp-side-card">
                <h3 class="bp-side-title">Top Partners by Rentals</h3>
                @forelse($topRentalPartners as $partner)
                    <div class="bp-side-row"><span>{{ $partner->displayName() }}</span><strong>{{ $partner->rentals_count }}</strong></div>
                @empty
                    <div class="bp-muted">No rental-linked partners yet.</div>
                @endforelse
            </section>

            <section class="bp-card bp-side-card">
                <h3 class="bp-side-title">Recent Activity</h3>
                @forelse($recentPartners as $partner)
                    <div class="bp-side-row"><span>{{ $partner->displayName() }}<br><small class="bp-muted">Partner updated</small></span><strong>{{ optional($partner->updated_at)->diffForHumans() }}</strong></div>
                @empty
                    <div class="bp-muted">No recent partner activity.</div>
                @endforelse
            </section>

            <section class="bp-card bp-side-card">
                <h3 class="bp-side-title">Need help managing partners?</h3>
                <p class="bp-muted" style="margin:0 0 10px;">Partners are billed contacts. Actual clients receive delivery and service.</p>
                <a class="bp-btn" href="{{ route('business-partners.create') }}">Add Partner</a>
            </section>
        </aside>
    </div>
</div>
<script>
    document.addEventListener('click', function (event) {
        document.querySelectorAll('.bp-more[open]').forEach(function (menu) {
            if (!menu.contains(event.target)) {
                menu.removeAttribute('open');
            }
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;
        document.querySelectorAll('.bp-more[open]').forEach(function (menu) {
            menu.removeAttribute('open');
        });
    });

    document.addEventListener('toggle', function (event) {
        const current = event.target;
        if (!current.matches || !current.matches('.bp-more[open]')) return;
        document.querySelectorAll('.bp-more[open]').forEach(function (menu) {
            if (menu !== current) {
                menu.removeAttribute('open');
            }
        });
    }, true);
</script>
@endsection
