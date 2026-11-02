const fs=require('node:fs'),path=require('node:path'),http=require('node:http'),assert=require('node:assert/strict');
const {test}=require('node:test'),puppeteer=require('puppeteer');
const folder=process.env.PHOS_CYLINDER_RENDER_DIR;
test('cylinder service forms fit phones and capture and clear a named signature',{skip:!folder,timeout:180000},async()=>{
 const root=path.resolve(__dirname,'../../public');let origin,browser;const errors=[],writes=[];
 const server=http.createServer((req,res)=>{
  if(req.method!=='GET'){writes.push(req.method);res.writeHead(405).end();return;}
  const url=new URL(req.url,origin),name=url.pathname.slice(1);
  if(['job','create','create-empty','stock','stock-register','index','rental'].includes(name)){
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
   await page.setViewport({width,height:950,deviceScaleFactor:2});
   for(const name of ['create','create-empty','stock','stock-register','index','rental','job']){
    await page.goto(origin+'/'+name,{waitUntil:'networkidle0'});
    assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`${name} overflows at ${width}`);
    if(name==='create-empty'){
     assert.equal(await page.$$eval('.cyl-setup-steps li',els=>els.length),3);
     assert.ok(await page.$('.cyl-setup-actions a[href*="register=1"]'));
     assert.equal(await page.$('form[action*="cylinder-services"]'),null);
     if(width===390)await page.screenshot({path:path.join(folder,'cylinder-setup-390.png'),fullPage:true});
    }
    if(name==='stock-register')assert.equal(await page.$eval('#cylinder-registration',el=>el.open),true);
    if(name==='rental'){
     if(width<768)await page.evaluate(()=>{const el=[...document.querySelectorAll('.ph-rental-mobile-accordion')].find(el=>el.querySelector('summary')?.textContent.includes('Operations'));el.open=true;});
     else await page.$eval('.rental-tab-row a[href="#rental-operations-workspace"]',el=>el.click());
     const controls=await page.$$eval('.rcc-cylinder-panel',els=>els.filter(el=>el.getBoundingClientRect().width>0&&el.getBoundingClientRect().height>0).map(el=>({
      text:el.textContent,links:[...el.querySelectorAll('a')].map(a=>{const r=a.getBoundingClientRect(),s=getComputedStyle(a);return {left:r.left,right:r.right,height:r.height,font:parseFloat(s.fontSize),background:s.backgroundColor,color:s.color,href:a.getAttribute('href')};})
     })));
     assert.equal(controls.length,1,`one visible cylinder panel at ${width}`);
     assert.match(controls[0].text,/Book refill \/ exchange/);
     assert.equal(controls[0].links.length,2);
     for(const a of controls[0].links){assert.ok(a.height>=44&&a.font>=14,`readable touch button at ${width}`);assert.ok(a.left>=0&&a.right<=width+1);}
     assert.notEqual(controls[0].links[0].background,controls[0].links[1].background);
     assert.ok(controls[0].links[0].href.includes('/cylinder-services/create'));
     const panel=await page.evaluateHandle(()=>[...document.querySelectorAll('.rcc-cylinder-panel')].find(el=>el.getBoundingClientRect().height>0));
     if([390,1440].includes(width))await panel.screenshot({path:path.join(folder,`cylinder-rental-actions-${width}.png`)});
    }
   }
   await page.$eval('details.cyl-card',d=>{d.open=true;});
   const canvas=await page.$('[data-cylinder-signature]');await canvas.scrollIntoView();
   const box=await canvas.boundingBox();assert.ok(box.width>160);
   await page.mouse.move(box.x+20,box.y+80);await page.mouse.down();await page.mouse.move(box.x+90,box.y+30,{steps:8});await page.mouse.move(box.x+160,box.y+100,{steps:8});await page.mouse.up();
   assert.ok((await page.$eval('[name=signature_data]',e=>e.value)).startsWith('data:image/png;base64,'));
   await page.$eval('[data-signature-clear]',e=>e.click());assert.equal(await page.$eval('[name=signature_data]',e=>e.value),'');
   if(width===390)await page.screenshot({path:path.join(folder,'cylinder-job-390.png'),fullPage:true});
  }
  assert.deepEqual(errors,[]);assert.deepEqual(writes,[]);
 }finally{if(browser)await browser.close();await new Promise(r=>server.close(r));}
});
