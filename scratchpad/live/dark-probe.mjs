// 상세 다크(390px) — 시트를 연 뒤 흰·밝은 배경 요소와 글자 대비가 낮은 요소를 찾는다
import { createRequire } from 'node:module'; import fs from 'node:fs'; import path from 'node:path';
const require = createRequire(import.meta.url); const { chromium } = require('playwright');
const S='https://duck-hoo.com', UA='Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
const ROOT='/home/user/new-website-build'; const OUT=process.env.OUT||'../dark-probe'; fs.mkdirSync(OUT,{recursive:true});
const url=process.argv[2]||'/product/%eb%85%b8%eb%b3%b4-%eb%a6%ac%ed%80%b4%eb%93%9c-101-%ea%b8%88%ec%95%a1-120000%ec%9b%90/';
const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium',args:['--no-sandbox']});
const ctx=await b.newContext({viewport:{width:390,height:844},userAgent:UA,deviceScaleFactor:2}); const page=await ctx.newPage();
await page.addInitScript(()=>{ try{ localStorage.setItem('dhr-theme','dark'); }catch(e){} });
await page.route('**/*', async route=>{ const u=route.request().url(); if(!/^https?:/.test(u)) return route.abort();
  const lm=process.env.LOCAL==='1' && u.match(/\/plugins\/new-website-build\/assets\/([a-z-]+\.(?:css|js))/); if(lm){ return route.fulfill({status:200,headers:{'content-type':lm[1].endsWith('.css')?'text/css':'application/javascript'},body:fs.readFileSync(path.join(ROOT,'assets',lm[1]))}); }
  try{ const r=await fetch(u,{headers:{'user-agent':UA},redirect:'manual'}); const h={}; r.headers.forEach((v,k)=>{ if(!/^(content-encoding|transfer-encoding|content-length|set-cookie)$/i.test(k)) h[k]=v; }); route.fulfill({status:r.status,headers:h,body:Buffer.from(await r.arrayBuffer())}); }catch(e){ route.abort(); } });
await page.goto(S+url+'?nocache='+Date.now(),{waitUntil:'load',timeout:90000});
await page.evaluate(()=>{ document.querySelectorAll('#pop-dim,#pop6,#dh-agegate2').forEach(e=>e.remove()); document.documentElement.classList.remove('dh-ag2-lock'); document.body.classList.remove('dh-ag2-lock'); });
await page.waitForTimeout(2500);
// 1) 구매 줄 — 상자를 지나친 뒤
await page.evaluate(()=>window.scrollTo(0,2600)); await page.waitForTimeout(800);
await page.screenshot({path:`${OUT}/bar.png`});
const bar=await page.evaluate(()=>{ const q=s=>document.querySelector(s); const cs=e=>e&&getComputedStyle(e); const b=q('.dhp-bar'); if(!b) return null; const out={};
  b.querySelectorAll('button, a, span, b, strong').forEach((e,i)=>{ const c=cs(e); if(e.innerText.trim()) out[(e.className||e.tagName)+'#'+i]={text:e.innerText.trim().slice(0,20),color:c.color,bg:c.backgroundColor,op:c.opacity,dis:e.disabled}; }); return out; });
console.log('BAR', JSON.stringify(bar,null,1));
// 2) 시트 안 — 밝은 배경 요소
await page.evaluate(()=>window.DHR.openBuySheet()); await page.waitForTimeout(600);
await page.screenshot({path:`${OUT}/sheet.png`});
const lum=s=>{ const m=s.match(/[\d.]+/g); if(!m) return null; const [r,g,b,a]=m.map(Number); if(a===0) return null; return (0.2126*r+0.7152*g+0.0722*b)/255; };
const light=await page.evaluate(()=>{ const card=document.querySelector('[data-dock]'); const out=[]; card.querySelectorAll('*').forEach(e=>{ const c=getComputedStyle(e); const m=c.backgroundColor.match(/[\d.]+/g); if(!m) return; const [r,g,b,a]=m.map(Number); if(a===0) return; const L=(0.2126*r+0.7152*g+0.0722*b)/255; if(L>0.6){ const r2=e.getBoundingClientRect(); if(r2.width>20&&r2.height>10) out.push({sel:e.tagName.toLowerCase()+(e.className&&typeof e.className==='string'?'.'+e.className.trim().split(/\s+/).join('.'):''), bg:c.backgroundColor, color:c.color, w:Math.round(r2.width), h:Math.round(r2.height), text:(e.innerText||'').trim().slice(0,24)}); } }); return out.slice(0,40); });
console.log('LIGHT-IN-SHEET', JSON.stringify(light,null,1));
// 3) 설명의 규격 표
const spec=await page.evaluate(()=>{ const t=[...document.querySelectorAll('.dhp-desc table, .dhp-about ~ * table, main table')].slice(0,3); return t.map(e=>{ const c=getComputedStyle(e); const p=e.parentElement; return {cls:e.className, bg:c.backgroundColor, color:c.color, parent:p.tagName+'.'+p.className, parentBg:getComputedStyle(p).backgroundColor, inlineStyle:(e.getAttribute('style')||'').slice(0,80), tdStyle:(e.querySelector('td')?.getAttribute('style')||'').slice(0,80), ancestors:(()=>{ let a=e, arr=[]; for(let i=0;i<5&&a;i++){ a=a.parentElement; if(a) arr.push(a.tagName.toLowerCase()+'.'+(a.className||'').toString().slice(0,30)+' bg='+getComputedStyle(a).backgroundColor); } return arr; })() }; }); });
console.log('SPEC', JSON.stringify(spec,null,1));
await b.close();
