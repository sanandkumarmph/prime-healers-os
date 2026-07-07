@if(!empty($href))
    <a href="{{ $href }}" class="mobile-fab {{ !empty($compact) ? 'is-compact' : '' }}" aria-label="{{ $label ?? 'Add record' }}">
        <span aria-hidden="true">+</span>
        @unless(!empty($compact))
            <strong>{{ $label ?? 'Add' }}</strong>
        @endunless
    </a>
@endif
