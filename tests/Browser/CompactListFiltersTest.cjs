const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const {test} = require('node:test');
const puppeteer = require('puppeteer');

const repository = path.resolve(__dirname, '../..');
const script = fs.readFileSync(path.join(repository, 'public/js/phos-operations.js'));
const stylesheet = fs.readFileSync(path.join(repository, 'public/css/phos-operations.css'));
// Optional local override permits demonstrating the legacy-selector regression.
const layout = fs.readFileSync(process.env.PHOS_COMPACT_LAYOUT_SOURCE
    || path.join(repository, 'resources/views/layouts/app.blade.php'), 'utf8');
const application = fs.readFileSync(path.join(repository, 'resources/js/app.js'), 'utf8');
function sourceSection(source, start, end) {
    const first = source.indexOf(start);
    const last = source.indexOf(end, first);
    assert.ok(first >= 0 && last > first, 'The existing filter initialization block must be available for coexistence testing.');
    return source.slice(first, last);
}
// Execute the existing initialization blocks themselves, so a regression in the
// older active/local-storage/mobile handlers cannot be hidden by a test copy.
const legacyScript = sourceSection(application, 'const initializeMobileUxStandardization = ', 'const initializeGlobalOutsideDismiss = ')
    + '\ndocument.addEventListener("DOMContentLoaded", () => {\n'
    + sourceSection(layout, '            const filterPanels = ', '            const closeAllSortMenus = ')
    + '\n});';
const escape = value => String(value).replaceAll('&', '&amp;').replaceAll('"', '&quot;')
    .replaceAll('<', '&lt;').replaceAll('>', '&gt;');

// The fixture exercises the shared component's public HTML contract. Real Blade
// rendering, permission gates, and all eight forms are covered by the PHP suite.
function fixture(url) {
    const active = ['search', 'status', 'from_date', 'to_date', 'city'].some(key => url.searchParams.get(key));
    const value = name => escape(url.searchParams.get(name) || '');
    const filters = (id, label, content, isActive = false) => `
        <details class="phos-list-filter${isActive ? ' has-active-filters' : ''}" id="${id}" data-phos-list-filter data-mobile-keep-open="true" data-filter-panel data-filter-panel-key="${id}" data-filter-active="${isActive}">
            <summary class="phos-list-filter-toggle" data-phos-list-filter-toggle aria-label="${label}${isActive ? ', filters active' : ''}" aria-controls="${id}-panel">Filters
                ${isActive ? '<span class="phos-list-filter-dot" aria-hidden="true"></span>' : ''}
            </summary>
            <div class="phos-list-filter-panel" id="${id}-panel" data-phos-list-filter-panel role="region" aria-label="${label}">
                <div class="phos-list-filter-head"><strong>${label}</strong><button type="button" class="phos-list-filter-close" data-phos-list-filter-close aria-label="Close filters" hidden>Close</button></div>
                <div class="phos-list-filter-content">${content}</div>
            </div>
        </details>`;
    const form = `<form method="GET" action="${url.pathname}" data-test="filter-form">
        <input type="hidden" name="filter" value="${escape(url.searchParams.get('filter') || 'all')}">
        <label>Search <input name="search" type="search" value="${value('search')}"></label>
        <label>Status <select name="status">${['', 'active', 'overdue'].map(status => `<option value="${status}"${url.searchParams.get('status') === status ? ' selected' : ''}>${status || 'All statuses'}</option>`).join('')}</select></label>
        <label>From date <input name="from_date" type="date" value="${value('from_date')}"></label>
        <details data-filter-panel data-filter-panel-key="fixture-advanced"><summary>More filters</summary>
            <label>To date <input name="to_date" type="date" value="${value('to_date')}"></label>
            <label>City <select name="city">${['', 'Bengaluru', 'Mysuru'].map(city => `<option value="${city}"${url.searchParams.get('city') === city ? ' selected' : ''}>${city || 'All cities'}</option>`).join('')}</select></label>
        </details>
        <button type="submit">Apply filters</button><a href="${url.pathname}" data-test="reset">Reset</a>
    </form>`;
    return `<!doctype html><html><head><meta charset="utf-8"><title>Compact filter regression fixture</title>
        <link rel="stylesheet" href="/public/css/phos-operations.css">
        <style>body{margin:0;padding:20px;font:14px Arial}main{max-width:900px}.phos-page-header{display:flex;gap:16px;align-items:center}.phos-list-filter-content form{display:grid;gap:10px}label{display:grid;gap:4px}button,a{padding:7px}[data-test=outside]{position:fixed;bottom:70px;left:20px}.phos-predictive-panel{position:fixed;bottom:10px;left:20px}</style>
        </head><body class="phos-navigation-refresh phos-list-page"><main data-phos-compact-page="rentals">
            <header class="phos-page-header"><h1>Rentals</h1>${filters('rentals-list-filters', 'Filter rentals', form, active)}
                ${filters('secondary-filters', 'Other filters', '<label>Other search <input type="search" name="other"></label>')}
                <a href="/rentals/new" data-test="new">New rental</a>
            </header><button type="button" data-test="outside">Outside control</button>
            <div class="phos-predictive-panel"><button type="button" data-test="predictive-option">Predictive search option</button></div>
        </main><script src="/legacy-filter-initialization.js"></script><script src="/public/js/phos-operations.js"></script></body></html>`;
}

test('compact list filters preserve native form navigation and dismissal', {timeout: 90000}, async t => {
    const requests = [];
    const disallowed = [];
    let origin;
    const server = http.createServer((request, response) => {
        const url = new URL(request.url, origin);
        if (request.method !== 'GET') {
            disallowed.push({method: request.method, pathname: url.pathname});
            response.writeHead(405);
            response.end();
            return;
        }
        if (url.pathname === '/legacy-filter-initialization.js') {
            response.writeHead(200, {'Content-Type': 'application/javascript', 'Cache-Control': 'no-store'});
            response.end(legacyScript);
        } else if (url.pathname === '/public/js/phos-operations.js' || url.pathname === '/public/css/phos-operations.css') {
            response.writeHead(200, {'Content-Type': url.pathname.endsWith('.js') ? 'application/javascript' : 'text/css', 'Cache-Control': 'no-store'});
            response.end(url.pathname.endsWith('.js') ? script : stylesheet);
        } else if (url.pathname === '/rentals') {
            requests.push(url);
            response.writeHead(200, {'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store'});
            response.end(fixture(url));
        } else {
            response.writeHead(204);
            response.end();
        }
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    origin = `http://127.0.0.1:${server.address().port}`;
    let browser;
    t.after(async () => {
        if (browser) await browser.close();
        await new Promise(resolve => server.close(resolve));
        assert.deepEqual(disallowed, [], 'The browser regression must never submit a mutating request.');
    });
    browser = await puppeteer.launch({headless: true,
        ...(process.env.CI ? {args: ['--no-sandbox', '--disable-setuid-sandbox']} : {})});

    async function pageFor(query = {}, {javascript = true, width = 1280} = {}) {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.setViewport({width, height: 1000});
        await page.setJavaScriptEnabled(javascript);
        await page.setRequestInterception(true);
        page.on('request', request => request.url().startsWith(origin + '/') ? request.continue() : request.abort());
        await page.evaluateOnNewDocument(() => {
            // Staff may have expanded the old filter panel before the restyle.
            localStorage.setItem('rentnexis:filter-panel:rentals-list-filters', 'open');
        });
        await page.goto(origin + '/rentals?' + new URLSearchParams(query), {waitUntil: 'networkidle0'});
        return {page, errors};
    }
    const isOpen = (page, id = 'rentals-list-filters') => page.$eval('#' + id, details => details.open);
    const waitForOpen = (page, open, id = 'rentals-list-filters') => page.waitForFunction(
        (selector, expected) => document.querySelector(selector).open === expected, {}, '#' + id, open);

    await t.test('active filters stay collapsed with their badge; opening another popover closes the first', async () => {
        const {page, errors} = await pageFor({status: 'active', city: 'Bengaluru'}, {width: 390});
        try {
            assert.equal(await isOpen(page), false);
            assert.equal(await page.$eval('#rentals-list-filters > summary', summary => summary.getAttribute('aria-label')), 'Filter rentals, filters active');
            assert.equal(await page.$$('#rentals-list-filters > summary .phos-list-filter-dot').then(nodes => nodes.length), 1);
            await page.click('#rentals-list-filters > summary');
            await waitForOpen(page, true);
            assert.equal(await page.$eval('#rentals-list-filters details[data-filter-panel]', details => details.open), true);
            assert.equal(await page.$eval('#rentals-list-filters details[data-filter-panel]', details => details.dataset.mobileKeepOpen), 'true');
            await page.setViewport({width: 1280, height: 1000});
            await page.setViewport({width: 390, height: 1000});
            await waitForOpen(page, true);
            await page.click('#secondary-filters > summary');
            await waitForOpen(page, true, 'secondary-filters');
            await waitForOpen(page, false);
            assert.deepEqual(errors, []);
        } finally {await page.close();}
    });

    await t.test('close and Escape return focus to the trigger; outside dismissal preserves the clicked control focus', async () => {
        const {page, errors} = await pageFor();
        try {
            await page.click('#rentals-list-filters > summary');
            await page.click('#rentals-list-filters [data-phos-list-filter-close]');
            await waitForOpen(page, false);
            assert.equal(await page.$eval('#rentals-list-filters > summary', summary => document.activeElement === summary), true);
            await page.keyboard.press('Enter');
            await waitForOpen(page, true);
            await page.focus('[data-test="filter-form"] [name="search"]');
            await page.keyboard.press('Escape');
            await waitForOpen(page, false);
            assert.equal(await page.$eval('#rentals-list-filters > summary', summary => document.activeElement === summary), true);
            await page.click('#rentals-list-filters > summary');
            await page.click('[data-test="outside"]');
            await waitForOpen(page, false);
            assert.equal(await page.$eval('[data-test="outside"]', button => document.activeElement === button), true);
            await page.click('#rentals-list-filters > summary');
            await page.click('[data-test="predictive-option"]');
            assert.equal(await isOpen(page), true, 'A portaled predictive-search option belongs to the open filter interaction.');
            assert.deepEqual(errors, []);
        } finally {await page.close();}
    });

    for (const javascript of [true, false]) {
        await t.test(`Apply and Reset use real GET requests on the current list (${javascript ? 'JavaScript' : 'no JavaScript'})`, async () => {
            const {page, errors} = await pageFor({filter: 'ending_soon', page: '4', city: 'Bengaluru'}, {javascript, width: javascript ? 1280 : 390});
            try {
                assert.equal(await isOpen(page), false);
                assert.equal(await page.$eval('#rentals-list-filters [data-phos-list-filter-close]', button => button.hidden), !javascript);
                await page.click('#rentals-list-filters > summary');
                if (!await page.$eval('#rentals-list-filters details[data-filter-panel]', details => details.open)) {
                    await page.click('#rentals-list-filters details[data-filter-panel] > summary');
                }
                await page.$eval('[data-test="filter-form"] [name="search"]', input => {input.value = '';});
                await page.type('[data-test="filter-form"] [name="search"]', 'Oxygen & accessories');
                await page.select('[data-test="filter-form"] [name="status"]', 'overdue');
                await page.select('[data-test="filter-form"] [name="city"]', 'Mysuru');
                await page.$eval('[data-test="filter-form"]', form => {
                    form.elements.from_date.value = '2026-10-01';
                    form.elements.to_date.value = '2026-10-30';
                });
                const before = requests.length;
                await Promise.all([
                    page.waitForNavigation({waitUntil: 'networkidle0', timeout: 5000}),
                    page.click('[data-test="filter-form"] button[type="submit"]'),
                ]);
                assert.equal(requests.length, before + 1);
                assert.equal(requests.at(-1).pathname, '/rentals');
                const expected = {filter: 'ending_soon', search: 'Oxygen & accessories', status: 'overdue', from_date: '2026-10-01', to_date: '2026-10-30', city: 'Mysuru'};
                assert.deepEqual(Object.fromEntries(requests.at(-1).searchParams), expected);
                assert.deepEqual(Object.fromEntries(new URL(page.url()).searchParams), expected);
                assert.equal(new URL(page.url()).searchParams.has('page'), false, 'Applying filters starts the matching list at its first page.');
                assert.equal(await isOpen(page), false);
                await page.click('#rentals-list-filters > summary');
                await Promise.all([
                    page.waitForNavigation({waitUntil: 'networkidle0', timeout: 5000}),
                    page.click('[data-test="reset"]'),
                ]);
                assert.equal(requests.at(-1).pathname, '/rentals');
                assert.equal(requests.at(-1).search, '');
                assert.equal(await page.$$('#rentals-list-filters > summary .phos-list-filter-dot').then(nodes => nodes.length), 0);
                assert.equal(await isOpen(page), false);
                assert.deepEqual(errors, []);
            } finally {await page.close();}
        });
    }
});
