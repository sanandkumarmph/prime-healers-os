@php
    $sale = $sale ?? null;
    $isEdit = (bool) $sale;
    $businessPartners = $businessPartners ?? collect();
    $initialPartnerClients = $initialPartnerClients ?? collect();
    $businessPartnerFlowAvailable = $businessPartnerFlowAvailable ?? true;
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
        'sale_price' => (float) ($product->sale_price ?? 0),
        'tax_percentage' => $product->gst_tax_type === \App\Models\Product::GST_TAX_TYPE_CGST_SGST
            ? round((float) ($product->cgst_rate ?? 0) + (float) ($product->sgst_rate ?? 0), 2)
            : round((float) ($product->igst_rate ?? 0), 2),
        'tax_mode' => $product->gst_calculation_mode ?? 'exclusive',
    ])->values();

    $assetData = ($assets ?? collect())->map(fn ($asset) => [
        'id' => $asset->id,
        'product_id' => $asset->product_id,
        'serial_number' => $asset->serial_number,
        'asset_name' => $asset->asset_name,
        'warehouse_id' => $asset->warehouse_id,
        'warehouse_name' => $asset->warehouse?->name,
        'label' => trim(implode(' - ', array_filter([
            $asset->serial_number ?: $asset->asset_name ?: ('Asset #' . $asset->id),
            $asset->product?->name,
            $asset->warehouse?->name,
        ]))),
    ])->values();

@endphp

@include('partials.business-partner-flow-styles')

<style>
    .sales-shell { display:grid; gap:16px; padding:18px 22px 28px; width:100%; max-width:100%; min-width:0; overflow-x:clip; }
    .sales-header { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap; }
    .sales-header h1 { margin:0; font-size:28px; color:#0f172a; }
    .sales-header p { margin:6px 0 0; color:#64748b; font-size:13px; max-width:760px; }
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
        background:#fff; border:1px solid #dbe3ef; border-radius:18px; padding:18px;
        box-shadow:0 8px 24px rgba(15, 23, 42, 0.04);
        min-width:0;
        max-width:100%;
    }
    .sales-card h2 { margin:0 0 4px; font-size:17px; color:#0f172a; }
    .sales-card > p { margin:0 0 14px; color:#64748b; font-size:12px; }
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
    .searchable-select-empty { color:#64748b; }
    .sales-field.is-error .searchable-select-trigger {
        border-color:#dc2626; box-shadow:0 0 0 3px rgba(220, 38, 38, 0.12); background:#fff7f7;
    }
    @media (max-width: 1024px) {
        .sales-col-3, .sales-col-4, .sales-col-6, .sales-col-8 { grid-column:span 12; }
        .sales-summary { grid-template-columns:repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 720px) {
        .sales-shell { padding:14px 14px calc(112px + env(safe-area-inset-bottom, 0px)); }
        .sales-summary { grid-template-columns:1fr; }
        .sales-card { padding:14px; border-radius:16px; }
        .sales-header h1 { font-size:24px; }
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
            flex-direction:column;
            align-items:stretch;
        }
        .sales-order-total > div:last-child {
            width:100%;
            display:grid !important;
            grid-template-columns:1fr;
            gap:10px;
        }
        .sales-order-total > div:last-child > * {
            min-width:0 !important;
            width:100%;
            max-width:100%;
        }
        #sale-save-section {
            flex-direction:column;
            align-items:stretch !important;
        }
        #sale-save-section .sales-btn,
        #sale-save-section .sales-btn-light,
        #sale-save-section button {
            width:100%;
            white-space:normal;
            text-align:center;
        }
        .searchable-select-trigger {
            white-space:normal;
        }
    }
</style>

<div class="container sales-shell">
    <div class="sales-header">
        <div>
            <h1>{{ $isEdit ? 'Edit Sale' : 'Create Sale' }}</h1>
            <p>Capture sale details, keep the customer context separate, and add one or many products in a single clean order.</p>
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

    <x-section-nav
        label="Sale form sections"
        :items="[
            ['id' => 'sale-details-section', 'label' => 'Details'],
            ['id' => 'sale-customer-section', 'label' => 'Customer'],
            ['id' => 'sale-products-section', 'label' => 'Product'],
            ['id' => 'sale-pricing-section', 'label' => 'Pricing'],
            ['id' => 'sale-save-section', 'label' => 'Save'],
        ]"
    />

    <div class="sales-card">
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
                <strong id="saleAmountMetric">₹0.00</strong>
            </div>
        </div>
    </div>

    <div class="sales-card section-nav-target" id="sale-details-section">
        <h2>Sale Details</h2>
        <p>Use order-level fields here so finance and operations can read the transaction context quickly.</p>
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
                <small>If linked, the selected customer will sync to the rental customer.</small>
            </div>
            <div class="sales-field sales-col-4 {{ $errors->has('fulfilment_source') ? 'is-error' : '' }}">
                <label for="sale_fulfilment_source">Fulfilment Source</label>
                <select name="fulfilment_source" id="sale_fulfilment_source">
                    <option value="in_house" {{ $selectedFulfilmentSource === 'in_house' ? 'selected' : '' }}>In-house Stock</option>
                    <option value="vendor_supplied" {{ $selectedFulfilmentSource === 'vendor_supplied' ? 'selected' : '' }}>Vendor Supplied</option>
                </select>
                <small>Vendor supplied sales skip PH sale-unit reduction while keeping customer, invoice, and order records.</small>
                @error('fulfilment_source')<div class="sales-field-error">{{ $message }}</div>@enderror
            </div>
            <div class="sales-field sales-col-4 {{ $errors->has('vendor_id') ? 'is-error' : '' }}">
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
    </div>

    <div class="sales-card section-nav-target" id="sale-customer-section">
        <h2>Customer</h2>
        <p>Keep billing/reminder contact separate from the actual delivery client when a tie-up partner is involved.</p>
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
                    <div class="party-flow-hint">Keep the order fast: direct customer stays simple, while business partner only appears when billing and delivery go to different people.</div>
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
                                        {{ $customer->name }}{{ $customer->phone ? ' • ' . $customer->phone : '' }}
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
                                        {{ $partner->displayName() }}{{ $partner->phone ? ' • ' . $partner->phone : '' }}
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
                                        {{ $client->displayName() }}{{ $client->primaryPhone() ? ' • ' . $client->primaryPhone() : '' }}
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
                            <strong id="saleReminderContactSummary">{{ $selectedCustomerType === 'business_partner' ? (($selectedBusinessPartner?->displayName() ?: 'Select business partner') . (($selectedBusinessPartner?->phone) ? ' • ' . $selectedBusinessPartner->phone : '')) : (($selectedCustomer?->name ?: 'Select customer') . (($selectedCustomer?->phone) ? ' • ' . $selectedCustomer->phone : '')) }}</strong>
                        </div>
                        <div class="party-flow-line" id="saleDeliveryContactLine"{{ $selectedCustomerType === 'business_partner' ? '' : ' hidden' }}>
                            <label id="saleDeliveryContactLabel">Delivery / Service Contact</label>
                            <strong id="saleDeliveryContactSummary">{{ $selectedCustomerType === 'business_partner' ? (($selectedPartnerClient?->displayName() ?: 'Select actual client') . (($selectedPartnerClient?->primaryPhone()) ? ' • ' . $selectedPartnerClient->primaryPhone() : '')) : (($selectedCustomer?->name ?: 'Select customer') . (($selectedCustomer?->phone) ? ' • ' . $selectedCustomer->phone : '')) }}</strong>
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
    </div>

    <div class="sales-card section-nav-target" id="sale-products-section">
        <h2>Product Items</h2>
        <p>Add one or many product lines. Each line calculates its own total, then one common shipping charge is added at the sale level.</p>

        <div class="sales-items-toolbar">
            <strong id="salesItemsToolbarSummary">{{ count($initialSaleItems) }} product line(s)</strong>
            <button type="button" class="sales-btn-light" id="addSaleItemButton">Add Product</button>
        </div>

        <div class="sales-item-list" id="saleItemsList"></div>

        <div class="sales-order-total section-nav-target" id="sale-pricing-section" style="margin-top:14px;">
            <div>
                <div style="color:#64748b; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em;">Sale Net Amount</div>
                <strong id="saleOrderTotal">₹0.00</strong>
            </div>
            <div class="sales-field {{ $errors->has('shipping_charges') ? 'is-error' : '' }}" style="min-width:220px; margin:0;">
                <label for="shipping_charges">Shipping Cost</label>
                <input type="number" step="0.01" min="0" name="shipping_charges" id="shipping_charges" value="{{ $initialShippingCharges }}">
                <small>Applied once to the whole sale, not repeated per product line.</small>
                @error('shipping_charges')<div class="sales-field-error">{{ $message }}</div>@enderror
            </div>
            <div class="sales-field" style="min-width:220px; margin:0;">
                <label for="sale_amount">Calculated Total</label>
                <input type="number" step="0.01" min="0" name="sale_amount" id="sale_amount" value="{{ old('sale_amount', $sale->sale_amount ?? 0) }}" readonly>
                <small>Saved as product totals plus sale-level shipping.</small>
            </div>
        </div>
    </div>

    <div class="section-nav-target" id="sale-save-section" style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
        <div style="border:1px solid #e2e8f0; border-radius:14px; background:#f8fafc; color:#475569; padding:12px 14px; font-size:12px;">
            This sale can still generate a single invoice, but that invoice will now carry every product line on the order.
        </div>
        <div class="sales-actions">
            <a href="{{ route('sales.index') }}" class="sales-btn-light">Cancel</a>
            <button type="submit" class="sales-btn">{{ $isEdit ? 'Update Sale' : 'Save Sale' }}</button>
        </div>
    </div>
</div>

    <script>
    (function () {
        const initialSaleItems = @json(array_values($initialSaleItems));
        const products = @json($productData);
        const assets = @json($assetData);
        const organizationState = @json($organizationState);
        const partnerClientEndpointTemplate = @json($businessPartnerFlowAvailable ? route('sales.business-partners.actual-clients', ['business_partner' => '__PARTNER__']) : null);

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
        const saleShippingInput = document.getElementById('shipping_charges');
        const saleFulfilmentSource = document.getElementById('sale_fulfilment_source');
        const saleVendorSelect = document.getElementById('sale_vendor_id');
        const saleDeliveryResponsibilitySelect = document.getElementById('sale_delivery_responsibility');
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
        const standardGstRates = ['0.00', '5.00', '12.00', '18.00', '28.00'];

        const productMap = new Map(products.map(function (product) { return [parseInt(product.id, 10), product]; }));
        const assetMap = new Map(assets.map(function (asset) { return [parseInt(asset.id, 10), asset]; }));
        const partnerClientCache = new Map();
        let partnerClientRequestToken = 0;

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

            if (saleVendorSelect) {
                saleVendorSelect.disabled = !vendorSupplied;
            }

            if (!vendorSupplied && saleDeliveryResponsibilitySelect) {
                saleDeliveryResponsibilitySelect.value = 'ph_internal_delivery';
            }
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
                    button.textContent = option.textContent.trim();
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

        function updateSaleSummary() {
            const productTotal = saleItems.reduce(function (carry, item) {
                return carry + lineCommercials(item);
            }, 0);
            const shipping = Math.max(parseFloat((saleShippingInput && saleShippingInput.value) || 0), 0);
            const total = productTotal + shipping;

            saleAmountInput.value = total.toFixed(2);
            saleOrderTotal.textContent = formatCurrency(total);
            saleAmountMetric.textContent = formatCurrency(total);
            saleItemsCountMetric.textContent = saleItems.length + ' line(s)';
            salesItemsToolbarSummary.textContent = saleItems.length + ' product line(s)';
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
                saleReminderContactSummary.textContent = partner ? [partner.name, partner.phone].filter(Boolean).join(' • ') : 'Select business partner';
                saleDeliveryContactSummary.textContent = client ? [client.name, client.phone].filter(Boolean).join(' • ') : 'Select actual client';
                saleDeliveryAddressSummary.textContent = client ? [client.address, client.city, client.state].filter(Boolean).join(', ') : '';
                saleCustomerFlowChip.textContent = 'Business Partner';
            } else {
                saleCustomerMetric.textContent = customer ? customer.name : 'Select customer';
                saleReminderContactSummary.textContent = customer ? [customer.name, customer.phone].filter(Boolean).join(' • ') : 'Select customer';
                saleDeliveryContactSummary.textContent = customer ? [customer.name, customer.phone].filter(Boolean).join(' • ') : 'Select customer';
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
            option.textContent = [client.name, client.phone].filter(Boolean).join(' • ') || client.name || 'Actual client';
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

        function productOptionsHtml(selectedProductId) {
            let html = '<option value="">Select product</option>';

            products.forEach(function (product) {
                const search = [product.name, product.brand, product.model_name, product.sku, product.product_code].filter(Boolean).join(' ');
                html += '<option value="' + product.id + '" data-search="' + escapeHtml(search) + '"' + (selectedProductId === parseInt(product.id, 10) ? ' selected' : '') + '>' + escapeHtml(product.name) + '</option>';
            });

            return html;
        }

        function assetOptionsHtml(item) {
            let html = '<option value="">No serialized asset link</option>';

            assets.forEach(function (asset) {
                if (item.product_id && parseInt(asset.product_id, 10) !== parseInt(item.product_id, 10)) {
                    return;
                }

                html += '<option value="' + asset.id + '"' + (parseInt(item.asset_id || 0, 10) === parseInt(asset.id, 10) ? ' selected' : '') + '>' + escapeHtml(asset.label) + '</option>';
            });

            return html;
        }

        function renderSaleItems() {
            saleItemsList.innerHTML = '';

            saleItems.forEach(function (item, index) {
                const total = lineCommercials(item);
                const product = item.product_id ? productMap.get(parseInt(item.product_id, 10)) : null;

                const row = document.createElement('div');
                row.className = 'sales-item-card';
                row.innerHTML = `
                    <div class="sales-item-head">
                        <div>
                            <strong>${escapeHtml(product ? product.name : 'Product line #' + (index + 1))}</strong>
                            <div class="sales-line-pill">Line ${index + 1}</div>
                        </div>
                        ${saleItems.length > 1 ? '<button type="button" class="sales-remove-btn" data-remove-index="' + index + '">Remove</button>' : ''}
                    </div>
                    <div class="sales-grid">
                        <div class="sales-field sales-col-6">
                            <label for="sale_item_product_${index}">Product</label>
                            <select name="sale_items[${index}][product_id]" id="sale_item_product_${index}" data-searchable-select data-search-placeholder="Search product by name, brand, model, SKU, or code">
                                ${productOptionsHtml(item.product_id ? parseInt(item.product_id, 10) : null)}
                            </select>
                        </div>
                        <div class="sales-field sales-col-6">
                            <label for="sale_item_asset_${index}">Asset Link</label>
                            <select name="sale_items[${index}][asset_id]" id="sale_item_asset_${index}">
                                ${assetOptionsHtml(item)}
                            </select>
                            <small>Optional serialized asset for tracked sale stock.</small>
                        </div>
                        <div class="sales-field sales-col-3">
                            <label for="sale_item_quantity_${index}">Qty</label>
                            <input type="number" min="1" step="1" name="sale_items[${index}][quantity]" id="sale_item_quantity_${index}" value="${escapeHtml(item.quantity)}">
                        </div>
                        <div class="sales-field sales-col-3">
                            <label for="sale_item_unit_price_${index}">Unit Price</label>
                            <input type="number" min="0" step="0.01" name="sale_items[${index}][unit_price]" id="sale_item_unit_price_${index}" value="${escapeHtml(item.unit_price)}">
                        </div>
                        <div class="sales-field sales-col-3">
                            <label for="sale_item_discount_${index}">Discount</label>
                            <input type="number" min="0" step="0.01" name="sale_items[${index}][discount_amount]" id="sale_item_discount_${index}" value="${escapeHtml(item.discount_amount)}">
                        </div>
                        <div class="sales-field sales-col-3">
                            <label for="sale_item_tax_${index}">GST %</label>
                            <select name="sale_items[${index}][tax_percentage]" id="sale_item_tax_${index}">
                                ${gstOptionsHtml(item.tax_percentage)}
                            </select>
                        </div>
                        <div class="sales-field sales-col-3">
                            <label for="sale_item_tax_mode_${index}">GST Mode</label>
                            <select name="sale_items[${index}][tax_calculation_mode]" id="sale_item_tax_mode_${index}">
                                <option value="exclusive"${item.tax_calculation_mode === 'exclusive' ? ' selected' : ''}>Exclusive</option>
                                <option value="inclusive"${item.tax_calculation_mode === 'inclusive' ? ' selected' : ''}>Inclusive</option>
                            </select>
                        </div>
                        <div class="sales-field sales-col-3">
                            <label for="sale_item_tax_type_${index}">Tax Type</label>
                            <select name="sale_items[${index}][tax_type]" id="sale_item_tax_type_${index}">
                                ${taxTypeOptionsHtml(item.tax_type)}
                            </select>
                        </div>
                        <div class="sales-field sales-col-6">
                            <label for="sale_item_notes_${index}">Item Notes</label>
                            <input type="text" name="sale_items[${index}][notes]" id="sale_item_notes_${index}" value="${escapeHtml(item.notes || '')}" placeholder="Optional item note">
                        </div>
                        <div class="sales-col-12">
                            <div class="sales-line-total">
                                <div>
                                    <span>Line Total</span>
                                    <strong id="sale_item_total_${index}">${formatCurrency(total)}</strong>
                                </div>
                                <div style="font-size:12px; opacity:.8;">Discount, GST mode, and GST % are included here. Shipping is added once at sale level.</div>
                            </div>
                        </div>
                    </div>
                `;

                saleItemsList.appendChild(row);

                const productSelect = row.querySelector('#sale_item_product_' + index);
                const assetSelect = row.querySelector('#sale_item_asset_' + index);
                const quantityInput = row.querySelector('#sale_item_quantity_' + index);
                const unitPriceInput = row.querySelector('#sale_item_unit_price_' + index);
                const discountInput = row.querySelector('#sale_item_discount_' + index);
                const taxInput = row.querySelector('#sale_item_tax_' + index);
                const taxModeSelect = row.querySelector('#sale_item_tax_mode_' + index);
                const taxTypeSelect = row.querySelector('#sale_item_tax_type_' + index);
                const notesInput = row.querySelector('#sale_item_notes_' + index);
                const totalLabel = row.querySelector('#sale_item_total_' + index);
                const removeButton = row.querySelector('[data-remove-index="' + index + '"]');

                enhanceSearchableSelect(productSelect);

                function refreshLineTotal() {
                    totalLabel.textContent = formatCurrency(lineCommercials(saleItems[index]));
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

                quantityInput.addEventListener('input', function () {
                    saleItems[index].quantity = Math.max(parseInt(this.value || 1, 10), 1);
                    refreshLineTotal();
                });

                unitPriceInput.addEventListener('input', function () {
                    saleItems[index].unit_price = normalizeMoney(this.value);
                    refreshLineTotal();
                });

                discountInput.addEventListener('input', function () {
                    saleItems[index].discount_amount = normalizeMoney(this.value);
                    refreshLineTotal();
                });

                taxInput.addEventListener('change', function () {
                    saleItems[index].tax_percentage = normalizeMoney(this.value);
                    refreshLineTotal();
                });

                taxModeSelect.addEventListener('change', function () {
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

        addSaleItemButton.addEventListener('click', function () {
            saleItems.push(defaultSaleItem());
            renderSaleItems();
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
            option.textContent = partner.phone ? partner.name + ' • ' + partner.phone : partner.name;
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
            option.textContent = client.phone ? client.name + ' • ' + client.phone : client.name;
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
        updateCustomerModeVisibility();
        updateCustomerPanel();
        syncCustomerFromRental();
        syncSaleFulfilmentControls();
        renderSaleItems();
    })();
</script>
