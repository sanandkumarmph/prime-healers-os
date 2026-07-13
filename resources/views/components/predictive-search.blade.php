@props([
    'name',
    'hiddenName' => null,
    'endpoint' => route('search.suggestions'),
    'type' => 'all',
    'value' => '',
    'selectedId' => '',
    'placeholder' => 'Search...',
    'minChars' => 2,
    'limit' => 8,
    'context' => [],
])

@php
    $contextAttributes = collect($context)
        ->mapWithKeys(fn ($value, $key) => ['data-context-' . str_replace('_', '-', (string) $key) => $value])
        ->all();
@endphp

<div {{ $attributes->merge(['class' => 'phos-predictive-field']) }}>
    <input
        type="search"
        name="{{ $name }}"
        value="{{ $value }}"
        placeholder="{{ $placeholder }}"
        autocomplete="off"
        spellcheck="false"
        data-predictive-search
        data-predictive-endpoint="{{ $endpoint }}"
        data-predictive-type="{{ $type }}"
        data-predictive-min-chars="{{ $minChars }}"
        data-predictive-limit="{{ $limit }}"
        @foreach($contextAttributes as $attribute => $attributeValue)
            {{ $attribute }}="{{ $attributeValue }}"
        @endforeach
    >
    @if($hiddenName)
        <input type="hidden" name="{{ $hiddenName }}" value="{{ $selectedId }}" data-predictive-hidden-id>
    @endif
</div>
