@php
    $businessPartner = $businessPartner ?? null;
    $isEdit = (bool) $businessPartner;
    $gstRegistered = old('gst_registered', $businessPartner?->gst_registered ? '1' : '0') === '1';
    $sameAsBusinessAddress = old('same_as_business_address', ($businessPartner?->gst_registered && ($businessPartner?->billing_address === $businessPartner?->address) && ($businessPartner?->billing_city === $businessPartner?->city) && ($businessPartner?->billing_state === $businessPartner?->state) && ($businessPartner?->billing_pincode === $businessPartner?->pincode)) ? '1' : '0') === '1';
@endphp

<style>
    .partner-shell { display:grid; gap:16px; max-width:1040px; margin:0 auto; padding:18px 22px 30px; }
    .partner-card { background:#fff; border:1px solid #dbe3ef; border-radius:16px; padding:18px; box-shadow:0 8px 24px rgba(15,23,42,.04); }
    .partner-card h1, .partner-card h2 { margin:0 0 6px; color:#0f172a; }
    .partner-card p { margin:0 0 14px; color:#64748b; font-size:13px; }
    .partner-grid { display:grid; grid-template-columns:repeat(12, minmax(0, 1fr)); gap:14px; }
    .partner-col-3 { grid-column:span 3; }
    .partner-col-4 { grid-column:span 4; }
    .partner-col-6 { grid-column:span 6; }
    .partner-col-12 { grid-column:span 12; }
    .partner-field { display:grid; gap:6px; }
    .partner-field label { font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.04em; color:#475569; }
    .partner-field input,
    .partner-field select,
    .partner-field textarea { width:100%; box-sizing:border-box; border:1px solid #cbd5e1; border-radius:12px; padding:10px 12px; font-size:14px; color:#0f172a; background:#fff; }
    .partner-field textarea { min-height:90px; resize:vertical; }
    .partner-field.is-error input,
    .partner-field.is-error select,
    .partner-field.is-error textarea { border-color:#dc2626; box-shadow:0 0 0 3px rgba(220,38,38,.08); background:#fff7f7; }
    .partner-error { color:#b91c1c; font-size:12px; line-height:1.4; }
    .partner-actions { display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; }
    .partner-gst-shell { display:grid; gap:12px; padding:14px; border:1px solid #e2e8f0; border-radius:14px; background:#f8fafc; }
    .partner-gst-head { display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; }
    .partner-gst-title { font-size:12px; font-weight:800; text-transform:uppercase; letter-spacing:.04em; color:#475569; }
    .partner-gst-note { color:#64748b; font-size:12px; line-height:1.45; }
    .partner-toggle { display:inline-grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:4px; padding:4px; border:1px solid #dbe3ef; border-radius:999px; background:#fff; min-width:min(100%, 220px); }
    .partner-toggle-input { position:absolute !important; width:1px !important; height:1px !important; padding:0 !important; margin:-1px !important; overflow:hidden !important; clip:rect(0,0,0,0) !important; white-space:nowrap !important; border:0 !important; }
    .partner-toggle-btn { border:none; border-radius:999px; min-height:38px; padding:8px 12px; background:transparent; color:#475569; font-size:13px; font-weight:700; cursor:pointer; }
    .partner-toggle-btn.is-active { background:#0f172a; color:#fff; }
    .partner-gst-fields[hidden] { display:none !important; }
    .partner-check { display:flex; align-items:center; gap:8px; color:#334155; font-size:13px; font-weight:600; }
    .partner-check input { width:16px; height:16px; }
    .partner-btn, .partner-btn-light {
        display:inline-flex; align-items:center; justify-content:center; gap:6px;
        min-height:42px; padding:10px 14px; border-radius:12px; border:1px solid transparent;
        text-decoration:none; font-size:13px; font-weight:700; cursor:pointer;
    }
    .partner-btn { background:#0f172a; color:#fff; }
    .partner-btn-light { background:#fff; color:#334155; border-color:#cbd5e1; }
    @media (max-width: 900px) {
        .partner-col-3, .partner-col-4, .partner-col-6 { grid-column:span 12; }
    }
</style>

<div class="partner-shell">
    @if ($errors->any())
        <div class="partner-card" style="border-color:#fecaca;background:#fef2f2;color:#991b1b;">
            <h2>Please review the business partner details.</h2>
            <ul style="margin:8px 0 0 18px;padding:0;">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="partner-card">
        <h1>{{ $isEdit ? 'Edit Business Partner' : 'Add Business Partner' }}</h1>
        <p>Use business partners for reminder, invoice, and payment communication. Actual clients can then be attached as delivery or service locations.</p>

        <div class="partner-grid">
            <div class="partner-field partner-col-6{{ $errors->has('business_name') ? ' is-error' : '' }}">
                <label for="business_name">Business Name</label>
                <input type="text" name="business_name" id="business_name" value="{{ old('business_name', $businessPartner->business_name ?? '') }}" required>
                @error('business_name')<div class="partner-error">{{ $message }}</div>@enderror
            </div>
            <div class="partner-field partner-col-6{{ $errors->has('contact_person') ? ' is-error' : '' }}">
                <label for="contact_person">Contact Person</label>
                <input type="text" name="contact_person" id="contact_person" value="{{ old('contact_person', $businessPartner->contact_person ?? '') }}">
                @error('contact_person')<div class="partner-error">{{ $message }}</div>@enderror
            </div>

            <div class="partner-field partner-col-3{{ $errors->has('phone') ? ' is-error' : '' }}">
                <label for="phone">Phone</label>
                <input type="text" name="phone" id="phone" value="{{ old('phone', $businessPartner->phone ?? '') }}">
                @error('phone')<div class="partner-error">{{ $message }}</div>@enderror
            </div>
            <div class="partner-field partner-col-3{{ $errors->has('whatsapp') ? ' is-error' : '' }}">
                <label for="whatsapp">WhatsApp</label>
                <input type="text" name="whatsapp" id="whatsapp" value="{{ old('whatsapp', $businessPartner->whatsapp ?? '') }}">
                @error('whatsapp')<div class="partner-error">{{ $message }}</div>@enderror
            </div>
            <div class="partner-field partner-col-3{{ $errors->has('email') ? ' is-error' : '' }}">
                <label for="email">Email</label>
                <input type="email" name="email" id="email" value="{{ old('email', $businessPartner->email ?? '') }}">
                @error('email')<div class="partner-error">{{ $message }}</div>@enderror
            </div>
            <div class="partner-field partner-col-3{{ $errors->has('status') ? ' is-error' : '' }}">
                <label for="status">Status</label>
                <select name="status" id="status">
                    <option value="active" {{ old('status', $businessPartner->status ?? 'active') === 'active' ? 'selected' : '' }}>Active</option>
                    <option value="inactive" {{ old('status', $businessPartner->status ?? 'active') === 'inactive' ? 'selected' : '' }}>Inactive</option>
                </select>
                @error('status')<div class="partner-error">{{ $message }}</div>@enderror
            </div>

            <div class="partner-field partner-col-12{{ $errors->has('address') ? ' is-error' : '' }}">
                <label for="address">Address</label>
                <textarea name="address" id="address">{{ old('address', $businessPartner->address ?? '') }}</textarea>
                @error('address')<div class="partner-error">{{ $message }}</div>@enderror
            </div>

            <div class="partner-col-12">
                <div class="partner-gst-shell">
                    <div class="partner-gst-head">
                        <div>
                            <div class="partner-gst-title">GST Details</div>
                            <div class="partner-gst-note">Use GST billing details when this partner should appear on invoices as the billing contact.</div>
                        </div>
                        <div class="partner-field{{ $errors->has('gst_registered') ? ' is-error' : '' }}" style="gap:8px;">
                            <label for="gst_registered">GST Registered?</label>
                            <select name="gst_registered" id="gst_registered" class="partner-toggle-input">
                                <option value="0" {{ $gstRegistered ? '' : 'selected' }}>No</option>
                                <option value="1" {{ $gstRegistered ? 'selected' : '' }}>Yes</option>
                            </select>
                            <div class="partner-toggle" role="tablist" aria-label="GST registered">
                                <button type="button" class="partner-toggle-btn{{ $gstRegistered ? '' : ' is-active' }}" data-gst-option="0" aria-pressed="{{ $gstRegistered ? 'false' : 'true' }}">No</button>
                                <button type="button" class="partner-toggle-btn{{ $gstRegistered ? ' is-active' : '' }}" data-gst-option="1" aria-pressed="{{ $gstRegistered ? 'true' : 'false' }}">Yes</button>
                            </div>
                            @error('gst_registered')<div class="partner-error">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    <div class="partner-grid partner-gst-fields" id="partnerGstFields"{{ $gstRegistered ? '' : ' hidden' }}>
                        <div class="partner-field partner-col-4{{ $errors->has('gstin') ? ' is-error' : '' }}">
                            <label for="gstin">GSTIN</label>
                            <input type="text" name="gstin" id="gstin" value="{{ old('gstin', $businessPartner->gstin ?? '') }}" placeholder="29ABCDE1234F1Z5">
                            @error('gstin')<div class="partner-error">{{ $message }}</div>@enderror
                        </div>
                        <div class="partner-field partner-col-4{{ $errors->has('legal_name') ? ' is-error' : '' }}">
                            <label for="legal_name">Legal Business Name</label>
                            <input type="text" name="legal_name" id="legal_name" value="{{ old('legal_name', $businessPartner->legal_name ?? '') }}" placeholder="Legal billing entity name">
                            @error('legal_name')<div class="partner-error">{{ $message }}</div>@enderror
                        </div>
                        <div class="partner-field partner-col-4{{ $errors->has('billing_state') ? ' is-error' : '' }}">
                            <label for="billing_state">Billing State</label>
                            <input type="text" name="billing_state" id="billing_state" value="{{ old('billing_state', $businessPartner->billing_state ?? '') }}" placeholder="Billing state">
                            @error('billing_state')<div class="partner-error">{{ $message }}</div>@enderror
                        </div>
                        <div class="partner-col-12">
                            <label class="partner-check">
                                <input type="checkbox" id="same_as_business_address" name="same_as_business_address" value="1" {{ $sameAsBusinessAddress ? 'checked' : '' }}>
                                <span>Same as business address</span>
                            </label>
                        </div>
                        <div class="partner-field partner-col-12{{ $errors->has('billing_address') ? ' is-error' : '' }}">
                            <label for="billing_address">GST Address / Billing Address</label>
                            <textarea name="billing_address" id="billing_address">{{ old('billing_address', $businessPartner->billing_address ?? '') }}</textarea>
                            @error('billing_address')<div class="partner-error">{{ $message }}</div>@enderror
                        </div>
                        <div class="partner-field partner-col-6{{ $errors->has('billing_city') ? ' is-error' : '' }}">
                            <label for="billing_city">Billing City</label>
                            <input type="text" name="billing_city" id="billing_city" value="{{ old('billing_city', $businessPartner->billing_city ?? '') }}">
                            @error('billing_city')<div class="partner-error">{{ $message }}</div>@enderror
                        </div>
                        <div class="partner-field partner-col-6{{ $errors->has('billing_pincode') ? ' is-error' : '' }}">
                            <label for="billing_pincode">Billing Pincode</label>
                            <input type="text" name="billing_pincode" id="billing_pincode" value="{{ old('billing_pincode', $businessPartner->billing_pincode ?? '') }}">
                            @error('billing_pincode')<div class="partner-error">{{ $message }}</div>@enderror
                        </div>
                    </div>
                </div>
            </div>

            <div class="partner-field partner-col-4{{ $errors->has('city') ? ' is-error' : '' }}">
                <label for="city">City</label>
                <input type="text" name="city" id="city" value="{{ old('city', $businessPartner->city ?? '') }}">
                @error('city')<div class="partner-error">{{ $message }}</div>@enderror
            </div>
            <div class="partner-field partner-col-4{{ $errors->has('state') ? ' is-error' : '' }}">
                <label for="state">State</label>
                <input type="text" name="state" id="state" value="{{ old('state', $businessPartner->state ?? '') }}">
                @error('state')<div class="partner-error">{{ $message }}</div>@enderror
            </div>
            <div class="partner-field partner-col-4{{ $errors->has('pincode') ? ' is-error' : '' }}">
                <label for="pincode">Pincode</label>
                <input type="text" name="pincode" id="pincode" value="{{ old('pincode', $businessPartner->pincode ?? '') }}">
                @error('pincode')<div class="partner-error">{{ $message }}</div>@enderror
            </div>

            <div class="partner-field partner-col-12{{ $errors->has('location') ? ' is-error' : '' }}">
                <label for="location">Map / Location Link</label>
                <input type="text" name="location" id="location" value="{{ old('location', $businessPartner->location ?? '') }}" placeholder="Paste Google Maps link or location note">
                @error('location')<div class="partner-error">{{ $message }}</div>@enderror
            </div>

            <div class="partner-field partner-col-6{{ $errors->has('latitude') ? ' is-error' : '' }}">
                <label for="latitude">Latitude</label>
                <input type="number" step="0.000001" name="latitude" id="latitude" value="{{ old('latitude', $businessPartner->latitude ?? '') }}">
                @error('latitude')<div class="partner-error">{{ $message }}</div>@enderror
            </div>
            <div class="partner-field partner-col-6{{ $errors->has('longitude') ? ' is-error' : '' }}">
                <label for="longitude">Longitude</label>
                <input type="number" step="0.000001" name="longitude" id="longitude" value="{{ old('longitude', $businessPartner->longitude ?? '') }}">
                @error('longitude')<div class="partner-error">{{ $message }}</div>@enderror
            </div>
        </div>
    </div>

    <div class="partner-actions">
        <a href="{{ $isEdit ? route('business-partners.show', $businessPartner) : route('business-partners.index') }}" class="partner-btn-light">Cancel</a>
        <button type="submit" class="partner-btn">{{ $isEdit ? 'Update Business Partner' : 'Save Business Partner' }}</button>
    </div>
</div>

<script>
    (function () {
        const gstRegisteredSelect = document.getElementById('gst_registered');
        const gstButtons = Array.from(document.querySelectorAll('[data-gst-option]'));
        const gstFields = document.getElementById('partnerGstFields');
        const sameAddressCheckbox = document.getElementById('same_as_business_address');
        const businessAddress = document.getElementById('address');
        const businessCity = document.getElementById('city');
        const businessState = document.getElementById('state');
        const businessPincode = document.getElementById('pincode');
        const billingAddress = document.getElementById('billing_address');
        const billingCity = document.getElementById('billing_city');
        const billingState = document.getElementById('billing_state');
        const billingPincode = document.getElementById('billing_pincode');

        if (!gstRegisteredSelect || !gstFields) {
            return;
        }

        function gstEnabled() {
            return gstRegisteredSelect.value === '1';
        }

        function syncGstButtons() {
            const activeValue = gstRegisteredSelect.value;
            gstButtons.forEach(function (button) {
                const isActive = button.getAttribute('data-gst-option') === activeValue;
                button.classList.toggle('is-active', isActive);
                button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            });
        }

        function copyBusinessAddressToBilling() {
            if (!sameAddressCheckbox?.checked) {
                return;
            }

            if (billingAddress) billingAddress.value = businessAddress?.value || '';
            if (billingCity) billingCity.value = businessCity?.value || '';
            if (billingState) billingState.value = businessState?.value || '';
            if (billingPincode) billingPincode.value = businessPincode?.value || '';
        }

        function syncGstSection() {
            const enabled = gstEnabled();
            gstFields.hidden = !enabled;
            syncGstButtons();

            if (!enabled) {
                if (sameAddressCheckbox) {
                    sameAddressCheckbox.checked = false;
                }
                return;
            }

            copyBusinessAddressToBilling();
        }

        gstButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                const nextValue = button.getAttribute('data-gst-option') || '0';
                if (gstRegisteredSelect.value === nextValue) {
                    return;
                }

                gstRegisteredSelect.value = nextValue;
                syncGstSection();
            });
        });

        sameAddressCheckbox?.addEventListener('change', copyBusinessAddressToBilling);
        [businessAddress, businessCity, businessState, businessPincode].forEach(function (input) {
            input?.addEventListener('input', copyBusinessAddressToBilling);
        });

        syncGstSection();
    })();
</script>
