@props([
    'title' => 'Nothing here yet',
    'message' => null,
])

<div {{ $attributes->class(['ph-empty-state']) }}>
    <strong>{{ $title }}</strong>
    @if(filled($message))
        <p>{{ $message }}</p>
    @endif
    {{ $slot }}
</div>

@once
    <style>
        .ph-empty-state {
            display: grid;
            gap: 8px;
            padding: 18px;
            border: 1px dashed #cbd5e1;
            border-radius: 18px;
            background: #fbfdff;
            color: #64748b;
        }
        .ph-empty-state strong {
            color: #0f172a;
            font-size: 15px;
        }
        .ph-empty-state p {
            margin: 0;
            font-size: 13px;
            line-height: 1.6;
        }
    </style>
@endonce
