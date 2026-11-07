const fs=require('node:fs'),path=require('node:path'),http=require('node:http');
const assert=require('node:assert/strict'),{test}=require('node:test'),puppeteer=require('puppeteer');
const folder=process.env.PHOS_CUSTOMER_MENU_RENDER_DIR;
test('open customer action menu stays above neighboring row buttons',{skip:!folder,timeout:90000},async()=>{
    const root=path.resolve(__dirname,'../../public'),report={checks:[],errors:[],writes:[]};let origin,browser;
    const server=http.createServer((req,res)=>{
        if(req.method!=='GET'){report.writes.push(req.method);res.writeHead(405).end();return;}
        const url=new URL(req.url,origin);
        if(url.pathname==='/customers'){
            const role=url.searchParams.get('role')==='sales'?'sales':'super_admin';
            res.writeHead(200,{'Content-Type':'text/html; charset=utf-8'}).end(fs.readFileSync(path.join(folder,role+'.html'),'utf8').replaceAll('http://127.0.0.1:8000',origin));return;
        }
        const file=path.resolve(root,'.'+url.pathname);
        if(file.startsWith(root+path.sep)&&fs.existsSync(file)&&fs.statSync(file).isFile()){
            res.writeHead(200,{'Content-Type':path.extname(file)==='.css'?'text/css':path.extname(file)==='.js'?'text/javascript':'application/octet-stream'});fs.createReadStream(file).pipe(res);return;
        }
        res.writeHead(200,{'Content-Type':'application/json'}).end(JSON.stringify({data:[],notifications:[],unread_count:0}));
    });
    try{
        await new Promise(r=>server.listen(0,'127.0.0.1',r));origin=`http://127.0.0.1:${server.address().port}`;
        browser=await puppeteer.launch({headless:true});const page=await browser.newPage();
        await page.setRequestInterception(true);page.on('pageerror',e=>report.errors.push(e.message));
        page.on('request',r=>r.url().startsWith(origin+'/')||r.url().startsWith('data:')?r.continue():r.respond({status:200,body:''}));
        for(const role of ['super_admin','sales'])for(const width of [1560,1280,1024,390,320]){
            await page.setViewport({width,height:1000});await page.goto(origin+'/customers?role='+role,{waitUntil:'networkidle0'});
            const parent=width>=768?'.customers-table tbody tr':'.customer-mobile-card';
            const selector=parent+' .customer-action-menu';
            await page.$eval(selector,el=>el.scrollIntoView({block:'center'}));
            await page.click(selector+' > summary');await page.evaluate(()=>new Promise(r=>setTimeout(r,180)));
            const overlaps=await page.evaluate(selector=>{
                const menus=[...document.querySelectorAll(selector)],active=menus[0].querySelector('.customer-action-panel'),p=active.getBoundingClientRect();
                return menus.slice(1).map(menu=>menu.querySelector('summary').getBoundingClientRect()).map(r=>({
                    x:(Math.max(r.left,p.left)+Math.min(r.right,p.right))/2,
                    y:(Math.max(r.top,p.top)+Math.min(r.bottom,p.bottom))/2,
                    overlaps:r.right>p.left&&r.left<p.right&&r.bottom>p.top&&r.top<p.bottom
                })).filter(r=>r.overlaps&&r.x>0&&r.x<innerWidth&&r.y>0&&r.y<innerHeight);
            },selector);
            if(width>=768)assert.ok(overlaps.length>0,JSON.stringify({width,role,overlaps}));
            for(const point of overlaps){
                await page.mouse.move(point.x,point.y);await page.evaluate(()=>new Promise(r=>requestAnimationFrame(r)));
                const hit=await page.evaluate(({selector,point})=>document.querySelector(selector+' .customer-action-panel').contains(document.elementFromPoint(point.x,point.y)),{selector,point});
                assert.ok(hit,JSON.stringify({width,role,point,error:'A neighboring row button covers the open customer menu'}));
            }
            if(role==='super_admin'&&[1560,390].includes(width))await page.screenshot({path:path.join(folder,'customer-menu-'+width+'.png')});
            await page.keyboard.press('Escape');
            await page.waitForFunction(s=>!document.querySelector(s).open,{},selector);
            const second=await page.$$(selector);await second[1].$eval('summary',el=>el.click());
            await page.waitForFunction(s=>[...document.querySelectorAll(s)].filter(el=>el.open).length===1,{},selector);
            await page.mouse.click(width/2,110);await page.waitForFunction(s=>![...document.querySelectorAll(s)].some(el=>el.open),{},selector);
            report.checks.push({width,role,coveredNeighborButtons:overlaps.length,escapeAndOutsideDismiss:true});
        }
        assert.deepEqual(report.errors,[]);assert.deepEqual(report.writes,[]);
        process.stdout.write('CUSTOMER_ACTION_MENU_CHECKS='+report.checks.length+'\n');
    }finally{if(browser)await browser.close();await new Promise(r=>server.close(r));fs.writeFileSync(path.join(folder,'browser-results.json'),JSON.stringify(report,null,2));}
});
