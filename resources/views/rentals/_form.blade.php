@php
    $isEdit = isset($rental);
    $selectedCustomer = $selectedCustomer ?? null;
    $businessPartners = $businessPartners ?? collect();
    $initialPartnerClients = $initialPartnerClients ?? collect();
    $businessPartnerFlowAvailable = $businessPartnerFlowAvailable ?? true;
    $customerTypeValue = old('customer_type', $isEdit ? $rental->customerTypeValue() : 'direct_customer');
    if (!$businessPartnerFlowAvailable && $customerTypeValue === 'business_partner') {
        $customerTypeValue = 'direct_customer';
    }
    $selectedCustomerId = old('customer_id', $isEdit ? $rental->customer_id : ($selectedCustomer?->id));
    $selectedBusinessPartnerId = (int) old('business_partner_id', $isEdit ? ($rental->business_partner_id ?? 0) : 0);
    $selectedPartnerClientId = (int) old('partner_client_id', $isEdit ? ($rental->partner_client_id ?? 0) : 0);
    $selectedBusinessPartner = $businessPartners->firstWhere('id', $selectedBusinessPartnerId);
    $selectedPartnerClient = $initialPartnerClients->firstWhere('id', $selectedPartnerClientId);
    $shouldShowRentalCustomerSummary = $customerTypeValue === 'business_partner'
        ? (bool) ($selectedBusinessPartner || $selectedPartnerClient)
        : filled($selectedCustomerId);
    $rentalProducts = $rentalProducts ?? $products;
    $selectedAssetIds = collect(old('asset_ids', $isEdit ? $rental->activeRentalAssets->pluck('asset_id')->all() : []))
        ->filter(fn ($value) => filled($value))
        ->map(fn ($value) => (int) $value)
        ->values()
        ->all();
    $existingRentalItems = $isEdit ? ($rental->rentalItems ?? collect()) : collect();
    $primaryRentalItem = $existingRentalItems->first();
    $additionalRentalRows = collect(old('rental_items', $existingRentalItems->skip(1)->map(function ($item) {
        return [
            'product_id' => $item->product_id,
            'asset_ids' => collect($item->asset_ids ?? [])->filter(fn ($value) => filled($value))->map(fn ($value) => (int) $value)->values()->all(),
            'quantity' => $item->quantity,
            'unit_rental_amount' => $item->unit_rental_amount,
            'gst_rate' => $item->gst_rate ?? 0,
            'gst_mode' => $item->gst_mode ?? 'exclusive',
            'tax_type' => $item->tax_type ?? \App\Models\Product::GST_TAX_TYPE_CGST_SGST,
            'notes' => $item->notes,
        ];
    })->all()))
        ->filter(fn ($item) => filled(data_get($item, 'product_id')) || filled(data_get($item, 'quantity')))
        ->values()
        ->all();
    $sellableProducts = $products->filter(fn ($product) => (bool) ($product->is_sellable ?? true))->values();
    $saleItemRows = collect(old('sale_items', $isEdit ? ($rental->saleItems ?? collect())->map(function ($item) {
        return [
            'product_id' => $item->product_id,
            'asset_id' => $item->asset_id,
            'warehouse_id' => $item->warehouse_id,
            'quantity' => $item->quantity,
            'unit_price' => $item->unit_price,
            'gst_rate' => $item->gst_rate ?? 0,
            'gst_mode' => $item->gst_mode ?? 'exclusive',
            'tax_type' => $item->tax_type ?? \App\Models\Product::GST_TAX_TYPE_CGST_SGST,
            'notes' => $item->notes,
        ];
    })->all() : []))
        ->filter(fn ($item) => filled(data_get($item, 'product_id')) || filled(data_get($item, 'quantity')))
        ->values()
        ->all();
    $saleAssetRows = collect($saleAssets ?? collect())->map(function ($asset) {
        return [
            'id' => $asset->id,
            'product_id' => $asset->product_id,
            'warehouse_id' => $asset->warehouse_id,
            'label' => e(collect([
                $asset->serial_number ?: ($asset->asset_name ?: ('Asset #' . $asset->id)),
                $asset->product?->name,
                $asset->warehouse?->name,
            ])->filter()->implode(' | ')),
        ];
    })->values()->all();
    $gstStandardRates = [0, 5, 12, 18, 28];
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
    $additionalRentalExpanded = count($additionalRentalRows) > 0;
    $newProductsExpanded = count($saleItemRows) > 0;
    $selectedDeliveryAssignment = old('delivery_staff_id');
    $vendorDeliveryMembers = collect($staffMembers ?? collect())->filter(function ($staff) {
        return ($staff->effective_role ?? null) === 'vendor';
    })->values();
    $thirdPartyDeliveryMembers = collect($staffMembers ?? collect())->filter(function ($staff) {
        return ($staff->effective_role ?? null) === 'third_party';
    })->values();
    $otherAssignableStaffMembers = collect($staffMembers ?? collect())->reject(function ($staff) {
        return in_array($staff->effective_role ?? null, ['vendor', 'third_party'], true);
    })->values();
    $phoneParts = \App\Support\PhoneNumber::split(old('phone', $isEdit ? $rental->phone : ($selectedCustomer?->phone ?? '')));
    if ($selectedDeliveryAssignment === null && $isEdit) {
        $selectedDeliveryAssignment = ($rental->deliveryRecord?->assignment_type ?? null) === 'third_party'
            ? 'third_party'
            : ($rental->deliveryRecord?->assigned_user_id
                ? 'user:' . $rental->deliveryRecord->assigned_user_id
                : (($rental->deliveryRecord?->assigned_staff_id ?? $rental->delivery_staff_id)
                    ? 'staff:' . ($rental->deliveryRecord?->assigned_staff_id ?? $rental->delivery_staff_id)
                    : ''));
    }

    $thirdPartyNameValue = old('third_party_name', $isEdit ? ($rental->deliveryRecord?->third_party_name ?? '') : '');
    $thirdPartyContactValue = old('third_party_contact', $isEdit ? ($rental->deliveryRecord?->third_party_contact ?? '') : '');
    $thirdPartyPhoneValue = old('third_party_phone', $isEdit ? ($rental->deliveryRecord?->third_party_phone ?? '') : '');

    $hasFieldError = function (string ...$keys) use ($errors): bool {
        foreach ($keys as $key) {
            if ($errors->has($key)) {
                return true;
            }
        }

        return false;
    };

    $fieldError = function (string ...$keys) use ($errors): ?string {
        foreach ($keys as $key) {
            if ($errors->has($key)) {
                return $errors->first($key);
            }
        }

        return null;
    };

    $hasRentalItemsError = collect($errors->keys())->contains(fn ($key) => str_starts_with($key, 'rental_items'));
    $hasSaleItemsError = collect($errors->keys())->contains(fn ($key) => str_starts_with($key, 'sale_items'));
@endphp

@include('partials.business-partner-flow-styles')

<style>
    .rental-shell { display:grid; gap:18px; }
    .rental-toolbar { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap; margin-bottom:4px; }
    .rental-title h1 { margin:0; font-size:26px; color:#0f172a; }
    .rental-title p { margin:6px 0 0; color:#64748b; font-size:13px; }
    .rental-actions { display:flex; gap:8px; flex-wrap:wrap; }
    .rental-card { background:#fff; border:1px solid #dbe3ef; border-radius:14px; padding:16px; box-shadow:0 8px 24px rgba(15, 23, 42, 0.04); }
    .rental-card h2 { margin:0 0 4px; font-size:16px; color:#0f172a; }
    .rental-card p.section-copy { margin:0 0 10px; font-size:11px; color:#64748b; }
    .rental-grid { display:grid; grid-template-columns:repeat(12, minmax(0, 1fr)); gap:12px; }
    .rental-col-3 { grid-column:span 3; }
    .rental-col-4 { grid-column:span 4; }
    .rental-col-5 { grid-column:span 5; }
    .rental-col-6 { grid-column:span 6; }
    .rental-col-7 { grid-column:span 7; }
    .rental-col-8 { grid-column:span 8; }
    .rental-col-12 { grid-column:span 12; }
    .rental-field { display:flex; flex-direction:column; gap:4px; }
    .rental-field label { font-size:11px; font-weight:700; color:#334155; letter-spacing:0.03em; text-transform:uppercase; }
    .rental-field .hint { font-size:10px; color:#94a3b8; line-height:1.35; }
    .rental-mode-shell { display:grid; gap:12px; }
    .rental-mode-summary { display:grid; gap:10px; padding:12px; border:1px solid #dbe3ef; border-radius:12px; background:#f8fbff; }
    .rental-mode-summary strong { color:#0f172a; font-size:14px; }
    .rental-mode-summary .subtext { color:#475569; font-size:12px; line-height:1.5; }
    .rental-mode-chip { display:inline-flex; align-items:center; padding:4px 8px; border-radius:999px; background:#dbeafe; color:#1d4ed8; font-size:11px; font-weight:700; }
    [data-customer-mode-block][hidden] { display:none !important; }
    .rental-field input,
    .rental-field select,
    .rental-field textarea {
        width:100%;
        border:1px solid #cbd5e1;
        border-radius:9px;
        padding:8px 11px;
        font-size:13px;
        color:#0f172a;
        background:#fff;
        box-sizing:border-box;
    }
    .rental-field textarea { min-height:78px; resize:vertical; }
    .rental-field.is-error label { color:#b91c1c; }
    .rental-field.is-error input,
    .rental-field.is-error select,
    .rental-field.is-error textarea {
        border-color:#ef4444;
        box-shadow:0 0 0 3px rgba(239, 68, 68, 0.10);
        background:#fffafa;
    }
    .rental-field input:focus,
    .rental-field select:focus,
    .rental-field textarea:focus {
        outline:none;
        border-color:#2563eb;
        box-shadow:0 0 0 3px rgba(37, 99, 235, 0.12);
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
        min-height:42px;
        padding:8px 11px;
        border-radius:9px;
        border:1px solid #cbd5e1;
        background:#fff;
        color:#0f172a;
        font-size:13px;
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
        background:#fff;
        color:#0f172a;
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
    .searchable-select-option { cursor:pointer; }
    .searchable-select-option:hover,
    .searchable-select-option.is-selected {
        background:#eff6ff;
        color:#1d4ed8;
    }
    .searchable-select-option.is-active {
        background:#dbeafe;
        color:#1d4ed8;
    }
    .searchable-select-option mark {
        background:#dbeafe;
        color:inherit;
        padding:0 1px;
        border-radius:4px;
        font-weight:800;
    }
    .searchable-select-empty { color:#64748b; }
    .rental-field.is-error .searchable-select-trigger {
        border-color:#ef4444;
        box-shadow:0 0 0 3px rgba(239, 68, 68, 0.10);
        background:#fffafa;
    }
    .field-error {
        font-size:11px;
        color:#b91c1c;
        line-height:1.35;
    }
    .field-warning {
        display:none;
        padding:10px 12px;
        border-radius:10px;
        border:1px solid #fecaca;
        background:#fef2f2;
        color:#991b1b;
        font-size:12px;
        line-height:1.4;
    }
    .rental-inline-note {
        padding:10px 12px;
        border-radius:10px;
        background:#f8fafc;
        border:1px solid #e2e8f0;
        font-size:12px;
        color:#475569;
    }
    .rental-summary {
        display:grid;
        grid-template-columns:repeat(4, minmax(0, 1fr));
        gap:10px;
    }
    .rental-metric {
        border:1px solid #e2e8f0;
        border-radius:12px;
        padding:12px;
        background:#f8fafc;
    }
    .rental-metric span { display:block; font-size:11px; text-transform:uppercase; letter-spacing:0.04em; color:#64748b; }
    .rental-metric strong { display:block; margin-top:5px; font-size:18px; color:#0f172a; }
    .asset-panel {
        border:1px solid #dbe3ef;
        border-radius:12px;
        padding:14px;
        background:#fcfdff;
    }
    .asset-toolbar {
        display:flex;
        justify-content:space-between;
        align-items:flex-start;
        gap:10px;
        flex-wrap:wrap;
        margin-bottom:10px;
    }
    .asset-toolbar-actions {
        display:flex;
        align-items:center;
        justify-content:flex-end;
        gap:8px;
        flex-wrap:wrap;
        margin-left:auto;
    }
    .asset-search {
        width:260px;
        max-width:100%;
        border:1px solid #cbd5e1;
        border-radius:10px;
        padding:9px 12px;
        font-size:14px;
    }
    .asset-helper { font-size:12px; color:#64748b; }
    .asset-summary-grid {
        display:grid;
        grid-template-columns:repeat(4, minmax(0, 1fr));
        gap:10px;
        margin-bottom:12px;
    }
    .asset-summary-box {
        border:1px solid #dbe3ef;
        border-radius:12px;
        padding:12px;
        background:#fff;
        display:grid;
        gap:4px;
    }
    .asset-summary-box span {
        font-size:11px;
        text-transform:uppercase;
        letter-spacing:0.04em;
        color:#64748b;
        font-weight:700;
    }
    .asset-summary-box strong {
        font-size:18px;
        color:#0f172a;
        line-height:1.2;
    }
    .asset-summary-box small {
        font-size:12px;
        color:#64748b;
        line-height:1.4;
    }
    .asset-summary-box.is-primary {
        border-color:#93c5fd;
        background:#eff6ff;
    }
    .asset-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(210px, 1fr)); gap:10px; }
    .asset-card {
        border:1px solid #dbe3ef;
        border-radius:12px;
        padding:12px;
        background:#fff;
        display:flex;
        flex-direction:column;
        gap:6px;
        cursor:pointer;
        transition:border-color 0.15s ease, box-shadow 0.15s ease, transform 0.15s ease;
    }
    .asset-card:hover { border-color:#93c5fd; transform:translateY(-1px); }
    .asset-card.is-selected {
        border-color:#2563eb;
        box-shadow:0 0 0 3px rgba(37, 99, 235, 0.12);
        background:#eff6ff;
    }
    .asset-card-head {
        display:flex;
        align-items:flex-start;
        justify-content:space-between;
        gap:8px;
    }
    .asset-card strong { color:#0f172a; font-size:14px; }
    .asset-card small { color:#64748b; font-size:12px; }
    .asset-card input { display:none; }
    .asset-card-pill {
        display:inline-flex;
        align-items:center;
        justify-content:center;
        min-width:74px;
        padding:4px 9px;
        border-radius:999px;
        border:1px solid #cbd5e1;
        background:#fff;
        color:#475569;
        font-size:11px;
        font-weight:700;
        text-transform:uppercase;
        letter-spacing:0.03em;
    }
    .asset-card-pill.is-selected {
        border-color:#2563eb;
        background:#2563eb;
        color:#fff;
    }
    .asset-card-note {
        margin-top:2px;
        font-size:12px;
        font-weight:600;
        color:#2563eb;
    }
    .asset-empty,
    .asset-warning {
        border:1px dashed #cbd5e1;
        border-radius:12px;
        padding:18px;
        text-align:center;
        font-size:13px;
        color:#64748b;
        background:#fff;
    }
    .asset-warning {
        border-style:solid;
        border-color:#fde68a;
        background:#fffbeb;
        color:#92400e;
        text-align:left;
    }
    .asset-load-more {
        display:flex;
        justify-content:center;
        margin-top:12px;
    }
    .ops-button,
    .ops-button-secondary,
    .ops-link {
        display:inline-flex;
        align-items:center;
        justify-content:center;
        gap:6px;
        border-radius:10px;
        padding:10px 14px;
        font-size:13px;
        font-weight:600;
        text-decoration:none;
        border:1px solid transparent;
        cursor:pointer;
    }
    .ops-button { background:#2563eb; color:#fff; }
    .ops-button-secondary { background:#fff; border-color:#cbd5e1; color:#334155; }
    .ops-link { padding:0; border:none; background:none; color:#2563eb; }
    .rental-error {
        border:1px solid #fecaca;
        background:#fef2f2;
        color:#b91c1c;
        border-radius:12px;
        padding:14px 16px;
    }
    .badge {
        display:inline-flex;
        align-items:center;
        padding:4px 10px;
        border-radius:999px;
        font-size:11px;
        font-weight:700;
        letter-spacing:0.03em;
        text-transform:uppercase;
    }
    .badge-blue { background:#dbeafe; color:#1d4ed8; }
    .badge-amber { background:#fef3c7; color:#b45309; }
    .badge-slate { background:#e2e8f0; color:#475569; }
    .sale-item-panel {
        border:1px solid #dbe3ef;
        border-radius:12px;
        background:#fcfdff;
        overflow:hidden;
    }
    .sale-item-head,
    .sale-item-row {
        display:grid;
        grid-template-columns:minmax(0, 2.2fr) 90px 120px 120px 190px;
        gap:10px;
        align-items:start;
        padding:12px 14px;
    }
    .sale-item-panel.is-sale-items .sale-item-head,
    .sale-item-panel.is-sale-items .sale-item-row {
        grid-template-columns:minmax(0, 2fr) minmax(140px, 1fr) 90px 140px 190px;
    }
    .sale-item-head {
        background:#f8fafc;
        border-bottom:1px solid #e2e8f0;
        font-size:11px;
        font-weight:700;
        color:#64748b;
        text-transform:uppercase;
        letter-spacing:.05em;
    }
    .sale-item-row + .sale-item-row { border-top:1px solid #e2e8f0; }
    .sale-item-entry + .sale-item-entry { border-top:1px solid #e2e8f0; }
    .sale-item-row select,
    .sale-item-row input,
    .sale-item-row textarea {
        width:100%;
        border:1px solid #cbd5e1;
        border-radius:10px;
        padding:9px 10px;
        font-size:13px;
        box-sizing:border-box;
        background:#fff;
        color:#0f172a;
    }
    .sale-item-row textarea { min-height:42px; resize:vertical; }
    .sale-item-subnote {
        margin-top:6px;
        font-size:11px;
        color:#64748b;
        line-height:1.4;
    }
    .sale-item-detail {
        padding:0 14px 14px;
        background:#fcfdff;
    }
    .sale-item-detail[hidden] { display:none !important; }
    .sale-item-detail-grid {
        display:grid;
        grid-template-columns:repeat(2, minmax(0, 1fr));
        gap:12px;
        padding:12px 14px;
        border:1px dashed #dbe3ef;
        border-radius:12px;
        background:#fff;
    }
    .sale-item-detail-field {
        display:flex;
        flex-direction:column;
        gap:6px;
    }
    .sale-item-detail-field label {
        font-size:11px;
        font-weight:700;
        text-transform:uppercase;
        letter-spacing:.04em;
        color:#475569;
    }
    .rental-line-asset-picker {
        display:grid;
        gap:8px;
        max-height:220px;
        overflow:auto;
        padding:10px;
        border:1px solid #cbd5e1;
        border-radius:10px;
        background:#fff;
    }
    .rental-line-asset-option {
        display:flex;
        align-items:flex-start;
        gap:8px;
        font-size:12px;
        color:#334155;
        line-height:1.4;
    }
    .rental-line-asset-option input {
        margin-top:2px;
        flex:0 0 auto;
    }
    .rental-line-asset-empty {
        font-size:12px;
        color:#64748b;
    }
    .line-asset-search {
        width:100%;
        border:1px solid #cbd5e1;
        border-radius:10px;
        padding:8px 10px;
        font-size:12px;
        box-sizing:border-box;
        background:#fff;
        color:#0f172a;
    }
    .line-asset-load-more {
        display:flex;
        justify-content:flex-start;
        margin-top:8px;
    }
    .sale-section-disclosure {
        border:1px solid #dbe3ef;
        border-radius:14px;
        background:#fff;
        overflow:hidden;
    }
    .sale-section-disclosure.is-error {
        border-color:#ef4444;
        box-shadow:0 0 0 3px rgba(239, 68, 68, 0.08);
    }
    .sale-section-disclosure summary {
        list-style:none;
        cursor:pointer;
        display:flex;
        justify-content:space-between;
        align-items:flex-start;
        gap:16px;
        padding:16px 18px;
    }
    .sale-section-disclosure summary::-webkit-details-marker { display:none; }
    .sale-section-heading {
        display:grid;
        gap:6px;
        min-width:0;
    }
    .sale-section-heading strong {
        font-size:20px;
        color:#0f172a;
        line-height:1.2;
    }
    .sale-section-heading span {
        font-size:13px;
        color:#64748b;
        line-height:1.5;
    }
    .sale-section-meta {
        display:flex;
        align-items:center;
        justify-content:flex-end;
        gap:8px;
        flex-wrap:wrap;
    }
    .sale-section-chip {
        display:inline-flex;
        align-items:center;
        gap:6px;
        padding:8px 11px;
        border-radius:999px;
        border:1px solid #dbe3ef;
        background:#f8fafc;
        font-size:12px;
        color:#475569;
        white-space:nowrap;
    }
    .sale-section-chip strong {
        color:#0f172a;
        font-size:13px;
    }
    .sale-section-state .is-open { display:none; }
    .sale-section-disclosure[open] .sale-section-state .is-open { display:inline; }
    .sale-section-disclosure[open] .sale-section-state .is-collapsed { display:none; }
    .sale-section-body {
        border-top:1px solid #e2e8f0;
        padding:0 18px 18px;
        display:grid;
        gap:12px;
    }
    .compact-section-disclosure summary {
        padding:14px 18px;
        align-items:center;
    }
    .compact-section-title {
        display:inline-flex;
        align-items:center;
        gap:8px;
        font-size:16px;
        font-weight:700;
        color:#0f172a;
        line-height:1.3;
    }
    .compact-section-title .is-open { display:none; }
    .compact-section-disclosure[open] .compact-section-title .is-open { display:inline; }
    .compact-section-disclosure[open] .compact-section-title .is-collapsed { display:none; }
    .compact-section-summary {
        display:flex;
        align-items:center;
        gap:8px;
        flex-wrap:wrap;
        justify-content:flex-end;
    }
    .compact-section-summary .sale-section-chip {
        padding:7px 10px;
    }
    .sale-item-total {
        display:flex;
        align-items:center;
        min-height:42px;
        font-size:13px;
        font-weight:700;
        color:#0f172a;
    }
    .sale-item-actions {
        display:flex;
        justify-content:flex-end;
        align-items:center;
        gap:8px;
        flex-wrap:wrap;
    }
    .sale-item-empty {
        padding:18px 14px;
        color:#64748b;
        font-size:13px;
        text-align:center;
        background:#fff;
    }
    .sale-item-summary {
        display:flex;
        justify-content:space-between;
        align-items:center;
        gap:10px;
        flex-wrap:wrap;
        margin-top:12px;
        padding:12px 14px;
        border:1px solid #e2e8f0;
        border-radius:12px;
        background:#f8fafc;
        font-size:13px;
        color:#475569;
    }
    .rental-inline-stack { display:flex; gap:8px; align-items:flex-end; flex-wrap:nowrap; }
    .rental-inline-stack .rental-field { flex:1 1 auto; min-width:0; }
    .ops-button-secondary.is-compact { padding:8px 12px; white-space:nowrap; }
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
    @media (max-width: 980px) {
        .rental-col-3,
        .rental-col-4,
        .rental-col-5,
        .rental-col-6,
        .rental-col-7,
        .rental-col-8 { grid-column:span 12; }
        .rental-summary { grid-template-columns:repeat(2, minmax(0, 1fr)); }
        .asset-summary-grid { grid-template-columns:repeat(2, minmax(0, 1fr)); }
        .sale-item-head { display:none; }
        .sale-item-row { grid-template-columns:repeat(2, minmax(0, 1fr)); }
        .sale-item-detail-grid { grid-template-columns:1fr; }
        .sale-section-disclosure summary { flex-direction:column; }
        .sale-section-meta { justify-content:flex-start; }
        .compact-section-summary { justify-content:flex-start; }
        .rental-inline-stack { flex-wrap:wrap; }
    }
    @media (max-width: 640px) {
        .rental-toolbar {
            flex-direction:column;
            margin-bottom:0;
        }
        .rental-actions {
            width:100%;
        }
        .rental-actions > * {
            flex:1 1 calc(50% - 8px);
            min-width:0;
        }
        .rental-summary { grid-template-columns:repeat(2, minmax(0, 1fr)); }
        .asset-toolbar { align-items:stretch; }
        .asset-toolbar-actions { justify-content:stretch; margin-left:0; }
        .asset-toolbar-actions .ops-button-secondary { flex:1 1 140px; }
        .asset-summary-grid { grid-template-columns:1fr; }
        .asset-search { width:100%; }
        .quick-col-4,
        .quick-col-8 { grid-column:span 12; }
        .quick-inline-grid { grid-template-columns:1fr; }
        .sale-item-row { grid-template-columns:1fr; }
        .rental-snapshot-card { display:none; }
        .rental-card { border-radius:14px !important; }
        .rental-card h2 { font-size:18px !important; }
        .rental-inline-stack { flex-direction:column; align-items:stretch; }
        .rental-inline-stack > * { width:100%; }
        .ops-button,
        .ops-button-secondary {
            min-height:44px;
        }
    }
</style>

<div class="rental-shell">
    <div class="rental-toolbar">
        <div class="rental-title">
            <h1>{{ $isEdit ? 'Edit Rental' : 'New Rental' }}</h1>
            @if($isEdit)
                <p>Update booking, assets, and delivery.</p>
            @endif
        </div>
        <div class="rental-actions">
            <a href="{{ route('rentals.index') }}" class="ops-button-secondary">Back to Rentals</a>
            @if($isEdit)
                <a href="{{ route('rentals.show', $rental) }}" class="ops-button-secondary">View Rental</a>
            @endif
        </div>
    </div>

    @if ($errors->any())
        <div class="rental-error">
            <strong>Please review the highlighted rental details.</strong>
            <ul style="margin:8px 0 0 18px; padding:0;">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="rental-card">
        <h2>Rental Snapshot</h2>
        <div class="rental-summary">
            <div class="rental-metric">
                <span>Mode</span>
                <strong>{{ $isEdit ? 'Update Existing' : 'Create Fresh' }}</strong>
            </div>
            <div class="rental-metric">
                <span>Assigned Assets</span>
                <strong id="selectedAssetCount">{{ count($selectedAssetIds) }}</strong>
            </div>
            <div class="rental-metric">
                <span>Requested Quantity</span>
                <strong id="quantityMetric">{{ old('quantity', $isEdit ? $rental->quantity : 1) }}</strong>
            </div>
            <div class="rental-metric">
                <span>Dispatch Warehouse</span>
                <strong id="warehouseMetric">{{ old('dispatch_warehouse_id', $isEdit ? $rental->dispatch_warehouse_id : '') ? optional($warehouses->firstWhere('id', (int) old('dispatch_warehouse_id', $isEdit ? $rental->dispatch_warehouse_id : '')))->name : 'Any warehouse' }}</strong>
            </div>
        </div>
    </div>

    <div class="rental-card">
        <h2>Customer & Rental Details</h2>
        <div class="rental-grid">
            <div class="rental-col-12 party-flow-shell">
                <div class="party-flow-toggle-wrap">
                    <div class="rental-field{{ $hasFieldError('customer_type') ? ' is-error' : '' }}" style="gap:8px;">
                        <label for="customer_type">Customer Type</label>
                        <select name="customer_type" id="customer_type" class="party-flow-select-native">
                            <option value="direct_customer" {{ $customerTypeValue === 'direct_customer' ? 'selected' : '' }}>Direct Customer</option>
                            <option value="business_partner" {{ $customerTypeValue === 'business_partner' ? 'selected' : '' }}{{ $businessPartnerFlowAvailable ? '' : ' disabled' }}>Business Partner / Tie-up</option>
                        </select>
                        <div class="party-flow-toggle" role="tablist" aria-label="Customer type">
                            <button type="button" class="party-flow-option{{ $customerTypeValue === 'direct_customer' ? ' is-active' : '' }}" data-customer-type-option="direct_customer" aria-pressed="{{ $customerTypeValue === 'direct_customer' ? 'true' : 'false' }}">Direct Customer</button>
                            <button type="button" class="party-flow-option{{ $customerTypeValue === 'business_partner' ? ' is-active' : '' }}{{ $businessPartnerFlowAvailable ? '' : ' is-disabled' }}" data-customer-type-option="business_partner" aria-pressed="{{ $customerTypeValue === 'business_partner' ? 'true' : 'false' }}"{{ $businessPartnerFlowAvailable ? '' : ' aria-disabled="true" disabled' }}>Business Partner</button>
                        </div>
                        @if($hasFieldError('customer_type'))
                            <span class="field-error">{{ $fieldError('customer_type') }}</span>
                        @endif
                    </div>
                    <div class="party-flow-hint">Keep it fast: direct customer stays simple, while business partner only appears when billing and delivery go to different people.</div>
                    <div class="party-flow-admin-note"{{ $businessPartnerFlowAvailable ? ' hidden' : '' }}>
                        Business Partner setup pending. Run migrations to enable partner workflow.
                    </div>
                </div>

                <div class="party-flow-rows">
                    <div class="party-flow-row" data-customer-mode-block="direct_customer">
                        <div class="rental-field{{ $hasFieldError('customer_id') ? ' is-error' : '' }}">
                            <label for="customer_id">Customer</label>
                            <select name="customer_id" id="customer_id" data-searchable-select data-search-placeholder="Search customer by name or phone">
                                <option value="">Select customer</option>
                                @foreach($customers as $customer)
                                    <option
                                        value="{{ $customer->id }}"
                                        data-name="{{ $customer->name }}"
                                        data-phone="{{ \App\Support\PhoneNumber::local($customer->phone) }}"
                                        data-phone-country="{{ \App\Support\PhoneNumber::countryCode($customer->phone) }}"
                                        data-address="{{ $customer->address }}"
                                        data-city="{{ $customer->city }}"
                                        data-state="{{ $customer->state }}"
                                        data-location="{{ $customer->openMapUrl() }}"
                                        data-search="{{ trim(implode(' ', array_filter([$customer->name, $customer->phone, $customer->email, $customer->city]))) }}"
                                        {{ (int) $selectedCustomerId === $customer->id ? 'selected' : '' }}>
                                        {{ $customer->name }}{{ $customer->phone ? ' • ' . $customer->phone : '' }}
                                    </option>
                                @endforeach
                            </select>
                            @if($hasFieldError('customer_id'))
                                <span class="field-error">{{ $fieldError('customer_id') }}</span>
                            @endif
                        </div>
                        <button type="button" class="party-flow-link" data-open-modal="rentalQuickCustomerModal">+ Add</button>
                    </div>

                    <div class="party-flow-row" data-customer-mode-block="business_partner">
                        <div class="rental-field{{ $hasFieldError('business_partner_id') ? ' is-error' : '' }}">
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
                            @if($hasFieldError('business_partner_id'))
                                <span class="field-error">{{ $fieldError('business_partner_id') }}</span>
                            @endif
                        </div>
                        <button type="button" class="party-flow-link" data-open-modal="rentalBusinessPartnerModal">+ Add</button>
                    </div>

                    <div class="party-flow-row" data-customer-mode-block="business_partner" id="partnerClientRow">
                        <div class="rental-field{{ $hasFieldError('partner_client_id') ? ' is-error' : '' }}">
                            <label for="partner_client_id">Actual Client / Delivery Location</label>
                            <select name="partner_client_id" id="partner_client_id" data-searchable-select data-search-placeholder="Search actual client by name, phone, address, or city">
                                <option value="">Select actual client</option>
                                @foreach($initialPartnerClients as $client)
                                    <option
                                        value="{{ $client->id }}"
                                        data-business-partner-id="{{ $client->business_partner_id }}"
                                        data-name="{{ $client->displayName() }}"
                                        data-phone="{{ \App\Support\PhoneNumber::local($client->primaryPhone()) }}"
                                        data-phone-country="{{ \App\Support\PhoneNumber::countryCode($client->primaryPhone()) }}"
                                        data-state="{{ $client->state }}"
                                        data-address="{{ $client->address }}"
                                        data-city="{{ $client->city }}"
                                        data-location="{{ $client->openMapUrl() }}"
                                        data-notes="{{ $client->delivery_notes }}"
                                        data-search="{{ trim(implode(' ', array_filter([$client->displayName(), $client->primaryPhone(), $client->address, $client->city, $client->state]))) }}"
                                        {{ $selectedPartnerClientId === $client->id ? 'selected' : '' }}>
                                        {{ $client->displayName() }}{{ $client->primaryPhone() ? ' • ' . $client->primaryPhone() : '' }}
                                    </option>
                                @endforeach
                            </select>
                            @if($hasFieldError('partner_client_id'))
                                <span class="field-error">{{ $fieldError('partner_client_id') }}</span>
                            @endif
                        </div>
                        <button type="button" class="party-flow-link{{ $selectedBusinessPartner ? '' : ' is-disabled' }}" id="addActualClientLink" data-open-modal="rentalPartnerClientModal" aria-disabled="{{ $selectedBusinessPartner ? 'false' : 'true' }}">+ Add</button>
                    </div>
                    <div class="party-flow-helper" id="partnerClientHelper" data-customer-mode-block="business_partner"{{ $selectedPartnerClient ? ' hidden' : '' }}>
                        <strong id="partnerClientHelperTitle">{{ $selectedBusinessPartner ? 'Select or add an actual delivery client.' : 'Select a business partner to continue.' }}</strong>
                        <span id="partnerClientHelperText">{{ $selectedBusinessPartner ? (((int) ($selectedBusinessPartner->partner_clients_count ?? 0)) > 0 ? 'Choose the delivery or service client for this rental.' : 'No actual clients added for this business partner yet.') : 'The actual client will be used for delivery, pickup, and service.' }}</span>
                    </div>
                </div>

                <div class="party-flow-summary" id="customerContactUsageCard"{{ $shouldShowRentalCustomerSummary ? '' : ' hidden' }}>
                    <div class="party-flow-summary-head">
                        <span class="party-flow-summary-title" id="customerFlowSummaryTitle">{{ $customerTypeValue === 'business_partner' ? 'Contact Routing' : 'Customer Summary' }}</span>
                        <span class="party-flow-badge" id="customerFlowBadge">{{ $customerTypeValue === 'business_partner' ? 'Business Partner' : 'Direct Customer' }}</span>
                    </div>
                    <div class="party-flow-lines">
                        <div class="party-flow-line">
                            <label id="reminderContactLabel">{{ $customerTypeValue === 'business_partner' ? 'Reminder / Payment Contact' : 'Customer' }}</label>
                            <strong id="reminderContactSummary">
                                @if($customerTypeValue === 'business_partner')
                                    {{ $selectedBusinessPartner ? collect([$selectedBusinessPartner->displayName(), $selectedBusinessPartner->phone])->filter()->implode(' • ') : 'Select a business partner' }}
                                @else
                                    {{ $selectedCustomer ? collect([$selectedCustomer->name, \App\Support\PhoneNumber::local($selectedCustomer->phone) ?: $selectedCustomer->phone])->filter()->implode(' • ') : 'Select a customer' }}
                                @endif
                            </strong>
                        </div>
                        <div class="party-flow-line" id="deliveryContactLine"{{ $customerTypeValue === 'business_partner' ? '' : ' hidden' }}>
                            <label id="deliveryContactLabel">Delivery / Pickup Contact</label>
                            <strong id="deliveryContactSummary">
                                {{ $selectedPartnerClient ? collect([$selectedPartnerClient->displayName(), \App\Support\PhoneNumber::local($selectedPartnerClient->primaryPhone()) ?: $selectedPartnerClient->primaryPhone()])->filter()->implode(' • ') : 'Select an actual client' }}
                            </strong>
                        </div>
                        <div class="party-flow-line">
                            <label id="deliveryAddressLabel">{{ $customerTypeValue === 'business_partner' ? 'Delivery Address' : 'Address' }}</label>
                            <strong id="deliveryAddressSummary">
                                @if($customerTypeValue === 'business_partner')
                                    {{ collect([$selectedPartnerClient?->address, $selectedPartnerClient?->city, $selectedPartnerClient?->state])->filter()->implode(', ') }}
                                @else
                                    {{ collect([$selectedCustomer?->address, $selectedCustomer?->city, $selectedCustomer?->state])->filter()->implode(', ') }}
                                @endif
                            </strong>
                        </div>
                        <div class="party-flow-meta" id="deliveryNotesSummary">{{ $customerTypeValue === 'business_partner' ? ($selectedPartnerClient?->delivery_notes ?: '') : '' }}</div>
                    </div>
                    <div class="party-flow-summary-actions" id="deliverySummaryActions"{{ (($customerTypeValue === 'business_partner' ? ($selectedPartnerClient?->openMapUrl()) : ($selectedCustomer?->openMapUrl())) ? '' : ' hidden') }}>
                        <a
                            href="{{ $customerTypeValue === 'business_partner' ? ($selectedPartnerClient?->openMapUrl() ?: '#') : ($selectedCustomer?->openMapUrl() ?: '#') }}"
                            target="_blank"
                            rel="noopener"
                            class="party-flow-summary-link"
                            id="deliveryOpenMapLink"{{ (($customerTypeValue === 'business_partner' ? ($selectedPartnerClient?->openMapUrl()) : ($selectedCustomer?->openMapUrl())) ? '' : ' hidden') }}>
                            Open Map
                        </a>
                    </div>
                </div>
            </div>

            <input type="hidden" name="customer_name" id="customer_name" value="{{ old('customer_name', $isEdit ? $rental->customer_name : ($selectedCustomer?->name ?? '')) }}">
            <input type="hidden" name="phone_country_code" id="phone_country_code" value="{{ old('phone_country_code', $phoneParts['code']) }}">
            <input type="hidden" name="phone" id="phone" value="{{ $phoneParts['local'] }}">

            <div class="rental-field rental-col-4{{ $hasFieldError('product_id', 'rental_items') ? ' is-error' : '' }}">
                <label for="product_id">Product</label>
                <select name="product_id" id="product_id" required data-searchable-select data-search-placeholder="Search product by name, brand, model, SKU, or code">
                    <option value="">Select product</option>
                    @foreach($rentalProducts as $product)
                        <option
                            value="{{ $product->id }}"
                            data-available="{{ $product->rental_available_quantity ?? $product->display_available_quantity ?? $product->available_quantity }}"
                            data-rental-available="{{ $product->rental_available_quantity ?? $product->display_available_quantity ?? $product->available_quantity }}"
                            data-rental-status="{{ $product->rental_availability_status }}"
                            data-rental-label="{{ $product->rental_dropdown_label }}"
                            data-rental-warehouse-quantities="{{ e(json_encode($product->rental_warehouse_quantities ?? [])) }}"
                            data-tracks-rental="{{ $product->tracksRentalStock() ? 1 : 0 }}"
                            data-product-name="{{ $product->name }}"
                            data-price-per-day="{{ (float) ($product->price_per_day ?? 0) }}"
                            data-rental-price="{{ (float) ($product->rental_price ?? 0) }}"
                            data-rental-price-15="{{ (float) ($product->rental_price_15_days ?? 0) }}"
                            data-rental-price-30="{{ (float) ($product->rental_price_30_days ?? 0) }}"
                            data-rental-price-90="{{ (float) ($product->rental_price_3_months ?? 0) }}"
                            data-gst-rate="{{ $product->gst_tax_type === \App\Models\Product::GST_TAX_TYPE_IGST ? round((float) ($product->igst_rate ?? 0), 2) : round((float) ($product->cgst_rate ?? 0) + (float) ($product->sgst_rate ?? 0), 2) }}"
                            data-gst-mode="{{ in_array($product->gst_calculation_mode, \App\Models\Product::GST_CALCULATION_MODES, true) ? $product->gst_calculation_mode : 'exclusive' }}"
                            data-search="{{ trim(implode(' ', array_filter([$product->name, $product->brand, $product->model_name, $product->sku, $product->product_code]))) }}"
                            {{ (int) old('product_id', $isEdit ? $rental->product_id : null) === $product->id ? 'selected' : '' }}>
                            {{ $product->name }} • {{ $product->rental_dropdown_label ?? ('Rental Available ' . ($product->display_available_quantity ?? $product->available_quantity)) }}
                        </option>
                    @endforeach
                </select>
                <div class="field-warning" id="productRentalWarning"></div>
                @if($hasFieldError('product_id', 'rental_items'))
                    <span class="field-error">{{ $fieldError('product_id', 'rental_items') }}</span>
                @endif
            </div>

            <div class="rental-field rental-col-4{{ $hasFieldError('dispatch_warehouse_id') ? ' is-error' : '' }}">
                <label for="dispatch_warehouse_id">Warehouse</label>
                <select name="dispatch_warehouse_id" id="dispatch_warehouse_id">
                    <option value="">Any warehouse</option>
                    @foreach($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}" {{ (int) old('dispatch_warehouse_id', $isEdit ? $rental->dispatch_warehouse_id : null) === $warehouse->id ? 'selected' : '' }}>
                            {{ $warehouse->name }}
                        </option>
                    @endforeach
                </select>
                @if($hasFieldError('dispatch_warehouse_id'))
                    <span class="field-error">{{ $fieldError('dispatch_warehouse_id') }}</span>
                @endif
            </div>

            <div class="rental-field rental-col-4{{ $hasFieldError('quantity', 'asset_ids', 'rental_items') ? ' is-error' : '' }}">
                <label for="quantity">Quantity</label>
                <input type="number" name="quantity" id="quantity" min="1" value="{{ old('quantity', $isEdit ? $rental->quantity : 1) }}" required>
                <span class="hint" id="productAvailabilityHint">Rental availability will show here.</span>
                @if($hasFieldError('quantity', 'asset_ids', 'rental_items'))
                    <span class="field-error">{{ $fieldError('quantity', 'asset_ids', 'rental_items') }}</span>
                @endif
            </div>

            <div class="rental-field rental-col-3{{ $hasFieldError('duration_preset') ? ' is-error' : '' }}">
                <label for="duration_preset">Duration</label>
                <select id="duration_preset" name="duration_preset">
                    <option value="custom">Custom</option>
                    <option value="7_days">7 Days</option>
                    <option value="15_days">15 Days</option>
                    <option value="30_days">30 Days</option>
                    <option value="3_months">3 Months</option>
                    <option value="6_months">6 Months</option>
                </select>
            </div>

            <div class="rental-field rental-col-3{{ $hasFieldError('start_date') ? ' is-error' : '' }}">
                <label for="start_date">Start Date</label>
                <input type="date" name="start_date" id="start_date" value="{{ old('start_date', $isEdit && $rental->start_date ? $rental->start_date->format('Y-m-d') : '') }}" required>
                @if($hasFieldError('start_date'))
                    <span class="field-error">{{ $fieldError('start_date') }}</span>
                @endif
            </div>

            <div class="rental-field rental-col-3{{ $hasFieldError('end_date') ? ' is-error' : '' }}">
                <label for="end_date">End Date</label>
                <input type="date" name="end_date" id="end_date" value="{{ old('end_date', $isEdit && $rental->end_date ? $rental->end_date->format('Y-m-d') : '') }}" required>
                @if($hasFieldError('end_date'))
                    <span class="field-error">{{ $fieldError('end_date') }}</span>
                @endif
            </div>

            <div class="rental-field rental-col-3{{ $hasFieldError('rental_amount') ? ' is-error' : '' }}">
                <label for="rental_amount">Rental Amount</label>
                <input type="number" step="0.01" min="0" name="rental_amount" id="rental_amount" value="{{ old('rental_amount', $isEdit ? $rental->rental_amount : 0) }}">
                @if($hasFieldError('rental_amount'))
                    <span class="field-error">{{ $fieldError('rental_amount') }}</span>
                @endif
            </div>

            <div class="rental-field rental-col-3{{ $hasFieldError('gst_rate') ? ' is-error' : '' }}">
                <label for="gst_rate">GST %</label>
                @php($primaryGstRateValue = old('gst_rate', $primaryRentalItem?->gst_rate ?? 0))
                <select name="gst_rate" id="gst_rate">
                    @foreach($gstDropdownOptions($primaryGstRateValue) as $rateOption)
                        <option value="{{ $rateOption }}" @selected(number_format((float) $primaryGstRateValue, 2, '.', '') === $rateOption)>
                            {{ rtrim(rtrim($rateOption, '0'), '.') }}%
                        </option>
                    @endforeach
                </select>
                @if($hasFieldError('gst_rate'))
                    <span class="field-error">{{ $fieldError('gst_rate') }}</span>
                @endif
            </div>

            <div class="rental-field rental-col-3{{ $hasFieldError('gst_mode') ? ' is-error' : '' }}">
                <label for="gst_mode">GST Mode</label>
                <select name="gst_mode" id="gst_mode">
                    <option value="exclusive" {{ old('gst_mode', $primaryRentalItem?->gst_mode ?? 'exclusive') === 'exclusive' ? 'selected' : '' }}>Exclusive</option>
                    <option value="inclusive" {{ old('gst_mode', $primaryRentalItem?->gst_mode ?? 'exclusive') === 'inclusive' ? 'selected' : '' }}>Inclusive</option>
                </select>
                @if($hasFieldError('gst_mode'))
                    <span class="field-error">{{ $fieldError('gst_mode') }}</span>
                @endif
            </div>

            <div class="rental-field rental-col-3{{ $hasFieldError('tax_type') ? ' is-error' : '' }}">
                <label for="tax_type">Tax Type</label>
                <select name="tax_type" id="tax_type">
                    <option value="{{ \App\Models\Product::GST_TAX_TYPE_CGST_SGST }}" {{ old('tax_type', $primaryRentalItem?->tax_type ?? \App\Models\Product::GST_TAX_TYPE_CGST_SGST) === \App\Models\Product::GST_TAX_TYPE_CGST_SGST ? 'selected' : '' }}>CGST + SGST</option>
                    <option value="{{ \App\Models\Product::GST_TAX_TYPE_IGST }}" {{ old('tax_type', $primaryRentalItem?->tax_type ?? \App\Models\Product::GST_TAX_TYPE_CGST_SGST) === \App\Models\Product::GST_TAX_TYPE_IGST ? 'selected' : '' }}>IGST</option>
                </select>
                @if($hasFieldError('tax_type'))
                    <span class="field-error">{{ $fieldError('tax_type') }}</span>
                @endif
            </div>

            <div class="rental-field rental-col-3{{ $hasFieldError('deposit_amount') ? ' is-error' : '' }}">
                <label for="deposit_amount">Deposit</label>
                <input type="number" step="0.01" min="0" name="deposit_amount" id="deposit_amount" value="{{ old('deposit_amount', $isEdit ? $rental->deposit_amount : 0) }}">
                @if($hasFieldError('deposit_amount'))
                    <span class="field-error">{{ $fieldError('deposit_amount') }}</span>
                @endif
            </div>

            <div class="rental-field rental-col-3{{ $hasFieldError('transport_amount') ? ' is-error' : '' }}">
                <label for="transport_amount">Transport</label>
                <input type="number" step="0.01" min="0" name="transport_amount" id="transport_amount" value="{{ old('transport_amount', $isEdit ? $rental->transport_amount : 0) }}">
                @if($hasFieldError('transport_amount'))
                    <span class="field-error">{{ $fieldError('transport_amount') }}</span>
                @endif
            </div>

            <div class="rental-field rental-col-3{{ $hasFieldError('other_amount') ? ' is-error' : '' }}">
                <label for="other_amount">Other</label>
                <input type="number" step="0.01" min="0" name="other_amount" id="other_amount" value="{{ old('other_amount', $isEdit ? $rental->other_amount : 0) }}">
                @if($hasFieldError('other_amount'))
                    <span class="field-error">{{ $fieldError('other_amount') }}</span>
                @endif
            </div>

            <div class="rental-field {{ $isEdit ? 'rental-col-3' : 'rental-col-6' }}{{ $hasFieldError('delivery_staff_id') ? ' is-error' : '' }}">
                <label for="delivery_staff_id">Delivery Assignment</label>
                <select name="delivery_staff_id" id="delivery_staff_id">
                    <option value="">Select delivery partner</option>
                    @if(($assignableUsers ?? collect())->isNotEmpty())
                        <optgroup label="Internal Delivery Staff">
                            @foreach($assignableUsers as $user)
                                <option value="user:{{ $user->id }}" {{ $selectedDeliveryAssignment === 'user:' . $user->id ? 'selected' : '' }}>
                                    {{ $user->name }} - {{ ucwords(str_replace('_', ' ', $user->effective_role ?? $user->role ?? 'delivery')) }}
                                </option>
                            @endforeach
                        </optgroup>
                    @endif
                    @if($thirdPartyDeliveryMembers->isNotEmpty())
                        <optgroup label="Third-Party Delivery">
                            <option value="third_party" {{ $selectedDeliveryAssignment === 'third_party' ? 'selected' : '' }}>One-time Third-Party Partner</option>
                            @foreach($thirdPartyDeliveryMembers as $staff)
                                <option value="staff:{{ $staff->id }}" {{ $selectedDeliveryAssignment === 'staff:' . $staff->id ? 'selected' : '' }}>
                                    {{ $staff->name }} - {{ $staff->role_display }}
                                </option>
                            @endforeach
                        </optgroup>
                    @else
                        <optgroup label="Third-Party Delivery">
                            <option value="third_party" {{ $selectedDeliveryAssignment === 'third_party' ? 'selected' : '' }}>One-time Third-Party Partner</option>
                        </optgroup>
                    @endif
                    @if($vendorDeliveryMembers->isNotEmpty())
                        <optgroup label="Vendor Delivery">
                            @foreach($vendorDeliveryMembers as $staff)
                                <option value="staff:{{ $staff->id }}" {{ $selectedDeliveryAssignment === 'staff:' . $staff->id ? 'selected' : '' }}>
                                    {{ $staff->name }} - {{ $staff->role_display }}
                                </option>
                            @endforeach
                        </optgroup>
                    @endif
                    @if($otherAssignableStaffMembers->isNotEmpty())
                        <optgroup label="Other Assignment Records">
                            @foreach($otherAssignableStaffMembers as $staff)
                                <option value="staff:{{ $staff->id }}" {{ $selectedDeliveryAssignment === 'staff:' . $staff->id ? 'selected' : '' }}>
                                    {{ $staff->name }} - {{ $staff->role_display }}
                                </option>
                            @endforeach
                        </optgroup>
                    @endif
                </select>
                <div class="ops-muted" style="margin-top:6px;">Choose internal staff or a third-party/vendor delivery partner.</div>
                @if($hasFieldError('delivery_staff_id'))
                    <span class="field-error">{{ $fieldError('delivery_staff_id') }}</span>
                @endif
            </div>

            <div
                class="rental-col-12"
                id="thirdPartyDeliveryFields"
                style="{{ $selectedDeliveryAssignment === 'third_party' ? '' : 'display:none;' }}"
            >
                <div class="rental-card" style="margin:0;">
                    <h2>Third-Party Delivery Details</h2>
                    <p class="section-copy">Use this when the delivery partner is not in your saved vendor/staff list.</p>
                    <div class="rental-grid">
                        <div class="rental-field rental-col-4{{ $hasFieldError('third_party_name') ? ' is-error' : '' }}">
                            <label for="third_party_name">Partner Name</label>
                            <input type="text" name="third_party_name" id="third_party_name" value="{{ $thirdPartyNameValue }}" placeholder="Enter partner or company name">
                            @if($hasFieldError('third_party_name'))
                                <span class="field-error">{{ $fieldError('third_party_name') }}</span>
                            @endif
                        </div>
                        <div class="rental-field rental-col-4{{ $hasFieldError('third_party_contact') ? ' is-error' : '' }}">
                            <label for="third_party_contact">Contact Person</label>
                            <input type="text" name="third_party_contact" id="third_party_contact" value="{{ $thirdPartyContactValue }}" placeholder="Enter contact person">
                            @if($hasFieldError('third_party_contact'))
                                <span class="field-error">{{ $fieldError('third_party_contact') }}</span>
                            @endif
                        </div>
                        <div class="rental-field rental-col-4{{ $hasFieldError('third_party_phone') ? ' is-error' : '' }}">
                            <label for="third_party_phone">Contact Phone</label>
                            <input type="text" name="third_party_phone" id="third_party_phone" value="{{ $thirdPartyPhoneValue }}" placeholder="Enter phone number">
                            @if($hasFieldError('third_party_phone'))
                                <span class="field-error">{{ $fieldError('third_party_phone') }}</span>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            @if($isEdit)
            <div class="rental-field rental-col-3{{ $hasFieldError('pickup_staff_id') ? ' is-error' : '' }}">
                <label for="pickup_staff_id">Pickup</label>
                <select name="pickup_staff_id" id="pickup_staff_id">
                    <option value="">Select pickup</option>
                    @foreach($staffMembers as $staff)
                        <option value="{{ $staff->id }}" {{ (int) old('pickup_staff_id', $rental->pickup_staff_id) === $staff->id ? 'selected' : '' }}>
                            {{ $staff->name }} - {{ $staff->role_display }}
                        </option>
                    @endforeach
                </select>
                @if($hasFieldError('pickup_staff_id'))
                    <span class="field-error">{{ $fieldError('pickup_staff_id') }}</span>
                @endif
            </div>
            @endif

            <div class="rental-field rental-col-12">
                <label for="internal_notes">Ops Note</label>
                <textarea id="internal_notes" disabled placeholder="Use delivery notes after save.">{{ $isEdit ? 'Adjust delivery or assets after save.' : 'Delivery opens after save.' }}</textarea>
            </div>
        </div>
    </div>

    <div class="rental-card{{ $hasFieldError('asset_ids') ? ' is-error' : '' }}">
        <h2>Asset Assignment</h2>
        <p class="section-copy">Select rental assets.</p>
        <div class="asset-panel">
            <div class="asset-toolbar">
                <div>
                    <span class="badge badge-blue">Asset linked rental</span>
                    <div class="asset-helper" id="assetCountNote">Select product to load assets.</div>
                </div>
                <div class="asset-toolbar-actions">
                    <button type="button" class="ops-button-secondary" id="selectSuggestedAssets">Auto Select</button>
                    <button type="button" class="ops-button-secondary" id="clearSelectedAssets">Clear</button>
                    <input type="text" id="assetSearch" class="asset-search" placeholder="Search serial, barcode, or asset name">
                </div>
            </div>
            <div class="asset-summary-grid">
                <div class="asset-summary-box is-primary">
                    <span>Status</span>
                    <strong id="assetSelectionStatus">Select product</strong>
                    <small id="assetSelectionHelp">Load assets from product.</small>
                </div>
                <div class="asset-summary-box">
                    <span>Required</span>
                    <strong id="assetRequiredCount">{{ (int) old('quantity', $isEdit ? $rental->quantity : 1) }}</strong>
                    <small>Match qty</small>
                </div>
                <div class="asset-summary-box">
                    <span>Selected</span>
                    <strong id="assetSelectedCountInline">{{ count($selectedAssetIds) }}</strong>
                    <small id="assetSelectedLabel">{{ count($selectedAssetIds) }} chosen</small>
                </div>
                <div class="asset-summary-box">
                    <span>Select</span>
                    <strong>Click card</strong>
                    <small>Blue means assigned</small>
                </div>
            </div>
            <div id="assetSelectionAlert" class="asset-warning" style="display:none;"></div>
            <div id="assetGrid" class="asset-grid"></div>
            <div class="asset-load-more" id="assetLoadMoreWrap" style="display:none;">
                <button type="button" class="ops-button-secondary" id="assetLoadMoreButton">Load More</button>
            </div>
            <div id="assetEmptyState" class="asset-empty">Select product</div>
            @if($hasFieldError('asset_ids'))
                <div class="field-error" style="margin-top:10px;">{{ $fieldError('asset_ids') }}</div>
            @endif
        </div>
    </div>

    <div class="rental-card">
        <details class="sale-section-disclosure compact-section-disclosure{{ $hasRentalItemsError ? ' is-error' : '' }}" id="additionalRentalDisclosure" {{ $additionalRentalExpanded ? 'open' : '' }}>
            <summary>
                <span class="compact-section-title">
                    Add More rental products
                    <span class="is-collapsed">+</span>
                    <span class="is-open">-</span>
                </span>
                <div class="compact-section-summary">
                    <span class="sale-section-chip"><strong id="rentalItemsCountSummary">{{ count($additionalRentalRows) }}</strong> line(s)</span>
                    <span class="sale-section-chip">Total <strong id="rentalItemsGrandTotalSummary">0.00</strong></span>
                </div>
            </summary>
            <div class="sale-section-body">
                @if($hasRentalItemsError)
                    <div class="field-error" style="margin:0 0 10px;">{{ $fieldError('rental_items', 'rental_items.0.asset_ids', 'rental_items.0.product_id', 'rental_items.0.quantity') }}</div>
                @endif
                <div class="sale-item-panel">
                    <div class="sale-item-head">
                        <div>Rental Product</div>
                        <div>Qty</div>
                        <div>Unit Rental</div>
                        <div>Line Total</div>
                        <div>Options</div>
                    </div>
                    <div id="rentalItemsList">
                        <div class="sale-item-empty" id="rentalItemEmptyState">No extra rental products added yet.</div>
                    </div>
                </div>
                <div class="sale-item-summary">
                    <div><strong id="rentalItemsCount">0</strong> additional rental line(s)</div>
                    <div>Additional rental total: <strong id="rentalItemsGrandTotal">0.00</strong></div>
                    <div class="sale-item-actions">
                        <button type="button" class="ops-button-secondary" id="addRentalItemButton">Add Rental Product</button>
                    </div>
                </div>
            </div>
        </details>
    </div>

    <div class="rental-card">
        <details class="sale-section-disclosure{{ $hasSaleItemsError ? ' is-error' : '' }}" id="newProductsDisclosure" {{ $newProductsExpanded ? 'open' : '' }}>
            <summary>
                <div class="sale-section-heading">
                    <strong>New Products Alongside Rental</strong>
                    <span>Optional sale lines.</span>
                </div>
                <div class="sale-section-meta">
                    <span class="sale-section-chip"><strong id="saleItemsCountSummary">{{ count($saleItemRows) }}</strong> line(s)</span>
                    <span class="sale-section-chip">Total <strong id="saleItemsGrandTotalSummary">0.00</strong></span>
                    <span class="sale-section-chip sale-section-state">
                        <span class="is-collapsed">Expand</span>
                        <span class="is-open">Collapse</span>
                    </span>
                </div>
            </summary>
            <div class="sale-section-body">
                @if($hasSaleItemsError)
                    <div class="field-error" style="margin:0 0 10px;">{{ $fieldError('sale_items', 'sale_items.0.asset_id', 'sale_items.0.product_id', 'sale_items.0.quantity') }}</div>
                @endif
                <div class="sale-item-panel is-sale-items">
                    <div class="sale-item-head">
                        <div>New Product</div>
                        <div>Warehouse</div>
                        <div>Qty</div>
                        <div>Unit Price</div>
                        <div>Options</div>
                    </div>
                    <div id="saleItemsList">
                        <div class="sale-item-empty" id="saleItemEmptyState">No new products added yet.</div>
                    </div>
                </div>
                <div class="sale-item-summary">
                    <div><strong id="saleItemsCount">0</strong> new product line(s) attached</div>
                    <div>New products total: <strong id="saleItemsGrandTotal">0.00</strong></div>
                    <div class="sale-item-actions">
                        <button type="button" class="ops-button-secondary" id="addSaleItemButton">Add New Product</button>
                    </div>
                </div>
            </div>
        </details>
    </div>

    <div class="rental-toolbar" style="margin-top:-4px;">
        <div class="rental-inline-note" id="rentalSaveGuidance">
            Match rental asset count with quantity.
        </div>
        <div class="rental-actions">
            <a href="{{ route('rentals.index') }}" class="ops-button-secondary">Cancel</a>
            <button type="submit" class="ops-button" id="rentalSubmitButton">{{ $isEdit ? 'Update Rental' : 'Save Rental' }}</button>
        </div>
    </div>
</div>

<script>
    (function () {
        const customerSelect = document.getElementById('customer_id');
        const customerTypeSelect = document.getElementById('customer_type');
        const businessPartnerSelect = document.getElementById('business_partner_id');
        const partnerClientSelect = document.getElementById('partner_client_id');
        const customerTypeButtons = Array.from(document.querySelectorAll('[data-customer-type-option]'));
        const customerName = document.getElementById('customer_name');
        const phoneInput = document.getElementById('phone');
        const phoneCountryCodeInput = document.getElementById('phone_country_code');
        const customerModeBlocks = Array.from(document.querySelectorAll('[data-customer-mode-block]'));
        const partnerClientRow = document.getElementById('partnerClientRow');
        const customerFlowBadge = document.getElementById('customerFlowBadge');
        const customerFlowSummaryTitle = document.getElementById('customerFlowSummaryTitle');
        const customerContactUsageCard = document.getElementById('customerContactUsageCard');
        const reminderContactLabel = document.getElementById('reminderContactLabel');
        const reminderContactSummary = document.getElementById('reminderContactSummary');
        const deliveryContactLine = document.getElementById('deliveryContactLine');
        const deliveryContactLabel = document.getElementById('deliveryContactLabel');
        const deliveryContactSummary = document.getElementById('deliveryContactSummary');
        const deliveryAddressLabel = document.getElementById('deliveryAddressLabel');
        const deliveryAddressSummary = document.getElementById('deliveryAddressSummary');
        const deliveryNotesSummary = document.getElementById('deliveryNotesSummary');
        const deliverySummaryActions = document.getElementById('deliverySummaryActions');
        const deliveryOpenMapLink = document.getElementById('deliveryOpenMapLink');
        const addActualClientLink = document.getElementById('addActualClientLink');
        const partnerClientHelper = document.getElementById('partnerClientHelper');
        const partnerClientHelperTitle = document.getElementById('partnerClientHelperTitle');
        const partnerClientHelperText = document.getElementById('partnerClientHelperText');
        const productSelect = document.getElementById('product_id');
        const warehouseSelect = document.getElementById('dispatch_warehouse_id');
        const deliveryAssignmentSelect = document.getElementById('delivery_staff_id');
        const thirdPartyDeliveryFields = document.getElementById('thirdPartyDeliveryFields');
        const quantityInput = document.getElementById('quantity');
        const startDateInput = document.getElementById('start_date');
        const endDateInput = document.getElementById('end_date');
        const durationPresetSelect = document.getElementById('duration_preset');
        const rentalAmountInput = document.getElementById('rental_amount');
        const primaryGstRateInput = document.getElementById('gst_rate');
        const primaryGstModeSelect = document.getElementById('gst_mode');
        const primaryTaxTypeSelect = document.getElementById('tax_type');
        const assetGrid = document.getElementById('assetGrid');
        const assetEmptyState = document.getElementById('assetEmptyState');
        const assetSearch = document.getElementById('assetSearch');
        const assetCountNote = document.getElementById('assetCountNote');
        const selectionAlert = document.getElementById('assetSelectionAlert');
        const selectedAssetCount = document.getElementById('selectedAssetCount');
        const quantityMetric = document.getElementById('quantityMetric');
        const warehouseMetric = document.getElementById('warehouseMetric');
        const availabilityHint = document.getElementById('productAvailabilityHint');
        const saleItemsList = document.getElementById('saleItemsList');
        const saleItemEmptyState = document.getElementById('saleItemEmptyState');
        const saleItemsCount = document.getElementById('saleItemsCount');
        const saleItemsCountSummary = document.getElementById('saleItemsCountSummary');
        const saleItemsGrandTotal = document.getElementById('saleItemsGrandTotal');
        const saleItemsGrandTotalSummary = document.getElementById('saleItemsGrandTotalSummary');
        const addSaleItemButton = document.getElementById('addSaleItemButton');
        const newProductsDisclosure = document.getElementById('newProductsDisclosure');
        const rentalItemsList = document.getElementById('rentalItemsList');
        const rentalItemEmptyState = document.getElementById('rentalItemEmptyState');
        const rentalItemsCount = document.getElementById('rentalItemsCount');
        const rentalItemsCountSummary = document.getElementById('rentalItemsCountSummary');
        const rentalItemsGrandTotal = document.getElementById('rentalItemsGrandTotal');
        const rentalItemsGrandTotalSummary = document.getElementById('rentalItemsGrandTotalSummary');
        const addRentalItemButton = document.getElementById('addRentalItemButton');
        const additionalRentalDisclosure = document.getElementById('additionalRentalDisclosure');
        const assetSelectionStatus = document.getElementById('assetSelectionStatus');
        const assetSelectionHelp = document.getElementById('assetSelectionHelp');
        const assetRequiredCount = document.getElementById('assetRequiredCount');
        const assetSelectedCountInline = document.getElementById('assetSelectedCountInline');
        const assetSelectedLabel = document.getElementById('assetSelectedLabel');
        const selectSuggestedAssetsButton = document.getElementById('selectSuggestedAssets');
        const clearSelectedAssetsButton = document.getElementById('clearSelectedAssets');
        const assetLoadMoreWrap = document.getElementById('assetLoadMoreWrap');
        const assetLoadMoreButton = document.getElementById('assetLoadMoreButton');
        const productRentalWarning = document.getElementById('productRentalWarning');
        const rentalSubmitButton = document.getElementById('rentalSubmitButton');
        const rentalSaveGuidance = document.getElementById('rentalSaveGuidance');
        const unavailableRentalMessage = 'No rental asset is available for this product. Add rental asset or convert sale unit to rental first.';

        let selectedAssetIds = @json($selectedAssetIds);
        let currentAssets = [];
        let rentalItems = @json($additionalRentalRows);
        let saleItems = @json($saleItemRows);
        const standardGstRates = ['0.00', '5.00', '12.00', '18.00', '28.00'];
        let rentalAmountTouched = Boolean(@json($isEdit || (old('rental_amount') !== null && old('rental_amount') !== '')));
        const assetVisibleStep = 3;
        let visibleAssetCount = assetVisibleStep;
        const currentRentalId = @json($isEdit ? $rental->id : null);
        const organizationState = @json($organization?->state ?? null);
        const partnerClientEndpointTemplate = @json($businessPartnerFlowAvailable ? route('rentals.business-partners.actual-clients', ['business_partner' => '__PARTNER__']) : null);
        const rentalProductOptionsHtml = `<option value="">Select rental product</option>@foreach($rentalProducts as $product)<option value="{{ $product->id }}" data-default-price="{{ (float) ($product->rental_price ?? $product->price_per_day ?? 0) }}" data-gst-rate="{{ $product->gst_tax_type === \App\Models\Product::GST_TAX_TYPE_IGST ? round((float) ($product->igst_rate ?? 0), 2) : round((float) ($product->cgst_rate ?? 0) + (float) ($product->sgst_rate ?? 0), 2) }}" data-gst-mode="{{ in_array($product->gst_calculation_mode, \App\Models\Product::GST_CALCULATION_MODES, true) ? $product->gst_calculation_mode : 'exclusive' }}" data-search="{{ e(trim(implode(' ', array_filter([$product->name, $product->brand, $product->model_name, $product->sku, $product->product_code, $product->rental_dropdown_label ?? ('Rental Available ' . ($product->display_available_quantity ?? $product->available_quantity))])))) }}">{{ e($product->name) }} | {{ e($product->rental_dropdown_label ?? ('Rental Available ' . ($product->display_available_quantity ?? $product->available_quantity))) }}</option>@endforeach`;
        const saleProductOptionsHtml = `<option value="">Select new product</option>@foreach($sellableProducts as $product)<option value="{{ $product->id }}" data-default-price="{{ (float) ($product->sale_price ?? 0) }}" data-gst-rate="{{ $product->gst_tax_type === \App\Models\Product::GST_TAX_TYPE_IGST ? round((float) ($product->igst_rate ?? 0), 2) : round((float) ($product->cgst_rate ?? 0) + (float) ($product->sgst_rate ?? 0), 2) }}" data-gst-mode="{{ in_array($product->gst_calculation_mode, \App\Models\Product::GST_CALCULATION_MODES, true) ? $product->gst_calculation_mode : 'exclusive' }}" data-search="{{ e(trim(implode(' ', array_filter([$product->name, $product->brand, $product->model_name, $product->sku, $product->product_code, 'Sale ' . number_format((float) ($product->sale_price ?? 0), 2)])))) }}">{{ e($product->name) }} | Sale {{ number_format((float) ($product->sale_price ?? 0), 2) }}</option>@endforeach`;
        const saleAssetOptions = @json($saleAssetRows);
        const warehouseOptionsHtml = `<option value="">Auto / best stock</option>@foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}">{{ e($warehouse->name) }}</option>@endforeach`;
        const rentalItemAssetCache = {};
        const rentalItemAssetRequests = {};
        const partnerClientCache = new Map();
        const inlineAssetVisibleStep = 3;
        let primaryAvailabilityLoadedFor = null;
        let partnerClientRequestToken = 0;

        function escapeHtml(value) {
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function normalizeMoney(value) {
            const parsed = parseFloat(value || 0);
            return Number.isNaN(parsed) ? '0.00' : parsed.toFixed(2);
        }

        function formatGstLabel(value) {
            const normalized = normalizeMoney(value);
            return `${normalized.replace(/\.00$/, '').replace(/(\.\d*[1-9])0+$/, '$1')}%`;
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

        rentalItems = (Array.isArray(rentalItems) ? rentalItems : []).map(function (item) {
            return Object.assign({
                gst_rate: '0.00',
                gst_mode: 'exclusive',
                tax_type: recommendedTaxType(),
            }, item || {});
        });

        saleItems = (Array.isArray(saleItems) ? saleItems : []).map(function (item) {
            return Object.assign({
                gst_rate: '0.00',
                gst_mode: 'exclusive',
                tax_type: recommendedTaxType(),
            }, item || {});
        });

        function highlightMatch(text, query) {
            const source = String(text || '');
            const term = String(query || '').trim();

            if (!term) {
                return escapeHtml(source);
            }

            const lowerSource = source.toLowerCase();
            const lowerTerm = term.toLowerCase();
            const start = lowerSource.indexOf(lowerTerm);

            if (start === -1) {
                return escapeHtml(source);
            }

            const end = start + term.length;

            return `${escapeHtml(source.slice(0, start))}<mark>${escapeHtml(source.slice(start, end))}</mark>${escapeHtml(source.slice(end))}`;
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

            let activeIndex = -1;
            let visibleOptions = [];

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
                activeIndex = -1;
            }

            function syncActiveOption() {
                Array.from(optionsWrap.querySelectorAll('.searchable-select-option')).forEach(function (button, index) {
                    button.classList.toggle('is-active', index === activeIndex);

                    if (index === activeIndex) {
                        button.scrollIntoView({ block: 'nearest' });
                    }
                });
            }

            function renderOptions() {
                const query = searchInput.value.trim().toLowerCase();
                optionsWrap.innerHTML = '';

                visibleOptions = Array.from(select.options).filter(function (option) {
                    if (option.hidden) {
                        return false;
                    }
                    const searchText = (option.getAttribute('data-search') || option.textContent || '').toLowerCase();
                    return !query || searchText.includes(query);
                });

                visibleOptions.forEach(function (option) {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'searchable-select-option' + (option.selected ? ' is-selected' : '');
                    button.innerHTML = highlightMatch(option.textContent.trim(), searchInput.value);
                    button.dataset.value = option.value;
                    button.addEventListener('click', function () {
                        select.value = option.value;
                        select.dispatchEvent(new Event('change', { bubbles: true }));
                        syncTriggerLabel();
                        closePanel();
                        trigger.focus();
                    });
                    optionsWrap.appendChild(button);
                });

                if (visibleOptions.length) {
                    const selectedIndex = visibleOptions.findIndex(function (option) {
                        return option.selected;
                    });
                    activeIndex = selectedIndex >= 0 ? selectedIndex : 0;
                    syncActiveOption();
                } else {
                    activeIndex = -1;
                }

                if (!visibleOptions.length) {
                    const empty = document.createElement('div');
                    empty.className = 'searchable-select-empty';
                    empty.textContent = 'No matching options';
                    optionsWrap.appendChild(empty);
                }
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
            searchInput.addEventListener('keydown', function (event) {
                if (event.key === 'ArrowDown') {
                    event.preventDefault();

                    if (!visibleOptions.length) {
                        return;
                    }

                    activeIndex = activeIndex < visibleOptions.length - 1 ? activeIndex + 1 : 0;
                    syncActiveOption();
                    return;
                }

                if (event.key === 'ArrowUp') {
                    event.preventDefault();

                    if (!visibleOptions.length) {
                        return;
                    }

                    activeIndex = activeIndex > 0 ? activeIndex - 1 : visibleOptions.length - 1;
                    syncActiveOption();
                    return;
                }

                if (event.key === 'Enter') {
                    if (activeIndex < 0 || !visibleOptions[activeIndex]) {
                        return;
                    }

                    event.preventDefault();
                    select.value = visibleOptions[activeIndex].value;
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                    syncTriggerLabel();
                    closePanel();
                    trigger.focus();
                    return;
                }

                if (event.key === 'Escape') {
                    event.preventDefault();
                    closePanel();
                    trigger.focus();
                }
            });
            select.addEventListener('change', refresh);
            trigger.addEventListener('keydown', function (event) {
                if (event.key === 'ArrowDown' || event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    openPanel();
                }
            });

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
        enhanceSearchableSelect(businessPartnerSelect);
        enhanceSearchableSelect(partnerClientSelect);
        enhanceSearchableSelect(productSelect);

        if (businessPartnerSelect?.value && partnerClientSelect) {
            const seededClients = Array.from(partnerClientSelect.options)
                .slice(1)
                .map(function (option) {
                    return {
                        id: option.value,
                        business_partner_id: option.getAttribute('data-business-partner-id') || businessPartnerSelect.value,
                        name: option.getAttribute('data-name') || option.textContent.trim(),
                        phone: option.getAttribute('data-phone') || '',
                        phone_country: option.getAttribute('data-phone-country') || '',
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

        function normalizeIdArray(values) {
            const source = Array.isArray(values) ? values : (values ? [values] : []);

            return Array.from(new Set(source
                .map(function (value) {
                    return parseInt(value || '0', 10);
                })
                .filter(function (value) {
                    return value > 0;
                })));
        }

        function customerMode() {
            return customerTypeSelect?.value === 'business_partner' ? 'business_partner' : 'direct_customer';
        }

        function syncCustomerTypeButtons() {
            const activeMode = customerMode();

            customerTypeButtons.forEach(function (button) {
                const isActive = button.getAttribute('data-customer-type-option') === activeMode;
                button.classList.toggle('is-active', isActive);
                button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            });
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
                alternate_phone: option.getAttribute('data-alternate-phone') || '',
                address: option.getAttribute('data-address') || '',
                city: option.getAttribute('data-city') || '',
                state: option.getAttribute('data-state') || '',
                location: option.getAttribute('data-location') || '',
                delivery_notes: option.getAttribute('data-notes') || '',
            };
        }

        function syncCustomerFields() {
            if (customerMode() !== 'direct_customer') {
                return;
            }

            const selected = customerSelect.options[customerSelect.selectedIndex];

            if (!selected || !customerSelect.value) {
                customerName.value = '';
                phoneInput.value = '';
                return;
            }

            customerName.value = selected.getAttribute('data-name') || '';
            phoneInput.value = selected.getAttribute('data-phone') || '';
            if (phoneCountryCodeInput) {
                phoneCountryCodeInput.value = selected.getAttribute('data-phone-country') || '';
            }
        }

        function partnerClientEndpoint(partnerId) {
            if (!partnerClientEndpointTemplate || !partnerId) {
                return null;
            }

            return partnerClientEndpointTemplate.replace('__PARTNER__', String(partnerId));
        }

        function buildPartnerClientOption(client, isSelected) {
            const option = document.createElement('option');
            option.value = String(client.id);
            option.textContent = [client.name, client.phone].filter(Boolean).join(' • ') || client.name || 'Actual client';
            option.setAttribute('data-business-partner-id', String(client.business_partner_id || ''));
            option.setAttribute('data-name', client.name || '');
            option.setAttribute('data-phone', client.phone || '');
            option.setAttribute('data-phone-country', client.phone_country || '');
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
            if (!partnerClientHelper) {
                return;
            }

            const activePartnerId = businessPartnerSelect?.value || '';

            if (!activePartnerId) {
                partnerClientHelper.hidden = false;
                if (partnerClientHelperTitle) {
                    partnerClientHelperTitle.textContent = 'Select a business partner to continue.';
                }
                if (partnerClientHelperText) {
                    partnerClientHelperText.textContent = 'The actual client will be used for delivery, pickup, and service.';
                }
                return;
            }

            if (partnerClientSelect?.value) {
                partnerClientHelper.hidden = true;
                return;
            }

            partnerClientHelper.hidden = false;
            if (partnerClientHelperTitle) {
                partnerClientHelperTitle.textContent = 'Select or add an actual delivery client.';
            }
            if (partnerClientHelperText) {
                partnerClientHelperText.textContent = message || (visibleClientCount > 0
                    ? 'Choose the delivery or service client for this rental.'
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

        function syncPartnerClientFields() {
            if (customerMode() !== 'business_partner') {
                return;
            }

            const partner = selectedBusinessPartnerData();
            const client = selectedPartnerClientData();

            if (client) {
                customerName.value = client.name || '';
                phoneInput.value = String(client.phone || '').replace(/\D+/g, '').slice(0, 15) || '';
                if (phoneCountryCodeInput) {
                    const clientOption = partnerClientSelect.options[partnerClientSelect.selectedIndex];
                    phoneCountryCodeInput.value = clientOption?.getAttribute('data-phone-country') || '';
                }
                return;
            }

            if (partner) {
                customerName.value = partner.name || '';
                phoneInput.value = String(partner.phone || '').replace(/\D+/g, '').slice(0, 15) || '';
                return;
            }

            customerName.value = '';
            phoneInput.value = '';
        }

        function updateContactUsageSummary() {
            const mode = customerMode();
            const partner = selectedBusinessPartnerData();
            const client = selectedPartnerClientData();
            const customer = customerSelect?.selectedOptions?.[0];
            const customerAddressText = customer
                ? [customer.getAttribute('data-address'), customer.getAttribute('data-city'), customer.getAttribute('data-state')].filter(Boolean).join(', ')
                : '';
            const customerLocation = customer?.getAttribute('data-location') || '';
            const clientAddressText = client
                ? [client.address, client.city, client.state].filter(Boolean).join(', ')
                : '';
            const clientLocation = client?.location || '';

            if (customerFlowBadge) {
                customerFlowBadge.textContent = mode === 'business_partner' ? 'Business Partner' : 'Direct Customer';
            }
            if (customerFlowSummaryTitle) {
                customerFlowSummaryTitle.textContent = mode === 'business_partner' ? 'Contact Routing' : 'Customer Summary';
            }

            if (mode === 'business_partner') {
                const hasPartnerSummary = Boolean(client);

                if (customerContactUsageCard) {
                    customerContactUsageCard.hidden = !hasPartnerSummary;
                }
                if (reminderContactLabel) {
                    reminderContactLabel.textContent = 'Reminder / Payment Contact';
                }
                if (deliveryContactLine) {
                    deliveryContactLine.hidden = false;
                }
                if (deliveryContactLabel) {
                    deliveryContactLabel.textContent = 'Delivery / Pickup Contact';
                }
                if (deliveryAddressLabel) {
                    deliveryAddressLabel.textContent = 'Delivery Address';
                }
                reminderContactSummary.textContent = partner
                    ? [partner.name, partner.phone].filter(Boolean).join(' • ')
                    : 'Select a business partner';
                deliveryContactSummary.textContent = client
                    ? [client.name, client.phone].filter(Boolean).join(' • ')
                    : 'Select an actual client';
                deliveryAddressSummary.textContent = clientAddressText;
                deliveryNotesSummary.textContent = client?.delivery_notes ? 'Notes: ' + client.delivery_notes : '';
                if (deliverySummaryActions) {
                    deliverySummaryActions.hidden = !clientLocation;
                }
                if (deliveryOpenMapLink) {
                    deliveryOpenMapLink.hidden = !clientLocation;
                    deliveryOpenMapLink.href = clientLocation || '#';
                }
                if (partnerClientHelper) {
                    partnerClientHelper.hidden = Boolean(client);
                }

                if (addActualClientLink) {
                    addActualClientLink.classList.toggle('is-disabled', !partner);
                    addActualClientLink.setAttribute('aria-disabled', partner ? 'false' : 'true');
                }

                return;
            }

            const customerNameText = customer?.getAttribute('data-name') || 'Select a customer';
            const customerPhoneText = customer?.getAttribute('data-phone') || '';
            const hasCustomerSummary = Boolean(customer && customerSelect?.value);

            if (customerContactUsageCard) {
                customerContactUsageCard.hidden = !hasCustomerSummary;
            }
            if (reminderContactLabel) {
                reminderContactLabel.textContent = 'Customer';
            }
            if (deliveryContactLine) {
                deliveryContactLine.hidden = true;
            }
            if (deliveryAddressLabel) {
                deliveryAddressLabel.textContent = 'Address';
            }
            reminderContactSummary.textContent = [customerNameText, customerPhoneText].filter(Boolean).join(' • ');
            deliveryContactSummary.textContent = '';
            deliveryAddressSummary.textContent = customerAddressText;
            deliveryNotesSummary.textContent = '';
            if (deliverySummaryActions) {
                deliverySummaryActions.hidden = !customerLocation;
            }
            if (deliveryOpenMapLink) {
                deliveryOpenMapLink.hidden = !customerLocation;
                deliveryOpenMapLink.href = customerLocation || '#';
            }
            if (partnerClientHelper) {
                partnerClientHelper.hidden = true;
            }
            if (addActualClientLink) {
                addActualClientLink.href = '#';
                addActualClientLink.classList.add('is-disabled');
                addActualClientLink.setAttribute('aria-disabled', 'true');
            }
        }

        function updateCustomerModeVisibility() {
            const mode = customerMode();
            const hasPartner = Boolean(businessPartnerSelect?.value);

            customerModeBlocks.forEach(function (block) {
                block.hidden = block.getAttribute('data-customer-mode-block') !== mode;
            });

            if (partnerClientRow) {
                partnerClientRow.hidden = mode !== 'business_partner';
            }

            if (customerSelect) {
                customerSelect.required = mode === 'direct_customer';
            }
            if (businessPartnerSelect) {
                businessPartnerSelect.required = mode === 'business_partner';
            }
            if (partnerClientSelect) {
                partnerClientSelect.required = mode === 'business_partner' && hasPartner;
            }

            syncCustomerTypeButtons();
            renderPartnerClientOptions();
            if (mode === 'direct_customer') {
                syncCustomerFields();
            } else {
                syncPartnerClientFields();
            }
            updateContactUsageSummary();
        }

        function syncThirdPartyDeliveryFields() {
            if (!deliveryAssignmentSelect || !thirdPartyDeliveryFields) {
                return;
            }

            thirdPartyDeliveryFields.style.display = deliveryAssignmentSelect.value === 'third_party' ? '' : 'none';
        }

        function updateWarehouseMetric() {
            const selected = warehouseSelect.options[warehouseSelect.selectedIndex];
            warehouseMetric.textContent = warehouseSelect.value ? selected.textContent.trim() : 'Any warehouse';
        }

        function parseRentalWarehouseQuantities(selected) {
            if (!selected) {
                return {};
            }

            try {
                return JSON.parse(selected.getAttribute('data-rental-warehouse-quantities') || '{}');
            } catch (error) {
                return {};
            }
        }

        function selectedPrimaryRentalState() {
            const selected = productSelect.options[productSelect.selectedIndex];

            if (!selected || !productSelect.value) {
                return null;
            }

            let available = parseInt(selected.getAttribute('data-rental-available') || selected.getAttribute('data-available') || '0', 10);
            let status = selected.getAttribute('data-rental-status') || 'no_rental_assets';
            let label = selected.getAttribute('data-rental-label') || '';
            const warehouseQuantities = parseRentalWarehouseQuantities(selected);
            const tracksRental = selected.getAttribute('data-tracks-rental') === '1';

            if (warehouseSelect.value && tracksRental && status !== 'sale_only') {
                available = parseInt(warehouseQuantities[warehouseSelect.value] || '0', 10);
                status = available > 0 ? 'rental_available' : 'no_rental_assets';
                label = available > 0 ? 'Rental Available ' + available : 'No rental assets available';
            }

            if (tracksRental && status !== 'sale_only' && primaryAvailabilityLoadedFor === productSelect.value) {
                available = currentAssets.length;
                status = available > 0 ? 'rental_available' : 'no_rental_assets';
                label = available > 0 ? 'Rental Available ' + available : 'No rental assets available';
            }

            return {
                name: selected.getAttribute('data-product-name') || '',
                available: Number.isNaN(available) ? 0 : available,
                status: status,
                label: label || 'No rental assets available'
            };
        }

        function updateAvailabilityHint() {
            const state = selectedPrimaryRentalState();

            if (!state) {
                availabilityHint.textContent = 'Rental availability will show here.';
                return;
            }

            if (state.status === 'sale_only') {
                availabilityHint.textContent = 'Sale only. Not available for rental.';
                return;
            }

            availabilityHint.textContent = warehouseSelect.value
                ? state.label + ' in selected warehouse.'
                : state.label + '.';
        }

        function updatePrimaryRentalFeedback() {
            const state = selectedPrimaryRentalState();
            const requestedQuantity = Math.max(parseInt(quantityInput.value || '0', 10), 0);
            let warningText = '';
            let disableSubmit = false;

            if (!state) {
                productRentalWarning.style.display = 'none';
                productRentalWarning.textContent = '';
                rentalSaveGuidance.textContent = 'Match rental asset count with quantity.';
                rentalSubmitButton.disabled = false;
                return;
            }

            if (state.status === 'sale_only' || state.available <= 0) {
                warningText = unavailableRentalMessage;
                disableSubmit = true;
            } else if (requestedQuantity > state.available) {
                warningText = 'Only ' + state.available + ' rental asset(s) are available for this product' + (warehouseSelect.value ? ' in the selected warehouse.' : '.');
                disableSubmit = true;
            }

            productRentalWarning.textContent = warningText;
            productRentalWarning.style.display = warningText ? 'block' : 'none';
            rentalSaveGuidance.textContent = warningText || 'Match rental asset count with quantity.';
            rentalSubmitButton.disabled = disableSubmit;
        }

        function selectedProductPricing() {
            const selected = productSelect.options[productSelect.selectedIndex];

            if (!selected || !productSelect.value) {
                return null;
            }

            return {
                perDay: parseFloat(selected.getAttribute('data-price-per-day') || '0'),
                fallback: parseFloat(selected.getAttribute('data-rental-price') || '0'),
                days15: parseFloat(selected.getAttribute('data-rental-price-15') || '0'),
                days30: parseFloat(selected.getAttribute('data-rental-price-30') || '0'),
                days90: parseFloat(selected.getAttribute('data-rental-price-90') || '0')
            };
        }

        function normalizeStateName(value) {
            const normalized = String(value || '').trim().toLowerCase();
            return normalized || null;
        }

        function recommendedTaxType() {
            const customerState = customerSelect?.selectedOptions?.[0]?.getAttribute('data-state') || '';
            const effectiveCustomerState = customerMode() === 'business_partner'
                ? (selectedBusinessPartnerData()?.billing_state || selectedPartnerClientData()?.state || selectedBusinessPartnerData()?.state || '')
                : customerState;
            const normalizedCustomerState = normalizeStateName(effectiveCustomerState);
            const normalizedOrganizationState = normalizeStateName(organizationState);

            if (normalizedCustomerState && normalizedOrganizationState && normalizedCustomerState !== normalizedOrganizationState) {
                return 'igst';
            }

            return 'cgst_sgst';
        }

        function selectedProductTaxDefaults(selectEl) {
            const selected = selectEl?.options?.[selectEl.selectedIndex];

            if (!selected || !selectEl.value) {
                return {
                    gstRate: '0.00',
                    gstMode: 'exclusive',
                    taxType: recommendedTaxType(),
                };
            }

            return {
                gstRate: normalizeMoney(selected.getAttribute('data-gst-rate') || '0'),
                gstMode: selected.getAttribute('data-gst-mode') === 'inclusive' ? 'inclusive' : 'exclusive',
                taxType: recommendedTaxType(),
            };
        }

        function calculateTaxedLineTotal(quantityValue, unitValue, gstRateValue, gstModeValue) {
            const quantity = Math.max(parseFloat(quantityValue || 0), 0);
            const unitPrice = Math.max(parseFloat(unitValue || 0), 0);
            const gstRate = Math.max(parseFloat(gstRateValue || 0), 0);
            const gstMode = gstModeValue === 'inclusive' ? 'inclusive' : 'exclusive';
            const subtotal = quantity * unitPrice;

            if (gstMode === 'inclusive' || gstRate <= 0) {
                return subtotal.toFixed(2);
            }

            return (subtotal + (subtotal * (gstRate / 100))).toFixed(2);
        }

        function syncPrimaryTaxDefaults(forceOverride) {
            if (!primaryGstRateInput || !primaryGstModeSelect || !primaryTaxTypeSelect) {
                return;
            }

            const defaults = selectedProductTaxDefaults(productSelect);

            if (forceOverride || !primaryGstRateInput.value) {
                primaryGstRateInput.value = defaults.gstRate;
            }

            if (forceOverride || !primaryGstModeSelect.value) {
                primaryGstModeSelect.value = defaults.gstMode;
            }

            if (forceOverride || !primaryTaxTypeSelect.value) {
                primaryTaxTypeSelect.value = defaults.taxType;
            }
        }

        function activeRentalDays() {
            if (durationPresetSelect.value === '7_days') {
                return 7;
            }

            if (durationPresetSelect.value === '15_days') {
                return 15;
            }

            if (durationPresetSelect.value === '30_days') {
                return 30;
            }

            if (durationPresetSelect.value === '3_months') {
                return 90;
            }

            if (durationPresetSelect.value === '6_months') {
                return 180;
            }

            if (!startDateInput.value || !endDateInput.value) {
                return 0;
            }

            const startDate = new Date(startDateInput.value + 'T00:00:00');
            const endDate = new Date(endDateInput.value + 'T00:00:00');

            if (Number.isNaN(startDate.getTime()) || Number.isNaN(endDate.getTime()) || endDate < startDate) {
                return 0;
            }

            const millisecondsPerDay = 24 * 60 * 60 * 1000;

            return Math.floor((endDate.getTime() - startDate.getTime()) / millisecondsPerDay) + 1;
        }

        function defaultRentalUnitPrice() {
            const pricing = selectedProductPricing();

            if (!pricing) {
                return 0;
            }

            const days = activeRentalDays();
            const preset = durationPresetSelect?.value || 'custom';

            if (days === 15 && pricing.days15 > 0) {
                return pricing.days15;
            }

            if (days === 30 && (pricing.days30 > 0 || pricing.fallback > 0)) {
                return pricing.days30 > 0 ? pricing.days30 : pricing.fallback;
            }

            if (days === 90 && pricing.days90 > 0) {
                return pricing.days90;
            }

            if (days === 180) {
                if (pricing.days90 > 0) {
                    return pricing.days90 * 2;
                }

                if (pricing.fallback > 0) {
                    return pricing.fallback * 2;
                }

                if (pricing.perDay > 0) {
                    return pricing.perDay * 180;
                }
            }

            if (preset !== 'custom') {
                if (pricing.fallback > 0) {
                    return pricing.fallback;
                }

                if (pricing.perDay > 0) {
                    return pricing.perDay;
                }
            }

            if (days > 0 && pricing.perDay > 0) {
                return pricing.perDay * days;
            }

            if (pricing.days30 > 0) {
                return pricing.days30;
            }

            if (pricing.fallback > 0) {
                return pricing.fallback;
            }

            return pricing.perDay > 0 && days > 0 ? pricing.perDay * days : pricing.perDay;
        }

        function syncPrimaryRentalAmount(forceUpdate) {
            if (!rentalAmountInput) {
                return;
            }

            if (!forceUpdate && rentalAmountTouched) {
                return;
            }

            const quantity = Math.max(parseInt(quantityInput.value || '0', 10), 0);
            const unitPrice = defaultRentalUnitPrice();
            const lineTotal = unitPrice > 0 && quantity > 0 ? unitPrice * quantity : 0;

            rentalAmountInput.value = lineTotal > 0 ? lineTotal.toFixed(2) : '0';
        }

        function handlePrimaryProductChange() {
            rentalAmountTouched = false;
            primaryAvailabilityLoadedFor = null;
            syncPrimaryTaxDefaults(true);
            updateAvailabilityHint();
            updatePrimaryRentalFeedback();
            fetchAssets();
            syncPrimaryRentalAmount(true);

            window.requestAnimationFrame(function () {
                syncPrimaryRentalAmount(true);
            });
        }

        function getFilteredAssets() {
            const term = (assetSearch.value || '').trim().toLowerCase();

            return currentAssets.filter(function (asset) {
                return !term || [
                    asset.label,
                    asset.serial_number,
                    asset.barcode_value,
                    asset.warehouse,
                    asset.condition_status,
                    asset.asset_status
                ].join(' ').toLowerCase().includes(term);
            });
        }

        function findSaleAsset(assetId) {
            const normalizedId = parseInt(assetId || '0', 10);
            return saleAssetOptions.find(function (asset) {
                return asset.id === normalizedId;
            }) || null;
        }

        function availableSaleAssetsForItem(item) {
            const selectedAssetId = parseInt(item.asset_id || '0', 10);
            const selectedProductId = parseInt(item.product_id || '0', 10);
            const selectedWarehouseId = parseInt(item.warehouse_id || '0', 10);

            return saleAssetOptions.filter(function (asset) {
                if (selectedAssetId && asset.id === selectedAssetId) {
                    return true;
                }

                if (selectedProductId && parseInt(asset.product_id || '0', 10) !== selectedProductId) {
                    return false;
                }

                if (selectedWarehouseId && parseInt(asset.warehouse_id || '0', 10) !== selectedWarehouseId) {
                    return false;
                }

                return true;
            });
        }

        function buildSaleAssetOptions(item) {
            const options = ['<option value="">No serialized asset link</option>'];

            availableSaleAssetsForItem(item).forEach(function (asset) {
                options.push(`<option value="${asset.id}" ${parseInt(item.asset_id || '0', 10) === asset.id ? 'selected' : ''}>${asset.label}</option>`);
            });

            return options.join('');
        }

        function updateSelectionMetrics() {
            const quantity = parseInt(quantityInput.value || '0', 10);
            const selectedCount = selectedAssetIds.length;
            const hasAssets = currentAssets.length > 0;

            quantityMetric.textContent = quantity || 0;
            selectedAssetCount.textContent = selectedCount;
            assetRequiredCount.textContent = quantity || 0;
            assetSelectedCountInline.textContent = selectedCount;
            assetSelectedLabel.textContent = selectedCount + ' chosen';

            if (!productSelect.value) {
                assetSelectionStatus.textContent = 'Select product';
                assetSelectionHelp.textContent = 'Load assets from product.';
                selectionAlert.style.display = 'none';
                return;
            }

            if (selectedCount === 0) {
                assetSelectionStatus.textContent = 'Select asset';
                assetSelectionHelp.textContent = hasAssets
                    ? 'Pick from the list below.'
                    : 'No assets available.';

                if (hasAssets) {
                    selectionAlert.style.display = 'block';
                    selectionAlert.textContent = 'Select asset before save.';
                } else {
                    selectionAlert.style.display = 'none';
                }

                return;
            }

            if (quantity && selectedCount !== quantity) {
                assetSelectionStatus.textContent = 'Qty mismatch';
                assetSelectionHelp.textContent = selectedCount + ' of ' + quantity + ' selected.';
                selectionAlert.style.display = 'block';
                selectionAlert.textContent = 'Selected ' + selectedCount + ', required ' + quantity + '.';
            } else {
                assetSelectionStatus.textContent = 'Ready';
                assetSelectionHelp.textContent = 'Assets will be linked.';
                selectionAlert.style.display = 'none';
            }
        }

        function syncDurationPreset() {
            if (!startDateInput.value || !durationPresetSelect.value || durationPresetSelect.value === 'custom') {
                syncPrimaryRentalAmount(false);
                return;
            }

            const startDate = new Date(startDateInput.value + 'T00:00:00');

            if (Number.isNaN(startDate.getTime())) {
                return;
            }

            const endDate = new Date(startDate.getTime());

            switch (durationPresetSelect.value) {
                case '7_days':
                    endDate.setDate(endDate.getDate() + 6);
                    break;
                case '15_days':
                    endDate.setDate(endDate.getDate() + 14);
                    break;
                case '30_days':
                    endDate.setDate(endDate.getDate() + 29);
                    break;
                case '3_months':
                    endDate.setMonth(endDate.getMonth() + 3);
                    endDate.setDate(endDate.getDate() - 1);
                    break;
                case '6_months':
                    endDate.setMonth(endDate.getMonth() + 6);
                    endDate.setDate(endDate.getDate() - 1);
                    break;
                default:
                    return;
            }

            const year = endDate.getFullYear();
            const month = String(endDate.getMonth() + 1).padStart(2, '0');
            const day = String(endDate.getDate()).padStart(2, '0');

            endDateInput.value = `${year}-${month}-${day}`;
            syncPrimaryRentalAmount(false);
        }

        function getAssetCardLabel(asset) {
            return asset.label || asset.serial_number;
        }

        function toggleAssetSelection(assetId) {
            if (!selectedAssetIds.includes(assetId)) {
                selectedAssetIds.push(assetId);
            } else {
                selectedAssetIds = selectedAssetIds.filter(function (selectedId) {
                    return selectedId !== assetId;
                });
            }

            renderAssets();
            updateSelectionMetrics();
            renderRentalItems();
        }

        function renderAssets() {
            assetGrid.innerHTML = '';

            const filtered = getFilteredAssets();
            const prioritizedAssets = filtered.slice().sort(function (left, right) {
                return Number(selectedAssetIds.includes(right.id)) - Number(selectedAssetIds.includes(left.id));
            });
            const displayCount = Math.max(visibleAssetCount, selectedAssetIds.length);
            const visibleAssets = prioritizedAssets.slice(0, displayCount);

            if (!productSelect.value) {
                assetEmptyState.style.display = 'block';
                assetEmptyState.textContent = 'Select product';
                assetLoadMoreWrap.style.display = 'none';
                return;
            }

            if (!filtered.length) {
                assetEmptyState.style.display = 'block';
                assetEmptyState.textContent = currentAssets.length
                    ? 'No match found.'
                    : 'No assets available.';
                assetLoadMoreWrap.style.display = 'none';
                return;
            }

            assetEmptyState.style.display = 'none';

            visibleAssets.forEach(function (asset) {
                const isSelected = selectedAssetIds.includes(asset.id);
                const card = document.createElement('label');
                card.className = 'asset-card' + (isSelected ? ' is-selected' : '');
                card.setAttribute('role', 'button');
                card.setAttribute('tabindex', '0');
                card.setAttribute('aria-pressed', isSelected ? 'true' : 'false');
                card.innerHTML = `
                    <input type="checkbox" name="asset_ids[]" value="${asset.id}" ${isSelected ? 'checked' : ''}>
                    <div class="asset-card-head">
                        <strong>${getAssetCardLabel(asset)}</strong>
                        <span class="asset-card-pill${isSelected ? ' is-selected' : ''}">${isSelected ? 'Assigned' : 'Select'}</span>
                    </div>
                    <small>Serial: ${asset.serial_number || '-'}</small>
                    <small>Barcode: ${asset.barcode_value || '-'}</small>
                    <small>Warehouse: ${asset.warehouse || 'Not set'}</small>
                    <small>Status: ${asset.asset_status || '-'} | Condition: ${asset.condition_status || '-'}</small>
                `;

                card.addEventListener('click', function (event) {
                    event.preventDefault();
                    toggleAssetSelection(asset.id);
                });

                card.addEventListener('keydown', function (event) {
                    if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        toggleAssetSelection(asset.id);
                    }
                });

                assetGrid.appendChild(card);
            });

            assetCountNote.textContent = filtered.length + ' asset(s). Showing ' + visibleAssets.length + '.';

            if (prioritizedAssets.length > visibleAssets.length) {
                assetLoadMoreWrap.style.display = 'flex';
                assetLoadMoreButton.textContent = 'Load More (' + (prioritizedAssets.length - visibleAssets.length) + ' remaining)';
            } else {
                assetLoadMoreWrap.style.display = 'none';
            }
        }

        function fetchAssets() {
            currentAssets = [];
            primaryAvailabilityLoadedFor = null;
            visibleAssetCount = assetVisibleStep;
            renderAssets();
            updateAvailabilityHint();
            updatePrimaryRentalFeedback();

            if (!productSelect.value) {
                assetCountNote.textContent = 'Select product to load assets.';
                return;
            }

            assetEmptyState.style.display = 'block';
            assetEmptyState.textContent = 'Loading assets...';

            const params = new URLSearchParams({
                product_id: productSelect.value
            });

            if (warehouseSelect.value) {
                params.append('dispatch_warehouse_id', warehouseSelect.value);
            }

            @if($isEdit)
                params.append('rental_id', '{{ $rental->id }}');
            @endif

            fetch('{{ route('rentals.available-assets') }}?' + params.toString(), {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('Unable to load assets');
                    }

                    return response.json();
                })
                .then(function (payload) {
                    primaryAvailabilityLoadedFor = productSelect.value;
                    currentAssets = Array.isArray(payload.data) ? payload.data : [];
                    selectedAssetIds = selectedAssetIds.filter(function (assetId) {
                        return currentAssets.some(function (asset) {
                            return asset.id === assetId;
                        });
                    });
                    updateAvailabilityHint();
                    updatePrimaryRentalFeedback();
                    renderAssets();
                    updateSelectionMetrics();
                    renderRentalItems();
                })
                .catch(function () {
                    primaryAvailabilityLoadedFor = productSelect.value;
                    currentAssets = [];
                    assetGrid.innerHTML = '';
                    assetEmptyState.style.display = 'block';
                    assetEmptyState.textContent = 'Unable to load assets.';
                    assetCountNote.textContent = 'Load failed.';
                    updateAvailabilityHint();
                    updatePrimaryRentalFeedback();
                });
        }

        function saleItemLineTotal(item) {
            return calculateTaxedLineTotal(item.quantity, item.unit_price, item.gst_rate, item.gst_mode);
        }

        function rentalItemLineTotal(item) {
            return calculateTaxedLineTotal(item.quantity, item.unit_rental_amount, item.gst_rate, item.gst_mode);
        }

        function rentalItemAssetCacheKey(productId) {
            return [parseInt(productId || '0', 10), parseInt(warehouseSelect.value || '0', 10), parseInt(currentRentalId || '0', 10)].join(':');
        }

        function selectedAdditionalRentalAssetIds(excludeIndex) {
            const selectedIds = [];

            rentalItems.forEach(function (item, index) {
                if (index === excludeIndex) {
                    return;
                }

                normalizeIdArray(item.asset_ids).forEach(function (assetId) {
                    selectedIds.push(assetId);
                });
            });

            selectedAssetIds.forEach(function (assetId) {
                selectedIds.push(parseInt(assetId, 10));
            });

            return Array.from(new Set(selectedIds.filter(function (assetId) {
                return assetId > 0;
            })));
        }

        function ensureInlineAssetState(item) {
            if (typeof item.assetSearch !== 'string') {
                item.assetSearch = '';
            }

            if (typeof item.assetVisibleCount !== 'number' || item.assetVisibleCount < inlineAssetVisibleStep) {
                item.assetVisibleCount = inlineAssetVisibleStep;
            }
        }

        function ensureRentalItemAssetsLoaded(item) {
            if (!item.product_id) {
                return;
            }

            const cacheKey = rentalItemAssetCacheKey(item.product_id);

            if (rentalItemAssetCache[cacheKey] || rentalItemAssetRequests[cacheKey]) {
                return;
            }

            const params = new URLSearchParams({
                product_id: String(item.product_id)
            });

            if (warehouseSelect.value) {
                params.append('dispatch_warehouse_id', warehouseSelect.value);
            }

            if (currentRentalId) {
                params.append('rental_id', String(currentRentalId));
            }

            rentalItemAssetRequests[cacheKey] = fetch('{{ route('rentals.available-assets') }}?' + params.toString(), {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('Unable to load assets');
                    }

                    return response.json();
                })
                .then(function (payload) {
                    rentalItemAssetCache[cacheKey] = Array.isArray(payload.data) ? payload.data : [];
                    delete rentalItemAssetRequests[cacheKey];
                    renderRentalItems();
                })
                .catch(function () {
                    rentalItemAssetCache[cacheKey] = [];
                    delete rentalItemAssetRequests[cacheKey];
                    renderRentalItems();
                });
        }

        function rentalItemAssetChoices(item, index) {
            const cacheKey = rentalItemAssetCacheKey(item.product_id);
            const cachedAssets = rentalItemAssetCache[cacheKey] || [];
            const selectedIds = normalizeIdArray(item.asset_ids);
            const usedElsewhere = selectedAdditionalRentalAssetIds(index);

            return cachedAssets.filter(function (asset) {
                return selectedIds.includes(asset.id) || !usedElsewhere.includes(asset.id);
            });
        }

        function renderRentalItems() {
            rentalItemsList.innerHTML = '';

            if (!rentalItems.length) {
                rentalItemsList.appendChild(rentalItemEmptyState);
                rentalItemEmptyState.style.display = 'block';
                rentalItemsCount.textContent = '0';
                rentalItemsCountSummary.textContent = '0';
                rentalItemsGrandTotal.textContent = '0.00';
                rentalItemsGrandTotalSummary.textContent = '0.00';
                return;
            }

            rentalItemEmptyState.style.display = 'none';

            let total = 0;

            rentalItems.forEach(function (item, index) {
                if (typeof item.expanded !== 'boolean') {
                    item.expanded = Boolean((item.notes && item.notes.trim()) || normalizeIdArray(item.asset_ids).length);
                }
                ensureInlineAssetState(item);

                ensureRentalItemAssetsLoaded(item);

                const selectedIds = normalizeIdArray(item.asset_ids);
                const cacheKey = rentalItemAssetCacheKey(item.product_id);
                const hasCachedAssets = Object.prototype.hasOwnProperty.call(rentalItemAssetCache, cacheKey);
                const cachedAssets = rentalItemAssetCache[cacheKey] || [];
                const cachedAssetIds = cachedAssets.map(function (asset) {
                    return asset.id;
                });

                if (hasCachedAssets && selectedIds.some(function (assetId) {
                    return !cachedAssetIds.includes(assetId);
                })) {
                    rentalItems[index].asset_ids = selectedIds.filter(function (assetId) {
                        return cachedAssetIds.includes(assetId);
                    });
                    renderRentalItems();
                    return;
                }

                const assetChoices = rentalItemAssetChoices(item, index);
                const filteredAssetChoices = assetChoices
                    .filter(function (asset) {
                        const term = (item.assetSearch || '').trim().toLowerCase();

                        if (!term) {
                            return true;
                        }

                        return [
                            asset.label,
                            asset.serial_number,
                            asset.barcode_value,
                            asset.warehouse,
                        ].join(' ').toLowerCase().includes(term);
                    })
                    .sort(function (left, right) {
                        return Number(selectedIds.includes(right.id)) - Number(selectedIds.includes(left.id));
                    });
                const visibleAssets = filteredAssetChoices.slice(0, Math.max(item.assetVisibleCount, selectedIds.length));
                const isLoadingAssets = Boolean(item.product_id && rentalItemAssetRequests[cacheKey]);
                const quantity = Math.max(parseInt(item.quantity || '0', 10), 0);
                const toggleLabel = item.expanded
                    ? 'Hide Details'
                    : (selectedIds.length ? 'Show Assets' : (item.notes ? 'Show Note' : 'Assets / Note'));
                const assetHelpText = !item.product_id
                    ? 'Select product first.'
                    : (isLoadingAssets
                        ? 'Loading assets...'
                        : (assetChoices.length
                            ? (selectedIds.length && selectedIds.length !== quantity
                                ? 'Selected ' + selectedIds.length + ' of ' + quantity + '.'
                                : assetChoices.length + ' asset(s) available.')
                            : 'No matching assets.'));
                const entry = document.createElement('div');
                entry.className = 'sale-item-entry';
                const row = document.createElement('div');
                row.className = 'sale-item-row';
                row.innerHTML = `
                    <div>
                        <select name="rental_items[${index}][product_id]" data-rental-product-index="${index}" data-searchable-select data-search-placeholder="Search rental product by name, brand, model, SKU, or code">
                            ${rentalProductOptionsHtml}
                        </select>
                    </div>
                    <div>
                        <input type="number" min="1" name="rental_items[${index}][quantity]" value="${item.quantity || 1}" data-rental-quantity-index="${index}">
                    </div>
                    <div>
                        <input type="number" step="0.01" min="0" name="rental_items[${index}][unit_rental_amount]" value="${item.unit_rental_amount || ''}" data-rental-price-index="${index}">
                    </div>
                    <div class="sale-item-total">Total ${rentalItemLineTotal(item)}</div>
                    <div class="sale-item-actions">
                        <button type="button" class="ops-button-secondary" data-toggle-rental-item="${index}">${toggleLabel}</button>
                        <button type="button" class="ops-button-secondary" data-remove-rental-item="${index}">Remove</button>
                    </div>
                `;
                entry.appendChild(row);

                const detail = document.createElement('div');
                detail.className = 'sale-item-detail';
                detail.hidden = !item.expanded;
                detail.innerHTML = `
                    <div class="sale-item-detail-grid">
                        <div class="sale-item-detail-field">
                            <label>Assets</label>
                            <input type="text" class="line-asset-search" placeholder="Search asset" value="${item.assetSearch || ''}" data-rental-asset-search="${index}">
                            <div class="rental-line-asset-picker" data-rental-asset-picker="${index}">
                                ${visibleAssets.length
                                    ? visibleAssets.map(function (asset) {
                                        return `<label class="rental-line-asset-option"><input type="checkbox" name="rental_items[${index}][asset_ids][]" value="${asset.id}" data-rental-asset-input="${index}" ${selectedIds.includes(asset.id) ? 'checked' : ''}><span>${asset.label}</span></label>`;
                                    }).join('')
                                    : `<div class="rental-line-asset-empty">${item.product_id ? (isLoadingAssets ? 'Loading assets...' : 'No assets available') : 'Select product first'}</div>`}
                            </div>
                            ${filteredAssetChoices.length > visibleAssets.length
                                ? `<div class="line-asset-load-more"><button type="button" class="ops-button-secondary" data-rental-asset-load-more="${index}">Load More</button></div>`
                                : ''}
                            <div class="sale-item-subnote">${assetHelpText}</div>
                        </div>
                        <div class="sale-item-detail-field">
                            <label>GST %</label>
                            <select name="rental_items[${index}][gst_rate]" data-rental-gst-rate="${index}">
                                ${gstOptionsHtml(item.gst_rate || '0.00')}
                            </select>
                        </div>
                        <div class="sale-item-detail-field">
                            <label>GST Mode</label>
                            <select name="rental_items[${index}][gst_mode]" data-rental-gst-mode="${index}">
                                <option value="exclusive"${item.gst_mode === 'inclusive' ? '' : ' selected'}>Exclusive</option>
                                <option value="inclusive"${item.gst_mode === 'inclusive' ? ' selected' : ''}>Inclusive</option>
                            </select>
                        </div>
                        <div class="sale-item-detail-field">
                            <label>Tax Type</label>
                            <select name="rental_items[${index}][tax_type]" data-rental-tax-type="${index}">
                                <option value="cgst_sgst"${item.tax_type === 'igst' ? '' : ' selected'}>CGST + SGST</option>
                                <option value="igst"${item.tax_type === 'igst' ? ' selected' : ''}>IGST</option>
                            </select>
                        </div>
                        <div class="sale-item-detail-field">
                            <label>Notes</label>
                            <textarea name="rental_items[${index}][notes]" placeholder="Optional note">${item.notes || ''}</textarea>
                        </div>
                    </div>
                `;
                entry.appendChild(detail);

                rentalItemsList.appendChild(entry);

                const productSelectEl = row.querySelector(`[data-rental-product-index="${index}"]`);
                const quantityInputEl = row.querySelector(`[data-rental-quantity-index="${index}"]`);
                const priceInputEl = row.querySelector(`[data-rental-price-index="${index}"]`);
                const assetSearchEl = detail.querySelector(`[data-rental-asset-search="${index}"]`);
                const assetCheckboxes = detail.querySelectorAll(`[data-rental-asset-input="${index}"]`);
                const assetLoadMoreButton = detail.querySelector(`[data-rental-asset-load-more="${index}"]`);
                const gstRateInputEl = detail.querySelector(`[data-rental-gst-rate="${index}"]`);
                const gstModeSelectEl = detail.querySelector(`[data-rental-gst-mode="${index}"]`);
                const taxTypeSelectEl = detail.querySelector(`[data-rental-tax-type="${index}"]`);
                const notesInputEl = detail.querySelector(`textarea[name="rental_items[${index}][notes]"]`);
                const toggleButton = row.querySelector(`[data-toggle-rental-item="${index}"]`);
                const removeButton = row.querySelector(`[data-remove-rental-item="${index}"]`);

                productSelectEl.value = item.product_id || '';
                enhanceSearchableSelect(productSelectEl);

                productSelectEl.addEventListener('change', function () {
                    rentalItems[index].product_id = this.value ? parseInt(this.value, 10) : null;
                    rentalItems[index].asset_ids = [];
                    const defaults = selectedProductTaxDefaults(this);
                    rentalItems[index].gst_rate = defaults.gstRate;
                    rentalItems[index].gst_mode = defaults.gstMode;
                    rentalItems[index].tax_type = defaults.taxType;

                    if (!priceInputEl.value) {
                        const selected = this.options[this.selectedIndex];
                        const defaultPrice = selected ? parseFloat(selected.getAttribute('data-default-price') || '0') : 0;
                        rentalItems[index].unit_rental_amount = defaultPrice ? defaultPrice.toFixed(2) : '';
                    }

                    renderRentalItems();
                });

                quantityInputEl.addEventListener('input', function () {
                    rentalItems[index].quantity = Math.max(parseInt(this.value || '0', 10), 0);
                    renderRentalItems();
                });

                priceInputEl.addEventListener('input', function () {
                    rentalItems[index].unit_rental_amount = this.value || '';
                    renderRentalItems();
                });

                gstRateInputEl.addEventListener('change', function () {
                    rentalItems[index].gst_rate = normalizeMoney(this.value);
                    renderRentalItems();
                });

                gstModeSelectEl.addEventListener('change', function () {
                    rentalItems[index].gst_mode = this.value === 'inclusive' ? 'inclusive' : 'exclusive';
                    renderRentalItems();
                });

                taxTypeSelectEl.addEventListener('change', function () {
                    rentalItems[index].tax_type = this.value === 'igst' ? 'igst' : 'cgst_sgst';
                    renderRentalItems();
                });

                assetSearchEl.addEventListener('input', function () {
                    rentalItems[index].assetSearch = this.value || '';
                    rentalItems[index].assetVisibleCount = inlineAssetVisibleStep;
                    renderRentalItems();
                });

                assetCheckboxes.forEach(function (checkbox) {
                    checkbox.addEventListener('change', function () {
                        rentalItems[index].asset_ids = Array.from(detail.querySelectorAll(`[data-rental-asset-input="${index}"]:checked`))
                            .map(function (option) {
                                return parseInt(option.value || '0', 10);
                            })
                            .filter(function (value) {
                                return value > 0;
                            });
                        renderRentalItems();
                    });
                });

                if (assetLoadMoreButton) {
                    assetLoadMoreButton.addEventListener('click', function () {
                        rentalItems[index].assetVisibleCount += inlineAssetVisibleStep;
                        renderRentalItems();
                    });
                }

                notesInputEl.addEventListener('input', function () {
                    rentalItems[index].notes = this.value || '';
                });

                toggleButton.addEventListener('click', function () {
                    rentalItems[index].expanded = !rentalItems[index].expanded;
                    renderRentalItems();
                });

                removeButton.addEventListener('click', function () {
                    rentalItems.splice(index, 1);
                    renderRentalItems();
                });

                total += parseFloat(rentalItemLineTotal(item));
            });

            rentalItemsCount.textContent = String(rentalItems.length);
            rentalItemsCountSummary.textContent = String(rentalItems.length);
            rentalItemsGrandTotal.textContent = total.toFixed(2);
            rentalItemsGrandTotalSummary.textContent = total.toFixed(2);
        }

        function renderSaleItems() {
            saleItemsList.innerHTML = '';

            if (!saleItems.length) {
                saleItemsList.appendChild(saleItemEmptyState);
                saleItemEmptyState.style.display = 'block';
                saleItemsCount.textContent = '0';
                saleItemsCountSummary.textContent = '0';
                saleItemsGrandTotal.textContent = '0.00';
                saleItemsGrandTotalSummary.textContent = '0.00';
                return;
            }

            saleItemEmptyState.style.display = 'none';

            let total = 0;

            saleItems.forEach(function (item, index) {
                if (typeof item.expanded !== 'boolean') {
                    item.expanded = Boolean(item.asset_id || item.notes);
                }
                ensureInlineAssetState(item);

                const linkedAsset = findSaleAsset(item.asset_id);

                if (linkedAsset) {
                    item.quantity = 1;
                }

                const assetChoices = availableSaleAssetsForItem(item);
                const filteredAssetChoices = assetChoices
                    .filter(function (asset) {
                        const term = (item.assetSearch || '').trim().toLowerCase();

                        if (!term) {
                            return true;
                        }

                        return [
                            asset.label,
                            asset.serial_number,
                            asset.barcode_value,
                            asset.warehouse,
                        ].join(' ').toLowerCase().includes(term);
                    })
                    .sort(function (left, right) {
                        return Number(parseInt(item.asset_id || '0', 10) === right.id) - Number(parseInt(item.asset_id || '0', 10) === left.id);
                    });
                const visibleAssets = filteredAssetChoices.slice(0, Math.max(item.assetVisibleCount, item.asset_id ? 1 : 0));
                const toggleLabel = item.expanded
                    ? 'Hide Details'
                    : (linkedAsset
                        ? 'Show Linked Asset'
                        : (item.notes ? 'Show Note' : 'Add Asset / Note'));
                const assetHelpText = linkedAsset
                    ? 'Serialized asset linked. Quantity is fixed to 1 and warehouse follows the asset.'
                    : (item.product_id
                        ? (assetChoices.length ? 'Optional for serialized new-stock units.' : 'No serialized assets available for this product and warehouse yet.')
                        : 'Select new product first to see matching serialized assets.');
                const entry = document.createElement('div');
                entry.className = 'sale-item-entry';
                const row = document.createElement('div');
                row.className = 'sale-item-row';
                row.innerHTML = `
                    <div>
                        <select name="sale_items[${index}][product_id]" data-sale-product-index="${index}" data-searchable-select data-search-placeholder="Search new product by name, brand, model, SKU, or code">
                            ${saleProductOptionsHtml}
                        </select>
                    </div>
                    <div>
                        <select name="sale_items[${index}][warehouse_id]" data-sale-warehouse-index="${index}">
                            ${warehouseOptionsHtml}
                        </select>
                    </div>
                    <div>
                        <input type="number" min="1" name="sale_items[${index}][quantity]" value="${item.quantity || 1}" data-sale-quantity-index="${index}" ${linkedAsset ? 'readonly' : ''}>
                    </div>
                    <div>
                        <input type="number" step="0.01" min="0" name="sale_items[${index}][unit_price]" value="${item.unit_price || ''}" data-sale-price-index="${index}">
                        <div class="sale-item-total">Total ${saleItemLineTotal(item)}</div>
                    </div>
                    <div class="sale-item-actions">
                        <button type="button" class="ops-button-secondary" data-toggle-sale-item="${index}">${toggleLabel}</button>
                        <button type="button" class="ops-button-secondary" data-remove-sale-item="${index}">Remove</button>
                    </div>
                `;
                entry.appendChild(row);

                const detail = document.createElement('div');
                detail.className = 'sale-item-detail';
                detail.hidden = !item.expanded;
                detail.innerHTML = `
                    <div class="sale-item-detail-grid">
                        <div class="sale-item-detail-field">
                            <label>Asset Link</label>
                            <input type="text" class="line-asset-search" placeholder="Search asset" value="${item.assetSearch || ''}" data-sale-asset-search="${index}">
                            <div class="rental-line-asset-picker" data-sale-asset-picker="${index}">
                                <label class="rental-line-asset-option">
                                    <input type="radio" name="sale_items[${index}][asset_id]" value="" data-sale-asset-input="${index}" ${item.asset_id ? '' : 'checked'}>
                                    <span>No serialized asset link</span>
                                </label>
                                ${visibleAssets.map(function (asset) {
                                    return `<label class="rental-line-asset-option"><input type="radio" name="sale_items[${index}][asset_id]" value="${asset.id}" data-sale-asset-input="${index}" ${parseInt(item.asset_id || '0', 10) === asset.id ? 'checked' : ''}><span>${asset.label}</span></label>`;
                                }).join('')}
                                ${!visibleAssets.length && item.product_id ? `<div class="rental-line-asset-empty">No assets available</div>` : ''}
                            </div>
                            ${filteredAssetChoices.length > visibleAssets.length
                                ? `<div class="line-asset-load-more"><button type="button" class="ops-button-secondary" data-sale-asset-load-more="${index}">Load More</button></div>`
                                : ''}
                            <div class="sale-item-subnote">${assetHelpText}</div>
                        </div>
                        <div class="sale-item-detail-field">
                            <label>GST %</label>
                            <select name="sale_items[${index}][gst_rate]" data-sale-gst-rate="${index}">
                                ${gstOptionsHtml(item.gst_rate || '0.00')}
                            </select>
                        </div>
                        <div class="sale-item-detail-field">
                            <label>GST Mode</label>
                            <select name="sale_items[${index}][gst_mode]" data-sale-gst-mode="${index}">
                                <option value="exclusive"${item.gst_mode === 'inclusive' ? '' : ' selected'}>Exclusive</option>
                                <option value="inclusive"${item.gst_mode === 'inclusive' ? ' selected' : ''}>Inclusive</option>
                            </select>
                        </div>
                        <div class="sale-item-detail-field">
                            <label>Tax Type</label>
                            <select name="sale_items[${index}][tax_type]" data-sale-tax-type="${index}">
                                <option value="cgst_sgst"${item.tax_type === 'igst' ? '' : ' selected'}>CGST + SGST</option>
                                <option value="igst"${item.tax_type === 'igst' ? ' selected' : ''}>IGST</option>
                            </select>
                        </div>
                        <div class="sale-item-detail-field">
                            <label>Notes</label>
                            <textarea name="sale_items[${index}][notes]" placeholder="Optional note">${item.notes || ''}</textarea>
                        </div>
                    </div>
                `;
                entry.appendChild(detail);
                saleItemsList.appendChild(entry);

                const productSelectEl = row.querySelector(`[data-sale-product-index="${index}"]`);
                const warehouseSelectEl = row.querySelector(`[data-sale-warehouse-index="${index}"]`);
                const assetSearchEl = detail.querySelector(`[data-sale-asset-search="${index}"]`);
                const assetInputs = detail.querySelectorAll(`[data-sale-asset-input="${index}"]`);
                const assetLoadMoreButton = detail.querySelector(`[data-sale-asset-load-more="${index}"]`);
                const quantityInputEl = row.querySelector(`[data-sale-quantity-index="${index}"]`);
                const priceInputEl = row.querySelector(`[data-sale-price-index="${index}"]`);
                const gstRateInputEl = detail.querySelector(`[data-sale-gst-rate="${index}"]`);
                const gstModeSelectEl = detail.querySelector(`[data-sale-gst-mode="${index}"]`);
                const taxTypeSelectEl = detail.querySelector(`[data-sale-tax-type="${index}"]`);
                const notesInputEl = detail.querySelector(`textarea[name="sale_items[${index}][notes]"]`);
                const toggleButton = row.querySelector(`[data-toggle-sale-item="${index}"]`);
                const removeButton = row.querySelector(`[data-remove-sale-item="${index}"]`);

                productSelectEl.value = item.product_id || '';
                enhanceSearchableSelect(productSelectEl);
                warehouseSelectEl.value = item.warehouse_id || '';

                productSelectEl.addEventListener('change', function () {
                    saleItems[index].product_id = this.value ? parseInt(this.value, 10) : null;
                    saleItems[index].assetSearch = '';
                    saleItems[index].assetVisibleCount = inlineAssetVisibleStep;

                    const selectedAsset = findSaleAsset(saleItems[index].asset_id);
                    if (selectedAsset && parseInt(selectedAsset.product_id || '0', 10) !== parseInt(this.value || '0', 10)) {
                        saleItems[index].asset_id = null;
                    }

                    const defaults = selectedProductTaxDefaults(this);
                    saleItems[index].gst_rate = defaults.gstRate;
                    saleItems[index].gst_mode = defaults.gstMode;
                    saleItems[index].tax_type = defaults.taxType;

                    if (!priceInputEl.value) {
                        const selected = this.options[this.selectedIndex];
                        const defaultPrice = selected ? parseFloat(selected.getAttribute('data-default-price') || '0') : 0;
                        saleItems[index].unit_price = defaultPrice ? defaultPrice.toFixed(2) : '';
                    }

                    renderSaleItems();
                });

                warehouseSelectEl.addEventListener('change', function () {
                    saleItems[index].warehouse_id = this.value ? parseInt(this.value, 10) : null;
                    saleItems[index].assetSearch = '';
                    saleItems[index].assetVisibleCount = inlineAssetVisibleStep;
                    const selectedAsset = findSaleAsset(saleItems[index].asset_id);

                    if (
                        selectedAsset &&
                        saleItems[index].warehouse_id &&
                        parseInt(selectedAsset.warehouse_id || '0', 10) !== saleItems[index].warehouse_id
                    ) {
                        saleItems[index].asset_id = null;
                    }

                    renderSaleItems();
                });

                assetSearchEl.addEventListener('input', function () {
                    saleItems[index].assetSearch = this.value || '';
                    saleItems[index].assetVisibleCount = inlineAssetVisibleStep;
                    renderSaleItems();
                });

                assetInputs.forEach(function (input) {
                    input.addEventListener('change', function () {
                        saleItems[index].asset_id = this.value ? parseInt(this.value, 10) : null;

                        if (saleItems[index].asset_id) {
                            const selectedAsset = findSaleAsset(saleItems[index].asset_id);
                            saleItems[index].quantity = 1;

                            if (selectedAsset && selectedAsset.warehouse_id) {
                                saleItems[index].warehouse_id = parseInt(selectedAsset.warehouse_id, 10);
                            }
                        }

                        renderSaleItems();
                    });
                });

                if (assetLoadMoreButton) {
                    assetLoadMoreButton.addEventListener('click', function () {
                        saleItems[index].assetVisibleCount += inlineAssetVisibleStep;
                        renderSaleItems();
                    });
                }

                quantityInputEl.addEventListener('input', function () {
                    saleItems[index].quantity = saleItems[index].asset_id
                        ? 1
                        : Math.max(parseInt(this.value || '0', 10), 0);
                    renderSaleItems();
                });

                priceInputEl.addEventListener('input', function () {
                    saleItems[index].unit_price = this.value || '';
                    renderSaleItems();
                });

                gstRateInputEl.addEventListener('change', function () {
                    saleItems[index].gst_rate = normalizeMoney(this.value);
                    renderSaleItems();
                });

                gstModeSelectEl.addEventListener('change', function () {
                    saleItems[index].gst_mode = this.value === 'inclusive' ? 'inclusive' : 'exclusive';
                    renderSaleItems();
                });

                taxTypeSelectEl.addEventListener('change', function () {
                    saleItems[index].tax_type = this.value === 'igst' ? 'igst' : 'cgst_sgst';
                    renderSaleItems();
                });

                notesInputEl.addEventListener('input', function () {
                    saleItems[index].notes = this.value || '';
                });

                toggleButton.addEventListener('click', function () {
                    saleItems[index].expanded = !saleItems[index].expanded;
                    renderSaleItems();
                });

                removeButton.addEventListener('click', function () {
                    saleItems.splice(index, 1);
                    renderSaleItems();
                });

                total += parseFloat(saleItemLineTotal(item));
            });

            saleItemsCount.textContent = String(saleItems.length);
            saleItemsCountSummary.textContent = String(saleItems.length);
            saleItemsGrandTotal.textContent = total.toFixed(2);
            saleItemsGrandTotalSummary.textContent = total.toFixed(2);
        }

        customerTypeButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                if (!customerTypeSelect || button.disabled || button.getAttribute('aria-disabled') === 'true') {
                    return;
                }

                const nextMode = button.getAttribute('data-customer-type-option') || 'direct_customer';
                if (customerTypeSelect.value === nextMode) {
                    return;
                }

                customerTypeSelect.value = nextMode;
                customerTypeSelect.dispatchEvent(new Event('change', { bubbles: true }));
            });
        });

        customerTypeSelect?.addEventListener('change', function () {
            updateCustomerModeVisibility();
            if (primaryTaxTypeSelect) {
                primaryTaxTypeSelect.value = recommendedTaxType();
            }
            rentalItems = rentalItems.map(function (item) {
                return Object.assign({}, item, { tax_type: recommendedTaxType() });
            });
            saleItems = saleItems.map(function (item) {
                return Object.assign({}, item, { tax_type: recommendedTaxType() });
            });
            renderRentalItems();
            renderSaleItems();
        });
        customerSelect.addEventListener('change', function () {
            syncCustomerFields();
            updateContactUsageSummary();
            if (primaryTaxTypeSelect) {
                primaryTaxTypeSelect.value = recommendedTaxType();
            }
            rentalItems = rentalItems.map(function (item) {
                return Object.assign({}, item, { tax_type: recommendedTaxType() });
            });
            saleItems = saleItems.map(function (item) {
                return Object.assign({}, item, { tax_type: recommendedTaxType() });
            });
            renderRentalItems();
            renderSaleItems();
        });
        businessPartnerSelect?.addEventListener('change', function () {
            updateCustomerModeVisibility();
            syncPartnerClientFields();
            updateContactUsageSummary();
            if (primaryTaxTypeSelect) {
                primaryTaxTypeSelect.value = recommendedTaxType();
            }
        });
        partnerClientSelect?.addEventListener('change', function () {
            syncPartnerClientFields();
            updateContactUsageSummary();
            if (primaryTaxTypeSelect) {
                primaryTaxTypeSelect.value = recommendedTaxType();
            }
        });
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
            option.setAttribute('data-phone-country', client.phone_country || '');
            option.setAttribute('data-state', client.state || '');
            option.setAttribute('data-address', client.address || '');
            option.setAttribute('data-city', client.city || '');
            option.setAttribute('data-location', client.location || '');
            option.setAttribute('data-notes', client.delivery_notes || '');
            option.setAttribute('data-search', [client.name, client.phone, client.address, client.city, client.state].filter(Boolean).join(' '));

            if (String(businessPartnerSelect?.value || '') === String(partnerId)) {
                partnerClientSelect.appendChild(option);
                partnerClientSelect.value = String(client.id);
                partnerClientSelect.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
        productSelect.addEventListener('change', handlePrimaryProductChange);
        productSelect.addEventListener('input', handlePrimaryProductChange);
        warehouseSelect.addEventListener('change', function () {
            updateWarehouseMetric();
            primaryAvailabilityLoadedFor = null;
            updateAvailabilityHint();
            updatePrimaryRentalFeedback();
            fetchAssets();
            Object.keys(rentalItemAssetCache).forEach(function (key) {
                delete rentalItemAssetCache[key];
            });
            renderRentalItems();
        });
        quantityInput.addEventListener('input', function () {
            updateSelectionMetrics();
            updatePrimaryRentalFeedback();
            syncPrimaryRentalAmount(false);
        });
        startDateInput.addEventListener('change', function () {
            syncDurationPreset();
            syncPrimaryRentalAmount(false);
        });
        endDateInput.addEventListener('change', function () {
            syncPrimaryRentalAmount(false);
        });
        durationPresetSelect.addEventListener('change', function () {
            rentalAmountTouched = false;
            syncDurationPreset();
            syncPrimaryRentalAmount(true);
        });
        deliveryAssignmentSelect?.addEventListener('change', syncThirdPartyDeliveryFields);
        rentalAmountInput.addEventListener('input', function () {
            rentalAmountTouched = true;
        });
        assetSearch.addEventListener('input', function () {
            visibleAssetCount = assetVisibleStep;
            renderAssets();
        });
        selectSuggestedAssetsButton.addEventListener('click', function () {
            const quantity = Math.max(parseInt(quantityInput.value || '0', 10), 0);
            const filtered = getFilteredAssets();
            const maxSelectable = quantity > 0 ? quantity : filtered.length;

            selectedAssetIds = filtered.slice(0, maxSelectable).map(function (asset) {
                return asset.id;
            });

            renderAssets();
            updateSelectionMetrics();
            renderRentalItems();
        });
        clearSelectedAssetsButton.addEventListener('click', function () {
            selectedAssetIds = [];
            renderAssets();
            updateSelectionMetrics();
            renderRentalItems();
        });
        assetLoadMoreButton.addEventListener('click', function () {
            visibleAssetCount += assetVisibleStep;
            renderAssets();
        });
        addRentalItemButton.addEventListener('click', function () {
            additionalRentalDisclosure.open = true;
            rentalItems.push({
                product_id: null,
                asset_ids: [],
                quantity: 1,
                unit_rental_amount: '',
                gst_rate: '0.00',
                gst_mode: 'exclusive',
                tax_type: recommendedTaxType(),
                notes: '',
                expanded: false
            });

            renderRentalItems();
        });
        addSaleItemButton.addEventListener('click', function () {
            newProductsDisclosure.open = true;
            saleItems.push({
                product_id: null,
                asset_id: null,
                warehouse_id: warehouseSelect.value ? parseInt(warehouseSelect.value, 10) : null,
                quantity: 1,
                unit_price: '',
                gst_rate: '0.00',
                gst_mode: 'exclusive',
                tax_type: recommendedTaxType(),
                notes: '',
                expanded: false
            });

            renderSaleItems();
        });

        updateCustomerModeVisibility();
        syncCustomerFields();
        syncPartnerClientFields();
        syncPrimaryTaxDefaults(false);
        updateWarehouseMetric();
        updateAvailabilityHint();
        updatePrimaryRentalFeedback();
        updateSelectionMetrics();
        fetchAssets();
        syncDurationPreset();
        syncPrimaryRentalAmount(false);
        syncThirdPartyDeliveryFields();
        window.setTimeout(function () {
            syncPrimaryRentalAmount(false);
        }, 0);
        renderRentalItems();
        renderSaleItems();
    })();
</script>

