@extends('layouts.app')

@section('content')
<div class="ph-mobile-form-page">
    <form id="saleForm" action="{{ route('sales.store') }}" method="POST" novalidate>
        @csrf
        @include('sales.partials.form', ['sale' => null])
    </form>
</div>

@include('customers.partials.quick-create-modal', [
    'quickModalId' => 'saleQuickCustomerModal',
    'quickFormId' => 'saleQuickCustomerForm',
    'quickSelectTarget' => 'customer_id',
    'quickRoute' => route('sales.customers.quick-store'),
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
