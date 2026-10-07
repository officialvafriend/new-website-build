import { createRequire } from 'node:module'; import path from 'node:path';
const require = createRequire(path.join(process.cwd(),'scratchpad/live/node_modules','x.js')); const { chromium } = require('playwright');
const UA='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36';
const S='https://duck-hoo.com'; const url=process.argv[2];
const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] });
for (const [w,h,tag] of [[390,844,'m'],[1280,900,'d']]) {
  const ctx = await b.newContext({ viewport:{width:w,height:h}, userAgent: UA, isMobile:w<880 }); const page = await ctx.newPage();
  await page.route('**/*', async (route) => { const req=route.request(); const u=req.url(); if(!/^https?:/.test(u)) return route.abort();
    if(/wc\/store\/v1\/cart\/add-item|wc-ajax=add_to_cart|add-to-cart=/.test(u) && req.method()==='POST') return route.abort();
    try { const r = await fetch(u, { method: req.method(), headers: { 'user-agent': UA, accept: '*/*' }, body: req.postData() ?? undefined, redirect: 'manual' });
      const headers = {}; r.headers.forEach((v,k)=>{ if(!/^(content-encoding|transfer-encoding|content-length|set-cookie)$/i.test(k)) headers[k]=v; });
      route.fulfill({ status: r.status, headers, body: Buffer.from(await r.arrayBuffer()) }); } catch (e) { route.abort(); } });
  await page.goto(S+url+'?nocache='+Date.now(), { waitUntil:'load', timeout:90000 }); await page.waitForTimeout(3000);
  await page.evaluate(()=>{ document.querySelectorAll('#dh-agegate2,#pop-dim,#pop6').forEach(e=>e.remove()); document.documentElement.classList.remove('dh-ag2-lock'); document.body.classList.remove('dh-ag2-lock'); });
  const st = () => page.evaluate(()=>{ const q=s=>document.querySelector(s); const btn=q('.single_add_to_cart_button'); return { dhx: document.querySelectorAll('.dhx-card').length, chipsCard: !!q('.dhx-chips'), picks:[...document.querySelectorAll('.dhx-bundle__pick')].map(p=>p.textContent.trim().slice(0,40)), locked: !!q('.dhx-card.is-locked'), btn: btn&&[btn.textContent.trim(), btn.disabled], pk: !!q('.dhpk'), chips:[...document.querySelectorAll('.dhx-chip')].slice(0,3).map(c=>[c.querySelector('.dhx-chip__pick').textContent.trim(), c.querySelector('.dhx-qty__n').textContent]), heads:[...document.querySelectorAll('.dhx-card__title')].map(t=>t.textContent.trim().slice(0,40)) }; });
  console.log(tag,'0',JSON.stringify(await st()));
  const pk = await page.$$('.dhx-bundle__pick'); if(pk.length){ await pk[0].click(); await page.waitForTimeout(800); console.log(tag,'1 pick',JSON.stringify(await st())); }
  await page.evaluate(()=>{ const c=document.querySelector('.dhx-chip'); if(c) c.querySelector('.dhx-qty button:last-child').click(); }); await page.waitForTimeout(800); console.log(tag,'2 +1',JSON.stringify(await st()));
  await ctx.close();
}
await b.close();
