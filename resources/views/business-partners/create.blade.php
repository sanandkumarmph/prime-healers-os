@extends('layouts.app')

@section('content')
<form action="{{ route('business-partners.store') }}" method="POST">
    @csrf
    @include('business-partners._form')
</form>
@endsection
