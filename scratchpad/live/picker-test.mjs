// 맛 고르기 타일 — LOCAL=1 작업본으로 디오 5+5 에서: 그려지는지 · 칩 카드 숨김 · + / 타일 탭 / 골고루 / 비우기 → json · 게이트 버튼. 담기는 절대 안 한다.
import { createRequire } from 'node:module'; import fs from 'node:fs'; import path from 'node:path';
const require = createRequire(path.join(process.cwd(),'scratchpad/live/node_modules','x.js')); const { chromium } = require('playwright');
const UA='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36';
const S='https://duck-hoo.com'; const LOCAL=process.env.LOCAL==='1'; const OUT=process.env.OUT||'scratchpad/picker'; fs.mkdirSync(OUT,{recursive:true});
const url=process.argv[2]||'/product/%EB%94%94%EC%98%A4%EB%A6%AC%ED%80%B4%EB%93%9C-55-%EB%AC%B6%EC%9D%8C-%EC%9D%B4%EB%B2%A4%ED%8A%B8/';
const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] });
for (const [w,h,tag] of [[390,844,'m'],[1280,900,'d']]) {
  const ctx = await b.newContext({ viewport:{width:w,height:h}, userAgent: UA, isMobile: w<880 }); const page = await ctx.newPage(); const errs=[]; page.on('pageerror',e=>errs.push(e.message));
  await page.route('**/*', async (route) => { const req=route.request(); const u=req.url(); if(!/^https?:/.test(u)) return route.abort();
    if(/wc\/store\/v1\/cart\/add-item|wc-ajax=add_to_cart|add-to-cart=/.test(u) && req.method()==='POST') return route.abort();
    const lm=LOCAL && u.match(/\/plugins\/new-website-build\/assets\/([a-z-]+\.(?:css|js))/); if(lm){ try { return route.fulfill({ status:200, headers:{'content-type': lm[1].endsWith('.css')?'text/css':'application/javascript'}, body: fs.readFileSync(path.join(process.cwd(),'assets',lm[1])) }); } catch(e){} }
    try { const r = await fetch(u, { method: req.method(), headers: { 'user-agent': UA, accept: '*/*' }, body: req.postData() ?? undefined, redirect: 'manual' });
      const headers = {}; r.headers.forEach((v,k)=>{ if(!/^(content-encoding|transfer-encoding|content-length|set-cookie)$/i.test(k)) headers[k]=v; });
      route.fulfill({ status: r.status, headers, body: Buffer.from(await r.arrayBuffer()) }); } catch (e) { route.abort(); } });
  await page.goto(S+url+'?nocache='+Date.now(), { waitUntil:'load', timeout:90000 }); await page.waitForTimeout(2500);
  await page.evaluate(()=>{ document.querySelectorAll('#dh-agegate2,#pop-dim,#pop6').forEach(e=>e.remove()); document.documentElement.classList.remove('dh-ag2-lock'); document.body.classList.remove('dh-ag2-lock'); });
  const st = () => page.evaluate(()=>{ const q=s=>document.querySelector(s); const btn=q('.single_add_to_cart_button'); const pk=q('.dhpk'); const r=e=>{ if(!e) return null; const b=e.getBoundingClientRect(); return [Math.round(b.x),Math.round(b.y),Math.round(b.width),Math.round(b.height)]; };
    return { pk: !!pk, pkRect: r(pk), chipsHidden: q('.dhx-card.dhpk-src') ? getComputedStyle(q('.dhx-card.dhpk-src')).display : 'none-card', tiles: [...document.querySelectorAll('.dhpk__tile')].map(t=>[t.dataset.name, t.querySelector('.dhpk__n').textContent, t.classList.contains('is-on')]), cnt: q('.dhpk__cnt')?.textContent, hint: q('.dhpk__hint')?.textContent, btn: btn && [btn.textContent.trim(), btn.disabled], json: (q('input[name=wd_option_builder_json]')||{}).value?.slice(0,300), inForm: !!q('form.cart .dhpk'), buttonsInForm: [...document.querySelectorAll('form.cart .dhpk button')].length, over:(window.scrollTo(600,0),window.scrollX) }; });
  const s0 = await st(); console.log(tag,'0', JSON.stringify(s0));
  if(!s0.pk){ await ctx.close(); continue; }
  await page.evaluate(()=>document.querySelector('.dhpk').scrollIntoView({block:'center'})); await page.waitForTimeout(400);
  await page.evaluate(()=>document.querySelector('.dhpk').scrollIntoView({block:'center'})); await page.waitForTimeout(300); await page.screenshot({ path:`${OUT}/pk0-${tag}.png` });
  // 타일 탭 두 번 (잠김 → 자동으로 구성 선택)
  await page.click('.dhpk__tile:nth-child(1) .dhpk__name'); await page.click('.dhpk__tile:nth-child(1) .dhpk__name'); await page.waitForTimeout(500);
  console.log(tag,'1 tap×2', JSON.stringify((await st())).slice(0,420));
  // + 세 번 (pointer)
  for(let i=0;i<3;i++){ await page.click('.dhpk__tile:nth-child(2) .dhpk__b--p'); } await page.waitForTimeout(500);
  // 골고루
  await page.click('.dhpk__chip:not(.dhpk__chip--ghost)'); await page.waitForTimeout(800);
  const s2 = await st(); console.log(tag,'2 +3 · 골고루', JSON.stringify(s2).slice(0,700));
  await page.evaluate(()=>document.querySelector('.dhpk').scrollIntoView({block:'center'})); await page.waitForTimeout(300); await page.screenshot({ path:`${OUT}/pk1-${tag}.png` });
  // 꽉 찼을 때 + 는 막힘
  await page.click('.dhpk__tile:nth-child(3) .dhpk__b--p'); await page.waitForTimeout(400); const s3=await st(); console.log(tag,'3 over+', s3.cnt, s3.btn);
  // − 하나 → 다시 1병 부족
  await page.click('.dhpk__tile:nth-child(1) .dhpk__b--m'); await page.waitForTimeout(400); const s4=await st(); console.log(tag,'4 −1', s4.cnt, s4.btn, s4.hint);
  // 비우기
  await page.click('.dhpk__chip--ghost'); await page.waitForTimeout(600); const s5=await st(); console.log(tag,'5 clear', s5.cnt, s5.btn, s5.json);
  console.log(tag,'errs', JSON.stringify(errs.filter(e=>!/CERT|n\[e\]/.test(e))));
  await ctx.close();
}
await b.close();
