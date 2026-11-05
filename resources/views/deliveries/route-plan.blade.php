@extends('layouts.app')
@section('content')
@include('sales-workspace.style')
<main class="sw">
    <header class="sw-head">
        <div><div class="sw-eyebrow">Delivery Operations</div><h1>Suggested delivery route</h1></div>
        <a class="sw-btn" href="{{ route('deliveries.index') }}">Task Board</a>
    </header>
    <p class="sw-muted">PHOS groups deliveries and pickups by scheduled time in IST. Within the same time slot, it suggests the nearest saved map pin next using straight-line distance. Allow for road conditions and traffic when opening directions.</p>
    <form class="sw-panel" method="get" action="{{ route('deliveries.route-plan') }}">
        <div class="sw-fields">
            <div class="sw-field"><label for="route-date">Scheduled date (IST)</label><input type="date" id="route-date" name="date" value="{{ $date }}" required></div>
            @if($drivers->isNotEmpty())
                <div class="sw-field">
                    <label for="driver">Assigned team member</label>
                    <select id="driver" name="driver">
                        <option value="">First available for this date</option>
                        @foreach($drivers as $id => $name)
                            <option value="{{ $id }}" @selected($driver === (int) $id)>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
        </div>
        <button class="sw-btn sw-primary">Plan route</button>
    </form>
    @if($truncated)<p class="sw-muted" role="status">Showing the first 200 stops for this team member and date.</p>@endif
    <section class="sw-panel">
        <h2>Scheduled stops</h2>
        @if(array_filter($maps))
            <p class="sw-muted">Open the route in sections of up to four destinations. Stops without a usable destination stay in the list for follow-up.</p>
            <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px">
                @foreach($maps as $index => $url)
                    @if($url)<a class="sw-btn" href="{{ $url }}" target="_blank" rel="noopener noreferrer">Open map section {{ $index + 1 }}</a>@endif
                @endforeach
            </div>
        @endif
        <div style="overflow:auto"><table class="sw-table">
            <thead><tr><th scope="col">Stop</th><th scope="col">Time (IST)</th><th scope="col">Task</th><th scope="col">Destination</th><th scope="col">Directions</th></tr></thead>
            <tbody>
                @forelse($stops as $stop)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td>{{ $stop['task']->scheduled_at->format('g:i A') }}</td>
                        <td><a href="{{ route('deliveries.show', $stop['task']) }}">{{ ucfirst($stop['task']->type) }} #{{ $stop['task']->id }}</a></td>
                        <td>{{ $stop['task']->linkedCustomerName() }}<br>{{ $stop['address'] }}</td>
                        <td>
                            @if($stop['navigation'])
                                <a href="{{ $stop['navigation'] }}" target="_blank" rel="noopener noreferrer">Open directions</a>
                            @endif
                            @if(!$stop['point'])
                                <div class="sw-muted">{{ $stop['navigation'] ? 'Address only. Save a map pin to include this stop in distance ordering.' : 'Add a destination address or map pin.' }}</div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5">No open assigned deliveries or pickups on this date.</td></tr>
                @endforelse
            </tbody>
        </table></div>
    </section>
</main>
@endsection
