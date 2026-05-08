@props([
    'title' => 'Quick view',
    'items' => [],
])

@if(!empty($items))
    <section style="display:grid; gap:14px;">
        <div style="font-size:16px; font-weight:800; color:#0f172a;">{{ $title }}</div>
        <div style="display:grid; gap:14px; grid-template-columns:repeat(auto-fit,minmax(180px,1fr));">
            @foreach($items as $item)
                <article style="background:#fff; border:1px solid #dbe3ef; border-radius:18px; padding:16px; display:grid; gap:8px;">
                    <div style="font-size:11px; font-weight:800; letter-spacing:.08em; text-transform:uppercase; color:#64748b;">{{ $item['title'] ?? '' }}</div>
                    <div style="font-size:18px; font-weight:900; color:#0f172a;">{{ $item['value'] ?? '' }}</div>
                    <div style="color:#475569; font-size:13px; line-height:1.7;">{{ $item['description'] ?? '' }}</div>
                </article>
            @endforeach
        </div>
    </section>
@endif
