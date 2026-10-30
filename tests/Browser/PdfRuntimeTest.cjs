const assert = require('node:assert/strict');
const { test } = require('node:test');
const puppeteer = require('puppeteer');

test('the supported Node runtime renders a synthetic PDF without page network requests', async () => {
    const browser = await puppeteer.launch({
        headless: true,
        args: ['--disable-background-networking', '--no-first-run', ...(process.env.CI ? ['--no-sandbox'] : [])],
    });
    try {
        const page = await browser.newPage();
        const requests = [], errors = [];
        await page.setRequestInterception(true);
        page.on('request', request => {
            requests.push(request.url());
            return request.abort();
        });
        page.on('pageerror', error => errors.push(error.message));
        await page.setContent('<!doctype html><html lang="en"><meta charset="utf-8"><title>PHOS PDF runtime check</title><body><h1>PHOS PDF runtime check</h1><p>Synthetic content only.</p></body></html>');
        const pdf = Buffer.from(await page.pdf({ format: 'A4', timeout: 30000 }));
        assert.equal(pdf.subarray(0, 5).toString(), '%PDF-');
        assert.ok(pdf.length > 1000, 'A populated PDF must be produced');
        assert.deepEqual(requests, []);
        assert.deepEqual(errors, []);
    } finally {
        await browser.close();
    }
});
