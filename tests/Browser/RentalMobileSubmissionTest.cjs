const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const {randomBytes} = require('node:crypto');
const fs = require('node:fs'), http = require('node:http'), os = require('node:os'), path = require('node:path');
const {test} = require('node:test');
const puppeteer = require('puppeteer');
const repo = path.resolve(__dirname, '../..');

test('mobile rental create and edit submit the owning form and follow availability state', {timeout:180000}, async t => {
    const temporaryParent = fs.realpathSync(os.tmpdir());
    const folder = fs.mkdtempSync(path.join(temporaryParent, 'phos-rental-mobile-'));
    t.after(() => {
        assert.equal(path.dirname(folder), temporaryParent);
        assert.ok(path.basename(folder).startsWith('phos-rental-mobile-'));
        fs.rmSync(folder, {recursive:true, force:true});
    });
    for (const name of ['rendered', 'views', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/logs']) fs.mkdirSync(path.join(folder,name),{recursive:true});
    try { execFileSync(process.env.PHOS_TEST_PHP || 'php', ['vendor/bin/pest','tests/Feature/Regression/RentalMobileSubmissionRegressionTest.php','--do-not-cache-result','--colors=never'], {
        cwd:repo, timeout:120000, maxBuffer:8*1024*1024,
        env:{...process.env,APP_ENV:'testing',APP_DEBUG:'false',APP_URL:'http://127.0.0.1:8000',APP_KEY:'base64:'+randomBytes(32).toString('base64'),
            APP_CONFIG_CACHE:path.join(folder,'config.php'),APP_ROUTES_CACHE:path.join(folder,'routes.php'),VIEW_COMPILED_PATH:path.join(folder,'views'),LARAVEL_STORAGE_PATH:path.join(folder,'storage'),
            DB_CONNECTION:'sqlite',DB_DATABASE:':memory:',DB_URL:'',CACHE_STORE:'array',SESSION_DRIVER:'array',QUEUE_CONNECTION:'sync',MAIL_MAILER:'array',PHOS_RENTAL_MOBILE_RENDER_DIR:path.join(folder,'rendered')},
    }); } catch (error) {throw new Error(error.stdout?.toString() || error.message);}
    const publicRoot=path.join(repo,'public');
    let origin; const writes=[], errors=[];
    const server=http.createServer((req,res)=>{
        if(!['GET','HEAD'].includes(req.method)){writes.push(req.method);res.writeHead(405);res.end();return;}
        const url=new URL(req.url,origin);
        if(/^\/fixture\/(create|edit)$/.test(url.pathname)){
            res.setHeader('Content-Type','text/html; charset=utf-8');
            res.end(fs.readFileSync(path.join(folder,'rendered','rental-'+url.pathname.split('/').pop()+'.html'),'utf8').replaceAll('http://127.0.0.1:8000',origin));return;
        }
        const file=path.resolve(publicRoot,'.'+decodeURIComponent(url.pathname));
        if(file.startsWith(publicRoot+path.sep)&&fs.existsSync(file)&&fs.statSync(file).isFile()){
            res.setHeader('Content-Type',({'.js':'text/javascript','.css':'text/css','.svg':'image/svg+xml','.png':'image/png','.woff2':'font/woff2'})[path.extname(file)]||'application/octet-stream');fs.createReadStream(file).pipe(res);return;
        }
        res.setHeader('Content-Type','application/json');res.end(JSON.stringify({data:[],results:[],rows:[],notifications:[],count:0,unread_count:0}));
    });
    await new Promise(r=>server.listen(0,'127.0.0.1',r));origin='http://127.0.0.1:'+server.address().port;
    let browser;
    try {
        browser=await puppeteer.launch({headless:true,args:['--disable-background-networking','--no-first-run']});
        for(const width of [320,390,768,1440]) for(const mode of ['create','edit']) await t.test(mode+' '+width,async()=>{
            const page=await browser.newPage();
            try {
                await page.setViewport({width,height:width<768?844:1000});
                page.on('pageerror',e=>errors.push(e.message));
                await page.setRequestInterception(true);
                page.on('request',req=>req.url().startsWith(origin+'/')||req.url().startsWith('data:')?req.continue():req.respond({status:200,contentType:'text/css',body:''}));
                await page.goto(origin+'/fixture/'+mode,{waitUntil:'networkidle0'});
                await page.addStyleTag({content:'*{scroll-behavior:auto!important}'});
                const collectOnDelivery = mode === 'create' && [390, 1440].includes(width);
                await page.evaluate((mode, collectOnDelivery)=>{
                    window.rentalSubmits=[];
                    document.addEventListener('submit',event=>{event.preventDefault();window.rentalSubmits.push({action:event.target.getAttribute('action'),data:Object.fromEntries(new FormData(event.target,event.submitter)),submitter:event.submitter?.id});},true);
                    const set=(id,value)=>{const e=document.getElementById(id);if(e){e.value=value;e.dispatchEvent(new Event('change',{bubbles:true}));e.dispatchEvent(new Event('input',{bubbles:true}));}};
                    const choose=id=>{const e=document.getElementById(id);if(e)set(id,Array.from(e.options).find(o=>o.value)?.value||'');};
                    choose('city_id');choose('customer_id');choose('product_id');choose('dispatch_warehouse_id');set('quantity','1');set('start_date','2026-11-05');set('end_date','2026-11-10');set('rental_amount','600');set('delivery_assignment_type','customer_pickup');window.updateSummary?.();
                    if (mode === 'create') {
                        const delivery = document.getElementById('delivery_assignment_type');
                        if (![...delivery.options].some(option => option.value === 'vendor')) throw new Error('Vendor delivery is missing');
                        set('delivery_assignment_type', 'third_party');
                        if (document.getElementById('delivery_staff_id').value !== 'third_party') throw new Error('One-time logistics option is unavailable');
                        set('delivery_assignment_type', 'vendor');
                        if (document.getElementById('delivery_vendor_id').disabled) throw new Error('Delivery vendor is disabled for PH stock');
                        choose('delivery_vendor_id');
                        set('collection_mode', 'pay_on_delivery');
                        choose('collection_assignee_id');
                        if (collectOnDelivery) {
                            set('collection_mode', '');
                            set('collection_assignee_id', '');
                            set('delivery_assignment_type', 'ph_internal');
                            const staff = document.getElementById('delivery_staff_id');
                            const driver = [...staff.options].find(option => option.dataset.collectionEligible === '1');
                            if (!driver) throw new Error('Delivery collection operator is missing');
                            set('delivery_staff_id', driver.value);
                            set('collection_owner', 'delivery');
                            set('collection_amount', '125.00');
                            set('collection_methods', 'both');
                            set('collection_instructions', 'UAT browser instructions');
                        }
                    }
                    document.querySelector('[data-rental-wizard-tab="review"]').click();
                }, mode, collectOnDelivery);
                if(mode==='create'){
                    await page.waitForSelector('#incentive_owner_id',{visible:true});
                    const owner=await page.$eval('#incentive_owner_id',el=>[...el.options].find(option=>option.textContent==='Earlier shift salesperson').value);
                    await page.select('#incentive_owner_id',owner);
                    await page.select('#incentive_source','incoming_phone');
                }
                const mobile=width<=640, selector=mobile?'#rentalMobileSaveButton':'#rentalSubmitButton';
                assert.equal(await page.$eval(selector,button=>button.form?.id),'rentalCreateForm');
                if(mobile){
                    // Async availability updates the primary action after an input event.
                    // Check the observer in the same task/microtask turn. A pending
                    // availability response may legitimately change it on the next
                    // task, so a later polling callback can miss the tested state.
                    assert.equal(await page.$eval('#rentalSubmitButton',async button=>{
                        button.disabled=true;
                        await new Promise(resolve=>queueMicrotask(resolve));
                        return document.getElementById('rentalMobileSaveButton').disabled;
                    }),true);
                    assert.equal(await page.$eval('#rentalSubmitButton',async button=>{
                        button.disabled=false;
                        await new Promise(resolve=>queueMicrotask(resolve));
                        return document.getElementById('rentalMobileSaveButton').disabled;
                    }),false);
                }
                await page.click(selector);
                const submissions=await page.evaluate(()=>window.rentalSubmits);
                assert.equal(submissions.length,1);
                assert.ok(submissions[0].data._token);
                assert.equal(submissions[0].data.action,'save');
                assert.equal(submissions[0].data.product_id,'1');
                assert.equal(submissions[0].data.quantity,'1');
                assert.equal(submissions[0].data._method,mode==='edit'?'PUT':undefined);
                if(mode==='create'){
                    assert.ok(submissions[0].data.incentive_owner_id);
                    assert.equal(submissions[0].data.incentive_source,'incoming_phone');
                    assert.equal(submissions[0].data.fulfilment_source, 'in_house');
                    if (collectOnDelivery) {
                        assert.equal(submissions[0].data.delivery_assignment_type, 'ph_internal');
                        assert.match(submissions[0].data.delivery_staff_id, /^user:\d+$/);
                        assert.equal(submissions[0].data.collection_enabled, '1');
                        assert.equal(submissions[0].data.collection_amount, '125.00');
                        assert.equal(submissions[0].data.collection_methods, 'both');
                        assert.equal(submissions[0].data.collection_instructions, 'UAT browser instructions');
                    } else {
                        assert.equal(submissions[0].data.delivery_assignment_type, 'vendor');
                        assert.equal(submissions[0].data.delivery_vendor_id, '1');
                        assert.equal(submissions[0].data.collection_mode, 'pay_on_delivery');
                        assert.ok(submissions[0].data.collection_assignee_id);
                    }
                }else{
                    assert.equal(submissions[0].data.incentive_owner_id,undefined);
                }
                assert.ok(new URL(submissions[0].action).pathname=== (mode==='edit'?'/rentals/1':'/rentals'));
                // Native required validation must continue preventing invalid saves.
                await page.$eval('#end_date',field=>{field.value='';});
                await page.click(selector);
                assert.equal(await page.evaluate(()=>window.rentalSubmits.length),1);
                const owningStep=await page.$eval('#end_date',e=>e.closest('[data-rental-wizard-step]').dataset.rentalWizardStep);
                assert.equal(await page.$eval('[data-rental-wizard-tab].is-active',e=>e.dataset.rentalWizardTab),owningStep);
            } finally {await page.close();}
        });
        assert.deepEqual(errors,[]);assert.deepEqual(writes,[]);
    } finally {if(browser)await browser.close();await new Promise(r=>server.close(r));}
});
