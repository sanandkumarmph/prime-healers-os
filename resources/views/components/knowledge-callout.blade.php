@props([
    'tone' => 'tip',
    'title' => '',
    'body' => '',
])

@php
    $tones = [
        'tip' => ['bg' => '#ecfeff', 'border' => '#a5f3fc', 'text' => '#155e75', 'icon' => 'spark'],
        'warning' => ['bg' => '#fff7ed', 'border' => '#fdba74', 'text' => '#9a3412', 'icon' => 'warning'],
        'important' => ['bg' => '#eff6ff', 'border' => '#93c5fd', 'text' => '#1d4ed8', 'icon' => 'important'],
        'example' => ['bg' => '#f5f3ff', 'border' => '#c4b5fd', 'text' => '#6d28d9', 'icon' => 'example'],
    ];
    $style = $tones[$tone] ?? $tones['tip'];
@endphp

<section style="display:flex; align-items:flex-start; gap:12px; padding:16px; border-radius:18px; background:{{ $style['bg'] }}; border:1px solid {{ $style['border'] }}; color:{{ $style['text'] }};">
    <span style="display:inline-flex; width:32px; height:32px; align-items:center; justify-content:center; border-radius:12px; background:rgba(255,255,255,.72); flex:0 0 auto;">
        @if($style['icon'] === 'warning')
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 9v4"></path><path d="M12 17h.01"></path><path d="M10.29 3.86l-7.18 12.42A2 2 0 0 0 4.82 19h14.36a2 2 0 0 0 1.71-3l-7.18-12.14a2 2 0 0 0-3.42 0z"></path></svg>
        @elseif($style['icon'] === 'important')
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 16v-4"></path><path d="M12 8h.01"></path><circle cx="12" cy="12" r="9"></circle></svg>
        @elseif($style['icon'] === 'example')
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 5h16v14H4z"></path><path d="M8 9h8"></path><path d="M8 13h5"></path></svg>
        @else
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l1.9 5.8H20l-5 3.7 1.9 5.8L12 14.6 7.1 18.3 9 12.5 4 8.8h6.1L12 3z"></path></svg>
        @endif
    </span>
    <div style="display:grid; gap:6px;">
        <div style="font-size:12px; font-weight:800; letter-spacing:.08em; text-transform:uppercase;">{{ ucfirst($tone) }}</div>
        <div style="font-size:15px; font-weight:800;">{{ $title }}</div>
        <div style="font-size:14px; line-height:1.75;">{{ $body }}</div>
    </div>
</section>
