const fs=require('node:fs'), path=require('node:path'), cp=require('node:child_process'), crypto=require('node:crypto');
const assert=require('node:assert/strict');
const root=path.resolve(__dirname,'../..');
const php=process.env.PHOS_TEST_PHP || 'php';
if (!process.argv[2]) throw Error('Pass the private disposable MariaDB instance JSON.');
const instance=JSON.parse(fs.readFileSync(process.argv[2],'utf8').replace(/^\uFEFF/,''));
const run=path.join(root,'storage/framework/testing/common-'+crypto.randomBytes(6).toString('hex')); fs.mkdirSync(run,{recursive:true});
const config=path.join(run,'database.json');
fs.writeFileSync(config,JSON.stringify({host:'127.0.0.1',port:instance.port,database:'phos_validation_'+crypto.randomBytes(6).toString('hex'),
  username:'root',password:'',expectedDataDirectory:instance.dataDirectory,appKey:'base64:'+crypto.randomBytes(32).toString('base64')}));
const worker=path.join(__dirname,'CommonWorkflowWorker.php');
const delay=ms=>new Promise(resolve=>setTimeout(resolve,ms));
const results=[];
function file(input) { const name=path.join(run,crypto.randomBytes(8).toString('hex')+'.json');fs.writeFileSync(name,JSON.stringify(input));return name; }
function sync(mode,input) {
  const result=cp.spawnSync(php,[worker,config,mode,...(input?[file(input)]:[])],{cwd:root,encoding:'utf8',timeout:90000,windowsHide:true});
  if(result.status!==0)throw Error(result.stderr+result.stdout);
  try{return JSON.parse(result.stdout.trim());}catch(error){throw Error(result.stdout+'\n'+error.message);}
}
function request(input) {
  const child=cp.spawn(php,[worker,config,'request',file(input)],{cwd:root,windowsHide:true});
  const state={done:false,out:'',error:''}; child.stdout.on('data',x=>state.out+=x);child.stderr.on('data',x=>state.error+=x);
  state.result=new Promise((resolve,reject)=>{
    const timer=setTimeout(()=>{child.kill();reject(Error('Request timeout'));},40000);
    child.on('error',error=>{clearTimeout(timer);reject(error);});
    child.on('exit',code=>{state.done=true;clearTimeout(timer);if(code!==0)return reject(Error(state.error+state.out));
      try{resolve(JSON.parse(state.out.trim()));}catch(error){reject(Error(state.error+state.out+'\n'+error.message));}});
  });state.result.catch(()=>{});return state;
}
async function barrier(name,state) {
  const end=Date.now()+15000;
  while(!fs.existsSync(name)){if(state.done||Date.now()>end)throw Error('Barrier not reached: '+state.error+state.out);await delay(20);}
}
async function scenario(isolation,spec) {
  const a=sync('fixture',spec.fixture||{}), b=spec.independent?sync('fixture',spec.fixture||{}):a;
  const dir=path.join(run,spec.name+'-'+isolation.replaceAll(' ','-'));fs.mkdirSync(dir);
  const token=crypto.randomUUID(),release=path.join(dir,'release');
  const firstInput={...a,isolation,operation:spec.a,token,amount:spec.amountA,started:path.join(dir,'a-started'),entered:path.join(dir,'a-entered'),release,failAfterSave:!!spec.rollback};
  const secondInput={...b,isolation,operation:spec.b||spec.a,token:spec.sameToken?token:crypto.randomUUID(),amount:spec.amountB??spec.amountA,
    started:path.join(dir,'b-started'),entered:path.join(dir,'b-entered')};
  const first=request(firstInput);let second;
  try {
    await barrier(firstInput.entered,first);second=request(secondInput);await barrier(secondInput.started,second);
    if(spec.independent){await Promise.race([second.result,delay(8000).then(()=>{throw Error('Unrelated organisation was blocked');})]);}
    else{await delay(500);assert.equal(second.done,false,'Second request must wait for organisation transaction');assert.equal(fs.existsSync(secondInput.entered),false,'Second request entered before the first committed');}
  } finally {fs.writeFileSync(release,'release');}
  const response=await Promise.all([first.result,second.result]);
  const statuses=response.map(x=>x.status);assert.deepEqual(statuses,spec.statuses||[302,302],JSON.stringify(response));
  const summary=sync('summary',a);for(const [key,value] of Object.entries(spec.expect||{}))assert.deepEqual(summary[key],value,spec.name+': '+key);
  if(spec.cash)for(const [key,value] of Object.entries(spec.cash))assert.equal(summary.cash[key],value,spec.name+': cash '+key);
  if(spec.independent)assert.equal(sync('summary',b).paid,150);
  results.push({name:spec.name,isolation,statuses,summary});
  fs.writeFileSync(path.join(run,'results.json'),JSON.stringify({server,results},null,2));
  console.log('PASS '+isolation+' '+spec.name);
}
let server;
(async()=>{
  server=sync('init');console.log('DATABASE_READY='+server.version);
  const scenarios=[
    {name:'collection-replay',a:'collection',sameToken:true,expect:{payments:1,collections:1,paid:150,balance:150}},
    {name:'competing-collections',a:'collection',amountA:200,statuses:[302,422],expect:{payments:1,collections:1,paid:200,balance:100}},
    {name:'collection-versus-manual-payment',a:'collection',b:'payment',amountA:200,statuses:[302,422],expect:{payments:1,paid:200}},
    {name:'handover-replay',fixture:{cash:true},a:'handover',sameToken:true,expect:{payments:1,handovers:1,paid:150},cash:{pending:10000,available:5000}},
    {name:'handover-overbooking',fixture:{cash:true},a:'handover',statuses:[302,422],expect:{handovers:1,paid:150},cash:{pending:10000}},
    {name:'receipt-replay',fixture:{handover:true},a:'confirm',sameToken:true,expect:{payments:1,handovers:1,paid:150},cash:{confirmed:6000,outstanding:9000}},
    {name:'competing-receipts',fixture:{handover:true},a:'confirm',amountA:60,amountB:100,statuses:[302,422],cash:{confirmed:6000,outstanding:9000}},
    {name:'online-verification-replay',fixture:{online:true},a:'review',expect:{payments:1,collections:1,paid:150}},
    {name:'online-verification-versus-manual-payment',fixture:{online:true},a:'review',b:'payment',amountB:200,statuses:[302,422],expect:{payments:1,paid:150}},
    {name:'correction-versus-handover',fixture:{cash:true},a:'reverse',b:'handover',statuses:[302,422],expect:{payments:2,paid:0,handovers:0,balance:300}},
    {name:'partial-delivery-replay',a:'partial',amountA:1,sameToken:true,expect:{delivered:1,submissions:1}},
    {name:'partial-delivery-overbooking',a:'partial',amountA:2,statuses:[302,422],expect:{delivered:2,submissions:1}},
    {name:'quotation-conversion-replay',fixture:{quotation:true},a:'quotation',expect:{sales:1,stock:19,quoteStatus:'converted'}},
    {name:'collection-rollback',a:'collection',sameToken:true,rollback:true,statuses:[422,302],expect:{payments:1,collections:1,paid:150}},
    {name:'quotation-rollback',fixture:{quotation:true},a:'quotation',rollback:true,statuses:[422,302],expect:{sales:1,stock:19,quoteStatus:'converted'}},
    {name:'independent-organisations',a:'collection',independent:true,expect:{payments:1,paid:150}},
  ];
  for(const isolation of ['REPEATABLE READ','READ COMMITTED'])for(const spec of scenarios.filter(spec=>!process.env.PHOS_CONCURRENCY_FOCUS || spec.name.includes(process.env.PHOS_CONCURRENCY_FOCUS)))await scenario(isolation,spec);
  console.log('PHOS_COMMON_CONCURRENCY_PASSED='+results.length);console.log('EVIDENCE='+path.join(run,'results.json'));
})().catch(error=>{fs.writeFileSync(path.join(run,'failure.json'),JSON.stringify({error:error.stack,results},null,2));console.error(error);process.exitCode=1;});
