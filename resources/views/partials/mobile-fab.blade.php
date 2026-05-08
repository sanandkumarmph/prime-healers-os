@if(!empty($href))
    <a href="{{ $href }}" class="mobile-fab" aria-label="{{ $label ?? 'Add record' }}">
        <span aria-hidden="true">+</span>
        <strong>{{ $label ?? 'Add' }}</strong>
    </a>
@endif
