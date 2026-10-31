const fs = require('node:fs'), path = require('node:path'), http = require('node:http');
const assert = require('node:assert/strict');
const { test } = require('node:test');
const puppeteer = require('puppeteer');
const rendered = process.env.PHOS_MOBILE_LAYOUT_RENDER_DIR;
test('dashboard, task board and product mobile layouts use readable actual rendered content', { skip: !rendered, timeout: 180000 }, async () => {
const folder = process.env.PHOS_MOBILE_LAYOUT_REPORT_DIR || rendered;
const publicRoot = path.resolve(__dirname, '../../public');
const fixtures = JSON.parse(fs.readFileSync(path.join(rendered, 'mobile-fixtures.json')));
const sourceOrigins = [...new Set(fixtures.map(f => new URL(f.url).origin))];
const selected = process.argv.find(a => a.startsWith('--pages='))?.slice(8).split(',');
const report = {synthetic:true, checks:[], overflow:[], errors:[], assets:[], requests:[], screenshots:[]};
fs.mkdirSync(path.join(folder,'screenshots'),{recursive:true});
let origin, browser;
const check = (name, pass, data={}) => { report.checks.push({name,pass:!!pass,...data}); process.stdout.write(`${pass?'PASS':'FAIL'} ${name}\n`); if(!pass)process.stdout.write(JSON.stringify(data).slice(0,3500)+'\n'); };
const mime = {'.css':'text/css','.js':'text/javascript','.png':'image/png','.svg':'image/svg+xml','.jpg':'image/jpeg','.ico':'image/x-icon','.woff2':'font/woff2'};
const server = http.createServer((req,res)=>{
  const url = new URL(req.url,origin); report.requests.push({method:req.method,path:url.pathname});
  if(!['GET','HEAD'].includes(req.method)){res.writeHead(405);res.end('Read-only');return;}
  const fixture = fixtures.find(f=>new URL(f.url).pathname===url.pathname&&new URL(f.url).search===url.search)||fixtures.find(f=>new URL(f.url).pathname===url.pathname);
  if(fixture){res.writeHead(200,{'Content-Type':'text/html; charset=utf-8'});let html=fs.readFileSync(path.join(rendered,fixture.file),'utf8');for(const source of sourceOrigins)html=html.replaceAll(source,origin);res.end(html);return;}
  const file=path.resolve(publicRoot,'.'+decodeURIComponent(url.pathname));
  if(file.startsWith(publicRoot+path.sep)&&fs.existsSync(file)&&fs.statSync(file).isFile()){res.writeHead(200,{'Content-Type':mime[path.extname(file)]||'application/octet-stream'});fs.createReadStream(file).pipe(res);return;}
  if(/\.(?:css|js|svg|png|jpe?g|woff2?|ico)$/.test(url.pathname)){res.writeHead(404);res.end();return;}
  if(req.headers.accept?.includes('application/json')||url.pathname.includes('/notifications/')){res.writeHead(200,{'Content-Type':'application/json'});res.end(JSON.stringify({data:[],results:[],notifications:[],count:0,unread_count:0}));return;}
  res.writeHead(200,{'Content-Type':'text/html'});res.end('<title>Local read-only destination</title>');
});
const pause=ms=>new Promise(r=>setTimeout(r,ms));

try {

 await new Promise(r=>server.listen(0,'127.0.0.1',r));origin=`http://127.0.0.1:${server.address().port}`;
 browser=await puppeteer.launch({headless:true,args:['--disable-background-networking','--no-first-run']});
 for(const fixture of fixtures.filter(f=>!selected||selected.includes(f.page))){
  const page=await browser.newPage();await page.setViewport({width:1440,height:1000});await page.setRequestInterception(true);
  page.on('request',req=>req.url().startsWith(origin+'/')||req.url().startsWith('data:')?req.continue():req.respond({status:200,contentType:'text/css',body:''}));
  page.on('pageerror',e=>report.errors.push({page:fixture.page,message:e.message}));
  page.on('response',res=>{if(res.url().startsWith(origin+'/')&&/\.(?:css|js)(?:\?|$)/.test(res.url()))report.assets.push({url:res.url(),status:res.status(),type:res.headers()['content-type']});});
  await page.goto(origin+new URL(fixture.url).pathname+new URL(fixture.url).search,{waitUntil:'networkidle0'});
  check(fixture.page+' body font',await page.evaluate(()=>/Inter|Segoe UI|Arial/i.test(getComputedStyle(document.body).fontFamily)));
  let pipelineValues;
  for(const width of [1440,768,390,360,320]){
   await page.setViewport({width,height:width===320?680:width===390?760:1000});
   if(fixture.module==='rentals'&&['create','edit'].includes(fixture.screen)&&width<=390)await page.$eval('[data-rental-mobile-step="customer"]',button=>button.click());
   await page.evaluate(()=>{window.scrollTo(0,0);const main=document.querySelector('.app-shell-main');if(main)main.scrollTop=0;});await pause(70);
   const layout=await page.evaluate(()=>{
    const main=document.querySelector('.app-shell-main'),b=main.getBoundingClientRect();
    const visible=el=>{const r=el.getBoundingClientRect(),s=getComputedStyle(el);return r.width>0&&r.height>0&&s.visibility!=='hidden'&&s.clip!=='rect(0px, 0px, 0px, 0px)'&&s.opacity!=='0';};
    const offenders=[...main.querySelectorAll('h1,h2,h3,p,a,button,input,select,textarea,section,table,form,div')].filter(visible).filter(el=>{
      const r=el.getBoundingClientRect();if(el.closest('.app-shell-topbar,.mobile-topbar,.mobile-bottom-nav'))return false;if(r.left>=b.left-2&&r.right<=b.right+2)return false;
      for(let node=el.parentElement;node&&node!==main;node=node.parentElement){const style=getComputedStyle(node);if(/auto|scroll/.test(style.overflowX)&&node.scrollWidth>node.clientWidth+1)return false;}
      return !el.closest('[hidden],details:not([open]) > :not(summary)');
    }).slice(0,12).map(el=>({tag:el.tagName,class:el.className,text:(el.textContent||'').trim().slice(0,80),left:el.getBoundingClientRect().left,right:el.getBoundingClientRect().right}));
    return {mainLeft:b.left,mainRight:b.right,mainWidth:b.width,documentWidth:document.documentElement.scrollWidth,font:getComputedStyle(document.body).fontFamily,offenders};
   });
   report.overflow.push({page:fixture.page,width,...layout});
   check(`${fixture.page} content fits ${width}`,layout.offenders.length===0&&layout.documentWidth<=width+1,layout);
   if(fixture.module==='products'){
    const text=await page.evaluate(()=>document.body.innerText);
    check(`${fixture.page} readable product labels ${width}`,!['â‚¹','âœ“','âŒ„','âŒƒ','â–¡','â–£','â—‡','â—·','â€”','@php','@endphp','<?php'].some(fragment=>text.includes(fragment)));
   }
   if(fixture.page.startsWith('dashboard-')&&width<=390){
    const grids=await page.$$eval('.executive-health-strip,.control-room-priority-grid,.phos-overview-metrics,.operations-health-grid,.inventory-health-grid',elements=>elements.filter(el=>el.getBoundingClientRect().height>0).map(el=>({class:el.className,width:el.getBoundingClientRect().width,columns:getComputedStyle(el).gridTemplateColumns})));
    check(`${fixture.page} phone metrics have readable single columns ${width}`,grids.every(grid=>grid.columns.split(' ').length===1),{grids});
   }
   if(fixture.page==='dashboard-operations'){
    const pipeline=await page.$eval('[data-dashboard-panel="operations"] .operations-pipeline-wrap .control-room-pipeline-track',track=>{
     const rect=e=>{const r=e.getBoundingClientRect();return{left:r.left,right:r.right,height:r.height};};
     return {columns:getComputedStyle(track).gridTemplateColumns.split(' ').length,rows:[...track.children].map(row=>{
      const label=row.querySelector('.control-room-pipeline-stage-label'),count=row.querySelector('.control-room-pipeline-stage-value');
      return{label:label.textContent.trim(),value:count.textContent.trim(),row:rect(row),icon:rect(row.querySelector('.control-room-pipeline-stage-icon')),text:rect(label),count:rect(count),fontSize:parseFloat(getComputedStyle(label).fontSize),lineHeight:parseFloat(getComputedStyle(label).lineHeight)};
     })};
    });
    const values=pipeline.rows.map(row=>({label:row.label,value:row.value}));pipelineValues??=values;
    const readable=width>480||pipeline.columns===1&&pipeline.rows.every(row=>row.fontSize>=12&&row.text.height<=row.lineHeight+1&&row.icon.right<=row.text.left&&row.text.right<=row.count.left&&row.count.right<=row.row.right);
    check(`Operations pipeline labels and counts remain readable ${width}`,pipeline.rows.length===6&&JSON.stringify(values)===JSON.stringify(pipelineValues)&&readable,{pipeline});
   }
   if(fixture.page==='tasks-attention'&&width<=390){
    const attention=await page.$$eval('.ops-attention-row',rows=>rows.filter(row=>row.getBoundingClientRect().height>0).map(row=>{const label=row.querySelector('span:nth-child(2)'),assignee=row.querySelector('em'),a=label.getBoundingClientRect(),b=assignee.getBoundingClientRect(),r=row.getBoundingClientRect();return{label:label.textContent.trim(),assignee:assignee.textContent.trim(),noOverlap:b.top>=a.bottom-1,contained:a.right<=r.right+1&&b.right<=r.right+1&&a.bottom<=r.bottom+1&&b.bottom<=r.bottom+1,width:r.width};}));
    check(`Tasks attention label/assignee do not overlap ${width}`,attention.length>0&&attention.every(row=>row.noOverlap&&row.contained),{attention});
    const reachable=await page.$eval('.ops-attention-list',list=>{list.scrollTop=list.scrollHeight;const last=list.lastElementChild.getBoundingClientRect(),bounds=list.getBoundingClientRect();const reachable=last.bottom<=bounds.bottom+2&&last.top>=bounds.top-1;list.scrollTop=0;return reachable;});
    check(`Last attention task remains reachable ${width}`,reachable);
   }
   if(width===1440||width===320||width===390){const filename=path.join(folder,'screenshots',fixture.page+'-'+width+'.png');await page.screenshot({path:filename});report.screenshots.push(filename);}
   if(width===320&&(fixture.page.startsWith('dashboard-')||fixture.page==='tasks-attention'||fixture.module==='products')){
    const focused=await page.evaluate(({page,module,screen})=>{
      let target;
      if(page==='tasks-attention') target=document.querySelector('.ops-control-strip');
      else if(page.startsWith('dashboard-')) target=[...document.querySelectorAll('.executive-health-strip,[data-dashboard-panel]:not([hidden]) .control-room-card,[data-dashboard-panel]:not([hidden]) .team-performance-head')].find(el=>el.getBoundingClientRect().height>0);
      else if(module==='products'&&['create','edit'].includes(screen)){target=document.querySelector('[data-product-mobile-section="pricing"]');if(target&&!target.classList.contains('is-open'))target.querySelector('[data-product-mobile-toggle]')?.click();}
      else if(module==='products'&&screen==='show'){const summary=[...document.querySelectorAll('.product-mobile-accordion summary')].find(el=>el.textContent.includes('Pricing'));target=summary?.parentElement;if(target)target.open=true;}
      if(!target)return false;target.scrollIntoView({block:'start'});window.scrollBy(0,-126);const main=document.querySelector('.app-shell-main');if(main?.scrollTop>0)main.scrollTop=Math.max(0,main.scrollTop-126);return true;
    },fixture);
    if(focused){await pause(70);const filename=path.join(folder,'screenshots',fixture.page+'-details-320.png');await page.screenshot({path:filename});report.screenshots.push(filename);}
   }
   if(['create','edit'].includes(fixture.screen)&&width<=390){
    if(fixture.module==='rentals')await page.$eval('[data-rental-mobile-step="review"]',button=>button.click());
    const action=new URL(fixture.url).pathname.replace(/\/(?:create|edit)$/,'');
    const submit=await page.evaluate(action=>{const form=[...document.querySelectorAll('main form,.app-shell-main form')].find(f=>new URL(f.getAttribute('action')||location.href,location.href).pathname===action);const button=[...document.querySelectorAll('button[type=submit],input[type=submit]')].find(b=>b.form===form&&b.getBoundingClientRect().width>0);if(!button)return null;button.dataset.qaSubmit='true';button.scrollIntoView({block:'center'});return true;},action);
    if(submit){await pause(60);const hit=await page.$eval('[data-qa-submit]',button=>{const r=button.getBoundingClientRect(),x=r.left+r.width/2,y=r.top+r.height/2,target=document.elementFromPoint(x,y);return{visible:x>=0&&x<=innerWidth&&y>=0&&y<=innerHeight&&!!target&&(target===button||button.contains(target)),left:r.left,right:r.right,top:r.top,bottom:r.bottom,cover:target?.className};});check(`${fixture.page} submit reachable ${width}`,hit.visible,hit);}
    else check(`${fixture.page} native submit present ${width}`,false);
   }
  }
  await page.close();fs.writeFileSync(path.join(folder,'mobile-entry-progress.json'),JSON.stringify(report,null,2));
 }
 check('no business writes',report.requests.every(r=>['GET','HEAD'].includes(r.method)));
 check('assets load with correct content type',report.assets.length>0&&report.assets.every(a=>a.status===200&&(/\.css(?:\?|$)/.test(a.url)?a.type?.includes('text/css'):/javascript/.test(a.type||''))),{invalid:report.assets.filter(a=>a.status!==200)});
 report.summary={passed:report.checks.filter(c=>c.pass).length,failed:report.checks.filter(c=>!c.pass).length,browserErrors:report.errors.length};
 fs.writeFileSync(path.join(folder,'mobile-entry-results.json'),JSON.stringify(report,null,2));process.stdout.write(JSON.stringify(report.summary)+'\n');assert.equal(report.summary.failed,0,'Mobile layout checks failed; see report for elements and viewport');assert.equal(report.errors.length,0,'Browser script errors');

} finally { if(browser) await browser.close(); await new Promise(resolve=>server.close(resolve)); }
});
