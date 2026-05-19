@extends('layouts.app')

@section('content')
<form action="{{ route('business-partners.clients.store', $businessPartner) }}" method="POST">
    @csrf
    @include('business-partners.clients._form', ['businessPartner' => $businessPartner])
</form>
@endsection
