@php
    $isEdit = $asset->exists;
    $selectedStage = old('asset_stage', $asset->asset_stage ?: \App\Models\Asset::STAGE_RENTAL_STOCK);
    $selectedStatus = old('asset_status', $asset->asset_status ?: ($selectedStage === \App\Models\Asset::STAGE_NEW_STOCK ? 'available_for_sale' : 'available'));
    $selectedCondition = old('condition_status', $asset->condition_status ?: 'good');
    $workflowControl = $workflowControl ?? ['locked' => false, 'locks_condition' => false, 'message' => null, 'action_label' => null, 'action_url' => null, 'convert_url' => null];
    $isWorkflowLocked = (bool) ($workflowControl['locked'] ?? false);
    $serialPendingEnabled = (bool) ($serialPendingEnabled ?? old('serial_pending', false));
    $fieldStyle = fn (string $field, string $base) => $base . ($errors->has($field)
        ? ' border-color:#dc2626; box-shadow:0 0 0 3px rgba(220, 38, 38, 0.12); background:#fff7f7;'
        : '');
    $fieldError = fn (string $field) => $errors->first($field);
    $productOptionLabel = function ($product) {
        $secondary = trim(collect([$product->brand, $product->model_name])->filter()->implode(' '));

        if ($secondary === '') {
            $secondary = trim((string) ($product->product_code ?: $product->sku ?: 'No model assigned'));
        }

        return $product->name . ' - ' . $secondary;
    };
@endphp

<style>
    .asset-form-page {
        max-width: 1080px;
        margin: 0 auto;
    }
    .asset-form-header {
        display:flex;
        justify-content:space-between;
        align-items:flex-start;
        gap:16px;
        margin-bottom:24px;
        flex-wrap:wrap;
    }
    .asset-stage-grid {
        display:grid;
        grid-template-columns:repeat(2, minmax(0, 1fr));
        gap:18px;
    }
    .asset-main-grid {
        display:grid;
        grid-template-columns:minmax(0, 1.05fr) minmax(0, 0.95fr);
        gap:20px;
        align-items:start;
    }
    .asset-main-column {
        display:grid;
        gap:20px;
    }
    .asset-card {
        background:#ffffff;
        border:1px solid #e2e8f0;
        border-radius:22px;
        padding:24px;
    }
    .asset-field-grid {
        display:grid;
        grid-template-columns:repeat(2, minmax(0, 1fr));
        gap:18px;
    }
    .asset-form-actions {
        display:flex;
        justify-content:flex-end;
        gap:12px;
    }
    .asset-guidance-grid {
        display:grid;
        grid-template-columns:repeat(3, minmax(0, 1fr));
        gap:12px;
        margin-bottom:18px;
    }
    @media (max-width: 767px) {
        .asset-form-page {
            max-width:100%;
        }
        .asset-form-header {
            gap:12px;
            margin-bottom:16px;
        }
        .asset-form-header h1 {
            margin:10px 0 6px !important;
            font-size:26px !important;
        }
        .asset-form-header p {
            font-size:12px !important;
            line-height:1.45 !important;
            max-width:none !important;
        }
        .asset-card {
            border-radius:16px;
            padding:16px;
        }
        .asset-stage-grid,
        .asset-main-grid,
        .asset-field-grid {
            grid-template-columns:1fr !important;
            gap:14px !important;
        }
        .asset-main-column {
            gap:14px;
        }
        .asset-stage-option {
            padding:14px !important;
            border-radius:16px !important;
        }
        .asset-stage-option input {
            margin-top:2px !important;
        }
        .asset-stage-option-title {
            font-size:14px !important;
            line-height:1.35 !important;
        }
        .asset-stage-option-copy {
            font-size:12px !important;
            line-height:1.5 !important;
            margin-top:4px !important;
        }
        .asset-form-actions {
            display:grid;
            grid-template-columns:1fr;
        }
        .asset-form-actions a,
        .asset-form-actions button {
            width:100%;
            min-height:44px;
        }
        .asset-guidance-grid {
            grid-template-columns:1fr;
            gap:10px;
            margin-bottom:14px;
        }
    }
</style>

<div class="asset-form-page">
    <div class="asset-form-header">
        <div>
            <div style="display:inline-flex; padding:6px 10px; border-radius:999px; background:#eff6ff; color:#1d4ed8; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.08em;">Inventory Unit</div>
            <h1 style="margin:12px 0 8px; font-size:32px; letter-spacing:-0.03em;">{{ $isEdit ? 'Edit Asset' : 'Add Asset' }}</h1>
            <p style="margin:0; color:#64748b; max-width:760px;">One asset form creates one physical unit. Use Product Master for catalog and pricing, then use Asset Register for serials, barcodes, warehouse placement, and lifecycle status.</p>
        </div>
        <a href="{{ route('assets.index') }}" style="display:inline-flex; align-items:center; justify-content:center; padding:10px 14px; border-radius:12px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a; text-decoration:none; font-weight:600;">
            Back to Asset Register
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

    @if(!empty($prefillLookup))
        <div style="margin-bottom:18px; padding:14px 16px; border-radius:16px; background:#eff6ff; border:1px solid #bfdbfe; color:#1d4ed8;">
            Prefilled from search: <strong>{{ $prefillLookup }}</strong>. You can keep it as the serial number or move it to barcode if needed.
        </div>
    @endif

    <div class="asset-guidance-grid">
        <div style="padding:14px 16px; border-radius:18px; background:#ffffff; border:1px solid #e2e8f0;">
            <div style="font-size:11px; color:#64748b; font-weight:800; text-transform:uppercase; letter-spacing:.08em;">Product Master</div>
            <div style="margin-top:6px; color:#0f172a; font-weight:700;">Catalog and pricing live there.</div>
        </div>
        <div style="padding:14px 16px; border-radius:18px; background:#ffffff; border:1px solid #e2e8f0;">
            <div style="font-size:11px; color:#64748b; font-weight:800; text-transform:uppercase; letter-spacing:.08em;">Asset Register</div>
            <div style="margin-top:6px; color:#0f172a; font-weight:700;">Physical units, serials, barcodes, and warehouse tracking live here.</div>
        </div>
        <div style="padding:14px 16px; border-radius:18px; background:#ffffff; border:1px solid #e2e8f0;">
            <div style="font-size:11px; color:#64748b; font-weight:800; text-transform:uppercase; letter-spacing:.08em;">Add Stock</div>
            <div style="margin-top:6px; color:#0f172a; font-weight:700;">Create one physical unit at a time. Use one asset per real item for tracked stock.</div>
        </div>
    </div>

    <form method="POST" action="{{ $isEdit ? route('assets.update', $asset) : route('assets.store') }}" style="display:grid; gap:20px;">
        @csrf
        @if($isEdit)
            @method('PUT')
        @endif
        @if($isEdit && $isWorkflowLocked)
            <input type="hidden" name="asset_stage" value="{{ old('asset_stage', $asset->asset_stage) }}">
            <input type="hidden" name="asset_status" value="{{ old('asset_status', $asset->asset_status) }}">
            @if($workflowControl['locks_condition'] ?? false)
                <input type="hidden" name="condition_status" value="{{ old('condition_status', $asset->condition_status) }}">
            @endif
        @endif

        <div class="asset-card">
            <div style="display:flex; justify-content:space-between; gap:16px; align-items:flex-start; flex-wrap:wrap; margin-bottom:18px;">
                <div>
                    <h2 style="margin:0; font-size:22px;">Asset Type / Stage</h2>
                    <p style="margin:8px 0 0; color:#64748b;">Choose whether this one unit should start as a sale unit or as a rental asset.</p>
                </div>
                <div id="asset-stage-badge" style="display:inline-flex; padding:8px 12px; border-radius:999px; background:#eff6ff; color:#1d4ed8; font-size:12px; font-weight:700;">
                    Rental Asset workflow
                </div>
            </div>

            <div class="asset-stage-grid">
                <label class="asset-stage-option" style="{{ $fieldStyle('asset_stage', 'display:flex; gap:12px; align-items:flex-start; padding:18px; border-radius:18px; border:1px solid #fed7aa; background:#fffaf0; cursor:pointer;') }}">
                    <input type="radio" name="asset_stage" value="{{ \App\Models\Asset::STAGE_NEW_STOCK }}" @checked($selectedStage === \App\Models\Asset::STAGE_NEW_STOCK) @disabled($isEdit && $isWorkflowLocked) style="margin-top:4px;">
                    <span>
                        <span class="asset-stage-option-title" style="display:block; font-size:15px; font-weight:800; color:#9a3412;">Sale Unit</span>
                        <span class="asset-stage-option-copy" style="display:block; margin-top:6px; color:#9a3412; font-size:13px;">Fresh physical unit available for sale now and possible future conversion to rental assets.</span>
                    </span>
                </label>
                <label class="asset-stage-option" style="{{ $fieldStyle('asset_stage', 'display:flex; gap:12px; align-items:flex-start; padding:18px; border-radius:18px; border:1px solid #bfdbfe; background:#eff6ff; cursor:pointer;') }}">
                    <input type="radio" name="asset_stage" value="{{ \App\Models\Asset::STAGE_RENTAL_STOCK }}" @checked($selectedStage === \App\Models\Asset::STAGE_RENTAL_STOCK) @disabled($isEdit && $isWorkflowLocked) style="margin-top:4px;">
                    <span>
                        <span class="asset-stage-option-title" style="display:block; font-size:15px; font-weight:800; color:#1d4ed8;">Rental Asset</span>
                        <span class="asset-stage-option-copy" style="display:block; margin-top:6px; color:#1d4ed8; font-size:13px;">Unit already intended for rental operations, dispatch, pickup, service, and lifecycle tracking.</span>
                    </span>
                </label>
            </div>
        </div>

        <div class="asset-main-grid">
            <div class="asset-main-column">
                <div class="asset-card">
                    <h2 style="margin:0; font-size:22px;">Asset Identity</h2>
                    <p style="margin:8px 0 18px; color:#64748b;">Link this unit to a product, then capture the serial and barcode details that identify this physical item.</p>

                    <div class="asset-field-grid">
                        <div>
                            <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Product</label>
                            <select name="product_id" required style="{{ $fieldStyle('product_id', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff;') }}">
                                <option value="">Select product</option>
                                @foreach($products as $product)
                                    <option value="{{ $product->id }}" @selected(old('product_id', $asset->product_id) == $product->id)>{{ $productOptionLabel($product) }}</option>
                                @endforeach
                            </select>
                            @if($fieldError('product_id'))
                                <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('product_id') }}</div>
                            @endif
                            @if($asset->product)
                                @php
                                    $currentSecondary = trim(collect([$asset->product->brand, $asset->product->model_name])->filter()->implode(' '));
                                    if ($currentSecondary === '') {
                                        $currentSecondary = trim((string) ($asset->product->product_code ?: $asset->product->sku ?: 'No model assigned'));
                                    }
                                @endphp
                                <div style="margin-top:6px; color:#64748b; font-size:12px;">
                                    Linked product: <strong style="color:#0f172a;">{{ $asset->product->name }}</strong>
                                    <span style="color:#475569;">/ {{ $currentSecondary }}</span>
                                </div>
                            @endif
                        </div>

                        <div>
                            <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Warehouse</label>
                            <select name="warehouse_id" required style="{{ $fieldStyle('warehouse_id', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff;') }}">
                                <option value="">Select warehouse</option>
                                @foreach($warehouses as $warehouse)
                                    <option value="{{ $warehouse->id }}" @selected(old('warehouse_id', $asset->warehouse_id) == $warehouse->id)>{{ $warehouse->name }}</option>
                                @endforeach
                            </select>
                            @if($fieldError('warehouse_id'))
                                <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('warehouse_id') }}</div>
                            @endif
                        </div>

                        <div>
                            <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Asset Name</label>
                            <input type="text" name="asset_name" value="{{ old('asset_name', $asset->asset_name) }}"
                                   style="{{ $fieldStyle('asset_name', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff;') }}">
                            @if($fieldError('asset_name'))
                                <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('asset_name') }}</div>
                            @endif
                        </div>

                        <div>
                            <label id="asset-serial-label" style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Serial Number</label>
                            <input type="text" name="serial_number" id="asset_serial_number" value="{{ old('serial_number', $asset->serial_number) }}" @if(!$serialPendingEnabled) required @endif autofocus autocomplete="off" spellcheck="false"
                                   style="{{ $fieldStyle('serial_number', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff;') }}">
                            @if($fieldError('serial_number'))
                                <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('serial_number') }}</div>
                            @endif
                            @if(!$isEdit)
                                <label style="display:flex; gap:10px; align-items:flex-start; margin-top:10px; color:#334155; font-size:12px;">
                                    <input type="checkbox" name="serial_pending" id="asset_serial_pending" value="1" @checked($serialPendingEnabled) style="margin-top:2px;">
                                    <span><strong>Serial currently unavailable</strong> and will be updated later.</span>
                                </label>
                            @endif
                            <div id="asset-serial-helper" style="margin-top:6px; color:#64748b; font-size:12px;">Recommended for every tracked unit. Serial can be added later during Return Verification if currently unavailable.</div>
                        </div>

                        <div style="grid-column:1 / -1;">
                            <label id="asset-barcode-label" style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Barcode Value</label>
                            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                                <input type="text" name="barcode_value" id="asset_barcode_value" value="{{ old('barcode_value', $asset->barcode_value) }}" autocomplete="off" spellcheck="false"
                                       style="{{ $fieldStyle('barcode_value', 'flex:1; min-width:220px; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff;') }}">
                                <input type="file" id="assetBarcodeCameraInput" accept="image/*" capture="environment" style="display:none;">
                                <button type="button" id="assetBarcodeCameraButton" style="display:inline-flex; align-items:center; justify-content:center; padding:0 16px; min-height:46px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff; color:#0f172a; font-weight:700; cursor:pointer;">
                                    Scan by Camera
                                </button>
                            </div>
                            @if($fieldError('barcode_value'))
                                <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('barcode_value') }}</div>
                            @endif
                            <div id="asset-barcode-helper" style="margin-top:6px; color:#64748b; font-size:12px;">Optional but recommended for new stock and high-value tracked items. Barcode can be updated later if labels are applied afterward.</div>
                        </div>
                    </div>
                </div>

                <div class="asset-card">
                    <h2 style="margin:0; font-size:22px;">Stage-Specific Status</h2>
                    <p id="asset-status-helper" style="margin:8px 0 18px; color:#64748b;">Choose the operational status only when this unit is not already controlled by sale or rental workflow.</p>

                    @if($isEdit && $isWorkflowLocked)
                        <div style="display:grid; gap:16px;">
                            <div style="display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:16px;">
                                <div style="padding:14px 16px; border-radius:16px; border:1px solid #e2e8f0; background:#f8fafc;">
                                    <div style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase;">Status</div>
                                    <div style="margin-top:8px;">
                                        <span style="display:inline-flex; padding:7px 12px; border-radius:999px; background:#eff6ff; color:#0f172a; font-size:12px; font-weight:800; text-transform:uppercase;">{{ ucwords(str_replace('_', ' ', $asset->asset_status)) }}</span>
                                    </div>
                                </div>
                                <div style="padding:14px 16px; border-radius:16px; border:1px solid #e2e8f0; background:#f8fafc;">
                                    <div style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase;">Condition Status</div>
                                    <div style="margin-top:8px; font-size:14px; font-weight:700; color:#0f172a; text-transform:capitalize;">{{ $asset->condition_status ?: 'Not recorded' }}</div>
                                </div>
                            </div>

                            <div style="padding:14px 16px; border-radius:16px; border:1px solid #fde68a; background:#fffbeb; color:#92400e;">
                                <div style="font-weight:800; margin-bottom:6px;">Workflow-Controlled Status</div>
                                <div style="font-size:13px; line-height:1.5;">{{ $workflowControl['message'] }}</div>
                                <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:12px;">
                                    @if(!empty($workflowControl['action_label']) && !empty($workflowControl['action_url']))
                                        <a href="{{ $workflowControl['action_url'] }}" style="display:inline-flex; align-items:center; justify-content:center; padding:10px 14px; border-radius:12px; background:#ffffff; color:#92400e; border:1px solid #fcd34d; text-decoration:none; font-weight:700;">{{ $workflowControl['action_label'] }}</a>
                                    @endif
                                    @if(!empty($workflowControl['convert_url']))
                                        <a href="{{ $workflowControl['convert_url'] }}" style="display:inline-flex; align-items:center; justify-content:center; padding:10px 14px; border-radius:12px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a; text-decoration:none; font-weight:600;">Convert Stock</a>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @else
                        <div class="asset-field-grid">
                            <div>
                                <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Status</label>
                                <select name="asset_status" id="asset_status_select" required style="{{ $fieldStyle('asset_status', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff;') }}"
                                        data-selected="{{ $selectedStatus }}"
                                        data-new-options='@json($newStockStatuses)'
                                        data-rental-options='@json($rentalAssetStatuses)'>
                                </select>
                                @if($fieldError('asset_status'))
                                    <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('asset_status') }}</div>
                                @endif
                            </div>

                            <div id="condition-status-group">
                                <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Condition Status</label>
                                <select name="condition_status" id="condition_status_select" style="{{ $fieldStyle('condition_status', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff;') }}">
                                    @foreach($conditionStatuses as $status)
                                        <option value="{{ $status }}" @selected($selectedCondition === $status)>{{ ucfirst($status) }}</option>
                                    @endforeach
                                </select>
                                @if($fieldError('condition_status'))
                                    <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('condition_status') }}</div>
                                @endif
                                <div id="condition-status-helper" style="margin-top:6px; color:#64748b; font-size:12px;">Used for rental wear-and-tear tracking.</div>
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            <div class="asset-main-column">
                <div class="asset-card">
                    <h2 style="margin:0; font-size:22px;">Purchase & Service</h2>
                    <p id="service-helper" style="margin:8px 0 18px; color:#64748b;">Use this for cost control and maintenance planning.</p>

                    <div style="display:grid; gap:18px;">
                        <div>
                            <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Purchase Date</label>
                            <input type="date" name="purchase_date" value="{{ old('purchase_date', optional($asset->purchase_date)->format('Y-m-d')) }}"
                                   style="{{ $fieldStyle('purchase_date', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff;') }}">
                            @if($fieldError('purchase_date'))
                                <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('purchase_date') }}</div>
                            @endif
                        </div>

                        <div>
                            <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Purchase Cost</label>
                            <input type="number" min="0" step="0.01" name="purchase_cost" value="{{ old('purchase_cost', $asset->purchase_cost) }}"
                                   style="{{ $fieldStyle('purchase_cost', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff;') }}">
                            @if($fieldError('purchase_cost'))
                                <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('purchase_cost') }}</div>
                            @endif
                        </div>

                        <div id="service-date-fields" style="display:grid; gap:18px;">
                            <div>
                                <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Last Service Date</label>
                                <input type="date" name="last_service_date" value="{{ old('last_service_date', optional($asset->last_service_date)->format('Y-m-d')) }}"
                                       style="{{ $fieldStyle('last_service_date', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff;') }}">
                                @if($fieldError('last_service_date'))
                                    <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('last_service_date') }}</div>
                                @endif
                            </div>

                            <div>
                                <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Next Service Date</label>
                                <input type="date" name="next_service_date" value="{{ old('next_service_date', optional($asset->next_service_date)->format('Y-m-d')) }}"
                                       style="{{ $fieldStyle('next_service_date', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff;') }}">
                                @if($fieldError('next_service_date'))
                                    <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('next_service_date') }}</div>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>

                <div class="asset-card">
                    <h2 style="margin:0; font-size:22px;">Notes</h2>
                    <p style="margin:8px 0 18px; color:#64748b;">Use this area for stock remarks, conversion context, sale notes, or rental handling instructions.</p>

                    <textarea name="notes" rows="8" style="{{ $fieldStyle('notes', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff; resize:vertical;') }}">{{ old('notes', $asset->notes) }}</textarea>
                    @if($fieldError('notes'))
                        <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('notes') }}</div>
                    @endif
                </div>
            </div>
        </div>

        <div class="asset-form-actions">
            <a href="{{ route('assets.index') }}" style="display:inline-flex; align-items:center; justify-content:center; padding:11px 16px; border-radius:12px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a; text-decoration:none; font-weight:600;">Cancel</a>
            <button type="submit" style="display:inline-flex; align-items:center; justify-content:center; padding:11px 18px; border:none; border-radius:12px; background:#0f766e; color:#ffffff; font-weight:700; cursor:pointer;">
                {{ $isEdit ? 'Update Asset' : 'Save Asset' }}
            </button>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const serialField = document.getElementById('asset_serial_number');
    const barcodeField = document.getElementById('asset_barcode_value');
    const barcodeCameraInput = document.getElementById('assetBarcodeCameraInput');
    const barcodeCameraButton = document.getElementById('assetBarcodeCameraButton');
    const serialPendingToggle = document.getElementById('asset_serial_pending');
    const stageInputs = document.querySelectorAll('input[name="asset_stage"]');
    const statusSelect = document.getElementById('asset_status_select');
    const conditionGroup = document.getElementById('condition-status-group');
    const conditionSelect = document.getElementById('condition_status_select');
    const serviceDateFields = document.getElementById('service-date-fields');
    const stageBadge = document.getElementById('asset-stage-badge');
    const statusHelper = document.getElementById('asset-status-helper');
    const conditionHelper = document.getElementById('condition-status-helper');
    const serviceHelper = document.getElementById('service-helper');
    const serialHelper = document.getElementById('asset-serial-helper');

    if (serialField) {
        serialField.focus();
        serialField.select();
    }

    const syncSerialPendingUi = function () {
        if (!serialField || !serialPendingToggle) {
            return;
        }

        if (serialPendingToggle.checked) {
            serialField.required = false;
            serialField.placeholder = 'Optional now. System will generate a PENDING serial.';
            if (serialHelper) {
                serialHelper.textContent = 'Serial pending is enabled. A unique placeholder serial will be generated in the format PENDING-{product_code}-{unique_id}. Replace it later when the real serial is confirmed.';
            }
        } else {
            serialField.required = true;
            serialField.placeholder = '';
            if (serialHelper) {
                serialHelper.textContent = 'Recommended for every tracked unit. Serial can be added later during Return Verification if currently unavailable.';
            }
        }
    };

    const titleize = function (value) {
        return value.replace(/_/g, ' ').replace(/\b\w/g, function (char) {
            return char.toUpperCase();
        });
    };

    const populateStatusOptions = function (stage) {
        if (!statusSelect) {
            return;
        }

        const selected = statusSelect.dataset.selected || statusSelect.value;
        const newOptions = JSON.parse(statusSelect.dataset.newOptions || '[]');
        const rentalOptions = JSON.parse(statusSelect.dataset.rentalOptions || '[]');
        const options = stage === 'new_stock' ? newOptions : rentalOptions;
        const fallback = stage === 'new_stock' ? 'available_for_sale' : 'available';

        statusSelect.innerHTML = '';

        options.forEach(function (optionValue) {
            const option = document.createElement('option');
            option.value = optionValue;
            option.textContent = titleize(optionValue);
            option.selected = optionValue === selected;
            statusSelect.appendChild(option);
        });

        if (!statusSelect.value && fallback) {
            statusSelect.value = fallback;
        }
    };

    const syncStageUi = function () {
        const activeStageInput = document.querySelector('input[name="asset_stage"]:checked');
        const stage = activeStageInput ? activeStageInput.value : 'rental_stock';

        populateStatusOptions(stage);

        if (stage === 'new_stock') {
            if (stageBadge) {
                stageBadge.textContent = 'Sale Unit workflow';
                stageBadge.style.background = '#fff7ed';
                stageBadge.style.color = '#9a3412';
            }

            if (statusHelper) {
                statusHelper.textContent = 'Use sale-unit statuses for fresh physical stock that may later be sold or converted.';
            }

            if (conditionGroup) {
                conditionGroup.style.opacity = '0.75';
            }

            if (conditionSelect) {
                conditionSelect.value = conditionSelect.value || 'good';
            }

            if (conditionHelper) {
                conditionHelper.textContent = 'Condition is optional here. Fresh stock usually stays as good unless you want to note damage.';
            }

            if (serviceDateFields) {
                serviceDateFields.style.opacity = '0.6';
            }

            if (serviceHelper) {
                serviceHelper.textContent = 'Service dates are usually not important for sale units, but you can still keep them if needed.';
            }
        } else {
            if (stageBadge) {
                stageBadge.textContent = 'Rental Asset workflow';
                stageBadge.style.background = '#eff6ff';
                stageBadge.style.color = '#1d4ed8';
            }

            if (statusHelper) {
                statusHelper.textContent = 'Use rental lifecycle statuses for operational assets used in dispatch, pickup, and maintenance.';
            }

            if (conditionGroup) {
                conditionGroup.style.opacity = '1';
            }

            if (conditionHelper) {
                conditionHelper.textContent = 'Required for rental wear-and-tear tracking.';
            }

            if (serviceDateFields) {
                serviceDateFields.style.opacity = '1';
            }

            if (serviceHelper) {
                serviceHelper.textContent = 'Useful for cost control and maintenance planning.';
            }
        }

        if (statusSelect) {
            statusSelect.dataset.selected = statusSelect.value;
        }
    };

    stageInputs.forEach(function (input) {
        input.addEventListener('change', syncStageUi);
    });

    if (serialPendingToggle) {
        serialPendingToggle.addEventListener('change', syncSerialPendingUi);
    }

    if (statusSelect) {
        statusSelect.addEventListener('change', function () {
            statusSelect.dataset.selected = statusSelect.value;
        });
    }

    syncStageUi();
    syncSerialPendingUi();

    async function decodeBarcodeFromFile(file) {
        if (!('BarcodeDetector' in window)) {
            alert('Camera capture is available, but barcode decoding is not supported on this browser. Please use Chrome on mobile or type the barcode manually.');
            return;
        }

        try {
            const detector = new BarcodeDetector({
                formats: ['code_128', 'code_39', 'codabar', 'ean_13', 'ean_8', 'upc_a', 'upc_e', 'qr_code']
            });
            const bitmap = await createImageBitmap(file);
            const barcodes = await detector.detect(bitmap);

            if (!barcodes.length) {
                alert('No barcode was detected in that image. Please try again with a clearer image.');
                return;
            }

            if (barcodeField) {
                barcodeField.value = barcodes[0].rawValue || '';
            }
        } catch (error) {
            alert('Unable to decode barcode from the captured image. Please try again or type it manually.');
        }
    }

    if (barcodeCameraButton && barcodeCameraInput) {
        barcodeCameraButton.addEventListener('click', function () {
            barcodeCameraInput.click();
        });

        barcodeCameraInput.addEventListener('change', function (event) {
            const file = event.target.files && event.target.files[0];

            if (file) {
                decodeBarcodeFromFile(file);
            }
        });
    }
});
</script>
