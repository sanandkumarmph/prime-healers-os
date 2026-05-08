@php
    $logs = collect($logs ?? []);
    $title = $title ?? 'Recent Activity';
    $subtitle = $subtitle ?? 'Latest operational updates linked to this record.';
    $visiblePropertyKeys = [
        'status',
        'payment_status',
        'amount',
        'sale_amount',
        'total_amount',
        'balance_amount',
        'invoice_number',
        'scheduled_at',
    ];
@endphp

<style>
    .rn-activity-card {
        border: 1px solid #dbe3ef;
        border-radius: 18px;
        background: #fff;
        margin-top: 18px;
        overflow: hidden;
        box-shadow: 0 14px 34px rgba(15, 23, 42, .04);
    }
    .rn-activity-summary {
        list-style: none;
        cursor: pointer;
    }
    .rn-activity-summary::-webkit-details-marker {
        display: none;
    }
    .rn-activity-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 16px 18px;
    }
    .rn-activity-card[open] .rn-activity-head {
        border-bottom: 1px solid #e5edf7;
    }
    .rn-activity-head h2 {
        margin: 0;
        font-size: 18px;
        color: #0f172a;
        letter-spacing: -.01em;
    }
    .rn-activity-head p {
        margin: 4px 0 0;
        color: #64748b;
        font-size: 13px;
    }
    .rn-activity-count {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 34px;
        height: 28px;
        padding: 0 10px;
        border-radius: 999px;
        background: #eff6ff;
        color: #1d4ed8;
        font-size: 12px;
        font-weight: 800;
    }
    .rn-activity-toggle {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        flex: 0 0 auto;
        min-width: 96px;
        justify-content: flex-end;
    }
    .rn-activity-toggle-label {
        color: #475569;
        font-size: 12px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .05em;
        white-space: nowrap;
    }
    .rn-activity-chevron {
        width: 10px;
        height: 10px;
        border-right: 2px solid #64748b;
        border-bottom: 2px solid #64748b;
        transform: rotate(45deg);
        transition: transform .18s ease;
        margin-top: -3px;
    }
    .rn-activity-card[open] .rn-activity-chevron {
        transform: rotate(-135deg);
        margin-top: 3px;
    }
    .rn-activity-list {
        display: grid;
        gap: 0;
    }
    .rn-activity-row {
        display: grid;
        grid-template-columns: 140px minmax(0, 1fr);
        gap: 14px;
        padding: 14px 18px;
        border-bottom: 1px solid #edf2f8;
    }
    .rn-activity-row:last-child {
        border-bottom: 0;
    }
    .rn-activity-time {
        color: #64748b;
        font-size: 12px;
        line-height: 1.45;
    }
    .rn-activity-action {
        color: #0f172a;
        font-size: 14px;
        font-weight: 800;
        margin-bottom: 4px;
    }
    .rn-activity-meta {
        color: #64748b;
        font-size: 12px;
        margin-bottom: 6px;
    }
    .rn-activity-desc {
        color: #334155;
        font-size: 13px;
        line-height: 1.45;
        margin: 0;
    }
    .rn-activity-tags {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        margin-top: 8px;
    }
    .rn-activity-tag {
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        color: #475569;
        font-size: 11px;
        font-weight: 700;
        padding: 4px 8px;
    }
    .rn-activity-empty {
        padding: 18px;
        color: #64748b;
        font-size: 13px;
    }
    @media (max-width: 767px) {
        .rn-activity-card {
            margin-top: 14px;
            border-radius: 14px;
        }
        .rn-activity-head {
            padding: 13px 14px;
        }
        .rn-activity-head h2 {
            font-size: 16px;
        }
        .rn-activity-row {
            grid-template-columns: 1fr;
            gap: 6px;
            padding: 12px 14px;
        }
        .rn-activity-toggle {
            min-width: 84px;
            gap: 6px;
        }
        .rn-activity-toggle-label {
            font-size: 11px;
        }
    }
</style>

<details class="rn-activity-card" aria-label="{{ $title }}">
    <summary class="rn-activity-summary">
        <div class="rn-activity-head">
            <div>
                <h2>{{ $title }}</h2>
                <p>{{ $subtitle }}</p>
            </div>
            <div class="rn-activity-toggle">
                <span class="rn-activity-count">{{ $logs->count() }}</span>
                <span class="rn-activity-toggle-label">Open</span>
                <span class="rn-activity-chevron" aria-hidden="true"></span>
            </div>
        </div>
    </summary>

    @if($logs->isEmpty())
        <div class="rn-activity-empty">No activity has been logged for this record yet.</div>
    @else
        <div class="rn-activity-list">
            @foreach($logs as $log)
                @php
                    $properties = collect($log->properties ?? [])
                        ->only($visiblePropertyKeys)
                        ->filter(fn ($value) => filled($value));
                @endphp
                <article class="rn-activity-row">
                    <div class="rn-activity-time">
                        <strong>{{ optional($log->created_at)->format('d M Y') }}</strong><br>
                        {{ optional($log->created_at)->format('h:i A') }}
                    </div>
                    <div>
                        <div class="rn-activity-action">{{ str($log->action)->replace('.', ' ')->title() }}</div>
                        <div class="rn-activity-meta">By {{ $log->user->name ?? 'System' }}</div>
                        @if($log->description)
                            <p class="rn-activity-desc">{{ $log->description }}</p>
                        @endif
                        @if($properties->isNotEmpty())
                            <div class="rn-activity-tags">
                                @foreach($properties as $key => $value)
                                    <span class="rn-activity-tag">{{ str($key)->replace('_', ' ')->title() }}: {{ is_numeric($value) ? number_format((float) $value, 2) : $value }}</span>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>
    @endif
</details>
