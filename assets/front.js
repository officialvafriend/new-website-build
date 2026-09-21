/* 홈 — 카운트다운 · 필터 이동 · 찜(브라우저 저장) */
(function(){
  var end=(window.DHR&&window.DHR.saleEnd)||0, el=document.getElementById('dhr-left');
  function pad(n){return String(n).padStart(2,'0')}
  function tick(){ if(!el||!end)return; var ms=Math.max(0,end-Date.now()), d=Math.floor(ms/864e5), h=Math.floor(ms/36e5)%24, m=Math.floor(ms/6e4)%60, s=Math.floor(ms/1e3)%60;
    el.textContent=d+'일 '+pad(h)+':'+pad(m)+':'+pad(s); }
  tick(); setInterval(tick,1000);

  document.addEventListener('change',function(e){ var s=e.target.closest('[data-go]'); if(s&&s.value){ location.href=s.value; } });

})();

/* 가로 스크롤러 — 데스크톱은 휠이 세로로만 가니 화살표와 드래그를 붙인다 */
(function(){
  document.querySelectorAll('.scroller').forEach(function(sc){
    var wrap=document.createElement('div'); wrap.className='scw'; sc.parentNode.insertBefore(wrap,sc); wrap.appendChild(sc);
    var mk=function(dir){ var b=document.createElement('button'); b.type='button'; b.className='scb scb-'+dir; b.setAttribute('aria-label',dir==='prev'?'이전':'다음');
      b.innerHTML='<svg viewBox="0 0 24 24" aria-hidden="true"><path d="'+(dir==='prev'?'m15 5-7 7 7 7':'m9 5 7 7-7 7')+'"/></svg>';
      b.addEventListener('click',function(){ var card=sc.querySelector('.card'); var step=card?card.getBoundingClientRect().width+12:300; sc.scrollBy({left:dir==='prev'?-step*2:step*2,behavior:'smooth'}); }); return b; };
    var prev=mk('prev'), next=mk('next'); wrap.appendChild(prev); wrap.appendChild(next);
    var paint=function(){ prev.classList.toggle('off',sc.scrollLeft<=2); next.classList.toggle('off',sc.scrollLeft+sc.clientWidth>=sc.scrollWidth-2); wrap.classList.toggle('scw-none',sc.scrollWidth<=sc.clientWidth+2); };
    sc.addEventListener('scroll',paint,{passive:true}); window.addEventListener('resize',paint); paint();
    // 마우스 드래그
    var down=false, sx=0, sl=0, moved=false;
    sc.addEventListener('pointerdown',function(e){ if(e.pointerType!=='mouse')return; down=true; moved=false; sx=e.clientX; sl=sc.scrollLeft; sc.classList.add('dragging'); });
    window.addEventListener('pointermove',function(e){ if(!down)return; var dx=e.clientX-sx; if(Math.abs(dx)>4)moved=true; sc.scrollLeft=sl-dx; });
    window.addEventListener('pointerup',function(){ if(!down)return; down=false; sc.classList.remove('dragging'); });
    sc.addEventListener('click',function(e){ if(moved){ e.preventDefault(); e.stopPropagation(); moved=false; } },true);
  });
})();

/* 히어로 슬라이드 — 묶음 상품을 넘겨 본다.
   스스로 넘어가는 것에는 멈춤이 따라와야 한다: 마우스·포커스가 들어오면 서고,
   멈춤 버튼이 있고, 동작 줄이기를 켠 사람에게는 아예 자동으로 넘기지 않는다. */
(function(){
  var root = document.querySelector('[data-hero]');
  if(!root) return;
  var items = [].slice.call(root.querySelectorAll('.hslide-item'));
  if(items.length < 2) return;
  var track = root.querySelector('.hslide-track');
  var dots  = [].slice.call(root.querySelectorAll('.hdot'));
  var play  = root.querySelector('.hplay');
  var calm  = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var i = 0, timer = null, on = !calm;

  function show(n){
    i = (n + items.length) % items.length;
    track.style.transform = 'translateX(' + (-i * 100) + '%)';
    items.forEach(function(el, k){
      var cur = k === i;
      el.setAttribute('aria-hidden', cur ? 'false' : 'true');
      if(cur) el.removeAttribute('tabindex'); else el.setAttribute('tabindex','-1');
    });
    dots.forEach(function(d, k){ d.classList.toggle('on', k === i); d.setAttribute('aria-selected', k === i); });
  }
  function stop(){ if(timer){ clearInterval(timer); timer = null; } }
  function start(){ stop(); if(on) timer = setInterval(function(){ show(i + 1); }, 5000); }
  function setPlay(v){ on = v; if(play){ play.dataset.playing = v ? '1' : '0'; play.setAttribute('aria-label', v ? '자동 넘김 멈춤' : '자동 넘김 시작'); } v ? start() : stop(); }

  root.querySelector('.hprev').addEventListener('click', function(){ setPlay(false); show(i - 1); });
  root.querySelector('.hnext').addEventListener('click', function(){ setPlay(false); show(i + 1); });
  dots.forEach(function(d, k){ d.addEventListener('click', function(){ setPlay(false); show(k); }); });
  if(play) play.addEventListener('click', function(){ setPlay(!on); });

  root.addEventListener('mouseenter', stop);
  root.addEventListener('mouseleave', function(){ if(on) start(); });
  root.addEventListener('focusin', stop);
  root.addEventListener('focusout', function(){ if(on) start(); });

  /* 손가락으로 넘기기 — 세로 스크롤은 방해하지 않는다 */
  var x0 = null, y0 = null;
  root.addEventListener('touchstart', function(e){ x0 = e.touches[0].clientX; y0 = e.touches[0].clientY; }, {passive:true});
  root.addEventListener('touchend', function(e){
    if(x0 === null) return;
    var dx = e.changedTouches[0].clientX - x0, dy = e.changedTouches[0].clientY - y0;
    if(Math.abs(dx) > 45 && Math.abs(dx) > Math.abs(dy)){ setPlay(false); show(i + (dx < 0 ? 1 : -1)); }
    x0 = y0 = null;
  }, {passive:true});

  show(0);
  setPlay(!calm);
})();

/* 상품 상세 — 추천 상품이 구매 상자 안에 들어가 있다.
   WooCommerce Product Recommendations 가 woocommerce_after_add_to_cart_form 에 붙어서
   .summary 의 자식으로 그려지기 때문이다. 구매 상자 안에 상품 카드가 4개 끼어 있으면
   무엇을 사는 화면인지 흐려진다. 두 칸 아래로 꺼내 전체 폭으로 눕힌다.
   DOM 을 옮기기만 한다 — 링크도 폼도 그대로다. */
(function(){
  var prod = document.querySelector('.single-product div.product');
  if(!prod) return;
  var moved = [];
  prod.querySelectorAll('.dhp-buy .wc-prl-recommendations, .dhp-buy .related, .dhp-buy .upsells, .summary .wc-prl-recommendations, .summary .related, .summary .upsells').forEach(function(el){ moved.push(el); });
  moved.forEach(function(el){ prod.appendChild(el); });
})();

/* 상품 사진 — 썸네일을 누르면 그 사진이 크게. src 를 바꾸지 않고 슬라이드를 보였다 감춘다. */
(function(){
  var g = document.querySelector('[data-gal]'); if(!g) return;
  var slides = [].slice.call(g.querySelectorAll('.dhp-gal__slide')), thumbs = [].slice.call(g.querySelectorAll('[data-thumb]'));
  function go(i){ slides.forEach(function(s,k){ s.classList.toggle('on', k===i); }); thumbs.forEach(function(t,k){ t.classList.toggle('on', k===i); t.setAttribute('aria-selected', k===i); }); }
  thumbs.forEach(function(t){ t.addEventListener('click', function(){ go(+t.dataset.thumb); }); });
  var x0=null; g.addEventListener('touchstart', function(e){ x0=e.touches[0].clientX; }, {passive:true});
  g.addEventListener('touchend', function(e){ if(x0===null||slides.length<2) return; var dx=e.changedTouches[0].clientX-x0; x0=null; if(Math.abs(dx)<45) return;
    var cur=slides.findIndex(function(s){ return s.classList.contains('on'); }); go((cur+(dx<0?1:-1)+slides.length)%slides.length); }, {passive:true});
})();

/* 상품 사진 — 몇 장째인지. 썸네일·넘김 양쪽에서 같은 숫자를 본다. */
(function(){
  var g = document.querySelector('[data-gal]'), n = g && g.querySelector('[data-gal-n] b'); if(!g || !n) return;
  var slides = g.querySelectorAll('.dhp-gal__slide');
  var obs = new MutationObserver(function(){ for(var i=0;i<slides.length;i++){ if(slides[i].classList.contains('on')){ n.textContent = i + 1; break; } } });
  for(var i=0;i<slides.length;i++) obs.observe(slides[i], {attributes:true, attributeFilter:['class']});
})();

/* 장바구니 서랍 — 담기 직후와 헤더·탭바의 장바구니 버튼에서 연다.
   내용은 WooCommerce Store API(/wc/store/v1/cart) 로 읽는다. 지우기만 여기서 하고
   수량은 장바구니 페이지(키플)에서 — 묶음 옵션은 그쪽 규칙이 있다.
   비로그인은 사진을 그리지 않는다: Store API 사진은 키플의 19 가림을 안 거친다. */
(function(){
  var D = window.DHR || {}, root = document.querySelector('[data-cart-drawer]'); if(!root || !window.fetch) return;
  var list = root.querySelector('[data-cart-list]'), count = root.querySelector('[data-cart-count]'), total = root.querySelector('[data-cart-total]');
  var ship = root.querySelector('[data-cart-ship]'), shipT = root.querySelector('[data-cart-ship-text]'), shipF = root.querySelector('[data-cart-ship-fill]');
  var checkout = root.querySelector('[data-cart-checkout]'), panel = root.querySelector('.dhc__panel');
  var wall = root.querySelector('.dhr-wall');   /* 비회원 가입 안내 — 담긴 것이 있을 때만 */
  var nonce = D.nonce || '', unit = 0, opener = null, cartUrl = D.cartUrl || '/cart/';
  var won = function(v){ var x = Number(v) / Math.pow(10, unit); return (isFinite(x) ? Math.round(x) : 0).toLocaleString('ko-KR') + '원'; };
  var esc = function(s){ return String(s == null ? '' : s).replace(/[&<>"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); };
  var strip = function(s){ return String(s == null ? '' : s).replace(/<[^>]*>/g, '').trim(); };

  function badge(n){
    document.querySelectorAll('.gnb .gi.wide').forEach(function(a){
      var b = a.querySelector('.b'), l = a.querySelector('.lbl');
      if(b){ b.textContent = n; b.style.display = n ? '' : 'none'; }
      if(l){ l.textContent = n ? n + '개' : '장바구니'; }
    });
  }
  function render(c){
    var items = (c && c.items) || [], n = c ? (c.items_count || 0) : 0;
    unit = (c && c.totals && c.totals.currency_minor_unit) || 0;
    count.textContent = n ? n : '';
    total.textContent = won(c && c.totals ? c.totals.total_items : 0);
    if(!items.length){ list.innerHTML = '<p class="dhc__empty">담긴 상품이 없습니다.<br><a href="' + esc(D.shopUrl || '/shop/') + '">상품 보러 가기</a></p>'; }
    else list.innerHTML = items.map(function(it){
      var img = D.loggedIn && it.images && it.images[0] ? '<img src="' + esc(it.images[0].thumbnail) + '" alt="" loading="lazy">' : '<i></i>';
      var opts = (it.item_data || []).map(function(d){ return strip(d.display || d.value); }).filter(Boolean);
      return '<div class="dhc__it" data-key="' + esc(it.key) + '"><a class="dhc__im" href="' + esc(it.permalink) + '">' + img + '</a>'
        + '<div class="dhc__tx"><a class="dhc__nm" href="' + esc(it.permalink) + '">' + esc(strip(it.name)) + '</a>'
        + (opts.length ? '<p class="dhc__op">' + esc(opts.join(' · ')) + '</p>' : '')
        + '<div class="dhc__row"><span>수량 ' + esc(it.quantity) + '</span><b class="n">' + won(it.totals && it.totals.line_total) + '</b></div></div>'
        + '<button type="button" class="dhc__rm" data-cart-remove aria-label="' + esc(strip(it.name)) + ' 빼기">×</button></div>';
    }).join('');
    var goal = Number(D.freeShip || 0), sub = c && c.totals ? Number(c.totals.total_items) / Math.pow(10, unit) : 0;
    if(goal && items.length){
      ship.hidden = false; var left = goal - sub;
      shipT.innerHTML = left > 0 ? '<b>' + left.toLocaleString('ko-KR') + '원</b> 더 담으면 무료배송' : '<b>무료배송</b> 조건을 채웠어요';
      shipF.style.width = Math.min(100, sub / goal * 100) + '%'; ship.classList.toggle('is-ok', left <= 0);
    } else ship.hidden = true;
    if(checkout){ checkout.classList.toggle('is-off', !items.length); checkout.setAttribute('aria-disabled', items.length ? 'false' : 'true'); }
    if(wall) wall.style.display = items.length ? '' : 'none';   /* 빈 장바구니에 「가입하고 주문하기」는 할 말이 아니다 */
    badge(n);
  }
  function load(){
    list.classList.add('is-busy');
    return fetch('/wp-json/wc/store/v1/cart', {credentials:'include', headers: nonce ? {'Nonce': nonce} : {}}).then(function(r){
      var h = r.headers.get('Nonce') || r.headers.get('X-WC-Store-API-Nonce'); if(h) nonce = h;
      if(!r.ok) throw new Error(r.status); return r.json();
    }).then(render).finally(function(){ list.classList.remove('is-busy'); });
  }
  function open(from){
    opener = from || document.activeElement; root.hidden = false; document.body.classList.add('dhc-open');
    requestAnimationFrame(function(){ root.classList.add('on'); var x = root.querySelector('.dhc__x'); if(x) x.focus(); });
    return load().catch(function(){ list.innerHTML = '<p class="dhc__empty">장바구니를 불러오지 못했습니다.<br><a href="' + esc(cartUrl) + '">장바구니 페이지로</a></p>'; });
  }
  function close(){
    root.classList.remove('on'); document.body.classList.remove('dhc-open');
    setTimeout(function(){ root.hidden = true; }, 220);
    if(opener && opener.focus) opener.focus();
  }
  root.addEventListener('click', function(e){
    if(e.target.closest('[data-cart-close]')){ close(); return; }
    var rm = e.target.closest('[data-cart-remove]'); if(!rm) return;
    var it = rm.closest('[data-key]'); if(!it) return; it.classList.add('is-busy'); rm.disabled = true;
    fetch('/wp-json/wc/store/v1/cart/remove-item', {method:'POST', credentials:'include', headers:{'Content-Type':'application/json', 'Nonce': nonce}, body: JSON.stringify({key: it.dataset.key})})
      .then(function(r){ var h = r.headers.get('Nonce'); if(h) nonce = h; if(!r.ok) throw new Error(r.status); return r.json(); })
      .then(render).catch(function(){ it.classList.remove('is-busy'); rm.disabled = false; location.href = cartUrl; });
  });
  document.addEventListener('keydown', function(e){ if(e.key === 'Escape' && !root.hidden) close(); });
  if(checkout) checkout.addEventListener('click', function(e){ if(checkout.classList.contains('is-off')){ e.preventDefault(); } });
  /* 서랍 안에서만 탭이 돈다 */
  panel.addEventListener('keydown', function(e){
    if(e.key !== 'Tab') return;
    var f = panel.querySelectorAll('a[href],button:not([disabled])'); if(!f.length) return;
    var a = f[0], z = f[f.length - 1];
    if(e.shiftKey && document.activeElement === a){ e.preventDefault(); z.focus(); }
    else if(!e.shiftKey && document.activeElement === z){ e.preventDefault(); a.focus(); }
  });

  /* 헤더 · 탭바의 장바구니 → 서랍. 장바구니 페이지 자체에서는 그냥 링크. */
  if(!document.body.classList.contains('woocommerce-cart')){
    document.querySelectorAll('.gnb .gi.wide, nav.tabs a[href*="/cart"]').forEach(function(a){
      a.addEventListener('click', function(e){ if(e.metaKey || e.ctrlKey) return; e.preventDefault(); open(a); });
    });
  }
  /* 담긴 직후 — WooCommerce 알림이 "장바구니에 추가" 를 말하면 서랍을 연다.
     **장바구니 · 주문서에서는 열지 않는다** — 거기까지 온 손님에게 장바구니를 다시
     펴 보이는 것은 길을 막는 것이다 (주문서 제외는 2026-09-14). */
  var msg  = document.querySelector('.woocommerce-message');
  var here = document.body.classList;
  if(msg && /장바구니|cart/i.test(msg.textContent) && !here.contains('woocommerce-cart') && !here.contains('woocommerce-checkout')){
    setTimeout(function(){ open(null); }, 350);
  }
  window.DHR = D; D.openCart = open;
})();

/* 상품 상세 — 아래 고정 구매 줄(모바일). 진짜 버튼은 폼 안의 것이다: 담기 · 결제하기의
   잠김 상태와 글자를 그대로 비추고, 누르면 그 버튼을 대신 누른다. 폼의 버튼이 화면에
   보일 때는 줄을 내린다 — 같은 버튼이 두 번 보이면 안 된다. */
(function(){
  var bar = document.querySelector('[data-buybar]'), form = document.querySelector('form.cart'); if(!bar || !form) return;
  var add = form.querySelector('.single_add_to_cart_button'), buy = form.querySelector('.wd-direct-checkout-btn');
  if(!add) return;
  var tot = bar.querySelector('[data-bar-total]'), bCart = bar.querySelector('[data-bar-cart]'), bBuy = bar.querySelector('[data-bar-buy]');
  var price = Number(bar.dataset.price || 0), qty = form.querySelector('input.qty');
  var won = function(v){ return Math.round(v).toLocaleString('ko-KR') + '원'; };
  function sync(){
    var off = !!add.disabled || add.classList.contains('vf-btn-disabled') || add.classList.contains('disabled');
    bar.classList.toggle('is-off', off);
    var sum = form.querySelector('.dhx-sum__total, .wd-option-builder-total');
    var t = sum ? sum.textContent.trim() : '';
    if(t && !/^0\s*원?$/.test(t)) tot.textContent = t;
    else if(price) tot.textContent = won(price * (qty ? Math.max(1, Number(qty.value) || 1) : 1));
    else tot.textContent = '—';
    bBuy.textContent = off ? (sum ? '옵션을 골라 주세요' : add.textContent.trim() || '결제하기') : (buy ? '결제하기' : '장바구니에 담기');
    bCart.hidden = !buy;
  }
  /* 아직 고르지 않았으면 맨 위로 되돌리지 않는다 — 구매 카드를 아래에서 올린다 */
  function sheet(){ return window.DHR && window.DHR.openBuySheet && window.DHR.openBuySheet(); }
  function hit(real){
    if(bar.classList.contains('is-off')){
      if(sheet()) return;
      var box = form.querySelector('.dhx') || form; box.scrollIntoView({behavior:'smooth', block:'start'});
      bar.classList.add('nudge'); setTimeout(function(){ bar.classList.remove('nudge'); }, 700); return;
    }
    real.click();
  }
  bCart.addEventListener('click', function(){ hit(add); });
  bBuy.addEventListener('click', function(){ hit(buy || add); });
  var bOpen = bar.querySelector('[data-bar-open]');
  if(bOpen){ bOpen.addEventListener('click', function(){ if(!sheet()){ form.scrollIntoView({behavior:'smooth', block:'start'}); } }); }
  new MutationObserver(sync).observe(form, {subtree:true, childList:true, attributes:true, attributeFilter:['disabled','class'], characterData:true});
  form.addEventListener('input', sync); form.addEventListener('change', sync);
  /* 구매 줄은 **구매 상자를 지나쳐 내려갔을 때만** 올린다. 맨 위에서부터 떠 있으면
     아직 보지도 않은 버튼이 화면을 가리고, 상자가 화면 아래에 있다는 사실도 감춘다.
     상자가 화면 위로 지나갔는지(bottom < 0)로 판단한다 — 아래에 있을 때는 올리지 않는다. */
  var anchor = form.querySelector('.vf-drawer-actions') || add;
  function above(){ return anchor.getBoundingClientRect().bottom < 0; }
  if('IntersectionObserver' in window){
    new IntersectionObserver(function(en){
      bar.classList.toggle('is-away', !en[0].isIntersecting && above());
    }, {threshold: 0.2}).observe(anchor);
    /* 관찰자는 경계를 넘을 때만 깨어난다 — 아주 빠른 스크롤이나 되돌아온 화면을 위해 한 번 더 본다 */
    var t2 = false;
    window.addEventListener('scroll', function(){
      if(t2) return; t2 = true;
      requestAnimationFrame(function(){ t2 = false;
        var r = anchor.getBoundingClientRect();
        bar.classList.toggle('is-away', r.bottom < 0);
      });
    }, {passive: true});
  } else bar.classList.add('is-away');
  sync(); bar.hidden = false;
})();

/* 카루셀 — Swiper 11. 카드 폭은 CSS 가 정한다(auto). 없으면 CSS 가로 스크롤로 남는다. */
(function(){
  if(!window.Swiper) return;
  document.querySelectorAll('.dhs-wrap').forEach(function(w){
    var el = w.querySelector('.dhs'); if(!el) return;
    new Swiper(el, { slidesPerView: 'auto', spaceBetween: 14, speed: 750, grabCursor: true,
      keyboard: { enabled: true, onlyInViewport: true },
      navigation: { nextEl: w.querySelector('.dhs-next'), prevEl: w.querySelector('.dhs-prev') },
      breakpoints: { 880: { spaceBetween: 18 } } });
    w.classList.add('is-ready');
  });
})();

/* 등장 — 스크롤에 맞춰 카드 · 섹션 머리가 아래에서 위로. 한 번만, 동작 줄이기면 안 한다.
   CSS 는 아무것도 감추지 않는다: 스크립트가 없으면 그냥 다 보인다. */
(function(){
  if(!window.gsap || !window.ScrollTrigger) return;
  gsap.registerPlugin(ScrollTrigger);
  gsap.matchMedia().add('(prefers-reduced-motion: no-preference)', function(){
    var targets = gsap.utils.toArray('.dhr .grid > .card, .dhr .dhs .card, .dhr .sh, .dhr .bcard, .dhr .qcats a, .dhr .deals-h, .dhr .banner > div');
    if(!targets.length) return;
    gsap.set(targets, { y: 36, opacity: 0 });
    ScrollTrigger.batch(targets, { start: 'top 92%', once: true, batchMax: 8,
      onEnter: function(b){ gsap.to(b, { y: 0, opacity: 1, duration: 1.1, ease: 'expo.out', stagger: .07, overwrite: true,
        onComplete: function(){ gsap.set(b, { clearProps: 'transform,opacity' }); } }); } });
    /* 화면에 이미 들어와 있는 것은 바로 */
    ScrollTrigger.refresh();
  });
})();

/* 구매 서랍 (데스크톱) — 상세가 길어서 주문하려면 맨 위로 돌아가야 했다.
   구매 카드가 화면 밖으로 나가면 그 카드 자체를 오른쪽 고정 서랍으로 바꾼다.
   폼을 복제하지 않는다 — PPOM · 키플 옵션이 붙은 진짜 폼이라 복제하면 값이 갈린다.
   자리는 바깥 wrap 이 그 높이를 기억해 메운다. 닫으면 작은 '구매하기' 알약만 남는다. */
(function(){
  var card = document.querySelector('[data-dock]'), wrap = document.querySelector('[data-dockwrap]');
  var open = document.querySelector('[data-dock-open]');
  if(!card || !wrap || !open) return;
  var wide = window.matchMedia('(min-width: 900px)'), shut = false, docked = false, onSheet = false;
  var dim = document.querySelector('[data-sheet-dim]');

  /* 모바일 — 같은 카드를 아래에서 올라오는 시트로 만든다. 데스크톱 서랍과 똑같이
     **폼을 복제하지 않는다**: 진짜 폼이 그대로 올라오므로 고른 옵션이 유지되고,
     구매 게이트가 읽는 칸 이름도 폼 안쪽이라 바뀌지 않는다. */
  function openSheet(){
    if(wide.matches) return false;
    if(onSheet) return true;
    wrap.style.minHeight = wrap.getBoundingClientRect().height + 'px';
    onSheet = true;
    card.classList.add('is-sheet');
    document.body.classList.add('dhp-sheet-on');
    if(dim) dim.hidden = false;
    card.scrollTop = 0;
    return true;
  }
  function closeSheet(){
    if(!onSheet) return false;
    onSheet = false;
    card.classList.remove('is-sheet');
    document.body.classList.remove('dhp-sheet-on');
    if(dim) dim.hidden = true;
    wrap.style.minHeight = '';
    return true;
  }
  window.DHR = window.DHR || {};
  window.DHR.openBuySheet = openSheet;
  if(dim) dim.addEventListener('click', closeSheet);
  document.addEventListener('keydown', function(e){ if(e.key === 'Escape') closeSheet(); });
  /* 담기 · 결제하기를 누르면 시트는 할 일을 마쳤다 */
  card.addEventListener('click', function(e){
    if(e.target.closest('.single_add_to_cart_button, .wd-direct-checkout-btn')) setTimeout(closeSheet, 60);
  });

  function undock(){
    if(!docked) return; docked = false;
    card.classList.remove('is-docked'); wrap.style.minHeight = ''; open.hidden = true;
  }
  function dock(){
    if(docked || shut || !wide.matches) return;
    wrap.style.minHeight = wrap.getBoundingClientRect().height + 'px';
    docked = true; card.classList.add('is-docked');
  }
  function paint(){
    if(!wide.matches){ undock(); return; }
    /* 카드가 놓인 자리(wrap)가 화면 위로 지나갔으면 서랍으로 */
    var r = wrap.getBoundingClientRect();
    var gone = r.bottom < 140;
    if(gone && !shut) dock();
    else if(!gone){ undock(); shut = false; }
    else if(gone && shut){ open.hidden = false; }
  }
  var tick = false;
  window.addEventListener('scroll', function(){ if(!tick){ tick = true; requestAnimationFrame(function(){ tick = false; paint(); }); } }, {passive:true});
  window.addEventListener('resize', function(){ undock(); closeSheet(); paint(); });
  card.querySelector('[data-dock-close]').addEventListener('click', function(){
    if(closeSheet()) return;
    shut = true; undock(); open.hidden = false;
  });
  open.addEventListener('click', function(){ shut = false; open.hidden = true; dock(); card.querySelector('select, button, input') && card.querySelector('select, button, input').focus(); });
  paint();
})();

/* 입체감 — 카드가 마우스를 따라 아주 조금 기운다. 3.2도를 넘기지 않는다:
   그 이상은 상품 사진이 찌그러져 보인다. transform 만 건드려 그리기 비용이 없고,
   손가락 화면·동작 줄이기에서는 아예 걸지 않는다. */
(function(){
  if(!window.matchMedia) return;
  if(!window.matchMedia('(hover: hover) and (pointer: fine)').matches) return;
  if(window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  var MAX = 3.2;
  document.querySelectorAll('.dhr .card, .dhr .bcard, .dhr .qcats a, .dhr .hcard-b').forEach(function(el){
    var raf = null, rx = 0, ry = 0;
    el.addEventListener('pointermove', function(e){
      var r = el.getBoundingClientRect();
      ry = ((e.clientX - r.left) / r.width - .5) * MAX * 2;
      rx = -((e.clientY - r.top) / r.height - .5) * MAX * 2;
      if(raf) return;
      raf = requestAnimationFrame(function(){ raf = null; el.style.setProperty('--rx', rx.toFixed(2) + 'deg'); el.style.setProperty('--ry', ry.toFixed(2) + 'deg'); });
    });
    el.addEventListener('pointerleave', function(){ el.style.setProperty('--rx', '0deg'); el.style.setProperty('--ry', '0deg'); });
  });
})();

/* 선택창 — 브라우저가 그리는 목록은 CSS 로 바꿀 수 없다. 원본 select 는 값을 쥔 채
   1px 로 접어 두고(옛 프론트의 .dhx-src 와 같은 방식), 그 위에 우리 목록을 얹는다.
   **name · value · change 이벤트는 그대로다** — 입금 자동매칭과 PPOM 이 그 경로로 붙어 있다.

   버튼(<button>)으로 만들지 않는다: 테마 스크립트가 form.cart 안의 버튼을 전부 걷어
   자기 구매 줄로 옮기고 라벨을 .text() 로 덮어쓴다. 한 번 그렇게 당해 목록이 비었다.
   그래서 role 만 준 div 로 짓는다. 묶음 상품은 옛 옵션 UI(.dhx)가 이미 같은 일을 한다. */
(function(){
  var form = document.querySelector('.dhp-card--form form.cart') || document.querySelector('form.cart');
  if(!form) return;
  var seq = 0;

  /* "브이메이트V4팟 0.7옴(2EA) [+7,000원]" → 이름과 값을 갈라 오른쪽에 값을 세운다 */
  function split(text){
    var m = String(text).match(/^(.*?)\s*\[\s*(\+?[\d,]+\s*원?)\s*\]\s*$/);
    return m ? { name: m[1].trim(), price: m[2].replace(/\s+/g, '') } : { name: String(text).trim(), price: '' };
  }
  function el(tag, cls){ var n = document.createElement(tag); n.className = cls; return n; }
  function svg(d, cls){
    var s = '<svg class="' + cls + '" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor"'
      + ' stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="' + d + '"/></svg>';
    var w = document.createElement('span'); w.className = cls + '-w'; w.innerHTML = s; return w.firstChild;
  }

  /* 사장님 스니펫(vf 구매 게이트)은 각 select 의 **조상 텍스트**로 그 칸이 무슨 칸인지
     판단한다 — 위로 올라가다 한글이 2자 이상 나오는 조상에서 멈추고, 거기서 <select> 만
     지운 나머지 글자를 칸 이름으로 쓴다. 그래서 select 옆에 글자를 하나라도 더 놓으면
     칸 이름이 바뀌어 버린다.

     실제로 그렇게 깨졌다: 우리가 select 를 .dhsel 안으로 옮기고 그 안에 옵션 글자를
     늘어놓자 "기기 함께 구매하기 (할인 특가 · 선택사항)" 이던 칸 이름이 "…조바 기기…"
     가 되어, 「함께 구매」(선택사항) 로 빠져 있던 칸이 **필수**로 잡혔다. 그 칸은 기기를
     사지 않으면 고를 수가 없으니 구매 버튼이 영영 열리지 않았다 (필수 2개 중 1개).

     그래서 이 선택창은 **폼 안에 글자를 남기지 않는다**:
       · select 는 원래 부모 그대로 둔다 (1px 로 접어만 둔다)
       · 고른 값은 텍스트 노드가 아니라 data-l + CSS content 로 그린다
       · 목록은 열 때만 만들어 document.body 로 띄우고 닫으면 지운다
     읽어 주는 것은 aria-label 이 맡는다. */
  function build(sel){
    if(sel.closest('.dhx-src') || sel.closest('.dhx') || sel.dataset.dhsOn) return;
    if(sel.multiple || sel.options.length < 2) return;
    sel.dataset.dhsOn = '1';
    var id = 'dhs-' + (++seq);

    var root = el('div', 'dhsel');
    var trig = el('div', 'dhsel__btn');
    trig.id = id + '-b'; trig.tabIndex = 0;
    trig.setAttribute('role', 'combobox'); trig.setAttribute('aria-haspopup', 'listbox');
    trig.setAttribute('aria-expanded', 'false'); trig.setAttribute('aria-controls', id + '-l');
    var val = el('span', 'dhsel__val'), cost = el('span', 'dhsel__cost n');
    trig.appendChild(val); trig.appendChild(cost); trig.appendChild(svg('m6 9 6 6 6-6', 'dhsel__chev'));
    root.appendChild(trig);

    var wrap = sel.closest('.ppom-field-wrapper, .form-row');
    var lab = wrap && wrap.querySelector('label');
    var fieldName = lab ? lab.textContent.replace(/\*+/g, '').trim() : '';

    var list = null, rows = [];

    /* 고정 위치라 화면 밖으로 나가지 않게 가둔다 — 선택창이 접힌 칸 안에 있거나
       화면 아래쪽에 있을 때 목록이 보이지 않는 자리에 떨어졌다. */
    function place(){
      if(!list) return;
      var r = trig.getBoundingClientRect();
      var w = Math.max(r.width, 220);
      list.style.width = w + 'px';
      list.style.left = Math.round(Math.min(Math.max(8, r.left), innerWidth - w - 8)) + 'px';
      list.style.top = '0px';
      var h = list.offsetHeight;
      var below = innerHeight - r.bottom - 10, above = r.top - 10, top;
      if(h <= below) top = r.bottom + 6;
      else if(h <= above) top = r.top - 6 - h;
      else top = Math.min(Math.max(8, r.bottom + 6), Math.max(8, innerHeight - h - 8));
      list.style.top = Math.round(Math.max(8, top)) + 'px';
    }

    function make(){
      list = el('div', 'dhsel__list dhsel__list--pop');
      list.id = id + '-l'; list.setAttribute('role', 'listbox');
      rows = [];
      [].forEach.call(sel.options, function(o, i){
        var d = split(o.textContent);
        var r = el('div', 'dhsel__opt');
        r.setAttribute('role', 'option'); r.dataset.i = i; r.tabIndex = -1;
        var nm = el('span', 'dhsel__nm'); nm.textContent = d.name; r.appendChild(nm);
        if(d.price){ var pr = el('span', 'dhsel__pr n'); pr.textContent = d.price; r.appendChild(pr); }
        r.appendChild(svg('m5 12.5 5 5 9.5-11', 'dhsel__tick'));
        if(o.disabled){ r.setAttribute('aria-disabled', 'true'); r.classList.add('is-off'); }
        var on = i === sel.selectedIndex;
        r.classList.toggle('on', on); r.setAttribute('aria-selected', on ? 'true' : 'false');
        list.appendChild(r); rows.push(r);
      });
      list.addEventListener('click', function(e){ var r = e.target.closest('.dhsel__opt'); if(r) pick(+r.dataset.i); });
      list.addEventListener('keydown', keys);
      document.body.appendChild(list);
      place();
    }

    /* 값은 글자가 아니라 속성으로 — 폼 안 텍스트를 늘리지 않는다 */
    function paint(){
      var o = sel.options[sel.selectedIndex] || sel.options[0];
      var d = split(o ? o.textContent : '');
      val.dataset.l = d.name; cost.dataset.l = d.price;
      root.classList.toggle('is-set', sel.selectedIndex > 0);
      trig.setAttribute('aria-label', (fieldName ? fieldName + ': ' : '') + d.name + (d.price ? ' ' + d.price : ''));
      if(list){ rows.forEach(function(r, i){
        var on = i === sel.selectedIndex;
        r.classList.toggle('on', on); r.setAttribute('aria-selected', on ? 'true' : 'false');
      }); }
    }
    function open(){
      if(list) return;
      make(); root.classList.add('is-open'); trig.setAttribute('aria-expanded', 'true');
      addEventListener('scroll', place, true); addEventListener('resize', place);
      var cur = rows[sel.selectedIndex] || rows[0];
      if(cur){ cur.focus(); cur.scrollIntoView({block: 'nearest'}); }
    }
    function close(back){
      if(!list) return;
      removeEventListener('scroll', place, true); removeEventListener('resize', place);
      list.remove(); list = null; rows = [];
      root.classList.remove('is-open'); trig.setAttribute('aria-expanded', 'false');
      if(back) trig.focus();
    }
    function pick(i){
      if(rows[i] && rows[i].classList.contains('is-off')) return;
      if(sel.selectedIndex !== i){
        sel.selectedIndex = i;
        /* 값이 바뀌었다는 사실을 원본 경로로 알린다 — PPOM · 테마 계산이 여기에 붙어 있다.
           change 는 **한 번만** 쏜다. jQuery 로 한 번 더 쏘면 테마가 같은 옵션을 두 번
           담아 수량이 2가 된다 (jQuery 위임 핸들러는 네이티브 이벤트로도 깨어난다). */
        sel.dispatchEvent(new Event('input', {bubbles: true}));
        sel.dispatchEvent(new Event('change', {bubbles: true}));
      }
      close(true); paint();
      /* 테마(wd-option-builder)는 고른 것을 아래 목록에 카드로 쌓고 50ms 뒤 선택창을
         "선택해주세요" 로 되돌린다 — 값은 그쪽 숨은 필드가 쥔다. 원래 select 가 그렇게
         돌아가므로 우리 선택창도 같이 돌아가야 한다. */
      setTimeout(paint, 120); setTimeout(paint, 500);
    }
    function keys(e){
      var at = rows.indexOf(document.activeElement);
      if(e.key === 'Enter' || e.key === ' '){ e.preventDefault(); if(at >= 0) pick(at); }
      else if(e.key === 'Escape'){ e.preventDefault(); close(true); }
      else if(e.key === 'ArrowDown'){ e.preventDefault(); (rows[at + 1] || rows[0]).focus(); }
      else if(e.key === 'ArrowUp'){ e.preventDefault(); (rows[at - 1] || rows[rows.length - 1]).focus(); }
      else if(e.key === 'Home'){ e.preventDefault(); rows[0].focus(); }
      else if(e.key === 'End'){ e.preventDefault(); rows[rows.length - 1].focus(); }
      else if(e.key === 'Tab'){ close(false); }
    }

    trig.addEventListener('click', function(){ list ? close(true) : open(); });
    trig.addEventListener('keydown', function(e){
      if(e.key === 'Enter' || e.key === ' ' || e.key === 'ArrowDown' || e.key === 'ArrowUp'){ e.preventDefault(); open(); }
    });
    document.addEventListener('click', function(e){
      if(root.contains(e.target) || (list && list.contains(e.target))) return;
      close(false);
    });
    /* 다른 스크립트가 값을 바꿔도 따라 그린다 */
    sel.addEventListener('change', paint);

    sel.classList.add('dhsel-src');
    sel.setAttribute('tabindex', '-1');
    sel.setAttribute('aria-hidden', 'true');
    /* select 는 원래 부모에 그대로 둔다. 옮기면 스니펫의 칸 이름 계산이 어긋난다. */
    sel.parentNode.insertBefore(root, sel);
    paint();
  }

  /* 옛 옵션 UI(.dhx)가 있는 화면 — 묶음 · 이벤트 상품 — 에서는 아예 만들지 않는다.
     거기서는 .dhx 가 눈에 보이는 UI 를 그리고 우리 선택창은 34px 로 찌그러져 보이지도
     않았다. 보이지도 않으면서 칸 이름 판정만 어긋나게 했다.
     .dhx 는 그쪽 스크립트가 DOMContentLoaded 에서 만든다 — 우리는 푸터에서 그보다 먼저
     돌기 때문에, 그때 찾으면 늘 없다. **load 까지 기다렸다가** 본다. */
  function init(){
    if(document.querySelector('.dhx')) return;
    form.querySelectorAll('select.ppom-input, .ppom-field-wrapper select').forEach(build);
  }
  if(document.readyState === 'complete') init();
  else addEventListener('load', init);
})();

/* 묶음 "다시 누르면 해지" — 옛 옵션 UI(dh-option-ui)의 안내대로 동작하지 않았다.
   골라 둔 줄을 다시 눌러도 그대로 남는다. 그 플러그인의 상태를 우리가 다시 짜지 않고,
   같은 화면에 있는 수량 − 버튼을 0까지 눌러 준다 — 해지는 그쪽이 스스로 한다. */
(function(){
  document.addEventListener('click', function(e){
    var pick = e.target.closest('.dhx-bundle__pick');
    if(!pick) return;
    var row = pick.closest('.dhx-bundle');
    if(!row || !row.classList.contains('is-on')) return;
    e.preventDefault(); e.stopImmediatePropagation();
    var minus = row.querySelector('.dhx-qty button');
    if(!minus) return;
    var guard = 0;
    (function step(){
      if(guard++ > 40 || !row.classList.contains('is-on')) return;
      var n = row.querySelector('.dhx-qty__n');
      if(n && Number(n.textContent) <= 0) return;
      minus.click();
      setTimeout(step, 70);
    })();
  }, true);
})();

/* 값이 붙지 않는 선택은 세트 수만큼만 ─────────────────────────────────────
   "젤로크리스탈 기기 + 액상 5병 증정" 상품의 3번 카드는 기기 **색**을 고르는 칸이다
   (실버 · 블랙, 둘 다 +0원). 그런데 수량을 3까지 올릴 수 있었고 총액은 69,000원 그대로였다 —
   기기 두 대를 공짜로 담을 수 있었다는 뜻이다. 사장님 게이트 스니펫은 맛(addon_1)이
   세트당 5개인지만 보고 그 뒤 칸에는 상한이 없다.
   테마 · 옛 옵션 UI 는 건드리지 않고, + 를 capture 단계에서 가로챈다.
   값(+N원)이 붙은 줄이 하나라도 있는 카드는 **돈을 더 내고 사는 추가 구매**이므로 그대로 둔다
   (팟 · 코일 · 기기 함께 구매). 상한은 세트당 1개 — 필터 `duckhoo_free_choice_per_set`.
   안내 글자는 `data-l` + CSS 로 그린다. 폼 안에 텍스트 노드를 더하면 구매 게이트가 읽는
   칸 이름이 바뀔 수 있어서다 (한 번 그렇게 막혔다). */
(function(){
  var PER = window.DHR && window.DHR.freeChoicePerSet != null ? Number(window.DHR.freeChoicePerSet) : 1;
  if(!PER) return;
  var SWAP = false;

  function num(row){ var n = row.querySelector('.dhx-qty__n'); return n ? (parseInt(n.textContent, 10) || 0) : 0; }

  /* 세트 수 — 테마가 쥔 값이 정답이다. 못 읽으면 1번 카드의 숫자를 센다. */
  function sets(){
    var i = document.querySelector('form.cart input[name="wd_option_builder_json"]');
    if(i && i.value){
      try{
        var n = 0;
        JSON.parse(i.value).forEach(function(r){ if(r && r.type === 'required') n += parseInt(r.qty, 10) || 0; });
        if(n > 0) return n;
      }catch(err){}
    }
    var d = 0;
    document.querySelectorAll('.dhx-bundle').forEach(function(r){ d += num(r); });
    return d;
  }

  /* 줄마다 값이 안 붙은 카드 = 고르는 칸(색상 · 구성). 하나라도 값이 붙으면 유료 추가다. */
  function isChoice(card){
    var rows = card.querySelectorAll('.dhx-row');
    if(!rows.length) return false;
    for(var i = 0; i < rows.length; i++){ if(rows[i].querySelector('.dhx-row__price')) return false; }
    return true;
  }
  function total(card){
    var t = 0;
    card.querySelectorAll('.dhx-row').forEach(function(r){ t += num(r); });
    return t;
  }
  function cap(){ return Math.max(1, sets()) * PER; }
  function title(card){ var t = card.querySelector('.dhx-card__title'); return t ? t.textContent.trim() : '이 항목'; }

  function say(card, max){
    var inner = card.querySelector('.dhx-card__inner') || card;
    var n = inner.querySelector('.dhr-onenote');
    if(!n){ n = document.createElement('p'); n.className = 'dhr-onenote'; n.setAttribute('role', 'status'); inner.appendChild(n); }
    n.setAttribute('data-l', '세트당 ' + max + '개까지 고를 수 있습니다. 바꾸려면 고른 것을 − 로 내려주세요.');
    n.classList.add('is-on');
    clearTimeout(n._t);
    n._t = setTimeout(function(){ n.classList.remove('is-on'); }, 4000);
  }

  /* 색을 바꾸는 것이므로 차 있는 줄을 0까지 내리고 이쪽을 올린다.
     내리는 것은 그쪽 스크립트가 하게 두고 우리는 − 를 눌러 줄 뿐이다. */
  function swap(card, row){
    SWAP = true;
    var guard = 0;
    (function step(){
      if(guard++ > 40){ SWAP = false; return; }
      var busy = null;
      card.querySelectorAll('.dhx-row').forEach(function(r){ if(!busy && r !== row && num(r) > 0) busy = r; });
      if(busy){ busy.querySelector('.dhx-qty button').click(); setTimeout(step, 300); return; }
      var plus = row.querySelector('.dhx-qty button:last-child');
      if(plus) plus.click();
      setTimeout(function(){ SWAP = false; }, 500);
    })();
  }

  document.addEventListener('click', function(e){
    if(SWAP) return;
    var btn = e.target.closest && e.target.closest('.dhx-row .dhx-qty button');
    if(!btn || btn !== btn.parentElement.lastElementChild) return;   /* + 만 */
    var card = btn.closest('.dhx-card');
    if(!card || !isChoice(card)) return;
    var max = cap();
    if(total(card) < max) return;
    e.preventDefault(); e.stopPropagation();
    if(e.stopImmediatePropagation) e.stopImmediatePropagation();
    var row = btn.closest('.dhx-row');
    if(num(row) > 0 || max > 1){ say(card, max); return; }
    swap(card, row);
  }, true);

  /* 마지막 빗장 — 어떤 경로로든 상한을 넘긴 채 담기까지 가지 않게 한다.
     담는 데이터에는 손대지 않는다. 무엇을 고쳐야 하는지만 말하고 멈춘다. */
  document.addEventListener('click', function(e){
    var b = e.target.closest && e.target.closest('.single_add_to_cart_button, .wd-direct-checkout-btn');
    if(!b) return;
    var max = cap(), bad = null;
    document.querySelectorAll('.dhx-card').forEach(function(c){
      if(!bad && isChoice(c) && total(c) > max) bad = c;
    });
    if(!bad) return;
    e.preventDefault(); e.stopPropagation();
    if(e.stopImmediatePropagation) e.stopImmediatePropagation();
    say(bad, max);
    alert(title(bad) + ' 은(는) ' + max + '개까지만 고를 수 있습니다. (현재 ' + total(bad) + '개)');
  }, true);
})();


/* 단품에서 「고르는 칸」은 사는 개수만큼만 ───────────────────────────────────
   `젤로 크리스탈 0.6옴 팟 [블랙/클리어]` 는 **색**을 고르는 칸인데 클리어와 블랙을
   둘 다 고를 수 있었고 총액은 10,000원 그대로였다 (2026-09-14 재현). 주문 데이터에는
   두 줄이 그대로 실린다:
     [{group_key:"addon_pod_0", label:"…클리어팟", qty:1, unit_price:0, type:"addon"},
      {group_key:"addon_pod_0", label:"…블랙팟",   qty:1, unit_price:0, type:"addon"},
      {group_key:"required_main", …, unit_price:10000}]
   팟 두 개를 한 값에 담을 수 있고, 손님도 「둘 다 오는 건가」 하고 헷갈린다.

   위 `.dhx` 규칙과 **같은 생각, 다른 화면**이다. 저쪽은 옛 옵션 UI(묶음 · 이벤트)를
   보고, 이쪽은 테마 옵션 빌더가 직접 그리는 단품 화면을 본다.
   **`.dhx` 가 있으면 물러난다** — 묶음의 맛 칸은 세트당 5개가 정상이라 여기서 잡으면
   안 된다 (확인: 디오 5+5 는 `.dhx-card` 4개, 이 팟은 0개).

   상한은 세트 수(`type:required` 의 수량) × `freeChoicePerSet`. 그래서 2팩을 사면
   색도 두 개 고를 수 있다 — 막는 규칙이 아니라 **고른 개수 = 사는 개수** 규칙이다.
   테마 · 스니펫은 건드리지 않는다. 마지막에 고른 것을 남기고 먼저 고른 줄의 `×` 를
   대신 눌러 준다 (= 색을 바꿔 준다). 안내 글자는 `data-l` + CSS 로 그린다 — 폼 안에
   텍스트 노드를 더하면 구매 게이트가 읽는 칸 이름이 바뀐다. */
(function(){
  var PER = window.DHR && window.DHR.freeChoicePerSet != null ? Number(window.DHR.freeChoicePerSet) : 1;
  if(!PER) return;
  var BUSY = false, TIMER = null;

  function rows(){
    var i = document.querySelector('form.cart input[name="wd_option_builder_json"]');
    if(!i || !i.value) return [];
    try{ var a = JSON.parse(i.value); return Array.isArray(a) ? a : []; }catch(err){ return []; }
  }
  function sets(list){
    var n = 0;
    list.forEach(function(r){ if(r && r.type === 'required') n += parseInt(r.qty, 10) || 0; });
    return Math.max(1, n);
  }
  /* 줄마다 값이 0원인 그룹 = 고르는 칸(색 · 맛). 하나라도 값이 붙으면 유료 추가다. */
  function groups(list){
    var g = {};
    list.forEach(function(r){
      if(!r || r.type === 'required' || !r.group_key) return;
      var k = r.group_key;
      g[k] = g[k] || { qty: 0, free: true, rows: [] };
      g[k].qty += parseInt(r.qty, 10) || 0;
      if(Number(r.unit_price) > 0) g[k].free = false;
      g[k].rows.push(r);
    });
    return g;
  }
  function over(){
    if(document.querySelector('.dhx')) return null;   /* 묶음은 위 규칙이 본다 */
    var list = rows();
    if(!list.length) return null;
    var cap = sets(list) * PER, g = groups(list);
    for(var k in g){ if(g[k].free && g[k].qty > cap) return { key: k, cap: cap, box: g[k], excess: g[k].qty - cap }; }
    return null;
  }
  function remover(group, label){
    var out = null;
    document.querySelectorAll('.wd-option-remove').forEach(function(b){
      if(!out && b.getAttribute('data-group') === group && b.getAttribute('data-label') === label) out = b;
    });
    return out;
  }
  /* 안내는 **방금 고른 칸 바로 아래**에 붙는다. 고른 것이 쌓이는 목록(`.wd-option-builder-list`)은
     선택칸보다 **위**에 있어서(측정: 목록 1063px · 선택칸 1400px) 거기에 붙이면 손이 있는 자리에서
     멀고, 화면 밖으로 밀려 못 보는 일이 생긴다. 선택칸이 없을 때만 목록 끝으로 돌아간다. */
  function host(){
    var sel = document.querySelector('form.cart select.ppom-input') || document.querySelector('form.cart select');
    var box = sel ? sel.parentElement : null;
    if(box){
      var trigger = box.querySelector('.dhsel');   /* 우리 선택창이 있으면 그 아래 */
      return { box: box, after: trigger || sel };
    }
    var list = document.querySelector('.wd-option-builder-list') || document.querySelector('.wd-option-builder');
    return list ? { box: list, after: null } : null;
  }
  function say(cap){
    var h = host();
    if(!h) return;
    var n = document.querySelector('.dhr-onenote');
    if(!n){ n = document.createElement('p'); n.className = 'dhr-onenote'; n.setAttribute('role', 'status'); }
    if(n.parentElement !== h.box){
      if(h.after && h.after.parentElement === h.box) h.box.insertBefore(n, h.after.nextSibling);
      else h.box.appendChild(n);
    }
    n.setAttribute('data-l', cap > 1
      ? '지금 수량으로는 ' + cap + '개까지 고를 수 있습니다.'
      : '한 개만 고를 수 있습니다. 방금 고른 것으로 바꿔 드렸어요 — 두 개가 필요하시면 수량을 2로 올려 주세요.');
    n.classList.add('is-on');
    clearTimeout(n._t);
    n._t = setTimeout(function(){ n.classList.remove('is-on'); }, 5000);
  }

  /* 넘친 만큼 **먼저 고른 쪽**을 내린다. 내리는 일은 테마가 하게 두고 버튼만 눌러 준다. */
  function trim(depth){
    if(BUSY || (depth || 0) > 8) return;
    var o = over();
    if(!o) return;
    /* **넘친 만큼만** 내린다. 먼저 고른 줄이 넘친 양보다 크면 그 줄을 지우지 않고
       − 를 눌러 한 칸만 내린다 — 안 그러면 수량 2짜리 줄이 통째로 빠져 필요 이상으로 준다. */
    var first = o.box.rows[0] || {};
    var one   = remover(o.key, first.label || '');
    var btn   = one;
    if(one && (parseInt(first.qty, 10) || 0) > o.excess){
      var item = one.closest ? one.closest('.wd-option-item') : null;
      var minus = item ? item.querySelector('[class*="minus"]') : null;
      if(minus) btn = minus;
    }
    if(!btn) return;
    BUSY = true;
    btn.click();
    setTimeout(function(){ BUSY = false; say(o.cap); trim((depth || 0) + 1); }, 260);
  }
  /* **한 번만 보면 놓친다.** `change` 는 테마가 목록 · 숨은 필드를 고치기 **전에** 오고,
     테마가 옵션 상자를 통째로 다시 그리면 거기 걸어 둔 관찰자도 같이 떨어져 나간다.
     그래서 몸통(body)을 보고, 고른 뒤에도 세 번 더 다시 본다 (한 번 이 때문에 안 줄어들었다). */
  function soon(){
    clearTimeout(TIMER);
    TIMER = setTimeout(function(){ trim(0); }, 250);
    [800, 1600].forEach(function(ms){ setTimeout(function(){ trim(0); }, ms); });
  }

  function watch(){
    if(watch.on) return;
    watch.on = true;
    new MutationObserver(soon).observe(document.body, { childList: true, subtree: true });
    document.addEventListener('change', soon, true);
    soon();
  }
  if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', watch);
  else watch();
  window.addEventListener('load', watch);

  /* 마지막 빗장 — 어떤 길로든 넘긴 채 담기까지 가지 않게 한다.
     담는 데이터에는 손대지 않고 무엇을 고쳐야 하는지만 말한다. */
  document.addEventListener('click', function(e){
    var b = e.target.closest && e.target.closest('.single_add_to_cart_button, .wd-direct-checkout-btn');
    if(!b) return;
    var o = over();
    if(!o) return;
    e.preventDefault(); e.stopPropagation();
    if(e.stopImmediatePropagation) e.stopImmediatePropagation();
    say(o.cap);
    trim(0);
    alert('고르신 것이 ' + o.box.qty + '개입니다. 지금 수량으로는 ' + o.cap + '개까지 담을 수 있어요.');
  }, true);
})();


/* 「쿠폰 받기」가 워드프레스 관리자 로그인으로 튄다 ──────────────────────────
   키플 쿠폰 플러그인은 비로그인이 버튼을 누르면 `KeypleCoupon.login_url`
   (= `wp-login.php?redirect_to=…`) 로 보낸다. 손님 눈에는 사이트가 통째로 다른 곳으로
   튄 것처럼 보이고 브랜드도 회원가입으로 가는 길도 없다 — **1:1 문의에서 고친 그 문제**다.

   플러그인은 건드리지 않고 그 값 하나만 우리 로그인 화면으로 갈아 끼운다. 돌아올 곳은
   지금 보고 있는 화면이다 (로그인 폼의 숨은 `redirect` 필드가 그리로 되돌린다). */
(function(){
  var K = window.KeypleCoupon, base = window.DHR && window.DHR.loginUrl;
  if(!K || !base || !K.login_url || /\/my-account/.test(String(K.login_url))) return;
  K.login_url = base + (base.indexOf('?') < 0 ? '?' : '&') + 'redirect_to=' + encodeURIComponent(location.href);
})();

/* 결제 화면의 쿠폰 카드가 아무 일도 하지 않았다 ───────────────────────────────
   테마 `assets/js/wd-checkout-custom.js` 는 `jQuery(function ($) { … })` 로 열리는데,
   **마지막 28줄(쿠폰 카드 체크박스 핸들러)이 그 래퍼 밖에** 있다 (183줄에서 닫히고
   185줄부터 다시 `$` 를 쓴다). 워드프레스는 전역에 `$` 를 주지 않으므로 그 줄에서
   `$ is not a function` 이 나고 **핸들러가 등록조차 되지 않는다** — 쿠폰함에서 받은
   쿠폰을 결제 화면에서 체크해도 아무 일도 일어나지 않는다 (2026-09-14 확인).

   테마 파일은 건드리지 않는다. `window.$ = jQuery` 로 전역을 만드는 길도 있지만
   (그러면 테마의 그 28줄이 스스로 산다) 전역을 하나 더 만드는 대신 **같은 핸들러를
   우리가 등록**한다. 하는 일은 테마가 하려던 것과 똑같다 — 워드커머스 쿠폰칸에
   코드를 넣고 적용 버튼을 누른다. 쿠폰 계산은 워드커머스가 한다.

   **테마 쪽이 살아나면 우리는 물러난다** (`alive()`). 그래도 둘 다 걸리는 일이
   있을 수 있어 같은 코드를 1.5초 안에 두 번 적용하지 않는다. */
(function(){
  var $ = window.jQuery;
  if(!$) return;
  var last = { code: '', at: 0 };

  /* 테마가 같은 자리에 이미 걸어 두었나 (jQuery 가 document 에 쥔 위임 핸들러를 본다) */
  function alive(){
    try{
      var ev = $._data ? $._data(document, 'events') : null;
      var list = ( ev && ev.change ) || [];
      for(var i = 0; i < list.length; i++){
        if(String(list[i].selector || '').indexOf('wd-checkout-coupon-card') >= 0) return true;
      }
    }catch(err){}
    return false;
  }

  function apply(code){
    var now = Date.now();
    if(code === last.code && now - last.at < 1500) return;   /* 두 번 누르지 않는다 */
    last = { code: code, at: now };
    $("[name='coupon_code']").val(code);
    $("[name='apply_coupon']").trigger('click');
  }

  function arm(){
    if(arm.on) return;
    if(!document.querySelector('.wd-checkout-coupon-card')) return;   /* 결제 화면에만 있다 */
    if(alive()) return;                                              /* 테마가 하면 우리는 안 한다 */
    arm.on = true;
    $(document).on('change.dhrcoupon', ".wd-checkout-coupon-card input[type='checkbox']", function(){
      var $box = $(this), code = String($box.val() || '');
      if(!code) return;
      if($box.is(':checked')){
        $(".wd-checkout-coupon-card input[type='checkbox']").not($box).prop('checked', false);
        apply(code);
      }else{
        last = { code: '', at: 0 };
        $(".woocommerce-remove-coupon[data-coupon='" + code.toLowerCase() + "']").trigger('click');
      }
    });
  }

  /* 테마 스크립트가 먼저 돌 자리를 주고 본다. 결제 화면은 `updated_checkout` 으로
     합계 영역을 통째로 다시 그리므로 그때마다 한 번 더 확인한다. */
  window.addEventListener('load', function(){ setTimeout(arm, 300); });
  if(document.readyState !== 'loading') setTimeout(arm, 800);
  $(document.body).on('updated_checkout', function(){ setTimeout(arm, 100); });
})();

/* **결제 화면의 쿠폰칸이 두 번 헛돌았다** (사장님 영상 · 캡처 2026-09-15).
   코드를 넣고 눌러도 안내도 오류도 없이 `쿠폰할인 − 0원` 그대로였다.

   처음엔 워드커머스 이름(`coupon_code` · `apply_coupon`)에 걸었는데 **결제 화면은
   로그인이 있어야 열려 DOM 을 직접 못 본다.** 캡처를 보니 화면에는 코드 넣는 자리가
   둘이다 — 왼쪽 `쿠폰` 상자와 오른쪽 `상품권이 있나요?` 상자. 이름이 우리가 건 것과
   다르면 우리 핸들러는 아예 안 깨어난다.

   그래서 **이름이 아니라 화면에 보이는 것으로 잡는다**: 조상 글자에 `쿠폰` 이 있고
   `상품권` 은 없는 상자 안의 글자칸 + `적용` 버튼. **상품권 상자는 건드리지 않는다** —
   그쪽은 다른 시스템이고, 우리가 워드커머스 쿠폰으로 보내면 남의 기능을 망가뜨린다.

   넣는 길은 장바구니 쿠폰칸과 같은 Store API `apply-coupon` 이다 (라이브에서 확인된 길).
   끝나면 `update_checkout` 으로 **합계만** 다시 그린다 — 새로고침하면 손님이 적어 둔
   배송 정보가 날아간다. */
(function(){
  var $ = window.jQuery; if(!$) return;
  var here = document.body.classList;
  var isCheckout = here.contains('woocommerce-checkout');
  /* 상품권 칸은 장바구니에도 나올 수 있어 두 화면에서 돈다. 쿠폰칸을 받는 것은 결제뿐 —
     장바구니에는 이미 우리 쿠폰칸(`.dhr-cpn`)이 있어 두 번 걸리면 안 된다 */
  if(!isCheckout && !here.contains('woocommerce-cart')) return;

  var nonce = (window.DHR && window.DHR.nonce) || '', busy = false;

  function seen(el){
    if(!el) return false;
    var r = el.getBoundingClientRect();
    return !!(el.offsetParent || r.width || r.height);
  }
  function words(el){ return (el.textContent || '').replace(/\s+/g, ' ').trim(); }

  /* 쿠폰 코드를 넣는 자리 찾기 — 이름이 아니라 둘레의 글자로 판단한다.

     **버튼에서 시작한다.** 칸에서 시작해 위로 올라가면 배송 주소칸도 「쿠폰」이 적힌
     큰 조상에 걸려 같은 버튼과 짝지어진다 (가짜 화면 시험에서 실제로 그랬다 —
     주소칸이 먼저라 코드 대신 빈 값을 읽었다). 버튼에서 내려오면 짝이 하나로 정해진다. */
  function textIns(el){
    var out = [], all = el.querySelectorAll('input');
    for(var i = 0; i < all.length; i++){
      var t = String(all[i].getAttribute('type') || 'text').toLowerCase();
      if(t !== 'text' && t !== 'search') continue;
      if(!seen(all[i])) continue;
      out.push(all[i]);
    }
    return out;
  }

  function spots(){
    var out = [], used = [];
    if(!isCheckout) return out;
    var cand = document.querySelectorAll('button, input[type="submit"], input[type="button"], a');
    for(var i = 0; i < cand.length; i++){
      var b = cand[i];
      if(!seen(b)) continue;
      var label = (b.tagName === 'INPUT' ? String(b.value || '') : words(b)).trim();
      var strong = /쿠폰\s*적용/.test(label);
      if(!strong && label !== '적용') continue;

      /* 버튼과 가장 가까운, 글자칸이 **하나뿐인** 상자 */
      var row = null, input = null, el = b.parentElement;
      for(var up = 0; up < 4 && el; up++, el = el.parentElement){
        var f = textIns(el);
        if(f.length === 1){ row = el; input = f[0]; break; }
        if(f.length > 1) break;                    /* 애매하면 손대지 않는다 */
      }
      if(!input || used.indexOf(input) >= 0) continue;
      if(input.closest('.dhr-cpn')) continue;        /* 우리 장바구니 쿠폰칸은 제 갈 길이 있다 */

      /* 「쿠폰」 이라고 적힌 상자 — 위로 네 단계까지 본다.
         **「상품권」 은 어느 단계에서 나오든 물러난다** (그쪽은 다른 시스템이다). */
      var card = row, found = null, gift = false;
      for(var u2 = 0; u2 < 4 && card; u2++, card = card.parentElement){
        var t = words(card);
        if(t.length > 600) break;
        /* **더 가까운 이름이 이긴다.** 위로 계속 올라가면 결국 둘 다 든 조상(body)에
           닿아 멀쩡한 쿠폰칸까지 물러나게 된다 — 가짜 화면 시험에서 그랬다 */
        if(t.indexOf('상품권') >= 0){ gift = true; break; }
        if(t.indexOf('쿠폰') >= 0){ found = card; break; }
      }
      if(gift) continue;
      if(!found && !strong) continue;            /* 그냥 「적용」 은 쿠폰이라는 말이 있어야 받는다 */

      used.push(input);
      out.push({ i: input, b: b, row: row, box: found || row });
    }
    return out;
  }

  function plain(t){ var d = document.createElement('textarea'); d.innerHTML = String(t == null ? '' : t).replace(/<[^>]*>/g, ''); return d.value; }
  /* **만든 것은 칸에 매달아 둔다.** 상자를 다시 찾아 `querySelector` 로 뒤지면,
     안내를 상자 **밖**(줄 다음)에 붙인 경우 못 찾아 부를 때마다 새로 만든다 —
     가짜 화면 시험에서 안내가 12개까지 늘었다. */
  function seat(spot, cls, tag){
    var key = '__dhr_' + cls;
    var n = spot.i[key];
    if(n && n.isConnected) return n;
    n = document.createElement(tag);
    n.className = cls;
    if(tag === 'p') n.setAttribute('role', 'status');
    /* **칸이 있는 줄 바로 아래**에 붙인다. 줄 안에 넣으면 칸 · 버튼과 가로로 늘어선다 */
    var after = spot.i['__dhr_dhr-cocpn'] && spot.i['__dhr_dhr-cocpn'].isConnected
      ? spot.i['__dhr_dhr-cocpn'] : (spot.row || spot.box);
    if(after.parentNode) after.parentNode.insertBefore(n, after.nextSibling);
    else spot.box.appendChild(n);
    spot.i[key] = n;
    return n;
  }
  function note(spot){ return seat(spot, 'dhr-cocpn', 'p'); }
  function say(spot, t, bad){ var n = note(spot); n.textContent = plain(t); n.classList.toggle('is-bad', !!bad); }

  function cart(then){
    fetch('/wp-json/wc/store/v1/cart', {credentials:'include', headers: nonce ? {'Nonce': nonce} : {}})
      .then(function(r){ return r.ok ? r.json() : null; }).then(then).catch(function(){ then(null); });
  }

  function post(url, body, spot, ok){
    busy = true; spot.b.disabled = true;
    fetch(url, {
      method:'POST', credentials:'include',
      headers: nonce ? {'Content-Type':'application/json', 'Nonce': nonce} : {'Content-Type':'application/json'},
      body: JSON.stringify(body)
    })
    .then(function(r){
      var h = r.headers.get('Nonce') || r.headers.get('X-WC-Store-API-Nonce'); if(h) nonce = h;
      return r.json().then(function(j){ return { ok: r.ok, j: j }; });
    })
    .then(function(res){
      busy = false; spot.b.disabled = false;
      if(!res.ok){ say(spot, (res.j && res.j.message) || '쿠폰을 적용하지 못했습니다.', true); return; }
      ok(res.j);
    })
    .catch(function(){ busy = false; spot.b.disabled = false; say(spot, '잠시 뒤에 다시 눌러 주세요.', true); });
  }

  /* **주문 요약은 `update_checkout` 으로 안 바뀐다.**
     테마(`wd-checkout-custom.js`)의 `refreshDiscountSummary()` 는 쿠폰 할인액을
     `#wd-summary-coupon-discount` **자기 자신에게서 읽어** 다시 써 넣는다 —
     서버(`form-checkout.php`)가 그린 값을 되쓰는 구조라, 새로고침 전에는 영원히
     `쿠폰할인 − 0원` 이다 (테마 주석에도 「#order_review 테이블이 이 화면에는 없어서」라고 적혀 있다).

     그래서 **쿠폰을 넣은 뒤 그 두 숫자를 우리가 써 넣는다.** 값은 워드커머스가 준 것 그대로다 —
     할인액은 `totals.total_discount`, 총액은 `totals.total_price`(주문에 실제로 잡히는 금액).
     **새로고침하지 않는다** — 손님이 적어 둔 배송 정보가 날아간다.
     손을 댄 뒤에는 테마가 합계를 다시 그릴 때마다(`updated_checkout`) 다시 맞춰 준다. */
  var touched = false;
  function won(n){ return Number(n).toLocaleString('ko-KR') + '원'; }
  function paint(c){
    if(!c || !c.totals) return;
    var t = c.totals, unit = Math.pow(10, t.currency_minor_unit != null ? t.currency_minor_unit : 0);
    var off = Math.round(Number(t.total_discount || 0) / unit);
    var total = Math.round(Number(t.total_price || 0) / unit);
    var el = document.getElementById('wd-summary-coupon-discount');
    if(el) el.textContent = '- ' + won(off);
    var tot = document.querySelector('.wd-summary-total strong');
    if(tot && total > 0) tot.textContent = won(total);
  }

  function apply(spot){
    if(busy) return;
    var code = String(spot.i.value || '').trim();
    if(!code){ say(spot, '코드를 넣어 주세요.', true); spot.i.focus(); return; }
    say(spot, '적용하는 중…');
    cart(function(c){
      var list = (c && c.coupons) || [];
      for(var k = 0; k < list.length; k++){
        if(String(list[k].code).toLowerCase() === code.toLowerCase()){
          say(spot, '이미 적용된 쿠폰입니다.'); show(); return;      /* 두 번 넣지 않는다 */
        }
      }
      post('/wp-json/wc/store/v1/cart/apply-coupon', { code: code }, spot, function(j){
        say(spot, '쿠폰을 적용했습니다.');
        spot.i.value = '';
        touched = true;
        show();
        paint(j);
        $(document.body).trigger('update_checkout');   /* 결제수단 · 배송비는 그쪽이 본다 */
      });
    });
  }

  /* 지금 걸려 있는 쿠폰 — 뺄 수도 있어야 한다 */
  function show(){
    cart(function(c){
      var list = (c && c.coupons) || [];
      spots().forEach(function(spot){
        note(spot);                       /* 안내가 먼저 서야 목록이 그 아래로 간다 */
        var fresh = !(spot.i['__dhr_dhr-cocpn-on'] && spot.i['__dhr_dhr-cocpn-on'].isConnected);
        var ul = seat(spot, 'dhr-cocpn-on', 'ul');
        if(fresh){
          ul.addEventListener('click', function(e){
            var x = e.target.closest('.dhr-cocpn-x'); if(!x || busy) return;
            post('/wp-json/wc/store/v1/cart/remove-coupon', { code: x.getAttribute('data-code') }, spot, function(j){
              say(spot, '쿠폰을 뺐습니다.'); touched = true; show(); paint(j);
              $(document.body).trigger('update_checkout');
            });
          });
        }
        ul.innerHTML = list.map(function(cp){
          var unit = Math.pow(10, (cp.totals && cp.totals.currency_minor_unit != null) ? cp.totals.currency_minor_unit : 0);
          var off = cp.totals && cp.totals.total_discount
            ? Math.round(Number(cp.totals.total_discount) / unit).toLocaleString('ko-KR') + '원 할인' : '적용됨';
          var code = String(cp.code).replace(/[&<>"]/g, '');
          return '<li><b>' + code.toUpperCase() + '</b> <span>' + off + '</span>' +
                 '<button type="button" class="dhr-cocpn-x" data-code="' + code + '">빼기</button></li>';
        }).join('');
      });
    });
  }

  /* 누르는 것은 위임으로 한 번만 건다 — 합계를 다시 그리면 칸이 바뀔 수 있다 */
  document.addEventListener('click', function(e){
    if(!e.target.closest) return;
    var list = spots();
    for(var k = 0; k < list.length; k++){
      if(list[k].b === e.target || list[k].b.contains(e.target)){
        e.preventDefault(); e.stopPropagation();
        apply(list[k]);
        return;
      }
    }
  }, true);

  document.addEventListener('keydown', function(e){
    if(e.key !== 'Enter' || !e.target.closest) return;
    var list = spots();
    for(var k = 0; k < list.length; k++){
      if(list[k].i === e.target){ e.preventDefault(); apply(list[k]); return; }
    }
  }, true);

  /* **「쿠폰이 있으세요? 코드를 입력하려면 여기를 클릭하세요」 알림을 없앤다.**
     워드커머스가 접어 둔 쿠폰 폼을 여는 안내인데, 사장님 스니펫 #21 이 그것을 토스트로
     바꿔 결제 화면에 들어갈 때마다 띄운다. 코드 넣는 자리는 이미 화면에 펼쳐져 있으므로
     이 안내는 할 일이 없다. **스니펫은 건드리지 않는다** — 그쪽이 「이미 처리함」으로
     보게 `data-wd-toasted` 를 먼저 찍고, 우리는 화면에서만 뺀다. */
  var TOGGLE = '쿠폰이 있으세요';
  function hushToggle(){
    var wrap = document.querySelector('.woocommerce-form-coupon-toggle');
    if(wrap){
      wrap.classList.add('dhr-hide-note');
      var info = wrap.querySelector('.woocommerce-info') || wrap;
      info.setAttribute('data-wd-toasted', '1');
    }
    var all = document.querySelectorAll('.woocommerce-info, #wd-coupon-toast, .wd-coupon-toast');
    for(var i = 0; i < all.length; i++){
      if(words(all[i]).indexOf(TOGGLE) < 0) continue;
      all[i].setAttribute('data-wd-toasted', '1');
      all[i].classList.add('dhr-hide-note');
    }
  }

  /* **「사용 가능한 쿠폰이 없습니다…」 한 줄은 뺀다** (사장님 2026-09-15).
     키플 쿠폰함(사이트에서 「받기」를 누른 쿠폰) 이야기라, 문자로 코드를 받은 손님에게는
     틀린 말이다. 바로 아래가 그 코드를 넣는 칸인데 「없습니다」부터 읽게 된다.
     플러그인은 건드리지 않고 **글자를 가진 가장 안쪽 요소 하나만** 화면에서 뺀다. */
  var MARK = '사용 가능한 쿠폰이 없습니다';
  function dropEmptyNote(){
    var all = document.querySelectorAll('p, div, span, li, small, em');
    for(var i = 0; i < all.length; i++){
      var el = all[i];
      if(el.classList.contains('dhr-hide-note')) continue;
      var t = words(el);
      if(t.indexOf(MARK) !== 0 || t.length > 200) continue;   /* 큰 덩어리는 건드리지 않는다 */
      var inner = false;
      for(var k = 0; k < el.children.length; k++){
        if((el.children[k].textContent || '').indexOf(MARK) >= 0){ inner = true; break; }
      }
      if(inner) continue;                                     /* 안쪽에 같은 글자가 있으면 그쪽이 진짜다 */
      el.classList.add('dhr-hide-note');
    }
  }

  /* **상품권(기프트카드) 코드칸을 화면에서 뺀다** (사장님 2026-09-15 — 「헷갈릴 거 같은데」).
     `woocommerce-gift-cards` 플러그인이 그리는데 이 가게는 상품권을 팔지 않는다
     (확인: 상품 검색 0건). 쿠폰칸 바로 옆에 또 코드칸이 있으면 문자로 쿠폰을 받은
     손님이 어디에 넣을지 헷갈린다. **플러그인은 건드리지 않고 화면에서만 뺀다** —
     되돌리기는 `add_filter( 'duckhoo_hide_giftcard', '__return_false' );` 한 줄.

     플러그인 클래스(`wc_gc_*`)를 먼저 보고, 못 찾으면 「상품권」 이라고 적힌 상자를 찾는다. */
  function dropGift(){
    if(window.DHR && window.DHR.hideGift === false) return;
    var seeds = document.querySelectorAll(
      '.wc_gc_cart_redeem, .wc_gc_cart_redeem_form, [id^="wc_gc_cart_redeem"], [name^="wc_gc_"], [class*="wc-gc-redeem"]'
    );
    var hit = [];
    for(var i = 0; i < seeds.length; i++){
      /* 칸 하나만 숨기면 제목 · 버튼이 남는다 — 「상품권」 이라고 적힌 상자까지 올라간다 */
      var el = seeds[i], card = null;
      for(var up = 0; up < 5 && el; up++, el = el.parentElement){
        var t = words(el);
        if(t.length > 600) break;
        if(t.indexOf('상품권') >= 0 || t.indexOf('기프트') >= 0){ card = el; break; }
      }
      hit.push(card || seeds[i]);
    }
    if(!hit.length){
      /* 클래스가 다를 수도 있다 — 「상품권」 이 적혀 있고 글자칸을 가진 가장 작은 상자 */
      var all = document.querySelectorAll('div, section, li, form, p');
      for(var k = 0; k < all.length; k++){
        var t2 = words(all[k]);
        if(t2.indexOf('상품권') < 0 || t2.length > 200) continue;
        if(t2.indexOf('쿠폰') >= 0) continue;              /* 쿠폰칸까지 같이 숨기면 안 된다 */
        if(!all[k].querySelector('input')) continue;
        var inner = false;
        for(var m = 0; m < all[k].children.length; m++){
          var c = all[k].children[m];
          if((c.textContent || '').indexOf('상품권') >= 0 && c.querySelector && c.querySelector('input')){ inner = true; break; }
        }
        if(inner) continue;                                 /* 안쪽에 더 작은 상자가 있으면 그쪽이 진짜다 */
        hit.push(all[k]);
      }
    }
    for(var n = 0; n < hit.length; n++) if(hit[n]) hit[n].classList.add('dhr-hide-note');
  }

  function tidy(){ hushToggle(); dropEmptyNote(); dropGift(); }
  tidy();
  document.addEventListener('DOMContentLoaded', tidy);
  window.addEventListener('load', function(){ tidy(); setTimeout(tidy, 500); setTimeout(show, 700); });
  $(document.body).on('updated_checkout', function(){
    setTimeout(tidy, 150);
    /* 테마가 요약을 다시 계산하면 쿠폰 줄이 도로 0 이 된다 — 손댄 뒤에는 다시 맞춘다.
       그때의 장바구니를 새로 읽어서 쓴다 (주소 · 배송비가 바뀌었을 수 있다) */
    if(touched) setTimeout(function(){ cart(paint); }, 0);
  });
  /* 토스트는 우리보다 뒤에 만들어질 수 있다 — 8초만 지켜본다 */
  var mo = new MutationObserver(function(){ hushToggle(); });
  mo.observe(document.body, { childList: true, subtree: true });
  setTimeout(function(){ mo.disconnect(); }, 8000);

  /* 안 될 때 한 장으로 끝내기 위한 읽을거리 — `?dhr_cpn=1` 을 붙였을 때만 그린다 */
  if(/[?&]dhr_cpn=1/.test(location.search)){
    window.addEventListener('load', function(){
      var lines = spots().map(function(s, n){
        return (n + 1) + ') 칸 name=' + (s.i.name || '-') + ' id=' + (s.i.id || '-') +
               ' / 버튼 ' + s.b.tagName + ' name=' + (s.b.name || '-') + ' "' + words(s.b).slice(0, 12) + '"' +
               ' / 폼안=' + (s.i.closest('form.checkout_coupon') ? 'Y' : 'N');
      });
      var d = document.createElement('pre');
      d.style.cssText = 'position:fixed;left:8px;right:8px;bottom:8px;z-index:99999;background:#111;color:#fff;font-size:12px;padding:10px;border-radius:10px;white-space:pre-wrap;max-height:40vh;overflow:auto';
      d.textContent = '[쿠폰칸 진단] 찾은 칸 ' + lines.length + '개\n' + (lines.join('\n') || '(못 찾음)');
      document.body.appendChild(d);
    });
  }
})();

/* **화면 읽기 — `?dhr_ui=1`** (결제 · 장바구니 · 계정 어디서나).

   결제 화면은 로그인이 있어야 열려 우리가 못 본다. 「UI 가 다른 것 같다」를 고치려면
   지금 화면이 실제로 어떤 클래스 · 색 · 크기로 그려지는지를 알아야 하는데, 스크린샷만으로는
   클래스 이름도 정확한 색도 못 읽는다. 이 스위치를 붙이면 그것을 **글자로** 뽑아
   그대로 복사할 수 있다. 주소에 붙였을 때만 돈다 — 손님 화면에는 없다. */
(function(){
  if(!/[?&]dhr_ui=1/.test(location.search)) return;

  function px(v){ return Math.round(parseFloat(v) || 0); }
  function line(el){
    var c = getComputedStyle(el), r = el.getBoundingClientRect();
    if(r.width < 8 && r.height < 8) return null;
    var name = el.tagName.toLowerCase() +
      (el.id ? '#' + el.id : '') +
      (el.className && typeof el.className === 'string'
        ? '.' + el.className.trim().split(/\s+/).slice(0, 3).join('.') : '');
    var bits = [
      Math.round(r.width) + '×' + Math.round(r.height),
      'font ' + px(c.fontSize) + '/' + c.fontWeight,
      'color ' + c.color,
      'bg ' + c.backgroundColor
    ];
    if(px(c.borderTopWidth) || px(c.borderLeftWidth)) bits.push('border ' + c.borderTopWidth + ' ' + c.borderTopStyle + ' ' + c.borderTopColor);
    if(px(c.borderTopLeftRadius)) bits.push('radius ' + px(c.borderTopLeftRadius));
    if(px(c.paddingTop) || px(c.paddingLeft)) bits.push('padding ' + px(c.paddingTop) + '/' + px(c.paddingLeft));
    return name + '  ' + bits.join(' · ');
  }

  function dump(){
    var out = ['[화면 읽기] ' + location.pathname + '  창 ' + window.innerWidth + 'px'];
    var want = document.querySelectorAll(
      '[class*="wd-checkout"], [class*="wd-summary"], #payment, .woocommerce-checkout-payment, ' +
      'form.checkout, .wd-cpg, [class*="wd-point"], [class*="keyple"], .dhr-cocpn, ' +
      'h1, h2, h3, h4, input:not([type=hidden]), select, textarea, button, a.button, .button'
    );
    var n = 0;
    for(var i = 0; i < want.length && n < 90; i++){
      var t = line(want[i]);
      if(t){ out.push(t); n++; }
    }
    /* 분홍 · 형광처럼 우리 팔레트가 아닌 색이 어디에 쓰였는지 */
    var odd = [], all = document.querySelectorAll('*');
    for(var k = 0; k < all.length && odd.length < 20; k++){
      var cs = getComputedStyle(all[k]);
      [['color', cs.color], ['bg', cs.backgroundColor], ['border', cs.borderTopColor]].forEach(function(pair){
        var m = /^rgba?\((\d+), ?(\d+), ?(\d+)/.exec(pair[1] || '');
        if(!m) return;
        var R = +m[1], G = +m[2], B = +m[3];
        if(R > 190 && B > 90 && G < 110 && (R - G) > 90){   /* 분홍 · 진분홍 계열 */
          var w = all[k].tagName.toLowerCase() + (all[k].className && typeof all[k].className === 'string' ? '.' + all[k].className.trim().split(/\s+/)[0] : '');
          var s2 = w + ' ' + pair[0] + ' ' + pair[1];
          if(odd.indexOf(s2) < 0) odd.push(s2);
        }
      });
    }
    if(odd.length) out.push('', '[우리 색이 아닌 것]', odd.join('\n'));
    return out.join('\n');
  }

  window.addEventListener('load', function(){
    setTimeout(function(){
      var text = dump();
      var box = document.createElement('div');
      box.style.cssText = 'position:fixed;left:8px;right:8px;bottom:8px;z-index:99999;background:#111;color:#fff;border-radius:12px;padding:10px;max-height:52vh;overflow:auto';
      var b = document.createElement('button');
      b.type = 'button'; b.textContent = '전부 복사';
      b.style.cssText = 'position:sticky;top:0;float:right;background:#fff;color:#111;border:0;border-radius:999px;padding:6px 14px;font-weight:800;cursor:pointer';
      b.addEventListener('click', function(){
        if(navigator.clipboard && navigator.clipboard.writeText){ navigator.clipboard.writeText(text).then(function(){ b.textContent = '복사됨'; }); return; }
        var ta = document.createElement('textarea'); ta.value = text; document.body.appendChild(ta);
        ta.select(); try{ document.execCommand('copy'); b.textContent = '복사됨'; }catch(err){} ta.remove();
      });
      var pre = document.createElement('pre');
      pre.style.cssText = 'margin:0;white-space:pre-wrap;font-size:11px;line-height:1.5';
      pre.textContent = text;
      box.appendChild(b); box.appendChild(pre);
      document.body.appendChild(box);
    }, 700);
  });
})();

/* 장바구니 — 마크업은 키플 것이라 손대지 않고, 자리만 고친다.
   1) 합계와 주문 버튼을 한 덩어리로 묶어 오른쪽에 붙인다. 원래는 상품 표가 끝난 뒤에야
      주문 버튼이 나와서, 담은 게 많으면 한참 내려가야 주문할 수 있었다.
      **감싸는 상자는 form 안에 만든다** — 주문 버튼이 submit 이라 폼 밖으로 나가면 안 된다.
   2) 비어 있는 위시리스트가 233px 를 먹는다. 비었으면 접는다.
   3) 금액대별 자동 할인 안내를 지금 규칙으로 고쳐 쓴다 (window.DHR.discount). */
(function(){
  var cpg = document.querySelector('.wd-cpg'); if(!cpg) return;
  var form = cpg.querySelector('form.wd-cpg-form');
  var sum = cpg.querySelector('.wd-cpg-summary'), order = cpg.querySelector('.wd-cpg-order');
  if(form && sum && order && sum.parentElement === order.parentElement && !document.querySelector('.dhr-cartside')){
    var side = document.createElement('div'); side.className = 'dhr-cartside';
    sum.parentNode.insertBefore(side, sum);
    /* 할인 적용 안내는 합계 옆이 제자리다 */
    var applied = form.querySelector('#coupon-applied-notice');
    if(applied) side.appendChild(applied);
    side.appendChild(sum); side.appendChild(order);
  }
  /* **담은 뒤에 서는 가입 벽을 문으로.** 비회원은 이 화면의 `주문하기` 를 눌러도
     결제 화면이 302 로 가입 화면에 돌려보낸다 — 아무 말 없이. 그 말을 여기서 한다.
     글자는 서버가 만든다 (`Front\join_wall()`): 로그인한 손님에게는 빈 문자열이다. */
  (function(){
    var wall = (window.DHR && window.DHR.joinWall) || '';
    if(!wall) return;
    var side = document.querySelector('.dhr-cartside') || (sum && sum.parentElement);
    if(!side) return;
    /* **이미 붙였는지는 이 상자 안에서만 본다.** 문서 전체를 보면 푸터의 장바구니
       서랍이 가진 같은 안내(`.dhc .dhr-wall`)에 걸려, 장바구니 화면에는 한 번도
       안 그려졌다 — 어제 서랍만 확인하고 넘어가 놓친 자리다. */
    if(side.querySelector('.dhr-wall')) return;
    var box = document.createElement('div');
    box.innerHTML = wall;
    var el = box.firstElementChild;
    if(!el) return;
    /* 자리는 합계 다음 · 주문하기 바로 위 — 누르려는 그 자리에서 말해 준다. */
    if(order && order.parentElement === side) side.insertBefore(el, order);
    else side.insertBefore(el, side.firstChild);
  })();
  /* **쿠폰 코드를 넣을 자리.** 이 장바구니는 키플이 만든 페이지라 워드커머스의 쿠폰칸이
     아예 없다 (확인: `coupon_code` 0개). 문자로 코드를 받은 손님이 넣을 데가 없으므로
     우리가 합계 옆에 하나 세운다.

     넣고 빼는 것은 **워드커머스 Store API** 가 한다 (`apply-coupon` · `remove-coupon`) —
     장바구니 서랍이 쓰는 그 길이고, 할인 계산도 워드커머스가 그대로 한다. 우리는
     금액을 만지지 않는다. 끝나면 **화면을 새로 고친다** — 합계 · 주문 버튼은 키플이
     서버에서 그리므로 그래야 숫자가 갈리지 않는다. */
  (function(){
    var side = document.querySelector('.dhr-cartside') || (sum && sum.parentElement);
    if(!side || document.querySelector('.dhr-cpn')) return;
    var nonce = (window.DHR && window.DHR.nonce) || '';

    var box = document.createElement('div');
    box.className = 'dhr-cpn';
    box.innerHTML =
      '<label class="dhr-cpn__l" for="dhr-cpn-i">쿠폰 코드</label>' +
      '<div class="dhr-cpn__row">' +
        '<input id="dhr-cpn-i" class="dhr-cpn__i" type="text" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="문자로 받은 코드">' +
        '<button type="button" class="dhr-cpn__b">적용</button>' +
      '</div>' +
      '<p class="dhr-cpn__msg" role="status"></p>' +
      '<ul class="dhr-cpn__on"></ul>';
    side.insertBefore(box, side.firstChild);

    var input = box.querySelector('.dhr-cpn__i'), btn = box.querySelector('.dhr-cpn__b');
    var msg = box.querySelector('.dhr-cpn__msg'), on = box.querySelector('.dhr-cpn__on');

    /* 워드커머스가 주는 말에는 `&quot;` 같은 엔티티가 들어 있다 — 풀어서 보여 준다.
       (안 풀면 손님 화면에 `&quot;test1000&quot; 쿠폰은…` 이라고 그대로 찍힌다) */
    function plain(t){
      var d = document.createElement('textarea');
      d.innerHTML = String(t == null ? '' : t).replace(/<[^>]*>/g, '');
      return d.value;
    }
    function say(t, bad){ msg.textContent = plain(t); box.classList.toggle('is-bad', !!bad); }
    function esc(v){ return String(v).replace(/[&<>"]/g, function(c){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'})[c]; }); }
    function head(){ var h = {'Content-Type':'application/json'}; if(nonce) h.Nonce = nonce; return h; }
    function keep(r){ var h = r.headers.get('Nonce') || r.headers.get('X-WC-Store-API-Nonce'); if(h) nonce = h; return r; }

    /* 이미 걸려 있는 쿠폰을 보여 준다 — 뺄 수도 있어야 한다 */
    function show(cart){
      var list = (cart && cart.coupons) || [];
      on.innerHTML = list.map(function(c){
        var off = c.totals && c.totals.total_discount
          ? Math.round(Number(c.totals.total_discount) / Math.pow(10, (c.totals.currency_minor_unit != null ? c.totals.currency_minor_unit : 0))).toLocaleString('ko-KR') + '원 할인'
          : '적용됨';
        return '<li><b>' + esc(String(c.code).toUpperCase()) + '</b> <span>' + esc(off) + '</span>' +
               '<button type="button" class="dhr-cpn__x" data-code="' + esc(c.code) + '" aria-label="쿠폰 빼기">빼기</button></li>';
      }).join('');
    }
    fetch('/wp-json/wc/store/v1/cart', {credentials:'include', headers: nonce ? {'Nonce': nonce} : {}})
      .then(keep).then(function(r){ return r.ok ? r.json() : null; }).then(function(c){ if(c) show(c); }).catch(function(){});

    function send(url, body, done){
      btn.disabled = true; box.classList.add('is-busy'); say('');
      fetch(url, {method:'POST', credentials:'include', headers: head(), body: JSON.stringify(body)})
        .then(keep).then(function(r){ return r.json().then(function(j){ return { ok: r.ok, j: j }; }); })
        .then(function(res){
          if(!res.ok){
            /* 워드커머스가 왜 안 되는지 한국어로 말해 준다 — 그 말을 그대로 보여 준다 */
            say((res.j && res.j.message) || '쿠폰을 적용하지 못했습니다.', true);
            btn.disabled = false; box.classList.remove('is-busy');
            return;
          }
          done();
        })
        .catch(function(){ say('잠시 뒤에 다시 눌러 주세요.', true); btn.disabled = false; box.classList.remove('is-busy'); });
    }

    function apply(){
      var code = (input.value || '').trim();
      if(!code){ say('코드를 넣어 주세요.', true); input.focus(); return; }
      send('/wp-json/wc/store/v1/cart/apply-coupon', { code: code }, function(){
        say('쿠폰을 적용했습니다. 금액을 다시 계산합니다…');
        location.reload();   /* 합계 · 주문 버튼은 키플이 서버에서 그린다 */
      });
    }
    btn.addEventListener('click', apply);
    input.addEventListener('keydown', function(e){ if(e.key === 'Enter'){ e.preventDefault(); apply(); } });
    on.addEventListener('click', function(e){
      var x = e.target.closest('.dhr-cpn__x'); if(!x) return;
      send('/wp-json/wc/store/v1/cart/remove-coupon', { code: x.dataset.code }, function(){ location.reload(); });
    });
  })();

  var wl = cpg.querySelector('.wd-cpg-wishlist');
  if(wl && !wl.querySelector('a[href*="/product/"], .wd-cpg-recom__item, li')) wl.classList.add('is-empty');

  /* **합계의 `할인금액` 한 줄에 서로 다른 할인이 뭉쳐 있었다.**
     16,000원(정가) − 13,000원(판매가) 3,000원 + 쿠폰 1,000원 = `할인금액 4,000원`.
     문자로 코드를 받아 넣은 손님은 자기 쿠폰이 먹었는지 이 숫자로는 알 수 없다.

     키플 마크업은 그대로 두고 **그 줄을 둘로 나눈다** — 금액을 우리가 만들지 않는다.
     쿠폰 몫은 워드커머스가 준 `totals.total_discount` 그대로이고, 남는 몫은
     `상품금액 − total_items` 와 맞을 때만 「상품 할인」이라 부른다 (아니면 「기타 할인」).
     합계 상자는 한 줄에 한 칸인 격자라 줄이 하나 늘어도 폭이 밀리지 않는다. */
  (function(){
    var calc = document.querySelector('.wd-cpg-summary__calc'); if(!calc) return;
    var nonce = (window.DHR && window.DHR.nonce) || '';
    var won = function(n){ return Number(n).toLocaleString('ko-KR') + '원'; };
    var num = function(el){ return el ? Number(String(el.textContent).replace(/[^\d]/g, '')) : 0; };
    var cell = function(row){ return { n: row.querySelector('strong'), l: row.querySelector('span') }; };

    function split(cart){
      var row = calc.querySelector('.wd-cpg-summary__item--discount');
      if(!row || row.dataset.dhrSplit) return;

      var t = (cart && cart.totals) || {};
      var unit = Math.pow(10, t.currency_minor_unit != null ? t.currency_minor_unit : 0);
      var coupon = Math.round(Number(t.total_discount || 0) / unit);
      if(!(coupon > 0)) return;

      var c = cell(row); if(!c.n || !c.l) return;
      var all = num(c.n); if(!all || coupon > all) return;   /* 못 읽으면 손대지 않는다 */
      var rest = all - coupon;

      /* 남는 몫이 정가↔판매가 차이와 맞는지 — 맞을 때만 「상품 할인」이라 부른다 */
      var listed = 0, items = calc.querySelectorAll('.wd-cpg-summary__item');
      for(var i = 0; i < items.length; i++){
        if((items[i].querySelector('span') || {}).textContent === '상품금액'){ listed = num(items[i].querySelector('strong')); break; }
      }
      var sale = listed ? listed - Math.round(Number(t.total_items || 0) / unit) : -1;

      row.dataset.dhrSplit = '1';
      if(rest <= 0){ c.n.textContent = won(coupon); c.l.textContent = '쿠폰 할인'; return; }

      c.n.textContent = won(rest);
      c.l.textContent = rest === sale ? '상품 할인' : '기타 할인';

      var sym = row.previousElementSibling;
      var add = row.nextSibling;
      if(sym && sym.classList.contains('wd-cpg-summary__sym')) calc.insertBefore(sym.cloneNode(true), add);
      var line = row.cloneNode(true);
      line.dataset.dhrSplit = '1';
      line.classList.add('dhr-sum-coupon');
      cell(line).n.textContent = won(coupon);
      cell(line).l.textContent = '쿠폰 할인';
      calc.insertBefore(line, add);
    }

    var tries = 0;
    function look(){
      if(++tries > 6) return;   /* 쿠폰이 없을 때 합계가 바뀔 때마다 부르지 않게 */
      fetch('/wp-json/wc/store/v1/cart', {credentials:'include', headers: nonce ? {'Nonce': nonce} : {}})
        .then(function(r){ return r.ok ? r.json() : null; })
        .then(function(c){ if(c) split(c); }).catch(function(){});
    }
    look();
    /* 수량을 고치면 키플이 합계를 다시 그린다 — 그때 우리 표시가 사라지므로 다시 나눈다 */
    var mo = new MutationObserver(function(){
      if(!calc.querySelector('.wd-cpg-summary__item--discount[data-dhr-split]')) look();
    });
    mo.observe(calc, { childList: true, subtree: true });
    setTimeout(function(){ mo.disconnect(); }, 12000);
  })();

  /* 안내 문구는 사장님 스니펫이 우리 뒤에 다시 그린다. 한 번 쓰고 끝내면 옛 문구로
     되돌아가므로, 그 자리를 지켜보다가 우리 것이 아니면 다시 쓴다. */
  /* 규칙이 비었으면 = 이벤트가 꺼졌다. 없는 혜택을 계속 광고하면 손님이 결제에서
     배신당하므로 사장님 스니펫이 그린 안내를 지운다. */
  var tiers = (window.DHR && window.DHR.discount) || [];
  if(!tiers.length){
    var wipe = function(){
      ['#coupon-auto-notice','#coupon-applied-notice'].forEach(function(sel){
        var n = document.querySelector(sel);
        if(n) n.remove();
      });
    };
    wipe();
    var mo0 = new MutationObserver(wipe);
    mo0.observe(document.documentElement, {childList: true, subtree: true});
    setTimeout(function(){ mo0.disconnect(); wipe(); }, 8000);
    return;
  }
  var won = function(v){ return Number(v).toLocaleString('ko-KR'); };
  var ex = (window.DHR && window.DHR.discountEx) || '';
  var line = tiers.map(function(t){ return won(t.min) + '원 이상 ' + won(t.amount) + '원'; }).join(' · ')
    + ' 자동 할인' + (ex ? ' (' + ex + ')' : '');
  function write(){
    var note = document.querySelector('#coupon-auto-notice');
    if(!note || note.dataset.dhr === line) return;
    note.textContent = '';
    var b = document.createElement('b'); b.textContent = '금액대별 자동 할인';
    var d = document.createElement('span'); d.className = 'n'; d.textContent = line;
    note.appendChild(b); note.appendChild(d);
    note.dataset.dhr = line;
  }
  write();
  var host = document.querySelector('.wd-cpg') || document.body;
  var mo = new MutationObserver(write);
  mo.observe(host, {childList: true, subtree: true, characterData: true});
  setTimeout(function(){ mo.disconnect(); write(); }, 8000);
})();

/* 홈 팝업 — 여러 장을 칩으로 넘겨 보게 돼 있는데, 사장님이 "4월 24일 이전 생산품" 안내
   한 장만 띄우기로 했다. 스니펫의 칩을 우리가 대신 눌러 그 장을 띄우고 칩 줄을 감춘다.
   이미지 주소를 우리가 박지 않는다 — 스니펫이 바꾸면 그대로 따라간다. */
(function(){
  var want = (window.DHR && window.DHR.popupTab) || '';
  if(!want) return;
  var tries = 0;
  (function go(){
    var pop = document.querySelector('#pop6');
    var btns = pop && pop.querySelector('.buttons');
    if(!btns){ if(tries++ < 30) return setTimeout(go, 200); return; }
    var chips = [].slice.call(btns.querySelectorAll('.bbtn'));
    var hit = chips.filter(function(b){ return b.textContent.trim() === want; })[0]
      || chips.filter(function(b){ return b.textContent.indexOf(want) > -1; })[0];
    if(!hit){ if(tries++ < 30) return setTimeout(go, 200); return; }
    if(!hit.classList.contains('active')) hit.click();
    pop.classList.add('dhr-single');
  })();
})();

/* 회원탈퇴 마지막 확인 — 되돌릴 수 없는 일이라, 사라지는 적립금 액수를 눈앞에 두고
   한 번 더 묻는다. 스크립트가 없으면 이 창은 안 뜨고 폼이 바로 넘어간다 (서버 검사는 그대로). */
(function(){
  var form = document.querySelector('form.dh-leave__form');
  var box = document.querySelector('[data-leave-confirm]');
  if(!form || !box) return;
  var go = box.querySelector('[data-leave-go]'), panel = box.querySelector('.dh-leave__box');
  var opener = null, armed = false;

  function open(){
    opener = document.activeElement;
    box.hidden = false; document.body.classList.add('dhc-open');
    requestAnimationFrame(function(){ box.classList.add('on'); go.focus(); });
  }
  function close(){
    box.classList.remove('on'); document.body.classList.remove('dhc-open');
    setTimeout(function(){ box.hidden = true; }, 200);
    if(opener && opener.focus) opener.focus();
  }
  form.addEventListener('submit', function(e){
    if(armed) return;                    /* 확인을 누른 뒤에는 그대로 보낸다 */
    if(!form.reportValidity()) return;    /* 비밀번호 · 동의 체크가 먼저다 */
    e.preventDefault(); open();
  });
  go.addEventListener('click', function(){
    armed = true; go.disabled = true; go.textContent = '처리 중…';
    if(form.requestSubmit) form.requestSubmit(); else form.submit();
  });
  box.addEventListener('click', function(e){ if(e.target.closest('[data-leave-close]')) close(); });
  document.addEventListener('keydown', function(e){ if(e.key === 'Escape' && !box.hidden) close(); });
  panel.addEventListener('keydown', function(e){
    if(e.key !== 'Tab') return;
    var f = panel.querySelectorAll('button:not([disabled])'); if(!f.length) return;
    var a = f[0], z = f[f.length - 1];
    if(e.shiftKey && document.activeElement === a){ e.preventDefault(); z.focus(); }
    else if(!e.shiftKey && document.activeElement === z){ e.preventDefault(); a.focus(); }
  });
})();

/* 푸터 링크 묶음 — 폰에서만 접힌다. 데스크톱은 CSS 가 늘 펼쳐 두므로 상태를 봐도 소용없다. */
(function(){
  document.querySelectorAll('[data-fcol] .fcol__t').forEach(function(t){
    t.addEventListener('click', function(){
      t.setAttribute('aria-expanded', t.getAttribute('aria-expanded') === 'true' ? 'false' : 'true');
    });
  });
})();

/* 성인인증 안내 팝업 (#dh-agegate2) — 사장님 Code Snippets 가 그린다. 그 파일은 건드리지
   않고, 색은 shell.css 가 덮고 여기서는 "오늘 하루 보지 않기" 를 기억하게만 한다.

   스니펫은 0.8초 뒤 팝업을 열면서 html/body 에 dh-ag2-lock 을 붙여 스크롤을 잠근다.
   그래서 노드를 지워 버리면 팝업만 사라지고 화면은 잠긴 채 남는다. 노드는 그대로 두고
   (1) 몸통 클래스로 화면에서만 빼고 (2) 열리는 순간 잠금을 도로 푼다.

   막는 것은 이 안내 팝업뿐이다. 진짜 성인 확인 — 가입 때의 휴대폰 본인확인과
   결제 단계의 회원 확인 — 은 서버 쪽이라 여기서 건드리지 않는다. */
(function(){
  var KEY = 'dhr-ag2';
  var today = (function(){ var d = new Date();
    return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); })();

  function read(){ try{ return localStorage.getItem(KEY); }catch(e){ return null; } }
  function write(){ try{ localStorage.setItem(KEY, today); }catch(e){} }
  function unlock(){
    document.documentElement.classList.remove('dh-ag2-lock');
    document.body.classList.remove('dh-ag2-lock');
  }

  /* 팝업 마크업은 우리 스크립트보다 뒤에 찍힌다 — 바로 찾으면 늘 없다.
     DOM 이 다 그려진 뒤에 보고, 그래도 없으면 load 에서 한 번 더 본다. */
  function init(){
    var box = document.getElementById('dh-agegate2');
    if(!box || box.dataset.dhrAg2) return !!box;
    box.dataset.dhrAg2 = '1';

    var later = box.querySelector('.dh-ag2-later');
    if(later){
      /* 문구가 하는 일과 맞아야 한다 — 누르면 오늘은 다시 뜨지 않는다 */
      later.textContent = '오늘 하루 보지 않기';
      later.addEventListener('click', function(){ write(); }, true);
    }

    if(read() !== today) return true;

    /* 오늘은 이미 닫았다 — 잠깐도 비치지 않게 CSS 로 먼저 빼 두고, 스니펫이 열면 잠금만 푼다 */
    document.body.classList.add('dhr-ag2-off');
    box.setAttribute('hidden', '');
    unlock();
    new MutationObserver(function(){
      if(!box.hasAttribute('hidden')) box.setAttribute('hidden', '');
      unlock();
    }).observe(box, {attributes: true, attributeFilter: ['hidden']});
    return true;
  }

  if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
  addEventListener('load', init);
})();

/* 계좌번호 복사 — 계좌이체만 받는 가게라 이 열한 자리를 손으로 옮겨 적는다.
   한 자리만 틀려도 입금이 안 맞고 사람이 손으로 찾아야 한다.
   송장번호(`.dhr-copy`)도 같은 일이라 같은 손잡이를 쓴다. */
(function(){
  document.addEventListener('click', function(e){
    var b = e.target.closest('.fbank__copy, .dhr-copy');
    if(!b) return;
    var v = b.dataset.copy || '';
    var done = function(){
      var was = b.dataset.was || b.textContent;
      b.dataset.was = was; b.textContent = '복사됨'; b.classList.add('is-done');
      setTimeout(function(){ b.textContent = was; b.classList.remove('is-done'); }, 1600);
    };
    if(navigator.clipboard && navigator.clipboard.writeText){
      navigator.clipboard.writeText(v).then(done, function(){});
      return;
    }
    /* 옛 브라우저 — 화면 밖에 잠깐 두고 복사한다 */
    var t = document.createElement('textarea');
    t.value = v; t.setAttribute('readonly', '');
    t.style.cssText = 'position:fixed;top:-1000px;left:-1000px;opacity:0';
    document.body.appendChild(t); t.select();
    try{ document.execCommand('copy'); done(); }catch(err){}
    t.remove();
  });
})();

/* 약관 화면의 나이 문구. 이 가게가 파는 것은 만 19세 미만에게 팔 수 없다 —
   일반 가입 약관의 "만 14세" 를 그대로 두면 안 된다. 사장님 스니펫이 그리는
   페이지라 그 파일은 건드리지 않고, 동의 항목의 글자만 바꾼다.
   **약관 본문(스크롤 상자)은 손대지 않는다** — 거기 14세는 개인정보 수집 규정이라 맞는 말이다. */
(function(){
  var row = document.querySelector('.wd-agree-age');
  if(!row) return;
  [].forEach.call(row.childNodes, function(n){
    if(n.nodeType === 1 && !n.classList.contains('wd-agree-required')){
      n.innerHTML = n.innerHTML.replace(/만\s*14\s*세/g, '만 19세');
    } else if(n.nodeType === 3){
      n.nodeValue = n.nodeValue.replace(/만\s*14\s*세/g, '만 19세');
    }
  });
})();

/* 폰 검색창 ─────────────────────────────────────────────────────────────
   폰에는 헤더 검색창이 880px 아래에서 숨겨져 있고, 검색 버튼은 없는 앵커를
   가리키고 있었다. **폰에서는 검색을 할 방법이 아예 없었다.**
   여기서 여는 창은 데스크톱 검색창과 필드 이름이 같다 (s · post_type) —
   검색이 도는 길은 그대로다. */
(function(){
  var panel = document.getElementById('dhr-search');
  if(!panel) return;
  var input = panel.querySelector('.dhsearch__in');
  var last = null;

  function open(e){
    if(e){ e.preventDefault(); }
    last = document.activeElement;
    panel.hidden = false;
    document.body.classList.add('dhr-search-on');
    /* 여는 버튼이 여럿이다 — 헤더 아이콘과 홈의 검색 알약 */
    document.querySelectorAll('[data-search-open]').forEach(function(b){ b.setAttribute('aria-expanded','true'); });
    requestAnimationFrame(function(){
      panel.classList.add('is-on');
      if(input){ input.focus(); input.select(); }
    });
  }

  function close(){
    panel.classList.remove('is-on');
    document.body.classList.remove('dhr-search-on');
    document.querySelectorAll('[data-search-open]').forEach(function(b){ b.setAttribute('aria-expanded','false'); });
    setTimeout(function(){ panel.hidden = true; }, 180);
    if(last && last.focus){ last.focus(); }
  }

  document.addEventListener('click', function(e){
    var o = e.target.closest && e.target.closest('[data-search-open]');
    if(o){ open(e); return; }
    var c = e.target.closest && e.target.closest('[data-search-close]');
    if(c && panel.contains(c)){ e.preventDefault(); close(); }
  });

  document.addEventListener('keydown', function(e){
    if(e.key === 'Escape' && !panel.hidden){ close(); }
  });

  /* 빈 검색은 보내지 않는다 — 결과 0건 화면으로 가면 손님은 그냥 나간다 */
  var form = panel.querySelector('.dhsearch__form');
  if(form){
    form.addEventListener('submit', function(e){
      if(!input || input.value.trim()) return;
      e.preventDefault();
      input.focus();
      panel.classList.add('is-empty');
      setTimeout(function(){ panel.classList.remove('is-empty'); }, 900);
    });
  }
})();

/* ── 노보 하루 한도를 고르는 자리에서 막는다 ────────────────────────────────
   서버는 이미 담기 · 주문에서 막는다. 하지만 손님은 다 고르고 나서야 거절을 만난다.
   테마 옵션 UI 의 수량 `+` 를 capture 단계에서 가로채, 오늘 살 수 있는 만큼에서 멈춘다.

   **담기는 값에는 손대지 않는다** — 누르지 못하게 할 뿐이다. 안내 글자는 `data-l` +
   CSS `content` 로 그린다 (폼 안에 텍스트 노드를 더하면 구매 게이트가 읽는 칸 이름이
   바뀐다). 세는 단위는 서버와 같다: 단품이면 병, 10+1 이면 세트. */
(function(){
  var N = window.DHR && window.DHR.novo;
  if(!N || !(N.max >= 0)) return;

  function count(){
    /* 테마가 쥔 값이 정답이다 — form.cart 의 quantity 가 아니라 옵션 JSON 이다. */
    var i = document.querySelector('form.cart input[name="wd_option_builder_json"]');
    if(i && i.value){
      try{
        var n = 0;
        JSON.parse(i.value).forEach(function(r){ if(r && r.type === 'required') n += parseInt(r.qty, 10) || 0; });
        return n;
      }catch(err){}
    }
    var d = 0;
    document.querySelectorAll('.dhx-bundle .dhx-qty__n').forEach(function(e){ d += parseInt(e.textContent, 10) || 0; });
    return d;
  }

  function say(near){
    var host = near.closest('.dhx-card__inner') || near.closest('.dhx-card') || near.parentElement;
    if(!host) return;
    var n = host.querySelector('.dhr-onenote');
    if(!n){ n = document.createElement('p'); n.className = 'dhr-onenote'; n.setAttribute('role', 'status'); host.appendChild(n); }
    n.setAttribute('data-l', N.max > 0
      ? '노보 10+1 묶음은 하루 ' + N.max + N.unit + '까지 담으실 수 있습니다. 낱병은 제한이 없습니다.'
      : '오늘 담을 수 있는 10+1 묶음을 이미 다 담으셨습니다. 낱병은 제한 없이 담으실 수 있습니다.');
    n.classList.add('is-on');
    clearTimeout(n._t);
    n._t = setTimeout(function(){ n.classList.remove('is-on'); }, 5000);
  }

  document.addEventListener('click', function(e){
    /* **세트 수를 올리는 버튼만 본다.** 한때 테마의 `.wd-option-plus` 를 통째로 잡았는데,
       묶음 상품에서는 맛 선택의 `+` 도 같은 클래스라 그것까지 죽었다 — 손님이 맛을
       한 병도 고르지 못했다. 세는 것은 세트 수뿐이므로 세트 줄만 막으면 된다. */
    var btn = e.target.closest && e.target.closest('.dhx-bundle .dhx-qty button');
    if(!btn) return;
    /* 내리는 버튼은 언제나 놔둔다 */
    if(btn !== btn.parentElement.lastElementChild) return;
    if(count() + 1 > N.max){
      e.preventDefault();
      e.stopPropagation();
      e.stopImmediatePropagation();
      say(btn);
    }
  }, true);
})();

/* 홈 맨 위 검은 공지 띠 (#wd-top-announce) — 사장님 Code Snippets **두 개**가 만든다.
   그 파일들은 건드리지 않고 글자만 우리가 정한다 (#pop6 · #dh-agegate2 와 같은 방식).

   2026-09-09: 자동 할인을 껐는데 띠는 「9월 특가 진행 중 — 10만원 이상 10,000원
   자동 할인!」 이라고 그대로 말하고 있었다. 없는 혜택을 믿고 담은 손님은 결제
   화면에서 배신당한다.

   **스니펫이 둘이라 한 번 써 놓고 끝내면 안 된다.**
     A. 띠를 만든다 (`injectAnnounceBar`, DOMContentLoaded, 8월 문구)
     B. 그 띠를 자기 문구로 덮어쓴다 (`dhfPatchTopBar`, 9월 문구) — **body 변화마다**
        다시 돈다 (`MutationObserver(subtree:true)`). 이미 고친 것은 `data-dhf="1"` 로 가른다
   그래서 (1) 우리가 먼저 `data-dhf="1"` 을 찍어 B 가 지나가게 하고,
   (2) 그래도 덮이면 띠 자신을 8초간 지켜보다 도로 우리 글자로 되돌린다.

   띠는 우리 스크립트보다 뒤에 만들어지므로 지금 · DOMContentLoaded · load 세 번 보고,
   그래도 놓치면 body 를 지켜본다. 그 동안 CSS(body.dhr-ann)가 띠를 감춰 둔다 —
   옛 문구가 한 번 번쩍이면 그것도 광고다. */
(function(){
  var C = window.DHR || {};
  if(!C.takeAnnounce) return;
  var seen = null, watching = false;

  function write(bar){
    if(!C.announce){ bar.hidden = true; bar.style.display = 'none'; return; }
    if(bar.textContent === C.announce) return;
    bar.textContent = C.announce;   /* 스니펫이 넣은 <strong> 까지 통째로 갈아 끼운다 */
  }

  function apply(){
    var bar = document.getElementById('wd-top-announce');
    if(!bar) return false;
    /* B 스니펫의 「이미 고쳤다」 표시를 우리가 먼저 찍는다 — 그러면 지나간다 */
    bar.setAttribute('data-dhf', '1');
    bar.dataset.dhrAnn = '1';
    write(bar);

    if(!watching && bar !== seen){
      seen = bar; watching = true;
      var mo = new MutationObserver(function(){ write(bar); });
      mo.observe(bar, {childList: true, characterData: true, subtree: true});
      setTimeout(function(){ mo.disconnect(); watching = false; }, 8000);
    }
    return true;
  }

  if(!apply()){
    if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', apply);
    addEventListener('load', apply);
    var body = new MutationObserver(function(){ if(apply()) body.disconnect(); });
    body.observe(document.body, {childList: true});
    setTimeout(function(){ body.disconnect(); }, 8000);
  }
})();

/* ── 구매 깔때기 신호 ─────────────────────────────────────────────────────
   서버는 비로그인 화면을 캐시로 내주므로 PHP 가 돌지 않는다. 그래서 화면 단계는
   브라우저가 알린다. 하루에 같은 단계는 한 번만 — 사람 수를 세는 것이지 조회 수가 아니다.
   보내는 것은 단계 이름과 로그인 여부뿐이다. */
(function(){
  var C = window.DHR || {};
  if(!C.stage || !C.beacon) return;
  var day = new Date(); day = day.getFullYear()+'-'+(day.getMonth()+1)+'-'+day.getDate();
  var seen = {};
  try{ seen = JSON.parse(localStorage.getItem('dhr-f') || '{}'); }catch(e){ seen = {}; }
  if(seen.d !== day){ seen = { d: day, s: [] }; }
  if(!seen.s) seen.s = [];
  if(seen.s.indexOf(C.stage) !== -1) return;
  seen.s.push(C.stage);
  try{ localStorage.setItem('dhr-f', JSON.stringify(seen)); }catch(e){}
  var body = 's=' + encodeURIComponent(C.stage) + '&m=' + (C.loggedIn ? '1' : '0');
  try{
    fetch(C.beacon, { method:'POST', keepalive:true, credentials:'omit',
      headers:{ 'Content-Type':'application/x-www-form-urlencoded' }, body: body }).catch(function(){});
  }catch(e){}
})();

/* 주문 목록의 `배송조회` 는 택배사 화면으로 나간다 — 새 창으로 연다.
   워드커머스 주문 목록 템플릿은 동작 목록에 `target` 을 넣을 자리를 주지 않아
   (url · name 뿐이다) 여기서 붙인다. 주소는 우리가 그린 것만 본다. */
(function(){
  function mark(){
    var a = document.querySelectorAll('.woocommerce-orders-table a.duckhoo-track, .woocommerce-orders-table a[href*="epost.go.kr"], .woocommerce-orders-table a[href*="cjlogistics.com"], .woocommerce-orders-table a[href*="hanjin.com"], .woocommerce-orders-table a[href*="ilogen.com"], .woocommerce-orders-table a[href*="lotteglogis.com"]');
    for(var i = 0; i < a.length; i++){
      if(a[i].target === '_blank') continue;
      a[i].target = '_blank';
      a[i].rel = 'noopener noreferrer';
    }
  }
  if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mark);
  else mark();
  addEventListener('load', mark);
})();

/* 안내 띠 (.dhn) — 헤더 아래 카드 세 장. 펼치는 것은 없다 (다 보인다). × 는 그날 하루만 닫는다.
   글이 바뀌면 data-dhn(내용 해시)이 달라져 닫아 둔 사람에게도 다시 보인다.
   서버가 늘 그리므로 JS 가 죽어도 안내는 보인다 — 없어지는 것은 닫기뿐이다. */
(function(){
  var bar = document.querySelector('[data-dhn]');
  if (!bar) return;
  var KEY = 'dhr-nb', v = bar.getAttribute('data-dhn') || '';
  var d = new Date(), today = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  try {
    var s = JSON.parse(localStorage.getItem(KEY) || 'null');
    if (s && s.v === v && s.d === today) { bar.hidden = true; return; }
  } catch (e) {}
  var x = bar.querySelector('[data-dhn-close]');
  if (x) x.addEventListener('click', function(){
    bar.hidden = true;
    try { localStorage.setItem(KEY, JSON.stringify({ v: v, d: today })); } catch (e) {}
  });
})();
