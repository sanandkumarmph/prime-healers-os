@extends('layouts.app')

@section('content')
<form action="{{ route('sales.store') }}" method="POST">
    @csrf
    @include('sales.partials.form', ['sale' => null])
</form>

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
