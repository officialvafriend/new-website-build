// 어떤 리소스가 실패하는지: 상품 상세 · 폰 · 다크
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url); const { chromium } = require('playwright');
const S='https://duck-hoo.com', UA='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36';
const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium',args:['--no-sandbox']});
const ctx=await b.newContext({viewport:{width:390,height:844},userAgent:UA,isMobile:true}); const page=await ctx.newPage();
await page.addInitScript(()=>{ try{ localStorage.setItem('dhr-theme','dark'); }catch(e){} });
const fails=[]; page.on('requestfailed', r=>fails.push([r.url().slice(0,140), r.failure()?.errorText]));
page.on('console', m=>{ if(m.type()==='error') fails.push(['console', m.text().slice(0,160), m.location()?.url?.slice(0,120)]); });
await page.route('**/*', async route=>{ const url=route.request().url(); if(!/^https?:/.test(url)) return route.abort();
  try{ const r=await fetch(url,{method:route.request().method(),headers:{'user-agent':UA,accept:'*/*'},body:route.request().postData()??undefined,redirect:'manual'}); const hd={}; r.headers.forEach((v,k)=>{ if(!/^(content-encoding|transfer-encoding|content-length|set-cookie)$/i.test(k)) hd[k]=v; }); route.fulfill({status:r.status,headers:hd,body:Buffer.from(await r.arrayBuffer())}); }catch(e){ fails.push(['fetch-abort',url.slice(0,140),String(e).slice(0,80)]); route.abort(); } });
await page.goto(S+'/product/%EB%85%B8%EB%B3%B4-%ED%83%80%EB%B0%95%EB%A9%98%EC%86%94-9-8mg-30ml/?nocache='+Date.now(),{waitUntil:'load',timeout:90000});
await page.waitForTimeout(3000); console.log(JSON.stringify(fails,null,1)); await b.close();
