const fs=require('node:fs'),path=require('node:path'),http=require('node:http');
const assert=require('node:assert/strict'),{test}=require('node:test'),puppeteer=require('puppeteer');
const folder=process.env.PHOS_COLLECTION_RENDER_DIR;

test('one collection chooser clears stale delivery instructions and keeps Sales available at pickup',{skip:!folder,timeout:60000},async()=>{
 let browser,origin;const writes=[],errors=[];
 const server=http.createServer((req,res)=>{
  if(req.method!=='GET'){writes.push(req.method);res.writeHead(405).end();return;}
  if(req.url==='/collection.js'){res.writeHead(200,{'Content-Type':'text/javascript'}).end(fs.readFileSync(path.resolve(__dirname,'../../public/js/phos-order-collection.js')));return;}
  const kind=req.url.includes('sale')?'sale':'rental',html=fs.readFileSync(path.join(folder,kind+'.html'),'utf8');
  const component=html.match(/<section class="[^"]*order-collection-setup"[\s\S]*?<\/section>/)?.[0];
  assert.ok(component,'Actual Blade collection component available');
  const mode=kind==='sale'?'<select name="delivery_responsibility" id="assignment"><option value="ph_internal_delivery">Internal</option><option value="customer_pickup">Pickup</option><option value="vendor_delivery">Vendor</option><option value="third_party">Third party</option></select><span id="saleOrderTotal">1000.00</span>'
   :'<select name="delivery_assignment_type" id="assignment"><option value="ph_internal">Internal</option><option value="customer_pickup">Pickup</option><option value="vendor">Vendor</option><option value="third_party">Third party</option></select><select name="delivery_staff_id"><option value="">Select staff</option><option value="user:3" data-collection-eligible="1">Synthetic operator</option><option value="user:1" data-collection-eligible="0">Admin</option></select><span data-summary-net-total>1000.00</span>';
  res.writeHead(200,{'Content-Type':'text/html; charset=utf-8'}).end('<!doctype html><html><body><form>'+mode+component+'</form><script src="/collection.js" defer></script></body></html>');
 });
 try{
  await new Promise(r=>server.listen(0,'127.0.0.1',r));origin=`http://127.0.0.1:${server.address().port}`;
  browser=await puppeteer.launch({headless:true});const page=await browser.newPage();page.on('pageerror',e=>errors.push(e.message));
  for(const kind of ['rental','sale'])for(const width of [1440,390,320]){
   await page.setViewport({width,height:900});await page.goto(origin+'/'+kind,{waitUntil:'networkidle0'});
   const set=async(name,value)=>page.$eval(`[name="${name}"]`,(element,value)=>{element.value=value;element.dispatchEvent(new Event('change',{bubbles:true}));},value);
   const values=()=>page.$eval('form',f=>Object.fromEntries(new FormData(f)));
   assert.equal(await page.$$eval('[data-order-collection]',e=>e.length),1);
   assert.equal((await values()).collection_enabled,undefined);
   await set('collection_owner','delivery');
   if(kind==='rental')await set('delivery_staff_id','user:3');else await set('delivery_operator_id','3');
   assert.equal((await values()).collection_enabled,'1');assert.equal((await values()).collection_amount,'1000.00');
   await set('collection_amount','125.50');await set('collection_instructions','Synthetic instruction');
   await set('collection_methods','both');assert.equal((await values()).collection_amount,'125.50');
   for(const mode of kind==='rental'?['customer_pickup','vendor','third_party']:['customer_pickup','vendor_delivery','third_party']){
    await set(kind==='rental'?'delivery_assignment_type':'delivery_responsibility',mode);
    assert.equal(await page.$('#collection_owner option[value="delivery"]'),null);
    const data=await values();assert.equal(data.collection_owner,'none');
    for(const name of ['collection_enabled','collection_amount','collection_methods','collection_instructions','delivery_operator_id','collection_submission_token'])assert.equal(data[name],undefined,name+' omitted for '+mode);
    assert.ok(await page.$('#collection_owner option[value="sales"]'));
   }
   await set('collection_owner','sales');assert.equal(await page.$eval('[data-sales-collection-fields]',e=>e.disabled),false);
   assert.equal(await page.$eval('form',f=>f.checkValidity()),false,'Sales assignment required');
   await set('collection_mode','cash');await set('collection_assignee_id','2');
   let data=await values();assert.equal(data.collection_mode,'cash');assert.equal(data.collection_assignee_id,'2');assert.equal(data.collection_enabled,undefined);
   assert.equal(await page.$eval('form',f=>f.checkValidity()),true);
   await set(kind==='rental'?'delivery_assignment_type':'delivery_responsibility',kind==='rental'?'ph_internal':'ph_internal_delivery');
   await set('collection_owner','delivery');
   data=await values();assert.equal(data.collection_mode,undefined);assert.equal(data.collection_assignee_id,undefined);
   assert.equal(data.collection_amount,'1000.00');assert.equal(data.collection_instructions,'');
   if(kind==='rental'){
    await set('delivery_staff_id','user:1');assert.equal(await page.$eval('form',f=>f.checkValidity()),false,'Admin is not Delivery operator');
    await set('delivery_staff_id','user:3');assert.equal(await page.$eval('form',f=>f.checkValidity()),true);
   }
   await set('collection_owner','none');data=await values();assert.equal(data.collection_enabled,undefined);assert.equal(data.collection_mode,undefined);
   assert.equal(await page.$eval('[data-order-collection-fields]',e=>e.disabled),true);
  }
  assert.deepEqual(errors,[]);assert.deepEqual(writes,[]);
  process.stdout.write('UNIFIED_COLLECTION_INTERNAL_VENDOR_THIRDPARTY_PICKUP_AND_SALES_CHECKS_PASSED=6 NO_PAYMENTS_RECORDED=true\n');
 }finally{if(browser)await browser.close();await new Promise(r=>server.close(r));}
});
