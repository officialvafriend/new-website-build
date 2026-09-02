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
