@php
    $staff = $staff ?? null;
    $isEdit = $isEdit ?? isset($staff);
    $phoneParts = \App\Support\PhoneNumber::split(old('phone', $staff->phone ?? ''));
    $phoneValue = $phoneParts['local'];
    $phoneCountryCode = old('phone_country_code', $phoneParts['code']);
    $countryCodeOptions = \App\Support\PhoneNumber::countryCodeOptions();
@endphp

<style>
    .staff-form-shell { display:grid; gap:18px; }
    .staff-form-header { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap; }
    .staff-form-header h1 { margin:0; font-size:28px; color:#0f172a; }
    .staff-form-header p { margin:6px 0 0; color:#64748b; font-size:13px; max-width:740px; }
    .staff-form-actions { display:flex; gap:8px; flex-wrap:wrap; }
    .staff-form-card { background:#fff; border:1px solid #dbe3ef; border-radius:14px; padding:18px; box-shadow:0 8px 24px rgba(15,23,42,0.04); }
    .staff-form-card h2 { margin:0 0 4px; font-size:16px; color:#0f172a; }
    .staff-form-card p.section-copy { margin:0 0 14px; font-size:12px; color:#64748b; }
    .staff-form-grid { display:grid; grid-template-columns:repeat(12, minmax(0, 1fr)); gap:14px; }
    .staff-col-3 { grid-column:span 3; }
    .staff-col-4 { grid-column:span 4; }
    .staff-col-6 { grid-column:span 6; }
    .staff-col-8 { grid-column:span 8; }
    .staff-col-12 { grid-column:span 12; }
    .staff-field { display:flex; flex-direction:column; gap:6px; }
    .staff-field label { font-size:12px; font-weight:700; color:#334155; letter-spacing:.03em; text-transform:uppercase; }
    .staff-field .hint { font-size:11px; color:#94a3b8; }
    .staff-field input,
    .staff-field select,
    .staff-field textarea {
        width:100%; border:1px solid #cbd5e1; border-radius:10px; padding:10px 12px;
        font-size:14px; color:#0f172a; background:#fff; box-sizing:border-box;
    }
    .staff-field textarea { min-height:110px; resize:vertical; }
    .staff-field input:focus,
    .staff-field select:focus,
    .staff-field textarea:focus {
        outline:none; border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,0.12);
    }
    .staff-phone-group {
        display:flex;
        align-items:center;
        border:1px solid #cbd5e1;
        border-radius:10px;
        overflow:visible;
        background:#fff;
    }
    .staff-phone-code {
        padding:10px 12px;
        background:#f8fafc;
        border-right:1px solid #cbd5e1;
        color:#475569;
        font-weight:700;
    }
    .staff-phone-group select {
        border:none;
        border-right:1px solid #cbd5e1;
        background:#f8fafc;
        padding:10px 8px;
        color:#475569;
        font-weight:700;
    }
    .staff-phone-group input {
        border:none;
        box-shadow:none !important;
    }
    .staff-check {
        display:flex; align-items:flex-start; gap:10px; padding:12px;
        border:1px solid #e2e8f0; border-radius:12px; background:#f8fafc;
    }
    .staff-check input { width:18px; height:18px; margin-top:2px; }
    .staff-check strong { display:block; color:#0f172a; font-size:14px; }
    .staff-check span { display:block; color:#64748b; font-size:12px; margin-top:3px; }
    .staff-summary { display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:10px; }
    .staff-metric { border:1px solid #e2e8f0; border-radius:12px; padding:12px; background:#f8fafc; }
    .staff-metric span { display:block; font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:.04em; }
    .staff-metric strong { display:block; margin-top:6px; font-size:18px; color:#0f172a; }
    .staff-note { padding:10px 12px; border-radius:10px; background:#f8fafc; border:1px solid #e2e8f0; font-size:12px; color:#475569; }
    .staff-error { border:1px solid #fecaca; background:#fef2f2; color:#b91c1c; border-radius:12px; padding:14px 16px; }
    .staff-btn,
    .staff-btn-secondary {
        display:inline-flex; align-items:center; justify-content:center; gap:6px;
        border-radius:10px; padding:10px 14px; font-size:13px; font-weight:600; text-decoration:none;
        border:1px solid transparent; cursor:pointer;
    }
    .staff-btn { background:#2563eb; color:#fff; }
    .staff-btn-secondary { background:#fff; border-color:#cbd5e1; color:#334155; }
    @media (max-width: 980px) {
        .staff-col-3, .staff-col-4, .staff-col-6, .staff-col-8 { grid-column:span 12; }
        .staff-summary { grid-template-columns:1fr; }
    }
    @media (max-width: 640px) {
        .staff-form-actions { flex-direction:column; align-items:stretch; }
    }
</style>

<div class="staff-form-shell">
    <div class="staff-form-header">
        <div>
            <h1>{{ $isEdit ? 'Edit Staff Profile' : 'Add Staff Profile' }}</h1>
            <p>Define operational role, assignment eligibility, and contact details clearly so delivery and pickup teams only see relevant people.</p>
        </div>
        <div class="staff-form-actions">
            <a href="{{ route('staff.index') }}" class="staff-btn-secondary">Back to Staff</a>
            @if($isEdit)
                <a href="{{ route('staff.show', $staff->id) }}" class="staff-btn-secondary">View Profile</a>
            @endif
        </div>
    </div>

    @if ($errors->any())
        <div class="staff-error">
            <strong>Please review the staff details below.</strong>
            <ul style="margin:8px 0 0 18px; padding:0;">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="staff-form-card">
        <h2>Staff Snapshot</h2>
        <p class="section-copy">Keep the record practical for office operations, dispatch teams, and vendor coordination.</p>
        <div class="staff-summary">
            <div class="staff-metric">
                <span>Mode</span>
                <strong>{{ $isEdit ? 'Update Existing' : 'Create Fresh' }}</strong>
            </div>
            <div class="staff-metric">
                <span>Primary Role</span>
                <strong>{{ $roleOptions[$defaultRole] ?? 'Office' }}</strong>
            </div>
            <div class="staff-metric">
                <span>Assignment Setup</span>
                <strong>{{ $defaultAssignmentEnabled === '1' ? ($assignmentRoleOptions[$defaultAssignmentRole] ?? 'Enabled') : 'Disabled' }}</strong>
            </div>
        </div>
    </div>

    <div class="staff-form-card">
        <h2>Core Details</h2>
        <p class="section-copy">Basic contact and classification used across staff listings and assignment screens.</p>
        <div class="staff-form-grid">
            <div class="staff-field staff-col-4">
                <label for="name">Name</label>
                <input type="text" name="name" id="name" value="{{ old('name', $staff->name ?? '') }}" required>
            </div>

            <div class="staff-field staff-col-4">
                <label for="role">Role / Type</label>
                <select name="role" id="role" required>
                    @foreach($roleOptions as $roleValue => $roleLabel)
                        <option value="{{ $roleValue }}" {{ $defaultRole === $roleValue ? 'selected' : '' }}>{{ $roleLabel }}</option>
                    @endforeach
                </select>
            </div>

            <div class="staff-field staff-col-4">
                <label for="status">Status</label>
                <select name="status" id="status" required>
                    <option value="active" {{ old('status', $staff->status ?? 'active') === 'active' ? 'selected' : '' }}>Active</option>
                    <option value="inactive" {{ old('status', $staff->status ?? 'active') === 'inactive' ? 'selected' : '' }}>Inactive</option>
                </select>
            </div>

            <div class="staff-field staff-col-4">
                <label for="phone">Phone</label>
                <div class="staff-phone-group">
                    @include('partials.country-code-picker', [
                        'name' => 'phone_country_code',
                        'pickerId' => 'phone_country_code',
                        'value' => $phoneCountryCode,
                        'options' => $countryCodeOptions,
                        'dividerColor' => '#cbd5e1',
                        'width' => '92px',
                    ])
                    <input type="text" name="phone" id="phone" value="{{ $phoneValue }}" inputmode="numeric" maxlength="15" pattern="[0-9]{6,15}" data-phone-local>
                </div>
            </div>

            <div class="staff-field staff-col-4">
                <label for="email">Email</label>
                <input type="email" name="email" id="email" value="{{ old('email', $staff->email ?? '') }}">
            </div>

            @if(\App\Models\Staff::hasCityColumn())
                <div class="staff-field staff-col-4">
                    <label for="city">City</label>
                    <input type="text" name="city" id="city" value="{{ old('city', $staff->city ?? '') }}">
                </div>
            @endif

            <div class="staff-field staff-col-4">
                <label for="joining_date">Joining Date</label>
                <input type="date" name="joining_date" id="joining_date" value="{{ old('joining_date', $staff->joining_date ?? '') }}">
            </div>

            <div class="staff-field staff-col-4">
                <label for="salary">Salary</label>
                <input type="number" step="0.01" min="0" name="salary" id="salary" value="{{ old('salary', $staff->salary ?? '') }}">
            </div>

            <div class="staff-field staff-col-12">
                <label for="address">Address</label>
                <textarea name="address" id="address">{{ old('address', $staff->address ?? '') }}</textarea>
            </div>
        </div>
    </div>

    <div class="staff-form-card">
        <h2>Assignment Settings</h2>
        <p class="section-copy">This decides whether the person appears in delivery and pickup assignment dropdowns.</p>
        <div class="staff-form-grid">
            <div class="staff-field staff-col-6">
                <label for="assignment_role">Assignment Role</label>
                <select name="assignment_role" id="assignment_role">
                    @foreach($assignmentRoleOptions as $roleValue => $roleLabel)
                        <option value="{{ $roleValue }}" {{ $defaultAssignmentRole === $roleValue ? 'selected' : '' }}>{{ $roleLabel }}</option>
                    @endforeach
                </select>
                <span class="hint">Use Delivery Staff, Vendor, or Third Party for assignable logistics people.</span>
            </div>

            <div class="staff-field staff-col-6">
                <label>Assignment Eligibility</label>
                <input type="hidden" name="is_assignment_enabled" value="0">
                <label class="staff-check" style="text-transform:none;font-weight:400;">
                    <input type="checkbox" name="is_assignment_enabled" value="1" {{ $defaultAssignmentEnabled === '1' ? 'checked' : '' }}>
                    <span>
                        <strong>Show in assignment dropdowns</strong>
                        <span>Only enabled active records should appear for delivery and pickup assignment.</span>
                    </span>
                </label>
            </div>

            @if(\App\Models\Staff::hasNotesColumn())
                <div class="staff-field staff-col-12">
                    <label for="notes">Operational Notes</label>
                    <textarea name="notes" id="notes">{{ old('notes', $staff->notes ?? '') }}</textarea>
                    <span class="hint">Use for vendor notes, availability reminders, or special handling instructions.</span>
                </div>
            @endif
        </div>
    </div>

    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap;">
        <div class="staff-note">Inactive or assignment-disabled records will no longer appear in delivery and pickup staff selection.</div>
        <div class="staff-form-actions">
            <a href="{{ route('staff.index') }}" class="staff-btn-secondary">Cancel</a>
            <button type="submit" class="staff-btn">{{ $isEdit ? 'Update Staff' : 'Save Staff' }}</button>
        </div>
    </div>
</div>
<script>
document.querySelectorAll('[data-phone-local]').forEach(function (input) {
    input.addEventListener('input', function () {
        input.value = input.value.replace(/\D+/g, '').slice(0, 15);
    });
});
</script>
