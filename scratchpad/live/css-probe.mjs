// 작업본 CSS 가 실제로 끼워졌는지 · 카드 computed 값을 찍는다: node css-probe.mjs <path>
import { createRequire } from 'node:module'; import fs from 'node:fs'; import path from 'node:path';
const require = createRequire(import.meta.url); const { chromium } = require('playwright');
const S='https://duck-hoo.com', UA='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36';
const ROOT='/home/user/new-website-build';
const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium',args:['--no-sandbox']});
const ctx=await b.newContext({viewport:{width:1280,height:900},userAgent:UA}); const page=await ctx.newPage();
await page.route('**/*', async route=>{ const url=route.request().url(); if(!/^https?:/.test(url)) return route.abort();
  const lm=url.match(/\/plugins\/new-website-build\/assets\/([a-z-]+\.(?:css|js))/); if(lm){ return route.fulfill({status:200,headers:{'content-type':lm[1].endsWith('.css')?'text/css':'application/javascript'},body:fs.readFileSync(path.join(ROOT,'assets',lm[1]))}); }
  try{ const r=await fetch(url,{headers:{'user-agent':UA},redirect:'manual'}); const h={}; r.headers.forEach((v,k)=>{ if(!/^(content-encoding|transfer-encoding|content-length|set-cookie)$/i.test(k)) h[k]=v; }); route.fulfill({status:r.status,headers:h,body:Buffer.from(await r.arrayBuffer())}); }catch(e){ route.abort(); } });
await page.goto(S+(process.argv[2]||'/shop/')+'?nocache='+Date.now(),{waitUntil:'load',timeout:90000});
const r=await page.evaluate(()=>{ const out={}; for(const s of document.styleSheets){ try{ const t=[...s.cssRules].map(r=>r.cssText).join(''); if(/front\.css/.test(s.href||'')) out.frontHasMarker=t.includes('is-gated .fig img'); if(/shell\.css/.test(s.href||'')) out.shellLen=t.length; }catch(e){ out.err=(s.href||'')+' '+e.message; } }
  const c=document.querySelector('.card'); const buy=c&&c.querySelector('.buy'); const cs=e=>e&&getComputedStyle(e);
  out.card=c&&{radius:cs(c).borderRadius,border:cs(c).borderTopWidth+' '+cs(c).borderTopColor,pad:cs(c).padding,transform:cs(c).transform};
  out.buy=buy&&{align:cs(buy).alignSelf,w:buy.getBoundingClientRect().width,cw:c.getBoundingClientRect().width};
  const img=c&&c.querySelector('.fig img'); out.img=img&&{w:img.getBoundingClientRect().width,pos:cs(img).position,cls:img.className};
  out.links=[...document.querySelectorAll('link[rel=stylesheet]')].map(l=>l.href).filter(h=>/new-website-build/.test(h));
  return out; });
console.log(JSON.stringify(r,null,1)); await b.close();
