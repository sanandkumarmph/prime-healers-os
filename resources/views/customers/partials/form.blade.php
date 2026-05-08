@php
    $customerData = $customer ?? null;
    $fieldValue = function (string $field, $default = null) use ($customerData) {
        return old($field, $customerData?->{$field} ?? $default);
    };
    $customerTypeValue = $fieldValue('customer_type', $customerData?->normalizedCustomerType() ?? 'Individual');
    $whatsAppEnabled = \App\Models\Customer::hasWhatsappNumberColumn();
    $customerTypeOptions = collect($customerTypeOptions ?? ['Individual', 'Business'])
        ->mapWithKeys(fn ($value) => [$value => $value])
        ->all();
    $salutationOptions = collect($salutationOptions ?? ['Mr.', 'Mrs.', 'Ms.', 'Dr.'])
        ->mapWithKeys(fn ($value) => [$value => $value])
        ->all();
    $idProofTypeOptions = collect($idProofTypeOptions ?? ['Aadhaar', 'PAN', 'Driving License', 'Passport', 'Other'])
        ->mapWithKeys(fn ($value) => [$value => $value])
        ->all();
    $indianStates = $indianStates ?? \App\Models\Customer::indianStates();
    $countryCodeOptions = \App\Support\PhoneNumber::countryCodeOptions();
    $phoneParts = \App\Support\PhoneNumber::split($fieldValue('phone'));
    $whatsAppParts = \App\Support\PhoneNumber::split($fieldValue('whatsapp_number'));
    $existingProofUrl = null;

    if ($customerData && filled($customerData->id_proof_file_path)) {
        $existingProofUrl = route('customers.id-proof.download', $customerData->id);
    }
    $hasFieldError = fn (string $field) => $errors->has($field);
    $fieldError = fn (string $field) => $errors->first($field);
@endphp

<style>
    .customer-form-shell { display:grid; gap:16px; }
    .customer-type-switch {
        display:flex;
        gap:8px;
        flex-wrap:wrap;
        margin-bottom:4px;
    }
    .customer-type-pill {
        border:1px solid #cbd5e1;
        border-radius:999px;
        background:#fff;
        color:#475569;
        padding:8px 12px;
        font-size:12px;
        font-weight:700;
        letter-spacing:.03em;
        cursor:pointer;
        transition:all .15s ease;
    }
    .customer-type-pill.is-active {
        background:#0f172a;
        border-color:#0f172a;
        color:#fff;
    }
    .customer-type-help {
        color:#64748b;
        font-size:12px;
        margin:0;
    }
    .customer-form-grid { display:grid; grid-template-columns:repeat(12, minmax(0, 1fr)); gap:14px; }
    .customer-card {
        border:1px solid #dbe3ef;
        border-radius:14px;
        background:#fcfdff;
        padding:16px;
    }
    .customer-card h2 {
        margin:0;
        font-size:16px;
        color:#0f172a;
    }
    .customer-card p {
        margin:4px 0 0;
        color:#64748b;
        font-size:12px;
    }
    .span-4 { grid-column:span 4; }
    .span-5 { grid-column:span 5; }
    .span-6 { grid-column:span 6; }
    .span-7 { grid-column:span 7; }
    .span-8 { grid-column:span 8; }
    .span-12 { grid-column:span 12; }
    .field-grid { display:grid; grid-template-columns:repeat(12, minmax(0, 1fr)); gap:12px; margin-top:14px; }
    .field { display:grid; gap:6px; }
    .field label {
        font-size:11px;
        color:#64748b;
        font-weight:700;
        text-transform:uppercase;
        letter-spacing:.04em;
    }
    .field small {
        color:#94a3b8;
        font-size:11px;
        line-height:1.4;
    }
    .field input,
    .field select,
    .field textarea {
        width:100%;
        box-sizing:border-box;
        padding:9px 12px;
        border-radius:10px;
        border:1px solid #cbd5e1;
        background:#fff;
        color:#0f172a;
        font-size:14px;
        line-height:1.4;
    }
    .field input:focus,
    .field select:focus,
    .field textarea:focus {
        outline:none;
        border-color:#2563eb;
        box-shadow:0 0 0 3px rgba(37, 99, 235, 0.12);
    }
    .field.is-error input,
    .field.is-error select,
    .field.is-error textarea,
    .identity-panel.is-error {
        border-color:#dc2626;
        box-shadow:0 0 0 3px rgba(220, 38, 38, 0.12);
        background:#fff7f7;
    }
    .field-error {
        color:#b91c1c;
        font-size:12px;
        line-height:1.4;
    }
    .field textarea { min-height:92px; resize:vertical; }
    .phone-group {
        display:flex;
        align-items:center;
        border:1px solid #cbd5e1;
        border-radius:10px;
        overflow:visible;
        background:#fff;
        min-width:0;
    }
    .phone-group:focus-within {
        border-color:#2563eb;
        box-shadow:0 0 0 3px rgba(37, 99, 235, 0.12);
    }
    .phone-group .country-code-picker {
        flex:0 0 88px;
        min-width:88px;
        max-width:88px;
        align-self:stretch;
    }
    .phone-group .country-code-picker__trigger {
        min-height:42px;
        padding:10px 10px 10px 12px;
        box-shadow:none !important;
    }
    .phone-group input[data-phone-local] {
        flex:1 1 auto;
        min-width:0;
        width:1%;
        padding:10px 14px;
        border:none !important;
        box-shadow:none !important;
        background:transparent !important;
        border-radius:0 !important;
    }
    .field.is-error .phone-group {
        border-color:#dc2626;
        box-shadow:0 0 0 3px rgba(220, 38, 38, 0.12);
        background:#fff7f7;
    }
    .identity-panel {
        display:grid;
        gap:10px;
        padding:12px;
        border-radius:12px;
        border:1px solid #dbe3ef;
        background:#fff;
    }
    .identity-panel[hidden] { display:none !important; }
    .identity-grid {
        display:grid;
        grid-template-columns:repeat(3, minmax(0, 1fr));
        gap:12px;
    }
    .helper-box {
        border:1px solid #e2e8f0;
        background:#f8fafc;
        border-radius:12px;
        padding:11px 12px;
        color:#475569;
        font-size:12px;
        line-height:1.5;
    }
    .proof-chip {
        display:inline-flex;
        align-items:center;
        gap:6px;
        padding:7px 10px;
        border-radius:10px;
        background:#eff6ff;
        border:1px solid #bfdbfe;
        color:#1d4ed8;
        font-size:12px;
        font-weight:600;
        text-decoration:none;
    }
    .form-actions {
        display:flex;
        justify-content:flex-end;
        gap:8px;
        flex-wrap:wrap;
    }
    .ops-btn,
    .ops-btn-light {
        display:inline-flex;
        align-items:center;
        justify-content:center;
        gap:6px;
        padding:8px 13px;
        border-radius:10px;
        font-size:13px;
        font-weight:600;
        text-decoration:none;
        border:1px solid transparent;
        cursor:pointer;
    }
    .ops-btn { background:#0f172a; color:#fff; }
    .ops-btn-light { background:#fff; color:#334155; border-color:#cbd5e1; }
    @media (max-width: 1024px) {
        .span-4, .span-5, .span-6, .span-7, .span-8 { grid-column:span 12; }
    }
    @media (max-width: 720px) {
        .field-grid,
        .identity-grid { grid-template-columns:1fr; }
        .field-grid > div,
        .identity-grid > div { grid-column:span 1 !important; }
    }
</style>

<div class="customer-form-shell">
    <div>
        <div class="customer-type-switch" data-customer-type-switch>
            @foreach($customerTypeOptions as $value => $label)
                <button
                    type="button"
                    class="customer-type-pill {{ $customerTypeValue === $value ? 'is-active' : '' }}"
                    data-type-trigger="{{ $value }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>
        <p class="customer-type-help">
            Individual customers use salutation, first name, and last name. Business customers use company and contact name.
        </p>
    </div>

    <input type="hidden" name="customer_type" id="customer_type" value="{{ $customerTypeValue }}">
    <input type="hidden" name="name" id="display_name" value="{{ $fieldValue('name') }}">

    <div class="customer-form-grid">
        <div class="customer-card span-8">
            <h2>Customer Identity</h2>
            <p>Capture only the identity fields relevant to the selected customer type to keep records cleaner and easier to search.</p>

            <div class="field-grid">
                <div class="field span-12">
                    <div class="identity-panel" data-type-panel="Individual" {{ $customerTypeValue === 'Individual' ? '' : 'hidden' }}>
                        <div class="identity-grid">
                            <div class="field {{ $hasFieldError('salutation') ? 'is-error' : '' }}">
                                <label for="salutation">Salutation</label>
                                <select id="salutation" name="salutation">
                                    <option value="">Select</option>
                                    @foreach($salutationOptions as $value => $label)
                                        <option value="{{ $value }}" {{ $fieldValue('salutation') === $value ? 'selected' : '' }}>{{ $label }}</option>
                                    @endforeach
                                </select>
                                @if($fieldError('salutation'))
                                    <div class="field-error">{{ $fieldError('salutation') }}</div>
                                @endif
                            </div>
                            <div class="field {{ $hasFieldError('first_name') ? 'is-error' : '' }}">
                                <label for="first_name">First Name</label>
                                <input id="first_name" type="text" name="first_name" value="{{ $fieldValue('first_name') }}" placeholder="First name">
                                @if($fieldError('first_name'))
                                    <div class="field-error">{{ $fieldError('first_name') }}</div>
                                @endif
                            </div>
                            <div class="field {{ $hasFieldError('last_name') ? 'is-error' : '' }}">
                                <label for="last_name">Last Name</label>
                                <input id="last_name" type="text" name="last_name" value="{{ $fieldValue('last_name') }}" placeholder="Last name">
                                @if($fieldError('last_name'))
                                    <div class="field-error">{{ $fieldError('last_name') }}</div>
                                @endif
                            </div>
                        </div>
                        <small>Use this for patient, household, or personal rentals and sales.</small>
                    </div>

                    <div class="identity-panel" data-type-panel="Business" {{ $customerTypeValue === 'Business' ? '' : 'hidden' }}>
                        <div class="identity-grid" style="grid-template-columns:repeat(2, minmax(0, 1fr));">
                            <div class="field {{ $hasFieldError('company_name') ? 'is-error' : '' }}">
                                <label for="company_name">Company Name</label>
                                <input id="company_name" type="text" name="company_name" value="{{ $fieldValue('company_name') }}" placeholder="Company or business name">
                                @if($fieldError('company_name'))
                                    <div class="field-error">{{ $fieldError('company_name') }}</div>
                                @endif
                            </div>
                            <div class="field {{ $hasFieldError('contact_name') ? 'is-error' : '' }}">
                                <label for="contact_name">Contact Name</label>
                                <input id="contact_name" type="text" name="contact_name" value="{{ $fieldValue('contact_name') }}" placeholder="Primary contact person">
                                @if($fieldError('contact_name'))
                                    <div class="field-error">{{ $fieldError('contact_name') }}</div>
                                @endif
                            </div>
                        </div>
                        <small>Use this for hospitals, institutions, partners, and B2B accounts.</small>
                    </div>
                </div>

                <div class="field {{ $hasFieldError('phone') ? 'is-error' : '' }}" style="grid-column:span 6;">
                    <label for="phone">Phone</label>
                    <div class="phone-group">
                        @include('partials.country-code-picker', [
                            'name' => 'phone_country_code',
                            'pickerId' => 'phone_country_code',
                            'value' => old('phone_country_code', $phoneParts['code']),
                            'options' => $countryCodeOptions,
                            'dividerColor' => '#cbd5e1',
                            'width' => '92px',
                        ])
                        <input id="phone" type="text" name="phone" value="{{ $phoneParts['local'] }}" placeholder="Primary phone" inputmode="numeric" maxlength="15" pattern="[0-9]{6,15}" data-phone-local>
                    </div>
                    @if($fieldError('phone'))
                        <div class="field-error">{{ $fieldError('phone') }}</div>
                    @endif
                </div>

                @if($whatsAppEnabled)
                    <div class="field {{ $hasFieldError('whatsapp_number') ? 'is-error' : '' }}" style="grid-column:span 6;">
                        <label for="whatsapp_number">WhatsApp</label>
                        <div class="phone-group">
                            @include('partials.country-code-picker', [
                                'name' => 'whatsapp_number_country_code',
                                'pickerId' => 'whatsapp_number_country_code',
                                'value' => old('whatsapp_number_country_code', $whatsAppParts['code']),
                                'options' => $countryCodeOptions,
                                'dividerColor' => '#cbd5e1',
                                'width' => '92px',
                            ])
                            <input id="whatsapp_number" type="text" name="whatsapp_number" value="{{ $whatsAppParts['local'] }}" placeholder="WhatsApp number" inputmode="numeric" maxlength="15" pattern="[0-9]{6,15}" data-phone-local>
                        </div>
                        @if($fieldError('whatsapp_number'))
                            <div class="field-error">{{ $fieldError('whatsapp_number') }}</div>
                        @endif
                    </div>
                @endif

                <div class="field {{ $hasFieldError('email') ? 'is-error' : '' }}" style="grid-column:span 12;">
                    <label for="email">Email</label>
                    <input id="email" type="email" name="email" value="{{ $fieldValue('email') }}" placeholder="Email address">
                    @if($fieldError('email'))
                        <div class="field-error">{{ $fieldError('email') }}</div>
                    @endif
                </div>

                <div class="field {{ $hasFieldError('address') ? 'is-error' : '' }}" style="grid-column:span 6;">
                    <label for="address">Address</label>
                    <textarea id="address" name="address" placeholder="Street, building, landmark">{{ $fieldValue('address') }}</textarea>
                    @if($fieldError('address'))
                        <div class="field-error">{{ $fieldError('address') }}</div>
                    @endif
                </div>

                <div class="field {{ $hasFieldError('notes') ? 'is-error' : '' }}" style="grid-column:span 6;">
                    <label for="notes">Notes</label>
                    <textarea id="notes" name="notes" placeholder="Internal notes or handling remarks">{{ $fieldValue('notes') }}</textarea>
                    @if($fieldError('notes'))
                        <div class="field-error">{{ $fieldError('notes') }}</div>
                    @endif
                </div>
            </div>
        </div>

        <div class="customer-card span-4">
            <h2>Billing & Compliance</h2>
            <p>Keep tax details and optional ID proof visible without making the form heavy.</p>

            <div class="field-grid">
                <div class="field {{ $hasFieldError('gst_number') ? 'is-error' : '' }}" style="grid-column:span 12;">
                    <label for="gst_number">GST Number</label>
                    <input id="gst_number" type="text" name="gst_number" value="{{ $fieldValue('gst_number') }}" placeholder="GST number">
                    @if($fieldError('gst_number'))
                        <div class="field-error">{{ $fieldError('gst_number') }}</div>
                    @endif
                </div>

                <div class="field {{ $hasFieldError('id_proof_type') ? 'is-error' : '' }}" style="grid-column:span 12;">
                    <label for="id_proof_type">ID Proof Type</label>
                    <select id="id_proof_type" name="id_proof_type">
                        <option value="">Select proof type</option>
                        @foreach($idProofTypeOptions as $value => $label)
                            <option value="{{ $value }}" {{ $fieldValue('id_proof_type') === $value ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                    @if($fieldError('id_proof_type'))
                        <div class="field-error">{{ $fieldError('id_proof_type') }}</div>
                    @endif
                </div>

                <div class="field {{ $hasFieldError('id_proof_number') ? 'is-error' : '' }}" style="grid-column:span 12;">
                    <label for="id_proof_number">ID Proof Number</label>
                    <input id="id_proof_number" type="text" name="id_proof_number" value="{{ $fieldValue('id_proof_number') }}" placeholder="Proof number">
                    @if($fieldError('id_proof_number'))
                        <div class="field-error">{{ $fieldError('id_proof_number') }}</div>
                    @endif
                </div>

                <div class="field {{ $hasFieldError('id_proof_file') ? 'is-error' : '' }}" style="grid-column:span 12;">
                    <label for="id_proof_file">ID Proof Upload</label>
                    <input id="id_proof_file" type="file" name="id_proof_file">
                    @if($fieldError('id_proof_file'))
                        <div class="field-error">{{ $fieldError('id_proof_file') }}</div>
                    @endif
                    <small>Optional document for verification. Keep uploads lightweight and readable.</small>
                    @if($existingProofUrl)
                        <a href="{{ $existingProofUrl }}" target="_blank" class="proof-chip">
                            View Existing Proof
                            @if(filled($customerData?->id_proof_original_name))
                                <span>{{ $customerData->id_proof_original_name }}</span>
                            @endif
                        </a>
                    @endif
                </div>
            </div>
        </div>

        <div class="customer-card span-12">
            <h2>Location & Delivery Reference</h2>
            <p>Keep address, state, and map reference easy for delivery teams and vendors to reuse.</p>

            <div class="field-grid">
                <div class="field {{ $hasFieldError('city') ? 'is-error' : '' }}" style="grid-column:span 3;">
                    <label for="city">City</label>
                    <input id="city" type="text" name="city" value="{{ $fieldValue('city') }}" placeholder="City">
                    @if($fieldError('city'))
                        <div class="field-error">{{ $fieldError('city') }}</div>
                    @endif
                </div>

                <div class="field {{ $hasFieldError('state') ? 'is-error' : '' }}" style="grid-column:span 3;">
                    <label for="state">State</label>
                    <select id="state" name="state">
                        <option value="">Select state</option>
                        @foreach($indianStates as $state)
                            <option value="{{ $state }}" {{ $fieldValue('state') === $state ? 'selected' : '' }}>{{ $state }}</option>
                        @endforeach
                    </select>
                    @if($fieldError('state'))
                        <div class="field-error">{{ $fieldError('state') }}</div>
                    @endif
                </div>

                <div class="field {{ $hasFieldError('pincode') ? 'is-error' : '' }}" style="grid-column:span 3;">
                    <label for="pincode">Pincode</label>
                    <input id="pincode" type="text" name="pincode" value="{{ $fieldValue('pincode') }}" placeholder="Pincode">
                    @if($fieldError('pincode'))
                        <div class="field-error">{{ $fieldError('pincode') }}</div>
                    @endif
                </div>

                <div class="field {{ $hasFieldError('place_of_supply') ? 'is-error' : '' }}" style="grid-column:span 3;">
                    <label for="place_of_supply">Place of Supply</label>
                    <input id="place_of_supply" type="text" name="place_of_supply" value="{{ $fieldValue('place_of_supply') }}" placeholder="GST place of supply">
                    @if($fieldError('place_of_supply'))
                        <div class="field-error">{{ $fieldError('place_of_supply') }}</div>
                    @endif
                </div>

                <div class="field {{ $hasFieldError('map_location_text') ? 'is-error' : '' }}" style="grid-column:span 6;">
                    <label for="map_location_text">Map Location Text</label>
                    <input id="map_location_text" type="text" name="map_location_text" value="{{ $fieldValue('map_location_text') }}" placeholder="Landmark or map label">
                    @if($fieldError('map_location_text'))
                        <div class="field-error">{{ $fieldError('map_location_text') }}</div>
                    @endif
                </div>

                <div class="field {{ $hasFieldError('map_location_url') ? 'is-error' : '' }}" style="grid-column:span 6;">
                    <label for="map_location_url">Map Location URL</label>
                    <input id="map_location_url" type="url" name="map_location_url" value="{{ $fieldValue('map_location_url') }}" placeholder="https://maps.google.com/...">
                    @if($fieldError('map_location_url'))
                        <div class="field-error">{{ $fieldError('map_location_url') }}</div>
                    @endif
                </div>

                <div class="field" style="grid-column:span 12;">
                    <div class="helper-box">
                        Save a map link when deliveries or pickups need quick navigation support. This stays lightweight and works well for staff, vendors, and third-party logistics.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="form-actions">
        <a href="{{ $cancelUrl }}" class="ops-btn-light">Cancel</a>
        <button type="submit" class="ops-btn">{{ $submitLabel }}</button>
    </div>
</div>

<script>
    (function () {
        const customerTypeInput = document.getElementById('customer_type');
        const displayNameInput = document.getElementById('display_name');
        const typeButtons = document.querySelectorAll('[data-type-trigger]');
        const typePanels = document.querySelectorAll('[data-type-panel]');
        const salutation = document.getElementById('salutation');
        const firstName = document.getElementById('first_name');
        const lastName = document.getElementById('last_name');
        const companyName = document.getElementById('company_name');
        const contactName = document.getElementById('contact_name');

        function buildDisplayName(type) {
            if (type === 'Business') {
                return [companyName ? companyName.value : '', contactName ? contactName.value : '']
                    .filter(Boolean)
                    .join(' - ');
            }

            return [salutation ? salutation.value : '', firstName ? firstName.value : '', lastName ? lastName.value : '']
                .filter(Boolean)
                .join(' ');
        }

        function syncType(type) {
            customerTypeInput.value = type;

            typeButtons.forEach(function (button) {
                button.classList.toggle('is-active', button.getAttribute('data-type-trigger') === type);
            });

            typePanels.forEach(function (panel) {
                panel.hidden = panel.getAttribute('data-type-panel') !== type;
            });

            displayNameInput.value = buildDisplayName(type);
        }

        typeButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                syncType(button.getAttribute('data-type-trigger'));
            });
        });

        [salutation, firstName, lastName, companyName, contactName].forEach(function (input) {
            if (!input) {
                return;
            }

            input.addEventListener('input', function () {
                displayNameInput.value = buildDisplayName(customerTypeInput.value);
            });

            input.addEventListener('change', function () {
                displayNameInput.value = buildDisplayName(customerTypeInput.value);
            });
        });

        syncType(customerTypeInput.value || 'Individual');
    })();

    document.querySelectorAll('[data-phone-local]').forEach(function (input) {
        input.addEventListener('input', function () {
            input.value = input.value.replace(/\D+/g, '').slice(0, 15);
        });
    });
</script>
