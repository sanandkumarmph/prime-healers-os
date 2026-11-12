const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { randomUUID } = require('node:crypto');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { test } = require('node:test');
const puppeteer = require('puppeteer');

const root = path.resolve(__dirname, '../..');
const route = 'http://127.0.0.1/deliveries/route-plan';
const handle = '[data-route-drag-handle]';
const stop = '[data-route-stop]';

test('route rows can be dragged or placed using a mouse, touchscreen or keyboard', { timeout: 90000 }, async t => {
    const fixture = path.join(os.tmpdir(), 'phos-route-order-' + randomUUID() + '.html');
    t.after(() => fs.rmSync(fixture, { force: true }));
    execFileSync(process.env.PHOS_TEST_PHP || 'php', [
        'vendor/bin/pest', 'tests/Feature/Regression/DeliveryRoutePlannerTest.php',
        '--configuration', 'phpunit.xml', '--do-not-cache-result',
    ], { cwd: root, env: { ...process.env, PHOS_ROUTE_PLANNER_BROWSER_FIXTURE: fixture }, timeout: 60000 });
    const html = fs.readFileSync(fixture, 'utf8');
    const script = fs.readFileSync(path.join(root, 'public/js/phos-route-order.js'), 'utf8');
    const browser = await puppeteer.launch({ headless: true, args: ['--disable-background-networking', '--no-first-run'] });
    t.after(() => browser.close());
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.setRequestInterception(true);
    page.on('request', request => {
        const url = new URL(request.url());
        if (url.pathname === '/deliveries/route-plan') {
            return request.respond({ status: 200, contentType: 'text/html', body: html });
        }
        if (url.pathname === '/js/phos-route-order.js') {
            return request.respond({ status: 200, contentType: 'text/javascript', body: script });
        }
        return request.respond({ status: 404, body: '' });
    });

    const sequence = () => page.$$eval(stop, rows => rows.map(row => row.dataset.routeStop));
    async function load(viewport) {
        await page.setViewport(viewport);
        await page.goto(route);
        await page.waitForSelector(handle, { visible: true });
        assert.equal(await page.$eval('[data-route-reorder-fallback]', element => getComputedStyle(element).display), 'none');
        assert.equal(await page.$eval('[data-route-drag-help]', element => getComputedStyle(element).clipPath), 'inset(50%)');
        await page.$eval(handle, element => element.scrollIntoView({ block: 'center', inline: 'nearest' }));
        return sequence();
    }

    async function point(selector, index, deltaY = 0) {
        return page.$$eval(selector, (elements, index, deltaY) => {
            const box = elements[index].getBoundingClientRect();
            return { x: Math.round(box.left + box.width / 2), y: Math.round(box.top + box.height / 2 + deltaY) };
        }, index, deltaY);
    }

    async function place(action, ids) {
        await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 7000 }), action()]);
        const result = new URL(page.url());
        assert.equal(result.searchParams.get('order'), [ids[1], ids[0], ...ids.slice(2)].join(','));
        assert.equal(result.searchParams.get('date'), '2026-10-10');
        assert.ok(result.searchParams.get('warehouse'));
        assert.equal(result.hash, '#route-stop-' + ids[0]);
    }

    const desktop = { width: 1280, height: 900 };
    const desktopIds = await load(desktop);
    const first = await point(handle, 0);
    const belowSecond = await point(stop, 1, 10);
    assert.equal(await page.evaluate(({ x, y }) => !!document.elementFromPoint(x, y)?.closest('[data-route-drag-handle]'), first), true);
    await place(async () => {
        await page.mouse.move(first.x, first.y);
        await page.mouse.down();
        await page.mouse.move(first.x, belowSecond.y, { steps: 8 });
        await page.mouse.up();
    }, desktopIds);

    const keyboardIds = await load(desktop);
    await page.focus(handle);
    await page.keyboard.press('Space');
    await page.keyboard.press('ArrowDown');
    assert.equal((await sequence())[1], keyboardIds[0]);
    await page.keyboard.press('Escape');
    assert.deepEqual(await sequence(), keyboardIds);
    await page.keyboard.press('Space');
    await page.keyboard.press('ArrowDown');
    await place(() => page.keyboard.press('Space'), keyboardIds);

    const mobile = { width: 390, height: 844, isMobile: true, hasTouch: true, deviceScaleFactor: 1 };
    for (const width of [320, 390]) {
        await load({ ...mobile, width });
        const layout = await page.$eval(stop, row => {
            const destination = row.querySelector('.route-stop-destination');
            const directions = row.querySelector('.route-stop-directions a');
            return {
                display: getComputedStyle(row).display,
                destinationWidth: destination.getBoundingClientRect().width,
                directionsHeight: directions.getBoundingClientRect().height,
                overflow: document.documentElement.scrollWidth > window.innerWidth,
            };
        });
        assert.equal(layout.display, 'grid');
        assert.ok(layout.destinationWidth > width * 0.62, 'Destination should use the width of the stop card');
        assert.ok(layout.directionsHeight >= 44, 'Directions should be easy to tap');
        assert.equal(layout.overflow, false);
    }
    const touchIds = await load(mobile);
    const finger = await point(handle, 0);
    const drop = await point(stop, 1, 10);
    await place(async () => {
        const touch = await page.touchscreen.touchStart(finger.x, finger.y);
        for (let step = 1; step <= 8; step++) {
            await touch.move(finger.x, Math.round(finger.y + (drop.y - finger.y) * step / 8));
        }
        await touch.end();
    }, touchIds);

    const tapIds = await load(mobile);
    const tapFirst = await point(handle, 0);
    const tapSecond = await point(handle, 1);
    await page.touchscreen.tap(tapFirst.x, tapFirst.y);
    await place(() => page.touchscreen.tap(tapSecond.x, tapSecond.y), tapIds);
    assert.deepEqual(errors, []);
});
