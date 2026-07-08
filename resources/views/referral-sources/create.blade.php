@extends('layouts.app')

@section('content')
<div style="max-width:920px; margin:0 auto;">
    <div style="display:flex; justify-content:space-between; gap:16px; align-items:flex-start; margin-bottom:18px;">
        <div>
            <h1 style="margin:0 0 6px; font-size:30px;">Add Referral Source</h1>
            <p style="margin:0; color:#64748b;">Create a standardized referrer record for rentals and sales.</p>
        </div>
        <a href="{{ route('referral-sources.index') }}" style="padding:10px 14px; border-radius:12px; border:1px solid #cbd5e1; color:#0f172a; text-decoration:none; font-weight:800;">Back</a>
    </div>

    <form method="POST" action="{{ route('referral-sources.store') }}">
        @include('referral-sources._form')
    </form>
</div>
@endsection
