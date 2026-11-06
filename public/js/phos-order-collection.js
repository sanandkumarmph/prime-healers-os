document.querySelectorAll('[data-order-collection]').forEach(block => {
    const form = block.closest('form');
    const field = name => form.elements.namedItem(name);
    const enabled = field('collection_enabled');
    const amount = field('collection_amount');
    const sale = block.dataset.orderCollection === 'sale';
    const operator = field('delivery_operator_id');
    const total = form.querySelector(sale ? '#saleOrderTotal' : '[data-summary-net-total]');
    const fields = block.querySelector('fieldset');
    const error = block.querySelector('[data-order-collection-error]');
    const cents = value => Math.round((Number(String(value || '').replace(/Rs\.?|₹|,|\s/gi, '')) || 0) * 100);
    let manual = block.dataset.manualAmount === '1';

    function sync() {
        const internal = sale
            ? field('delivery_responsibility')?.value === 'ph_internal_delivery'
            : field('delivery_assignment_type')?.value === 'ph_internal';
        const assigned = sale ? Boolean(operator?.value) : field('delivery_staff_id')?.selectedOptions[0]?.dataset.collectionEligible === '1';
        const unpaid = sale && ['paid', 'void'].includes(field('payment_status')?.value) ? 0 : Math.max(0, cents(total?.textContent));
        if (!manual) amount.value = (unpaid / 100).toFixed(2);
        amount.max = (unpaid / 100).toFixed(2);
        fields.hidden = fields.disabled = !enabled.checked;
        amount.required = enabled.checked;
        block.querySelector('[data-collection-suggestion]').textContent = 'Unpaid amount ₹' + (unpaid / 100).toFixed(2);

        const message = !enabled.checked ? '' : (!internal || !assigned)
            ? 'Choose internal delivery and assign a Delivery operator.'
            : (field('collection_assignee_id')?.value || field('collection_mode')?.value)
                ? 'Choose the Delivery operator or the Sales assignee for collection.'
                : unpaid <= 0 ? 'There is no unpaid amount to collect.'
                    : cents(amount.value) <= 0 || cents(amount.value) > unpaid ? 'Enter an amount within the unpaid balance.' : '';
        enabled.setCustomValidity(message);
        error.textContent = message;
        error.hidden = !message;
        return !message;
    }

    amount.addEventListener('input', () => { manual = true; });
    amount.addEventListener('change', () => { manual = true; });
    form.addEventListener('input', sync);
    form.addEventListener('change', sync);
    form.addEventListener('click', event => {
        const next = event.target.closest('[data-rental-wizard-next="review"], #rentalMobileContinueButton');
        if (next && form.querySelector('[data-rental-wizard-step="delivery"].is-active') && !sync()) {
            event.preventDefault();
            event.stopImmediatePropagation();
            enabled.reportValidity();
        }
    }, true);
    if (total) new MutationObserver(sync).observe(total, { childList: true, characterData: true, subtree: true });
    sync();
});

document.querySelectorAll('[data-order-collection-outcome]').forEach(form => {
    const { outcome, method, amount, cash_amount: cash, note, reference, payment_screenshot: screenshot } = form.elements;
    const fields = form.querySelector('[data-collection-payment-fields]');
    const split = form.querySelector('[data-collection-split]');
    const online = form.querySelector('[data-collection-online]');
    const reason = form.querySelector('[data-collection-reason]');

    function sync() {
        const paid = outcome.value !== 'none';
        const full = outcome.value === 'collected';
        fields.hidden = fields.disabled = !paid;
        split.hidden = split.disabled = !paid || method.value !== 'split';
        online.hidden = online.disabled = !paid || method.value === 'cash';
        amount.required = paid;
        amount.readOnly = full;
        if (full) amount.value = amount.max;
        cash.required = !split.disabled;
        cash.max = Math.max(0, Math.round(Number(amount.value) * 100) - 1) / 100;
        reference.required = !online.disabled && !screenshot.files.length;
        note.required = !full;
        reason.hidden = full;
    }

    form.addEventListener('input', sync);
    form.addEventListener('change', sync);
    sync();
});
