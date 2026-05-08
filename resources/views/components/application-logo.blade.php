@php
    $logoFallback = "data:image/svg+xml;utf8," . rawurlencode(
        '<svg xmlns="http://www.w3.org/2000/svg" width="44" height="44" viewBox="0 0 44 44" fill="none">'
        . '<rect width="44" height="44" rx="12" fill="#0B64E8"/>'
        . '<text x="22" y="28" text-anchor="middle" font-family="Arial, sans-serif" font-size="16" font-weight="700" fill="white">RX</text>'
        . '</svg>'
    );
@endphp

<img
    src="{{ asset('images/logo-rentnexis.png') }}"
    alt="Rentnexis - Smarter Rental Operations"
    onerror="this.onerror=null;this.src='{{ $logoFallback }}';"
    {{ $attributes->merge(['class' => 'h-14 w-auto max-w-[220px] object-contain']) }}
/>
