/**
 * The workspace script's behaviours, in a real browser (1.7.0): the confirm sheet, the reason it
 * asks for, the signature pad, and the keys. Exit 1 on any failure. Run by build gate 2b.
 *
 *   node tools/js-check.js
 */
const fs = require('fs'), path = require('path'), os = require('os');
const { chromium } = require('playwright');
const P = path.resolve(__dirname, '..', 'plugin-source', 'wb-core');
const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'wb-js-'));
fs.writeFileSync(path.join(dir, 'page.html'), `<!doctype html><html><head><meta charset="utf-8"><link rel="stylesheet" href="file://${P}/assets/wb-workspace.css"></head><body class="wb-app">
<div class="wb-head-acts"><a href="#made-new">New quote</a></div>
<form class="wb-listbar"><label class="wb-listbar-q"><input type="search" name="q"></label></form>
<form id="f1" method="get" action="#sent" data-wb-confirm="Issue this note?"><input name="x" value="1"><button type="submit" class="wb-btn">Issue the note</button></form>
<form id="f2" method="get" action="#archived" data-wb-danger="1" data-wb-reason="Why archive it?"><input type="hidden" name="wb_reason" value=""><button type="submit">Archive</button></form>
<form id="f3" method="get" action="#signed"><div class="wb-sign" data-wb-sign><canvas width="600" height="200"></canvas><input type="hidden" name="signature" value=""><button type="button" data-wb-sign-clear>Clear</button></div><button type="submit">Save</button></form>
<script src="file://${P}/assets/wb-workspace.js"></script></body></html>`);
const url = 'file://' + path.join(dir, 'page.html');
let fails = 0, n = 0;
const eq = (name, got, want) => { n++; if (JSON.stringify(got) !== JSON.stringify(want)) { fails++; console.log(`FAIL  ${name}\n      got:  ${JSON.stringify(got)}\n      want: ${JSON.stringify(want)}`); } };
(async () => {
  const b = await chromium.launch(); const p = await b.newPage();
  await p.goto(url);
  await p.click('#f1 button');
  eq('the sheet asks in the page', await p.isVisible('dialog.wb-sheet'), true);
  eq('with the question', await p.textContent('.wb-sheet-q'), 'Issue this note?');
  eq('the button is named for the action', await p.textContent('.wb-sheet-go'), 'Issue the note');
  await p.click('dialog button[value=no]'); await p.waitForTimeout(100);
  eq('Cancel sends nothing', await p.evaluate(() => location.hash), '');
  await p.click('#f1 button'); await p.click('.wb-sheet-go'); await p.waitForTimeout(200);
  eq('Go sends the form', await p.evaluate(() => location.hash), '#sent');
  await p.goto(url);
  await p.click('#f2 button');
  eq('a danger says it cannot be undone', await p.isVisible('.wb-sheet-danger'), true);
  await p.click('.wb-sheet-go'); await p.waitForTimeout(100);
  eq('an empty reason is refused', [await p.isVisible('dialog.wb-sheet'), await p.evaluate(() => location.hash)], [true, '']);
  await p.fill('.wb-sheet-why textarea', 'Duplicate customer'); await p.click('.wb-sheet-go'); await p.waitForTimeout(200);
  eq('the reason goes with the form', await p.evaluate(() => location.search), '?wb_reason=Duplicate+customer');
  await p.goto(url);
  const bb = await (await p.$('canvas')).boundingBox();
  await p.mouse.move(bb.x + 20, bb.y + 60); await p.mouse.down(); await p.mouse.move(bb.x + 200, bb.y + 120, { steps: 8 }); await p.mouse.up();
  eq('a signature becomes a PNG', (await p.inputValue('input[name=signature]')).slice(0, 22), 'data:image/png;base64,');
  await p.click('[data-wb-sign-clear]');
  eq('Clear empties it', await p.inputValue('input[name=signature]'), '');
  await p.keyboard.press('/');
  eq('/ goes to the search box', await p.evaluate(() => document.activeElement.name), 'q');
  await p.keyboard.type('n');
  eq('typing in a field is never a key', await p.evaluate(() => location.hash), '');
  await p.evaluate(() => document.activeElement.blur()); await p.keyboard.press('n'); await p.waitForTimeout(100);
  eq('n opens the main action', await p.evaluate(() => location.hash), '#made-new');
  await b.close();
  fs.rmSync(dir, { recursive: true, force: true });
  console.log(`${n} script behaviours checked, ${fails} problem(s)`);
  process.exit(fails ? 1 : 0);
})();
