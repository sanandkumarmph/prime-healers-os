@php
    $sale = $sale ?? null;
    $isEdit = (bool) $sale;
    $selectedCustomerId = old('customer_id', $sale->customer_id ?? request('customer_id'));
    $selectedProductId = old('product_id', $sale->product_id ?? null);
    $selectedAssetId = old('asset_id', $sale->asset_id ?? null);
    $selectedRentalId = old('rental_id', $sale->rental_id ?? null);
    $selectedProduct = $products->firstWhere('id', (int) $selectedProductId);
    $selectedAsset = ($assets ?? collect())->firstWhere('id', (int) $selectedAssetId);
    $selectedRental = ($rentals ?? collect())->firstWhere('id', (int) $selectedRentalId);

    $quantityValue = (int) old('quantity', $sale->quantity ?? 1);
    $unitPriceValue = (float) old('unit_price', $sale->unit_price ?? $selectedProduct?->sale_price ?? 0);
    $discountValue = (float) old('discount_amount', $sale->discount_amount ?? 0);
    $shippingValue = (float) old('shipping_charges', $sale->shipping_charges ?? 0);
    $taxPercentageValue = (float) old('tax_percentage', $sale->tax_percentage ?? 0);
    $taxCalculationModeValue = old('tax_calculation_mode', $sale->tax_calculation_mode ?? 'exclusive');
    $saleAmountValue = (float) old('sale_amount', $sale->sale_amount ?? 0);

    $currency = fn ($value) => \App\Support\CurrencyFormatter::format((float) $value);
    $hasFieldError = fn (string $field) => $errors->has($field);
    $fieldError = fn (string $field) => $errors->first($field);
@endphp

<style>
    .sales-shell { display:grid; gap:16px; padding:18px 22px 28px; }
    .sales-header { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap; }
    .sales-header h1 { margin:0; font-size:26px; color:#0f172a; }
    .sales-header p { margin:6px 0 0; color:#64748b; font-size:13px; max-width:760px; }
    .sales-actions { display:flex; gap:8px; flex-wrap:wrap; }
    .sales-card {
        background:#fff;
        border:1px solid #dbe3ef;
        border-radius:14px;
        padding:16px 18px;
        box-shadow:0 8px 24px rgba(15, 23, 42, 0.04);
    }
    .sales-card h2 { margin:0 0 4px; font-size:16px; color:#0f172a; }
    .sales-card p { margin:0 0 14px; color:#64748b; font-size:12px; }
    .sales-summary { display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:10px; }
    .sales-metric {
        border:1px solid #e2e8f0;
        border-radius:12px;
        background:#f8fafc;
        padding:12px;
    }
    .sales-metric span {
        display:block;
        font-size:11px;
        font-weight:700;
        text-transform:uppercase;
        letter-spacing:.04em;
        color:#64748b;
    }
    .sales-metric strong {
        display:block;
        margin-top:6px;
        font-size:18px;
        color:#0f172a;
    }
    .sales-grid { display:grid; grid-template-columns:repeat(12, minmax(0, 1fr)); gap:12px 14px; }
    .sales-col-3 { grid-column:span 3; }
    .sales-col-4 { grid-column:span 4; }
    .sales-col-5 { grid-column:span 5; }
    .sales-col-6 { grid-column:span 6; }
    .sales-col-7 { grid-column:span 7; }
    .sales-col-8 { grid-column:span 8; }
    .sales-col-12 { grid-column:span 12; }
    .sales-field { display:flex; flex-direction:column; gap:6px; }
    .sales-field label {
        font-size:11px;
        font-weight:700;
        text-transform:uppercase;
        letter-spacing:.04em;
        color:#475569;
    }
    .sales-field small { font-size:11px; color:#94a3b8; }
    .sales-field input,
    .sales-field select,
    .sales-field textarea {
        width:100%;
        box-sizing:border-box;
        padding:10px 12px;
        border-radius:10px;
        border:1px solid #cbd5e1;
        background:#fff;
        color:#0f172a;
        font-size:14px;
    }
    .sales-field textarea { min-height:92px; resize:vertical; }
    .sales-field input:focus,
    .sales-field select:focus,
    .sales-field textarea:focus {
        outline:none;
        border-color:#2563eb;
        box-shadow:0 0 0 3px rgba(37, 99, 235, 0.12);
    }
    .sales-field.is-error input,
    .sales-field.is-error select,
    .sales-field.is-error textarea {
        border-color:#dc2626;
        box-shadow:0 0 0 3px rgba(220, 38, 38, 0.12);
        background:#fff7f7;
    }
    .sales-field-error {
        color:#b91c1c;
        font-size:12px;
        line-height:1.4;
    }
    .searchable-select-native {
        position:absolute !important;
        width:1px !important;
        height:1px !important;
        padding:0 !important;
        margin:-1px !important;
        overflow:hidden !important;
        clip:rect(0, 0, 0, 0) !important;
        white-space:nowrap !important;
        border:0 !important;
    }
    .searchable-select {
        position:relative;
    }
    .searchable-select-trigger {
        width:100%;
        min-height:44px;
        padding:10px 12px;
        border-radius:10px;
        border:1px solid #cbd5e1;
        background:#fff;
        color:#0f172a;
        font-size:14px;
        text-align:left;
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:10px;
        cursor:pointer;
    }
    .searchable-select-trigger::after {
        content:"";
        width:8px;
        height:8px;
        border-right:2px solid #64748b;
        border-bottom:2px solid #64748b;
        transform:rotate(45deg);
        flex:0 0 8px;
        margin-top:-4px;
    }
    .searchable-select.is-open .searchable-select-trigger,
    .searchable-select-trigger:focus {
        outline:none;
        border-color:#2563eb;
        box-shadow:0 0 0 3px rgba(37, 99, 235, 0.12);
    }
    .searchable-select-panel {
        position:absolute;
        top:calc(100% + 6px);
        left:0;
        right:0;
        z-index:35;
        padding:10px;
        border:1px solid #cbd5e1;
        border-radius:12px;
        background:#fff;
        box-shadow:0 18px 40px rgba(15, 23, 42, 0.14);
        display:grid;
        gap:8px;
    }
    .searchable-select-panel[hidden] { display:none !important; }
    .searchable-select-search {
        width:100%;
        padding:9px 11px;
        border-radius:10px;
        border:1px solid #cbd5e1;
        font-size:13px;
        box-sizing:border-box;
    }
    .searchable-select-options {
        max-height:220px;
        overflow:auto;
        display:grid;
        gap:4px;
    }
    .searchable-select-option,
    .searchable-select-empty {
        width:100%;
        padding:9px 11px;
        border:none;
        border-radius:9px;
        background:#fff;
        color:#0f172a;
        font-size:13px;
        text-align:left;
    }
    .searchable-select-option {
        cursor:pointer;
    }
    .searchable-select-option:hover,
    .searchable-select-option.is-selected {
        background:#eff6ff;
        color:#1d4ed8;
    }
    .searchable-select-empty {
        color:#64748b;
    }
    .sales-field.is-error .searchable-select-trigger {
        border-color:#dc2626;
        box-shadow:0 0 0 3px rgba(220, 38, 38, 0.12);
        background:#fff7f7;
    }
    .sales-inline {
        display:flex;
        gap:8px;
        align-items:flex-end;
        flex-wrap:wrap;
    }
    .sales-inline .sales-field { flex:1 1 260px; }
    .sales-quick-btn,
    .sales-btn,
    .sales-btn-light {
        display:inline-flex;
        align-items:center;
        justify-content:center;
        gap:6px;
        padding:8px 12px;
        border-radius:10px;
        font-size:13px;
        font-weight:600;
        text-decoration:none;
        border:1px solid transparent;
        cursor:pointer;
        line-height:1.2;
    }
    .sales-btn { background:#0f172a; color:#fff; }
    .sales-btn-light { background:#fff; color:#334155; border-color:#cbd5e1; }
    .sales-quick-btn { background:#eff6ff; color:#1d4ed8; border-color:#bfdbfe; white-space:nowrap; }
    .sales-note {
        border:1px solid #e2e8f0;
        border-radius:12px;
        background:#f8fafc;
        color:#475569;
        padding:10px 12px;
        font-size:12px;
    }
    .sales-errors {
        border:1px solid #fecaca;
        background:#fef2f2;
        color:#b91c1c;
        border-radius:12px;
        padding:14px 16px;
    }
    .sales-actions-row {
        display:flex;
        justify-content:space-between;
        align-items:center;
        gap:12px;
        flex-wrap:wrap;
    }
    .sales-link-grid {
        display:grid;
        grid-template-columns:repeat(2, minmax(0, 1fr));
        gap:10px;
    }
    .sales-link-card {
        border:1px solid #dbe3ef;
        background:#f8fafc;
        border-radius:12px;
        padding:12px 14px;
        display:grid;
        gap:5px;
    }
    .sales-link-card span {
        font-size:11px;
        font-weight:700;
        text-transform:uppercase;
        letter-spacing:.04em;
        color:#64748b;
    }
    .sales-link-card strong { color:#0f172a; font-size:15px; }
    .sales-link-card small { color:#64748b; font-size:12px; }
    .quick-customer-modal {
        position:fixed;
        inset:0;
        z-index:1050;
        display:grid;
        place-items:center;
        padding:18px;
    }
    .quick-customer-modal[hidden] {
        display:none !important;
    }
    .quick-customer-backdrop {
        position:absolute;
        inset:0;
        background:rgba(15, 23, 42, 0.52);
    }
    .quick-customer-dialog {
        position:relative;
        width:min(760px, 100%);
        background:#fff;
        border-radius:18px;
        border:1px solid #dbe3ef;
        box-shadow:0 24px 60px rgba(15, 23, 42, 0.22);
        overflow:hidden;
    }
    .quick-customer-header {
        display:flex;
        justify-content:space-between;
        align-items:flex-start;
        gap:12px;
        padding:16px 18px 10px;
        border-bottom:1px solid #e2e8f0;
    }
    .quick-customer-header h3 { margin:0; font-size:18px; color:#0f172a; }
    .quick-customer-header p { margin:5px 0 0; color:#64748b; font-size:12px; }
    .quick-customer-close {
        width:34px;
        height:34px;
        border:none;
        border-radius:999px;
        background:#f1f5f9;
        color:#334155;
        cursor:pointer;
        font-size:22px;
        line-height:1;
    }
    .quick-customer-form { padding:14px 18px 18px; display:grid; gap:12px; }
    .quick-customer-grid { display:grid; grid-template-columns:repeat(12, minmax(0, 1fr)); gap:12px; }
    .quick-col-4 { grid-column:span 4; }
    .quick-col-8 { grid-column:span 8; }
    .quick-col-12 { grid-column:span 12; }
    .quick-inline-grid { display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:12px; }
    .quick-field { display:flex; flex-direction:column; gap:6px; }
    .quick-field label {
        font-size:11px;
        font-weight:700;
        color:#475569;
        letter-spacing:.04em;
        text-transform:uppercase;
    }
    .quick-field input,
    .quick-field select,
    .quick-field textarea {
        width:100%;
        box-sizing:border-box;
        padding:9px 11px;
        border-radius:10px;
        border:1px solid #cbd5e1;
        background:#fff;
        color:#0f172a;
        font-size:14px;
    }
    .quick-field textarea { resize:vertical; min-height:76px; }
    .quick-customer-actions {
        display:flex;
        justify-content:flex-end;
        gap:8px;
        flex-wrap:wrap;
    }
    .quick-btn {
        display:inline-flex;
        align-items:center;
        justify-content:center;
        gap:6px;
        padding:8px 12px;
        border-radius:10px;
        font-size:13px;
        font-weight:600;
        border:1px solid transparent;
        cursor:pointer;
    }
    .quick-btn-primary { background:#0f172a; color:#fff; }
    .quick-btn-light { background:#fff; color:#334155; border-color:#cbd5e1; }
    .quick-customer-alert {
        border-radius:10px;
        padding:10px 12px;
        font-size:12px;
        border:1px solid transparent;
    }
    .quick-customer-alert.is-error {
        background:#fef2f2;
        border-color:#fecaca;
        color:#b91c1c;
    }
    .quick-customer-alert.is-success {
        background:#dcfce7;
        border-color:#bbf7d0;
        color:#166534;
    }
    body.modal-open { overflow:hidden; }
    @media (max-width: 1024px) {
        .sales-col-3,
        .sales-col-4,
        .sales-col-5,
        .sales-col-6,
        .sales-col-7,
        .sales-col-8 { grid-column:span 12; }
        .sales-summary { grid-template-columns:repeat(2, minmax(0, 1fr)); }
        .sales-link-grid { grid-template-columns:1fr; }
    }
    @media (max-width: 720px) {
        .sales-shell { padding:14px; }
        .sales-summary { grid-template-columns:1fr; }
        .quick-col-4,
        .quick-col-8 { grid-column:span 12; }
        .quick-inline-grid { grid-template-columns:1fr; }
        .sales-snapshot-card { display:none; }
        .sales-header p,
        .sales-card > p {
            font-size:12px;
            line-height:1.45;
        }
        .sales-card {
            padding:14px !important;
            border-radius:14px !important;
        }
        .sales-card h2 {
            font-size:18px !important;
        }
    }
</style>

<div class="container sales-shell">
    <div class="sales-header">
        <div>
            <h1>{{ $isEdit ? 'Edit Sale' : 'Add Sale' }}</h1>
            <p>Create a sale, link an asset if needed, and invoice it.</p>
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

    <div class="sales-card sales-snapshot-card">
        <h2>Sale Snapshot</h2>
        <p>Quick check before save.</p>
        <div class="sales-summary">
            <div class="sales-metric">
                <span>Mode</span>
                <strong>{{ $isEdit ? 'Update Existing' : 'Create Fresh' }}</strong>
            </div>
            <div class="sales-metric">
                <span>Customer</span>
                <strong id="saleCustomerMetric">{{ optional($customers->firstWhere('id', (int) $selectedCustomerId))->name ?: 'Select customer' }}</strong>
            </div>
            <div class="sales-metric">
                <span>Product</span>
                <strong id="saleProductMetric">{{ $selectedProduct->name ?? 'Select product' }}</strong>
            </div>
            <div class="sales-metric">
                <span>Net Amount</span>
                <strong id="saleAmountMetric">{{ $currency($saleAmountValue) }}</strong>
            </div>
        </div>
    </div>

    <div class="sales-card">
        <h2>Customer & Product</h2>
        <p>Choose customer, product, and optional asset.</p>
        <div class="sales-grid">
            <div class="sales-col-7">
                <div class="sales-inline">
                    <div class="sales-field {{ $hasFieldError('customer_id') ? 'is-error' : '' }}">
                        <label for="customer_id">Customer</label>
                        <select name="customer_id" id="customer_id" required data-searchable-select data-search-placeholder="Search customer by name or phone">
                            <option value="">Select customer</option>
                            @foreach($customers as $customer)
                                <option
                                    value="{{ $customer->id }}"
                                    data-name="{{ $customer->name }}"
                                    data-phone="{{ $customer->phone }}"
                                    data-search="{{ trim(implode(' ', array_filter([$customer->name, $customer->phone, $customer->email, $customer->city]))) }}"
                                    {{ (int) $selectedCustomerId === $customer->id ? 'selected' : '' }}>
                                    {{ $customer->name }}{{ $customer->phone ? ' - ' . $customer->phone : '' }}
                                </option>
                            @endforeach
                        </select>
                        @if($fieldError('customer_id'))
                            <div class="sales-field-error">{{ $fieldError('customer_id') }}</div>
                        @endif
                    </div>
                    <button type="button" class="sales-quick-btn" data-open-modal="saleQuickCustomerModal">Add Customer</button>
                </div>
            </div>

            <div class="sales-field sales-col-5 {{ $hasFieldError('product_id') ? 'is-error' : '' }}">
                <label for="product_id">Product</label>
                <select name="product_id" id="product_id" required data-searchable-select data-search-placeholder="Search product by name, brand, model, SKU, or code">
                    <option value="">Select product</option>
                    @foreach($products as $product)
                        <option
                            value="{{ $product->id }}"
                            data-sale-price="{{ (float) ($product->sale_price ?? 0) }}"
                            data-search="{{ trim(implode(' ', array_filter([$product->name, $product->brand, $product->model_name, $product->sku, $product->product_code]))) }}"
                            {{ (int) $selectedProductId === $product->id ? 'selected' : '' }}>
                            {{ $product->name }}
                        </option>
                    @endforeach
                </select>
                @if($fieldError('product_id'))
                    <div class="sales-field-error">{{ $fieldError('product_id') }}</div>
                @endif
            </div>

            <div class="sales-field sales-col-6 {{ $hasFieldError('asset_id') ? 'is-error' : '' }}">
                <label for="asset_id">Asset Link</label>
                <select name="asset_id" id="asset_id">
                    <option value="">No serialized asset link</option>
                    @foreach(($assets ?? collect()) as $asset)
                        <option
                            value="{{ $asset->id }}"
                            data-product-id="{{ $asset->product_id }}"
                            {{ (int) $selectedAssetId === $asset->id ? 'selected' : '' }}>
                            {{ $asset->serial_number ?: $asset->asset_name ?: ('Asset #' . $asset->id) }}
                            - {{ $asset->product?->name ?? 'Product' }}
                            @if($asset->warehouse)
                                - {{ $asset->warehouse->name }}
                            @endif
                        </option>
                    @endforeach
                </select>
                @if($fieldError('asset_id'))
                    <div class="sales-field-error">{{ $fieldError('asset_id') }}</div>
                @endif
                <small>Use this for serialized fresh stock units sold directly to the customer.</small>
            </div>

            <div class="sales-field sales-col-6 {{ $hasFieldError('rental_id') ? 'is-error' : '' }}">
                <label for="rental_id">Linked Rental</label>
                <select name="rental_id" id="rental_id">
                    <option value="">No linked rental</option>
                    @foreach(($rentals ?? collect()) as $rental)
                        <option
                            value="{{ $rental->id }}"
                            data-customer-id="{{ $rental->customer_id }}"
                            data-product-id="{{ $rental->product_id }}"
                            {{ (int) $selectedRentalId === $rental->id ? 'selected' : '' }}>
                            #{{ $rental->id }} - {{ $rental->customer_name ?: $rental->customer?->name ?: 'Customer' }}
                            - {{ $rental->product?->name ?? 'Rental product' }}
                        </option>
                    @endforeach
                </select>
                @if($fieldError('rental_id'))
                    <div class="sales-field-error">{{ $fieldError('rental_id') }}</div>
                @endif
                <small>Link new products or sale follow-ups back to an existing rental when they belong to the same case.</small>
            </div>
        </div>
    </div>

    <div class="sales-card">
        <h2>Commercial Details</h2>
        <p>Capture quantity, unit rate, discount, shipping, and GST treatment. The final sale total is calculated automatically.</p>
        <div class="sales-grid">
            <div class="sales-field sales-col-3 {{ $hasFieldError('quantity') ? 'is-error' : '' }}">
                <label for="quantity">Quantity</label>
                <input type="number" name="quantity" id="quantity" value="{{ $quantityValue }}" min="1" required>
                @if($fieldError('quantity'))
                    <div class="sales-field-error">{{ $fieldError('quantity') }}</div>
                @endif
            </div>

            <div class="sales-field sales-col-3 {{ $hasFieldError('unit_price') ? 'is-error' : '' }}">
                <label for="unit_price">Unit Price</label>
                <input type="number" step="0.01" min="0" name="unit_price" id="unit_price" value="{{ $unitPriceValue }}" required>
                @if($fieldError('unit_price'))
                    <div class="sales-field-error">{{ $fieldError('unit_price') }}</div>
                @endif
            </div>

            <div class="sales-field sales-col-3 {{ $hasFieldError('discount_amount') ? 'is-error' : '' }}">
                <label for="discount_amount">Discount</label>
                <input type="number" step="0.01" min="0" name="discount_amount" id="discount_amount" value="{{ $discountValue }}">
                @if($fieldError('discount_amount'))
                    <div class="sales-field-error">{{ $fieldError('discount_amount') }}</div>
                @endif
            </div>

            <div class="sales-field sales-col-3 {{ $hasFieldError('shipping_charges') ? 'is-error' : '' }}">
                <label for="shipping_charges">Shipping Cost</label>
                <input type="number" step="0.01" min="0" name="shipping_charges" id="shipping_charges" value="{{ $shippingValue }}">
                @if($fieldError('shipping_charges'))
                    <div class="sales-field-error">{{ $fieldError('shipping_charges') }}</div>
                @endif
            </div>

            <div class="sales-field sales-col-3 {{ $hasFieldError('tax_percentage') ? 'is-error' : '' }}">
                <label for="tax_percentage">GST %</label>
                <select name="tax_percentage" id="tax_percentage">
                    @foreach([0, 5, 12, 18, 28] as $taxRate)
                        <option value="{{ $taxRate }}" @selected((float) $taxPercentageValue === (float) $taxRate)>{{ $taxRate }}%</option>
                    @endforeach
                </select>
                @if($fieldError('tax_percentage'))
                    <div class="sales-field-error">{{ $fieldError('tax_percentage') }}</div>
                @endif
            </div>

            <div class="sales-field sales-col-3 {{ $hasFieldError('tax_calculation_mode') ? 'is-error' : '' }}">
                <label for="tax_calculation_mode">GST Mode</label>
                <select name="tax_calculation_mode" id="tax_calculation_mode" required>
                    <option value="exclusive" @selected($taxCalculationModeValue === 'exclusive')>Exclusive</option>
                    <option value="inclusive" @selected($taxCalculationModeValue === 'inclusive')>Inclusive</option>
                </select>
                @if($fieldError('tax_calculation_mode'))
                    <div class="sales-field-error">{{ $fieldError('tax_calculation_mode') }}</div>
                @endif
            </div>

            <div class="sales-field sales-col-3 {{ $hasFieldError('sale_date') ? 'is-error' : '' }}">
                <label for="sale_date">Sale Date</label>
                <input type="date" name="sale_date" id="sale_date" value="{{ old('sale_date', isset($sale->sale_date) ? \Illuminate\Support\Carbon::parse($sale->sale_date)->format('Y-m-d') : now()->format('Y-m-d')) }}" required>
                @if($fieldError('sale_date'))
                    <div class="sales-field-error">{{ $fieldError('sale_date') }}</div>
                @endif
            </div>

            <div class="sales-field sales-col-3 {{ $hasFieldError('payment_status') ? 'is-error' : '' }}">
                <label for="payment_status">Payment Status</label>
                <select name="payment_status" id="payment_status" required>
                    <option value="pending" {{ old('payment_status', $sale->payment_status ?? 'pending') === 'pending' ? 'selected' : '' }}>Pending</option>
                    <option value="paid" {{ old('payment_status', $sale->payment_status ?? '') === 'paid' ? 'selected' : '' }}>Paid</option>
                    <option value="partial" {{ old('payment_status', $sale->payment_status ?? '') === 'partial' ? 'selected' : '' }}>Partial</option>
                    @if(old('payment_status', $sale->payment_status ?? '') === 'void')
                        <option value="void" selected>Void</option>
                    @endif
                </select>
                @if($fieldError('payment_status'))
                    <div class="sales-field-error">{{ $fieldError('payment_status') }}</div>
                @endif
            </div>

            <div class="sales-field sales-col-12 {{ $hasFieldError('sale_amount') ? 'is-error' : '' }}">
                <label for="sale_amount">Net Sale Amount</label>
                <input type="number" step="0.01" min="0" name="sale_amount" id="sale_amount" value="{{ $saleAmountValue }}" readonly required>
                @if($fieldError('sale_amount'))
                    <div class="sales-field-error">{{ $fieldError('sale_amount') }}</div>
                @endif
                <small>Calculated from quantity, unit price, discount, GST mode, GST percentage, and shipping.</small>
            </div>
        </div>
    </div>

    <div class="sales-card">
        <h2>Context & Notes</h2>
        <p>Keep internal context visible so the operations or finance desk can understand why this sale exists and how it connects to the rest of the workflow.</p>
        <div class="sales-link-grid">
            <div class="sales-link-card">
                <span>Linked Asset</span>
                <strong id="saleAssetMetric">{{ $selectedAsset?->serial_number ?: ($selectedAsset?->asset_name ?: 'Not linked') }}</strong>
                <small id="saleAssetSubtle">
                    @if($selectedAsset)
                        {{ $selectedAsset->product?->name ?? 'Product' }}{{ $selectedAsset->warehouse ? ' • ' . $selectedAsset->warehouse->name : '' }}
                    @else
                        Serialized unit can be linked when needed
                    @endif
                </small>
            </div>
            <div class="sales-link-card">
                <span>Linked Rental</span>
                <strong id="saleRentalMetric">{{ $selectedRental ? ('Rental #' . $selectedRental->id) : 'Not linked' }}</strong>
                <small id="saleRentalSubtle">
                    @if($selectedRental)
                        {{ $selectedRental->customer_name ?: $selectedRental->customer?->name ?: 'Customer' }} • {{ $selectedRental->product?->name ?? 'Rental product' }}
                    @else
                        Use this when the sale is tied to an existing rental case
                    @endif
                </small>
            </div>
        </div>

        <div class="sales-grid" style="margin-top:14px;">
            <div class="sales-field sales-col-12 {{ $hasFieldError('notes') ? 'is-error' : '' }}">
                <label for="notes">Notes</label>
                <textarea name="notes" id="notes" rows="3" placeholder="Internal notes, delivery comment, linked rental reason, or payment follow-up">{{ old('notes', $sale->notes ?? '') }}</textarea>
                @if($fieldError('notes'))
                    <div class="sales-field-error">{{ $fieldError('notes') }}</div>
                @endif
            </div>
        </div>
    </div>

    <div class="sales-actions-row">
        <div class="sales-note">
            One-click invoice generation will use this saved sales record, including GST mode, discount, shipping, and any linked rental context.
        </div>
        <div class="sales-actions">
            <a href="{{ route('sales.index') }}" class="sales-btn-light">Cancel</a>
            <button type="submit" class="sales-btn">{{ $isEdit ? 'Update Sale' : 'Save Sale' }}</button>
        </div>
    </div>
</div>

<script>
    (function () {
        const customerSelect = document.getElementById('customer_id');
        const productSelect = document.getElementById('product_id');
        const assetSelect = document.getElementById('asset_id');
        const assetOptions = Array.from(assetSelect ? assetSelect.options : []).map(function (option) {
            return {
                value: option.value,
                text: option.textContent,
                productId: option.getAttribute('data-product-id'),
                selected: option.selected,
            };
        });
        const rentalSelect = document.getElementById('rental_id');
        const rentalOptions = Array.from(rentalSelect ? rentalSelect.options : []).map(function (option) {
            return {
                value: option.value,
                text: option.textContent,
                customerId: option.getAttribute('data-customer-id'),
                productId: option.getAttribute('data-product-id'),
                selected: option.selected,
            };
        });
        const quantityInput = document.getElementById('quantity');
        const unitPriceInput = document.getElementById('unit_price');
        const discountInput = document.getElementById('discount_amount');
        const shippingInput = document.getElementById('shipping_charges');
        const taxSelect = document.getElementById('tax_percentage');
        const taxModeSelect = document.getElementById('tax_calculation_mode');
        const amountInput = document.getElementById('sale_amount');
        const customerMetric = document.getElementById('saleCustomerMetric');
        const productMetric = document.getElementById('saleProductMetric');
        const amountMetric = document.getElementById('saleAmountMetric');
        const assetMetric = document.getElementById('saleAssetMetric');
        const assetSubtle = document.getElementById('saleAssetSubtle');
        const rentalMetric = document.getElementById('saleRentalMetric');
        const rentalSubtle = document.getElementById('saleRentalSubtle');

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

            let optionButtons = [];

            function syncTriggerLabel() {
                const selected = select.options[select.selectedIndex];
                triggerLabel.textContent = selected ? selected.textContent.trim() : 'Select option';
            }

            function closePanel() {
                wrapper.classList.remove('is-open');
                panel.hidden = true;
                trigger.setAttribute('aria-expanded', 'false');
            }

            function openPanel() {
                wrapper.classList.add('is-open');
                panel.hidden = false;
                trigger.setAttribute('aria-expanded', 'true');
                searchInput.value = '';
                renderOptions();
                window.requestAnimationFrame(function () {
                    searchInput.focus();
                });
            }

            function renderOptions() {
                const query = searchInput.value.trim().toLowerCase();
                optionsWrap.innerHTML = '';
                optionButtons = [];

                Array.from(select.options).forEach(function (option) {
                    const searchText = (option.getAttribute('data-search') || option.textContent || '').toLowerCase();

                    if (query && !searchText.includes(query)) {
                        return;
                    }

                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'searchable-select-option' + (option.selected ? ' is-selected' : '');
                    button.textContent = option.textContent.trim();
                    button.dataset.value = option.value;
                    button.addEventListener('click', function () {
                        select.value = option.value;
                        select.dispatchEvent(new Event('change', { bubbles: true }));
                        syncTriggerLabel();
                        closePanel();
                        trigger.focus();
                    });
                    optionsWrap.appendChild(button);
                    optionButtons.push(button);
                });

                if (!optionButtons.length) {
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
                if (panel.hidden) {
                    openPanel();
                } else {
                    closePanel();
                }
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

        enhanceSearchableSelect(customerSelect);
        enhanceSearchableSelect(productSelect);

        function formatCurrency(value) {
            return new Intl.NumberFormat('en-IN', {
                style: 'currency',
                currency: 'INR',
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }).format(value || 0);
        }

        function selectedOption(select) {
            return select && select.selectedIndex >= 0 ? select.options[select.selectedIndex] : null;
        }

        function calculateAmount() {
            const quantity = Math.max(parseFloat(quantityInput.value || 0), 1);
            const unitPrice = Math.max(parseFloat(unitPriceInput.value || 0), 0);
            const discount = Math.max(parseFloat(discountInput.value || 0), 0);
            const shipping = Math.max(parseFloat(shippingInput.value || 0), 0);
            const taxPercentage = Math.max(parseFloat(taxSelect.value || 0), 0);
            const mode = taxModeSelect.value || 'exclusive';

            const subtotal = quantity * unitPrice;
            const taxableBase = Math.max(subtotal - discount, 0);
            let taxAmount = 0;
            let total = taxableBase + shipping;

            if (mode === 'inclusive' && taxPercentage > 0) {
                taxAmount = taxableBase - (taxableBase / (1 + (taxPercentage / 100)));
                total = taxableBase + shipping;
            } else if (taxPercentage > 0) {
                taxAmount = taxableBase * (taxPercentage / 100);
                total = taxableBase + taxAmount + shipping;
            }

            amountInput.value = total.toFixed(2);
            amountMetric.textContent = formatCurrency(total);
        }

        function updateCustomerMetric() {
            const selected = selectedOption(customerSelect);
            customerMetric.textContent = customerSelect.value && selected ? selected.textContent.trim() : 'Select customer';
        }

        function updateProductMetric() {
            const selected = selectedOption(productSelect);
            productMetric.textContent = productSelect.value && selected ? selected.textContent.trim() : 'Select product';

            if (selected && productSelect.value && !unitPriceInput.dataset.touched) {
                const suggestedPrice = parseFloat(selected.getAttribute('data-sale-price') || 0);
                if (!Number.isNaN(suggestedPrice) && suggestedPrice > 0) {
                    unitPriceInput.value = suggestedPrice.toFixed(2);
                }
            }

            calculateAmount();
        }

        function filterAssetOptions() {
            if (!assetSelect) {
                return;
            }

            const currentProductId = productSelect.value || '';
            const currentAssetId = assetSelect.value || '';

            assetSelect.innerHTML = '';

            assetOptions.forEach(function (item) {
                if (item.value && currentProductId && item.productId !== currentProductId) {
                    return;
                }

                const option = document.createElement('option');
                option.value = item.value;
                option.textContent = item.text;

                if (item.productId) {
                    option.setAttribute('data-product-id', item.productId);
                }

                if (currentAssetId && item.value === currentAssetId) {
                    option.selected = true;
                }

                assetSelect.appendChild(option);
            });

            if (currentAssetId && assetSelect.value !== currentAssetId) {
                assetSelect.value = '';
            }
        }

        function updateAssetMetric() {
            const selected = selectedOption(assetSelect);
            if (assetSelect.value && selected) {
                assetMetric.textContent = selected.textContent.trim().split(' - ')[0];
                assetSubtle.textContent = selected.textContent.trim();
            } else {
                assetMetric.textContent = 'Not linked';
                assetSubtle.textContent = 'Serialized unit can be linked when needed';
            }
        }

        function updateRentalMetric() {
            const selected = selectedOption(rentalSelect);
            if (rentalSelect.value && selected) {
                rentalMetric.textContent = 'Rental #' + rentalSelect.value;
                rentalSubtle.textContent = selected.textContent.trim();
            } else {
                rentalMetric.textContent = 'Not linked';
                rentalSubtle.textContent = 'Use this when the sale is tied to an existing rental case';
            }
        }

        function filterRentalOptions() {
            if (!rentalSelect) {
                return;
            }

            const currentCustomerId = customerSelect.value || '';
            const currentRentalId = rentalSelect.value || '';

            rentalSelect.innerHTML = '';

            rentalOptions.forEach(function (item) {
                if (item.value && currentCustomerId && item.customerId !== currentCustomerId) {
                    return;
                }

                const option = document.createElement('option');
                option.value = item.value;
                option.textContent = item.text;

                if (item.customerId) {
                    option.setAttribute('data-customer-id', item.customerId);
                }

                if (item.productId) {
                    option.setAttribute('data-product-id', item.productId);
                }

                if (currentRentalId && item.value === currentRentalId) {
                    option.selected = true;
                }

                rentalSelect.appendChild(option);
            });

            if (currentRentalId && rentalSelect.value !== currentRentalId) {
                rentalSelect.value = '';
            }
        }

        function synchronizeCustomerWithRental() {
            const selected = selectedOption(rentalSelect);

            if (!selected || !rentalSelect.value) {
                return;
            }

            const rentalCustomerId = selected.getAttribute('data-customer-id');

            if (rentalCustomerId && customerSelect.value !== rentalCustomerId) {
                customerSelect.value = rentalCustomerId;
                updateCustomerMetric();
            }
        }

        unitPriceInput.addEventListener('input', function () {
            unitPriceInput.dataset.touched = 'true';
            calculateAmount();
        });

        [quantityInput, discountInput, shippingInput].forEach(function (input) {
            input.addEventListener('input', calculateAmount);
        });

        [taxSelect, taxModeSelect].forEach(function (select) {
            select.addEventListener('change', calculateAmount);
        });

        customerSelect.addEventListener('change', function () {
            updateCustomerMetric();
            filterRentalOptions();
            updateRentalMetric();
        });
        productSelect.addEventListener('change', function () {
            updateProductMetric();
            filterAssetOptions();
            updateAssetMetric();
        });
        assetSelect.addEventListener('change', updateAssetMetric);
        rentalSelect.addEventListener('change', function () {
            synchronizeCustomerWithRental();
            filterRentalOptions();
            updateRentalMetric();
        });

        updateCustomerMetric();
        updateProductMetric();
        filterAssetOptions();
        updateAssetMetric();
        synchronizeCustomerWithRental();
        filterRentalOptions();
        updateRentalMetric();
        calculateAmount();
    })();
</script>
