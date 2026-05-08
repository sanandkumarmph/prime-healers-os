@extends('layouts.app')

@section('content')
@php
    $statusBadge = function (?string $status) {
        return $status === 'active'
            ? 'background:#dcfce7;color:#166534;'
            : 'background:#fee2e2;color:#991b1b;';
    };
@endphp

<style>
    .staff-shell { display:grid; gap:16px; padding:18px 22px 28px; }
    .staff-header { display:flex; justify-content:space-between; align-items:flex-start; gap:14px; flex-wrap:wrap; }
    .staff-header h1 { margin:0; font-size:30px; color:#0f172a; }
    .staff-header p { margin:6px 0 0; color:#64748b; font-size:13px; max-width:780px; }
    .staff-actions { display:flex; gap:8px; flex-wrap:wrap; }
    .staff-btn,
    .staff-btn-secondary,
    .staff-btn-danger {
        display:inline-flex; align-items:center; justify-content:center; gap:6px;
        min-height:36px; padding:8px 12px; border-radius:10px; font-size:13px; font-weight:600;
        text-decoration:none; border:1px solid transparent; cursor:pointer;
    }
    .staff-btn { background:#0f172a; color:#fff; }
    .staff-btn-secondary { background:#fff; color:#334155; border-color:#cbd5e1; }
    .staff-btn-danger { background:#fff; color:#b91c1c; border-color:#fecaca; }
    .staff-card { background:#fff; border:1px solid #dbe3ef; border-radius:14px; box-shadow:0 8px 24px rgba(15,23,42,0.04); }
    .staff-card-head { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap; padding:14px 16px; border-bottom:1px solid #e2e8f0; }
    .staff-card-head h2 { margin:0; font-size:16px; color:#0f172a; }
    .staff-card-head p { margin:4px 0 0; color:#64748b; font-size:12px; }
    .staff-card-body { padding:14px 16px; }
    .staff-filter-grid { display:grid; grid-template-columns:2fr 1fr 1fr auto; gap:10px; }
    .staff-field { display:grid; gap:5px; }
    .staff-field label { font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.05em; }
    .staff-input,
    .staff-select {
        width:100%; min-height:38px; padding:8px 11px; border:1px solid #cbd5e1; border-radius:10px;
        font-size:13px; color:#0f172a; background:#fff;
    }
    .staff-filter-actions { display:flex; gap:8px; align-items:flex-end; flex-wrap:wrap; }
    .staff-summary { display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:12px; }
    .staff-summary-card {
        border:1px solid #e2e8f0; border-radius:12px; padding:14px; background:#fcfdff;
        display:grid; gap:6px;
    }
    .staff-summary-card span { font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.05em; }
    .staff-summary-card strong { font-size:24px; color:#0f172a; }
    .staff-table-wrap { overflow-x:auto; }
    .staff-table { width:100%; border-collapse:collapse; }
    .staff-table th, .staff-table td { padding:11px 8px; border-bottom:1px solid #e2e8f0; text-align:left; vertical-align:top; font-size:13px; }
    .staff-table th { color:#64748b; font-size:11px; text-transform:uppercase; letter-spacing:.05em; }
    .staff-table a { color:#0f172a; text-decoration:none; font-weight:600; }
    .staff-table a:hover { color:#2563eb; }
    .staff-meta { display:grid; gap:2px; }
    .staff-meta small { color:#64748b; }
    .staff-badge {
        display:inline-flex; align-items:center; padding:5px 9px; border-radius:999px;
        font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em;
    }
    .staff-role { background:#eff6ff; color:#1d4ed8; }
    .staff-assign-yes { background:#dcfce7; color:#166534; }
    .staff-assign-no { background:#f1f5f9; color:#475569; }
    .staff-actions-row { display:flex; gap:6px; flex-wrap:wrap; }
    .staff-empty { color:#64748b; font-size:13px; padding:16px 0; text-align:center; }
    @media (max-width: 1100px) {
        .staff-filter-grid { grid-template-columns:1fr 1fr; }
        .staff-summary { grid-template-columns:repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 767px) {
        .staff-shell { padding:14px; }
        .staff-filter-grid,
        .staff-summary { grid-template-columns:1fr; }
        .staff-actions,
        .staff-filter-actions,
        .staff-actions-row { flex-direction:column; align-items:stretch; }
        .staff-table-wrap { overflow:visible; }
        .staff-table { min-width:0; display:block; border-collapse:separate; border-spacing:0 10px; }
        .staff-table thead { display:none; }
        .staff-table tbody,
        .staff-table tr,
        .staff-table td { display:block; width:100%; }
        .staff-table tr {
            margin-bottom:10px; border:1px solid #dbe3ef; border-radius:14px;
            background:#fff; box-shadow:0 8px 22px rgba(15,23,42,.04); overflow:hidden;
        }
        .staff-table td {
            display:grid; grid-template-columns:108px minmax(0, 1fr); gap:10px;
            padding:10px 12px; border-bottom:1px solid #edf2f7; background:#fff;
        }
        .staff-table td:last-child { border-bottom:none; }
        .staff-table td::before {
            color:#64748b; font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:.06em;
        }
        .staff-table td:nth-child(1)::before { content:"Staff"; }
        .staff-table td:nth-child(2)::before { content:"Role"; }
        .staff-table td:nth-child(3)::before { content:"Status"; }
        .staff-table td:nth-child(4)::before { content:"City"; }
        .staff-table td:nth-child(5)::before { content:"Assignable"; }
        .staff-table td:nth-child(6)::before { content:"Work"; }
        .staff-table td:nth-child(7)::before { content:"Actions"; }
        .staff-actions-row .staff-btn-secondary,
        .staff-actions-row .staff-btn-danger { width:100%; }
    }
</style>

<div class="container staff-shell rn-list-page">
    <div class="staff-header">
        <div>
            <div class="rx-eyebrow">Team Directory</div>
            <h1>Staff Operations</h1>
            <p>Manage internal delivery staff, vendors, and third-party contacts in one place so assignment dropdowns stay relevant and clean across rentals and logistics.</p>
        </div>

        <div class="staff-actions">
            <a href="{{ route('staff.export.csv', request()->query()) }}" class="staff-btn-secondary">Export CSV</a>
            <a href="{{ route('staff.create') }}" class="staff-btn">+ Add Staff</a>
        </div>
    </div>

    @if(session('success'))
        <div style="background:#dcfce7;color:#166534;border:1px solid #bbf7d0;padding:12px 14px;border-radius:12px;">
            {{ session('success') }}
        </div>
    @endif

    <details class="staff-card">
        <summary class="staff-card-head" style="cursor:pointer; list-style:none;">
            <div>
                <h2>Search & Filters</h2>
                <p>Find people by name, phone, role, or city and narrow the list to who can actually be assigned.</p>
            </div>
        </summary>
        <div class="staff-card-body">
            <form method="GET" action="{{ route('staff.index') }}">
                <div class="staff-filter-grid">
                    <div class="staff-field">
                        <label for="search">Search</label>
                        <input id="search" class="staff-input" type="text" name="search" value="{{ request('search') }}" placeholder="Name, phone, email, city">
                    </div>

                    <div class="staff-field">
                        <label for="role">Role</label>
                        <select id="role" class="staff-select" name="role">
                            <option value="">All Roles</option>
                            @foreach($roleOptions as $roleValue => $roleLabel)
                                <option value="{{ $roleValue }}" @selected(request('role') === $roleValue)>{{ $roleLabel }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="staff-field">
                        <label for="status">Status</label>
                        <select id="status" class="staff-select" name="status">
                            <option value="">All Status</option>
                            <option value="active" @selected(request('status') === 'active')>Active</option>
                            <option value="inactive" @selected(request('status') === 'inactive')>Inactive</option>
                        </select>
                    </div>

                    <div class="staff-filter-actions">
                        <button type="submit" class="staff-btn">Apply</button>
                        <a href="{{ route('staff.index') }}" class="staff-btn-secondary">Reset</a>
                    </div>
                </div>
            </form>
        </div>
    </details>

    @php
        $staffCollection = $staff->getCollection();
        $activeCount = $staffCollection->where('status', 'active')->count();
        $assignableCount = $staffCollection->filter(fn ($member) => $member->assignment_eligible)->count();
        $vendorCount = $staffCollection->filter(fn ($member) => in_array($member->effective_role, ['vendor', 'third_party'], true))->count();
    @endphp

    <div class="staff-summary">
        <div class="staff-summary-card">
            <span>Filtered Staff</span>
            <strong>{{ $staff->total() }}</strong>
        </div>
        <div class="staff-summary-card">
            <span>Active</span>
            <strong>{{ $activeCount }}</strong>
        </div>
        <div class="staff-summary-card">
            <span>Assignable</span>
            <strong>{{ $assignableCount }}</strong>
        </div>
        <div class="staff-summary-card">
            <span>Vendors / Third Parties</span>
            <strong>{{ $vendorCount }}</strong>
        </div>
    </div>

    <div class="staff-card rn-table-shell">
        <div class="staff-card-head">
            <div>
                <h2>Team Directory</h2>
                <p>Compact list for operations teams to identify who can handle delivery and pickup work.</p>
            </div>
        </div>
        <div class="staff-card-body">
            @if($staff->isEmpty())
                <div class="staff-empty">No staff members match this view right now. Broaden the filters or add a team member to continue.</div>
            @else
                <div class="staff-table-wrap">
                    <table class="staff-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Role / Type</th>
                                <th>Status</th>
                                <th>City</th>
                                <th>Assignment Eligibility</th>
                                <th>Assignments</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($staff as $member)
                                <tr>
                                    <td>
                                        <div class="staff-meta">
                                            <a href="{{ route('staff.show', $member->id) }}">{{ $member->name }}</a>
                                            <small>{{ $member->phone ?: 'Phone not set' }}</small>
                                            <small>{{ $member->email ?: 'Email not set' }}</small>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="display:grid; gap:6px;">
                                            <span class="staff-badge staff-role">{{ $member->role_display }}</span>
                                            <small style="color:#64748b;">Assignment role: {{ $member->assignment_display }}</small>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="staff-badge rn-badge {{ $member->status === 'active' ? 'rn-badge-active' : 'rn-badge-muted' }}" style="{{ $statusBadge($member->status) }}">
                                            {{ ucfirst($member->status) }}
                                        </span>
                                    </td>
                                    <td>{{ \App\Models\Staff::hasCityColumn() ? ($member->city ?: 'N/A') : 'N/A' }}</td>
                                    <td>
                                        <span class="staff-badge {{ $member->assignment_eligible ? 'staff-assign-yes' : 'staff-assign-no' }}">
                                            {{ $member->assignment_eligible ? 'Eligible' : 'Not Assignable' }}
                                        </span>
                                    </td>
                                    <td>
                                        <div class="staff-meta">
                                            <small>Delivery: {{ $member->delivery_assignments_count ?? 0 }}</small>
                                            <small>Pickup: {{ $member->pickup_assignments_count ?? 0 }}</small>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="staff-actions-row">
                                            @if($member->phone)
                                                <a href="tel:{{ preg_replace('/\D+/', '', $member->phone) }}" class="staff-btn-secondary" style="min-height:32px;padding:6px 10px;">Call</a>
                                            @endif
                                            <a href="{{ route('staff.show', $member->id) }}" class="staff-btn-secondary" style="min-height:32px;padding:6px 10px;">View</a>
                                            <a href="{{ route('staff.edit', $member->id) }}" class="staff-btn-secondary" style="min-height:32px;padding:6px 10px;">Edit</a>
                                            <form action="{{ route('staff.destroy', $member->id) }}" method="POST" onsubmit="return confirm('Delete this staff member?');" style="margin:0;">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="staff-btn-danger" style="min-height:32px;padding:6px 10px;">Delete</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div style="margin-top:14px;">
                    {{ $staff->links() }}
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
