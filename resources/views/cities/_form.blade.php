@php
    $isEdit = $city->exists;
    $fieldStyle = fn (string $field, string $base) => $base . ($errors->has($field)
        ? ' border-color:#dc2626; box-shadow:0 0 0 3px rgba(220, 38, 38, 0.12); background:#fff7f7;'
        : '');
    $fieldError = fn (string $field) => $errors->first($field);
@endphp

<div style="max-width:960px; margin:0 auto;">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; margin-bottom:24px;">
        <div>
            <div style="display:inline-flex; padding:6px 10px; border-radius:999px; background:#eff6ff; color:#1d4ed8; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.08em;">Company Settings</div>
            <h1 style="margin:12px 0 8px; font-size:32px; letter-spacing:-0.03em;">{{ $isEdit ? 'Edit City' : 'Add City' }}</h1>
            <p style="margin:0; color:#64748b;">Keep city master data reusable across users, warehouses, vendors, customers, and reports.</p>
        </div>
        <a href="{{ route('cities.index') }}" style="display:inline-flex; align-items:center; justify-content:center; padding:10px 14px; border-radius:12px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a; text-decoration:none; font-weight:600;">Back to Cities</a>
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

    <form method="POST" action="{{ $isEdit ? route('cities.update', $city) : route('cities.store') }}" style="display:grid; gap:20px;">
        @csrf
        @if($isEdit)
            @method('PUT')
        @endif

        <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:22px; padding:24px;">
            <div style="display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:18px;">
                <div>
                    <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">City Name</label>
                    <input type="text" name="name" value="{{ old('name', $city->name) }}" required style="{{ $fieldStyle('name', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px;') }}">
                    @if($fieldError('name'))
                        <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('name') }}</div>
                    @endif
                </div>
                <div>
                    <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">State</label>
                    <input type="text" name="state" value="{{ old('state', $city->state) }}" style="{{ $fieldStyle('state', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px;') }}">
                    @if($fieldError('state'))
                        <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('state') }}</div>
                    @endif
                </div>
                <div>
                    <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Country</label>
                    <input type="text" name="country" value="{{ old('country', $city->country ?: 'India') }}" style="{{ $fieldStyle('country', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px;') }}">
                    @if($fieldError('country'))
                        <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('country') }}</div>
                    @endif
                </div>
                <div style="display:flex; align-items:end;">
                    <label style="{{ $fieldStyle('is_active', 'display:flex; align-items:center; gap:10px; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; width:100%; background:#f8fafc;') }}">
                        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $city->is_active ?? true))>
                        <span style="font-weight:600; color:#0f172a;">Active city</span>
                    </label>
                </div>
            </div>
        </div>

        <div style="display:flex; justify-content:flex-end; gap:12px;">
            <a href="{{ route('cities.index') }}" style="display:inline-flex; align-items:center; justify-content:center; padding:11px 16px; border-radius:12px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a; text-decoration:none; font-weight:600;">Cancel</a>
            <button type="submit" style="display:inline-flex; align-items:center; justify-content:center; padding:11px 18px; border:none; border-radius:12px; background:#1d4ed8; color:#ffffff; font-weight:700; cursor:pointer;">
                {{ $isEdit ? 'Update City' : 'Save City' }}
            </button>
        </div>
    </form>
</div>
