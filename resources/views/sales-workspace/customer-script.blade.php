<script>
(() => {
    const select = document.getElementById('customer_id'), search = document.getElementById('customer-search');
    if (!select || !search) return;
    const contact = {{ Illuminate\Support\Js::from(['name'=>$enquiry?->name,'phone'=>\App\Support\PhoneNumber::normalize($enquiry?->phone),'email'=>$enquiry?->email]) }};
    const digits = value => String(value || '').replace(/\D/g, '');
    search.addEventListener('input', () => {
        const term = search.value.toLowerCase().trim();
        for (const option of select.options) option.hidden = !!option.value && option.value !== select.value && ![option.textContent, option.dataset.email].join(' ').toLowerCase().includes(term);
    });
    const suggestions = document.getElementById('customer-suggestions');
    const matches = Array.from(select.options).filter(option => option.value && (
        (contact.phone && digits(option.dataset.phoneNormalized || option.dataset.phone) === digits(contact.phone)) ||
        (contact.email && (option.dataset.email || '').toLowerCase() === contact.email.toLowerCase())
    ));
    if (!select.value && matches.length) {
        suggestions.textContent = 'Possible matching customer: ';
        for (const option of matches.slice(0,3)) {
            const button = document.createElement('button');
            button.type = 'button'; button.className = 'sw-btn sw-small'; button.textContent = option.textContent;
            button.addEventListener('click', () => { select.value = option.value; select.dispatchEvent(new Event('change', {bubbles:true})); });
            suggestions.append(button);
        }
    }
    const update = () => {
        const option = select.selectedOptions[0];
        document.getElementById('customer-selection-note').textContent = select.value
            ? 'Addressed to '+(option.dataset.name || option.textContent)+'. Your changes are applied when you save.'
            : 'Select the billing recipient. You can still save a draft without one.';
        suggestions.hidden = !!select.value;
        const label = document.getElementById('recipient-label');
        if (label) label.textContent = (select.value ? (option.dataset.name || option.textContent) : 'Select or add customer')+' · Change';
        const summary = document.getElementById('quote-recipient');
        if (summary) summary.textContent = select.value ? (option.dataset.name || option.textContent) : 'Choose a recipient';
    };
    select.addEventListener('change', update); update();
    const capture = document.getElementById('enquiry-form');
    document.getElementById('salesQuickCustomerForm')?.addEventListener('quick-customer:prefill', event => {
        if (!capture) return;
        const form = event.target;
        form.elements.first_name.value = capture.elements.name.value;
        form.elements.email.value = capture.elements.email.value;
        const phone = capture.elements.phone.value.trim();
        const codes = {{ Illuminate\Support\Js::from(array_keys(\App\Support\PhoneNumber::countryCodeOptions())) }};
        const code = phone.startsWith('+') ? codes.sort((a,b)=>b.length-a.length).find(code => ('+'+digits(phone)).startsWith(code)) : null;
        if (code) {
            form.elements.phone_country_code.value = code;
            form.elements.phone_country_code.dispatchEvent(new Event('change', {bubbles:true}));
            form.elements.phone.value = digits(phone).slice(code.length-1);
        } else form.elements.phone.value = phone.startsWith('+') ? '' : digits(phone);
    });
})();
</script>
