const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const assert = require('node:assert/strict');
const {test} = require('node:test');
const puppeteer = require('puppeteer');

const attentionFolder = process.env.PHOS_SALES_ATTENTION_RENDER_DIR;
const actionFolder = process.env.PHOS_GUIDED_ACTION_RENDER_DIR;
const enabled = attentionFolder && actionFolder;
const widths = [1440, 768, 390, 320];
const phase = process.env.PHOS_GUIDED_BROWSER_PHASE || 'all';

// Fixtures are rendered by the application feature tests; only isolated local pages are served here.
test('Sales attention queues and guided actions are usable on desktop and phones', {skip: !enabled, timeout: 240000}, async () => {
    const publicRoot = path.resolve(__dirname, '../../public');
    const report = {checks: [], errors: [], writes: [], navigations: []};
    let browser, origin, liveState = null;
    const read = (folder, name) => fs.readFileSync(path.join(folder, name), 'utf8');
    const replaceOrigin = text => text.replaceAll('http://127.0.0.1:8000', origin);
    const manifestFile = path.join(attentionFolder,'manifest.json');
    const manifest = fs.existsSync(manifestFile) ? JSON.parse(read(attentionFolder,'manifest.json')) : {};
    const fixtureSpecs = manifest.fixtures || {};
    const fixtureFor = url => {
        const requested=Object.fromEntries([...url.searchParams].filter(([key,value])=>value!==''&&key!=='sort'));
        if(!requested.view)requested.view='attention';if(requested.attention==='all')delete requested.attention;
        return Object.entries(fixtureSpecs).find(([,spec])=>{
            const expected=Object.fromEntries(Object.entries(spec.query||{}).filter(([key,value])=>value!==''&&key!=='sort'));
            if(!expected.view)expected.view='attention';if(expected.attention==='all')delete expected.attention;
            return JSON.stringify(Object.entries(requested).sort())===JSON.stringify(Object.entries(expected).sort());
        })?.[0] || 'attention';
    };
    const server = http.createServer((req, res) => {
        const url = new URL(req.url, origin);
        if (req.method !== 'GET') { report.writes.push({method: req.method, path: url.pathname}); res.writeHead(405).end(); return; }
        if (/^\/quotations\/\d+\/status$/.test(url.pathname) && liveState) {
            res.writeHead(200, {'Content-Type':'application/json', 'Cache-Control':'private, no-store'}).end(replaceOrigin(JSON.stringify(liveState))); return;
        }
        if(url.pathname==='/enquiries'){
            const fixture=fixtureFor(url);report.navigations.push({path:url.pathname,query:Object.fromEntries(url.searchParams),fixture});
            res.writeHead(200,{'Content-Type':'text/html; charset=utf-8'}).end(replaceOrigin(read(attentionFolder,fixture+'.html')));return;
        }
        const match = url.pathname.match(/^\/(attention|action)\/([a-z0-9_-]+)$/);
        if (match) {
            const folder = match[1] === 'attention' ? attentionFolder : actionFolder;
            const file = path.join(folder, match[2] + '.html');
            if (!fs.existsSync(file)) { res.writeHead(404).end('Missing rendered test fixture'); return; }
            res.writeHead(200, {'Content-Type':'text/html; charset=utf-8'}).end(replaceOrigin(read(folder, match[2]+'.html'))); return;
        }
        const file = path.resolve(publicRoot, '.' + decodeURIComponent(url.pathname));
        if (file.startsWith(publicRoot + path.sep) && fs.existsSync(file) && fs.statSync(file).isFile()) {
            res.writeHead(200, {'Content-Type':({'.js':'text/javascript','.css':'text/css','.svg':'image/svg+xml'})[path.extname(file)] || 'application/octet-stream'});
            fs.createReadStream(file).pipe(res); return;
        }
        res.writeHead(200, {'Content-Type':'application/json'}).end(JSON.stringify({data:[],notifications:[],unread_count:0}));
    });
    const visible = async (page, selector) => page.$$eval(selector, elements => elements.filter(element => {
        if (element.closest('[hidden],[aria-hidden="true"]') || !element.getClientRects().length || getComputedStyle(element).visibility === 'hidden') return false;
        for (let p=element.parentElement;p;p=p.parentElement) if (p.tagName === 'DETAILS' && !p.open && !p.querySelector(':scope > summary')?.contains(element)) return false;
        return true;
    }).map(element => ({text:element.textContent.trim(),href:element.getAttribute('href'),height:element.getBoundingClientRect().height})));
    const prepare = async (width, url) => {
        const page = await browser.newPage(); await page.setViewport({width,height:1000});
        page.on('pageerror', error => report.errors.push(error.message));
        await page.setRequestInterception(true);
        page.on('request', request => request.url().startsWith(origin+'/') || request.url().startsWith('data:') ? request.continue() : request.respond({status:200,body:''}));
        await page.goto(origin+url,{waitUntil:'networkidle0'}); return page;
    };
    try {
        await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve)); origin=`http://127.0.0.1:${server.address().port}`;
        browser=await puppeteer.launch({headless:true});
        const attentionFixtures = fs.readdirSync(attentionFolder).filter(name=>name.endsWith('.html')).map(name=>name.slice(0,-5));
        assert.ok(attentionFixtures.includes('attention'),'Main attention fixture must come from the real controller.');
        for (const width of (phase==='hashes'?[]:widths)) for (const fixture of (phase==='visual'?['attention']:attentionFixtures)) {
            const page=await prepare(width,'/attention/'+fixture);
            assert.ok(await page.$('[data-sales-workspace-index]'),'Queue root available');
            assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Attention page fits '+width+' '+fixture);
            const rows=await page.$$eval('[data-attention-row]',nodes=>nodes.map(row=>({
                id:row.dataset.enquiryId, text:row.textContent, reason:row.querySelector('[data-attention-reason]')?.textContent.trim(),
                actions:[...row.querySelectorAll('[data-attention-action]')].map(a=>({text:a.textContent.trim(),href:a.getAttribute('href'),height:a.getBoundingClientRect().height})),
                cells:[...row.querySelectorAll('td')].map(cell=>({width:cell.getBoundingClientRect().width,overflow:cell.scrollWidth>cell.clientWidth+2}))
            })));
            assert.equal(new Set(rows.map(row=>row.id)).size,rows.length,'Each enquiry appears once');
            if(fixtureSpecs[fixture]?.ids)assert.deepEqual(rows.map(row=>Number(row.id)),fixtureSpecs[fixture].ids,'Real controller row identities');
            for(const row of rows){
                assert.equal(row.actions.length,1,'One next action per queue record '+row.id);
                assert.ok(row.reason && row.reason.length>4,'Clear reason for attention '+row.id);
                assert.ok(row.actions[0].text.length>2); assert.ok(row.actions[0].href && row.actions[0].href!=='#');
                assert.ok(row.actions[0].height>=(width<=600?36:30),'Action remains easy to activate');
                assert.equal(row.cells.some(cell=>cell.overflow),false,'Readable cells '+width+' '+fixture+' '+row.id);
            }
            if(width<=600) assert.ok(await page.$$eval('#sales-workspace-filters input:not([type=hidden]):not([type=checkbox]),#sales-workspace-filters select',nodes=>nodes.every(e=>parseFloat(getComputedStyle(e).fontSize)>=16)),'Mobile filters use readable text');
            if(fixture==='attention'&&[1440,390].includes(width))await page.screenshot({path:path.join(attentionFolder,'attention-'+width+'.png'),fullPage:true});
            report.checks.push({type:'attention',width,fixture,rows:rows.length}); await page.close();
        }
        for(const width of (phase==='all'?[1440,390]:[])){
            const page=await prepare(width,'/attention/attention');
            const categorySpec=Object.entries(fixtureSpecs).find(([,spec])=>spec.query?.attention==='ready');
            if(categorySpec){
                await page.select('#sales-workspace-filters select[name=attention]','ready');
                await Promise.all([page.waitForNavigation({waitUntil:'networkidle0'}),page.$eval('#sales-workspace-filters button[type=submit]',button=>button.click())]);
                assert.equal(new URL(page.url()).searchParams.get('attention'),'ready');
                assert.deepEqual(await page.$$eval('[data-attention-row]',nodes=>nodes.map(row=>Number(row.dataset.enquiryId))),categorySpec[1].ids);
                assert.equal(await page.$eval('select[name=attention]',e=>e.value),'ready');
                report.checks.push({type:'categoryGetForm',width});
            }
            const searchSpec=fixtureSpecs.search;
            if(searchSpec?.query?.q){
                await page.goto(origin+'/attention/attention',{waitUntil:'networkidle0'});
                await page.type('#sales-workspace-filters input[name=q]',searchSpec.query.q);
                await Promise.all([page.waitForNavigation({waitUntil:'networkidle0'}),page.$eval('#sales-workspace-filters button[type=submit]',button=>button.click())]);
                assert.equal(new URL(page.url()).searchParams.get('q'),searchSpec.query.q);
                assert.deepEqual(await page.$$eval('[data-attention-row]',nodes=>nodes.map(row=>Number(row.dataset.enquiryId))),searchSpec.ids);
                report.checks.push({type:'searchGetForm',width});
            }
            await page.close();
        }
        const actionFixtures = ['noquote','draft','unsigned','signed','ready','lost','readonly',
            ...['quote-unsigned','quote-ready','quote-signed'].filter(name=>fs.existsSync(path.join(actionFolder,name+'.html')))];
        for(const width of (phase==='hashes'?[]:widths)) for(const fixture of (phase==='visual'?['ready']:actionFixtures)) {
            liveState=null;const page=await prepare(width,'/action/'+fixture);
            const rootSelector=await page.$('[data-enquiry-detail]')?'[data-enquiry-detail]':'[data-quote-live]';
            assert.ok(await page.$(rootSelector));
            assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Record page fits '+width+' '+fixture);
            const primary=await visible(page,rootSelector+' .sw-primary');
            assert.ok(await page.$eval('[data-sales-next-action]',element=>parseFloat(getComputedStyle(element).paddingLeft)>=10),'Next-action panel has comfortable inner spacing');
            assert.equal(primary.length,['lost','readonly'].includes(fixture)?0:1,'One clear rendered primary action '+fixture+' '+JSON.stringify(primary));
            if(primary.length)assert.ok(primary[0].height>=(width<=600?38:32),'Primary action touch target');
            if(fixture==='ready')assert.match(primary[0].text,/Create Rental/i);
            if(fixture==='signed')assert.match(primary[0].text,/Record quotation response/i);
            if(['unsigned','signed','draft'].includes(fixture))assert.equal(primary.some(a=>/Create Rental/i.test(a.text)),false,'Terms and acceptance remain distinct');
            if(fixture==='readonly')assert.equal(await page.$$eval(rootSelector+' form',nodes=>nodes.length),0,'Read-only page has no mutation form');
            if(fixture==='ready'&&[1440,390].includes(width))await page.screenshot({path:path.join(actionFolder,'guided-ready-'+width+'.png'),fullPage:true});
            const disclosure=await page.$('[data-sales-section]:not([open])');
            if(disclosure){
                await disclosure.$eval(':scope > summary',summary=>summary.focus());await page.keyboard.press('Enter');
                assert.equal(await disclosure.evaluate(element=>element.open),true,'Completed and secondary sections remain keyboard accessible');
                await disclosure.evaluate(element=>element.open=false);
            }
            if(fixture==='signed'){
                await page.$eval('[data-sales-current-action]',element=>element.scrollIntoView({block:'center'}));
                await page.click('[data-sales-current-action]');
                assert.equal(await page.$eval('#enquiry-response',element=>element.open),true,'Next action opens the relevant response section');
                assert.ok(await page.$eval('#enquiry-response',element=>element.contains(document.activeElement)),'Next action puts keyboard focus in the active section');
            }
            report.checks.push({type:'action',width,fixture,primary:primary[0]?.text||null}); await page.close();
        }
        for(const width of [1440,390,320])for(const fixture of ['unsigned','quote-unsigned']){
            const page=await prepare(width,'/action/'+fixture+'#enquiry-response');
            await page.waitForFunction(()=>document.getElementById('enquiry-response')?.open===true);
            assert.equal(await page.$eval('#enquiry-response',element=>element.open),true,'Initial workflow hash reveals the outer response section');
            await page.$eval('#rental-agreement',element=>element.open=false);
            await page.evaluate(()=>location.hash='rental-agreement');
            await page.waitForFunction(()=>document.getElementById('rental-agreement')?.open===true);
            assert.equal(await page.$eval('#rental-agreement',element=>element.open),true,'Changed workflow hash reveals the terms section');
            assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
            report.checks.push({type:'initialAndChangedHash',width,fixture});await page.close();
        }
        assert.deepEqual(report.errors,[]);assert.deepEqual(report.writes,[]);
        process.stdout.write('SALES_ATTENTION_AND_GUIDED_ACTION_BROWSER_CHECKS='+report.checks.length+' ERRORS=0 MUTATIONS=0\n');
    } finally {
        if(browser)await browser.close();await new Promise(resolve=>server.close(resolve));
        fs.writeFileSync(path.join(attentionFolder,'guided-browser-results'+(phase==='all'?'':'-'+phase)+'.json'),JSON.stringify(report,null,2));
    }
});
