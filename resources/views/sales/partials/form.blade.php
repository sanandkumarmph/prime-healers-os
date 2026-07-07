@php
    $sale = $sale ?? null;
    $isEdit = (bool) $sale;
    $businessPartners = $businessPartners ?? collect();
    $initialPartnerClients = $initialPartnerClients ?? collect();
    $businessPartnerFlowAvailable = $businessPartnerFlowAvailable ?? true;
    $referralSourceOptions = collect($referralSourceOptions ?? collect())->values();
    $selectedCustomerType = old('customer_type', $sale?->customerTypeValue() ?? 'direct_customer');
    if (!$businessPartnerFlowAvailable && $selectedCustomerType === 'business_partner') {
        $selectedCustomerType = 'direct_customer';
    }
    $selectedCustomerId = (int) old('customer_id', $sale->customer_id ?? request('customer_id'));
    $selectedBusinessPartnerId = (int) old('business_partner_id', $sale->business_partner_id ?? 0);
    $selectedPartnerClientId = (int) old('partner_client_id', $sale->partner_client_id ?? 0);
    $selectedRentalId = (int) old('rental_id', $sale->rental_id ?? null);
    $selectedCustomer = $customers->firstWhere('id', $selectedCustomerId);
    $selectedBusinessPartner = $businessPartners->firstWhere('id', $selectedBusinessPartnerId);
    $selectedPartnerClient = $initialPartnerClients->firstWhere('id', $selectedPartnerClientId);
    $shouldShowSaleCustomerSummary = $selectedCustomerType === 'business_partner'
        ? (bool) ($selectedBusinessPartner || $selectedPartnerClient)
        : filled($selectedCustomerId);
    $organizationState = optional(auth()->user()->organization)->state;
    $fulfilmentVendors = collect($fulfilmentVendors ?? collect())->values();
    $selectedFulfilmentSource = old('fulfilment_source', $sale?->fulfilment_source ?? \App\Models\VendorOrderDetail::FULFILMENT_SOURCE_IN_HOUSE);
    $selectedFulfilmentVendorId = (int) old('vendor_id', $sale->vendor_id ?? 0);
    $selectedDeliveryResponsibility = old('delivery_responsibility', $sale?->delivery_responsibility ?? 'ph_internal_delivery');
    $currentUser = auth()->user();
    $canViewSaleProfitability = $currentUser && (
        in_array($currentUser->effective_role, [
            \App\Models\User::ROLE_SUPER_ADMIN,
            \App\Models\User::ROLE_ADMIN_OPERATIONS,
            'admin',
            \App\Models\User::ROLE_FINANCE,
        ], true)
        || $currentUser->canViewFinance('finance.view_profit')
        || $currentUser->hasPermission('dashboard.finance.full')
        || $currentUser->hasPermission('vendor_costs.view')
    );

    $initialSaleItems = old('sale_items');
    $initialShippingCharges = old('shipping_charges');

    if ($initialShippingCharges === null) {
        $initialShippingCharges = $isEdit
            ? number_format((float) $sale->resolvedShippingCharges(), 2, '.', '')
            : number_format((float) old('shipping_charges', 0), 2, '.', '');
    } else {
        $initialShippingCharges = number_format((float) $initialShippingCharges, 2, '.', '');
    }

    if (!is_array($initialSaleItems) || $initialSaleItems === []) {
        if ($isEdit) {
            $initialSaleItems = $sale->displaySaleItems()->map(function ($item) {
                return [
                    'product_id' => $item->product_id,
                    'asset_id' => $item->asset_id,
                    'quantity' => (int) ($item->quantity ?? 1),
                    'unit_price' => number_format((float) ($item->unit_price ?? 0), 2, '.', ''),
                    'discount_amount' => number_format((float) ($item->discount_amount ?? 0), 2, '.', ''),
                    'tax_percentage' => number_format((float) ($item->tax_percentage ?? 0), 2, '.', ''),
                    'tax_calculation_mode' => $item->tax_calculation_mode ?? 'exclusive',
                    'tax_type' => $item->tax_type ?? null,
                    'notes' => $item->notes,
                ];
            })->values()->all();
        } elseif (old('product_id')) {
            $initialSaleItems = [[
                'product_id' => old('product_id'),
                'asset_id' => old('asset_id'),
                'quantity' => old('quantity', 1),
                'unit_price' => old('unit_price', 0),
                'discount_amount' => old('discount_amount', 0),
                'tax_percentage' => old('tax_percentage', 0),
                'tax_calculation_mode' => old('tax_calculation_mode', 'exclusive'),
                'tax_type' => old('tax_type'),
                'notes' => null,
            ]];
        } else {
            $initialSaleItems = [[
                'product_id' => null,
                'asset_id' => null,
                'quantity' => 1,
                'unit_price' => 0,
                'discount_amount' => 0,
                'tax_percentage' => 0,
                'tax_calculation_mode' => 'exclusive',
                'tax_type' => null,
                'notes' => null,
            ]];
        }
    }

    $productData = $products->map(fn ($product) => [
        'id' => $product->id,
        'name' => $product->name,
        'brand' => $product->brand,
        'model_name' => $product->model_name,
        'sku' => $product->sku,
        'product_code' => $product->product_code,
        'hsn_code' => $product->hsn_code,
        'stock_mode' => $product->stock_mode,
        'stock_mode_label' => $product->stockModeLabel(),
        'total_quantity' => (int) ($product->total_quantity ?? 0),
        'available_quantity' => (int) ($product->available_quantity ?? 0),
        'sale_price' => (float) ($product->sale_price ?? 0),
        'image_url' => $product->product_image_url,
        'tax_percentage' => $product->gst_tax_type === \App\Models\Product::GST_TAX_TYPE_CGST_SGST
            ? round((float) ($product->cgst_rate ?? 0) + (float) ($product->sgst_rate ?? 0), 2)
            : round((float) ($product->igst_rate ?? 0), 2),
        'tax_mode' => $product->gst_calculation_mode ?? 'exclusive',
    ])->values();

    $assetData = ($assets ?? collect())->map(fn ($asset) => [
        'id' => $asset->id,
        'product_id' => $asset->product_id,
        'serial_number' => $asset->serial_number,
        'barcode_value' => $asset->barcode_value,
        'asset_name' => $asset->asset_name,
        'warehouse_id' => $asset->warehouse_id,
        'warehouse_name' => $asset->warehouse?->name,
        'label' => trim(implode(' - ', array_filter([
            $asset->serial_number ?: $asset->asset_name ?: ('Asset #' . $asset->id),
            $asset->barcode_value,
            $asset->product?->name,
            $asset->warehouse?->name,
        ]))),
    ])->values();

@endphp

@include('partials.business-partner-flow-styles')

<style>
    .sales-shell { display:grid; gap:12px; padding:12px 18px 24px; width:100%; max-width:1240px; min-width:0; overflow-x:clip; }
    .sales-header { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap; }
    .sales-header h1 { margin:0; font-size:26px; color:#0f172a; line-height:1.05; }
    .sales-header p { margin:5px 0 0; color:#64748b; font-size:13px; max-width:760px; }
    .sales-actions { display:flex; gap:8px; flex-wrap:wrap; }
    .sales-btn, .sales-btn-light, .sales-quick-btn {
        display:inline-flex; align-items:center; justify-content:center; gap:6px;
        min-height:40px; padding:9px 13px; border-radius:12px; font-size:13px; font-weight:700;
        text-decoration:none; border:1px solid transparent; cursor:pointer; line-height:1.2;
    }
    .sales-btn { background:#0f172a; color:#fff; }
    .sales-btn-light { background:#fff; color:#334155; border-color:#cbd5e1; }
    .sales-quick-btn { background:#eff6ff; color:#1d4ed8; border-color:#bfdbfe; white-space:nowrap; }
    .sales-card {
        background:#fff; border:1px solid #dbe3ef; border-radius:16px; padding:14px;
        box-shadow:0 8px 24px rgba(15, 23, 42, 0.04);
        min-width:0;
        max-width:100%;
    }
    .sales-card h2 { margin:0 0 4px; font-size:17px; color:#0f172a; }
    .sales-card > p { margin:0 0 10px; color:#64748b; font-size:12px; }
    .sale-create-workspace { display:grid; grid-template-columns:minmax(0, 1fr) minmax(280px, 340px); gap:14px; align-items:start; }
    .sale-step-column { display:grid; gap:12px; min-width:0; }
    .sale-step-card { display:none; }
    .sale-step-card.is-active { display:block; }
    .sale-wizard-tabs {
        display:grid; grid-template-columns:repeat(5, minmax(0,1fr)); gap:8px;
        padding:8px; border:1px solid #dbe3ef; border-radius:16px; background:#fff; box-shadow:0 8px 22px rgba(15,23,42,.04);
    }
    .sale-wizard-tab {
        border:1px solid #dbe3ef; border-radius:12px; background:#f8fbff; color:#334155;
        min-height:40px; padding:8px 10px; font-size:12px; font-weight:800; cursor:pointer;
        display:flex; align-items:center; justify-content:center; gap:6px;
    }
    .sale-wizard-tab.is-active { background:#3150ff; border-color:#3150ff; color:#fff; box-shadow:0 10px 22px rgba(49,80,255,.18); }
    .sale-wizard-tab.is-complete { border-color:#bbf7d0; background:#f0fdf4; color:#166534; }
    .sale-summary-panel {
        position:sticky; top:86px; display:grid; gap:10px; padding:14px; border:1px solid #dbe3ef;
        border-radius:18px; background:linear-gradient(180deg,#fff 0%,#f8fbff 100%); box-shadow:0 12px 30px rgba(15,23,42,.07);
    }
    .sale-summary-panel h2 { margin:0; font-size:16px; color:#0f172a; }
    .sale-summary-panel p { margin:2px 0 0; color:#64748b; font-size:12px; }
    .sale-summary-line { display:flex; justify-content:space-between; gap:12px; padding-top:8px; border-top:1px solid #e8eef7; color:#64748b; font-size:12px; }
    .sale-summary-line:first-child { border-top:0; padding-top:0; }
    .sale-summary-line strong { color:#0f172a; text-align:right; overflow-wrap:anywhere; }
    .sale-step-footer { display:flex; justify-content:space-between; gap:8px; flex-wrap:wrap; margin-top:12px; }
    .sale-step-warning { display:none; margin-top:10px; padding:10px 12px; border:1px solid #fed7aa; border-radius:12px; background:#fff7ed; color:#9a3412; font-size:12px; }
    .sale-step-warning.is-visible { display:block; }
    .sale-mobile-sticky-footer { display:none; }
    .sale-legacy-snapshot { display:none !important; }
    .sales-grid { display:grid; grid-template-columns:repeat(12, minmax(0, 1fr)); gap:12px 14px; min-width:0; }
    .sales-col-3 { grid-column:span 3; }
    .sales-col-4 { grid-column:span 4; }
    .sales-col-6 { grid-column:span 6; }
    .sales-col-8 { grid-column:span 8; }
    .sales-col-12 { grid-column:span 12; }
    .sales-field { display:flex; flex-direction:column; gap:6px; }
    .sales-field label {
        font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#475569;
    }
    .sales-field small { font-size:11px; color:#94a3b8; }
    .sales-field input,
    .sales-field select,
    .sales-field textarea {
        width:100%; box-sizing:border-box; padding:10px 12px; border-radius:12px; border:1px solid #cbd5e1;
        background:#fff; color:#0f172a; font-size:14px;
    }
    .sales-field textarea { min-height:92px; resize:vertical; }
    .sales-field input:focus,
    .sales-field select:focus,
    .sales-field textarea:focus {
        outline:none; border-color:#2563eb; box-shadow:0 0 0 3px rgba(37, 99, 235, 0.12);
    }
    .sales-field.is-error input,
    .sales-field.is-error select,
    .sales-field.is-error textarea {
        border-color:#dc2626; box-shadow:0 0 0 3px rgba(220, 38, 38, 0.12); background:#fff7f7;
    }
    .sales-field-error { color:#b91c1c; font-size:12px; line-height:1.4; }
    .sales-inline { display:flex; gap:8px; align-items:flex-end; flex-wrap:wrap; }
    .sales-inline .sales-field { flex:1 1 280px; }
    .sales-errors {
        border:1px solid #fecaca; background:#fef2f2; color:#b91c1c; border-radius:14px; padding:14px 16px;
    }
    .sales-summary { display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:10px; }
    .sales-metric {
        border:1px solid #e2e8f0; border-radius:14px; background:#f8fafc; padding:12px;
    }
    .sales-metric span {
        display:block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#64748b;
    }
    .sales-metric strong {
        display:block; margin-top:6px; font-size:18px; color:#0f172a; min-width:0; overflow-wrap:anywhere;
    }
    .sales-referral-card {
        border:1px solid #dbe3ef;
        border-radius:16px;
        background:linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
        padding:14px;
        display:grid;
        gap:12px;
    }
    .sales-referral-card h3 {
        margin:0;
        color:#0f172a;
        font-size:15px;
    }
    .sales-referral-card p {
        margin:3px 0 0;
        color:#64748b;
        font-size:12px;
    }
    .sales-referral-grid {
        display:grid;
        grid-template-columns:repeat(4, minmax(0, 1fr));
        gap:12px 14px;
        align-items:start;
    }
    .sales-referral-grid .sales-field {
        grid-column:auto;
    }
    .sales-referral-grid .sales-field label {
        min-height:34px;
        display:flex;
        align-items:flex-start;
    }
    .sales-referral-grid .sales-field:last-child {
        grid-column:1 / -1;
    }
    .sales-scan-row {
        display:grid;
        grid-template-columns:minmax(0, 1fr) auto;
        gap:8px;
        align-items:end;
    }
    .sales-scan-row input {
        text-transform:uppercase;
    }
    .sales-customer-card {
        display:grid; gap:6px; padding:14px; border:1px solid #dbe3ef; border-radius:14px; background:#f8fbff;
    }
    .sales-customer-card strong { color:#0f172a; font-size:15px; }
    .sales-customer-card div { color:#475569; font-size:13px; }
    .sales-customer-chip {
        display:inline-flex; align-items:center; padding:4px 8px; border-radius:999px;
        background:#dbeafe; color:#1d4ed8; font-size:11px; font-weight:800; letter-spacing:.04em; text-transform:uppercase;
    }
    [data-sales-customer-mode][hidden] { display:none !important; }
    .sales-items-toolbar {
        display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:12px;
    }
    .sales-items-toolbar strong { color:#0f172a; font-size:14px; }
    .sales-item-list { display:grid; gap:12px; }
    .sales-item-card {
        border:1px solid #dbe3ef; border-radius:16px; background:linear-gradient(180deg, #ffffff 0%, #f8fbff 100%); padding:14px;
        display:grid; gap:12px;
    }
    .sales-item-card.is-compact { padding:16px; border-radius:14px; background:#fff; box-shadow:0 8px 20px rgba(15,23,42,.035); }
    .sales-item-title { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
    .sales-line-actions { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
    .sales-collapse-btn {
        width:32px; height:32px; border:1px solid #dbe3ef; border-radius:10px; background:#fff; color:#334155;
        display:inline-flex; align-items:center; justify-content:center; cursor:pointer; font-size:16px; font-weight:900;
    }
    .sales-product-first-row { display:grid; grid-template-columns:minmax(0, 1.1fr) minmax(0, .95fr) max-content; gap:12px; align-items:end; min-width:0; max-width:100%; }
    .sales-add-stock-btn {
        min-height:40px; height:40px; padding:9px 12px; border-radius:12px; border:1px solid #b8c7ff; background:#fff;
        color:#2440d8; font-size:13px; font-weight:800; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; gap:7px; white-space:nowrap; max-width:100%;
    }
    .sales-add-stock-btn:hover { background:#eff6ff; border-color:#3150ff; }
    .sales-product-preview {
        display:flex; align-items:center; gap:10px; padding:10px 12px; border:1px solid #e2e8f0; border-radius:12px; background:#f8fafc; min-width:0;
    }
    .sales-product-preview[hidden] { display:none !important; }
    .sales-product-thumb {
        width:44px; height:44px; border-radius:12px; background:#eef2ff; color:#3150ff; display:grid; place-items:center; flex:0 0 auto; font-weight:900; overflow:hidden;
    }
    .sales-product-thumb img { width:100%; height:100%; object-fit:cover; display:block; }
    .sales-product-preview strong { display:block; color:#0f172a; font-size:14px; line-height:1.25; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .sales-product-preview span { display:block; color:#64748b; font-size:12px; margin-top:2px; }
    .sales-stock-mode-badge { display:inline-flex; width:max-content; margin-top:5px; padding:3px 7px; border-radius:999px; background:#dcfce7; color:#166534; font-size:10px; font-weight:900; text-transform:uppercase; letter-spacing:.04em; }
    .sales-stock-chip-row { display:grid; grid-template-columns:repeat(5, minmax(0, 1fr)); gap:8px; padding:10px; border:1px solid #e2e8f0; border-radius:12px; background:#f8fafc; }
    .sales-stock-chip-row.is-muted { display:block; color:#64748b; font-size:12px; }
    .sales-stock-chip { display:flex; align-items:center; gap:8px; min-width:0; padding:8px 9px; border-right:1px solid #e2e8f0; }
    .sales-stock-chip:last-child { border-right:0; }
    .sales-stock-dot { width:8px; height:8px; border-radius:999px; background:#94a3b8; flex:0 0 auto; }
    .sales-stock-chip.is-available .sales-stock-dot { background:#22c55e; }
    .sales-stock-chip.is-sold .sales-stock-dot { background:#3b82f6; }
    .sales-stock-chip.is-reserved .sales-stock-dot { background:#f59e0b; }
    .sales-stock-chip.is-transit .sales-stock-dot { background:#8b5cf6; }
    .sales-stock-chip span { display:block; color:#475569; font-size:11px; font-weight:800; line-height:1.1; }
    .sales-stock-chip strong { display:block; margin-top:3px; color:#0f172a; font-size:15px; line-height:1; }
    .sales-qty-stepper { display:grid; grid-template-columns:40px minmax(52px, 1fr) 40px; border:1px solid #cbd5e1; border-radius:12px; overflow:hidden; background:#fff; }
    .sales-qty-stepper button { border:0; background:#f8fafc; color:#334155; font-size:18px; font-weight:900; cursor:pointer; }
    .sales-qty-stepper input { border:0; border-left:1px solid #e2e8f0; border-right:1px solid #e2e8f0; border-radius:0; text-align:center; min-height:40px; box-shadow:none !important; }
    .sales-line-total.is-compact { background:#f8fafc; color:#0f172a; border:1px solid #e2e8f0; border-radius:0 0 12px 12px; padding:10px 14px; justify-content:flex-end; }
    .sales-line-total.is-compact strong { font-size:20px; color:#0f172a; }
    .sales-line-total.is-compact span { color:#64748b; opacity:1; }
    .sales-product-details[hidden] { display:none !important; }
    .sales-tax-details summary { cursor:pointer; color:#3150ff; font-size:12px; font-weight:800; list-style:none; }
    .sales-tax-details summary::-webkit-details-marker { display:none; }
    .sales-commercial-grid { display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:12px 14px; align-items:end; min-width:0; max-width:100%; }
    .sales-commercial-grid .sales-field { min-width:0; }
    .sales-commercial-grid input, .sales-commercial-grid select { min-height:42px; }
    .sales-stock-popup {
        position:fixed; inset:0; z-index:999999; display:none; align-items:center; justify-content:center;
        padding:18px; background:rgba(15,23,42,.55);
    }
    .sales-stock-popup.is-open { display:flex; }
    .sales-stock-popup-panel {
        width:min(1040px, calc(100vw - 32px)); height:min(86vh, 820px); overflow:hidden;
        border:1px solid #dbe3ef; border-radius:18px; background:#fff; box-shadow:0 28px 80px rgba(15,23,42,.28);
        display:grid; grid-template-rows:auto minmax(0, 1fr);
    }
    .sales-stock-popup-head { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:12px 14px; border-bottom:1px solid #e2e8f0; }
    .sales-stock-popup-head strong { color:#0f172a; font-size:16px; }
    .sales-stock-popup-frame { width:100%; height:100%; border:0; background:#f8fafc; }
    .sales-item-head {
        display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap;
    }
    .sales-item-head strong { color:#0f172a; font-size:15px; }
    .sales-line-pill {
        display:inline-flex; align-items:center; padding:6px 10px; border-radius:999px; background:#eff6ff; color:#1d4ed8;
        font-size:11px; font-weight:800; letter-spacing:.04em; text-transform:uppercase;
    }
    .sales-remove-btn {
        display:inline-flex; align-items:center; justify-content:center; min-height:38px; padding:0 12px; border-radius:12px;
        border:1px solid #fecaca; background:#fff1f2; color:#b91c1c; font-size:12px; font-weight:700; cursor:pointer;
    }
    .sales-line-total {
        display:flex; align-items:center; justify-content:space-between; gap:12px; padding:12px 14px; border-radius:14px;
        background:#0f172a; color:#fff;
    }
    .sales-line-total span { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; opacity:.74; }
    .sales-line-total strong { font-size:20px; }
    .sales-order-total {
        display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;
        padding:14px 16px; border:1px solid #dbe3ef; border-radius:16px; background:#f8fbff;
        min-width:0;
        max-width:100%;
    }
    .sales-order-total strong { color:#0f172a; font-size:22px; }
    .sales-pricing-static {
        border:1px dashed #cbd5e1;
        border-radius:12px;
        background:#fff;
        padding:10px 12px;
    }
    .sales-pricing-static span {
        color:#64748b;
        font-size:13px;
        font-weight:700;
    }
    .searchable-select-native {
        position:absolute !important; width:1px !important; height:1px !important; padding:0 !important; margin:-1px !important;
        overflow:hidden !important; clip:rect(0, 0, 0, 0) !important; white-space:nowrap !important; border:0 !important;
    }
    .searchable-select { position:relative; }
    .searchable-select-trigger {
        width:100%; min-height:46px; padding:10px 12px; border-radius:12px; border:1px solid #cbd5e1;
        background:#fff; color:#0f172a; font-size:14px; text-align:left; display:flex; align-items:center;
        justify-content:space-between; gap:10px; cursor:pointer;
    }
    .searchable-select-trigger::after {
        content:""; width:8px; height:8px; border-right:2px solid #64748b; border-bottom:2px solid #64748b;
        transform:rotate(45deg); flex:0 0 8px; margin-top:-4px;
    }
    .searchable-select.is-open .searchable-select-trigger,
    .searchable-select-trigger:focus {
        outline:none; border-color:#2563eb; box-shadow:0 0 0 3px rgba(37, 99, 235, 0.12);
    }
    .searchable-select-panel {
        position:absolute; top:calc(100% + 6px); left:0; right:0; z-index:35; padding:10px;
        border:1px solid #cbd5e1; border-radius:14px; background:#fff; box-shadow:0 18px 40px rgba(15, 23, 42, 0.14); display:grid; gap:8px;
    }
    .searchable-select-panel[hidden] { display:none !important; }
    .searchable-select-search {
        width:100%; padding:9px 11px; border-radius:10px; border:1px solid #cbd5e1; font-size:13px; box-sizing:border-box;
    }
    .searchable-select-options { max-height:220px; overflow:auto; display:grid; gap:4px; }
    .searchable-select-option, .searchable-select-empty {
        width:100%; padding:9px 11px; border:none; border-radius:10px; background:#fff; color:#0f172a; font-size:13px; text-align:left;
    }
    .searchable-select-option { cursor:pointer; }
    .searchable-select-option:hover, .searchable-select-option.is-selected { background:#eff6ff; color:#1d4ed8; }
    .searchable-select-option.has-product-media { padding:8px; }
    .product-option-media { display:flex; align-items:center; gap:10px; min-width:0; }
    .product-option-thumb {
        width:38px;
        height:38px;
        border:1px solid #dbe3ef;
        border-radius:10px;
        background:#eef2ff;
        color:#3150ff;
        display:grid;
        place-items:center;
        flex:0 0 auto;
        font-size:13px;
        font-weight:900;
        overflow:hidden;
    }
    .product-option-thumb img { width:100%; height:100%; object-fit:cover; display:block; }
    .product-option-copy { display:grid; gap:2px; min-width:0; }
    .product-option-copy strong { color:#0f172a; font-size:13px; line-height:1.2; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .product-option-copy span { color:#64748b; font-size:11px; line-height:1.25; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .product-option-copy em { color:#1d4ed8; font-size:10px; font-style:normal; font-weight:800; line-height:1.2; }    .searchable-select-empty { color:#64748b; }
    .sales-field.is-error .searchable-select-trigger {
        border-color:#dc2626; box-shadow:0 0 0 3px rgba(220, 38, 38, 0.12); background:#fff7f7;
    }
    /* Sales create compact polish pass */
    .sales-shell { padding-top:10px; }
    .sale-wizard-tab { min-height:38px; padding:7px 10px; font-size:12px; }
    .sale-summary-line { padding-top:7px; }
    .sale-summary-line strong { max-width:190px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .sales-summary { grid-template-columns:repeat(3, minmax(0, 1fr)); gap:10px; }
    .sales-metric { padding:10px 12px; }
    .sales-metric strong { margin-top:5px; font-size:16px; line-height:1.25; }
    .sales-referral-grid { grid-template-columns:minmax(150px,.85fr) minmax(240px,1.25fr) minmax(170px,.95fr) minmax(150px,.8fr); }
    .sales-referral-grid .sales-field label { min-height:0; }
    .sales-order-total { padding:12px; }
    .sales-order-total strong { font-size:20px; }
    .sales-order-total .sales-field { border:1px solid #e2e8f0; border-radius:14px; background:#fff; padding:10px 12px; min-width:150px !important; }
    .sales-order-total .sales-field input { min-height:38px; }
    .sales-pricing-net { border:1px solid #bfdbfe; border-radius:14px; background:linear-gradient(180deg,#eff6ff 0%,#fff 100%); padding:10px 12px; min-width:180px; }
    .searchable-select-trigger { min-height:40px; padding:9px 12px; }
    .searchable-select-trigger span { display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; line-height:1.25; }
    .sales-modal-overlay { position:fixed; inset:0; z-index:999998; display:none; align-items:center; justify-content:center; padding:18px; background:rgba(15,23,42,.52); }
    .sales-modal-overlay.is-open { display:flex; }
    .sales-modal-panel { width:min(560px, calc(100vw - 32px)); max-height:88vh; overflow:auto; border:1px solid #dbe3ef; border-radius:18px; background:#fff; box-shadow:0 28px 80px rgba(15,23,42,.26); }
    .sales-modal-head { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:14px 16px; border-bottom:1px solid #e2e8f0; background:#f8fbff; }
    .sales-modal-head strong { color:#0f172a; font-size:16px; }
    .sales-modal-body { display:grid; gap:12px; padding:14px 16px; }
    .sales-modal-grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:12px; }
    .sales-modal-close { width:34px; height:34px; border:1px solid #cbd5e1; border-radius:10px; background:#fff; color:#334155; cursor:pointer; font-weight:900; }
    .sales-modal-error { display:none; padding:10px 12px; border-radius:12px; background:#fef2f2; color:#b91c1c; font-size:12px; }
    .sales-modal-error.is-visible { display:block; }
    @media (max-width: 720px) {
        .sales-summary { grid-template-columns:1fr; }
        .sales-referral-grid, .sales-modal-grid { grid-template-columns:1fr; }
        .sale-summary-line strong { max-width:58vw; }
    }
    @media (max-width: 1024px) {
        .sales-col-3, .sales-col-4, .sales-col-6, .sales-col-8 { grid-column:span 12; }
        .sales-summary { grid-template-columns:repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 720px) {
        .sales-shell { padding:4px 8px calc(152px + env(safe-area-inset-bottom, 0px)); gap:8px; }
        .sales-header { gap:6px; }
        .sales-header h1 { font-size:20px; }
        .sales-header p { display:none; }
        .sales-actions { display:none; }
        .sale-create-workspace { grid-template-columns:1fr; }
        .sales-product-first-row { grid-template-columns:1fr; }
        .sales-commercial-grid { grid-template-columns:1fr; }
        .sales-add-stock-btn { width:100%; }
        .sales-stock-chip-row { grid-template-columns:repeat(2, minmax(0, 1fr)); }
        .sales-stock-chip { border-right:0; border-bottom:1px solid #e2e8f0; }
        .sales-stock-chip:nth-last-child(-n+1) { border-bottom:0; }
        .sale-summary-panel {
            display:none;
        }
        #saleSummaryToggle { display:none !important; }
        .sale-wizard-tabs {
            display:flex;
            gap:10px;
            overflow-x:auto;
            padding:6px 2px;
            border:0;
            border-radius:0;
            background:transparent;
            box-shadow:none;
            scrollbar-width:none;
        }
        .sale-wizard-tabs::-webkit-scrollbar { display:none; }
        .sale-wizard-tab {
            flex:0 0 auto;
            min-height:34px;
            border:0;
            border-radius:0;
            background:transparent;
            padding:0 2px;
            display:grid;
            gap:3px;
            color:#64748b;
            font-size:10px;
            box-shadow:none;
        }
        .sale-wizard-tab::before {
            content:attr(data-step-index);
            width:22px;
            height:22px;
            display:grid;
            place-items:center;
            justify-self:center;
            border-radius:999px;
            background:#eef2ff;
            color:#4f46e5;
            font-size:11px;
            font-weight:900;
        }
        .sale-wizard-tab.is-active {
            background:transparent;
            border-color:transparent;
            color:#4f46e5;
            box-shadow:none;
        }
        .sale-wizard-tab.is-active::before {
            background:#4f46e5;
            color:#fff;
        }
        .sale-wizard-tab.is-complete {
            border-color:transparent;
            background:transparent;
            color:#16a34a;
        }
        .sale-wizard-tab.is-complete::before {
            content:"OK";
            background:#16a34a;
            color:#fff;
        }
        .sales-summary { grid-template-columns:1fr; }
        .sales-referral-grid { grid-template-columns:1fr; }
        .sales-referral-grid .sales-field label { min-height:0; }
        .sales-card { padding:10px; border-radius:14px; }
        .sales-card h2 { font-size:15px; margin:0; }
        .sales-card > p { display:none; }
        .sales-item-head { align-items:flex-start; }
        .sales-header,
        .sales-actions,
        .sales-header > div,
        .sales-card > div,
        .sales-order-total > div {
            min-width:0;
            max-width:100%;
        }
        .sales-header {
            flex-direction:column;
            align-items:stretch;
        }
        .sales-actions {
            width:100%;
            justify-content:stretch;
        }
        .sales-actions .sales-btn,
        .sales-actions .sales-btn-light,
        .sales-actions .sales-quick-btn {
            flex:1 1 140px;
            white-space:normal;
            text-align:center;
        }
        .sales-order-total {
            display:grid;
            grid-template-columns:repeat(2, minmax(0, 1fr));
            gap:8px;
            padding:10px;
            align-items:stretch;
        }
        .sales-order-total strong {
            font-size:17px;
            line-height:1.15;
        }
        .sales-order-total .sales-pricing-net {
            grid-column:1 / -1;
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:10px;
            border:1px solid #e2e8f0;
            border-radius:12px;
            background:#fff;
            padding:8px 10px;
        }
        .sales-order-total .sales-pricing-net strong {
            font-size:19px;
        }
        .sales-order-total .sales-pricing-full {
            grid-column:1 / -1;
            min-width:0 !important;
            width:100%;
            max-width:100%;
        }
        .sales-pricing-static {
            padding:8px 10px;
            min-height:0;
        }
        .sales-pricing-static span {
            font-size:12px;
        }
        #sale-save-section {
            flex-direction:column;
            align-items:stretch !important;
        }
        .sale-step-footer {
            display:none !important;
        }
        #sale-save-section .sales-btn,
        #sale-save-section .sales-btn-light,
        #sale-save-section button {
            width:100%;
            white-space:normal;
            text-align:center;
        }
        #sale-save-section .sales-actions {
            display:none;
        }
        .sales-items-toolbar {
            margin-bottom:8px;
        }
        .sales-item-list {
            gap:8px;
        }
        .sales-item-card {
            padding:10px;
            gap:8px;
            border-radius:14px;
        }
        .sales-item-head strong {
            font-size:13px;
        }
        .sales-line-pill {
            padding:4px 8px;
            font-size:10px;
        }
        .sales-remove-btn {
            min-height:32px;
            padding:0 10px;
        }
        .sales-grid {
            gap:8px;
        }
        .sales-field {
            gap:4px;
        }
        .sales-field label {
            font-size:10px;
        }
        .sales-field input,
        .sales-field select,
        .sales-field textarea,
        .searchable-select-trigger {
            min-height:40px;
            padding:8px 10px;
            border-radius:11px;
            font-size:13px;
        }
        .sales-field textarea {
            min-height:66px;
        }
        .sales-line-total {
            padding:9px 10px;
            border-radius:12px;
        }
        .sales-line-total strong {
            font-size:16px;
        }
        .sales-line-total div:last-child {
            display:none;
        }
        .sales-referral-card {
            padding:10px;
            gap:8px;
            margin-top:8px !important;
            border-radius:14px;
        }
        .sales-referral-card h3 { font-size:13px; }
        .sales-referral-card p { display:none; }
        .party-flow-summary,
        .sales-customer-card {
            padding:10px;
            border-radius:12px;
        }
        .sales-metric {
            padding:9px;
            border-radius:12px;
        }
        .sales-metric strong {
            font-size:14px;
        }
        .sale-mobile-sticky-footer {
            position:fixed;
            left:8px;
            right:8px;
            bottom:calc(82px + env(safe-area-inset-bottom, 0px));
            z-index:908;
            display:grid;
            grid-template-columns:auto minmax(0,1fr) auto;
            gap:8px;
            align-items:center;
            padding:8px;
            border:1px solid #dbe3ef;
            border-radius:18px;
            background:rgba(255,255,255,.98);
            box-shadow:0 18px 44px rgba(15,23,42,.18);
            backdrop-filter:blur(14px);
        }
        .sale-mobile-sticky-meta {
            display:flex;
            gap:10px;
            align-items:center;
            min-width:0;
            color:#475569;
            font-size:10px;
            font-weight:800;
        }
        .sale-mobile-sticky-meta strong {
            display:block;
            color:#0f172a;
            font-size:13px;
            line-height:1.1;
            white-space:nowrap;
        }
        .sale-mobile-sticky-actions {
            display:flex;
            gap:6px;
            justify-content:flex-end;
            min-width:0;
        }
        .sale-mobile-sticky-actions .sales-btn,
        .sale-mobile-sticky-actions .sales-btn-light {
            min-height:40px;
            padding:8px 12px;
            border-radius:12px;
            font-size:12px;
            white-space:nowrap;
        }
        .sale-mobile-sticky-actions .sales-btn {
            background:#4f46e5;
            border-color:#4f46e5;
        }
        .sale-mobile-sticky-footer.is-review {
            grid-template-columns:minmax(0,1fr) auto;
        }
        .searchable-select-trigger {
            white-space:normal;
        }
    }

    /* Sales create final responsive/submit polish */
    .sale-create-workspace { grid-template-columns:minmax(0, 72fr) minmax(280px, 28fr); gap:14px; }
    .sale-step-column, .sales-card, .sales-referral-card, .sales-item-card { min-width:0; }
    .sales-referral-card { overflow:hidden; }
    .sales-referral-grid { grid-template-columns:minmax(140px,.8fr) minmax(220px,1.2fr) minmax(160px,.9fr) minmax(140px,.8fr); }
    .sales-summary { grid-template-columns:repeat(3, minmax(0, 1fr)); }
    .sales-metric { display:grid; grid-template-columns:auto minmax(0,1fr); gap:8px 10px; align-items:center; min-height:64px; }
    .sales-metric::before { content:""; width:28px; height:28px; border-radius:10px; background:#eef2ff; grid-row:1 / span 2; }
    .sales-metric span { align-self:end; }
    .sales-metric strong { align-self:start; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; overflow-wrap:anywhere; }
    .sale-summary-panel { overflow:hidden; }
    .sale-summary-line strong { max-width:180px; }
    .sale-summary-line:has(#saleSummaryNet) { margin-top:4px; padding-top:10px; color:#3150ff; }
    .sale-summary-line:has(#saleSummaryNet) strong { color:#3150ff; font-size:18px; }
    .sales-tax-details { border:1px solid #dbe3ef; border-radius:12px; background:#f8fbff; overflow:hidden; }
    .sales-tax-details summary { display:flex; align-items:center; gap:8px; padding:10px 12px; color:#1d4ed8; background:#fff; cursor:pointer; user-select:none; }
    .sales-tax-details summary span { color:#0f172a; font-size:13px; font-weight:900; }
    .sales-tax-details summary em { color:#64748b; font-size:12px; font-style:normal; font-weight:700; }
    .sales-tax-details summary b { margin-left:auto; width:24px; height:24px; display:grid; place-items:center; border-radius:999px; background:#eef2ff; color:#3150ff; font-size:12px; }
    .sales-tax-details[open] summary b { transform:rotate(180deg); }
    .sales-tax-grid { padding:0 12px 12px; }
    .sales-submit-btn { position:relative; min-width:128px; }
    .sales-submit-btn.is-loading { pointer-events:none; opacity:.86; }
    .sales-submit-btn.is-loading::before { content:""; width:14px; height:14px; border:2px solid rgba(255,255,255,.48); border-top-color:#fff; border-radius:999px; animation:saleSpin .7s linear infinite; }
    @keyframes saleSpin { to { transform:rotate(360deg); } }
    .sale-submit-status { display:none; align-items:center; gap:8px; color:#3150ff; font-size:12px; font-weight:800; }
    .sale-submit-status.is-visible { display:flex; }
    @media (max-width: 900px) {
        .sale-create-workspace { grid-template-columns:1fr; }
        .sales-referral-grid { grid-template-columns:repeat(2, minmax(0,1fr)); }
    }
    @media (max-width: 720px) {
        .sales-shell { padding-bottom:calc(132px + env(safe-area-inset-bottom, 0px)); }
        .sale-wizard-tabs { gap:8px; padding:4px 0; }
        .sale-wizard-tab { min-width:58px; font-size:9px; }
        .sales-product-first-row { grid-template-columns:1fr; gap:8px; }
        .sales-product-preview { padding:8px; }
        .sales-product-thumb { width:34px; height:34px; border-radius:10px; }
        .sales-stock-chip-row { display:flex; gap:6px; overflow-x:auto; padding:8px; scrollbar-width:none; }
        .sales-stock-chip-row::-webkit-scrollbar { display:none; }
        .sales-stock-chip { flex:0 0 122px; border-right:0; border-bottom:0; border:1px solid #e2e8f0; border-radius:10px; background:#fff; padding:7px 8px; }
        .sales-commercial-grid { grid-template-columns:repeat(2, minmax(0,1fr)); gap:8px; }
        .sales-commercial-grid .sales-field:first-child,
        .sales-gst-field,
        .sales-commercial-grid .sales-field[style*="grid-column"] { grid-column:1 / -1 !important; }
        .sales-field textarea { min-height:56px; }
        .sales-line-total.is-compact { position:sticky; bottom:118px; z-index:2; border-radius:12px; box-shadow:0 10px 26px rgba(15,23,42,.08); }
        .sale-mobile-sticky-footer { bottom:calc(72px + env(safe-area-inset-bottom, 0px)); }
        .sales-summary { grid-template-columns:1fr; }
        .sales-metric { min-height:54px; }
    }

    /* Sales referral section declutter */
    .sales-referral-card {
        padding:12px 14px;
        gap:10px;
        overflow:visible;
    }
    .sales-referral-card h3 {
        font-size:15px;
        line-height:1.2;
    }
    .sales-referral-card p {
        margin-top:2px;
        font-size:12px;
        line-height:1.35;
        max-width:560px;
    }
    .sales-referral-grid {
        grid-template-columns:minmax(180px,.78fr) minmax(260px,1.22fr);
        gap:10px 12px;
        align-items:start;
    }
    .sales-referral-grid .sales-field {
        min-width:0;
    }
    .sales-referral-grid .sales-field:nth-child(3),
    .sales-referral-grid .sales-field:nth-child(4) {
        grid-column:auto;
    }
    .sales-referral-grid .sales-field:last-child {
        grid-column:1 / -1;
    }
    .sales-referral-grid label {
        min-height:0 !important;
        line-height:1.25;
        margin-bottom:2px;
    }
    .sales-referral-grid input,
    .sales-referral-grid select,
    .sales-referral-grid .searchable-select-trigger {
        min-height:40px;
        height:40px;
        padding:8px 12px;
        font-size:14px;
    }
    .sales-referral-grid textarea {
        min-height:74px;
        height:74px;
        resize:vertical;
        font-size:14px;
    }
    .sales-referral-grid a[data-open-sale-referral-modal] {
        display:inline-flex;
        width:max-content;
        margin-top:5px;
        font-size:12px !important;
        line-height:1.2;
    }
    @media (min-width: 1180px) {
        .sales-referral-grid {
            grid-template-columns:minmax(160px,.72fr) minmax(250px,1.22fr) minmax(180px,.9fr) minmax(130px,.62fr);
        }
    }
    @media (max-width: 900px) {
        .sales-referral-grid {
            grid-template-columns:1fr 1fr;
        }
    }
    @media (max-width: 640px) {
        .sales-referral-card {
            padding:11px;
        }
        .sales-referral-grid {
            grid-template-columns:1fr;
        }
        .sales-referral-card p {
            display:none;
        }
    }

    /* Sales create referral overflow correction */
    .sales-referral-card,
    .sales-referral-grid,
    .sales-referral-grid .sales-field {
        min-width: 0;
        max-width: 100%;
    }

    .sales-referral-grid input,
    .sales-referral-grid select,
    .sales-referral-grid textarea,
    .sales-referral-grid .searchable-select,
    .sales-referral-grid .searchable-select-trigger {
        width: 100%;
        max-width: 100%;
        min-width: 0;
        box-sizing: border-box;
    }

    @media (max-width: 1180px) {
        .sale-create-workspace {
            grid-template-columns: minmax(0, 1fr) minmax(250px, 300px);
        }

        .sales-referral-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
        }

        .sales-referral-grid .sales-field:nth-child(5) {
            grid-column: 1 / -1;
        }
    }

    @media (max-width: 980px) {
        .sale-create-workspace {
            grid-template-columns: 1fr;
        }

        .sale-summary-panel {
            position: static;
            width: 100%;
            max-width: 100%;
        }
    }

    @media (max-width: 720px) {
        .sales-referral-card {
            padding: 12px;
            overflow: hidden;
        }

        .sales-referral-grid {
            grid-template-columns: 1fr !important;
            gap: 10px;
        }

        .sales-referral-grid .sales-field {
            grid-column: 1 / -1 !important;
        }

        .sales-referral-grid textarea {
            min-height: 72px;
        }
    }

    /* Sales create referral intrinsic wrapping fix */
    .sales-referral-card {
        max-width: 100%;
        overflow: hidden;
    }

    .sales-referral-grid {
        grid-template-columns: repeat(auto-fit, minmax(min(220px, 100%), 1fr)) !important;
        width: 100%;
        max-width: 100%;
        overflow: hidden;
    }

    .sales-referral-grid .sales-field {
        min-width: 0 !important;
        max-width: 100%;
    }

    .sales-referral-grid .sales-field:last-child,
    .sales-referral-grid .sales-field.sales-col-12 {
        grid-column: 1 / -1 !important;
    }

    .sales-referral-grid input,
    .sales-referral-grid select,
    .sales-referral-grid textarea,
    .sales-referral-grid .searchable-select,
    .sales-referral-grid .searchable-select-trigger,
    .sales-referral-grid .searchable-select-panel {
        width: 100% !important;
        max-width: 100% !important;
        min-width: 0 !important;
        box-sizing: border-box;
    }

    .sales-referral-grid textarea {
        display: block;
        resize: vertical;
        overflow: auto;
    }

    @media (min-width: 981px) {
        .sale-create-workspace {
            grid-template-columns: minmax(0, 1fr) minmax(260px, 360px);
        }
    }
</style>

<div class="container sales-shell">
    <div class="sales-header">
        <div>
            <h1>{{ $isEdit ? 'Edit Sale' : 'Create Sale' }}</h1>
            <p>Create sales order with customer, products, pricing, referral, and delivery.</p>
        </div>
        <div class="sales-actions">
            <a href="{{ route('sales.index') }}" class="sales-btn-light">Back to Sales</a>
            @if($isEdit)
                <a href="{{ route('sales.show', $sale->id) }}" class="sales-btn-light">View Sale</a>
            @endif
        </div>
    </div>

    @if ($errors->any())
        <div class="sales-errors">
            <strong>Please review the sale details below.</strong>
            <ul style="margin:8px 0 0 18px; padding:0;">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="sale-wizard-tabs" role="tablist" aria-label="Create sale steps">
        <button type="button" class="sale-wizard-tab is-active" data-step-index="1" data-sale-step-button="customer">Customer</button>
        <button type="button" class="sale-wizard-tab" data-step-index="2" data-sale-step-button="products">Products</button>
        <button type="button" class="sale-wizard-tab" data-step-index="3" data-sale-step-button="pricing">Pricing</button>
        <button type="button" class="sale-wizard-tab" data-step-index="4" data-sale-step-button="delivery">Delivery</button>
        <button type="button" class="sale-wizard-tab" data-step-index="5" data-sale-step-button="review">Review</button>
    </div>

    <div class="sale-create-workspace">
        <div class="sale-step-column">

    <div class="sales-card sale-legacy-snapshot" hidden>
        <h2>Sale Snapshot</h2>
        <p>Quick summary before save.</p>
        <div class="sales-summary">
            <div class="sales-metric">
                <span>Mode</span>
                <strong>{{ $isEdit ? 'Update Existing' : 'Create Fresh' }}</strong>
            </div>
            <div class="sales-metric">
                <span>Billing / Reminder Contact</span>
                <strong id="saleCustomerMetric">{{ $selectedCustomerType === 'business_partner' ? ($selectedBusinessPartner?->displayName() ?: 'Select business partner') : ($selectedCustomer?->name ?: 'Select customer') }}</strong>
            </div>
            <div class="sales-metric">
                <span>Products</span>
                <strong id="saleItemsCountMetric">{{ count($initialSaleItems) }} line(s)</strong>
            </div>
            <div class="sales-metric">
                <span>Net Amount</span>
                <strong id="saleAmountMetric">Rs. 0.00</strong>
            </div>
        </div>
    </div>

    <div class="sales-card sale-step-card section-nav-target" id="sale-details-section" data-sale-step="delivery">
        <h2>Delivery & Fulfilment</h2>
        <p>Choose fulfilment source, delivery responsibility, and operational notes.</p>
        <div class="sales-grid">
            <div class="sales-field sales-col-4 {{ $errors->has('sale_date') ? 'is-error' : '' }}">
                <label for="sale_date">Sale Date</label>
                <input type="date" name="sale_date" id="sale_date" value="{{ old('sale_date', $sale?->sale_date ? \Carbon\Carbon::parse($sale->sale_date)->format('Y-m-d') : now()->format('Y-m-d')) }}" required>
                @error('sale_date')<div class="sales-field-error">{{ $message }}</div>@enderror
            </div>
            <div class="sales-field sales-col-4 {{ $errors->has('payment_status') ? 'is-error' : '' }}">
                <label for="payment_status">Payment Status</label>
                <select name="payment_status" id="payment_status" required>
                    <option value="pending" {{ old('payment_status', $sale->payment_status ?? 'pending') === 'pending' ? 'selected' : '' }}>Pending</option>
                    <option value="paid" {{ old('payment_status', $sale->payment_status ?? '') === 'paid' ? 'selected' : '' }}>Paid</option>
                    <option value="partial" {{ old('payment_status', $sale->payment_status ?? '') === 'partial' ? 'selected' : '' }}>Partial</option>
                    @if(old('payment_status', $sale->payment_status ?? '') === 'void')
                        <option value="void" selected>Void</option>
                    @endif
                </select>
                @error('payment_status')<div class="sales-field-error">{{ $message }}</div>@enderror
            </div>
            <div class="sales-field sales-col-4 {{ $errors->has('rental_id') ? 'is-error' : '' }}">
                <label for="rental_id">Linked Rental</label>
                <select name="rental_id" id="rental_id" data-searchable-select data-search-placeholder="Search rental by customer or rental number">
                    <option value="">No linked rental</option>
                    @foreach(($rentals ?? collect()) as $rental)
                        <option
                            value="{{ $rental->id }}"
                            data-search="{{ trim('Rental ' . $rental->id . ' ' . ($rental->customer?->name ?? $rental->customer_name ?? '') . ' ' . ($rental->product?->name ?? '')) }}"
                            data-customer-id="{{ $rental->customer_id }}"
                            data-customer-type="{{ $rental->customerTypeValue() }}"
                            data-business-partner-id="{{ $rental->business_partner_id }}"
                            data-partner-client-id="{{ $rental->partner_client_id }}"
                            {{ (int) $selectedRentalId === $rental->id ? 'selected' : '' }}>
                            Rental #{{ $rental->id }} - {{ $rental->billingContactName() }} - {{ $rental->product?->name ?? 'Product' }}
                        </option>
                    @endforeach
                </select>
                @error('rental_id')<div class="sales-field-error">{{ $message }}</div>@enderror
                <small>Optional link to an existing rental.</small>
            </div>
            <div class="sales-field sales-col-4 {{ $errors->has('fulfilment_source') ? 'is-error' : '' }}">
                <label for="sale_fulfilment_source">Fulfilment Source</label>
                <select name="fulfilment_source" id="sale_fulfilment_source">
                    <option value="in_house" {{ $selectedFulfilmentSource === 'in_house' ? 'selected' : '' }}>In-house Stock</option>
                    <option value="vendor_supplied" {{ $selectedFulfilmentSource === 'vendor_supplied' ? 'selected' : '' }}>Vendor Supplied</option>
                </select>
                <small>Vendor supplied skips PH stock reduction.</small>
                @error('fulfilment_source')<div class="sales-field-error">{{ $message }}</div>@enderror
            </div>
            <div class="sales-field sales-col-4 {{ $errors->has('vendor_id') ? 'is-error' : '' }}" id="saleVendorField"{{ $selectedFulfilmentSource === 'vendor_supplied' ? '' : ' hidden' }}>
                <label for="sale_vendor_id">Vendor</label>
                <select name="vendor_id" id="sale_vendor_id">
                    <option value="">Select vendor when vendor supplied</option>
                    @foreach($fulfilmentVendors as $vendor)
                        <option value="{{ $vendor->id }}" {{ $selectedFulfilmentVendorId === (int) $vendor->id ? 'selected' : '' }}>
                            {{ $vendor->name }}{{ $vendor->vendor_type ? ' - ' . $vendor->vendor_type : '' }}
                        </option>
                    @endforeach
                </select>
                @error('vendor_id')<div class="sales-field-error">{{ $message }}</div>@enderror
            </div>
            <div class="sales-field sales-col-4 {{ $errors->has('delivery_responsibility') ? 'is-error' : '' }}">
                <label for="sale_delivery_responsibility">Delivery Responsibility</label>
                <select name="delivery_responsibility" id="sale_delivery_responsibility">
                    <option value="ph_internal_delivery" {{ $selectedDeliveryResponsibility === 'ph_internal_delivery' ? 'selected' : '' }}>PH Internal Delivery</option>
                    <option value="vendor_delivery" {{ $selectedDeliveryResponsibility === 'vendor_delivery' ? 'selected' : '' }}>Vendor Delivery</option>
                    <option value="customer_pickup" {{ $selectedDeliveryResponsibility === 'customer_pickup' ? 'selected' : '' }}>Customer Pickup</option>
                </select>
                @error('delivery_responsibility')<div class="sales-field-error">{{ $message }}</div>@enderror
            </div>
            <div class="sales-field sales-col-12 {{ $errors->has('notes') ? 'is-error' : '' }}">
                <label for="notes">Sale Notes</label>
                <textarea name="notes" id="notes" rows="3" placeholder="Internal context, delivery note, or payment follow-up">{{ old('notes', $sale->notes ?? '') }}</textarea>
                @error('notes')<div class="sales-field-error">{{ $message }}</div>@enderror
            </div>
        </div>
        <div class="sale-step-footer">
            <button type="button" class="sales-btn-light" data-sale-step-prev="pricing">Previous</button>
            <button type="button" class="sales-btn" data-sale-step-next="review">Next: Review & Create</button>
        </div>
    </div>

    <div class="sales-card sale-step-card is-active section-nav-target" id="sale-customer-section" data-sale-step="customer">
        <h2>Customer</h2>
        <p>Select billing contact, actual client, city, phone, and referral source.</p>
        <div class="sales-grid">
            <div class="sales-col-12 party-flow-shell">
                <div class="party-flow-toggle-wrap">
                    <div class="sales-field {{ $errors->has('customer_type') ? 'is-error' : '' }}" style="gap:8px;">
                        <label for="customer_type">Customer Type</label>
                        <select name="customer_type" id="customer_type" class="party-flow-select-native">
                            <option value="direct_customer" {{ $selectedCustomerType === 'direct_customer' ? 'selected' : '' }}>Direct Customer</option>
                            <option value="business_partner" {{ $selectedCustomerType === 'business_partner' ? 'selected' : '' }}{{ $businessPartnerFlowAvailable ? '' : ' disabled' }}>Business Partner / Tie-up</option>
                        </select>
                        <div class="party-flow-toggle" role="tablist" aria-label="Customer type">
                            <button type="button" class="party-flow-option{{ $selectedCustomerType === 'direct_customer' ? ' is-active' : '' }}" data-sales-customer-type-option="direct_customer" aria-pressed="{{ $selectedCustomerType === 'direct_customer' ? 'true' : 'false' }}">Direct Customer</button>
                            <button type="button" class="party-flow-option{{ $selectedCustomerType === 'business_partner' ? ' is-active' : '' }}{{ $businessPartnerFlowAvailable ? '' : ' is-disabled' }}" data-sales-customer-type-option="business_partner" aria-pressed="{{ $selectedCustomerType === 'business_partner' ? 'true' : 'false' }}"{{ $businessPartnerFlowAvailable ? '' : ' aria-disabled="true" disabled' }}>Business Partner</button>
                        </div>
                        @error('customer_type')<div class="sales-field-error">{{ $message }}</div>@enderror
                    </div>
                        <div class="party-flow-hint">Partner is billed; actual client receives delivery.</div>
                    <div class="party-flow-admin-note"{{ $businessPartnerFlowAvailable ? ' hidden' : '' }}>
                        Business Partner setup pending. Run migrations to enable partner workflow.
                    </div>
                </div>

                <div class="party-flow-rows">
                    <div class="party-flow-row" data-sales-customer-mode="direct_customer">
                        <div class="sales-field {{ $errors->has('customer_id') ? 'is-error' : '' }}">
                            <label for="customer_id">Customer</label>
                            <select name="customer_id" id="customer_id" data-searchable-select data-search-placeholder="Search customer by name, phone, email, or city">
                                <option value="">Select customer</option>
                                @foreach($customers as $customer)
                                    <option
                                        value="{{ $customer->id }}"
                                        data-name="{{ $customer->name }}"
                                        data-phone="{{ $customer->phone }}"
                                        data-email="{{ $customer->email }}"
                                        data-address="{{ $customer->address }}"
                                        data-city="{{ $customer->city }}"
                                        data-state="{{ $customer->state }}"
                                        data-location="{{ $customer->openMapUrl() }}"
                                        data-search="{{ trim(implode(' ', array_filter([$customer->name, $customer->phone, $customer->email, $customer->city]))) }}"
                                        {{ $selectedCustomerId === $customer->id ? 'selected' : '' }}>
                                        {{ $customer->name }}{{ $customer->phone ? ' - ' . $customer->phone : '' }}
                                    </option>
                                @endforeach
                            </select>
                            @error('customer_id')<div class="sales-field-error">{{ $message }}</div>@enderror
                        </div>
                        <button type="button" class="party-flow-link" data-open-modal="saleQuickCustomerModal">+ Add</button>
                    </div>

                    <div class="party-flow-row" data-sales-customer-mode="business_partner">
                        <div class="sales-field {{ $errors->has('business_partner_id') ? 'is-error' : '' }}">
                            <label for="business_partner_id">Business Partner</label>
                            <select name="business_partner_id" id="business_partner_id" data-searchable-select data-search-placeholder="Search business partner by name, contact, phone, or city">
                                <option value="">Select business partner</option>
                                @foreach($businessPartners as $partner)
                                    <option
                                        value="{{ $partner->id }}"
                                        data-name="{{ $partner->displayName() }}"
                                        data-phone="{{ $partner->phone }}"
                                        data-email="{{ $partner->email }}"
                                        data-address="{{ $partner->address }}"
                                        data-city="{{ $partner->city }}"
                                        data-state="{{ $partner->state }}"
                                        data-billing-state="{{ $partner->billingStateValue() }}"
                                        data-location="{{ $partner->openMapUrl() }}"
                                        data-partner-clients-count="{{ (int) ($partner->partner_clients_count ?? 0) }}"
                                        data-search="{{ trim(implode(' ', array_filter([$partner->displayName(), $partner->contact_person, $partner->phone, $partner->email, $partner->city, $partner->state]))) }}"
                                        {{ $selectedBusinessPartnerId === $partner->id ? 'selected' : '' }}>
                                        {{ $partner->displayName() }}{{ $partner->phone ? ' - ' . $partner->phone : '' }}
                                    </option>
                                @endforeach
                            </select>
                            @error('business_partner_id')<div class="sales-field-error">{{ $message }}</div>@enderror
                        </div>
                        <button type="button" class="party-flow-link" data-open-modal="saleBusinessPartnerModal">+ Add</button>
                    </div>

                    <div class="party-flow-row" data-sales-customer-mode="business_partner" id="salePartnerClientRow">
                        <div class="sales-field {{ $errors->has('partner_client_id') ? 'is-error' : '' }}">
                            <label for="partner_client_id">Actual Client / Delivery Location</label>
                            <select name="partner_client_id" id="partner_client_id" data-searchable-select data-search-placeholder="Search actual client by name, phone, address, or city">
                                <option value="">Select actual client</option>
                                @foreach($initialPartnerClients as $client)
                                    <option
                                        value="{{ $client->id }}"
                                        data-business-partner-id="{{ $client->business_partner_id }}"
                                        data-name="{{ $client->displayName() }}"
                                        data-phone="{{ $client->primaryPhone() }}"
                                        data-email=""
                                        data-city="{{ $client->city }}"
                                        data-state="{{ $client->state }}"
                                        data-address="{{ $client->address }}"
                                        data-location="{{ $client->openMapUrl() }}"
                                        data-notes="{{ $client->delivery_notes }}"
                                        data-search="{{ trim(implode(' ', array_filter([$client->displayName(), $client->primaryPhone(), $client->address, $client->city, $client->state]))) }}"
                                        {{ $selectedPartnerClientId === $client->id ? 'selected' : '' }}>
                                        {{ $client->displayName() }}{{ $client->primaryPhone() ? ' - ' . $client->primaryPhone() : '' }}
                                    </option>
                                @endforeach
                            </select>
                            @error('partner_client_id')<div class="sales-field-error">{{ $message }}</div>@enderror
                        </div>
                        <button type="button" class="party-flow-link{{ $selectedBusinessPartner ? '' : ' is-disabled' }}" id="saleAddActualClientLink" data-open-modal="salePartnerClientModal" aria-disabled="{{ $selectedBusinessPartner ? 'false' : 'true' }}">+ Add</button>
                    </div>
                    <div class="party-flow-helper" id="salePartnerClientHelper" data-sales-customer-mode="business_partner"{{ $selectedPartnerClient ? ' hidden' : '' }}>
                        <strong id="salePartnerClientHelperTitle">{{ $selectedBusinessPartner ? 'Select or add an actual delivery client.' : 'Select a business partner to continue.' }}</strong>
                        <span id="salePartnerClientHelperText">{{ $selectedBusinessPartner ? (((int) ($selectedBusinessPartner->partner_clients_count ?? 0)) > 0 ? 'Choose the delivery or service client for this sale.' : 'No actual clients added for this business partner yet.') : 'The actual client will be used for delivery and service.' }}</span>
                    </div>
                </div>

                <div class="party-flow-summary" id="saleCustomerSummaryCard"{{ $shouldShowSaleCustomerSummary ? '' : ' hidden' }}>
                    <div class="party-flow-summary-head">
                        <span class="party-flow-summary-title" id="saleCustomerFlowSummaryTitle">{{ $selectedCustomerType === 'business_partner' ? 'Contact Routing' : 'Customer Summary' }}</span>
                        <span class="party-flow-badge" id="saleCustomerFlowChip">{{ $selectedCustomerType === 'business_partner' ? 'Business Partner' : 'Direct Customer' }}</span>
                    </div>
                    <div class="party-flow-lines">
                        <div class="party-flow-line">
                            <label id="saleReminderContactLabel">{{ $selectedCustomerType === 'business_partner' ? 'Reminder / Payment Contact' : 'Customer' }}</label>
                            <strong id="saleReminderContactSummary">{{ $selectedCustomerType === 'business_partner' ? (($selectedBusinessPartner?->displayName() ?: 'Select business partner') . (($selectedBusinessPartner?->phone) ? ' - ' . $selectedBusinessPartner->phone : '')) : (($selectedCustomer?->name ?: 'Select customer') . (($selectedCustomer?->phone) ? ' - ' . $selectedCustomer->phone : '')) }}</strong>
                        </div>
                        <div class="party-flow-line" id="saleDeliveryContactLine"{{ $selectedCustomerType === 'business_partner' ? '' : ' hidden' }}>
                            <label id="saleDeliveryContactLabel">Delivery / Service Contact</label>
                            <strong id="saleDeliveryContactSummary">{{ $selectedCustomerType === 'business_partner' ? (($selectedPartnerClient?->displayName() ?: 'Select actual client') . (($selectedPartnerClient?->primaryPhone()) ? ' - ' . $selectedPartnerClient->primaryPhone() : '')) : (($selectedCustomer?->name ?: 'Select customer') . (($selectedCustomer?->phone) ? ' - ' . $selectedCustomer->phone : '')) }}</strong>
                        </div>
                        <div class="party-flow-line">
                            <label id="saleDeliveryAddressLabel">{{ $selectedCustomerType === 'business_partner' ? 'Delivery Address' : 'Address' }}</label>
                            <strong id="saleDeliveryAddressSummary">{{ $selectedCustomerType === 'business_partner' ? collect([$selectedPartnerClient?->address, $selectedPartnerClient?->city, $selectedPartnerClient?->state])->filter()->join(', ') : collect([$selectedCustomer?->address, $selectedCustomer?->city, $selectedCustomer?->state])->filter()->join(', ') }}</strong>
                        </div>
                        <div class="party-flow-meta" id="saleDeliveryNotesSummary">{{ $selectedCustomerType === 'business_partner' ? ($selectedPartnerClient?->delivery_notes ?: '') : '' }}</div>
                    </div>
                    <div class="party-flow-summary-actions" id="saleDeliverySummaryActions"{{ (($selectedCustomerType === 'business_partner' ? ($selectedPartnerClient?->openMapUrl()) : ($selectedCustomer?->openMapUrl())) ? '' : ' hidden') }}>
                        <a
                            href="{{ $selectedCustomerType === 'business_partner' ? ($selectedPartnerClient?->openMapUrl() ?: '#') : ($selectedCustomer?->openMapUrl() ?: '#') }}"
                            target="_blank"
                            rel="noopener"
                            class="party-flow-summary-link"
                            id="saleDeliveryOpenMapLink"{{ (($selectedCustomerType === 'business_partner' ? ($selectedPartnerClient?->openMapUrl()) : ($selectedCustomer?->openMapUrl())) ? '' : ' hidden') }}>
                            Open Map
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="sales-referral-card" style="margin-top:14px;">
            <div>
                <h3>Referral</h3>
                <p>Track who introduced this sale for reporting and incentive review.</p>
            </div>
            <div class="sales-referral-grid">
                <div class="sales-field sales-col-3 {{ $errors->has('referral_source_type') ? 'is-error' : '' }}">
                    <label for="referral_source_type">Referral Source Type</label>
                    <select name="referral_source_type" id="referral_source_type">
                        @php($selectedReferralType = old('referral_source_type', $sale->referral_source_type ?? ''))
                        <option value="">No referral</option>
                        <option value="doctor" {{ $selectedReferralType === 'doctor' ? 'selected' : '' }}>Doctor</option>
                        <option value="hospital" {{ $selectedReferralType === 'hospital' ? 'selected' : '' }}>Hospital</option>
                        <option value="business_partner" {{ $selectedReferralType === 'business_partner' ? 'selected' : '' }}>Business Partner</option>
                        <option value="customer_referral" {{ $selectedReferralType === 'customer_referral' ? 'selected' : '' }}>Customer Referral</option>
                        <option value="employee" {{ $selectedReferralType === 'employee' ? 'selected' : '' }}>Employee</option>
                        <option value="digital_marketing" {{ $selectedReferralType === 'digital_marketing' ? 'selected' : '' }}>Digital Marketing</option>
                        <option value="walk_in" {{ $selectedReferralType === 'walk_in' ? 'selected' : '' }}>Walk-in</option>
                        <option value="other" {{ $selectedReferralType === 'other' ? 'selected' : '' }}>Other</option>
                    </select>
                    @error('referral_source_type')<div class="sales-field-error">{{ $message }}</div>@enderror
                </div>
                <div class="sales-field sales-col-3 {{ $errors->has('referral_source_id') || $errors->has('referral_source_name') ? 'is-error' : '' }}">
                    <label for="referral_source_name">Referral Source Name</label>
                    @php($selectedReferralName = old('referral_source_name', $sale->referral_source_name ?? ''))
                    @php($selectedReferralSourceId = (int) old('referral_source_id', $sale->referral_source_id ?? 0))
                    <select name="referral_source_id" id="referral_source_name" data-searchable-select data-search-placeholder="Search referral source">
                        <option value="">No referred person</option>
                        @foreach($referralSourceOptions as $option)
                            <option
                                value="{{ $option['id'] }}"
                                data-referral-type="{{ $option['type'] }}"
                                data-referral-name="{{ $option['name'] }}"
                                data-referral-contact="{{ $option['contact'] ?? '' }}"
                                data-referral-city="{{ $option['city'] ?? '' }}"
                                data-search="{{ trim(($option['label'] ?? $option['name']) . ' ' . ($option['contact'] ?? '') . ' ' . ($option['city'] ?? '') . ' ' . ($option['type'] ?? '')) }}"
                                {{ $selectedReferralSourceId === (int) $option['id'] ? 'selected' : '' }}>
                                {{ $option['label'] ?? $option['name'] }}
                            </option>
                        @endforeach
                        @if(filled($selectedReferralName) && !$selectedReferralSourceId)
                            <option value="" data-referral-name="{{ $selectedReferralName }}" selected>{{ $selectedReferralName }} (legacy)</option>
                        @endif
                    </select>
                    <input type="hidden" name="referral_source_name" id="referral_source_name_snapshot" value="{{ $selectedReferralName }}">
                    <a href="{{ route('referral-sources.create') }}" data-open-sale-referral-modal style="font-size:11px; font-weight:800; color:#2563eb; text-decoration:none;">+ Add referral source</a>
                    @error('referral_source_id')<div class="sales-field-error">{{ $message }}</div>@enderror
                    @error('referral_source_name')<div class="sales-field-error">{{ $message }}</div>@enderror
                </div>
                <div class="sales-field sales-col-3 {{ $errors->has('referral_contact') ? 'is-error' : '' }}">
                    <label for="referral_contact">Referral Contact</label>
                    <input type="text" name="referral_contact" id="referral_contact" value="{{ old('referral_contact', $sale->referral_contact ?? '') }}" placeholder="Phone or email">
                    @error('referral_contact')<div class="sales-field-error">{{ $message }}</div>@enderror
                </div>
                <div class="sales-field sales-col-3 {{ $errors->has('referral_city') ? 'is-error' : '' }}">
                    <label for="referral_city">Referral City</label>
                    <input type="text" name="referral_city" id="referral_city" value="{{ old('referral_city', $sale->referral_city ?? '') }}" placeholder="City">
                    @error('referral_city')<div class="sales-field-error">{{ $message }}</div>@enderror
                </div>
                <div class="sales-field sales-col-12 {{ $errors->has('referral_notes') ? 'is-error' : '' }}">
                    <label for="referral_notes">Referral Notes</label>
                    <textarea name="referral_notes" id="referral_notes" rows="2" placeholder="Incentive notes or referral context">{{ old('referral_notes', $sale->referral_notes ?? '') }}</textarea>
                    @error('referral_notes')<div class="sales-field-error">{{ $message }}</div>@enderror
                </div>
            </div>
        </div>
        <div class="sale-step-footer">
            <span></span>
            <button type="button" class="sales-btn" data-sale-step-next="products">Next: Products</button>
        </div>
    </div>

    <div class="sales-card sale-step-card section-nav-target" id="sale-products-section" data-sale-step="products">
        <h2>Products</h2>
        <p>Add one or many sale products. Existing rows stay intact when a new product is added.</p>

        <div class="sales-items-toolbar">
            <strong id="salesItemsToolbarSummary">{{ count($initialSaleItems) }} product line(s)</strong>
            <button type="button" class="sales-btn-light" id="addSaleItemButton">Add Product</button>
        </div>

        <div class="sales-item-list" id="saleItemsList"></div>
        <div class="sale-step-footer">
            <button type="button" class="sales-btn-light" data-sale-step-prev="customer">Previous</button>
            <button type="button" class="sales-btn" data-sale-step-next="pricing">Next: Pricing</button>
        </div>
    </div>

    <div class="sales-card sale-step-card section-nav-target" id="sale-pricing-section" data-sale-step="pricing">
        <h2>Pricing</h2>
        <p>Review subtotal, discount, GST, transport, and net amount.</p>
        <div class="sales-order-total">
            <div class="sales-pricing-net">
                <div style="color:#64748b; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em;">Sale Net Amount</div>
                <strong id="saleOrderTotal">Rs. 0.00</strong>
            </div>
            <div class="sales-field" style="min-width:180px; margin:0;">
                <label>Subtotal</label>
                <strong id="saleSubtotalSummary">Rs. 0.00</strong>
            </div>
            <div class="sales-field" style="min-width:180px; margin:0;">
                <label>Discount</label>
                <strong id="saleDiscountSummary">Rs. 0.00</strong>
            </div>
            <div class="sales-field" style="min-width:180px; margin:0;">
                <label>GST</label>
                <strong id="saleGstSummary">Rs. 0.00</strong>
            </div>
            <div class="sales-field sales-pricing-full {{ $errors->has('shipping_charges') ? 'is-error' : '' }}" style="min-width:220px; margin:0;">
                <label for="shipping_charges">Transport</label>
                <input type="number" step="0.01" min="0" name="shipping_charges" id="shipping_charges" value="{{ $initialShippingCharges }}">
                <small>Applied once to the sale.</small>
                @error('shipping_charges')<div class="sales-field-error">{{ $message }}</div>@enderror
            </div>
            <div class="sales-field sales-pricing-full" style="min-width:220px; margin:0;">
                <label for="sale_amount">Calculated Total</label>
                <input type="number" step="0.01" min="0" name="sale_amount" id="sale_amount" value="{{ old('sale_amount', $sale->sale_amount ?? 0) }}" readonly>
                <small>Products plus transport.</small>
            </div>
        </div>
        <div class="sale-step-footer">
            <button type="button" class="sales-btn-light" data-sale-step-prev="products">Previous</button>
            <button type="button" class="sales-btn" data-sale-step-next="delivery">Next: Delivery</button>
        </div>
    </div>


    <div class="sales-card sale-step-card section-nav-target" id="sale-save-section" data-sale-step="review">
        <h2>Review & Create</h2>
        <p>Confirm customer, referral, products, pricing, delivery, and fulfilment before saving.</p>
        <div class="sales-summary">
            <div class="sales-metric"><span>Customer</span><strong id="saleReviewCustomer">Select customer</strong></div>
            <div class="sales-metric"><span>Referral</span><strong id="saleReviewReferral">None</strong></div>
            <div class="sales-metric"><span>Products</span><strong id="saleReviewProducts">{{ count($initialSaleItems) }} line(s)</strong></div>
            <div class="sales-metric"><span>Pricing</span><strong id="saleReviewPricing">Rs. 0.00</strong></div>
            <div class="sales-metric"><span>Delivery</span><strong id="saleReviewDelivery">Not assigned</strong></div>
            <div class="sales-metric"><span>Fulfilment</span><strong id="saleReviewFulfilment">In-house</strong></div>
        </div>
        <div class="sale-step-warning" id="saleReviewWarnings"></div>
        <div class="sales-actions sale-step-footer">
            <button type="button" class="sales-btn-light" data-sale-step-prev="delivery">Previous</button>
            <a href="{{ route('sales.index') }}" class="sales-btn-light">Cancel</a>
            <button type="submit" form="saleForm" class="sales-btn sales-submit-btn" data-sale-submit>{{ $isEdit ? 'Update Sale' : 'Create Sale' }}</button>
        </div>
    </div>
</div>

<aside class="sale-summary-panel" data-collapsible>
    <button type="button" class="sales-btn-light" id="saleSummaryToggle" style="display:none;">Sale Summary</button>
    <div>
        <h2>Sale Summary</h2>
        <p>Live order snapshot</p>
    </div>
    <div class="sale-summary-body">
        <div class="sale-summary-line"><span>Customer</span><strong id="saleSummaryCustomer">Select customer</strong></div>
        <div class="sale-summary-line"><span>City</span><strong id="saleSummaryCity">Not set</strong></div>
        <div class="sale-summary-line"><span>Referral</span><strong id="saleSummaryReferral">None</strong></div>
        <div class="sale-summary-line"><span>Fulfilment</span><strong id="saleSummaryFulfilment">In-house</strong></div>
        <div class="sale-summary-line"><span>Products</span><strong id="saleSummaryProducts">{{ count($initialSaleItems) }} line(s)</strong></div>
        <div class="sale-summary-line"><span>Subtotal</span><strong id="saleSummarySubtotal">Rs. 0.00</strong></div>
        <div class="sale-summary-line"><span>Discount</span><strong id="saleSummaryDiscount">Rs. 0.00</strong></div>
        <div class="sale-summary-line"><span>GST</span><strong id="saleSummaryGst">Rs. 0.00</strong></div>
        <div class="sale-summary-line"><span>Transport</span><strong id="saleSummaryTransport">Rs. 0.00</strong></div>
        <div class="sale-summary-line"><span>Net Amount</span><strong id="saleSummaryNet">Rs. 0.00</strong></div>
        <div class="sale-summary-line"><span>Delivery</span><strong id="saleSummaryDelivery">Not assigned</strong></div>
        @if($canViewSaleProfitability)
            <div class="sale-summary-line"><span>Vendor Cost</span><strong id="saleSummaryVendorCost">After save</strong></div>
            <div class="sale-summary-line"><span>Margin</span><strong id="saleSummaryMargin">After save</strong></div>
        @endif
    </div>
</aside>
</div>
</div>



<div class="sales-modal-overlay" id="saleReferralSourceModal" aria-hidden="true">
    <div class="sales-modal-panel" role="dialog" aria-modal="true" aria-labelledby="saleReferralSourceModalTitle">
        <div class="sales-modal-head">
            <strong id="saleReferralSourceModalTitle">Add Referral Source</strong>
            <button type="button" class="sales-modal-close" data-close-sale-referral-modal aria-label="Close">x</button>
        </div>
        <div class="sales-modal-body">
            <div class="sales-modal-error" id="saleReferralSourceModalError"></div>
            <div class="sales-modal-grid">
                <div class="sales-field">
                    <label for="saleReferralQuickType">Source Type</label>
                    <select id="saleReferralQuickType">
                        <option value="">Select type</option>
                        <option value="doctor">Doctor</option>
                        <option value="hospital">Hospital</option>
                        <option value="business_partner">Business Partner</option>
                        <option value="customer_referral">Customer Referral</option>
                        <option value="employee">Employee</option>
                        <option value="digital_marketing">Digital Marketing</option>
                        <option value="walk_in">Walk-in</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="sales-field">
                    <label for="saleReferralQuickName">Referral Name</label>
                    <input type="text" id="saleReferralQuickName" placeholder="Doctor, hospital, partner, or person" required>
                </div>
                <div class="sales-field">
                    <label for="saleReferralQuickContact">Contact</label>
                    <input type="text" id="saleReferralQuickContact" placeholder="Phone or email">
                </div>
                <div class="sales-field">
                    <label for="saleReferralQuickCity">City</label>
                    <input type="text" id="saleReferralQuickCity" placeholder="City">
                </div>
                <div class="sales-field" style="grid-column:1 / -1;">
                    <label for="saleReferralQuickNotes">Notes</label>
                    <textarea id="saleReferralQuickNotes" rows="2" placeholder="Optional referral context"></textarea>
                </div>
            </div>
            <div class="sales-actions" style="justify-content:flex-end;">
                <button type="button" class="sales-btn-light" data-close-sale-referral-modal>Cancel</button>
                <button type="button" class="sales-btn" data-save-sale-referral-source>Save Referral</button>
            </div>
        </div>
    </div>
</div>
<div class="sales-stock-popup" id="saleStockPopup" aria-hidden="true">
    <div class="sales-stock-popup-panel" role="dialog" aria-modal="true" aria-labelledby="saleStockPopupTitle">
        <div class="sales-stock-popup-head">
            <strong id="saleStockPopupTitle">Add Sale Stock</strong>
            <button type="button" class="sales-btn-light" data-close-sale-stock-popup>Close</button>
        </div>
        <iframe class="sales-stock-popup-frame" id="saleStockPopupFrame" title="Add Sale Stock"></iframe>
    </div>
</div>
<div class="sale-mobile-sticky-footer" id="saleMobileStickyFooter" data-current-step="customer">
    <div class="sale-mobile-sticky-meta">
        <span><strong id="saleMobileStickyItems">{{ count($initialSaleItems) }}</strong> Items</span>
        <span><strong id="saleMobileStickyTotal">Rs. 0.00</strong> Total</span>
    </div>
    <div class="sale-mobile-sticky-actions">
        <button type="button" class="sales-btn-light" id="saleMobileBackButton">Back</button>
        <button type="button" class="sales-btn" id="saleMobileNextButton">Continue</button>
        <button type="submit" form="saleForm" class="sales-btn sales-submit-btn" id="saleMobileSaveButton" data-sale-submit hidden>{{ $isEdit ? 'Update Sale' : 'Create Sale' }}</button>
    </div>
</div>

    <script>
    (function () {
        const initialSaleItems = @json(array_values($initialSaleItems));
        const products = @json($productData);
        const assets = @json($assetData);
        const organizationState = @json($organizationState);
        const partnerClientEndpointTemplate = @json($businessPartnerFlowAvailable ? route('sales.business-partners.actual-clients', ['business_partner' => '__PARTNER__']) : null);
        const addSaleStockUrlTemplate = @json(route('assets.create', ['asset_stage' => 'new_stock', 'product_id' => '__PRODUCT__']));
        const referralQuickStoreUrl = @json(route('referral-sources.quick-store'));

        const customerTypeSelect = document.getElementById('customer_type');
        const customerTypeButtons = Array.from(document.querySelectorAll('[data-sales-customer-type-option]'));
        const customerSelect = document.getElementById('customer_id');
        const businessPartnerSelect = document.getElementById('business_partner_id');
        const partnerClientSelect = document.getElementById('partner_client_id');
        const saleAddActualClientLink = document.getElementById('saleAddActualClientLink');
        const customerModeBlocks = Array.from(document.querySelectorAll('[data-sales-customer-mode]'));
        const salePartnerClientRow = document.getElementById('salePartnerClientRow');
        const rentalSelect = document.getElementById('rental_id');
        const saleItemsList = document.getElementById('saleItemsList');
        const addSaleItemButton = document.getElementById('addSaleItemButton');
        const saleAmountInput = document.getElementById('sale_amount');
        const saleOrderTotal = document.getElementById('saleOrderTotal');
        const saleAmountMetric = document.getElementById('saleAmountMetric');
        const saleCustomerMetric = document.getElementById('saleCustomerMetric');
        const saleItemsCountMetric = document.getElementById('saleItemsCountMetric');
        const salesItemsToolbarSummary = document.getElementById('salesItemsToolbarSummary');
        const saleStepButtons = Array.from(document.querySelectorAll('[data-sale-step-button]'));
        const saleStepCards = Array.from(document.querySelectorAll('[data-sale-step]'));
        const saleStepNextButtons = Array.from(document.querySelectorAll('[data-sale-step-next]'));
        const saleStepPrevButtons = Array.from(document.querySelectorAll('[data-sale-step-prev]'));
        const saleSummaryToggle = document.getElementById('saleSummaryToggle');
        const saleSummaryPanel = document.querySelector('.sale-summary-panel');
        const saleMobileStickyFooter = document.getElementById('saleMobileStickyFooter');
        const saleMobileStickyItems = document.getElementById('saleMobileStickyItems');
        const saleMobileStickyTotal = document.getElementById('saleMobileStickyTotal');
        const saleMobileBackButton = document.getElementById('saleMobileBackButton');
        const saleMobileNextButton = document.getElementById('saleMobileNextButton');
        const saleMobileSaveButton = document.getElementById('saleMobileSaveButton');
        const saleShippingInput = document.getElementById('shipping_charges');
        const saleFulfilmentSource = document.getElementById('sale_fulfilment_source');
        const saleVendorField = document.getElementById('saleVendorField');
        const saleVendorSelect = document.getElementById('sale_vendor_id');
        const saleDeliveryResponsibilitySelect = document.getElementById('sale_delivery_responsibility');
        const referralTypeSelect = document.getElementById('referral_source_type');
        const referralNameInput = document.getElementById('referral_source_name');
        const referralNameSnapshotInput = document.getElementById('referral_source_name_snapshot');
        const referralContactInput = document.getElementById('referral_contact');
        const referralCityInput = document.getElementById('referral_city');
        const saleReferralSourceModal = document.getElementById('saleReferralSourceModal');
        const saleReferralQuickType = document.getElementById('saleReferralQuickType');
        const saleReferralQuickName = document.getElementById('saleReferralQuickName');
        const saleReferralQuickContact = document.getElementById('saleReferralQuickContact');
        const saleReferralQuickCity = document.getElementById('saleReferralQuickCity');
        const saleReferralQuickNotes = document.getElementById('saleReferralQuickNotes');
        const saleReferralQuickError = document.getElementById('saleReferralSourceModalError');
        const saleReferralQuickSave = document.querySelector('[data-save-sale-referral-source]');
        const summaryEls = {
            customer: document.getElementById('saleSummaryCustomer'),
            city: document.getElementById('saleSummaryCity'),
            referral: document.getElementById('saleSummaryReferral'),
            fulfilment: document.getElementById('saleSummaryFulfilment'),
            products: document.getElementById('saleSummaryProducts'),
            subtotal: document.getElementById('saleSummarySubtotal'),
            discount: document.getElementById('saleSummaryDiscount'),
            gst: document.getElementById('saleSummaryGst'),
            transport: document.getElementById('saleSummaryTransport'),
            net: document.getElementById('saleSummaryNet'),
            delivery: document.getElementById('saleSummaryDelivery'),
            reviewCustomer: document.getElementById('saleReviewCustomer'),
            reviewReferral: document.getElementById('saleReviewReferral'),
            reviewProducts: document.getElementById('saleReviewProducts'),
            reviewPricing: document.getElementById('saleReviewPricing'),
            reviewDelivery: document.getElementById('saleReviewDelivery'),
            reviewFulfilment: document.getElementById('saleReviewFulfilment'),
            reviewWarnings: document.getElementById('saleReviewWarnings'),
            subtotalInline: document.getElementById('saleSubtotalSummary'),
            discountInline: document.getElementById('saleDiscountSummary'),
            gstInline: document.getElementById('saleGstSummary'),
        };
        const saleCustomerSummaryCard = document.getElementById('saleCustomerSummaryCard');
        const saleCustomerFlowSummaryTitle = document.getElementById('saleCustomerFlowSummaryTitle');
        const saleCustomerFlowChip = document.getElementById('saleCustomerFlowChip');
        const saleReminderContactLabel = document.getElementById('saleReminderContactLabel');
        const saleReminderContactSummary = document.getElementById('saleReminderContactSummary');
        const saleDeliveryContactLine = document.getElementById('saleDeliveryContactLine');
        const saleDeliveryContactLabel = document.getElementById('saleDeliveryContactLabel');
        const saleDeliveryContactSummary = document.getElementById('saleDeliveryContactSummary');
        const saleDeliveryAddressLabel = document.getElementById('saleDeliveryAddressLabel');
        const saleDeliveryAddressSummary = document.getElementById('saleDeliveryAddressSummary');
        const saleDeliveryNotesSummary = document.getElementById('saleDeliveryNotesSummary');
        const saleDeliverySummaryActions = document.getElementById('saleDeliverySummaryActions');
        const saleDeliveryOpenMapLink = document.getElementById('saleDeliveryOpenMapLink');
        const salePartnerClientHelper = document.getElementById('salePartnerClientHelper');
        const salePartnerClientHelperTitle = document.getElementById('salePartnerClientHelperTitle');
        const salePartnerClientHelperText = document.getElementById('salePartnerClientHelperText');
        const saleForm = document.getElementById('saleForm') || document.currentScript?.closest('form');
        const saleSubmitButtons = Array.from(document.querySelectorAll('[data-sale-submit]'));
        let saleSubmitInProgress = false;

        function setSaleSubmitting(isSubmitting) {
            saleSubmitButtons.forEach(function (button) {
                if (!button) return;
                if (!button.dataset.defaultLabel) {
                    button.dataset.defaultLabel = button.textContent.trim();
                }
                button.disabled = isSubmitting;
                button.classList.toggle('is-loading', isSubmitting);
                button.textContent = isSubmitting ? 'Creating...' : button.dataset.defaultLabel;
            });
        }

        function submitSaleForm(button) {
            const formId = button?.getAttribute('form') || 'saleForm';
            const form = document.getElementById(formId) || saleForm;

            if (!form || saleSubmitInProgress) {
                return;
            }

            saleSubmitInProgress = true;
            setSaleSubmitting(true);

            if (typeof form.requestSubmit === 'function') {
                try {
                    form.requestSubmit(button || undefined);
                } catch (error) {
                    form.requestSubmit();
                }
                return;
            }

            form.submit();
        }

        saleSubmitButtons.forEach(function (button) {
            button.addEventListener('click', function (event) {
                event.preventDefault();
                submitSaleForm(button);
            });
        });

        saleForm?.addEventListener('submit', function () {
            saleSubmitInProgress = true;
            setSaleSubmitting(true);
        });

        window.addEventListener('pageshow', function () {
            saleSubmitInProgress = false;
            setSaleSubmitting(false);
        });
        const standardGstRates = ['0.00', '5.00', '12.00', '18.00', '28.00'];

        const productMap = new Map(products.map(function (product) { return [parseInt(product.id, 10), product]; }));
        const assetMap = new Map(assets.map(function (asset) { return [parseInt(asset.id, 10), asset]; }));
        const partnerClientCache = new Map();
        let partnerClientRequestToken = 0;
        const saleStepOrder = ['customer', 'products', 'pricing', 'delivery', 'review'];
        let currentSaleStep = 'customer';

        let saleItems = initialSaleItems.length ? initialSaleItems.map(normalizeItem) : [defaultSaleItem()];

        function defaultSaleItem() {
            return {
                product_id: null,
                asset_id: null,
                quantity: 1,
                unit_price: '0.00',
                discount_amount: '0.00',
                tax_percentage: '0.00',
                tax_calculation_mode: 'exclusive',
                tax_type: recommendedTaxType(),
                tax_type_auto: true,
                notes: ''
            };
        }

        function syncSaleFulfilmentControls() {
            const vendorSupplied = saleFulfilmentSource?.value === 'vendor_supplied';

            if (saleVendorField) {
                saleVendorField.hidden = !vendorSupplied;
            }

            if (saleVendorSelect) {
                saleVendorSelect.disabled = !vendorSupplied;
                if (!vendorSupplied) {
                    saleVendorSelect.value = '';
                }
            }

            if (!vendorSupplied && saleDeliveryResponsibilitySelect) {
                saleDeliveryResponsibilitySelect.value = 'ph_internal_delivery';
            }

            updateSaleSummary();
            filterLinkedRentalOptions();
        }

        function normalizeItem(item) {
            const hasTaxType = ['cgst_sgst', 'igst'].includes(item.tax_type);

            return {
                product_id: item.product_id ? parseInt(item.product_id, 10) : null,
                asset_id: item.asset_id ? parseInt(item.asset_id, 10) : null,
                quantity: Math.max(parseInt(item.quantity || 1, 10), 1),
                unit_price: normalizeMoney(item.unit_price),
                discount_amount: normalizeMoney(item.discount_amount),
                tax_percentage: normalizeMoney(item.tax_percentage),
                tax_calculation_mode: item.tax_calculation_mode === 'inclusive' ? 'inclusive' : 'exclusive',
                tax_type: hasTaxType ? item.tax_type : recommendedTaxType(),
                tax_type_auto: !hasTaxType,
                notes: item.notes || ''
            };
        }

        function normalizeMoney(value) {
            const parsed = parseFloat(value || 0);
            return Number.isNaN(parsed) ? '0.00' : parsed.toFixed(2);
        }

        function formatGstLabel(value) {
            const normalized = normalizeMoney(value);
            return `${normalized.replace(/\.00$/, '').replace(/(\.\d*[1-9])0+$/, '$1')}%`;
        }

        function normalizeStateName(value) {
            return String(value || '').trim().toLowerCase();
        }

        function customerMode() {
            return customerTypeSelect?.value === 'business_partner' ? 'business_partner' : 'direct_customer';
        }

        function syncCustomerTypeButtons() {
            const activeMode = customerMode();

            customerTypeButtons.forEach(function (button) {
                const isActive = button.getAttribute('data-sales-customer-type-option') === activeMode;
                button.classList.toggle('is-active', isActive);
                button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            });
        }

        function selectedCustomerData() {
            const option = customerSelect?.selectedOptions?.[0];

            if (!option || !customerSelect?.value) {
                return null;
            }

            return {
                id: parseInt(option.value, 10),
                name: option.getAttribute('data-name') || option.textContent.trim(),
                phone: option.getAttribute('data-phone') || '',
                email: option.getAttribute('data-email') || '',
                address: option.getAttribute('data-address') || '',
                city: option.getAttribute('data-city') || '',
                state: option.getAttribute('data-state') || '',
                location: option.getAttribute('data-location') || '',
            };
        }

        function selectedBusinessPartnerData() {
            const option = businessPartnerSelect?.selectedOptions?.[0];

            if (!option || !businessPartnerSelect?.value) {
                return null;
            }

            return {
                id: parseInt(option.value, 10),
                name: option.getAttribute('data-name') || option.textContent.trim(),
                phone: option.getAttribute('data-phone') || '',
                email: option.getAttribute('data-email') || '',
                address: option.getAttribute('data-address') || '',
                city: option.getAttribute('data-city') || '',
                state: option.getAttribute('data-state') || '',
                billing_state: option.getAttribute('data-billing-state') || option.getAttribute('data-state') || '',
                location: option.getAttribute('data-location') || '',
                partner_clients_count: parseInt(option.getAttribute('data-partner-clients-count') || '0', 10) || 0,
            };
        }

        function selectedPartnerClientData() {
            const option = partnerClientSelect?.selectedOptions?.[0];
            if (!option || !partnerClientSelect?.value) {
                return null;
            }

            return {
                id: parseInt(option.value, 10),
                business_partner_id: parseInt(option.getAttribute('data-business-partner-id') || '0', 10) || null,
                name: option.getAttribute('data-name') || option.textContent.trim(),
                phone: option.getAttribute('data-phone') || '',
                address: option.getAttribute('data-address') || '',
                city: option.getAttribute('data-city') || '',
                state: option.getAttribute('data-state') || '',
                location: option.getAttribute('data-location') || '',
                delivery_notes: option.getAttribute('data-notes') || '',
            };
        }

        function partnerClientEndpoint(partnerId) {
            if (!partnerClientEndpointTemplate || !partnerId) {
                return null;
            }

            return partnerClientEndpointTemplate.replace('__PARTNER__', String(partnerId));
        }

        function recommendedTaxType(customerStateValue) {
            const customerState = normalizeStateName(
                customerStateValue
                || (customerMode() === 'business_partner'
                    ? (selectedBusinessPartnerData()?.billing_state || selectedPartnerClientData()?.state || selectedBusinessPartnerData()?.state)
                    : selectedCustomerData()?.state)
            );
            const orgState = normalizeStateName(organizationState);

            if (customerState && orgState && customerState !== orgState) {
                return 'igst';
            }

            return 'cgst_sgst';
        }

        function gstOptionsHtml(currentValue) {
            const normalized = normalizeMoney(currentValue);
            const options = standardGstRates.includes(normalized)
                ? [...standardGstRates]
                : [...standardGstRates, normalized].sort(function (left, right) {
                    return parseFloat(left) - parseFloat(right);
                });

            return options.map(function (option) {
                return `<option value="${escapeHtml(option)}"${option === normalized ? ' selected' : ''}>${escapeHtml(formatGstLabel(option))}</option>`;
            }).join('');
        }

        function taxTypeOptionsHtml(currentValue) {
            const normalized = currentValue === 'igst' ? 'igst' : 'cgst_sgst';

            return `
                <option value="cgst_sgst"${normalized === 'cgst_sgst' ? ' selected' : ''}>CGST + SGST</option>
                <option value="igst"${normalized === 'igst' ? ' selected' : ''}>IGST</option>
            `;
        }

        function formatCurrency(value) {
            return new Intl.NumberFormat('en-IN', {
                style: 'currency',
                currency: 'INR',
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }).format(value || 0);
        }

        function escapeHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function optionProductName(option) {
            return option?.getAttribute('data-product-name') || option?.textContent?.trim() || '';
        }

        function optionProductInitial(option) {
            return String(optionProductName(option) || 'P').trim().charAt(0).toUpperCase() || 'P';
        }

        function optionProductThumbHtml(option) {
            const imageUrl = option?.getAttribute('data-product-image-url') || '';
            const initial = escapeHtml(optionProductInitial(option));

            if (!imageUrl) {
                return `<span class="product-option-thumb" aria-hidden="true">${initial}</span>`;
            }

            return `<span class="product-option-thumb" aria-hidden="true"><img src="${escapeHtml(imageUrl)}" alt="" onerror="this.parentElement.textContent='${initial}'"></span>`;
        }

        function optionHasProductMedia(select, option) {
            return Boolean(option?.value) && Boolean(optionProductName(option)) && String(select?.name || '').includes('[product_id]');
        }

        function productOptionMarkup(select, option) {
            if (!optionHasProductMedia(select, option)) {
                return escapeHtml(option.textContent.trim());
            }

            const meta = option.getAttribute('data-product-meta') || [
                option.getAttribute('data-product-brand'),
                option.getAttribute('data-product-model'),
                option.getAttribute('data-product-sku'),
                option.getAttribute('data-product-code'),
            ].filter(Boolean).join(' | ');
            const availability = option.getAttribute('data-availability-label') || '';

            return `<span class="product-option-media">${optionProductThumbHtml(option)}<span class="product-option-copy"><strong>${escapeHtml(optionProductName(option))}</strong>${meta ? `<span>${escapeHtml(meta)}</span>` : ''}${availability ? `<em>${escapeHtml(availability)}</em>` : ''}</span></span>`;
        }
        function selectedOption(select) {
            return select && select.selectedIndex >= 0 ? select.options[select.selectedIndex] : null;
        }

        function enhanceSearchableSelect(select) {
            if (!select || select.dataset.searchableEnhanced === 'true') {
                return;
            }

            select.dataset.searchableEnhanced = 'true';
            select.classList.add('searchable-select-native');

            const wrapper = document.createElement('div');
            wrapper.className = 'searchable-select';

            const trigger = document.createElement('button');
            trigger.type = 'button';
            trigger.className = 'searchable-select-trigger';
            trigger.setAttribute('aria-haspopup', 'listbox');
            trigger.setAttribute('aria-expanded', 'false');

            const triggerLabel = document.createElement('span');
            trigger.appendChild(triggerLabel);

            const panel = document.createElement('div');
            panel.className = 'searchable-select-panel';
            panel.hidden = true;

            const searchInput = document.createElement('input');
            searchInput.type = 'search';
            searchInput.className = 'searchable-select-search';
            searchInput.placeholder = select.getAttribute('data-search-placeholder') || 'Search options';

            const optionsWrap = document.createElement('div');
            optionsWrap.className = 'searchable-select-options';

            panel.appendChild(searchInput);
            panel.appendChild(optionsWrap);

            select.insertAdjacentElement('afterend', wrapper);
            wrapper.appendChild(trigger);
            wrapper.appendChild(panel);

            function syncTriggerLabel() {
                const selected = select.options[select.selectedIndex];
                triggerLabel.textContent = selected ? selected.textContent.trim() : 'Select option';
            }

            function closePanel() {
                wrapper.classList.remove('is-open');
                panel.hidden = true;
                trigger.setAttribute('aria-expanded', 'false');
            }

            function renderOptions() {
                const query = searchInput.value.trim().toLowerCase();
                optionsWrap.innerHTML = '';
                let count = 0;

                Array.from(select.options).forEach(function (option) {
                    if (option.hidden) {
                        return;
                    }
                    const searchText = (option.getAttribute('data-search') || option.textContent || '').toLowerCase();
                    if (query && !searchText.includes(query)) {
                        return;
                    }

                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'searchable-select-option' + (option.selected ? ' is-selected' : '');
                    button.classList.toggle('has-product-media', optionHasProductMedia(select, option));
                    button.innerHTML = productOptionMarkup(select, option);
                    button.setAttribute('aria-label', option.textContent.trim());
                    button.addEventListener('click', function () {
                        select.value = option.value;
                        select.dispatchEvent(new Event('change', { bubbles: true }));
                        syncTriggerLabel();
                        closePanel();
                        trigger.focus();
                    });
                    optionsWrap.appendChild(button);
                    count += 1;
                });

                if (!count) {
                    const empty = document.createElement('div');
                    empty.className = 'searchable-select-empty';
                    empty.textContent = 'No matching options';
                    optionsWrap.appendChild(empty);
                }
            }

            function refresh() {
                syncTriggerLabel();
                renderOptions();
            }

            trigger.addEventListener('click', function () {
                const opening = panel.hidden;
                document.querySelectorAll('.searchable-select.is-open').forEach(function (openWrapper) {
                    if (openWrapper !== wrapper) {
                        openWrapper.classList.remove('is-open');
                        const openPanel = openWrapper.querySelector('.searchable-select-panel');
                        const openTrigger = openWrapper.querySelector('.searchable-select-trigger');
                        if (openPanel) {
                            openPanel.hidden = true;
                        }
                        if (openTrigger) {
                            openTrigger.setAttribute('aria-expanded', 'false');
                        }
                    }
                });

                if (!opening) {
                    closePanel();
                    return;
                }

                wrapper.classList.add('is-open');
                panel.hidden = false;
                trigger.setAttribute('aria-expanded', 'true');
                searchInput.value = '';
                renderOptions();
                window.requestAnimationFrame(function () {
                    searchInput.focus();
                });
            });

            searchInput.addEventListener('input', renderOptions);
            select.addEventListener('change', refresh);

            document.addEventListener('click', function (event) {
                if (!wrapper.contains(event.target)) {
                    closePanel();
                }
            });

            const observer = new MutationObserver(refresh);
            observer.observe(select, { childList: true, subtree: true, attributes: true, attributeFilter: ['selected'] });

            select._searchableSelect = { refresh: refresh };
            refresh();
        }

        if (businessPartnerSelect?.value && partnerClientSelect) {
            const seededClients = Array.from(partnerClientSelect.options)
                .slice(1)
                .map(function (option) {
                    return {
                        id: option.value,
                        business_partner_id: option.getAttribute('data-business-partner-id') || businessPartnerSelect.value,
                        name: option.getAttribute('data-name') || option.textContent.trim(),
                        phone: option.getAttribute('data-phone') || '',
                        address: option.getAttribute('data-address') || '',
                        city: option.getAttribute('data-city') || '',
                        state: option.getAttribute('data-state') || '',
                        location: option.getAttribute('data-location') || '',
                        delivery_notes: option.getAttribute('data-notes') || '',
                        search: option.getAttribute('data-search') || '',
                    };
                });

            partnerClientCache.set(parseInt(businessPartnerSelect.value, 10), seededClients);
        }

        function lineCommercials(item) {
            const quantity = Math.max(parseFloat(item.quantity || 0), 1);
            const unitPrice = Math.max(parseFloat(item.unit_price || 0), 0);
            const discount = Math.max(parseFloat(item.discount_amount || 0), 0);
            const taxPercentage = Math.max(parseFloat(item.tax_percentage || 0), 0);
            const mode = item.tax_calculation_mode === 'inclusive' ? 'inclusive' : 'exclusive';

            const subtotal = quantity * unitPrice;
            const taxableBase = Math.max(subtotal - discount, 0);
            let total = taxableBase;

            if (mode === 'exclusive' && taxPercentage > 0) {
                total += taxableBase * (taxPercentage / 100);
            }

            return total;
        }

        function lineBreakdown(item) {
            const quantity = Math.max(parseFloat(item.quantity || 0), 1);
            const unitPrice = Math.max(parseFloat(item.unit_price || 0), 0);
            const discount = Math.max(parseFloat(item.discount_amount || 0), 0);
            const taxPercentage = Math.max(parseFloat(item.tax_percentage || 0), 0);
            const mode = item.tax_calculation_mode === 'inclusive' ? 'inclusive' : 'exclusive';
            const subtotal = quantity * unitPrice;
            const taxableBase = Math.max(subtotal - discount, 0);
            let gst = 0;

            if (taxPercentage > 0) {
                if (mode === 'inclusive') {
                    gst = taxableBase - (taxableBase / (1 + (taxPercentage / 100)));
                } else {
                    gst = taxableBase * (taxPercentage / 100);
                }
            }

            return {
                subtotal,
                discount,
                gst,
                total: lineCommercials(item),
            };
        }

        function updateSaleSummary() {
            const totals = saleItems.reduce(function (carry, item) {
                const breakdown = lineBreakdown(item);
                carry.subtotal += breakdown.subtotal;
                carry.discount += breakdown.discount;
                carry.gst += breakdown.gst;
                carry.productTotal += breakdown.total;
                return carry;
            }, { subtotal: 0, discount: 0, gst: 0, productTotal: 0 });
            const productTotal = totals.productTotal;
            const shipping = Math.max(parseFloat((saleShippingInput && saleShippingInput.value) || 0), 0);
            const total = productTotal + shipping;

            saleAmountInput.value = total.toFixed(2);
            saleOrderTotal.textContent = formatCurrency(total);
            saleAmountMetric.textContent = formatCurrency(total);
            saleItemsCountMetric.textContent = saleItems.length + ' line(s)';
            salesItemsToolbarSummary.textContent = saleItems.length + ' product line(s)';
            if (saleMobileStickyItems) {
                saleMobileStickyItems.textContent = saleItems.length;
            }
            if (saleMobileStickyTotal) {
                saleMobileStickyTotal.textContent = formatCurrency(total);
            }
            updateLiveSummary(totals, shipping, total);
        }

        function selectedOption(select) {
            return select?.selectedOptions?.[0] || null;
        }

        function readable(value) {
            return (value || '').replace(/_/g, ' ').replace(/\b\w/g, function (letter) {
                return letter.toUpperCase();
            });
        }

        function summaryCustomerData() {
            const mode = customerMode();
            const option = mode === 'business_partner' ? selectedOption(businessPartnerSelect) : selectedOption(customerSelect);
            const clientOption = selectedOption(partnerClientSelect);
            const activeOption = mode === 'business_partner' ? (clientOption || option) : option;

            return {
                name: option?.dataset?.name || option?.textContent?.trim() || '',
                city: activeOption?.dataset?.city || '',
                phone: activeOption?.dataset?.phone || '',
            };
        }

        function updateLiveSummary(totals = null, shipping = null, total = null) {
            if (!totals) {
                totals = saleItems.reduce(function (carry, item) {
                    const breakdown = lineBreakdown(item);
                    carry.subtotal += breakdown.subtotal;
                    carry.discount += breakdown.discount;
                    carry.gst += breakdown.gst;
                    carry.productTotal += breakdown.total;
                    return carry;
                }, { subtotal: 0, discount: 0, gst: 0, productTotal: 0 });
            }

            shipping = shipping ?? Math.max(parseFloat((saleShippingInput && saleShippingInput.value) || 0), 0);
            total = total ?? (totals.productTotal + shipping);

            const customerData = summaryCustomerData();
            const selectedReferral = selectedOption(referralNameInput);
            const referral = selectedReferral && selectedReferral.value
                ? (selectedReferral.getAttribute('data-referral-name') || selectedReferral.textContent.trim() || 'None')
                : (referralNameSnapshotInput?.value || readable(referralTypeSelect?.value) || 'None');
            const fulfilment = readable(saleFulfilmentSource?.value || 'in_house');
            const delivery = readable(saleDeliveryResponsibilitySelect?.value || 'not_assigned');
            const selectedSaleItems = saleItems.filter(function (item) {
                return item.product_id;
            });
            const firstProduct = selectedSaleItems[0]
                ? productMap.get(parseInt(selectedSaleItems[0].product_id, 10))
                : null;
            const productCount = selectedSaleItems.length
                ? (firstProduct?.name || 'Selected product') + (selectedSaleItems.length > 1 ? ' + ' + (selectedSaleItems.length - 1) + ' more' : '')
                : 'No product selected';

            if (summaryEls.customer) summaryEls.customer.textContent = customerData.name || 'Select customer';
            if (summaryEls.city) summaryEls.city.textContent = customerData.city || 'Not set';
            if (summaryEls.referral) summaryEls.referral.textContent = referral;
            if (summaryEls.fulfilment) summaryEls.fulfilment.textContent = fulfilment;
            if (summaryEls.products) summaryEls.products.textContent = productCount;
            if (summaryEls.subtotal) summaryEls.subtotal.textContent = formatCurrency(totals.subtotal);
            if (summaryEls.discount) summaryEls.discount.textContent = formatCurrency(totals.discount);
            if (summaryEls.gst) summaryEls.gst.textContent = formatCurrency(totals.gst);
            if (summaryEls.transport) summaryEls.transport.textContent = formatCurrency(shipping);
            if (summaryEls.net) summaryEls.net.textContent = formatCurrency(total);
            if (summaryEls.delivery) summaryEls.delivery.textContent = delivery;
            if (summaryEls.reviewCustomer) summaryEls.reviewCustomer.textContent = customerData.name || 'Missing';
            if (summaryEls.reviewReferral) summaryEls.reviewReferral.textContent = referral;
            if (summaryEls.reviewProducts) summaryEls.reviewProducts.textContent = productCount;
            if (summaryEls.reviewPricing) summaryEls.reviewPricing.textContent = formatCurrency(total);
            if (summaryEls.reviewDelivery) summaryEls.reviewDelivery.textContent = delivery;
            if (summaryEls.reviewFulfilment) summaryEls.reviewFulfilment.textContent = fulfilment;
            if (summaryEls.subtotalInline) summaryEls.subtotalInline.textContent = formatCurrency(totals.subtotal);
            if (summaryEls.discountInline) summaryEls.discountInline.textContent = formatCurrency(totals.discount);
            if (summaryEls.gstInline) summaryEls.gstInline.textContent = formatCurrency(totals.gst);

            updateWizardCompletion();
            updateReviewWarnings();
        }

        function updateReviewWarnings() {
            if (!summaryEls.reviewWarnings) {
                return;
            }

            const warnings = [];
            if (!customerSelect?.value && customerMode() === 'direct_customer') warnings.push('No customer selected.');
            if (customerMode() === 'business_partner' && (!businessPartnerSelect?.value || !partnerClientSelect?.value)) warnings.push('Business partner or actual client missing.');
            if (!saleItems.some((item) => item.product_id)) warnings.push('No product selected.');
            if (!saleDeliveryResponsibilitySelect?.value) warnings.push('No delivery responsibility selected.');

            summaryEls.reviewWarnings.textContent = warnings.join(' ');
            summaryEls.reviewWarnings.classList.toggle('is-visible', warnings.length > 0);
        }

        function updateWizardCompletion() {
            const currentStepIndex = saleStepOrder.indexOf(currentSaleStep);
            const deliveryStepIndex = saleStepOrder.indexOf('delivery');
            const complete = {
                customer: customerMode() === 'business_partner'
                    ? Boolean(businessPartnerSelect?.value && partnerClientSelect?.value)
                    : Boolean(customerSelect?.value),
                products: saleItems.some((item) => item.product_id),
                pricing: parseFloat(saleAmountInput?.value || '0') > 0,
                delivery: Boolean(saleDeliveryResponsibilitySelect?.value) && currentStepIndex > deliveryStepIndex,
                review: false,
            };

            saleStepButtons.forEach(function (button) {
                const step = button.dataset.saleStepButton;
                button.classList.toggle('is-complete', Boolean(complete[step]) && !button.classList.contains('is-active'));
            });
        }

        function showSaleStep(step) {
            if (!saleStepOrder.includes(step)) {
                step = 'customer';
            }
            currentSaleStep = step;
            saleStepCards.forEach(function (card) {
                card.classList.toggle('is-active', card.dataset.saleStep === step);
            });
            saleStepButtons.forEach(function (button) {
                button.classList.toggle('is-active', button.dataset.saleStepButton === step);
            });
            if (window.matchMedia('(max-width: 720px)').matches) {
                document.querySelector('.sale-wizard-tabs')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            } else {
                document.querySelector('.sale-create-workspace')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
            updateWizardCompletion();
            updateMobileStickyFooter();
        }

        function updateMobileStickyFooter() {
            if (!saleMobileStickyFooter) {
                return;
            }

            const index = saleStepOrder.indexOf(currentSaleStep);
            const isFirst = index <= 0;
            const isReview = currentSaleStep === 'review';
            const nextLabel = currentSaleStep === 'delivery' ? 'Review' : 'Continue';

            saleMobileStickyFooter.dataset.currentStep = currentSaleStep;
            saleMobileStickyFooter.classList.toggle('is-review', isReview);
            saleMobileBackButton.hidden = isFirst;
            saleMobileNextButton.hidden = isReview;
            saleMobileSaveButton.hidden = !isReview;
            saleMobileNextButton.textContent = nextLabel;
        }

        function updateCustomerPanel() {
            const mode = customerMode();
            const customer = selectedCustomerData();
            const partner = selectedBusinessPartnerData();
            const client = selectedPartnerClientData();
            const customerAddressText = customer
                ? [customer.address, customer.city, customer.state].filter(Boolean).join(', ')
                : '';
            const customerLocation = customer?.location || '';
            const clientAddressText = client
                ? [client.address, client.city, client.state].filter(Boolean).join(', ')
                : '';
            const clientLocation = client?.location || '';

            if (mode === 'business_partner') {
                saleCustomerMetric.textContent = partner ? partner.name : 'Select business partner';
                saleReminderContactSummary.textContent = partner ? [partner.name, partner.phone].filter(Boolean).join(' - ') : 'Select business partner';
                saleDeliveryContactSummary.textContent = client ? [client.name, client.phone].filter(Boolean).join(' - ') : 'Select actual client';
                saleDeliveryAddressSummary.textContent = client ? [client.address, client.city, client.state].filter(Boolean).join(', ') : '';
                saleCustomerFlowChip.textContent = 'Business Partner';
            } else {
                saleCustomerMetric.textContent = customer ? customer.name : 'Select customer';
                saleReminderContactSummary.textContent = customer ? [customer.name, customer.phone].filter(Boolean).join(' - ') : 'Select customer';
                saleDeliveryContactSummary.textContent = customer ? [customer.name, customer.phone].filter(Boolean).join(' - ') : 'Select customer';
                saleDeliveryAddressSummary.textContent = '';
                saleCustomerFlowChip.textContent = 'Direct Customer';
            }

            if (mode === 'business_partner') {
                const hasPartnerSummary = Boolean(client);

                if (saleCustomerSummaryCard) {
                    saleCustomerSummaryCard.hidden = !hasPartnerSummary;
                }
                if (saleCustomerFlowSummaryTitle) {
                    saleCustomerFlowSummaryTitle.textContent = 'Contact Routing';
                }
                if (saleReminderContactLabel) {
                    saleReminderContactLabel.textContent = 'Reminder / Payment Contact';
                }
                if (saleDeliveryContactLine) {
                    saleDeliveryContactLine.hidden = false;
                }
                if (saleDeliveryContactLabel) {
                    saleDeliveryContactLabel.textContent = 'Delivery / Service Contact';
                }
                if (saleDeliveryAddressLabel) {
                    saleDeliveryAddressLabel.textContent = 'Delivery Address';
                }
                saleDeliveryAddressSummary.textContent = clientAddressText;
                if (saleDeliveryNotesSummary) {
                    saleDeliveryNotesSummary.textContent = client?.delivery_notes ? 'Notes: ' + client.delivery_notes : '';
                }
                if (saleDeliverySummaryActions) {
                    saleDeliverySummaryActions.hidden = !clientLocation;
                }
                if (saleDeliveryOpenMapLink) {
                    saleDeliveryOpenMapLink.hidden = !clientLocation;
                    saleDeliveryOpenMapLink.href = clientLocation || '#';
                }
                if (salePartnerClientHelper) {
                    salePartnerClientHelper.hidden = Boolean(client);
                }
            } else {
                const hasCustomerSummary = Boolean(customer && customerSelect?.value);

                if (saleCustomerSummaryCard) {
                    saleCustomerSummaryCard.hidden = !hasCustomerSummary;
                }
                if (saleCustomerFlowSummaryTitle) {
                    saleCustomerFlowSummaryTitle.textContent = 'Customer Summary';
                }
                if (saleReminderContactLabel) {
                    saleReminderContactLabel.textContent = 'Customer';
                }
                if (saleDeliveryContactLine) {
                    saleDeliveryContactLine.hidden = true;
                }
                if (saleDeliveryAddressLabel) {
                    saleDeliveryAddressLabel.textContent = 'Address';
                }
                saleDeliveryContactSummary.textContent = '';
                saleDeliveryAddressSummary.textContent = customerAddressText;
                if (saleDeliveryNotesSummary) {
                    saleDeliveryNotesSummary.textContent = '';
                }
                if (saleDeliverySummaryActions) {
                    saleDeliverySummaryActions.hidden = !customerLocation;
                }
                if (saleDeliveryOpenMapLink) {
                    saleDeliveryOpenMapLink.hidden = !customerLocation;
                    saleDeliveryOpenMapLink.href = customerLocation || '#';
                }
                if (salePartnerClientHelper) {
                    salePartnerClientHelper.hidden = true;
                }
            }

            updateSaleSummary();
            filterLinkedRentalOptions();

            const recommended = recommendedTaxType(mode === 'business_partner' ? (client?.state || partner?.state) : customer?.state);
            let needsRender = false;

            saleItems.forEach(function (item) {
                if (item.tax_type_auto) {
                    if (item.tax_type !== recommended) {
                        item.tax_type = recommended;
                        needsRender = true;
                    }
                }
            });

            if (needsRender) {
                renderSaleItems();
            }
        }

        function buildPartnerClientOption(client, isSelected) {
            const option = document.createElement('option');
            option.value = String(client.id);
            option.textContent = [client.name, client.phone].filter(Boolean).join(' - ') || client.name || 'Actual client';
            option.setAttribute('data-business-partner-id', String(client.business_partner_id || ''));
            option.setAttribute('data-name', client.name || '');
            option.setAttribute('data-phone', client.phone || '');
            option.setAttribute('data-state', client.state || '');
            option.setAttribute('data-address', client.address || '');
            option.setAttribute('data-city', client.city || '');
            option.setAttribute('data-location', client.location || '');
            option.setAttribute('data-notes', client.delivery_notes || '');
            option.setAttribute('data-search', client.search || [client.name, client.phone, client.address, client.city, client.state].filter(Boolean).join(' '));
            if (isSelected) {
                option.selected = true;
            }

            return option;
        }

        function setPartnerClientOptions(clients, selectedValue) {
            if (!partnerClientSelect) {
                return;
            }

            const preferred = selectedValue ? String(selectedValue) : '';
            partnerClientSelect.innerHTML = '';
            partnerClientSelect.appendChild(new Option('Select actual client', ''));

            (clients || []).forEach(function (client) {
                partnerClientSelect.appendChild(buildPartnerClientOption(client, preferred !== '' && String(client.id) === preferred));
            });

            if (preferred && !Array.from(partnerClientSelect.options).some(function (option) { return option.value === preferred; })) {
                partnerClientSelect.value = '';
            }

            partnerClientSelect._searchableSelect?.refresh?.();
        }

        function updatePartnerClientHelperState(visibleClientCount, message) {
            if (!salePartnerClientHelper) {
                return;
            }

            const activePartnerId = businessPartnerSelect?.value || '';

            if (!activePartnerId) {
                salePartnerClientHelper.hidden = false;
                if (salePartnerClientHelperTitle) {
                    salePartnerClientHelperTitle.textContent = 'Select a business partner to continue.';
                }
                if (salePartnerClientHelperText) {
                    salePartnerClientHelperText.textContent = 'The actual client will be used for delivery and service.';
                }
                return;
            }

            if (partnerClientSelect?.value) {
                salePartnerClientHelper.hidden = true;
                return;
            }

            salePartnerClientHelper.hidden = false;
            if (salePartnerClientHelperTitle) {
                salePartnerClientHelperTitle.textContent = 'Select or add an actual delivery client.';
            }
            if (salePartnerClientHelperText) {
                salePartnerClientHelperText.textContent = message || (visibleClientCount > 0
                    ? 'Choose the delivery or service client for this sale.'
                    : 'No actual clients added for this business partner yet.');
            }
        }

        function loadPartnerClients(partnerId, selectedValue) {
            if (!partnerClientSelect) {
                return Promise.resolve();
            }

            if (!partnerId) {
                setPartnerClientOptions([], null);
                updatePartnerClientHelperState(0);
                return Promise.resolve();
            }

            const normalizedPartnerId = parseInt(partnerId, 10);
            if (Number.isNaN(normalizedPartnerId) || normalizedPartnerId <= 0) {
                setPartnerClientOptions([], null);
                updatePartnerClientHelperState(0);
                return Promise.resolve();
            }

            if (partnerClientCache.has(normalizedPartnerId)) {
                const cachedClients = partnerClientCache.get(normalizedPartnerId) || [];
                setPartnerClientOptions(cachedClients, selectedValue);
                updatePartnerClientHelperState(cachedClients.length);
                return Promise.resolve(cachedClients);
            }

            const requestToken = ++partnerClientRequestToken;
            setPartnerClientOptions([], null);
            updatePartnerClientHelperState(0, 'Loading actual clients...');

            return fetch(partnerClientEndpoint(normalizedPartnerId), {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('Unable to load actual clients.');
                    }

                    return response.json();
                })
                .then(function (payload) {
                    if (requestToken !== partnerClientRequestToken) {
                        return [];
                    }

                    const clients = Array.isArray(payload?.partner_clients) ? payload.partner_clients : [];
                    partnerClientCache.set(normalizedPartnerId, clients);
                    setPartnerClientOptions(clients, selectedValue);
                    updatePartnerClientHelperState(clients.length);

                    return clients;
                })
                .catch(function () {
                    if (requestToken !== partnerClientRequestToken) {
                        return [];
                    }

                    setPartnerClientOptions([], null);
                    updatePartnerClientHelperState(0, 'Unable to load actual clients right now. Try again.');
                    return [];
                });
        }

        function renderPartnerClientOptions() {
            if (!partnerClientSelect) {
                return;
            }

            const activePartnerId = businessPartnerSelect?.value || '';
            const selectedValue = partnerClientSelect?.value || '';
            loadPartnerClients(activePartnerId, selectedValue);
        }

        function updateCustomerModeVisibility() {
            const mode = customerMode();
            const hasPartner = Boolean(businessPartnerSelect?.value);

            customerModeBlocks.forEach(function (block) {
                block.hidden = block.getAttribute('data-sales-customer-mode') !== mode;
            });

            if (salePartnerClientRow) {
                salePartnerClientRow.hidden = mode !== 'business_partner';
            }

            customerSelect.required = mode === 'direct_customer';
            if (businessPartnerSelect) {
                businessPartnerSelect.required = mode === 'business_partner';
            }
            if (partnerClientSelect) {
                partnerClientSelect.required = mode === 'business_partner' && hasPartner;
            }

            syncCustomerTypeButtons();
            renderPartnerClientOptions();

            if (saleAddActualClientLink) {
                saleAddActualClientLink.setAttribute('aria-disabled', hasPartner ? 'false' : 'true');
                saleAddActualClientLink.classList.toggle('is-disabled', !hasPartner);
            }

            updateCustomerPanel();
        }

        function syncCustomerFromRental() {
            const selected = selectedOption(rentalSelect);
            if (!selected || !rentalSelect.value) {
                return;
            }

            const rentalCustomerType = selected.getAttribute('data-customer-type') === 'business_partner'
                ? 'business_partner'
                : 'direct_customer';

            if (customerTypeSelect.value !== rentalCustomerType) {
                customerTypeSelect.value = rentalCustomerType;
            }

            if (rentalCustomerType === 'business_partner') {
                businessPartnerSelect.value = selected.getAttribute('data-business-partner-id') || '';
                renderPartnerClientOptions();
                partnerClientSelect.value = selected.getAttribute('data-partner-client-id') || '';
                updateCustomerModeVisibility();
                return;
            }

            const rentalCustomerId = selected.getAttribute('data-customer-id');
            if (rentalCustomerId && customerSelect.value !== rentalCustomerId) {
                customerSelect.value = rentalCustomerId;
            }

            updateCustomerModeVisibility();
        }

        function rentalOptionMatchesCurrentCustomer(option) {
            if (!option || !option.value) {
                return true;
            }

            const mode = customerMode();
            if (mode === 'business_partner') {
                const partnerId = String(businessPartnerSelect?.value || '');
                const clientId = String(partnerClientSelect?.value || '');
                if (!partnerId || !clientId) {
                    return option.selected;
                }

                return option.getAttribute('data-customer-type') === 'business_partner'
                    && String(option.getAttribute('data-business-partner-id') || '') === partnerId
                    && String(option.getAttribute('data-partner-client-id') || '') === clientId;
            }

            const customerId = String(customerSelect?.value || '');
            if (!customerId) {
                return option.selected;
            }

            return option.getAttribute('data-customer-type') !== 'business_partner'
                && String(option.getAttribute('data-customer-id') || '') === customerId;
        }

        function filterLinkedRentalOptions() {
            if (!rentalSelect) {
                return;
            }

            Array.from(rentalSelect.options).forEach(function (option) {
                const matches = rentalOptionMatchesCurrentCustomer(option);
                option.hidden = !matches;
                option.disabled = !matches;
            });

            const current = selectedOption(rentalSelect);
            if (current && current.value && !rentalOptionMatchesCurrentCustomer(current)) {
                rentalSelect.value = '';
            }

            rentalSelect._searchableSelect?.refresh?.();
        }

        function compactProductMeta(product) {
            if (!product) {
                return '';
            }

            const parts = [];
            if (product.sku) parts.push('SKU: ' + product.sku);
            if (product.product_code) parts.push('Code: ' + product.product_code);
            if (product.brand) parts.push(product.brand);
            return parts.join(' | ');
        }

        function stockModeLabel(product) {
            if (!product) {
                return '';
            }

            const raw = String(product.stock_mode_label || product.stock_mode || 'Untracked Sale');
            if (raw === 'Tracked Both') return 'Tracked Sale';
            if (raw === 'Tracked Rental') return 'Sale Eligible';
            return raw.includes('Sale') ? raw : raw + ' Sale';
        }

        function productInitial(product) {
            return String(product?.name || 'P').trim().charAt(0).toUpperCase() || 'P';
        }

        function saleStockSummary(product) {
            if (!product) {
                return null;
            }

            const trackedAvailable = assets.filter(function (asset) {
                return parseInt(asset.product_id || 0, 10) === parseInt(product.id || 0, 10);
            }).length;
            const usesTrackedSale = ['tracked_sale', 'tracked_both'].includes(String(product.stock_mode || ''));
            const available = usesTrackedSale ? trackedAvailable : Math.max(parseInt(product.available_quantity || 0, 10), 0);
            const total = Math.max(parseInt(product.total_quantity || 0, 10), available);
            // Reserved and in-transit sale stock counts are not exposed to this form yet; keep them visible as zero until backend data is available.
            const reserved = 0;
            const inTransit = 0;
            const sold = Math.max(total - available - reserved - inTransit, 0);

            return { available, sold, reserved, inTransit, total };
        }

        function stockChipsHtml(product) {
            const stock = saleStockSummary(product);

            if (!stock) {
                return '<div class="sales-stock-chip-row is-muted">Select product to view stock.</div>';
            }

            return `
                <div class="sales-stock-chip-row" aria-label="Sale stock summary">
                    <div class="sales-stock-chip is-available"><i class="sales-stock-dot"></i><div><span>Available</span><strong>${stock.available}</strong></div></div>
                    <div class="sales-stock-chip is-sold"><i class="sales-stock-dot"></i><div><span>Sold</span><strong>${stock.sold}</strong></div></div>
                    <div class="sales-stock-chip is-reserved"><i class="sales-stock-dot"></i><div><span>Reserved</span><strong>${stock.reserved}</strong></div></div>
                    <div class="sales-stock-chip is-transit"><i class="sales-stock-dot"></i><div><span>In Transit</span><strong>${stock.inTransit}</strong></div></div>
                    <div class="sales-stock-chip"><i class="sales-stock-dot"></i><div><span>Total Stock</span><strong>${stock.total}</strong></div></div>
                </div>
            `;
        }

        function addSaleStockUrl(productId) {
            return addSaleStockUrlTemplate.replace('__PRODUCT__', productId || '');
        }
        function productOptionsHtml(selectedProductId) {
            let html = '<option value="">Select product</option>';

            products.forEach(function (product) {
                const search = [product.name, product.brand, product.model_name, product.sku, product.product_code].filter(Boolean).join(' ');
                const meta = compactProductMeta(product);
                html += '<option value="' + product.id + '" data-search="' + escapeHtml(search) + '" data-product-name="' + escapeHtml(product.name) + '" data-product-image-url="' + escapeHtml(product.image_url || '') + '" data-product-brand="' + escapeHtml(product.brand || '') + '" data-product-model="' + escapeHtml(product.model_name || '') + '" data-product-sku="' + escapeHtml(product.sku || '') + '" data-product-code="' + escapeHtml(product.product_code || '') + '" data-product-meta="' + escapeHtml(meta) + '" data-availability-label="Available: ' + escapeHtml(product.available_quantity ?? 0) + '"' + (selectedProductId === parseInt(product.id, 10) ? ' selected' : '') + '>' + escapeHtml(product.name) + '</option>';
            });

            return html;
        }

        function assetOptionsHtml(item, currentIndex) {
            let html = '<option value="">No serialized asset link</option>';
            const selectedAssetIds = new Set(saleItems
                .map(function (saleItem, index) {
                    return index === currentIndex ? null : parseInt(saleItem.asset_id || 0, 10);
                })
                .filter(function (assetId) { return assetId > 0; }));

            assets.forEach(function (asset) {
                if (item.product_id && parseInt(asset.product_id, 10) !== parseInt(item.product_id, 10)) {
                    return;
                }
                if (selectedAssetIds.has(parseInt(asset.id, 10))) {
                    return;
                }

                html += '<option value="' + asset.id + '"' + (parseInt(item.asset_id || 0, 10) === parseInt(asset.id, 10) ? ' selected' : '') + '>' + escapeHtml(asset.label) + '</option>';
            });

            return html;
        }

        function findAssetByScan(value, productId) {
            const needle = String(value || '').trim().toLowerCase();
            if (!needle) {
                return null;
            }

            return assets.find(function (asset) {
                if (productId && parseInt(asset.product_id, 10) !== parseInt(productId, 10)) {
                    return false;
                }

                const tokens = [
                    asset.serial_number,
                    asset.barcode_value,
                    asset.asset_name,
                    asset.label,
                ].filter(Boolean).map(function (token) {
                    return String(token).toLowerCase();
                });

                return tokens.some(function (token) {
                    return token === needle || token.includes(needle);
                });
            }) || null;
        }

        function renderSaleItems() {
            saleItemsList.innerHTML = '';

            saleItems.forEach(function (item, index) {
                const total = lineCommercials(item);
                const product = item.product_id ? productMap.get(parseInt(item.product_id, 10)) : null;
                const productMeta = compactProductMeta(product);
                const hsnCode = product?.hsn_code || '';
                const addStockHref = addSaleStockUrl(product ? product.id : '');
                const discountedUnit = Math.max(parseFloat(item.unit_price || 0) - parseFloat(item.discount_amount || 0), 0);

                const row = document.createElement('div');
                row.className = 'sales-item-card is-compact';
                row.innerHTML = `
                    <div class="sales-item-head">
                        <div class="sales-item-title">
                            <strong>Product Line #${index + 1}</strong>
                            <span class="sales-line-pill">Line ${index + 1}</span>
                        </div>
                        <div class="sales-line-actions">
                            ${saleItems.length > 1 ? '<button type="button" class="sales-remove-btn" data-remove-index="' + index + '">Remove</button>' : ''}
                            <button type="button" class="sales-collapse-btn" aria-label="Collapse product line" data-toggle-line-details="${index}">^</button>
                        </div>
                    </div>
                    <div class="sales-product-details" data-line-details="${index}">
                        <div class="sales-product-first-row">
                            <div class="sales-field">
                                <label for="sale_item_product_${index}">Product <span style="color:#dc2626;">*</span></label>
                                <select name="sale_items[${index}][product_id]" id="sale_item_product_${index}" data-searchable-select data-search-placeholder="Search product by name, brand, model, SKU, or code">
                                    ${productOptionsHtml(item.product_id ? parseInt(item.product_id, 10) : null)}
                                </select>
                            </div>
                            <div class="sales-field">
                                <label for="sale_item_asset_${index}">Asset Link <span style="color:#64748b; text-transform:none; font-weight:700;">(Optional)</span></label>
                                <select name="sale_items[${index}][asset_id]" id="sale_item_asset_${index}">
                                    ${assetOptionsHtml(item, index)}
                                </select>
                            </div>
                            <button type="button" class="sales-add-stock-btn" data-add-sale-stock-link data-stock-url="${escapeHtml(addStockHref)}">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21 8-9-5-9 5 9 5 9-5Z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/></svg>
                                Add Stock
                            </button>
                        </div>

                        <div class="sales-product-preview" ${product ? '' : 'hidden'}>
                            <div class="sales-product-thumb">${product && product.image_url ? `<img src="${escapeHtml(product.image_url)}" alt="" onerror="this.parentElement.textContent='${escapeHtml(productInitial(product))}'">` : escapeHtml(productInitial(product))}</div>
                            <div style="min-width:0;">
                                <strong>${escapeHtml(product ? product.name : '')}</strong>
                                <span>${escapeHtml(productMeta || 'Sale product')}</span>
                                <em class="sales-stock-mode-badge">${escapeHtml(stockModeLabel(product))}</em>
                            </div>
                        </div>

                        ${stockChipsHtml(product)}

                        <div class="sales-commercial-grid">
                            <div class="sales-field">
                                <label for="sale_item_asset_scan_${index}">Scan Serial / Barcode</label>
                                <div class="sales-scan-row">
                                    <input type="text" id="sale_item_asset_scan_${index}" placeholder="Scan or type serial/barcode">
                                    <button type="button" class="sales-btn-light" data-scan-asset-index="${index}">Link</button>
                                </div>
                            </div>
                            <div class="sales-field">
                                <label for="sale_item_quantity_${index}">Qty <span style="color:#dc2626;">*</span></label>
                                <div class="sales-qty-stepper">
                                    <button type="button" data-qty-step="${index}" data-qty-delta="-1" aria-label="Decrease quantity">-</button>
                                    <input type="number" min="1" step="1" name="sale_items[${index}][quantity]" id="sale_item_quantity_${index}" value="${escapeHtml(item.quantity)}">
                                    <button type="button" data-qty-step="${index}" data-qty-delta="1" aria-label="Increase quantity">+</button>
                                </div>
                            </div>
                            <div class="sales-field">
                                <label for="sale_item_unit_price_${index}">Unit Price (Rs.) <span style="color:#dc2626;">*</span></label>
                                <input type="number" min="0" step="0.01" name="sale_items[${index}][unit_price]" id="sale_item_unit_price_${index}" value="${escapeHtml(item.unit_price)}">
                            </div>
                            <div class="sales-field">
                                <label for="sale_item_discount_${index}">Discount</label>
                                <input type="number" min="0" step="0.01" name="sale_items[${index}][discount_amount]" id="sale_item_discount_${index}" value="${escapeHtml(item.discount_amount)}">
                            </div>
                            <div class="sales-field">
                                <label for="sale_item_tax_type_${index}">Tax Type</label>
                                <select name="sale_items[${index}][tax_type]" id="sale_item_tax_type_${index}">
                                    ${taxTypeOptionsHtml(item.tax_type)}
                                </select>
                            </div>
                            <div class="sales-field">
                                <label>Unit Price After Discount (Rs.)</label>
                                <input type="number" min="0" step="0.01" value="${discountedUnit.toFixed(2)}" id="sale_item_discounted_unit_${index}" readonly>
                            </div>
                            <div class="sales-field sales-gst-field" style="grid-column:1 / -1;">
                                <details class="sales-tax-details">
                                    <summary>
                                        <span>GST Details</span>
                                        <em>Auto from Product Master</em>
                                        <b aria-hidden="true">v</b>
                                    </summary>
                                    <div class="sales-grid sales-tax-grid">
                                        <div class="sales-field sales-col-4">
                                            <label for="sale_item_tax_${index}">GST %</label>
                                            <select name="sale_items[${index}][tax_percentage]" id="sale_item_tax_${index}">
                                                ${gstOptionsHtml(item.tax_percentage)}
                                            </select>
                                        </div>
                                        <div class="sales-field sales-col-4">
                                            <label for="sale_item_tax_mode_${index}">GST Mode</label>
                                            <select name="sale_items[${index}][tax_calculation_mode]" id="sale_item_tax_mode_${index}">
                                                <option value="exclusive"${item.tax_calculation_mode === 'exclusive' ? ' selected' : ''}>Exclusive</option>
                                                <option value="inclusive"${item.tax_calculation_mode === 'inclusive' ? ' selected' : ''}>Inclusive</option>
                                            </select>
                                        </div>
                                        <div class="sales-field sales-col-4">
                                            <label>HSN</label>
                                            <input type="text" value="${escapeHtml(hsnCode || 'Not set')}" readonly>
                                        </div>
                                    </div>
                                </details>
                            </div>
                            <div class="sales-field" style="grid-column:1 / -1;">
                                <label for="sale_item_notes_${index}">Notes <span style="color:#64748b; text-transform:none; font-weight:700;">(Optional)</span></label>
                                <textarea name="sale_items[${index}][notes]" id="sale_item_notes_${index}" rows="2" placeholder="Add notes for this product line">${escapeHtml(item.notes || '')}</textarea>
                            </div>
                        </div>
                    </div>
                    <div class="sales-line-total is-compact">
                        <span>Line Total (Rs.)</span>
                        <strong id="sale_item_total_${index}">${formatCurrency(total)}</strong>
                    </div>
                `;

                saleItemsList.appendChild(row);

                const productSelect = row.querySelector('#sale_item_product_' + index);
                const assetSelect = row.querySelector('#sale_item_asset_' + index);
                const assetScanInput = row.querySelector('#sale_item_asset_scan_' + index);
                const assetScanButton = row.querySelector('[data-scan-asset-index="' + index + '"]');
                const quantityInput = row.querySelector('#sale_item_quantity_' + index);
                const qtyButtons = Array.from(row.querySelectorAll('[data-qty-step="' + index + '"]'));
                const unitPriceInput = row.querySelector('#sale_item_unit_price_' + index);
                const discountInput = row.querySelector('#sale_item_discount_' + index);
                const discountedUnitInput = row.querySelector('#sale_item_discounted_unit_' + index);
                const taxInput = row.querySelector('#sale_item_tax_' + index);
                const taxModeSelect = row.querySelector('#sale_item_tax_mode_' + index);
                const taxTypeSelect = row.querySelector('#sale_item_tax_type_' + index);
                const notesInput = row.querySelector('#sale_item_notes_' + index);
                const totalLabel = row.querySelector('#sale_item_total_' + index);
                const removeButton = row.querySelector('[data-remove-index="' + index + '"]');
                const collapseButton = row.querySelector('[data-toggle-line-details="' + index + '"]');
                const detailsBlock = row.querySelector('[data-line-details="' + index + '"]');

                enhanceSearchableSelect(productSelect);

                function refreshLineTotal() {
                    totalLabel.textContent = formatCurrency(lineCommercials(saleItems[index]));
                    if (discountedUnitInput) {
                        const discounted = Math.max(parseFloat(saleItems[index].unit_price || 0) - parseFloat(saleItems[index].discount_amount || 0), 0);
                        discountedUnitInput.value = discounted.toFixed(2);
                    }
                    updateSaleSummary();
                }

                productSelect.addEventListener('change', function () {
                    saleItems[index].product_id = this.value ? parseInt(this.value, 10) : null;
                    saleItems[index].asset_id = null;

                    const selectedProduct = saleItems[index].product_id ? productMap.get(saleItems[index].product_id) : null;
                    if (selectedProduct) {
                        saleItems[index].unit_price = normalizeMoney(selectedProduct.sale_price);
                        saleItems[index].tax_percentage = normalizeMoney(selectedProduct.tax_percentage);
                        saleItems[index].tax_calculation_mode = selectedProduct.tax_mode === 'inclusive' ? 'inclusive' : 'exclusive';
                        if (saleItems[index].tax_type_auto) {
                            saleItems[index].tax_type = recommendedTaxType();
                        }
                    }

                    renderSaleItems();
                });

                assetSelect.addEventListener('change', function () {
                    saleItems[index].asset_id = this.value ? parseInt(this.value, 10) : null;
                    if (saleItems[index].asset_id) {
                        saleItems[index].quantity = 1;
                    }
                    renderSaleItems();
                });

                function applyAssetScan() {
                    const matchedAsset = findAssetByScan(assetScanInput?.value || '', saleItems[index].product_id);
                    if (!matchedAsset) {
                        assetScanInput?.focus();
                        return;
                    }

                    const alreadySelected = saleItems.some(function (saleItem, saleItemIndex) {
                        return saleItemIndex !== index && parseInt(saleItem.asset_id || 0, 10) === parseInt(matchedAsset.id, 10);
                    });
                    if (alreadySelected) {
                        assetScanInput.value = '';
                        assetScanInput.placeholder = 'Already linked on another line';
                        assetScanInput.focus();
                        return;
                    }

                    saleItems[index].asset_id = parseInt(matchedAsset.id, 10);
                    saleItems[index].product_id = parseInt(matchedAsset.product_id, 10);
                    saleItems[index].quantity = 1;
                    const matchedProduct = productMap.get(saleItems[index].product_id);
                    if (matchedProduct) {
                        saleItems[index].unit_price = normalizeMoney(matchedProduct.sale_price);
                        saleItems[index].tax_percentage = normalizeMoney(matchedProduct.tax_percentage);
                        saleItems[index].tax_calculation_mode = matchedProduct.tax_mode === 'inclusive' ? 'inclusive' : 'exclusive';
                        if (saleItems[index].tax_type_auto) {
                            saleItems[index].tax_type = recommendedTaxType();
                        }
                    }
                    renderSaleItems();
                }

                assetScanButton?.addEventListener('click', applyAssetScan);
                assetScanInput?.addEventListener('keydown', function (event) {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        applyAssetScan();
                    }
                });

                function applyQuantity(value) {
                    const nextQuantity = Math.max(parseInt(value || 1, 10), 1);
                    saleItems[index].quantity = nextQuantity;
                    quantityInput.value = nextQuantity;
                    refreshLineTotal();
                }

                qtyButtons.forEach(function (button) {
                    button.addEventListener('click', function () {
                        const delta = parseInt(button.dataset.qtyDelta || 0, 10);
                        applyQuantity((parseInt(quantityInput.value || 1, 10) || 1) + delta);
                    });
                });

                quantityInput.addEventListener('input', function () {
                    applyQuantity(this.value);
                });

                unitPriceInput.addEventListener('input', function () {
                    saleItems[index].unit_price = normalizeMoney(this.value);
                    refreshLineTotal();
                });

                discountInput.addEventListener('input', function () {
                    saleItems[index].discount_amount = normalizeMoney(this.value);
                    refreshLineTotal();
                });

                taxInput?.addEventListener('change', function () {
                    saleItems[index].tax_percentage = normalizeMoney(this.value);
                    refreshLineTotal();
                });

                taxModeSelect?.addEventListener('change', function () {
                    saleItems[index].tax_calculation_mode = this.value === 'inclusive' ? 'inclusive' : 'exclusive';
                    refreshLineTotal();
                });

                taxTypeSelect.addEventListener('change', function () {
                    saleItems[index].tax_type = this.value === 'igst' ? 'igst' : 'cgst_sgst';
                    saleItems[index].tax_type_auto = false;
                });

                notesInput.addEventListener('input', function () {
                    saleItems[index].notes = this.value || '';
                });

                collapseButton?.addEventListener('click', function () {
                    const collapsed = !detailsBlock.hidden;
                    detailsBlock.hidden = collapsed;
                    collapseButton.textContent = collapsed ? 'v' : '^';
                    collapseButton.setAttribute('aria-label', collapsed ? 'Expand product line' : 'Collapse product line');
                });

                if (removeButton) {
                    removeButton.addEventListener('click', function () {
                        saleItems.splice(index, 1);
                        if (!saleItems.length) {
                            saleItems = [defaultSaleItem()];
                        }
                        renderSaleItems();
                    });
                }
            });

            updateSaleSummary();
        }

        function showReferralQuickError(message) {
            if (!saleReferralQuickError) {
                return;
            }
            saleReferralQuickError.textContent = message || '';
            saleReferralQuickError.classList.toggle('is-visible', Boolean(message));
        }

        function openSaleReferralModal() {
            if (!saleReferralSourceModal) {
                return;
            }
            showReferralQuickError('');
            if (saleReferralQuickType) {
                saleReferralQuickType.value = referralTypeSelect?.value || '';
            }
            if (saleReferralQuickName) saleReferralQuickName.value = '';
            if (saleReferralQuickContact) saleReferralQuickContact.value = '';
            if (saleReferralQuickCity) saleReferralQuickCity.value = '';
            if (saleReferralQuickNotes) saleReferralQuickNotes.value = '';
            saleReferralSourceModal.classList.add('is-open');
            saleReferralSourceModal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('modal-open');
            window.requestAnimationFrame(function () {
                saleReferralQuickName?.focus();
            });
        }

        function closeSaleReferralModal() {
            if (!saleReferralSourceModal) {
                return;
            }
            saleReferralSourceModal.classList.remove('is-open');
            saleReferralSourceModal.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('modal-open');
        }

        function appendReferralSourceOption(source) {
            if (!source || !referralNameInput) {
                return;
            }
            const option = document.createElement('option');
            const label = [source.name, source.contact].filter(Boolean).join(' - ') || source.name || 'Referral source';
            option.value = String(source.id || '');
            option.textContent = label;
            option.setAttribute('data-referral-type', source.source_type || '');
            option.setAttribute('data-referral-name', source.name || '');
            option.setAttribute('data-referral-contact', source.contact || '');
            option.setAttribute('data-referral-city', source.city || '');
            option.setAttribute('data-search', [label, source.city, source.source_type].filter(Boolean).join(' '));
            referralNameInput.appendChild(option);
            referralNameInput.value = option.value;
            referralNameInput._searchableSelect?.refresh?.();
            referralNameInput.dispatchEvent(new Event('change', { bubbles: true }));
        }

        function saveSaleReferralSource() {
            if (!saleReferralQuickName?.value.trim()) {
                showReferralQuickError('Referral name is required.');
                saleReferralQuickName?.focus();
                return;
            }

            if (saleReferralQuickSave) {
                saleReferralQuickSave.disabled = true;
                saleReferralQuickSave.textContent = 'Saving...';
            }
            showReferralQuickError('');

            fetch(referralQuickStoreUrl, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || document.querySelector('input[name="_token"]')?.value || '',
                },
                body: JSON.stringify({
                    source_type: saleReferralQuickType?.value || '',
                    name: saleReferralQuickName.value.trim(),
                    contact: saleReferralQuickContact?.value.trim() || '',
                    city: saleReferralQuickCity?.value.trim() || '',
                    notes: saleReferralQuickNotes?.value.trim() || '',
                    is_active: true,
                }),
            })
                .then(function (response) {
                    return response.json().then(function (payload) {
                        if (!response.ok) {
                            const firstError = payload?.errors ? Object.values(payload.errors).flat()[0] : null;
                            throw new Error(firstError || payload?.message || 'Unable to save referral source.');
                        }
                        return payload;
                    });
                })
                .then(function (payload) {
                    appendReferralSourceOption(payload);
                    closeSaleReferralModal();
                })
                .catch(function (error) {
                    showReferralQuickError(error.message || 'Unable to save referral source.');
                })
                .finally(function () {
                    if (saleReferralQuickSave) {
                        saleReferralQuickSave.disabled = false;
                        saleReferralQuickSave.textContent = 'Save Referral';
                    }
                });
        }
        function openSaleStockPopup(url) {
            const modal = document.getElementById('saleStockPopup');
            const frame = document.getElementById('saleStockPopupFrame');
            if (!modal || !frame || !url) {
                if (url) window.open(url, '_blank', 'noopener');
                return;
            }
            frame.src = url;
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('modal-open');
        }

        function closeSaleStockPopup() {
            const modal = document.getElementById('saleStockPopup');
            const frame = document.getElementById('saleStockPopupFrame');
            if (!modal) return;
            modal.classList.remove('is-open');
            modal.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('modal-open');
            if (frame) frame.src = 'about:blank';
        }
        document.addEventListener('click', function (event) {
            const openReferral = event.target.closest('[data-open-sale-referral-modal]');
            if (openReferral) {
                event.preventDefault();
                openSaleReferralModal();
                return;
            }

            if (event.target.closest('[data-close-sale-referral-modal]') || event.target === saleReferralSourceModal) {
                event.preventDefault();
                closeSaleReferralModal();
            }
        });

        saleReferralQuickSave?.addEventListener('click', saveSaleReferralSource);
        saleReferralQuickName?.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                saveSaleReferralSource();
            }
        });
        document.addEventListener('click', function (event) {
            const addStockButton = event.target.closest('[data-add-sale-stock-link]');
            if (addStockButton) {
                event.preventDefault();
                openSaleStockPopup(addStockButton.dataset.stockUrl || addStockButton.getAttribute('href'));
                return;
            }

            if (event.target.closest('[data-close-sale-stock-popup]') || event.target?.id === 'saleStockPopup') {
                event.preventDefault();
                closeSaleStockPopup();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeSaleReferralModal();
                closeSaleStockPopup();
            }
        });
        addSaleItemButton.addEventListener('click', function () {
            saleItems.push(defaultSaleItem());
            renderSaleItems();
        });

        saleStepButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                showSaleStep(button.dataset.saleStepButton || 'customer');
            });
        });
        saleStepNextButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                showSaleStep(button.dataset.saleStepNext || 'customer');
            });
        });
        saleStepPrevButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                showSaleStep(button.dataset.saleStepPrev || 'customer');
            });
        });

        saleMobileBackButton?.addEventListener('click', function () {
            const index = saleStepOrder.indexOf(currentSaleStep);
            showSaleStep(saleStepOrder[Math.max(index - 1, 0)] || 'customer');
        });

        saleMobileNextButton?.addEventListener('click', function () {
            const index = saleStepOrder.indexOf(currentSaleStep);
            showSaleStep(saleStepOrder[Math.min(index + 1, saleStepOrder.length - 1)] || 'review');
        });

        saleSummaryToggle?.addEventListener('click', function () {
            saleSummaryPanel?.classList.toggle('is-open');
        });

        customerTypeButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                if (!customerTypeSelect || button.disabled || button.getAttribute('aria-disabled') === 'true') {
                    return;
                }

                const nextMode = button.getAttribute('data-sales-customer-type-option') || 'direct_customer';
                if (customerTypeSelect.value === nextMode) {
                    return;
                }

                customerTypeSelect.value = nextMode;
                customerTypeSelect.dispatchEvent(new Event('change', { bubbles: true }));
            });
        });

        customerTypeSelect?.addEventListener('change', updateCustomerModeVisibility);
        customerSelect.addEventListener('change', updateCustomerPanel);
        businessPartnerSelect?.addEventListener('change', updateCustomerModeVisibility);
        partnerClientSelect?.addEventListener('change', updateCustomerPanel);
        rentalSelect.addEventListener('change', syncCustomerFromRental);
        saleFulfilmentSource?.addEventListener('change', syncSaleFulfilmentControls);
        saleDeliveryResponsibilitySelect?.addEventListener('change', updateSaleSummary);
        referralTypeSelect?.addEventListener('change', updateSaleSummary);
        referralNameInput?.addEventListener('change', function () {
            const selected = selectedOption(referralNameInput);
            if (selected) {
                if (referralTypeSelect && selected.getAttribute('data-referral-type')) {
                    referralTypeSelect.value = selected.getAttribute('data-referral-type');
                }
                if (referralNameSnapshotInput) {
                    referralNameSnapshotInput.value = selected.getAttribute('data-referral-name') || '';
                }
                if (referralContactInput && selected.getAttribute('data-referral-contact')) {
                    referralContactInput.value = selected.getAttribute('data-referral-contact');
                }
                if (referralCityInput && selected.getAttribute('data-referral-city')) {
                    referralCityInput.value = selected.getAttribute('data-referral-city');
                }
            }
            updateSaleSummary();
        });
        if (saleShippingInput) {
            saleShippingInput.addEventListener('input', updateSaleSummary);
        }
        window.addEventListener('business-partner:created', function (event) {
            const partner = event.detail;

            if (!partner || !businessPartnerSelect) {
                return;
            }

            const option = document.createElement('option');
            option.value = partner.id;
            option.textContent = partner.phone ? partner.name + ' - ' + partner.phone : partner.name;
            option.setAttribute('data-name', partner.name || '');
            option.setAttribute('data-phone', partner.phone || '');
            option.setAttribute('data-email', partner.email || '');
            option.setAttribute('data-address', partner.address || '');
            option.setAttribute('data-city', partner.city || '');
            option.setAttribute('data-state', partner.state || '');
            option.setAttribute('data-billing-state', partner.billing_state || partner.state || '');
            option.setAttribute('data-location', partner.location || '');
            option.setAttribute('data-partner-clients-count', '0');
            option.setAttribute('data-search', [partner.name, partner.contact_person, partner.phone, partner.email, partner.city, partner.state].filter(Boolean).join(' '));
            businessPartnerSelect.appendChild(option);
            partnerClientCache.set(parseInt(partner.id, 10), []);
            businessPartnerSelect.value = String(partner.id);
            businessPartnerSelect.dispatchEvent(new Event('change', { bubbles: true }));
        });
        window.addEventListener('partner-client:created', function (event) {
            const client = event.detail;

            if (!client || !partnerClientSelect) {
                return;
            }

            const partnerId = parseInt(client.business_partner_id || '0', 10);
            const cachedClients = partnerClientCache.get(partnerId) || [];
            partnerClientCache.set(partnerId, cachedClients.concat([client]));

            const currentPartnerOption = businessPartnerSelect?.querySelector(`option[value="${partnerId}"]`);
            if (currentPartnerOption) {
                const existingCount = parseInt(currentPartnerOption.getAttribute('data-partner-clients-count') || '0', 10) || 0;
                currentPartnerOption.setAttribute('data-partner-clients-count', String(existingCount + 1));
            }

            const option = document.createElement('option');
            option.value = client.id;
            option.textContent = client.phone ? client.name + ' - ' + client.phone : client.name;
            option.setAttribute('data-business-partner-id', String(client.business_partner_id || ''));
            option.setAttribute('data-name', client.name || '');
            option.setAttribute('data-phone', client.phone || '');
            option.setAttribute('data-email', '');
            option.setAttribute('data-city', client.city || '');
            option.setAttribute('data-state', client.state || '');
            option.setAttribute('data-address', client.address || '');
            option.setAttribute('data-location', client.location || '');
            option.setAttribute('data-notes', client.delivery_notes || '');
            option.setAttribute('data-search', [client.name, client.phone, client.address, client.city, client.state].filter(Boolean).join(' '));
            if (String(businessPartnerSelect?.value || '') === String(partnerId)) {
                partnerClientSelect.appendChild(option);
                partnerClientSelect.value = String(client.id);
                partnerClientSelect.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });

        enhanceSearchableSelect(customerSelect);
        enhanceSearchableSelect(businessPartnerSelect);
        enhanceSearchableSelect(partnerClientSelect);
        enhanceSearchableSelect(rentalSelect);
        enhanceSearchableSelect(referralNameInput);
        updateCustomerModeVisibility();
        updateCustomerPanel();
        syncCustomerFromRental();
        syncSaleFulfilmentControls();
        renderSaleItems();
        updateMobileStickyFooter();

        if (window.matchMedia('(max-width: 720px)').matches && !window.location.hash) {
            if ('scrollRestoration' in window.history) {
                window.history.scrollRestoration = 'manual';
            }

            requestAnimationFrame(function () {
                window.scrollTo({ top: 0, left: 0, behavior: 'auto' });
            });
        }
    })();
</script>
