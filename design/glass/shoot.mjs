// 유리 시안 캡처: DHR_NODE_MODULES=<playwright 가 있는 node_modules> DHR_OUT=<폴더> [DHR_ACCENT=prism] [DHR_PAGES=index,shop] node design/glass/shoot.mjs
// 유리 시안 스크린샷: 라이트/다크 × 390/1280, 홈 중간 스크롤, 시트 · 완료 막
import { createRequire } from 'node:module';
const require = createRequire(process.env.DHR_NODE_MODULES ? process.env.DHR_NODE_MODULES + '/x.js' : import.meta.url);
const { chromium } = require('playwright');
const out = process.env.DHR_OUT || '/tmp/claude-0/-home-user-new-website-build/eaa69852-6737-54fa-a860-4a2fc73b9c20/scratchpad/glass';
const base = 'file://' + new URL('.', import.meta.url).pathname;
const br = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] });
const pages = (process.env.DHR_PAGES || 'index,shop,product,cart,account,login').split(',');
const res = [];
for (const theme of ['light','dark']) for (const [w,h,tag] of [[390,844,'m'],[1280,900,'d']]) {
  const ctx = await br.newContext({ viewport:{width:w,height:h}, deviceScaleFactor:1, colorScheme: theme, isMobile: w<880, hasTouch: w<880 });
  for (const p of pages) {
    const pg = await ctx.newPage();
    if (process.env.DHR_ACCENT) await pg.addInitScript(a => { try { localStorage.setItem('dhr-accent', a); } catch (e) {} document.addEventListener('DOMContentLoaded', () => { document.documentElement.dataset.accent = a; }); }, process.env.DHR_ACCENT);
    await pg.goto(base + p + '.html'); await pg.waitForTimeout(500);
    // 전체 페이지: 등장 애니메이션이 스크롤 타임라인이라 full page 캡처에서는 아래가 투명할 수 있어, 먼저 끝까지 훑는다
    const H = await pg.evaluate(() => document.documentElement.scrollHeight);
    for (let y=0; y<H; y+=600) { await pg.evaluate(v=>window.scrollTo(0,v), y); await pg.waitForTimeout(60); }
    await pg.evaluate(()=>window.scrollTo(0,0)); await pg.waitForTimeout(300);
    await pg.evaluate(()=>document.documentElement.classList.add('static')); await pg.waitForTimeout(150);
    const over = await pg.evaluate(()=>{ window.scrollTo(600,0); const x=window.scrollX; window.scrollTo(0,0); return x; });
    await pg.screenshot({ path:`${out}/${p}-${theme}-${tag}.png`, fullPage:true });
    res.push([p,theme,tag,H,over]);
    await pg.evaluate(()=>document.documentElement.classList.remove('static')); await pg.waitForTimeout(150);
    if (p==='index') { // 중간 스크롤 뷰포트 두 장: 히어로가 줄어드는 자리 · 브랜드 스택
      await pg.evaluate(()=>window.scrollTo(0, 320)); await pg.waitForTimeout(250); await pg.screenshot({ path:`${out}/index-${theme}-${tag}-s1.png` });
      const y = await pg.evaluate(()=>document.querySelector('.stack').getBoundingClientRect().top + window.scrollY + (innerWidth<880?520:560));
      await pg.evaluate(v=>window.scrollTo(0,v), y); await pg.waitForTimeout(250); await pg.screenshot({ path:`${out}/index-${theme}-${tag}-s2.png` });
    }
    if (p==='product') {
      await pg.click('[data-open-sheet]'); await pg.waitForTimeout(500); await pg.screenshot({ path:`${out}/product-${theme}-${tag}-sheet.png` });
      await pg.click('.sheet [data-done="order"]'); await pg.waitForTimeout(700); await pg.screenshot({ path:`${out}/product-${theme}-${tag}-order.png` });
      await pg.keyboard.press('Escape'); await pg.waitForTimeout(300);
      await pg.click('.buy [data-done="cart"]'); await pg.waitForTimeout(600); await pg.screenshot({ path:`${out}/product-${theme}-${tag}-cart.png` });
    }
    await pg.close();
  }
  await ctx.close();
}
await br.close();
console.table(res);
