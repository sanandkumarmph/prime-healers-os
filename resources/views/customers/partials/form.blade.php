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
    $gstOpen = $errors->hasAny(['gst_registered', 'gst_number', 'legal_name', 'billing_address'])
        || filled($fieldValue('gst_number'))
        || filled($fieldValue('legal_name'))
        || filled($fieldValue('billing_address'))
        || (string) $fieldValue('gst_registered', $customerData?->gst_registered ? '1' : '0') === '1';
    $idProofOpen = $errors->hasAny(['id_proof_type', 'id_proof_number', 'id_proof_file'])
        || filled($fieldValue('id_proof_type'))
        || filled($fieldValue('id_proof_number'))
        || $existingProofUrl !== null;
    $additionalContactOpen = $errors->has('email')
        || filled($fieldValue('email'));
    $addressOpen = $errors->hasAny(['address', 'city', 'state', 'pincode', 'place_of_supply', 'map_location_text', 'map_location_url'])
        || filled($fieldValue('address'))
        || filled($fieldValue('city'))
        || filled($fieldValue('state'))
        || filled($fieldValue('pincode'))
        || filled($fieldValue('place_of_supply'))
        || filled($fieldValue('map_location_text'))
        || filled($fieldValue('map_location_url'));
    $notesOpen = $errors->has('notes')
        || filled($fieldValue('notes'));
    $identityOpen = $errors->hasAny(['salutation', 'last_name'])
        || filled($fieldValue('salutation'))
        || filled($fieldValue('last_name'));
    $sameBillingAsAddress = old('same_as_customer_address', ($customerData?->billing_address ?? null) === ($customerData?->address ?? null) ? '1' : '0') === '1';
@endphp

<style>
    .customer-form-shell { display:grid; gap:18px; }
    .mobile-only { display:none !important; }
    .customer-type-switch {
        display:inline-flex;
        gap:6px;
        padding:4px;
        border:1px solid #dbe3ef;
        border-radius:999px;
        background:#f8fafc;
        flex-wrap:wrap;
    }
    .customer-type-pill {
        border:none;
        border-radius:999px;
        background:transparent;
        color:#475569;
        padding:9px 14px;
        font-size:13px;
        font-weight:700;
        cursor:pointer;
        transition:all .15s ease;
    }
    .customer-type-pill.is-active {
        background:#0f172a;
        color:#fff;
        box-shadow:0 10px 24px rgba(15, 23, 42, 0.12);
    }
    .customer-type-help {
        margin:8px 0 0;
        color:#64748b;
        font-size:12px;
        line-height:1.5;
    }
    .customer-sections {
        display:grid;
        gap:14px;
    }
    .customer-section {
        border:1px solid #e2e8f0;
        border-radius:18px;
        background:#fff;
        padding:18px;
        display:grid;
        gap:14px;
    }
    .customer-section-heading {
        display:flex;
        justify-content:space-between;
        align-items:flex-start;
        gap:10px;
        flex-wrap:wrap;
    }
    .customer-section-heading h2 {
        margin:0;
        font-size:16px;
        color:#0f172a;
    }
    .customer-section-heading p {
        margin:4px 0 0;
        color:#64748b;
        font-size:12px;
        line-height:1.45;
    }
    .customer-section-divider {
        height:1px;
        background:#eef2f7;
    }
    .customer-form-grid {
        display:grid;
        grid-template-columns:repeat(12, minmax(0, 1fr));
        gap:14px;
    }
    .span-3 { grid-column:span 3; }
    .span-4 { grid-column:span 4; }
    .span-5 { grid-column:span 5; }
    .span-6 { grid-column:span 6; }
    .span-7 { grid-column:span 7; }
    .span-8 { grid-column:span 8; }
    .span-12 { grid-column:span 12; }
    .field { display:grid; gap:6px; min-width:0; }
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
        padding:10px 12px;
        border-radius:12px;
        border:1px solid #cbd5e1;
        background:#fff;
        color:#0f172a;
        font-size:14px;
        line-height:1.45;
    }
    .field input:focus,
    .field select:focus,
    .field textarea:focus {
        outline:none;
        border-color:#2563eb;
        box-shadow:0 0 0 3px rgba(37, 99, 235, 0.12);
    }
    .field textarea { min-height:92px; resize:vertical; }
    .field.is-error input,
    .field.is-error select,
    .field.is-error textarea,
    .type-panel.is-error,
    .customer-optional-content.is-error {
        border-color:#dc2626;
        box-shadow:0 0 0 3px rgba(220, 38, 38, 0.1);
        background:#fff7f7;
    }
    .field-error {
        color:#b91c1c;
        font-size:12px;
        line-height:1.4;
    }
    .type-panel {
        display:grid;
        gap:12px;
        padding:14px;
        border:1px solid #e2e8f0;
        border-radius:16px;
        background:#f8fafc;
    }
    .type-panel[hidden] { display:none !important; }
    .inline-grid {
        display:grid;
        grid-template-columns:repeat(3, minmax(0, 1fr));
        gap:12px;
    }
    .phone-group {
        display:flex;
        align-items:center;
        border:1px solid #cbd5e1;
        border-radius:12px;
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
        min-height:44px;
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
        box-shadow:0 0 0 3px rgba(220, 38, 38, 0.1);
        background:#fff7f7;
    }
    .customer-optional {
        border:1px solid #e2e8f0;
        border-radius:16px;
        background:#fbfdff;
        overflow:hidden;
    }
    .customer-optional-toggle {
        width:100%;
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:10px;
        border:none;
        background:transparent;
        padding:14px 16px;
        cursor:pointer;
        text-align:left;
    }
    .customer-optional-toggle strong {
        font-size:13px;
        color:#0f172a;
    }
    .customer-optional-toggle span {
        color:#64748b;
        font-size:12px;
    }
    .customer-optional-toggle em {
        font-style:normal;
        color:#475569;
        font-weight:700;
        font-size:16px;
    }
    .customer-optional-content {
        border-top:1px solid #eef2f7;
        padding:16px;
        display:grid;
        gap:14px;
    }
    .customer-optional-content[hidden] { display:none !important; }
    .customer-badge {
        display:inline-flex;
        align-items:center;
        gap:6px;
        padding:5px 10px;
        border-radius:999px;
        background:#eef2ff;
        color:#4338ca;
        font-size:11px;
        font-weight:700;
        letter-spacing:.03em;
        text-transform:uppercase;
    }
    .customer-map-tools {
        display:flex;
        gap:8px;
        flex-wrap:wrap;
    }
    .customer-map-preview {
        border:1px dashed #cbd5e1;
        border-radius:14px;
        background:#f8fafc;
        padding:12px 14px;
        display:grid;
        gap:4px;
        color:#334155;
        font-size:13px;
        line-height:1.5;
    }
    .customer-map-preview[hidden] { display:none !important; }
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
        padding-top:4px;
    }
    .customer-mobile-collapsible-trigger {
        width:100%;
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:12px;
        padding:14px 16px;
        border:1px solid #e2e8f0;
        border-radius:16px;
        background:#fff;
        color:#0f172a;
        text-align:left;
        cursor:pointer;
    }
    .customer-mobile-collapsible-trigger strong {
        display:block;
        font-size:14px;
        line-height:1.4;
    }
    .customer-mobile-collapsible-trigger span {
        display:block;
        margin-top:3px;
        color:#64748b;
        font-size:12px;
        line-height:1.4;
    }
    .customer-mobile-collapsible-trigger em,
    .customer-mobile-inline-toggle em {
        font-style:normal;
        color:#475569;
        font-size:16px;
        font-weight:700;
    }
    .customer-mobile-collapsible-body[hidden],
    .customer-mobile-advanced[hidden] {
        display:none !important;
    }
    .customer-mobile-inline-toggle {
        width:100%;
        display:none;
        align-items:center;
        justify-content:space-between;
        gap:12px;
        margin-top:4px;
        padding:11px 12px;
        border:1px dashed #cbd5e1;
        border-radius:12px;
        background:#f8fafc;
        color:#0f172a;
        text-align:left;
        cursor:pointer;
    }
    .customer-mobile-inline-toggle strong {
        font-size:13px;
    }
    .customer-mobile-inline-toggle span {
        color:#64748b;
        font-size:12px;
    }
    .ops-btn,
    .ops-btn-light {
        display:inline-flex;
        align-items:center;
        justify-content:center;
        gap:6px;
        padding:10px 14px;
        border-radius:12px;
        font-size:13px;
        font-weight:600;
        text-decoration:none;
        border:1px solid transparent;
        cursor:pointer;
    }
    .ops-btn { background:#0f172a; color:#fff; }
    .ops-btn-light { background:#fff; color:#334155; border-color:#cbd5e1; }
    @media (min-width: 721px) {
        .desktop-only { display:initial !important; }
    }
    @media (max-width: 1024px) {
        .span-3, .span-4, .span-5, .span-6, .span-7, .span-8 { grid-column:span 12; }
    }
    @media (max-width: 720px) {
        .desktop-only { display:none !important; }
        .mobile-only { display:initial !important; }
        .customer-form-shell {
            gap:10px;
            padding-bottom:104px;
        }
        .customer-type-switch {
            width:100%;
            justify-content:stretch;
            padding:3px;
            gap:4px;
        }
        .customer-type-pill {
            flex:1 1 0;
            padding:9px 12px;
            text-align:center;
            font-size:12px;
        }
        .customer-type-help {
            margin-top:4px;
            font-size:10px;
            line-height:1.35;
        }
        .customer-sections {
            gap:8px;
        }
        .customer-section {
            padding:12px;
            gap:10px;
            border-radius:14px;
        }
        .customer-section-heading h2 {
            font-size:14px;
        }
        .customer-section-heading p,
        .customer-badge {
            display:none;
        }
        .customer-section-divider {
            display:none;
        }
        .customer-form-grid {
            gap:10px;
        }
        .field label {
            font-size:10px;
        }
        .field input,
        .field select,
        .field textarea,
        .phone-group .country-code-picker__trigger,
        .phone-group input[data-phone-local] {
            font-size:15px;
        }
        .customer-mobile-inline-toggle {
            display:flex;
            margin-top:2px;
            padding:10px 12px;
        }
        .customer-mobile-collapsible-trigger {
            display:flex !important;
            padding:12px 14px;
            border-radius:14px;
        }
        .inline-grid { grid-template-columns:1fr; }
        .customer-map-tools,
        .form-actions { grid-template-columns:1fr; }
        .customer-map-tools { display:grid; }
        .form-actions {
            display:grid;
            grid-template-columns:minmax(0, 1fr) minmax(0, 1fr);
            position:fixed;
            left:12px;
            right:12px;
            bottom:calc(84px + env(safe-area-inset-bottom, 0px));
            z-index:60;
            padding:10px;
            border:1px solid rgba(203, 213, 225, 0.9);
            border-radius:16px;
            background:rgba(255, 255, 255, 0.96);
            box-shadow:0 18px 40px rgba(15, 23, 42, 0.14);
            backdrop-filter:blur(8px);
        }
        .form-actions > * {
            width:100%;
            min-width:0;
        }
        .form-actions .ops-btn,
        .form-actions .ops-btn-light {
            min-height:46px;
        }
        @media (max-width: 380px) {
            .form-actions {
                grid-template-columns:1fr;
                bottom:calc(96px + env(safe-area-inset-bottom, 0px));
            }
        }
        .customer-optional-toggle {
            padding:11px 14px;
        }
        .customer-optional-toggle span span {
            display:none;
        }
        .customer-optional-toggle strong {
            font-size:12px;
        }
        .customer-optional-content {
            padding:12px;
            gap:10px;
        }
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
            Use Business only for institutional billing.
        </p>
    </div>

    <input type="hidden" name="customer_type" id="customer_type" value="{{ $customerTypeValue }}">
    <input type="hidden" name="name" id="display_name" value="{{ $fieldValue('name') }}">
    <input type="hidden" name="gst_registered" id="gst_registered" value="{{ $fieldValue('gst_registered', $customerData?->gst_registered ? '1' : '0') }}">

    <div class="customer-sections">
        <section class="customer-section">
            <div class="customer-section-heading">
                <div>
                    <h2>Identity</h2>
                    <p>Choose the customer type first, then capture only the essential identity fields.</p>
                </div>
                <span class="customer-badge">Customer Module Only</span>
            </div>
            <div class="customer-section-divider"></div>

            <div class="customer-form-grid">
                <div class="span-12">
                    <div class="type-panel {{ $hasFieldError('salutation') || $hasFieldError('first_name') || $hasFieldError('last_name') ? 'is-error' : '' }}" data-type-panel="Individual" {{ $customerTypeValue === 'Individual' ? '' : 'hidden' }}>
                        <div class="inline-grid">
                            <div class="field {{ $hasFieldError('salutation') ? 'is-error' : '' }} customer-mobile-advanced" data-mobile-inline-panel="identity" {{ $identityOpen ? '' : 'hidden' }}>
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
                                <label for="first_name">Customer Name</label>
                                <input id="first_name" type="text" name="first_name" value="{{ $fieldValue('first_name') }}" placeholder="Customer name">
                                @if($fieldError('first_name'))
                                    <div class="field-error">{{ $fieldError('first_name') }}</div>
                                @endif
                            </div>
                            <div class="field {{ $hasFieldError('last_name') ? 'is-error' : '' }} customer-mobile-advanced" data-mobile-inline-panel="identity" {{ $identityOpen ? '' : 'hidden' }}>
                                <label for="last_name">Last Name</label>
                                <input id="last_name" type="text" name="last_name" value="{{ $fieldValue('last_name') }}" placeholder="Last name">
                                @if($fieldError('last_name'))
                                    <div class="field-error">{{ $fieldError('last_name') }}</div>
                                @endif
                            </div>
                        </div>
                        <button type="button" class="customer-mobile-inline-toggle mobile-only" data-mobile-inline-toggle="identity">
                            <span>
                                <strong>Optional Fields</strong>
                                <span>Salutation and last name</span>
                            </span>
                            <em data-mobile-inline-icon="identity">{{ $identityOpen ? '−' : '+' }}</em>
                        </button>
                    </div>

                    <div class="type-panel {{ $hasFieldError('company_name') || $hasFieldError('contact_name') ? 'is-error' : '' }}" data-type-panel="Business" {{ $customerTypeValue === 'Business' ? '' : 'hidden' }}>
                        <div class="inline-grid" style="grid-template-columns:repeat(2, minmax(0, 1fr));">
                            <div class="field {{ $hasFieldError('company_name') ? 'is-error' : '' }}">
                                <label for="company_name">Company Name</label>
                                <input id="company_name" type="text" name="company_name" value="{{ $fieldValue('company_name') }}" placeholder="Company or institution name">
                                @if($fieldError('company_name'))
                                    <div class="field-error">{{ $fieldError('company_name') }}</div>
                                @endif
                            </div>
                            <div class="field {{ $hasFieldError('contact_name') ? 'is-error' : '' }}">
                                <label for="contact_name">Contact Person</label>
                                <input id="contact_name" type="text" name="contact_name" value="{{ $fieldValue('contact_name') }}" placeholder="Primary contact person">
                                @if($fieldError('contact_name'))
                                    <div class="field-error">{{ $fieldError('contact_name') }}</div>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="customer-section">
            <div class="customer-section-heading">
                <div>
                    <h2>Contact</h2>
                    <p>Keep the essential customer communication details clean and easy to scan.</p>
                </div>
            </div>
            <div class="customer-section-divider"></div>

            <div class="customer-form-grid">
                <div class="field span-4 {{ $hasFieldError('phone') ? 'is-error' : '' }}">
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
                    <div class="field span-4 {{ $hasFieldError('whatsapp_number') ? 'is-error' : '' }}">
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

                <div class="field span-4 {{ $hasFieldError('city') ? 'is-error' : '' }}">
                    <label for="city">City</label>
                    <input id="city" type="text" name="city" value="{{ $fieldValue('city') }}" placeholder="City">
                    @if($fieldError('city'))
                        <div class="field-error">{{ $fieldError('city') }}</div>
                    @endif
                </div>
            </div>
        </section>

        <section class="customer-optional">
            <button type="button" class="customer-optional-toggle" data-optional-toggle="contact-details">
                <span>
                    <strong>Additional Contact Details</strong>
                    <span>Email and extra communication details.</span>
                </span>
                <em data-optional-icon="contact-details">{{ $additionalContactOpen ? '−' : '+' }}</em>
            </button>
            <div class="customer-optional-content {{ $hasFieldError('email') ? 'is-error' : '' }}" data-optional-panel="contact-details" {{ $additionalContactOpen ? '' : 'hidden' }}>
                <div class="customer-form-grid">
                    <div class="field span-12 {{ $hasFieldError('email') ? 'is-error' : '' }}">
                        <label for="email">Email</label>
                        <input id="email" type="email" name="email" value="{{ $fieldValue('email') }}" placeholder="Email address">
                        @if($fieldError('email'))
                            <div class="field-error">{{ $fieldError('email') }}</div>
                        @endif
                    </div>
                </div>
            </div>
        </section>

        <section class="customer-section">
            <div class="customer-section-heading desktop-only">
                <div>
                    <h2>Address & Location</h2>
                    <p>Use the delivery address plus map reference so dispatch and field teams can navigate quickly.</p>
                </div>
            </div>
            <button type="button" class="customer-mobile-collapsible-trigger mobile-only" data-mobile-toggle="address">
                <span>
                    <strong>Address & Location</strong>
                    <span>Address, state, pincode, GST location, and map.</span>
                </span>
                <em data-mobile-icon="address">{{ $addressOpen ? '−' : '+' }}</em>
            </button>
            <div class="customer-mobile-collapsible-body" data-mobile-panel="address" {{ $addressOpen ? '' : 'hidden' }}>
            <div class="customer-section-divider desktop-only"></div>

            <div class="customer-form-grid">
                <div class="field span-6 {{ $hasFieldError('address') ? 'is-error' : '' }}">
                    <label for="address">Address</label>
                    <textarea id="address" name="address" placeholder="Street, building, landmark">{{ $fieldValue('address') }}</textarea>
                    @if($fieldError('address'))
                        <div class="field-error">{{ $fieldError('address') }}</div>
                    @endif
                </div>

                <div class="field span-3 {{ $hasFieldError('state') ? 'is-error' : '' }}">
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

                <div class="field span-3 {{ $hasFieldError('pincode') ? 'is-error' : '' }}">
                    <label for="pincode">Pincode</label>
                    <input id="pincode" type="text" name="pincode" value="{{ $fieldValue('pincode') }}" placeholder="Pincode">
                    @if($fieldError('pincode'))
                        <div class="field-error">{{ $fieldError('pincode') }}</div>
                    @endif
                </div>

                <div class="field span-3 {{ $hasFieldError('place_of_supply') ? 'is-error' : '' }}">
                    <label for="place_of_supply">Place of Supply</label>
                    <input id="place_of_supply" type="text" name="place_of_supply" value="{{ $fieldValue('place_of_supply') }}" placeholder="Defaults from state when left blank">
                    @if($fieldError('place_of_supply'))
                        <div class="field-error">{{ $fieldError('place_of_supply') }}</div>
                    @endif
                </div>

                <div class="field span-5 {{ $hasFieldError('map_location_text') ? 'is-error' : '' }}">
                    <label for="map_location_text">Location / Area</label>
                    <input id="map_location_text" type="text" name="map_location_text" value="{{ $fieldValue('map_location_text') }}" placeholder="Area, landmark, or map label">
                    @if($fieldError('map_location_text'))
                        <div class="field-error">{{ $fieldError('map_location_text') }}</div>
                    @endif
                </div>

                <div class="field span-7 {{ $hasFieldError('map_location_url') ? 'is-error' : '' }}">
                    <label for="map_location_url">Map Location</label>
                    <input id="map_location_url" type="url" name="map_location_url" value="{{ $fieldValue('map_location_url') }}" placeholder="Google Maps Link / Location URL">
                    @if($fieldError('map_location_url'))
                        <div class="field-error">{{ $fieldError('map_location_url') }}</div>
                    @endif
                </div>

                <div class="field span-12">
                    <div class="customer-map-tools">
                        <button type="button" class="ops-btn-light" data-map-current>Use Current Location</button>
                        <button type="button" class="ops-btn-light" data-map-open>Pick Location on Map</button>
                    </div>
                    <div class="customer-map-preview" data-map-preview {{ ($fieldValue('map_location_text') || $fieldValue('map_location_url')) ? '' : 'hidden' }}>
                        <strong data-map-preview-label>{{ $fieldValue('map_location_text') ?: 'Map location saved' }}</strong>
                        <span data-map-preview-url>{{ $fieldValue('map_location_url') }}</span>
                    </div>
                </div>
            </div>
            </div>
        </section>

        <section class="customer-optional">
            <button type="button" class="customer-optional-toggle" data-optional-toggle="gst">
                <span>
                    <strong>GST Details</strong>
                    <span>Optional. Expand only when this direct customer needs GST billing details.</span>
                </span>
                <em data-optional-icon="gst">{{ $gstOpen ? '−' : '+' }}</em>
            </button>
            <div class="customer-optional-content {{ $errors->hasAny(['gst_registered', 'gst_number', 'legal_name', 'billing_address']) ? 'is-error' : '' }}" data-optional-panel="gst" {{ $gstOpen ? '' : 'hidden' }}>
                <div class="customer-form-grid">
                    <div class="field span-4 {{ $hasFieldError('gst_registered') ? 'is-error' : '' }}">
                        <label>GST Registered?</label>
                        <div class="customer-type-switch" style="display:inline-flex;" data-gst-switch>
                            <button type="button" class="customer-type-pill {{ (string) $fieldValue('gst_registered', $customerData?->gst_registered ? '1' : '0') === '1' ? '' : 'is-active' }}" data-gst-trigger="0">No</button>
                            <button type="button" class="customer-type-pill {{ (string) $fieldValue('gst_registered', $customerData?->gst_registered ? '1' : '0') === '1' ? 'is-active' : '' }}" data-gst-trigger="1">Yes</button>
                        </div>
                        @if($fieldError('gst_registered'))
                            <div class="field-error">{{ $fieldError('gst_registered') }}</div>
                        @endif
                    </div>

                    <div class="field span-4 {{ $hasFieldError('gst_number') ? 'is-error' : '' }}" data-gst-field {{ (string) $fieldValue('gst_registered', $customerData?->gst_registered ? '1' : '0') === '1' ? '' : 'hidden' }}>
                        <label for="gst_number">GSTIN</label>
                        <input id="gst_number" type="text" name="gst_number" value="{{ $fieldValue('gst_number') }}" placeholder="29ABCDE1234F1Z5">
                        @if($fieldError('gst_number'))
                            <div class="field-error">{{ $fieldError('gst_number') }}</div>
                        @endif
                    </div>

                    <div class="field span-4 {{ $hasFieldError('legal_name') ? 'is-error' : '' }}" data-gst-field {{ (string) $fieldValue('gst_registered', $customerData?->gst_registered ? '1' : '0') === '1' ? '' : 'hidden' }}>
                        <label for="legal_name">Legal Name</label>
                        <input id="legal_name" type="text" name="legal_name" value="{{ $fieldValue('legal_name') }}" placeholder="Legal billing name">
                        @if($fieldError('legal_name'))
                            <div class="field-error">{{ $fieldError('legal_name') }}</div>
                        @endif
                    </div>

                    <div class="field span-12" data-gst-field {{ (string) $fieldValue('gst_registered', $customerData?->gst_registered ? '1' : '0') === '1' ? '' : 'hidden' }}>
                        <label style="display:flex; align-items:center; gap:8px; text-transform:none; letter-spacing:0; font-size:13px; color:#334155;">
                            <input type="checkbox" name="same_as_customer_address" value="1" {{ $sameBillingAsAddress ? 'checked' : '' }} data-same-billing style="width:auto;">
                            Same as customer address
                        </label>
                    </div>

                    <div class="field span-12 {{ $hasFieldError('billing_address') ? 'is-error' : '' }}" data-gst-field {{ (string) $fieldValue('gst_registered', $customerData?->gst_registered ? '1' : '0') === '1' ? '' : 'hidden' }}>
                        <label for="billing_address">Billing Address</label>
                        <textarea id="billing_address" name="billing_address" placeholder="Billing address for GST invoices">{{ $fieldValue('billing_address') }}</textarea>
                        @if($fieldError('billing_address'))
                            <div class="field-error">{{ $fieldError('billing_address') }}</div>
                        @endif
                    </div>
                </div>
            </div>
        </section>

        <section class="customer-optional">
            <button type="button" class="customer-optional-toggle" data-optional-toggle="notes">
                <span>
                    <strong>Notes</strong>
                    <span>Timing, delivery access, and internal context.</span>
                </span>
                <em data-optional-icon="notes">{{ $notesOpen ? '−' : '+' }}</em>
            </button>
            <div class="customer-optional-content {{ $hasFieldError('notes') ? 'is-error' : '' }}" data-optional-panel="notes" {{ $notesOpen ? '' : 'hidden' }}>
                <div class="customer-form-grid">
                    <div class="field span-12 {{ $hasFieldError('notes') ? 'is-error' : '' }}">
                        <label for="notes">Notes</label>
                        <textarea id="notes" name="notes" placeholder="Delivery notes, timing, access, or internal remarks">{{ $fieldValue('notes') }}</textarea>
                        @if($fieldError('notes'))
                            <div class="field-error">{{ $fieldError('notes') }}</div>
                        @endif
                    </div>
                </div>
            </div>
        </section>

        <section class="customer-optional">
            <button type="button" class="customer-optional-toggle" data-optional-toggle="id-proof">
                <span>
                    <strong>ID Proof</strong>
                    <span>Optional. Keep this tucked away unless KYC or compliance proof is needed.</span>
                </span>
                <em data-optional-icon="id-proof">{{ $idProofOpen ? '−' : '+' }}</em>
            </button>
            <div class="customer-optional-content {{ $errors->hasAny(['id_proof_type', 'id_proof_number', 'id_proof_file']) ? 'is-error' : '' }}" data-optional-panel="id-proof" {{ $idProofOpen ? '' : 'hidden' }}>
                <div class="customer-form-grid">
                    <div class="field span-4 {{ $hasFieldError('id_proof_type') ? 'is-error' : '' }}">
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

                    <div class="field span-4 {{ $hasFieldError('id_proof_number') ? 'is-error' : '' }}">
                        <label for="id_proof_number">ID Proof Number</label>
                        <input id="id_proof_number" type="text" name="id_proof_number" value="{{ $fieldValue('id_proof_number') }}" placeholder="Proof number">
                        @if($fieldError('id_proof_number'))
                            <div class="field-error">{{ $fieldError('id_proof_number') }}</div>
                        @endif
                    </div>

                    <div class="field span-4 {{ $hasFieldError('id_proof_file') ? 'is-error' : '' }}">
                        <label for="id_proof_file">Upload ID Proof</label>
                        <input id="id_proof_file" type="file" name="id_proof_file">
                        @if($fieldError('id_proof_file'))
                            <div class="field-error">{{ $fieldError('id_proof_file') }}</div>
                        @endif
                    </div>

                    @if($existingProofUrl)
                        <div class="field span-12">
                            <a href="{{ $existingProofUrl }}" target="_blank" class="proof-chip">
                                View Existing Proof
                                @if(filled($customerData?->id_proof_original_name))
                                    <span>{{ $customerData->id_proof_original_name }}</span>
                                @endif
                            </a>
                        </div>
                    @endif
                </div>
            </div>
        </section>
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
        const optionalToggles = document.querySelectorAll('[data-optional-toggle]');
        const gstRegisteredInput = document.getElementById('gst_registered');
        const gstButtons = document.querySelectorAll('[data-gst-trigger]');
        const gstFields = document.querySelectorAll('[data-gst-field]');
        const sameBillingCheckbox = document.querySelector('[data-same-billing]');
        const addressField = document.getElementById('address');
        const cityField = document.getElementById('city');
        const stateField = document.getElementById('state');
        const pincodeField = document.getElementById('pincode');
        const billingAddressField = document.getElementById('billing_address');
        const mapTextField = document.getElementById('map_location_text');
        const mapUrlField = document.getElementById('map_location_url');
        const mapPreview = document.querySelector('[data-map-preview]');
        const mapPreviewLabel = document.querySelector('[data-map-preview-label]');
        const mapPreviewUrl = document.querySelector('[data-map-preview-url]');
        const mobileCollapsibleButtons = document.querySelectorAll('[data-mobile-toggle]');
        const mobileInlineButtons = document.querySelectorAll('[data-mobile-inline-toggle]');
        const mapCurrentButton = document.querySelector('[data-map-current]');
        const mapOpenButton = document.querySelector('[data-map-open]');

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

        function syncOptionalPanel(key, forceOpen) {
            const panel = document.querySelector('[data-optional-panel="' + key + '"]');
            const icon = document.querySelector('[data-optional-icon="' + key + '"]');

            if (!panel || !icon) {
                return;
            }

            const nextHidden = forceOpen === undefined ? !panel.hidden : !forceOpen;
            panel.hidden = nextHidden;
            icon.textContent = nextHidden ? '+' : '−';
        }

        function syncMobilePanel(key, forceOpen) {
            const panel = document.querySelector('[data-mobile-panel="' + key + '"]');
            const icon = document.querySelector('[data-mobile-icon="' + key + '"]');

            if (!panel || !icon) {
                return;
            }

            const nextHidden = forceOpen === undefined ? !panel.hidden : !forceOpen;
            panel.hidden = nextHidden;
            icon.textContent = nextHidden ? '+' : 'âˆ’';
        }

        function syncInlineOptional(key, forceOpen) {
            const panels = document.querySelectorAll('[data-mobile-inline-panel="' + key + '"]');
            const icon = document.querySelector('[data-mobile-inline-icon="' + key + '"]');

            if (!panels.length || !icon) {
                return;
            }

            const nextHidden = forceOpen === undefined ? !panels[0].hidden : !forceOpen;

            panels.forEach(function (panel) {
                panel.hidden = nextHidden;
            });

            icon.textContent = nextHidden ? '+' : 'âˆ’';
        }

        function syncGstFields() {
            const enabled = gstRegisteredInput.value === '1';

            gstButtons.forEach(function (button) {
                const active = button.getAttribute('data-gst-trigger') === gstRegisteredInput.value;
                button.classList.toggle('is-active', active);
            });

            gstFields.forEach(function (field) {
                field.hidden = !enabled;
            });

            if (!enabled && sameBillingCheckbox) {
                sameBillingCheckbox.checked = false;
            }
        }

        function copyBillingAddress() {
            if (!sameBillingCheckbox || !sameBillingCheckbox.checked || !billingAddressField) {
                return;
            }

            const merged = [addressField?.value || '', cityField?.value || '', stateField?.value || '', pincodeField?.value || '']
                .filter(Boolean)
                .join(', ');

            billingAddressField.value = merged;
        }

        function syncMapPreview() {
            if (!mapPreview || !mapPreviewLabel || !mapPreviewUrl) {
                return;
            }

            const label = (mapTextField?.value || '').trim();
            const url = (mapUrlField?.value || '').trim();
            const hasValue = label !== '' || url !== '';

            mapPreview.hidden = !hasValue;
            mapPreviewLabel.textContent = label !== '' ? label : 'Map location saved';
            mapPreviewUrl.textContent = url;
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

        optionalToggles.forEach(function (button) {
            button.addEventListener('click', function () {
                syncOptionalPanel(button.getAttribute('data-optional-toggle'));
            });
        });

        mobileCollapsibleButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                syncMobilePanel(button.getAttribute('data-mobile-toggle'));
            });
        });

        mobileInlineButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                syncInlineOptional(button.getAttribute('data-mobile-inline-toggle'));
            });
        });

        gstButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                gstRegisteredInput.value = button.getAttribute('data-gst-trigger') || '0';
                if (gstRegisteredInput.value === '1') {
                    syncOptionalPanel('gst', true);
                }
                syncGstFields();
            });
        });

        sameBillingCheckbox?.addEventListener('change', copyBillingAddress);
        [addressField, cityField, stateField, pincodeField].forEach(function (input) {
            input?.addEventListener('input', copyBillingAddress);
            input?.addEventListener('change', copyBillingAddress);
        });

        [mapTextField, mapUrlField].forEach(function (input) {
            input?.addEventListener('input', syncMapPreview);
            input?.addEventListener('change', syncMapPreview);
        });

        mapCurrentButton?.addEventListener('click', function () {
            if (!navigator.geolocation) {
                window.alert('Current location is not supported on this device.');
                return;
            }

            mapCurrentButton.disabled = true;
            mapCurrentButton.textContent = 'Capturing...';

            navigator.geolocation.getCurrentPosition(function (position) {
                const latitude = position.coords.latitude.toFixed(6);
                const longitude = position.coords.longitude.toFixed(6);
                const accuracy = Math.round(position.coords.accuracy || 0);

                if (mapTextField && !mapTextField.value.trim()) {
                    mapTextField.value = 'Current location';
                }

                if (mapUrlField) {
                    mapUrlField.value = 'https://maps.google.com/?q=' + latitude + ',' + longitude;
                }

                if (mapTextField) {
                    mapTextField.value = (mapTextField.value || 'Current location') + ' (' + latitude + ', ' + longitude + (accuracy ? ' • ±' + accuracy + 'm' : '') + ')';
                }

                syncMapPreview();
                mapCurrentButton.disabled = false;
                mapCurrentButton.textContent = 'Use Current Location';
            }, function () {
                window.alert('Unable to capture current location. You can still paste a Google Maps link manually.');
                mapCurrentButton.disabled = false;
                mapCurrentButton.textContent = 'Use Current Location';
            }, {
                enableHighAccuracy: true,
                timeout: 10000,
                maximumAge: 0
            });
        });

        mapOpenButton?.addEventListener('click', function () {
            const existingUrl = (mapUrlField?.value || '').trim();
            window.open(existingUrl !== '' ? existingUrl : 'https://maps.google.com', '_blank', 'noopener');
        });

        document.querySelectorAll('[data-phone-local]').forEach(function (input) {
            input.addEventListener('input', function () {
                input.value = input.value.replace(/\D+/g, '').slice(0, 15);
            });
        });

        syncType(customerTypeInput.value || 'Individual');
        syncInlineOptional('identity', {{ $identityOpen ? 'true' : 'false' }});
        syncMobilePanel('address', {{ $addressOpen ? 'true' : 'false' }});
        syncOptionalPanel('contact-details', {{ $additionalContactOpen ? 'true' : 'false' }});
        syncOptionalPanel('gst', {{ $gstOpen ? 'true' : 'false' }});
        syncOptionalPanel('notes', {{ $notesOpen ? 'true' : 'false' }});
        syncOptionalPanel('id-proof', {{ $idProofOpen ? 'true' : 'false' }});
        syncGstFields();
        syncMapPreview();
        copyBillingAddress();
    })();
</script>
