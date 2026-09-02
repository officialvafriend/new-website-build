/* 홈 — 카운트다운 · 필터 이동 · 찜(브라우저 저장) */
(function(){
  var end=(window.DHR&&window.DHR.saleEnd)||0, el=document.getElementById('dhr-left');
  function pad(n){return String(n).padStart(2,'0')}
  function tick(){ if(!el||!end)return; var ms=Math.max(0,end-Date.now()), d=Math.floor(ms/864e5), h=Math.floor(ms/36e5)%24, m=Math.floor(ms/6e4)%60, s=Math.floor(ms/1e3)%60;
    el.textContent=d+'일 '+pad(h)+':'+pad(m)+':'+pad(s); }
  tick(); setInterval(tick,1000);

  document.addEventListener('change',function(e){ var s=e.target.closest('[data-go]'); if(s&&s.value){ location.href=s.value; } });

  var KEY='dhr-wish', wish; try{ wish=new Set(JSON.parse(localStorage.getItem(KEY)||'[]')); }catch(e){ wish=new Set(); }
  function paint(){ document.querySelectorAll('[data-wish]').forEach(function(b){ var on=wish.has(+b.dataset.wish); b.classList.toggle('on',on); b.setAttribute('aria-pressed',on); }); }
  document.addEventListener('click',function(e){ var b=e.target.closest('[data-wish]'); if(!b)return; var id=+b.dataset.wish;
    wish.has(id)?wish.delete(id):wish.add(id); try{ localStorage.setItem(KEY,JSON.stringify([].concat.apply([],[Array.from(wish)]))); }catch(x){} paint(); });
  paint();
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
