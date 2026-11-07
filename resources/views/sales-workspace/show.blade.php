@extends('layouts.app')
@section('content')
@include('sales-workspace.style')
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
<div class="sw">
    <a href="{{ route('enquiries.index') }}">← Sales workspace</a>
    <header class="sw-head" style="margin-top:20px">
        <div>
            <div class="sw-eyebrow">Enquiry #{{ $enquiry->id }}</div>
            <h1 class="sw-wrap">{{ $enquiry->subject }}</h1>
            <p class="sw-muted">{{ $enquiry->name }} · Assigned to {{ $enquiry->owner?->name ?? 'Unassigned' }}</p>
        </div>
        <div class="sw-actions">
            @if($canEdit && !$enquiry->closed())
                <a class="sw-btn" href="{{ route('enquiries.edit', $enquiry) }}">Edit details</a>
            @endif
            @if($orderAction)
                <a class="sw-btn sw-primary" href="{{ $orderAction['url'] }}">{{ $orderAction['label'] }}</a>
            @elseif($canUpdateQuote && $quoteStatus === 'sent' && $agreement?->signed_at && !$enquiry->closed())
                <a class="sw-btn sw-primary" href="#enquiry-response">Record quotation response</a>
            @elseif($canCreateQuote && !$latestQuote)
                <a class="sw-btn sw-primary" href="{{ $quotationUrl }}">Create quotation</a>
            @elseif($latestQuote && !$enquiry->closed())
                <a class="sw-btn sw-primary" href="{{ $quotationUrl }}">Open latest quotation</a>
            @endif
        </div>
    </header>
    @include('sales-workspace.alerts')
    @if($enquiry->stage === 'lost')
        <div class="sw-alert"><strong>Closed as lost</strong><p>{{ $enquiry->lost_reason }}</p></div>
    @endif
    @include('sales-workspace.workflow-navigation', [
        'workflowEnquiry' => $enquiry, 'workflowQuotationUrl' => $quotationUrl, 'workflowOrderUrl' => $orderAction['url'] ?? null,
        'workflowOnEnquiry' => true, 'workflowStep' => $progress,
    ])
    <p class="sw-guide" id="enquiry-next" tabindex="-1">
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
        @elseif($quoteStatus === 'accepted' && $requiresSignature && !$agreement?->signed_at)
            Share the rental agreement and collect the signature before creating the order.
        @elseif($quoteStatus === 'accepted')
            A staff member with order creation access can create the order.
        @elseif($quoteStatus === 'sent' && $agreement?->signed_at)
            The rental terms are signed. Record the customer's quotation acceptance to continue.
        @elseif($quoteStatus === 'sent')
            {{ $latestQuote->shared_at ? 'Follow up and record the customer response.' : 'Open the quotation and share it with the customer.' }}
        @else
            Open the latest quotation to review the response or prepare a revision.
        @endif
    </p>
    <div class="sw-grid"><div>
        <section class="sw-panel" id="enquiry-response" tabindex="-1">
            <h2>Quotation response</h2>
            @if($latestQuote)
                <p>{{ $latestQuote->number() }} · <strong>{{ $quoteStatus === 'sent' ? 'Awaiting quotation acceptance' : $latestQuote->statusLabel() }}</strong></p>
                @if($canUpdateQuote && $quoteStatus === 'sent' && !$enquiry->closed())
                    @include('sales-workspace.quotation-response', [
                        'quote' => $latestQuote, 'returnToEnquiry' => true, 'showResponseForm' => (bool) $agreement?->signed_at,
                    ])
                @endif
            @else
                <p class="sw-muted">The quotation response will appear here.</p>
            @endif
        </section>
        @include('sales-workspace.enquiry-agreement', ['quote' => $latestQuote])
<section class="sw-panel" id="enquiry-followups" tabindex="-1"><h2>Follow-ups <span class="sw-meta">Optional</span></h2>@if($enquiry->closed())<p class="sw-muted">This enquiry is closed. Its conversations and quotations remain available below.</p>@elseif($followUps->isEmpty())<p class="sw-meta">No follow-up scheduled.</p>@endif
@foreach($followUps as $task)<article class="sw-row"><div><strong>{{ $task->subject }}</strong><div class="sw-meta {{ $task->due_at->isPast() ? 'sw-overdue' : '' }}">{{ ucfirst($task->channel) }} · {{ $task->due_at->timezone($tz)->format('d M Y, H:i') }} {{ $tz }} {{ $task->due_at->isPast() ? '· Overdue' : '' }}</div>
@if($canEdit)<details><summary>Complete follow-up</summary><form action="{{ route('enquiries.follow-up.complete',[$enquiry,$task]) }}" method="post">@csrf<div class="sw-fields"><div class="sw-field"><label for="outcome-{{ $task->id }}">Outcome</label><select name="outcome" id="outcome-{{ $task->id }}" required><option value="connected">Connected</option><option value="no_response">No response</option><option value="requested_revision">Revision requested</option><option value="cancelled">Cancelled</option></select></div><div class="sw-field"><label for="completion-{{ $task->id }}">What happened?</label><textarea id="completion-{{ $task->id }}" name="completion_note" required maxlength="3000" rows="2"></textarea></div></div><button class="sw-btn sw-small" style="margin-top:10px">Save outcome</button></form></details>@endif</div></article>@endforeach
@if($canEdit && !$enquiry->closed())@include('sales-workspace.follow-up-form')@endif</section>
<section class="sw-panel" id="enquiry-quotations" tabindex="-1"><h2>Quotations</h2>@forelse($quotes as $q)<div class="sw-row"><div><a href="{{ route('quotations.show',$q) }}"><strong>{{ $q->number() }}</strong></a><div class="sw-meta">{{ ucfirst($q->kind) }} · {{ $q->snapshot['currency'] }} {{ number_format($q->snapshot['total'],2) }} · Valid until {{ $q->valid_until->format('d M Y') }}</div></div><span class="sw-badge" data-status="{{ $q->displayStatus() }}">{{ $q->statusLabel() }}</span></div>@empty<p class="sw-muted">Create a quotation when the requirements are clear. It will not reserve stock.</p>@endforelse</section>
<section class="sw-panel"><h2>Activity</h2>@if($canEdit)<form action="{{ route('enquiries.note',$enquiry) }}" method="post" style="margin-bottom:24px">@csrf<div class="sw-field"><label for="note">Add an internal note</label><textarea name="note" id="note" rows="2" required maxlength="5000" placeholder="Keep the next person up to date">{{ old('note') }}</textarea></div><button class="sw-btn sw-small" style="margin-top:10px">Add note</button></form>@endif
<ol class="sw-timeline">@foreach($activities as $activity)<li><p>{{ $activity->body }}</p><div class="sw-meta">{{ $activity->user?->name ?? 'Customer response' }} · {{ $activity->created_at?->copy()->timezone('Asia/Kolkata')->format('d M Y, h:i:s A \I\S\T') }}</div></li>@endforeach</ol>{{ $activities->links() }}</section></div>
<aside><section class="sw-panel" id="enquiry-details" tabindex="-1"><h2>Contact & requirement</h2><p><strong>{{ $enquiry->name }}</strong></p>@if($enquiry->phone)<p class="sw-wrap">{{ $enquiry->phone }}</p>@endif @if($enquiry->email)<p class="sw-wrap">{{ $enquiry->email }}</p>@endif<p class="sw-meta">Source: {{ $enquiry->source_label ?: 'Not specified' }}</p><p class="sw-meta">Customer: {{ $enquiry->customer?->name ?? 'Not linked yet' }}</p><hr style="border:0;border-top:1px solid #e0e7ef;margin:18px 0"><p class="sw-note">{{ $enquiry->requirements ?: 'No additional requirements recorded.' }}</p></section>
@if($canEdit && $enquiry->stage!=='won')<section class="sw-panel"><details @if($errors->has('stage') || $errors->has('reason')) open @endif><summary>Update stage / close enquiry</summary><p class="sw-meta">Quotation and order stages update automatically. Withdraw an active quotation before closing or reopening the enquiry.</p><form action="{{ route('enquiries.stage',$enquiry) }}" method="post">@csrf<input type="hidden" name="lock_version" value="{{ $enquiry->lock_version }}"><div class="sw-field"><label for="next-stage">Stage</label><select name="stage" id="next-stage"><option value="qualified">Qualified</option><option value="new">New / reopen</option><option value="lost">Lost</option></select></div><div class="sw-field" style="margin-top:12px"><label for="reason">Reason (required when lost)</label><textarea name="reason" id="reason" rows="2" maxlength="2000">{{ old('reason') }}</textarea></div><button class="sw-btn" style="margin-top:12px">Update stage</button></form></details></section>@endif</aside></div></div>

@include('partials.record-timestamps', ['timestampRecord' => $enquiry, 'timestampFields' => ['created_at' => 'Created', 'updated_at' => 'Last updated']])
@endsection
