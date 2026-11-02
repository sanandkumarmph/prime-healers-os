(() => {
    const form = document.getElementById('quotation-form');
    const button = form?.querySelector('[data-quote-rental-check]');
    const target = form?.querySelector('[data-quote-availability-results]');
    if (!button || !target) return;
    let version = 0;
    for (const event of ['input', 'change']) form.addEventListener(event, () => {
        version++;
        if (target.textContent) target.textContent = 'Selection changed. Check rental availability again.';
    });
    form.addEventListener('phos:quote-dates-changed', () => {
        version++;
        target.textContent = 'Dates changed. Check rental availability again.';
    });
    button.addEventListener('click', async () => {
        if (button.disabled) return;
        const current = ++version, fields = new FormData(form), lines = [];
        for (const [name, value] of fields) {
            const match = name.match(/^lines\[(\d+)\]\[product_id\]$/);
            if (match && value && (fields.get(`lines[${match[1]}][line_type]`) || fields.get('kind')) === 'rental') {
                lines.push({product_id: value, quantity: fields.get(`lines[${match[1]}][quantity]`)});
            }
        }
        if (!lines.length) { target.textContent = 'Add at least one rental product to check availability.'; return; }
        button.disabled = true;
        target.textContent = 'Checking rental availability…';
        const controller = new AbortController(), timer = setTimeout(() => controller.abort(), 20000);
        try {
            const response = await fetch(button.dataset.quoteRentalCheck, {method: 'POST', credentials: 'same-origin', signal: controller.signal,
                headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': fields.get('_token') || ''},
                body: JSON.stringify({start_date: fields.get('start_date'), end_date: fields.get('end_date'), lines})});
            const result = await response.json();
            if (current !== version) return;
            if (!response.ok || !Array.isArray(result.rows)) {
                target.textContent = response.status === 422 ? Object.values(result.errors || {}).flat().join(' ').slice(0, 1000) : 'Unable to check availability. Refresh or try again.';
                return;
            }
            target.replaceChildren();
            for (const row of result.rows) {
                const card = document.createElement('section'), title = document.createElement('strong'), copy = document.createElement('p');
                card.className = 'sw-panel';
                title.textContent = `${row.name} · ${row.status}`;
                copy.textContent = row.location_unknown ? 'Ask an administrator to confirm stock for the correct location.'
                    : `Requested ${row.requested} · Ready now ${row.ready_now} · Forecast ${row.forecast} · Shortfall ${row.shortfall || 0}. Expected back ${row.expected_back} · Committed ${row.committed} · Overdue ${row.overdue} · Unavailable ${row.unavailable}.`;
                card.append(title, copy); target.append(card);
            }
        } catch {
            if (current === version) target.textContent = 'Unable to check availability. Please try again.';
        } finally { clearTimeout(timer); button.disabled = false; }
    });
})();
