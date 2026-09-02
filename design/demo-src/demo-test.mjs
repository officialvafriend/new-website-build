import { chromium } from 'playwright';
import fs from 'fs';
const HARD = setTimeout(() => { console.log('\n⏱ 하드 타임아웃'); process.exit(2); }, 150000); HARD.unref?.();

/* 사용법: npm i playwright gsap 한 뒤
   SP=<gsap/playwright 가 설치된 폴더> node design/demo-src/demo-test.mjs */
import path from 'path';
import os from 'os';

/* 아티팩트가 감싸는 것과 같은 뼈대(charset + viewport + 작은 리셋)를 씌워서 본다.
   viewport 메타가 없으면 모바일 브라우저가 980px 로 잡아 버려 실제와 달라진다. */
const WRAPPED = path.join(os.tmpdir(), 'duckhoo-demo-wrapped.html');
fs.writeFileSync(WRAPPED, `<!doctype html><html><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>:root{color-scheme:light}body{margin:0}img{max-width:100%}[hidden]{display:none!important}</style>
</head><body>${fs.readFileSync(path.resolve('design/demo.html'), 'utf8')}</body></html>`);
const FILE = 'file://' + WRAPPED;
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
  const ctx = await b.newContext({ viewport: { width, height }, deviceScaleFactor: 1, isMobile: width < 880, hasTouch: width < 880 });
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
const routes = ['#/', '#/shop', '#/shop?f=멘솔', '#/p/12', '#/p/4', '#/cart', '#/search', '#/search?q=멘솔', '#/login', '#/join', '#/orders', '#/order/10481', '#/my', '#/zzz'];

for (const w of [390, 768, 1280]) {
  const p = await mk(w, 900);
  for (const r of routes) {
    await go(p, r);
    const m = await p.evaluate(() => {
      const v = document.querySelector('#view'); v.scrollTo(9999, 0); const x = v.scrollLeft; v.scrollTo(0, 0);
      const tabs = document.querySelector('.tabs');
      return { sw: v.scrollWidth, cw: v.clientWidth, x, dsw: document.documentElement.scrollWidth, dcw: document.documentElement.clientWidth,
               docH: document.documentElement.scrollHeight, cliH: document.documentElement.clientHeight,
               tabBottom: tabs ? Math.round(tabs.getBoundingClientRect().bottom) : 0,
               len: v.innerHTML.trim().length };
    });
    if (m.sw > m.cw + 1 || m.x > 0 || m.dsw > m.dcw + 1) errs.push(`[${w}] 가로 넘침 @${r}: sw=${m.sw} cw=${m.cw} scrollX=${m.x} doc=${m.dsw}/${m.dcw}`);
    if (m.len < 80) errs.push(`[${w}] 빈 화면 @${r}`);
    if (!(await p.locator('#view footer.foot').count())) errs.push(`[${w}] 푸터 없음 @${r}`);
    // 문서 자체는 스크롤하지 않는다 (스크롤은 #view 안에서만). 그래야 탭바가 안 움직인다.
    if (m.docH > m.cliH + 1) errs.push(`[${w}] 문서가 스크롤됨 @${r}: docH=${m.docH} cliH=${m.cliH}`);
    if (w < 880 && Math.abs(m.tabBottom - m.cliH) > 1) errs.push(`[${w}] 탭바가 화면 아래에 안 붙음 @${r}: ${m.tabBottom} vs ${m.cliH}`);
  }
  if (w < 880) {
    await go(p, '#/');
    const before = await p.evaluate(() => Math.round(document.querySelector('.tabs').getBoundingClientRect().top));
    await p.evaluate(() => document.querySelector('#view').scrollTo(0, 900)); await p.waitForTimeout(300);
    const after = await p.evaluate(() => Math.round(document.querySelector('.tabs').getBoundingClientRect().top));
    const small = await p.evaluate(() => document.querySelector('.gnb').classList.contains('small'));
    if (before !== after) errs.push(`[${w}] 스크롤 중 탭바가 움직임 ${before}→${after}`);
    if (!small) errs.push(`[${w}] 스크롤해도 작은 제목이 안 켜짐`);
    await go(p, '#/orders');
    const after2 = await p.evaluate(() => Math.round(document.querySelector('.tabs').getBoundingClientRect().top));
    if (before !== after2) errs.push(`[${w}] 홈→주문내역에서 탭바가 움직임 ${before}→${after2}`);
    note(`${w}px 탭바 고정 (${before}px) · 작은 제목 전환`);
  }
  note(`${w}px 라우트 ${routes.length}개 확인`);
  await p.close();
}

// ── 구매 흐름 (390px) ──
const p = await mk(390, 844);
const step = async (label, fn) => { try { await fn(); await p.waitForTimeout(320); } catch (e) { errs.push(`흐름[${label}]: ${e.message.split('\n')[0]}`); } };

// 홈: 카운트다운 · 필터 · 찜 · 푸터
await step('홈', () => go(p, '#/'));
const left1 = await p.textContent('#left'); await p.waitForTimeout(1200); const left2 = await p.textContent('#left');
if (!left1 || left1 === left2) errs.push(`흐름: 카운트다운이 안 움직임 (${left1} → ${left2})`); else note('특가 카운트다운 동작');
await step('브랜드 필터', () => p.selectOption('[data-hf="b"]', '맥스쿨'));
const cnt = await p.textContent('#hcount');
if (cnt !== '3종') errs.push(`흐름: 브랜드 필터 결과 ${cnt} (3종이어야 함)`); else note('홈 필터: 맥스쿨 3종');
await step('니코틴 필터 겹치기', () => p.selectOption('[data-hf="n"]', '9.8mg'));
if (!/없습니다/.test(await p.textContent('#hgrid'))) errs.push('흐름: 맥스쿨 + 9.8mg 인데 빈 결과 안내가 없음'); else note('빈 결과 안내 + 초기화');
await step('필터 초기화', () => p.locator('[data-hf-reset]').first().click());
if ((await p.textContent('#hcount')) !== '15종') errs.push('흐름: 필터 초기화 실패');
const wishBefore = await p.evaluate(() => S.wish.size);
await step('찜', () => p.locator('#hgrid [data-wish]').first().click());
if ((await p.evaluate(() => S.wish.size)) !== wishBefore + 1) errs.push('흐름: 찜이 안 됨'); else note('찜 토글');
if (!(await p.locator('footer.foot .fcol').count())) errs.push('흐름: 푸터 링크 열이 없음'); else note('푸터');

// 검색
await step('검색 화면', () => go(p, '#/search'));
if (!(await p.evaluate(() => document.activeElement && document.activeElement.id === 'sq'))) errs.push('흐름: 검색 화면에서 입력칸에 포커스가 없음');
else note('검색 입력에 포커스');
await step('검색어 입력', () => p.fill('#sq', '멘솔'));
const menthol = await p.locator('#sres .card').count();
if (menthol < 3) errs.push(`흐름: "멘솔" 검색 결과 ${menthol}건`);
else note(`"멘솔" 검색 ${menthol}건`);
if (!/#\/search\?q=/.test(await p.evaluate(() => location.hash))) errs.push('흐름: 검색어가 주소에 안 남음');
await step('브랜드 검색', () => p.fill('#sq', '맥스쿨'));
const brand = await p.locator('#sres .card').count();
if (brand !== 3) errs.push(`흐름: 브랜드 "맥스쿨" 검색 ${brand}건 (3건이어야 함)`);
else note('브랜드로도 찾힘');
await step('없는 검색어', () => p.fill('#sq', 'zzzz'));
if (!/맞는 상품이 없습니다/.test(await p.textContent('#sres'))) errs.push('흐름: 결과 없음 안내가 안 나옴');
else note('결과 없음 안내');
await step('추천어 클릭', () => p.locator('#sres [data-kw]').first().click());
if (!(await p.locator('#sres .card').count())) errs.push('흐름: 추천 검색어를 눌러도 결과가 없음');
else note('추천 검색어 동작');
await step('지우기', () => p.locator('#sform .x').click());
if (await p.evaluate(() => S.q)) errs.push('흐름: 검색어 지우기가 안 됨');
else note('검색어 지우기');
await step('검색에서 상품으로', () => p.locator('#sres .card').first().click());
if (!/#\/p\//.test(await p.evaluate(() => location.hash))) errs.push('흐름: 검색 결과에서 상품으로 못 감');
else note('검색 결과 → 상품 상세');

await step('로그인 화면', () => go(p, '#/login'));
await step('로그인', () => p.locator('[data-act="login"]').first().click());
if (!(await p.evaluate(() => !!S.user))) errs.push('흐름: 로그인 실패');
else note('로그인됨');

await step('품절 상품', () => go(p, '#/p/6'));
if (!(await p.evaluate(() => document.querySelector('[data-act="buy"]').disabled))) errs.push('흐름: 품절인데 구매 버튼이 살아 있음'); else note('품절 상품은 구매 막힘');
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
if (rows < 4) errs.push(`흐름: 주문내역 ${rows}건 (4건이어야 함)`);
else note(`주문내역 ${rows}건`);

// 확인필요 주문은 고객이 직접 못 지운다 (입금 문자가 이미 들어온 상태다)
await step('확인필요 주문', () => p.evaluate(() => { location.hash = '#/order/' + S.orders[0].id; }));
if (await p.locator('#view [data-act="cancel"]').count()) errs.push('흐름: 확인필요인데 취소 버튼이 살아 있음');
else note('확인필요 주문은 취소 막힘');

// 입금전 주문은 취소된다
await step('입금전 주문', () => go(p, '#/order/10479'));
const before = await p.evaluate(() => S.orders.length);
await step('취소', () => p.locator('[data-act="cancel"]').first().click());
await step('취소 확인', () => p.click('#mOk'));
const after = await p.evaluate(() => S.orders.length);
if (after !== before - 1) errs.push(`흐름: 입금전 주문취소 실패 ${before}→${after}`);
else note('입금전 주문 취소됨');

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

// 회원탈퇴 — 미배송 주문이나 적립금이 남아 있으면 막힌다
await step('마이', () => go(p, '#/my'));
await step('탈퇴 시도', () => p.locator('[data-act="leave"]').first().click());
const stopText = await p.textContent('#mBody');
if (!/주문이 \d+건|적립금/.test(stopText || '')) errs.push(`흐름: 탈퇴 차단 사유가 안 보임 (${stopText})`);
else note('탈퇴 차단 사유가 모달에 나옴');
await step('닫기', () => p.click('#mCancel'));
if (!(await p.evaluate(() => !!S.user))) errs.push('흐름: 차단 상태인데 탈퇴가 됨');

await p.evaluate(() => { S.orders = []; S.points = 0; });
await step('탈퇴 재시도', () => p.locator('[data-act="leave"]').first().click());
await step('탈퇴 확인', () => p.click('#mOk'));
if (await p.evaluate(() => !!S.user)) errs.push('흐름: 조건을 다 지웠는데 탈퇴 안 됨');
else note('조건 정리 후 탈퇴 완료');

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
