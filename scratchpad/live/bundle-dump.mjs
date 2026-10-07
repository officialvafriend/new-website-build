// 묶음 상품의 구매 폼 구조를 통째로 찍는다 — PPOM select · .dhx 카드 · 테마 옵션 빌더 · 숨은 json · 필요 병 수 문구
import { createRequire } from 'node:module'; import fs from 'node:fs'; import path from 'node:path';
const require = createRequire(path.join(process.cwd(),'scratchpad/live/node_modules','x.js')); const { chromium } = require('playwright');
const UA='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36';
const S='https://duck-hoo.com'; const url=process.argv[2]||'/product/%EB%94%94%EC%98%A4%EB%A6%AC%ED%80%B4%EB%93%9C-55-%EB%AC%B6%EC%9D%8C-%EC%9D%B4%EB%B2%A4%ED%8A%B8/';
const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] });
const ctx = await b.newContext({ viewport:{width:390,height:844}, userAgent: UA, isMobile:true });
const page = await ctx.newPage();
await page.route('**/*', async (route) => { const req=route.request(); const u=req.url(); if(!/^https?:/.test(u)) return route.abort();
  try { const r = await fetch(u, { method: req.method(), headers: { 'user-agent': UA, accept: '*/*' }, body: req.postData() ?? undefined, redirect: 'manual' });
    const headers = {}; r.headers.forEach((v,k)=>{ if(!/^(content-encoding|transfer-encoding|content-length|set-cookie)$/i.test(k)) headers[k]=v; });
    route.fulfill({ status: r.status, headers, body: Buffer.from(await r.arrayBuffer()) }); } catch (e) { route.abort(); } });
const r = await page.goto(S+url, { waitUntil:'load', timeout:90000 }); console.log('status', r.status(), await page.title());
await page.waitForTimeout(2500);
const d = await page.evaluate(()=>{
  const form=document.querySelector('form.cart'); if(!form) return {noform:true};
  const sels=[...form.querySelectorAll('select')].map(s=>({name:s.name, id:s.id, cls:s.className, opts:[...s.options].map(o=>[o.value,o.textContent.trim(),o.dataset.price]).slice(0,40)}));
  const dhx=[...form.querySelectorAll('.dhx-card')].map(c=>({title:(c.querySelector('.dhx-card__title,.dhx-card__head,h3,h4')||{}).textContent?.trim().slice(0,60), rows:[...c.querySelectorAll('.dhx-bundle__row,.dhx-row')].length, html:c.outerHTML.slice(0,900)}));
  const builder=form.querySelector('.wd-option-builder'); const hidden=[...form.querySelectorAll('input[type=hidden]')].map(i=>[i.name,(i.value||'').slice(0,120)]);
  const btns=[...form.querySelectorAll('button')].map(b=>[b.className.slice(0,60),b.textContent.trim().slice(0,30),b.disabled]);
  const txt=form.innerText.replace(/\s+/g,' ').slice(0,1500);
  const need=(document.body.innerText.match(/맛[^\n]{0,20}총\s*\d+\s*개[^\n]{0,30}/g)||[]).slice(0,5);
  return { sels, dhxN:dhx.length, dhx, builderHtml: builder?builder.outerHTML.slice(0,1500):null, hidden, btns, txt, need, scripts:[...document.scripts].map(s=>s.src).filter(s=>/option|bundle|dhx|vf|snippet/i.test(s)) };
});
fs.writeFileSync('scratchpad/bundle-dump.json', JSON.stringify(d,null,1)); console.log(JSON.stringify(d).slice(0,3000));
await b.close();
