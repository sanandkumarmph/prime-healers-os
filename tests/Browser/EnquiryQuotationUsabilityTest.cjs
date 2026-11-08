const fs=require('node:fs'),path=require('node:path'),http=require('node:http');
const assert=require('node:assert/strict'),{test}=require('node:test'),puppeteer=require('puppeteer');
const folder=process.env.PHOS_ENQUIRY_QUOTE_USABILITY_RENDER_DIR;

test('one customer picker searches real results and quotation sharing has clear chronological actions',{skip:!folder,timeout:120000},async()=>{
 const root=path.resolve(__dirname,'../../public'),report={checks:[],errors:[],writes:[]};let origin,browser;
 const server=http.createServer((req,res)=>{
  if(req.method!=='GET'){report.writes.push(req.method);res.writeHead(405).end();return;}
  const url=new URL(req.url,origin),fixture=url.pathname.slice(1);
  if(['enquiry-capture','quotation-draft','quotation-sharing'].includes(fixture)){
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
  browser=await puppeteer.launch({headless:true});const page=await browser.newPage();page.setDefaultTimeout(10000);
  page.on('pageerror',e=>report.errors.push(e.message));await page.setRequestInterception(true);
  page.on('request',r=>r.url().startsWith(origin+'/')||r.url().startsWith('data:')?r.continue():r.respond({status:200,body:''}));
  const focus=selector=>page.$eval(selector,e=>{e.scrollIntoView({block:'center'});e.focus();});
  const resetSearch=async term=>{await focus('#customer-picker-search');await page.$eval('#customer-picker-search',e=>{e.value='';e.dispatchEvent(new Event('input',{bubbles:true}));});await page.type('#customer-picker-search',term);};
  for(const width of [1440,1024,768,390,320])for(const fixture of ['enquiry-capture','quotation-draft','quotation-sharing']){
   await page.setViewport({width,height:1000});await page.goto(origin+'/'+fixture,{waitUntil:'networkidle0'});
   assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`${width} ${fixture} overflow`);
   if(fixture!=='quotation-sharing'){
    await page.$eval('[data-sw-customer-picker]',e=>{for(let p=e.parentElement;p;p=p.parentElement)if(p.tagName==='DETAILS')p.open=true;});
    assert.equal(await page.$('#customer-search'),null);
    assert.equal(await page.$eval('#customer_id',e=>e.hidden),true);
    await resetSearch('bob@example.test');
    assert.deepEqual(await page.$$eval('#customer-picker-results [role=option] strong',e=>e.map(x=>x.textContent)),['Use enquiry contact','Bob Existing']);
    await page.$eval('#customer-picker-results [role=option]:last-of-type',e=>e.click());
    assert.equal(await page.$eval('#customer_id',e=>e.selectedOptions[0].dataset.name),'Bob Existing');
    assert.equal(await page.$eval('#customer-picker-search',e=>e.getAttribute('aria-expanded')),'false');
    if(fixture==='enquiry-capture'){
     assert.equal(await page.$eval('#name',e=>e.value),'Bob Existing');assert.equal(await page.$eval('#phone',e=>e.value),'+919000000002');
    }
    await resetSearch('+91 90000 00001');
    assert.deepEqual(await page.$$eval('#customer-picker-results [role=option] strong',e=>e.map(x=>x.textContent)),['Use enquiry contact','Alice Existing']);
    await page.keyboard.press('ArrowDown');await page.keyboard.press('ArrowDown');await page.keyboard.press('Enter');
    assert.equal(await page.$eval('#customer_id',e=>e.selectedOptions[0].dataset.name),'Alice Existing');
    if(fixture==='enquiry-capture')assert.equal(await page.$eval('#salutation',e=>e.value),'Dr.');
    await resetSearch('zzzzmissing');assert.match(await page.$eval('#customer-picker-results',e=>e.textContent),/No matching customers/);
    await page.$eval('#customer-picker-results [role=option]',e=>e.click());assert.equal(await page.$eval('#customer_id',e=>e.value),'');
    if(fixture==='enquiry-capture')assert.equal(await page.$eval('#name',e=>e.value),'');
    if(fixture==='quotation-draft')assert.equal(await page.$eval('#recipient-label',e=>e.textContent),'Helen Rao · Change');
    await resetSearch('alice');await page.keyboard.press('Escape');assert.equal(await page.$eval('#customer-picker-search',e=>e.getAttribute('aria-expanded')),'false');
   }else{
    assert.deepEqual(await page.$$eval('[data-share-step]',e=>e.map(x=>x.dataset.shareStep)),['quotation','terms','order']);
    assert.equal(await page.$$eval('[data-share-step=quotation] [data-copy-whatsapp]',e=>e.map(x=>x.textContent.trim())).then(x=>x[0]),'Copy quotation message');
    assert.equal(await page.$$eval('[data-share-step=terms] [data-copy-whatsapp]',e=>e.map(x=>x.textContent.trim())).then(x=>x[0]),'Copy rental terms message');
    assert.equal(await page.$$eval('#response',e=>e.length),1);assert.equal(await page.$$eval('#contact-name',e=>e.length),1);
    assert.equal(await page.$eval('[data-edit-quotation] [name=action]',e=>e.value),'revise');
    await page.evaluate(()=>Object.defineProperty(navigator,'clipboard',{configurable:true,value:{writeText:async text=>{window.copiedShare=text;}}}));
    for(const step of ['quotation','terms']){
     const selector=`[data-share-step=${step}] [data-copy-whatsapp]`;await focus(selector);await page.$eval(selector,e=>e.click());
     await page.waitForFunction(()=>!!window.copiedShare);
     const copied=await page.evaluate(()=>window.copiedShare);
     assert.ok(step==='quotation'?copied.includes('Rental quotation'):copied.includes('Rental agreement'));
     await page.evaluate(()=>{window.copiedShare=null;});
    }
    if([1440,390].includes(width)){
     await page.evaluate(()=>{location.hash='';document.activeElement?.blur();for(const e of document.querySelectorAll('*'))if(e.scrollTop>0)e.scrollTop=0;});
     await page.screenshot({path:path.join(folder,'sharing-chronology-'+width+'.png'),fullPage:true});
    }
   }
   report.checks.push({width,fixture,passed:true});
  }
  assert.deepEqual(report.errors,[]);assert.deepEqual(report.writes,[]);
  process.stdout.write('ENQUIRY_QUOTATION_USABILITY_BROWSER_CHECKS='+report.checks.length+' ERRORS=0 MUTATIONS=0\n');
 }finally{if(browser)await browser.close();await new Promise(r=>server.close(r));fs.writeFileSync(path.join(folder,'enquiry-quote-browser-results.json'),JSON.stringify(report,null,2));}
});
