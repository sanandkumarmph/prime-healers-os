const number = value => Number(value || 0).toLocaleString();
const invalidate = target => {
    target.availabilityVersion = (target.availabilityVersion || 0) + 1;
    target.textContent = 'Selection changed. Check availability again for a current result.';
};
export function renderAvailability(target, result) {
    target.replaceChildren();
    for (const row of result.rows) {
        const card = document.createElement('section');
        card.className = 'sw-panel';
        const title = document.createElement('h3');
        title.className = 'sw-availability-title';
        title.textContent = `${row.name} · ${row.status}`;
        card.append(title);
        const summary = document.createElement('p');
        summary.className = 'sw-availability-summary';
        summary.textContent = row.location_unknown
            ? 'This product uses quantity stock without warehouse allocation. Clear the warehouse filter to check the organisation total; city-restricted accounts should ask an administrator.'
            : `Requested ${number(row.requested)} · Ready now ${number(row.ready_now)} · Forecast for these dates ${number(row.forecast)}${row.shortfall ? ` · Shortfall ${number(row.shortfall)}` : ''}`;
        card.append(summary);
        if (!row.location_unknown) {
            const detail = document.createElement('p');
            detail.className = 'sw-meta';
            detail.textContent = `Expected back ${number(row.expected_back)} · Reserved later ${number(row.reserved_later)} · Committed ${number(row.committed)} · Overdue ${number(row.overdue)} · Unavailable ${number(row.unavailable)}`;
            card.append(detail);
        }
        target.append(card);
    }
}
export async function checkAvailability(url, data, target, button) {
    const version = target.availabilityVersion = (target.availabilityVersion || 0) + 1;
    button.disabled = true;
    target.textContent = 'Checking availability…';
    try {
        const response = await fetch(url, {method: 'POST', credentials: 'same-origin', headers: {
            'Content-Type': 'application/json', Accept: 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
        }, body: JSON.stringify(data)});
        const result = await response.json();
        if (!response.ok) throw new Error(Object.values(result.errors || {}).flat().join(' ') || result.message || 'Unable to check availability.');
        if (target.availabilityVersion === version) renderAvailability(target, result);
    } catch (error) { if (target.availabilityVersion === version) target.textContent = error.message || 'Unable to check availability. Please try again.'; }
    finally { button.disabled = false; }
}
document.querySelectorAll('[data-availability-workspace]').forEach(workspace => {
    const form = workspace.querySelector('[data-availability-form]');
    const target = workspace.querySelector('[data-availability-results]');
    form.addEventListener('input', () => invalidate(target));
    form.addEventListener('submit', event => {
        event.preventDefault();
        const fields = new FormData(form);
        checkAvailability(workspace.dataset.checkUrl, {
            start_date: fields.get('start_date'), end_date: fields.get('end_date'), warehouse_id: fields.get('warehouse_id') || null,
            lines: [{product_id: fields.get('product_id'), quantity: fields.get('quantity')}],
        }, target, form.querySelector('button[type="submit"]'));
    });
});
const quotation = document.querySelector('#quotation-form');
if (quotation) {
    const button = quotation.querySelector('[data-quote-availability]');
    const target = quotation.querySelector('[data-quote-availability-results]');
    button?.addEventListener('click', () => {
        const fields = new FormData(quotation);
        const lines = [];
        for (const [name, value] of fields) {
            const match = name.match(/^lines\[(\d+)\]\[product_id\]$/);
            if (match && value) lines.push({product_id: value, quantity: fields.get(`lines[${match[1]}][quantity]`)});
        }
        checkAvailability(button.dataset.quoteAvailability, {start_date: fields.get('start_date'), end_date: fields.get('end_date'), lines}, target, button);
    });
    quotation.addEventListener('input', event => {
        if (target && /^(start_date|end_date|kind|lines\[)/.test(event.target.name || '')) invalidate(target);
    });
    quotation.querySelector('#quotation-lines')?.addEventListener('click', () => { if (target) invalidate(target); });
}
