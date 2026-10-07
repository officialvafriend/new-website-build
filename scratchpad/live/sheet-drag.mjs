// 모바일 구매 시트 손잡이 끌기 시험 — 작게 끌면 되돌아오고, 많이·빠르게 끌면 닫히는지 (LOCAL=1 이면 작업본 CSS·JS)
import { createRequire } from 'node:module'; import fs from 'node:fs'; import path from 'node:path';
const require = createRequire(import.meta.url); const { chromium } = require('playwright');
const S='https://duck-hoo.com', UA='Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
const ROOT='/home/user/new-website-build'; const OUT=process.env.OUT||'../sheet-drag'; fs.mkdirSync(OUT,{recursive:true});
const url=process.argv[2]||'/product/%eb%85%b8%eb%b3%b4-%eb%a6%ac%ed%80%b4%eb%93%9c-101-%ea%b8%88%ec%95%a1-120000%ec%9b%90/';
const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium',args:['--no-sandbox']});
const ctx=await b.newContext({viewport:{width:390,height:844},userAgent:UA,deviceScaleFactor:2}); const page=await ctx.newPage();
const errs=[]; page.on('pageerror',e=>errs.push(String(e.message)));
let posts=0;
await page.route('**/*', async route=>{ const u=route.request().url(); if(!/^https?:/.test(u)) return route.abort();
  if(route.request().method()==='POST' && /add-to-cart|add_to_cart|cart\/add-item/.test(u)){ posts++; return route.abort(); }
  const lm=process.env.LOCAL==='1' && u.match(/\/plugins\/new-website-build\/assets\/([a-z-]+\.(?:css|js))/); if(lm){ return route.fulfill({status:200,headers:{'content-type':lm[1].endsWith('.css')?'text/css':'application/javascript'},body:fs.readFileSync(path.join(ROOT,'assets',lm[1]))}); }
  try{ const r=await fetch(u,{headers:{'user-agent':UA},redirect:'manual'}); const h={}; r.headers.forEach((v,k)=>{ if(!/^(content-encoding|transfer-encoding|content-length|set-cookie)$/i.test(k)) h[k]=v; }); route.fulfill({status:r.status,headers:h,body:Buffer.from(await r.arrayBuffer())}); }catch(e){ route.abort(); } });
const r=await page.goto(S+url+'?nocache='+Date.now(),{waitUntil:'load',timeout:90000}); console.log('status',r.status());
await page.evaluate(()=>{ document.querySelectorAll('#pop-dim,#pop6,#dh-agegate2').forEach(e=>e.remove()); document.documentElement.classList.remove('dh-ag2-lock'); document.body.classList.remove('dh-ag2-lock'); });
await page.waitForTimeout(2000);
const state=()=>page.evaluate(()=>{ const c=document.querySelector('[data-dock]'); const d=document.querySelector('[data-sheet-dim]');
  return { sheet:c.classList.contains('is-sheet'), dragging:c.classList.contains('is-dragging'), tf:getComputedStyle(c).transform, styleTf:c.style.transform, body:document.body.classList.contains('dhp-sheet-on'), dim:d?!d.hidden:null, dimOp:d?getComputedStyle(d).opacity:null, h:c.offsetHeight, top:c.getBoundingClientRect().top, ta:getComputedStyle(c.querySelector('.dhp-dock__head')).touchAction }; });
const openIt=async()=>{ await page.evaluate(()=>window.DHR.openBuySheet()); await page.waitForTimeout(500); };
const grab=async()=>{ const bb=await page.evaluate(()=>{ const h=document.querySelector('[data-dock] .dhp-dock__head'); const r=h.getBoundingClientRect(); return {x:r.left+r.width/2, y:r.top+10}; }); return bb; };
// A — 살짝 끌었다 놓기 → 되돌아온다
await openIt(); let s0=await state(); console.log('opened', s0);
let g=await grab(); await page.mouse.move(g.x,g.y); await page.mouse.down();
for(let i=1;i<=8;i++){ await page.mouse.move(g.x, g.y+i*5); await page.waitForTimeout(40); }
const mid=await state(); console.log('A mid', {styleTf:mid.styleTf, dragging:mid.dragging, dimOp:mid.dimOp});
await page.screenshot({path:`${OUT}/a-mid.png`});
await page.mouse.up(); await page.waitForTimeout(600); const a=await state(); console.log('A after', a);
// B — 반 넘게 끌어 내리기 → 닫힌다
g=await grab(); await page.mouse.move(g.x,g.y); await page.mouse.down();
for(let i=1;i<=12;i++){ await page.mouse.move(g.x, g.y+i*(s0.h*0.5/12)); await page.waitForTimeout(30); }
await page.screenshot({path:`${OUT}/b-mid.png`});
await page.mouse.up(); await page.waitForTimeout(500); const bb=await state(); console.log('B after', bb);
// C — 짧게 빠르게 튕기기(60px · 60ms) → 닫힌다
await openIt(); g=await grab(); await page.mouse.move(g.x,g.y); await page.mouse.down();
for(let i=1;i<=4;i++){ await page.mouse.move(g.x, g.y+i*15); await page.waitForTimeout(12); }
await page.mouse.up(); await page.waitForTimeout(500); const c=await state(); console.log('C after', c);
// D — 위로 끌기 → 고무줄, 놓으면 제자리
await openIt(); g=await grab(); await page.mouse.move(g.x,g.y); await page.mouse.down();
for(let i=1;i<=6;i++){ await page.mouse.move(g.x, g.y-i*20); await page.waitForTimeout(30); }
const dmid=await state(); console.log('D mid', dmid.styleTf);
await page.mouse.up(); await page.waitForTimeout(600); const d=await state(); console.log('D after', d);
// E — 되돌아오는 중(360ms 스프링)에 다시 잡아 끌어 내리기: 보이던 자리에서 이어져 닫혀야 한다
g=await grab(); await page.mouse.move(g.x,g.y); await page.mouse.down();
for(let i=1;i<=5;i++){ await page.mouse.move(g.x, g.y+i*16); await page.waitForTimeout(120); }
await page.mouse.up(); await page.waitForTimeout(90); // 되돌아오는 중
const during=await state(); const gy=during.top+10; await page.mouse.move(g.x,gy); await page.mouse.down();
const grabbed=await state(); console.log('E during', during.tf, '→ grabbed', grabbed.styleTf, 'dragging', grabbed.dragging);
for(let i=1;i<=10;i++){ await page.mouse.move(g.x, gy+i*45); await page.waitForTimeout(25); }
await page.mouse.up(); await page.waitForTimeout(600); const e=await state(); console.log('E after (끌어 내렸으니 닫혀야)', {sheet:e.sheet, styleTf:e.styleTf, dim:e.dim});
// F — 닫기 버튼 · 시트 안 버튼은 그대로 눌리는가
await openIt(); await page.click('[data-dock] [data-dock-close]'); await page.waitForTimeout(300); console.log('F close btn', (await state()).sheet);
await openIt(); const sw=await page.evaluate(()=>{ const c=document.querySelector('[data-dock]'); return { scrollW: document.documentElement.scrollWidth, cardW: c.getBoundingClientRect().width }; });
await page.screenshot({path:`${OUT}/open.png`});
console.log('width', sw, 'errors', errs, 'cartPosts', posts);
await b.close();
