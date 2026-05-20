@extends('layouts.app')

@section('content')
@php
    use App\Models\FollowUp;
    use Illuminate\Support\Str;

    $tabs = [
        'pending' => 'Pending Follow-ups',
        'today' => 'Today',
        'overdue' => 'Overdue',
        'renewals' => 'Renewals',
        'payments' => 'Payments',
        'pickups' => 'Pickups',
        'delivery' => 'Delivery',
        'notes' => 'Notes',
        'all' => 'All Communication',
    ];

    $statusTone = function (string $status): string {
        return match ($status) {
            FollowUp::STATUS_OVERDUE => 'background:#fee2e2;color:#b91c1c;border-color:#fecaca;',
            FollowUp::STATUS_COMPLETED => 'background:#dcfce7;color:#166534;border-color:#86efac;',
            FollowUp::STATUS_CANCELLED => 'background:#e2e8f0;color:#334155;border-color:#cbd5e1;',
            default => 'background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe;',
        };
    };

    $priorityTone = function (?string $priority): string {
        return match ($priority) {
            FollowUp::PRIORITY_URGENT => 'background:#7f1d1d;color:#fff;',
            FollowUp::PRIORITY_HIGH => 'background:#fee2e2;color:#b91c1c;',
            FollowUp::PRIORITY_MEDIUM => 'background:#fef3c7;color:#b45309;',
            default => 'background:#ecfeff;color:#0f766e;',
        };
    };

    $callHref = fn (?string $phone) => $phone ? 'tel:' . preg_replace('/\D+/', '', $phone) : null;
    $tabsWithCounts = collect($tabs)->map(function ($label, $key) use ($counts) {
        return ['key' => $key, 'label' => $label, 'count' => (int) ($counts[$key] ?? 0)];
    });
    $clearHref = route('communication-center.index', ['tab' => $tab]);
@endphp

<style>
    .comms-page { display:grid; gap:18px; padding:22px 0 28px; }
    .comms-shell,
    .comms-card {
        border:1px solid #dbe3ef;
        border-radius:20px;
        background:#fff;
        box-shadow:0 16px 38px rgba(15,23,42,.05);
    }
    .comms-shell { padding:18px; display:grid; gap:16px; }
    .comms-head { display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap; }
    .comms-head h1 { margin:0; font-size:28px; color:#0f172a; }
    .comms-head p { margin:6px 0 0; color:#64748b; font-size:13px; max-width:760px; }
    .comms-actions { display:flex; gap:10px; flex-wrap:wrap; }
    .comms-tab-row { display:flex; gap:10px; flex-wrap:wrap; }
    .comms-tab {
        display:inline-flex; align-items:center; gap:8px; min-height:40px; padding:8px 14px;
        border-radius:999px; border:1px solid #dbe3ef; background:#f8fafc; color:#334155;
        text-decoration:none; font-size:12px; font-weight:800;
    }
    .comms-tab.is-active { background:#0f172a; border-color:#0f172a; color:#fff; }
    .comms-tab-count {
        display:inline-flex; align-items:center; justify-content:center; min-width:22px; height:22px;
        padding:0 6px; border-radius:999px; background:rgba(255,255,255,.18); color:inherit; font-size:11px;
    }
    .comms-search-row { display:grid; grid-template-columns:minmax(0,1.7fr) repeat(2, minmax(150px, .7fr)) auto; gap:10px; }
    .comms-input,
    .comms-select,
    .comms-textarea {
        width:100%; min-height:44px; box-sizing:border-box; border:1px solid #dbe3ef; border-radius:14px;
        background:#fff; color:#0f172a; padding:10px 12px; font-size:14px;
    }
    .comms-textarea { min-height:96px; resize:vertical; }
    .comms-btn, .comms-btn-soft, .comms-btn-ghost {
        display:inline-flex; align-items:center; justify-content:center; gap:8px; min-height:42px;
        padding:10px 14px; border-radius:14px; text-decoration:none; border:1px solid transparent;
        font-size:13px; font-weight:800; cursor:pointer; font-family:inherit;
    }
    .comms-btn { background:#2563eb; color:#fff; }
    .comms-btn-soft { background:#eef2ff; color:#3730a3; border-color:#c7d2fe; }
    .comms-btn-ghost { background:#fff; color:#334155; border-color:#dbe3ef; }
    .comms-active-filters { display:flex; gap:8px; flex-wrap:wrap; }
    .comms-chip {
        display:inline-flex; align-items:center; padding:6px 10px; border-radius:999px;
        background:#f8fafc; border:1px solid #dbe3ef; color:#475569; font-size:12px; font-weight:700;
    }
    .comms-list { display:grid; gap:14px; }
    .comms-card { padding:16px; display:grid; gap:14px; }
    .comms-card-top { display:grid; grid-template-columns:minmax(0,1fr) auto; gap:16px; align-items:start; }
    .comms-card-main { display:grid; gap:8px; min-width:0; }
    .comms-card-headline { display:flex; flex-wrap:wrap; gap:8px; align-items:center; min-width:0; }
    .comms-card-main h2 { margin:0; font-size:18px; color:#0f172a; }
    .comms-card-line { color:#475569; font-size:13px; min-width:0; overflow-wrap:anywhere; }
    .comms-card-subtle { color:#64748b; font-size:12px; line-height:1.55; overflow-wrap:anywhere; }
    .comms-status-chip, .comms-priority-chip {
        display:inline-flex; align-items:center; padding:6px 10px; border-radius:999px;
        border:1px solid transparent; font-size:11px; font-weight:800; letter-spacing:.04em; text-transform:uppercase;
    }
    .comms-priority-chip { border:none; }
    .comms-card-actions { display:flex; gap:8px; flex-wrap:wrap; justify-content:flex-end; align-items:flex-start; }
    .comms-card-contacts { display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:12px; }
    .comms-contact-block,
    .comms-kv-item {
        display:grid; gap:6px; min-width:0; padding:12px 14px; border-radius:16px; background:#f8fafc; border:1px solid #e5edf7;
    }
    .comms-contact-label,
    .comms-kv-item span {
        font-size:11px; font-weight:800; letter-spacing:.05em; text-transform:uppercase; color:#64748b;
    }
    .comms-contact-value,
    .comms-kv-item strong {
        font-size:13px; color:#0f172a; font-weight:700; line-height:1.55; overflow-wrap:anywhere;
    }
    .comms-contact-link {
        display:inline-flex; align-items:center; gap:6px; color:#2563eb; font-size:12px; font-weight:800; text-decoration:none; width:max-content; max-width:100%;
    }
    .comms-kv { display:grid; grid-template-columns:repeat(4, minmax(0,1fr)); gap:10px; }
    .comms-more { position:relative; }
    .comms-more summary { list-style:none; }
    .comms-more summary::-webkit-details-marker { display:none; }
    .comms-more-panel {
        position:absolute; right:0; top:calc(100% + 8px); min-width:220px; max-width:min(280px, calc(100vw - 48px));
        padding:10px; border-radius:16px; border:1px solid #dbe3ef; background:#fff; box-shadow:0 18px 40px rgba(15,23,42,.12);
        display:grid; gap:8px; z-index:30;
    }
    .comms-more-panel a,
    .comms-more-panel button,
    .comms-more-panel form button {
        width:100%; display:inline-flex; align-items:center; justify-content:flex-start; min-height:38px;
        padding:9px 11px; border-radius:12px; border:1px solid #e5edf7; background:#fff; color:#334155;
        font-size:12px; font-weight:800; text-decoration:none; cursor:pointer; box-sizing:border-box; font-family:inherit;
    }
    .comms-more-panel form { margin:0; }
    .comms-empty { padding:28px 18px; text-align:center; color:#64748b; }
    .comms-empty strong { display:block; color:#0f172a; font-size:18px; margin-bottom:6px; }
    .comms-note-strip {
        display:grid; gap:6px; padding:12px 14px; border-radius:16px; background:#fff7ed; border:1px solid #fed7aa;
    }
    .comms-note-strip span { font-size:11px; font-weight:800; letter-spacing:.05em; text-transform:uppercase; color:#9a3412; }
    .comms-modal[hidden] { display:none; }
    .comms-modal {
        position:fixed; inset:0; z-index:1300; background:rgba(15,23,42,.45); display:flex; align-items:center; justify-content:center; padding:18px;
    }
    .comms-modal-dialog {
        width:min(100%, 560px); max-height:calc(100vh - 36px); overflow:auto; border-radius:20px; background:#fff; border:1px solid #dbe3ef; box-shadow:0 28px 60px rgba(15,23,42,.24);
    }
    .comms-modal-head, .comms-modal-foot { position:sticky; background:#fff; z-index:2; }
    .comms-modal-head { top:0; padding:18px 18px 14px; border-bottom:1px solid #eef2f7; display:flex; justify-content:space-between; gap:12px; align-items:flex-start; }
    .comms-modal-head h3 { margin:0; font-size:18px; color:#0f172a; }
    .comms-modal-head p { margin:6px 0 0; color:#64748b; font-size:12px; }
    .comms-modal-body { padding:18px; display:grid; gap:14px; }
    .comms-modal-grid { display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:12px; }
    .comms-modal-field { display:grid; gap:6px; }
    .comms-modal-field label { font-size:12px; font-weight:800; color:#334155; }
    .comms-modal-foot { bottom:0; padding:14px 18px 18px; border-top:1px solid #eef2f7; display:flex; justify-content:flex-end; gap:10px; flex-wrap:wrap; }
    .comms-close {
        display:inline-flex; align-items:center; justify-content:center; width:36px; height:36px; border-radius:999px; border:1px solid #dbe3ef; background:#fff; color:#475569; cursor:pointer;
    }
    @media (max-width: 960px) {
        .comms-search-row { grid-template-columns:repeat(2, minmax(0,1fr)); }
        .comms-card-top { grid-template-columns:1fr; }
        .comms-card-actions { justify-content:flex-start; }
        .comms-card-contacts { grid-template-columns:1fr; }
        .comms-kv { grid-template-columns:repeat(2, minmax(0,1fr)); }
    }
    @media (max-width: 640px) {
        .comms-page { gap:12px; padding:10px 0 20px; }
        .comms-shell, .comms-card { border-radius:18px; }
        .comms-shell { padding:14px; }
        .comms-head h1 { font-size:24px; }
        .comms-search-row, .comms-modal-grid, .comms-kv { grid-template-columns:1fr; }
        .comms-card-actions { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); }
        .comms-card-actions > * { width:100%; }
        .comms-more { width:100%; }
        .comms-more-panel { position:static; min-width:0; max-width:none; box-shadow:none; margin-top:8px; }
        .comms-modal { padding:0; align-items:stretch; }
        .comms-modal-dialog { width:100%; max-height:100vh; border-radius:0; }
    }
</style>

<div class="container comms-page">
    <section class="comms-shell">
        <div class="comms-head">
            <div>
                <h1>Communication Center</h1>
                <p>One place to manage follow-ups, communication accountability, overdue outreach, and operational callbacks across customers, partners, rentals, sales, invoices, and pickups.</p>
            </div>
            <div class="comms-actions">
                @if($featureReady)
                    <button type="button"
                            class="comms-btn"
                            data-open-follow-up-modal
                            data-follow-up-context='{}'
                            data-follow-up-type="{{ \App\Models\FollowUp::TYPE_GENERAL }}"
                            data-follow-up-priority="{{ \App\Models\FollowUp::PRIORITY_MEDIUM }}">
                        Add Follow-up
                    </button>
                @endif
            </div>
        </div>

        @if(!$featureReady)
            <div class="comms-note-strip">
                <span>Setup Pending</span>
                <div>The Communication Center will be available after the <code>follow_ups</code> migration is applied.</div>
            </div>
        @else
            <div class="comms-tab-row">
                @foreach($tabsWithCounts as $tabItem)
                    <a href="{{ route('communication-center.index', array_merge(request()->except('page'), ['tab' => $tabItem['key']])) }}"
                       class="comms-tab {{ $tab === $tabItem['key'] ? 'is-active' : '' }}">
                        <span>{{ $tabItem['label'] }}</span>
                        <span class="comms-tab-count">{{ number_format($tabItem['count']) }}</span>
                    </a>
                @endforeach
            </div>

            <form method="GET" action="{{ route('communication-center.index') }}" class="comms-search-row">
                <input type="hidden" name="tab" value="{{ $tab }}">
                <input class="comms-input" type="search" name="search" value="{{ request('search') }}" placeholder="Search customer, partner, actual client, phone, rental, invoice, product">
                <select class="comms-select" name="priority">
                    <option value="">All priorities</option>
                    @foreach(\App\Models\FollowUp::PRIORITIES as $value => $label)
                        <option value="{{ $value }}" @selected(request('priority') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <select class="comms-select" name="staff">
                    <option value="">All staff</option>
                    <option value="unassigned" @selected(request('staff') === 'unassigned')>Unassigned</option>
                    @foreach($assignableUsers as $user)
                        <option value="user:{{ $user->id }}" @selected(request('staff') === 'user:' . $user->id)>{{ $user->name }} ({{ Str::headline($user->role ?: 'user') }})</option>
                    @endforeach
                </select>
                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                    <button class="comms-btn" type="submit">Apply</button>
                    <a href="{{ $clearHref }}" class="comms-btn-ghost">Clear</a>
                </div>
            </form>

            @if(!empty($activeFilters))
                <div class="comms-active-filters" aria-label="Active communication filters">
                    @foreach($activeFilters as $chip)
                        <span class="comms-chip">{{ $chip }}</span>
                    @endforeach
                </div>
            @endif
        @endif
    </section>

    @if($featureReady)
        <section class="comms-list">
            @forelse($followUps as $followUp)
                @php
                    $effectiveStatus = $followUp->effectiveStatus();
                    $callTargetPhone = $followUp->callTargetPhone();
                    $callTargetHref = $callHref($callTargetPhone);
                    $referenceHref = $followUp->delivery_id
                        ? route('deliveries.show', $followUp->delivery_id)
                        : ($followUp->rental_id
                            ? route('rentals.show', $followUp->rental_id)
                            : ($followUp->sale_id
                                ? route('sales.show', $followUp->sale_id)
                                : ($followUp->invoice_id ? route('invoices.show', $followUp->invoice_id) : null)));
                    $noteContext = [
                        'customer_id' => $followUp->customer_id,
                        'business_partner_id' => $followUp->business_partner_id,
                        'partner_client_id' => $followUp->partner_client_id,
                        'rental_id' => $followUp->rental_id,
                        'sale_id' => $followUp->sale_id,
                        'invoice_id' => $followUp->invoice_id,
                        'delivery_id' => $followUp->delivery_id,
                        'reference' => $followUp->referenceLabel(),
                        'reminder_contact' => collect([$followUp->reminderContactName(), $followUp->reminderContactPhone()])->filter()->implode(' | '),
                        'service_contact' => collect([$followUp->serviceContactName(), $followUp->serviceContactPhone()])->filter()->implode(' | '),
                        'service_address' => $followUp->serviceContactAddress(),
                    ];
                    $lastCommunication = $followUp->note ?: 'No communication note added yet.';
                @endphp
                <article class="comms-card">
                    <div class="comms-card-top">
                        <div class="comms-card-main">
                            <div class="comms-card-headline">
                                <h2>{{ $followUp->title }}</h2>
                                <span class="comms-status-chip" style="{{ $statusTone($effectiveStatus) }}">{{ $followUp->statusLabel() }}</span>
                                <span class="comms-priority-chip" style="{{ $priorityTone($followUp->priority) }}">{{ $followUp->priorityLabel() }}</span>
                            </div>
                            <div class="comms-card-line">
                                <strong>{{ $followUp->referenceLabel() }}</strong>
                                @if($followUp->productLabel())
                                    <span class="comms-card-subtle">| {{ $followUp->productLabel() }}</span>
                                @endif
                            </div>
                            <div class="comms-card-subtle">
                                {{ $followUp->typeLabel() }} follow-up
                                @if($followUp->assignedUser)
                                    | Assigned to {{ $followUp->assignedUser->name }}
                                @endif
                                | Due {{ $followUp->dueLabel() }}
                                @if($followUp->overdueLabel())
                                    | {{ $followUp->overdueLabel() }}
                                @endif
                            </div>
                        </div>

                        <div class="comms-card-actions">
                            @if($callTargetHref)
                                <a href="{{ $callTargetHref }}" class="comms-btn-soft">Call</a>
                            @endif
                            @if($followUp->whatsappUrl())
                                <a href="{{ $followUp->whatsappUrl() }}" target="_blank" rel="noopener" class="comms-btn-soft">WhatsApp</a>
                            @endif
                            @if(!in_array($followUp->status, [\App\Models\FollowUp::STATUS_COMPLETED, \App\Models\FollowUp::STATUS_CANCELLED], true))
                                <form method="POST" action="{{ route('communication-center.complete', $followUp) }}" style="margin:0;">
                                    @csrf
                                    <button type="submit" class="comms-btn">Mark Complete</button>
                                </form>
                            @endif
                            <details class="comms-more">
                                <summary class="comms-btn-ghost">More</summary>
                                <div class="comms-more-panel">
                                    @if($referenceHref)
                                        <a href="{{ $referenceHref }}">Open Record</a>
                                    @endif
                                    @if($followUp->rental_id)
                                        <a href="{{ route('rentals.show', $followUp->rental_id) }}">Open Rental</a>
                                    @endif
                                    @if($followUp->invoice_id)
                                        <a href="{{ route('invoices.show', $followUp->invoice_id) }}">Open Invoice</a>
                                    @endif
                                    <button type="button"
                                            data-open-follow-up-modal
                                            data-follow-up-context='@json($noteContext)'
                                            data-follow-up-type="{{ \App\Models\FollowUp::TYPE_GENERAL }}"
                                            data-follow-up-priority="{{ \App\Models\FollowUp::PRIORITY_MEDIUM }}"
                                            data-follow-up-note="Follow-up note linked to {{ $followUp->referenceLabel() }}.">
                                        Add Note
                                    </button>
                                    @if($followUp->serviceContactMapUrl())
                                        <a href="{{ $followUp->serviceContactMapUrl() }}" target="_blank" rel="noopener">Open Map</a>
                                    @endif
                                </div>
                            </details>
                        </div>
                    </div>

                    <div class="comms-card-contacts">
                        <div class="comms-contact-block">
                            <span class="comms-contact-label">Reminder / Payment</span>
                            <span class="comms-contact-value">{{ collect([$followUp->reminderContactName(), $followUp->reminderContactPhone()])->filter()->implode(' | ') ?: 'Not linked' }}</span>
                        </div>
                        <div class="comms-contact-block">
                            <span class="comms-contact-label">Service Location</span>
                            <span class="comms-contact-value">{{ collect([$followUp->serviceContactName(), $followUp->serviceContactPhone()])->filter()->implode(' | ') ?: 'Not linked' }}</span>
                        </div>
                        <div class="comms-contact-block">
                            <span class="comms-contact-label">Address</span>
                            <span class="comms-contact-value">{{ $followUp->serviceContactAddress() ?: 'No service address available' }}</span>
                            @if($followUp->serviceContactMapUrl())
                                <a href="{{ $followUp->serviceContactMapUrl() }}" target="_blank" rel="noopener" class="comms-contact-link">Open Map</a>
                            @endif
                        </div>
                    </div>

                    <div class="comms-note-strip">
                        <span>Last Communication</span>
                        <div>{{ $lastCommunication }}</div>
                    </div>

                    <div class="comms-kv">
                        <div class="comms-kv-item">
                            <span>Due</span>
                            <strong>{{ $followUp->dueLabel() }}</strong>
                        </div>
                        <div class="comms-kv-item">
                            <span>Assigned Staff</span>
                            <strong>{{ $followUp->assignedUser?->name ?? 'Unassigned' }}</strong>
                        </div>
                        <div class="comms-kv-item">
                            <span>Status</span>
                            <strong>{{ $followUp->statusLabel() }}</strong>
                        </div>
                        <div class="comms-kv-item">
                            <span>Priority</span>
                            <strong>{{ $followUp->priorityLabel() }}</strong>
                        </div>
                    </div>

                    @if(!in_array($followUp->status, [\App\Models\FollowUp::STATUS_COMPLETED, \App\Models\FollowUp::STATUS_CANCELLED], true))
                        <div class="comms-note-strip" style="background:#f8fafc;border-color:#e2e8f0;">
                            <span>Reschedule Follow-up</span>
                            <form method="POST" action="{{ route('communication-center.reschedule', $followUp) }}" style="display:grid;gap:12px;margin-top:4px;">
                                @csrf
                                <div class="comms-modal-grid">
                                    <div class="comms-modal-field">
                                        <label>New Due Date & Time</label>
                                        <input class="comms-input" type="datetime-local" name="due_at" value="{{ optional($followUp->due_at)->format('Y-m-d\TH:i') }}">
                                    </div>
                                    <div class="comms-modal-field">
                                        <label>Assigned Staff</label>
                                        <select class="comms-select" name="assigned_user_id">
                                            <option value="">Unassigned</option>
                                            @foreach($assignableUsers as $user)
                                                <option value="{{ $user->id }}" @selected((int) $followUp->assigned_user_id === (int) $user->id)>{{ $user->name }} ({{ Str::headline($user->role ?: 'user') }})</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                                <div class="comms-modal-grid">
                                    <div class="comms-modal-field">
                                        <label>Priority</label>
                                        <select class="comms-select" name="priority">
                                            @foreach(\App\Models\FollowUp::PRIORITIES as $value => $label)
                                                <option value="{{ $value }}" @selected($followUp->priority === $value)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="comms-modal-field">
                                        <label>Reschedule Note</label>
                                        <input class="comms-input" type="text" name="reschedule_note" placeholder="Why this follow-up moved">
                                    </div>
                                </div>
                                <div style="display:flex;justify-content:flex-end;">
                                    <button type="submit" class="comms-btn-ghost">Save Reschedule</button>
                                </div>
                            </form>
                        </div>
                    @endif
                </article>
            @empty
                <div class="comms-card comms-empty">
                    <strong>No communication items in this queue</strong>
                    <span>Try another tab or clear the current filters to see more follow-up work.</span>
                </div>
            @endforelse
        </section>

        @if($followUps instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator)
            {{ $followUps->links() }}
        @endif
    @endif
</div>

@include('partials.follow-up-modal', [
    'followUpFeatureReady' => $featureReady,
    'followUpAssignableUsers' => $assignableUsers ?? collect(),
])

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const menus = Array.from(document.querySelectorAll('.comms-more'));
        const closeMenus = (except = null) => {
            menus.forEach((menu) => {
                if (menu !== except) {
                    menu.removeAttribute('open');
                }
            });
        };

        menus.forEach((menu) => {
            menu.addEventListener('toggle', () => {
                if (menu.open) {
                    closeMenus(menu);
                }
            });
        });

        document.addEventListener('click', (event) => {
            if (!event.target.closest('.comms-more')) {
                closeMenus();
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeMenus();
            }
        });
    });
</script>
@endsection
