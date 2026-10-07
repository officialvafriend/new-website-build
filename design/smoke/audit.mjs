// 화면 점검 — 아홉 화면 × 390/1280 × 라이트/다크: HTTP · 가로 넘침 · 끊긴 HTML(푸터 없음) · JS 오류 · 글자 대비(2.2:1 아래) · 전체 스크린샷.
// 쓰는 법: NODE_USE_ENV_PROXY=1 NODE_EXTRA_CA_CERTS=/root/.ccr/ca-bundle.crt node design/smoke/audit.mjs   (LOCAL=1 이면 assets/*.css|js 를 작업본으로 바꿔 끼운다 · ONLY=home,shop 로 좁힌다 · OUT=폴더) · **루프 변수를 path 로 두면 node:path 가 가려져 LOCAL 이 조용히 꺼진다** (2026-10-07 에 잡음)
import { createRequire } from 'node:module'; import fs from 'node:fs'; import path from 'node:path';
const NM = process.env.DHR_NODE_MODULES || '/tmp/claude-0/-home-user-new-website-build/eaa69852-6737-54fa-a860-4a2fc73b9c20/scratchpad/live/node_modules';
const require = createRequire(path.join(NM, 'x.js')); const { chromium } = require('playwright');
const UA='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36';
const OUT=process.env.OUT||'/tmp/dhr-audit'; fs.mkdirSync(OUT,{recursive:true}); const S='https://duck-hoo.com';
const ONLY=process.env.ONLY?process.env.ONLY.split(','):null; const LOCAL=process.env.LOCAL==='1';
const pages0=[['home','/'],['shop','/shop/'],['novo','/product-category/novo-liquid/'],['prod','/product/%EB%85%B8%EB%B3%B4-%ED%83%80%EB%B0%95%EB%A9%98%EC%86%94-9-8mg-30ml/'],['cart','/cart/'],['login','/login/'],['reg1','/register/'],['reg2','/agree/'],['reg3','/join-form/']];
const pages=ONLY?pages0.filter(p=>ONLY.includes(p[0])):pages0;
const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] });
const out=[];
for (const [w,h,tag] of [[390,844,'m'],[1280,900,'d']]) for (const theme of ['light','dark']) for (const [k,pth] of pages) {
  const ctx = await b.newContext({ viewport:{width:w,height:h}, userAgent: UA, isMobile: w<880, deviceScaleFactor:1 });
  const page = await ctx.newPage();
  if (theme==='dark') await page.addInitScript(()=>{ try{ localStorage.setItem('dhr-theme','dark'); }catch(e){} });
  const errs=[]; page.on('pageerror', e=>errs.push(e.message)); page.on('console', m=>{ if(m.type()==='error') errs.push('[console] '+m.text().slice(0,140)); });
  await page.route('**/*', async (route) => { const req=route.request(); const url=req.url(); if(!/^https?:/.test(url)) return route.abort();
    const lm=LOCAL && url.match(/\/plugins\/new-website-build\/assets\/([a-z-]+\.(?:css|js))/); if(lm){ try { return route.fulfill({ status:200, headers:{'content-type': lm[1].endsWith('.css')?'text/css':'application/javascript'}, body: fs.readFileSync(path.join(process.cwd(),'assets',lm[1])) }); } catch(e){} }
    try { const r = await fetch(url, { method: req.method(), headers: { 'user-agent': UA, accept: '*/*' }, body: req.postData() ?? undefined, redirect: 'manual' });
      const headers = {}; r.headers.forEach((v,kk)=>{ if(!/^(content-encoding|transfer-encoding|content-length|set-cookie)$/i.test(kk)) headers[kk]=v; });
      route.fulfill({ status: r.status, headers, body: Buffer.from(await r.arrayBuffer()) }); } catch (e) { route.abort(); } });
  let status=0; try { const r=await page.goto(S+pth+(pth.includes('?')?'&':'?')+'nocache='+Date.now(), { waitUntil:'load', timeout:90000 }); status=r?.status()||0; } catch(e){ errs.push('goto '+e.message.slice(0,80)); }
  await page.evaluate(() => { document.querySelectorAll('#pop-dim,#pop6,#dh-agegate2').forEach(e=>e.remove()); document.documentElement.classList.remove('dh-ag2-lock'); document.body.classList.remove('dh-ag2-lock'); });
  await page.waitForTimeout(1200);
  const H = await page.evaluate(()=>document.body.scrollHeight);
  for (let y=0; y<Math.min(H,9000); y+=h*0.7) { await page.evaluate(v=>window.scrollTo(0,v), y); await page.waitForTimeout(120); }
  await page.evaluate(()=>window.scrollTo(0,0)); await page.waitForTimeout(900);
  const m = await page.evaluate(()=>{
    const q=s=>document.querySelector(s);
    window.scrollTo(600,0); const over=window.scrollX; window.scrollTo(0,0);
    const whole = !!document.querySelector('footer, .foot');
    // 대비 검사: 보이는 글자 요소의 색 vs 배경
    const toRgb=s=>{ const m=s.match(/rgba?\(([^)]+)\)/); if(!m) return null; const p=m[1].split(',').map(Number); return {r:p[0],g:p[1],b:p[2],a:p.length>3?p[3]:1}; };
    const lum=c=>{ const f=v=>{v/=255; return v<=.03928? v/12.92 : Math.pow((v+.055)/1.055,2.4)}; return .2126*f(c.r)+.7152*f(c.g)+.0722*f(c.b); };
    const bgOf=el=>{ let e=el; while(e && e!==document.documentElement){ const bg=toRgb(getComputedStyle(e).backgroundColor); if(bg && bg.a>0.6) return bg; e=e.parentElement; } const r=toRgb(getComputedStyle(document.body).backgroundColor); return (r&&r.a>0)?r:{r:255,g:255,b:255,a:1}; };
    const low=[]; const seen=new Set();
    const walker=document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    let n; let count=0;
    while((n=walker.nextNode()) && count<4000){ const t=n.textContent.trim(); if(t.length<2) continue; const el=n.parentElement; if(!el||seen.has(el)) continue; seen.add(el); count++;
      const cs=getComputedStyle(el); if(cs.display==='none'||cs.visibility==='hidden'||parseFloat(cs.opacity)===0||parseFloat(cs.fontSize)<1) continue;
      const rect=el.getBoundingClientRect(); if(rect.width<2||rect.height<2) continue; if(rect.bottom<0) continue;
      // 화면 밖으로 치운 요소(클립) 건너뜀
      if(cs.position==='absolute' && (parseInt(cs.width)<=1 || cs.clip!=='auto')) continue;
      const fg=toRgb(cs.color); if(!fg) continue; const bg=bgOf(el);
      const L1=lum(fg), L2=lum(bg); const cr=(Math.max(L1,L2)+.05)/(Math.min(L1,L2)+.05);
      if(cr<2.2) low.push({t:t.slice(0,28), cls:(el.className&&typeof el.className==='string'?el.className.slice(0,40):el.tagName), fg:cs.color, bg:`rgb(${bg.r},${bg.g},${bg.b})`, cr:+cr.toFixed(2)});
    }
    return { theme: document.documentElement.getAttribute('data-theme'), over, whole, title: document.title.slice(0,50), low: low.slice(0,12), lowN: low.length, bytes: document.documentElement.outerHTML.length };
  });
  await page.screenshot({ path:`${OUT}/${k}-${theme}-${tag}.png`, fullPage:true });
  out.push({ k, tag, theme, status, ...m, errs: errs.filter(e=>!/n\[e\] is not a function|woocommerce-analytics|favicon|googletagmanager|facebook|net::ERR_ABORTED/.test(e)).slice(0,4) });
  await ctx.close();
}
await b.close();
let bad=0;
for (const r of out) { const errs=r.errs.filter(e=>!/CERT_AUTHORITY/.test(e)); const flag=[]; if(r.status!==200) flag.push('HTTP '+r.status); if(r.over) flag.push('넘침 '+r.over); if(!r.whole) flag.push('끊김'); if(errs.length) flag.push('JS오류 '+errs.length); if(r.lowN) flag.push('저대비 '+r.lowN);
  if(flag.length) bad++; console.log((flag.length?'❌':'✅')+' '+r.k.padEnd(6)+r.tag+' '+(r.theme||'light').padEnd(5)+' '+(flag.join(' · ')||'OK')); for(const e of errs) console.log('     ERR '+e.slice(0,120)); for(const x of r.low.slice(0,4)) console.log('     LOW '+JSON.stringify(x)); }
console.log(bad?`\n❌ ${bad}개 화면에 걸림 → ${OUT}`:`\n✅ 모두 통과 → ${OUT}`); process.exit(bad?1:0);
