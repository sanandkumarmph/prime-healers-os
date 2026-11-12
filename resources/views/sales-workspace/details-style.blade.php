<style>
.sw.sw-detail{width:100%;max-width:none;min-width:0;padding:4px 0 36px;display:grid;gap:12px;font-family:var(--ph-font-body,Arial),Arial,sans-serif}
.sw-detail .swd-back{font-size:12px;justify-self:start;line-height:1.5}
.sw-detail .swd-header{margin:0;padding:14px 16px;background:#fff;border:1px solid var(--sw-line);border-radius:12px;gap:14px}
.swd-heading{min-width:0;flex:1}.swd-identity{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.sw-detail .swd-header h1{font-size:23px;line-height:1.3;margin:6px 0}
.sw-detail .swd-header p{margin:0}.sw-detail .swd-header .sw-actions{justify-content:flex-end;align-items:stretch}
.sw-detail .sw-btn{min-height:36px;padding:7px 11px;font-size:12px}.sw-detail .sw-small{min-height:34px;padding:5px 10px}
.sw-detail .sw-alert{margin:0;padding:10px 14px;font-size:13px}.sw-detail .sw-alert p:last-child{margin-bottom:0}
.swd-progress{padding:10px 12px;background:#fff;border:1px solid var(--sw-line);border-radius:12px;min-width:0}
.sw-detail .sw-stage{margin:0;gap:6px}.sw-detail .sw-stage a{font-size:12px;min-height:38px}
.sw-detail #enquiry-next{margin:8px 2px 0;padding:0;font-size:12px;line-height:1.5}
.swd-grid{display:grid;grid-template-columns:minmax(0,1.8fr) minmax(270px,1fr);grid-template-areas:'actions contact';gap:12px;align-items:start;min-width:0}
.swd-contact-column{grid-area:contact}.swd-main-column{grid-area:actions}
.swd-contact-column,.swd-main-column{min-width:0;display:grid;gap:12px}
.sw-detail .sw-panel{padding:14px 16px;margin:0;min-width:0;border-radius:12px}
.sw-detail h2{font-size:15px;margin-bottom:10px;line-height:1.4}.sw-detail h3{font-size:12px;margin-bottom:5px}
.sw-detail p{font-size:13px;margin-bottom:9px}.sw-detail .sw-meta{font-size:11px;line-height:1.55}
.sw-detail .sw-badge{font-size:10px;white-space:normal;line-height:1.4}
.swd-section-heading{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:10px}
.sw-detail .swd-section-heading h2,.sw-detail .swd-section-heading .sw-meta{margin:0}.swd-section-heading>a{font-size:11px;font-weight:650}
.swd-count{display:inline-flex;align-items:center;justify-content:center;min-width:20px;padding:1px 6px;margin-left:5px;background:#eef3fa;border-radius:6px;font-size:10px;color:#5d6e83;vertical-align:middle}
.swd-quote-summary{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:10px 12px;background:#f7f9fc;border:1px solid #e8edf4;border-radius:8px}
.swd-quote-summary>div{min-width:0}.swd-quote-summary>div:last-child{text-align:right}
.swd-quote-summary strong{font-size:14px}.swd-quote-summary .sw-meta{display:block;margin-top:3px}
.sw-detail details{padding-top:10px;margin-top:10px}.sw-detail summary{font-size:12px;margin-bottom:10px}
.sw-detail .sw-panel>details:only-child{padding:0;margin:0;border:0}
.sw-detail :is(input:not([type=hidden]),select,textarea){min-height:38px;padding:8px 10px;font-size:13px}
.sw-detail label{font-size:11px}.sw-detail .sw-fields{gap:10px}
.sw-detail :is(#enquiry-response,.swd-stage-panel) form{display:grid;gap:10px}
.sw-detail :is(#enquiry-response,.swd-stage-panel) form .sw-field,.sw-detail :is(#enquiry-response,.swd-stage-panel) form .sw-btn{margin:0!important}
.sw-detail :is(#enquiry-response,.swd-stage-panel) form>.sw-btn{justify-self:start}
.swd-customer{display:block;font-size:14px;overflow-wrap:anywhere}.swd-contact-links{display:grid;gap:4px;margin-top:5px;font-size:13px;overflow-wrap:anywhere}
.swd-facts{display:grid;gap:8px;margin:12px 0 0;padding:12px 0 0;border-top:1px solid var(--sw-line)}
.swd-facts>div{display:grid;grid-template-columns:74px minmax(0,1fr);gap:8px;font-size:11px;align-items:baseline}
.swd-facts dt{color:var(--sw-muted)}.swd-facts dd{margin:0;overflow-wrap:anywhere}.swd-facts time{font-size:11px}
.swd-requirement{margin-top:12px;padding-top:12px;border-top:1px solid var(--sw-line)}.swd-requirement .sw-note{font-size:12px;margin:0}
.swd-follow-up{padding:10px 0;border-bottom:1px solid var(--sw-line)}.swd-follow-up>div>strong{font-size:13px;overflow-wrap:anywhere}
.swd-follow-up details{margin-top:7px;padding-top:0;border:0}.swd-follow-up form{display:grid;gap:9px}.swd-follow-up form>.sw-btn{justify-self:start}
.sw-detail .sw-follow-up{margin-top:10px}.sw-detail .sw-follow-up>form{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.sw-detail .sw-follow-up>form .sw-meta{margin:0}.sw-detail .sw-follow-up .sw-fields{grid-template-columns:repeat(2,minmax(0,1fr))}
.swd-note-form{display:flex;gap:10px;align-items:flex-end}.swd-note-form>.sw-field{flex:1;min-width:0}
.sw-detail .sw-timeline li{padding:0 0 12px 15px}.sw-detail .sw-timeline p{font-size:12px}
.sw-detail .sw-row{padding:10px 0}.sw-detail .sw-row strong{font-size:13px}
.sw-detail :is(#enquiry-next,#enquiry-response,#enquiry-details,#enquiry-followups,#enquiry-quotations,#enquiry-activity,#rental-agreement){scroll-margin-top:18px}
@media(max-width:950px){
 .swd-grid{display:flex;flex-direction:column;align-items:stretch}
 .swd-contact-column,.swd-main-column{display:contents}
 #enquiry-details{order:0}#enquiry-response{order:1}#rental-agreement{order:2}#enquiry-followups{order:3}#enquiry-activity{order:4}#enquiry-quotations{order:5}.swd-stage-panel{order:6}
}
@media(max-width:600px){
 .sw.sw-detail{padding:4px 0 100px;gap:9px}.sw-detail .swd-header{padding:12px;gap:10px;align-items:stretch}
 .sw-detail .swd-header h1{font-size:20px}.sw-detail .swd-header .sw-actions{justify-content:flex-start;width:100%;gap:7px}
 .sw-detail .swd-header .sw-actions .sw-btn{flex:1}.swd-progress{padding:8px}
 .sw-detail .sw-stage{gap:4px;grid-template-columns:repeat(4,minmax(0,1fr))}.sw-detail .sw-stage a{font-size:10px;padding:5px 3px;min-height:36px}
 .sw-detail .sw-panel{padding:12px}.sw-detail .swd-contact-column{grid-template-columns:minmax(0,1fr);gap:9px}.swd-grid,.swd-main-column{gap:9px}
 .swd-facts{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px 10px}.swd-facts>div{display:block}.swd-facts dt{font-size:10px;margin-bottom:2px}
 .sw-detail :is(input:not([type=hidden]),select,textarea){font-size:16px;min-height:42px}
 .swd-note-form{display:grid;gap:8px}.swd-note-form>.sw-btn{justify-self:start}.sw-detail .sw-follow-up .sw-fields{grid-template-columns:1fr}
 .swd-quote-summary{padding:9px;gap:8px}.swd-quote-summary>div:last-child{max-width:58%}
}
</style>
