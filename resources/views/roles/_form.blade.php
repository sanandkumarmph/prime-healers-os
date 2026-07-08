@php
    $isEdit = $role->exists;
    $selectedPermissions = old('permissions', $role->permissions ?? []);
    $fieldError = fn (string $field) => $errors->first($field);
    $hasPermissionError = $errors->has('permissions') || collect($errors->keys())->contains(fn ($key) => str_starts_with($key, 'permissions.'));
    $actionLabels = ['read'=>'View','create'=>'Create','update'=>'Edit','delete'=>'Delete'];
    $actionHints = ['read'=>'Read','create'=>'Add','update'=>'Modify','delete'=>'Remove'];
    $moduleIcons = ['users'=>'US','roles'=>'RL','cities'=>'CT','warehouses'=>'WH','vendors'=>'VN','customers'=>'CU','products'=>'PR','assets'=>'AS','rentals'=>'RN','sales'=>'SL','invoices'=>'IN','payments'=>'PY','deliveries'=>'DL','reports'=>'RP','settings'=>'ST'];
    $totalModulePermissions = count($permissionModules) * count($permissionActions);
    $selectedModuleCount = collect($permissionModules)->keys()->sum(fn ($moduleKey) => count($selectedPermissions[$moduleKey] ?? []));
    $selectedSpecialCount = count($selectedPermissions['__special'] ?? []);
    $totalPermissionCount = $totalModulePermissions + count($specialPermissions ?? []);
@endphp

<style>
.role-builder-page{max-width:1500px;margin:0 auto;padding-bottom:92px;color:#111827}.rb-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:16px}.rb-crumb{display:flex;gap:8px;align-items:center;color:#64748b;font-size:12px;font-weight:800;margin-bottom:8px}.rb-title{margin:0;font-size:24px;line-height:1.15;letter-spacing:-.03em}.rb-sub{margin:6px 0 0;color:#64748b;font-size:14px}.rb-back{display:inline-flex;align-items:center;justify-content:center;height:40px;padding:0 14px;border-radius:12px;border:1px solid #cbd5e1;background:#fff;color:#0f172a;text-decoration:none;font-weight:800}.rb-panel{background:#fff;border:1px solid #dbe4f0;border-radius:18px;box-shadow:0 12px 30px rgba(15,23,42,.05);overflow:hidden}.rb-panel-head{display:flex;justify-content:space-between;gap:14px;align-items:center;padding:14px 16px;border-bottom:1px solid #e5edf6}.rb-panel-title{margin:0;font-size:16px;font-weight:900}.rb-panel-sub{margin:4px 0 0;color:#64748b;font-size:12px}.rb-form-grid{display:grid;grid-template-columns:minmax(180px,1fr) minmax(180px,1fr);gap:12px;padding:16px}.rb-field label,.rb-label{display:block;margin-bottom:6px;color:#475569;font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.04em}.rb-input{width:100%;height:40px;padding:0 12px;border:1px solid #cbd5e1;border-radius:12px;background:#fff;color:#111827;box-sizing:border-box}.rb-textarea{width:100%;min-height:74px;padding:10px 12px;border:1px solid #cbd5e1;border-radius:12px;resize:vertical;box-sizing:border-box}.rb-active{display:inline-flex;align-items:center;gap:9px;min-height:40px;padding:0 12px;border:1px solid #cbd5e1;border-radius:12px;background:#f8fafc;font-weight:800}.rb-error{margin-bottom:14px;padding:12px 14px;border-radius:14px;border:1px solid #fecaca;background:#fff1f2;color:#991b1b}.perm-shell{display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:14px}.perm-toolbar{display:grid;grid-template-columns:minmax(220px,1fr) auto;gap:10px;align-items:center;padding:12px 14px;border-bottom:1px solid #e5edf6}.perm-search{height:38px;border:1px solid #cbd5e1;border-radius:12px;padding:0 12px;font-size:13px}.perm-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;justify-content:flex-end}.perm-btn,.perm-select{height:36px;border:1px solid #d6e0ee;background:#fff;color:#334155;border-radius:11px;padding:0 11px;font-weight:900;font-size:12px;cursor:pointer}.perm-btn.primary{background:#4f46e5;color:#fff;border-color:#4f46e5}.perm-btn.warn{background:#fff7ed;color:#9a3412;border-color:#fed7aa}.matrix-wrap{overflow:auto;border-bottom:1px solid #e5edf6}.perm-matrix{min-width:680px;width:100%;border-collapse:separate;border-spacing:0}.perm-matrix th{position:sticky;top:0;z-index:3;background:#f8fafc;color:#475569;font-size:11px;text-transform:uppercase;letter-spacing:.04em;text-align:center;padding:9px 8px;border-bottom:1px solid #e5edf6}.perm-matrix th:first-child{left:0;z-index:4;text-align:left}.perm-matrix td{border-bottom:1px solid #edf2f7;padding:7px 8px;vertical-align:middle}.module-cell{position:sticky;left:0;z-index:2;background:#fff;min-width:230px}.module-info{display:flex;align-items:center;gap:10px}.module-icon{width:34px;height:34px;border-radius:11px;background:#eef2ff;color:#4338ca;display:inline-flex;align-items:center;justify-content:center;font-size:11px;font-weight:900;flex:0 0 auto}.module-name{font-size:13px;font-weight:900;color:#111827}.module-count{margin-top:2px;color:#64748b;font-size:11px;font-weight:700}.module-row:hover td,.module-row:hover .module-cell{background:#fbfdff}.perm-cell{text-align:center;cursor:pointer;min-width:82px}.perm-cell label{display:flex;align-items:center;justify-content:center;min-height:34px;border-radius:10px;cursor:pointer}.perm-cell:hover label{background:#eef2ff}.perm-cell input,.advanced-check input,.sensitive-check input{width:16px;height:16px;accent-color:#4f46e5}.details-row[hidden]{display:none}.details-row td{background:#fbfdff}.advanced-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.advanced-check{display:flex;gap:8px;align-items:flex-start;padding:9px 10px;border:1px solid #e5edf6;border-radius:11px;background:#fff;color:#334155;font-size:12px;font-weight:800}.module-expand{border:0;background:transparent;color:#334155;font-weight:900;cursor:pointer;padding:4px}.summary{position:sticky;top:12px;align-self:start;display:grid;gap:10px}.summary-card{border:1px solid #dbe4f0;border-radius:16px;background:#fff;padding:14px}.summary-title{margin:0 0 10px;font-size:15px;font-weight:900}.summary-donut{width:112px;height:112px;border-radius:999px;margin:0 auto 12px;background:conic-gradient(#22c55e var(--enabled,0%),#e2e8f0 0);display:grid;place-items:center}.summary-donut-inner{width:76px;height:76px;border-radius:999px;background:#fff;display:grid;place-items:center;text-align:center;font-weight:900}.summary-donut-inner span{display:block;font-size:22px}.summary-line{display:flex;align-items:center;justify-content:space-between;gap:10px;font-size:12px;color:#475569;padding:6px 0;border-bottom:1px solid #edf2f7}.summary-line:last-child{border-bottom:0}.module-progress{display:grid;gap:7px}.progress-row{display:grid;grid-template-columns:92px 1fr 46px;gap:8px;align-items:center;font-size:11px;color:#475569}.progress-track{height:7px;border-radius:999px;background:#edf2f7;overflow:hidden}.progress-fill{height:100%;background:#4f46e5;border-radius:999px;width:0}.template-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.template-btn{min-height:36px;border:1px solid #d6e0ee;border-radius:11px;background:#fff;color:#334155;font-weight:900;font-size:12px;cursor:pointer}.template-btn:hover{border-color:#a5b4fc;background:#eef2ff;color:#4338ca}
</style>
<style>
.sensitive{border:1px solid #fed7aa;background:#fff7ed;border-radius:16px;overflow:hidden}.sensitive-head{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:12px 14px;border-bottom:1px solid #fed7aa}.sensitive-title{margin:0;font-size:15px;font-weight:900;color:#9a3412}.sensitive-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;padding:12px}.sensitive-check{display:flex;gap:8px;align-items:flex-start;padding:9px 10px;border:1px solid #fed7aa;border-radius:11px;background:#fff;color:#334155;font-size:12px;font-weight:800}.sensitive-check input{margin-top:2px;accent-color:#f59e0b}.mobile-perms{display:none}.sticky-save{position:sticky;bottom:0;z-index:20;display:flex;justify-content:space-between;align-items:center;gap:12px;padding:12px 14px;border:1px solid #dbe4f0;border-radius:16px;background:rgba(255,255,255,.96);backdrop-filter:blur(12px);box-shadow:0 -12px 30px rgba(15,23,42,.08)}.save-state{display:flex;align-items:center;gap:8px;color:#64748b;font-size:13px;font-weight:800}.save-dot{width:9px;height:9px;border-radius:999px;background:#22c55e}.footer-actions{display:flex;gap:10px}.cancel-btn,.save-btn{display:inline-flex;align-items:center;justify-content:center;height:40px;padding:0 16px;border-radius:12px;font-weight:900;text-decoration:none}.cancel-btn{border:1px solid #cbd5e1;background:#fff;color:#0f172a}.save-btn{border:0;background:#4f46e5;color:#fff;cursor:pointer}.search-hit .module-cell,.search-hit td{background:#ffffbf!important}.pill{display:inline-flex;align-items:center;height:24px;padding:0 8px;border-radius:999px;background:#eef2ff;color:#4338ca;font-size:11px;font-weight:900}@media(max-width:1180px){.perm-shell{grid-template-columns:1fr}.summary{position:static;grid-template-columns:repeat(2,minmax(0,1fr))}.summary-card:first-child{grid-row:span 2}}@media(max-width:767px){.role-builder-page{padding-bottom:120px}.rb-head{flex-direction:column}.rb-title{font-size:24px}.rb-form-grid{grid-template-columns:1fr;padding:14px}.perm-shell{display:block}.role-permission-desktop{display:none!important}.summary{grid-template-columns:1fr;margin-top:12px}.mobile-perms{display:grid!important;gap:10px;padding:12px}.mobile-module{border:1px solid #dbe4f0;border-radius:14px;background:#fff;overflow:hidden}.mobile-module summary{display:flex;justify-content:space-between;align-items:center;gap:10px;min-height:48px;padding:0 12px;list-style:none;font-weight:900}.mobile-module summary::-webkit-details-marker{display:none}.mobile-body{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;padding:10px 12px;border-top:1px solid #edf2f7}.mobile-check{display:flex;align-items:center;gap:8px;min-height:38px;padding:0 10px;border:1px solid #e5edf6;border-radius:10px;background:#f8fafc;font-size:12px;font-weight:900}.sensitive-grid{grid-template-columns:1fr}.sticky-save{left:8px;right:8px;bottom:78px;position:fixed}.footer-actions{flex:1}.cancel-btn,.save-btn{flex:1}.perm-toolbar{grid-template-columns:1fr}.perm-actions{justify-content:flex-start;overflow-x:auto;flex-wrap:nowrap;padding-bottom:2px}.perm-btn,.perm-select{flex:0 0 auto}.advanced-grid{grid-template-columns:1fr}}
</style>
<style>
/* Role builder polish: compact mobile controls and footer button sizing. */
.role-builder-page .pill{min-width:max-content;white-space:nowrap;text-transform:none;line-height:1;padding:0 10px;max-width:none;}
.role-builder-page .mobile-check{position:relative;display:grid;grid-template-columns:22px 1fr;align-items:center;background:#fff;border-color:#dbe4f0;min-height:38px;padding:0 10px;gap:9px;}
.role-builder-page .mobile-check input,
.role-builder-page .advanced-check input,
.role-builder-page .sensitive-check input{appearance:none;-webkit-appearance:none;inline-size:18px!important;block-size:18px!important;width:18px!important;height:18px!important;min-width:18px!important;max-width:18px!important;min-height:18px!important;max-height:18px!important;flex:0 0 18px!important;padding:0!important;box-sizing:border-box;border:2px solid #cbd5e1;border-radius:50%!important;background:#fff;margin:0;display:inline-grid;place-content:center;}
.role-builder-page .mobile-check input:checked,
.role-builder-page .advanced-check input:checked,
.role-builder-page .sensitive-check input:checked{border-color:#4f46e5;background:#4f46e5;box-shadow:inset 0 0 0 4px #fff;}
.role-builder-page .mobile-check span{min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.role-builder-page .sticky-save{box-sizing:border-box;}
.role-builder-page .cancel-btn,.role-builder-page .save-btn{min-width:118px;white-space:nowrap;text-align:center;line-height:1.1;}
@media(max-width:767px){.role-builder-page .sticky-save{display:grid;grid-template-columns:minmax(0,1fr);gap:8px;padding:10px;}.role-builder-page .save-state{font-size:12px;min-width:0;}.role-builder-page .footer-actions{display:grid;grid-template-columns:1fr 1fr;width:100%;gap:8px;}.role-builder-page .cancel-btn,.role-builder-page .save-btn{width:100%;min-width:0;padding:0 10px;height:44px;}}
</style>
<div class="role-builder-page" data-permission-builder data-total-permissions="{{ $totalPermissionCount }}">
    <div class="rb-head">
        <div>
            <div class="rb-crumb"><span>Home</span><span>/</span><span>Company Settings</span><span>/</span><span>Roles</span><span>/</span><strong>{{ $isEdit ? 'Edit Role' : 'Add Role' }}</strong></div>
            <h1 class="rb-title">{{ $isEdit ? 'Edit Role Permission' : 'Add Role Permission' }}</h1>
            <p class="rb-sub">Define what this role can access and which actions are allowed.</p>
        </div>
        <a class="rb-back" href="{{ route('roles.index') }}">Back to Roles</a>
    </div>

    @if ($errors->any())
        <div class="rb-error"><strong>Please fix the following:</strong><ul style="margin:8px 0 0 18px;">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    <form method="POST" action="{{ $isEdit ? route('roles.update', $role) : route('roles.store') }}" style="display:grid;gap:14px;" data-role-form>
        @csrf
        @if($isEdit) @method('PUT') @endif

        <section class="rb-panel">
            <div class="rb-panel-head">
                <div><h2 class="rb-panel-title">Role Definition</h2><p class="rb-panel-sub">Keep names reusable and permission intent clear.</p></div>
                <span class="pill">{{ $isEdit ? ($role->is_system ? 'System' : 'Custom') : 'Custom' }}</span>
            </div>
            <div class="rb-form-grid">
                <div class="rb-field"><label>Role Name</label><input class="rb-input" type="text" name="name" value="{{ old('name', $role->name) }}" required style="{{ $errors->has('name') ? 'border-color:#dc2626;background:#fff7f7;' : '' }}">@if($fieldError('name'))<div style="margin-top:6px;color:#b91c1c;font-size:12px;">{{ $fieldError('name') }}</div>@endif</div>
                <div class="rb-field"><label>Slug</label><input class="rb-input" type="text" name="slug" value="{{ old('slug', $role->slug) }}" style="{{ $errors->has('slug') ? 'border-color:#dc2626;background:#fff7f7;' : '' }}">@if($fieldError('slug'))<div style="margin-top:6px;color:#b91c1c;font-size:12px;">{{ $fieldError('slug') }}</div>@endif</div>
                <div class="rb-field" style="grid-column:1/-1;"><label>Description</label><textarea class="rb-textarea" name="description" style="{{ $errors->has('description') ? 'border-color:#dc2626;background:#fff7f7;' : '' }}">{{ old('description', $role->description) }}</textarea>@if($fieldError('description'))<div style="margin-top:6px;color:#b91c1c;font-size:12px;">{{ $fieldError('description') }}</div>@endif</div>
                <div style="grid-column:1/-1;"><label class="rb-active"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $role->is_active ?? true))><span>Active role</span></label></div>
            </div>
        </section>

        <div class="perm-shell">
            <div style="display:grid;gap:14px;min-width:0;">
                <section class="rb-panel role-permission-desktop" style="{{ $hasPermissionError ? 'border-color:#fecaca;background:#fff7f7;' : '' }}">
                    <div class="perm-toolbar">
                        <input class="perm-search" type="search" placeholder="Search permissions, modules, stock, invoice..." data-permission-search aria-label="Search permissions">
                        <div class="perm-actions">
                            <button type="button" class="perm-btn primary" data-permission-action="select-all">Select All</button>
                            <button type="button" class="perm-btn" data-permission-action="clear-all">Clear All</button>
                            <button type="button" class="perm-btn" data-permission-action="view-only">View Only</button>
                            <select class="perm-select" data-template-select aria-label="Apply permission template"><option value="">Apply Template</option><option value="read">Read Only</option><option value="operations">Operations</option><option value="finance">Finance</option><option value="warehouse">Warehouse</option><option value="admin">Admin</option><option value="custom">Custom</option></select>
                            <button type="button" class="perm-btn" data-permission-action="collapse-all">Collapse All</button>
                        </div>
                    </div>
                    @if($hasPermissionError)<div style="padding:8px 14px;color:#b91c1c;font-size:12px;font-weight:800;">{{ $fieldError('permissions') ?: 'Review the permission selection below.' }}</div>@endif
                    <div class="matrix-wrap">
                        <table class="perm-matrix" data-permission-matrix>
                            <thead><tr><th>Modules ({{ count($permissionModules) }})</th>@foreach($permissionActions as $action)<th>{{ $actionLabels[$action] ?? ucfirst($action) }}<br><small style="font-weight:700;text-transform:none;letter-spacing:0;color:#64748b;">{{ $actionHints[$action] ?? '' }}</small></th>@endforeach<th>Expand</th></tr></thead>
                            <tbody>
                                @foreach($permissionModules as $moduleKey => $moduleLabel)
                                    @php($moduleActions = $selectedPermissions[$moduleKey] ?? [])
                                    <tr class="module-row" data-module-row data-module="{{ $moduleKey }}" data-search-text="{{ strtolower($moduleLabel . ' ' . $moduleKey . ' ' . implode(' ', $permissionActions)) }}">
                                        <td class="module-cell"><div class="module-info"><span class="module-icon">{{ $moduleIcons[$moduleKey] ?? strtoupper(substr($moduleLabel, 0, 2)) }}</span><div><div class="module-name">{{ $moduleLabel }}</div><div class="module-count"><span data-module-selected>{{ count($moduleActions) }}</span>/{{ count($permissionActions) }} permissions</div></div></div></td>
                                        @foreach($permissionActions as $action)
                                            <td class="perm-cell" data-permission-cell><label title="{{ $actionLabels[$action] ?? ucfirst($action) }} {{ $moduleLabel }}"><input type="checkbox" name="permissions[{{ $moduleKey }}][]" value="{{ $action }}" data-permission-checkbox data-module="{{ $moduleKey }}" data-action="{{ $action }}" @checked(in_array($action, $moduleActions, true))></label></td>
                                        @endforeach
                                        <td style="text-align:center;"><button type="button" class="module-expand" data-toggle-module-details aria-expanded="false">+</button></td>
                                    </tr>
                                    <tr class="details-row" data-module-details="{{ $moduleKey }}" hidden><td></td><td colspan="{{ count($permissionActions) + 1 }}"><div class="advanced-grid">@foreach($permissionActions as $action)<label class="advanced-check"><input type="checkbox" data-mirror-checkbox data-module="{{ $moduleKey }}" data-action="{{ $action }}" @checked(in_array($action, $moduleActions, true))><span>{{ $actionLabels[$action] ?? ucfirst($action) }} {{ $moduleLabel }}</span></label>@endforeach</div></td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="mobile-perms" aria-label="Mobile permission builder">
                    <input class="perm-search" type="search" placeholder="Search permissions..." data-permission-search-mobile aria-label="Search permissions">
                    @foreach($permissionModules as $moduleKey => $moduleLabel)
                        @php($moduleActions = $selectedPermissions[$moduleKey] ?? [])
                        <details class="mobile-module" data-mobile-module data-module="{{ $moduleKey }}" {{ !empty($moduleActions) ? 'open' : '' }}>
                            <summary><span>{{ $moduleLabel }}</span><span><span data-mobile-module-selected>{{ count($moduleActions) }}</span>/{{ count($permissionActions) }}</span></summary>
                            <div class="mobile-body">@foreach($permissionActions as $action)<label class="mobile-check"><input type="checkbox" data-mobile-mirror-checkbox data-module="{{ $moduleKey }}" data-action="{{ $action }}" @checked(in_array($action, $moduleActions, true))><span>{{ $actionLabels[$action] ?? ucfirst($action) }}</span></label>@endforeach</div>
                        </details>
                    @endforeach
                </section>

                <section class="sensitive">
                    <div class="sensitive-head"><div><h2 class="sensitive-title">Sensitive Access</h2><p style="margin:4px 0 0;color:#9a3412;font-size:12px;">Private exports, finance data, vendor costs, and system-level access.</p></div><button type="button" class="perm-btn warn" data-permission-action="select-sensitive">Select All</button></div>
                    <div class="sensitive-grid">
                        @foreach(($specialPermissions ?? []) as $permissionKey => $permissionLabel)
                            <label class="sensitive-check"><input type="checkbox" name="permissions[__special][]" value="{{ $permissionKey }}" data-special-permission @checked(in_array($permissionKey, $selectedPermissions['__special'] ?? [], true))><span>{{ $permissionLabel }}</span></label>
                        @endforeach
                    </div>
                </section>
            </div>

            <aside class="summary" aria-label="Permission summary">
                <section class="summary-card"><h2 class="summary-title">Permission Summary</h2><div class="summary-donut" data-summary-donut style="--enabled:0%;"><div class="summary-donut-inner"><div><span data-enabled-count>{{ $selectedModuleCount + $selectedSpecialCount }}</span><small>Total enabled</small></div></div></div><div class="summary-line"><span>Enabled</span><strong data-enabled-text>{{ $selectedModuleCount + $selectedSpecialCount }}</strong></div><div class="summary-line"><span>Disabled</span><strong data-disabled-text>{{ $totalPermissionCount - ($selectedModuleCount + $selectedSpecialCount) }}</strong></div><div class="summary-line"><span>Sensitive</span><strong data-sensitive-count>{{ $selectedSpecialCount }}</strong></div></section>
                <section class="summary-card"><h2 class="summary-title">Module Coverage</h2><div class="module-progress">@foreach($permissionModules as $moduleKey => $moduleLabel)<div class="progress-row" data-module-progress="{{ $moduleKey }}"><span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $moduleLabel }}</span><span class="progress-track"><span class="progress-fill" data-progress-fill></span></span><strong data-progress-text>{{ count($selectedPermissions[$moduleKey] ?? []) }}/{{ count($permissionActions) }}</strong></div>@endforeach</div></section>
                <section class="summary-card"><h2 class="summary-title">Quick Templates</h2><div class="template-grid"><button type="button" class="template-btn" data-template-button="read">Read Only</button><button type="button" class="template-btn" data-template-button="operations">Operations</button><button type="button" class="template-btn" data-template-button="finance">Finance</button><button type="button" class="template-btn" data-template-button="warehouse">Warehouse</button><button type="button" class="template-btn" data-template-button="admin">Admin</button><button type="button" class="template-btn" data-template-button="custom">Custom</button></div></section>
            </aside>
        </div>

        <div class="sticky-save"><div class="save-state"><span class="save-dot" data-save-dot></span><span data-save-state>All changes saved</span></div><div class="footer-actions"><a class="cancel-btn" href="{{ route('roles.index') }}">Cancel</a><button class="save-btn" type="submit">Save Role</button></div></div>
    </form>
</div>
<script>
(function(){
    const root = document.querySelector('[data-permission-builder]');
    if (!root) return;
    const form = root.querySelector('[data-role-form]');
    const totalPermissions = Number(root.dataset.totalPermissions || 0);
    const checkboxes = () => Array.from(root.querySelectorAll('[data-permission-checkbox]'));
    const specialBoxes = () => Array.from(root.querySelectorAll('[data-special-permission]'));
    const moduleRows = () => Array.from(root.querySelectorAll('[data-module-row]'));
    const dirtyText = root.querySelector('[data-save-state]');
    const dirtyDot = root.querySelector('[data-save-dot]');
    let dirty = false;
    const templates = {
        read: { all: ['read'] },
        operations: { modules: { customers:['read','create','update'], products:['read'], assets:['read','update'], rentals:['read','create','update'], deliveries:['read','update'], reports:['read'] } },
        finance: { modules: { customers:['read'], rentals:['read'], sales:['read'], invoices:['read','create','update'], payments:['read','create','update'], reports:['read'], settings:['read'] } },
        warehouse: { modules: { products:['read'], assets:['read','create','update'], warehouses:['read','update'], deliveries:['read','update'], reports:['read'] } },
        admin: { all: ['read','create','update','delete'] },
        custom: null
    };
    function markDirty(){ dirty = true; if (dirtyText) dirtyText.textContent = 'Unsaved changes'; if (dirtyDot) dirtyDot.style.background = '#f59e0b'; }
    function syncMirrors(module, action, checked){ root.querySelectorAll(`[data-mirror-checkbox][data-module="${module}"][data-action="${action}"], [data-mobile-mirror-checkbox][data-module="${module}"][data-action="${action}"]`).forEach(mirror => mirror.checked = checked); }
    function primaryBox(module, action){ return root.querySelector(`[data-permission-checkbox][data-module="${module}"][data-action="${action}"]`); }
    function setBox(box, checked){ box.checked = checked; syncMirrors(box.dataset.module, box.dataset.action, checked); }
    function updateSummary(){
        const enabled = checkboxes().filter(box => box.checked).length + specialBoxes().filter(box => box.checked).length;
        const percent = totalPermissions ? Math.round((enabled / totalPermissions) * 100) : 0;
        root.querySelector('[data-summary-donut]')?.style.setProperty('--enabled', percent + '%');
        root.querySelectorAll('[data-enabled-count], [data-enabled-text]').forEach(item => item.textContent = enabled);
        root.querySelectorAll('[data-disabled-text]').forEach(item => item.textContent = Math.max(0, totalPermissions - enabled));
        root.querySelectorAll('[data-sensitive-count]').forEach(item => item.textContent = specialBoxes().filter(box => box.checked).length);
        moduleRows().forEach(row => {
            const module = row.dataset.module;
            const selected = checkboxes().filter(box => box.dataset.module === module && box.checked).length;
            const total = checkboxes().filter(box => box.dataset.module === module).length;
            row.querySelectorAll('[data-module-selected]').forEach(item => item.textContent = selected);
            root.querySelectorAll(`[data-module-progress="${module}"]`).forEach(progress => {
                progress.querySelector('[data-progress-fill]')?.style.setProperty('width', (total ? Math.round((selected / total) * 100) : 0) + '%');
                const text = progress.querySelector('[data-progress-text]'); if (text) text.textContent = `${selected}/${total}`;
            });
            root.querySelectorAll(`[data-mobile-module][data-module="${module}"] [data-mobile-module-selected]`).forEach(item => item.textContent = selected);
        });
    }
    function applyTemplate(name){
        const template = templates[name]; if (!template) return;
        checkboxes().forEach(box => setBox(box, false)); specialBoxes().forEach(box => box.checked = false);
        if (template.all) checkboxes().forEach(box => setBox(box, template.all.includes(box.dataset.action)));
        if (template.modules) Object.entries(template.modules).forEach(([module, actions]) => actions.forEach(action => { const box = primaryBox(module, action); if (box) setBox(box, true); }));
        markDirty(); updateSummary();
    }
    root.addEventListener('change', event => {
        const box = event.target.closest('[data-permission-checkbox]');
        const mirror = event.target.closest('[data-mirror-checkbox], [data-mobile-mirror-checkbox]');
        if (box) syncMirrors(box.dataset.module, box.dataset.action, box.checked);
        if (mirror) { const primary = primaryBox(mirror.dataset.module, mirror.dataset.action); if (primary) primary.checked = mirror.checked; syncMirrors(mirror.dataset.module, mirror.dataset.action, mirror.checked); }
        const select = event.target.closest('[data-template-select]');
        if (select && select.value) { applyTemplate(select.value); select.value = ''; return; }
        if (event.target.matches('input, textarea, select')) markDirty();
        updateSummary();
    });
    root.addEventListener('click', event => {
        const action = event.target.closest('[data-permission-action]')?.dataset.permissionAction;
        if (action === 'select-all') checkboxes().forEach(box => setBox(box, true));
        if (action === 'clear-all') { checkboxes().forEach(box => setBox(box, false)); specialBoxes().forEach(box => box.checked = false); }
        if (action === 'view-only') checkboxes().forEach(box => setBox(box, box.dataset.action === 'read'));
        if (action === 'collapse-all') root.querySelectorAll('[data-module-details]').forEach(row => row.hidden = true);
        if (action === 'select-sensitive') specialBoxes().forEach(box => box.checked = true);
        if (action) { markDirty(); updateSummary(); }
        const templateButton = event.target.closest('[data-template-button]'); if (templateButton) applyTemplate(templateButton.dataset.templateButton);
        const expander = event.target.closest('[data-toggle-module-details]');
        if (expander) { const row = expander.closest('[data-module-row]'); const details = root.querySelector(`[data-module-details="${row?.dataset.module}"]`); if (details) { details.hidden = !details.hidden; expander.setAttribute('aria-expanded', String(!details.hidden)); expander.textContent = details.hidden ? '+' : '-'; } }
    });
    function applySearch(value){
        const query = value.trim().toLowerCase();
        moduleRows().forEach(row => { const hit = !query || (row.dataset.searchText || '').includes(query); row.hidden = !hit; row.classList.toggle('search-hit', Boolean(query && hit)); const details = root.querySelector(`[data-module-details="${row.dataset.module}"]`); if (details) details.hidden = true; });
        root.querySelectorAll('[data-mobile-module]').forEach(module => { const text = module.textContent.toLowerCase() + ' ' + (module.dataset.module || ''); module.hidden = Boolean(query && !text.includes(query)); });
    }
    root.querySelector('[data-permission-search]')?.addEventListener('input', event => applySearch(event.target.value));
    root.querySelector('[data-permission-search-mobile]')?.addEventListener('input', event => applySearch(event.target.value));
    form?.addEventListener('submit', event => { if (specialBoxes().some(box => box.checked) && !window.confirm('Sensitive permissions are enabled for this role. Continue saving?')) event.preventDefault(); });
    window.addEventListener('beforeunload', event => { if (!dirty) return; event.preventDefault(); event.returnValue = ''; });
    updateSummary();
})();
</script>
