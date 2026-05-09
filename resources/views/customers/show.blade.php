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

    $statusBadge = function (?string $status) {
        return match ($status) {
            'active', 'paid', 'completed', 'delivered' => 'background:#dcfce7;color:#166534;',
            'returned' => 'background:#dbeafe;color:#1d4ed8;',
            'pending', 'assigned' => 'background:#fef3c7;color:#b45309;',
            'overdue', 'unpaid' => 'background:#fee2e2;color:#b91c1c;',
            default => 'background:#f1f5f9;color:#475569;',
        };
    };
@endphp

<style>
    .profile-page { padding: 18px 22px 28px; display: grid; gap: 16px; }
    .profile-header { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap; }
    .profile-header h1 { margin:0; font-size:28px; color:#0f172a; }
    .profile-header p { margin:6px 0 0; color:#64748b; font-size:13px; }
    .profile-actions { display:flex; gap:8px; flex-wrap:wrap; }
    .ops-card {
        background:#fff;
        border:1px solid #dbe3ef;
        border-radius:14px;
        box-shadow:0 8px 24px rgba(15, 23, 42, 0.04);
    }
    .ops-card-body { padding:16px; }
    .ops-btn,
    .ops-btn-secondary,
    .ops-btn-light,
    .ops-btn-danger,
    .ops-btn-disabled {
        display:inline-flex;
        align-items:center;
        justify-content:center;
        gap:6px;
        padding:8px 12px;
        border-radius:10px;
        font-size:13px;
        font-weight:600;
        text-decoration:none;
        border:1px solid transparent;
        cursor:pointer;
        line-height:1.2;
    }
    .ops-btn { background:#0f172a; color:#fff; }
    .ops-btn-secondary { background:#2563eb; color:#fff; }
    .ops-btn-light { background:#fff; color:#334155; border-color:#cbd5e1; }
    .ops-btn-danger { background:#dc2626; color:#fff; }
    .ops-btn-disabled {
        background:#f8fafc;
        color:#94a3b8;
        border-color:#e2e8f0;
        cursor:not-allowed;
    }
    .metric-grid { display:grid; grid-template-columns:repeat(5, minmax(0, 1fr)); gap:12px; }
    .metric-box {
        border:1px solid #e2e8f0;
        border-radius:12px;
        background:linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
        padding:14px;
        text-decoration:none;
        color:inherit;
        display:block;
    }
    .metric-box span {
        display:block;
        font-size:11px;
        color:#64748b;
        font-weight:700;
        text-transform:uppercase;
        letter-spacing:.04em;
        margin-bottom:8px;
    }
    .metric-box strong { font-size:24px; color:#0f172a; line-height:1; }
    .metric-box small { display:block; margin-top:8px; color:#475569; font-size:12px; }
    .profile-grid { display:grid; grid-template-columns:repeat(12, minmax(0, 1fr)); gap:16px; }
    .span-4 { grid-column:span 4; }
    .span-6 { grid-column:span 6; }
    .span-8 { grid-column:span 8; }
    .span-12 { grid-column:span 12; }
    .section-title { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; margin-bottom:12px; }
    .section-title h2 { margin:0; font-size:18px; color:#0f172a; }
    .section-title p { margin:4px 0 0; color:#64748b; font-size:12px; }
    .info-grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:12px; }
    .info-grid-compact { grid-template-columns:repeat(2, minmax(0, 1fr)); }
    .info-box {
        border:1px solid #e2e8f0;
        border-radius:12px;
        background:#fcfdff;
        padding:12px;
    }
    .label {
        display:block;
        font-size:11px;
        color:#64748b;
        font-weight:700;
        text-transform:uppercase;
        letter-spacing:.04em;
        margin-bottom:5px;
    }
    .value { color:#0f172a; font-size:14px; line-height:1.6; }
    .badge {
        display:inline-flex;
        align-items:center;
        padding:4px 9px;
        border-radius:999px;
        font-size:11px;
        font-weight:700;
        text-transform:uppercase;
        letter-spacing:.03em;
    }
    .mini-list { display:grid; gap:10px; }
    .mini-item {
        border:1px solid #e2e8f0;
        border-radius:12px;
        padding:12px;
        background:#fcfdff;
    }
    .mini-head {
        display:flex;
        justify-content:space-between;
        gap:10px;
        align-items:flex-start;
        flex-wrap:wrap;
        margin-bottom:6px;
    }
    .mini-head strong { color:#0f172a; font-size:14px; }
    .mini-sub { color:#64748b; font-size:12px; line-height:1.5; }
    .chip-row { display:flex; flex-wrap:wrap; gap:6px; }
    .chip {
        display:inline-flex;
        align-items:center;
        padding:4px 8px;
        border-radius:999px;
        background:#eef2ff;
        color:#3730a3;
        font-size:11px;
        font-weight:700;
        text-decoration:none;
    }
    .action-grid { display:flex; flex-wrap:wrap; gap:8px; }
    .empty-state {
        color:#64748b;
        font-size:13px;
        padding:8px 0;
    }
    @media (max-width: 1100px) {
        .metric-grid { grid-template-columns:repeat(3, minmax(0, 1fr)); }
        .span-4, .span-6, .span-8 { grid-column:span 12; }
    }
    @media (max-width: 720px) {
        .profile-page { padding:14px; }
        .metric-grid { grid-template-columns:repeat(2, minmax(0, 1fr)); }
        .info-grid { grid-template-columns:repeat(2, minmax(0, 1fr)); }
        .profile-actions {
            display:grid;
            grid-template-columns:repeat(2, minmax(0, 1fr));
            align-items:stretch;
            width:100%;
        }
        .profile-actions form { margin:0; }
        .profile-actions .ops-btn,
        .profile-actions .ops-btn-light,
        .profile-actions .ops-btn-secondary,
        .profile-actions .ops-btn-danger { width:100%; min-height:44px; }
    }
    @media (max-width: 520px) {
        .metric-grid { grid-template-columns:repeat(2, minmax(0, 1fr)); }
        .info-grid { grid-template-columns:1fr; }
        .info-grid.info-grid-compact { grid-template-columns:repeat(2, minmax(0, 1fr)); }
    }
</style>

<div class="container profile-page">
    <div class="profile-header">
        <div>
            <h1>{{ $customerName }}</h1>
            <p>Customer profile with identity, location, WhatsApp actions, and compact drilldowns into rentals, sales, invoices, and payments.</p>
        </div>
        <div class="profile-actions">
            <a href="{{ route('customers.index') }}" class="ops-btn-light">Back</a>
            @if($canUpdateCustomers)
                <a href="{{ route('customers.edit', $customer) }}" class="ops-btn-light">Edit Customer</a>
            @endif
            @if($canCreateRentals)
                <a href="{{ route('rentals.create', ['customer_id' => $customer->id]) }}" class="ops-btn-light">Create Rental</a>
            @endif
            @if($canCreateSales)
                <a href="{{ route('sales.create', ['customer_id' => $customer->id]) }}" class="ops-btn-light">Create Sale</a>
            @endif
            @if($canCreateInvoices)
                <a href="{{ route('invoices.create', ['customer_id' => $customer->id]) }}" class="ops-btn">Create Invoice</a>
            @endif
            @if($canDeleteCustomers)
                <form method="POST" action="{{ route('customers.destroy', $customer) }}" style="margin:0;" onsubmit="return confirm('Delete this customer? This will be blocked if dependencies exist.');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="ops-btn-danger">Delete</button>
                </form>
            @endif
        </div>
    </div>

    @if(session('success'))
        <div class="ops-card">
            <div class="ops-card-body" style="background:#dcfce7; color:#166534; border-radius:14px;">
                {{ session('success') }}
            </div>
        </div>
    @endif

    @if(session('error'))
        <div class="ops-card">
            <div class="ops-card-body" style="background:#fee2e2; color:#991b1b; border-radius:14px;">
                {{ session('error') }}
            </div>
        </div>
    @endif

    <div class="metric-grid">
        <a href="{{ route('rentals.index', ['customer_id' => $customer->id]) }}" class="metric-box">
            <span>Total Rentals</span>
            <strong>{{ $customer->rentals_count ?? 0 }}</strong>
            <small>Open full rental history</small>
        </a>
        <a href="{{ route('rentals.index', ['customer_id' => $customer->id, 'status' => 'active']) }}" class="metric-box">
            <span>Active Rentals</span>
            <strong>{{ $customer->active_rentals_count ?? 0 }}</strong>
            <small>Current rental workload</small>
        </a>
        <a href="{{ route('rentals.index', ['customer_id' => $customer->id, 'filter' => 'overdue']) }}" class="metric-box">
            <span>Overdue Rentals</span>
            <strong>{{ $customer->overdue_rentals_count ?? 0 }}</strong>
            <small>Requires immediate follow-up</small>
        </a>
        <a href="{{ route('sales.index', ['customer_id' => $customer->id]) }}" class="metric-box">
            <span>Total Sales</span>
            <strong>{{ $customer->sales_count ?? 0 }}</strong>
            <small>Sales history for this customer</small>
        </a>
        <a href="{{ route('invoices.index', ['customer_id' => $customer->id]) }}" class="metric-box">
            <span>Invoices</span>
            <strong>{{ $customer->invoices_count ?? 0 }}</strong>
            <small>{{ $customer->unpaid_invoices_count ?? 0 }} unpaid</small>
        </a>
    </div>

    <div class="profile-grid">
        <div class="ops-card span-8">
            <div class="ops-card-body">
                <div class="section-title">
                    <div>
                        <h2>Customer Summary</h2>
                        <p>Identity, communication, and address fields structured around the new individual or business flow.</p>
                    </div>
                </div>

                <div class="info-grid info-grid-compact">
                    <div class="info-box">
                        <span class="label">Customer Type</span>
                        <div class="value">{{ $customerType }}</div>
                    </div>
                    <div class="info-box">
                        <span class="label">{{ $customerType === 'Business' ? 'Company Name' : 'Full Name' }}</span>
                        <div class="value">{{ $identityLabel ?: $customerName ?: '-' }}</div>
                    </div>
                    @if($customerType === 'Business')
                        <div class="info-box">
                            <span class="label">Contact Name</span>
                            <div class="value">{{ $customer->contactPersonName() ?: '-' }}</div>
                        </div>
                    @endif
                    <div class="info-box">
                        <span class="label">Phone</span>
                        <div class="value">{{ $customer->phone ?: '-' }}</div>
                    </div>
                    <div class="info-box">
                        <span class="label">Email</span>
                        <div class="value">{{ $customer->email ?: '-' }}</div>
                    </div>
                    <div class="info-box">
                        <span class="label">City</span>
                        <div class="value">{{ $customer->city ?: '-' }}</div>
                    </div>
                    <div class="info-box">
                        <span class="label">State</span>
                        <div class="value">{{ $customer->state ?: '-' }}</div>
                    </div>
                    <div class="info-box">
                        <span class="label">Pincode</span>
                        <div class="value">{{ $customer->pincode ?: '-' }}</div>
                    </div>
                    <div class="info-box">
                        <span class="label">Map Location</span>
                        <div class="value">
                            @if($mapUrl)
                                <a href="{{ $mapUrl }}" target="_blank" class="chip">Open Map</a>
                                @if($customer->map_location_text)
                                    <div style="margin-top:6px;">{{ $customer->map_location_text }}</div>
                                @endif
                            @else
                                -
                            @endif
                        </div>
                    </div>
                    <div class="info-box">
                        <span class="label">ID Proof</span>
                        <div class="value">
                            @if($proofUrl)
                                <a href="{{ $proofUrl }}" target="_blank" class="chip">View Proof</a>
                                @if($customer->id_proof_type || $customer->id_proof_number)
                                    <div style="margin-top:6px;">
                                        {{ $customer->id_proof_type ?: 'Proof' }}{{ $customer->id_proof_number ? ' - ' . $customer->id_proof_number : '' }}
                                    </div>
                                @endif
                            @else
                                {{ $customer->id_proof_type || $customer->id_proof_number ? trim(($customer->id_proof_type ?: 'Proof') . ' ' . ($customer->id_proof_number ?: '')) : '-' }}
                            @endif
                        </div>
                    </div>
                    <div class="info-box" style="grid-column:1 / -1;">
                        <span class="label">Address</span>
                        <div class="value">{{ $customer->address ?: '-' }}</div>
                    </div>
                    @if($customer->notes)
                        <div class="info-box" style="grid-column:1 / -1;">
                            <span class="label">Notes</span>
                            <div class="value">{{ $customer->notes }}</div>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="ops-card span-4">
            <div class="ops-card-body">
                <div class="section-title">
                    <div>
                        <h2>Quick Actions</h2>
                        <p>Fast follow-up actions for staff and office teams.</p>
                    </div>
                </div>

                <div class="action-grid" style="margin-bottom:10px;">
                    @if($generalWhatsAppUrl)
                        <a href="{{ $generalWhatsAppUrl }}" target="_blank" class="ops-btn-secondary">WhatsApp Customer</a>
                    @else
                        <span class="ops-btn-disabled">WhatsApp Unavailable</span>
                    @endif

                    @if($customer->phone)
                        <a href="tel:{{ preg_replace('/\s+/', '', $customer->phone) }}" class="ops-btn-light">Call</a>
                    @else
                        <span class="ops-btn-disabled">Call</span>
                    @endif

                    @if($mapUrl)
                        <a href="{{ $mapUrl }}" target="_blank" class="ops-btn-light">Open Map</a>
                    @endif
                </div>

                <div class="chip-row">
                    @if($renewalUrl)
                        <a href="{{ $renewalUrl }}" target="_blank" class="chip">Renewal Reminder</a>
                    @endif
                    @if($paymentReminderUrl)
                        <a href="{{ $paymentReminderUrl }}" target="_blank" class="chip">Payment Reminder</a>
                    @endif
                    @if($deliveryUrl)
                        <a href="{{ $deliveryUrl }}" target="_blank" class="chip">Delivery Confirmation</a>
                    @endif
                    @if($pickupUrl)
                        <a href="{{ $pickupUrl }}" target="_blank" class="chip">Pickup Reminder</a>
                    @endif
                    @if($invoiceMessageUrl)
                        <a href="{{ $invoiceMessageUrl }}" target="_blank" class="chip">Invoice Message</a>
                    @endif
                </div>
            </div>
        </div>

        <div class="ops-card span-6">
            <div class="ops-card-body">
                <div class="section-title">
                    <div>
                        <h2>Rental Summary</h2>
                        <p>Live rental status breakdown with drilldowns.</p>
                    </div>
                </div>
                <div class="info-grid info-grid-compact">
                    <a href="{{ route('rentals.index', ['customer_id' => $customer->id]) }}" class="info-box" style="text-decoration:none;">
                        <span class="label">Total Rentals</span>
                        <div class="value">{{ $customer->rentals_count ?? 0 }}</div>
                    </a>
                    <a href="{{ route('rentals.index', ['customer_id' => $customer->id, 'status' => 'active']) }}" class="info-box" style="text-decoration:none;">
                        <span class="label">Active Rentals</span>
                        <div class="value">{{ $customer->active_rentals_count ?? 0 }}</div>
                    </a>
                    <a href="{{ route('rentals.index', ['customer_id' => $customer->id, 'filter' => 'overdue']) }}" class="info-box" style="text-decoration:none;">
                        <span class="label">Overdue Rentals</span>
                        <div class="value">{{ $customer->overdue_rentals_count ?? 0 }}</div>
                    </a>
                    <a href="{{ route('rentals.index', ['customer_id' => $customer->id, 'status' => 'returned']) }}" class="info-box" style="text-decoration:none;">
                        <span class="label">Returned Rentals</span>
                        <div class="value">{{ $customer->returned_rentals_count ?? 0 }}</div>
                    </a>
                    <a href="{{ route('rentals.index', ['customer_id' => $customer->id, 'filter' => 'ending_soon']) }}" class="info-box" style="text-decoration:none;">
                        <span class="label">Ending Soon</span>
                        <div class="value">{{ $customer->ending_soon_rentals_count ?? 0 }}</div>
                    </a>
                </div>
            </div>
        </div>

        <div class="ops-card span-6">
            <div class="ops-card-body">
                <div class="section-title">
                    <div>
                        <h2>Sales & Invoice Summary</h2>
                        <p>Commercial activity and invoice follow-up at a glance.</p>
                    </div>
                </div>
                <div class="info-grid">
                    <a href="{{ route('sales.index', ['customer_id' => $customer->id]) }}" class="info-box" style="text-decoration:none;">
                        <span class="label">Total Sales</span>
                        <div class="value">{{ $customer->sales_count ?? 0 }}</div>
                    </a>
                    <a href="{{ route('invoices.index', ['customer_id' => $customer->id]) }}" class="info-box" style="text-decoration:none;">
                        <span class="label">Invoice Count</span>
                        <div class="value">{{ $customer->invoices_count ?? 0 }}</div>
                    </a>
                    <a href="{{ route('invoices.index', ['customer_id' => $customer->id]) }}" class="info-box" style="text-decoration:none;">
                        <span class="label">Unpaid Invoices</span>
                        <div class="value">{{ $customer->unpaid_invoices_count ?? 0 }}</div>
                    </a>
                </div>
            </div>
        </div>

        <div class="ops-card span-12">
            <div class="ops-card-body">
                <div class="section-title">
                    <div>
                        <h2>Recent Activity</h2>
                        <p>Recent rentals, delivery or pickup movements, invoices, and payments from this customer.</p>
                    </div>
                </div>

                <div class="profile-grid" style="gap:12px;">
                    <div class="span-6">
                        <div class="mini-list">
                            <div class="mini-item">
                                <div class="mini-head">
                                    <strong>Recent Rentals</strong>
                                    <a href="{{ route('rentals.index', ['customer_id' => $customer->id]) }}" class="chip">View All</a>
                                </div>
                                @forelse($customer->rentals as $rental)
                                    <div style="padding:10px 0; border-top:1px solid #e2e8f0;">
                                        <div class="mini-head" style="margin-bottom:4px;">
                                            <strong>{{ $rental->product->name ?? 'Rental #' . $rental->id }}</strong>
                                            <span class="badge" style="{{ $statusBadge($rental->status) }}">{{ $rental->status }}</span>
                                        </div>
                                        <div class="mini-sub">
                                            {{ optional($rental->start_date)->format('d M Y') }} to {{ optional($rental->end_date)->format('d M Y') }}
                                        </div>
                                        <div class="chip-row" style="margin-top:6px;">
                                            <a href="{{ route('rentals.show', $rental) }}" class="chip">Open</a>
                                            @if($rental->canBeReturned())
                                                <form action="{{ route('rentals.return', $rental) }}" method="POST" style="display:inline-block; margin:0;">
                                                    @csrf
                                                    @method('PUT')
                                                    <button type="submit" class="chip" style="border:none; cursor:pointer;" onclick="return confirm('Mark this rental as returned?');">Return</button>
                                                </form>
                                            @endif
                                        </div>
                                    </div>
                                @empty
                                    <div class="empty-state">No recent rentals available.</div>
                                @endforelse
                            </div>
                        </div>
                    </div>

                    <div class="span-6">
                        <div class="mini-list">
                            <div class="mini-item">
                                <div class="mini-head">
                                    <strong>Recent Deliveries & Pickups</strong>
                                </div>
                                @forelse($deliveryEntries as $rental)
                                    <div style="padding:10px 0; border-top:1px solid #e2e8f0;">
                                        <div class="mini-head" style="margin-bottom:4px;">
                                            <strong>{{ $rental->product->name ?? 'Rental #' . $rental->id }}</strong>
                                            <a href="{{ route('rentals.show', $rental) }}" class="chip">View Rental</a>
                                        </div>
                                        @if($rental->deliveryRecord)
                                            <div class="mini-sub">
                                                Delivery: <span class="badge" style="{{ $statusBadge($rental->deliveryRecord->status) }}">{{ str_replace('_', ' ', $rental->deliveryRecord->status) }}</span>
                                            </div>
                                        @endif
                                        @if($rental->pickupRecord)
                                            <div class="mini-sub" style="margin-top:4px;">
                                                Pickup: <span class="badge" style="{{ $statusBadge($rental->pickupRecord->status) }}">{{ str_replace('_', ' ', $rental->pickupRecord->status) }}</span>
                                            </div>
                                        @endif
                                    </div>
                                @empty
                                    <div class="empty-state">No delivery or pickup activity yet.</div>
                                @endforelse
                            </div>

                            <div class="mini-item">
                                <div class="mini-head">
                                    <strong>Recent Invoices</strong>
                                    <a href="{{ route('invoices.index', ['customer_id' => $customer->id]) }}" class="chip">View All</a>
                                </div>
                                @forelse($customer->invoices as $invoice)
                                    <div style="padding:10px 0; border-top:1px solid #e2e8f0;">
                                        <div class="mini-head" style="margin-bottom:4px;">
                                            <strong>{{ $invoice->invoice_number ?? 'Invoice #' . $invoice->id }}</strong>
                                            <span class="badge" style="{{ $statusBadge($invoice->payment_status ?? 'pending') }}">{{ $invoice->payment_status ?? 'pending' }}</span>
                                        </div>
                                        <div class="mini-sub">
                                            {{ optional($invoice->invoice_date)->format('d M Y') }} | {{ $currency($invoice->total_amount ?? 0) }}
                                        </div>
                                        <div class="chip-row" style="margin-top:6px;">
                                            <a href="{{ route('invoices.show', $invoice->id) }}" class="chip">Open</a>
                                            @if($customerWhatsapp)
                                                <a href="{{ \App\Support\WhatsAppHelper::chatUrl($customerWhatsapp, \App\Support\WhatsAppHelper::invoiceMessage($invoice)) }}" target="_blank" class="chip">WhatsApp</a>
                                            @endif
                                        </div>
                                    </div>
                                @empty
                                    <div class="empty-state">No invoices available.</div>
                                @endforelse
                            </div>

                            <div class="mini-item">
                                <div class="mini-head">
                                    <strong>Recent Payments</strong>
                                </div>
                                @forelse($customer->payments as $payment)
                                    <div style="padding:10px 0; border-top:1px solid #e2e8f0;">
                                        <div class="mini-head" style="margin-bottom:4px;">
                                            <strong>{{ $currency($payment->amount ?? 0) }}</strong>
                                            <span class="badge" style="{{ $statusBadge('paid') }}">{{ $payment->payment_method ?: 'payment' }}</span>
                                        </div>
                                        <div class="mini-sub">
                                            {{ optional($payment->payment_date)->format('d M Y') }} | {{ $payment->rental->product->name ?? 'Rental Payment' }}
                                        </div>
                                    </div>
                                @empty
                                    <div class="empty-state">No payments recorded yet.</div>
                                @endforelse
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="mobile-sticky-actions" aria-label="Customer primary actions">
    @if($customer->phone)
        <a href="tel:{{ preg_replace('/\D+/', '', $customer->phone) }}" class="is-primary">Call</a>
    @endif
    @if($canUpdateCustomers)
        <a href="{{ route('customers.edit', $customer) }}">Edit</a>
    @endif
    @if($canCreateRentals)
        <a href="{{ route('rentals.create', ['customer_id' => $customer->id]) }}">Rental</a>
    @endif
    @if($canCreateSales)
        <a href="{{ route('sales.create', ['customer_id' => $customer->id]) }}">Sale</a>
    @endif
</div>
@endsection
