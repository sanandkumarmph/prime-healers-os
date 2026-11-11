@extends('layouts.app')

@section('breadcrumbs')
    <span class="sr-only">Business Partners</span>
@endsection

@section('content')
@php
    $money = fn ($amount) => 'Rs. ' . number_format((float) $amount, 2);
    $queryFor = fn (array $overrides = []) => array_filter(array_merge([
        'search' => $search, 'status' => $status, 'city' => $city, 'partner_type' => $partnerType,
    ], $overrides), fn ($value) => filled($value));
    $filters = [
        'All' => ['status' => '', 'partner_type' => ''],
        'GST' => ['partner_type' => 'gst_registered'],
        'General' => ['partner_type' => 'general'],
        'Active' => ['status' => 'active'],
        'Inactive' => ['status' => 'inactive'],
    ];
    $activeFilters = array_filter([
        'search' => filled($search) ? 'Search: ' . $search : null,
        'status' => filled($status) ? 'Status: ' . ucfirst($status) : null,
        'city' => filled($city) ? 'City: ' . $city : null,
        'partner_type' => filled($partnerType) ? ($partnerType === 'gst_registered' ? 'GST registered' : 'General') : null,
    ]);
    $partnerRows = $businessPartners->getCollection();
    $inactivePartners = max(0, (int) $totalPartners - (int) $activePartners);
    $activePercent = $totalPartners ? round(($activePartners / $totalPartners) * 100) : 0;
    $topRentalPartners = $partnerRows->sortByDesc('rentals_count')->take(5);
    $recentPartners = $partnerRows->sortByDesc('updated_at')->take(4);
@endphp

<style>
    .bp-directory {
        --bp-ink: #172944;
        --bp-muted: #52657f;
        --bp-line: #dce5ef;
        --bp-soft: #f4f8fc;
        --bp-blue: #174f8e;
        display: grid;
        gap: 10px;
        width: 100%;
        max-width: 1460px;
        min-width: 0;
        margin-inline: auto;
        color: var(--bp-ink);
    }
    .bp-directory .bp-header {
        display: grid;
        grid-template: "title search actions" / auto minmax(0, 1fr) auto;
        align-items: center;
        gap: 10px 14px;
    }
    .bp-header h1 { grid-area: title; }
    .bp-directory :is(.bp-search, .bp-header-actions, .bp-filter-actions, .bp-quick-filters, .bp-filter-chips) {
        display: flex;
        align-items: center;
        gap: 7px;
        min-width: 0;
    }
    .bp-search { grid-area: search; }
    .bp-search label { flex: 1; min-width: 0; }
    .bp-header-actions { grid-area: actions; justify-content: flex-end; }
    .bp-directory :is(.bp-search input, .bp-filter-form input, .bp-filter-form select) {
        width: 100%;
        min-width: 0;
        min-height: 38px;
        padding: 7px 10px;
        border: 1px solid #becde0;
        border-radius: 8px;
        background: #fff;
        color: var(--bp-ink);
        font-size: 13px;
    }
    .bp-directory .bp-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 5px;
        min-height: 36px;
        padding: 7px 10px;
        border: 1px solid #c7d6e6;
        border-radius: 8px;
        background: #fff;
        color: var(--bp-blue);
        font-size: 13px;
        font-weight: 650;
        line-height: 1.2;
        text-decoration: none;
        white-space: nowrap;
        cursor: pointer;
    }
    .bp-directory .bp-btn:hover { background: var(--bp-soft); }
    .bp-directory .bp-btn-primary { border-color: #0f75bd; background: #0f75bd; color: #fff; }
    .bp-directory .bp-btn-primary:hover { background: #0b5e99; }
    .bp-directory :focus-visible { outline: 2px solid #2361ad; outline-offset: 2px; }
    .bp-header-actions .phos-list-filter-toggle { display: flex; gap: 6px; width: auto; }
    .bp-header-actions .phos-list-filter-toggle::after { content: "Filters"; }
    .bp-add-short { display: none; }
    .bp-filter-form { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px 12px; }
    .bp-filter-form > label { display: grid; gap: 4px; font-size: 12px; font-weight: 650; }
    .bp-filter-actions { grid-column: 1 / -1; }
    .bp-directory :is(.bp-summary, .bp-list, .bp-insights) {
        min-width: 0;
        border: 1px solid var(--bp-line);
        border-radius: 12px;
        background: #fff;
    }
    .bp-directory .bp-summary {
        display: grid;
        grid-template-columns: repeat(5, minmax(0, 1fr));
        margin: 0;
        background: var(--bp-soft);
    }
    .bp-summary > div { min-width: 0; padding: 8px 11px; }
    .bp-summary > div + div { border-inline-start: 1px solid var(--bp-line); }
    .bp-summary dt { color: var(--bp-muted); font-size: 11px; font-weight: 650; }
    .bp-summary dd { margin: 1px 0 0; font-size: 15px; font-weight: 750; overflow-wrap: anywhere; font-variant-numeric: tabular-nums; }
    .bp-summary small { margin-inline-start: 5px; color: var(--bp-muted); font-size: 11px; font-weight: 500; }
    .bp-list-toolbar { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 7px 12px; padding: 8px 11px; }
    .bp-result-count { color: var(--bp-ink); font-size: 13px; }
    .bp-quick-filters, .bp-filter-chips { flex-wrap: wrap; }
    .bp-directory :is(.bp-quick-filters, .bp-filter-chips) a {
        padding: 3px 6px;
        border-radius: 6px;
        color: var(--bp-blue);
        font-size: 12px;
        font-weight: 650;
        text-decoration: none;
    }
    .bp-quick-filters a.is-active, .bp-filter-chips a { background: #eaf3fc; }
    .bp-directory :is(.bp-quick-filters, .bp-filter-chips) a:hover { text-decoration: underline; }
    .bp-directory .bp-filter-chips { flex-basis: 100%; }
    .bp-table { width: 100%; table-layout: fixed; border-collapse: separate; border-spacing: 0; }
    body.phos-list-page .bp-directory .bp-table :is(th, td) {
        padding: 8px 10px !important;
        border-top: 1px solid var(--bp-line);
        text-align: left;
        vertical-align: top;
    }
    .bp-table thead th { background: var(--bp-soft); }
    .bp-table .bp-location-col { width: 16%; }
    .bp-table .bp-activity-col { width: 23%; }
    .bp-table .bp-billing-col { width: 15%; }
    .bp-table .bp-updated-col { width: 12%; }
    .bp-table .bp-actions-col { width: 64px; }
    .bp-table td { overflow-wrap: anywhere; }
    .bp-table tbody tr:hover { background: #f7fbff; }
    .bp-identity { display: flex; align-items: flex-start; gap: 8px; }
    .bp-avatar {
        display: grid;
        place-items: center;
        flex: 0 0 28px;
        height: 28px;
        border-radius: 8px;
        background: #eaf3fc;
        color: var(--bp-blue);
        font-size: 11px;
        font-weight: 750;
    }
    .bp-record-name { display: block; color: var(--bp-blue); font-size: 13px; font-weight: 750; text-decoration: none; }
    .bp-directory .bp-link { color: var(--bp-blue); font-weight: 650; text-decoration: none; }
    .bp-directory :is(.bp-record-name, .bp-link):hover { text-decoration: underline; }
    .bp-meta { color: var(--bp-muted); font-size: 11px; }
    .bp-identity .bp-meta { display: block; }
    .bp-labels { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 3px; }
    .bp-badge { display: inline-block; padding: 1px 5px; border-radius: 5px; background: #eaf3fc; color: var(--bp-blue); font-size: 10px; white-space: nowrap; }
    .bp-badge.is-active { background: #ecf5ef; color: #206043; }
    .bp-badge.is-inactive { background: #f1f4f8; color: var(--bp-muted); }
    .bp-clients { margin-top: 4px; }
    .bp-clients summary { width: fit-content; color: var(--bp-blue); font-size: 11px; font-weight: 650; cursor: pointer; }
    .bp-client-preview { display: grid; gap: 5px; margin-top: 6px; }
    .bp-client {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 4px 6px;
        padding: 6px;
        border-radius: 6px;
        background: var(--bp-soft);
        font-size: 11px;
    }
    .bp-client .bp-meta { display: block; }
    .bp-money { font-size: 12px; font-weight: 750; font-variant-numeric: tabular-nums; }
    .bp-money.is-due { color: #a52230; }
    .bp-more { position: relative; margin-inline-start: auto; width: fit-content; }
    .bp-more summary {
        display: grid;
        place-items: center;
        width: 32px;
        height: 32px;
        border: 1px solid var(--bp-line);
        border-radius: 7px;
        background: #fff;
        color: var(--bp-blue);
        font-size: 17px;
        line-height: 1;
        list-style: none;
        cursor: pointer;
    }
    .bp-more summary::-webkit-details-marker { display: none; }
    .bp-more[open] summary, .bp-more summary:hover { background: #eaf3fc; }
    .bp-table tr:has(.bp-more[open]) { position: relative; z-index: 60; }
    .bp-menu {
        position: absolute;
        z-index: 1;
        inset-inline-end: 0;
        top: calc(100% + 4px);
        display: grid;
        gap: 2px;
        width: 160px;
        padding: 5px;
        border: 1px solid var(--bp-line);
        border-radius: 9px;
        background: #fff;
        box-shadow: 0 10px 24px rgb(23 41 68 / 15%);
    }
    .bp-more.opens-up .bp-menu { top: auto; bottom: calc(100% + 4px); }
    .bp-menu a {
        display: flex;
        align-items: center;
        min-height: 34px;
        padding: 6px 8px;
        border-radius: 6px;
        color: var(--bp-ink);
        font-size: 12px;
        text-decoration: none;
    }
    .bp-menu a:hover { background: var(--bp-soft); }
    .bp-pagination { padding: 9px 11px; border-top: 1px solid var(--bp-line); }
    .bp-empty { padding: 12px 8px; color: var(--bp-muted); text-align: center; }
    .bp-insights > summary { padding: 10px 12px; color: var(--bp-blue); font-size: 13px; font-weight: 650; cursor: pointer; }
    .bp-insight-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); padding: 0 12px 10px; gap: 12px; }
    .bp-insight-grid h2 { margin: 0; font-size: 13px; font-weight: 750; }
    .bp-insight-grid ul { list-style: none; margin: 5px 0 0; padding: 0; }
    .bp-insight-grid li {
        display: flex;
        align-items: baseline;
        justify-content: space-between;
        gap: 6px;
        padding: 4px 0;
        border-top: 1px solid var(--bp-line);
        font-size: 11px;
        overflow-wrap: anywhere;
    }
    .bp-insight-grid li strong { flex: none; }
    .bp-insight-help { display: flex; align-items: center; flex-wrap: wrap; gap: 6px 10px; margin: 0 12px; padding: 7px 0; border-top: 1px solid var(--bp-line); }
    .bp-insight-help p { margin: 0; font-size: 11px; color: var(--bp-muted); }
    @media (max-width: 1080px) {
        .bp-directory .bp-header { grid-template: "title actions" "search search" / minmax(0, 1fr) auto; }
    }
    @media (max-width: 940px) {
        .bp-directory { gap: 8px; }
        .bp-table, .bp-table tbody { display: block; }
        .bp-table thead { position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); }
        .bp-table .bp-row {
            display: grid;
            grid-template: "identity identity actions" "location updated updated" "activity billing billing"
                / minmax(0, 1.6fr) minmax(0, 1fr) auto;
            align-items: start;
            gap: 7px 12px;
            padding: 9px 11px;
            border-top: 1px solid var(--bp-line);
        }
        body.phos-list-page .bp-directory .bp-table .bp-row td { width: auto; min-width: 0; padding: 0 !important; border: 0; }
        .bp-identity-col { grid-area: identity; }
        .bp-location-col { grid-area: location; }
        .bp-activity-col { grid-area: activity; }
        .bp-billing-col { grid-area: billing; }
        .bp-updated-col { grid-area: updated; }
        .bp-actions-col { grid-area: actions; }
        .bp-more summary { width: 38px; height: 38px; }
    }
    @media (max-width: 640px) {
        .bp-directory .bp-header { gap: 8px; }
        .bp-header-actions .phos-list-filter-toggle::after { content: none; }
        .bp-add-full { display: none; }
        .bp-add-short { display: inline; }
        .bp-directory .bp-summary { grid-template-columns: repeat(6, minmax(0, 1fr)); }
        .bp-summary > div { grid-column: span 2; padding: 5px 8px; }
        .bp-summary > div:nth-child(4) { border-inline-start: 0; }
        .bp-summary > div:nth-child(n+4) { grid-column: span 3; border-top: 1px solid var(--bp-line); }
        .bp-summary dd { font-size: 13px; }
        .bp-summary small { margin-inline-start: 3px; }
        .bp-insight-grid { grid-template-columns: 1fr; }
    }
</style>

<div class="bp-directory" data-phos-compact-page="business-partners" data-business-partner-directory>
    <header class="bp-header phos-page-header">
        <h1>Business Partners</h1>
        <form method="GET" action="{{ route('business-partners.index') }}" class="bp-search" data-business-partner-search role="search">
            @foreach($queryFor(['search' => '']) as $key => $value)
                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
            @endforeach
            <label>
                <span class="sr-only">Search by partner name, contact, phone, email, city, or state</span>
                <input type="search" name="search" value="{{ $search }}" placeholder="Name, contact or city">
            </label>
            <button type="submit" class="bp-btn">Search</button>
        </form>
        <div class="bp-header-actions">
            @if(Route::has('business-partners.export'))
                <a href="{{ route('business-partners.export', request()->query()) }}" class="bp-btn" title="Export partners">Export</a>
            @endif
            @if(Route::has('import.index'))
                <a href="{{ route('import.index') }}" class="bp-btn" title="Import partners">Import Partners</a>
            @endif
            <x-phos-list-filter id="business-partners-list-filters" label="Filter business partners" :active="$activeFilters !== []">
                <form method="GET" action="{{ route('business-partners.index') }}" class="bp-filter-form">
                    <label for="bp-search">Search
                        <input id="bp-search" type="search" name="search" value="{{ $search }}">
                    </label>
                    <label for="bp-type">Partner type
                        <select id="bp-type" name="partner_type">
                            <option value="">All types</option>
                            <option value="gst_registered" @selected($partnerType === 'gst_registered')>GST registered</option>
                            <option value="general" @selected($partnerType === 'general')>General</option>
                        </select>
                    </label>
                    <label for="bp-status">Status
                        <select id="bp-status" name="status">
                            <option value="">All statuses</option>
                            <option value="active" @selected($status === 'active')>Active</option>
                            <option value="inactive" @selected($status === 'inactive')>Inactive</option>
                        </select>
                    </label>
                    <label for="bp-city">City
                        <select id="bp-city" name="city">
                            <option value="">All cities</option>
                            @foreach($cityOptions as $cityOption)
                                <option value="{{ $cityOption }}" @selected($city === $cityOption)>{{ $cityOption }}</option>
                            @endforeach
                        </select>
                    </label>
                    <div class="bp-filter-actions">
                        <button type="submit" class="bp-btn bp-btn-primary">Apply filters</button>
                        <a href="{{ route('business-partners.index') }}" class="bp-btn">Clear</a>
                    </div>
                </form>
            </x-phos-list-filter>
            @can('create', App\Models\BusinessPartner::class)
                <a href="{{ route('business-partners.create') }}" class="bp-btn bp-btn-primary" aria-label="Add Partner"><span class="bp-add-full">Add Partner</span><span class="bp-add-short" aria-hidden="true">Add</span></a>
            @endcan
        </div>
    </header>

    <dl class="bp-summary" aria-label="Business partner summary" data-business-partner-summary>
        <div><dt>Partners</dt><dd>{{ $totalPartners }} <small>{{ $activePartners }} active</small></dd></div>
        <div><dt>Actual clients</dt><dd>{{ $totalClients }} <small>{{ $activeClients }} active</small></dd></div>
        <div><dt>Linked rentals</dt><dd>{{ $totalRentals }}</dd></div>
        <div><dt>Outstanding balance</dt><dd>{{ $money($totalOutstandingAmount) }}</dd></div>
        <div><dt>Partner invoices</dt><dd>{{ $totalPartnerInvoices }}</dd></div>
    </dl>

    <section class="bp-list" aria-labelledby="bp-result-count">
        <div class="bp-list-toolbar">
            <strong class="bp-result-count" id="bp-result-count">
                @if($businessPartners->count())
                    {{ $businessPartners->firstItem() }}–{{ $businessPartners->lastItem() }} of {{ $businessPartners->total() }} partners
                @else
                    {{ $businessPartners->total() }} partners
                @endif
            </strong>
            <nav class="bp-quick-filters" aria-label="Quick partner filters">
                @foreach($filters as $label => $values)
                    @php
                        $current = collect($values)->every(fn ($value, $key) => ($key === 'status' ? $status : $partnerType) === $value);
                    @endphp
                    <a href="{{ route('business-partners.index', $queryFor($values)) }}" @if($current) class="is-active" aria-label="{{ $label }}, selected" @endif>{{ $label }}</a>
                @endforeach
            </nav>
            @if($activeFilters)
                <div class="bp-filter-chips" aria-label="Active filters">
                    @foreach($activeFilters as $key => $label)
                        <a href="{{ route('business-partners.index', $queryFor([$key => ''])) }}" aria-label="Remove {{ $label }}">{{ $label }} <span aria-hidden="true">×</span></a>
                    @endforeach
                    <a href="{{ route('business-partners.index') }}">Clear all</a>
                </div>
            @endif
        </div>

        <table class="bp-table" role="table" aria-label="Business partner list">
            <thead role="rowgroup">
                <tr role="row">
                    <th class="bp-identity-col" role="columnheader" scope="col">Partner &amp; contact</th>
                    <th class="bp-location-col" role="columnheader" scope="col">Location</th>
                    <th class="bp-activity-col" role="columnheader" scope="col">Clients &amp; activity</th>
                    <th class="bp-billing-col" role="columnheader" scope="col">Outstanding</th>
                    <th class="bp-updated-col" role="columnheader" scope="col">Last activity</th>
                    <th class="bp-actions-col" role="columnheader" scope="col">Actions</th>
                </tr>
            </thead>
            <tbody role="rowgroup">
                @forelse($businessPartners as $partner)
                    @php
                        $partnerName = $partner->displayName();
                        $partnerUrl = route('business-partners.show', $partner);
                        $initials = collect(preg_split('/\s+/', trim($partnerName)))->filter()->take(2)
                            ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('') ?: 'BP';
                        $clientsPreview = $partner->partnerClients->take(3);
                    @endphp
                    <tr class="bp-row" role="row" data-business-partner-row>
                        <td class="bp-identity-col" role="cell">
                            <div class="bp-identity">
                                <span class="bp-avatar" aria-hidden="true">{{ $initials }}</span>
                                <div>
                                    <a href="{{ $partnerUrl }}" class="bp-record-name" data-ph-action="view">{{ $partnerName }}</a>
                                    @if($partner->contact_person)<span class="bp-meta">{{ $partner->contact_person }}</span>@endif
                                    <span class="bp-meta">
                                        {{ $partner->phone ?: 'No phone' }}
                                        @if($partner->email)
                                            <span aria-hidden="true">&middot;</span>
                                            <a href="mailto:{{ $partner->email }}" class="bp-link" title="{{ $partner->email }}">Email</a>
                                        @endif
                                    </span>
                                    <div class="bp-labels">
                                        <span class="bp-badge {{ $partner->status === 'inactive' ? 'is-inactive' : 'is-active' }}">{{ ucfirst($partner->status ?: 'active') }}</span>
                                        <span class="bp-badge">{{ $partner->gst_registered ? 'GST Partner' : 'General' }}</span>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td class="bp-location-col" role="cell">
                            <div>{{ collect([$partner->city, $partner->state])->filter()->implode(', ') ?: 'Location not set' }}</div>
                            @if($partner->pincode)<div class="bp-meta">{{ $partner->pincode }}</div>@endif
                        </td>
                        <td class="bp-activity-col" role="cell">
                            <div class="bp-meta">{{ $partner->rentals_count }} rentals <span aria-hidden="true">&middot;</span> {{ $partner->sales_count }} sales</div>
                            <details class="bp-clients">
                                <summary>{{ $partner->partner_clients_count }} actual {{ Str::plural('client', (int) $partner->partner_clients_count) }}</summary>
                                <div class="bp-client-preview">
                                    @forelse($clientsPreview as $client)
                                        <div class="bp-client">
                                            <div><strong>{{ $client->displayName() }}</strong><span class="bp-meta">{{ $client->primaryPhone() ?: 'No phone' }}</span></div>
                                            <span class="bp-badge {{ $client->status === 'inactive' ? 'is-inactive' : 'is-active' }}">{{ ucfirst($client->status ?: 'active') }}</span>
                                        </div>
                                    @empty
                                        <span class="bp-meta">No actual clients linked.</span>
                                    @endforelse
                                    @if($partner->partner_clients_count > $clientsPreview->count())
                                        <a class="bp-link" href="{{ $partnerUrl }}#actual-clients">View all {{ $partner->partner_clients_count }}</a>
                                    @endif
                                </div>
                            </details>
                        </td>
                        <td class="bp-billing-col" role="cell">
                            <div class="bp-money @if($partner->outstanding_amount > 0) is-due @endif">{{ $money($partner->outstanding_amount ?? 0) }}</div>
                            <div class="bp-meta">{{ $partner->linked_invoices_count }} {{ Str::plural('invoice', (int) $partner->linked_invoices_count) }}</div>
                            @if((int) $partner->linked_invoices_count > 0)
                                <a class="bp-link" href="{{ $partnerUrl }}">View invoices</a>
                            @endif
                        </td>
                        <td class="bp-updated-col" role="cell">
                            <span class="bp-meta">Updated {{ $partner->updated_at?->diffForHumans() ?: '—' }}</span>
                        </td>
                        <td class="bp-actions-col" role="cell">
                            <details class="bp-more" data-business-partner-actions>
                                <summary aria-label="More actions for {{ $partnerName }}">⋯</summary>
                                <div class="bp-menu">
                                    <a href="{{ $partnerUrl }}" data-ph-action="view">Open profile</a>
                                    @can('update', $partner)
                                        <a href="{{ route('business-partners.edit', $partner) }}" data-ph-action="edit">Edit partner</a>
                                    @endcan
                                    @can('create', App\Models\PartnerClient::class)
                                        <a href="{{ route('business-partners.clients.create', $partner) }}">Add actual client</a>
                                    @endcan
                                </div>
                            </details>
                        </td>
                    </tr>
                @empty
                    <tr role="row"><td colspan="6" role="cell"><p class="bp-empty">No business partners found.</p></td></tr>
                @endforelse
            </tbody>
        </table>
        @if($businessPartners->hasPages())
            <div class="bp-pagination">{{ $businessPartners->links() }}</div>
        @endif
    </section>

    <details class="bp-insights">
        <summary>Partner insights</summary>
        <div class="bp-insight-grid">
            <section>
                <h2>Partner health</h2>
                <ul>
                    <li><span>Active share</span><strong>{{ $activePercent }}%</strong></li>
                    <li><span>Active</span><strong>{{ $activePartners }}</strong></li>
                    <li><span>Inactive</span><strong>{{ $inactivePartners }}</strong></li>
                    <li><span>Clients</span><strong>{{ $totalClients }}</strong></li>
                </ul>
            </section>
            <section>
                <h2>Top partners by rentals</h2>
                <ul>
                    @forelse($topRentalPartners as $partner)
                        <li><span>{{ $partner->displayName() }}</span><strong>{{ $partner->rentals_count }}</strong></li>
                    @empty
                        <li>No rental-linked partners yet.</li>
                    @endforelse
                </ul>
            </section>
            <section>
                <h2>Recent activity</h2>
                <ul>
                    @forelse($recentPartners as $partner)
                        <li><span>{{ $partner->displayName() }}<br><small class="bp-meta">Partner updated</small></span><strong>{{ $partner->updated_at?->diffForHumans() }}</strong></li>
                    @empty
                        <li>No recent partner activity.</li>
                    @endforelse
                </ul>
            </section>
        </div>
        <div class="bp-insight-help">
            <p>Partners are billed contacts. Actual clients receive delivery and service.</p>
            @can('create', App\Models\BusinessPartner::class)
                <a class="bp-link" href="{{ route('business-partners.create') }}">Add Partner</a>
            @endcan
        </div>
    </details>
</div>

<script>
    (() => {
        const directory = document.querySelector('[data-business-partner-directory]');
        const menus = [...directory.querySelectorAll('[data-business-partner-actions]')];

        function closeMenus(except) {
            menus.forEach(menu => {
                if (menu.open && menu !== except) menu.open = false;
            });
        }

        menus.forEach(menu => {
            menu.addEventListener('toggle', () => {
                menu.classList.remove('opens-up');
                if (!menu.open) return;

                closeMenus(menu);
                const summary = menu.querySelector('summary').getBoundingClientRect();
                const viewportHeight = window.visualViewport?.height ?? window.innerHeight;
                const below = viewportHeight - summary.bottom;
                const above = summary.top;
                if (below < menu.querySelector('.bp-menu').offsetHeight + 8 && above > below) {
                    menu.classList.add('opens-up');
                }
            });
        });

        document.addEventListener('click', event => {
            if (!event.target.closest('[data-business-partner-actions]')) closeMenus();
        });

        document.addEventListener('keydown', event => {
            if (event.key !== 'Escape') return;
            const openMenu = menus.find(menu => menu.open);
            if (!openMenu) return;

            openMenu.open = false;
            openMenu.querySelector('summary').focus();
        });
    })();
</script>
@endsection
