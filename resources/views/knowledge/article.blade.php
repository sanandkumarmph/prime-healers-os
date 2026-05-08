@extends('layouts.app')

@php
    $typeLabels = [
        'sop' => 'SOP',
        'guide' => 'Guide',
        'faq' => 'FAQ',
        'tutorial' => 'Tutorial',
        'policy' => 'Policy',
    ];
    $typeTone = [
        'sop' => ['bg' => '#eff6ff', 'border' => '#bfdbfe', 'text' => '#1d4ed8'],
        'guide' => ['bg' => '#ecfeff', 'border' => '#a5f3fc', 'text' => '#0f766e'],
        'faq' => ['bg' => '#fef3c7', 'border' => '#fde68a', 'text' => '#b45309'],
        'tutorial' => ['bg' => '#f5f3ff', 'border' => '#ddd6fe', 'text' => '#6d28d9'],
        'policy' => ['bg' => '#f1f5f9', 'border' => '#cbd5e1', 'text' => '#334155'],
    ];
    $tone = $typeTone[$article->type] ?? $typeTone['guide'];
@endphp

@section('content')
    <div style="display:grid; gap:18px;">
        <section style="display:grid; gap:16px; background:#fff; border:1px solid #dbe3ef; border-radius:22px; padding:24px; box-shadow:0 16px 36px rgba(15,23,42,.06);">
            <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                <a href="{{ route('knowledge.index') }}" style="display:inline-flex; align-items:center; gap:8px; color:#1d4ed8; text-decoration:none; font-size:13px; font-weight:700;">
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"></path><path d="M21 12H9"></path></svg>
                    <span>Back to Knowledge Hub</span>
                </a>
                @if($article->category)
                    <span style="color:#94a3b8;">/</span>
                    <a href="{{ route('knowledge.categories.show', $article->category) }}" style="color:#475569; text-decoration:none; font-size:13px; font-weight:700;">{{ $article->category->name }}</a>
                @endif
            </div>

            <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                <span style="display:inline-flex; align-items:center; min-height:30px; padding:0 10px; border-radius:999px; background:{{ $tone['bg'] }}; border:1px solid {{ $tone['border'] }}; color:{{ $tone['text'] }}; font-size:11px; font-weight:800; letter-spacing:.06em; text-transform:uppercase;">{{ $typeLabels[$article->type] ?? strtoupper($article->type) }}</span>
                @if($article->category)
                    <span style="display:inline-flex; align-items:center; min-height:30px; padding:0 10px; border-radius:999px; background:#f8fafc; border:1px solid #e2e8f0; color:#475569; font-size:11px; font-weight:800; letter-spacing:.06em; text-transform:uppercase;">{{ $article->category->name }}</span>
                @endif
            </div>

            <div>
                <h1 style="margin:0; font-size:32px; line-height:1.12; color:#0f172a;">{{ $article->title }}</h1>
                @if(!empty($article->excerpt))
                    <p style="margin:12px 0 0; max-width:820px; color:#64748b; font-size:15px; line-height:1.8;">{{ $article->excerpt }}</p>
                @endif
            </div>

            <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap; color:#64748b; font-size:12px; font-weight:700;">
                <span>Published {{ optional($article->published_at)->format('d M Y') ?: optional($article->created_at)->format('d M Y') }}</span>
                <span>&bull;</span>
                <span>Updated {{ optional($article->updated_at)->format('d M Y') }}</span>
            </div>
        </section>

        @if(!empty($visuals))
            <section style="display:grid; gap:18px;">
                @if(!empty($visuals['summary']))
                    <x-knowledge-summary-box
                        :purpose="$visuals['summary']['purpose'] ?? null"
                        :when-to-use="$visuals['summary']['when_to_use'] ?? null"
                        :who-should-use="$visuals['summary']['who_should_use'] ?? null"
                        :common-mistakes="$visuals['summary']['common_mistakes'] ?? []"
                    />
                @endif

                @if(!empty($visuals['flow']))
                    <x-knowledge-flow
                        :label="$visuals['flow']['label'] ?? 'Process flow'"
                        :steps="$visuals['flow']['steps'] ?? []"
                    />
                @endif

                @if(!empty($visuals['callouts']))
                    <div style="display:grid; gap:14px;">
                        @foreach($visuals['callouts'] as $callout)
                            <x-knowledge-callout
                                :tone="$callout['tone'] ?? 'tip'"
                                :title="$callout['title'] ?? ''"
                                :body="$callout['body'] ?? ''"
                            />
                        @endforeach
                    </div>
                @endif

                @if(!empty($visuals['steps']))
                    <section style="display:grid; gap:14px;">
                        <div style="font-size:16px; font-weight:800; color:#0f172a;">Step-by-step</div>
                        <div style="display:grid; gap:14px; grid-template-columns:repeat(auto-fit,minmax(220px,1fr));">
                            @foreach($visuals['steps'] as $index => $step)
                                <x-knowledge-step-card
                                    :number="$index + 1"
                                    :title="$step['title'] ?? ''"
                                    :description="$step['description'] ?? ''"
                                />
                            @endforeach
                        </div>
                    </section>
                @endif

                @if(!empty($visuals['infographics']))
                    <x-knowledge-infographic-grid
                        :title="$visuals['infographics']['title'] ?? 'Quick view'"
                        :items="$visuals['infographics']['items'] ?? []"
                    />
                @endif

                @if(!empty($visuals['checklists']))
                    <div style="display:grid; gap:14px; grid-template-columns:repeat(auto-fit,minmax(260px,1fr));">
                        @foreach($visuals['checklists'] as $checklist)
                            <x-knowledge-checklist
                                :title="$checklist['title'] ?? 'Checklist'"
                                :items="$checklist['items'] ?? []"
                            />
                        @endforeach
                    </div>
                @endif
            </section>
        @endif

        <article style="background:#fff; border:1px solid #dbe3ef; border-radius:22px; padding:24px; box-shadow:0 16px 36px rgba(15,23,42,.06); color:#1e293b; font-size:14px; line-height:1.9; overflow-wrap:anywhere;">
            <div style="display:grid; gap:8px; margin-bottom:16px; padding-bottom:16px; border-bottom:1px solid #e2e8f0;">
                <div style="font-size:12px; font-weight:800; letter-spacing:.08em; text-transform:uppercase; color:#64748b;">Full reference</div>
                <div style="font-size:15px; color:#475569;">Original article content is preserved below for detailed reading.</div>
            </div>
            {!! nl2br(e($article->content)) !!}
        </article>
    </div>
@endsection
