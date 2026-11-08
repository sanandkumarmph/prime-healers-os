const fs=require('node:fs'),path=require('node:path'),http=require('node:http');
const assert=require('node:assert/strict'),{test}=require('node:test'),puppeteer=require('puppeteer');
const folder=process.env.PHOS_LIVE_QUOTE_RENDER_DIR;

test('customer actions update Sales status and order links without reload or losing drafts',{skip:!folder,timeout:120000},async()=>{
 const root=path.resolve(__dirname,'../../public'),report={checks:[],errors:[],writes:[],polls:0};let browser,origin,phase='pending';
 const states=Object.fromEntries(['pending','accepted','signed','exception','exception-signed'].map(name=>[name,JSON.parse(fs.readFileSync(path.join(folder,name+'.json'),'utf8'))]));
 const server=http.createServer(async(req,res)=>{
  const url=new URL(req.url,origin);
  if(req.method==='POST'){
   if(!url.pathname.startsWith('/customer/')){report.writes.push(url.pathname);res.writeHead(405).end();return;}
   phase=url.pathname.split('/').at(-1);res.setHeader('Content-Type','application/json');res.end('{"ok":true}');return;
  }
  if(/^\/quotations\/\d+\/status$/.test(url.pathname)){
   report.polls++;const value=states[phase]||states.signed;
   res.writeHead(200,{'Content-Type':'application/json','Cache-Control':'private, no-store'}).end(JSON.stringify(value).replaceAll('http://127.0.0.1:8000',origin));return;
  }
  const fixture=url.pathname.slice(1);
  if(['quotation','enquiry','enquiry-exception'].includes(fixture)){
   res.writeHead(200,{'Content-Type':'text/html; charset=utf-8'}).end(fs.readFileSync(path.join(folder,fixture+'.html'),'utf8').replaceAll('http://127.0.0.1:8000',origin));return;
  }
  if(fixture==='customer'){
   res.writeHead(200,{'Content-Type':'text/html'}).end('<button id="accept" onclick="fetch(\'/customer/accepted\',{method:\'POST\'})">Accept quotation</button><button id="sign" onclick="fetch(\'/customer/signed\',{method:\'POST\'})">Sign agreement</button>');return;
  }
  const file=path.resolve(root,'.'+decodeURIComponent(url.pathname));
  if(file.startsWith(root+path.sep)&&fs.existsSync(file)&&fs.statSync(file).isFile()){
   res.writeHead(200,{'Content-Type':({'.js':'text/javascript','.css':'text/css','.svg':'image/svg+xml'})[path.extname(file)]||'application/octet-stream'});fs.createReadStream(file).pipe(res);return;
  }
  res.setHeader('Content-Type','application/json');res.end(JSON.stringify({data:[],notifications:[],unread_count:0}));
 });
 try{
  await new Promise(r=>server.listen(0,'127.0.0.1',r));origin=`http://127.0.0.1:${server.address().port}`;
  browser=await puppeteer.launch({headless:true});
  for(const width of [1440,390,320])for(const fixture of ['quotation','enquiry']){
   phase='pending';const page=await browser.newPage();page.on('pageerror',error=>report.errors.push(error.message));await page.setViewport({width,height:1000});
   await page.setRequestInterception(true);page.on('request',r=>r.url().startsWith(origin+'/')||r.url().startsWith('data:')?r.continue():r.respond({status:200,body:''}));
   await page.goto(origin+'/'+fixture,{waitUntil:'networkidle0'});await page.waitForFunction(()=>document.querySelector('.sw-live-waiting'));
   let reloads=0;page.on('framenavigated',frame=>{if(frame===page.mainFrame())reloads++;});
   const note=fixture==='enquiry'?'#note':'#response-note';
   if(fixture==='quotation')await page.$eval('[data-live-response] details',element=>element.open=true);
   await page.$eval(note,e=>e.scrollIntoView({block:'center'}));await page.type(note,'Keep my unsaved sales note');
   const customer=await browser.newPage();await customer.goto(origin+'/customer');
   await Promise.all([customer.waitForResponse(response=>response.url().endsWith('/customer/accepted')),customer.click('#accept')]);
   await page.bringToFront();await page.waitForFunction(()=>document.querySelector('[data-quote-live]').dataset.liveQuotationStatus==='accepted');
   await page.waitForFunction(()=>document.querySelector('[data-live-response]')?.hidden===false||document.querySelector('[data-live-status-text]'));
   assert.equal(await page.$eval(note,e=>e.value),'Keep my unsaved sales note');
   // Changing tab focus causes a prompt poll; no fifteen-second timer is required.
   await customer.bringToFront();await Promise.all([customer.waitForResponse(response=>response.url().endsWith('/customer/signed')),customer.click('#sign')]);
   await page.bringToFront();await page.waitForFunction(()=>document.querySelector('[data-live-status-text]').textContent.trim()==='Rental terms signed');
   await page.waitForFunction(()=>document.querySelector('[data-live-quote-header] a')?.textContent==='Create Rental');
   assert.equal(await page.$eval(note,e=>e.value),'Keep my unsaved sales note');assert.equal(reloads,0);
   assert.ok(await page.$('[data-live-agreement-actions] a[href*="signed-agreement"]'));
   assert.match(await page.$eval('[data-live-order-link]',e=>e.href),/\/rentals\/create\?quotation_id=/);
   assert.equal(await page.$('.sw-live-waiting'),null);
   if(fixture==='quotation'){
    assert.equal(await page.$eval('[data-live-response]',e=>e.hidden),false);
    assert.equal(await page.$eval('[data-live-response] button[type=submit],[data-live-response] button:not([type])',e=>e.disabled),true);
    assert.match(await page.$eval('[data-live-form-note]',e=>e.textContent),/unsaved text is preserved/);
   }
   await page.emulateMediaFeatures([{name:'prefers-reduced-motion',value:'reduce'}]);
   assert.equal(await page.$eval('[data-live-status-banner]',e=>getComputedStyle(e,'::before').animationName),'none');
   assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
   await customer.close();await page.close();report.checks.push({width,fixture,noReload:true,draftPreserved:true,signedReady:true});
  }
  // An open modal defers action changes, including a terminal revision update.
  phase='pending';const page=await browser.newPage();await page.goto(origin+'/quotation',{waitUntil:'networkidle0'});
  await page.evaluate(()=>{const modal=document.createElement('div');modal.id='synthetic-modal';modal.className='quick-customer-modal';modal.style.cssText='position:fixed;inset:0;background:white';document.body.append(modal);});
  const latest={...states.signed,current:false,terminal:true,quotation_status:'superseded',order_action:null,version:'terminal-revision-test'};states.terminal=latest;phase='terminal';
  await page.evaluate(()=>dispatchEvent(new Event('focus')));
  await page.waitForFunction(()=>document.querySelector('[data-live-status-text]').textContent.includes('newer quotation'));
  assert.equal(await page.$eval('[data-live-quote-header]',e=>e.textContent.includes('Open latest quotation')),false);
  await page.$eval('#synthetic-modal',e=>e.hidden=true);
  await page.waitForFunction(()=>document.querySelector('[data-live-quote-header]').textContent.includes('Open latest quotation'));
  await page.close();report.checks.push({modalDeferredTerminalUpdate:true});
  // With no focus change, a visible page still receives the regular fifteen-second update.
  phase='pending';const timerPage=await browser.newPage();await timerPage.goto(origin+'/quotation',{waitUntil:'networkidle0'});await timerPage.bringToFront();
  await timerPage.waitForFunction(()=>document.querySelector('[data-quote-live]').dataset.liveQuotationStatus==='sent');phase='signed';
  await timerPage.waitForFunction(()=>document.querySelector('[data-quote-live]').dataset.liveAgreementState==='signed',{timeout:20000});
  await timerPage.close();report.checks.push({visibleFifteenSecondPolling:true});
  // Draft finalisation controls must survive a status check.
  states.draft={...states.pending,quotation_status:'draft',quotation_status_label:'Draft',can_record_response:false,version:'draft-controls'};phase='draft';
  const draftPage=await browser.newPage();await draftPage.goto(origin+'/quotation',{waitUntil:'networkidle0'});
  await draftPage.$eval('[data-live-quote-header]',e=>e.innerHTML='<button id="draft-finalise">Finalise & share</button>');
  await draftPage.waitForFunction(()=>document.querySelector('[data-quote-live]').dataset.liveQuotationStatus==='draft');
  assert.ok(await draftPage.$('#draft-finalise'));assert.equal(await draftPage.$('.sw-live-waiting'),null);
  await draftPage.close();report.checks.push({draftFinalisationPreserved:true});
  phase='exception';const exceptionPage=await browser.newPage();await exceptionPage.goto(origin+'/enquiry-exception',{waitUntil:'networkidle0'});await exceptionPage.bringToFront();
  await exceptionPage.waitForFunction(()=>document.querySelector('[data-quote-live]').dataset.liveAgreementState==='exception');
  assert.equal(await exceptionPage.$('[data-live-agreement-actions] a[href*="signed-agreement"]'),null);
  phase='exception-signed';await exceptionPage.evaluate(()=>dispatchEvent(new Event('focus')));
  await exceptionPage.waitForSelector('[data-live-agreement-actions] a[href*="signed-agreement"]');
  assert.equal(await exceptionPage.$eval('[data-live-agreement-actions]',e=>e.dataset.liveSigned),'1');
  await exceptionPage.close();report.checks.push({initialApprovedExceptionBecomesActualSignedCopy:true});
  assert.deepEqual(report.errors,[]);assert.deepEqual(report.writes,[]);
  process.stdout.write('LIVE_QUOTATION_BROWSER_CHECKS='+report.checks.length+' RELOADS=0 DRAFTS_PRESERVED=true\n');
 }finally{if(browser){report.finalPages=await Promise.all((await browser.pages()).map(page=>page.evaluate(()=>({url:location.pathname,status:document.querySelector('[data-quote-live]')?.dataset.liveQuotationStatus,header:document.querySelector('[data-live-quote-header]')?.outerHTML,modals:[...document.querySelectorAll('dialog[open],.quick-customer-modal,[aria-modal="true"]')].map(e=>({tag:e.tagName,cls:e.className,hidden:!!e.closest('[hidden]'),rects:e.getClientRects().length,visibility:getComputedStyle(e).visibility,display:getComputedStyle(e).display}))})).catch(()=>({closed:true}))));await browser.close();}await new Promise(r=>server.close(r));fs.writeFileSync(path.join(folder,'browser-results.json'),JSON.stringify(report,null,2));}
});
