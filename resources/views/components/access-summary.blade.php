@props(['user' => null, 'role' => null])
@php
    $subject = $user;
    if (!$subject && $role) {
        $subject = new \App\Models\User(['organization_id' => $role->organization_id, 'role_id' => $role->id,
            'role' => $role->slug, 'is_active' => true]);
        $subject->setRelation('assignedRole', $role);
    }
    $scopeLabels = ['all' => 'Organization', 'assigned' => 'Assigned work', 'self_created' => 'Own records'];
    $summaryRows = [
        'Account access' => $subject->hasActiveAccess() ? 'Enabled' : 'Blocked — inactive account or invalid role',
        'Dashboard' => $subject->canAccessDashboard() ? 'Allowed — role widgets and permissions apply' : 'Blocked',
        'Rental records' => $scopeLabels[$subject->scopeType('rentals')] ?? 'Role permissions',
        'Delivery tasks' => $scopeLabels[$subject->scopeType('deliveries')] ?? 'Role permissions',
        'Finance dashboard' => $subject->hasActiveAccess() && $subject->canAccessDashboard() && $subject->canViewFinanceDashboard() ? 'Permitted widgets only' : 'Hidden',
        'Team performance' => $subject->isSuperAdmin() && $subject->canAccessDashboard() ? 'Allowed' : 'Hidden',
    ];
@endphp
<section aria-label="Effective access" style="margin:18px 0;padding:18px;border:1px solid #dce7ee;border-radius:16px;background:#f8fbfd;">
    <h2 style="margin:0 0 6px;font-size:18px;">Effective access</h2>
    <p style="margin:0 0 14px;color:#64748b;font-size:13px;">The assigned role controls access. Record scopes apply only to modules this role can open.</p>
    <dl style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,220px),1fr));gap:14px;margin:0;">
        @foreach($summaryRows as $label => $value)
            <div style="min-width:0;"><dt style="font-size:12px;color:#64748b;">{{ $label }}</dt><dd style="margin:4px 0 0;font-size:14px;font-weight:600;overflow-wrap:anywhere;">{{ $value }}</dd></div>
        @endforeach
    </dl>
    @if(auth()->user()?->isSuperAdmin() || auth()->user()?->isAdminOperations() || auth()->user()?->canAccessModule('settings', 'update'))
        <p style="margin:14px 0 0;font-size:13px;"><a href="{{ route('organization.dashboard-settings.edit', ['role_id' => $role?->id ?? $subject->role_id]) }}">Configure dashboard widgets for this role</a></p>
    @endif
</section>
