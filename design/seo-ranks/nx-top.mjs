import { chromium } from 'playwright';
const qs = JSON.parse(process.argv[2]); const out = process.argv[3];
const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] });
const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, locale: 'ko-KR', userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1' });
await ctx.route('**/*', async r => { const u = r.request().url(); if (!/^https?:/.test(u) || /\.(mp4|woff2?)(\?|$)/.test(u)) return r.abort();
  try { const f = await fetch(u, { method: r.request().method(), headers: { ...r.request().headers() }, body: r.request().postData() || undefined, redirect: 'manual' }); const body = Buffer.from(await f.arrayBuffer()); const h = {}; f.headers.forEach((v, k) => { if (!/content-encoding|content-length|transfer-encoding/.test(k)) h[k] = v; }); await r.fulfill({ status: f.status, headers: h, body }); } catch { r.abort(); } });
const p = await ctx.newPage();
for (const [i, q] of qs.entries()) {
  try { await p.goto('https://m.search.naver.com/search.naver?query=' + encodeURIComponent(q), { waitUntil: 'domcontentloaded', timeout: 30000 }); } catch (e) { console.log('ERR', q); continue; }
  await p.waitForTimeout(7000);
  const sec = await p.evaluate(() => [...document.querySelectorAll('#ct > section, #ct > div > section, .api_subject_bx, section.sc_new')].map(s => { const r = s.getBoundingClientRect(); const t = (s.querySelector('h2, .api_title, .fds-comps-header-headline')?.innerText || s.innerText).trim().split('\n')[0].slice(0, 24); return { t, y: Math.round(r.top + scrollY), h: Math.round(r.height) }; }).filter(s => s.h > 40).sort((a, b) => a.y - b.y).slice(0, 10));
  console.log(q, '|', sec.map(s => `${s.t}@${s.y}`).join(' / '));
  await p.screenshot({ path: `${out}-${i}.png`, fullPage: false });
  await p.waitForTimeout(2000);
}
await b.close();
