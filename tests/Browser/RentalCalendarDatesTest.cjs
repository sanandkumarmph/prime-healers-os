const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {test} = require('node:test');
const puppeteer = require('puppeteer');

test('primary and additional rental calendar durations are independent of browser timezone and daylight saving', async () => {
    const source = fs.readFileSync(path.resolve(__dirname, '../../resources/views/rentals/_form.blade.php'), 'utf8');
    const primary = source.match(/function activeRentalDays\(\) \{[\s\S]*?\n        \}/)?.[0];
    const line = source.match(/const syncLineDurationFromDates = (function \(\) \{[\s\S]*?\n                \});/)?.[1];
    assert.ok(primary && line, 'Exercise the actual page calculations');
    const browser = await puppeteer.launch({headless:true,args:['--disable-background-networking','--no-first-run']});
    try {
        const page = await browser.newPage();
        for (const timezone of ['Asia/Kolkata', 'America/New_York', 'Europe/London', 'Pacific/Apia', 'Pacific/Kiritimati']) {
            await page.emulateTimezone(timezone);
            for (const [start, end, expected] of [['2026-03-07','2026-03-09',3],['2026-10-31','2026-11-02',3],['2028-02-28','2028-03-01',3],['2026-12-31','2027-01-01',2],['2026-10-30','2026-10-30',1],['2011-12-30','2011-12-31',2]]) {
                const result = await page.evaluate(({primary,line,start,end}) => {
                    const first = new Function('startDateInput','endDateInput','durationPresetSelect',`return (${primary})();`)({value:start},{value:end},{value:'custom'});
                    const second = new Function('rentalItems','index','startInputEl','endInputEl','durationInputEl','safeUpdateSummary',`(${line})(); return rentalItems[0].duration_days;`)([{}],0,{value:start},{value:end},{},()=>{});
                    return {first,second};
                }, {primary,line,start,end});
                assert.deepEqual(result, {first:expected,second:expected}, `${timezone}: ${start} through ${end}`);
            }
        }
    } finally {await browser.close();}
});


test('follow-up clock and draft status display IST on foreign devices', async () => {
    const modal=fs.readFileSync(path.resolve(__dirname,'../../resources/views/partials/follow-up-modal.blade.php'),'utf8');
    const helper=modal.match(/const defaultDueAt = (\(\) => \{[\s\S]*?\n            \});/)?.[1];
    assert.ok(helper);
    const browser=await puppeteer.launch({headless:true,args:['--disable-background-networking','--no-first-run']});
    try {
        const page=await browser.newPage();
        for(const timezone of ['Asia/Kolkata','UTC','America/New_York','Pacific/Kiritimati']) {
            await page.emulateTimezone(timezone);
            for(const [instant,expected] of [['2026-10-29T18:45:00Z','2026-10-30T00:15'],['2026-12-31T18:30:00Z','2027-01-01T00:00']]) {
                const actual=await page.evaluate(({helper,instant})=>{
                    const NativeDate=Date;const FakeDate=class extends NativeDate {constructor(...args){super(...(args.length?args:[instant]));}};
                    return new Function('Date',`return (${helper})();`)(FakeDate);
                },{helper,instant});
                assert.equal(actual,expected,timezone+' '+instant);
            }
        }
    } finally {await browser.close();}
});


test('rental and renewal presets keep calendar dates on foreign devices',async()=>{
    const create=fs.readFileSync(path.resolve(__dirname,'../../resources/views/rentals/_form.blade.php'),'utf8');
    const show=fs.readFileSync(path.resolve(__dirname,'../../resources/views/rentals/show.blade.php'),'utf8');
    const preset=create.match(/function syncDurationPreset\(\) \{[\s\S]*?\n        \}/)?.[0];
    const renewal=show.match(/const applyRenewalPreset = (\(\) => \{[\s\S]*?\n        \});/)?.[1];
    assert.ok(preset&&renewal);
    const draft=create.match(/new Date\(\).toLocaleTimeString\('en-IN', \{[^\n]+?\}\)/)?.[0].replace(/@json\(config\('app.timezone'\)\)/, "'Asia/Kolkata'");
    assert.ok(draft);
    const browser=await puppeteer.launch({headless:true});
    try {
        const page=await browser.newPage();
        for(const timezone of ['Asia/Kolkata','UTC','America/New_York','Pacific/Apia','Pacific/Kiritimati']) {
            await page.emulateTimezone(timezone);
            const result=await page.evaluate(({preset,renewal,draft})=>{
                const end={value:''};
                new Function('startDateInput','durationPresetSelect','endDateInput','syncPrimaryRentalAmount',`(${preset})();`)({value:'2026-03-07'},{value:'7_days'},end,()=>{});
                const renew={value:''};
                new Function('durationPreset','newEndDateInput','activeCurrentEndDate',`(${renewal})();`)({value:'7_days'},renew,'2026-03-07');
                const NativeDate=Date;const FakeDate=class extends NativeDate {constructor(...args){super(...(args.length?args:['2026-10-29T18:45:00Z']));}};
                return {end:end.value,renew:renew.value,draft:new Function('Date',`return ${draft};`)(FakeDate)};
            },{preset,renewal,draft});
            assert.equal(result.end,'2026-03-13',timezone);
            assert.equal(result.renew,'2026-03-14',timezone);
            assert.match(result.draft,/12:15/);
            assert.match(result.draft,/IST|GMT\+5:30/);
        }
    }finally{await browser.close();}
});
