@props([
    'time' => null,
    'title' => null,
    'category' => null,
    'description' => null,
])

<article {{ $attributes->class(['ph-timeline-entry']) }}>
    <div class="ph-timeline-entry__time">{{ $time ?: '-' }}</div>
    <div class="ph-timeline-entry__body">
        <div class="ph-timeline-entry__title-row">
            <strong>{{ $title ?: 'Activity' }}</strong>
            @if(filled($category))
                <x-status-chip :label="$category" tone="info" size="sm" />
            @endif
        </div>
        @if(filled($description))
            <p>{{ $description }}</p>
        @endif
        {{ $slot }}
    </div>
</article>

@once
    <style>
        .ph-timeline-entry {
            display: grid;
            grid-template-columns: 88px minmax(0, 1fr);
            gap: 12px;
            padding: 14px;
            border: 1px solid #e8eef6;
            border-radius: 16px;
            background: #fcfdff;
        }
        .ph-timeline-entry__time {
            color: #64748b;
            font-size: 12px;
            font-weight: 700;
        }
        .ph-timeline-entry__body {
            display: grid;
            gap: 8px;
            min-width: 0;
        }
        .ph-timeline-entry__title-row {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap;
        }
        .ph-timeline-entry__title-row strong {
            color: #0f172a;
            font-size: 15px;
            line-height: 1.35;
        }
        .ph-timeline-entry__body p {
            margin: 0;
            color: #334155;
            font-size: 13px;
            line-height: 1.6;
        }
        @media (max-width: 768px) {
            .ph-timeline-entry {
                grid-template-columns: 1fr;
            }
        }
    </style>
@endonce
