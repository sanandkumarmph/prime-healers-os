@php($selectedCustomer = old('customer_id', $quote?->snapshot['customer_id'] ?? $enquiry?->customer_id))
<div class="sw-field sw-customer-picker" data-sw-customer-picker>
    <label for="customer_id" data-customer-picker-label>Existing customer (optional)</label>
    <select id="customer_id" name="customer_id" data-customer-picker data-searchable-select>
        <option value="">Use enquiry contact — no customer record needed</option>
        @foreach($customers as $customer)
            <option value="{{ $customer->id }}" data-name="{{ $customer->name }}" data-salutation="{{ $customer->salutation }}" data-phone="{{ $customer->phone }}" data-phone-normalized="{{ \App\Support\PhoneNumber::normalize($customer->phone) }}" data-email="{{ $customer->email }}" @selected((string)$selectedCustomer === (string)$customer->id)>{{ $customer->name }}{{ $customer->phone ? ' · '.$customer->phone : '' }}{{ $customer->email ? ' · '.$customer->email : '' }}</option>
        @endforeach
    </select>
    @error('customer_id')<span class="sw-overdue">{{ $message }}</span>@enderror
</div>
<p id="customer-selection-note" class="sw-meta" role="status">Use the enquiry contact, or select an existing customer.</p>
