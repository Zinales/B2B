/**
 * Accessibility check on rendered pages, in a real browser (Playwright + Chromium).
 *
 *   php tools/a11y-pages.php /tmp/wb-a11y && node tools/a11y-check.js /tmp/wb-a11y
 *
 * For every page: every element with its own text is measured as the browser paints it (computed
 * colour against the first painted background behind it) and must clear WCAG AA (4.5:1, or 3:1 for
 * large text). Also: every button and link has an accessible name; form controls have a label;
 * interactive targets are at least 24×24 px (WCAG 2.5.8); nothing is wider than the viewport
 * (no sideways scroll) at 375 px and 1280 px. Exit 1 on any failure. Set NODE_PATH to the global
 * node_modules if playwright is installed globally.
 */
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const dir = process.argv[2];
if (!dir) { console.error('usage: node tools/a11y-check.js <dir of .html>'); process.exit(2); }

const MEASURE = () => {
  const lum = (r, g, b) => { const f = v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }; return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b); };
  const parse = s => { const m = s && s.match(/rgba?\(([\d.]+),\s*([\d.]+),\s*([\d.]+)(?:,\s*([\d.]+))?\)/); return m ? [+m[1], +m[2], +m[3], m[4] === undefined ? 1 : +m[4]] : null; };
  const blend = (fg, bg) => { const a = fg[3]; return [fg[0] * a + bg[0] * (1 - a), fg[1] * a + bg[1] * (1 - a), fg[2] * a + bg[2] * (1 - a), 1]; };
  const bgOf = el => {
    let stack = []; let e = el;
    while (e && e !== document.documentElement) { const c = parse(getComputedStyle(e).backgroundColor); if (c && c[3] > 0) { stack.push(c); if (c[3] >= 1) break; } e = e.parentElement; }
    let bg = parse(getComputedStyle(document.body).backgroundColor); if (!bg || bg[3] < 1) bg = [255, 255, 255, 1];
    for (let i = stack.length - 1; i >= 0; i--) bg = blend(stack[i], bg);
    return bg;
  };
  const ratio = (a, b) => { const la = lum(a[0], a[1], a[2]), lb = lum(b[0], b[1], b[2]); return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05); };
  const visible = el => { const r = el.getBoundingClientRect(); const cs = getComputedStyle(el); return r.width > 0 && r.height > 0 && cs.visibility !== 'hidden' && cs.display !== 'none' && cs.opacity !== '0'; };
  const desc = el => { const t = (el.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 40); return `<${el.tagName.toLowerCase()}${el.className ? ' class="' + el.className + '"' : ''}> "${t}"`; };
  const out = { contrast: [], names: [], labels: [], targets: [], overflow: false, counted: 0 };
  out.overflow = document.documentElement.scrollWidth > window.innerWidth + 1;
  for (const el of document.body.querySelectorAll('*')) {
    if (!visible(el) || el.closest('[aria-hidden="true"]')) continue;   // decorative text (ghost numerals) is not read
    const own = Array.from(el.childNodes).some(n => n.nodeType === 3 && n.textContent.trim());
    if (own) {
      const cs = getComputedStyle(el); const fg = parse(cs.color); if (!fg) continue;
      const bg = bgOf(el); const f = fg[3] < 1 ? blend(fg, bg) : fg;
      const size = parseFloat(cs.fontSize); const bold = parseInt(cs.fontWeight, 10) >= 700;
      const large = size >= 24 || (size >= 18.66 && bold);
      const r = ratio(f, bg); out.counted++;
      if (r < (large ? 3 : 4.5)) out.contrast.push({ el: desc(el), ratio: +r.toFixed(2), fg: cs.color, bg: `rgb(${bg.slice(0, 3).map(Math.round).join(',')})` });
    }
    if (el.matches('a,button,[role=button],[role=menuitem]')) {
      const name = (el.getAttribute('aria-label') || el.textContent || el.getAttribute('title') || '').trim();
      if (!name) out.names.push(desc(el));
      const r = el.getBoundingClientRect();
      if ((r.width < 24 || r.height < 24) && !el.closest('p, li, td, .wb-next')) out.targets.push({ el: desc(el), w: Math.round(r.width), h: Math.round(r.height) });
    }
    if (el.matches('input:not([type=hidden]),select,textarea')) {
      const id = el.id; const lab = (id && document.querySelector(`label[for="${CSS.escape(id)}"]`)) || el.closest('label') || el.getAttribute('aria-label') || el.getAttribute('aria-labelledby');
      if (!lab) out.labels.push(desc(el));
    }
  }
  return out;
};

(async () => {
  const browser = await chromium.launch();
  let failures = 0, checked = 0;
  const files = fs.readdirSync(dir).filter(f => f.endsWith('.html')).sort();
  for (const width of [1280, 375]) {
    const ctx = await browser.newContext({ viewport: { width, height: 900 } });
    const page = await ctx.newPage();
    for (const f of files) {
      await page.goto('file://' + path.resolve(dir, f), { waitUntil: 'load' });
      const r = await page.evaluate(MEASURE);
      checked += r.counted;
      const problems = [];
      for (const c of r.contrast) problems.push(`contrast ${c.ratio}:1  ${c.el}  text ${c.fg} on ${c.bg}`);
      for (const n of r.names) problems.push(`no accessible name  ${n}`);
      for (const l of r.labels) problems.push(`no label  ${l}`);
      for (const t of r.targets) problems.push(`target ${t.w}×${t.h}px (min 24×24)  ${t.el}`);
      if (r.overflow) problems.push('page scrolls sideways');
      const uniq = [...new Set(problems)];
      failures += uniq.length;
      console.log(`${uniq.length ? 'FAIL' : 'ok  '} ${f} @${width}px` + (uniq.length ? '\n   ' + uniq.join('\n   ') : ''));
    }
    await ctx.close();
  }
  await browser.close();
  console.log(`\n${checked} text elements measured, ${failures} problem(s)`);
  process.exit(failures ? 1 : 0);
})();
