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
    $iconLabels = [
        'rentals' => 'RT',
        'sales' => 'SL',
        'payments' => 'PY',
        'deliveries' => 'DL',
        'inventory' => 'IN',
        'customers' => 'CU',
        'csv' => 'CSV',
        'reports' => 'RP',
    ];
@endphp

@section('content')
    <style>
        .rn-knowledge-article-card {
            text-decoration: none;
            color: inherit;
            transition: border-color .18s ease, box-shadow .18s ease, transform .18s ease, background-color .18s ease;
        }
        .rn-knowledge-article-card:hover,
        .rn-knowledge-article-card:focus-visible {
            border-color: #93c5fd !important;
            box-shadow: 0 16px 32px rgba(29, 78, 216, .10) !important;
            background: #fcfdff !important;
            transform: translateY(-1px);
            outline: none;
        }
        .rn-knowledge-article-title {
            color: #1d4ed8 !important;
            text-decoration: none;
            transition: color .18s ease, text-decoration-color .18s ease;
        }
        .rn-knowledge-article-card:hover .rn-knowledge-article-title,
        .rn-knowledge-article-card:focus-visible .rn-knowledge-article-title {
            color: #1e40af !important;
            text-decoration: underline;
            text-decoration-thickness: 1.5px;
            text-underline-offset: 2px;
        }
        .rn-knowledge-read-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 34px;
            padding: 0 12px;
            border-radius: 999px;
            border: 1px solid #bfdbfe;
            background: #eff6ff;
            color: #1d4ed8;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: .02em;
            white-space: nowrap;
            transition: background-color .18s ease, border-color .18s ease, color .18s ease;
        }
        .rn-knowledge-article-card:hover .rn-knowledge-read-btn,
        .rn-knowledge-article-card:focus-visible .rn-knowledge-read-btn {
            background: #dbeafe;
            border-color: #93c5fd;
            color: #1e40af;
        }
    </style>
    <div style="display:grid; gap:18px;">
        <section style="display:grid; gap:18px; background:#fff; border:1px solid #dbe3ef; border-radius:22px; padding:24px; box-shadow:0 16px 36px rgba(15,23,42,.06);">
            <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:18px; flex-wrap:wrap;">
                <div style="display:grid; gap:10px;">
                    <div style="display:inline-flex; align-items:center; gap:8px; width:max-content; min-height:30px; padding:6px 10px; border-radius:999px; background:#eff6ff; border:1px solid #bfdbfe; color:#1d4ed8; font-size:11px; font-weight:800; letter-spacing:.08em; text-transform:uppercase;">Knowledge Hub</div>
                    <div>
                        <h1 style="margin:0; font-size:30px; line-height:1.08; color:#0f172a;">Knowledge Hub</h1>
                        <p style="margin:10px 0 0; max-width:760px; color:#64748b; font-size:14px; line-height:1.7;">Help guides, SOPs, tutorials, FAQs, and internal documentation for daily Prime Healers operations.</p>
                    </div>
                </div>
            </div>

            <form method="GET" action="{{ route('knowledge.index') }}" style="display:grid; gap:12px;">
                <div style="display:flex; gap:12px; flex-wrap:wrap;">
                    <label style="flex:1 1 320px; min-width:240px; display:flex; align-items:center; gap:10px; min-height:44px; padding:0 14px; border:1px solid #dbe3ef; border-radius:14px; background:#f8fafc;">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="#94a3b8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path></svg>
                        <input type="text" name="search" value="{{ $search }}" placeholder="Search articles, topics, and categories" style="width:100%; border:0; background:transparent; color:#0f172a; font-size:13px; outline:none;">
                    </label>
                    <label style="flex:0 1 180px; min-width:160px; display:flex; align-items:center; padding:0 14px; border:1px solid #dbe3ef; border-radius:14px; background:#f8fafc;">
                        <select name="type" style="width:100%; border:0; background:transparent; color:#0f172a; font-size:13px; outline:none; min-height:44px;">
                            <option value="">All article types</option>
                            @foreach($typeOptions as $option)
                                <option value="{{ $option }}" @selected($typeFilter === $option)>{{ $typeLabels[$option] ?? ucfirst($option) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <button type="submit" class="rn-btn rn-btn-primary" style="min-height:44px; padding:0 16px;">Search</button>
                    @if($search !== '' || $typeFilter !== '')
                        <a href="{{ route('knowledge.index') }}" class="rn-btn rn-btn-secondary" style="min-height:44px; padding:0 16px; text-decoration:none;">Clear</a>
                    @endif
                </div>
            </form>
        </section>

        <section style="display:grid; gap:14px;">
            <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                <div>
                    <h2 style="margin:0; font-size:20px; color:#0f172a;">Browse by category</h2>
                    <p style="margin:6px 0 0; color:#64748b; font-size:13px;">Quick access to the most-used operational topics.</p>
                </div>
            </div>

            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:14px;">
                @forelse($categories as $category)
                    <a href="{{ route('knowledge.categories.show', $category) }}" style="display:grid; gap:12px; padding:18px; border-radius:18px; border:1px solid #dbe3ef; background:#fff; box-shadow:0 14px 28px rgba(15,23,42,.05); text-decoration:none; color:inherit;">
                        <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:12px;">
                            <span style="width:42px; height:42px; border-radius:14px; display:grid; place-items:center; background:#eff6ff; border:1px solid #bfdbfe; color:#1d4ed8; font-size:12px; font-weight:900; letter-spacing:.06em;">{{ $iconLabels[$category->icon] ?? strtoupper(\Illuminate\Support\Str::substr($category->name, 0, 2)) }}</span>
                            <span style="display:inline-flex; align-items:center; justify-content:center; min-width:32px; height:32px; padding:0 10px; border-radius:999px; background:#f8fafc; border:1px solid #e2e8f0; color:#475569; font-size:12px; font-weight:800;">{{ (int) $category->published_articles_count }}</span>
                        </div>
                        <div>
                            <h3 style="margin:0; font-size:17px; line-height:1.3; color:#0f172a;">{{ $category->name }}</h3>
                            <p style="margin:8px 0 0; color:#64748b; font-size:13px; line-height:1.6;">{{ $category->description ?: 'Internal help articles and working notes.' }}</p>
                        </div>
                    </a>
                @empty
                    <div style="grid-column:1/-1; padding:18px; border-radius:18px; border:1px dashed #dbe3ef; background:#fff; color:#64748b; font-size:13px; line-height:1.7;">
                        No knowledge categories are available yet.
                    </div>
                @endforelse
            </div>
        </section>

        <section style="display:grid; gap:14px;">
            <div>
                <h2 style="margin:0; font-size:20px; color:#0f172a;">Featured articles</h2>
                <p style="margin:6px 0 0; color:#64748b; font-size:13px;">High-priority SOPs and walkthroughs for common workflows.</p>
            </div>
            @if($featuredArticles->isEmpty())
                <div style="padding:18px; border-radius:18px; border:1px dashed #dbe3ef; background:#fff; color:#64748b; font-size:13px; line-height:1.7;">
                    No featured articles match the current search.
                </div>
            @else
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:14px;">
                    @foreach($featuredArticles as $article)
                        @php($tone = $typeTone[$article->type] ?? $typeTone['guide'])
                        <a href="{{ route('knowledge.articles.show', $article) }}" class="rn-knowledge-article-card" style="display:grid; gap:12px; padding:18px; border-radius:18px; border:1px solid #dbe3ef; background:#fff; box-shadow:0 14px 28px rgba(15,23,42,.05);" aria-label="Read article: {{ $article->title }}">
                            <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                                <span style="display:inline-flex; align-items:center; min-height:28px; padding:0 10px; border-radius:999px; background:{{ $tone['bg'] }}; border:1px solid {{ $tone['border'] }}; color:{{ $tone['text'] }}; font-size:11px; font-weight:800; letter-spacing:.06em; text-transform:uppercase;">{{ $typeLabels[$article->type] ?? strtoupper($article->type) }}</span>
                                @if($article->category)
                                    <span style="color:#64748b; font-size:12px; font-weight:700;">{{ $article->category->name }}</span>
                                @endif
                            </div>
                            <div>
                                <h3 class="rn-knowledge-article-title" style="margin:0; font-size:18px; line-height:1.35;">{{ $article->title }}</h3>
                                <p style="margin:8px 0 0; color:#64748b; font-size:13px; line-height:1.65;">{{ $article->excerpt ?: \Illuminate\Support\Str::limit(strip_tags($article->content), 150) }}</p>
                            </div>
                            <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; color:#64748b; font-size:12px; font-weight:700;">
                                <span>{{ optional($article->published_at)->format('d M Y') ?: optional($article->updated_at)->format('d M Y') }}</span>
                                <span class="rn-knowledge-read-btn">
                                    <span>Read</span>
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"></path><path d="m13 5 7 7-7 7"></path></svg>
                                </span>
                            </div>
                        </a>
                    @endforeach
                </div>
            @endif
        </section>

        <section style="display:grid; gap:14px;">
            <div>
                <h2 style="margin:0; font-size:20px; color:#0f172a;">Recent articles</h2>
                <p style="margin:6px 0 0; color:#64748b; font-size:13px;">Latest published help notes and team guidance.</p>
            </div>
            @if($recentArticles->isEmpty())
                <div style="padding:20px; border-radius:18px; border:1px dashed #dbe3ef; background:#fff; color:#64748b; font-size:13px; line-height:1.7;">
                    No knowledge articles are published yet.
                </div>
            @else
                <div style="display:grid; gap:12px;">
                    @foreach($recentArticles as $article)
                        @php($tone = $typeTone[$article->type] ?? $typeTone['guide'])
                        <a href="{{ route('knowledge.articles.show', $article) }}" class="rn-knowledge-article-card" style="display:grid; gap:10px; padding:16px 18px; border-radius:18px; border:1px solid #dbe3ef; background:#fff; box-shadow:0 12px 24px rgba(15,23,42,.04);" aria-label="Read article: {{ $article->title }}">
                            <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                                <div style="flex:1 1 280px;">
                                    <h3 class="rn-knowledge-article-title" style="margin:0; font-size:17px; line-height:1.35;">{{ $article->title }}</h3>
                                    <p style="margin:7px 0 0; color:#64748b; font-size:13px; line-height:1.65;">{{ $article->excerpt ?: \Illuminate\Support\Str::limit(strip_tags($article->content), 180) }}</p>
                                </div>
                                <div style="display:flex; align-items:center; justify-content:flex-end; gap:10px; flex:0 0 auto; flex-wrap:wrap;">
                                    <span style="display:inline-flex; align-items:center; min-height:28px; padding:0 10px; border-radius:999px; background:{{ $tone['bg'] }}; border:1px solid {{ $tone['border'] }}; color:{{ $tone['text'] }}; font-size:11px; font-weight:800; letter-spacing:.06em; text-transform:uppercase; flex:0 0 auto;">{{ $typeLabels[$article->type] ?? strtoupper($article->type) }}</span>
                                    <span class="rn-knowledge-read-btn">
                                        <span>Read</span>
                                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"></path><path d="m13 5 7 7-7 7"></path></svg>
                                    </span>
                                </div>
                            </div>
                            <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap; color:#64748b; font-size:12px; font-weight:700;">
                                <span>{{ $article->category?->name ?: 'General' }}</span>
                                <span>&bull;</span>
                                <span>{{ optional($article->published_at)->format('d M Y') ?: optional($article->updated_at)->format('d M Y') }}</span>
                            </div>
                        </a>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
@endsection
