const fs=require('node:fs'),path=require('node:path'),http=require('node:http');
const assert=require('node:assert/strict'),{test}=require('node:test'),puppeteer=require('puppeteer');

test('rental sharing copies exact messages and offers protected direct desktop/mobile handoff',{timeout:60000},async()=>{
 let browser,origin;const writes=[];
 const app=http.createServer((req,res)=>{
  if(req.url==='/sharing.js'){res.writeHead(200,{'Content-Type':'text/javascript'}).end(fs.readFileSync(path.resolve(__dirname,'../../public/js/phos-sales-sharing.js')));return;}
  if(req.method==='POST'){
   writes.push(req.url);res.writeHead(200,{'Content-Type':'application/json','Cache-Control':'private, no-store'}).end(JSON.stringify({whatsapp_url:'https://wa.me/919876543210?text=Prepared%20terms%20with%20private%20link'}));return;
  }
  res.writeHead(200,{'Content-Type':'text/html'}).end(`<!doctype html><html><body>
   <div><a data-phos-whatsapp id="first" href="https://wa.me/919876543210?text=First%20message" target="_blank" rel="noopener noreferrer">First</a><button data-copy-whatsapp>Copy WhatsApp message</button></div>
   <div><a data-phos-whatsapp id="second" href="https://wa.me/919876543211?text=Second%20message" target="_blank" rel="noopener noreferrer">Second</a><button data-copy-whatsapp>Copy WhatsApp message</button></div>
   <form data-phos-whatsapp-prepare action="/prepare" method="post"><button>Prepare</button></form>
   <details data-terms-preview open><summary>Preview / copy rental terms</summary><button data-copy-terms>Copy rental terms</button><button data-copy-terms-message>Copy message with signing link</button><textarea data-terms-text hidden readonly>Exact rental terms\nSecond line</textarea><textarea data-terms-message hidden readonly>Rental terms with private signing link</textarea><p data-terms-copy-status role="status"></p></details>
   <script>window.__opened=[];window.__destinations=[];window.__copied=[];window.open=(...args)=>{window.__opened.push(args);return null;};Object.defineProperty(navigator,'clipboard',{configurable:true,value:{writeText:async value=>window.__copied.push(value)}});</script><script src="/sharing.js" defer></script></body></html>`);
 });
 try{
  await new Promise(r=>app.listen(0,'127.0.0.1',r));origin=`http://127.0.0.1:${app.address().port}`;
  browser=await puppeteer.launch({headless:true});const page=await browser.newPage();page.setDefaultTimeout(10000);
  const load=async()=>{
   await page.goto(origin,{waitUntil:'networkidle0'});
   // Observe the final protected destination after the production handler, keeping the test local.
   await page.evaluate(()=>document.addEventListener('click',event=>{
    const link=event.target.closest('a[data-phos-whatsapp]');if(!link)return;
    window.__destinations.push({href:link.href,target:link.target,rel:link.rel});event.preventDefault();
   }));
  };
  await load();
  await page.click('[data-copy-terms]');await page.waitForFunction(()=>window.__copied.length===1);
  assert.equal(await page.evaluate(()=>window.__copied[0]),'Exact rental terms\nSecond line');
  await page.click('[data-copy-terms-message]');await page.waitForFunction(()=>window.__copied.length===2);
  assert.equal(await page.evaluate(()=>window.__copied[1]),'Rental terms with private signing link');
  await page.click('#first');await page.click('#second');
  const destinations=await page.evaluate(()=>window.__destinations);
  for(const [index,phone] of ['919876543210','919876543211'].entries()){
   const url=new URL(destinations[index].href);assert.equal(url.hostname,'web.whatsapp.com');assert.equal(url.pathname,'/send');
   assert.equal(url.searchParams.get('phone'),phone);assert.equal(destinations[index].target,'_blank');assert.match(destinations[index].rel,/noopener/);assert.match(destinations[index].rel,/noreferrer/);
  }
  await page.click('#first + [data-copy-whatsapp]');await page.waitForFunction(()=>window.__copied.length===3);
  assert.equal(await page.evaluate(()=>window.__copied[2]),'First message','Copy still works after direct-compose URL conversion');
  await page.evaluate(()=>Object.defineProperty(navigator,'clipboard',{configurable:true,value:{writeText:async()=>{throw Error('Denied');}}}));
  await page.click('[data-copy-terms]');await page.waitForFunction(()=>!document.querySelector('[data-terms-text]').hidden);
  assert.equal(await page.$eval('[data-terms-text]',e=>e.selectionEnd-e.selectionStart),'Exact rental terms\nSecond line'.length);
  await page.click('#first + [data-copy-whatsapp]');await page.waitForSelector('[data-whatsapp-copy-text]');
  assert.equal(await page.$eval('[data-whatsapp-copy-text]',e=>e.value),'First message');
  await page.evaluate(()=>Object.defineProperty(navigator,'clipboard',{configurable:true,value:{writeText:async value=>window.__copied.push(value)}}));
  await page.click('form > button');await page.waitForSelector('form [data-phos-whatsapp]');
  assert.equal(await page.$eval('form > button',e=>e.hidden),true);
  assert.deepEqual(await page.evaluate(()=>window.__opened),[],'Preparation must not open an empty popup');
  await page.click('form [data-copy-whatsapp]');await page.waitForFunction(()=>window.__copied.length===4);
  assert.equal(await page.evaluate(()=>window.__copied[3]),'Prepared terms with private link');
  assert.equal(await page.$$eval('form [data-whatsapp-prepared-actions] a',links=>links.length),1);
  await page.evaluate(()=>Object.defineProperty(navigator,'clipboard',{configurable:true,value:{writeText:async()=>{throw Error('Denied');}}}));
  await page.click('form [data-copy-whatsapp]');await page.waitForSelector('form [data-whatsapp-copy-text]');
  assert.equal(await page.$$eval('form [data-whatsapp-prepared-actions] a',links=>links.length),1);
  assert.equal(await page.$$eval('form [data-whatsapp-prepared-actions] [data-copy-whatsapp]',buttons=>buttons.length),1);
  assert.deepEqual(writes,['/prepare']);
  for(const ua of ['Mozilla/5.0 (Linux; Android 13) AppleWebKit/537.36 Mobile Safari/537.36','Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148']){
   await page.setUserAgent(ua);await load();await page.click('#first');
   const destination=await page.evaluate(()=>window.__destinations[0]);assert.equal(new URL(destination.href).hostname,'wa.me');assert.equal(destination.target,'_self');
   assert.deepEqual(await page.evaluate(()=>window.__opened),[]);
  }
  process.stdout.write('RENTAL_TERMS_COPY_FALLBACK_DESKTOP_ANDROID_IPHONE_AND_NO_EMPTY_POPUPS_VERIFIED=true\n');
 }finally{if(browser)await browser.close();app.closeAllConnections();await new Promise(r=>app.close(r));}
});
