@extends('layouts.app')

@php
    $currentUser = auth()->user();
    $canCreateAssets = $currentUser?->canAccessModule('assets', 'create') ?? false;
    $canUpdateAssets = $currentUser?->canAccessModule('assets', 'update') ?? false;
    $canDeleteAssets = $currentUser?->canAccessModule('assets', 'delete') ?? false;
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
            border: 1px solid #dbe3ef;
            background: #ffffff;
            color: #334155;
            text-decoration: none;
            font-size: 12px;
            font-weight: 800;
        }
        .asset-chip.is-active {
            background: #0f172a;
            border-color: #0f172a;
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
            border: 1px solid #e2e8f0;
            background: #ffffff;
            text-decoration: none;
            color: inherit;
        }
        .asset-register-shell {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 22px;
            overflow: hidden;
        }
        .asset-register-shell-header {
            padding: 20px 22px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: flex-start;
            flex-wrap: wrap;
        }
        .asset-table-shell {
            width: 100%;
            overflow: auto;
        }
        .asset-table {
            width: 100%;
            min-width: 1200px;
            border-collapse: collapse;
        }
        .asset-table-checkbox {
            width: 18px;
            height: 18px;
            accent-color: #2563eb;
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
            border: 1px solid #dbe3ef;
            background: #fff;
            color: #0f172a;
            display: grid;
            place-items: center;
            cursor: pointer;
            font-weight: 900;
        }
        .asset-action-menu summary::-webkit-details-marker {
            display: none;
        }
        .asset-action-menu[open] summary {
            background: #eff6ff;
            color: #1d4ed8;
            border-color: #bfdbfe;
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
            border: 1px solid #dbe3ef;
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.14);
        }
        .asset-action-link,
        .asset-action-panel button {
            display: flex;
            align-items: center;
            justify-content: flex-start;
            min-height: 36px;
            padding: 8px 10px;
            border-radius: 10px;
            border: 1px solid #edf2f7;
            background: #fff;
            color: #334155;
            text-decoration: none;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
        }
        .asset-action-panel .danger {
            background: #fff1f2;
            border-color: #fecdd3;
            color: #be123c;
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
            color: #0f172a;
        }
        .asset-copy-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 28px;
            padding: 5px 8px;
            border: 1px solid #cbd5e1;
            border-radius: 999px;
            background: #fff;
            color: #334155;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            flex: 0 0 auto;
        }
        .asset-mobile-list {
            display: none;
        }
        .asset-mobile-card {
            padding: 16px;
            border-top: 1px solid #e2e8f0;
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
        @media (max-width: 991px) {
            .asset-register-summary {
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
        }
    </style>

    <div class="asset-register-page">
        <div class="rx-page-header" style="margin-bottom:0;">
            <div>
                <div class="rx-eyebrow" style="background:#eff6ff; color:#1d4ed8;">Asset Register</div>
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
            <div style="padding:14px 16px; border-radius:16px; background:#ecfdf5; border:1px solid #bbf7d0; color:#166534;">{{ session('success') }}</div>
        @endif

        @if(session('error'))
            <div style="padding:14px 16px; border-radius:16px; background:#fff1f2; border:1px solid #fecaca; color:#991b1b;">{{ session('error') }}</div>
        @endif

        @if(($duplicateProductNameGroups ?? collect())->isNotEmpty())
            <div style="padding:14px 16px; border-radius:16px; background:#fff7ed; border:1px solid #fed7aa; color:#9a3412;">
                <strong>{{ $duplicateProductNameGroups->count() }} Product Master name {{ $duplicateProductNameGroups->count() === 1 ? 'group has' : 'groups have' }} multiple variants.</strong>
                Asset Register now shows only the linked Product Master identity. Review duplicate names in Product Master when brand/model variants share the same name.
            </div>
        @endif

        @if(($mixedAssetProductGroups ?? collect())->isNotEmpty())
            <div style="padding:14px 16px; border-radius:16px; background:#fff1f2; border:1px solid #fecaca; color:#991b1b;">
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
                <div style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase; letter-spacing:0.08em;">All Units</div>
                <div style="margin-top:8px; font-size:28px; font-weight:800; color:#0f172a;">{{ $summary['total_assets'] }}</div>
            </a>
            <a href="{{ route('assets.index', ['asset_stage' => 'new_stock']) }}" class="asset-register-summary-card" style="background:#fff7ed; border-color:#fed7aa;">
                <div style="font-size:11px; color:#9a3412; font-weight:700; text-transform:uppercase; letter-spacing:0.08em;">Sale Units</div>
                <div style="margin-top:8px; font-size:28px; font-weight:800; color:#9a3412;">{{ $summary['sale_stock'] }}</div>
            </a>
            <a href="{{ route('assets.index', ['asset_stage' => 'rental_stock']) }}" class="asset-register-summary-card" style="background:#eff6ff; border-color:#bfdbfe;">
                <div style="font-size:11px; color:#1d4ed8; font-weight:700; text-transform:uppercase; letter-spacing:0.08em;">Rental Assets</div>
                <div style="margin-top:8px; font-size:28px; font-weight:800; color:#1d4ed8;">{{ $summary['rental_stock_assets'] }}</div>
            </a>
            <a href="{{ route('assets.pending-verification') }}" class="asset-register-summary-card" style="background:#fef3c7; border-color:#fde68a;">
                <div style="font-size:11px; color:#b45309; font-weight:700; text-transform:uppercase; letter-spacing:0.08em;">Awaiting Verification</div>
                <div style="margin-top:8px; font-size:28px; font-weight:800; color:#b45309;">{{ $summary['awaiting_verification_assets'] }}</div>
            </a>
        </div>

        <div class="rx-card" style="border-radius:22px;">
            <div class="rx-card-body" style="padding:20px 22px;">
                <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; margin-bottom:14px; flex-wrap:wrap;">
                    <div>
                        <h2 style="margin:0; font-size:22px;">Quick Scan / Jump to Asset</h2>
                        <p style="margin:8px 0 0; color:#64748b;">Scan or type a serial or barcode to open the exact physical unit fast.</p>
                    </div>
                    <div style="display:flex; gap:8px; flex-wrap:wrap;">
                        <span style="display:inline-flex; padding:8px 12px; border-radius:999px; background:#fff7ed; color:#9a3412; font-size:12px; font-weight:700;">Sale Unit</span>
                        <span style="display:inline-flex; padding:8px 12px; border-radius:999px; background:#eff6ff; color:#1d4ed8; font-size:12px; font-weight:700;">Rental Asset</span>
                    </div>
                </div>

                <form method="GET" action="{{ route('assets.scan-lookup') }}" id="assetLookupForm" style="display:flex; gap:12px; flex-wrap:wrap;">
                    <input type="text" name="lookup" id="assetLookupInput" placeholder="Scan barcode / enter serial number" autocomplete="off" spellcheck="false"
                           style="flex:1; min-width:280px; padding:14px 16px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff; font-size:15px;">
                    <input type="file" id="assetLookupCameraInput" accept="image/*" capture="environment" style="display:none;">
                    <button type="button" id="assetLookupCameraButton" style="display:inline-flex; align-items:center; justify-content:center; padding:0 18px; min-height:48px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff; color:#0f172a; font-weight:700; cursor:pointer;">
                        Open Camera
                    </button>
                    <button type="submit" style="display:inline-flex; align-items:center; justify-content:center; padding:0 18px; min-height:48px; border:none; border-radius:14px; background:#0f172a; color:#ffffff; font-weight:700; cursor:pointer;">
                        Open Asset
                    </button>
                </form>
            </div>
        </div>

        <details class="rx-card" style="border-radius:22px;">
            <summary style="cursor:pointer; list-style:none; padding:16px 20px; font-weight:800; color:#0f172a;">Filters &amp; Sorting <span style="color:#64748b; font-size:12px; font-weight:700;">{{ request()->hasAny(['search', 'asset_stage', 'warehouse_id', 'asset_status', 'condition_status']) ? 'Active' : 'Expand' }}</span></summary>
            <form method="GET" action="{{ route('assets.index') }}" style="padding:0 20px 20px; display:grid; grid-template-columns:2fr 1fr 1fr 1fr 1fr auto; gap:12px; align-items:end;">
                <div>
                    <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Search</label>
                    <input type="text" name="search" value="{{ request('search') }}" placeholder="Serial number / barcode / product"
                           style="width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px;">
                </div>
                <div>
                    <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Unit Type</label>
                    <select name="asset_stage" style="width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px;">
                        <option value="">All units</option>
                        <option value="new_stock" @selected(request('asset_stage') === 'new_stock')>Sale Unit</option>
                        <option value="rental_stock" @selected(request('asset_stage') === 'rental_stock')>Rental Asset</option>
                    </select>
                </div>
                <div>
                    <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Warehouse</label>
                    <select name="warehouse_id" style="width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px;">
                        <option value="">All warehouses</option>
                        @foreach($warehouses as $warehouse)
                            <option value="{{ $warehouse->id }}" @selected(request('warehouse_id') == $warehouse->id)>{{ $warehouse->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Status</label>
                    <select name="asset_status" style="width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px;">
                        <option value="">All statuses</option>
                        @foreach($assetStatuses as $status)
                            <option value="{{ $status }}" @selected(request('asset_status') === $status)>{{ ucwords(str_replace('_', ' ', $status)) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Condition</label>
                    <select name="condition_status" style="width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px;">
                        <option value="">All conditions</option>
                        @foreach($conditionStatuses as $status)
                            <option value="{{ $status }}" @selected(request('condition_status') === $status)>{{ ucfirst($status) }}</option>
                        @endforeach
                    </select>
                </div>
                <div style="display:flex; gap:10px;">
                    <button type="submit" style="padding:12px 16px; border:none; border-radius:14px; background:#0f172a; color:#ffffff; font-weight:700; cursor:pointer;">Filter</button>
                    <a href="{{ route('assets.index') }}" style="display:inline-flex; align-items:center; justify-content:center; padding:12px 16px; border-radius:14px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a; text-decoration:none; font-weight:600;">Reset</a>
                </div>
            </form>
        </details>

        <div class="asset-register-shell">
            <div class="asset-register-shell-header">
                <div>
                    <h2 style="margin:0; font-size:22px;">Asset Register Workspace</h2>
                    <p style="margin:8px 0 0; color:#64748b;">Every physical unit with stage, serial, barcode, warehouse, and workflow status in one place.</p>
                </div>
                <span style="display:inline-flex; padding:8px 12px; border-radius:999px; background:#f8fafc; color:#334155; font-size:12px; font-weight:700;">
                    Physical units only
                </span>
            </div>

            <div class="asset-table-shell">
                <table class="asset-table">
                    <thead style="background:#f8fafc;">
                        <tr>
                            <th style="text-align:center; padding:14px 12px; font-size:12px; color:#64748b; text-transform:uppercase; letter-spacing:0.08em;">
                                <input type="checkbox" id="assetSelectAll" class="asset-table-checkbox" aria-label="Select all visible assets">
                            </th>
                            <th style="text-align:left; padding:14px 12px; font-size:12px; color:#64748b; text-transform:uppercase; letter-spacing:0.08em;">Sl No.</th>
                            <th style="text-align:left; padding:14px 18px; font-size:12px; color:#64748b; text-transform:uppercase; letter-spacing:0.08em;">Asset / Product</th>
                            <th style="text-align:left; padding:14px 18px; font-size:12px; color:#64748b; text-transform:uppercase; letter-spacing:0.08em;">Type</th>
                            <th style="text-align:left; padding:14px 18px; font-size:12px; color:#64748b; text-transform:uppercase; letter-spacing:0.08em;">Serial</th>
                            <th style="text-align:left; padding:14px 18px; font-size:12px; color:#64748b; text-transform:uppercase; letter-spacing:0.08em;">Barcode</th>
                            <th style="text-align:left; padding:14px 18px; font-size:12px; color:#64748b; text-transform:uppercase; letter-spacing:0.08em;">Warehouse</th>
                            <th style="text-align:left; padding:14px 18px; font-size:12px; color:#64748b; text-transform:uppercase; letter-spacing:0.08em;">Current Link</th>
                            <th style="text-align:left; padding:14px 18px; font-size:12px; color:#64748b; text-transform:uppercase; letter-spacing:0.08em;">Status</th>
                            <th style="text-align:right; padding:14px 18px; font-size:12px; color:#64748b; text-transform:uppercase; letter-spacing:0.08em;">Actions</th>
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
                        <tr style="border-top:1px solid #e2e8f0;">
                            <td style="padding:16px 12px; text-align:center;">
                                <input type="checkbox" class="asset-table-checkbox asset-row-checkbox" value="{{ $asset->id }}" aria-label="Select asset {{ $asset->serial_number ?: $asset->id }}">
                            </td>
                            <td style="padding:16px 12px; color:#64748b; font-weight:700;">{{ $rowNumber }}</td>
                            <td style="padding:16px 18px;">
                                <div style="display:grid; gap:6px;">
                                    @php
                                        $productIdentity = $assetProductIdentity($asset->product);
                                    @endphp
                                    <a href="{{ route('assets.show', $asset) }}" class="rn-record-link asset-product-title" style="font-size:15px;">{{ $productIdentity['primary'] }}</a>
                                    @if($asset->product && \Illuminate\Support\Facades\Route::has('products.show'))
                                        <a href="{{ route('products.show', $asset->product) }}" class="rn-record-link-subtle asset-product-subtitle" style="font-size:13px;">{{ $productIdentity['secondary'] }}</a>
                                    @else
                                        <div class="asset-product-subtitle" style="font-size:13px; color:#64748b;">{{ $productIdentity['secondary'] }}</div>
                                    @endif
                                    @if($asset->variant_identity_warning)
                                        <span style="display:inline-flex; width:max-content; padding:5px 9px; border-radius:999px; background:#fff7ed; color:#9a3412; font-size:10px; font-weight:800; text-transform:uppercase;">
                                            Check linked variant
                                        </span>
                                    @endif
                                </div>
                            </td>
                            <td style="padding:16px 18px;">
                                @if($asset->asset_stage === 'new_stock')
                                    <span style="display:inline-flex; padding:6px 10px; border-radius:999px; background:#fff7ed; color:#9a3412; font-size:11px; font-weight:700; text-transform:uppercase;">Sale Unit</span>
                                @else
                                    <span style="display:inline-flex; padding:6px 10px; border-radius:999px; background:#eff6ff; color:#1d4ed8; font-size:11px; font-weight:700; text-transform:uppercase;">Rental Asset</span>
                                @endif
                            </td>
                            <td style="padding:16px 18px;">
                                <div style="display:grid; gap:6px;">
                                    <div class="asset-copy-pill">
                                        <span class="asset-copy-pill-value" title="{{ $asset->serial_number }}">{{ $compactCode($asset->serial_number) }}</span>
                                        <button type="button" class="asset-copy-btn" data-copy-text="{{ $asset->serial_number }}">Copy</button>
                                    </div>
                                    @if($asset->isSerialPending())
                                        <span style="display:inline-flex; width:max-content; padding:5px 9px; border-radius:999px; background:#fff7ed; color:#9a3412; font-size:10px; font-weight:800; text-transform:uppercase;">Serial Pending</span>
                                    @endif
                                </div>
                            </td>
                            <td style="padding:16px 18px;">
                                @if($asset->barcode_value)
                                    <div class="asset-copy-pill">
                                        <span class="asset-copy-pill-value" title="{{ $asset->barcode_value }}">{{ $compactCode($asset->barcode_value) }}</span>
                                        <button type="button" class="asset-copy-btn" data-copy-text="{{ $asset->barcode_value }}">Copy</button>
                                    </div>
                                @else
                                    <span style="color:#64748b;">N/A</span>
                                @endif
                            </td>
                            <td style="padding:16px 18px;">{{ optional($asset->warehouse)->name ?: 'N/A' }}</td>
                            <td style="padding:16px 18px;">
                                @if($activeRental && \Illuminate\Support\Facades\Route::has('rentals.show'))
                                    <div style="display:grid; gap:4px;">
                                        <a href="{{ route('rentals.show', $activeRental) }}" class="rn-record-link-subtle">Rental #{{ $activeRental->id }}</a>
                                        <span style="font-size:12px; color:#64748b;">{{ $activeCustomer?->name ?: 'Customer linked' }}</span>
                                    </div>
                                @elseif($latestSale && \Illuminate\Support\Facades\Route::has('sales.show'))
                                    <div style="display:grid; gap:4px;">
                                        <a href="{{ route('sales.show', $latestSale) }}" class="rn-record-link-subtle">Sale #{{ $latestSale->id }}</a>
                                        <span style="font-size:12px; color:#64748b;">{{ $latestSale->customer?->name ?: 'Customer linked' }}</span>
                                    </div>
                                @else
                                    <div style="display:grid; gap:4px;">
                                        <span style="color:#64748b;">Standalone unit</span>
                                        @if($asset->variant_identity_warning)
                                            <span style="font-size:12px; color:#9a3412;">{{ $asset->variant_identity_warning['message'] }}</span>
                                        @endif
                                    </div>
                                @endif
                            </td>
                            <td style="padding:16px 18px;">
                                <span style="display:inline-flex; padding:6px 10px; border-radius:999px; background:{{ $badge[0] }}; color:{{ $badge[1] }}; font-size:11px; font-weight:700; text-transform:uppercase;">
                                    {{ str_replace('_', ' ', $asset->asset_status) }}
                                </span>
                            </td>
                            <td style="padding:16px 18px; text-align:right;">
                                <div style="display:inline-flex; gap:10px; flex-wrap:wrap; justify-content:flex-end;">
                                    <a href="{{ route('assets.show', $asset) }}" style="display:inline-flex; align-items:center; justify-content:center; padding:9px 12px; border-radius:12px; background:#0f172a; color:#ffffff; text-decoration:none; font-weight:700; font-size:13px;">View</a>
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
                            <td colspan="10" style="padding:24px 18px; color:#64748b;">No units match this view right now. Try a broader filter or register a new sale unit or rental asset.</td>
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
                                <div class="asset-product-subtitle" style="font-size:13px; color:#64748b;">{{ $productIdentity['secondary'] }}</div>
                                @if($asset->variant_identity_warning)
                                    <span style="display:inline-flex; width:max-content; padding:5px 9px; border-radius:999px; background:#fff7ed; color:#9a3412; font-size:10px; font-weight:800; text-transform:uppercase;">Check linked variant</span>
                                @endif
                                @if($asset->isSerialPending())
                                    <span style="display:inline-flex; width:max-content; padding:5px 9px; border-radius:999px; background:#fff7ed; color:#9a3412; font-size:10px; font-weight:800; text-transform:uppercase;">Serial Pending</span>
                                @endif
                            </div>
                            <div style="display:grid; gap:6px; justify-items:end;">
                                @if($asset->asset_stage === 'new_stock')
                                    <span style="display:inline-flex; padding:6px 10px; border-radius:999px; background:#fff7ed; color:#9a3412; font-size:11px; font-weight:700; text-transform:uppercase;">Sale Unit</span>
                                @else
                                    <span style="display:inline-flex; padding:6px 10px; border-radius:999px; background:#eff6ff; color:#1d4ed8; font-size:11px; font-weight:700; text-transform:uppercase;">Rental Asset</span>
                                @endif
                                <span style="display:inline-flex; padding:6px 10px; border-radius:999px; background:{{ $badge[0] }}; color:{{ $badge[1] }}; font-size:11px; font-weight:700; text-transform:uppercase;">
                                    {{ str_replace('_', ' ', $asset->asset_status) }}
                                </span>
                            </div>
                        </div>

                        <div class="asset-mobile-meta">
                            <div style="padding:12px; border-radius:14px; background:#f8fafc; border:1px solid #e2e8f0;">
                                <div style="font-size:10px; color:#64748b; font-weight:800; text-transform:uppercase; letter-spacing:.06em;">Serial</div>
                                <div class="asset-copy-pill" style="margin-top:6px;">
                                    <span class="asset-copy-pill-value" title="{{ $asset->serial_number }}">{{ $compactCode($asset->serial_number) }}</span>
                                    <button type="button" class="asset-copy-btn" data-copy-text="{{ $asset->serial_number }}">Copy</button>
                                </div>
                                @if($asset->isSerialPending())
                                    <div style="margin-top:6px;"><span style="display:inline-flex; width:max-content; padding:5px 9px; border-radius:999px; background:#fff7ed; color:#9a3412; font-size:10px; font-weight:800; text-transform:uppercase;">Serial Pending</span></div>
                                @endif
                            </div>
                            <div style="padding:12px; border-radius:14px; background:#f8fafc; border:1px solid #e2e8f0;">
                                <div style="font-size:10px; color:#64748b; font-weight:800; text-transform:uppercase; letter-spacing:.06em;">Barcode</div>
                                @if($asset->barcode_value)
                                    <div class="asset-copy-pill" style="margin-top:6px;">
                                        <span class="asset-copy-pill-value" title="{{ $asset->barcode_value }}">{{ $compactCode($asset->barcode_value) }}</span>
                                        <button type="button" class="asset-copy-btn" data-copy-text="{{ $asset->barcode_value }}">Copy</button>
                                    </div>
                                @else
                                    <div style="margin-top:6px; font-size:13px; font-weight:700; color:#0f172a;">N/A</div>
                                @endif
                            </div>
                            <div style="padding:12px; border-radius:14px; background:#f8fafc; border:1px solid #e2e8f0;">
                                <div style="font-size:10px; color:#64748b; font-weight:800; text-transform:uppercase; letter-spacing:.06em;">Warehouse</div>
                                <div style="margin-top:6px; font-size:13px; font-weight:700; color:#0f172a;">{{ optional($asset->warehouse)->name ?: 'N/A' }}</div>
                            </div>
                            <div style="padding:12px; border-radius:14px; background:#f8fafc; border:1px solid #e2e8f0;">
                                <div style="font-size:10px; color:#64748b; font-weight:800; text-transform:uppercase; letter-spacing:.06em;">Current Link</div>
                                <div style="margin-top:6px; font-size:13px; font-weight:700; color:#0f172a;">
                                    @if($activeRental && \Illuminate\Support\Facades\Route::has('rentals.show'))
                                        <a href="{{ route('rentals.show', $activeRental) }}" style="color:#1d4ed8; text-decoration:none;">Rental #{{ $activeRental->id }}</a>
                                    @elseif($latestSale && \Illuminate\Support\Facades\Route::has('sales.show'))
                                        <a href="{{ route('sales.show', $latestSale) }}" style="color:#1d4ed8; text-decoration:none;">Sale #{{ $latestSale->id }}</a>
                                    @else
                                        Standalone
                                    @endif
                                </div>
                                @if($activeCustomer)
                                    <div style="margin-top:4px; font-size:12px; color:#64748b;">{{ $activeCustomer->name }}</div>
                                @elseif($latestSale?->customer)
                                    <div style="margin-top:4px; font-size:12px; color:#64748b;">{{ $latestSale->customer->name }}</div>
                                @elseif($asset->variant_identity_warning)
                                    <div style="margin-top:4px; font-size:12px; color:#9a3412;">{{ $asset->variant_identity_warning['message'] }}</div>
                                @endif
                            </div>
                        </div>

                        <div class="asset-mobile-actions">
                            <a href="{{ route('assets.show', $asset) }}" style="display:inline-flex; align-items:center; justify-content:center; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a; text-decoration:none; font-weight:700;">View</a>
                            @if($canUpdateAssets)
                                <a href="{{ route('assets.edit', $asset) }}" style="display:inline-flex; align-items:center; justify-content:center; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a; text-decoration:none; font-weight:700;">Edit</a>
                                @if($asset->asset_status === 'awaiting_verification')
                                    <a href="{{ route('assets.verify-return', $asset) }}" style="display:inline-flex; align-items:center; justify-content:center; border:none; background:#fef3c7; color:#b45309; text-decoration:none; font-weight:800;">Verify</a>
                                @else
                                    <a href="{{ route('assets.transfer', $asset) }}" style="display:inline-flex; align-items:center; justify-content:center; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a; text-decoration:none; font-weight:700;">Transfer</a>
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
                    <div style="padding:18px 16px; color:#64748b;">No units match this view right now. Try a broader filter or register a new unit.</div>
                @endforelse
            </div>

            <div style="padding:18px 22px;">
                {{ $assets->links() }}
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
