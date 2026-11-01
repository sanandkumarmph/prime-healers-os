@php
    // Only use attributes of the already-authorized record; never load relations here.
    $recordTimestampFields = $timestampFields ?? ['created_at' => 'Created', 'updated_at' => 'Last updated'];
@endphp
<section aria-label="Record timestamps" data-record-timestamps style="margin:16px 0; padding:12px 16px; border:1px solid #dbe3ef; border-radius:12px; background:#fff;">
    <dl style="display:flex; flex-wrap:wrap; gap:12px 28px; margin:0;">
        @foreach($recordTimestampFields as $timestampField => $timestampLabel)
            @php($recordActionAt = $timestampRecord->getAttribute($timestampField))
            @if($recordActionAt !== null || in_array($timestampField, ['created_at', 'updated_at'], true))
                <div style="flex:1 1 225px; min-width:0;">
                    <dt style="font-size:12px; color:#64748b; font-weight:600;">{{ $timestampLabel }}</dt>
                    <dd style="margin:3px 0 0; font-size:13px; color:#334155; line-height:1.5;">@include('partials.action-time', ['actionAt' => $recordActionAt, 'clockOnly' => false])</dd>
                </div>
            @endif
        @endforeach
    </dl>
</section>
