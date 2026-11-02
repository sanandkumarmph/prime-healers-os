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
    const lineType = row => kind.value === 'sale' ? 'sale' : value(row,'line_type').value;
    const eligible = (p, row) => !!p && !!p[lineType(row) === 'rental' ? 'can_rent' : 'can_sell'];
    const date = input => /^\d{4}-\d{2}-\d{2}$/.test(input) ? new Date(input+'T00:00:00Z') : null;
    function periodDays() {
        const from = date(form.elements.start_date.value), to = date(form.elements.end_date.value);
        return from && to && to >= from ? Math.round((to-from)/86400000)+1 : null;
    }
    function periodPrice(p) {
        const days = periodDays();
        if (!days) return null;
        if (days === 15 && p.days15 > 0) return p.days15;
        if (days === 30 && p.days30 > 0) return p.days30;
        return p.daily > 0 ? round(p.daily * days) : null;
    }
    function periodNote() {
        const days = periodDays();
        document.getElementById('quote-duration-note').textContent = days ? days+' days, including start and end dates. Entered prices cover this entire period; changing dates preserves negotiated prices.' : 'Choose rental start and end dates.';
    }
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
            if (kind.value === 'sale') value(row,'line_type').value = 'sale';
            value(row,'line_type').querySelector('[value="rental"]').disabled = kind.value === 'sale';
            const select = value(row,'product_id');
            for (const option of select.options) {
                const unavailable = !!option.value && !eligible(products[option.value],row);
                option.hidden = unavailable && option.value !== select.value;
                option.disabled = unavailable && option.value !== select.value;
            }
            const invalid = !!select.value && !eligible(products[select.value],row);
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
    kind.addEventListener('change', () => { form.elements.kind_confirmed.value = '0'; for (const row of list.querySelectorAll('[data-quote-line]')) value(row,'line_type').value = kind.value; syncType(); });
    function confirmPrices(useDefaults) {
        if (useDefaults) for (const row of list.querySelectorAll('[data-quote-line]')) {
            const p = products[value(row,'product_id').value];
            if (eligible(p,row)) value(row,'rate').value = p[lineType(row)];
        }
        form.elements.pricing_kind.value = kind.value; form.elements.kind_confirmed.value = '1'; dirty(); syncType();
    }
    document.getElementById('use-kind-prices').addEventListener('click', () => confirmPrices(true));
    document.getElementById('keep-kind-prices').addEventListener('click', () => confirmPrices(false));
    list.addEventListener('change', event => {
        if (event.target.matches('[data-line-field=product_id],[data-line-field=line_type]')) {
            const row = event.target.closest('[data-quote-line]'), p = products[value(row,'product_id').value];
            if (p && eligible(p,row)) { value(row,'rate').value = p[lineType(row)]; value(row,'tax_rate').value = p.tax_rate.toFixed(2); value(row,'tax_mode').value = p.tax_mode; }
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
        value(list.lastElementChild,'line_type').value = kind.value;
        filterProducts(); dirty(); list.lastElementChild.querySelector('select').focus(); recalc();
    });
    form.elements.transport.addEventListener('input',recalc); form.elements.deposit.addEventListener('input',recalc);
    document.querySelectorAll('[data-close-review]').forEach(button => button.addEventListener('click', () => review.close()));
    document.getElementById('quote-duration').addEventListener('change', event => {
        const from = date(form.elements.start_date.value), days = Number(event.target.value);
        if (from && days > 0) {
            from.setUTCDate(from.getUTCDate()+days-1);
            form.elements.end_date.value = from.toISOString().slice(0,10);
            dirty(); periodNote();
            form.dispatchEvent(new Event('phos:quote-dates-changed'));
        }
    });
    ['start_date','end_date'].forEach(name => form.elements[name].addEventListener('change', () => {
        document.getElementById('quote-duration').value = 'custom'; periodNote();
    }));
    document.getElementById('quote-period-prices').addEventListener('click', () => {
        let missing = 0;
        for (const row of list.querySelectorAll('[data-quote-line]')) {
            const p = products[value(row,'product_id').value];
            if (!p || lineType(row) !== 'rental') continue;
            const price = periodPrice(p);
            if (price === null) missing++; else value(row,'rate').value = price;
        }
        dirty(); recalc(); periodNote();
        if (missing) document.getElementById('quote-duration-note').textContent += ' '+missing+' product(s) have no saved rate for these dates; keep or enter an agreed price.';
    });
    syncType(); periodNote();
    if (review?.dataset.open === '1') review.showModal();
})();
</script>
