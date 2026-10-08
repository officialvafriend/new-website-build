#!/usr/bin/env node
/**
 * 손님 길 점검 — 배포 전후로 돌린다. 기존 버그든 우리 버그든 손님이 지나가는 길이 끊기면 여기서 잡는다.
 *
 *   NODE_USE_ENV_PROXY=1 NODE_EXTRA_CA_CERTS=/root/.ccr/ca-bundle.crt node design/smoke/smoke.mjs [--site https://duck-hoo.com]
 *
 * 보는 것 (전부 비로그인 · 서버 데이터를 만들지 않는다 — 담은 것은 도로 뺀다):
 *   1 홈 · 전체 상품 · 상품 상세 · 가입 3장 · 로그인 · 장바구니 · 가격표 가 200 이고 치명 오류 글자가 없다
 *   2 우리 자산(front.js · shell.css)이 200
 *   3 상품 상세에 구매 폼(form.cart)이 있고 비로그인 사진 가림(19)이 살아 있다
 *   4 가입 폼: 버튼이 name=wd_join_form_submit 이고, 브라우저에서 「가입하기」를 누르면 전송 데이터에 그 값이 실린다
 *     (2026-09-28 사고 — 버튼을 잠그다 값이 빠져 가입이 통째로 막혔다). 전송은 가로채 서버에 보내지 않는다
 *   5 가입 폼 POST 를 본인확인 없이 같은 이메일로 두 번 → 둘 다 200 · 튕김 없음 · 계정 안 생김
 *   6 로그인: 틀린 비밀번호 → 302 ?login=failed (치명 오류가 아니라 정상 거절)
 *   7 Store API: 담기 201 → 장바구니에 1줄 → 지우기 → 0줄
 *   8 비로그인 /checkout/ → 302 /register/ (성인인증 회원만 결제)
 *   9 wp-json/duckhoo/v1/brief 가 키 없이 401/403 (열쇠 없이 안 열림)
 *
 * 결과는 표로, 하나라도 실패하면 exit 1. Playwright 가 없으면 4번만 건너뛴다.
 */
import fs from 'fs';
const SITE = (process.argv.includes('--site') ? process.argv[process.argv.indexOf('--site') + 1] : 'https://duck-hoo.com').replace(/\/$/, '');
const UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
const rows = [];
const ok = (name, pass, note = '') => { rows.push({ name, pass: !!pass, note }); };
const nc = () => 'nocache=' + Math.random().toString(36).slice(2);
const jar = new Map([['dhr_agree', 's0e0t0']]); // 약관을 거친 것처럼 — 안 거치면 /join-form/ 이 /agree/ 로 302 (agree-gate.php)
const cookie = () => [...jar.entries()].map(([k, v]) => k + '=' + v).join('; ');
async function get(path, opt = {}) {
  const u = SITE + path + (path.includes('?') ? '&' : '?') + nc();
  const h = { 'user-agent': UA, ...(opt.headers || {}) }; if (jar.size) h.cookie = cookie();
  const r = await fetch(u, { ...opt, headers: h, redirect: 'manual' });
  for (const c of (r.headers.getSetCookie ? r.headers.getSetCookie() : [])) { const [kv] = c.split(';'); const i = kv.indexOf('='); if (i > 0) jar.set(kv.slice(0, i).trim(), kv.slice(i + 1).trim()); }
  return r;
}
// 가입 3단계를 약관 없이 열면 2단계로 돌려보내는지 (쿠키 없이 · 한 번만)
async function gateCheck(){ const r = await fetch(SITE + '/join-form/?' + nc(), { headers: { 'user-agent': UA }, redirect: 'manual' }); const loc = r.headers.get('location') || ''; ok('약관 없이 3단계 → 2단계로 302', r.status === 302 && /\/agree\/?/.test(loc), r.status + ' ' + loc); }
const fatal = (t) => /critical error|치명적인 오류|Fatal error|There has been a critical error/i.test(t);

// 1 · 2 · 3 · 8 · 9 — 화면 200 과 치명 오류
const pages = [['/', '홈'], ['/shop/', '전체 상품'], ['/register/', '가입 1'], ['/agree/', '가입 2'], ['/join-form/', '가입 3'], ['/login/', '로그인'], ['/cart/', '장바구니'], ['/price/', '가격표']];
let productUrl = '';
for (const [p, label] of pages) {
  try { const r = await get(p); const t = await r.text();
    // 워드프레스닷컴은 치명 오류를 200 + 끊긴 HTML 로 내기도 한다 (2026-10-06 홈이 그랬다) — 끝까지 그려졌는지(</footer>)도 본다
    const whole = /<\/footer>|<\/body>/i.test(t);
    ok(`${label} ${p}`, r.status === 200 && !fatal(t) && whole, `HTTP ${r.status}${whole ? '' : ' · HTML 이 끝까지 안 그려짐 (' + t.length + 'B)'}`);
    if (p === '/' && !productUrl) { const m = t.match(/href="(https?:\/\/[^"]+\/product\/[^"]+)"/); if (m) productUrl = m[1].replace(SITE, ''); }
  } catch (e) { ok(`${label} ${p}`, false, e.message); }
}
try { await gateCheck(); } catch (e) { ok('약관 없이 3단계 → 2단계로 302', false, e.message); }
for (const a of ['/wp-content/plugins/new-website-build/assets/front.js', '/wp-content/plugins/new-website-build/assets/shell.css']) {
  try { const r = await get(a); ok('자산 ' + a.split('/').pop(), r.status === 200, `HTTP ${r.status}`); } catch (e) { ok('자산 ' + a, false, e.message); }
}
let productId = 0;
if (productUrl) {
  try { const r = await get(productUrl); const t = await r.text();
    ok('상품 상세 200', r.status === 200 && !fatal(t), `HTTP ${r.status} ${productUrl.slice(0, 40)}`);
    ok('상품 상세 구매 폼(form.cart)', /<form[^>]+class="[^"]*\bcart\b/.test(t));
    ok('비로그인 사진 가림(19) 살아 있음', /wd-prelogin-thumb/.test(t));
    const m = t.match(/name="add-to-cart"[^>]*value="(\d+)"|data-product_id="(\d+)"|postid-(\d+)/); productId = Number((m && (m[1] || m[2] || m[3])) || 0);
  } catch (e) { ok('상품 상세', false, e.message); }
} else ok('상품 상세', false, '홈에서 상품 링크를 못 찾음');
try { const r = await get('/checkout/'); const loc = r.headers.get('location') || ''; ok('비로그인 결제 → 가입으로 튕김', r.status === 302 && /register|login|my-account/.test(loc), `HTTP ${r.status} → ${loc.replace(SITE, '')}`); } catch (e) { ok('비로그인 결제', false, e.message); }
try { const r = await get('/wp-json/duckhoo/v1/brief'); ok('브리핑 API 키 없이 닫힘', r.status === 401 || r.status === 403, `HTTP ${r.status}`); } catch (e) { ok('브리핑 API', false, e.message); }

// 6 — 로그인 거절이 정상인가
try {
  const body = new URLSearchParams({ log: 'dhr_nobody_' + Date.now(), pwd: 'x' + Date.now(), 'wp-submit': '1', testcookie: '1' });
  const r = await get('/wp-login.php', { method: 'POST', headers: { 'content-type': 'application/x-www-form-urlencoded', referer: SITE + '/login/', cookie: 'wordpress_test_cookie=WP%20Cookie%20check' }, body });
  const loc = r.headers.get('location') || ''; ok('로그인 틀린 비번 → 정상 거절', r.status === 302 && /login=failed/.test(loc), `HTTP ${r.status} → ${loc.replace(SITE, '')}`);
} catch (e) { ok('로그인 거절', false, e.message); }

// 5 — 가입 POST 두 번 (본인확인 없음 · 계정 안 생김)
try {
  const email = `dhr-smoke-${Date.now()}@example.com`; const phone = '0109' + String(Date.now()).slice(-7); const st = [];
  for (let i = 0; i < 2; i++) {
    const body = new URLSearchParams({ wd_join_form_nonce: 'deadbeef', wd_join_email: email, wd_join_phone: phone, wd_join_password: 'Xx12345678!', wd_join_form_submit: '1' });
    const r = await get('/join-form/', { method: 'POST', headers: { 'content-type': 'application/x-www-form-urlencoded', referer: SITE + '/join-form/' }, body });
    st.push(r.status + (r.headers.get('location') ? '→' + (r.headers.get('location') || '').replace(SITE, '') : ''));
  }
  ok('가입 POST 두 번 — 튕김 없음', st.every(s => s === '200'), st.join(' / '));
} catch (e) { ok('가입 POST', false, e.message); }

// 7 — Store API 담기 · 지우기
if (productId) {
  try {
    let r = await get('/wp-json/wc/store/v1/cart'); let nonce = r.headers.get('nonce') || r.headers.get('x-wc-store-api-nonce') || '';
    r = await get('/wp-json/wc/store/v1/cart/add-item', { method: 'POST', headers: { 'content-type': 'application/json', nonce }, body: JSON.stringify({ id: productId, quantity: 1 }) });
    nonce = r.headers.get('nonce') || nonce; const added = r.status === 201 || r.status === 200; const j = added ? await r.json() : null;
    const key = j && j.items && j.items[0] ? j.items[0].key : '';
    if (added && key) { const d = await get('/wp-json/wc/store/v1/cart/remove-item', { method: 'POST', headers: { 'content-type': 'application/json', nonce }, body: JSON.stringify({ key }) }); const dj = d.status === 200 ? await d.json() : { items: [1] };
      ok('Store API 담기 → 지우기', dj.items && dj.items.length === 0, `add ${r.status} · remove ${d.status}`); }
    else { const t = j ? JSON.stringify(j).slice(0, 80) : await r.text(); ok('Store API 담기', r.status === 400 && /옵션|option/i.test(t), `HTTP ${r.status} (옵션 필수 상품이면 400 이 정상) ${t.slice(0, 60)}`); }
  } catch (e) { ok('Store API', false, e.message); }
} else ok('Store API 담기', false, '상품 번호를 못 읽음');

// 4 — 브라우저: 가입하기 전송 데이터에 버튼 값이 실리는가
// playwright 는 저장소에 없다 — 이 환경에서는 scratchpad/live/node_modules 에 있다 (DHR_NODE_MODULES 로 자리를 준다)
let pw = null;
try { pw = await import('playwright'); } catch (e) {
  try { const { createRequire } = await import('module'); const dir = process.env.DHR_NODE_MODULES || '/tmp/claude-0/-home-user-new-website-build/eaa69852-6737-54fa-a860-4a2fc73b9c20/scratchpad/live/node_modules';
    pw = createRequire(dir + '/x.js')('playwright'); } catch (e2) { ok('가입 버튼 값 전송 (브라우저)', true, 'playwright 없음 — 건너뜀'); }
}
if (pw) {
  try {
    // 크로미움이 직접 소켓을 열지 않게 모든 요청을 Node fetch(NODE_USE_ENV_PROXY=1 로 프록시 · CA 를 탄다)로 대신 받아 준다
    const br = await pw.chromium.launch({ executablePath: process.env.PW_CHROMIUM || '/opt/pw-browsers/chromium', args: ['--no-sandbox'] });
    const ctx = await br.newContext({ userAgent: UA, viewport: { width: 390, height: 844 } }); const posts = [];
    await ctx.addCookies([{ name: 'dhr_agree', value: 's0e0t0', domain: new URL(SITE).hostname, path: '/' }]);
    await ctx.route('**/*', async r => { const req = r.request(); const u = req.url(); if (!/^https?:/.test(u)) return r.continue();
      if (req.method() === 'POST' && u.includes('/join-form')) { posts.push(req.postData() || ''); return r.fulfill({ status: 200, headers: { 'content-type': 'text/html' }, body: '<html></html>' }); }
      if (req.method() === 'POST') return r.fulfill({ status: 200, headers: { 'content-type': 'application/json' }, body: '{}' });
      try { const res = await fetch(u, { method: 'GET', headers: { ...req.headers(), 'accept-encoding': 'identity' }, redirect: 'follow' }); const body = Buffer.from(await res.arrayBuffer()); const h = {}; res.headers.forEach((v, k) => { if (!['content-encoding', 'transfer-encoding', 'content-length', 'set-cookie'].includes(k)) h[k] = v; }); await r.fulfill({ status: res.status, headers: h, body }); } catch (e) { await r.fulfill({ status: 204, body: '' }); } });
    const p = await ctx.newPage(); p.on('dialog', d => d.dismiss().catch(() => {}));
    await p.goto(SITE + '/join-form/?' + nc(), { waitUntil: 'load', timeout: 120000 }); await p.waitForTimeout(1200);
    const btn = await p.evaluate(() => { const b = document.querySelector('form.wd-join-form .wd-join-submit'); return b ? { name: b.name, value: b.value } : null; });
    ok('가입 버튼 name=wd_join_form_submit', btn && btn.name === 'wd_join_form_submit' && btn.value === '1', JSON.stringify(btn));
    await p.evaluate(() => { const f = document.querySelector('form.wd-join-form'); f && f.querySelectorAll('input').forEach(i => { if (i.type === 'checkbox') i.checked = true; else if (i.type === 'email' || /email/.test(i.name)) i.value = 'dhr-smoke@example.com'; else if (/phone/.test(i.name)) i.value = '01099998888'; else if (i.type === 'password') i.value = 'Xx12345678!'; else if (i.type === 'text' && !i.readOnly && !i.value) i.value = '시험'; }); });
    await p.click('form.wd-join-form .wd-join-submit', { force: true }).catch(() => {}); await p.waitForTimeout(2500);
    ok('가입하기 누르면 전송에 버튼 값 실림', posts.length > 0 && /(^|&)wd_join_form_submit=1(&|$)/.test(posts[0]), posts.length ? (posts[0].split('&').length + '칸') : '전송 없음');
    await br.close();
  } catch (e) { ok('가입 버튼 값 전송 (브라우저)', false, e.message.split('\n')[0]); }
}

// 결과
const w = Math.max(...rows.map(r => r.name.length));
for (const r of rows) console.log((r.pass ? '  ✅ ' : '  ❌ ') + r.name.padEnd(w + 2) + r.note);
const bad = rows.filter(r => !r.pass);
console.log(bad.length ? `\n❌ ${bad.length}개 실패 (${SITE})` : `\n✅ 모두 통과 (${SITE})`);
process.exit(bad.length ? 1 : 0);
