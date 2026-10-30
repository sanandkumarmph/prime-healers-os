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
    const browser = await puppeteer.launch({headless:true,args:['--disable-background-networking','--no-first-run', ...(process.env.CI ? ['--no-sandbox','--disable-setuid-sandbox'] : [])]});
    try {
        const page = await browser.newPage();
        for (const timezone of ['Asia/Kolkata', 'America/New_York', 'Europe/London']) {
            await page.emulateTimezone(timezone);
            for (const [start, end, expected] of [['2026-03-07','2026-03-09',3],['2026-10-31','2026-11-02',3],['2028-02-28','2028-03-01',3],['2026-12-31','2027-01-01',2],['2026-10-30','2026-10-30',1]]) {
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
