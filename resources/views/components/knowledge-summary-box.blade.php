@props([
    'purpose' => null,
    'whenToUse' => null,
    'whoShouldUse' => null,
    'commonMistakes' => [],
])

<section style="display:grid; gap:14px; background:linear-gradient(135deg,#f8fbff 0%,#f5f3ff 100%); border:1px solid #d7e3f3; border-radius:20px; padding:18px;">
    <div style="display:flex; align-items:center; gap:10px;">
        <span style="display:inline-flex; width:34px; height:34px; align-items:center; justify-content:center; border-radius:12px; background:#e0e7ff; color:#4338ca;">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l8 4v6c0 5-3.5 7.5-8 8-4.5-.5-8-3-8-8V7l8-4z"></path><path d="M9.5 12l1.8 1.8L15 10.2"></path></svg>
        </span>
        <div>
            <div style="font-size:12px; font-weight:800; letter-spacing:.08em; text-transform:uppercase; color:#64748b;">Quick summary</div>
            <div style="font-size:15px; font-weight:800; color:#0f172a;">Know the purpose before you start</div>
        </div>
    </div>

    <div style="display:grid; gap:12px; grid-template-columns:repeat(auto-fit,minmax(180px,1fr));">
        @if($purpose)
            <div style="background:#fff; border:1px solid #e2e8f0; border-radius:16px; padding:14px;">
                <div style="font-size:11px; font-weight:800; letter-spacing:.08em; text-transform:uppercase; color:#64748b;">Purpose</div>
                <div style="margin-top:6px; color:#1e293b; font-size:14px; line-height:1.7;">{{ $purpose }}</div>
            </div>
        @endif
        @if($whenToUse)
            <div style="background:#fff; border:1px solid #e2e8f0; border-radius:16px; padding:14px;">
                <div style="font-size:11px; font-weight:800; letter-spacing:.08em; text-transform:uppercase; color:#64748b;">When to use</div>
                <div style="margin-top:6px; color:#1e293b; font-size:14px; line-height:1.7;">{{ $whenToUse }}</div>
            </div>
        @endif
        @if($whoShouldUse)
            <div style="background:#fff; border:1px solid #e2e8f0; border-radius:16px; padding:14px;">
                <div style="font-size:11px; font-weight:800; letter-spacing:.08em; text-transform:uppercase; color:#64748b;">Who should use</div>
                <div style="margin-top:6px; color:#1e293b; font-size:14px; line-height:1.7;">{{ $whoShouldUse }}</div>
            </div>
        @endif
    </div>

    @if(!empty($commonMistakes))
        <div style="background:#fff7ed; border:1px solid #fed7aa; border-radius:16px; padding:14px;">
            <div style="font-size:11px; font-weight:800; letter-spacing:.08em; text-transform:uppercase; color:#c2410c;">Common mistakes</div>
            <ul style="margin:10px 0 0; padding-left:18px; color:#7c2d12; font-size:14px; line-height:1.8;">
                @foreach($commonMistakes as $mistake)
                    <li>{{ $mistake }}</li>
                @endforeach
            </ul>
        </div>
    @endif
</section>
