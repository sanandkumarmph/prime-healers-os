<div class="swx-attention-list" data-attention-list>
    <p class="swx-attention-guide">One next step per enquiry. Future follow-ups are under “Waiting on customer”.</p>
    <table class="swx-attention-table">
        <thead><tr><th scope="col">#</th><th scope="col">Enquiry / customer</th><th scope="col">Salesperson</th><th scope="col">Needs attention</th><th scope="col">Next action</th></tr></thead>
        <tbody>
        @forelse($records as $enquiry)
            @php($next=$enquiry->attention_action)
            <tr data-attention-row data-enquiry-id="{{ $enquiry->id }}" data-attention-key="{{ $next['key'] }}">
                <td class="swx-attention-number">{{ $records->firstItem()+$loop->index }}</td>
                <td class="swx-attention-contact"><a href="{{ route('enquiries.show',$enquiry) }}">{{ $enquiry->subject }}</a><span>{{ $enquiry->display_name }}@if($enquiry->phone) · <a href="tel:{{ preg_replace('/[^0-9+]/','',$enquiry->phone) }}">{{ $enquiry->phone }}</a>@endif</span><small>Enquiry #{{ $enquiry->id }} · Created @include('partials.action-time',['actionAt'=>$enquiry->created_at,'clockOnly'=>false])</small></td>
                <td class="swx-attention-owner"><strong>{{ $enquiry->owner?->name ?? 'Unassigned' }}</strong><small>{{ $enquiry->source_label }}</small></td>
                <td class="swx-attention-reason"><p data-attention-reason>{{ $next['reason'] }}</p>@if($next['due_at'])<small class="{{ $next['key']==='overdue_followup' ? 'is-overdue' : '' }}">Follow-up {{ \Illuminate\Support\Carbon::parse($next['due_at'])->timezone('Asia/Kolkata')->format('d M, h:i A') }} IST</small>@endif</td>
                <td class="swx-attention-action">@if($next['url'])<a class="sw-btn sw-small" data-attention-action href="{{ $next['url'] }}">{{ $next['label'] }} <span aria-hidden="true">→</span></a>@else<span>{{ $next['reason'] }}</span>@endif</td>
            </tr>
        @empty
            <tr><td colspan="5" class="swx-empty"><h3>No work in this view</h3><p>{{ $hasFilters ? 'Try another action or clear the filters.' : 'Your current enquiries have no outstanding next steps.' }}</p><a href="{{ route('enquiries.index',['view'=>'attention']) }}">View needs attention</a></td></tr>
        @endforelse
        </tbody>
    </table>
</div>
