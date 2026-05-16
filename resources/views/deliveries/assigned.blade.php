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
        'partially_delivered' => 'background:#dbeafe;color:#1d4ed8;',
        'completed' => 'background:#dcfce7;color:#166534;',
        default => 'background:#fef3c7;color:#b45309;',
    };
    $progressLabel = fn (?string $status) => match ($status) {
        'partially_delivered' => 'Partial Delivery',
        default => ucfirst(str_replace('_', ' ', $status ?: 'pending')),
    };
@endphp

<style>
    .board-page { display:grid; gap:18px; padding:20px 24px 32px; }
    .board-toolbar { display:flex; align-items:center; }
    .board-back-link {
        display:inline-flex; align-items:center; gap:8px;
        min-height:38px; padding:8px 12px;
        border-radius:12px; border:1px solid #dbe3ef;
        background:#fff; color:#2563eb; text-decoration:none;
        font-size:12px; font-weight:800;
        box-shadow:0 8px 20px rgba(15, 23, 42, 0.04);
    }
    .board-back-link svg { width:16px; height:16px; flex:0 0 16px; }
    .board-header h1 { margin:0; font-size:28px; color:#0f172a; }
    .board-header p { margin:6px 0 0; color:#64748b; font-size:13px; }
    .board-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(280px, 1fr)); gap:12px; }
    .board-card { background:#fff; border:1px solid #dbe3ef; border-radius:14px; padding:16px; box-shadow:0 8px 24px rgba(15, 23, 42, 0.04); }
    .board-card h2 { margin:0 0 10px; font-size:16px; color:#0f172a; }
    .ops-muted { color:#64748b; font-size:12px; }
    .status-badge { display:inline-flex; align-items:center; padding:5px 10px; border-radius:999px; font-size:11px; font-weight:700; letter-spacing:.03em; text-transform:uppercase; }
    .board-actions { display:flex; flex-wrap:wrap; gap:8px; margin-top:12px; }
    .board-item-block { margin-top:14px; border-top:1px solid #e5edf7; padding-top:12px; }
    .board-item-title { color:#475569; font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.05em; margin-bottom:8px; }
    .board-item-list { display:grid; gap:8px; margin:0; padding:0; list-style:none; }
    .board-item {
        display:grid; grid-template-columns:28px minmax(0, 1fr); gap:10px; align-items:flex-start;
        border:1px solid #e2e8f0; border-radius:12px; padding:10px; background:#f8fafc;
    }
    .board-item-num {
        display:inline-flex; align-items:center; justify-content:center; width:28px; height:28px;
        border-radius:999px; font-size:12px; font-weight:800; color:#0f172a; background:#e2e8f0;
    }
    .board-item.is-rental { border-color:#bfdbfe; background:#eff6ff; }
    .board-item.is-rental .board-item-num { background:#2563eb; color:#fff; }
    .board-item.is-sale { border-color:#bbf7d0; background:#f0fdf4; }
    .board-item.is-sale .board-item-num { background:#16a34a; color:#fff; }
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
    .progress-copy { color:#64748b; font-size:12px; margin-top:6px; }

    @media (max-width: 767px) {
        .board-toolbar { padding:0 12px; }
        .board-back-link { width:100%; justify-content:center; }
        .board-page { gap:10px; padding:8px 0 16px; }
        .board-grid { grid-template-columns:1fr; gap:10px; }
        .board-card { padding:14px; }
        .board-actions {
            display:grid;
            grid-template-columns:repeat(2, minmax(0, 1fr));
            gap:8px;
        }
        .board-actions > * { min-width:0; }
        .board-actions .board-btn,
        .board-actions .board-btn-secondary,
        .board-actions form button { width:100%; min-height:44px; }
    }
</style>

<div class="container board-page">
    <div class="board-toolbar">
        <a href="{{ route('deliveries.index') }}" class="board-back-link" aria-label="Back to Tasks Board">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M15 18l-6-6 6-6"></path>
                <path d="M21 12H9"></path>
            </svg>
            <span>Back to Tasks Board</span>
        </a>
    </div>
    <div class="board-header">
        <h1>Deliveries Assigned</h1>
        <p>Clean field-ready delivery board with just the details the team needs to act.</p>
    </div>

    <div class="board-grid">
        @forelse($deliveries as $delivery)
            @php
                $isSaleTask = (bool) $delivery->sale_id;
                $rentalAssets = $delivery->rental?->activeRentalAssets ?? collect();
                $saleItems = $delivery->rental?->saleItems ?? collect();
                $deliveryItems = collect();

                if ($isSaleTask) {
                    $saleAsset = $delivery->sale?->asset;
                    if ($saleAsset) {
                        $deliveryItems->push([
                            'kind' => 'sale',
                            'name' => $delivery->sale?->product?->name ?? 'Sale item',
                            'serial' => $saleAsset->serial_number,
                            'warehouse' => $saleAsset->warehouse?->name,
                        ]);
                    } elseif ($delivery->sale) {
                        $deliveryItems->push([
                            'kind' => 'sale',
                            'name' => $delivery->sale?->product?->name ?? 'Sale item',
                            'serial' => null,
                            'warehouse' => $delivery->sale?->warehouse?->name ?? null,
                        ]);
                    }
                } else {
                    foreach ($rentalAssets as $assignment) {
                        $deliveryItems->push([
                            'kind' => 'rental',
                            'name' => $assignment->asset->product?->name ?? $delivery->rental?->product?->name ?? 'Rental item',
                            'serial' => $assignment->asset->serial_number ?? null,
                            'warehouse' => $assignment->asset->warehouse?->name ?? $delivery->rental?->dispatchWarehouse?->name,
                        ]);
                    }

                    foreach ($saleItems->filter(fn ($item) => $item->asset) as $saleItem) {
                        $deliveryItems->push([
                            'kind' => 'sale',
                            'name' => $saleItem->product->name ?? 'New product',
                            'serial' => $saleItem->asset->serial_number ?? null,
                            'warehouse' => $saleItem->asset->warehouse?->name,
                        ]);
                    }
                }
            @endphp
            <div class="board-card">
                @php($contactPhone = $isSaleTask ? ($delivery->sale?->customer?->phone) : ($delivery->rental?->phone))
                @php($hasProofHistory = (int) ($delivery->proofs_count ?? 0) > 0)
                <h2>{{ $isSaleTask ? ($delivery->sale?->customer?->name ?? 'N/A') : ($delivery->rental?->customer_name ?? 'N/A') }}</h2>
                <div class="ops-muted">{{ $isSaleTask ? ($delivery->sale?->product?->name ?? 'Sale product') : ($delivery->rental?->product?->name ?? 'N/A') }}</div>
                <div class="ops-muted" style="margin-top:6px;">Warehouse: {{ $isSaleTask ? ($delivery->sale?->asset?->warehouse?->name ?? 'Sale dispatch') : ($delivery->rental?->dispatchWarehouse?->name ?? 'Any warehouse') }}</div>
                <div class="ops-muted">{{ $isSaleTask ? 'Sale #' . $delivery->sale_id : 'Rental #' . ($delivery->rental_id ?? '-') }}</div>
                <div class="ops-muted">Assigned to: {{ $delivery->assignedStaff->name ?? $delivery->third_party_name ?? $delivery->assignedUser->name ?? 'Unassigned' }}</div>
                <div class="ops-muted">Scheduled: {{ $delivery->scheduled_at ? $delivery->scheduled_at->format('d M Y h:i A') : 'Not scheduled' }}</div>
                <div style="margin-top:10px;">
                    <span class="status-badge" style="{{ $badge($delivery->status) }}">{{ ucfirst(str_replace('_', ' ', $delivery->status)) }}</span>
                    @if(!$isSaleTask && $delivery->rental && $delivery->rental->deliveryStatus() !== $delivery->status)
                        <span class="status-badge" style="{{ $progressBadge($delivery->rental->deliveryStatus()) }}">{{ $progressLabel($delivery->rental->deliveryStatus()) }}</span>
                    @endif
                </div>
                @if(!$isSaleTask && $delivery->rental)
                    <div class="progress-copy">{{ $delivery->rental->deliveredQuantityTotal() }}/{{ max($delivery->rental->displayRentalItems()->sum(fn ($item) => (int) ($item->ordered_quantity ?? $item->quantity ?? 0)), 0) }} delivered</div>
                @endif

                <div class="board-item-block">
                    <div class="board-item-title">{{ $isSaleTask ? 'Items To Deliver' : 'Items To Deliver' }}</div>
                    @if($deliveryItems->isEmpty())
                        <div class="ops-muted">No linked items are listed on this task yet.</div>
                    @else
                        <ol class="board-item-list">
                            @foreach($deliveryItems as $index => $item)
                                <li class="board-item {{ $item['kind'] === 'sale' ? 'is-sale' : 'is-rental' }}">
                                    <span class="board-item-num">{{ $index + 1 }}</span>
                                    <div>
                                        <div class="board-item-name">{{ $item['name'] }}</div>
                                        <div class="board-item-meta">
                                            {{ $item['kind'] === 'sale' ? 'New Product With Rental' : 'Rental Asset' }}
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

                @if($delivery->status !== 'completed')
                    <div class="board-actions">
                        <a href="{{ route('deliveries.show', $delivery) }}" class="board-btn-secondary board-utility-btn">View</a>
                        @if($contactPhone)
                            <a href="tel:{{ preg_replace('/\D+/', '', $contactPhone) }}" class="board-btn-secondary board-utility-btn">Call</a>
                        @endif
                        @if($delivery->status === 'pending')
                            <a href="{{ route('deliveries.show', $delivery) }}#workflow-proof-section" class="board-btn-secondary">Start Delivery</a>
                        @endif
                        <a href="{{ route('deliveries.show', $delivery) }}#workflow-proof-section" class="board-btn">
                            Complete Delivery
                        </a>
                    </div>
                @elseif($hasProofHistory)
                    <div class="board-actions">
                        <a href="{{ route('deliveries.show', $delivery) }}#delivery-proof-history" class="board-btn">View Proof</a>
                    </div>
                @endif
            </div>
        @empty
            <div class="board-card">
                <div class="ops-muted">No deliveries assigned.</div>
            </div>
        @endforelse
    </div>
</div>
@endsection
