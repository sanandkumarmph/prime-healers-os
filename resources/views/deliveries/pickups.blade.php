@extends('layouts.app')

@section('content')
@php
    $badge = function (string $status) {
        return match ($status) {
            'pending' => 'background:#e2e8f0;color:#334155;',
            'in_progress' => 'background:#fef3c7;color:#b45309;',
            'completed' => 'background:#dcfce7;color:#166534;',
            default => 'background:#f1f5f9;color:#475569;',
        };
    };
    $progressBadge = fn (?string $status) => match ($status) {
        'partial_return' => 'background:#dbeafe;color:#1d4ed8;',
        'completed' => 'background:#dcfce7;color:#166534;',
        default => 'background:#fef3c7;color:#b45309;',
    };
    $progressLabel = fn (?string $status) => match ($status) {
        'partial_return' => 'Partial Pickup',
        default => ucfirst(str_replace('_', ' ', $status ?: 'pending')),
    };
@endphp

<style>
    .board-page { display:grid; gap:18px; padding:20px 24px 32px; }
    .board-toolbar { display:flex; align-items:center; }
    .board-back-link {
        display:inline-flex; align-items:center; gap:8px;
        min-height:38px; padding:8px 12px;
        border-radius:12px; border:1px solid #fed7aa;
        background:#fff7ed; color:#ea580c; text-decoration:none;
        font-size:12px; font-weight:800;
        box-shadow:0 8px 20px rgba(15, 23, 42, 0.04);
    }
    .board-back-link svg { width:16px; height:16px; flex:0 0 16px; }
    .board-header h1 { margin:0; font-size:28px; color:#0f172a; }
    .board-header p { margin:6px 0 0; color:#64748b; font-size:13px; }
    .board-list { display:grid; gap:12px; }
    .board-card {
        background:#fff; border:1px solid #dbe3ef; border-radius:16px; padding:16px;
        box-shadow:0 8px 24px rgba(15, 23, 42, 0.04);
        display:grid; gap:12px;
    }
    .pickup-row {
        display:grid;
        grid-template-columns:minmax(0, 1.15fr) minmax(220px, .85fr) minmax(240px, 1fr);
        gap:16px;
        align-items:start;
    }
    .pickup-card-head { display:grid; gap:6px; }
    .pickup-card-head h2 { margin:0; font-size:18px; color:#0f172a; }
    .pickup-meta { display:grid; gap:4px; }
    .pickup-meta-strong { color:#0f172a; font-size:13px; font-weight:800; }
    .ops-muted { color:#64748b; font-size:12px; }
    .status-badge { display:inline-flex; align-items:center; padding:5px 10px; border-radius:999px; font-size:11px; font-weight:700; letter-spacing:.03em; text-transform:uppercase; }
    .board-actions { display:flex; flex-wrap:wrap; gap:8px; margin-top:4px; }
    .board-item-block { border-top:1px solid #e5edf7; padding-top:12px; }
    .board-item-title { color:#475569; font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.05em; margin-bottom:8px; }
    .board-item-list { display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:8px; margin:0; padding:0; list-style:none; }
    .board-item {
        display:grid; grid-template-columns:28px minmax(0, 1fr); gap:10px; align-items:flex-start;
        border:1px solid #bfdbfe; border-radius:12px; padding:10px; background:#eff6ff;
    }
    .board-item-num {
        display:inline-flex; align-items:center; justify-content:center; width:28px; height:28px;
        border-radius:999px; font-size:12px; font-weight:800; color:#fff; background:#2563eb;
    }
    .board-item-name { color:#0f172a; font-size:13px; font-weight:800; }
    .board-item-meta { color:#64748b; font-size:12px; margin-top:3px; line-height:1.45; }
    .board-btn, .board-btn-secondary {
        display:inline-flex; align-items:center; justify-content:center; gap:6px;
        border-radius:10px; padding:9px 13px; font-size:13px; font-weight:600; text-decoration:none;
        border:1px solid transparent; cursor:pointer;
    }
    .board-btn { background:#2563eb; color:#fff; }
    .board-btn-secondary { background:#fff; color:#334155; border-color:#cbd5e1; }
    .board-utility-btn { min-height:42px; }
    .mobile-list-command { display:none; }
    .mobile-chip-row { display:none; }
    .progress-copy { color:#64748b; font-size:12px; margin-top:6px; }
    .pickup-status-stack { display:grid; gap:8px; align-content:start; }
    .pickup-actions-wrap { display:grid; gap:12px; align-content:start; }
    .pickup-empty-line {
        padding:10px 12px;
        border:1px dashed #dbe3ef;
        border-radius:12px;
        background:#f8fafc;
    }

    @media (max-width: 1080px) {
        .pickup-row { grid-template-columns:1fr; }
        .board-item-list { grid-template-columns:1fr 1fr; }
    }

    @media (max-width: 767px) {
        .board-page { gap:8px; padding:4px 0 16px; }
        .board-toolbar { padding:0 12px; }
        .board-back-link { width:100%; justify-content:center; }
        .board-header { display:none; }
        .board-list { gap:8px; }
        .board-card { padding:12px; border-radius:14px; }
        .pickup-card-head h2 { font-size:15px; }
        .mobile-list-command {
            display:grid; gap:8px; padding:8px; border:1px solid #dbe3ef; border-radius:16px;
            background:#fff; box-shadow:0 8px 22px rgba(15,23,42,.04);
        }
        .mobile-search-row { display:grid; grid-template-columns:minmax(0,1fr); gap:8px; }
        .mobile-search-row input {
            width:100%; min-height:40px; border:1px solid #cbd5e1; border-radius:12px;
            padding:8px 10px; font-size:16px; box-sizing:border-box;
        }
        .mobile-stat-strip { display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:6px; }
        .mobile-stat-strip button {
            display:grid; gap:2px; min-width:0; padding:7px 8px; border:1px solid #e2e8f0; border-radius:12px;
            background:#f8fafc; color:#0f172a; text-align:left;
        }
        .mobile-stat-strip span { font-size:9px; color:#64748b; font-weight:800; text-transform:uppercase; letter-spacing:.05em; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .mobile-stat-strip strong { font-size:16px; line-height:1; }
        .mobile-chip-row {
            display:flex; gap:8px; overflow-x:auto; padding:2px 1px 4px; scrollbar-width:none;
        }
        .mobile-chip-row::-webkit-scrollbar { display:none; }
        .mobile-chip {
            flex:0 0 auto; display:inline-flex; align-items:center; justify-content:center;
            min-height:34px; padding:7px 11px; border-radius:999px; border:1px solid #cbd5e1;
            background:#fff; color:#334155; text-decoration:none; font-size:12px; font-weight:800; cursor:pointer;
        }
        .mobile-chip.is-active { background:#0f172a; color:#fff; border-color:#0f172a; }
        .board-actions {
            display:grid;
            grid-template-columns:repeat(2, minmax(0, 1fr));
            gap:8px;
        }
        .board-actions > * { min-width:0; }
        .board-actions .board-btn,
        .board-actions .board-btn-secondary,
        .board-actions form button { width:100%; min-height:44px; }
        .board-item-list { grid-template-columns:1fr; }
    }
</style>

<div class="container board-page">
    <div class="board-toolbar">
        <a href="{{ route('deliveries.index', ['tab' => 'pickups', 'task_type' => 'pickup']) }}" class="board-back-link" aria-label="Back to Tasks Board">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M15 18l-6-6 6-6"></path>
                <path d="M21 12H9"></path>
            </svg>
            <span>Back to Tasks Board</span>
        </a>
    </div>
    <div class="mobile-list-command" aria-label="Mobile pickup controls">
        <div class="mobile-search-row">
            <input type="search" id="pickup-mobile-search" placeholder="Search pickup, customer, product, mobile">
        </div>
        <div class="mobile-stat-strip" aria-label="Pickup summary">
            <button type="button" data-pickup-filter="pending"><span>Pending</span><strong>{{ $deliveries->where('status', 'pending')->count() }}</strong></button>
            <button type="button" data-pickup-filter="in_progress"><span>Out</span><strong>{{ $deliveries->where('status', 'in_progress')->count() }}</strong></button>
            <button type="button" data-pickup-filter="completed"><span>Done</span><strong>{{ $deliveries->where('status', 'completed')->count() }}</strong></button>
        </div>
        <div class="mobile-chip-row" aria-label="Pickup quick filters">
            <button type="button" class="mobile-chip is-active" data-pickup-filter="all">All</button>
            <button type="button" class="mobile-chip" data-pickup-filter="pending">Pending</button>
            <button type="button" class="mobile-chip" data-pickup-filter="in_progress">In Progress</button>
            <button type="button" class="mobile-chip" data-pickup-filter="completed">Completed</button>
        </div>
    </div>

    <div class="board-header">
        <h1>Pickups Assigned</h1>
        <p>Practical pickup board for returned rentals and follow-up collection work.</p>
    </div>

    <div class="board-list">
        @forelse($deliveries as $delivery)
            @php
                $isSaleTask = (bool) $delivery->sale_id;
                $pickupItems = collect();

                if (!$isSaleTask && $delivery->rental) {
                    $pickupItems = collect($delivery->rental->rentalItems ?? collect())
                        ->filter(fn ($item) => ($item->pending_pickup_quantity ?? 0) > 0 || ($item->delivered_quantity_value ?? 0) > ($item->returned_quantity_value ?? 0))
                        ->map(function ($item) use ($delivery) {
                            return [
                                'name' => $item->product?->name ?? $delivery->rental?->product?->name ?? 'Rental item',
                                'serial' => null,
                                'warehouse' => $delivery->rental?->dispatchWarehouse?->name,
                                'quantity' => $item->pending_pickup_quantity ?? max(($item->delivered_quantity_value ?? 0) - ($item->returned_quantity_value ?? 0), 0),
                            ];
                        })
                        ->values();

                    if ($pickupItems->isEmpty()) {
                        $pickupItems = collect($delivery->rental->activeRentalAssets ?? collect())->map(function ($assignment) use ($delivery) {
                            return [
                                'name' => $assignment->asset->product?->name ?? $delivery->rental?->product?->name ?? 'Rental item',
                                'serial' => $assignment->asset->serial_number ?? null,
                                'warehouse' => $assignment->asset->warehouse?->name ?? $delivery->rental?->dispatchWarehouse?->name,
                                'quantity' => 1,
                            ];
                        })->values();
                    }
                }
            @endphp
            <div class="board-card" data-pickup-card data-pickup-status="{{ $delivery->status }}" data-pickup-search="{{ strtolower(collect([$isSaleTask ? ($delivery->sale?->customer?->name ?? '') : ($delivery->rental?->customer_name ?? ''), $isSaleTask ? ($delivery->sale?->customer?->phone ?? '') : ($delivery->rental?->phone ?? ''), $isSaleTask ? ($delivery->sale?->product?->name ?? '') : ($delivery->rental?->product?->name ?? ''), $delivery->assignedStaff->name ?? $delivery->third_party_name ?? $delivery->assignedUser->name ?? ''])->filter()->join(' ')) }}">
                @php($contactPhone = $isSaleTask ? ($delivery->sale?->customer?->phone) : ($delivery->rental?->phone))
                <div class="pickup-row">
                    <div class="pickup-card-head">
                        <h2>{{ $isSaleTask ? ($delivery->sale?->customer?->name ?? 'N/A') : ($delivery->rental?->customer_name ?? 'N/A') }}</h2>
                        <div class="pickup-meta">
                            <div class="pickup-meta-strong">{{ $isSaleTask ? ($delivery->sale?->product?->name ?? 'Sale product') : ($delivery->rental?->product?->name ?? 'N/A') }}</div>
                            <div class="ops-muted">{{ $isSaleTask ? 'Sale #' . $delivery->sale_id : 'Rental #' . ($delivery->rental_id ?? '-') }}</div>
                            <div class="ops-muted">Warehouse: {{ $isSaleTask ? ($delivery->sale?->asset?->warehouse?->name ?? 'Sale dispatch') : ($delivery->rental?->dispatchWarehouse?->name ?? 'Any warehouse') }}</div>
                        </div>
                    </div>

                    <div class="pickup-status-stack">
                        <div class="ops-muted">Assigned to: {{ $delivery->assignedStaff->name ?? $delivery->third_party_name ?? $delivery->assignedUser->name ?? 'Unassigned' }}</div>
                        <div class="ops-muted">Scheduled: {{ $delivery->scheduled_at ? $delivery->scheduled_at->format('d M Y h:i A') : 'Not scheduled' }}</div>
                        <div class="ops-inline">
                            <span class="status-badge" style="{{ $badge($delivery->status) }}">{{ ucfirst(str_replace('_', ' ', $delivery->status)) }}</span>
                            @if(!$isSaleTask && $delivery->rental && $delivery->rental->pickupStatus() !== $delivery->status)
                                <span class="status-badge" style="{{ $progressBadge($delivery->rental->pickupStatus()) }}">{{ $progressLabel($delivery->rental->pickupStatus()) }}</span>
                            @endif
                        </div>
                        @if(!$isSaleTask && $delivery->rental)
                            <div class="progress-copy">{{ $delivery->rental->returnedQuantityTotal() }}/{{ max($delivery->rental->deliveredQuantityTotal(), 0) }} picked up</div>
                        @endif
                    </div>

                    <div class="pickup-actions-wrap">
                        <div class="board-actions">
                            <a href="{{ route('deliveries.show', $delivery) }}" class="board-btn-secondary">View</a>
                            @if($contactPhone)
                                <a href="tel:{{ preg_replace('/\D+/', '', $contactPhone) }}" class="board-btn-secondary board-utility-btn">Call</a>
                            @endif
                            @if($delivery->status === 'pending')
                                <form action="{{ route('deliveries.in_progress', $delivery) }}" method="POST">
                                    @csrf
                                    @method('PUT')
                                    <button type="submit" class="board-btn-secondary">Start Pickup</button>
                                </form>
                            @endif
                            @if($delivery->status !== 'completed')
                                <form action="{{ route('deliveries.complete', $delivery) }}" method="POST">
                                    @csrf
                                    @method('PUT')
                                    @if(!$isSaleTask && $delivery->rental?->pendingPickupQuantityTotal() > 0)
                                        <input type="hidden" name="confirm_partial" value="1">
                                        <button type="submit" class="board-btn">Complete Partial</button>
                                    @else
                                        <button type="submit" class="board-btn">Complete Pickup</button>
                                    @endif
                                </form>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="board-item-block">
                    <div class="board-item-title">Items To Pick Up</div>
                    @if($pickupItems->isEmpty())
                        <div class="pickup-empty-line ops-muted">No rental-return items are linked to this pickup yet.</div>
                    @else
                        <ol class="board-item-list">
                            @foreach($pickupItems as $index => $item)
                                <li class="board-item">
                                    <span class="board-item-num">{{ $index + 1 }}</span>
                                    <div>
                                        <div class="board-item-name">{{ $item['name'] }}</div>
                                        <div class="board-item-meta">
                                            Rental Return
                                            @if(!empty($item['quantity']))
                                                <br>Qty Pending: {{ $item['quantity'] }}
                                            @endif
                                            @if(!empty($item['serial']))
                                                <br>Serial: {{ $item['serial'] }}
                                            @endif
                                            @if(!empty($item['warehouse']))
                                                <br>Warehouse: {{ $item['warehouse'] }}
                                            @endif
                                        </div>
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </div>
            </div>
        @empty
            <div class="board-card">
                <div class="ops-muted">No pickups assigned.</div>
            </div>
        @endforelse
    </div>
</div>
@endsection

@push('scripts')
<script>
    (() => {
        const search = document.getElementById('pickup-mobile-search');
        const cards = Array.from(document.querySelectorAll('[data-pickup-card]'));
        const filters = Array.from(document.querySelectorAll('[data-pickup-filter]'));
        let status = 'all';

        const applyFilters = () => {
            const term = (search?.value || '').trim().toLowerCase();
            cards.forEach((card) => {
                const statusMatch = status === 'all' || card.dataset.pickupStatus === status;
                const searchMatch = term === '' || (card.dataset.pickupSearch || '').includes(term);
                card.style.display = statusMatch && searchMatch ? '' : 'none';
            });
        };

        search?.addEventListener('input', applyFilters);
        filters.forEach((button) => {
            button.addEventListener('click', () => {
                status = button.dataset.pickupFilter || 'all';
                filters.forEach((item) => item.classList.toggle('is-active', item.dataset.pickupFilter === status));
                applyFilters();
            });
        });
    })();
</script>
@endpush
