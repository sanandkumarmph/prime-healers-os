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
    let origin, stockScenario;
    const writes=[], errors=[];
    const server=http.createServer(async (req,res)=>{
        const url=new URL(req.url,origin);
        if (stockScenario && req.method === 'POST' && url.pathname === '/rentals/rental-stock') {
            const chunks = [];
            for await (const chunk of req) chunks.push(chunk);
            const body = Buffer.concat(chunks).toString('utf8');
            const field = name => body.match(new RegExp('name="' + name + '"\\r\\n\\r\\n([^\\r\\n]*)'))?.[1] || '';
            const asset = {
                id: 901 + stockScenario.assets.length,
                product_id: Number(field('product_id')),
                warehouse_id: Number(field('warehouse_id')),
                label: 'Test serialized oxygen cylinder',
                serial_number: field('serial_number'),
                barcode_value: '',
                asset_status: 'available',
                condition_status: 'good',
                warehouse: 'Main',
            };
            stockScenario.assets.push(asset);
            res.writeHead(201, {'Content-Type':'application/json'});
            res.end(JSON.stringify({asset}));
            return;
        }
        if (stockScenario && req.method === 'GET' && url.pathname === '/rentals/available-assets') {
            const product = Number(url.searchParams.get('product_id'));
            const warehouse = Number(url.searchParams.get('dispatch_warehouse_id'));
            const assets = stockScenario.assets.filter(asset => asset.product_id === product
                && (!warehouse || asset.warehouse_id === warehouse));
            stockScenario.lookups.push({product, warehouse, ids: assets.map(asset => asset.id)});
            res.writeHead(200, {'Content-Type':'application/json'});
            res.end(JSON.stringify({data:assets}));
            return;
        }
        if(!['GET','HEAD'].includes(req.method)){writes.push(req.method);res.writeHead(405);res.end();return;}
        if(/^\/fixture\/(create|edit)$/.test(url.pathname)){
            res.setHeader('Content-Type','text/html; charset=utf-8');
            const html = fs.readFileSync(path.join(folder,'rendered','rental-'+url.pathname.split('/').pop()+'.html'),'utf8');
            const escapedOrigin = value => value.replaceAll('/', '\\/');
            res.end(html.replaceAll('http://127.0.0.1:8000',origin)
                .replaceAll(escapedOrigin('http://127.0.0.1:8000'), escapedOrigin(origin)));
            return;
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
                await page.goto(origin+'/fixture/'+mode+(mode==='edit'?'#rental-assets-section':''),{waitUntil:'networkidle0'});
                await page.addStyleTag({content:'*{scroll-behavior:auto!important}'});
                const activeStep = () => page.$eval('#rentalDesktopProgress [aria-current="step"]', el => el.dataset.rentalWizardTab);
                if(mode==='edit'){
                    assert.equal(await activeStep(),'product');
                    assert.equal(await page.evaluate(()=>document.activeElement?.closest('section')?.id),'rental-assets-section');
                }else{
                    assert.equal(await activeStep(),'fulfilment');
                    const labels = await page.evaluate(()=>[
                        [...document.querySelectorAll('#rentalDesktopProgress strong')].map(el=>el.textContent.trim()),
                        [...document.querySelectorAll('#rentalMobileProgress .rental-mobile-step-label')].map(el=>el.textContent.trim()),
                    ]);
                    assert.deepEqual(labels[0], ['Customer','Items & dates','Charges','Delivery','Review']);
                    assert.deepEqual(labels[1], labels[0]);
                    assert.equal(await page.$eval('#rentalDesktopProgress [data-rental-wizard-tab="delivery"]', el=>el.classList.contains('is-warning')),false);
                    if (width <= 640) {
                        const badgeIsAboveActions = await page.evaluate(() => {
                            const badge = document.querySelector('.phos-release-badge')?.getBoundingClientRect();
                            const actions = document.getElementById('rentalMobileActionBar').getBoundingClientRect();
                            return badge && badge.height > 0 && badge.bottom < actions.top;
                        });
                        assert.equal(badgeIsAboveActions,true);
                    }
                    assert.match(await page.$eval('#rentalUseSuggestedCity', el=>el.textContent), /Bengaluru/);
                    await page.select('#city_id','2');
                    assert.equal(await page.$eval('#rentalUseSuggestedCity', el=>el.hidden),true);
                    assert.equal(await page.$eval('#city_id', el=>el.value),'2');
                    await page.select('#city_id','');
                    await page.$eval('#rentalUseSuggestedCity', el=>el.scrollIntoView({block:'center'}));
                    await page.click('#rentalUseSuggestedCity');
                    assert.equal(await page.$eval('#city_id', el=>el.value),'1');
                }
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
                assert.equal(await page.$eval('#rental-review-section [data-summary-value="duration"]', el=>el.textContent),'6 days');
                await page.click('[data-rental-review-edit="product"]');
                assert.equal(await activeStep(),'product');
                assert.equal(await page.evaluate(()=>document.activeElement?.textContent.trim()),'Items & dates');
                await page.$eval('[data-rental-wizard-tab="review"]',button=>button.click());
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
        for (const width of [390,1440]) await t.test('assign freshly added rental stock at ' + width, async () => {
            stockScenario = {assets: [], lookups: []};
            const page = await browser.newPage();
            const click = async (selector) => {
                await page.$eval(selector, element => element.scrollIntoView({block:'center', behavior:'instant'}));
                await page.click(selector);
            };
            const addStock = async (trigger, serial) => {
                await click(trigger);
                await page.waitForSelector('#rentalStockModal', {visible:true});
                assert.equal(await page.$eval('#rental_stock_warehouse_id', field => field.value), '1');
                await page.type('#rental_stock_serial_number', serial);
                await click('#saveRentalStockButton');
                await page.waitForSelector('#rentalStockModal', {hidden:true});
            };
            const primaryAssetIds = () => page.$eval('#rentalCreateForm', form => new FormData(form).getAll('asset_ids[]'));

            try {
                await page.setViewport({width, height:width < 768 ? 844 : 1000});
                page.on('pageerror', error => errors.push(error.message));
                await page.setRequestInterception(true);
                page.on('request', req => req.url().startsWith(origin + '/') || req.url().startsWith('data:')
                    ? req.continue() : req.respond({status:200, body:''}));
                await page.goto(origin + '/fixture/create', {waitUntil:'networkidle0'});
                let pageNavigations = 0;
                page.on('framenavigated', frame => { if (frame === page.mainFrame()) pageNavigations++; });
                await page.select('#city_id', '1');
                await click(width <= 640 ? '#rentalMobileProgress [data-rental-mobile-step="product"]' : '#rentalDesktopProgress [data-rental-wizard-tab="product"]');
                const product = await page.$eval('#product_id', field => [...field.options]
                    .find(option => option.dataset.productName === 'Test serialized oxygen cylinder')?.value);
                assert.ok(product);
                await page.select('#product_id', product);
                await page.select('#dispatch_warehouse_id', '1');
                await page.$eval('#rental_amount', field => {
                    field.value = '789';
                    field.dispatchEvent(new Event('input', {bubbles:true}));
                });

                // Open the empty picker first so a previously loaded result cannot mask the regression.
                await click('#rentalAssignAssetButton');
                await page.waitForSelector('#dynamicRentalAssetModal[data-assets-loading="0"]');
                assert.equal(await page.$eval('#dynamicRentalAssetModal', modal => modal.dataset.assetsError), '0');
                assert.ok(stockScenario.lookups.some(lookup => lookup.product === Number(product) && lookup.warehouse === 1 && lookup.ids.length === 0));
                assert.equal(await page.$$eval('[data-dynamic-asset-id]', rows => rows.length), 0);
                await page.keyboard.press('Escape');
                await addStock('#openRentalStockModal', 'NEW-PRIMARY-901');
                assert.deepEqual(stockScenario.assets.map(asset => [asset.serial_number, asset.product_id, asset.warehouse_id]),
                    [['NEW-PRIMARY-901', Number(product), 1]]);
                await click('#rentalAssignAssetButton');
                await page.waitForSelector('#dynamicRentalAssetModal[data-assets-loading="0"]');
                assert.ok(stockScenario.lookups.some(lookup => lookup.product === Number(product) && lookup.warehouse === 1 && lookup.ids.includes(901)),
                    'Expected a fresh picker lookup after stock save: ' + JSON.stringify(stockScenario.lookups));
                await page.waitForSelector('[data-dynamic-asset-id="901"]', {visible:true, timeout:4000});
                assert.match(await page.$eval('[data-dynamic-asset-id="901"]', row => row.textContent), /NEW-PRIMARY-901/);
                assert.equal(await page.$eval('#dynamicRentalAssetModal', modal => {
                    const viewport = document.documentElement.clientWidth;
                    const dialog = modal.firstElementChild;
                    const bounds = dialog.getBoundingClientRect();
                    return bounds.left >= 0 && bounds.right <= viewport && dialog.scrollWidth <= dialog.clientWidth;
                }), true);
                await click('[data-dynamic-asset-id="901"]');
                await click('[data-dynamic-asset-assign]');
                assert.deepEqual(await primaryAssetIds(), ['901']);
                assert.equal(await page.$eval('#assignmentLaunchSelected', count => count.textContent.trim()), '1 of 1');

                await click('.rental-step-header [data-add-rental-item]');
                await page.select('[data-rental-product-index="0"]', product);
                await addStock('[data-rental-stock-item="0"]', 'NEW-ADDITIONAL-902');
                const additionalDetails = '[data-rental-line="0"] .sale-item-detail';
                if (await page.$eval(additionalDetails, details => details.hidden)) {
                    await click('[data-toggle-rental-item="0"]');
                }
                const addedAsset = '[data-rental-asset-input="0"][value="902"]';
                const assetLabel = '.rental-line-asset-option:has(' + addedAsset + ')';
                assert.equal(stockScenario.assets.length, 2);
                await page.waitForSelector(assetLabel, {visible:true, timeout:4000});
                assert.match(await page.$eval(assetLabel, label => label.textContent), /NEW-ADDITIONAL-902/);
                assert.equal(await page.$('[data-rental-asset-input="0"][value="901"]'), null);
                if (!await page.$eval(addedAsset, input => input.checked)) {
                    await click(assetLabel);
                }
                assert.deepEqual(await page.$eval('#rentalCreateForm', form => new FormData(form).getAll('rental_items[0][asset_ids][]')), ['902']);
                assert.deepEqual(await primaryAssetIds(), ['901']);
                assert.equal(await page.$eval('#rental_amount', field => Number(field.value)), 789);
                assert.equal(pageNavigations, 0);
                assert.deepEqual(stockScenario.assets.map(asset => [asset.serial_number, asset.product_id, asset.warehouse_id]),
                    [['NEW-PRIMARY-901', Number(product), 1], ['NEW-ADDITIONAL-902', Number(product), 1]]);
                assert.equal(await page.$eval('#rentalAssignAssetButton', button => Boolean(button.closest('.primary-rental-product-card'))), true);
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
            } finally {
                await page.close();
                stockScenario = null;
            }
        });
        assert.deepEqual(errors,[]);assert.deepEqual(writes,[]);
    } finally {if(browser)await browser.close();await new Promise(r=>server.close(r));}
});
