import { createRequire } from 'node:module'; import fs from 'node:fs'; import path from 'node:path';
const require = createRequire(import.meta.url); const { chromium } = require('playwright');
const S='https://duck-hoo.com', UA='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36';
const ROOT='/home/user/new-website-build'; const LOCAL=process.env.LOCAL==='1'; const THEME=process.env.THEME||'light';
const HV='<section class="hvid" aria-label="t"><a class="hvid__media" href="#"><video class="hvid__v" muted loop playsinline poster="/wp-content/plugins/new-website-build/assets/media/jello-crystal-16x9.jpg"></video></a><div class="hvid__bar"><div class="hvid__in"><h2 class="hvid__t">젤로 크리스탈 기기 + 액상 10병</h2><p class="hvid__sub">액상 10병은 맛을 직접 골라 담습니다</p><p class="hvid__p"><b>100,000원</b><s>218,000원</s><em>54% 할인</em></p><a class="hvid__cta" href="#">젤로 크리스탈 10병 구매하기</a></div></div></section>';
const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium',args:['--no-sandbox']});
const ctx=await b.newContext({viewport:{width:1280,height:900},userAgent:UA}); const page=await ctx.newPage();
await page.addInitScript((t)=>{ try{ localStorage.setItem('dhr-theme',t); }catch(e){} }, THEME);
let first=true;
await page.route('**/*', async route=>{ const u=route.request().url(); if(!/^https?:/.test(u)) return route.abort();
  const lm=LOCAL&&u.match(/\/plugins\/new-website-build\/assets\/([a-z-]+\.(?:css|js))/); if(lm){ return route.fulfill({status:200,headers:{'content-type':lm[1].endsWith('.css')?'text/css':'application/javascript'},body:fs.readFileSync(path.join(ROOT,'assets',lm[1]))}); }
  try{ const r=await fetch(u,{headers:{'user-agent':UA},redirect:'manual'}); const h={}; r.headers.forEach((v,k)=>{ if(!/^(content-encoding|transfer-encoding|content-length|set-cookie)$/i.test(k)) h[k]=v; });
    let body=Buffer.from(await r.arrayBuffer()); if(first && route.request().resourceType()==='document'){ first=false; let s=body.toString('utf8'); s=s.replace(/(<main[^>]*>)/, '$1'+HV); body=Buffer.from(s); }
    route.fulfill({status:r.status,headers:h,body}); }catch(e){ route.abort(); } });
await page.goto(S+'/?nocache='+Date.now(),{waitUntil:'load',timeout:90000});
await page.waitForTimeout(3000);
await page.evaluate(()=>{ for(const id of ['pop-dim','pop6']){ const e=document.getElementById(id); if(e) e.remove(); } });
const r=await page.evaluate(()=>{ const e=document.querySelector('.hvid__t'); if(!e) return 'no hvid'; const cs=getComputedStyle(e); const chain=[]; let n=e; while(n&&n!==document.body){ const c=getComputedStyle(n); chain.push([n.className, c.opacity, c.filter, c.mixBlendMode, n.getAttribute('style')]); n=n.parentElement; }
  return {theme:document.documentElement.getAttribute('data-theme'), color:cs.color, op:cs.opacity, style:e.getAttribute('style'), chain}; });
console.log(JSON.stringify(r,null,1));
const el=await page.$('.hvid'); if(el) await el.screenshot({path:'/tmp/claude-0/-home-user-new-website-build/eaa69852-6737-54fa-a860-4a2fc73b9c20/scratchpad/hvid-'+THEME+(LOCAL?'-local':'')+'.png'});
await b.close();
