@extends('layouts.app')

@php
    $currentUser = auth()->user();
    $canCreateAssets = $currentUser?->canAccessModule('assets', 'create') ?? false;
    $canUpdateAssets = $currentUser?->canAccessModule('assets', 'update') ?? false;
    $canDeleteAssets = $currentUser?->canAccessModule('assets', 'delete') ?? false;
    $canViewRentals = $currentUser?->canAccessModule('rentals', 'read') ?? false;
    $canViewSales = $currentUser?->canAccessModule('sales', 'read') ?? false;
    $canViewProducts = $currentUser?->canAccessModule('products', 'read') ?? false;
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
    $assetCityFilter = request('city');
    $assetConditionFilter = request('condition_status');
    $assetCustodyFilter = request('custody');
    $assetRiskFilter = request('risk');
    $hasActiveFilters = request()->hasAny(['search', 'asset_stage', 'warehouse_id', 'city', 'asset_status', 'condition_status', 'custody', 'risk']);
    $activeFilterChips = collect([
        filled($assetSearchFilter) ? 'Search: ' . $assetSearchFilter : null,
        filled($assetStageFilter) ? 'Unit Type: ' . ($assetStageFilter === 'new_stock' ? 'Sale Unit' : 'Rental Asset') : null,
        filled($assetWarehouseFilter) ? ($assetWarehouseFilter === '__missing' ? 'Warehouse: Missing' : 'Warehouse selected') : null,
        filled($assetCityFilter) ? 'City: ' . $assetCityFilter : null,
        filled($assetStatusFilter) ? 'Status: ' . ucwords(str_replace('_', ' ', $assetStatusFilter)) : null,
        filled($assetConditionFilter) ? 'Condition: ' . ucfirst($assetConditionFilter) : null,
        filled($assetCustodyFilter) ? 'Custody: ' . ucwords(str_replace('_', ' ', $assetCustodyFilter)) : null,
        filled($assetRiskFilter) ? 'Risk: ' . ucwords(str_replace('_', ' ', $assetRiskFilter)) : null,
    ])->filter()->values();
    $hasContextFilters = filled($assetSearchFilter) || filled($assetWarehouseFilter) || filled($assetCityFilter) || filled($assetConditionFilter) || filled($assetCustodyFilter) || filled($assetRiskFilter);
    $allUnitsActive = blank($assetStageFilter) && blank($assetStatusFilter) && ! $hasContextFilters;
    $saleUnitsActive = $assetStageFilter === 'new_stock' && blank($assetStatusFilter) && ! $hasContextFilters;
    $rentalAssetsActive = $assetStageFilter === 'rental_stock' && blank($assetStatusFilter) && ! $hasContextFilters;
    $awaitingVerificationActive = request()->routeIs('assets.pending-verification') || ($assetStatusFilter === 'awaiting_verification' && ! $hasContextFilters);
    $underRepairActive = $assetStatusFilter === 'maintenance' && ! $hasContextFilters;
    $soldUnitsActive = $assetStatusFilter === 'sold' && ! $hasContextFilters;
    $retiredAssetsActive = $assetStatusFilter === 'retired' && ! $hasContextFilters;
    $compactCode = function (?string $value, int $visible = 14) {
        $value = trim((string) $value);
        if ($value === '') {
            return '-';
        }
        if (mb_strlen($value) <= $visible) {
            return $value;
        }
        return mb_substr($value, 0, $visible) . '...';
    };
    $statusLabel = fn (?string $status) => filled($status) ? ucwords(str_replace('_', ' ', (string) $status)) : '-';
    $conditionLabel = fn (?string $condition) => filled($condition) ? ucwords(str_replace('_', ' ', (string) $condition)) : '-';
    $conditionBadge = fn (?string $condition) => match ((string) $condition) {
        'new', 'good' => 'is-success',
        'fair' => 'is-warning',
        'needs_repair', 'repair', 'damaged' => 'is-danger',
        'retired', 'inactive' => 'is-neutral',
        default => 'is-neutral',
    };
    $rowTone = fn (?string $status) => match ($status) {
        'available', 'available_for_sale', 'converted_to_rental' => 'is-green',
        'rented', 'sold' => 'is-blue',
        'reserved', 'reserved_for_sale', 'awaiting_verification' => 'is-amber',
        'maintenance' => 'is-red',
        'retired' => 'is-grey',
        default => 'is-muted',
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
    $assetProductThumbnail = function ($product) {
        if (!$product) {
            return null;
        }

        $rawPath = collect(['product_image_path', 'image_path', 'photo_path', 'thumbnail_path', 'product_image', 'image', 'photo'])
            ->map(fn ($field) => trim((string) ($product->getAttribute($field) ?? '')))
            ->first(fn ($value) => $value !== '');

        if (!$rawPath) {
            return null;
        }

        if (str_starts_with($rawPath, 'http://') || str_starts_with($rawPath, 'https://') || str_starts_with($rawPath, 'data:')) {
            return $rawPath;
        }

        if (str_starts_with($rawPath, 'storage/')) {
            return asset($rawPath);
        }

        return \Illuminate\Support\Facades\Storage::disk('public')->url($rawPath);
    };
    $assetUtilizationTotal = max(1, (int) ($summary['total_assets'] ?? 0));
    $assetUtilizationPercent = round(((int) ($summary['rented_assets'] ?? 0) / $assetUtilizationTotal) * 100);
    $assetOtherCount = max(0, (int) ($summary['total_assets'] ?? 0) - (int) ($summary['rented_assets'] ?? 0) - (int) ($summary['available_assets'] ?? 0) - (int) ($summary['maintenance_assets'] ?? 0) - (int) ($summary['awaiting_verification_assets'] ?? 0));
@endphp

@section('content')
    <style>
        .asset-register-page {
            display: grid;
            gap: 18px;
            padding-bottom: calc(112px + env(safe-area-inset-bottom, 0px));
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
            padding: 11px 14px;
            font-weight: 800;
            color: var(--ph-color-text);
            font-family: var(--ph-font-heading);
        }
        .asset-filter-summary span {
            color: var(--ph-color-text-soft);
            font-size: 12px;
            font-weight: 700;
        }
        .asset-search-shell {
            display:grid;
            gap:10px;
            padding:12px 20px 0;
            border-top:1px solid var(--ph-color-border);
            background:linear-gradient(180deg, #f8fbff 0%, #ffffff 100%);
        }
        .asset-search-form {
            display:grid;
            grid-template-columns:minmax(0, 1fr) auto auto;
            gap:8px;
            align-items:end;
        }
        .asset-chip-inline-row { display:flex; gap:8px; flex-wrap:wrap; }
        .asset-filter-chip {
            display:inline-flex; align-items:center; min-height:30px; padding:6px 10px;
            border-radius:999px; border:1px solid var(--ph-color-border); background:#fff; color:var(--ph-color-text); font-size:12px; font-weight:700;
        }
        .asset-filter-form {
            padding: 0 14px 14px;
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr)) auto;
            gap: 8px;
            align-items: end;
        }
        .asset-field label {
            display: block;
            margin-bottom: 5px;
            color: var(--ph-color-text-soft);
            font-size: 12px;
            font-weight: 700;
        }
        .asset-field input,
        .asset-field select {
            width: 100%;
            padding: 9px 11px;
            border: 1px solid var(--ph-color-border-strong);
            border-radius: 11px;
            color: var(--ph-color-text);
            background: #fff;
            min-height: 40px;
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
        .asset-badge.is-danger { background:#fee2e2; color:#b91c1c; border-color:#fecaca; }
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
        .asset-register-page {
            gap: 12px;
        }
        .asset-hero {
            padding: 14px 18px !important;
            border-radius: 18px !important;
        }
        .asset-hero .rx-page-title {
            font-size: 28px !important;
        }
        .asset-hero .rx-page-subtitle {
            font-size: 14px !important;
            margin-top: 4px !important;
        }
        .asset-register-summary {
            grid-template-columns: repeat(6, minmax(0, 1fr));
            gap: 8px;
        }
        .asset-register-summary-card {
            padding: 10px 12px;
            border-radius: 14px;
            box-shadow: none;
        }
        .asset-summary-label {
            font-size: 10px;
            letter-spacing: .06em;
        }
        .asset-summary-value {
            margin-top: 3px;
            font-size: 22px;
            line-height: 1;
        }
        .asset-register-summary-card.is-green { background:#ecfdf5; border-color:#bbf7d0; }
        .asset-register-summary-card.is-green .asset-summary-label,
        .asset-register-summary-card.is-green .asset-summary-value { color:#047857; }
        .asset-register-summary-card.is-blue { background:#eff6ff; border-color:#bfdbfe; }
        .asset-register-summary-card.is-blue .asset-summary-label,
        .asset-register-summary-card.is-blue .asset-summary-value { color:#1d4ed8; }
        .asset-register-summary-card.is-red { background:#fef2f2; border-color:#fecaca; }
        .asset-register-summary-card.is-red .asset-summary-label,
        .asset-register-summary-card.is-red .asset-summary-value { color:#b91c1c; }
        .asset-register-summary-card.is-grey { background:#f8fafc; border-color:#e2e8f0; }
        .asset-register-summary-card.is-grey .asset-summary-label,
        .asset-register-summary-card.is-grey .asset-summary-value { color:#475569; }
        .asset-attention-panel {
            display:grid;
            gap:8px;
            padding:10px 12px;
            border:1px solid #fed7aa;
            border-radius:14px;
            background:#fff7ed;
        }
        .asset-attention-panel-title {
            margin:0;
            color:#9a3412;
            font-size:11px;
            font-weight:900;
            letter-spacing:.08em;
            text-transform:uppercase;
            font-family:var(--ph-font-heading);
        }
        .asset-attention-strip {
            display:grid;
            grid-template-columns:repeat(5, minmax(0, 1fr));
            gap:8px;
        }
        .asset-attention-item {
            display:flex;
            justify-content:space-between;
            gap:10px;
            align-items:center;
            padding:9px 11px;
            border:1px solid #fdba74;
            border-radius:13px;
            background:#fffaf0;
            color:#9a3412;
            text-decoration:none;
            font-size:12px;
            font-weight:800;
        }
        .asset-attention-item span {
            min-width:0;
            overflow:hidden;
            text-overflow:ellipsis;
            white-space:nowrap;
        }
        .asset-attention-item strong {
            font-size:18px;
            line-height:1;
            flex:0 0 auto;
        }
        .asset-register-shell-header {
            padding: 12px 16px;
        }
        .asset-register-shell-header h2 {
            font-size: 18px;
        }
        .asset-register-shell-header p {
            margin-top: 3px;
            font-size: 13px;
        }
        .asset-table {
            min-width: 0;
            table-layout: fixed;
        }
        .asset-table-head {
            position: sticky;
            top: 0;
            z-index: 3;
        }
        .asset-table-head th {
            padding: 9px 10px;
            font-size: 10px;
        }
        .asset-row {
            border-top: 1px solid #e5edf7;
            border-left: 4px solid transparent;
            cursor: pointer;
        }
        .asset-row:hover,
        .asset-row:focus-visible {
            background:#f8fbff;
        }
        .asset-row.is-green { border-left-color:#22c55e; }
        .asset-row.is-blue { border-left-color:#2563eb; }
        .asset-row.is-amber { border-left-color:#f59e0b; }
        .asset-row.is-red { border-left-color:#ef4444; background:#fffafa; }
        .asset-row.is-grey { border-left-color:#94a3b8; opacity:.9; }
        .asset-row-number {
            color:#475569;
            font-size:13px;
            font-weight:900;
            font-family:var(--ph-font-heading);
        }
        .asset-cell {
            padding: 8px 10px;
            vertical-align: middle;
        }
        .asset-identity-primary {
            display:flex;
            align-items:center;
            gap:8px;
            min-width:0;
            color:#0f172a;
            font-size:14px;
            line-height:1.15;
            font-weight:850;
            text-decoration:none;
        }
        .asset-identity-primary span {
            min-width:0;
            overflow:hidden;
            text-overflow:ellipsis;
            white-space:nowrap;
        }
        .asset-serial-link {
            display:inline-flex;
            align-items:center;
            gap:6px;
            min-width:0;
            color:#0f172a;
            text-decoration:none;
        }
        .asset-serial-prefix {
            flex:0 0 auto;
            color:#64748b;
            font-size:10px;
            font-weight:900;
            letter-spacing:.08em;
            text-transform:uppercase;
        }
        .asset-identity-product {
            margin-top:3px;
            color:#64748b;
            font-size:12px;
            line-height:1.25;
            overflow:hidden;
            text-overflow:ellipsis;
            white-space:nowrap;
        }
        .asset-compact-line {
            color:#64748b;
            font-size:12px;
            line-height:1.35;
            overflow:hidden;
            text-overflow:ellipsis;
            white-space:nowrap;
        }
        .asset-compact-line strong {
            color:#334155;
            font-weight:800;
        }
        .asset-custody-card {
            display:grid;
            gap:3px;
            padding:7px 9px;
            border:1px solid #dbeafe;
            border-radius:12px;
            background:#f8fbff;
            min-width:0;
        }
        .asset-custody-card.is-attention {
            background:#fffbeb;
            border-color:#fde68a;
        }
        .asset-custody-card.is-risk {
            background:#fef2f2;
            border-color:#fecaca;
        }
        .asset-custody-type {
            color:#1d4ed8;
            font-size:10px;
            font-weight:900;
            letter-spacing:.06em;
            text-transform:uppercase;
        }
        .asset-custody-main {
            color:#0f172a;
            font-size:13px;
            font-weight:900;
            overflow:hidden;
            text-overflow:ellipsis;
            white-space:nowrap;
        }
        .asset-health-line {
            display:flex;
            gap:5px;
            flex-wrap:wrap;
            margin-top:4px;
        }
        .asset-health-chip {
            display:inline-flex;
            align-items:center;
            min-height:22px;
            padding:3px 7px;
            border-radius:999px;
            background:#f8fafc;
            border:1px solid #e2e8f0;
            color:#475569;
            font-size:10px;
            font-weight:800;
            white-space:nowrap;
        }
        .asset-per-page-form {
            display:inline-flex;
            align-items:center;
            gap:7px;
            min-height:34px;
            padding:4px 6px 4px 10px;
            border:1px solid #d9e3f0;
            border-radius:14px;
            background:#ffffff;
        }
        .asset-per-page-form label {
            color:#64748b;
            font-size:11px;
            font-weight:900;
            letter-spacing:.06em;
            text-transform:uppercase;
        }
        .asset-per-page-form select {
            height:28px;
            border:0;
            border-radius:10px;
            color:#0f172a;
            background:#f8fafc;
            font-size:13px;
            font-weight:800;
            padding:0 26px 0 8px;
        }
        .asset-risk-badges {
            display:flex;
            gap:5px;
            flex-wrap:wrap;
            margin-top:5px;
        }
        .asset-risk-badge {
            display:inline-flex;
            padding:3px 7px;
            border-radius:999px;
            background:#fee2e2;
            color:#b91c1c;
            font-size:10px;
            font-weight:900;
            text-transform:uppercase;
        }
        .asset-row .asset-copy-btn {
            opacity: 0;
            width:24px;
            height:24px;
            font-size:0;
            border-radius:999px;
        }
        .asset-row:hover .asset-copy-btn {
            opacity: 1;
        }
        .asset-copy-btn::before {
            content: '#';
            font-size: 12px;
            line-height: 1;
        }
        .asset-primary-action {
            display:inline-flex;
            align-items:center;
            justify-content:center;
            min-height:32px;
            padding:6px 10px;
            border-radius:10px;
            background:var(--ph-color-primary);
            color:#fff;
            text-decoration:none;
            font-size:12px;
            font-weight:900;
            white-space:nowrap;
        }
        .asset-action-menu summary {
            width:32px;
            height:32px;
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
            align-items:center;
            gap:10px;
            margin-bottom:8px;
            flex-wrap:wrap;
        }
        .asset-quickscan-head h2 {
            margin:0;
            font-size:18px;
            color: var(--ph-color-text);
            font-family: var(--ph-font-heading);
        }
        .asset-quickscan-head p {
            margin:3px 0 0;
            color: var(--ph-color-text-soft);
            font-size:13px;
        }
        .asset-quickscan-form {
            display:flex;
            gap:8px;
            flex-wrap:wrap;
        }
        .asset-quickscan-input {
            flex:1;
            min-width:280px;
            padding:10px 12px;
            border:1px solid var(--ph-color-border-strong);
            border-radius:12px;
            background:#ffffff;
            font-size:15px;
            color: var(--ph-color-text);
            min-height:42px;
        }
        .asset-mobile-empty,
        .asset-empty {
            color: var(--ph-color-text-soft);
        }
        .asset-mobile-active-filters {
            display:none;
        }
        .asset-mobile-custody-line,
        .asset-mobile-health-line {
            display:flex;
            align-items:center;
            gap:6px;
            min-width:0;
            color:var(--ph-color-text-soft);
            font-size:12px;
            line-height:1.35;
        }
        .asset-mobile-custody-line strong {
            color:var(--ph-color-text);
            font-weight:800;
        }
        .asset-mobile-card-top {
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:8px;
            min-width:0;
        }
        .asset-mobile-serial {
            min-width:0;
            color:#0f172a;
            font-size:15px;
            line-height:1.1;
            font-weight:900;
            text-decoration:none;
            overflow:hidden;
            text-overflow:ellipsis;
            white-space:nowrap;
        }
        .asset-mobile-card-actions {
            display:flex;
            gap:6px;
            align-items:center;
            flex-wrap:wrap;
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
                gap: 8px;
                padding-bottom: calc(84px + env(safe-area-inset-bottom, 0px));
            }
            .asset-hero {
                padding:10px 12px !important;
                border-radius:16px !important;
            }
            .asset-hero .rx-eyebrow,
            .asset-hero .rx-page-subtitle,
            .asset-hero > div:last-child {
                display:none !important;
            }
            .asset-hero .rx-page-title {
                font-size:23px !important;
            }
            .asset-register-summary {
                grid-template-columns: repeat(5, minmax(0, 1fr));
                gap: 6px;
            }
            .asset-register-summary-card {
                padding: 8px 6px;
                border-radius: 12px;
                box-shadow:none;
                text-align:center;
            }
            .asset-summary-label {
                font-size:8.5px;
                letter-spacing:.05em;
            }
            .asset-summary-value {
                margin-top:3px;
                font-size:17px;
                line-height:1;
            }
            .asset-register-summary-card:nth-child(5) {
                display:none;
            }
            .asset-chip-row {
                flex-wrap:nowrap;
                gap:6px;
                overflow-x:auto;
                padding-bottom:2px;
                scrollbar-width:none;
            }
            .asset-chip-row::-webkit-scrollbar {
                display:none;
            }
            .asset-chip {
                min-height:30px;
                padding:6px 10px;
                font-size:11px;
                white-space:nowrap;
            }
            .asset-attention-panel {
                gap:6px;
                padding:8px 9px;
                border-radius:12px;
            }
            .asset-attention-strip {
                display:flex;
                gap:7px;
                overflow-x:auto;
                padding-bottom:1px;
                scrollbar-width:none;
            }
            .asset-attention-strip::-webkit-scrollbar {
                display:none;
            }
            .asset-attention-item {
                flex:0 0 132px;
                min-width:132px;
                padding:7px 9px;
                border-radius:11px;
                gap:7px;
                font-size:11px;
            }
            .asset-attention-item strong {
                font-size:16px;
            }
            .asset-mobile-active-filters {
                display:flex;
                gap:6px;
                flex-wrap:wrap;
            }
            .asset-card {
                border-radius:16px;
                box-shadow:none;
            }
            .asset-card > .rx-card-body {
                padding:8px 10px !important;
            }
            .asset-quickscan-head {
                margin:0;
            }
            .asset-quickscan-head h2 {
                font-size:14px;
            }
            .asset-quickscan-head p {
                display:none;
            }
            .asset-filter-summary {
                padding:10px 12px;
                display:flex;
                justify-content:space-between;
                gap:10px;
                align-items:center;
                font-size:14px;
            }
            .asset-filter-form {
                padding:0 10px 10px;
                gap:7px;
            }
            .asset-field label {
                margin-bottom:3px;
                font-size:10px;
            }
            .asset-field input,
            .asset-field select {
                min-height:36px;
                padding:7px 9px;
                border-radius:10px;
                font-size:13px;
            }
            .asset-filter-actions {
                display:grid;
                grid-template-columns:1fr 1fr;
                gap:7px;
            }
            .asset-quickscan-input {
                min-width:0;
                min-height:38px;
                padding:8px 10px;
                font-size:14px;
                border-radius:10px;
            }
            .asset-quickscan-form .ph-btn,
            .asset-quickscan-form .ph-btn-secondary {
                min-height:36px;
                padding:7px 9px;
                border-radius:10px;
                font-size:12px;
            }
            .asset-register-shell {
                border-radius: 16px;
            }
            .asset-register-shell-header {
                padding: 10px 12px;
                align-items:center;
            }
            .asset-register-shell-header h2 {
                font-size: 16px !important;
            }
            .asset-register-shell-header p {
                display:none;
            }
            .asset-per-page-form {
                min-height:32px;
                padding:3px 5px 3px 9px;
            }
            .asset-per-page-form label {
                font-size:10px;
            }
            .asset-per-page-form select {
                height:26px;
                max-width:94px;
            }
            .asset-table-shell {
                display: none;
            }
            .asset-mobile-list {
                display:grid;
                gap:7px;
                padding:7px;
                background:#f8fafc;
            }
            .asset-mobile-card {
                padding:9px 10px;
                border:1px solid #dbe3ef;
                border-left:4px solid #cbd5e1;
                border-radius:14px;
                background:#fff;
                display:grid;
                gap:7px;
                cursor:pointer;
            }
            .asset-mobile-card.is-green { border-left-color:#22c55e; }
            .asset-mobile-card.is-blue { border-left-color:#2563eb; }
            .asset-mobile-card.is-amber { border-left-color:#f59e0b; }
            .asset-mobile-card.is-red { border-left-color:#ef4444; background:#fffafa; }
            .asset-mobile-card.is-grey { border-left-color:#94a3b8; }
            .asset-mobile-card .asset-badge {
                padding:4px 7px;
                font-size:9.5px;
                line-height:1;
            }
            .asset-product-title {
                font-size:13px !important;
                line-height:1.2;
                display:-webkit-box;
                -webkit-line-clamp:2;
                -webkit-box-orient:vertical;
                overflow:hidden;
            }
            .asset-product-subtitle,
            .asset-meta-subtle {
                font-size:11.5px;
            }
            .asset-mobile-card-actions .ph-btn-secondary,
            .asset-mobile-card-actions .ph-btn-soft,
            .asset-mobile-card-actions .asset-action-menu summary {
                min-height:30px;
                padding:5px 8px;
                border-radius:9px;
                font-size:11px;
            }
            .asset-mobile-card-actions .asset-action-menu summary {
                width:32px;
                padding:0;
            }
            .asset-action-panel {
                position: static;
                min-width: 0;
                margin-top: 8px;
                box-shadow: none;
            }
            .asset-search-form {
                grid-template-columns: 1fr;
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


        /* Asset Register command-center refinement */
        .asset-hero { align-items:center; }
        .asset-hero > div:last-child { display:flex; gap:8px; flex-wrap:wrap; }
        .asset-hero .rx-btn-soft, .asset-hero .rx-btn-primary { min-height:38px; padding:9px 13px; border-radius:12px; }
        .asset-register-summary-card { display:flex; align-items:center; gap:10px; min-height:78px; }
        .asset-summary-icon { width:38px; height:38px; border-radius:13px; display:grid; place-items:center; flex:0 0 auto; background:#eef2ff; color:#4f46e5; }
        .asset-summary-icon svg { width:19px; height:19px; stroke:currentColor; stroke-width:2; fill:none; }
        .asset-summary-copy { min-width:0; }
        .asset-command-grid { display:grid; grid-template-columns:minmax(0, 1fr) 280px; gap:12px; align-items:start; }
        .asset-command-main { min-width:0; }
        .asset-side-panel { display:grid; gap:12px; position:sticky; top:86px; }
        .asset-side-card { background:#fff; border:1px solid var(--ph-color-border); border-radius:18px; box-shadow:var(--ph-shadow-soft); overflow:hidden; }
        .asset-side-card-header { padding:14px 16px 10px; display:flex; align-items:center; justify-content:space-between; gap:10px; border-bottom:1px solid #eef2f7; }
        .asset-side-card-title { margin:0; font-size:15px; font-weight:900; color:#0f172a; font-family:var(--ph-font-heading); }
        .asset-side-list { display:grid; padding:8px 14px 12px; }
        .asset-side-row { display:flex; justify-content:space-between; gap:12px; padding:7px 0; border-bottom:1px solid #eef2f7; color:#64748b; font-size:12px; }
        .asset-side-row:last-child { border-bottom:0; }
        .asset-side-row strong { color:#0f172a; font-size:13px; }
        .asset-util-card { display:grid; grid-template-columns:92px 1fr; gap:12px; align-items:center; padding:14px; }
        .asset-util-ring { width:86px; height:86px; border-radius:50%; display:grid; place-items:center; background:conic-gradient(#4f46e5 calc(var(--asset-utilization, 0) * 1%), #16a34a 0 85%, #f97316 0 92%, #e2e8f0 0); position:relative; }
        .asset-util-ring::after { content:''; position:absolute; inset:12px; border-radius:50%; background:#fff; }
        .asset-util-ring span { position:relative; z-index:1; font-size:20px; font-weight:900; color:#0f172a; }
        .asset-operation-grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:8px; padding:12px; }
        .asset-operation-btn { min-height:42px; display:grid; place-items:center; text-align:center; padding:8px; border:1px solid #dbe3ef; border-radius:12px; text-decoration:none; color:#3150ff; font-size:11px; font-weight:900; background:#fff; }
        .asset-search-card.asset-search-inline summary { display:none; }
        .asset-search-card.asset-search-inline .rx-card-body { padding:10px 12px !important; }
        .asset-search-card.asset-search-inline .asset-quickscan-head { display:none; }
        .asset-bulk-bar { display:none; align-items:center; justify-content:space-between; gap:10px; padding:10px 12px; border-bottom:1px solid #dbeafe; background:#eff6ff; }
        .asset-bulk-bar.is-visible { display:flex; }
        .asset-bulk-actions { display:flex; gap:8px; flex-wrap:wrap; justify-content:flex-end; }
        .asset-bulk-count { font-size:13px; font-weight:900; color:#1d4ed8; }
        .asset-icon-action { width:32px; height:32px; border-radius:10px; border:1px solid #dbe3ef; background:#fff; color:#3150ff; display:inline-grid; place-items:center; text-decoration:none; }
        .asset-icon-action svg { width:16px; height:16px; stroke:currentColor; stroke-width:2; fill:none; }
        .asset-row { border-left:3px solid transparent; }
        .asset-row.is-green { border-left-color:#22c55e; }
        .asset-row.is-blue { border-left-color:#2563eb; }
        .asset-row.is-amber { border-left-color:#f59e0b; }
        .asset-row.is-red { border-left-color:#ef4444; }
        .asset-row.is-grey { border-left-color:#94a3b8; }
        .asset-table-head th { padding:10px 12px; font-size:10.5px; }
        .asset-cell { padding:10px 12px; vertical-align:middle; }
        .asset-table { font-size:12px; }
        .asset-identity-product, .asset-custody-main { font-size:12.5px; }
        .asset-action-panel { right:0; top:38px; }
        @media (max-width: 1180px) {
            .asset-command-grid { grid-template-columns:1fr; }
            .asset-side-panel { position:static; grid-template-columns:repeat(3, minmax(0, 1fr)); }
        }
        @media (max-width: 767px) {
            .asset-hero { display:grid !important; gap:8px; }
            .asset-hero > div:last-child { display:grid !important; grid-template-columns:repeat(2, minmax(0, 1fr)); width:100%; gap:7px !important; }
            .asset-hero .rx-btn-soft, .asset-hero .rx-btn-primary { min-height:34px; padding:7px 9px; font-size:11px; }
            .asset-register-summary { grid-template-columns:repeat(2, minmax(0, 1fr)) !important; }
            .asset-register-summary-card:nth-child(5) { display:flex !important; }
            .asset-register-summary-card { min-height:64px; padding:8px 9px; gap:8px; }
            .asset-summary-icon { width:30px; height:30px; border-radius:10px; }
            .asset-summary-icon svg { width:15px; height:15px; }
            .asset-side-panel { grid-template-columns:1fr; }
            .asset-side-card { display:none; }
            .asset-side-card:first-child { display:block; }
            .asset-bulk-bar { position:sticky; top:0; z-index:8; align-items:flex-start; flex-direction:column; }
            .asset-bulk-actions { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); width:100%; }
            .asset-operation-grid { grid-template-columns:repeat(4, minmax(0, 1fr)); }
            .asset-operation-btn { min-height:38px; font-size:10px; }
            .asset-mobile-card-actions { grid-template-columns:repeat(4, minmax(0, 1fr)); }
        }

        .asset-register-page { gap:12px; }
        .asset-hero { padding:10px 12px !important; min-height:auto; align-items:center; }
        .asset-hero .rx-page-title { font-size:24px; line-height:1.1; margin:2px 0; }
        .asset-hero .rx-page-subtitle { font-size:13px; margin:0; }
        .asset-header-actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; justify-content:flex-end; }
        .asset-header-icon-btn { width:38px; height:38px; display:grid; place-items:center; border:1px solid #dbe3ef; border-radius:12px; background:#fff; color:#1d4ed8; text-decoration:none; font-weight:900; }
        .asset-header-icon-btn svg { width:18px; height:18px; stroke:currentColor; stroke-width:2; fill:none; }
        .asset-register-summary-card { min-height:82px; align-items:center; }
        .asset-attention-panel { padding:10px 12px; }
        .asset-attention-panel-title { margin-bottom:8px; font-size:12px; }
        .asset-attention-item { min-height:40px; padding:8px 12px; position:relative; }
        .asset-attention-item::after { content:'>'; font-size:16px; color:#9a3412; font-weight:900; }
        .asset-quickscan-head { display:none; }
        .asset-search-form, .asset-quickscan-form { grid-template-columns:minmax(0,1fr) auto auto auto; gap:8px; }
        .asset-quickscan-input { min-height:42px; }
        .asset-product-cell { display:flex; align-items:center; gap:10px; min-width:0; }
        .asset-product-thumb { width:46px; height:46px; border-radius:12px; border:1px solid #dbe3ef; background:#eef2ff; color:#3150ff; display:grid; place-items:center; flex:0 0 auto; overflow:hidden; font-size:12px; font-weight:900; }
        .asset-product-thumb img { width:100%; height:100%; object-fit:cover; display:block; }
        .asset-identity-meta { display:grid; gap:3px; min-width:0; }
        .asset-serial-link { font-size:12px; }
        .asset-identity-product { font-size:12px; line-height:1.25; }
        .asset-custody-card { padding:0; border:0; background:transparent; box-shadow:none; gap:3px; }
        .asset-custody-type { font-size:10px; }
        .asset-custody-main { font-size:12px; }
        .asset-health-line { gap:4px; }
        .asset-health-chip { padding:3px 6px; font-size:10px; }
        .asset-risk-badges { gap:4px; }
        .asset-risk-badge { padding:3px 6px; font-size:10px; }
        .asset-preview-button { cursor:pointer; }
        .asset-preview-overlay { position:fixed; inset:0; z-index:99998; background:rgba(15,23,42,.36); opacity:0; pointer-events:none; transition:opacity .18s ease; }
        .asset-preview-overlay.is-open { opacity:1; pointer-events:auto; }
        .asset-preview-drawer { position:fixed; top:0; right:0; height:100vh; width:min(420px, 100vw); z-index:99999; background:#fff; border-left:1px solid #dbe3ef; box-shadow:-22px 0 55px rgba(15,23,42,.18); transform:translateX(105%); transition:transform .22s ease; display:flex; flex-direction:column; }
        .asset-preview-drawer.is-open { transform:translateX(0); }
        .asset-preview-head { display:flex; justify-content:space-between; gap:12px; padding:16px; border-bottom:1px solid #e5edf7; }
        .asset-preview-title { display:flex; align-items:center; gap:10px; min-width:0; }
        .asset-preview-title h2 { margin:0; font-size:18px; line-height:1.2; }
        .asset-preview-title p { margin:2px 0 0; color:#64748b; font-size:12px; }
        .asset-preview-close { width:36px; height:36px; border:1px solid #dbe3ef; border-radius:12px; background:#fff; font-size:20px; cursor:pointer; }
        .asset-preview-body { padding:14px 16px 22px; overflow:auto; display:grid; gap:12px; }
        .asset-preview-section { border:1px solid #e5edf7; border-radius:14px; padding:12px; background:#f8fafc; }
        .asset-preview-section h3 { margin:0 0 10px; font-size:13px; color:#0f172a; }
        .asset-preview-grid { display:grid; gap:8px; }
        .asset-preview-row { display:flex; justify-content:space-between; gap:12px; font-size:12px; color:#64748b; border-bottom:1px solid #e5edf7; padding-bottom:6px; }
        .asset-preview-row:last-child { border-bottom:0; padding-bottom:0; }
        .asset-preview-row strong { color:#0f172a; text-align:right; }
        .asset-preview-timeline { display:grid; gap:8px; }
        .asset-preview-timeline-item { display:grid; grid-template-columns:18px 1fr; gap:8px; font-size:12px; color:#475569; }
        .asset-preview-timeline-item span:first-child { width:10px; height:10px; border-radius:50%; background:#3150ff; margin-top:4px; }
        .asset-preview-actions { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:8px; }
        .asset-preview-actions a { text-align:center; min-height:36px; display:grid; place-items:center; border-radius:10px; border:1px solid #dbe3ef; text-decoration:none; font-size:12px; font-weight:900; color:#1d4ed8; background:#fff; }
        .asset-mobile-identity { display:flex; gap:10px; align-items:flex-start; min-width:0; }
        .asset-mobile-details { margin-top:2px; border:1px solid #e5edf7; border-radius:12px; padding:8px 10px; background:#f8fafc; }
        .asset-mobile-details summary { cursor:pointer; font-size:12px; font-weight:900; color:#334155; }
        .asset-mobile-detail-grid { display:grid; gap:6px; padding-top:8px; font-size:12px; color:#64748b; }
        @media (max-width: 767px) {
            .asset-hero { padding:10px !important; }
            .asset-header-actions { width:100%; justify-content:flex-start; }
            .asset-register-summary-card { min-height:70px; }
            .asset-search-form, .asset-quickscan-form { grid-template-columns:1fr; }
            .asset-product-thumb { width:48px; height:48px; }
            .asset-preview-drawer { width:100vw; border-left:0; }
            .asset-preview-body { padding-bottom:calc(96px + env(safe-area-inset-bottom,0px)); }
            .asset-mobile-card { padding:10px; gap:8px; }
        }
        /* Asset Register compact table refinement */
        .asset-command-grid { grid-template-columns:minmax(0, 1fr) minmax(260px, 280px); gap:10px; }
        .asset-command-main { min-width:0; }
        .asset-side-panel { gap:10px; top:78px; }
        .asset-side-card { border-radius:14px; box-shadow:0 8px 24px rgba(15,23,42,.05); }
        .asset-side-card-header { padding:11px 12px 8px; }
        .asset-side-card-title { font-size:13px; }
        .asset-side-list { padding:6px 12px 10px; }
        .asset-side-row { padding:6px 0; font-size:11.5px; }
        .asset-side-row strong { font-size:12.5px; }
        .asset-util-card { grid-template-columns:72px 1fr; gap:10px; padding:11px; }
        .asset-util-ring { width:68px; height:68px; }
        .asset-util-ring::after { inset:10px; }
        .asset-util-ring span { font-size:16px; }
        .asset-operation-grid { gap:7px; padding:10px; }
        .asset-operation-btn { min-height:36px; padding:6px; border-radius:10px; font-size:10px; }
        .asset-register-shell-header { padding:10px 12px; }
        .asset-register-shell-header h2 { font-size:16px; }
        .asset-register-shell-header p { font-size:12px; }
        .asset-table-shell { overflow:auto; border-radius:0 0 14px 14px; }
        .asset-table { min-width:1060px; table-layout:fixed; font-size:11.5px; }
        .asset-table-head th { padding:8px 8px; font-size:9.5px; letter-spacing:.06em; line-height:1.2; }
        .asset-cell { padding:7px 8px; vertical-align:middle; }
        .asset-row { border-left-width:3px; }
        .asset-product-cell { gap:8px; align-items:center; }
        .asset-product-thumb { width:40px; height:40px; border-radius:10px; }
        .asset-identity-meta { gap:2px; }
        .asset-identity-primary { gap:5px; font-size:12.5px; flex-wrap:wrap; }
        .asset-serial-link { gap:4px; max-width:100%; }
        .asset-serial-prefix { display:none; }
        .asset-identity-product { white-space:normal; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; line-height:1.25; font-size:11.5px; margin-top:1px; }
        .asset-compact-line { font-size:11px; line-height:1.25; }
        .asset-badge { padding:3px 7px; font-size:9.5px; line-height:1.1; }
        .asset-custody-card { padding:0; border:0; background:transparent; box-shadow:none; gap:2px; }
        .asset-custody-card.is-attention,
        .asset-custody-card.is-risk { background:transparent; border-color:transparent; }
        .asset-custody-type { font-size:9.5px; color:#3150ff; }
        .asset-custody-main { font-size:11.5px; white-space:normal; display:-webkit-box; -webkit-line-clamp:1; -webkit-box-orient:vertical; overflow:hidden; }
        .asset-health-line { gap:4px; margin-top:3px; }
        .asset-health-chip { min-height:20px; padding:2px 6px; font-size:9.5px; }
        .asset-risk-badges { gap:4px; margin:0 0 3px; }
        .asset-risk-badge { padding:2px 6px; font-size:9.5px; }
        .asset-row-actions { display:inline-flex; align-items:center; gap:5px; justify-content:flex-end; flex-wrap:nowrap; }
        .asset-icon-action,
        .asset-action-menu summary { width:34px; height:34px; border-radius:10px; }
        .asset-icon-action svg { width:15px; height:15px; }
        .asset-action-panel { right:0; top:38px; }
        .asset-primary-action { width:34px; height:34px; min-height:34px; padding:0; border-radius:10px; font-size:0; }
        .asset-primary-action::before { content:'\2197'; font-size:16px; line-height:1; }
        .asset-mobile-card { padding:10px 11px; gap:8px; border-left:3px solid transparent; }
        .asset-mobile-card.is-green { border-left-color:#22c55e; }
        .asset-mobile-card.is-blue { border-left-color:#2563eb; }
        .asset-mobile-card.is-amber { border-left-color:#f59e0b; }
        .asset-mobile-card.is-red { border-left-color:#ef4444; }
        .asset-mobile-card-actions { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:7px; }
        .asset-mobile-card-actions .ph-btn-secondary { min-height:36px; padding:7px 8px; font-size:11px; }
        @media (max-width: 1260px) {
            .asset-command-grid { grid-template-columns:1fr; }
            .asset-side-panel { position:static; grid-template-columns:repeat(3,minmax(0,1fr)); }
        }
        @media (max-width: 767px) {
            .asset-table-shell { display:none; }
            .asset-side-panel { grid-template-columns:1fr; }
            .asset-product-thumb { width:44px; height:44px; }
            .asset-mobile-card-actions { grid-template-columns:repeat(4,minmax(0,1fr)); }
            .asset-mobile-card-actions .ph-btn-secondary { font-size:0; min-height:40px; }
            .asset-mobile-card-actions .ph-btn-secondary::first-letter { font-size:0; }
        }
    </style>

    <div class="asset-register-page">
        <div class="rx-page-header asset-hero">
            <div>
                <div class="rx-eyebrow" style="background:var(--ph-color-info-soft); color:var(--ph-color-primary);">Home / Asset Register</div>
                <h1 class="rx-page-title">Asset Register</h1>
                <p class="rx-page-subtitle">Track every physical asset.</p>
            </div>

            <div class="asset-header-actions">
                @if($canCreateAssets)
                    <a href="{{ route('assets.create', ['asset_stage' => 'new_stock']) }}" class="rx-btn-soft">+ Sale Unit</a>
                    <a href="{{ route('assets.create', ['asset_stage' => 'rental_stock']) }}" class="rx-btn-soft">+ Rental Asset</a>
                @endif
                <a href="{{ route('assets.pending-verification') }}" class="rx-btn-primary">Return Verification</a>
                <a href="#assetLookupInput" id="assetHeaderSearch" class="asset-header-icon-btn" title="Search assets" aria-label="Search assets"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m21 21-4.3-4.3"/><circle cx="11" cy="11" r="7"/></svg></a>
                <a href="#assetAdvancedFilters" class="asset-header-icon-btn" title="Open filters" aria-label="Open filters"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 5h18"/><path d="M6 12h12"/><path d="M10 19h4"/></svg></a>
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
                Asset Register shows only the linked Product Master identity. Review duplicate names in Product Master when brand/model variants share the same name.
            </div>
        @endif

        @if(($mixedAssetProductGroups ?? collect())->isNotEmpty())
            <div style="padding:14px 16px; border-radius:16px; background:var(--ph-color-danger-soft); border:1px solid rgba(179,13,35,.18); color:var(--ph-color-danger);">
                <strong>{{ $mixedAssetProductGroups->count() }} asset name {{ $mixedAssetProductGroups->count() === 1 ? 'group is' : 'groups are' }} already linked across multiple Product Master records.</strong>
                Review those assets before relying on name-only matching.
            </div>
        @endif

        <div class="asset-chip-row" aria-label="Asset register views">
            <a href="{{ route('assets.index') }}" class="asset-chip{{ $allUnitsActive ? ' is-active' : '' }}">All</a>
            <a href="{{ route('assets.index', ['asset_status' => 'available']) }}" class="asset-chip{{ $assetStatusFilter === 'available' ? ' is-active' : '' }}">Available</a>
            <a href="{{ route('assets.index', ['asset_status' => 'rented']) }}" class="asset-chip{{ $assetStatusFilter === 'rented' ? ' is-active' : '' }}">Rented</a>
            <a href="{{ route('assets.pending-verification') }}" class="asset-chip{{ $awaitingVerificationActive ? ' is-active' : '' }}">Verification</a>
            <a href="{{ route('assets.index', ['asset_status' => 'maintenance']) }}" class="asset-chip{{ $underRepairActive ? ' is-active' : '' }}">Repair</a>
            <a href="{{ route('assets.index', ['risk' => 'missing_custody']) }}" class="asset-chip{{ $assetRiskFilter === 'missing_custody' ? ' is-active' : '' }}">Risk</a>
        </div>

        @if($hasActiveFilters)
            <div class="asset-mobile-active-filters" aria-label="Active asset filters">
                @foreach($activeFilterChips as $chip)
                    <span class="asset-filter-chip">{{ $chip }}</span>
                @endforeach
                <a href="{{ route('assets.index') }}" class="asset-filter-chip" data-filter-clear="assets-index">Clear</a>
            </div>
        @endif

        <div class="asset-register-summary">
            <a href="{{ route('assets.index') }}" class="asset-register-summary-card">
                <div class="asset-summary-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg></div>
                <div class="asset-summary-copy">
                    <div class="asset-summary-label">Total Assets</div>
                    <div class="asset-summary-value">{{ $summary['total_assets'] }}</div>
                </div>
            </a>
            <a href="{{ route('assets.index', ['asset_status' => 'available']) }}" class="asset-register-summary-card is-green">
                <div class="asset-summary-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg></div>
                <div class="asset-summary-copy">
                    <div class="asset-summary-label">Available</div>
                    <div class="asset-summary-value">{{ $summary['available_assets'] }}</div>
                </div>
            </a>
            <a href="{{ route('assets.index', ['asset_status' => 'rented']) }}" class="asset-register-summary-card is-blue">
                <div class="asset-summary-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg></div>
                <div class="asset-summary-copy">
                    <div class="asset-summary-label">Rented</div>
                    <div class="asset-summary-value">{{ $summary['rented_assets'] }}</div>
                </div>
            </a>
            <a href="{{ route('assets.index', ['asset_status' => 'maintenance']) }}" class="asset-register-summary-card is-red">
                <div class="asset-summary-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg></div>
                <div class="asset-summary-copy">
                    <div class="asset-summary-label">Under Repair</div>
                    <div class="asset-summary-value">{{ $summary['maintenance_assets'] }}</div>
                </div>
            </a>
            <a href="{{ route('assets.pending-verification') }}" class="asset-register-summary-card is-warning">
                <div class="asset-summary-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg></div>
                <div class="asset-summary-copy">
                    <div class="asset-summary-label">Verification</div>
                    <div class="asset-summary-value">{{ $summary['awaiting_verification_assets'] }}</div>
                </div>
            </a>
            <a href="{{ route('assets.index', ['risk' => 'missing_custody']) }}" class="asset-register-summary-card is-red">
                <div class="asset-summary-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg></div>
                <div class="asset-summary-copy">
                    <div class="asset-summary-label">Risk</div>
                    <div class="asset-summary-value">{{ $summary['risk_assets'] }}</div>
                </div>
            </a>
        </div>

        <div class="asset-attention-panel">
            <p class="asset-attention-panel-title">Needs Attention</p>
            <div class="asset-attention-strip">
                <a href="{{ route('assets.index', ['risk' => 'verification']) }}" class="asset-attention-item" aria-label="Awaiting verification"><span>Verification</span><strong>{{ $summary['awaiting_verification_assets'] }}</strong></a>
                <a href="{{ route('assets.index', ['risk' => 'overdue_return']) }}" class="asset-attention-item" aria-label="Overdue rentals"><span>Overdue</span><strong>{{ $summary['attention_overdue_returns'] }}</strong></a>
                <a href="{{ route('assets.index', ['risk' => 'repair_delay']) }}" class="asset-attention-item" aria-label="Repair delays"><span>Repair</span><strong>{{ $summary['attention_repair_30_days'] }}</strong></a>
                <a href="{{ route('assets.index', ['risk' => 'missing_custody']) }}" class="asset-attention-item" aria-label="Missing custody"><span>Custody</span><strong>{{ $summary['attention_no_location'] }}</strong></a>
                <a href="{{ route('assets.index', ['risk' => 'no_movement']) }}" class="asset-attention-item" aria-label="No movement over 90 days"><span>No Move &gt;90d</span><strong>{{ $summary['attention_no_movement_90_days'] }}</strong></a>
            </div>
        </div>

        <div class="asset-card asset-search-card asset-search-inline">
            <div class="rx-card-body" style="padding:12px 14px;">
                <div class="asset-quickscan-head">
                    <div>
                        <h2>Scan / Search Asset</h2>
                        <p>Search serial, barcode, customer, rental, product, or asset ID.</p>
                    </div>
                </div>

                <form method="GET" action="{{ route('assets.scan-lookup') }}" id="assetLookupForm" class="asset-quickscan-form">
                    <input type="text" name="lookup" id="assetLookupInput" placeholder="Search by serial, barcode, customer, rental or product" autocomplete="off" spellcheck="false"
                           class="asset-quickscan-input">
                    <input type="file" id="assetLookupCameraInput" accept="image/*" capture="environment" style="display:none;">
                    <button type="button" id="assetLookupCameraButton" class="ph-btn-secondary">
                        Scan
                    </button>
                    <button type="submit" class="ph-btn">
                        Open Asset
                    </button>
                    <button type="submit" formaction="{{ route('assets.index') }}" id="assetLookupSearchButton" class="ph-btn-secondary">
                        Search
                    </button>
                </form>
            @if($hasActiveFilters)
                <div class="asset-chip-inline-row" style="margin-top:8px;">
                    @foreach($activeFilterChips as $chip)
                        <span class="asset-filter-chip">{{ $chip }}</span>
                    @endforeach
                    <a href="{{ route('assets.index') }}" class="asset-filter-chip" data-filter-clear="assets-index">Clear</a>
                </div>
            @endif
            </div>
        </div>

        <details id="assetAdvancedFilters" class="asset-card" data-filter-panel data-filter-panel-key="assets-index" data-filter-active="{{ $hasActiveFilters ? 'true' : 'false' }}" @if($hasActiveFilters) open @endif>
            <summary class="asset-filter-summary">Filters <span>{{ $hasActiveFilters ? 'Filters Active' : 'Closed' }}</span></summary>
            <form method="GET" action="{{ route('assets.index') }}" class="asset-filter-form">
                @if(filled(request('search')))
                    <input type="hidden" name="search" value="{{ request('search') }}">
                @endif
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
                        <option value="__missing" @selected(request('warehouse_id') === '__missing')>Missing warehouse</option>
                        @foreach($warehouses as $warehouse)
                            <option value="{{ $warehouse->id }}" @selected(request('warehouse_id') == $warehouse->id)>{{ $warehouse->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="asset-field">
                    <label>City</label>
                    <select name="city">
                        <option value="">All cities</option>
                        @foreach(($cityOptions ?? collect()) as $city)
                            <option value="{{ $city }}" @selected(request('city') === $city)>{{ $city }}</option>
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
                <div class="asset-field">
                    <label>Custody</label>
                    <select name="custody">
                        <option value="">Any custody</option>
                        <option value="warehouse" @selected(request('custody') === 'warehouse')>Warehouse</option>
                        <option value="customer" @selected(request('custody') === 'customer')>Customer / Rental</option>
                        <option value="sale" @selected(request('custody') === 'sale')>Sale</option>
                        <option value="verification" @selected(request('custody') === 'verification')>Awaiting Verification</option>
                        <option value="missing" @selected(request('custody') === 'missing')>Missing Custody</option>
                    </select>
                </div>
                <div class="asset-filter-actions">
                    <button type="submit" class="ph-btn">Filter</button>
                    <a href="{{ route('assets.index') }}" class="ph-btn-secondary" data-filter-clear="assets-index">Reset</a>
                </div>
            </form>
        </details>
        <div class="asset-command-grid">
            <div class="asset-command-main">

        <div class="asset-register-shell">
            <div class="asset-register-shell-header">
                <div>
                    <h2>Asset Register</h2>
                    <p>Custody, condition, risk, movement, and actions in one compact operational row.</p>
                </div>
                <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                    <form method="GET" action="{{ route('assets.index') }}" class="asset-per-page-form">
                        @foreach(request()->except(['per_page', 'page']) as $key => $value)
                            @if(is_array($value))
                                @foreach($value as $nestedValue)
                                    <input type="hidden" name="{{ $key }}[]" value="{{ $nestedValue }}">
                                @endforeach
                            @else
                                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                            @endif
                        @endforeach
                        <label for="assetPerPage">Show</label>
                        <select id="assetPerPage" name="per_page" onchange="this.form.submit()" aria-label="Assets per page">
                            @foreach([10, 25, 50, 100] as $option)
                                <option value="{{ $option }}" @selected((int) request('per_page', 25) === $option)>{{ $option }} assets</option>
                            @endforeach
                        </select>
                    </form>
                    <a href="{{ route('assets.export.csv', request()->query()) }}" class="ph-btn-secondary">Export CSV</a>
                    <span class="asset-badge is-neutral" style="padding:8px 12px;">
                        Physical units only
                    </span>
                </div>
            </div>
            <div class="asset-bulk-bar" data-asset-bulk-bar hidden>
                <span class="asset-bulk-count"><span data-asset-selected-count>0</span> selected</span>
                <div class="asset-bulk-actions">
                    <a href="{{ route('assets.pending-verification') }}" class="ph-btn-secondary">Bulk Verify</a>
                    <a href="{{ route('assets.index', ['asset_status' => 'available']) }}" class="ph-btn-secondary">Bulk Transfer</a>
                    <button type="button" class="ph-btn-secondary">Print Labels</button>
                    <a href="{{ route('assets.export.csv', request()->query()) }}" class="ph-btn-secondary">Export</a>
                    @if($canDeleteAssets)
                        <button type="button" class="ph-btn-secondary">Archive</button>
                    @endif
                </div>
            </div>



            <div class="asset-table-shell">
                <table class="asset-table">
                    <thead class="asset-table-head">
                        <tr>
                            <th style="width:34px;">#</th>
                            <th style="width:34px; text-align:center;">
                                <input type="checkbox" id="assetSelectAll" class="asset-table-checkbox" aria-label="Select all visible assets">
                            </th>
                            <th style="width:30%;">Asset Identity</th>
                            <th style="width:15%;">Current Custody</th>
                            <th style="width:12%;">Status</th>
                            <th style="width:16%;">Condition / Health</th>
                            <th style="width:13%;">Risk / Activity</th>
                            <th style="width:10%; text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($assets as $asset)
                        @php
                            $badge = $statusBadge($asset->asset_status);
                            $activeAssignment = $asset->activeRentalAssignments->sortByDesc('assigned_at')->first();
                            $latestAssignment = $asset->rentalAssignments->sortByDesc('assigned_at')->first();
                            $activeRental = $activeAssignment?->rental ?? $latestAssignment?->rental;
                            $activeCustomer = $activeRental?->customer;
                            $latestSale = $asset->sales->sortByDesc(fn ($sale) => $sale->sale_date ?? $sale->created_at)->first();
                            $productIdentity = $assetProductIdentity($asset->product);
                            $productThumbnail = $assetProductThumbnail($asset->product);
                            $latestMovement = $asset->movements->first();
                            $assetAge = $asset->purchase_date ? $asset->purchase_date->diffForHumans(['parts' => 1, 'short' => true]) : null;
                            $rentalCount = $asset->rentalAssignments->pluck('rental_id')->filter()->unique()->count();
                            $lastServiceLabel = $asset->last_service_date ? $asset->last_service_date->format('d M Y') : null;
                            $isOverdueReturn = $activeRental?->end_date
                                && optional($activeRental->end_date)->lt(now()->startOfDay())
                                && ! in_array((string) $activeRental->status, ['returned', 'cancelled'], true);
                            $isRepairDelayed = $asset->asset_status === 'maintenance' && optional($asset->updated_at)->lt(now()->subDays(30));
                            $isNoMovement90 = optional($asset->updated_at)->lt(now()->subDays(90));
                            $missingCustody = blank($asset->warehouse_id) && ! $activeRental && ! $latestSale;
                            $primaryActionLabel = match ($asset->asset_status) {
                                'awaiting_verification' => 'Verify Return',
                                'maintenance' => 'Update Repair',
                                'rented' => $activeRental ? 'View Rental' : 'View',
                                default => 'View',
                            };
                            $primaryActionUrl = match (true) {
                                $asset->asset_status === 'awaiting_verification' && $canUpdateAssets => route('assets.verify-return', $asset),
                                $asset->asset_status === 'rented' && $activeRental && $canViewRentals && \Illuminate\Support\Facades\Route::has('rentals.show') => route('rentals.show', $activeRental),
                                default => route('assets.show', $asset),
                            };
                            $riskBadges = collect([
                                $isOverdueReturn ? 'Overdue Return' : null,
                                $asset->asset_status === 'awaiting_verification' ? 'Verification Pending' : null,
                                $isRepairDelayed ? 'Repair >30 Days' : null,
                                $isNoMovement90 ? 'No Movement >90 Days' : null,
                                $missingCustody ? 'Missing Custody' : null,
                            ])->filter();
                        @endphp
                        <tr class="asset-row {{ $rowTone($asset->asset_status) }}" data-href="{{ route('assets.show', $asset) }}" data-asset-preview data-asset-url="{{ route('assets.show', $asset) }}" data-edit-url="{{ $canUpdateAssets ? route('assets.edit', $asset) : '' }}" data-transfer-url="{{ $canUpdateAssets ? route('assets.transfer', $asset) : '' }}" data-verify-url="{{ ($asset->asset_status === 'awaiting_verification' && $canUpdateAssets) ? route('assets.verify-return', $asset) : '' }}" data-asset-title="{{ e($asset->serial_number ?: $asset->asset_name ?: ('Asset #' . $asset->id)) }}" data-product="{{ e($productIdentity['primary']) }}" data-product-subtitle="{{ e($productIdentity['secondary']) }}" data-thumb="{{ e($productThumbnail ?? '') }}" data-status="{{ e($statusLabel($asset->asset_status)) }}" data-condition="{{ e($conditionLabel($asset->condition_status)) }}" data-warehouse="{{ e(optional($asset->warehouse)->name ?: '-') }}" data-custody="{{ e($activeRental ? (($activeCustomer?->name ?: 'Customer linked') . ' / Rental #' . $activeRental->id) : ($latestSale ? (($latestSale->customer?->name ?: 'Customer linked') . ' / Sale #' . $latestSale->id) : (optional($asset->warehouse)->name ?: 'Missing custody'))) }}" data-barcode="{{ e($asset->barcode_value ?: '-') }}" data-age="{{ e($assetAge ?: '-') }}" data-rentals="{{ e((string) $rentalCount) }}" data-service="{{ e($lastServiceLabel ?: '-') }}" data-movement="{{ e($latestMovement ? ($statusLabel($latestMovement->movement_type) . ' - ' . optional($latestMovement->created_at)->diffForHumans()) : ('Updated - ' . optional($asset->updated_at)->diffForHumans())) }}" tabindex="0" aria-label="Preview asset {{ $asset->serial_number ?: $asset->id }}">
                            <td class="asset-cell">
                                <span class="asset-row-number">{{ $assets->firstItem() + $loop->index }}</span>
                            </td>
                            <td class="asset-cell" style="text-align:center;">
                                <input type="checkbox" class="asset-table-checkbox asset-row-checkbox" value="{{ $asset->id }}" aria-label="Select asset {{ $asset->serial_number ?: $asset->id }}">
                            </td>
                            <td class="asset-cell">
                                <div class="asset-product-cell">
                                    <div class="asset-product-thumb" aria-hidden="true">
                                        @if($productThumbnail)
                                            <img src="{{ $productThumbnail }}" alt="">
                                        @else
                                            {{ mb_strtoupper(mb_substr($productIdentity['primary'], 0, 1)) }}
                                        @endif
                                    </div>
                                    <div class="asset-identity-meta">
                                    <div class="asset-identity-primary">
                                        <a href="{{ route('assets.show', $asset) }}" class="asset-serial-link">
                                            <span class="asset-serial-prefix">Serial</span>
                                            <span title="{{ $asset->serial_number }}">{{ $compactCode($asset->serial_number, 22) }}</span>
                                        </a>
                                        @if($asset->serial_number)
                                            <button type="button" class="asset-copy-btn" data-copy-text="{{ $asset->serial_number }}" title="Copy serial">Copy</button>
                                        @endif
                                        @if($asset->asset_stage === 'new_stock')
                                            <span class="asset-badge is-warning">Sale Unit</span>
                                        @else
                                            <span class="asset-badge is-info">Rental Asset</span>
                                        @endif
                                    </div>
                                    @if($asset->product && $canViewProducts && \Illuminate\Support\Facades\Route::has('products.show'))
                                        <a href="{{ route('products.show', $asset->product) }}" class="asset-identity-product">{{ $productIdentity['primary'] }} &bull; {{ $productIdentity['secondary'] }}</a>
                                    @else
                                        <div class="asset-identity-product">{{ $productIdentity['primary'] }} &bull; {{ $productIdentity['secondary'] }}</div>
                                    @endif
                                    <div class="asset-compact-line">
                                        Barcode {{ $compactCode($asset->barcode_value, 18) }}
                                        @if($asset->barcode_value)
                                            <button type="button" class="asset-copy-btn" data-copy-text="{{ $asset->barcode_value }}" title="Copy barcode">Copy</button>
                                        @endif
                                    </div>
                                    @if($asset->isSerialPending())
                                        <span class="asset-badge is-warning" style="width:max-content; padding:4px 8px; font-size:10px; font-weight:800;">Serial Pending</span>
                                    @endif
                                    @if($asset->variant_identity_warning)
                                        <span class="asset-badge is-warning" style="width:max-content; padding:4px 8px; font-size:10px; font-weight:800;">Check linked variant</span>
                                    @endif
                                    </div>
                                </div>
                            </td>
                            <td class="asset-cell">
                                @if($activeRental && $canViewRentals && \Illuminate\Support\Facades\Route::has('rentals.show'))
                                    <div class="asset-custody-card">
                                        <span class="asset-custody-type">Customer</span>
                                        <a href="{{ route('rentals.show', $activeRental) }}" class="asset-custody-main">{{ $activeCustomer?->name ?: 'Customer linked' }}</a>
                                        <span class="asset-compact-line">Rental #{{ $activeRental->id }}</span>
                                    </div>
                                @elseif($activeRental)
                                    <div class="asset-custody-card">
                                        <span class="asset-custody-type">Customer</span>
                                        <span class="asset-custody-main">{{ $activeCustomer?->name ?: 'Customer linked' }}</span>
                                        <span class="asset-compact-line">Rental #{{ $activeRental->id }}</span>
                                    </div>
                                @elseif($latestSale && $canViewSales && \Illuminate\Support\Facades\Route::has('sales.show'))
                                    <div class="asset-custody-card">
                                        <span class="asset-custody-type">Customer</span>
                                        <a href="{{ route('sales.show', $latestSale) }}" class="asset-custody-main">{{ $latestSale->customer?->name ?: 'Customer linked' }}</a>
                                        <span class="asset-compact-line">Sale #{{ $latestSale->id }}</span>
                                    </div>
                                @elseif($latestSale)
                                    <div class="asset-custody-card">
                                        <span class="asset-custody-type">Customer</span>
                                        <span class="asset-custody-main">{{ $latestSale->customer?->name ?: 'Customer linked' }}</span>
                                        <span class="asset-compact-line">Sale #{{ $latestSale->id }}</span>
                                    </div>
                                @elseif($asset->asset_status === 'maintenance')
                                    <div class="asset-custody-card is-attention">
                                        <span class="asset-custody-type">Repair Vendor</span>
                                        <span class="asset-custody-main">Service Desk</span>
                                        <span class="asset-compact-line">Under repair</span>
                                    </div>
                                @elseif($asset->asset_status === 'awaiting_verification')
                                    <div class="asset-custody-card is-attention">
                                        <span class="asset-custody-type">Awaiting Verification</span>
                                        <span class="asset-custody-main">{{ optional($asset->warehouse)->name ?: 'Return queue' }}</span>
                                        <span class="asset-compact-line">Post-return check</span>
                                    </div>
                                @elseif($asset->warehouse)
                                    <div class="asset-custody-card">
                                        <span class="asset-custody-type">Warehouse</span>
                                        <span class="asset-custody-main">{{ $asset->warehouse->name }}</span>
                                        <span class="asset-compact-line">Ready location</span>
                                    </div>
                                @else
                                    <div class="asset-custody-card is-risk">
                                        <span class="asset-custody-type">Missing Custody</span>
                                        <span class="asset-custody-main">-</span>
                                        @if($asset->variant_identity_warning)
                                            <span style="font-size:12px; color:var(--ph-color-warning);">{{ $asset->variant_identity_warning['message'] }}</span>
                                        @endif
                                    </div>
                                @endif
                            </td>
                            <td class="asset-cell">
                                <div style="display:grid; gap:5px;">
                                    <span class="asset-badge {{ $badge }}" style="width:max-content;">{{ $statusLabel($asset->asset_status) }}</span>
                                    <div class="asset-compact-line"><strong>Stage:</strong> {{ $asset->asset_stage === 'new_stock' ? 'Sale Unit' : 'Rental Asset' }}</div>
                                    <div class="asset-compact-line"><strong>Warehouse:</strong> {{ optional($asset->warehouse)->name ?: '-' }}</div>
                                </div>
                            </td>
                            <td class="asset-cell">
                                <div style="display:grid; gap:7px;">
                                    <span class="asset-badge {{ $conditionBadge($asset->condition_status) }}" style="width:max-content;">{{ $conditionLabel($asset->condition_status) }}</span>
                                    <div class="asset-health-line">
                                        <span class="asset-health-chip">Age {{ $assetAge ?: '-' }}</span>
                                        <span class="asset-health-chip">Rentals {{ $rentalCount }}</span>
                                        <span class="asset-health-chip">Service {{ $lastServiceLabel ?: '-' }}</span>
                                    </div>
                                </div>
                            </td>
                            <td class="asset-cell">
                                @if($riskBadges->isNotEmpty())
                                    <div class="asset-risk-badges">
                                        @foreach($riskBadges as $riskBadge)
                                            <span class="asset-risk-badge">{{ $riskBadge }}</span>
                                        @endforeach
                                    </div>
                                @else
                                    <span class="asset-health-chip">No active risk</span>
                                @endif
                                <div class="asset-compact-line"><strong>{{ $latestMovement ? $statusLabel($latestMovement->movement_type) : 'Updated' }}</strong></div>
                                <div class="asset-compact-line">{{ $latestMovement?->movedBy?->name ?: 'System' }}</div>
                                <div class="asset-compact-line">{{ optional($latestMovement?->created_at ?? $asset->updated_at)->diffForHumans() }}</div>
                            </td>
                            <td class="asset-cell" style="text-align:right;">
                                <div class="asset-row-actions">
                                    <a href="{{ route('assets.show', $asset) }}" class="asset-icon-action" title="View asset" aria-label="View asset"><svg viewBox="0 0 24 24"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg></a>
                                    <a href="{{ route('assets.show', $asset) }}#movements" class="asset-icon-action" title="Timeline" aria-label="Timeline"><svg viewBox="0 0 24 24"><path d="M12 8v5l3 3"/><circle cx="12" cy="12" r="10"/></svg></a>
                                    <a href="{{ route('assets.show', $asset) }}#custody" class="asset-icon-action" title="Location" aria-label="Location"><svg viewBox="0 0 24 24"><path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg></a>
                                    <details class="asset-action-menu">
                                        <summary aria-label="More actions for {{ $asset->serial_number }}">...</summary>
                                        <div class="asset-action-panel">
                                            <a href="{{ route('assets.show', $asset) }}" class="asset-action-link">View Asset</a>
                                            @if($canUpdateAssets)
                                                @if($asset->asset_status === 'awaiting_verification')
                                                    <a href="{{ route('assets.verify-return', $asset) }}" class="asset-action-link">Verify Return</a>
                                                @endif
                                                <a href="{{ route('assets.edit', $asset) }}" class="asset-action-link">Edit Asset</a>
                                                <a href="{{ route('assets.transfer', $asset) }}" class="asset-action-link">Stock Movement</a>
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
                            <td colspan="8" class="asset-empty" style="padding:24px 18px;">No units match this view right now. Try a broader filter or register a new sale unit or rental asset.</td>
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
                        $productIdentity = $assetProductIdentity($asset->product);
                        $productThumbnail = $assetProductThumbnail($asset->product);
                        $latestMovement = $asset->movements->first();
                        $isOverdueReturn = $activeRental?->end_date
                            && optional($activeRental->end_date)->lt(now()->startOfDay())
                            && ! in_array((string) $activeRental->status, ['returned', 'cancelled'], true);
                        $isRepairDelayed = $asset->asset_status === 'maintenance' && optional($asset->updated_at)->lt(now()->subDays(30));
                        $missingCustody = blank($asset->warehouse_id) && ! $activeRental && ! $latestSale;
                        $primaryActionLabel = match ($asset->asset_status) {
                            'awaiting_verification' => 'Verify',
                            'rented' => $activeRental ? 'Rental' : 'View',
                            default => 'View',
                        };
                        $primaryActionUrl = match (true) {
                            $asset->asset_status === 'awaiting_verification' && $canUpdateAssets => route('assets.verify-return', $asset),
                            $asset->asset_status === 'rented' && $activeRental && $canViewRentals && \Illuminate\Support\Facades\Route::has('rentals.show') => route('rentals.show', $activeRental),
                            default => route('assets.show', $asset),
                        };
                        $custodyLabel = match (true) {
                            (bool) $activeRental => 'Customer',
                            (bool) $latestSale => 'Sale',
                            $asset->asset_status === 'awaiting_verification' => 'Verification',
                            filled($asset->warehouse?->name) => 'Warehouse',
                            default => 'Custody',
                        };
                        $custodyValue = match (true) {
                            (bool) $activeRental => 'Rental #' . $activeRental->id . ($activeCustomer?->name ? ' - ' . $activeCustomer->name : ''),
                            (bool) $latestSale => 'Sale #' . $latestSale->id . ($latestSale->customer?->name ? ' - ' . $latestSale->customer->name : ''),
                            $asset->asset_status === 'awaiting_verification' => 'Awaiting return check',
                            filled($asset->warehouse?->name) => $asset->warehouse->name,
                            default => 'Missing custody',
                        };
                        $lastMovementText = $latestMovement?->created_at
                            ? 'Moved ' . $latestMovement->created_at->diffForHumans()
                            : 'Updated ' . optional($asset->updated_at)->diffForHumans();
                    @endphp
                    <div class="asset-mobile-card {{ $rowTone($asset->asset_status) }}" data-href="{{ route('assets.show', $asset) }}" data-asset-preview data-asset-url="{{ route('assets.show', $asset) }}" data-edit-url="{{ $canUpdateAssets ? route('assets.edit', $asset) : '' }}" data-transfer-url="{{ $canUpdateAssets ? route('assets.transfer', $asset) : '' }}" data-verify-url="{{ ($asset->asset_status === 'awaiting_verification' && $canUpdateAssets) ? route('assets.verify-return', $asset) : '' }}" data-asset-title="{{ e($asset->serial_number ?: $asset->asset_name ?: ('Asset #' . $asset->id)) }}" data-product="{{ e($productIdentity['primary']) }}" data-product-subtitle="{{ e($productIdentity['secondary']) }}" data-thumb="{{ e($productThumbnail ?? '') }}" data-status="{{ e($statusLabel($asset->asset_status)) }}" data-condition="{{ e($conditionLabel($asset->condition_status)) }}" data-warehouse="{{ e(optional($asset->warehouse)->name ?: '-') }}" data-custody="{{ e($custodyValue) }}" data-barcode="{{ e($asset->barcode_value ?: '-') }}" data-age="{{ e($asset->purchase_date ? $asset->purchase_date->diffForHumans(['parts' => 1, 'short' => true]) : '-') }}" data-rentals="{{ e((string) $asset->rentalAssignments->pluck('rental_id')->filter()->unique()->count()) }}" data-service="{{ e($asset->last_service_date ? $asset->last_service_date->format('d M Y') : '-') }}" data-movement="{{ e($lastMovementText) }}" tabindex="0" aria-label="Preview asset {{ $asset->serial_number ?: $asset->id }}">
                        <div class="asset-mobile-card-top">
                            <a href="{{ route('assets.show', $asset) }}" class="asset-mobile-serial">
                                {{ $assets->firstItem() + $loop->index }}. {{ $compactCode($asset->serial_number ?: $asset->asset_name ?: ('Asset #' . $asset->id), 22) }}
                            </a>
                            <span class="asset-badge {{ $badge }}">{{ $statusLabel($asset->asset_status) }}</span>
                        </div>

                        <div class="asset-mobile-identity">
                            <div class="asset-product-thumb" aria-hidden="true">
                                @if($productThumbnail)
                                    <img src="{{ $productThumbnail }}" alt="">
                                @else
                                    {{ mb_strtoupper(mb_substr($productIdentity['primary'], 0, 1)) }}
                                @endif
                            </div>
                            <div style="min-width:0; display:grid; gap:3px;">
                                <a href="{{ route('assets.show', $asset) }}" class="rn-record-link asset-product-title">{{ $productIdentity['primary'] }}</a>
                                <span class="asset-compact-line">Serial {{ $compactCode($asset->serial_number, 18) }} - Barcode {{ $compactCode($asset->barcode_value, 14) }}</span>
                            </div>
                        </div>

                        <div class="asset-mobile-custody-line">
                            <strong>{{ $custodyLabel }}:</strong>
                            <span>{{ $custodyValue }}</span>
                        </div>

                        <div class="asset-mobile-health-line">
                            <span class="asset-badge {{ $conditionBadge($asset->condition_status) }}">{{ $conditionLabel($asset->condition_status) }}</span>
                            <span>{{ $lastMovementText }}</span>
                            @if($isOverdueReturn || $isRepairDelayed || $missingCustody || $asset->asset_status === 'awaiting_verification')
                                <span class="asset-risk-badge">{{ $asset->asset_status === 'awaiting_verification' ? 'Verify' : 'Risk' }}</span>
                            @endif
                        </div>

                        <details class="asset-mobile-details">
                            <summary>More details</summary>
                            <div class="asset-mobile-detail-grid">
                                <span>Warehouse: {{ optional($asset->warehouse)->name ?: '-' }}</span>
                                <span>Barcode: {{ $compactCode($asset->barcode_value, 24) }}</span>
                                <span>Condition: {{ $conditionLabel($asset->condition_status) }}</span>
                                <span>Status: {{ $statusLabel($asset->asset_status) }}</span>
                            </div>
                        </details>

                        <div class="asset-mobile-card-actions">
                            <button type="button" class="ph-btn-secondary asset-preview-button" data-open-asset-preview>Preview</button>
                            <a href="{{ $primaryActionUrl }}" class="ph-btn-secondary">{{ $primaryActionLabel }}</a>
                            @if($canUpdateAssets)
                                <details class="asset-action-menu">
                                    <summary aria-label="More actions for {{ $asset->serial_number }}">...</summary>
                                    <div class="asset-action-panel">
                                        <a href="{{ route('assets.edit', $asset) }}" class="asset-action-link">Edit Asset</a>
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
            <aside class="asset-side-panel" aria-label="Asset Register summary">
                <section class="asset-side-card asset-selected-summary" data-asset-selected-summary hidden>
                    <div class="asset-side-card-header"><h2 class="asset-side-card-title">Selected Asset</h2></div>
                    <div class="asset-side-list">
                        <div class="asset-side-row"><span>Asset</span><strong data-selected-asset-title>-</strong></div>
                        <div class="asset-side-row"><span>Product</span><strong data-selected-asset-product>-</strong></div>
                        <div class="asset-side-row"><span>Status</span><strong data-selected-asset-status>-</strong></div>
                        <div class="asset-side-row"><span>Custody</span><strong data-selected-asset-custody>-</strong></div>
                    </div>
                    <div class="asset-operation-grid">
                        <a href="#" class="asset-operation-btn" data-selected-asset-open>Open Asset</a>
                        <a href="#" class="asset-operation-btn" data-selected-asset-edit>Edit</a>
                    </div>
                </section>
                <section class="asset-side-card">
                    <div class="asset-side-card-header"><h2 class="asset-side-card-title">Quick Summary</h2></div>
                    <div class="asset-side-list">
                        <div class="asset-side-row"><span>Total Assets</span><strong>{{ $summary['total_assets'] }}</strong></div>
                        <div class="asset-side-row"><span>Available</span><strong>{{ $summary['available_assets'] }}</strong></div>
                        <div class="asset-side-row"><span>Rented</span><strong>{{ $summary['rented_assets'] }}</strong></div>
                        <div class="asset-side-row"><span>Under Repair</span><strong>{{ $summary['maintenance_assets'] }}</strong></div>
                        <div class="asset-side-row"><span>Verification</span><strong>{{ $summary['awaiting_verification_assets'] }}</strong></div>
                        <div class="asset-side-row"><span>Risk</span><strong>{{ $summary['risk_assets'] }}</strong></div>
                    </div>
                </section>
                <section class="asset-side-card">
                    <div class="asset-side-card-header"><h2 class="asset-side-card-title">Asset Utilization</h2></div>
                    <div class="asset-util-card" style="--asset-utilization: {{ $assetUtilizationPercent }};">
                        <div class="asset-util-ring"><span>{{ $assetUtilizationPercent }}%</span></div>
                        <div class="asset-side-list" style="padding:0;">
                            <div class="asset-side-row"><span>Rented</span><strong>{{ $summary['rented_assets'] }}</strong></div>
                            <div class="asset-side-row"><span>Available</span><strong>{{ $summary['available_assets'] }}</strong></div>
                            <div class="asset-side-row"><span>Repair</span><strong>{{ $summary['maintenance_assets'] }}</strong></div>
                            <div class="asset-side-row"><span>Other</span><strong>{{ $assetOtherCount }}</strong></div>
                        </div>
                    </div>
                </section>
                <section class="asset-side-card">
                    <div class="asset-side-card-header"><h2 class="asset-side-card-title">Operations Shortcuts</h2></div>
                    <div class="asset-operation-grid">
                        <a href="{{ route('assets.pending-verification') }}" class="asset-operation-btn">Bulk Verify</a>
                        <a href="{{ route('assets.index', ['asset_status' => 'available']) }}" class="asset-operation-btn">Bulk Transfer</a>
                        <a href="{{ route('assets.index') }}" class="asset-operation-btn">Print Labels</a>
                        <a href="{{ route('assets.export.csv', request()->query()) }}" class="asset-operation-btn">Export CSV</a>
                    </div>
                </section>
            </aside>
        </div>
        </div>
        <div class="asset-preview-overlay" data-asset-preview-overlay data-asset-preview-close hidden></div>
        <aside class="asset-preview-drawer" data-asset-preview-drawer aria-hidden="true" aria-label="Asset quick preview" hidden>
            <div class="asset-preview-head">
                <div class="asset-preview-title">
                    <div class="asset-product-thumb" aria-hidden="true" data-preview-thumb-wrap><span data-preview-thumb-fallback>A</span></div>
                    <div style="min-width:0;">
                        <h2 data-preview-title>Asset preview</h2>
                        <p data-preview-product>Product</p>
                    </div>
                </div>
                <button type="button" class="asset-preview-close" data-asset-preview-close aria-label="Close asset preview">&times;</button>
            </div>
            <div class="asset-preview-body">
                <section class="asset-preview-section">
                    <h3>Current State</h3>
                    <div class="asset-preview-grid">
                        <div class="asset-preview-row"><span>Status</span><strong data-preview-status>-</strong></div>
                        <div class="asset-preview-row"><span>Condition</span><strong data-preview-condition>-</strong></div>
                        <div class="asset-preview-row"><span>Custody</span><strong data-preview-custody>-</strong></div>
                        <div class="asset-preview-row"><span>Warehouse</span><strong data-preview-warehouse>-</strong></div>
                        <div class="asset-preview-row"><span>Barcode</span><strong data-preview-barcode>-</strong></div>
                    </div>
                </section>
                <section class="asset-preview-section">
                    <h3>Lifecycle</h3>
                    <div class="asset-preview-timeline">
                        <div class="asset-preview-timeline-item"><span></span><div><strong>Created</strong><br><small>Registered in Asset Register</small></div></div>
                        <div class="asset-preview-timeline-item"><span></span><div><strong data-preview-warehouse-timeline>Warehouse</strong><br><small>Current stock custody</small></div></div>
                        <div class="asset-preview-timeline-item"><span></span><div><strong data-preview-custody-timeline>Custody</strong><br><small>Current customer, sale, or warehouse owner</small></div></div>
                        <div class="asset-preview-timeline-item"><span></span><div><strong>Latest movement</strong><br><small data-preview-movement>-</small></div></div>
                    </div>
                </section>
                <section class="asset-preview-section">
                    <h3>Health</h3>
                    <div class="asset-preview-grid">
                        <div class="asset-preview-row"><span>Age</span><strong data-preview-age>-</strong></div>
                        <div class="asset-preview-row"><span>Rentals</span><strong data-preview-rentals>-</strong></div>
                        <div class="asset-preview-row"><span>Service due</span><strong data-preview-service>-</strong></div>
                    </div>
                </section>
                <section class="asset-preview-section">
                    <h3>Quick Actions</h3>
                    <div class="asset-preview-actions">
                        <a href="#" data-preview-open>Open asset</a>
                        <a href="#" data-preview-edit>Edit</a>
                        <a href="#" data-preview-transfer>Transfer</a>
                        <a href="#" data-preview-verify>Verify</a>
                    </div>
                </section>
            </div>
        </aside>

@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const lookupInput = document.getElementById('assetLookupInput');
    const lookupForm = document.getElementById('assetLookupForm');
    const lookupSearchButton = document.getElementById('assetLookupSearchButton');
    const lookupCameraInput = document.getElementById('assetLookupCameraInput');
    const lookupCameraButton = document.getElementById('assetLookupCameraButton');
    const assetSelectAll = document.getElementById('assetSelectAll');
    const assetRowCheckboxes = Array.from(document.querySelectorAll('.asset-row-checkbox'));
    const assetRows = Array.from(document.querySelectorAll('.asset-row[data-href], .asset-mobile-card[data-href]'));
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

    if (lookupForm && lookupInput && lookupSearchButton) {
        lookupSearchButton.addEventListener('click', function () {
            lookupInput.name = 'search';
        });

        lookupForm.addEventListener('submit', function (event) {
            if (event.submitter !== lookupSearchButton) {
                lookupInput.name = 'lookup';
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

    let updateAssetBulkBar = function () {
        const bulkBar = document.querySelector('[data-asset-bulk-bar]');
        const countTarget = document.querySelector('[data-asset-selected-count]');
        if (!bulkBar || !countTarget) {
            return;
        }
        const selectedCount = assetRowCheckboxes.filter(function (checkbox) { return checkbox.checked; }).length;
        countTarget.textContent = selectedCount;
        bulkBar.hidden = selectedCount === 0;
        bulkBar.classList.toggle('is-visible', selectedCount > 0);
        updateSelectedAssetSummary();
    };

    const previewOverlay = document.querySelector('[data-asset-preview-overlay]');
    const previewDrawer = document.querySelector('[data-asset-preview-drawer]');
    const selectedSummaryCard = document.querySelector('[data-asset-selected-summary]');

    function setPreviewText(selector, value) {
        const target = document.querySelector(selector);
        if (target) {
            target.textContent = value || '-';
        }
    }

    function setPreviewLink(selector, href) {
        const target = document.querySelector(selector);
        if (!target) {
            return;
        }
        if (href) {
            target.href = href;
            target.removeAttribute('aria-disabled');
            target.style.display = '';
        } else {
            target.href = '#';
            target.setAttribute('aria-disabled', 'true');
            target.style.display = 'none';
        }
    }

    function setPreviewThumb(source) {
        const wrap = document.querySelector('[data-preview-thumb-wrap]');
        if (!wrap) {
            return;
        }
        wrap.innerHTML = '';
        if (source.dataset.thumb) {
            const image = document.createElement('img');
            image.src = source.dataset.thumb;
            image.alt = '';
            wrap.appendChild(image);
            return;
        }
        const fallback = document.createElement('span');
        fallback.textContent = (source.dataset.product || source.dataset.assetTitle || 'A').trim().charAt(0).toUpperCase();
        wrap.appendChild(fallback);
    }

    function openAssetPreview(source) {
        if (!source || !previewOverlay || !previewDrawer) {
            if (source?.dataset.href) {
                window.location.href = source.dataset.href;
            }
            return;
        }
        setPreviewThumb(source);
        setPreviewText('[data-preview-title]', source.dataset.assetTitle);
        setPreviewText('[data-preview-product]', [source.dataset.product, source.dataset.productSubtitle].filter(Boolean).join(' - '));
        setPreviewText('[data-preview-status]', source.dataset.status);
        setPreviewText('[data-preview-condition]', source.dataset.condition);
        setPreviewText('[data-preview-custody]', source.dataset.custody);
        setPreviewText('[data-preview-warehouse]', source.dataset.warehouse);
        setPreviewText('[data-preview-barcode]', source.dataset.barcode);
        setPreviewText('[data-preview-warehouse-timeline]', source.dataset.warehouse);
        setPreviewText('[data-preview-custody-timeline]', source.dataset.custody);
        setPreviewText('[data-preview-movement]', source.dataset.movement);
        setPreviewText('[data-preview-age]', source.dataset.age);
        setPreviewText('[data-preview-rentals]', source.dataset.rentals);
        setPreviewText('[data-preview-service]', source.dataset.service);
        setPreviewLink('[data-preview-open]', source.dataset.assetUrl || source.dataset.href);
        setPreviewLink('[data-preview-edit]', source.dataset.editUrl);
        setPreviewLink('[data-preview-transfer]', source.dataset.transferUrl);
        setPreviewLink('[data-preview-verify]', source.dataset.verifyUrl);

        previewOverlay.hidden = false;
        previewDrawer.hidden = false;
        requestAnimationFrame(function () {
            previewOverlay.classList.add('is-open');
            previewDrawer.classList.add('is-open');
            previewDrawer.setAttribute('aria-hidden', 'false');
        });
    }

    function closeAssetPreview() {
        if (!previewOverlay || !previewDrawer) {
            return;
        }
        previewOverlay.classList.remove('is-open');
        previewDrawer.classList.remove('is-open');
        previewDrawer.setAttribute('aria-hidden', 'true');
        setTimeout(function () {
            previewOverlay.hidden = true;
            previewDrawer.hidden = true;
        }, 220);
    }

    function updateSelectedAssetSummary() {
        if (!selectedSummaryCard) {
            return;
        }
        const selected = assetRowCheckboxes.filter(function (checkbox) { return checkbox.checked; });
        if (selected.length !== 1) {
            selectedSummaryCard.hidden = true;
            return;
        }
        const row = selected[0].closest('[data-asset-preview]');
        if (!row) {
            selectedSummaryCard.hidden = true;
            return;
        }
        selectedSummaryCard.hidden = false;
        setPreviewText('[data-selected-asset-title]', row.dataset.assetTitle);
        setPreviewText('[data-selected-asset-product]', row.dataset.product);
        setPreviewText('[data-selected-asset-status]', row.dataset.status);
        setPreviewText('[data-selected-asset-custody]', row.dataset.custody);
        setPreviewLink('[data-selected-asset-open]', row.dataset.assetUrl || row.dataset.href);
        setPreviewLink('[data-selected-asset-edit]', row.dataset.editUrl);
    }


    if (assetSelectAll && assetRowCheckboxes.length) {
        assetSelectAll.addEventListener('change', function () {
            assetRowCheckboxes.forEach(function (checkbox) {
                checkbox.checked = assetSelectAll.checked;
            });
            updateAssetBulkBar();
        });

        assetRowCheckboxes.forEach(function (checkbox) {
            checkbox.addEventListener('change', function () {
                assetSelectAll.checked = assetRowCheckboxes.every(function (rowCheckbox) {
                    return rowCheckbox.checked;
                });
                updateAssetBulkBar();
            });
        });
        updateAssetBulkBar();
    }

    function isInteractiveClick(target) {
        return Boolean(target.closest('a, button, input, select, textarea, details, summary, label, form'));
    }

    assetRows.forEach(function (row) {
        row.addEventListener('click', function (event) {
            if (isInteractiveClick(event.target)) {
                return;
            }
            openAssetPreview(row);
        });

        row.addEventListener('keydown', function (event) {
            if (!['Enter', ' '].includes(event.key) || isInteractiveClick(event.target)) {
                return;
            }
            event.preventDefault();
            openAssetPreview(row);
        });
    });

    document.addEventListener('click', function (event) {
        const previewButton = event.target.closest('[data-open-asset-preview]');
        if (previewButton) {
            event.preventDefault();
            openAssetPreview(previewButton.closest('[data-asset-preview]'));
            return;
        }
        if (event.target.closest('[data-asset-preview-close]')) {
            event.preventDefault();
            closeAssetPreview();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeAssetPreview();
        }
    });

    if (lookupInput) {
        let filterTimer = null;
        lookupInput.addEventListener('input', function () {
            clearTimeout(filterTimer);
            filterTimer = setTimeout(function () {
                const query = lookupInput.value.trim().toLowerCase();
                document.querySelectorAll('.asset-row[data-asset-preview], .asset-mobile-card[data-asset-preview]').forEach(function (item) {
                    item.hidden = Boolean(query) && !item.textContent.toLowerCase().includes(query);
                });
            }, 120);
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
