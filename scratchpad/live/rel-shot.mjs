// 상품 상세의 추천 카드(.wc-prl-recommendations) 자리만 찍는다
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url); const { chromium } = require('playwright');
const S='https://duck-hoo.com', UA='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36';
const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium',args:['--no-sandbox']});
for (const [w,h,tag] of [[390,844,'m'],[1280,900,'d']]) {
const ctx=await b.newContext({viewport:{width:w,height:h},userAgent:UA,isMobile:w<880}); const page=await ctx.newPage();
await page.route('**/*', async route=>{ const url=route.request().url(); if(!/^https?:/.test(url)) return route.abort();
  try{ const r=await fetch(url,{headers:{'user-agent':UA},redirect:'manual'}); const hd={}; r.headers.forEach((v,k)=>{ if(!/^(content-encoding|transfer-encoding|content-length|set-cookie)$/i.test(k)) hd[k]=v; }); route.fulfill({status:r.status,headers:hd,body:Buffer.from(await r.arrayBuffer())}); }catch(e){ route.abort(); } });
await page.goto(S+'/product/%EB%85%B8%EB%B3%B4-%ED%83%80%EB%B0%95%EB%A9%98%EC%86%94-9-8mg-30ml/?nocache='+Date.now(),{waitUntil:'load',timeout:90000});
await page.evaluate(()=>{ document.querySelectorAll('#pop-dim,#pop6,#dh-agegate2').forEach(e=>e.remove()); document.documentElement.classList.remove('dh-ag2-lock'); document.body.classList.remove('dh-ag2-lock'); });
const el=await page.$('.wc-prl-recommendations, .related.products'); if(!el){ console.log(tag,'no related'); continue; }
await el.scrollIntoViewIfNeeded(); await page.waitForTimeout(1500);
await el.screenshot({path:`../audit-s3-prod/rel-${tag}.png`});
const m=await page.evaluate(()=>{ const cs=[...document.querySelectorAll('.wc-prl-recommendations .card, .related.products .card')]; return {n:cs.length, hs:cs.map(c=>Math.round(c.getBoundingClientRect().height)), btnBottom:cs.map(c=>{const b=c.querySelector('.buy'); return b?Math.round(c.getBoundingClientRect().bottom-b.getBoundingClientRect().bottom):null;})}; });
console.log(tag, JSON.stringify(m)); }
await b.close();
