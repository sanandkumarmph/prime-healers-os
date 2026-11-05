@extends('layouts.app')
@section('content')
@include('sales-workspace.style')
<div class="sw sw-capture">
    <a href="{{ $enquiry ? route('enquiries.show',$enquiry) : route('enquiries.index') }}">← {{ $enquiry ? 'Back to enquiry' : 'Sales workspace' }}</a>
    <header class="sw-head"><div><h1>{{ $enquiry ? 'Edit enquiry' : 'New enquiry' }}</h1><p class="sw-muted">Capture the need now. Add prices when you are ready.</p></div></header>
    @include('sales-workspace.alerts')
    <form method="post" action="{{ $enquiry ? route('enquiries.update',$enquiry) : route('enquiries.store') }}" class="sw-panel" id="enquiry-form">
        @csrf
        @if($enquiry)@method('PUT')<input type="hidden" name="lock_version" value="{{ old('lock_version',$enquiry->lock_version) }}">@endif
        <div class="sw-fields">
            <div class="sw-field"><label for="name">Contact name *</label><input id="name" name="name" value="{{ old('name',$enquiry?->name) }}" required maxlength="255" autocomplete="name" autofocus></div>
            <div class="sw-field"><label for="phone">Phone</label><input id="phone" type="tel" name="phone" value="{{ old('phone',$enquiry?->phone) }}" maxlength="40" autocomplete="tel"></div>
            <div class="sw-field"><label for="intent">Interested in</label><select id="intent" name="intent"><option value="">Not decided yet</option><option value="rental" @selected(old('intent',$enquiry?->intent)==='rental')>Rental</option><option value="sale" @selected(old('intent',$enquiry?->intent)==='sale')>Sale</option></select></div>
            <div class="sw-field">
                <label for="source">Enquiry source *</label>
                <select id="source" name="source" required>
                    <option value="">Choose source</option>
                    @foreach(\App\Models\Enquiry::SOURCES as $key => $label)
                        <option value="{{ $key }}" @selected(old('source',$enquiry?->source) === $key)>{{ $label }}</option>
                    @endforeach
                </select>
                @if($enquiry?->source && !isset(\App\Models\Enquiry::SOURCES[$enquiry->source]))
                    <small class="sw-muted">Previously entered as {{ $enquiry->source }}. Choose the matching source to save.</small>
                @endif
                @error('source')<p class="sw-muted" role="alert">{{ $message }}</p>@enderror
            </div>
            <div class="sw-field"><label for="owner_id">Assigned to *</label><select name="owner_id" id="owner_id" required>@foreach($owners as $owner)@if(auth()->user()->scopeType('enquiries') === 'all' || $owner->id === auth()->id())<option value="{{ $owner->id }}" @selected((int)old('owner_id',$enquiry?->owner_id ?? auth()->id())===$owner->id)>{{ $owner->name }}{{ $owner->id===auth()->id() ? ' (me)' : '' }}</option>@endif @endforeach</select></div>
            <div class="sw-field sw-full"><label for="subject">What do they need? *</label><input id="subject" name="subject" value="{{ old('subject',$enquiry?->subject) }}" required maxlength="255" placeholder="e.g. Oxygen concentrator for one month"></div>
        </div>
        <details @if($errors->any() || $enquiry) open @endif>
            <summary>More details <span class="sw-meta">Email, notes and existing customer</span></summary>
            <div class="sw-fields">
                <div class="sw-field"><label for="email">Email</label><input id="email" type="email" name="email" value="{{ old('email',$enquiry?->email) }}" maxlength="255" autocomplete="email"></div>
                <div class="sw-field sw-full"><label for="requirements">Internal notes</label><textarea id="requirements" name="requirements" rows="2" maxlength="5000" placeholder="Dates, quantities or questions to clarify">{{ old('requirements',$enquiry?->requirements) }}</textarea></div>
                <div class="sw-full">@include('sales-workspace.customer-picker',['quote'=>null])</div>
            </div>
        </details>
        <footer class="sw-footer"><a href="{{ $enquiry ? route('enquiries.show',$enquiry) : route('enquiries.index') }}">Cancel</a><div class="sw-actions">
            <button class="sw-btn sw-primary" name="next" value="enquiry">{{ $enquiry ? 'Save changes' : 'Save enquiry' }}</button>
        </div></footer>
    </form>
</div>
@endsection
@push('scripts')@include('sales-workspace.customer-script')@endpush
