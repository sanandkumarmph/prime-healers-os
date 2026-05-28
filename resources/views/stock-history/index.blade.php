@extends('layouts.app')

@section('content')
<div style="display:grid; gap:18px; max-width:100%;">
    <div class="ph-card" style="padding:20px; border:1px solid #dbe7f3; border-radius:24px; background:#ffffff;">
        <div style="display:flex; justify-content:space-between; gap:16px; align-items:flex-start; flex-wrap:wrap;">
            <div style="display:grid; gap:6px; min-width:0;">
                <span style="font-size:12px; font-weight:700; letter-spacing:.12em; text-transform:uppercase; color:#64748b;">Inventory</span>
                <h1 style="margin:0; font-size:28px; line-height:1.1;">Stock History</h1>
                <p style="margin:0; color:#64748b;">Read-only movement ledger for product stock, asset transfers, delivery returns, and verification outcomes.</p>
            </div>
            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                @if($canExportStockHistory)
                    <a href="{{ route('stock-history.export.csv', request()->query()) }}" class="ph-button ph-button-secondary">Export CSV</a>
                @endif
            </div>
        </div>
    </div>

    <div class="ph-card" style="padding:20px; border:1px solid #dbe7f3; border-radius:24px; background:#ffffff;">
        <form method="GET" action="{{ route('stock-history.index') }}" style="display:grid; gap:14px;">
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px;">
                <div>
                    <label style="display:block; font-size:12px; font-weight:700; text-transform:uppercase; color:#64748b; margin-bottom:6px;">Product</label>
                    <select name="product_id" class="ph-input" style="width:100%;">
                        <option value="">All products</option>
                        @foreach($products as $product)
                            <option value="{{ $product->id }}" @selected((string) request('product_id') === (string) $product->id)>
                                {{ trim(collect([$product->name, $product->brand, $product->model_name])->filter()->implode(' - ')) }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label style="display:block; font-size:12px; font-weight:700; text-transform:uppercase; color:#64748b; margin-bottom:6px;">Asset</label>
                    <select name="asset_id" class="ph-input" style="width:100%;">
                        <option value="">All assets</option>
                        @foreach($assets as $asset)
                            <option value="{{ $asset->id }}" @selected((string) request('asset_id') === (string) $asset->id)>
                                {{ $asset->serial_number ?: ('Asset #' . $asset->id) }}@if($asset->barcode_value) · {{ $asset->barcode_value }}@endif
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label style="display:block; font-size:12px; font-weight:700; text-transform:uppercase; color:#64748b; margin-bottom:6px;">Warehouse</label>
                    <select name="warehouse_id" class="ph-input" style="width:100%;">
                        <option value="">All warehouses</option>
                        @foreach($warehouses as $warehouse)
                            <option value="{{ $warehouse->id }}" @selected((string) request('warehouse_id') === (string) $warehouse->id)>
                                {{ $warehouse->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label style="display:block; font-size:12px; font-weight:700; text-transform:uppercase; color:#64748b; margin-bottom:6px;">Movement Type</label>
                    <select name="movement_type" class="ph-input" style="width:100%;">
                        <option value="">All types</option>
                        @foreach($movementTypes as $movementType)
                            <option value="{{ $movementType }}" @selected((string) request('movement_type') === (string) $movementType)>
                                {{ str($movementType)->replace('_', ' ')->title() }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label style="display:block; font-size:12px; font-weight:700; text-transform:uppercase; color:#64748b; margin-bottom:6px;">From</label>
                    <input type="date" name="from_date" value="{{ request('from_date') }}" class="ph-input" style="width:100%;">
                </div>
                <div>
                    <label style="display:block; font-size:12px; font-weight:700; text-transform:uppercase; color:#64748b; margin-bottom:6px;">To</label>
                    <input type="date" name="to_date" value="{{ request('to_date') }}" class="ph-input" style="width:100%;">
                </div>
            </div>
            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                <button type="submit" class="ph-button ph-button-primary">Apply Filters</button>
                <a href="{{ route('stock-history.index') }}" class="ph-button ph-button-secondary">Reset</a>
            </div>
        </form>
    </div>

    <div class="ph-card" style="padding:0; border:1px solid #dbe7f3; border-radius:24px; background:#ffffff; overflow:hidden;">
        <div style="padding:18px 20px; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; gap:12px; align-items:center; flex-wrap:wrap;">
            <div style="display:grid; gap:4px;">
                <strong style="font-size:18px;">Movement Ledger</strong>
                <span style="font-size:13px; color:#64748b;">{{ $movements->total() }} movement{{ $movements->total() === 1 ? '' : 's' }} matched.</span>
            </div>
        </div>

        <div style="overflow:auto;">
            <table style="width:100%; border-collapse:collapse; min-width:1024px;">
                <thead>
                    <tr style="background:#f8fafc;">
                        <th style="text-align:left; padding:14px 16px; font-size:12px; text-transform:uppercase; color:#64748b;">When</th>
                        <th style="text-align:left; padding:14px 16px; font-size:12px; text-transform:uppercase; color:#64748b;">Type</th>
                        <th style="text-align:left; padding:14px 16px; font-size:12px; text-transform:uppercase; color:#64748b;">Product / Asset</th>
                        <th style="text-align:left; padding:14px 16px; font-size:12px; text-transform:uppercase; color:#64748b;">Qty</th>
                        <th style="text-align:left; padding:14px 16px; font-size:12px; text-transform:uppercase; color:#64748b;">From</th>
                        <th style="text-align:left; padding:14px 16px; font-size:12px; text-transform:uppercase; color:#64748b;">To</th>
                        <th style="text-align:left; padding:14px 16px; font-size:12px; text-transform:uppercase; color:#64748b;">Related</th>
                        <th style="text-align:left; padding:14px 16px; font-size:12px; text-transform:uppercase; color:#64748b;">Performed By</th>
                        <th style="text-align:left; padding:14px 16px; font-size:12px; text-transform:uppercase; color:#64748b;">Notes</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($movements as $movement)
                    <tr style="border-top:1px solid #e2e8f0;">
                        <td style="padding:14px 16px; vertical-align:top;">
                            <div style="display:grid; gap:4px;">
                                <strong>{{ optional($movement->movement_at)->format('d M Y, h:i A') }}</strong>
                                <span style="font-size:12px; color:#64748b;">Created: {{ optional($movement->created_at)->format('d M Y, h:i A') ?: '—' }}</span>
                            </div>
                        </td>
                        <td style="padding:14px 16px; vertical-align:top;">
                            <span style="display:inline-flex; padding:6px 10px; border-radius:999px; background:#eff6ff; color:#1d4ed8; font-size:12px; font-weight:700;">
                                {{ str($movement->movement_type)->replace('_', ' ')->title() }}
                            </span>
                        </td>
                        <td style="padding:14px 16px; vertical-align:top;">
                            <div style="display:grid; gap:4px;">
                                <strong>{{ trim(collect([$movement->product?->name, $movement->product?->brand, $movement->product?->model_name])->filter()->implode(' - ')) ?: '—' }}</strong>
                                @if($movement->asset)
                                    <span style="font-size:13px; color:#64748b;">Asset: {{ $movement->asset->serial_number ?: ('#' . $movement->asset->id) }}</span>
                                @endif
                            </div>
                        </td>
                        <td style="padding:14px 16px; vertical-align:top;">{{ $movement->quantity }}</td>
                        <td style="padding:14px 16px; vertical-align:top;">
                            <div style="display:grid; gap:4px;">
                                <span>{{ $movement->from_status ?: '—' }}</span>
                                <span style="font-size:13px; color:#64748b;">{{ $movement->fromWarehouse?->name ?: '—' }}</span>
                            </div>
                        </td>
                        <td style="padding:14px 16px; vertical-align:top;">
                            <div style="display:grid; gap:4px;">
                                <span>{{ $movement->to_status ?: '—' }}</span>
                                <span style="font-size:13px; color:#64748b;">{{ $movement->toWarehouse?->name ?: '—' }}</span>
                            </div>
                        </td>
                        <td style="padding:14px 16px; vertical-align:top;">
                            <div style="display:grid; gap:4px; font-size:13px; color:#475569;">
                                @if($movement->rental_id)<span>Rental #{{ $movement->rental_id }}</span>@endif
                                @if($movement->sale_id)<span>Sale #{{ $movement->sale_id }}</span>@endif
                                @if($movement->delivery_id)<span>Delivery #{{ $movement->delivery_id }}</span>@endif
                                @if($movement->invoice)<span>Invoice {{ $movement->invoice->invoice_number }}</span>@endif
                                @if($movement->payment_id)<span>Payment #{{ $movement->payment_id }}</span>@endif
                                @if(!$movement->rental_id && !$movement->sale_id && !$movement->delivery_id && !$movement->invoice_id && !$movement->payment_id)
                                    <span>—</span>
                                @endif
                            </div>
                        </td>
                        <td style="padding:14px 16px; vertical-align:top;">{{ $movement->performedBy?->name ?: 'System' }}</td>
                        <td style="padding:14px 16px; vertical-align:top; color:#475569;">{{ $movement->notes ?: '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" style="padding:28px 16px; color:#64748b;">No stock movement history matched the selected filters yet.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div style="padding:18px 20px; border-top:1px solid #e2e8f0;">
            {{ $movements->links() }}
        </div>
    </div>
</div>
@endsection
