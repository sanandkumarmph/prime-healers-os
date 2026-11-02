const fs=require('node:fs'),path=require('node:path'),http=require('node:http'),assert=require('node:assert/strict');
const {test}=require('node:test'),puppeteer=require('puppeteer'),folder=process.env.PHOS_SIMPLE_REFILL_RENDER_DIR;
test('refill popup stays on rental, needs no serial, and delivery tasks show no financial fields',{skip:!folder,timeout:180000},async()=>{
 const publicRoot=path.resolve(__dirname,'../../public'),posts=[],errors=[];let origin,browser;
 const text=name=>fs.readFileSync(path.join(folder,name),'utf8').replaceAll('http://localhost',origin).replaceAll('http://127.0.0.1:8000',origin);
 const server=http.createServer((req,res)=>{
  const url=new URL(req.url,origin);
  if(req.method==='POST'){
   let body='';req.on('data',chunk=>body+=chunk);req.on('end',()=>{posts.push({path:url.pathname,body});res.writeHead(200,{'Content-Type':'application/json'}).end(JSON.stringify({html:text('booked-fragment.html')}));});return;
  }
  if(/^\/rentals\/\d+\/cylinder-refills$/.test(url.pathname)){res.writeHead(200,{'Content-Type':'application/json'}).end(JSON.stringify({html:text('popup-fragment.html')}));return;}
  if(/^\/rentals\/\d+$/.test(url.pathname)){res.writeHead(200,{'Content-Type':'text/html'}).end(text('rental.html'));return;}
  if(url.pathname==='/test-tasks'){res.writeHead(200,{'Content-Type':'text/html'}).end(text('tasks.html'));return;}
  const file=path.resolve(publicRoot,'.'+decodeURIComponent(url.pathname));
  if(file.startsWith(publicRoot+path.sep)&&fs.existsSync(file)&&fs.statSync(file).isFile()){
   res.writeHead(200,{'Content-Type':({'.js':'text/javascript','.css':'text/css','.svg':'image/svg+xml'})[path.extname(file)]||'application/octet-stream'});fs.createReadStream(file).pipe(res);return;}
  res.writeHead(200,{'Content-Type':'application/json'}).end(JSON.stringify({data:[],notifications:[],unread_count:0}));
 });
 try{
  await new Promise(r=>server.listen(0,'127.0.0.1',r));origin=`http://127.0.0.1:${server.address().port}`;
  browser=await puppeteer.launch({headless:true});const page=await browser.newPage();page.on('pageerror',e=>errors.push(e.message));
  await page.setRequestInterception(true);page.on('request',r=>r.url().startsWith(origin+'/')||r.url().startsWith('data:')?r.continue():r.respond({status:200,contentType:'text/css',body:''}));
  for(const width of [320,390,768,1440]){
   await page.setViewport({width,height:900});await page.goto(origin+'/rentals/1',{waitUntil:'networkidle0'});
   if(width<768)await page.evaluate(()=>{const el=[...document.querySelectorAll('.ph-rental-mobile-accordion')].find(d=>d.querySelector('summary')?.textContent.includes('Operations'));if(el)el.open=true;});
   await page.evaluate(()=>[...document.querySelectorAll('[data-open-cylinder-refill]')].find(el=>el.getBoundingClientRect().height>0).click());
   await page.waitForSelector('[data-cylinder-refill-dialog][open] .cr-book');
   assert.equal(new URL(page.url()).pathname,'/rentals/1');
   assert.ok(await page.$eval('[data-cylinder-refill-dialog]',el=>el.scrollWidth<=el.clientWidth+1),`popup overflow at ${width}`);
   assert.equal(await page.$eval('[name=serial_reference]',el=>el.required),false);
   assert.equal(await page.$('[name=outgoing_cylinder_id]'),null);assert.equal(await page.$('[name=incoming_cylinder_id]'),null);
   assert.match(await page.$eval('.cr-book [name=product_id]',el=>el.selectedOptions[0].textContent),/4.5 litres/);
   assert.match(await page.$eval('[data-refill-issued]',el=>el.textContent),/Previously issued: 1/);
   if(width===390)await page.screenshot({path:path.join(folder,'simple-refill-popup-390.png'),fullPage:true});
   await page.type('.cr-book [name=refill_charge]','600');await page.$eval('.cr-book [name=transport_charge]',el=>el.value='100');
   const staff=await page.$eval('.cr-book [name=assigned_user_id]',el=>[...el.options].find(o=>o.value).value);await page.select('.cr-book [name=assigned_user_id]',staff);
   await page.$eval('.cr-book .cr-primary',el=>el.click());await page.waitForSelector('[data-cylinder-refill-dialog] .cr-card');
   assert.equal(new URL(page.url()).pathname,'/rentals/1');
   assert.match(await page.$eval('[data-cylinder-refill-body]',el=>el.textContent),/1 empty cylinder\(s\) still need to be received back/);
   assert.equal(posts.length,[320,390,768,1440].indexOf(width)+1);
   await page.click('[data-close-cylinder-refill]');assert.equal(await page.$eval('[data-cylinder-refill-dialog]',el=>el.open),false);
   await page.goto(origin+'/test-tasks',{waitUntil:'networkidle0'});
   assert.equal(await page.$('[name=refill_charge]'),null);assert.equal(await page.$('[name=warehouse_id]'),null);
   assert.match(await page.$eval('[data-cylinder-task-content]',el=>el.textContent),/Refill delivered/);
   assert.match(await page.$eval('[data-cylinder-task-content]',el=>el.textContent),/Empty cylinder picked up/);
   assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`task overflow at ${width}`);
  }
  assert.deepEqual(errors,[]);for(const post of posts){assert.ok(post.path.endsWith('/cylinder-refills'));assert.match(post.body,/name="refill_charge"\r\n\r\n600/);}
 }finally{if(browser)await browser.close();await new Promise(r=>server.close(r));}
});
