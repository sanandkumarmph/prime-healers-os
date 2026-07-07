@extends('layouts.app')

@section('content')
<style>
    .customer-form-page { padding: 18px 22px 28px; display: grid; gap: 16px; }
    .customer-form-header { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap; }
    .customer-form-header h1 { margin:0; font-size:28px; color:#0f172a; }
    .customer-form-header p { margin:6px 0 0; color:#64748b; font-size:13px; }
    .form-card {
        background:#fff;
        border:1px solid #dbe3ef;
        border-radius:14px;
        box-shadow:0 8px 24px rgba(15, 23, 42, 0.04);
    }
    .form-card-body { padding:18px; }
    .ops-btn,
    .ops-btn-light {
        display:inline-flex;
        align-items:center;
        justify-content:center;
        gap:6px;
        padding:8px 12px;
        border-radius:10px;
        font-size:13px;
        font-weight:600;
        text-decoration:none;
        border:1px solid transparent;
    }
    .ops-btn { background:#0f172a; color:#fff; }
    .ops-btn-light { background:#fff; color:#334155; border-color:#cbd5e1; }
    .error-box {
        background:#fee2e2;
        border:1px solid #fecaca;
        color:#991b1b;
        border-radius:12px;
        padding:14px 16px;
    }
    .error-box ul { margin:0; padding-left:18px; }
    @media (max-width: 720px) {
        .customer-form-page { padding:14px; }
        .customer-form-header {
            gap:8px;
            align-items:flex-start;
        }
        .customer-form-header h1 {
            font-size:22px;
            line-height:1.1;
        }
        .customer-form-header p {
            margin-top:2px;
            font-size:11px;
            line-height:1.4;
            max-width:28ch;
        }
        .customer-form-header .ops-btn-light {
            min-height:38px;
            padding:8px 11px;
            font-size:12px;
            border-radius:11px;
        }
        .form-card-body {
            padding:12px;
        }
    }
</style>

<div class="container customer-form-page">
    <div class="customer-form-header">
        <div>
            <h1>Add Customer</h1>
            <p>Create a clean customer record with contact details, WhatsApp access, location, and billing information.</p>
        </div>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <a href="{{ route('customers.index') }}" class="ops-btn-light">Back to Customers</a>
        </div>
    </div>

    @if ($errors->any())
        <div class="error-box">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="form-card">
        <div class="form-card-body">
            <form action="{{ route('customers.store') }}" method="POST" enctype="multipart/form-data">
                @csrf
                @include('customers.partials.form', [
                    'customer' => null,
                    'submitLabel' => 'Save Customer',
                    'cancelUrl' => route('customers.index'),
                ])
            </form>
        </div>
    </div>
</div>
@endsection
