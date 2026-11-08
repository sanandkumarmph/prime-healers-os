@extends('layouts.app')
@section('content')
@include('sales-workspace.style')
@include('sales-workspace.details-style')
@php
    $quotationUrl = $latestQuote ? route('quotations.show', $latestQuote)
        : ($canCreateQuote ? route('quotations.create', $enquiry) : '#enquiry-quotations');
    $progress = match (true) {
        $orderAction !== null || $enquiry->stage === 'won' => 'order',
        in_array($enquiry->stage, ['quoted', 'accepted'], true) => 'response',
        $latestQuote !== null => 'quotation',
        default => 'enquiry',
    };

@endphp
<div class="sw sw-detail" data-enquiry-detail @if($latestQuote) data-quote-live="{{ $latestQuote->id }}" data-live-page="enquiry" data-live-status-url="{{ route('quotations.status',$latestQuote) }}" @endif>
    <a class="swd-back" href="{{ route('enquiries.index') }}">← Sales workspace</a>
    <header class="sw-head swd-header">
        <div class="swd-heading">
            <div class="swd-identity"><span class="sw-eyebrow">Enquiry #{{ $enquiry->id }}</span><span class="sw-badge" data-live-enquiry-stage data-status="{{ $enquiry->stage }}">{{ \App\Models\Enquiry::STAGES[$enquiry->stage] }}</span></div>
            <h1 class="sw-wrap">{{ $enquiry->subject }}</h1>
            <p class="sw-meta">{{ $enquiry->name }} <span aria-hidden="true">·</span> Owner: <strong>{{ $enquiry->owner?->name ?? 'Unassigned' }}</strong></p>
        </div>
        <div class="sw-actions">
            @if($canEdit && !$enquiry->closed())<a class="sw-btn" href="{{ route('enquiries.edit',$enquiry) }}">Edit details</a>@endif
            <span data-live-quote-header>
            @if($orderAction)
                <a class="sw-btn sw-primary" href="{{ $orderAction['url'] }}">{{ $orderAction['label'] }}</a>
            @elseif($canUpdateQuote && $quoteStatus === 'sent' && app(\App\Services\Sales\RentalAgreements::class)->readyForQuote($latestQuote,$agreement) && !$enquiry->closed())
                <a class="sw-btn sw-primary" href="#enquiry-response">Record quotation response</a>
            @elseif($canCreateQuote && !$latestQuote)
                <a class="sw-btn sw-primary" href="{{ $quotationUrl }}">Create quotation</a>
            @elseif($latestQuote && !$enquiry->closed())
                <a class="sw-btn sw-primary" href="{{ $quotationUrl }}">Open latest quotation</a>
            @endif
            </span>
        </div>
    </header>
    @include('sales-workspace.alerts', ['showSuccess'=>true])
    @if($latestQuote)@include('sales-workspace.live-status',['liveQuote'=>$latestQuote,'liveAgreement'=>$agreement])@endif
    @if($enquiry->stage==='lost')<div class="sw-alert sw-error"><strong>Closed as lost</strong><p>{{ $enquiry->lost_reason }}</p></div>@endif
    <div class="swd-progress">
    @include('sales-workspace.workflow-navigation', [
        'workflowEnquiry' => $enquiry, 'workflowQuotationUrl' => $quotationUrl, 'workflowOrderUrl' => $orderAction['url'] ?? null,
        'workflowOnEnquiry' => true, 'workflowStep' => $progress,
    ])
    <p class="sw-guide" id="enquiry-next" data-live-quote-next tabindex="-1">
        <strong>Next:</strong>
        @if($enquiry->closed())
            {{ $orderAction ? 'Open the order from the Order step.' : 'Review the enquiry history below.' }}
        @elseif($orderAction)
            The quotation is ready. Select {{ $orderAction['label'] }} to open the prefilled order.
        @elseif(!$latestQuote)
            Confirm the requirements and prepare a quotation.
        @elseif($quoteStatus === 'draft')
            Complete the quotation and review it before sharing.
        @elseif($quoteStatus === 'expired')
            Create a quotation revision with a new validity date.
        @elseif($quoteStatus === 'accepted' && $requiresSignature && !app(\App\Services\Sales\RentalAgreements::class)->readyForQuote($latestQuote,$agreement))
            Share the rental agreement and collect the signature before creating the order.
        @elseif($quoteStatus === 'accepted')
            A staff member with order creation access can create the order.
        @elseif($quoteStatus === 'sent' && app(\App\Services\Sales\RentalAgreements::class)->readyForQuote($latestQuote,$agreement))
            {{ $agreement?->signed_at ? "The rental terms are signed. Record the customer's quotation acceptance to continue." : "A signature exception is approved. Record the customer's quotation acceptance to continue." }}
        @elseif($quoteStatus === 'sent')
            {{ $latestQuote->shared_at ? 'Follow up and record the customer response.' : 'Open the quotation and share it with the customer.' }}
        @else
            Open the latest quotation to review the response or prepare a revision.
        @endif
    </p>
    </div>
    <div class="swd-grid">
        <aside class="swd-contact-column">
            <section class="sw-panel" id="enquiry-details" tabindex="-1">
                <h2>Contact & requirement</h2>
                <strong class="swd-customer">{{ $enquiry->name }}</strong>
                <div class="swd-contact-links">
                    @if($enquiry->phone)<a href="tel:{{ preg_replace('/[^0-9+]/','',$enquiry->phone) }}">{{ $enquiry->phone }}</a>@endif
                    @if($enquiry->email)<a href="mailto:{{ $enquiry->email }}">{{ $enquiry->email }}</a>@endif
                </div>
                <dl class="swd-facts" data-record-timestamps>
                    <div><dt>Source</dt><dd>{{ $enquiry->source_label ?: 'Not specified' }}</dd></div>
                    <div><dt>Customer</dt><dd>{{ $enquiry->customer?->name ?? 'Not linked yet' }}</dd></div>
                    <div><dt>Created</dt><dd>@include('partials.action-time',['actionAt'=>$enquiry->created_at,'clockOnly'=>false])</dd></div>
                    <div><dt>Last updated</dt><dd>@include('partials.action-time',['actionAt'=>$enquiry->updated_at,'clockOnly'=>false])</dd></div>
                </dl>
                <div class="swd-requirement"><h3>Requirement</h3><p class="sw-note">{{ $enquiry->requirements ?: 'No additional requirements recorded.' }}</p></div>
            </section>
            <section class="sw-panel" id="enquiry-activity" tabindex="-1">
                <h2>Activity</h2>
                @if($canEdit)<form class="swd-note-form" action="{{ route('enquiries.note',$enquiry) }}" method="post">@csrf
                    <div class="sw-field"><label for="note">Add an internal note</label><textarea name="note" id="note" rows="2" required maxlength="5000" placeholder="Keep the next person up to date">{{ old('note') }}</textarea></div><button class="sw-btn sw-small" type="submit">Add note</button>
                </form>@endif
                <details data-detail-history @if(request()->filled('page')) open @endif><summary>Activity history <span class="swd-count">{{ $activities->total() }}</span></summary>
                    <ol class="sw-timeline">@forelse($activities as $activity)<li><p>{{ $activity->body }}</p><div class="sw-meta">{{ $activity->user?->name ?? 'Customer response' }} · {{ $activity->created_at?->copy()->timezone('Asia/Kolkata')->format('d M Y, h:i:s A \I\S\T') }}</div></li>@empty<li class="sw-meta">No activity recorded yet.</li>@endforelse</ol>{{ $activities->links() }}
                </details>
            </section>
            @if($canEdit && $enquiry->stage!=='won')
            <section class="sw-panel swd-stage-panel"><details @if($errors->has('stage') || $errors->has('reason')) open @endif>
                <summary>Update stage / close enquiry</summary>
                <p class="sw-meta">Quotation and order stages update automatically. Withdraw an active quotation before closing or reopening the enquiry.</p>
                <form action="{{ route('enquiries.stage',$enquiry) }}" method="post">@csrf
                    <input type="hidden" name="lock_version" value="{{ $enquiry->lock_version }}">
                    <div class="sw-field"><label for="next-stage">Stage</label><select name="stage" id="next-stage"><option value="qualified" @selected(old('stage')==='qualified')>Qualified</option><option value="new" @selected(old('stage')==='new')>New / reopen</option><option value="lost" @selected(old('stage')==='lost')>Lost</option></select></div>
                    <div class="sw-field"><label for="reason">Reason (required when lost)</label><textarea name="reason" id="reason" rows="2" maxlength="2000">{{ old('reason') }}</textarea></div>
                    <button class="sw-btn sw-small" type="submit">Update stage</button>
                </form>
            </details></section>
            @endif
        </aside>
        <div class="swd-main-column" role="region" aria-label="Enquiry actions">
            <section class="sw-panel" id="enquiry-response" tabindex="-1">
                <div class="swd-section-heading"><h2>Quotation response</h2>@if($latestQuote)<a href="{{ $quotationUrl }}">Open quotation →</a>@endif</div>
                @if($latestQuote)
                    <div class="swd-quote-summary">
                        <div><strong>{{ $latestQuote->number() }}</strong><span class="sw-meta">{{ ucfirst($latestQuote->kind) }} · {{ $latestQuote->snapshot['currency'] }} {{ number_format($latestQuote->snapshot['total'],2) }}</span></div>
                        <div><span class="sw-badge" data-live-quote-label data-status="{{ $quoteStatus }}">{{ $quoteStatus==='sent' ? 'Awaiting quotation acceptance' : $latestQuote->statusLabel() }}</span><span class="sw-meta">Valid until {{ $latestQuote->valid_until->format('d M Y') }}</span></div>
                    </div>
                    <div data-live-response>
                    @if($canUpdateQuote && $quoteStatus==='sent' && !$enquiry->closed())
                        @include('sales-workspace.quotation-response',['quote'=>$latestQuote,'returnToEnquiry'=>true,'showResponseForm'=>app(\App\Services\Sales\RentalAgreements::class)->readyForQuote($latestQuote,$agreement)])
                    @endif
                    </div>
                @else
                    <p class="sw-muted">The quotation response will appear here.</p>
                @endif
            </section>
            @include('sales-workspace.enquiry-agreement',['quote'=>$latestQuote])
            <section class="sw-panel" id="enquiry-followups" tabindex="-1">
                <div class="swd-section-heading"><h2>Follow-ups <span class="swd-count">{{ $followUps->count() }}</span></h2><span class="sw-meta">Optional</span></div>
                @if($enquiry->closed())<p class="sw-muted">This enquiry is closed. Its conversations and quotations remain available below.</p>
                @elseif($followUps->isEmpty())<p class="sw-meta">No follow-up scheduled.</p>@endif
                @foreach($followUps as $task)
                    <article class="swd-follow-up">
                        <div><strong>{{ $task->subject }}</strong><div class="sw-meta {{ $task->due_at->isPast() ? 'sw-overdue' : '' }}">{{ ucfirst($task->channel) }} · {{ $task->due_at->timezone($tz)->format('d M Y, h:i A') }} IST {{ $task->due_at->isPast() ? '· Overdue' : '' }}</div></div>
                        @if($canEdit)<details @if($errors->has('outcome') || $errors->has('completion_note')) open @endif><summary>Complete follow-up</summary>
                            <form action="{{ route('enquiries.follow-up.complete',[$enquiry,$task]) }}" method="post">@csrf
                                <div class="sw-fields"><div class="sw-field"><label for="outcome-{{ $task->id }}">Outcome</label><select name="outcome" id="outcome-{{ $task->id }}" required>@foreach(['connected'=>'Connected','no_response'=>'No response','requested_revision'=>'Revision requested','cancelled'=>'Cancelled'] as $key=>$label)<option value="{{ $key }}" @selected(old('outcome')===$key)>{{ $label }}</option>@endforeach</select></div>
                                <div class="sw-field"><label for="completion-{{ $task->id }}">What happened?</label><textarea id="completion-{{ $task->id }}" name="completion_note" required maxlength="3000" rows="2">{{ old('completion_note') }}</textarea></div></div>
                                <button class="sw-btn sw-small" type="submit">Save outcome</button>
                            </form>
                        </details>@endif
                    </article>
                @endforeach
                @if($canEdit && !$enquiry->closed())@include('sales-workspace.follow-up-form')@endif
            </section>
            <section class="sw-panel swd-history" id="enquiry-quotations" tabindex="-1"><details data-detail-history>
                <summary>Quotations <span class="swd-count">{{ $quotes->count() }}</span></summary>
                @forelse($quotes as $q)<div class="sw-row"><div><a href="{{ route('quotations.show',$q) }}"><strong>{{ $q->number() }}</strong></a><div class="sw-meta">{{ ucfirst($q->kind) }} · {{ $q->snapshot['currency'] }} {{ number_format($q->snapshot['total'],2) }} · Valid until {{ $q->valid_until->format('d M Y') }}</div></div><span class="sw-badge" data-status="{{ $q->displayStatus() }}">{{ $q->statusLabel() }}</span></div>
                @empty<p class="sw-muted">Create a quotation when the requirements are clear. It will not reserve stock.</p>@endforelse
            </details></section>

        </div>
    </div>
</div>
<script>
(() => {
    const reveal = () => {
        let id; try { id = decodeURIComponent(location.hash.slice(1)); } catch { return; }
        const target = document.getElementById(id);
        if (!target || !target.closest('[data-enquiry-detail]')) return;
        for (let el = target.parentElement; el; el = el.parentElement) if (el.tagName === 'DETAILS') el.open = true;
        if (id === 'enquiry-response' || id === 'enquiry-quotations' || id === 'enquiry-activity') {
            const details = target.querySelector('details'); if (details) details.open = true;
        }
    };
    document.querySelectorAll('[data-enquiry-detail] a[href^="#"]').forEach(link => link.addEventListener('click', () => setTimeout(reveal, 0)));
    addEventListener('hashchange', reveal); reveal();
})();
</script>
@endsection
