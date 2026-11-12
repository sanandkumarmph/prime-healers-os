(() => {
    'use strict';
    const mobile = () => /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
    const messageUrl = value => {
        try {
            const url = new URL(value);
            return url.protocol === 'https:' && url.hostname === 'wa.me' && /^\/\d*$/.test(url.pathname) && url.searchParams.has('text') ? url : null;
        } catch { return null; }
    };
    const composeUrl = url => {
        if (mobile()) return url.href;
        const web = new URL('https://web.whatsapp.com/send');
        if (url.pathname.length > 1) web.searchParams.set('phone', url.pathname.slice(1));
        web.searchParams.set('text', url.searchParams.get('text'));
        return web.href;
    };
    const statusFor = element => {
        const parent = element.closest('form') || element.parentElement;
        let status = parent.querySelector('[data-whatsapp-status]');
        if (!status) {
            status = document.createElement('span'); status.className = 'sw-sharing-status';
            status.dataset.whatsappStatus = ''; status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite'); parent.append(status);
        }
        return status;
    };
    const showPreparedActions = (status, url) => {
        status.textContent = 'Rental terms ready. Choose WhatsApp or copy the message.';
        let actions = status.parentElement.querySelector('[data-whatsapp-prepared-actions]');
        if (!actions) { actions = document.createElement('div'); actions.dataset.whatsappPreparedActions = ''; actions.className = 'sw-actions';
            actions.style.cssText = 'display:flex;flex-wrap:wrap;gap:8px;margin-top:8px'; status.before(actions); }
        actions.replaceChildren();
        const link = document.createElement('a'); link.href = url.href; link.target = '_blank'; link.rel = 'noopener noreferrer';
        link.dataset.phosWhatsapp = ''; link.textContent = 'Open WhatsApp'; link.className = 'sw-btn sw-small';
        const copy = document.createElement('button'); copy.type = 'button'; copy.dataset.copyWhatsapp = url.href;
        copy.className = 'sw-btn sw-small'; copy.textContent = 'Copy WhatsApp message'; actions.append(link, copy);
    };
    document.addEventListener('click', async event => {
        const copyMessage = event.target.closest('[data-copy-whatsapp]');
        if (copyMessage) {
            const link = copyMessage.parentElement.querySelector('a[data-phos-whatsapp]');
            const url = messageUrl(copyMessage.dataset.copyWhatsapp || link?.dataset.whatsappOriginal || link?.href);
            if (!url) return;
            const status = statusFor(copyMessage), text = url.searchParams.get('text');
            try {
                await navigator.clipboard.writeText(text);
                status.textContent = 'Message copied. Paste it into your existing WhatsApp chat, check the recipient, then send.';
            } catch {
                let input = status.parentElement.querySelector('[data-whatsapp-copy-text]');
                if (!input) { input = document.createElement('textarea'); input.dataset.whatsappCopyText = ''; input.readOnly = true; input.rows = 8;
                    input.setAttribute('aria-label','WhatsApp message to copy'); input.style.cssText = 'display:block;width:100%;max-width:100%;margin-top:8px;padding:10px;border:1px solid #cbd6e3;border-radius:8px;box-sizing:border-box;font:inherit;'; status.parentElement.append(input); }
                input.value = text; input.focus(); input.select();
                status.textContent = 'Select and copy the message shown below, then paste into WhatsApp.';
            }
            return;
        }
        const copy = event.target.closest('[data-copy-terms], [data-copy-terms-message]');
        if (copy) {
            const preview = copy.closest('[data-terms-preview]');
            const message = copy.hasAttribute('data-copy-terms-message');
            const input = preview.querySelector(message ? '[data-terms-message]' : '[data-terms-text]');
            const status = preview.querySelector('[data-terms-copy-status]');
            if (!input || !status) return;
            try {
                await navigator.clipboard.writeText(input.value);
                status.textContent = message ? 'Message copied with the private customer link. Check the recipient before sending.' : 'Rental terms copied.';
            } catch {
                input.hidden = false; input.focus(); input.select();
                status.textContent = 'Select and copy the text shown below the preview.';
            }
            return;
        }
        const link = event.target.closest('a[data-phos-whatsapp]');
        if (!link || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        const url = messageUrl(link.dataset.whatsappOriginal || link.href); if (!url) return;
        link.dataset.whatsappOriginal = url.href;
        link.href = composeUrl(url); link.target = mobile() ? '_self' : '_blank'; link.rel = 'noopener noreferrer';
        // Desktop opens directly in WhatsApp Web; mobile hands off in the current tab.
    });
    document.addEventListener('submit', async event => {
        const form = event.target.closest('form[data-phos-whatsapp-prepare]');
        if (!form || event.defaultPrevented || new URL(form.action).origin !== location.origin) return;
        event.preventDefault();
        const button = form.querySelector('button[type=submit], button:not([type])');
        const status = statusFor(form), label = button?.textContent;
        if (button) { button.disabled = true; button.textContent = 'Preparing terms…'; }
        status.textContent = '';
        try {
            const response = await fetch(form.action, {method:'POST', credentials:'same-origin', body:new FormData(form),
                headers:{Accept:'application/json', 'X-Requested-With':'XMLHttpRequest'}});
            if (!response.ok) throw new Error('Preparation failed');
            const data = await response.json(), url = messageUrl(data.whatsapp_url);
            if (!url) throw new Error('Invalid sharing destination');
            showPreparedActions(status, url);
            if (button) button.hidden = true;
        } catch {
            status.textContent = 'The message could not be prepared. No message was sent. Reload this page and try again.';
        } finally {
            if (button) { button.disabled = false; button.textContent = label; }
        }
    });
})();
