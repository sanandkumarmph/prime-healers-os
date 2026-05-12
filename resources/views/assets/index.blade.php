@extends('layouts.app')

@php
    $currentUser = auth()->user();
    $canCreateAssets = $currentUser?->canAccessModule('assets', 'create') ?? false;
    $canUpdateAssets = $currentUser?->canAccessModule('assets', 'update') ?? false;
    $canDeleteAssets = $currentUser?->canAccessModule('assets', 'delete') ?? false;
    $statusBadge = fn ($status) => match($status) {
        'available' => 'is-success',
        'awaiting_verification' => 'is-warning',
        'rented' => 'is-info',
        'maintenance' => 'is-warning',
        'reserved' => 'is-accent',
        'retired' => 'is-neutral',
        'available_for_sale' => 'is-warning',
        'reserved_for_sale' => 'is-warning',
        'sold' => 'is-neutral',
        'converted_to_rental' => 'is-success',
        default => 'is-neutral',
    };
    $assetStageFilter = request('asset_stage');
    $assetStatusFilter = request('asset_status');
    $assetSearchFilter = request('search');
    $assetWarehouseFilter = request('warehouse_id');
    $assetConditionFilter = request('condition_status');
    $allUnitsActive = blank($assetStageFilter) && blank($assetStatusFilter) && blank($assetSearchFilter) && blank($assetWarehouseFilter) && blank($assetConditionFilter);
    $saleUnitsActive = $assetStageFilter === 'new_stock' && blank($assetStatusFilter) && blank($assetSearchFilter) && blank($assetWarehouseFilter) && blank($assetConditionFilter);
    $rentalAssetsActive = $assetStageFilter === 'rental_stock' && blank($assetStatusFilter) && blank($assetSearchFilter) && blank($assetWarehouseFilter) && blank($assetConditionFilter);
    $awaitingVerificationActive = request()->routeIs('assets.pending-verification') || ($assetStatusFilter === 'awaiting_verification' && blank($assetSearchFilter) && blank($assetWarehouseFilter) && blank($assetConditionFilter));
    $underRepairActive = $assetStatusFilter === 'maintenance' && blank($assetSearchFilter) && blank($assetWarehouseFilter) && blank($assetConditionFilter);
    $soldUnitsActive = $assetStatusFilter === 'sold' && blank($assetSearchFilter) && blank($assetWarehouseFilter) && blank($assetConditionFilter);
    $retiredAssetsActive = $assetStatusFilter === 'retired' && blank($assetSearchFilter) && blank($assetWarehouseFilter) && blank($assetConditionFilter);
    $compactCode = function (?string $value, int $visible = 14) {
        $value = trim((string) $value);
        if ($value === '') {
            return 'N/A';
        }
        if (mb_strlen($value) <= $visible) {
            return $value;
        }
        return mb_substr($value, 0, $visible) . '...';
    };
    $assetProductIdentity = function ($product) {
        if (!$product) {
            return [
                'primary' => 'No linked product',
                'secondary' => 'No model assigned',
            ];
        }

        $brandModel = trim(collect([$product->brand, $product->model_name])->filter()->implode(' '));

        return [
            'primary' => $product->name,
            'secondary' => $brandModel !== '' ? $brandModel : 'No model assigned',
        ];
    };
@endphp

@section('content')
    <style>
        .asset-register-page {
            display: grid;
            gap: 18px;
        }
        .asset-hero {
            margin-bottom: 0 !important;
        }
        .asset-chip-row {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        .asset-chip {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 38px;
            padding: 8px 14px;
            border-radius: 999px;
            border: 1px solid var(--ph-color-border);
            background: #ffffff;
            color: var(--ph-color-text);
            text-decoration: none;
            font-size: 12px;
            font-weight: 800;
        }
        .asset-chip.is-active {
            background: var(--ph-color-primary);
            border-color: var(--ph-color-primary);
            color: #ffffff;
        }
        .asset-register-summary {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
        }
        .asset-register-summary-card {
            display: block;
            padding: 18px;
            border-radius: 20px;
            border: 1px solid var(--ph-color-border);
            background: #ffffff;
            text-decoration: none;
            color: inherit;
            box-shadow: var(--ph-shadow-soft);
            transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
        }
        .asset-register-summary-card:hover {
            transform: translateY(-1px);
            box-shadow: var(--ph-shadow-card);
        }
        .asset-register-summary-card.is-warning {
            background: var(--ph-color-warning-soft);
            border-color: rgba(183,121,31,.18);
        }
        .asset-register-summary-card.is-info {
            background: var(--ph-color-info-soft);
            border-color: rgba(23,119,189,.18);
        }
        .asset-register-summary-card.is-alert {
            background: #fff7ed;
            border-color: rgba(183,121,31,.22);
        }
        .asset-summary-label {
            font-size: 11px;
            color: var(--ph-color-text-soft);
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-family: var(--ph-font-heading);
        }
        .asset-summary-value {
            margin-top: 8px;
            font-size: 28px;
            font-weight: 800;
            color: var(--ph-color-text);
            font-family: var(--ph-font-heading);
        }
        .asset-register-summary-card.is-warning .asset-summary-label,
        .asset-register-summary-card.is-warning .asset-summary-value,
        .asset-register-summary-card.is-alert .asset-summary-label,
        .asset-register-summary-card.is-alert .asset-summary-value { color: var(--ph-color-warning); }
        .asset-register-summary-card.is-info .asset-summary-label,
        .asset-register-summary-card.is-info .asset-summary-value { color: var(--ph-color-primary); }
        .asset-card {
            background: #fff;
            border: 1px solid var(--ph-color-border);
            border-radius: 22px;
            box-shadow: var(--ph-shadow-soft);
        }
        .asset-register-shell {
            background: #ffffff;
            border: 1px solid var(--ph-color-border);
            border-radius: 22px;
            overflow: hidden;
            box-shadow: var(--ph-shadow-soft);
        }
        .asset-register-shell-header {
            padding: 20px 22px;
            border-bottom: 1px solid var(--ph-color-border);
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: flex-start;
            flex-wrap: wrap;
        }
        .asset-register-shell-header h2 {
            margin: 0;
            font-size: 22px;
            color: var(--ph-color-text);
            font-family: var(--ph-font-heading);
        }
        .asset-register-shell-header p {
            margin: 8px 0 0;
            color: var(--ph-color-text-soft);
        }
        .asset-filter-summary {
            cursor: pointer;
            list-style: none;
            padding: 16px 20px;
            font-weight: 800;
            color: var(--ph-color-text);
            font-family: var(--ph-font-heading);
        }
        .asset-filter-summary span {
            color: var(--ph-color-text-soft);
            font-size: 12px;
            font-weight: 700;
        }
        .asset-filter-form {
            padding: 0 20px 20px;
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr 1fr auto;
            gap: 12px;
            align-items: end;
        }
        .asset-field label {
            display: block;
            margin-bottom: 8px;
            color: var(--ph-color-text-soft);
            font-size: 13px;
            font-weight: 700;
        }
        .asset-field input,
        .asset-field select {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid var(--ph-color-border-strong);
            border-radius: 14px;
            color: var(--ph-color-text);
            background: #fff;
        }
        .asset-filter-actions {
            display: flex;
            gap: 10px;
        }
        .asset-badge {
            display: inline-flex;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            border: 1px solid transparent;
        }
        .asset-badge.is-warning { background: var(--ph-color-warning-soft); color: var(--ph-color-warning); border-color: rgba(183,121,31,.18); }
        .asset-badge.is-info { background: var(--ph-color-info-soft); color: var(--ph-color-primary); border-color: rgba(23,119,189,.18); }
        .asset-badge.is-success { background: var(--ph-color-success-soft); color: var(--ph-color-success); border-color: rgba(14,159,75,.18); }
        .asset-badge.is-neutral { background: var(--ph-color-surface-soft); color: var(--ph-color-text-soft); border-color: var(--ph-color-border); }
        .asset-badge.is-accent { background: #f5f3ff; color: #6d28d9; border-color: rgba(109,40,217,.16); }
        .asset-table-shell {
            width: 100%;
            overflow: auto;
        }
        .asset-table {
            width: 100%;
            min-width: 1200px;
            border-collapse: collapse;
        }
        .asset-table-head { background: var(--ph-color-surface-soft); }
        .asset-table-head th {
            text-align: left;
            padding: 14px 18px;
            font-size: 12px;
            color: var(--ph-color-text-soft);
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-family: var(--ph-font-heading);
        }
        .asset-row { border-top: 1px solid var(--ph-color-border); }
        .asset-cell { padding: 16px 18px; }
        .asset-table-checkbox {
            width: 18px;
            height: 18px;
            accent-color: var(--ph-color-primary);
            cursor: pointer;
        }
        .asset-action-menu {
            position: relative;
            display: inline-block;
        }
        .asset-action-menu summary {
            list-style: none;
            width: 38px;
            height: 38px;
            border-radius: 12px;
            border: 1px solid var(--ph-color-border);
            background: #fff;
            color: var(--ph-color-text);
            display: grid;
            place-items: center;
            cursor: pointer;
            font-weight: 900;
        }
        .asset-action-menu summary::-webkit-details-marker {
            display: none;
        }
        .asset-action-menu[open] summary {
            background: var(--ph-color-info-soft);
            color: var(--ph-color-primary);
            border-color: rgba(23,119,189,.18);
        }
        .asset-action-panel {
            position: absolute;
            right: 48px;
            top: 0;
            z-index: 40;
            min-width: 180px;
            display: grid;
            gap: 6px;
            padding: 8px;
            border: 1px solid var(--ph-color-border);
            border-radius: 14px;
            background: #fff;
            box-shadow: var(--ph-shadow-float);
        }
        .asset-action-link,
        .asset-action-panel button {
            display: flex;
            align-items: center;
            justify-content: flex-start;
            min-height: 36px;
            padding: 8px 10px;
            border-radius: 10px;
            border: 1px solid var(--ph-color-border);
            background: #fff;
            color: var(--ph-color-text);
            text-decoration: none;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
        }
        .asset-action-panel .danger {
            background: var(--ph-color-danger-soft);
            border-color: rgba(179,13,35,.18);
            color: var(--ph-color-danger);
        }
        .asset-copy-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            min-width: 0;
            max-width: 100%;
        }
        .asset-copy-pill-value {
            min-width: 0;
            max-width: 100%;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-weight: 700;
            color: var(--ph-color-text);
        }
        .asset-copy-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 28px;
            padding: 5px 8px;
            border: 1px solid var(--ph-color-border-strong);
            border-radius: 999px;
            background: #fff;
            color: var(--ph-color-text);
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            flex: 0 0 auto;
        }
        .asset-mobile-list {
            display: none;
        }
        .asset-pagination-shell {
            padding: 18px 22px 22px;
            border-top: 1px solid var(--ph-color-border);
            background: var(--ph-color-surface-soft);
        }
        .asset-mobile-card {
            padding: 16px;
            border-top: 1px solid var(--ph-color-border);
            display: grid;
            gap: 14px;
        }
        .asset-mobile-meta {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }
        .asset-mobile-actions {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 8px;
        }
        .asset-mobile-actions a,
        .asset-mobile-actions button,
        .asset-mobile-actions summary {
            min-height: 38px;
            border-radius: 12px;
            font-size: 12px;
        }
        .asset-product-title,
        .asset-product-subtitle {
            max-width: 100%;
            word-break: normal;
            overflow-wrap: anywhere;
        }
        .asset-product-title {
            font-size: 15px;
            color: var(--ph-color-text);
            font-family: var(--ph-font-heading);
        }
        .asset-product-subtitle {
            font-size: 13px;
            color: var(--ph-color-text-soft);
        }
        .asset-meta-card {
            padding: 12px;
            border-radius: 14px;
            background: var(--ph-color-surface-soft);
            border: 1px solid var(--ph-color-border);
        }
        .asset-meta-label {
            font-size: 10px;
            color: var(--ph-color-text-soft);
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .06em;
            font-family: var(--ph-font-heading);
        }
        .asset-meta-value {
            margin-top: 6px;
            font-size: 13px;
            font-weight: 700;
            color: var(--ph-color-text);
        }
        .asset-meta-subtle {
            margin-top: 4px;
            font-size: 12px;
            color: var(--ph-color-text-soft);
        }
        .asset-link-soft {
            color: var(--ph-color-primary);
            text-decoration: none;
        }
        .asset-quickscan-head {
            display:flex;
            justify-content:space-between;
            align-items:flex-start;
            gap:16px;
            margin-bottom:14px;
            flex-wrap:wrap;
        }
        .asset-quickscan-head h2 {
            margin:0;
            font-size:22px;
            color: var(--ph-color-text);
            font-family: var(--ph-font-heading);
        }
        .asset-quickscan-head p {
            margin:8px 0 0;
            color: var(--ph-color-text-soft);
        }
        .asset-quickscan-form {
            display:flex;
            gap:12px;
            flex-wrap:wrap;
        }
        .asset-quickscan-input {
            flex:1;
            min-width:280px;
            padding:14px 16px;
            border:1px solid var(--ph-color-border-strong);
            border-radius:14px;
            background:#ffffff;
            font-size:15px;
            color: var(--ph-color-text);
        }
        .asset-mobile-empty,
        .asset-empty {
            color: var(--ph-color-text-soft);
        }
        @media (max-width: 991px) {
            .asset-register-summary {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .asset-filter-form {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
        @media (max-width: 767px) {
            .asset-register-page {
                gap: 12px;
            }
            .asset-register-summary {
                gap: 10px;
            }
            .asset-register-summary-card {
                padding: 14px;
                border-radius: 16px;
            }
            .asset-register-shell {
                border-radius: 18px;
            }
            .asset-register-shell-header {
                padding: 16px;
            }
            .asset-register-shell-header h2 {
                font-size: 18px !important;
            }
            .asset-table-shell {
                display: none;
            }
            .asset-mobile-list {
                display: block;
            }
            .asset-action-panel {
                position: static;
                min-width: 0;
                margin-top: 8px;
                box-shadow: none;
            }
            .asset-mobile-actions {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .asset-filter-form {
                grid-template-columns: 1fr;
            }
            .asset-quickscan-form {
                display: grid;
            }
        }
    </style>

    <div class="asset-register-page">
        <div class="rx-page-header asset-hero">
            <div>
                <div class="rx-eyebrow" style="background:var(--ph-color-info-soft); color:var(--ph-color-primary);">Asset Register</div>
                <h1 class="rx-page-title">Asset Register</h1>
                <p class="rx-page-subtitle">Track sale units, rental assets, serial numbers, barcodes, condition, and workflow status.</p>
            </div>

            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                @if($canCreateAssets)
                    <a href="{{ route('assets.create', ['asset_stage' => 'new_stock']) }}" class="rx-btn-soft">Add Sale Unit</a>
                    <a href="{{ route('assets.create', ['asset_stage' => 'rental_stock']) }}" class="rx-btn-soft">Add Rental Asset</a>
                @endif
                <a href="{{ route('assets.pending-verification') }}" class="rx-btn-primary">Return Verification</a>
            </div>
        </div>

        @if(session('success'))
            <div style="padding:14px 16px; border-radius:16px; background:var(--ph-color-success-soft); border:1px solid rgba(14,159,75,.18); color:var(--ph-color-success);">{{ session('success') }}</div>
        @endif

        @if(session('error'))
            <div style="padding:14px 16px; border-radius:16px; background:var(--ph-color-danger-soft); border:1px solid rgba(179,13,35,.18); color:var(--ph-color-danger);">{{ session('error') }}</div>
        @endif

        @if(($duplicateProductNameGroups ?? collect())->isNotEmpty())
            <div style="padding:14px 16px; border-radius:16px; background:var(--ph-color-warning-soft); border:1px solid rgba(183,121,31,.18); color:var(--ph-color-warning);">
                <strong>{{ $duplicateProductNameGroups->count() }} Product Master name {{ $duplicateProductNameGroups->count() === 1 ? 'group has' : 'groups have' }} multiple variants.</strong>
                Asset Register now shows only the linked Product Master identity. Review duplicate names in Product Master when brand/model variants share the same name.
            </div>
        @endif

        @if(($mixedAssetProductGroups ?? collect())->isNotEmpty())
            <div style="padding:14px 16px; border-radius:16px; background:var(--ph-color-danger-soft); border:1px solid rgba(179,13,35,.18); color:var(--ph-color-danger);">
                <strong>{{ $mixedAssetProductGroups->count() }} asset name {{ $mixedAssetProductGroups->count() === 1 ? 'group is' : 'groups are' }} already linked across multiple Product Master records.</strong>
                Review those assets before relying on name-only matching.
            </div>
        @endif

        <div class="asset-chip-row" aria-label="Asset register views">
            <a href="{{ route('assets.index') }}" class="asset-chip{{ $allUnitsActive ? ' is-active' : '' }}">All Units</a>
            <a href="{{ route('assets.index', ['asset_stage' => 'new_stock']) }}" class="asset-chip{{ $saleUnitsActive ? ' is-active' : '' }}">Sale Units</a>
            <a href="{{ route('assets.index', ['asset_stage' => 'rental_stock']) }}" class="asset-chip{{ $rentalAssetsActive ? ' is-active' : '' }}">Rental Assets</a>
            <a href="{{ route('assets.pending-verification') }}" class="asset-chip{{ $awaitingVerificationActive ? ' is-active' : '' }}">Awaiting Verification</a>
            <a href="{{ route('assets.index', ['asset_status' => 'maintenance']) }}" class="asset-chip{{ $underRepairActive ? ' is-active' : '' }}">Under Repair</a>
            <a href="{{ route('assets.index', ['asset_status' => 'sold']) }}" class="asset-chip{{ $soldUnitsActive ? ' is-active' : '' }}">Sold Units</a>
            <a href="{{ route('assets.index', ['asset_status' => 'retired']) }}" class="asset-chip{{ $retiredAssetsActive ? ' is-active' : '' }}">Retired Assets</a>
        </div>

        <div class="asset-register-summary">
            <a href="{{ route('assets.index') }}" class="asset-register-summary-card">
                <div class="asset-summary-label">All Units</div>
                <div class="asset-summary-value">{{ $summary['total_assets'] }}</div>
            </a>
            <a href="{{ route('assets.index', ['asset_stage' => 'new_stock']) }}" class="asset-register-summary-card is-warning">
                <div class="asset-summary-label">Sale Units</div>
                <div class="asset-summary-value">{{ $summary['sale_stock'] }}</div>
            </a>
            <a href="{{ route('assets.index', ['asset_stage' => 'rental_stock']) }}" class="asset-register-summary-card is-info">
                <div class="asset-summary-label">Rental Assets</div>
                <div class="asset-summary-value">{{ $summary['rental_stock_assets'] }}</div>
            </a>
            <a href="{{ route('assets.pending-verification') }}" class="asset-register-summary-card is-alert">
                <div class="asset-summary-label">Awaiting Verification</div>
                <div class="asset-summary-value">{{ $summary['awaiting_verification_assets'] }}</div>
            </a>
        </div>

        <div class="asset-card">
            <div class="rx-card-body" style="padding:20px 22px;">
                <div class="asset-quickscan-head">
                    <div>
                        <h2>Quick Scan / Jump to Asset</h2>
                        <p>Scan or type a serial or barcode to open the exact equipment unit fast.</p>
                    </div>
                    <div style="display:flex; gap:8px; flex-wrap:wrap;">
                        <span class="asset-badge is-warning">Sale Unit</span>
                        <span class="asset-badge is-info">Rental Asset</span>
                    </div>
                </div>

                <form method="GET" action="{{ route('assets.scan-lookup') }}" id="assetLookupForm" class="asset-quickscan-form">
                    <input type="text" name="lookup" id="assetLookupInput" placeholder="Scan barcode / enter serial number" autocomplete="off" spellcheck="false"
                           class="asset-quickscan-input">
                    <input type="file" id="assetLookupCameraInput" accept="image/*" capture="environment" style="display:none;">
                    <button type="button" id="assetLookupCameraButton" class="ph-btn-secondary">
                        Open Camera
                    </button>
                    <button type="submit" class="ph-btn">
                        Open Asset
                    </button>
                </form>
            </div>
        </div>

        <details class="asset-card">
            <summary class="asset-filter-summary">Filters &amp; Sorting <span>{{ request()->hasAny(['search', 'asset_stage', 'warehouse_id', 'asset_status', 'condition_status']) ? 'Active' : 'Expand' }}</span></summary>
            <form method="GET" action="{{ route('assets.index') }}" class="asset-filter-form">
                <div class="asset-field">
                    <label>Search</label>
                    <input type="text" name="search" value="{{ request('search') }}" placeholder="Serial number / barcode / product">
                </div>
                <div class="asset-field">
                    <label>Unit Type</label>
                    <select name="asset_stage">
                        <option value="">All units</option>
                        <option value="new_stock" @selected(request('asset_stage') === 'new_stock')>Sale Unit</option>
                        <option value="rental_stock" @selected(request('asset_stage') === 'rental_stock')>Rental Asset</option>
                    </select>
                </div>
                <div class="asset-field">
                    <label>Warehouse</label>
                    <select name="warehouse_id">
                        <option value="">All warehouses</option>
                        @foreach($warehouses as $warehouse)
                            <option value="{{ $warehouse->id }}" @selected(request('warehouse_id') == $warehouse->id)>{{ $warehouse->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="asset-field">
                    <label>Status</label>
                    <select name="asset_status">
                        <option value="">All statuses</option>
                        @foreach($assetStatuses as $status)
                            <option value="{{ $status }}" @selected(request('asset_status') === $status)>{{ ucwords(str_replace('_', ' ', $status)) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="asset-field">
                    <label>Condition</label>
                    <select name="condition_status">
                        <option value="">All conditions</option>
                        @foreach($conditionStatuses as $status)
                            <option value="{{ $status }}" @selected(request('condition_status') === $status)>{{ ucfirst($status) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="asset-filter-actions">
                    <button type="submit" class="ph-btn">Filter</button>
                    <a href="{{ route('assets.index') }}" class="ph-btn-secondary">Reset</a>
                </div>
            </form>
        </details>

        <div class="asset-register-shell">
            <div class="asset-register-shell-header">
                <div>
                    <h2>Equipment Fleet Workspace</h2>
                    <p>Every physical unit with stage, serial, barcode, warehouse, and workflow status in one operational register.</p>
                </div>
                <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                    <a href="{{ route('assets.export.csv', request()->query()) }}" class="ph-btn-secondary">Export CSV</a>
                    <span class="asset-badge is-neutral" style="padding:8px 12px;">
                        Physical units only
                    </span>
                </div>
            </div>

            <div class="asset-table-shell">
                <table class="asset-table">
                    <thead class="asset-table-head">
                        <tr>
                            <th style="text-align:center; padding:14px 12px;">
                                <input type="checkbox" id="assetSelectAll" class="asset-table-checkbox" aria-label="Select all visible assets">
                            </th>
                            <th style="text-align:left; padding:14px 12px;">Sl No.</th>
                            <th>Asset / Product</th>
                            <th>Type</th>
                            <th>Serial</th>
                            <th>Barcode</th>
                            <th>Warehouse</th>
                            <th>Current Link</th>
                            <th>Status</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($assets as $asset)
                        @php
                            $badge = $statusBadge($asset->asset_status);
                            $rowNumber = method_exists($assets, 'firstItem') && $assets->firstItem()
                                ? $assets->firstItem() + $loop->index
                                : $loop->iteration;
                            $activeAssignment = $asset->activeRentalAssignments->sortByDesc('assigned_at')->first();
                            $latestAssignment = $asset->rentalAssignments->sortByDesc('assigned_at')->first();
                            $activeRental = $activeAssignment?->rental ?? $latestAssignment?->rental;
                            $activeCustomer = $activeRental?->customer;
                            $latestSale = $asset->sales->sortByDesc(fn ($sale) => $sale->sale_date ?? $sale->created_at)->first();
                        @endphp
                        <tr class="asset-row">
                            <td class="asset-cell" style="padding:16px 12px; text-align:center;">
                                <input type="checkbox" class="asset-table-checkbox asset-row-checkbox" value="{{ $asset->id }}" aria-label="Select asset {{ $asset->serial_number ?: $asset->id }}">
                            </td>
                            <td class="asset-cell" style="padding:16px 12px; color:var(--ph-color-text-soft); font-weight:700;">{{ $rowNumber }}</td>
                            <td class="asset-cell">
                                <div style="display:grid; gap:6px;">
                                    @php
                                        $productIdentity = $assetProductIdentity($asset->product);
                                    @endphp
                                    <a href="{{ route('assets.show', $asset) }}" class="rn-record-link asset-product-title" style="font-size:15px;">{{ $productIdentity['primary'] }}</a>
                                    @if($asset->product && \Illuminate\Support\Facades\Route::has('products.show'))
                                        <a href="{{ route('products.show', $asset->product) }}" class="rn-record-link-subtle asset-product-subtitle" style="font-size:13px;">{{ $productIdentity['secondary'] }}</a>
                                    @else
                                        <div class="asset-product-subtitle">{{ $productIdentity['secondary'] }}</div>
                                    @endif
                                    @if($asset->variant_identity_warning)
                                        <span class="asset-badge is-warning" style="width:max-content; padding:5px 9px; font-size:10px; font-weight:800;">Check linked variant</span>
                                    @endif
                                </div>
                            </td>
                            <td class="asset-cell">
                                @if($asset->asset_stage === 'new_stock')
                                    <span class="asset-badge is-warning">Sale Unit</span>
                                @else
                                    <span class="asset-badge is-info">Rental Asset</span>
                                @endif
                            </td>
                            <td class="asset-cell">
                                <div style="display:grid; gap:6px;">
                                    <div class="asset-copy-pill">
                                        <span class="asset-copy-pill-value" title="{{ $asset->serial_number }}">{{ $compactCode($asset->serial_number) }}</span>
                                        <button type="button" class="asset-copy-btn" data-copy-text="{{ $asset->serial_number }}">Copy</button>
                                    </div>
                                    @if($asset->isSerialPending())
                                        <span class="asset-badge is-warning" style="width:max-content; padding:5px 9px; font-size:10px; font-weight:800;">Serial Pending</span>
                                    @endif
                                </div>
                            </td>
                            <td class="asset-cell">
                                @if($asset->barcode_value)
                                    <div class="asset-copy-pill">
                                        <span class="asset-copy-pill-value" title="{{ $asset->barcode_value }}">{{ $compactCode($asset->barcode_value) }}</span>
                                        <button type="button" class="asset-copy-btn" data-copy-text="{{ $asset->barcode_value }}">Copy</button>
                                    </div>
                                @else
                                    <span style="color:var(--ph-color-text-soft);">N/A</span>
                                @endif
                            </td>
                            <td class="asset-cell">{{ optional($asset->warehouse)->name ?: 'N/A' }}</td>
                            <td class="asset-cell">
                                @if($activeRental && \Illuminate\Support\Facades\Route::has('rentals.show'))
                                    <div style="display:grid; gap:4px;">
                                        <a href="{{ route('rentals.show', $activeRental) }}" class="rn-record-link-subtle">Rental #{{ $activeRental->id }}</a>
                                        <span class="asset-meta-subtle" style="margin-top:0;">{{ $activeCustomer?->name ?: 'Customer linked' }}</span>
                                    </div>
                                @elseif($latestSale && \Illuminate\Support\Facades\Route::has('sales.show'))
                                    <div style="display:grid; gap:4px;">
                                        <a href="{{ route('sales.show', $latestSale) }}" class="rn-record-link-subtle">Sale #{{ $latestSale->id }}</a>
                                        <span class="asset-meta-subtle" style="margin-top:0;">{{ $latestSale->customer?->name ?: 'Customer linked' }}</span>
                                    </div>
                                @else
                                    <div style="display:grid; gap:4px;">
                                        <span style="color:var(--ph-color-text-soft);">Standalone unit</span>
                                        @if($asset->variant_identity_warning)
                                            <span style="font-size:12px; color:var(--ph-color-warning);">{{ $asset->variant_identity_warning['message'] }}</span>
                                        @endif
                                    </div>
                                @endif
                            </td>
                            <td class="asset-cell">
                                <span class="asset-badge {{ $badge }}">
                                    {{ str_replace('_', ' ', $asset->asset_status) }}
                                </span>
                            </td>
                            <td class="asset-cell" style="text-align:right;">
                                <div style="display:inline-flex; gap:10px; flex-wrap:wrap; justify-content:flex-end;">
                                    <a href="{{ route('assets.show', $asset) }}" class="ph-btn">View</a>
                                    <details class="asset-action-menu">
                                        <summary aria-label="More actions for {{ $asset->serial_number }}">...</summary>
                                        <div class="asset-action-panel">
                                            @if($canUpdateAssets)
                                                @if($asset->asset_status === 'awaiting_verification')
                                                    <a href="{{ route('assets.verify-return', $asset) }}" class="asset-action-link">Verify Return</a>
                                                @endif
                                                <a href="{{ route('assets.edit', $asset) }}" class="asset-action-link">Edit</a>
                                                <a href="{{ route('assets.transfer', $asset) }}" class="asset-action-link">Transfer</a>
                                            @endif
                                            @if($canDeleteAssets)
                                                <form method="POST" action="{{ route('assets.destroy', $asset) }}" style="margin:0;" onsubmit="return confirm('Delete this asset? This will be blocked if dependencies exist.');">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="danger">Delete</button>
                                                </form>
                                            @endif
                                        </div>
                                    </details>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="asset-empty" style="padding:24px 18px;">No units match this view right now. Try a broader filter or register a new sale unit or rental asset.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            <div class="asset-mobile-list">
                @forelse($assets as $asset)
                    @php
                        $badge = $statusBadge($asset->asset_status);
                        $activeAssignment = $asset->activeRentalAssignments->sortByDesc('assigned_at')->first();
                        $latestAssignment = $asset->rentalAssignments->sortByDesc('assigned_at')->first();
                        $activeRental = $activeAssignment?->rental ?? $latestAssignment?->rental;
                        $activeCustomer = $activeRental?->customer;
                        $latestSale = $asset->sales->sortByDesc(fn ($sale) => $sale->sale_date ?? $sale->created_at)->first();
                    @endphp
                    <div class="asset-mobile-card">
                        <div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:flex-start;">
                            @php
                                $productIdentity = $assetProductIdentity($asset->product);
                            @endphp
                            <div style="display:grid; gap:6px; min-width:0;">
                                <a href="{{ route('assets.show', $asset) }}" class="rn-record-link asset-product-title" style="font-size:15px;">{{ $productIdentity['primary'] }}</a>
                                <div class="asset-product-subtitle">{{ $productIdentity['secondary'] }}</div>
                                @if($asset->variant_identity_warning)
                                    <span class="asset-badge is-warning" style="width:max-content; padding:5px 9px; font-size:10px; font-weight:800;">Check linked variant</span>
                                @endif
                                @if($asset->isSerialPending())
                                    <span class="asset-badge is-warning" style="width:max-content; padding:5px 9px; font-size:10px; font-weight:800;">Serial Pending</span>
                                @endif
                            </div>
                            <div style="display:grid; gap:6px; justify-items:end;">
                                @if($asset->asset_stage === 'new_stock')
                                    <span class="asset-badge is-warning">Sale Unit</span>
                                @else
                                    <span class="asset-badge is-info">Rental Asset</span>
                                @endif
                                <span class="asset-badge {{ $badge }}">
                                    {{ str_replace('_', ' ', $asset->asset_status) }}
                                </span>
                            </div>
                        </div>

                        <div class="asset-mobile-meta">
                            <div class="asset-meta-card">
                                <div class="asset-meta-label">Serial</div>
                                <div class="asset-copy-pill" style="margin-top:6px;">
                                    <span class="asset-copy-pill-value" title="{{ $asset->serial_number }}">{{ $compactCode($asset->serial_number) }}</span>
                                    <button type="button" class="asset-copy-btn" data-copy-text="{{ $asset->serial_number }}">Copy</button>
                                </div>
                                @if($asset->isSerialPending())
                                    <div style="margin-top:6px;"><span class="asset-badge is-warning" style="width:max-content; padding:5px 9px; font-size:10px; font-weight:800;">Serial Pending</span></div>
                                @endif
                            </div>
                            <div class="asset-meta-card">
                                <div class="asset-meta-label">Barcode</div>
                                @if($asset->barcode_value)
                                    <div class="asset-copy-pill" style="margin-top:6px;">
                                        <span class="asset-copy-pill-value" title="{{ $asset->barcode_value }}">{{ $compactCode($asset->barcode_value) }}</span>
                                        <button type="button" class="asset-copy-btn" data-copy-text="{{ $asset->barcode_value }}">Copy</button>
                                    </div>
                                @else
                                    <div class="asset-meta-value">N/A</div>
                                @endif
                            </div>
                            <div class="asset-meta-card">
                                <div class="asset-meta-label">Warehouse</div>
                                <div class="asset-meta-value">{{ optional($asset->warehouse)->name ?: 'N/A' }}</div>
                            </div>
                            <div class="asset-meta-card">
                                <div class="asset-meta-label">Current Link</div>
                                <div class="asset-meta-value">
                                    @if($activeRental && \Illuminate\Support\Facades\Route::has('rentals.show'))
                                        <a href="{{ route('rentals.show', $activeRental) }}" class="asset-link-soft">Rental #{{ $activeRental->id }}</a>
                                    @elseif($latestSale && \Illuminate\Support\Facades\Route::has('sales.show'))
                                        <a href="{{ route('sales.show', $latestSale) }}" class="asset-link-soft">Sale #{{ $latestSale->id }}</a>
                                    @else
                                        Standalone
                                    @endif
                                </div>
                                @if($activeCustomer)
                                    <div class="asset-meta-subtle">{{ $activeCustomer->name }}</div>
                                @elseif($latestSale?->customer)
                                    <div class="asset-meta-subtle">{{ $latestSale->customer->name }}</div>
                                @elseif($asset->variant_identity_warning)
                                    <div style="margin-top:4px; font-size:12px; color:var(--ph-color-warning);">{{ $asset->variant_identity_warning['message'] }}</div>
                                @endif
                            </div>
                        </div>

                        <div class="asset-mobile-actions">
                            <a href="{{ route('assets.show', $asset) }}" class="ph-btn-secondary">View</a>
                            @if($canUpdateAssets)
                                <a href="{{ route('assets.edit', $asset) }}" class="ph-btn-secondary">Edit</a>
                                @if($asset->asset_status === 'awaiting_verification')
                                    <a href="{{ route('assets.verify-return', $asset) }}" class="ph-btn-soft" style="background:var(--ph-color-warning-soft); color:var(--ph-color-warning); border-color:rgba(183,121,31,.18);">Verify</a>
                                @else
                                    <a href="{{ route('assets.transfer', $asset) }}" class="ph-btn-secondary">Transfer</a>
                                @endif
                                <details class="asset-action-menu">
                                    <summary aria-label="More actions for {{ $asset->serial_number }}">...</summary>
                                    <div class="asset-action-panel">
                                        <a href="{{ route('assets.transfer', $asset) }}" class="asset-action-link">Transfer</a>
                                        @if($asset->asset_status === 'awaiting_verification')
                                            <a href="{{ route('assets.verify-return', $asset) }}" class="asset-action-link">Verify Return</a>
                                        @endif
                                        @if($canDeleteAssets)
                                            <form method="POST" action="{{ route('assets.destroy', $asset) }}" style="margin:0;" onsubmit="return confirm('Delete this asset? This will be blocked if dependencies exist.');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="danger">Delete</button>
                                            </form>
                                        @endif
                                    </div>
                                </details>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="asset-mobile-empty" style="padding:18px 16px;">No units match this view right now. Try a broader filter or register a new unit.</div>
                @endforelse
            </div>

            <div class="asset-pagination-shell">
                {{ $assets->links('vendor.pagination.prime-healers', [
                    'summaryLabel' => 'assets',
                    'ariaLabel' => 'Asset Register pagination',
                ]) }}
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const lookupInput = document.getElementById('assetLookupInput');
    const lookupForm = document.getElementById('assetLookupForm');
    const lookupCameraInput = document.getElementById('assetLookupCameraInput');
    const lookupCameraButton = document.getElementById('assetLookupCameraButton');
    const assetSelectAll = document.getElementById('assetSelectAll');
    const assetRowCheckboxes = Array.from(document.querySelectorAll('.asset-row-checkbox'));
    const copyButtons = Array.from(document.querySelectorAll('.asset-copy-btn'));

    if (lookupInput && lookupForm) {
        lookupInput.focus();

        lookupInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                lookupForm.submit();
            }
        });
    }

    async function decodeBarcodeFromFile(file) {
        if (!('BarcodeDetector' in window)) {
            alert('Camera capture is available, but barcode decoding is not supported on this browser. Please use Chrome on mobile or type the barcode manually.');
            return;
        }

        try {
            const detector = new BarcodeDetector({
                formats: ['code_128', 'code_39', 'codabar', 'ean_13', 'ean_8', 'upc_a', 'upc_e', 'qr_code']
            });
            const bitmap = await createImageBitmap(file);
            const barcodes = await detector.detect(bitmap);

            if (!barcodes.length) {
                alert('No barcode was detected in that image. Please try again with a clearer image.');
                return;
            }

            if (lookupInput) {
                lookupInput.value = barcodes[0].rawValue || '';
            }
            if (lookupForm) {
                lookupForm.submit();
            }
        } catch (error) {
            alert('Unable to decode barcode from the captured image. Please try again or type it manually.');
        }
    }

    if (lookupCameraButton && lookupCameraInput) {
        lookupCameraButton.addEventListener('click', function () {
            lookupCameraInput.click();
        });

        lookupCameraInput.addEventListener('change', function (event) {
            const file = event.target.files && event.target.files[0];
            if (file) {
                decodeBarcodeFromFile(file);
            }
        });
    }

    if (assetSelectAll && assetRowCheckboxes.length) {
        assetSelectAll.addEventListener('change', function () {
            assetRowCheckboxes.forEach(function (checkbox) {
                checkbox.checked = assetSelectAll.checked;
            });
        });

        assetRowCheckboxes.forEach(function (checkbox) {
            checkbox.addEventListener('change', function () {
                assetSelectAll.checked = assetRowCheckboxes.every(function (rowCheckbox) {
                    return rowCheckbox.checked;
                });
            });
        });
    }

    copyButtons.forEach(function (button) {
        button.addEventListener('click', async function () {
            const value = button.dataset.copyText || '';
            if (!value) {
                return;
            }

            try {
                if (navigator.clipboard?.writeText) {
                    await navigator.clipboard.writeText(value);
                } else {
                    const helper = document.createElement('textarea');
                    helper.value = value;
                    helper.setAttribute('readonly', 'readonly');
                    helper.style.position = 'absolute';
                    helper.style.left = '-9999px';
                    document.body.appendChild(helper);
                    helper.select();
                    document.execCommand('copy');
                    helper.remove();
                }

                const previous = button.textContent;
                button.textContent = 'Copied';
                setTimeout(function () {
                    button.textContent = previous;
                }, 1200);
            } catch (error) {
                console.warn('Copy failed', error);
            }
        });
    });
});
</script>
@endpush
