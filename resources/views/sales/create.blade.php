@extends('layouts.app')

@section('content')
@include('sales.partials.stock-modal')

<div class="ph-mobile-form-page">
    <form id="saleForm" action="{{ route('sales.store') }}" method="POST" novalidate>
        @include('sales-workspace.conversion-banner')
        @csrf
        @include('sales.partials.form', ['sale' => null])
    </form>
</div>

@include('customers.partials.quick-create-modal', [
    'quickModalId' => 'saleQuickCustomerModal',
    'quickFormId' => 'saleQuickCustomerForm',
    'quickSelectTarget' => 'customer_id',
    'quickRoute' => route('sales.customers.quick-store'),
    'quickPrefill' => request()->attributes->get('conversion_quote')?->snapshot['recipient'] ?? [],
    'quickAutoOpen' => request()->attributes->get('conversion_quote') && !data_get(request()->attributes->get('conversion_quote')->snapshot, 'customer_id') && !old('customer_id') && !$errors->any(),
])
@include('partials.business-partner-quick-modals', [
    'quickPartnerModalId' => 'saleBusinessPartnerModal',
    'quickPartnerFormId' => 'saleBusinessPartnerForm',
    'quickPartnerSelectTarget' => 'business_partner_id',
    'quickClientModalId' => 'salePartnerClientModal',
    'quickClientFormId' => 'salePartnerClientForm',
    'quickClientSelectTarget' => 'partner_client_id',
    'quickClientPartnerSource' => 'business_partner_id',
])
@endsection
