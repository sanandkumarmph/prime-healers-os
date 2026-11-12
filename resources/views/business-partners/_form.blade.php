@php
    $businessPartner = $businessPartner ?? null;
    $isEdit = $businessPartner !== null;
    $value = fn (string $field, $default = '') => old($field, $businessPartner?->{$field} ?? $default);
    $gstRegistered = (string) $value('gst_registered', '0') === '1';
    $businessAddressFields = ['address', 'city', 'state', 'pincode'];
    $billingAddressMatches = $businessPartner?->gst_registered
        && collect($businessAddressFields)->every(fn ($field) => $businessPartner->{'billing_' . $field} === $businessPartner->{$field});
    $sameAsBusinessAddress = (string) old('same_as_business_address',
        !session()->hasOldInput('gst_registered') && $billingAddressMatches ? '1' : '0') === '1';
    $locationFields = ['location', 'latitude', 'longitude'];
    $billingFields = ['gstin', 'legal_name', 'billing_address', 'billing_city', 'billing_state', 'billing_pincode'];
    $locationOpen = $errors->hasAny($locationFields);
    $billingFieldsHaveErrors = $errors->hasAny($billingFields);
    $billingOpen = $billingFieldsHaveErrors || $errors->has('gst_registered');
    $locationEntered = collect($locationFields)->contains(fn ($field) => filled($value($field)));
    $fieldErrorAttributes = function (string $field) use ($errors): string {
        return $errors->has($field) ? 'aria-invalid="true" aria-describedby="' . $field . '-error"' : '';
    };
    $partnerListUrl = route('business-partners.index');
    $partnerProfileUrl = $isEdit ? route('business-partners.show', $businessPartner) : null;
@endphp

<style>
    .partner-form {
        --partner-ink: #172944;
        --partner-muted: #52657f;
        --partner-line: #dce5f1;
        --partner-input-line: #becce0;
        --partner-blue: #234fb8;
        --partner-blue-soft: #eef4ff;
        width: min(100%, 1040px);
        min-width: 0;
        margin-inline: auto;
        display: grid;
        gap: 12px;
        color: var(--partner-ink);
    }
    .partner-form [hidden] { display: none !important; }
    .partner-form :focus-visible { outline: 2px solid var(--partner-blue); outline-offset: 2px; }
    .partner-form :is(.partner-form-header, .partner-form-card, .partner-form-actions) {
        min-width: 0;
        border: 1px solid var(--partner-line);
        border-radius: 12px;
        background: #fff;
    }
    .partner-form-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 8px 16px;
        padding: 12px 16px;
    }
    .partner-form-header h1 {
        margin: 0;
        font-size: clamp(21px, 3vw, 27px);
        font-weight: 800;
        line-height: 1.2;
        color: var(--partner-ink);
    }
    .partner-form-header p { margin: 4px 0 0; font-size: 13px; color: var(--partner-muted); overflow-wrap: anywhere; }
    .partner-form-nav { display: flex; align-items: center; flex-wrap: wrap; gap: 6px 12px; }
    .partner-form-nav a {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        min-height: 36px;
        font-size: 13px;
        font-weight: 700;
        color: var(--partner-blue);
        text-decoration: none;
    }
    .partner-form-nav a:hover { text-decoration: underline; }
    .partner-form-nav .partner-form-profile { padding-inline: 10px; border: 1px solid var(--partner-line); border-radius: 8px; background: #f6f9fe; }
    .partner-form-errors {
        padding: 10px 14px;
        border: 1px solid #efc6c4;
        border-radius: 10px;
        background: #fff5f4;
        color: #8f211a;
        font-size: 13px;
        overflow-wrap: anywhere;
    }
    .partner-form-errors ul { margin: 4px 0 0; padding-inline-start: 18px; }
    .partner-form-section { display: grid; gap: 12px; padding: 16px; min-width: 0; }
    .partner-form-section + .partner-form-section { border-top: 1px solid var(--partner-line); }
    .partner-form-section h2 { margin: 0; color: var(--partner-ink); font-size: 16px; font-weight: 750; line-height: 1.25; }
    .partner-form-grid { display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 10px 12px; min-width: 0; }
    .partner-form .partner-col-3 { grid-column: span 3; }
    .partner-form .partner-col-4 { grid-column: span 4; }
    .partner-form .partner-col-5 { grid-column: span 5; }
    .partner-form .partner-col-6 { grid-column: span 6; }
    .partner-form .partner-col-12 { grid-column: 1 / -1; }
    .partner-form-field { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
    .partner-form-field label { font-size: 13px; font-weight: 650; line-height: 1.3; }
    .partner-form-required { color: var(--partner-blue); }
    .partner-form-field :is(input, select, textarea) {
        width: 100%;
        min-width: 0;
        min-height: 42px;
        padding: 8px 10px;
        border: 1px solid var(--partner-input-line);
        border-radius: 8px;
        box-sizing: border-box;
        background: #fff;
        color: var(--partner-ink);
        font-size: 14px;
        line-height: 1.4;
        scroll-margin-block: 90px 160px;
    }
    .partner-form-field textarea { min-height: 58px; resize: vertical; }
    .partner-form-field :is(input, select, textarea):focus {
        outline: none;
        border-color: var(--partner-blue);
        box-shadow: 0 0 0 2px #d7e5ff;
    }
    .partner-form-field [aria-invalid="true"] { border-color: #b7352a; background: #fff6f5; }
    .partner-form-error { color: #9d281e; font-size: 12px; line-height: 1.4; overflow-wrap: anywhere; }
    .partner-form-field-head { display: flex; justify-content: space-between; align-items: baseline; flex-wrap: wrap; gap: 3px 6px; }
    .partner-form-copy {
        padding: 0;
        border: 0;
        background: transparent;
        color: var(--partner-blue);
        font-size: 12px;
        font-weight: 650;
        text-decoration: underline;
        text-underline-offset: 2px;
        cursor: pointer;
    }
    .partner-form-copy:disabled { color: var(--partner-muted); text-decoration: none; cursor: default; }
    .partner-form-options { border: 1px solid var(--partner-line); border-radius: 8px; }
    .partner-form-option + .partner-form-option { border-top: 1px solid var(--partner-line); }
    .partner-form-option > summary {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 4px 8px;
        min-height: 42px;
        padding: 8px 12px;
        border-radius: 7px;
        list-style: none;
        color: var(--partner-ink);
        font-size: 13px;
        font-weight: 650;
        cursor: pointer;
        scroll-margin-block: 90px 160px;
    }
    .partner-form-option > summary::-webkit-details-marker { display: none; }
    .partner-form-option > summary::after {
        content: '+';
        margin-inline-start: auto;
        color: var(--partner-blue);
        font-size: 17px;
        line-height: 1;
    }
    .partner-form-option[open] > summary::after { content: '−'; }
    .partner-form-option[open] > summary,
    .partner-form-option > summary:hover { background: var(--partner-blue-soft); color: var(--partner-blue); }
    .partner-form-option-status { color: var(--partner-muted); font-size: 12px; font-weight: 500; }
    .partner-form-option-body { display: grid; gap: 10px; padding: 12px; }
    .partner-form-billing-toggle { max-width: 200px; }
    .partner-form-check {
        display: flex;
        align-items: center;
        gap: 8px;
        width: fit-content;
        min-height: 34px;
        color: var(--partner-ink);
        font-size: 13px;
        font-weight: 650;
    }
    .partner-form-check input[type="checkbox"] { width: 16px; height: 16px; min-height: 0; margin: 0; flex: none; }
    .partner-form-actions {
        position: sticky;
        z-index: 4;
        bottom: 12px;
        display: flex;
        justify-content: flex-end;
        gap: 8px;
        padding: 9px 12px;
        box-shadow: 0 5px 16px rgba(15, 23, 42, .06);
    }
    .partner-form-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 42px;
        padding: 8px 14px;
        border: 1px solid var(--partner-input-line);
        border-radius: 8px;
        background: #fff;
        color: var(--partner-ink);
        font-size: 14px;
        font-weight: 650;
        text-align: center;
        text-decoration: none;
        cursor: pointer;
    }
    .partner-form-btn:hover { background: var(--partner-blue-soft); }
    .partner-form-btn.is-primary { border-color: var(--partner-blue); background: var(--partner-blue); color: #fff; }
    .partner-form-btn.is-primary:hover { border-color: #193e99; background: #193e99; }
    @media (max-width: 980px) {
        .partner-form { padding-bottom: 76px; }
        .partner-form-actions { position: fixed; inset-inline: 12px; bottom: 12px; z-index: 904; }
    }
    @media (max-width: 767px) {
        .partner-form { padding-bottom: 0; }
        .partner-form-actions { bottom: calc(82px + env(safe-area-inset-bottom, 0px)); }
        body:has([data-business-partner-form]) .phos-release-badge { bottom: calc(150px + env(safe-area-inset-bottom, 0px)); }
    }
    @media (max-width: 640px) {
        .partner-form { gap: 8px; }
        .partner-form-header, .partner-form-section { padding: 12px; }
        .partner-form-nav a { min-height: 40px; }
        .partner-form-field :is(input, select, textarea) { font-size: 16px; }
        .partner-form .partner-contact-grid > *, .partner-form .partner-billing-grid > .partner-form-field { grid-column: 1 / -1; }
        .partner-form .partner-address-grid > .partner-col-4 { grid-column: span 6; }
        .partner-form-actions .is-primary { flex: 1; }
    }
</style>

<div class="partner-form" data-business-partner-form="{{ $isEdit ? 'edit' : 'create' }}">
    <header class="partner-form-header">
        <div>
            <h1>{{ $isEdit ? 'Edit Business Partner' : 'Add Business Partner' }}</h1>
            @if($isEdit)
                <p>{{ $businessPartner->displayName() }}</p>
            @endif
        </div>
        <nav class="partner-form-nav" aria-label="Business Partner navigation">
            <a href="{{ $partnerListUrl }}" aria-label="Back to Business Partners"><span aria-hidden="true">&larr;</span> Business Partners</a>
            @if($isEdit)
                <a href="{{ $partnerProfileUrl }}" class="partner-form-profile">View Profile</a>
            @endif
        </nav>
    </header>

    @if($errors->any())
        <div class="partner-form-errors" role="alert">
            <strong>Check the highlighted fields</strong>
            <ul>
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="partner-form-card">
        <section class="partner-form-section" aria-labelledby="partner-contact-heading">
            <h2 id="partner-contact-heading">Business &amp; contact</h2>
            <div class="partner-form-grid partner-contact-grid">
                <div class="partner-form-field partner-col-5">
                    <label for="business_name">Business name <span class="partner-form-required" aria-hidden="true">*</span></label>
                    <input type="text" name="business_name" id="business_name" value="{{ $value('business_name') }}" autocomplete="organization" required {!! $fieldErrorAttributes('business_name') !!}>
                    @error('business_name')<div id="business_name-error" class="partner-form-error">{{ $message }}</div>@enderror
                </div>
                <div class="partner-form-field partner-col-4">
                    <label for="contact_person">Contact person</label>
                    <input type="text" name="contact_person" id="contact_person" value="{{ $value('contact_person') }}" autocomplete="name" {!! $fieldErrorAttributes('contact_person') !!}>
                    @error('contact_person')<div id="contact_person-error" class="partner-form-error">{{ $message }}</div>@enderror
                </div>
                <div class="partner-form-field partner-col-3">
                    <label for="status">Status</label>
                    <select name="status" id="status" {!! $fieldErrorAttributes('status') !!}>
                        <option value="active" @selected($value('status', 'active') === 'active')>Active</option>
                        <option value="inactive" @selected($value('status', 'active') === 'inactive')>Inactive</option>
                    </select>
                    @error('status')<div id="status-error" class="partner-form-error">{{ $message }}</div>@enderror
                </div>
                <div class="partner-form-field partner-col-4">
                    <label for="phone">Phone</label>
                    <input type="text" inputmode="tel" name="phone" id="phone" value="{{ $value('phone') }}" autocomplete="tel" {!! $fieldErrorAttributes('phone') !!}>
                    @error('phone')<div id="phone-error" class="partner-form-error">{{ $message }}</div>@enderror
                </div>
                <div class="partner-form-field partner-col-4">
                    <div class="partner-form-field-head">
                        <label for="whatsapp">WhatsApp</label>
                        <button type="button" class="partner-form-copy" data-copy-partner-phone @disabled(blank($value('phone')))>Use phone number</button>
                        <span class="sr-only" data-copy-partner-phone-status role="status"></span>
                    </div>
                    <input type="text" inputmode="tel" name="whatsapp" id="whatsapp" value="{{ $value('whatsapp') }}" {!! $fieldErrorAttributes('whatsapp') !!}>
                    @error('whatsapp')<div id="whatsapp-error" class="partner-form-error">{{ $message }}</div>@enderror
                </div>
                <div class="partner-form-field partner-col-4">
                    <label for="email">Email</label>
                    <input type="email" name="email" id="email" value="{{ $value('email') }}" autocomplete="email" {!! $fieldErrorAttributes('email') !!}>
                    @error('email')<div id="email-error" class="partner-form-error">{{ $message }}</div>@enderror
                </div>
            </div>
        </section>

        <section class="partner-form-section" aria-labelledby="partner-address-heading">
            <h2 id="partner-address-heading">Business address</h2>
            <div class="partner-form-grid partner-address-grid">
                <div class="partner-form-field partner-col-12">
                    <label for="address">Street address</label>
                    <textarea name="address" id="address" rows="2" autocomplete="street-address" placeholder="Building, street, landmark" {!! $fieldErrorAttributes('address') !!}>{{ $value('address') }}</textarea>
                    @error('address')<div id="address-error" class="partner-form-error">{{ $message }}</div>@enderror
                </div>
                @foreach(['city' => ['City', 'address-level2'], 'state' => ['State', 'address-level1'], 'pincode' => ['Pincode', 'postal-code']] as $field => [$label, $autocomplete])
                    <div class="partner-form-field partner-col-4">
                        <label for="{{ $field }}">{{ $label }}</label>
                        <input type="text" name="{{ $field }}" id="{{ $field }}" value="{{ $value($field) }}" autocomplete="{{ $autocomplete }}" {!! $fieldErrorAttributes($field) !!}>
                        @error($field)<div id="{{ $field }}-error" class="partner-form-error">{{ $message }}</div>@enderror
                    </div>
                @endforeach
            </div>
        </section>
    </div>

    <section class="partner-form-card partner-form-section" aria-labelledby="partner-additional-heading">
        <h2 id="partner-additional-heading">Additional details</h2>
        <div class="partner-form-options">
            <details class="partner-form-option" data-partner-panel="location" @if($locationOpen) open @endif>
                <summary>
                    Map &amp; coordinates
                    @if($locationEntered)<span class="partner-form-option-status">Location entered</span>@endif
                </summary>
                <div class="partner-form-option-body partner-form-grid">
                    <div class="partner-form-field partner-col-12">
                        <label for="location">Map or location link</label>
                        <input type="text" name="location" id="location" value="{{ $value('location') }}" placeholder="Google Maps link or location note" {!! $fieldErrorAttributes('location') !!}>
                        @error('location')<div id="location-error" class="partner-form-error">{{ $message }}</div>@enderror
                    </div>
                    @foreach(['latitude' => 'Latitude', 'longitude' => 'Longitude'] as $field => $label)
                        <div class="partner-form-field partner-col-6">
                            <label for="{{ $field }}">{{ $label }}</label>
                            <input type="number" step="any" name="{{ $field }}" id="{{ $field }}" value="{{ $value($field) }}" {!! $fieldErrorAttributes($field) !!}>
                            @error($field)<div id="{{ $field }}-error" class="partner-form-error">{{ $message }}</div>@enderror
                        </div>
                    @endforeach
                </div>
            </details>

            <details class="partner-form-option" data-partner-panel="billing" @if($billingOpen) open @endif>
                <summary>
                    GST &amp; billing
                    <span class="partner-form-option-status" data-partner-gst-status>{{ $gstRegistered ? 'Registered' : 'Not registered' }}</span>
                </summary>
                <div class="partner-form-option-body">
                    <div class="partner-form-field partner-form-billing-toggle">
                        <label for="gst_registered">GST registered?</label>
                        <select name="gst_registered" id="gst_registered" aria-controls="partnerGstFields" {!! $fieldErrorAttributes('gst_registered') !!}>
                            <option value="0" @selected(!$gstRegistered)>No</option>
                            <option value="1" @selected($gstRegistered)>Yes</option>
                        </select>
                        @error('gst_registered')<div id="gst_registered-error" class="partner-form-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="partner-form-grid partner-billing-grid" id="partnerGstFields" @if(!$gstRegistered && !$billingFieldsHaveErrors) hidden @endif>
                        <div class="partner-form-field partner-col-6">
                            <label for="gstin">GSTIN <span class="partner-form-required" aria-hidden="true">*</span></label>
                            <input type="text" name="gstin" id="gstin" value="{{ $value('gstin') }}" placeholder="29ABCDE1234F1Z5" data-partner-billing-required aria-required="{{ $gstRegistered ? 'true' : 'false' }}" {!! $fieldErrorAttributes('gstin') !!}>
                            @error('gstin')<div id="gstin-error" class="partner-form-error">{{ $message }}</div>@enderror
                        </div>
                        <div class="partner-form-field partner-col-6">
                            <label for="legal_name">Legal business name <span class="partner-form-required" aria-hidden="true">*</span></label>
                            <input type="text" name="legal_name" id="legal_name" value="{{ $value('legal_name') }}" data-partner-billing-required aria-required="{{ $gstRegistered ? 'true' : 'false' }}" {!! $fieldErrorAttributes('legal_name') !!}>
                            @error('legal_name')<div id="legal_name-error" class="partner-form-error">{{ $message }}</div>@enderror
                        </div>
                        <label class="partner-form-check partner-col-12">
                            <input type="checkbox" id="same_as_business_address" name="same_as_business_address" value="1" @checked($sameAsBusinessAddress)>
                            <span>Same as business address</span>
                        </label>
                        <div class="partner-form-field partner-col-12">
                            <label for="billing_address">Billing address <span class="partner-form-required" aria-hidden="true">*</span></label>
                            <textarea name="billing_address" id="billing_address" rows="2" data-partner-billing-required aria-required="{{ $gstRegistered ? 'true' : 'false' }}" {!! $fieldErrorAttributes('billing_address') !!}>{{ $value('billing_address') }}</textarea>
                            @error('billing_address')<div id="billing_address-error" class="partner-form-error">{{ $message }}</div>@enderror
                        </div>
                        @foreach(['billing_city' => 'Billing city', 'billing_state' => 'Billing state', 'billing_pincode' => 'Billing pincode'] as $field => $label)
                            <div class="partner-form-field partner-col-4">
                                <label for="{{ $field }}">{{ $label }} @if($field === 'billing_state')<span class="partner-form-required" aria-hidden="true">*</span>@endif</label>
                                <input type="text" name="{{ $field }}" id="{{ $field }}" value="{{ $value($field) }}" @if($field === 'billing_state') data-partner-billing-required aria-required="{{ $gstRegistered ? 'true' : 'false' }}" @endif {!! $fieldErrorAttributes($field) !!}>
                                @error($field)<div id="{{ $field }}-error" class="partner-form-error">{{ $message }}</div>@enderror
                            </div>
                        @endforeach
                    </div>
                </div>
            </details>
        </div>
    </section>

    <div class="partner-form-actions" data-partner-form-actions>
        <a href="{{ $partnerProfileUrl ?? $partnerListUrl }}" class="partner-form-btn">Cancel</a>
        <button type="submit" class="partner-form-btn is-primary">{{ $isEdit ? 'Update Business Partner' : 'Save Business Partner' }}</button>
    </div>
</div>

<script>
    (() => {
        const form = document.getElementById('business-partner-form');
        const field = name => form.elements.namedItem(name);
        const phone = field('phone');
        const copyPhone = form.querySelector('[data-copy-partner-phone]');
        const gstRegistered = field('gst_registered');
        const billingFields = form.querySelector('#partnerGstFields');
        const sameAddress = field('same_as_business_address');
        const addressFields = ['address', 'city', 'state', 'pincode'];

        function updateCopyPhone() {
            copyPhone.disabled = !phone.value.trim();
        }

        function copyBusinessAddress() {
            if (!sameAddress.checked) return;
            addressFields.forEach(name => { field('billing_' + name).value = field(name).value; });
        }

        phone.addEventListener('input', updateCopyPhone);
        phone.addEventListener('change', updateCopyPhone);
        copyPhone.addEventListener('click', () => {
            field('whatsapp').value = phone.value;
            form.querySelector('[data-copy-partner-phone-status]').textContent = 'Phone number copied to WhatsApp.';
        });
        gstRegistered.addEventListener('change', () => {
            const registered = gstRegistered.value === '1';
            billingFields.hidden = !registered;
            form.querySelector('[data-partner-gst-status]').textContent = registered ? 'Registered' : 'Not registered';
            billingFields.querySelectorAll('[data-partner-billing-required]').forEach(input => {
                input.setAttribute('aria-required', String(registered));
            });
            if (!registered) sameAddress.checked = false;
        });
        sameAddress.addEventListener('change', copyBusinessAddress);
        addressFields.forEach(name => {
            field(name).addEventListener('input', copyBusinessAddress);
            field(name).addEventListener('change', copyBusinessAddress);
        });
        form.addEventListener('invalid', event => {
            const panel = event.target.closest('[data-partner-panel]');
            if (panel) panel.open = true;
        }, true);
    })();
</script>
