// 유리 언어 일괄 교체 확인 — 헤더 · 분류 칩 · 안내 띠 · 장바구니 서랍 (LOCAL=1 이면 작업본)
import { createRequire } from 'node:module'; import fs from 'node:fs'; import path from 'node:path';
const require = createRequire(import.meta.url); const { chromium } = require('playwright');
const S='https://duck-hoo.com', ROOT='/home/user/new-website-build';
const OUT=process.env.OUT||path.join(ROOT,'scratchpad/glass-sweep'); fs.mkdirSync(OUT,{recursive:true});
const URL='/product/%EB%85%B8%EB%B3%B4-%ED%83%80%EB%B0%95%EB%A9%98%EC%86%94-9-8mg-30ml/';
const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium',args:['--no-sandbox']});
for(const [width,dark,mobile,home] of [[390,true,true,false],[390,false,true,false],[1280,true,false,true]]){
  const UA = mobile ? 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1' : 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36';
  const ctx=await b.newContext({viewport:{width,height:mobile?844:900},userAgent:UA,deviceScaleFactor:mobile?2:1});
  await ctx.addInitScript((d)=>{ try{ if(d) localStorage.setItem('dhr-theme','dark'); else localStorage.removeItem('dhr-theme'); }catch(e){} }, dark);
  const page=await ctx.newPage();
  await page.route('**/*', async route=>{ const u=route.request().url(); if(!/^https?:/.test(u)) return route.abort();
    if(route.request().method()==='POST' && /add-to-cart|cart\/add-item/.test(u)) return route.abort();
    const lm=process.env.LOCAL==='1' && u.match(/\/plugins\/new-website-build\/assets\/([a-z-]+\.(?:css|js))/);
    if(lm){ return route.fulfill({status:200,headers:{'content-type':lm[1].endsWith('.css')?'text/css':'application/javascript'},body:fs.readFileSync(path.join(ROOT,'assets',lm[1]))}); }
    try{ const r=await fetch(u,{headers:{'user-agent':UA},redirect:'manual'}); const h={}; r.headers.forEach((v,k)=>{ if(!/^(content-encoding|transfer-encoding|content-length|set-cookie)$/i.test(k)) h[k]=v; }); route.fulfill({status:r.status,headers:h,body:Buffer.from(await r.arrayBuffer())}); }catch(e){ route.abort(); } });
  const tag=`${width}-${dark?'dark':'light'}`;
  await page.goto(S+(home?'/':URL)+'?nocache='+Date.now(),{waitUntil:'load',timeout:90000});
  await page.evaluate(()=>{ document.querySelectorAll('#pop-dim,#pop6,#dh-agegate2').forEach(e=>e.remove()); document.documentElement.classList.remove('dh-ag2-lock'); document.body.classList.remove('dh-ag2-lock'); });
  await page.waitForTimeout(2500);
  await page.evaluate(()=>window.scrollTo(0,380)); await page.waitForTimeout(600);
  await page.screenshot({path:`${OUT}/top-${tag}.png`});
  console.log(tag, await page.evaluate(()=>{ const q=s=>document.querySelector(s); const c=e=>e?getComputedStyle(e):null; const bn=c(q('.bnav a')), bb=c(q('.bnav')), dn=c(q('.dhn__row')), hs=c(q('.hs'));
    return {bnavBg:bb&&bb.backgroundColor, bnavBlur:bb&&(bb.backdropFilter||bb.webkitBackdropFilter), chipBg:bn&&bn.backgroundColor, chipShadow:bn&&bn.boxShadow.slice(0,60), noticeBg:dn&&dn.backgroundColor, searchBg:hs&&hs.backgroundColor, overflow:document.documentElement.scrollWidth}; }));
  await page.evaluate(()=>window.DHR.openCart(null)); await page.waitForTimeout(900);
  await page.screenshot({path:`${OUT}/drawer-${tag}.png`});
  console.log(tag+' drawer', await page.evaluate(()=>{ const p=getComputedStyle(document.querySelector('.dhc__panel')); const d=getComputedStyle(document.querySelector('.dhc__dim')); return {bg:p.backgroundColor, blur:p.backdropFilter||p.webkitBackdropFilter, radius:p.borderTopLeftRadius, dim:d.backgroundColor, dimBlur:d.backdropFilter||d.webkitBackdropFilter}; }));
  await ctx.close();
}
await b.close();
