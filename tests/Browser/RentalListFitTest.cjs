const fs = require('node:fs'), path = require('node:path'), http = require('node:http');
const assert = require('node:assert/strict');
const {test} = require('node:test');
const puppeteer = require('puppeteer');
const rendered = process.env.PHOS_COMPACT_PREVIEW_DIR;

test('rental information and actions fit without horizontal scrolling', {skip:!rendered, timeout:120000}, async () => {
    const publicRoot = path.resolve(__dirname, '../../public');
    const report = {checks:[], errors:[], writes:[]};
    const mime = {'.css':'text/css', '.js':'text/javascript', '.png':'image/png', '.svg':'image/svg+xml', '.ico':'image/x-icon', '.woff2':'font/woff2'};
    let origin, browser;
    const server = http.createServer((req, res) => {
        if (!['GET','HEAD'].includes(req.method)) {report.writes.push(req.method); res.writeHead(405).end(); return;}
        const url = new URL(req.url, origin);
        if (url.pathname === '/rentals') {
            const filename = url.searchParams.has('reader') ? 'rentals-reader.html' : 'rentals.html';
            const html = fs.readFileSync(path.join(rendered, filename), 'utf8').replaceAll('http://127.0.0.1:8000', origin);
            res.writeHead(200, {'Content-Type':'text/html; charset=utf-8'}).end(html); return;
        }
        const file = path.resolve(publicRoot, '.' + decodeURIComponent(url.pathname));
        if (file.startsWith(publicRoot + path.sep) && fs.existsSync(file) && fs.statSync(file).isFile()) {
            res.writeHead(200, {'Content-Type':mime[path.extname(file)] || 'application/octet-stream'});
            fs.createReadStream(file).pipe(res); return;
        }
        res.writeHead(200, {'Content-Type':'application/json'}).end(JSON.stringify({data:[], notifications:[], unread_count:0}));
    });
    try {
        await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
        origin = `http://127.0.0.1:${server.address().port}`;
        browser = await puppeteer.launch({headless:true});
        const page = await browser.newPage();
        await page.setRequestInterception(true);
        page.on('request', req => req.url().startsWith(origin + '/') || req.url().startsWith('data:') ? req.continue() : req.respond({status:200, body:''}));
        page.on('pageerror', error => report.errors.push(error.message));
        for (const reader of [false, true]) {
            await page.goto(origin + '/rentals' + (reader ? '?reader=1' : ''), {waitUntil:'networkidle0'});
            await page.evaluate(() => {
                const row = document.querySelector('.rental-row');
                row.querySelectorAll('.rn-record-link').forEach(link => {
                    link.textContent = link.closest('.items-col') ? 'Hospital Bed Manual – Kraft Two Function Deluxe with Mattress and Accessories' : 'Anand Harris Aigarni Anand Harris Aigarni';
                });
                for (let i = 0; i < 3; i++) row.parentElement.append(row.cloneNode(true));
            });
            for (const width of [1920,1440,1280,1100,1024,900,820,768,767,480,390,360,320]) {
                await page.setViewport({width, height:1000});
                await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
                const result = await page.evaluate(() => {
                    const wrap = document.querySelector('.table-wrap');
                    const row = document.querySelector('.rental-row');
                    const shown = el => el.getBoundingClientRect().width > 0 && el.getBoundingClientRect().height > 0;
                    const cells = [...row.cells].filter(shown).map(el => ({label:el.dataset.label, width:el.clientWidth, scroll:el.scrollWidth}));
                    return {document:document.documentElement.scrollWidth, list:wrap.clientWidth, listScroll:wrap.scrollWidth,
                        layout:getComputedStyle(row).display, cells, mobile:shown(row.querySelector('.mobile-rental-card'))};
                });
                const context = JSON.stringify({width,reader,...result});
                assert.ok(result.document <= width + 1, context);
                assert.ok(result.listScroll <= result.list + 1, context);
                assert.ok(result.cells.every(cell => cell.scroll <= cell.width + 1), context);
                if (width >= 768) {
                    for (const label of ['Customer','Items','Rental Period','Status','Actions']) assert.ok(result.cells.some(cell => cell.label === label), context);
                    assert.equal(result.mobile, false, context);
                } else assert.equal(result.mobile, true, context);
                report.checks.push({width, reader, layout:result.layout, listWidth:result.list});
                if (!reader && [1440,1280,1024,390].includes(width)) {
                    await page.$eval('.rn-table-shell', el => el.scrollIntoView({block:'start'}));
                    await page.screenshot({path:path.join(rendered, `rental-list-${width}.png`), fullPage:false});
                }
            }
            if (!reader) {
                for (const width of [1440,1024,390]) {
                    await page.setViewport({width,height:1000});
                    const selector = width < 768 ? '.mobile-rental-actions .ops-action-menu' : '.actions-cell .ops-action-menu';
                    const menu = await page.$(selector);
                    await menu.evaluate(el => el.scrollIntoView({block:'center'}));
                    await menu.$eval('summary', el => el.click());
                    await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
                    const bounds = await menu.evaluate(el => {
                        const r = el.querySelector('.ops-action-panel').getBoundingClientRect();
                        return {open:el.open, left:r.left,right:r.right,top:r.top,bottom:r.bottom,width:innerWidth,height:innerHeight};
                    });
                    assert.ok(bounds.open && bounds.left >= 0 && bounds.right <= bounds.width + 1 && bounds.top >= 0 && bounds.bottom <= bounds.height + 1, JSON.stringify({width,...bounds}));
                    await menu.evaluate(el => {el.open = false;});
                    report.checks.push({width,menuFits:true});
                }
            }
        }
        assert.deepEqual(report.errors, []);
        assert.deepEqual(report.writes, []);
        process.stdout.write(`RENTAL_LIST_VIEWPORT_AND_MENU_CHECKS=${report.checks.length}\n`);
    } finally {
        if (browser) await browser.close();
        await new Promise(resolve => server.close(resolve));
        fs.writeFileSync(path.join(rendered, 'rental-list-browser-results.json'), JSON.stringify(report,null,2));
    }
});
