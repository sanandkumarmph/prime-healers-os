@extends('layouts.app')

@section('content')
<form id="business-partner-form" action="{{ route('business-partners.store') }}" method="POST">
    @csrf
    @include('business-partners._form')
</form>
@endsection
