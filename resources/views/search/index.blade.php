@extends('layouts.app')

@section('content')
@php
    $sectionDescriptions = [
        'customers' => 'Customer identities, contact details, and patient-facing records.',
        'products' => 'Medical equipment catalog entries and product master records.',
        'assets' => 'Individual equipment units, serial numbers, and barcode-linked inventory.',
        'rentals' => 'Live and historical patient rental records.',
        'sales' => 'Equipment sales and linked customer transactions.',
        'invoices' => 'Billing records, invoice numbers, and receivables.',
        'deliveries' => 'Dispatch, delivery, and pickup workflow records.',
    ];
@endphp

<style>
    .global-search-page { display:grid; gap:18px; padding:8px 0 28px; max-width:1280px; margin:0 auto; }
    .global-search-header { display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap; }
    .global-search-title h1 { margin:0; color:var(--ph-color-text); font-size:32px; letter-spacing:-0.045em; font-family:var(--ph-font-heading); }
    .global-search-title p { margin:8px 0 0; color:var(--ph-color-text-soft); font-size:14px; line-height:1.6; max-width:720px; }
    .global-search-query { display:inline-flex; align-items:center; gap:8px; padding:9px 12px; border-radius:999px; background:var(--ph-color-info-soft); color:var(--ph-color-info); font-size:11px; font-weight:800; letter-spacing:.08em; text-transform:uppercase; }
    .global-search-grid { display:grid; gap:16px; }
    .global-search-section { display:grid; gap:12px; }
    .global-search-section-head { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap; }
    .global-search-section-head h2 { margin:0; color:var(--ph-color-text); font-size:20px; letter-spacing:-0.03em; font-family:var(--ph-font-heading); }
    .global-search-section-head p { margin:6px 0 0; color:var(--ph-color-text-soft); font-size:13px; line-height:1.55; }
    .global-search-count { display:inline-flex; align-items:center; justify-content:center; min-width:32px; min-height:32px; padding:0 10px; border-radius:999px; background:var(--ph-color-surface-soft); border:1px solid var(--ph-color-border); color:var(--ph-color-text-soft); font-size:12px; font-weight:800; }
    .global-search-card { padding:18px; }
    .global-search-list { display:grid; gap:10px; }
    .global-search-result { display:flex; justify-content:space-between; align-items:flex-start; gap:14px; padding:14px 15px; border:1px solid var(--ph-color-border); border-radius:16px; background:#fff; text-decoration:none; color:inherit; transition:transform .16s ease, box-shadow .16s ease, border-color .16s ease; }
    .global-search-result:hover { transform:translateY(-1px); border-color:rgba(23,119,189,.24); box-shadow:var(--ph-shadow-soft); }
    .global-search-copy { min-width:0; display:grid; gap:6px; }
    .global-search-copy strong { color:var(--ph-color-text); font-size:15px; line-height:1.3; font-family:var(--ph-font-heading); }
    .global-search-copy span { color:var(--ph-color-text-soft); font-size:13px; line-height:1.55; }
    .global-search-meta { display:inline-flex; align-items:center; white-space:nowrap; padding:6px 10px; border-radius:999px; background:var(--ph-color-surface-soft); color:var(--ph-color-text-soft); border:1px solid var(--ph-color-border); font-size:11px; font-weight:800; letter-spacing:.05em; text-transform:uppercase; }
    .global-search-empty,
    .global-search-help { padding:20px; text-align:center; color:var(--ph-color-text-soft); font-size:14px; line-height:1.65; }
    .global-search-help strong,
    .global-search-empty strong { display:block; margin-bottom:6px; color:var(--ph-color-text); font-family:var(--ph-font-heading); font-size:16px; }
    .global-search-validation { padding:16px 18px; border:1px solid rgba(179,13,35,.14); border-radius:16px; background:var(--ph-color-danger-soft); color:var(--ph-color-danger); font-size:14px; line-height:1.5; }
    @media (max-width: 768px) {
        .global-search-page { gap:14px; }
        .global-search-title h1 { font-size:26px; }
        .global-search-result { flex-direction:column; align-items:flex-start; }
    }
</style>

<div class="global-search-page">
    <section class="ph-card global-search-card">
        <div class="global-search-header">
            <div class="global-search-title">
                <h1>Global Search</h1>
                <p>Search customers, medical equipment, equipment units, rentals, sales, invoices, and dispatch records across Prime Healers operations.</p>
            </div>
            @if($performedSearch && $query !== '')
                <span class="global-search-query">Query: {{ $query }}</span>
            @endif
        </div>
    </section>

    @if($validationMessage)
        <div class="global-search-validation">{{ $validationMessage }}</div>
    @elseif(!$performedSearch)
        <section class="ph-card global-search-card global-search-help">
            <strong>Start with a customer, invoice, equipment, or serial number.</strong>
            Type at least 2 characters in the top search bar and press Enter.
        </section>
    @elseif(!$hasResults)
        <section class="ph-card global-search-card global-search-empty">
            <strong>No matching records found.</strong>
            Try another customer name, phone number, invoice number, serial number, or equipment model.
        </section>
    @else
        <div class="global-search-grid">
            @foreach($resultGroups as $group)
                @continue($group['results']->isEmpty())
                <section class="ph-card global-search-card global-search-section">
                    <div class="global-search-section-head">
                        <div>
                            <h2>{{ $group['title'] }}</h2>
                            <p>{{ $sectionDescriptions[$group['key']] ?? 'Operational records found for this query.' }}</p>
                        </div>
                        <span class="global-search-count">{{ $group['results']->count() }}</span>
                    </div>
                    <div class="global-search-list">
                        @foreach($group['results'] as $result)
                            @php($href = $result->href ?? null)
                            @if($href)
                                <a href="{{ $href }}" class="global-search-result">
                                    <div class="global-search-copy">
                                        <strong>{{ $result->title }}</strong>
                                        @if(!empty($result->subtitle))
                                            <span>{{ $result->subtitle }}</span>
                                        @endif
                                    </div>
                                    @if(!empty($result->meta))
                                        <span class="global-search-meta">{{ $result->meta }}</span>
                                    @endif
                                </a>
                            @else
                                <div class="global-search-result">
                                    <div class="global-search-copy">
                                        <strong>{{ $result->title }}</strong>
                                        @if(!empty($result->subtitle))
                                            <span>{{ $result->subtitle }}</span>
                                        @endif
                                    </div>
                                    @if(!empty($result->meta))
                                        <span class="global-search-meta">{{ $result->meta }}</span>
                                    @endif
                                </div>
                            @endif
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>
    @endif
</div>
@endsection
