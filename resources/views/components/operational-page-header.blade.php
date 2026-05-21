@props([
    'eyebrow' => null,
    'title' => null,
    'subtitle' => null,
    'backUrl' => null,
    'backLabel' => 'Back',
    'meta' => [],
    'chips' => [],
])

@php
    $metaItems = collect($meta)->filter(fn ($item) => filled($item['label'] ?? null) && filled($item['value'] ?? null))->values();
    $chipItems = collect($chips)->filter(fn ($chip) => filled($chip['label'] ?? null))->values();
@endphp

<section {{ $attributes->class(['ph-operational-header']) }}>
    <div class="ph-operational-header__main">
        @if(filled($backUrl))
            <a href="{{ $backUrl }}" class="ph-operational-header__back">{{ $backLabel }}</a>
        @endif

        @if(filled($eyebrow))
            <span class="ph-operational-header__eyebrow">{{ $eyebrow }}</span>
        @endif

        @if(filled($title))
            <h1>{{ $title }}</h1>
        @endif

        @if(filled($subtitle))
            <p>{{ $subtitle }}</p>
        @endif

        @if($metaItems->isNotEmpty())
            <div class="ph-operational-header__meta">
                @foreach($metaItems as $item)
                    <div class="ph-operational-header__meta-item">
                        <span>{{ $item['label'] }}</span>
                        <strong>{{ $item['value'] }}</strong>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    @if($chipItems->isNotEmpty() || $slot->isNotEmpty())
        <div class="ph-operational-header__aside">
            @if($chipItems->isNotEmpty())
                <div class="ph-operational-header__chips">
                    @foreach($chipItems as $chip)
                        <x-status-chip :label="$chip['label']" :tone="$chip['tone'] ?? 'neutral'" />
                    @endforeach
                </div>
            @endif

            @if($slot->isNotEmpty())
                <div class="ph-operational-header__slot">
                    {{ $slot }}
                </div>
            @endif
        </div>
    @endif
</section>

@once
    <style>
        .ph-operational-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 18px;
            flex-wrap: wrap;
            padding: 0 0 6px;
        }
        .ph-operational-header__main {
            display: grid;
            gap: 10px;
            min-width: 0;
        }
        .ph-operational-header__back {
            display: inline-flex;
            align-items: center;
            color: #1d4ed8;
            font-size: 13px;
            font-weight: 800;
            text-decoration: none;
        }
        .ph-operational-header__eyebrow {
            color: #64748b;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .08em;
        }
        .ph-operational-header h1 {
            margin: 0;
            color: #0f172a;
            font-size: clamp(30px, 4vw, 40px);
            line-height: 1.04;
            letter-spacing: -.04em;
        }
        .ph-operational-header p {
            margin: 0;
            max-width: 760px;
            color: #64748b;
            font-size: 14px;
            line-height: 1.7;
        }
        .ph-operational-header__meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, max-content));
            gap: 12px;
        }
        .ph-operational-header__meta-item {
            display: grid;
            gap: 5px;
            min-width: 0;
        }
        .ph-operational-header__meta-item span {
            color: #64748b;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .05em;
        }
        .ph-operational-header__meta-item strong {
            color: #0f172a;
            font-size: 14px;
            line-height: 1.4;
            overflow-wrap: anywhere;
        }
        .ph-operational-header__aside {
            display: grid;
            gap: 10px;
            justify-items: end;
            min-width: min(320px, 100%);
        }
        .ph-operational-header__chips,
        .ph-operational-header__slot {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            flex-wrap: wrap;
        }
        @media (max-width: 900px) {
            .ph-operational-header__aside {
                justify-items: start;
                min-width: 100%;
            }
            .ph-operational-header__chips,
            .ph-operational-header__slot {
                justify-content: flex-start;
            }
        }
        @media (max-width: 768px) {
            .ph-operational-header h1 {
                font-size: 28px;
            }
        }
    </style>
@endonce
