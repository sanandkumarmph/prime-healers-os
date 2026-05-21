@props([
    'label' => '',
    'tone' => 'neutral',
    'size' => 'md',
])

@php
    $toneClass = match ($tone) {
        'success' => 'ph-status-chip--success',
        'warning' => 'ph-status-chip--warning',
        'danger' => 'ph-status-chip--danger',
        'info' => 'ph-status-chip--info',
        'accent' => 'ph-status-chip--accent',
        default => 'ph-status-chip--neutral',
    };

    $sizeClass = $size === 'sm' ? 'ph-status-chip--sm' : '';
@endphp

@if(filled($label))
    <span {{ $attributes->class(['ph-status-chip', $toneClass, $sizeClass]) }}>
        {{ $label }}
    </span>
@endif

@once
    <style>
        .ph-status-chip {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 32px;
            padding: 7px 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 800;
            line-height: 1;
            letter-spacing: .01em;
            white-space: nowrap;
        }
        .ph-status-chip--sm {
            min-height: 28px;
            padding: 6px 10px;
            font-size: 11px;
        }
        .ph-status-chip--neutral {
            background: #f1f5f9;
            color: #475569;
        }
        .ph-status-chip--success {
            background: #dcfce7;
            color: #166534;
        }
        .ph-status-chip--warning {
            background: #fef3c7;
            color: #b45309;
        }
        .ph-status-chip--danger {
            background: #fee2e2;
            color: #b91c1c;
        }
        .ph-status-chip--info {
            background: #dbeafe;
            color: #1d4ed8;
        }
        .ph-status-chip--accent {
            background: #e0f2fe;
            color: #0f4f84;
        }
    </style>
@endonce
