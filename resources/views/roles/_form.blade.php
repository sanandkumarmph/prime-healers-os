@php
    $isEdit = $role->exists;
    $selectedPermissions = old('permissions', $role->permissions ?? []);
    $fieldStyle = fn (string $field, string $base) => $base . ($errors->has($field)
        ? ' border-color:#dc2626; box-shadow:0 0 0 3px rgba(220, 38, 38, 0.12); background:#fff7f7;'
        : '');
    $fieldError = fn (string $field) => $errors->first($field);
    $hasPermissionError = $errors->has('permissions') || collect($errors->keys())->contains(fn ($key) => str_starts_with($key, 'permissions.'));
@endphp

<style>
    @media (max-width: 767px) {
        .role-form-header {
            flex-direction: column;
            margin-bottom: 18px !important;
        }
        .role-form-header h1 {
            font-size: 26px !important;
        }
        .role-form-grid {
            grid-template-columns: 1fr !important;
            gap: 14px !important;
            padding: 16px !important;
        }
        .role-permission-desktop {
            display: none !important;
        }
        .role-form-footer {
            flex-direction: column;
        }
        .role-form-footer a,
        .role-form-footer button {
            width: 100%;
            min-height: 44px;
        }
    }
</style>

<div style="max-width:1180px; margin:0 auto;">
    <div class="role-form-header" style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; margin-bottom:24px;">
        <div>
            <div style="display:inline-flex; padding:6px 10px; border-radius:999px; background:#eff6ff; color:#1d4ed8; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.08em;">Company Settings</div>
            <h1 style="margin:12px 0 8px; font-size:32px; letter-spacing:-0.03em;">{{ $isEdit ? 'Edit Role' : 'Add Role' }}</h1>
            <p style="margin:0; color:#64748b;">Create practical permission sets for super admin, operations, and staff workflows.</p>
        </div>
        <a href="{{ route('roles.index') }}" style="display:inline-flex; align-items:center; justify-content:center; padding:10px 14px; border-radius:12px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a; text-decoration:none; font-weight:600;">
            Back to Roles
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

    <form method="POST" action="{{ $isEdit ? route('roles.update', $role) : route('roles.store') }}" style="display:grid; gap:20px;">
        @csrf
        @if($isEdit)
            @method('PUT')
        @endif

        <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:22px; overflow:hidden;">
            <div style="padding:20px 22px; border-bottom:1px solid #e2e8f0;">
                <h2 style="margin:0; font-size:20px;">Role Definition</h2>
                <p style="margin:6px 0 0; color:#64748b; font-size:14px;">Keep the role naming clean and reuse it across users.</p>
            </div>
            <div class="role-form-grid" style="padding:22px; display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:18px;">
                <div>
                    <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Role Name</label>
                    <input type="text" name="name" value="{{ old('name', $role->name) }}" required style="{{ $fieldStyle('name', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px;') }}">
                    @if($fieldError('name'))
                        <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('name') }}</div>
                    @endif
                </div>
                <div>
                    <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Slug</label>
                    <input type="text" name="slug" value="{{ old('slug', $role->slug) }}" style="{{ $fieldStyle('slug', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px;') }}">
                    @if($fieldError('slug'))
                        <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('slug') }}</div>
                    @endif
                </div>
                <div style="grid-column:1 / -1;">
                    <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Description</label>
                    <textarea name="description" rows="3" style="{{ $fieldStyle('description', 'width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; resize:vertical;') }}">{{ old('description', $role->description) }}</textarea>
                    @if($fieldError('description'))
                        <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $fieldError('description') }}</div>
                    @endif
                </div>
                <div style="grid-column:1 / -1;">
                    <label style="{{ $fieldStyle('is_active', 'display:flex; align-items:center; gap:10px; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#f8fafc; max-width:280px;') }}">
                        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $role->is_active ?? true))>
                        <span style="font-weight:600; color:#0f172a;">Active role</span>
                    </label>
                </div>
            </div>
        </div>

        <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:22px; overflow:hidden;">
            <div style="padding:20px 22px; border-bottom:1px solid #e2e8f0;">
                <h2 style="margin:0; font-size:20px;">Module Permissions</h2>
                <p style="margin:6px 0 0; color:#64748b; font-size:14px;">Use simple read, create, update, and delete access to keep admin controls understandable.</p>
            </div>
            <div class="role-permission-desktop" style="padding:22px; display:grid; gap:14px;{{ $hasPermissionError ? ' border-top:1px solid #fecaca; background:#fff7f7;' : '' }}">
                @if($hasPermissionError)
                    <div style="margin-bottom:4px; color:#b91c1c; font-size:12px;">{{ $fieldError('permissions') ?: 'Review the permission selection below.' }}</div>
                @endif
                @foreach($permissionModules as $moduleKey => $moduleLabel)
                    <div style="display:grid; grid-template-columns:220px 1fr; gap:16px; padding:14px 16px; border:1px solid #e2e8f0; border-radius:16px; align-items:center;{{ $errors->has('permissions.' . $moduleKey) ? ' border-color:#dc2626; background:#fff7f7;' : '' }}">
                        <div>
                            <div style="font-weight:700; color:#0f172a;">{{ $moduleLabel }}</div>
                            <div style="margin-top:4px; color:#64748b; font-size:13px;">{{ ucwords(str_replace('_', ' ', $moduleKey)) }}</div>
                        </div>
                        <div style="display:flex; flex-wrap:wrap; gap:10px;">
                            @foreach($permissionActions as $action)
                                <label style="display:inline-flex; align-items:center; gap:8px; padding:10px 12px; border:1px solid #cbd5e1; border-radius:12px; background:#f8fafc; font-size:13px; color:#0f172a;">
                                    <input type="checkbox" name="permissions[{{ $moduleKey }}][]" value="{{ $action }}"
                                        @checked(in_array($action, $selectedPermissions[$moduleKey] ?? [], true))>
                                    <span>{{ ucfirst($action) }}</span>
                                </label>
                            @endforeach
                        </div>
                        @if($errors->has('permissions.' . $moduleKey))
                            <div style="grid-column:2; color:#b91c1c; font-size:12px;">{{ $fieldError('permissions.' . $moduleKey) }}</div>
                        @endif
                    </div>
                @endforeach
            </div>
            <div class="mobile-accordion-permissions" style="padding:16px;{{ $hasPermissionError ? ' border-top:1px solid #fecaca; background:#fff7f7;' : '' }}">
                @foreach($permissionModules as $moduleKey => $moduleLabel)
                    @php($moduleActions = $selectedPermissions[$moduleKey] ?? [])
                    <details {{ !empty($moduleActions) ? 'open' : '' }}>
                        <summary>
                            <span>{{ $moduleLabel }}</span>
                            <span>{{ count($moduleActions) ? count($moduleActions) . ' selected' : 'No access' }}</span>
                        </summary>
                        <div class="mobile-accordion-body">
                            @foreach($permissionActions as $action)
                                <label class="mobile-permission-check">
                                    <input type="checkbox" name="permissions[{{ $moduleKey }}][]" value="{{ $action }}" @checked(in_array($action, $moduleActions, true))>
                                    <span>{{ ucfirst($action) }}</span>
                                </label>
                            @endforeach
                        </div>
                    </details>
                @endforeach
            </div>
        </div>

        <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:22px; overflow:hidden;">
            <div style="padding:20px 22px; border-bottom:1px solid #e2e8f0;">
                <h2 style="margin:0; font-size:20px;">Sensitive Access</h2>
                <p style="margin:6px 0 0; color:#64748b; font-size:14px;">Grant private-document access only to trusted finance or admin staff.</p>
            </div>
            <div style="padding:22px; display:grid; gap:14px;">
                @foreach(($specialPermissions ?? []) as $permissionKey => $permissionLabel)
                    <label style="display:flex; align-items:flex-start; gap:12px; padding:14px 16px; border:1px solid #e2e8f0; border-radius:16px; background:#f8fafc;">
                        <input type="checkbox" name="permissions[__special][]" value="{{ $permissionKey }}"
                            @checked(in_array($permissionKey, $selectedPermissions['__special'] ?? [], true))>
                        <span>
                            <span style="display:block; font-weight:700; color:#0f172a;">{{ $permissionLabel }}</span>
                            <span style="display:block; margin-top:4px; color:#64748b; font-size:13px;">{{ $permissionKey }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
        </div>

        <div class="role-form-footer" style="display:flex; justify-content:flex-end; gap:12px;">
            <a href="{{ route('roles.index') }}" style="display:inline-flex; align-items:center; justify-content:center; padding:11px 16px; border-radius:12px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a; text-decoration:none; font-weight:600;">Cancel</a>
            <button type="submit" style="display:inline-flex; align-items:center; justify-content:center; padding:11px 18px; border:none; border-radius:12px; background:#1d4ed8; color:#ffffff; font-weight:700; cursor:pointer;">
                {{ $isEdit ? 'Update Role' : 'Save Role' }}
            </button>
        </div>
    </form>
</div>
