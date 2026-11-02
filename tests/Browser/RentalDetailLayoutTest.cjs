const fs = require('node:fs'), path = require('node:path'), http = require('node:http');
const assert = require('node:assert/strict');
const {test} = require('node:test');
const puppeteer = require('puppeteer');
const rendered = process.env.PHOS_RENTAL_DETAIL_RENDER_DIR;

test('rendered rental overview keeps activity readable and uses its available width', {skip: !rendered, timeout: 120000}, async () => {
    const fixture = JSON.parse(fs.readFileSync(path.join(rendered, 'fixture.json')));
    const publicRoot = path.resolve(__dirname, '../../public');
    const report = {checks: [], errors: [], writes: []};
    const mime = {'.css': 'text/css', '.js': 'text/javascript', '.svg': 'image/svg+xml', '.png': 'image/png', '.woff2': 'font/woff2'};
    let origin, browser;
    const server = http.createServer((req, res) => {
        if (!['GET', 'HEAD'].includes(req.method)) {report.writes.push(req.method); res.writeHead(405).end(); return;}
        const url = new URL(req.url, origin);
        if (url.pathname === new URL(fixture.url).pathname) {
            const file = url.searchParams.has('empty') ? 'rental-empty.html' : 'rental.html';
            const html = fs.readFileSync(path.join(rendered, file), 'utf8').replaceAll(new URL(fixture.url).origin, origin);
            res.writeHead(200, {'Content-Type': 'text/html; charset=utf-8'}).end(html); return;
        }
        const file = path.resolve(publicRoot, '.' + decodeURIComponent(url.pathname));
        if (file.startsWith(publicRoot + path.sep) && fs.existsSync(file) && fs.statSync(file).isFile()) {
            res.writeHead(200, {'Content-Type': mime[path.extname(file)] || 'application/octet-stream'});
            fs.createReadStream(file).pipe(res); return;
        }
        res.writeHead(200, {'Content-Type': 'application/json'}).end(JSON.stringify({data: [], notifications: [], unread_count: 0}));
    });
    try {
        await new Promise(r => server.listen(0, '127.0.0.1', r));
        origin = `http://127.0.0.1:${server.address().port}`;
        browser = await puppeteer.launch({headless: true});
        const page = await browser.newPage();
        await page.setRequestInterception(true);
        page.on('request', req => req.url().startsWith(origin + '/') || req.url().startsWith('data:') ? req.continue() : req.respond({status: 200, contentType: 'text/css', body: ''}));
        page.on('pageerror', e => report.errors.push(e.message));
        for (const empty of [false, true]) {
            await page.goto(origin + new URL(fixture.url).pathname + (empty ? '?empty=1' : ''), {waitUntil: 'networkidle0'});
            for (const width of [1920, 1440, 1280, 1024, 900, 820, 768, 767, 390, 360, 320]) {
                await page.setViewport({width, height: 1000});
                if (width < 768) await page.evaluate(() => {
                    const accordion = [...document.querySelectorAll('.ph-rental-mobile-accordion')].find(el => el.querySelector('summary')?.textContent.includes('Activity'));
                    accordion.open = true;
                    accordion.scrollIntoView({block: 'center'});
                });
                else await page.$eval('#rental-overview-section', el => el.scrollIntoView({block: 'center'}));
                const result = await page.evaluate(() => {
                    const grid = document.querySelector('#rental-overview-section');
                    const box = el => {const r = el.getBoundingClientRect(); return {left:r.left, right:r.right, top:r.top, bottom:r.bottom, width:r.width, height:r.height};};
                    const shown = el => el.getBoundingClientRect().width > 0 && el.getBoundingClientRect().height > 0;
                    const cards = [...grid.querySelectorAll('article')].map(box);
                    const rows = [...document.querySelectorAll('.rental-activity-mini-row')].filter(shown).map(el => {
                        const title = el.querySelector('strong'), clock = el.querySelector('time'), copy = el.querySelector('.rental-activity-mini-copy');
                        return {row:box(el), copy:box(copy), title:box(title), clock:box(clock), text:clock.textContent,
                            iso:clock.dateTime, fits:el.scrollWidth <= el.clientWidth + 1};
                    });
                    return {documentWidth:document.documentElement.scrollWidth, grid:box(grid), cards,
                        columns:getComputedStyle(grid).gridTemplateColumns.split(' ').filter(value => parseFloat(value) > 0).length, rows,
                        buttons:[...grid.querySelectorAll('a')].map(el => ({...box(el), fits:el.scrollWidth <= el.clientWidth + 1}))};
                });
                const context = JSON.stringify({width, empty, result});
                assert.ok(result.documentWidth <= width + 1, context);
                if (width >= 768) {
                    assert.equal(result.cards.length, 3, context);
                    assert.ok(result.cards.every(c => c.height < 550 && c.left >= result.grid.left && c.right <= result.grid.right), context);
                    assert.ok(Math.abs(result.cards[0].left - result.grid.left - 10) <= 2, context);
                    const farRight = Math.max(...result.cards.map(c => c.right));
                    assert.ok(Math.abs(result.grid.right - farRight - 10) <= 2, context);
                    if (width >= 1440) assert.equal(result.columns, 3, context);
                    assert.ok(result.buttons.every(b => b.fits), context);
                }
                assert.equal(result.rows.length, empty ? 0 : 4, context);
                for (const row of result.rows) {
                    assert.ok(row.fits && row.copy.width >= 150 && row.row.height < 130, context);
                    assert.ok(row.clock.top >= row.title.bottom && row.clock.right <= row.row.right + 1, context);
                    assert.ok(row.text.includes('05:18:19 PM IST') && row.iso.endsWith('+05:30'), context);
                }
                report.checks.push({width, empty, columns:result.columns, activityRows:result.rows.length});
                if (!empty && [1440,1024,390].includes(width)) {
                    const target = width < 768 ? await page.evaluateHandle(() => [...document.querySelectorAll('.ph-rental-mobile-accordion')].find(el => el.querySelector('summary')?.textContent.includes('Activity'))) : await page.$('#rental-workspace-shell');
                    await target.screenshot({path:path.join(rendered, `rental-detail-${width}.png`)});
                }
            }
        }
        assert.deepEqual(report.errors, []);
        assert.deepEqual(report.writes, []);
    } finally {
        if (browser) await browser.close();
        await new Promise(r => server.close(r));
        fs.writeFileSync(path.join(rendered, 'rental-detail-browser-results.json'), JSON.stringify(report, null, 2));
    }
});
