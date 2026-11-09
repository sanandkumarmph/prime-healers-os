const fs=require('node:fs'),path=require('node:path'),http=require('node:http');
const assert=require('node:assert/strict'),{test}=require('node:test'),puppeteer=require('puppeteer');
const folder=process.env.PHOS_RENTAL_DEFAULTS_RENDER_DIR;
test('editable rental defaults include every product line while agreed and entered values stay intact',{skip:!folder,timeout:180000},async()=>{
 const root=path.resolve(__dirname,'../../public'),products=JSON.parse(fs.readFileSync(path.join(folder,'products.json'),'utf8'));
 const report={checks:[],errors:[],writes:[]};let origin,browser;
 const fixtures=['fresh-admin','fresh-sales','retry','edit','quotation'];
 const server=http.createServer((req,res)=>{
  if(req.method!=='GET'){report.writes.push(req.method);res.writeHead(405).end();return;}
  const url=new URL(req.url,origin),fixture=url.pathname.slice(1);
  if(fixtures.includes(fixture)){res.writeHead(200,{'Content-Type':'text/html; charset=utf-8'}).end(fs.readFileSync(path.join(folder,fixture+'.html'),'utf8').replaceAll('http://127.0.0.1:8000',origin));return;}
  const file=path.resolve(root,'.'+decodeURIComponent(url.pathname));
  if(file.startsWith(root+path.sep)&&fs.existsSync(file)&&fs.statSync(file).isFile()){
   res.writeHead(200,{'Content-Type':({'.js':'text/javascript','.css':'text/css','.svg':'image/svg+xml','.png':'image/png'})[path.extname(file)]||'application/octet-stream'});fs.createReadStream(file).pipe(res);return;
  }
  res.writeHead(200,{'Content-Type':'application/json'}).end(JSON.stringify({data:[],results:[],rows:[],notifications:[],unread_count:0}));
 });
 const value=(page,id)=>page.$eval('#'+id,e=>e.value);
 const set=(page,selector,text,event='input')=>page.$eval(selector,(e,args)=>{e.value=String(args.text);e.dispatchEvent(new Event(args.event,{bubbles:true}));},{text,event});
 try{
  await new Promise(r=>server.listen(0,'127.0.0.1',r));origin=`http://127.0.0.1:${server.address().port}`;
  browser=await puppeteer.launch({headless:true});
  for(const width of [1440,390])for(const fixture of fixtures){
   const page=await browser.newPage();page.on('pageerror',e=>report.errors.push(e.message));await page.setViewport({width,height:1000});
   await page.setRequestInterception(true);page.on('request',r=>r.url().startsWith(origin+'/')||r.url().startsWith('data:')?r.continue():r.respond({status:200,body:''}));
   await page.goto(origin+'/'+fixture,{waitUntil:'networkidle0'});await page.addStyleTag({content:'*{scroll-behavior:auto!important}'});
   if(fixture.startsWith('fresh')){
    assert.equal(Number(await value(page,'deposit_amount')),2000);assert.equal(Number(await value(page,'transport_amount')),800);
    assert.equal(await page.$eval('#rentalUseSuggestedTransport',e=>getComputedStyle(e).display),'none');
    await page.select('#duration_preset','30_days');await page.select('#product_id',String(products.small));
    assert.equal(Number(await value(page,'rental_amount')),3000);assert.equal(await value(page,'gst_mode'),'inclusive');assert.equal(Number(await value(page,'gst_rate')),5);
    await set(page,'#quantity',4);assert.equal(Number(await value(page,'transport_amount')),2500);
    await set(page,'#quantity',3);assert.equal(Number(await value(page,'transport_amount')),800);
    await set(page,'#rental_amount',1111);await set(page,'#transport_amount',0);await set(page,'#deposit_amount',0);
    await page.select('#gst_rate','18.00');await page.select('#gst_mode','exclusive');
    await set(page,'#quantity',4);assert.equal(Number(await value(page,'rental_amount')),1111);assert.equal(Number(await value(page,'transport_amount')),0);
    assert.notEqual(await page.$eval('#rentalUseSuggestedTransport',e=>getComputedStyle(e).display),'none');
    assert.equal(await value(page,'gst_rate'),'18.00');assert.equal(await value(page,'gst_mode'),'exclusive');
    await page.$eval('#rentalUseSuggestedTransport',e=>e.click());assert.equal(Number(await value(page,'transport_amount')),2500);
    assert.equal(await page.$eval('#rentalUseSuggestedTransport',e=>getComputedStyle(e).display),'none');
    await set(page,'#quantity',1);await page.select('#product_id',String(products.heavy));assert.equal(Number(await value(page,'transport_amount')),2500);assert.equal(Number(await value(page,'rental_amount')),7000);
    await page.select('#product_id',String(products.small));assert.equal(Number(await value(page,'transport_amount')),800);assert.equal(Number(await value(page,'deposit_amount')),0);
    await page.$eval('#addRentalItemButton',e=>e.click());await page.select('[data-rental-product-index="0"]',String(products.small));
    assert.equal(Number(await page.$eval('[data-rental-price-index="0"]',e=>e.value)),3000);
    assert.equal(await page.$eval('[data-rental-gst-mode="0"]',e=>e.value),'inclusive');
    await set(page,'[data-rental-quantity-index="0"]',2);assert.equal(Number(await value(page,'transport_amount')),800);
    await page.$eval('#addSaleItemButton',e=>e.click());await page.select('[data-sale-product-index="0"]',String(products.accessory));
    assert.equal(Number(await page.$eval('[data-sale-price-index="0"]',e=>e.value)),150);assert.equal(await page.$eval('[data-sale-gst-mode="0"]',e=>e.value),'inclusive');
    assert.equal(Number(await page.$eval('[data-sale-gst-rate="0"]',e=>e.value)),12);assert.equal(Number(await value(page,'transport_amount')),2500);
    await set(page,'[data-rental-price-index="0"]',0);await set(page,'[data-rental-quantity-index="0"]',3);
    assert.equal(Number(await page.$eval('[data-rental-price-index="0"]',e=>e.value)),0);
    await page.$eval('[data-remove-sale-item="0"]',e=>e.click());await set(page,'[data-rental-quantity-index="0"]',2);assert.equal(Number(await value(page,'transport_amount')),800);
    await set(page,'#transport_amount',123);await page.select('#product_id',String(products.heavy));assert.equal(Number(await value(page,'transport_amount')),123);
    if(fixture==='fresh-sales')await page.screenshot({path:path.join(folder,'rental-defaults-'+width+'.png')});
   }else{
    const expected=fixture==='quotation'?{rental_amount:2100,transport_amount:600,deposit_amount:0}:fixture==='edit'?{rental_amount:1777,transport_amount:123,deposit_amount:0}:{rental_amount:0,transport_amount:0,deposit_amount:0};
    for(const [id,amount]of Object.entries(expected))assert.equal(Number(await value(page,id)),amount,fixture+' '+id);
    await set(page,'#quantity',4);for(const [id,amount]of Object.entries(expected))assert.equal(Number(await value(page,id)),amount,fixture+' keeps '+id+' after quantity');
    if(fixture==='quotation')assert.equal(await value(page,'gst_mode'),'exclusive');
   }
   report.checks.push({width,fixture,defaultsOrPreservationVerified:true});await page.close();
  }
  assert.deepEqual(report.errors,[]);assert.deepEqual(report.writes,[]);process.stdout.write('RENTAL_COMMERCIAL_DEFAULT_BROWSER_CASES='+report.checks.length+' ERRORS=0 MUTATIONS=0\n');
 }finally{if(browser)await browser.close();await new Promise(r=>server.close(r));fs.writeFileSync(path.join(folder,'browser-results.json'),JSON.stringify(report,null,2));}
});
