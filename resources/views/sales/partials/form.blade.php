@php
    $sale = $sale ?? null;
    $isEdit = (bool) $sale;
    $selectedCustomerId = (int) old('customer_id', $sale->customer_id ?? request('customer_id'));
    $selectedRentalId = (int) old('rental_id', $sale->rental_id ?? null);
    $selectedCustomer = $customers->firstWhere('id', $selectedCustomerId);
    $selectedRental = ($rentals ?? collect())->firstWhere('id', $selectedRentalId);
    $organizationState = optional(auth()->user()->organization)->state;

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

    $rentalData = ($rentals ?? collect())->map(fn ($rental) => [
        'id' => $rental->id,
        'customer_id' => $rental->customer_id,
        'product_id' => $rental->product_id,
        'label' => 'Rental #' . $rental->id . ' - ' . ($rental->customer?->name ?? $rental->customer_name ?? 'Customer') . ' - ' . ($rental->product?->name ?? 'Product'),
    ])->values();

    $customerData = $customers->map(fn ($customer) => [
        'id' => $customer->id,
        'name' => $customer->name,
        'phone' => $customer->phone,
        'email' => $customer->email,
        'city' => $customer->city,
        'state' => $customer->state,
    ])->values();
@endphp

<style>
    .sales-shell { display:grid; gap:16px; padding:18px 22px 28px; }
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
    }
    .sales-card h2 { margin:0 0 4px; font-size:17px; color:#0f172a; }
    .sales-card > p { margin:0 0 14px; color:#64748b; font-size:12px; }
    .sales-grid { display:grid; grid-template-columns:repeat(12, minmax(0, 1fr)); gap:12px 14px; }
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
        .sales-shell { padding:14px; }
        .sales-summary { grid-template-columns:1fr; }
        .sales-card { padding:14px; border-radius:16px; }
        .sales-header h1 { font-size:24px; }
        .sales-item-head { align-items:flex-start; }
        .sales-order-total { align-items:flex-start; }
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

    <div class="sales-card">
        <h2>Sale Snapshot</h2>
        <p>Quick summary before save.</p>
        <div class="sales-summary">
            <div class="sales-metric">
                <span>Mode</span>
                <strong>{{ $isEdit ? 'Update Existing' : 'Create Fresh' }}</strong>
            </div>
            <div class="sales-metric">
                <span>Customer</span>
                <strong id="saleCustomerMetric">{{ $selectedCustomer?->name ?: 'Select customer' }}</strong>
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

    <div class="sales-card">
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
                            {{ (int) $selectedRentalId === $rental->id ? 'selected' : '' }}>
                            Rental #{{ $rental->id }} - {{ $rental->customer?->name ?? $rental->customer_name ?? 'Customer' }} - {{ $rental->product?->name ?? 'Product' }}
                        </option>
                    @endforeach
                </select>
                @error('rental_id')<div class="sales-field-error">{{ $message }}</div>@enderror
                <small>If linked, the selected customer will sync to the rental customer.</small>
            </div>
            <div class="sales-field sales-col-12 {{ $errors->has('notes') ? 'is-error' : '' }}">
                <label for="notes">Sale Notes</label>
                <textarea name="notes" id="notes" rows="3" placeholder="Internal context, delivery note, or payment follow-up">{{ old('notes', $sale->notes ?? '') }}</textarea>
                @error('notes')<div class="sales-field-error">{{ $message }}</div>@enderror
            </div>
        </div>
    </div>

    <div class="sales-card">
        <h2>Customer</h2>
        <p>Keep the customer selection separate so invoice and follow-up context stays clear.</p>
        <div class="sales-grid">
            <div class="sales-col-8">
                <div class="sales-inline">
                    <div class="sales-field {{ $errors->has('customer_id') ? 'is-error' : '' }}">
                        <label for="customer_id">Customer</label>
                        <select name="customer_id" id="customer_id" required data-searchable-select data-search-placeholder="Search customer by name, phone, email, or city">
                            <option value="">Select customer</option>
                            @foreach($customers as $customer)
                                <option
                                    value="{{ $customer->id }}"
                                    data-name="{{ $customer->name }}"
                                    data-phone="{{ $customer->phone }}"
                                    data-email="{{ $customer->email }}"
                                    data-city="{{ $customer->city }}"
                                    data-state="{{ $customer->state }}"
                                    data-search="{{ trim(implode(' ', array_filter([$customer->name, $customer->phone, $customer->email, $customer->city]))) }}"
                                    {{ $selectedCustomerId === $customer->id ? 'selected' : '' }}>
                                    {{ $customer->name }}{{ $customer->phone ? ' - ' . $customer->phone : '' }}
                                </option>
                            @endforeach
                        </select>
                        @error('customer_id')<div class="sales-field-error">{{ $message }}</div>@enderror
                    </div>
                    <button type="button" class="sales-quick-btn" data-open-modal="saleQuickCustomerModal">Add Customer</button>
                </div>
            </div>
            <div class="sales-col-4">
                <div class="sales-customer-card">
                    <strong id="saleCustomerName">{{ $selectedCustomer?->name ?: 'Select customer' }}</strong>
                    <div id="saleCustomerPhone">{{ $selectedCustomer?->phone ?: 'Phone will appear here' }}</div>
                    <div id="saleCustomerEmail">{{ $selectedCustomer?->email ?: 'Email will appear here' }}</div>
                    <div id="saleCustomerCity">{{ $selectedCustomer?->city ?: 'City will appear here' }}</div>
                </div>
            </div>
        </div>
    </div>

    <div class="sales-card">
        <h2>Product Items</h2>
        <p>Add one or many product lines. Each line calculates its own total, then one common shipping charge is added at the sale level.</p>

        <div class="sales-items-toolbar">
            <strong id="salesItemsToolbarSummary">{{ count($initialSaleItems) }} product line(s)</strong>
            <button type="button" class="sales-btn-light" id="addSaleItemButton">Add Product</button>
        </div>

        <div class="sales-item-list" id="saleItemsList"></div>

        <div class="sales-order-total" style="margin-top:14px;">
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

    <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
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
        const customers = @json($customerData);
        const rentals = @json($rentalData);
        const organizationState = @json($organizationState);

        const customerSelect = document.getElementById('customer_id');
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
        const saleCustomerName = document.getElementById('saleCustomerName');
        const saleCustomerPhone = document.getElementById('saleCustomerPhone');
        const saleCustomerEmail = document.getElementById('saleCustomerEmail');
        const saleCustomerCity = document.getElementById('saleCustomerCity');
        const standardGstRates = ['0.00', '5.00', '12.00', '18.00', '28.00'];

        const productMap = new Map(products.map(function (product) { return [parseInt(product.id, 10), product]; }));
        const assetMap = new Map(assets.map(function (asset) { return [parseInt(asset.id, 10), asset]; }));
        const customerMap = new Map(customers.map(function (customer) { return [parseInt(customer.id, 10), customer]; }));

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

        function selectedCustomerData() {
            const customerId = customerSelect.value ? parseInt(customerSelect.value, 10) : null;
            return customerId ? customerMap.get(customerId) : null;
        }

        function recommendedTaxType(customerStateValue) {
            const customerState = normalizeStateName(customerStateValue || selectedCustomerData()?.state);
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
            const customer = selectedCustomerData();

            saleCustomerMetric.textContent = customer ? customer.name : 'Select customer';
            saleCustomerName.textContent = customer ? customer.name : 'Select customer';
            saleCustomerPhone.textContent = customer && customer.phone ? customer.phone : 'Phone will appear here';
            saleCustomerEmail.textContent = customer && customer.email ? customer.email : 'Email will appear here';
            saleCustomerCity.textContent = customer && customer.city ? customer.city : 'City will appear here';

            const recommended = recommendedTaxType(customer?.state);
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

        function syncCustomerFromRental() {
            const selected = selectedOption(rentalSelect);
            if (!selected || !rentalSelect.value) {
                return;
            }

            const rentalCustomerId = selected.getAttribute('data-customer-id');
            if (rentalCustomerId && customerSelect.value !== rentalCustomerId) {
                customerSelect.value = rentalCustomerId;
                customerSelect.dispatchEvent(new Event('change', { bubbles: true }));
            }
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

        customerSelect.addEventListener('change', updateCustomerPanel);
        rentalSelect.addEventListener('change', syncCustomerFromRental);
        if (saleShippingInput) {
            saleShippingInput.addEventListener('input', updateSaleSummary);
        }

        enhanceSearchableSelect(customerSelect);
        enhanceSearchableSelect(rentalSelect);
        updateCustomerPanel();
        syncCustomerFromRental();
        renderSaleItems();
    })();
</script>
