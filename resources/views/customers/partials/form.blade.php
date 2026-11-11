@php
    $customerData = $customer ?? null;
    $fieldValue = fn (string $field, $default = null) => old($field, $customerData?->{$field} ?? $default);
    $customerTypeValue = \App\Support\CustomerProfileSupport::normalizeCustomerType($fieldValue('customer_type'));
    $whatsAppEnabled = \App\Models\Customer::hasWhatsappNumberColumn();
    $customerTypeOptions = $customerTypeOptions ?? ['Individual', 'Business'];
    $salutationOptions = $salutationOptions ?? ['Mr.', 'Mrs.', 'Ms.', 'Dr.'];
    $idProofTypeOptions = $idProofTypeOptions ?? ['Aadhaar', 'PAN', 'Driving License', 'Passport', 'Other'];
    $indianStates = $indianStates ?? \App\Models\Customer::indianStates();
    $countryCodeOptions = \App\Support\PhoneNumber::countryCodeOptions();
    $phoneParts = \App\Support\PhoneNumber::split($fieldValue('phone'));
    $whatsAppParts = \App\Support\PhoneNumber::split($fieldValue('whatsapp_number'));
    $existingProofUrl = $customerData && filled($customerData->id_proof_file_path)
        ? route('customers.id-proof.download', $customerData->id)
        : null;

    $gstValue = (string) $fieldValue('gst_registered', $customerData?->gst_registered ? '1' : '0');
    $gstRequiredFields = ['gst_number', 'legal_name', 'billing_address'];
    $showGstFields = $gstValue === '1' || $errors->hasAny($gstRequiredFields);
    $gstOpen = $errors->hasAny([...$gstRequiredFields, 'gst_registered', 'place_of_supply']);
    $mapNotesOpen = $errors->hasAny(['map_location_text', 'map_location_url', 'notes']);
    $idProofOpen = $errors->hasAny(['id_proof_type', 'id_proof_number', 'id_proof_file']);
    $mapValuePresent = filled($fieldValue('map_location_text')) || filled($fieldValue('map_location_url'));
    $notesPresent = filled($fieldValue('notes'));
    $billingAddressMatches = filled($customerData?->billing_address) && $customerData->billing_address === $customerData->address;
    $sameBillingAsAddress = (string) old('same_as_customer_address',
        !session()->hasOldInput('customer_type') && $billingAddressMatches ? '1' : '0') === '1';
    $fieldError = fn (string $field) => $errors->first($field)
        ?: (in_array($field, ['phone', 'whatsapp_number'], true) ? $errors->first($field . '_country_code') : null);
@endphp

<style>
    .customer-profile-form {
        --profile-ink: #172944;
        --profile-muted: #52657f;
        --profile-line: #dce5f1;
        --profile-input-line: #becce0;
        --profile-blue: #234fb8;
        --profile-blue-soft: #eef4ff;
        display: grid;
        gap: 12px;
        min-width: 0;
        color: var(--profile-ink);
    }
    .customer-profile-form [hidden] { display: none !important; }
    .customer-profile-form :focus-visible { outline: 2px solid var(--profile-blue); outline-offset: 2px; }
    .customer-profile-card,
    .customer-profile-actions {
        min-width: 0;
        border: 1px solid var(--profile-line);
        border-radius: 12px;
        background: #fff;
    }
    .customer-profile-section { display: grid; gap: 12px; padding: 16px; min-width: 0; }
    .customer-profile-section + .customer-profile-section { border-top: 1px solid var(--profile-line); }
    .customer-profile-section h2 { margin: 0; color: var(--profile-ink); font-size: 16px; line-height: 1.25; font-weight: 750; }
    .customer-profile-heading {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 8px;
    }
    .customer-profile-grid,
    .customer-profile-person,
    .customer-profile-business {
        display: grid;
        grid-template-columns: repeat(12, minmax(0, 1fr));
        gap: 10px 12px;
        min-width: 0;
    }
    .customer-profile-person { grid-template-columns: minmax(100px, .65fr) repeat(2, minmax(0, 1fr)); }
    .customer-profile-business { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .customer-profile-form .span-4 { grid-column: span 4; }
    .customer-profile-form .span-6 { grid-column: span 6; }
    .customer-profile-form .span-8 { grid-column: span 8; }
    .customer-profile-form .span-12 { grid-column: 1 / -1; }
    .customer-profile-field { display: flex; flex-direction: column; min-width: 0; gap: 4px; }
    .customer-profile-form :is(.customer-profile-field label, .customer-profile-field-label) {
        font-size: 13px;
        font-weight: 650;
        line-height: 1.3;
        color: var(--profile-ink);
    }
    .customer-profile-required { color: var(--profile-blue); }
    .customer-profile-form .customer-profile-field :is(input:not([type="checkbox"], [type="hidden"]), select, textarea) {
        box-sizing: border-box;
        width: 100%;
        min-width: 0;
        min-height: 42px;
        padding: 8px 10px;
        border: 1px solid var(--profile-input-line);
        border-radius: 8px;
        background: #fff;
        color: var(--profile-ink);
        font-size: 14px;
        line-height: 1.4;
        scroll-margin-block: 90px 160px;
    }
    .customer-profile-form .customer-profile-field textarea { min-height: 58px; resize: vertical; }
    .customer-profile-form .customer-profile-field :is(input:not([type="checkbox"]), select, textarea):focus {
        outline: none;
        border-color: var(--profile-blue);
        box-shadow: 0 0 0 2px #d7e5ff;
    }
    .customer-profile-form .customer-profile-field :is(input, select, textarea)[aria-invalid="true"],
    .customer-profile-field.is-error .customer-profile-phone { border-color: #b7352a; background: #fff6f5; }
    .customer-profile-error { margin: 0; color: #9d281e; font-size: 12px; line-height: 1.4; overflow-wrap: anywhere; }
    .customer-profile-label-row { display: flex; align-items: baseline; justify-content: space-between; flex-wrap: wrap; gap: 3px 6px; }
    .customer-profile-copy {
        padding: 0;
        border: 0;
        background: none;
        color: var(--profile-blue);
        font-size: 12px;
        font-weight: 650;
        cursor: pointer;
        text-decoration: underline;
        text-underline-offset: 2px;
    }
    .customer-profile-copy:disabled { color: var(--profile-muted); cursor: default; text-decoration: none; }
    .customer-profile-segment {
        display: inline-flex;
        align-self: flex-start;
        gap: 3px;
        padding: 3px;
        border: 1px solid var(--profile-line);
        border-radius: 9px;
        background: #f3f6fb;
    }
    .customer-profile-choice {
        padding: 6px 11px;
        min-height: 32px;
        border: 1px solid transparent;
        border-radius: 6px;
        background: transparent;
        color: var(--profile-muted);
        font-size: 13px;
        font-weight: 650;
        cursor: pointer;
    }
    .customer-profile-choice[aria-pressed="true"] { border-color: #c1d2f5; background: #fff; color: var(--profile-blue); }
    .customer-profile-phone {
        display: flex;
        align-items: stretch;
        min-width: 0;
        border: 1px solid var(--profile-input-line);
        border-radius: 8px;
        background: #fff;
    }
    .customer-profile-phone .country-code-picker,
    .customer-profile-phone .country-code-picker__trigger { border-radius: 7px 0 0 7px; }
    .customer-profile-phone .country-code-picker__trigger { min-height: 40px; height: 100%; }
    .customer-profile-phone .country-code-picker__menu { width: min(280px, calc(100vw - 48px)); }
    .customer-profile-form .customer-profile-phone input.country-code-picker__search { padding-inline-start: 36px; }
    .customer-profile-form .customer-profile-phone input[data-phone-local] {
        flex: 1 1 auto;
        width: 1%;
        min-height: 40px;
        border: 0;
        border-radius: 0 7px 7px 0;
        background: transparent;
    }
    .customer-profile-disclosures { border: 1px solid var(--profile-line); border-radius: 8px; }
    .customer-profile-disclosure + .customer-profile-disclosure { border-top: 1px solid var(--profile-line); }
    .customer-profile-disclosure > summary {
        display: flex;
        align-items: center;
        gap: 8px;
        min-height: 42px;
        padding: 8px 12px;
        border-radius: 7px;
        list-style: none;
        font-size: 13px;
        font-weight: 650;
        cursor: pointer;
    }
    .customer-profile-disclosure > summary::-webkit-details-marker { display: none; }
    .customer-profile-disclosure > summary::after {
        content: '+';
        margin-inline-start: auto;
        color: var(--profile-blue);
        font-size: 17px;
        line-height: 1;
    }
    .customer-profile-disclosure[open] > summary { background: var(--profile-blue-soft); color: var(--profile-blue); }
    .customer-profile-disclosure[open] > summary::after { content: '−'; }
    .customer-profile-disclosure > summary:hover { background: var(--profile-blue-soft); }
    .customer-profile-content { padding: 12px; }
    .customer-profile-summary { color: var(--profile-muted); font-size: 12px; font-weight: 500; }
    .customer-profile-map-tools { display: flex; flex-wrap: wrap; gap: 6px; }
    .customer-profile-map-preview {
        display: grid;
        gap: 3px;
        padding: 8px 10px;
        border-radius: 8px;
        background: var(--profile-blue-soft);
        color: var(--profile-ink);
        font-size: 12px;
        overflow-wrap: anywhere;
    }
    .customer-profile-checkbox { display: flex; align-items: center; gap: 7px; }
    .customer-profile-form .customer-profile-checkbox input { width: 16px; height: 16px; min-height: 0; }
    .customer-profile-proof { width: fit-content; max-width: 100%; color: var(--profile-blue); font-weight: 650; overflow-wrap: anywhere; }
    .customer-profile-proof span { display: block; color: var(--profile-muted); font-size: 12px; font-weight: 500; }
    .customer-profile-actions {
        position: sticky;
        z-index: 4;
        bottom: 12px;
        display: flex;
        justify-content: flex-end;
        gap: 8px;
        padding: 9px 12px;
        box-shadow: 0 5px 16px rgba(15, 23, 42, .06);
    }
    .customer-profile-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 8px 14px;
        min-height: 42px;
        border: 1px solid var(--profile-input-line);
        border-radius: 8px;
        background: #fff;
        color: var(--profile-ink);
        font-size: 14px;
        font-weight: 650;
        text-decoration: none;
        cursor: pointer;
    }
    .customer-profile-btn:hover:not(:disabled) { background: var(--profile-blue-soft); }
    .customer-profile-btn.is-primary { border-color: var(--profile-blue); background: var(--profile-blue); color: #fff; }
    .customer-profile-btn.is-primary:hover { border-color: #193e99; background: #193e99; }
    .customer-profile-btn:disabled { opacity: .65; cursor: wait; }
    @media (max-width: 1024px) {
        .customer-profile-actions {
            position: fixed;
            inset-inline: 12px;
            bottom: 12px;
            z-index: 904;
        }
    }
    @media (max-width: 767px) {
        .customer-profile-actions { bottom: calc(82px + env(safe-area-inset-bottom, 0px)); }
        body:has(.customer-form-page) .phos-release-badge { bottom: calc(150px + env(safe-area-inset-bottom, 0px)); }
    }
    @media (max-width: 640px) {
        .customer-profile-section { padding: 12px; }
        .customer-profile-person { grid-template-columns: minmax(76px, .7fr) repeat(2, minmax(0, 1fr)); }
        .customer-profile-business { grid-template-columns: minmax(0, 1fr); }
        .customer-profile-form .customer-profile-field :is(input:not([type="checkbox"]), select, textarea) { font-size: 16px; }
        .customer-profile-contact .customer-profile-field,
        .customer-profile-content .customer-profile-field { grid-column: 1 / -1; }
        .customer-profile-form .customer-profile-address .span-4 { grid-column: span 6; }
        .customer-profile-actions .is-primary { flex: 1; }
    }
    @media (max-width: 360px) {
        .customer-profile-person { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .customer-profile-person > :first-child { grid-column: 1 / -1; max-width: 150px; }
    }
</style>

<div class="customer-profile-form" data-customer-profile-form>
    <input type="hidden" name="customer_type" id="customer_type" value="{{ $customerTypeValue }}">
    <input type="hidden" name="name" id="display_name" value="{{ $fieldValue('name') }}">
    <input type="hidden" name="gst_registered" id="gst_registered" value="{{ $gstValue }}">

    <div class="customer-profile-card">
        <section class="customer-profile-section" aria-labelledby="customer-contact-heading">
            <div class="customer-profile-heading">
                <h2 id="customer-contact-heading">Contact</h2>
                <div class="customer-profile-segment" data-customer-type-switch role="group" aria-label="Customer type">
                    @foreach($customerTypeOptions as $type)
                        <button type="button" class="customer-profile-choice" data-type-trigger="{{ $type }}"
                            aria-pressed="{{ $customerTypeValue === $type ? 'true' : 'false' }}">{{ $type }}</button>
                    @endforeach
                </div>
            </div>

            <div class="customer-profile-person" data-type-panel="Individual" @if($customerTypeValue !== 'Individual') hidden @endif>
                <div class="customer-profile-field">
                    <label for="salutation">Salutation</label>
                    <select id="salutation" name="salutation" @if($fieldError('salutation')) aria-invalid="true" aria-describedby="salutation-error" @endif>
                        <option value="">None</option>
                        @foreach($salutationOptions as $option)
                            <option value="{{ $option }}" @selected($fieldValue('salutation') === $option)>{{ $option }}</option>
                        @endforeach
                    </select>
                    @if($fieldError('salutation'))
                        <p class="customer-profile-error" id="salutation-error">{{ $fieldError('salutation') }}</p>
                    @endif
                </div>
                <div class="customer-profile-field">
                    <label for="first_name">First name <span class="customer-profile-required" aria-hidden="true">*</span></label>
                    <input id="first_name" type="text" name="first_name" value="{{ $fieldValue('first_name') }}" aria-required="true" autocomplete="given-name" @if($fieldError('first_name')) aria-invalid="true" aria-describedby="first_name-error" @endif>
                    @if($fieldError('first_name'))
                        <p class="customer-profile-error" id="first_name-error">{{ $fieldError('first_name') }}</p>
                    @endif
                </div>
                <div class="customer-profile-field">
                    <label for="last_name">Last name</label>
                    <input id="last_name" type="text" name="last_name" value="{{ $fieldValue('last_name') }}" autocomplete="family-name" @if($fieldError('last_name')) aria-invalid="true" aria-describedby="last_name-error" @endif>
                    @if($fieldError('last_name'))
                        <p class="customer-profile-error" id="last_name-error">{{ $fieldError('last_name') }}</p>
                    @endif
                </div>
            </div>

            <div class="customer-profile-business" data-type-panel="Business" @if($customerTypeValue !== 'Business') hidden @endif>
                <div class="customer-profile-field">
                    <label for="company_name">Company name <span class="customer-profile-required" aria-hidden="true">*</span></label>
                    <input id="company_name" type="text" name="company_name" value="{{ $fieldValue('company_name') }}" aria-required="true" autocomplete="organization" @if($fieldError('company_name')) aria-invalid="true" aria-describedby="company_name-error" @endif>
                    @if($fieldError('company_name'))
                        <p class="customer-profile-error" id="company_name-error">{{ $fieldError('company_name') }}</p>
                    @endif
                </div>
                <div class="customer-profile-field">
                    <label for="contact_name">Contact person <span class="customer-profile-required" aria-hidden="true">*</span></label>
                    <input id="contact_name" type="text" name="contact_name" value="{{ $fieldValue('contact_name') }}" aria-required="true" @if($fieldError('contact_name')) aria-invalid="true" aria-describedby="contact_name-error" @endif>
                    @if($fieldError('contact_name'))
                        <p class="customer-profile-error" id="contact_name-error">{{ $fieldError('contact_name') }}</p>
                    @endif
                </div>
            </div>

            <div class="customer-profile-grid customer-profile-contact">
                <div class="customer-profile-field {{ $whatsAppEnabled ? 'span-4' : 'span-6' }}{{ $fieldError('phone') ? ' is-error' : '' }}">
                    <label for="phone">Phone</label>
                    <div class="customer-profile-phone">
                        @include('partials.country-code-picker', [
                            'name' => 'phone_country_code',
                            'pickerId' => 'phone_country_code',
                            'value' => $phoneParts['code'],
                            'options' => $countryCodeOptions,
                            'dividerColor' => '#becce0',
                            'width' => '72px',
                        ])
                        <input id="phone" type="text" name="phone" value="{{ $phoneParts['local'] }}" inputmode="tel" autocomplete="tel-national" maxlength="15" pattern="[0-9]{6,15}" data-phone-local
                            @if($fieldError('phone')) aria-invalid="true" aria-describedby="phone-error" @endif>
                    </div>
                    @if($fieldError('phone'))
                        <p class="customer-profile-error" id="phone-error">{{ $fieldError('phone') }}</p>
                    @endif
                </div>

                @if($whatsAppEnabled)
                    <div class="customer-profile-field span-4{{ $fieldError('whatsapp_number') ? ' is-error' : '' }}">
                        <div class="customer-profile-label-row">
                            <label for="whatsapp_number">WhatsApp</label>
                            <button type="button" class="customer-profile-copy" data-copy-customer-phone @disabled(blank($phoneParts['local']))>Use phone number</button>
                            <span class="sr-only" data-copy-customer-phone-status role="status"></span>
                        </div>
                        <div class="customer-profile-phone">
                            @include('partials.country-code-picker', [
                                'name' => 'whatsapp_number_country_code',
                                'pickerId' => 'whatsapp_number_country_code',
                                'value' => $whatsAppParts['code'],
                                'options' => $countryCodeOptions,
                                'dividerColor' => '#becce0',
                                'width' => '72px',
                            ])
                            <input id="whatsapp_number" type="text" name="whatsapp_number" value="{{ $whatsAppParts['local'] }}" inputmode="tel" maxlength="15" pattern="[0-9]{6,15}" data-phone-local
                                @if($fieldError('whatsapp_number')) aria-invalid="true" aria-describedby="whatsapp_number-error" @endif>
                        </div>
                        @if($fieldError('whatsapp_number'))
                            <p class="customer-profile-error" id="whatsapp_number-error">{{ $fieldError('whatsapp_number') }}</p>
                        @endif
                    </div>
                @endif

                <div class="customer-profile-field {{ $whatsAppEnabled ? 'span-4' : 'span-6' }}">
                    <label for="email">Email</label>
                    <input id="email" type="email" name="email" value="{{ $fieldValue('email') }}" autocomplete="email" @if($fieldError('email')) aria-invalid="true" aria-describedby="email-error" @endif>
                    @if($fieldError('email'))
                        <p class="customer-profile-error" id="email-error">{{ $fieldError('email') }}</p>
                    @endif
                </div>
            </div>
        </section>

        <section class="customer-profile-section" aria-labelledby="customer-address-heading">
            <h2 id="customer-address-heading">Address</h2>
            <div class="customer-profile-grid customer-profile-address">
                <div class="customer-profile-field span-12">
                    <label for="address">Street address</label>
                    <textarea id="address" name="address" rows="2" autocomplete="street-address" placeholder="Building, street, landmark" @if($fieldError('address')) aria-invalid="true" aria-describedby="address-error" @endif>{{ $fieldValue('address') }}</textarea>
                    @if($fieldError('address'))
                        <p class="customer-profile-error" id="address-error">{{ $fieldError('address') }}</p>
                    @endif
                </div>
                <div class="customer-profile-field span-4">
                    <label for="city">City</label>
                    <input id="city" type="text" name="city" value="{{ $fieldValue('city') }}" autocomplete="address-level2" @if($fieldError('city')) aria-invalid="true" aria-describedby="city-error" @endif>
                    @if($fieldError('city'))
                        <p class="customer-profile-error" id="city-error">{{ $fieldError('city') }}</p>
                    @endif
                </div>
                <div class="customer-profile-field span-4">
                    <label for="state">State</label>
                    <select id="state" name="state" autocomplete="address-level1" @if($fieldError('state')) aria-invalid="true" aria-describedby="state-error" @endif>
                        <option value="">Select state</option>
                        @foreach($indianStates as $option)
                            <option value="{{ $option }}" @selected($fieldValue('state') === $option)>{{ $option }}</option>
                        @endforeach
                    </select>
                    @if($fieldError('state'))
                        <p class="customer-profile-error" id="state-error">{{ $fieldError('state') }}</p>
                    @endif
                </div>
                <div class="customer-profile-field span-4">
                    <label for="pincode">Pincode</label>
                    <input id="pincode" type="text" name="pincode" value="{{ $fieldValue('pincode') }}" autocomplete="postal-code" @if($fieldError('pincode')) aria-invalid="true" aria-describedby="pincode-error" @endif>
                    @if($fieldError('pincode'))
                        <p class="customer-profile-error" id="pincode-error">{{ $fieldError('pincode') }}</p>
                    @endif
                </div>
            </div>
        </section>
    </div>

    <section class="customer-profile-card customer-profile-section" aria-labelledby="customer-additional-heading">
        <h2 id="customer-additional-heading">Additional details</h2>

        <div class="customer-profile-disclosures">
            <details class="customer-profile-disclosure" data-optional-panel="map-notes" @if($mapNotesOpen) open @endif>
                <summary data-optional-toggle="map-notes">
                    Map &amp; notes
                    @if($mapValuePresent || $notesPresent)
                        <span class="customer-profile-summary">{{ $mapValuePresent && $notesPresent ? 'Location and notes entered' : ($mapValuePresent ? 'Location entered' : 'Notes entered') }}</span>
                    @endif
                </summary>
                <div class="customer-profile-content customer-profile-grid">
                    <div class="customer-profile-field span-4">
                        <label for="map_location_text">Location / area</label>
                        <input id="map_location_text" type="text" name="map_location_text" value="{{ $fieldValue('map_location_text') }}" placeholder="Area or landmark" @if($fieldError('map_location_text')) aria-invalid="true" aria-describedby="map_location_text-error" @endif>
                        @if($fieldError('map_location_text'))
                            <p class="customer-profile-error" id="map_location_text-error">{{ $fieldError('map_location_text') }}</p>
                        @endif
                    </div>
                    <div class="customer-profile-field span-8">
                        <label for="map_location_url">Map location</label>
                        <input id="map_location_url" type="url" name="map_location_url" value="{{ $fieldValue('map_location_url') }}" placeholder="Google Maps link or location URL" @if($fieldError('map_location_url')) aria-invalid="true" aria-describedby="map_location_url-error" @endif>
                        @if($fieldError('map_location_url'))
                            <p class="customer-profile-error" id="map_location_url-error">{{ $fieldError('map_location_url') }}</p>
                        @endif
                    </div>
                    <div class="customer-profile-field span-12">
                        <div class="customer-profile-map-tools">
                            <button type="button" class="customer-profile-btn" data-map-current>Use Current Location</button>
                            <button type="button" class="customer-profile-btn" data-map-open>Pick Location on Map</button>
                        </div>
                        <p class="customer-profile-error" data-map-status role="status" hidden></p>
                        <div class="customer-profile-map-preview" data-map-preview @if(!$mapValuePresent) hidden @endif>
                            <strong data-map-preview-label>{{ $fieldValue('map_location_text') ?: 'Map location entered' }}</strong>
                            <span data-map-preview-url>{{ $fieldValue('map_location_url') }}</span>
                        </div>
                    </div>
                    <div class="customer-profile-field span-12">
                        <label for="notes">Notes</label>
                        <textarea id="notes" name="notes" rows="2" placeholder="Delivery notes or internal remarks" @if($fieldError('notes')) aria-invalid="true" aria-describedby="notes-error" @endif>{{ $fieldValue('notes') }}</textarea>
                        @if($fieldError('notes'))
                            <p class="customer-profile-error" id="notes-error">{{ $fieldError('notes') }}</p>
                        @endif
                    </div>
                </div>
            </details>

            <details class="customer-profile-disclosure" data-optional-panel="gst" @if($gstOpen) open @endif>
                <summary data-optional-toggle="gst">
                    GST Details
                    @if($gstValue === '1')
                        <span class="customer-profile-summary">GST registered</span>
                    @elseif(filled($fieldValue('place_of_supply')))
                        <span class="customer-profile-summary">Place of supply entered</span>
                    @endif
                </summary>
                <div class="customer-profile-content customer-profile-grid">
                    <div class="customer-profile-field span-4">
                        <span id="customer-gst-label" class="customer-profile-field-label">GST registered?</span>
                        <div class="customer-profile-segment" data-gst-switch role="group" aria-labelledby="customer-gst-label">
                            <button type="button" class="customer-profile-choice" data-gst-trigger="0" aria-pressed="{{ $gstValue === '1' ? 'false' : 'true' }}">No</button>
                            <button type="button" class="customer-profile-choice" data-gst-trigger="1" aria-pressed="{{ $gstValue === '1' ? 'true' : 'false' }}">Yes</button>
                        </div>
                        @if($fieldError('gst_registered'))
                            <p class="customer-profile-error" id="gst_registered-error">{{ $fieldError('gst_registered') }}</p>
                        @endif
                    </div>
                    <div class="customer-profile-field span-8">
                        <label for="place_of_supply">Place of supply</label>
                        <input id="place_of_supply" type="text" name="place_of_supply" value="{{ $fieldValue('place_of_supply') }}" placeholder="Defaults to customer state" @if($fieldError('place_of_supply')) aria-invalid="true" aria-describedby="place_of_supply-error" @endif>
                        @if($fieldError('place_of_supply'))
                            <p class="customer-profile-error" id="place_of_supply-error">{{ $fieldError('place_of_supply') }}</p>
                        @endif
                    </div>

                    <div class="customer-profile-field span-6" data-gst-field @if(!$showGstFields) hidden @endif>
                        <label for="gst_number">GSTIN</label>
                        <input id="gst_number" type="text" name="gst_number" value="{{ $fieldValue('gst_number') }}" placeholder="29ABCDE1234F1Z5" @if($fieldError('gst_number')) aria-invalid="true" aria-describedby="gst_number-error" @endif>
                        @if($fieldError('gst_number'))
                            <p class="customer-profile-error" id="gst_number-error">{{ $fieldError('gst_number') }}</p>
                        @endif
                    </div>
                    <div class="customer-profile-field span-6" data-gst-field @if(!$showGstFields) hidden @endif>
                        <label for="legal_name">Legal name</label>
                        <input id="legal_name" type="text" name="legal_name" value="{{ $fieldValue('legal_name') }}" @if($fieldError('legal_name')) aria-invalid="true" aria-describedby="legal_name-error" @endif>
                        @if($fieldError('legal_name'))
                            <p class="customer-profile-error" id="legal_name-error">{{ $fieldError('legal_name') }}</p>
                        @endif
                    </div>

                    <div class="customer-profile-field span-12" data-gst-field @if(!$showGstFields) hidden @endif>
                        <label class="customer-profile-checkbox">
                            <input type="checkbox" name="same_as_customer_address" value="1" data-same-billing @checked($sameBillingAsAddress)>
                            Same as customer address
                        </label>
                    </div>
                    <div class="customer-profile-field span-12" data-gst-field @if(!$showGstFields) hidden @endif>
                        <label for="billing_address">Billing address</label>
                        <textarea id="billing_address" name="billing_address" rows="2"  @if($fieldError('billing_address')) aria-invalid="true" aria-describedby="billing_address-error" @endif>{{ $fieldValue('billing_address') }}</textarea>
                        @if($fieldError('billing_address'))
                            <p class="customer-profile-error" id="billing_address-error">{{ $fieldError('billing_address') }}</p>
                        @endif
                    </div>
                </div>
            </details>

            <details class="customer-profile-disclosure" data-optional-panel="id-proof" @if($idProofOpen) open @endif>
                <summary data-optional-toggle="id-proof">
                    ID Proof
                    @if($existingProofUrl)
                        <span class="customer-profile-summary">File on record</span>
                    @elseif(filled($fieldValue('id_proof_type')) || filled($fieldValue('id_proof_number')))
                        <span class="customer-profile-summary">Proof details entered</span>
                    @endif
                </summary>
                <div class="customer-profile-content customer-profile-grid">
                    <div class="customer-profile-field span-4">
                        <label for="id_proof_type">ID proof type</label>
                        <select id="id_proof_type" name="id_proof_type" @if($fieldError('id_proof_type')) aria-invalid="true" aria-describedby="id_proof_type-error" @endif>
                            <option value="">Select proof type</option>
                            @foreach($idProofTypeOptions as $option)
                                <option value="{{ $option }}" @selected($fieldValue('id_proof_type') === $option)>{{ $option }}</option>
                            @endforeach
                        </select>
                        @if($fieldError('id_proof_type'))
                            <p class="customer-profile-error" id="id_proof_type-error">{{ $fieldError('id_proof_type') }}</p>
                        @endif
                    </div>
                    <div class="customer-profile-field span-4">
                        <label for="id_proof_number">ID proof number</label>
                        <input id="id_proof_number" type="text" name="id_proof_number" value="{{ $fieldValue('id_proof_number') }}" @if($fieldError('id_proof_number')) aria-invalid="true" aria-describedby="id_proof_number-error" @endif>
                        @if($fieldError('id_proof_number'))
                            <p class="customer-profile-error" id="id_proof_number-error">{{ $fieldError('id_proof_number') }}</p>
                        @endif
                    </div>
                    <div class="customer-profile-field span-4">
                        <label for="id_proof_file">Upload ID proof</label>
                        <input id="id_proof_file" type="file" name="id_proof_file" accept="image/jpeg,image/png,application/pdf" @if($fieldError('id_proof_file')) aria-invalid="true" aria-describedby="id_proof_file-error" @endif>
                        @if($fieldError('id_proof_file'))
                            <p class="customer-profile-error" id="id_proof_file-error">{{ $fieldError('id_proof_file') }}</p>
                        @endif
                    </div>

                    @if($existingProofUrl)
                        <div class="customer-profile-field span-12">
                            <a href="{{ $existingProofUrl }}" target="_blank" rel="noopener" class="customer-profile-proof">
                                View Existing Proof
                                @if(filled($customerData?->id_proof_original_name))
                                    <span>{{ $customerData->id_proof_original_name }}</span>
                                @endif
                            </a>
                        </div>
                    @endif
                </div>
            </details>
        </div>
    </section>

    <div class="customer-profile-actions" data-customer-form-actions>
        <a href="{{ $cancelUrl }}" class="customer-profile-btn">Cancel</a>
        <button type="submit" class="customer-profile-btn is-primary">{{ $submitLabel }}</button>
    </div>
</div>

<script>
    (() => {
        const profile = document.querySelector('[data-customer-profile-form]');
        const fields = profile.closest('form').elements;
        const field = (name) => fields.namedItem(name);
        const customerType = field('customer_type');
        const displayName = field('name');
        const gstRegistered = field('gst_registered');
        const typeButtons = profile.querySelectorAll('[data-type-trigger]');
        const typePanels = profile.querySelectorAll('[data-type-panel]');
        const gstButtons = profile.querySelectorAll('[data-gst-trigger]');
        const gstFields = profile.querySelectorAll('[data-gst-field]');
        const sameBilling = profile.querySelector('[data-same-billing]');
        const billingAddress = field('billing_address');
        const addressFields = ['address', 'city', 'state', 'pincode'].map(field);
        const phone = field('phone');
        const copyPhone = profile.querySelector('[data-copy-customer-phone]');
        const copyStatus = profile.querySelector('[data-copy-customer-phone-status]');
        const mapText = field('map_location_text');
        const mapUrl = field('map_location_url');
        const mapPreview = profile.querySelector('[data-map-preview]');
        const mapLabel = profile.querySelector('[data-map-preview-label]');
        const mapPreviewUrl = profile.querySelector('[data-map-preview-url]');
        const mapStatus = profile.querySelector('[data-map-status]');
        const mapCurrent = profile.querySelector('[data-map-current]');

        const individualNames = ['salutation', 'first_name', 'last_name'].map(field);
        const businessNames = ['company_name', 'contact_name'].map(field);

        function updateDisplayName() {
            const business = customerType.value === 'Business';
            const names = business ? businessNames : individualNames;
            displayName.value = names.map(input => input.value).filter(Boolean).join(business ? ' - ' : ' ');
        }

        typeButtons.forEach(button => {
            button.addEventListener('click', () => {
                customerType.value = button.dataset.typeTrigger;
                typeButtons.forEach(choice => choice.setAttribute('aria-pressed', String(choice === button)));
                typePanels.forEach(panel => { panel.hidden = panel.dataset.typePanel !== customerType.value; });
                updateDisplayName();
            });
        });
        [...individualNames, ...businessNames].forEach(input => {
            input.addEventListener('input', updateDisplayName);
            input.addEventListener('change', updateDisplayName);
        });
        updateDisplayName();

        profile.querySelectorAll('[data-phone-local]').forEach(input => {
            input.addEventListener('input', () => { input.value = input.value.replace(/\D+/g, '').slice(0, 15); });
        });
        if (copyPhone) {
            const syncCopyButton = () => { copyPhone.disabled = !phone.value.trim(); };
            phone.addEventListener('input', syncCopyButton);
            phone.addEventListener('change', syncCopyButton);
            copyPhone.addEventListener('click', () => {
                field('whatsapp_number').value = phone.value;
                const whatsappCode = field('whatsapp_number_country_code');
                whatsappCode.value = field('phone_country_code').value;
                whatsappCode.dispatchEvent(new Event('change', { bubbles: true }));
                copyStatus.textContent = 'Phone number copied to WhatsApp.';
            });
        }

        gstButtons.forEach(button => {
            button.addEventListener('click', () => {
                gstRegistered.value = button.dataset.gstTrigger;
                const enabled = gstRegistered.value === '1';
                gstButtons.forEach(choice => choice.setAttribute('aria-pressed', String(choice === button)));
                gstFields.forEach(group => { group.hidden = !enabled; });
                if (!enabled) sameBilling.checked = false;
            });
        });

        function copyBillingAddress() {
            if (sameBilling.checked) {
                billingAddress.value = addressFields.map(input => input.value).filter(Boolean).join(', ');
            }
        }

        sameBilling.addEventListener('change', copyBillingAddress);
        addressFields.forEach(input => {
            input.addEventListener('input', copyBillingAddress);
            input.addEventListener('change', copyBillingAddress);
        });

        profile.addEventListener('invalid', event => {
            const panel = event.target.closest('[data-optional-panel]');
            if (panel) panel.open = true;
        }, true);

        function updateMapPreview() {
            const label = mapText.value.trim();
            const url = mapUrl.value.trim();
            mapPreview.hidden = !label && !url;
            mapLabel.textContent = label || 'Map location entered';
            mapPreviewUrl.textContent = url;
        }

        [mapText, mapUrl].forEach(input => {
            input.addEventListener('input', updateMapPreview);
            input.addEventListener('change', updateMapPreview);
        });

        function showMapStatus(message) {
            mapStatus.textContent = message;
            mapStatus.hidden = !message;
        }

        function finishLocationCapture() {
            mapCurrent.disabled = false;
            mapCurrent.textContent = 'Use Current Location';
        }

        mapCurrent.addEventListener('click', () => {
            if (!navigator.geolocation) {
                showMapStatus('Current location is unavailable. Paste a map link to continue.');
                return;
            }

            showMapStatus('');
            mapCurrent.disabled = true;
            mapCurrent.textContent = 'Capturing…';
            navigator.geolocation.getCurrentPosition(position => {
                const latitude = position.coords.latitude.toFixed(6);
                const longitude = position.coords.longitude.toFixed(6);
                const accuracy = Math.round(position.coords.accuracy || 0);
                mapUrl.value = 'https://maps.google.com/?q=' + latitude + ',' + longitude;
                mapText.value = (mapText.value.trim() || 'Current location') + ' (' + latitude + ', ' + longitude
                    + (accuracy ? ' • ±' + accuracy + 'm' : '') + ')';
                updateMapPreview();
                finishLocationCapture();
            }, () => {
                showMapStatus('Unable to capture your location. You can paste a map link instead.');
                finishLocationCapture();
            }, {
                enableHighAccuracy: true,
                timeout: 10000,
                maximumAge: 0
            });
        });

        profile.querySelector('[data-map-open]').addEventListener('click', () => {
            window.open(mapUrl.value.trim() || 'https://maps.google.com', '_blank', 'noopener');
        });
    })();
</script>
