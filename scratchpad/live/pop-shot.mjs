// 홈 팝업(#pop6) 모양 확인 — 390/1280 × 라이트/다크, LOCAL=1 이면 작업본 CSS/JS. 팝업은 지우지 않는다.
import { createRequire } from 'node:module'; import fs from 'node:fs'; import path from 'node:path';
const require = createRequire(path.join(process.cwd(),'scratchpad/live/node_modules','x.js')); const { chromium } = require('playwright');
const UA='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36';
const OUT=process.env.OUT||'scratchpad/pop'; fs.mkdirSync(OUT,{recursive:true}); const S='https://duck-hoo.com'; const LOCAL=process.env.LOCAL==='1';
const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] });
for (const [w,h,tag] of (process.env.VPS||'390x844xm,1280x900xd').split(',').map(v=>{const a=v.split('x');return [+a[0],+a[1],a[2]];})) for (const theme of (process.env.THEMES||'light,dark').split(',')) {
  const ctx = await b.newContext({ viewport:{width:w,height:h}, userAgent: UA, isMobile: w<880, deviceScaleFactor:1 });
  const page = await ctx.newPage();
  if (theme==='dark') await page.addInitScript(()=>{ try{ localStorage.setItem('dhr-theme','dark'); }catch(e){} });
  await page.addInitScript(()=>{ try{ localStorage.removeItem('pop6-hide-until'); }catch(e){} });
  await page.route('**/*', async (route) => { const req=route.request(); const url=req.url(); if(!/^https?:/.test(url)) return route.abort();
    const lm=LOCAL && url.match(/\/plugins\/new-website-build\/assets\/([a-z-]+\.(?:css|js))/); if(lm){ try { return route.fulfill({ status:200, headers:{'content-type': lm[1].endsWith('.css')?'text/css':'application/javascript'}, body: fs.readFileSync(path.join(process.cwd(),'assets',lm[1])) }); } catch(e){} }
    try { const r = await fetch(url, { method: req.method(), headers: { 'user-agent': UA, accept: '*/*' }, body: req.postData() ?? undefined, redirect: 'manual' });
      const headers = {}; r.headers.forEach((v,kk)=>{ if(!/^(content-encoding|transfer-encoding|content-length|set-cookie)$/i.test(kk)) headers[kk]=v; });
      route.fulfill({ status: r.status, headers, body: Buffer.from(await r.arrayBuffer()) }); } catch (e) { route.abort(); } });
  await page.goto(S+'/?nocache='+Date.now(), { waitUntil:'load', timeout:90000 });
  await page.evaluate(()=>{ document.querySelectorAll('#dh-agegate2').forEach(e=>e.remove()); });
  await page.waitForTimeout(2500);
  const m = await page.evaluate(()=>{ const q=s=>document.querySelector(s); const cs=e=>e?getComputedStyle(e):null; const r=e=>{ if(!e) return null; const b=e.getBoundingClientRect(); return [Math.round(b.x),Math.round(b.y),Math.round(b.width),Math.round(b.height)]; };
    const pop=q('#pop6'), hero=q('#pop6 .hero'), img=q('#hero-img'), btns=[...document.querySelectorAll('#pop6 .bbtn')];
    return { shown: pop&&pop.classList.contains('show'), single: pop&&pop.classList.contains('dhr-single'), pop:r(pop), panel:r(q('#pop6 .panel')), hero:r(hero), heroBg: cs(hero)?.backgroundColor, img:r(img), imgNat: img?[img.naturalWidth,img.naturalHeight]:null, imgFit: cs(img)?.objectFit, btns: btns.map(b=>[b.textContent, b.classList.contains('active'), r(b)]), bar:r(q('#pop6 .bar')), vh: innerHeight };
  });
  console.log(tag, theme, JSON.stringify(m));
  await page.screenshot({ path:`${OUT}/pop-${theme}-${tag}.png` });
  await ctx.close();
}
await b.close();
