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
            : 'Addressed to the enquiry contact. No customer record or full address is needed to prepare and share this quotation.';
        suggestions.hidden = !!select.value;
        const label = document.getElementById('recipient-label');
        if (label) label.textContent = (select.value ? (option.dataset.name || option.textContent) : contact.name || 'Enquiry contact')+' · Change';
        const summary = document.getElementById('quote-recipient');
        if (summary) summary.textContent = select.value ? (option.dataset.name || option.textContent) : contact.name || 'Enquiry contact';
    };
    select.addEventListener('change', update); update();
})();
</script>
