@extends('layouts.app')

@section('content')
@php
    $statusBadge = $staff->status === 'active'
        ? 'background:#dcfce7;color:#166534;'
        : 'background:#fee2e2;color:#991b1b;';
@endphp

<style>
    .staff-show-shell { display:grid; gap:16px; padding:18px 22px 28px; }
    .staff-show-header { display:flex; justify-content:space-between; align-items:flex-start; gap:14px; flex-wrap:wrap; }
    .staff-show-header h1 { margin:0; font-size:30px; color:#0f172a; }
    .staff-show-header p { margin:6px 0 0; color:#64748b; font-size:13px; max-width:760px; }
    .staff-show-actions { display:flex; gap:8px; flex-wrap:wrap; }
    .staff-show-btn,
    .staff-show-btn-secondary {
        display:inline-flex; align-items:center; justify-content:center; gap:6px;
        min-height:36px; padding:8px 12px; border-radius:10px; font-size:13px; font-weight:600;
        text-decoration:none; border:1px solid transparent; cursor:pointer;
    }
    .staff-show-btn { background:#0f172a; color:#fff; }
    .staff-show-btn-secondary { background:#fff; color:#334155; border-color:#cbd5e1; }
    .staff-show-card { background:#fff; border:1px solid #dbe3ef; border-radius:14px; box-shadow:0 8px 24px rgba(15,23,42,0.04); }
    .staff-show-card-head { padding:14px 16px; border-bottom:1px solid #e2e8f0; }
    .staff-show-card-head h2 { margin:0; font-size:16px; color:#0f172a; }
    .staff-show-card-head p { margin:4px 0 0; color:#64748b; font-size:12px; }
    .staff-show-card-body { padding:14px 16px; }
    .staff-show-grid-2 { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:16px; }
    .staff-show-grid-4 { display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:12px; }
    .staff-show-metric { border:1px solid #e2e8f0; border-radius:12px; padding:12px; background:#f8fafc; }
    .staff-show-metric span { display:block; font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.04em; }
    .staff-show-metric strong { display:block; margin-top:6px; font-size:18px; color:#0f172a; }
    .staff-show-badge {
        display:inline-flex; align-items:center; padding:5px 9px; border-radius:999px;
        font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em;
    }
    .role-badge { background:#eff6ff; color:#1d4ed8; }
    .assign-yes { background:#dcfce7; color:#166534; }
    .assign-no { background:#f1f5f9; color:#475569; }
    .staff-show-list { display:grid; gap:10px; }
    .staff-show-item { border:1px solid #e2e8f0; border-radius:12px; padding:12px; background:#fcfdff; display:grid; gap:6px; }
    .staff-show-item-head { display:flex; justify-content:space-between; align-items:flex-start; gap:8px; flex-wrap:wrap; }
    .staff-show-item h3 { margin:0; font-size:14px; color:#0f172a; }
    .staff-show-item p { margin:0; color:#64748b; font-size:12px; }
    .staff-show-meta { display:flex; gap:12px; flex-wrap:wrap; color:#64748b; font-size:12px; }
    @media (max-width: 980px) {
        .staff-show-grid-2,
        .staff-show-grid-4 { grid-template-columns:1fr; }
    }
    @media (max-width: 640px) {
        .staff-show-shell { padding:14px; }
        .staff-show-actions { flex-direction:column; align-items:stretch; }
    }
</style>

<div class="container staff-show-shell">
    <div class="staff-show-header">
        <div>
            <h1>{{ $staff->name }}</h1>
            <p>Operational profile for delivery assignment, pickup coordination, and vendor visibility.</p>
        </div>
        <div class="staff-show-actions">
            <a href="{{ route('staff.index') }}" class="staff-show-btn-secondary">Back to Staff</a>
            <a href="{{ route('staff.edit', $staff->id) }}" class="staff-show-btn">Edit Staff</a>
        </div>
    </div>

    <div class="staff-show-grid-4">
        <div class="staff-show-metric">
            <span>Role</span>
            <strong>{{ $staff->role_display }}</strong>
        </div>
        <div class="staff-show-metric">
            <span>Status</span>
            <strong>{{ ucfirst($staff->status) }}</strong>
        </div>
        <div class="staff-show-metric">
            <span>Delivery Assignments</span>
            <strong>{{ $staff->delivery_assignments_count ?? 0 }}</strong>
        </div>
        <div class="staff-show-metric">
            <span>Pickup Assignments</span>
            <strong>{{ $staff->pickup_assignments_count ?? 0 }}</strong>
        </div>
    </div>

    <div class="staff-show-grid-2">
        <div class="staff-show-card">
            <div class="staff-show-card-head">
                <h2>Profile Summary</h2>
                <p>Core contact and classification details used across operations screens.</p>
            </div>
            <div class="staff-show-card-body" style="display:grid; gap:12px;">
                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                    <span class="staff-show-badge role-badge">{{ $staff->assignment_display }}</span>
                    <span class="staff-show-badge" style="{{ $statusBadge }}">{{ ucfirst($staff->status) }}</span>
                    <span class="staff-show-badge {{ $staff->assignment_eligible ? 'assign-yes' : 'assign-no' }}">
                        {{ $staff->assignment_eligible ? 'Assignable' : 'Not Assignable' }}
                    </span>
                </div>

                <div class="staff-show-meta">
                    <span>{{ $staff->phone ?: 'Phone not set' }}</span>
                    <span>{{ $staff->email ?: 'Email not set' }}</span>
                    <span>{{ \App\Models\Staff::hasCityColumn() ? ($staff->city ?: 'City not set') : 'City not available' }}</span>
                    <span>{{ $staff->joining_date ?: 'Joining date not set' }}</span>
                </div>

                <div>
                    <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em;">Address</div>
                    <div style="margin-top:5px;color:#0f172a;font-size:14px;">{{ $staff->address ?: 'No address added.' }}</div>
                </div>

                @if(\App\Models\Staff::hasNotesColumn())
                    <div>
                        <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em;">Operational Notes</div>
                        <div style="margin-top:5px;color:#0f172a;font-size:14px;">{{ $staff->notes ?: 'No operational notes added.' }}</div>
                    </div>
                @endif
            </div>
        </div>

        <div class="staff-show-card">
            <div class="staff-show-card-head">
                <h2>Quick Actions</h2>
                <p>Jump to the most common operational follow-ups.</p>
            </div>
            <div class="staff-show-card-body" style="display:grid; gap:10px;">
                <a href="{{ route('staff.edit', $staff->id) }}" class="staff-show-btn-secondary">Edit Staff Profile</a>
                @if($staff->phone)
                    <a href="tel:{{ preg_replace('/\s+/', '', $staff->phone) }}" class="staff-show-btn-secondary">Call {{ $staff->phone }}</a>
                @endif
                <a href="{{ route('deliveries.index') }}" class="staff-show-btn-secondary">View All Deliveries</a>
                <a href="{{ route('pickups.assigned') }}" class="staff-show-btn-secondary">View Pickup Queue</a>
            </div>
        </div>
    </div>

    <div class="staff-show-grid-2">
        <div class="staff-show-card">
            <div class="staff-show-card-head">
                <h2>Recent Delivery Assignments</h2>
                <p>Most recent delivery work linked to this staff member.</p>
            </div>
            <div class="staff-show-card-body">
                <div class="staff-show-list">
                    @forelse($recentDeliveries as $delivery)
                        <div class="staff-show-item">
                            <div class="staff-show-item-head">
                                <div>
                                    <h3><a href="{{ route('deliveries.show', $delivery->id) }}" style="color:#0f172a;text-decoration:none;">Delivery #{{ $delivery->id }}</a></h3>
                                    <p>Rental #{{ $delivery->rental_id }} • {{ $delivery->rental->customer_name ?? optional($delivery->rental?->customer)->name ?? 'Customer' }}</p>
                                </div>
                                <span class="staff-show-badge {{ $delivery->status === 'completed' ? 'assign-yes' : ($delivery->status === 'in_progress' ? 'role-badge' : 'assign-no') }}">
                                    {{ ucfirst(str_replace('_', ' ', $delivery->status)) }}
                                </span>
                            </div>
                            <div class="staff-show-meta">
                                <span>{{ $delivery->rental->product->name ?? 'Product N/A' }}</span>
                                <span>{{ $delivery->scheduled_at?->format('d M Y h:i A') ?? 'Schedule pending' }}</span>
                            </div>
                        </div>
                    @empty
                        <div style="color:#64748b;font-size:13px;">No recent delivery assignments found for this staff member.</div>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="staff-show-card">
            <div class="staff-show-card-head">
                <h2>Recent Pickup Assignments</h2>
                <p>Latest pickup work handled by this staff member.</p>
            </div>
            <div class="staff-show-card-body">
                <div class="staff-show-list">
                    @forelse($recentPickups as $pickup)
                        <div class="staff-show-item">
                            <div class="staff-show-item-head">
                                <div>
                                    <h3><a href="{{ route('deliveries.show', $pickup->id) }}" style="color:#0f172a;text-decoration:none;">Pickup #{{ $pickup->id }}</a></h3>
                                    <p>Rental #{{ $pickup->rental_id }} • {{ $pickup->rental->customer_name ?? optional($pickup->rental?->customer)->name ?? 'Customer' }}</p>
                                </div>
                                <span class="staff-show-badge {{ $pickup->status === 'completed' ? 'assign-yes' : ($pickup->status === 'in_progress' ? 'role-badge' : 'assign-no') }}">
                                    {{ ucfirst(str_replace('_', ' ', $pickup->status)) }}
                                </span>
                            </div>
                            <div class="staff-show-meta">
                                <span>{{ $pickup->rental->product->name ?? 'Product N/A' }}</span>
                                <span>{{ $pickup->scheduled_at?->format('d M Y h:i A') ?? 'Schedule pending' }}</span>
                            </div>
                        </div>
                    @empty
                        <div style="color:#64748b;font-size:13px;">No recent pickup assignments found for this staff member.</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
