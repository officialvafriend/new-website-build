// /agree/ 에서 필수 셋 체크 → 「동의하고 가입」 누르면 dhr_agree 쿠키가 적히는지 · 상품 팝업의 가입 링크가 /register/ 로 바뀌는지
import { createRequire } from 'node:module'; const require = createRequire(import.meta.url); const { chromium } = require('playwright');
const S='https://duck-hoo.com', UA='Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1';
const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium',args:['--no-sandbox']});
const ctx=await b.newContext({viewport:{width:390,height:844},userAgent:UA}); const page=await ctx.newPage(); const errs=[]; page.on('pageerror',e=>errs.push(e.message)); page.on('dialog',d=>d.dismiss().catch(()=>{}));
await page.route('**/*', async route=>{ const u=route.request().url(); if(!/^https?:/.test(u)) return route.abort(); if(route.request().method()==='POST') return route.fulfill({status:200,body:'{}'});
  try{ const r=await fetch(u,{headers:{'user-agent':UA},redirect:'follow'}); const h={}; r.headers.forEach((v,k)=>{ if(!/^(content-encoding|transfer-encoding|content-length|set-cookie)$/i.test(k)) h[k]=v; }); route.fulfill({status:r.status,headers:h,body:Buffer.from(await r.arrayBuffer())}); }catch(e){ route.abort(); } });
await page.goto(S+'/agree/?nocache='+Date.now(),{waitUntil:'load',timeout:90000}); await page.waitForTimeout(800);
// 필수만 체크 (SMS 는 안 체크) → 버튼 — 이동은 막는다
await page.evaluate(()=>{ ['wdAgreeTerms','wdAgreePrivacy','wdAgreeAge','wdAgreeEmail'].forEach(id=>{ const c=document.getElementById(id); if(c) c.checked=true; }); window.addEventListener('beforeunload',e=>{e.preventDefault();}); });
const navP = page.waitForNavigation({timeout:4000}).catch(()=>null);
await page.evaluate(()=>{ const b=document.getElementById('wdAgreeSubmit'); const stop=e=>{ e.preventDefault(); }; b.addEventListener('click',()=>{}); b.click(); });
await page.waitForTimeout(600);
const ck=(await ctx.cookies(S)).filter(c=>c.name==='dhr_agree').map(c=>c.value);
console.log('agree cookie after click (sms unchecked, email checked):', ck, 'errors:', errs.filter(e=>/front\.js/.test(e)));
await navP;
// 상품 팝업 링크
const p2=await ctx.newPage(); await p2.route('**/*', async route=>{ const u=route.request().url(); if(!/^https?:/.test(u)) return route.abort(); if(route.request().method()==='POST') return route.fulfill({status:200,body:'{}'}); try{ const r=await fetch(u,{headers:{'user-agent':UA},redirect:'follow'}); const h={}; r.headers.forEach((v,k)=>{ if(!/^(content-encoding|transfer-encoding|content-length|set-cookie)$/i.test(k)) h[k]=v; }); route.fulfill({status:r.status,headers:h,body:Buffer.from(await r.arrayBuffer())}); }catch(e){ route.abort(); } });
await p2.goto(S+'/product/%EB%85%B8%EB%B3%B4-%ED%83%80%EB%B0%95%EB%A9%98%EC%86%94-9-8mg-30ml/?nocache='+Date.now(),{waitUntil:'load',timeout:90000}); await p2.waitForTimeout(1500);
console.log('popup signup href:', await p2.evaluate(()=>{ const a=document.querySelector('#dh-agegate2 .dh-ag2-primary'); return a&&a.getAttribute('href'); }));
await b.close();
