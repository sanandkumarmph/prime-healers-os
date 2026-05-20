@extends('layouts.app')

@section('content')
@php
    $currentUser = auth()->user();
    $canCreateRentals = $currentUser?->canAccessModule('rentals', 'create') ?? false;
    $canCreateSales = $currentUser?->canAccessModule('sales', 'create') ?? false;
    $partnerPhone = $businessPartner->preferredReminderNumber() ?: $businessPartner->phone;
    $partnerCallHref = $partnerPhone ? 'tel:' . preg_replace('/\D+/', '', $partnerPhone) : null;
    $partnerWhatsappHref = \App\Support\WhatsAppHelper::chatUrl(
        \App\Support\WhatsAppHelper::normalizeNumber($businessPartner->whatsapp ?: $partnerPhone),
        $partnerPhone ? "Hello {$businessPartner->displayName()}, this is a quick update from Prime Healers." : null
    );
    $partnerFollowUpContextJson = json_encode([
        'business_partner_id' => $businessPartner->id,
        'reference' => 'Business Partner #' . $businessPartner->id,
        'reminder_contact' => collect([$businessPartner->displayName(), $partnerPhone])->filter()->implode(' | '),
        'service_contact' => $businessPartner->partnerClients->first()
            ? collect([$businessPartner->partnerClients->first()->displayName(), $businessPartner->partnerClients->first()->primaryPhone()])->filter()->implode(' | ')
            : null,
        'service_address' => $businessPartner->partnerClients->first()?->address,
    ]);
    $partnerQuickActions = collect();
    $partnerMoreActions = collect();
    $partnerInfoItems = collect();

    if ($partnerCallHref) {
        $partnerQuickActions->push([
            'type' => 'link',
            'label' => 'Call',
            'href' => $partnerCallHref,
        ]);
    }

    if ($partnerWhatsappHref) {
        $partnerQuickActions->push([
            'type' => 'link',
            'label' => 'WhatsApp',
            'href' => $partnerWhatsappHref,
            'target' => '_blank',
            'rel' => 'noopener',
            'accent' => true,
        ]);
    }

    if ($canCreateRentals) {
        $partnerQuickActions->push([
            'type' => 'link',
            'label' => 'New Rental',
            'href' => route('rentals.create', ['customer_type' => 'business_partner', 'business_partner_id' => $businessPartner->id]),
        ]);
    }

    if ($canCreateSales) {
        $partnerQuickActions->push([
            'type' => 'link',
            'label' => 'New Sale',
            'href' => route('sales.create', ['customer_type' => 'business_partner', 'business_partner_id' => $businessPartner->id]),
        ]);
    }

    $partnerQuickActions->push([
        'type' => 'link',
        'label' => 'Add Actual Client',
        'href' => route('business-partners.clients.create', $businessPartner),
    ]);

    $partnerMoreActions->push([
        'type' => 'link',
        'label' => 'Add Note',
        'href' => '#business-partner-timeline',
    ]);
    $partnerMoreActions->push([
        'type' => 'link',
        'label' => 'View Timeline',
        'href' => '#business-partner-timeline',
    ]);
    $partnerMoreActions->push([
        'type' => 'button',
        'label' => 'Add Follow-up',
        'attributes' => [
            'data-open-follow-up-modal' => true,
            'data-follow-up-context' => $partnerFollowUpContextJson,
            'data-follow-up-type' => \App\Models\FollowUp::TYPE_PAYMENT,
            'data-follow-up-priority' => \App\Models\FollowUp::PRIORITY_MEDIUM,
        ],
    ]);
    $partnerMoreActions->push([
        'type' => 'link',
        'label' => 'View Rentals',
        'href' => route('rentals.index', ['business_partner_id' => $businessPartner->id]),
    ]);
    $partnerMoreActions->push([
        'type' => 'link',
        'label' => 'View Invoices',
        'href' => route('invoices.index', ['business_partner_id' => $businessPartner->id]),
    ]);
    $partnerMoreActions->push([
        'type' => 'link',
        'label' => 'Edit Partner',
        'href' => route('business-partners.edit', $businessPartner),
    ]);

    if ($businessPartner->openMapUrl()) {
        $partnerMoreActions->push([
            'type' => 'link',
            'label' => 'Open Map',
            'href' => $businessPartner->openMapUrl(),
            'target' => '_blank',
            'rel' => 'noopener',
        ]);
    }

    $partnerInfoItems->push([
        'label' => 'Reminder / Payment',
        'value' => collect([$businessPartner->displayName(), $partnerPhone])->filter()->implode(' | '),
    ]);

    if ($businessPartner->address) {
        $partnerInfoItems->push([
            'label' => 'Billing Address',
            'value' => collect([$businessPartner->address, collect([$businessPartner->city, $businessPartner->state, $businessPartner->pincode])->filter()->implode(', ')])->filter()->implode(' | '),
            'href' => $businessPartner->openMapUrl(),
            'target' => '_blank',
            'rel' => 'noopener',
            'linkLabel' => 'Open Map',
        ]);
    }
@endphp
<style>
    .bp-show { display:grid; gap:16px; padding:18px 22px 30px; max-width:1200px; margin:0 auto; }
    .bp-show-card { background:#fff; border:1px solid #dbe3ef; border-radius:16px; padding:18px; box-shadow:0 8px 24px rgba(15,23,42,.04); }
    .bp-show-grid { display:grid; grid-template-columns:repeat(12, minmax(0,1fr)); gap:14px; }
    .bp-show-col-4 { grid-column:span 4; }
    .bp-show-col-6 { grid-column:span 6; }
    .bp-show-col-12 { grid-column:span 12; }
    .bp-show-label { display:block; margin-bottom:5px; color:#64748b; font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.05em; }
    .bp-show-value { color:#0f172a; font-size:14px; line-height:1.5; }
    .bp-show-btn, .bp-show-btn-light { display:inline-flex; align-items:center; justify-content:center; gap:6px; min-height:40px; padding:10px 14px; border-radius:12px; border:1px solid transparent; text-decoration:none; font-size:13px; font-weight:700; cursor:pointer; }
    .bp-show-btn { background:#0f172a; color:#fff; }
    .bp-show-btn-light { background:#fff; color:#334155; border-color:#cbd5e1; }
    .bp-show-table { width:100%; border-collapse:collapse; }
    .bp-show-table th, .bp-show-table td { padding:12px 14px; border-bottom:1px solid #eef2f7; text-align:left; vertical-align:top; }
    .bp-show-table th { font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:.05em; }
    @media (max-width: 900px) {
        .bp-show-col-4, .bp-show-col-6 { grid-column:span 12; }
    }
    @media (max-width: 640px) {
        .bp-show { padding:14px; }
    }
</style>

<div class="bp-show">
    <div class="bp-show-card">
        <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:flex-start;">
            <div>
                <h1 style="margin:0;color:#0f172a;">{{ $businessPartner->displayName() }}</h1>
                <p style="margin:6px 0 0;color:#64748b;">Reminder and billing contact for tie-up rentals and sales.</p>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <a href="{{ route('business-partners.index') }}" class="bp-show-btn-light">Back</a>
                <a href="{{ route('business-partners.edit', $businessPartner) }}" class="bp-show-btn-light">Edit Partner</a>
                <a href="{{ route('business-partners.clients.create', $businessPartner) }}" class="bp-show-btn">Add Actual Client</a>
            </div>
        </div>
    </div>

    @include('partials.quick-action-toolbar', [
        'label' => 'Business Partner Quick Actions',
        'actions' => $partnerQuickActions,
        'moreActions' => $partnerMoreActions,
        'infoItems' => $partnerInfoItems,
    ])

    <div class="bp-show-card">
        <div class="bp-show-grid">
            <div class="bp-show-col-4">
                <span class="bp-show-label">Contact Person</span>
                <div class="bp-show-value">{{ $businessPartner->contact_person ?: 'Not set' }}</div>
            </div>
            <div class="bp-show-col-4">
                <span class="bp-show-label">Phone</span>
                <div class="bp-show-value">{{ $businessPartner->phone ?: 'Not set' }}</div>
            </div>
            <div class="bp-show-col-4">
                <span class="bp-show-label">WhatsApp</span>
                <div class="bp-show-value">{{ $businessPartner->whatsapp ?: 'Not set' }}</div>
            </div>
            <div class="bp-show-col-4">
                <span class="bp-show-label">Email</span>
                <div class="bp-show-value">{{ $businessPartner->email ?: 'Not set' }}</div>
            </div>
            <div class="bp-show-col-4">
                <span class="bp-show-label">City / State</span>
                <div class="bp-show-value">{{ collect([$businessPartner->city, $businessPartner->state])->filter()->implode(', ') ?: 'Not set' }}</div>
            </div>
            <div class="bp-show-col-4">
                <span class="bp-show-label">Status</span>
                <div class="bp-show-value">{{ ucfirst($businessPartner->status ?: 'active') }}</div>
            </div>
            <div class="bp-show-col-12">
                <span class="bp-show-label">Address</span>
                <div class="bp-show-value">{{ $businessPartner->address ?: 'Not set' }}</div>
            </div>
            <div class="bp-show-col-12">
                <span class="bp-show-label">Map / Location</span>
                <div class="bp-show-value">
                    @if($businessPartner->openMapUrl())
                        <a href="{{ $businessPartner->openMapUrl() }}" target="_blank" rel="noopener">{{ $businessPartner->location ?: 'Open map' }}</a>
                    @else
                        {{ $businessPartner->location ?: 'Not set' }}
                    @endif
                </div>
            </div>
            <div class="bp-show-col-4">
                <span class="bp-show-label">GST Registered</span>
                <div class="bp-show-value">{{ $businessPartner->gst_registered ? 'Yes' : 'No' }}</div>
            </div>
            <div class="bp-show-col-4">
                <span class="bp-show-label">GSTIN</span>
                <div class="bp-show-value">{{ $businessPartner->gstin ?: 'Not set' }}</div>
            </div>
            <div class="bp-show-col-4">
                <span class="bp-show-label">Legal Business Name</span>
                <div class="bp-show-value">{{ $businessPartner->legal_name ?: 'Not set' }}</div>
            </div>
            <div class="bp-show-col-4">
                <span class="bp-show-label">Billing State</span>
                <div class="bp-show-value">{{ $businessPartner->billingStateValue() ?: 'Not set' }}</div>
            </div>
            <div class="bp-show-col-12">
                <span class="bp-show-label">GST Billing Address</span>
                <div class="bp-show-value">
                    {{ collect([
                        $businessPartner->billingAddressLine(),
                        collect([$businessPartner->billingCityValue(), $businessPartner->billingStateValue(), $businessPartner->billingPincodeValue()])->filter()->implode(', ')
                    ])->filter()->implode(' · ') ?: 'Not set' }}
                </div>
            </div>
        </div>
    </div>

    <div class="bp-show-card" id="actual-clients">
        <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:flex-start;">
            <div>
                <h2 style="margin:0;color:#0f172a;">Actual Clients</h2>
                <div style="color:#64748b;font-size:12px;margin-top:4px;">Delivery and service locations linked to this business partner.</div>
            </div>
            <a href="{{ route('business-partners.clients.create', $businessPartner) }}" class="bp-show-btn">Add Actual Client</a>
        </div>

        <form method="GET" style="display:grid;grid-template-columns:minmax(0,2fr) 220px auto auto;gap:10px;align-items:end;margin-top:14px;">
            <div>
                <label style="display:block;margin-bottom:6px;color:#475569;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;">Search Clients</label>
                <input type="text" name="client_search" value="{{ $clientSearch }}" placeholder="Search client name, phone, address, or city" style="width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:12px;padding:10px 12px;">
            </div>
            <div>
                <label style="display:block;margin-bottom:6px;color:#475569;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;">Status</label>
                <select name="client_status" style="width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:12px;padding:10px 12px;">
                    <option value="">All statuses</option>
                    <option value="active" {{ $clientStatus === 'active' ? 'selected' : '' }}>Active</option>
                    <option value="inactive" {{ $clientStatus === 'inactive' ? 'selected' : '' }}>Inactive</option>
                </select>
            </div>
            <button type="submit" class="bp-show-btn">Search</button>
            <a href="{{ route('business-partners.show', $businessPartner) }}" class="bp-show-btn-light">Clear</a>
        </form>

        <div style="overflow:auto;margin-top:14px;">
            <table class="bp-show-table">
                <thead>
                    <tr>
                        <th>Actual Client</th>
                        <th>Phone</th>
                        <th>Address</th>
                        <th>Notes</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($clients as $client)
                        <tr>
                            <td>
                                <strong>{{ $client->displayName() }}</strong>
                                <div style="color:#64748b;font-size:12px;margin-top:4px;">{{ ucfirst($client->status ?: 'active') }}</div>
                            </td>
                            <td>
                                <div>{{ $client->primaryPhone() ?: 'Not set' }}</div>
                                @if($client->alternate_phone)
                                    <div style="color:#64748b;font-size:12px;">Alt: {{ $client->alternate_phone }}</div>
                                @endif
                            </td>
                            <td>
                                <div>{{ collect([$client->address, collect([$client->city, $client->state, $client->pincode])->filter()->implode(', ')])->filter()->implode(' · ') ?: 'Not set' }}</div>
                                @if($client->openMapUrl())
                                    <div style="margin-top:4px;"><a href="{{ $client->openMapUrl() }}" target="_blank" rel="noopener">Open map</a></div>
                                @endif
                            </td>
                            <td>{{ $client->delivery_notes ?: 'No notes' }}</td>
                            <td>
                                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                                    @if($client->primaryPhone())
                                        <a href="tel:{{ preg_replace('/\D+/', '', $client->primaryPhone()) }}" class="bp-show-btn-light">Call</a>
                                    @endif
                                    @if($client->openMapUrl())
                                        <a href="{{ $client->openMapUrl() }}" target="_blank" rel="noopener" class="bp-show-btn-light">Open Map</a>
                                    @endif
                                    @if($canCreateRentals)
                                        <a href="{{ route('rentals.create', ['customer_type' => 'business_partner', 'business_partner_id' => $businessPartner->id, 'partner_client_id' => $client->id]) }}" class="bp-show-btn-light">New Rental</a>
                                    @endif
                                    @if($canCreateSales)
                                        <a href="{{ route('sales.create', ['customer_type' => 'business_partner', 'business_partner_id' => $businessPartner->id, 'partner_client_id' => $client->id]) }}" class="bp-show-btn-light">New Sale</a>
                                    @endif
                                    <a href="{{ route('business-partners.clients.edit', [$businessPartner, $client]) }}" class="bp-show-btn-light">Edit</a>
                                    <form action="{{ route('business-partners.clients.destroy', [$businessPartner, $client]) }}" method="POST" style="margin:0;">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="bp-show-btn-light" onclick="return confirm('Delete this actual client?');" style="color:#991b1b;border-color:#fecaca;background:#fff1f2;">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" style="color:#64748b;">No actual clients found for this business partner.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div style="margin-top:14px;">{{ $clients->links() }}</div>
    </div>

    @include('partials.activity-timeline', [
        'timeline' => $activityTimeline ?? collect(),
        'title' => 'Timeline',
        'subtitle' => 'Partner setup, actual clients, rentals, sales, reminders, and follow-up history.',
        'timelineFilter' => $timelineFilter ?? 'all',
        'timelineRoute' => 'business-partners.show',
        'noteAction' => route('business-partners.notes.store', $businessPartner),
        'noteLabel' => 'Add Note',
            'anchorId' => 'business-partner-timeline',
    ])
</div>
@include('partials.follow-up-modal')
@endsection
