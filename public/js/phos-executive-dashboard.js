(() => {
    'use strict';

    const root = document.querySelector('[data-executive-dashboard]');
    if (!root) return; // Field-facing roles keep their existing task-first screen.

    const links = [...root.querySelectorAll('a[data-dashboard-tab]')];
    const panels = [...root.querySelectorAll('[data-dashboard-panel]')];
    const allowed = new Set(links.map(link => link.dataset.dashboardTab));
    const initial = root.dataset.activeDashboardTab;
    let active = allowed.has(initial) ? initial : links[0]?.dataset.dashboardTab;
    if (!active) return;

    const panelFor = name => panels.find(panel => panel.dataset.dashboardPanel === name);
    const dashboardPath = new URL(links[0].href, location.href).pathname;
    const reducedMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function syncForms() {
        root.querySelectorAll('form').forEach(form => {
            if ((form.getAttribute('method') || 'get').toLowerCase() !== 'get') return;
            const action = new URL(form.getAttribute('action') || location.href, location.href);
            if (action.origin !== location.origin || action.pathname !== dashboardPath) return;
            let input = form.querySelector('input[name="dashboard_tab"]');
            if (!input) {
                input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'dashboard_tab';
                form.append(input);
            }
            input.value = active;
        });
    }

    function select(name, {history = null, hash = ''} = {}) {
        if (!allowed.has(name) || !panelFor(name)) return false;
        active = name;
        panels.forEach(panel => { panel.hidden = panel.dataset.dashboardPanel !== active; });
        links.forEach(link => {
            const selected = link.dataset.dashboardTab === active;
            link.setAttribute('aria-selected', String(selected));
            link.classList.toggle('is-active', selected);
            const destination = new URL(location.href);
            destination.pathname = dashboardPath;
            destination.searchParams.set('dashboard_tab', link.dataset.dashboardTab);
            destination.hash = '';
            link.href = destination.href;
        });
        root.dataset.activeDashboardTab = active;
        syncForms();

        if (history) {
            const destination = new URL(location.href);
            destination.pathname = dashboardPath;
            destination.searchParams.set('dashboard_tab', active);
            destination.hash = hash;
            if (destination.href !== location.href) {
                window.history[history === 'replace' ? 'replaceState' : 'pushState'](null, '', destination);
            }
        }
        root.dispatchEvent(new CustomEvent('phos:dashboard-tab-change', {detail: {tab: active}}));
        return true;
    }

    function revealHash(hash, history = null) {
        if (!hash || hash === '#') return false;
        let id;
        try { id = decodeURIComponent(hash.slice(1)); } catch { return false; }
        const target = document.getElementById(id);
        if (!target || !root.contains(target)) return false;
        const panel = target.closest('[data-dashboard-panel]');
        if (!panel || !select(panel.dataset.dashboardPanel, {history, hash})) return false;
        let ancestor = target;
        while (ancestor && ancestor !== root) {
            if (ancestor.tagName === 'DETAILS') ancestor.open = true;
            ancestor = ancestor.parentElement;
        }
        requestAnimationFrame(() => target.scrollIntoView({block: 'start', behavior: reducedMotion() ? 'auto' : 'smooth'}));
        return true;
    }

    root.addEventListener('click', event => {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        const link = event.target.closest('a');
        if (!link || !root.contains(link) || link.target === '_blank' || link.hasAttribute('download')) return;
        if (link.hasAttribute('data-dashboard-tab')) {
            if (select(link.dataset.dashboardTab, {history: 'push'})) event.preventDefault();
            return;
        }
        const url = new URL(link.href, location.href);
        // Filter changes need a server response before revealing their destination.
        if (url.origin === location.origin && url.pathname === dashboardPath && url.search === location.search && url.hash && revealHash(url.hash, 'push')) {
            event.preventDefault();
        }
    });

    root.querySelector('[role="tablist"]')?.addEventListener('keydown', event => {
        const current = event.target.closest('[data-dashboard-tab]');
        if (!current) return;
        let index = links.indexOf(current);
        if (event.key === 'ArrowRight') index = (index + 1) % links.length;
        else if (event.key === 'ArrowLeft') index = (index + links.length - 1) % links.length;
        else if (event.key === 'Home') index = 0;
        else if (event.key === 'End') index = links.length - 1;
        else return;
        event.preventDefault();
        links[index].focus();
        select(links[index].dataset.dashboardTab, {history: 'push'});
    });

    window.addEventListener('popstate', () => {
        const requested = new URL(location.href).searchParams.get('dashboard_tab');
        select(allowed.has(requested) ? requested : (allowed.has('overview') ? 'overview' : initial));
        revealHash(location.hash, 'replace');
    });
    window.addEventListener('hashchange', () => revealHash(location.hash, 'replace'));

    select(active);
    revealHash(location.hash, 'replace');
})();
