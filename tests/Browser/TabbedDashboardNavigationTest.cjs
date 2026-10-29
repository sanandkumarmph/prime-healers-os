const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const { test } = require('node:test');
const puppeteer = require('puppeteer');

const repository = path.resolve(__dirname, '../..');
// Optional local override permits demonstrating that the pre-fix asset fails.
const script = fs.readFileSync(process.env.PHOS_DASHBOARD_NAVIGATION_SCRIPT
    || path.join(repository, 'public/js/phos-executive-dashboard.js'));
const tabs = ['overview', 'team', 'operations', 'revenue', 'inventory', 'activity'];
const teamFilters = ['team_performance_period', 'team_performance_from_date',
    'team_performance_to_date', 'team_performance_city', 'team_performance_role'];
const globalFilters = { city: 'Bengaluru', from_date: '2026-10-01', to_date: '2026-10-29', vendor_id: '4' };
const escape = value => String(value).replaceAll('&', '&amp;').replaceAll('"', '&quot;')
    .replaceAll('<', '&lt;').replaceAll('>', '&gt;');

function fixture(url, requestId) {
    const selected = tabs.includes(url.searchParams.get('dashboard_tab')) ? url.searchParams.get('dashboard_tab') : 'overview';
    const reset = new URL(url);
    teamFilters.forEach(key => reset.searchParams.delete(key));
    reset.hash = 'team-performance';
    const inventory = new URL(url);
    inventory.hash = 'inventory-detail';
    const changedFilter = new URL(url);
    changedFilter.searchParams.set('city', 'Mysuru');
    changedFilter.hash = 'revenue-aging';
    const tabLinks = tabs.map(tab => {
        const destination = new URL(url);
        destination.searchParams.set('dashboard_tab', tab);
        destination.hash = '';
        return `<a role="tab" data-dashboard-tab="${tab}" aria-selected="${tab === selected}" href="${escape(destination.pathname + destination.search)}">${tab}</a>`;
    }).join('');
    const content = {
        overview: `<a data-test="same-query" href="${escape(inventory.pathname + inventory.search + inventory.hash)}">Inventory details</a>
            <a data-test="changed-filter" href="${escape(changedFilter.pathname + changedFilter.search + changedFilter.hash)}">Mysuru invoice aging</a>`,
        team: `<section id="team-performance"><h2>Team Performance</h2><a data-test="reset" href="${escape(reset.pathname + reset.search + reset.hash)}">Reset</a></section>`,
        operations: '<h2>Operations</h2>',
        revenue: '<details id="revenue-aging"><summary>Invoice aging</summary><p>Current server-filtered balances</p></details>',
        inventory: '<details id="inventory-detail"><summary>Inventory details</summary><p>Current server-filtered equipment</p></details>',
        activity: '<h2>Activity</h2>',
    };
    return `<!doctype html><html><head><meta charset="utf-8"><title>Dashboard navigation fixture</title>
        <style>[hidden]{display:none!important}a{display:inline-block;margin:8px;padding:8px}section{min-height:60px}</style></head><body>
        <main data-executive-dashboard data-active-dashboard-tab="${selected}" data-server-request="${requestId}" data-server-city="${escape(url.searchParams.get('city') || '')}">
            <nav role="tablist">${tabLinks}</nav>
            ${tabs.map(tab => `<section data-dashboard-panel="${tab}"${tab === selected ? '' : ' hidden'}>${content[tab]}</section>`).join('')}
        </main><script src="/public/js/phos-executive-dashboard.js"></script></body></html>`;
}

test('dashboard fragments preserve server-side filter navigation', { timeout: 90000 }, async t => {
    const requests = [];
    let origin;
    const server = http.createServer((request, response) => {
        const url = new URL(request.url, origin);
        if (url.pathname === '/public/js/phos-executive-dashboard.js') {
            response.writeHead(200, { 'Content-Type': 'application/javascript', 'Cache-Control': 'no-store' });
            response.end(script);
        } else if (url.pathname === '/dashboard') {
            requests.push({ method: request.method, url });
            response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
            response.end(fixture(url, requests.length));
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
    });
    browser = await puppeteer.launch({ headless: true,
        ...(process.env.CI ? { args: ['--no-sandbox', '--disable-setuid-sandbox'] } : {}) });

    async function pageFor(query) {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.setRequestInterception(true);
        page.on('request', request => request.url().startsWith(origin + '/')
            ? request.continue() : request.abort());
        await page.emulateMediaFeatures([{ name: 'prefers-reduced-motion', value: 'reduce' }]);
        await page.goto(origin + '/dashboard?' + new URLSearchParams(query), { waitUntil: 'networkidle0' });
        return { page, errors };
    }

    async function assertPanel(page, selected) {
        assert.equal(await page.$eval('[data-executive-dashboard]', root => root.dataset.activeDashboardTab), selected);
        assert.deepEqual(await page.$$eval('[data-dashboard-panel]', panels => panels
            .filter(panel => !panel.hidden).map(panel => panel.dataset.dashboardPanel)), [selected]);
        assert.deepEqual(await page.$$eval('[data-dashboard-tab]', links => links
            .filter(link => link.getAttribute('aria-selected') === 'true').map(link => link.dataset.dashboardTab)), [selected]);
    }

    await t.test('Team Reset removes all five team filters through a server GET and reveals Team from its hash', async () => {
        const query = { ...globalFilters, dashboard_tab: 'overview', team_performance_period: 'custom_range',
            team_performance_from_date: '2026-10-02', team_performance_to_date: '2026-10-20',
            team_performance_city: 'Bengaluru', team_performance_role: 'sales' };
        const { page, errors } = await pageFor(query);
        try {
            const initialRequest = await page.$eval('[data-executive-dashboard]', root => root.dataset.serverRequest);
            const count = requests.length;
            await page.click('[data-dashboard-tab="team"]');
            await assertPanel(page, 'team');
            assert.equal(requests.length, count, 'A normal tab switch should stay local.');
            await Promise.all([
                page.waitForNavigation({ waitUntil: 'networkidle0', timeout: 5000 }),
                page.click('[data-test="reset"]'),
            ]);
            assert.notEqual(await page.$eval('[data-executive-dashboard]', root => root.dataset.serverRequest), initialRequest);
            assert.equal(requests.length, count + 1, 'Reset must request newly filtered data from the server.');
            assert.equal(requests.at(-1).method, 'GET');
            for (const key of teamFilters) {
                assert.equal(requests.at(-1).url.searchParams.has(key), false, `Reset did not remove ${key} on the server request.`);
                assert.equal(new URL(page.url()).searchParams.has(key), false, `Reset left ${key} in browser history.`);
            }
            for (const [key, value] of Object.entries(globalFilters)) {
                assert.equal(requests.at(-1).url.searchParams.get(key), value, `Reset discarded global filter ${key}.`);
                assert.equal(new URL(page.url()).searchParams.get(key), value);
            }
            assert.equal(requests.at(-1).url.searchParams.get('dashboard_tab'), 'overview', 'Fixture should exercise hash-driven Team selection after server rendering Overview.');
            assert.equal(new URL(page.url()).hash, '#team-performance');
            await assertPanel(page, 'team');
            assert.deepEqual(errors, []);
        } finally { await page.close(); }
    });

    await t.test('same-query cross-panel fragments remain local and retain the current filters', async () => {
        const { page, errors } = await pageFor({ ...globalFilters, dashboard_tab: 'overview', team_performance_role: 'sales' });
        try {
            const count = requests.length;
            const before = await page.$eval('[data-executive-dashboard]', root => root.dataset.serverRequest);
            await page.click('[data-test="same-query"]');
            await assertPanel(page, 'inventory');
            assert.equal(requests.length, count);
            assert.equal(await page.$eval('[data-executive-dashboard]', root => root.dataset.serverRequest), before);
            assert.equal(new URL(page.url()).hash, '#inventory-detail');
            assert.equal(new URL(page.url()).searchParams.get('team_performance_role'), 'sales');
            for (const [key, value] of Object.entries(globalFilters)) assert.equal(new URL(page.url()).searchParams.get(key), value);
            assert.equal(await page.$eval('#inventory-detail', details => details.open), true);
            assert.deepEqual(errors, []);
        } finally { await page.close(); }
    });

    await t.test('adding a filter to a dashboard fragment requests fresh server data before showing the target panel', async () => {
        const { page, errors } = await pageFor({ ...globalFilters, dashboard_tab: 'overview' });
        try {
            const count = requests.length;
            const before = await page.$eval('[data-executive-dashboard]', root => root.dataset.serverRequest);
            await Promise.all([
                page.waitForNavigation({ waitUntil: 'networkidle0', timeout: 5000 }),
                page.click('[data-test="changed-filter"]'),
            ]);
            assert.equal(requests.length, count + 1);
            assert.notEqual(await page.$eval('[data-executive-dashboard]', root => root.dataset.serverRequest), before);
            assert.equal(requests.at(-1).url.searchParams.get('city'), 'Mysuru');
            assert.equal(await page.$eval('[data-executive-dashboard]', root => root.dataset.serverCity), 'Mysuru');
            for (const key of ['from_date', 'to_date', 'vendor_id']) assert.equal(new URL(page.url()).searchParams.get(key), globalFilters[key]);
            assert.equal(new URL(page.url()).hash, '#revenue-aging');
            await assertPanel(page, 'revenue');
            assert.equal(await page.$eval('#revenue-aging', details => details.open), true);
            assert.deepEqual(errors, []);
        } finally { await page.close(); }
    });
});
