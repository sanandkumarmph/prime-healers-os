@php($selectedCustomer = old('customer_id', $quote?->snapshot['customer_id'] ?? $enquiry?->customer_id))
<div class="sw-field"><label for="customer-search">Find a customer</label><input id="customer-search" type="search" placeholder="Search name, phone or email" autocomplete="off" aria-controls="customer_id"></div>
<div class="sw-field" style="margin-top:10px">
    <label for="customer_id">Quotation addressed to</label>
    <select id="customer_id" name="customer_id" data-customer-picker>
        <option value="">Select a customer or add a new one</option>
        @foreach($customers as $customer)
            <option value="{{ $customer->id }}" data-name="{{ $customer->name }}" data-phone="{{ $customer->phone }}" data-phone-normalized="{{ \App\Support\PhoneNumber::normalize($customer->phone) }}" data-email="{{ $customer->email }}" @selected((string)$selectedCustomer === (string)$customer->id)>{{ $customer->name }}{{ $customer->phone ? ' · '.$customer->phone : '' }}</option>
        @endforeach
    </select>
    @error('customer_id')<span class="sw-overdue">{{ $message }}</span>@enderror
</div>
<div id="customer-suggestions" class="sw-meta" aria-live="polite"></div>
<p id="customer-selection-note" class="sw-meta">Select the billing recipient. The enquiry contact stays unchanged.</p>
@if(auth()->user()->canAccessModule('customers','create'))<button type="button" class="sw-btn sw-small" data-open-modal="salesQuickCustomerModal">+ Add customer</button>@endif
