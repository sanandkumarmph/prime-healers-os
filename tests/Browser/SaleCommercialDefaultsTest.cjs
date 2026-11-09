const fs=require('node:fs'),path=require('node:path'),http=require('node:http');
const assert=require('node:assert/strict'),{test}=require('node:test'),puppeteer=require('puppeteer');
const folder=process.env.PHOS_SALE_DEFAULTS_RENDER_DIR;

test('editable Sale transport and GST-inclusive selection preserve manual, saved and quoted values',{skip:!folder,timeout:150000},async()=>{
 const root=path.resolve(__dirname,'../../public'),ids=JSON.parse(fs.readFileSync(path.join(folder,'ids.json'))),report={checks:[],errors:[],writes:[]};let browser,origin;
 const names=['fresh-admin','fresh-sales','retry','edit','conversion'];
 const server=http.createServer((req,res)=>{
  if(req.method!=='GET'){report.writes.push(req.method);res.writeHead(405).end();return;}
  const url=new URL(req.url,origin),name=url.pathname.slice(1);
  if(names.includes(name)){res.writeHead(200,{'Content-Type':'text/html; charset=utf-8'}).end(fs.readFileSync(path.join(folder,name+'.html'),'utf8').replaceAll('http://127.0.0.1:8000',origin));return;}
  const file=path.resolve(root,'.'+decodeURIComponent(url.pathname));
  if(file.startsWith(root+path.sep)&&fs.existsSync(file)&&fs.statSync(file).isFile()){
   res.writeHead(200,{'Content-Type':({'.js':'text/javascript','.css':'text/css','.svg':'image/svg+xml'})[path.extname(file)]||'application/octet-stream'});fs.createReadStream(file).pipe(res);return;
  }
  res.writeHead(200,{'Content-Type':'application/json'}).end(JSON.stringify({data:[],notifications:[],unread_count:0}));
 });
 try{
  await new Promise(r=>server.listen(0,'127.0.0.1',r));origin=`http://127.0.0.1:${server.address().port}`;
  browser=await puppeteer.launch({headless:true});
  const page=await browser.newPage();page.on('pageerror',e=>report.errors.push(e.message));
  await page.setRequestInterception(true);page.on('request',r=>r.url().startsWith(origin+'/')||r.url().startsWith('data:')?r.continue():r.respond({status:200,body:''}));
  const value=selector=>page.$eval(selector,e=>e.value);
  const change=async(selector,v,event='input')=>page.$eval(selector,(e,args)=>{e.value=args.v;e.dispatchEvent(new Event(args.event,{bubbles:true}));},{v:String(v),event});
  const selectProduct=(index,id)=>change('#sale_item_product_'+index,id,'change');
  for(const role of ['admin','sales'])for(const width of [1440,768,390,320]){
   await page.setViewport({width,height:1000});await page.goto(origin+'/fresh-'+role,{waitUntil:'networkidle0'});
   assert.equal(await value('#shipping_charges'),'800.00');
   assert.equal(await page.$eval('#saleUseSuggestedTransport',e=>getComputedStyle(e).display),'none');
   assert.equal(await page.$('[name=deposit_amount]'),null);
   await change('#customer_id',ids.customer,'change');
   await selectProduct(0,ids.small);
   assert.equal(await value('#sale_item_unit_price_0'),'1180.00');assert.equal(await value('#sale_item_tax_0'),'18.00');assert.equal(await value('#sale_item_tax_mode_0'),'inclusive');
   assert.equal(await page.$eval('#sale_item_gst_hint_0',e=>e.textContent),'GST 18% included');
   assert.equal(await value('#sale_amount'),'1980.00');
   await change('#sale_item_quantity_0',3);assert.equal(await value('#shipping_charges'),'800.00');
   await change('#sale_item_quantity_0',4);assert.equal(await value('#shipping_charges'),'2500.00');assert.equal(await value('#sale_amount'),'7220.00');
   await change('#sale_item_quantity_0',1);assert.equal(await value('#shipping_charges'),'800.00');
   await selectProduct(0,ids.heavy);assert.equal(await value('#shipping_charges'),'2500.00');assert.equal(await value('#sale_item_unit_price_0'),'2360.00');
   await change('#shipping_charges',0);await selectProduct(0,ids.small);await change('#sale_item_quantity_0',4);assert.equal(await value('#shipping_charges'),'0');
   assert.notEqual(await page.$eval('#saleUseSuggestedTransport',e=>getComputedStyle(e).display),'none');
   await page.$eval('#saleUseSuggestedTransport',e=>e.click());assert.equal(await value('#shipping_charges'),'2500.00');
   assert.equal(await page.$eval('#saleUseSuggestedTransport',e=>getComputedStyle(e).display),'none');
   await change('#sale_item_unit_price_0',900);await change('#sale_item_tax_0','5.00','change');await change('#sale_item_quantity_0',2);
   assert.equal(await value('#sale_item_unit_price_0'),'900');assert.equal(await value('#sale_item_tax_0'),'5.00');assert.equal(await value('#shipping_charges'),'800.00');
   assert.equal(await page.$eval('#sale_item_gst_hint_0',e=>e.textContent),'GST 5% included');
   await page.$eval('#addSaleItemButton',e=>e.click());await selectProduct(1,ids.small);await change('#sale_item_quantity_1',2);
   assert.equal(await value('#sale_item_tax_mode_1'),'inclusive');assert.equal(await value('#shipping_charges'),'2500.00');
   await page.$eval('[data-remove-index="1"]',e=>e.click());assert.equal(await value('#shipping_charges'),'800.00');
   await page.$eval('[data-sale-step-button="pricing"]',e=>e.click());
   assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'No horizontal overflow '+width);
   if(role==='sales'&&[1440,390].includes(width))await page.screenshot({path:path.join(folder,'sale-defaults-'+width+'.png')});
   report.checks.push({role,width,inclusive:true,threshold:true,heavy:true,manualZero:true,additionalLines:true});
  }
  for(const name of ['retry','edit','conversion'])for(const width of [1440,390]){
   await page.setViewport({width,height:1000});await page.goto(origin+'/'+name,{waitUntil:'networkidle0'});
   assert.equal(await value('#shipping_charges'),'0.00');assert.equal(await value('#sale_item_unit_price_0'),'777.00');assert.equal(await value('#sale_item_tax_mode_0'),'exclusive');assert.equal(await value('#sale_item_tax_0'),'5.00');
   assert.equal(await page.$eval('#sale_item_gst_hint_0',e=>e.textContent),'GST 5% additional');
   await change('#sale_item_quantity_0',5);assert.equal(await value('#shipping_charges'),'0.00');assert.equal(await value('#sale_item_unit_price_0'),'777.00');
   if(name==='conversion')assert.equal(await page.$eval('#saleUseSuggestedTransport',e=>getComputedStyle(e).display),'none');
   else{await page.$eval('#saleUseSuggestedTransport',e=>e.click());assert.equal(await value('#shipping_charges'),'2500.00');}
   report.checks.push({name,width,preserved:true});
  }
  assert.deepEqual(report.errors,[]);assert.deepEqual(report.writes,[]);process.stdout.write('SALE_COMMERCIAL_DEFAULT_BROWSER_CHECKS='+report.checks.length+' ERRORS=0 MUTATIONS=0\n');
 }finally{if(browser)await browser.close();await new Promise(r=>server.close(r));fs.writeFileSync(path.join(folder,'browser-results.json'),JSON.stringify(report,null,2));}
});
