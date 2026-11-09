const fs=require('node:fs'),path=require('node:path'),http=require('node:http');
const assert=require('node:assert/strict'),{test}=require('node:test'),puppeteer=require('puppeteer');
const folder=process.env.PHOS_QUOTE_DEFAULTS_RENDER_DIR;

test('quotation defaults follow products and units while preserving negotiated values',{skip:!folder,timeout:150000},async()=>{
 const root=path.resolve(__dirname,'../../public'),report={checks:[],errors:[],writes:[]};let browser,origin;
 const server=http.createServer((req,res)=>{
  if(req.method!=='GET'){report.writes.push(req.method);res.writeHead(405).end();return;}
  const url=new URL(req.url,origin),name=url.pathname.slice(1);
  if(['super_admin','sales','saved-zero','saved-exclusive','validation-zero'].includes(name)){
   res.writeHead(200,{'Content-Type':'text/html; charset=utf-8'}).end(fs.readFileSync(path.join(folder,name+'.html'),'utf8').replaceAll('http://127.0.0.1:8000',origin));return;
  }
  const file=path.resolve(root,'.'+decodeURIComponent(url.pathname));
  if(file.startsWith(root+path.sep)&&fs.existsSync(file)&&fs.statSync(file).isFile()){
   res.writeHead(200,{'Content-Type':({'.js':'text/javascript','.css':'text/css','.svg':'image/svg+xml'})[path.extname(file)]||'application/octet-stream'});fs.createReadStream(file).pipe(res);return;
  }
  res.writeHead(200,{'Content-Type':'application/json'}).end(JSON.stringify({data:[],notifications:[],unread_count:0}));
 });
 try{
  await new Promise(r=>server.listen(0,'127.0.0.1',r));origin=`http://127.0.0.1:${server.address().port}`;
  browser=await puppeteer.launch({headless:true});const page=await browser.newPage();page.on('pageerror',e=>report.errors.push(e.message));
  await page.setRequestInterception(true);page.on('request',r=>r.url().startsWith(origin+'/')||r.url().startsWith('data:')?r.continue():r.respond({status:200,body:''}));
  const input=async(selector,value)=>page.$eval(selector,(e,v)=>{e.value=v;e.dispatchEvent(new Event('input',{bubbles:true}));},String(value));
  const product=async(selector,text)=>page.$eval(selector,(e,t)=>{e.value=[...e.options].find(o=>o.textContent.toLowerCase().includes(t))?.value||'';e.dispatchEvent(new Event('change',{bubbles:true}));},text);
  const val=selector=>page.$eval(selector,e=>e.value);
  for(const role of ['super_admin','sales'])for(const width of [1440,390,320]){
   await page.setViewport({width,height:1000});await page.goto(origin+'/'+role,{waitUntil:'networkidle0'});
   assert.equal(Number(await val('#deposit')),2000);assert.equal(Number(await val('#transport')),800);
   assert.equal(await page.$eval('#quote-use-suggested-transport',e=>getComputedStyle(e).display),'none');
   await product('#product-0','small concentrator');assert.equal(Number(await val('#rate-0')),3500);assert.equal(await val('#mode-0'),'inclusive');assert.equal(Number(await val('#tax-0')),5);
   assert.equal(await page.$eval('#preview-total',e=>e.textContent),'4,300.00');
   await input('#qty-0',3);assert.equal(Number(await val('#transport')),800);
   await input('#qty-0',4);assert.equal(Number(await val('#transport')),2500);
   await input('#qty-0',1);assert.equal(Number(await val('#transport')),800);
   await product('#product-0','hospital bed');assert.equal(Number(await val('#transport')),2500);
   await product('#product-0','small concentrator');assert.equal(Number(await val('#transport')),800);
   await input('#transport',0);await input('#deposit',1500);await input('#qty-0',4);
   assert.equal(Number(await val('#transport')),0);assert.equal(Number(await val('#deposit')),1500);
   assert.notEqual(await page.$eval('#quote-use-suggested-transport',e=>getComputedStyle(e).display),'none');
   await page.$eval('#quote-use-suggested-transport',e=>e.click());assert.equal(Number(await val('#transport')),2500);
   await input('#qty-0',3);await page.$eval('#add-quote-line',e=>e.click());await page.waitForSelector('#product-1-search');await page.select('#line-type-1','sale');
   await product('#product-1','nasal cannula');assert.equal(Number(await val('#transport')),2500);assert.equal(Number(await val('#rate-1')),150);assert.equal(await val('#mode-1'),'inclusive');
   await page.$eval('[data-quote-line]:last-child [data-remove-line]',e=>e.click());assert.equal(Number(await val('#transport')),800);
   await input('#rate-0',1234);await input('#qty-0',2);assert.equal(Number(await val('#rate-0')),1234);
   await page.select('#kind','sale');assert.equal(await page.$eval('[data-rental-dates]:has(#deposit)',e=>e.hidden),true);assert.equal(Number(await val('#deposit')),1500);
   assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
   report.checks.push({role,width,unitsBoundary:true,heavy:true,mixedLines:true,manualPreserved:true});
  }
  for(const fixture of ['saved-zero','saved-exclusive','validation-zero']){
   await page.setViewport({width:390,height:1000});await page.goto(origin+'/'+fixture,{waitUntil:'networkidle0'});
   const initialTransport=await val('#transport'),initialDeposit=await val('#deposit'),initialRate=await val('#rate-0'),mode=await val('#mode-0');
   await input('#qty-0',4);
   assert.equal(await val('#transport'),initialTransport);assert.equal(await val('#deposit'),initialDeposit);assert.equal(await val('#rate-0'),initialRate);assert.equal(await val('#mode-0'),mode);
   report.checks.push({fixture,savedValuesPreserved:true});
  }
  // A fresh sale has no deposit; returning to Rental restores the untouched standard deposit.
  await page.goto(origin+'/sales',{waitUntil:'networkidle0'});await page.select('#kind','sale');assert.equal(Number(await val('#deposit')),0);
  await page.select('#kind','rental');assert.equal(Number(await val('#deposit')),2000);report.checks.push({rentalOnlyDeposit:true});
  await input('#start_date','2026-11-09');await input('#end_date','2026-11-23');await product('#product-0','small concentrator');
  assert.equal(Number(await val('#rate-0')),2000);assert.equal(await val('#mode-0'),'inclusive');
  await input('#rate-0',1750);await input('#end_date','2026-12-08');assert.equal(Number(await val('#rate-0')),1750);
  report.checks.push({savedPeriodPriceUsed:true,negotiatedPriceKeptOnDateChange:true});
  assert.deepEqual(report.errors,[]);assert.deepEqual(report.writes,[]);process.stdout.write('QUOTATION_COMMERCIAL_DEFAULT_BROWSER_CHECKS='+report.checks.length+' ERRORS=0 MUTATIONS=0\n');
 }finally{if(browser)await browser.close();await new Promise(r=>server.close(r));fs.writeFileSync(path.join(folder,'browser-results.json'),JSON.stringify(report,null,2));}
});
