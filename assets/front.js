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
  prod.querySelectorAll('.summary .wc-prl-recommendations, .summary .related, .summary .upsells').forEach(function(el){ moved.push(el); });
  moved.forEach(function(el){ prod.appendChild(el); });
})();
