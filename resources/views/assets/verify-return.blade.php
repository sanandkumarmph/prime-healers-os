@extends('layouts.app')

@php
    $rental = $rentalAssignment?->rental;
    $customerName = $rental
        ? ($rental->customer_type === 'business_partner'
            ? ($rental->businessPartner?->billingDisplayName() ?: $rental->businessPartner?->displayName() ?: 'Business Partner')
            : ($rental->customer?->displayName() ?: ($rental->customer_name ?: 'Customer')))
        : 'Not linked';
    $returnDate = $rentalAssignment?->returned_at ?: $asset->updated_at;
    $conditionOptions = [
        'new' => 'New',
        'good' => 'Good',
        'fair' => 'Fair',
        'needs_repair' => 'Needs Repair',
        'damaged' => 'Damaged',
        'retired' => 'Retired',
    ];
    $outcomeOptions = [
        'return_to_stock' => 'Return to Stock / Available',
        'repair' => 'Send to Repair',
        'damaged' => 'Mark Damaged',
        'retire' => 'Retire Asset',
        'missing_components' => 'Missing Components',
        'review' => 'Escalate for Review',
    ];
    $primaryLabels = [
        'return_to_stock' => 'Verify & Return to Stock',
        'repair' => 'Verify & Send to Repair',
        'damaged' => 'Verify & Mark Damaged',
        'retire' => 'Verify & Retire Asset',
        'missing_components' => 'Verify & Flag Missing Components',
        'review' => 'Verify & Escalate',
    ];
@endphp

@section('content')
    <style>
        .verify-return-page { display:grid; gap:14px; padding-bottom:78px; }
        .verify-return-header { display:flex; justify-content:space-between; align-items:center; gap:12px; padding:14px 18px; border:1px solid #dbe4f0; background:#fff; border-radius:22px; box-shadow:0 18px 45px rgba(15,23,42,.06); }
        .verify-return-title { margin:0; font-size:28px; line-height:1.1; color:#0f172a; letter-spacing:0; }
        .vr-badge { display:inline-flex; align-items:center; justify-content:center; gap:6px; min-height:30px; padding:6px 12px; border-radius:999px; border:1px solid #dbe4f0; background:#f8fafc; color:#475569; font-size:12px; font-weight:900; text-transform:uppercase; letter-spacing:.04em; white-space:nowrap; }
        .vr-badge.warning { background:#fff7ed; border-color:#fed7aa; color:#b45309; }
        .vr-badge.soft { background:#eef6ff; border-color:#bfdbfe; color:#1d4ed8; }
        .verify-return-layout { display:grid; grid-template-columns:minmax(0,1.08fr) minmax(340px,.92fr); gap:14px; align-items:start; }
        .vr-card { border:1px solid #dbe4f0; background:#fff; border-radius:20px; box-shadow:0 14px 38px rgba(15,23,42,.05); overflow:hidden; }
        .vr-card-body { padding:16px; }
        .vr-section { display:grid; gap:12px; }
        .vr-section + .vr-section { margin-top:14px; padding-top:14px; border-top:1px solid #e8eef6; }
        .vr-section-title { margin:0; color:#0f172a; font-size:17px; font-weight:800; }
        .vr-section-subtitle { margin:2px 0 0; color:#64748b; font-size:13px; }
        .verify-return-form-grid, .verify-return-summary-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px; }
        .vr-field label { display:flex; gap:6px; align-items:center; margin-bottom:5px; color:#475569; font-size:12px; font-weight:900; text-transform:uppercase; letter-spacing:.04em; }
        .vr-input, .vr-select, .vr-textarea { width:100%; min-height:42px; padding:10px 12px; border:1px solid #cbd5e1; border-radius:13px; background:#fff; color:#0f172a; font-size:15px; outline:none; }
        .vr-select {
            appearance:none;
            padding-right:34px;
            background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='18' height='18' viewBox='0 0 24 24' fill='none' stroke='%23475569' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");
            background-repeat:no-repeat;
            background-position:right 11px center;
            background-size:16px;
        }
        .vr-textarea { min-height:92px; resize:vertical; }
        .vr-input:focus, .vr-select:focus, .vr-textarea:focus { border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,.12); }
        .vr-summary-card { padding:11px 12px; border:1px solid #e2e8f0; background:#f8fafc; border-radius:14px; min-width:0; }
        .vr-summary-label { color:#64748b; font-size:11px; font-weight:900; text-transform:uppercase; letter-spacing:.04em; }
        .vr-summary-value { margin-top:4px; color:#0f172a; font-size:15px; font-weight:850; overflow:hidden; text-overflow:ellipsis; }
        .vr-summary-note { margin-top:2px; color:#64748b; font-size:12px; }
        .vr-side { position:sticky; top:92px; display:grid; gap:14px; }
        .vr-accessory-list { display:grid; gap:8px; }
        .vr-accessory-row { display:grid; grid-template-columns:minmax(0,1fr) 150px; gap:8px; padding:9px; border:1px solid #e2e8f0; border-radius:14px; background:#fbfdff; }
        .vr-accessory-name { color:#0f172a; font-size:14px; font-weight:800; min-width:0; }
        .vr-accessory-meta { color:#64748b; font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.04em; }
        .vr-accessory-remarks { grid-column:1 / -1; min-height:34px; padding:8px 10px; font-size:13px; border:1px solid #dbe4f0; border-radius:11px; }
        .vr-photo-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:8px; }
        .vr-photo { display:grid; gap:5px; padding:10px; border:1px dashed #cbd5e1; border-radius:14px; background:#f8fafc; color:#475569; font-size:12px; font-weight:800; }
        .vr-actions { position:fixed; left:var(--sidebar-width, 0px); right:0; bottom:0; z-index:30; display:flex; justify-content:flex-end; gap:10px; padding:12px 22px calc(12px + env(safe-area-inset-bottom, 0px)); background:rgba(255,255,255,.92); border-top:1px solid #dbe4f0; backdrop-filter:blur(14px); }
        .verify-return-form-actions { display:flex; gap:10px; justify-content:flex-end; }
        .vr-btn { display:inline-flex; align-items:center; justify-content:center; min-height:42px; padding:10px 16px; border-radius:13px; border:1px solid #cbd5e1; background:#fff; color:#1f2937; font-weight:900; text-decoration:none; cursor:pointer; }
        .vr-btn.primary { min-width:220px; border-color:#2563eb; background:#2563eb; color:#fff; box-shadow:0 12px 26px rgba(37,99,235,.24); }
        .vr-btn.soft { background:#f8fafc; }
        .vr-btn.ghost { border-color:transparent; background:transparent; color:#475569; }
        .vr-alert { padding:12px 14px; border-radius:15px; background:#fff1f2; border:1px solid #fecaca; color:#991b1b; }
        .vr-helper { margin:7px 0 0; color:#64748b; font-size:12px; }
        .vr-serial-alert { padding:10px 12px; border-radius:14px; background:#fff7ed; border:1px solid #fed7aa; color:#9a3412; font-size:13px; }

        @media (max-width: 1023px) {
            .verify-return-layout { grid-template-columns:1fr; }
            .vr-side { position:static; }
            .vr-actions { left:0; }
        }
        @media (max-width: 767px) {
            .rn-detail-page, .verify-return-page { padding-bottom:calc(188px + env(safe-area-inset-bottom, 0px)); }
            .verify-return-header { align-items:flex-start; flex-direction:column; border-radius:18px; padding:13px; }
            .verify-return-title { font-size:24px; }
            .verify-return-summary-grid,
            .verify-return-form-grid,
            .vr-photo-grid,
            .vr-accessory-row { grid-template-columns:1fr !important; gap:9px !important; }
            .verify-return-summary-card,
            .verify-return-form-actions { min-width:0; }
            .verify-return-form-actions { justify-content:stretch !important; flex-direction:row; }
            .verify-return-form-actions > *,
            .vr-actions > * { width:auto; flex:1 1 0; }
            .verify-return-form-actions .rx-btn-soft,
            .verify-return-form-actions .rx-btn-primary { justify-content:center; }
            .vr-actions {
                left:8px;
                right:8px;
                bottom:86px;
                flex-direction:row;
                gap:6px;
                padding:8px;
                border:1px solid #dbe4f0;
                border-radius:18px;
                box-shadow:0 14px 34px rgba(15,23,42,.12);
            }
            .vr-card-body { padding:12px; }
            .vr-section { gap:9px; }
            .vr-section + .vr-section { margin-top:10px; padding-top:10px; }
            .vr-section-title { font-size:16px; }
            .vr-section-subtitle { font-size:12px; line-height:1.35; }
            .vr-summary-card {
                padding:9px 10px;
                border-radius:12px;
            }
            .vr-summary-label { font-size:10px; }
            .vr-summary-value {
                margin-top:3px;
                font-size:14px;
                line-height:1.25;
            }
            .vr-summary-note { font-size:11px; line-height:1.25; }
            .vr-accessory-row {
                padding:8px;
                border-radius:12px;
            }
            .vr-accessory-name { font-size:13px; }
            .vr-accessory-meta { font-size:10px; }
            .vr-input, .vr-select, .vr-textarea {
                min-height:38px;
                padding-top:8px;
                padding-bottom:8px;
                font-size:14px;
                border-radius:12px;
            }
            .vr-select {
                padding-right:32px;
                background-position:right 10px center;
            }
            .vr-accessory-remarks {
                min-height:36px;
                padding:8px 10px;
                font-size:12px;
            }
            .vr-btn {
                min-height:38px;
                padding:8px 10px;
                border-radius:12px;
                font-size:13px;
                line-height:1.1;
            }
            .vr-btn.primary { min-width:0; flex:1.25 1 0; }
            .rn-detail-page div,
            .rn-detail-page span,
            .rn-detail-page p,
            .rn-detail-page strong,
            .rn-detail-page a,
            .rn-detail-page label,
            .verify-return-page div,
            .verify-return-page span,
            .verify-return-page p,
            .verify-return-page strong,
            .verify-return-page a,
            .verify-return-page label {
                overflow-wrap:anywhere;
                word-break:break-word;
            }
        }
    </style>

    <div class="rn-detail-page verify-return-page">
        <div class="verify-return-header">
            <div>
                <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:7px;">
                    <span class="vr-badge warning">Awaiting Verification</span>
                    <span class="vr-badge soft">{{ $asset->serial_number ?: 'Serial pending' }}</span>
                </div>
                <h1 class="verify-return-title">Verify Returned Asset</h1>
                <p class="vr-section-subtitle">Check identity, accessories, condition, and routing before this unit re-enters stock.</p>
            </div>
            <a href="{{ route('assets.pending-verification') }}" class="vr-btn soft">Back to Queue</a>
        </div>

        @if ($errors->any())
            <div class="vr-alert">
                <strong>Please fix the following:</strong>
                <ul style="margin:8px 0 0; padding-left:18px;">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form id="returnVerificationForm" method="POST" action="{{ route('assets.verify-return.store', $asset) }}" enctype="multipart/form-data">
            @csrf
            @method('PUT')

            <div class="verify-return-layout">
                <div class="vr-card">
                    <div class="vr-card-body">
                        @if($asset->isSerialPending())
                            <div class="vr-serial-alert">
                                <strong>Serial pending:</strong> replace the placeholder with the real equipment serial before making it available.
                            </div>
                        @endif

                        <div class="vr-section">
                            <div>
                                <h2 class="vr-section-title">Asset Identification</h2>
                                <p class="vr-section-subtitle">Confirm the exact physical unit.</p>
                            </div>
                            <div class="verify-return-form-grid">
                                <div class="vr-field">
                                    <label for="serial_number">Serial Number</label>
                                    <input class="vr-input" type="text" id="serial_number" name="serial_number" value="{{ old('serial_number', $asset->serial_number) }}" required>
                                </div>
                                <div class="vr-field">
                                    <label for="barcode_value">Barcode</label>
                                    <input class="vr-input" type="text" id="barcode_value" name="barcode_value" value="{{ old('barcode_value', $asset->barcode_value) }}">
                                </div>
                                <div class="vr-field">
                                    <label for="manufacturing_year">Manufacturing Year</label>
                                    <input class="vr-input" type="number" id="manufacturing_year" name="manufacturing_year" min="1900" max="2100" value="{{ old('manufacturing_year') }}">
                                </div>
                                <div class="vr-field">
                                    <label for="model_variant">Model / Variant</label>
                                    <input class="vr-input" type="text" id="model_variant" name="model_variant" value="{{ old('model_variant', $asset->product?->display_model) }}" placeholder="Model, variant, version">
                                </div>
                            </div>
                        </div>

                        <div class="vr-section">
                            <div>
                                <h2 class="vr-section-title">Verification Details</h2>
                                <p class="vr-section-subtitle">Pick the final routing decision.</p>
                            </div>
                            <div class="verify-return-form-grid">
                                <div class="vr-field">
                                    <label for="condition_status">Overall Condition</label>
                                    <select class="vr-select" id="condition_status" name="condition_status" required>
                                        @foreach($conditionOptions as $value => $label)
                                            <option value="{{ $value }}" @selected(old('condition_status', $asset->condition_status ?: 'good') === $value)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="vr-field">
                                    <label for="verification_outcome">Verification Outcome</label>
                                    <select class="vr-select" id="verification_outcome" name="verification_outcome" required>
                                        @foreach($outcomeOptions as $value => $label)
                                            <option value="{{ $value }}" @selected(old('verification_outcome', 'return_to_stock') === $value)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            <div class="vr-field">
                                <label for="remarks">Remarks</label>
                                <textarea class="vr-textarea" id="remarks" name="remarks" rows="4" placeholder="Required for missing accessories, damage, repair, or retirement.">{{ old('remarks') }}</textarea>
                                <p id="verificationSuggestion" class="vr-helper">Referral to stock is allowed only after this verification is completed.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <aside class="vr-side">
                    <div class="vr-card">
                        <div class="vr-card-body vr-section">
                            <div>
                                <h2 class="vr-section-title">Asset Summary</h2>
                                <p class="vr-section-subtitle">{{ $asset->product?->name ?: 'Product not set' }}</p>
                            </div>
                            <div class="verify-return-summary-grid">
                                <div class="vr-summary-card verify-return-summary-card">
                                    <div class="vr-summary-label">Product</div>
                                    <div class="vr-summary-value">{{ $asset->product?->name ?: 'N/A' }}</div>
                                </div>
                                <div class="vr-summary-card verify-return-summary-card">
                                    <div class="vr-summary-label">Warehouse</div>
                                    <div class="vr-summary-value">{{ $asset->warehouse?->name ?: 'N/A' }}</div>
                                </div>
                                <div class="vr-summary-card verify-return-summary-card">
                                    <div class="vr-summary-label">Rental</div>
                                    <div class="vr-summary-value">
                                        @if($rental)
                                            <a href="{{ route('rentals.show', $rental) }}" style="color:#2563eb; text-decoration:none;">Rental #{{ $rental->id }}</a>
                                        @else
                                            -
                                        @endif
                                    </div>
                                    <div class="vr-summary-note">{{ $customerName }}</div>
                                </div>
                                <div class="vr-summary-card verify-return-summary-card">
                                    <div class="vr-summary-label">Return Date</div>
                                    <div class="vr-summary-value">{{ optional($returnDate)->format('d M Y, h:i A') ?: '-' }}</div>
                                </div>
                                <div class="vr-summary-card verify-return-summary-card">
                                    <div class="vr-summary-label">Current Condition</div>
                                    <div class="vr-summary-value">{{ str_replace('_', ' ', $asset->condition_status ?: 'Not recorded') }}</div>
                                </div>
                                <div class="vr-summary-card verify-return-summary-card">
                                    <div class="vr-summary-label">Asset Health</div>
                                    <div class="vr-summary-value">{{ $asset->rentalAssignments->count() }} rentals</div>
                                    <div class="vr-summary-note">Last move: {{ optional($asset->movements->first()?->created_at)->diffForHumans() ?: '-' }}</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="vr-card">
                        <div class="vr-card-body vr-section">
                            <div style="display:flex; justify-content:space-between; gap:10px; align-items:flex-start;">
                                <div>
                                    <h2 class="vr-section-title">Accessories</h2>
                                    <p class="vr-section-subtitle">Checklist is selected from product template or equipment type.</p>
                                </div>
                                <button type="button" class="vr-btn ghost" data-select-returned style="min-height:32px; padding:6px 8px; font-size:12px;">All returned</button>
                            </div>
                            <div class="vr-accessory-list" id="accessoryList">
                                @forelse($accessoryChecklist as $index => $item)
                                    <div class="vr-accessory-row">
                                        <div>
                                            <div class="vr-accessory-name">{{ $item['name'] }}</div>
                                            <div class="vr-accessory-meta">{{ ($item['required'] ?? true) ? 'Required' : 'Optional' }} · {{ $item['source'] ?? 'template' }}</div>
                                            <input type="hidden" name="accessories[{{ $index }}][name]" value="{{ $item['name'] }}">
                                        </div>
                                        <select class="vr-select accessory-status" name="accessories[{{ $index }}][status]">
                                            <option value="returned" @selected(old("accessories.$index.status", 'returned') === 'returned')>Returned</option>
                                            <option value="missing" @selected(old("accessories.$index.status") === 'missing')>Missing</option>
                                            <option value="damaged" @selected(old("accessories.$index.status") === 'damaged')>Damaged</option>
                                            <option value="not_applicable" @selected(old("accessories.$index.status") === 'not_applicable')>N/A</option>
                                        </select>
                                        <input class="vr-accessory-remarks" type="text" name="accessories[{{ $index }}][remarks]" value="{{ old("accessories.$index.remarks") }}" placeholder="Accessory note if needed">
                                    </div>
                                @empty
                                    <div class="vr-summary-card">No template matched. Add accessories manually if needed.</div>
                                @endforelse
                            </div>
                            <button type="button" class="vr-btn soft" data-add-accessory style="justify-self:start;">Add Custom Accessory</button>
                        </div>
                    </div>

                    <div class="vr-card">
                        <div class="vr-card-body vr-section">
                            <div>
                                <h2 class="vr-section-title">Photos</h2>
                                <p class="vr-section-subtitle">Photo required when condition is damaged or needs repair.</p>
                            </div>
                            <div class="vr-photo-grid">
                                <label class="vr-photo">Overall Asset<input type="file" name="photos[overall]" accept="image/*"></label>
                                <label class="vr-photo">Serial / Barcode<input type="file" name="photos[serial_barcode]" accept="image/*"></label>
                                <label class="vr-photo">Damage Photo<input type="file" name="photos[damage]" accept="image/*"></label>
                                <label class="vr-photo">Missing Accessory<input type="file" name="photos[missing_accessory]" accept="image/*"></label>
                            </div>
                        </div>
                    </div>
                </aside>
            </div>

            <div class="vr-actions verify-return-form-actions">
                <a href="{{ route('assets.pending-verification') }}" class="vr-btn soft">Cancel</a>
                <button type="submit" name="save_draft" value="1" formnovalidate class="vr-btn soft">Save Draft</button>
                <button type="submit" class="vr-btn primary" id="verifyReturnCta">{{ $primaryLabels[old('verification_outcome', 'return_to_stock')] ?? 'Verify & Complete Return' }}</button>
            </div>
        </form>
    </div>

    <script>
        (() => {
            const outcome = document.getElementById('verification_outcome');
            const condition = document.getElementById('condition_status');
            const cta = document.getElementById('verifyReturnCta');
            const suggestion = document.getElementById('verificationSuggestion');
            const labels = @json($primaryLabels);
            const accessoryList = document.getElementById('accessoryList');
            let customIndex = 0;

            const updateCta = () => {
                const value = outcome?.value || 'return_to_stock';
                if (cta) cta.textContent = labels[value] || 'Verify & Complete Return';
                if (condition) {
                    if (value === 'repair' && condition.value === 'good') condition.value = 'needs_repair';
                    if (value === 'damaged') condition.value = 'damaged';
                    if (value === 'retire') condition.value = 'retired';
                }
            };

            const hasAccessoryIssue = () => [...document.querySelectorAll('.accessory-status')]
                .some(select => ['missing', 'damaged'].includes(select.value));

            const updateSuggestion = () => {
                if (!suggestion) return;
                suggestion.textContent = hasAccessoryIssue()
                    ? 'Missing or damaged accessories need remarks. Consider Missing Components or Send to Repair.'
                    : 'Return to stock is allowed only after this verification is completed.';
            };

            outcome?.addEventListener('change', updateCta);
            document.addEventListener('change', (event) => {
                if (event.target.matches('.accessory-status')) {
                    updateSuggestion();
                    if (['missing', 'damaged'].includes(event.target.value) && outcome?.value === 'return_to_stock') {
                        outcome.value = event.target.value === 'missing' ? 'missing_components' : 'repair';
                        updateCta();
                    }
                }
            });

            document.querySelector('[data-select-returned]')?.addEventListener('click', () => {
                document.querySelectorAll('.accessory-status').forEach(select => select.value = 'returned');
                updateSuggestion();
            });

            document.querySelector('[data-add-accessory]')?.addEventListener('click', () => {
                const key = customIndex++;
                const row = document.createElement('div');
                row.className = 'vr-accessory-row';
                row.innerHTML = `
                    <div>
                        <input class="vr-input" name="custom_accessories[${key}][name]" placeholder="Accessory name">
                        <div class="vr-accessory-meta" style="margin-top:4px;">Custom</div>
                    </div>
                    <select class="vr-select accessory-status" name="custom_accessories[${key}][status]">
                        <option value="returned">Returned</option>
                        <option value="missing">Missing</option>
                        <option value="damaged">Damaged</option>
                        <option value="not_applicable">N/A</option>
                    </select>
                    <input class="vr-accessory-remarks" type="text" name="custom_accessories[${key}][remarks]" placeholder="Accessory note if needed">
                `;
                accessoryList?.appendChild(row);
            });

            updateCta();
            updateSuggestion();
        })();
    </script>
@endsection
