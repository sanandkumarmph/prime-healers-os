@extends('layouts.app')
@section('content')
<link rel="stylesheet" href="{{ asset('css/phos-cylinder-refills.css') }}"><div class="cr-standalone"><a href="{{ route('rentals.show',$rental) }}">Back to rental</a><h1>Cylinder refill</h1>@include('cylinder-refills.content')</div>
<script src="{{ asset('js/phos-cylinder-refills.js') }}" defer></script>
@endsection
