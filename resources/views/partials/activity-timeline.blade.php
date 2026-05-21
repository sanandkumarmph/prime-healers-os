@php
    $timelineSource = $timeline ?? $logs ?? collect();
    $timelineItems = $timelineSource instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator
        ? collect($timelineSource->items())
        : collect($timelineSource);

    $title = $title ?? 'Timeline';
    $subtitle = $subtitle ?? 'Chronological activity linked to this record.';
    $timelineFilter = \App\Support\ActivityTimelineService::normalizeFilter($timelineFilter ?? request('timeline_filter', 'all'));
    $timelineRoute = $timelineRoute ?? null;
    $noteAction = $noteAction ?? null;
    $noteLabel = $noteLabel ?? 'Add Note';
    $canAddNote = $canAddNote ?? filled($noteAction);
    $anchorId = $anchorId ?? 'activity-timeline';
    $emptyMessage = $emptyMessage ?? 'No activity has been logged for this record yet.';
    $groupedTimeline = $timelineItems->groupBy(fn ($log) => optional($log->created_at)->format('Y-m-d') ?: 'undated');
    $noteDetailsOpen = $errors->has('note') || $errors->has('note_type');
    $timelineItemCount = $timelineItems->count();
    $latestTimelineItem = $timelineItems->first();
    $latestTimelineLabel = $latestTimelineItem
        ? \App\Support\ActivityTimelineService::actionLabel($latestTimelineItem)
        : 'Timeline activity will appear here.';
    $latestTimelineAgo = $latestTimelineItem?->created_at?->diffForHumans();
    $timelineShouldStartOpen = $noteDetailsOpen || request()->filled('timeline_filter');
    $noteTypeOptions = [
        'general' => 'General',
        'follow-up' => 'Follow-up',
        'payment' => 'Payment',
        'delivery' => 'Delivery',
        'complaint' => 'Complaint',
        'escalation' => 'Escalation',
    ];
@endphp

<style>
    .timeline-shell {
        display:grid;
        gap:12px;
        border:1px solid #dbe3ef;
        border-radius:16px;
        background:#fff;
        padding:12px;
        box-shadow:0 12px 28px rgba(15,23,42,.05);
    }
    .timeline-shell[open] {
        padding-bottom:14px;
    }
    .timeline-shell > summary {
        list-style:none;
        cursor:pointer;
    }
    .timeline-shell > summary::-webkit-details-marker {
        display:none;
    }
    .timeline-summary {
        display:flex;
        align-items:flex-start;
        justify-content:space-between;
        gap:12px;
        flex-wrap:wrap;
        padding:4px 4px 0;
    }
    .timeline-summary-copy {
        display:grid;
        gap:5px;
        min-width:0;
    }
    .timeline-summary-copy h2 {
        margin:0;
        color:#0f172a;
        font-size:18px;
        letter-spacing:-.01em;
    }
    .timeline-summary-copy p {
        margin:0;
        color:#475569;
        font-size:12px;
        line-height:1.5;
        overflow-wrap:anywhere;
    }
    .timeline-summary-copy p strong {
        color:#0f172a;
    }
    .timeline-summary-actions {
        display:flex;
        align-items:center;
        gap:8px;
        flex-wrap:wrap;
    }
    .timeline-head {
        display:flex;
        align-items:flex-start;
        justify-content:space-between;
        gap:12px;
        flex-wrap:wrap;
    }
    .timeline-head h2 {
        margin:0;
        color:#0f172a;
        font-size:18px;
        letter-spacing:-.01em;
    }
    .timeline-head p {
        margin:5px 0 0;
        color:#64748b;
        font-size:12px;
    }
    .timeline-pill {
        display:inline-flex;
        align-items:center;
        gap:6px;
        min-height:28px;
        padding:5px 9px;
        border-radius:999px;
        background:#f8fafc;
        border:1px solid #d9e2ef;
        color:#475569;
        font-size:11px;
        font-weight:700;
        text-decoration:none;
    }
    .timeline-pill.active {
        background:#e0ecff;
        border-color:#bfd7ff;
        color:#1d4ed8;
    }
    .timeline-toolbar {
        display:grid;
        gap:10px;
    }
    .timeline-filter-row,
    .timeline-active-row,
    .timeline-links {
        display:flex;
        flex-wrap:wrap;
        gap:8px;
    }
    .timeline-note-toggle {
        display:inline-flex;
        align-items:center;
        justify-content:center;
        gap:6px;
        min-height:34px;
        padding:8px 12px;
        border-radius:12px;
        border:1px solid #cbd5e1;
        background:#fff;
        color:#334155;
        font-size:12px;
        font-weight:700;
        cursor:pointer;
    }
    .timeline-note-box {
        border:1px solid #e2e8f0;
        border-radius:16px;
        background:#fbfdff;
        overflow:hidden;
    }
    .timeline-note-box summary {
        list-style:none;
        cursor:pointer;
        padding:10px 12px;
    }
    .timeline-note-box summary::-webkit-details-marker {
        display:none;
    }
    .timeline-note-form {
        display:grid;
        gap:12px;
        padding:0 12px 12px;
    }
    .timeline-note-grid {
        display:grid;
        grid-template-columns:220px minmax(0,1fr);
        gap:12px;
    }
    .timeline-note-form label {
        display:block;
        margin-bottom:6px;
        color:#475569;
        font-size:11px;
        font-weight:800;
        text-transform:uppercase;
        letter-spacing:.05em;
    }
    .timeline-note-form select,
    .timeline-note-form textarea {
        width:100%;
        box-sizing:border-box;
        border:1px solid #cbd5e1;
        border-radius:12px;
        padding:10px 12px;
        font-size:12px;
        background:#fff;
    }
    .timeline-note-form textarea {
        min-height:94px;
        resize:vertical;
    }
    .timeline-date-group {
        display:grid;
        gap:12px;
    }
    .timeline-date-heading {
        margin:0;
        color:#0f172a;
        font-size:14px;
        font-weight:800;
    }
    .timeline-items {
        display:grid;
        gap:10px;
    }
    .timeline-item {
        display:grid;
        grid-template-columns:92px minmax(0,1fr);
        gap:12px;
        padding:12px;
        border:1px solid #e8eef6;
        border-radius:14px;
        background:#fcfdff;
    }
    .timeline-time {
        color:#64748b;
        font-size:12px;
        font-weight:700;
    }
    .timeline-time strong {
        display:block;
        margin-bottom:4px;
        color:#0f172a;
        font-size:12px;
    }
    .timeline-body {
        display:grid;
        gap:8px;
        min-width:0;
    }
    .timeline-title-row {
        display:flex;
        align-items:flex-start;
        justify-content:space-between;
        gap:10px;
        flex-wrap:wrap;
    }
    .timeline-title {
        color:#0f172a;
        font-size:14px;
        font-weight:800;
        line-height:1.35;
    }
    .timeline-category {
        display:inline-flex;
        align-items:center;
        padding:4px 9px;
        border-radius:999px;
        font-size:11px;
        font-weight:800;
        text-transform:uppercase;
        letter-spacing:.04em;
        background:#eff6ff;
        color:#1d4ed8;
    }
    .timeline-meta {
        color:#64748b;
        font-size:11px;
        line-height:1.5;
    }
    .timeline-description {
        margin:0;
        color:#334155;
        font-size:12px;
        line-height:1.6;
    }
    .timeline-inline-strip {
        display:grid;
        gap:6px;
        border-radius:14px;
        background:#f8fafc;
        border:1px solid #e2e8f0;
        padding:10px 12px;
    }
    .timeline-inline-strip div {
        color:#334155;
        font-size:11px;
        line-height:1.5;
    }
    .timeline-inline-strip strong {
        color:#0f172a;
    }
    .timeline-tag-row {
        display:flex;
        flex-wrap:wrap;
        gap:6px;
    }
    .timeline-tag {
        display:inline-flex;
        align-items:center;
        padding:4px 8px;
        border-radius:999px;
        background:#fff;
        border:1px solid #dbe3ef;
        color:#475569;
        font-size:11px;
        font-weight:700;
    }
    .timeline-empty {
        padding:14px;
        border:1px dashed #dbe3ef;
        border-radius:14px;
        color:#64748b;
        font-size:12px;
        background:#fbfdff;
    }
    @media (max-width: 767px) {
        .timeline-shell {
            padding:10px;
            border-radius:14px;
        }
        .timeline-summary {
            padding:2px 2px 0;
        }
        .timeline-note-grid,
        .timeline-item {
            grid-template-columns:1fr;
        }
        .timeline-time {
            display:flex;
            align-items:center;
            gap:8px;
        }
        .timeline-time strong {
            margin-bottom:0;
        }
    }
</style>

<details class="timeline-shell" id="{{ $anchorId }}" data-activity-timeline data-timeline-anchor="{{ $anchorId }}" @if($timelineShouldStartOpen) open @endif>
    <summary data-activity-timeline-toggle>
        <div class="timeline-summary">
            <div class="timeline-summary-copy">
                <h2>{{ $title }}</h2>
                <p>{{ $subtitle }}</p>
                <p>
                    <strong>Latest:</strong> {{ $latestTimelineLabel }}
                    @if($latestTimelineAgo)
                        <span style="color:#64748b;">&bull; {{ $latestTimelineAgo }}</span>
                    @endif
                </p>
            </div>
            <div class="timeline-summary-actions">
                <span class="timeline-pill">{{ $timelineItemCount }} {{ \Illuminate\Support\Str::plural('entry', $timelineItemCount) }}</span>
                <span class="timeline-pill" data-activity-timeline-state>{{ $timelineShouldStartOpen ? 'Collapse' : 'Expand' }}</span>
            </div>
        </div>
    </summary>

    <div class="timeline-head">
        <div>
            <h2>{{ $title }}</h2>
            <p>{{ $subtitle }}</p>
        </div>
        <div class="timeline-links">
            @if($canAddNote)
                <details class="timeline-note-box" @if($noteDetailsOpen) open @endif>
                    <summary class="timeline-note-toggle">{{ $noteLabel }}</summary>
                    <form method="POST" action="{{ $noteAction }}" class="timeline-note-form">
                        @csrf
                        <div class="timeline-note-grid">
                            <div>
                                <label for="{{ $anchorId }}-note-type">Note Type</label>
                                <select id="{{ $anchorId }}-note-type" name="note_type">
                                    @foreach($noteTypeOptions as $value => $label)
                                        <option value="{{ $value }}" @selected(old('note_type') === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="{{ $anchorId }}-note">Note</label>
                                <textarea id="{{ $anchorId }}-note" name="note" placeholder="Add a quick internal note for follow-up, delivery, payment, or escalation.">{{ old('note') }}</textarea>
                            </div>
                        </div>
                        @if($errors->has('note') || $errors->has('note_type'))
                            <div style="color:#b91c1c;font-size:12px;line-height:1.5;">
                                @foreach($errors->get('note') as $message)
                                    <div>{{ $message }}</div>
                                @endforeach
                                @foreach($errors->get('note_type') as $message)
                                    <div>{{ $message }}</div>
                                @endforeach
                            </div>
                        @endif
                        <div class="timeline-links">
                            <button type="submit" class="timeline-note-toggle" style="background:#0f172a;color:#fff;border-color:#0f172a;">Save Note</button>
                        </div>
                    </form>
                </details>
            @endif
        </div>
    </div>

    @if($timelineRoute)
        <div class="timeline-toolbar">
            <div class="timeline-filter-row">
                @foreach(\App\Support\ActivityTimelineService::filterOptions() as $filterValue => $filterLabel)
                    <a href="{{ route($timelineRoute, array_merge(request()->route()?->parameters() ?? [], request()->except('timeline_page', 'timeline_filter'), ['timeline_filter' => $filterValue])) }}#{{ $anchorId }}"
                       class="timeline-pill {{ $timelineFilter === $filterValue ? 'active' : '' }}">
                        {{ $filterLabel }}
                    </a>
                @endforeach
            </div>
            @if($timelineFilter !== 'all')
                <div class="timeline-active-row">
                    <span class="timeline-pill active">Showing: {{ \App\Support\ActivityTimelineService::categoryLabel($timelineFilter) }}</span>
                </div>
            @endif
        </div>
    @endif

    @if($timelineItems->isEmpty())
        <div class="timeline-empty">{{ $emptyMessage }}</div>
    @else
        @foreach($groupedTimeline as $dateKey => $dateLogs)
            <div class="timeline-date-group">
                <h3 class="timeline-date-heading">
                    {{ $dateKey === 'undated' ? 'Undated' : \Carbon\Carbon::parse($dateKey)->format('d M Y') }}
                </h3>
                <div class="timeline-items">
                    @foreach($dateLogs as $log)
                        @php
                            $category = \App\Support\ActivityTimelineService::actionCategory($log);
                            $links = \App\Support\ActivityTimelineService::relatedLinks($log);
                            $tags = \App\Support\ActivityTimelineService::timelineTags($log);
                            $contactSummary = \App\Support\ActivityTimelineService::contactSummary($log);
                        @endphp
                        <article class="timeline-item">
                            <div class="timeline-time">
                                <strong>{{ optional($log->created_at)->format('h:i A') }}</strong>
                                <span>{{ optional($log->created_at)->format('D') }}</span>
                            </div>
                            <div class="timeline-body">
                                <div class="timeline-title-row">
                                    <div class="timeline-title">{{ \App\Support\ActivityTimelineService::actionLabel($log) }}</div>
                                    <span class="timeline-category">{{ \App\Support\ActivityTimelineService::categoryLabel($category) }}</span>
                                </div>
                                <div class="timeline-meta">
                                    {{ $log->user->name ?? 'System' }}
                                    @if($links !== [])
                                        &bull;
                                        @foreach($links as $index => $link)
                                            <a href="{{ $link['url'] }}" style="color:#2563eb;text-decoration:none;">{{ $link['label'] }}</a>@if($index < count($links) - 1) &bull; @endif
                                        @endforeach
                                    @endif
                                </div>
                                @if(filled($log->description))
                                    <p class="timeline-description">{{ $log->description }}</p>
                                @endif

                                @if($contactSummary['reminder'] || $contactSummary['delivery'] || $contactSummary['address'])
                                    <div class="timeline-inline-strip">
                                        @if($contactSummary['reminder'])
                                            <div><strong>Reminder To:</strong> {{ $contactSummary['reminder'] }}</div>
                                        @endif
                                        @if($contactSummary['delivery'])
                                            <div><strong>Service Location:</strong> {{ $contactSummary['delivery'] }}</div>
                                        @endif
                                        @if($contactSummary['address'])
                                            <div>
                                                <strong>Address:</strong> {{ $contactSummary['address'] }}
                                                @if($contactSummary['map_url'])
                                                    &bull; <a href="{{ $contactSummary['map_url'] }}" target="_blank" rel="noopener" style="color:#2563eb;text-decoration:none;">Open Map</a>
                                                @endif
                                            </div>
                                        @endif
                                    </div>
                                @endif

                                @if($tags !== [])
                                    <div class="timeline-tag-row">
                                        @foreach($tags as $tag)
                                            <span class="timeline-tag">{{ $tag['label'] }}: {{ $tag['value'] }}</span>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            </div>
        @endforeach

        @if($timelineSource instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator)
            <div>
                {{ $timelineSource->links() }}
            </div>
        @endif
    @endif
</details>

@once
    <script>
        (function () {
            function syncTimelineState(details) {
                const state = details.querySelector('[data-activity-timeline-state]');
                if (state) {
                    state.textContent = details.open ? 'Collapse' : 'Expand';
                    state.setAttribute('aria-current', details.open ? 'true' : 'false');
                }
            }

            function openTimelineFromHash() {
                const hash = window.location.hash ? window.location.hash.replace('#', '') : '';
                if (!hash) {
                    return;
                }

                const timeline = document.querySelector('[data-activity-timeline][data-timeline-anchor="' + hash + '"]');
                if (!(timeline instanceof HTMLDetailsElement)) {
                    return;
                }

                timeline.open = true;
                syncTimelineState(timeline);
            }

            function initActivityTimelines() {
                document.querySelectorAll('[data-activity-timeline]').forEach(function (timeline) {
                    if (!(timeline instanceof HTMLDetailsElement) || timeline.dataset.timelineReady === 'true') {
                        return;
                    }

                    timeline.dataset.timelineReady = 'true';
                    syncTimelineState(timeline);
                    timeline.addEventListener('toggle', function () {
                        syncTimelineState(timeline);
                    });
                });

                openTimelineFromHash();
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initActivityTimelines, { once: true });
            } else {
                initActivityTimelines();
            }

            window.addEventListener('hashchange', openTimelineFromHash);
        })();
    </script>
@endonce
