@php($s=$quote->snapshot)
<section class="sw-panel"><header class="sw-head"><div><div class="sw-eyebrow">@if(!empty($s['issuer']['logo']))<img class="sw-logo" src="{{ $s['issuer']['logo'] }}" alt="{{ $s['issuer']['name'] }}"><br>@endif{{ $s['issuer']['name'] }}</div><h1>Quotation</h1><p class="sw-muted">{{ $quote->number() }} · {{ ucfirst($quote->kind) }}</p></div><div><span class="sw-badge" data-status="{{ $quote->displayStatus() }}">{{ ($customerView ?? false) && $quote->displayStatus()==='sent' ? 'Awaiting response' : $quote->statusLabel() }}</span><div class="sw-meta">Issued {{ $quote->created_at->timezone($s['timezone'] ?? 'UTC')->format('d M Y') }}</div><div class="sw-meta">Valid until {{ $quote->valid_until->format('d M Y') }}</div></div></header>
<div class="sw-fields" style="margin-bottom:18px">
    @foreach(['recipient'=>'Prepared for','issuer'=>'From'] as $party=>$label)
    <div>
        <div class="sw-eyebrow">{{ $label }}</div>
        <h3>{{ $s[$party]['name'] }}</h3>
        @if($s[$party]['address'])
            <p class="sw-note">{{ $s[$party]['address'] }}</p>
        @endif
        @if($s[$party]['phone'] || $s[$party]['email'] || $s[$party]['tax_number'])
        <p class="sw-meta">
            @if($s[$party]['phone'])
                <span style="display:block">{{ $s[$party]['phone'] }}</span>
            @endif
            @if($s[$party]['email'])
                <span style="display:block">{{ $s[$party]['email'] }}</span>
            @endif
            @if($s[$party]['tax_number'])
                <span style="display:block">{{ $s['tax_label'] }} ID: {{ $s[$party]['tax_number'] }}</span>
            @endif
        </p>
        @endif
    </div>
    @endforeach
</div>
@if(!empty($s['contact']['name']) && $s['contact']['name']!==$s['recipient']['name'])<p class="sw-meta">Enquiry contact: {{ $s['contact']['name'] }}</p>@endif
@if($quote->kind==='rental')<p class="sw-alert">Planned rental period: <strong>{{ \Illuminate\Support\Carbon::parse($s['start_date'])->format('d M Y') }} – {{ \Illuminate\Support\Carbon::parse($s['end_date'])->format('d M Y') }}</strong><br><small>Unit prices cover the entire quoted period.</small></p>@endif
<div class="sw-quote-lines-desktop"><table class="sw-table"><thead><tr><th>Product</th><th class="sw-number">Qty</th><th class="sw-number">Unit price</th><th>{{ $s['tax_label'] }}</th><th class="sw-number">Amount</th></tr></thead><tbody>@foreach($s['lines'] as $l)<tr><td>{{ $l['name'] }}</td><td class="sw-number">{{ $l['quantity'] }}</td><td class="sw-number">{{ number_format($l['rate'],2) }}</td><td>{{ $l['tax_rate'] }}%@if($l['tax_rate']>0)<br><small class="sw-muted">{{ $l['tax_mode']==='inclusive' ? 'Included' : 'Extra' }}</small>@endif</td><td class="sw-number">{{ number_format($l['total'],2) }}</td></tr>@endforeach</tbody></table></div>
<div class="sw-quote-lines-mobile">@foreach($s['lines'] as $l)<div class="sw-line-card"><strong class="sw-wrap">{{ $l['name'] }}</strong><div class="sw-meta">{{ $l['quantity'] }} × {{ number_format($l['rate'],2) }} · {{ $l['tax_rate'] }}% {{ $s['tax_label'] }} {{ $l['tax_rate']>0 ? ($l['tax_mode']==='inclusive' ? 'included' : 'extra') : '' }}</div><strong>{{ $s['currency'] }} {{ number_format($l['total'],2) }}</strong></div>@endforeach</div>
<div class="sw-totals"><div><span>Taxable amount</span><span>{{ number_format($s['subtotal'],2) }}</span></div><div><span>{{ $s['tax_label'] }}{{ $s['tax_label']==='GST' ? ($s['tax_type']==='cgst_sgst' ? ' (CGST + SGST)' : ' (IGST)') : '' }}</span><span>{{ number_format($s['tax'],2) }}</span></div><div><span>Delivery / transport</span><span>{{ number_format($s['transport'],2) }}</span></div><div class="sw-grand"><span>Total</span><span>{{ $s['currency'] }} {{ number_format($s['total'],2) }}</span></div>@if($quote->kind==='rental')<div><span>Refundable deposit</span><span>{{ number_format($s['deposit'],2) }}</span></div><div><strong>Total with deposit</strong><strong>{{ $s['currency'] }} {{ number_format($s['payable_with_deposit'],2) }}</strong></div>@endif</div>
@if($s['terms'])<h2>Terms & conditions</h2><div class="sw-note">{{ $s['terms'] }}</div>@endif
<p class="sw-meta" style="border-top:1px solid #e0e7ef;padding-top:16px;margin-top:24px">This is a quotation, not a tax invoice or payment receipt. Acceptance does not reserve stock. Availability and fulfilment are confirmed when the order is created.</p></section>
