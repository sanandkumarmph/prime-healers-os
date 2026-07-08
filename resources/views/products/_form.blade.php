@php
    $isEdit = $product->exists;
    $currentUser = auth()->user();
    $canCreateAssets = $currentUser?->canAccessModule('assets', 'create') ?? false;
    $canCreateProducts = $currentUser?->canAccessModule('products', 'create') ?? false;
    $rupee = html_entity_decode('&#8377;');
    $categoryOptions = collect($categoryOptions ?? []);
    $brandOptions = collect($brandOptions ?? []);

    $productType = old('product_type', $product->product_type ?? (
        (($product->is_sellable ?? false) && ($product->is_rentable ?? false))
            ? \App\Models\Product::TYPE_BOTH
            : (($product->is_rentable ?? false) ? \App\Models\Product::TYPE_RENTABLE : \App\Models\Product::TYPE_SELLABLE)
    ));
    $stockMode = old('stock_mode', $product->stock_mode ?? \App\Models\Product::STOCK_MODE_UNTRACKED);
    $categoryIdValue = old('category_id', $product->category_id);
    $brandIdValue = old('brand_id', $product->brand_id);
    $pricePerDayValue = old('price_per_day', $product->price_per_day);
    $salePriceValue = old('sale_price', $product->sale_price);
    $rental15DayValue = old('rental_price_15_days', $product->rental_price_15_days);
    $rental30DayValue = old('rental_price_30_days', $product->rental_price_30_days ?? $product->rental_price);
    $rental3MonthValue = old('rental_price_3_months', $product->rental_price_3_months);
    $quantityValue = old('quantity', (int) ($product->total_quantity ?? 0));
    $gstTaxTypeValue = old('gst_tax_type', $product->gst_tax_type);
    $gstCalculationModeValue = old('gst_calculation_mode', $product->gst_calculation_mode ?? 'exclusive');
    $cgstRateValue = old('cgst_rate', $product->cgst_rate ?? 0);
    $sgstRateValue = old('sgst_rate', $product->sgst_rate ?? 0);
    $igstRateValue = old('igst_rate', $product->igst_rate ?? 0);
    $gstStandardRates = [0, 5, 12, 18, 28];
    $splitTotalGstRateValue = round((float) $cgstRateValue + (float) $sgstRateValue, 2);
    $igstTotalGstRateValue = round((float) $igstRateValue, 2);
    $managedSaleUnitsCount = $isEdit ? (int) $product->saleUnits()->count() : 0;
    $managedRentalAssetsCount = $isEdit
        ? (int) $product->assets()->where('asset_stage', \App\Models\Asset::STAGE_RENTAL_STOCK)->count()
        : 0;
    $usesManagedStock = $stockMode !== \App\Models\Product::STOCK_MODE_UNTRACKED || ($managedSaleUnitsCount + $managedRentalAssetsCount) > 0;
    $isReusableInventoryProfile = $productType === \App\Models\Product::TYPE_BOTH
        && $stockMode === \App\Models\Product::STOCK_MODE_TRACKED_BOTH;
    $isConsumableInventoryProfile = $productType === \App\Models\Product::TYPE_SELLABLE
        && $stockMode === \App\Models\Product::STOCK_MODE_UNTRACKED;
    $inventoryProfile = $isReusableInventoryProfile
        ? 'equipment'
        : ($isConsumableInventoryProfile ? 'consumable' : 'advanced');
    $stockModeLabel = match ($stockMode) {
        \App\Models\Product::STOCK_MODE_TRACKED_SALE => 'Tracked Sale',
        \App\Models\Product::STOCK_MODE_TRACKED_RENTAL => 'Tracked Rental',
        \App\Models\Product::STOCK_MODE_TRACKED_BOTH => 'Tracked Both',
        default => 'Untracked',
    };
    $canUpdateProducts = $currentUser?->canAccessModule('products', 'update') ?? false;
    $canManageProductMasters = ($currentUser?->isSuperAdmin() ?? false)
        || ($currentUser?->isAdminOperations() ?? false)
        || ($currentUser?->canAccessModule('products', 'create') ?? false);
    $fieldStyle = fn (string $field, string $base) => $base . ($errors->has($field)
        ? ' border-color:#dc2626; box-shadow:0 0 0 3px rgba(220, 38, 38, 0.12); background:#fff7f7;'
        : '');
    $fieldError = fn (string $field) => $errors->first($field);
    $gstDropdownOptions = function (float|int|string|null $selectedValue) use ($gstStandardRates): array {
        $normalizedSelected = number_format((float) ($selectedValue ?? 0), 2, '.', '');
        $options = collect($gstStandardRates)
            ->map(fn ($rate) => number_format((float) $rate, 2, '.', ''))
            ->all();

        if (!in_array($normalizedSelected, $options, true)) {
            $options[] = $normalizedSelected;
        }

        return collect($options)
            ->unique()
            ->sort(fn ($left, $right) => (float) $left <=> (float) $right)
            ->values()
            ->all();
    };
@endphp

<style>
    .product-form-page {
        max-width: 1380px;
        margin: 0 auto;
    }
    .product-form-page input,
    .product-form-page select,
    .product-form-page textarea {
        min-height:40px !important;
        padding:8px 10px !important;
        border-radius:10px !important;
        font-size:14px;
    }
    .product-master-modal {
        position:fixed;
        inset:0;
        z-index:5000;
        display:none;
        align-items:center;
        justify-content:center;
        padding:18px;
        background:rgba(15,23,42,.42);
    }
    .product-master-modal.is-open { display:flex; }
    .product-master-dialog {
        width:min(520px, 100%);
        max-height:calc(100vh - 36px);
        display:grid;
        grid-template-rows:auto minmax(0, 1fr) auto;
        min-height:0;
        overflow:hidden;
        border-radius:22px;
        border:1px solid #dbe3ef;
        background:#fff;
        box-shadow:0 28px 80px rgba(15,23,42,.24);
    }
    .product-master-modal-head,
    .product-master-modal-foot {
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:10px;
        padding:16px 18px;
        border-bottom:1px solid #e2e8f0;
    }
    .product-master-modal-foot {
        justify-content:flex-end;
        border-top:1px solid #e2e8f0;
        border-bottom:0;
    }
    .product-master-modal-body {
        display:grid;
        gap:12px;
        padding:16px 18px;
        min-height:0;
        overflow:auto;
    }
    .product-master-modal-body label {
        display:grid;
        gap:6px;
        color:#475569;
        font-size:12px !important;
        font-weight:800;
        text-transform:uppercase;
        letter-spacing:.03em;
    }
    .product-master-modal-body input,
    .product-master-modal-body select,
    .product-master-modal-body textarea {
        width:100%;
        min-height:42px !important;
        border:1px solid #cbd5e1;
        border-radius:14px !important;
        padding:10px 12px !important;
        color:#0f172a;
        font-size:14px;
        text-transform:none;
        letter-spacing:0;
        background:#fff;
    }
    .product-master-check {
        display:flex !important;
        align-items:center;
        gap:10px;
        text-transform:none !important;
        letter-spacing:0 !important;
        font-size:13px !important;
    }
    .product-master-check input {
        width:18px;
        height:18px;
        min-height:18px !important;
    }
    .product-master-error,
    .product-master-success {
        display:none;
        margin:0;
        padding:10px 12px;
        border-radius:12px;
        font-size:13px;
        font-weight:800;
    }
    .product-master-error {
        color:#991b1b;
        background:#fee2e2;
    }
    .product-master-success {
        color:#166534;
        background:#dcfce7;
    }
    .product-master-error.is-visible,
    .product-master-success.is-visible { display:block; }
    @media (max-width: 640px) {
        .product-master-modal {
            align-items:flex-end;
            padding:0;
        }
        .product-master-dialog {
            width:100%;
            height:min(88dvh, calc(100dvh - 72px));
            max-height:calc(100dvh - 72px);
            border-radius:24px 24px 0 0;
        }
        .product-master-modal-head,
        .product-master-modal-body,
        .product-master-modal-foot {
            padding:12px;
        }
        .product-master-modal-foot {
            display:grid;
            grid-template-columns:repeat(2, minmax(0, 1fr));
            gap:8px;
            padding-bottom:calc(12px + env(safe-area-inset-bottom, 0px));
            background:#fff;
        }
        .product-master-modal-foot button {
            width:100%;
            min-height:44px;
        }
    }
    .product-form-page label {
        margin-bottom:5px !important;
        font-size:12px !important;
        color:#475569;
    }
    .product-form-header {
        display:flex;
        justify-content:space-between;
        align-items:flex-start;
        gap:12px;
        margin-bottom:10px;
        flex-wrap:wrap;
        padding:10px 12px;
        border:1px solid #e2e8f0;
        border-radius:18px;
        background:#ffffff;
    }
    .product-form-header h1 {
        margin:8px 0 4px !important;
        font-size:28px !important;
        line-height:1.05;
    }
    .product-form-header p {
        font-size:13px !important;
        line-height:1.35;
    }
    .product-form-header > a {
        min-height:38px;
        padding:8px 11px !important;
        border-radius:10px !important;
        font-size:13px;
    }
    .product-form-grid {
        display:grid;
        grid-template-columns:minmax(0, 7fr) minmax(280px, 3fr);
        gap:14px;
        align-items:start;
    }
    .product-form-column {
        display:grid;
        gap:12px;
    }
    .product-image-field {
        display: grid;
        gap: 10px;
        align-content: start;
    }
    .product-image-control {
        display: flex;
        gap: 12px;
        align-items: center;
        flex-wrap: wrap;
        padding: 12px;
        border: 1px solid #dbe3ef;
        border-radius: 16px;
        background: #f8fafc;
    }
    .product-image-preview {
        width: 86px;
        height: 86px;
        border-radius: 16px;
        border: 1px solid #dbe3ef;
        background: linear-gradient(135deg, #eef2ff, #ffffff);
        color: #4f46e5;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        flex: 0 0 auto;
        font-size: 13px;
        font-weight: 900;
    }
    .product-image-preview img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }
    .product-image-actions {
        min-width: 180px;
        display: grid;
        gap: 8px;
        flex: 1 1 220px;
    }
    .product-image-actions input[type="file"] {
        min-height: 40px;
        padding: 8px !important;
        background: #ffffff;
    }
    .product-image-remove {
        display: inline-flex;
        gap: 8px;
        align-items: center;
        color: #475569;
        font-size: 12px;
        font-weight: 800;
    }
    .product-form-card {
        background:#ffffff;
        border:1px solid #e2e8f0;
        border-radius:14px;
        padding:12px;
        box-shadow:0 10px 24px rgba(15,23,42,.04);
    }
    .product-form-card h2 {
        margin:0;
        font-size:18px;
    }
    .product-form-copy {
        margin:4px 0 10px;
        color:#64748b;
        font-size:12px;
        line-height:1.4;
    }
    .product-form-card > div:first-child {
        margin-bottom:10px !important;
    }
    .product-form-two-col {
        display:grid;
        grid-template-columns:repeat(2, minmax(0, 1fr));
        gap:10px;
    }
    .product-form-actions {
        position:sticky;
        bottom:0;
        z-index:30;
        display:flex;
        justify-content:flex-end;
        gap:10px;
        padding:10px 12px;
        border:1px solid #e2e8f0;
        border-radius:16px;
        background:rgba(255,255,255,.96);
        box-shadow:0 -12px 30px rgba(15,23,42,.08);
    }
    .product-choice-grid {
        display:grid;
        grid-template-columns:repeat(3, minmax(0, 1fr));
        gap:8px;
    }
    .product-choice-grid.is-four {
        grid-template-columns:repeat(4, minmax(0, 1fr));
    }
    .product-choice {
        display:grid;
        gap:4px;
        min-height:60px;
        padding:8px;
        border:1px solid #dbe4f0;
        border-radius:12px;
        background:#f8fafc;
        color:#334155;
        cursor:pointer;
        transition:.16s ease;
    }
    .product-choice input {
        position:absolute;
        opacity:0;
        pointer-events:none;
    }
    .product-choice strong {
        color:#0f172a;
        font-size:12px;
    }
    .product-choice span {
        color:#64748b;
        font-size:10.5px;
        line-height:1.3;
    }
    .product-choice:has(input:checked) {
        border-color:#4f46e5;
        background:#eef2ff;
        box-shadow:0 0 0 3px rgba(79,70,229,.12);
    }
    .inventory-profile-grid {
        display:grid;
        grid-template-columns:repeat(2, minmax(0, 1fr));
        gap:12px;
    }
    .inventory-profile-card {
        position:relative;
        display:grid;
        gap:10px;
        padding:14px;
        border:1px solid #dbe4f0;
        border-radius:18px;
        background:#ffffff;
        cursor:pointer;
        transition:.16s ease;
    }
    .inventory-profile-card input {
        position:absolute;
        opacity:0;
        pointer-events:none;
    }
    .inventory-profile-card.is-selected,
    .inventory-profile-card:has(input:checked) {
        border-color:#4f46e5;
        background:#f5f7ff;
        box-shadow:0 0 0 3px rgba(79,70,229,.12);
    }
    .inventory-profile-top {
        display:flex;
        align-items:flex-start;
        gap:10px;
    }
    .inventory-profile-icon {
        display:inline-flex;
        width:36px;
        height:36px;
        align-items:center;
        justify-content:center;
        border-radius:12px;
        background:#eef2ff;
        color:#4f46e5;
        font-size:18px;
        flex:0 0 auto;
    }
    .inventory-profile-title {
        display:grid;
        gap:3px;
    }
    .inventory-profile-title strong {
        color:#0f172a;
        font-size:15px;
        line-height:1.2;
    }
    .inventory-profile-title span,
    .inventory-profile-examples {
        color:#64748b;
        font-size:12px;
        line-height:1.35;
    }
    .inventory-profile-checks {
        display:flex;
        flex-wrap:wrap;
        gap:6px;
    }
    .inventory-profile-checks span {
        display:inline-flex;
        align-items:center;
        gap:4px;
        padding:5px 8px;
        border-radius:999px;
        background:#ecfdf5;
        color:#047857;
        font-size:11px;
        font-weight:700;
    }
    .inventory-advanced-settings {
        border:1px dashed #cbd5e1;
        border-radius:16px;
        background:#f8fafc;
        overflow:hidden;
    }
    .inventory-advanced-settings summary {
        display:flex;
        justify-content:space-between;
        align-items:center;
        gap:10px;
        padding:12px 14px;
        color:#334155;
        font-weight:800;
        cursor:pointer;
        list-style:none;
    }
    .inventory-advanced-settings summary::-webkit-details-marker {
        display:none;
    }
    .inventory-advanced-settings summary::after {
        content:'âŒ„';
        color:#64748b;
        font-size:16px;
    }
    .inventory-advanced-settings[open] summary::after {
        transform:rotate(180deg);
    }
    .inventory-advanced-body {
        display:grid;
        gap:14px;
        padding:0 14px 14px;
    }
    .product-form-snapshot {
        position:sticky;
        top:90px;
        align-self:start;
    }
    .product-mobile-section-header,
    .product-mobile-savebar {
        display:none;
    }
    .product-summary-line {
        display:flex;
        justify-content:space-between;
        gap:10px;
        padding:8px 0;
        border-bottom:1px solid #e2e8f0;
        color:#64748b;
        font-size:12px;
    }
    .product-summary-line strong {
        color:#0f172a;
        text-align:right;
    }
    .product-validation-list {
        display:grid;
        gap:6px;
        margin-top:10px;
    }
    .product-validation-list span {
        font-size:12px;
        color:#64748b;
    }
    .product-validation-list span.is-ok { color:#15803d; }
    .product-validation-list span.is-warn { color:#b45309; }
    .is-hidden,
    .product-stock-message.is-hidden {
        display:none;
    }
    .product-stock-links {
        display:flex;
        gap:8px;
        flex-wrap:wrap;
        margin-top:10px;
    }
    .product-stock-links a {
        display:inline-flex;
        align-items:center;
        justify-content:center;
        min-height:36px;
        padding:8px 12px;
        border-radius:10px;
        border:1px solid #cbd5e1;
        background:#fff;
        color:#0f172a;
        font-size:12px;
        font-weight:700;
        text-decoration:none;
    }
    @media (max-width: 767px) {
        .product-form-page {
            max-width:100%;
            padding-bottom:104px;
        }
        .product-form-header {
            gap:10px;
            margin-bottom:8px;
            padding:8px 10px;
            border-radius:16px;
        }
        .product-form-header > div > div:first-child {
            padding:4px 8px !important;
            font-size:10px !important;
        }
        .product-form-header h1 {
            margin:4px 0 0 !important;
            font-size:22px !important;
        }
        .product-form-header p {
            display:none;
        }
        .product-form-header > a {
            min-height:34px;
            padding:7px 10px !important;
            font-size:12px;
        }
        .product-form-grid,
        .product-form-two-col {
            grid-template-columns:1fr !important;
            gap:10px !important;
        }
        .product-form-column {
            gap:10px;
        }
        .product-image-field {
        display: grid;
        gap: 10px;
        align-content: start;
    }
    .product-image-control {
        display: flex;
        gap: 12px;
        align-items: center;
        flex-wrap: wrap;
        padding: 12px;
        border: 1px solid #dbe3ef;
        border-radius: 16px;
        background: #f8fafc;
    }
    .product-image-preview {
        width: 86px;
        height: 86px;
        border-radius: 16px;
        border: 1px solid #dbe3ef;
        background: linear-gradient(135deg, #eef2ff, #ffffff);
        color: #4f46e5;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        flex: 0 0 auto;
        font-size: 13px;
        font-weight: 900;
    }
    .product-image-preview img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }
    .product-image-actions {
        min-width: 180px;
        display: grid;
        gap: 8px;
        flex: 1 1 220px;
    }
    .product-image-actions input[type="file"] {
        min-height: 40px;
        padding: 8px !important;
        background: #ffffff;
    }
    .product-image-remove {
        display: inline-flex;
        gap: 8px;
        align-items: center;
        color: #475569;
        font-size: 12px;
        font-weight: 800;
    }
    .product-form-card {
            border-radius:16px;
            padding:12px;
        }
        .product-mobile-section-header {
            width:100%;
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:10px;
            padding:0;
            border:0;
            background:transparent;
            text-align:left;
            color:#0f172a;
            cursor:pointer;
        }
        .product-mobile-section-title {
            display:flex;
            align-items:center;
            gap:8px;
            font-size:15px;
            font-weight:900;
        }
        .product-mobile-section-title span:first-child {
            display:inline-flex;
            width:28px;
            height:28px;
            align-items:center;
            justify-content:center;
            border-radius:10px;
            background:#f3e8ff;
            color:#4f46e5;
            font-size:14px;
        }
        .product-mobile-section-title small {
            display:block;
            margin-top:2px;
            color:#64748b;
            font-size:11px;
            font-weight:700;
        }
        .product-mobile-section-chevron {
            color:#64748b;
            font-size:16px;
            font-weight:900;
            transform:rotate(0deg);
            transition:transform .16s ease;
        }
        .product-mobile-section.is-open .product-mobile-section-chevron {
            transform:rotate(180deg);
        }
        .product-mobile-section:not(.is-open) > :not(.product-mobile-section-header) {
            display:none !important;
        }
        .product-mobile-desktop-heading {
            display:none !important;
        }
        .product-form-copy {
            margin:4px 0 8px;
            font-size:11px;
        }
        .product-form-card label {
            font-size:12px !important;
            margin-bottom:6px !important;
        }
        .product-form-card input,
        .product-form-card select,
        .product-form-card textarea {
            min-height:42px;
            padding:9px 11px !important;
            border-radius:12px !important;
            font-size:14px !important;
        }
        .product-choice-grid,
        .product-choice-grid.is-four {
            grid-template-columns:repeat(2, minmax(0, 1fr)) !important;
            gap:8px !important;
        }
        .product-choice-grid:not(.is-four) {
            grid-template-columns:repeat(3, minmax(0, 1fr)) !important;
        }
        .product-choice {
            min-height:42px;
            align-content:center;
            padding:9px 8px;
            text-align:center;
        }
        .product-choice strong {
            font-size:12px;
        }
        .product-choice span {
            display:none;
        }
        .inventory-profile-grid {
            grid-template-columns:1fr !important;
            gap:8px !important;
        }
        .inventory-profile-card {
            padding:10px !important;
            gap:8px !important;
            border-radius:14px !important;
        }
        .inventory-profile-icon {
            width:30px;
            height:30px;
            border-radius:10px;
            font-size:15px;
        }
        .inventory-profile-title strong {
            font-size:13px;
        }
        .inventory-profile-title span,
        .inventory-profile-examples {
            font-size:11px;
        }
        .inventory-profile-checks span {
            padding:4px 7px;
            font-size:10px;
        }
        .product-stock-message {
            padding:9px 10px !important;
            border-radius:12px !important;
            font-size:11px !important;
            line-height:1.3;
        }
        .product-form-actions {
            display:none !important;
        }
        .product-stock-links {
            display:grid;
            grid-template-columns:1fr;
        }
        .product-stock-links a {
            width:100%;
        }
        .product-form-snapshot {
            display:none;
        }
        .product-mobile-savebar {
            position:fixed;
            left:8px;
            right:8px;
            bottom:calc(74px + env(safe-area-inset-bottom));
            z-index:70;
            display:grid;
            grid-template-columns:minmax(0, 1fr) auto;
            align-items:center;
            gap:10px;
            padding:10px;
            border:1px solid #e2e8f0;
            border-radius:18px;
            background:rgba(255,255,255,.97);
            box-shadow:0 -14px 34px rgba(15,23,42,.14);
        }
        .product-mobile-savebar-meta {
            min-width:0;
            display:grid;
            gap:4px;
        }
        .product-mobile-savebar-mode {
            max-width:100%;
            color:#4f46e5;
            font-size:11px;
            font-weight:900;
            text-transform:uppercase;
            white-space:nowrap;
            overflow:hidden;
            text-overflow:ellipsis;
        }
        .product-mobile-savebar-prices {
            display:flex;
            gap:10px;
            color:#0f172a;
            font-size:12px;
            font-weight:900;
            white-space:nowrap;
            overflow:hidden;
        }
        .product-mobile-savebar-prices span {
            min-width:0;
            overflow:hidden;
            text-overflow:ellipsis;
        }
        .product-mobile-savebar button {
            min-height:44px;
            padding:0 16px;
            border:0;
            border-radius:14px;
            background:#4f46e5;
            color:#fff;
            font-size:13px;
            font-weight:900;
            box-shadow:0 10px 22px rgba(79,70,229,.28);
        }
    }
</style>

<div class="product-form-page">
    <div class="product-form-header">
        <div>
            <div style="display:inline-flex; padding:6px 10px; border-radius:999px; background:#ecfeff; color:#0f766e; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.08em;">Product Master</div>
            <h1 style="margin:12px 0 8px; font-size:32px; letter-spacing:-0.03em;">{{ $isEdit ? 'Edit Product' : 'Add Product' }}</h1>
            <p style="margin:0; color:#64748b; max-width:720px;">Set the master, choose the stock model, and save clean pricing.</p>
        </div>

        <a href="{{ route('products.index') }}" style="display:inline-flex; align-items:center; justify-content:center; padding:10px 14px; border-radius:12px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a; text-decoration:none; font-weight:600;">
            Back to Product Master
        </a>
    </div>

    @if ($errors->any())
        <div style="margin-bottom:18px; padding:14px 16px; border-radius:16px; border:1px solid #fecaca; background:#fff1f2; color:#991b1b;">
            <strong>Please fix the following:</strong>
            <ul style="margin:10px 0 0 18px;">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" enctype="multipart/form-data" action="{{ $isEdit ? route('products.update', $product) : route('products.store') }}" style="display:grid; gap:20px;">
        @csrf
        @if($isEdit)
            @method('PUT')
        @endif

        <div class="product-form-grid">
            <div class="product-form-column">
                <div class="product-form-card product-mobile-section is-open" data-product-mobile-section="basics">
                    <button type="button" class="product-mobile-section-header" data-product-mobile-toggle aria-expanded="true">
                        <span class="product-mobile-section-title">
                            <span>â–¡</span>
                            <span>Product Basics<small>Name, category, catalog IDs</small></span>
                        </span>
                        <span class="product-mobile-section-chevron">âŒ„</span>
                    </button>
                    <div class="product-mobile-desktop-heading" style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap; margin-bottom:18px;">
                        <div>
                            <h2 style="margin:0; font-size:22px;">Product Master Details</h2>
                            <p class="product-form-copy">Name and catalog identifiers.</p>
                        </div>
                    </div>

                    <div class="product-form-two-col">
                        <div style="grid-column:1 / -1;">
                            <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Product Name</label>
                            <input type="text" name="name" value="{{ old('name', $product->name) }}" required
                                   style="{{ $fieldStyle('name', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff;') }}">
                            @if($fieldError('name'))
                                <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('name') }}</div>
                            @endif
                        </div>

                        <div>
                            <div style="display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:8px;">
                                <label style="display:block; color:#475569; font-size:13px; font-weight:700;">Category</label>
                                @if($canManageProductMasters && \Illuminate\Support\Facades\Route::has('product-categories.quick-store'))
                                    <button type="button" data-master-modal-open="category" style="border:0; background:transparent; color:#4f46e5; font-size:12px; font-weight:800; cursor:pointer;">+ Add Category</button>
                                @endif
                            </div>
                            <select name="category_id" data-searchable-select data-search-placeholder="Search category"
                                    style="{{ $fieldStyle('category_id', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff;') }}">
                                <option value="">Select category</option>
                                @foreach($categoryOptions as $option)
                                    <option value="{{ $option->id }}" @selected((string) $categoryIdValue === (string) $option->id)>{{ $option->name }}</option>
                                @endforeach
                            </select>
                            @if($fieldError('category_id'))
                                <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('category_id') }}</div>
                            @endif
                        </div>

                        <div>
                            <div style="display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:8px;">
                                <label style="display:block; color:#475569; font-size:13px; font-weight:700;">Brand</label>
                                @if($canManageProductMasters && \Illuminate\Support\Facades\Route::has('product-brands.quick-store'))
                                    <button type="button" data-master-modal-open="brand" style="border:0; background:transparent; color:#4f46e5; font-size:12px; font-weight:800; cursor:pointer;">+ Add Brand</button>
                                @endif
                            </div>
                            <select name="brand_id" data-searchable-select data-search-placeholder="Search brand"
                                    style="{{ $fieldStyle('brand_id', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff;') }}">
                                <option value="">Select brand</option>
                                @foreach($brandOptions as $option)
                                    <option value="{{ $option->id }}" @selected((string) $brandIdValue === (string) $option->id)>{{ $option->name }}</option>
                                @endforeach
                            </select>
                            @if($fieldError('brand_id'))
                                <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('brand_id') }}</div>
                            @endif
                        </div>

                        <div style="grid-column:1 / -1;">
                            <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Model Name</label>
                            <input type="text" name="model_name" value="{{ old('model_name', $product->model_name) }}"
                                   style="{{ $fieldStyle('model_name', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff;') }}">
                            @if($fieldError('model_name'))
                                <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('model_name') }}</div>
                            @endif
                        </div>

                        <div class="product-image-field" style="grid-column:1 / -1;">
                            <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Product Image</label>
                            <div class="product-image-control">
                                <div class="product-image-preview" data-product-image-preview aria-hidden="true">
                                    @if($product->product_image_url)
                                        <img src="{{ $product->product_image_url }}" alt="" data-product-image-preview-img>
                                    @else
                                        <span data-product-image-placeholder>Image</span>
                                        <img src="" alt="" data-product-image-preview-img style="display:none;">
                                    @endif
                                </div>
                                <div class="product-image-actions">
                                    <input type="file" name="product_image" accept="image/jpeg,image/png,image/webp" data-product-image-input
                                           style="{{ $fieldStyle('product_image', 'width:100%; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff;') }}">
                                    <div style="color:#64748b; font-size:12px; font-weight:700;">JPG, PNG, or WebP. Max 2MB.</div>
                                    @if($isEdit && $product->product_image_path)
                                        <label class="product-image-remove">
                                            <input type="checkbox" name="remove_product_image" value="1" data-product-image-remove @checked(old('remove_product_image'))>
                                            Remove current image
                                        </label>
                                    @endif
                                    @if($fieldError('product_image'))
                                        <div style="color:#b91c1c; font-size:12px;">{{ $fieldError('product_image') }}</div>
                                    @endif
                                </div>
                            </div>
                        </div>
                        <div>
                            <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Product Code</label>
                            <input type="text" name="product_code" value="{{ old('product_code', $product->product_code) }}"
                                   style="{{ $fieldStyle('product_code', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff;') }}">
                            @if($fieldError('product_code'))
                                <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('product_code') }}</div>
                            @endif
                        </div>

                        <div>
                            <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">SKU</label>
                            <input type="text" name="sku" value="{{ old('sku', $product->sku) }}"
                                   style="{{ $fieldStyle('sku', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff;') }}">
                            @if($fieldError('sku'))
                                <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('sku') }}</div>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="product-form-card product-mobile-section" data-product-mobile-section="inventory">
                    <button type="button" class="product-mobile-section-header" data-product-mobile-toggle aria-expanded="false">
                        <span class="product-mobile-section-title">
                            <span>â–£</span>
                            <span>Inventory Configuration<small>Type, stock mode, quantity</small></span>
                        </span>
                        <span class="product-mobile-section-chevron">âŒ„</span>
                    </button>
                    <div class="product-mobile-desktop-heading" style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap;">
                        <div>
                            <h2 style="margin:0; font-size:22px;">Inventory Configuration</h2>
                            <p class="product-form-copy">Choose the business category. PHOS will map the technical inventory setup automatically.</p>
                        </div>
                        <span style="display:inline-flex; padding:7px 12px; border-radius:999px; background:{{ $usesManagedStock ? '#eff6ff' : '#f8fafc' }}; color:{{ $usesManagedStock ? '#1d4ed8' : '#475569' }}; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.08em;">
                            {{ $inventoryProfile === 'equipment' ? 'Reusable Equipment' : ($inventoryProfile === 'consumable' ? 'Consumable Product' : $stockModeLabel) }}
                        </span>
                    </div>

                    <div style="display:grid; gap:18px;">
                        <div>
                            <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">What are you creating?</label>
                            <div class="inventory-profile-grid">
                                <label class="inventory-profile-card {{ $inventoryProfile === 'equipment' ? 'is-selected' : '' }}" data-inventory-profile-card>
                                    <input type="radio"
                                           name="inventory_profile"
                                           value="equipment"
                                           data-product-type-value="{{ \App\Models\Product::TYPE_BOTH }}"
                                           data-stock-mode-value="{{ \App\Models\Product::STOCK_MODE_TRACKED_BOTH }}"
                                           @checked($inventoryProfile === 'equipment')>
                                    <span class="inventory-profile-top">
                                        <span class="inventory-profile-icon">+</span>
                                        <span class="inventory-profile-title">
                                            <strong>Reusable Equipment</strong>
                                            <span>Can be rented and sold.</span>
                                        </span>
                                    </span>
                                    <span class="inventory-profile-examples">Beds, concentrators, CPAP, BiPAP, wheelchairs.</span>
                                    <span class="inventory-profile-checks">
                                        <span>âœ“ Rental</span>
                                        <span>âœ“ Sale</span>
                                        <span>âœ“ Asset tracking</span>
                                        <span>âœ“ Serial numbers</span>
                                    </span>
                                </label>
                                <label class="inventory-profile-card {{ $inventoryProfile === 'consumable' ? 'is-selected' : '' }}" data-inventory-profile-card>
                                    <input type="radio"
                                           name="inventory_profile"
                                           value="consumable"
                                           data-product-type-value="{{ \App\Models\Product::TYPE_SELLABLE }}"
                                           data-stock-mode-value="{{ \App\Models\Product::STOCK_MODE_UNTRACKED }}"
                                           @checked($inventoryProfile === 'consumable')>
                                    <span class="inventory-profile-top">
                                        <span class="inventory-profile-icon">#</span>
                                        <span class="inventory-profile-title">
                                            <strong>Consumable Product</strong>
                                            <span>Sale only with quantity stock.</span>
                                        </span>
                                    </span>
                                    <span class="inventory-profile-examples">Masks, filters, tubing, accessories, diapers.</span>
                                    <span class="inventory-profile-checks">
                                        <span>âœ“ Sale only</span>
                                        <span>âœ“ Quantity stock</span>
                                        <span>âœ“ No asset tracking</span>
                                    </span>
                                </label>
                            </div>
                        </div>

                        <details class="inventory-advanced-settings">
                            <summary>Advanced Settings</summary>
                            <div class="inventory-advanced-body">
                                <div>
                                    <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Product Type</label>
                                    <div class="product-choice-grid">
                                        <label class="product-choice">
                                            <input type="radio" name="product_type" id="product_type" value="{{ \App\Models\Product::TYPE_SELLABLE }}" @checked($productType === \App\Models\Product::TYPE_SELLABLE)>
                                            <strong>Sellable</strong>
                                            <span>Sale units and customer invoices.</span>
                                        </label>
                                        <label class="product-choice">
                                            <input type="radio" name="product_type" value="{{ \App\Models\Product::TYPE_RENTABLE }}" @checked($productType === \App\Models\Product::TYPE_RENTABLE)>
                                            <strong>Rentable</strong>
                                            <span>Rental assets and dispatch flow.</span>
                                        </label>
                                        <label class="product-choice">
                                            <input type="radio" name="product_type" value="{{ \App\Models\Product::TYPE_BOTH }}" @checked($productType === \App\Models\Product::TYPE_BOTH)>
                                            <strong>Both</strong>
                                            <span>Sell and rent the same catalog item.</span>
                                        </label>
                                    </div>
                                    @if($fieldError('product_type'))
                                        <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('product_type') }}</div>
                                    @endif
                                </div>

                                <div>
                                    <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Stock Mode</label>
                                    <div class="product-choice-grid is-four">
                                        <label class="product-choice">
                                            <input type="radio" name="stock_mode" value="{{ \App\Models\Product::STOCK_MODE_UNTRACKED }}" @checked($stockMode === \App\Models\Product::STOCK_MODE_UNTRACKED)>
                                            <strong>Untracked</strong>
                                            <span>Only quantity tracked.</span>
                                        </label>
                                        <label class="product-choice">
                                            <input type="radio" name="stock_mode" value="{{ \App\Models\Product::STOCK_MODE_TRACKED_SALE }}" @checked($stockMode === \App\Models\Product::STOCK_MODE_TRACKED_SALE)>
                                            <strong>Tracked Sale</strong>
                                            <span>Individual sale units tracked.</span>
                                        </label>
                                        <label class="product-choice">
                                            <input type="radio" name="stock_mode" value="{{ \App\Models\Product::STOCK_MODE_TRACKED_RENTAL }}" @checked($stockMode === \App\Models\Product::STOCK_MODE_TRACKED_RENTAL)>
                                            <strong>Tracked Rental</strong>
                                            <span>Individual rental assets tracked.</span>
                                        </label>
                                        <label class="product-choice">
                                            <input type="radio" name="stock_mode" value="{{ \App\Models\Product::STOCK_MODE_TRACKED_BOTH }}" @checked($stockMode === \App\Models\Product::STOCK_MODE_TRACKED_BOTH)>
                                            <strong>Tracked Both</strong>
                                            <span>Sale units and rental assets.</span>
                                        </label>
                                    </div>
                                    @if($fieldError('stock_mode'))
                                        <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('stock_mode') }}</div>
                                    @endif
                                </div>
                            </div>
                        </details>

                        <div>
                            <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Quantity</label>
                            <input type="number" name="quantity" min="0" value="{{ $quantityValue }}"
                                   @if($usesManagedStock) readonly @endif
                                   style="{{ $fieldStyle('quantity', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff;') }} {{ $usesManagedStock ? 'color:#64748b; background:#f8fafc;' : '' }}">
                            @if($fieldError('quantity'))
                                <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('quantity') }}</div>
                            @endif
                            <div style="margin-top:6px; color:#64748b; font-size:12px;">
                                @if($usesManagedStock)
                                    Quantity is summary-only because this product uses tracked stock.
                                @else
                                    Set the opening quantity here until tracked sale units or rental assets are added.
                                @endif
                            </div>
                            @if($usesManagedStock && $isEdit)
                                <div class="product-stock-links">
                                    <a href="{{ route('products.show', $product) }}">Add Stock</a>
                                    <a href="{{ route('products.show', $product) }}#conversion-history">Convert Stock</a>
                                    @if($canUpdateProducts)
                                        <a href="{{ route('products.show', $product) }}#conversion-history">Adjust Stock (Admin Only)</a>
                                    @endif
                                </div>
                            @endif
                        </div>

                        <div class="product-stock-message {{ !in_array($stockMode, [\App\Models\Product::STOCK_MODE_TRACKED_SALE, \App\Models\Product::STOCK_MODE_TRACKED_BOTH], true) ? 'is-hidden' : '' }}" data-stock-message="tracked-sale" style="padding:14px 16px; border-radius:16px; background:#fff7ed; border:1px solid #fed7aa; color:#9a3412;">
                            Sale stock is summarized from serialized new-stock units.
                        </div>
                        <div class="product-stock-message {{ !in_array($stockMode, [\App\Models\Product::STOCK_MODE_TRACKED_RENTAL, \App\Models\Product::STOCK_MODE_TRACKED_BOTH], true) ? 'is-hidden' : '' }}" data-stock-message="tracked-rental" style="padding:14px 16px; border-radius:16px; background:#eff6ff; border:1px solid #bfdbfe; color:#1d4ed8;">
                            Rental stock is summarized from tracked rental assets.
                        </div>
                        <div class="product-stock-message {{ $stockMode !== \App\Models\Product::STOCK_MODE_UNTRACKED ? 'is-hidden' : '' }}" data-stock-message="untracked" style="padding:14px 16px; border-radius:16px; background:#f8fafc; border:1px solid #e2e8f0; color:#475569;">
                            Untracked products use the manual quantity fields until tracked stock is introduced.
                        </div>
                    </div>
                </div>
            </div>

            <div class="product-form-column">
                <div class="product-form-card product-mobile-section" data-product-mobile-section="pricing">
                    <button type="button" class="product-mobile-section-header" data-product-mobile-toggle aria-expanded="false">
                        <span class="product-mobile-section-title">
                            <span>â‚¹</span>
                            <span>Pricing &amp; Tax<small>Sale, rental, GST setup</small></span>
                        </span>
                        <span class="product-mobile-section-chevron">âŒ„</span>
                    </button>
                    <div class="product-mobile-desktop-heading">
                        <h2 style="margin:0;">Pricing</h2>
                        <p class="product-form-copy">Keep pricing short and clear.</p>
                    </div>

                    <div style="display:grid; gap:18px;">
                        <div data-pricing-section="sale">
                            <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Sale Price</label>
                            <input type="number" min="0" step="0.01" name="sale_price" value="{{ $salePriceValue }}"
                                   style="{{ $fieldStyle('sale_price', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff;') }}">
                            @if($fieldError('sale_price'))
                                <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('sale_price') }}</div>
                            @endif
                        </div>

                        <div style="display:grid; gap:14px;" data-pricing-section="rental">
                            <div>
                                <div style="font-size:11px; color:#1d4ed8; font-weight:800; text-transform:uppercase; letter-spacing:0.08em;">Rental Pricing</div>
                                <div style="margin-top:6px; color:#64748b; font-size:13px;">Daily and package pricing.</div>
                            </div>

                            <div class="product-form-two-col">
                                <div>
                                    <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Price Per Day</label>
                                    <input type="number" min="0" step="0.01" name="price_per_day" id="price_per_day" value="{{ $pricePerDayValue }}"
                                           style="{{ $fieldStyle('price_per_day', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff;') }}">
                                    @if($fieldError('price_per_day'))
                                        <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('price_per_day') }}</div>
                                    @endif
                                </div>

                                <div>
                                    <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">15 Days Package</label>
                                    <input type="number" min="0" step="0.01" name="rental_price_15_days" value="{{ $rental15DayValue }}"
                                           style="{{ $fieldStyle('rental_price_15_days', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff;') }}">
                                    @if($fieldError('rental_price_15_days'))
                                        <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('rental_price_15_days') }}</div>
                                    @endif
                                </div>

                                <div>
                                    <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">30 Days Package</label>
                                    <input type="number" min="0" step="0.01" name="rental_price_30_days" value="{{ $rental30DayValue }}"
                                           style="{{ $fieldStyle('rental_price_30_days', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff;') }}">
                                    @if($fieldError('rental_price_30_days'))
                                        <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('rental_price_30_days') }}</div>
                                    @endif
                                    <div style="margin-top:6px; color:#64748b; font-size:12px;">Default rental quote.</div>
                                </div>

                                <div>
                                    <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">3 Months Package</label>
                                    <input type="number" min="0" step="0.01" name="rental_price_3_months" value="{{ $rental3MonthValue }}"
                                           style="{{ $fieldStyle('rental_price_3_months', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff;') }}">
                                    @if($fieldError('rental_price_3_months'))
                                        <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('rental_price_3_months') }}</div>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <div style="display:grid; gap:14px; margin-top:4px;">
                            <div>
                                <div style="font-size:11px; color:#0f766e; font-weight:800; text-transform:uppercase; letter-spacing:0.08em;">GST Setup</div>
                                <div style="margin-top:6px; color:#64748b; font-size:13px;">Save the product-level GST structure so it can be reviewed and modified later.</div>
                            </div>

                            <div class="product-form-two-col">
                                <div>
                                    <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">GST Type</label>
                                    <select name="gst_tax_type" id="gst_tax_type"
                                           style="{{ $fieldStyle('gst_tax_type', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff;') }}">
                                        <option value="">Not set</option>
                                        <option value="{{ \App\Models\Product::GST_TAX_TYPE_BOTH }}" @selected($gstTaxTypeValue === \App\Models\Product::GST_TAX_TYPE_BOTH)>Both: CGST + SGST and IGST</option>
                                        <option value="{{ \App\Models\Product::GST_TAX_TYPE_CGST_SGST }}" @selected($gstTaxTypeValue === \App\Models\Product::GST_TAX_TYPE_CGST_SGST)>CGST + SGST</option>
                                        <option value="{{ \App\Models\Product::GST_TAX_TYPE_IGST }}" @selected($gstTaxTypeValue === \App\Models\Product::GST_TAX_TYPE_IGST)>IGST</option>
                                    </select>
                                    @if($fieldError('gst_tax_type'))
                                        <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('gst_tax_type') }}</div>
                                    @endif
                                </div>

                                <div>
                                    <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">GST Mode</label>
                                    <select name="gst_calculation_mode" id="gst_calculation_mode"
                                           style="{{ $fieldStyle('gst_calculation_mode', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff;') }}">
                                        <option value="exclusive" @selected($gstCalculationModeValue === 'exclusive')>Exclusive</option>
                                        <option value="inclusive" @selected($gstCalculationModeValue === 'inclusive')>Inclusive</option>
                                    </select>
                                    @if($fieldError('gst_calculation_mode'))
                                        <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('gst_calculation_mode') }}</div>
                                    @endif
                                </div>
                            </div>

                            <div id="gstSplitRates" class="product-form-two-col">
                                <div>
                                    <label for="gst_split_total_rate" style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">CGST + SGST %</label>
                                    <input type="hidden" name="cgst_rate" id="cgst_rate" value="{{ number_format((float) $cgstRateValue, 2, '.', '') }}">
                                    <input type="hidden" name="sgst_rate" id="sgst_rate" value="{{ number_format((float) $sgstRateValue, 2, '.', '') }}">
                                    <select id="gst_split_total_rate"
                                            style="{{ $fieldStyle('cgst_rate', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff;') }}">
                                        @foreach($gstDropdownOptions($splitTotalGstRateValue) as $rateOption)
                                            <option value="{{ $rateOption }}" @selected(number_format((float) $splitTotalGstRateValue, 2, '.', '') === $rateOption)>
                                                {{ rtrim(rtrim($rateOption, '0'), '.') }}%
                                            </option>
                                        @endforeach
                                    </select>
                                    @if($fieldError('cgst_rate'))
                                        <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('cgst_rate') }}</div>
                                    @endif
                                    @if($fieldError('sgst_rate'))
                                        <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('sgst_rate') }}</div>
                                    @endif
                                    <div style="margin-top:6px; color:#64748b; font-size:12px;">Split evenly into CGST and SGST for same-state billing.</div>
                                </div>

                                <div style="display:grid; align-content:start; gap:8px;">
                                    <div style="padding:8px 10px; border-radius:12px; background:#f8fafc; border:1px solid #e2e8f0; color:#475569; font-size:12px; line-height:1.35;">
                                        <strong style="color:#0f172a;">CGST + SGST split</strong><br>
                                        <span id="gstSplitPreview">CGST {{ number_format((float) $cgstRateValue, 2) }}% + SGST {{ number_format((float) $sgstRateValue, 2) }}%</span>
                                    </div>
                                    <div style="color:#64748b; font-size:12px;">Legacy non-standard GST values stay available as selected dropdown options.</div>
                                </div>
                            </div>

                            <div id="gstIgstRateWrap">
                                <label for="gst_igst_total_rate" style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">IGST %</label>
                                <input type="hidden" name="igst_rate" id="igst_rate" value="{{ number_format((float) $igstRateValue, 2, '.', '') }}">
                                <select id="gst_igst_total_rate"
                                       style="{{ $fieldStyle('igst_rate', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff;') }}">
                                    @foreach($gstDropdownOptions($igstTotalGstRateValue) as $rateOption)
                                        <option value="{{ $rateOption }}" @selected(number_format((float) $igstTotalGstRateValue, 2, '.', '') === $rateOption)>
                                            {{ rtrim(rtrim($rateOption, '0'), '.') }}%
                                        </option>
                                    @endforeach
                                </select>
                                @if($fieldError('igst_rate'))
                                    <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('igst_rate') }}</div>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>

                <div class="product-form-card product-form-snapshot">
                    <h2 style="margin:0;">Quick Summary</h2>
                    <p class="product-form-copy">Live setup check before saving.</p>

                    <div style="font-size:20px; font-weight:800; color:#0f172a; line-height:1.2;" data-summary="name">
                        {{ old('name', $product->name) ?: 'Product name' }}
                    </div>
                    <div style="margin-top:4px; color:#64748b; font-size:12px;" data-summary="category">
                        {{ old('category', $product->category) ?: 'No category yet' }}
                    </div>

                    <div style="margin-top:12px;">
                        <div class="product-summary-line"><span>Type</span><strong data-summary="type">{{ ucfirst(str_replace('_', ' ', $productType)) }}</strong></div>
                        <div class="product-summary-line"><span>Stock</span><strong data-summary="stock">{{ $stockModeLabel }}</strong></div>
                        <div class="product-summary-line"><span>Sale</span><strong data-summary="sale">{{ $salePriceValue !== null && $salePriceValue !== '' ? $rupee . ' ' . number_format((float) $salePriceValue, 2) : 'Not set' }}</strong></div>
                        <div class="product-summary-line"><span>Daily</span><strong data-summary="daily">{{ $pricePerDayValue !== null && $pricePerDayValue !== '' ? $rupee . ' ' . number_format((float) $pricePerDayValue, 2) : 'Not set' }}</strong></div>
                        <div class="product-summary-line"><span>15 Day</span><strong data-summary="rental15">{{ $rental15DayValue !== null && $rental15DayValue !== '' ? $rupee . ' ' . number_format((float) $rental15DayValue, 2) : 'Not set' }}</strong></div>
                        <div class="product-summary-line"><span>30 Day</span><strong data-summary="rental30">{{ $rental30DayValue !== null && $rental30DayValue !== '' ? $rupee . ' ' . number_format((float) $rental30DayValue, 2) : 'Not set' }}</strong></div>
                        <div class="product-summary-line"><span>3 Month</span><strong data-summary="rental3">{{ $rental3MonthValue !== null && $rental3MonthValue !== '' ? $rupee . ' ' . number_format((float) $rental3MonthValue, 2) : 'Not set' }}</strong></div>
                        <div class="product-summary-line"><span>GST</span><strong data-summary="gst">Not set</strong></div>
                    </div>

                    <div class="product-validation-list">
                        <span data-validation="name">Product name required</span>
                        <span data-validation="pricing">Pricing not configured</span>
                        <span data-validation="gst">GST not selected</span>
                    </div>

                    @if($isEdit)
                        <div style="display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:8px; margin-top:12px;">
                            <div style="padding:8px; border-radius:12px; background:#f8fafc; color:#475569; font-size:12px;"><strong style="display:block; color:#0f172a;">{{ number_format($managedRentalAssetsCount) }}</strong>Rental assets</div>
                            <div style="padding:8px; border-radius:12px; background:#f8fafc; color:#475569; font-size:12px;"><strong style="display:block; color:#0f172a;">{{ number_format($managedSaleUnitsCount) }}</strong>Sale units</div>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="product-form-actions">
            <a href="{{ route('products.index') }}" style="display:inline-flex; align-items:center; justify-content:center; padding:11px 16px; border-radius:12px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a; text-decoration:none; font-weight:600;">
                Cancel
            </a>
            <button type="submit" style="display:inline-flex; align-items:center; justify-content:center; padding:11px 18px; border:none; border-radius:12px; background:#0f766e; color:#ffffff; font-weight:700; cursor:pointer;">
                {{ $isEdit ? 'Update Product' : 'Save Product' }}
            </button>
        </div>

        <div class="product-mobile-savebar" aria-label="Mobile product save bar">
            <div class="product-mobile-savebar-meta">
                <div class="product-mobile-savebar-mode" data-mobile-summary="mode">{{ $inventoryProfile === 'equipment' ? 'Reusable Equipment' : ($inventoryProfile === 'consumable' ? 'Consumable Product' : $stockModeLabel) }}</div>
                <div class="product-mobile-savebar-prices">
                    <span data-mobile-summary="sale">{{ $salePriceValue !== null && $salePriceValue !== '' ? $rupee . ' ' . number_format((float) $salePriceValue, 2) . ' Sale' : 'Sale not set' }}</span>
                    <span data-mobile-summary="daily">{{ $pricePerDayValue !== null && $pricePerDayValue !== '' ? $rupee . ' ' . number_format((float) $pricePerDayValue, 2) . '/day' : 'Rent not set' }}</span>
                </div>
            </div>
            <button type="submit">{{ $isEdit ? 'Update' : 'Save Product' }}</button>
        </div>
    </form>

    @if($canManageProductMasters)
        <div class="product-master-modal" data-master-modal="category" aria-hidden="true">
            <div class="product-master-dialog" role="dialog" aria-modal="true" aria-labelledby="categoryMasterTitle">
                <div class="product-master-modal-head">
                    <div>
                        <h3 id="categoryMasterTitle" style="margin:0; font-size:18px;">Add Category</h3>
                        <p style="margin:4px 0 0; color:#64748b; font-size:13px;">Create a reusable product category.</p>
                    </div>
                    <button type="button" data-master-modal-close style="border:1px solid #cbd5e1; background:#fff; border-radius:12px; padding:8px 12px; font-weight:800;">Cancel</button>
                </div>
                <div class="product-master-modal-body">
                    <p class="product-master-error" data-master-error></p>
                    <p class="product-master-success" data-master-success></p>
                    <label>
                        Category Name *
                        <input type="text" data-master-field="name" autocomplete="off" placeholder="Respiratory Care">
                    </label>
                    <label>
                        Parent Category
                        <select data-master-field="parent_id">
                            <option value="">No parent</option>
                            @foreach($categoryOptions as $option)
                                <option value="{{ $option->id }}">{{ $option->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>
                        Description
                        <textarea data-master-field="description" rows="3" placeholder="Optional category notes"></textarea>
                    </label>
                    <label class="product-master-check">
                        <input type="checkbox" data-master-field="is_active" checked value="1">
                        Active
                    </label>
                </div>
                <div class="product-master-modal-foot">
                    <button type="button" data-master-modal-close style="border:1px solid #cbd5e1; background:#fff; border-radius:12px; padding:10px 14px; font-weight:800;">Cancel</button>
                    <button type="button" data-master-save="category" data-url="{{ route('product-categories.quick-store') }}" style="border:0; background:#4f46e5; color:#fff; border-radius:12px; padding:10px 16px; font-weight:900;">Save Category</button>
                </div>
            </div>
        </div>

        <div class="product-master-modal" data-master-modal="brand" aria-hidden="true">
            <div class="product-master-dialog" role="dialog" aria-modal="true" aria-labelledby="brandMasterTitle">
                <div class="product-master-modal-head">
                    <div>
                        <h3 id="brandMasterTitle" style="margin:0; font-size:18px;">Add Brand</h3>
                        <p style="margin:4px 0 0; color:#64748b; font-size:13px;">Create a reusable product brand.</p>
                    </div>
                    <button type="button" data-master-modal-close style="border:1px solid #cbd5e1; background:#fff; border-radius:12px; padding:8px 12px; font-weight:800;">Cancel</button>
                </div>
                <div class="product-master-modal-body">
                    <p class="product-master-error" data-master-error></p>
                    <p class="product-master-success" data-master-success></p>
                    <label>
                        Brand Name *
                        <input type="text" data-master-field="name" autocomplete="off" placeholder="Philips">
                    </label>
                    <label>
                        Manufacturer
                        <input type="text" data-master-field="manufacturer" autocomplete="off" placeholder="Optional manufacturer">
                    </label>
                    <label>
                        Description
                        <textarea data-master-field="description" rows="3" placeholder="Optional brand notes"></textarea>
                    </label>
                    <label class="product-master-check">
                        <input type="checkbox" data-master-field="is_active" checked value="1">
                        Active
                    </label>
                </div>
                <div class="product-master-modal-foot">
                    <button type="button" data-master-modal-close style="border:1px solid #cbd5e1; background:#fff; border-radius:12px; padding:10px 14px; font-weight:800;">Cancel</button>
                    <button type="button" data-master-save="brand" data-url="{{ route('product-brands.quick-store') }}" style="border:0; background:#4f46e5; color:#fff; border-radius:12px; padding:10px 16px; font-weight:900;">Save Brand</button>
                </div>
            </div>
        </div>
    @endif
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const productTypeInputs = Array.from(document.querySelectorAll('input[name="product_type"]'));
    const productImageInput = document.querySelector('[data-product-image-input]');
    const productImageRemove = document.querySelector('[data-product-image-remove]');
    const productImagePreviewImg = document.querySelector('[data-product-image-preview-img]');
    const productImagePlaceholder = document.querySelector('[data-product-image-placeholder]');

    const syncProductImagePreview = function () {
        const file = productImageInput && productImageInput.files && productImageInput.files[0] ? productImageInput.files[0] : null;

        if (!file || !productImagePreviewImg) {
            return;
        }

        productImagePreviewImg.src = URL.createObjectURL(file);
        productImagePreviewImg.style.display = 'block';

        if (productImagePlaceholder) {
            productImagePlaceholder.style.display = 'none';
        }

        if (productImageRemove) {
            productImageRemove.checked = false;
        }
    };

    productImageInput?.addEventListener('change', syncProductImagePreview);
    productImageRemove?.addEventListener('change', function () {
        if (!this.checked || !productImagePreviewImg) {
            return;
        }

        if (productImageInput) {
            productImageInput.value = '';
        }

        productImagePreviewImg.removeAttribute('src');
        productImagePreviewImg.style.display = 'none';

        if (productImagePlaceholder) {
            productImagePlaceholder.style.display = 'inline';
        }
    });

    const stockModeInputs = Array.from(document.querySelectorAll('input[name="stock_mode"]'));
    const inventoryProfileInputs = Array.from(document.querySelectorAll('input[name="inventory_profile"]'));
    const inventoryProfileCards = Array.from(document.querySelectorAll('[data-inventory-profile-card]'));
    const stockMessages = Array.from(document.querySelectorAll('.product-stock-message'));
    const pricePerDay = document.querySelector('[name="price_per_day"]');
    const salePrice = document.querySelector('[name="sale_price"]');
    const rental15 = document.querySelector('[name="rental_price_15_days"]');
    const rental30 = document.querySelector('[name="rental_price_30_days"]');
    const rental3 = document.querySelector('[name="rental_price_3_months"]');
    const nameInput = document.querySelector('[name="name"]');
    const categoryInput = document.querySelector('[name="category_id"]');
    const brandInput = document.querySelector('[name="brand_id"]');
    const saleSection = document.querySelector('[data-pricing-section="sale"]');
    const rentalSection = document.querySelector('[data-pricing-section="rental"]');
    const gstTaxType = document.getElementById('gst_tax_type');
    const gstMode = document.getElementById('gst_calculation_mode');
    const gstSplitRates = document.getElementById('gstSplitRates');
    const gstIgstRateWrap = document.getElementById('gstIgstRateWrap');
    const cgstRate = document.getElementById('cgst_rate');
    const sgstRate = document.getElementById('sgst_rate');
    const igstRate = document.getElementById('igst_rate');
    const gstSplitTotalRate = document.getElementById('gst_split_total_rate');
    const gstIgstTotalRate = document.getElementById('gst_igst_total_rate');
    const gstSplitPreview = document.getElementById('gstSplitPreview');
    const summary = (key) => document.querySelector(`[data-summary="${key}"]`);
    const mobileSummary = (key) => document.querySelector(`[data-mobile-summary="${key}"]`);
    const validation = (key) => document.querySelector(`[data-validation="${key}"]`);
    const rupee = @json($rupee);

    if (!productTypeInputs.length) {
        return;
    }

    const selectedValue = (name, fallback = '') => document.querySelector(`[name="${name}"]:checked`)?.value || fallback;
    const money = (input) => {
        const value = parseFloat((input && input.value) || '');
        return Number.isFinite(value) && value > 0 ? `${rupee} ${value.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}` : 'Not set';
    };
    const labelize = (value) => (value || 'not set').replace(/_/g, ' ').replace(/\b\w/g, (char) => char.toUpperCase());
    const inventoryProfileFor = (type, stockMode) => {
        if (type === '{{ \App\Models\Product::TYPE_BOTH }}' && stockMode === '{{ \App\Models\Product::STOCK_MODE_TRACKED_BOTH }}') {
            return 'equipment';
        }

        if (type === '{{ \App\Models\Product::TYPE_SELLABLE }}' && stockMode === '{{ \App\Models\Product::STOCK_MODE_UNTRACKED }}') {
            return 'consumable';
        }

        return 'advanced';
    };
    const inventoryProfileLabel = (type, stockMode) => {
        const profile = inventoryProfileFor(type, stockMode);

        if (profile === 'equipment') {
            return 'Reusable Equipment';
        }

        if (profile === 'consumable') {
            return 'Consumable Product';
        }

        return labelize(stockMode);
    };
    const setRadioValue = (name, value) => {
        const input = document.querySelector(`input[name="${name}"][value="${value}"]`);

        if (!input) {
            return;
        }

        input.checked = true;
    };
    const syncInventoryProfileCards = (type, stockMode) => {
        const profile = inventoryProfileFor(type, stockMode);

        inventoryProfileInputs.forEach(function (input) {
            input.checked = input.value === profile;
        });

        inventoryProfileCards.forEach(function (card) {
            const input = card.querySelector('input[name="inventory_profile"]');
            card.classList.toggle('is-selected', Boolean(input && input.checked));
        });
    };

    const syncMode = function () {
        const type = selectedValue('product_type', '{{ $productType }}');
        const stockMode = selectedValue('stock_mode', '{{ $stockMode }}');
        const isSellable = ['{{ \App\Models\Product::TYPE_SELLABLE }}', '{{ \App\Models\Product::TYPE_BOTH }}'].includes(type);
        const isRentable = ['{{ \App\Models\Product::TYPE_RENTABLE }}', '{{ \App\Models\Product::TYPE_BOTH }}'].includes(type);

        syncInventoryProfileCards(type, stockMode);

        if (saleSection) {
            saleSection.classList.toggle('is-hidden', !isSellable);
        }

        if (rentalSection) {
            rentalSection.classList.toggle('is-hidden', !isRentable);
        }

        stockMessages.forEach(function (message) {
            const key = message.dataset.stockMessage;
            const visible = (key === 'untracked' && stockMode === '{{ \App\Models\Product::STOCK_MODE_UNTRACKED }}')
                || (key === 'tracked-sale' && ['{{ \App\Models\Product::STOCK_MODE_TRACKED_SALE }}', '{{ \App\Models\Product::STOCK_MODE_TRACKED_BOTH }}'].includes(stockMode))
                || (key === 'tracked-rental' && ['{{ \App\Models\Product::STOCK_MODE_TRACKED_RENTAL }}', '{{ \App\Models\Product::STOCK_MODE_TRACKED_BOTH }}'].includes(stockMode));
            message.classList.toggle('is-hidden', !visible);
        });

        if (pricePerDay) {
            pricePerDay.required = isRentable;
        }

        syncSummary();
    };

    const syncGstMode = function () {
        if (gstSplitRates) {
            gstSplitRates.classList.remove('is-hidden');
        }

        if (gstIgstRateWrap) {
            gstIgstRateWrap.classList.remove('is-hidden');
        }
    };

    const syncProductGstRates = function () {
        const type = gstTaxType ? (gstTaxType.value || '') : '';
        const splitTotal = parseFloat((gstSplitTotalRate && gstSplitTotalRate.value) || '0') || 0;
        const igstTotal = parseFloat((gstIgstTotalRate && gstIgstTotalRate.value) || '0') || 0;

        if (type === '{{ \App\Models\Product::GST_TAX_TYPE_CGST_SGST }}' || type === '{{ \App\Models\Product::GST_TAX_TYPE_BOTH }}') {
            const halfRate = (splitTotal / 2).toFixed(2);

            if (cgstRate) {
                cgstRate.value = halfRate;
            }

            if (sgstRate) {
                sgstRate.value = halfRate;
            }

            if (igstRate) {
                igstRate.value = type === '{{ \App\Models\Product::GST_TAX_TYPE_BOTH }}'
                    ? igstTotal.toFixed(2)
                    : '0.00';
            }

            if (gstSplitPreview) {
                gstSplitPreview.textContent = `CGST ${halfRate}% + SGST ${halfRate}%`;
            }

            return;
        }

        if (type === '{{ \App\Models\Product::GST_TAX_TYPE_IGST }}') {
            if (cgstRate) {
                cgstRate.value = '0.00';
            }

            if (sgstRate) {
                sgstRate.value = '0.00';
            }

            if (igstRate) {
                igstRate.value = igstTotal.toFixed(2);
            }

            if (gstSplitPreview) {
                gstSplitPreview.textContent = 'CGST 0.00% + SGST 0.00%';
            }

            return;
        }

        if (cgstRate) {
            cgstRate.value = '0.00';
        }

        if (sgstRate) {
            sgstRate.value = '0.00';
        }

        if (igstRate) {
            igstRate.value = '0.00';
        }

        if (gstSplitPreview) {
            gstSplitPreview.textContent = 'CGST 0.00% + SGST 0.00%';
        }
    };

    const syncSummary = function () {
        const type = selectedValue('product_type', '{{ $productType }}');
        const stockMode = selectedValue('stock_mode', '{{ $stockMode }}');
        const name = (nameInput?.value || '').trim();
        const category = categoryInput?.selectedOptions?.[0]?.textContent?.trim() || '';
        const gstTypeLabel = gstTaxType?.selectedOptions?.[0]?.textContent?.trim() || 'Not set';
        const gstModeLabel = gstMode?.selectedOptions?.[0]?.textContent?.trim() || 'Exclusive';
        const rateLabel = gstTaxType?.value === '{{ \App\Models\Product::GST_TAX_TYPE_BOTH }}'
            ? `${parseFloat(gstSplitTotalRate?.value || '0') || 0}% + IGST ${parseFloat(gstIgstTotalRate?.value || '0') || 0}%`
            : (gstTaxType?.value === '{{ \App\Models\Product::GST_TAX_TYPE_CGST_SGST }}'
            ? `${parseFloat(gstSplitTotalRate?.value || '0') || 0}%`
            : (gstTaxType?.value === '{{ \App\Models\Product::GST_TAX_TYPE_IGST }}' ? `${parseFloat(gstIgstTotalRate?.value || '0') || 0}%` : ''));
        const pricingConfigured = money(salePrice) !== 'Not set'
            || money(pricePerDay) !== 'Not set'
            || money(rental15) !== 'Not set'
            || money(rental30) !== 'Not set'
            || money(rental3) !== 'Not set';

        if (summary('name')) summary('name').textContent = name || 'Product name';
        if (summary('category')) summary('category').textContent = category && category !== 'Select category' ? category : 'No category yet';
        if (summary('type')) summary('type').textContent = inventoryProfileLabel(type, stockMode);
        if (summary('stock')) summary('stock').textContent = labelize(stockMode);
        if (summary('sale')) summary('sale').textContent = money(salePrice);
        if (summary('daily')) summary('daily').textContent = money(pricePerDay);
        if (summary('rental15')) summary('rental15').textContent = money(rental15);
        if (summary('rental30')) summary('rental30').textContent = money(rental30);
        if (summary('rental3')) summary('rental3').textContent = money(rental3);
        if (summary('gst')) summary('gst').textContent = gstTaxType?.value ? `${gstTypeLabel} ${rateLabel} ${gstModeLabel}` : 'Not set';
        if (mobileSummary('mode')) mobileSummary('mode').textContent = inventoryProfileLabel(type, stockMode);
        if (mobileSummary('sale')) {
            const saleValue = money(salePrice);
            mobileSummary('sale').textContent = saleValue === 'Not set' ? 'Sale not set' : `${saleValue} Sale`;
        }
        if (mobileSummary('daily')) {
            const dailyValue = money(pricePerDay);
            mobileSummary('daily').textContent = dailyValue === 'Not set' ? 'Rent not set' : `${dailyValue}/day`;
        }

        if (validation('name')) {
            validation('name').textContent = name ? 'âœ“ Product name entered' : 'Product name required';
            validation('name').className = name ? 'is-ok' : 'is-warn';
        }
        if (validation('pricing')) {
            validation('pricing').textContent = pricingConfigured ? 'âœ“ Pricing configured' : 'Pricing not configured';
            validation('pricing').className = pricingConfigured ? 'is-ok' : 'is-warn';
        }
        if (validation('gst')) {
            validation('gst').textContent = gstTaxType?.value ? 'âœ“ GST selected' : 'GST not selected';
            validation('gst').className = gstTaxType?.value ? 'is-ok' : 'is-warn';
        }
    };

    const showMasterMessage = (message) => {
        const toast = document.createElement('div');
        toast.textContent = message;
        toast.style.position = 'fixed';
        toast.style.right = '16px';
        toast.style.bottom = window.matchMedia('(max-width: 768px)').matches ? '92px' : '18px';
        toast.style.zIndex = '140';
        toast.style.padding = '12px 14px';
        toast.style.borderRadius = '14px';
        toast.style.background = '#dcfce7';
        toast.style.color = '#166534';
        toast.style.fontWeight = '900';
        toast.style.boxShadow = '0 18px 42px rgba(15,23,42,.16)';
        document.body.appendChild(toast);
        window.setTimeout(() => toast.remove(), 2600);
    };

    const masterModals = document.querySelectorAll('[data-master-modal]');
    const closeMasterModal = (modal) => {
        if (!(modal instanceof HTMLElement)) return;
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        modal.querySelectorAll('[data-master-error], [data-master-success]').forEach((node) => {
            node.textContent = '';
            node.classList.remove('is-visible');
        });
    };
    const openMasterModal = (type) => {
        const modal = document.querySelector(`[data-master-modal="${type}"]`);
        if (!(modal instanceof HTMLElement)) return;
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        const nameField = modal.querySelector('[data-master-field="name"]');
        if (nameField instanceof HTMLElement) {
            window.setTimeout(() => nameField.focus(), 80);
        }
    };
    const masterPayload = (modal) => {
        const payload = {};
        modal.querySelectorAll('[data-master-field]').forEach((field) => {
            const key = field.getAttribute('data-master-field');
            if (!key) return;
            if (field instanceof HTMLInputElement && field.type === 'checkbox') {
                payload[key] = field.checked ? '1' : '0';
            } else {
                payload[key] = field.value || '';
            }
        });
        return payload;
    };
    const appendAndSelectMasterOption = (select, item) => {
        if (!(select instanceof HTMLSelectElement) || !item?.id) return;
        let option = Array.from(select.options).find((candidate) => String(candidate.value) === String(item.id));
        if (!option) {
            option = document.createElement('option');
            option.value = item.id;
            option.textContent = item.name;
            select.appendChild(option);
        } else {
            option.textContent = item.name;
        }
        select.value = String(item.id);
        select.dispatchEvent(new Event('change', { bubbles: true }));
    };

    document.querySelectorAll('[data-master-modal-open]').forEach((button) => {
        button.addEventListener('click', () => openMasterModal(button.getAttribute('data-master-modal-open')));
    });
    masterModals.forEach((modal) => {
        modal.addEventListener('click', (event) => {
            if (event.target === modal || event.target.closest('[data-master-modal-close]')) {
                closeMasterModal(modal);
            }
        });
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            masterModals.forEach(closeMasterModal);
        }
    });
    document.querySelectorAll('[data-master-save]').forEach((button) => {
        button.addEventListener('click', async () => {
            const modal = button.closest('[data-master-modal]');
            if (!(modal instanceof HTMLElement)) return;
            const type = button.getAttribute('data-master-save');
            const errorBox = modal.querySelector('[data-master-error]');
            const url = button.getAttribute('data-url');
            const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            const payload = masterPayload(modal);

            errorBox?.classList.remove('is-visible');
            if (!String(payload.name || '').trim()) {
                if (errorBox) {
                    errorBox.textContent = type === 'brand' ? 'Brand name is required.' : 'Category name is required.';
                    errorBox.classList.add('is-visible');
                }
                return;
            }

            button.disabled = true;
            button.textContent = type === 'brand' ? 'Saving Brand...' : 'Saving Category...';
            try {
                const response = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify(payload),
                });
                const data = await response.json().catch(() => ({}));
                if (!response.ok) {
                    const message = data?.errors?.name?.[0] || data?.message || 'Unable to save. Please check the details.';
                    if (errorBox) {
                        errorBox.textContent = message;
                        errorBox.classList.add('is-visible');
                    }
                    return;
                }

                if (type === 'brand') {
                    appendAndSelectMasterOption(brandInput, data.brand);
                } else {
                    appendAndSelectMasterOption(categoryInput, data.category);
                    const parentSelect = modal.querySelector('[data-master-field="parent_id"]');
                    if (parentSelect instanceof HTMLSelectElement && data.category?.id) {
                        const parentOption = document.createElement('option');
                        parentOption.value = data.category.id;
                        parentOption.textContent = data.category.name;
                        parentSelect.appendChild(parentOption);
                    }
                }

                modal.querySelectorAll('[data-master-field]').forEach((field) => {
                    if (field instanceof HTMLInputElement && field.type === 'checkbox') {
                        field.checked = true;
                    } else {
                        field.value = '';
                    }
                });
                showMasterMessage(type === 'brand' ? 'Brand added and selected.' : 'Category added and selected.');
                closeMasterModal(modal);
            } catch (error) {
                if (errorBox) {
                    errorBox.textContent = 'Unable to save right now. Please try again.';
                    errorBox.classList.add('is-visible');
                }
            } finally {
                button.disabled = false;
                button.textContent = type === 'brand' ? 'Save Brand' : 'Save Category';
            }
        });
    });

    inventoryProfileInputs.forEach(function (input) {
        input.addEventListener('change', function () {
            if (!input.checked) {
                return;
            }

            setRadioValue('product_type', input.dataset.productTypeValue);
            setRadioValue('stock_mode', input.dataset.stockModeValue);
            syncMode();
        });
    });
    productTypeInputs.forEach((input) => input.addEventListener('change', syncMode));
    stockModeInputs.forEach((input) => input.addEventListener('change', syncMode));
    document.querySelectorAll('[data-product-mobile-toggle]').forEach(function (toggle) {
        toggle.addEventListener('click', function () {
            const section = toggle.closest('[data-product-mobile-section]');
            if (!section) {
                return;
            }

            const willOpen = !section.classList.contains('is-open');
            if (!willOpen) {
                return;
            }

            document.querySelectorAll('[data-product-mobile-section]').forEach(function (item) {
                item.classList.remove('is-open');
                item.querySelector('[data-product-mobile-toggle]')?.setAttribute('aria-expanded', 'false');
            });

            if (willOpen) {
                section.classList.add('is-open');
                toggle.setAttribute('aria-expanded', 'true');
            }
        });
    });
    [nameInput, categoryInput, salePrice, pricePerDay, rental15, rental30, rental3, gstMode].forEach((input) => {
        input?.addEventListener('input', syncSummary);
        input?.addEventListener('change', syncSummary);
    });
    gstTaxType?.addEventListener('change', function () {
        syncGstMode();
        syncProductGstRates();
        syncSummary();
    });
    gstSplitTotalRate?.addEventListener('change', function () {
        syncProductGstRates();
        syncSummary();
    });
    gstIgstTotalRate?.addEventListener('change', function () {
        syncProductGstRates();
        syncSummary();
    });
    syncMode();
    syncGstMode();
    syncProductGstRates();
    syncSummary();
});
</script>
@endpush
