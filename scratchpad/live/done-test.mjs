// ④ 담기 완료 막 · 유리 시트 시험 — LOCAL=1 이면 작업본 CSS/JS. 상품 페이지 HTML 에 「장바구니에 추가」 알림을 끼워
// 담기 직후 화면을 흉내 낸다 (서버에는 아무것도 안 보낸다 · add-to-cart POST 는 끊는다).
import { createRequire } from 'node:module'; import fs from 'node:fs'; import path from 'node:path';
const require = createRequire(import.meta.url); const { chromium } = require('playwright');
const S='https://duck-hoo.com', ROOT='/home/user/new-website-build';
const OUT=process.env.OUT||path.join(ROOT,'scratchpad/done-test'); fs.mkdirSync(OUT,{recursive:true});
const URL='/product/%EB%85%B8%EB%B3%B4-%ED%83%80%EB%B0%95%EB%A9%98%EC%86%94-9-8mg-30ml/';
const MSG='<div class="woocommerce-notices-wrapper"><div class="woocommerce-message" role="alert">“[노보] 타박멘솔 (9.8mg / 30ml)” 상품이 장바구니에 추가되었습니다. <a href="/cart/" class="button wc-forward">장바구니 보기</a></div></div>';
const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium',args:['--no-sandbox']});
const out=[]; const log=(k,v)=>{ out.push([k,v]); console.log(k, JSON.stringify(v)); };
async function ctxFor(width, dark, mobile){
  const UA = mobile ? 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1' : 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36';
  const ctx=await b.newContext({viewport:{width,height:mobile?844:900},userAgent:UA,deviceScaleFactor:mobile?2:1,hasTouch:mobile});
  await ctx.addInitScript((d)=>{ try{ if(d) localStorage.setItem('dhr-theme','dark'); else localStorage.removeItem('dhr-theme'); }catch(e){} }, dark);
  const page=await ctx.newPage(); const errs=[]; page.on('pageerror',e=>errs.push(String(e.message)));
  await page.route('**/*', async route=>{ const u=route.request().url(); if(!/^https?:/.test(u)) return route.abort();
    if(route.request().method()==='POST' && /add-to-cart|add_to_cart|cart\/add-item/.test(u)) return route.abort();
    const lm=process.env.LOCAL==='1' && u.match(/\/plugins\/new-website-build\/assets\/([a-z-]+\.(?:css|js))/);
    if(lm){ return route.fulfill({status:200,headers:{'content-type':lm[1].endsWith('.css')?'text/css':'application/javascript'},body:fs.readFileSync(path.join(ROOT,'assets',lm[1]))}); }
    try{ const r=await fetch(u,{headers:{'user-agent':UA},redirect:'manual'}); const h={}; r.headers.forEach((v,k)=>{ if(!/^(content-encoding|transfer-encoding|content-length|set-cookie)$/i.test(k)) h[k]=v; });
      let body=Buffer.from(await r.arrayBuffer());
      if(process.env.MSG!=='0' && /text\/html/.test(h['content-type']||'') && u.includes('/product/')){ body=Buffer.from(body.toString('utf8').replace(/<body([^>]*)>/, (m)=>m+MSG)); }
      route.fulfill({status:r.status,headers:h,body}); }catch(e){ route.abort(); } });
  return {ctx,page,errs};
}
for(const [width,dark,mobile] of [[390,false,true],[390,true,true],[1150,true,false]]){
  const tag=`${width}-${dark?'dark':'light'}`;
  const {ctx,page,errs}=await ctxFor(width,dark,mobile);
  await page.goto(S+URL+'?nocache='+Date.now(),{waitUntil:'load',timeout:90000});
  await page.evaluate(()=>{ document.querySelectorAll('#pop-dim,#pop6,#dh-agegate2').forEach(e=>e.remove()); document.documentElement.classList.remove('dh-ag2-lock'); document.body.classList.remove('dh-ag2-lock'); });
  await page.waitForTimeout(700);
  // 막
  const v1=await page.evaluate(()=>{ const d=document.querySelector('.dhd'); if(!d) return null; const c=getComputedStyle(d); const k=d.querySelector('.dhd__k'); const kc=getComputedStyle(k);
    return {hidden:d.hidden,on:d.classList.contains('on'),opacity:c.opacity,blur:c.backdropFilter||c.webkitBackdropFilter,kbg:kc.backgroundColor,kradius:kc.borderRadius,title:d.querySelector('.dhd__t').textContent,sub:d.querySelector('.dhd__s').textContent,btn:d.querySelector('.dhd__btn').textContent,msgHidden:getComputedStyle(document.querySelector('.woocommerce-message')).display, focus:document.activeElement&&document.activeElement.className}; });
  log(tag+' veil', v1);
  await page.screenshot({path:`${OUT}/veil-${tag}.png`});
  // 자동 닫힘 (2.2초)
  await page.waitForTimeout(2400);
  log(tag+' veil after 3s', await page.evaluate(()=>{ const d=document.querySelector('.dhd'); return {hidden:d.hidden,on:d.classList.contains('on'),bodyOpen:document.body.classList.contains('dhd-open')}; }));
  // 다시 띄워 「장바구니 보기」 → 서랍
  await page.evaluate(()=>window.DHR.done('cart',{sub:'시험 · 1개', onGo:function(){ window.DHR.openCart && window.DHR.openCart(null); }}));
  await page.waitForTimeout(400); await page.click('.dhd__btn'); await page.waitForTimeout(600);
  log(tag+' go→drawer', await page.evaluate(()=>{ const r=document.querySelector('[data-cart-drawer]'); return {veilHidden:document.querySelector('.dhd').hidden, drawerOn:r&&r.classList.contains('on')}; }));
  await page.evaluate(()=>{ const x=document.querySelector('[data-cart-close]'); if(x) x.click(); }); await page.waitForTimeout(400);
  // 안을 만지면 자동 닫힘이 멈추는지
  await page.evaluate(()=>window.DHR.done('cart',{sub:'잡고 있기'})); await page.waitForTimeout(300);
  await page.mouse.move(width/2, 420); await page.mouse.down(); await page.mouse.up(); await page.waitForTimeout(2500);
  log(tag+' held open', await page.evaluate(()=>!document.querySelector('.dhd').hidden));
  await page.keyboard.press('Escape'); await page.waitForTimeout(300);
  log(tag+' esc closes', await page.evaluate(()=>document.querySelector('.dhd').hidden));
  if(mobile){
    // 시트 — 유리 · 28px · 닫힘 220ms
    await page.evaluate(()=>window.scrollTo(0,1800)); await page.waitForTimeout(500);
    await page.evaluate(()=>window.DHR.openBuySheet()); await page.waitForTimeout(500);
    log(tag+' sheet', await page.evaluate(()=>{ const c=document.querySelector('[data-dock]'); const s=getComputedStyle(c); const h=getComputedStyle(c.querySelector('.dhp-dock__head')); const d=getComputedStyle(document.querySelector('[data-sheet-dim]'));
      return {isSheet:c.classList.contains('is-sheet'),radius:s.borderTopLeftRadius,bg:s.backgroundColor,blur:s.backdropFilter||s.webkitBackdropFilter,headBg:h.backgroundColor,dimBlur:d.backdropFilter||d.webkitBackdropFilter,dimBg:d.backgroundColor, overflow: document.documentElement.scrollWidth}; }));
    await page.screenshot({path:`${OUT}/sheet-${tag}.png`});
    const t0=Date.now(); await page.click('[data-dock-close]');
    const mid=await page.evaluate(()=>{ const c=document.querySelector('[data-dock]'); return {isSheet:c.classList.contains('is-sheet'), tr:c.style.transform, trans:c.style.transition}; });
    await page.waitForFunction(()=>!document.querySelector('[data-dock]').classList.contains('is-sheet'),{timeout:2000}); const ms=Date.now()-t0;
    log(tag+' sheet close', {mid, ms, styleLeft: await page.evaluate(()=>document.querySelector('[data-dock]').getAttribute('style'))});
    // 닫히는 중 다시 열기
    await page.evaluate(()=>window.DHR.openBuySheet()); await page.waitForTimeout(400); await page.evaluate(()=>{ document.querySelector('[data-dock-close]').click(); });
    await page.waitForTimeout(80); await page.evaluate(()=>window.DHR.openBuySheet()); await page.waitForTimeout(500);
    log(tag+' reopen mid-close', await page.evaluate(()=>{ const c=document.querySelector('[data-dock]'); const m=getComputedStyle(c).transform; return {isSheet:c.classList.contains('is-sheet'), transform:m, style:c.getAttribute('style')}; }));
    await page.evaluate(()=>{ document.querySelector('[data-dock-close]').click(); }); await page.waitForTimeout(400);
    // 아래 구매 줄
    await page.evaluate(()=>window.scrollTo(0,2600)); await page.waitForTimeout(600);
    log(tag+' bar', await page.evaluate(()=>{ const b=document.querySelector('.dhp-bar'); const s=getComputedStyle(b); return {away:b.classList.contains('is-away'),radius:s.borderTopLeftRadius,bg:s.backgroundColor,blur:s.backdropFilter||s.webkitBackdropFilter}; }));
    await page.screenshot({path:`${OUT}/bar-${tag}.png`});
  }else{
    await page.evaluate(()=>window.scrollTo(0,1600)); await page.waitForTimeout(1200);
    log(tag+' dock', await page.evaluate(()=>{ const c=document.querySelector('[data-dock]'); const s=getComputedStyle(c); return {docked:c.classList.contains('is-docked'),radius:s.borderTopLeftRadius,bg:s.backgroundColor,blur:s.backdropFilter||s.webkitBackdropFilter}; }));
    await page.screenshot({path:`${OUT}/dock-${tag}.png`});
  }
  log(tag+' errors', errs.filter(e=>/front\.js|dhd|dhp/.test(e)).concat(errs.length?['(all:'+errs.length+')']:[]));
  await ctx.close();
}
fs.writeFileSync(path.join(OUT,'result.json'), JSON.stringify(out,null,1));
await b.close();
