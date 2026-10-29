#!/usr/bin/env node
// One-off audit: names the exact elements behind inspect.mjs findings (hairline, outlined-box,
// card-rows, small-text, side-scroll) for the static preview dump.
//   node .taste-skill/audit.mjs public/preview-etudiant.html
import { existsSync, statSync, createReadStream } from 'node:fs';
import { resolve, join, relative, extname, dirname, sep } from 'node:path';
import { createServer } from 'node:http';
import { Browser } from '/Users/mohamedyassine/.agents/skills/design-taste-frontend/scripts/lib/cdp.mjs';

const target = process.argv[2] || 'public/preview-etudiant.html';
const file = resolve(target);
if (!existsSync(file)) { console.error('No such file: ' + file); process.exit(2); }
const cwd = resolve('.');
const root = file.startsWith(cwd + sep) ? cwd : dirname(file);
const MIME = { '.html': 'text/html; charset=utf-8', '.css': 'text/css; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg', '.woff2': 'font/woff2', '.webmanifest': 'application/manifest+json' };
const server = createServer((req, res) => {
  try {
    let f = resolve(join(root, decodeURIComponent(new URL(req.url, 'http://x').pathname)));
    if (f !== root && !f.startsWith(root + sep)) { res.writeHead(403); return res.end(); }
    if (statSync(f).isDirectory()) f = join(f, 'index.html');
    res.writeHead(200, { 'content-type': MIME[extname(f).toLowerCase()] || 'application/octet-stream', 'content-length': statSync(f).size, 'cache-control': 'no-store' });
    createReadStream(f).pipe(res);
  } catch { res.writeHead(404); res.end('not found'); }
});
await new Promise((r) => server.listen(0, '127.0.0.1', r));
const url = `http://127.0.0.1:${server.address().port}/${relative(root, file).split(sep).map(encodeURIComponent).join('/')}`;

const audit = () => {
  const W = innerWidth, H = innerHeight, SY = scrollY;
  const label = (el) => (el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') + (typeof el.className === 'string' && el.className ? '.' + el.className.trim().split(/\s+/).slice(0, 4).join('.') : '')).slice(0, 110);
  const txt = (el) => (el.innerText || '').replace(/\s+/g, ' ').trim().slice(0, 46);
  const all = [...document.body.querySelectorAll('*')].slice(0, 9000);
  const big = [];
  for (const el of all) {
    const tag = el.tagName; if (tag === 'SCRIPT' || tag === 'STYLE' || tag === 'NOSCRIPT' || tag === 'TEMPLATE') continue;
    const r = el.getBoundingClientRect(); if (r.width < 1 || r.height < 1) continue;
    const cs = getComputedStyle(el); if (cs.display === 'none' || cs.visibility === 'hidden') continue;
    if (r.width * r.height >= 60 * 30) big.push({ el, r, cs, top: r.top + SY });
  }
  const formish = (el) => el.matches('input, textarea, select, table, thead, tbody, tr, td, th, hr') || !!el.closest('table, [data-ui]');
  const hairlines = [], outlined = [], cardRows = [], sideScrollers = [];
  for (const b of big) {
    const { cs, r, el } = b;
    const bt = parseFloat(cs.borderTopWidth) || 0, bb = parseFloat(cs.borderBottomWidth) || 0, bl = parseFloat(cs.borderLeftWidth) || 0, br = parseFloat(cs.borderRightWidth) || 0;
    const thin = (w, st) => w > 0 && w <= 1.5 && st !== 'none';
    const top = thin(bt, cs.borderTopStyle), bot = thin(bb, cs.borderBottomStyle), lef = thin(bl, cs.borderLeftStyle), rig = thin(br, cs.borderRightStyle);
    if (!formish(el)) {
      if (top && bot && lef && rig) { if (r.width >= 150 && r.height >= 80) outlined.push({ el: label(el), text: txt(el), w: Math.round(r.width), h: Math.round(r.height), border: cs.borderWidth + ' ' + cs.borderColor, top: Math.round(b.top) }); }
      else if ((r.width >= 240 && (top || bot)) || (r.height >= 240 && (lef || rig))) hairlines.push({ el: label(el), text: txt(el), w: Math.round(r.width), h: Math.round(r.height), which: [top && 'top', bot && 'bottom', lef && 'left', rig && 'right'].filter(Boolean).join('+'), color: [top && cs.borderTopColor, bot && cs.borderBottomColor].filter(Boolean).join(','), top: Math.round(b.top) });
    }
  }
  for (const el of all) {
    const r = el.getBoundingClientRect();
    const horiz = r.height > 0 && r.height <= 1.5 && r.width >= 160, vert = r.width > 0 && r.width <= 1.5 && r.height >= 160;
    if (!horiz && !vert) continue; if (formish(el) && (el.tagName !== 'HR' || el.closest('[data-ui]'))) continue;
    const cs = getComputedStyle(el); if (cs.display === 'none' || cs.visibility === 'hidden' || +cs.opacity < 0.03) continue;
    if ((cs.backgroundColor !== 'rgba(0, 0, 0, 0)' && cs.backgroundColor !== 'transparent') || parseFloat(cs.borderTopWidth) > 0 || parseFloat(cs.borderLeftWidth) > 0 || cs.backgroundImage !== 'none') hairlines.push({ el: label(el), rule: true, w: Math.round(r.width), h: Math.round(r.height), bg: cs.backgroundColor, top: Math.round(r.top + SY) });
  }
  const seen = new Set();
  for (const b of big) {
    const p = b.el.parentElement; if (!p || seen.has(p)) continue; seen.add(p);
    const kids = [...p.children].filter((k) => { const r = k.getBoundingClientRect(); return r.width >= 150 && r.width < W * 0.9 && r.height >= 90 && !k.matches('img, picture, video, canvas, svg, figure:not(:has(figcaption))') && (k.innerText || '').trim().length >= 12; });
    if (kids.length < 3) continue;
    const boxed = kids.filter((k) => { const cs = getComputedStyle(k); return cs.backgroundColor !== 'rgba(0, 0, 0, 0)' || parseFloat(cs.borderTopWidth) > 0 || cs.boxShadow !== 'none'; });
    if (boxed.length < 3) continue;
    const w0 = boxed[0].getBoundingClientRect().width;
    const same = boxed.filter((k) => Math.abs(k.getBoundingClientRect().width - w0) < 3);
    if (same.length < 3) continue;
    cardRows.push({ parent: label(p), count: same.length, firstChild: label(same[0]), firstText: txt(same[0]), top: Math.round(same[0].getBoundingClientRect().top + SY) });
  }
  for (const el of all) {
    if (el.closest('[data-ui], table, pre, code')) continue; const cs = getComputedStyle(el);
    if (/auto|scroll/.test(cs.overflowX) && el.scrollWidth > el.clientWidth + 24 && el.clientHeight > 40) sideScrollers.push({ el: label(el), text: txt(el), scrollW: el.scrollWidth, clientW: el.clientWidth, top: Math.round(el.getBoundingClientRect().top + SY) });
  }
  const small = [];
  for (const el of all) {
    let direct = ''; for (const n of el.childNodes) if (n.nodeType === 3) direct += n.nodeValue;
    direct = direct.replace(/\s+/g, ' ').trim(); if (!direct) continue;
    if (el.tagName === 'OPTION' || el.closest('svg, pre, code, kbd, samp, textarea, [data-ui]')) continue;
    const cs = getComputedStyle(el); if (cs.display === 'none' || cs.visibility === 'hidden') continue;
    const size = parseFloat(cs.fontSize);
    if (!size || size >= 13 || direct.length < 12) continue;
    small.push({ el: label(el), text: direct.slice(0, 60), size, top: Math.round(el.getBoundingClientRect().top + SY) });
  }
  const imgs = [...document.images].map((i) => ({ src: (i.currentSrc || i.src).slice(0, 90), w: Math.round(i.getBoundingClientRect().width), h: Math.round(i.getBoundingClientRect().height), nat: i.naturalWidth }));
  return { viewport: { W, H }, hairlines, outlined, cardRows, sideScrollers, small, imgs };
};

const browser = await Browser.launch();
try {
  for (const [w, h] of [[1440, 900], [768, 1024], [390, 844]]) {
    const page = await browser.newPage({ width: w, height: h, dsf: 1, mobile: w < 600 });
    await page.goto(url, { timeoutMs: 30000, settleMs: 600 });
    const a = await page.eval(audit);
    console.log(`\n════════ ${w}px ════════`);
    for (const k of ['hairlines', 'outlined', 'cardRows', 'sideScrollers', 'small']) {
      console.log(`── ${k}: ${a[k].length}`);
      for (const x of a[k]) console.log('   ' + JSON.stringify(x));
    }
    console.log('── imgs: ' + JSON.stringify(a.imgs));
    await page.close();
  }
} finally {
  await browser.close(); server.close();
}
