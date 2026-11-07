const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process'),crypto=require('node:crypto'),assert=require('node:assert/strict');
const root=path.resolve(__dirname,'../..'),instance=JSON.parse(fs.readFileSync(process.argv[2],'utf8').replace(/^\uFEFF/,''));
const run=fs.mkdtempSync(path.join(root,'storage/framework/testing/gateway-concurrency-'));
const config=path.join(run,'database.json'),php=process.env.PHOS_TEST_PHP||'C:/xampp/php/php.exe',worker=path.join(__dirname,'CustomerPaymentLinkWorker.php');
fs.writeFileSync(config,JSON.stringify({host:'127.0.0.1',port:instance.port,database:'phos_validation_'+crypto.randomBytes(6).toString('hex'),username:'root',password:'',expectedDataDirectory:instance.dataDirectory,appKey:'base64:'+crypto.randomBytes(32).toString('base64')}));
function inputFile(data){const file=path.join(run,crypto.randomUUID()+'.json');fs.writeFileSync(file,JSON.stringify(data));return file;}
function sync(mode,input={}){const r=cp.spawnSync(php,[worker,config,mode,inputFile(input)],{cwd:root,encoding:'utf8',timeout:45000,windowsHide:true});assert.equal(r.status,0,r.stderr+r.stdout);return JSON.parse(r.stdout.trim());}
function asyncWorker(mode,input){const child=cp.spawn(php,[worker,config,mode,inputFile(input)],{cwd:root,windowsHide:true});let stdout='',stderr='';const state={done:false};state.result=new Promise((resolve,reject)=>{child.stdout.on('data',b=>stdout+=b);child.stderr.on('data',b=>stderr+=b);child.on('error',reject);child.on('exit',code=>{state.done=true;code===0?resolve(JSON.parse(stdout.trim())):reject(Error(stderr+stdout));});});state.result.catch(()=>{});return state;}
const delay=ms=>new Promise(r=>setTimeout(r,ms));
(async()=>{
    let initialized=false;
    try{
        sync('init');initialized=true;
        for(const mode of ['receive','manual']){
            const f=sync('fixture'),entered=path.join(run,mode+'-entered'),release=path.join(run,mode+'-release');
            const a=asyncWorker('receive',{...f,entered,release});let b;
            try{
                const deadline=Date.now()+12000;while(!fs.existsSync(entered)){assert.ok(!a.done&&Date.now()<deadline,'First receipt must reach the locked barrier');await delay(25);}
                b=asyncWorker(mode,f);await delay(650);assert.equal(b.done,false,'Concurrent operation must wait for the PHOS organization lock');
            }finally{fs.writeFileSync(release,'release');}
            const results=await Promise.all([a.result,b.result]);assert.equal(results[0].received,true);
            if(mode==='manual')assert.equal(results[1].manual_blocked,true);else assert.equal(results[1].received,true);
            const state=sync('summary',f);assert.deepEqual(state,{payments:1,receipts:1,balance:0,status:'paid'});
            console.log(JSON.stringify({scenario:mode==='manual'?'gateway-versus-manual-collection':'duplicate-gateway-callback',...state,passed:true}));
        }
        console.log('GATEWAY_MARIADB_CONCURRENCY_CHECKS=2');
    }finally{if(initialized)console.log(JSON.stringify(sync('cleanup')));}
})().catch(e=>{console.error(e.stack);process.exitCode=1;});
