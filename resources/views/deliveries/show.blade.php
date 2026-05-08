@extends('layouts.app')

@section('content')
@php
    $isSaleTask = (bool) $delivery->sale_id;
    $linkedCustomer = $isSaleTask ? $delivery->sale?->customer : $delivery->rental?->customer;
    $linkedPhone = $linkedCustomer?->phone ?: ($delivery->rental?->phone ?? null);
    $rentalAssets = $delivery->rental?->activeRentalAssets ?? collect();
    $rentalSaleItems = $delivery->rental?->saleItems ?? collect();
    $deliverySaleAssets = $delivery->type === 'delivery'
        ? $rentalSaleItems
        ->filter(fn ($item) => $item->asset)
        ->values()
        : collect();
    $rentalItems = $delivery->rental?->displayRentalItems() ?? collect();
    $assigneeName = $delivery->assignedUser?->name
        ?? $delivery->assignedStaff?->name
        ?? $delivery->third_party_name
        ?? 'Not assigned';
    $assigneeRole = $delivery->assignedUser
        ? 'Delivery Team'
        : ($delivery->assignedStaff
            ? ucwords(str_replace('_', ' ', $delivery->assignedStaff->assignment_role ?? 'vendor'))
            : ($delivery->assignment_type === 'third_party' ? 'Third Party' : 'Unassigned'));
    $assigneeSecondaryLabel = $delivery->assignedUser ? 'Assigned User' : 'Assigned Staff';
    $assigneeContact = $delivery->assignedUser?->phone
        ?? $delivery->assignedStaff?->phone
        ?? $delivery->third_party_contact
        ?? $delivery->third_party_phone
        ?? '-';
    $showThirdPartyDetails = filled($delivery->third_party_name)
        || filled($delivery->third_party_contact)
        || filled($delivery->third_party_phone);
    $statusStyle = match ($delivery->status) {
        'pending' => 'background:#e2e8f0;color:#334155;',
        'in_progress' => 'background:#fef3c7;color:#b45309;',
        'completed' => 'background:#dcfce7;color:#166534;',
        'cancelled' => 'background:#f1f5f9;color:#64748b;',
        default => 'background:#f1f5f9;color:#475569;',
    };
    $itemProgressBadge = fn (?string $status) => match ($status) {
        'partial', 'partially_delivered', 'partial_return', 'partially_returned', 'with_customer' => 'background:#dbeafe;color:#1d4ed8;',
        'delivered', 'picked_up', 'completed', 'returned' => 'background:#dcfce7;color:#166534;',
        'awaiting_verification' => 'background:#fef3c7;color:#b45309;',
        'pickup_pending', 'delivery_pending' => 'background:#fef3c7;color:#b45309;',
        default => 'background:#fef3c7;color:#b45309;',
    };
    $itemProgressLabel = fn (?string $status) => match ($status) {
        'partial' => 'Partial',
        'delivered' => 'Delivered',
        'picked_up' => 'Picked Up',
        'partially_delivered' => 'Partially Delivered',
        'partial_return' => 'Partial Pickup',
        'partially_returned' => 'Partially Returned',
        'with_customer' => 'With Customer',
        'pickup_pending' => 'Pickup Pending',
        'delivery_pending' => 'Delivery Pending',
        'returned' => 'Returned',
        'awaiting_verification' => 'Awaiting Verification',
        'completed' => 'Completed',
        default => ucfirst(str_replace('_', ' ', $status ?: 'pending')),
    };
@endphp

<style>
    .delivery-detail { display:grid; gap:16px; padding:10px 0 18px; max-width:1220px; margin:0 auto; }
    .delivery-detail-header { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap; }
    .delivery-detail-header h1 { margin:0; font-size:28px; color:#0f172a; }
    .delivery-detail-header p { margin:6px 0 0; color:#64748b; font-size:13px; }
    .detail-actions { display:flex; gap:8px; flex-wrap:wrap; }
    .mobile-inline-actions { display:none; }
    .mobile-actions-menu {
        position:relative;
        width:100%;
    }
    .mobile-actions-menu summary {
        list-style:none;
        display:inline-flex;
        align-items:center;
        justify-content:center;
        min-height:44px;
        min-width:44px;
        padding:10px 12px;
        border-radius:13px;
        border:1px solid #cbd5e1;
        background:#fff;
        color:#0f172a;
        font-size:12px;
        font-weight:800;
        cursor:pointer;
    }
    .mobile-actions-menu summary::-webkit-details-marker { display:none; }
    .mobile-actions-menu[open] summary { border-color:#93c5fd; box-shadow:0 0 0 3px rgba(37,99,235,.10); }
    .mobile-actions-panel {
        position:absolute;
        right:0;
        bottom:calc(100% + 8px);
        width:min(280px, calc(100vw - 28px));
        display:grid;
        gap:6px;
        padding:10px;
        border:1px solid #dbe3ef;
        border-radius:16px;
        background:#fff;
        box-shadow:0 18px 40px rgba(15,23,42,.18);
        z-index:8;
    }
    .mobile-actions-panel a,
    .mobile-actions-panel button {
        width:100%;
        justify-content:flex-start;
        min-height:42px;
        padding:10px 12px;
        border-radius:12px;
        border:1px solid #e2e8f0;
        background:#fff;
        color:#0f172a;
        font-size:13px;
        font-weight:700;
        text-decoration:none;
    }
    .mobile-actions-panel .is-danger {
        color:#991b1b;
        background:#fff1f2;
        border-color:#fecaca;
    }
    .detail-btn, .detail-btn-secondary {
        display:inline-flex; align-items:center; justify-content:center; gap:6px;
        border-radius:10px; padding:8px 12px; font-size:12px; font-weight:600; text-decoration:none;
        border:1px solid transparent; cursor:pointer;
    }
    .detail-btn { background:#2563eb; color:#fff; }
    .detail-btn-secondary { background:#fff; border-color:#cbd5e1; color:#334155; }
    .detail-card { background:#fff; border:1px solid #dbe3ef; border-radius:14px; padding:14px; box-shadow:0 8px 24px rgba(15, 23, 42, 0.04); }
    .detail-grid { display:grid; grid-template-columns:repeat(12, minmax(0, 1fr)); gap:14px; }
    .span-4 { grid-column:span 4; }
    .span-6 { grid-column:span 6; }
    .span-12 { grid-column:span 12; }
    .label { display:block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#64748b; margin-bottom:5px; }
    .value { color:#0f172a; font-size:14px; }
    .status-badge { display:inline-flex; align-items:center; padding:5px 10px; border-radius:999px; font-size:11px; font-weight:700; letter-spacing:.03em; text-transform:uppercase; }
    .asset-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(220px, 1fr)); gap:10px; }
    .asset-box { border:1px solid #dbe3ef; border-radius:12px; padding:12px; background:#fcfdff; }
    .item-progress-table { width:100%; border-collapse:separate; border-spacing:0; }
    .item-progress-table th { text-align:left; font-size:11px; text-transform:uppercase; letter-spacing:.05em; color:#64748b; padding:10px 12px; border-bottom:1px solid #e2e8f0; }
    .item-progress-table td { padding:12px; border-bottom:1px solid #eef2f7; vertical-align:top; }
    .item-progress-form { display:flex; gap:8px; flex-wrap:wrap; align-items:end; }
    .item-progress-form input { border:1px solid #cbd5e1; border-radius:10px; padding:8px 10px; font-size:13px; }
    .item-progress-form input[type="number"] { width:88px; }
    @media (max-width: 900px) {
        .span-4, .span-6 { grid-column:span 12; }
        .item-progress-table, .item-progress-table tbody, .item-progress-table tr, .item-progress-table td { display:block; width:100%; }
        .item-progress-table thead { display:none; }
        .item-progress-table tr { border:1px solid #e2e8f0; border-radius:12px; margin-bottom:10px; overflow:hidden; }
    }
    @media (max-width: 767px) {
        .delivery-detail { padding:8px 0 16px; }
        .detail-actions { display:none; }
        .mobile-inline-actions {
            display:grid;
            grid-template-columns:repeat(2, minmax(0, 1fr));
            gap:8px;
            width:100%;
        }
        .mobile-inline-actions > * { min-width:0; }
        .mobile-sticky-actions .mobile-actions-menu {
            flex:1 0 auto;
            width:auto;
            min-width:0;
        }
        .mobile-sticky-actions .mobile-actions-menu summary {
            width:100%;
            min-height:44px;
        }
        .mobile-sticky-actions .mobile-actions-panel {
            width:min(220px, calc(100vw - 44px));
            margin-top:0;
        }
    }
</style>

<div class="delivery-detail">
    <div class="delivery-detail-header">
        <div>
            <h1>{{ ucfirst($delivery->type) }} #{{ $delivery->id }}</h1>
            <p>Compact logistics detail for scheduling, assignee, warehouse, and linked {{ $isSaleTask ? 'sale order' : 'rental assets' }}.</p>
        </div>
        <div class="detail-actions">
            <a href="{{ route('deliveries.index') }}" class="detail-btn-secondary">Back</a>
            <a href="{{ route('deliveries.edit', $delivery) }}" class="detail-btn-secondary">Edit</a>
            @if(auth()->user()?->canAccessModule('deliveries', 'update') && !in_array($delivery->status, ['completed', 'cancelled'], true))
                <form action="{{ route('deliveries.cancel', $delivery) }}" method="POST" style="margin:0;">
                    @csrf
                    @method('PUT')
                    <button type="submit" class="detail-btn-secondary" onclick="return confirm('Cancel this {{ $delivery->type }} task?');">Cancel</button>
                </form>
            @endif
        </div>
        <div class="mobile-inline-actions">
            @if($linkedPhone)
                <a href="tel:{{ preg_replace('/\D+/', '', $linkedPhone) }}" class="detail-btn-secondary">Call</a>
            @endif
            @if($delivery->status === 'pending')
                <form action="{{ route('deliveries.in_progress', $delivery) }}" method="POST" style="margin:0;">
                    @csrf
                    @method('PUT')
                    <button type="submit" class="detail-btn" style="width:100%;">Start</button>
                </form>
            @elseif($delivery->status === 'in_progress')
                <form action="{{ route('deliveries.complete', $delivery) }}" method="POST" style="margin:0;">
                    @csrf
                    @method('PUT')
                    @if((!$isSaleTask && $delivery->type === 'delivery' && $delivery->rental?->pendingDeliveryQuantityTotal() > 0) || (!$isSaleTask && $delivery->type === 'pickup' && $delivery->rental?->pendingPickupQuantityTotal() > 0))
                        <input type="hidden" name="confirm_partial" value="1">
                        <button type="submit" class="detail-btn" style="width:100%;">Complete Partial</button>
                    @else
                        <button type="submit" class="detail-btn" style="width:100%;">Complete</button>
                    @endif
                </form>
            @endif
        </div>
    </div>

    <div class="detail-card">
        <div class="detail-grid">
            <div class="span-4">
                <span class="label">Status</span>
                <span class="status-badge" style="{{ $statusStyle }}">{{ ucfirst(str_replace('_', ' ', $delivery->status)) }}</span>
            </div>
            <div class="span-4">
                <span class="label">Assignment Type</span>
                <div class="value">{{ ucwords(str_replace('_', ' ', $delivery->assignment_type ?? 'delivery_team')) }}</div>
            </div>
            <div class="span-4">
                <span class="label">Scheduled At</span>
                <div class="value">{{ $delivery->scheduled_at ? $delivery->scheduled_at->format('d M Y h:i A') : 'Not scheduled' }}</div>
            </div>
        </div>
    </div>

    @if(!$isSaleTask)
    <div class="detail-card">
        <div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; align-items:flex-start;">
            <div>
                <h2 style="margin:0;">Item Progress</h2>
                <div style="margin-top:6px; color:#64748b; font-size:13px;">Record delivered and picked-up quantities per rental item without closing the entire rental at once.</div>
            </div>
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <span class="status-badge" style="{{ $itemProgressBadge($delivery->rental?->deliveryStatus()) }}">{{ $itemProgressLabel($delivery->rental?->deliveryStatus()) }}</span>
                <span class="status-badge" style="{{ $itemProgressBadge($delivery->rental?->pickupStatus()) }}">{{ $itemProgressLabel($delivery->rental?->pickupStatus()) }}</span>
            </div>
        </div>
        <div style="overflow:auto; margin-top:14px;">
            <table class="item-progress-table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Ordered</th>
                        <th>Delivered</th>
                        <th>Pending Delivery</th>
                        <th>Returned</th>
                        <th>Pending Pickup</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rentalItems as $item)
                        @php
                            $orderedQty = (int) ($item->ordered_quantity ?? $item->quantity ?? 0);
                            $deliveredQty = (int) ($item->delivered_quantity_value ?? 0);
                            $pendingDeliveryQty = (int) ($item->pending_delivery_quantity ?? max($orderedQty - $deliveredQty, 0));
                            $returnedQty = (int) ($item->returned_quantity_value ?? 0);
                            $pendingPickupQty = (int) ($item->pending_pickup_quantity ?? max($deliveredQty - $returnedQty, 0));
                            $linkedAssetIds = collect($item->asset_ids ?? [])
                                ->filter(fn ($assetId) => filled($assetId))
                                ->map(fn ($assetId) => (int) $assetId)
                                ->filter(fn ($assetId) => $assetId > 0)
                                ->values();
                            $hasAwaitingVerificationAsset = $linkedAssetIds->isNotEmpty()
                                && \App\Models\Asset::query()
                                    ->where('organization_id', $delivery->organization_id)
                                    ->whereIn('id', $linkedAssetIds->all())
                                    ->where('asset_status', \App\Models\Asset::STATUS_AWAITING_VERIFICATION)
                                    ->exists();
                            $itemDeliveryStatus = $pendingDeliveryQty > 0
                                ? ($deliveredQty > 0 ? 'partially_delivered' : 'delivery_pending')
                                : ($deliveredQty > 0 ? 'delivered' : 'delivery_pending');
                            $itemLifecycleStatuses = collect();

                            if ($deliveredQty > $returnedQty) {
                                $itemLifecycleStatuses->push('with_customer');
                            }

                            if ($pendingPickupQty > 0) {
                                $itemLifecycleStatuses->push($returnedQty > 0 ? 'partially_returned' : 'pickup_pending');
                            } elseif ($deliveredQty > 0 && $returnedQty === $deliveredQty) {
                                $itemLifecycleStatuses->push($hasAwaitingVerificationAsset ? 'awaiting_verification' : 'returned');
                            }

                            $itemLifecycleStatuses = $itemLifecycleStatuses->unique()->values();
                        @endphp
                        <tr>
                            <td>
                                <div class="value" style="font-weight:700;">{{ $item->product->name ?? $delivery->rental?->product?->name ?? 'Rental item' }}</div>
                                @if(!empty($item->notes))
                                    <div style="margin-top:4px; color:#64748b; font-size:12px;">{{ $item->notes }}</div>
                                @endif
                            </td>
                            <td class="value">{{ $orderedQty }}</td>
                            <td class="value">{{ $deliveredQty }}</td>
                            <td class="value">{{ $pendingDeliveryQty }}</td>
                            <td class="value">{{ $returnedQty }}</td>
                            <td class="value">{{ $pendingPickupQty }}</td>
                            <td>
                                <div style="display:grid; gap:6px;">
                                    <span class="status-badge" style="{{ $itemProgressBadge($itemDeliveryStatus) }}">{{ $itemProgressLabel($itemDeliveryStatus) }}</span>
                                    @foreach($itemLifecycleStatuses as $itemLifecycleStatus)
                                        <span class="status-badge" style="{{ $itemProgressBadge($itemLifecycleStatus) }}">{{ $itemProgressLabel($itemLifecycleStatus) }}</span>
                                    @endforeach
                                </div>
                            </td>
                            <td>
                                @if($delivery->type === 'delivery' && in_array($delivery->status, ['pending', 'in_progress'], true) && $pendingDeliveryQty > 0)
                                    <form method="POST" action="{{ route('deliveries.partial_delivery', $delivery) }}" class="item-progress-form">
                                        @csrf
                                        @method('PUT')
                                        <input type="hidden" name="rental_item_id" value="{{ $item->id }}">
                                        <input type="number" name="quantity" min="1" max="{{ $pendingDeliveryQty }}" value="1" aria-label="Deliver quantity">
                                        <button type="submit" class="detail-btn">Deliver</button>
                                    </form>
                                @elseif($delivery->type === 'pickup' && in_array($delivery->status, ['pending', 'in_progress'], true) && $pendingPickupQty > 0)
                                    <form method="POST" action="{{ route('deliveries.partial_pickup', $delivery) }}" class="item-progress-form">
                                        @csrf
                                        @method('PUT')
                                        <input type="hidden" name="rental_item_id" value="{{ $item->id }}">
                                        <input type="number" name="quantity" min="1" max="{{ $pendingPickupQty }}" value="1" aria-label="Pickup quantity">
                                        <button type="submit" class="detail-btn">Pickup</button>
                                    </form>
                                @else
                                    <span style="color:#64748b; font-size:12px;">No action pending.</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    @include('partials.activity-timeline', [
        'logs' => $activityLogs ?? collect(),
        'title' => 'Operations History',
        'subtitle' => 'Assignment, start, completion, and linked order updates for this task.',
    ])

    <div class="detail-grid">
        <div class="detail-card span-6">
            <h2 style="margin-top:0;">{{ $isSaleTask ? 'Sale Order' : 'Rental' }}</h2>
            <div class="detail-grid">
                <div class="span-6">
                    <span class="label">{{ $isSaleTask ? 'Sale' : 'Rental' }}</span>
                    <div class="value">#{{ $isSaleTask ? ($delivery->sale->id ?? '-') : ($delivery->rental->id ?? '-') }}</div>
                </div>
                <div class="span-6">
                    <span class="label">Customer</span>
                    <div class="value">{{ $isSaleTask ? ($delivery->sale?->customer?->name ?? 'N/A') : ($delivery->rental?->customer_name ?? 'N/A') }}</div>
                </div>
                <div class="span-6">
                    <span class="label">Product</span>
                    <div class="value">{{ $isSaleTask ? ($delivery->sale?->product?->name ?? 'N/A') : ($delivery->rental?->product?->name ?? 'N/A') }}</div>
                </div>
                <div class="span-6">
                    <span class="label">Warehouse</span>
                    <div class="value">{{ $isSaleTask ? ($delivery->sale?->asset?->warehouse?->name ?? 'Sale dispatch') : ($delivery->rental?->dispatchWarehouse?->name ?? 'Any warehouse') }}</div>
                </div>
            </div>
        </div>

        <div class="detail-card span-6">
            <h2 style="margin-top:0;">Assignee</h2>
            <div class="detail-grid">
                <div class="span-6">
                    <span class="label">{{ $assigneeSecondaryLabel }}</span>
                    <div class="value">{{ $assigneeName }}</div>
                </div>
                <div class="span-6">
                    <span class="label">Role</span>
                    <div class="value">{{ $assigneeRole }}</div>
                </div>
                @if($showThirdPartyDetails)
                    <div class="span-6">
                        <span class="label">Third Party</span>
                        <div class="value">{{ $delivery->third_party_name ?: 'Assigned' }}</div>
                    </div>
                @endif
                <div class="span-6">
                    <span class="label">Contact</span>
                    <div class="value">{{ $assigneeContact }}</div>
                </div>
                <div class="span-12">
                    <span class="label">Notes</span>
                    <div class="value">{{ $delivery->notes ?: 'No notes added.' }}</div>
                </div>
            </div>
        </div>

        @if(!$isSaleTask)
        <div class="detail-card span-12">
            <h2 style="margin-top:0;">Linked Assets</h2>
            <div class="asset-grid">
                @forelse($rentalAssets as $assignment)
                    <div class="asset-box">
                        <span class="label">Category</span>
                        <div class="value">Rental Asset</div>
                        <span class="label">Serial</span>
                        <div class="value">{{ $assignment->asset->serial_number ?? 'N/A' }}</div>
                        <span class="label" style="margin-top:10px;">Barcode</span>
                        <div class="value">{{ $assignment->asset->barcode_value ?? '-' }}</div>
                    </div>
                @empty
                @endforelse

                @if($delivery->type === 'delivery')
                    @foreach($deliverySaleAssets as $saleItem)
                        <div class="asset-box">
                            <span class="label">Category</span>
                            <div class="value">New Product With Rental</div>
                            <span class="label" style="margin-top:10px;">Product</span>
                            <div class="value">{{ $saleItem->product->name ?? 'New product' }}</div>
                            <span class="label" style="margin-top:10px;">Serial</span>
                            <div class="value">{{ $saleItem->asset->serial_number ?? 'N/A' }}</div>
                            <span class="label" style="margin-top:10px;">Warehouse</span>
                            <div class="value">{{ $saleItem->asset->warehouse->name ?? '-' }}</div>
                        </div>
                    @endforeach
                @endif

                @if($rentalAssets->isEmpty() && $deliverySaleAssets->isEmpty())
                    <div class="asset-box">
                        <div class="value">No specific assets linked to this task.</div>
                    </div>
                @endif
            </div>
        </div>
        @endif
    </div>
</div>

<div class="mobile-sticky-actions" aria-label="Delivery primary actions">
    @if($linkedPhone)
        <a href="tel:{{ preg_replace('/\D+/', '', $linkedPhone) }}">Call</a>
    @endif
    @if($delivery->status === 'pending')
        <form action="{{ route('deliveries.in_progress', $delivery) }}" method="POST">
            @csrf
            @method('PUT')
            <button type="submit" class="is-primary">Start</button>
        </form>
    @elseif($delivery->status === 'in_progress')
        <form action="{{ route('deliveries.complete', $delivery) }}" method="POST">
            @csrf
            @method('PUT')
            @if((!$isSaleTask && $delivery->type === 'delivery' && $delivery->rental?->pendingDeliveryQuantityTotal() > 0) || (!$isSaleTask && $delivery->type === 'pickup' && $delivery->rental?->pendingPickupQuantityTotal() > 0))
                <input type="hidden" name="confirm_partial" value="1">
                <button type="submit" class="is-primary">Complete Partial</button>
            @else
                <button type="submit" class="is-primary">Complete</button>
            @endif
        </form>
    @endif
    <details class="mobile-actions-menu">
        <summary type="button">More</summary>
        <div class="mobile-actions-panel">
            <a href="{{ route('deliveries.index') }}">Back to Tasks Board</a>
            <a href="{{ route('deliveries.edit', $delivery) }}">Edit Assignment</a>
            @if(auth()->user()?->canAccessModule('deliveries', 'update') && !in_array($delivery->status, ['completed', 'cancelled'], true))
                <form action="{{ route('deliveries.cancel', $delivery) }}" method="POST" style="margin:0;">
                    @csrf
                    @method('PUT')
                    <button type="submit" class="is-danger" onclick="return confirm('Cancel this {{ $delivery->type }} task?');">Cancel Task</button>
                </form>
            @endif
        </div>
    </details>
</div>
@endsection
