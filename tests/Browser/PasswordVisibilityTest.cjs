const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const { test } = require('node:test');
const puppeteer = require('puppeteer');

test('password visibility preserves inputs, supports keyboard and fits a narrow phone', async () => {
    const repository = path.resolve(__dirname, '../..');
    const html = execFileSync(process.env.PHOS_TEST_PHP || 'php', ['-r', `
        require 'vendor/autoload.php'; $app=require 'bootstrap/app.php';
        $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
        echo Illuminate\\Support\\Facades\\Blade::render(
            '<form><x-password-input id="password" name="password" required />'
            .'<x-password-input id="password_confirmation" name="password_confirmation" autocomplete="new-password" required /></form>');
    `], { cwd: repository, encoding: 'utf8', env: { ...process.env,
        APP_ENV: 'testing', CACHE_STORE: 'array', SESSION_DRIVER: 'array',
        DB_CONNECTION: 'sqlite', DB_DATABASE: ':memory:' } });
    const browser = await puppeteer.launch({ headless: true, args: ['--no-sandbox'] });
    try {
        const page = await browser.newPage();
        await page.setViewport({ width: 320, height: 640 });
        await page.setContent(`<style>input{box-sizing:border-box;width:100%;height:56px}form{margin:16px}</style>${html}`);
        await page.evaluate(() => {
            window.submissions = 0;
            document.querySelector('form').addEventListener('submit', event => { event.preventDefault(); window.submissions++; });
        });
        await page.addScriptTag({ content: fs.readFileSync(require.resolve('alpinejs/dist/cdn.min.js'), 'utf8') });
        await page.waitForFunction(() => !!document.querySelector('[x-data]')._x_dataStack);
        const field = '#password';
        const toggle = 'button[aria-controls="password"]';
        assert.equal(await page.$eval(field, el => el.type), 'password');
        assert.equal(await page.$eval(field, el => el.autocomplete), 'current-password');
        await page.type(field, 'Synthetic-visibility-test-42');
        await page.click(toggle);
        assert.equal(await page.$eval(field, el => el.type), 'text');
        assert.equal(await page.$eval(toggle, el => el.getAttribute('aria-label')), 'Hide password');
        assert.equal(await page.$eval(toggle, el => el.getAttribute('aria-pressed')), 'true');
        assert.equal(await page.$eval('#password_confirmation', el => el.type), 'password');
        await page.click(toggle);
        assert.equal(await page.$eval(field, el => el.type), 'password');
        await page.focus(toggle);
        await page.keyboard.press('Space');
        assert.equal(await page.$eval(field, el => el.type), 'text');
        await page.keyboard.press('Escape');
        assert.equal(await page.$eval(field, el => el.type), 'password');
        assert.equal(await page.$eval(field, el => el.value), 'Synthetic-visibility-test-42');
        assert.equal(await page.evaluate(() => window.submissions), 0);
        assert.equal(await page.$eval('#password_confirmation', el => el.autocomplete), 'new-password');
        assert.ok(await page.$eval(toggle, el => {
            const button = el.getBoundingClientRect(), input = document.getElementById(el.getAttribute('aria-controls')).getBoundingClientRect();
            return button.width >= 44 && button.height >= 44 && button.left >= input.left && button.right <= input.right;
        }));
    } finally {
        await browser.close();
    }
});
