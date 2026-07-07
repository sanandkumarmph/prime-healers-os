@php
    $isEdit = $asset->exists;
    $selectedMode = old('entry_mode', request('mode') === 'bulk' ? 'bulk' : 'single');
    $selectedStage = old('asset_stage', $asset->asset_stage ?: \App\Models\Asset::STAGE_RENTAL_STOCK);
    $selectedStatus = old('asset_status', $asset->asset_status ?: ($selectedStage === \App\Models\Asset::STAGE_NEW_STOCK ? 'available_for_sale' : 'available'));
    $selectedCondition = old('condition_status', $asset->condition_status ?: ($isEdit ? 'good' : 'new'));
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
    $conditionLabels = [
        'new' => 'New',
        'good' => 'Good',
        'fair' => 'Fair',
        'needs_repair' => 'Needs Repair',
        'damaged' => 'Damaged',
        'retired' => 'Retired',
        'repair' => 'Needs Repair (legacy)',
        'inactive' => 'Retired (legacy)',
    ];
    $primaryConditionStatuses = ['new', 'good', 'fair', 'needs_repair', 'damaged', 'retired'];
    $displayConditionStatuses = collect($primaryConditionStatuses)
        ->when(!in_array($selectedCondition, $primaryConditionStatuses, true) && filled($selectedCondition), fn ($statuses) => $statuses->push($selectedCondition))
        ->all();
    $oldBulkSerials = old('serial_numbers', '');
@endphp

<style>
    .asset-form-page {
        max-width: 1320px;
        margin: 0 auto;
    }
    .asset-form-header {
        display:flex;
        justify-content:space-between;
        align-items:flex-start;
        gap:16px;
        margin-bottom:8px;
        flex-wrap:wrap;
    }
    .asset-stage-grid {
        display:grid;
        grid-template-columns:repeat(2, minmax(0, 1fr));
        gap:10px;
    }
    .asset-main-grid {
        display:grid;
        grid-template-columns:minmax(0, 1fr) minmax(0, 1fr);
        gap:14px;
        align-items:start;
    }
    .asset-main-column {
        display:grid;
        gap:12px;
    }
    .asset-card {
        background:#ffffff;
        border:1px solid #e2e8f0;
        border-radius:14px;
        padding:12px;
    }
    .asset-field-grid {
        display:grid;
        grid-template-columns:repeat(2, minmax(0, 1fr));
        gap:12px;
    }
    .asset-form-actions {
        display:flex;
        justify-content:flex-end;
        gap:12px;
        position:sticky;
        bottom:12px;
        z-index:20;
        padding:10px;
        border:1px solid #dbeafe;
        border-radius:18px;
        background:rgba(255,255,255,.94);
        box-shadow:0 16px 42px rgba(15,23,42,.12);
        backdrop-filter:blur(10px);
    }
    .asset-guidance-grid {
        display:grid;
        grid-template-columns:repeat(3, minmax(0, 1fr));
        gap:12px;
        margin-bottom:12px;
    }
    .asset-form-page input,
    .asset-form-page select,
    .asset-form-page textarea {
        min-height:42px !important;
        padding:9px 11px !important;
        border-radius:11px !important;
        font-size:14px !important;
    }
    .asset-form-page textarea {
        min-height:92px !important;
    }
    .asset-form-page label {
        margin-bottom:5px !important;
        font-size:12px !important;
    }
    .asset-form-page h2 {
        font-size:17px !important;
    }
    .asset-form-page p {
        line-height:1.35 !important;
    }
    .asset-stage-option {
        padding:9px 12px !important;
        border-radius:12px !important;
        align-items:center !important;
    }
    .asset-stage-option input {
        width:18px !important;
        min-height:18px !important;
        height:18px !important;
        margin-top:0 !important;
        padding:0 !important;
    }
    .asset-stage-option-title {
        font-size:14px !important;
    }
    .asset-stage-option-copy {
        font-size:11px !important;
        margin-top:1px !important;
    }
    .asset-summary-grid {
        display:grid;
        grid-template-columns:repeat(2, minmax(0, 1fr));
        gap:8px;
    }
    .asset-summary-item {
        padding:10px;
        border:1px solid #e2e8f0;
        border-radius:12px;
        background:#f8fafc;
    }
    .asset-summary-label {
        font-size:10px;
        color:#64748b;
        font-weight:800;
        text-transform:uppercase;
        letter-spacing:.07em;
    }
    .asset-summary-value {
        margin-top:4px;
        color:#0f172a;
        font-size:13px;
        font-weight:800;
        white-space:nowrap;
        overflow:hidden;
        text-overflow:ellipsis;
    }
    .asset-actions-secondary {
        background:#ffffff !important;
        color:#0f172a !important;
        border:1px solid #cbd5e1 !important;
    }
    .asset-actions-primary {
        background:#0f766e !important;
        color:#ffffff !important;
        border:1px solid #0f766e !important;
    }
    .asset-mode-control {
        display:inline-grid;
        grid-template-columns:repeat(2, minmax(0, 1fr));
        gap:4px;
        padding:4px;
        margin-bottom:10px;
        border:1px solid #dbeafe;
        border-radius:14px;
        background:#ffffff;
    }
    .asset-mode-button {
        border:0;
        border-radius:10px;
        background:transparent;
        color:#475569;
        cursor:pointer;
        font-weight:800;
        padding:8px 12px;
    }
    .asset-mode-button.is-active {
        background:#2563eb;
        color:#ffffff;
        box-shadow:0 8px 20px rgba(37,99,235,.22);
    }
    .asset-bulk-panel[hidden],
    .asset-single-panel[hidden] {
        display:none !important;
    }
    .asset-bulk-common-grid {
        display:grid;
        grid-template-columns:repeat(2, minmax(0, 1fr));
        gap:12px;
    }
    .asset-bulk-serial-list {
        display:grid;
        gap:6px;
        max-height:260px;
        overflow:auto;
    }
    .asset-bulk-row {
        display:grid;
        grid-template-columns:minmax(0, 1fr) 150px 84px;
        gap:8px;
        align-items:center;
        padding:8px 10px;
        border:1px solid #e2e8f0;
        border-radius:12px;
        background:#f8fafc;
    }
    .asset-bulk-status {
        justify-self:start;
        padding:4px 8px;
        border-radius:999px;
        font-size:11px;
        font-weight:800;
        text-transform:uppercase;
    }
    .asset-bulk-status.is-ready {
        background:#dcfce7;
        color:#166534;
    }
    .asset-bulk-status.is-duplicate {
        background:#fef3c7;
        color:#92400e;
    }
    .asset-bulk-status.is-invalid {
        background:#fee2e2;
        color:#b91c1c;
    }
    .asset-bulk-row button {
        min-height:32px !important;
        padding:6px 8px !important;
        border:1px solid #cbd5e1;
        border-radius:9px;
        background:#ffffff;
        color:#0f172a;
        cursor:pointer;
        font-weight:800;
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
            position:static;
            padding:0;
            border:none;
            box-shadow:none;
            background:transparent;
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
        .asset-mode-control,
        .asset-bulk-common-grid,
        .asset-bulk-row {
            grid-template-columns:1fr;
        }
    }
</style>

<div class="asset-form-page">
    <div class="asset-form-header">
        <div>
            <div style="display:inline-flex; padding:4px 9px; border-radius:999px; background:#eff6ff; color:#1d4ed8; font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:0.08em;">Inventory Unit</div>
            <h1 style="margin:5px 0 2px; font-size:24px; letter-spacing:-0.03em;">{{ $isEdit ? 'Edit Asset' : 'Add Stock' }}</h1>
            <p style="margin:0; color:#64748b; max-width:560px; font-size:14px;">Create one physical unit.</p>
        </div>
        <a href="{{ route('assets.index') }}" style="display:inline-flex; align-items:center; justify-content:center; padding:8px 12px; border-radius:11px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a; text-decoration:none; font-weight:700; font-size:14px;">
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

    <div class="asset-guidance-grid" style="display:none;">
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

    @unless($isEdit)
        <div class="asset-mode-control" role="tablist" aria-label="Add stock mode">
            <button type="button" class="asset-mode-button {{ $selectedMode === 'single' ? 'is-active' : '' }}" data-asset-mode-target="single">Single Unit</button>
            <button type="button" class="asset-mode-button {{ $selectedMode === 'bulk' ? 'is-active' : '' }}" data-asset-mode-target="bulk">Multi Unit / Bulk Scan</button>
        </div>

        <form method="POST" action="{{ route('assets.bulk-store') }}" id="assetBulkForm" class="asset-bulk-panel" style="display:grid; gap:12px;" @if($selectedMode !== 'bulk') hidden @endif>
            @csrf
            <input type="hidden" name="entry_mode" value="bulk">

            <div class="asset-card">
                <div style="display:flex; justify-content:space-between; gap:12px; align-items:center; flex-wrap:wrap; margin-bottom:10px;">
                    <div>
                        <h2 style="margin:0;">Bulk Stock Details</h2>
                        <p style="margin:2px 0 0; color:#64748b; font-size:12px;">These values apply to every serial.</p>
                    </div>
                    <span id="assetBulkCountBadge" style="display:inline-flex; padding:6px 10px; border-radius:999px; background:#eff6ff; color:#1d4ed8; font-size:11px; font-weight:800;">0 ready</span>
                </div>

                <div class="asset-bulk-common-grid">
                    <div style="grid-column:1 / -1;">
                        <div class="asset-stage-grid">
                            <label class="asset-stage-option" style="display:flex; gap:10px; align-items:center; padding:9px 12px; border-radius:12px; border:1px solid #fed7aa; background:#fffaf0; cursor:pointer;">
                                <input type="radio" name="asset_stage" value="{{ \App\Models\Asset::STAGE_NEW_STOCK }}" @checked($selectedStage === \App\Models\Asset::STAGE_NEW_STOCK)>
                                <span>
                                    <span class="asset-stage-option-title" style="display:block; font-weight:800; color:#9a3412;">Sale Unit</span>
                                    <span class="asset-stage-option-copy" style="display:block; color:#9a3412;">Available for sale.</span>
                                </span>
                            </label>
                            <label class="asset-stage-option" style="display:flex; gap:10px; align-items:center; padding:9px 12px; border-radius:12px; border:1px solid #bfdbfe; background:#eff6ff; cursor:pointer;">
                                <input type="radio" name="asset_stage" value="{{ \App\Models\Asset::STAGE_RENTAL_STOCK }}" @checked($selectedStage === \App\Models\Asset::STAGE_RENTAL_STOCK)>
                                <span>
                                    <span class="asset-stage-option-title" style="display:block; font-weight:800; color:#1d4ed8;">Rental Asset</span>
                                    <span class="asset-stage-option-copy" style="display:block; color:#1d4ed8;">Ready for rental workflow.</span>
                                </span>
                            </label>
                        </div>
                    </div>

                    <div>
                        <label style="display:block; color:#475569; font-weight:700;">Product *</label>
                        <select name="product_id" required style="{{ $fieldStyle('product_id', 'width:100%; border:1px solid #cbd5e1; background:#ffffff;') }}">
                            <option value="">Select product</option>
                            @foreach($products as $product)
                                <option value="{{ $product->id }}" @selected(old('product_id', $asset->product_id) == $product->id)>{{ $productOptionLabel($product) }}</option>
                            @endforeach
                        </select>
                        @if($fieldError('product_id'))
                            <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('product_id') }}</div>
                        @endif
                    </div>

                    <div>
                        <label style="display:block; color:#475569; font-weight:700;">Warehouse *</label>
                        <select name="warehouse_id" required style="{{ $fieldStyle('warehouse_id', 'width:100%; border:1px solid #cbd5e1; background:#ffffff;') }}">
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
                        <label style="display:block; color:#475569; font-weight:700;">Condition *</label>
                        <select name="condition_status" required style="{{ $fieldStyle('condition_status', 'width:100%; border:1px solid #cbd5e1; background:#ffffff;') }}">
                            @foreach($displayConditionStatuses as $status)
                                <option value="{{ $status }}" @selected($selectedCondition === $status)>{{ $conditionLabels[$status] ?? ucfirst(str_replace('_', ' ', $status)) }}</option>
                            @endforeach
                        </select>
                        @if($fieldError('condition_status'))
                            <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('condition_status') }}</div>
                        @endif
                    </div>

                    <div>
                        <label style="display:block; color:#475569; font-weight:700;">Purchase Cost</label>
                        <input type="number" min="0" step="0.01" name="purchase_cost" value="{{ old('purchase_cost', $asset->purchase_cost) }}" style="{{ $fieldStyle('purchase_cost', 'width:100%; border:1px solid #cbd5e1; background:#ffffff;') }}">
                    </div>

                    <div>
                        <label style="display:block; color:#475569; font-weight:700;">Purchase Date</label>
                        <input type="date" name="purchase_date" value="{{ old('purchase_date', optional($asset->purchase_date)->format('Y-m-d')) }}" style="{{ $fieldStyle('purchase_date', 'width:100%; border:1px solid #cbd5e1; background:#ffffff;') }}">
                    </div>

                    <div>
                        <label style="display:block; color:#475569; font-weight:700;">Last Service</label>
                        <input type="date" name="last_service_date" value="{{ old('last_service_date', optional($asset->last_service_date)->format('Y-m-d')) }}" style="{{ $fieldStyle('last_service_date', 'width:100%; border:1px solid #cbd5e1; background:#ffffff;') }}">
                    </div>

                    <div>
                        <label style="display:block; color:#475569; font-weight:700;">Next Service</label>
                        <input type="date" name="next_service_date" value="{{ old('next_service_date', optional($asset->next_service_date)->format('Y-m-d')) }}" style="{{ $fieldStyle('next_service_date', 'width:100%; border:1px solid #cbd5e1; background:#ffffff;') }}">
                    </div>

                    <div style="grid-column:1 / -1;">
                        <label style="display:block; color:#475569; font-weight:700;">Notes</label>
                        <textarea name="notes" rows="2" style="{{ $fieldStyle('notes', 'width:100%; border:1px solid #cbd5e1; background:#ffffff; resize:vertical;') }}">{{ old('notes', $asset->notes) }}</textarea>
                    </div>
                </div>
            </div>

            <div class="asset-card">
                <div style="display:flex; justify-content:space-between; gap:12px; align-items:center; flex-wrap:wrap; margin-bottom:10px;">
                    <div>
                        <h2 style="margin:0;">Scan or Enter Serial Numbers</h2>
                        <p style="margin:2px 0 0; color:#64748b; font-size:12px;">Scan barcode or type serial and press Enter.</p>
                    </div>
                    <button type="button" id="assetBulkClearButton" class="asset-actions-secondary" style="display:inline-flex; align-items:center; justify-content:center; padding:8px 10px; border-radius:10px; font-weight:800; cursor:pointer;">Clear List</button>
                </div>

                <div style="display:flex; gap:8px; align-items:stretch; flex-wrap:wrap;">
                    <input type="text" id="assetBulkSerialInput" autocomplete="off" spellcheck="false" placeholder="Scan or enter serial number" style="flex:1; min-width:240px; border:1px solid #2563eb; background:#ffffff;">
                    <button type="button" id="assetBulkAddButton" class="asset-actions-primary" style="display:inline-flex; align-items:center; justify-content:center; padding:0 14px; border-radius:11px; font-weight:800; cursor:pointer;">Add Serial</button>
                    <input type="file" id="assetBulkCameraInput" accept="image/*" capture="environment" style="display:none;">
                    <button type="button" id="assetBulkCameraButton" class="asset-actions-secondary" style="display:inline-flex; align-items:center; justify-content:center; padding:0 14px; border-radius:11px; font-weight:800; cursor:pointer;">Open Camera</button>
                </div>
                <input type="hidden" name="serial_numbers" id="assetBulkSerials" value="{{ $oldBulkSerials }}">
                @if($fieldError('serial_numbers'))
                    <div style="margin-top:8px; color:#b91c1c; font-size:12px; font-weight:700;">{{ $fieldError('serial_numbers') }}</div>
                @endif

                <div id="assetBulkSerialList" class="asset-bulk-serial-list" style="margin-top:10px;"></div>
                <div id="assetBulkEmptyState" style="margin-top:10px; padding:12px; border:1px dashed #cbd5e1; border-radius:12px; color:#64748b; font-size:13px;">No serials added yet.</div>
            </div>

            <div class="asset-form-actions">
                <a href="{{ route('assets.index') }}" class="asset-actions-secondary" style="display:inline-flex; align-items:center; justify-content:center; padding:10px 14px; border-radius:12px; text-decoration:none; font-weight:700;">Cancel</a>
                <button type="button" id="assetBulkClearButtonBottom" class="asset-actions-secondary" style="display:inline-flex; align-items:center; justify-content:center; padding:10px 14px; border-radius:12px; font-weight:800; cursor:pointer;">Clear List</button>
                <button type="submit" name="save_action" value="add_more" id="assetBulkSaveMoreButton" class="asset-actions-secondary" style="display:inline-flex; align-items:center; justify-content:center; padding:10px 14px; border-radius:12px; font-weight:800; cursor:pointer;">Save & Add More</button>
                <button type="submit" name="save_action" value="save" id="assetBulkSaveButton" class="asset-actions-primary" style="display:inline-flex; align-items:center; justify-content:center; padding:10px 18px; border:none; border-radius:12px; font-weight:800; cursor:pointer;">Save 0 Assets</button>
            </div>
        </form>
    @endunless

    <div id="assetSinglePanel" class="asset-single-panel" @if(!$isEdit && $selectedMode === 'bulk') hidden @endif>
    <form method="POST" action="{{ $isEdit ? route('assets.update', $asset) : route('assets.store') }}" style="display:grid; gap:12px;">
        @unless($isEdit)
            <input type="hidden" name="entry_mode" value="single">
        @endunless
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
            <div style="display:flex; justify-content:space-between; gap:12px; align-items:center; flex-wrap:wrap; margin-bottom:8px;">
                <div>
                    <h2 style="margin:0; font-size:22px;">Stock Type</h2>
                    <p style="margin:2px 0 0; color:#64748b; font-size:12px;">Sale stock or rental asset.</p>
                </div>
                <div id="asset-stage-badge" style="display:inline-flex; padding:6px 10px; border-radius:999px; background:#eff6ff; color:#1d4ed8; font-size:11px; font-weight:800;">
                    Rental Asset workflow
                </div>
            </div>

            <div class="asset-stage-grid">
                <label class="asset-stage-option" style="{{ $fieldStyle('asset_stage', 'display:flex; gap:10px; align-items:center; padding:9px 12px; border-radius:12px; border:1px solid #fed7aa; background:#fffaf0; cursor:pointer;') }}">
                    <input type="radio" name="asset_stage" value="{{ \App\Models\Asset::STAGE_NEW_STOCK }}" @checked($selectedStage === \App\Models\Asset::STAGE_NEW_STOCK) @disabled($isEdit && $isWorkflowLocked) style="margin-top:4px;">
                    <span>
                        <span class="asset-stage-option-title" style="display:block; font-size:15px; font-weight:800; color:#9a3412;">Sale Unit</span>
                        <span class="asset-stage-option-copy" style="display:block; margin-top:4px; color:#9a3412; font-size:12px;">Available for sale.</span>
                    </span>
                </label>
                <label class="asset-stage-option" style="{{ $fieldStyle('asset_stage', 'display:flex; gap:10px; align-items:center; padding:9px 12px; border-radius:12px; border:1px solid #bfdbfe; background:#eff6ff; cursor:pointer;') }}">
                    <input type="radio" name="asset_stage" value="{{ \App\Models\Asset::STAGE_RENTAL_STOCK }}" @checked($selectedStage === \App\Models\Asset::STAGE_RENTAL_STOCK) @disabled($isEdit && $isWorkflowLocked) style="margin-top:4px;">
                    <span>
                        <span class="asset-stage-option-title" style="display:block; font-size:15px; font-weight:800; color:#1d4ed8;">Rental Asset</span>
                        <span class="asset-stage-option-copy" style="display:block; margin-top:4px; color:#1d4ed8; font-size:12px;">Ready for rental workflow.</span>
                    </span>
                </label>
            </div>
        </div>

        <div class="asset-main-grid">
            <div class="asset-main-column">
                <div class="asset-card">
                    <h2 style="margin:0; font-size:22px;">Product & Identity</h2>
                    <p style="margin:4px 0 12px; color:#64748b; font-size:13px;">Required fields are marked with *.</p>

                    <div class="asset-field-grid">
                        <div>
                            <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Product *</label>
                            <select name="product_id" id="asset_product_id" required style="{{ $fieldStyle('product_id', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff;') }}">
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
                            <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Warehouse *</label>
                            <select name="warehouse_id" id="asset_warehouse_id" required style="{{ $fieldStyle('warehouse_id', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff;') }}">
                                <option value="">Select warehouse</option>
                                @foreach($warehouses as $warehouse)
                                    <option value="{{ $warehouse->id }}" @selected(old('warehouse_id', $asset->warehouse_id) == $warehouse->id)>{{ $warehouse->name }}</option>
                                @endforeach
                            </select>
                            @if($fieldError('warehouse_id'))
                                <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('warehouse_id') }}</div>
                            @endif
                        </div>

                        <input type="hidden" name="asset_name" id="asset_name" value="{{ old('asset_name', $asset->asset_name) }}">

                        <div style="grid-column:1 / -1;">
                            <label id="asset-serial-label" style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Serial Number</label>
                            <div style="display:flex; gap:8px; align-items:stretch; flex-wrap:wrap;">
                                <input type="text" name="serial_number" id="asset_serial_number" value="{{ old('serial_number', $asset->serial_number) }}" @if(!$serialPendingEnabled) required @endif autofocus autocomplete="off" spellcheck="false"
                                       style="{{ $fieldStyle('serial_number', 'flex:1; min-width:240px; padding:12px 14px; border:1px solid #2563eb; border-radius:14px; background:#ffffff;') }}">
                                <input type="hidden" name="barcode_value" id="asset_barcode_value" value="{{ old('barcode_value', $asset->barcode_value) }}">
                                <input type="file" id="assetBarcodeCameraInput" accept="image/*" capture="environment" style="display:none;">
                                <button type="button" id="assetBarcodeCameraButton" class="asset-actions-secondary" style="display:inline-flex; align-items:center; justify-content:center; padding:0 14px; border-radius:11px; font-weight:800; cursor:pointer;">
                                    Scan
                                </button>
                            </div>
                            @if($fieldError('serial_number'))
                                <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('serial_number') }}</div>
                            @endif
                            @if($fieldError('barcode_value'))
                                <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('barcode_value') }}</div>
                            @endif
                            @if(!$isEdit)
                                <label style="display:flex; gap:10px; align-items:flex-start; margin-top:10px; color:#334155; font-size:12px;">
                                    <input type="checkbox" name="serial_pending" id="asset_serial_pending" value="1" @checked($serialPendingEnabled) style="margin-top:2px;">
                                    <span><strong>Serial unavailable</strong></span>
                                </label>
                            @endif
                            <div id="asset-serial-helper" style="margin-top:6px; color:#64748b; font-size:12px;">Scan or enter when available.</div>
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
                                    @foreach($displayConditionStatuses as $status)
                                        <option value="{{ $status }}" @selected($selectedCondition === $status)>{{ $conditionLabels[$status] ?? ucfirst(str_replace('_', ' ', $status)) }}</option>
                                    @endforeach
                                </select>
                                @if($fieldError('condition_status'))
                                    <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('condition_status') }}</div>
                                @endif
                                <div id="condition-status-helper" style="margin-top:6px; color:#64748b; font-size:12px;">Current physical condition.</div>
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            <div class="asset-main-column">
                <div class="asset-card">
                    <div style="display:flex; justify-content:space-between; gap:10px; align-items:center; margin-bottom:10px;">
                        <h2 style="margin:0; font-size:22px;">Live Summary</h2>
                        <span style="display:inline-flex; padding:5px 9px; border-radius:999px; background:#ecfeff; color:#0f766e; font-size:11px; font-weight:800; text-transform:uppercase;">Ready check</span>
                    </div>
                    <div class="asset-summary-grid">
                        <div class="asset-summary-item">
                            <div class="asset-summary-label">Product</div>
                            <div class="asset-summary-value" id="asset_summary_product">Not selected</div>
                        </div>
                        <div class="asset-summary-item">
                            <div class="asset-summary-label">Warehouse</div>
                            <div class="asset-summary-value" id="asset_summary_warehouse">Not selected</div>
                        </div>
                        <div class="asset-summary-item">
                            <div class="asset-summary-label">Serial</div>
                            <div class="asset-summary-value" id="asset_summary_serial">Required</div>
                        </div>
                        <div class="asset-summary-item">
                            <div class="asset-summary-label">Condition</div>
                            <div class="asset-summary-value" id="asset_summary_condition">New</div>
                        </div>
                    </div>
                </div>

                <div class="asset-card">
                    <h2 style="margin:0; font-size:22px;">Purchase & Service</h2>
                    <p id="service-helper" style="margin:4px 0 12px; color:#64748b; font-size:13px;">Cost and service dates.</p>

                    <div style="display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:12px;">
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

                        <div id="service-date-fields" style="display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:12px; grid-column:1 / -1;">
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

                <details class="asset-card" @if(filled(old('notes', $asset->notes)) || $fieldError('notes')) open @endif>
                    <summary style="cursor:pointer; font-size:18px; font-weight:800; color:#0f172a;">Notes</summary>
                    <div style="margin-top:10px;">
                        <textarea name="notes" rows="4" style="{{ $fieldStyle('notes', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff; resize:vertical;') }}">{{ old('notes', $asset->notes) }}</textarea>
                        @if($fieldError('notes'))
                            <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('notes') }}</div>
                        @endif
                    </div>
                </details>
            </div>
        </div>

        <div class="asset-form-actions">
            <a href="{{ route('assets.index') }}" class="asset-actions-secondary" style="display:inline-flex; align-items:center; justify-content:center; padding:10px 14px; border-radius:12px; text-decoration:none; font-weight:700;">Cancel</a>
            @unless($isEdit)
                <button type="submit" name="save_action" value="add_another" class="asset-actions-secondary" style="display:inline-flex; align-items:center; justify-content:center; padding:10px 14px; border-radius:12px; font-weight:800; cursor:pointer;">
                    Save & Add Another
                </button>
            @endunless
            <button type="submit" name="save_action" value="save" class="asset-actions-primary" style="display:inline-flex; align-items:center; justify-content:center; padding:10px 18px; border:none; border-radius:12px; font-weight:800; cursor:pointer;">
                {{ $isEdit ? 'Update Asset' : 'Save Stock' }}
            </button>
        </div>
    </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const serialField = document.getElementById('asset_serial_number');
    const barcodeField = document.getElementById('asset_barcode_value');
    const productSelect = document.getElementById('asset_product_id');
    const warehouseSelect = document.getElementById('asset_warehouse_id');
    const assetNameField = document.getElementById('asset_name');
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
    const summaryProduct = document.getElementById('asset_summary_product');
    const summaryWarehouse = document.getElementById('asset_summary_warehouse');
    const summarySerial = document.getElementById('asset_summary_serial');
    const summaryCondition = document.getElementById('asset_summary_condition');
    let assetNameManuallyEdited = Boolean(assetNameField && assetNameField.value.trim());

    const modeButtons = document.querySelectorAll('[data-asset-mode-target]');
    const bulkPanel = document.getElementById('assetBulkForm');
    const singlePanel = document.getElementById('assetSinglePanel');
    const bulkInput = document.getElementById('assetBulkSerialInput');
    const bulkAddButton = document.getElementById('assetBulkAddButton');
    const bulkCameraInput = document.getElementById('assetBulkCameraInput');
    const bulkCameraButton = document.getElementById('assetBulkCameraButton');
    const bulkHidden = document.getElementById('assetBulkSerials');
    const bulkList = document.getElementById('assetBulkSerialList');
    const bulkEmpty = document.getElementById('assetBulkEmptyState');
    const bulkCountBadge = document.getElementById('assetBulkCountBadge');
    const bulkSaveButton = document.getElementById('assetBulkSaveButton');
    const bulkSaveMoreButton = document.getElementById('assetBulkSaveMoreButton');
    const bulkClearButtons = [document.getElementById('assetBulkClearButton'), document.getElementById('assetBulkClearButtonBottom')].filter(Boolean);
    let bulkSerials = bulkHidden && bulkHidden.value
        ? bulkHidden.value.split(/[\s,]+/).map((serial) => serial.trim()).filter(Boolean)
        : [];

    const setAssetMode = function (mode) {
        modeButtons.forEach(function (button) {
            button.classList.toggle('is-active', button.dataset.assetModeTarget === mode);
        });

        if (bulkPanel) {
            bulkPanel.hidden = mode !== 'bulk';
        }

        if (singlePanel) {
            singlePanel.hidden = mode === 'bulk';
        }

        if (mode === 'bulk' && bulkInput) {
            setTimeout(function () {
                bulkInput.focus();
            }, 50);
        }
    };

    modeButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            setAssetMode(button.dataset.assetModeTarget || 'single');
        });
    });

    const parseBulkSerials = function (value) {
        return (value || '')
            .split(/[\s,]+/)
            .map((serial) => serial.trim())
            .filter(Boolean);
    };

    const syncBulkSerials = function () {
        if (!bulkList || !bulkHidden) {
            return;
        }

        const counts = bulkSerials.reduce(function (carry, serial) {
            const key = serial.toLowerCase();
            carry[key] = (carry[key] || 0) + 1;
            return carry;
        }, {});
        const rows = bulkSerials.map(function (serial, index) {
            const duplicate = counts[serial.toLowerCase()] > 1;
            const invalid = !serial || serial.length > 255;
            const status = invalid ? 'Empty / Invalid' : (duplicate ? 'Duplicate in this batch' : 'Ready');
            const statusClass = invalid ? 'is-invalid' : (duplicate ? 'is-duplicate' : 'is-ready');

            return { serial, index, status, statusClass, duplicate, invalid };
        });
        const readyCount = rows.filter((row) => !row.duplicate && !row.invalid).length;
        const hasInvalidRows = rows.some((row) => row.duplicate || row.invalid);

        bulkHidden.value = bulkSerials.join("\n");
        bulkList.innerHTML = '';

        const escapeHtml = function (value) {
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        };

        rows.forEach(function (row) {
            const item = document.createElement('div');
            item.className = 'asset-bulk-row';
            item.innerHTML = `
                <input type="text" value="${escapeHtml(row.serial)}" data-bulk-edit="${row.index}" aria-label="Edit serial ${row.index + 1}" style="width:100%; border:1px solid #cbd5e1; background:#ffffff;">
                <span class="asset-bulk-status ${row.statusClass}">${row.status}</span>
                <button type="button" data-bulk-remove="${row.index}">Remove</button>
            `;
            bulkList.appendChild(item);
        });

        if (bulkEmpty) {
            bulkEmpty.style.display = rows.length ? 'none' : 'block';
        }

        if (bulkCountBadge) {
            bulkCountBadge.textContent = readyCount + ' ready';
        }

        [bulkSaveButton, bulkSaveMoreButton].forEach(function (button) {
            if (!button) {
                return;
            }

            button.disabled = rows.length === 0 || hasInvalidRows;
            button.style.opacity = button.disabled ? '0.55' : '1';
            button.style.cursor = button.disabled ? 'not-allowed' : 'pointer';

            if (button === bulkSaveButton) {
                button.textContent = 'Save ' + readyCount + (readyCount === 1 ? ' Asset' : ' Assets');
            }
        });
    };

    const addBulkSerials = function (value) {
        const serials = parseBulkSerials(value);

        if (!serials.length) {
            syncBulkSerials();
            return;
        }

        bulkSerials = bulkSerials.concat(serials);
        syncBulkSerials();
    };

    const addBulkInputValue = function () {
        if (!bulkInput) {
            return;
        }

        addBulkSerials(bulkInput.value);
        bulkInput.value = '';
        bulkInput.focus();
    };

    if (bulkInput) {
        bulkInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                addBulkInputValue();
            }
        });

        bulkInput.addEventListener('paste', function (event) {
            const pasted = event.clipboardData ? event.clipboardData.getData('text') : '';

            if (parseBulkSerials(pasted).length > 1) {
                event.preventDefault();
                addBulkSerials(pasted);
                bulkInput.value = '';
                bulkInput.focus();
            }
        });
    }

    if (bulkAddButton) {
        bulkAddButton.addEventListener('click', addBulkInputValue);
    }

    if (bulkList) {
        bulkList.addEventListener('click', function (event) {
            const removeButton = event.target.closest('[data-bulk-remove]');

            if (!removeButton) {
                return;
            }

            bulkSerials.splice(Number(removeButton.dataset.bulkRemove), 1);
            syncBulkSerials();
            bulkInput?.focus();
        });

        bulkList.addEventListener('change', function (event) {
            const editInput = event.target.closest('[data-bulk-edit]');

            if (!editInput) {
                return;
            }

            bulkSerials[Number(editInput.dataset.bulkEdit)] = editInput.value.trim();
            syncBulkSerials();
        });
    }

    bulkClearButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            bulkSerials = [];
            if (bulkInput) {
                bulkInput.value = '';
                bulkInput.focus();
            }
            syncBulkSerials();
        });
    });

    syncBulkSerials();

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
                serialHelper.textContent = 'A pending serial will be generated.';
            }
        } else {
            serialField.required = true;
            serialField.placeholder = '';
            if (serialHelper) {
                serialHelper.textContent = 'Scan or enter when available.';
            }
        }

        syncAssetSummary();
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
                conditionHelper.textContent = 'Current physical condition.';
            }

            if (serviceDateFields) {
                serviceDateFields.style.opacity = '0.6';
            }

            if (serviceHelper) {
                serviceHelper.textContent = 'Optional for sale units.';
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
                conditionHelper.textContent = 'Current physical condition.';
            }

            if (serviceDateFields) {
                serviceDateFields.style.opacity = '1';
            }

            if (serviceHelper) {
                serviceHelper.textContent = 'Useful for maintenance planning.';
            }
        }

        if (statusSelect) {
            statusSelect.dataset.selected = statusSelect.value;
        }

        syncAssetSummary();
    };

    stageInputs.forEach(function (input) {
        input.addEventListener('change', syncStageUi);
    });

    if (serialPendingToggle) {
        serialPendingToggle.addEventListener('change', syncSerialPendingUi);
    }

    const cleanSelectedText = function (select) {
        if (!select || !select.selectedOptions || !select.selectedOptions.length) {
            return '';
        }

        return (select.selectedOptions[0].textContent || '').trim();
    };

    const productNameFromOption = function () {
        const optionText = cleanSelectedText(productSelect);

        if (!optionText || optionText === 'Select product') {
            return '';
        }

        return optionText.split(' - ')[0].trim() || optionText;
    };

    function syncAssetSummary() {
        const productName = productNameFromOption();
        const warehouseName = cleanSelectedText(warehouseSelect);
        const conditionText = cleanSelectedText(conditionSelect);
        const hasSerial = Boolean(serialField && serialField.value.trim());
        const serialPending = Boolean(serialPendingToggle && serialPendingToggle.checked);

        if (summaryProduct) {
            summaryProduct.textContent = productName || 'Not selected';
        }

        if (summaryWarehouse) {
            summaryWarehouse.textContent = warehouseName && warehouseName !== 'Select warehouse' ? warehouseName : 'Not selected';
        }

        if (summarySerial) {
            summarySerial.textContent = hasSerial ? 'Captured' : (serialPending ? 'Pending' : 'Required');
        }

        if (summaryCondition) {
            summaryCondition.textContent = conditionText || 'Not set';
        }

        if (barcodeField && serialField && !barcodeField.value.trim()) {
            barcodeField.value = serialField.value.trim();
        }
    }

    if (assetNameField) {
        assetNameField.addEventListener('input', function () {
            assetNameManuallyEdited = Boolean(assetNameField.value.trim());
            syncAssetSummary();
        });
    }

    if (productSelect) {
        productSelect.addEventListener('change', function () {
            const productName = productNameFromOption();

            if (assetNameField && productName && !assetNameManuallyEdited) {
                assetNameField.value = productName;
            }

            syncAssetSummary();
        });
    }

    [warehouseSelect, conditionSelect, serialField].forEach(function (field) {
        if (field) {
            field.addEventListener('input', syncAssetSummary);
            field.addEventListener('change', syncAssetSummary);
        }
    });

    if (serialField && barcodeField) {
        serialField.addEventListener('input', function () {
            barcodeField.value = serialField.value.trim();
        });
    }

    if (statusSelect) {
        statusSelect.addEventListener('change', function () {
            statusSelect.dataset.selected = statusSelect.value;
        });
    }

    syncStageUi();
    syncSerialPendingUi();
    syncAssetSummary();

    async function decodeBarcodeFromFile(file, onDecoded) {
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

            const decodedValue = barcodes[0].rawValue || '';

            if (typeof onDecoded === 'function') {
                onDecoded(decodedValue);
            } else if (serialField) {
                serialField.value = decodedValue;
                if (barcodeField) {
                    barcodeField.value = decodedValue;
                }
                syncSerialPendingUi();
                syncAssetSummary();
                serialField.focus();
            } else if (barcodeField) {
                barcodeField.value = decodedValue;
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

    if (bulkCameraButton && bulkCameraInput) {
        bulkCameraButton.addEventListener('click', function () {
            bulkCameraInput.click();
        });

        bulkCameraInput.addEventListener('change', function (event) {
            const file = event.target.files && event.target.files[0];

            if (file) {
                decodeBarcodeFromFile(file, function (decodedValue) {
                    addBulkSerials(decodedValue);
                    if (bulkInput) {
                        bulkInput.value = '';
                        bulkInput.focus();
                    }
                });
            }

            bulkCameraInput.value = '';
        });
    }
});
</script>
