@php
    $isEdit = $user->exists;
    $fieldStyle = fn (string $field, string $base) => $base . ($errors->has($field)
        ? ' border-color:#dc2626; box-shadow:0 0 0 3px rgba(220, 38, 38, 0.12); background:#fff7f7;'
        : '');
    $fieldError = fn (string $field) => $errors->first($field);
    $phoneParts = \App\Support\PhoneNumber::split(old('phone', $user->phone));
    $phoneValue = $phoneParts['local'];
    $phoneCountryCode = old('phone_country_code', $phoneParts['code']);
    $countryCodeOptions = \App\Support\PhoneNumber::countryCodeOptions();
@endphp

<style>
    @media (max-width: 767px) {
        .user-form-header {
            flex-direction: column;
            margin-bottom: 18px !important;
        }
        .user-form-header h1 {
            font-size: 26px !important;
        }
        .user-form-actions {
            width: 100%;
        }
        .user-form-actions a,
        .user-form-actions button {
            width: 100%;
        }
        .user-form-grid {
            grid-template-columns: 1fr !important;
            gap: 14px !important;
            padding: 16px !important;
        }
        .user-phone-wrap {
            flex-wrap: nowrap;
            min-height: 44px;
        }
        .user-phone-wrap [data-country-code-picker] {
            flex: 0 0 92px;
            min-width: 92px;
        }
        .user-phone-wrap input {
            min-width: 0;
            min-height: 44px;
        }
        .user-form-footer {
            flex-direction: column;
        }
        .user-form-footer a,
        .user-form-footer button {
            width: 100%;
            min-height: 44px;
        }
    }
</style>

<div style="max-width:1100px; margin:0 auto;">
    <div class="user-form-header" style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; margin-bottom:24px;">
        <div>
            <div style="display:inline-flex; padding:6px 10px; border-radius:999px; background:#eff6ff; color:#1d4ed8; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.08em;">Company Settings</div>
            <h1 style="margin:12px 0 8px; font-size:32px; letter-spacing:-0.03em;">{{ $isEdit ? 'Edit User' : 'Add User' }}</h1>
            <p style="margin:0; color:#64748b;">Create team accounts with role mapping, city assignment, and clear operational status.</p>
        </div>
        <a href="{{ route('users.index') }}" class="user-form-actions" style="display:inline-flex; align-items:center; justify-content:center; padding:10px 14px; border-radius:12px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a; text-decoration:none; font-weight:600;">
            Back to Users
        </a>
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

    <form method="POST" action="{{ $isEdit ? route('users.update', $user) : route('users.store') }}" style="display:grid; gap:20px;">
        @csrf
        @if($isEdit)
            @method('PUT')
        @endif

        <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:22px; overflow:hidden;">
            <div style="padding:20px 22px; border-bottom:1px solid #e2e8f0;">
                <h2 style="margin:0; font-size:20px;">User Profile</h2>
                <p style="margin:6px 0 0; color:#64748b; font-size:14px;">Map each user to a role and city so the super admin structure stays consistent.</p>
            </div>
            <div class="user-form-grid" style="padding:22px; display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:18px;">
                <div>
                    <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Full Name</label>
                    <input type="text" name="name" value="{{ old('name', $user->name) }}" required style="{{ $fieldStyle('name', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px;') }}">
                    @if($fieldError('name'))
                        <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('name') }}</div>
                    @endif
                </div>
                <div>
                    <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Email</label>
                    <input type="email" name="email" value="{{ old('email', $user->email) }}" required style="{{ $fieldStyle('email', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px;') }}">
                    @if($fieldError('email'))
                        <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('email') }}</div>
                    @endif
                </div>
                <div>
                    <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Phone</label>
                    <div class="user-phone-wrap" style="{{ $fieldStyle('phone', 'display:flex; align-items:center; border:1px solid #cbd5e1; border-radius:14px; overflow:visible;') }}">
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
                    <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Role</label>
                    <select name="role_id" required style="{{ $fieldStyle('role_id', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff;') }}">
                        <option value="">Select role</option>
                        @foreach($roles as $role)
                            <option value="{{ $role->id }}" @selected((string) old('role_id', $user->role_id) === (string) $role->id)>{{ $role->name }}</option>
                        @endforeach
                    </select>
                    @if($fieldError('role_id'))
                        <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('role_id') }}</div>
                    @endif
                </div>
                <div>
                    <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">City</label>
                    <select name="city_id" style="{{ $fieldStyle('city_id', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff;') }}">
                        <option value="">Select city</option>
                        @foreach($cities as $city)
                            <option value="{{ $city->id }}" @selected((string) old('city_id', $user->city_id) === (string) $city->id)>{{ $city->name }}{{ $city->state ? ' - ' . $city->state : '' }}</option>
                        @endforeach
                    </select>
                    @if($fieldError('city_id'))
                        <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('city_id') }}</div>
                    @endif
                </div>
                <div>
                    <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Password {{ $isEdit ? '(leave blank to keep current password)' : '' }}</label>
                    <input type="password" name="password" {{ $isEdit ? '' : 'required' }} style="{{ $fieldStyle('password', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px;') }}">
                    @if($fieldError('password'))
                        <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('password') }}</div>
                    @endif
                </div>
                <div style="grid-column:1 / -1;">
                    <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Address</label>
                    <textarea name="address" rows="4" style="{{ $fieldStyle('address', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; resize:vertical;') }}">{{ old('address', $user->address) }}</textarea>
                    @if($fieldError('address'))
                        <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('address') }}</div>
                    @endif
                </div>
                <div style="grid-column:1 / -1;">
                    <label style="{{ $fieldStyle('is_active', 'display:flex; align-items:center; gap:10px; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#f8fafc; max-width:280px;') }}">
                        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $user->is_active ?? true))>
                        <span style="font-weight:600; color:#0f172a;">Active user</span>
                    </label>
                </div>
            </div>
        </div>

        <div class="user-form-footer" style="display:flex; justify-content:flex-end; gap:12px;">
            <a href="{{ route('users.index') }}" style="display:inline-flex; align-items:center; justify-content:center; padding:11px 16px; border-radius:12px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a; text-decoration:none; font-weight:600;">Cancel</a>
            <button type="submit" style="display:inline-flex; align-items:center; justify-content:center; padding:11px 18px; border:none; border-radius:12px; background:#1d4ed8; color:#ffffff; font-weight:700; cursor:pointer;">
                {{ $isEdit ? 'Update User' : 'Save User' }}
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
