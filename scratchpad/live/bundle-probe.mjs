// 묶음 맛 칸을 빠르게 눌러도 테마가 따라오는지 — 세트 고르기 → 한 맛 + 10번 연속 → json · 버튼 상태
import { createRequire } from 'node:module'; import path from 'node:path';
const require = createRequire(path.join(process.cwd(),'scratchpad/live/node_modules','x.js')); const { chromium } = require('playwright');
const UA='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36';
const S='https://duck-hoo.com'; const url=process.argv[2]||'/product/%EB%94%94%EC%98%A4%EB%A6%AC%ED%80%B4%EB%93%9C-55-%EB%AC%B6%EC%9D%8C-%EC%9D%B4%EB%B2%A4%ED%8A%B8/';
const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] });
const ctx = await b.newContext({ viewport:{width:390,height:844}, userAgent: UA, isMobile:true });
const page = await ctx.newPage();
await page.route('**/*', async (route) => { const req=route.request(); const u=req.url(); if(!/^https?:/.test(u)) return route.abort();
  if(/wc\/store\/v1\/cart\/add-item|\?wc-ajax=add_to_cart|add-to-cart=/.test(u) && req.method()==='POST') return route.abort(); // 절대 담지 않는다
  try { const r = await fetch(u, { method: req.method(), headers: { 'user-agent': UA, accept: '*/*' }, body: req.postData() ?? undefined, redirect: 'manual' });
    const headers = {}; r.headers.forEach((v,k)=>{ if(!/^(content-encoding|transfer-encoding|content-length|set-cookie)$/i.test(k)) headers[k]=v; });
    route.fulfill({ status: r.status, headers, body: Buffer.from(await r.arrayBuffer()) }); } catch (e) { route.abort(); } });
await page.goto(S+url, { waitUntil:'load', timeout:90000 }); await page.waitForTimeout(2500);
await page.evaluate(()=>{ document.querySelectorAll('#dh-agegate2,#pop-dim,#pop6').forEach(e=>e.remove()); document.documentElement.classList.remove('dh-ag2-lock'); document.body.classList.remove('dh-ag2-lock'); });
const state = () => page.evaluate(()=>{ const q=s=>document.querySelector(s); const btn=q('.single_add_to_cart_button'); const chips=[...document.querySelectorAll('.dhx-chip')].map(c=>[c.querySelector('.dhx-chip__pick').textContent, c.querySelector('.dhx-qty__n').textContent]);
  return { btn: btn && [btn.textContent.trim(), btn.disabled], json: (q('input[name=wd_option_builder_json]')||{}).value?.slice(0,400), total: q('.dhx-sum__total, .wd-option-builder-total')?.textContent, chips, locked: !!q('.dhx-card.is-locked'), sel2: q('#select_copy_1')?.value }; });
console.log('0', JSON.stringify(await state()));
await page.click('.dhx-bundle__pick'); await page.waitForTimeout(600); console.log('1 set', JSON.stringify(await state()));
const t0=Date.now();
await page.evaluate(()=>{ const chip=[...document.querySelectorAll('.dhx-chip')][0]; const plus=chip.querySelector('.dhx-qty button:last-child'); for(let i=0;i<6;i++) plus.click(); });
await page.waitForTimeout(1500); console.log('2 +6 fast', Date.now()-t0, JSON.stringify(await state()));
await page.evaluate(()=>{ const chip=[...document.querySelectorAll('.dhx-chip')][1]; const plus=chip.querySelector('.dhx-qty button:last-child'); for(let i=0;i<4;i++) plus.click(); });
await page.waitForTimeout(1500); console.log('3 +4 fast', JSON.stringify(await state()));
await page.evaluate(()=>{ const chip=[...document.querySelectorAll('.dhx-chip')][0]; const minus=chip.querySelector('.dhx-qty button:first-child'); for(let i=0;i<2;i++) minus.click(); });
await page.waitForTimeout(1500); console.log('4 -2', JSON.stringify(await state()));
await b.close();
