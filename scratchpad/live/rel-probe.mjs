// 상세 추천 카드 이미지에 걸리는 규칙을 전부 찍는다
import { createRequire } from 'node:module'; import fs from 'node:fs'; import path from 'node:path';
const require = createRequire(import.meta.url); const { chromium } = require('playwright');
const S='https://duck-hoo.com', UA='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36';
const ROOT='/home/user/new-website-build';
const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium',args:['--no-sandbox']});
const ctx=await b.newContext({viewport:{width:1280,height:900},userAgent:UA}); const page=await ctx.newPage();
await page.route('**/*', async route=>{ const url=route.request().url(); if(!/^https?:/.test(url)) return route.abort();
  const lm=process.env.LOCAL==='1' && url.match(/\/plugins\/new-website-build\/assets\/([a-z-]+\.(?:css|js))/); if(lm){ return route.fulfill({status:200,headers:{'content-type':lm[1].endsWith('.css')?'text/css':'application/javascript'},body:fs.readFileSync(path.join(ROOT,'assets',lm[1]))}); }
  try{ const r=await fetch(url,{headers:{'user-agent':UA},redirect:'manual'}); const h={}; r.headers.forEach((v,k)=>{ if(!/^(content-encoding|transfer-encoding|content-length|set-cookie)$/i.test(k)) h[k]=v; }); route.fulfill({status:r.status,headers:h,body:Buffer.from(await r.arrayBuffer())}); }catch(e){ route.abort(); } });
await page.goto(S+'/product/%EB%85%B8%EB%B3%B4-%ED%83%80%EB%B0%95%EB%A9%98%EC%86%94-9-8mg-30ml/?nocache='+Date.now(),{waitUntil:'load',timeout:90000});
const r=await page.evaluate(()=>{ const c=document.querySelector('.wc-prl-recommendations .card, .related.products .card'); if(!c) return 'no card'; const img=c.querySelector('.fig img'); const cs=getComputedStyle(img); const out={cls:c.className, imgCls:img.className, w:img.getBoundingClientRect().width, pos:cs.position, blend:cs.mixBlendMode, rules:[]};
  for(const s of document.styleSheets){ let rs; try{ rs=[...s.cssRules]; }catch(e){ continue; } const walk=(list)=>{ for(const r of list){ if(r.cssRules && r.media) { walk([...r.cssRules]); continue; } if(!r.selectorText) continue; let m=false; try{ m=img.matches(r.selectorText); }catch(e){} if(m && /(width|height|position|inset|top|bottom|object-fit|transform|max-width)/.test(r.style.cssText)) out.rules.push([(s.href||'inline').split('/').pop().slice(0,30), r.selectorText.slice(0,120), r.style.cssText.slice(0,160)]); } }; walk(rs); }
  return out; });
console.log(JSON.stringify(r,null,1)); await b.close();
