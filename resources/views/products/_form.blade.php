@php
    $isEdit = $product->exists;
    $currentUser = auth()->user();
    $canCreateAssets = $currentUser?->canAccessModule('assets', 'create') ?? false;
    $rupee = html_entity_decode('&#8377;');

    $productType = old('product_type', $product->product_type ?? (($product->is_rentable ?? false) ? \App\Models\Product::TYPE_RENTABLE : \App\Models\Product::TYPE_SELLABLE));
    $stockMode = old('stock_mode', $product->stock_mode ?? \App\Models\Product::STOCK_MODE_UNTRACKED);
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
    $stockModeLabel = match ($stockMode) {
        \App\Models\Product::STOCK_MODE_TRACKED_SALE => 'Tracked Sale',
        \App\Models\Product::STOCK_MODE_TRACKED_RENTAL => 'Tracked Rental',
        \App\Models\Product::STOCK_MODE_TRACKED_BOTH => 'Tracked Both',
        default => 'Untracked',
    };
    $canUpdateProducts = $currentUser?->canAccessModule('products', 'update') ?? false;
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
        max-width: 1080px;
        margin: 0 auto;
    }
    .product-form-header {
        display:flex;
        justify-content:space-between;
        align-items:flex-start;
        gap:16px;
        margin-bottom:24px;
        flex-wrap:wrap;
    }
    .product-form-grid {
        display:grid;
        grid-template-columns:minmax(0, 1.1fr) minmax(0, 0.9fr);
        gap:20px;
        align-items:start;
    }
    .product-form-column {
        display:grid;
        gap:20px;
    }
    .product-form-card {
        background:#ffffff;
        border:1px solid #e2e8f0;
        border-radius:22px;
        padding:24px;
    }
    .product-form-card h2 {
        margin:0;
        font-size:22px;
    }
    .product-form-copy {
        margin:8px 0 18px;
        color:#64748b;
        font-size:13px;
        line-height:1.55;
    }
    .product-form-two-col {
        display:grid;
        grid-template-columns:repeat(2, minmax(0, 1fr));
        gap:18px;
    }
        .product-form-actions {
            display:flex;
            justify-content:flex-end;
            gap:12px;
        }
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
        }
        .product-form-header {
            gap:12px;
            margin-bottom:16px;
        }
        .product-form-header h1 {
            margin:8px 0 4px !important;
            font-size:26px !important;
        }
        .product-form-header p {
            font-size:12px !important;
            line-height:1.45 !important;
            max-width:none !important;
        }
        .product-form-grid,
        .product-form-two-col {
            grid-template-columns:1fr !important;
            gap:14px !important;
        }
        .product-form-column {
            gap:14px;
        }
        .product-form-card {
            border-radius:16px;
            padding:16px;
        }
        .product-form-card h2 {
            font-size:18px;
        }
        .product-form-copy {
            margin:6px 0 14px;
            font-size:12px;
        }
        .product-form-card label {
            font-size:12px !important;
            margin-bottom:6px !important;
        }
        .product-form-card input,
        .product-form-card select,
        .product-form-card textarea {
            padding:11px 12px !important;
            border-radius:12px !important;
        }
        .product-form-actions {
            display:grid;
            grid-template-columns:1fr;
            padding:0;
            border:none;
            border-radius:0;
            background:transparent;
            box-shadow:none;
        }
        .product-form-actions a,
        .product-form-actions button {
            width:100%;
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

    <form method="POST" action="{{ $isEdit ? route('products.update', $product) : route('products.store') }}" style="display:grid; gap:20px;">
        @csrf
        @if($isEdit)
            @method('PUT')
        @endif

        <div class="product-form-grid">
            <div class="product-form-column">
                <div class="product-form-card">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap; margin-bottom:18px;">
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
                            <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Category</label>
                            <input type="text" name="category" value="{{ old('category', $product->category) }}"
                                   style="{{ $fieldStyle('category', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff;') }}">
                            @if($fieldError('category'))
                                <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('category') }}</div>
                            @endif
                        </div>

                        <div>
                            <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Brand</label>
                            <input type="text" name="brand" value="{{ old('brand', $product->brand) }}"
                                   style="{{ $fieldStyle('brand', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff;') }}">
                            @if($fieldError('brand'))
                                <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('brand') }}</div>
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

                <div class="product-form-card">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap;">
                        <div>
                            <h2 style="margin:0; font-size:22px;">Inventory Structure</h2>
                            <p class="product-form-copy">Choose one stock model per product.</p>
                        </div>
                        <span style="display:inline-flex; padding:7px 12px; border-radius:999px; background:{{ $usesManagedStock ? '#eff6ff' : '#f8fafc' }}; color:{{ $usesManagedStock ? '#1d4ed8' : '#475569' }}; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.08em;">
                            {{ $stockModeLabel }}
                        </span>
                    </div>

                    <div style="display:grid; gap:18px;">
                        <div>
                            <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Product Type</label>
                            <select name="product_type" id="product_type"
                                   style="{{ $fieldStyle('product_type', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff;') }}">
                                <option value="{{ \App\Models\Product::TYPE_SELLABLE }}" @selected($productType === \App\Models\Product::TYPE_SELLABLE)>Sellable</option>
                                <option value="{{ \App\Models\Product::TYPE_RENTABLE }}" @selected($productType === \App\Models\Product::TYPE_RENTABLE)>Rentable</option>
                            </select>
                            @if($fieldError('product_type'))
                                <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('product_type') }}</div>
                            @endif
                            <div style="margin-top:6px; color:#64748b; font-size:12px;">
                                Sellable products use sale units. Rentable products use rental assets.
                            </div>
                        </div>

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
                <div class="product-form-card">
                    <h2 style="margin:0; font-size:22px;">Pricing</h2>
                    <p class="product-form-copy">Keep pricing short and clear.</p>

                    <div style="display:grid; gap:18px;">
                        <div>
                            <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Sale Price</label>
                            <input type="number" min="0" step="0.01" name="sale_price" value="{{ $salePriceValue }}"
                                   style="{{ $fieldStyle('sale_price', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff;') }}">
                            @if($fieldError('sale_price'))
                                <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('sale_price') }}</div>
                            @endif
                        </div>

                        <div style="display:grid; gap:14px;">
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

                            <div id="gstSplitRates" class="product-form-two-col {{ $gstTaxTypeValue === \App\Models\Product::GST_TAX_TYPE_CGST_SGST ? '' : 'is-hidden' }}">
                                <div>
                                    <label for="gst_split_total_rate" style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">GST %</label>
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
                                    <div style="padding:12px 14px; border-radius:14px; background:#f8fafc; border:1px solid #e2e8f0; color:#475569; font-size:13px; line-height:1.5;">
                                        <strong style="color:#0f172a;">CGST + SGST split</strong><br>
                                        <span id="gstSplitPreview">CGST {{ number_format((float) $cgstRateValue, 2) }}% + SGST {{ number_format((float) $sgstRateValue, 2) }}%</span>
                                    </div>
                                    <div style="color:#64748b; font-size:12px;">Legacy non-standard GST values stay available as selected dropdown options.</div>
                                </div>
                            </div>

                            <div id="gstIgstRateWrap" class="{{ $gstTaxTypeValue === \App\Models\Product::GST_TAX_TYPE_IGST ? '' : 'is-hidden' }}">
                                <label for="gst_igst_total_rate" style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">GST %</label>
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
                    <h2 style="margin:0; font-size:22px;">Quick Snapshot</h2>
                    <p class="product-form-copy">Quick check before saving.</p>

                    <div style="display:grid; gap:12px;">
                            <div style="padding:16px; border-radius:18px; background:#fff7ed; border:1px solid #fed7aa;">
                                <div style="font-size:11px; color:#9a3412; font-weight:700; text-transform:uppercase;">Product Type</div>
                                <div style="margin-top:6px; font-size:24px; font-weight:800; color:#9a3412;">{{ ucfirst($productType) }}</div>
                            </div>

                        <div style="padding:16px; border-radius:18px; background:#eff6ff; border:1px solid #bfdbfe;">
                            <div style="font-size:11px; color:#1d4ed8; font-weight:700; text-transform:uppercase;">Stock Mode</div>
                            <div style="margin-top:6px; font-size:24px; font-weight:800; color:#1d4ed8;">{{ $stockModeLabel }}</div>
                        </div>

                        <div style="padding:16px; border-radius:18px; background:#f8fafc; border:1px solid #e2e8f0; color:#475569; line-height:1.6;">
                            <strong>Pricing reference:</strong><br>
                            Sale {{ $salePriceValue !== null && $salePriceValue !== '' ? $rupee . ' ' . number_format((float) $salePriceValue, 2) : 'not set' }}<br>
                            Per Day {{ $pricePerDayValue !== null && $pricePerDayValue !== '' ? $rupee . ' ' . number_format((float) $pricePerDayValue, 2) : 'not set' }}<br>
                            15 Days {{ $rental15DayValue !== null && $rental15DayValue !== '' ? $rupee . ' ' . number_format((float) $rental15DayValue, 2) : 'not set' }}<br>
                            30 Days {{ $rental30DayValue !== null && $rental30DayValue !== '' ? $rupee . ' ' . number_format((float) $rental30DayValue, 2) : 'not set' }}<br>
                            3 Months {{ $rental3MonthValue !== null && $rental3MonthValue !== '' ? $rupee . ' ' . number_format((float) $rental3MonthValue, 2) : 'not set' }}<br>
                            GST {{ $gstTaxTypeValue === \App\Models\Product::GST_TAX_TYPE_CGST_SGST
                                ? 'CGST '.number_format((float) $cgstRateValue, 2).'% + SGST '.number_format((float) $sgstRateValue, 2).'%'
                                : ($gstTaxTypeValue === \App\Models\Product::GST_TAX_TYPE_IGST
                                    ? 'IGST '.number_format((float) $igstRateValue, 2).'%'
                                    : 'not set') }} /
                            {{ $gstCalculationModeValue === 'inclusive' ? 'Inclusive' : 'Exclusive' }}
                        </div>
                    </div>
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
    </form>
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const productType = document.getElementById('product_type');
    const stockMessages = Array.from(document.querySelectorAll('.product-stock-message'));
    const pricePerDay = document.getElementById('price_per_day');
    const gstTaxType = document.getElementById('gst_tax_type');
    const gstSplitRates = document.getElementById('gstSplitRates');
    const gstIgstRateWrap = document.getElementById('gstIgstRateWrap');
    const cgstRate = document.getElementById('cgst_rate');
    const sgstRate = document.getElementById('sgst_rate');
    const igstRate = document.getElementById('igst_rate');
    const gstSplitTotalRate = document.getElementById('gst_split_total_rate');
    const gstIgstTotalRate = document.getElementById('gst_igst_total_rate');
    const gstSplitPreview = document.getElementById('gstSplitPreview');

    if (!productType) {
        return;
    }

    const syncMode = function () {
        const isRentable = productType.value === '{{ \App\Models\Product::TYPE_RENTABLE }}';

        stockMessages.forEach(function (message) {
            if (message.dataset.stockMessage !== 'untracked') {
                return;
            }

            message.classList.toggle('is-hidden', true);
        });

        if (pricePerDay) {
            pricePerDay.required = isRentable;
        }
    };

    const syncGstMode = function () {
        if (!gstTaxType) {
            return;
        }

        const value = gstTaxType.value || '';

        if (gstSplitRates) {
            gstSplitRates.classList.toggle('is-hidden', value !== '{{ \App\Models\Product::GST_TAX_TYPE_CGST_SGST }}');
        }

        if (gstIgstRateWrap) {
            gstIgstRateWrap.classList.toggle('is-hidden', value !== '{{ \App\Models\Product::GST_TAX_TYPE_IGST }}');
        }
    };

    const syncProductGstRates = function () {
        const type = gstTaxType ? (gstTaxType.value || '') : '';
        const splitTotal = parseFloat((gstSplitTotalRate && gstSplitTotalRate.value) || '0') || 0;
        const igstTotal = parseFloat((gstIgstTotalRate && gstIgstTotalRate.value) || '0') || 0;

        if (type === '{{ \App\Models\Product::GST_TAX_TYPE_CGST_SGST }}') {
            const halfRate = (splitTotal / 2).toFixed(2);

            if (cgstRate) {
                cgstRate.value = halfRate;
            }

            if (sgstRate) {
                sgstRate.value = halfRate;
            }

            if (igstRate) {
                igstRate.value = '0.00';
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

    productType.addEventListener('change', syncMode);
    gstTaxType?.addEventListener('change', function () {
        syncGstMode();
        syncProductGstRates();
    });
    gstSplitTotalRate?.addEventListener('change', syncProductGstRates);
    gstIgstTotalRate?.addEventListener('change', syncProductGstRates);
    syncMode();
    syncGstMode();
    syncProductGstRates();
});
</script>
@endpush
