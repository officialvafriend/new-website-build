/* 커버플로우 — 21st.dev React 컴포넌트를 바닐라로 이식.
   각도/감쇠/투명도/관성 계산식은 원본과 동일하다. */
function coverflow(root, slides, opt={}){
  const o={rotate:44,depth:.6,perspective:3,falloff:.56,fade:.1,gap:.05,loop:true,...opt};
  const n=slides.length;
  root.innerHTML=`<div class="cf-frame" tabindex="0" role="region" aria-roledescription="carousel"
      aria-label="${o.label||'특가 캐러셀'}" style="perspective:calc(var(--cf-card) * ${o.perspective})">
      <div class="cf-track">${slides.map((s,i)=>`
        <div class="cf-card" role="group" aria-roledescription="slide" aria-label="${i+1} / ${n}">
          <div class="cf-img" style="background:${s.bg}"><span class="cf-btl" style="background:${s.c}"></span>
            <span class="cf-flag">${s.flag}</span></div>
        </div>`).join('')}</div></div>
    <div class="cf-cap" id="${root.id}-cap"></div>
    <div class="cf-dots">${slides.map((_,i)=>
      `<button type="button" data-g="${i}" aria-label="${i+1}번째로"></button>`).join('')}</div>`;

  const frame=root.querySelector('.cf-frame'), cards=[...root.querySelectorAll('.cf-card')];
  const cap=root.querySelector('.cf-cap'), dots=[...root.querySelectorAll('.cf-dots button')];
  let pos=0, target=0, w=0, raf=null, drag=null, sel=0;
  const idx=p=>((Math.round(p)%n)+n)%n;

  function paint(){
    if(!w) return;
    const pitch=w*(1+o.gap);
    cards.forEach((card,i)=>{
      let off=i-pos;
      if(o.loop){ off=((off%n)+n)%n; if(off>n/2) off-=n; }
      const d=Math.abs(off), ramp=Math.pow(d,o.falloff);
      const tilt=Math.min(o.rotate*ramp,82)*Math.sign(off);
      card.style.transform=`translateX(calc(-50% + ${off*pitch}px)) translateZ(${-o.depth*w*ramp}px) rotateY(${-tilt}deg)`;
      const edge=o.loop?Math.min(1,Math.max(0,n/2-d)):1;
      card.style.opacity=String(Math.max(0,1-o.fade*d)*edge);
      card.style.zIndex=String(100-Math.round(d));
    });
  }
  function caption(){
    const s=slides[sel];
    cap.innerHTML=`<b>${s.title}</b><span>${s.subtitle}</span>
      <dl>${s.meta.map(m=>`<div><dt>${m.label}</dt><dd>${m.value}</dd></div>`).join('')}</dl>`;
    dots.forEach((d,i)=>d.setAttribute('aria-current',i===sel));
    o.onSel&&o.onSel(sel);
  }
  function setSel(v){ if(v!==sel){ sel=v; caption(); } }
  function settle(t){
    if(raf) cancelAnimationFrame(raf);
    target=t; setSel(idx(t));
    (function step(){
      const rem=target-pos;
      if(Math.abs(rem)<.0004){ pos=target; paint(); raf=null; return; }
      pos+=rem*.16; paint(); raf=requestAnimationFrame(step);
    })();
  }
  const clamp=p=>o.loop?p:Math.max(0,Math.min(n-1,p));
  const goTo=i=>settle(clamp(o.loop?i+Math.round((target-i)/n)*n:i));
  const nudge=by=>settle(clamp(Math.round(target)+by));

  frame.addEventListener('pointerdown',e=>{
    if(raf){cancelAnimationFrame(raf);raf=null}
    frame.setPointerCapture(e.pointerId); target=pos;
    drag={id:e.pointerId,x:e.clientX,pos,v:0,t:performance.now()};
  });
  frame.addEventListener('pointermove',e=>{
    if(!drag||drag.id!==e.pointerId) return;
    const pitch=w*(1+o.gap); if(!pitch) return;
    const now=performance.now(), prev=pos;
    pos=clamp(drag.pos-(e.clientX-drag.x)/pitch);
    drag.v=((pos-prev)/Math.max(now-drag.t,1))*1000; drag.t=now;
    setSel(idx(pos)); paint();
  });
  const end=e=>{
    if(!drag||drag.id!==e.pointerId) return;
    const carried=Math.max(-2,Math.min(2,drag.v*.18)); drag=null;
    settle(clamp(Math.round(pos+carried)));
  };
  frame.addEventListener('pointerup',end); frame.addEventListener('pointercancel',end);
  frame.addEventListener('keydown',e=>{
    if(e.key==='ArrowLeft'){e.preventDefault();nudge(-1)}
    else if(e.key==='ArrowRight'){e.preventDefault();nudge(1)}
  });
  root.querySelector('.cf-dots').addEventListener('click',e=>{
    const b=e.target.closest('button[data-g]'); if(b) goTo(+b.dataset.g);
  });
  const measure=()=>{ w=cards[0].offsetWidth; paint(); };
  new ResizeObserver(measure).observe(frame);
  measure(); caption();
  return {nudge,goTo};
}
