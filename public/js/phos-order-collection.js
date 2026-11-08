document.querySelectorAll('[data-order-collection]').forEach(block => {
    const form = block.closest('form');
    const field = name => form.elements.namedItem(name);
    const owner = block.querySelector('[data-collection-owner]');
    if (!owner) return;
    const enabled = field('collection_enabled'), amount = field('collection_amount');
    const sale = block.dataset.orderCollection === 'sale';
    const total = form.querySelector(sale ? '#saleOrderTotal' : '[data-summary-net-total]');
    const deliveryFields = block.querySelector('[data-order-collection-fields]');
    const salesFields = block.querySelector('[data-sales-collection-fields]');
    const error = block.querySelector('[data-order-collection-error]');
    const deliveryOption = owner.querySelector('[data-delivery-collection-option]');
    const cents = value => Math.round((Number(String(value || '').replace(/Rs\.?|₹|,|\s/gi, '')) || 0) * 100);
    let manual = block.dataset.manualAmount === '1', previousOwner = owner.value;

    function sync() {
        const internal = sale ? field('delivery_responsibility')?.value === 'ph_internal_delivery'
            : field('delivery_assignment_type')?.value === 'ph_internal';
        if (!internal && deliveryOption?.parentElement) {
            if (owner.value === 'delivery') owner.value = 'none';
            deliveryOption.remove();
        } else if (internal && deliveryOption && !deliveryOption.parentElement) {
            owner.insertBefore(deliveryOption, owner.querySelector('option[value="sales"]'));
        }
        const delivery = internal && owner.value === 'delivery', sales = owner.value === 'sales';
        if (enabled) enabled.checked = delivery;
        if (deliveryFields) deliveryFields.hidden = deliveryFields.disabled = !delivery;
        if (salesFields) salesFields.hidden = salesFields.disabled = !sales;
        const mode = field('collection_mode'), assignee = field('collection_assignee_id');
        if (!sales) { if (mode) mode.value = ''; if (assignee) assignee.value = ''; }
        if (mode) mode.required = sales;
        if (assignee) assignee.required = sales;
        if (!delivery && (previousOwner === 'delivery' || !internal)) {
            if (amount) amount.value = '';
            const instructions = field('collection_instructions'); if (instructions) instructions.value = '';
            const operator = field('delivery_operator_id'); if (operator) operator.value = '';
            manual = false;
        }
        const unpaid = sale && ['paid', 'void'].includes(field('payment_status')?.value) ? 0 : Math.max(0, cents(total?.textContent));
        if (amount) {
            if (delivery && !manual) amount.value = (unpaid / 100).toFixed(2);
            amount.max = (unpaid / 100).toFixed(2); amount.required = delivery;
        }
        const suggestion = block.querySelector('[data-collection-suggestion]');
        if (suggestion) suggestion.textContent = 'Unpaid amount ₹' + (unpaid / 100).toFixed(2);
        const operator = field('delivery_operator_id'); if (operator) operator.required = delivery;
        const assigned = sale ? Boolean(operator?.value) : field('delivery_staff_id')?.selectedOptions[0]?.dataset.collectionEligible === '1';
        const message = delivery ? !assigned ? 'Assign an internal Delivery operator above.'
            : unpaid <= 0 ? 'There is no unpaid amount to collect.'
            : cents(amount?.value) <= 0 || cents(amount?.value) > unpaid ? 'Enter an amount within the unpaid balance.' : ''
            : sales && (!mode?.value || !assignee?.value) ? 'Select the Sales team member and collection type.' : '';
        owner.setCustomValidity(message); error.textContent = message; error.hidden = !message;
        previousOwner = owner.value;
        return !message;
    }

    amount?.addEventListener('input', () => { manual = true; });
    amount?.addEventListener('change', () => { manual = true; });
    const update = event => {
        // Preserve old draft fields while presenting only one collection choice.
        if (['collection_mode', 'collection_assignee_id'].includes(event.target.name)
            && (field('collection_mode')?.value || field('collection_assignee_id')?.value)) owner.value = 'sales';
        sync();
    };
    form.addEventListener('input', update); form.addEventListener('change', update);
    form.addEventListener('click', event => {
        const next = event.target.closest('[data-rental-wizard-next="review"], #rentalMobileContinueButton');
        if (next && form.querySelector('[data-rental-wizard-step="delivery"].is-active') && !sync()) {
            event.preventDefault(); event.stopImmediatePropagation(); owner.reportValidity();
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
