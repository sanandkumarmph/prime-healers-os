const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { test } = require('node:test');
const puppeteer = require('puppeteer');

// The fixture is the actual Laravel form rendered with synthetic local test data.
const fixture = process.env.PHOS_ROLE_PRESET_HTML;
for (const width of [1280, 390]) {
    test(`standard role presets fill the real form at ${width}px`, { skip: !fixture }, async () => {
        const browser = await puppeteer.launch({ headless: true });
        try {
            const page = await browser.newPage();
            await page.setViewport({ width, height: 900 });
            await page.setRequestInterception(true);
            page.on('request', request => request.abort());
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            await page.setContent(fs.readFileSync(path.resolve(fixture), 'utf8'), { waitUntil: 'domcontentloaded' });
            const keys = await page.$$eval('[data-template-button]', buttons => buttons.map(button => button.dataset.templateButton));
            assert.deepEqual(keys, ['super_admin', 'admin', 'sales', 'service', 'delivery', 'finance', 'custom']);
            const placement = await page.$eval('[data-template-select]', selector => {
                const name = document.querySelector('[data-role-form] [name="name"]');
                const bounds = selector.getBoundingClientRect();
                return {
                    count: document.querySelectorAll('[data-template-select]').length,
                    label: document.querySelector(`label[for="${selector.id}"]`)?.textContent.trim(),
                    beforeName: Boolean(selector.compareDocumentPosition(name) & Node.DOCUMENT_POSITION_FOLLOWING),
                    visible: bounds.width > 0 && bounds.height > 0 && getComputedStyle(selector).visibility !== 'hidden',
                    inDefinition: selector.closest('.rb-panel').querySelector('.rb-panel-title').textContent.trim() === 'Role Definition',
                };
            });
            assert.deepEqual(placement, { count: 1, label: 'Standard role', beforeName: true, visible: true, inDefinition: true });
            for (const key of ['sales', 'service', 'delivery', 'finance', 'super_admin']) {
                await page.select('[data-template-select]', key);
                assert.equal(await page.$eval('[data-template-select]', selector => selector.value), key);
                const state = await page.evaluate(() => ({
                    name: document.querySelector('[data-role-form] [name="name"]').value,
                    slug: document.querySelector('[data-role-form] [name="slug"]').value,
                    selected: [...document.querySelectorAll('[data-special-permission]:checked')].map(box => box.value),
                    modules: [...document.querySelectorAll('[data-permission-checkbox]:checked')].map(box => `${box.dataset.module}.${box.dataset.action}`),
                }));
                assert.equal(state.slug, key);
                assert.ok(state.name);
                if (['sales', 'service', 'finance'].includes(key)) {
                    assert.ok(state.selected.includes('finance.view_dues'));
                    assert.ok(!state.selected.includes('finance.view_revenue'));
                    assert.ok(!state.selected.includes('finance.view_profit'));
                    assert.ok(!state.modules.includes('roles.update'));
                }
                if (key === 'delivery') assert.deepEqual(state.selected, []);
                if (key === 'super_admin') assert.ok(state.selected.includes('finance.view_profit'));
            }
            await page.select('[data-template-select]', 'admin');
            assert.equal(await page.$eval('[data-role-form] [name="slug"]', input => input.value), 'admin_operations');
            await page.$eval('[data-template-button="sales"]', button => button.click());
            assert.equal(await page.$eval('[data-template-select]', selector => selector.value), 'sales');
            assert.deepEqual(errors, []);
            const widthFits = await page.$eval('[data-permission-builder]', root => root.scrollWidth <= root.clientWidth + 2);
            assert.ok(widthFits, 'Role editor must not overflow its available width');
        } finally {
            await browser.close();
        }
    });
}
