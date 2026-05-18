@extends('layouts.app')

@section('content')
@php
    $isSaleTask = (bool) $delivery->sale_id;
    $linkedCustomer = $isSaleTask ? $delivery->sale?->customer : $delivery->rental?->customer;
    $linkedPhone = $linkedCustomer?->phone ?: ($delivery->rental?->phone ?? null);
    $linkedWhatsapp = $linkedCustomer?->preferredWhatsAppNumber() ?: $linkedPhone;
    $rentalAssets = $delivery->rental?->activeRentalAssets ?? collect();
    $rentalSaleItems = $delivery->rental?->saleItems ?? collect();
    $pickupRecord = $delivery->rental?->pickupRecord;
    $pickupAssigned = $pickupRecord && $pickupRecord->status !== 'cancelled';
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
    $displayStatus = $delivery->status;

    if (!$isSaleTask && $delivery->rental) {
        if ($delivery->type === 'delivery') {
            $displayStatus = match ($delivery->rental->deliveryStatus()) {
                'completed', 'delivered' => 'delivered',
                'partially_delivered' => 'in_progress',
                default => $delivery->status,
            };
        } elseif ($delivery->type === 'pickup') {
            $displayStatus = match ($delivery->rental->pickupStatus()) {
                'completed', 'picked_up', 'returned' => 'picked_up',
                'partial_return' => 'in_progress',
                default => $delivery->status,
            };
        }
    }

    $statusStyle = match ($displayStatus) {
        'pending' => 'background:#e2e8f0;color:#334155;',
        'in_progress' => 'background:#fef3c7;color:#b45309;',
        'delivered', 'picked_up', 'completed' => 'background:#dcfce7;color:#166534;',
        'cancelled' => 'background:#f1f5f9;color:#64748b;',
        default => 'background:#f1f5f9;color:#475569;',
    };
    $itemProgressBadge = fn (?string $status) => match ($status) {
        'partial', 'partially_delivered', 'partial_return', 'partially_returned', 'with_customer' => 'background:#dbeafe;color:#1d4ed8;',
        'delivered', 'picked_up', 'completed', 'returned' => 'background:#dcfce7;color:#166534;',
        'awaiting_verification' => 'background:#fef3c7;color:#b45309;',
        'pickup_pending', 'delivery_pending' => 'background:#fef3c7;color:#b45309;',
        'pickup_not_assigned' => 'background:#e2e8f0;color:#475569;',
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
        'pickup_not_assigned' => 'Pickup Not Assigned',
        'delivery_pending' => 'Delivery Pending',
        'returned' => 'Returned',
        'awaiting_verification' => 'Awaiting Verification',
        'completed' => 'Completed',
        default => ucfirst(str_replace('_', ' ', $status ?: 'pending')),
    };
    $displayStatusLabel = match ($displayStatus) {
        'delivered' => 'Delivered',
        'picked_up' => 'Picked Up',
        default => ucfirst(str_replace('_', ' ', $displayStatus)),
    };
    $canUpdateTask = auth()->user()?->can('update', $delivery) ?? false;
    $canDeleteTask = auth()->user()?->can('delete', $delivery) ?? false;
    $deliveryProofs = collect($deliveryProofs ?? []);
    $proofConfig = $proofConfig ?? ['max_kb' => 100, 'target_kb' => 50, 'max_dimension' => 1024];
    $workflowProofSectionId = 'workflow-proof-section';
    $workflowStage = $delivery->type === 'pickup' ? \App\Models\DeliveryProof::STAGE_PICKUP : \App\Models\DeliveryProof::STAGE_DELIVERY;
    $acknowledgementText = \App\Models\DeliveryProof::acknowledgementFor($workflowStage, ! $isSaleTask);
    $locationProofs = $deliveryProofs->where('proof_type', \App\Models\DeliveryProof::TYPE_LOCATION)->values();
    $fileProofs = $deliveryProofs->reject(fn ($proof) => $proof->proof_type === \App\Models\DeliveryProof::TYPE_LOCATION)->values();
    $hasPendingWorkflowCapture = $canUpdateTask && in_array($delivery->status, ['pending', 'in_progress'], true);
    $hasProofHistory = $deliveryProofs->isNotEmpty();
    $primaryWorkflowCtaLabel = $delivery->status === 'pending'
        ? ($delivery->type === 'pickup' ? 'Start Pickup' : 'Start Delivery')
        : ($delivery->type === 'pickup' ? 'Complete Pickup' : 'Complete Delivery');
    $workflowErrorFields = [
        'delivery_device_photos',
        'delivery_device_photos.*',
        'premises_photo',
        'pickup_device_photos',
        'pickup_device_photos.*',
        'damage_photos',
        'damage_photos.*',
        'damage_notes',
        'missing_accessories_notes',
        'signature_data',
        'location_missing_reason',
        'location_latitude',
        'location_longitude',
    ];
    $hasWorkflowErrors = collect($workflowErrorFields)->contains(fn ($field) => $errors->has($field));

    $mobileQuickActions = collect([
        [
            'type' => 'link',
            'label' => 'Back',
            'href' => route('deliveries.index'),
        ],
    ]);

    if ($linkedPhone) {
        $mobileQuickActions->push([
            'type' => 'link',
            'label' => 'Call',
            'href' => 'tel:' . preg_replace('/\D+/', '', $linkedPhone),
        ]);
    }

    if ($linkedWhatsapp) {
        $mobileQuickActions->push([
            'type' => 'link',
            'label' => 'WhatsApp',
            'href' => 'https://wa.me/' . preg_replace('/\D+/', '', $linkedWhatsapp),
            'target' => '_blank',
            'rel' => 'noopener noreferrer',
        ]);
    }

    if ($hasProofHistory) {
        $mobileQuickActions->push([
            'type' => 'link',
            'label' => 'Proof History',
            'href' => '#delivery-proof-history',
        ]);
    }

    if ($canUpdateTask) {
        $mobileQuickActions->push([
            'type' => 'link',
            'label' => 'Edit Assignment',
            'href' => route('deliveries.edit', $delivery),
        ]);
    }

    if ($canUpdateTask && !in_array($delivery->status, ['completed', 'cancelled'], true)) {
        $mobileQuickActions->push([
            'type' => 'form',
            'label' => 'Cancel Task',
            'action' => route('deliveries.cancel', $delivery),
            'method' => 'PUT',
            'confirm' => 'Cancel this ' . $delivery->type . ' task?',
            'danger' => true,
        ]);
    }

    $mobileQuickActions = $mobileQuickActions->values();
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
    .delivery-page-spacer {
        display:none;
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
    .workflow-proof-card { display:grid; gap:14px; }
    .workflow-proof-grid { display:grid; grid-template-columns:repeat(12, minmax(0, 1fr)); gap:14px; }
    .workflow-proof-field { grid-column:span 6; display:grid; gap:6px; }
    .workflow-proof-field.span-12 { grid-column:span 12; }
    .workflow-proof-field label { font-size:12px; font-weight:700; color:#334155; }
    .workflow-proof-field input[type="text"],
    .workflow-proof-field input[type="file"],
    .workflow-proof-field textarea {
        width:100%;
        border:1px solid #cbd5e1;
        border-radius:12px;
        padding:10px 12px;
        font-size:13px;
        color:#0f172a;
        background:#fff;
    }
    .workflow-proof-field textarea { min-height:86px; resize:vertical; }
    .workflow-proof-help { color:#64748b; font-size:12px; line-height:1.45; }
    .workflow-proof-badges { display:flex; gap:8px; flex-wrap:wrap; }
    .workflow-proof-badge {
        display:inline-flex;
        align-items:center;
        gap:6px;
        padding:6px 10px;
        border-radius:999px;
        background:#eff6ff;
        color:#1d4ed8;
        font-size:11px;
        font-weight:800;
    }
    .workflow-proof-actions { display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
    .workflow-proof-trigger {
        display:inline-flex;
        align-items:center;
        justify-content:center;
        gap:6px;
        min-height:42px;
        padding:10px 14px;
        border-radius:12px;
        border:1px solid #cbd5e1;
        background:#f8fafc;
        color:#0f172a;
        font-size:13px;
        font-weight:800;
        text-decoration:none;
    }
    .workflow-proof-trigger.is-primary {
        background:#2563eb;
        border-color:#2563eb;
        color:#fff;
    }
    .workflow-proof-status {
        min-height:38px;
        padding:10px 12px;
        border-radius:12px;
        border:1px dashed #cbd5e1;
        background:#f8fafc;
        color:#475569;
        font-size:12px;
        line-height:1.45;
    }
    .workflow-proof-status.is-success {
        border-style:solid;
        border-color:#86efac;
        background:#f0fdf4;
        color:#166534;
    }
    .workflow-proof-status.is-warning {
        border-style:solid;
        border-color:#fdba74;
        background:#fff7ed;
        color:#9a3412;
    }
    .workflow-proof-signature-wrap {
        border:1px solid #dbe3ef;
        border-radius:16px;
        padding:12px;
        background:#fcfdff;
        display:grid;
        gap:10px;
    }
    .workflow-proof-signature-pad {
        width:100%;
        height:180px;
        border:1px solid #cbd5e1;
        border-radius:14px;
        background:#fff;
        touch-action:none;
        cursor:crosshair;
    }
    .workflow-proof-history {
        display:grid;
        gap:12px;
    }
    .workflow-proof-history-item {
        display:grid;
        grid-template-columns:minmax(0, 120px) minmax(0, 1fr);
        gap:12px;
        padding:12px;
        border:1px solid #e2e8f0;
        border-radius:14px;
        background:#fff;
    }
    .workflow-proof-history-thumb {
        width:100%;
        max-width:120px;
        border-radius:12px;
        border:1px solid #dbe3ef;
        object-fit:cover;
        background:#f8fafc;
    }
    .workflow-proof-history-meta { display:grid; gap:6px; }
    .workflow-proof-history-meta strong { color:#0f172a; }
    .workflow-proof-history-meta small { color:#64748b; font-size:12px; }
    .workflow-proof-divider { height:1px; background:#e2e8f0; margin:4px 0; }
    @media (max-width: 900px) {
        .span-4, .span-6 { grid-column:span 12; }
        .item-progress-table, .item-progress-table tbody, .item-progress-table tr, .item-progress-table td { display:block; width:100%; }
        .item-progress-table thead { display:none; }
        .item-progress-table tr { border:1px solid #e2e8f0; border-radius:12px; margin-bottom:10px; overflow:hidden; }
        .workflow-proof-field { grid-column:span 12; }
        .workflow-proof-history-item { grid-template-columns:1fr; }
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
        .mobile-inline-actions .detail-btn-secondary,
        .mobile-inline-actions .detail-btn,
        .mobile-inline-actions .mobile-actions-menu summary {
            width:100%;
            min-height:44px;
            box-sizing:border-box;
        }
        .delivery-page-spacer {
            display:block;
            height:calc(96px + env(safe-area-inset-bottom, 0px));
            pointer-events:none;
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
            @if($canUpdateTask && $delivery->status === 'pending')
                <a href="#{{ $workflowProofSectionId }}" class="detail-btn">{{ $primaryWorkflowCtaLabel }}</a>
            @elseif($canUpdateTask && $delivery->status === 'in_progress')
                <a href="#{{ $workflowProofSectionId }}" class="detail-btn">{{ $primaryWorkflowCtaLabel }}</a>
            @elseif($hasProofHistory)
                <a href="#delivery-proof-history" class="detail-btn">View Proof</a>
            @endif
            @if($canUpdateTask)
                <a href="{{ route('deliveries.edit', $delivery) }}" class="detail-btn-secondary">Edit</a>
            @endif
            @if($canUpdateTask && !in_array($delivery->status, ['completed', 'cancelled'], true))
                <form action="{{ route('deliveries.cancel', $delivery) }}" method="POST" style="margin:0;">
                    @csrf
                    @method('PUT')
                    <button type="submit" class="detail-btn-secondary" onclick="return confirm('Cancel this {{ $delivery->type }} task?');">Cancel</button>
                </form>
            @endif
        </div>
    </div>
    <div class="mobile-inline-actions" aria-label="Delivery quick actions">
        @foreach($mobileQuickActions as $action)
            @php
                $method = strtoupper((string) ($action['method'] ?? 'POST'));
            @endphp
            @if(($action['type'] ?? 'link') === 'form')
                <form action="{{ $action['action'] }}" method="{{ in_array($method, ['GET', 'POST'], true) ? $method : 'POST' }}" @if(filled($action['confirm'] ?? null)) onsubmit="return confirm('{{ e($action['confirm']) }}');" @endif style="margin:0;">
                    @csrf
                    @if(!in_array($method, ['GET', 'POST'], true))
                        @method($method)
                    @endif
                    <button type="submit" class="detail-btn-secondary{{ !empty($action['danger']) ? ' is-danger' : '' }}" style="{{ !empty($action['danger']) ? 'background:#fff1f2;border-color:#fecaca;color:#991b1b;' : '' }}">{{ $action['label'] }}</button>
                </form>
            @else
                <a href="{{ $action['href'] }}" class="detail-btn-secondary" @if(!empty($action['target'])) target="{{ $action['target'] }}" @endif @if(!empty($action['rel'])) rel="{{ $action['rel'] }}" @endif>{{ $action['label'] }}</a>
            @endif
        @endforeach
    </div>

    <div class="detail-card">
        <div class="detail-grid">
            <div class="span-4">
                <span class="label">Status</span>
                <span class="status-badge" style="{{ $statusStyle }}">{{ $displayStatusLabel }}</span>
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
                                if (!$pickupAssigned) {
                                    $itemLifecycleStatuses->push('pickup_not_assigned');
                                } else {
                                    $itemLifecycleStatuses->push($returnedQty > 0 ? 'partially_returned' : 'pickup_pending');
                                }
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
                                @if($canUpdateTask && $delivery->type === 'delivery' && in_array($delivery->status, ['pending', 'in_progress'], true) && $pendingDeliveryQty > 0)
                                    <form method="POST" action="{{ route('deliveries.partial_delivery', $delivery) }}" class="item-progress-form">
                                        @csrf
                                        @method('PUT')
                                        <input type="hidden" name="rental_item_id" value="{{ $item->id }}">
                                        <input type="number" name="quantity" min="1" max="{{ $pendingDeliveryQty }}" value="1" aria-label="Deliver quantity">
                                        <button type="submit" class="detail-btn">Deliver</button>
                                    </form>
                                @elseif($canUpdateTask && $delivery->type === 'pickup' && in_array($delivery->status, ['pending', 'in_progress'], true) && $pendingPickupQty > 0)
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

    <div class="detail-card workflow-proof-card" id="{{ $workflowProofSectionId }}">
        <div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; align-items:flex-start;">
            <div>
                <h2 style="margin:0;">{{ $delivery->type === 'pickup' ? 'Pickup' : 'Delivery' }} Proof Capture</h2>
                <div style="margin-top:6px; color:#64748b; font-size:13px;">Capture location, customer acknowledgement, and small compressed proof images without exposing files publicly.</div>
            </div>
            <div class="workflow-proof-badges">
                <span class="workflow-proof-badge">Target image size {{ $proofConfig['target_kb'] }} KB</span>
                <span class="workflow-proof-badge">Max image size {{ $proofConfig['max_kb'] }} KB</span>
                <span class="workflow-proof-badge">Max dimension {{ $proofConfig['max_dimension'] }} px</span>
            </div>
        </div>
        @if($hasWorkflowErrors)
            <div class="workflow-proof-status is-warning" data-workflow-error-summary tabindex="-1">
                Please complete the required proof fields marked below before continuing this {{ $delivery->type }} task.
            </div>
        @endif

        @if($canUpdateTask && $delivery->status === 'pending')
            <form action="{{ route('deliveries.in_progress', $delivery) }}" method="POST" class="workflow-proof-card" data-workflow-form="start">
                @csrf
                @method('PUT')
                <input type="hidden" name="workflow_capture_form" value="1">
                <div class="workflow-proof-grid">
                    <div class="workflow-proof-field span-12">
                        <label>Start location capture</label>
                        <div class="workflow-proof-help">Use browser GPS at {{ $delivery->type }} start. If location permission is denied, add the reason and continue.</div>
                        <div class="workflow-proof-actions">
                            <button type="button" class="workflow-proof-trigger is-primary" data-capture-location>Capture Current Location</button>
                            <button type="button" class="workflow-proof-trigger" data-recapture-location hidden>Re-capture Location</button>
                            <button type="button" class="workflow-proof-trigger" data-clear-location hidden>Clear Location</button>
                            <a href="#delivery-proof-history" class="workflow-proof-trigger">Jump to Proof History</a>
                        </div>
                        <div class="workflow-proof-status" data-location-status>Location not captured yet.</div>
                    </div>
                    <div class="workflow-proof-field">
                        <label for="location_missing_reason_start">Location unavailable reason</label>
                        <textarea id="location_missing_reason_start" name="location_missing_reason" placeholder="Explain why location could not be captured.">{{ old('location_missing_reason') }}</textarea>
                        @error('location_missing_reason')
                            <div class="workflow-proof-help" style="color:#b91c1c;">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="workflow-proof-field">
                        <label>Captured coordinates</label>
                        <div class="workflow-proof-help">Latitude, longitude, and accuracy are filled automatically when GPS succeeds.</div>
                        <div class="workflow-proof-status">
                            <div>Lat: <span data-location-lat-preview>{{ old('location_latitude', '-') }}</span></div>
                            <div>Lng: <span data-location-lng-preview>{{ old('location_longitude', '-') }}</span></div>
                            <div>Accuracy: <span data-location-accuracy-preview>{{ old('location_accuracy', '-') }}</span></div>
                            <div>Captured: <span data-location-captured-preview>{{ old('location_captured_at', '-') }}</span></div>
                        </div>
                    </div>
                </div>
                <input type="hidden" name="location_latitude" value="{{ old('location_latitude') }}" data-location-latitude>
                <input type="hidden" name="location_longitude" value="{{ old('location_longitude') }}" data-location-longitude>
                <input type="hidden" name="location_accuracy" value="{{ old('location_accuracy') }}" data-location-accuracy>
                <input type="hidden" name="location_captured_at" value="{{ old('location_captured_at') }}" data-location-captured-at>
                <div class="workflow-proof-actions">
                    <button type="submit" class="detail-btn">{{ $delivery->type === 'pickup' ? 'Start Pickup' : 'Start Delivery' }}</button>
                    <div class="workflow-proof-help">Start is blocked until GPS is captured or a missing-location reason is provided.</div>
                </div>
            </form>
        @elseif($canUpdateTask && $delivery->status === 'in_progress')
            <form action="{{ route('deliveries.complete', $delivery) }}" method="POST" enctype="multipart/form-data" class="workflow-proof-card" data-workflow-form="complete">
                @csrf
                @method('PUT')
                <input type="hidden" name="workflow_capture_form" value="1">
                @if((!$isSaleTask && $delivery->type === 'delivery' && $delivery->rental?->pendingDeliveryQuantityTotal() > 0) || (!$isSaleTask && $delivery->type === 'pickup' && $delivery->rental?->pendingPickupQuantityTotal() > 0))
                    <input type="hidden" name="confirm_partial" value="1">
                @endif
                <div class="workflow-proof-grid">
                    @if($delivery->type === 'delivery')
                        <div class="workflow-proof-field">
                            <label for="delivery_device_photos">Delivered device photo(s)</label>
                            <input id="delivery_device_photos" type="file" name="delivery_device_photos[]" accept="image/*" capture="environment" multiple data-compress-images>
                            <div class="workflow-proof-help">At least one compressed device or product photo is required at delivery.</div>
                            @error('delivery_device_photos')
                                <div class="workflow-proof-help" style="color:#b91c1c;">{{ $message }}</div>
                            @enderror
                            @error('delivery_device_photos.*')
                                <div class="workflow-proof-help" style="color:#b91c1c;">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="workflow-proof-field">
                            <label for="premises_photo">Premises / location photo</label>
                            <input id="premises_photo" type="file" name="premises_photo" accept="image/*" capture="environment" data-compress-images>
                            <div class="workflow-proof-help">Premises photo is required only during delivery completion.</div>
                            @error('premises_photo')
                                <div class="workflow-proof-help" style="color:#b91c1c;">{{ $message }}</div>
                            @enderror
                        </div>
                    @else
                        <div class="workflow-proof-field">
                            <label for="pickup_device_photos">Picked-up device photo(s)</label>
                            <input id="pickup_device_photos" type="file" name="pickup_device_photos[]" accept="image/*" capture="environment" multiple data-compress-images>
                            <div class="workflow-proof-help">At least one compressed pickup photo is required.</div>
                            @error('pickup_device_photos')
                                <div class="workflow-proof-help" style="color:#b91c1c;">{{ $message }}</div>
                            @enderror
                            @error('pickup_device_photos.*')
                                <div class="workflow-proof-help" style="color:#b91c1c;">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="workflow-proof-field">
                            <label for="damage_photos">Damage photo(s)</label>
                            <input id="damage_photos" type="file" name="damage_photos[]" accept="image/*" capture="environment" multiple data-compress-images>
                            <div class="workflow-proof-help">Required if damage is reported at pickup.</div>
                            @error('damage_photos')
                                <div class="workflow-proof-help" style="color:#b91c1c;">{{ $message }}</div>
                            @enderror
                            @error('damage_photos.*')
                                <div class="workflow-proof-help" style="color:#b91c1c;">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="workflow-proof-field span-12">
                            <label style="display:flex; align-items:center; gap:8px;">
                                <input type="checkbox" name="damage_reported" value="1" {{ old('damage_reported') ? 'checked' : '' }}>
                                Damage or missing accessories reported at pickup
                            </label>
                            @error('damage_reported')
                                <div class="workflow-proof-help" style="color:#b91c1c;">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="workflow-proof-field">
                            <label for="damage_notes">Damage notes</label>
                            <textarea id="damage_notes" name="damage_notes" placeholder="Describe visible damage or concerns.">{{ old('damage_notes') }}</textarea>
                            @error('damage_notes')
                                <div class="workflow-proof-help" style="color:#b91c1c;">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="workflow-proof-field">
                            <label for="missing_accessories_notes">Missing accessories notes</label>
                            <textarea id="missing_accessories_notes" name="missing_accessories_notes" placeholder="List missing adapters, masks, humidifiers, etc.">{{ old('missing_accessories_notes') }}</textarea>
                            @error('missing_accessories_notes')
                                <div class="workflow-proof-help" style="color:#b91c1c;">{{ $message }}</div>
                            @enderror
                        </div>
                    @endif

                    <div class="workflow-proof-field span-12">
                        <label>{{ ucfirst($delivery->type) }} completion location</label>
                        <div class="workflow-proof-help">Capture GPS on completion. If blocked, give a reason so the workflow is marked as location-missing.</div>
                        <div class="workflow-proof-actions">
                            <button type="button" class="workflow-proof-trigger is-primary" data-capture-location>Capture Current Location</button>
                            <button type="button" class="workflow-proof-trigger" data-recapture-location hidden>Re-capture Location</button>
                            <button type="button" class="workflow-proof-trigger" data-clear-location hidden>Clear Location</button>
                        </div>
                        <div class="workflow-proof-status" data-location-status>Location not captured yet.</div>
                    </div>
                    <div class="workflow-proof-field">
                        <label for="location_missing_reason_complete">Location unavailable reason</label>
                        <textarea id="location_missing_reason_complete" name="location_missing_reason" placeholder="Explain why GPS could not be captured.">{{ old('location_missing_reason') }}</textarea>
                        @error('location_missing_reason')
                            <div class="workflow-proof-help" style="color:#b91c1c;">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="workflow-proof-field">
                        <label>Captured coordinates</label>
                        <div class="workflow-proof-status">
                            <div>Lat: <span data-location-lat-preview>{{ old('location_latitude', '-') }}</span></div>
                            <div>Lng: <span data-location-lng-preview>{{ old('location_longitude', '-') }}</span></div>
                            <div>Accuracy: <span data-location-accuracy-preview>{{ old('location_accuracy', '-') }}</span></div>
                            <div>Captured: <span data-location-captured-preview>{{ old('location_captured_at', '-') }}</span></div>
                        </div>
                    </div>

                    <div class="workflow-proof-field span-12">
                        <label>Acknowledgement required before signature</label>
                        <div class="workflow-proof-signature-wrap">
                            <div class="workflow-proof-help">{{ $acknowledgementText }}</div>
                            <canvas class="workflow-proof-signature-pad" data-signature-pad data-target-input="signature_data"></canvas>
                            <input type="hidden" name="signature_data" value="{{ old('signature_data') }}">
                            <div class="workflow-proof-actions">
                                <button type="button" class="workflow-proof-trigger" data-signature-clear>Clear Signature</button>
                                <div class="workflow-proof-help">Sign with a finger or stylus on mobile. Signature is stored privately.</div>
                            </div>
                            @error('signature_data')
                                <div class="workflow-proof-help" style="color:#b91c1c;">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="workflow-proof-field span-12">
                        <label for="proof_notes">Workflow notes</label>
                        <textarea id="proof_notes" name="proof_notes" placeholder="Add delivery or pickup notes for the operations team.">{{ old('proof_notes') }}</textarea>
                        @error('proof_notes')
                            <div class="workflow-proof-help" style="color:#b91c1c;">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
                <input type="hidden" name="location_latitude" value="{{ old('location_latitude') }}" data-location-latitude>
                <input type="hidden" name="location_longitude" value="{{ old('location_longitude') }}" data-location-longitude>
                <input type="hidden" name="location_accuracy" value="{{ old('location_accuracy') }}" data-location-accuracy>
                <input type="hidden" name="location_captured_at" value="{{ old('location_captured_at') }}" data-location-captured-at>
                <div class="workflow-proof-actions">
                    <button type="submit" class="detail-btn">{{ $delivery->type === 'pickup' ? 'Complete Pickup' : 'Complete Delivery' }}</button>
                    <div class="workflow-proof-help">Completion requires proof photos, customer signature, and location capture or a missing-location reason.</div>
                </div>
            </form>
        @elseif(!$canUpdateTask && in_array($delivery->status, ['pending', 'in_progress'], true))
            <div class="workflow-proof-status">You can review proof history for this task, but only the assigned workflow owner can capture start or completion proof.</div>
        @endif

        <div class="workflow-proof-divider"></div>

        <div id="delivery-proof-history" class="workflow-proof-history">
            <div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; align-items:flex-start;">
                <div>
                    <h3 style="margin:0; font-size:18px; color:#0f172a;">Proof History</h3>
                    <div class="workflow-proof-help">Private proof files, location captures, signatures, and damage notes captured for this task.</div>
                </div>
                <div class="workflow-proof-badges">
                    <span class="workflow-proof-badge">{{ $deliveryProofs->count() }} item{{ $deliveryProofs->count() === 1 ? '' : 's' }}</span>
                </div>
            </div>

            @forelse($deliveryProofs as $proof)
                @php
                    $proofLabel = \App\Models\DeliveryProof::labelForType($proof->proof_type);
                    $proofWhen = $proof->captured_at ?: $proof->created_at;
                    $proofMeta = collect($proof->meta ?? [])->filter(fn ($value) => filled($value));
                    $proofUrl = $proof->file_path ? route('deliveries.proofs.view', [$delivery, $proof]) : null;
                    $hasCoordinates = filled($proof->latitude) && filled($proof->longitude);
                @endphp
                <article class="workflow-proof-history-item">
                    <div>
                        @if($proofUrl)
                            <img src="{{ $proofUrl }}" alt="{{ $proofLabel }}" class="workflow-proof-history-thumb">
                        @else
                            <div class="workflow-proof-status {{ $hasCoordinates ? 'is-success' : 'is-warning' }}" style="min-height:120px; display:flex; align-items:center; justify-content:center;">
                                {{ $hasCoordinates ? 'GPS' : 'No file' }}
                            </div>
                        @endif
                    </div>
                    <div class="workflow-proof-history-meta">
                        <div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap;">
                            <strong>{{ $proofLabel }}</strong>
                            <small>{{ optional($proofWhen)->format('d M Y h:i A') }}</small>
                        </div>
                        <small>{{ ucfirst($proof->workflow_stage) }} {{ $proof->capture_moment ? ucfirst($proof->capture_moment) : 'Record' }} · Uploaded by {{ $proof->creator->name ?? 'System' }}</small>
                        @if($proof->acknowledgement_text)
                            <div class="workflow-proof-help"><strong>Acknowledgement:</strong> {{ $proof->acknowledgement_text }}</div>
                        @endif
                        @if($hasCoordinates)
                            <div class="workflow-proof-help"><strong>Coordinates:</strong> {{ number_format((float) $proof->latitude, 6) }}, {{ number_format((float) $proof->longitude, 6) }} @if(filled($proof->accuracy)) · Accuracy {{ number_format((float) $proof->accuracy, 1) }} m @endif</div>
                        @elseif($proof->proof_type === \App\Models\DeliveryProof::TYPE_LOCATION)
                            <div class="workflow-proof-help"><strong>Location missing reason:</strong> {{ $proof->notes ?: 'No reason provided' }}</div>
                        @endif
                        @if($proof->notes && $proof->proof_type !== \App\Models\DeliveryProof::TYPE_LOCATION)
                            <div class="workflow-proof-help"><strong>Notes:</strong> {{ $proof->notes }}</div>
                        @endif
                        @if($proofMeta->isNotEmpty())
                            <div class="workflow-proof-help">
                                @foreach($proofMeta as $metaKey => $metaValue)
                                    <div><strong>{{ str($metaKey)->replace('_', ' ')->title() }}:</strong> {{ is_bool($metaValue) ? ($metaValue ? 'Yes' : 'No') : $metaValue }}</div>
                                @endforeach
                            </div>
                        @endif
                        @if($proofUrl)
                            <div class="workflow-proof-actions">
                                <a href="{{ $proofUrl }}" target="_blank" class="workflow-proof-trigger">View Full Size</a>
                            </div>
                        @endif
                    </div>
                </article>
            @empty
                <div class="workflow-proof-status">No proof has been captured for this task yet.</div>
            @endforelse
        </div>
    </div>

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
<div class="delivery-page-spacer" aria-hidden="true"></div>
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const proofConfig = {
        targetBytes: {{ (int) ($proofConfig['target_kb'] ?? 50) * 1024 }},
        maxBytes: {{ (int) ($proofConfig['max_kb'] ?? 100) * 1024 }},
        maxDimension: {{ (int) ($proofConfig['max_dimension'] ?? 1024) }},
    };
    const workflowSection = document.getElementById(@json($workflowProofSectionId));
    const workflowErrorSummary = document.querySelector('[data-workflow-error-summary]');

    if (workflowSection && (window.location.hash === '#{{ $workflowProofSectionId }}' || {{ $hasWorkflowErrors ? 'true' : 'false' }})) {
        workflowSection.scrollIntoView({ behavior: 'smooth', block: 'start' });

        if (workflowErrorSummary instanceof HTMLElement) {
            window.setTimeout(() => workflowErrorSummary.focus(), 160);
        }
    }

    const canvasToBlob = (canvas, type, quality) => new Promise((resolve) => {
        canvas.toBlob((blob) => resolve(blob), type, quality);
    });

    const loadImageFromFile = (file) => new Promise((resolve, reject) => {
        const reader = new FileReader();
        const image = new Image();

        reader.onerror = () => reject(new Error('Unable to read the selected image.'));
        image.onerror = () => reject(new Error('Unable to process the selected image.'));
        image.onload = () => resolve(image);
        reader.onload = () => {
            image.src = reader.result;
        };
        reader.readAsDataURL(file);
    });

    const formatCapturedAt = (value) => {
        if (!value) {
            return '-';
        }

        const parsed = new Date(value);

        if (Number.isNaN(parsed.getTime())) {
            return value;
        }

        return new Intl.DateTimeFormat('en-IN', {
            timeZone: 'Asia/Kolkata',
            day: '2-digit',
            month: 'short',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            hour12: true,
        }).format(parsed);
    };

    const compressImageFile = async (file) => {
        if (!(file instanceof File) || !file.type.startsWith('image/')) {
            return file;
        }

        const image = await loadImageFromFile(file);
        const canvas = document.createElement('canvas');
        const context = canvas.getContext('2d', { alpha: false });
        const dimensions = [proofConfig.maxDimension, 800, 640, 480]
            .filter((dimension, index, items) => dimension > 0 && items.indexOf(dimension) === index);
        const qualities = [0.75, 0.6, 0.45, 0.35];
        let blob = null;

        for (const dimension of dimensions) {
            const scale = Math.min(1, dimension / Math.max(image.width, image.height));
            const width = Math.max(Math.round(image.width * scale), 1);
            const height = Math.max(Math.round(image.height * scale), 1);

            canvas.width = width;
            canvas.height = height;
            context.fillStyle = '#ffffff';
            context.fillRect(0, 0, width, height);
            context.drawImage(image, 0, 0, width, height);

            for (const quality of qualities) {
                blob = await canvasToBlob(canvas, 'image/jpeg', quality);

                if (!blob) {
                    continue;
                }

                if (blob.size <= proofConfig.targetBytes) {
                    break;
                }
            }

            if (blob && blob.size <= proofConfig.maxBytes) {
                break;
            }
        }

        if (!blob) {
            throw new Error('Unable to compress the selected image.');
        }

        if (blob.size > proofConfig.maxBytes) {
            throw new Error('Image is still too large. Try taking a closer photo with less background, or use retake/choose another photo.');
        }

        const baseName = (file.name || 'proof-image').replace(/\.[^/.]+$/, '');

        return new File([blob], `${baseName}.jpg`, {
            type: 'image/jpeg',
            lastModified: Date.now(),
        });
    };

    document.querySelectorAll('[data-compress-images]').forEach((input) => {
        input.addEventListener('change', async () => {
            if (!(input instanceof HTMLInputElement) || !input.files || input.files.length === 0) {
                return;
            }

            const files = Array.from(input.files);
            const dataTransfer = new DataTransfer();

            try {
                for (const file of files) {
                    const compressed = await compressImageFile(file);
                    dataTransfer.items.add(compressed);
                }

                input.files = dataTransfer.files;
                input.setCustomValidity('');
            } catch (error) {
                input.value = '';
                input.setCustomValidity(error.message || 'Unable to compress the selected image.');
                input.reportValidity();
            }
        });
    });

    const updateLocationPreview = (form) => {
        const status = form.querySelector('[data-location-status]');
        const latitudeInput = form.querySelector('[data-location-latitude]');
        const longitudeInput = form.querySelector('[data-location-longitude]');
        const accuracyInput = form.querySelector('[data-location-accuracy]');
        const capturedAtInput = form.querySelector('[data-location-captured-at]');
        const reasonField = form.querySelector('textarea[name="location_missing_reason"]');
        const latPreview = form.querySelector('[data-location-lat-preview]');
        const lngPreview = form.querySelector('[data-location-lng-preview]');
        const accuracyPreview = form.querySelector('[data-location-accuracy-preview]');
        const capturedPreview = form.querySelector('[data-location-captured-preview]');
        const captureButton = form.querySelector('[data-capture-location]');
        const recaptureButton = form.querySelector('[data-recapture-location]');
        const clearButton = form.querySelector('[data-clear-location]');
        const hasCoordinates = latitudeInput?.value && longitudeInput?.value;
        const reason = reasonField?.value?.trim() || '';

        if (latPreview) latPreview.textContent = latitudeInput?.value || '-';
        if (lngPreview) lngPreview.textContent = longitudeInput?.value || '-';
        if (accuracyPreview) accuracyPreview.textContent = accuracyInput?.value || '-';
        if (capturedPreview) capturedPreview.textContent = formatCapturedAt(capturedAtInput?.value || '');

        if (captureButton instanceof HTMLElement) {
            captureButton.hidden = !!hasCoordinates;
        }
        if (recaptureButton instanceof HTMLElement) {
            recaptureButton.hidden = !hasCoordinates;
        }
        if (clearButton instanceof HTMLElement) {
            clearButton.hidden = !hasCoordinates;
        }

        if (!status) {
            return;
        }

        status.classList.remove('is-success', 'is-warning');

        if (hasCoordinates) {
            status.classList.add('is-success');
            status.textContent = `Location captured${capturedAtInput?.value ? ` at ${formatCapturedAt(capturedAtInput.value)}` : ''}.`;
            return;
        }

        if (reason !== '') {
            status.classList.add('is-warning');
            status.textContent = `Location marked as unavailable: ${reason}`;
            return;
        }

        status.textContent = 'Location not captured yet.';
    };

    document.querySelectorAll('[data-workflow-form]').forEach((form) => {
        const captureButton = form.querySelector('[data-capture-location]');
        const recaptureButton = form.querySelector('[data-recapture-location]');
        const clearButton = form.querySelector('[data-clear-location]');
        const latitudeInput = form.querySelector('[data-location-latitude]');
        const longitudeInput = form.querySelector('[data-location-longitude]');
        const accuracyInput = form.querySelector('[data-location-accuracy]');
        const capturedAtInput = form.querySelector('[data-location-captured-at]');
        const reasonField = form.querySelector('textarea[name="location_missing_reason"]');
        const resetCoordinates = () => {
            latitudeInput.value = '';
            longitudeInput.value = '';
            accuracyInput.value = '';
            capturedAtInput.value = '';
        };

        const handleLocationCapture = () => {
            if (!navigator.geolocation) {
                if (reasonField) {
                    reasonField.focus();
                }
                const status = form.querySelector('[data-location-status]');
                if (status) {
                    status.classList.add('is-warning');
                    status.textContent = 'Geolocation is not supported on this device. Add a missing-location reason to continue.';
                }
                return;
            }

            navigator.geolocation.getCurrentPosition((position) => {
                latitudeInput.value = position.coords.latitude.toFixed(6);
                longitudeInput.value = position.coords.longitude.toFixed(6);
                accuracyInput.value = Math.round(position.coords.accuracy * 10) / 10;
                capturedAtInput.value = new Date().toISOString();
                if (reasonField) {
                    reasonField.value = '';
                }
                updateLocationPreview(form);
            }, (error) => {
                const status = form.querySelector('[data-location-status]');
                if (status) {
                    status.classList.add('is-warning');
                    status.textContent = `${error.message || 'Location permission was denied.'} Add a missing-location reason to continue.`;
                }
                if (reasonField) {
                    reasonField.focus();
                }
            }, {
                enableHighAccuracy: true,
                timeout: 10000,
                maximumAge: 0,
            });
        };

        updateLocationPreview(form);

        reasonField?.addEventListener('input', () => {
            if (reasonField.value.trim() !== '') {
                resetCoordinates();
            }

            updateLocationPreview(form);
        });

        captureButton?.addEventListener('click', handleLocationCapture);
        recaptureButton?.addEventListener('click', handleLocationCapture);
        clearButton?.addEventListener('click', () => {
            resetCoordinates();
            updateLocationPreview(form);
            captureButton?.focus();
        });
    });

    document.querySelectorAll('[data-signature-pad]').forEach((canvas) => {
        const form = canvas.closest('form');
        const hiddenInputName = canvas.dataset.targetInput;
        const hiddenInput = form?.querySelector(`input[name="${hiddenInputName}"]`);
        const clearButton = form?.querySelector('[data-signature-clear]');
        const context = canvas.getContext('2d');
        let drawing = false;
        let hasSignature = false;

        const resizeCanvas = () => {
            const ratio = window.devicePixelRatio || 1;
            const bounds = canvas.getBoundingClientRect();
            canvas.width = Math.max(Math.floor(bounds.width * ratio), 300);
            canvas.height = Math.max(Math.floor(bounds.height * ratio), 160);
            context.setTransform(1, 0, 0, 1, 0, 0);
            context.scale(ratio, ratio);
            context.lineWidth = 2;
            context.lineCap = 'round';
            context.lineJoin = 'round';
            context.strokeStyle = '#0f172a';
        };

        const positionForEvent = (event) => {
            const bounds = canvas.getBoundingClientRect();
            const point = event.touches ? event.touches[0] : event;

            return {
                x: point.clientX - bounds.left,
                y: point.clientY - bounds.top,
            };
        };

        const startStroke = (event) => {
            drawing = true;
            const point = positionForEvent(event);
            context.beginPath();
            context.moveTo(point.x, point.y);
            event.preventDefault();
        };

        const continueStroke = (event) => {
            if (!drawing) {
                return;
            }

            const point = positionForEvent(event);
            context.lineTo(point.x, point.y);
            context.stroke();
            hasSignature = true;
            event.preventDefault();
        };

        const stopStroke = () => {
            drawing = false;
        };

        resizeCanvas();
        window.addEventListener('resize', resizeCanvas);

        canvas.addEventListener('mousedown', startStroke);
        canvas.addEventListener('mousemove', continueStroke);
        canvas.addEventListener('mouseup', stopStroke);
        canvas.addEventListener('mouseleave', stopStroke);
        canvas.addEventListener('touchstart', startStroke, { passive: false });
        canvas.addEventListener('touchmove', continueStroke, { passive: false });
        canvas.addEventListener('touchend', stopStroke);

        clearButton?.addEventListener('click', () => {
            context.clearRect(0, 0, canvas.width, canvas.height);
            hasSignature = false;
            if (hiddenInput) {
                hiddenInput.value = '';
            }
        });

        form?.addEventListener('submit', () => {
            if (!hiddenInput) {
                return;
            }

            if (hasSignature) {
                hiddenInput.value = canvas.toDataURL('image/png');
            }
        });
    });
});
</script>
@endpush
@endsection
