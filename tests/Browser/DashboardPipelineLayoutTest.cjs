const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {test} = require('node:test');
const puppeteer = require('puppeteer');

const repository = path.resolve(__dirname, '../..');
const blade = fs.readFileSync(path.join(repository, 'resources/views/dashboard.blade.php'), 'utf8');
const inlineStyles = [...blade.matchAll(/<style>([\s\S]*?)<\/style>/g)].map(match => match[1]).join('\n');
const baseStyles = fs.readFileSync(path.join(repository, 'resources/css/app.css'), 'utf8');
const stylesheet = fs.readFileSync(process.env.PHOS_DASHBOARD_PIPELINE_STYLESHEET
    || path.join(repository, 'public/css/phos-executive-dashboard.css'), 'utf8');
const stageTemplates = [...blade.matchAll(/<div class="control-room-pipeline-stage\b[\s\S]*?<\/div>/g)];
const stageTemplate = stageTemplates.at(-1)?.[0];
const stageSource = blade.match(/\$pipelineStages = collect\(\[([\s\S]*?)\]\)->values\(\);/)?.[1];
const labels = [...(stageSource || '').matchAll(/'label'\s*=>\s*'([^']+)'/g)].map(match => match[1]);
const counts = ['12', '8', '123,456', '34', '7', '19'];
const escape = value => value.replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;');

test('actual dashboard pipeline stages keep complete labels and every count readable on phones', {timeout:60000}, async () => {
    assert.ok(stageTemplate && inlineStyles, 'Exercise the actual Blade stage markup and its existing styles.');
    assert.deepEqual(labels, ['Created', 'Assigned', 'Out for Delivery', 'Active Rental', 'Return Due', 'Completed']);
    const stages = labels.map((label, index) => stageTemplate
        .replace(/{{\s*\$toneCardClass\([\s\S]*?}}/, 'is-info')
        .replace(/{!!\s*\$dashboardIcon\(\$stageIcon\)\s*!!}/, '<svg width="20" height="20" aria-hidden="true"><path d="M3 3h14v14H3z"/></svg>')
        .replace(/{{\s*\$stage\['label'\]\s*}}/, escape(label))
        .replace(/{{\s*number_format\([\s\S]*?}}/, counts[index])).join('');
    const html = `<!doctype html><html><head><meta charset="utf-8"><style>${baseStyles}\n${inlineStyles}\n${stylesheet}
        html,body{margin:0;padding:0}
        [data-executive-dashboard]{width:calc(100% - 20px);max-width:1320px;margin:10px auto}
        </style></head><body class="phos-executive-page"><main data-executive-dashboard>
        <section class="control-room-card operations-pipeline-wrap"><div class="control-room-pipeline-track">${stages}</div></section>
        </main></body></html>`;
    const browser = await puppeteer.launch({headless:true,args:['--disable-background-networking','--no-first-run',
        ...(process.env.CI ? ['--no-sandbox','--disable-setuid-sandbox'] : [])]});
    try {
        const page = await browser.newPage();
        await page.setJavaScriptEnabled(false);
        await page.setRequestInterception(true);
        page.on('request', request => request.url().startsWith('data:') ? request.continue() : request.abort());
        for (const width of [320, 390, 480, 481, 768, 1440]) {
            await page.setViewport({width,height:900});
            await page.setContent(html);
            const geometry = await page.evaluate(() => {
                const track = document.querySelector('.control-room-pipeline-track');
                const rect = element => {const r=element.getBoundingClientRect();return{left:r.left,right:r.right,top:r.top,bottom:r.bottom,width:r.width,height:r.height};};
                return {columns:getComputedStyle(track).gridTemplateColumns.split(' ').length,
                    documentWidth:document.documentElement.scrollWidth,
                    rows:[...track.children].map(row => {
                        const label=row.querySelector('.control-room-pipeline-stage-label'), value=row.querySelector('.control-room-pipeline-stage-value');
                        return {label:label.textContent,value:value.textContent,row:rect(row),icon:rect(row.querySelector('.control-room-pipeline-stage-icon')),
                            text:rect(label),count:rect(value),fontSize:parseFloat(getComputedStyle(label).fontSize),lineHeight:parseFloat(getComputedStyle(label).lineHeight)};
                    })};
            });
            assert.deepEqual(geometry.rows.map(row => row.label), labels);
            assert.deepEqual(geometry.rows.map(row => row.value), counts);
            assert.ok(geometry.documentWidth <= width + 1, `Pipeline causes page overflow at ${width}px.`);
            assert.equal(geometry.columns, width <= 480 ? 1 : width <= 720 ? 2 : width <= 900 ? 3 : 6);
            if (width <= 480) {
                for (const row of geometry.rows) {
                    assert.ok(row.fontSize >= 12, `Label remains undersized: ${row.label}, ${width}px.`);
                    assert.ok(row.text.height <= row.lineHeight + 1, `A complete stage label must fit a single line: ${row.label}, ${width}px.`);
                    assert.ok(row.icon.right <= row.text.left && row.text.right <= row.count.left,
                        `Stage icon/label/count overlap: ${row.label}, ${width}px.`);
                    assert.ok(row.row.left <= row.icon.left && row.count.right <= row.row.right,
                        `The stage count must remain inside its row: ${row.label}, ${width}px.`);
                }
            }
        }
    } finally {await browser.close();}
});
