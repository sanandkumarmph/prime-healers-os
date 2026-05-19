@extends('layouts.app')

@section('content')
<form action="{{ route('business-partners.update', $businessPartner) }}" method="POST">
    @csrf
    @method('PUT')
    @include('business-partners._form', ['businessPartner' => $businessPartner])
</form>
@endsection
