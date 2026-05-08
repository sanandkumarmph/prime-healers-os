@props([
    'title' => 'Checklist',
    'items' => [],
])

@if(!empty($items))
    <section style="background:#fff; border:1px solid #dbe3ef; border-radius:20px; padding:18px; display:grid; gap:12px;">
        <div style="font-size:16px; font-weight:800; color:#0f172a;">{{ $title }}</div>
        <div style="display:grid; gap:10px;">
            @foreach($items as $item)
                <div style="display:flex; align-items:flex-start; gap:10px; padding:10px 12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:14px;">
                    <span style="display:inline-flex; width:22px; height:22px; align-items:center; justify-content:center; border-radius:999px; background:#dcfce7; color:#166534; flex:0 0 auto;">
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6L9 17l-5-5"></path></svg>
                    </span>
                    <div style="color:#1e293b; font-size:14px; line-height:1.7;">{{ $item }}</div>
                </div>
            @endforeach
        </div>
    </section>
@endif
