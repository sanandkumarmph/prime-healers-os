@extends('layouts.app')

@section('content')
@php
    use App\Models\Rental;
    use Illuminate\Support\Str;

    $tabLabels = [
        'due_today' => 'Due Today',
        'next_7_days' => 'Due in Next 7 Days',
        'overdue' => 'Overdue',
        'awaiting_confirmation' => 'Awaiting Confirmation',
        'pickup_requested' => 'Pickup Requested',
        'renewed' => 'Renewed',
        'all' => 'All',
    ];

    $queryWithoutPage = request()->except('page');
    $tabUrl = function (string $tabValue) use ($queryWithoutPage) {
        return route('renewal-center.index', array_filter(array_merge($queryWithoutPage, ['tab' => $tabValue])));
    };

    $clearUrl = route('renewal-center.index', ['tab' => $tab]);
    $currency = fn ($value) => '₹' . number_format((float) $value, 2);
    $activeChipMap = [
        'search' => fn ($value) => 'Search: ' . $value,
        'city' => fn ($value) => 'City: ' . $value,
        'product_id' => fn ($value) => 'Product Filter',
        'staff' => fn ($value) => 'Staff Filter',
        'overdue_days' => fn ($value) => 'Overdue ' . $value . '+ days',
        'payment_status' => fn ($value) => 'Payment: ' . Str::headline((string) $value),
        'delivery_status' => fn ($value) => 'Delivery: ' . Str::headline((string) $value),
        'pickup_status' => fn ($value) => 'Pickup: ' . Str::headline((string) $value),
    ];

    $statusChip = function (Rental $rental) use ($today) {
        $pickupOpen = $rental->pickupRecord && in_array($rental->pickupRecord->status, ['pending', 'in_progress'], true);
        $latestReminder = $rental->latestReminderSentAt();
        $latestRenewal = $rental->renewals->sortByDesc('id')->first();

        if ($pickupOpen) {
            return ['label' => 'Pickup Requested', 'tone' => 'pickup'];
        }

        if ($latestRenewal && $latestRenewal->created_at && $latestRenewal->created_at->gte($today->copy()->subDays(30))) {
            return ['label' => 'Renewed', 'tone' => 'renewed'];
        }

        if ($rental->end_date && $rental->end_date->lt($today)) {
            return ['label' => 'Overdue', 'tone' => 'overdue'];
        }

        if ($rental->end_date && $rental->end_date->isSameDay($today)) {
            return ['label' => 'Due Today', 'tone' => 'today'];
        }

        if ($latestReminder && $rental->end_date && $rental->end_date->lte($today->copy()->addDays(7))) {
            return ['label' => 'Awaiting Confirmation', 'tone' => 'waiting'];
        }

        return ['label' => 'Due Soon', 'tone' => 'soon'];
    };

    $daysLabel = function (Rental $rental) use ($today) {
        if (!$rental->end_date) {
            return 'No renewal date';
        }

        if ($rental->end_date->lt($today)) {
            return $rental->end_date->diffInDays($today) . ' day(s) overdue';
        }

        if ($rental->end_date->isSameDay($today)) {
            return 'Due today';
        }

        return $today->diffInDays($rental->end_date) . ' day(s) remaining';
    };

    $assigneeLabel = function (Rental $rental) {
        $pickupRecord = $rental->pickupRecord;
        $deliveryRecord = $rental->deliveryRecord;
        $record = $pickupRecord && in_array($pickupRecord->status, ['pending', 'in_progress', 'completed'], true)
            ? $pickupRecord
            : $deliveryRecord;

        if (!$record) {
            return 'Unassigned';
        }

        if ($record->assignedUser) {
            return $record->assignedUser->name;
        }

        if ($record->assignedStaff) {
            return $record->assignedStaff->name;
        }

        return 'Unassigned';
    };

    $paymentStatusLabel = function (Rental $rental) {
        $latestRenewal = $rental->renewals->sortByDesc('id')->first();

        if ($latestRenewal) {
            return Str::headline($latestRenewal->paymentWorkflowStatus());
        }

        if ($rental->invoice) {
            return Str::headline((string) ($rental->invoice->payment_status ?? $rental->invoice->status ?? 'pending'));
        }

        return $rental->outstandingBalance() > 0 ? 'Pending' : 'Paid';
    };

    $currentInvoice = function (Rental $rental) {
        $latestRenewal = $rental->renewals->sortByDesc('id')->first();

        return $latestRenewal?->invoice ?: $rental->invoice;
    };
@endphp

<style>
    .renewal-center-shell {
        display: grid;
        gap: 12px;
        max-width: 1320px;
        margin: 0 auto;
    }
    .renewal-hero,
    .renewal-card {
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        background: #fff;
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05);
    }
    .renewal-hero {
        padding: 14px;
        display: grid;
        gap: 10px;
        background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
    }
    .renewal-hero-top {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 12px;
        flex-wrap: wrap;
    }
    .renewal-eyebrow {
        font-size: 11px;
        font-weight: 700;
        letter-spacing: .08em;
        text-transform: uppercase;
        color: #2563eb;
    }
    .renewal-title {
        margin: 5px 0 3px;
        font-size: clamp(24px, 2.4vw, 30px);
        line-height: 1.04;
        letter-spacing: -.04em;
        color: #0f172a;
    }
    .renewal-copy {
        max-width: 780px;
        color: #475569;
        font-size: 12px;
        line-height: 1.5;
    }
    .renewal-stat-strip {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 8px;
    }
    .renewal-stat {
        border: 1px solid #dbe7f5;
        border-radius: 14px;
        padding: 10px 11px;
        background: #f8fbff;
        display: grid;
        gap: 3px;
    }
    .renewal-stat-label {
        font-size: 10px;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: .05em;
        font-weight: 800;
    }
    .renewal-stat-value {
        font-size: 23px;
        font-weight: 800;
        color: #0f172a;
        letter-spacing: -.04em;
    }
    .renewal-tabs {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }
    .renewal-tab {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 7px 11px;
        border-radius: 999px;
        border: 1px solid #dbe7f5;
        background: #fff;
        color: #334155;
        text-decoration: none;
        font-weight: 800;
        font-size: 11px;
    }
    .renewal-tab.is-active {
        background: #0f172a;
        border-color: #0f172a;
        color: #fff;
        box-shadow: 0 14px 26px rgba(15, 23, 42, 0.18);
    }
    .renewal-tab-count {
        min-width: 22px;
        height: 22px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 999px;
        background: rgba(15, 23, 42, 0.08);
        font-size: 12px;
        padding: 0 6px;
    }
    .renewal-tab.is-active .renewal-tab-count {
        background: rgba(255, 255, 255, 0.18);
    }
    .renewal-card {
        padding: 14px;
        display: grid;
        gap: 10px;
    }
    .renewal-toolbar,
    .renewal-filter-grid,
    .renewal-list-actions {
        display: grid;
        gap: 10px;
    }
    .renewal-toolbar {
        grid-template-columns: minmax(0, 1.4fr) minmax(220px, .8fr) auto auto;
        align-items: end;
    }
    .renewal-field {
        display: grid;
        gap: 6px;
    }
    .renewal-field label {
        font-size: 11px;
        font-weight: 700;
        color: #475569;
        text-transform: uppercase;
        letter-spacing: .05em;
    }
    .renewal-input,
    .renewal-select,
    .renewal-textarea {
        width: 100%;
        border-radius: 14px;
        border: 1px solid #d7e1ec;
        background: #fff;
        padding: 11px 13px;
        color: #0f172a;
        font-size: 14px;
    }
    .renewal-textarea {
        min-height: 150px;
        resize: vertical;
    }
    .renewal-btn,
    .renewal-btn-secondary,
    .renewal-btn-ghost {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        padding: 11px 14px;
        border-radius: 14px;
        border: 1px solid transparent;
        text-decoration: none;
        font-weight: 700;
        font-size: 14px;
        cursor: pointer;
    }
    .renewal-btn {
        background: #0f172a;
        color: #fff;
        box-shadow: 0 14px 26px rgba(15, 23, 42, 0.18);
    }
    .renewal-btn-secondary {
        background: #fff;
        border-color: #d7e1ec;
        color: #0f172a;
    }
    .renewal-btn-ghost {
        background: #f8fafc;
        border-color: #e2e8f0;
        color: #334155;
    }
    .renewal-filter-grid {
        grid-template-columns: repeat(6, minmax(0, 1fr));
        align-items: end;
    }
    .renewal-meta-strip {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 8px;
    }
    .renewal-chip {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 6px 10px;
        border-radius: 999px;
        background: #eff6ff;
        border: 1px solid #bfdbfe;
        color: #1d4ed8;
        font-size: 12px;
        font-weight: 700;
    }
    .renewal-list {
        overflow: hidden;
        border-radius: 18px;
        border: 1px solid #e2e8f0;
    }
    .renewal-table {
        width: 100%;
        border-collapse: collapse;
        background: #fff;
    }
    .renewal-table th,
    .renewal-table td {
        padding: 14px 12px;
        border-bottom: 1px solid #edf2f7;
        text-align: left;
        vertical-align: top;
    }
    .renewal-table th {
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: .06em;
        color: #64748b;
        background: #f8fafc;
    }
    .renewal-row-title {
        font-weight: 800;
        color: #0f172a;
        letter-spacing: -.02em;
    }
    .renewal-row-copy {
        margin-top: 4px;
        color: #475569;
        font-size: 13px;
        line-height: 1.5;
    }
    .renewal-muted {
        color: #64748b;
        font-size: 12px;
    }
    .renewal-status {
        display: inline-flex;
        align-items: center;
        padding: 6px 10px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 800;
        border: 1px solid transparent;
        white-space: nowrap;
    }
    .renewal-status.is-today { background: #fff7ed; color: #c2410c; border-color: #fdba74; }
    .renewal-status.is-overdue { background: #fef2f2; color: #b91c1c; border-color: #fca5a5; }
    .renewal-status.is-soon { background: #eff6ff; color: #1d4ed8; border-color: #bfdbfe; }
    .renewal-status.is-waiting { background: #fefce8; color: #a16207; border-color: #fde68a; }
    .renewal-status.is-pickup { background: #eef2ff; color: #4338ca; border-color: #c7d2fe; }
    .renewal-status.is-renewed { background: #ecfdf5; color: #047857; border-color: #86efac; }
    .renewal-action-set {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }
    .renewal-action-set .renewal-btn-secondary,
    .renewal-action-set .renewal-btn-ghost {
        padding: 8px 11px;
        font-size: 12px;
    }
    .renewal-mobile-list {
        display: none;
        gap: 12px;
    }
    .renewal-mobile-card {
        border: 1px solid #e2e8f0;
        border-radius: 18px;
        background: #fff;
        padding: 14px;
        display: grid;
        gap: 10px;
    }
    .renewal-mobile-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
    }
    .renewal-mobile-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }
    .renewal-empty {
        border: 1px dashed #cbd5e1;
        border-radius: 18px;
        padding: 30px 18px;
        text-align: center;
        color: #64748b;
    }
    .renewal-pagination {
        display: flex;
        justify-content: flex-end;
    }
    .renewal-modal-backdrop[hidden] {
        display: none !important;
    }
    .renewal-modal-backdrop {
        position: fixed;
        inset: 0;
        z-index: 70;
        background: rgba(15, 23, 42, 0.55);
        display: flex;
        align-items: flex-end;
        justify-content: center;
        padding: 18px;
    }
    .renewal-modal {
        width: min(760px, 100%);
        max-height: min(88vh, 900px);
        border-radius: 24px;
        background: #fff;
        box-shadow: 0 28px 60px rgba(15, 23, 42, 0.22);
        display: grid;
        grid-template-rows: auto 1fr auto;
        overflow: hidden;
    }
    .renewal-modal-header,
    .renewal-modal-footer {
        padding: 16px 18px;
        border-bottom: 1px solid #e2e8f0;
        background: #fff;
    }
    .renewal-modal-footer {
        border-bottom: 0;
        border-top: 1px solid #e2e8f0;
        display: flex;
        justify-content: space-between;
        gap: 10px;
        flex-wrap: wrap;
    }
    .renewal-modal-body {
        overflow: auto;
        padding: 18px;
        display: grid;
        gap: 14px;
        background: #f8fafc;
    }
    .renewal-modal-title {
        margin: 0;
        font-size: 24px;
        letter-spacing: -.03em;
        color: #0f172a;
    }
    .renewal-summary-strip {
        display: grid;
        gap: 6px;
        padding: 12px 14px;
        border-radius: 16px;
        background: #eef6ff;
        border: 1px solid #bfdbfe;
    }
    .renewal-helper {
        color: #64748b;
        font-size: 13px;
    }
    .renewal-modal-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
    }
    .renewal-section-card {
        border: 1px solid #e2e8f0;
        border-radius: 18px;
        background: #fff;
        padding: 14px;
        display: grid;
        gap: 12px;
    }
    @media (max-width: 1024px) {
        .renewal-toolbar,
        .renewal-filter-grid,
        .renewal-stat-strip,
        .renewal-modal-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }
    @media (max-width: 768px) {
        .renewal-hero,
        .renewal-card {
            border-radius: 18px;
            padding: 14px;
        }
        .renewal-stat-strip,
        .renewal-toolbar,
        .renewal-filter-grid,
        .renewal-modal-grid,
        .renewal-mobile-grid {
            grid-template-columns: 1fr;
        }
        .renewal-list {
            display: none;
        }
        .renewal-mobile-list {
            display: grid;
        }
        .renewal-modal-backdrop {
            padding: 0;
        }
        .renewal-modal {
            width: 100%;
            max-height: 100vh;
            border-radius: 22px 22px 0 0;
        }
        .renewal-pagination {
            justify-content: center;
        }
    }
</style>

<div class="renewal-center-shell">
    <section class="renewal-hero">
        <div class="renewal-hero-top">
            <div>
                <div class="renewal-eyebrow">Phase 1B</div>
                <h1 class="renewal-title">Renewal Center</h1>
                <p class="renewal-copy">Keep every renewal in one place so the team can quickly decide whether to follow up, renew, or schedule pickup without losing track of reminder contact versus service location.</p>
            </div>
            <div class="renewal-meta-strip">
                <span class="renewal-chip">Reminder contact follows billing logic</span>
                <span class="renewal-chip">Service location follows delivery logic</span>
            </div>
        </div>

        <div class="renewal-stat-strip">
            <div class="renewal-stat">
                <span class="renewal-stat-label">Due Today</span>
                <strong class="renewal-stat-value">{{ number_format((int) ($counts['due_today'] ?? 0)) }}</strong>
            </div>
            <div class="renewal-stat">
                <span class="renewal-stat-label">Due This Week</span>
                <strong class="renewal-stat-value">{{ number_format((int) ($counts['next_7_days'] ?? 0)) }}</strong>
            </div>
            <div class="renewal-stat">
                <span class="renewal-stat-label">Overdue</span>
                <strong class="renewal-stat-value">{{ number_format((int) ($counts['overdue'] ?? 0)) }}</strong>
            </div>
            <div class="renewal-stat">
                <span class="renewal-stat-label">Pickup Requested</span>
                <strong class="renewal-stat-value">{{ number_format((int) ($counts['pickup_requested'] ?? 0)) }}</strong>
            </div>
        </div>

        <div class="renewal-tabs">
            @foreach($tabLabels as $tabValue => $tabLabel)
                <a href="{{ $tabUrl($tabValue) }}" class="renewal-tab {{ $tab === $tabValue ? 'is-active' : '' }}">
                    <span>{{ $tabLabel }}</span>
                    <span class="renewal-tab-count">{{ number_format((int) ($counts[$tabValue] ?? 0)) }}</span>
                </a>
            @endforeach
        </div>
    </section>

    <section class="renewal-card">
        <form method="GET" action="{{ route('renewal-center.index') }}" class="renewal-toolbar">
            <input type="hidden" name="tab" value="{{ $tab }}">
            <div class="renewal-field">
                <label for="renewal-search">Search</label>
                <input
                    id="renewal-search"
                    type="text"
                    name="search"
                    value="{{ request('search') }}"
                    class="renewal-input"
                    placeholder="Search rental number, customer, partner, client, phone, or product"
                >
            </div>
            <div class="renewal-field">
                <label for="renewal-sort-by">Sort By</label>
                <select id="renewal-sort-by" name="sort_by" class="renewal-select">
                    <option value="action_priority" @selected($sortBy === 'action_priority')>Action Priority</option>
                    <option value="renewal_date" @selected($sortBy === 'renewal_date')>Renewal Date</option>
                    <option value="status" @selected($sortBy === 'status')>Status</option>
                    <option value="customer" @selected($sortBy === 'customer')>Customer / Partner</option>
                    <option value="product" @selected($sortBy === 'product')>Product</option>
                    <option value="amount" @selected($sortBy === 'amount')>Rental Amount</option>
                    <option value="updated_at" @selected($sortBy === 'updated_at')>Recently Updated</option>
                </select>
            </div>
            <div class="renewal-field">
                <label for="renewal-sort-dir">Direction</label>
                <select id="renewal-sort-dir" name="sort_dir" class="renewal-select">
                    <option value="asc" @selected($sortDir === 'asc')>Ascending</option>
                    <option value="desc" @selected($sortDir === 'desc')>Descending</option>
                </select>
            </div>
            <div class="renewal-list-actions">
                <button type="submit" class="renewal-btn">Apply</button>
                <a href="{{ $clearUrl }}" class="renewal-btn-secondary">Clear Filters</a>
            </div>

            <div class="renewal-filter-grid" style="grid-column:1 / -1;">
                <div class="renewal-field">
                    <label for="renewal-city">City</label>
                    <input id="renewal-city" type="text" name="city" value="{{ request('city') }}" class="renewal-input" placeholder="Delivery city">
                </div>
                <div class="renewal-field">
                    <label for="renewal-product">Product</label>
                    <select id="renewal-product" name="product_id" class="renewal-select">
                        <option value="">All Products</option>
                        @foreach($products as $product)
                            <option value="{{ $product->id }}" @selected((int) request('product_id') === (int) $product->id)>{{ $product->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="renewal-field">
                    <label for="renewal-staff">Assigned Staff</label>
                    <select id="renewal-staff" name="staff" class="renewal-select">
                        <option value="">Any Staff</option>
                        @if($assignableUsers->isNotEmpty())
                            <optgroup label="Users">
                                @foreach($assignableUsers as $user)
                                    <option value="user:{{ $user->id }}" @selected(request('staff') === 'user:' . $user->id)>{{ $user->name }}</option>
                                @endforeach
                            </optgroup>
                        @endif
                        @if($assignableStaff->isNotEmpty())
                            <optgroup label="Staff / Vendors">
                                @foreach($assignableStaff as $staffMember)
                                    <option value="staff:{{ $staffMember->id }}" @selected(request('staff') === 'staff:' . $staffMember->id)>{{ $staffMember->name }}</option>
                                @endforeach
                            </optgroup>
                        @endif
                    </select>
                </div>
                <div class="renewal-field">
                    <label for="renewal-overdue-days">Overdue Days</label>
                    <select id="renewal-overdue-days" name="overdue_days" class="renewal-select">
                        <option value="">Any</option>
                        <option value="1" @selected((int) request('overdue_days') === 1)>1+ day</option>
                        <option value="3" @selected((int) request('overdue_days') === 3)>3+ days</option>
                        <option value="7" @selected((int) request('overdue_days') === 7)>7+ days</option>
                    </select>
                </div>
                <div class="renewal-field">
                    <label for="renewal-payment-status">Payment Status</label>
                    <select id="renewal-payment-status" name="payment_status" class="renewal-select">
                        <option value="">Any</option>
                        <option value="paid" @selected(request('payment_status') === 'paid')>Paid</option>
                        <option value="partial" @selected(request('payment_status') === 'partial')>Partial</option>
                        <option value="unpaid" @selected(request('payment_status') === 'unpaid')>Unpaid</option>
                        <option value="overdue" @selected(request('payment_status') === 'overdue')>Overdue</option>
                    </select>
                </div>
                <div class="renewal-field">
                    <label for="renewal-pickup-status">Pickup Status</label>
                    <select id="renewal-pickup-status" name="pickup_status" class="renewal-select">
                        <option value="">Any</option>
                        <option value="pending" @selected(request('pickup_status') === 'pending')>Pending</option>
                        <option value="in_progress" @selected(request('pickup_status') === 'in_progress')>In Progress</option>
                        <option value="completed" @selected(request('pickup_status') === 'completed')>Completed</option>
                        <option value="cancelled" @selected(request('pickup_status') === 'cancelled')>Cancelled</option>
                    </select>
                </div>
            </div>
        </form>

        @if($activeFilters->isNotEmpty())
            <div class="renewal-meta-strip">
                <span class="renewal-chip">Filters Active</span>
                @foreach($activeFilters as $key => $value)
                    <span class="renewal-chip">{{ $activeChipMap[$key]($value) }}</span>
                @endforeach
            </div>
        @endif

        @if($rentals->count() > 0)
            <div class="renewal-list">
                <table class="renewal-table">
                    <thead>
                        <tr>
                            <th>Rental</th>
                            <th>Renewal Window</th>
                            <th>Contacts</th>
                            <th>Amounts</th>
                            <th>Ops Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rentals as $rental)
                            @php
                                $chip = $statusChip($rental);
                                $invoice = $currentInvoice($rental);
                                $reminderNumber = $rental->getAttribute('renewal_reminder_number');
                                $reminderMessage = $rental->getAttribute('renewal_reminder_message');
                                $whatsAppUrl = $reminderNumber ? \App\Support\WhatsAppHelper::chatUrl($reminderNumber, $reminderMessage) : null;
                                $pickupOpen = $rental->pickupRecord && in_array($rental->pickupRecord->status, ['pending', 'in_progress'], true);
                            @endphp
                            <tr>
                                <td>
                                    <div class="renewal-row-title">Rental #{{ $rental->id }}</div>
                                    <div class="renewal-row-copy">{{ $rental->product->name ?? 'Product' }}</div>
                                    <div class="renewal-muted" style="margin-top:4px;">{{ $rental->usesBusinessPartnerFlow() ? ($rental->businessPartner?->displayName() ?? 'Business Partner') : ($rental->customer?->displayName() ?? $rental->customer_name) }}</div>
                                    @if($rental->usesBusinessPartnerFlow())
                                        <div class="renewal-muted">Actual Client: {{ $rental->partnerClient?->displayName() ?? 'Not linked' }}</div>
                                    @endif
                                </td>
                                <td>
                                    <span class="renewal-status is-{{ $chip['tone'] }}">{{ $chip['label'] }}</span>
                                    <div class="renewal-row-copy" style="margin-top:8px;">
                                        Renewal Date: {{ optional($rental->end_date)->format('d M Y') ?: 'N/A' }}
                                    </div>
                                    <div class="renewal-muted">{{ $daysLabel($rental) }}</div>
                                    @if($rental->latestReminderSentAt())
                                        <div class="renewal-muted" style="margin-top:8px;">Last reminder {{ $rental->latestReminderSentAt()->format('d M Y h:i A') }}</div>
                                    @endif
                                </td>
                                <td>
                                    <div class="renewal-row-copy">
                                        <strong>Reminder To:</strong> {{ $rental->reminderContactName() }} • {{ $rental->reminderContactPhone() ?: 'No phone' }}
                                    </div>
                                    <div class="renewal-row-copy">
                                        <strong>Service Location:</strong> {{ $rental->deliveryContactName() }} • {{ $rental->deliveryContactPhone() ?: 'No phone' }}
                                    </div>
                                    <div class="renewal-muted">{{ collect([$rental->deliveryContactAddress(), $rental->deliveryContactCity(), $rental->deliveryContactState(), $rental->deliveryContactPincode()])->filter()->join(', ') ?: 'No address' }}</div>
                                    @if($rental->deliveryContactMapUrl())
                                        <div style="margin-top:8px;">
                                            <a href="{{ $rental->deliveryContactMapUrl() }}" target="_blank" class="renewal-btn-ghost">Open Map</a>
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    <div class="renewal-row-copy">Rental {{ $currency($rental->rental_amount ?? 0) }}</div>
                                    <div class="renewal-muted">Deposit {{ $currency($rental->deposit_amount ?? 0) }}</div>
                                    <div class="renewal-muted">Payment {{ $paymentStatusLabel($rental) }}</div>
                                    @if($invoice)
                                        <div class="renewal-muted">Invoice {{ $invoice->invoice_number }}</div>
                                    @endif
                                </td>
                                <td>
                                    <div class="renewal-row-copy">Delivery {{ Str::headline((string) ($rental->deliveryRecord->status ?? 'pending')) }}</div>
                                    <div class="renewal-row-copy">Pickup {{ $rental->pickupRecord ? Str::headline((string) $rental->pickupRecord->status) : 'Not scheduled' }}</div>
                                    <div class="renewal-muted">Assigned {{ $assigneeLabel($rental) }}</div>
                                </td>
                                <td>
                                    <div class="renewal-action-set">
                                        <button
                                            type="button"
                                            class="renewal-btn-secondary"
                                            data-reminder-trigger
                                            data-action="{{ route('renewal-center.mark-reminder-sent', $rental) }}"
                                            data-title="Send Reminder for Rental #{{ $rental->id }}"
                                            data-reminder-name="{{ $rental->reminderContactName() }}"
                                            data-reminder-phone="{{ $rental->reminderContactPhone() }}"
                                            data-client-name="{{ $rental->deliveryContactName() }}"
                                            data-product-name="{{ $rental->product->name ?? 'Rental item' }}"
                                            data-renewal-date="{{ optional($rental->end_date)->format('d M Y') }}"
                                            data-amount="{{ $currency($rental->rental_amount ?? 0) }}"
                                            data-message="{{ $reminderMessage }}"
                                            data-whatsapp-url="{{ $whatsAppUrl }}"
                                        >Send Reminder</button>

                                        <button
                                            type="button"
                                            class="renewal-btn-secondary"
                                            data-renew-trigger
                                            data-action="{{ route('rentals.renew', $rental) }}"
                                            data-title="Mark Rental #{{ $rental->id }} Renewed"
                                            data-current-end="{{ optional($rental->end_date)->format('d M Y') }}"
                                            data-new-end="{{ optional($rental->end_date)->copy()?->addDays($rental->suggestedRenewalDays())->format('Y-m-d') }}"
                                            data-rental-amount="{{ number_format($rental->suggestedRenewalAmount(), 2, '.', '') }}"
                                        >Mark Renewed</button>

                                        @if(!$pickupOpen)
                                            <button
                                                type="button"
                                                class="renewal-btn-secondary"
                                                data-pickup-trigger
                                                data-action="{{ route('renewal-center.schedule-pickup', $rental) }}"
                                                data-title="Schedule Pickup for Rental #{{ $rental->id }}"
                                                data-pickup-date="{{ optional($rental->end_date)->format('Y-m-d') ?: now()->toDateString() }}"
                                                data-pickup-address="{{ collect([$rental->deliveryContactAddress(), $rental->deliveryContactCity(), $rental->deliveryContactState(), $rental->deliveryContactPincode()])->filter()->join(', ') }}"
                                                data-pickup-notes="{{ $rental->deliveryContactNotes() ?? '' }}"
                                            >Schedule Pickup</button>
                                        @endif

                                        @if($rental->reminderContactPhone())
                                            <a href="tel:{{ preg_replace('/\s+/', '', $rental->reminderContactPhone()) }}" class="renewal-btn-ghost">Call</a>
                                        @endif
                                        @if($whatsAppUrl)
                                            <a href="{{ $whatsAppUrl }}" target="_blank" class="renewal-btn-ghost">WhatsApp</a>
                                        @endif
                                        <a href="{{ route('rentals.show', $rental) }}" class="renewal-btn-ghost">View Rental</a>
                                        @if($invoice)
                                            <a href="{{ route('invoices.show', $invoice) }}" class="renewal-btn-ghost">View Invoice</a>
                                        @endif
                                        @if($pickupOpen)
                                            <a href="{{ route('deliveries.show', $rental->pickupRecord) }}" class="renewal-btn-ghost">Pickup Task</a>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="renewal-mobile-list">
                @foreach($rentals as $rental)
                    @php
                        $chip = $statusChip($rental);
                        $invoice = $currentInvoice($rental);
                        $reminderNumber = $rental->getAttribute('renewal_reminder_number');
                        $reminderMessage = $rental->getAttribute('renewal_reminder_message');
                        $whatsAppUrl = $reminderNumber ? \App\Support\WhatsAppHelper::chatUrl($reminderNumber, $reminderMessage) : null;
                        $pickupOpen = $rental->pickupRecord && in_array($rental->pickupRecord->status, ['pending', 'in_progress'], true);
                    @endphp
                    <article class="renewal-mobile-card">
                        <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:10px;">
                            <div>
                                <div class="renewal-row-title">Rental #{{ $rental->id }}</div>
                                <div class="renewal-row-copy">{{ $rental->product->name ?? 'Product' }}</div>
                            </div>
                            <span class="renewal-status is-{{ $chip['tone'] }}">{{ $chip['label'] }}</span>
                        </div>

                        <div class="renewal-mobile-grid">
                            <div>
                                <div class="renewal-muted">Renewal Date</div>
                                <div class="renewal-row-copy">{{ optional($rental->end_date)->format('d M Y') ?: 'N/A' }}</div>
                            </div>
                            <div>
                                <div class="renewal-muted">Window</div>
                                <div class="renewal-row-copy">{{ $daysLabel($rental) }}</div>
                            </div>
                            <div>
                                <div class="renewal-muted">Reminder To</div>
                                <div class="renewal-row-copy">{{ $rental->reminderContactName() }}</div>
                            </div>
                            <div>
                                <div class="renewal-muted">Service Location</div>
                                <div class="renewal-row-copy">{{ $rental->deliveryContactName() }}</div>
                            </div>
                        </div>

                        <div class="renewal-muted">{{ collect([$rental->deliveryContactAddress(), $rental->deliveryContactCity(), $rental->deliveryContactState(), $rental->deliveryContactPincode()])->filter()->join(', ') ?: 'No address' }}</div>

                        <div class="renewal-mobile-actions">
                            <button
                                type="button"
                                class="renewal-btn-secondary"
                                data-reminder-trigger
                                data-action="{{ route('renewal-center.mark-reminder-sent', $rental) }}"
                                data-title="Send Reminder for Rental #{{ $rental->id }}"
                                data-reminder-name="{{ $rental->reminderContactName() }}"
                                data-reminder-phone="{{ $rental->reminderContactPhone() }}"
                                data-client-name="{{ $rental->deliveryContactName() }}"
                                data-product-name="{{ $rental->product->name ?? 'Rental item' }}"
                                data-renewal-date="{{ optional($rental->end_date)->format('d M Y') }}"
                                data-amount="{{ $currency($rental->rental_amount ?? 0) }}"
                                data-message="{{ $reminderMessage }}"
                                data-whatsapp-url="{{ $whatsAppUrl }}"
                            >Send Reminder</button>
                            <button
                                type="button"
                                class="renewal-btn-secondary"
                                data-renew-trigger
                                data-action="{{ route('rentals.renew', $rental) }}"
                                data-title="Mark Rental #{{ $rental->id }} Renewed"
                                data-current-end="{{ optional($rental->end_date)->format('d M Y') }}"
                                data-new-end="{{ optional($rental->end_date)->copy()?->addDays($rental->suggestedRenewalDays())->format('Y-m-d') }}"
                                data-rental-amount="{{ number_format($rental->suggestedRenewalAmount(), 2, '.', '') }}"
                            >Mark Renewed</button>
                            @if(!$pickupOpen)
                                <button
                                    type="button"
                                    class="renewal-btn-secondary"
                                    data-pickup-trigger
                                    data-action="{{ route('renewal-center.schedule-pickup', $rental) }}"
                                    data-title="Schedule Pickup for Rental #{{ $rental->id }}"
                                    data-pickup-date="{{ optional($rental->end_date)->format('Y-m-d') ?: now()->toDateString() }}"
                                    data-pickup-address="{{ collect([$rental->deliveryContactAddress(), $rental->deliveryContactCity(), $rental->deliveryContactState(), $rental->deliveryContactPincode()])->filter()->join(', ') }}"
                                    data-pickup-notes="{{ $rental->deliveryContactNotes() ?? '' }}"
                                >Schedule Pickup</button>
                            @endif
                            <a href="{{ route('rentals.show', $rental) }}" class="renewal-btn-ghost">View Rental</a>
                            @if($invoice)
                                <a href="{{ route('invoices.show', $invoice) }}" class="renewal-btn-ghost">View Invoice</a>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>

            <div class="renewal-pagination">{{ $rentals->links() }}</div>
        @else
            <div class="renewal-empty">
                <strong>No rentals match this renewal view right now.</strong>
                <div style="margin-top:6px;">Try another tab or clear filters to widen the queue.</div>
            </div>
        @endif
    </section>
</div>

<div class="renewal-modal-backdrop" id="renewalReminderModal" hidden>
    <div class="renewal-modal" role="dialog" aria-modal="true" aria-labelledby="renewalReminderTitle">
        <div class="renewal-modal-header">
            <h2 class="renewal-modal-title" id="renewalReminderTitle">Send Reminder</h2>
        </div>
        <div class="renewal-modal-body">
            <div class="renewal-summary-strip">
                <div><strong>Reminder To:</strong> <span data-reminder-contact></span></div>
                <div><strong>Client:</strong> <span data-reminder-client></span></div>
                <div><strong>Product:</strong> <span data-reminder-product></span></div>
                <div><strong>Renewal Date:</strong> <span data-reminder-date></span> • <strong>Amount:</strong> <span data-reminder-amount></span></div>
            </div>
            <div class="renewal-section-card">
                <div class="renewal-helper">Preview the message, then copy it or open WhatsApp. Mark it sent once the reminder is actually dispatched.</div>
                <textarea class="renewal-textarea" readonly data-reminder-message></textarea>
            </div>
        </div>
        <div class="renewal-modal-footer">
            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                <button type="button" class="renewal-btn-secondary" data-copy-reminder>Copy Message</button>
                <a href="#" target="_blank" class="renewal-btn" data-reminder-whatsapp>Open WhatsApp</a>
            </div>
            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                <form method="POST" data-reminder-form>
                    @csrf
                    <button type="submit" class="renewal-btn-secondary">Mark Reminder Sent</button>
                </form>
                <button type="button" class="renewal-btn-ghost" data-close-modal>Cancel</button>
            </div>
        </div>
    </div>
</div>

<div class="renewal-modal-backdrop" id="renewalMarkModal" hidden>
    <div class="renewal-modal" role="dialog" aria-modal="true" aria-labelledby="renewalMarkTitle">
        <div class="renewal-modal-header">
            <h2 class="renewal-modal-title" id="renewalMarkTitle">Mark Renewed</h2>
        </div>
        <form method="POST" data-renew-form class="renewal-modal" style="box-shadow:none; width:100%; max-height:none; border-radius:0; background:transparent; grid-template-rows:1fr auto;">
            @csrf
            <input type="hidden" name="renewal_mode" value="custom">
            <input type="hidden" name="payment_amount" data-renew-payment-amount value="0">
            <input type="hidden" name="payment_date" value="{{ now()->toDateString() }}">
            <input type="hidden" name="return_to_renewal_center" value="1">
            <div class="renewal-modal-body">
                <div class="renewal-summary-strip">
                    <div><strong>Current Renewal Date:</strong> <span data-renew-current-end></span></div>
                    <div class="renewal-helper">This uses the existing rental renewal logic and keeps invoice/payment workflows unchanged.</div>
                </div>
                <div class="renewal-section-card">
                    <div class="renewal-modal-grid">
                        <div class="renewal-field">
                            <label for="renewal-new-end-date">New Renewal Date</label>
                            <input id="renewal-new-end-date" type="date" name="new_end_date" class="renewal-input" data-renew-new-end required>
                        </div>
                        <div class="renewal-field">
                            <label for="renewal-rental-amount">Rental Amount</label>
                            <input id="renewal-rental-amount" type="number" step="0.01" min="0" name="rental_amount_added" class="renewal-input" data-renew-rental-amount required>
                        </div>
                        <div class="renewal-field">
                            <label for="renewal-payment-status">Payment Status</label>
                            <select id="renewal-payment-status" class="renewal-select" data-renew-payment-status>
                                <option value="pending">Pending</option>
                                <option value="paid">Paid</option>
                            </select>
                        </div>
                        <div class="renewal-field">
                            <label for="renewal-note">Note</label>
                            <input id="renewal-note" type="text" name="notes" class="renewal-input" placeholder="Optional note for this renewal">
                        </div>
                    </div>
                </div>
            </div>
            <div class="renewal-modal-footer">
                <button type="button" class="renewal-btn-ghost" data-close-modal>Cancel</button>
                <button type="submit" class="renewal-btn">Save Renewal</button>
            </div>
        </form>
    </div>
</div>

<div class="renewal-modal-backdrop" id="renewalPickupModal" hidden>
    <div class="renewal-modal" role="dialog" aria-modal="true" aria-labelledby="renewalPickupTitle">
        <div class="renewal-modal-header">
            <h2 class="renewal-modal-title" id="renewalPickupTitle">Schedule Pickup</h2>
        </div>
        <form method="POST" data-pickup-form class="renewal-modal" style="box-shadow:none; width:100%; max-height:none; border-radius:0; background:transparent; grid-template-rows:1fr auto;">
            @csrf
            <div class="renewal-modal-body">
                <div class="renewal-summary-strip">
                    <div><strong>Pickup Address:</strong> <span data-pickup-address></span></div>
                    <div class="renewal-helper">This creates a pickup task in Tasks Board with the same delivery contact and location logic already used by rentals.</div>
                </div>
                <div class="renewal-section-card">
                    <div class="renewal-modal-grid">
                        <div class="renewal-field">
                            <label for="pickup-date">Pickup Date</label>
                            <input id="pickup-date" type="date" name="pickup_date" class="renewal-input" data-pickup-date required>
                        </div>
                        <div class="renewal-field">
                            <label for="pickup-slot">Pickup Time Slot</label>
                            <select id="pickup-slot" name="pickup_time_slot" class="renewal-select">
                                <option value="">Flexible</option>
                                <option value="09:00-12:00">09:00 - 12:00</option>
                                <option value="12:00-15:00">12:00 - 15:00</option>
                                <option value="15:00-18:00">15:00 - 18:00</option>
                                <option value="18:00-20:00">18:00 - 20:00</option>
                            </select>
                        </div>
                        <div class="renewal-field">
                            <label for="pickup-assignment">Assign To</label>
                            <select id="pickup-assignment" name="assignment_target" class="renewal-select">
                                <option value="">Leave Unassigned</option>
                                @if($assignableUsers->isNotEmpty())
                                    <optgroup label="Users">
                                        @foreach($assignableUsers as $user)
                                            <option value="user:{{ $user->id }}">{{ $user->name }}</option>
                                        @endforeach
                                    </optgroup>
                                @endif
                                @if($assignableStaff->isNotEmpty())
                                    <optgroup label="Staff / Vendors">
                                        @foreach($assignableStaff as $staffMember)
                                            <option value="staff:{{ $staffMember->id }}">{{ $staffMember->name }}</option>
                                        @endforeach
                                    </optgroup>
                                @endif
                            </select>
                        </div>
                        <div class="renewal-field" style="grid-column:1 / -1;">
                            <label for="pickup-notes">Pickup Notes</label>
                            <textarea id="pickup-notes" name="pickup_notes" class="renewal-textarea" data-pickup-notes placeholder="Add pickup notes, instructions, or follow-up context"></textarea>
                        </div>
                    </div>
                </div>
            </div>
            <div class="renewal-modal-footer">
                <button type="button" class="renewal-btn-ghost" data-close-modal>Cancel</button>
                <button type="submit" class="renewal-btn">Create Pickup Task</button>
            </div>
        </form>
    </div>
</div>

<script>
    (() => {
        const closeButtons = Array.from(document.querySelectorAll('[data-close-modal]'));
        const modals = Array.from(document.querySelectorAll('.renewal-modal-backdrop'));

        const closeModal = (modal) => {
            if (!modal) return;
            modal.hidden = true;
        };

        const openModal = (modal) => {
            if (!modal) return;
            modal.hidden = false;
        };

        closeButtons.forEach((button) => {
            button.addEventListener('click', () => closeModal(button.closest('.renewal-modal-backdrop')));
        });

        modals.forEach((modal) => {
            modal.addEventListener('click', (event) => {
                if (event.target === modal) {
                    closeModal(modal);
                }
            });
        });

        const reminderModal = document.getElementById('renewalReminderModal');
        const reminderForm = reminderModal?.querySelector('[data-reminder-form]');
        const reminderMessage = reminderModal?.querySelector('[data-reminder-message]');
        const reminderWhatsApp = reminderModal?.querySelector('[data-reminder-whatsapp]');

        document.querySelectorAll('[data-reminder-trigger]').forEach((button) => {
            button.addEventListener('click', () => {
                reminderModal.querySelector('#renewalReminderTitle').textContent = button.dataset.title || 'Send Reminder';
                reminderModal.querySelector('[data-reminder-contact]').textContent = `${button.dataset.reminderName || ''} • ${button.dataset.reminderPhone || 'No phone'}`;
                reminderModal.querySelector('[data-reminder-client]').textContent = button.dataset.clientName || '-';
                reminderModal.querySelector('[data-reminder-product]').textContent = button.dataset.productName || '-';
                reminderModal.querySelector('[data-reminder-date]').textContent = button.dataset.renewalDate || '-';
                reminderModal.querySelector('[data-reminder-amount]').textContent = button.dataset.amount || '-';
                reminderMessage.value = button.dataset.message || '';
                reminderWhatsApp.href = button.dataset.whatsappUrl || '#';
                reminderWhatsApp.style.pointerEvents = button.dataset.whatsappUrl ? 'auto' : 'none';
                reminderWhatsApp.style.opacity = button.dataset.whatsappUrl ? '1' : '.5';
                reminderForm.action = button.dataset.action;
                openModal(reminderModal);
            });
        });

        reminderModal?.querySelector('[data-copy-reminder]')?.addEventListener('click', async () => {
            if (!reminderMessage?.value) return;

            try {
                await navigator.clipboard.writeText(reminderMessage.value);
            } catch (error) {
                reminderMessage.focus();
                reminderMessage.select();
                document.execCommand('copy');
            }
        });

        const renewModal = document.getElementById('renewalMarkModal');
        const renewForm = renewModal?.querySelector('[data-renew-form]');
        const renewPaymentStatus = renewModal?.querySelector('[data-renew-payment-status]');
        const renewPaymentAmount = renewModal?.querySelector('[data-renew-payment-amount]');
        const renewRentalAmount = renewModal?.querySelector('[data-renew-rental-amount]');

        const syncRenewPaymentAmount = () => {
            if (!renewPaymentStatus || !renewPaymentAmount || !renewRentalAmount) return;
            renewPaymentAmount.value = renewPaymentStatus.value === 'paid' ? (renewRentalAmount.value || '0') : '0';
        };

        renewPaymentStatus?.addEventListener('change', syncRenewPaymentAmount);
        renewRentalAmount?.addEventListener('input', syncRenewPaymentAmount);

        document.querySelectorAll('[data-renew-trigger]').forEach((button) => {
            button.addEventListener('click', () => {
                renewModal.querySelector('#renewalMarkTitle').textContent = button.dataset.title || 'Mark Renewed';
                renewModal.querySelector('[data-renew-current-end]').textContent = button.dataset.currentEnd || '-';
                renewModal.querySelector('[data-renew-new-end]').value = button.dataset.newEnd || '';
                renewModal.querySelector('[data-renew-rental-amount]').value = button.dataset.rentalAmount || '0';
                renewForm.action = button.dataset.action;
                renewPaymentStatus.value = 'pending';
                syncRenewPaymentAmount();
                openModal(renewModal);
            });
        });

        const pickupModal = document.getElementById('renewalPickupModal');
        const pickupForm = pickupModal?.querySelector('[data-pickup-form]');

        document.querySelectorAll('[data-pickup-trigger]').forEach((button) => {
            button.addEventListener('click', () => {
                pickupModal.querySelector('#renewalPickupTitle').textContent = button.dataset.title || 'Schedule Pickup';
                pickupModal.querySelector('[data-pickup-address]').textContent = button.dataset.pickupAddress || 'No address captured.';
                pickupModal.querySelector('[data-pickup-date]').value = button.dataset.pickupDate || '';
                pickupModal.querySelector('[data-pickup-notes]').value = button.dataset.pickupNotes || '';
                pickupForm.action = button.dataset.action;
                openModal(pickupModal);
            });
        });
    })();
</script>
@endsection
