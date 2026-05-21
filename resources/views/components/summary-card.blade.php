@props([
    'label' => null,
    'value' => null,
    'meta' => null,
    'tone' => 'neutral',
])

<div {{ $attributes->class(['ph-summary-card', 'ph-summary-card--' . $tone]) }}>
    @if(filled($label))
        <span class="ph-summary-card__label">{{ $label }}</span>
    @endif
    @if(filled($value))
        <strong class="ph-summary-card__value">{{ $value }}</strong>
    @endif
    @if(filled($meta))
        <span class="ph-summary-card__meta">{{ $meta }}</span>
    @endif
    {{ $slot }}
</div>

@once
    <style>
        .ph-summary-card {
            display: grid;
            gap: 7px;
            min-width: 0;
            padding: 16px 18px;
            border: 1px solid #e2e8f0;
            border-radius: 18px;
            background: #fff;
        }
        .ph-summary-card__label {
            color: #64748b;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .06em;
        }
        .ph-summary-card__value {
            color: #0f172a;
            font-size: 20px;
            line-height: 1.2;
            letter-spacing: -.03em;
            overflow-wrap: anywhere;
        }
        .ph-summary-card__meta {
            color: #64748b;
            font-size: 12px;
            line-height: 1.5;
        }
        .ph-summary-card--warning {
            background: linear-gradient(180deg, #fffdf5 0%, #fff8df 100%);
        }
        .ph-summary-card--danger {
            background: linear-gradient(180deg, #fffafa 0%, #fff1f2 100%);
        }
        .ph-summary-card--info {
            background: linear-gradient(180deg, #fbfdff 0%, #eff6ff 100%);
        }
        .ph-summary-card--success {
            background: linear-gradient(180deg, #fbfffc 0%, #ecfdf5 100%);
        }
    </style>
@endonce
