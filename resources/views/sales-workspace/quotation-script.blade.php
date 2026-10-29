<script>
(() => {
    const form = document.getElementById('quotation-form'); if (!form) return;
    const products = {{ Illuminate\Support\Js::from($productData) }};
    const list = document.getElementById('quotation-lines'), kind = document.getElementById('kind');
    const priceReview = document.getElementById('price-review'), review = document.getElementById('quotation-review');
    let nextIndex = {{ count($lines) }};
    const value = (row, name) => row.querySelector('[data-line-field="'+name+'"]');
    const number = n => Number.isFinite(Number(n)) ? Number(n) : 0;
    const round = n => Math.round((n + Number.EPSILON) * 100) / 100;
    const money = n => n.toLocaleString(undefined, {minimumFractionDigits:2,maximumFractionDigits:2});
    const eligible = p => !!p && !!p[kind.value === 'rental' ? 'can_rent' : 'can_sell'];
    function recalc() {
        let subtotal = 0, tax = 0, total = 0;
        for (const row of list.querySelectorAll('[data-quote-line]')) {
            const base = round(number(value(row,'quantity').value) * number(value(row,'rate').value));
            const rate = number(value(row,'tax_rate').value), inclusive = value(row,'tax_mode').value === 'inclusive';
            const taxable = inclusive ? round(base / (1 + rate / 100)) : base;
            const amount = inclusive ? round(base - taxable) : round(base * rate / 100);
            const lineTotal = inclusive ? base : round(base + amount);
            subtotal += taxable; tax += amount; total += lineTotal;
            row.querySelector('[data-line-total]').textContent = money(lineTotal);
        }
        total = round(total + number(form.elements.transport.value));
        document.getElementById('preview-subtotal').textContent = money(subtotal);
        document.getElementById('preview-tax').textContent = money(tax);
        document.getElementById('preview-total').textContent = money(total);
        document.getElementById('preview-payable').textContent = money(total + (kind.value === 'rental' ? number(form.elements.deposit.value) : 0));
    }
    function filterProducts() {
        for (const row of list.querySelectorAll('[data-quote-line]')) {
            const select = value(row,'product_id');
            for (const option of select.options) {
                const unavailable = !!option.value && !eligible(products[option.value]);
                option.hidden = unavailable && option.value !== select.value;
                option.disabled = unavailable && option.value !== select.value;
            }
            const invalid = !!select.value && !eligible(products[select.value]);
            select.setCustomValidity(invalid ? 'Choose a product available for '+kind.value+'.' : '');
            const note = row.querySelector('[data-product-warning]');
            note.hidden = !invalid;
            note.textContent = invalid ? 'This product is not available for '+kind.value+'. Choose another product.' : '';
        }
    }
    function unresolved() { return form.elements.pricing_kind.value !== kind.value || (!!form.dataset.originalKind && form.dataset.originalKind !== kind.value && form.elements.kind_confirmed.value !== '1'); }
    function syncType() {
        document.querySelectorAll('[data-rental-dates]').forEach(el => el.hidden = kind.value !== 'rental');
        ['start_date','end_date'].forEach(name => {form.elements[name].required = kind.value === 'rental'; form.elements[name].disabled = kind.value !== 'rental';});
        document.getElementById('rental-rate-hint').hidden = kind.value !== 'rental';
        priceReview.hidden = !unresolved();
        kind.setCustomValidity(unresolved() ? 'Confirm the prices for the new order type.' : '');
        document.getElementById('use-kind-prices').textContent = 'Use '+kind.value+' product prices';
        filterProducts(); recalc();
    }
    function dirty() {
        document.getElementById('draft-status').textContent = 'Unsaved changes';
        const download = document.getElementById('saved-draft-pdf'); if (download) download.hidden = true;
        if (review?.open) review.close();
        const finalise = document.getElementById('finalise-quote'); if (finalise) finalise.disabled = true;
    }
    form.addEventListener('input',dirty); form.addEventListener('change',dirty);
    kind.addEventListener('change', () => { form.elements.kind_confirmed.value = '0'; syncType(); });
    function confirmPrices(useDefaults) {
        if (useDefaults) for (const row of list.querySelectorAll('[data-quote-line]')) {
            const p = products[value(row,'product_id').value];
            if (eligible(p)) value(row,'rate').value = p[kind.value];
        }
        form.elements.pricing_kind.value = kind.value; form.elements.kind_confirmed.value = '1'; dirty(); syncType();
    }
    document.getElementById('use-kind-prices').addEventListener('click', () => confirmPrices(true));
    document.getElementById('keep-kind-prices').addEventListener('click', () => confirmPrices(false));
    list.addEventListener('change', event => {
        if (event.target.matches('[data-line-field=product_id]')) {
            const row = event.target.closest('[data-quote-line]'), p = products[event.target.value];
            if (p) { value(row,'rate').value = p[kind.value]; value(row,'tax_rate').value = p.tax_rate.toFixed(2); value(row,'tax_mode').value = p.tax_mode; }
        }
        filterProducts(); recalc();
    });
    list.addEventListener('input', recalc);
    list.addEventListener('click', event => {
        if (!event.target.matches('[data-remove-line]')) return;
        if (list.children.length > 1) {event.target.closest('[data-quote-line]').remove(); dirty(); recalc();}
        else document.getElementById('line-status').textContent = 'Keep at least one product.';
    });
    document.getElementById('add-quote-line').addEventListener('click', () => {
        if (list.children.length >= 30) return;
        list.insertAdjacentHTML('beforeend', document.getElementById('quote-line-template').innerHTML.replaceAll('__INDEX__',String(nextIndex++)));
        filterProducts(); dirty(); list.lastElementChild.querySelector('select').focus(); recalc();
    });
    form.elements.transport.addEventListener('input',recalc); form.elements.deposit.addEventListener('input',recalc);
    form.addEventListener('submit', event => {
        if (event.submitter?.value === 'review' && !form.elements.customer_id.value) {
            event.preventDefault(); document.querySelector('.sw-recipient').open = true;
            document.getElementById('customer-selection-note').textContent = 'Select a recipient or add a customer here before reviewing.';
            form.elements.customer_id.focus();
        }
    });
    document.querySelectorAll('[data-close-review]').forEach(button => button.addEventListener('click', () => review.close()));
    syncType();
    if (review?.dataset.open === '1') review.showModal();
})();
</script>
