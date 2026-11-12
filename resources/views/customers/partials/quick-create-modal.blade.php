@php
    $quickModalId = $quickModalId ?? 'quickCustomerModal';
    $quickFormId = $quickFormId ?? ($quickModalId . 'Form');
    $quickSelectTarget = $quickSelectTarget ?? 'customer_id';
    $quickRoute = $quickRoute ?? route('customers.quick-store');
    $quickStates = \App\Models\Customer::indianStates();
    $quickCountryCodes = \App\Support\PhoneNumber::countryCodeOptions();
    $quickCustomerTypes = ['Individual' => 'Individual', 'Business' => 'Business'];
    $quickSalutations = array_combine(\App\Models\Enquiry::SALUTATIONS, \App\Models\Enquiry::SALUTATIONS);
    $quickIdProofTypes = ['Aadhaar', 'PAN', 'Driving License', 'Passport', 'Other'];
    $quickPrefill = $quickPrefill ?? [];
    $quickPrefillPhone = \App\Support\PhoneNumber::split($quickPrefill['phone'] ?? null);
    $quickPrefillWhatsapp = \App\Support\PhoneNumber::split($quickPrefill['whatsapp_number'] ?? null);
    $quickAutoOpen = $quickAutoOpen ?? false;
@endphp

@once
<style>
    .quick-customer-modal {
        --quick-ink: #172944;
        --quick-muted: #52657f;
        --quick-line: #dce5f1;
        --quick-field-line: #becce0;
        --quick-blue: #234fb8;
        --quick-soft: #eef4ff;
        position: fixed;
        inset: 0;
        z-index: 1050;
        display: grid;
        place-items: center;
        padding: 16px;
    }
    .quick-customer-modal [hidden],
    .quick-customer-modal[hidden] { display: none !important; }
    body:has(.quick-customer-modal:not([hidden])) .phos-release-badge { visibility: hidden; }
    .quick-customer-modal .quick-customer-backdrop { position: absolute; inset: 0; background: rgba(16, 30, 53, .56); }
    .quick-customer-modal .quick-customer-dialog {
        position: relative;
        width: 100%;
        max-width: 784px;
        max-height: calc(100dvh - 32px);
        display: flex;
        flex-direction: column;
        color: var(--quick-ink);
        background: #fff;
        border: 1px solid var(--quick-line);
        border-radius: 18px;
        box-shadow: 0 20px 64px rgba(16, 30, 53, .24);
        overflow: hidden;
    }
    .quick-customer-dialog * { min-width: 0; }
    .quick-customer-modal .quick-customer-header {
        display: flex;
        flex: 0 0 auto;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 12px 22px;
        background: #f6f9fe;
        border-bottom: 1px solid var(--quick-line);
    }
    .quick-customer-modal .quick-customer-header h3 { margin: 0; color: var(--quick-ink); font-size: 21px; line-height: 1.25; font-weight: 750; }
    .quick-customer-modal .quick-customer-close {
        display: inline-grid;
        place-items: center;
        width: 44px;
        height: 44px;
        padding: 0;
        border: 1px solid var(--quick-line);
        border-radius: 10px;
        background: #fff;
        color: var(--quick-ink);
        font-size: 22px;
        cursor: pointer;
    }
    .quick-customer-modal .quick-customer-form {
        flex: 1 1 auto;
        min-height: 0;
        display: flex;
        flex-direction: column;
        gap: 0;
        padding: 0;
    }
    .quick-customer-body {
        min-height: 0;
        overflow: auto;
        overscroll-behavior: contain;
        scrollbar-gutter: stable;
        padding: 0 22px;
    }
    .quick-section { display: grid; gap: 11px; padding: 13px 0 15px; }
    .quick-section + .quick-section { border-top: 1px solid var(--quick-line); }
    .quick-section-head { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; }
    .quick-section h4 { margin: 0; color: var(--quick-ink); font-size: 15px; line-height: 1.25; font-weight: 750; }
    .quick-customer-modal .quick-customer-alert,
    .quick-customer-conflict {
        margin: 12px 0 0;
        padding: 10px 12px;
        border: 1px solid #efc6c4;
        border-radius: 8px;
        color: #8f211a;
        background: #fff5f4;
        font-size: 13px;
        overflow-wrap: anywhere;
    }
    .quick-customer-conflict { border-color: #e8ca8a; background: #fffbec; color: #58401b; }
    .quick-customer-modal .quick-customer-grid { display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 10px 12px; }
    .quick-customer-modal .quick-col-4 { grid-column: span 4; }
    .quick-customer-modal .quick-col-6 { grid-column: span 6; }
    .quick-customer-modal .quick-col-8 { grid-column: span 8; }
    .quick-customer-modal .quick-col-12 { grid-column: 1 / -1; }
    .quick-inline-grid { display: grid; grid-template-columns: minmax(88px, .65fr) repeat(2, minmax(0, 1fr)); gap: 10px 12px; }
    .quick-inline-grid-business { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .quick-customer-modal .quick-field { display: flex; flex-direction: column; gap: 4px; }
    .quick-customer-modal .quick-field :is(label, .quick-control-label) {
        color: var(--quick-ink);
        font-size: 13px;
        font-weight: 650;
        line-height: 1.3;
        text-transform: none;
        letter-spacing: normal;
    }
    .quick-field .quick-required { color: var(--quick-blue); }
    .quick-field-heading { display: flex; align-items: baseline; justify-content: space-between; flex-wrap: wrap; gap: 4px; }
    .quick-customer-modal .quick-field input:not([type="checkbox"]),
    .quick-customer-modal .quick-field select,
    .quick-customer-modal .quick-field textarea {
        width: 100%;
        min-height: 42px;
        box-sizing: border-box;
        padding: 8px 10px;
        border-radius: 8px;
        border: 1px solid var(--quick-field-line);
        background: #fff;
        color: var(--quick-ink);
        font-size: 14px;
        line-height: 1.4;
    }
    .quick-customer-modal .quick-field textarea { min-height: 55px; resize: vertical; }
    .quick-customer-modal :focus-visible { outline: 2px solid var(--quick-blue); outline-offset: 2px; }
    .quick-customer-modal .quick-field :is(input:not([type="checkbox"]), select, textarea):focus {
        outline: none;
        border-color: var(--quick-blue);
        box-shadow: 0 0 0 2px #d7e5ff;
    }
    .quick-customer-modal .quick-field.is-error :is(input:not([type="checkbox"]), select, textarea),
    .quick-field.is-error .quick-phone-group { border-color: #c1352b; background: #fff7f6; }
    .quick-field-error { color: #9d281e; font-size: 12px; line-height: 1.4; }
    .quick-segmented {
        display: inline-flex;
        align-self: flex-start;
        gap: 3px;
        padding: 3px;
        border: 1px solid var(--quick-line);
        border-radius: 10px;
        background: #f3f6fb;
    }
    .quick-segmented-btn {
        padding: 6px 12px;
        min-height: 34px;
        border: 1px solid transparent;
        border-radius: 7px;
        background: transparent;
        color: var(--quick-muted);
        font-size: 13px;
        font-weight: 700;
        cursor: pointer;
    }
    .quick-segmented-btn.is-active { border-color: #c1d2f5; background: #fff; color: var(--quick-blue); }
    .quick-phone-group { display: flex; align-items: stretch; border: 1px solid var(--quick-field-line); border-radius: 8px; background: #fff; }
    .quick-phone-group .country-code-picker { border-radius: 7px 0 0 7px; }
    .quick-phone-group .country-code-picker__trigger { min-height: 40px; height: 100%; border-radius: 7px 0 0 7px; }
    .quick-phone-group .country-code-picker__menu { width: min(280px, calc(100vw - 34px)); }
    .quick-customer-modal .quick-phone-group input.country-code-picker__search { padding-inline-start: 34px; }
    .quick-customer-modal .quick-phone-group input[data-phone-local] {
        flex: 1 1 auto;
        width: 1%;
        min-height: 40px;
        border: 0;
        border-radius: 0 7px 7px 0;
        background: transparent;
    }
    .quick-copy-phone {
        border: 0;
        padding: 0 0 0 4px;
        background: none;
        color: var(--quick-blue);
        font-size: 12px;
        font-weight: 650;
        cursor: pointer;
        text-decoration: underline;
        text-underline-offset: 2px;
    }
    .quick-copy-phone:disabled { color: var(--quick-muted); cursor: default; text-decoration: none; }
    .quick-optional-list { border: 1px solid var(--quick-line); border-radius: 10px; }
    .quick-optional + .quick-optional { border-top: 1px solid var(--quick-line); }
    .quick-optional-toggle {
        display: flex;
        width: 100%;
        min-height: 42px;
        justify-content: space-between;
        align-items: center;
        gap: 8px;
        padding: 8px 12px;
        border: 0;
        border-radius: 8px;
        background: transparent;
        color: var(--quick-ink);
        font-size: 13px;
        font-weight: 650;
        text-align: left;
        cursor: pointer;
    }
    .quick-optional-toggle:hover,
    .quick-optional-toggle[aria-expanded="true"] { background: var(--quick-soft); color: var(--quick-blue); }
    .quick-optional-toggle em { color: var(--quick-blue); font-size: 17px; font-weight: 500; font-style: normal; line-height: 1; }
    .quick-optional-content { padding: 12px; }
    .quick-map-tools { display: flex; flex-wrap: wrap; gap: 6px; }
    .quick-map-preview { display: grid; gap: 4px; padding: 8px 10px; background: var(--quick-soft); border-radius: 8px; font-size: 12px; overflow-wrap: anywhere; }
    .quick-customer-modal .quick-customer-actions {
        display: flex;
        flex: 0 0 auto;
        justify-content: flex-end;
        align-items: center;
        gap: 8px;
        padding: 10px 22px;
        border-top: 1px solid var(--quick-line);
        background: #fff;
    }
    .quick-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 8px 14px;
        min-height: 42px;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 650;
        border: 1px solid transparent;
        cursor: pointer;
    }
    .quick-btn-primary { background: var(--quick-blue); color: #fff; }
    .quick-btn-primary:hover:not(:disabled) { background: #193e99; }
    .quick-btn-light { background: #fff; color: var(--quick-ink); border-color: var(--quick-field-line); }
    .quick-btn-light:hover:not(:disabled) { background: var(--quick-soft); }
    .quick-btn:disabled { opacity: .65; cursor: wait; }
    .quick-sr-only { position: absolute; width: 1px; height: 1px; margin: -1px; padding: 0; overflow: hidden; clip-path: inset(50%); white-space: nowrap; }
    @media (max-width: 640px) {
        .quick-customer-modal { padding: 0; }
        .quick-customer-modal .quick-customer-dialog { height: 100dvh; max-height: 100dvh; border: 0; border-radius: 0; }
        .quick-customer-modal .quick-customer-header,
        .quick-customer-body,
        .quick-customer-modal .quick-customer-actions { padding-left: 14px; padding-right: 14px; }
        .quick-customer-modal .quick-customer-actions { padding-bottom: max(10px, env(safe-area-inset-bottom)); }
        .quick-customer-actions .quick-btn-primary { flex: 1; }
        .quick-customer-modal .quick-contact-grid > .quick-field,
        .quick-customer-modal .quick-optional-content .quick-field { grid-column: 1 / -1; }
        .quick-customer-modal .quick-address-grid .quick-col-4 { grid-column: span 6; }
        .quick-inline-grid:not(.quick-inline-grid-business) { grid-template-columns: minmax(78px, .7fr) repeat(2, minmax(0, 1fr)); }
        .quick-customer-modal .quick-field :is(input:not([type="checkbox"]), select, textarea) { font-size: 16px; }
    }
    @media (max-width: 360px) {
        .quick-inline-grid:not(.quick-inline-grid-business) { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .quick-inline-grid:not(.quick-inline-grid-business) [data-field="salutation"] { grid-column: 1 / -1; max-width: 160px; }
        .quick-inline-grid-business { grid-template-columns: minmax(0, 1fr); }
    }
</style>
@endonce

<div class="quick-customer-modal" id="{{ $quickModalId }}" hidden aria-hidden="true">
    <div class="quick-customer-backdrop" data-close-modal="overlay"></div>
    <div class="quick-customer-dialog" role="dialog" aria-modal="true" aria-labelledby="{{ $quickModalId }}Title">
        <div class="quick-customer-header">
            <h3 id="{{ $quickModalId }}Title">Add Customer</h3>
            <button type="button" class="quick-customer-close" data-close-modal="button" aria-label="Close">&times;</button>
        </div>

        <form id="{{ $quickFormId }}" action="{{ $quickRoute }}" method="POST" class="quick-customer-form" novalidate>
            @csrf
            <input type="hidden" name="quick_create" value="1">
            <input type="hidden" name="name" value="">
            <input type="hidden" name="gst_registered" value="0" data-role="gst-registered">

            <div class="quick-customer-body" data-role="quick-body">
                <div class="quick-customer-alert" data-role="quick-alert" role="alert" tabindex="-1" hidden></div>
                <section class="quick-customer-conflict" data-customer-identity-conflict aria-label="Choose the intended customer" tabindex="-1" hidden></section>
                <input type="hidden" name="customer_type" value="Individual" data-role="customer-type">

                <section class="quick-section" aria-labelledby="{{ $quickModalId }}Contact">
                    <div class="quick-section-head">
                        <h4 id="{{ $quickModalId }}Contact">Contact</h4>
                        <div class="quick-segmented" data-role="customer-type-switch" role="group" aria-label="Customer type">
                            @foreach($quickCustomerTypes as $value => $label)
                                <button type="button" class="quick-segmented-btn{{ $value === 'Individual' ? ' is-active' : '' }}" data-quick-type-trigger="{{ $value }}" aria-pressed="{{ $value === 'Individual' ? 'true' : 'false' }}">{{ $label }}</button>
                            @endforeach
                        </div>
                    </div>
                    <div class="quick-customer-grid quick-contact-grid">
                        <div class="quick-col-12">
                            <div class="quick-identity-panel" data-type-panel="Individual">
                                <div class="quick-inline-grid">
                                    <div class="quick-field" data-field="salutation">
                                        <label for="{{ $quickModalId }}Salutation">Salutation</label>
                                        <select name="salutation" id="{{ $quickModalId }}Salutation">
                                            <option value="">None</option>
                                            @foreach($quickSalutations as $value => $label)
                                                <option value="{{ $value }}" @selected(($quickPrefill['salutation'] ?? '') === $value)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        <div class="quick-field-error" data-error-for="salutation" hidden></div>
                                    </div>
                                    <div class="quick-field" data-field="first_name">
                                        <label for="{{ $quickModalId }}FirstName">First name <span class="quick-required" aria-hidden="true">*</span></label>
                                        <input type="text" name="first_name" id="{{ $quickModalId }}FirstName" autocomplete="given-name" aria-required="true" value="{{ $quickPrefill['first_name'] ?? $quickPrefill['name'] ?? '' }}">
                                        <div class="quick-field-error" data-error-for="first_name" hidden></div>
                                    </div>
                                    <div class="quick-field" data-field="last_name">
                                        <label for="{{ $quickModalId }}LastName">Last name</label>
                                        <input type="text" name="last_name" id="{{ $quickModalId }}LastName" autocomplete="family-name" value="{{ $quickPrefill['last_name'] ?? '' }}">
                                        <div class="quick-field-error" data-error-for="last_name" hidden></div>
                                    </div>
                                </div>
                            </div>

                            <div class="quick-identity-panel" data-type-panel="Business" hidden>
                                <div class="quick-inline-grid quick-inline-grid-business">
                                    <div class="quick-field" data-field="company_name">
                                        <label for="{{ $quickModalId }}CompanyName">Company name <span class="quick-required" aria-hidden="true">*</span></label>
                                        <input type="text" name="company_name" id="{{ $quickModalId }}CompanyName" autocomplete="organization" aria-required="true">
                                        <div class="quick-field-error" data-error-for="company_name" hidden></div>
                                    </div>
                                    <div class="quick-field" data-field="contact_name">
                                        <label for="{{ $quickModalId }}ContactName">Contact person</label>
                                        <input type="text" name="contact_name" id="{{ $quickModalId }}ContactName">
                                        <div class="quick-field-error" data-error-for="contact_name" hidden></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="quick-field quick-col-4" data-field="phone">
                            <label for="{{ $quickModalId }}Phone">Phone <span class="quick-required" aria-hidden="true">*</span></label>
                            <div class="quick-phone-group">
                                @include('partials.country-code-picker', [
                                    'name' => 'phone_country_code',
                                    'pickerId' => $quickModalId . 'PhoneCountryCode',
                                    'value' => $quickPrefillPhone['code'],
                                    'options' => $quickCountryCodes,
                                    'dividerColor' => '#cbd5e1',
                                    'width' => '70px',
                                ])
                                <input type="text" name="phone" id="{{ $quickModalId }}Phone" required inputmode="tel" autocomplete="tel-national" maxlength="15" pattern="[0-9]{6,15}" data-phone-local value="{{ $quickPrefillPhone['local'] }}">
                            </div>
                            <div class="quick-field-error" data-error-for="phone" hidden></div>
                        </div>

                        <div class="quick-field quick-col-4" data-field="whatsapp_number">
                            <div class="quick-field-heading">
                                <label for="{{ $quickModalId }}Whatsapp">WhatsApp</label>
                                <button type="button" class="quick-copy-phone" data-quick-copy-phone>Use phone number</button>
                                <span class="quick-sr-only" data-quick-copy-phone-status role="status"></span>
                            </div>
                            <div class="quick-phone-group">
                                @include('partials.country-code-picker', [
                                    'name' => 'whatsapp_number_country_code',
                                    'pickerId' => $quickModalId . 'WhatsappCountryCode',
                                    'value' => $quickPrefillWhatsapp['code'],
                                    'options' => $quickCountryCodes,
                                    'dividerColor' => '#cbd5e1',
                                    'width' => '70px',
                                ])
                                <input type="text" name="whatsapp_number" id="{{ $quickModalId }}Whatsapp" inputmode="tel" maxlength="15" pattern="[0-9]{6,15}" data-phone-local value="{{ $quickPrefillWhatsapp['local'] }}">
                            </div>
                            <div class="quick-field-error" data-error-for="whatsapp_number" hidden></div>
                        </div>

                        <div class="quick-field quick-col-4" data-field="email">
                            <label for="{{ $quickModalId }}Email">Email</label>
                            <input type="email" name="email" id="{{ $quickModalId }}Email" autocomplete="email" value="{{ $quickPrefill['email'] ?? '' }}">
                            <div class="quick-field-error" data-error-for="email" hidden></div>
                        </div>
                    </div>
                </section>

                <section class="quick-section" aria-labelledby="{{ $quickModalId }}AddressHeading">
                    <h4 id="{{ $quickModalId }}AddressHeading">Address</h4>
                    <div class="quick-customer-grid quick-address-grid">
                        <div class="quick-field quick-col-12" data-field="address">
                            <label for="{{ $quickModalId }}Address">Street address</label>
                            <textarea name="address" id="{{ $quickModalId }}Address" rows="2" autocomplete="street-address" placeholder="Building, street, landmark">{{ $quickPrefill['address'] ?? '' }}</textarea>
                            <div class="quick-field-error" data-error-for="address" hidden></div>
                        </div>

                        <div class="quick-field quick-col-4" data-field="city">
                            <label for="{{ $quickModalId }}City">City</label>
                            <input type="text" name="city" id="{{ $quickModalId }}City" autocomplete="address-level2" value="{{ $quickPrefill['city'] ?? '' }}">
                            <div class="quick-field-error" data-error-for="city" hidden></div>
                        </div>

                        <div class="quick-field quick-col-4" data-field="state">
                            <label for="{{ $quickModalId }}State">State</label>
                            <select name="state" id="{{ $quickModalId }}State" autocomplete="address-level1">
                                <option value="">Select state</option>
                                @foreach($quickStates as $state)
                                    <option value="{{ $state }}" @selected(($quickPrefill['state'] ?? '') === $state)>{{ $state }}</option>
                                @endforeach
                            </select>
                            <div class="quick-field-error" data-error-for="state" hidden></div>
                        </div>

                        <div class="quick-field quick-col-4" data-field="pincode">
                            <label for="{{ $quickModalId }}Pincode">Pincode</label>
                            <input type="text" name="pincode" id="{{ $quickModalId }}Pincode" autocomplete="postal-code" value="{{ $quickPrefill['pincode'] ?? '' }}">
                            <div class="quick-field-error" data-error-for="pincode" hidden></div>
                        </div>
                    </div>
                </section>

                <section class="quick-section" aria-labelledby="{{ $quickModalId }}Additional">
                    <h4 id="{{ $quickModalId }}Additional">Additional details</h4>
                    <div class="quick-optional-list">
                        <div class="quick-optional">
                            <button type="button" class="quick-optional-toggle" data-optional-toggle="map-notes" aria-expanded="false" aria-controls="{{ $quickModalId }}MapNotes">
                                Map &amp; notes <em data-optional-icon="map-notes" aria-hidden="true">+</em>
                            </button>
                            <div class="quick-optional-content" id="{{ $quickModalId }}MapNotes" data-optional-panel="map-notes" hidden>
                                <div class="quick-customer-grid">
                                    <div class="quick-field quick-col-4" data-field="map_location_text">
                                        <label for="{{ $quickModalId }}MapText">Location / Area</label>
                                        <input type="text" name="map_location_text" id="{{ $quickModalId }}MapText" placeholder="Area or landmark">
                                        <div class="quick-field-error" data-error-for="map_location_text" hidden></div>
                                    </div>

                                    <div class="quick-field quick-col-8" data-field="map_location_url">
                                        <label for="{{ $quickModalId }}MapUrl">Map Location</label>
                                        <input type="url" name="map_location_url" id="{{ $quickModalId }}MapUrl" placeholder="Google Maps Link / Location URL">
                                        <div class="quick-field-error" data-error-for="map_location_url" hidden></div>
                                    </div>

                                    <div class="quick-field quick-col-12">
                                        <div class="quick-map-tools">
                                            <button type="button" class="quick-btn quick-btn-light" data-quick-map-current>Use Current Location</button>
                                            <button type="button" class="quick-btn quick-btn-light" data-quick-map-open>Pick Location on Map</button>
                                        </div>
                                        <div class="quick-map-preview" data-quick-map-preview hidden>
                                            <strong data-quick-map-preview-label>Map location saved</strong>
                                            <span data-quick-map-preview-url></span>
                                        </div>
                                    </div>
                                    <div class="quick-field quick-col-12" data-field="notes">
                                        <label for="{{ $quickModalId }}Notes">Notes</label>
                                        <textarea name="notes" id="{{ $quickModalId }}Notes" rows="2" placeholder="Delivery notes or internal remarks"></textarea>
                                        <div class="quick-field-error" data-error-for="notes" hidden></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="quick-optional">
                            <button type="button" class="quick-optional-toggle" data-optional-toggle="gst" aria-expanded="false" aria-controls="{{ $quickModalId }}GstDetails">
                                GST &amp; billing <em data-optional-icon="gst" aria-hidden="true">+</em>
                            </button>
                            <div class="quick-optional-content" id="{{ $quickModalId }}GstDetails" data-optional-panel="gst" hidden>
                                <div class="quick-customer-grid">
                                    <div class="quick-field quick-col-8" data-field="place_of_supply">
                                        <label for="{{ $quickModalId }}PlaceOfSupply">Place of supply</label>
                                        <input type="text" name="place_of_supply" id="{{ $quickModalId }}PlaceOfSupply" placeholder="Defaults to customer state" value="{{ $quickPrefill['place_of_supply'] ?? $quickPrefill['state'] ?? '' }}">
                                        <div class="quick-field-error" data-error-for="place_of_supply" hidden></div>
                                    </div>
                                    <div class="quick-field quick-col-4">
                                        <span class="quick-control-label" id="{{ $quickModalId }}GstLabel">GST registered?</span>
                                        <div class="quick-segmented" data-role="gst-switch" role="group" aria-labelledby="{{ $quickModalId }}GstLabel">
                                            <button type="button" class="quick-segmented-btn is-active" data-gst-trigger="0" aria-pressed="true">No</button>
                                            <button type="button" class="quick-segmented-btn" data-gst-trigger="1" aria-pressed="false">Yes</button>
                                        </div>
                                        <div class="quick-field-error" data-error-for="gst_registered" hidden></div>
                                    </div>

                                    <div class="quick-field quick-col-6" data-gst-field hidden data-field="gst_number">
                                        <label for="{{ $quickModalId }}Gstin">GSTIN</label>
                                        <input type="text" name="gst_number" id="{{ $quickModalId }}Gstin" placeholder="29ABCDE1234F1Z5">
                                        <div class="quick-field-error" data-error-for="gst_number" hidden></div>
                                    </div>

                                    <div class="quick-field quick-col-6" data-gst-field hidden data-field="legal_name">
                                        <label for="{{ $quickModalId }}LegalName">Legal Name</label>
                                        <input type="text" name="legal_name" id="{{ $quickModalId }}LegalName" placeholder="Legal billing name">
                                        <div class="quick-field-error" data-error-for="legal_name" hidden></div>
                                    </div>

                                    <div class="quick-field quick-col-12" data-gst-field hidden>
                                        <label style="display:flex; align-items:center; gap:8px; text-transform:none; letter-spacing:0; font-size:13px; color:#334155;">
                                            <input type="checkbox" name="same_as_customer_address" value="1" data-quick-same-billing style="width:auto;">
                                            Same as customer address
                                        </label>
                                    </div>

                                    <div class="quick-field quick-col-12" data-gst-field hidden data-field="billing_address">
                                        <label for="{{ $quickModalId }}BillingAddress">Billing Address</label>
                                        <textarea name="billing_address" id="{{ $quickModalId }}BillingAddress" rows="2" placeholder="Billing address for GST invoices"></textarea>
                                        <div class="quick-field-error" data-error-for="billing_address" hidden></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="quick-optional">
                            <button type="button" class="quick-optional-toggle" data-optional-toggle="id-proof" aria-expanded="false" aria-controls="{{ $quickModalId }}IdProof">
                                ID proof <em data-optional-icon="id-proof" aria-hidden="true">+</em>
                            </button>
                            <div class="quick-optional-content" id="{{ $quickModalId }}IdProof" data-optional-panel="id-proof" hidden>
                                <div class="quick-customer-grid">
                                    <div class="quick-field quick-col-4" data-field="id_proof_type">
                                        <label for="{{ $quickModalId }}ProofType">ID Proof Type</label>
                                        <select name="id_proof_type" id="{{ $quickModalId }}ProofType">
                                            <option value="">Select proof type</option>
                                            @foreach($quickIdProofTypes as $value => $label)
                                                <option value="{{ $value }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        <div class="quick-field-error" data-error-for="id_proof_type" hidden></div>
                                    </div>

                                    <div class="quick-field quick-col-4" data-field="id_proof_number">
                                        <label for="{{ $quickModalId }}ProofNumber">ID Proof Number</label>
                                        <input type="text" name="id_proof_number" id="{{ $quickModalId }}ProofNumber" placeholder="Proof number">
                                        <div class="quick-field-error" data-error-for="id_proof_number" hidden></div>
                                    </div>

                                    <div class="quick-field quick-col-4" data-field="id_proof_file">
                                        <label for="{{ $quickModalId }}ProofFile">Upload ID Proof</label>
                                        <input type="file" name="id_proof_file" id="{{ $quickModalId }}ProofFile">
                                        <div class="quick-field-error" data-error-for="id_proof_file" hidden></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
            </div>

            <div class="quick-customer-actions">
                <button type="button" class="quick-btn quick-btn-light" data-close-modal="button">Cancel</button>
                <button type="submit" class="quick-btn quick-btn-primary">Save Customer</button>
            </div>
        </form>
    </div>
</div>

<script>
    (function () {
        const modal = document.getElementById(@json($quickModalId));

        if (!modal || modal.dataset.initialized === 'true') {
            return;
        }

        modal.dataset.initialized = 'true';

        const form = document.getElementById(@json($quickFormId));
        const targetSelect = document.getElementById(@json($quickSelectTarget));
        const customerTypeInput = form.querySelector('[data-role="customer-type"]');
        const customerTypeButtons = form.querySelectorAll('[data-quick-type-trigger]');
        const typePanels = form.querySelectorAll('[data-type-panel]');
        const displayNameInput = form.querySelector('input[name="name"]');
        const gstRegisteredInput = form.querySelector('[data-role="gst-registered"]');
        const gstButtons = form.querySelectorAll('[data-gst-trigger]');
        const gstFields = form.querySelectorAll('[data-gst-field]');
        const sameBillingCheckbox = form.querySelector('[data-quick-same-billing]');
        const addressField = form.querySelector('textarea[name="address"]');
        const cityField = form.querySelector('input[name="city"]');
        const stateField = form.querySelector('select[name="state"]');
        const pincodeField = form.querySelector('input[name="pincode"]');
        const billingAddressField = form.querySelector('textarea[name="billing_address"]');
        const salutationField = form.querySelector('select[name="salutation"]');
        const firstNameField = form.querySelector('input[name="first_name"]');
        const lastNameField = form.querySelector('input[name="last_name"]');
        const companyNameField = form.querySelector('input[name="company_name"]');
        const contactNameField = form.querySelector('input[name="contact_name"]');
        const phoneField = form.elements.namedItem('phone');
        const copyPhoneButton = form.querySelector('[data-quick-copy-phone]');
        const copyPhoneStatus = form.querySelector('[data-quick-copy-phone-status]');
        const mapTextField = form.querySelector('input[name="map_location_text"]');
        const mapUrlField = form.querySelector('input[name="map_location_url"]');
        const mapPreview = form.querySelector('[data-quick-map-preview]');
        const mapPreviewLabel = form.querySelector('[data-quick-map-preview-label]');
        const mapPreviewUrl = form.querySelector('[data-quick-map-preview-url]');
        const mapCurrentButton = form.querySelector('[data-quick-map-current]');
        const mapOpenButton = form.querySelector('[data-quick-map-open]');
        const alertBox = form.querySelector('[data-role="quick-alert"]');
        const conflictPanel = form.querySelector('[data-customer-identity-conflict]');
        let conflictChoice = null, savingCustomer = false, returnFocus = null;
        const clearIdentityChoice = () => { conflictChoice = null; conflictPanel.hidden = true; conflictPanel.replaceChildren(); };
        form.addEventListener('input', clearIdentityChoice);
        form.addEventListener('change', clearIdentityChoice);
        const bodyScroll = form.querySelector('[data-role="quick-body"]');
        const openButtons = document.querySelectorAll('[data-open-modal="{{ $quickModalId }}"]');
        const optionalToggles = form.querySelectorAll('[data-optional-toggle]');

        function setAlert(message, isError) {
            if (!message) {
                alertBox.hidden = true;
                alertBox.textContent = '';
                alertBox.classList.remove('is-error');
                return;
            }

            alertBox.hidden = false;
            alertBox.textContent = message;
            alertBox.classList.toggle('is-error', !!isError);
        }

        function clearFieldErrors() {
            form.querySelectorAll('[data-error-for]').forEach(function (errorNode) {
                errorNode.hidden = true;
                errorNode.textContent = '';
            });

            form.querySelectorAll('[data-field]').forEach(function (fieldNode) {
                fieldNode.classList.remove('is-error');
            });

            form.querySelectorAll('[aria-invalid="true"]').forEach(input => input.removeAttribute('aria-invalid'));
        }

        function setFieldErrors(errors) {
            Object.entries(errors || {}).forEach(function (entry) {
                const field = entry[0].replace(/_country_code$/, '');
                const message = Array.isArray(entry[1]) ? entry[1][0] : entry[1];
                const errorNode = form.querySelector('[data-error-for="' + field + '"]');
                const fieldNode = form.querySelector('[data-field="' + field + '"]');
                const input = form.elements.namedItem(field);

                if (fieldNode) {
                    fieldNode.classList.add('is-error');
                    const panel = fieldNode.closest('[data-optional-panel]');
                    if (panel) syncOptionalPanel(panel.dataset.optionalPanel, true);
                    if (input instanceof HTMLElement) input.setAttribute('aria-invalid', 'true');
                }

                if (errorNode) {
                    errorNode.hidden = false;
                    errorNode.textContent = message;
                }
            });
        }

        function buildDisplayName(type) {
            if (type === 'Business') {
                return [companyNameField?.value || '', contactNameField?.value || '']
                    .filter(Boolean)
                    .join(' - ');
            }

            return [salutationField?.value || '', firstNameField?.value || '', lastNameField?.value || '']
                .filter(Boolean)
                .join(' ');
        }

        function syncTypePanels() {
            const currentType = customerTypeInput.value || 'Individual';

            customerTypeButtons.forEach(function (button) {
                const selected = button.dataset.quickTypeTrigger === currentType;
                button.classList.toggle('is-active', selected);
                button.setAttribute('aria-pressed', String(selected));
            });

            typePanels.forEach(function (panel) {
                panel.hidden = panel.getAttribute('data-type-panel') !== currentType;
            });

            displayNameInput.value = buildDisplayName(currentType);
        }

        function syncOptionalPanel(key, forceOpen) {
            const panel = form.querySelector('[data-optional-panel="' + key + '"]');
            const icon = form.querySelector('[data-optional-icon="' + key + '"]');
            const toggle = form.querySelector('[data-optional-toggle="' + key + '"]');

            if (!panel || !toggle) {
                return;
            }

            const nextHidden = forceOpen === undefined ? !panel.hidden : !forceOpen;
            panel.hidden = nextHidden;
            toggle.setAttribute('aria-expanded', String(!nextHidden));
            if (icon) icon.textContent = nextHidden ? '+' : '−';
        }

        function syncGstFields() {
            const enabled = gstRegisteredInput.value === '1';

            gstButtons.forEach(function (button) {
                const selected = button.dataset.gstTrigger === gstRegisteredInput.value;
                button.classList.toggle('is-active', selected);
                button.setAttribute('aria-pressed', String(selected));
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

            billingAddressField.value = [addressField?.value || '', cityField?.value || '', stateField?.value || '', pincodeField?.value || '']
                .filter(Boolean)
                .join(', ');
        }

        function syncMapPreview() {
            const label = (mapTextField?.value || '').trim();
            const url = (mapUrlField?.value || '').trim();
            const hasValue = label !== '' || url !== '';

            mapPreview.hidden = !hasValue;
            mapPreviewLabel.textContent = label !== '' ? label : 'Map location saved';
            mapPreviewUrl.textContent = url;
        }

        function resetCustomerModal() {
            clearIdentityChoice();
            form.reset();
            form.querySelectorAll('[data-country-code-value]').forEach(input => input.dispatchEvent(new Event('change')));
            if (copyPhoneStatus) copyPhoneStatus.textContent = '';
            customerTypeInput.value = 'Individual';
            gstRegisteredInput.value = '0';
            clearFieldErrors();
            setAlert('', false);
            syncTypePanels();
            optionalToggles.forEach(toggle => syncOptionalPanel(toggle.dataset.optionalToggle, false));
            syncGstFields();
            syncMapPreview();
        }

        function openCustomerModal(trigger) {
            returnFocus = trigger ?? Array.from(openButtons).find(button => button.getClientRects().length);
            resetCustomerModal();
            form.dispatchEvent(new CustomEvent('quick-customer:prefill'));
            updateCopyPhoneButton();
            modal.hidden = false;
            modal.setAttribute('aria-hidden', 'false');
            window.rentnexisModalLock?.lock();
            bodyScroll.scrollTop = 0;
            firstNameField.focus({ preventScroll: true });
        }

        function closeCustomerModal() {
            if (modal.hidden) return;
            modal.hidden = true;
            modal.setAttribute('aria-hidden', 'true');
            window.rentnexisModalLock?.unlock();
            setAlert('', false);
            returnFocus?.focus({ preventScroll: true });
            returnFocus = null;
        }

        function updateCopyPhoneButton() {
            if (copyPhoneButton) copyPhoneButton.disabled = !phoneField.value;
        }

        copyPhoneButton?.addEventListener('click', function () {
            if (!phoneField.value) return;
            const whatsapp = form.elements.namedItem('whatsapp_number');
            const whatsappCode = form.elements.namedItem('whatsapp_number_country_code');
            whatsapp.value = phoneField.value;
            whatsappCode.value = form.elements.namedItem('phone_country_code').value;
            whatsappCode.dispatchEvent(new Event('change', { bubbles: true }));
            whatsapp.dispatchEvent(new Event('input', { bubbles: true }));
            if (copyPhoneStatus) copyPhoneStatus.textContent = 'Phone number copied to WhatsApp.';
        });

        function appendAndSelectCustomer(customer) {
            if (!targetSelect || !customer) {
                return;
            }

            const countryCode = customer.phone_country_code || '+91';
            const phoneDigits = String(customer.phone || '').replace(/\D+/g, '');
            const codeDigits = String(countryCode).replace(/\D+/g, '');
            const localPhone = phoneDigits.startsWith(codeDigits)
                ? phoneDigits.slice(codeDigits.length)
                : phoneDigits;

            const existingOption = targetSelect.querySelector('option[value="' + customer.id + '"]');
            const option = existingOption || document.createElement('option');

            option.value = customer.id;
            option.textContent = customer.phone ? customer.name + ' - ' + customer.phone : customer.name;
            option.setAttribute('data-name', customer.name || '');
            option.setAttribute('data-phone', localPhone || '');
            option.setAttribute('data-phone-country', countryCode);
            ['address','city','state','email','salutation'].forEach(key => option.setAttribute('data-'+key, customer[key] || ''));
            option.setAttribute('data-location', customer.map_location_url || '');
            option.setAttribute('data-search', [customer.name,customer.phone,customer.email,customer.city].filter(Boolean).join(' - '));

            if (!existingOption) {
                targetSelect.appendChild(option);
            }

            targetSelect.value = String(customer.id);
            targetSelect.dispatchEvent(new Event('change', { bubbles: true }));
        }

        customerTypeButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                customerTypeInput.value = button.getAttribute('data-quick-type-trigger') || 'Individual';
                syncTypePanels();
            });
        });

        [salutationField, firstNameField, lastNameField, companyNameField, contactNameField].forEach(function (input) {
            input?.addEventListener('input', function () {
                displayNameInput.value = buildDisplayName(customerTypeInput.value);
            });

            input?.addEventListener('change', function () {
                displayNameInput.value = buildDisplayName(customerTypeInput.value);
            });
        });

        optionalToggles.forEach(function (button) {
            button.addEventListener('click', function () {
                syncOptionalPanel(button.getAttribute('data-optional-toggle'));
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
                setAlert('Current location is not supported on this device. You can still paste a Google Maps link manually.', true);
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
                setAlert('', false);
            }, function () {
                setAlert('Unable to capture current location. You can still paste a Google Maps link manually.', true);
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

        openButtons.forEach(function (button) {
            button.addEventListener('click', function (event) {
                event.preventDefault();
                openCustomerModal(button);
            });
        });

        modal.addEventListener('click', function (event) {
            if (event.target === modal || event.target.closest('[data-close-modal]')) {
                closeCustomerModal();
            }
        });

        modal.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                const openPicker = modal.querySelector('[data-country-code-trigger][aria-expanded="true"]');
                if (openPicker) {
                    openPicker.click();
                    openPicker.focus({ preventScroll: true });
                } else {
                    closeCustomerModal();
                }
                return;
            }

            if (event.key !== 'Tab') return;
            const tabbable = Array.from(modal.querySelectorAll('button, input, select, textarea, a[href]'))
                .filter(control => !control.disabled && control.tabIndex >= 0 && control.getClientRects().length);
            const first = tabbable[0], last = tabbable.at(-1);
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        });

        form.querySelectorAll('[data-phone-local]').forEach(function (input) {
            input.addEventListener('input', function () {
                input.value = input.value.replace(/\D+/g, '').slice(0, 15);
            });
        });
        phoneField.addEventListener('input', updateCopyPhoneButton);
        phoneField.addEventListener('change', updateCopyPhoneButton);

        function showIdentityConflict(payload) {
            conflictPanel.replaceChildren(); conflictPanel.hidden = false;
            const title = document.createElement('strong'); title.textContent = 'Choose the intended customer'; conflictPanel.append(title);
            const explanation = document.createElement('p'); explanation.textContent = payload.message; explanation.style.cssText = 'font-size:13px;margin:7px 0'; conflictPanel.append(explanation);
            const matches = Array.isArray(payload.matches) ? payload.matches : [];
            const separate = document.createElement('button'); separate.type = 'button'; separate.className = 'quick-btn quick-btn-primary'; separate.dataset.customerConflictChoice = '';
            separate.textContent = 'Create ' + (payload.entered_name || 'this person') + ' as a separate customer';
            separate.addEventListener('click', () => { conflictChoice = {action:'separate',ids:matches.map(customer=>customer.id)}; form.requestSubmit(); }); conflictPanel.append(separate);
            for (const customer of matches) {
                const row = document.createElement('div'); row.style.cssText = 'margin-top:8px;display:flex;gap:8px;align-items:center;flex-wrap:wrap';
                const description = document.createElement('span'); description.textContent = [customer.name,customer.phone,customer.email].filter(Boolean).join(' · '); description.style.cssText = 'font-size:12px;overflow-wrap:anywhere';
                const use = document.createElement('button'); use.type='button';use.className='quick-btn quick-btn-light';use.dataset.customerConflictChoice='';use.textContent='Use '+customer.name+"'s profile";
                use.addEventListener('click',()=>{conflictChoice={action:'existing',id:customer.id};form.requestSubmit();});row.append(description,use);conflictPanel.append(row);
            }
            conflictPanel.focus();
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            if (savingCustomer) return;
            savingCustomer = true;
            const saveButton = form.querySelector('button[type=submit]');
            if (saveButton) saveButton.disabled = true;
            conflictPanel.querySelectorAll('button').forEach(button => button.disabled = true);
            setAlert('', false);
            clearFieldErrors();
            displayNameInput.value = buildDisplayName(customerTypeInput.value);

            const formData = new FormData(form);
            if (conflictChoice) {
                formData.set('quick_conflict_action', conflictChoice.action);
                if (conflictChoice.id) formData.set('quick_existing_customer_id', String(conflictChoice.id));
                for (const id of conflictChoice.ids || []) formData.append('quick_conflict_customer_ids[]', String(id));
            }

            fetch(form.action, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': form.querySelector('input[name="_token"]').value
                },
                body: formData
            })
                .then(function (response) {
                    return response.json().then(function (payload) {
                        if (!response.ok) {
                            throw payload;
                        }

                        return payload;
                    });
                })
                .then(function (payload) {
                    if (!payload?.customer?.id) throw {message:'The customer could not be selected. Please try again.'};
                    appendAndSelectCustomer(payload.customer);
                    resetCustomerModal();
                    closeCustomerModal();
                })
                .catch(function (payload) {
                    if (payload?.code === 'customer_identity_conflict') { showIdentityConflict(payload); return; }
                    const errors = payload && payload.errors ? payload.errors : {};
                    setFieldErrors(errors);

                    const message = payload && payload.message
                        ? payload.message
                        : 'Unable to save customer. Please check the required fields.';

                    if (errors.gst_number || errors.legal_name || errors.billing_address || errors.gst_registered) {
                        syncOptionalPanel('gst', true);
                        gstRegisteredInput.value = '1';
                        syncGstFields();
                    }

                    setAlert(message, true);
                    const firstError = Array.from(form.querySelectorAll('[aria-invalid="true"]'))
                        .find(input => input.getClientRects().length);
                    (firstError ?? alertBox).focus();
                }).finally(function () {
                    savingCustomer = false;
                    if (saveButton) saveButton.disabled = false;
                    conflictPanel.querySelectorAll('button').forEach(button => button.disabled = false);
                });
        });

        syncTypePanels();
        syncGstFields();
        syncMapPreview();
        if (@json((bool) $quickAutoOpen)) {
            const openForConfirmedProspect = () => { if (!targetSelect?.value) openCustomerModal(); };
            if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', openForConfirmedProspect, {once:true});
            else openForConfirmedProspect();
        }
    })();
</script>
