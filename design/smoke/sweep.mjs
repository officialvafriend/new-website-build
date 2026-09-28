#!/usr/bin/env node
/**
 * 전체 훑기 — 「모르는 오류」를 찾는다. 비로그인 · 서버에 남기는 것 없음 (담은 것은 도로 뺀다).
 *
 *   NODE_USE_ENV_PROXY=1 NODE_EXTRA_CA_CERTS=/root/.ccr/ca-bundle.crt node design/smoke/sweep.mjs [--site URL] [--out DIR] [--no-browser]
 *
 *   A 사이트맵의 모든 주소: HTTP 상태 · 치명 오류 · PHP Warning/Notice/Deprecated 가 화면에 새는지
 *   B 상품 전부(Store API 목록): 상세 200 · 구매 폼 · 가격 · 담기(Store API add-item) 결과를 분류한다
 *       201 = 담김(도로 뺀다) · 400 옵션 = 옵션 필수 상품이라 정상 · 그 밖 = 의심
 *   C 대표 화면 8종을 폰 폭(390)으로 열어 JS 오류 · 가로 넘침 · 깨진 이미지를 센다
 *
 * 결과: 화면에 요약, --out 에 sweep-*.tsv
 */
import fs from 'fs';
const arg = (k, d) => { const i = process.argv.indexOf(k); return i > 0 ? process.argv[i + 1] : d; };
const SITE = arg('--site', 'https://duck-hoo.com').replace(/\/$/, '');
const OUT = arg('--out', '/tmp'); const NOBR = process.argv.includes('--no-browser');
const UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
const nc = () => (Math.random().toString(36).slice(2));
const jar = new Map();
const cookie = () => [...jar.entries()].map(([k, v]) => k + '=' + v).join('; ');
async function get(url, opt = {}) {
  const u = url + (url.includes('?') ? '&' : '?') + 'nocache=' + nc();
  const h = { 'user-agent': UA, ...(opt.headers || {}) }; if (jar.size) h.cookie = cookie();
  const r = await fetch(u, { ...opt, headers: h, redirect: opt.redirect || 'follow' });
  for (const c of (r.headers.getSetCookie ? r.headers.getSetCookie() : [])) { const [kv] = c.split(';'); const i = kv.indexOf('='); if (i > 0) jar.set(kv.slice(0, i).trim(), kv.slice(i + 1).trim()); }
  return r;
}
const leak = (t) => { const m = t.match(/<b>(Warning|Notice|Deprecated|Fatal error)<\/b>:\s*[^<]{0,120}|(Warning|Notice|Deprecated): [^<\n]{0,120} in \/[^\s<]+/); return m ? m[0].replace(/<[^>]+>/g, '').slice(0, 140) : ''; };
const fatal = (t) => /There has been a critical error|치명적인 오류가 발생|Fatal error/i.test(t);
const pool = async (items, n, fn) => { const out = []; let i = 0; await Promise.all(Array.from({ length: n }, async () => { while (i < items.length) { const k = i++; out[k] = await fn(items[k], k); } })); return out; };

// ── A 사이트맵
async function sitemapUrls() {
  const seen = new Set(); const urls = [];
  const add = (u) => { if (u.startsWith(SITE) && !seen.has(u)) { seen.add(u); urls.push(u); } };
  const idx = await (await get(SITE + '/sitemap.xml')).text();
  const subs = [...idx.matchAll(/<loc>([^<]+)<\/loc>/g)].map(m => m[1]);
  for (const s of subs) { if (!/\.xml$/.test(s)) { add(s); continue; } try { const x = await (await get(s)).text(); for (const m of x.matchAll(/<loc>([^<]+)<\/loc>/g)) add(m[1]); } catch (e) {} }
  try { const b = await (await get(SITE + '/brands.xml')).text(); for (const m of b.matchAll(/<loc>([^<]+)<\/loc>/g)) add(m[1]); } catch (e) {}
  return urls;
}
console.log('A. 사이트맵 주소 훑기 …');
const urls = await sitemapUrls();
const A = await pool(urls, 6, async (u) => { try { const r = await get(u); const t = await r.text(); return { u, status: r.status, fatal: fatal(t), leak: leak(t) }; } catch (e) { return { u, status: 0, fatal: false, leak: e.message }; } });
const Abad = A.filter(r => r.status !== 200 || r.fatal || r.leak);
console.log(`   ${urls.length}개 중 문제 ${Abad.length}개`);
fs.writeFileSync(OUT + '/sweep-pages.tsv', ['status\tfatal\tleak\turl', ...A.map(r => `${r.status}\t${r.fatal ? 'FATAL' : ''}\t${r.leak}\t${r.u}`)].join('\n'));

// ── B 상품 전부
console.log('B. 상품 전부 담기 시험 …');
const products = [];
for (let page = 1; page < 10; page++) { const r = await get(`${SITE}/wp-json/wc/store/v1/products?per_page=100&page=${page}`); if (r.status !== 200) break; const j = await r.json(); if (!j.length) break; products.push(...j.map(p => ({ id: p.id, name: p.name, url: p.permalink, stock: p.is_in_stock, purchasable: p.is_purchasable, price: p.prices && p.prices.price }))); if (j.length < 100) break; }
let nonce = ''; { const r = await get(SITE + '/wp-json/wc/store/v1/cart'); nonce = r.headers.get('nonce') || ''; }
// 상세는 6개씩 나란히 받고, 담기는 장바구니 논스 때문에 차례로
const B = await pool(products, 6, async (p) => {
  const row = { ...p, page: 0, form: false, priceShown: false, selects: 0, dhx: false, add: '', note: '' };
  try { const r = await get(p.url); const t = await r.text(); row.page = r.status; row.form = /<form[^>]+class="[^"]*\bcart\b/.test(t); row.priceShown = /class="pr"|woocommerce-Price-amount|dhp-price/.test(t); row.selects = (t.match(/<select[^>]+ppom-input/g) || []).length; row.dhx = /dh-option-ui|dhx-/.test(t); if (fatal(t)) row.note = 'FATAL'; const l = leak(t); if (l) row.note += ' ' + l; } catch (e) { row.note = e.message; }
  return row;
});
console.log(`   상세 ${B.length}개 받음 · 담기 시험 시작`);
for (const row of B) { const p = row;
  if (p.stock && p.purchasable) {
    try {
      const r = await get(SITE + '/wp-json/wc/store/v1/cart/add-item', { method: 'POST', headers: { 'content-type': 'application/json', nonce }, body: JSON.stringify({ id: p.id, quantity: 1 }) });
      nonce = r.headers.get('nonce') || nonce; const j = await r.json().catch(() => ({}));
      if (r.status === 201 || r.status === 200) { row.add = '담김'; const key = j.items && j.items.find(i => i.id === p.id) ? j.items.find(i => i.id === p.id).key : (j.items && j.items[0] && j.items[0].key);
        if (key) { const d = await get(SITE + '/wp-json/wc/store/v1/cart/remove-item', { method: 'POST', headers: { 'content-type': 'application/json', nonce }, body: JSON.stringify({ key }) }); nonce = d.headers.get('nonce') || nonce; } }
      else if (r.status === 400 && /옵션|option|선택/.test(JSON.stringify(j))) row.add = '옵션 필수(정상)';
      else row.add = `의심 ${r.status} ${(j.message || JSON.stringify(j)).slice(0, 80)}`;
    } catch (e) { row.add = '의심 ' + e.message; }
  } else row.add = p.stock ? '구매 불가 표시' : '품절';
}
// 혹시 남은 줄이 있으면 비운다
try { const r = await get(SITE + '/wp-json/wc/store/v1/cart'); const j = await r.json(); nonce = r.headers.get('nonce') || nonce; for (const it of (j.items || [])) { await get(SITE + '/wp-json/wc/store/v1/cart/remove-item', { method: 'POST', headers: { 'content-type': 'application/json', nonce }, body: JSON.stringify({ key: it.key }) }); } } catch (e) {}
const Bbad = B.filter(r => r.page !== 200 || !r.form || !r.priceShown || /의심|FATAL/.test(r.add + r.note) || r.note);
console.log(`   ${B.length}개 중 의심 ${Bbad.length}개 · 담김 ${B.filter(r => r.add === '담김').length} · 옵션 필수 ${B.filter(r => r.add.startsWith('옵션')).length} · 품절/구매불가 ${B.filter(r => /품절|불가/.test(r.add)).length}`);
fs.writeFileSync(OUT + '/sweep-products.tsv', ['id\tpage\tform\tprice\tselects\tdhx\tadd\tnote\tname\turl', ...B.map(r => [r.id, r.page, r.form, r.priceShown, r.selects, r.dhx, r.add, r.note, r.name, r.url].join('\t'))].join('\n'));

// ── C 브라우저
const C = [];
if (!NOBR) {
  console.log('C. 대표 화면 브라우저 점검 …');
  let pw = null; try { pw = await import('playwright'); } catch (e) { try { const { createRequire } = await import('module'); pw = createRequire((process.env.DHR_NODE_MODULES || '/tmp/claude-0/-home-user-new-website-build/eaa69852-6737-54fa-a860-4a2fc73b9c20/scratchpad/live/node_modules') + '/x.js')('playwright'); } catch (e2) {} }
  if (pw) {
    const single = B.find(r => r.add === '담김' && r.selects > 0 && !r.dhx) || B.find(r => r.add === '담김');
    const bundle = B.find(r => r.dhx) || B.find(r => r.add.startsWith('옵션'));
    const targets = [['홈', '/'], ['전체 상품', '/shop/'], ['단품 상세', single ? single.url.replace(SITE, '') : '/shop/'], ['묶음 상세', bundle ? bundle.url.replace(SITE, '') : '/shop/'], ['장바구니', '/cart/'], ['가입 1', '/register/'], ['가입 3', '/join-form/'], ['로그인', '/login/'], ['가격표', '/price/'], ['노보 분류', '/product-category/novo-liquid/']];
    const br = await pw.chromium.launch({ executablePath: process.env.PW_CHROMIUM || '/opt/pw-browsers/chromium', args: ['--no-sandbox'] });
    for (const [label, path] of targets) {
      const ctx = await br.newContext({ userAgent: UA, viewport: { width: 390, height: 844 } });
      await ctx.route('**/*', async r => { const req = r.request(); const u = req.url(); if (!/^https?:/.test(u)) return r.continue(); if (req.method() !== 'GET') return r.fulfill({ status: 200, headers: { 'content-type': 'application/json' }, body: '{}' });
        try { const res = await fetch(u, { headers: { ...req.headers(), 'accept-encoding': 'identity' }, redirect: 'follow' }); const body = Buffer.from(await res.arrayBuffer()); const h = {}; res.headers.forEach((v, k) => { if (!['content-encoding', 'transfer-encoding', 'content-length', 'set-cookie'].includes(k)) h[k] = v; }); await r.fulfill({ status: res.status, headers: h, body }); } catch (e) { await r.fulfill({ status: 204, body: '' }); } });
      const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message.split('\n')[0])); p.on('console', m => { if (m.type() === 'error') errs.push(m.text().slice(0, 120)); }); p.on('dialog', d => d.dismiss().catch(() => {}));
      try { await p.goto(SITE + path + '?nocache=' + nc(), { waitUntil: 'load', timeout: 120000 }); await p.waitForTimeout(2500);
        const m = await p.evaluate(() => { window.scrollTo(600, 0); const sx = window.scrollX; window.scrollTo(0, 0); const broken = [...document.images].filter(i => i.complete && i.naturalWidth === 0 && i.getAttribute('src')).length; return { scrollX: sx, broken, imgs: document.images.length }; });
        C.push({ label, path, errs: [...new Set(errs)], overflow: m.scrollX > 0, broken: m.broken, imgs: m.imgs });
      } catch (e) { C.push({ label, path, errs: [e.message.split('\n')[0]], overflow: false, broken: 0, imgs: 0 }); }
      await ctx.close();
    }
    await br.close();
  }
  fs.writeFileSync(OUT + '/sweep-browser.tsv', ['화면\t경로\t가로넘침\t깨진이미지\tJS오류', ...C.map(r => [r.label, r.path, r.overflow, r.broken + '/' + r.imgs, r.errs.join(' | ')].join('\t'))].join('\n'));
}

// ── 요약
console.log('\n══ A. 화면 문제');
for (const r of Abad.slice(0, 40)) console.log(`  ${r.status}${r.fatal ? ' FATAL' : ''} ${r.leak ? '[' + r.leak + ']' : ''} ${r.u.replace(SITE, '')}`);
console.log('\n══ B. 상품 의심');
for (const r of Bbad.slice(0, 60)) console.log(`  #${r.id} page=${r.page} form=${r.form} price=${r.priceShown} sel=${r.selects} dhx=${r.dhx} add=${r.add} ${r.note} · ${r.name.slice(0, 40)}`);
if (C.length) { console.log('\n══ C. 브라우저 (폰 390px)'); for (const r of C) console.log(`  ${r.overflow ? '넘침 ' : ''}${r.broken ? '깨진이미지 ' + r.broken + ' ' : ''}${r.errs.length ? 'JS오류 ' + r.errs.length + ' ' : ''}${r.label} ${r.path}` + (r.errs.length ? '\n     ' + r.errs.slice(0, 4).join('\n     ') : '')); }
console.log(`\n표: ${OUT}/sweep-pages.tsv · sweep-products.tsv · sweep-browser.tsv`);
