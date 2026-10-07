// 홈 카루셀 카드 사진의 computed 값: 왜 안 보이는가
import { createRequire } from 'node:module'; import fs from 'node:fs'; import path from 'node:path';
const require = createRequire(import.meta.url); const { chromium } = require('playwright');
const S='https://duck-hoo.com', UA='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36';
const ROOT='/home/user/new-website-build';
const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium',args:['--no-sandbox']});
const ctx=await b.newContext({viewport:{width:1280,height:900},userAgent:UA}); const page=await ctx.newPage();
await page.route('**/*', async route=>{ const url=route.request().url(); if(!/^https?:/.test(url)) return route.abort();
  const lm=process.env.LOCAL!=="0" && url.match(/\/plugins\/new-website-build\/assets\/([a-z-]+\.(?:css|js))/); if(lm){ return route.fulfill({status:200,headers:{'content-type':lm[1].endsWith('.css')?'text/css':'application/javascript'},body:fs.readFileSync(path.join(ROOT,'assets',lm[1]))}); }
  try{ const r=await fetch(url,{headers:{'user-agent':UA},redirect:'manual'}); const h={}; r.headers.forEach((v,k)=>{ if(!/^(content-encoding|transfer-encoding|content-length|set-cookie)$/i.test(k)) h[k]=v; }); route.fulfill({status:r.status,headers:h,body:Buffer.from(await r.arrayBuffer())}); }catch(e){ route.abort(); } });
await page.goto(S+'/?nocache='+Date.now(),{waitUntil:'load',timeout:90000});
await page.evaluate(()=>window.scrollTo(0,1500)); await page.waitForTimeout(3000);
const r=await page.evaluate(()=>{ const img=document.querySelector('.dhs .card .fig img'); const cs=getComputedStyle(img); const fig=img.closest('.fig'); const fr=fig.getBoundingClientRect(), ir=img.getBoundingClientRect();
  return {cls:img.className, body:document.body.className, src:img.currentSrc.slice(-40), complete:img.complete, nw:img.naturalWidth, opacity:cs.opacity, transform:cs.transform, pos:cs.position, w:ir.width,h:ir.height, top:ir.top-fr.top, left:ir.left-fr.left, figW:fr.width, figH:fr.height, blend:cs.mixBlendMode, figPos:getComputedStyle(fig).position, disp:cs.display, vis:cs.visibility}; });
console.log(JSON.stringify(r,null,1)); await b.close();
