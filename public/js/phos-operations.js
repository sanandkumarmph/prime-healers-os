(() => {
    'use strict';
    const filters = [...document.querySelectorAll('[data-phos-list-filter]')];
    const close = (filter, focus = false) => {
        filter.open = false;
        if (focus) filter.querySelector('summary')?.focus();
    };
    filters.forEach(filter => {
        filter.querySelectorAll('details[data-filter-panel]').forEach(panel => {
            panel.dataset.mobileKeepOpen = 'true';
            panel.open = true;
        });
        filter.addEventListener('toggle', () => {
            if (filter.open) filters.filter(other => other !== filter).forEach(other => close(other));
        });
        filter.querySelectorAll('[data-phos-list-filter-close]').forEach(button => {
            button.hidden = false;
            button.addEventListener('click', () => close(filter, true));
        });
    });
    document.addEventListener('click', event => {
        if (event.target.closest('.phos-predictive-panel')) return;
        filters.filter(filter => filter.open && !filter.contains(event.target)).forEach(filter => close(filter));
    });
    document.addEventListener('keydown', event => {
        if (event.defaultPrevented || event.key !== 'Escape') return;
        const filter = filters.find(item => item.open && item.contains(document.activeElement)) || filters.find(item => item.open);
        if (filter) { event.preventDefault(); close(filter, true); }
    });
})();
