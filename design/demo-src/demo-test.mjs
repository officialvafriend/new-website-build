import { chromium } from 'playwright';
import fs from 'fs';
const HARD = setTimeout(() => { console.log('\n⏱ 하드 타임아웃'); process.exit(2); }, 150000); HARD.unref?.();

/* 사용법: npm i playwright gsap 한 뒤
   SP=<gsap/playwright 가 설치된 폴더> node design/demo-src/demo-test.mjs */
import path from 'path';
const FILE = 'file://' + path.resolve('design/demo.html');
const SP = process.env.SP || process.cwd();
const GSAP = fs.readFileSync(SP + '/node_modules/gsap/dist/gsap.min.js', 'utf8');
const STR = fs.readFileSync(SP + '/node_modules/gsap/dist/ScrollTrigger.min.js', 'utf8');
const errs = [];
const note = s => console.log('· ' + s);

const b = await chromium.launch({
  executablePath: '/opt/pw-browsers/chromium',
  args: ['--disable-background-networking', '--no-first-run', '--no-default-browser-check',
    '--disable-component-update', '--disable-sync', '--disable-domain-reliability',
    '--disable-client-side-phishing-detection', '--metrics-recording-only','--disable-dev-shm-usage','--disable-gpu'],
});

async function mk(width, height) {
  const ctx = await b.newContext({ viewport: { width, height }, deviceScaleFactor: 1, hasTouch: width < 800 });
  ctx.setDefaultTimeout(4000);
  await ctx.route('**/*', r => {
    const u = r.request().url();
    if (u.startsWith('file:')) return r.continue();
    if (/ScrollTrigger\.min\.js/.test(u)) return r.fulfill({ contentType: 'application/javascript', body: STR });
    if (/gsap\.min\.js/.test(u)) return r.fulfill({ contentType: 'application/javascript', body: GSAP });
    return r.fulfill({ status: 204, body: '' });
  });
  const p = await ctx.newPage();
  p.on('pageerror', e => errs.push(`[${width}] pageerror: ${e.message}`));
  p.on('console', m => { if (m.type() === 'error') errs.push(`[${width}] console: ${m.text()}`); });
  await p.goto(FILE, { waitUntil: 'load' });
  await p.waitForTimeout(500);
  return p;
}

const go = async (p, h) => { await p.evaluate(x => { location.hash = x; }, h); await p.waitForTimeout(400); };
const routes = ['#/', '#/shop', '#/shop?f=멘솔', '#/p/12', '#/p/4', '#/cart', '#/login', '#/join', '#/orders', '#/order/10481', '#/my', '#/zzz'];

for (const w of [390, 768, 1280]) {
  const p = await mk(w, 900);
  for (const r of routes) {
    await go(p, r);
    const m = await p.evaluate(() => {
      window.scrollTo(9999, 0); const x = window.scrollX; window.scrollTo(0, 0);
      return { sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth, x,
               len: document.querySelector('#view').innerHTML.trim().length };
    });
    if (m.sw > m.cw + 1 || m.x > 0) errs.push(`[${w}] 가로 넘침 @${r}: sw=${m.sw} cw=${m.cw} scrollX=${m.x}`);
    if (m.len < 80) errs.push(`[${w}] 빈 화면 @${r}`);
  }
  note(`${w}px 라우트 ${routes.length}개 확인`);
  await p.close();
}

// ── 구매 흐름 (390px) ──
const p = await mk(390, 844);
const step = async (label, fn) => { try { await fn(); await p.waitForTimeout(320); } catch (e) { errs.push(`흐름[${label}]: ${e.message.split('\n')[0]}`); } };

await step('로그인 화면', () => go(p, '#/login'));
await step('로그인', () => p.locator('[data-act="login"]').first().click());
if (!(await p.evaluate(() => !!S.user))) errs.push('흐름: 로그인 실패');
else note('로그인됨');

await step('상품', () => go(p, '#/p/12'));
await step('장바구니 담기', () => p.locator('[data-act="cart"]').first().click());
if ((await p.textContent('#cartN')) === '0') errs.push('흐름: 담기 후 배지가 0');
else note('장바구니 담김');
await step('장바구니', () => go(p, '#/cart'));
await step('주문서', () => go(p, '#/checkout'));
await step('주문자명', () => p.fill('#oName', '홍길동'));
await step('입금자명 다르게', () => p.fill('#oDep', '김철수'));
await step('주문하기', () => p.locator('[data-act="order"]').first().click());
const fresh = await p.evaluate(() => S.orders.find(o => o.id > 10481));
if (!fresh) errs.push('흐름: 주문이 만들어지지 않음');
else if (fresh.st !== 'chk') errs.push(`흐름: 입금자명 불일치인데 상태가 ${fresh.st} (chk 이어야 함)`);
else note('입금자명 불일치 → 확인필요로 접수됨');
if (await p.evaluate(() => S.cart.length)) errs.push('흐름: 주문 후에도 장바구니가 남아 있음');

await step('주문내역', () => go(p, '#/orders'));
const rows = await p.locator('#view a[href^="#/order/"]').count();
if (rows < 3) errs.push(`흐름: 주문내역 ${rows}건 (3건이어야 함)`);
else note(`주문내역 ${rows}건`);

// 주문취소 — 확인필요 상태
await step('주문상세', () => p.evaluate(() => { location.hash = '#/order/' + S.orders[0].id; }));
const before = await p.evaluate(() => S.orders.length);
await step('취소', () => p.locator('[data-act="cancel"]').first().click());
await step('취소 확인', () => p.click('#mOk'));
const after = await p.evaluate(() => S.orders.length);
if (after !== before - 1) errs.push(`흐름: 주문취소 실패 ${before}→${after}`);
else note('확인필요 주문 취소됨');

// 배송중 주문은 취소 불가
await step('배송중 주문', () => go(p, '#/order/10481'));
const shipState = await p.evaluate(() => {
  const live = document.querySelector('#view [data-act="cancel"]');
  const txt = document.querySelector('#view').textContent;
  return live ? 'enabled' : (txt.includes('고객센터') || txt.includes('카카오톡') ? 'blocked' : 'missing');
});
if (shipState !== 'blocked') errs.push(`흐름: 배송중 주문 취소 상태 = ${shipState}`);
else note('배송중 주문은 취소 막힘 + 안내 문구');

// 회원가입 + 성인인증 (로그아웃 후)
await step('마이', () => go(p, '#/my'));
await step('로그아웃', () => p.locator('[data-act="logout"]').first().click());
await step('회원가입', () => go(p, '#/join'));
const passFirst = await p.evaluate(() => !!document.querySelector('#view [data-act="pass"]') && !document.querySelector('#jId'));
if (!passFirst) errs.push('흐름: 본인확인 전에 가입 폼이 보임');
else note('본인확인이 가입 폼보다 앞에 있음');
await step('PASS 열기', () => p.locator('[data-act="pass"]').first().click());
await step('PASS 완료', () => p.click('#mOk'));
if (!(await p.evaluate(() => !!document.querySelector('#jId')))) errs.push('흐름: 본인확인 후에도 가입 폼이 없음');
await step('아이디', () => p.fill('#jId', 'duckfan'));
await step('이름', () => p.fill('#jName', '홍길동'));
await step('가입 완료', () => p.locator('[data-act="join"]').first().click());
if (!(await p.evaluate(() => S.user && S.user.name))) errs.push('흐름: 가입 후 로그인 상태 아님');
else note('가입 완료 → 로그인 상태');

// 회원탈퇴 — 배송 중 주문이 있으면 막히고, 없으면 된다
await step('마이', () => go(p, '#/my'));
await step('탈퇴 시도', () => p.locator('[data-act="leave"]').first().click());
await step('탈퇴 확인', () => p.click('#mOk'));
if (!(await p.evaluate(() => !!S.user))) errs.push('흐름: 배송 중 주문이 있는데 탈퇴가 됨');
else note('배송 중 주문이 있어 탈퇴 막힘');

await p.evaluate(() => { S.orders = S.orders.filter(o => o.st !== 'ship'); });
await step('탈퇴 재시도', () => p.locator('[data-act="leave"]').first().click());
await step('탈퇴 확인', () => p.click('#mOk'));
if (await p.evaluate(() => !!S.user)) errs.push('흐름: 배송 주문이 없는데도 탈퇴 안 됨');
else note('배송 주문 정리 후 탈퇴 완료');

await go(p, '#/'); await p.waitForTimeout(800);
await p.screenshot({ path: SP + '/shot-home-390.png', fullPage: true });
await go(p, '#/p/12');
await p.screenshot({ path: SP + '/shot-p-390.png', fullPage: true });
await go(p, '#/shop');
await p.screenshot({ path: SP + '/shot-shop-390.png', fullPage: true });
await p.close();

const d = await mk(1280, 900);
await d.waitForTimeout(700);
await d.screenshot({ path: SP + '/shot-home-1280.png', fullPage: true });
await go(d, '#/p/12');
await d.screenshot({ path: SP + '/shot-p-1280.png', fullPage: true });
await d.close();

clearTimeout(HARD);
await b.close();
console.log(errs.length ? '\n❌ ' + errs.length + '건\n' + errs.join('\n') : '\n✅ 모두 통과');
