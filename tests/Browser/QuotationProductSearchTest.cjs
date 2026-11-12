const fs=require('node:fs'),path=require('node:path'),http=require('node:http');
const assert=require('node:assert/strict'),{test}=require('node:test'),puppeteer=require('puppeteer');
const folder=process.env.PHOS_PRODUCT_SEARCH_RENDER_DIR;

test('Sales and Admin search every quotation product line without changing prices while typing',{skip:!folder,timeout:120000},async()=>{
 const root=path.resolve(__dirname,'../../public'),report={checks:[],errors:[],writes:[]};let browser,origin;
 const server=http.createServer((req,res)=>{
  if(req.method!=='GET'){report.writes.push(req.method);res.writeHead(405).end();return;}
  const url=new URL(req.url,origin),name=url.pathname.slice(1);
  if(['super_admin','sales'].includes(name)){
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
  for(const role of ['super_admin','sales'])for(const width of [1440,768,390,320]){
   await page.setViewport({width,height:1000});await page.goto(origin+'/'+role,{waitUntil:'networkidle0'});
   const search='#product-0-search';await page.$eval(search,e=>e.scrollIntoView({block:'center'}));await page.click(search);await page.type(search,'OX-5');
   assert.equal(await page.$eval('#draft-status',e=>e.textContent.trim()),'Not saved yet');
   assert.equal(await page.$$eval('#product-0-results button',buttons=>buttons.length),1);
   await page.click('#valid_until');assert.equal(await page.$eval('#draft-status',e=>e.textContent.trim()),'Not saved yet');
   await page.click(search);await page.type(search,'OX-5');
   await page.keyboard.press('ArrowDown');await page.keyboard.press('Enter');
   assert.equal(await page.$eval(search,e=>e.value),'Oxygen concentrator Mini');
   assert.equal(await page.$eval('[data-line-field=rate]',e=>e.value),'3000');
   await page.click(search);await page.type(search,'no-such-product');assert.match(await page.$eval('#product-0-results',e=>e.textContent),/No matching/);
   await page.keyboard.press('Escape');assert.equal(await page.$eval(search,e=>e.value),'Oxygen concentrator Mini');
   await page.$eval('#add-quote-line',e=>e.click());await page.waitForSelector('#product-1-search');
   await page.select('#line-type-1','sale');await page.$eval('#product-1-search',e=>e.scrollIntoView({block:'center'}));
   await page.click('#product-1-search');await page.type('#product-1-search','Mini');assert.equal(await page.$$eval('#product-1-results button',e=>e.length),0);
   await page.$eval('#product-1-search',e=>{e.value='cannula';e.dispatchEvent(new Event('input',{bubbles:true}));});
   await page.$eval('#product-1-results button',e=>e.click());
   assert.equal(await page.$eval('#rate-1',e=>e.value),'150');assert.equal(await page.$eval('#preview-total',e=>e.textContent),'3,950.00');
   assert.equal(Number(await page.$eval('#transport',e=>e.value)),800);
   assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'No horizontal overflow '+role+width);
   assert.equal(await page.$eval('#product-1-search',e=>e.getBoundingClientRect().width>=100),true);
   if(role==='sales'&&[1440,390].includes(width))await page.screenshot({path:path.join(folder,'product-search-'+width+'.png')});
   report.checks.push({role,width,dynamicProductLine:true,priceSelection:true,queryDidNotDirty:true});
  }
  assert.deepEqual(report.errors,[]);assert.deepEqual(report.writes,[]);process.stdout.write('QUOTATION_PRODUCT_SEARCH_CHECKS='+report.checks.length+' ERRORS=0 MUTATIONS=0\n');
 }finally{if(browser)await browser.close();await new Promise(r=>server.close(r));fs.writeFileSync(path.join(folder,'browser-results.json'),JSON.stringify(report,null,2));}
});
