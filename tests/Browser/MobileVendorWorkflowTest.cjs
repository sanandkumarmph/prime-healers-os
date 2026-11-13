const assert = require('node:assert/strict');
const { execFileSync, spawn } = require('node:child_process');
const { randomBytes } = require('node:crypto');
const { once } = require('node:events');
const fs = require('node:fs');
const net = require('node:net');
const os = require('node:os');
const path = require('node:path');
const { test } = require('node:test');
const { setTimeout: delay } = require('node:timers/promises');
const puppeteer = require('puppeteer');

const uatOrigin = 'https://uat-admin.primehealers.com';
const localRepository = path.resolve(process.env.PHOS_MOBILE_VENDOR_REPOSITORY || path.join(__dirname, '../..'));
const fixture = path.join(__dirname, 'fixtures/mobile-vendor-workflow.php');
const php = process.env.PHOS_TEST_PHP || 'php';
const reason = 'Synthetic browser test. No customer or site visit.';
const gps = 'Synthetic browser test. No GPS captured.';

async function loopbackPort() {
    const server = net.createServer();
    server.listen(0, '127.0.0.1');
    await once(server, 'listening');
    const port = server.address().port;
    server.close();
    await once(server, 'close');
    return port;
}

async function localFixtures(temporary, t) {
    const password = randomBytes(16).toString('hex');
    const port = await loopbackPort();
    const origin = `http://127.0.0.1:${port}`;
    const db = path.join(temporary, 'database.sqlite');
    fs.writeFileSync(db, '');
    for (const folder of ['views', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/logs']) {
        fs.mkdirSync(path.join(temporary, folder), { recursive: true });
    }
    const env = {
        ...process.env,
        APP_ENV: 'testing', APP_DEBUG: 'false', APP_URL: origin, APP_TIMEZONE: 'Asia/Kolkata',
        APP_KEY: 'base64:' + randomBytes(32).toString('base64'),
        APP_CONFIG_CACHE: path.join(temporary, 'config.php'), APP_ROUTES_CACHE: path.join(temporary, 'routes.php'),
        VIEW_COMPILED_PATH: path.join(temporary, 'views'), LARAVEL_STORAGE_PATH: path.join(temporary, 'storage'),
        DB_CONNECTION: 'sqlite', DB_DATABASE: db, DB_URL: '', INTERNAL_SINGLE_ORG_ID: '',
        INTERNAL_SINGLE_ORG_MODE: 'true', INTERNAL_SINGLE_ORG_NAME: 'Prime Healers',
        CACHE_STORE: 'array', SESSION_DRIVER: 'file', SESSION_DOMAIN: '', SESSION_SECURE_COOKIE: 'false',
        QUEUE_CONNECTION: 'sync', MAIL_MAILER: 'array', PROOF_STORAGE_DISK: 'local',
        PHOS_MOBILE_VENDOR_FIXTURE_DIR: temporary, PHOS_MOBILE_VENDOR_FIXTURE_PASSWORD: password,
    };
    const options = { cwd: localRepository, env, timeout: 60000, encoding: 'utf8' };
    const phpOptions = ['-d', 'variables_order=EGPCS'];
    execFileSync(php, [...phpOptions, 'artisan', 'migrate', '--force', '--no-interaction'], options);
    const tasks = JSON.parse(execFileSync(php, [...phpOptions, fixture, 'seed'], options));
    const router = path.join(localRepository, 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php');
    const server = spawn(php, [...phpOptions, '-S', `127.0.0.1:${port}`, router], {
        cwd: path.join(localRepository, 'public'), env, stdio: ['ignore', 'ignore', 'pipe'],
    });
    let failure = '';
    server.stderr.on('data', data => { failure = (failure + data).slice(-2500); });
    t.after(async () => {
        if (server.exitCode === null && server.signalCode === null) {
            const exit = once(server, 'exit');
            server.kill();
            await exit;
        }
    });
    for (let attempt = 0; attempt < 50; attempt++) {
        const response = await fetch(`${origin}/login`).catch(() => null);
        if (response?.ok) {
            return { ...tasks, origin, email: 'vendor@example.test', password,
                verify: () => JSON.parse(execFileSync(php, [...phpOptions, fixture, 'verify'], options)) };
        }
        if (server.exitCode !== null) break;
        await delay(100);
    }
    assert.fail('The disposable loopback test server did not start. ' + failure);
}

function uatFixtures(filename) {
    const { email, password, delivery, pickup } = JSON.parse(fs.readFileSync(filename, 'utf8'));
    const tasks = [delivery, pickup].map(value => Number(value?.task ?? value));
    assert.ok(typeof email === 'string' && typeof password === 'string' && tasks.every(id => Number.isSafeInteger(id) && id > 0),
        'The private UAT fixture must provide login credentials and two valid task IDs.');
    assert.notEqual(tasks[0], tasks[1]);
    return { origin: uatOrigin, email, password, delivery: tasks[0], pickup: tasks[1] };
}

function stepButton(form, step) {
    return `${form} [data-workflow-step-panel][data-workflow-step-index="${step}"] [data-workflow-next]`;
}

async function clickAction(page, selector, title) {
    const button = await page.waitForSelector(selector, { visible: true });
    const target = await button.evaluate(element => {
        const box = element.getBoundingClientRect();
        const x = box.left + box.width / 2, y = box.top + box.height / 2;
        const front = document.elementFromPoint(x, y);
        const toast = document.querySelector('[data-action-toast]');
        const toastBox = toast?.getBoundingClientRect();
        const footer = element.closest('.workflow-step-actions')?.getBoundingClientRect();
        const toastOverlaps = toastBox && footer && footer.left < toastBox.right && footer.right > toastBox.left
            && footer.top < toastBox.bottom && footer.bottom > toastBox.top;
        return { x, y, reachable: element.contains(front), toastOverlaps, toastHeight: toastBox?.height,
            toastPointerEvents: toast && getComputedStyle(toast).pointerEvents,
            blocker: front?.closest('[data-action-toast]') ? 'action toast' : front?.tagName };
    });
    assert.ok(!target.toastOverlaps, `${title} must remain clear of the action toast`);
    assert.ok(target.reachable, `${title} must receive a physical click, front element: ${target.blocker}`);
    if (target.toastPointerEvents) {
        assert.ok(target.toastHeight > 0 && target.toastHeight <= 120, `${title} action toast must remain compact`);
        assert.equal(target.toastPointerEvents, 'none', `${title} action toast must never intercept clicks`);
    }
    await page.mouse.click(target.x, target.y);
}

async function waitForStep(page, form, step) {
    await page.waitForSelector(`${form} [data-workflow-step-panel][data-workflow-step-index="${step}"].is-current`, { visible: true });
}

test('a Vendor starts and completes both mobile workflows using the action buttons', { timeout: 150000 }, async t => {
    const tempParent = fs.realpathSync(os.tmpdir());
    const temporary = fs.mkdtempSync(path.join(tempParent, 'phos-mobile-vendor-'));
    const browserFixtures = process.env.PHOS_MOBILE_VENDOR_UAT_FIXTURES;
    let browser;
    try {
        const fixtures = browserFixtures ? uatFixtures(browserFixtures) : await localFixtures(temporary, t);
        browser = await puppeteer.launch({ headless: true, args: ['--disable-background-networking', '--no-first-run'] });
        const page = await browser.newPage();
        const width = Number(process.env.PHOS_MOBILE_VENDOR_WIDTH || 390);
        assert.ok(Number.isInteger(width) && width >= 320 && width <= 767);
        await page.setViewport({ width, height: 844, hasTouch: true, isMobile: true });
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.setRequestInterception(true);
        page.on('request', request => {
            if ([fixtures.origin + '/', 'data:', 'blob:'].some(prefix => request.url().startsWith(prefix))) return request.continue();
            return request.abort();
        });
        await page.goto(fixtures.origin + '/login', { waitUntil: 'domcontentloaded' });
        await page.type('#email', fixtures.email);
        await page.type('#password', fixtures.password);
        await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.click('form button[type="submit"]')]);
        await page.waitForFunction(() => document.body.innerText.includes('Tasks Board'));
        assert.match(new URL(page.url()).pathname, /^\/deliveries\/?$/);

        const photo = path.join(temporary, 'synthetic-equipment.png');
        const png = await page.evaluate(() => {
            const canvas = document.createElement('canvas');
            canvas.width = 220; canvas.height = 110;
            const context = canvas.getContext('2d');
            context.fillStyle = '#f0f4f8'; context.fillRect(0, 0, 220, 110);
            context.fillStyle = '#142a3d'; context.font = '18px sans-serif'; context.fillText('SYNTHETIC TEST PHOTO', 6, 55);
            return canvas.toDataURL('image/png').split(',')[1];
        });
        fs.writeFileSync(photo, Buffer.from(png, 'base64'));

        for (const type of ['delivery', 'pickup']) {
            const id = fixtures[type];
            const label = type === 'delivery' ? 'Delivery' : 'Pickup';
            const taskPath = `/deliveries/${id}`;
            const start = 'form[data-workflow-form="start"]';
            const complete = 'form[data-workflow-form="complete"]';
            const next = async (form, step, text) => {
                await clickAction(page, stepButton(form, step), `${label} ${text}`);
                await waitForStep(page, form, step + 1);
            };
            await page.goto(fixtures.origin + taskPath, { waitUntil: 'domcontentloaded' });
            await page.click('.delivery-mobile-sticky-cta a[href="#workflow-proof-section"]');
            await waitForStep(page, start, 1);
            await next(start, 1, 'Start');
            await page.type('#location_missing_reason_start', gps);
            await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), clickAction(page, stepButton(start, 2), `${label} Save GPS`)]);
            await page.waitForSelector(complete, { visible: true });
            assert.match(await page.$eval('[data-action-toast]', element => element.textContent), /marked as in progress/);

            // The start redirect may go straight to Photos when the GPS reason is reused.
            if (await page.$(`${complete} [data-workflow-step-index="1"].is-current`)) {
                const currentReason = await page.$eval('#location_missing_reason_complete', element => element.value);
                if (!currentReason) await page.type('#location_missing_reason_complete', gps);
                await next(complete, 1, 'Continue to Photos');
            }
            await waitForStep(page, complete, 2);
            const photoFields = type === 'delivery' ? ['#delivery_device_photos', '#premises_photo'] : ['#pickup_device_photos'];
            for (const selector of photoFields) await (await page.$(selector)).uploadFile(photo);
            await page.waitForFunction(selectors => selectors.every(selector => {
                const field = document.querySelector(selector);
                return field.files.length && field.dataset.preparingPhotos !== '1' && field.validity.valid;
            }), {}, photoFields);
            await next(complete, 2, type === 'pickup' ? 'Continue to Check' : 'Continue to Notes');

            // An expired toast can still intercept pointer events if only opacity changes.
            await page.waitForFunction(() => Number(getComputedStyle(document.querySelector('[data-action-toast]')).opacity) === 0
                || getComputedStyle(document.querySelector('[data-action-toast]')).visibility === 'hidden');
            if (type === 'pickup') {
                await page.click('[data-pickup-condition="good"]');
                await next(complete, 3, 'Continue to Notes');
            }
            const notesStep = type === 'pickup' ? 4 : 3;
            await next(complete, notesStep, 'Continue to Signature');
            await page.type('#signature_unavailable_reason', reason);
            await next(complete, notesStep + 1, 'Review');
            await page.click('input[name="completion_confirmed"]');
            const submitted = page.waitForResponse(response => response.url() === fixtures.origin + taskPath + '/complete'
                && response.request().method() === 'POST');
            const completionPage = page.waitForNavigation({ waitUntil: 'domcontentloaded' });
            await clickAction(page, 'button[data-workflow-complete]', `${label} Complete`);
            assert.equal((await submitted).status(), 302);
            await completionPage;
            if (!(await page.$('[data-workflow-success-screen]'))) {
                const failure = await page.evaluate(() => document.querySelector('[data-action-toast]')?.textContent.trim());
                assert.fail(`${label} completion was not saved. ${failure || 'No completion feedback.'}`);
            }
            await page.waitForNavigation({ waitUntil: 'domcontentloaded' });
            assert.equal(new URL(page.url()).pathname, '/deliveries');
        }

        for (const [type, label] of [['delivery', 'Delivered'], ['pickup', 'Picked Up']]) {
            const response = await page.goto(`${fixtures.origin}/deliveries/${fixtures[type]}`, { waitUntil: 'domcontentloaded' });
            assert.equal(response.status(), 200);
            const statuses = await page.$$eval('.status-badge', badges => badges.map(badge => badge.textContent.trim()));
            assert.ok(statuses.includes(label), `The Vendor task page must retain ${label} after reopening.`);
            assert.equal(await page.$('form[data-workflow-form]'), null, 'A completed task has no start or completion form.');
        }
        if (fixtures.verify) {
            const results = fixtures.verify();
            for (const [type, task] of Object.entries(results)) {
                assert.equal(task.status, 'completed');
                assert.equal(task.completed_at_saved, true);
                assert.equal(task.delivered_quantity, 1);
                assert.equal(task.start_proof && task.completion_proof && task.proof_files_exist, true, JSON.stringify({type, ...task}));
                assert.equal(task.proof_images, type === 'delivery' ? 2 : 1);
            }
            assert.equal(results.pickup.pickup_status, 'picked_up');
            assert.equal(results.pickup.rental_status, 'returned');
            assert.equal(results.pickup.returned_quantity, 1);
        }
        assert.deepEqual(errors, []);
    } finally {
        await browser?.close();
        t.after(() => {
            assert.equal(path.dirname(fs.realpathSync(temporary)), tempParent);
            assert.ok(path.basename(temporary).startsWith('phos-mobile-vendor-'));
            fs.rmSync(temporary, { recursive: true, force: true, maxRetries: 5, retryDelay: 100 });
        });
    }
});
