@extends(request()->boolean('embedded') ? 'layouts.embedded' : 'layouts.app')

@section('content')
<div class="delivery-assignment-modal-page {{ request()->boolean('embedded') ? 'is-embedded' : '' }}">
    <form action="{{ route('deliveries.update', $delivery) }}" method="POST" class="delivery-assignment-modal-panel">
        @csrf
        @method('PUT')
        @if(request()->boolean('embedded'))
            <input type="hidden" name="embedded" value="1">
        @endif
        @include('deliveries._form')
    </form>
</div>
<style>
    .desktop-breadcrumb {
        display:none !important;
    }
    .delivery-assignment-modal-page {
        min-height:calc(100dvh - 118px);
        display:flex;
        align-items:flex-start;
        justify-content:center;
        padding:12px 8px 18px;
        background:linear-gradient(180deg, rgba(241,245,249,.72), rgba(248,250,252,.2));
    }
    .delivery-assignment-modal-panel {
        width:min(760px, 100%);
        max-height:calc(100dvh - 142px);
        display:flex;
        flex-direction:column;
        overflow:hidden;
        border:1px solid #dbe3ef;
        border-radius:20px;
        background:#fff;
        box-shadow:0 24px 64px rgba(15,23,42,.18);
    }
    .delivery-assignment-modal-page .ops-shell {
        gap:0;
        min-height:0;
        display:flex;
        flex-direction:column;
        overflow:hidden;
    }
    .delivery-assignment-modal-page .ops-header:first-child {
        flex:0 0 auto;
        padding:12px 14px;
        border-bottom:1px solid #e2e8f0;
        background:#fff;
    }
    .delivery-assignment-modal-page .ops-header:first-child h1 {
        font-size:20px;
        line-height:1.15;
    }
    .delivery-assignment-modal-page .ops-header:first-child p {
        margin-top:3px;
        font-size:11.5px;
    }
    .delivery-assignment-modal-page .ops-header:first-child .ops-actions {
        gap:6px;
    }
    .delivery-assignment-modal-page .ops-shell > .ops-error,
    .delivery-assignment-modal-page .ops-shell > .ops-card {
        margin:10px 12px 0;
    }
    .delivery-assignment-modal-page .ops-shell {
        overflow-y:auto;
        -webkit-overflow-scrolling:touch;
    }
    .delivery-assignment-modal-page .ops-card {
        padding:11px;
        border-radius:13px;
        box-shadow:none;
    }
    .delivery-assignment-modal-page .ops-card h2 {
        font-size:14px;
        margin-bottom:2px;
    }
    .delivery-assignment-modal-page .ops-card p.section-copy {
        display:none;
    }
    .delivery-assignment-modal-page .ops-grid {
        gap:8px;
    }
    .delivery-assignment-modal-page .ops-field {
        gap:4px;
    }
    .delivery-assignment-modal-page .ops-field label {
        font-size:10.5px;
    }
    .delivery-assignment-modal-page .ops-field input,
    .delivery-assignment-modal-page .ops-field select,
    .delivery-assignment-modal-page .ops-field textarea {
        min-height:34px;
        padding:7px 9px;
        border-radius:9px;
        font-size:12.5px;
    }
    .delivery-assignment-modal-page .ops-field textarea {
        min-height:58px;
    }
    .delivery-assignment-modal-page .summary-grid {
        grid-template-columns:repeat(3,minmax(0,1fr));
        gap:7px;
    }
    .delivery-assignment-modal-page .summary-box {
        padding:8px 9px;
        border-radius:10px;
    }
    .delivery-assignment-modal-page .summary-box span {
        font-size:10px;
    }
    .delivery-assignment-modal-page .summary-box strong {
        margin-top:3px;
        font-size:14px;
        line-height:1.2;
    }
    .delivery-assignment-modal-page .ops-note {
        padding:8px 10px;
        font-size:11.5px;
        border-radius:10px;
    }
    .delivery-assignment-modal-page .ops-shell > .ops-header:last-child {
        position:sticky;
        bottom:0;
        z-index:3;
        margin-top:10px !important;
        padding:10px 12px;
        border-top:1px solid #e2e8f0;
        background:rgba(255,255,255,.96);
        backdrop-filter:blur(12px);
    }
    .delivery-assignment-modal-page .ops-shell > .ops-header:last-child .ops-note {
        flex:1 1 auto;
        min-width:240px;
    }
    .delivery-assignment-modal-page .ops-button,
    .delivery-assignment-modal-page .ops-button-secondary {
        min-height:34px;
        padding:7px 10px;
        border-radius:9px;
        font-size:12px;
    }
    .delivery-assignment-modal-page.is-embedded {
        min-height:auto;
        padding:10px 0 0;
        background:#f8fafc;
    }
    .delivery-assignment-modal-page.is-embedded .delivery-assignment-modal-panel {
        width:100%;
        max-height:none;
        border:0;
        border-radius:0;
        box-shadow:none;
    }
    .delivery-assignment-modal-page.is-embedded .ops-shell {
        overflow:visible;
    }
    @media (max-width: 767px) {
        .delivery-assignment-modal-page {
            min-height:calc(100dvh - 96px);
            padding:6px 0 12px;
        }
        .delivery-assignment-modal-panel {
            width:100%;
            max-height:calc(100dvh - 104px);
            border-radius:18px 18px 0 0;
        }
        .delivery-assignment-modal-page .ops-shell > .ops-header:last-child {
            display:grid;
            gap:8px;
        }
        .delivery-assignment-modal-page .ops-shell > .ops-header:last-child .ops-note {
            min-width:0;
        }
        .delivery-assignment-modal-page .summary-grid {
            grid-template-columns:1fr;
        }
    }
</style>
@endsection
