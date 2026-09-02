# -*- coding: utf-8 -*-
"""데모 사이트를 한 파일로 합친다.

    python3 design/demo-src/build.py   →   design/demo.html

시안이 아니라 실제로 눌리는 데모다. 상품·주문 상태·입금자명 매칭 규칙은
duck-hoo.com 의 것을 그대로 옮겨 놨고, 화면만 새로 입혔다.
"""
import json, pathlib, re

SRC  = pathlib.Path(__file__).resolve().parent
ROOT = SRC.parent.parent
OUT  = ROOT / 'design' / 'demo.html'

css   = (SRC / 'site.css').read_text(encoding='utf-8')
cfcss = (SRC / 'coverflow.css').read_text(encoding='utf-8')
cfjs  = (ROOT / 'design' / 'coverflow.js').read_text(encoding='utf-8')
js    = (SRC / 'site.js').read_text(encoding='utf-8')
icons = json.loads((SRC / 'icons.json').read_text(encoding='utf-8'))

extra = """
/* 커버플로우 캡션 아래 CTA */
.cf-go{display:flex;justify-content:center;margin-top:1.1rem}
.cf-cap b{font-size:18px}
.cf-cap span{font-size:14px}
.cf-cap dl{font-size:14px}
"""

# 9월 특가 섹션을 가로 스크롤 대신 커버플로우로
old = """  <div class="scroller">${P.filter(p=>p.was||p.tag==='BEST'||p.tag==='신상').map(card).join('')}</div></section>"""
new = """  <div class="cf" id="cf"></div>
  <div class="cf-go"><a class="btn btn-d" id="cfGo" href="#/shop">이 상품 보기 ${I.arrow}</a></div></section>"""
assert old in js
js = js.replace(old, new, 1)

old = """ syncChrome(seg[0]||'');"""
new = """ syncChrome(seg[0]||'');
 if(!seg.length)mountCF();
 mountMotion();"""
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
  if(go){go.setAttribute('href','#/p/'+hot[i].id);go.innerHTML=(hot[i].n.length>12?'이 상품 보기':hot[i].n+' 보기')+' '+I.arrow;}
 }});
}
/* 화면은 처음부터 다 보인다. 스크롤에 맞춰 살짝 올라오고, 큰 카드의 병은 반대로 흐른다.
   스크롤러는 문서가 아니라 #view 다. */
function mountMotion(){
 if(!window.gsap||!window.ScrollTrigger||matchMedia('(prefers-reduced-motion: reduce)').matches)return;
 ScrollTrigger.getAll().forEach(t=>t.kill());
 document.querySelectorAll('#view .sec').forEach((el,i)=>{
  gsap.from(el,{y:22,duration:.55,ease:'power2.out',scrollTrigger:{trigger:el,scroller:'#view',start:'top 92%',once:true}});
 });
 document.querySelectorAll('#view .feat').forEach(card=>{
  const btl=card.querySelector('[data-px]'); if(!btl)return;
  gsap.fromTo(btl,{y:-36,rotate:-4},{y:36,rotate:4,ease:'none',
   scrollTrigger:{trigger:card,scroller:'#view',start:'top bottom',end:'bottom top',scrub:.6}});
 });
 ScrollTrigger.refresh();
}
"""
js = js.replace('/* ── 라우터 ── */', mount + '\n/* ── 라우터 ── */', 1)

# 아이콘을 JS 에 상수로 넣는다
js = 'const I=' + json.dumps(icons, ensure_ascii=False) + ';\n' + js

def ic(name): return icons[name]

shell = f"""<header class="gnb"><div class="wrap gnb-in">
  <a class="lg" href="#/"><span class="g"></span>액상덕후</a>
  <span class="ttl" id="ttl" aria-hidden="true"></span>
  <nav>
   <a href="#/shop" data-k="shop">전체 상품</a>
   <a href="#/shop?n=0mg" data-k="">무니코틴</a>
   <a href="#/shop?f=멘솔" data-k="">멘솔</a>
   <a href="#/shop?b=액상덕후" data-k="">자체 제작</a>
   <a href="#/orders" data-k="orders">주문내역</a>
  </nav>
  <div class="sp">
   <a class="gi" href="#/search" aria-label="상품 검색">{ic('search')}</a>
   <a class="gi" href="#/cart" aria-label="장바구니">{ic('bag')}<span class="b n" id="cartN">0</span></a>
   <a class="who" id="who" href="#/login" aria-label="로그인">{ic('user')}</a>
  </div>
 </div></header>

<main id="view"></main>

<nav class="tabs" aria-label="주요 메뉴">
 <a href="#/" data-k="">{ic('home')}홈</a>
 <a href="#/shop" data-k="shop">{ic('grid')}상품</a>
 <a href="#/cart" data-k="cart">{ic('bag')}<span class="b n" id="tabN">0</span>장바구니</a>
 <a href="#/orders" data-k="orders">{ic('receipt')}주문내역</a>
 <a href="#/my" data-k="my">{ic('user')}마이</a>
</nav>

<div class="toast" id="toast" role="status" aria-live="polite"></div>
<div class="dim" id="dim"></div>
<div class="modal" id="modal" role="dialog" aria-modal="true" aria-labelledby="mTitle">
 <h3 id="mTitle"></h3><div id="mBody"></div>
 <div class="acts"><button class="btn btn-o" id="mCancel">닫기</button><button class="btn btn-p" id="mOk"></button></div>
</div>"""

html = f"""<title>액상덕후 스토어</title>
<meta name="theme-color" content="#F5F5F7">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Noto+Sans+KR:wght@400..900&family=IBM+Plex+Mono:wght@500;600&display=swap">
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
