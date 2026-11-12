@extends('layouts.app')

@section('content')
@php
    $currentUser = auth()->user();
    $canUpdateCustomers = $currentUser?->canAccessModule('customers', 'update') ?? false;
    $canDeleteCustomers = $currentUser?->canAccessModule('customers', 'delete') ?? false;
    $canCreateRentals = $currentUser?->canAccessModule('rentals', 'create') ?? false;
    $canCreateSales = $currentUser?->canAccessModule('sales', 'create') ?? false;
    $canCreateInvoices = $currentUser?->canAccessModule('invoices', 'create') ?? false;
    $currency = fn ($value) => \App\Support\CurrencyFormatter::format((float) $value);
    $customerWhatsapp = \App\Support\WhatsAppHelper::resolveCustomerNumber($customer);
    $customerName = $customer->displayName();
    $customerType = $customer->normalizedCustomerType();
    $identityLabel = $customerType === 'Business'
        ? ($customer->company_name ?: $customerName)
        : trim(collect([$customer->salutation, $customer->first_name, $customer->last_name])->filter()->implode(' '));
    $mapUrl = $customer->openMapUrl();
    $proofUrl = filled($customer->id_proof_file_path) ? route('customers.id-proof.download', $customer->id) : null;
    $customerFollowUpContextJson = json_encode([
        'customer_id' => $customer->id,
        'reference' => 'Customer #' . $customer->id,
        'reminder_contact' => collect([$customerName, $customer->phone])->filter()->implode(' | '),
        'service_contact' => collect([$customerName, $customer->phone])->filter()->implode(' | '),
        'service_address' => collect([$customer->address, collect([$customer->city, $customer->state, $customer->pincode])->filter()->implode(', ')])->filter()->implode(' | '),
    ]);

    $generalWhatsAppUrl = \App\Support\WhatsAppHelper::chatUrl($customerWhatsapp, $customerWhatsapp ? "Hello {$customerName}, this is a quick update from Prime Healers." : null);

    $latestRental = $customer->rentals->first();
    $latestInvoice = $customer->invoices->first();

    $renewalUrl = $latestRental ? \App\Support\WhatsAppHelper::chatUrl($customerWhatsapp, \App\Support\WhatsAppHelper::rentalRenewalReminder($latestRental)) : null;
    $deliveryUrl = $latestRental ? \App\Support\WhatsAppHelper::chatUrl($customerWhatsapp, \App\Support\WhatsAppHelper::rentalDeliveryConfirmation($latestRental)) : null;
    $pickupUrl = $latestRental ? \App\Support\WhatsAppHelper::chatUrl($customerWhatsapp, \App\Support\WhatsAppHelper::rentalPickupReminder($latestRental)) : null;
    $paymentReminderUrl = $latestInvoice ? \App\Support\WhatsAppHelper::chatUrl($customerWhatsapp, \App\Support\WhatsAppHelper::paymentReminderForInvoice($latestInvoice)) : null;
    $invoiceMessageUrl = $latestInvoice ? \App\Support\WhatsAppHelper::chatUrl($customerWhatsapp, \App\Support\WhatsAppHelper::invoiceMessage($latestInvoice)) : null;

    $deliveryEntries = $customer->rentals
        ->filter(fn ($rental) => $rental->deliveryRecord || $rental->pickupRecord)
        ->take(6);
    $deliveriesSearchHref = \Illuminate\Support\Facades\Route::has('deliveries.index')
        ? route('deliveries.index', ['search' => $customerName])
        : null;
    $paymentsIndexHref = \Illuminate\Support\Facades\Route::has('payments.index')
        ? route('payments.index', ['customer_id' => $customer->id])
        : null;

    $mobilePrimaryActions = collect();
    $mobileMoreActions = collect();

    if ($canCreateRentals) {
        $mobilePrimaryActions->push([
            'type' => 'link',
            'label' => 'Rental',
            'href' => route('rentals.create', ['customer_id' => $customer->id]),
        ]);
    }

    if ($canCreateSales) {
        $mobilePrimaryActions->push([
            'type' => 'link',
            'label' => 'Sale',
            'href' => route('sales.create', ['customer_id' => $customer->id]),
        ]);
    }

    if ($canCreateInvoices) {
        $mobilePrimaryActions->push([
            'type' => 'link',
            'label' => 'Invoice',
            'href' => route('invoices.create', ['customer_id' => $customer->id]),
        ]);
    }

    if ($customer->phone) {
        $mobilePrimaryActions->push([
            'type' => 'link',
            'label' => 'Call',
            'href' => 'tel:' . preg_replace('/\D+/', '', $customer->phone),
        ]);
    }

    if ($canUpdateCustomers) {
        $mobilePrimaryActions->push([
            'type' => 'link',
            'label' => 'Edit',
            'href' => route('customers.edit', $customer),
        ]);
    }

    $mobilePrimaryActions = $mobilePrimaryActions->take(2)->values();
    $usedMobileLabels = $mobilePrimaryActions->pluck('label')->all();

    $pushMoreAction = function (array $action) use (&$mobileMoreActions, $usedMobileLabels): void {
        if (in_array($action['label'], $usedMobileLabels, true)) {
            return;
        }

        $mobileMoreActions->push($action);
    };

    $pushMoreAction([
        'type' => 'link',
        'label' => 'Back to Customers',
        'href' => route('customers.index'),
    ]);

    if ($customer->phone) {
        $pushMoreAction([
            'type' => 'link',
            'label' => 'Call Customer',
            'href' => 'tel:' . preg_replace('/\D+/', '', $customer->phone),
        ]);
    }

    if ($generalWhatsAppUrl) {
        $pushMoreAction([
            'type' => 'link',
            'label' => 'WhatsApp Customer',
            'href' => $generalWhatsAppUrl,
            'target' => '_blank',
            'rel' => 'noopener',
        ]);
    }

    if ($canUpdateCustomers) {
        $pushMoreAction([
            'type' => 'link',
            'label' => 'Edit Customer',
            'href' => route('customers.edit', $customer),
        ]);
    }

    if ($canCreateInvoices) {
        $pushMoreAction([
            'type' => 'link',
            'label' => 'Create Invoice',
            'href' => route('invoices.create', ['customer_id' => $customer->id]),
        ]);
    }

    if ($mapUrl) {
        $pushMoreAction([
            'type' => 'link',
            'label' => 'Open Map',
            'href' => $mapUrl,
            'target' => '_blank',
            'rel' => 'noopener',
        ]);
    }

    if ($canDeleteCustomers) {
        $pushMoreAction([
            'type' => 'form',
            'label' => 'Delete Customer',
            'action' => route('customers.destroy', $customer),
            'method' => 'DELETE',
            'confirm' => 'Delete this customer? This will be blocked if dependencies exist.',
            'danger' => true,
        ]);
    }

    $customerQuickActions = collect();
    if ($customer->phone) {
        $customerQuickActions->push([
            'type' => 'link',
            'label' => 'Call',
            'href' => 'tel:' . preg_replace('/\D+/', '', $customer->phone),
        ]);
    }
    if ($generalWhatsAppUrl) {
        $customerQuickActions->push([
            'type' => 'link',
            'label' => 'WhatsApp',
            'href' => $generalWhatsAppUrl,
            'target' => '_blank',
            'rel' => 'noopener',
            'accent' => true,
        ]);
    }
    if ($canCreateRentals) {
        $customerQuickActions->push([
            'type' => 'link',
            'label' => 'New Rental',
            'href' => route('rentals.create', ['customer_id' => $customer->id]),
        ]);
    }
    if ($canCreateSales) {
        $customerQuickActions->push([
            'type' => 'link',
            'label' => 'New Sale',
            'href' => route('sales.create', ['customer_id' => $customer->id]),
        ]);
    }

    $customerMoreActions = collect();
    $customerMoreActions->push([
        'type' => 'link',
        'label' => 'Add Note',
        'href' => '#customer-timeline-note',
    ]);
    $customerMoreActions->push([
        'type' => 'link',
        'label' => 'View Timeline',
        'href' => '#customer-timeline',
    ]);
    $customerMoreActions->push([
        'type' => 'button',
        'label' => 'Add Follow-up',
        'attributes' => [
            'data-open-follow-up-modal' => true,
            'data-follow-up-context' => $customerFollowUpContextJson,
            'data-follow-up-type' => \App\Models\FollowUp::TYPE_CALLBACK,
            'data-follow-up-priority' => \App\Models\FollowUp::PRIORITY_MEDIUM,
        ],
    ]);
    $customerMoreActions->push([
        'type' => 'link',
        'label' => 'View Invoices',
        'href' => route('invoices.index', ['customer_id' => $customer->id]),
    ]);
    if ($currentUser?->canViewRecordFinance()) {
        $customerMoreActions->push([
            'type' => 'link',
            'label' => 'Ledger',
            'href' => route('ledger.index', ['customer_id' => $customer->id]),
        ]);
    }
    if ($paymentsIndexHref) {
        $customerMoreActions->push([
            'type' => 'link',
            'label' => 'View Payments',
            'href' => $paymentsIndexHref,
        ]);
    }
    if ($deliveriesSearchHref) {
        $customerMoreActions->push([
            'type' => 'link',
            'label' => 'View Deliveries',
            'href' => $deliveriesSearchHref,
        ]);
    }
    if ($canUpdateCustomers) {
        $customerMoreActions->push([
            'type' => 'link',
            'label' => 'Edit Customer',
            'href' => route('customers.edit', $customer),
        ]);
    }
    if ($mapUrl) {
        $customerMoreActions->push([
            'type' => 'link',
            'label' => 'Open Map',
            'href' => $mapUrl,
            'target' => '_blank',
            'rel' => 'noopener',
        ]);
    }

    if ($canCreateInvoices) {
        $customerMoreActions->push([
            'type' => 'link',
            'label' => 'Create Invoice',
            'href' => route('invoices.create', ['customer_id' => $customer->id]),
        ]);
    }
    if ($customer->email) {
        $customerMoreActions->push([
            'type' => 'link',
            'label' => 'Email Customer',
            'href' => 'mailto:' . $customer->email,
        ]);
    }
    foreach ([
        'Renewal Reminder' => $renewalUrl,
        'Payment Reminder' => $paymentReminderUrl,
        'Delivery Confirmation' => $deliveryUrl,
        'Pickup Reminder' => $pickupUrl,
        'Invoice Message' => $invoiceMessageUrl,
    ] as $label => $href) {
        if ($href) {
            $customerMoreActions->push(['type' => 'link', 'label' => $label, 'href' => $href, 'target' => '_blank', 'rel' => 'noopener']);
        }
    }
    if ($canDeleteCustomers) {
        $customerMoreActions->push([
            'type' => 'form',
            'label' => 'Delete Customer',
            'action' => route('customers.destroy', $customer),
            'method' => 'DELETE',
            'confirm' => 'Delete this customer? This will be blocked if dependencies exist.',
            'danger' => true,
        ]);
    }

    $customerStatus = \App\Models\Customer::hasStatusColumn()
        ? ucfirst((string) ($customer->status ?: 'active'))
        : null;
    $customerInitials = collect(preg_split('/\s+/', trim($customerName)))
        ->filter()
        ->take(2)
        ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
        ->implode('');
    $customerLocation = collect([$customer->city, $customer->state])->filter()->implode(', ');
    $rentalsHref = route('rentals.index', ['customer_id' => $customer->id]);
    $salesHref = route('sales.index', ['customer_id' => $customer->id]);
    $invoicesHref = route('invoices.index', ['customer_id' => $customer->id]);
    $unpaidInvoicesHref = route('invoices.index', ['customer_id' => $customer->id, 'status' => 'unpaid']);
    $rentalCounts = [
        ['label' => 'All rentals', 'count' => $customer->rentals_count, 'href' => $rentalsHref],
        ['label' => 'Active', 'count' => $customer->active_rentals_count, 'href' => route('rentals.index', ['customer_id' => $customer->id, 'status' => 'active'])],
        ['label' => 'Overdue', 'count' => $customer->overdue_rentals_count, 'href' => route('rentals.index', ['customer_id' => $customer->id, 'filter' => 'overdue'])],
        ['label' => 'Returned', 'count' => $customer->returned_rentals_count, 'href' => route('rentals.index', ['customer_id' => $customer->id, 'status' => 'returned'])],
        ['label' => 'Ending soon', 'count' => $customer->ending_soon_rentals_count, 'href' => route('rentals.index', ['customer_id' => $customer->id, 'filter' => 'ending_soon'])],
    ];
    $paymentsTotal = $customer->payments->sum('amount');
    $invoiceOutstandingTotal = $customer->invoices->sum(fn ($invoice) => $invoice->balance_amount
        ?? max((float) ($invoice->total_amount ?? 0) - (float) ($invoice->paid_amount ?? 0), 0));
    $statusStyle = fn (?string $status) => match ($status) {
        'active', 'paid', 'completed', 'delivered' => 'customer-status-positive',
        'returned' => 'customer-status-info',
        'pending', 'assigned' => 'customer-status-warning',
        'overdue', 'unpaid' => 'customer-status-danger',
        default => '',
    };
@endphp

<style>
    .customer-detail {
        --customer-ink: #172944;
        --customer-muted: #51647e;
        --customer-blue: #234fb8;
        --customer-soft: #eef4ff;
        --customer-line: #dce5f1;
        --customer-section-offset: 56px;
        display: grid;
        gap: 12px;
        color: var(--customer-ink);
        min-width: 0;
    }
    .customer-detail-card {
        min-width: 0;
        background: #fff;
        border: 1px solid var(--customer-line);
        border-radius: 12px;
    }
    .customer-detail-header { padding: 14px 16px; }
    .customer-back-link,
    .customer-detail-link {
        color: var(--customer-blue);
        font-size: 13px;
        font-weight: 700;
        text-decoration: none;
    }
    .customer-back-link:hover,
    .customer-detail-link:hover { text-decoration: underline; }
    .customer-heading { display: flex; align-items: center; gap: 12px; margin: 10px 0 12px; }
    .customer-heading > div:last-child { min-width: 0; }
    .customer-initials {
        display: grid;
        flex: 0 0 44px;
        height: 44px;
        place-items: center;
        border: 1px solid #d3e0ff;
        border-radius: 10px;
        background: var(--customer-soft);
        color: var(--customer-blue);
        font-weight: 800;
    }
    .customer-heading h1 {
        margin: 0;
        color: var(--customer-ink);
        font-size: clamp(20px, 3vw, 27px);
        line-height: 1.2;
        font-weight: 800;
        overflow-wrap: anywhere;
    }
    .customer-heading-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 9px; margin-top: 4px; color: var(--customer-muted); font-size: 13px; }
    .customer-detail .ph-quick-actions {
        display: block;
        padding: 10px 0 0;
        border: 0;
        border-top: 1px solid var(--customer-line);
        border-radius: 0;
        background: transparent;
        box-shadow: none;
    }
    .customer-detail .ph-quick-actions-head { position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); }
    .customer-detail .ph-quick-actions-row { gap: 6px; }
    .customer-detail .ph-quick-action-btn { min-height: 36px; padding: 7px 10px; border-radius: 8px; box-shadow: none; }
    .customer-detail .ph-quick-action-btn.is-primary { border-color: var(--customer-blue); background: var(--customer-blue); }
    .customer-detail .ph-quick-action-btn.is-primary.is-accent { background: #e9f8ee; border-color: #b6e6c9; color: #176637; }
    .customer-detail .ph-quick-action-panel {
        max-width: calc(100vw - 46px);
        max-height: min(52vh, 390px);
        overflow-y: auto;
        overscroll-behavior: contain;
    }
    .customer-detail .ph-section-nav-shell { position: sticky; top: 0; z-index: 5; margin: 0; min-width: 0; }
    .customer-detail .ph-section-nav {
        flex-wrap: wrap;
        gap: 3px;
        padding: 4px;
        border: 1px solid var(--customer-line);
        border-radius: 9px;
        background: #fff;
        box-shadow: none;
        overflow: visible;
    }
    .customer-detail .ph-section-nav-link { padding: 7px 10px; border-radius: 6px; }
    .customer-detail .ph-section-nav-link.is-active { background: var(--customer-soft); color: var(--customer-blue); box-shadow: none; }
    .customer-detail :is(.section-nav-target, #customer-timeline, #customer-timeline-note) { scroll-margin-top: var(--customer-section-offset); }
    .customer-detail-section-heading {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 6px 12px;
        padding: 11px 14px;
        border-bottom: 1px solid var(--customer-line);
    }
    .customer-detail h2 { margin: 0; color: var(--customer-ink); font-size: 16px; font-weight: 750; }
    .customer-detail h3 { margin: 0 0 5px; color: var(--customer-ink); font-size: 13px; font-weight: 750; }
    .customer-detail-groups { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); }
    .customer-detail-group { min-width: 0; padding: 11px 14px; }
    .customer-detail-group + .customer-detail-group { border-left: 1px solid var(--customer-line); }
    .customer-detail-data { display: grid; gap: 5px; margin: 0; }
    .customer-detail-data > div { display: grid; grid-template-columns: minmax(80px, 104px) minmax(0, 1fr); align-items: baseline; gap: 8px; }
    .customer-detail-data dt { color: var(--customer-muted); font-size: 12px; }
    .customer-detail-data dd { margin: 0; font-size: 13px; overflow-wrap: anywhere; }
    .customer-detail-data dd a { font-size: inherit; }
    .customer-detail-data .customer-field-note { white-space: pre-line; }
    .customer-detail-panel > summary {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 11px 14px;
        min-height: 48px;
        border-radius: 12px;
        cursor: pointer;
        list-style: none;
    }
    .customer-detail-panel > summary::-webkit-details-marker { display: none; }
    .customer-detail-panel > summary::after {
        content: '';
        width: 7px;
        height: 7px;
        margin: 0 3px 3px auto;
        transform: rotate(45deg);
        border: solid var(--customer-muted);
        border-width: 0 2px 2px 0;
        flex: 0 0 auto;
    }
    .customer-detail-panel[open] > summary { border-bottom: 1px solid var(--customer-line); border-radius: 12px 12px 0 0; }
    .customer-detail-panel[open] > summary::after { transform: rotate(225deg); margin-bottom: -3px; }
    .customer-panel-meta { color: var(--customer-muted); font-size: 12px; }
    .customer-panel-tools { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 12px; padding: 8px 14px; }
    .customer-count-link {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 4px 7px;
        border-radius: 6px;
        background: var(--customer-soft);
        color: var(--customer-blue);
        font-size: 12px;
        font-weight: 650;
        text-decoration: none;
    }
    .customer-count-link:hover { text-decoration: underline; }
    .customer-detail-records { list-style: none; padding: 0; margin: 0; }
    .customer-detail-record {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        align-items: center;
        gap: 4px 12px;
        padding: 8px 14px;
        border-top: 1px solid var(--customer-line);
    }
    .customer-detail-record:first-child { border-top: 0; }
    .customer-panel-tools + .customer-detail-records > .customer-detail-record:first-child { border-top: 1px solid var(--customer-line); }
    .customer-record-main { min-width: 0; }
    .customer-record-main strong { color: var(--customer-ink); font-size: 13px; font-weight: 700; overflow-wrap: anywhere; }
    .customer-record-main a strong { color: inherit; }
    .customer-record-meta { display: flex; flex-wrap: wrap; gap: 1px 10px; margin-top: 2px; color: var(--customer-muted); font-size: 12px; overflow-wrap: anywhere; }
    .customer-record-actions { display: flex; align-items: center; flex-wrap: wrap; justify-content: flex-end; gap: 5px 8px; }
    .customer-record-actions form { margin: 0; }
    .customer-record-button {
        padding: 5px 8px;
        border: 1px solid var(--customer-line);
        border-radius: 6px;
        background: #fff;
        color: var(--customer-blue);
        font-size: 12px;
        font-weight: 700;
        text-decoration: none;
        cursor: pointer;
    }
    .customer-record-button:hover { background: var(--customer-soft); }
    .customer-detail-status {
        display: inline-flex;
        max-width: 100%;
        padding: 3px 7px;
        border-radius: 6px;
        background: #f0f3f7;
        color: var(--customer-muted);
        font-size: 11px;
        font-weight: 700;
        line-height: 1.3;
        overflow-wrap: anywhere;
    }
    .customer-status-positive { background: #e8f7ed; color: #166534; }
    .customer-status-info { background: #e9f1ff; color: #224eaa; }
    .customer-status-warning { background: #fef3da; color: #8f4b0e; }
    .customer-status-danger { background: #fdebeb; color: #9d2a25; }
    .customer-detail-empty { margin: 0; padding: 9px 14px 11px; color: var(--customer-muted); font-size: 13px; }
    .customer-detail-message { margin: 0; padding: 8px 12px; border-radius: 8px; background: #e8f7ed; color: #166534; font-size: 13px; }
    .customer-detail-message.is-error { background: #fdebeb; color: #9d2a25; }
    .customer-detail .timeline-shell { min-width: 0; border-radius: 12px; box-shadow: none; }
    .customer-detail [data-record-timestamps] { margin: 0 !important; }
    .customer-detail :is(a, button, summary):focus-visible { outline: 2px solid var(--customer-blue); outline-offset: 2px; }
    @media (max-width: 1024px) {
        .customer-detail { --customer-section-offset: 100px; }
        .customer-detail .ph-section-nav-shell { position: relative; }
    }
    @media (max-width: 640px) {
        .customer-detail { gap: 9px; }
        .customer-detail-header { padding: 11px 12px; }
        .customer-heading { margin-block: 8px 10px; }
        .customer-detail .ph-section-nav-link { min-height: 34px; padding-inline: 8px; }
        .customer-detail-groups { grid-template-columns: minmax(0, 1fr); }
        .customer-detail-group + .customer-detail-group { border-left: 0; border-top: 1px solid var(--customer-line); }
        .customer-detail-panel > summary { gap: 5px 8px; flex-wrap: wrap; }
        .customer-detail-record { grid-template-columns: minmax(0, 1fr); }
        .customer-record-actions { justify-content: flex-start; }
        .customer-detail .ph-quick-action-menu { position: static; }
        .customer-detail .ph-quick-actions-row { position: relative; }
        .customer-detail .ph-quick-action-panel { left: auto; right: 0; }
    }
</style>

<div class="customer-detail" data-customer-details>
    <header class="customer-detail-card customer-detail-header">
        <a href="{{ route('customers.index') }}" class="customer-back-link">&larr; Customers</a>
        <div class="customer-heading">
            <div class="customer-initials" aria-hidden="true">{{ $customerInitials ?: 'CU' }}</div>
            <div>
                <h1>{{ $customerName }}</h1>
                <div class="customer-heading-meta">
                    <span>{{ $customerType }} customer</span>
                    @if($customerStatus)
                        <span class="customer-detail-status {{ $statusStyle(strtolower($customerStatus)) }}">{{ $customerStatus }}</span>
                    @endif
                    @if($customerLocation)
                        <span>{{ $customerLocation }}</span>
                    @endif
                </div>
            </div>
        </div>

        @include('partials.quick-action-toolbar', [
            'label' => 'Customer Quick Actions',
            'actions' => $customerQuickActions->all(),
            'moreActions' => $customerMoreActions->all(),
        ])
    </header>

    <x-section-nav label="Customer page sections" :items="[
        ['id' => 'customer-overview-section', 'label' => 'Overview'],
        ['id' => 'customer-rentals-section', 'label' => 'Rentals'],
        ['id' => 'customer-sales-section', 'label' => 'Sales'],
        ['id' => 'customer-invoices-section', 'label' => 'Invoices'],
        ['id' => 'customer-payments-section', 'label' => 'Payments'],
        ['id' => 'customer-deliveries-section', 'label' => 'Delivery & Pickup'],
        ['id' => 'customer-timeline', 'label' => 'Timeline'],
    ]" />

    @if(session('success'))
        <p class="customer-detail-message" role="status">{{ session('success') }}</p>
    @endif
    @if(session('error'))
        <p class="customer-detail-message is-error" role="alert">{{ session('error') }}</p>
    @endif

    <section class="customer-detail-card section-nav-target" id="customer-overview-section" aria-labelledby="customer-details-heading">
        <div class="customer-detail-section-heading">
            <h2 id="customer-details-heading">Customer Details</h2>
            @if($canUpdateCustomers)
                <a href="{{ route('customers.edit', $customer) }}" class="customer-detail-link">Edit Customer</a>
            @endif
        </div>
        <div class="customer-detail-groups">
            <div class="customer-detail-group">
                <h3>Contact</h3>
                <dl class="customer-detail-data">
                    <div>
                        <dt>{{ $customerType === 'Business' ? 'Company name' : 'Full name' }}</dt>
                        <dd>{{ $identityLabel ?: $customerName ?: '—' }}</dd>
                    </div>
                    @if($customerType === 'Business')
                        <div><dt>Contact name</dt><dd>{{ $customer->contactPersonName() ?: '—' }}</dd></div>
                    @endif
                    <div>
                        <dt>Phone</dt>
                        <dd>
                            @if($customer->phone)
                                <a class="customer-detail-link" href="tel:{{ preg_replace('/\s+/', '', $customer->phone) }}">{{ $customer->phone }}</a>
                            @else
                                —
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt>WhatsApp</dt>
                        <dd>
                            @if($generalWhatsAppUrl)
                                <a class="customer-detail-link" href="{{ $generalWhatsAppUrl }}" target="_blank" rel="noopener">WhatsApp Customer</a>
                            @else
                                Unavailable
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt>Email</dt>
                        <dd>
                            @if($customer->email)
                                <a class="customer-detail-link" href="mailto:{{ $customer->email }}">{{ $customer->email }}</a>
                            @else
                                —
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt>ID proof</dt>
                        <dd>
                            @if($proofUrl)
                                <a href="{{ $proofUrl }}" target="_blank" rel="noopener" class="customer-detail-link">View Proof</a>
                                @if($customer->id_proof_type || $customer->id_proof_number)
                                    <div>{{ $customer->id_proof_type ?: 'Proof' }}{{ $customer->id_proof_number ? ' - ' . $customer->id_proof_number : '' }}</div>
                                @endif
                            @else
                                {{ $customer->id_proof_type || $customer->id_proof_number ? trim(($customer->id_proof_type ?: 'Proof') . ' ' . ($customer->id_proof_number ?: '')) : 'Not uploaded' }}
                            @endif
                        </dd>
                    </div>
                </dl>
            </div>
            <div class="customer-detail-group">
                <h3>Address</h3>
                <dl class="customer-detail-data">
                    <div><dt>Street address</dt><dd class="customer-field-note">{{ $customer->address ?: '—' }}</dd></div>
                    <div><dt>City</dt><dd>{{ $customer->city ?: '—' }}</dd></div>
                    <div><dt>State</dt><dd>{{ $customer->state ?: '—' }}</dd></div>
                    <div><dt>Pincode</dt><dd>{{ $customer->pincode ?: '—' }}</dd></div>
                    <div>
                        <dt>Map location</dt>
                        <dd>
                            @if($mapUrl)
                                <a href="{{ $mapUrl }}" target="_blank" rel="noopener" class="customer-detail-link">Open Map</a>
                                @if($customer->map_location_text)
                                    <div>{{ $customer->map_location_text }}</div>
                                @endif
                            @else
                                —
                            @endif
                        </dd>
                    </div>
                    @if($customer->notes)
                        <div><dt>Notes</dt><dd class="customer-field-note">{{ $customer->notes }}</dd></div>
                    @endif
                </dl>
            </div>
        </div>
    </section>

    <details class="customer-detail-card customer-detail-panel section-nav-target" id="customer-rentals-section">
        <summary>
            <h2>Rentals</h2>
            <span class="customer-panel-meta">{{ $customer->rentals_count ?? 0 }} total &middot; {{ $customer->active_rentals_count ?? 0 }} active</span>
        </summary>
        <nav class="customer-panel-tools" aria-label="Rental status filters">
            @foreach($rentalCounts as $count)
                <a class="customer-count-link" href="{{ $count['href'] }}">{{ $count['label'] }} <strong>{{ $count['count'] ?? 0 }}</strong></a>
            @endforeach
        </nav>
        @if($customer->rentals->isNotEmpty())
            <ul class="customer-detail-records" aria-label="Recent rentals">
                @foreach($customer->rentals as $rental)
                    <li class="customer-detail-record">
                        <div class="customer-record-main">
                            <a href="{{ route('rentals.show', $rental) }}" class="customer-detail-link"><strong>Rental #{{ $rental->id }}</strong></a>
                            <div class="customer-record-meta">
                                <span>{{ $rental->product->name ?? 'Rental item' }}</span>
                                <span>{{ optional($rental->start_date)->format('d M Y') ?: '—' }} to {{ optional($rental->end_date)->format('d M Y') ?: '—' }}</span>
                                <span>{{ $currency($rental->rental_amount ?? 0) }}</span>
                            </div>
                        </div>
                        <div class="customer-record-actions">
                            <span class="customer-detail-status {{ $statusStyle($rental->status) }}">{{ $rental->status }}</span>
                            @if($rental->canBeReturned())
                                <form action="{{ route('rentals.return', $rental) }}" method="POST" onsubmit="return confirm('Mark this rental as returned?');">
                                    @csrf
                                    @method('PUT')
                                    <button type="submit" class="customer-record-button">Return</button>
                                </form>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @else
            <p class="customer-detail-empty">No rentals available.</p>
        @endif
    </details>

    <details class="customer-detail-card customer-detail-panel section-nav-target" id="customer-sales-section">
        <summary>
            <h2>Sales</h2>
            <span class="customer-panel-meta">{{ $customer->sales_count ?? 0 }} total</span>
        </summary>
        <div class="customer-panel-tools">
            <a href="{{ $salesHref }}" class="customer-detail-link">View All Sales</a>
        </div>
        @if($customer->sales->isNotEmpty())
            <ul class="customer-detail-records" aria-label="Recent sales">
                @foreach($customer->sales as $sale)
                    <li class="customer-detail-record">
                        <div class="customer-record-main">
                            <a href="{{ \Illuminate\Support\Facades\Route::has('sales.show') ? route('sales.show', $sale) : $salesHref }}" class="customer-detail-link"><strong>Sale #{{ $sale->id }}</strong></a>
                            <div class="customer-record-meta">
                                <span>{{ $sale->product->name ?? 'Sale item' }}</span>
                                <span>{{ \App\Support\ActionTimestamp::label($sale->created_at) ?: '—' }}</span>
                                <span>{{ $currency($sale->sale_amount ?? 0) }}</span>
                            </div>
                        </div>
                        <div class="customer-record-actions">
                            <span class="customer-detail-status {{ $statusStyle($sale->payment_status ?? 'pending') }}">{{ $sale->payment_status ?? 'pending' }}</span>
                        </div>
                    </li>
                @endforeach
            </ul>
        @else
            <p class="customer-detail-empty">No sales available.</p>
        @endif
    </details>

    <details class="customer-detail-card customer-detail-panel section-nav-target" id="customer-invoices-section">
        <summary>
            <h2>Invoices</h2>
            <span class="customer-panel-meta">{{ $customer->invoices_count ?? 0 }} total &middot; {{ $customer->unpaid_invoices_count ?? 0 }} unpaid</span>
        </summary>
        <div class="customer-panel-tools">
            <a href="{{ $invoicesHref }}" class="customer-detail-link">View All Invoices</a>
            <a href="{{ $unpaidInvoicesHref }}" class="customer-detail-link">View Unpaid Invoices</a>
        </div>
        @if($customer->invoices->isNotEmpty())
            <ul class="customer-detail-records" aria-label="Recent invoices">
                @foreach($customer->invoices as $invoice)
                    <li class="customer-detail-record">
                        <div class="customer-record-main">
                            <a href="{{ route('invoices.show', $invoice->id) }}" class="customer-detail-link"><strong>{{ $invoice->invoice_number ?? 'Invoice #' . $invoice->id }}</strong></a>
                            <div class="customer-record-meta">
                                <span>{{ optional($invoice->invoice_date)->format('d M Y') ?: '—' }}</span>
                                <span>{{ $currency($invoice->total_amount ?? 0) }}</span>
                            </div>
                        </div>
                        <div class="customer-record-actions">
                            <span class="customer-detail-status {{ $statusStyle($invoice->payment_status ?? 'pending') }}">{{ $invoice->payment_status ?? 'pending' }}</span>
                            @if($customerWhatsapp)
                                <a href="{{ \App\Support\WhatsAppHelper::chatUrl($customerWhatsapp, \App\Support\WhatsAppHelper::invoiceMessage($invoice)) }}" target="_blank" rel="noopener" class="customer-record-button">WhatsApp</a>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @else
            <p class="customer-detail-empty">No invoices available.</p>
        @endif
    </details>

    <details class="customer-detail-card customer-detail-panel section-nav-target" id="customer-payments-section">
        <summary>
            <h2>Payments</h2>
            <span class="customer-panel-meta">{{ $customer->payments->count() }} recent</span>
        </summary>
        <div class="customer-panel-tools">
            <span class="customer-panel-meta">Recent payments <strong>{{ $currency($paymentsTotal) }}</strong></span>
            <a href="{{ $unpaidInvoicesHref }}" class="customer-detail-link">Balance on recent invoices {{ $currency($invoiceOutstandingTotal) }}</a>
            @if($paymentsIndexHref)
                <a href="{{ $paymentsIndexHref }}" class="customer-detail-link">View All Payments</a>
            @endif
        </div>
        @if($customer->payments->isNotEmpty())
            <ul class="customer-detail-records" aria-label="Recent payments">
                @foreach($customer->payments as $payment)
                    <li class="customer-detail-record">
                        <div class="customer-record-main">
                            <a href="{{ $paymentsIndexHref ?: $invoicesHref }}" class="customer-detail-link"><strong>{{ $currency($payment->amount ?? 0) }}</strong></a>
                            <div class="customer-record-meta">
                                <span>{{ optional($payment->payment_date)->format('d M Y') ?: '—' }}</span>
                                <span>{{ $payment->rental->product->name ?? 'Rental Payment' }}</span>
                            </div>
                        </div>
                        <div class="customer-record-actions">
                            <span class="customer-detail-status customer-status-positive">{{ $payment->payment_method ?: 'payment' }}</span>
                        </div>
                    </li>
                @endforeach
            </ul>
        @else
            <p class="customer-detail-empty">No payments recorded yet.</p>
        @endif
    </details>

    <details class="customer-detail-card customer-detail-panel section-nav-target" id="customer-deliveries-section">
        <summary>
            <h2>Delivery &amp; Pickup</h2>
            <span class="customer-panel-meta">{{ $deliveryEntries->count() }} recent rentals</span>
        </summary>
        @if($deliveriesSearchHref)
            <div class="customer-panel-tools"><a href="{{ $deliveriesSearchHref }}" class="customer-detail-link">View Deliveries</a></div>
        @endif
        @if($deliveryEntries->isNotEmpty())
            <ul class="customer-detail-records" aria-label="Recent deliveries and pickups">
                @foreach($deliveryEntries as $rental)
                    <li class="customer-detail-record">
                        <div class="customer-record-main">
                            <a href="{{ route('rentals.show', $rental) }}" class="customer-detail-link"><strong>Rental #{{ $rental->id }}</strong></a>
                            <div class="customer-record-meta">{{ $rental->product->name ?? 'Rental item' }}</div>
                        </div>
                        <div class="customer-record-actions">
                            @if($rental->deliveryRecord)
                                <span class="customer-detail-status {{ $statusStyle($rental->deliveryRecord->status) }}">Delivery &middot; {{ str_replace('_', ' ', $rental->deliveryRecord->status) }}</span>
                            @endif
                            @if($rental->pickupRecord)
                                <span class="customer-detail-status {{ $statusStyle($rental->pickupRecord->status) }}">Pickup &middot; {{ str_replace('_', ' ', $rental->pickupRecord->status) }}</span>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @else
            <p class="customer-detail-empty">No delivery or pickup activity yet.</p>
        @endif
    </details>

    @include('partials.activity-timeline', [
        'timeline' => $activityTimeline ?? collect(),
        'title' => 'Timeline',
        'subtitle' => 'Rentals, sales, invoices, payments, deliveries, reminders, and notes.',
        'timelineFilter' => $timelineFilter ?? 'all',
        'timelineRoute' => 'customers.show',
        'noteAction' => route('customers.notes.store', $customer->id),
        'noteLabel' => 'Add Note',
        'anchorId' => 'customer-timeline',
    ])

    @include('partials.record-timestamps', ['timestampRecord' => $customer, 'timestampFields' => ['created_at' => 'Created', 'updated_at' => 'Last updated']])
</div>

@include('partials.follow-up-modal')
@include('partials.mobile-action-bar', [
    'label' => 'Customer mobile actions',
    'moreLabel' => 'Customer secondary actions',
    'actions' => $mobilePrimaryActions->all(),
    'moreActions' => $mobileMoreActions->all(),
])

<script>
    (() => {
        const page = document.querySelector('[data-customer-details]');
        const revealSection = (id) => {
            const target = document.getElementById(id);
            if (!target || !page.contains(target)) return;

            for (let section = target.closest('details'); section; section = section.parentElement.closest('details')) {
                section.open = true;
            }
        };

        page.addEventListener('click', (event) => {
            const link = event.target.closest('a[href^="#customer-"]');
            if (link) {
                revealSection(link.hash.slice(1));
                link.closest('[data-quick-action-menu]')?.removeAttribute('open');
            }
        }, true);

        window.addEventListener('hashchange', () => revealSection(location.hash.slice(1)));
        revealSection(location.hash.slice(1));
    })();
</script>
@endsection
