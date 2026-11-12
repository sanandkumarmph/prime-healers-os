@extends('layouts.app')
@section('content')
@include('sales-workspace.style')
<main class="sw">
    <header class="sw-head"><div><div class="sw-eyebrow">Analytics · Staff Performance</div><h1>Staff incentive revenue</h1></div><a class="sw-btn" href="{{ route('reports.index',['tab'=>'staff']) }}">Back to Staff Performance</a></header>
    <p class="sw-muted">The report opens with the last 12 months. Bookings use the order creation date. Incentive credit uses the original invoice date and covers eligible product and rental charges after discounts and before GST. Renewals, deposits, transport, direct website orders and B2B referrals are excluded from incentive credit.</p>
    @if(session('success'))<p class="sw-success" role="status">{{ session('success') }}</p>@endif
    @if($errors->any())<p class="sw-error" role="alert">{{ $errors->first() }}</p>@endif
    <form class="sw-panel" method="get">
        <div class="sw-fields">
            <div class="sw-field"><label for="from">From date</label><input id="from" name="from" type="date" value="{{ $from }}"></div>
            <div class="sw-field"><label for="to">Through date</label><input id="to" name="to" type="date" value="{{ $to }}"></div>
            <div class="sw-field"><label for="staff">Staff member</label><select id="staff" name="staff"><option value="">All staff</option>@foreach($staffNames as $id => $name)<option value="{{ $id }}" @selected($staff === (int)$id)>{{ $name }}</option>@endforeach</select></div>
            <div class="sw-field"><label for="source">Enquiry source</label><select id="source" name="source"><option value="">All eligible sources</option>@foreach(\App\Models\Enquiry::INCENTIVE_SOURCES as $key => $label)<option value="{{ $key }}" @selected($source === $key)>{{ $label }}</option>@endforeach</select></div>
        </div>
        <button class="sw-btn sw-primary">Apply filters</button>
        @can('manage-bulk-records')
            <a class="sw-btn" href="{{ route('reports.staff-incentives', array_filter(['from' => $from, 'to' => $to, 'staff' => $staff, 'source' => $source, 'credit_kind' => $creditKind, 'download' => 'xlsx'])) }}">Download Excel</a>
        @endcan
    </form>
    @if($reviewOrders->total())
        <p><a class="sw-btn" href="#attribution">{{ $reviewOrders->total() }} invoiced {{ Str::plural('order', $reviewOrders->total()) }} awaiting source or salesperson</a></p>
    @endif
    <section class="sw-panel">
        <h2>Eligible revenue by staff member</h2>
        <p class="sw-muted">Booking counts show who entered the orders. The source filter applies to credit. Orders in review need their source or owner confirmed before credit is calculated.</p>
        <div style="overflow:auto"><table class="sw-table"><thead><tr><th>Staff</th><th>Sales booked</th><th>Rentals booked</th><th>Orders in review</th><th>Sales credited</th><th>Rentals credited</th><th>Sale credit</th><th>Rental credit</th><th>Eligible total</th></tr></thead>
            <tbody>@forelse($staffTotals as $id => $row)<tr>
                @php
                    $bookingFilters = ['salesperson_id' => $id, 'salesperson_match' => 'booked', 'from_date' => $from, 'to_date' => $to];
                    $creditFilters = array_filter(['staff' => $id, 'from' => $from, 'to' => $to, 'source' => $source]);
                @endphp
                <td>{{ $staffNames[$id] }}</td>
                @foreach(['sales' => $bookedSales, 'rentals' => $bookedRentals] as $module => $counts)
                    <td>@if($counts->get($id) && auth()->user()->canAccessModule($module, 'read'))
                        <a href="{{ route($module.'.index', $bookingFilters) }}" aria-label="{{ ucfirst($module) }} booked by {{ $staffNames[$id] }}">{{ $counts->get($id) }}</a>
                    @else{{ $counts->get($id, 0) }}@endif</td>
                @endforeach
                <td>{{ $pendingByStaff->get($id, 0) }}</td>
                @foreach(['sale' => $row['sales_credited'], 'rental' => $row['rentals_credited']] as $kind => $count)
                    <td>@if($count)
                        <a href="{{ route('reports.staff-incentives', $creditFilters + ['credit_kind' => $kind]) }}#order-credits" aria-label="{{ ucfirst($kind) }} orders credited to {{ $staffNames[$id] }}">{{ $count }}</a>
                    @else 0 @endif</td>
                @endforeach
                <td>₹{{ number_format($row['sale_revenue'],2) }}</td>
                <td>₹{{ number_format($row['rental_revenue'],2) }}</td><td>₹{{ number_format($row['revenue'],2) }}</td>
            </tr>@empty<tr><td colspan="9">No sales staff in this report.</td></tr>@endforelse</tbody>
            <tfoot><tr><th colspan="8">Total eligible credit</th><td>₹{{ number_format($total,2) }}</td></tr></tfoot>
        </table></div>
    </section>
    <section class="sw-panel" id="attribution">
        <h2>Orders awaiting attribution</h2>
        <p class="sw-muted">Use the original enquiry or booking information to confirm the source and salesperson. Revenue appears in the incentive totals once both are confirmed for an eligible source. Review follows the invoice dates and the selected staff member.</p>
        @if($reviewOrders->isNotEmpty())
            <div style="overflow:auto"><table class="sw-table">
                <thead><tr><th>Invoice date</th><th>Order</th><th>Invoice</th><th>Review</th></tr></thead>
                <tbody>@foreach($reviewOrders as $row)
                    @php $hasOldInput = old('kind') === $row->kind && (int) old('order') === (int) $row->order_id; @endphp
                    <tr>
                        <td>{{ \Illuminate\Support\Carbon::parse($row->invoice_date)->format('d M Y') }}</td>
                        <td>@if(auth()->user()->canAccessModule($row->kind.'s','read'))<a href="{{ route($row->kind.'s.show',$row->order_id) }}">{{ ucfirst($row->kind) }} #{{ $row->order_id }}</a>@else{{ ucfirst($row->kind) }} #{{ $row->order_id }}@endif</td>
                        <td>@if(auth()->user()->canAccessModule('invoices','read'))<a href="{{ route('invoices.show',$row->invoice_id) }}">{{ $row->invoice_number }}</a>@else{{ $row->invoice_number }}@endif</td>
                        <td>
                            @if($row->booked_by_name)<small>Booked by {{ $row->booked_by_name }}</small>@endif
                            @if($canAssign)
                                <details @if($hasOldInput) open @endif>
                                    <summary>{{ $row->known_source ? 'Assign salesperson' : 'Confirm source and salesperson' }}</summary>
                                    <form method="post" action="{{ route('reports.staff-incentives.assign') }}">
                                        @csrf
                                        <input type="hidden" name="kind" value="{{ $row->kind }}">
                                        <input type="hidden" name="order" value="{{ $row->order_id }}">
                                        <input type="hidden" name="from" value="{{ $from }}">
                                        <input type="hidden" name="to" value="{{ $to }}">
                                        @if($row->known_source)
                                            <p>{{ \App\Models\Enquiry::SOURCES[$row->known_source] }}</p>
                                            <input type="hidden" name="source" value="{{ $row->known_source }}">
                                        @else
                                            <label class="sw-field">Enquiry source
                                                <select name="source" required>
                                                    <option value="">Choose source</option>
                                                    @foreach(\App\Models\Enquiry::SOURCES as $key => $label)<option value="{{ $key }}" @selected($hasOldInput && old('source') === $key)>{{ $label }}</option>@endforeach
                                                </select>
                                            </label>
                                        @endif
                                        <label class="sw-field">Salesperson (required for eligible sources)
                                            <select name="staff" @required($row->eligible_source)>
                                                <option value="">Choose salesperson</option>
                                                @foreach($salesStaff as $id => $name)<option value="{{ $id }}" @selected((int)($hasOldInput ? old('staff') : $row->suggested_staff_id) === (int)$id)>{{ $name }}</option>@endforeach
                                            </select>
                                        </label>
                                        <button class="sw-btn sw-primary">Save attribution</button>
                                    </form>
                                </details>
                            @else
                                {{ $row->known_source ? 'Salesperson needed' : 'Source needed' }}
                            @endif
                        </td>
                    </tr>
                @endforeach</tbody>
            </table></div>
            {{ $reviewOrders->links() }}
        @else
            <p class="sw-muted">No orders awaiting attribution for these invoice dates.</p>
        @endif
    </section>
    <section class="sw-panel"><h2>Revenue by enquiry source</h2>
        @foreach(\App\Models\Enquiry::INCENTIVE_SOURCES as $key => $label)
            @if(!$source || $source === $key)<p>{{ $label }} · {{ $sourceTotals[$key]['orders'] ?? 0 }} order(s) · ₹{{ number_format($sourceTotals[$key]['revenue'] ?? 0,2) }}</p>@endif
        @endforeach
    </section>
    <section class="sw-panel" id="order-credits"><h2>{{ $creditKind ? ucfirst($creditKind).' credit details' : 'Order credit details' }}</h2>
        @if($creditKind)<p><a href="{{ route('reports.staff-incentives', request()->except(['credit_kind', 'page'])) }}#order-credits">Show all credited orders</a></p>@endif
        <div style="overflow:auto"><table class="sw-table"><thead><tr><th>Invoice date</th><th>Staff</th><th>Source</th><th>Order</th><th>Invoice</th><th>Share</th><th>Revenue credit</th></tr></thead>
            <tbody>@forelse($orders as $row)<tr>
                <td>{{ $row->invoice_date }}</td><td>{{ $staffNames[$row->staff_id] ?? ($row->staff_id ? 'User #'.$row->staff_id : 'Unattributed') }}</td>
                <td>{{ \App\Models\Enquiry::INCENTIVE_SOURCES[$row->eligible_source] }}</td>
                <td>@if(auth()->user()->canAccessModule($row->kind.'s', 'read'))<a href="{{ route($row->kind.'s.show', $row->order_id) }}">{{ ucfirst($row->kind) }} #{{ $row->order_id }}</a>@else{{ ucfirst($row->kind) }} #{{ $row->order_id }}@endif</td>
                <td>@if(auth()->user()->canAccessModule('invoices','read'))<a href="{{ route('invoices.show',$row->invoice_id) }}">{{ $row->invoice_number }}</a>@else{{ $row->invoice_number }}@endif</td>
                <td>{{ $row->credit_label }}</td>
                <td>₹{{ number_format($row->credit_paise / 100,2) }}</td>
            </tr>@empty<tr><td colspan="7">No orders to show.</td></tr>@endforelse</tbody>
        </table></div>{{ $orders->links() }}
    </section>
</main>
@endsection
