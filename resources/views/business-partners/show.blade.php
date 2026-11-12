@extends('layouts.app')

@section('content')
@php
    $currentUser = auth()->user();
    $canAddClient = $currentUser?->canAccessModule('customers', 'create') ?? false;
    $canEditPartner = $currentUser?->can('update', $businessPartner) ?? false;
    $canCreateRentals = $currentUser?->canAccessModule('rentals', 'create') ?? false;
    $canCreateSales = $currentUser?->canAccessModule('sales', 'create') ?? false;
    $partnerName = $businessPartner->displayName();
    $partnerStatus = ucfirst($businessPartner->status ?: 'active');
    $partnerPhone = $businessPartner->preferredReminderNumber() ?: $businessPartner->phone;
    $partnerCallHref = $partnerPhone ? 'tel:' . preg_replace('/\D+/', '', $partnerPhone) : null;
    $partnerWhatsappHref = \App\Support\WhatsAppHelper::chatUrl(
        \App\Support\WhatsAppHelper::normalizeNumber($businessPartner->whatsapp ?: $partnerPhone),
        $partnerPhone ? "Hello {$partnerName}, this is a quick update from Prime Healers." : null
    );
    $partnerMapUrl = $businessPartner->openMapUrl();
    $partnerUrl = route('business-partners.show', $businessPartner);
    $actualClientsUrl = $partnerUrl . '#actual-clients';
    $addClientUrl = route('business-partners.clients.create', $businessPartner);
    $orderContext = ['customer_type' => 'business_partner', 'business_partner_id' => $businessPartner->id];
    $partnerLocation = collect([$businessPartner->city, $businessPartner->state])->filter()->implode(', ');
    $partnerInitials = collect(preg_split('/\s+/', trim($partnerName)))->filter()->take(2)
        ->map(fn ($word) => mb_strtoupper(mb_substr($word, 0, 1)))->implode('');
    $partnerBillingAddress = collect([
        $businessPartner->billingAddressLine(),
        collect([$businessPartner->billingCityValue(), $businessPartner->billingStateValue(), $businessPartner->billingPincodeValue()])
            ->filter()->implode(', '),
    ])->filter()->implode(' · ');

    $partnerQuickActions = [];
    if ($canAddClient) {
        $partnerQuickActions[] = ['label' => 'Add Actual Client', 'href' => $addClientUrl];
    }
    if ($partnerCallHref) {
        $partnerQuickActions[] = ['label' => 'Call', 'href' => $partnerCallHref];
    }
    if ($partnerWhatsappHref) {
        $partnerQuickActions[] = ['label' => 'WhatsApp', 'href' => $partnerWhatsappHref, 'target' => '_blank', 'rel' => 'noopener', 'accent' => true];
    }
    if ($canCreateRentals) {
        $partnerQuickActions[] = ['label' => 'New Rental', 'href' => route('rentals.create', $orderContext)];
    }
    if ($canCreateSales) {
        $partnerQuickActions[] = ['label' => 'New Sale', 'href' => route('sales.create', $orderContext)];
    }

    $partnerMoreActions = [];
    if ($canEditPartner) {
        $partnerMoreActions[] = ['label' => 'Add Note', 'href' => '#business-partner-timeline-note'];
    }
    $partnerMoreActions[] = ['label' => 'View Timeline', 'href' => '#business-partner-timeline'];
    if ($currentUser && !$currentUser->isFinance()) {
        $firstClient = $businessPartner->partnerClients()->where('organization_id', $businessPartner->organization_id)->first();
        $partnerMoreActions[] = [
            'type' => 'button',
            'label' => 'Add Follow-up',
            'attributes' => [
                'data-open-follow-up-modal' => true,
                'data-follow-up-context' => json_encode([
                    'business_partner_id' => $businessPartner->id,
                    'reference' => 'Business Partner #' . $businessPartner->id,
                    'reminder_contact' => collect([$partnerName, $partnerPhone])->filter()->implode(' | '),
                    'service_contact' => $firstClient ? collect([$firstClient->displayName(), $firstClient->primaryPhone()])->filter()->implode(' | ') : null,
                    'service_address' => $firstClient?->address,
                ]),
                'data-follow-up-type' => \App\Models\FollowUp::TYPE_PAYMENT,
                'data-follow-up-priority' => \App\Models\FollowUp::PRIORITY_MEDIUM,
            ],
        ];
    }
    if ($currentUser?->canAccessModule('rentals', 'read')) {
        $partnerMoreActions[] = ['label' => 'View Rentals', 'href' => route('rentals.index', ['business_partner_id' => $businessPartner->id])];
    }
    if ($currentUser?->canAccessModule('invoices', 'read')) {
        $partnerMoreActions[] = ['label' => 'View Invoices', 'href' => route('invoices.index', ['business_partner_id' => $businessPartner->id])];
    }
    if ($currentUser?->canViewRecordFinance()) {
        $partnerMoreActions[] = ['label' => 'Ledger', 'href' => route('ledger.index', ['business_partner_id' => $businessPartner->id])];
    }
    if ($canEditPartner) {
        $partnerMoreActions[] = ['label' => 'Edit Partner', 'href' => route('business-partners.edit', $businessPartner)];
    }
    if ($partnerMapUrl) {
        $partnerMoreActions[] = ['label' => 'Open Map', 'href' => $partnerMapUrl, 'target' => '_blank', 'rel' => 'noopener'];
    }
@endphp

<style>
    .bp-detail {
        --bp-detail-ink: #172944;
        --bp-detail-muted: #52657f;
        --bp-detail-line: #dce5f1;
        --bp-detail-blue: #234fb8;
        --bp-detail-soft: #eef4ff;
        display: grid;
        grid-template-columns: minmax(0, 1fr);
        gap: 12px;
        min-width: 0;
        color: var(--bp-detail-ink);
    }
    .bp-detail-card {
        min-width: 0;
        border: 1px solid var(--bp-detail-line);
        border-radius: 12px;
        background: #fff;
    }
    .bp-detail-header { padding: 14px 16px; }
    .bp-detail a.bp-detail-link {
        color: var(--bp-detail-blue);
        font-weight: 650;
        text-decoration: none;
        overflow-wrap: anywhere;
    }
    .bp-detail a.bp-detail-link:hover { text-decoration: underline; }
    .bp-detail :focus-visible { outline: 2px solid var(--bp-detail-blue); outline-offset: 2px; }
    .bp-detail-heading { display: flex; align-items: center; gap: 12px; margin: 10px 0 12px; }
    .bp-detail-heading > div { min-width: 0; }
    .bp-detail-heading h1 {
        margin: 0;
        color: var(--bp-detail-ink);
        font-size: clamp(20px, 3vw, 27px);
        line-height: 1.2;
        font-weight: 800;
        overflow-wrap: anywhere;
    }
    .bp-detail-initials {
        display: grid;
        flex: 0 0 44px;
        height: 44px;
        place-items: center;
        border: 1px solid #d3e0ff;
        border-radius: 10px;
        background: var(--bp-detail-soft);
        color: var(--bp-detail-blue);
        font-weight: 800;
    }
    .bp-detail-meta {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 4px 8px;
        margin-top: 4px;
        font-size: 13px;
        color: var(--bp-detail-muted);
    }
    .bp-detail-status {
        display: inline-block;
        width: fit-content;
        padding: 2px 7px;
        border-radius: 5px;
        background: #f0f3f7;
        color: #455771;
        font-size: 11px;
        font-weight: 700;
    }
    .bp-detail-status.is-active { background: #e8f5ee; color: #1a653d; }
    .bp-detail .ph-quick-actions {
        position: relative;
        z-index: 6;
        display: block;
        padding: 10px 0 0;
        border: 0;
        border-top: 1px solid var(--bp-detail-line);
        border-radius: 0;
        background: transparent;
        box-shadow: none;
    }
    .bp-detail .ph-quick-actions-head { position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); }
    .bp-detail .ph-quick-actions-row { gap: 6px; }
    .bp-detail .ph-quick-action-btn { min-height: 36px; padding: 7px 10px; border-radius: 8px; box-shadow: none; }
    .bp-detail .ph-quick-action-btn.is-primary { border-color: var(--bp-detail-blue); background: var(--bp-detail-blue); }
    .bp-detail .ph-quick-action-btn.is-accent { border-color: #b6e6c9; background: #e9f8ee; color: #176637; }
    .bp-detail .ph-quick-action-panel { max-width: calc(100vw - 46px); max-height: min(52vh, 390px); overflow-y: auto; }
    .bp-detail .ph-section-nav-shell { position: sticky; top: 0; z-index: 4; margin: 0; }
    .bp-detail .ph-section-nav { flex-wrap: wrap; gap: 3px; padding: 4px; border-radius: 9px; background: #fff; box-shadow: none; }
    .bp-detail .ph-section-nav-link { border-radius: 6px; padding: 7px 10px; }
    .bp-detail .ph-section-nav-link.is-active { background: var(--bp-detail-soft); color: var(--bp-detail-blue); box-shadow: none; }
    .bp-detail :is(.section-nav-target, #business-partner-timeline, #business-partner-timeline-note) { scroll-margin-top: 58px; }
    .bp-detail-section-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 6px 12px;
        padding: 11px 14px;
        border-bottom: 1px solid var(--bp-detail-line);
    }
    .bp-detail h2, .bp-detail h3 { margin: 0; color: var(--bp-detail-ink); font-weight: 750; }
    .bp-detail h2 { font-size: 16px; }
    .bp-detail h3 { margin-bottom: 7px; font-size: 13px; }
    .bp-detail-profile { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .bp-detail-group { min-width: 0; padding: 12px 14px; }
    .bp-detail-group + .bp-detail-group { border-left: 1px solid var(--bp-detail-line); }
    .bp-detail-data { display: grid; grid-template-columns: minmax(106px, .42fr) minmax(0, 1fr); gap: 5px 10px; margin: 0; }
    .bp-detail-data dt { color: var(--bp-detail-muted); font-size: 12px; }
    .bp-detail-data dd { min-width: 0; margin: 0; font-size: 13px; overflow-wrap: anywhere; }
    .bp-detail-billing { border-top: 1px solid var(--bp-detail-line); }
    .bp-detail-billing > summary {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 11px 14px;
        cursor: pointer;
        list-style: none;
    }
    .bp-detail-billing > summary::-webkit-details-marker, .bp-client-menu > summary::-webkit-details-marker { display: none; }
    .bp-detail-billing > summary::after { content: '+'; margin-left: auto; color: var(--bp-detail-blue); font-size: 17px; }
    .bp-detail-billing[open] > summary::after { content: '−'; }
    .bp-detail-billing[open] > summary { background: var(--bp-detail-soft); }
    .bp-detail-billing > summary span { color: var(--bp-detail-muted); font-size: 12px; }
    .bp-detail-billing .bp-detail-data { padding: 12px 14px; }
    .bp-detail-btn {
        display: inline-flex;
        justify-content: center;
        align-items: center;
        min-height: 36px;
        padding: 6px 10px;
        border: 1px solid #cbd8e9;
        border-radius: 8px;
        background: #fff;
        color: var(--bp-detail-blue);
        font-size: 13px;
        font-weight: 650;
        text-decoration: none;
        cursor: pointer;
    }
    .bp-detail-btn:hover { background: var(--bp-detail-soft); }
    .bp-detail-btn.is-primary { border-color: var(--bp-detail-blue); background: var(--bp-detail-blue); color: #fff; }
    .bp-client-controls { display: grid; grid-template-columns: minmax(0, 2fr) minmax(106px, 1fr) auto; gap: 8px; align-items: end; padding: 11px 14px; }
    .bp-client-controls label { display: grid; min-width: 0; gap: 4px; color: var(--bp-detail-ink); font-size: 12px; font-weight: 650; }
    .bp-client-controls :is(input, select) {
        width: 100%;
        min-width: 0;
        min-height: 36px;
        padding: 6px 9px;
        border: 1px solid #cbd8e9;
        border-radius: 8px;
        background: #fff;
        color: var(--bp-detail-ink);
        font-size: 13px;
    }
    .bp-client-filter-actions { display: flex; flex-wrap: wrap; gap: 6px; }
    .bp-client-count { margin: 0; color: var(--bp-detail-muted); font-size: 12px; }
    .bp-client-table { width: 100%; table-layout: fixed; border-collapse: collapse; font-size: 13px; }
    .bp-client-table :is(th, td) { padding: 9px 12px; border-top: 1px solid var(--bp-detail-line); text-align: left; vertical-align: top; overflow-wrap: anywhere; }
    .bp-client-table th { background: #f4f7fb; color: var(--bp-detail-muted); font-size: 12px; font-weight: 750; }
    .bp-client-table th:nth-child(1) { width: 21%; }
    .bp-client-table th:nth-child(2) { width: 17%; }
    .bp-client-table th:nth-child(3) { width: 31%; }
    .bp-client-table th:nth-child(4) { width: 19%; }
    .bp-client-table th:nth-child(5) { width: 12%; }
    .bp-client-table td small { display: block; color: var(--bp-detail-muted); font-size: 11px; }
    .bp-client-name { display: block; margin-bottom: 3px; font-weight: 750; }
    .bp-client-actions { display: flex; justify-content: flex-end; }
    .bp-client-menu { position: relative; width: max-content; }
    .bp-client-menu > summary { min-width: 40px; list-style: none; font-size: 15px; line-height: 1; }
    .bp-client-menu[open] > summary { background: var(--bp-detail-soft); }
    .bp-client-menu-content {
        position: absolute;
        top: calc(100% + 4px);
        right: 0;
        z-index: 3;
        width: 154px;
        max-width: calc(100vw - 44px);
        max-height: min(48vh, 260px);
        overflow: auto;
        overscroll-behavior: contain;
        padding: 5px;
        border: 1px solid var(--bp-detail-line);
        border-radius: 9px;
        background: #fff;
        box-shadow: 0 10px 24px rgba(23, 41, 68, .15);
    }
    .bp-client-menu.opens-up .bp-client-menu-content { top: auto; bottom: calc(100% + 4px); }
    .bp-client-menu-content :is(a, button) {
        display: flex;
        align-items: center;
        min-height: 34px;
        width: 100%;
        padding: 6px 8px;
        border: 0;
        border-radius: 6px;
        background: #fff;
        color: var(--bp-detail-ink);
        font-size: 13px;
        text-align: left;
        text-decoration: none;
        cursor: pointer;
    }
    .bp-client-menu-content :is(a, button):hover { background: var(--bp-detail-soft); }
    .bp-client-menu-content button { color: #9c2a22; }
    .bp-client-menu-content form { margin: 0; }
    .bp-client-pagination { padding: 10px 14px; border-top: 1px solid var(--bp-detail-line); }
    .bp-detail [data-record-timestamps] { margin: 0 !important; }

    @media (max-width: 850px) {
        .bp-detail .ph-section-nav-shell { position: relative; }
        .bp-client-table, .bp-client-table tbody { display: block; }
        .bp-client-table thead { position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); }
        .bp-client-table tr { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 5px 10px; padding: 10px 14px; border-top: 1px solid var(--bp-detail-line); }
        .bp-client-table td { grid-column: 1 / -1; min-width: 0; padding: 0; border: 0; }
        .bp-client-table :is(.bp-client-identity, .bp-client-actions-cell) { grid-row: 1; }
        .bp-client-table .bp-client-identity { grid-column: 1; }
        .bp-client-table .bp-client-actions-cell { grid-column: 2; }
        .bp-client-table td[data-label]::before { content: attr(data-label); color: var(--bp-detail-muted); font-size: 11px; font-weight: 700; }
    }
    @media (max-width: 640px) {
        .bp-detail-header { padding: 11px 12px; }
        .bp-detail .ph-quick-action-btn { min-height: 40px; }
        .bp-detail .ph-quick-action-menu { position: static; }
        .bp-detail .ph-quick-action-panel { right: 0; }
        .bp-detail-profile { grid-template-columns: minmax(0, 1fr); }
        .bp-detail-group + .bp-detail-group { border-left: 0; border-top: 1px solid var(--bp-detail-line); }
        .bp-client-controls { grid-template-columns: minmax(0, 1fr) auto; }
        .bp-client-controls > label:first-child { grid-column: 1 / -1; }
        .bp-client-controls :is(input, select) { font-size: 16px; }
        .bp-client-menu-content :is(a, button) { min-height: 40px; }
        .bp-detail :is(.section-nav-target, #business-partner-timeline, #business-partner-timeline-note) { scroll-margin-top: 12px; }
    }
</style>

<div class="bp-detail" data-business-partner-details>
    <header class="bp-detail-card bp-detail-header">
        <a href="{{ route('business-partners.index') }}" class="bp-detail-link">&larr; Business Partners</a>
        <div class="bp-detail-heading">
            <span class="bp-detail-initials" aria-hidden="true">{{ $partnerInitials ?: 'BP' }}</span>
            <div>
                <h1>{{ $partnerName }}</h1>
                <div class="bp-detail-meta">
                    <span class="bp-detail-status {{ $partnerStatus === 'Active' ? 'is-active' : '' }}">{{ $partnerStatus }}</span>
                    @if($partnerLocation)
                        <span>{{ $partnerLocation }}</span>
                    @endif
                </div>
            </div>
        </div>
        @include('partials.quick-action-toolbar', [
            'label' => 'Business Partner Quick Actions',
            'actions' => $partnerQuickActions,
            'moreActions' => $partnerMoreActions,
        ])
    </header>

    <x-section-nav label="Business partner page sections" :items="[
        ['id' => 'business-partner-overview-section', 'label' => 'Overview'],
        ['id' => 'actual-clients', 'label' => 'Actual Clients'],
        ['id' => 'business-partner-timeline', 'label' => 'Timeline'],
    ]" />

    <section class="bp-detail-card section-nav-target" id="business-partner-overview-section" aria-labelledby="business-partner-profile-heading">
        <header class="bp-detail-section-head">
            <h2 id="business-partner-profile-heading">Partner Details</h2>
            @if($canEditPartner)
                <a href="{{ route('business-partners.edit', $businessPartner) }}" class="bp-detail-link">Edit Partner</a>
            @endif
        </header>
        <div class="bp-detail-profile">
            <section class="bp-detail-group">
                <h3>Contact</h3>
                <dl class="bp-detail-data">
                    <dt>Contact person</dt>
                    <dd>{{ $businessPartner->contact_person ?: 'Not set' }}</dd>
                    <dt>Phone</dt>
                    <dd>{{ $businessPartner->phone ?: 'Not set' }}</dd>
                    <dt>WhatsApp</dt>
                    <dd>{{ $businessPartner->whatsapp ?: 'Not set' }}</dd>
                    <dt>Email</dt>
                    <dd>
                        @if($businessPartner->email)
                            <a class="bp-detail-link" href="mailto:{{ $businessPartner->email }}">{{ $businessPartner->email }}</a>
                        @else
                            Not set
                        @endif
                    </dd>
                    <dt>Reminder / Payment</dt>
                    <dd>{{ collect([$partnerName, $partnerPhone])->filter()->implode(' | ') }}</dd>
                </dl>
            </section>
            <section class="bp-detail-group">
                <h3>Address</h3>
                <dl class="bp-detail-data">
                    <dt>Street address</dt>
                    <dd>{{ $businessPartner->address ?: 'Not set' }}</dd>
                    <dt>City / State</dt>
                    <dd>{{ $partnerLocation ?: 'Not set' }}</dd>
                    <dt>Pincode</dt>
                    <dd>{{ $businessPartner->pincode ?: 'Not set' }}</dd>
                    <dt>Map / Location</dt>
                    <dd>
                        @if($partnerMapUrl)
                            <a href="{{ $partnerMapUrl }}" target="_blank" rel="noopener" class="bp-detail-link">{{ $businessPartner->location ?: 'Open map' }}</a>
                        @else
                            {{ $businessPartner->location ?: 'Not set' }}
                        @endif
                    </dd>
                </dl>
            </section>
        </div>
        <details class="bp-detail-billing" id="business-partner-billing">
            <summary>
                <strong>GST &amp; billing</strong>
                <span>{{ $businessPartner->gst_registered ? 'GST registered' : 'GST not registered' }}</span>
            </summary>
            <dl class="bp-detail-data">
                <dt>GST Registered</dt>
                <dd>{{ $businessPartner->gst_registered ? 'Yes' : 'No' }}</dd>
                <dt>GSTIN</dt>
                <dd>{{ $businessPartner->gstin ?: 'Not set' }}</dd>
                <dt>Legal Business Name</dt>
                <dd>{{ $businessPartner->legal_name ?: 'Not set' }}</dd>
                <dt>Billing State</dt>
                <dd>{{ $businessPartner->billingStateValue() ?: 'Not set' }}</dd>
                <dt>GST Billing Address</dt>
                <dd>{{ $partnerBillingAddress ?: 'Not set' }}</dd>
            </dl>
        </details>
    </section>

    <section class="bp-detail-card section-nav-target" id="actual-clients" aria-labelledby="business-partner-clients-heading">
        <header class="bp-detail-section-head">
            <div>
                <h2 id="business-partner-clients-heading">Actual Clients</h2>
                <p class="bp-client-count">{{ number_format($clients->total()) }} {{ \Illuminate\Support\Str::plural('client', $clients->total()) }}</p>
            </div>
            @if($canAddClient)
                <a href="{{ $addClientUrl }}" class="bp-detail-btn is-primary">Add Actual Client</a>
            @endif
        </header>

        <form method="GET" action="{{ $actualClientsUrl }}" class="bp-client-controls" data-partner-client-filters>
            <label for="partner-client-search">Search clients
                <input id="partner-client-search" type="search" name="client_search" value="{{ $clientSearch }}" placeholder="Name, phone or location">
            </label>
            <label for="partner-client-status">Status
                <select id="partner-client-status" name="client_status">
                    <option value="">All statuses</option>
                    <option value="active" @selected($clientStatus === 'active')>Active</option>
                    <option value="inactive" @selected($clientStatus === 'inactive')>Inactive</option>
                </select>
            </label>
            <div class="bp-client-filter-actions">
                <button type="submit" class="bp-detail-btn">Search</button>
                <a href="{{ $actualClientsUrl }}" class="bp-detail-btn">Clear</a>
            </div>
        </form>

        <table class="bp-client-table" role="table" aria-label="Actual clients">
            <thead role="rowgroup">
                <tr role="row">
                    <th scope="col" role="columnheader">Actual Client</th>
                    <th scope="col" role="columnheader">Phone</th>
                    <th scope="col" role="columnheader">Address</th>
                    <th scope="col" role="columnheader">Notes</th>
                    <th scope="col" role="columnheader">Actions</th>
                </tr>
            </thead>
            <tbody role="rowgroup">
                @forelse($clients as $client)
                    @php
                        $clientName = $client->displayName();
                        $clientStatusLabel = ucfirst($client->status ?: 'active');
                        $clientPhone = $client->primaryPhone();
                        $clientMapUrl = $client->openMapUrl();
                        $clientLocation = collect([$client->address, collect([$client->city, $client->state, $client->pincode])->filter()->implode(', ')])->filter()->implode(' · ');
                        $canEditClient = $currentUser?->can('update', $client) ?? false;
                        $canDeleteClient = $currentUser?->can('delete', $client) ?? false;
                        $clientOrderContext = $orderContext + ['partner_client_id' => $client->id];
                    @endphp
                    <tr role="row" data-partner-client-row>
                        <td class="bp-client-identity" role="cell">
                            <strong class="bp-client-name">{{ $clientName }}</strong>
                            <span class="bp-detail-status {{ $clientStatusLabel === 'Active' ? 'is-active' : '' }}">{{ $clientStatusLabel }}</span>
                        </td>
                        <td role="cell" data-label="Phone">
                            <div>
                                @if($clientPhone)
                                    <a href="tel:{{ preg_replace('/\D+/', '', $clientPhone) }}" class="bp-detail-link" aria-label="Call {{ $clientName }}">{{ $clientPhone }}</a>
                                @else
                                    Not set
                                @endif
                            </div>
                            @if($client->alternate_phone)
                                <small>Alt: {{ $client->alternate_phone }}</small>
                            @endif
                        </td>
                        <td role="cell" data-label="Address">
                            <div>{{ $clientLocation ?: 'Not set' }}</div>
                            @if($clientMapUrl)
                                <a href="{{ $clientMapUrl }}" target="_blank" rel="noopener" class="bp-detail-link">Open map</a>
                            @endif
                        </td>
                        <td role="cell" data-label="Notes"><div>{{ $client->delivery_notes ?: 'No notes' }}</div></td>
                        <td class="bp-client-actions-cell" role="cell">
                            @if($canCreateRentals || $canCreateSales || $canEditClient || $canDeleteClient)
                                <div class="bp-client-actions">
                                    <details class="bp-client-menu" data-partner-client-menu>
                                        <summary class="bp-detail-btn" aria-label="More actions for {{ $clientName }}">&hellip;</summary>
                                        <div class="bp-client-menu-content">
                                            @if($canCreateRentals)
                                                <a href="{{ route('rentals.create', $clientOrderContext) }}">New Rental</a>
                                            @endif
                                            @if($canCreateSales)
                                                <a href="{{ route('sales.create', $clientOrderContext) }}">New Sale</a>
                                            @endif
                                            @if($canEditClient)
                                                <a href="{{ route('business-partners.clients.edit', [$businessPartner, $client]) }}">Edit</a>
                                            @endif
                                            @if($canDeleteClient)
                                                <form action="{{ route('business-partners.clients.destroy', [$businessPartner, $client]) }}" method="POST" onsubmit="return confirm('Delete this actual client?');">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit">Delete</button>
                                                </form>
                                            @endif
                                        </div>
                                    </details>
                                </div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr role="row"><td colspan="5" role="cell">No actual clients found for this business partner.</td></tr>
                @endforelse
            </tbody>
        </table>
        @if($clients->hasPages())
            <div class="bp-client-pagination">{{ $clients->fragment('actual-clients')->links() }}</div>
        @endif
    </section>

    @include('partials.activity-timeline', [
        'timeline' => $activityTimeline ?? collect(),
        'title' => 'Timeline',
        'subtitle' => 'Partner setup, actual clients, rentals, sales, reminders, and follow-up history.',
        'timelineFilter' => $timelineFilter ?? 'all',
        'timelineRoute' => 'business-partners.show',
        'noteAction' => route('business-partners.notes.store', $businessPartner),
        'canAddNote' => $canEditPartner,
        'noteLabel' => 'Add Note',
        'anchorId' => 'business-partner-timeline',
    ])

    @include('partials.record-timestamps', ['timestampRecord' => $businessPartner, 'timestampFields' => ['created_at' => 'Created', 'updated_at' => 'Last updated']])
</div>

@include('partials.follow-up-modal')

<script>
    (() => {
        const page = document.querySelector('[data-business-partner-details]');
        const menus = [...page.querySelectorAll('[data-partner-client-menu]')];

        function revealSection(id) {
            const target = document.getElementById(id);
            if (!target || !page.contains(target)) return;
            for (let panel = target.closest('details'); panel; panel = panel.parentElement.closest('details')) {
                panel.open = true;
            }
        }

        function closeClientMenus(except = null) {
            menus.forEach(menu => {
                if (menu !== except && menu.open) menu.open = false;
            });
        }

        menus.forEach(menu => {
            menu.addEventListener('toggle', () => {
                menu.classList.remove('opens-up');
                if (!menu.open) return;
                closeClientMenus(menu);
                const summary = menu.querySelector('summary');
                const panel = menu.querySelector('.bp-client-menu-content');
                const bounds = summary.getBoundingClientRect();
                const below = (window.visualViewport?.height ?? window.innerHeight) - bounds.bottom;
                if (below < panel.offsetHeight + 6 && bounds.top > below) menu.classList.add('opens-up');
            });
        });

        page.addEventListener('click', event => {
            const link = event.target.closest('a[href^="#business-partner-"]');
            if (!link) return;
            revealSection(link.hash.slice(1));
            link.closest('[data-quick-action-menu]')?.removeAttribute('open');
        }, true);

        document.addEventListener('click', event => {
            if (!event.target.closest('[data-partner-client-menu]')) closeClientMenus();
        });
        document.addEventListener('keydown', event => {
            if (event.key !== 'Escape') return;
            const openMenu = menus.find(menu => menu.open);
            if (!openMenu) return;
            closeClientMenus();
            openMenu.querySelector('summary').focus();
        });

        window.addEventListener('hashchange', () => revealSection(location.hash.slice(1)));
        revealSection(location.hash.slice(1));
    })();
</script>
@endsection
