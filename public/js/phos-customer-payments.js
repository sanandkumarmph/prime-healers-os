document.querySelectorAll('[data-copy-field]').forEach(button => {
    button.addEventListener('click', async () => {
        const input = document.getElementById(button.dataset.copyField);
        const status = button.closest('section')?.querySelector('[data-copy-status]');
        if (!input || !status) return;
        try {
            await navigator.clipboard.writeText(input.value);
            status.textContent = 'Copied to clipboard.';
        } catch {
            input.focus(); input.select();
            status.textContent = 'Clipboard access is unavailable. Select and copy the value from the field.';
        }
    });
});

const watch = document.querySelector('[data-payment-watch]');
if (watch) {
    const message = watch.querySelector('[data-payment-watch-message]');
    const reload = watch.querySelector('[data-payment-watch-reload]');
    let dirty = false;
    let lastSubmission;
    let busy = false;
    let stopped = false;
    let failures = 0;
    let timer;
    const changed = event => {
        if (event.target.closest('form') && !event.target.readOnly) dirty = true;
    };
    document.addEventListener('input', changed);
    document.addEventListener('change', changed);
    // Retain the event so cancellation by any submit handler is observed when
    // a status response arrives. A submission never disables status polling.
    document.addEventListener('submit', event => { lastSubmission = event; });
    const hasPendingSubmission = () => {
        const event = lastSubmission;
        const form = event?.target;
        if (!event || event.defaultPrevented || form?.tagName !== 'FORM') return false;
        const target = event.submitter?.getAttribute('formtarget')
            ?? form.getAttribute('target')
            ?? document.querySelector('base[target]')?.getAttribute('target')
            ?? '_self';
        const method = event.submitter?.getAttribute('formmethod')
            ?? form.getAttribute('method') ?? 'get';
        // Keep a same-window form protected even if navigation never happens
        // (for example a download), while continuing to check payment status.
        return method.toLowerCase() !== 'dialog'
            && (!target || ['_self', '_parent', '_top'].includes(target.toLowerCase()) || target === window.name);
    };
    reload.addEventListener('click', () => window.location.reload());
    const check = async () => {
        if (busy || stopped || document.hidden) return;
        busy = true;
        const abort = new AbortController();
        const timeout = window.setTimeout(() => abort.abort(), 8000);
        try {
            const response = await fetch(watch.dataset.statusUrl, {
                headers: { Accept: 'application/json' }, credentials: 'same-origin',
                cache: 'no-store', signal: abort.signal,
            });
            if ([401, 403, 404, 419].includes(response.status) || response.redirected) {
                stopped = true;
                message.textContent = 'Automatic updates paused. Refresh the page to check your session.';
                reload.hidden = false;
                return;
            }
            if (!response.ok) throw new Error('Status check unavailable');
            const data = await response.json();
            if (typeof data.version !== 'string') throw new Error('Invalid status response');
            if (stopped || document.hidden) return;
            failures = 0;
            if (data.version !== watch.dataset.statusVersion) {
                const dialogOpen = [...document.querySelectorAll('dialog[open], .modal.show, [aria-modal="true"], .renewal-modal.is-open[aria-hidden="false"], details[data-invoice-action][open]')]
                    .some(element => element.getClientRects().length > 0);
                if (!dirty && !dialogOpen && !hasPendingSubmission()) {
                    stopped = true;
                    window.location.reload();
                } else {
                    message.textContent = 'Payment status changed. Finish your current action, then refresh this page.';
                    reload.hidden = false;
                }
            } else {
                message.textContent = 'Payment updates automatically.';
            }
        } catch {
            failures++;
            message.textContent = 'Automatic update temporarily unavailable. Retrying…';
        } finally {
            window.clearTimeout(timeout);
            busy = false;
        }
    };
    const tick = async () => {
        await check();
        if (!stopped) timer = window.setTimeout(tick, Math.min(60000, 10000 * (2 ** Math.min(failures, 3))));
    };
    document.addEventListener('visibilitychange', () => { if (!document.hidden) check(); });
    window.addEventListener('pagehide', () => { stopped = true; window.clearTimeout(timer); });
    window.addEventListener('pageshow', event => {
        if (event.persisted) { stopped = false; tick(); }
    });
    tick();
}
