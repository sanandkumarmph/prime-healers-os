@extends('layouts.app')

@section('focused_form', true)

@section('content')
<div class="container ph-mobile-form-page rental-focused-form-page">
    <form id="rentalCreateForm" action="{{ route('rentals.store') }}" method="POST">
        @csrf
        @include('rentals._form')
    </form>
</div>

@include('customers.partials.quick-create-modal', [
    'quickModalId' => 'rentalQuickCustomerModal',
    'quickFormId' => 'rentalQuickCustomerForm',
    'quickSelectTarget' => 'customer_id',
    'quickRoute' => route('rentals.customers.quick-store'),
])
@include('partials.business-partner-quick-modals', [
    'quickPartnerModalId' => 'rentalBusinessPartnerModal',
    'quickPartnerFormId' => 'rentalBusinessPartnerForm',
    'quickPartnerSelectTarget' => 'business_partner_id',
    'quickClientModalId' => 'rentalPartnerClientModal',
    'quickClientFormId' => 'rentalPartnerClientForm',
    'quickClientSelectTarget' => 'partner_client_id',
    'quickClientPartnerSource' => 'business_partner_id',
])
@endsection
