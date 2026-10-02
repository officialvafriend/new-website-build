#!/usr/bin/env python3
"""vafriend.com 의 리뷰 구역을 네이버 플레이스 실제 방문자 리뷰로 바꾼다.
입력: vfrev/reviews.json (naver 에서 받은 것) · vfsite/ (원본 폴더)
출력: vfsite_out/ (고친 폴더)"""
import json,re,shutil,html,datetime,sys
from pathlib import Path
SP=Path(__file__).resolve().parent
SRC=SP/'vfsite'; OUT=SP/'vfsite_out'
D=json.load(open(SP/'vfrev/reviews.json',encoding='utf-8'))
TODAY=datetime.date(2026,10,2)
ASOF='2026.10'   # 화면에 적는 기준 달

def kdate(v):
    """'10.2.금' → 2026.10.02 · '20.11.14.토' → 2020.11.14"""
    p=v.split('.')
    if len(p)==4: y,m,d=2000+int(p[0]),int(p[1]),int(p[2])
    elif len(p)==3:
        m,d=int(p[0]),int(p[1]); y=TODAY.year if (m,d)<=(TODAY.month,TODAY.day) else TODAY.year-1
    else: return v
    return f'{y}.{m:02d}.{d:02d}'
def mask(n):
    n=(n or '').strip()
    if not n: return '네이버 회원'
    if '*' in n: return n            # 네이버가 이미 가린 꼴 (tmd****)
    return n[0]+'*'*max(2,min(len(n)-1,3))
def stars(r):
    full=int(round(r or 5))
    return ''.join('<span>★</span>' if i<full else '<span style="opacity:.25">★</span>' for i in range(5))
def esc(t): return html.escape(t,quote=False)

stores=[]; allrev=[]
for name,v in D.items():
    link=f"https://m.place.naver.com/place/{v['pid']}/review/visitor"
    stores.append({'name':name,'pid':v['pid'],'total':v['total'],'score':v['score'],'dist':v['dist'],'scored':v['scored'],'link':link})
    for r in v['reviews']:
        b=re.sub(r'\s+',' ',r['body'] or '').strip()
        if len(b)<4: continue
        allrev.append({'store':name,'link':link,'body':b,'nick':mask(r['nick']),'date':kdate(r['visited']),'rating':r['rating'] or 5,'origin':r['origin'] or '','n':r['visitCount'] or 1})
allrev.sort(key=lambda r:r['date'],reverse=True)
total=sum(s['total'] for s in stores); wscore=sum(s['total']*s['score'] for s in stores)/total
dist={k:sum(s['dist'][k] for s in stores) for k in '54321'}; scored=sum(dist.values())
pct={k:dist[k]/scored*100 for k in dist}
def pstr(x): return ('0%' if x==0 else f'{x:.1f}%'.replace('.0%','%') if x>=1 else f'{x:.1f}%')
print(f'텍스트 리뷰 {len(allrev)}개 · 전체 {total:,} · 가중 평균 {wscore:.2f} · 분포 {[pstr(pct[k]) for k in "54321"]}')
by_store={s['name']:sum(1 for r in allrev if r['store']==s['name']) for s in stores}; print(by_store)

# ── 홈 index.html ──────────────────────────────────────────────
idx=(SRC/'index.html').read_text(encoding='utf-8')
hero=re.search(r'<div class="rating-hero rv">.*?(?=\s*<!-- Label -->)',idx,re.S)
assert hero and 'rating-stats' in hero.group(0)
new_hero=f'''<div class="rating-hero rv">
        <div class="rating-score-block">
          <div class="rating-num">{wscore:.2f}</div>
          <div class="rating-stars-row"><span>★</span><span>★</span><span>★</span><span>★</span><span>★</span></div>
          <div class="rating-label">NAVER PLACE · 대구 직영 8개점 방문자 리뷰 {total:,}개</div>
        </div>
        <div class="rating-divider"></div>
        <div class="rating-stats">
''' + ''.join(f'''          <div class="rating-stat"><div class="rating-stat-num">{k}</div><div class="rating-stat-bar-wrap"><div class="rating-stat-bar" style="width:{max(pct[k],0.5 if pct[k]>0 else 0):.1f}%"></div></div><div class="rating-stat-count">{pstr(pct[k])}</div></div>
''' for k in '54321') + '''        </div>
      </div>'''
idx=idx.replace(hero.group(0),new_hero,1)
idx=idx.replace('<p class="section-subtitle" style="margin-top:16px;">베이프렌드를 직접 경험한 고객분들의 진솔한 후기입니다.</p>',
  f'<p class="section-subtitle" style="margin-top:16px;">네이버 플레이스에 영수증 · 결제내역으로 인증된 방문자 리뷰를 그대로 옮겼습니다. 글은 한 글자도 고치지 않았습니다 ({ASOF} 기준).<br>일부는 매장 리뷰 이벤트에 참여한 뒤 작성된 글입니다.</p>',1)
def home_card(r):
    tag=' · '+('영수증 인증' if r['origin']=='영수증' else '결제 인증' if r['origin']=='결제내역' else '방문자') 
    return (f'<div class="review-card"><div class="review-stars">{stars(r["rating"])}</div><p class="review-text">"{esc(r["body"])}"</p>'
            f'<div class="review-meta"><div class="review-avatar">{esc(r["nick"][0])}</div><div><div class="review-author">{esc(r["nick"])}</div>'
            f'<div class="review-naver-badge">NAVER PLACE · {r["store"]} · {r["date"]}{tag}</div></div></div></div>')
reels=[allrev[i::3] for i in range(3)]
durs=['100s','130s','115s']
tracks=re.findall(r'<div class="reel-track"[^>]*>.*?</div>\s*</div>\s*(?=<div class="reel-col"|</div>\s*<div style)',idx,re.S)
assert len(tracks)==3
for t,cards,du in zip(tracks,reels,durs):
    body=''.join('            '+home_card(r)+'\n' for r in cards)*2   # 두 번 — translateY(-50%) 루프
    idx=idx.replace(t,f'<div class="reel-track" style="animation-duration:{du};">\n{body}          </div>\n        </div>\n        ',1)
assert '기기에서 액상이 새는' not in idx and idx.count('<div class="review-card">')==len(allrev)*2
# 전체 리뷰 보기 → 네이버가 아니라 우리 리뷰 페이지 (그대로) 
(OUT).mkdir(exist_ok=True)

# ── SNS social.html ─────────────────────────────────────────────
soc=(SRC/'social.html').read_text(encoding='utf-8')
ov=re.search(r'<div class="reviews-overall rv-r d2">.*?</div>\s*</div>\s*</div>',soc,re.S); assert ov and 'overall-bars' in ov.group(0)
new_ov=f'''<div class="reviews-overall rv-r d2">
          <div class="overall-score">
            <div class="overall-num">{wscore:.2f}</div>
            <div class="overall-stars">★★★★★</div>
            <div class="overall-count">방문자 리뷰 {total:,}건</div>
          </div>
          <div class="overall-bars">
''' + ''.join(f'''            <div class="bar-row"><span class="bar-label">{k}</span><div class="bar-track"><div class="bar-fill" data-width="{max(pct[k],0.5 if pct[k]>0 else 0):.1f}%"></div></div><span class="bar-count">{pstr(pct[k])}</span></div>
''' for k in '54321') + '''          </div>
        </div>'''
soc=soc.replace(ov.group(0),new_ov,1)
# 지점별 한 줄 (사실 · 링크) — 제목 아래
store_row='<div class="rv d1" style="display:flex;flex-wrap:wrap;gap:8px 14px;margin:18px 0 34px;font-size:0.8rem;color:rgba(var(--text-rgb),0.55);line-height:1.6;">' + ''.join(
  f'<a href="{s["link"]}" target="_blank" rel="noopener" style="color:inherit;text-decoration:none;border-bottom:1px solid rgba(var(--text-rgb),0.18);">{s["name"]} {s["total"]:,} · {s["score"]:.2f}</a>' for s in sorted(stores,key=lambda s:-s['total'])) + \
  f'<span style="flex-basis:100%;font-size:0.72rem;color:rgba(var(--text-rgb),0.4);">네이버 플레이스 방문자 리뷰 · {ASOF} 기준 · 지점 이름을 누르면 네이버에서 전체 리뷰를 볼 수 있습니다 · 일부는 매장 리뷰 이벤트에 참여한 뒤 작성된 글입니다</span></div>\n\n      <div class="reviews-grid">'
soc=soc.replace('<div class="reviews-grid">',store_row,1)
grid=re.search(r'<div class="reviews-grid">(.*?)\n      </div>\n\n      <div class="review-cta',soc,re.S); assert grid
# 그리드 카드: 지점마다 가장 긴 글 하나씩 + 나머지는 길이순, 12장
picked=[]; used=set()
for s in stores:
    c=sorted([r for r in allrev if r['store']==s['name']],key=lambda r:-len(r['body']))
    if c: picked.append(c[0]); used.add(id(c[0]))
rest=sorted([r for r in allrev if id(r) not in used],key=lambda r:-len(r['body']))
picked=(picked+rest)[:12]; picked.sort(key=lambda r:r['date'],reverse=True)
def soc_card(r,i):
    d=['',' d1',' d2'][i%3]
    tag='영수증 인증' if r['origin']=='영수증' else '결제 인증' if r['origin']=='결제내역' else '방문자 리뷰'
    return f'''        <div class="review-card rv{d}">
          <div class="review-meta-top"><span class="review-store-tag">{r['store']}</span><span class="review-date">{r['date']}</span></div>
          <div class="review-header-row">
            <div class="review-stars">{stars(r['rating'])}</div>
          </div>
          <span class="review-tag">{tag}</span>
          <p class="review-text">"{esc(r['body'])}"</p>
          <div class="review-meta">
            <div class="review-avatar">{esc(r['nick'][0])}</div>
            <div>
              <div class="review-author">{esc(r['nick'])}</div>
              <div class="review-source"><a href="{r['link']}" target="_blank" rel="noopener" style="color:inherit;text-decoration:none;">네이버 플레이스 방문자 리뷰 →</a></div>
            </div>
          </div>
        </div>
'''
soc=soc.replace(grid.group(1),'\n'+''.join(soc_card(r,i) for i,r in enumerate(picked)),1)
assert '처음 방문했는데 직원분이 정말 친절하게' not in soc
# 사이트맵 lastmod
sm=(SRC/'sitemap.xml').read_text(encoding='utf-8')
sm=re.sub(r'(<loc>https://vafriend.com/(?:social)?</loc>\s*<lastmod>)[^<]+',r'\g<1>2026-10-02',sm)

if OUT.exists(): shutil.rmtree(OUT)
shutil.copytree(SRC,OUT,ignore=shutil.ignore_patterns('live_*.html'))
(OUT/'index.html').write_text(idx,encoding='utf-8'); (OUT/'social.html').write_text(soc,encoding='utf-8'); (OUT/'sitemap.xml').write_text(sm,encoding='utf-8')
print('written',OUT, 'home cards',idx.count('<div class="review-card">'),'social cards',soc.count('<div class="review-card rv'))
