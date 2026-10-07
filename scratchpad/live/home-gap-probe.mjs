// 프로덕션 홈: 히어로 점 → 타일 → 가림 안내 → 숫자 사이 간격 · 벤토 캡처 (390/1280)
import { createRequire } from 'node:module'; import fs from 'node:fs'; import path from 'node:path';
const require = createRequire(import.meta.url); const { chromium } = require('playwright');
const S='https://duck-hoo.com', UA='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36';
const ROOT='/home/user/new-website-build'; const OUT=process.env.OUT||'../audit-s3-prod';
const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium',args:['--no-sandbox']});
for (const [w,h,tag] of [[390,844,'m'],[1280,900,'d']]) {
  const ctx=await b.newContext({viewport:{width:w,height:h},userAgent:UA,isMobile:w<880}); const page=await ctx.newPage();
  await page.route('**/*', async route=>{ const u=route.request().url(); if(!/^https?:/.test(u)) return route.abort();
    const lm=process.env.LOCAL==='1' && u.match(/\/plugins\/new-website-build\/assets\/([a-z-]+\.(?:css|js))/); if(lm){ return route.fulfill({status:200,headers:{'content-type':lm[1].endsWith('.css')?'text/css':'application/javascript'},body:fs.readFileSync(path.join(ROOT,'assets',lm[1]))}); }
    try{ const r=await fetch(u,{headers:{'user-agent':UA},redirect:'manual'}); const hd={}; r.headers.forEach((v,k)=>{ if(!/^(content-encoding|transfer-encoding|content-length|set-cookie)$/i.test(k)) hd[k]=v; }); route.fulfill({status:r.status,headers:hd,body:Buffer.from(await r.arrayBuffer())}); }catch(e){ route.abort(); } });
  await page.goto(S+'/?nocache='+Date.now(),{waitUntil:'load',timeout:90000});
  await page.evaluate(()=>{ document.querySelectorAll('#pop-dim,#pop6,#dh-agegate2').forEach(e=>e.remove()); });
  const H=await page.evaluate(()=>document.body.scrollHeight); for(let y=0;y<H;y+=700){ await page.evaluate(v=>window.scrollTo(0,v),y); await page.waitForTimeout(70); }
  await page.waitForTimeout(1500);
  const m=await page.evaluate(()=>{ const r=s=>document.querySelector(s)?.getBoundingClientRect(); const d=r('.hero__dots'),qc=r('.qcats'),g=r('.qcats + .dhr-gate'),n=r('.nums'); return d&&qc&&g&&n?{dotsToTiles:Math.round(qc.top-d.bottom), tilesToGate:Math.round(g.top-qc.bottom), gateToNums:Math.round(n.top-g.bottom)}:'missing'; });
  console.log(tag, JSON.stringify(m));
  await page.evaluate(()=>window.scrollTo(0, document.querySelector('.qcats').getBoundingClientRect().top+window.scrollY-70)); await page.waitForTimeout(500); await page.screenshot({path:`${OUT}/gap-${tag}.png`});
  const ab=await page.$('.dhr-about'); if(ab){ await ab.scrollIntoViewIfNeeded(); await page.waitForTimeout(1500); await ab.screenshot({path:`${OUT}/about-${tag}.png`}); }
  await ctx.close(); }
await b.close();
