<section class="sw-sales-next" data-sales-next-action data-action-key="{{ $salesNextAction['key'] }}" id="enquiry-next" tabindex="-1">
    <div><h2>{{ $salesNextAction['attention'] ? 'Next action' : 'Current status' }}</h2><p data-sales-next-reason data-live-quote-next>{{ $salesNextAction['reason'] }}</p></div>
    <span data-live-quote-header>@if($salesNextAction['url'] && $salesNextAction['label'])<a class="sw-btn sw-primary" data-sales-current-action data-next-section="{{ $salesNextAction['section'] ?? '' }}" href="{{ $salesNextAction['url'] }}">{{ $salesNextAction['label'] }}</a>@endif</span>
</section>
@once
<style>
.sw-sales-next{display:flex;justify-content:space-between;align-items:center;gap:14px;padding:14px 16px;margin:14px 0;border:1px solid #cad9ed;border-radius:12px;background:#f4f8ff;scroll-margin-top:100px}.sw-sales-next h2{font-size:14px;margin:0 0 4px;color:#172b4d}.sw-sales-next p{font-size:12px;line-height:1.5;margin:0;color:#4c6380}.sw-sales-next>span{flex-shrink:0}.sw-sales-next .sw-btn{font-size:13px;min-height:42px}.sw-guided-section{scroll-margin-top:100px}.sw-guided-section>summary{cursor:pointer;font-weight:700;font-size:14px;line-height:1.5;list-style-position:inside}.sw-guided-section>summary .sw-meta{font-size:11px;font-weight:400;margin-left:6px}.sw-guided-section[open]>summary{margin-bottom:12px}.sw-guided-section .sw-primary{background:#fff;color:#2558c8;border-color:#cbd6e3}.sw-guided-section .sw-primary:hover{background:#eef4ff}.sw :is([data-live-order-actions],[data-live-agreement-actions]) .sw-primary{background:#fff;color:#2558c8;border-color:#cbd6e3}
.sw.sw-detail #enquiry-next.sw-sales-next{padding:14px 16px;margin:12px 0 0}.sw.sw-detail .sw-sales-next h2{font-size:14px;margin:0 0 4px}.sw.sw-detail .sw-sales-next p{font-size:12px;margin:0}.sw.sw-detail .sw-sales-next .sw-btn{font-size:13px;min-height:42px}
@media(max-width:600px){.sw.sw-detail #enquiry-next.sw-sales-next{padding:12px}.sw-sales-next{align-items:stretch;flex-direction:column;padding:12px;gap:10px}.sw-sales-next .sw-btn{width:100%;justify-content:center}.sw-sales-next>span{display:block}.sw-guided-section>summary .sw-meta{display:block;margin-left:0}}
</style>
@push('scripts')
<script>
const revealSalesHash=()=>{
    let id;try{id=decodeURIComponent(location.hash.slice(1));}catch{return;}
    const target=document.getElementById(id),root=target?.closest('.sw');if(!root)return;
    for(let current=target;current&&root.contains(current);current=current.parentElement)if(current.tagName==='DETAILS')current.open=true;
    if(id==='enquiry-response'){const details=target.querySelector('details');if(details)details.open=true;}
};
addEventListener('hashchange',revealSalesHash);revealSalesHash();
document.addEventListener('click', event => {
    const action=event.target.closest('[data-sales-current-action]');if(!action)return;
    const root=action.closest('.sw'),section=action.dataset.nextSection;
    if(!root||!section)return;
    if(root.dataset.livePage==='quotation'&&section==='enquiry-followups')return;
    const target=document.getElementById(section);if(!target||!root.contains(target))return;
    event.preventDefault();
    for(let current=target;current&&root.contains(current);current=current.parentElement)if(current.tagName==='DETAILS')current.open=true;
    if(['enquiry-response','enquiry-followups'].includes(section)){const details=target.querySelector('details');if(details)details.open=true;}
    target.scrollIntoView({block:'center',behavior:matchMedia('(prefers-reduced-motion:reduce)').matches?'auto':'smooth'});
    const key=root.querySelector('[data-sales-next-action]')?.dataset.actionKey;
    const execute=target.querySelector('[data-guided-execute="'+key+'"]');
    if(execute){execute.click();return;}
    const field=[...target.querySelectorAll('input:not([type=hidden]),textarea,select,button,a[href]')].find(element=>element.getClientRects().length&&!element.disabled);if(field)field.focus({preventScroll:true});
});
</script>
@endpush
@endonce
