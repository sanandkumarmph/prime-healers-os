@php
    $isEdit = isset($rental);
    $selectedCustomer = $selectedCustomer ?? null;
    $businessPartners = $businessPartners ?? collect();
    $initialPartnerClients = $initialPartnerClients ?? collect();
    $businessPartnerFlowAvailable = $businessPartnerFlowAvailable ?? true;
    $hasReferralSourceTypeColumn = \Illuminate\Support\Facades\Schema::hasColumn('rentals', 'referral_source_type');
    $hasReferredByColumn = \Illuminate\Support\Facades\Schema::hasColumn('rentals', 'referred_by');
    $hasReferralContactColumn = \Illuminate\Support\Facades\Schema::hasColumn('rentals', 'referral_contact');
    $hasReferralCityColumn = \Illuminate\Support\Facades\Schema::hasColumn('rentals', 'referral_city');
    $referralSourceOptions = collect($referralSourceOptions ?? collect())->values();
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
    $existingRentalItems = $isEdit ? ($rental->rentalItems ?? collect()) : collect();
    $primaryRentalItem = $existingRentalItems->first();
    $savedPrimaryAssetIds = $primaryRentalItem && filled($primaryRentalItem->asset_ids)
        ? collect($primaryRentalItem->asset_ids)->all()
        : ($isEdit ? $rental->activeRentalAssets->pluck('asset_id')->all() : []);
    $oldPrimaryAssetIds = old('asset_ids', []);
    if (empty($oldPrimaryAssetIds) && filled(old('primary_asset_ids'))) {
        $oldPrimaryAssetIds = preg_split('/[,\\s]+/', (string) old('primary_asset_ids'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
    $selectedAssetIds = collect(!empty($oldPrimaryAssetIds) ? $oldPrimaryAssetIds : $savedPrimaryAssetIds)
        ->filter(fn ($value) => filled($value))
        ->map(fn ($value) => (int) $value)
        ->unique()
        ->values()
        ->all();
    $additionalRentalRows = collect(old('rental_items', $existingRentalItems->skip(1)->map(function ($item) {
        return [
            'product_id' => $item->product_id,
            'asset_ids' => collect($item->asset_ids ?? [])->filter(fn ($value) => filled($value))->map(fn ($value) => (int) $value)->values()->all(),
            'quantity' => $item->quantity,
                        'start_date' => $item->start_date ? \Illuminate\Support\Carbon::parse($item->start_date)->format('Y-m-d') : '',
            'end_date' => $item->end_date ? \Illuminate\Support\Carbon::parse($item->end_date)->format('Y-m-d') : '',
            'duration_days' => $item->duration_days,
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
    $selectedDeliveryAssignment = old('delivery_staff_id');
    $internalAssignableUsers = collect($assignableUsers ?? collect())->filter(function ($user) {
        $signals = collect([
            $user->effective_role ?? null,
            $user->role ?? null,
            $user->assignedRole->slug ?? null,
            $user->assignedRole->name ?? null,
        ])->filter()->map(function ($value) {
            return \Illuminate\Support\Str::of((string) $value)
                ->lower()
                ->replace([' ', '-'], '_')
                ->value();
        });

        return $signals->contains(fn ($value) => \Illuminate\Support\Str::contains($value, 'delivery'));
    })->values();
    $vendorDeliveryMembers = collect($staffMembers ?? collect())->filter(function ($staff) {
        return ($staff->effective_role ?? null) === 'vendor';
    })->values();
    $vendorUsers = collect($vendors ?? collect())
        ->reject(function ($vendor) use ($internalAssignableUsers) {
            return collect($internalAssignableUsers ?? collect())->contains(fn ($user) => (int) $user->id === (int) $vendor->id);
        })
        ->values();
    $fulfilmentVendors = collect($fulfilmentVendors ?? collect())->values();
    $cities = collect($cities ?? collect())->values();
    $selectedFulfilmentSource = old(
        'fulfilment_source',
        request()->input(
            'fulfilment_source',
            $isEdit ? ($rental->fulfilment_source ?? \App\Models\VendorOrderDetail::FULFILMENT_SOURCE_IN_HOUSE) : \App\Models\VendorOrderDetail::FULFILMENT_SOURCE_IN_HOUSE
        )
    );
    $selectedFulfilmentVendorId = (int) old('vendor_id', $isEdit ? ($rental->vendor_id ?? 0) : 0);
    $selectedDeliveryResponsibility = old('delivery_responsibility', $isEdit ? ($rental->delivery_responsibility ?? 'ph_internal_delivery') : 'ph_internal_delivery');
    $selectedPickupResponsibility = old('pickup_responsibility', $isEdit ? ($rental->pickup_responsibility ?? 'ph_internal_pickup') : 'ph_internal_pickup');
    $selectedCityId = (int) old('city_id', 0);
    $thirdPartyDeliveryMembers = collect($staffMembers ?? collect())->filter(function ($staff) {
        return ($staff->effective_role ?? null) === 'third_party';
    })->values();
    $selectedLegacyAssignableStaff = collect($staffMembers ?? collect())->first(function ($staff) use ($selectedDeliveryAssignment, $internalAssignableUsers, $thirdPartyDeliveryMembers, $vendorDeliveryMembers) {
        if ($selectedDeliveryAssignment !== 'staff:' . $staff->id) {
            return false;
        }

        $isInternalDelivery = $internalAssignableUsers->contains(fn ($user) => (int) $user->id === (int) $staff->id);
        $isThirdParty = $thirdPartyDeliveryMembers->contains(fn ($member) => (int) $member->id === (int) $staff->id);
        $isVendor = $vendorDeliveryMembers->contains(fn ($member) => (int) $member->id === (int) $staff->id);

        return !$isInternalDelivery && !$isThirdParty && !$isVendor;
    });
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
    if ($selectedCityId <= 0) {
        $selectedCityId = (int) old(
            'city_id',
            $isEdit
                ? ($rental->dispatchWarehouse?->city_id
                    ?? $rental->vendorOrderDetail?->vendor?->city_id
                    ?? 0)
                : 0
        );
    }
    $selectedDeliveryAssignmentType = old('delivery_assignment_type');
    if (!$selectedDeliveryAssignmentType) {
        $selectedDeliveryAssignmentType = match (true) {
            $selectedDeliveryResponsibility === 'customer_pickup' => 'customer_pickup',
            $selectedDeliveryResponsibility === 'vendor_delivery' => 'vendor',
            $selectedDeliveryAssignment === 'third_party' => 'third_party',
            default => 'ph_internal',
        };
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
    $assetAssignmentError = $fieldError('asset_ids', 'product_id', 'dispatch_warehouse_id', 'quantity', 'rental_items', 'rental_items.0.asset_ids', 'rental_items.0.product_id', 'rental_items.0.quantity');
    $assetAssignmentDisplayError = $assetAssignmentError
        ? 'Rental could not be saved because: ' . $assetAssignmentError
        : null;
    $additionalRentalExpanded = $hasRentalItemsError;
    $newProductsExpanded = $hasSaleItemsError;
    $currentUser = auth()->user();
    $canCreateRentalStock = $currentUser?->canAccessModule('assets', 'create') ?? false;
@endphp

@include('partials.business-partner-flow-styles')

<style>
    .rental-focused-form-page {
        width:100%;
        max-width:100%;
        padding:0 0 24px;
        margin:0;
    }
    .rental-shell { display:grid; gap:10px; width:100%; max-width:100%; min-width:0; overflow-x:clip; }
    .rental-toolbar { display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:0; }
    .rental-title h1 { margin:0; font-size:24px; color:#0f172a; }
    .rental-title p { margin:4px 0 0; color:#64748b; font-size:13px; }
    .rental-actions { display:flex; gap:8px; flex-wrap:wrap; }
    .rental-card { background:#fff; border:1px solid #dbe3ef; border-radius:12px; padding:12px; box-shadow:0 8px 24px rgba(15, 23, 42, 0.04); min-width:0; max-width:100%; }
    .rental-card h2 { margin:0 0 3px; font-size:15px; color:#0f172a; }
    .rental-card p.section-copy { margin:0 0 8px; font-size:11px; color:#64748b; }
    .rental-card[data-rental-wizard-step="product"],
    .rental-step-actions[data-rental-wizard-step="product"] { margin-top:12px; }
    .rental-card[data-rental-wizard-step="product"] { padding:16px; border-radius:12px; box-shadow:0 10px 28px rgba(15,23,42,.045); }
    .rental-step-header { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; margin-bottom:12px; }
    .rental-step-header h2 { margin:0 0 4px; }
    .rental-step-header .section-copy { margin:0; }
    .rental-step-header-actions { display:flex; align-items:center; justify-content:flex-end; gap:8px; flex-wrap:wrap; }
    .rental-icon-title { display:flex; align-items:center; gap:8px; }
    .rental-icon-title::before { content:attr(data-icon); display:inline-grid; place-items:center; width:28px; height:28px; border-radius:9px; background:#eef2ff; color:#4f46e5; font-size:12px; font-weight:900; }
    .primary-rental-product-card { border:1px solid #dbe3ef; border-radius:12px; padding:16px; background:#fff; box-shadow:0 8px 18px rgba(15,23,42,.035); }
    .primary-rental-product-card .rental-grid { gap:12px; }
    .primary-rental-product-card .rental-line-heading { padding:0 0 8px; border-bottom:1px solid #eef2f7; margin-bottom:2px; }
    .primary-rental-product-card .rental-line-heading strong { font-size:14px; }
    .primary-rental-product-card .rental-line-heading span { color:#4f46e5; background:#eef2ff; border-radius:999px; padding:3px 9px; }
    .primary-rental-product-card .rental-field { gap:6px; }
    .primary-rental-product-card .rental-field input,
    .primary-rental-product-card .rental-field select,
    .primary-rental-product-card .searchable-select-trigger { min-height:44px; height:44px; border-radius:10px; }
    .primary-rental-product-card .rental-product-setup-grid { align-items:start; }
    .primary-rental-product-card .rental-product-label-row { display:flex; align-items:center; justify-content:space-between; gap:8px; min-height:16px; }
    .primary-rental-product-card .rental-product-label-row label { margin:0; }
    .primary-rental-product-card .rental-stock-inline-button { flex:0 0 auto; min-height:28px; height:28px; border-radius:999px; padding:0 9px; box-sizing:border-box; gap:4px; overflow:hidden; text-overflow:ellipsis; font-size:11px; }
    .primary-rental-product-card .rental-stock-inline-button .desktop-label { display:none; }
    .primary-rental-product-card .rental-stock-inline-button .mobile-label { display:inline; }
    .rental-section-panel { border:1px solid #dbe3ef; border-radius:12px; padding:16px; background:#fff; box-shadow:0 8px 18px rgba(15,23,42,.035); }
    .rental-section-panel .sale-section-body { padding-top:10px; }
    .rental-availability-panel { display:grid; grid-template-columns:repeat(5,minmax(0,1fr)); gap:8px; padding:8px; border:1px solid #e2e8f0; border-radius:12px; background:#f8fafc; }
    .rental-availability-panel div { min-height:48px; padding:8px 10px; border:1px solid #e2e8f0; border-radius:10px; background:#fff; }
    .rental-availability-panel span { display:flex; align-items:center; gap:6px; font-size:10px; color:#475569; }
    .rental-availability-panel span::before { content:''; width:7px; height:7px; border-radius:999px; background:#22c55e; flex:0 0 auto; }
    .rental-availability-panel div:nth-child(2) span::before { background:#f59e0b; }
    .rental-availability-panel div:nth-child(3) span::before { background:#3b82f6; }
    .rental-availability-panel div:nth-child(4) span::before { background:#8b5cf6; }
    .rental-availability-panel div:nth-child(5) span::before { background:#64748b; }
    .rental-availability-panel strong { font-size:16px; }
    .gst-details-collapse { grid-column:1 / -1; border:1px solid #dbe3ef; border-radius:10px; background:#fff; }
    .gst-details-collapse summary { display:flex; align-items:center; justify-content:space-between; gap:10px; min-height:38px; padding:8px 10px; cursor:pointer; color:#334155; font-size:12px; font-weight:800; }
    .gst-details-collapse summary::-webkit-details-marker { display:none; }
    .gst-details-collapse summary::after { content:'v'; color:#64748b; font-size:12px; }
    .gst-details-collapse[open] summary::after { content:'^'; }
    .gst-details-collapse .hint { margin-left:4px; font-weight:700; color:#64748b; text-transform:none; letter-spacing:0; }
    .gst-details-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:10px; padding:0 10px 10px; }
    .assignment-launch-card { padding:12px; border-radius:12px; }
    .assignment-launch-grid { grid-template-columns:repeat(4,minmax(0,1fr)); gap:8px; }
    .assignment-launch-metric { min-height:54px; padding:9px 10px; }
    .asset-panel { margin-top:10px; }
    .sale-section-disclosure.rental-section-panel { display:block; padding:0; overflow:hidden; }
    .sale-section-disclosure.rental-section-panel > summary { padding:14px 16px; }
    .sale-section-disclosure.rental-section-panel .sale-section-body { padding:0 16px 16px; }
    .sale-section-disclosure.rental-section-panel .sale-item-summary { border-radius:10px; padding:10px 12px; }
    .sale-section-chip { min-height:30px; padding:5px 10px; }
    .add-line-cta { min-height:36px !important; border-color:#b7c7ff !important; background:#fff !important; color:#4f46e5 !important; box-shadow:none !important; }
    .add-line-cta:hover { background:#eef2ff !important; border-color:#818cf8 !important; }
    .rental-step-actions[data-rental-wizard-step="product"] { background:transparent; border:0; box-shadow:none; padding:0; }
    .rental-step-actions[data-rental-wizard-step="product"] .ops-button,
    .rental-step-actions[data-rental-wizard-step="product"] .ops-button-secondary { min-height:40px; border-radius:10px; }
    @media (max-width: 900px) {
        .rental-step-header { flex-direction:column; }
        .rental-step-header-actions { width:100%; justify-content:flex-start; }
        .rental-availability-panel { grid-template-columns:repeat(2,minmax(0,1fr)); }
        .gst-details-grid,
        .assignment-launch-grid { grid-template-columns:1fr; }
    }
    .rental-grid { display:grid; grid-template-columns:repeat(12, minmax(0, 1fr)); gap:9px; min-width:0; }
    .rental-col-3 { grid-column:span 3; }
    .rental-col-4 { grid-column:span 4; }
    .rental-col-5 { grid-column:span 5; }
    .rental-col-6 { grid-column:span 6; }
    .rental-col-7 { grid-column:span 7; }
    .rental-col-8 { grid-column:span 8; }
    .rental-col-12 { grid-column:span 12; }
    .rental-field { display:flex; flex-direction:column; gap:3px; }
    .rental-field label { font-size:11px; font-weight:700; color:#334155; letter-spacing:0.03em; text-transform:uppercase; }
    .rental-field-label-row { display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:2px; }
    .rental-field-label-row label { margin:0; }
    .rental-stock-inline-button {
        display:inline-flex;
        align-items:center;
        justify-content:center;
        min-height:28px;
        padding:5px 10px;
        border:1px solid #bfdbfe;
        border-radius:999px;
        background:#eff6ff;
        color:#1d4ed8;
        font-size:12px;
        font-weight:800;
        line-height:1;
        white-space:nowrap;
        cursor:pointer;
    }
    .rental-stock-inline-button:hover { border-color:#93c5fd; background:#dbeafe; }
    #rental-product-section .rental-stock-inline-button .desktop-label { display:none; }
    #rental-product-section .rental-stock-inline-button .mobile-label { display:inline; }
    #deliveryPartnerHint { font-size:12px !important; line-height:1.35 !important; color:#64748b !important; max-width:480px; margin-top:4px !important; }
    .rental-wizard-step[data-rental-wizard-step="delivery"] > .rental-grid { align-items:start; }
    .rental-wizard-step[data-rental-wizard-step="delivery"] > .rental-grid > .rental-field > label { min-height:30px; display:flex; align-items:flex-end; }
    .rental-wizard-step[data-rental-wizard-step="delivery"] > .rental-grid > .rental-field select { min-height:44px; height:44px; box-sizing:border-box; }
    .rental-stock-inline-button .mobile-label { display:none; }
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
        padding:7px 10px;
        font-size:13px;
        color:#0f172a;
        background:#fff;
        box-sizing:border-box;
    }
    .rental-field textarea { min-height:62px; resize:vertical; }
    .rental-inline-warning {
        display:none;
        grid-column:span 12;
        padding:9px 11px;
        border:1px solid #fbbf24;
        border-radius:10px;
        background:#fffbeb;
        color:#78350f;
        font-size:12px;
        font-weight:700;
        line-height:1.4;
    }
    .rental-inline-warning.is-visible { display:block; }
    .rental-missing-checklist {
        display:none;
        gap:6px;
        flex-wrap:wrap;
        align-items:center;
        margin-top:6px;
        font-size:11px;
    }
    .rental-missing-checklist.is-visible { display:flex; }
    .rental-missing-checklist span {
        display:inline-flex;
        align-items:center;
        min-height:22px;
        padding:3px 8px;
        border:1px solid #fed7aa;
        border-radius:999px;
        background:#fff7ed;
        color:#9a3412;
        font-weight:800;
    }
    .rental-summary-secondary {
        margin-top:2px;
        border-top:1px solid #e2e8f0;
        padding-top:6px;
    }
    .rental-summary-secondary summary {
        display:flex;
        justify-content:flex-start;
        color:#475569;
        font-size:11px;
        font-weight:800;
        text-transform:uppercase;
        letter-spacing:.04em;
    }
    .rental-summary-secondary .rental-summary-list { margin-top:7px; }
    .rental-wizard-nav {
        display:grid;
        grid-template-columns:repeat(5, minmax(0, 1fr));
        gap:7px;
        padding:8px;
        border:1px solid #dbe3ef;
        border-radius:14px;
        background:#fff;
        box-shadow:0 8px 24px rgba(15, 23, 42, 0.04);
    }
    .rental-wizard-tab {
        display:flex;
        align-items:center;
        gap:7px;
        min-width:0;
        min-height:38px;
        border:1px solid #dbe3ef;
        border-radius:11px;
        background:#f8fafc;
        color:#334155;
        padding:7px 8px;
        text-align:left;
        cursor:pointer;
        font-size:12px;
        font-weight:800;
    }
    .rental-wizard-tab span:first-child {
        display:grid;
        place-items:center;
        width:22px;
        height:22px;
        border-radius:999px;
        background:#e2e8f0;
        color:#334155;
        font-size:11px;
        flex:0 0 22px;
    }
    .rental-wizard-tab strong { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .rental-wizard-tab.is-active { border-color:#a5b4fc; background:#eef2ff; color:#4338ca; }
    .rental-wizard-tab.is-active span:first-child { background:#4f46e5; color:#fff; }
    .rental-wizard-tab.is-complete span:first-child { background:#16a34a; color:#fff; }
    .rental-wizard-tab.is-warning span:first-child { background:#f59e0b; color:#fff; }
    .rental-mobile-progress { display:none; }
    .rental-mobile-progress button {
        border:0;
        background:transparent;
        color:#64748b;
        font-size:10px;
        font-weight:800;
        display:grid;
        justify-items:center;
        gap:3px;
        min-width:54px;
        padding:0;
    }
    .rental-mobile-progress span {
        display:grid;
        place-items:center;
        width:20px;
        height:20px;
        border-radius:999px;
        border:1px solid #cbd5e1;
        background:#fff;
        color:#64748b;
        font-size:10px;
    }
    .rental-mobile-progress button.is-active { color:#4f46e5; }
    .rental-mobile-progress button.is-active span {
        background:#4f46e5;
        border-color:#4f46e5;
        color:#fff;
    }
    .rental-mobile-progress button.is-complete { color:#16a34a; }
    .rental-mobile-progress button.is-complete span {
        background:#16a34a;
        border-color:#16a34a;
        color:#fff;
    }
    .rental-wizard-layout {
        display:grid;
        grid-template-columns:minmax(0, 1fr) minmax(280px, 340px);
        gap:10px;
        align-items:start;
    }
    .rental-wizard-main { display:grid; gap:10px; min-width:0; }
    .rental-wizard-step { display:none; }
    .rental-wizard-step.is-active { display:block; }
    .rental-wizard-step.rental-step-actions { display:none; }
    .rental-wizard-step.rental-step-actions.is-active { display:flex; }
    .rental-step-actions {
        display:flex;
        justify-content:space-between;
        align-items:center;
        gap:8px;
        flex-wrap:wrap;
        padding:8px 0 0;
    }
    .rental-step-actions .ops-button,
    .rental-step-actions .ops-button-secondary { min-height:34px; }
    .rental-step-actions button:disabled {
        opacity:.48;
        cursor:not-allowed;
        filter:grayscale(.2);
    }
    .rental-order-summary {
        position:sticky;
        top:88px;
        display:grid;
        gap:10px;
        padding:12px;
        border:1px solid #dbe3ef;
        border-radius:14px;
        background:#fff;
        box-shadow:0 12px 30px rgba(15, 23, 42, 0.08);
        min-width:0;
    }
    .rental-order-summary h2 { margin:0; font-size:15px; color:#0f172a; }
    .rental-order-summary details { display:grid; gap:8px; }
    .rental-order-summary summary { list-style:none; display:flex; justify-content:space-between; gap:8px; cursor:pointer; }
    .rental-order-summary summary::-webkit-details-marker { display:none; }
    .rental-summary-list { display:grid; gap:7px; }
    .rental-summary-row {
        display:grid;
        grid-template-columns:105px minmax(0, 1fr);
        gap:8px;
        align-items:start;
        font-size:12px;
    }
    .rental-summary-row span { color:#64748b; font-weight:800; text-transform:uppercase; letter-spacing:.04em; font-size:10px; }
    .rental-summary-row strong { color:#0f172a; font-weight:800; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .rental-review-grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:8px; }
    .rental-review-box { border:1px solid #e2e8f0; border-radius:10px; padding:9px; background:#f8fafc; display:grid; gap:3px; }
    .rental-review-box span { font-size:10px; color:#64748b; font-weight:800; text-transform:uppercase; letter-spacing:.04em; }
    .rental-review-box strong { font-size:13px; color:#0f172a; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .rental-availability-panel {
        display:grid;
        grid-template-columns:repeat(5, minmax(0, 1fr));
        gap:8px;
        padding:8px;
        border:1px solid #dbe3ef;
        border-radius:12px;
        background:#f8fafc;
    }
    .rental-availability-panel div { display:grid; gap:3px; padding:8px; border-radius:10px; background:#fff; border:1px solid #e2e8f0; min-width:0; }
    .rental-availability-panel span { font-size:10px; color:#64748b; font-weight:800; text-transform:uppercase; letter-spacing:.04em; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .rental-availability-panel strong { font-size:15px; color:#0f172a; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .partner-billing-note {
        display:inline-flex;
        margin-top:6px;
        padding:6px 8px;
        border-radius:9px;
        background:#eef2ff;
        color:#4338ca;
        font-size:11px;
        font-weight:800;
    }
    .rental-draft-status {
        font-size:11px;
        color:#64748b;
        font-weight:700;
    }
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
        width:100%;
        min-width:0;
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
        box-sizing:border-box;
    }
    .searchable-select-trigger span {
        min-width:0;
        overflow:hidden;
        text-overflow:ellipsis;
        white-space:nowrap;
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
        min-width:min(320px, calc(100vw - 32px));
    }
    .searchable-select.is-product-search .searchable-select-panel {
        right:auto;
        width:min(560px, calc(100vw - 32px));
        max-width:min(650px, calc(100vw - 32px));
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
    .searchable-select-option.has-product-media { min-height:58px; padding:10px 12px; }
    .product-option-media { display:flex; align-items:center; gap:10px; min-width:0; }
    .product-option-thumb,
    .selected-product-thumb {
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
    .product-option-thumb img,
    .selected-product-thumb img { width:100%; height:100%; object-fit:cover; display:block; }
    .product-option-copy,
    .selected-product-copy { display:grid; gap:2px; min-width:0; }
    .product-option-copy { gap:3px; }
    .product-option-copy strong { color:#0f172a; font-size:14px; font-weight:800; line-height:1.25; overflow:hidden; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; white-space:normal; }
    .selected-product-copy strong { color:#0f172a; font-size:13px; line-height:1.2; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .product-option-copy span,
    .selected-product-copy span { color:#64748b; font-size:11px; line-height:1.25; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .product-option-copy em { color:#15803d; font-size:11px; font-style:normal; font-weight:800; line-height:1.2; }
    .selected-product-copy em { color:#1d4ed8; font-size:10px; font-style:normal; font-weight:800; line-height:1.2; }
    .selected-product-preview {
        display:flex;
        align-items:center;
        gap:10px;
        margin-top:7px;
        padding:8px 10px;
        border:1px solid #e2e8f0;
        border-radius:11px;
        background:#f8fafc;
        min-width:0;
    }
    .selected-product-preview[hidden] { display:none !important; }    .searchable-select-empty { color:#64748b; }
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
    .rental-mobile-action-bar {
        display:none;
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
    .assignment-launch-card {
        display:grid;
        grid-template-columns:minmax(0, 1fr) auto;
        gap:14px;
        align-items:center;
        border:1px solid #dbe3ef;
        border-radius:14px;
        padding:14px;
        background:linear-gradient(135deg, #ffffff, #f8fbff);
    }
    .assignment-launch-title {
        display:flex;
        align-items:center;
        gap:8px;
        flex-wrap:wrap;
        margin-bottom:8px;
    }
    .assignment-launch-title strong {
        color:#0f172a;
        font-size:16px;
    }
    .assignment-launch-grid {
        display:grid;
        grid-template-columns:repeat(4, minmax(0, 1fr));
        gap:8px;
    }
    .assignment-launch-metric {
        border:1px solid #e2e8f0;
        border-radius:10px;
        padding:8px 10px;
        background:#fff;
        min-width:0;
    }
    .assignment-launch-metric span {
        display:block;
        color:#64748b;
        font-size:10px;
        font-weight:800;
        letter-spacing:.05em;
        text-transform:uppercase;
    }
    .assignment-launch-metric strong {
        display:block;
        margin-top:3px;
        color:#0f172a;
        font-size:13px;
        line-height:1.2;
        overflow:hidden;
        text-overflow:ellipsis;
        white-space:nowrap;
    }
    .assignment-drawer-backdrop[hidden] {
        display:none !important;
    }
    #rentalAssetAssignmentModal[hidden],
    #assetAssignmentModal[hidden],
    [data-rental-asset-modal][hidden] {
        display:none !important;
    }
    #rentalAssetAssignmentModal.is-open,
    #assetAssignmentModal.is-open,
    [data-rental-asset-modal].is-open {
        display:flex !important;
        position:fixed !important;
        inset:0 !important;
        z-index:999999 !important;
    }
    .assignment-drawer-backdrop {
        position:fixed;
        inset:0;
        z-index:80;
        display:flex;
        justify-content:flex-end;
        background:rgba(15, 23, 42, .32);
        backdrop-filter:blur(2px);
    }
    .assignment-drawer {
        width:min(580px, 100vw);
        height:100vh;
        display:grid;
        grid-template-rows:auto minmax(0, 1fr) auto;
        background:#f8fafc;
        box-shadow:-20px 0 50px rgba(15, 23, 42, .18);
    }
    .assignment-drawer-header {
        padding:16px 18px 12px;
        border-bottom:1px solid #dbe3ef;
        background:#fff;
    }
    .assignment-drawer-title {
        display:flex;
        justify-content:space-between;
        align-items:flex-start;
        gap:12px;
        margin-bottom:12px;
    }
    .assignment-drawer-title h3 {
        margin:0;
        color:#0f172a;
        font-size:20px;
        line-height:1.15;
    }
    .assignment-drawer-close {
        width:36px;
        height:36px;
        border:1px solid #dbe3ef;
        border-radius:10px;
        background:#fff;
        color:#334155;
        font-size:20px;
        line-height:1;
        cursor:pointer;
    }
    .assignment-asset-summary {
        display:grid;
        grid-template-columns:repeat(2, minmax(0, 1fr));
        gap:8px;
    }
    .assignment-asset-summary div {
        border:1px solid #e2e8f0;
        border-radius:10px;
        padding:8px 10px;
        background:#f8fafc;
        min-width:0;
    }
    .assignment-asset-summary span {
        display:block;
        color:#64748b;
        font-size:10px;
        font-weight:800;
        letter-spacing:.05em;
        text-transform:uppercase;
    }
    .assignment-asset-summary strong {
        display:block;
        margin-top:3px;
        color:#0f172a;
        font-size:13px;
        overflow:hidden;
        text-overflow:ellipsis;
        white-space:nowrap;
    }
    .assignment-drawer-body {
        overflow:auto;
        padding:14px 18px 18px;
        display:grid;
        gap:12px;
    }
    .assignment-type-grid {
        display:grid;
        grid-template-columns:repeat(2, minmax(0, 1fr));
        gap:8px;
    }
    .assignment-type-card {
        display:flex;
        align-items:center;
        gap:9px;
        border:1px solid #dbe3ef;
        border-radius:12px;
        padding:10px;
        background:#fff;
        color:#334155;
        font-size:13px;
        font-weight:800;
    }
    .assignment-type-card input {
        width:16px;
        height:16px;
    }
    .assignment-type-card.is-disabled {
        opacity:.55;
        background:#f8fafc;
    }
    .assignment-section-label {
        display:block;
        color:#334155;
        font-size:12px;
        font-weight:800;
        margin-bottom:6px;
    }
    .assignment-drawer-fields {
        display:grid;
        grid-template-columns:repeat(2, minmax(0, 1fr));
        gap:8px;
    }
    .assignment-drawer-field {
        border:1px solid #e2e8f0;
        border-radius:10px;
        padding:8px 10px;
        background:#fff;
    }
    .assignment-drawer-field span {
        display:block;
        color:#64748b;
        font-size:10px;
        font-weight:800;
        letter-spacing:.05em;
        text-transform:uppercase;
    }
    .assignment-drawer-field strong {
        display:block;
        margin-top:3px;
        color:#0f172a;
        font-size:13px;
        line-height:1.2;
    }
    .assignment-drawer-footer {
        display:flex;
        justify-content:flex-end;
        gap:10px;
        padding:12px 18px;
        border-top:1px solid #dbe3ef;
        background:#fff;
        box-shadow:0 -8px 24px rgba(15, 23, 42, .06);
    }
    .assignment-toast {
        position:fixed;
        right:24px;
        bottom:24px;
        z-index:90;
        padding:10px 14px;
        border-radius:12px;
        background:#0f766e;
        color:#fff;
        font-size:13px;
        font-weight:800;
        box-shadow:0 16px 34px rgba(15, 118, 110, .22);
    }
    .assignment-toast[hidden] {
        display:none !important;
    }
    .rental-stock-modal {
        position:fixed;
        inset:0;
        z-index:9999;
        display:none;
        justify-content:flex-end;
        background:rgba(15, 23, 42, .34);
        backdrop-filter:blur(2px);
    }
    #rentalStockModal[hidden] {
        display:none !important;
    }
    #rentalStockModal.is-open {
        display:flex !important;
        position:fixed;
        inset:0;
        z-index:9999;
    }
    .rental-stock-modal.is-open,
    .rental-stock-modal:target {
        display:flex !important;
    }
    .rental-stock-sheet {
        width:min(560px, 100vw);
        height:100vh;
        display:grid;
        grid-template-rows:auto 1fr auto;
        background:#fff;
        box-shadow:-22px 0 48px rgba(15, 23, 42, .2);
    }
    .rental-stock-header {
        display:flex;
        align-items:flex-start;
        justify-content:space-between;
        gap:12px;
        padding:16px 18px;
        border-bottom:1px solid #dbe3ef;
    }
    .rental-stock-header h3 { margin:0; font-size:20px; color:#0f172a; }
    .rental-stock-header p { margin:3px 0 0; color:#64748b; font-size:13px; line-height:1.35; }
    .rental-stock-body {
        min-height:0;
        overflow:auto;
        padding:16px 18px;
    }
    .rental-stock-grid {
        display:grid;
        grid-template-columns:repeat(12, minmax(0, 1fr));
        gap:12px;
    }
    .rental-stock-col-6 { grid-column:span 6; }
    .rental-stock-col-12 { grid-column:span 12; }
    .rental-stock-alert {
        margin-bottom:12px;
        padding:10px 12px;
        border:1px solid #fed7aa;
        border-radius:12px;
        background:#fff7ed;
        color:#9a3412;
        font-size:13px;
        font-weight:700;
        line-height:1.35;
    }
    .rental-stock-alert.is-error {
        border-color:#fecaca;
        background:#fef2f2;
        color:#991b1b;
    }
    .rental-stock-footer {
        display:flex;
        justify-content:flex-end;
        gap:10px;
        padding:12px 18px;
        border-top:1px solid #dbe3ef;
        background:#fff;
        box-shadow:0 -8px 24px rgba(15, 23, 42, .06);
    }
    body.assignment-drawer-open {
        overflow:hidden;
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
        overflow:visible;
        max-width:100%;
    }
    .sale-item-panel.is-rental-items { overflow:visible; }
    .sale-item-head,
    .sale-item-row {
        display:grid;
        grid-template-columns:minmax(0, 2fr) minmax(70px, .45fr) minmax(100px, .7fr) minmax(90px, .65fr) minmax(130px, .8fr);
        gap:10px;
        align-items:start;
        padding:12px 14px;
        max-width:100%;
        box-sizing:border-box;
    }
    .sale-item-panel.is-sale-items .sale-item-head,
    .sale-item-panel.is-sale-items .sale-item-row {
        grid-template-columns:minmax(0, 1.7fr) minmax(0, 1.2fr) minmax(70px, .45fr) minmax(105px, .7fr) minmax(130px, .8fr);
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
    .rental-line-heading {
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:10px;
        padding:10px 14px 0;
        color:#0f172a;
        font-weight:800;
        font-size:13px;
    }
    .rental-line-heading span {
        color:#64748b;
        font-size:11px;
        font-weight:700;
        text-transform:uppercase;
        letter-spacing:.04em;
    }
    .rental-grid .rental-line-heading {
        padding:0;
        grid-column:1 / -1;
    }
    .sale-item-row select,
    .sale-item-row input,
    .sale-item-row textarea {
        width:100%;
        min-width:0;
        border:1px solid #cbd5e1;
        border-radius:10px;
        padding:9px 10px;
        font-size:13px;
        box-sizing:border-box;
        background:#fff;
        color:#0f172a;
    }
    .sale-item-row textarea { min-height:42px; resize:vertical; }
    .rental-line-subsection { margin-top:2px; }
    .rental-line-heading.is-sub {
        padding:6px 0 2px;
        border-top:1px dashed #dbe3ef;
    }
    .sale-item-field-block { display:flex; flex-direction:column; gap:4px; min-width:0; }
    .sale-item-field-block label,
    .sale-item-total span,
    .rental-line-asset-summary span {
        font-size:10px;
        font-weight:800;
        color:#64748b;
        text-transform:uppercase;
        letter-spacing:.04em;
    }
    .rental-product-line-card { background:#fff; }
    .rental-line-row { grid-template-columns:minmax(0, 2.1fr) minmax(74px, .45fr) minmax(120px, .75fr) minmax(110px, .7fr) minmax(145px, .85fr); }
    .sale-item-total { display:flex; flex-direction:column; gap:4px; color:#0f172a; font-weight:800; }
    .sale-item-total strong { font-size:15px; }
    .rental-line-detail-grid { grid-template-columns:minmax(0, 2fr) repeat(3, minmax(120px, .7fr)) minmax(0, 1.3fr); }
    .rental-line-asset-field { grid-column:1 / -1; }
    .rental-line-gst-collapse { grid-column:1 / -1; }
    .rental-line-gst-collapse .gst-details-grid { grid-template-columns:repeat(3, minmax(0, 1fr)); }
    .rental-line-remove-button {
        margin-left:auto;
        min-height:28px;
        padding:4px 10px;
        border:1px solid #fecaca;
        border-radius:999px;
        background:#fff;
        color:#b91c1c;
        font-size:11px;
        font-weight:800;
        cursor:pointer;
    }
    .rental-line-remove-button:hover { background:#fef2f2; border-color:#fca5a5; }
    .rental-line-asset-summary { display:flex; gap:8px; align-items:center; margin-bottom:8px; }
    .rental-line-asset-summary strong { color:#15803d; font-size:13px; }
    .rental-summary-row.is-total { border-top:1px solid #dbe3ef; padding-top:10px; margin-top:4px; }
    .rental-summary-row.is-total strong { color:#4f46e5; font-size:18px; }
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
    .rental-line-asset-option-text {
        display:flex;
        flex-direction:column;
        gap:2px;
        min-width:0;
    }
    .rental-line-asset-option-title {
        font-weight:600;
        color:#1e293b;
        word-break:break-word;
    }
    .rental-line-asset-option-meta {
        color:#64748b;
        word-break:break-word;
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
        min-width:0;
    }
    .sale-item-empty {
        padding:18px 14px;
        color:#64748b;
        font-size:13px;
        text-align:center;
        background:#fff;
    }
    .sale-item-summary {
        display:grid;
        grid-template-columns:1fr;
        align-items:center;
        gap:10px;
        margin-top:12px;
        padding:12px 14px;
        border:1px solid #e2e8f0;
        border-radius:12px;
        background:#f8fafc;
        font-size:13px;
        color:#475569;
        overflow:visible;
        max-width:100%;
        box-sizing:border-box;
    }
    .sale-item-summary > div {
        min-width:0;
    }
    .sale-item-summary .sale-item-actions {
        justify-content:stretch;
    }
    .sale-item-summary .sale-item-actions .ops-button-secondary {
        width:100%;
    }
    .add-line-cta.is-header {
        min-height:36px;
        padding:8px 14px;
        white-space:nowrap;
    }
    .ops-button-secondary.add-line-cta {
        background:#4f46e5;
        border-color:#4f46e5;
        color:#fff;
        box-shadow:0 10px 22px rgba(79, 70, 229, 0.22);
        font-weight:800;
        max-width:100%;
        box-sizing:border-box;
    }
    .ops-button-secondary.add-line-cta:hover,
    .ops-button-secondary.add-line-cta:focus {
        background:#4338ca;
        border-color:#4338ca;
        color:#fff;
        box-shadow:0 12px 26px rgba(79, 70, 229, 0.28);
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
    @media (max-width: 1280px) {
        .sale-item-panel .sale-item-head { display:none; }
        .sale-item-row,
        .sale-item-panel.is-sale-items .sale-item-row {
            grid-template-columns:repeat(2, minmax(0, 1fr));
        }
        .sale-item-actions {
            justify-content:stretch;
        }
        .sale-item-actions .ops-button-secondary {
            flex:1 1 140px;
        }
        .sale-item-total {
            min-height:34px;
        }
    }
    @media (max-width: 980px) {
        .rental-wizard-layout { grid-template-columns:1fr; }
        .rental-order-summary { position:static; order:-1; }
        .rental-wizard-nav {
            display:flex;
            overflow-x:auto;
            scrollbar-width:none;
        }
        .rental-wizard-nav::-webkit-scrollbar { display:none; }
        .rental-wizard-tab { flex:0 0 190px; }
        .rental-col-3,
        .rental-col-4,
        .rental-col-5,
        .rental-col-6,
        .rental-col-7,
        .rental-col-8 { grid-column:span 12; }
        .rental-summary { grid-template-columns:repeat(2, minmax(0, 1fr)); }
        .rental-availability-panel { grid-template-columns:repeat(2, minmax(0, 1fr)); }
        .asset-summary-grid { grid-template-columns:repeat(2, minmax(0, 1fr)); }
        .assignment-launch-grid { grid-template-columns:repeat(2, minmax(0, 1fr)); }
        .sale-item-head { display:none; }
        .sale-item-row { grid-template-columns:repeat(2, minmax(0, 1fr)); }
        .sale-item-detail-grid { grid-template-columns:1fr; }
        .sale-section-disclosure summary { flex-direction:column; }
        .sale-section-meta { justify-content:flex-start; }
        .compact-section-summary { justify-content:flex-start; }
        .rental-inline-stack { flex-wrap:wrap; }
    }
    @media (max-width: 640px) {
        .mobile-topbar-search {
            display:none !important;
        }
        .mobile-back-row {
            min-height:0 !important;
            margin:-8px 0 0 !important;
            padding:0 !important;
        }
        .mobile-back-link {
            min-height:28px !important;
            padding:5px 10px !important;
            font-size:12px !important;
        }
        .rental-shell {
            gap:4px;
            padding-bottom:calc(176px + env(safe-area-inset-bottom, 0px));
        }
        .rental-wizard-nav {
            display:none !important;
        }
        .rental-mobile-progress {
            display:flex;
            align-items:center;
            gap:8px;
            max-height:none;
            overflow-x:auto;
            padding:4px 2px 5px;
            scrollbar-width:none;
        }
        .rental-mobile-progress::-webkit-scrollbar { display:none; }
        .rental-step-actions,
        .rental-toolbar.rental-wizard-step[data-rental-wizard-step="review"] {
            display:none !important;
        }
        .rental-toolbar {
            display:none;
            flex-direction:column;
            margin-bottom:0;
            align-items:stretch;
            gap:2px;
        }
        .rental-title h1 {
            font-size:17px;
            line-height:1.15;
        }
        .rental-title p {
            display:none;
        }
        .rental-error {
            padding:9px 10px;
            border-radius:10px;
            font-size:12px;
        }
        .rental-error ul {
            max-height:80px;
            overflow:auto;
        }
        .rental-actions {
            width:100%;
        }
        .rental-toolbar > .rental-actions > a[href*="/rentals"]:first-child {
            display:none;
        }
        .rental-actions > * {
            flex:1 1 calc(50% - 8px);
            min-width:0;
        }
        .rental-summary { grid-template-columns:repeat(2, minmax(0, 1fr)); }
        .rental-wizard-layout,
        .rental-wizard-main {
            gap:8px;
        }
        .rental-card {
            padding:10px;
            border-radius:12px;
        }
        .rental-card h2 {
            font-size:15px;
            margin-bottom:2px;
        }
        .rental-card p.section-copy {
            margin-bottom:7px;
            font-size:10px;
            line-height:1.25;
        }
        .rental-grid {
            gap:7px;
        }
        .rental-field {
            gap:3px;
        }
        .rental-field label {
            font-size:10px;
        }
        .rental-field-label-row {
            align-items:center;
            gap:6px;
        }
        .rental-stock-inline-button {
            min-height:26px;
            padding:5px 9px;
            font-size:11px;
        }
        .rental-stock-inline-button .desktop-label { display:none; }
        .rental-stock-inline-button .mobile-label { display:inline; }
        .rental-field input,
        .rental-field select,
        .rental-field textarea,
        .searchable-select-trigger {
            min-height:38px;
            padding:7px 9px;
            border-radius:10px;
            font-size:13px;
        }
        .rental-field textarea {
            min-height:54px;
        }
        .rental-line-heading {
            padding:0;
            font-size:12px;
        }
        .rental-line-heading span {
            font-size:10px;
        }
        .rental-availability-panel {
            grid-template-columns:repeat(2, minmax(0, 1fr));
            gap:6px;
            padding:7px;
        }
        .rental-availability-panel div {
            padding:7px;
        }
        .rental-order-summary {
            order:-1;
            position:static;
            padding:0;
            border-radius:10px;
            box-shadow:none;
        }
        .rental-order-summary summary {
            align-items:center;
        }
        .rental-order-summary h2 {
            font-size:14px;
        }
        .rental-summary-list {
            gap:5px;
            padding-top:6px;
        }
        .rental-summary-row {
            grid-template-columns:80px minmax(0, 1fr);
            gap:6px;
            font-size:11px;
        }
        .rental-summary-row span {
            font-size:9px;
        }
        .rental-review-grid {
            grid-template-columns:1fr;
            gap:6px;
        }
        .rental-review-box {
            padding:7px;
        }
        .asset-toolbar { align-items:stretch; }
        .asset-toolbar-actions { justify-content:stretch; margin-left:0; }
        .asset-toolbar-actions .ops-button-secondary { flex:1 1 140px; }
        .asset-summary-grid {
            grid-template-columns:repeat(2, minmax(0, 1fr));
            gap:7px;
        }
        .asset-summary-box {
            padding:8px;
            min-height:auto;
        }
        .asset-summary-box strong { font-size:14px; }
        .asset-summary-box small { font-size:11px; }
        .asset-panel {
            padding:10px;
        }
        .asset-warning,
        .field-warning,
        .rental-inline-note {
            padding:8px 10px;
            border-radius:10px;
            font-size:11px;
            line-height:1.35;
        }
        .field-error {
            margin-top:4px;
            font-size:11px;
            line-height:1.25;
        }
        .asset-search { width:100%; }
        .assignment-launch-card {
            grid-template-columns:1fr;
            padding:10px;
            gap:8px;
        }
        .assignment-asset-summary,
        .assignment-drawer-fields,
        .assignment-type-grid { grid-template-columns:1fr; }
        .assignment-launch-grid {
            grid-template-columns:repeat(2, minmax(0, 1fr));
            gap:6px;
        }
        .assignment-launch-title {
            margin-bottom:6px;
        }
        .assignment-launch-title strong {
            font-size:14px;
        }
        .assignment-launch-metric {
            padding:7px 8px;
        }
        .assignment-drawer-backdrop {
            align-items:stretch;
            justify-content:stretch;
            z-index:2200;
        }
        .assignment-drawer {
            width:100vw;
            height:100dvh;
            max-height:100dvh;
            overflow:hidden;
        }
        .rental-stock-modal {
            z-index:2300;
            align-items:flex-end;
            justify-content:stretch;
        }
        .rental-stock-sheet {
            width:100vw;
            height:auto;
            max-height:calc(100dvh - 12px);
            border-radius:18px 18px 0 0;
            grid-template-rows:auto minmax(0, 1fr) auto;
        }
        .rental-stock-header {
            padding:13px 14px;
        }
        .rental-stock-header h3 {
            font-size:18px;
        }
        .rental-stock-body {
            padding:12px 14px;
        }
        .rental-stock-grid {
            grid-template-columns:1fr;
            gap:9px;
        }
        .rental-stock-col-6,
        .rental-stock-col-12 {
            grid-column:span 1;
        }
        .rental-stock-footer {
            padding:10px 14px calc(10px + env(safe-area-inset-bottom));
        }
        .rental-stock-footer .ops-button,
        .rental-stock-footer .ops-button-secondary {
            flex:1 1 0;
            min-height:42px;
        }
        .assignment-drawer-header {
            padding:10px 12px 8px;
        }
        .assignment-drawer-title {
            margin-bottom:8px;
        }
        .assignment-drawer-title h3 {
            font-size:17px;
        }
        .assignment-asset-summary {
            grid-template-columns:repeat(2, minmax(0, 1fr));
            gap:6px;
        }
        .assignment-asset-summary div {
            padding:7px 8px;
        }
        .assignment-drawer-body {
            min-height:0;
            padding:10px 12px 12px;
            gap:9px;
            overflow-y:auto;
            overscroll-behavior:contain;
        }
        .assignment-drawer-footer {
            position:sticky;
            bottom:0;
            z-index:2;
            padding:8px 12px calc(10px + env(safe-area-inset-bottom, 0px));
            gap:8px;
            box-shadow:0 -10px 26px rgba(15, 23, 42, .12);
        }
        .assignment-drawer-footer .ops-button,
        .assignment-drawer-footer .ops-button-secondary {
            flex:1 1 0;
            min-height:42px;
        }
        .quick-col-4,
        .quick-col-8 { grid-column:span 12; }
        .quick-inline-grid { grid-template-columns:1fr; }
        .sale-item-row { grid-template-columns:1fr; }
        .rental-snapshot-card { display:none; }
        .rental-card { border-radius:12px !important; padding:10px; }
        .rental-card h2 { font-size:15px !important; }
        .rental-wizard-nav {
            position:sticky;
            top:68px;
            z-index:24;
            gap:5px;
            padding:4px;
            margin:0 -4px 8px;
            min-height:36px;
            border-radius:12px;
            background:rgba(255, 255, 255, 0.96);
            box-shadow:0 10px 26px rgba(15, 23, 42, 0.08);
        }
        .rental-wizard-tab {
            flex:0 0 auto;
            min-height:28px;
            padding:4px 7px;
            gap:4px;
            border-radius:999px;
        }
        .rental-wizard-tab span:first-child {
            width:18px;
            height:18px;
            font-size:11px;
        }
        .rental-wizard-tab strong {
            font-size:0;
        }
        .rental-wizard-tab[data-rental-wizard-tab="fulfilment"] strong::after { content:"Customer"; font-size:11px; }
        .rental-wizard-tab[data-rental-wizard-tab="product"] strong::after { content:"Asset"; font-size:11px; }
        .rental-wizard-tab[data-rental-wizard-tab="dates"] strong::after { content:"Charges"; font-size:11px; }
        .rental-wizard-tab[data-rental-wizard-tab="delivery"] strong::after { content:"Delivery"; font-size:11px; }
        .rental-wizard-tab[data-rental-wizard-tab="review"] strong::after { content:"Review"; font-size:11px; }
        .rental-order-summary details {
            border-radius:10px;
        }
        .rental-order-summary summary {
            min-height:30px;
            padding:5px 9px;
        }
        .rental-order-summary summary h2 {
            font-size:12px;
        }
        .rental-order-summary details:not([open]) {
            margin-bottom:4px;
        }
        .rental-order-summary details:not([open]) summary::after {
            content:attr(data-mobile-summary);
            margin-left:auto;
            color:#64748b;
            font-size:10px;
            font-weight:800;
        }
        .rental-summary-list {
            padding:8px 10px 10px;
            gap:6px;
        }
        .rental-summary-row { grid-template-columns:80px minmax(0, 1fr); }
        .rental-review-grid { grid-template-columns:1fr; }
        .rental-availability-panel { grid-template-columns:repeat(2, minmax(0, 1fr)); }
        .rental-grid {
            gap:7px;
        }
        .rental-field label {
            margin-bottom:2px;
            font-size:10px;
        }
        .rental-field input,
        .rental-field select,
        .rental-field textarea {
            min-height:38px;
            padding:7px 9px;
            border-radius:10px;
            font-size:13px;
        }
        .rental-field textarea {
            min-height:54px;
        }
        .sale-item-summary { grid-template-columns:1fr; align-items:stretch; }
        .sale-item-summary .sale-item-actions { justify-content:stretch; }
        .sale-item-summary .sale-item-actions .ops-button-secondary { width:100%; }
        .asset-toolbar,
        .asset-toolbar-actions { flex-direction:column; align-items:stretch; }
        .assignment-drawer-body {
            padding:8px 10px 78px;
            gap:8px;
        }
        .assignment-section-label {
            margin-bottom:4px;
            font-size:10px;
            text-transform:uppercase;
            letter-spacing:.05em;
            color:#64748b;
        }
        .assignment-type-grid {
            display:flex !important;
            gap:6px;
            overflow-x:auto;
            padding-bottom:1px;
            scrollbar-width:none;
        }
        .assignment-type-grid::-webkit-scrollbar {
            display:none;
        }
        .assignment-type-card {
            min-height:34px;
            padding:6px 10px;
            border-radius:999px;
            font-size:12px;
            flex:0 0 auto;
        }
        .assignment-type-card input {
            width:14px;
            height:14px;
        }
        .assignment-type-card.is-disabled {
            display:none;
        }
        .assignment-drawer-fields {
            grid-template-columns:repeat(2, minmax(0, 1fr));
            gap:6px;
        }
        .assignment-drawer-field {
            padding:7px 8px;
            border-radius:9px;
        }
        .assignment-drawer-field span {
            font-size:9px;
        }
        .assignment-drawer-field strong {
            font-size:12px;
        }
        .asset-panel {
            padding:8px;
            border-radius:10px;
        }
        .asset-toolbar {
            gap:6px;
            margin-bottom:7px;
        }
        .asset-toolbar > div:first-child {
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:8px;
        }
        .asset-toolbar .badge {
            font-size:10px;
            padding:4px 8px;
        }
        .asset-toolbar-actions {
            display:grid;
            grid-template-columns:1fr 1fr;
            gap:6px;
            margin-left:0;
        }
        .asset-toolbar-actions .ops-button-secondary {
            min-height:34px;
            padding:7px 8px;
            border-radius:9px;
            font-size:12px;
            flex:initial;
        }
        .asset-toolbar-actions .asset-search {
            grid-column:1 / -1;
        }
        .asset-search {
            min-height:36px;
            padding:7px 9px;
            border-radius:9px;
            font-size:12px;
        }
        .asset-summary-grid {
            grid-template-columns:repeat(4, minmax(0, 1fr));
            gap:5px;
            margin-bottom:7px;
        }
        .asset-summary-box {
            padding:6px;
            border-radius:9px;
            gap:2px;
        }
        .asset-summary-box span {
            font-size:8px;
            letter-spacing:.03em;
        }
        .asset-summary-box strong {
            font-size:12px;
        }
        .asset-summary-box small {
            display:none;
        }
        .asset-warning {
            padding:7px 9px;
            font-size:11px;
            border-radius:9px;
            margin-bottom:7px;
        }
        .rental-inline-stack { flex-direction:column; align-items:stretch; }
        .rental-inline-stack > * { width:100%; }
        .asset-grid {
            grid-template-columns:1fr;
            gap:6px;
        }
        .asset-card {
            padding:8px;
            gap:4px;
            border-radius:10px;
        }
        .asset-card-head {
            align-items:center;
        }
        .asset-card strong {
            font-size:13px;
            line-height:1.25;
        }
        .asset-card small {
            font-size:11px;
            line-height:1.25;
        }
        .asset-card-meta-extra {
            display:none;
        }
        .asset-card-pill {
            min-width:auto;
            padding:3px 8px;
            font-size:10px;
        }
        .asset-empty {
            padding:10px;
            border-radius:9px;
            font-size:12px;
        }
        .asset-load-more {
            margin-top:6px;
        }
        .asset-load-more .ops-button-secondary {
            width:100%;
            min-height:34px;
            padding:7px 9px;
            font-size:12px;
        }
        .rental-mobile-action-bar {
            position:fixed;
            left:10px;
            right:10px;
            bottom:calc(var(--ph-mobile-nav-height, 74px) + 8px + env(safe-area-inset-bottom, 0px));
            z-index:70;
            display:grid;
            grid-template-columns:minmax(0, 1fr) auto;
            gap:6px;
            align-items:center;
            min-height:52px;
            max-height:56px;
            padding:6px 8px;
            border:1px solid #dbe3ef;
            border-radius:14px;
            background:#fff;
            box-shadow:0 18px 44px rgba(15, 23, 42, 0.18);
        }
        .rental-mobile-action-bar.is-ready,
        .rental-mobile-action-bar.is-asset-selected {
            border-color:#86efac;
            background:#f7fff9;
        }
        .rental-mobile-action-state strong {
            display:block;
            font-size:12px;
            line-height:1.2;
            color:#0f172a;
        }
        .rental-mobile-action-state span {
            display:block;
            margin-top:1px;
            font-size:10px;
            line-height:1.15;
            color:#64748b;
            white-space:nowrap;
            overflow:hidden;
            text-overflow:ellipsis;
            max-width:132px;
        }
        .rental-mobile-action-buttons {
            display:flex;
            gap:5px;
            align-items:center;
        }
        .rental-mobile-action-buttons .ops-button,
        .rental-mobile-action-buttons .ops-button-secondary {
            min-height:38px;
            padding:8px 10px;
            border-radius:11px;
            white-space:nowrap;
            font-size:12px;
        }
        .rental-mobile-action-buttons [hidden] {
            display:none !important;
        }
        .ops-button,
        .ops-button-secondary {
            min-height:44px;
            white-space:normal;
            text-align:center;
        }
        .rental-mobile-action-buttons .ops-button,
        .rental-mobile-action-buttons .ops-button-secondary {
            min-height:38px;
            white-space:nowrap;
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
            <strong>Rental could not be saved because:</strong>
            <ul style="margin:8px 0 0 18px; padding:0;">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="rental-wizard-nav" aria-label="Rental creation steps">
        <button type="button" class="rental-wizard-tab is-active" data-rental-wizard-tab="fulfilment"><span>1</span><strong>Fulfilment & Customer</strong></button>
        <button type="button" class="rental-wizard-tab" data-rental-wizard-tab="product"><span>2</span><strong>Products & Assets</strong></button>
        <button type="button" class="rental-wizard-tab" data-rental-wizard-tab="dates"><span>3</span><strong>Charges</strong></button>
        <button type="button" class="rental-wizard-tab" data-rental-wizard-tab="delivery"><span>4</span><strong>Delivery & Pickup</strong></button>
        <button type="button" class="rental-wizard-tab" data-rental-wizard-tab="review"><span>5</span><strong>Review & Create</strong></button>
    </div>
    <div class="rental-mobile-progress" id="rentalMobileProgress" aria-label="Rental creation progress">
        <button type="button" data-rental-mobile-step="customer"><span>1</span>Customer</button>
        <button type="button" data-rental-mobile-step="product"><span>2</span>Products</button>
        <button type="button" data-rental-mobile-step="asset"><span>3</span>Asset</button>
        <button type="button" data-rental-mobile-step="pricing"><span>4</span>Charges</button>
        <button type="button" data-rental-mobile-step="delivery"><span>5</span>Delivery</button>
        <button type="button" data-rental-mobile-step="review"><span>6</span>Review</button>
    </div>

    <div class="rental-wizard-layout">
        <div class="rental-wizard-main">
    <section class="rental-card rental-wizard-step is-active" data-rental-wizard-step="fulfilment">
        <h2>Fulfilment & Customer</h2>
        <p class="section-copy">Select fulfilment and city.</p>
        <div class="rental-grid">
            <div class="rental-col-12 section-nav-target" id="rental-fulfilment-section">
                <div class="rental-grid" style="margin-bottom:10px;">
                    <div class="rental-field rental-col-6{{ $hasFieldError('fulfilment_source') ? ' is-error' : '' }}">
                        <label for="fulfilment_source">Step 1 - Fulfilment Source</label>
                        <select name="fulfilment_source" id="fulfilment_source">
                            <option value="in_house" {{ $selectedFulfilmentSource === 'in_house' ? 'selected' : '' }}>In-house Stock</option>
                            <option value="vendor_supplied" {{ $selectedFulfilmentSource === 'vendor_supplied' ? 'selected' : '' }}>Vendor Supplied</option>
                        </select>
                        <span class="hint"></span>
                        @if($hasFieldError('fulfilment_source'))
                            <span class="field-error">{{ $fieldError('fulfilment_source') }}</span>
                        @endif
                    </div>
                    <div class="rental-field rental-col-6{{ $hasFieldError('city_id') ? ' is-error' : '' }}">
                        <label for="city_id">Step 2 - City</label>
                        <select name="city_id" id="city_id">
                            <option value="">Select city</option>
                            @foreach($cities as $city)
                                <option value="{{ $city->id }}" {{ $selectedCityId === (int) $city->id ? 'selected' : '' }}>
                                    {{ $city->name }}{{ $city->state ? ' - ' . $city->state : '' }}
                                </option>
                            @endforeach
                        </select>
                        <span class="hint">City filters options.</span>
                        @if($hasFieldError('city_id'))
                            <span class="field-error">{{ $fieldError('city_id') }}</span>
                        @endif
                    </div>
                </div>
            </div>
            <div class="rental-col-12 party-flow-shell section-nav-target" id="rental-customer-section">
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
                                        data-search="{{ trim(implode(' - ', array_filter([$customer->name, $customer->phone, $customer->email, $customer->city]))) }}"
                                        {{ (int) $selectedCustomerId === $customer->id ? 'selected' : '' }}>
                                        {{ $customer->name }}{{ $customer->phone ? ' - ' . $customer->phone : '' }}
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
                                        data-search="{{ trim(implode(' - ', array_filter([$partner->displayName(), $partner->contact_person, $partner->phone, $partner->email, $partner->city, $partner->state]))) }}"
                                        {{ $selectedBusinessPartnerId === $partner->id ? 'selected' : '' }}>
                                        {{ $partner->displayName() }}{{ $partner->phone ? ' - ' . $partner->phone : '' }}
                                    </option>
                                @endforeach
                            </select>
                            @if($hasFieldError('business_partner_id'))
                                <span class="field-error">{{ $fieldError('business_partner_id') }}</span>
                            @endif
                        </div>
                        <button type="button" class="party-flow-link" data-open-modal="rentalBusinessPartnerModal">+ Add</button>
                    </div>
                    <div class="partner-billing-note" data-customer-mode-block="business_partner">Partner is billed; actual client receives delivery.</div>

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
                                        data-search="{{ trim(implode(' - ', array_filter([$client->displayName(), $client->primaryPhone(), $client->address, $client->city, $client->state]))) }}"
                                        {{ $selectedPartnerClientId === $client->id ? 'selected' : '' }}>
                                        {{ $client->displayName() }}{{ $client->primaryPhone() ? ' - ' . $client->primaryPhone() : '' }}
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
                                    {{ $selectedBusinessPartner ? collect([$selectedBusinessPartner->displayName(), $selectedBusinessPartner->phone])->filter()->implode(' - ') : 'Select a business partner' }}
                                @else
                                    {{ $selectedCustomer ? collect([$selectedCustomer->name, \App\Support\PhoneNumber::local($selectedCustomer->phone) ?: $selectedCustomer->phone])->filter()->implode(' - ') : 'Select a customer' }}
                                @endif
                            </strong>
                        </div>
                        <div class="party-flow-line" id="deliveryContactLine"{{ $customerTypeValue === 'business_partner' ? '' : ' hidden' }}>
                            <label id="deliveryContactLabel">Delivery / Pickup Contact</label>
                            <strong id="deliveryContactSummary">
                                {{ $selectedPartnerClient ? collect([$selectedPartnerClient->displayName(), \App\Support\PhoneNumber::local($selectedPartnerClient->primaryPhone()) ?: $selectedPartnerClient->primaryPhone()])->filter()->implode(' - ') : 'Select an actual client' }}
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

            <div class="rental-field rental-col-3{{ $hasFieldError('referral_source_type') ? ' is-error' : '' }}">
                <label for="referral_source_type">Referred By Type</label>
                @php($referralSourceType = old('referral_source_type', ($isEdit && $hasReferralSourceTypeColumn) ? ($rental->referral_source_type ?? '') : ''))
                <select name="referral_source_type" id="referral_source_type">
                    <option value="">No referral</option>
                    <option value="doctor" {{ $referralSourceType === 'doctor' ? 'selected' : '' }}>Doctor</option>
                    <option value="hospital" {{ $referralSourceType === 'hospital' ? 'selected' : '' }}>Hospital</option>
                    <option value="business_partner" {{ $referralSourceType === 'business_partner' ? 'selected' : '' }}>Business Partner</option>
                    <option value="customer_referral" {{ $referralSourceType === 'customer_referral' ? 'selected' : '' }}>Customer Referral</option>
                    <option value="employee" {{ $referralSourceType === 'employee' ? 'selected' : '' }}>Employee</option>
                    <option value="digital_marketing" {{ $referralSourceType === 'digital_marketing' ? 'selected' : '' }}>Digital Marketing</option>
                    <option value="walk_in" {{ $referralSourceType === 'walk_in' ? 'selected' : '' }}>Walk-in</option>
                    <option value="other" {{ $referralSourceType === 'other' ? 'selected' : '' }}>Other</option>
                </select>
                @if($hasFieldError('referral_source_type'))
                    <span class="field-error">{{ $fieldError('referral_source_type') }}</span>
                @endif
            </div>

            <div class="rental-field rental-col-3{{ $hasFieldError('referral_source_id', 'referred_by') ? ' is-error' : '' }}">
                <label for="referred_by">Referred By</label>
                @php($selectedReferralName = old('referred_by', ($isEdit && $hasReferredByColumn) ? ($rental->referred_by ?? '') : ''))
                @php($selectedReferralSourceId = (int) old('referral_source_id', ($isEdit && \Illuminate\Support\Facades\Schema::hasColumn('rentals', 'referral_source_id')) ? ($rental->referral_source_id ?? 0) : 0))
                <select name="referral_source_id" id="referred_by" data-searchable-select data-search-placeholder="Search referral source">
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
                <input type="hidden" name="referred_by" id="referred_by_name" value="{{ $selectedReferralName }}">
                <a href="{{ route('referral-sources.create') }}" target="_blank" rel="noopener" class="hint" style="font-weight:700; color:#2563eb; text-decoration:none;">+ Add referral source</a>
                <span class="hint">{{ $hasReferredByColumn ? 'Used for referral tracking and incentive review.' : 'Referral tracking needs the pending migration before it can be saved.' }}</span>
                @if($hasFieldError('referred_by'))
                    <span class="field-error">{{ $fieldError('referred_by') }}</span>
                @endif
            </div>

            <div class="rental-field rental-col-3{{ $hasFieldError('referral_contact') ? ' is-error' : '' }}">
                <label for="referral_contact">Referral Contact</label>
                <input type="text" name="referral_contact" id="referral_contact" value="{{ old('referral_contact', ($isEdit && $hasReferralContactColumn) ? ($rental->referral_contact ?? '') : '') }}" placeholder="Phone or email">
                @if($hasFieldError('referral_contact'))
                    <span class="field-error">{{ $fieldError('referral_contact') }}</span>
                @endif
            </div>

            <div class="rental-field rental-col-3{{ $hasFieldError('referral_city') ? ' is-error' : '' }}">
                <label for="referral_city">Referral City</label>
                <input type="text" name="referral_city" id="referral_city" value="{{ old('referral_city', ($isEdit && $hasReferralCityColumn) ? ($rental->referral_city ?? '') : '') }}" placeholder="City">
                @if($hasFieldError('referral_city'))
                    <span class="field-error">{{ $fieldError('referral_city') }}</span>
                @endif
            </div>

            <input type="hidden" name="customer_name" id="customer_name" value="{{ old('customer_name', $isEdit ? $rental->customer_name : ($selectedCustomer?->name ?? '')) }}">
            <input type="hidden" name="phone_country_code" id="phone_country_code" value="{{ old('phone_country_code', $phoneParts['code']) }}">
            <input type="hidden" name="phone" id="phone" value="{{ $phoneParts['local'] }}">

        </div>
        <div class="rental-step-actions">
            <span class="rental-draft-status" data-rental-draft-status></span>
            <button type="button" class="ops-button" data-rental-wizard-next="product">Next: Products & Assets</button>
        </div>
    </section>

    <section class="rental-card rental-wizard-step" data-rental-wizard-step="product">
        <div class="rental-step-header">
            <div>
                <h2 class="rental-icon-title" data-icon="P">Products & Assets</h2>
                <p class="section-copy">Select product, stock, dates, price, and assets.</p>
            </div>
            <div class="rental-step-header-actions">
                <button type="button" class="ops-button-secondary add-line-cta" data-add-rental-item>+ Add Rental Product</button>
            </div>
        </div>
        <div class="primary-rental-product-card">
        <div class="rental-grid rental-product-setup-grid"><div class="rental-line-heading">
                <strong>Rental Item #1</strong>
                <span>Primary</span>
            </div>
            <div class="rental-field rental-col-5{{ $hasFieldError('product_id', 'rental_items') ? ' is-error' : '' }} section-nav-target" id="rental-product-section">
                <div class="rental-product-label-row">
                    <label for="product_id">Product</label>
                    @if($canCreateRentalStock)
                        <button type="button" class="rental-stock-inline-button" id="openRentalStockModal" data-open-rental-stock-modal data-add-rental-stock-trigger>
                            + <span class="desktop-label">Add Rental Stock</span><span class="mobile-label">Stock</span>
                        </button>
                    @endif
                </div>
                <select name="product_id" id="product_id" required data-searchable-select data-search-placeholder="Search product by name, brand, model, SKU, or code">
                    <option value="">Select rental product</option>
                    @foreach($rentalProducts as $product)
                        <option
                            value="{{ $product->id }}"
                            data-available="{{ $product->rental_available_quantity ?? $product->display_available_quantity ?? $product->available_quantity }}"
                            data-rental-available="{{ $product->rental_available_quantity ?? $product->display_available_quantity ?? $product->available_quantity }}"
                            data-rental-status="{{ $product->rental_availability_status }}"
                            data-rental-label="{{ $product->rental_dropdown_label }}"
                            data-in-house-option-label="{{ $product->name }} - {{ $product->rental_dropdown_label ?? ('Rental Available ' . ($product->display_available_quantity ?? $product->available_quantity)) }}"
                            data-vendor-option-label="{{ $product->name }}"
                            data-rental-warehouse-quantities="{{ e(json_encode($product->rental_warehouse_quantities ?? [])) }}"
                            data-tracks-rental="{{ $product->tracksRentalStock() ? 1 : 0 }}"
                            data-product-name="{{ $product->name }}"
                            data-product-image-url="{{ $product->product_image_url }}"
                            data-product-brand="{{ $product->brand }}"
                            data-product-model="{{ $product->model_name ?? $product->display_model }}"
                            data-product-sku="{{ $product->sku }}"
                            data-product-code="{{ $product->product_code }}"
                            data-price-per-day="{{ (float) ($product->price_per_day ?? 0) }}"
                            data-rental-price="{{ (float) ($product->rental_price ?? 0) }}"
                            data-rental-price-15="{{ (float) ($product->rental_price_15_days ?? 0) }}"
                            data-rental-price-30="{{ (float) ($product->rental_price_30_days ?? 0) }}"
                            data-rental-price-90="{{ (float) ($product->rental_price_3_months ?? 0) }}"
                            data-gst-rate="{{ $product->gst_tax_type === \App\Models\Product::GST_TAX_TYPE_IGST ? round((float) ($product->igst_rate ?? 0), 2) : round((float) ($product->cgst_rate ?? 0) + (float) ($product->sgst_rate ?? 0), 2) }}"
                            data-gst-mode="{{ in_array($product->gst_calculation_mode, \App\Models\Product::GST_CALCULATION_MODES, true) ? $product->gst_calculation_mode : 'exclusive' }}"
                            data-search="{{ trim(implode(' - ', array_filter([$product->name, $product->category, $product->brand, $product->model_name, $product->display_model ?? null, $product->sku, $product->product_code, $product->rental_dropdown_label ?? null, $product->stock_mode, $product->product_type]))) }}"
                            {{ (int) old('product_id', $isEdit ? $rental->product_id : null) === $product->id ? 'selected' : '' }}>
                            {{ $selectedFulfilmentSource === 'vendor_supplied'
                                ? $product->name
                                : ($product->name . ' - ' . ($product->rental_dropdown_label ?? ('Rental Available ' . ($product->display_available_quantity ?? $product->available_quantity)))) }}
                        </option>
                    @endforeach
                </select>
                <div class="selected-product-preview" id="primaryRentalProductPreview" data-selected-product-preview hidden></div>
                <div class="field-warning" id="productRentalWarning" data-server-message="{{ $assetAssignmentDisplayError }}" style="{{ $assetAssignmentDisplayError ? 'display:block;' : '' }}">{{ $assetAssignmentDisplayError }}</div>
                @if($hasFieldError('product_id', 'rental_items'))
                    <span class="field-error">{{ $fieldError('product_id', 'rental_items') }}</span>
                @endif
            </div>
            <div class="rental-field rental-col-4{{ $hasFieldError('dispatch_warehouse_id') ? ' is-error' : '' }}" id="dispatchWarehouseField">
                <label for="dispatch_warehouse_id">Warehouse</label>
                <select name="dispatch_warehouse_id" id="dispatch_warehouse_id">
                    <option value="">Any warehouse</option>
                    @foreach($warehouses as $warehouse)
                        <option
                            value="{{ $warehouse->id }}"
                            data-city-id="{{ (int) ($warehouse->city_id ?? 0) }}"
                            data-city-name="{{ trim((string) ($warehouse->cityRecord?->name ?? $warehouse->city ?? '')) }}"
                            {{ (int) old('dispatch_warehouse_id', $isEdit ? $rental->dispatch_warehouse_id : null) === $warehouse->id ? 'selected' : '' }}>
                            {{ $warehouse->name }}
                        </option>
                    @endforeach
                </select>
                <span class="hint" id="warehouseCityHint">City warehouse.</span>
                @if($hasFieldError('dispatch_warehouse_id'))
                    <span class="field-error">{{ $fieldError('dispatch_warehouse_id') }}</span>
                @endif
            </div>

            <div class="rental-field rental-col-4{{ $hasFieldError('vendor_id') ? ' is-error' : '' }}" id="vendorField">
                <label for="vendor_id">Vendor</label>
                <select name="vendor_id" id="vendor_id">
                    <option value="">Select vendor when vendor supplied</option>
                    @foreach($fulfilmentVendors as $vendor)
                        <option
                            value="{{ $vendor->id }}"
                            data-city-id="{{ (int) ($vendor->city_id ?? 0) }}"
                            data-city-name="{{ trim((string) ($vendor->cityRecord?->name ?? $vendor->city ?? '')) }}"
                            {{ $selectedFulfilmentVendorId === (int) $vendor->id ? 'selected' : '' }}>
                            {{ $vendor->name }}{{ $vendor->vendor_type ? ' - ' . $vendor->vendor_type : '' }}
                        </option>
                    @endforeach
                </select>
                <span class="hint" id="vendorCityHint">No active vendors available for this city.</span>
                @if($hasFieldError('vendor_id'))
                    <span class="field-error">{{ $fieldError('vendor_id') }}</span>
                @endif
            </div>

            <div class="rental-field rental-col-3{{ $hasFieldError('quantity', 'asset_ids', 'rental_items') ? ' is-error' : '' }}">
                <label for="quantity">Quantity</label>
                <input type="number" name="quantity" id="quantity" min="1" value="{{ old('quantity', $isEdit ? $rental->quantity : 1) }}" required>
                <span class="hint" id="productAvailabilityHint">{{ $selectedFulfilmentSource === 'vendor_supplied' ? 'Vendor supplied.' : 'Available: 0' }}</span>
                @if($hasFieldError('quantity', 'asset_ids', 'rental_items'))
                    <span class="field-error">{{ $fieldError('quantity', 'asset_ids', 'rental_items') }}</span>
                @endif
            </div>

            <div class="rental-col-12 rental-line-subsection">
                <div class="rental-line-heading is-sub"><strong>Rental Details & Pricing</strong><span>Primary rental line</span></div>
            </div>
            <div class="rental-field rental-col-3{{ $hasFieldError('duration_preset') ? ' is-error' : '' }} section-nav-target" id="rental-dates-section">
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
            <div class="rental-field rental-col-3{{ $hasFieldError('rental_amount') ? ' is-error' : '' }} section-nav-target" id="rental-pricing-section">
                <label for="rental_amount">Rental Amount</label>
                <input type="number" step="0.01" min="0" name="rental_amount" id="rental_amount" value="{{ old('rental_amount', $isEdit ? $rental->rental_amount : 0) }}">
                @if($hasFieldError('rental_amount'))
                    <span class="field-error">{{ $fieldError('rental_amount') }}</span>
                @endif
            </div>

            <div class="rental-col-12">
                <details class="gst-details-collapse">
                    <summary>GST Details <span class="hint">Auto from Product Master</span></summary>
                    <div class="gst-details-grid">
                        <div class="rental-field{{ $hasFieldError('gst_rate') ? ' is-error' : '' }}">
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

                        <div class="rental-field{{ $hasFieldError('gst_mode') ? ' is-error' : '' }}">
                            <label for="gst_mode">GST Mode</label>
                            <select name="gst_mode" id="gst_mode">
                                <option value="exclusive" {{ old('gst_mode', $primaryRentalItem?->gst_mode ?? 'exclusive') === 'exclusive' ? 'selected' : '' }}>Exclusive</option>
                                <option value="inclusive" {{ old('gst_mode', $primaryRentalItem?->gst_mode ?? 'exclusive') === 'inclusive' ? 'selected' : '' }}>Inclusive</option>
                            </select>
                            @if($hasFieldError('gst_mode'))
                                <span class="field-error">{{ $fieldError('gst_mode') }}</span>
                            @endif
                        </div>

                        <div class="rental-field{{ $hasFieldError('tax_type') ? ' is-error' : '' }}">
                            <label for="tax_type">Tax Type</label>
                            <select name="tax_type" id="tax_type">
                                <option value="{{ \App\Models\Product::GST_TAX_TYPE_CGST_SGST }}" {{ old('tax_type', $primaryRentalItem?->tax_type ?? \App\Models\Product::GST_TAX_TYPE_CGST_SGST) === \App\Models\Product::GST_TAX_TYPE_CGST_SGST ? 'selected' : '' }}>CGST + SGST</option>
                                <option value="{{ \App\Models\Product::GST_TAX_TYPE_IGST }}" {{ old('tax_type', $primaryRentalItem?->tax_type ?? \App\Models\Product::GST_TAX_TYPE_CGST_SGST) === \App\Models\Product::GST_TAX_TYPE_IGST ? 'selected' : '' }}>IGST</option>
                            </select>
                            @if($hasFieldError('tax_type'))
                                <span class="field-error">{{ $fieldError('tax_type') }}</span>
                            @endif
                        </div>
                    </div>
                </details>
            </div>

            <div class="rental-col-12">
                <div class="rental-availability-panel" id="rentalAvailabilityPanel"><div><span>Available</span><strong data-availability-value="available">0</strong></div>
                    <div><span>On Rent</span><strong data-availability-value="onRent">0</strong></div>
                    <div><span>Maintenance</span><strong data-availability-value="maintenance">0</strong></div>
                    <div><span>Reserved</span><strong data-availability-value="reserved">0</strong></div>
                    <div><span>Warehouse Stock</span><strong data-availability-value="warehouse">Select warehouse</strong></div>
                </div>
            </div>

        </div>
        </div>
        <div class="rental-step-actions">
            <button type="button" class="ops-button-secondary" data-rental-wizard-prev="fulfilment">Previous</button></div>
    </section>


    <section class="rental-card rental-wizard-step{{ $assetAssignmentError ? ' is-error' : '' }} section-nav-target" data-rental-wizard-step="product" id="rental-assets-section">
        <h2>Asset Assignment</h2>
        <p class="section-copy">Assign rental asset.</p>
        <div class="assignment-launch-card">
            <div>
                <div class="assignment-launch-title">
                    <span class="badge badge-blue">Rental Asset</span>
                    <strong id="assignmentLaunchTitle">Asset not assigned</strong>
                </div>
                <div class="assignment-launch-grid">
                    <div class="assignment-launch-metric">
                        <span>Product</span>
                        <strong id="assignmentLaunchProduct">Select product</strong>
                    </div>
                    <div class="assignment-launch-metric">
                        <span>Warehouse</span>
                        <strong id="assignmentLaunchWarehouse">Any warehouse</strong>
                    </div>
                    <div class="assignment-launch-metric">
                        <span>Required</span>
                        <strong id="assignmentLaunchRequired">{{ (int) old('quantity', $isEdit ? $rental->quantity : 1) }}</strong>
                    </div>
                    <div class="assignment-launch-metric">
                        <span>Selected</span>
                        <strong id="assignmentLaunchSelected">{{ count($selectedAssetIds) }}</strong>
                    </div>
                </div>
            </div>
            <button type="button" class="ops-button js-open-rental-asset-modal" id="rentalAssignAssetButton" data-open-rental-asset-modal>Assign Asset</button>
        </div>

        <div class="assignment-drawer-backdrop" id="rentalAssetAssignmentModal" data-rental-asset-modal hidden aria-hidden="true">
            <aside class="assignment-drawer" role="dialog" aria-modal="true" aria-labelledby="assetAssignmentDrawerTitle">
                <div class="assignment-drawer-header">
                    <div class="assignment-drawer-title">
                        <div>
                            <h3 id="assetAssignmentDrawerTitle">Assign Asset</h3>
                            <div class="asset-helper">Search and select the physical unit for this rental line.</div>
                        </div>
                        <button type="button" class="assignment-drawer-close" id="closeAssetAssignmentDrawer" data-close-rental-asset-modal aria-label="Close assignment drawer">&times;</button>
                    </div>
                    <div class="assignment-asset-summary">
                        <div><span>Asset</span><strong id="assignmentDrawerAsset">Asset not assigned</strong></div>
                        <div><span>Product</span><strong id="assignmentDrawerProduct">Select product</strong></div>
                        <div><span>Status</span><strong id="assignmentDrawerStatus">Select asset</strong></div>
                        <div><span>Warehouse</span><strong id="assignmentDrawerWarehouse">Any warehouse</strong></div>
                    </div>
                </div>

                <div class="assignment-drawer-body">
                    <div>
                        <span class="assignment-section-label">Assignment Type</span>
                        <div class="assignment-type-grid">
                            <label class="assignment-type-card"><input type="radio" name="assignment_drawer_type" value="rental" checked> Rental</label>
                            <label class="assignment-type-card is-disabled" title="Managed from sales workflow"><input type="radio" name="assignment_drawer_type_disabled_sale" value="sale" disabled> Sale</label>
                            <label class="assignment-type-card is-disabled" title="Use repair workflow for repair state"><input type="radio" name="assignment_drawer_type_disabled_repair" value="repair" disabled> Repair</label>
                            <label class="assignment-type-card is-disabled" title="Use transfer workflow for internal movement"><input type="radio" name="assignment_drawer_type_disabled_internal" value="internal" disabled> Internal Use</label>
                            <label class="assignment-type-card is-disabled" title="Use transfer workflow for vendor movement"><input type="radio" name="assignment_drawer_type_disabled_vendor" value="vendor" disabled> Vendor Transfer</label>
                        </div>
                    </div>

                    <div class="assignment-drawer-fields">
                        <div class="assignment-drawer-field"><span>Rental Number</span><strong>{{ $isEdit ? ('Rental #' . $rental->id) : 'New rental' }}</strong></div>
                        <div class="assignment-drawer-field"><span>Assignment Date</span><strong>{{ now()->format('d M Y') }}</strong></div>
                        <div class="assignment-drawer-field"><span>Assigned By</span><strong>{{ auth()->user()?->name ?? 'Current user' }}</strong></div>
                        <div class="assignment-drawer-field"><span>Notes</span><strong>Saved with rental</strong></div>
                    </div>

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
                        <div id="assetSelectionAlert" class="asset-warning" data-server-message="{{ $assetAssignmentDisplayError }}" style="{{ $assetAssignmentDisplayError ? 'display:block;' : 'display:none;' }}">{{ $assetAssignmentDisplayError }}</div>
                        <div id="assetGrid" class="asset-grid"></div>
                        <input type="hidden" name="primary_asset_ids" id="primary_asset_ids" value="{{ implode(',', $selectedAssetIds) }}">
                        <div id="primaryAssetHiddenInputs"></div>
                        <div class="asset-load-more" id="assetLoadMoreWrap" style="display:none;">
                            <button type="button" class="ops-button-secondary" id="assetLoadMoreButton">Load More</button>
                        </div>
                        <div id="assetEmptyState" class="asset-empty">Select product</div>
                    </div>
                </div>

                <div class="assignment-drawer-footer">
                    <button type="button" class="ops-button-secondary" id="cancelAssetAssignmentDrawer" data-close-rental-asset-modal>Cancel</button>
                    <button type="button" class="ops-button" id="applyAssetAssignmentDrawer">Assign Asset</button>
                </div>
            </aside>
        </div>
        <div class="assignment-toast" id="assetAssignmentToast" hidden>Asset assigned successfully.</div>
        @if($canCreateRentalStock)
            <div class="rental-stock-modal" id="rentalStockModal" aria-hidden="true" hidden>
                <aside class="rental-stock-sheet" role="dialog" aria-modal="true" aria-labelledby="rentalStockModalTitle">
                    <div class="rental-stock-header">
                        <div>
                            <h3 id="rentalStockModalTitle">Add Rental Stock</h3>
                            <p>Add one rental-ready asset without leaving this rental.</p>
                        </div>
                        <button type="button" class="assignment-drawer-close" id="closeRentalStockModal" data-close-rental-stock-modal aria-label="Close rental stock modal">&times;</button>
                    </div>
                    <div id="rentalStockForm" data-action="{{ route('rentals.rental-stock.store') }}" data-token="{{ csrf_token() }}">
                        <input type="hidden" id="rentalStockFulfilmentSource" value="{{ old('fulfilment_source', $selectedFulfilmentSource) }}">
                        <input type="hidden" id="rentalStockCityId" value="{{ old('city_id', $isEdit ? $rental->city_id : '') }}">
                        <input type="hidden" id="rentalStockAssetStatus" value="{{ \App\Models\Asset::STATUS_AVAILABLE }}">
                        <div class="rental-stock-body">
                            <div class="rental-stock-alert" id="rentalStockMessage" hidden></div>
                            <div class="rental-stock-grid">
                                <div class="rental-field rental-stock-col-12">
                                    <label for="rental_stock_product_id">Product</label>
                                    <select id="rental_stock_product_id">
                                        <option value="">Select rental product</option>
                                        @foreach($rentalProducts as $product)
                                            <option
                                                value="{{ $product->id }}"
                                                data-product-name="{{ $product->name }}"
                                                data-tracks-rental="{{ $product->tracksRentalStock() ? 1 : 0 }}"
                                                data-rentable="{{ $product->isRentalEligibleForSelection() ? 1 : 0 }}">
                                                {{ $product->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="rental-field rental-stock-col-6">
                                    <label for="rental_stock_warehouse_id">Warehouse</label>
                                    <select id="rental_stock_warehouse_id">
                                        <option value="">Select warehouse</option>
                                        @foreach($warehouses as $warehouse)
                                            <option
                                                value="{{ $warehouse->id }}"
                                                data-city-id="{{ (int) ($warehouse->city_id ?? 0) }}"
                                                data-city-name="{{ trim((string) ($warehouse->cityRecord?->name ?? $warehouse->city ?? '')) }}">
                                                {{ $warehouse->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="rental-field rental-stock-col-6">
                                    <label for="rental_stock_serial_number">Serial / Asset Number</label>
                                    <input type="text" id="rental_stock_serial_number" autocomplete="off">
                                </div>
                                <div class="rental-field rental-stock-col-6">
                                    <label for="rental_stock_barcode_value">Barcode</label>
                                    <input type="text" id="rental_stock_barcode_value" autocomplete="off">
                                </div>
                                <div class="rental-field rental-stock-col-6">
                                    <label for="rental_stock_condition_status">Condition</label>
                                    <select id="rental_stock_condition_status">
                                        <option value="{{ \App\Models\Asset::CONDITION_STATUS_GOOD }}" selected>Good</option>
                                        <option value="{{ \App\Models\Asset::CONDITION_STATUS_NEW }}">New</option>
                                        <option value="{{ \App\Models\Asset::CONDITION_STATUS_FAIR }}">Fair</option>
                                        <option value="{{ \App\Models\Asset::CONDITION_STATUS_NEEDS_REPAIR }}">Needs Repair</option>
                                        <option value="{{ \App\Models\Asset::CONDITION_STATUS_DAMAGED }}">Damaged</option>
                                        <option value="{{ \App\Models\Asset::CONDITION_STATUS_RETIRED }}">Retired</option>
                                    </select>
                                </div>
                                <div class="rental-field rental-stock-col-12">
                                    <label for="rental_stock_notes">Notes</label>
                                    <textarea id="rental_stock_notes" rows="2" placeholder="Optional stock note"></textarea>
                                </div>
                            </div>
                        </div>
                        <div class="rental-stock-footer">
                            <button type="button" class="ops-button-secondary" id="cancelRentalStockModal" data-close-rental-stock-modal>Cancel</button>
                            <button type="button" class="ops-button" id="saveRentalStockButton">Save Stock</button>
                        </div>
                    </div>
                </aside>
            </div>
            <div class="assignment-toast" id="rentalStockToast" hidden>Rental stock added and available for selection.</div>
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    const openBtn = document.querySelector('[data-open-rental-stock-modal]');
                    const modal = document.getElementById('rentalStockModal');
                    const closeBtns = document.querySelectorAll('[data-close-rental-stock-modal]');

                    if (!openBtn || !modal) {
                        return;
                    }

                    openBtn.addEventListener('click', function (event) {
                        event.preventDefault();
                        event.stopPropagation();
                        modal.hidden = false;
                        modal.classList.add('is-open');
                        modal.setAttribute('aria-hidden', 'false');
                        document.body.classList.add('modal-open');
                        document.body.classList.add('assignment-drawer-open');
                    });

                    closeBtns.forEach(function (btn) {
                        btn.addEventListener('click', function () {
                            modal.hidden = true;
                            modal.classList.remove('is-open');
                            modal.setAttribute('aria-hidden', 'true');
                            document.body.classList.remove('modal-open');
                            document.body.classList.remove('assignment-drawer-open');
                        });
                    });
                });
            </script>
        @endif
        @if($assetAssignmentDisplayError)
            <div class="field-error" style="margin-top:10px;">{{ $assetAssignmentDisplayError }}</div>
        @endif
    </section>

    <section class="rental-card rental-wizard-step" data-rental-wizard-step="dates">
        <h2>Charges</h2>
        <p class="section-copy">Order-level deposit, transport, and other charges.</p>
        <div class="rental-grid"><div class="rental-field rental-col-3{{ $hasFieldError('deposit_amount') ? ' is-error' : '' }}">
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

            <div class="rental-inline-warning" id="pricingWarning" role="status" aria-live="polite"></div>

            <input type="hidden" name="delivery_responsibility" id="delivery_responsibility" value="{{ $selectedDeliveryResponsibility }}">
            <input type="hidden" name="pickup_responsibility" id="pickup_responsibility" value="{{ $selectedPickupResponsibility }}">

        </div>
        <div class="rental-step-actions">
            <button type="button" class="ops-button-secondary" data-rental-wizard-prev="product">Previous</button>
            <button type="button" class="ops-button" data-rental-wizard-next="delivery">Next: Delivery & Pickup</button>
        </div>
    </section>

    <section class="rental-card rental-wizard-step" data-rental-wizard-step="delivery">
        <h2>Delivery & Pickup</h2>
        <p class="section-copy">Assign dispatch responsibility and capture pickup handling.</p>
        <div class="rental-grid">
            <div class="rental-field rental-col-3{{ $hasFieldError('delivery_assignment_type') ? ' is-error' : '' }} section-nav-target" id="rental-delivery-section">
                <label for="delivery_assignment_type">Step 6 - Delivery Assignment</label>
                <select name="delivery_assignment_type" id="delivery_assignment_type">
                    <option value="ph_internal" {{ $selectedDeliveryAssignmentType === 'ph_internal' ? 'selected' : '' }}>PH Internal Delivery Staff</option>
                    <option value="customer_pickup" {{ $selectedDeliveryAssignmentType === 'customer_pickup' ? 'selected' : '' }}>Customer Pickup</option>
                    <option value="third_party" {{ $selectedDeliveryAssignmentType === 'third_party' ? 'selected' : '' }}>Third Party Logistics</option>
                    <option value="vendor" {{ $selectedDeliveryAssignmentType === 'vendor' ? 'selected' : '' }}>Vendor Delivery</option>
                </select>
                @if($hasFieldError('delivery_assignment_type'))
                    <span class="field-error">{{ $fieldError('delivery_assignment_type') }}</span>
                @endif
            </div>

            <div class="rental-field {{ $isEdit ? 'rental-col-3' : 'rental-col-6' }}{{ $hasFieldError('delivery_staff_id') ? ' is-error' : '' }}" id="deliveryPartnerField">
                <label for="delivery_staff_id" id="deliveryPartnerLabel">Delivery Partner</label>
                <select name="delivery_staff_id" id="delivery_staff_id">
                    <option value="">Select delivery partner</option>
                    @if(($internalAssignableUsers ?? collect())->isNotEmpty())
                        <optgroup label="Internal Delivery Staff">
                            @foreach($internalAssignableUsers as $user)
                                <option
                                    value="user:{{ $user->id }}"
                                    data-assignment-kind="ph_internal"
                                    data-city-id="{{ (int) ($user->city_id ?? 0) }}"
                                    {{ $selectedDeliveryAssignment === 'user:' . $user->id ? 'selected' : '' }}>
                                    {{ $user->name }} - {{ ucwords(str_replace('_', ' ', $user->effective_role ?? $user->role ?? 'delivery')) }}
                                </option>
                            @endforeach
                        </optgroup>
                    @endif
                    @if($thirdPartyDeliveryMembers->isNotEmpty())
                        <optgroup label="Third-Party Delivery">
                            <option value="third_party" data-assignment-kind="third_party" data-always-visible="1" {{ $selectedDeliveryAssignment === 'third_party' ? 'selected' : '' }}>One-time Third-Party Partner</option>
                            @foreach($thirdPartyDeliveryMembers as $staff)
                                <option
                                    value="staff:{{ $staff->id }}"
                                    data-assignment-kind="third_party"
                                    data-city-name="{{ trim((string) ($staff->city ?? '')) }}"
                                    {{ $selectedDeliveryAssignment === 'staff:' . $staff->id ? 'selected' : '' }}>
                                    {{ $staff->name }} - {{ $staff->role_display }}
                                </option>
                            @endforeach
                        </optgroup>
                    @else
                        <optgroup label="Third-Party Delivery">
                            <option value="third_party" data-assignment-kind="third_party" data-always-visible="1" {{ $selectedDeliveryAssignment === 'third_party' ? 'selected' : '' }}>One-time Third-Party Partner</option>
                        </optgroup>
                    @endif
                    @if($vendorDeliveryMembers->isNotEmpty())
                        <optgroup label="Vendor Delivery">
                            @foreach($vendorDeliveryMembers as $staff)
                                <option
                                    value="staff:{{ $staff->id }}"
                                    data-assignment-kind="vendor"
                                    data-city-name="{{ trim((string) ($staff->city ?? '')) }}"
                                    {{ $selectedDeliveryAssignment === 'staff:' . $staff->id ? 'selected' : '' }}>
                                    {{ $staff->name }} - {{ $staff->role_display }}
                                </option>
                            @endforeach
                        </optgroup>
                    @endif
                    @if($vendorUsers->isNotEmpty())
                        <optgroup label="Saved Vendors">
                            @foreach($vendorUsers as $vendor)
                                <option
                                    value="user:{{ $vendor->id }}"
                                    data-assignment-kind="vendor"
                                    data-city-id="{{ (int) ($vendor->city_id ?? 0) }}"
                                    {{ $selectedDeliveryAssignment === 'user:' . $vendor->id ? 'selected' : '' }}>
                                    {{ $vendor->name }} - Vendor
                                </option>
                            @endforeach
                        </optgroup>
                    @endif
                    @if($selectedLegacyAssignableStaff)
                        <optgroup label="Current Saved Assignment">
                            <option
                                value="staff:{{ $selectedLegacyAssignableStaff->id }}"
                                data-assignment-kind="legacy"
                                data-always-visible="1"
                                data-city-name="{{ trim((string) ($selectedLegacyAssignableStaff->city ?? '')) }}"
                                selected>
                                {{ $selectedLegacyAssignableStaff->name }} - {{ $selectedLegacyAssignableStaff->role_display }}
                            </option>
                        </optgroup>
                    @endif
                </select>
                <div class="ops-muted" id="deliveryPartnerHint" style="margin-top:6px;">Choose a city-filtered fulfilment partner when this assignment type needs one.</div>
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

            <div class="rental-field rental-col-12 section-nav-target{{ $hasFieldError('delivery_notes') ? ' is-error' : '' }}" id="rental-notes-section">
                <label for="delivery_notes">Delivery Note</label>
                <textarea name="delivery_notes" id="delivery_notes" placeholder="Delivery instruction, landmark, service note, or customer handling request.">{{ old('delivery_notes', $isEdit ? ($rental->delivery_notes ?? '') : '') }}</textarea>
                <span class="hint">Visible to operations while coordinating delivery and pickup.</span>
                @if($hasFieldError('delivery_notes'))
                    <span class="field-error">{{ $fieldError('delivery_notes') }}</span>
                @endif
            </div>
        </div>
        <div class="rental-step-actions">
            <button type="button" class="ops-button-secondary" data-rental-wizard-prev="dates">Previous</button>
            <button type="button" class="ops-button" data-rental-wizard-next="review">Next: Review & Create</button>
        </div>
    </section>

    <section class="rental-card rental-wizard-step" data-rental-wizard-step="review" id="rental-review-section">
        <h2>Review & Create</h2>

        <div class="rental-review-grid is-compact-review">
            <div class="rental-review-box"><span>Customer</span><strong data-summary-value="customer">Not selected</strong></div>
            <div class="rental-review-box"><span>Product</span><strong data-summary-value="product" data-summary-product>Not selected</strong></div>
            <div class="rental-review-box"><span>Dates</span><strong data-summary-value="dates">Not set</strong></div>
            <div class="rental-review-box"><span>Rental</span><strong data-summary-value="pricing" data-summary-rental-total>0.00</strong></div>
            <div class="rental-review-box"><span>Assets</span><strong data-summary-value="assets" data-summary-assets>Not assigned</strong></div>
        </div>
    </section>

    <section class="rental-card rental-wizard-step section-nav-target" data-rental-wizard-step="product" id="rental-addons-section">
        <details class="sale-section-disclosure compact-section-disclosure rental-section-panel{{ $hasRentalItemsError ? ' is-error' : '' }}" id="additionalRentalDisclosure" {{ $additionalRentalExpanded ? 'open' : '' }}>
            <summary>
                <span class="compact-section-title">
                    Rental Products
                    <span class="is-collapsed">+</span>
                    <span class="is-open">-</span>
                </span>
                <div class="compact-section-summary">
                    <span class="sale-section-chip"><strong id="rentalItemsCountSummary">{{ count($additionalRentalRows) }}</strong> line(s)</span>
                    <span class="sale-section-chip">Total <strong id="rentalItemsGrandTotalSummary">0.00</strong></span>
                    <button type="button" class="ops-button-secondary add-line-cta is-header" data-add-rental-item>+ Add Rental Product</button>
                </div>
            </summary>
            <div class="sale-section-body">
                @if($hasRentalItemsError)
                    <div class="field-error" style="margin:0 0 10px;">{{ $fieldError('rental_items', 'rental_items.0.asset_ids', 'rental_items.0.product_id', 'rental_items.0.quantity') }}</div>
                @endif
                <div class="sale-item-panel is-rental-items">
                    <div class="sale-item-head">
                        <div>Rental Product</div>
                        <div>Qty</div>
                        <div>Unit Rental</div>
                        <div>Line Total</div>
                        <div>Options</div>
                    </div>
                    <div id="rentalItemsList">
                        <div class="sale-item-empty" id="rentalItemEmptyState">No extra rental lines added yet.</div>
                    </div>
                </div>
                <div class="sale-item-summary">
                    <div><strong id="rentalItemsCount">0</strong> extra rental line(s)</div>
                    <div>Extra rental total: <strong id="rentalItemsGrandTotal">0.00</strong></div>
                    <div class="sale-item-actions">
                        <button type="button" class="ops-button-secondary add-line-cta" id="addRentalItemButton">+ Add Rental Product</button>
                    </div>
                </div>
            </div>
        </details>
    </section>

    <section class="rental-card rental-wizard-step" data-rental-wizard-step="product">
        <details class="sale-section-disclosure rental-section-panel{{ $hasSaleItemsError ? ' is-error' : '' }}" id="newProductsDisclosure" {{ $newProductsExpanded ? 'open' : '' }}>
            <summary>
                <div class="sale-section-heading">
                    <strong>Sales Add-ons</strong>
                    <span>Optional sale products billed with this rental.</span>
                </div>
                <div class="sale-section-meta">
                    <span class="sale-section-chip"><strong id="saleItemsCountSummary">{{ count($saleItemRows) }}</strong> line(s)</span>
                    <span class="sale-section-chip">Total <strong id="saleItemsGrandTotalSummary">0.00</strong></span>
                    <span class="sale-section-chip sale-section-state">
                        <span class="is-collapsed">Expand</span>
                        <span class="is-open">Collapse</span>
                    </span>
                    <button type="button" class="ops-button-secondary add-line-cta is-header" data-add-sale-item>+ Add Sale Product</button>
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
                        <button type="button" class="ops-button-secondary add-line-cta" id="addSaleItemButton">+ Add Sale Product</button>
                    </div>
                </div>
            </div>
        </details>
    </section>

    <div class="rental-step-actions rental-wizard-step" data-rental-wizard-step="product">
        <button type="button" class="ops-button-secondary" data-rental-wizard-prev="fulfilment">Previous</button>
        <button type="button" class="ops-button" data-rental-wizard-next="dates">Next: Charges</button>
    </div>

    <div class="rental-toolbar rental-wizard-step" data-rental-wizard-step="review" style="margin-top:-4px;">
        <div><div class="rental-inline-note" id="rentalSaveGuidance">Check required items.</div><div class="rental-missing-checklist" id="rentalMissingChecklist" aria-live="polite"></div></div>
        <div class="rental-actions">
            <span class="rental-draft-status" data-rental-draft-status></span>
            <a href="{{ route('rentals.index') }}" class="ops-button-secondary">Cancel</a>
            <button type="button" class="ops-button-secondary" id="rentalSaveDraftButton">Save Draft</button>
            <button type="submit" form="rentalCreateForm" name="action" value="save" class="ops-button" id="rentalSubmitButton" data-save-rental>{{ $isEdit ? 'Update Rental' : 'Create Rental' }}</button>
        </div>
    </div>
        </div>

        <aside class="rental-order-summary" aria-label="Order summary">
            <details open>
                <summary data-mobile-summary="Live">
                    <h2>Order Summary</h2>
                    <span class="badge badge-blue">Live</span>
                </summary>
                <div class="rental-summary-list">
                    <div class="rental-summary-row"><span>Fulfilment</span><strong data-summary-value="fulfilment">In-house Stock</strong></div>
                    <div class="rental-summary-row"><span>Customer</span><strong data-summary-value="customer">Not selected</strong></div>
                    <div class="rental-summary-row"><span>Product Lines</span><strong data-summary-value="product" data-summary-product>Not selected</strong></div>
                    <div class="rental-summary-row"><span>Dates</span><strong data-summary-value="dates">Not set</strong></div>
                    <div class="rental-summary-row"><span>Rental excl. GST</span><strong data-summary-value="pricing" data-summary-rental-total>0.00</strong></div>
                    <div class="rental-summary-row"><span>GST</span><strong data-summary-value="gst">0%</strong></div>
                    <div class="rental-summary-row"><span>Deposit</span><strong data-summary-value="deposit">0.00</strong></div>
                    <div class="rental-summary-row"><span>Transportation</span><strong data-summary-value="transport">0.00</strong></div>
                    <div class="rental-summary-row"><span>Assets</span><strong data-summary-value="assets" data-summary-assets>Not assigned</strong></div>
                    <div class="rental-summary-row is-total"><span>Grand Total</span><strong data-summary-value="netTotal" data-summary-net-total>0.00</strong></div>
                    <details class="rental-summary-secondary">
                        <summary>More</summary>
                        <div class="rental-summary-list">
                            <div class="rental-summary-row"><span>City</span><strong data-summary-value="city">Not selected</strong></div>
                            <div class="rental-summary-row"><span>Actual Client</span><strong data-summary-value="actualClient">Not applicable</strong></div>
                            <div class="rental-summary-row"><span>Quantity</span><strong data-summary-value="quantity">{{ old('quantity', $isEdit ? $rental->quantity : 1) }}</strong></div>
                            <div class="rental-summary-row"><span>Source</span><strong data-summary-value="source">Any warehouse</strong></div>
                            <div class="rental-summary-row"><span>Delivery</span><strong data-summary-value="delivery">PH Internal</strong></div>
                        </div>
                    </details>
                </div>
            </details>
        </aside>
    </div>
</div>

<div class="rental-mobile-action-bar" id="rentalMobileActionBar" aria-live="polite">
    <div class="rental-mobile-action-state">
        <strong id="rentalMobileActionState">Start rental</strong>
        <span id="rentalMobileActionHint">Complete this step.</span>
    </div>
    <div class="rental-mobile-action-buttons">
        <button type="button" class="ops-button-secondary" id="rentalMobileBackButton">Back</button>
        <button type="button" class="ops-button" id="rentalMobileAssignButton" data-assign-asset-mobile>Assign Asset</button>
        <button type="button" class="ops-button" id="rentalMobileContinueButton">Continue</button>
        <button type="button" class="ops-button-secondary" id="rentalMobileDraftButton" hidden>Save Draft</button>
        <button type="submit" form="rentalCreateForm" name="action" value="save" class="ops-button" id="rentalMobileSaveButton" data-save-rental hidden>{{ $isEdit ? 'Update' : 'Create' }}</button>
    </div>
</div>

<script>
    (function () {
        const tabs = Array.from(document.querySelectorAll('[data-rental-wizard-tab]'));
        const steps = Array.from(document.querySelectorAll('[data-rental-wizard-step]'));
        const nextButtons = Array.from(document.querySelectorAll('[data-rental-wizard-next]'));
        const prevButtons = Array.from(document.querySelectorAll('[data-rental-wizard-prev]'));
        const openAddonButtons = Array.from(document.querySelectorAll('[data-open-addons]'));
        const saveDraftButton = document.getElementById('rentalSaveDraftButton');
        const draftStatusNodes = Array.from(document.querySelectorAll('[data-rental-draft-status]'));
        const summaryValues = Array.from(document.querySelectorAll('[data-summary-value]'));
        const form = document.currentScript.closest('form') || document.querySelector('form[action*="rentals"]');
        const orderSummaryTrigger = document.querySelector('.rental-order-summary summary');
        const pricingWarning = document.getElementById('pricingWarning');
        const rentalSubmitButton = document.getElementById('rentalSubmitButton');
        const rentalMissingChecklist = document.getElementById('rentalMissingChecklist');
        const mobileActionBar = document.getElementById('rentalMobileActionBar');
        const mobileActionState = document.getElementById('rentalMobileActionState');
        const mobileActionHint = document.getElementById('rentalMobileActionHint');
        const mobileBackButton = document.getElementById('rentalMobileBackButton');
        const mobileAssignButton = document.getElementById('rentalMobileAssignButton');
        const mobileContinueButton = document.getElementById('rentalMobileContinueButton');
        const mobileDraftButton = document.getElementById('rentalMobileDraftButton');
        const mobileSaveButton = document.getElementById('rentalMobileSaveButton');
        const mobileProgressButtons = Array.from(document.querySelectorAll('[data-rental-mobile-step]'));
        const openAssetAssignmentButton = document.getElementById('rentalAssignAssetButton');
        const wizardNextStep = {
            fulfilment: 'product',
            product: 'dates',
            dates: 'delivery',
            delivery: 'review',
        };
        const wizardPreviousStep = {
            product: 'fulfilment',
            dates: 'product',
            delivery: 'dates',
            review: 'delivery',
        };
        const rentalIndexUrl = @json(route('rentals.index'));
        const draftKey = 'phos:rental-create:draft';
        let lastSelectedAssetSerial = '';
        const getEl = (id) => document.getElementById(id);
        const textOf = (select) => select?.selectedOptions?.[0]?.textContent?.trim() || '';
        const valueOf = (id) => getEl(id)?.value?.trim() || '';
        const setSummary = (key, value) => {
            summaryValues
                .filter((node) => node.dataset.summaryValue === key)
                .forEach((node) => { node.textContent = value || 'Not selected'; });
        };
        const setAvailability = (key, value) => {
            document.querySelectorAll('[data-availability-value="' + key + '"]')
                .forEach((node) => { node.textContent = value; });
        };
        const stepRequirements = {
            dates: ['start_date', 'end_date', 'rental_amount'],
        };
        const normalizeWizardAssetId = (value) => String(value ?? '').trim();
        window.primaryAssetServerValidationMessage = @json($assetAssignmentDisplayError);
        const primaryAssetServerMessage = () => window.primaryAssetServerValidationMessage || '';
        const clearPrimaryAssetServerValidationMessage = () => { window.primaryAssetServerValidationMessage = ''; };
        const getPrimaryRentalAssetHiddenInputs = () => Array.from(document.querySelectorAll('input[type="hidden"][name="asset_ids[]"]'))
            .map((input) => normalizeWizardAssetId(input.value))
            .filter(Boolean);
        const primaryAssetFallbackIds = () => (getEl('primary_asset_ids')?.value || '')
            .split(/[,\s]+/)
            .map(normalizeWizardAssetId)
            .filter(Boolean);
        const primarySubmittedAssetIds = () => {
            const ids = [
                ...getPrimaryRentalAssetHiddenInputs(),
                ...primaryAssetFallbackIds(),
                ...(typeof window.__phosPrimaryRentalAssetIds === 'function'
                    ? window.__phosPrimaryRentalAssetIds().map(normalizeWizardAssetId).filter(Boolean)
                    : []),
            ];

            return Array.from(new Set(ids.filter(Boolean)));
        };
        const syncPrimaryAssetSubmitFields = () => {
            const ids = primarySubmittedAssetIds();
            const fallback = getEl('primary_asset_ids');
            const hiddenWrap = getEl('primaryAssetHiddenInputs');

            if (fallback) {
                fallback.value = ids.join(',');
            }
            if (hiddenWrap) {
                hiddenWrap.innerHTML = ids.map((assetId) => '<input type="hidden" name="asset_ids[]" value="' + assetId.replace(/&/g, '&amp;').replace(/"/g, '&quot;') + '">').join('');
            }

            return ids;
        };
        const selectedAssetCount = () => new Set(primarySubmittedAssetIds()).size;
        const primaryProductRequiresAssets = () => {
            if (valueOf('fulfilment_source') === 'vendor_supplied') {
                return false;
            }

            const option = getEl('product_id')?.selectedOptions?.[0];
            return Boolean(option?.value) && option.getAttribute('data-tracks-rental') === '1';
        };
        const requiredPrimaryAssetCount = () => Math.max(parseInt(valueOf('quantity') || '0', 10), 0);
        const primaryAssetSelectionMessage = () => {
            if (!primaryProductRequiresAssets()) {
                return '';
            }

            const required = requiredPrimaryAssetCount();
            const selected = selectedAssetCount();

            if (required > 0 && selected < required) {
                return required + ' asset' + (required === 1 ? '' : 's') + ' required. ' + selected + ' selected.';
            }

            return '';
        };
        const syncPrimaryAssetWarning = () => {
            if (primaryProductRequiresAssets() && requiredPrimaryAssetCount() > 0 && selectedAssetCount() >= requiredPrimaryAssetCount()) {
                clearPrimaryAssetServerValidationMessage();
            }
            const message = primaryAssetSelectionMessage() || primaryAssetServerMessage();
            const alert = document.getElementById('assetSelectionAlert');
            const productWarning = document.getElementById('productRentalWarning');

            if (alert) {
                alert.textContent = message;
                alert.style.display = message ? 'block' : 'none';
            }

            if (productWarning) {
                productWarning.textContent = message;
                productWarning.style.display = message ? 'block' : 'none';
            }

            document.getElementById('rental-assets-section')?.classList.toggle('is-error', Boolean(message));
        };
        const pricingWarningMessage = () => {
            const missing = [];

            if (parseFloat(valueOf('deposit_amount') || '0') <= 0) {
                missing.push('deposit');
            }

            if (parseFloat(valueOf('transport_amount') || '0') <= 0) {
                missing.push('transportation cost');
            }

            return missing.length
                ? 'Warning: ' + missing.join(' - ') + ' not added. Confirm this is intentional before creating the rental.'
                : '';
        };
        const syncPricingWarning = () => {
            if (!pricingWarning) {
                return;
            }

            const message = pricingWarningMessage();
            pricingWarning.textContent = message;
            pricingWarning.classList.toggle('is-visible', Boolean(message));
        };
        const hasValue = (id) => Boolean(valueOf(id));
        const isVisibleAndEnabled = (id) => {
            const element = getEl(id);

            if (!element || element.disabled) {
                return false;
            }

            const wrapper = element.closest('.rental-field, [data-customer-mode-block], #vendorField, #dispatchWarehouseField, #deliveryPartnerField') || element;

            if (wrapper.hidden || wrapper.hasAttribute('hidden')) {
                return false;
            }

            const style = window.getComputedStyle(wrapper);
            return style.display !== 'none' && style.visibility !== 'hidden';
        };
        const isProductBasicsComplete = () => {
            const quantity = parseInt(valueOf('quantity') || '0', 10);
            return hasValue('product_id') && quantity > 0;
        };
        const isCustomerStepComplete = () => {
            if (!hasValue('fulfilment_source') || !hasValue('city_id')) {
                return false;
            }

            if (valueOf('customer_type') === 'business_partner') {
                return hasValue('business_partner_id') && hasValue('partner_client_id');
            }

            return hasValue('customer_id');
        };
        const isProductStepComplete = () => {
            if (!isProductBasicsComplete()) {
                return false;
            }

            if (valueOf('fulfilment_source') === 'vendor_supplied') {
                if (isVisibleAndEnabled('vendor_id') && !hasValue('vendor_id')) {
                    return false;
                }
            } else if (isVisibleAndEnabled('dispatch_warehouse_id') && !hasValue('dispatch_warehouse_id')) {
                return false;
            }

            return !primaryAssetSelectionMessage();
        };
        const isPricingStepComplete = () => {
            if (!(stepRequirements.dates || []).every(hasValue)) {
                return false;
            }

            return parseFloat(valueOf('rental_amount') || '0') > 0;
        };
        const isDeliveryStepComplete = () => {
            if (!hasValue('delivery_assignment_type')) {
                return false;
            }

            if (isVisibleAndEnabled('delivery_staff_id') && !hasValue('delivery_staff_id')) {
                return false;
            }

            return true;
        };
        const areRequiredFieldsComplete = (stepName) => {
            if (stepName === 'fulfilment') {
                return isCustomerStepComplete();
            }

            if (stepName === 'product') {
                return isProductStepComplete();
            }

            if (stepName === 'dates') {
                return isPricingStepComplete();
            }

            if (stepName === 'delivery') {
                return isDeliveryStepComplete();
            }

            if (stepName === 'review') {
                return false;
            }

            return (stepRequirements[stepName] || []).every(hasValue);
        };
        const isReviewStepComplete = () => (
            isCustomerStepComplete()
            && isProductStepComplete()
            && isPricingStepComplete()
            && isDeliveryStepComplete()
            && !rentalSubmitButton?.disabled
        );
        const isStepComplete = (stepName) => {
            if (stepName === 'review') {
                return isReviewStepComplete();
            }

            return areRequiredFieldsComplete(stepName);
        };
        const missingFieldsForStep = (stepName) => {
            const missing = [];

            if (stepName === 'fulfilment') {
                if (!hasValue('fulfilment_source')) missing.push('fulfilment source');
                if (!hasValue('city_id')) missing.push('city');
                if (valueOf('customer_type') === 'business_partner') {
                    if (!hasValue('business_partner_id')) missing.push('business partner');
                    if (!hasValue('partner_client_id')) missing.push('actual client');
                } else if (!hasValue('customer_id')) {
                    missing.push('customer');
                }
            }

            if (stepName === 'product') {
                if (!hasValue('product_id')) missing.push('product');
                if (parseInt(valueOf('quantity') || '0', 10) <= 0) missing.push('quantity');
                if (valueOf('fulfilment_source') === 'vendor_supplied') {
                    if (isVisibleAndEnabled('vendor_id') && !hasValue('vendor_id')) missing.push('vendor');
                } else if (isVisibleAndEnabled('dispatch_warehouse_id') && !hasValue('dispatch_warehouse_id')) {
                    missing.push('warehouse');
                }
                const assetMessage = primaryAssetSelectionMessage();
                if (assetMessage) missing.push(assetMessage);
            }

            if (stepName === 'dates') {
                if (!hasValue('start_date')) missing.push('start date');
                if (!hasValue('end_date')) missing.push('end date');
                if (parseFloat(valueOf('rental_amount') || '0') <= 0) missing.push('rental amount');
            }

            if (stepName === 'delivery') {
                if (!hasValue('delivery_assignment_type')) missing.push('delivery assignment');
                if (isVisibleAndEnabled('delivery_staff_id') && !hasValue('delivery_staff_id')) missing.push('delivery staff');
            }

            return missing;
        };
        const missingStepMessage = (stepName) => {
            const missing = missingFieldsForStep(stepName);
            return missing.length ? 'Missing: ' + missing.join(', ') + '.' : '';
        };
        const updateMissingChecklist = (stepName = currentWizardStep()) => {
            if (!rentalMissingChecklist) {
                return;
            }
            const missing = missingFieldsForStep(stepName);
            rentalMissingChecklist.innerHTML = '';
            missing.forEach((item) => {
                const chip = document.createElement('span');
                chip.textContent = item;
                rentalMissingChecklist.appendChild(chip);
            });
            rentalMissingChecklist.classList.toggle('is-visible', missing.length > 0);
        };
        const syncMissingStepMessage = (stepName) => {
            const message = missingStepMessage(stepName);
            if (stepName === 'product') {
                const productWarning = document.getElementById('productRentalWarning');
                if (productWarning && message && !primaryAssetSelectionMessage()) {
                    productWarning.textContent = message;
                    productWarning.style.display = 'block';
                }
            }
            if (stepName === 'dates' && pricingWarning && message) {
                pricingWarning.textContent = message;
                pricingWarning.classList.add('is-visible');
            }
            return message;
        };
        const mobileStepOrder = ['customer', 'product', 'asset', 'pricing', 'delivery', 'review'];
        const mobileStepForWizard = (stepName) => {
            if (stepName === 'fulfilment') {
                return 'customer';
            }
            if (stepName === 'dates') {
                return 'pricing';
            }
            if (stepName === 'product') {
                return isProductBasicsComplete() && primaryProductRequiresAssets() && selectedAssetCount() === 0
                    ? 'asset'
                    : 'product';
            }
            return stepName;
        };
        const isMobileConceptComplete = (mobileStep) => {
            if (mobileStep === 'customer') {
                return isStepComplete('fulfilment');
            }
            if (mobileStep === 'product') {
                return isProductBasicsComplete();
            }
            if (mobileStep === 'asset') {
                return !primaryProductRequiresAssets() || !primaryAssetSelectionMessage();
            }
            if (mobileStep === 'pricing') {
                return isStepComplete('dates');
            }
            if (mobileStep === 'delivery') {
                return isStepComplete('delivery');
            }
            if (mobileStep === 'review') {
                return isReviewStepComplete();
            }

            return false;
        };
        const updateMobileProgress = () => {
            if (!mobileProgressButtons.length) {
                return;
            }

            const activeMobileStep = mobileStepForWizard(currentWizardStep());
            const activeIndex = mobileStepOrder.indexOf(activeMobileStep);
            mobileProgressButtons.forEach((button) => {
                const key = button.dataset.rentalMobileStep;
                const index = mobileStepOrder.indexOf(key);
                const complete = index < activeIndex || isMobileConceptComplete(key);
                button.classList.toggle('is-active', key === activeMobileStep);
                button.classList.toggle('is-complete', complete && key !== activeMobileStep);
                const marker = button.querySelector('span');
                if (marker) {
                    marker.textContent = complete && key !== activeMobileStep ? 'OK' : String(index + 1);
                }
            });
        };
        const showStep = (stepName, shouldScroll = true) => {
            tabs.forEach((tab) => tab.classList.toggle('is-active', tab.dataset.rentalWizardTab === stepName));
            steps.forEach((step) => step.classList.toggle('is-active', step.dataset.rentalWizardStep === stepName));
            if (shouldScroll) {
                document.querySelector('[data-rental-wizard-tab="' + stepName + '"]')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
            updateMobileActionBar();
            updateMissingChecklist(currentWizardStep());
        };
        const currentWizardStep = () => {
            const activeTab = tabs.find((tab) => tab.classList.contains('is-active'));
            return activeTab?.dataset?.rentalWizardTab || 'fulfilment';
        };
        const updateMobileActionBar = () => {
            if (!mobileActionBar) {
                return;
            }

            const stepName = currentWizardStep();
            const complete = isStepComplete(stepName);
            const assetCount = selectedAssetCount();
            const requiresAssets = primaryProductRequiresAssets();
            let state = 'Complete this step';
            let hint = 'Continue when the required details are ready.';

            if (stepName === 'fulfilment') {
                state = complete ? 'Customer Selected' : 'Select customer';
                hint = complete ? 'Continue to product and asset.' : 'Choose fulfilment, city, and customer.';
            } else if (stepName === 'product') {
                if (requiresAssets && assetCount > 0) {
                    state = 'Asset Selected';
                    hint = lastSelectedAssetSerial
                        ? 'Serial: ' + lastSelectedAssetSerial
                        : (complete ? 'Continue to pricing.' : 'Match selected assets with quantity.');
                } else if (requiresAssets) {
                    state = 'Select asset';
                    hint = 'Pick the rental asset to continue.';
                } else {
                    state = complete ? 'Product Ready' : 'Select product';
                    hint = complete ? 'Continue to pricing.' : 'Choose product and quantity.';
                }
            } else if (stepName === 'dates') {
                state = complete ? 'Pricing Ready' : 'Add pricing';
                hint = complete ? 'Continue to delivery.' : 'Set dates and rental amount.';
            } else if (stepName === 'delivery') {
                state = complete ? 'Delivery Ready' : 'Set delivery';
                hint = complete ? 'Review and save rental.' : 'Choose delivery assignment.';
            } else {
                state = rentalSubmitButton?.disabled ? 'Review rental' : 'Ready to save';
                hint = rentalSubmitButton?.disabled ? 'Resolve warnings before saving.' : 'Save rental when ready.';
            }

            if (mobileActionState) {
                mobileActionState.textContent = state;
            }
            if (mobileActionHint) {
                mobileActionHint.textContent = hint;
            }

            const isReview = stepName === 'review';
            const showAssignAction = stepName === 'product' && requiresAssets && Boolean(primaryAssetSelectionMessage());
            if (mobileBackButton) {
                mobileBackButton.textContent = stepName === 'fulfilment' || isReview ? 'Cancel' : 'Back';
                mobileBackButton.hidden = isReview;
                mobileBackButton.disabled = false;
            }
            if (mobileAssignButton) {
                mobileAssignButton.hidden = !showAssignAction;
                mobileAssignButton.textContent = assetCount > 0 ? 'Change Asset' : 'Assign Asset';
                mobileAssignButton.disabled = false;
            }
            if (mobileContinueButton) {
                mobileContinueButton.hidden = isReview || showAssignAction;
                mobileContinueButton.disabled = !complete;
                mobileContinueButton.title = complete ? '' : 'Complete this step first.';
            }
            if (mobileDraftButton) {
                mobileDraftButton.hidden = !isReview;
            }
            if (mobileSaveButton) {
                mobileSaveButton.hidden = !isReview;
                mobileSaveButton.disabled = Boolean(rentalSubmitButton?.disabled);
                mobileSaveButton.textContent = @json($isEdit ? 'Update Rental' : 'Create Rental');
            }

            mobileActionBar.classList.toggle('is-ready', complete);
            mobileActionBar.classList.toggle('is-asset-selected', stepName === 'product' && assetCount > 0);
            updateMobileProgress();
        };
        const updateWizardState = () => {
            syncPrimaryAssetWarning();
            syncPricingWarning();
            tabs.forEach((tab) => {
                const stepName = tab.dataset.rentalWizardTab;
                const complete = isStepComplete(stepName);
                const rawHasErrors = steps
                    .filter((step) => step.dataset.rentalWizardStep === stepName)
                    .some((step) => step.querySelector('.is-error, .field-error'));
                const hasErrors = rawHasErrors && !(stepName === 'product' && complete);
                tab.classList.toggle('is-complete', complete && !hasErrors);
                tab.classList.toggle('is-warning', hasErrors);
                const badge = tab.querySelector('span:first-child');
                if (badge) {
                    badge.textContent = hasErrors ? '!' : (complete ? 'OK' : String(tabs.indexOf(tab) + 1));
                }
            });
            nextButtons.forEach((button) => {
                const stepName = button.closest('[data-rental-wizard-step]')?.dataset.rentalWizardStep;
                button.disabled = !!stepName && !isStepComplete(stepName);
                button.title = button.disabled ? (missingStepMessage(stepName) || 'Complete required fields in this step first.') : '';
            });
            updateMobileActionBar();
            updateMissingChecklist(currentWizardStep());
        };
        const setDraftStatus = (message) => {
            draftStatusNodes.forEach((node) => { node.textContent = message || ''; });
        };
        const updateSummary = () => {
            const productSelect = getEl('product_id');
            const selectedProduct = productSelect?.selectedOptions?.[0] || null;
            const warehouseId = valueOf('dispatch_warehouse_id');
            const fulfilment = textOf(getEl('fulfilment_source')) || 'In-house Stock';
            const isVendor = valueOf('fulfilment_source') === 'vendor_supplied';
            const customerType = valueOf('customer_type');
            setSummary('fulfilment', fulfilment);
            setSummary('city', textOf(getEl('city_id')) || 'Not selected');
            setSummary('customer', customerType === 'business_partner' ? textOf(getEl('business_partner_id')) : textOf(getEl('customer_id')));
            setSummary('actualClient', customerType === 'business_partner' ? textOf(getEl('partner_client_id')) : 'Direct customer');
            setSummary('product', textOf(getEl('product_id')) || 'Not selected');
            setSummary('quantity', valueOf('quantity') || '1');
            setSummary('source', isVendor ? (textOf(getEl('vendor_id')) || 'Vendor not selected') : (textOf(getEl('dispatch_warehouse_id')) || 'Warehouse not selected'));
            const start = valueOf('start_date');
            const end = valueOf('end_date');
            const rentalValue = parseFloat(valueOf('rental_amount') || '0') || 0;
            const depositValue = parseFloat(valueOf('deposit_amount') || '0') || 0;
            const transportValue = parseFloat(valueOf('transport_amount') || '0') || 0;
            const otherValue = parseFloat(valueOf('other_amount') || '0') || 0;
            const gstRateValue = parseFloat(valueOf('gst_rate') || '0') || 0;
            const gstModeValue = valueOf('gst_mode') === 'inclusive' ? 'inclusive' : 'exclusive';
            const gstValue = gstModeValue === 'exclusive' ? rentalValue * (gstRateValue / 100) : 0;
            const addOnSummary = typeof window.__phosRentalAdditionalSummary === 'function'
                ? window.__phosRentalAdditionalSummary()
                : { rentalCount: 0, saleCount: 0, rentalTotal: 0, saleTotal: 0, gstTotal: 0, assetCount: 0 };
            const additionalRentalTotal = parseFloat(addOnSummary.rentalTotal || '0') || 0;
            const saleAddOnTotal = parseFloat(addOnSummary.saleTotal || '0') || 0;
            const additionalGstValue = parseFloat(addOnSummary.gstTotal || '0') || 0;
            const rentalSummaryTotal = rentalValue + additionalRentalTotal;
            const gstSummaryTotal = gstValue + additionalGstValue;
            const netTotal = rentalValue + gstValue + additionalRentalTotal + saleAddOnTotal + depositValue + transportValue + otherValue;
            const primaryProductLabel = textOf(getEl('product_id')) || 'Not selected';
            const productParts = [primaryProductLabel];
            if ((addOnSummary.rentalCount || 0) > 0) {
                productParts.push(addOnSummary.rentalCount + ' rental add-on' + (addOnSummary.rentalCount === 1 ? '' : 's'));
            }
            if ((addOnSummary.saleCount || 0) > 0) {
                productParts.push(addOnSummary.saleCount + ' sale item' + (addOnSummary.saleCount === 1 ? '' : 's'));
            }
            setSummary('product', productParts.join(' + '));
            setSummary('dates', addOnSummary.hasMixedDates ? 'Mixed dates' : (start && end ? start + ' - ' + end : 'Not set'));
            setSummary('pricing', rentalSummaryTotal.toFixed(2));
            setSummary('gst', gstSummaryTotal > 0
                ? gstSummaryTotal.toFixed(2)
                : (gstRateValue.toFixed(2).replace(/\.00$/, '') + '%' + (gstModeValue === 'inclusive' ? ' incl.' : '')));
            setSummary('deposit', depositValue.toFixed(2));
            setSummary('transport', transportValue.toFixed(2));
            setSummary('netTotal', netTotal.toFixed(2));
            orderSummaryTrigger?.setAttribute('data-mobile-summary', 'Live | Rs. ' + netTotal.toFixed(2));
            setSummary('delivery', textOf(getEl('delivery_assignment_type')) || 'PH Internal');            const primaryAssetCount = selectedAssetCount();
            const addOnAssetCount = parseInt(addOnSummary.assetCount || '0', 10) || 0;
            const assetCount = primaryAssetCount + addOnAssetCount;
            let assetSummary = assetCount ? assetCount + ' selected' : 'Not assigned';
            if (!isVendor && primaryProductRequiresAssets() && primaryAssetCount < requiredPrimaryAssetCount()) {
                assetSummary = addOnAssetCount
                    ? 'Primary not assigned + ' + addOnAssetCount + ' add-on'
                    : 'Primary not assigned';
            }
            setSummary('assets', isVendor ? 'Vendor supplied' : assetSummary);
            let available = parseInt(selectedProduct?.dataset?.rentalAvailable || selectedProduct?.dataset?.available || '0', 10);
            let warehouseStock = warehouseId ? '0' : 'All warehouses';
            try {
                const warehouseQuantities = JSON.parse(selectedProduct?.dataset?.rentalWarehouseQuantities || '{}');
                if (warehouseId && Object.prototype.hasOwnProperty.call(warehouseQuantities, warehouseId)) {
                    available = parseInt(warehouseQuantities[warehouseId] || '0', 10);
                    warehouseStock = String(available);
                }
            } catch (error) {
                warehouseStock = warehouseId ? String(Number.isNaN(available) ? 0 : available) : 'All warehouses';
            }
            if (!selectedProduct || !selectedProduct.value || isVendor) {
                setAvailability('available', isVendor ? 'Vendor' : '0');
                setAvailability('warehouse', isVendor ? 'Vendor stock' : 'Select product');
            } else {
                setAvailability('available', String(Number.isNaN(available) ? 0 : available));
                setAvailability('warehouse', warehouseStock);
            }
            setAvailability('onRent', '0');
            setAvailability('maintenance', '0');
            setAvailability('reserved', '0');
            updateWizardState();
        };
        tabs.forEach((tab) => {
            tab.addEventListener('click', () => showStep(tab.dataset.rentalWizardTab));
        });
        mobileProgressButtons.forEach((button) => {
            button.addEventListener('click', () => {
                const mobileStep = button.dataset.rentalMobileStep;
                const targetStep = {
                    customer: 'fulfilment',
                    product: 'product',
                    asset: 'product',
                    pricing: 'dates',
                    delivery: 'delivery',
                    review: 'review',
                }[mobileStep] || 'fulfilment';

                showStep(targetStep);

                if (mobileStep === 'asset') {
                    window.setTimeout(() => {
                        if (primaryProductRequiresAssets()) {
                            openAssetAssignmentButton?.click();
                        } else {
                            document.getElementById('rental-assets-section')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        }
                    }, 80);
                }
            });
        });
        nextButtons.forEach((button) => {
            button.addEventListener('click', () => {
                const stepName = button.closest('[data-rental-wizard-step]')?.dataset.rentalWizardStep;
                syncPrimaryAssetWarning();
                syncPricingWarning();

                if (stepName === 'product' && primaryAssetSelectionMessage()) {
                    document.getElementById('rental-assets-section')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    return;
                }

                if (button.disabled) {
                    return;
                }
                showStep(button.dataset.rentalWizardNext);
                if (button.hasAttribute('data-open-addons')) {
                    document.getElementById('additionalRentalDisclosure')?.setAttribute('open', 'open');
                    document.getElementById('newProductsDisclosure')?.setAttribute('open', 'open');
                }
            });
        });
        openAddonButtons.forEach((button) => {
            button.addEventListener('click', () => {
                document.getElementById('additionalRentalDisclosure')?.setAttribute('open', 'open');
                document.getElementById('newProductsDisclosure')?.setAttribute('open', 'open');
                document.getElementById('rental-addons-section')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        });
        prevButtons.forEach((button) => {
            button.addEventListener('click', () => showStep(button.dataset.rentalWizardPrev));
        });
        mobileContinueButton?.addEventListener('click', () => {
            const stepName = currentWizardStep();
            syncPrimaryAssetWarning();
            syncPricingWarning();

            if (stepName === 'product' && primaryAssetSelectionMessage()) {
                syncMissingStepMessage(stepName);
                document.getElementById('rental-assets-section')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }

            if (!isStepComplete(stepName)) {
                syncMissingStepMessage(stepName);
                updateMobileActionBar();
                return;
            }

            const nextStep = wizardNextStep[stepName];
            if (nextStep) {
                showStep(nextStep);
            }
        });
        mobileAssignButton?.addEventListener('click', () => {
            showStep('product');
            document.querySelector('[data-open-rental-asset-modal]')?.click();
        });
        mobileBackButton?.addEventListener('click', () => {
            if (currentWizardStep() === 'fulfilment' || currentWizardStep() === 'review') {
                window.location.href = rentalIndexUrl;
                return;
            }

            const previousStep = wizardPreviousStep[currentWizardStep()];
            if (previousStep) {
                showStep(previousStep);
            }
        });
        mobileDraftButton?.addEventListener('click', () => {
            saveDraftButton?.click();
        });
        const showSaveBlockedMessage = () => {
            const stepName = currentWizardStep();
            const message = missingStepMessage(stepName) || primaryAssetSelectionMessage() || primaryAssetServerMessage() || 'Resolve warnings before saving.';
            if (rentalSaveGuidance) {
                rentalSaveGuidance.textContent = message;
            }
            syncMissingStepMessage(stepName);
            updateMissingChecklist(stepName);
        };
        mobileSaveButton?.addEventListener('click', (event) => {
            if (rentalSubmitButton?.disabled) {
                event.preventDefault();
                showSaveBlockedMessage();
                updateMobileActionBar();
            }
        });
        document.addEventListener('click', (event) => {
            const saveButton = event.target.closest('[data-save-rental]');
            if (!saveButton) {
                return;
            }

            const submitForm = document.getElementById('rentalCreateForm') || saveButton.form || form;
            syncPrimaryAssetSubmitFields();
            if (saveButton.disabled || rentalSubmitButton?.disabled) {
                event.preventDefault();
                showSaveBlockedMessage();
                updateMobileActionBar();
                return;
            }

            if (submitForm && saveButton.form !== submitForm) {
                event.preventDefault();
                submitForm.requestSubmit ? submitForm.requestSubmit(rentalSubmitButton || saveButton) : submitForm.submit();
            }
        });
        form?.addEventListener('submit', () => {
            syncPrimaryAssetSubmitFields();
        }, true);
        saveDraftButton?.addEventListener('click', () => {
            if (!form) {
                setDraftStatus('Unable to find the rental form.');
                return;
            }

            const originalLabel = saveDraftButton.textContent;
            const payload = {};

            try {
                const formData = new FormData(form);
                formData.forEach((value, key) => {
                    if (key === '_token' || key === '_method') {
                        return;
                    }
                    if (Object.prototype.hasOwnProperty.call(payload, key)) {
                        payload[key] = Array.isArray(payload[key]) ? payload[key].concat(value) : [payload[key], value];
                    } else {
                        payload[key] = value;
                    }
                });
                localStorage.setItem(draftKey, JSON.stringify({ savedAt: new Date().toISOString(), payload }));
                saveDraftButton.textContent = 'Draft Saved';
                setDraftStatus('Draft saved locally at ' + new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) + '.');
                window.setTimeout(() => {
                    saveDraftButton.textContent = originalLabel;
                }, 1800);
            } catch (error) {
                setDraftStatus('Unable to save draft in this browser.');
            }
        });
        try {
            const draft = JSON.parse(localStorage.getItem(draftKey) || 'null');
            if (draft?.savedAt) {
                setDraftStatus('Local draft available.');
            }
        } catch (error) {
            localStorage.removeItem(draftKey);
        }
        window.updateSummary = updateSummary;
        form?.addEventListener('input', updateSummary);
        form?.addEventListener('change', updateSummary);
        form?.addEventListener('invalid', (event) => {
            const stepName = event.target.closest('[data-rental-wizard-step]')?.dataset.rentalWizardStep;
            if (stepName) {
                showStep(stepName);
            }
        }, true);
        document.addEventListener('click', (event) => {
            if (event.target.closest('#selectSuggestedAssets, #clearSelectedAssets, .asset-card')) {
                window.setTimeout(updateSummary, 0);
            }
        });
        const syncMobileAssetDetail = (detail) => {
            if (detail?.count === 0) {
                lastSelectedAssetSerial = '';
                return;
            }
            if (detail?.serial) {
                lastSelectedAssetSerial = detail.serial;
            }
        };
        const refreshWizardAfterAssetChange = (event) => {
            syncMobileAssetDetail(event.detail);
            syncPrimaryAssetWarning();
            updateSummary();
            updateWizardState();
            updateMobileActionBar();
            updateMissingChecklist(currentWizardStep());
        };
        document.addEventListener('phos:rental-assets-updated', refreshWizardAfterAssetChange);
        document.addEventListener('phos:rental-assets-applied', refreshWizardAfterAssetChange);
        window.__phosRentalStepDebug = function () {
            const productOption = getEl('product_id')?.selectedOptions?.[0] || null;
            const hiddenAssetIds = getPrimaryRentalAssetHiddenInputs();
            const productStepValid = isProductStepComplete();

            return {
                productId: valueOf('product_id'),
                warehouseId: valueOf('dispatch_warehouse_id'),
                quantity: parseInt(valueOf('quantity') || '0', 10) || 0,
                fulfilmentSource: valueOf('fulfilment_source'),
                stockMode: productOption?.getAttribute('data-stock-mode') || productOption?.getAttribute('data-product-stock-mode') || '',
                productType: productOption?.getAttribute('data-product-type') || '',
                tracksRental: productOption?.getAttribute('data-tracks-rental') === '1',
                hiddenAssetIds: hiddenAssetIds,
                hiddenAssetCount: new Set(hiddenAssetIds).size,
                selectedAssetIds: typeof window.__phosPrimaryRentalAssetIds === 'function' ? window.__phosPrimaryRentalAssetIds() : [],
                selectedAssetCount: selectedAssetCount(),
                currentStep: currentWizardStep(),
                currentStepValid: currentWizardStep() === 'product' ? productStepValid : isStepComplete(currentWizardStep()),
                productStepValid: productStepValid,
                assetMessage: primaryAssetSelectionMessage(),
                nextButtonDisabled: nextButtons
                    .filter((button) => button.closest('[data-rental-wizard-step]')?.dataset.rentalWizardStep === 'product')
                    .map((button) => button.disabled),
            };
        };
        const firstErrorStep = steps.find((step) => step.querySelector('.is-error, .field-error'))?.dataset.rentalWizardStep;
        if (window.matchMedia('(max-width: 980px)').matches) {
            document.querySelector('.rental-order-summary details')?.removeAttribute('open');
        }
        showStep(firstErrorStep || 'fulfilment', false);
        updateSummary();
    })();
</script>

<script>
    (function () {

        document.addEventListener('click', function (event) {
            const trigger = event.target.closest('[data-open-rental-stock-modal], #openRentalStockModal');

            if (!trigger) {
                return;
            }


            event.preventDefault();
            event.stopPropagation();

            const modal = document.getElementById('rentalStockModal');

            if (!modal) {
                alert('Rental stock modal not found in DOM');
                return;
            }

            if (modal.parentElement !== document.body) {
                document.body.appendChild(modal);
            }

            modal.hidden = false;
            modal.removeAttribute('hidden');
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
            modal.style.display = 'flex';
            modal.style.position = 'fixed';
            modal.style.inset = '0';
            modal.style.zIndex = '999999';
            modal.style.background = 'rgba(15,23,42,.55)';

            document.body.classList.add('modal-open');
            document.body.classList.add('assignment-drawer-open');

        }, true);

        document.addEventListener('click', function (event) {
            const close = event.target.closest('[data-close-rental-stock-modal]');

            if (!close) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();

            const modal = document.getElementById('rentalStockModal');

            if (modal) {
                modal.hidden = true;
                modal.setAttribute('hidden', 'hidden');
                modal.classList.remove('is-open');
                modal.setAttribute('aria-hidden', 'true');
                modal.style.display = 'none';
            }

            document.body.classList.remove('modal-open');
            document.body.classList.remove('assignment-drawer-open');
        }, true);

        document.addEventListener('click', function (event) {
            const saveButton = event.target.closest('#saveRentalStockButton');

            if (!saveButton) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
            event.stopImmediatePropagation();

            const form = document.getElementById('rentalStockForm');
            const modal = document.getElementById('rentalStockModal');
            const message = document.getElementById('rentalStockMessage');

            if (!form) {
                alert('Rental stock form not found in DOM');
                return;
            }

            const setMessage = function (text, isError = true) {
                if (!message) {
                    if (isError && text) {
                        alert(text);
                    }
                    return;
                }

                message.textContent = text || '';
                message.classList.toggle('is-error', Boolean(isError));
                message.hidden = !text;
            };

            const selectedProduct = document.getElementById('rental_stock_product_id')?.value || document.getElementById('product_id')?.value || '';
            const selectedWarehouse = document.getElementById('rental_stock_warehouse_id')?.value || document.getElementById('warehouse_id')?.value || '';
            const fulfilmentSource = document.getElementById('fulfilment_source')?.value || 'in_house';

            if (document.getElementById('rental_stock_product_id') && selectedProduct) {
                document.getElementById('rental_stock_product_id').value = selectedProduct;
            }
            if (document.getElementById('rental_stock_warehouse_id') && selectedWarehouse) {
                document.getElementById('rental_stock_warehouse_id').value = selectedWarehouse;
            }

            if (!selectedProduct) {
                setMessage('Select a rental product before saving stock.');
                return;
            }

            if (fulfilmentSource === 'vendor_supplied') {
                setMessage('Vendor-supplied rentals do not require PH rental stock.');
                return;
            }

            if (!selectedWarehouse) {
                setMessage('Select a warehouse before saving stock.');
                return;
            }

            const serialNumber = (document.getElementById('rental_stock_serial_number')?.value || '').trim();

            if (!serialNumber) {
                setMessage('Enter Serial / Asset Number before saving stock.');
                document.getElementById('rental_stock_serial_number')?.focus();
                return;
            }

            const formData = new FormData();
            formData.set('_token', form.dataset.token || '');
            formData.set('product_id', selectedProduct);
            formData.set('warehouse_id', selectedWarehouse);
            formData.set('serial_number', serialNumber);
            formData.set('barcode_value', document.getElementById('rental_stock_barcode_value')?.value || '');
            formData.set('condition_status', document.getElementById('rental_stock_condition_status')?.value || 'good');
            formData.set('notes', document.getElementById('rental_stock_notes')?.value || '');
            formData.set('asset_status', document.getElementById('rentalStockAssetStatus')?.value || 'available');
            formData.set('fulfilment_source', fulfilmentSource);
            formData.set('city_id', document.getElementById('city_id')?.value || '');
            formData.set('quantity', '1');

            saveButton.disabled = true;
            saveButton.textContent = 'Saving...';
            setMessage('', false);

            fetch(form.dataset.action, {
                method: 'POST',
                body: formData,
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': form.dataset.token || ''
                }
            })
                .then(function (response) {
                    return response.json().catch(function () {
                        return {};
                    }).then(function (payload) {
                        if (!response.ok) {
                            throw payload;
                        }

                        return payload;
                    });
                })
                .then(function (payload) {
                    document.getElementById('rental_stock_serial_number').value = '';
                    if (document.getElementById('rental_stock_barcode_value')) {
                        document.getElementById('rental_stock_barcode_value').value = '';
                    }
                    if (document.getElementById('rental_stock_notes')) {
                        document.getElementById('rental_stock_notes').value = '';
                    }

                    document.dispatchEvent(new CustomEvent('phos:rental-stock-created', { detail: payload }));

                    if (modal) {
                        modal.hidden = true;
                        modal.setAttribute('hidden', 'hidden');
                        modal.classList.remove('is-open');
                        modal.setAttribute('aria-hidden', 'true');
                        modal.style.display = 'none';
                    }

                    document.body.classList.remove('modal-open');
                    document.body.classList.remove('assignment-drawer-open');
                })
                .catch(function (payload) {
                    const errors = payload?.errors || {};
                    const firstError = Object.values(errors).flat()[0] || payload?.message || 'Unable to add rental stock.';
                    setMessage(firstError);
                })
                .finally(function () {
                    saveButton.disabled = false;
                    saveButton.textContent = 'Save Stock';
                });
        }, true);
    })();
</script>

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
        const primaryAssetHiddenInputs = document.getElementById('primaryAssetHiddenInputs');
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
        const assetAssignmentDrawer = document.getElementById('rentalAssetAssignmentModal');
        const openAssetAssignmentDrawerButton = document.getElementById('rentalAssignAssetButton');
        if (openAssetAssignmentDrawerButton) {
        }
        const closeAssetAssignmentDrawerButton = document.getElementById('closeAssetAssignmentDrawer');
        const cancelAssetAssignmentDrawerButton = document.getElementById('cancelAssetAssignmentDrawer');
        const applyAssetAssignmentDrawerButton = document.getElementById('applyAssetAssignmentDrawer');
        const assetAssignmentToast = document.getElementById('assetAssignmentToast');
        const openRentalStockModalButton = document.querySelector('[data-add-rental-stock-trigger]');
        const rentalStockModal = document.getElementById('rentalStockModal');
        const closeRentalStockModalButton = document.getElementById('closeRentalStockModal');
        const cancelRentalStockModalButton = document.getElementById('cancelRentalStockModal');
        const safeUpdateSummary = () => {
            if (typeof window.updateSummary === 'function') {
                window.updateSummary();
            }
        };
        const rentalStockForm = document.getElementById('rentalStockForm');
        const rentalStockProductSelect = document.getElementById('rental_stock_product_id');
        const rentalStockWarehouseSelect = document.getElementById('rental_stock_warehouse_id');
        const rentalStockSerialInput = document.getElementById('rental_stock_serial_number');
        const rentalStockFulfilmentInput = document.getElementById('rentalStockFulfilmentSource');
        const rentalStockCityInput = document.getElementById('rentalStockCityId');
        const rentalStockMessage = document.getElementById('rentalStockMessage');
        const rentalStockToast = document.getElementById('rentalStockToast');
        const saveRentalStockButton = document.getElementById('saveRentalStockButton');
        const assignmentLaunchTitle = document.getElementById('assignmentLaunchTitle');
        const assignmentLaunchProduct = document.getElementById('assignmentLaunchProduct');
        const assignmentLaunchWarehouse = document.getElementById('assignmentLaunchWarehouse');
        const assignmentLaunchRequired = document.getElementById('assignmentLaunchRequired');
        const assignmentLaunchSelected = document.getElementById('assignmentLaunchSelected');
        const assignmentDrawerAsset = document.getElementById('assignmentDrawerAsset');
        const assignmentDrawerProduct = document.getElementById('assignmentDrawerProduct');
        const assignmentDrawerStatus = document.getElementById('assignmentDrawerStatus');
        const assignmentDrawerWarehouse = document.getElementById('assignmentDrawerWarehouse');
        const selectSuggestedAssetsButton = document.getElementById('selectSuggestedAssets');
        const clearSelectedAssetsButton = document.getElementById('clearSelectedAssets');
        const assetLoadMoreWrap = document.getElementById('assetLoadMoreWrap');
        const assetLoadMoreButton = document.getElementById('assetLoadMoreButton');
        const productRentalWarning = document.getElementById('productRentalWarning');
        const rentalSubmitButton = document.getElementById('rentalSubmitButton');
        const rentalSaveGuidance = document.getElementById('rentalSaveGuidance');
        const unavailableRentalMessage = 'No available asset for this product.';

        const normalizeAssetId = (value) => String(value ?? '').trim();
        const normalizeAssetIds = (values) => Array.from(new Set((Array.isArray(values) ? values : (values ? [values] : []))
            .map(normalizeAssetId)
            .filter(Boolean)));
        let selectedAssetIds = normalizeAssetIds(@json($selectedAssetIds));
        const notifyPrimaryAssetUserChanged = () => document.dispatchEvent(new CustomEvent('phos:rental-assets-user-changed'));
        window.__phosPrimaryRentalAssetIds = function () {
            return Array.from(document.querySelectorAll('input[type="hidden"][name="asset_ids[]"]'))
                .map(function (input) { return normalizeAssetId(input.value); })
                .filter(Boolean);
        };
        let currentAssets = [];
        let rentalItems = @json($additionalRentalRows);
        let saleItems = @json($saleItemRows);
        const standardGstRates = ['0.00', '5.00', '12.00', '18.00', '28.00'];
        let rentalAmountTouched = Boolean(@json($isEdit || (old('rental_amount') !== null && old('rental_amount') !== '')));
        let syncingRentalAmount = false;
        const assetVisibleStep = 3;
        let visibleAssetCount = assetVisibleStep;
        const currentRentalId = @json($isEdit ? $rental->id : null);
        const organizationState = @json($organization?->state ?? null);
        const partnerClientEndpointTemplate = @json($businessPartnerFlowAvailable ? route('rentals.business-partners.actual-clients', ['business_partner' => '__PARTNER__']) : null);
        const rentalProductOptionsHtml = `<option value="">Select rental product</option>@foreach($rentalProducts as $product)<option value="{{ $product->id }}" data-default-price="{{ (float) ($product->rental_price ?? $product->price_per_day ?? 0) }}" data-gst-rate="{{ $product->gst_tax_type === \App\Models\Product::GST_TAX_TYPE_IGST ? round((float) ($product->igst_rate ?? 0), 2) : round((float) ($product->cgst_rate ?? 0) + (float) ($product->sgst_rate ?? 0), 2) }}" data-gst-mode="{{ in_array($product->gst_calculation_mode, \App\Models\Product::GST_CALCULATION_MODES, true) ? $product->gst_calculation_mode : 'exclusive' }}" data-product-name="{{ e($product->name) }}" data-product-image-url="{{ e($product->product_image_url) }}" data-product-brand="{{ e($product->brand) }}" data-product-model="{{ e($product->model_name ?? $product->display_model) }}" data-product-sku="{{ e($product->sku) }}" data-product-code="{{ e($product->product_code) }}" data-in-house-option-label="{{ e($product->name . ' - ' . ($product->rental_dropdown_label ?? ('Rental Available ' . ($product->display_available_quantity ?? $product->available_quantity)))) }}" data-vendor-option-label="{{ e($product->name) }}" data-rental-available="{{ $product->rental_available_quantity ?? $product->display_available_quantity ?? $product->available_quantity }}" data-rental-status="{{ $product->rental_availability_status }}" data-rental-label="{{ e($product->rental_dropdown_label ?? ('Rental Available ' . ($product->display_available_quantity ?? $product->available_quantity))) }}" data-tracks-rental="{{ $product->tracksRentalStock() ? 1 : 0 }}" data-search="{{ e(trim(implode(' - ', array_filter([$product->name, $product->category, $product->brand, $product->model_name, $product->display_model ?? null, $product->sku, $product->product_code, $product->stock_mode, $product->product_type, $product->rental_dropdown_label ?? ('Rental Available ' . ($product->display_available_quantity ?? $product->available_quantity))])))) }}">{{ e($product->name) }} - {{ e($product->rental_dropdown_label ?? ('Rental Available ' . ($product->display_available_quantity ?? $product->available_quantity))) }}</option>@endforeach`;
        const saleProductOptionsHtml = `<option value="">Select new product</option>@foreach($sellableProducts as $product)<option value="{{ $product->id }}" data-default-price="{{ (float) ($product->sale_price ?? 0) }}" data-gst-rate="{{ $product->gst_tax_type === \App\Models\Product::GST_TAX_TYPE_IGST ? round((float) ($product->igst_rate ?? 0), 2) : round((float) ($product->cgst_rate ?? 0) + (float) ($product->sgst_rate ?? 0), 2) }}" data-gst-mode="{{ in_array($product->gst_calculation_mode, \App\Models\Product::GST_CALCULATION_MODES, true) ? $product->gst_calculation_mode : 'exclusive' }}" data-product-name="{{ e($product->name) }}" data-product-image-url="{{ e($product->product_image_url) }}" data-product-brand="{{ e($product->brand) }}" data-product-model="{{ e($product->model_name ?? $product->display_model) }}" data-product-sku="{{ e($product->sku) }}" data-product-code="{{ e($product->product_code) }}" data-product-meta="{{ e(trim(implode(' | ', array_filter([$product->brand, $product->model_name ?? $product->display_model, $product->sku, $product->product_code])))) }}" data-availability-label="Sale {{ number_format((float) ($product->sale_price ?? 0), 2) }}" data-search="{{ e(trim(implode(' - ', array_filter([$product->name, $product->brand, $product->model_name, $product->sku, $product->product_code, 'Sale ' . number_format((float) ($product->sale_price ?? 0), 2)])))) }}">{{ e($product->name) }} | Sale {{ number_format((float) ($product->sale_price ?? 0), 2) }}</option>@endforeach`;
        const saleAssetOptions = @json($saleAssetRows);
        const primaryRentalAssetIndex = @json($primaryRentalAssetIndex ?? []);
        const warehouseOptionsHtml = `<option value="">Auto / best stock</option>@foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}">{{ e($warehouse->name) }}</option>@endforeach`;
        const rentalItemAssetCache = {};
        const rentalItemAssetRequests = {};
        const partnerClientCache = new Map();
        const inlineAssetVisibleStep = 3;
        let primaryAvailabilityLoadedFor = null;
        let primaryAssetRequestToken = 0;
        let lastPrimaryAssetSignature = null;
        let lastPrimaryProductValue = null;
        let latestPrimaryAssetResponseCount = 0;
        let latestPrimaryAssetRequestUrl = '-';
        let latestAssetHideReason = 'initial';
        let activeAssetAssignmentItemIndex = 0;
        let activeAssetAssignmentProductId = '';
        let activeAssetAssignmentWarehouseId = '';
        let activeAssetAssignmentRequiredQty = 0;
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
            if (submittedAssetIds.length) {
                clearPrimaryAssetServerValidationMessage();
            }
        }

        rentalItems = (Array.isArray(rentalItems) ? rentalItems : []).map(function (item) {
            return Object.assign({
                start_date: startDateInput?.value || '',
                end_date: endDateInput?.value || '',
                duration_days: activeRentalDays() || '',
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

        function optionProductName(option) {
            return option?.getAttribute('data-product-name') || option?.textContent?.trim() || '';
        }

        function optionProductInitial(option) {
            return String(optionProductName(option) || 'P').trim().charAt(0).toUpperCase() || 'P';
        }

        function optionProductMeta(option) {
            return option?.getAttribute('data-product-meta') || [
                option?.getAttribute('data-product-brand'),
                option?.getAttribute('data-product-model'),
                option?.getAttribute('data-product-sku'),
                option?.getAttribute('data-product-code'),
            ].filter(Boolean).join(' | ');
        }

        function optionAvailabilityLabel(select, option) {
            if (!option || !option.value) {
                return '';
            }

            if (option.getAttribute('data-availability-label')) {
                return option.getAttribute('data-availability-label');
            }

            if (select?.id === 'product_id' || select?.hasAttribute('data-rental-product-index')) {
                if (document.getElementById('fulfilment_source')?.value === 'vendor_supplied') {
                    return 'Vendor supplied';
                }

                return option.getAttribute('data-rental-label') || '';
            }

            return '';
        }

        function optionProductThumbHtml(option, className = 'product-option-thumb') {
            const imageUrl = option?.getAttribute('data-product-image-url') || '';
            const initial = escapeHtml(optionProductInitial(option));

            if (!imageUrl) {
                return `<span class="${className}" aria-hidden="true">${initial}</span>`;
            }

            return `<span class="${className}" aria-hidden="true"><img src="${escapeHtml(imageUrl)}" alt="" onerror="this.parentElement.textContent='${initial}'"></span>`;
        }

        function isProductSearchSelect(select) {
            const name = String(select?.name || '');
            return name.includes('[product_id]')
                || select?.id === 'product_id'
                || select?.hasAttribute('data-sale-product-select')
                || select?.hasAttribute('data-rental-product-index')
                || select?.hasAttribute('data-sale-product-index');
        }

        function optionHasProductMedia(select, option) {
            return Boolean(option?.value) && Boolean(optionProductName(option)) && isProductSearchSelect(select);
        }

        function productOptionMarkup(select, option, query) {
            if (!optionHasProductMedia(select, option)) {
                return highlightMatch(optionDisplayLabel(select, option), query);
            }

            const name = optionProductName(option);
            const meta = optionProductMeta(option);
            const availability = optionAvailabilityLabel(select, option);

            return `<span class="product-option-media">${optionProductThumbHtml(option)}<span class="product-option-copy"><strong>${highlightMatch(name, query)}</strong>${meta ? `<span>${escapeHtml(meta)}</span>` : ''}${availability ? `<em>${escapeHtml(availability)}</em>` : ''}</span></span>`;
        }

        function renderSelectedProductPreview(container, option, select) {
            if (!container) {
                return;
            }

            if (!optionHasProductMedia(select, option)) {
                container.hidden = true;
                container.innerHTML = '';
                return;
            }

            const meta = optionProductMeta(option);
            const availability = optionAvailabilityLabel(select, option);
            container.hidden = false;
            container.innerHTML = `${optionProductThumbHtml(option, 'selected-product-thumb')}<span class="selected-product-copy"><strong>${escapeHtml(optionProductName(option))}</strong>${meta ? `<span>${escapeHtml(meta)}</span>` : ''}${availability ? `<em>${escapeHtml(availability)}</em>` : ''}</span>`;
        }
        function normalizeSearchText(value) {
            return String(value || '')
                .toLowerCase()
                .replace(/[^a-z0-9]+/g, ' ')
                .replace(/\s+/g, ' ')
                .trim();
        }

        function optionMatchesSearch(option, query, select) {
            const normalizedQuery = normalizeSearchText(query);

            if (!normalizedQuery) {
                return true;
            }

            const haystack = normalizeSearchText([
                option.getAttribute('data-search'),
                optionDisplayLabel(select, option),
                option.textContent,
                option.value,
            ].filter(Boolean).join(' - '));

            if (!haystack) {
                return false;
            }

            const words = haystack.split(' ');

            return normalizedQuery.split(' ').every(function (token) {
                return haystack.includes(token) || words.some(function (word) {
                    return word.startsWith(token) || token.startsWith(word);
                });
            });
        }

        function optionDisplayLabel(select, option) {
            if (!option) {
                return '';
            }

            if (select?.id === 'product_id' || select?.hasAttribute('data-rental-product-index')) {
                if (!option.value) {
                    return (option.textContent || '').trim();
                }

                const source = document.getElementById('fulfilment_source')?.value;

                if (source === 'vendor_supplied') {
                    return (option.getAttribute('data-vendor-option-label') || option.getAttribute('data-product-name') || option.textContent || '').trim();
                }

                return (option.getAttribute('data-in-house-option-label') || option.textContent || '').trim();
            }

            return (option.getAttribute('data-display-label') || option.textContent || '').trim();
        }

        function enhanceSearchableSelect(select) {
            if (!select || select.dataset.searchableEnhanced === 'true') {
                return;
            }

            select.dataset.searchableEnhanced = 'true';
            select.classList.add('searchable-select-native');

            const wrapper = document.createElement('div');
            wrapper.className = 'searchable-select';
            wrapper.classList.toggle('is-product-search', isProductSearchSelect(select));

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
            searchInput.placeholder = isProductSearchSelect(select)
                ? 'Search product by name, SKU or code...'
                : (select.getAttribute('data-search-placeholder') || 'Search options');

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
                triggerLabel.textContent = selected
                    ? optionDisplayLabel(select, selected)
                    : 'Select option';
                const preview = select.closest('.rental-field, .sale-item-field-block, .sale-item-row')?.querySelector('[data-selected-product-preview]');
                renderSelectedProductPreview(preview, selected, select);
            }

            function closePanel() {
                wrapper.classList.remove('is-open');
                panel.hidden = true;
                trigger.setAttribute('aria-expanded', 'false');
                activeIndex = -1;
            }

            function positionProductPanel() {
                if (panel.hidden || !isProductSearchSelect(select)) {
                    return;
                }

                const isMobile = window.matchMedia('(max-width: 640px)').matches;
                const isTablet = window.matchMedia('(min-width: 641px) and (max-width: 1024px)').matches;
                const viewportPadding = isMobile ? 12 : 16;
                const viewportWidth = document.documentElement.clientWidth || window.innerWidth;
                const preferredWidth = isTablet ? 420 : 560;
                const targetWidth = isMobile
                    ? viewportWidth - (viewportPadding * 2)
                    : Math.min(650, Math.max(preferredWidth, trigger.getBoundingClientRect().width));
                const safeWidth = Math.max(trigger.getBoundingClientRect().width, Math.min(targetWidth, viewportWidth - (viewportPadding * 2)));
                const triggerRect = trigger.getBoundingClientRect();
                const wrapperRect = wrapper.getBoundingClientRect();
                const viewportLeft = Math.min(Math.max(triggerRect.left, viewportPadding), viewportWidth - safeWidth - viewportPadding);

                panel.style.width = safeWidth + 'px';
                panel.style.left = (viewportLeft - wrapperRect.left) + 'px';
                panel.style.right = 'auto';
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
                const query = searchInput.value.trim();
                optionsWrap.innerHTML = '';

                visibleOptions = Array.from(select.options).filter(function (option) {
                    if (option.hidden) {
                        return false;
                    }

                    return optionMatchesSearch(option, query, select);
                });

                visibleOptions.forEach(function (option) {
                    const button = document.createElement('button');
                    const displayLabel = optionDisplayLabel(select, option);
                    button.type = 'button';
                    button.className = 'searchable-select-option' + (option.selected ? ' is-selected' : '');
                    button.classList.toggle('has-product-media', optionHasProductMedia(select, option));
                    button.innerHTML = productOptionMarkup(select, option, searchInput.value);
                    button.setAttribute('aria-label', displayLabel);
                    button.dataset.value = option.value;
                    button.addEventListener('click', function () {
                        const previousValue = select.value;
                        select.value = option.value;
                        select.dataset.selectedValue = option.value;
                        if (previousValue !== option.value) {
                            select.dispatchEvent(new Event('input', { bubbles: true }));
                        }
                        select.dispatchEvent(new Event('change', { bubbles: true }));
                        select.dispatchEvent(new CustomEvent('searchable-select:changed', {
                            bubbles: true,
                            detail: {
                                value: option.value,
                                previousValue: previousValue,
                            },
                        }));
                        if (select.id === 'product_id' && typeof window.__phosHandlePrimaryRentalProductSelection === 'function') {
                            window.__phosHandlePrimaryRentalProductSelection();
                        }
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
                positionProductPanel();
                window.requestAnimationFrame(function () {
                    positionProductPanel();
                    searchInput.focus();
                });
            }

            function refresh() {
                if (select.value) {
                    select.dataset.selectedValue = select.value;
                } else if (select.dataset.selectedValue && !select.querySelector(`option[value="${select.dataset.selectedValue}"]`)) {
                    delete select.dataset.selectedValue;
                }
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
            window.addEventListener('resize', positionProductPanel);
            window.addEventListener('scroll', positionProductPanel, true);
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
                    const previousValue = select.value;
                    select.value = visibleOptions[activeIndex].value;
                    select.dataset.selectedValue = visibleOptions[activeIndex].value;
                    if (previousValue !== visibleOptions[activeIndex].value) {
                        select.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                    select.dispatchEvent(new CustomEvent('searchable-select:changed', {
                        bubbles: true,
                        detail: {
                            value: visibleOptions[activeIndex].value,
                            previousValue: previousValue,
                        },
                    }));
                    if (select.id === 'product_id' && typeof window.__phosHandlePrimaryRentalProductSelection === 'function') {
                        window.__phosHandlePrimaryRentalProductSelection();
                    }
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

            select._searchableSelect = {
                refresh: refresh,
                trigger: trigger,
                triggerLabel: triggerLabel,
                wrapper: wrapper,
            };
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
            option.textContent = [client.name, client.phone].filter(Boolean).join(' - ') || client.name || 'Actual client';
            option.setAttribute('data-business-partner-id', String(client.business_partner_id || ''));
            option.setAttribute('data-name', client.name || '');
            option.setAttribute('data-phone', client.phone || '');
            option.setAttribute('data-phone-country', client.phone_country || '');
            option.setAttribute('data-state', client.state || '');
            option.setAttribute('data-address', client.address || '');
            option.setAttribute('data-city', client.city || '');
            option.setAttribute('data-location', client.location || '');
            option.setAttribute('data-notes', client.delivery_notes || '');
            option.setAttribute('data-search', client.search || [client.name, client.phone, client.address, client.city, client.state].filter(Boolean).join(' - '));
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
                ? [customer.getAttribute('data-address'), customer.getAttribute('data-city'), customer.getAttribute('data-state')].filter(Boolean).join(' - ')
                : '';
            const customerLocation = customer?.getAttribute('data-location') || '';
            const clientAddressText = client
                ? [client.address, client.city, client.state].filter(Boolean).join(' - ')
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
                    ? [partner.name, partner.phone].filter(Boolean).join(' - ')
                    : 'Select a business partner';
                deliveryContactSummary.textContent = client
                    ? [client.name, client.phone].filter(Boolean).join(' - ')
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
            reminderContactSummary.textContent = [customerNameText, customerPhoneText].filter(Boolean).join(' - ');
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
            if (warehouseMetric) {
                warehouseMetric.textContent = warehouseSelect.value ? selected.textContent.trim() : 'Any warehouse';
            }
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

        function syncPrimaryProductInputFromWidget() {
            if (!productSelect) {
                return;
            }

            if (productSelect.value) {
                return;
            }

            const triggerLabel = (productSelect?._searchableSelect?.triggerLabel?.textContent || '').trim();
            if (!triggerLabel || /^select (rental )?product$/i.test(triggerLabel)) {
                return;
            }

            const normalizeProductLabel = (value) => String(value || '')
                .replace(/\s+/g, ' ')
                .trim()
                .toLowerCase();
            const normalizedTrigger = normalizeProductLabel(triggerLabel);

            const matchedOption = Array.from(productSelect.options).find(function (option) {
                if (!option.value) {
                    return false;
                }

                const displayLabel = normalizeProductLabel(optionDisplayLabel(productSelect, option));
                const productName = normalizeProductLabel(option.getAttribute('data-product-name') || option.textContent || '');

                return displayLabel === normalizedTrigger
                    || productName === normalizedTrigger
                    || (productName && normalizedTrigger.startsWith(productName))
                    || (displayLabel && normalizedTrigger.startsWith(displayLabel));
            });

            if (matchedOption) {
                productSelect.value = matchedOption.value;
                productSelect.dataset.selectedValue = matchedOption.value;
            }
        }

        function selectedPrimaryProductValue() {
            syncPrimaryProductInputFromWidget();
            return productSelect.value || '';
        }

        function selectedPrimaryProductOption() {
            const selectedValue = selectedPrimaryProductValue();
            if (!selectedValue) {
                return null;
            }

            return Array.from(productSelect.options).find(function (option) {
                return option.value === selectedValue;
            }) || null;
        }

        function currentFulfilmentSourceValue() {
            return document.getElementById('fulfilment_source')?.value || '';
        }

        function currentSelectedCityId() {
            return document.getElementById('city_id')?.value || '';
        }

        function selectedPrimaryRentalState() {
            const selected = selectedPrimaryProductOption();
            const isVendorSupplied = currentFulfilmentSourceValue() === 'vendor_supplied';

            if (!selected || !selectedPrimaryProductValue()) {
                return null;
            }

            if (isVendorSupplied) {
                return {
                    name: selected.getAttribute('data-product-name') || '',
                    available: null,
                    status: 'vendor_catalog',
                    label: 'Vendor supplied.',
                };
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

            if (tracksRental && status !== 'sale_only' && primaryAvailabilityLoadedFor === selectedPrimaryProductValue()) {
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
                availabilityHint.textContent = 'Available: 0';
                return;
            }

            if (state.status === 'sale_only') {
                availabilityHint.textContent = 'Sale only. Not available for rental.';
                return;
            }

            if (state.status === 'vendor_catalog') {
                availabilityHint.textContent = 'Vendor supplied.';
                return;
            }

            availabilityHint.textContent = 'Available: ' + state.available;
        }

        function updatePrimaryRentalFeedback() {
            const state = selectedPrimaryRentalState();
            const requestedQuantity = Math.max(parseInt(quantityInput.value || '0', 10), 0);
            let warningText = '';
            let disableSubmit = false;

            if (!state) {
                const assetWarningText = window.primaryAssetServerValidationMessage || '';
                productRentalWarning.style.display = assetWarningText ? 'block' : 'none';
                productRentalWarning.textContent = assetWarningText;
                rentalSaveGuidance.textContent = assetWarningText || 'Check required items.';
                rentalSubmitButton.disabled = false;
                return;
            }

            if (state.status === 'vendor_catalog') {
                const assetWarningText = window.primaryAssetServerValidationMessage || '';
                productRentalWarning.style.display = assetWarningText ? 'block' : 'none';
                productRentalWarning.textContent = assetWarningText;
                rentalSaveGuidance.textContent = assetWarningText || 'Vendor supplied rentals do not reserve PH stock or rental assets.';
                rentalSubmitButton.disabled = false;
                return;
            }

            const selectedCount = selectedAssetCount();
            const hasRequiredAssets = requestedQuantity > 0 && selectedCount >= requestedQuantity;

            if (state.status === 'sale_only') {
                warningText = unavailableRentalMessage;
                disableSubmit = true;
            } else if (!hasRequiredAssets && state.available <= 0) {
                warningText = unavailableRentalMessage;
                disableSubmit = true;
            } else if (!hasRequiredAssets && requestedQuantity > state.available) {
                warningText = 'Only ' + state.available + ' - ' + (warehouseSelect.value ? ' in the selected warehouse.' : '.');
                disableSubmit = true;
            }

            warningText = warningText || window.primaryAssetServerValidationMessage || '';
            productRentalWarning.textContent = warningText;
            productRentalWarning.style.display = warningText ? 'block' : 'none';
            rentalSaveGuidance.textContent = warningText || 'Ready when all required items are complete.';
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

            if (pricing.days15 > 0) {
                return pricing.days15;
            }

            if (pricing.days90 > 0) {
                return pricing.days90;
            }

            return pricing.perDay > 0 && days > 0 ? pricing.perDay * days : pricing.perDay;
        }

        function syncPrimaryRentalAmount(forceUpdate) {
            if (!rentalAmountInput) {
                return;
            }

            if (!forceUpdate && rentalAmountTouched) {
                syncingRentalAmount = true;
                rentalAmountInput.dispatchEvent(new Event('input', { bubbles: true }));
                syncingRentalAmount = false;
                return;
            }

            const quantity = Math.max(parseInt(quantityInput.value || '0', 10), 0);
            const unitPrice = defaultRentalUnitPrice();
            const lineTotal = unitPrice > 0 && quantity > 0 ? unitPrice * quantity : 0;

            rentalAmountInput.value = lineTotal > 0 ? lineTotal.toFixed(2) : '0';
            syncingRentalAmount = true;
            rentalAmountInput.dispatchEvent(new Event('input', { bubbles: true }));
            syncingRentalAmount = false;
        }

        function handlePrimaryProductChange() {
            const nextProductValue = selectedPrimaryProductValue();
            const productChanged = primaryProductInitialized && nextProductValue !== lastPrimaryProductValue;

            lastPrimaryProductValue = nextProductValue;
            primaryProductInitialized = true;

            if (productChanged) {
                rentalAmountTouched = false;
                clearPrimaryAssetSelection();
                primaryAvailabilityLoadedFor = null;
                lastPrimaryAssetSignature = null;
                syncPrimaryTaxDefaults(true);
            }

            updateAvailabilityHint();
            updatePrimaryRentalFeedback();

            if (productChanged) {
                fetchAssets();
                syncPrimaryRentalAmount(true);

                window.requestAnimationFrame(function () {
                    syncPrimaryRentalAmount(true);
                    syncPrimaryAssetStateIfNeeded();
                });
                return;
            }

            syncPrimaryAssetStateIfNeeded();
        }

        lastPrimaryProductValue = selectedPrimaryProductValue();
        primaryProductInitialized = true;

        window.__phosHandlePrimaryRentalProductSelection = handlePrimaryProductChange;
        window.__phosRentalAssetState = function () {
            return {
                activeItemIndex: activeAssetAssignmentItemIndex,
                modalProductId: activeAssetAssignmentProductId,
                modalWarehouseId: activeAssetAssignmentWarehouseId,
                modalRequiredQty: activeAssetAssignmentRequiredQty,
                hiddenAssetIds: window.__phosPrimaryRentalAssetIds?.() || [],
                selectedAssetIds: normalizeAssetIds(selectedAssetIds),
                latestPrimaryAssetRequestUrl: latestPrimaryAssetRequestUrl,
                latestPrimaryAssetResponseCount: latestPrimaryAssetResponseCount,
                latestAssetHideReason: latestAssetHideReason,
            };
        };

        function syncPrimaryAssetStateIfNeeded() {
            const signature = [
                currentFulfilmentSourceValue(),
                selectedPrimaryProductValue(),
                warehouseSelect?.value || '',
            ].join(' - ');

            if (signature === lastPrimaryAssetSignature) {
                return;
            }

            lastPrimaryAssetSignature = signature;

            if (currentFulfilmentSourceValue() === 'vendor_supplied') {
                currentAssets = [];
                primaryAvailabilityLoadedFor = null;
                renderAssets();
                updateSelectionMetrics();
                return;
            }

            fetchAssets();
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
                ].join(' - ').toLowerCase().includes(term);
            });
        }

        function preloadedPrimaryAssets() {
            const productId = String(selectedPrimaryProductValue() || '');
            if (!productId) {
                return null;
            }

            const productEntry = primaryRentalAssetIndex[productId];
            if (!productEntry) {
                return null;
            }

            const warehouseId = String(warehouseSelect?.value || '');
            if (warehouseId && productEntry.warehouses && Array.isArray(productEntry.warehouses[warehouseId])) {
                return productEntry.warehouses[warehouseId];
            }

            return Array.isArray(productEntry.all) ? productEntry.all : null;
        }

        function refreshAssetDebugPanel() {
            return;
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

            return options.join(' - ');
        }

        function updateSelectionMetrics() {
            syncPrimaryAssetHiddenInputs();
            const quantity = parseInt(quantityInput.value || '0', 10);
            const selectedCount = selectedAssetIds.length;
            const hasAssets = currentAssets.length > 0;

            if (quantityMetric) {
                quantityMetric.textContent = quantity || 0;
            }
            if (selectedAssetCount) {
                selectedAssetCount.textContent = selectedCount;
            }
            assetRequiredCount.textContent = quantity || 0;
            assetSelectedCountInline.textContent = selectedCount;
            assetSelectedLabel.textContent = selectedCount + ' chosen';

            if (!selectedPrimaryProductValue()) {
                assetSelectionStatus.textContent = 'Select product';
                assetSelectionHelp.textContent = 'Load assets from product.';
                selectionAlert.style.display = 'none';
                latestAssetHideReason = 'no_product_selected';
                updateAssignmentDrawerSummary();
                refreshAssetDebugPanel();
                document.dispatchEvent(new CustomEvent('phos:rental-assets-updated', { detail: selectedPrimaryAssetSummary() }));
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

                latestAssetHideReason = hasAssets ? 'awaiting_asset_selection' : 'no_assets_available';
                updateAssignmentDrawerSummary();
                refreshAssetDebugPanel();
                document.dispatchEvent(new CustomEvent('phos:rental-assets-updated', { detail: selectedPrimaryAssetSummary() }));
                return;
            }

            if (quantity && selectedCount !== quantity) {
                assetSelectionStatus.textContent = 'Qty mismatch';
                assetSelectionHelp.textContent = quantity + ' asset' + (quantity === 1 ? '' : 's') + ' required. ' + selectedCount + ' selected.';
                selectionAlert.style.display = 'block';
                selectionAlert.textContent = quantity + ' asset' + (quantity === 1 ? '' : 's') + ' required. ' + selectedCount + ' selected.';
            } else {
                assetSelectionStatus.textContent = 'Ready';
                assetSelectionHelp.textContent = 'Assets will be linked.';
                selectionAlert.style.display = 'none';
            }

            latestAssetHideReason = selectedCount === quantity ? 'ready' : 'quantity_mismatch';
            updateAssignmentDrawerSummary();
            refreshAssetDebugPanel();
            document.dispatchEvent(new CustomEvent('phos:rental-assets-updated', { detail: selectedPrimaryAssetSummary() }));
        }

        function syncDurationPreset() {
            if (!startDateInput.value || !durationPresetSelect.value || durationPresetSelect.value === 'custom') {
                syncPrimaryRentalAmount(true);
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
            syncPrimaryRentalAmount(true);
        }

        function getAssetCardLabel(asset) {
            return asset.label || asset.serial_number;
        }

        function selectedPrimaryAssets() {
            return selectedAssetIds
                .map(function (assetId) {
                    return currentAssets.find(function (asset) {
                        return parseInt(asset.id || '0', 10) === parseInt(assetId || '0', 10);
                    });
                })
                .filter(Boolean);
        }

        function selectedPrimaryAssetLabel() {
            const selectedAssets = selectedPrimaryAssets();

            if (!selectedAssets.length) {
                return 'Asset not assigned';
            }

            return selectedAssets.map(getAssetCardLabel).join(' - ');
        }

        function selectedPrimaryAssetSummary() {
            const selectedAssets = selectedPrimaryAssets();
            const firstAsset = selectedAssets[0] || null;

            return {
                count: selectedAssets.length,
                serial: firstAsset?.serial_number || firstAsset?.barcode_value || firstAsset?.label || '',
            };
        }

        document.addEventListener('phos:rental-assets-dynamic-selected', function (event) {
            const detail = event.detail || {};
            const incomingAssets = Array.isArray(detail.assets) ? detail.assets : [];
            const incomingIds = normalizeAssetIds(detail.assetIds || incomingAssets.map(function (asset) { return asset.id; }));

            incomingAssets.forEach(function (asset) {
                const normalizedAsset = normalizeRentalStockAsset(asset);
                const exists = currentAssets.some(function (existingAsset) {
                    return normalizeAssetId(existingAsset.id) === normalizeAssetId(normalizedAsset.id);
                });

                if (!exists) {
                    currentAssets.push(normalizedAsset);
                }
            });

            selectedAssetIds = incomingIds;
            syncPrimaryAssetHiddenInputs();
            renderAssets();
            updateSelectionMetrics();
            updateAssignmentDrawerSummary();
            safeUpdateSummary();
            renderRentalItems();
            document.dispatchEvent(new CustomEvent('phos:rental-assets-applied', { detail: selectedPrimaryAssetSummary() }));
        });

        function currentProductLabel() {
            const option = selectedPrimaryProductOption();

            return option?.getAttribute('data-product-name') || option?.textContent?.trim() || 'Select product';
        }

        function currentWarehouseLabel() {
            return warehouseSelect?.selectedOptions?.[0]?.textContent?.trim() || 'Any warehouse';
        }

        function updateAssignmentDrawerSummary() {
            const selectedCount = selectedAssetIds.length;
            const requiredCount = Math.max(parseInt(quantityInput.value || '0', 10), 0);
            const assetLabel = selectedPrimaryAssetLabel();
            const productLabel = currentProductLabel();
            const warehouseLabel = currentWarehouseLabel();
            const statusLabel = selectedCount
                ? (requiredCount && selectedCount !== requiredCount ? 'Quantity mismatch' : 'Ready')
                : 'Select asset';

            if (assignmentLaunchTitle) {
                assignmentLaunchTitle.textContent = assetLabel;
            }
            if (assignmentLaunchProduct) {
                assignmentLaunchProduct.textContent = productLabel;
            }
            if (assignmentLaunchWarehouse) {
                assignmentLaunchWarehouse.textContent = warehouseLabel;
            }
            if (assignmentLaunchRequired) {
                assignmentLaunchRequired.textContent = requiredCount || 0;
            }
            if (assignmentLaunchSelected) {
                assignmentLaunchSelected.textContent = selectedCount;
            }
            if (assignmentDrawerAsset) {
                assignmentDrawerAsset.textContent = assetLabel;
            }
            if (assignmentDrawerProduct) {
                assignmentDrawerProduct.textContent = productLabel;
            }
            if (assignmentDrawerStatus) {
                assignmentDrawerStatus.textContent = statusLabel;
            }
            if (assignmentDrawerWarehouse) {
                assignmentDrawerWarehouse.textContent = warehouseLabel;
            }
        }

        function currentPrimaryAssetAssignmentContext() {
            syncPrimaryProductInputFromWidget();

            activeAssetAssignmentItemIndex = 0;
            activeAssetAssignmentProductId = String(selectedPrimaryProductValue() || '');
            activeAssetAssignmentWarehouseId = String(warehouseSelect?.value || '');
            activeAssetAssignmentRequiredQty = Math.max(parseInt(quantityInput?.value || '0', 10), 0);

            return {
                itemIndex: activeAssetAssignmentItemIndex,
                productId: activeAssetAssignmentProductId,
                productName: currentProductLabel(),
                warehouseId: activeAssetAssignmentWarehouseId,
                warehouseName: currentWarehouseLabel(),
                quantity: activeAssetAssignmentRequiredQty,
                selectedAssetIds: normalizeAssetIds(selectedAssetIds),
            };
        }

        function showAssetAssignmentContextMessage(message, status = 'Select product') {
            if (assetCountNote) {
                assetCountNote.textContent = message;
            }
            if (assetSelectionStatus) {
                assetSelectionStatus.textContent = status;
            }
            if (assetSelectionHelp) {
                assetSelectionHelp.textContent = message;
            }
            if (assetEmptyState) {
                assetEmptyState.style.display = 'block';
                assetEmptyState.textContent = message;
            }
            if (selectionAlert) {
                selectionAlert.style.display = 'block';
                selectionAlert.textContent = message;
            }
            refreshAssetDebugPanel();
        }

        function syncAssetAssignmentDrawerContext() {
            const context = currentPrimaryAssetAssignmentContext();
            updateAssignmentDrawerSummary();

            if (!context.productId) {
                currentAssets = [];
                renderAssets();
                latestAssetHideReason = 'drawer_open_missing_product';
                showAssetAssignmentContextMessage('Select rental product first.', 'Select product');
                return context;
            }

            if (currentFulfilmentSourceValue() !== 'vendor_supplied' && !context.warehouseId) {
                currentAssets = [];
                renderAssets();
                latestAssetHideReason = 'drawer_open_missing_warehouse';
                showAssetAssignmentContextMessage('Select warehouse first.', 'Select warehouse');
                return context;
            }

            const loadedForProduct = primaryAvailabilityLoadedFor === context.productId;
            const loadedForWarehouse = latestPrimaryAssetRequestUrl === 'preloaded:index'
                || !context.warehouseId
                || latestPrimaryAssetRequestUrl.includes('dispatch_warehouse_id=' + encodeURIComponent(context.warehouseId));

            if (!loadedForProduct || !loadedForWarehouse) {
                fetchAssets();
            } else {
                renderAssets();
                updateSelectionMetrics();
            }

            return context;
        }

        function openAssetAssignmentDrawer() {
            if (!assetAssignmentDrawer) {
                alert('Asset assignment modal not found');
                return;
            }

            syncAssetAssignmentDrawerContext();
            assetAssignmentDrawer.hidden = false;
            assetAssignmentDrawer.removeAttribute('hidden');
            assetAssignmentDrawer.setAttribute('aria-hidden', 'false');
            assetAssignmentDrawer.classList.add('is-open');
            assetAssignmentDrawer.style.display = 'flex';
            assetAssignmentDrawer.style.position = 'fixed';
            assetAssignmentDrawer.style.inset = '0';
            assetAssignmentDrawer.style.zIndex = '99999';
            document.body.classList.add('assignment-drawer-open');
            updateAssignmentDrawerSummary();
            window.setTimeout(function () {
                assetSearch?.focus();
            }, 80);
        }

        function closeAssetAssignmentDrawer(showToast = false) {
            if (!assetAssignmentDrawer) {
                return;
            }

            assetAssignmentDrawer.hidden = true;
            assetAssignmentDrawer.setAttribute('hidden', 'hidden');
            assetAssignmentDrawer.setAttribute('aria-hidden', 'true');
            assetAssignmentDrawer.classList.remove('is-open');
            assetAssignmentDrawer.style.display = 'none';
            document.body.classList.remove('assignment-drawer-open');

            if (showToast && assetAssignmentToast) {
                assetAssignmentToast.hidden = false;
                window.setTimeout(function () {
                    assetAssignmentToast.hidden = true;
                }, 2200);
            }
        }

        function showRentalStockMessage(message, type = 'warning') {
            if (!rentalStockMessage) {
                return;
            }

            rentalStockMessage.textContent = message || '';
            rentalStockMessage.classList.toggle('is-error', type === 'error');
            rentalStockMessage.hidden = !message;
        }

        function rentalStockSelectedProductOption() {
            const selectedValue = rentalStockProductSelect?.value || '';
            if (!selectedValue) {
                return null;
            }

            return Array.from(rentalStockProductSelect.options).find(function (option) {
                return option.value === selectedValue;
            }) || null;
        }

        function syncRentalStockWarehouseOptions() {
            if (!rentalStockWarehouseSelect) {
                return;
            }

            const selectedCity = String(currentSelectedCityId() || '');
            const currentValue = rentalStockWarehouseSelect.value;
            let currentStillVisible = currentValue === '';

            Array.from(rentalStockWarehouseSelect.options).forEach(function (option) {
                if (!option.value) {
                    option.hidden = false;
                    option.disabled = false;
                    return;
                }

                const optionCityId = String(option.getAttribute('data-city-id') || '');
                const shouldShow = !selectedCity || !optionCityId || optionCityId === selectedCity;
                option.hidden = !shouldShow;
                option.disabled = !shouldShow;

                if (shouldShow && option.value === currentValue) {
                    currentStillVisible = true;
                }
            });

            if (!currentStillVisible) {
                rentalStockWarehouseSelect.value = '';
            }
        }

        function syncRentalStockModalState() {
            if (!rentalStockModal) {
                return true;
            }

            if (rentalStockFulfilmentInput) {
                rentalStockFulfilmentInput.value = currentFulfilmentSourceValue() || 'in_house';
            }
            if (rentalStockCityInput) {
                rentalStockCityInput.value = currentSelectedCityId() || '';
            }
            if (rentalStockProductSelect && selectedPrimaryProductValue() && rentalStockProductSelect.value !== selectedPrimaryProductValue()) {
                rentalStockProductSelect.value = selectedPrimaryProductValue();
            }

            syncRentalStockWarehouseOptions();

            if (rentalStockWarehouseSelect && warehouseSelect?.value) {
                const matchingWarehouse = Array.from(rentalStockWarehouseSelect.options).find(function (option) {
                    return option.value === warehouseSelect.value && !option.disabled;
                });
                if (matchingWarehouse) {
                    rentalStockWarehouseSelect.value = warehouseSelect.value;
                }
            }

            const selectedProduct = rentalStockSelectedProductOption();
            let blockedMessage = '';

            if (currentFulfilmentSourceValue() === 'vendor_supplied') {
                blockedMessage = 'Vendor-supplied rentals do not require PH rental stock. Please continue with vendor fulfilment.';
            } else if (!selectedProduct || selectedProduct.getAttribute('data-rentable') !== '1' || selectedProduct.getAttribute('data-tracks-rental') !== '1') {
                blockedMessage = 'Rental stock can be added only for reusable/rentable equipment.';
            }

            showRentalStockMessage(blockedMessage, blockedMessage ? 'error' : 'warning');
            if (saveRentalStockButton) {
                saveRentalStockButton.disabled = Boolean(blockedMessage);
            }

            return !blockedMessage;
        }

        function openRentalStockModal() {
            if (!rentalStockModal) {
                return;
            }

            rentalStockModal.hidden = false;
            rentalStockModal.classList.add('is-open');
            rentalStockModal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('assignment-drawer-open');
            document.body.classList.add('modal-open');
            syncRentalStockModalState();
            window.setTimeout(function () {
                const focusTarget = selectedPrimaryProductValue() ? rentalStockSerialInput : rentalStockProductSelect;
                focusTarget?.focus();
            }, 80);
        }

        function closeRentalStockModal(showToast = false) {
            if (!rentalStockModal) {
                return;
            }

            rentalStockModal.classList.remove('is-open');
            rentalStockModal.setAttribute('aria-hidden', 'true');
            rentalStockModal.hidden = true;
            document.body.classList.remove('assignment-drawer-open');
            document.body.classList.remove('modal-open');
            if (window.location.hash === '#rentalStockModal' && window.history?.replaceState) {
                window.history.replaceState(null, '', window.location.pathname + window.location.search);
            }
            showRentalStockMessage('');

            if (showToast && rentalStockToast) {
                rentalStockToast.hidden = false;
                window.setTimeout(function () {
                    rentalStockToast.hidden = true;
                }, 2400);
            }
        }

        function normalizeRentalStockAsset(asset) {
            return {
                id: parseInt(asset.id || '0', 10),
                product_id: parseInt(asset.product_id || '0', 10),
                warehouse_id: parseInt(asset.warehouse_id || '0', 10),
                label: asset.label || asset.serial_number || ('Asset #' + asset.id),
                serial_number: asset.serial_number || '',
                barcode_value: asset.barcode_value || '',
                asset_status: asset.asset_status || 'available',
                condition_status: asset.condition_status || '',
                warehouse: asset.warehouse || ''
            };
        }

        function addAssetToPrimaryIndex(asset) {
            const normalizedAsset = normalizeRentalStockAsset(asset);
            if (!normalizedAsset.id || !normalizedAsset.product_id) {
                return;
            }

            const productKey = String(normalizedAsset.product_id);
            const warehouseKey = String(normalizedAsset.warehouse_id || '');
            primaryRentalAssetIndex[productKey] = primaryRentalAssetIndex[productKey] || { all: [], warehouses: {} };
            const productEntry = primaryRentalAssetIndex[productKey];
            productEntry.all = Array.isArray(productEntry.all) ? productEntry.all : [];
            productEntry.warehouses = productEntry.warehouses || {};

            if (!productEntry.all.some(function (existing) { return parseInt(existing.id || '0', 10) === normalizedAsset.id; })) {
                productEntry.all.unshift(normalizedAsset);
            }

            if (warehouseKey) {
                productEntry.warehouses[warehouseKey] = Array.isArray(productEntry.warehouses[warehouseKey])
                    ? productEntry.warehouses[warehouseKey]
                    : [];
                if (!productEntry.warehouses[warehouseKey].some(function (existing) { return parseInt(existing.id || '0', 10) === normalizedAsset.id; })) {
                    productEntry.warehouses[warehouseKey].unshift(normalizedAsset);
                }
            }
        }


        function addAssetToRentalItemCache(asset) {
            const normalizedAsset = normalizeRentalStockAsset(asset);
            if (!normalizedAsset.id || !normalizedAsset.product_id) {
                return;
            }
            const currentWarehouseId = parseInt(warehouseSelect?.value || '0', 10);
            if (currentWarehouseId && normalizedAsset.warehouse_id && normalizedAsset.warehouse_id !== currentWarehouseId) {
                return;
            }
            const cacheKey = rentalItemAssetCacheKey(normalizedAsset.product_id);
            rentalItemAssetCache[cacheKey] = Array.isArray(rentalItemAssetCache[cacheKey]) ? rentalItemAssetCache[cacheKey] : [];
            if (!rentalItemAssetCache[cacheKey].some(function (existing) { return parseInt(existing.id || '0', 10) === normalizedAsset.id; })) {
                rentalItemAssetCache[cacheKey].unshift(normalizedAsset);
            }
            rentalItems.forEach(function (item, index) {
                if (parseInt(item.product_id || '0', 10) === normalizedAsset.product_id) {
                    syncRentalItem(index);
                }
            });
        }
        function updateProductOptionAvailability(asset) {
            const normalizedAsset = normalizeRentalStockAsset(asset);
            const productOption = Array.from(productSelect?.options || []).find(function (option) {
                return option.value === String(normalizedAsset.product_id);
            });

            if (!productOption) {
                return;
            }

            const nextAvailable = Math.max(parseInt(productOption.getAttribute('data-rental-available') || productOption.getAttribute('data-available') || '0', 10), 0) + 1;
            const productName = productOption.getAttribute('data-product-name') || productOption.textContent.split('|')[0].trim();
            const nextLabel = 'Rental Available ' + nextAvailable;
            const inHouseLabel = productName + ' - ' + nextLabel;

            productOption.setAttribute('data-rental-available', String(nextAvailable));
            productOption.setAttribute('data-available', String(nextAvailable));
            productOption.setAttribute('data-rental-status', 'rental_available');
            productOption.setAttribute('data-rental-label', nextLabel);
            productOption.setAttribute('data-in-house-option-label', inHouseLabel);

            if (currentFulfilmentSourceValue() !== 'vendor_supplied') {
                productOption.setAttribute('data-display-label', inHouseLabel);
                productOption.textContent = inHouseLabel;
            }

            if (normalizedAsset.warehouse_id) {
                let warehouseQuantities = {};
                try {
                    warehouseQuantities = JSON.parse(productOption.getAttribute('data-rental-warehouse-quantities') || '{}') || {};
                } catch (error) {
                    warehouseQuantities = {};
                }
                const warehouseKey = String(normalizedAsset.warehouse_id);
                warehouseQuantities[warehouseKey] = Math.max(parseInt(warehouseQuantities[warehouseKey] || '0', 10), 0) + 1;
                productOption.setAttribute('data-rental-warehouse-quantities', JSON.stringify(warehouseQuantities));
            }

            productSelect?._searchableSelect?.refresh?.();
        }

        function injectRentalStockAsset(asset) {
            const normalizedAsset = normalizeRentalStockAsset(asset);
            addAssetToPrimaryIndex(normalizedAsset);
            addAssetToRentalItemCache(normalizedAsset);
            updateProductOptionAvailability(normalizedAsset);
            primaryAvailabilityLoadedFor = null;
            lastPrimaryAssetSignature = null;
            const selectedProductId = String(selectedPrimaryProductValue() || '');
            const selectedWarehouseId = String(warehouseSelect?.value || '');
            const assetProductId = String(normalizedAsset.product_id || '');
            const assetWarehouseId = String(normalizedAsset.warehouse_id || '');

            if (
                selectedProductId
                && selectedProductId === assetProductId
                && (!selectedWarehouseId || selectedWarehouseId === assetWarehouseId)
            ) {
                currentAssets = currentAssets.filter(function (existingAsset) {
                    return parseInt(existingAsset.id || '0', 10) !== normalizedAsset.id;
                });
                currentAssets.unshift(normalizedAsset);
                primaryAvailabilityLoadedFor = selectedProductId;
                latestPrimaryAssetResponseCount = currentAssets.length;
                visibleAssetCount = Math.max(visibleAssetCount, assetVisibleStep);
                renderAssets();
                updateSelectionMetrics();
            }

            fetchAssets();
            updateAvailabilityHint();
            updatePrimaryRentalFeedback();
            renderRentalItems();
        }

        document.addEventListener('phos:rental-stock-created', function (event) {
            if (event.detail?.asset) {
                injectRentalStockAsset(event.detail.asset);
            }
        });

        function syncPrimaryAssetHiddenInputs() {
            if (!primaryAssetHiddenInputs) {
                return;
            }

            const checkedCardAssetIds = Array.from(assetGrid?.querySelectorAll('input[data-asset-id]:checked') || [])
                .map(function (input) { return input.getAttribute('data-asset-id') || input.value; });
            const hiddenInputAssetIds = Array.from(primaryAssetHiddenInputs.querySelectorAll('input[type="hidden"][name="asset_ids[]"]'))
                .map(function (input) { return input.value; });
            const submittedAssetIds = normalizeAssetIds([].concat(selectedAssetIds, checkedCardAssetIds, hiddenInputAssetIds));
            selectedAssetIds = submittedAssetIds;
            primaryAssetHiddenInputs.innerHTML = submittedAssetIds.map(function (assetId) {
                return `<input type="hidden" name="asset_ids[]" value="${assetId.replace(/&/g, '&amp;').replace(/"/g, '&quot;')}">`;
            }).join('');
            if (submittedAssetIds.length) {
                clearPrimaryAssetServerValidationMessage();
            }
            const primaryAssetIdsInput = document.getElementById('primary_asset_ids');
            if (primaryAssetIdsInput) {
                primaryAssetIdsInput.value = submittedAssetIds.join(',');
            }
        }
        function clearPrimaryAssetSelection() {
            selectedAssetIds = [];
            syncPrimaryAssetHiddenInputs();
            notifyPrimaryAssetUserChanged();
        }

        function toggleAssetSelection(assetId) {
            const normalizedAssetId = normalizeAssetId(assetId);

            if (!normalizedAssetId) {
                return;
            }

            if (!selectedAssetIds.includes(normalizedAssetId)) {
                selectedAssetIds = normalizeAssetIds(selectedAssetIds.concat(normalizedAssetId));
            } else {
                selectedAssetIds = selectedAssetIds.filter(function (selectedId) {
                    return selectedId !== normalizedAssetId;
                });
            }

            syncPrimaryAssetHiddenInputs();
            notifyPrimaryAssetUserChanged();
            renderAssets();
            updateSelectionMetrics();
            renderRentalItems();
        }

        function renderAssets() {
            assetGrid.innerHTML = '';

            const filtered = getFilteredAssets();
            const prioritizedAssets = filtered.slice().sort(function (left, right) {
                return Number(selectedAssetIds.includes(normalizeAssetId(right.id))) - Number(selectedAssetIds.includes(normalizeAssetId(left.id)));
            });
            selectedAssetIds = normalizeAssetIds(selectedAssetIds);
            const displayCount = Math.max(visibleAssetCount, selectedAssetIds.length);
            const visibleAssets = prioritizedAssets.slice(0, displayCount);
            const visibleAssetIds = visibleAssets.map(function (asset) {
                return normalizeAssetId(asset.id);
            });

            renderPrimaryAssetHiddenInputs(visibleAssetIds);

            if (!selectedPrimaryProductValue()) {
                assetEmptyState.style.display = 'block';
                assetEmptyState.textContent = 'Select product';
                assetLoadMoreWrap.style.display = 'none';
                latestAssetHideReason = 'render_blocked_no_product';
                refreshAssetDebugPanel();
                return;
            }

            if (!filtered.length) {
                assetEmptyState.style.display = 'block';
                assetEmptyState.textContent = currentAssets.length
                    ? 'No match found.'
                    : 'No assets available.';
                assetLoadMoreWrap.style.display = 'none';
                latestAssetHideReason = currentAssets.length ? 'filtered_no_match' : 'render_no_assets';
                refreshAssetDebugPanel();
                return;
            }

            assetEmptyState.style.display = 'none';

            visibleAssets.forEach(function (asset) {
                const normalizedAssetId = normalizeAssetId(asset.id);
                const isSelected = selectedAssetIds.includes(normalizedAssetId);
                const card = document.createElement('label');
                card.className = 'asset-card' + (isSelected ? ' is-selected' : '');
                card.setAttribute('role', 'button');
                card.setAttribute('tabindex', '0');
                card.setAttribute('aria-pressed', isSelected ? 'true' : 'false');
                card.innerHTML = `
                    <input type="checkbox" data-asset-id="${normalizedAssetId}" ${isSelected ? 'checked' : ''}>
                    <div class="asset-card-head">
                        <strong>${getAssetCardLabel(asset)}</strong>
                        <span class="asset-card-pill${isSelected ? ' is-selected' : ''}">${isSelected ? 'Assigned' : 'Select'}</span>
                    </div>
                    <small class="asset-card-serial">Serial: ${asset.serial_number || '-'}</small>
                    <small class="asset-card-meta-extra">Barcode: ${asset.barcode_value || '-'}</small>
                    <small class="asset-card-meta-extra">Warehouse: ${asset.warehouse || 'Not set'}</small>
                    <small class="asset-card-status">Status: ${asset.asset_status || '-'} | Condition: ${asset.condition_status || '-'}</small>
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

            assetCountNote.textContent = filtered.length + ' - ' + visibleAssets.length + '.';

            if (prioritizedAssets.length > visibleAssets.length) {
                assetLoadMoreWrap.style.display = 'flex';
                assetLoadMoreButton.textContent = 'Load More (' + (prioritizedAssets.length - visibleAssets.length) + ' remaining)';
            } else {
                assetLoadMoreWrap.style.display = 'none';
            }

            latestAssetHideReason = 'assets_rendered';
            refreshAssetDebugPanel();
        }

        function renderPrimaryAssetHiddenInputs() {
            syncPrimaryAssetHiddenInputs();
        }

        function fetchAssets() {
            const requestToken = ++primaryAssetRequestToken;
            currentAssets = [];
            primaryAvailabilityLoadedFor = null;
            latestPrimaryAssetResponseCount = 0;
            visibleAssetCount = assetVisibleStep;
            renderAssets();
            updateAvailabilityHint();
            updatePrimaryRentalFeedback();

            if (!selectedPrimaryProductValue()) {
                assetCountNote.textContent = 'Select product to load assets.';
                latestAssetHideReason = 'fetch_skipped_no_product';
                refreshAssetDebugPanel();
                return;
            }

            const preloadedAssets = preloadedPrimaryAssets();
            const stateBeforeFetch = selectedPrimaryRentalState();
            const shouldUsePreloadedAssets = Array.isArray(preloadedAssets)
                && (preloadedAssets.length > 0 || !stateBeforeFetch || !stateBeforeFetch.available || stateBeforeFetch.status === 'sale_only');
            if (shouldUsePreloadedAssets) {
                primaryAvailabilityLoadedFor = selectedPrimaryProductValue();
                currentAssets = preloadedAssets;
                latestPrimaryAssetResponseCount = preloadedAssets.length;
        latestPrimaryAssetRequestUrl = 'preloaded:index';
                updateAvailabilityHint();
                updatePrimaryRentalFeedback();
                renderAssets();
                updateSelectionMetrics();
                renderRentalItems();
                return;
            }

            assetEmptyState.style.display = 'block';
            assetEmptyState.textContent = 'Loading assets...';

            const requestAssets = function (withWarehouseFilter) {
                const params = new URLSearchParams({
                    product_id: selectedPrimaryProductValue()
                });

                if (withWarehouseFilter && warehouseSelect.value) {
                    params.append('dispatch_warehouse_id', warehouseSelect.value);
                }

                @if($isEdit)
                    params.append('rental_id', '{{ $rental->id }}');
                @endif
                latestPrimaryAssetRequestUrl = '{{ route('rentals.available-assets') }}?' + params.toString();
                return fetch(latestPrimaryAssetRequestUrl, {
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                }).then(function (response) {
                    if (!response.ok) {
                        throw new Error('Unable to load assets');
                    }

                    return response.json();
                });
            };

            requestAssets(true)
                .then(function (payload) {
                    const initialAssets = Array.isArray(payload.data) ? payload.data : [];
                    const state = selectedPrimaryRentalState();

                    if (
                        !initialAssets.length
                        && warehouseSelect.value
                        && state
                        && state.available
                        && state.status !== 'sale_only'
                    ) {
                        return requestAssets(false);
                    }

                    return payload;
                })
                .then(function (payload) {
                    if (requestToken !== primaryAssetRequestToken) {
                        return;
                    }

                    primaryAvailabilityLoadedFor = selectedPrimaryProductValue();
                    currentAssets = Array.isArray(payload.data) ? payload.data : [];
                    latestPrimaryAssetResponseCount = currentAssets.length;
                    updateAvailabilityHint();
                    updatePrimaryRentalFeedback();
                    renderAssets();
                    updateSelectionMetrics();
                    renderRentalItems();
                })
                .catch(function () {
                    if (requestToken !== primaryAssetRequestToken) {
                        return;
                    }

                    primaryAvailabilityLoadedFor = selectedPrimaryProductValue();
                    currentAssets = [];
                    latestPrimaryAssetResponseCount = 0;
                    assetGrid.innerHTML = '';
                    assetEmptyState.style.display = 'block';
                    assetEmptyState.textContent = 'Unable to load assets.';
                    assetCountNote.textContent = 'Load failed.';
                    latestAssetHideReason = 'fetch_failed';
                    updateAvailabilityHint();
                    updatePrimaryRentalFeedback();
                    refreshAssetDebugPanel();
                });
        }

        function saleItemLineTotal(item) {
            return calculateTaxedLineTotal(item.quantity, item.unit_price, item.gst_rate, item.gst_mode);
        }

        function rentalItemLineTotal(item) {
            return calculateTaxedLineTotal(item.quantity, item.unit_rental_amount, item.gst_rate, item.gst_mode);
        }


        function lineTaxAmount(item, unitKey) {
            const quantity = Math.max(parseFloat(item?.quantity || 0), 0);
            const unitPrice = Math.max(parseFloat(item?.[unitKey] || 0), 0);
            const gstRate = Math.max(parseFloat(item?.gst_rate || 0), 0);
            const gstMode = item?.gst_mode === 'inclusive' ? 'inclusive' : 'exclusive';
            const subtotal = quantity * unitPrice;

            return gstMode === 'exclusive' && gstRate > 0 ? subtotal * (gstRate / 100) : 0;
        }

        function activeAdditionalRentalItems() {
            return (Array.isArray(rentalItems) ? rentalItems : []).filter(function (item) {
                return parseInt(item?.product_id || '0', 10) > 0;
            });
        }

        function activeSaleItems() {
            return (Array.isArray(saleItems) ? saleItems : []).filter(function (item) {
                return parseInt(item?.product_id || '0', 10) > 0;
            });
        }

        function additionalRentalAssetCount() {
            return activeAdditionalRentalItems().reduce(function (count, item) {
                return count + normalizeIdArray(item.asset_ids).length;
            }, 0);
        }

        window.__phosRentalAdditionalSummary = function () {
            const rentalLines = activeAdditionalRentalItems();
            const saleLines = activeSaleItems();
            const rentalTotal = rentalLines.reduce(function (sum, item) {
                return sum + parseFloat(rentalItemLineTotal(item) || '0');
            }, 0);
            const saleTotal = saleLines.reduce(function (sum, item) {
                return sum + parseFloat(saleItemLineTotal(item) || '0');
            }, 0);
            const gstTotal = rentalLines.reduce(function (sum, item) {
                return sum + lineTaxAmount(item, 'unit_rental_amount');
            }, 0) + saleLines.reduce(function (sum, item) {
                return sum + lineTaxAmount(item, 'unit_price');
            }, 0);

            const primaryDateKey = [startDateInput?.value || '', endDateInput?.value || ''].join(' - ');
            const dateKeys = rentalLines.map(function (item) {
                return [item.start_date || startDateInput?.value || '', item.end_date || endDateInput?.value || ''].join(' - ');
            }).filter(Boolean);
            const hasMixedDates = dateKeys.some(function (dateKey) { return dateKey !== primaryDateKey; });

            return {
                rentalCount: rentalLines.length,
                saleCount: saleLines.length,
                rentalTotal: rentalTotal,
                saleTotal: saleTotal,
                gstTotal: gstTotal,
                assetCount: additionalRentalAssetCount(),
                hasMixedDates: hasMixedDates,
            };
        };

        function rentalItemAssetCacheKey(productId) {
            return [parseInt(productId || '0', 10), parseInt(warehouseSelect.value || '0', 10), parseInt(currentRentalId || '0', 10)].join(' - ');
        }

        function rentalCatalogOption(productId) {
            const normalizedId = String(parseInt(productId || '0', 10) || '');

            if (!normalizedId) {
                return null;
            }

            return Array.from(productSelect?.options || []).find(function (option) {
                return option.value === normalizedId;
            }) || null;
        }
        function syncRentalItem(index) {
            const item = rentalItems[index];
            if (!item) {
                return null;
            }

            item.quantity = Math.max(parseInt(item.quantity || '1', 10), 1);
            const option = rentalCatalogOption(item.product_id);
            if (option) {
                if (!item.unit_rental_amount) {
                    const defaultPrice = parseFloat(option.getAttribute('data-default-price') || option.getAttribute('data-rental-price') || option.getAttribute('data-price-per-day') || '0');
                    item.unit_rental_amount = defaultPrice ? defaultPrice.toFixed(2) : item.unit_rental_amount;
                }
                const defaults = selectedProductTaxDefaults({ value: option.value, selectedOptions: [option], options: [option], selectedIndex: 0 });
                item.gst_rate = item.gst_rate || defaults.gstRate;
                item.gst_mode = item.gst_mode || defaults.gstMode;
                item.tax_type = item.tax_type || defaults.taxType;
            }

            ensureInlineAssetState(item);
            ensureRentalItemAssetsLoaded(item);
            return item;
        }

        window.__phosSyncRentalItem = syncRentalItem;
        function rentalItemUsesPhAssets(item) {
            if (currentFulfilmentSourceValue() === 'vendor_supplied') {
                return false;
            }

            const option = rentalCatalogOption(item?.product_id);

            return option ? option.getAttribute('data-tracks-rental') === '1' : false;
        }

        function rentalItemNeedsWarehouseSelection(item) {
            return rentalItemUsesPhAssets(item) && !warehouseSelect.value;
        }

        function autoSelectRentalItemAssets(index, availableAssets) {
            const item = rentalItems[index];

            if (!item || !rentalItemUsesPhAssets(item)) {
                return false;
            }            const quantity = Math.max(parseInt(item.quantity || '0', 10), 0);
            const selectedIds = normalizeIdArray(item.asset_ids);
            const usedElsewhere = selectedRentalAssetIdsOutsideLine(index);
            const selectableAssets = availableAssets.filter(function (asset) {
                const assetId = parseInt(asset?.id || '0', 10);
                return selectedIds.includes(assetId) || !usedElsewhere.includes(assetId);
            });

            if (quantity <= 0 || selectedIds.length >= quantity || selectableAssets.length < quantity) {
                return false;
            }

            rentalItems[index].asset_ids = selectableAssets.slice(0, quantity).map(function (asset) {
                return normalizeAssetId(asset.id);
            });

            return true;
        }
        function selectedPrimaryRentalAssetIdsForLines() {
            const ids = [];

            if (typeof primarySubmittedAssetIds === 'function') {
                normalizeIdArray(primarySubmittedAssetIds()).forEach(function (assetId) {
                    ids.push(assetId);
                });
            }

            normalizeIdArray(selectedAssetIds).forEach(function (assetId) {
                ids.push(assetId);
            });

            return Array.from(new Set(ids.filter(function (assetId) {
                return assetId > 0;
            })));
        }

        function selectedRentalAssetIdsOutsideLine(excludeIndex) {
            const selectedIds = [];

            rentalItems.forEach(function (item, index) {
                if (index === excludeIndex) {
                    return;
                }

                normalizeIdArray(item.asset_ids).forEach(function (assetId) {
                    selectedIds.push(assetId);
                });
            });

            selectedPrimaryRentalAssetIdsForLines().forEach(function (assetId) {
                selectedIds.push(assetId);
            });

            return Array.from(new Set(selectedIds.filter(function (assetId) {
                return assetId > 0;
            })));
        }

        function selectedAdditionalRentalAssetIds(excludeIndex) {
            return selectedRentalAssetIdsOutsideLine(excludeIndex);
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

            if (!rentalItemUsesPhAssets(item)) {
                return;
            }

            if (rentalItemNeedsWarehouseSelection(item)) {
                return;
            }

            const cacheKey = rentalItemAssetCacheKey(item.product_id);

            if (rentalItemAssetCache[cacheKey] || rentalItemAssetRequests[cacheKey]) {
                return;
            }

            const requestRentalItemAssets = function (withWarehouseFilter) {
                const params = new URLSearchParams({
                    product_id: String(item.product_id)
                });

                if (withWarehouseFilter && warehouseSelect.value) {
                    params.append('dispatch_warehouse_id', warehouseSelect.value);
                }

                if (currentRentalId) {
                    params.append('rental_id', String(currentRentalId));
                }

                return fetch('{{ route('rentals.available-assets') }}?' + params.toString(), {
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                }).then(function (response) {
                    if (!response.ok) {
                        throw new Error('Unable to load assets');
                    }

                    return response.json();
                });
            };

            rentalItemAssetRequests[cacheKey] = requestRentalItemAssets(true)
                .then(function (payload) {
                    const initialAssets = Array.isArray(payload.data) ? payload.data : [];

                    if (!initialAssets.length && warehouseSelect.value) {
                        return requestRentalItemAssets(false);
                    }

                    return payload;
                })
                .then(function (payload) {
                    rentalItemAssetCache[cacheKey] = Array.isArray(payload.data) ? payload.data : [];
                    const rentalItemIndex = rentalItems.findIndex(function (candidate) {
                        return candidate === item;
                    });

                    if (rentalItemIndex >= 0) {
                        autoSelectRentalItemAssets(rentalItemIndex, rentalItemAssetCache[cacheKey]);
                    }

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
            const usedElsewhere = selectedRentalAssetIdsOutsideLine(index);

            return cachedAssets.filter(function (asset) {
                const assetId = parseInt(asset?.id || '0', 10);
                return selectedIds.includes(assetId) || !usedElsewhere.includes(assetId);
            });
        }
        function renderRentalItems() {
            if (!rentalItemsList) {
                return;
            }

            rentalItemsList.innerHTML = '';

            if (!rentalItems.length) {
                rentalItemsList.appendChild(rentalItemEmptyState);
                rentalItemEmptyState.style.display = 'block';
                rentalItemsCount.textContent = '0';
                rentalItemsCountSummary.textContent = '0';
                rentalItemsGrandTotal.textContent = '0.00';
                rentalItemsGrandTotalSummary.textContent = '0.00';
                safeUpdateSummary();
                return;
            }

            rentalItemEmptyState.style.display = 'none';
            let total = 0;

            rentalItems.forEach(function (rawItem, index) {
                const item = syncRentalItem(index) || rawItem;
                const selectedIds = normalizeIdArray(item.asset_ids);
                const usesPhAssets = rentalItemUsesPhAssets(item);
                const needsWarehouseSelection = rentalItemNeedsWarehouseSelection(item);
                const cacheKey = rentalItemAssetCacheKey(item.product_id);
                const isLoadingAssets = Boolean(rentalItemAssetRequests[cacheKey]);
                const choices = rentalItemAssetChoices(item, index);
                const query = String(item.assetSearch || '').trim().toLowerCase();
                const filteredAssetChoices = choices.filter(function (asset) {
                    return !query || [asset.label, asset.serial_number, asset.barcode_value, asset.warehouse].join(' ').toLowerCase().includes(query);
                });
                const visibleAssets = filteredAssetChoices.slice(0, item.assetVisibleCount || inlineAssetVisibleStep);
                const visibleAssetIds = visibleAssets.map(function (asset) { return parseInt(asset.id || '0', 10); });
                const retainedSelectedIds = selectedIds.filter(function (assetId) { return !visibleAssetIds.includes(assetId); });
                const selectedAssetLabels = choices
                    .filter(function (asset) { return selectedIds.includes(parseInt(asset.id || '0', 10)); })
                    .map(function (asset) { return asset.label || asset.serial_number || ('Asset #' + asset.id); });
                const quantity = Math.max(parseInt(item.quantity || '1', 10), 1);
                const selectedCount = selectedIds.length;
                const toggleLabel = item.expanded ? 'Hide Details' : 'Details';
                const assetHelpText = usesPhAssets
                    ? `${selectedCount} of ${quantity} assigned`
                    : 'PH asset not required.';
                const lineTotal = rentalItemLineTotal(item);
                const entry = document.createElement('div');
                entry.className = 'sale-item-entry rental-product-line-card';
                entry.setAttribute('data-rental-line', String(index));
                entry.setAttribute('data-selected-assets', selectedIds.join(','));

                const heading = document.createElement('div');
                heading.className = 'rental-line-heading';
                heading.innerHTML = `<strong>Rental Product #${index + 2}</strong><span>${selectedCount} of ${quantity} assigned</span><button type="button" class="rental-line-remove-button" data-remove-rental-item="${index}">Remove</button>`;
                entry.appendChild(heading);

                const row = document.createElement('div');
                row.className = 'sale-item-row rental-line-row';
                row.innerHTML = `
                    <div class="sale-item-field-block">
                        <label>Product</label>
                        <select name="rental_items[${index}][product_id]" data-rental-product-index="${index}" data-searchable-select data-search-placeholder="Search rental product by name, brand, model, SKU, or code">
                            ${rentalProductOptionsHtml}
                        </select>
                        <div class="selected-product-preview" data-selected-product-preview data-rental-product-preview="${index}" hidden></div>
                    </div>
                    <div class="sale-item-field-block">
                        <label>Qty</label>
                        <input type="number" min="1" name="rental_items[${index}][quantity]" value="${item.quantity || 1}" data-rental-quantity-index="${index}">
                    </div>
                    <div class="sale-item-field-block">
                        <label>Unit Rental</label>
                        <input type="number" step="0.01" min="0" name="rental_items[${index}][unit_rental_amount]" value="${item.unit_rental_amount || ''}" data-rental-price-index="${index}">
                    </div>
                    <div class="sale-item-total" data-line-total="${lineTotal}"><span>Line Total</span><strong>${lineTotal}</strong></div>
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
                    <div class="sale-item-detail-grid rental-line-detail-grid">
                        <div class="sale-item-detail-field">
                            <label>Start Date</label>
                            <input type="date" name="rental_items[${index}][start_date]" value="${item.start_date || startDateInput?.value || ''}" data-rental-start-index="${index}">
                        </div>
                        <div class="sale-item-detail-field">
                            <label>End Date</label>
                            <input type="date" name="rental_items[${index}][end_date]" value="${item.end_date || endDateInput?.value || ''}" data-rental-end-index="${index}">
                        </div>
                        <div class="sale-item-detail-field">
                            <label>Duration</label>
                            <input type="number" min="1" name="rental_items[${index}][duration_days]" value="${item.duration_days || activeRentalDays() || ''}" data-rental-duration-index="${index}">
                        </div>
                        <div class="sale-item-detail-field rental-line-asset-field">
                            <label>Asset Allocation</label>
                            <div class="rental-line-asset-summary"><strong>Required ${quantity}</strong><span>Selected ${selectedCount}</span></div>
                            ${usesPhAssets ? `<div class="line-asset-load-more" style="justify-content:flex-start; margin-bottom:8px; gap:8px;"><button type="button" class="ops-button-secondary" data-rental-asset-autoselect="${index}">Auto Select</button><button type="button" class="ops-button-secondary" data-rental-asset-clear="${index}">Clear</button></div>` : ''}
                            ${usesPhAssets ? `<input type="text" class="line-asset-search" placeholder="Search serial, barcode, or asset" value="${escapeHtml(item.assetSearch || '')}" data-rental-asset-search="${index}">` : ''}
                            <div class="rental-line-asset-picker" data-rental-asset-picker="${index}">
                                ${!usesPhAssets
                                    ? `<div class="rental-line-asset-empty">PH asset selection is not required for vendor supplied lines.</div>`
                                    : (needsWarehouseSelection
                                        ? `<div class="rental-line-asset-empty">Select warehouse to load assets.</div>`
                                        : (visibleAssets.length
                                            ? visibleAssets.map(function (asset) {
                                                const assetId = parseInt(asset.id || '0', 10);
                                                const serial = asset.serial_number || '-';
                                                const warehouse = asset.warehouse || '-';
                                                const checked = selectedIds.includes(assetId);
                                                return `<label class="rental-line-asset-option${checked ? ' is-selected' : ''}"><input type="checkbox" name="rental_items[${index}][asset_ids][]" value="${assetId}" data-rental-asset-input="${index}" ${checked ? 'checked' : ''}><span class="rental-line-asset-option-text"><span class="rental-line-asset-option-title">${escapeHtml(asset.label || ('Asset #' + assetId))}</span><span class="rental-line-asset-option-meta">Serial: ${escapeHtml(serial)} | Warehouse: ${escapeHtml(warehouse)}</span></span></label>`;
                                            }).join('')
                                            : `<div class="rental-line-asset-empty">${item.product_id ? (isLoadingAssets ? 'Loading assets...' : 'No assets available') : 'Select product first'}</div>`))}
                                ${retainedSelectedIds.map(function (assetId) {
                                    return `<input type="hidden" name="rental_items[${index}][asset_ids][]" value="${assetId}">`;
                                }).join('')}
                            </div>
                            ${selectedAssetLabels.length ? `<div class="sale-item-subnote">Assigned: ${escapeHtml(selectedAssetLabels.join(', '))}</div>` : `<div class="sale-item-subnote">${assetHelpText}</div>`}
                            ${usesPhAssets && filteredAssetChoices.length > visibleAssets.length
                                ? `<div class="line-asset-load-more"><button type="button" class="ops-button-secondary" data-rental-asset-load-more="${index}">Load More</button></div>`
                                : ''}
                        </div>
                        <details class="gst-details-collapse rental-line-gst-collapse">
                            <summary>GST Details <span class="hint">Auto from Product Master</span></summary>
                            <div class="gst-details-grid">
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
                            </div>
                        </details>
                        <div class="sale-item-detail-field">
                            <label>Notes</label>
                            <textarea name="rental_items[${index}][notes]" placeholder="Optional note">${escapeHtml(item.notes || '')}</textarea>
                        </div>
                    </div>
                `;
                entry.appendChild(detail);
                rentalItemsList.appendChild(entry);

                const productSelectEl = row.querySelector(`[data-rental-product-index="${index}"]`);
                const quantityInputEl = row.querySelector(`[data-rental-quantity-index="${index}"]`);
                const priceInputEl = row.querySelector(`[data-rental-price-index="${index}"]`);
                const startInputEl = detail.querySelector(`[data-rental-start-index="${index}"]`);
                const endInputEl = detail.querySelector(`[data-rental-end-index="${index}"]`);
                const durationInputEl = detail.querySelector(`[data-rental-duration-index="${index}"]`);
                const assetSearchEl = detail.querySelector(`[data-rental-asset-search="${index}"]`);
                const assetCheckboxes = detail.querySelectorAll(`[data-rental-asset-input="${index}"]`);
                const assetLoadMoreButton = detail.querySelector(`[data-rental-asset-load-more="${index}"]`);
                const assetAutoSelectButton = detail.querySelector(`[data-rental-asset-autoselect="${index}"]`);
                const assetClearButton = detail.querySelector(`[data-rental-asset-clear="${index}"]`);
                const gstRateInputEl = detail.querySelector(`[data-rental-gst-rate="${index}"]`);
                const gstModeSelectEl = detail.querySelector(`[data-rental-gst-mode="${index}"]`);
                const taxTypeSelectEl = detail.querySelector(`[data-rental-tax-type="${index}"]`);
                const notesInputEl = detail.querySelector(`textarea[name="rental_items[${index}][notes]"]`);
                const toggleButton = row.querySelector(`[data-toggle-rental-item="${index}"]`);
                const removeButton = row.querySelector(`[data-remove-rental-item="${index}"]`);
                const headerRemoveButton = heading.querySelector(`[data-remove-rental-item="${index}"]`);

                productSelectEl.value = item.product_id || '';
                enhanceSearchableSelect(productSelectEl);

                const handleRentalItemProductChange = function () {
                    const previousProductId = parseInt(rentalItems[index].product_id || '0', 10) || null;
                    const nextProductId = this.value ? parseInt(this.value, 10) : null;

                    if (previousProductId === nextProductId) {
                        return;
                    }

                    rentalItems[index].product_id = nextProductId;
                    rentalItems[index].asset_ids = [];
                    rentalItems[index].assetSearch = '';
                    rentalItems[index].assetVisibleCount = inlineAssetVisibleStep;
                    rentalItems[index].expanded = Boolean(rentalItems[index].product_id);
                    const defaults = selectedProductTaxDefaults(this);
                    rentalItems[index].gst_rate = defaults.gstRate;
                    rentalItems[index].gst_mode = defaults.gstMode;
                    rentalItems[index].tax_type = defaults.taxType;

                    const selected = this.options[this.selectedIndex];
                    const defaultPrice = selected ? parseFloat(selected.getAttribute('data-default-price') || '0') : 0;
                    rentalItems[index].unit_rental_amount = defaultPrice ? defaultPrice.toFixed(2) : '';

                    renderRentalItems();
                };

                productSelectEl.addEventListener('change', handleRentalItemProductChange);
                productSelectEl.addEventListener('searchable-select:changed', handleRentalItemProductChange);

                quantityInputEl.addEventListener('input', function () {
                    rentalItems[index].quantity = Math.max(parseInt(this.value || '0', 10), 1);
                    renderRentalItems();
                });

                priceInputEl.addEventListener('input', function () {
                    rentalItems[index].unit_rental_amount = this.value || '';
                    const totalNode = row.querySelector('.sale-item-total');
                    if (totalNode) {
                        totalNode.setAttribute('data-line-total', rentalItemLineTotal(rentalItems[index]));
                        totalNode.innerHTML = `<span>Line Total</span><strong>${rentalItemLineTotal(rentalItems[index])}</strong>`;
                    }
                    safeUpdateSummary();
                });

                priceInputEl.addEventListener('change', function () {
                    rentalItems[index].unit_rental_amount = this.value || '';
                    renderRentalItems();
                });

                const syncLineDurationFromDates = function () {
                    rentalItems[index].start_date = startInputEl?.value || '';
                    rentalItems[index].end_date = endInputEl?.value || '';
                    if (rentalItems[index].start_date && rentalItems[index].end_date) {
                        const start = new Date(rentalItems[index].start_date + 'T00:00:00');
                        const end = new Date(rentalItems[index].end_date + 'T00:00:00');
                        if (!Number.isNaN(start.getTime()) && !Number.isNaN(end.getTime()) && end >= start) {
                            rentalItems[index].duration_days = Math.floor((end.getTime() - start.getTime()) / (24 * 60 * 60 * 1000)) + 1;
                            if (durationInputEl) durationInputEl.value = rentalItems[index].duration_days;
                        }
                    }
                    safeUpdateSummary();
                };

                startInputEl?.addEventListener('change', syncLineDurationFromDates);
                endInputEl?.addEventListener('change', syncLineDurationFromDates);
                durationInputEl?.addEventListener('input', function () {
                    rentalItems[index].duration_days = Math.max(parseInt(this.value || '0', 10), 0) || '';
                    safeUpdateSummary();
                });

                gstRateInputEl?.addEventListener('change', function () {
                    rentalItems[index].gst_rate = normalizeMoney(this.value);
                    renderRentalItems();
                });

                gstModeSelectEl?.addEventListener('change', function () {
                    rentalItems[index].gst_mode = this.value === 'inclusive' ? 'inclusive' : 'exclusive';
                    renderRentalItems();
                });

                taxTypeSelectEl?.addEventListener('change', function () {
                    rentalItems[index].tax_type = this.value === 'igst' ? 'igst' : 'cgst_sgst';
                    renderRentalItems();
                });

                assetSearchEl?.addEventListener('input', function () {
                    rentalItems[index].assetSearch = this.value || '';
                    rentalItems[index].assetVisibleCount = inlineAssetVisibleStep;
                    renderRentalItems();
                });

                assetCheckboxes.forEach(function (checkbox) {
                    checkbox.addEventListener('change', function () {
                        const usedElsewhere = selectedRentalAssetIdsOutsideLine(index);
                        rentalItems[index].asset_ids = Array.from(detail.querySelectorAll(`[data-rental-asset-input="${index}"]:checked`))
                            .map(function (option) { return parseInt(option.value || '0', 10); })
                            .filter(function (value) { return value > 0 && !usedElsewhere.includes(value); })
                            .slice(0, Math.max(parseInt(rentalItems[index].quantity || '1', 10), 1));
                        renderRentalItems();
                    });
                });

                assetLoadMoreButton?.addEventListener('click', function () {
                    rentalItems[index].assetVisibleCount += inlineAssetVisibleStep;
                    renderRentalItems();
                });

                assetAutoSelectButton?.addEventListener('click', function () {
                    const quantity = Math.max(parseInt(rentalItems[index].quantity || '0', 10), 0);
                    const choices = rentalItemAssetChoices(rentalItems[index], index);
                    rentalItems[index].asset_ids = choices.slice(0, quantity).map(function (asset) { return asset.id; });
                    renderRentalItems();
                });

                assetClearButton?.addEventListener('click', function () {
                    rentalItems[index].asset_ids = [];
                    renderRentalItems();
                });

                notesInputEl?.addEventListener('input', function () {
                    rentalItems[index].notes = this.value || '';
                });

                toggleButton?.addEventListener('click', function () {
                    rentalItems[index].expanded = !rentalItems[index].expanded;
                    renderRentalItems();
                });

                const removeRentalItem = function () {
                    rentalItems.splice(index, 1);
                    renderRentalItems();
                };

                removeButton?.addEventListener('click', removeRentalItem);
                headerRemoveButton?.addEventListener('click', removeRentalItem);

                total += parseFloat(lineTotal || '0');
            });

            rentalItemsCount.textContent = String(rentalItems.length);
            rentalItemsCountSummary.textContent = String(rentalItems.length);
            rentalItemsGrandTotal.textContent = total.toFixed(2);
            rentalItemsGrandTotalSummary.textContent = total.toFixed(2);
            safeUpdateSummary();
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
                safeUpdateSummary();
                return;
            }

            saleItemEmptyState.style.display = 'none';

            let total = 0;

            saleItems.forEach(function (item, index) {
                if (typeof item.expanded !== 'boolean') {
                    item.expanded = Boolean(item.product_id || item.asset_id || item.notes);
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
                        ].join(' - ').toLowerCase().includes(term);
                    })
                    .sort(function (left, right) {
                        return Number(parseInt(item.asset_id || '0', 10) === right.id) - Number(parseInt(item.asset_id || '0', 10) === left.id);
                    });
                const visibleAssets = filteredAssetChoices.slice(0, Math.max(item.assetVisibleCount, item.asset_id ? 1 : 0));
                const selectedSaleAssetId = parseInt(item.asset_id || '0', 10);
                const visibleSaleAssetIds = visibleAssets.map(function (asset) {
                    return asset.id;
                });
                const shouldRetainHiddenSaleAsset = selectedSaleAssetId > 0 && !visibleSaleAssetIds.includes(selectedSaleAssetId);
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
                entry.setAttribute('data-sale-line', String(index));
                entry.setAttribute('data-selected-assets', item.asset_id ? String(item.asset_id) : '');
                const heading = document.createElement('div');
                heading.className = 'rental-line-heading';
                heading.innerHTML = `<strong>Sale Item #${index + 1}</strong><span>Sales add-on</span>`;
                entry.appendChild(heading);
                const row = document.createElement('div');
                row.className = 'sale-item-row';
                row.innerHTML = `
                    <div>
                        <select name="sale_items[${index}][product_id]" data-sale-product-index="${index}" data-searchable-select data-search-placeholder="Search new product by name, brand, model, SKU, or code">
                            ${saleProductOptionsHtml}
                        </select>
                        <div class="selected-product-preview" data-selected-product-preview data-sale-product-preview="${index}" hidden></div>
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
                        <div class="sale-item-total" data-line-total="${saleItemLineTotal(item)}">Total ${saleItemLineTotal(item)}</div>
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
                                }).join(' - ')}
                                ${shouldRetainHiddenSaleAsset ? `<input type="hidden" name="sale_items[${index}][asset_id]" value="${selectedSaleAssetId}">` : ''}
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
                    const previousProductId = parseInt(saleItems[index].product_id || '0', 10) || null;
                    const nextProductId = this.value ? parseInt(this.value, 10) : null;

                    if (previousProductId === nextProductId) {
                        return;
                    }

                    saleItems[index].product_id = nextProductId;
                    saleItems[index].assetSearch = '';
                    saleItems[index].assetVisibleCount = inlineAssetVisibleStep;
                    saleItems[index].expanded = Boolean(saleItems[index].product_id);

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
                    const totalNode = row.querySelector('.sale-item-total');
                    if (totalNode) {
                        totalNode.textContent = 'Total ' + saleItemLineTotal(saleItems[index]);
                    }
                    saleItemsGrandTotal.textContent = saleItems
                        .reduce(function (sum, saleItem) {
                            return sum + parseFloat(saleItemLineTotal(saleItem));
                        }, 0)
                        .toFixed(2);
                    saleItemsGrandTotalSummary.textContent = saleItemsGrandTotal.textContent;
                    safeUpdateSummary();
                });

                priceInputEl.addEventListener('change', function () {
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
            safeUpdateSummary();
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
            option.setAttribute('data-search', [partner.name, partner.contact_person, partner.phone, partner.email, partner.city, partner.state].filter(Boolean).join(' - '));
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
            option.setAttribute('data-phone-country', client.phone_country || '');
            option.setAttribute('data-state', client.state || '');
            option.setAttribute('data-address', client.address || '');
            option.setAttribute('data-city', client.city || '');
            option.setAttribute('data-location', client.location || '');
            option.setAttribute('data-notes', client.delivery_notes || '');
            option.setAttribute('data-search', [client.name, client.phone, client.address, client.city, client.state].filter(Boolean).join(' - '));

            if (String(businessPartnerSelect?.value || '') === String(partnerId)) {
                partnerClientSelect.appendChild(option);
                partnerClientSelect.value = String(client.id);
                partnerClientSelect.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
        productSelect.addEventListener('change', handlePrimaryProductChange);
        productSelect.addEventListener('input', handlePrimaryProductChange);
        productSelect.addEventListener('searchable-select:changed', handlePrimaryProductChange);
        productSelect.addEventListener('change', syncRentalStockModalState);
        productSelect.addEventListener('searchable-select:changed', syncRentalStockModalState);
        const productTriggerLabel = productSelect?._searchableSelect?.triggerLabel;
        if (productTriggerLabel && typeof MutationObserver !== 'undefined') {
            let lastObservedProductLabel = (productTriggerLabel.textContent || '').trim();
            new MutationObserver(function () {
                const nextLabel = (productTriggerLabel.textContent || '').trim();
                if (!nextLabel || nextLabel === lastObservedProductLabel) {
                    return;
                }

                lastObservedProductLabel = nextLabel;

                if (!/^select product$/i.test(nextLabel)) {
                    window.requestAnimationFrame(function () {
                        handlePrimaryProductChange();
                    });
                }
            }).observe(productTriggerLabel, { childList: true, characterData: true, subtree: true });
        }
        warehouseSelect.addEventListener('change', function () {
            clearPrimaryAssetSelection();
            updateWarehouseMetric();
            primaryAvailabilityLoadedFor = null;
            lastPrimaryAssetSignature = null;
            updateAvailabilityHint();
            updatePrimaryRentalFeedback();
            fetchAssets();
            syncRentalStockModalState();
            Object.keys(rentalItemAssetCache).forEach(function (key) {
                delete rentalItemAssetCache[key];
            });
            renderRentalItems();
        });
        quantityInput.addEventListener('input', function () {
            updateSelectionMetrics();
            updatePrimaryRentalFeedback();
            syncPrimaryRentalAmount(true);
        });
        startDateInput.addEventListener('change', function () {
            syncDurationPreset();
            syncPrimaryRentalAmount(true);
        });
        endDateInput.addEventListener('change', function () {
            syncPrimaryRentalAmount(true);
        });
        durationPresetSelect.addEventListener('change', function () {
            syncDurationPreset();
            syncPrimaryRentalAmount(true);
        });
        deliveryAssignmentSelect?.addEventListener('change', syncThirdPartyDeliveryFields);
        rentalAmountInput.addEventListener('input', function () {
            if (!syncingRentalAmount) {
                rentalAmountTouched = true;
            }
            safeUpdateSummary();
            if (typeof updateWizardState === 'function') {
                updateWizardState();
            }
        });
        assetSearch.addEventListener('input', function () {
            visibleAssetCount = assetVisibleStep;
            renderAssets();
        });
        document.addEventListener('click', function (event) {
            const assignAssetButton = event.target.closest('[data-open-rental-asset-modal]');
            if (!assignAssetButton) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
            openAssetAssignmentDrawer();
        });
        document.addEventListener('click', function (event) {
            const closeAssetModalButton = event.target.closest('[data-close-rental-asset-modal]');
            if (!closeAssetModalButton) {
                return;
            }

            event.preventDefault();
            closeAssetAssignmentDrawer();
        });
        closeAssetAssignmentDrawerButton?.addEventListener('click', function () {
            closeAssetAssignmentDrawer();
        });
        cancelAssetAssignmentDrawerButton?.addEventListener('click', function () {
            closeAssetAssignmentDrawer();
        });
        applyAssetAssignmentDrawerButton?.addEventListener('click', function () {
            updateAssignmentDrawerSummary();
            closeAssetAssignmentDrawer(selectedAssetIds.length > 0);
            safeUpdateSummary();
            document.dispatchEvent(new CustomEvent('phos:rental-assets-applied', { detail: selectedPrimaryAssetSummary() }));
        });
        assetAssignmentDrawer?.addEventListener('click', function (event) {
            if (event.target === assetAssignmentDrawer) {
                closeAssetAssignmentDrawer();
            }
        });
        openRentalStockModalButton?.addEventListener('click', function (event) {
            event.preventDefault();
            openRentalStockModal();
        });
        document.addEventListener('click', function (event) {
            const trigger = event.target.closest('[data-add-rental-stock-trigger]');

            if (!trigger || trigger === openRentalStockModalButton) {
                return;
            }

            event.preventDefault();
            openRentalStockModal();
        });
        closeRentalStockModalButton?.addEventListener('click', function () {
            closeRentalStockModal();
        });
        cancelRentalStockModalButton?.addEventListener('click', function () {
            closeRentalStockModal();
        });
        rentalStockModal?.addEventListener('click', function (event) {
            if (event.target === rentalStockModal) {
                closeRentalStockModal();
            }
        });
        rentalStockProductSelect?.addEventListener('change', syncRentalStockModalState);
        rentalStockWarehouseSelect?.addEventListener('change', syncRentalStockModalState);
        saveRentalStockButton?.addEventListener('click', function (event) {
            event.preventDefault();

            if (!syncRentalStockModalState() || !rentalStockForm) {
                return;
            }

            const formData = new FormData();
            formData.set('_token', rentalStockForm.dataset.token || '');
            formData.set('product_id', rentalStockProductSelect?.value || '');
            formData.set('warehouse_id', rentalStockWarehouseSelect?.value || '');
            formData.set('serial_number', document.getElementById('rental_stock_serial_number')?.value || '');
            formData.set('barcode_value', document.getElementById('rental_stock_barcode_value')?.value || '');
            formData.set('condition_status', document.getElementById('rental_stock_condition_status')?.value || '');
            formData.set('notes', document.getElementById('rental_stock_notes')?.value || '');
            formData.set('asset_status', document.getElementById('rentalStockAssetStatus')?.value || 'available');
            formData.set('fulfilment_source', currentFulfilmentSourceValue() || 'in_house');
            formData.set('city_id', currentSelectedCityId() || '');
            formData.set('quantity', '1');

            if (saveRentalStockButton) {
                saveRentalStockButton.disabled = true;
            }

            fetch(rentalStockForm.dataset.action, {
                method: 'POST',
                body: formData,
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': rentalStockForm.dataset.token || ''
                }
            })
                .then(function (response) {
                    return response.json().catch(function () {
                        return {};
                    }).then(function (payload) {
                        if (!response.ok) {
                            throw payload;
                        }

                        return payload;
                    });
                })
                .then(function (payload) {
                    if (payload.asset) {
                        injectRentalStockAsset(payload.asset);
                    }
                    if (document.getElementById('rental_stock_serial_number')) {
                        document.getElementById('rental_stock_serial_number').value = '';
                    }
                    if (document.getElementById('rental_stock_barcode_value')) {
                        document.getElementById('rental_stock_barcode_value').value = '';
                    }
                    if (document.getElementById('rental_stock_notes')) {
                        document.getElementById('rental_stock_notes').value = '';
                    }
                    if (rentalStockProductSelect && selectedPrimaryProductValue()) {
                        rentalStockProductSelect.value = selectedPrimaryProductValue();
                    }
                    closeRentalStockModal(true);
                })
                .catch(function (payload) {
                    const errors = payload?.errors || {};
                    const firstError = Object.values(errors).flat()[0] || payload?.message || 'Unable to add rental stock.';
                    showRentalStockMessage(firstError, 'error');
                })
                .finally(function () {
                    if (saveRentalStockButton) {
                        saveRentalStockButton.disabled = false;
                    }
                    syncRentalStockModalState();
                });
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && assetAssignmentDrawer && !assetAssignmentDrawer.hidden) {
                closeAssetAssignmentDrawer();
            }
            if (event.key === 'Escape' && rentalStockModal && (rentalStockModal.classList.contains('is-open') || window.location.hash === '#rentalStockModal')) {
                closeRentalStockModal();
            }
        });
        selectSuggestedAssetsButton?.addEventListener('click', function () {
            const quantity = Math.max(parseInt(quantityInput.value || '0', 10), 0);
            const filtered = getFilteredAssets();
            const maxSelectable = quantity > 0 ? quantity : filtered.length;

            selectedAssetIds = normalizeAssetIds(filtered.slice(0, maxSelectable).map(function (asset) {
                return asset.id;
            }));
            syncPrimaryAssetHiddenInputs();
            notifyPrimaryAssetUserChanged();
            renderAssets();
            updateSelectionMetrics();
            renderRentalItems();
        });
        clearSelectedAssetsButton?.addEventListener('click', function () {
            clearPrimaryAssetSelection();
            renderAssets();
            updateSelectionMetrics();
            renderRentalItems();
        });
        assetLoadMoreButton?.addEventListener('click', function () {
            visibleAssetCount += assetVisibleStep;
            renderAssets();
        });
        const addRentalItem = function () {
            additionalRentalDisclosure.open = true;
            rentalItems.push({
                product_id: null,
                asset_ids: [],
                quantity: 1,
                start_date: startDateInput?.value || '',
                end_date: endDateInput?.value || '',
                duration_days: activeRentalDays() || '',
                unit_rental_amount: '',
                gst_rate: '0.00',
                gst_mode: 'exclusive',
                tax_type: recommendedTaxType(),
                notes: '',
                expanded: true
            });

            renderRentalItems();
        };
        const addSaleItem = function () {
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
        };
        addRentalItemButton.addEventListener('click', addRentalItem);
        addSaleItemButton.addEventListener('click', addSaleItem);
        document.querySelectorAll('[data-add-rental-item]').forEach(function (button) {
            button.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                addRentalItem();
            });
        });
        document.querySelectorAll('[data-add-sale-item]').forEach(function (button) {
            button.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                addSaleItem();
            });
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
        syncPrimaryRentalAmount(true);
        syncThirdPartyDeliveryFields();
        window.setTimeout(function () {
            syncPrimaryRentalAmount(true);
        }, 0);
        renderRentalItems();
        renderSaleItems();
        window.setInterval(syncPrimaryAssetStateIfNeeded, 250);
    })();
</script>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const referralTypeSelect = document.getElementById('referral_source_type');
        const referredBySelect = document.getElementById('referred_by');
        const referredByNameInput = document.getElementById('referred_by_name');
        const referralContactInput = document.getElementById('referral_contact');
        const referralCityInput = document.getElementById('referral_city');
        referredBySelect?.addEventListener('change', function () {
            const selected = referredBySelect.options[referredBySelect.selectedIndex];
            if (!selected) {
                return;
            }

            if (referralTypeSelect && selected.getAttribute('data-referral-type')) {
                referralTypeSelect.value = selected.getAttribute('data-referral-type');
            }
            if (referredByNameInput) {
                referredByNameInput.value = selected.getAttribute('data-referral-name') || '';
            }
            if (referralContactInput && selected.getAttribute('data-referral-contact')) {
                referralContactInput.value = selected.getAttribute('data-referral-contact');
            }
            if (referralCityInput && selected.getAttribute('data-referral-city')) {
                referralCityInput.value = selected.getAttribute('data-referral-city');
            }
        });

        const fulfilmentSourceSelect = document.getElementById('fulfilment_source');
        const citySelect = document.getElementById('city_id');
        const productSelect = document.getElementById('product_id');
        const warehouseField = document.getElementById('dispatchWarehouseField');
        const warehouseSelect = document.getElementById('dispatch_warehouse_id');
        const vendorField = document.getElementById('vendorField');
        const vendorSelect = document.getElementById('vendor_id');
        const warehouseCityHint = document.getElementById('warehouseCityHint');
        const vendorCityHint = document.getElementById('vendorCityHint');
        const deliveryAssignmentTypeSelect = document.getElementById('delivery_assignment_type');
        const deliveryPartnerField = document.getElementById('deliveryPartnerField');
        const deliveryAssignmentSelect = document.getElementById('delivery_staff_id');
        const deliveryPartnerLabel = document.getElementById('deliveryPartnerLabel');
        const deliveryPartnerHint = document.getElementById('deliveryPartnerHint');
        const deliveryResponsibilityInput = document.getElementById('delivery_responsibility');
        const pickupResponsibilityInput = document.getElementById('pickup_responsibility');
        const thirdPartyDeliveryFields = document.getElementById('thirdPartyDeliveryFields');
        const assetSection = document.getElementById('rental-assets-section');
        const productWarning = document.getElementById('productRentalWarning');
        const availabilityHint = document.getElementById('productAvailabilityHint');

        if (!fulfilmentSourceSelect || !citySelect || !deliveryAssignmentTypeSelect) {
            return;
        }

        const originalWarehouseNodes = warehouseSelect ? Array.from(warehouseSelect.children).map((node) => node.cloneNode(true)) : [];
        const originalVendorNodes = vendorSelect ? Array.from(vendorSelect.children).map((node) => node.cloneNode(true)) : [];
        const originalAssignmentTypeNodes = Array.from(deliveryAssignmentTypeSelect.children).map((node) => node.cloneNode(true));
        const originalDeliveryPartnerNodes = deliveryAssignmentSelect ? Array.from(deliveryAssignmentSelect.children).map((node) => node.cloneNode(true)) : [];

        function syncProductOptionLabels() {
            const isVendorSupplied = fulfilmentSourceSelect.value === 'vendor_supplied';

            Array.from(productSelect?.options || []).forEach((option) => {
                if (!option.value) {
                    return;
                }

                const displayLabel = isVendorSupplied
                    ? (option.getAttribute('data-vendor-option-label') || option.getAttribute('data-product-name') || option.textContent)
                    : (option.getAttribute('data-in-house-option-label') || option.textContent);

                option.setAttribute('data-display-label', displayLabel);
                option.textContent = displayLabel;
            });

            productSelect?._searchableSelect?.refresh?.();
        }

        function selectedCityName() {
            const selected = citySelect.options[citySelect.selectedIndex];
            return (selected?.textContent || '').split('-')[0].trim().toLowerCase();
        }

        function selectedCityId() {
            return parseInt(citySelect.value || '0', 10) || 0;
        }

        function matchesCity(option) {
            const cityId = selectedCityId();
            if (!cityId) {
                return false;
            }

            const optionCityId = parseInt(option.getAttribute('data-city-id') || '0', 10) || 0;
            if (optionCityId > 0) {
                return optionCityId === cityId;
            }

            const optionCityName = (option.getAttribute('data-city-name') || '').trim().toLowerCase();
            return optionCityName !== '' && optionCityName === selectedCityName();
        }

        function hasCityMetadata(option) {
            if (!option) {
                return false;
            }

            const optionCityId = parseInt(option.getAttribute('data-city-id') || '0', 10) || 0;
            const optionCityName = (option.getAttribute('data-city-name') || '').trim();

            return optionCityId > 0 || optionCityName !== '';
        }

        function rebuildSelect(select, originalNodes, optionFilter, emptyText) {
            if (!select) {
                return 0;
            }

            const previousValue = select.value;
            select.innerHTML = '';
            let visibleCount = 0;

            originalNodes.forEach((node) => {
                const clone = node.cloneNode(true);
                if (clone.tagName === 'OPTION') {
                    if (!clone.value || optionFilter(clone)) {
                        if (!clone.value && emptyText) {
                            clone.textContent = emptyText;
                        }
                        select.appendChild(clone);
                        if (clone.value) {
                            visibleCount += 1;
                        }
                    }
                    return;
                }

                if (clone.tagName === 'OPTGROUP') {
                    const allowedOptions = Array.from(clone.querySelectorAll('option')).filter((option) => !option.value || optionFilter(option));
                    if (!allowedOptions.length) {
                        return;
                    }

                    clone.innerHTML = '';
                    allowedOptions.forEach((option) => {
                        if (!option.value && emptyText) {
                            option.textContent = emptyText;
                        }
                        clone.appendChild(option);
                        if (option.value) {
                            visibleCount += 1;
                        }
                    });

                    select.appendChild(clone);
                }
            });

            if (previousValue && select.querySelector(`option[value="${previousValue}"]`)) {
                select.value = previousValue;
            } else {
                select.value = '';
            }

            return visibleCount;
        }

        function updateAssignmentTypeOptions() {
            const isVendorSupplied = fulfilmentSourceSelect.value === 'vendor_supplied';
            const previousValue = deliveryAssignmentTypeSelect.value;
            deliveryAssignmentTypeSelect.innerHTML = '';

            originalAssignmentTypeNodes.forEach((node) => {
                const clone = node.cloneNode(true);
                if (clone.tagName !== 'OPTION') {
                    return;
                }

                if (!isVendorSupplied && clone.value === 'vendor') {
                    return;
                }

                deliveryAssignmentTypeSelect.appendChild(clone);
            });

            if (deliveryAssignmentTypeSelect.querySelector(`option[value="${previousValue}"]`)) {
                deliveryAssignmentTypeSelect.value = previousValue;
            } else if (!isVendorSupplied && previousValue === 'vendor') {
                deliveryAssignmentTypeSelect.value = 'ph_internal';
            } else if (isVendorSupplied && !deliveryAssignmentTypeSelect.value) {
                deliveryAssignmentTypeSelect.value = 'vendor';
            }
        }

        function updatePartnerField() {
            const isVendorSupplied = fulfilmentSourceSelect.value === 'vendor_supplied';
            const assignmentType = deliveryAssignmentTypeSelect.value;
            const cityChosen = selectedCityId() > 0;

            if (deliveryResponsibilityInput) {
                deliveryResponsibilityInput.value = assignmentType === 'vendor'
                    ? 'vendor_delivery'
                    : (assignmentType === 'customer_pickup' ? 'customer_pickup' : 'ph_internal_delivery');
            }

            if (pickupResponsibilityInput) {
                pickupResponsibilityInput.value = isVendorSupplied ? 'vendor_pickup' : 'ph_internal_pickup';
            }

            const showPartnerField = assignmentType === 'ph_internal' || assignmentType === 'third_party';
            if (deliveryPartnerField) {
                deliveryPartnerField.style.display = showPartnerField ? '' : 'none';
            }

            if (!deliveryAssignmentSelect) {
                return;
            }

            if (!showPartnerField) {
                deliveryAssignmentSelect.value = '';
                deliveryAssignmentSelect.disabled = true;
                if (thirdPartyDeliveryFields) {
                    thirdPartyDeliveryFields.style.display = 'none';
                }
                return;
            }

            deliveryAssignmentSelect.disabled = !cityChosen;

            const visiblePartners = rebuildSelect(
                deliveryAssignmentSelect,
                originalDeliveryPartnerNodes,
                function (option) {
                    const kind = option.getAttribute('data-assignment-kind') || '';
                    const optionCityId = parseInt(option.getAttribute('data-city-id') || '0', 10) || 0;
                    const optionCityName = (option.getAttribute('data-city-name') || '').trim();
                    const hasCityMeta = optionCityId > 0 || optionCityName !== '';
                    if (assignmentType === 'third_party') {
                        return option.getAttribute('data-always-visible') === '1' || (kind === 'third_party' && matchesCity(option));
                    }

                    if (kind === 'third_party' || option.value === 'third_party') {
                        return false;
                    }

                    return option.getAttribute('data-always-visible') === '1'
                        || (kind === 'ph_internal' && (matchesCity(option) || !hasCityMeta));
                },
                assignmentType === 'third_party' ? 'Select logistics partner' : 'Select PH delivery staff'
            );

            if (assignmentType === 'third_party' && deliveryAssignmentSelect.querySelector('option[value="third_party"]') && !deliveryAssignmentSelect.value) {
                deliveryAssignmentSelect.value = 'third_party';
            }

            if (deliveryPartnerLabel) {
                deliveryPartnerLabel.textContent = assignmentType === 'third_party'
                    ? 'Third-Party Logistics'
                    : 'PH Internal Delivery Staff';
            }

            if (deliveryPartnerHint) {
                deliveryPartnerHint.textContent = !cityChosen
                    ? 'Select city first to load matching delivery partners.'
                    : (visiblePartners > 0
                        ? (assignmentType === 'third_party'
                            ? 'Choose a saved logistics partner for this city or use the one-time partner option.'
                            : 'Only delivery staff assigned to this city, plus any unassigned fallback staff, are shown.')
                        : (assignmentType === 'third_party'
                            ? 'No third-party logistics partners assigned for this city.'
                            : 'No delivery staff assigned for this city.'));
            }

            if (thirdPartyDeliveryFields) {
                thirdPartyDeliveryFields.style.display = assignmentType === 'third_party' && deliveryAssignmentSelect.value === 'third_party' ? '' : 'none';
            }
        }

        function updateFulfilmentFields() {
            const isVendorSupplied = fulfilmentSourceSelect.value === 'vendor_supplied';
            const cityChosen = selectedCityId() > 0;

            if (vendorField) {
                vendorField.style.display = isVendorSupplied ? '' : 'none';
            }
            if (warehouseField) {
                warehouseField.style.display = isVendorSupplied ? 'none' : '';
            }
            if (assetSection) {
                const productChosen = !!(productSelect && productSelect.value);
                assetSection.style.display = (!isVendorSupplied && productChosen) ? '' : 'none';
            }

            if (vendorSelect) {
                vendorSelect.disabled = !isVendorSupplied || !cityChosen;
                const visibleVendors = rebuildSelect(
                    vendorSelect,
                    originalVendorNodes,
                    function (option) {
                        return true;
                    },
                    'Select vendor'
                );
                if (vendorCityHint) {
                    vendorCityHint.textContent = !cityChosen
                        ? 'Select city first to load active vendors.'
                        : (visibleVendors > 0 ? 'Active vendors are shown. Choose the city-matched vendor where available.' : 'No active vendors available.');
                }
            }

            if (warehouseSelect) {
                warehouseSelect.disabled = isVendorSupplied || (!cityChosen && !Boolean(productSelect?.value));
                const visibleWarehouses = rebuildSelect(
                    warehouseSelect,
                    originalWarehouseNodes,
                    function (option) {
                        return cityChosen ? (matchesCity(option) || !hasCityMetadata(option)) : true;
                    },
                    'Select warehouse for this city'
                );
                if (warehouseCityHint) {
                    warehouseCityHint.textContent = !cityChosen
                        ? (productSelect?.value ? 'Choose warehouse.' : 'Select city or product first.')
                        : (visibleWarehouses > 0 ? 'City warehouse.' : 'No city warehouse.');
                }
            }

            if (availabilityHint && isVendorSupplied) {
                availabilityHint.textContent = 'Vendor supplied.';
            }

            if (productWarning && isVendorSupplied) {
                productWarning.textContent = '';
                productWarning.style.display = 'none';
            }

            syncProductOptionLabels();

            updateAssignmentTypeOptions();
            updatePartnerField();

            if (!isVendorSupplied && productSelect?.value) {
                productSelect.dispatchEvent(new CustomEvent('searchable-select:changed', {
                    bubbles: true,
                    detail: {
                        value: productSelect.value,
                        source: 'fulfilment-refresh',
                    },
                }));
            }
        }

        fulfilmentSourceSelect.addEventListener('change', updateFulfilmentFields);
        citySelect.addEventListener('change', updateFulfilmentFields);
        productSelect?.addEventListener('change', updateFulfilmentFields);
        deliveryAssignmentTypeSelect.addEventListener('change', updatePartnerField);
        deliveryAssignmentSelect?.addEventListener('change', updatePartnerField);

        updateFulfilmentFields();
    });
</script>
<script>
(function () {
    const availableAssetEndpoint = @json(route('rentals.available-assets'));
    let dynamicSelectedAssetIds = [];
    let dynamicAssets = [];
    const dynamicAssetCache = new Map();
    const dynamicAssetRequests = new Map();


    function normalizeId(value) {
        return String(value ?? '').trim();
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function productSelect() {
        return document.getElementById('product_id');
    }

    function warehouseSelect() {
        return document.getElementById('dispatch_warehouse_id');
    }

    function quantityInput() {
        return document.getElementById('quantity');
    }

    function selectedProductOption() {
        const select = productSelect();
        if (!select || !select.value) {
            return null;
        }

        return Array.from(select.options || []).find(function (option) {
            return option.value === select.value;
        }) || select.selectedOptions?.[0] || null;
    }

    function selectedProductLabel() {
        const option = selectedProductOption();
        return option?.getAttribute('data-product-name')
            || option?.getAttribute('data-in-house-option-label')
            || option?.textContent?.trim()
            || 'Select product';
    }

    function selectedWarehouseLabel() {
        const select = warehouseSelect();
        const option = select?.selectedOptions?.[0];
        return option?.textContent?.trim() || 'Any warehouse';
    }

    function selectedQuantity() {
        return Math.max(parseInt(quantityInput()?.value || '0', 10), 0);
    }

    function assetCacheKey(productId, warehouseId) {
        return normalizeId(productId) + ':' + normalizeId(warehouseId);
    }

    function fetchDynamicAssetsFor(productId, warehouseId) {
        const key = assetCacheKey(productId, warehouseId);
        if (dynamicAssetCache.has(key)) {
            return Promise.resolve(dynamicAssetCache.get(key));
        }
        if (dynamicAssetRequests.has(key)) {
            return dynamicAssetRequests.get(key);
        }

        const params = new URLSearchParams({ product_id: productId });
        if (warehouseId) {
            params.set('dispatch_warehouse_id', warehouseId);
        }

        const request = fetch(availableAssetEndpoint + '?' + params.toString(), {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        })
            .then(function (response) { return response.ok ? response.json() : Promise.reject(response); })
            .then(function (payload) {
                const assets = Array.isArray(payload.data) ? payload.data : [];
                dynamicAssetCache.set(key, assets);
                dynamicAssetRequests.delete(key);
                return assets;
            })
            .catch(function (error) {
                dynamicAssetRequests.delete(key);
                throw error;
            });

        dynamicAssetRequests.set(key, request);
        return request;
    }

    function preloadDynamicAssets() {
        const productId = productSelect()?.value || '';
        const warehouseId = warehouseSelect()?.value || '';
        if (!productId || !warehouseId) {
            return;
        }

        fetchDynamicAssetsFor(productId, warehouseId).catch(function () {
        });
    }

    function primaryAssetHiddenInputs() {
        return Array.from(document.querySelectorAll('#primaryAssetHiddenInputs input[type="hidden"][name="asset_ids[]"]'));
    }

    function hiddenAssetIds() {
        return primaryAssetHiddenInputs()
            .map(function (input) { return normalizeId(input.value); })
            .filter(Boolean);
    }

    function setText(id, value) {
        const node = document.getElementById(id);
        if (node) {
            node.textContent = value;
        }
    }

    function renderHiddenAssetInputs(assetIds) {
        const hiddenWrap = document.getElementById('primaryAssetHiddenInputs');
        if (!hiddenWrap) {
            return;
        }

        const uniqueIds = Array.from(new Set(assetIds.map(normalizeId).filter(Boolean)));
        hiddenWrap.innerHTML = uniqueIds.map(function (assetId) {
            return '<input type="hidden" name="asset_ids[]" value="' + escapeHtml(assetId) + '">';
        }).join('');
        const primaryAssetIdsInput = document.getElementById('primary_asset_ids');
        if (primaryAssetIdsInput) {
            primaryAssetIdsInput.value = uniqueIds.join(',');
        }
    }

    function syncAssetAssignmentCard(clearAssets) {
        if (clearAssets) {
            renderHiddenAssetInputs([]);
        }

        const selectedIds = hiddenAssetIds();
        const selectedCount = selectedIds.length;
        const selectedAssets = dynamicAssets.filter(function (asset) {
            return selectedIds.includes(normalizeId(asset.id));
        });
        const title = selectedAssets.length
            ? selectedAssets.map(function (asset) { return asset.serial_number || asset.label || ('Asset #' + asset.id); }).join(', ')
            : 'Asset not assigned';

        setText('assignmentLaunchTitle', title);
        setText('assignmentDrawerAsset', title);
        setText('assignmentLaunchProduct', selectedProductLabel());
        setText('assignmentDrawerProduct', selectedProductLabel());
        setText('assignmentLaunchWarehouse', selectedWarehouseLabel());
        setText('assignmentDrawerWarehouse', selectedWarehouseLabel());
        setText('assignmentLaunchRequired', selectedQuantity() || 0);
        setText('assetRequiredCount', selectedQuantity() || 0);
        setText('assignmentLaunchSelected', selectedCount + ' of ' + (selectedQuantity() || 0));
        setText('assetSelectedCountInline', selectedCount);
        setText('assetSelectedLabel', selectedCount + ' chosen');
        setText('assignmentDrawerStatus', selectedCount ? 'Assigned ' + selectedCount + ' of ' + (selectedQuantity() || 0) : 'Select asset');

        document.querySelectorAll('[data-summary-value="assets"]').forEach(function (node) {
            node.textContent = selectedCount ? selectedCount + ' selected' : 'Not assigned';
        });

        document.dispatchEvent(new CustomEvent('phos:rental-assets-updated', { detail: { count: selectedCount } }));
    }

    function removeDynamicModal() {
        document.getElementById('dynamicRentalAssetModal')?.remove();
        document.body.classList.remove('modal-open');
        document.body.classList.remove('assignment-drawer-open');
    }

    function assetSearchText(asset) {
        return [asset.label, asset.serial_number, asset.barcode_value, asset.asset_status, asset.condition_status, asset.warehouse]
            .filter(Boolean)
            .join(' ')
            .toLowerCase();
    }

    function renderDynamicAssetCards(modal) {
        const grid = modal.querySelector('[data-dynamic-asset-grid]');
        const empty = modal.querySelector('[data-dynamic-asset-empty]');
        const selectedCountNode = modal.querySelector('[data-dynamic-selected-count]');
        const searchValue = modal.querySelector('[data-dynamic-asset-search]')?.value?.trim().toLowerCase() || '';
        const required = Math.max(selectedQuantity(), 1);
        const isLoading = modal.dataset.assetsLoading === '1';
        const assignButton = modal.querySelector('[data-dynamic-asset-assign]');
        if (assignButton) {
            assignButton.disabled = isLoading;
            assignButton.style.opacity = isLoading ? '.55' : '1';
            assignButton.style.cursor = isLoading ? 'not-allowed' : 'pointer';
        }
        const visibleAssets = dynamicAssets.filter(function (asset) {
            return !searchValue || assetSearchText(asset).includes(searchValue);
        });

        selectedCountNode.textContent = String(dynamicSelectedAssetIds.length);
        grid.innerHTML = '';
        empty.style.display = visibleAssets.length ? 'none' : 'block';
        empty.textContent = isLoading
            ? 'Loading available assets...'
            : (dynamicAssets.length ? 'No matching assets.' : 'No available rental assets found.');

        visibleAssets.forEach(function (asset) {
            const assetId = normalizeId(asset.id);
            const selected = dynamicSelectedAssetIds.includes(assetId);
            const card = document.createElement('button');
            card.type = 'button';
            card.dataset.dynamicAssetId = assetId;
            card.style.cssText = selected
                ? 'text-align:left;border:2px solid #2563eb;background:#eff6ff;border-radius:14px;padding:14px;display:grid;gap:6px;color:#0f172a;box-shadow:0 14px 30px rgba(37,99,235,.16);'
                : 'text-align:left;border:1px solid #dbe3ef;background:#fff;border-radius:14px;padding:14px;display:grid;gap:6px;color:#0f172a;';
            card.innerHTML = '<div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start;">'
                + '<strong style="font-size:16px;line-height:1.25;">' + escapeHtml(asset.label || asset.serial_number || ('Asset #' + asset.id)) + '</strong>'
                + '<span style="border:1px solid ' + (selected ? '#2563eb' : '#cbd5e1') + ';border-radius:999px;padding:4px 10px;font-size:12px;font-weight:800;color:' + (selected ? '#1d4ed8' : '#475569') + ';">' + (selected ? 'SELECTED' : 'SELECT') + '</span>'
                + '</div>'
                + '<span style="color:#64748b;font-size:14px;">Serial: ' + escapeHtml(asset.serial_number || '-') + '</span>'
                + '<span style="color:#64748b;font-size:14px;">Barcode: ' + escapeHtml(asset.barcode_value || '-') + '</span>'
                + '<span style="color:#64748b;font-size:14px;">Warehouse: ' + escapeHtml(asset.warehouse || selectedWarehouseLabel()) + '</span>'
                + '<span style="color:#64748b;font-size:14px;">Status: ' + escapeHtml(asset.asset_status || '-') + ' | Condition: ' + escapeHtml(asset.condition_status || '-') + '</span>';
            card.addEventListener('click', function () {
                const current = dynamicSelectedAssetIds.includes(assetId);
                if (current) {
                    dynamicSelectedAssetIds = dynamicSelectedAssetIds.filter(function (id) { return id !== assetId; });
                } else if (dynamicSelectedAssetIds.length < required) {
                    dynamicSelectedAssetIds = dynamicSelectedAssetIds.concat([assetId]);
                }

                renderDynamicAssetCards(modal);
            });
            grid.appendChild(card);
        });
    }

    function createDynamicModalShell(context) {
        removeDynamicModal();

        const modal = document.createElement('div');
        modal.id = 'dynamicRentalAssetModal';
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');
        modal.style.cssText = 'position:fixed;inset:0;z-index:999999;display:flex;align-items:center;justify-content:center;background:rgba(15,23,42,.58);padding:18px;';
        modal.innerHTML = '<div style="width:min(860px, calc(100vw - 28px));max-height:90vh;overflow:auto;background:#fff;border-radius:18px;box-shadow:0 30px 80px rgba(15,23,42,.28);border:1px solid #dbe3ef;">'
            + '<div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;padding:18px 20px;border-bottom:1px solid #e2e8f0;">'
            + '<div><h2 style="margin:0;color:#0f172a;font-size:22px;line-height:1.15;">Assign Rental Asset</h2><p style="margin:6px 0 0;color:#64748b;font-size:14px;">Select available serialized stock for this rental line.</p></div>'
            + '<button type="button" data-dynamic-asset-cancel style="width:38px;height:38px;border:1px solid #cbd5e1;border-radius:12px;background:#fff;color:#334155;font-size:24px;line-height:1;cursor:pointer;">&times;</button>'
            + '</div>'
            + '<div style="padding:16px 20px;display:grid;gap:14px;">'
            + '<div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;">'
            + '<div style="border:1px solid #e2e8f0;border-radius:12px;padding:10px;background:#f8fafc;"><span style="display:block;color:#64748b;font-size:11px;font-weight:800;text-transform:uppercase;">Product</span><strong style="display:block;margin-top:4px;color:#0f172a;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + escapeHtml(context.productName) + '</strong></div>'
            + '<div style="border:1px solid #e2e8f0;border-radius:12px;padding:10px;background:#f8fafc;"><span style="display:block;color:#64748b;font-size:11px;font-weight:800;text-transform:uppercase;">Warehouse</span><strong style="display:block;margin-top:4px;color:#0f172a;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + escapeHtml(context.warehouseName) + '</strong></div>'
            + '<div style="border:1px solid #e2e8f0;border-radius:12px;padding:10px;background:#f8fafc;"><span style="display:block;color:#64748b;font-size:11px;font-weight:800;text-transform:uppercase;">Required</span><strong style="display:block;margin-top:4px;color:#0f172a;font-size:22px;">' + escapeHtml(context.required) + '</strong></div>'
            + '<div style="border:1px solid #bfdbfe;border-radius:12px;padding:10px;background:#eff6ff;"><span style="display:block;color:#1d4ed8;font-size:11px;font-weight:800;text-transform:uppercase;">Selected</span><strong data-dynamic-selected-count style="display:block;margin-top:4px;color:#0f172a;font-size:22px;">0</strong></div>'
            + '</div>'
            + '<input type="search" data-dynamic-asset-search placeholder="Search serial, barcode, or asset name" style="width:100%;min-height:46px;border:1px solid #cbd5e1;border-radius:12px;padding:10px 13px;font-size:16px;color:#0f172a;">'
            + '<div data-dynamic-asset-empty style="display:none;border:1px dashed #cbd5e1;border-radius:14px;padding:18px;text-align:center;color:#64748b;"></div>'
            + '<div data-dynamic-asset-grid style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:12px;"></div>'
            + '</div>'
            + '<div style="display:flex;justify-content:flex-end;gap:10px;padding:14px 20px;border-top:1px solid #e2e8f0;background:#f8fafc;">'
            + '<button type="button" data-dynamic-asset-cancel class="ops-button-secondary">Cancel</button>'
            + '<button type="button" data-dynamic-asset-assign class="ops-button" disabled style="opacity:.55;cursor:not-allowed;">Assign Asset</button>'
            + '</div>'
            + '</div>';

        modal.addEventListener('click', function (event) {
            if (event.target === modal || event.target.closest('[data-dynamic-asset-cancel]')) {
                event.preventDefault();
                removeDynamicModal();
            }
        });
        modal.querySelector('[data-dynamic-asset-search]')?.addEventListener('input', function () {
            renderDynamicAssetCards(modal);
        });
        modal.querySelector('[data-dynamic-asset-assign]')?.addEventListener('click', function () {
            const selectedAssets = dynamicAssets.filter(function (asset) {
                return dynamicSelectedAssetIds.includes(normalizeId(asset.id));
            });
            renderHiddenAssetInputs(dynamicSelectedAssetIds);
            document.dispatchEvent(new CustomEvent('phos:rental-assets-dynamic-selected', {
                detail: {
                    assetIds: dynamicSelectedAssetIds,
                    assets: selectedAssets,
                },
            }));
            syncAssetAssignmentCard(false);
            removeDynamicModal();
        });

        document.body.appendChild(modal);
        document.body.classList.add('modal-open');
        document.body.classList.add('assignment-drawer-open');

        return modal;
    }

    function openDynamicAssetModal() {
        const productId = productSelect()?.value || '';
        const warehouseId = warehouseSelect()?.value || '';
        const required = Math.max(selectedQuantity(), 1);

        if (!productId) {
            alert('Select product first.');
            return;
        }
        if (!warehouseId) {
            alert('Select warehouse first.');
            return;
        }

        const context = {
            productId,
            productName: selectedProductLabel(),
            warehouseId,
            warehouseName: selectedWarehouseLabel(),
            required,
        };

        dynamicSelectedAssetIds = hiddenAssetIds().slice(0, required);
        const modal = createDynamicModalShell(context);
        const key = assetCacheKey(productId, warehouseId);
        if (dynamicAssetCache.has(key)) {
            dynamicAssets = dynamicAssetCache.get(key);
            modal.dataset.assetsLoading = '0';
            renderDynamicAssetCards(modal);
            return;
        }

        dynamicAssets = [];
        modal.dataset.assetsLoading = '1';
        renderDynamicAssetCards(modal);

        fetchDynamicAssetsFor(productId, warehouseId)
            .then(function (assets) {
                dynamicAssets = assets;
                modal.dataset.assetsLoading = '0';
                renderDynamicAssetCards(modal);
            })
            .catch(function () {
                dynamicAssets = [];
                modal.dataset.assetsLoading = '0';
                renderDynamicAssetCards(modal);
            });
    }

    ['change', 'input', 'searchable-select:changed'].forEach(function (eventName) {
        productSelect()?.addEventListener(eventName, function () { syncAssetAssignmentCard(true); preloadDynamicAssets(); });
        warehouseSelect()?.addEventListener(eventName, function () { syncAssetAssignmentCard(true); preloadDynamicAssets(); });
        quantityInput()?.addEventListener(eventName, function () { syncAssetAssignmentCard(false); });
    });
    window.setTimeout(function () { syncAssetAssignmentCard(false); }, 0);
    window.setTimeout(function () { syncAssetAssignmentCard(false); preloadDynamicAssets(); }, 250);

    document.addEventListener('click', function (event) {
        const button = event.target.closest('[data-open-rental-asset-modal], #rentalAssignAssetButton, .js-open-rental-asset-modal');
        if (!button) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        openDynamicAssetModal();
    }, true);

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && document.getElementById('dynamicRentalAssetModal')) {
            removeDynamicModal();
        }
    });
})();
</script>
