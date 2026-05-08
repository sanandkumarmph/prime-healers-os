<nav class="mobile-bottom-nav" aria-label="Mobile primary navigation">
    @foreach(($mobilePrimaryItems ?? []) as $item)
        @if(!empty($item['href']))
            <a href="{{ $item['href'] }}" class="mobile-nav-item {{ !empty($item['active']) ? 'is-active' : '' }}">
                <span>{!! $navIcon($item['icon'] ?? 'dashboard') !!}</span>
                <small>{{ $item['label'] }}</small>
            </a>
        @else
            <span class="mobile-nav-item is-disabled">
                <span>{!! $navIcon($item['icon'] ?? 'dashboard') !!}</span>
                <small>{{ $item['label'] }}</small>
            </span>
        @endif
    @endforeach

    <button type="button" class="mobile-nav-item" data-mobile-more-open>
        <span>{!! $navIcon('settings') !!}</span>
        <small>More</small>
    </button>
</nav>
