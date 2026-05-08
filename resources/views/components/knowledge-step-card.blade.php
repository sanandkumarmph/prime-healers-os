@props([
    'number' => 1,
    'title' => '',
    'description' => '',
])

<article style="background:#fff; border:1px solid #dbe3ef; border-radius:18px; padding:16px; display:grid; gap:10px; height:100%;">
    <div style="display:flex; align-items:center; gap:10px;">
        <span style="display:inline-flex; width:34px; height:34px; align-items:center; justify-content:center; border-radius:12px; background:#dbeafe; color:#1d4ed8; font-size:13px; font-weight:900;">{{ $number }}</span>
        <div style="font-size:15px; font-weight:800; color:#0f172a;">{{ $title }}</div>
    </div>
    <div style="color:#475569; font-size:14px; line-height:1.75;">{{ $description }}</div>
</article>
