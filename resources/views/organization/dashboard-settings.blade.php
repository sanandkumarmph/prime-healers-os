@extends('layouts.app')

@section('content')
@php
    $sectionMeta = [
        'executive' => ['label' => 'Executive', 'full' => 'Executive Summary', 'icon' => 'EX', 'accent' => '#4f46e5'],
        'operations' => ['label' => 'Operations', 'full' => 'Operations Center', 'icon' => 'OP', 'accent' => '#0ea5e9'],
        'finance' => ['label' => 'Finance', 'full' => 'Finance Center', 'icon' => 'FN', 'accent' => '#f97316'],
        'inventory' => ['label' => 'Inventory', 'full' => 'Inventory Center', 'icon' => 'IN', 'accent' => '#14b8a6'],
        'communication' => ['label' => 'Communication', 'full' => 'Communication Center', 'icon' => 'CM', 'accent' => '#8b5cf6'],
        'activity' => ['label' => 'Activity', 'full' => 'Activity Center', 'icon' => 'AC', 'accent' => '#64748b'],
    ];

    $sectionFor = function (array $widget): string {
        $text = strtolower(($widget['widget_key'] ?? '').' '.($widget['category'] ?? '').' '.($widget['name'] ?? '').' '.($widget['description'] ?? ''));

        if (str_contains($text, 'revenue') || str_contains($text, 'cash') || str_contains($text, 'collection') || str_contains($text, 'invoice') || str_contains($text, 'finance') || str_contains($text, 'payment')) {
            return 'finance';
        }

        if (str_contains($text, 'inventory') || str_contains($text, 'stock') || str_contains($text, 'asset') || str_contains($text, 'warehouse') || str_contains($text, 'product')) {
            return 'inventory';
        }

        if (str_contains($text, 'communication') || str_contains($text, 'message') || str_contains($text, 'alert') || str_contains($text, 'follow') || str_contains($text, 'notification')) {
            return 'communication';
        }

        if (str_contains($text, 'activity') || str_contains($text, 'timeline') || str_contains($text, 'recent')) {
            return 'activity';
        }

        if (str_contains($text, 'delivery') || str_contains($text, 'pickup') || str_contains($text, 'operation') || str_contains($text, 'task') || str_contains($text, 'renewal') || str_contains($text, 'rental')) {
            return 'operations';
        }

        return 'executive';
    };

    $allWidgets = collect($widgetsByCategory)->flatten(1)->sortBy('sort_order')->values();
    $sections = collect($sectionMeta)->map(function ($meta, $key) use ($allWidgets, $sectionFor) {
        $widgets = $allWidgets->filter(fn ($widget) => $sectionFor($widget) === $key)->values();

        return array_merge($meta, [
            'key' => $key,
            'widgets' => $widgets,
            'count' => $widgets->count(),
            'enabled_count' => $widgets->where('enabled', true)->count(),
        ]);
    })->values();
    $selectedSection = $sections->firstWhere('count', '>', 0)['key'] ?? 'executive';
@endphp

<div class="dc-page" data-dashboard-config>
    <style>
        .dc-page{--bg:#f6f8fb;--card:#fff;--line:#dbe4f0;--text:#0f172a;--muted:#64748b;--primary:#4f46e5;margin:-8px -8px 0;padding:14px;background:var(--bg);min-height:calc(100vh - 88px);color:var(--text)}
        .dc-wrap{max-width:1280px;margin:0 auto;display:grid;gap:12px}.dc-card{background:var(--card);border:1px solid var(--line);border-radius:14px;box-shadow:0 8px 18px rgba(15,23,42,.04)}
        .dc-header{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 14px}.dc-title{font-size:24px;line-height:1.1;font-weight:820;margin:0}.dc-sub{font-size:12px;color:var(--muted);margin:4px 0 0}.dc-actions{display:flex;align-items:end;justify-content:flex-end;gap:8px;flex-wrap:wrap}.dc-field{display:grid;gap:4px}.dc-label{font-size:10.5px;line-height:1;font-weight:850;letter-spacing:.07em;text-transform:uppercase;color:var(--muted)}
        .dc-input,.dc-select{height:36px;border:1px solid #cbd7e6;border-radius:10px;background:#fff;color:var(--text);font-size:13px;padding:0 10px;outline:none}.dc-input:focus,.dc-select:focus{border-color:#818cf8;box-shadow:0 0 0 3px rgba(79,70,229,.10)}.dc-select{min-width:170px}.dc-btn{height:36px;border:1px solid #cbd7e6;border-radius:10px;background:#fff;color:#263449;font-weight:800;font-size:13px;padding:0 12px;display:inline-flex;align-items:center;justify-content:center;gap:7px;cursor:pointer;text-decoration:none;white-space:nowrap}.dc-btn:hover{border-color:#aebbd0}.dc-primary{border-color:var(--primary);background:var(--primary);color:#fff;box-shadow:0 10px 18px rgba(79,70,229,.18)}.dc-muted-btn{background:#f8fafc}.dc-status{height:28px;border-radius:999px;padding:0 10px;display:inline-flex;align-items:center;font-size:12px;font-weight:800;background:#ecfdf5;color:#047857;border:1px solid #bbf7d0}.dc-status.dirty{background:#eef2ff;color:#3730a3;border-color:#c7d2fe}.dc-alert{border:1px solid #bbf7d0;background:#f0fdf4;color:#166534;border-radius:12px;padding:10px 12px;font-weight:750;font-size:13px}.dc-errors{border:1px solid #fecaca;background:#fff1f2;color:#b91c1c;border-radius:12px;padding:10px 12px;font-weight:750;font-size:13px}.dc-errors ul{margin:6px 0 0;padding-left:18px}
        .dc-tools{display:grid;grid-template-columns:minmax(220px,1fr) 190px auto;gap:8px;padding:12px 14px}.dc-builder{display:grid;grid-template-columns:230px minmax(0,1fr);gap:12px;align-items:start}.dc-panel-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 12px;border-bottom:1px solid #e7edf5}.dc-panel-title{font-size:16px;line-height:1.2;font-weight:820;margin:0}.dc-panel-copy{font-size:11.5px;color:var(--muted);margin:2px 0 0}.dc-pill{height:22px;border-radius:999px;background:#eef2ff;color:#4338ca;display:inline-flex;align-items:center;font-size:11px;font-weight:850;padding:0 8px;white-space:nowrap}
        .dc-section-list,.dc-widget-list{display:grid;gap:8px;padding:10px}.dc-widget-list[hidden]{display:none!important}.dc-section{display:grid;grid-template-columns:32px minmax(0,1fr) auto;align-items:center;gap:9px;border:1px solid #e2e8f0;background:#fff;border-radius:11px;padding:8px;text-align:left;cursor:pointer;color:var(--text)}.dc-section:hover{border-color:#cbd5e1}.dc-section.active{background:#f1f5ff;border-color:#c7d2fe;box-shadow:inset 3px 0 0 var(--accent)}.dc-ico{width:32px;height:32px;border-radius:9px;display:grid;place-items:center;background:#eef2ff;color:#4338ca;font-weight:900;font-size:11px;flex:0 0 auto}.dc-section-name{font-size:13px;font-weight:820;line-height:1.1}.dc-small{font-size:11px;color:var(--muted)}
        .dc-widget{min-height:52px;display:grid;grid-template-columns:34px minmax(0,1fr) auto auto;gap:10px;align-items:center;border:1px solid #e2e8f0;border-radius:12px;padding:8px 10px;background:#fff}.dc-widget:hover{border-color:#cbd5e1;box-shadow:0 7px 14px rgba(15,23,42,.04)}.dc-widget.hidden-widget{background:#f8fafc;opacity:.72}.dc-widget-title{font-size:14px;font-weight:780;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.dc-widget-details{margin-top:4px}.dc-widget-details summary{width:max-content;list-style:none;font-size:11px;color:#4f46e5;font-weight:750;cursor:pointer}.dc-widget-details summary::-webkit-details-marker{display:none}.dc-widget-details p{font-size:11.5px;color:#64748b;margin:5px 0 0;line-height:1.35}.dc-state{height:22px;border-radius:999px;background:#dcfce7;color:#166534;padding:0 8px;display:inline-flex;align-items:center;font-size:10.5px;font-weight:850;text-transform:uppercase}.hidden-widget .dc-state{background:#e2e8f0;color:#475569}.dc-switch{position:relative;width:42px;height:24px}.dc-switch input{position:absolute;opacity:0}.dc-switch span{display:block;width:42px;height:24px;border-radius:999px;background:#cbd5e1}.dc-switch span:before{content:'';position:absolute;left:3px;top:3px;width:18px;height:18px;border-radius:999px;background:#fff;transition:.16s}.dc-switch input:checked+span{background:var(--primary)}.dc-switch input:checked+span:before{transform:translateX(18px)}.dc-empty{padding:22px;text-align:center;color:#64748b;font-size:13px}.dc-mobile-save{display:none}
        @media(max-width:760px){.dc-page{margin:-10px -12px 0;padding:10px 10px 92px}.dc-header,.dc-actions,.dc-tools,.dc-builder{display:grid;grid-template-columns:1fr}.dc-title{font-size:22px}.dc-select,.dc-btn,.dc-actions .dc-field{width:100%}.dc-status{justify-content:center}.dc-tools{padding:10px}.dc-section-list{display:flex;overflow-x:auto;gap:8px}.dc-section{min-width:176px}.dc-widget-list{padding:8px}.dc-widget{grid-template-columns:32px minmax(0,1fr) auto;min-height:50px}.dc-widget .dc-state{display:none}.dc-switch{grid-column:3}.dc-widget-details{display:none}.dc-mobile-save{position:fixed;left:10px;right:10px;bottom:76px;z-index:35;display:flex;gap:8px;background:#fff;border:1px solid var(--line);border-radius:14px;padding:8px;box-shadow:0 12px 28px rgba(15,23,42,.15)}.dc-mobile-save .dc-btn{flex:1}}
    </style>

    <div class="dc-wrap">
        @if(session('success'))
            <div class="dc-alert">{{ session('success') }}</div>
        @endif

        @if($errors->any())
            <div class="dc-errors">
                Dashboard settings could not be saved.
                <ul>
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <header class="dc-card dc-header">
            <div>
                <h1 class="dc-title">Dashboard Configuration</h1>
                <p class="dc-sub">Choose a role, select a section, and control which widgets appear on the dashboard.</p>
            </div>
            <div class="dc-actions">
                <form method="GET" action="{{ route('organization.dashboard-settings.edit') }}" id="dashboardRoleForm" class="dc-field">
                    <label class="dc-label" for="dashboardRoleSelect">Role</label>
                    <select name="role_id" id="dashboardRoleSelect" class="dc-select" data-role-select data-current-role="{{ $selectedRole->id }}">
                        @foreach($roles as $role)
                            <option value="{{ $role->id }}" @selected((int) $selectedRole->id === (int) $role->id)>{{ $role->name }}</option>
                        @endforeach
                    </select>
                </form>
                <span class="dc-status" data-save-state>All changes saved</span>
                <button type="button" class="dc-btn dc-muted-btn" data-reset-changes>Reset Changes</button>
                <button type="submit" form="dashboardConfigForm" class="dc-btn dc-primary">Save Changes</button>
            </div>
        </header>

        <form method="POST" action="{{ route('organization.dashboard-settings.update') }}" id="dashboardConfigForm">
            @csrf
            @method('PUT')
            <input type="hidden" name="role_id" value="{{ $selectedRole->id }}">

            <section class="dc-card dc-tools" aria-label="Dashboard widget tools">
                <input type="search" class="dc-input" placeholder="Search widgets..." data-search-widgets>
                <select class="dc-select" data-bulk-action aria-label="Bulk actions">
                    <option value="">Bulk Actions</option>
                    <option value="enable">Enable all in this section</option>
                    <option value="disable">Disable all in this section</option>
                    <option value="reset">Reset this section</option>
                </select>
                <span class="dc-pill" data-current-progress>{{ $sections->firstWhere('key', $selectedSection)['enabled_count'] ?? 0 }} / {{ $sections->firstWhere('key', $selectedSection)['count'] ?? 0 }} visible</span>
            </section>

            <section class="dc-builder">
                <aside class="dc-card">
                    <div class="dc-panel-head">
                        <div>
                            <h2 class="dc-panel-title">Sections</h2>
                            <p class="dc-panel-copy">Select one area to edit.</p>
                        </div>
                        <span class="dc-pill">{{ $allWidgets->count() }}</span>
                    </div>
                    <div class="dc-section-list" role="list">
                        @foreach($sections as $section)
                            <button type="button" class="dc-section @if($section['key'] === $selectedSection) active @endif" data-section-target="{{ $section['key'] }}" style="--accent:{{ $section['accent'] }}" aria-pressed="{{ $section['key'] === $selectedSection ? 'true' : 'false' }}">
                                <span class="dc-ico" style="background:{{ $section['accent'] }}18;color:{{ $section['accent'] }}">{{ $section['icon'] }}</span>
                                <span>
                                    <span class="dc-section-name">{{ $section['label'] }}</span><br>
                                    <span class="dc-small"><span data-section-count="{{ $section['key'] }}">{{ $section['enabled_count'] }}</span> / {{ $section['count'] }} visible</span>
                                </span>
                                <span class="dc-pill">{{ $section['count'] }}</span>
                            </button>
                        @endforeach
                    </div>
                </aside>

                <main class="dc-card">
                    <div class="dc-panel-head">
                        <div>
                            <h2 class="dc-panel-title" data-current-section>{{ $sections->firstWhere('key', $selectedSection)['full'] ?? 'Widgets' }}</h2>
                            <p class="dc-panel-copy">Toggle visibility for {{ $selectedRole->name }}. Existing widget order is preserved.</p>
                        </div>
                    </div>

                    @foreach($sections as $section)
                        <div class="dc-widget-list" data-section-panel="{{ $section['key'] }}" @if($section['key'] !== $selectedSection) hidden @endif>
                            @forelse($section['widgets'] as $widget)
                                @php
                                    $widgetKey = $widget['widget_key'];
                                    $enabled = (bool) $widget['enabled'];
                                    $description = $widget['description'] ?: 'Dashboard widget';
                                @endphp
                                <article class="dc-widget @unless($enabled) hidden-widget @endunless" data-widget data-section="{{ $section['key'] }}" data-enabled="{{ $enabled ? '1' : '0' }}" data-original-enabled="{{ $enabled ? '1' : '0' }}" data-search="{{ strtolower($widget['name'].' '.$description.' '.$widget['category'].' '.$widget['sensitivity']) }}">
                                    <span class="dc-ico" style="background:{{ $section['accent'] }}18;color:{{ $section['accent'] }}">{{ strtoupper(substr($widget['name'], 0, 1)) }}</span>
                                    <span style="min-width:0;">
                                        <span class="dc-widget-title">{{ $widget['name'] }}</span>
                                        <details class="dc-widget-details">
                                            <summary>Details</summary>
                                            <p>{{ $description }}</p>
                                        </details>
                                    </span>
                                    <span class="dc-state" data-state-label>{{ $enabled ? 'Visible' : 'Hidden' }}</span>
                                    <label class="dc-switch" title="Toggle {{ $widget['name'] }} visibility">
                                        <input type="hidden" name="widgets[{{ $widgetKey }}][is_enabled]" value="0">
                                        <input type="checkbox" name="widgets[{{ $widgetKey }}][is_enabled]" value="1" @checked($enabled) data-widget-toggle aria-label="Show {{ $widget['name'] }}">
                                        <span></span>
                                    </label>
                                    <input type="hidden" name="widgets[{{ $widgetKey }}][sort_order]" value="{{ $widget['sort_order'] }}">
                                </article>
                            @empty
                                <div class="dc-empty">No widgets are registered in this section.</div>
                            @endforelse
                        </div>
                    @endforeach
                </main>
            </section>

            <div class="dc-mobile-save" aria-label="Mobile save actions">
                <button type="button" class="dc-btn" data-reset-changes-mobile>Reset</button>
                <button type="submit" class="dc-btn dc-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
    (function () {
        const root = document.querySelector('[data-dashboard-config]');
        if (!root) return;

        const sectionLabels = @json($sections->mapWithKeys(fn ($section) => [$section['key'] => ['label' => $section['full'], 'count' => $section['count']]])->all());
        let activeSection = @json($selectedSection);
        let dirty = false;
        let searchTerm = '';

        const form = document.getElementById('dashboardConfigForm');
        const saveState = root.querySelector('[data-save-state]');
        const roleSelect = root.querySelector('[data-role-select]');
        const progress = root.querySelector('[data-current-progress]');
        const currentSectionTitle = root.querySelector('[data-current-section]');

        function widgetsFor(section) {
            return Array.from(root.querySelectorAll('[data-widget][data-section="' + section + '"]'));
        }

        function markDirty() {
            dirty = true;
            if (saveState) {
                saveState.textContent = 'Unsaved changes';
                saveState.classList.add('dirty');
            }
        }

        function markClean() {
            dirty = false;
            if (saveState) {
                saveState.textContent = 'All changes saved';
                saveState.classList.remove('dirty');
            }
        }

        function updateCounts() {
            Object.keys(sectionLabels).forEach(function (section) {
                const enabledCount = widgetsFor(section).filter(function (row) {
                    return row.dataset.enabled === '1';
                }).length;

                root.querySelectorAll('[data-section-count="' + section + '"]').forEach(function (node) {
                    node.textContent = enabledCount;
                });

                if (section === activeSection && progress) {
                    progress.textContent = enabledCount + ' / ' + sectionLabels[section].count + ' visible';
                }
            });
        }

        function updateRow(row, checked) {
            const isEnabled = checked ? '1' : '0';
            row.dataset.enabled = isEnabled;
            row.classList.toggle('hidden-widget', !checked);

            const label = row.querySelector('[data-state-label]');
            if (label) label.textContent = checked ? 'Visible' : 'Hidden';

            const toggle = row.querySelector('[data-widget-toggle]');
            if (toggle) toggle.checked = checked;
        }

        function applySearch() {
            root.querySelectorAll('[data-widget]').forEach(function (row) {
                if (row.dataset.section !== activeSection) {
                    row.hidden = false;
                    return;
                }

                row.hidden = !!searchTerm && !(row.dataset.search || '').includes(searchTerm);
            });
        }

        root.querySelectorAll('[data-section-target]').forEach(function (button) {
            button.addEventListener('click', function () {
                activeSection = button.dataset.sectionTarget;

                root.querySelectorAll('[data-section-target]').forEach(function (item) {
                    const active = item === button;
                    item.classList.toggle('active', active);
                    item.setAttribute('aria-pressed', active ? 'true' : 'false');
                });

                searchTerm = '';
                const searchInput = root.querySelector('[data-search-widgets]');
                if (searchInput) searchInput.value = '';

                root.querySelectorAll('[data-widget]').forEach(function (row) {
                    row.hidden = false;
                });

                root.querySelectorAll('[data-section-panel]').forEach(function (panel) {
                    const active = panel.dataset.sectionPanel === activeSection;
                    panel.hidden = !active;
                    panel.setAttribute('aria-hidden', active ? 'false' : 'true');
                });

                if (currentSectionTitle) currentSectionTitle.textContent = sectionLabels[activeSection].label;
                updateCounts();
                applySearch();
            });
        });

        root.querySelector('[data-search-widgets]')?.addEventListener('input', function (event) {
            searchTerm = event.target.value.trim().toLowerCase();
            applySearch();
        });

        root.querySelectorAll('[data-widget-toggle]').forEach(function (toggle) {
            toggle.addEventListener('change', function () {
                const row = toggle.closest('[data-widget]');
                updateRow(row, toggle.checked);
                updateCounts();
                markDirty();
            });
        });

        root.querySelector('[data-bulk-action]')?.addEventListener('change', function (event) {
            const action = event.target.value;
            if (!action) return;

            widgetsFor(activeSection).forEach(function (row) {
                if (row.hidden) return;

                if (action === 'enable') updateRow(row, true);
                if (action === 'disable') updateRow(row, false);
                if (action === 'reset') updateRow(row, row.dataset.originalEnabled === '1');
            });

            event.target.value = '';
            updateCounts();
            markDirty();
        });

        function resetChanges() {
            widgetsFor(activeSection).forEach(function (row) {
                updateRow(row, row.dataset.originalEnabled === '1');
            });
            updateCounts();
            markDirty();
        }

        root.querySelectorAll('[data-reset-changes], [data-reset-changes-mobile]').forEach(function (button) {
            button.addEventListener('click', function () {
                if (confirm('Reset unsaved changes in this section?')) resetChanges();
            });
        });

        roleSelect?.addEventListener('change', function () {
            if (dirty && !confirm('You have unsaved changes. Switch role without saving?')) {
                roleSelect.value = roleSelect.dataset.currentRole;
                return;
            }

            document.getElementById('dashboardRoleForm')?.submit();
        });

        form?.addEventListener('submit', function () {
            markClean();
        });
    })();
</script>
@endsection