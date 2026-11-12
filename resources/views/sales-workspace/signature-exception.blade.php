@php
    $exceptionQuote = $exceptionQuote ?? null;
    $exceptionRental = $exceptionRental ?? null;
    $exceptionAgreement = $agreement ?? null;
    $exceptionActor = auth()->user();
    $canApproveSignatureException = $exceptionAgreement && $exceptionActor && ($exceptionQuote
        ? $agreements->canApproveQuoteException($exceptionActor, $exceptionQuote)
        : ($exceptionRental && $agreements->canApproveRentalException($exceptionActor, $exceptionRental)));
    $currentSignatureException = $exceptionAgreement && ($exceptionQuote
        ? $agreements->exceptionForQuote($exceptionQuote, $exceptionAgreement)
        : ($exceptionRental && !$exceptionAgreement->signed_at && $agreements->readyFor($exceptionRental, $exceptionAgreement)));
    $canOfferSignatureException = $canApproveSignatureException && !$exceptionAgreement->signed_at && !$currentSignatureException
        && ($exceptionQuote ? $agreements->canIssueForQuote($exceptionQuote)
            : ($exceptionRental->status === 'active' && $agreements->matches($exceptionRental, $exceptionAgreement)));
    $signatureExceptionId = 'signature-exception-'.$exceptionAgreement?->id;
@endphp
@if($exceptionAgreement)
<div class="sw-signature-exception" data-signature-exception>
    @if($currentSignatureException && !$exceptionAgreement->signed_at)
        <p class="sw-exception-approved" data-signature-exception-status><strong>Signature exception approved</strong><br><span>No digital signature was captured.</span></p>
    @endif
    @if($canApproveSignatureException && $exceptionAgreement->exception_approval)
        @php($exceptionAudit = $exceptionAgreement->exception_approval)
        <details data-signature-exception-audit>
            <summary>Signature exception record</summary>
            <p>Approved by <strong>{{ $exceptionAudit['approved_by_name'] }}</strong> · {{ \Illuminate\Support\Carbon::parse($exceptionAudit['approved_at'])->timezone('Asia/Kolkata')->format('d M Y, h:i A') }} IST</p>
            <p class="sw-note">{{ $exceptionAudit['reason'] }}</p>
            @if(!$currentSignatureException && !$exceptionAgreement->signed_at)<p>The earlier exception does not cover the current quotation or rental details.</p>@endif
            @if($exceptionAgreement->signed_at)<p>The customer subsequently provided a digital signature.</p>@endif
        </details>
    @endif
    @if($canOfferSignatureException)
        <details @if($errors->has('exception_reason') || $errors->has('alternative_acceptance_confirmed') || $errors->has('exception_context')) open @endif>
            <summary>Customer unable to digitally sign?</summary>
            <p class="sw-meta">Explain the terms to the customer and record why a digital signature is unavailable. Quotation acceptance is still required.</p>
            <form action="{{ $exceptionQuote ? route('quotations.signature-exception',$exceptionQuote) : route('rentals.signature-exception',$exceptionRental) }}" method="post" data-signature-exception-form>
                @csrf
                <input type="hidden" name="exception_context" value="{{ $agreements->exceptionContext($exceptionAgreement,$exceptionQuote,$exceptionRental) }}">
                @if($exceptionQuote)<input type="hidden" name="lock_version" value="{{ $exceptionQuote->lock_version }}">@endif
                <div class="sw-field"><label for="{{ $signatureExceptionId }}-reason">Reason for signature exception *</label><textarea id="{{ $signatureExceptionId }}-reason" name="exception_reason" required minlength="10" maxlength="1500" rows="2" placeholder="e.g. Customer cannot use the digital signature pad; terms explained and accepted by phone.">{{ old('exception_reason') }}</textarea></div>
                <label class="sw-exception-confirm"><input type="checkbox" name="alternative_acceptance_confirmed" value="1" required @checked(old('alternative_acceptance_confirmed'))> I explained the rental terms and the customer agreed by an alternative method.</label>
                <button type="submit" class="sw-btn sw-small">Approve signature exception</button>
            </form>
        </details>
    @endif
</div>
@endif
@once
<style>
.sw-signature-exception{font-size:12px}.sw-signature-exception p{font-size:12px;line-height:1.5;margin:8px 0}.sw-signature-exception details{border-top:1px solid #e0e7ef;padding-top:10px;margin-top:10px}.sw-signature-exception summary{cursor:pointer;font-weight:650;font-size:12px}.sw-signature-exception form{display:grid;gap:9px;margin-top:10px}.sw-signature-exception textarea{box-sizing:border-box;width:100%;padding:9px;border:1px solid #cbd6e3;border-radius:8px;color:#172b4d;background:white;font:inherit}.sw-signature-exception .sw-exception-confirm{display:flex;gap:8px;align-items:flex-start;font-size:12px;line-height:1.5}.sw-signature-exception .sw-exception-confirm input{flex:0 0 17px;width:17px;height:17px;min-height:17px;padding:0;margin:2px 0 0}.sw-signature-exception form>button{justify-self:start}.sw-exception-approved{padding:8px 10px;border:1px solid #ebd69f;border-radius:8px;background:#fff8e9;color:#705213}.sw-exception-approved span{font-size:11px}
@media(max-width:600px){.sw-signature-exception textarea{font-size:16px}.sw-signature-exception form>button{min-height:42px}}
</style>
@endonce
