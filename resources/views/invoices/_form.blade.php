@php
    $oldDescriptions = old('item_description');
    $initialItems = [];
    $safeInvoiceDateLabel = function ($value, string $format = 'd M Y') {
        if ($value instanceof \Carbon\CarbonInterface) {
            return $value->format($format);
        }

        if ($value instanceof \DateTimeInterface) {
            return \Illuminate\Support\Carbon::instance($value)->format($format);
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return \Illuminate\Support\Carbon::parse($value)->format($format);
            } catch (\Throwable $exception) {
                return '-';
            }
        }

        return '-';
    };

    $oldProductIds = old('item_product_id');
    $oldCustomNames = old('item_custom_name');
    $resolveFallbackProductId = function ($item) use ($products, $rentals, $sales) {
        if (!empty($item->product_id)) {
            return $item->product_id;
        }

        if (($item->source_type ?? null) === 'rental' && !empty($item->source_id)) {
            $matchedRental = $rentals->firstWhere('id', (int) $item->source_id);
            if ($matchedRental?->product_id) {
                return $matchedRental->product_id;
            }
        }

        if (($item->source_type ?? null) === 'sale' && !empty($item->source_id)) {
            $matchedSale = $sales->firstWhere('id', (int) $item->source_id);
            if ($matchedSale?->product_id) {
                return $matchedSale->product_id;
            }
        }

        $matchedProduct = $products->first(function ($product) use ($item) {
            return strcasecmp(trim((string) $product->name), trim((string) $item->display_description)) === 0;
        });

        return $matchedProduct?->id;
    };

    if (is_array($oldDescriptions) || is_array($oldProductIds) || is_array($oldCustomNames)) {
        $oldProductIds = is_array($oldProductIds) ? $oldProductIds : [];
        $oldCustomNames = is_array($oldCustomNames) ? $oldCustomNames : [];
        $oldDescriptions = is_array($oldDescriptions) ? $oldDescriptions : [];
        $oldSourceTypes = old('item_source_type', []);
        $oldSourceIds = old('item_source_id', []);
        $oldHsnCodes = old('item_hsn_sac_code', []);
        $oldQuantities = old('item_quantity', []);
        $oldUnits = old('item_unit', []);
        $oldDays = old('item_days', []);
        $oldRates = old('item_rate', []);
        $oldDiscounts = old('item_discount', []);
        $oldTaxes = old('item_tax_percentage', []);
        $itemIndexes = collect([
            ...array_keys($oldDescriptions),
            ...array_keys($oldCustomNames),
            ...array_keys($oldProductIds),
        ])->unique()->sort()->values()->all();

        foreach ($itemIndexes as $index) {
            $productId = $oldProductIds[$index] ?? '';
            $description = $oldDescriptions[$index] ?? '';
            $customName = $oldCustomNames[$index] ?? '';

            if ($customName === '' && !$productId) {
                $customName = $description;
            }

            $initialItems[] = [
                'product_id' => $productId,
                'source_type' => $oldSourceTypes[$index] ?? '',
                'source_id' => $oldSourceIds[$index] ?? '',
                'hsn_sac_code' => $oldHsnCodes[$index] ?? '',
                'custom_name' => $customName,
                'description' => $description,
                'quantity' => $oldQuantities[$index] ?? 1,
                'unit' => $oldUnits[$index] ?? '',
                'days' => $oldDays[$index] ?? '',
                'rate' => $oldRates[$index] ?? 0,
                'discount_amount' => $oldDiscounts[$index] ?? 0,
                'tax_percentage' => $oldTaxes[$index] ?? 0,
            ];
        }
    } elseif ($invoice && $invoice->items->isNotEmpty()) {
        $resolvedRental = $invoice->resolvedRental();

        $initialItems = $invoice->items->map(function ($item) use ($invoice, $resolveFallbackProductId, $resolvedRental, $safeInvoiceDateLabel) {
            $renewalMeta = null;
            $resolvedProductId = $resolveFallbackProductId($item);
            $resolvedSourceType = $item->source_type;
            $resolvedSourceId = $item->source_id;

            if ($invoice->rentalRenewal && ($resolvedRental?->id || $invoice->rentalRenewal->rental_id)) {
                $resolvedSourceType = 'rental';
                $resolvedSourceId = $resolvedSourceId ?: $invoice->rentalRenewal->rental_id ?: $resolvedRental?->id;
                $resolvedProductId = $resolvedProductId ?: $resolvedRental?->product_id;
            } elseif (($resolvedSourceType === 'manual' || empty($resolvedSourceType)) && $resolvedRental && $resolvedProductId) {
                if ((int) $resolvedProductId === (int) $resolvedRental->product_id || empty($item->product_id)) {
                    $resolvedSourceType = 'rental';
                    $resolvedSourceId = $resolvedSourceId ?: $resolvedRental->id;
                }
            }

            if (!$resolvedProductId && $resolvedRental && (($resolvedSourceType === 'rental') || $invoice->linkedRentalId())) {
                $resolvedProductId = $resolvedRental->product_id;
                $resolvedSourceType = 'rental';
                $resolvedSourceId = $resolvedSourceId ?: $resolvedRental->id;
            }

            if ($invoice->rentalRenewal && ($item->unit === 'renewal' || ($item->source_type === 'rental' && $item->product_id))) {
                $renewalMeta = 'Renewal period: '
                    . $safeInvoiceDateLabel($invoice->rentalRenewal->previous_end_date)
                    . ' to '
                    . $safeInvoiceDateLabel($invoice->rentalRenewal->renewed_end_date);
            }

            $resolvedDescription = $item->display_description;

            if ((!filled($resolvedDescription) || $resolvedDescription === 'Item') && $resolvedProductId) {
                $resolvedDescription = optional($item->product)->name ?: $resolvedRental?->product?->name ?: $resolvedDescription;
            }

            return [
                'product_id' => $resolvedProductId,
                'source_type' => $resolvedSourceType,
                'source_id' => $resolvedSourceId,
                'hsn_sac_code' => $item->hsn_sac_code,
                'custom_name' => $resolvedProductId ? '' : $item->display_description,
                'description' => $resolvedDescription,
                'quantity' => $item->quantity,
                'unit' => $item->unit,
                'days' => $item->days,
                'rate' => $item->rate,
                'discount_amount' => $item->discount_amount,
                'tax_percentage' => $item->tax_percentage,
                'meta_note' => $renewalMeta,
            ];
        })->values()->all();

        $hasMeaningfulInvoiceRows = collect($initialItems)->contains(function ($row) {
            return filled($row['product_id'] ?? null)
                || filled($row['source_type'] ?? null)
                || filled($row['source_id'] ?? null)
                || filled($row['description'] ?? null)
                || filled($row['custom_name'] ?? null);
        });

        if (!$hasMeaningfulInvoiceRows && ($fallbackItem = $invoice->editableFallbackItem())) {
            $initialItems = [$fallbackItem];
        }
    } elseif ($invoice && ($fallbackItem = $invoice->editableFallbackItem())) {
        $initialItems = [$fallbackItem];
    }

    $productOptions = $products->map(function ($product) {
        return [
            'id' => $product->id,
            'name' => $product->name,
        ];
    })->values()->all();

    $customerOptions = $customers->map(function ($customer) {
        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'customer_type' => $customer->customer_type,
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'company_name' => $customer->company_name,
            'phone' => $customer->phone,
            'email' => $customer->email,
            'gst_treatment' => $customer->gst_treatment,
            'place_of_supply' => $customer->place_of_supply,
            'gst_number' => $customer->gst_number,
            'address' => $customer->address,
            'city' => $customer->city,
            'state' => $customer->state,
            'pincode' => $customer->pincode,
            'patient_name' => $customer->patient_name,
            'notes' => $customer->notes,
        ];
    })->values()->all();

    $selectedCustomerOption = collect($customerOptions)->firstWhere('id', (int) $selectedCustomerId);
    $selectedCustomerLabel = $selectedCustomerOption
        ? trim(collect([
            $selectedCustomerOption['name'] ?? null,
            $selectedCustomerOption['phone'] ? '- ' . $selectedCustomerOption['phone'] : null,
        ])->filter()->implode(' '))
        : 'Search customer';

    $indianStates = [
        'Andhra Pradesh',
        'Arunachal Pradesh',
        'Assam',
        'Bihar',
        'Chhattisgarh',
        'Goa',
        'Gujarat',
        'Haryana',
        'Himachal Pradesh',
        'Jharkhand',
        'Karnataka',
        'Kerala',
        'Madhya Pradesh',
        'Maharashtra',
        'Manipur',
        'Meghalaya',
        'Mizoram',
        'Nagaland',
        'Odisha',
        'Punjab',
        'Rajasthan',
        'Sikkim',
        'Tamil Nadu',
        'Telangana',
        'Tripura',
        'Uttar Pradesh',
        'Uttarakhand',
        'West Bengal',
        'Andaman and Nicobar Islands',
        'Chandigarh',
        'Dadra and Nagar Haveli and Daman and Diu',
        'Delhi',
        'Jammu and Kashmir',
        'Ladakh',
        'Lakshadweep',
        'Puducherry',
    ];

    $gstTreatmentOptions = [
        'Registered Business - Regular',
        'Unregistered Business',
        'Consumer',
        'Overseas',
        'SEZ',
    ];

    $selectedPlaceOfSupplyLabel = $placeOfSupplyStateValue ?: 'Search state';
    $taxCalculationModeValue = old('tax_calculation_mode', $invoice->tax_calculation_mode ?? 'exclusive');

    $rentalReferenceOptions = $rentals->map(function ($rental) {
        return [
            'id' => $rental->id,
            'product_id' => $rental->product_id,
            'product_name' => optional($rental->product)->name,
            'customer_name' => optional($rental->customer)->name ?: $rental->customer_name,
            'quantity' => $rental->quantity,
            'rate' => $rental->rental_amount,
        ];
    })->values()->all();

    $saleReferenceOptions = $sales->map(function ($sale) {
        return [
            'id' => $sale->id,
            'product_id' => $sale->product_id,
            'product_name' => optional($sale->product)->name,
            'customer_name' => optional($sale->customer)->name,
            'quantity' => $sale->quantity,
            'rate' => $sale->sale_amount,
        ];
    })->values()->all();
@endphp

<div style="max-width:1240px; margin:0 auto;">
    <style>
        .invoice-form-shell {
            display: grid;
            gap: 22px;
        }

        .invoice-form-hero {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 22px;
            padding: 30px;
            border-radius: 28px;
            border: 1px solid #dbe7f3;
            background: linear-gradient(135deg, #f8fbff 0%, #eef6ff 100%);
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.06);
        }

        .invoice-form-hero h1 {
            margin: 0 0 8px;
            font-size: 34px;
            line-height: 1.05;
            color: #0f172a;
        }

        .invoice-form-hero p {
            margin: 0;
            color: #64748b;
            font-size: 15px;
            line-height: 1.7;
            max-width: 700px;
        }

        .invoice-form-badge {
            display: inline-flex;
            align-items: center;
            padding: 8px 12px;
            border-radius: 999px;
            background: #ecfeff;
            color: #0f766e;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .invoice-error-box {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
            padding: 14px 16px;
            border-radius: 18px;
        }

        .invoice-error-box ul {
            margin: 0;
            padding-left: 18px;
        }

        .invoice-form-grid {
            display: grid;
            gap: 22px;
        }

        .invoice-panel {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 24px;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.04);
            overflow: hidden;
        }

        .invoice-panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            padding: 22px 24px 0;
            flex-wrap: wrap;
        }

        .invoice-panel-title {
            margin: 0;
            font-size: 20px;
            color: #0f172a;
        }

        .invoice-panel-subtitle {
            margin: 6px 0 0;
            color: #64748b;
            font-size: 14px;
        }

        .invoice-panel-body {
            padding: 24px;
        }

        .invoice-field-grid-3 {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
        }

        .invoice-field-grid-2 {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        .invoice-address-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 20px;
        }

        .invoice-supply-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
        }

        .invoice-inline-note {
            margin-top: 12px;
            padding: 12px 14px;
            border-radius: 14px;
            border: 1px solid #dbe7f3;
            background: #f8fbff;
            color: #475569;
            font-size: 13px;
            line-height: 1.6;
        }

        .invoice-customer-stack,
        .invoice-customer-modal-form {
            display: grid;
            gap: 10px;
        }

        .invoice-customer-tools,
        .invoice-inline-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }

        .invoice-helper-text {
            margin: 0;
            color: #64748b;
            font-size: 11px;
            line-height: 1.5;
        }

        .invoice-customer-picker {
            position: relative;
        }

        .invoice-customer-trigger {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            min-height: 45px;
            padding: 10px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            background: #ffffff;
            color: #0f172a;
            font-size: 14px;
            cursor: pointer;
            text-align: left;
        }

        .invoice-customer-trigger strong {
            display: block;
            font-size: 14px;
            font-weight: 500;
            color: #0f172a;
            line-height: 1.3;
        }

        .invoice-customer-trigger span {
            display: none;
            color: #64748b;
            font-size: 12px;
            margin-top: 3px;
        }

        .invoice-customer-menu {
            position: absolute;
            top: calc(100% + 8px);
            left: 0;
            right: 0;
            z-index: 30;
            padding: 12px;
            border: 1px solid #dbe7f3;
            border-radius: 16px;
            background: #ffffff;
            box-shadow: 0 22px 44px rgba(15, 23, 42, 0.12);
            display: none;
        }

        .invoice-customer-menu.is-open {
            display: block;
        }

        .invoice-customer-search {
            width: 100%;
            margin-bottom: 8px;
        }

        .invoice-customer-results {
            max-height: 240px;
            overflow-y: auto;
            display: grid;
            gap: 8px;
        }

        .invoice-customer-option,
        .invoice-customer-create {
            width: 100%;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            background: #ffffff;
            padding: 9px 11px;
            text-align: left;
            cursor: pointer;
        }

        .invoice-customer-option strong,
        .invoice-customer-create strong {
            display: block;
            color: #0f172a;
            font-size: 13px;
        }

        .invoice-customer-option span,
        .invoice-customer-create span {
            display: block;
            color: #64748b;
            font-size: 11px;
            margin-top: 3px;
        }

        .invoice-customer-option:hover,
        .invoice-customer-create:hover {
            border-color: #94a3b8;
            background: #f8fbff;
        }

        .invoice-customer-create {
            border-style: dashed;
            background: #f8fbff;
        }

        .invoice-customer-empty {
            padding: 14px;
            border-radius: 14px;
            background: #f8fafc;
            color: #64748b;
            font-size: 13px;
            text-align: center;
        }

        .invoice-state-picker {
            position: relative;
        }

        .invoice-state-trigger {
            width: 100%;
            min-height: 44px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 10px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            background: #ffffff;
            color: #0f172a;
            font-size: 14px;
            cursor: pointer;
            text-align: left;
        }

        .invoice-state-menu {
            position: absolute;
            top: calc(100% + 8px);
            left: 0;
            right: 0;
            z-index: 25;
            display: none;
            padding: 12px;
            border: 1px solid #dbe7f3;
            border-radius: 16px;
            background: #ffffff;
            box-shadow: 0 22px 44px rgba(15, 23, 42, 0.12);
        }

        .invoice-state-menu.is-open {
            display: block;
        }

        .invoice-state-search {
            width: 100%;
            margin-bottom: 8px;
        }

        .invoice-state-results {
            max-height: 220px;
            overflow-y: auto;
            display: grid;
            gap: 8px;
        }

        .invoice-state-option {
            width: 100%;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            background: #ffffff;
            padding: 9px 11px;
            text-align: left;
            color: #0f172a;
            font-size: 13px;
            cursor: pointer;
        }

        .invoice-state-option:hover {
            border-color: #94a3b8;
            background: #f8fbff;
        }

        .invoice-copy-toggle {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 14px;
            color: #334155;
            font-size: 13px;
            font-weight: 600;
        }

        .invoice-card-lite {
            padding: 18px;
            border: 1px solid #e2e8f0;
            border-radius: 20px;
            background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%);
        }

        .invoice-card-lite h3 {
            margin: 0 0 14px;
            font-size: 16px;
            color: #0f172a;
        }

        .invoice-label {
            display: block;
            margin-bottom: 8px;
            color: #475569;
            font-size: 13px;
            font-weight: 600;
        }

        .invoice-search-input,
        .invoice-input,
        .invoice-select,
        .invoice-textarea {
            width: 100%;
            padding: 11px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            background: #ffffff;
            color: #0f172a;
            font-size: 14px;
            outline: none;
        }

        .invoice-search-input {
            background: #f8fafc;
        }

        .invoice-textarea {
            min-height: 120px;
            resize: vertical;
        }

        .invoice-search-input:focus,
        .invoice-input:focus,
        .invoice-select:focus,
        .invoice-textarea:focus {
            border-color: #0f766e;
            box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.12);
        }

        .invoice-stack {
            display: grid;
            gap: 12px;
        }

        .invoice-light-button,
        .invoice-link-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            padding: 10px 14px;
            text-decoration: none;
            font-weight: 600;
            cursor: pointer;
        }

        .invoice-customer-action {
            min-height: 38px;
            padding: 8px 12px;
            font-size: 12px;
            border-radius: 10px;
        }

        .invoice-customer-action.invoice-light-button {
            border-color: #cbd5e1;
            background: #ffffff;
            color: #334155;
        }

        .invoice-customer-action.invoice-link-button {
            border-color: #0f172a;
            background: #0f172a;
            color: #ffffff;
        }

        .invoice-customer-inline-link {
            display: inline-flex;
            align-items: center;
            color: #64748b;
            font-size: 11px;
            text-decoration: none;
        }

        .invoice-light-button {
            border: 1px solid #cbd5e1;
            background: #ffffff;
            color: #0f172a;
        }

        .invoice-link-button {
            border: 1px solid #dbe7f3;
            background: #f8fbff;
            color: #0f766e;
        }

        .invoice-mini-badge {
            display: inline-flex;
            align-items: center;
            padding: 6px 10px;
            border-radius: 999px;
            background: #ecfeff;
            color: #0f766e;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .invoice-modal-overlay {
            position: fixed;
            inset: 0;
            z-index: 60;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 12px;
            background: rgba(15, 23, 42, 0.5);
            overflow: hidden;
        }

        .invoice-modal-overlay.is-open {
            display: flex;
        }

        .invoice-modal-card {
            width: 100%;
            max-width: 720px;
            max-height: min(90vh, calc(100dvh - 24px));
            display: flex;
            flex-direction: column;
            overflow: hidden;
            border-radius: 24px;
            border: 1px solid #dbe7f3;
            background: #ffffff;
            box-shadow: 0 28px 70px rgba(15, 23, 42, 0.18);
        }

        .invoice-modal-header,
        .invoice-modal-body,
        .invoice-modal-footer {
            padding: 22px 24px;
        }

        .invoice-modal-header {
            flex: 0 0 auto;
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            border-bottom: 1px solid #e2e8f0;
            background: #ffffff;
            z-index: 2;
        }

        .invoice-modal-body {
            flex: 1 1 auto;
            min-height: 0;
            overflow-y: auto;
            overflow-x: hidden;
            -webkit-overflow-scrolling: touch;
            overscroll-behavior: contain;
        }

        .invoice-modal-title {
            margin: 0;
            font-size: 24px;
            color: #0f172a;
        }

        .invoice-modal-subtitle {
            margin: 8px 0 0;
            color: #64748b;
            font-size: 14px;
            line-height: 1.6;
        }

        .invoice-modal-close {
            border: 1px solid #cbd5e1;
            background: #f8fafc;
            color: #334155;
            border-radius: 999px;
            width: 44px;
            height: 44px;
            min-width: 44px;
            min-height: 44px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0;
        }

        .invoice-modal-close::before {
            content: '×';
            font-size: 24px;
            line-height: 1;
        }

        .invoice-modal-grid-3 {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
        }

        .invoice-modal-close::before {
            content: '\00D7';
        }

        .invoice-modal-grid-2 {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        .invoice-modal-section {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e2e8f0;
        }

        .invoice-modal-section:first-child {
            margin-top: 0;
            padding-top: 0;
            border-top: none;
        }

        .invoice-modal-section h3 {
            margin: 0 0 14px;
            font-size: 16px;
            color: #0f172a;
        }

        .invoice-modal-errors {
            display: none;
            margin-bottom: 16px;
            padding: 12px 14px;
            border-radius: 14px;
            background: #fee2e2;
            border: 1px solid #fecaca;
            color: #991b1b;
            font-size: 13px;
        }

        .invoice-modal-errors.is-visible {
            display: block;
        }

        .invoice-modal-errors ul {
            margin: 0;
            padding-left: 18px;
        }

        .invoice-modal-footer {
            flex: 0 0 auto;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            border-top: 1px solid #e2e8f0;
            background: #ffffff;
        }

        .invoice-add-button,
        .invoice-submit-button,
        .invoice-remove-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
        }

        .invoice-add-button {
            border: 1px solid #0f172a;
            background: #0f172a;
            color: #ffffff;
            padding: 11px 16px;
        }

        .invoice-submit-button {
            border: 1px solid #0f172a;
            background: #0f172a;
            color: #ffffff;
            padding: 13px 20px;
            box-shadow: 0 14px 30px rgba(15, 23, 42, 0.16);
        }

        .invoice-remove-button {
            border: none;
            background: #ef4444;
            color: #ffffff;
            padding: 8px 10px;
        }

        .invoice-items-wrap {
            overflow-x: auto;
            padding: 0 24px 24px;
        }

        .invoice-items-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            min-width: 1180px;
        }

        .invoice-items-table thead th {
            background: #f8fafc;
            color: #334155;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            padding: 14px 12px;
            border-top: 1px solid #e2e8f0;
            border-bottom: 1px solid #e2e8f0;
            text-align: left;
            white-space: nowrap;
        }

        .invoice-items-table thead th:first-child {
            border-left: 1px solid #e2e8f0;
            border-top-left-radius: 16px;
        }

        .invoice-items-table thead th:last-child {
            border-right: 1px solid #e2e8f0;
            border-top-right-radius: 16px;
        }

        .invoice-items-table tbody td {
            border-left: 1px solid #e2e8f0;
            border-bottom: 1px solid #e2e8f0;
            padding: 10px;
            vertical-align: top;
            background: #ffffff;
        }

        .invoice-items-table tbody td:last-child {
            border-right: 1px solid #e2e8f0;
        }

        .invoice-items-table tbody tr:last-child td:first-child {
            border-bottom-left-radius: 16px;
        }

        .invoice-items-table tbody tr:last-child td:last-child {
            border-bottom-right-radius: 16px;
        }

        .invoice-row-total {
            display: inline-block;
            min-width: 90px;
            text-align: right;
            color: #0f172a;
        }

        .invoice-bottom-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 360px;
            gap: 22px;
            align-items: start;
        }

        .invoice-summary-card {
            padding: 22px;
            border-radius: 22px;
            border: 1px solid #dbe7f3;
            background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
        }

        .invoice-summary-card h3 {
            margin: 0 0 16px;
            color: #0f172a;
            font-size: 18px;
        }

        .invoice-summary-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 18px;
            padding: 8px 0;
            color: #334155;
            font-size: 14px;
        }

        .invoice-summary-row strong {
            color: #0f172a;
        }

        .invoice-summary-divider {
            border: 0;
            border-top: 1px solid #e2e8f0;
            margin: 14px 0;
        }

        .invoice-summary-total {
            margin-top: 8px;
            padding: 16px 18px;
            border-radius: 18px;
            background: #0f172a;
            color: #ffffff;
        }

        .invoice-summary-total .invoice-summary-row,
        .invoice-summary-total .invoice-summary-row strong {
            color: #ffffff;
        }

        .invoice-submit-wrap {
            display: flex;
            justify-content: flex-end;
        }

        @media (max-width: 1100px) {
            .invoice-bottom-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 900px) {
            .invoice-form-hero,
            .invoice-field-grid-3,
            .invoice-field-grid-2,
            .invoice-address-grid,
            .invoice-supply-grid,
            .invoice-modal-grid-2,
            .invoice-modal-grid-3 {
                grid-template-columns: 1fr;
                display: grid;
            }

            .invoice-form-hero {
                gap: 16px;
            }

            .invoice-modal-overlay {
                align-items: flex-end;
                padding: 8px;
            }

            .invoice-modal-card {
                max-width: 100%;
                max-height: min(92vh, calc(100dvh - 16px));
                border-radius: 20px;
            }

            .invoice-modal-header,
            .invoice-modal-body,
            .invoice-modal-footer {
                padding: 14px;
            }
        }

        @media (max-width: 640px) {
            .invoice-form-hero,
            .invoice-panel-body,
            .invoice-items-wrap {
                padding: 18px;
            }

            .invoice-panel-header {
                padding: 18px 18px 0;
            }

            .invoice-modal-title {
                font-size: 18px;
            }

            .invoice-modal-subtitle {
                font-size: 13px;
            }

            .invoice-modal-footer {
                display: grid;
                grid-template-columns: 1fr;
            }

            .invoice-light-button,
            .invoice-submit-button {
                width: 100%;
            }

            .invoice-panel,
            .invoice-card-lite,
            .invoice-summary-card {
                border-radius: 18px;
            }

            .invoice-submit-wrap {
                justify-content: stretch;
            }

            .invoice-submit-button {
                width: 100%;
            }
        }
    </style>

    <div class="invoice-form-shell">
        <div class="invoice-form-hero">
            <div>
                <h1>{{ $pageTitle }}</h1>
                <p>{{ $pageSubtitle }}</p>
            </div>
        </div>

        @if ($errors->any())
            <div class="invoice-error-box">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ $formAction }}" id="invoiceForm">
            @csrf
            @if($formMethod !== 'POST')
                @method($formMethod)
            @endif

            <div class="invoice-form-grid">
                <div class="invoice-panel">
                    <div class="invoice-panel-header">
                        <div>
                            <h2 class="invoice-panel-title">Invoice Details</h2>
                            <p class="invoice-panel-subtitle">Set the commercial and reference details for this invoice.</p>
                        </div>
                    </div>

                    <div class="invoice-panel-body">
                        <div class="invoice-field-grid-3">
                            <div>
                                <label class="invoice-label">Invoice Number</label>
                                <input type="text" name="invoice_number" value="{{ $invoiceNumberValue }}" class="invoice-input">
                            </div>

                            <div>
                                <label class="invoice-label">Invoice Date</label>
                                <input type="date" name="invoice_date" value="{{ $invoiceDateValue }}" class="invoice-input">
                            </div>

                            <div>
                                <label class="invoice-label">Due Date</label>
                                <input type="date" name="due_date" value="{{ $dueDateValue }}" class="invoice-input">
                            </div>

                            <div>
                                <label class="invoice-label">PO Number</label>
                                <input type="text" name="purchase_order_number" value="{{ $purchaseOrderNumberValue }}" class="invoice-input">
                            </div>

                            <div>
                                <label class="invoice-label">PO Date</label>
                                <input type="date" name="purchase_order_date" value="{{ $purchaseOrderDateValue }}" class="invoice-input">
                            </div>

                            <div>
                                <label class="invoice-label">Reference Number</label>
                                <input type="text" name="reference_number" value="{{ $referenceNumberValue }}" class="invoice-input">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="invoice-panel">
                    <div class="invoice-panel-header">
                        <div>
                            <h2 class="invoice-panel-title">Supply / GST Details</h2>
                            <p class="invoice-panel-subtitle">Capture the customer link and place-of-supply details used for GST treatment.</p>
                        </div>
                    </div>

                    <div class="invoice-panel-body">
                        <div class="invoice-supply-grid">
                            <div>
                                <label class="invoice-label">Customer</label>
                                <div class="invoice-customer-stack">
                                    <input type="hidden" name="customer_id" id="customer_id" value="{{ $selectedCustomerId }}">

                                    <div class="invoice-customer-picker" id="customer_picker">
                                        <button type="button" class="invoice-customer-trigger" id="customer_picker_trigger">
                                            <div>
                                                <strong id="customer_picker_label">{{ $selectedCustomerLabel }}</strong>
                                                <span id="customer_picker_hint">Search customer</span>
                                            </div>
                                        </button>

                                        <div class="invoice-customer-menu" id="customer_picker_menu">
                                            <input type="text" id="customer_search" class="invoice-search-input invoice-customer-search" placeholder="Search customer">
                                            <div class="invoice-customer-results" id="customer_results"></div>
                                        </div>
                                    </div>

                                    <div class="invoice-customer-tools">
                                        <button type="button" class="invoice-link-button invoice-customer-action" id="fill_customer_details">Use Selected</button>
                                        <button type="button" class="invoice-light-button invoice-customer-action" id="open_customer_modal">+ New Customer</button>
                                    </div>

                                    <p class="invoice-helper-text">Search existing customer or add quickly.</p>
                                </div>
                            </div>

                            <div>
                                <label class="invoice-label">Place of Supply</label>
                                <input type="hidden" name="place_of_supply_state" id="place_of_supply_state" value="{{ $placeOfSupplyStateValue }}">

                                <div class="invoice-state-picker" data-state-picker data-target-input="place_of_supply_state" data-trigger-label="place_of_supply_label" data-search-input="place_of_supply_search" data-results-box="place_of_supply_results">
                                    <button type="button" class="invoice-state-trigger" data-state-trigger>
                                        <span id="place_of_supply_label">{{ $selectedPlaceOfSupplyLabel }}</span>
                                    </button>

                                    <div class="invoice-state-menu" data-state-menu>
                                        <input type="text" id="place_of_supply_search" class="invoice-search-input invoice-state-search" placeholder="Search state" data-state-search>
                                        <div id="place_of_supply_results" class="invoice-state-results" data-state-results></div>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <label class="invoice-label">GST Mode</label>
                                <select name="tax_calculation_mode" id="tax_calculation_mode" class="invoice-select calc-input">
                                    <option value="exclusive" {{ $taxCalculationModeValue === 'exclusive' ? 'selected' : '' }}>Tax Exclusive</option>
                                    <option value="inclusive" {{ $taxCalculationModeValue === 'inclusive' ? 'selected' : '' }}>Tax Inclusive</option>
                                </select>
                            </div>
                        </div>

                        <div class="invoice-inline-note">
                            Tax mode auto-selection:
                            <strong id="tax_mode_badge">{{ $organization->state && $placeOfSupplyStateValue && strtolower(trim((string) $organization->state)) === strtolower(trim((string) $placeOfSupplyStateValue)) ? 'CGST / SGST' : 'IGST' }}</strong>
                            based on tenant state and place of supply.
                            GST calculation:
                            <strong id="tax_calculation_mode_badge">{{ $taxCalculationModeValue === 'inclusive' ? 'Inclusive' : 'Exclusive' }}</strong>.
                        </div>
                    </div>
                </div>

                <div class="invoice-address-grid">
                    <div class="invoice-panel">
                        <div class="invoice-panel-header">
                            <div>
                                <h2 class="invoice-panel-title">Bill To</h2>
                                <p class="invoice-panel-subtitle">Customer billing contact and GST registration details.</p>
                            </div>
                        </div>

                        <div class="invoice-panel-body">
                            <div class="invoice-card-lite">
                                <div class="invoice-stack">
                                    <input type="text" name="bill_to_name" value="{{ $billToNameValue }}" placeholder="Name" class="invoice-input">
                                    @php($billToPhoneParts = \App\Support\PhoneNumber::split($billToPhoneValue))
                                    <div style="display:flex; align-items:center; border:1px solid #dbe3ef; border-radius:12px; overflow:visible; background:#fff;">
                                        @include('partials.country-code-picker', [
                                            'name' => 'bill_to_phone_country_code',
                                            'pickerId' => 'bill_to_phone_country_code',
                                            'value' => old('bill_to_phone_country_code', $billToPhoneParts['code']),
                                            'options' => \App\Support\PhoneNumber::countryCodeOptions(),
                                            'dividerColor' => '#dbe3ef',
                                            'width' => '92px',
                                        ])
                                        <input type="text" name="bill_to_phone" value="{{ $billToPhoneParts['local'] }}" placeholder="Phone" class="invoice-input" inputmode="numeric" maxlength="15" pattern="[0-9]{6,15}" data-phone-local style="border:none; box-shadow:none;">
                                    </div>
                                    <input type="email" name="bill_to_email" value="{{ $billToEmailValue }}" placeholder="Email" class="invoice-input">
                                    <textarea name="bill_to_address" placeholder="Address" class="invoice-textarea">{{ $billToAddressValue }}</textarea>
                                    <input type="text" name="bill_to_city" value="{{ $billToCityValue }}" placeholder="City" class="invoice-input">
                                    <input type="text" name="bill_to_state" value="{{ $billToStateValue }}" placeholder="State" class="invoice-input">
                                    <input type="text" name="bill_to_state_code" value="{{ $billToStateCodeValue }}" placeholder="State Code" class="invoice-input">
                                    <input type="text" name="bill_to_pincode" value="{{ $billToPincodeValue }}" placeholder="Pincode" class="invoice-input">
                                    <input type="text" name="bill_to_gstin" value="{{ $billToGstinValue }}" placeholder="GSTIN" class="invoice-input">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="invoice-panel">
                        <div class="invoice-panel-header">
                            <div>
                                <h2 class="invoice-panel-title">Ship To</h2>
                                <p class="invoice-panel-subtitle">Delivery location details for rental dispatches and product supply.</p>
                            </div>
                        </div>

                        <div class="invoice-panel-body">
                            <div class="invoice-card-lite">
                                <label class="invoice-copy-toggle">
                                    <input type="checkbox" id="ship_same_as_bill">
                                    Ship To same as Bill To
                                </label>

                                <div class="invoice-stack">
                                    <input type="text" name="ship_to_name" value="{{ $shipToNameValue }}" placeholder="Name" class="invoice-input">
                                    @php($shipToPhoneParts = \App\Support\PhoneNumber::split($shipToPhoneValue))
                                    <div style="display:flex; align-items:center; border:1px solid #dbe3ef; border-radius:12px; overflow:visible; background:#fff;">
                                        @include('partials.country-code-picker', [
                                            'name' => 'ship_to_phone_country_code',
                                            'pickerId' => 'ship_to_phone_country_code',
                                            'value' => old('ship_to_phone_country_code', $shipToPhoneParts['code']),
                                            'options' => \App\Support\PhoneNumber::countryCodeOptions(),
                                            'dividerColor' => '#dbe3ef',
                                            'width' => '92px',
                                        ])
                                        <input type="text" name="ship_to_phone" value="{{ $shipToPhoneParts['local'] }}" placeholder="Phone" class="invoice-input" inputmode="numeric" maxlength="15" pattern="[0-9]{6,15}" data-phone-local style="border:none; box-shadow:none;">
                                    </div>
                                    <textarea name="ship_to_address" placeholder="Address" class="invoice-textarea">{{ $shipToAddressValue }}</textarea>
                                    <input type="text" name="ship_to_city" value="{{ $shipToCityValue }}" placeholder="City" class="invoice-input">
                                    <input type="text" name="ship_to_state" value="{{ $shipToStateValue }}" placeholder="State" class="invoice-input">
                                    <input type="text" name="ship_to_state_code" value="{{ $shipToStateCodeValue }}" placeholder="State Code" class="invoice-input">
                                    <input type="text" name="ship_to_pincode" value="{{ $shipToPincodeValue }}" placeholder="Pincode" class="invoice-input">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="invoice-panel">
                    <div class="invoice-panel-header">
                        <div>
                            <h2 class="invoice-panel-title">Invoice Items</h2>
                            <p class="invoice-panel-subtitle">Add rental, sale, refill, or free-text line items and the summary will update automatically.</p>
                        </div>

                        <button type="button" onclick="addRow()" class="invoice-add-button">+ Add Row</button>
                    </div>

                    <div class="invoice-items-wrap">
                        @if($invoice && $invoice->rentalRenewal)
                            <div class="invoice-inline-note" style="margin: 0 0 16px; border-color:#bfdbfe; background:#eff6ff; color:#1e3a8a;">
                                <strong>Renewal Invoice Context</strong><br>
                                Product-linked renewal for
                                {{ $invoice->items->firstWhere('product_id')?->product?->name ?? 'rental item' }}
                                from {{ $safeInvoiceDateLabel($invoice->rentalRenewal->previous_end_date) }}
                                to {{ $safeInvoiceDateLabel($invoice->rentalRenewal->renewed_end_date) }}.
                            </div>
                        @endif
                        <div class="invoice-inline-note" style="margin: 0 0 16px;">
                            Select a product when it exists, or type a custom line item directly in the description field for services, fees, or one-time charges.
                        </div>
                        <table class="invoice-items-table" id="itemsTable">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Type</th>
                                    <th>Reference</th>
                                    <th>Source ID</th>
                                    <th>HSN/SAC</th>
                                    <th>Item Description</th>
                                    <th>Qty</th>
                                    <th>Unit</th>
                                    <th>Days</th>
                                    <th>Rate</th>
                                    <th>Discount</th>
                                    <th>Tax %</th>
                                    <th>Amount</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody id="itemsBody"></tbody>
                        </table>
                    </div>
                </div>

                <div class="invoice-bottom-grid">
                    <div class="invoice-panel">
                        <div class="invoice-panel-header">
                            <div>
                                <h2 class="invoice-panel-title">Notes & Terms</h2>
                                <p class="invoice-panel-subtitle">Add customer-facing notes and contractual terms for the invoice.</p>
                            </div>
                        </div>

                        <div class="invoice-panel-body">
                            <div style="margin-bottom: 16px;">
                                <label class="invoice-label">Notes</label>
                                <textarea name="notes" class="invoice-textarea">{{ $notesValue }}</textarea>
                            </div>

                            <div>
                                <label class="invoice-label">Terms & Conditions</label>
                                <textarea name="terms_conditions" class="invoice-textarea" style="min-height: 160px;">{{ $termsValue }}</textarea>
                            </div>
                        </div>
                    </div>

                    <div class="invoice-summary-card">
                        <h3>Totals</h3>

                        <div style="margin-bottom: 12px;">
                            <label class="invoice-label">Deposit Amount</label>
                            <input type="number" step="0.01" name="deposit_amount" id="deposit_amount" value="{{ $depositAmountValue }}" class="invoice-input">
                        </div>

                        <div style="margin-bottom: 12px;">
                            <label class="invoice-label">Shipping Charges</label>
                            <input type="number" step="0.01" name="shipping_charges" id="shipping_charges" value="{{ $shippingChargesValue }}" class="invoice-input">
                        </div>

                        <div style="margin-bottom: 12px;">
                            <label class="invoice-label">Paid Amount</label>
                            <input type="number" step="0.01" name="paid_amount" id="paid_amount" value="{{ $paidAmountValue }}" class="invoice-input">
                        </div>

                        <hr class="invoice-summary-divider">

                        <div class="invoice-summary-row"><span>Subtotal</span><strong id="summary_subtotal">&#8377;0.00</strong></div>
                        <div class="invoice-summary-row"><span>Discount</span><strong id="summary_discount">&#8377;0.00</strong></div>
                        <div class="invoice-summary-row"><span>Taxable</span><strong id="summary_taxable">&#8377;0.00</strong></div>
                        <div class="invoice-summary-row"><span>CGST</span><strong id="summary_cgst">&#8377;0.00</strong></div>
                        <div class="invoice-summary-row"><span>SGST</span><strong id="summary_sgst">&#8377;0.00</strong></div>
                        <div class="invoice-summary-row"><span>IGST</span><strong id="summary_igst">&#8377;0.00</strong></div>
                        <div class="invoice-summary-row"><span>Deposit</span><strong id="summary_deposit">&#8377;0.00</strong></div>
                        <div class="invoice-summary-row"><span>Shipping</span><strong id="summary_shipping">&#8377;0.00</strong></div>

                        <hr class="invoice-summary-divider">

                        <div class="invoice-summary-total">
                            <div class="invoice-summary-row"><span>Total</span><strong id="summary_total">&#8377;0.00</strong></div>
                            <div class="invoice-summary-row"><span>Paid</span><strong id="summary_paid">&#8377;0.00</strong></div>
                            <div class="invoice-summary-row"><span>Balance</span><strong id="summary_balance">&#8377;0.00</strong></div>
                        </div>
                    </div>
                </div>

                <div class="invoice-submit-wrap">
                    <button type="submit" class="invoice-submit-button">{{ $submitLabel }}</button>
                </div>
            </div>
        </form>

        <div class="invoice-modal-overlay" id="customer_modal">
            <div class="invoice-modal-card">
                <div class="invoice-modal-header">
                    <div>
                        <span class="invoice-mini-badge">Quick Add</span>
                        <h2 class="invoice-modal-title">Add New Customer</h2>
                        <p class="invoice-modal-subtitle">Create a tenant-scoped customer without leaving the invoice screen. The new customer will be selected automatically after save.</p>
                    </div>

                    <button type="button" class="invoice-modal-close" id="close_customer_modal">Close</button>
                </div>

                <div class="invoice-modal-body">
                    <div class="invoice-modal-errors" id="customer_modal_errors"></div>

                    <div class="invoice-customer-modal-form">
                        <div class="invoice-modal-section">
                            <h3>Customer Basics</h3>

                            <div class="invoice-modal-grid-3">
                                <div>
                                    <label class="invoice-label">Customer Type</label>
                                    <select id="modal_customer_type" class="invoice-select">
                                        <option value="Business">Business</option>
                                        <option value="Individual">Individual</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="invoice-label">First Name</label>
                                    <input type="text" id="modal_first_name" class="invoice-input" placeholder="First name">
                                </div>

                                <div>
                                    <label class="invoice-label">Last Name</label>
                                    <input type="text" id="modal_last_name" class="invoice-input" placeholder="Last name">
                                </div>
                            </div>

                            <div class="invoice-modal-grid-2" style="margin-top: 16px;">
                                <div>
                                    <label class="invoice-label">Company Name</label>
                                    <input type="text" id="modal_company_name" class="invoice-input" placeholder="Company or clinic name">
                                </div>

                                <div>
                                    <label class="invoice-label">Patient Name</label>
                                    <input type="text" id="modal_patient_name" class="invoice-input" placeholder="Patient name if applicable">
                                </div>
                            </div>
                        </div>

                        <div class="invoice-modal-section">
                            <h3>Contact & GST</h3>

                            <div class="invoice-modal-grid-3">
                                <div>
                                    <label class="invoice-label">Phone</label>
                                    <div style="display:flex; align-items:center; border:1px solid #dbe3ef; border-radius:12px; overflow:visible; background:#fff;">
                                        @include('partials.country-code-picker', [
                                            'name' => 'modal_phone_country_code',
                                            'pickerId' => 'modal_phone_country_code',
                                            'value' => \App\Support\PhoneNumber::DEFAULT_CODE,
                                            'options' => \App\Support\PhoneNumber::countryCodeOptions(),
                                            'dividerColor' => '#dbe3ef',
                                            'width' => '92px',
                                        ])
                                        <input type="text" id="modal_phone" class="invoice-input" placeholder="Phone number" inputmode="numeric" maxlength="15" pattern="[0-9]{6,15}" data-phone-local style="border:none; box-shadow:none;">
                                    </div>
                                </div>

                                <div>
                                    <label class="invoice-label">Email Address</label>
                                    <input type="email" id="modal_email" class="invoice-input" placeholder="Email address">
                                </div>

                                <div>
                                    <label class="invoice-label">GST Treatment</label>
                                    <select id="modal_gst_treatment" class="invoice-select">
                                        <option value="">Select GST treatment</option>
                                        @foreach($gstTreatmentOptions as $gstTreatmentOption)
                                            <option value="{{ $gstTreatmentOption }}">{{ $gstTreatmentOption }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            <div class="invoice-modal-grid-2" style="margin-top: 16px;">
                                <div>
                                    <label class="invoice-label">Place of Supply</label>
                                    <input type="hidden" id="modal_place_of_supply" value="">
                                    <div class="invoice-state-picker" data-state-picker data-target-input="modal_place_of_supply" data-trigger-label="modal_place_of_supply_label" data-search-input="modal_place_of_supply_search" data-results-box="modal_place_of_supply_results">
                                        <button type="button" class="invoice-state-trigger" data-state-trigger>
                                            <span id="modal_place_of_supply_label">Search state</span>
                                        </button>

                                        <div class="invoice-state-menu" data-state-menu>
                                            <input type="text" id="modal_place_of_supply_search" class="invoice-search-input invoice-state-search" placeholder="Search state" data-state-search>
                                            <div id="modal_place_of_supply_results" class="invoice-state-results" data-state-results></div>
                                        </div>
                                    </div>
                                </div>

                                <div>
                                    <label class="invoice-label">GST Number</label>
                                    <input type="text" id="modal_gst_number" class="invoice-input" placeholder="GST number">
                                </div>
                            </div>
                        </div>

                        <div class="invoice-modal-section">
                            <h3>Address</h3>

                            <div style="margin-bottom: 16px;">
                                <label class="invoice-label">Address Line</label>
                                <textarea id="modal_address" class="invoice-textarea" placeholder="Address line"></textarea>
                            </div>

                            <div class="invoice-modal-grid-3">
                                <div>
                                    <label class="invoice-label">City</label>
                                    <input type="text" id="modal_city" class="invoice-input" placeholder="City">
                                </div>

                                <div>
                                    <label class="invoice-label">State</label>
                                    <input type="hidden" id="modal_state" value="">
                                    <div class="invoice-state-picker" data-state-picker data-target-input="modal_state" data-trigger-label="modal_state_label" data-search-input="modal_state_search" data-results-box="modal_state_results">
                                        <button type="button" class="invoice-state-trigger" data-state-trigger>
                                            <span id="modal_state_label">Search state</span>
                                        </button>

                                        <div class="invoice-state-menu" data-state-menu>
                                            <input type="text" id="modal_state_search" class="invoice-search-input invoice-state-search" placeholder="Search state" data-state-search>
                                            <div id="modal_state_results" class="invoice-state-results" data-state-results></div>
                                        </div>
                                    </div>
                                </div>

                                <div>
                                    <label class="invoice-label">Pincode</label>
                                    <input type="text" id="modal_pincode" class="invoice-input" placeholder="Pincode">
                                </div>
                            </div>
                        </div>

                        <div class="invoice-modal-section">
                            <h3>Notes</h3>

                            <textarea id="modal_notes" class="invoice-textarea" placeholder="Optional notes or remarks"></textarea>
                        </div>
                    </div>
                </div>

                <div class="invoice-modal-footer">
                    <button type="button" class="invoice-light-button" id="cancel_customer_modal">Cancel</button>
                    <button type="button" class="invoice-submit-button" id="save_customer_modal">Save Customer</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    const taxOptions = @json($taxOptions);
    const products = @json($productOptions);
    const customersData = @json($customerOptions);
    const indianStates = @json($indianStates);
    const rentalReferences = @json($rentalReferenceOptions);
    const saleReferences = @json($saleReferenceOptions);
    const orgStateName = @json($organization->state);
    const initialItems = @json($initialItems);

    function currency(num) {
        return '\u20B9' + Number(num || 0).toFixed(2);
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function normalizeSourceType(sourceType = '') {
        return String(sourceType || '').trim().toLowerCase();
    }

    function normalizeStateName(value = '') {
        return String(value || '').trim().toLowerCase();
    }

    function productOptionsHtml(selected = '') {
        let html = '<option value="">Free Text / No Linked Product</option>';
        products.forEach(product => {
            const isSelected = String(selected) === String(product.id) ? 'selected' : '';
            html += `<option value="${product.id}" ${isSelected}>${escapeHtml(product.name)}</option>`;
        });
        return html;
    }

    function taxOptionsHtml(selected = 0) {
        let html = '';
        taxOptions.forEach(tax => {
            const isSelected = Number(selected) === Number(tax) ? 'selected' : '';
            html += `<option value="${tax}" ${isSelected}>${tax}%</option>`;
        });
        return html;
    }

    function referenceItemsForType(sourceType = '') {
        const normalizedType = normalizeSourceType(sourceType);

        if (normalizedType === 'rental') {
            return rentalReferences;
        }

        if (normalizedType === 'sale') {
            return saleReferences;
        }

        return [];
    }

    function referenceLabel(reference, type) {
        const refPrefix = type === 'rental' ? 'Rental' : 'Sale';
        const parts = [
            `${refPrefix} #${reference.id}`,
            reference.customer_name,
            reference.product_name,
        ].filter(Boolean);

        return parts.join(' | ');
    }

    function referenceOptionsHtml(sourceType = '', selected = '') {
        const normalizedType = normalizeSourceType(sourceType);
        const references = referenceItemsForType(normalizedType);

        if (!references.length) {
            return '<option value="">No linked record</option>';
        }

        let html = '<option value="">Select reference</option>';
        references.forEach(reference => {
            const isSelected = String(selected) === String(reference.id) ? 'selected' : '';
            html += `<option value="${reference.id}" ${isSelected}>${escapeHtml(referenceLabel(reference, normalizedType))}</option>`;
        });

        return html;
    }

    function rowTemplate(data = {}) {
        const selectedSourceType = normalizeSourceType(data.source_type ?? '');
        const hasReferenceOptions = ['rental', 'sale'].includes(selectedSourceType);

        return `
            <tr class="item-row">
                <td>
                    <div class="invoice-stack" style="gap: 8px; min-width: 190px;">
                        <select name="item_product_id[]" class="invoice-select calc-input" style="min-width: 170px;">
                            ${productOptionsHtml(data.product_id ?? '')}
                        </select>
                        <input type="text" name="item_custom_name[]" value="${data.custom_name ?? ''}" class="invoice-input calc-input item-custom-name" placeholder="Or type custom item name">
                    </div>
                </td>
                <td>
                    <select name="item_source_type[]" class="invoice-select calc-input" style="min-width: 110px;">
                        <option value="">Select</option>
                        <option value="rental" ${(selectedSourceType === 'rental') ? 'selected' : ''}>Rental</option>
                        <option value="sale" ${(selectedSourceType === 'sale') ? 'selected' : ''}>Sale</option>
                        <option value="refill" ${(selectedSourceType === 'refill') ? 'selected' : ''}>Refill</option>
                        <option value="manual" ${(selectedSourceType === 'manual') ? 'selected' : ''}>Manual</option>
                    </select>
                </td>
                <td>
                    <select class="invoice-select item-reference-select" style="min-width: 210px;" ${hasReferenceOptions ? '' : 'disabled'}>
                        ${referenceOptionsHtml(selectedSourceType, data.source_id ?? '')}
                    </select>
                </td>
                <td><input type="number" name="item_source_id[]" value="${data.source_id ?? ''}" class="invoice-input calc-input item-source-id" placeholder="Editable ID" min="1" step="1" style="min-width: 110px;"></td>
                <td><input type="text" name="item_hsn_sac_code[]" value="${data.hsn_sac_code ?? ''}" class="invoice-input calc-input" style="min-width: 110px;"></td>
                <td>
                    <div class="invoice-stack" style="gap:6px; min-width: 260px;">
                        <input type="text" name="item_description[]" value="${data.description ?? ''}" class="invoice-input calc-input item-description" placeholder="Custom item, service, or billing description">
                        ${(data.meta_note ?? '') ? `<div class="invoice-helper-text">${escapeHtml(data.meta_note)}</div>` : ''}
                    </div>
                </td>
                <td><input type="number" step="0.01" name="item_quantity[]" value="${data.quantity ?? 1}" class="invoice-input calc-input item-qty" style="min-width: 75px;"></td>
                <td><input type="text" name="item_unit[]" value="${data.unit ?? ''}" class="invoice-input calc-input" style="min-width: 80px;"></td>
                <td><input type="number" step="0.01" name="item_days[]" value="${data.days ?? ''}" class="invoice-input calc-input" style="min-width: 75px;"></td>
                <td><input type="number" step="0.01" name="item_rate[]" value="${data.rate ?? 0}" class="invoice-input calc-input item-rate" style="min-width: 90px;"></td>
                <td><input type="number" step="0.01" name="item_discount[]" value="${data.discount_amount ?? 0}" class="invoice-input calc-input item-discount" style="min-width: 90px;"></td>
                <td>
                    <select name="item_tax_percentage[]" class="invoice-select calc-input item-tax" style="min-width: 80px;">
                        ${taxOptionsHtml(data.tax_percentage ?? 0)}
                    </select>
                </td>
                <td style="text-align: right;">
                    <strong class="invoice-row-total row-total">&#8377;0.00</strong>
                </td>
                <td style="text-align: center;">
                    <button type="button" onclick="removeRow(this)" class="invoice-remove-button">Remove</button>
                </td>
            </tr>
        `;
    }

    function syncReferenceOptions(row) {
        const typeSelect = row.querySelector('[name="item_source_type[]"]');
        const referenceSelect = row.querySelector('.item-reference-select');
        const sourceIdInput = row.querySelector('.item-source-id');
        const selectedType = normalizeSourceType(typeSelect?.value || '');
        const hasReferenceOptions = ['rental', 'sale'].includes(selectedType);

        if (!referenceSelect) {
            return;
        }

        const currentSourceId = sourceIdInput?.value || '';
        referenceSelect.innerHTML = referenceOptionsHtml(selectedType, currentSourceId);
        referenceSelect.disabled = !hasReferenceOptions;

        if (!hasReferenceOptions) {
            referenceSelect.value = '';
        }
    }

    function applyReferenceToRow(row) {
        const typeSelect = row.querySelector('[name="item_source_type[]"]');
        const referenceSelect = row.querySelector('.item-reference-select');
        const sourceIdInput = row.querySelector('.item-source-id');
        const productSelect = row.querySelector('[name="item_product_id[]"]');
        const descriptionInput = row.querySelector('.item-description');
        const qtyInput = row.querySelector('.item-qty');
        const rateInput = row.querySelector('.item-rate');
        const selectedType = normalizeSourceType(typeSelect?.value || '');
        const references = referenceItemsForType(selectedType);
        const selectedReference = references.find(reference => String(reference.id) === String(referenceSelect?.value || ''));

        if (!selectedReference) {
            return;
        }

        if (sourceIdInput) {
            sourceIdInput.value = selectedReference.id;
        }

        if (productSelect && !productSelect.value && selectedReference.product_id) {
            productSelect.value = selectedReference.product_id;
        }

        if (descriptionInput && !descriptionInput.value.trim()) {
            descriptionInput.value = referenceLabel(selectedReference, selectedType);
        }

        if (qtyInput && (!qtyInput.value || Number(qtyInput.value) === 0) && selectedReference.quantity) {
            qtyInput.value = selectedReference.quantity;
        }

        if (rateInput && (!rateInput.value || Number(rateInput.value) === 0) && selectedReference.rate) {
            rateInput.value = selectedReference.rate;
        }
    }

    function autofillDescriptionFromProduct(row) {
        const productSelect = row.querySelector('[name="item_product_id[]"]');
        const descriptionInput = row.querySelector('.item-description');
        const selectedProduct = products.find(product => String(product.id) === String(productSelect?.value || ''));

        if (selectedProduct && descriptionInput && !descriptionInput.value.trim()) {
            descriptionInput.value = selectedProduct.name;
        }
    }

    function autofillDescriptionFromCustomName(row) {
        const customNameInput = row.querySelector('.item-custom-name');
        const descriptionInput = row.querySelector('.item-description');

        if (customNameInput && descriptionInput && !descriptionInput.value.trim()) {
            descriptionInput.value = customNameInput.value.trim();
        }
    }

    function addRow(data = {}) {
        document.getElementById('itemsBody').insertAdjacentHTML('beforeend', rowTemplate(data));
        const row = document.querySelector('#itemsBody .item-row:last-child');
        syncReferenceOptions(row);
        calculateTotals();
    }

    function removeRow(button) {
        const rows = document.querySelectorAll('#itemsBody .item-row');
        if (rows.length <= 1) {
            alert('At least one row should remain.');
            return;
        }

        button.closest('tr').remove();
        calculateTotals();
    }

    function renderStateOptions(resultsBox, selectedValue = '', searchTerm = '') {
        if (!resultsBox) {
            return;
        }

        const normalizedSearch = normalizeStateName(searchTerm);
        const filteredStates = indianStates.filter((stateName) => {
            if (!normalizedSearch) {
                return true;
            }

            return normalizeStateName(stateName).includes(normalizedSearch);
        });

        if (!filteredStates.length) {
            resultsBox.innerHTML = '<div class="invoice-customer-empty">No matching states found.</div>';
            return;
        }

        resultsBox.innerHTML = filteredStates.map((stateName) => {
            const selectedClass = normalizeStateName(selectedValue) === normalizeStateName(stateName) ? ' style="border-color:#0f172a;background:#f8fbff;"' : '';
            return `<button type="button" class="invoice-state-option" data-state-value="${escapeHtml(stateName)}"${selectedClass}>${escapeHtml(stateName)}</button>`;
        }).join('');
    }

    function calculateTotals() {
        let subtotal = 0;
        let discount = 0;
        let taxable = 0;
        let cgst = 0;
        let sgst = 0;
        let igst = 0;

        const placeOfSupplyState = document.getElementById('place_of_supply_state')?.value || '';
        const intraState = normalizeStateName(placeOfSupplyState) !== '' && normalizeStateName(placeOfSupplyState) === normalizeStateName(orgStateName);
        const taxModeBadge = document.getElementById('tax_mode_badge');
        const taxCalculationMode = document.getElementById('tax_calculation_mode')?.value || 'exclusive';
        const taxCalculationModeBadge = document.getElementById('tax_calculation_mode_badge');

        if (taxModeBadge) {
            taxModeBadge.textContent = intraState ? 'CGST / SGST' : 'IGST';
        }

        if (taxCalculationModeBadge) {
            taxCalculationModeBadge.textContent = taxCalculationMode === 'inclusive' ? 'Inclusive' : 'Exclusive';
        }

        document.querySelectorAll('#itemsBody .item-row').forEach(row => {
            const qty = parseFloat(row.querySelector('.item-qty')?.value || 0);
            const rate = parseFloat(row.querySelector('.item-rate')?.value || 0);
            const disc = parseFloat(row.querySelector('.item-discount')?.value || 0);
            const taxPercent = parseFloat(row.querySelector('.item-tax')?.value || 0);

            const baseAmount = qty * rate;
            const effectiveAmount = Math.max(baseAmount - disc, 0);
            const taxableAmount = taxCalculationMode === 'inclusive' && taxPercent > 0
                ? effectiveAmount / (1 + (taxPercent / 100))
                : effectiveAmount;

            let lineCgst = 0;
            let lineSgst = 0;
            let lineIgst = 0;

            if (intraState) {
                lineCgst = taxableAmount * ((taxPercent / 2) / 100);
                lineSgst = taxableAmount * ((taxPercent / 2) / 100);
            } else {
                lineIgst = taxableAmount * (taxPercent / 100);
            }

            const lineTotal = taxCalculationMode === 'inclusive'
                ? effectiveAmount
                : (taxableAmount + lineCgst + lineSgst + lineIgst);

            subtotal += baseAmount;
            discount += disc;
            taxable += taxableAmount;
            cgst += lineCgst;
            sgst += lineSgst;
            igst += lineIgst;

            row.querySelector('.row-total').textContent = currency(lineTotal);
        });

        const deposit = parseFloat(document.getElementById('deposit_amount')?.value || 0);
        const shipping = parseFloat(document.getElementById('shipping_charges')?.value || 0);
        const paid = parseFloat(document.getElementById('paid_amount')?.value || 0);
        const totalTax = cgst + sgst + igst;
        const total = taxable + totalTax + deposit + shipping;
        const balance = Math.max(total - paid, 0);

        document.getElementById('summary_subtotal').textContent = currency(subtotal);
        document.getElementById('summary_discount').textContent = currency(discount);
        document.getElementById('summary_taxable').textContent = currency(taxable);
        document.getElementById('summary_cgst').textContent = currency(cgst);
        document.getElementById('summary_sgst').textContent = currency(sgst);
        document.getElementById('summary_igst').textContent = currency(igst);
        document.getElementById('summary_deposit').textContent = currency(deposit);
        document.getElementById('summary_shipping').textContent = currency(shipping);
        document.getElementById('summary_total').textContent = currency(total);
        document.getElementById('summary_paid').textContent = currency(paid);
        document.getElementById('summary_balance').textContent = currency(balance);
    }

    document.addEventListener('input', function (e) {
        if (e.target.classList.contains('calc-input') || ['deposit_amount', 'shipping_charges', 'paid_amount', 'place_of_supply_state', 'tax_calculation_mode'].includes(e.target.id)) {
            calculateTotals();
        }
    });

    document.addEventListener('change', function (e) {
        if (e.target.classList.contains('calc-input') || ['deposit_amount', 'shipping_charges', 'paid_amount', 'place_of_supply_state', 'tax_calculation_mode'].includes(e.target.id)) {
            calculateTotals();
        }

        if (e.target.matches('[name="item_source_type[]"]')) {
            const row = e.target.closest('.item-row');
            syncReferenceOptions(row);
        }

        if (e.target.matches('.item-reference-select')) {
            const row = e.target.closest('.item-row');
            applyReferenceToRow(row);
            calculateTotals();
        }

        if (e.target.matches('[name="item_product_id[]"]')) {
            const row = e.target.closest('.item-row');
            autofillDescriptionFromProduct(row);
        }

        if (e.target.matches('[name="item_custom_name[]"]')) {
            const row = e.target.closest('.item-row');
            autofillDescriptionFromCustomName(row);
        }
    });

    document.addEventListener('DOMContentLoaded', function () {
        const sameAsBillCheckbox = document.getElementById('ship_same_as_bill');
        const customerIdInput = document.getElementById('customer_id');
        const customerSearchInput = document.getElementById('customer_search');
        const customerPicker = document.getElementById('customer_picker');
        const customerPickerTrigger = document.getElementById('customer_picker_trigger');
        const customerPickerMenu = document.getElementById('customer_picker_menu');
        const customerResults = document.getElementById('customer_results');
        const customerPickerLabel = document.getElementById('customer_picker_label');
        const customerPickerHint = document.getElementById('customer_picker_hint');
        const fillCustomerButton = document.getElementById('fill_customer_details');
        const openCustomerModalButton = document.getElementById('open_customer_modal');
        const customerModal = document.getElementById('customer_modal');
        const closeCustomerModalButton = document.getElementById('close_customer_modal');
        const cancelCustomerModalButton = document.getElementById('cancel_customer_modal');
        const saveCustomerModalButton = document.getElementById('save_customer_modal');
        const customerModalErrors = document.getElementById('customer_modal_errors');
        const statePickers = document.querySelectorAll('[data-state-picker]');

        function copyBillToShipTo() {
            const fieldMap = {
                'bill_to_name': 'ship_to_name',
                'bill_to_phone': 'ship_to_phone',
                'bill_to_phone_country_code': 'ship_to_phone_country_code',
                'bill_to_address': 'ship_to_address',
                'bill_to_city': 'ship_to_city',
                'bill_to_state': 'ship_to_state',
                'bill_to_state_code': 'ship_to_state_code',
                'bill_to_pincode': 'ship_to_pincode',
            };

            Object.entries(fieldMap).forEach(([sourceName, targetName]) => {
                const source = document.querySelector(`[name="${sourceName}"]`);
                const target = document.querySelector(`[name="${targetName}"]`);

                if (source && target) {
                    target.value = source.value;
                }
            });
        }

        function customerDisplayName(customer) {
            return customer?.company_name || customer?.name || [customer?.first_name, customer?.last_name].filter(Boolean).join(' ') || '';
        }

        function customerDisplayLabel(customer) {
            return [customerDisplayName(customer), customer?.phone ? '- ' + customer.phone : null].filter(Boolean).join(' ');
        }

        function customerSearchHaystack(customer) {
            return [
                customer?.name,
                customer?.phone,
                customer?.company_name,
                customer?.first_name,
                customer?.last_name,
                customer?.email,
            ].filter(Boolean).join(' ').toLowerCase();
        }

        function setFieldValue(fieldName, value, force = false) {
            const field = document.querySelector(`[name="${fieldName}"]`);

            if (!field) {
                return;
            }

            if (force || !String(field.value || '').trim()) {
                field.value = value || '';
            }
        }

        function toLocalPhone(value) {
            const digits = String(value || '').replace(/\D+/g, '');
            const code = toCountryCode(value).replace(/\D+/g, '');

            if (code && digits.startsWith(code)) {
                return digits.slice(code.length, code.length + 15);
            }

            if (digits.length > 1 && digits.startsWith('0')) {
                return digits.replace(/^0+/, '').slice(0, 15);
            }

            return digits.slice(0, 15);
        }

        function toCountryCode(value) {
            const digits = String(value || '').replace(/\D+/g, '');
            const options = Array.from(new Set(
                Array.from(document.querySelectorAll('[data-country-code-option]')).map(option => option.dataset.value)
            ));

            for (const code of options) {
                const numeric = code.replace(/\D+/g, '');
                if (digits.startsWith(numeric)) {
                    return code;
                }
            }

            return '+91';
        }

        function selectedCustomer() {
            const selectedValue = customerIdInput?.value;
            return customersData.find(customer => String(customer.id) === String(selectedValue || '')) || null;
        }

        function updateCustomerTrigger(customer = null) {
            if (!customerPickerLabel || !customerPickerHint) {
                return;
            }

            if (!customer) {
                customerPickerLabel.textContent = 'Search customer';
                customerPickerHint.textContent = 'Search customer';
                return;
            }

            customerPickerLabel.textContent = customerDisplayLabel(customer) || 'Search customer';
            customerPickerHint.textContent = [customer.email, customer.city, customer.state].filter(Boolean).join(' | ') || 'Customer selected';
        }

        function openCustomerPicker() {
            customerPickerMenu?.classList.add('is-open');
            window.setTimeout(() => customerSearchInput?.focus(), 0);
        }

        function closeCustomerPicker() {
            customerPickerMenu?.classList.remove('is-open');
        }

        function renderCustomerResults(searchTerm = '') {
            if (!customerResults) {
                return;
            }

            const normalizedSearch = String(searchTerm || '').trim().toLowerCase();
            const filteredCustomers = customersData.filter(customer => {
                if (!normalizedSearch) {
                    return true;
                }

                return customerSearchHaystack(customer).includes(normalizedSearch);
            });

            let html = '';

            if (!filteredCustomers.length) {
                html += '<div class="invoice-customer-empty">No matching customers found.</div>';
            } else {
                filteredCustomers.forEach(customer => {
                    const subtitle = [customer.company_name, customer.email, customer.city, customer.state].filter(Boolean).join(' | ');
                    html += `
                        <button type="button" class="invoice-customer-option" data-customer-id="${customer.id}">
                            <strong>${escapeHtml(customerDisplayLabel(customer) || customerDisplayName(customer) || 'Customer')}</strong>
                            <span>${escapeHtml(subtitle || 'Click to select this customer')}</span>
                        </button>
                    `;
                });
            }

            html += `
                <button type="button" class="invoice-customer-create" id="customer_results_new">
                    <strong>+ New Customer</strong>
                    <span>Create a customer without leaving this invoice</span>
                </button>
            `;

            customerResults.innerHTML = html;
        }

        function setSelectedCustomer(customer, autoFill = true) {
            if (!customerIdInput) {
                return;
            }

            customerIdInput.value = customer?.id || '';
            updateCustomerTrigger(customer);
            closeCustomerPicker();

            if (customerSearchInput) {
                customerSearchInput.value = '';
            }

            if (customer && autoFill) {
                applyCustomerToInvoice(true);
            }
        }

        function applyCustomerToInvoice(force = false) {
            const customer = selectedCustomer();

            if (!customer) {
                return;
            }

            setFieldValue('bill_to_name', customerDisplayName(customer), force);
            setFieldValue('bill_to_phone', toLocalPhone(customer.phone), force);
            setFieldValue('bill_to_phone_country_code', toCountryCode(customer.phone), force);
            setFieldValue('bill_to_email', customer.email, force);
            setFieldValue('bill_to_address', customer.address, force);
            setFieldValue('bill_to_city', customer.city, force);
            setFieldValue('bill_to_state', customer.state || customer.place_of_supply, force);
            setFieldValue('bill_to_pincode', customer.pincode, force);
            setFieldValue('bill_to_gstin', customer.gst_number, force);

            const placeOfSupplyStateField = document.querySelector('[name="place_of_supply_state"]');
            if (placeOfSupplyStateField && (force || !String(placeOfSupplyStateField.value || '').trim())) {
                placeOfSupplyStateField.value = customer.place_of_supply || customer.state || '';
                const placeOfSupplyLabel = document.getElementById('place_of_supply_label');
                if (placeOfSupplyLabel) {
                    placeOfSupplyLabel.textContent = placeOfSupplyStateField.value || 'Search state';
                }
                calculateTotals();
            }

            if (sameAsBillCheckbox?.checked) {
                copyBillToShipTo();
            }
        }

        function openCustomerModal() {
            customerModal?.classList.add('is-open');
            customerModalErrors?.classList.remove('is-visible');
            if (customerModalErrors) {
                customerModalErrors.innerHTML = '';
            }
            window.rentnexisModalLock?.lock();
            const firstField = document.getElementById('modal_customer_type');
            const modalBody = customerModal?.querySelector('.invoice-modal-body');
            window.requestAnimationFrame(() => {
                if (firstField) {
                    firstField.focus({ preventScroll: true });
                    window.rentnexisModalScrollFieldIntoView?.(firstField, modalBody);
                }
            });
        }

        function closeCustomerModal() {
            customerModal?.classList.remove('is-open');
            window.rentnexisModalLock?.unlock();
        }

        function modalFieldValue(id) {
            return document.getElementById(id)?.value || '';
        }

        function resetCustomerModal() {
            [
                'modal_customer_type',
                'modal_first_name',
                'modal_last_name',
                'modal_company_name',
                'modal_phone',
                'modal_email',
                'modal_gst_treatment',
                'modal_place_of_supply',
                'modal_gst_number',
                'modal_address',
                'modal_city',
                'modal_state',
                'modal_pincode',
                'modal_patient_name',
                'modal_notes',
            ].forEach((id) => {
                const field = document.getElementById(id);
                if (!field) {
                    return;
                }

                field.value = id === 'modal_customer_type' ? 'Business' : '';
            });

            ['modal_place_of_supply_label', 'modal_state_label'].forEach((id) => {
                const label = document.getElementById(id);
                if (label) {
                    label.textContent = 'Search state';
                }
            });
        }

        document.querySelectorAll('[data-phone-local]').forEach(function (input) {
            input.addEventListener('input', function () {
                input.value = input.value.replace(/\D+/g, '').slice(0, 15);
            });
        });

        function showCustomerModalErrors(errors = {}) {
            if (!customerModalErrors) {
                return;
            }

            const messages = Object.values(errors).flat();
            if (!messages.length) {
                customerModalErrors.classList.remove('is-visible');
                customerModalErrors.innerHTML = '';
                return;
            }

            customerModalErrors.innerHTML = `<ul>${messages.map(message => `<li>${escapeHtml(message)}</li>`).join('')}</ul>`;
            customerModalErrors.classList.add('is-visible');
        }

        if (sameAsBillCheckbox) {
            sameAsBillCheckbox.addEventListener('change', function () {
                if (this.checked) {
                    copyBillToShipTo();
                }
            });

            ['bill_to_name', 'bill_to_phone', 'bill_to_address', 'bill_to_city', 'bill_to_state', 'bill_to_state_code', 'bill_to_pincode'].forEach((fieldName) => {
                const source = document.querySelector(`[name="${fieldName}"]`);

                if (source) {
                    source.addEventListener('input', function () {
                        if (sameAsBillCheckbox.checked) {
                            copyBillToShipTo();
                        }
                    });
                }
            });
        }

        if (customerPickerTrigger) {
            customerPickerTrigger.addEventListener('click', function () {
                if (customerPickerMenu?.classList.contains('is-open')) {
                    closeCustomerPicker();
                } else {
                    renderCustomerResults(customerSearchInput?.value || '');
                    openCustomerPicker();
                }
            });
        }

        if (customerSearchInput) {
            customerSearchInput.addEventListener('input', function () {
                renderCustomerResults(this.value);
            });
        }

        if (customerResults) {
            customerResults.addEventListener('click', function (event) {
                const optionButton = event.target.closest('.invoice-customer-option');
                const createButton = event.target.closest('#customer_results_new');

                if (optionButton) {
                    const customer = customersData.find(item => String(item.id) === String(optionButton.dataset.customerId));
                    if (customer) {
                        setSelectedCustomer(customer, true);
                    }
                }

                if (createButton) {
                    closeCustomerPicker();
                    openCustomerModal();
                }
            });
        }

        if (fillCustomerButton) {
            fillCustomerButton.addEventListener('click', function () {
                applyCustomerToInvoice(true);
            });
        }

        if (openCustomerModalButton) {
            openCustomerModalButton.addEventListener('click', function () {
                openCustomerModal();
            });
        }

        [closeCustomerModalButton, cancelCustomerModalButton].forEach((button) => {
            if (button) {
                button.addEventListener('click', function () {
                    closeCustomerModal();
                });
            }
        });

        if (customerModal) {
            customerModal.addEventListener('click', function (event) {
                if (event.target === customerModal) {
                    closeCustomerModal();
                }
            });

            customerModal.addEventListener('focusin', function (event) {
                const target = event.target;
                const modalBody = customerModal.querySelector('.invoice-modal-body');

                if (target instanceof HTMLElement && target.matches('input, select, textarea')) {
                    window.rentnexisModalScrollFieldIntoView?.(target, modalBody);
                }
            });
        }

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && customerModal?.classList.contains('is-open')) {
                closeCustomerModal();
            }
        });

        document.addEventListener('click', function (event) {
            if (customerPicker && !customerPicker.contains(event.target)) {
                closeCustomerPicker();
            }

            statePickers.forEach((picker) => {
                if (!picker.contains(event.target)) {
                    picker.querySelector('[data-state-menu]')?.classList.remove('is-open');
                }
            });
        });

        statePickers.forEach((picker) => {
            const targetInputId = picker.dataset.targetInput;
            const targetInput = document.getElementById(targetInputId);
            const trigger = picker.querySelector('[data-state-trigger]');
            const menu = picker.querySelector('[data-state-menu]');
            const searchInput = picker.querySelector('[data-state-search]');
            const resultsBox = picker.querySelector('[data-state-results]');
            const label = document.getElementById(picker.dataset.triggerLabel);

            const syncLabel = () => {
                if (label) {
                    label.textContent = targetInput?.value || 'Search state';
                }
            };

            renderStateOptions(resultsBox, targetInput?.value || '', '');
            syncLabel();

            trigger?.addEventListener('click', function () {
                const isOpen = menu?.classList.contains('is-open');
                statePickers.forEach((otherPicker) => {
                    if (otherPicker !== picker) {
                        otherPicker.querySelector('[data-state-menu]')?.classList.remove('is-open');
                    }
                });
                if (isOpen) {
                    menu?.classList.remove('is-open');
                } else {
                    menu?.classList.add('is-open');
                    renderStateOptions(resultsBox, targetInput?.value || '', searchInput?.value || '');
                    window.setTimeout(() => searchInput?.focus(), 0);
                }
            });

            searchInput?.addEventListener('input', function () {
                renderStateOptions(resultsBox, targetInput?.value || '', this.value);
            });

            resultsBox?.addEventListener('click', function (event) {
                const option = event.target.closest('.invoice-state-option');
                if (!option || !targetInput) {
                    return;
                }

                targetInput.value = option.dataset.stateValue || '';
                syncLabel();
                menu?.classList.remove('is-open');

                if (searchInput) {
                    searchInput.value = '';
                }

                if (targetInput.id === 'place_of_supply_state') {
                    calculateTotals();
                }
            });
        });

        if (saveCustomerModalButton) {
            saveCustomerModalButton.addEventListener('click', async function () {
                const payload = {
                    customer_type: modalFieldValue('modal_customer_type'),
                    first_name: modalFieldValue('modal_first_name'),
                    last_name: modalFieldValue('modal_last_name'),
                    company_name: modalFieldValue('modal_company_name'),
                    phone: modalFieldValue('modal_phone'),
                    phone_country_code: modalFieldValue('modal_phone_country_code'),
                    email: modalFieldValue('modal_email'),
                    gst_treatment: modalFieldValue('modal_gst_treatment'),
                    place_of_supply: modalFieldValue('modal_place_of_supply'),
                    gst_number: modalFieldValue('modal_gst_number'),
                    address: modalFieldValue('modal_address'),
                    city: modalFieldValue('modal_city'),
                    state: modalFieldValue('modal_state'),
                    pincode: modalFieldValue('modal_pincode'),
                    patient_name: modalFieldValue('modal_patient_name'),
                    notes: modalFieldValue('modal_notes'),
                };
                showCustomerModalErrors({});

                try {
                    const response = await fetch(@json(route('invoices.customers.quick-store')), {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('#invoiceForm input[name="_token"]')?.value || '',
                        },
                        body: JSON.stringify(payload),
                    });

                    const result = await response.json();

                    if (!response.ok) {
                        showCustomerModalErrors(result.errors || {
                            general: [result.message || 'Unable to create customer.'],
                        });
                        return;
                    }

                    customersData.push(result.customer);
                    customersData.sort((a, b) => String(a.name || '').localeCompare(String(b.name || '')));
                    setSelectedCustomer(result.customer, true);
                    resetCustomerModal();
                    closeCustomerModal();
                } catch (error) {
                    showCustomerModalErrors({
                        general: ['Unable to create customer right now.'],
                    });
                }
            });
        }

        if (initialItems.length) {
            initialItems.forEach(item => addRow(item));
        } else {
            addRow();
        }

        const currentCustomer = selectedCustomer();
        updateCustomerTrigger(currentCustomer);
        renderCustomerResults('');
        calculateTotals();
    });
</script>
