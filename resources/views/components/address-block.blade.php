@props([
    'title' => 'Address',
    'address' => null,
    'city' => null,
    'state' => null,
    'pincode' => null,
    'mapUrl' => null,
    'notes' => null,
])

@php
    $fullAddress = collect([$address, $city, $state, $pincode])->filter()->implode(', ');
@endphp

<div {{ $attributes->class(['ph-address-block']) }}>
    <span class="ph-address-block__label">{{ $title }}</span>
    <div class="ph-address-block__value">
        {{ $fullAddress ?: 'Address not available.' }}
    </div>
    @if(filled($notes))
        <div class="ph-address-block__notes">{{ $notes }}</div>
    @endif
    @if(filled($mapUrl))
        <a href="{{ $mapUrl }}" target="_blank" rel="noopener" class="ph-address-block__link">Open Map</a>
    @endif
</div>

@once
    <style>
        .ph-address-block {
            display: grid;
            gap: 8px;
            min-width: 0;
        }
        .ph-address-block__label {
            color: #64748b;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .05em;
        }
        .ph-address-block__value {
            color: #0f172a;
            font-size: 14px;
            line-height: 1.6;
            overflow-wrap: anywhere;
        }
        .ph-address-block__notes {
            color: #64748b;
            font-size: 12px;
            line-height: 1.6;
        }
        .ph-address-block__link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #1d4ed8;
            font-size: 12px;
            font-weight: 800;
            text-decoration: none;
        }
        .ph-address-block__link:hover {
            text-decoration: underline;
        }
    </style>
@endonce
