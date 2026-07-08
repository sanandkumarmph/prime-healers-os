@extends('layouts.app')

@php
    $currentUser = auth()->user();
    $canUpdateAssets = $currentUser?->canAccessModule('assets', 'update') ?? false;
    $canDeleteAssets = $currentUser?->canAccessModule('assets', 'delete') ?? false;
    $canViewAssetStockHistory = $currentUser?->hasAnyPermission(['stock_history.view', 'stock_history.asset']) ?? false;
    $rupee = html_entity_decode('&#8377;');
    $statusBadge = fn ($status) => match($status) {
        'available' => ['#ecfdf5', '#166534'],
        'awaiting_verification' => ['#fef3c7', '#b45309'],
        'rented' => ['#eff6ff', '#1d4ed8'],
        'maintenance' => ['#fff7ed', '#c2410c'],
        'reserved' => ['#f5f3ff', '#6d28d9'],
        'retired' => ['#f8fafc', '#475569'],
        'available_for_sale' => ['#fff7ed', '#9a3412'],
        'reserved_for_sale' => ['#fef3c7', '#b45309'],
        'sold' => ['#f3f4f6', '#4b5563'],
        'converted_to_rental' => ['#ecfeff', '#0f766e'],
        default => ['#f8fafc', '#334155'],
    };
    $badge = $statusBadge($asset->asset_status);
    $isNewStock = $asset->asset_stage === 'new_stock';
    $unitLabel = $isNewStock ? 'Sale Unit' : 'Rental Asset';
    $workflowControl = $workflowControl ?? ['locked' => false, 'message' => null, 'action_label' => null, 'action_url' => null, 'convert_url' => null, 'active_rental' => null, 'active_sale' => null];
    $activeRental = $workflowControl['active_rental']
        ?? $asset->activeRentalAssignments->sortByDesc('assigned_at')->first()?->rental
        ?? $asset->rentalAssignments->sortByDesc('assigned_at')->first()?->rental;
    $activeSale = $workflowControl['active_sale'] ?? $asset->sales->sortByDesc(fn ($sale) => $sale->sale_date ?? $sale->created_at)->first();
    $activeCustomer = $activeRental?->customer ?? $activeSale?->customer;
    $conversionHint = str_contains(strtolower((string) $asset->notes), 'converted from sale inventory')
        ? 'Converted from sale units'
        : null;
    $productName = optional($asset->product)->name ?: 'No linked product';
    $productIdentitySecondary = trim(collect([optional($asset->product)->brand, optional($asset->product)->model_name])->filter()->implode(' '));
    if ($productIdentitySecondary === '') {
        $productIdentitySecondary = trim((string) (optional($asset->product)->product_code ?: optional($asset->product)->sku ?: ''));
    }
    if ($productIdentitySecondary === '') {
        $productIdentitySecondary = 'No model assigned';
    }
    $conditionLabel = fn (?string $condition) => match((string) $condition) {
        'new' => 'New',
        'good' => 'Good',
        'fair' => 'Fair',
        'needs_repair', 'repair' => 'Needs Repair',
        'damaged' => 'Damaged',
        'retired', 'inactive' => 'Retired',
        default => 'Not recorded',
    };
    $conditionBadge = fn (?string $condition) => match((string) $condition) {
        'new', 'good' => ['#ecfdf5', '#047857'],
        'fair' => ['#fffbeb', '#b45309'],
        'needs_repair', 'repair', 'damaged' => ['#fef2f2', '#b91c1c'],
        'retired', 'inactive' => ['#f8fafc', '#475569'],
        default => ['#f8fafc', '#475569'],
    };
    $conditionTone = $conditionBadge($asset->condition_status);
    $latestMovement = $asset->movements->first();
    $rentalCount = $asset->rentalAssignments->pluck('rental_id')->filter()->unique()->count();
    $assetAge = $asset->purchase_date ? $asset->purchase_date->diffForHumans(['parts' => 1, 'short' => true]) : null;
    $lastServiceAgo = $asset->last_service_date ? $asset->last_service_date->diffForHumans(['parts' => 1, 'short' => true]) : null;
    $lastMovementAgo = optional($latestMovement?->created_at ?? $asset->updated_at)->diffForHumans();
    $isOverdueReturn = $activeRental?->end_date
        && optional($activeRental->end_date)->lt(now()->startOfDay())
        && ! in_array((string) $activeRental->status, ['returned', 'cancelled'], true);
    $isServiceDue = $asset->next_service_date && optional($asset->next_service_date)->lte(now()->endOfDay());
    $isRepairDelayed = $asset->asset_status === 'maintenance' && optional($asset->updated_at)->lt(now()->subDays(30));
    $isNoMovement90 = optional($asset->updated_at)->lt(now()->subDays(90));
    $attentionFlags = collect([
        $isOverdueReturn ? 'Overdue Rental' : null,
        $asset->asset_status === \App\Models\Asset::STATUS_AWAITING_VERIFICATION ? 'Verification Pending' : null,
        $isRepairDelayed ? 'Repair Delayed' : null,
        $isServiceDue ? 'Service Due' : null,
        $isNoMovement90 ? 'No Movement > 90 Days' : null,
    ])->filter();
    $custodyType = 'Warehouse';
    $custodyMain = optional($asset->warehouse)->name ?: 'Not assigned';
    $custodySub = 'Available custody';
    $custodyUrl = null;
    if ($activeRental) {
        $custodyType = 'Customer';
        $custodyMain = $activeCustomer?->name ?: 'Customer linked';
        $custodySub = 'Rental #' . $activeRental->id;
        $custodyUrl = route('rentals.show', $activeRental);
    } elseif ($activeSale) {
        $custodyType = 'Customer';
        $custodyMain = $activeCustomer?->name ?: 'Customer linked';
        $custodySub = 'Sale #' . $activeSale->id;
        $custodyUrl = route('sales.show', $activeSale);
    } elseif ($asset->asset_status === \App\Models\Asset::STATUS_MAINTENANCE) {
        $custodyType = 'Repair Vendor';
        $custodyMain = 'Service Desk';
        $custodySub = 'Under repair';
    } elseif ($asset->asset_status === \App\Models\Asset::STATUS_AWAITING_VERIFICATION) {
        $custodyType = 'Awaiting Verification';
        $custodyMain = optional($asset->warehouse)->name ?: 'Return queue';
        $custodySub = 'Post-return check';
    }
@endphp

@section('content')
    <style>
        .asset-detail-page {
            display: grid;
            gap: 18px;
            min-width: 0;
            width: 100%;
            max-width: 100%;
            overflow-x: clip;
            padding-bottom: calc(136px + env(safe-area-inset-bottom, 0px));
        }
        .asset-detail-page > * { min-width: 0; max-width: 100%; }
        .asset-detail-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 22px;
            padding: 22px;
            min-width: 0;
            max-width: 100%;
        }
        .asset-detail-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            flex-wrap: wrap;
            min-width: 0;
        }
        .asset-detail-header > div { min-width: 0; max-width: 100%; }
        .asset-detail-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            min-width: 0;
            max-width: 100%;
        }
        .asset-detail-actions a,
        .asset-detail-actions button {
            min-width: 0;
            max-width: 100%;
            white-space: normal;
            text-align: center;
            line-height: 1.35;
        }
        .asset-detail-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
            min-width: 0;
        }
        .asset-detail-info-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            margin-top: 18px;
            min-width: 0;
        }
        .asset-detail-info-item {
            padding: 14px 16px;
            border-radius: 16px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            min-width: 0;
        }
        .asset-command-layout {
            display:grid;
            grid-template-columns:minmax(280px, 35%) minmax(0, 1fr);
            gap:16px;
            align-items:start;
        }
        .asset-command-panel {
            position:sticky;
            top:88px;
            display:grid;
            gap:12px;
            padding:16px;
            border-radius:18px;
            border:1px solid #dbe4f0;
            background:#ffffff;
            box-shadow:0 18px 44px rgba(15, 23, 42, .08);
        }
        .asset-command-title {
            margin:0;
            color:#0f172a;
            font-size:24px;
            line-height:1.08;
            letter-spacing:-.02em;
            font-family:var(--ph-font-heading);
        }
        .asset-command-subtitle {
            margin:4px 0 0;
            color:#64748b;
            font-size:13px;
            line-height:1.35;
        }
        .asset-mini-badge {
            display:inline-flex;
            width:max-content;
            align-items:center;
            padding:5px 9px;
            border-radius:999px;
            font-size:11px;
            font-weight:900;
            text-transform:uppercase;
            letter-spacing:.04em;
        }
        .asset-custody-box {
            display:grid;
            gap:4px;
            padding:11px 12px;
            border-radius:14px;
            border:1px solid #bfdbfe;
            background:#eff6ff;
        }
        .asset-custody-label,
        .asset-section-label {
            color:#64748b;
            font-size:10px;
            font-weight:900;
            letter-spacing:.08em;
            text-transform:uppercase;
            font-family:var(--ph-font-heading);
        }
        .asset-custody-main {
            color:#0f172a;
            font-size:17px;
            font-weight:900;
            line-height:1.2;
            text-decoration:none;
        }
        .asset-custody-sub {
            color:#475569;
            font-size:13px;
            font-weight:700;
        }
        .asset-health-strip {
            display:grid;
            grid-template-columns:repeat(2, minmax(0, 1fr));
            gap:8px;
        }
        .asset-health-item {
            padding:9px 10px;
            border-radius:12px;
            border:1px solid #e2e8f0;
            background:#f8fafc;
        }
        .asset-health-value {
            margin-top:3px;
            color:#0f172a;
            font-size:14px;
            font-weight:900;
            line-height:1.2;
        }
        .asset-primary-action {
            display:flex;
            align-items:center;
            justify-content:center;
            min-height:42px;
            padding:10px 13px;
            border-radius:13px;
            background:#4f46e5;
            color:#ffffff;
            text-decoration:none;
            font-weight:900;
            box-shadow:0 14px 30px rgba(79, 70, 229, .24);
        }
        .asset-secondary-actions {
            display:grid;
            grid-template-columns:repeat(2, minmax(0, 1fr));
            gap:8px;
        }
        .asset-soft-action,
        .asset-more-actions summary {
            display:flex;
            align-items:center;
            justify-content:center;
            min-height:38px;
            padding:9px 11px;
            border-radius:12px;
            border:1px solid #cbd5e1;
            background:#ffffff;
            color:#0f172a;
            text-decoration:none;
            font-size:13px;
            font-weight:850;
            cursor:pointer;
        }
        .asset-more-actions { position:relative; }
        .asset-more-actions summary { list-style:none; }
        .asset-more-actions summary::-webkit-details-marker { display:none; }
        .asset-more-menu {
            position:absolute;
            right:0;
            top:calc(100% + 6px);
            z-index:20;
            min-width:190px;
            padding:8px;
            border:1px solid #dbe4f0;
            border-radius:14px;
            background:#ffffff;
            box-shadow:0 18px 40px rgba(15, 23, 42, .16);
        }
        .asset-more-menu a,
        .asset-more-menu button {
            display:block;
            width:100%;
            padding:9px 10px;
            border:0;
            border-radius:10px;
            background:transparent;
            color:#0f172a;
            text-align:left;
            text-decoration:none;
            font-weight:800;
            cursor:pointer;
        }
        .asset-more-menu a:hover,
        .asset-more-menu button:hover { background:#f8fafc; }
        .asset-content-stack {
            display:grid;
            gap:12px;
            min-width:0;
        }
        .asset-compact-section {
            padding:14px;
            border-radius:18px;
            border:1px solid #dbe4f0;
            background:#ffffff;
        }
        .asset-section-head {
            display:flex;
            justify-content:space-between;
            align-items:flex-start;
            gap:10px;
            margin-bottom:10px;
        }
        .asset-section-head h2 {
            margin:0;
            color:#0f172a;
            font-size:18px;
            font-family:var(--ph-font-heading);
        }
        .asset-compact-grid {
            display:grid;
            grid-template-columns:repeat(3, minmax(0, 1fr));
            gap:8px;
        }
        .asset-compact-tile {
            padding:9px 10px;
            border-radius:12px;
            border:1px solid #e2e8f0;
            background:#f8fafc;
            min-width:0;
        }
        .asset-compact-value {
            margin-top:3px;
            color:#0f172a;
            font-size:14px;
            font-weight:900;
            overflow:hidden;
            text-overflow:ellipsis;
            white-space:nowrap;
        }
        .asset-attention-panel {
            display:flex;
            flex-wrap:wrap;
            gap:7px;
            padding:10px;
            border-radius:14px;
            border:1px solid #fed7aa;
            background:#fff7ed;
        }
        .asset-attention-chip {
            display:inline-flex;
            padding:5px 8px;
            border-radius:999px;
            background:#fee2e2;
            color:#b91c1c;
            font-size:11px;
            font-weight:900;
            text-transform:uppercase;
        }
        .asset-timeline {
            display:grid;
            gap:8px;
        }
        .asset-timeline-item {
            display:grid;
            grid-template-columns:88px minmax(0, 1fr);
            gap:10px;
            padding:9px 0;
            border-top:1px solid #edf2f7;
        }
        .asset-timeline-item:first-child { border-top:0; padding-top:0; }
        .asset-timeline-date {
            color:#64748b;
            font-size:12px;
            font-weight:850;
        }
        .asset-timeline-title {
            color:#0f172a;
            font-size:14px;
            font-weight:900;
            text-transform:capitalize;
        }
        .asset-timeline-meta {
            margin-top:2px;
            color:#64748b;
            font-size:12px;
            line-height:1.35;
        }
        .asset-compact-table {
            width:100%;
            border-collapse:collapse;
        }
        .asset-compact-table th,
        .asset-compact-table td {
            padding:9px 8px;
            border-top:1px solid #edf2f7;
            text-align:left;
            font-size:13px;
        }
        .asset-compact-table th {
            color:#64748b;
            font-size:10px;
            font-weight:900;
            letter-spacing:.08em;
            text-transform:uppercase;
        }
        .asset-copy-row {
            display: flex;
            align-items: center;
            gap: 8px;
            min-width: 0;
            margin-top: 6px;
        }
        .asset-copy-value {
            min-width: 0;
            flex: 1 1 auto;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-size: 16px;
            font-weight: 700;
            color: #0f172a;
        }
        .asset-copy-btn {
            flex: 0 0 auto;
            min-height: 30px;
            padding: 6px 10px;
            border-radius: 999px;
            border: 1px solid #cbd5e1;
            background: #ffffff;
            color: #334155;
            font-size: 11px;
            font-weight: 800;
            cursor: pointer;
        }
        .asset-detail-wrap-safe,
        .asset-detail-card table,
        .asset-detail-card tbody,
        .asset-detail-card tr,
        .asset-detail-card td,
        .asset-detail-card th {
            min-width: 0;
            max-width: 100%;
        }
        @media (max-width: 767px) {
            .asset-detail-page {
                gap: 12px;
                padding-bottom: calc(188px + env(safe-area-inset-bottom, 0px));
            }
            .asset-command-layout {
                grid-template-columns:1fr;
            }
            .asset-command-panel {
                position:static;
                padding:14px;
            }
            .asset-compact-grid {
                grid-template-columns:1fr;
            }
            .asset-health-strip {
                grid-template-columns:repeat(2, minmax(0, 1fr));
            }
            .asset-timeline-item {
                grid-template-columns:1fr;
                gap:3px;
            }
            .asset-detail-card {
                padding: 16px;
                border-radius: 18px;
                overflow: hidden;
            }
            .asset-detail-header h1 {
                font-size: 24px !important;
                line-height: 1.15 !important;
            }
            .asset-detail-actions {
                width: 100%;
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 8px;
            }
            .asset-detail-actions a,
            .asset-detail-actions button {
                width: 100%;
                min-height: 40px !important;
                padding: 8px 12px !important;
                font-size: 12px !important;
            }
            .asset-detail-grid,
            .asset-detail-info-grid {
                grid-template-columns: 1fr !important;
                gap: 10px !important;
            }
            .asset-detail-info-item {
                padding: 12px 13px;
            }
            .asset-copy-row {
                align-items:flex-start;
                flex-wrap:wrap;
            }
            .asset-copy-value {
                font-size: 13px !important;
                white-space:normal;
                overflow:visible;
                text-overflow:clip;
                overflow-wrap:anywhere;
                word-break:break-word;
            }
            .asset-detail-card div,
            .asset-detail-card span,
            .asset-detail-card p,
            .asset-detail-card strong,
            .asset-detail-card a,
            .asset-detail-card td,
            .asset-detail-card th {
                overflow-wrap:anywhere;
                word-break:break-word;
            }
            .asset-detail-card table,
            .asset-detail-card thead,
            .asset-detail-card tbody,
            .asset-detail-card tr,
            .asset-detail-card td {
                display:block;
                width:100%;
            }
            .asset-detail-card thead {
                display:none;
            }
            .asset-detail-card tbody {
                display:grid;
                gap:10px;
            }
            .asset-detail-card tr {
                border:1px solid #e2e8f0;
                border-radius:14px;
                background:#f8fafc;
                padding:10px 12px;
            }
            .asset-detail-card td {
                padding:0 !important;
                border:none !important;
            }
            .asset-detail-card td + td {
                margin-top:8px;
            }
            .asset-detail-card td::before {
                content:attr(data-label);
                display:block;
                margin-bottom:4px;
                color:#64748b;
                font-size:10px;
                font-weight:800;
                letter-spacing:.08em;
                text-transform:uppercase;
            }
        }
    </style>

    <div class="asset-detail-page">
        @if(session('success'))
            <div style="padding:12px 14px; border-radius:14px; background:#ecfdf5; border:1px solid #bbf7d0; color:#166534;">{{ session('success') }}</div>
        @endif

        @if(session('error'))
            <div style="padding:12px 14px; border-radius:14px; background:#fff1f2; border:1px solid #fecaca; color:#991b1b;">{{ session('error') }}</div>
        @endif

        @if(!empty($workflowControl['locked']) && !empty($workflowControl['message']))
            <div style="padding:12px 14px; border-radius:14px; background:#fffbeb; border:1px solid #fde68a; color:#92400e;">
                {{ $workflowControl['message'] }}
            </div>
        @endif

        <div class="asset-command-layout">
            <aside class="asset-command-panel">
                <div style="display:flex; justify-content:space-between; gap:10px; align-items:flex-start;">
                    <div style="min-width:0;">
                        <span class="asset-mini-badge" style="background:{{ $isNewStock ? '#fff7ed' : '#eff6ff' }}; color:{{ $isNewStock ? '#9a3412' : '#1d4ed8' }};">{{ $unitLabel }}</span>
                        <h1 class="asset-command-title">{{ $productName }}</h1>
                        <p class="asset-command-subtitle">{{ $productIdentitySecondary }}</p>
                    </div>
                    <a href="{{ route('assets.index') }}" class="asset-soft-action" style="flex:0 0 auto;">Back</a>
                </div>

                <div style="display:flex; gap:7px; flex-wrap:wrap;">
                    <span class="asset-mini-badge" style="background:{{ $badge[0] }}; color:{{ $badge[1] }};">{{ str_replace('_', ' ', $asset->asset_status) }}</span>
                    <span class="asset-mini-badge" style="background:{{ $conditionTone[0] }}; color:{{ $conditionTone[1] }};">{{ $conditionLabel($asset->condition_status) }}</span>
                    @if($asset->isSerialPending())
                        <span class="asset-mini-badge" style="background:#fff7ed; color:#9a3412;">Serial Pending</span>
                    @endif
                </div>

                <div>
                    <div class="asset-section-label">Asset ID</div>
                    <div class="asset-copy-row">
                        <div class="asset-copy-value" title="{{ $asset->serial_number }}">{{ $asset->serial_number ?: '—' }}</div>
                        @if($asset->serial_number)
                            <button type="button" class="asset-copy-btn" data-copy-text="{{ $asset->serial_number }}">Copy</button>
                        @endif
                    </div>
                    @if($asset->barcode_value)
                        <div class="asset-command-subtitle">Barcode: {{ $asset->barcode_value }}</div>
                    @endif
                </div>

                <div class="asset-custody-box">
                    <div class="asset-custody-label">{{ $custodyType }}</div>
                    @if($custodyUrl)
                        <a href="{{ $custodyUrl }}" class="asset-custody-main">{{ $custodyMain }}</a>
                    @else
                        <div class="asset-custody-main">{{ $custodyMain }}</div>
                    @endif
                    <div class="asset-custody-sub">{{ $custodySub }}</div>
                </div>

                @if($attentionFlags->isNotEmpty())
                    <div class="asset-attention-panel" aria-label="Asset attention flags">
                        @foreach($attentionFlags as $flag)
                            <span class="asset-attention-chip">{{ $flag }}</span>
                        @endforeach
                    </div>
                @endif

                <div class="asset-health-strip">
                    <div class="asset-health-item">
                        <div class="asset-section-label">Age</div>
                        <div class="asset-health-value">{{ $assetAge ?: '—' }}</div>
                    </div>
                    <div class="asset-health-item">
                        <div class="asset-section-label">Rentals</div>
                        <div class="asset-health-value">{{ $rentalCount }}</div>
                    </div>
                    <div class="asset-health-item">
                        <div class="asset-section-label">Last Service</div>
                        <div class="asset-health-value">{{ $lastServiceAgo ?: '—' }}</div>
                    </div>
                    <div class="asset-health-item">
                        <div class="asset-section-label">Last Movement</div>
                        <div class="asset-health-value">{{ $lastMovementAgo ?: '—' }}</div>
                    </div>
                </div>

                @if($canUpdateAssets)
                    @if(!empty($workflowControl['action_label']) && !empty($workflowControl['action_url']))
                        <a href="{{ $workflowControl['action_url'] }}" class="asset-primary-action">{{ $workflowControl['action_label'] }}</a>
                    @elseif($asset->asset_status === \App\Models\Asset::STATUS_RENTED && $activeRental)
                        <a href="{{ route('rentals.show', $activeRental) }}" class="asset-primary-action">View Rental</a>
                    @elseif(!empty($workflowControl['convert_url']))
                        <a href="{{ $workflowControl['convert_url'] }}" class="asset-primary-action">Convert Stock</a>
                    @else
                        <a href="{{ route('assets.edit', $asset) }}" class="asset-primary-action">Edit Asset</a>
                    @endif
                @elseif($activeRental)
                    <a href="{{ route('rentals.show', $activeRental) }}" class="asset-primary-action">View Rental</a>
                @endif

                <div class="asset-secondary-actions">
                    @if($canUpdateAssets)
                        <a href="{{ route('assets.transfer', $asset) }}" class="asset-soft-action">Move Asset</a>
                        <a href="{{ route('assets.edit', $asset) }}" class="asset-soft-action">Edit</a>
                    @endif
                    <details class="asset-more-actions">
                        <summary>More</summary>
                        <div class="asset-more-menu">
                            <a href="{{ route('assets.index') }}">Asset Register</a>
                            @if($canViewAssetStockHistory)
                                <a href="{{ route('stock-history.index', ['asset_id' => $asset->id]) }}">Stock History</a>
                            @endif
                            @if($activeRental)
                                <a href="{{ route('rentals.show', $activeRental) }}">Current Rental</a>
                            @endif
                            @if($activeSale)
                                <a href="{{ route('sales.show', $activeSale) }}">Current Sale</a>
                            @endif
                            @if($canDeleteAssets)
                                <form method="POST" action="{{ route('assets.destroy', $asset) }}" style="margin:0;" onsubmit="return confirm('Delete this asset? This will be blocked if dependencies exist.');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" style="color:#be123c;">Delete Asset</button>
                                </form>
                            @endif
                        </div>
                    </details>
                </div>
            </aside>

            <main class="asset-content-stack">
                <section class="asset-compact-section">
                    <div class="asset-section-head">
                        <div>
                            <div class="asset-section-label">Priority</div>
                            <h2>Current Assignment</h2>
                        </div>
                    </div>
                    @if($activeRental || $activeSale || $asset->asset_status === \App\Models\Asset::STATUS_AWAITING_VERIFICATION)
                        <div class="asset-compact-grid">
                            @if($activeRental)
                                <div class="asset-compact-tile">
                                    <div class="asset-section-label">Rental</div>
                                    <div class="asset-compact-value"><a href="{{ route('rentals.show', $activeRental) }}" style="color:#1d4ed8; text-decoration:none;">Rental #{{ $activeRental->id }}</a></div>
                                </div>
                                <div class="asset-compact-tile">
                                    <div class="asset-section-label">Customer</div>
                                    <div class="asset-compact-value">{{ $activeCustomer?->name ?: '—' }}</div>
                                </div>
                                <div class="asset-compact-tile">
                                    <div class="asset-section-label">Expected Return</div>
                                    <div class="asset-compact-value">{{ optional($activeRental->end_date)->format('d M Y') ?: '—' }}</div>
                                </div>
                            @endif
                            @if($activeSale)
                                <div class="asset-compact-tile">
                                    <div class="asset-section-label">Sale</div>
                                    <div class="asset-compact-value"><a href="{{ route('sales.show', $activeSale) }}" style="color:#1d4ed8; text-decoration:none;">Sale #{{ $activeSale->id }}</a></div>
                                </div>
                                <div class="asset-compact-tile">
                                    <div class="asset-section-label">Customer</div>
                                    <div class="asset-compact-value">{{ $activeCustomer?->name ?: '—' }}</div>
                                </div>
                                <div class="asset-compact-tile">
                                    <div class="asset-section-label">Sale Date</div>
                                    <div class="asset-compact-value">{{ optional($activeSale->sale_date ?? $activeSale->created_at)->format('d M Y') ?: '—' }}</div>
                                </div>
                            @endif
                            <div class="asset-compact-tile">
                                <div class="asset-section-label">Location</div>
                                <div class="asset-compact-value">{{ optional($asset->warehouse)->name ?: ($activeCustomer?->city ?: '—') }}</div>
                            </div>
                            <div class="asset-compact-tile">
                                <div class="asset-section-label">Status</div>
                                <div class="asset-compact-value">{{ str_replace('_', ' ', $asset->asset_status) }}</div>
                            </div>
                            @if($asset->asset_status === \App\Models\Asset::STATUS_AWAITING_VERIFICATION)
                                <div class="asset-compact-tile">
                                    <div class="asset-section-label">Return Check</div>
                                    <div class="asset-compact-value"><a href="{{ route('assets.verify-return', $asset) }}" style="color:#b45309; text-decoration:none;">Verify Return</a></div>
                                </div>
                            @endif
                        </div>
                    @else
                        <div class="asset-compact-tile">
                            <div class="asset-section-label">Current State</div>
                            <div class="asset-compact-value">No active rental, sale, or verification link.</div>
                        </div>
                    @endif
                </section>

                <section class="asset-compact-section">
                    <div class="asset-section-head">
                        <div>
                            <div class="asset-section-label">Trace</div>
                            <h2>Recent Movement</h2>
                        </div>
                    </div>
                    <div class="asset-timeline">
                        @forelse($asset->movements->take(6) as $movement)
                            <div class="asset-timeline-item">
                                <div class="asset-timeline-date">{{ $movement->created_at->diffForHumans() }}</div>
                                <div>
                                    <div class="asset-timeline-title">{{ str_replace('_', ' ', $movement->movement_type) }}</div>
                                    <div class="asset-timeline-meta">
                                        {{ optional($movement->fromWarehouse)->name ?: '—' }} → {{ optional($movement->toWarehouse)->name ?: '—' }}
                                        @if($movement->movedBy)
                                            · {{ $movement->movedBy->name }}
                                        @endif
                                    </div>
                                    @if($movement->remarks)
                                        <div class="asset-timeline-meta">{{ $movement->remarks }}</div>
                                    @endif
                                </div>
                            </div>
                        @empty
                            <div class="asset-timeline-meta">No movement history available yet.</div>
                        @endforelse
                    </div>
                </section>

                <section class="asset-compact-section">
                    <div class="asset-section-head">
                        <div>
                            <div class="asset-section-label">Service</div>
                            <h2>Service History</h2>
                        </div>
                    </div>
                    <table class="asset-compact-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Service Type</th>
                                <th>Technician</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>{{ optional($asset->last_service_date)->format('d M Y') ?: '—' }}</td>
                                <td>Last service</td>
                                <td>—</td>
                                <td>{{ $asset->last_service_date ? 'Recorded' : 'Not recorded' }}</td>
                            </tr>
                            <tr>
                                <td>{{ optional($asset->next_service_date)->format('d M Y') ?: '—' }}</td>
                                <td>Next service</td>
                                <td>—</td>
                                <td>{{ $isServiceDue ? 'Due' : 'Scheduled' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </section>

                <section class="asset-compact-section">
                    <div class="asset-section-head">
                        <div>
                            <div class="asset-section-label">Audit</div>
                            <h2>Asset Details</h2>
                        </div>
                    </div>
                    <div class="asset-compact-grid">
                        <div class="asset-compact-tile">
                            <div class="asset-section-label">Batch</div>
                            <div class="asset-compact-value">{{ $asset->batch_number ?: '—' }}</div>
                        </div>
                        <div class="asset-compact-tile">
                            <div class="asset-section-label">Purchase Date</div>
                            <div class="asset-compact-value">{{ optional($asset->purchase_date)->format('d M Y') ?: '—' }}</div>
                        </div>
                        <div class="asset-compact-tile">
                            <div class="asset-section-label">Purchase Cost</div>
                            <div class="asset-compact-value">{{ $asset->purchase_cost !== null ? $rupee . ' ' . number_format($asset->purchase_cost, 2) : '—' }}</div>
                        </div>
                        <div class="asset-compact-tile" style="grid-column:1 / -1;">
                            <div class="asset-section-label">Notes</div>
                            <div style="margin-top:4px; color:#475569; font-size:13px; line-height:1.45;">{{ $asset->notes ?: 'No notes added.' }}</div>
                        </div>
                    </div>
                </section>
            </main>
        </div>
    </div>
@endsection

@push('scripts')
<script>
(() => {
    const copyButtons = document.querySelectorAll('[data-copy-text]');
    if (!copyButtons.length) {
        return;
    }

    copyButtons.forEach((button) => {
        button.addEventListener('click', async () => {
            const originalLabel = button.textContent;
            try {
                if (navigator.clipboard?.writeText) {
                    await navigator.clipboard.writeText(button.dataset.copyText || '');
                } else {
                    const helper = document.createElement('textarea');
                    helper.value = button.dataset.copyText || '';
                    helper.setAttribute('readonly', 'readonly');
                    helper.style.position = 'absolute';
                    helper.style.left = '-9999px';
                    document.body.appendChild(helper);
                    helper.select();
                    document.execCommand('copy');
                    helper.remove();
                }
                button.textContent = 'Copied';
                window.setTimeout(() => {
                    button.textContent = originalLabel;
                }, 1400);
            } catch (_error) {
                button.textContent = 'Failed';
                window.setTimeout(() => {
                    button.textContent = originalLabel;
                }, 1400);
            }
        });
    });
})();
</script>
@endpush
