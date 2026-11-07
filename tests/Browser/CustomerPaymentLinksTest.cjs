const fs=require('node:fs'),path=require('node:path'),http=require('node:http');
const {test}=require('node:test'),assert=require('node:assert/strict'),puppeteer=require('puppeteer');
const folder=process.env.PHOS_PAYMENT_PREVIEW_DIR;
test('payment settings and WhatsApp sharing remain readable on phones and desktop',{skip:!folder,timeout:90000},async()=>{
    const root=path.resolve(__dirname,'../../public'),report={checks:[],errors:[],writes:[]};
    let origin,browser;
    const server=http.createServer((req,res)=>{
        if(req.method!=='GET'){report.writes.push(req.method);res.writeHead(405).end();return;}
        const url=new URL(req.url,origin);
        if(url.pathname.endsWith('/payment-status')){
            const html=fs.readFileSync(path.join(folder,'payment-link.html'),'utf8');
            const version=html.match(/data-status-version="([a-f0-9]+)"/)[1];
            res.writeHead(200,{'Content-Type':'application/json'}).end(JSON.stringify({version}));return;
        }
        if(['/link','/settings'].includes(url.pathname)){
            res.writeHead(200,{'Content-Type':'text/html; charset=utf-8'}).end(fs.readFileSync(path.join(folder,url.pathname==='/link'?'payment-link.html':'payment-settings.html'),'utf8').replaceAll('http://127.0.0.1:8000',origin));return;
        }
        const file=path.resolve(root,'.'+url.pathname);
        if(file.startsWith(root+path.sep)&&fs.existsSync(file)&&fs.statSync(file).isFile()){
            res.writeHead(200,{'Content-Type':path.extname(file)==='.css'?'text/css':path.extname(file)==='.js'?'text/javascript':'application/octet-stream'});fs.createReadStream(file).pipe(res);return;
        }
        res.writeHead(200,{'Content-Type':'application/json'}).end(JSON.stringify({data:[],notifications:[],unread_count:0,version:'unchanged'}));
    });
    try{
        await new Promise(r=>server.listen(0,'127.0.0.1',r));origin=`http://127.0.0.1:${server.address().port}`;
        browser=await puppeteer.launch({headless:true});const page=await browser.newPage();
        await page.setRequestInterception(true);page.on('pageerror',e=>report.errors.push(e.message));
        page.on('request',r=>r.url().startsWith(origin+'/')||r.url().startsWith('data:')?r.continue():r.respond({status:200,body:''}));
        for(const screen of ['link','settings']){
            await page.goto(origin+'/'+screen,{waitUntil:'networkidle0'});
            for(const width of [1440,1280,1024,768,480,390,360,320]){
                await page.setViewport({width,height:900});await page.evaluate(()=>new Promise(r=>requestAnimationFrame(r)));
                const layout=await page.evaluate(()=>({width:document.documentElement.scrollWidth,
                    controls:[...document.querySelectorAll('.sw input,.sw select,.sw button,.sw a.sw-btn')].filter(el=>el.getClientRects().length).map(el=>({right:el.getBoundingClientRect().right,left:el.getBoundingClientRect().left}))}));
                assert.ok(layout.width<=width+1,JSON.stringify({screen,width,...layout}));
                assert.ok(layout.controls.every(c=>c.left>=0&&c.right<=width+1),JSON.stringify({screen,width,...layout}));
                report.checks.push({screen,width});
                if([1440,390].includes(width))await page.screenshot({path:path.join(folder,`${screen}-${width}.png`),fullPage:true});
            }
            if(screen==='link'){
                const share=await page.$eval('a[href^="https://wa.me/"]',el=>({href:el.href,rel:el.rel,target:el.target}));
                assert.ok(share.href.startsWith('https://wa.me/919000000000?'));
                const message=new URL(share.href).searchParams.get('text');
                assert.ok(message.includes('INR 118.00')&&message.includes('https://rzp.io/')&&message.includes('Regards,')&&message.includes('UAT test link'));
                assert.ok(share.rel.includes('noopener')&&share.target==='_blank');
                report.checks.push({whatsappMessageVerified:true});
            }else{
                assert.equal(await page.$eval('#gateway-secret',el=>el.type),'password');
                assert.equal(await page.$eval('#gateway-mode',el=>el.options.length),1);
                report.checks.push({testOnlyAndSecretsMasked:true});
            }
        }
        assert.deepEqual(report.errors,[]);assert.deepEqual(report.writes,[]);
        process.stdout.write(`PAYMENT_LINK_BROWSER_CHECKS=${report.checks.length}\n`);
    }finally{if(browser)await browser.close();await new Promise(r=>server.close(r));fs.writeFileSync(path.join(folder,'browser-results.json'),JSON.stringify(report,null,2));}
});
