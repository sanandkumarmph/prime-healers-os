@php
    $isEdit = $vendor->exists;
    $fieldStyle = fn (string $field, string $base) => $base . ($errors->has($field)
        ? ' border-color:#dc2626; box-shadow:0 0 0 3px rgba(220, 38, 38, 0.12); background:#fff7f7;'
        : '');
    $fieldError = fn (string $field) => $errors->first($field);
    $phoneParts = \App\Support\PhoneNumber::split(old('phone', $vendor->phone));
    $phoneValue = $phoneParts['local'];
    $phoneCountryCode = old('phone_country_code', $phoneParts['code']);
    $whatsappParts = \App\Support\PhoneNumber::split(old('whatsapp', $vendor->whatsapp));
    $countryCodeOptions = \App\Support\PhoneNumber::countryCodeOptions();
    $contactOpen = $errors->hasAny(['email', 'whatsapp'])
        || filled(old('email', $vendor->email))
        || filled(old('whatsapp', $vendor->whatsapp));
    $commercialOpen = $errors->hasAny(['vendor_type', 'gst_number', 'gst_registration_type', 'payment_terms'])
        || filled(old('vendor_type', $vendor->vendor_type))
        || filled(old('gst_number', $vendor->gst_number))
        || filled(old('gst_registration_type', $vendor->gst_registration_type))
        || filled(old('payment_terms', $vendor->payment_terms));
    $addressOpen = $errors->hasAny(['state', 'pincode', 'address'])
        || filled(old('state', $vendor->state))
        || filled(old('pincode', $vendor->pincode))
        || filled(old('address', $vendor->address));
    $notesOpen = $errors->has('notes') || filled(old('notes', $vendor->notes));
@endphp

<style>
    .vendor-form-shell { max-width:1100px; margin:0 auto; }
    .vendor-form-mobile { display:none; }
    .vendor-form-desktop { display:block; }
    @media (max-width: 768px) {
        .vendor-form-desktop { display:none !important; }
        .vendor-form-mobile {
            display:grid;
            gap:12px;
            padding-bottom:calc(108px + env(safe-area-inset-bottom, 0px));
        }
        .vendor-form-card,
        .vendor-form-optional {
            border:1px solid #dbe3ef;
            border-radius:18px;
            background:#fff;
            box-shadow:0 10px 28px rgba(15, 23, 42, 0.05);
            overflow:hidden;
        }
        .vendor-form-mobile-head {
            display:grid;
            gap:6px;
            padding:14px;
            border:1px solid #dbe3ef;
            border-radius:18px;
            background:#fff;
            box-shadow:0 10px 28px rgba(15, 23, 42, 0.05);
        }
        .vendor-form-mobile-head h1 {
            margin:0;
            font-size:22px;
            line-height:1.08;
            color:#0f172a;
        }
        .vendor-form-mobile-head p {
            margin:0;
            color:#64748b;
            font-size:12px;
            line-height:1.4;
        }
        .vendor-form-mobile-head a {
            justify-self:start;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            min-height:38px;
            padding:8px 12px;
            border-radius:12px;
            border:1px solid #cbd5e1;
            background:#fff;
            color:#334155;
            text-decoration:none;
            font-size:12px;
            font-weight:700;
        }
        .vendor-form-mobile-body {
            padding:14px;
            display:grid;
            gap:10px;
        }
        .vendor-field-grid {
            display:grid;
            gap:10px;
        }
        .vendor-field {
            display:grid;
            gap:6px;
        }
        .vendor-field label {
            color:#64748b;
            font-size:10px;
            font-weight:800;
            letter-spacing:.06em;
            text-transform:uppercase;
        }
        .vendor-field input,
        .vendor-field textarea,
        .vendor-field select {
            width:100%;
            min-height:42px;
            padding:10px 12px;
            border:1px solid #cbd5e1;
            border-radius:14px;
            background:#fff;
            color:#0f172a;
            font-size:15px;
            box-sizing:border-box;
        }
        .vendor-field textarea {
            min-height:96px;
            resize:vertical;
        }
        .vendor-field-error {
            color:#b91c1c;
            font-size:12px;
            line-height:1.4;
        }
        .vendor-form-toggle {
            width:100%;
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            padding:13px 14px;
            border:none;
            background:#fff;
            text-align:left;
            cursor:pointer;
        }
        .vendor-form-toggle strong {
            display:block;
            font-size:13px;
            color:#0f172a;
        }
        .vendor-form-toggle span {
            display:block;
            margin-top:3px;
            color:#64748b;
            font-size:11px;
            line-height:1.35;
        }
        .vendor-form-toggle em {
            font-style:normal;
            color:#475569;
            font-size:16px;
            font-weight:700;
        }
        .vendor-form-panel[hidden] { display:none !important; }
        .vendor-form-actions {
            position:fixed;
            left:12px;
            right:12px;
            bottom:calc(84px + env(safe-area-inset-bottom, 0px));
            z-index:60;
            display:grid;
            grid-template-columns:minmax(0, 1fr) minmax(0, 1fr);
            gap:8px;
            padding:10px;
            border:1px solid rgba(203, 213, 225, 0.92);
            border-radius:16px;
            background:rgba(255,255,255,.96);
            box-shadow:0 18px 40px rgba(15,23,42,.14);
            backdrop-filter:blur(8px);
        }
        .vendor-form-actions a,
        .vendor-form-actions button {
            min-height:46px;
            width:100%;
            border-radius:14px;
            font-size:14px;
            font-weight:800;
        }
        .vendor-form-actions a {
            display:inline-flex;
            align-items:center;
            justify-content:center;
            border:1px solid #cbd5e1;
            background:#fff;
            color:#334155;
            text-decoration:none;
        }
        .vendor-form-actions button {
            border:none;
            background:#4f46e5;
            color:#fff;
            cursor:pointer;
        }
        .vendor-phone-shell {
            display:flex;
            align-items:center;
            border:1px solid #cbd5e1;
            border-radius:14px;
            overflow:visible;
        }
        .vendor-phone-shell input {
            border:none !important;
            box-shadow:none !important;
            background:transparent !important;
        }
        @media (max-width: 380px) {
            .vendor-form-actions {
                grid-template-columns:1fr;
                bottom:calc(96px + env(safe-area-inset-bottom, 0px));
            }
        }
    }
</style>

<div class="vendor-form-shell">
    <div class="vendor-form-mobile">
        <div class="vendor-form-mobile-head">
            <h1>{{ $isEdit ? 'Edit Vendor' : 'Add Vendor' }}</h1>
            <p>Save the essential vendor details first. Everything else stays available below.</p>
            <a href="{{ route('vendors.index') }}">Back to Vendors</a>
        </div>

        @if ($errors->any())
            <div class="vendor-form-card">
                <div class="vendor-form-mobile-body" style="color:#991b1b; background:#fff1f2;">
                    <strong>Please fix the highlighted fields.</strong>
                </div>
            </div>
        @endif

        <form method="POST" action="{{ $isEdit ? route('vendors.update', $vendor) : route('vendors.store') }}" style="display:grid; gap:12px;">
            @csrf
            @if($isEdit)
                @method('PUT')
            @endif

            <section class="vendor-form-card">
                <div class="vendor-form-mobile-body">
                    <div class="vendor-field-grid">
                        <div class="vendor-field">
                            <label for="vendor_name_mobile">Vendor Name</label>
                            <input id="vendor_name_mobile" type="text" name="name" value="{{ old('name', $vendor->name) }}" required style="{{ $fieldStyle('name', '') }}">
                            @if($fieldError('name'))<div class="vendor-field-error">{{ $fieldError('name') }}</div>@endif
                        </div>
                        <div class="vendor-field">
                            <label for="vendor_contact_mobile">Contact Person</label>
                            <input id="vendor_contact_mobile" type="text" name="contact_person" value="{{ old('contact_person', $vendor->contact_person) }}" style="{{ $fieldStyle('contact_person', '') }}">
                            @if($fieldError('contact_person'))<div class="vendor-field-error">{{ $fieldError('contact_person') }}</div>@endif
                        </div>
                        <div class="vendor-field">
                            <label for="vendor_phone_mobile">Phone</label>
                            <div class="vendor-phone-shell" style="{{ $fieldStyle('phone', '') }}">
                                @include('partials.country-code-picker', [
                                    'name' => 'phone_country_code',
                                    'pickerId' => 'vendor_phone_country_code_mobile',
                                    'value' => $phoneCountryCode,
                                    'options' => $countryCodeOptions,
                                    'dividerColor' => '#cbd5e1',
                                    'width' => '92px',
                                ])
                                <input id="vendor_phone_mobile" type="text" name="phone" value="{{ $phoneValue }}" inputmode="numeric" maxlength="15" pattern="[0-9]{6,15}" data-phone-local>
                            </div>
                            @if($fieldError('phone'))<div class="vendor-field-error">{{ $fieldError('phone') }}</div>@endif
                        </div>
                        <div class="vendor-field">
                            <label for="vendor_city_mobile">City</label>
                            <select id="vendor_city_mobile" name="city_id" style="{{ $fieldStyle('city_id', '') }}">
                                <option value="">Select city</option>
                                @foreach($cities as $city)
                                    <option value="{{ $city->id }}" @selected((string) old('city_id', $vendor->city_id) === (string) $city->id)>{{ $city->name }}{{ $city->state ? ' - ' . $city->state : '' }}</option>
                                @endforeach
                            </select>
                            @if($fieldError('city_id'))<div class="vendor-field-error">{{ $fieldError('city_id') }}</div>@endif
                        </div>
                    </div>
                </div>
            </section>

            <section class="vendor-form-optional">
                <button type="button" class="vendor-form-toggle" data-vendor-toggle="contact">
                    <span><strong>Additional Contact</strong><span>Email and WhatsApp.</span></span>
                    <em data-vendor-icon="contact">{{ $contactOpen ? '−' : '+' }}</em>
                </button>
                <div class="vendor-form-mobile-body vendor-form-panel" data-vendor-panel="contact" {{ $contactOpen ? '' : 'hidden' }}>
                    <div class="vendor-field-grid">
                        <div class="vendor-field">
                            <label for="vendor_email_mobile">Email</label>
                            <input id="vendor_email_mobile" type="email" name="email" value="{{ old('email', $vendor->email) }}" style="{{ $fieldStyle('email', '') }}">
                            @if($fieldError('email'))<div class="vendor-field-error">{{ $fieldError('email') }}</div>@endif
                        </div>
                        <div class="vendor-field">
                            <label for="vendor_whatsapp_mobile">WhatsApp</label>
                            <div class="vendor-phone-shell" style="{{ $fieldStyle('whatsapp', '') }}">
                                @include('partials.country-code-picker', [
                                    'name' => 'whatsapp_country_code',
                                    'pickerId' => 'vendor_whatsapp_country_code_mobile',
                                    'value' => old('whatsapp_country_code', $whatsappParts['code']),
                                    'options' => $countryCodeOptions,
                                    'dividerColor' => '#cbd5e1',
                                    'width' => '92px',
                                ])
                                <input id="vendor_whatsapp_mobile" type="text" name="whatsapp" value="{{ $whatsappParts['local'] }}" inputmode="numeric" maxlength="15" pattern="[0-9]{6,15}" data-phone-local>
                            </div>
                            @if($fieldError('whatsapp'))<div class="vendor-field-error">{{ $fieldError('whatsapp') }}</div>@endif
                        </div>
                    </div>
                </div>
            </section>

            <section class="vendor-form-optional">
                <button type="button" class="vendor-form-toggle" data-vendor-toggle="commercial">
                    <span><strong>Commercial Details</strong><span>Type, GST, payment terms.</span></span>
                    <em data-vendor-icon="commercial">{{ $commercialOpen ? '−' : '+' }}</em>
                </button>
                <div class="vendor-form-mobile-body vendor-form-panel" data-vendor-panel="commercial" {{ $commercialOpen ? '' : 'hidden' }}>
                    <div class="vendor-field-grid">
                        <div class="vendor-field">
                            <label for="vendor_type_mobile">Vendor Type</label>
                            <input id="vendor_type_mobile" type="text" name="vendor_type" value="{{ old('vendor_type', $vendor->vendor_type) }}" placeholder="Rental supplier, delivery partner, wholesaler" style="{{ $fieldStyle('vendor_type', '') }}">
                        </div>
                        <div class="vendor-field">
                            <label for="vendor_gst_mobile">GST Number</label>
                            <input id="vendor_gst_mobile" type="text" name="gst_number" value="{{ old('gst_number', $vendor->gst_number) }}" style="{{ $fieldStyle('gst_number', '') }}">
                        </div>
                        <div class="vendor-field">
                            <label for="vendor_gst_reg_mobile">GST Registration</label>
                            <input id="vendor_gst_reg_mobile" type="text" name="gst_registration_type" value="{{ old('gst_registration_type', $vendor->gst_registration_type) }}" placeholder="Regular, composition, unregistered" style="{{ $fieldStyle('gst_registration_type', '') }}">
                        </div>
                        <div class="vendor-field">
                            <label for="vendor_terms_mobile">Payment Terms</label>
                            <input id="vendor_terms_mobile" type="text" name="payment_terms" value="{{ old('payment_terms', $vendor->payment_terms) }}" placeholder="Immediate, 7 days, 30 days" style="{{ $fieldStyle('payment_terms', '') }}">
                        </div>
                    </div>
                </div>
            </section>

            <section class="vendor-form-optional">
                <button type="button" class="vendor-form-toggle" data-vendor-toggle="address">
                    <span><strong>Address & Status</strong><span>State, pincode, address, active flag.</span></span>
                    <em data-vendor-icon="address">{{ $addressOpen ? '−' : '+' }}</em>
                </button>
                <div class="vendor-form-mobile-body vendor-form-panel" data-vendor-panel="address" {{ $addressOpen ? '' : 'hidden' }}>
                    <div class="vendor-field-grid">
                        <div class="vendor-field">
                            <label for="vendor_state_mobile">State</label>
                            <input id="vendor_state_mobile" type="text" name="state" value="{{ old('state', $vendor->state) }}" style="{{ $fieldStyle('state', '') }}">
                        </div>
                        <div class="vendor-field">
                            <label for="vendor_pin_mobile">Pincode</label>
                            <input id="vendor_pin_mobile" type="text" name="pincode" value="{{ old('pincode', $vendor->pincode) }}" style="{{ $fieldStyle('pincode', '') }}">
                        </div>
                        <div class="vendor-field">
                            <label for="vendor_address_mobile">Address</label>
                            <textarea id="vendor_address_mobile" name="address" rows="4" style="{{ $fieldStyle('address', '') }}">{{ old('address', $vendor->address) }}</textarea>
                        </div>
                        <div class="vendor-field">
                            <label style="display:flex; align-items:center; gap:10px; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#f8fafc;">
                                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $vendor->is_active ?? true)) style="width:auto; min-height:0;">
                                <span style="color:#0f172a; font-size:14px; font-weight:700; text-transform:none; letter-spacing:0;">Active vendor</span>
                            </label>
                        </div>
                    </div>
                </div>
            </section>

            <section class="vendor-form-optional">
                <button type="button" class="vendor-form-toggle" data-vendor-toggle="notes">
                    <span><strong>Notes</strong><span>Extra context for operations.</span></span>
                    <em data-vendor-icon="notes">{{ $notesOpen ? '−' : '+' }}</em>
                </button>
                <div class="vendor-form-mobile-body vendor-form-panel" data-vendor-panel="notes" {{ $notesOpen ? '' : 'hidden' }}>
                    <div class="vendor-field">
                        <label for="vendor_notes_mobile">Notes</label>
                        <textarea id="vendor_notes_mobile" name="notes" rows="3" style="{{ $fieldStyle('notes', '') }}">{{ old('notes', $vendor->notes) }}</textarea>
                        @if($fieldError('notes'))<div class="vendor-field-error">{{ $fieldError('notes') }}</div>@endif
                    </div>
                </div>
            </section>

            <div class="vendor-form-actions">
                <a href="{{ route('vendors.index') }}">Cancel</a>
                <button type="submit">{{ $isEdit ? 'Update Vendor' : 'Save Vendor' }}</button>
            </div>
        </form>
    </div>

    <div class="vendor-form-desktop">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; margin-bottom:24px;">
            <div>
                <div style="display:inline-flex; padding:6px 10px; border-radius:999px; background:#eff6ff; color:#1d4ed8; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.08em;">Company Settings</div>
                <h1 style="margin:12px 0 8px; font-size:32px; letter-spacing:-0.03em;">{{ $isEdit ? 'Edit Vendor' : 'Add Vendor' }}</h1>
                <p style="margin:0; color:#64748b;">Manage vendor and third-party master records from one clean operational area.</p>
            </div>
            <a href="{{ route('vendors.index') }}" style="display:inline-flex; align-items:center; justify-content:center; padding:10px 14px; border-radius:12px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a; text-decoration:none; font-weight:600;">Back to Vendors</a>
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

        <form method="POST" action="{{ $isEdit ? route('vendors.update', $vendor) : route('vendors.store') }}" style="display:grid; gap:20px;">
            @csrf
            @if($isEdit)
                @method('PUT')
            @endif

            <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:22px; padding:24px;">
                <div style="display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:18px;">
                    <div>
                        <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Vendor Name</label>
                        <input type="text" name="name" value="{{ old('name', $vendor->name) }}" required style="{{ $fieldStyle('name', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px;') }}">
                        @if($fieldError('name'))<div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('name') }}</div>@endif
                    </div>
                    <div>
                        <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Contact Person</label>
                        <input type="text" name="contact_person" value="{{ old('contact_person', $vendor->contact_person) }}" style="{{ $fieldStyle('contact_person', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px;') }}">
                        @if($fieldError('contact_person'))<div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('contact_person') }}</div>@endif
                    </div>
                    <div>
                        <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Phone</label>
                        <div style="{{ $fieldStyle('phone', 'display:flex; align-items:center; border:1px solid #cbd5e1; border-radius:14px; overflow:visible;') }}">
                            @include('partials.country-code-picker', [
                                'name' => 'phone_country_code',
                                'pickerId' => 'phone_country_code',
                                'value' => $phoneCountryCode,
                                'options' => $countryCodeOptions,
                                'dividerColor' => '#cbd5e1',
                                'width' => '92px',
                            ])
                            <input type="text" name="phone" value="{{ $phoneValue }}" inputmode="numeric" maxlength="15" pattern="[0-9]{6,15}" data-phone-local style="width:100%; padding:12px 14px; border:none; outline:none; background:transparent;">
                        </div>
                        @if($fieldError('phone'))<div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('phone') }}</div>@endif
                    </div>
                    <div>
                        <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Email</label>
                        <input type="email" name="email" value="{{ old('email', $vendor->email) }}" style="{{ $fieldStyle('email', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px;') }}">
                        @if($fieldError('email'))<div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('email') }}</div>@endif
                    </div>
                    <div>
                        <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">WhatsApp</label>
                        <div style="{{ $fieldStyle('whatsapp', 'display:flex; align-items:center; border:1px solid #cbd5e1; border-radius:14px; overflow:visible;') }}">
                            @include('partials.country-code-picker', [
                                'name' => 'whatsapp_country_code',
                                'pickerId' => 'whatsapp_country_code',
                                'value' => old('whatsapp_country_code', $whatsappParts['code']),
                                'options' => $countryCodeOptions,
                                'dividerColor' => '#cbd5e1',
                                'width' => '92px',
                            ])
                            <input type="text" name="whatsapp" value="{{ $whatsappParts['local'] }}" inputmode="numeric" maxlength="15" pattern="[0-9]{6,15}" data-phone-local style="width:100%; padding:12px 14px; border:none; outline:none; background:transparent;">
                        </div>
                        @if($fieldError('whatsapp'))<div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('whatsapp') }}</div>@endif
                    </div>
                    <div>
                        <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Vendor Type</label>
                        <input type="text" name="vendor_type" value="{{ old('vendor_type', $vendor->vendor_type) }}" placeholder="Rental supplier, delivery partner, wholesaler" style="{{ $fieldStyle('vendor_type', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px;') }}">
                    </div>
                    <div>
                        <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">GST Number</label>
                        <input type="text" name="gst_number" value="{{ old('gst_number', $vendor->gst_number) }}" style="{{ $fieldStyle('gst_number', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px;') }}">
                    </div>
                    <div>
                        <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">GST Registration</label>
                        <input type="text" name="gst_registration_type" value="{{ old('gst_registration_type', $vendor->gst_registration_type) }}" placeholder="Regular, composition, unregistered" style="{{ $fieldStyle('gst_registration_type', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px;') }}">
                    </div>
                    <div>
                        <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">City</label>
                        <select name="city_id" style="{{ $fieldStyle('city_id', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff;') }}">
                            <option value="">Select city</option>
                            @foreach($cities as $city)
                                <option value="{{ $city->id }}" @selected((string) old('city_id', $vendor->city_id) === (string) $city->id)>{{ $city->name }}{{ $city->state ? ' - ' . $city->state : '' }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">State</label>
                        <input type="text" name="state" value="{{ old('state', $vendor->state) }}" style="{{ $fieldStyle('state', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px;') }}">
                    </div>
                    <div>
                        <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Pincode</label>
                        <input type="text" name="pincode" value="{{ old('pincode', $vendor->pincode) }}" style="{{ $fieldStyle('pincode', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px;') }}">
                    </div>
                    <div>
                        <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Payment Terms</label>
                        <input type="text" name="payment_terms" value="{{ old('payment_terms', $vendor->payment_terms) }}" placeholder="Immediate, 7 days, 30 days" style="{{ $fieldStyle('payment_terms', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px;') }}">
                    </div>
                    <div style="display:flex; align-items:end;">
                        <label style="{{ $fieldStyle('is_active', 'display:flex; align-items:center; gap:10px; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; width:100%; background:#f8fafc;') }}">
                            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $vendor->is_active ?? true))>
                            <span style="font-weight:600; color:#0f172a;">Active vendor</span>
                        </label>
                    </div>
                    <div style="grid-column:1 / -1;">
                        <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Address</label>
                        <textarea name="address" rows="4" style="{{ $fieldStyle('address', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; resize:vertical;') }}">{{ old('address', $vendor->address) }}</textarea>
                    </div>
                    <div style="grid-column:1 / -1;">
                        <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Notes</label>
                        <textarea name="notes" rows="3" style="{{ $fieldStyle('notes', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; resize:vertical;') }}">{{ old('notes', $vendor->notes) }}</textarea>
                    </div>
                </div>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:12px;">
                <a href="{{ route('vendors.index') }}" style="display:inline-flex; align-items:center; justify-content:center; padding:11px 16px; border-radius:12px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a; text-decoration:none; font-weight:600;">Cancel</a>
                <button type="submit" style="display:inline-flex; align-items:center; justify-content:center; padding:11px 18px; border:none; border-radius:12px; background:#1d4ed8; color:#ffffff; font-weight:700; cursor:pointer;">
                    {{ $isEdit ? 'Update Vendor' : 'Save Vendor' }}
                </button>
            </div>
        </form>
    </div>
</div>
<script>
(function () {
    document.querySelectorAll('[data-phone-local]').forEach(function (input) {
        input.addEventListener('input', function () {
            input.value = input.value.replace(/\D+/g, '').slice(0, 15);
        });
    });

    document.querySelectorAll('[data-vendor-toggle]').forEach(function (button) {
        button.addEventListener('click', function () {
            const key = button.getAttribute('data-vendor-toggle');
            const panel = document.querySelector('[data-vendor-panel="' + key + '"]');
            const icon = document.querySelector('[data-vendor-icon="' + key + '"]');

            if (!panel || !icon) {
                return;
            }

            panel.hidden = !panel.hidden;
            icon.textContent = panel.hidden ? '+' : '−';
        });
    });
})();
</script>
