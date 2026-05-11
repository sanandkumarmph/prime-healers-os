@php
    $classString = trim((string) $attributes->get('class', ''));
    $classTokens = collect(preg_split('/\s+/', $classString))
        ->filter()
        ->values()
        ->all();
    $useWhiteLogo = in_array('rn-brand-logo', $classTokens, true);
    $primaryLogo = $useWhiteLogo
        ? asset('images/prime-healers-logo-white.png')
        : asset('images/prime-healers-logo.png');
    $legacyLogo = asset('images/logo-rentnexis.png');
    $logoFallback = "data:image/svg+xml;utf8," . rawurlencode(
        '<svg xmlns="http://www.w3.org/2000/svg" width="44" height="44" viewBox="0 0 44 44" fill="none">'
        . '<rect width="44" height="44" rx="12" fill="#0B64E8"/>'
        . '<text x="22" y="28" text-anchor="middle" font-family="Arial, sans-serif" font-size="16" font-weight="700" fill="white">RX</text>'
        . '</svg>'
    );
@endphp

<img
    src="{{ $primaryLogo }}"
    alt="Prime Healers OS"
    onerror="if(!this.dataset.legacyTried){this.dataset.legacyTried='1';this.src='{{ $legacyLogo }}';return;}this.onerror=null;this.src='{{ $logoFallback }}';"
    {{ $attributes->merge(['class' => 'h-14 w-auto max-w-[220px] object-contain']) }}
/>
