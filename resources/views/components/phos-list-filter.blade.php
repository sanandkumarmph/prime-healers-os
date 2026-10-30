@props(['id' => 'phos-list-filters', 'label' => 'Filters', 'active' => false])
<details {{ $attributes->class(['phos-list-filter', 'has-active-filters' => (bool) $active]) }} id="{{ $id }}" data-phos-list-filter data-mobile-keep-open="true">
    <summary class="phos-list-filter-toggle" data-phos-list-filter-toggle
             aria-label="{{ $label }}{{ $active ? ', filters active' : '' }}" title="{{ $label }}"
             aria-controls="{{ $id }}-panel">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 5h16l-6.5 7.5V19l-3 1v-7.5L4 5Z"/></svg>
        @if($active)<span class="phos-list-filter-dot" aria-hidden="true"></span>@endif
    </summary>
    <div id="{{ $id }}-panel" class="phos-list-filter-panel" data-phos-list-filter-panel role="region" aria-label="{{ $label }}">
        <div class="phos-list-filter-head">
            <div><strong>{{ $label }}</strong><span>Refine the records shown below.</span></div>
            <button type="button" class="phos-list-filter-close" data-phos-list-filter-close aria-label="Close filters" hidden>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg>
            </button>
        </div>
        <div class="phos-list-filter-content">{{ $slot }}</div>
    </div>
</details>
