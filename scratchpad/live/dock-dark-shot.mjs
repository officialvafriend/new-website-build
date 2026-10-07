// 다크 · 데스크톱 서랍: 세트를 고른 뒤 맛을 두 병 담은 상태로 서랍 전체를 찍는다 (사장님 캡처와 같은 상태)
import { createRequire } from 'node:module'; import fs from 'node:fs'; import path from 'node:path';
const require = createRequire(import.meta.url); const { chromium } = require('playwright');
const S='https://duck-hoo.com', UA='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36';
const ROOT='/home/user/new-website-build'; const OUT=process.env.OUT||'../audit-s3-local';
const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium',args:['--no-sandbox']});
for (const theme of ['dark','light']) {
const ctx=await b.newContext({viewport:{width:1150,height:900},userAgent:UA}); const page=await ctx.newPage();
if(theme==='dark') await page.addInitScript(()=>{ try{ localStorage.setItem('dhr-theme','dark'); }catch(e){} });
await page.route('**/*', async route=>{ const u=route.request().url(); if(!/^https?:/.test(u)) return route.abort(); if(/add-to-cart|wc-ajax=add_to_cart|\/cart\/add-item/.test(u)) return route.abort();
  const lm=process.env.LOCAL!=='0' && u.match(/\/plugins\/new-website-build\/assets\/([a-z-]+\.(?:css|js))/); if(lm){ return route.fulfill({status:200,headers:{'content-type':lm[1].endsWith('.css')?'text/css':'application/javascript'},body:fs.readFileSync(path.join(ROOT,'assets',lm[1]))}); }
  try{ const r=await fetch(u,{headers:{'user-agent':UA},redirect:'manual'}); const h={}; r.headers.forEach((v,k)=>{ if(!/^(content-encoding|transfer-encoding|content-length|set-cookie)$/i.test(k)) h[k]=v; }); route.fulfill({status:r.status,headers:h,body:Buffer.from(await r.arrayBuffer())}); }catch(e){ route.abort(); } });
await page.goto(S+'/product/%eb%85%b8%eb%b3%b4-%eb%a6%ac%ed%80%b4%eb%93%9c-101-%ea%b8%88%ec%95%a1-120000%ec%9b%90/?nocache='+Date.now(),{waitUntil:'load',timeout:90000});
await page.evaluate(()=>{ document.querySelectorAll('#pop-dim,#pop6,#dh-agegate2').forEach(e=>e.remove()); document.documentElement.classList.remove('dh-ag2-lock'); document.body.classList.remove('dh-ag2-lock'); });
await page.waitForTimeout(3000);
const t=await page.$('.dhpk__tile .dhpk__b--p'); if(t){ await t.click({force:true}); await page.waitForTimeout(1500); }
await page.evaluate(()=>window.scrollTo(0,1500)); await page.waitForTimeout(1500);
await page.screenshot({path:`${OUT}/dock2-${theme}.png`});
const m=await page.evaluate(()=>{ const q=s=>document.querySelector(s); const cs=e=>e?getComputedStyle(e):null; const g=(s)=>{const e=q(s); return e?{color:cs(e).color,bg:cs(e).backgroundColor}:null;};
  return {sumLabel:g('.dhx-sum__label'), sumTotal:g('.dhx-sum__total'), gaugeN:g('.dhx-gauge__n'), gaugeFill:g('.dhx-gauge__fill'), picked:g('.dhx-picked__row, .dhx-picked li, .dhx-picked'), card1Title:g('.dhx-card__title, .dhx-card__head b, .dhx-card__head'), qtyN:g('.dhx-qty__n'), btn:g('.single_add_to_cart_button'), dock:g('.dhp-card--form')}; });
console.log(theme, JSON.stringify(m)); await ctx.close(); }
await b.close();
