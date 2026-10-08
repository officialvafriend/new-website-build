// 노보 타박멘솔(단품 · 테마 옵션 빌더) 다크 · 데스크톱 서랍 — 밝은 면 요소 찾기
import { createRequire } from 'node:module'; import fs from 'node:fs'; import path from 'node:path';
const require = createRequire(import.meta.url); const { chromium } = require('playwright');
const S='https://duck-hoo.com', UA='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36';
const ROOT='/home/user/new-website-build'; const OUT=process.env.OUT||path.join(ROOT,'scratchpad/dark-probe3'); fs.mkdirSync(OUT,{recursive:true});
const url='/product/%EB%85%B8%EB%B3%B4-%ED%83%80%EB%B0%95%EB%A9%98%EC%86%94-9-8mg-30ml/';
const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium',args:['--no-sandbox']});
const ctx=await b.newContext({viewport:{width:1150,height:900},userAgent:UA}); const page=await ctx.newPage();
await page.addInitScript(()=>{ try{ localStorage.removeItem('dhr-theme'); }catch(e){} });
await page.route('**/*', async route=>{ const u=route.request().url(); if(!/^https?:/.test(u)) return route.abort();
  if(route.request().method()==='POST' && /add-to-cart|add_to_cart|cart\/add-item/.test(u)) return route.abort();
  const lm=process.env.LOCAL==='1' && u.match(/\/plugins\/new-website-build\/assets\/([a-z-]+\.(?:css|js))/); if(lm){ return route.fulfill({status:200,headers:{'content-type':lm[1].endsWith('.css')?'text/css':'application/javascript'},body:fs.readFileSync(path.join(ROOT,'assets',lm[1]))}); }
  try{ const r=await fetch(u,{headers:{'user-agent':UA},redirect:'manual'}); const h={}; r.headers.forEach((v,k)=>{ if(!/^(content-encoding|transfer-encoding|content-length|set-cookie)$/i.test(k)) h[k]=v; }); route.fulfill({status:r.status,headers:h,body:Buffer.from(await r.arrayBuffer())}); }catch(e){ route.abort(); } });
const r=await page.goto(S+url+'?nocache='+Date.now(),{waitUntil:'load',timeout:90000}); console.log('status',r.status());
await page.evaluate(()=>{ document.querySelectorAll('#pop-dim,#pop6,#dh-agegate2').forEach(e=>e.remove()); document.documentElement.classList.remove('dh-ag2-lock'); document.body.classList.remove('dh-ag2-lock'); });
await page.waitForTimeout(2500);
await page.evaluate(()=>window.scrollTo(0,1600)); await page.waitForTimeout(1200);
await page.screenshot({path:`${OUT}/dock.png`});
const light=await page.evaluate(()=>{ const card=document.querySelector('[data-dock]'); const out=[]; card.querySelectorAll('*').forEach(e=>{ const c=getComputedStyle(e); const m=c.backgroundColor.match(/[\d.]+/g); if(!m) return; const [r,g,b,a]=m.map(Number); if(a===0) return; const L=(0.2126*r+0.7152*g+0.0722*b)/255; const r2=e.getBoundingClientRect(); if(L>0.6 && r2.width>5&&r2.height>5) out.push({sel:e.tagName.toLowerCase()+(typeof e.className==='string'&&e.className?'.'+e.className.trim().split(/\s+/).slice(0,4).join('.'):''), id:e.id, bg:c.backgroundColor, color:c.color, w:Math.round(r2.width), h:Math.round(r2.height), text:(e.innerText||e.value||'').trim().slice(0,24), style:(e.getAttribute('style')||'').slice(0,80)}); }); return out; });
console.log('LIGHT', JSON.stringify(light,null,1));
const html=await page.evaluate(()=>{ const q=document.querySelector('[data-dock] .wd-option-item, [data-dock] .wd-option-list, [data-dock] .wd-option-builder'); return q?q.outerHTML.slice(0,1500):null; });
console.log('TOP', await page.evaluate(()=>{const t=document.querySelector('[data-dock] .wd-option-item__top');const c=getComputedStyle(t);const s=document.querySelector('[data-dock] .wd-option-qty > span');const sc=getComputedStyle(s);return {topBorder:c.borderBottom, spanBg:sc.backgroundColor, spanColor:sc.color};}));
await b.close();
