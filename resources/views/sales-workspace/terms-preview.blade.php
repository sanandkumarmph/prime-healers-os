@php
    $previewTerms = isset($agreement) ? $agreements->terms($agreement) : \App\Services\Sales\RentalAgreementTerms::TEXT;
    $previewMessage = isset($agreement) && ($allowTermsMessage ?? false) ? $agreements->message($agreement, $previewReference ?? 'Rental agreement') : null;
@endphp
<details class="sw-terms-preview" data-terms-preview>
    <summary>Preview / copy rental terms</summary>
    <div class="sw-terms-toolbar">
        <button type="button" class="sw-btn sw-small" data-copy-terms>Copy rental terms</button>
        @if($previewMessage)<button type="button" class="sw-btn sw-small" data-copy-terms-message>Copy message with {{ $agreement->signed_at ? 'signed-copy' : 'signing' }} link</button>@endif
    </div>
    <p class="sw-meta">{{ isset($agreement) && $agreement->signed_at ? 'The exact terms accepted by the customer.' : 'Review the terms the customer will be asked to sign.' }}</p>
    <div class="sw-terms-scroll">@include('sales-workspace.rental-agreement-terms', ['rentalTerms' => $previewTerms])</div>
    <textarea data-terms-text readonly hidden rows="10" aria-label="Rental terms to copy">{{ $previewTerms }}</textarea>
    @if($previewMessage)<textarea data-terms-message readonly hidden rows="10" aria-label="Rental terms message with private customer link">{{ $previewMessage }}</textarea>@endif
    <p class="sw-meta" data-terms-copy-status role="status" aria-live="polite"></p>
</details>
@once
<style>
.sw-terms-preview{border-top:1px solid #e0e7ef;padding-top:10px;margin-top:12px}.sw-terms-preview>summary{cursor:pointer;font-weight:650;color:#2558c8}.sw-terms-toolbar{display:flex;flex-wrap:wrap;gap:8px;margin:10px 0}.sw-terms-preview .sw-btn{display:inline-flex;align-items:center;justify-content:center;min-height:36px;padding:6px 10px;border:1px solid #cbd6e3;border-radius:8px;background:#fff;color:#172b4d;font-size:12px;font-weight:650;cursor:pointer}.sw-terms-scroll{max-height:320px;overflow:auto;overscroll-behavior:contain;border:1px solid #e0e7ef;border-radius:8px;padding:12px;background:#f9fbfd}.sw-terms-scroll .agreement-clause{margin:0 0 12px}.sw-terms-scroll h3{font-size:13px;margin:0 0 4px}.sw-terms-scroll p{font-size:12px;line-height:1.55;margin:0 0 4px}.sw-terms-preview textarea{display:block;box-sizing:border-box;width:100%;max-width:100%;margin-top:10px;padding:10px;border:1px solid #cbd6e3;border-radius:8px;font:inherit;font-size:13px;background:#fff;color:#172b4d}.sw-terms-preview textarea[hidden]{display:none!important}.sw-sharing-status{display:block;font-size:12px;line-height:1.5;color:#63748b;margin:7px 0 0}.sw-sharing-status a{color:#2558c8}
@media(max-width:600px){.sw-terms-preview textarea{font-size:16px}.sw-terms-toolbar .sw-btn{min-height:42px}}
</style>
@endonce
@push('scripts')
    @include('sales-workspace.share-message-script')
@endpush
