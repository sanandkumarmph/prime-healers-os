const fs=require('node:fs'),path=require('node:path'),http=require('node:http');
const assert=require('node:assert/strict'),{test}=require('node:test'),puppeteer=require('puppeteer');
const folder=process.env.PHOS_ENQUIRY_DETAILS_RENDER_DIR;
const fixtures=['noquote','draft','unsigned','signed','ready','lost','readonly'];

test('compact enquiry details preserve readable actions, workflow and disclosure on phones',{skip:!folder,timeout:210000},async()=>{
 const root=path.resolve(__dirname,'../../public'),report={checks:[],errors:[],writes:[]};let browser,origin,page;
 const server=http.createServer((req,res)=>{
  if(req.method!=='GET'){report.writes.push(req.method);res.writeHead(405).end();return;}
  const url=new URL(req.url,origin),fixture=url.pathname.slice(1);
  if(fixtures.includes(fixture)){
   res.writeHead(200,{'Content-Type':'text/html; charset=utf-8'}).end(fs.readFileSync(path.join(folder,fixture+'.html'),'utf8').replaceAll('http://127.0.0.1:8000',origin));return;
  }
  const file=path.resolve(root,'.'+decodeURIComponent(url.pathname));
  if(file.startsWith(root+path.sep)&&fs.existsSync(file)&&fs.statSync(file).isFile()){
   res.writeHead(200,{'Content-Type':({'.js':'text/javascript','.css':'text/css','.svg':'image/svg+xml'})[path.extname(file)]||'application/octet-stream'});fs.createReadStream(file).pipe(res);return;
  }
  res.writeHead(200,{'Content-Type':'application/json'}).end(JSON.stringify({data:[],notifications:[],unread_count:0}));
 });
 try{
  await new Promise(r=>server.listen(0,'127.0.0.1',r));origin=`http://127.0.0.1:${server.address().port}`;
  browser=await puppeteer.launch({headless:true});page=await browser.newPage();
  const click=async selector=>{await page.$eval(selector,e=>e.scrollIntoView({block:'center',inline:'nearest'}));await page.click(selector);};
  page.on('pageerror',e=>report.errors.push(e.message));await page.setRequestInterception(true);
  page.on('request',r=>r.url().startsWith(origin+'/')||r.url().startsWith('data:')?r.continue():r.respond({status:200,body:''}));
  for(const width of (process.env.PHOS_DETAILS_WIDTH?[Number(process.env.PHOS_DETAILS_WIDTH)]:[1440,1280,1024,768,480,390,360,320]))for(const fixture of (process.env.PHOS_DETAILS_FIXTURE?[process.env.PHOS_DETAILS_FIXTURE]:fixtures)){
   report.current={width,fixture};
   await page.setViewport({width,height:1000});await page.goto(origin+'/'+fixture,{waitUntil:'networkidle0'});
   const layout=await page.evaluate(()=>{
    const root=document.querySelector('[data-enquiry-detail]'),r=root.getBoundingClientRect();
    const bounds=e=>{const r=e.getBoundingClientRect();return {left:r.left,right:r.right,width:r.width,height:r.height};};
    return {viewport:innerWidth,pageWidth:document.documentElement.scrollWidth,root:bounds(root),
     header:bounds(root.querySelector('.swd-header')),contact:bounds(root.querySelector('#enquiry-details')),
     headerButtons:[...root.querySelectorAll('.swd-header .sw-actions .sw-btn')].map(bounds),
     nav:[...root.querySelectorAll('nav[aria-label="Sales progress"] a')].map(a=>({text:a.textContent.trim(),href:a.getAttribute('href'),...bounds(a)})),
     panels:[...root.querySelectorAll('.sw-panel')].map(bounds),
     primary:root.querySelector('.swd-header .sw-primary')?.textContent.trim(),
     unreadable:[...root.querySelectorAll('input:not([type=hidden]),select,textarea')].filter(e=>e.getBoundingClientRect().width>0&&e.getBoundingClientRect().width<100).map(e=>e.id)};
   });
   assert.ok(layout.pageWidth<=width+1,JSON.stringify({width,fixture,layout}));
   assert.equal(layout.nav.length,4);assert.deepEqual(layout.nav.map(a=>a.text),['1. Enquiry','2. Quotation','3. Response','4. Order']);
   assert.ok(layout.contact.width>=240,'Contact width '+JSON.stringify({width,fixture,layout}));
   assert.ok(layout.header.height<(width>600?170:270),'Compact heading '+JSON.stringify({width,fixture,layout}));
   if(layout.headerButtons.length>1)assert.ok(Math.max(...layout.headerButtons.map(b=>b.height))-Math.min(...layout.headerButtons.map(b=>b.height))<1,'Aligned header buttons '+JSON.stringify({width,fixture,buttons:layout.headerButtons}));
   assert.deepEqual(layout.unreadable,[]);
   for(const panel of layout.panels)assert.ok(panel.left>=layout.root.left-1&&panel.right<=layout.root.right+1,JSON.stringify({width,fixture,panel}));
   if(width<=950){
    const cards=await page.evaluate(()=>['enquiry-details','enquiry-response','rental-agreement','enquiry-followups','enquiry-activity','enquiry-quotations'].map(id=>({id,top:document.getElementById(id).getBoundingClientRect().top})));
    for(let i=1;i<cards.length;i++)assert.ok(cards[i].top>cards[i-1].top,'Mobile action order '+JSON.stringify({width,fixture,cards}));
   }
   if(fixture==='ready')assert.equal(layout.primary,'Create Rental');
   if(fixture==='signed')assert.equal(layout.primary,'Record quotation response');
   if(fixture==='readonly')assert.equal(await page.$$eval('.sw-detail form',nodes=>nodes.length),0);
   if(['draft','unsigned','signed','lost','readonly'].includes(fixture))assert.notEqual(layout.primary,'Create Rental');
   if(fixture==='signed'&&[1440,390].includes(width)){
    await page.evaluate(()=>{document.activeElement?.blur();for(let el=document.querySelector('[data-enquiry-detail]');el;el=el.parentElement)el.scrollTop=0;window.scrollTo(0,0);});
    await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
    await page.screenshot({path:path.join(folder,'enquiry-details-'+width+'.png'),fullPage:true});
   }
   await click('nav[aria-label="Sales progress"] a[href="#enquiry-details"]');
   assert.equal(new URL(page.url()).hash,'#enquiry-details');
   for(const id of ['enquiry-response','enquiry-quotations','enquiry-activity']){
    await page.evaluate(id=>{location.hash=id;},id);
    await page.waitForFunction(id=>!document.querySelector('#'+id+' details')||document.querySelector('#'+id+' details').open,{},id);
   }
   assert.ok(await page.$('[data-terms-preview]'),'Rental terms preview available before and after quotation in '+fixture);
   if(await page.$('[data-terms-preview]')){
    const preview='[data-terms-preview]';
    assert.equal(await page.$eval(preview,e=>e.open),false);
    await page.$eval(preview+' > summary',e=>e.focus());await page.keyboard.press('Enter');
    assert.equal(await page.$eval(preview,e=>e.open),true);
    await page.evaluate(()=>Object.defineProperty(navigator,'clipboard',{configurable:true,value:{writeText:async text=>{window.copiedTerms=text;}}}));
    const terms=await page.$eval('[data-terms-text]',e=>e.value);
    await click('[data-copy-terms]');await page.waitForFunction(()=>document.querySelector('[data-terms-copy-status]').textContent==='Rental terms copied.');
    assert.equal(await page.evaluate(()=>window.copiedTerms),terms);
    assert.match(terms,/Rental period and payment/);
    if(await page.$('[data-terms-message]')){
     const message=await page.$eval('[data-terms-message]',e=>e.value);assert.match(message,/signature=/);
     await click('[data-copy-terms-message]');await page.waitForFunction(message=>window.copiedTerms===message,{},message);
     assert.match(await page.$eval('[data-terms-copy-status]',e=>e.textContent),/Check the recipient/);
    }
    await page.evaluate(()=>Object.defineProperty(navigator,'clipboard',{configurable:true,value:{writeText:async()=>{throw new Error('Synthetic clipboard denial');}}}));
    await click('[data-copy-terms]');await page.waitForFunction(()=>!document.querySelector('[data-terms-text]').hidden);
    assert.equal(await page.$eval('[data-terms-text]',e=>({focused:document.activeElement===e,selected:e.selectionEnd-e.selectionStart})).then(x=>x.focused&&x.selected===terms.length),true);
   }
   if(['unsigned','signed','ready'].includes(fixture)){
    await page.evaluate(()=>{
     window.whatsappOpenCalls=0;window.whatsappNavigations=[];
     window.open=()=>{window.whatsappOpenCalls++;return null;};
     document.addEventListener('click',event=>{
      const link=event.target.closest('a[data-phos-whatsapp]');
      if(link){window.whatsappNavigations.push({url:link.href,target:link.target,rel:link.rel});event.preventDefault();}
     });
    });
    const whatsappSelector='a[data-phos-whatsapp]';
    const original=new URL(await page.$eval(whatsappSelector,e=>e.href));
    await click(whatsappSelector);await click(whatsappSelector);
    const sharing=await page.evaluate(()=>({opened:window.whatsappOpenCalls,navigations:window.whatsappNavigations}));
    assert.equal(sharing.opened,0,'No intermediary blank popup');assert.equal(sharing.navigations.length,2);
    for(const navigation of sharing.navigations){const url=new URL(navigation.url);assert.equal(url.host,'web.whatsapp.com');assert.equal(url.pathname,'/send');assert.equal(url.searchParams.get('text'),original.searchParams.get('text'));assert.equal(navigation.target,'_blank');assert.match(navigation.rel,/noopener/);assert.match(navigation.rel,/noreferrer/);}
    assert.ok(await page.$('[data-copy-whatsapp]'),'Adjacent copy message action');
    await page.evaluate(()=>Object.defineProperty(navigator,'clipboard',{configurable:true,value:{writeText:async text=>{window.copiedWhatsapp=text;}}}));
    await click('[data-copy-whatsapp]');await page.waitForFunction(text=>window.copiedWhatsapp===text,{},original.searchParams.get('text'));
    await page.evaluate(()=>Object.defineProperty(navigator,'clipboard',{configurable:true,value:{writeText:async()=>{throw new Error('Synthetic clipboard denial');}}}));
    await click('[data-copy-whatsapp]');await page.waitForFunction(()=>{const text=document.querySelector('[data-whatsapp-copy-text]');return text&&!text.hidden&&document.activeElement===text;});
    assert.equal(await page.$eval('[data-whatsapp-copy-text]',e=>e.value),original.searchParams.get('text'));
   }
   if(fixture==='noquote'){
    await page.evaluate(()=>{
     window.enquirySubmissions=[];
     document.querySelector('[data-enquiry-detail]').addEventListener('submit',event=>{
      event.preventDefault();window.enquirySubmissions.push({action:event.target.action,method:event.target.method,values:Object.fromEntries(new FormData(event.target))});
     });
    });
    await page.type('.swd-note-form textarea','Synthetic shift handover note');
    await page.$eval('.swd-note-form button',button=>button.click());
    let submissions=await page.evaluate(()=>window.enquirySubmissions);
    assert.equal(submissions.length,1);assert.equal(submissions[0].method,'post');assert.equal(submissions[0].values.note,'Synthetic shift handover note');
    assert.ok(submissions[0].values._token);
    const completion='.swd-follow-up details';
    await page.$eval(completion+' summary',element=>element.focus());await page.keyboard.press('Enter');
    assert.equal(await page.$eval(completion,element=>element.open),true);
    await page.select(completion+' select','connected');await page.type(completion+' textarea','Synthetic customer confirmed timing.');
    await page.$eval(completion+' button',button=>button.click());
    submissions=await page.evaluate(()=>window.enquirySubmissions);assert.equal(submissions.length,2);assert.equal(submissions[1].values.outcome,'connected');
    assert.equal(submissions[1].values.completion_note,'Synthetic customer confirmed timing.');
    await page.$eval('.swd-stage-panel summary',element=>element.focus());await page.keyboard.press('Enter');
    assert.equal(await page.$eval('.swd-stage-panel details',element=>element.open),true);
   }
   report.checks.push({width,fixture,noHorizontalOverflow:true,workflowAnchors:true,visibleContact:true});
  }
  assert.deepEqual(report.errors,[]);assert.deepEqual(report.writes,[]);
  process.stdout.write('ENQUIRY_DETAILS_BROWSER_CHECKS='+report.checks.length+' ERRORS=0 MUTATIONS=0\n');
 }finally{if(page)await page.screenshot({path:path.join(folder,'details-browser-last-state.png'),fullPage:true});if(browser)await browser.close();await new Promise(r=>server.close(r));fs.writeFileSync(path.join(folder,'details-browser-results.json'),JSON.stringify(report,null,2));}
});
