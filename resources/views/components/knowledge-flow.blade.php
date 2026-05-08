@props([
    'label' => 'Process flow',
    'steps' => [],
])

@if(!empty($steps))
    <section style="display:grid; gap:12px; background:#fff; border:1px solid #dbe3ef; border-radius:20px; padding:18px;">
        <div style="font-size:12px; font-weight:800; letter-spacing:.08em; text-transform:uppercase; color:#64748b;">{{ $label }}</div>
        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
            @foreach($steps as $index => $step)
                <span style="display:inline-flex; align-items:center; min-height:42px; padding:0 14px; border-radius:999px; background:#f8fafc; border:1px solid #dbe3ef; color:#0f172a; font-size:13px; font-weight:800;">
                    {{ $step }}
                </span>
                @if(!$loop->last)
                    <span style="color:#94a3b8; font-weight:800; font-size:18px;" aria-hidden="true">&rarr;</span>
                @endif
            @endforeach
        </div>
    </section>
@endif
