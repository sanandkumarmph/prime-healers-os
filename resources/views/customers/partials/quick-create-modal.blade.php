@php
    $quickModalId = $quickModalId ?? 'quickCustomerModal';
    $quickFormId = $quickFormId ?? ($quickModalId . 'Form');
    $quickSelectTarget = $quickSelectTarget ?? 'customer_id';
    $quickRoute = $quickRoute ?? route('customers.quick-store');
    $quickStates = \App\Models\Customer::indianStates();
    $quickCountryCodes = \App\Support\PhoneNumber::countryCodeOptions();
    $quickCustomerTypes = ['Individual' => 'Individual', 'Business' => 'Business'];
    $quickSalutations = ['Mr.' => 'Mr.', 'Mrs.' => 'Mrs.', 'Ms.' => 'Ms.', 'Dr.' => 'Dr.'];
    $quickIdProofTypes = ['Aadhaar', 'PAN', 'Driving License', 'Passport', 'Other'];
@endphp

@once
<style>
    .quick-customer-modal {
        position: fixed;
        inset: 0;
        z-index: 1050;
        display: grid;
        place-items: center;
        padding: 12px;
        overflow: hidden;
    }
    .quick-customer-modal[hidden] { display: none !important; }
    .quick-customer-backdrop {
        position: absolute;
        inset: 0;
        background: rgba(15, 23, 42, 0.52);
    }
    .quick-customer-dialog {
        position: relative;
        width: 100%;
        max-width: 840px;
        max-height: min(92vh, calc(100dvh - 24px));
        display: flex;
        flex-direction: column;
        background: #fff;
        border-radius: 20px;
        border: 1px solid #dbe3ef;
        box-shadow: 0 24px 60px rgba(15, 23, 42, 0.22);
        overflow: hidden;
    }
    .quick-customer-dialog * { min-width: 0; }
    .quick-customer-header {
        flex: 0 0 auto;
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 12px;
        padding: 18px 20px 14px;
        border-bottom: 1px solid #e2e8f0;
        background: #fff;
        z-index: 2;
    }
    .quick-customer-header h3 { margin: 0; font-size: 19px; color: #0f172a; line-height: 1.25; }
    .quick-customer-header p { margin: 6px 0 0; color: #64748b; font-size: 13px; line-height: 1.5; }
    .quick-customer-close {
        flex: 0 0 auto;
        width: 44px;
        height: 44px;
        min-width: 44px;
        min-height: 44px;
        border: 1px solid #cbd5e1;
        border-radius: 999px;
        background: #f8fafc;
        color: #334155;
        cursor: pointer;
        font-size: 24px;
        line-height: 1;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }
    .quick-customer-form {
        flex: 1 1 auto;
        min-height: 0;
        display: flex;
        flex-direction: column;
    }
    .quick-customer-body {
        flex: 1 1 auto;
        min-height: 0;
        overflow-y: auto;
        overflow-x: hidden;
        -webkit-overflow-scrolling: touch;
        overscroll-behavior: contain;
        padding: 16px 20px 18px;
        display: grid;
        gap: 14px;
    }
    .quick-customer-alert {
        border-radius: 12px;
        padding: 10px 12px;
        font-size: 12px;
        border: 1px solid transparent;
    }
    .quick-customer-alert.is-error { background: #fef2f2; border-color: #fecaca; color: #b91c1c; }
    .quick-customer-grid { display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 12px; }
    .quick-col-3 { grid-column: span 3; }
    .quick-col-4 { grid-column: span 4; }
    .quick-col-6 { grid-column: span 6; }
    .quick-col-8 { grid-column: span 8; }
    .quick-col-12 { grid-column: span 12; }
    .quick-inline-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
    .quick-field { display: flex; flex-direction: column; gap: 6px; }
    .quick-field label {
        font-size: 11px;
        font-weight: 700;
        color: #475569;
        letter-spacing: .04em;
        text-transform: uppercase;
    }
    .quick-field input,
    .quick-field select,
    .quick-field textarea {
        width: 100%;
        box-sizing: border-box;
        padding: 10px 12px;
        border-radius: 12px;
        border: 1px solid #cbd5e1;
        background: #fff;
        color: #0f172a;
        font-size: 14px;
        line-height: 1.45;
    }
    .quick-field textarea { resize: vertical; min-height: 92px; }
    .quick-field.is-error input,
    .quick-field.is-error select,
    .quick-field.is-error textarea,
    .quick-identity-panel.is-error,
    .quick-optional-content.is-error {
        border-color: #dc2626;
        box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        background: #fff7f7;
    }
    .quick-field-error {
        color: #b91c1c;
        font-size: 12px;
        line-height: 1.4;
    }
    .quick-segmented {
        display: inline-flex;
        gap: 6px;
        padding: 4px;
        border: 1px solid #dbe3ef;
        border-radius: 999px;
        background: #f8fafc;
        flex-wrap: wrap;
    }
    .quick-segmented-btn {
        border: none;
        border-radius: 999px;
        background: transparent;
        color: #475569;
        padding: 9px 14px;
        font-size: 13px;
        font-weight: 700;
        cursor: pointer;
        transition: all .15s ease;
    }
    .quick-segmented-btn.is-active {
        background: #0f172a;
        color: #fff;
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.12);
    }
    .quick-identity-panel {
        display: grid;
        gap: 12px;
        padding: 14px;
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        background: #f8fafc;
    }
    .quick-identity-panel[hidden] { display: none !important; }
    .quick-phone-group {
        display: flex;
        align-items: center;
        border: 1px solid #cbd5e1;
        border-radius: 12px;
        overflow: visible;
        background: #fff;
        min-width: 0;
    }
    .quick-phone-group .country-code-picker {
        flex: 0 0 88px;
        min-width: 88px;
        max-width: 88px;
        align-self: stretch;
    }
    .quick-phone-group .country-code-picker__trigger {
        min-height: 44px;
        padding: 10px 10px 10px 12px;
        box-shadow: none !important;
    }
    .quick-phone-group input[data-phone-local] {
        flex: 1 1 auto;
        min-width: 0;
        width: 1%;
        padding: 10px 14px;
        border: none !important;
        box-shadow: none !important;
        background: transparent !important;
        border-radius: 0 !important;
    }
    .quick-field.is-error .quick-phone-group {
        border-color: #dc2626;
        box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        background: #fff7f7;
    }
    .quick-optional {
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        background: #fbfdff;
        overflow: hidden;
    }
    .quick-optional-toggle {
        width: 100%;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        border: none;
        background: transparent;
        padding: 14px 16px;
        cursor: pointer;
        text-align: left;
    }
    .quick-optional-toggle strong {
        font-size: 13px;
        color: #0f172a;
    }
    .quick-optional-toggle span {
        color: #64748b;
        font-size: 12px;
    }
    .quick-optional-toggle em {
        font-style: normal;
        color: #475569;
        font-weight: 700;
        font-size: 16px;
    }
    .quick-optional-content {
        border-top: 1px solid #eef2f7;
        padding: 16px;
        display: grid;
        gap: 14px;
    }
    .quick-optional-content[hidden] { display: none !important; }
    .quick-map-tools {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }
    .quick-map-preview {
        border: 1px dashed #cbd5e1;
        border-radius: 14px;
        background: #f8fafc;
        padding: 12px 14px;
        display: grid;
        gap: 4px;
        color: #334155;
        font-size: 13px;
        line-height: 1.5;
    }
    .quick-map-preview[hidden] { display: none !important; }
    .quick-customer-actions {
        flex: 0 0 auto;
        display: flex;
        justify-content: flex-end;
        gap: 8px;
        flex-wrap: wrap;
        padding: 14px 20px 16px;
        border-top: 1px solid #e2e8f0;
        background: #fff;
    }
    .quick-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        min-height: 44px;
        padding: 10px 14px;
        border-radius: 12px;
        font-size: 14px;
        font-weight: 600;
        border: 1px solid transparent;
        cursor: pointer;
    }
    .quick-btn-primary { background: #0f172a; color: #fff; }
    .quick-btn-light { background: #fff; color: #334155; border-color: #cbd5e1; }
    @media (max-width: 640px) {
        .quick-customer-modal {
            padding: 0;
            place-items: end center;
        }
        .quick-customer-dialog {
            max-width: 100%;
            max-height: 100dvh;
            height: 100dvh;
            border-radius: 0;
        }
        .quick-customer-header,
        .quick-customer-body,
        .quick-customer-actions {
            padding-left: 14px;
            padding-right: 14px;
        }
        .quick-col-3,
        .quick-col-4,
        .quick-col-6,
        .quick-col-8 { grid-column: span 12; }
        .quick-inline-grid { grid-template-columns: 1fr; }
        .quick-map-tools,
        .quick-customer-actions {
            display: grid;
            grid-template-columns: 1fr;
        }
        .quick-btn { width: 100%; }
    }
</style>
@endonce

<div class="quick-customer-modal" id="{{ $quickModalId }}" hidden aria-hidden="true">
    <div class="quick-customer-backdrop" data-close-modal="overlay"></div>
    <div class="quick-customer-dialog" role="dialog" aria-modal="true" aria-labelledby="{{ $quickModalId }}Title">
        <div class="quick-customer-header">
            <div>
                <h3 id="{{ $quickModalId }}Title">Add Customer</h3>
                <p>Create an individual or business customer without leaving this workflow. Business Partner remains a separate module.</p>
            </div>
            <button type="button" class="quick-customer-close" data-close-modal="button" aria-label="Close">&times;</button>
        </div>

        <form id="{{ $quickFormId }}" action="{{ $quickRoute }}" method="POST" class="quick-customer-form" novalidate>
            @csrf
            <input type="hidden" name="quick_create" value="1">
            <input type="hidden" name="name" value="">
            <input type="hidden" name="gst_registered" value="0" data-role="gst-registered">

            <div class="quick-customer-body" data-role="quick-body">
                <div class="quick-customer-alert" data-role="quick-alert" hidden></div>

                <div>
                    <div class="quick-segmented" data-role="customer-type-switch">
                        @foreach($quickCustomerTypes as $value => $label)
                            <button type="button" class="quick-segmented-btn{{ $value === 'Individual' ? ' is-active' : '' }}" data-quick-type-trigger="{{ $value }}">{{ $label }}</button>
                        @endforeach
                    </div>
                </div>

                <input type="hidden" name="customer_type" value="Individual" data-role="customer-type">

                <div class="quick-customer-grid">
                    <div class="quick-col-12">
                        <div class="quick-identity-panel" data-type-panel="Individual">
                            <div class="quick-inline-grid">
                                <div class="quick-field" data-field="salutation">
                                    <label for="{{ $quickModalId }}Salutation">Salutation</label>
                                    <select name="salutation" id="{{ $quickModalId }}Salutation">
                                        <option value="">Select</option>
                                        @foreach($quickSalutations as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    <div class="quick-field-error" data-error-for="salutation" hidden></div>
                                </div>
                                <div class="quick-field" data-field="first_name">
                                    <label for="{{ $quickModalId }}FirstName">First Name</label>
                                    <input type="text" name="first_name" id="{{ $quickModalId }}FirstName" placeholder="First name">
                                    <div class="quick-field-error" data-error-for="first_name" hidden></div>
                                </div>
                                <div class="quick-field" data-field="last_name">
                                    <label for="{{ $quickModalId }}LastName">Last Name</label>
                                    <input type="text" name="last_name" id="{{ $quickModalId }}LastName" placeholder="Last name">
                                    <div class="quick-field-error" data-error-for="last_name" hidden></div>
                                </div>
                            </div>
                        </div>

                        <div class="quick-identity-panel" data-type-panel="Business" hidden>
                            <div class="quick-inline-grid" style="grid-template-columns:repeat(2, minmax(0, 1fr));">
                                <div class="quick-field" data-field="company_name">
                                    <label for="{{ $quickModalId }}CompanyName">Company Name</label>
                                    <input type="text" name="company_name" id="{{ $quickModalId }}CompanyName" placeholder="Company name">
                                    <div class="quick-field-error" data-error-for="company_name" hidden></div>
                                </div>
                                <div class="quick-field" data-field="contact_name">
                                    <label for="{{ $quickModalId }}ContactName">Contact Person</label>
                                    <input type="text" name="contact_name" id="{{ $quickModalId }}ContactName" placeholder="Primary contact">
                                    <div class="quick-field-error" data-error-for="contact_name" hidden></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="quick-field quick-col-4" data-field="phone">
                        <label for="{{ $quickModalId }}Phone">Phone</label>
                        <div class="quick-phone-group">
                            @include('partials.country-code-picker', [
                                'name' => 'phone_country_code',
                                'pickerId' => $quickModalId . 'PhoneCountryCode',
                                'value' => \App\Support\PhoneNumber::DEFAULT_CODE,
                                'options' => $quickCountryCodes,
                                'dividerColor' => '#cbd5e1',
                                'width' => '92px',
                            ])
                            <input type="text" name="phone" id="{{ $quickModalId }}Phone" placeholder="Primary phone" required inputmode="numeric" maxlength="15" pattern="[0-9]{6,15}" data-phone-local>
                        </div>
                        <div class="quick-field-error" data-error-for="phone" hidden></div>
                    </div>

                    <div class="quick-field quick-col-4" data-field="whatsapp_number">
                        <label for="{{ $quickModalId }}Whatsapp">WhatsApp</label>
                        <div class="quick-phone-group">
                            @include('partials.country-code-picker', [
                                'name' => 'whatsapp_number_country_code',
                                'pickerId' => $quickModalId . 'WhatsappCountryCode',
                                'value' => \App\Support\PhoneNumber::DEFAULT_CODE,
                                'options' => $quickCountryCodes,
                                'dividerColor' => '#cbd5e1',
                                'width' => '92px',
                            ])
                            <input type="text" name="whatsapp_number" id="{{ $quickModalId }}Whatsapp" placeholder="WhatsApp number" inputmode="numeric" maxlength="15" pattern="[0-9]{6,15}" data-phone-local>
                        </div>
                        <div class="quick-field-error" data-error-for="whatsapp_number" hidden></div>
                    </div>

                    <div class="quick-field quick-col-4" data-field="email">
                        <label for="{{ $quickModalId }}Email">Email</label>
                        <input type="email" name="email" id="{{ $quickModalId }}Email" placeholder="Email address">
                        <div class="quick-field-error" data-error-for="email" hidden></div>
                    </div>

                    <div class="quick-field quick-col-6" data-field="address">
                        <label for="{{ $quickModalId }}Address">Address</label>
                        <textarea name="address" id="{{ $quickModalId }}Address" rows="2" placeholder="Street, building, landmark"></textarea>
                        <div class="quick-field-error" data-error-for="address" hidden></div>
                    </div>

                    <div class="quick-field quick-col-6" data-field="notes">
                        <label for="{{ $quickModalId }}Notes">Notes</label>
                        <textarea name="notes" id="{{ $quickModalId }}Notes" rows="2" placeholder="Delivery notes or internal remarks"></textarea>
                        <div class="quick-field-error" data-error-for="notes" hidden></div>
                    </div>

                    <div class="quick-field quick-col-3" data-field="city">
                        <label for="{{ $quickModalId }}City">City</label>
                        <input type="text" name="city" id="{{ $quickModalId }}City" placeholder="City">
                        <div class="quick-field-error" data-error-for="city" hidden></div>
                    </div>

                    <div class="quick-field quick-col-3" data-field="state">
                        <label for="{{ $quickModalId }}State">State</label>
                        <select name="state" id="{{ $quickModalId }}State">
                            <option value="">Select state</option>
                            @foreach($quickStates as $state)
                                <option value="{{ $state }}">{{ $state }}</option>
                            @endforeach
                        </select>
                        <div class="quick-field-error" data-error-for="state" hidden></div>
                    </div>

                    <div class="quick-field quick-col-3" data-field="pincode">
                        <label for="{{ $quickModalId }}Pincode">Pincode</label>
                        <input type="text" name="pincode" id="{{ $quickModalId }}Pincode" placeholder="Pincode">
                        <div class="quick-field-error" data-error-for="pincode" hidden></div>
                    </div>

                    <div class="quick-field quick-col-3" data-field="place_of_supply">
                        <label for="{{ $quickModalId }}PlaceOfSupply">Place of Supply</label>
                        <input type="text" name="place_of_supply" id="{{ $quickModalId }}PlaceOfSupply" placeholder="Defaults from state">
                        <div class="quick-field-error" data-error-for="place_of_supply" hidden></div>
                    </div>

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
                </div>

                <section class="quick-optional">
                    <button type="button" class="quick-optional-toggle" data-optional-toggle="gst">
                        <span>
                            <strong>GST Details</strong>
                            <span>Optional. Expand only when this direct customer needs GST billing details.</span>
                        </span>
                        <em data-optional-icon="gst">+</em>
                    </button>
                    <div class="quick-optional-content" data-optional-panel="gst" hidden>
                        <div class="quick-customer-grid">
                            <div class="quick-field quick-col-4">
                                <label>GST Registered?</label>
                                <div class="quick-segmented" data-role="gst-switch">
                                    <button type="button" class="quick-segmented-btn is-active" data-gst-trigger="0">No</button>
                                    <button type="button" class="quick-segmented-btn" data-gst-trigger="1">Yes</button>
                                </div>
                                <div class="quick-field-error" data-error-for="gst_registered" hidden></div>
                            </div>

                            <div class="quick-field quick-col-4" data-gst-field hidden data-field="gst_number">
                                <label for="{{ $quickModalId }}Gstin">GSTIN</label>
                                <input type="text" name="gst_number" id="{{ $quickModalId }}Gstin" placeholder="29ABCDE1234F1Z5">
                                <div class="quick-field-error" data-error-for="gst_number" hidden></div>
                            </div>

                            <div class="quick-field quick-col-4" data-gst-field hidden data-field="legal_name">
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
                </section>

                <section class="quick-optional">
                    <button type="button" class="quick-optional-toggle" data-optional-toggle="id-proof">
                        <span>
                            <strong>ID Proof</strong>
                            <span>Optional. Keep this tucked away unless KYC proof is needed right now.</span>
                        </span>
                        <em data-optional-icon="id-proof">+</em>
                    </button>
                    <div class="quick-optional-content" data-optional-panel="id-proof" hidden>
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
        const mapTextField = form.querySelector('input[name="map_location_text"]');
        const mapUrlField = form.querySelector('input[name="map_location_url"]');
        const mapPreview = form.querySelector('[data-quick-map-preview]');
        const mapPreviewLabel = form.querySelector('[data-quick-map-preview-label]');
        const mapPreviewUrl = form.querySelector('[data-quick-map-preview-url]');
        const mapCurrentButton = form.querySelector('[data-quick-map-current]');
        const mapOpenButton = form.querySelector('[data-quick-map-open]');
        const alertBox = form.querySelector('[data-role="quick-alert"]');
        const bodyScroll = form.querySelector('[data-role="quick-body"]');
        const openButtons = document.querySelectorAll('[data-open-modal="{{ $quickModalId }}"]');
        const closeButtons = modal.querySelectorAll('[data-close-modal]');
        const firstFocusableField = form.querySelector('input, select, textarea');
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
        }

        function setFieldErrors(errors) {
            Object.entries(errors || {}).forEach(function (entry) {
                const field = entry[0];
                const message = Array.isArray(entry[1]) ? entry[1][0] : entry[1];
                const errorNode = form.querySelector('[data-error-for="' + field + '"]');
                const fieldNode = form.querySelector('[data-field="' + field + '"]');

                if (fieldNode) {
                    fieldNode.classList.add('is-error');
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
                button.classList.toggle('is-active', button.getAttribute('data-quick-type-trigger') === currentType);
            });

            typePanels.forEach(function (panel) {
                panel.hidden = panel.getAttribute('data-type-panel') !== currentType;
            });

            displayNameInput.value = buildDisplayName(currentType);
        }

        function syncOptionalPanel(key, forceOpen) {
            const panel = form.querySelector('[data-optional-panel="' + key + '"]');
            const icon = form.querySelector('[data-optional-icon="' + key + '"]');

            if (!panel || !icon) {
                return;
            }

            const nextHidden = forceOpen === undefined ? !panel.hidden : !forceOpen;
            panel.hidden = nextHidden;
            icon.textContent = nextHidden ? '+' : '−';
        }

        function syncGstFields() {
            const enabled = gstRegisteredInput.value === '1';

            gstButtons.forEach(function (button) {
                button.classList.toggle('is-active', button.getAttribute('data-gst-trigger') === gstRegisteredInput.value);
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
            form.reset();
            customerTypeInput.value = 'Individual';
            gstRegisteredInput.value = '0';
            clearFieldErrors();
            setAlert('', false);
            syncTypePanels();
            syncOptionalPanel('gst', false);
            syncOptionalPanel('id-proof', false);
            syncGstFields();
            syncMapPreview();
        }

        function openCustomerModal() {
            resetCustomerModal();
            modal.hidden = false;
            modal.setAttribute('aria-hidden', 'false');
            window.rentnexisModalLock?.lock();

            window.requestAnimationFrame(function () {
                if (firstFocusableField) {
                    firstFocusableField.focus({ preventScroll: true });
                    window.rentnexisModalScrollFieldIntoView?.(firstFocusableField, bodyScroll);
                }
            });
        }

        function closeCustomerModal() {
            modal.hidden = true;
            modal.setAttribute('aria-hidden', 'true');
            window.rentnexisModalLock?.unlock();
            setAlert('', false);
        }

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
                openCustomerModal();
            });
        });

        closeButtons.forEach(function (button) {
            button.addEventListener('click', function (event) {
                event.preventDefault();
                closeCustomerModal();
            });
        });

        modal.addEventListener('click', function (event) {
            if (event.target === modal || event.target.hasAttribute('data-close-modal')) {
                closeCustomerModal();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !modal.hidden) {
                closeCustomerModal();
            }
        });

        modal.addEventListener('focusin', function (event) {
            const target = event.target;

            if (target instanceof HTMLElement && target.matches('input, select, textarea')) {
                window.rentnexisModalScrollFieldIntoView?.(target, bodyScroll);
            }
        });

        form.querySelectorAll('[data-phone-local]').forEach(function (input) {
            input.addEventListener('input', function () {
                input.value = input.value.replace(/\D+/g, '').slice(0, 15);
            });
        });

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            setAlert('', false);
            clearFieldErrors();
            displayNameInput.value = buildDisplayName(customerTypeInput.value);

            const formData = new FormData(form);

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
                    appendAndSelectCustomer(payload.customer || null);
                    resetCustomerModal();
                    closeCustomerModal();
                })
                .catch(function (payload) {
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

                    if (errors.id_proof_type || errors.id_proof_number || errors.id_proof_file) {
                        syncOptionalPanel('id-proof', true);
                    }

                    setAlert(message, true);
                });
        });

        syncTypePanels();
        syncGstFields();
        syncMapPreview();
    })();
</script>
