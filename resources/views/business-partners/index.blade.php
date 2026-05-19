@extends('layouts.app')

@section('content')
<style>
    .bp-index { display:grid; gap:16px; padding:18px 22px 30px; max-width:1200px; margin:0 auto; }
    .bp-toolbar, .bp-summary { display:grid; gap:12px; }
    .bp-toolbar-card, .bp-table-card, .bp-summary-tile { background:#fff; border:1px solid #dbe3ef; border-radius:16px; box-shadow:0 8px 24px rgba(15,23,42,.04); }
    .bp-toolbar-card { padding:18px; }
    .bp-summary { grid-template-columns:repeat(4, minmax(0,1fr)); }
    .bp-summary-tile { padding:14px; }
    .bp-summary-tile span { display:block; color:#64748b; font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.05em; }
    .bp-summary-tile strong { display:block; margin-top:6px; color:#0f172a; font-size:24px; }
    .bp-filters { display:grid; grid-template-columns:minmax(0, 2fr) 220px auto auto; gap:10px; align-items:end; }
    .bp-filters input, .bp-filters select { width:100%; box-sizing:border-box; border:1px solid #cbd5e1; border-radius:12px; padding:10px 12px; font-size:14px; }
    .bp-table-card { overflow:hidden; }
    .bp-table-head { display:flex; justify-content:space-between; align-items:center; gap:12px; padding:16px 18px; border-bottom:1px solid #e2e8f0; }
    .bp-table-wrap { overflow:auto; }
    .bp-table { width:100%; border-collapse:collapse; }
    .bp-table th, .bp-table td { padding:12px 16px; border-bottom:1px solid #eef2f7; text-align:left; vertical-align:top; }
    .bp-table th { font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:.05em; }
    .bp-btn, .bp-btn-light { display:inline-flex; align-items:center; justify-content:center; gap:6px; min-height:40px; padding:10px 14px; border-radius:12px; border:1px solid transparent; text-decoration:none; font-size:13px; font-weight:700; cursor:pointer; }
    .bp-btn { background:#0f172a; color:#fff; }
    .bp-btn-light { background:#fff; color:#334155; border-color:#cbd5e1; }
    .bp-badge { display:inline-flex; align-items:center; padding:5px 10px; border-radius:999px; font-size:11px; font-weight:800; text-transform:uppercase; }
    .bp-badge.active { background:#dcfce7; color:#166534; }
    .bp-badge.inactive { background:#e2e8f0; color:#475569; }
    @media (max-width: 900px) {
        .bp-summary { grid-template-columns:repeat(2, minmax(0,1fr)); }
        .bp-filters { grid-template-columns:1fr; }
    }
    @media (max-width: 640px) {
        .bp-index { padding:14px; }
        .bp-summary { grid-template-columns:1fr; }
    }
</style>

<div class="bp-index">
    <div class="bp-toolbar-card">
        <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:flex-start;">
            <div>
                <h1 style="margin:0;color:#0f172a;">Business Partners</h1>
                <p style="margin:6px 0 0;color:#64748b;">Manage tie-up contacts for reminders, invoices, and payments. Actual clients stay nested under each partner.</p>
            </div>
            <a href="{{ route('business-partners.create') }}" class="bp-btn">Add Business Partner</a>
        </div>
    </div>

    <div class="bp-summary">
        <div class="bp-summary-tile"><span>Total Partners</span><strong>{{ $totalPartners }}</strong></div>
        <div class="bp-summary-tile"><span>Active Partners</span><strong>{{ $activePartners }}</strong></div>
        <div class="bp-summary-tile"><span>Total Actual Clients</span><strong>{{ $totalClients }}</strong></div>
        <div class="bp-summary-tile"><span>Active Actual Clients</span><strong>{{ $activeClients }}</strong></div>
    </div>

    <div class="bp-toolbar-card">
        <form method="GET" class="bp-filters">
            <div>
                <label style="display:block;margin-bottom:6px;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:#475569;">Search</label>
                <input type="text" name="search" value="{{ $search }}" placeholder="Search business name, contact person, phone, email, or city">
            </div>
            <div>
                <label style="display:block;margin-bottom:6px;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:#475569;">Status</label>
                <select name="status">
                    <option value="">All statuses</option>
                    <option value="active" {{ $status === 'active' ? 'selected' : '' }}>Active</option>
                    <option value="inactive" {{ $status === 'inactive' ? 'selected' : '' }}>Inactive</option>
                </select>
            </div>
            <button type="submit" class="bp-btn">Search</button>
            <a href="{{ route('business-partners.index') }}" class="bp-btn-light">Clear Filters</a>
        </form>
    </div>

    <div class="bp-table-card">
        <div class="bp-table-head">
            <div>
                <h2 style="margin:0;color:#0f172a;">Partner List</h2>
                <div style="color:#64748b;font-size:12px;margin-top:4px;">Searchable list with actual-client counts and status.</div>
            </div>
            <div style="color:#64748b;font-size:12px;">{{ $businessPartners->total() }} result{{ $businessPartners->total() === 1 ? '' : 's' }}</div>
        </div>
        <div class="bp-table-wrap">
            <table class="bp-table">
                <thead>
                    <tr>
                        <th>Business Partner</th>
                        <th>Contact</th>
                        <th>Location</th>
                        <th>Clients</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($businessPartners as $partner)
                        <tr>
                            <td>
                                <strong>{{ $partner->displayName() }}</strong>
                                @if($partner->contact_person)
                                    <div style="color:#64748b;font-size:12px;margin-top:4px;">{{ $partner->contact_person }}</div>
                                @endif
                            </td>
                            <td>
                                <div>{{ $partner->phone ?: '-' }}</div>
                                <div style="color:#64748b;font-size:12px;">{{ $partner->email ?: 'No email' }}</div>
                            </td>
                            <td>
                                <div>{{ collect([$partner->city, $partner->state])->filter()->implode(', ') ?: 'Not set' }}</div>
                                <div style="color:#64748b;font-size:12px;">{{ $partner->pincode ?: 'No pincode' }}</div>
                            </td>
                            <td>{{ $partner->partner_clients_count }}</td>
                            <td><span class="bp-badge {{ $partner->status === 'inactive' ? 'inactive' : 'active' }}">{{ ucfirst($partner->status ?: 'active') }}</span></td>
                            <td>
                                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                                    <a href="{{ route('business-partners.show', $partner) }}" class="bp-btn-light">View</a>
                                    <a href="{{ route('business-partners.edit', $partner) }}" class="bp-btn-light">Edit</a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" style="color:#64748b;">No business partners found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div style="padding:14px 18px;">{{ $businessPartners->links() }}</div>
    </div>
</div>
@endsection
