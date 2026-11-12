<?php

namespace App\Services\Sales;

use App\Models\{Enquiry, EnquiryFollowUp, Quotation, RentalAgreement, User};

/** One scoped, read-only recommendation shared by record pages and work queues. */
class SalesNextAction
{
    public function for(User $actor, Enquiry $enquiry, ?Quotation $quote = null, ?RentalAgreement $agreement = null, ?EnquiryFollowUp $followUp = null, ?SalesQueueContext $queueContext = null): array
    {
        $result = function(string $key, string $label, ?string $url, string $reason, int $priority = 999, ?string $section = null, bool $attention = true) use (&$followUp) { return [
            'key'=>$key,'label'=>$label,'url'=>$url,'reason'=>$reason,'attention'=>$attention,'priority'=>$priority,
            'section'=>$section,'due_at'=>$followUp?->due_at?->toIso8601String(),
        ]; };
        $none = fn(string $reason) => $result('no_action','',null,$reason,999,null,false);
        if ((int)$actor->organization_id !== (int)$enquiry->organization_id || !$actor->hasActiveAccess()
            || !$actor->canAccessModule('enquiries','read')
            || ($actor->scopeType('enquiries') !== 'all' && (int)$enquiry->owner_id !== (int)$actor->id)) return $none('This enquiry is outside your access.');
        $enquiryUrl = route('enquiries.show',$enquiry);
        $canEdit = $actor->canAccessModule('enquiries','update');
        $canQuote = $actor->canAccessModule('quotations','read')
            && ($actor->scopeType('quotations') === 'all' || (int)$enquiry->owner_id === (int)$actor->id);
        if (!$canQuote) $quote = null;
        elseif (!$quote) $quote = $enquiry->relationLoaded('quotations') ? $enquiry->quotations->sortByDesc('revision')->first() : $enquiry->quotations()->latest('revision')->first();
        if ($quote && ((int)$quote->organization_id !== (int)$enquiry->organization_id || (int)$quote->enquiry_id !== (int)$enquiry->id)) return $none('Review the current enquiry before continuing.');
        if ($quote && $quote->status === 'superseded') return $result('no_action','Open latest quotation',$enquiryUrl.'#enquiry-quotations','This is an earlier revision.',999,'enquiry-quotations',false);
        $agreements = app(RentalAgreements::class);
        $agreement ??= $quote ? ($quote->relationLoaded('rentalAgreement') ? $quote->getRelation('rentalAgreement') : $agreements->quotation($quote)) : null;
        $requiresTerms = $quote?->kind === 'rental' && RentalAgreements::enabled();
        $preparedQueue = $queueContext?->appliesTo($actor,$enquiry,$quote) === true;
        $termsReady = $preparedQueue ? $queueContext->termsReady : (!$requiresTerms || ($agreement && $agreements->readyForQuote($quote,$agreement)));
        if ($preparedQueue) {
            // This constructs a navigation recommendation only. Saving the order still runs every normal guard.
            $module=$quote?->kind.'s';
            $order=$quote && !$enquiry->closed() && $quote->displayStatus()==='accepted' && $termsReady && $canEdit && $canQuote
                && $actor->canAccessModule('quotations','update') && $actor->canAccessModule($module,'read') && $actor->canAccessModule($module,'create')
                ? ['label'=>'Create '.ucfirst($quote->kind),'url'=>route($module.'.create',['quotation_id'=>$quote->id])] : null;
        } else $order = $quote && ($quote->status === 'converted' || ($quote->displayStatus() === 'accepted' && $termsReady)) ? app(SalesWorkspace::class)->orderAction($actor,$enquiry,$quote,$agreement) : null;
        if ($enquiry->closed() || $quote?->status === 'converted') return $order
            ? $result('no_action',$order['label'],$order['url'],'This enquiry has an order.',999,null,false)
            : $none($enquiry->stage === 'lost' ? 'This enquiry is closed as lost.' : 'This enquiry is complete.');
        if (!$canEdit) return $none('You have view access. A sales editor can continue this enquiry.');
        if ($order && $quote?->displayStatus() === 'accepted') return $result('ready_order',$order['label'],$order['url'],'Quotation accepted. Continue with the order.',10);
        if (!$followUp) $followUp = $enquiry->relationLoaded('followUps') ? $enquiry->followUps->whereNull('completed_at')->sortBy('due_at')->first() : $enquiry->followUps()->whereNull('completed_at')->orderBy('due_at')->first();
        // The supplied follow-up must belong to this enquiry and still need work.
        if ($followUp && ((int)$followUp->enquiry_id !== (int)$enquiry->id || $followUp->completed_at)) $followUp = null;
        if ($followUp?->due_at?->isPast()) return $result('overdue_followup','Complete overdue follow-up',$enquiryUrl.'#enquiry-followups','A scheduled follow-up is overdue.',20,'enquiry-followups');
        $canUpdateQuote = $canQuote && $actor->canAccessModule('quotations','update');
        $quoteUrl = $quote ? route('quotations.show',$quote) : null;
        $status = $quote?->displayStatus();
        if ($quote && $canUpdateQuote && $status === 'declined') return $result('review_response','Review customer response',$enquiryUrl.'#enquiry-activity','The customer declined this quotation. Review the response before revising or closing.',30,'enquiry-activity');
        if ($quote && $canUpdateQuote && $status === 'sent' && $requiresTerms && $termsReady) return $result('review_response','Record quotation response',$enquiryUrl.'#enquiry-response','Rental terms completed. Record acceptance only after the customer confirms the quotation.',30,'enquiry-response');
        if ($followUp?->due_at && $followUp->due_at->copy()->timezone('Asia/Kolkata')->isToday()) return $result('followup_today','Complete today’s follow-up',$enquiryUrl.'#enquiry-followups','A follow-up is scheduled for today.',35,'enquiry-followups');
        if ($quote && $canUpdateQuote) {
            if (in_array($status,['sent','accepted'],true) && !$termsReady && ($status === 'accepted' || $quote->shared_at)) return $result('terms_pending','Share Rental Terms',$enquiryUrl.'#rental-agreement','The current rental terms still need a signature or an approved exception.',40,'rental-agreement');
            if ($status === 'sent' && !$quote->shared_at) return $result('share_quote','Share quotation',$quoteUrl.'#quotation-share','The quotation is ready. Send it to the customer.',50,'quotation-share');
            if ($status === 'draft') return $result('draft_quote','Complete quotation',route('quotations.edit',$quote),'Review the products and prices, then prepare the quotation for sharing.',60);
            if (in_array($status,['expired','withdrawn'],true) && $actor->canAccessModule('quotations','create')) return $result('draft_quote','Revise quotation',$quoteUrl.'#quotation-revision','This quotation is '.$status.'. Prepare a new revision to continue.',60,'quotation-revision');
            if ($status === 'accepted') return $none('Quotation accepted. A staff member with order creation access can continue.');
        }
        if (!$quote && $canQuote && $actor->canAccessModule('quotations','create')) return $result('new_enquiry','Create quotation',route('quotations.create',$enquiry),'Confirm the requirements and prepare the customer’s quotation.',70);
        if (!$followUp) return $result('plan_followup','Plan follow-up',$enquiryUrl.'#enquiry-followups','Choose when to contact the customer next.',80,'enquiry-followups');
        return $result('awaiting_response','Review follow-up',$enquiryUrl.'#enquiry-followups','Waiting for the customer. The next follow-up is already scheduled.',90,'enquiry-followups',false);
    }
}
