const fs = require('fs'), path = require('path'), cp = require('child_process'), crypto = require('crypto');
const assert = require('assert/strict');
const root = path.resolve(__dirname, '../..');
const php = process.env.PHOS_TEST_PHP || 'C:/xampp/php/php.exe';
if (!process.argv[2]) throw Error('Pass the private disposable loopback MariaDB instance JSON.');
const instance = JSON.parse(fs.readFileSync(process.argv[2], 'utf8').replace(/^\uFEFF/, ''));
const run = path.join(root, 'storage/framework/testing/corrections-' + crypto.randomBytes(6).toString('hex'));
fs.mkdirSync(run, {recursive: true});
const config = path.join(run, 'database.json');
fs.writeFileSync(config, JSON.stringify({host: '127.0.0.1', port: instance.port,
  database: 'phos_validation_' + crypto.randomBytes(6).toString('hex'), username: 'root', password: '',
  expectedDataDirectory: instance.dataDirectory, appKey: 'base64:' + crypto.randomBytes(32).toString('base64')}));
const worker = path.join(__dirname, 'PaymentCorrectionWorker.php');
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
function file(value) {
  const name = path.join(run, crypto.randomBytes(8).toString('hex') + '.json');
  fs.writeFileSync(name, JSON.stringify(value)); return name;
}
function sync(mode, input) {
  const result = cp.spawnSync(php, [worker, config, mode, ...(input ? [file(input)] : [])], {cwd: root, encoding: 'utf8', timeout: 45000, windowsHide: true});
  if (result.status !== 0) throw Error(result.stderr + result.stdout);
  return JSON.parse(result.stdout.trim());
}
function request(input) {
  const child = cp.spawn(php, [worker, config, 'request', file(input)], {cwd: root, windowsHide: true});
  const state = {done: false, out: '', error: ''};
  child.stdout.on('data', x => state.out += x); child.stderr.on('data', x => state.error += x);
  state.result = new Promise((resolve, reject) => {
    const timer = setTimeout(() => {child.kill(); reject(Error('Worker timeout'));}, 25000);
    child.on('error', error => {clearTimeout(timer); reject(error);});
    child.on('exit', code => {state.done = true; clearTimeout(timer);
      if (code !== 0) return reject(Error(state.error + state.out));
      try {resolve(JSON.parse(state.out.trim()));} catch(error) {reject(error);}
    });
  });
  state.result.catch(() => {}); return state;
}
async function barrier(fileName, state) {
  const end = Date.now() + 10000;
  while (!fs.existsSync(fileName)) {
    if (state.done || Date.now() > end) throw Error('Barrier failed: ' + state.error + state.out);
    await delay(20);
  }
}
async function scenario(name, operation, failAfterReceipt = false, independent = false) {
  const a = sync('fixture'), b = independent ? sync('fixture') : a;
  const dir = path.join(run, name); fs.mkdirSync(dir);
  const release = path.join(dir, 'release');
  const ia = {...a, operation: 'reverse', entered: path.join(dir, 'a-entered'), started: path.join(dir, 'a-started'), release, failAfterReceipt};
  const ib = {...b, operation, token: crypto.randomUUID(), amount: 800, entered: path.join(dir, 'b-entered'), started: path.join(dir, 'b-started')};
  const first = request(ia); let second;
  try {
    await barrier(ia.entered, first); second = request(ib); await barrier(ib.started, second);
    if (independent) {
      await Promise.race([second.result, delay(5000).then(() => {throw Error('Independent organization blocked');})]);
    } else {
      await delay(500); assert.equal(second.done, false, 'Second request passed the organization lock');
      assert.equal(fs.existsSync(ib.entered), false);
    }
  } finally {fs.writeFileSync(release, 'release');}
  const [ra, rb] = await Promise.all([first.result, second.result]);
  const s = sync('summary', a);
  assert.deepEqual([ra.status, rb.status], independent ? [302, 302] : failAfterReceipt ? [422, 302] : operation === 'payment' ? [302, 302] : [302, 422]);
  assert.equal(s.reversals, 1); assert.equal(s.audits, 1);
  assert.equal(s.payments, operation === 'payment' ? 3 : 2);
  assert.equal(s.amount, operation === 'payment' ? 800 : 0);
  assert.equal(s.balance, 1000 - s.amount);
  if (independent) {const other = sync('summary', b); assert.equal(other.reversals, 1); assert.equal(other.audits, 1);}
  console.log(JSON.stringify({name, statuses: [ra.status, rb.status], ...s, passed: true}));
}
(async () => {
  console.log(JSON.stringify(sync('init')));
  await scenario('same-receipt', 'reverse');
  await scenario('correction-and-new-payment', 'payment');
  await scenario('correction-and-invoice-delete', 'delete_invoice');
  await scenario('failed-correction-and-retry', 'reverse', true);
  await scenario('independent-organizations', 'reverse', false, true);
})().catch(error => {console.error(error); process.exitCode = 1;});
