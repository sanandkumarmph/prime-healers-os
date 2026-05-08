@php
    $isEdit = $vendor->exists;
    $fieldStyle = fn (string $field, string $base) => $base . ($errors->has($field)
        ? ' border-color:#dc2626; box-shadow:0 0 0 3px rgba(220, 38, 38, 0.12); background:#fff7f7;'
        : '');
    $fieldError = fn (string $field) => $errors->first($field);
    $phoneParts = \App\Support\PhoneNumber::split(old('phone', $vendor->phone));
    $phoneValue = $phoneParts['local'];
    $phoneCountryCode = old('phone_country_code', $phoneParts['code']);
    $countryCodeOptions = \App\Support\PhoneNumber::countryCodeOptions();
@endphp

<div style="max-width:1100px; margin:0 auto;">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; margin-bottom:24px;">
        <div>
            <div style="display:inline-flex; padding:6px 10px; border-radius:999px; background:#eff6ff; color:#1d4ed8; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.08em;">Organization &amp; Settings</div>
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
                    @if($fieldError('name'))
                        <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('name') }}</div>
                    @endif
                </div>
                <div>
                    <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Contact Person</label>
                    <input type="text" name="contact_person" value="{{ old('contact_person', $vendor->contact_person) }}" style="{{ $fieldStyle('contact_person', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px;') }}">
                    @if($fieldError('contact_person'))
                        <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('contact_person') }}</div>
                    @endif
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
                    @if($fieldError('phone'))
                        <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('phone') }}</div>
                    @endif
                </div>
                <div>
                    <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Email</label>
                    <input type="email" name="email" value="{{ old('email', $vendor->email) }}" style="{{ $fieldStyle('email', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px;') }}">
                    @if($fieldError('email'))
                        <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('email') }}</div>
                    @endif
                </div>
                <div>
                    <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">City</label>
                    <select name="city_id" style="{{ $fieldStyle('city_id', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff;') }}">
                        <option value="">Select city</option>
                        @foreach($cities as $city)
                            <option value="{{ $city->id }}" @selected((string) old('city_id', $vendor->city_id) === (string) $city->id)>{{ $city->name }}{{ $city->state ? ' - ' . $city->state : '' }}</option>
                        @endforeach
                    </select>
                    @if($fieldError('city_id'))
                        <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('city_id') }}</div>
                    @endif
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
                    @if($fieldError('address'))
                        <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('address') }}</div>
                    @endif
                </div>
                <div style="grid-column:1 / -1;">
                    <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Notes</label>
                    <textarea name="notes" rows="3" style="{{ $fieldStyle('notes', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; resize:vertical;') }}">{{ old('notes', $vendor->notes) }}</textarea>
                    @if($fieldError('notes'))
                        <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('notes') }}</div>
                    @endif
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
<script>
document.querySelectorAll('[data-phone-local]').forEach(function (input) {
    input.addEventListener('input', function () {
        input.value = input.value.replace(/\D+/g, '').slice(0, 15);
    });
});
</script>
