// 상세 다크 · 데스크톱 서랍 상태를 재현: 스크롤해 서랍을 띄운 뒤 뷰포트 캡처 + computed 색
import { createRequire } from 'node:module'; import fs from 'node:fs'; import path from 'node:path';
const require = createRequire(import.meta.url); const { chromium } = require('playwright');
const S='https://duck-hoo.com', UA='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36';
const ROOT='/home/user/new-website-build'; const OUT=process.env.OUT||'../audit-s3-prod';
const url=process.argv[2]||'/product/%EB%85%B8%EB%B3%B4-%EB%A6%AC%ED%80%B4%EB%93%9C-10-1-%EB%AC%B6%EC%9D%8C-%EC%9D%B4%EB%B2%A4%ED%8A%B8-11%EB%B3%91/';
const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium',args:['--no-sandbox']});
for (const theme of ['dark','light']) {
const ctx=await b.newContext({viewport:{width:1150,height:900},userAgent:UA}); const page=await ctx.newPage();
if(theme==='dark') await page.addInitScript(()=>{ try{ localStorage.setItem('dhr-theme','dark'); }catch(e){} });
await page.route('**/*', async route=>{ const u=route.request().url(); if(!/^https?:/.test(u)) return route.abort();
  const lm=process.env.LOCAL==='1' && u.match(/\/plugins\/new-website-build\/assets\/([a-z-]+\.(?:css|js))/); if(lm){ return route.fulfill({status:200,headers:{'content-type':lm[1].endsWith('.css')?'text/css':'application/javascript'},body:fs.readFileSync(path.join(ROOT,'assets',lm[1]))}); }
  try{ const r=await fetch(u,{headers:{'user-agent':UA},redirect:'manual'}); const h={}; r.headers.forEach((v,k)=>{ if(!/^(content-encoding|transfer-encoding|content-length|set-cookie)$/i.test(k)) h[k]=v; }); route.fulfill({status:r.status,headers:h,body:Buffer.from(await r.arrayBuffer())}); }catch(e){ route.abort(); } });
const r=await page.goto(S+url+'?nocache='+Date.now(),{waitUntil:'load',timeout:90000}); console.log(theme,'status',r.status());
await page.evaluate(()=>{ document.querySelectorAll('#pop-dim,#pop6,#dh-agegate2').forEach(e=>e.remove()); document.documentElement.classList.remove('dh-ag2-lock'); document.body.classList.remove('dh-ag2-lock'); });
await page.waitForTimeout(2500);
await page.evaluate(()=>window.scrollTo(0,1600)); await page.waitForTimeout(1200);
await page.screenshot({path:`${OUT}/dock-${theme}.png`});
const m=await page.evaluate(()=>{ const cs=e=>e?getComputedStyle(e):null; const q=s=>document.querySelector(s);
  const card=q('.dhp-card--form'); const main=q('main.dhp-main')||q('main');
  const pick=s=>{ const e=q(s); if(!e) return null; const c=cs(e); return {color:c.color,bg:c.backgroundColor,op:c.opacity}; };
  return { cardCls:card&&card.className, bodyCls:document.body.className.slice(0,120), html:document.documentElement.getAttribute('data-theme'),
    bodyBg:cs(document.body).backgroundColor, mainBg:main&&cs(main).backgroundColor, mainOpacity:main&&cs(main).opacity, mainFilter:main&&cs(main).filter,
    dim:pick('[data-sheet-dim]'), dockwrap:pick('[data-dockwrap]'),
    dhxTitle:pick('.dhx-card__title'), dhxPick:pick('.dhx-bundle__pick'), dhxSumLabel:pick('.dhx-sum__label, .dhx-sum .label, .dhx-sum'), dhxSumBar:pick('.dhx-sum__bar i, .dhx-sum__bar > *, .dhx-sum__fill'),
    btn:pick('.single_add_to_cart_button'), direct:pick('.wd-direct-checkout-btn'),
    dhxVars:['--dhx-ink','--dhx-ink-2','--dhx-ink-3','--dhx-bg','--dhx-card','--dhx-accent','--dhx-line'].map(v=>[v,(q('.dhx')?getComputedStyle(q('.dhx')).getPropertyValue(v):'')]),
    h1:pick('h1'), desc:pick('.dhp-about, .dhp-desc, #tab-description') }; });
console.log(JSON.stringify(m,null,1)); await ctx.close(); }
await b.close();
