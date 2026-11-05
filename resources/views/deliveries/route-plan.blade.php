@extends('layouts.app')
@section('content')
@include('sales-workspace.style')
<main class="sw">
    <header class="sw-head">
        <div><div class="sw-eyebrow">Delivery Operations</div><h1>Suggested delivery route</h1></div>
        <a class="sw-btn" href="{{ route('deliveries.index') }}">Task Board</a>
    </header>
    <p class="sw-muted">PHOS suggests nearby stops within each scheduled time slot. Use the arrows to change the stop order while keeping the booked times visible.</p>
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
            <div class="sw-field">
                <label for="route-warehouse">Starting warehouse</label>
                <select id="route-warehouse" name="warehouse">
                    <option value="">Use assigned warehouse if clear</option>
                    @foreach($warehouses as $option)
                        <option value="{{ $option->id }}" @selected($warehouse?->id === $option->id)>{{ $option->name }}{{ $option->city ? ' · '.$option->city : '' }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <button class="sw-btn sw-primary">Plan route</button>
    </form>
    @if($truncated)<p class="sw-muted" role="status">Showing the first 200 stops for this team member and date.</p>@endif
    <section class="sw-panel">
        <h2>Scheduled stops</h2>
        @if($warehousePoint)
            <p class="sw-muted">From <strong>{{ $warehouse->name }}</strong> directly to each stop. The distance is measured in a straight line. Estimated drive time assumes {{ \App\Services\DeliveryRoutePlanner::ESTIMATED_SPEED_KMH }} km/h and a road path 30% longer than the straight line. Traffic and earlier stops can change arrival times.</p>
        @elseif($warehouse)
            <p class="sw-muted">Add a map pin for {{ $warehouse->name }} to show distance and drive-time estimates.
                @if(auth()->user()->canAccessModule('warehouses', 'update'))
                    <a href="{{ route('warehouses.edit', $warehouse) }}">Set warehouse pin</a>
                @endif
            </p>
        @else
            <p class="sw-muted">Choose a starting warehouse to see distance and drive-time estimates.</p>
        @endif
        @if($manualOrder)
            <p role="status">Using your manual stop order. <a href="{{ route('deliveries.route-plan', $routeFilters) }}#route-stops">Reset to suggested order</a></p>
        @endif
        @if(count($stops) > 1)
            <form id="route-order" method="get" action="{{ route('deliveries.route-plan') }}">
                @foreach($routeFilters as $name => $value)
                    <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                @endforeach
                <input type="hidden" name="order" value="{{ implode(',', array_map(fn ($stop) => $stop['task']->id, $stops)) }}">
            </form>
        @endif
        @if(array_filter($maps))
            <p class="sw-muted">Open the route in sections of up to four destinations. Stops without a usable destination stay in the list for follow-up.</p>
            <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px">
                @foreach($maps as $index => $url)
                    @if($url)<a class="sw-btn" href="{{ $url }}" target="_blank" rel="noopener noreferrer">Open map section {{ $index + 1 }}</a>@endif
                @endforeach
            </div>
        @endif
        <div id="route-stops" style="overflow:auto"><table class="sw-table">
            <thead><tr><th scope="col">Stop order</th><th scope="col">Time (IST)</th><th scope="col">Task</th><th scope="col">Destination</th><th scope="col">Distance</th><th scope="col">Est. drive</th><th scope="col">Directions</th></tr></thead>
            <tbody>
                @forelse($stops as $stop)
                    <tr id="route-stop-{{ $stop['task']->id }}" tabindex="-1" style="scroll-margin-top:84px">
                        <td>
                            <strong>{{ $loop->iteration }}</strong>
                            @if(count($stops) > 1)
                                <div style="display:flex;gap:4px;margin-top:4px;">
                                    <button class="sw-btn" style="min-width:44px;min-height:44px;padding:4px" type="submit" form="route-order" name="move" value="up:{{ $stop['task']->id }}" aria-label="Move {{ $stop['task']->type }} {{ $stop['task']->id }} up" @disabled($loop->first)>↑</button>
                                    <button class="sw-btn" style="min-width:44px;min-height:44px;padding:4px" type="submit" form="route-order" name="move" value="down:{{ $stop['task']->id }}" aria-label="Move {{ $stop['task']->type }} {{ $stop['task']->id }} down" @disabled($loop->last)>↓</button>
                                </div>
                            @endif
                        </td>
                        <td style="white-space:nowrap">{{ $stop['task']->scheduled_at->format('g:i A') }}</td>
                        <td><a href="{{ route('deliveries.show', $stop['task']) }}">{{ ucfirst($stop['task']->type) }} #{{ $stop['task']->id }}</a></td>
                        <td>{{ $stop['task']->linkedCustomerName() }}<br>{{ $stop['address'] }}</td>
                        <td style="white-space:nowrap">{{ $stop['distance_km'] === null ? '—' : number_format($stop['distance_km'], 1).' km' }}</td>
                        <td style="white-space:nowrap">{{ $stop['travel_minutes'] === null ? '—' : '≈ '.$stop['travel_minutes'].' min' }}</td>
                        <td>
                            @if($stop['navigation'])
                                <a href="{{ $stop['navigation'] }}" target="_blank" rel="noopener noreferrer">{{ $origin ? 'Directions from warehouse' : 'Open directions' }}</a>
                            @endif
                            @if(!$stop['point'])
                                <div class="sw-muted">{{ $stop['address'] ? 'Address only. Save a map pin for estimates.' : 'Add a destination address or map pin.' }}</div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7">No open assigned deliveries or pickups on this date.</td></tr>
                @endforelse
            </tbody>
        </table></div>
    </section>
</main>
@endsection
