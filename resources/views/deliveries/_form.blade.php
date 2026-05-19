@php
    $isEdit = isset($delivery);
    $selectedAssignmentType = old('assignment_type', $isEdit ? ($delivery->assignment_type ?? 'delivery_team') : 'delivery_team');
    $selectedTypeValue = old('type', $isEdit ? $delivery->type : ($selectedType ?? 'delivery'));
    $selectedStatusValue = old('status', $isEdit ? $delivery->status : 'pending');
    $selectedCancellationReason = old('cancellation_reason', $isEdit ? $delivery->cancellation_reason : '');
    $selectedCancellationNotes = old('cancellation_notes', $isEdit ? $delivery->cancellation_notes : '');
    $selectedRentalValue = old('rental_id', $isEdit ? $delivery->rental_id : ($selectedRentalId ?? null));
    $selectedSaleValue = old('sale_id', $isEdit ? ($delivery->sale_id ?? null) : ($selectedSaleId ?? null));
    $selectedSaleAssetValue = old('sale_asset_id', $isEdit ? ($delivery->sale?->asset_id ?? null) : null);
    $hasSaleSupport = $hasSaleSupport ?? \Illuminate\Support\Facades\Schema::hasColumn('deliveries', 'sale_id');
    $saleAssetOptions = collect($saleAssets ?? collect())->map(function ($asset) {
        return [
            'id' => $asset->id,
            'product_id' => $asset->product_id,
            'label' => collect([
                $asset->serial_number ?: ($asset->asset_name ?: ('Asset #' . $asset->id)),
                $asset->product?->name,
                $asset->warehouse?->name,
            ])->filter()->implode(' | '),
        ];
    })->values()->all();
    $hasFieldError = fn (string $field) => $errors->has($field);
    $fieldError = fn (string $field) => $errors->first($field);
    $cancellationReasonOptions = $cancellationReasonOptions ?? [];
    $statusDisplayLabel = function (string $type, ?string $status): string {
        return match ($status) {
            'completed' => $type === 'pickup' ? 'Picked Up' : 'Delivered',
            'in_progress' => 'In Progress',
            'cancelled' => 'Cancelled',
            default => 'Pending',
        };
    };
    $thirdPartyPhoneParts = \App\Support\PhoneNumber::split(old('third_party_phone', $isEdit ? $delivery->third_party_phone : ''));
    $countryCodeOptions = \App\Support\PhoneNumber::countryCodeOptions();
@endphp

<style>
    .ops-shell { display:grid; gap:16px; }
    .ops-header { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap; }
    .ops-header h1 { margin:0; font-size:26px; color:#0f172a; }
    .ops-header p { margin:6px 0 0; color:#64748b; font-size:13px; }
    .ops-actions { display:flex; gap:8px; flex-wrap:wrap; }
    .ops-card { background:#fff; border:1px solid #dbe3ef; border-radius:14px; padding:16px; box-shadow:0 8px 24px rgba(15, 23, 42, 0.04); }
    .ops-card h2 { margin:0 0 4px; font-size:16px; color:#0f172a; }
    .ops-card p.section-copy { margin:0 0 10px; font-size:12px; color:#64748b; }
    .ops-grid { display:grid; grid-template-columns:repeat(12, minmax(0, 1fr)); gap:14px; }
    .span-3 { grid-column:span 3; }
    .span-4 { grid-column:span 4; }
    .span-6 { grid-column:span 6; }
    .span-12 { grid-column:span 12; }
    .ops-field { display:flex; flex-direction:column; gap:6px; }
    .ops-field label { font-size:12px; font-weight:700; letter-spacing:.03em; text-transform:uppercase; color:#334155; }
    .ops-field input,
    .ops-field select,
    .ops-field textarea {
        width:100%;
        border:1px solid #cbd5e1;
        border-radius:10px;
        padding:9px 11px;
        font-size:13px;
        color:#0f172a;
        background:#fff;
        box-sizing:border-box;
    }
    .ops-field textarea { min-height:110px; resize:vertical; }
    .ops-field input:focus,
    .ops-field select:focus,
    .ops-field textarea:focus {
        outline:none;
        border-color:#2563eb;
        box-shadow:0 0 0 3px rgba(37, 99, 235, 0.12);
    }
    .ops-field.is-error input,
    .ops-field.is-error select,
    .ops-field.is-error textarea {
        border-color:#dc2626;
        box-shadow:0 0 0 3px rgba(220, 38, 38, 0.12);
        background:#fff7f7;
    }
    .ops-field-error {
        color:#b91c1c;
        font-size:12px;
        line-height:1.4;
    }
    .ops-note { padding:10px 12px; border-radius:10px; background:#f8fafc; border:1px solid #e2e8f0; font-size:12px; color:#475569; }
    .ops-error { border:1px solid #fecaca; background:#fef2f2; color:#b91c1c; border-radius:12px; padding:14px 16px; }
    .ops-button,
    .ops-button-secondary {
        display:inline-flex; align-items:center; justify-content:center; gap:6px;
        border-radius:10px; padding:8px 12px; font-size:12px; font-weight:600; text-decoration:none;
        border:1px solid transparent; cursor:pointer;
    }
    .ops-button { background:#2563eb; color:#fff; }
    .ops-button-secondary { background:#fff; border-color:#cbd5e1; color:#334155; }
    .summary-grid { display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:10px; }
    .summary-box { border:1px solid #e2e8f0; border-radius:12px; padding:12px; background:#f8fafc; }
    .summary-box span { display:block; font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:.04em; }
    .summary-box strong { display:block; margin-top:6px; font-size:18px; color:#0f172a; }
    @media (max-width: 980px) {
        .span-3, .span-4, .span-6 { grid-column:span 12; }
        .summary-grid { grid-template-columns:repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 640px) {
        .ops-header {
            flex-direction: column;
        }
        .ops-actions {
            width: 100%;
        }
        .ops-actions > * {
            flex: 1 1 calc(50% - 8px);
            min-width: 0;
        }
        .ops-button,
        .ops-button-secondary {
            min-height: 44px;
        }
        #third_party_block .ops-grid > .ops-field span,
        #third_party_block .ops-grid > .ops-field div,
        #third_party_block .ops-grid > .ops-field input {
            min-width: 0;
        }
        .summary-grid .summary-box:last-child {
            grid-column: 1 / -1;
        }
        .ops-card {
            padding: 14px;
        }
    }
</style>

<div class="ops-shell">
    <div class="ops-header">
        <div>
            <h1>{{ $isEdit ? 'Edit Assignment' : 'Add Assignment' }}</h1>
            <p>Compact logistics form for delivery, pickup, vendor, or third-party assignment.</p>
        </div>
        <div class="ops-actions">
            <a href="{{ route('deliveries.index') }}" class="ops-button-secondary">Back</a>
            @if($isEdit)
                <a href="{{ route('deliveries.show', $delivery) }}" class="ops-button-secondary">View</a>
            @endif
        </div>
    </div>

    @if ($errors->any())
        <div class="ops-error">
            <strong>Please fix the logistics form below.</strong>
            <ul style="margin:8px 0 0 18px; padding:0;">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="ops-card">
        <h2>Assignment Summary</h2>
        <p class="section-copy">Keep the assignment small and practical for dispatch teams.</p>
        <div class="summary-grid">
            <div class="summary-box">
                <span>Type</span>
                <strong id="summaryType">{{ ucfirst($selectedTypeValue) }}</strong>
            </div>
            <div class="summary-box">
                <span>Assignment</span>
                <strong id="summaryAssignment">{{ ucwords(str_replace('_', ' ', $selectedAssignmentType)) }}</strong>
            </div>
            <div class="summary-box">
                <span>Status</span>
                <strong id="summaryStatus">{{ $statusDisplayLabel($selectedTypeValue, $selectedStatusValue) }}</strong>
            </div>
        </div>
    </div>

    <div class="ops-card">
        <h2>Core Details</h2>
        <p class="section-copy">Core order, schedule, and assignment details.</p>
        <div class="ops-grid">
            <div class="ops-field {{ $hasFieldError('rental_id') ? 'is-error ' : '' }}{{ $hasSaleSupport ? 'span-6' : 'span-12' }}">
                <label for="rental_id">Rental</label>
                <select name="rental_id" id="rental_id">
                    <option value="">No rental link</option>
                    @foreach($rentals as $rentalOption)
                        <option
                            value="{{ $rentalOption->id }}"
                            data-warehouse="{{ $rentalOption->dispatchWarehouse->name ?? 'Any warehouse' }}"
                            {{ (int) $selectedRentalValue === $rentalOption->id ? 'selected' : '' }}>
                            #{{ $rentalOption->id }} • {{ $rentalOption->customer_name }} • {{ $rentalOption->product->name ?? 'N/A' }}
                        </option>
                    @endforeach
                </select>
                @if($fieldError('rental_id'))
                    <div class="ops-field-error">{{ $fieldError('rental_id') }}</div>
                @endif
            </div>

            @if($hasSaleSupport)
            <div class="ops-field span-6 {{ $hasFieldError('sale_id') ? 'is-error' : '' }}">
                <label for="sale_id">Sale Order</label>
                <select name="sale_id" id="sale_id">
                    <option value="">No sale link</option>
                    @foreach(($sales ?? collect()) as $saleOption)
                        <option
                            value="{{ $saleOption->id }}"
                            data-product-id="{{ $saleOption->product_id }}"
                            data-warehouse="{{ $saleOption->asset?->warehouse?->name ?? 'Sale dispatch / any warehouse' }}"
                            {{ (int) $selectedSaleValue === $saleOption->id ? 'selected' : '' }}>
                            #{{ $saleOption->id }} - {{ $saleOption->customer?->name ?? 'Customer' }} - {{ $saleOption->product?->name ?? 'Sale product' }}
                        </option>
                    @endforeach
                </select>
                @if($fieldError('sale_id'))
                    <div class="ops-field-error">{{ $fieldError('sale_id') }}</div>
                @endif
                <span style="font-size:11px; color:#94a3b8;">Use this to assign delivery for a direct sale order.</span>
            </div>
            @endif

            @if($hasSaleSupport)
            <div class="ops-field span-6 {{ $hasFieldError('sale_asset_id') ? 'is-error' : '' }}" id="sale_asset_block" style="{{ $selectedSaleValue ? '' : 'display:none;' }}">
                <label for="sale_asset_id">Sale Asset</label>
                <select name="sale_asset_id" id="sale_asset_id">
                    <option value="">No asset link</option>
                </select>
                @if($fieldError('sale_asset_id'))
                    <div class="ops-field-error">{{ $fieldError('sale_asset_id') }}</div>
                @endif
                <span style="font-size:11px; color:#94a3b8;">Delivery team can link the asset here if it was missed in Sales.</span>
            </div>
            @endif

            <div class="ops-field span-3 {{ $hasFieldError('type') ? 'is-error' : '' }}">
                <label for="type">Type</label>
                <select name="type" id="type" required>
                    <option value="delivery" {{ $selectedTypeValue === 'delivery' ? 'selected' : '' }}>Delivery</option>
                    <option value="pickup" {{ $selectedTypeValue === 'pickup' ? 'selected' : '' }}>Pickup</option>
                </select>
                @if($fieldError('type'))
                    <div class="ops-field-error">{{ $fieldError('type') }}</div>
                @endif
            </div>

            <div class="ops-field span-3 {{ $hasFieldError('status') ? 'is-error' : '' }}">
                <label for="status">Status</label>
                <select name="status" id="status" required>
                    <option value="pending" {{ $selectedStatusValue === 'pending' ? 'selected' : '' }}>Pending</option>
                    <option value="in_progress" {{ $selectedStatusValue === 'in_progress' ? 'selected' : '' }}>In Progress</option>
                    <option value="completed" {{ $selectedStatusValue === 'completed' ? 'selected' : '' }} data-delivery-label="Delivered" data-pickup-label="Picked Up">{{ $statusDisplayLabel($selectedTypeValue, 'completed') }}</option>
                    <option value="cancelled" {{ $selectedStatusValue === 'cancelled' ? 'selected' : '' }}>Cancelled</option>
                </select>
                @if($fieldError('status'))
                    <div class="ops-field-error">{{ $fieldError('status') }}</div>
                @endif
            </div>

            <div class="ops-field span-4 {{ $hasFieldError('assignment_type') ? 'is-error' : '' }}">
                <label for="assignment_type">Assignment Role</label>
                <select name="assignment_type" id="assignment_type">
                    <option value="delivery_team" {{ $selectedAssignmentType === 'delivery_team' ? 'selected' : '' }}>Internal Delivery Staff</option>
                    <option value="vendor" {{ $selectedAssignmentType === 'vendor' ? 'selected' : '' }}>Vendor</option>
                    <option value="third_party" {{ $selectedAssignmentType === 'third_party' ? 'selected' : '' }}>Third Party</option>
                </select>
                @if($fieldError('assignment_type'))
                    <div class="ops-field-error">{{ $fieldError('assignment_type') }}</div>
                @endif
            </div>

            <div class="ops-field span-4 {{ $hasFieldError('assigned_user_id') ? 'is-error' : '' }}" id="user_assignment_block" style="{{ $selectedAssignmentType === 'delivery_team' ? '' : 'display:none;' }}">
                <label for="assigned_user_id">Assigned Delivery User</label>
                <select name="assigned_user_id" id="assigned_user_id">
                    <option value="">Select internal delivery user</option>
                    @foreach($assignableUsers as $user)
                        <option value="{{ $user->id }}" {{ (int) old('assigned_user_id', $isEdit ? $delivery->assigned_user_id : null) === $user->id ? 'selected' : '' }}>
                            {{ $user->name }} • {{ ucwords(str_replace('_', ' ', $user->effective_role ?? $user->role ?? 'delivery')) }}
                        </option>
                    @endforeach
                </select>
                @if($fieldError('assigned_user_id'))
                    <div class="ops-field-error">{{ $fieldError('assigned_user_id') }}</div>
                @endif
                <span style="font-size:11px; color:#94a3b8;">Only active internal delivery or pickup users are listed here.</span>
            </div>

            <div class="ops-field span-4 {{ $hasFieldError('assigned_staff_id') ? 'is-error' : '' }}" id="staff_assignment_block" style="{{ $selectedAssignmentType === 'vendor' ? '' : 'display:none;' }}">
                <label for="assigned_staff_id">Assigned Staff / Vendor</label>
                <select name="assigned_staff_id" id="assigned_staff_id">
                    <option value="">Select relevant delivery staff / vendor</option>
                    @foreach($assignableStaffMembers as $staff)
                        <option value="{{ $staff->id }}" {{ (int) old('assigned_staff_id', $isEdit ? $delivery->assigned_staff_id : null) === $staff->id ? 'selected' : '' }}>
                            {{ $staff->name }} • {{ ucwords(str_replace('_', ' ', $staff->assignment_role ?? $staff->role ?? 'delivery')) }}
                        </option>
                    @endforeach
                </select>
                @if($fieldError('assigned_staff_id'))
                    <div class="ops-field-error">{{ $fieldError('assigned_staff_id') }}</div>
                @endif
                <span style="font-size:11px; color:#94a3b8;">Use this when the assignment should go to a vendor or operational staff record.</span>
            </div>

            <div class="ops-field span-4 {{ $hasFieldError('scheduled_at') ? 'is-error' : '' }}">
                <label for="scheduled_at">Scheduled At</label>
                <input
                    type="datetime-local"
                    name="scheduled_at"
                    id="scheduled_at"
                    value="{{ old('scheduled_at', $isEdit && $delivery->scheduled_at ? $delivery->scheduled_at->format('Y-m-d\TH:i') : '') }}">
                @if($fieldError('scheduled_at'))
                    <div class="ops-field-error">{{ $fieldError('scheduled_at') }}</div>
                @endif
            </div>

            <div class="ops-field span-12">
                <div class="ops-note" id="warehouseInfo">
                    Dispatch warehouse:
                    @if($selectedSaleValue)
                        {{ optional(optional(($sales ?? collect())->firstWhere('id', (int) $selectedSaleValue))->asset)->warehouse->name ?? 'Sale dispatch / any warehouse' }}
                    @else
                        {{ optional(optional($rentals->firstWhere('id', (int) $selectedRentalValue))->dispatchWarehouse)->name ?? 'Any warehouse' }}
                    @endif
                </div>
            </div>

            <div id="cancellation_fields_block" class="span-12" style="{{ $selectedStatusValue === 'cancelled' ? '' : 'display:none;' }}">
                <div class="ops-grid">
                    <div class="ops-field span-4 {{ $hasFieldError('cancellation_reason') ? 'is-error' : '' }}">
                        <label for="cancellation_reason">Cancellation Reason</label>
                        <select name="cancellation_reason" id="cancellation_reason">
                            <option value="">Select reason</option>
                            @foreach($cancellationReasonOptions as $reasonValue => $reasonLabel)
                                <option value="{{ $reasonValue }}" {{ $selectedCancellationReason === $reasonValue ? 'selected' : '' }}>{{ $reasonLabel }}</option>
                            @endforeach
                        </select>
                        @if($fieldError('cancellation_reason'))
                            <div class="ops-field-error">{{ $fieldError('cancellation_reason') }}</div>
                        @endif
                    </div>
                    <div class="ops-field span-8 {{ $hasFieldError('cancellation_notes') ? 'is-error' : '' }}">
                        <label for="cancellation_notes">Cancellation Notes</label>
                        <textarea name="cancellation_notes" id="cancellation_notes" placeholder="Add additional context, especially when using Other.">{{ $selectedCancellationNotes }}</textarea>
                        @if($fieldError('cancellation_notes'))
                            <div class="ops-field-error">{{ $fieldError('cancellation_notes') }}</div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="ops-card" id="third_party_block" style="{{ $selectedAssignmentType === 'third_party' ? '' : 'display:none;' }}">
        <h2>Third Party Contact</h2>
        <p class="section-copy">Use this only when the work is outsourced to an external person or transporter.</p>
        <div class="ops-grid">
            <div class="ops-field span-4 {{ $hasFieldError('third_party_name') ? 'is-error' : '' }}">
                <label for="third_party_name">Company / Person</label>
                <input type="text" name="third_party_name" id="third_party_name" value="{{ old('third_party_name', $isEdit ? $delivery->third_party_name : '') }}">
                @if($fieldError('third_party_name'))
                    <div class="ops-field-error">{{ $fieldError('third_party_name') }}</div>
                @endif
            </div>
            <div class="ops-field span-4 {{ $hasFieldError('third_party_contact') ? 'is-error' : '' }}">
                <label for="third_party_contact">Contact Person</label>
                <input type="text" name="third_party_contact" id="third_party_contact" value="{{ old('third_party_contact', $isEdit ? $delivery->third_party_contact : '') }}">
                @if($fieldError('third_party_contact'))
                    <div class="ops-field-error">{{ $fieldError('third_party_contact') }}</div>
                @endif
            </div>
            <div class="ops-field span-4 {{ $hasFieldError('third_party_phone') ? 'is-error' : '' }}">
                <label for="third_party_phone">Phone</label>
                <div style="display:flex; align-items:center; border:1px solid #cbd5e1; border-radius:10px; overflow:visible; background:#fff; min-width:0;">
                    @include('partials.country-code-picker', [
                        'name' => 'third_party_phone_country_code',
                        'pickerId' => 'third_party_phone_country_code',
                        'value' => old('third_party_phone_country_code', $thirdPartyPhoneParts['code']),
                        'options' => $countryCodeOptions,
                        'dividerColor' => '#cbd5e1',
                        'width' => '92px',
                    ])
                    <input type="text" name="third_party_phone" id="third_party_phone" value="{{ $thirdPartyPhoneParts['local'] }}" inputmode="numeric" maxlength="15" pattern="[0-9]{6,15}" data-phone-local style="border:none; box-shadow:none; background:transparent;">
                </div>
                @if($fieldError('third_party_phone'))
                    <div class="ops-field-error">{{ $fieldError('third_party_phone') }}</div>
                @endif
            </div>
        </div>
    </div>

    <div class="ops-card">
        <h2>Notes</h2>
        <div class="ops-field span-12 {{ $hasFieldError('notes') ? 'is-error' : '' }}">
            <label for="notes">Dispatch Notes</label>
            <textarea name="notes" id="notes">{{ old('notes', $isEdit ? $delivery->notes : '') }}</textarea>
            @if($fieldError('notes'))
                <div class="ops-field-error">{{ $fieldError('notes') }}</div>
            @endif
        </div>
    </div>

    <div class="ops-header" style="margin-top:-4px;">
        <div class="ops-note">Keep the assignment small and practical. Use the rental detail screen for broader workflow context.</div>
        <div class="ops-actions">
            <a href="{{ route('deliveries.index') }}" class="ops-button-secondary">Cancel</a>
            <button type="submit" class="ops-button">{{ $isEdit ? 'Update Assignment' : 'Save Assignment' }}</button>
        </div>
    </div>
</div>

<script>
    (function () {
        const rentalSelect = document.getElementById('rental_id');
        const saleSelect = document.getElementById('sale_id');
        const typeSelect = document.getElementById('type');
        const statusSelect = document.getElementById('status');
        const assignmentTypeSelect = document.getElementById('assignment_type');
        const userBlock = document.getElementById('user_assignment_block');
        const staffBlock = document.getElementById('staff_assignment_block');
        const thirdPartyBlock = document.getElementById('third_party_block');
        const userSelect = document.getElementById('assigned_user_id');
        const staffSelect = document.getElementById('assigned_staff_id');
        const warehouseInfo = document.getElementById('warehouseInfo');
        const summaryType = document.getElementById('summaryType');
        const summaryAssignment = document.getElementById('summaryAssignment');
        const summaryStatus = document.getElementById('summaryStatus');
        const saleAssetBlock = document.getElementById('sale_asset_block');
        const saleAssetSelect = document.getElementById('sale_asset_id');
        const cancellationFieldsBlock = document.getElementById('cancellation_fields_block');
        const cancellationReasonSelect = document.getElementById('cancellation_reason');
        const cancellationNotesField = document.getElementById('cancellation_notes');
        const saleAssetOptions = @json($saleAssetOptions);
        const selectedSaleAssetId = @json($selectedSaleAssetValue ? (int) $selectedSaleAssetValue : null);
        const initialSaleId = @json($selectedSaleValue ? (int) $selectedSaleValue : null);

        document.querySelectorAll('[data-phone-local]').forEach(function (input) {
            input.addEventListener('input', function () {
                input.value = input.value.replace(/\D+/g, '').slice(0, 15);
            });
        });

        function syncStatusLabels() {
            const completedOption = statusSelect.querySelector('option[value="completed"]');
            if (!completedOption) {
                return;
            }

            const deliveryLabel = completedOption.getAttribute('data-delivery-label') || 'Delivered';
            const pickupLabel = completedOption.getAttribute('data-pickup-label') || 'Picked Up';
            completedOption.textContent = typeSelect.value === 'pickup' ? pickupLabel : deliveryLabel;
        }

        function updateSummary() {
            syncStatusLabels();
            summaryType.textContent = typeSelect.options[typeSelect.selectedIndex].textContent.trim();
            summaryAssignment.textContent = assignmentTypeSelect.options[assignmentTypeSelect.selectedIndex].textContent.trim();
            summaryStatus.textContent = statusSelect.options[statusSelect.selectedIndex].textContent.trim();
        }

        function updateCancellationFields() {
            const isCancelled = statusSelect.value === 'cancelled';

            if (cancellationFieldsBlock) {
                cancellationFieldsBlock.style.display = isCancelled ? 'block' : 'none';
            }

            if (!isCancelled) {
                if (cancellationReasonSelect) {
                    cancellationReasonSelect.value = '';
                }

                if (cancellationNotesField) {
                    cancellationNotesField.value = '';
                }
            }
        }

        function updateAssignmentFields() {
            const assignmentType = assignmentTypeSelect.value;
            const isThirdParty = assignmentType === 'third_party';
            const isVendor = assignmentType === 'vendor';
            const isDeliveryTeam = assignmentType === 'delivery_team';

            userBlock.style.display = isDeliveryTeam ? 'flex' : 'none';
            staffBlock.style.display = isVendor ? 'flex' : 'none';
            thirdPartyBlock.style.display = isThirdParty ? 'block' : 'none';

            if (!isDeliveryTeam && userSelect) {
                userSelect.value = '';
            }

            if (!isVendor && staffSelect) {
                staffSelect.value = '';
            }

            updateSummary();
        }

        function updateWarehouse() {
            const saleSelected = saleSelect && saleSelect.value ? saleSelect.options[saleSelect.selectedIndex] : null;
            const rentalSelected = rentalSelect && rentalSelect.value ? rentalSelect.options[rentalSelect.selectedIndex] : null;
            const selected = saleSelected || rentalSelected;
            const warehouse = selected ? selected.getAttribute('data-warehouse') : 'Any warehouse';
            warehouseInfo.textContent = 'Dispatch warehouse: ' + (warehouse || 'Any warehouse');
        }

        function updateSaleAssetOptions() {
            if (!saleAssetBlock || !saleAssetSelect) {
                return;
            }

            const saleSelected = saleSelect && saleSelect.value ? saleSelect.options[saleSelect.selectedIndex] : null;
            const productId = saleSelected ? parseInt(saleSelected.getAttribute('data-product-id') || '0', 10) : 0;
            const currentSaleId = saleSelected ? parseInt(saleSelected.value || '0', 10) : 0;
            const currentSelection = parseInt(
                saleAssetSelect.value || ((initialSaleId && currentSaleId === initialSaleId) ? selectedSaleAssetId : 0) || '0',
                10
            );

            saleAssetBlock.style.display = productId > 0 ? 'flex' : 'none';

            if (!productId) {
                saleAssetSelect.innerHTML = '<option value="">No asset link</option>';
                return;
            }

            const options = ['<option value="">No asset link</option>'];
            const filtered = saleAssetOptions.filter(function (asset) {
                return parseInt(asset.product_id || '0', 10) === productId;
            });

            filtered.forEach(function (asset) {
                const isSelected = currentSelection === asset.id;
                options.push(`<option value="${asset.id}" ${isSelected ? 'selected' : ''}>${asset.label}</option>`);
            });

            saleAssetSelect.innerHTML = options.join('');
        }

        rentalSelect.addEventListener('change', function () {
            if (rentalSelect.value && saleSelect) {
                saleSelect.value = '';
            }
            updateWarehouse();
            updateSaleAssetOptions();
        });
        if (saleSelect) {
            saleSelect.addEventListener('change', function () {
                if (saleSelect.value && rentalSelect) {
                    rentalSelect.value = '';
                }
                if (saleSelect.value) {
                    typeSelect.value = 'delivery';
                    updateSummary();
                }
                updateWarehouse();
                updateSaleAssetOptions();
            });
        }
        typeSelect.addEventListener('change', updateSummary);
        statusSelect.addEventListener('change', updateSummary);
        statusSelect.addEventListener('change', updateCancellationFields);
        assignmentTypeSelect.addEventListener('change', updateAssignmentFields);

        updateWarehouse();
        updateAssignmentFields();
        updateSaleAssetOptions();
        updateSummary();
        updateCancellationFields();
    })();
</script>
