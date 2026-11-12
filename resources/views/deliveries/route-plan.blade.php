@extends('layouts.app')
@section('content')
@include('sales-workspace.style')
<style>
    .route-drag-handle { min-width:44px; min-height:44px; padding:6px; cursor:grab; touch-action:none; user-select:none; }
    [data-route-reorder-fallback] { display:flex; gap:4px; margin-top:4px; }
    .route-drag-handle[hidden], [data-route-drag-help][hidden], [data-route-reorder-fallback][hidden] { display:none; }
    [data-route-drag-help], [data-route-drag-status] { position:absolute; width:1px; height:1px; overflow:hidden; clip-path:inset(50%); white-space:nowrap; }
    [data-route-picked-up] > td { background:#eff6ff; }
    [data-route-picked-up] .route-drag-handle { cursor:grabbing; }
    .route-stops { overflow:auto; }
    .route-stop-label { display:none; }
    @media (max-width: 767px) {
        .route-stops { overflow:visible; }
        .route-stops .sw-table, .route-stops tbody { display:block; width:100%; }
        .route-stops thead { position:absolute; width:1px; height:1px; overflow:hidden; clip-path:inset(50%); }
        .route-stops tbody tr { display:grid; grid-template-columns:auto minmax(0, 1fr) auto; gap:8px 12px; align-items:center; padding:12px; border:1px solid var(--sw-line); border-radius:10px; }
        .route-stops tbody tr + tr { margin-top:10px; }
        .route-stops .sw-table td { min-width:0; padding:0; border:0; overflow-wrap:anywhere; }
        .route-stop-position { grid-area:1 / 1; display:flex; align-items:center; gap:6px; }
        .route-stop-task { grid-area:1 / 2; font-weight:700; }
        .route-stop-time { grid-area:1 / 3; }
        .route-stop-destination, .route-stop-directions, .route-stop-empty { grid-column:1 / -1; }
        .route-stop-distance { grid-column:1 / 3; }
        .route-stop-label { display:block; font-size:12px; color:var(--sw-muted); }
        .route-stop-directions a { display:inline-flex; align-items:center; min-height:44px; font-weight:600; }
        .route-stops [data-route-picked-up] { background:#eff6ff; border-color:#93c5fd; }
        .route-stops [data-route-reorder-fallback] { margin:0; }
        .route-stop-position:has([data-route-reorder-fallback]:not([hidden])) { grid-column:1 / -1; }
        .route-stops tr:has([data-route-reorder-fallback]:not([hidden])) .route-stop-task { grid-area:2 / 1 / 2 / 3; }
        .route-stops tr:has([data-route-reorder-fallback]:not([hidden])) .route-stop-time { grid-row:2; }
    }
</style>
<main class="sw">
    <header class="sw-head">
        <div><div class="sw-eyebrow">Delivery Operations</div><h1>Suggested delivery route</h1></div>
        <a class="sw-btn" href="{{ route('deliveries.index') }}">Task Board</a>
    </header>
    <p class="sw-muted">PHOS suggests nearby stops within each scheduled time slot.</p>
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
        @if(!$warehousePoint && $warehouse)
            <p class="sw-muted">Add a map pin for {{ $warehouse->name }} to show distance and drive-time estimates.
                @if(auth()->user()->canAccessModule('warehouses', 'update'))
                    <a href="{{ route('warehouses.edit', $warehouse) }}">Set warehouse pin</a>
                @endif
            </p>
        @elseif(!$warehousePoint)
            <p class="sw-muted">Choose a starting warehouse to see distance and drive-time estimates.</p>
        @endif
        @if($manualOrder)
            <p role="status">Using your manual stop order. <a href="{{ route('deliveries.route-plan', $routeFilters) }}#route-stops">Reset to suggested order</a></p>
        @endif
        @if(count($stops) > 1)
            <p id="route-drag-help" data-route-drag-help hidden>
                Press Space to pick up, use the arrow keys to move, then press Space to place. Escape cancels.
            </p>
            <p role="status" data-route-drag-status></p>
            <form id="route-order" method="get" action="{{ route('deliveries.route-plan') }}">
                @foreach($routeFilters as $name => $value)
                    <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                @endforeach
                <input type="hidden" name="order" value="{{ implode(',', array_map(fn ($stop) => $stop['task']->id, $stops)) }}">
            </form>
        @endif
        @if(array_filter($maps))
            <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px">
                @foreach($maps as $index => $url)
                    @if($url)<a class="sw-btn" href="{{ $url }}" target="_blank" rel="noopener noreferrer">Open map section {{ $index + 1 }}</a>@endif
                @endforeach
            </div>
        @endif
        <div id="route-stops" class="route-stops"><table class="sw-table" role="table" aria-label="Scheduled stops">
            <thead role="rowgroup"><tr role="row"><th role="columnheader" scope="col">Stop order</th><th role="columnheader" scope="col">Time (IST)</th><th role="columnheader" scope="col">Task</th><th role="columnheader" scope="col">Destination</th><th role="columnheader" scope="col" title="Straight-line distance from the starting warehouse">Distance</th><th role="columnheader" scope="col" title="Estimated drive time from the starting warehouse">Est. drive</th><th role="columnheader" scope="col">Directions</th></tr></thead>
            <tbody role="rowgroup" data-route-stop-list>
                @forelse($stops as $stop)
                    <tr role="row" id="route-stop-{{ $stop['task']->id }}" data-route-stop="{{ $stop['task']->id }}" style="scroll-margin-top:84px">
                        <td role="cell" class="route-stop-position">
                            <strong data-route-position>{{ $loop->iteration }}</strong>
                            @if(count($stops) > 1)
                                <button type="button" class="sw-btn route-drag-handle" data-route-drag-handle hidden
                                    aria-label="Reorder {{ $stop['task']->type }} {{ $stop['task']->id }}" aria-describedby="route-drag-help">
                                    <svg width="20" height="20" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                        <path d="M7 3a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3Zm6 0a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3ZM7 8.5a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3Zm6 0a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3ZM7 14a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3Zm6 0a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3Z"/>
                                    </svg>
                                </button>
                                <div data-route-reorder-fallback>
                                    <button class="sw-btn" style="min-width:44px;min-height:44px;padding:4px" type="submit" form="route-order" name="move" value="up:{{ $stop['task']->id }}" aria-label="Move {{ $stop['task']->type }} {{ $stop['task']->id }} up" @disabled($loop->first)>↑</button>
                                    <button class="sw-btn" style="min-width:44px;min-height:44px;padding:4px" type="submit" form="route-order" name="move" value="down:{{ $stop['task']->id }}" aria-label="Move {{ $stop['task']->type }} {{ $stop['task']->id }} down" @disabled($loop->last)>↓</button>
                                </div>
                            @endif
                        </td>
                        <td role="cell" class="route-stop-time" style="white-space:nowrap"><span class="route-stop-label" aria-hidden="true">Time (IST)</span>{{ $stop['task']->scheduled_at->format('g:i A') }}</td>
                        <td role="cell" class="route-stop-task"><a href="{{ route('deliveries.show', $stop['task']) }}">{{ ucfirst($stop['task']->type) }} #{{ $stop['task']->id }}</a></td>
                        <td role="cell" class="route-stop-destination"><strong>{{ $stop['task']->linkedCustomerName() }}</strong><br>{{ $stop['address'] }}</td>
                        <td role="cell" class="route-stop-distance" style="white-space:nowrap"><span class="route-stop-label" aria-hidden="true">Distance</span>{{ $stop['distance_km'] === null ? '—' : number_format($stop['distance_km'], 1).' km' }}</td>
                        <td role="cell" class="route-stop-drive" style="white-space:nowrap"><span class="route-stop-label" aria-hidden="true">Est. drive</span>{{ $stop['travel_minutes'] === null ? '—' : '≈ '.$stop['travel_minutes'].' min' }}</td>
                        <td role="cell" class="route-stop-directions">
                            @if($stop['navigation'])
                                <a href="{{ $stop['navigation'] }}" target="_blank" rel="noopener noreferrer">{{ $origin ? 'Directions from warehouse' : 'Open directions' }}</a>
                            @endif
                            @if(!$stop['point'])
                                <div class="sw-muted">{{ $stop['address'] ? 'Address only. Save a map pin for estimates.' : 'Add a destination address or map pin.' }}</div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr role="row"><td role="cell" class="route-stop-empty" colspan="7">No open assigned deliveries or pickups on this date.</td></tr>
                @endforelse
            </tbody>
        </table></div>
    </section>
</main>
@if(count($stops) > 1)
    <script src="{{ asset('js/phos-route-order.js') }}?v={{ hash_file('sha256', public_path('js/phos-route-order.js')) }}" defer></script>
@endif
@endsection
