const fs = require('node:fs'), path = require('node:path'), http = require('node:http');
const assert = require('node:assert/strict');
const {test} = require('node:test');
const puppeteer = require('puppeteer');
const rendered = process.env.PHOS_ACTION_TIMESTAMPS_RENDER_DIR;

test('actual record clocks remain readable on phones and do not follow the browser timezone', {skip:!rendered, timeout:180000}, async () => {
    const fixtures = JSON.parse(fs.readFileSync(path.join(rendered, 'action-fixtures.json')));
    const publicRoot = path.resolve(__dirname, '../../public');
    const mime = {'.css':'text/css', '.js':'text/javascript', '.png':'image/png', '.svg':'image/svg+xml', '.woff2':'font/woff2', '.ico':'image/x-icon'};
    const report = {checks:[], errors:[], writes:[]};
    let origin, browser;
    const server = http.createServer((req,res) => {
        if (!['GET','HEAD'].includes(req.method)) {report.writes.push(req.method); res.writeHead(405).end(); return;}
        const url = new URL(req.url, origin);
        const fixture = fixtures.find(f => new URL(f.url).pathname === url.pathname);
        if (fixture) {
            let html = fs.readFileSync(path.join(rendered, fixture.file), 'utf8');
            for (const source of fixtures.map(f => new URL(f.url).origin)) html = html.replaceAll(source, origin);
            res.writeHead(200, {'Content-Type':'text/html; charset=utf-8'}).end(html); return;
        }
        const file = path.resolve(publicRoot, '.'+decodeURIComponent(url.pathname));
        if (file.startsWith(publicRoot+path.sep) && fs.existsSync(file) && fs.statSync(file).isFile()) {
            res.writeHead(200, {'Content-Type':mime[path.extname(file)] || 'application/octet-stream'}); fs.createReadStream(file).pipe(res); return;
        }
        res.writeHead(200, {'Content-Type':'application/json'}).end(JSON.stringify({data:[], notifications:[], unread_count:0}));
    });
    try {
        await new Promise(r => server.listen(0,'127.0.0.1',r));
        origin = `http://127.0.0.1:${server.address().port}`;
        browser = await puppeteer.launch({headless:true});
        for (const fixture of fixtures) {
            const page = await browser.newPage();
            await page.setRequestInterception(true);
            page.on('request',req => req.url().startsWith(origin+'/') || req.url().startsWith('data:') ? req.continue() : req.respond({status:200,contentType:'text/css',body:''}));
            page.on('pageerror', e => report.errors.push({module:fixture.module, message:e.message}));
            for (const timezone of ['America/Los_Angeles','Asia/Tokyo']) {
                await page.emulateTimezone(timezone);
                await page.goto(origin+new URL(fixture.url).pathname, {waitUntil:'networkidle0'});
                for (const width of [1440,768,390,320]) {
                    await page.setViewport({width,height:900});
                    await page.$eval('[data-record-timestamps]', element => element.scrollIntoView({block:'center'}));
                    const result = await page.$eval('[data-record-timestamps]', section => {
                        const box=section.getBoundingClientRect();
                        const clocks=[...section.querySelectorAll('time')].map(el => {
                            const r=el.getBoundingClientRect();
                            return {text:el.textContent, iso:el.dateTime, fits:r.left>=box.left && r.right<=box.right+1, size:parseFloat(getComputedStyle(el).fontSize)};
                        });
                        return {clocks, fits:section.scrollWidth<=section.clientWidth+1, width:box.width};
                    });
                    assert.ok(result.fits && result.clocks.length>=2, JSON.stringify({fixture,width,timezone,result}));
                    assert.ok(result.clocks.every(c=>c.fits && c.size>=11 && c.text.includes('IST') && c.iso.endsWith('+05:30')), JSON.stringify(result));
                    assert.ok(result.clocks[0].text.includes('05:18:19 PM IST'), JSON.stringify(result));
                    report.checks.push({module:fixture.module,width,timezone,pass:true});
                    if (fixture.module==='customers' && timezone==='America/Los_Angeles' && [390,1440].includes(width)) {
                        await (await page.$('[data-record-timestamps]')).screenshot({path:path.join(rendered,`timestamp-${width}.png`)});
                    }
                }
            }
            await page.close();
        }
        assert.deepEqual(report.errors,[]);
        assert.deepEqual(report.writes,[]);
    } finally {
        if (browser) await browser.close();
        await new Promise(r=>server.close(r));
        fs.writeFileSync(path.join(rendered,'timestamp-browser-results.json'),JSON.stringify(report,null,2));
    }
    process.stdout.write(`Timestamp browser checks passed: ${report.checks.length}\n`);
});
