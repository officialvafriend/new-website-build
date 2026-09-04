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
   묶음 상품은 옛 옵션 UI(.dhx)가 이미 같은 일을 하므로 건드리지 않는다. */
(function(){
  var form = document.querySelector('.dhp-card--form form.cart') || document.querySelector('form.cart');
  if(!form) return;
  var seq = 0;

  /* "브이메이트V4팟 0.7옴(2EA) [+7,000원]" → 이름과 값을 갈라 오른쪽에 값을 세운다 */
  function split(text){
    var m = String(text).match(/^(.*?)\s*\[\s*(\+?[\d,]+\s*원?)\s*\]\s*$/);
    return m ? { name: m[1].trim(), price: m[2].replace(/\s+/g, '') } : { name: String(text).trim(), price: '' };
  }

  function build(sel){
    if(sel.closest('.dhx-src') || sel.closest('.dhx') || sel.dataset.dhsOn) return;
    if(sel.multiple || sel.options.length < 2) return;
    sel.dataset.dhsOn = '1';
    var id = 'dhs-' + (++seq);

    var root = document.createElement('div'); root.className = 'dhsel';
    var btn = document.createElement('button');
    btn.type = 'button'; btn.className = 'dhsel__btn'; btn.id = id + '-b';
    btn.setAttribute('aria-haspopup', 'listbox'); btn.setAttribute('aria-expanded', 'false');
    btn.innerHTML = '<span class="dhsel__val"></span><span class="dhsel__cost n"></span>'
      + '<svg class="dhsel__chev" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>';
    var list = document.createElement('div');
    list.className = 'dhsel__list'; list.id = id + '-l'; list.setAttribute('role', 'listbox'); list.hidden = true;
    var lab = (sel.closest('.ppom-field-wrapper, .form-row') || {}).querySelector
      ? (sel.closest('.ppom-field-wrapper, .form-row').querySelector('label') || {}).textContent : '';
    if(lab) btn.setAttribute('aria-label', String(lab).replace(/\*+/g, '').trim());
    btn.setAttribute('aria-controls', list.id);

    var rows = [];
    [].forEach.call(sel.options, function(o, i){
      var d = split(o.textContent);
      var r = document.createElement('button');
      r.type = 'button'; r.className = 'dhsel__opt'; r.setAttribute('role', 'option'); r.dataset.i = i;
      r.innerHTML = '<span class="dhsel__nm"></span>' + (d.price ? '<span class="dhsel__pr n"></span>' : '')
        + '<svg class="dhsel__tick" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12.5 5 5 9.5-11"/></svg>';
      r.querySelector('.dhsel__nm').textContent = d.name;
      if(d.price) r.querySelector('.dhsel__pr').textContent = d.price;
      if(o.disabled) r.disabled = true;
      list.appendChild(r); rows.push(r);
    });

    function paint(){
      var o = sel.options[sel.selectedIndex] || sel.options[0];
      var d = split(o ? o.textContent : '');
      btn.querySelector('.dhsel__val').textContent = d.name;
      btn.querySelector('.dhsel__cost').textContent = d.price;
      root.classList.toggle('is-set', sel.selectedIndex > 0);
      rows.forEach(function(r, i){ var on = i === sel.selectedIndex; r.classList.toggle('on', on); r.setAttribute('aria-selected', on ? 'true' : 'false'); });
    }
    function open(){
      if(!list.hidden) return;
      list.hidden = false; root.classList.add('is-open'); btn.setAttribute('aria-expanded', 'true');
      var cur = rows[sel.selectedIndex] || rows[0]; if(cur){ cur.focus(); cur.scrollIntoView({block:'nearest'}); }
    }
    function close(back){
      if(list.hidden) return;
      list.hidden = true; root.classList.remove('is-open'); btn.setAttribute('aria-expanded', 'false');
      if(back) btn.focus();
    }
    function pick(i){
      if(sel.selectedIndex !== i){
        sel.selectedIndex = i;
        /* 값이 바뀌었다는 사실을 원본 경로로 알린다 — PPOM · 테마 계산이 여기에 붙어 있다 */
        sel.dispatchEvent(new Event('input', {bubbles:true}));
        sel.dispatchEvent(new Event('change', {bubbles:true}));
        if(window.jQuery) window.jQuery(sel).trigger('change');
      }
      paint(); close(true);
    }

    btn.addEventListener('click', function(){ list.hidden ? open() : close(true); });
    btn.addEventListener('keydown', function(e){ if(e.key === 'ArrowDown' || e.key === 'ArrowUp'){ e.preventDefault(); open(); } });
    list.addEventListener('click', function(e){ var r = e.target.closest('.dhsel__opt'); if(r && !r.disabled) pick(+r.dataset.i); });
    list.addEventListener('keydown', function(e){
      var at = rows.indexOf(document.activeElement);
      if(e.key === 'Escape'){ e.preventDefault(); close(true); }
      else if(e.key === 'ArrowDown'){ e.preventDefault(); (rows[at + 1] || rows[0]).focus(); }
      else if(e.key === 'ArrowUp'){ e.preventDefault(); (rows[at - 1] || rows[rows.length - 1]).focus(); }
      else if(e.key === 'Home'){ e.preventDefault(); rows[0].focus(); }
      else if(e.key === 'End'){ e.preventDefault(); rows[rows.length - 1].focus(); }
    });
    document.addEventListener('click', function(e){ if(!root.contains(e.target)) close(false); });
    /* 다른 스크립트가 값을 바꿔도 따라 그린다 */
    sel.addEventListener('change', paint);

    sel.classList.add('dhsel-src');
    sel.setAttribute('tabindex', '-1');
    sel.setAttribute('aria-hidden', 'true');
    sel.parentNode.insertBefore(root, sel);
    root.appendChild(btn); root.appendChild(list); root.appendChild(sel);
    paint();
  }

  form.querySelectorAll('select.ppom-input, .ppom-field-wrapper select').forEach(build);
})();
