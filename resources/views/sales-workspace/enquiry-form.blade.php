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
            <div class="sw-field"><label for="owner_id">Assigned to *</label><select name="owner_id" id="owner_id" required>@foreach($owners as $owner)@if(auth()->user()->scopeType('enquiries') === 'all' || $owner->id === auth()->id())<option value="{{ $owner->id }}" @selected((int)old('owner_id',$enquiry?->owner_id ?? auth()->id())===$owner->id)>{{ $owner->name }}{{ $owner->id===auth()->id() ? ' (me)' : '' }}</option>@endif @endforeach</select></div>
            <div class="sw-field sw-full"><label for="subject">What do they need? *</label><input id="subject" name="subject" value="{{ old('subject',$enquiry?->subject) }}" required maxlength="255" placeholder="e.g. Oxygen concentrator for one month"></div>
        </div>
        <details @if($errors->any() || $enquiry) open @endif>
            <summary>More details <span class="sw-meta">Email, source, notes and existing customer</span></summary>
            <div class="sw-fields">
                <div class="sw-field"><label for="email">Email</label><input id="email" type="email" name="email" value="{{ old('email',$enquiry?->email) }}" maxlength="255" autocomplete="email"></div>
                <div class="sw-field"><label for="source">Enquiry source</label><input id="source" name="source" value="{{ old('source',$enquiry?->source) }}" list="enquiry-sources" maxlength="80" placeholder="Choose or type"><datalist id="enquiry-sources"><option value="Phone"><option value="WhatsApp"><option value="Website"><option value="Walk-in"><option value="Referral"><option value="Social media"></datalist></div>
                <div class="sw-field sw-full"><label for="requirements">Internal notes</label><textarea id="requirements" name="requirements" rows="2" maxlength="5000" placeholder="Dates, quantities or questions to clarify">{{ old('requirements',$enquiry?->requirements) }}</textarea></div>
                <div class="sw-full">@include('sales-workspace.customer-picker',['quote'=>null])</div>
            </div>
        </details>
        <footer class="sw-footer"><a href="{{ $enquiry ? route('enquiries.show',$enquiry) : route('enquiries.index') }}">Cancel</a><div class="sw-actions">
            <button class="sw-btn {{ $enquiry ? 'sw-primary' : '' }}" name="next" value="enquiry">{{ $enquiry ? 'Save changes' : 'Save enquiry' }}</button>
            @if(!$enquiry && auth()->user()->canAccessModule('quotations','create') && auth()->user()->canAccessModule('enquiries','update'))<button class="sw-btn sw-primary" name="next" value="quotation">Save & create quotation</button>@endif
        </div></footer>
    </form>
</div>
@if(auth()->user()->canAccessModule('customers','create'))
@include('customers.partials.quick-create-modal', ['quickModalId'=>'salesQuickCustomerModal','quickFormId'=>'salesQuickCustomerForm','quickSelectTarget'=>'customer_id','quickPrefill'=>['name'=>$enquiry?->name,'phone'=>$enquiry?->phone,'email'=>$enquiry?->email]])
@endif
@endsection
@push('scripts')@include('sales-workspace.customer-script')@endpush
