import { createRequire } from 'node:module'; import fs from 'node:fs'; import path from 'node:path';
const require = createRequire(import.meta.url); const { chromium } = require('playwright');
const S='https://duck-hoo.com', UA='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36';
const ROOT='/home/user/new-website-build'; const LOCAL=process.env.LOCAL==='1'; const THEME=process.env.THEME||'light';
const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium',args:['--no-sandbox']});
const ctx=await b.newContext({viewport:{width:1280,height:900},userAgent:UA}); const page=await ctx.newPage();
await page.addInitScript((t)=>{ try{ localStorage.setItem('dhr-theme',t); }catch(e){} }, THEME);
await page.route('**/*', async route=>{ const u=route.request().url(); if(!/^https?:/.test(u)) return route.abort();
  const lm=LOCAL&&u.match(/\/plugins\/new-website-build\/assets\/([a-z-]+\.(?:css|js))/); if(lm){ return route.fulfill({status:200,headers:{'content-type':lm[1].endsWith('.css')?'text/css':'application/javascript'},body:fs.readFileSync(path.join(ROOT,'assets',lm[1]))}); }
  try{ const r=await fetch(u,{headers:{'user-agent':UA},redirect:'manual'}); const h={}; r.headers.forEach((v,k)=>{ if(!/^(content-encoding|transfer-encoding|content-length|set-cookie)$/i.test(k)) h[k]=v; }); route.fulfill({status:r.status,headers:h,body:Buffer.from(await r.arrayBuffer())}); }catch(e){ route.abort(); } });
await page.goto(S+'/?nocache='+Date.now(),{waitUntil:'load',timeout:90000});
await page.waitForTimeout(1500);
await page.evaluate(()=>{window.__T='light'; var t=document.querySelector('.gi.tgl,[data-theme-toggle]'); window.__btn=!!t;});
const r=await page.evaluate(()=>{ const main=document.querySelector('main')||document.body; const s=document.createElement('section'); s.className='hvid';
 s.innerHTML='<a class="hvid__media"><video class="hvid__v"></video></a><div class="hvid__bar"><div class="hvid__in"><h2 class="hvid__t">젤로 크리스탈</h2><p class="hvid__sub">sub</p><p class="hvid__p"><b>100,000원</b><s>218,000원</s></p><a class="hvid__cta">구매</a></div></div>';
 main.prepend(s); if(window.__T) document.documentElement.setAttribute('data-theme',window.__T); const out={theme:document.documentElement.dataset.theme}; for(const q of ['.hvid__t','.hvid__sub','.hvid__p b','.hvid__p s','.hvid__cta']){ const e=s.querySelector(q); const cs=getComputedStyle(e); out[q]={color:cs.color,op:cs.opacity,mix:cs.mixBlendMode,bgclip:cs.webkitTextFillColor}; }
 const q=s.querySelector('.hvid__t'); out.rules=[]; for(const sh of document.styleSheets){ let rs; try{ rs=[...sh.cssRules]; }catch(e){ continue; } const walk=l=>{ for(const r of l){ if(r.cssRules&&!r.selectorText){ walk([...r.cssRules]); continue; } if(!r.selectorText) continue; let m=false; try{ m=q.matches(r.selectorText);}catch(e){} if(m&&/color|opacity|fill|filter|blend/.test(r.style.cssText)) out.rules.push([(sh.href||'inline').split('/').pop().slice(0,24), r.selectorText.slice(0,140), r.style.cssText.slice(0,160)]); } }; walk(rs); }
 return out; });
console.log(JSON.stringify(r,null,1)); await b.close();
