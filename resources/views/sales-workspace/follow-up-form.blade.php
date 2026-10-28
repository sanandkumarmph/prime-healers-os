@php
    $tz = $enquiry->organization->timezone ?: 'UTC';
    $followSubject = isset($quote) ? 'Follow up on '.$quote->number() : 'Follow up on '.$enquiry->subject;
    $tomorrow = now($tz)->addDay()->setTime(10,0)->format('Y-m-d\TH:i');
@endphp
<div class="sw-follow-up">
    <form action="{{ route('enquiries.follow-up',$enquiry) }}" method="post">
        @csrf
        <input type="hidden" name="subject" value="{{ \Illuminate\Support\Str::limit($followSubject,255,'') }}">
        <input type="hidden" name="channel" value="call"><input type="hidden" name="due_at" value="{{ $tomorrow }}">
        <button class="sw-btn" type="submit">Remind me tomorrow</button>
        <p class="sw-meta">10:00 · {{ $tz }} · Internal reminder</p>
    </form>
    <details @if($errors->has('due_at') || $errors->has('channel') || $errors->has('subject')) open @endif>
        <summary>Choose date or add details</summary>
        <form action="{{ route('enquiries.follow-up',$enquiry) }}" method="post">@csrf
            <div class="sw-fields">
                <div class="sw-field"><label for="follow-subject">Next action *</label><input id="follow-subject" name="subject" required maxlength="255" value="{{ old('subject',\Illuminate\Support\Str::limit($followSubject,255,'')) }}"></div>
                <div class="sw-field"><label for="due_at">When ({{ $tz }}) *</label><input type="datetime-local" id="due_at" name="due_at" required value="{{ old('due_at',$tomorrow) }}"></div>
                <div class="sw-field"><label for="channel">Channel</label><select id="channel" name="channel">@foreach(['call'=>'Phone call','whatsapp'=>'WhatsApp','email'=>'Email','meeting'=>'Meeting'] as $key=>$label)<option value="{{ $key }}" @selected(old('channel')===$key)>{{ $label }}</option>@endforeach</select></div>
            </div><button class="sw-btn sw-primary" style="margin-top:12px">Schedule follow-up</button>
        </form>
    </details>
</div>
