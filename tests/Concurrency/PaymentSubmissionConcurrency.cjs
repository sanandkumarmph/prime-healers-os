const fs = require('fs'), path = require('path'), cp = require('child_process'), crypto = require('crypto');
const assert = require('assert/strict');
const root = path.resolve(__dirname, '../..');
const php = process.env.PHOS_TEST_PHP || 'C:/xampp/php/php.exe';
const instancePath = process.argv[2];
if (!instancePath) throw Error('Pass the private JSON describing a newly initialized loopback MariaDB instance.');
const instance = JSON.parse(fs.readFileSync(instancePath, 'utf8').replace(/^\uFEFF/, ''));
const run = path.join(root, 'storage/framework/testing/phos-concurrency-' + crypto.randomBytes(6).toString('hex'));
fs.mkdirSync(run, {recursive: true});
const configPath = path.join(run, 'database.json');
fs.writeFileSync(configPath, JSON.stringify({
  host: '127.0.0.1', port: instance.port, database: 'phos_validation_' + crypto.randomBytes(6).toString('hex'),
  username: 'root', password: '', expectedDataDirectory: instance.dataDirectory,
  appKey: 'base64:' + crypto.randomBytes(32).toString('base64')
}));
const worker = path.join(__dirname, 'PaymentSubmissionWorker.php');
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
function sync(mode, input) {
  const args = [worker, configPath, mode];
  if (input) { const file = path.join(run, crypto.randomBytes(6).toString('hex') + '.json'); fs.writeFileSync(file, JSON.stringify(input)); args.push(file); }
  const result = cp.spawnSync(php, args, {cwd: root, encoding: 'utf8', timeout: 45000});
  if (result.status !== 0) throw Error(mode + ': ' + result.stderr + result.stdout);
  return JSON.parse(result.stdout.trim());
}
function request(input) {
  const file = path.join(run, crypto.randomBytes(6).toString('hex') + '.json');
  fs.writeFileSync(file, JSON.stringify(input));
  const child = cp.spawn(php, [worker, configPath, 'request', file], {cwd: root, windowsHide: true});
  const state = {done: false, stdout: '', stderr: '', child};
  child.stdout.on('data', b => state.stdout += b);
  child.stderr.on('data', b => state.stderr += b);
  state.result = new Promise((resolve, reject) => {
    const timer = setTimeout(() => { child.kill(); reject(Error('Worker timeout')); }, 25000);
    child.on('error', e => { clearTimeout(timer); reject(e); });
    child.on('exit', code => { state.done = true; clearTimeout(timer); if (code !== 0) reject(Error(state.stderr + state.stdout)); else { try { resolve(JSON.parse(state.stdout.trim())); } catch(e) { reject(e); } } });
  });
  // Keep rejection observable until the barrier code consumes the result.
  state.result.catch(() => {});
  return state;
}
async function waitFile(file, state) {
  const end = Date.now() + 10000;
  while (!fs.existsSync(file)) {
    if (state.done) throw Error('Worker finished before its barrier: ' + state.stderr + state.stdout);
    if (Date.now() > end) throw Error('Barrier not reached');
    await delay(20);
  }
}
async function scenario(name, amountA, amountB, sameToken, failAfterReceipt = false, independent = false) {
  const a = sync('fixture'), b = independent ? sync('fixture') : a;
  const dir = path.join(run, name); fs.mkdirSync(dir);
  const token = crypto.randomUUID(), release = path.join(dir, 'release');
  const ia = {...a, token, amount: amountA, entered: path.join(dir, 'a-entered'), started: path.join(dir, 'a-started'), release, failAfterReceipt};
  const ib = {...b, token: sameToken ? token : crypto.randomUUID(), amount: amountB, entered: path.join(dir, 'b-entered'), started: path.join(dir, 'b-started')};
  const first = request(ia); let second, ra, rb;
  try {
    await waitFile(ia.entered, first);
    second = request(ib);
    await waitFile(ib.started, second);
    if (independent) {
      rb = await Promise.race([second.result, delay(5000).then(() => { throw Error('Independent organization was blocked'); })]);
      assert.equal(rb.status, 302);
    } else {
      await delay(500);
      assert.equal(second.done, false, 'Second request completed while the first held its organization lock');
      assert.equal(fs.existsSync(ib.entered), false, 'Second request reached controller before the first committed');
    }
  } finally {
    fs.writeFileSync(release, 'release');
  }
  [ra, rb] = await Promise.all([first.result, second.result]);
  const summary = sync('summary', a);
  if (independent) {
    assert.deepEqual([ra.status, rb.status], [302, 302]);
    const other = sync('summary', b);
    assert.equal(summary.payments, 1); assert.equal(other.payments, 1);
  } else if (failAfterReceipt) {
    assert.deepEqual([ra.status, rb.status], [422, 302]);
    assert.equal(summary.payments, 1); assert.equal(summary.claims, 1); assert.equal(summary.amount, amountB);
  } else if (sameToken) {
    assert.deepEqual([ra.status, rb.status], [302, 422]);
    assert.equal(summary.payments, 1); assert.equal(summary.claims, 1); assert.equal(summary.amount, amountA);
  } else if (amountA + amountB > 1000) {
    assert.deepEqual([ra.status, rb.status], [302, 422]);
    assert.equal(summary.payments, 1); assert.equal(summary.claims, 1); assert.equal(summary.amount, amountA);
  } else {
    assert.deepEqual([ra.status, rb.status], [302, 302]);
    assert.equal(summary.payments, 2); assert.equal(summary.claims, 2); assert.equal(summary.amount, amountA + amountB);
  }
  assert.equal(summary.balance, 1000 - summary.amount);
  const report = {name, statuses: [ra.status, rb.status], ...summary, passed: true};
  console.log(JSON.stringify(report));
  return report;
}
(async () => {
  const initialization = sync('init'); console.log(JSON.stringify(initialization));
  const reports = [];
  reports.push(await scenario('same-token', 100, 100, true));
  reports.push(await scenario('overpayment', 600, 600, false));
  reports.push(await scenario('equal-instalments', 500, 500, false));
  reports.push(await scenario('rollback-and-retry', 100, 100, true, true));
  reports.push(await scenario('independent-organizations', 100, 100, false, false, true));
  fs.writeFileSync(path.join(run, 'results.json'), JSON.stringify({initialization, reports}, null, 2));
  console.log('PAYMENT_CONCURRENCY_PASSED=5');
  console.log('RESULT_DIRECTORY=' + run);
})().catch(error => { fs.writeFileSync(path.join(run, 'failure.txt'), error.stack); console.error(error.stack); console.error('RESULT_DIRECTORY=' + run); process.exitCode = 1; });

