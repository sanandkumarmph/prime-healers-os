@php($actionInstant = \App\Support\ActionTimestamp::ist($actionAt ?? null))
@if($actionInstant)
    <time class="phos-action-time" datetime="{{ $actionInstant->toIso8601String() }}" title="{{ \App\Support\ActionTimestamp::label($actionInstant) }}" style="overflow-wrap:anywhere; font-variant-numeric:tabular-nums;">{{ !empty($clockOnly) ? $actionInstant->format('h:i:s A \I\S\T') : \App\Support\ActionTimestamp::label($actionInstant) }}</time>
@else
    <span class="phos-action-time-missing">Time not recorded</span>
@endif
