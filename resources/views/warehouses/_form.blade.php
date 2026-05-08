@php
    $isEdit = $warehouse->exists;
    $fieldStyle = fn (string $field, string $base) => $base . ($errors->has($field)
        ? ' border-color:#dc2626; box-shadow:0 0 0 3px rgba(220, 38, 38, 0.12); background:#fff7f7;'
        : '');
    $fieldError = fn (string $field) => $errors->first($field);
@endphp

<div style="max-width:860px; margin:0 auto;">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; margin-bottom:24px;">
            <div>
                <div style="display:inline-flex; padding:6px 10px; border-radius:999px; background:#eff6ff; color:#1d4ed8; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.08em;">Organization &amp; Settings</div>
                <h1 style="margin:12px 0 8px; font-size:32px; letter-spacing:-0.03em;">{{ $isEdit ? 'Edit Warehouse' : 'Add Warehouse' }}</h1>
                <p style="margin:0; color:#64748b;">Map storage locations with reusable city master records and keep admin forms consistent.</p>
            </div>
            <a href="{{ route('warehouses.index') }}" style="display:inline-flex; align-items:center; justify-content:center; padding:10px 14px; border-radius:12px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a; text-decoration:none; font-weight:600;">
                Back to Warehouses
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

        <form method="POST" action="{{ $isEdit ? route('warehouses.update', $warehouse) : route('warehouses.store') }}" style="display:grid; gap:20px;">
            @csrf
            @if($isEdit)
                @method('PUT')
            @endif

            <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:22px; padding:24px;">
                <div style="display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:18px;">
                    <div>
                        <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Warehouse Name</label>
                        <input type="text" name="name" value="{{ old('name', $warehouse->name) }}" required style="{{ $fieldStyle('name', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px;') }}">
                        @if($fieldError('name'))
                            <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('name') }}</div>
                        @endif
                    </div>
                    <div>
                        <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Code</label>
                        <input type="text" name="code" value="{{ old('code', $warehouse->code) }}" style="{{ $fieldStyle('code', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px;') }}">
                        @if($fieldError('code'))
                            <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('code') }}</div>
                        @endif
                    </div>
                    <div>
                        <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">City</label>
                        <select name="city_id" style="{{ $fieldStyle('city_id', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff;') }}">
                            <option value="">Select city</option>
                            @foreach($cities as $city)
                                <option value="{{ $city->id }}" @selected((string) old('city_id', $warehouse->city_id) === (string) $city->id)>{{ $city->name }}{{ $city->state ? ' - ' . $city->state : '' }}</option>
                            @endforeach
                        </select>
                        @if($fieldError('city_id'))
                            <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('city_id') }}</div>
                        @endif
                    </div>
                    <div>
                        <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">State</label>
                        <input type="text" name="state" value="{{ old('state', $warehouse->state) }}" style="{{ $fieldStyle('state', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px;') }}">
                        @if($fieldError('state'))
                            <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('state') }}</div>
                        @endif
                    </div>
                    <div>
                        <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Pincode</label>
                        <input type="text" name="pincode" value="{{ old('pincode', $warehouse->pincode) }}" style="{{ $fieldStyle('pincode', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px;') }}">
                        @if($fieldError('pincode'))
                            <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('pincode') }}</div>
                        @endif
                    </div>
                    <div style="display:flex; align-items:end;">
                        <label style="{{ $fieldStyle('is_active', 'display:flex; align-items:center; gap:10px; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; width:100%; background:#f8fafc;') }}">
                            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $warehouse->is_active ?? true))>
                            <span style="font-weight:600; color:#0f172a;">Active warehouse</span>
                        </label>
                    </div>
                    <div style="grid-column:1 / -1;">
                        <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Address</label>
                        <textarea name="address" rows="4" style="{{ $fieldStyle('address', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; resize:vertical;') }}">{{ old('address', $warehouse->address) }}</textarea>
                        @if($fieldError('address'))
                            <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('address') }}</div>
                        @endif
                    </div>
                </div>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:12px;">
                <a href="{{ route('warehouses.index') }}" style="display:inline-flex; align-items:center; justify-content:center; padding:11px 16px; border-radius:12px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a; text-decoration:none; font-weight:600;">Cancel</a>
                <button type="submit" style="display:inline-flex; align-items:center; justify-content:center; padding:11px 18px; border:none; border-radius:12px; background:#6d28d9; color:#ffffff; font-weight:700; cursor:pointer;">
                    {{ $isEdit ? 'Update Warehouse' : 'Save Warehouse' }}
                </button>
            </div>
        </form>
    </div>
