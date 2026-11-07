@extends('layouts.app')
@section('content')
@include('sales-workspace.style')
@include('sales-workspace.index-style')
@php
    $filterUrl = function (array $changes = []) {
        $query = array_merge(request()->except('page'), $changes);
        return route('enquiries.index', array_filter($query, fn ($value) => $value !== '' && $value !== null));
    };
    $sharedFilters = request()->only(['q', 'mine', 'owner_id', 'source', 'from_date', 'to_date']);
    $advancedCount = collect(request()->only(['owner_id', 'source', 'from_date', 'to_date']))->filter(fn ($value) => $value !== '' && $value !== null)->count();
    $hasFilters = collect(request()->only(['q', 'mine', 'owner_id', 'source', 'from_date', 'to_date', 'stage', 'queue']))->filter(fn ($value) => $value !== '' && $value !== null && $value !== 'all')->isNotEmpty();
    $viewLabel = ['enquiries'=>'Enquiries', 'followups'=>'Follow-ups', 'quotations'=>'Quotations'][$mode];
    $listLabel = $mode === 'enquiries' ? ['open'=>'Open enquiries', 'all'=>'All enquiries', 'no_followup'=>'Needs a follow-up plan'][$focus] : $viewLabel;
@endphp
<div class="sw sw-index" data-sales-workspace-index>
    <header class="sw-head swx-header">
        <div><h1>Sales workspace</h1><p class="sw-muted">Track conversations and keep the next step clear.</p></div>
        @if(auth()->user()->canAccessModule('enquiries','create'))<a class="sw-btn sw-primary" href="{{ route('enquiries.create') }}">+ New enquiry</a>@endif
    </header>
    @include('sales-workspace.alerts')
    <div class="swx-metrics" aria-label="Sales work queues">
        <a class="swx-metric" href="{{ route('enquiries.index', ['focus'=>'open']) }}"><span><span class="swx-metric-label">Open enquiries</span><small>Active conversations</small></span><strong>{{ $counts['open'] }}</strong></a>
        <a class="swx-metric" href="{{ route('enquiries.index', ['view'=>'followups','queue'=>'today']) }}"><span><span class="swx-metric-label">Follow-ups today</span><small>Scheduled for today</small></span><strong>{{ $counts['today'] }}</strong></a>
        <a class="swx-metric swx-metric-urgent" href="{{ route('enquiries.index', ['view'=>'followups','queue'=>'overdue']) }}"><span><span class="swx-metric-label">Overdue follow-ups</span><small>Needs attention</small></span><strong>{{ $counts['overdue'] }}</strong></a>
    </div>
    <section class="swx-workspace">
        <nav class="swx-tabs" aria-label="Sales workspace views">
            @foreach(['enquiries'=>'Enquiries','followups'=>'Follow-ups','quotations'=>'Quotations'] as $key=>$label)
                @if($key !== 'quotations' || auth()->user()->canAccessModule('quotations','read'))
                <a href="{{ route('enquiries.index', $sharedFilters + ['view'=>$key]) }}" @if($mode===$key) aria-current="page" @endif>{{ $label }}</a>
                @endif
            @endforeach
        </nav>
        <form class="swx-filters" id="sales-workspace-filters" method="get" action="{{ route('enquiries.index') }}" aria-label="Sales workspace filters">
            <input type="hidden" name="view" value="{{ $mode }}">
            @if($mode==='enquiries')<input type="hidden" name="focus" value="{{ $focus }}">@endif
            <div class="sw-field swx-search"><label for="sw-search">Search {{ strtolower($viewLabel) }}</label><input id="sw-search" name="q" value="{{ request('q') }}" placeholder="Contact, phone or requirement" maxlength="100" type="search"></div>
            @if($mode==='followups')
            <div class="sw-field"><label for="queue">Due</label><select name="queue" id="queue">@foreach(['all'=>'All upcoming','today'=>'Today','overdue'=>'Overdue'] as $key=>$label)<option value="{{ $key }}" @selected(request('queue','all')===$key)>{{ $label }}</option>@endforeach</select></div>
            @else
            <div class="sw-field"><label for="stage">{{ $mode==='quotations' ? 'Enquiry stage' : 'Stage' }}</label><select name="stage" id="stage"><option value="">All stages</option>@foreach(\App\Models\Enquiry::STAGES as $key=>$label)<option value="{{ $key }}" @selected(request('stage')===$key)>{{ $label }}</option>@endforeach</select></div>
            @endif
            <div class="sw-field"><label for="sw-sort">Sort</label><select name="sort" id="sw-sort">@foreach(($mode==='followups' ? ['due'=>'Due soonest'] : []) + ['newest'=>'Newest created','oldest'=>'Oldest created'] + ($mode!=='followups' ? ['updated'=>'Recently updated'] : []) as $key=>$label)<option value="{{ $key }}" @selected($sort===$key)>{{ $label }}</option>@endforeach</select></div>
            <label class="swx-mine"><input type="checkbox" id="sw-mine" name="mine" value="1" @checked(request()->boolean('mine') && !request()->filled('owner_id'))> Assigned to me</label>
            <button class="sw-btn swx-apply" type="submit">Apply</button>
            <details class="swx-advanced" @if($advancedCount) open @endif>
                <summary>More filters @if($advancedCount)<span class="swx-filter-count">{{ $advancedCount }}</span>@endif</summary>
                <div class="swx-advanced-fields">
                    <div class="sw-field"><label for="sw-owner">Salesperson</label><select id="sw-owner" name="owner_id"><option value="">All visible people</option>@foreach($owners as $owner)<option value="{{ $owner->id }}" @selected((string)request('owner_id')===(string)$owner->id)>{{ $owner->name }}</option>@endforeach</select></div>
                    <div class="sw-field"><label for="sw-source">Enquiry source</label><select id="sw-source" name="source"><option value="">All sources</option>@foreach(\App\Models\Enquiry::SOURCES as $key=>$label)<option value="{{ $key }}" @selected(request('source')===$key)>{{ $label }}</option>@endforeach</select></div>
                    <div class="sw-field"><label for="sw-from">Created from (IST)</label><input type="date" id="sw-from" name="from_date" value="{{ request('from_date') }}"></div>
                    <div class="sw-field"><label for="sw-to">Created to (IST)</label><input type="date" id="sw-to" name="to_date" value="{{ request('to_date') }}"></div>
                </div>
            </details>
            @if($hasFilters)<a class="swx-reset" href="{{ route('enquiries.index',['view'=>$mode]) }}">Clear filters</a>@endif
        </form>
        <div class="swx-list-head">
            <div><h2>{{ $listLabel }}</h2><span class="swx-result-count">{{ $records->total() }} {{ $records->total()===1 ? 'record' : 'records' }}</span></div>
            @if($mode==='enquiries')<nav class="swx-focus" aria-label="Enquiry priorities">@foreach(['open'=>'Open','no_followup'=>'No follow-up','all'=>'All'] as $key=>$label)<a href="{{ $filterUrl(['focus'=>$key,'stage'=>null]) }}" @if($focus===$key) aria-current="page" @endif>{{ $label }}</a>@endforeach</nav>@endif
        </div>
        <div class="swx-list-shell">
            <table class="swx-table">
                <thead><tr><th scope="col" class="swx-num">#</th><th scope="col" class="swx-contact">{{ $mode==='followups' ? 'Follow-up / contact' : ($mode==='quotations' ? 'Quotation / contact' : 'Enquiry / contact') }}</th><th scope="col" class="swx-owner">Salesperson / source</th><th scope="col" class="swx-created">Created (IST)</th><th scope="col" class="swx-stage">{{ $mode==='followups' ? 'Channel' : 'Stage' }}</th><th scope="col" class="swx-next">Next step</th></tr></thead>
                <tbody>
                @forelse($records as $record)
                    @php
                        $e = $mode==='enquiries' ? $record : $record->enquiry;
                        $created = \App\Support\ActionTimestamp::ist($record->created_at);
                        $dueAt = $mode==='followups' ? $record->due_at?->timezone($tz) : ($mode==='enquiries' && $e->next_follow_up ? \Illuminate\Support\Carbon::parse($e->next_follow_up, 'UTC')->timezone($tz) : null);
                        $target = $mode==='quotations' ? route('quotations.show',$record) : route('enquiries.show',$e);
                        $action = 'Open enquiry'; $actionUrl = $target;
                        if ($mode==='followups') { $action='Open follow-up'; $actionUrl.='#enquiry-followups'; }
                        elseif ($mode==='quotations') $action='Open quotation';
                        elseif ($e->stage==='accepted') { $action='Continue to order'; $actionUrl.='#enquiry-next'; }
                        elseif ($e->stage==='quoted') { $action='Review quotation'; $actionUrl.='#enquiry-quotations'; }
                        elseif (!$e->closed() && !$dueAt) { $action='Plan follow-up'; $actionUrl.='#enquiry-followups'; }
                    @endphp
                    <tr class="swx-record" data-record-id="{{ $record->id }}">
                        <td class="swx-num" data-label="#">{{ $records->firstItem() + $loop->index }}</td>
                        <td class="swx-contact"><a class="swx-subject" href="{{ $target }}">{{ $mode==='quotations' ? $record->number().' · '.$e->subject : ($mode==='followups' ? $record->subject : $e->subject) }}</a><div class="swx-contact-meta"><span>{{ $e->name }}</span>@if($e->phone)<a href="tel:{{ preg_replace('/[^0-9+]/','',$e->phone) }}">{{ $e->phone }}</a>@endif</div></td>
                        <td class="swx-owner" data-label="Salesperson / source"><strong>{{ $e->owner?->name ?? 'Unassigned' }}</strong><span class="swx-secondary">{{ $e->source_label }}</span></td>
                        <td class="swx-created" data-label="Created (IST)">@if($created)<time datetime="{{ $created->toIso8601String() }}"><strong>{{ $created->format('d M Y') }}</strong><span class="swx-secondary">{{ $created->format('h:i A') }} IST</span></time>@else<span class="swx-secondary">Not recorded</span>@endif</td>
                        <td class="swx-stage">@if($mode==='followups')<span class="sw-badge">{{ ucfirst($record->channel) }}</span>@elseif($mode==='quotations')<span class="sw-badge" data-status="{{ $record->displayStatus() }}">{{ $record->statusLabel() }}</span><span class="swx-secondary">{{ $record->snapshot['currency'] }} {{ number_format($record->snapshot['total'],2) }}</span>@else<span class="sw-badge" data-status="{{ $e->stage }}">{{ \App\Models\Enquiry::STAGES[$e->stage] }}</span>@endif</td>
                        <td class="swx-next">@if($dueAt)<span class="swx-due {{ $dueAt->isPast() ? 'is-overdue' : '' }}">{{ $mode==='enquiries' ? 'Next: ' : '' }}{{ $dueAt->format('d M, H:i') }} <span>IST</span>@if($dueAt->isPast()) · Overdue @endif</span>@elseif($mode==='quotations')<span class="swx-secondary">Valid until {{ $record->valid_until->format('d M Y') }}</span>@endif<a href="{{ $actionUrl }}" class="sw-btn sw-small swx-row-action">{{ $action }} <span aria-hidden="true">→</span></a></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="swx-empty"><h3>{{ $mode==='followups' ? 'No follow-ups in this view' : 'No matching records' }}</h3><p>Try another filter or open the full list.</p><a class="sw-btn sw-small" href="{{ route('enquiries.index',['view'=>$mode,'focus'=>'all']) }}">{{ $hasFilters ? 'Clear filters' : 'View all '.$viewLabel }}</a></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <footer class="swx-pagination"><span>{{ $records->firstItem() ?? 0 }}–{{ $records->lastItem() ?? 0 }} of {{ $records->total() }} records</span><div>{{ $records->links() }}</div></footer>
    </section>
</div>
<script>
(() => {
    const owner = document.getElementById('sw-owner'), mine = document.getElementById('sw-mine');
    owner?.addEventListener('change', () => { if (owner.value) mine.checked = false; });
    mine?.addEventListener('change', () => { if (mine.checked) owner.value = ''; });
})();
</script>
@endsection
