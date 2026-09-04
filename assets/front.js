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
  /* 담긴 직후 — WooCommerce 알림이 "장바구니에 추가" 를 말하면 서랍을 연다 */
  var msg = document.querySelector('.woocommerce-message');
  if(msg && /장바구니|cart/i.test(msg.textContent) && !document.body.classList.contains('woocommerce-cart')){ setTimeout(function(){ open(null); }, 350); }
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
  function hit(real){
    if(bar.classList.contains('is-off')){
      var box = form.querySelector('.dhx') || form; box.scrollIntoView({behavior:'smooth', block:'start'});
      bar.classList.add('nudge'); setTimeout(function(){ bar.classList.remove('nudge'); }, 700); return;
    }
    real.click();
  }
  bCart.addEventListener('click', function(){ hit(add); });
  bBuy.addEventListener('click', function(){ hit(buy || add); });
  new MutationObserver(sync).observe(form, {subtree:true, childList:true, attributes:true, attributeFilter:['disabled','class'], characterData:true});
  form.addEventListener('input', sync); form.addEventListener('change', sync);
  var anchor = form.querySelector('.vf-drawer-actions') || add;
  if('IntersectionObserver' in window){
    new IntersectionObserver(function(en){ bar.classList.toggle('is-away', !en[0].isIntersecting); }, {threshold: 0.2}).observe(anchor);
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
  var wide = window.matchMedia('(min-width: 900px)'), shut = false, docked = false;

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
  window.addEventListener('resize', function(){ undock(); paint(); });
  card.querySelector('[data-dock-close]').addEventListener('click', function(){ shut = true; undock(); open.hidden = false; });
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
  /* 옛 옵션 UI(.dhx)가 있는 화면 — 묶음 · 이벤트 상품 — 에서는 아예 만들지 않는다.
     거기서는 .dhx 가 눈에 보이는 UI 를 그리고 우리 선택창은 34px 로 찌그러져 보이지도
     않았다. 보이지도 않으면서 칸 이름 판정만 어긋나게 했다. */
  var legacy = !!document.querySelector('.dhx');

  function build(sel){
    if(legacy || sel.closest('.dhx-src') || sel.closest('.dhx') || sel.dataset.dhsOn) return;
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

    function place(){
      if(!list) return;
      var r = trig.getBoundingClientRect();
      list.style.left = r.left + 'px';
      list.style.width = r.width + 'px';
      var below = innerHeight - r.bottom - 12;
      if(below < 200 && r.top > below){ list.style.top = ''; list.style.bottom = (innerHeight - r.top + 6) + 'px'; }
      else { list.style.bottom = ''; list.style.top = (r.bottom + 6) + 'px'; }
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

  form.querySelectorAll('select.ppom-input, .ppom-field-wrapper select').forEach(build);
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
  var wl = cpg.querySelector('.wd-cpg-wishlist');
  if(wl && !wl.querySelector('a[href*="/product/"], .wd-cpg-recom__item, li')) wl.classList.add('is-empty');

  /* 안내 문구는 사장님 스니펫이 우리 뒤에 다시 그린다. 한 번 쓰고 끝내면 옛 문구로
     되돌아가므로, 그 자리를 지켜보다가 우리 것이 아니면 다시 쓴다. */
  var tiers = (window.DHR && window.DHR.discount) || [];
  if(!tiers.length) return;
  var won = function(v){ return Number(v).toLocaleString('ko-KR'); };
  var line = tiers.map(function(t){ return won(t.min) + '원 이상 ' + won(t.amount) + '원'; }).join(' · ') + ' 자동 할인';
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
