// 노보 「도매가 수준 · 현재 재고 한정」 — 상품 상세 · 노보 분류 배너, 390 라이트.
import { createRequire } from 'node:module'; import fs from 'node:fs'; import path from 'node:path';
const require = createRequire(path.join(process.cwd(),'scratchpad/live/node_modules','x.js')); const { chromium } = require('playwright');
const UA='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36';
const OUT=process.env.OUT||'scratchpad/deal'; fs.mkdirSync(OUT,{recursive:true}); const S='https://duck-hoo.com';
const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] });
const pages = [['pd','/product/%eb%85%b8%eb%b3%b4-%eb%b8%94%eb%9e%99-%ed%83%80%eb%b0%95%eb%a9%98%ec%86%94-9-8mg-30ml/','.dhp-novo'],['cat','/product-category/novo-liquid/','.nvs']];
for (const [tag,u,sel] of pages) {
  const ctx = await b.newContext({ viewport:{width:390,height:844}, userAgent: UA, isMobile: true, deviceScaleFactor:2 });
  const page = await ctx.newPage();
  await page.route('**/*', async (route) => { const req=route.request(); const url=req.url(); if(!/^https?:/.test(url)) return route.abort();
    try { const r = await fetch(url, { method: req.method(), headers: { 'user-agent': UA, accept: '*/*' }, body: req.postData() ?? undefined, redirect: 'manual' });
      const headers = {}; r.headers.forEach((v,kk)=>{ if(!/^(content-encoding|transfer-encoding|content-length|set-cookie)$/i.test(kk)) headers[kk]=v; });
      route.fulfill({ status: r.status, headers, body: Buffer.from(await r.arrayBuffer()) }); } catch (e) { route.abort(); } });
  await page.goto(S+u+'?nocache='+Date.now(), { waitUntil:'load', timeout:90000 });
  await page.evaluate(()=>{ document.querySelectorAll('#pop-dim,#pop6,#dh-agegate2').forEach(e=>e.remove()); document.documentElement.classList.remove('dh-ag2-lock'); document.body.classList.remove('dh-ag2-lock'); document.body.style.overflow=''; });
  await page.waitForTimeout(2000);
  const el = await page.$(sel); if (!el) { console.log(tag,'없음'); continue; }
  await el.scrollIntoViewIfNeeded(); await page.waitForTimeout(600);
  const r = await el.boundingBox();
  await page.screenshot({ path:`${OUT}/${tag}.png`, clip:{x:0,y:Math.max(0,r.y-140),width:390,height:Math.min(844,r.height+280)} });
  console.log(tag, JSON.stringify(r), await page.evaluate(()=>{ window.scrollTo(600, window.scrollY); return window.scrollX; }));
  await ctx.close();
}
await b.close();
