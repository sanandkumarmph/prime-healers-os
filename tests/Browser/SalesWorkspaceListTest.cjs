const fs=require('node:fs'),path=require('node:path'),http=require('node:http');
const assert=require('node:assert/strict'),{test}=require('node:test'),puppeteer=require('puppeteer');
const folder=process.env.PHOS_SALES_LIST_RENDER_DIR;

test('sales queues remain compact and readable across desktop and phone layouts',{skip:!folder,timeout:180000},async()=>{
 const root=path.resolve(__dirname,'../../public'),report={checks:[],errors:[],writes:[]};let browser,origin;
 const server=http.createServer((req,res)=>{
  if(req.method!=='GET'){report.writes.push(req.method);res.writeHead(405).end();return;}
  const url=new URL(req.url,origin),fixture=url.pathname.slice(1);
  if(['enquiries','followups','quotations','empty','filtered','sales'].includes(fixture)){
   res.writeHead(200,{'Content-Type':'text/html; charset=utf-8'}).end(fs.readFileSync(path.join(folder,fixture+'.html'),'utf8').replaceAll('http://127.0.0.1:8000',origin));return;
  }
  const file=path.resolve(root,'.'+decodeURIComponent(url.pathname));
  if(file.startsWith(root+path.sep)&&fs.existsSync(file)&&fs.statSync(file).isFile()){
   res.writeHead(200,{'Content-Type':({'.js':'text/javascript','.css':'text/css','.svg':'image/svg+xml'})[path.extname(file)]||'application/octet-stream'});fs.createReadStream(file).pipe(res);return;
  }
  res.writeHead(200,{'Content-Type':'application/json'}).end(JSON.stringify({data:[],notifications:[],unread_count:0}));
 });
 try{
  await new Promise(r=>server.listen(0,'127.0.0.1',r));origin=`http://127.0.0.1:${server.address().port}`;
  browser=await puppeteer.launch({headless:true});const page=await browser.newPage();
  page.on('pageerror',e=>report.errors.push(e.message));await page.setRequestInterception(true);
  page.on('request',r=>r.url().startsWith(origin+'/')||r.url().startsWith('data:')?r.continue():r.respond({status:200,body:''}));
  for(const width of [1440,1280,1024,850,768,480,390,360,320])for(const fixture of ['enquiries','followups','quotations','empty','filtered','sales']){
   await page.setViewport({width,height:1000});await page.goto(origin+'/'+fixture,{waitUntil:'networkidle0'});
   const layout=await page.evaluate(()=>{
    const root=document.querySelector('[data-sales-workspace-index]'),rect=root.getBoundingClientRect();
    const metrics=[...root.querySelectorAll('.swx-metric')].map(e=>{const r=e.getBoundingClientRect();return {width:r.width,height:r.height,top:r.top};});
    const rows=[...root.querySelectorAll('.swx-record')].map(row=>{
     const rect=row.getBoundingClientRect(),serial=row.querySelector('.swx-num'),created=row.querySelector('time');
     return {width:rect.width,height:rect.height,serial:serial.textContent.trim(),date:created?.dateTime,
      contactWidth:row.querySelector('.swx-contact').getBoundingClientRect().width,
      fieldOverflow:[...row.querySelectorAll('td')].some(cell=>cell.scrollWidth>cell.clientWidth+2)};
    });
    return {viewport:innerWidth,pageWidth:document.documentElement.scrollWidth,rootWidth:rect.width,
     tableWidth:root.querySelector('.swx-table').getBoundingClientRect().width,metrics,rows,
     firstRowTop:root.querySelector('.swx-record')?.getBoundingClientRect().top,
     headerHeight:root.querySelector('.swx-header').getBoundingClientRect().height};
   });
   assert.ok(layout.pageWidth<=width+1,JSON.stringify({width,fixture,layout}));
   assert.ok(layout.tableWidth<=layout.rootWidth+1,JSON.stringify({width,fixture,layout}));
   assert.ok(Math.max(...layout.metrics.map(x=>x.width))-Math.min(...layout.metrics.map(x=>x.width))<2);
   assert.ok(Math.max(...layout.metrics.map(x=>x.height))-Math.min(...layout.metrics.map(x=>x.height))<2);
   assert.ok(layout.headerHeight<100,'Compact header at '+width);
   const checkbox=await page.$eval('#sw-mine',e=>({width:e.getBoundingClientRect().width,height:e.getBoundingClientRect().height}));
   assert.ok(Math.abs(checkbox.width-checkbox.height)<1,'Square checkbox at '+width);
   for(const row of layout.rows){assert.match(row.serial,/^\d+$/);assert.match(row.date,/\+05:30$/);assert.ok(row.contactWidth>=100);assert.equal(row.fieldOverflow,false,JSON.stringify({width,fixture,row}));}
   if(width>=1280&&fixture==='enquiries')assert.ok(layout.firstRowTop<610,'Records visible without excess whitespace');
   if(fixture==='enquiries'){
    assert.equal(await page.$eval('.swx-advanced',e=>e.open),false);
    await page.click('.swx-advanced > summary');assert.equal(await page.$eval('.swx-advanced',e=>e.open),true);
    const ownerValue=await page.$eval('#sw-owner',s=>[...s.options].find(o=>o.value)?.value);
    await page.select('#sw-owner',ownerValue);await page.click('#sw-mine');assert.equal(await page.$eval('#sw-owner',e=>e.value),'');
    await page.select('#sw-owner',ownerValue);assert.equal(await page.$eval('#sw-mine',e=>e.checked),false);
    assert.ok(await page.$$eval('.swx-row-action',links=>links.some(a=>a.hash==='#enquiry-followups')&&links.some(a=>a.hash==='#enquiry-next')));
   }
   if(fixture==='filtered')assert.equal(await page.$eval('.swx-advanced',e=>e.open),true);
   if(fixture==='enquiries'&&[1440,390].includes(width)){
    await page.$eval('.swx-advanced',e=>e.open=false);await page.evaluate(()=>{document.activeElement?.blur();window.scrollTo(0,0);});
    await page.screenshot({path:path.join(folder,'sales-workspace-'+width+'.png'),fullPage:true});
   }
   report.checks.push({width,fixture,rows:layout.rows.length,noOverflow:true,alignedMetrics:true});
  }
  assert.deepEqual(report.errors,[]);assert.deepEqual(report.writes,[]);
  process.stdout.write('SALES_WORKSPACE_BROWSER_CHECKS='+report.checks.length+' ERRORS=0 MUTATIONS=0\n');
 }finally{if(browser)await browser.close();await new Promise(r=>server.close(r));fs.writeFileSync(path.join(folder,'browser-results.json'),JSON.stringify(report,null,2));}
});
