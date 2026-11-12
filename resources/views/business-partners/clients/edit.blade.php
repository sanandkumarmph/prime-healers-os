@extends('layouts.app')

@section('content')
<form id="actual-client-form" action="{{ route('business-partners.clients.update', [$businessPartner, $partnerClient]) }}" method="POST">
    @csrf
    @method('PUT')
    @include('business-partners.clients._form', ['businessPartner' => $businessPartner, 'partnerClient' => $partnerClient])
</form>
@endsection
