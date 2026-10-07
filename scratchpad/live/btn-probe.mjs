import { createRequire } from 'node:module'; import fs from 'node:fs'; import path from 'node:path';
const require = createRequire(import.meta.url); const { chromium } = require('playwright');
const S='https://duck-hoo.com', UA='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36';
const ROOT='/home/user/new-website-build';
const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium',args:['--no-sandbox']});
const ctx=await b.newContext({viewport:{width:1150,height:900},userAgent:UA}); const page=await ctx.newPage();
await page.addInitScript(()=>{ try{ localStorage.setItem('dhr-theme','dark'); }catch(e){} });
await page.route('**/*', async route=>{ const u=route.request().url(); if(!/^https?:/.test(u)) return route.abort();
  const lm=u.match(/\/plugins\/new-website-build\/assets\/([a-z-]+\.(?:css|js))/); if(lm){ return route.fulfill({status:200,headers:{'content-type':lm[1].endsWith('.css')?'text/css':'application/javascript'},body:fs.readFileSync(path.join(ROOT,'assets',lm[1]))}); }
  try{ const r=await fetch(u,{headers:{'user-agent':UA},redirect:'manual'}); const h={}; r.headers.forEach((v,k)=>{ if(!/^(content-encoding|transfer-encoding|content-length|set-cookie)$/i.test(k)) h[k]=v; }); route.fulfill({status:r.status,headers:h,body:Buffer.from(await r.arrayBuffer())}); }catch(e){ route.abort(); } });
await page.goto(S+'/product/%eb%85%b8%eb%b3%b4-%eb%a6%ac%ed%80%b4%eb%93%9c-101-%ea%b8%88%ec%95%a1-120000%ec%9b%90/?nocache='+Date.now(),{waitUntil:'load',timeout:90000});
await page.waitForTimeout(2500);
const r=await page.evaluate(()=>{ const out={}; for(const s of ['.single_add_to_cart_button','.wd-direct-checkout-btn','.dhx-qty__n','.dhx-qty button']){ const e=document.querySelector(s); if(!e){ out[s]=null; continue; } const cs=getComputedStyle(e); out[s]={cls:e.className,dis:e.disabled,aria:e.getAttribute('aria-disabled'),style:e.getAttribute('style'),bg:cs.backgroundColor,color:cs.color,op:cs.opacity,parent:e.parentElement.className}; }
  const q=document.querySelector('.single_add_to_cart_button'); out.rules=[]; for(const sh of document.styleSheets){ let rs; try{ rs=[...sh.cssRules]; }catch(e){ continue; } const walk=l=>{ for(const r of l){ if(r.cssRules&&r.media){ walk([...r.cssRules]); continue; } if(!r.selectorText) continue; let m=false; try{ m=q.matches(r.selectorText);}catch(e){} if(m&&/background|color|opacity/.test(r.style.cssText)) out.rules.push([(sh.href||'inline').split('/').pop().slice(0,24), r.selectorText.slice(0,110), r.style.cssText.slice(0,120)]); } }; walk(rs); }
  return out; });
console.log(JSON.stringify(r,null,1)); await b.close();
