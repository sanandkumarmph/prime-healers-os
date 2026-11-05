const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const { test } = require('node:test');
const puppeteer = require('puppeteer');

const folder = process.env.PHOS_PROSPECT_RENDER_DIR;

test('a customer can sign the rental quotation with touch or a mouse', { skip: !folder, timeout: 60000 }, async () => {
    const html = fs.readFileSync(path.join(folder, 'rental-signing.html'), 'utf8');
    const script = fs.readFileSync(path.resolve(__dirname, '../../public/js/phos-rental-agreement.js'));
    let origin;
    const server = http.createServer((request, response) => {
        if (request.url === '/js/phos-rental-agreement.js') {
            response.writeHead(200, { 'Content-Type': 'text/javascript' }).end(script);
        } else {
            response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' })
                .end(html.replace(/http:\/\/127\.0\.0\.1:\d+|http:\/\/localhost(?::\d+)?/g, origin));
        }
    });

    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    origin = 'http://127.0.0.1:' + server.address().port;
    let browser;
    try {
        browser = await puppeteer.launch({ headless: true });
        const page = await browser.newPage();
        await page.setRequestInterception(true);
        page.on('request', request => request.url().startsWith(origin) ? request.continue() : request.abort());
        for (const width of [320, 390, 1200]) {
            await page.setViewport({ width, height: 850, hasTouch: width < 500, isMobile: width < 500 });
            await page.goto(origin, { waitUntil: 'networkidle0' });
            assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
            await page.type('#rental-signer-name', 'Synthetic signer');
            await page.click('[name=rental_agreement_acknowledged]');
            await page.evaluate(() => {
                const form = document.querySelector('[data-rental-signature]');
                form.addEventListener('submit', event => {
                    event.preventDefault();
                    window.savedSignature = new FormData(form).get('signature_data');
                });
                form.querySelector('canvas').scrollIntoView({ block: 'center' });
            });
            await page.click('[data-rental-signature] [type=submit]');
            assert.equal(await page.$eval('[data-signature-error]', el => el.hidden), false);
            await page.$eval('canvas', canvas => canvas.scrollIntoView({ block: 'center' }));
            const bounds = await (await page.$('canvas')).boundingBox();
            const points = [[.15, .65], [.3, .25], [.5, .7], [.8, .35]]
                .map(([x, y]) => [bounds.x + bounds.width * x, bounds.y + bounds.height * y]);
            if (width < 500) {
                await page.touchscreen.touchStart(...points[0]);
                for (const point of points.slice(1)) await page.touchscreen.touchMove(...point);
                await page.touchscreen.touchEnd();
            } else {
                await page.mouse.move(...points[0]);
                await page.mouse.down();
                for (const point of points.slice(1)) await page.mouse.move(...point);
                await page.mouse.up();
            }
            await page.click('[data-rental-signature] [type=submit]');
            assert.match(await page.evaluate(() => window.savedSignature), /^data:image\/png;base64,/);
            await page.click('[data-clear-signature]');
            await page.click('[data-rental-signature] [type=submit]');
            assert.equal(await page.$eval('[data-signature-error]', el => el.hidden), false);
        }
    } finally {
        await browser?.close();
        await new Promise(resolve => server.close(resolve));
    }
});
