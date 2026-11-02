@if(!empty($s['sender']['name']))
<div style="margin-top:20px;padding-top:12px;border-top:1px solid #e0e7ef" data-quotation-signoff>
    Regards,<br><strong>{{ $s['sender']['name'] }}</strong><br>
    {{ $s['sender']['company'] ?? $s['issuer']['name'] }}
    @if(!empty($s['sender']['phone']))<br>Contact: {{ $s['sender']['phone'] }}@endif
</div>
@endif
