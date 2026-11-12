const fs=require('node:fs'),path=require('node:path'),http=require('node:http');
const assert=require('node:assert/strict'),{test}=require('node:test'),puppeteer=require('puppeteer');
const folder=process.env.PHOS_CUSTOMER_IDENTITY_RENDER_DIR;

test('Sales customer modal requires identity choice and selects the real API-created customer',{skip:!folder,timeout:100000},async()=>{
 const root=path.resolve(__dirname,'../../public'),actual=JSON.parse(fs.readFileSync(path.join(folder,'actual-api-responses.json')));
 assert.equal(actual.conflict.code,'customer_identity_conflict');assert.equal(actual.created.customer.name,'Ms. Kiara');
 const report={checks:[],errors:[],writes:[]};let origin,browser,phase=0;
 const server=http.createServer(async(req,res)=>{
  const url=new URL(req.url,origin);
  if(req.method==='POST'){
   let body='';for await(const chunk of req)body+=chunk;
   report.writes.push({path:url.pathname,body});
   if(!url.pathname.includes('quick-store')){res.writeHead(405).end();return;}
   res.setHeader('Content-Type','application/json');
   if(phase++===0){res.writeHead(409).end(JSON.stringify(actual.conflict));return;}
   assert.match(body,/name="quick_conflict_action"\r\n\r\nseparate\r\n/);
   assert.match(body,new RegExp('name="quick_conflict_customer_ids\\[\\]"\\r\\n\\r\\n'+actual.helen_id+'\\r\\n'));
   res.end(JSON.stringify(actual.created));return;
  }
  if(url.pathname==='/conversion'){
   res.setHeader('Content-Type','text/html; charset=utf-8');res.end(fs.readFileSync(path.join(folder,'conversion.html'),'utf8').replaceAll('http://127.0.0.1:8000',origin));return;
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
  for(const width of [1440,390,320]){
   phase=0;const page=await browser.newPage();await page.setViewport({width,height:1000});
   page.on('pageerror',e=>report.errors.push(e.message));await page.setRequestInterception(true);
   page.on('request',r=>r.url().startsWith(origin+'/')||r.url().startsWith('data:')?r.continue():r.respond({status:200,body:''}));
   await page.goto(origin+'/conversion',{waitUntil:'networkidle0'});
   const modal='#rentalQuickCustomerModal';assert.equal(await page.$eval(modal,e=>e.hidden),false);
   assert.equal(await page.$eval(modal+'FirstName',e=>e.value),'Kiara');
   assert.equal(await page.$eval(modal+'Salutation',e=>e.value),'Ms.');
   await page.$eval(modal+' [data-close-modal]',e=>e.click());
   await page.select('#customer_id',String(actual.helen_id));
   await page.$eval('[name=quotation_recipient_confirmed]',e=>e.checked=true);
   await page.$eval('[data-open-modal="rentalQuickCustomerModal"]',e=>e.click());
   await page.$eval('#rentalQuickCustomerForm',e=>e.requestSubmit());
   await page.waitForFunction(()=>!document.querySelector('[data-customer-identity-conflict]').hidden);
   assert.equal(await page.$eval(modal,e=>e.hidden),false);
   assert.equal(await page.$eval('#customer_id',e=>e.value),String(actual.helen_id));
   assert.equal(await page.$eval('[name=quotation_recipient_confirmed]',e=>e.checked),true);
   const conflict=await page.$eval('[data-customer-identity-conflict]',e=>e.textContent);
   assert.match(conflict,/Ms\. Helen/);assert.match(conflict,/Create Ms\. Kiara as a separate customer/);
   await page.$eval('[data-customer-conflict-choice]',e=>e.click());
   await page.waitForFunction(id=>document.getElementById('customer_id').value===String(id),{},actual.created.customer.id);
   assert.equal(await page.$eval(modal,e=>e.hidden),true);
   assert.equal(await page.$eval('#customer_name',e=>e.value),'Ms. Kiara');
   assert.equal(await page.$eval('#customer_id',e=>e.selectedOptions[0].dataset.name),'Ms. Kiara');
   assert.match(await page.$eval('#customerContactUsageCard',e=>e.textContent),/Ms\. Kiara/);
   assert.match(await page.$eval('#customer_id',e=>e._searchableSelect.triggerLabel.textContent),/Ms\. Kiara/);
   assert.equal(await page.$eval('[name=quotation_recipient_confirmed]',e=>e.checked),false);
   await page.$eval('[name=quotation_recipient_confirmed]',e=>e.checked=true);
   await page.$eval('#customer_id',e=>e.dispatchEvent(new Event('change',{bubbles:true})));
   assert.equal(await page.$eval('[name=quotation_recipient_confirmed]',e=>e.checked),true,'same identity change preserves confirmation');
   await page.select('#customer_id',String(actual.helen_id));
   assert.equal(await page.$eval('[name=quotation_recipient_confirmed]',e=>e.checked),false,'choosing a different person requires confirmation');
   assert.equal(await page.$eval('#rental_amount',e=>e.value),'3500.00');
   if(width===390)await page.screenshot({path:path.join(folder,'customer-identity-selected-390.png')});
   report.checks.push({width,backendDerivedIdentity:true,conflictKeepsPopup:true,selectedCustomerId:actual.created.customer.id,confirmationReset:true});await page.close();
  }
  assert.deepEqual(report.errors,[]);assert.equal(report.writes.length,6);
  process.stdout.write('CUSTOMER_IDENTITY_REAL_API_CONTRACT_BROWSER_CHECKS='+report.checks.length+'\n');
 }finally{if(browser)await browser.close();await new Promise(r=>server.close(r));fs.writeFileSync(path.join(folder,'browser-results.json'),JSON.stringify(report,null,2));}
});
