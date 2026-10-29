/* Synthetic Laravel-rendered fixtures only. No UAT session, credentials or outbound calls. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const puppeteer = require('puppeteer');

const root = path.resolve(__dirname, '../..');
const directory = process.env.GETTING_STARTED_PREVIEW_DIR;
if (!directory || !fs.existsSync(directory)) throw new Error('Set GETTING_STARTED_PREVIEW_DIR to the synthetic test fixtures.');
const out = path.join(directory, 'browser'); fs.mkdirSync(out, { recursive: true });
let origin;
const server = http.createServer((req, res) => {
  const requested = new URL(req.url, origin).pathname;
  if (requested.startsWith('/fixture/')) {
    const name = path.basename(requested);
    const file = path.join(directory, name);
    if (!name.endsWith('.html') || !fs.existsSync(file)) { res.writeHead(404).end(); return; }
    const html = fs.readFileSync(file, 'utf8').replaceAll('http://127.0.0.1:8000', origin).replaceAll('http://localhost', origin);
    res.writeHead(200, {'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store'}); res.end(html); return;
  }
  if (requested.includes('available-assets')) { res.writeHead(200, {'Content-Type': 'application/json'}); res.end('[]'); return; }
  const file = path.resolve(root, 'public', '.' + requested);
  const publicRoot = path.join(root, 'public') + path.sep;
  if (!file.startsWith(publicRoot) || !/\.(?:css|js|png|jpg|jpeg|svg|webp|ico|woff2?)$/.test(file) || !fs.existsSync(file)) { res.writeHead(404).end(); return; }
  const types = {'.css':'text/css','.js':'text/javascript','.svg':'image/svg+xml','.png':'image/png','.jpg':'image/jpeg','.woff2':'font/woff2'};
  res.writeHead(200, {'Content-Type': types[path.extname(file)] || 'application/octet-stream'}); fs.createReadStream(file).pipe(res);
});

(async () => {
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  origin = `http://127.0.0.1:${server.address().port}`;
  const browser = await puppeteer.launch({headless:true});
  const results = []; const errors = [];
  try {
    const names = ['phos-guides','phos-overview','phos-invoice','phos-delivery','phos-collection-ledger','phos-cash-handovers',
      'sw-index','sw-guided-draft','sw-guided-review','sw-public','availability'];
    for (const width of process.env.PHOS_BROWSER_FOCUS ? [] : [1440, 768, 390]) {
      for (const name of names) {
        assert.ok(fs.existsSync(path.join(directory, name+'.html')), 'Missing fixture '+name);
        const page = await browser.newPage(); await page.setViewport({width,height:1000,deviceScaleFactor:1});
        await page.setRequestInterception(true);
        page.on('request', request => {
          if (request.url().startsWith(origin+'/') || request.url().startsWith('data:') || request.url().startsWith('blob:')) request.continue();
          else request.abort();
        });
        const pageErrors = []; page.on('pageerror', error => pageErrors.push(error.message));
        await page.goto(origin+'/fixture/'+name+'.html', {waitUntil:'networkidle0'});
        const layout = await page.evaluate(() => ({width:innerWidth,scroll:document.documentElement.scrollWidth,heading:document.querySelector('h1')?.textContent}));
        assert.ok(layout.heading, `${name} has a visible page heading`);
        if (layout.scroll > layout.width + 2) errors.push(`${name}@${width}: horizontal overflow ${layout.scroll}`);
        if (pageErrors.length) errors.push(`${name}@${width}: ${pageErrors.join('; ')}`);
        await page.screenshot({path:path.join(out,`${name}-${width}.png`),fullPage:true});
        results.push({name,width,layout,pageErrors}); await page.close();
      }
    }

    // Use actual product change events, not just prefilled tax values.
    {
      const page=await browser.newPage(); const pageErrors=[];
      page.on('pageerror',error=>pageErrors.push(error.message));
      await page.setRequestInterception(true);
      page.on('request',request=>request.url().startsWith(origin+'/') || request.url().startsWith('data:') ? request.continue() : request.abort());
      await page.goto(origin+'/fixture/sw-tax-product-selection.html',{waitUntil:'networkidle0'});
      const choices=await page.$$eval('#quotation-lines [data-line-field="product_id"] option',options=>options
        .filter(option=>option.textContent.startsWith('Tax test ')).map(option=>({id:option.value,rate:Number(option.textContent.slice(9))})));
      assert.equal(choices.length,6,'All standard and custom product rates have fixtures');
      for (const choice of choices) {
        await page.select('#quotation-lines [data-line-field="product_id"]',choice.id);
        const actual=await page.evaluate(()=>{
          const tax=document.querySelector('#quotation-lines [data-line-field="tax_rate"]');
          return {tax:tax.value,valid:tax.checkValidity(),preview:Number(document.getElementById('preview-tax').textContent.replaceAll(',','')),
            submitted:new FormData(document.getElementById('quotation-form')).get('lines[0][tax_rate]')};
        });
        assert.equal(actual.tax,choice.rate.toFixed(2),'Product tax remains selected');
        assert.equal(actual.submitted,choice.rate.toFixed(2),'Selected tax is included in submitted form');
        assert.equal(actual.preview,choice.rate,'Exclusive tax preview matches the selected product');
        assert.ok(actual.valid);
      }
      await page.click('#add-quote-line');
      const custom=choices.find(choice=>choice.rate===7.5);
      await page.select('#quotation-lines [data-quote-line]:last-child [data-line-field="product_id"]',custom.id);
      assert.equal(await page.$eval('#quotation-lines [data-quote-line]:last-child [data-line-field="tax_rate"]',select=>select.value),'7.50','New line retains custom product GST');
      assert.deepEqual(pageErrors,[]);
      results.push({name:'product-tax-selection',rates:choices.map(choice=>choice.rate),passed:true});
      await page.close();
    }

    for (const [name,expected,additional] of [['inclusive','150.50',false],['exclusive','150.50',true],['complimentary','0.00',false]]) {
      const page=await browser.newPage(); await page.setViewport({width:390,height:900});
      const formErrors=[]; page.on('pageerror',error=>formErrors.push(error.message));
      await page.setRequestInterception(true); page.on('request', request => request.url().startsWith(origin+'/') || request.url().startsWith('data:') ? request.continue() : request.abort());
      await page.goto(origin+'/fixture/sw-rental-conversion-'+name+'.html',{waitUntil:'networkidle0'});
      const values=await page.evaluate(()=>{
        const form=document.getElementById('rentalCreateForm'), data=new FormData(form);
        return {primary:data.get('rental_amount'),quote:data.get('quotation_id'),rate:data.get('rental_items[0][unit_rental_amount]'),mode:data.get('gst_mode'),
          additionalInputs:[...document.querySelectorAll('[name^="rental_items["]')].map(e=>({name:e.name,value:e.value,form:e.form?.id,disabled:e.disabled})),
          fields:[...data.entries()].filter(([key])=>key.startsWith('rental_items'))};
      });
      assert.equal(Number(values.primary),Number(expected),`Negotiated ${name} price survives form initialization`);
      assert.ok(values.quote,'Quotation identity stays attached');
      assert.equal(values.mode,name==='exclusive'?'exclusive':'inclusive');
      if (additional && Number(values.rate)!==40.25) {
        console.log(JSON.stringify({values,formErrors},null,2));
        await page.screenshot({path:path.join(out,'rental-exclusive-debug.png'),fullPage:true});
      }
      if (additional) assert.equal(Number(values.rate),40.25,'Additional negotiated price survives');
      assert.deepEqual(formErrors,[],'Rental form initializes without errors');
      results.push({name:'rental-conversion-'+name,values}); await page.close();
    }

    // Camera/gallery acquisition is simulated with synthetic images; this is not a real-device GPS or signature acceptance test.
    if (!process.env.PHOS_BROWSER_FOCUS) {
    const page=await browser.newPage(); await page.setViewport({width:390,height:1000});
    await page.setRequestInterception(true); page.on('request', request => request.url().startsWith(origin+'/') || request.url().startsWith('data:') || request.url().startsWith('blob:') ? request.continue() : request.abort());
    await page.goto(origin+'/fixture/phos-delivery.html',{waitUntil:'networkidle0'});
    const images=await page.evaluate(()=>{
      const canvas=document.createElement('canvas'); canvas.width=120; canvas.height=80;
      const ctx=canvas.getContext('2d'); ctx.fillStyle='#087e83'; ctx.fillRect(0,0,120,80); return canvas.toDataURL('image/png').split(',')[1];
    });
    const imageFiles=[1,2,3,4].map(i=>{const file=path.join(out,`synthetic-photo-${i}.png`);fs.writeFileSync(file,Buffer.from(images,'base64'));return file;});
    const input=await page.$('#delivery_device_photos'); assert.ok(input,'Delivery photo input exists');
    for (let i=0;i<3;i++) {
      await input.uploadFile(imageFiles[i]);
      await page.waitForFunction(n=>document.querySelector('#delivery_device_photos').files.length===n && !document.querySelector('#delivery_device_photos').dataset.preparingPhotos,{},i+1);
    }
    assert.equal(await page.$eval('#delivery_device_photos',e=>e.files.length),3,'Separate selections retain all three photos');
    page.on('dialog',dialog=>dialog.dismiss());
    await input.uploadFile(imageFiles[3]);
    await page.waitForFunction(()=>!document.querySelector('#delivery_device_photos').dataset.preparingPhotos);
    assert.equal(await page.$eval('#delivery_device_photos',e=>e.files.length),3,'Fourth selection preserves earlier photos');
    await page.$eval('#delivery-device-preview button',button=>button.click());
    assert.equal(await page.$eval('#delivery_device_photos',e=>e.files.length),2,'Remove changes the submitted file list');
    results.push({name:'retained-photos',passed:true});
    await page.evaluate(() => {
      document.querySelectorAll('#delivery-device-preview button').forEach(button=>button.click());
      const input=document.querySelector('#delivery_device_photos');
      const canvas=document.createElement('canvas');canvas.width=80;canvas.height=60;
      canvas.getContext('2d').fillRect(5,5,60,40);
      const bytes=Uint8Array.from(atob(canvas.toDataURL('image/png').split(',')[1]),x=>x.charCodeAt(0));
      for (const name of ['rapid-a.png','rapid-b.png']) {
        const transfer=new DataTransfer();transfer.items.add(new File([bytes],name,{type:'image/png'}));
        input.files=transfer.files;input.dispatchEvent(new Event('change',{bubbles:true}));
      }
    });
    await page.waitForFunction(()=>document.querySelector('#delivery_device_photos').files.length===2 && !document.querySelector('#delivery_device_photos').dataset.preparingPhotos);
    assert.equal(await page.$eval('#delivery_device_photos',e=>e.files.length),2,'Overlapping acquisition retains both photos');
    results.push({name:'queued-photo-preparation',passed:true}); await page.close();
    }

    fs.writeFileSync(path.join(out,'results.json'),JSON.stringify({results,errors},null,2));
    assert.deepEqual(errors,[],'Rendered pages have no uncaught JavaScript errors or document overflow');
    console.log(`PHOS_BROWSER_CHECKS_PASSED=${results.length}`);
  } finally { await browser.close(); await new Promise(resolve=>server.close(resolve)); }
})().catch(error=>{console.error(error);process.exitCode=1;server.close();});
