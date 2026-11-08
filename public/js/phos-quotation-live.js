(() => {
    'use strict';
    document.querySelectorAll('[data-quote-live]').forEach(root => {
        const quoteId = Number(root.dataset.quoteLive), endpoint = root.dataset.liveStatusUrl;
        if (!quoteId || !endpoint) return;
        let version = null, timer, active = null, stopped = false, failures = 0, deferredState = null;
        const dirty = new WeakSet();
        root.addEventListener('input', event => { const form = event.target.closest('form'); if (form && event.target.type !== 'hidden') dirty.add(form); });
        root.addEventListener('change', event => { const form = event.target.closest('form'); if (form && event.target.type !== 'hidden') dirty.add(form); });
        const safeUrl = value => { try { const url = new URL(value, location.href); return url.origin === location.origin && /^https?:$/.test(url.protocol) ? url.href : null; } catch { return null; } };
        const link = (label, href, primary = false) => { const element = document.createElement('a'); element.className = 'sw-btn sw-small' + (primary ? ' sw-primary' : ''); element.textContent = label; element.href = href; return element; };
        const setText = (selector, value) => root.querySelectorAll(selector).forEach(element => { element.textContent = value || ''; });
        const modalOpen = () => [...document.querySelectorAll('dialog[open],.quick-customer-modal,[aria-modal="true"]')]
            .some(element => !element.closest('[hidden],[aria-hidden="true"],details:not([open])') && element.getClientRects().length > 0 && getComputedStyle(element).visibility !== 'hidden');
        const update = state => {
            if (state.schema !== 1 || state.quotation_id !== quoteId || typeof state.version !== 'string'
                || !['draft','sent','accepted','declined','withdrawn','superseded','converted','expired'].includes(state.quotation_status)
                || !['pending','signed','exception','not_required'].includes(state.agreement_state)) throw new Error('Invalid status');
            if (state.version === version) return;
            root.dataset.liveQuotationStatus=state.quotation_status;root.dataset.liveAgreementState=state.agreement_state;
            const signed = state.agreement_state === 'signed', ready = state.agreement_ready === true;
            const pending = state.agreement_state === 'pending' && state.current && ['sent','accepted'].includes(state.quotation_status) && !state.terminal;
            const message = !state.current ? 'A newer quotation revision is available.' : signed ? 'Rental terms signed'
                : state.agreement_state === 'exception' ? 'Signature exception approved' : pending ? 'Awaiting rental agreement.' : 'Quotation: ' + state.quotation_status_label;
            setText('[data-live-status-text]', message);setText('[data-live-status-time]', signed && state.agreement_signed_at ? ' · ' + state.agreement_signed_at : '');
            root.querySelectorAll('[data-live-status-banner]').forEach(banner => { banner.classList.toggle('sw-live-waiting', pending); banner.classList.toggle('sw-success', signed || state.agreement_state === 'exception'); });
            const recommendation = state.next_action && typeof state.next_action.key === 'string' ? state.next_action : null;
            setText('[data-live-quote-next]', recommendation?.reason || state.next_text);
            root.querySelectorAll('[data-live-enquiry-stage]').forEach(badge => { badge.textContent = state.enquiry_stage_label; badge.dataset.status = state.enquiry_stage; });
            setText('[data-live-quote-label]', state.quotation_status_label);
            setText('[data-live-quotation-summary]', 'Quotation: ' + state.quotation_status_label);
            setText('[data-live-terms-label]', signed ? 'Signed' : state.agreement_state==='exception' ? 'Exception approved' : 'Signature pending');
            setText('[data-live-share-label]', state.quotation_shared ? 'Shared' : 'Ready to share');
            const action = state.order_action && safeUrl(state.order_action.url) ? state.order_action : null;
            const latest = safeUrl(state.latest_url);
            root.querySelectorAll('[data-live-order-link]').forEach(element => { if (action) element.href = action.url; else if (!state.current && latest) element.href = latest; });
            deferredState = modalOpen() ? state : null;
            if (!modalOpen()) {
                root.querySelectorAll('[data-live-quote-header]').forEach(slot => {
                    if (state.current && state.quotation_status === 'draft') return;
                    const next = !state.current && latest ? link('Open latest quotation',latest,true)
                        : recommendation?.url && safeUrl(recommendation.url) && recommendation.label ? link(recommendation.label,recommendation.url,true)
                        : !recommendation && action ? link(action.label,action.url,true) : null;
                    if(next){next.dataset.salesCurrentAction='';next.dataset.nextSection=recommendation?.section || '';}
                    const key=next ? next.textContent+'|'+next.href : '';
                    if(slot.dataset.liveActionKey!==key){slot.replaceChildren(...(next?[next]:[]));slot.dataset.liveActionKey=key;}
                });
                const panel=root.querySelector('[data-sales-next-action]');
                if(panel&&recommendation){panel.dataset.actionKey=recommendation.key;panel.querySelector('h2').textContent=recommendation.attention?'Next action':'Current status';}
                root.querySelectorAll('[data-live-order-actions]').forEach(slot=>slot.replaceChildren(...(action?[link(action.label,action.url)]:[])));
                root.querySelectorAll('[data-sales-section]').forEach(section=>{
                    const busy=[...section.querySelectorAll('form')].some(form=>dirty.has(form)) || section.contains(document.activeElement);
                    if(busy)return;
                    const target=section.dataset.salesSection;
                    if(recommendation?.section===target)section.open=true;
                    else if((target==='rental-agreement'&&ready)||(target==='enquiry-response'&&['accepted','converted'].includes(state.quotation_status))||(target==='quotation-share'&&state.quotation_shared))section.open=false;
                });
                if (signed && safeUrl(state.signed_copy_url)) {
                    root.querySelectorAll('[data-live-agreement-actions]').forEach(slot => {
                        // Actual signature proof is never fetched into this status response.
                        if (slot.dataset.liveSigned === '1') return;
                        const pdf = link('Download signed PDF', state.signed_copy_url);
                        const prepare = safeUrl(state.prepare_signed_message_url), token = document.querySelector('meta[name="csrf-token"]')?.content || root.querySelector('input[name="_token"]')?.value;
                        const nodes = [pdf];
                        if (prepare && token) {
                            const form = document.createElement('form');form.method='post';form.action=prepare;form.dataset.phosWhatsappPrepare='';
                            for (const [name,value] of [['_token',token],['destination','whatsapp']]) { const field=document.createElement('input');field.type='hidden';field.name=name;field.value=value;form.append(field); }
                            const button=document.createElement('button');button.type='submit';button.className='sw-btn sw-small';button.textContent='Prepare signed-copy message';form.append(button);nodes.push(form);
                        }
                        slot.replaceChildren(...nodes);slot.dataset.liveSigned='1';
                    });
                }
            }
            root.querySelectorAll('[data-live-agreement-summary]').forEach(element => { element.textContent = signed ? 'Signed. The customer’s signed copy is ready.' : state.agreement_state === 'exception' ? 'Signature exception approved. The approval is recorded separately from a customer signature.' : 'Send the terms separately. The customer signs on their phone.'; });
            root.querySelectorAll('[data-live-response]').forEach(region => {
                const form = region.querySelector('form'), hasDraft = form && dirty.has(form);
                if (!state.can_record_response && hasDraft) {
                    region.hidden = false;form.querySelectorAll('button[type="submit"],button:not([type])').forEach(button=>button.disabled=true);
                    let note=region.querySelector('[data-live-form-note]');if(!note){note=document.createElement('p');note.className='sw-live-form-note';note.dataset.liveFormNote='';region.prepend(note);}note.textContent='The quotation status changed. Your unsaved text is preserved; another response is no longer needed.';
                } else region.hidden = !state.can_record_response;
            });
            root.querySelectorAll('form').forEach(form => {
                if (form.matches('[data-signature-exception-form]') && ready) {
                    form.querySelectorAll('button[type="submit"]').forEach(button=>button.disabled=true);
                    if (!dirty.has(form)) form.closest('details').hidden=true;
                }
                if (dirty.has(form)) return;
                const field=form.querySelector('input[name="lock_version"]');
                if (field && ((form.getAttribute('action') || '').includes('/quotations/' + quoteId + '/actions') || form.matches('[data-signature-exception-form]')) && state.current) field.value=String(state.quotation_version);
            });
            if (!modalOpen()) version = state.version;
            if (state.terminal) stopped = true;
        };
        // Closing a modal must apply even a terminal update which stopped the network poll.
        const modalObserver = new MutationObserver(() => {
            if (deferredState && !modalOpen()) { const state=deferredState;deferredState=null;update(state); }
        });
        modalObserver.observe(document.body,{subtree:true,attributes:true,attributeFilter:['hidden','open','aria-hidden','style','class']});
        const schedule = delay => { clearTimeout(timer); if (!stopped) timer=setTimeout(poll,delay); };
        const poll = async () => {
            if (stopped || document.hidden || active) return;
            const controller=new AbortController();active=controller;
            const timeout=setTimeout(()=>controller.abort(),10000);
            try {
                const response=await fetch(endpoint,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'},signal:controller.signal});
                if ([401,403,404,410].includes(response.status)) { stopped=true;setText('[data-live-connection-status]','Live updates paused. Reopen this page to check access and the latest status.');return; }
                if (!response.ok) throw new Error('Status unavailable');
                update(await response.json());failures=0;setText('[data-live-connection-status]','');
            } catch (error) {
                if (!document.hidden && !stopped) { failures++;if(failures>1)setText('[data-live-connection-status]','Checking for updates…'); }
            } finally { clearTimeout(timeout);active=null;if(!document.hidden)schedule(controller.signal.aborted && !failures ? 0 : Math.min(60000,15000*Math.pow(2,Math.min(failures,2)))); }
        };
        document.addEventListener('visibilitychange',()=>{if(document.hidden){clearTimeout(timer);active?.abort();}else poll();});
        window.addEventListener('focus',()=>{if(!document.hidden)poll();});
        window.addEventListener('pagehide',()=>{stopped=true;clearTimeout(timer);active?.abort();modalObserver.disconnect();},{once:true});
        schedule(1500);
    });
})();
