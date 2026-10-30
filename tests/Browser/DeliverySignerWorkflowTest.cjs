const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { randomBytes } = require('node:crypto');
const fs = require('node:fs');
const http = require('node:http');
const os = require('node:os');
const path = require('node:path');
const { test } = require('node:test');
const puppeteer = require('puppeteer');

const repository = path.resolve(__dirname, '../..');
const publicRoot = path.join(repository, 'public');
const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
const mime = { '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.png': 'image/png', '.woff2': 'font/woff2', '.ico': 'image/x-icon' };

test('delivery and pickup signer acknowledgement works in the actual rendered workflow', { timeout: 240000 }, async t => {
    const temporaryParent = fs.realpathSync(os.tmpdir());
    const temporary = fs.mkdtempSync(path.join(temporaryParent, 'phos-signer-browser-'));
    t.after(() => {
        assert.equal(path.dirname(temporary), temporaryParent);
        assert.ok(path.basename(temporary).startsWith('phos-signer-browser-'));
        fs.rmSync(temporary, { recursive: true, force: true });
    });
    for (const folder of ['rendered', 'views', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/logs']) {
        fs.mkdirSync(path.join(temporary, folder), { recursive: true });
    }
    const rendered = path.join(temporary, 'rendered');
    // No .env or persistent application database is needed. Isolated cache paths
    // also prevent a developer's cached production configuration being loaded.
    const fixtureEnvironment = {
        ...process.env,
        APP_ENV: 'testing', APP_DEBUG: 'false', APP_URL: 'http://127.0.0.1:8000',
        APP_KEY: 'base64:' + randomBytes(32).toString('base64'),
        APP_CONFIG_CACHE: path.join(temporary, 'config.php'), APP_ROUTES_CACHE: path.join(temporary, 'routes.php'),
        VIEW_COMPILED_PATH: path.join(temporary, 'views'), LARAVEL_STORAGE_PATH: path.join(temporary, 'storage'),
        DB_CONNECTION: 'sqlite', DB_DATABASE: ':memory:', DB_URL: '',
        CACHE_STORE: 'array', SESSION_DRIVER: 'array', QUEUE_CONNECTION: 'sync', MAIL_MAILER: 'array',
        PHOS_ACCESS_PREVIEW_DIR: rendered,
    };
    execFileSync(process.env.PHOS_TEST_PHP || 'php', [
        'vendor/bin/pest', 'tests/Feature/Regression/DeliverySignerNameRegressionTest.php',
        '--do-not-cache-result', '--colors=never',
    ], { cwd: repository, env: fixtureEnvironment, timeout: 120000, maxBuffer: 8 * 1024 * 1024 });

    const manifest = JSON.parse(fs.readFileSync(path.join(publicRoot, 'build/manifest.json'), 'utf8'));
    const expectedAssets = ['resources/js/app.js', 'resources/css/app.css'].map(key => '/build/' + manifest[key].file);
    const assets = new Map(), errors = [], writes = [];
    let origin;
    const server = http.createServer((request, response) => {
        const url = new URL(request.url, origin);
        if (request.method !== 'GET') {
            writes.push({ method: request.method, path: url.pathname });
            response.writeHead(405); response.end('Read only'); return;
        }
        if (url.pathname.startsWith('/fixture/')) {
            const filename = path.basename(url.pathname);
            const file = path.join(rendered, filename);
            if (!/^(delivery|pickup)-signature(?:-(?:escaped-|legacy-|unavailable-)?history)?\.html$/.test(filename) || !fs.existsSync(file)) {
                response.writeHead(404); response.end(); return;
            }
            response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
            response.end(fs.readFileSync(file, 'utf8').replaceAll('http://127.0.0.1:8000', origin)); return;
        }
        const file = path.resolve(publicRoot, '.' + decodeURIComponent(url.pathname));
        if (file.startsWith(publicRoot + path.sep) && fs.existsSync(file) && fs.statSync(file).isFile()) {
            response.writeHead(200, { 'Content-Type': mime[path.extname(file)] || 'application/octet-stream' });
            fs.createReadStream(file).pipe(response); return;
        }
        if (/\/proofs\//.test(url.pathname)) {
            response.writeHead(200, { 'Content-Type': 'image/svg+xml' });
            response.end('<svg xmlns="http://www.w3.org/2000/svg" width="120" height="60"><path d="M10 40L40 10L70 45L110 20" stroke="black" fill="none"/></svg>'); return;
        }
        if (/\.(js|css|svg|png|woff2?)$/.test(url.pathname)) { response.writeHead(404); response.end(); return; }
        response.writeHead(200, { 'Content-Type': 'application/json' });
        response.end(JSON.stringify({ data: [], notifications: [], unread_count: 0 }));
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    origin = `http://127.0.0.1:${server.address().port}`;
    t.after(() => new Promise(resolve => server.close(resolve)));
    const browser = await puppeteer.launch({ headless: true, args: ['--disable-background-networking', '--no-first-run', ...(process.env.CI ? ['--no-sandbox'] : [])] });
    t.after(() => browser.close());

    async function pageFor(filename, width) {
        const page = await browser.newPage();
        await page.setViewport({ width, height: width < 768 ? 844 : 1000 });
        await page.setRequestInterception(true);
        page.on('request', request => {
            if (request.method() !== 'GET') {
                writes.push({ method: request.method(), url: request.url() });
                return request.respond({ status: 405, body: 'Read only' });
            }
            if ([origin + '/', 'data:', 'blob:'].some(prefix => request.url().startsWith(prefix))) return request.continue();
            return request.respond({ status: 200, contentType: 'text/css', body: '' });
        });
        page.on('pageerror', error => errors.push(error.message));
        page.signerDialogs = [];
        page.on('dialog', dialog => { page.signerDialogs.push(dialog.message()); dialog.dismiss(); });
        page.on('console', message => { if (message.type() === 'error' && /ResizeObserver/.test(message.text())) errors.push(message.text()); });
        page.on('response', response => {
            const url = new URL(response.url());
            if (expectedAssets.includes(url.pathname)) assets.set(url.pathname, { status: response.status(), type: response.headers()['content-type'] });
        });
        await page.evaluateOnNewDocument(() => {
            window.interceptedWorkflowSubmits = [];
            document.addEventListener('submit', event => {
                event.preventDefault();
                window.interceptedWorkflowSubmits.push(Object.fromEntries([...new FormData(event.target)].filter(([, value]) => typeof value === 'string')));
            }, true);
        });
        await page.goto(`${origin}/fixture/${filename}`, { waitUntil: 'networkidle0' });
        await page.addStyleTag({ content: '*{scroll-behavior:auto!important}' });
        return page;
    }
    async function input(page, selector, value) {
        await page.$eval(selector, (field, value) => {
            field.value = value;
            field.dispatchEvent(new Event('input', { bubbles: true }));
            field.dispatchEvent(new Event('change', { bubbles: true }));
        }, value);
    }
    async function draw(page) {
        await page.$eval('[data-signature-pad]', canvas => canvas.scrollIntoView({ block: 'center' }));
        const r = await page.$eval('[data-signature-pad]', canvas => { const r = canvas.getBoundingClientRect(); return { x: r.x, y: r.y, width: r.width }; });
        await page.mouse.move(r.x + 20, r.y + 30); await page.mouse.down();
        await page.mouse.move(r.x + r.width * .35, r.y + 70, { steps: 6 });
        await page.mouse.move(r.x + r.width * .7, r.y + 35, { steps: 6 }); await page.mouse.up();
        await page.waitForFunction(() => document.querySelector('input[name="signature_data"]').value.startsWith('data:image/png;base64,'));
    }
    async function assertFits(page) {
        const state = await page.evaluate(() => {
            const wrap = document.querySelector('.workflow-proof-signature-wrap'), box = wrap.getBoundingClientRect();
            const controls = [...wrap.querySelectorAll('input:not([type=hidden]),textarea,canvas,button')].filter(el => el.getBoundingClientRect().width > 0).map(el => { const r = el.getBoundingClientRect(); return { left: r.left, right: r.right }; });
            return { fits: controls.every(r => r.left >= box.left - 1 && r.right <= box.right + 1), client: wrap.clientWidth, scroll: wrap.scrollWidth, page: document.documentElement.scrollWidth, width: innerWidth };
        });
        assert.ok(state.fits && state.scroll <= state.client + 1 && state.page <= state.width + 1, JSON.stringify(state));
    }
    async function screenshot(page, name) {
        if (!process.env.PHOS_SIGNER_BROWSER_ARTIFACTS) return;
        const folder = path.resolve(process.env.PHOS_SIGNER_BROWSER_ARTIFACTS);
        fs.mkdirSync(folder, { recursive: true });
        await page.screenshot({ path: path.join(folder, name + '.png') });
    }
    for (const type of ['delivery', 'pickup']) {
        await t.test(`${type}: a desktop reason cannot replace the captured signer`, async () => {
            const page = await pageFor(`${type}-signature.html`, 1440);
            try {
                await draw(page);
                await input(page, '#signature_unavailable_reason', 'Reason entered after drawing.');
                assert.equal(await page.$eval('[data-signature-signer-name]', el => el.required), true);
                assert.equal(await page.$eval('[data-review-item="signature"]', el => el.dataset.reviewReady), 'false');
                await input(page, '[data-signature-signer-name]', 'Asha Devi');
                assert.equal(await page.$eval('[data-review-signature-description]', el => el.textContent), 'Signed by: Asha Devi');
            } finally { await page.close(); }
        });
        for (const width of [390, 320]) {
            await t.test(`${type}: named, resized and unavailable acknowledgement at ${width}px`, async () => {
                const page = await pageFor(`${type}-signature.html`, width);
                try {
                    await page.evaluate(() => [...document.querySelectorAll('a[href="#workflow-proof-section"]')].find(a => a.getBoundingClientRect().width > 0).click());
                    await page.waitForFunction(() => document.querySelector('[data-mobile-workflow-shell]').dataset.mobileShellState === 'open');
                    const step = type === 'pickup' ? 5 : 4;
                    const panel = `[data-workflow-step-index="${step}"]`;
                    await page.$eval(`[data-workflow-step-tab="${step}"]`, el => el.click());
                    await assertFits(page); await draw(page);
                    const captured = await page.$eval('input[name="signature_data"]', el => el.value);
                    await page.setViewport({ width: width === 320 ? 390 : 320, height: 844 }); await pause(100);
                    await assertFits(page);
                    await page.$eval(`[data-workflow-step-tab="${step - 1}"]`, el => el.click());
                    await page.$eval(`[data-workflow-step-tab="${step}"]`, el => el.click()); await pause(70);
                    assert.equal(await page.$eval('input[name="signature_data"]', el => el.value), captured, 'Resizing or hiding a completed stroke must not rewrite the evidence.');
                    assert.equal(await page.$eval('[data-signature-preview-image]', el => el.src), captured);
                    assert.equal(await page.$eval('[data-signature-preview-image]', el => el.complete && el.naturalWidth > 0), true);
                    await draw(page);
                    assert.notEqual(await page.$eval('input[name="signature_data"]', el => el.value), captured, 'The visible resized pad must accept another real pointer stroke.');
                    await page.setViewport({ width, height: 844 }); await pause(60);
                    await page.$eval(panel + ' [data-workflow-next]', el => el.click());
                    assert.equal(await page.$eval(panel, el => el.classList.contains('is-current') && document.activeElement.matches('[data-signature-signer-name]')), true);
                    await input(page, '[data-signature-signer-name]', '   ');
                    await page.$eval(panel + ' [data-workflow-next]', el => el.click());
                    assert.equal(await page.$eval(panel, el => el.classList.contains('is-current')), true);
                    const name = 'Asha <script>window.signerInjected=1</script>';
                    await input(page, '[data-signature-signer-name]', name);
                    assert.equal(await page.$eval('[data-review-signature-description]', (el, name) => el.textContent === `Signed by: ${name}` && el.children.length === 0, name), true);
                    assert.equal(await page.evaluate(() => window.signerInjected), undefined);
                    await input(page, '[data-signature-signer-name]', 'Asha ' + 'LongSignerName'.repeat(8));
                    await input(page, 'textarea[name="location_missing_reason"]', 'Synthetic GPS unavailable indoors.');
                    const image = await page.$eval('input[name="signature_data"]', el => el.value.split(',')[1]);
                    const upload = path.join(temporary, 'synthetic-photo.png'); fs.writeFileSync(upload, Buffer.from(image, 'base64'));
                    for (const selector of (type === 'pickup' ? ['input[name="pickup_device_photos[]"]'] : ['input[name="delivery_device_photos[]"]', 'input[name="premises_photo"]'])) await (await page.$(selector)).uploadFile(upload);
                    if (type === 'pickup') await page.$eval('[data-pickup-condition="good"]', el => el.click());
                    await page.$eval('input[name="completion_confirmed"]', el => { el.checked = true; el.dispatchEvent(new Event('change', { bubbles: true })); });
                    await page.waitForFunction(() => document.querySelector('[data-review-item="photos"]').dataset.reviewReady === 'true');
                    await page.$eval(panel + ' [data-workflow-next]', el => el.click());
                    assert.equal(await page.$eval('[data-review-signature-description]', el => el.clientWidth > 0 && el.scrollWidth <= el.clientWidth + 1), true);
                    await input(page, '[data-signature-signer-name]', 'Asha Devi');
                    assert.equal(await page.$eval('[data-workflow-complete]', el => el.disabled), false);
                    await page.$eval('[data-workflow-complete]', el => el.click());
                    assert.equal(await page.evaluate(() => window.interceptedWorkflowSubmits.some(data => data.signature_signer_name === 'Asha Devi' && data.signature_data.startsWith('data:image/png;base64,'))), true);
                    await screenshot(page, `${type}-named-${width}`);
                    await page.$eval(`[data-workflow-step-tab="${step}"]`, el => el.click());
                    await page.$eval('[data-signature-clear]', el => el.click());
                    await input(page, '[data-signature-signer-name]', '');
                    const reason = 'Customer could not sign because of limited mobility. ' + 'Synthetic detail. '.repeat(15);
                    await input(page, '#signature_unavailable_reason', reason);
                    assert.equal(await page.$eval('[data-signature-signer-name]', el => el.required), false);
                    await page.$eval(panel + ' [data-workflow-next]', el => el.click());
                    assert.equal(await page.$eval('[data-review-signature-description]', el => el.clientWidth > 0 && el.scrollWidth <= el.clientWidth + 1), true);
                    await page.$eval('[data-workflow-complete]', el => el.click());
                    assert.equal(await page.evaluate(reason => window.interceptedWorkflowSubmits.some(data => !data.signature_data && !data.signature_signer_name && data.signature_unavailable_reason === reason), reason), true);
                    await page.$eval(`[data-workflow-step-tab="${step}"]`, el => el.click());
                    await draw(page);
                    assert.equal(await page.$eval('#signature_unavailable_reason', el => el.value), '', 'A new signature clears a stale reason.');
                    assert.equal(await page.$eval('[data-signature-signer-name]', el => el.required), true);
                } finally { await page.close(); }
            });
        }
    }
    for (const [filename, text] of [
        ['delivery-signature-escaped-history.html', 'Asha <script>alert(1)</script>'],
        ['delivery-signature-legacy-history.html', 'Name not recorded (legacy proof)'],
        ['pickup-signature-unavailable-history.html', 'Customer could not sign because of limited mobility.'],
    ]) {
        await t.test(filename, async () => {
            const page = await pageFor(filename, 320);
            try {
                await page.evaluate(() => [...document.querySelectorAll('[data-open-proof-history]')].find(a => a.getBoundingClientRect().width > 0).click());
                await page.waitForFunction(() => document.querySelector('#delivery-proof-history').open);
                assert.equal(await page.$eval('#delivery-proof-history', (el, text) => el.innerText.includes(text), text), true);
                assert.equal(await page.$eval('#delivery-proof-history', el => el.querySelectorAll('script').length), 0);
                assert.deepEqual(page.signerDialogs, []);
                if (filename.includes('unavailable')) assert.equal(await page.$eval('#delivery-proof-history', el => el.innerText.includes('Signed by:')), false);
                await screenshot(page, filename.replace('.html', ''));
            } finally { await page.close(); }
        });
    }
    for (const asset of expectedAssets) {
        assert.equal(assets.get(asset)?.status, 200, `Real built asset must load: ${asset}`);
        assert.match(assets.get(asset).type, asset.endsWith('.css') ? /text\/css/ : /javascript/);
    }
    assert.deepEqual(writes, [], 'No non-GET request may leave the synthetic browser.');
    assert.deepEqual(errors, [], 'No browser or ResizeObserver errors are expected.');
});
