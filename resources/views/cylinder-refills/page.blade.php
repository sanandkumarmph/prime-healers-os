@extends('layouts.app')
@section('content')
<link rel="stylesheet" href="{{ asset('css/phos-cylinder-refills.css') }}"><div class="cr-standalone"><a href="{{ route('rentals.show',$rental) }}">Back to rental</a><h1>Cylinder refill</h1><div data-cylinder-refill-body>@include('cylinder-refills.content')</div></div>
<script src="{{ asset('js/phos-cylinder-refills.js') }}?v={{ hash_file('sha256',public_path('js/phos-cylinder-refills.js')) }}" defer></script>
@endsection
