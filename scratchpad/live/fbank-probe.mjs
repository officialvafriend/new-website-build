// 푸터 계좌 상자: 홈 · 테마 화면 × 라이트/다크 — 은행 · 예금주 줄 computed 색 + 대비 + 캡처
import { createRequire } from 'node:module'; import fs from 'node:fs'; import path from 'node:path';
const require = createRequire(import.meta.url); const { chromium } = require('playwright');
const S='https://duck-hoo.com', UA='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36';
const ROOT='/home/user/new-website-build'; const OUT=process.env.OUT||'../audit-s3-local';
const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium',args:['--no-sandbox']});
const lum=c=>{ const [r,g,bb]=c.match(/\d+(\.\d+)?/g).slice(0,3).map(Number).map(v=>{v/=255;return v<=.03928?v/12.92:Math.pow((v+.055)/1.055,2.4)}); return .2126*r+.7152*g+.0722*bb; };
for (const theme of ['light','dark']) for (const [k,pth] of [['home','/'],['shop','/shop/']]) {
  const ctx=await b.newContext({viewport:{width:1100,height:800},userAgent:UA}); const page=await ctx.newPage();
  if(theme==='dark') await page.addInitScript(()=>{ try{ localStorage.setItem('dhr-theme','dark'); }catch(e){} });
  await page.route('**/*', async route=>{ const u=route.request().url(); if(!/^https?:/.test(u)) return route.abort();
    const lm=process.env.LOCAL==='1' && u.match(/\/plugins\/new-website-build\/assets\/([a-z-]+\.(?:css|js))/); if(lm){ return route.fulfill({status:200,headers:{'content-type':lm[1].endsWith('.css')?'text/css':'application/javascript'},body:fs.readFileSync(path.join(ROOT,'assets',lm[1]))}); }
    try{ const r=await fetch(u,{headers:{'user-agent':UA},redirect:'manual'}); const hd={}; r.headers.forEach((v,kk)=>{ if(!/^(content-encoding|transfer-encoding|content-length|set-cookie)$/i.test(kk)) hd[kk]=v; }); route.fulfill({status:r.status,headers:hd,body:Buffer.from(await r.arrayBuffer())}); }catch(e){ route.abort(); } });
  await page.goto(S+pth+'?nocache='+Date.now(),{waitUntil:'load',timeout:90000});
  await page.evaluate(()=>{ document.querySelectorAll('#pop-dim,#pop6,#dh-agegate2').forEach(e=>e.remove()); });
  const el=await page.$('.fbank'); if(!el){ console.log(theme,k,'no fbank'); await ctx.close(); continue; }
  await el.scrollIntoViewIfNeeded(); await page.waitForTimeout(800);
  const m=await page.evaluate(()=>{ const m=document.querySelector('.fbank__meta'), f=document.querySelector('.fbank'); const cs=getComputedStyle(m); return {color:cs.color,opacity:cs.opacity,bg:getComputedStyle(f).backgroundColor}; });
  // 불투명도 반영한 대비
  const toRGB=c=>c.match(/\d+(\.\d+)?/g).slice(0,3).map(Number); const fg=toRGB(m.color), bg=toRGB(m.bg), a=+m.opacity; const mix=fg.map((v,i)=>v*a+bg[i]*(1-a)); const l1=lum(`rgb(${mix.join(',')})`), l2=lum(m.bg); const ratio=((Math.max(l1,l2)+.05)/(Math.min(l1,l2)+.05)).toFixed(2);
  console.log(theme,k,JSON.stringify(m),'contrast',ratio);
  await el.screenshot({path:`${OUT}/fbank-${k}-${theme}.png`}); await ctx.close(); }
await b.close();
