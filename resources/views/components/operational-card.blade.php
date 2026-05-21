@props([
    'title' => null,
    'subtitle' => null,
    'padding' => 'md',
])

@php
    $paddingClass = match ($padding) {
        'sm' => 'ph-operational-card--compact',
        'lg' => 'ph-operational-card--spacious',
        default => '',
    };

    $actions = $actions ?? null;
@endphp

<section {{ $attributes->class(['ph-operational-card', $paddingClass]) }}>
    @if(filled($title) || filled($subtitle) || isset($actions))
        <div class="ph-operational-card__head">
            <div class="ph-operational-card__heading">
                @if(filled($title))
                    <h2>{{ $title }}</h2>
                @endif
                @if(filled($subtitle))
                    <p>{{ $subtitle }}</p>
                @endif
            </div>
            @if(isset($actions))
                <div class="ph-operational-card__actions">
                    {{ $actions }}
                </div>
            @endif
        </div>
    @endif

    <div class="ph-operational-card__body">
        {{ $slot }}
    </div>
</section>

@once
    <style>
        .ph-operational-card {
            display: grid;
            gap: 18px;
            padding: 22px;
            border: 1px solid #dbe3ef;
            border-radius: 24px;
            background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%);
            box-shadow: 0 20px 44px rgba(15, 23, 42, 0.05);
        }
        .ph-operational-card--compact {
            padding: 18px;
            gap: 14px;
        }
        .ph-operational-card--spacious {
            padding: 26px;
        }
        .ph-operational-card__head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 14px;
            flex-wrap: wrap;
        }
        .ph-operational-card__heading {
            display: grid;
            gap: 6px;
            min-width: 0;
        }
        .ph-operational-card__heading h2 {
            margin: 0;
            color: #0f172a;
            font-size: 19px;
            line-height: 1.2;
            letter-spacing: -.02em;
        }
        .ph-operational-card__heading p {
            margin: 0;
            color: #64748b;
            font-size: 13px;
            line-height: 1.6;
        }
        .ph-operational-card__actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .ph-operational-card__body {
            min-width: 0;
        }
        @media (max-width: 768px) {
            .ph-operational-card {
                padding: 18px;
                border-radius: 20px;
            }
            .ph-operational-card__heading h2 {
                font-size: 17px;
            }
        }
    </style>
@endonce
