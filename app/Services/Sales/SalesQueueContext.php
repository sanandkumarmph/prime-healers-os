<?php

namespace App\Services\Sales;

use App\Models\{Enquiry,Quotation,User};

/** Request-local recommendation facts; never used by conversion, signing or dispatch guards. */
final readonly class SalesQueueContext
{
    public function __construct(public int $organizationId,public int $actorId,public int $enquiryId,public ?int $quotationId,public bool $termsReady) {}
    public function appliesTo(User $actor,Enquiry $enquiry,?Quotation $quote): bool
    {
        return $this->organizationId===(int)$actor->organization_id && $this->organizationId===(int)$enquiry->organization_id
            && $this->actorId===(int)$actor->id && $this->enquiryId===(int)$enquiry->id
            && $this->quotationId===($quote?(int)$quote->id:null)
            && (!$quote || ((int)$quote->organization_id===$this->organizationId && (int)$quote->enquiry_id===$this->enquiryId));
    }
}
