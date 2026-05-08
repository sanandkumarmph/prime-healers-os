@extends('layouts.app')

@section('content')
<div style="max-width:1220px; margin:0 auto; padding:10px 0 18px;">
    <form action="{{ route('deliveries.store') }}" method="POST">
        @csrf
        @include('deliveries._form')
    </form>
</div>
@endsection
