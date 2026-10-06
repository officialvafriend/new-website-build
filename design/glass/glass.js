/* 유리 리디자인 v2 시안 — 공통 껍데기 · 테마 · 카루셀 · 시트 · 완료 막. 스크롤 리스너 없음 (IO · CSS 타임라인). */
(function () {
  const I = {
    search:'<svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.6-3.6"/></svg>',
    bag:'<svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 8h12l1 13H5z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/></svg>',
    user:'<svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>',
    home:'<svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 11 12 3l9 8"/><path d="M5 10v11h14V10"/></svg>',
    grid:'<svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>',
    chevL:'<svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path d="m15 5-7 7 7 7"/></svg>',
    chevR:'<svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 5 7 7-7 7"/></svg>',
    chevD:'<svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>',
    sun:'<svg class="ico sun" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>',
    moon:'<svg class="ico moon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5z"/></svg>',
    check:'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg>'
  };
  window.ICO = I;
  const page = document.body.dataset.page || '';
  const nav = [['index.html','노보'],['shop.html','입호흡'],['shop.html','폐호흡'],['shop.html','타격감'],['shop.html','적립금 상품'],['shop.html','이달 특가'],['shop.html','기기·팟'],['shop.html','가격표']];
  const hdr = `<header class="hdr glass"><div class="wrap">
    <a class="logo" href="index.html">액상덕후</a>
    <nav class="nav" aria-label="분류">${nav.map((n,i)=>`<a href="${n[0]}"${i===0?' class="on"':''}>${n[1]}</a>`).join('')}</nav>
    <a class="search" href="shop.html">${I.search}<span>상품, 브랜드 검색</span></a>
    <div class="right">
      <button class="ptg" type="button">파랑</button><button class="icbtn tgl" type="button" aria-label="밝기 바꾸기">${I.sun}${I.moon}</button>
      <a class="icbtn cartb" href="cart.html" aria-label="장바구니 2개">${I.bag}<span class="badge">2</span></a>
      <a class="icbtn" href="account.html" aria-label="내 계정">${I.user}</a>
    </div></div></header>
    <p class="promo">가입 즉시 8,800원 적립, 3만원 이상 무료배송 · 19세 미만 판매 금지</p>`;
  const tab = `<nav class="tab glass" aria-label="아래 메뉴">
    <a href="index.html"${page==='home'?' class="on"':''}>${I.home}<span>홈</span></a>
    <a href="shop.html"${page==='shop'?' class="on"':''}>${I.grid}<span>분류</span></a>
    <a href="shop.html">${I.search}<span>검색</span></a>
    <a href="cart.html"${page==='cart'?' class="on"':''}>${I.bag}<span>장바구니</span></a>
    <a href="account.html"${page==='account'?' class="on"':''}>${I.user}<span>내 계정</span></a></nav>`;
  const ftr = `<footer class="ftr"><div class="wrap in">
    <div><h4>액상덕후</h4><p>전자담배 액상 전문. 노보, 디오리퀴드, 화이트아웃, 펠릭스.<br>고객센터 평일 11:00-18:00</p><a href="#">카카오톡 오픈채팅 문의</a></div>
    <div><h4>상품</h4><a href="shop.html">노보 액상</a><a href="shop.html">입호흡 액상</a><a href="shop.html">폐호흡 액상</a><a href="shop.html">타격감</a><a href="shop.html">전 상품 가격표</a></div>
    <div><h4>안내</h4><a href="#">배송, 교환, 환불</a><a href="#">액상 고르는 법</a><a href="#">입호흡 vs 폐호흡</a><a href="#">이용약관</a><a href="#">개인정보처리방침</a></div>
    <div class="bank"><span>입금 계좌</span><b>3333-12-3456789</b><span>카카오뱅크, 예금주 액상덕후</span><span style="margin-top:6px;color:inherit;font-weight:700">입금자명은 주문자명과 같게 넣어 주세요</span><button class="btn" type="button">계좌번호 복사</button></div>
    </div><div class="wrap"><p class="legal">상호 액상덕후 · 사업자등록번호 000-00-00000 · 통신판매업 신고 0000-대구-0000<br>본 사이트의 모든 상품은 19세 이상 성인인증 회원에게만 판매합니다.</p></div></footer>`;
  if (!document.body.dataset.noshell) {
    document.body.insertAdjacentHTML('afterbegin', hdr);
    document.body.insertAdjacentHTML('beforeend', ftr + tab);
  }
  // 포인트 색: 실버 스위치
  try { const a = localStorage.getItem('dhr-accent'); if (a === 'silver') document.documentElement.dataset.accent = a; } catch (e) {}
  { const n = { silver: '실버', blue: '파랑' }[document.documentElement.dataset.accent]; if (n) { const b = document.querySelector('.ptg'); if (b) b.textContent = n; } }
  document.addEventListener('click', e => { const b = e.target.closest('.ptg'); if (!b) return; const r = document.documentElement; const order = ['', 'silver'], names = { '': '파랑', silver: '실버' }; const next = order[(order.indexOf(r.dataset.accent || '') + 1) % order.length]; if (next) r.dataset.accent = next; else delete r.dataset.accent; b.textContent = names[next]; try { localStorage.setItem('dhr-accent', r.dataset.accent || ''); } catch (e) {} });
  document.body.insertAdjacentHTML('beforeend','<svg width="0" height="0" style="position:absolute"><defs><linearGradient id="metalStroke" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#F4F5F7"/><stop offset=".45" stop-color="#9CA4AE"/><stop offset="1" stop-color="#E6E9ED"/></linearGradient></defs></svg>');
  // 테마: 저장값 > 시스템
  const root = document.documentElement;
  try { const t = localStorage.getItem('dhr-theme'); if (t) root.dataset.theme = t; } catch (e) {}
  document.addEventListener('click', e => {
    const b = e.target.closest('.tgl'); if (!b) return;
    const dark = root.dataset.theme === 'dark' || (!root.dataset.theme && matchMedia('(prefers-color-scheme: dark)').matches);
    root.dataset.theme = dark ? 'light' : 'dark';
    try { localStorage.setItem('dhr-theme', root.dataset.theme); } catch (e) {}
  });
  // 등장 폴백 (CSS 타임라인이 없을 때만 .in 을 붙인다)
  if (!CSS.supports('animation-timeline: view()')) {
    const io = new IntersectionObserver(es => es.forEach(x => { if (x.isIntersecting) { x.target.classList.add('in'); io.unobserve(x.target); } }), { rootMargin: '0px 0px -10% 0px' });
    document.querySelectorAll('.rv').forEach(el => io.observe(el));
  }
  // 숫자 올라감 (한 번)
  const nio = new IntersectionObserver(es => es.forEach(x => {
    if (!x.isIntersecting) return; nio.unobserve(x.target);
    const el = x.target, to = +el.dataset.n, t0 = performance.now(), d = 900;
    if (matchMedia('(prefers-reduced-motion: reduce)').matches) { el.textContent = to.toLocaleString(); return; }
    (function f(t) { const p = Math.min(1, (t - t0) / d), e = 1 - Math.pow(1 - p, 3); el.textContent = Math.round(to * e).toLocaleString(); if (p < 1) requestAnimationFrame(f); })(t0);
  }), { threshold: .4 });
  document.querySelectorAll('[data-n]').forEach(el => nio.observe(el));
  // 카루셀: 화살표 · 점 (IO 로 어느 카드가 보이는지 센다)
  document.querySelectorAll('.car').forEach(car => {
    const trk = car.querySelector('.trk'); const cards = [...trk.children];
    const dots = car.querySelector('.dots'); if (dots) dots.innerHTML = cards.map((_, i) => `<i${i ? '' : ' class="on"'}></i>`).join('');
    car.querySelectorAll('.arr').forEach(a => a.addEventListener('click', () => { const w = cards[0].getBoundingClientRect().width + parseFloat(getComputedStyle(trk).columnGap || 12); trk.scrollBy({ left: a.classList.contains('r') ? w : -w, behavior: 'smooth' }); }));
    if (dots) { const io = new IntersectionObserver(es => es.forEach(x => { if (x.isIntersecting) { const i = cards.indexOf(x.target); dots.querySelectorAll('i').forEach((d, j) => d.classList.toggle('on', j === i)); } }), { root: trk, threshold: .6 }); cards.forEach(c => io.observe(c)); }
  });
  // 시트 열고 닫기
  const veil = document.querySelector('.veil'), sheet = document.querySelector('.sheet');
  function openSheet() { if (!sheet) return; sheet.classList.remove('off'); sheet.classList.add('on'); veil && veil.classList.add('on'); sheet.querySelector('.x') && sheet.querySelector('.x').focus(); }
  function closeSheet() { if (!sheet) return; sheet.classList.add('off'); sheet.classList.remove('on'); veil && veil.classList.remove('on'); }
  document.addEventListener('click', e => { if (e.target.closest('[data-open-sheet]')) openSheet(); if (e.target.closest('[data-close-sheet]') || e.target === veil) closeSheet(); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') { closeSheet(); document.querySelectorAll('.done.on').forEach(d => d.classList.remove('on')); } });
  // 완료 막: data-done="cart" 이면 0.8초 뒤 자동 닫힘, "order" 는 머문다
  document.addEventListener('click', e => {
    const b = e.target.closest('[data-done]'); if (!b) return;
    const d = document.getElementById('done-' + b.dataset.done); if (!d) return;
    closeSheet(); d.classList.add('on');
    if (b.dataset.done === 'cart') setTimeout(() => d.classList.remove('on'), 800 + 300);
  });
  document.addEventListener('click', e => { if (e.target.closest('[data-close-done]')) e.target.closest('.done').classList.remove('on'); });
  // 구성 카드 · 수량 · 칩 (시안용 상태)
  document.addEventListener('click', e => {
    const bc = e.target.closest('.bc'); if (bc) { bc.parentElement.querySelectorAll('.bc').forEach(x => x.classList.toggle('on', x === bc)); }
    const q = e.target.closest('.qty button'); if (q) { const s = q.parentElement.querySelector('span'); let v = +s.textContent; v = q.dataset.d === '-' ? Math.max(0, v - 1) : v + 1; s.style.filter = 'blur(2px)'; s.style.opacity = '.6'; setTimeout(() => { s.textContent = v; s.style.filter = ''; s.style.opacity = ''; const fl = q.closest('.fl'); if (fl) fl.classList.toggle('on', v > 0); }, 110); }
    const ch = e.target.closest('.cw .chip'); if (ch) ch.classList.toggle('on');
  });
  document.querySelectorAll('.qty span').forEach(s => { s.style.transition = 'filter 120ms ease, opacity 120ms ease'; });
})();
