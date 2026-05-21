@extends('layouts.app')

@section('content')
@php
    $categoryLabels = [
        'priority' => 'Priority Cards',
        'kpi' => 'KPI Cards',
        'snapshot' => 'Snapshot Tiles',
        'today_widget' => 'Today Widgets',
        'section' => 'Sections',
        'alert' => 'Operational Alerts',
    ];

    $sensitivityLabels = [
        'public_operational' => 'Public operational',
        'role_operational' => 'Role operational',
        'management' => 'Management',
        'finance' => 'Finance',
        'sensitive_business' => 'Sensitive business',
    ];
@endphp

<div class="rx-page-grid" style="display:grid;gap:16px;max-width:1180px;margin:0 auto;">
    <section class="rx-card">
        <div class="rx-card-header">
            <div>
                <h1 class="rx-page-title">Dashboard Settings</h1>
                <p class="rx-card-copy">Choose which dashboard widgets each role can see, and set their display order without exposing management cards to operational users.</p>
            </div>
        </div>
        <div class="rx-card-body" style="display:grid;gap:16px;">
            <form method="GET" action="{{ route('organization.dashboard-settings.edit') }}" style="display:grid;gap:10px;max-width:420px;">
                <label class="rx-field">
                    <span class="rx-label">Role</span>
                    <select name="role_id" class="rn-input" onchange="this.form.submit()">
                        @foreach($roles as $role)
                            <option value="{{ $role->id }}" @selected((int) $selectedRole->id === (int) $role->id)>{{ $role->name }}</option>
                        @endforeach
                    </select>
                </label>
            </form>

            @if(session('success'))
                <div class="rx-alert is-success">{{ session('success') }}</div>
            @endif

            <form method="POST" action="{{ route('organization.dashboard-settings.update') }}" style="display:grid;gap:16px;">
                @csrf
                @method('PUT')
                <input type="hidden" name="role_id" value="{{ $selectedRole->id }}">

                @foreach($widgetsByCategory as $category => $widgets)
                    <section class="rx-card" style="border:1px solid var(--ph-color-border); box-shadow:none;">
                        <div class="rx-card-header">
                            <div>
                                <h2 class="rx-card-title">{{ $categoryLabels[$category] ?? \Illuminate\Support\Str::headline((string) $category) }}</h2>
                                <p class="rx-card-copy">Enabled widgets render for this role only when permissions and sensitivity allow them.</p>
                            </div>
                        </div>
                        <div class="rx-card-body" style="display:grid;gap:12px;">
                            @foreach($widgets as $widget)
                                <div style="display:grid;grid-template-columns:minmax(0,1fr) 120px;gap:12px;align-items:start;padding:12px 14px;border:1px solid var(--ph-color-border);border-radius:16px;background:#fff;">
                                    <label style="display:flex;gap:12px;align-items:flex-start;min-width:0;">
                                        <input type="hidden" name="widgets[{{ $widget['widget_key'] }}][is_enabled]" value="0">
                                        <input type="checkbox" name="widgets[{{ $widget['widget_key'] }}][is_enabled]" value="1" @checked($widget['enabled']) style="margin-top:3px;">
                                        <span style="display:grid;gap:6px;min-width:0;">
                                            <strong style="font-size:14px;color:#0f172a;">{{ $widget['name'] }}</strong>
                                            <span style="font-size:12px;color:#64748b;line-height:1.5;">{{ $widget['description'] }}</span>
                                            <span style="display:flex;gap:8px;flex-wrap:wrap;">
                                                <span class="rx-badge">{{ $sensitivityLabels[$widget['sensitivity']] ?? $widget['sensitivity'] }}</span>
                                                <span class="rx-badge">{{ $categoryLabels[$widget['category']] ?? \Illuminate\Support\Str::headline((string) $widget['category']) }}</span>
                                            </span>
                                        </span>
                                    </label>
                                    <label class="rx-field" style="display:grid;gap:6px;">
                                        <span class="rx-label">Order</span>
                                        <input type="number" min="1" max="9999" name="widgets[{{ $widget['widget_key'] }}][sort_order]" value="{{ $widget['sort_order'] }}" class="rn-input">
                                    </label>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endforeach

                <div style="display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap;">
                    <a href="{{ route('organization.settings.edit') }}" class="rx-btn-secondary">Back to Settings</a>
                    <button type="submit" class="rx-btn">Save Dashboard Settings</button>
                </div>
            </form>
        </div>
    </section>
</div>
@endsection
