@extends('layouts.app')

@section('content')
@php
    use App\Models\Delivery;
    use Illuminate\Support\Str;

    $tabs = [
        'pickup_requested' => 'Pickup Requested',
        'scheduled_today' => 'Scheduled Today',
        'upcoming' => 'Upcoming',
        'overdue' => 'Overdue',
        'failed_attempt' => 'Failed Attempt',
        'picked_up' => 'Picked Up',
        'all' => 'All',
    ];

    $statusTone = function (?string $status): string {
        return match ($status) {
            'requested' => 'background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe;',
            'scheduled' => 'background:#ecfeff;color:#0f766e;border-color:#99f6e4;',
            'assigned' => 'background:#eef2ff;color:#4338ca;border-color:#c7d2fe;',
            'in_progress' => 'background:#fef3c7;color:#b45309;border-color:#fcd34d;',
            'picked_up' => 'background:#dcfce7;color:#166534;border-color:#86efac;',
            'failed_attempt' => 'background:#fee2e2;color:#b91c1c;border-color:#fecaca;',
            'rescheduled' => 'background:#f5f3ff;color:#6d28d9;border-color:#ddd6fe;',
            'cancelled' => 'background:#e2e8f0;color:#334155;border-color:#cbd5e1;',
            default => 'background:#f8fafc;color:#475569;border-color:#e2e8f0;',
        };
    };

    $statusSubtitle = function ($pickup) use ($today): string {
        if (in_array($pickup->status, ['completed', 'cancelled'], true)) {
            return ucfirst(str_replace('_', ' ', $pickup->status));
        }

        if ($pickup->scheduled_at?->isPast() && !$pickup->scheduled_at?->isToday()) {
            return $pickup->scheduled_at->diffInDays($today) . ' day(s) overdue';
        }

        if ($pickup->scheduled_at?->isToday()) {
            return 'Due today';
        }

        if ($pickup->scheduled_at?->isFuture()) {
            return $pickup->scheduled_at->diffInDays($today) . ' day(s) remaining';
        }

        return 'Awaiting schedule';
    };

    $phoneLink = function (?string $phone): ?string {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        return $digits ? 'tel:' . $digits : null;
    };

    $waLink = function (?string $phone, string $message = ''): ?string {
        $digits = \App\Support\WhatsAppHelper::normalizeNumber($phone);
        if (!$digits) {
            return null;
        }

        $text = trim($message);

        return 'https://wa.me/' . $digits . ($text !== '' ? '?text=' . urlencode($text) : '');
    };

    $workflowHref = fn ($pickup) => route('deliveries.show', $pickup) . '#workflow-proof-section';
    $clearFilterHref = route('pickup-center.index', ['tab' => $tab]);
@endphp

<style>
    .pickup-center-page { display:grid; gap:18px; padding:22px 0 28px; }
    .pickup-center-shell,
    .pickup-center-card {
        border:1px solid #dbe3ef;
        border-radius:20px;
        background:#fff;
        box-shadow:0 16px 38px rgba(15,23,42,.05);
    }
    .pickup-center-shell { padding:18px; display:grid; gap:16px; }
    .pickup-center-head { display:flex; justify-content:space-between; gap:16px; align-items:flex-start; flex-wrap:wrap; }
    .pickup-center-head h1 { margin:0; font-size:28px; color:#0f172a; }
    .pickup-center-head p { margin:6px 0 0; color:#64748b; font-size:13px; max-width:760px; }
    .pickup-tab-row { display:flex; gap:10px; flex-wrap:wrap; }
    .pickup-tab {
        display:inline-flex; align-items:center; gap:8px; min-height:40px; padding:8px 14px;
        border-radius:999px; border:1px solid #dbe3ef; background:#f8fafc; color:#334155;
        text-decoration:none; font-size:12px; font-weight:800;
    }
    .pickup-tab.is-active { background:#0f172a; border-color:#0f172a; color:#fff; }
    .pickup-tab-count {
        display:inline-flex; align-items:center; justify-content:center; min-width:22px; height:22px;
        padding:0 6px; border-radius:999px; background:rgba(255,255,255,.18); color:inherit; font-size:11px;
    }
    .pickup-search-row { display:grid; grid-template-columns:minmax(0,1.5fr) repeat(4, minmax(140px, .7fr)) auto; gap:10px; }
    .pickup-input, .pickup-select, .pickup-textarea {
        width:100%; min-height:44px; border:1px solid #dbe3ef; border-radius:14px; background:#fff;
        color:#0f172a; padding:10px 12px; font-size:14px; box-sizing:border-box;
    }
    .pickup-textarea { min-height:96px; resize:vertical; }
    .pickup-input:focus, .pickup-select:focus, .pickup-textarea:focus {
        outline:none; border-color:#93c5fd; box-shadow:0 0 0 4px rgba(37,99,235,.10);
    }
    .pickup-form-actions { display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
    .pickup-btn, .pickup-btn-soft, .pickup-btn-ghost {
        display:inline-flex; align-items:center; justify-content:center; gap:8px; min-height:42px;
        padding:10px 14px; border-radius:14px; text-decoration:none; border:1px solid transparent;
        font-size:13px; font-weight:800; cursor:pointer;
    }
    .pickup-btn { background:#2563eb; color:#fff; }
    .pickup-btn-soft { background:#eef2ff; color:#3730a3; border-color:#c7d2fe; }
    .pickup-btn-ghost { background:#fff; color:#334155; border-color:#dbe3ef; }
    .pickup-active-filters { display:flex; gap:8px; flex-wrap:wrap; }
    .pickup-filter-chip {
        display:inline-flex; align-items:center; padding:6px 10px; border-radius:999px;
        background:#f8fafc; border:1px solid #dbe3ef; color:#475569; font-size:12px; font-weight:700;
    }
    .pickup-list { display:grid; gap:14px; }
    .pickup-center-card { padding:16px; display:grid; gap:14px; }
    .pickup-card-top { display:grid; grid-template-columns:minmax(0,1.15fr) minmax(0,1fr) auto; gap:16px; align-items:start; }
    .pickup-card-main { display:grid; gap:8px; }
    .pickup-card-main h2 { margin:0; font-size:18px; color:#0f172a; }
    .pickup-card-line { color:#475569; font-size:13px; }
    .pickup-card-strong { color:#0f172a; font-weight:800; }
    .pickup-card-subtle { color:#64748b; font-size:12px; line-height:1.55; }
    .pickup-status-chip {
        display:inline-flex; align-items:center; padding:6px 10px; border-radius:999px;
        border:1px solid transparent; font-size:11px; font-weight:800; letter-spacing:.04em; text-transform:uppercase;
    }
    .pickup-card-side { display:grid; gap:8px; }
    .pickup-summary-strip {
        display:grid; gap:8px; padding:12px 14px; border-radius:16px; background:#f8fafc; border:1px solid #e5edf7;
    }
    .pickup-summary-row { display:grid; gap:4px; }
    .pickup-summary-label { font-size:11px; font-weight:800; letter-spacing:.05em; text-transform:uppercase; color:#64748b; }
    .pickup-summary-value { font-size:13px; color:#0f172a; font-weight:700; }
    .pickup-card-actions { display:flex; gap:8px; flex-wrap:wrap; justify-content:flex-end; }
    .pickup-card-actions > * { min-width:0; }
    .pickup-card-actions .pickup-btn,
    .pickup-card-actions .pickup-btn-soft,
    .pickup-card-actions .pickup-btn-ghost { min-height:40px; }
    .pickup-kv { display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:10px; }
    .pickup-kv-item {
        padding:12px 13px; border-radius:14px; background:#fbfdff; border:1px solid #e5edf7; display:grid; gap:4px;
    }
    .pickup-kv-item span { font-size:11px; color:#64748b; font-weight:800; text-transform:uppercase; letter-spacing:.05em; }
    .pickup-kv-item strong { font-size:13px; color:#0f172a; line-height:1.45; }
    .pickup-empty { padding:28px 18px; text-align:center; color:#64748b; }
    .pickup-empty strong { display:block; color:#0f172a; font-size:18px; margin-bottom:6px; }
    .pickup-modal[hidden] { display:none; }
    .pickup-modal {
        position:fixed; inset:0; z-index:1200; background:rgba(15,23,42,.45);
        display:flex; align-items:center; justify-content:center; padding:18px;
    }
    .pickup-modal-dialog {
        width:min(100%, 560px); max-height:calc(100vh - 36px); overflow:auto;
        border-radius:20px; background:#fff; border:1px solid #dbe3ef; box-shadow:0 28px 60px rgba(15,23,42,.24);
    }
    .pickup-modal-head, .pickup-modal-foot { position:sticky; background:#fff; z-index:2; }
    .pickup-modal-head { top:0; padding:18px 18px 14px; border-bottom:1px solid #eef2f7; display:flex; justify-content:space-between; gap:12px; align-items:flex-start; }
    .pickup-modal-head h3 { margin:0; font-size:18px; color:#0f172a; }
    .pickup-modal-head p { margin:6px 0 0; color:#64748b; font-size:12px; }
    .pickup-modal-body { padding:18px; display:grid; gap:14px; }
    .pickup-modal-grid { display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:12px; }
    .pickup-modal-field { display:grid; gap:6px; }
    .pickup-modal-field label { font-size:12px; font-weight:800; color:#334155; }
    .pickup-modal-foot { bottom:0; padding:14px 18px 18px; border-top:1px solid #eef2f7; display:flex; justify-content:flex-end; gap:10px; flex-wrap:wrap; }
    .pickup-close {
        display:inline-flex; align-items:center; justify-content:center; width:36px; height:36px;
        border-radius:999px; border:1px solid #dbe3ef; background:#fff; color:#475569; cursor:pointer;
    }
    .pickup-card-paywarn {
        display:inline-flex; align-items:center; padding:5px 9px; border-radius:999px;
        background:#fef3c7; color:#92400e; font-size:11px; font-weight:800;
    }
    @media (max-width: 960px) {
        .pickup-search-row { grid-template-columns:repeat(2, minmax(0,1fr)); }
        .pickup-card-top { grid-template-columns:1fr; }
        .pickup-card-actions { justify-content:flex-start; }
        .pickup-kv { grid-template-columns:repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 640px) {
        .pickup-center-page { gap:12px; padding:10px 0 20px; }
        .pickup-center-shell, .pickup-center-card { border-radius:18px; }
        .pickup-center-shell { padding:14px; }
        .pickup-center-head h1 { font-size:24px; }
        .pickup-search-row, .pickup-modal-grid, .pickup-kv { grid-template-columns:1fr; }
        .pickup-form-actions { display:grid; grid-template-columns:1fr 1fr; }
        .pickup-form-actions > * { width:100%; }
        .pickup-card-actions {
            display:grid;
            grid-template-columns:repeat(2, minmax(0, 1fr));
        }
        .pickup-card-actions > * { width:100%; }
        .pickup-modal { padding:0; align-items:stretch; }
        .pickup-modal-dialog { width:100%; max-height:100vh; border-radius:0; }
    }
</style>

<div class="container pickup-center-page">
    <section class="pickup-center-shell">
        <div class="pickup-center-head">
            <div>
                <h1>Pickup Center</h1>
                <p>Central pickup queue for recovery, failed attempts, reschedules, and return-verification handoff.</p>
            </div>
            <div class="pickup-form-actions">
                <a href="{{ route('renewal-center.index', ['tab' => 'pickup_requested']) }}" class="pickup-btn-ghost">Open Renewal Queue</a>
                <a href="{{ route('deliveries.index', ['tab' => 'pickups', 'task_type' => 'pickup']) }}" class="pickup-btn-ghost">Open Tasks Board</a>
            </div>
        </div>

        <div class="pickup-tab-row">
            @foreach($tabs as $tabKey => $tabLabel)
                <a href="{{ route('pickup-center.index', array_merge(request()->except('page'), ['tab' => $tabKey])) }}" class="pickup-tab {{ $tab === $tabKey ? 'is-active' : '' }}">
                    <span>{{ $tabLabel }}</span>
                    <span class="pickup-tab-count">{{ number_format((int) ($counts[$tabKey] ?? 0)) }}</span>
                </a>
            @endforeach
        </div>

        <form method="GET" action="{{ route('pickup-center.index') }}" class="pickup-search-row">
            <input type="hidden" name="tab" value="{{ $tab }}">
            <input type="hidden" name="sort_by" value="{{ $sortBy }}">
            <input type="hidden" name="sort_dir" value="{{ $sortDirection }}">
            <input class="pickup-input" type="search" name="search" value="{{ request('search') }}" placeholder="Search rental, customer, business partner, actual client, phone, product, serial">
            <input class="pickup-input" type="text" name="city" value="{{ request('city') }}" placeholder="City">
            <input class="pickup-input" type="text" name="product" value="{{ request('product') }}" placeholder="Product">
            <select class="pickup-select" name="staff">
                <option value="">All staff</option>
                <option value="unassigned" @selected(request('staff') === 'unassigned')>Unassigned</option>
                @foreach($assignableUsers as $user)
                    <option value="user:{{ $user->id }}" @selected(request('staff') === 'user:' . $user->id)>{{ $user->name }} ({{ Str::headline($user->role ?: 'user') }})</option>
                @endforeach
                @foreach($assignableStaff as $staffMember)
                    <option value="staff:{{ $staffMember->id }}" @selected(request('staff') === 'staff:' . $staffMember->id)>{{ $staffMember->name }} (Vendor/Partner)</option>
                @endforeach
            </select>
            <select class="pickup-select" name="pickup_status">
                <option value="">All pickup states</option>
                @foreach(Delivery::PICKUP_STATUSES as $pickupState)
                    <option value="{{ $pickupState }}" @selected(request('pickup_status') === $pickupState)>{{ Str::headline($pickupState) }}</option>
                @endforeach
            </select>
            <div class="pickup-form-actions">
                <button class="pickup-btn" type="submit">Apply</button>
                <a href="{{ $clearFilterHref }}" class="pickup-btn-ghost">Clear</a>
            </div>
        </form>

        @if(!empty($activeFilters))
            <div class="pickup-active-filters" aria-label="Active pickup filters">
                @foreach($activeFilters as $chip)
                    <span class="pickup-filter-chip">{{ $chip }}</span>
                @endforeach
            </div>
        @endif
    </section>

    <section class="pickup-list">
        @forelse($pickups as $pickup)
            @php
                $rental = $pickup->rental;
                $mapUrl = $pickup->linkedCustomerMapUrl();
                $phone = $pickup->linkedCustomerPhone();
                $reminderPhone = $pickup->reminderContactPhone();
                $invoice = $rental?->invoice;
                $status = $pickup->pickupOperationalStatus();
                $workflowLabel = $pickup->status === 'in_progress' ? 'Mark Picked Up' : ($pickup->status === 'completed' ? 'View Proof' : 'Start Pickup');
                $workflowTarget = $pickup->status === 'completed'
                    ? route('deliveries.show', $pickup) . '#delivery-proof-history'
                    : $workflowHref($pickup);
                $whatsAppMessage = trim(collect([
                    'Pickup reminder for rental #' . ($rental?->id ?? $pickup->rental_id),
                    'Product: ' . ($rental?->product?->name ?? 'Rental product'),
                    $pickup->linkedCustomerAddress() ? 'Address: ' . $pickup->linkedCustomerAddress() : null,
                ])->filter()->implode("\n"));
            @endphp
            <article class="pickup-center-card">
                <div class="pickup-card-top">
                    <div class="pickup-card-main">
                        <div style="display:flex; flex-wrap:wrap; gap:8px; align-items:center;">
                            <h2>Pickup #{{ $pickup->id }}</h2>
                            <span class="pickup-status-chip" style="{{ $statusTone($status) }}">{{ $pickup->pickupOperationalLabel() }}</span>
                            @if($pickup->paymentPending())
                                <span class="pickup-card-paywarn">Payment Pending</span>
                            @endif
                        </div>
                        <div class="pickup-card-line">
                            <span class="pickup-card-strong">Rental #{{ $pickup->rental_id }}</span>
                            <span class="pickup-card-subtle">• {{ $rental?->product?->name ?? 'Rental product' }}</span>
                        </div>
                        <div class="pickup-card-subtle">{{ $statusSubtitle($pickup) }}</div>
                        @if($pickup->lastPickupNote())
                            <div class="pickup-card-subtle">Last note: {{ $pickup->lastPickupNote() }}</div>
                        @endif
                        @if($pickup->failed_attempt_reason)
                            <div class="pickup-card-subtle">Failed reason: {{ Delivery::failedAttemptReasonLabel($pickup->failed_attempt_reason) }}</div>
                        @endif
                    </div>

                    <div class="pickup-summary-strip">
                        <div class="pickup-summary-row">
                            <span class="pickup-summary-label">Pickup From</span>
                            <span class="pickup-summary-value">{{ $pickup->linkedCustomerName() }} • {{ $pickup->linkedCustomerPhone() ?: 'No phone' }}</span>
                        </div>
                        <div class="pickup-summary-row">
                            <span class="pickup-summary-label">Reminder / Payment</span>
                            <span class="pickup-summary-value">{{ $pickup->reminderContactName() }} • {{ $reminderPhone ?: 'No phone' }}</span>
                        </div>
                        <div class="pickup-summary-row">
                            <span class="pickup-summary-label">Address</span>
                            <span class="pickup-summary-value">{{ $pickup->linkedCustomerAddress() ?: 'No pickup address saved' }}</span>
                        </div>
                    </div>

                    <div class="pickup-card-actions">
                        @if($phoneLink($phone))
                            <a href="{{ $phoneLink($phone) }}" class="pickup-btn-soft">Call</a>
                        @endif
                        @if($waLink($phone, $whatsAppMessage))
                            <a href="{{ $waLink($phone, $whatsAppMessage) }}" target="_blank" rel="noopener" class="pickup-btn-soft">WhatsApp</a>
                        @endif
                        @if($mapUrl)
                            <a href="{{ $mapUrl }}" target="_blank" rel="noopener" class="pickup-btn-soft">Open Map</a>
                        @endif
                        <a href="{{ $workflowTarget }}" class="pickup-btn">{{ $workflowLabel }}</a>
                        @if($pickup->status !== 'completed' && $pickup->status !== 'cancelled')
                            <button type="button" class="pickup-btn-ghost" data-open-modal="assign-{{ $pickup->id }}">Assign Staff</button>
                            <button type="button" class="pickup-btn-ghost" data-open-modal="failed-{{ $pickup->id }}">Failed Attempt</button>
                            <button type="button" class="pickup-btn-ghost" data-open-modal="reschedule-{{ $pickup->id }}">Reschedule</button>
                            <button type="button" class="pickup-btn-ghost" data-open-modal="note-{{ $pickup->id }}">Add Note</button>
                        @endif
                        <a href="{{ route('rentals.show', $rental) }}" class="pickup-btn-ghost">View Rental</a>
                        @if($invoice)
                            <a href="{{ route('invoices.show', $invoice) }}" class="pickup-btn-ghost">View Invoice</a>
                        @endif
                    </div>
                </div>

                <div class="pickup-kv">
                    <div class="pickup-kv-item">
                        <span>Scheduled</span>
                        <strong>{{ $pickup->scheduled_at ? $pickup->scheduled_at->format('d M Y h:i A') : 'Not scheduled yet' }}</strong>
                    </div>
                    <div class="pickup-kv-item">
                        <span>Assigned Staff</span>
                        <strong>{{ $pickup->assignedUser?->name ?? $pickup->assignedStaff?->name ?? $pickup->third_party_name ?? 'Unassigned' }}</strong>
                    </div>
                    <div class="pickup-kv-item">
                        <span>Delivery / Pickup Status</span>
                        <strong>{{ $pickup->pickupOperationalLabel() }}</strong>
                    </div>
                    <div class="pickup-kv-item">
                        <span>Invoice / Payment</span>
                        <strong>{{ $invoice ? Str::headline((string) $invoice->payment_status) : 'No invoice linked' }}</strong>
                    </div>
                </div>

                @foreach([
                    'assign' => ['title' => 'Assign Pickup', 'description' => 'Set pickup date, time, and delivery/pickup staff.', 'route' => route('pickup-center.assign', $pickup)],
                    'failed' => ['title' => 'Record Failed Attempt', 'description' => 'Capture the failed reason and optional reschedule date.', 'route' => route('pickup-center.failed-attempt', $pickup)],
                    'reschedule' => ['title' => 'Reschedule Pickup', 'description' => 'Move the pickup to a new date or time slot.', 'route' => route('pickup-center.reschedule', $pickup)],
                    'note' => ['title' => 'Add Pickup Note', 'description' => 'Store the latest office or field note for this pickup.', 'route' => route('pickup-center.notes', $pickup)],
                ] as $modalKey => $modalConfig)
                    <div class="pickup-modal" data-pickup-modal="{{ $modalKey === 'assign' ? 'assign-' . $pickup->id : ($modalKey === 'failed' ? 'failed-' . $pickup->id : ($modalKey === 'reschedule' ? 'reschedule-' . $pickup->id : 'note-' . $pickup->id)) }}" hidden>
                        <div class="pickup-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="pickup-modal-title-{{ $modalKey }}-{{ $pickup->id }}">
                            <div class="pickup-modal-head">
                                <div>
                                    <h3 id="pickup-modal-title-{{ $modalKey }}-{{ $pickup->id }}">{{ $modalConfig['title'] }}</h3>
                                    <p>{{ $modalConfig['description'] }}</p>
                                </div>
                                <button type="button" class="pickup-close" data-close-modal>&times;</button>
                            </div>
                            <form method="POST" action="{{ $modalConfig['route'] }}">
                                @csrf
                                <div class="pickup-modal-body">
                                    @if($modalKey === 'assign')
                                        <div class="pickup-modal-grid">
                                            <div class="pickup-modal-field">
                                                <label>Pickup Date</label>
                                                <input class="pickup-input" type="date" name="pickup_date" value="{{ optional($pickup->scheduled_at)->toDateString() }}">
                                            </div>
                                            <div class="pickup-modal-field">
                                                <label>Time Slot</label>
                                                <select class="pickup-select" name="pickup_time_slot">
                                                    <option value="">Select time slot</option>
                                                    @foreach(['09:00-12:00', '12:00-15:00', '15:00-18:00', '18:00-20:00'] as $slot)
                                                        <option value="{{ $slot }}">{{ $slot }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                        </div>
                                        <div class="pickup-modal-field">
                                            <label>Assign To</label>
                                            <select class="pickup-select" name="assignment_target">
                                                <option value="">Choose staff</option>
                                                @foreach($assignableUsers as $user)
                                                    <option value="user:{{ $user->id }}">{{ $user->name }} ({{ Str::headline($user->role ?: 'user') }})</option>
                                                @endforeach
                                                @foreach($assignableStaff as $staffMember)
                                                    <option value="staff:{{ $staffMember->id }}">{{ $staffMember->name }} (Vendor/Partner)</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="pickup-modal-field">
                                            <label>Notes</label>
                                            <textarea class="pickup-textarea" name="notes" placeholder="Assignment notes, landmark, or customer availability note"></textarea>
                                        </div>
                                    @elseif($modalKey === 'failed')
                                        <div class="pickup-modal-field">
                                            <label>Reason</label>
                                            <select class="pickup-select" name="failed_attempt_reason">
                                                @foreach($failedReasonOptions as $reason)
                                                    <option value="{{ $reason }}">{{ Delivery::failedAttemptReasonLabel($reason) }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="pickup-modal-field">
                                            <label>Note</label>
                                            <textarea class="pickup-textarea" name="failed_attempt_note" placeholder="Explain what blocked the pickup"></textarea>
                                        </div>
                                        <div class="pickup-modal-grid">
                                            <div class="pickup-modal-field">
                                                <label>Reschedule Date</label>
                                                <input class="pickup-input" type="date" name="reschedule_date">
                                            </div>
                                            <div class="pickup-modal-field">
                                                <label>Reschedule Slot</label>
                                                <select class="pickup-select" name="pickup_time_slot">
                                                    <option value="">Keep unscheduled</option>
                                                    @foreach(['09:00-12:00', '12:00-15:00', '15:00-18:00', '18:00-20:00'] as $slot)
                                                        <option value="{{ $slot }}">{{ $slot }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                        </div>
                                    @elseif($modalKey === 'reschedule')
                                        <div class="pickup-modal-grid">
                                            <div class="pickup-modal-field">
                                                <label>New Pickup Date</label>
                                                <input class="pickup-input" type="date" name="pickup_date" value="{{ optional($pickup->scheduled_at)->toDateString() }}">
                                            </div>
                                            <div class="pickup-modal-field">
                                                <label>New Time Slot</label>
                                                <select class="pickup-select" name="pickup_time_slot">
                                                    <option value="">Select time slot</option>
                                                    @foreach(['09:00-12:00', '12:00-15:00', '15:00-18:00', '18:00-20:00'] as $slot)
                                                        <option value="{{ $slot }}">{{ $slot }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                        </div>
                                        <div class="pickup-modal-field">
                                            <label>Assigned Staff (optional)</label>
                                            <select class="pickup-select" name="assignment_target">
                                                <option value="">Keep current assignment</option>
                                                @foreach($assignableUsers as $user)
                                                    <option value="user:{{ $user->id }}">{{ $user->name }} ({{ Str::headline($user->role ?: 'user') }})</option>
                                                @endforeach
                                                @foreach($assignableStaff as $staffMember)
                                                    <option value="staff:{{ $staffMember->id }}">{{ $staffMember->name }} (Vendor/Partner)</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="pickup-modal-field">
                                            <label>Reason / Note</label>
                                            <textarea class="pickup-textarea" name="reason" placeholder="Reason for reschedule or updated customer note"></textarea>
                                        </div>
                                    @else
                                        <div class="pickup-modal-field">
                                            <label>Latest Pickup Note</label>
                                            <textarea class="pickup-textarea" name="note" placeholder="Add office follow-up, payment note, or field instruction"></textarea>
                                        </div>
                                    @endif
                                </div>
                                <div class="pickup-modal-foot">
                                    <button type="button" class="pickup-btn-ghost" data-close-modal>Cancel</button>
                                    <button type="submit" class="pickup-btn">
                                        {{ $modalKey === 'assign' ? 'Save Assignment' : ($modalKey === 'failed' ? 'Save Failed Attempt' : ($modalKey === 'reschedule' ? 'Save Reschedule' : 'Save Note')) }}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                @endforeach
            </article>
        @empty
            <div class="pickup-center-card pickup-empty">
                <strong>No pickup tasks in this queue</strong>
                <span>Try another tab or clear filters to see more pickup work.</span>
            </div>
        @endforelse
    </section>

    {{ $pickups->links() }}
</div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const openModal = (key) => {
            const modal = document.querySelector(`[data-pickup-modal="${key}"]`);
            if (!modal) return;
            modal.hidden = false;
            document.body.style.overflow = 'hidden';
        };

        const closeModal = (modal) => {
            modal.hidden = true;
            document.body.style.overflow = '';
        };

        document.querySelectorAll('[data-open-modal]').forEach((button) => {
            button.addEventListener('click', () => openModal(button.dataset.openModal));
        });

        document.querySelectorAll('[data-pickup-modal]').forEach((modal) => {
            modal.addEventListener('click', (event) => {
                if (event.target === modal) {
                    closeModal(modal);
                }
            });

            modal.querySelectorAll('[data-close-modal]').forEach((button) => {
                button.addEventListener('click', () => closeModal(modal));
            });
        });

        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') return;
            document.querySelectorAll('[data-pickup-modal]').forEach((modal) => {
                if (!modal.hidden) {
                    closeModal(modal);
                }
            });
        });
    });
</script>
@endsection
