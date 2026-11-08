@php
    $liveQuote = $liveQuote ?? $quote;
    $liveAgreement = $liveAgreement ?? app(\App\Services\Sales\RentalAgreements::class)->quotation($liveQuote);
    $liveRequiresTerms = $liveQuote->kind === 'rental' && \App\Services\Sales\RentalAgreements::enabled();
    $liveTermsReady = !$liveRequiresTerms || app(\App\Services\Sales\RentalAgreements::class)->readyForQuote($liveQuote, $liveAgreement);
    $liveWaiting = $liveRequiresTerms && !$liveTermsReady && app(\App\Services\Sales\RentalAgreements::class)->canIssueForQuote($liveQuote);
@endphp
<div class="sw-alert sw-live-status {{ $liveWaiting ? 'sw-live-waiting' : ($liveRequiresTerms && $liveTermsReady ? 'sw-success' : '') }}" data-live-status-banner role="status" aria-live="polite">
    <strong data-live-status-text>@if($liveRequiresTerms && $liveAgreement?->signed_at) Rental terms signed @elseif($liveRequiresTerms && $liveTermsReady) Signature exception approved @elseif($liveWaiting) Awaiting rental agreement. @else Quotation: {{ $liveQuote->statusLabel() }} @endif</strong>
    <span class="sw-meta" data-live-status-time>@if($liveAgreement?->signed_at) · {{ $liveAgreement->signed_at->timezone('Asia/Kolkata')->format('d M Y, h:i A') }} IST @endif</span>
    @if($liveRequiresTerms)<span class="sw-meta" data-live-quotation-summary>Quotation: {{ $liveQuote->statusLabel() }}</span>@endif
    <span class="sw-meta" data-live-connection-status></span>
</div>
@once
<style>
.sw-live-status{position:relative;isolation:isolate;overflow:hidden}.sw-live-status>*{position:relative;z-index:1}.sw-live-waiting{background:#fff8e8;border-color:#e8c773;color:#805a12}.sw-live-waiting::before{content:'';position:absolute;z-index:0;inset:0;background:linear-gradient(110deg,transparent 20%,rgba(245,193,69,.23) 48%,transparent 75%);transform:translateX(-100%);animation:sw-terms-wave 4.5s ease-in-out infinite;pointer-events:none}.sw-live-status .sw-meta{font-size:11px;margin-left:6px}.sw-live-form-note{font-size:12px;color:#805a12;margin:8px 0}.sw-live-copy-actions{display:flex;gap:7px;align-items:center;flex-wrap:wrap}
@keyframes sw-terms-wave{0%,15%{transform:translateX(-100%)}85%,100%{transform:translateX(100%)}}
@media(prefers-reduced-motion:reduce){.sw-live-waiting::before{animation:none;transform:none;opacity:.35}}
</style>
@endonce
@push('scripts')
@once
<script src="{{ asset('js/phos-quotation-live.js').'?v='.hash_file('sha256',public_path('js/phos-quotation-live.js')) }}" defer></script>
@endonce
@endpush
