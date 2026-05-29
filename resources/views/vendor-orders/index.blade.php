@extends('layouts.app')

@section('content')
@php
    $filterOptions = $filterOptions ?? [];
    $filters = $filters ?? [];
    $summary = $summary ?? [];
    $rows = $rows ?? collect();
    $canViewCosts = (bool) ($canViewCosts ?? false);
    $canUpdateCosts = (bool) ($canUpdateCosts ?? false);
    $currency = fn ($value) => 'Rs. ' . number_format((float) $value, 2);
@endphp

<div class="container" style="display:grid; gap:18px;">
    <section style="display:flex; justify-content:space-between; gap:16px; align-items:flex-start; flex-wrap:wrap; background:#fff; border:1px solid #dbe3ef; border-radius:22px; padding:24px;">
        <div>
            <div style="font-size:12px; font-weight:800; letter-spacing:.08em; text-transform:uppercase; color:#64748b;">Vendor Analytics</div>
            <h1 style="margin:10px 0 8px; font-size:42px; line-height:1.05; color:#0f172a;">Vendor Orders Report</h1>
            <p style="margin:0; max-width:900px; color:#64748b; font-size:16px;">Track vendor-supplied rentals and sales separately from in-house fulfilment. Reconcile customer revenue, vendor payable, gross margin, payment status, and operational fulfilment without exposing costs to unauthorized users.</p>
        </div>
        @if(auth()->user()?->hasPermission('vendor_reports.export'))
            <a href="{{ route('vendor-orders.export.csv', request()->query()) }}" style="display:inline-flex; align-items:center; justify-content:center; min-height:44px; padding:10px 16px; border-radius:14px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a; text-decoration:none; font-weight:700;">
                Export CSV
            </a>
        @endif
    </section>

    <section style="background:#fff; border:1px solid #dbe3ef; border-radius:20px; padding:22px;">
        <form method="GET" action="{{ route('vendor-orders.index') }}" style="display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:14px;">
            <div style="display:grid; gap:6px;">
                <label for="vendor_id" style="font-size:12px; font-weight:700; text-transform:uppercase; color:#475569;">Vendor</label>
                <select name="vendor_id" id="vendor_id" style="width:100%; padding:11px 12px; border:1px solid #cbd5e1; border-radius:14px;">
                    <option value="">All vendors</option>
                    @foreach(($filterOptions['vendors'] ?? collect()) as $vendor)
                        <option value="{{ $vendor->id }}" {{ (int) ($filters['vendor_id'] ?? 0) === (int) $vendor->id ? 'selected' : '' }}>{{ $vendor->name }}</option>
                    @endforeach
                </select>
            </div>
            <div style="display:grid; gap:6px;">
                <label for="order_type" style="font-size:12px; font-weight:700; text-transform:uppercase; color:#475569;">Order Type</label>
                <select name="order_type" id="order_type" style="width:100%; padding:11px 12px; border:1px solid #cbd5e1; border-radius:14px;">
                    <option value="">All vendor orders</option>
                    <option value="rental" {{ ($filters['order_type'] ?? '') === 'rental' ? 'selected' : '' }}>Rental</option>
                    <option value="sale" {{ ($filters['order_type'] ?? '') === 'sale' ? 'selected' : '' }}>Sale</option>
                </select>
            </div>
            <div style="display:grid; gap:6px;">
                <label for="payment_status" style="font-size:12px; font-weight:700; text-transform:uppercase; color:#475569;">Vendor Payment</label>
                <select name="payment_status" id="payment_status" style="width:100%; padding:11px 12px; border:1px solid #cbd5e1; border-radius:14px;">
                    <option value="">All payment states</option>
                    @foreach(\App\Models\VendorOrderDetail::VENDOR_PAYMENT_STATUSES as $status)
                        <option value="{{ $status }}" {{ ($filters['payment_status'] ?? '') === $status ? 'selected' : '' }}>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
                    @endforeach
                </select>
            </div>
            <div style="display:grid; gap:6px;">
                <label for="derived_state" style="font-size:12px; font-weight:700; text-transform:uppercase; color:#475569;">Reconciliation State</label>
                <select name="derived_state" id="derived_state" style="width:100%; padding:11px 12px; border:1px solid #cbd5e1; border-radius:14px;">
                    <option value="">All states</option>
                    @foreach(\App\Models\VendorOrderDetail::DERIVED_RECONCILIATION_STATES as $state)
                        <option value="{{ $state }}" {{ ($filters['derived_state'] ?? '') === $state ? 'selected' : '' }}>{{ ucfirst(str_replace('_', ' ', $state)) }}</option>
                    @endforeach
                </select>
            </div>
            <div style="display:grid; gap:6px;">
                <label for="margin_band" style="font-size:12px; font-weight:700; text-transform:uppercase; color:#475569;">Margin</label>
                <select name="margin_band" id="margin_band" style="width:100%; padding:11px 12px; border:1px solid #cbd5e1; border-radius:14px;">
                    <option value="">All margins</option>
                    <option value="negative" {{ ($filters['margin_band'] ?? '') === 'negative' ? 'selected' : '' }}>Negative</option>
                    <option value="zero" {{ ($filters['margin_band'] ?? '') === 'zero' ? 'selected' : '' }}>Zero</option>
                    <option value="positive" {{ ($filters['margin_band'] ?? '') === 'positive' ? 'selected' : '' }}>Positive</option>
                </select>
            </div>
            <div style="display:grid; gap:6px;">
                <label for="city" style="font-size:12px; font-weight:700; text-transform:uppercase; color:#475569;">City</label>
                <select name="city" id="city" style="width:100%; padding:11px 12px; border:1px solid #cbd5e1; border-radius:14px;">
                    <option value="">All cities</option>
                    @foreach(($filterOptions['cities'] ?? collect()) as $city)
                        <option value="{{ $city }}" {{ ($filters['city'] ?? '') === $city ? 'selected' : '' }}>{{ $city }}</option>
                    @endforeach
                </select>
            </div>
            <div style="display:grid; gap:6px;">
                <label for="product_id" style="font-size:12px; font-weight:700; text-transform:uppercase; color:#475569;">Product</label>
                <select name="product_id" id="product_id" style="width:100%; padding:11px 12px; border:1px solid #cbd5e1; border-radius:14px;">
                    <option value="">All products</option>
                    @foreach(($filterOptions['products'] ?? collect()) as $product)
                        <option value="{{ $product->id }}" {{ (int) ($filters['product_id'] ?? 0) === (int) $product->id ? 'selected' : '' }}>
                            {{ $product->name }}{{ $product->model_name ? ' - ' . $product->model_name : '' }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div style="display:grid; gap:6px;">
                <label for="from_date" style="font-size:12px; font-weight:700; text-transform:uppercase; color:#475569;">From Date</label>
                <input type="date" name="from_date" id="from_date" value="{{ $filters['from_date'] ?? '' }}" style="width:100%; padding:11px 12px; border:1px solid #cbd5e1; border-radius:14px;">
            </div>
            <div style="display:grid; gap:6px;">
                <label for="to_date" style="font-size:12px; font-weight:700; text-transform:uppercase; color:#475569;">To Date</label>
                <input type="date" name="to_date" id="to_date" value="{{ $filters['to_date'] ?? '' }}" style="width:100%; padding:11px 12px; border:1px solid #cbd5e1; border-radius:14px;">
            </div>
            <div style="grid-column:1 / -1; display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                <button type="submit" style="display:inline-flex; align-items:center; justify-content:center; min-height:42px; padding:10px 16px; border:none; border-radius:14px; background:#2563eb; color:#fff; font-weight:700; cursor:pointer;">Apply Filters</button>
                <a href="{{ route('vendor-orders.index') }}" style="display:inline-flex; align-items:center; justify-content:center; min-height:42px; padding:10px 16px; border-radius:14px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a; text-decoration:none; font-weight:700;">Reset</a>
            </div>
        </form>
    </section>

    <section style="display:grid; grid-template-columns:repeat(5, minmax(0, 1fr)); gap:14px;">
        <div style="background:#fff; border:1px solid #dbe3ef; border-radius:18px; padding:18px;">
            <div style="font-size:12px; font-weight:800; color:#64748b; text-transform:uppercase;">Vendor Orders</div>
            <div style="margin-top:10px; font-size:44px; font-weight:800; color:#0f172a;">{{ $summary['order_count'] ?? 0 }}</div>
        </div>
        <div style="background:#fff; border:1px solid #dbe3ef; border-radius:18px; padding:18px;">
            <div style="font-size:12px; font-weight:800; color:#64748b; text-transform:uppercase;">Customer Revenue</div>
            <div style="margin-top:10px; font-size:32px; font-weight:800; color:#0f172a;">{{ $currency($summary['revenue'] ?? 0) }}</div>
        </div>
        <div style="background:#fff; border:1px solid #dbe3ef; border-radius:18px; padding:18px;">
            <div style="font-size:12px; font-weight:800; color:#64748b; text-transform:uppercase;">Negative Margin</div>
            <div style="margin-top:10px; font-size:32px; font-weight:800; color:#b91c1c;">{{ $summary['negative_margin_count'] ?? 0 }}</div>
        </div>
        <div style="background:#fff; border:1px solid #dbe3ef; border-radius:18px; padding:18px;">
            <div style="font-size:12px; font-weight:800; color:#64748b; text-transform:uppercase;">Unpaid Vendor</div>
            <div style="margin-top:10px; font-size:32px; font-weight:800; color:#0f172a;">{{ $summary['unpaid_vendor_count'] ?? 0 }}</div>
        </div>
        <div style="background:#fff; border:1px solid #dbe3ef; border-radius:18px; padding:18px;">
            <div style="font-size:12px; font-weight:800; color:#64748b; text-transform:uppercase;">Vendor Balance Payable</div>
            <div style="margin-top:10px; font-size:32px; font-weight:800; color:#0f172a;">{{ $currency($summary['vendor_balance_payable'] ?? 0) }}</div>
        </div>
    </section>

    <section style="display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:14px;">
        <div style="background:#fff; border:1px solid #dbe3ef; border-radius:18px; padding:18px;">
            <div style="font-size:12px; font-weight:800; color:#64748b; text-transform:uppercase;">Customer Unpaid</div>
            <div style="margin-top:10px; font-size:32px; font-weight:800; color:#0f172a;">{{ $summary['customer_unpaid_count'] ?? 0 }}</div>
        </div>
        <div style="background:#fff; border:1px solid #dbe3ef; border-radius:18px; padding:18px;">
            <div style="font-size:12px; font-weight:800; color:#64748b; text-transform:uppercase;">Vendor Invoice Missing</div>
            <div style="margin-top:10px; font-size:32px; font-weight:800; color:#0f172a;">{{ $summary['vendor_invoice_missing_count'] ?? 0 }}</div>
        </div>
        @if($canViewCosts)
            <div style="background:#fff; border:1px solid #dbe3ef; border-radius:18px; padding:18px;">
                <div style="font-size:12px; font-weight:800; color:#64748b; text-transform:uppercase;">Vendor Cost</div>
                <div style="margin-top:10px; font-size:32px; font-weight:800; color:#0f172a;">{{ $currency($summary['vendor_cost'] ?? 0) }}</div>
            </div>
            <div style="background:#fff; border:1px solid #dbe3ef; border-radius:18px; padding:18px;">
                <div style="font-size:12px; font-weight:800; color:#64748b; text-transform:uppercase;">Gross Margin</div>
                <div style="margin-top:10px; font-size:32px; font-weight:800; color:{{ ($summary['gross_margin'] ?? 0) < 0 ? '#b91c1c' : '#0f172a' }};">{{ $currency($summary['gross_margin'] ?? 0) }}</div>
                <div style="margin-top:6px; font-size:13px; color:#64748b;">{{ number_format((float) ($summary['margin_percent'] ?? 0), 2) }}% margin</div>
            </div>
        @else
            <div style="background:#fff; border:1px solid #dbe3ef; border-radius:18px; padding:18px; grid-column:span 2;">
                <div style="font-size:12px; font-weight:800; color:#64748b; text-transform:uppercase;">Cost Visibility</div>
                <div style="margin-top:10px; font-size:15px; color:#475569;">Vendor procurement costs, payable balances, and gross margin stay hidden unless vendor cost permission is assigned.</div>
            </div>
        @endif
    </section>

    <section style="background:#fff; border:1px solid #dbe3ef; border-radius:20px; padding:20px; display:grid; gap:14px;">
        <div style="display:flex; justify-content:space-between; gap:12px; align-items:flex-start; flex-wrap:wrap;">
            <div>
                <h2 style="margin:0; font-size:22px; color:#0f172a;">Vendor Fulfilment Orders</h2>
                <p style="margin:6px 0 0; color:#64748b;">Reconcile customer invoice value, vendor costs, fulfilment status, payment status, and profitability without changing stock logic.</p>
            </div>
            <div style="font-size:12px; color:#64748b;">Rows: {{ $rows->total() }}</div>
        </div>

        <div style="display:grid; gap:14px;">
            @forelse($rows as $detail)
                @php
                    $productSummary = $detail->order_type === \App\Models\VendorOrderDetail::ORDER_TYPE_RENTAL
                        ? ($detail->rental?->product?->name ?? 'Rental Product')
                        : (($detail->sale?->saleItems?->isNotEmpty() ?? false)
                            ? $detail->sale->saleItems->map(fn ($item) => $item->product?->name ?? 'Product')->filter()->unique()->implode(', ')
                            : ($detail->sale?->product?->name ?? 'Sale Product'));
                @endphp
                <article style="border:1px solid #dbe3ef; border-radius:18px; padding:18px; display:grid; gap:14px;">
                    <div style="display:flex; justify-content:space-between; gap:14px; flex-wrap:wrap;">
                        <div style="display:grid; gap:6px;">
                            <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                                <span style="display:inline-flex; align-items:center; padding:5px 10px; border-radius:999px; background:#dbeafe; color:#1d4ed8; font-size:11px; font-weight:800; text-transform:uppercase;">{{ ucfirst($detail->order_type) }}</span>
                                <span style="display:inline-flex; align-items:center; padding:5px 10px; border-radius:999px; background:#ecfeff; color:#0f766e; font-size:11px; font-weight:800; text-transform:uppercase;">{{ $detail->deliveryResponsibilityLabel() }}</span>
                                @if($detail->pickupResponsibilityLabel())
                                    <span style="display:inline-flex; align-items:center; padding:5px 10px; border-radius:999px; background:#fef3c7; color:#92400e; font-size:11px; font-weight:800; text-transform:uppercase;">{{ $detail->pickupResponsibilityLabel() }}</span>
                                @endif
                                @foreach($detail->derivedStateLabels() as $label)
                                    <span style="display:inline-flex; align-items:center; padding:5px 10px; border-radius:999px; background:#fee2e2; color:#991b1b; font-size:11px; font-weight:800; text-transform:uppercase;">{{ $label }}</span>
                                @endforeach
                            </div>
                            <strong style="font-size:20px; color:#0f172a;">{{ ucfirst($detail->order_type) }} #{{ $detail->order_type === \App\Models\VendorOrderDetail::ORDER_TYPE_RENTAL ? $detail->rental_id : $detail->sale_id }}</strong>
                            <div style="font-size:14px; color:#475569;">Vendor: {{ $detail->vendor?->name ?? 'Vendor' }}</div>
                            <div style="font-size:14px; color:#475569;">Customer: {{ $detail->order_type === \App\Models\VendorOrderDetail::ORDER_TYPE_RENTAL ? $detail->rental?->billingContactName() : $detail->sale?->billingContactName() }}</div>
                            <div style="font-size:14px; color:#475569;">Product: {{ $productSummary }}</div>
                            <div style="font-size:13px; color:#64748b;">Date: {{ optional($detail->order_type === \App\Models\VendorOrderDetail::ORDER_TYPE_RENTAL ? $detail->rental?->start_date : $detail->sale?->sale_date)->format('d M Y') ?: '—' }} • City: {{ $detail->order_type === \App\Models\VendorOrderDetail::ORDER_TYPE_RENTAL ? ($detail->rental?->billingContactCity() ?: '—') : ($detail->sale?->billingContactCity() ?: '—') }}</div>
                            <div style="font-size:13px; color:#64748b;">Operational Fulfilment: {{ ucfirst(str_replace('_', ' ', $detail->operationalFulfilmentStatus())) }} • Customer Payment: {{ ucfirst(str_replace('_', ' ', $detail->customerPaymentStatus())) }}</div>
                        </div>
                        <div style="display:grid; gap:8px; min-width:250px;">
                            <div style="display:flex; justify-content:space-between; gap:12px; font-size:14px; color:#475569;"><span>Revenue</span><strong style="color:#0f172a;">{{ $currency($detail->customerRevenue()) }}</strong></div>
                            @if($canViewCosts)
                                <div style="display:flex; justify-content:space-between; gap:12px; font-size:14px; color:#475569;"><span>Total Vendor Cost</span><strong style="color:#0f172a;">{{ $currency($detail->totalVendorCost()) }}</strong></div>
                                <div style="display:flex; justify-content:space-between; gap:12px; font-size:14px; color:#475569;"><span>Gross Margin</span><strong style="color:{{ $detail->grossMargin() < 0 ? '#b91c1c' : '#0f172a' }};">{{ $currency($detail->grossMargin()) }}</strong></div>
                                <div style="display:flex; justify-content:space-between; gap:12px; font-size:14px; color:#475569;"><span>Margin %</span><strong style="color:#0f172a;">{{ number_format($detail->grossMarginPercent(), 2) }}%</strong></div>
                                <div style="display:flex; justify-content:space-between; gap:12px; font-size:14px; color:#475569;"><span>Vendor Payable</span><strong style="color:#0f172a;">{{ $currency($detail->vendorPayable()) }}</strong></div>
                                <div style="display:flex; justify-content:space-between; gap:12px; font-size:14px; color:#475569;"><span>Balance Payable</span><strong style="color:#0f172a;">{{ $currency($detail->vendorBalancePayable()) }}</strong></div>
                            @endif
                            <div style="display:flex; justify-content:space-between; gap:12px; font-size:14px; color:#475569;"><span>Vendor Payment</span><strong style="color:#0f172a;">{{ ucfirst(str_replace('_', ' ', $detail->vendor_payment_status)) }}</strong></div>
                            <div style="display:flex; justify-content:space-between; gap:12px; font-size:14px; color:#475569;"><span>Payment Due Aging</span><strong style="color:#0f172a;">{{ $detail->vendorPaymentDueDays() ?? 0 }} days</strong></div>
                        </div>
                    </div>

                    @if($canViewCosts)
                        <div style="display:grid; gap:10px; padding:14px; border:1px solid #e2e8f0; border-radius:16px; background:#f8fafc;">
                            <div style="font-size:12px; font-weight:800; color:#475569; text-transform:uppercase;">Vendor Costing & Payment</div>
                            @if($canUpdateCosts)
                                <form method="POST" action="{{ route('vendor-orders.costs.update', $detail) }}" style="display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:10px;">
                                    @csrf
                                    @method('PATCH')
                                    <div style="display:grid; gap:6px;">
                                        <label style="font-size:12px; color:#475569; font-weight:700;">Procurement Cost</label>
                                        <input type="number" step="0.01" min="0" name="procurement_cost" value="{{ old('procurement_cost', $detail->procurement_cost ?? 0) }}" style="width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:12px;">
                                    </div>
                                    <div style="display:grid; gap:6px;">
                                        <label style="font-size:12px; color:#475569; font-weight:700;">Delivery Cost</label>
                                        <input type="number" step="0.01" min="0" name="vendor_delivery_cost" value="{{ old('vendor_delivery_cost', $detail->vendor_delivery_cost ?? 0) }}" style="width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:12px;">
                                    </div>
                                    <div style="display:grid; gap:6px;">
                                        <label style="font-size:12px; color:#475569; font-weight:700;">Pickup Cost</label>
                                        <input type="number" step="0.01" min="0" name="vendor_pickup_cost" value="{{ old('vendor_pickup_cost', $detail->vendor_pickup_cost ?? 0) }}" style="width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:12px;">
                                    </div>
                                    <div style="display:grid; gap:6px;">
                                        <label style="font-size:12px; color:#475569; font-weight:700;">Other Cost</label>
                                        <input type="number" step="0.01" min="0" name="other_vendor_cost" value="{{ old('other_vendor_cost', $detail->other_vendor_cost ?? 0) }}" style="width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:12px;">
                                    </div>
                                    <div style="display:grid; gap:6px;">
                                        <label style="font-size:12px; color:#475569; font-weight:700;">Vendor Order Status</label>
                                        <select name="vendor_order_status" style="width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:12px;">
                                            @foreach(\App\Models\VendorOrderDetail::VENDOR_ORDER_STATUSES as $status)
                                                <option value="{{ $status }}" {{ $detail->vendor_order_status === $status ? 'selected' : '' }}>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div style="display:grid; gap:6px;">
                                        <label style="font-size:12px; color:#475569; font-weight:700;">Vendor Payment Status</label>
                                        <select name="vendor_payment_status" style="width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:12px;">
                                            @foreach(\App\Models\VendorOrderDetail::VENDOR_PAYMENT_STATUSES as $status)
                                                <option value="{{ $status }}" {{ $detail->vendor_payment_status === $status ? 'selected' : '' }}>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div style="display:grid; gap:6px;">
                                        <label style="font-size:12px; color:#475569; font-weight:700;">Vendor Invoice Number</label>
                                        <input type="text" name="vendor_invoice_number" value="{{ old('vendor_invoice_number', $detail->vendor_invoice_number) }}" style="width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:12px;">
                                    </div>
                                    <div style="display:grid; gap:6px;">
                                        <label style="font-size:12px; color:#475569; font-weight:700;">Vendor Paid At</label>
                                        <input type="date" name="vendor_paid_at" value="{{ old('vendor_paid_at', optional($detail->vendor_paid_at)->format('Y-m-d')) }}" style="width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:12px;">
                                    </div>
                                    <div style="grid-column:1 / -1; display:grid; gap:6px;">
                                        <label style="font-size:12px; color:#475569; font-weight:700;">Notes</label>
                                        <textarea name="notes" rows="2" style="width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:12px;">{{ old('notes', $detail->notes) }}</textarea>
                                    </div>
                                    <div style="grid-column:1 / -1; display:flex; justify-content:flex-end;">
                                        <button type="submit" style="display:inline-flex; align-items:center; justify-content:center; min-height:40px; padding:10px 16px; border:none; border-radius:12px; background:#0f172a; color:#fff; font-weight:700; cursor:pointer;">Update Vendor Costing</button>
                                    </div>
                                </form>
                            @else
                                <div style="display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:12px; color:#475569; font-size:14px;">
                                    <div><strong style="display:block; color:#64748b; font-size:12px; text-transform:uppercase;">Procurement</strong>{{ $currency($detail->procurement_cost ?? 0) }}</div>
                                    <div><strong style="display:block; color:#64748b; font-size:12px; text-transform:uppercase;">Delivery</strong>{{ $currency($detail->vendor_delivery_cost ?? 0) }}</div>
                                    <div><strong style="display:block; color:#64748b; font-size:12px; text-transform:uppercase;">Pickup</strong>{{ $currency($detail->vendor_pickup_cost ?? 0) }}</div>
                                    <div><strong style="display:block; color:#64748b; font-size:12px; text-transform:uppercase;">Other</strong>{{ $currency($detail->other_vendor_cost ?? 0) }}</div>
                                </div>
                            @endif
                        </div>
                    @endif
                </article>
            @empty
                <div style="border:1px dashed #cbd5e1; border-radius:18px; padding:24px; text-align:center; color:#64748b;">
                    No vendor-supplied orders matched the selected filters.
                </div>
            @endforelse
        </div>

        @if(method_exists($rows, 'links'))
            <div>{{ $rows->links() }}</div>
        @endif
    </section>
</div>
@endsection
