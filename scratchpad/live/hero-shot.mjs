// 홈 히어로 슬라이드 — 첫 장 · 점 눌러 둘째 장, 390/1280. LOCAL=1 이면 작업본.
import { createRequire } from 'node:module'; import fs from 'node:fs'; import path from 'node:path';
const require = createRequire(path.join(process.cwd(),'scratchpad/live/node_modules','x.js')); const { chromium } = require('playwright');
const UA='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36';
const OUT=process.env.OUT||'scratchpad/hero'; fs.mkdirSync(OUT,{recursive:true}); const S='https://duck-hoo.com'; const LOCAL=process.env.LOCAL==='1';
const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] });
for (const [w,h,tag] of [[390,844,'m'],[1280,900,'d']]) {
  const ctx = await b.newContext({ viewport:{width:w,height:h}, userAgent: UA, isMobile: w<880, deviceScaleFactor:1 });
  const page = await ctx.newPage(); const errs=[]; page.on('pageerror', e=>errs.push(e.message));
  await page.route('**/*', async (route) => { const req=route.request(); const url=req.url(); if(!/^https?:/.test(url)) return route.abort();
    const lm=LOCAL && url.match(/\/plugins\/new-website-build\/assets\/([a-z-]+\.(?:css|js))/); if(lm){ try { return route.fulfill({ status:200, headers:{'content-type': lm[1].endsWith('.css')?'text/css':'application/javascript'}, body: fs.readFileSync(path.join(process.cwd(),'assets',lm[1])) }); } catch(e){} }
    try { const r = await fetch(url, { method: req.method(), headers: { 'user-agent': UA, accept: '*/*' }, body: req.postData() ?? undefined, redirect: 'manual' });
      const headers = {}; r.headers.forEach((v,kk)=>{ if(!/^(content-encoding|transfer-encoding|content-length|set-cookie)$/i.test(kk)) headers[kk]=v; });
      route.fulfill({ status: r.status, headers, body: Buffer.from(await r.arrayBuffer()) }); } catch (e) { route.abort(); } });
  await page.goto(S+'/?nocache='+Date.now(), { waitUntil:'load', timeout:90000 });
  await page.evaluate(()=>{ document.querySelectorAll('#pop-dim,#pop6,#dh-agegate2').forEach(e=>e.remove()); document.body.style.overflow=''; });
  await page.waitForTimeout(2500);
  const m0 = await page.evaluate(()=>{ const r=e=>{const b=e.getBoundingClientRect();return [Math.round(b.y),Math.round(b.height)];}; const sl=[...document.querySelectorAll('.hslide')]; return { n: sl.length, on: sl.map(e=>e.classList.contains('on')), vis: sl.map(e=>getComputedStyle(e).visibility), hero: r(document.querySelector('.hhero')), dots: document.querySelectorAll('.hdot').length, titles: sl.map(e=>e.querySelector('.hero__t').textContent), over:(window.scrollTo(600,0),window.scrollX) }; });
  await page.screenshot({ path:`${OUT}/hero1-${tag}.png`, clip:{x:0,y:0,width:w,height:Math.min(h, m0.hero[0]+m0.hero[1]+40)} });
  await page.click('.hdot:nth-child(3)'); await page.waitForTimeout(700);
  const m1 = await page.evaluate(()=>{ const sl=[...document.querySelectorAll('.hslide')]; return { on: sl.map(e=>e.classList.contains('on')), vis: sl.map(e=>getComputedStyle(e).visibility), op: sl.map(e=>getComputedStyle(e).opacity) }; });
  await page.screenshot({ path:`${OUT}/hero3-${tag}.png`, clip:{x:0,y:0,width:w,height:Math.min(h, m0.hero[0]+m0.hero[1]+40)} });
  console.log(tag, JSON.stringify(m0), JSON.stringify(m1), 'errs', JSON.stringify(errs.filter(e=>!/CERT/.test(e)).map(e=>e.slice(0,160))));
  await ctx.close();
}
await b.close();
