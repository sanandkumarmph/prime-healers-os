const fs=require('node:fs'),path=require('node:path'),http=require('node:http'),assert=require('node:assert/strict');
const {test}=require('node:test'),puppeteer=require('puppeteer');
const folder=process.env.PHOS_PROSPECT_RENDER_DIR;
test('enquiry-first capture and customer-free quotation review work on phones',{skip:!folder,timeout:120000},async()=>{
 const root=path.resolve(__dirname,'../../public');let origin,browser;const errors=[],writes=[];
 const server=http.createServer((req,res)=>{
  if(req.method!=='GET'){writes.push(req.method);res.writeHead(405).end();return;}
  const url=new URL(req.url,origin),name=url.pathname.slice(1);
  if(['capture','enquiry','review','shared','order'].includes(name)){
   const html=fs.readFileSync(path.join(folder,name+'.html'),'utf8').replaceAll('http://127.0.0.1:8000',origin).replaceAll('http://localhost',origin);
   res.writeHead(200,{'Content-Type':'text/html; charset=utf-8'}).end(html);return;
  }
  const file=path.resolve(root,'.'+decodeURIComponent(url.pathname));
  if(file.startsWith(root+path.sep)&&fs.existsSync(file)&&fs.statSync(file).isFile()){
   res.writeHead(200,{'Content-Type':({'.js':'text/javascript','.css':'text/css','.svg':'image/svg+xml'})[path.extname(file)]||'application/octet-stream'});fs.createReadStream(file).pipe(res);return;
  }
  res.writeHead(200,{'Content-Type':'application/json'}).end(JSON.stringify({data:[],notifications:[],unread_count:0}));
 });
 try{
  await new Promise(r=>server.listen(0,'127.0.0.1',r));origin=`http://127.0.0.1:${server.address().port}`;
  browser=await puppeteer.launch({headless:true});const page=await browser.newPage();page.on('pageerror',e=>errors.push(e.message));
  await page.setRequestInterception(true);page.on('request',r=>r.url().startsWith(origin+'/')||r.url().startsWith('data:')?r.continue():r.respond({status:200,contentType:'text/css',body:''}));
  for(const width of [320,390,768,1440]){
   await page.setViewport({width,height:1000});
   await page.goto(origin+'/capture',{waitUntil:'networkidle0'});
   assert.deepEqual(await page.$$eval('#enquiry-form button[name=next]',buttons=>buttons.map(b=>b.value)),['enquiry']);
   assert.equal(await page.$('#salesQuickCustomerModal'),null);
   await page.goto(origin+'/enquiry',{waitUntil:'networkidle0'});
   assert.ok(await page.$eval('.sw',e=>e.textContent.includes('Follow-ups')&&e.textContent.includes('Create quotation')&&e.textContent.includes('Call before preparing quotation')));
   assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Enquiry overflow at '+width);
   await page.goto(origin+'/review',{waitUntil:'networkidle0'});
   assert.equal(await page.$eval('#customer_id',e=>e.value),'');
   assert.equal(await page.$eval('#quotation-review',e=>e.open),true);
   assert.ok(await page.$eval('#quotation-review',e=>e.textContent.includes('Synthetic prospect')));
   await page.$eval('[data-close-review]',b=>b.click());
   await page.evaluate(()=>{
    window.prospectSubmit=null;
    document.getElementById('quotation-form').addEventListener('submit',e=>{e.preventDefault();window.prospectSubmit=Object.fromEntries(new FormData(e.target,e.submitter));});
   });
   await page.$eval('#quotation-form button[value=review]',b=>b.click());
   const submitted=await page.evaluate(()=>window.prospectSubmit);
   assert.equal(submitted.next,'review');assert.equal(submitted.customer_id,'');
   assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Quote overflow at '+width);
   if(width===390)await page.screenshot({path:path.join(folder,'prospect-quotation-390.png'),fullPage:true});
   await page.goto(origin+'/shared',{waitUntil:'networkidle0'});
   const url=new URL(await page.$eval('[data-share-channel=whatsapp]',e=>e.href));
   assert.equal(url.hostname,'wa.me');assert.equal(url.pathname,'/919876543210');assert.ok(url.searchParams.get('text').includes('Synthetic prospect'));
   await page.goto(origin+'/order',{waitUntil:'networkidle0'});
   assert.equal(await page.$eval('[name=quotation_recipient_confirmed]',e=>({required:e.required,form:e.form.id})).then(x=>x.form),'rentalCreateForm');
   assert.equal(await page.$eval('[name=quotation_recipient_confirmed]',e=>e.required),true);
  }
  assert.deepEqual(errors,[]);assert.deepEqual(writes,[]);
 }finally{if(browser)await browser.close();await new Promise(r=>server.close(r));}
});
