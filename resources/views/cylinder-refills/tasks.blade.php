@extends('layouts.app')
@section('content')
<link rel="stylesheet" href="{{ asset('css/phos-cylinder-refills.css') }}"><div class="cr-standalone"><h1>Cylinder refills &amp; empty pickup</h1><p>Only your permitted assignments are shown. Deliver the refill and record empty-cylinder pickup.</p>@include('cylinder-refills.tasks-content')</div>
<script src="{{ asset('js/phos-cylinder-refills.js') }}?v={{ hash_file('sha256',public_path('js/phos-cylinder-refills.js')) }}" defer></script>
@endsection
