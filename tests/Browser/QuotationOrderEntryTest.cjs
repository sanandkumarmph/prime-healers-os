const fs=require('node:fs'),path=require('node:path'),http=require('node:http');
const assert=require('node:assert/strict'),{test}=require('node:test'),puppeteer=require('puppeteer');
const folder=process.env.PHOS_ORDER_ENTRY_RENDER_DIR;

test('accepted quotation opens customer entry and preserves all quoted lines through stock updates',{skip:!folder,timeout:120000},async()=>{
 const root=path.resolve(__dirname,'../../public'),meta=JSON.parse(fs.readFileSync(path.join(folder,'metadata.json')));
 const report={checks:[],errors:[],writes:[]};let browser,origin,createdAsset=null,scenario='prospect-order';
 const server=http.createServer(async(req,res)=>{
  const url=new URL(req.url,origin);
  if(req.method==='POST'){
   let body='';for await(const chunk of req)body+=chunk;
   report.writes.push({path:url.pathname,body});res.setHeader('Content-Type','application/json');
   if(url.pathname.includes('quick-store')){res.end(JSON.stringify({customer:{id:22,name:'Dr. Synthetic Prospect',phone:'+919876543210',phone_country_code:'+91',email:'synthetic@example.test',address:'Synthetic delivery address',city:'Bengaluru',state:scenario==='igst-order'?'Karnataka':'Delhi'}}));return;}
   if(url.pathname.includes('rental-stock')){if(body.includes('DUPLICATE')){res.writeHead(422).end(JSON.stringify({errors:{serial_number:['That serial number already exists.']}}));return;}createdAsset={id:902,product_id:meta.secondary,warehouse_id:meta.warehouse,serial_number:'SYNTHETIC-SECOND',asset_status:'available',condition_status:'good',warehouse:'Synthetic warehouse'};res.end(JSON.stringify({asset:createdAsset}));return;}
   res.writeHead(405).end();return;
  }
  const name=url.pathname.slice(1);
  if(['prospect-order','linked-order','plain-order','igst-order'].includes(name)){
   scenario=name;res.setHeader('Content-Type','text/html; charset=utf-8');res.end(fs.readFileSync(path.join(folder,name+'.html'),'utf8').replaceAll('http://127.0.0.1:8000',origin));return;
  }
  if(url.pathname.includes('available-assets')){
   res.setHeader('Content-Type','application/json');res.end(JSON.stringify({data:createdAsset&&Number(url.searchParams.get('product_id'))===meta.secondary?[createdAsset]:[]}));return;
  }
  const file=path.resolve(root,'.'+decodeURIComponent(url.pathname));
  if(file.startsWith(root+path.sep)&&fs.existsSync(file)&&fs.statSync(file).isFile()){
   res.writeHead(200,{'Content-Type':({'.js':'text/javascript','.css':'text/css','.svg':'image/svg+xml'})[path.extname(file)]||'application/octet-stream'});fs.createReadStream(file).pipe(res);return;
  }
  res.setHeader('Content-Type','application/json');res.end(JSON.stringify({data:[],results:[],notifications:[],unread_count:0}));
 });
 try{
  await new Promise(r=>server.listen(0,'127.0.0.1',r));origin=`http://127.0.0.1:${server.address().port}`;
  browser=await puppeteer.launch({headless:true});
  for(const width of [1440,390,320]){
   const page=await browser.newPage();page.on('pageerror',e=>report.errors.push(e.message));await page.setViewport({width,height:1000});
   await page.setRequestInterception(true);page.on('request',r=>r.url().startsWith(origin+'/')||r.url().startsWith('data:')?r.continue():r.respond({status:200,body:''}));
   let expectedTax='cgst_sgst';
   const checkPrices=async()=>{
    const values=await page.evaluate(()=>({product:document.getElementById('product_id').value,primary:document.getElementById('rental_amount').value,
     extra:[...document.querySelectorAll('[data-rental-price-index]')].map(e=>e.value),sale:[...document.querySelectorAll('[data-sale-price-index]')].map(e=>e.value),
     start:document.getElementById('start_date').value,end:document.getElementById('end_date').value,
     deposit:document.getElementById('deposit_amount').value,transport:document.getElementById('transport_amount').value,
     quantity:document.querySelector('[data-rental-quantity-index="0"]').value,gst:document.getElementById('gst_rate').value,mode:document.getElementById('gst_mode').value,taxTypes:[document.getElementById('tax_type').value,...document.querySelectorAll('[data-rental-tax-type],[data-sale-tax-type]')].map(e=>typeof e==='string'?e:e.value)}));
    assert.equal(values.product,String(meta.primary));assert.equal(Number(values.primary),0);assert.deepEqual(values.extra,['321.25']);assert.deepEqual(values.sale,['11.11']);
    assert.equal(values.start,'2026-11-09');assert.equal(values.end,'2026-12-08');assert.equal(Number(values.deposit),2000);assert.equal(Number(values.transport),600);
    assert.equal(values.quantity,'2');assert.equal(Number(values.gst),5);assert.equal(values.mode,'inclusive');
    assert.ok(values.taxTypes.every(type=>type===expectedTax),JSON.stringify(values.taxTypes));
   };
   await page.goto(origin+'/prospect-order',{waitUntil:'networkidle0'});await page.addStyleTag({content:'*{scroll-behavior:auto!important}'});
   assert.equal(await page.$eval('[data-rental-wizard-tab].is-active',e=>e.dataset.rentalWizardTab),'fulfilment');
   assert.equal(await page.$eval('#rentalQuickCustomerModal',e=>e.hidden),false);
   assert.equal(await page.$eval('#rentalQuickCustomerModalFirstName',e=>e.value),'Synthetic Prospect');
   assert.equal(await page.$eval('#rentalQuickCustomerModalSalutation',e=>e.value),'Dr.');
   assert.equal(await page.$eval('#rentalQuickCustomerModalEmail',e=>e.value),'synthetic@example.test');
   await checkPrices();
   await page.$eval('#rentalQuickCustomerModal .quick-customer-close',e=>e.click());
   assert.equal(await page.$eval('#rentalQuickCustomerModal',e=>e.hidden),true);await checkPrices();
   await page.$eval('[data-open-modal="rentalQuickCustomerModal"]',e=>e.click());
   await page.$eval('#rentalQuickCustomerModalAddress',e=>e.value='Synthetic delivery address');
   await page.$eval('#rentalQuickCustomerForm',e=>e.requestSubmit());
   await page.waitForFunction(()=>document.getElementById('customer_id').value==='22');await checkPrices();
   assert.equal(await page.$eval('#customer_id',e=>e.selectedOptions[0].dataset.address),'Synthetic delivery address');
   await page.evaluate(meta=>{
    const set=(id,value)=>{const e=document.getElementById(id);e.value=String(value);e.dispatchEvent(new Event('change',{bubbles:true}));};
    set('city_id',meta.city);set('dispatch_warehouse_id',meta.warehouse);document.querySelector('[data-rental-wizard-tab="product"]').click();
   },meta);
   await checkPrices();
   assert.equal(await page.$$eval('[data-rental-stock-item]',e=>e.length),1);
   await page.$eval('[data-rental-stock-item="0"]',e=>e.click());
   assert.equal(await page.$eval('#rentalStockModal',e=>e.hidden),false);
   assert.equal(await page.$eval('#rental_stock_product_id',e=>e.value),String(meta.secondary));
   assert.equal(await page.$eval('#rental_stock_warehouse_id',e=>e.value),String(meta.warehouse));
   await page.$eval('#rental_stock_serial_number',e=>e.value='DUPLICATE');
   await page.$eval('#saveRentalStockButton',e=>e.click());await page.waitForFunction(()=>document.getElementById('rentalStockMessage').textContent.includes('already exists')&&!document.getElementById('saveRentalStockButton').disabled);
   assert.equal(await page.$eval('#rentalStockModal',e=>e.hidden),false);await checkPrices();
   await page.$eval('#rental_stock_serial_number',e=>e.value='SYNTHETIC-SECOND');
   await page.$eval('#saveRentalStockButton',e=>e.click());await page.waitForFunction(()=>document.getElementById('rentalStockModal').hidden);
   await checkPrices();
   const stockWrite=report.writes.at(-1);assert.match(stockWrite.path,/rental-stock/);assert.match(stockWrite.body,new RegExp('name="product_id"\\r\\n\\r\\n'+meta.secondary+'\\r\\n'));
   await page.goto(origin+'/linked-order',{waitUntil:'networkidle0'});assert.equal(await page.$eval('#rentalQuickCustomerModal',e=>e.hidden),true);await checkPrices();
   await page.goto(origin+'/plain-order',{waitUntil:'networkidle0'});
   assert.equal(await page.$eval('[data-rental-wizard-tab].is-active',e=>e.dataset.rentalWizardTab),'fulfilment');
   assert.equal(await page.$('[name=quotation_id]'),null);assert.equal(await page.$eval('#rentalQuickCustomerModal',e=>e.hidden),true);
   await page.goto(origin+'/igst-order',{waitUntil:'networkidle0'});expectedTax='igst';
   await page.$eval('#rentalQuickCustomerForm',e=>e.requestSubmit());await page.waitForFunction(()=>document.getElementById('customer_id').value==='22');await checkPrices();
   report.checks.push({width,customerStep:true,modalPrefill:true,allPricesPreserved:true,stockTargetsSecondProduct:true});await page.close();
  }
  assert.deepEqual(report.errors,[]);assert.equal(report.writes.length,12);
  process.stdout.write('QUOTATION_ORDER_CUSTOMER_PREFILL_AND_LINE_STOCK_BROWSER_CHECKS='+report.checks.length+'\n');
 }finally{if(browser)await browser.close();await new Promise(r=>server.close(r));fs.writeFileSync(path.join(folder,'order-entry-browser-results.json'),JSON.stringify(report,null,2));}
});
