<nav class="mobile-bottom-nav" aria-label="Mobile primary navigation">
    @foreach(($mobilePrimaryItems ?? []) as $item)
        @continue(empty($item['href']))

        <a href="{{ $item['href'] }}" class="mobile-nav-item {{ !empty($item['active']) ? 'is-active' : '' }}">
            <span class="mobile-nav-icon icon-chip {{ $item['icon_class'] ?? 'icon-admin' }}">{!! $navIcon($item['icon'] ?? 'dashboard') !!}</span>
            <small>{{ $item['label'] }}</small>
        </a>
    @endforeach

    <button type="button" class="mobile-nav-item" data-mobile-more-open>
        <span class="mobile-nav-icon icon-chip icon-admin">{!! $navIcon('settings') !!}</span>
        <small>More</small>
    </button>
</nav>
