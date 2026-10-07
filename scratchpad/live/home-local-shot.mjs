// home-render.html(스텁 렌더)을 작업본 CSS·JS 로 열어 안내 벤토 · 타일 간격을 찍는다 (PHP 템플릿은 LOCAL 미리보기가 안 되므로)
import { createRequire } from 'node:module'; import fs from 'node:fs'; import path from 'node:path';
const require = createRequire(import.meta.url); const { chromium } = require('playwright');
const ROOT='/home/user/new-website-build'; const OUT=process.env.OUT||'../audit-s3-local';
let html=fs.readFileSync(path.join(ROOT,'scratchpad/home-render.html'),'utf8');
// 스텁 렌더에는 wp_head 가 없다 — charset · 토큰 · front.css · GSAP · front.js 를 우리가 끼운다. body 에 dhr 클래스가 없으면 붙인다
html=html.replace('<meta charset="">','<meta charset="utf-8">').replace('</head>','<link rel="stylesheet" href="/wp-content/plugins/new-website-build/assets/tokens.css"><link rel="stylesheet" href="/wp-content/plugins/new-website-build/assets/front.css"></head>')
  .replace(/<body\s*>/,'<body class="dhr home">').replace('</body>','<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script><script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/ScrollTrigger.min.js"></script><script>window.DHR={};</script><script src="/wp-content/plugins/new-website-build/assets/front.js"></script></body>');
// 자산 경로를 로컬로. 외부 스크립트(GSAP 등)는 그대로 받는다
const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium',args:['--no-sandbox']});
for (const theme of ['light','dark']) for (const [w,h,tag] of [[390,844,'m'],[1280,900,'d']]) {
  const ctx=await b.newContext({viewport:{width:w,height:h},isMobile:w<880}); const page=await ctx.newPage();
  if(theme==='dark') await page.addInitScript(()=>{ document.addEventListener('readystatechange',()=>{ if(document.documentElement) document.documentElement.setAttribute('data-theme','dark'); }); try{ localStorage.setItem('dhr-theme','dark'); }catch(e){} });
  await page.route('**/*', async route=>{ const u=route.request().url();
    const lm=u.match(/\/plugins\/new-website-build\/assets\/([a-z-]+\.(?:css|js))/); if(lm){ return route.fulfill({status:200,headers:{'content-type':lm[1].endsWith('.css')?'text/css':'application/javascript'},body:fs.readFileSync(path.join(ROOT,'assets',lm[1]))}); }
    if(u.startsWith('http://local.test/')) return route.fulfill({status:200,contentType:'text/html',body:html});
    try{ const r=await fetch(u,{redirect:'manual'}); const hd={}; r.headers.forEach((v,k)=>{ if(!/^(content-encoding|transfer-encoding|content-length|set-cookie)$/i.test(k)) hd[k]=v; }); route.fulfill({status:r.status,headers:hd,body:Buffer.from(await r.arrayBuffer())}); }catch(e){ route.abort(); } });
  await page.goto('http://local.test/',{waitUntil:'load',timeout:60000}); await page.waitForTimeout(800);
  if(theme==='dark') await page.evaluate(()=>document.documentElement.setAttribute('data-theme','dark'));
  await page.evaluate(()=>document.querySelectorAll('#pop-dim,#pop6').forEach(e=>e.remove()));
  const H=await page.evaluate(()=>document.body.scrollHeight); for(let y=0;y<H;y+=600){ await page.evaluate(v=>window.scrollTo(0,v),y); await page.waitForTimeout(80); }
  await page.waitForTimeout(1500);
  const ab=await page.$('.dhr-about'); if(ab){ await ab.scrollIntoViewIfNeeded(); await page.waitForTimeout(1200); await ab.screenshot({path:`${OUT}/about-${theme}-${tag}.png`}); }
  const q=await page.$('.qcats'); if(q){ const m=await page.evaluate(()=>{ const r=s=>document.querySelector(s)?.getBoundingClientRect(); const d=r('.hero__dots'),qc=r('.qcats'),g=r('.qcats + .dhr-gate'),n=r('.nums'); return {dotsToTiles:qc.top-d.bottom, tilesToGate:g.top-qc.bottom, gateToNums:n.top-g.bottom}; }); console.log(theme,tag,JSON.stringify(m));
    await page.evaluate(()=>window.scrollTo(0, document.querySelector('.hero__dots').getBoundingClientRect().top+window.scrollY-20)); await page.waitForTimeout(600); await page.screenshot({path:`${OUT}/tiles-${theme}-${tag}.png`}); }
  const over=await page.evaluate(()=>{ window.scrollTo(600,0); const x=window.scrollX; window.scrollTo(0,0); return x; }); console.log(theme,tag,'overflow',over);
  await ctx.close(); }
await b.close();
