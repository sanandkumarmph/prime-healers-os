const fs=require('node:fs'),path=require('node:path'),http=require('node:http'),assert=require('node:assert/strict');
const {test}=require('node:test');
const puppeteer=require('puppeteer');
const folder=process.env.PHOS_QUOTE_ENHANCEMENT_RENDER_DIR;
test('mixed quotation duration controls preserve prices and check only rental availability',{skip:!folder,timeout:120000},async()=>{
 const fixture=JSON.parse(fs.readFileSync(path.join(folder,'fixture.json'))),root=path.resolve(__dirname,'../../public');
 let origin,browser;const requests=[],errors=[];
 const server=http.createServer((req,res)=>{
  const url=new URL(req.url,origin);
  if(req.method==='POST'){
   let body='';req.on('data',chunk=>body+=chunk);req.on('end',()=>{requests.push({path:url.pathname,body:JSON.parse(body)});res.writeHead(200,{'Content-Type':'application/json'}).end(JSON.stringify({rows:[{name:'Oxygen concentrator',status:'Ready',requested:1,ready_now:5,forecast:5,shortfall:0,expected_back:0,committed:0,overdue:0,unavailable:0}]}));});return;
  }
  if(url.pathname==='/editor'||url.pathname==='/shared'){
   const html=fs.readFileSync(path.join(folder,url.pathname==='/editor'?'editor.html':'shared.html'),'utf8').replaceAll(new URL(fixture.url).origin,origin);
   res.writeHead(200,{'Content-Type':'text/html; charset=utf-8'}).end(html);return;
  }
  const file=path.resolve(root,'.'+decodeURIComponent(url.pathname));
  if(file.startsWith(root+path.sep)&&fs.existsSync(file)&&fs.statSync(file).isFile()){
   const type=path.extname(file)==='.css'?'text/css':path.extname(file)==='.js'?'text/javascript':path.extname(file)==='.svg'?'image/svg+xml':'application/octet-stream';
   res.writeHead(200,{'Content-Type':type});fs.createReadStream(file).pipe(res);return;
  }
  res.writeHead(200,{'Content-Type':'application/json'}).end(JSON.stringify({data:[],notifications:[],unread_count:0}));
 });
 try{
  await new Promise(r=>server.listen(0,'127.0.0.1',r));origin=`http://127.0.0.1:${server.address().port}`;
  browser=await puppeteer.launch({headless:true});const page=await browser.newPage();
  await page.setRequestInterception(true);page.on('request',r=>r.url().startsWith(origin+'/')||r.url().startsWith('data:')?r.continue():r.respond({status:200,contentType:'text/css',body:''}));
  page.on('pageerror',e=>errors.push(e.message));
  for(const width of [1440,768,390,320]){
   await page.setViewport({width,height:1000});await page.emulateTimezone('America/Los_Angeles');
   await page.goto(origin+'/editor',{waitUntil:'networkidle0'});
   const rental='[data-quote-line]:nth-child(2)',accessory='[data-quote-line]:nth-child(1)';
   assert.equal(await page.$eval(rental+' [data-line-field=rate]',e=>e.value),'1000');
   await page.select('#quote-duration','15');
   assert.equal(await page.$eval('[name=end_date]',e=>e.value),'2026-11-17');
   assert.equal(await page.$eval(rental+' [data-line-field=rate]',e=>e.value),'1000');
   await page.$eval('#quote-period-prices',e=>e.click());
   assert.equal(await page.$eval(rental+' [data-line-field=rate]',e=>e.value),'1200');
   assert.equal(await page.$eval(accessory+' [data-line-field=rate]',e=>e.value),'100');
   await page.$eval(rental+' [data-line-field=rate]',e=>{e.value='555';e.dispatchEvent(new Event('input',{bubbles:true}));});
   await page.select('#quote-duration','7');
   assert.equal(await page.$eval('[name=end_date]',e=>e.value),'2026-11-09');
   assert.equal(await page.$eval(rental+' [data-line-field=rate]',e=>e.value),'555');
   const response=page.waitForResponse(r=>r.request().method()==='POST');
   await page.$eval('[data-quote-rental-check]',e=>e.click());await response;
   assert.deepEqual(requests.at(-1).body.lines,[{product_id:String(fixture.rentalProduct),quantity:'1'}]);
   await page.waitForFunction(()=>document.querySelector('[data-quote-availability-results]').textContent.includes('Ready'));
   assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Page overflow '+width);
   if(width===390)await page.screenshot({path:path.join(folder,'quotation-editor-390.png'),fullPage:true});
   await page.goto(origin+'/shared',{waitUntil:'networkidle0'});
   const link=await page.$eval('[data-share-channel=whatsapp]',e=>e.href);
   const parsed=new URL(link);assert.equal(parsed.hostname,'wa.me');assert.equal(parsed.pathname,'/919876543210');
   const message=parsed.searchParams.get('text');assert.ok(message.includes('Nasal cannula')&&message.includes('Regards,\nMeera Sender'));
   assert.ok(message.includes('Refundable deposit')&&message.includes('₹1,750.00'));
   assert.equal(await page.$eval('#quote-message',e=>e.value),message.replaceAll(new URL(fixture.url).origin,origin));
  }
  assert.deepEqual(errors,[]);assert.equal(requests.length,4);
 }finally{if(browser)await browser.close();await new Promise(r=>server.close(r));fs.writeFileSync(path.join(folder,'quotation-browser-results.json'),JSON.stringify({requests,errors,productionAccessed:false},null,2));}
});
