<?php

namespace App\Services\Sales;

use App\Models\{Enquiry, EnquiryFollowUp, Quotation, RentalAgreement, User};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Scoped SQL classification and pagination; only the displayed page is hydrated. */
class SalesAttentionQueue
{
    public const CATEGORIES = ['all'=>'Needs attention','ready'=>'Ready for order','followups'=>'Follow-ups due',
        'terms'=>'Rental terms','prepare'=>'Prepare / share quotation','response'=>'Customer responses',
        'plan'=>'Plan follow-up','waiting'=>'Waiting on customer'];
    private const KEYS = ['ready'=>['ready_order'],'followups'=>['overdue_followup','followup_today'],
        'terms'=>['terms_pending'],'prepare'=>['share_quote','draft_quote','new_enquiry'],
        'response'=>['review_response'],'plan'=>['plan_followup'],'waiting'=>['awaiting_response']];

    public function page(Builder $visible, User $actor, string $category='all'): array
    {
        $visible=(clone $visible)->whereNotIn('enquiries.stage',['won','lost']);
        $canQuote = $actor->canAccessModule('quotations','read');
        $quoteVisible = Quotation::query()->where('quotations.organization_id',$actor->organization_id)
            ->whereIn('quotations.enquiry_id',(clone $visible)->select('enquiries.id'))
            ->whereNotExists(fn($q)=>$q->selectRaw('1')->from('quotations as newer')
                ->whereColumn('newer.enquiry_id','quotations.enquiry_id')->whereColumn('newer.organization_id','quotations.organization_id')
                ->whereColumn('newer.revision','>','quotations.revision'));
        if (!$canQuote) $quoteVisible->whereRaw('1=0');
        elseif ($actor->scopeType('quotations')!=='all') $quoteVisible->whereIn('enquiry_id',(clone $visible)->where('owner_id',$actor->id)->select('enquiries.id'));

        // valid_until is a date in the immutable quotation timezone, not the server's UTC date.
        // Only boundary dates need PHP timezone evaluation; older dates are certainly expired.
        $floor=now('UTC')->subDay()->toDateString();$ceiling=now('UTC')->addDay()->toDateString();$expired=[];
        (clone $quoteVisible)->whereBetween('valid_until',[$floor,$ceiling])->select(['id','valid_until','snapshot'])
            ->chunkById(100,function($quotes) use(&$expired){foreach($quotes as $quote)if($quote->expired())$expired[]=(int)$quote->id;});
        $expiredSql="(aq.valid_until < '".$floor."' OR aq.id IN (".implode(',',$expired ?: [0]).'))';

        // Encrypted approvals cannot be truthfully classified from a non-null SQL value.
        // Validate only this scoped latest-quotation approval subset, in bounded chunks.
        $exceptions=[];$agreements=app(RentalAgreements::class);
        RentalAgreement::where('organization_id',$actor->organization_id)->whereNotNull('exception_approval')->whereNull('signed_at')
            ->whereIn('quotation_id',(clone $quoteVisible)->select('quotations.id'))->select(['id','organization_id','quotation_id','terms','exception_approval'])
            ->chunkById(100,function($rows)use(&$exceptions,$agreements,$actor){
                $quotes=Quotation::where('organization_id',$actor->organization_id)->whereIn('id',$rows->pluck('quotation_id'))->with('enquiry')->get()->keyBy('id');
                foreach($rows as $row)if(($quote=$quotes->get($row->quotation_id)) && $this->validLatestException($quote,$row,$agreements))$exceptions[]=(int)$quote->id;
            });

        $latest=(clone $quoteVisible)->select(['quotations.id','enquiry_id','status','kind','valid_until','shared_at']);
        $due=EnquiryFollowUp::whereIn('enquiry_id',(clone $visible)->select('enquiries.id'))->whereNull('completed_at')
            ->select('enquiry_id')->selectRaw('MIN(due_at) as first_due')->groupBy('enquiry_id');
        $signed=RentalAgreement::where('organization_id',$actor->organization_id)->whereNotNull('quotation_id')
            ->select('quotation_id')->selectRaw('MAX(signed_at) as signed_at')->groupBy('quotation_id');
        $now=now('UTC')->format('Y-m-d H:i:s');$dayEnd=now('Asia/Kolkata')->addDay()->startOfDay()->utc()->format('Y-m-d H:i:s');
        $termsReady = RentalAgreements::enabled()
            ? "(aq.kind <> 'rental' OR agreement_state.signed_at IS NOT NULL OR aq.id IN (".implode(',',$exceptions ?: [0]).'))' : '1=1';
        $canEdit=$actor->canAccessModule('enquiries','update');$canUpdateQuote=$canQuote && $actor->canAccessModule('quotations','update');
        $canCreateQuote=$canQuote && $actor->canAccessModule('quotations','create');
        $quoteScopeSql=$actor->scopeType('quotations')==='all' ? '1=1' : 'enquiries.owner_id='.(int)$actor->id;
        $orderKinds=[];foreach(['rental','sale'] as $kind)if($canUpdateQuote && $actor->canAccessModule($kind.'s','read') && $actor->canAccessModule($kind.'s','create'))$orderKinds[]="'".$kind."'";
        $canOrder='aq.kind IN ('.implode(',',$orderKinds ?: ["'none'"]).')';
        $case="CASE WHEN ".($canEdit?'0=1':'1=1')." OR aq.status IN ('converted','superseded') THEN 'no_action' "
            ."WHEN aq.status='accepted' AND NOT $expiredSql AND $termsReady AND $canOrder THEN 'ready_order' "
            ."WHEN due.first_due < '$now' THEN 'overdue_followup' "
            .($canUpdateQuote?"WHEN aq.status='declined' ".(RentalAgreements::enabled()?"OR (aq.status='sent' AND NOT $expiredSql AND aq.kind='rental' AND $termsReady) ":'')."THEN 'review_response' ":'')
            ."WHEN due.first_due < '$dayEnd' THEN 'followup_today' "
            .($canUpdateQuote?"WHEN aq.status IN ('sent','accepted') AND NOT $expiredSql AND NOT $termsReady AND (aq.status='accepted' OR aq.shared_at IS NOT NULL) THEN 'terms_pending' "
                ."WHEN aq.status='sent' AND NOT $expiredSql AND aq.shared_at IS NULL THEN 'share_quote' "
                ."WHEN aq.status='draft' ".($canCreateQuote?"OR aq.status='withdrawn' OR (aq.status IN ('sent','accepted') AND $expiredSql) ":'')."THEN 'draft_quote' "
                ."WHEN aq.status='accepted' AND NOT $expiredSql THEN 'no_action' ":'')
            .($canCreateQuote?"WHEN aq.id IS NULL AND $quoteScopeSql THEN 'new_enquiry' ":'')
            ."WHEN due.first_due IS NULL THEN 'plan_followup' ELSE 'awaiting_response' END";
        $classified=(clone $visible)->whereNotIn('enquiries.stage',['won','lost'])
            ->leftJoinSub($latest,'aq',fn($j)=>$j->on('aq.enquiry_id','=','enquiries.id'))
            ->leftJoinSub($due,'due',fn($j)=>$j->on('due.enquiry_id','=','enquiries.id'))
            ->leftJoinSub($signed,'agreement_state',fn($j)=>$j->on('agreement_state.quotation_id','=','aq.id'))
            ->select('enquiries.*')->selectRaw("aq.id AS attention_quote_id, due.first_due AS attention_due_at, $case AS attention_key");
        $grouped=DB::query()->fromSub((clone $classified)->toBase(),'attention_work')->select('attention_key')->selectRaw('COUNT(*) AS records')->groupBy('attention_key')->get()->pluck('records','attention_key');
        $counts=['all'=>0];foreach(self::KEYS as $name=>$keys){$counts[$name]=array_sum(array_map(fn($key)=>(int)($grouped[$key]??0),$keys));if($name!=='waiting')$counts['all']+=$counts[$name];}
        $query=DB::query()->fromSub((clone $classified)->toBase(),'attention_work');
        if($category==='all')$query->whereNotIn('attention_key',['no_action','awaiting_response']);else $query->whereIn('attention_key',self::KEYS[$category] ?? []);
        $priority="CASE attention_key WHEN 'ready_order' THEN 10 WHEN 'overdue_followup' THEN 20 WHEN 'review_response' THEN 30 WHEN 'followup_today' THEN 35 WHEN 'terms_pending' THEN 40 WHEN 'share_quote' THEN 50 WHEN 'draft_quote' THEN 60 WHEN 'new_enquiry' THEN 70 WHEN 'plan_followup' THEN 80 ELSE 90 END";
        $page=$query->orderByRaw($priority)->orderByRaw('CASE WHEN attention_due_at IS NULL THEN 1 ELSE 0 END')->orderBy('attention_due_at')->orderBy('id')->paginate(20)->withQueryString();
        $records=Enquiry::hydrate($page->items());$records->load('owner');
        $quotes=Quotation::where('organization_id',$actor->organization_id)->whereIn('id',$records->pluck('attention_quote_id')->filter())->get()->keyBy('id');
        $pageAgreements=RentalAgreement::where('organization_id',$actor->organization_id)->whereIn('quotation_id',$quotes->keys())->get()->keyBy('quotation_id');
        foreach($records as $enquiry){
            $quote=$quotes->get($enquiry->attention_quote_id);$agreement=$quote ? $pageAgreements->get($quote->id) : null;
            $followUp=$enquiry->attention_due_at ? new EnquiryFollowUp(['enquiry_id'=>$enquiry->id,'due_at'=>\Illuminate\Support\Carbon::parse($enquiry->attention_due_at,'UTC')]) : null;
            $enquiry->setRelation('quotations',collect($quote?[$quote]:[]));$enquiry->setRelation('followUps',collect($followUp?[$followUp]:[]));
            if($quote){$quote->setRelation('enquiry',$enquiry);$quote->setRelation('rentalAgreement',$agreement);}
            $termsReady=!RentalAgreements::enabled() || !$quote || $quote->kind!=='rental' || $agreement?->signed_at || in_array((int)$quote->id,$exceptions,true);
            $context=new SalesQueueContext((int)$actor->organization_id,(int)$actor->id,(int)$enquiry->id,$quote?(int)$quote->id:null,(bool)$termsReady);
            $action=app(SalesNextAction::class)->for($actor,$enquiry,$quote,$agreement,$followUp,$context);
            $enquiry->setAttribute('attention_action',$action);
        }
        $page->setCollection($records);
        return ['records'=>$page,'attentionCounts'=>$counts,'attentionCategory'=>$category];
    }

    private function validLatestException(Quotation $quote,RentalAgreement $agreement,RentalAgreements $agreements): bool
    {
        // The candidate subquery already proved this is the latest scoped revision. Validate all remaining
        // immutable approval facts here without a newer-revision query per row. Execution guards are unchanged.
        if ((int)$agreement->organization_id!==(int)$quote->organization_id || (int)$agreement->quotation_id!==(int)$quote->id
            || !$agreements->hasException($agreement) || !$agreements->canIssueForQuote($quote)) return false;
        $approval=$agreement->exception_approval;
        return (int)($approval['quotation_id']??0)===(int)$quote->id
            && hash_equals((string)($approval['quotation_snapshot_sha256']??''),hash('sha256',json_encode($quote->snapshot,JSON_THROW_ON_ERROR)));
    }
}
