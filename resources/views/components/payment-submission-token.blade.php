@php
    $paymentSubmissionToken = old('payment_submission_token');
    if (!is_string($paymentSubmissionToken) || !\Illuminate\Support\Str::isUuid($paymentSubmissionToken)) {
        $paymentSubmissionToken = (string) \Illuminate\Support\Str::uuid();
    }
@endphp
<input type="hidden" name="payment_submission_token" value="{{ $paymentSubmissionToken }}">
