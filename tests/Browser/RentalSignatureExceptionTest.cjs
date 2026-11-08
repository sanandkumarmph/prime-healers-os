const fs=require('node:fs'),path=require('node:path'),http=require('node:http');
const assert=require('node:assert/strict'),{test}=require('node:test'),puppeteer=require('puppeteer');
const folder=process.env.PHOS_SIGNATURE_EXCEPTION_RENDER_DIR;

test('Sales signature exception has explicit reason and acknowledgment without a fake signature',{skip:!folder,timeout:60000},async()=>{
 let browser,origin;const errors=[],writes=[];
 const server=http.createServer((req,res)=>{
  if(req.method!=='GET'){writes.push(req.method);res.writeHead(405).end();return;}
  const name=new URL(req.url,origin).pathname==='/approved'?'approved':'pending';
  res.writeHead(200,{'Content-Type':'text/html; charset=utf-8'}).end(fs.readFileSync(path.join(folder,name+'.html'),'utf8'));
 });
 try{
  await new Promise(r=>server.listen(0,'127.0.0.1',r));origin=`http://127.0.0.1:${server.address().port}`;
  browser=await puppeteer.launch({headless:true});const page=await browser.newPage();page.on('pageerror',e=>errors.push(e.message));
  for(const width of [1440,390,320]){
   await page.setViewport({width,height:900});await page.goto(origin+'/pending',{waitUntil:'networkidle0'});
   assert.equal(await page.$eval('[data-signature-exception] details',e=>e.open),false);
   await page.focus('summary');await page.keyboard.press('Enter');
   assert.equal(await page.$eval('[data-signature-exception] details',e=>e.open),true);
   assert.equal(await page.$eval('form',e=>e.checkValidity()),false);
   await page.type('textarea','Customer cannot use the signature pad; terms explained and agreed by phone.');
   assert.equal(await page.$eval('form',e=>e.checkValidity()),false);
   await page.click('[name=alternative_acceptance_confirmed]');assert.equal(await page.$eval('form',e=>e.checkValidity()),true);
   await page.evaluate(()=>document.querySelector('form').addEventListener('submit',e=>{e.preventDefault();window.approval=Object.fromEntries(new FormData(e.target));}));
   await page.click('button[type=submit]');
   const data=await page.evaluate(()=>window.approval);assert.equal(data.alternative_acceptance_confirmed,'1');assert.match(data.exception_context,/^[a-f0-9]{64}$/);
   assert.equal(data.signature_data,undefined);assert.equal(data.signed_at,undefined);
   assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
   await page.goto(origin+'/approved',{waitUntil:'networkidle0'});
   assert.ok(await page.$('[data-signature-exception-status]'));assert.equal(await page.$('form'),null);
   await page.click('summary');assert.match(await page.$eval('[data-signature-exception-audit]',e=>e.textContent),/Synthetic Sales Approver/);
   assert.ok(await page.evaluate(()=>document.body.textContent.includes('No digital signature was captured.')));
   assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
  }
  assert.deepEqual(errors,[]);assert.deepEqual(writes,[]);process.stdout.write('SIGNATURE_EXCEPTION_SALES_REASON_ACK_AUDIT_AND_NO_FAKE_SIGNATURE_CHECKS=3\n');
 }finally{if(browser)await browser.close();await new Promise(r=>server.close(r));}
});
