# -*- coding: utf-8 -*-
"""데모 사이트를 한 파일로 합친다.

    python3 design/demo-src/build.py   →   design/demo.html

시안이 아니라 실제로 눌리는 데모다. 상품·주문 상태·입금자명 매칭 규칙은
duck-hoo.com 의 것을 그대로 옮겨 놨고, 화면만 새로 입혔다.
"""
import pathlib

SRC  = pathlib.Path(__file__).resolve().parent
ROOT = SRC.parent.parent
OUT  = ROOT / 'design' / 'demo.html'

css   = (SRC / 'site.css').read_text(encoding='utf-8')
cfcss = (SRC / 'coverflow.css').read_text(encoding='utf-8')
cfjs  = (ROOT / 'design' / 'coverflow.js').read_text(encoding='utf-8')
js    = (SRC / 'site.js').read_text(encoding='utf-8')

# 헤더는 불투명하게 (반투명 표면 금지)
css = css.replace('background:rgba(255,255,255,.97)', 'background:#fff')

extra = """
/* 커버플로우 캡션 아래 CTA */
.cf-go{display:flex;justify-content:center;margin-top:1.1rem}
"""

# 9월 특가 섹션을 가로 스크롤 대신 커버플로우로
old = """  <div class="scroller">${P.filter(p=>p.was||p.tag==='BEST'||p.tag==='신상').map(card).join('')}</div></section>"""
new = """  <div class="cf" id="cf"></div>
  <div class="cf-go"><a class="btn btn-d" id="cfGo" href="#/shop">이 상품 보기</a></div></section>"""
assert old in js
js = js.replace(old, new, 1)

old = """ syncChrome(seg[0]||'');"""
new = """ syncChrome(seg[0]||'');
 if(!seg.length)mountCF();
 mountReveal();"""
assert old in js
js = js.replace(old, new, 1)

mount = """
/* ── 커버플로우 · 스크롤 연출 ── */
const HOT=()=>P.filter(p=>p.was||p.tag==='BEST'||p.tag==='신상');
function mountCF(){
 const root=document.getElementById('cf'); if(!root||typeof coverflow!=='function')return;
 const hot=HOT();
 const slides=hot.map(p=>({bg:p.t,c:p.c,flag:p.tag||p.nic[0],title:p.n,subtitle:p.b+' · '+p.f,
  meta:[{label:'병당 가격',value:won(p.p)+'원'},{label:'니코틴',value:p.nic.join(' / ')},{label:'용량',value:'30ml'}]}));
 coverflow(root,slides,{label:'9월 특가 캐러셀',onSel:i=>{
  const go=document.getElementById('cfGo');
  if(go){go.setAttribute('href','#/p/'+hot[i].id);go.textContent=hot[i].n.length>12?'이 상품 보기':hot[i].n+' 보기';}
 }});
}
/* 화면은 처음부터 다 보인다. 스크롤에 맞춰 살짝 올라올 뿐이다. */
function mountReveal(){
 if(!window.gsap||!window.ScrollTrigger||matchMedia('(prefers-reduced-motion: reduce)').matches)return;
 ScrollTrigger.getAll().forEach(t=>t.kill());
 document.querySelectorAll('#view .sec').forEach((el,i)=>{
  if(i===0)return;
  gsap.from(el,{y:22,duration:.55,ease:'power2.out',
   scrollTrigger:{trigger:el,start:'top 90%',once:true}});
 });
 ScrollTrigger.refresh();
}
"""
js = js.replace('/* ── 라우터 ── */', mount + '\n/* ── 라우터 ── */', 1)

shell = """<header class="gnb"><div class="wrap gnb-in">
  <a class="lg" href="#/"><span class="g"></span>액상덕후</a>
  <nav>
   <a href="#/shop" data-k="shop">전체 상품</a>
   <a href="#/shop?f=멘솔" data-k="">멘솔</a>
   <a href="#/shop?b=액상덕후" data-k="">자체 제작</a>
   <a href="#/orders" data-k="orders">주문내역</a>
  </nav>
  <div class="sp">
   <a class="gi" href="#/shop" aria-label="상품 검색">⌕</a>
   <a class="gi" href="#/cart" aria-label="장바구니">◫<span class="b n" id="cartN">0</span></a>
   <a class="gi who" id="who" href="#/login">로그인</a>
  </div>
 </div></header>

<main id="view"></main>

<nav class="tabs" aria-label="주요 메뉴">
 <a href="#/" data-k=""><i>⌂</i>홈</a>
 <a href="#/shop" data-k="shop"><i>▦</i>상품</a>
 <a href="#/cart" data-k="cart"><i>◫</i>장바구니</a>
 <a href="#/orders" data-k="orders"><i>≡</i>주문내역</a>
 <a href="#/my" data-k="my"><i>◍</i>마이</a>
</nav>

<div class="toast" id="toast" role="status" aria-live="polite"></div>
<div class="dim" id="dim"></div>
<div class="modal" id="modal" role="dialog" aria-modal="true" aria-labelledby="mTitle">
 <h3 id="mTitle"></h3><div id="mBody"></div>
 <div class="acts"><button class="btn btn-o" id="mCancel">닫기</button><button class="btn btn-p" id="mOk"></button></div>
</div>"""

html = f"""<title>액상덕후 스토어</title>
<meta name="theme-color" content="#111111">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&display=swap">
<style>
@font-face{{font-family:Pretendard;font-display:swap;src:local("Pretendard Variable"),local("Pretendard")}}
{css}
{cfcss}
{extra}
</style>

{shell}

<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/ScrollTrigger.min.js"></script>
<script>
if(window.gsap&&window.ScrollTrigger)gsap.registerPlugin(ScrollTrigger);
{cfjs}
</script>
<script>
{js}
</script>
"""
OUT.write_text(html, encoding='utf-8')
print('wrote', OUT, len(html), 'bytes')
