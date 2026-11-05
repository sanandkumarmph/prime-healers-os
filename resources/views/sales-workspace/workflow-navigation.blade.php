@php
    $enquiryLink = ($workflowOnEnquiry ?? false) ? '' : route('enquiries.show', $workflowEnquiry);
    $steps = [
        'enquiry' => ['1. Enquiry', $enquiryLink.'#enquiry-details'],
        'quotation' => ['2. Quotation', $workflowQuotationUrl],
        'response' => ['3. Response', $enquiryLink.'#enquiry-response'],
        'order' => ['4. Order', $workflowOrderUrl ?? $enquiryLink.'#enquiry-next'],
    ];
@endphp
<nav aria-label="Sales progress">
    <ol class="sw-stage">
        @foreach($steps as $step => [$label, $url])
            <li><a href="{{ $url }}" @if($workflowStep === $step) aria-current="step" @endif>{{ $label }}</a></li>
        @endforeach
    </ol>
</nav>
