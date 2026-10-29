@extends('layouts.app')
@section('content')
@include('sales-workspace.style')
<main class="sw" data-workflow-guides>
    <header class="sw-head"><div><div class="sw-eyebrow">PHOS help</div><h1>One step at a time.</h1><p class="sw-muted">Choose a workflow. Guides match the actions available to your role.</p></div><a class="sw-btn" href="{{ route('knowledge.index') }}">Knowledge Hub & SOPs</a></header>
    <div class="sw-fields">
    @forelse($guides as $guide)
        <section class="sw-panel" id="{{ $guide['id'] }}">
            <details><summary style="font-size:18px;font-weight:650;cursor:pointer">{{ $guide['title'] }}</summary>
                <ol style="padding-left:22px;line-height:1.7;margin:20px 0">@foreach($guide['steps'] as $step)<li style="padding:6px 0">{{ $step }}</li>@endforeach</ol>
                <a class="sw-btn sw-primary" href="{{ route($guide['route']) }}">Open workflow</a>
            </details>
        </section>
    @empty
        <p class="sw-panel">No workflow guides match your current permissions. You can still read the Knowledge Hub.</p>
    @endforelse
    </div>
</main>
@endsection
