<style>
.sw-customer-picker{position:relative}.sw-customer-picker .searchable-select-panel{position:absolute;top:100%;left:0;right:0;z-index:80;padding:5px;border:1px solid #ccd7e5;border-radius:9px;background:#fff;box-shadow:0 12px 32px #17335520;max-height:260px;overflow:auto}.sw-customer-picker .searchable-select-panel[hidden]{display:none!important}.sw-customer-option{display:block;width:100%;padding:9px 10px;border:0;border-radius:6px;background:#fff;text-align:left;color:#172b4d;font:inherit;font-size:13px;cursor:pointer;overflow-wrap:anywhere}.sw-customer-option:hover,.sw-customer-option[aria-selected=true]{background:#edf3ff}.sw-customer-option strong,.sw-customer-option small{display:block}.sw-customer-option small{color:#63748b;font-size:11px;margin-top:2px}.sw-customer-empty{padding:10px;color:#63748b;font-size:12px}.sw-customer-picker select[hidden]{display:none!important}
</style>
<script>
(() => {
    const select = document.querySelector('[data-customer-picker]');
    if (!select || select.dataset.swEnhanced) return;
    select.dataset.swEnhanced = 'true';
    const holder = select.closest('[data-sw-customer-picker]'), label = holder.querySelector('[data-customer-picker-label]');
    const contact = {{ Illuminate\Support\Js::from(['name'=>$enquiry?->name,'phone'=>\App\Support\PhoneNumber::normalize($enquiry?->phone),'email'=>$enquiry?->email]) }};
    const search = document.createElement('input');
    search.id = 'customer-picker-search'; search.type = 'search'; search.autocomplete = 'off';
    search.className = 'searchable-select-search'; search.dataset.noPredictiveSearch = 'true';
    search.placeholder = 'Search customer name, phone or email';
    search.setAttribute('role', 'combobox'); search.setAttribute('aria-autocomplete', 'list');
    search.setAttribute('aria-expanded', 'false'); search.setAttribute('aria-controls', 'customer-picker-results');
    const panel = document.createElement('div'); panel.className = 'searchable-select-panel'; panel.id = 'customer-picker-results'; panel.hidden = true; panel.setAttribute('role', 'listbox');
    select.hidden = true; label.htmlFor = search.id; select.after(search, panel);
    let highlighted = -1;
    const options = Array.from(select.options);
    const close = () => { panel.hidden = true; search.setAttribute('aria-expanded', 'false'); search.removeAttribute('aria-activedescendant'); };
    const selectedLabel = () => select.value ? (select.selectedOptions[0]?.dataset.name || select.selectedOptions[0]?.textContent || '') : '';
    const choose = option => { select.value = option.value; select.dispatchEvent(new Event('change', {bubbles:true})); search.focus(); search.value = selectedLabel(); close(); };
    const render = () => {
        panel.replaceChildren(); highlighted = -1;
        const term = search.value.toLocaleLowerCase().trim(), digits = term.replace(/[^0-9]/g, '');
        const matching = options.filter(option => !option.value || !term || (option.textContent+' '+(option.dataset.email || '')).toLocaleLowerCase().includes(term) || (digits.length >= 3 && (option.dataset.phoneNormalized || option.dataset.phone || '').replace(/[^0-9]/g,'').includes(digits)));
        matching.slice(0,60).forEach((option,index) => {
            const button = document.createElement('button'); button.type = 'button'; button.className = 'sw-customer-option'; button.id = 'customer-result-'+index;
            button.setAttribute('role','option'); button.setAttribute('aria-selected',option.selected ? 'true' : 'false');
            const title = document.createElement('strong'); title.textContent = option.value ? option.dataset.name : 'Use enquiry contact'; button.append(title);
            const meta = document.createElement('small'); meta.textContent = option.value ? [option.dataset.phone,option.dataset.email].filter(Boolean).join(' · ') : 'No customer record needed'; button.append(meta);
            button.addEventListener('click', () => choose(option)); panel.append(button);
        });
        if (!matching.some(option => option.value)) { const empty = document.createElement('div'); empty.className = 'sw-customer-empty'; empty.textContent = term ? 'No matching customers. You can keep the enquiry contact.' : 'No existing customers.'; panel.append(empty); }
        panel.hidden = false; search.setAttribute('aria-expanded','true');
    };
    search.addEventListener('focus', () => { search.value = ''; render(); });
    search.addEventListener('input', render);
    search.addEventListener('keydown', event => {
        const choices = Array.from(panel.querySelectorAll('[role=option]'));
        if (event.key === 'Escape') { close(); search.value = selectedLabel(); return; }
        if (event.key === 'Tab') { close(); search.value = selectedLabel(); return; }
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault(); if (panel.hidden) render();
            const active = Array.from(panel.querySelectorAll('[role=option]'));
            if (!active.length) return;
            highlighted = (highlighted + (event.key === 'ArrowDown' ? 1 : -1) + active.length) % active.length;
            active.forEach((button,index) => button.setAttribute('aria-selected',index === highlighted ? 'true' : 'false'));
            search.setAttribute('aria-activedescendant',active[highlighted].id); active[highlighted].scrollIntoView({block:'nearest'});
        } else if (event.key === 'Enter' && !panel.hidden) { event.preventDefault(); if (highlighted >= 0) choices[highlighted]?.click(); }
    });
    document.addEventListener('pointerdown', event => { if (!holder.contains(event.target)) { close(); search.value = selectedLabel(); } });
    const filled = {}, initial = Object.fromEntries(['name','phone','email','salutation'].map(key => [key,document.getElementById(key)?.value || '']));
    const update = () => {
        const option = select.selectedOptions[0]; search.value = selectedLabel();
        if (select.value && document.getElementById('enquiry-form')) {
            for (const key of ['name','phone','email','salutation']) {
                const input = document.getElementById(key), value = option.dataset[key] || '';
                if (input && (!input.value || input.value === filled[key])) { input.value = value; filled[key] = value; }
            }
        } else if (document.getElementById('enquiry-form')) {
            for (const key of Object.keys(filled)) {
                const input = document.getElementById(key);
                if (input && input.value === filled[key]) input.value = initial[key];
                delete filled[key];
            }
        }
        document.getElementById('customer-selection-note').textContent = select.value
            ? 'Quotation recipient: '+(option.dataset.name || option.textContent)+'. Save to apply.'
            : 'Use the enquiry contact. Customer registration is needed only when creating the order.';
        const recipient = select.value ? (option.dataset.name || option.textContent) : contact.name || 'Enquiry contact';
        const recipientLabel = document.getElementById('recipient-label'); if (recipientLabel) recipientLabel.textContent = recipient+' · Change';
        const summary = document.getElementById('quote-recipient'); if (summary) summary.textContent = recipient;
    };
    select.addEventListener('change', update); update();
})();
</script>
