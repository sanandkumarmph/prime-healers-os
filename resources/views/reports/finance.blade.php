@extends('layouts.app')
@section('content')
@include('sales-workspace.style')
<main class="sw">
    <header class="sw-head">
        <div><div class="sw-eyebrow">Analytics</div><h1>Finance reports</h1></div>
        <div>
            <a class="sw-btn" href="{{ route('invoices.index') }}">Invoices</a>
            <a class="sw-btn" href="{{ route('ledger.index') }}">Payment ledger</a>
        </div>
    </header>
    <form method="get" class="sw-panel">
        <div class="sw-fields">
            <div class="sw-field"><label for="from_date">From date</label><input type="date" id="from_date" name="from_date" value="{{ $filters['from_date'] }}"></div>
            <div class="sw-field"><label for="to_date">Through date</label><input type="date" id="to_date" name="to_date" value="{{ $filters['to_date'] }}"></div>
        </div>
        <button class="sw-btn sw-primary">Apply dates</button>
        <a class="sw-btn" href="{{ route('reports.index') }}">Reset</a>
    </form>
    <section class="sw-panel">
        <h2>Revenue and collections</h2>
        <div style="overflow:auto"><table class="sw-table">
            <thead><tr><th scope="col">Measure</th><th scope="col">Amount</th></tr></thead>
            <tbody>
                @foreach([
                    'Total revenue' => $revenue['total_revenue'],
                    'Rental revenue' => $revenue['rental_revenue'],
                    'Sales revenue' => $revenue['sales_revenue'],
                    'Deposits' => $revenue['deposit'],
                    'Transportation' => $revenue['transport'],
                    'Other charges' => $revenue['other_charges'],
                    'Payments today' => $collections['payments_today'],
                    'Payments this month' => $collections['payments_this_month'],
                    'Outstanding dues' => $collections['outstanding_dues'],
                ] as $label => $amount)
                    <tr><th scope="row">{{ $label }}</th><td>₹{{ number_format($amount, 2) }}</td></tr>
                @endforeach
            </tbody>
        </table></div>
    </section>
</main>
@endsection
