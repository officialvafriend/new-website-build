// 조바(단품) 라이트 — 수량 상자가 라이트에서도 멀쩡한지 (LOCAL 작업본 CSS 를 끼운다)
import { createRequire } from 'node:module'; import fs from 'node:fs'; import path from 'node:path';
const require = createRequire(import.meta.url); const { chromium } = require('playwright');
const S='https://duck-hoo.com', UA='Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1';
const ROOT='/home/user/new-website-build'; const OUT=process.env.OUT||path.join(ROOT,'scratchpad/dark-probe2-local'); fs.mkdirSync(OUT,{recursive:true});
const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium',args:['--no-sandbox']}); const ctx=await b.newContext({viewport:{width:390,height:844},userAgent:UA,deviceScaleFactor:2}); const page=await ctx.newPage();
await page.route('**/*', async route=>{ const u=route.request().url(); if(!/^https?:/.test(u)) return route.abort();
  const lm=process.env.LOCAL==='1' && u.match(/\/plugins\/new-website-build\/assets\/([a-z-]+\.(?:css|js))/); if(lm){ return route.fulfill({status:200,headers:{'content-type':lm[1].endsWith('.css')?'text/css':'application/javascript'},body:fs.readFileSync(path.join(ROOT,'assets',lm[1]))}); }
  try{ const r=await fetch(u,{headers:{'user-agent':UA},redirect:'manual'}); const h={}; r.headers.forEach((v,k)=>{ if(!/^(content-encoding|transfer-encoding|content-length|set-cookie)$/i.test(k)) h[k]=v; }); route.fulfill({status:r.status,headers:h,body:Buffer.from(await r.arrayBuffer())}); }catch(e){ route.abort(); } });
await page.goto(S+'/product/%ec%a1%b0%eb%b0%94-%ec%9e%85%ed%98%b8%ed%9d%a1-%ec%a0%84%ec%9e%90%eb%8b%b4%eb%b0%b0/?nocache='+Date.now(),{waitUntil:'load',timeout:90000});
await page.evaluate(()=>{ document.querySelectorAll('#pop-dim,#pop6,#dh-agegate2').forEach(e=>e.remove()); document.documentElement.classList.remove('dh-ag2-lock'); document.body.classList.remove('dh-ag2-lock'); });
await page.waitForTimeout(2000); await page.evaluate(()=>window.DHR.openBuySheet()); await page.waitForTimeout(600);
await page.screenshot({path:`${OUT}/sheet-light.png`});
console.log(await page.evaluate(()=>{ const q=document.querySelector('[data-dock] .quantity'); const c=getComputedStyle(q); const b=[...q.querySelectorAll('.vf-qty-btn')]; return {bg:c.backgroundColor, label:getComputedStyle(q.querySelector('.vf-qty-label')).color, minus:getComputedStyle(b[0]).color, plus:getComputedStyle(b[1]).color, input:getComputedStyle(q.querySelector('input.qty')).color, meta:document.querySelector('meta[name="format-detection"]')?.content||null}; }));
await b.close();
