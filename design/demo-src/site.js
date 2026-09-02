const won=n=>n.toLocaleString('ko-KR');
const $=s=>document.querySelector(s);
const esc=s=>String(s).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));

const P=[
 {id:1,b:'액상덕후',n:'파이낫푸루 흑염룡 시리즈',p:14000,was:0,c:'#1F2937',t:'#DDE3EA',f:'과일',nic:['0.98mg'],tag:'자체',d:'멘솔을 뺀 순수 파인애플 계열. 목넘김이 부드럽습니다.'},
 {id:2,b:'액상덕후',n:'쟈쿠로 흑염룡 시리즈',p:14000,was:0,c:'#7B1E3A',t:'#FBDCE8',f:'과일',nic:['0.98mg'],tag:'자체',d:'석류 베이스. 달지 않고 산미가 남습니다.'},
 {id:3,b:'액상덕후',n:'머스케또쨩 멘솔쨩 시리즈',p:14000,was:0,c:'#1E5F74',t:'#D6EEF7',f:'멘솔',nic:['0.98mg'],tag:'자체',d:'청포도에 멘솔. 시원함을 앞에 세운 라인입니다.'},
 {id:4,b:'맥스쿨',n:'샤인머스캣 무니코틴 액상',p:8100,was:9000,c:'#7FA83C',t:'#E4F7C4',f:'과일',nic:['0mg'],tag:'9월',d:'9월 특가. 달콤한 청포도 향.'},
 {id:5,b:'맥스쿨',n:'포카리 무니코틴 액상',p:9000,was:0,c:'#5B9BD5',t:'#D6EAFC',f:'음료',nic:['0mg'],d:'이온음료 계열. 물리지 않습니다.'},
 {id:6,b:'맥스쿨',n:'쿨베리 무니코틴 액상',p:9000,was:0,c:'#8E44AD',t:'#EADCF8',f:'멘솔',nic:['0mg'],d:'베리에 쿨링. 여름에 잘 나갑니다.'},
 {id:7,b:'얼려먹구싶오',n:'모드 청포도 무니코틴 액상',p:8100,was:9000,c:'#9BBF3E',t:'#E8FAC8',f:'과일',nic:['0mg'],tag:'9월',d:'9월 특가. 무니코틴 베스트셀러.'},
 {id:8,b:'얼려먹구싶오',n:'모드 레드불 무니코틴 액상',p:9000,was:0,c:'#2C6FBB',t:'#D6EAFC',f:'음료',nic:['0mg'],d:'에너지드링크 향을 그대로 옮겼습니다.'},
 {id:9,b:'심쿵',n:'망고 무니코틴 액상',p:8100,was:9000,c:'#E4A11B',t:'#FFEFB8',f:'과일',nic:['0mg'],tag:'9월',d:'9월 특가. 진한 열대과일.'},
 {id:10,b:'심쿵',n:'멘솔 무니코틴 액상',p:9000,was:0,c:'#31A8A0',t:'#D3F5F1',f:'멘솔',nic:['0mg'],d:'첨가물 없는 순수 멘솔.'},
 {id:11,b:'깔끔',n:'깔끔 아이스 무니코틴 액상',p:9000,was:0,c:'#4FA8C7',t:'#CFF0FB',f:'멘솔',nic:['0mg'],d:'이름 그대로. 군더더기 없습니다.'},
 {id:12,b:'리퀴드랩',n:'금실딸기',p:13000,was:0,c:'#C2334D',t:'#FFDCE2',f:'디저트',nic:['9.8mg'],tag:'BEST',d:'재구매 1위. 딸기우유에 가까운 단맛.'},
 {id:13,b:'네스티',n:'더블 슬로우블로우 파인애플',p:15000,was:0,c:'#E0B020',t:'#FFF0B5',f:'과일',nic:['9.8mg','0.98mg'],tag:'신상',d:'네스티 대표작. 파인애플에 약한 민트.'},
 {id:14,b:'펠릭스',n:'더블 라임 + 라임 알로에',p:13000,was:0,c:'#6FA83C',t:'#E2F7C6',f:'과일',nic:['9.8mg'],d:'라임 두 종을 섞은 묶음 구성.'},
 {id:15,b:'첨가제',n:'아이 차가워! 쿨링첨가제 30ml',p:6000,was:0,c:'#3AAFD9',t:'#CFF0FB',f:'멘솔',nic:['0mg'],d:'기존 액상에 섞어 쓰는 쿨링 첨가제.'},
];
const BUNDLES=[{k:'단품 1병',q:1,note:'맛 하나만'},{k:'3+1 · 총 4병',q:4,mult:3,note:'1병 무료'},
 {k:'5+5 · 총 10병',q:10,mult:5,note:'5병 무료 · 9월 최저 병당가'},{k:'2+3 · 총 5병',q:5,mult:2,note:'3병 무료 · 9월 한정'}];
const FL=['전체','과일','멘솔','음료','디저트'];
const BRANDS=['액상덕후','맥스쿨','얼려먹구싶오','심쿵','깔끔','리퀴드랩','디오리퀴드','펠릭스','네스티'];
const ST={pend:['입금전','pend'],chk:['확인필요','chk'],paid:['입금확인','paid'],ship:['배송중','ship'],done:['배송완료','done']};

let S={user:null,cart:[],orders:[],flt:'전체',seq:10482,points:2400,q:''};

/* 탈퇴를 막는 이유들. 비어 있으면 탈퇴할 수 있다. */
const leaveBlockers=()=>{
 const out=[];
 const open=S.orders.filter(o=>o.st!=='done').length;
 if(open)out.push(`아직 끝나지 않은 주문이 ${open}건 있습니다. 배송이 끝난 뒤에 탈퇴해 주세요.`);
 if(S.points>0)out.push(`적립금이 ${won(S.points)}원 남아 있습니다. 다 쓰신 뒤에 탈퇴해 주세요.`);
 return out;
};
const find=id=>P.find(p=>p.id===id);
const cartQty=()=>S.cart.reduce((a,c)=>a+c.q,0);
const cartSum=()=>S.cart.reduce((a,c)=>a+c.pay,0);
const ship=()=>cartSum()>=30000||!S.cart.length?0:3000;

function toast(t){const el=$('#toast');el.textContent=t;el.classList.add('on');clearTimeout(toast.t);
 toast.t=setTimeout(()=>el.classList.remove('on'),2400)}
function modal(title,body,okLabel,onOk,danger){
 $('#mTitle').textContent=title;$('#mBody').innerHTML=body;
 const ok=$('#mOk');ok.textContent=okLabel;ok.className='btn '+(danger?'btn-dg':'btn-p');
 ok.onclick=()=>{closeModal();onOk&&onOk()};
 $('#dim').classList.add('on');$('#modal').classList.add('on');
}
const closeModal=()=>{$('#dim').classList.remove('on');$('#modal').classList.remove('on')};

/* ── 컴포넌트 ── */
const card=p=>`<a class="card" href="#/p/${p.id}">
 <div class="fig" style="background:${p.t}">${p.tag?`<span class="pill ${p.tag!=='자체'?'hot':''}">${p.tag}</span>`
  :`<span class="pill">${p.nic[0]}</span>`}<span class="btl" style="background:${p.c}"></span></div>
 <div class="bd"><span class="brand">${esc(p.b)}</span><span class="nm">${esc(p.n)}</span>
 <span class="row"><span class="pr">${p.was?`<span class="was n">${won(p.was)}</span>`:''}<b class="n">${won(p.p)}</b>
 <small>30ml · 단품</small></span></span></div></a>`;
const stBadge=k=>`<span class="st ${ST[k][1]}">${ST[k][0]}</span>`;

/* ── 화면 ── */
const V={};

/* 홈 — 큰 제목 → 분류 타일 → 브랜드 → 이번 달 큰 카드(패럴랙스) → 9월 특가 → 무니코틴 → 안내 */
const CATS=[
 {k:'무니코틴',s:'0mg',q:'n=0mg',c:['#7FA83C','#5B9BD5','#8E44AD']},
 {k:'저농도',s:'0.98mg',q:'n=0.98mg',c:['#1F2937','#7B1E3A','#1E5F74']},
 {k:'고농도',s:'9.8mg',q:'n=9.8mg',c:['#C2334D','#E0B020','#6FA83C']},
 {k:'멘솔',s:'시원한 것만',q:'f=멘솔',c:['#31A8A0','#4FA8C7','#1E5F74']},
 {k:'첨가제',s:'섞어 쓰는',q:'b=첨가제',c:['#3AAFD9']},
 {k:'자체 제작',s:'액상덕후',q:'b=액상덕후',c:['#1F2937','#7B1E3A','#1E5F74']},
];
const FEATS=[
 {id:13,tag:'신상',title:'더블 슬로우블로우 파인애플',sub:'네스티 대표작. 파인애플에 약한 민트.'},
 {id:12,tag:'재구매 1위',title:'금실딸기',sub:'딸기우유에 가까운 단맛. 질리지 않습니다.'},
 {id:4,tag:'9월 특가',title:'샤인머스캣 무니코틴',sub:'9,000원에서 8,100원. 9월 한 달만.'},
];
const feat=f=>{const p=find(f.id);return `<a class="feat" href="#/p/${p.id}" style="--fc:${p.c}">
 <div class="feat-art"><span class="glow"></span><span class="btl" style="background:${p.c}" data-px></span>
  <span class="tag">${f.tag}</span></div>
 <div class="feat-body"><h3>${esc(f.title)}</h3><p>${esc(f.sub)}</p></div>
 <div class="feat-foot"><span class="pr">병당 <b class="n">${won(p.p)}원</b>${p.was?` <s style="color:#8E8E93;font-weight:500">${won(p.was)}</s>`:''}</span>
  <span class="btn">구매 ${I.arrow}</span></div></a>`};

V.home=()=>`<div class="wrap">
 <section class="hero"><div><h1>액상덕후</h1>
  <p class="lead">9개 브랜드 173종. 무니코틴부터 9.8mg까지, <b>병당 가격</b>으로 고르세요.</p></div></section>
 <div class="tiles">${CATS.map(c=>`<a class="tile" href="#/shop?${c.q}">
  <span class="art">${c.c.map(col=>`<span class="btl" style="background:${col}"></span>`).join('')}</span>
  <b>${c.k}</b><span>${c.s}</span></a>`).join('')}</div>

 <section class="sec" style="padding-top:.4rem"><div class="sec-h"><h2>브랜드</h2></div>
  <div class="chips-x">${BRANDS.map(b=>`<a class="chip" href="#/shop?b=${encodeURIComponent(b)}">${b}</a>`).join('')}</div></section>

 <section class="sec"><div class="sec-h"><h2>이번 달 볼 것</h2><span class="sub">3개</span></div>
  ${FEATS.map(feat).join('')}</section>

 <section class="sec"><div class="sec-h"><h2>9월 특가</h2><span class="sub">4종 · 넘겨 보세요</span>
  <a class="lk" style="margin-left:auto" href="#/shop">전체 보기 ${I.chev}</a></div>
  <div class="scroller">${P.filter(p=>p.was||p.tag==='BEST'||p.tag==='신상').map(card).join('')}</div></section>

 <section class="sec"><div class="sec-h"><h2>무니코틴</h2><span class="sub">가장 많이 찾는 분류</span>
  <a class="lk" style="margin-left:auto" href="#/shop?n=0mg">전체 보기 ${I.chev}</a></div>
  <div class="scroller">${P.filter(p=>p.nic[0]==='0mg').map(card).join('')}</div></section>

 <section class="sec"><div class="sec-h"><h2>이렇게 삽니다</h2></div>
  <div class="info">
   <div class="panel">${I.bank}<div><b>계좌이체 전용</b><p>카드결제는 받지 않습니다. 주문 후 안내되는 계좌로 보내주세요.</p></div></div>
   <div class="panel">${I.check}<div><b>입금자명 = 주문자명</b><p>이름이 같으면 자동으로 입금확인됩니다. 다르면 사람이 찾느라 하루쯤 늦어집니다.</p></div></div>
   <div class="panel">${I.truck}<div><b>3만원 이상 무료배송</b><p>입금확인 당일 발송. 우체국택배로 갑니다.</p></div></div>
  </div></section>

 <footer class="ft"><b>19세 미만 청소년에게 판매하지 않습니다.</b><br>
  구매 시 휴대폰 본인확인이 필요합니다 · 니코틴은 중독성이 있는 물질입니다<br>액상덕후 · 전자담배 액상 전문몰</footer>
</div>`;

V.shop=q=>{
 const b=q.get('b'),n=q.get('n'); if(q.get('f'))S.flt=q.get('f');
 let list=b?P.filter(p=>p.b===b):n?P.filter(p=>p.nic.includes(n)):P;
 if(!b&&!n&&S.flt!=='전체')list=list.filter(p=>p.f===S.flt);
 const title=b?esc(b):n?(n==='0mg'?'무니코틴':n==='0.98mg'?'저농도 0.98mg':'고농도 9.8mg'):'전체 상품';
 return `<div class="wrap"><div class="crumb"><a href="#/">홈</a> › 상품</div>
 <h1 class="pagetitle">${title}</h1>
 ${(b||n)?'':`<div class="filters">${FL.map(f=>`<button class="chip ${f===S.flt?'on':''}" data-flt="${f}">${f}
  <span class="c">${f==='전체'?P.length:P.filter(x=>x.f===f).length}</span></button>`).join('')}</div>`}
 <p style="font-size:14px;color:var(--ink3);margin-bottom:.8rem">${list.length}종</p>
 <div class="grid">${list.map(card).join('')}</div><div style="height:2rem"></div></div>`;
};

V.p=id=>{
 const p=find(+id); if(!p)return V.e404();
 const b=BUNDLES[state.bi],pay=(b.mult||b.q)*p.p,per=Math.round(pay/b.q);
 return `<div class="wrap"><div class="crumb"><a href="#/">홈</a> › <a href="#/shop">상품</a> › ${esc(p.b)}</div>
 <div class="pd" style="margin-top:.8rem">
  <div><div class="pd-fig" style="background:${p.t}"><span class="btl" style="background:${p.c}"></span>
   ${p.tag?`<span class="pill ${p.tag!=='자체'?'hot':''}">${p.tag}</span>`:''}</div>
   <div class="pd-thumbs">${[0,1,2,3].map(i=>`<button class="${i===0?'on':''}" style="background:${p.t}" 
    aria-label="이미지 ${i+1}"></button>`).join('')}</div></div>
  <div>
   <span class="brand">${esc(p.b)}</span>
   <h1 style="font-size:clamp(1.3rem,1.1rem + 1vw,1.8rem);margin:.3rem 0 .5rem">${esc(p.n)}</h1>
   <p style="font-size:15px;color:var(--ink2);line-height:1.7">${esc(p.d)}</p>
   <div style="margin-top:.9rem;display:flex;align-items:baseline;gap:.4rem">
    ${p.was?`<span class="was n" style="text-decoration:line-through;color:var(--ink3)">${won(p.was)}</span>`:''}
    <b class="n" style="font-size:26px;font-weight:900;letter-spacing:-.03em">${won(p.p)}</b>
    <span style="font-size:14px;color:var(--ink3)">원 / 30ml 단품</span></div>
   <div style="margin-top:1.3rem"><div class="sec-h" style="margin-bottom:.5rem"><h2 style="font-size:14px">구성 선택</h2></div>
    <div class="opts">${BUNDLES.map((x,i)=>{const pp=(x.mult||x.q)*p.p,per2=Math.round(pp/x.q);
     return `<button class="opt ${i===state.bi?'on':''}" data-bi="${i}"><span class="l"><b>${x.k}</b>
     <span>${x.note} · 병당 ${won(per2)}원</span></span>
     <span class="d">${i===0?won(pp):'+'+won(pp-p.p)}</span></button>`}).join('')}</div></div>
   <div style="margin-top:1.1rem"><div class="sec-h" style="margin-bottom:.5rem"><h2 style="font-size:14px">니코틴 함량</h2></div>
    <div class="chips">${p.nic.map((x,i)=>`<button class="chip ${i===state.ni?'on':''}" data-ni="${i}">${x}</button>`).join('')}</div></div>
   <div class="totals"><div><span>구성</span><span>${b.k}</span></div>
    <div><span>병당 가격</span><span class="n">${won(per)}원</span></div>
    <div class="big"><span>총 금액</span><b class="n">${won(pay)}원</b></div></div>
   <div class="buybar"><button class="btn btn-o" data-act="cart">장바구니</button>
    <button class="btn btn-p main" data-act="buy">바로 구매</button></div>
  </div></div>
 ${detail(p)}
 <div style="height:2rem"></div></div>`;
};

/* 상세 본문 — 실제 사이트에서는 워드프레스 상품 설명이 이 자리에 들어간다.
   여기 글은 예시다. 표의 항목 이름은 액상 판매에 실제로 필요한 것들이다. */
const detail=p=>{
 const nic=p.nic.join(' / ');
 const flav={과일:'과일',멘솔:'멘솔·쿨링',음료:'음료',디저트:'디저트'}[p.f]||p.f;
 const tone=p.f==='멘솔'?'시원함이 먼저 오고 단맛이 뒤에 남습니다.':p.f==='디저트'?'단맛이 앞에 서고 향이 길게 남습니다.':'향이 먼저 오고 단맛은 뒤에서 받쳐 줍니다.';
 return `<div class="pdx">
  <nav class="pdx-nav" aria-label="상세 내용">
   <a href="#/p/${p.id}" data-jump="d1" class="on">상품 설명</a><a href="#/p/${p.id}" data-jump="d2">제품 정보</a>
   <a href="#/p/${p.id}" data-jump="d3">리뷰</a><a href="#/p/${p.id}" data-jump="d4">배송·교환</a></nav>
  <section id="d1"><h2>상품 설명</h2>
   <div class="copy"><p><b>${esc(p.n)}</b>. ${esc(p.d)} ${tone}</p>
    <p>30ml 한 병으로 하루 3–4ml 기준 약 일주일 씁니다. 처음이면 단품 한 병으로 맛을 보고, 맞으면 3+1이나 5+5로 병당 가격을 낮추는 걸 권합니다.</p></div>
   <div class="pdx-hero" style="--fc:${p.c}"><span class="btl" style="background:${p.c}"></span>
    <div><b>${esc(p.n)}</b><span>${esc(p.b)} · ${flav} · ${nic}</span></div></div>
   <div class="copy"><p>개봉 후에는 직사광선을 피해 서늘한 곳에 두세요. 니코틴 함량이 있는 제품은 <b>반드시 어린이 손이 닿지 않는 곳</b>에 보관해야 합니다.</p></div>
  </section>
  <section id="d2"><h2>제품 정보</h2>
   <table class="spec"><tbody>
    <tr><th>용량</th><td>30ml</td></tr>
    <tr><th>니코틴</th><td>${nic}${p.nic[0]==='0mg'?' (무니코틴)':''}</td></tr>
    <tr><th>베이스</th><td>PG 50 / VG 50</td></tr>
    <tr><th>맛 계열</th><td>${flav}</td></tr>
    <tr><th>제조·유통</th><td>${p.b==='액상덕후'?'액상덕후 자체 제작':esc(p.b)+' · 액상덕후'}</td></tr>
    <tr><th>원산지</th><td>대한민국</td></tr>
    <tr><th>구매 제한</th><td>만 19세 이상 · 휴대폰 본인확인 필요</td></tr>
   </tbody></table></section>
  <section id="d3"><h2>리뷰 <span style="color:var(--ink3);font-size:15px;font-weight:600">4.8 · 127개</span></h2>
   <div class="rev">
    <div class="panel"><div class="who"><b>김**</b><span class="stars">★★★★★</span><span>3+1 · ${p.nic[0]}</span></div>
     <p>세 번째 재구매. 병당 가격 보고 4병 묶음으로 바꿨는데 같은 맛이라 부담 없습니다.</p></div>
    <div class="panel"><div class="who"><b>박**</b><span class="stars">★★★★★</span><span>단품 · ${p.nic[0]}</span></div>
     <p>입금자명 똑같이 넣었더니 20분 만에 입금확인 떴어요. 다음 날 도착.</p></div>
    <div class="panel"><div class="who"><b>이**</b><span class="stars">★★★★☆</span><span>5+5 · ${p.nic[p.nic.length-1]}</span></div>
     <p>맛은 설명 그대로. 10병은 좀 많았는데 병당 ${won(Math.round(BUNDLES[2].mult*p.p/BUNDLES[2].q))}원이라 그냥 샀습니다.</p></div>
   </div></section>
  <section id="d4"><h2>배송 · 교환 · 환불</h2>
   <div class="faq">
    <details open><summary>언제 출발하나요? ${I.chev}</summary><p>입금확인 당일 우체국택배로 발송합니다. 오후 2시 이후 확인분은 다음 날 출발합니다. 3만원 이상 무료배송, 미만은 3,000원.</p></details>
    <details><summary>입금했는데 입금확인이 안 떠요 ${I.chev}</summary><p>입금자명이 주문자명과 다르면 자동으로 확인되지 않고 <b>확인필요</b>로 넘어갑니다. 카카오톡으로 입금자명을 알려주시면 바로 처리합니다.</p></details>
    <details><summary>교환·환불은요? ${I.chev}</summary><p>개봉하지 않은 제품은 수령 후 7일 안에 교환·환불됩니다. 액상은 위생 문제로 개봉 후에는 불량이 아니면 어렵습니다. 불량은 사진과 함께 알려주시면 왕복 배송비 없이 바꿔 드립니다.</p></details>
   </div></section>
 </div>`;
};

V.cart=()=>{
 if(!S.cart.length)return `<div class="wrap"><h1 class="pagetitle">장바구니</h1>
  <div class="empty"><div class="ico">${I.bag}</div><p>담긴 상품이 없습니다.</p>
  <a class="btn btn-p" href="#/shop">상품 보러가기</a></div></div>`;
 return `<div class="wrap"><h1 class="pagetitle">장바구니 <span class="n" style="color:var(--ink3);font-size:.6em">${cartQty()}병</span></h1>
 <div class="panel">${S.cart.map((c,i)=>{const p=find(c.pid);return `<div class="lrow">
  <span class="lthumb" style="background:${p.t}"><span class="btl" style="background:${p.c}"></span></span>
  <span class="lbody"><span class="t">${esc(p.n)}</span>
   <span class="s">${c.bundle} · ${c.nic} · 병당 ${won(Math.round(c.pay/c.q))}원</span>
   <span class="qty"><button data-dec="${i}" aria-label="수량 줄이기">−</button><span class="n">${c.q}</span>
   <button data-inc="${i}" aria-label="수량 늘리기">+</button></span></span>
  <span class="lend"><b class="n">${won(c.pay)}원</b><br>
   <button class="lk" data-del="${i}" style="font-size:13.5px;color:var(--ink3)">삭제</button></span></div>`}).join('')}</div>
 <div class="totals"><div><span>상품 금액</span><span class="n">${won(cartSum())}원</span></div>
  <div><span>배송비</span><span class="n">${ship()?won(ship())+'원':'무료'}</span></div>
  <div class="big"><span>결제 예정 금액</span><b class="n">${won(cartSum()+ship())}원</b></div></div>
 ${ship()?`<p style="font-size:13.5px;color:var(--ink3);margin-top:.5rem">30,000원 이상 구매 시 배송비 무료입니다.</p>`:''}
 <div style="margin-top:1.2rem"><a class="btn btn-p btn-w" href="#/checkout">주문서 작성</a></div>
 <div style="height:2rem"></div></div>`;
};

V.checkout=()=>{
 if(!S.cart.length)return V.cart();
 if(!S.user)return V.login('checkout');
 return `<div class="wrap"><h1 class="pagetitle">주문서</h1>
 <div class="steps"><div class="on">1 주문서</div><div>2 입금</div><div>3 완료</div></div>
 <div class="panel"><h3>주문자</h3>
  <div class="field"><label>이름</label><input id="oName" value="${esc(S.user.name)}"></div>
  <div class="field"><label>연락처</label><input id="oTel" value="${esc(S.user.tel)}"></div></div>
 <div class="panel"><h3>배송지</h3>
  <div class="field"><label>주소</label><input id="oAddr" placeholder="주소를 입력하세요" value="서울시 강남구 테헤란로 1"></div>
  <div class="field"><label>배송 메모</label><input id="oMemo" placeholder="부재 시 문 앞에 놓아주세요"></div></div>
 <div class="panel"><h3>결제</h3>
  <p style="font-size:14px;color:var(--ink2);margin-bottom:.8rem">무통장입금 전용입니다. 카드결제는 받지 않습니다.</p>
  <div class="field key"><label>입금자명</label>
   <input id="oDep" value="${esc(S.user.name)}">
   <span class="hint"><b>주문자명과 똑같이</b> 넣어주세요. 다르면 자동 확인이 안 되고
   <b>확인필요</b> 상태로 넘어가 하루쯤 늦어집니다.</span></div>
  <div class="acct"><span class="v"><b>국민 ●●●●●●-●●-●●●●●●</b><span>예금주 액상덕후</span></span>
   <button class="btn btn-d btn-sm" data-act="copy">복사</button></div></div>
 <div class="totals"><div><span>상품 금액</span><span class="n">${won(cartSum())}원</span></div>
  <div><span>배송비</span><span class="n">${ship()?won(ship())+'원':'무료'}</span></div>
  <div class="big"><span>입금하실 금액</span><b class="n">${won(cartSum()+ship())}원</b></div></div>
 <div style="margin-top:1.2rem"><button class="btn btn-p btn-w" data-act="order">주문하기</button></div>
 <div style="height:2rem"></div></div>`;
};

V.done=oid=>{
 const o=S.orders.find(x=>x.id===oid); if(!o)return V.e404();
 return `<div class="wrap"><div class="big-ok"><div class="ico">${I.check}</div>
  <h1 style="font-size:1.5rem">주문이 접수됐습니다</h1>
  <p style="color:var(--ink2);font-size:15px;margin-top:.5rem">주문번호 #${o.id}</p></div>
 <div class="panel"><h3>입금 안내</h3>
  <div class="acct"><span class="v"><b>국민 ●●●●●●-●●-●●●●●●</b><span>예금주 액상덕후</span></span>
   <button class="btn btn-d btn-sm" data-act="copy">복사</button></div>
  <div class="totals" style="margin-top:.7rem"><div class="big"><span>입금하실 금액</span>
   <b class="n">${won(o.total)}원</b></div></div>
  <div class="note" style="margin-top:.8rem">입금자명 <b>${esc(o.depositor)}</b> 으로 보내주세요.
   이름이 다르면 자동 확인이 안 됩니다.</div></div>
 <div style="margin-top:1.2rem;display:flex;gap:.5rem"><a class="btn btn-o" style="flex:1" href="#/orders">주문내역</a>
  <a class="btn btn-p" style="flex:1" href="#/shop">계속 쇼핑</a></div><div style="height:2rem"></div></div>`;
};

V.login=next=>`<div class="wrap" style="max-width:440px"><h1 class="pagetitle">로그인</h1>
 <div class="panel">
  <div class="field"><label>아이디</label><input id="lId" placeholder="아이디" value="duckhoo"></div>
  <div class="field"><label>비밀번호</label><input id="lPw" type="password" placeholder="비밀번호" value="demo1234"></div>
  <button class="btn btn-p btn-w" data-act="login" data-next="${next||''}">로그인</button>
  <p style="text-align:center;font-size:14px;color:var(--ink3);margin-top:1rem">
   아직 회원이 아니신가요? <a class="lk" href="#/join">회원가입</a></p></div>
 <p style="font-size:13.5px;color:var(--ink3);margin-top:1rem;text-align:center">데모입니다. 아무 값이나 넣어도 로그인됩니다.</p>
 <div style="height:2rem"></div></div>`;

V.join=()=>`<div class="wrap" style="max-width:440px"><h1 class="pagetitle">회원가입</h1>
 <div class="steps"><div class="${state.joined?'':'on'}">1 본인확인</div><div class="${state.joined?'on':''}">2 정보입력</div></div>
 ${!state.joined?`<div class="panel">
  <h3>휴대폰 본인확인</h3>
  <p style="font-size:14px;color:var(--ink2);line-height:1.7">전자담배 액상은 <b style="color:var(--ink)">19세 미만 판매 금지</b> 품목입니다.
   PASS 앱으로 본인확인을 완료해야 가입할 수 있습니다.</p>
  <div class="warnbox" style="margin-top:.9rem">본인확인은 건너뛸 수 없습니다.
   미성년자 확인 시 가입이 거부되고, 이미 가입된 계정은 정지됩니다.</div>
  <button class="btn btn-p btn-w" style="margin-top:1rem" data-act="pass">PASS로 본인확인</button>
 </div>`:`<div class="panel">
  <div class="note" style="margin-bottom:1rem">본인확인 완료 · <b>만 19세 이상</b> 확인됨</div>
  <div class="field"><label>아이디</label><input id="jId" placeholder="영문 소문자, 숫자 4~20자"></div>
  <div class="field"><label>비밀번호</label><input id="jPw" type="password" placeholder="8자 이상"></div>
  <div class="field"><label>이름</label><input id="jName" placeholder="실명" value="홍길동"></div>
  <div class="field"><label>연락처</label><input id="jTel" value="010-0000-0000"></div>
  <div class="note">가입 후 주문 시 <b>입금자명은 여기 적은 이름</b>과 같아야 자동 확인됩니다.</div>
  <button class="btn btn-p btn-w" style="margin-top:1rem" data-act="join">가입 완료</button>
 </div>`}<div style="height:2rem"></div></div>`;

V.orders=()=>{
 if(!S.user)return V.login('orders');
 if(!S.orders.length)return `<div class="wrap"><h1 class="pagetitle">주문내역</h1>
  <div class="empty"><div class="ico">${I.receipt}</div><p>주문 내역이 없습니다.</p>
  <a class="btn btn-p" href="#/shop">상품 보러가기</a></div></div>`;
 return `<div class="wrap"><h1 class="pagetitle">주문내역</h1>
 ${S.orders.map(o=>`<a class="panel" style="display:block;margin-bottom:.7rem" href="#/order/${o.id}">
  <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.7rem">
   <span class="n" style="font-size:14px;color:var(--ink3);font-weight:600">#${o.id}</span>
   ${stBadge(o.st)}<span style="margin-left:auto;font-size:13.5px;color:var(--ink3)">${o.date}</span></div>
  ${o.items.slice(0,2).map(c=>{const p=find(c.pid);return `<div class="lrow" style="padding:.5rem 0;border:0">
   <span class="lthumb" style="background:${p.t};flex-basis:46px;width:46px;height:46px">
    <span class="btl" style="background:${p.c};width:20px;height:34px"></span></span>
   <span class="lbody"><span class="t" style="font-size:13px">${esc(p.n)}</span>
    <span class="s">${c.bundle} · ${c.q}병</span></span></div>`}).join('')}
  ${o.items.length>2?`<p style="font-size:13.5px;color:var(--ink3);padding-left:56px">외 ${o.items.length-2}건</p>`:''}
  <div style="display:flex;align-items:baseline;margin-top:.6rem;padding-top:.6rem;border-top:1px solid var(--line)">
   <span style="font-size:14px;color:var(--ink3)">결제 금액</span>
   <b class="n" style="margin-left:auto;font-size:15px">${won(o.total)}원</b></div></a>`).join('')}
 <div style="height:2rem"></div></div>`;
};

V.order=oid=>{
 const o=S.orders.find(x=>x.id===oid); if(!o)return V.e404();
 /* 취소는 입금전 하나뿐이다. 확인필요부터는 돈이 이미 들어와 있어 사람이 봐야 한다. */
 const canCancel=o.st==='pend';
 const why=o.st==='chk'?'입금자명 확인 중이라 직접 취소할 수 없습니다.'
  :o.st==='paid'?'입금이 확인된 주문은 직접 취소할 수 없습니다.'
  :'배송이 시작된 주문은 직접 취소할 수 없습니다.';
 return `<div class="wrap"><div class="crumb"><a href="#/orders">주문내역</a> › #${o.id}</div>
 <h1 class="pagetitle">주문 상세</h1>
 <div class="panel"><div style="display:flex;align-items:center;gap:.6rem">
  ${stBadge(o.st)}<span style="margin-left:auto;font-size:13.5px;color:var(--ink3);font-weight:600">${o.date}</span></div>
  ${o.st==='pend'?`<div class="note" style="margin-top:.8rem">입금자명 <b>${esc(o.depositor)}</b> 으로
   <b>${won(o.total)}원</b> 입금해 주세요.</div>`:''}
  ${o.st==='chk'?`<div class="warnbox" style="margin-top:.8rem"><b>입금자명이 주문자명과 다릅니다.</b>
   확인에 시간이 걸립니다. 카카오톡으로 문의해 주시면 빠르게 처리됩니다.</div>`:''}</div>
 <div class="panel">${o.items.map(c=>{const p=find(c.pid);return `<div class="lrow">
  <span class="lthumb" style="background:${p.t}"><span class="btl" style="background:${p.c}"></span></span>
  <span class="lbody"><span class="t">${esc(p.n)}</span><span class="s">${c.bundle} · ${c.nic} · ${c.q}병</span></span>
  <span class="lend"><b class="n">${won(c.pay)}원</b></span></div>`}).join('')}</div>
 <div class="totals"><div><span>상품 금액</span><span class="n">${won(o.sum)}원</span></div>
  <div><span>배송비</span><span class="n">${o.ship?won(o.ship)+'원':'무료'}</span></div>
  <div class="big"><span>결제 금액</span><b class="n">${won(o.total)}원</b></div></div>
 <div style="margin-top:1.2rem;display:flex;gap:.5rem;flex-wrap:wrap">
  ${canCancel?`<button class="btn btn-dg" style="flex:1" data-act="cancel" data-oid="${o.id}">주문 취소</button>`
   :`<button class="btn btn-o" style="flex:1" disabled title="${why}">주문 취소</button>`}
  <a class="btn btn-o" style="flex:1" href="#/orders">목록으로</a></div>
 ${canCancel?'':`<p style="font-size:13.5px;color:var(--ink3);margin-top:.6rem">
  ${why} 카카오톡으로 문의해 주세요.</p>`}
 <div style="height:2rem"></div></div>`;
};

V.my=()=>{
 if(!S.user)return V.login('my');
 return `<div class="wrap"><h1 class="pagetitle">마이페이지</h1>
 <div class="panel"><div style="display:flex;align-items:center;gap:.8rem">
  <span style="width:46px;height:46px;border-radius:999px;background:var(--acsoft);display:flex;align-items:center;
   justify-content:center;color:var(--acdeep)">${I.user}</span>
  <span><b style="font-size:15px">${esc(S.user.name)}</b>
   <span style="display:block;font-size:13.5px;color:var(--ink3)">${esc(S.user.id)} · 일반회원</span></span>
  <span style="margin-left:auto;text-align:right"><b class="n" style="font-size:15px">${won(S.points)}</b>
   <span style="display:block;font-size:13.5px;color:var(--ink3)">적립금</span></span></div>
  <div class="note" style="margin-top:.9rem">본인확인 완료 · 만 19세 이상</div></div>
 <div class="mine">
  <a href="#/orders">${I.receipt}주문내역</a><a href="#/cart">${I.bag}장바구니</a>
  <a href="#/shop">${I.heart}찜한 상품</a><a href="#/my">${I.coin}적립금</a></div>
 <div class="panel" style="margin-top:1rem"><h3>계정</h3>
  <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.6rem">
   <button class="btn btn-o btn-sm" data-act="logout">로그아웃</button>
   <button class="btn btn-dg btn-sm" data-act="leave">회원 탈퇴</button></div></div>
 <div style="height:2rem"></div></div>`;
};

/* 검색 — 이름·브랜드·맛·니코틴을 한 덩어리로 놓고 본다.
   무니코틴처럼 사람이 쓰는 말이 데이터에 없을 때가 있어 그건 따로 붙여 준다. */
const hay=p=>[p.n,p.b,p.f,p.nic.join(' '),p.nic[0]==='0mg'?'무니코틴 노니코틴':'',p.was?'특가 세일 9월':'',p.tag||'']
 .join(' ').toLowerCase().replace(/\s+/g,'');
const searchHits=q=>{
 const k=q.trim().toLowerCase().replace(/\s+/g,'');
 if(!k)return [];
 return P.filter(p=>hay(p).includes(k));
};
const SKW=['무니코틴','멘솔','9.8mg','액상덕후','청포도','9월 특가'];

const searchBody=q=>{
 if(!q.trim())return `<div class="skw">${SKW.map(k=>`<button class="chip" data-kw="${k}">${k}</button>`).join('')}</div>
  <div class="sec-h" style="margin-top:.4rem"><h2 style="font-size:1.05rem">많이 찾는 상품</h2></div>
  <div class="grid">${P.filter(p=>p.tag).map(card).join('')}</div>`;
 const hits=searchHits(q);
 if(!hits.length)return `<div class="empty"><div class="ico">${I.search}</div>
  <p><b>${esc(q)}</b> 에 맞는 상품이 없습니다.<br>브랜드나 맛으로 다시 찾아보세요.</p>
  <div class="skw" style="justify-content:center">${SKW.map(k=>`<button class="chip" data-kw="${k}">${k}</button>`).join('')}</div></div>`;
 return `<p style="font-size:14px;color:var(--ink3);margin-bottom:.8rem">${hits.length}종</p>
  <div class="grid">${hits.map(card).join('')}</div>`;
};

V.search=()=>`<div class="wrap"><div class="crumb"><a href="#/">홈</a> › 검색</div>
 <h1 class="pagetitle">검색</h1>
 <form class="sfield" id="sform" role="search">
  ${I.search}
  <input id="sq" type="search" enterkeyhint="search" autocomplete="off"
   placeholder="상품명 · 브랜드 · 맛 · 니코틴" aria-label="상품 검색" value="${esc(S.q)}">
  ${S.q?`<button type="button" class="x" data-kw="" aria-label="검색어 지우기">${I.x}</button>`:''}
 </form>
 <div id="sres">${searchBody(S.q)}</div><div style="height:2rem"></div></div>`;

V.e404=()=>`<div class="wrap"><div class="empty"><div class="ico">${I.spark}</div>
 <p>페이지를 찾을 수 없습니다.</p><a class="btn btn-p" href="#/">홈으로</a></div></div>`;

/* ── 라우터 ── */
let state={bi:1,ni:0,joined:false};
function route(){
 const h=location.hash.slice(1)||'/';
 const [path,qs]=h.split('?');
 const q=new URLSearchParams(qs||'');
 const seg=path.split('/').filter(Boolean);
 let html;
 if(!seg.length)html=V.home();
 else if(seg[0]==='shop')html=V.shop(q);
 else if(seg[0]==='p'){state.bi=1;state.ni=0;html=V.p(seg[1])}
 else if(seg[0]==='cart')html=V.cart();
 else if(seg[0]==='checkout')html=V.checkout();
 else if(seg[0]==='done')html=V.done(+seg[1]);
 else if(seg[0]==='login')html=V.login();
 else if(seg[0]==='join')html=V.join();
 else if(seg[0]==='orders')html=V.orders();
 else if(seg[0]==='order')html=V.order(+seg[1]);
 else if(seg[0]==='search'){if(q.has('q'))S.q=q.get('q');html=V.search()}
 else if(seg[0]==='my')html=V.my();
 else html=V.e404();
 $('#view').innerHTML=html;
 $('#view').scrollTo(0,0);
 /* 큰 제목이 올라가면 헤더 가운데에 작은 제목이 뜬다 */
 const big=$('#view h1');
 const bt=big?big.textContent.replace(/\s+/g,' ').trim().slice(0,18):'';
 $('#ttl').textContent=bt==='액상덕후'?'':bt; /* 홈은 로고가 이미 그 말이다 */
 $('.gnb').classList.remove('small');
 syncChrome(seg[0]||'');
 if(seg[0]==='search'){const i=$('#sq'); if(i){const v=i.value;i.focus();i.setSelectionRange(v.length,v.length)}}
 if(window.gsap&&!matchMedia('(prefers-reduced-motion: reduce)').matches)
  gsap.fromTo('#view > *',{opacity:0,y:10},{opacity:1,y:0,duration:.3,ease:'power2.out'});
}
function syncChrome(k){
 $('#cartN').textContent=cartQty();
 $('#cartN').style.display=cartQty()?'flex':'none';
 const who=$('#who');
 who.innerHTML=S.user?esc(S.user.name.slice(0,1)):I.user;
 who.classList.toggle('in',!!S.user);
 who.setAttribute('href',S.user?'#/my':'#/login');
 who.setAttribute('aria-label',S.user?S.user.name+' 마이페이지':'로그인');
 const tb=$('#tabN'); if(tb){tb.textContent=cartQty();tb.style.display=cartQty()?'flex':'none'}
 document.querySelectorAll('.tabs a').forEach(a=>a.classList.toggle('on',a.dataset.k===(k||'')));
 document.querySelectorAll('.gnb nav a').forEach(a=>a.classList.toggle('on',a.dataset.k===(k||'')));
}
const rerender=()=>route();

/* 큰 제목이 헤더 뒤로 들어가면 작은 제목을 켠다 */
$('#view').addEventListener('scroll',()=>{
 const big=$('#view h1'), g=$('.gnb');
 if(!big){g.classList.toggle('small',$('#view').scrollTop>8);return}
 g.classList.toggle('small',big.getBoundingClientRect().bottom<g.getBoundingClientRect().bottom+4);
},{passive:true});

/* ── 이벤트 ── */
/* 결과만 갈아끼운다. 화면 전체를 다시 그리면 입력 포커스가 날아간다. */
function paintSearch(){
 const box=$('#sres'); if(box)box.innerHTML=searchBody(S.q);
 const f=$('#sform'); if(f){
  const x=f.querySelector('.x');
  if(S.q&&!x){f.insertAdjacentHTML('beforeend',`<button type="button" class="x" data-kw="" aria-label="검색어 지우기">${I.x}</button>`)}
  else if(!S.q&&x){x.remove()}
 }
 history.replaceState(null,'','#/search'+(S.q?'?q='+encodeURIComponent(S.q):''));
}
document.addEventListener('input',e=>{
 if(!e.target.closest('#sq'))return;
 S.q=e.target.value; paintSearch();
});
document.addEventListener('submit',e=>{
 const f=e.target.closest('#sform'); if(!f)return;
 e.preventDefault(); $('#sq')?.blur();
});

document.addEventListener('click',e=>{
 const t=e.target;
 const jump=t.closest('[data-jump]');
 if(jump){e.preventDefault();const sec=document.getElementById(jump.dataset.jump);
  if(sec){sec.scrollIntoView({behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth',block:'start'})}
  document.querySelectorAll('.pdx-nav a').forEach(a=>a.classList.toggle('on',a===jump));return}
 const kw=t.closest('[data-kw]');
 if(kw){S.q=kw.dataset.kw;const i=$('#sq');if(i)i.value=S.q;paintSearch();if(!S.q&&i)i.focus();return}
 const flt=t.closest('[data-flt]'); if(flt){S.flt=flt.dataset.flt;rerender();return}
 const bi=t.closest('[data-bi]'); if(bi){state.bi=+bi.dataset.bi;rerender();return}
 const ni=t.closest('[data-ni]'); if(ni){state.ni=+ni.dataset.ni;rerender();return}
 const inc=t.closest('[data-inc]'); if(inc){const c=S.cart[+inc.dataset.inc];
  c.q+=c.per;c.pay+=c.unit;rerender();return}
 const dec=t.closest('[data-dec]'); if(dec){const i=+dec.dataset.dec,c=S.cart[i];
  if(c.q<=c.per){S.cart.splice(i,1)}else{c.q-=c.per;c.pay-=c.unit}rerender();return}
 const del=t.closest('[data-del]'); if(del){S.cart.splice(+del.dataset.del,1);rerender();toast('삭제했습니다');return}
 const a=t.closest('[data-act]'); if(!a)return;
 const act=a.dataset.act;

 if(act==='cart'||act==='buy'){
  const pid=+location.hash.split('/')[2],p=find(pid),b=BUNDLES[state.bi];
  const pay=(b.mult||b.q)*p.p;
  S.cart.push({pid,bundle:b.k,nic:p.nic[state.ni],q:b.q,per:b.q,unit:pay,pay});
  if(act==='cart'){syncChrome(location.hash.split('/')[1]);toast('장바구니에 담았습니다')}
  else location.hash='#/checkout';
  return;
 }
 if(act==='copy'){toast('계좌번호를 복사했습니다');return}
 if(act==='login'){
  const id=$('#lId').value.trim()||'duckhoo';
  S.user={id,name:'홍길동',tel:'010-0000-0000'};
  toast('로그인했습니다');
  location.hash=a.dataset.next?'#/'+a.dataset.next:'#/my'; rerender(); return;
 }
 if(act==='pass'){
  modal('PASS 본인확인','<p>실제 사이트에서는 여기서 PASS 앱 인증창이 열립니다. 데모에서는 통과한 것으로 처리합니다.</p>',
   '인증 완료',()=>{state.joined=true;rerender();toast('본인확인이 완료됐습니다')});
  return;
 }
 if(act==='join'){
  const name=$('#jName').value.trim()||'홍길동';
  S.user={id:$('#jId').value.trim()||'duckhoo',name,tel:$('#jTel').value.trim()};
  state.joined=false; location.hash='#/my'; rerender(); toast('가입이 완료됐습니다'); return;
 }
 if(act==='order'){
  const dep=$('#oDep').value.trim(),name=$('#oName').value.trim();
  const id=++S.seq, sum=cartSum(), sh=ship();
  /* 입금자명이 주문자명과 다르면 확인필요로 들어간다 — 실제 매칭 규칙 그대로 */
  const st=dep===name?'pend':'chk';
  S.orders.unshift({id,st,date:'2026.09.02',items:S.cart.slice(),sum,ship:sh,total:sum+sh,depositor:dep});
  S.cart=[]; location.hash='#/done/'+id; rerender();
  if(st==='chk')toast('입금자명이 주문자명과 달라 확인필요로 접수됐습니다');
  return;
 }
 if(act==='cancel'){
  const oid=+a.dataset.oid;
  modal('주문을 취소할까요?','<p>취소하면 되돌릴 수 없습니다. 이미 입금하셨다면 환불까지 1~2일 걸립니다.</p>',
   '주문 취소',()=>{S.orders=S.orders.filter(o=>o.id!==oid);location.hash='#/orders';rerender();
    toast('주문이 취소됐습니다')},true);
  return;
 }
 if(act==='logout'){S.user=null;location.hash='#/';rerender();toast('로그아웃했습니다');return}
 if(act==='leave'){
  /* 실제 사이트와 같은 규칙: 끝나지 않은 주문이 있거나 적립금이 남아 있으면 막는다.
     계정을 지우지는 않는다 — 로그인을 끊고 개인정보만 지운다. 주문 기록은 5년 보존이다. */
  const stop=leaveBlockers();
  if(stop.length){
   modal('지금은 탈퇴할 수 없습니다',
    '<ul style="margin:0;padding-left:1.1rem">'+stop.map(r=>`<li>${r}</li>`).join('')+'</ul>',
    '알겠습니다',null);
   return;
  }
  modal('정말 탈퇴하시겠어요?',
   '<p>탈퇴하면 로그인할 수 없게 되고 이름·연락처·주소가 지워집니다. 되돌릴 수 없습니다.</p>'+
   '<p style="margin-top:.6rem">주문 기록은 남습니다. 전자상거래법상 거래기록은 5년간 보관해야 합니다.</p>',
   '탈퇴하기',()=>{S={user:null,cart:[],orders:[],flt:'전체',seq:S.seq,points:0};
    location.hash='#/';rerender();toast('탈퇴가 완료됐습니다')},true);
  return;
 }
});
$('#dim').addEventListener('click',closeModal);
$('#mCancel').addEventListener('click',closeModal);
document.addEventListener('keydown',e=>{if(e.key==='Escape')closeModal()});
window.addEventListener('hashchange',route);

/* 데모용 예시 주문 — 상태별로 하나씩 */
S.orders=[
 {id:10481,st:'ship',date:'2026.08.30',items:[{pid:12,bundle:'3+1 · 총 4병',nic:'9.8mg',q:4,per:4,unit:39000,pay:39000}],
  sum:39000,ship:0,total:39000,depositor:'홍길동'},
 {id:10480,st:'chk',date:'2026.08.28',items:[{pid:4,bundle:'단품 1병',nic:'0mg',q:1,per:1,unit:8100,pay:8100}],
  sum:8100,ship:3000,total:11100,depositor:'홍길순'},
 {id:10479,st:'pend',date:'2026.08.27',items:[{pid:13,bundle:'단품 1병',nic:'9.8mg',q:1,per:1,unit:15000,pay:15000}],
  sum:15000,ship:3000,total:18000,depositor:'홍길동'},
];
route();
