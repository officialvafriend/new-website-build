"""베이프렌드 10월 웹사이트(네이버 웹 검색) 쉬운 보고서 — 우리 사이트 넷.
- 홈페이지 vafriend.com · 블로그 모음 대구전자담배.com · 이벤트 대구전자담배이벤트.com · 각인 갤러리 전자담배각인.com
- 2026-10-30-sites.json: 10월 30일 네이버 웹 탭(블로그 · 카페 섞인 첫 쪽 20건)에서 검색어 41개를 잰 것.
  자리 = 블로그 · 카페 · 지식인을 뺀 웹사이트끼리의 순서 (web_hits)
- 10월 1일 값은 9월 보고(옛 웹문서 탭 15건, 홈페이지만 셈), 10월 9일 값은 받은 통합성과 엑셀(홈페이지 · 블로그 모음만 셈)
python3 build_oct.py 2026-10-seo-vafriend.html"""
import sys, json
sys.path.insert(0, '../reports-lib')
from easy import *

D = json.load(open('2026-10-30-sites.json'))
SITES = [('vafriend.com', '홈페이지', '매장 · 메뉴 · 각인 안내'), ('대구전자담배.com', '블로그 모음', '지점 · 액상 글 모음'),
         ('대구전자담배이벤트.com', '이벤트', '할인 · 증정 소식'), ('전자담배각인.com', '각인 갤러리', '레이저 각인 작업물')]
NAME = dict((h, n) for h, n, _ in SITES)
def pos(q, h):
    for p, x in D[q]['web_hits']:
        if x == h: return p
def best(q):
    h = D[q]['web_hits']
    return (h[0][0], NAME[h[0][1]]) if h else None
def cell(p): return '–' if p is None else f'<b>{p}</b>' if p == 1 else str(p)
def ugc(q): return not D[q]['top3']

NAMEQ = ['베이프렌드', 'vafriend', '대구전자담배매장']
EVENT = ['대구전자담배이벤트', '대구전자담배 이벤트', '대구전자담배할인', '전자담배 할인 이벤트', '전자담배이벤트']
ENGR = ['전자담배각인', '전자담배 각인', '대구전자담배각인', '전자담배 레이저각인', '대구 각인']
BROAD = ['대구전자담배블로그', '대구전자담배', '대구전자담배 추천', '대구전자담배액상', '대구일회용전자담배', '대구액상']
BRANCH = [('동성로', '동성로전자담배'), ('동성로', '대구동성로전자담배'), ('본점', '동대구전자담배'), ('본점', '대구동구전자담배'),
          ('수성', '수성전자담배'), ('수성', '수성구전자담배'), ('수성', '대구수성전자담배'), ('율하', '율하전자담배'), ('율하', '대구율하전자담배'),
          ('반월당', '반월당전자담배'), ('반월당', '대구반월당전자담배'), ('상인', '상인동전자담배'), ('상인', '대구상인동전자담배'),
          ('상인', '대구상인전자담배'), ('광장코아', '광장코아전자담배'), ('광장코아', '대구광장코아전자담배'), ('현풍', '현풍전자담배'),
          ('현풍', '대구현풍전자담배'), ('두류', '두류동전자담배'), ('신천', '대구신천동전자담배')]
ALL = NAMEQ + EVENT + ENGR + BROAD + [q for _, q in BRANCH]
assert set(ALL) <= set(D) and len(ALL) == len(set(ALL))
# 10월 9일 (받은 엑셀, 홈페이지 · 블로그 모음 중 높은 자리)
RK0 = {'동성로전자담배': 1, '대구동성로전자담배': 2, '동대구전자담배': 3, '대구동구전자담배': 4, '수성전자담배': 1, '대구수성전자담배': 3,
       '율하전자담배': 2, '대구율하전자담배': 2, '반월당전자담배': 2, '대구반월당전자담배': 1, '상인동전자담배': 5, '대구상인동전자담배': 4,
       '대구상인전자담배': 4, '광장코아전자담배': 2, '대구광장코아전자담배': 5, '현풍전자담배': 1, '대구현풍전자담배': 4}

top1 = [q for q in ALL if best(q) and best(q)[0] == 1]
page1 = [q for q in ALL if best(q)]
def sitestat(h): return (sum(1 for q in ALL if pos(q, h)), sum(1 for q in ALL if pos(q, h) == 1))
br_top1 = sum(1 for _, q in BRANCH if q in top1)
br_in = sum(1 for _, q in BRANCH if best(q))

d = Doc('베이프렌드 10월 웹사이트 검색', 4)
ev1 = sum(1 for q in EVENT if pos(q, '대구전자담배이벤트.com') == 1)
cov = cover('베이프렌드 · 10월 웹사이트 검색 보고 (네이버 웹 검색)', f'{len(top1)}<small>개</small>',
    f'검색어 {len(ALL)}개 중 {len(top1)}개에서<br>우리 사이트가 웹사이트 1위입니다',
    '홈페이지(vafriend.com) · 블로그 모음(대구전자담배.com) · 이벤트(대구전자담배이벤트.com) · 각인 갤러리(전자담배각인.com), 우리 사이트 넷이 네이버 웹 검색에서 어디에 서 있는지 쟀습니다. 「대구 + 지점」 「이벤트」 「각인」처럼 뜻이 분명한 말은 우리 사이트가 1위를 잡았고, 「대구전자담배」처럼 넓은 말은 첫 쪽이 블로그 · 카페 글로 채워져 있습니다.',
    [(f'{len(page1)}개', f'검색어 {len(ALL)}개 중 첫 쪽에 우리 사이트가 있는 것'), (f'{ev1} / {len(EVENT)}', '이벤트 검색어에서 이벤트 사이트 1위'),
     (f'{br_top1}개', f'지점 검색어 {len(BRANCH)}개 중 1위')],
    '<span>보고 대상 <b>대표님 · 이사님</b></span><span>작성 <b>웹사이트 운영</b></span><span>2026년 10월 30일</span>')

st = []
for h, n, job in SITES:
    a, b = sitestat(h)
    ex = [q for q in ALL if pos(q, h) == 1][:3]
    st.append([f'<b>{n}</b><small>{h} · {job}</small>', f'{a}개', f'<b>{b}개</b>' if b else '0개',
               ' · '.join(f'「{q}」' for q in ex) if ex else '<small>1위 없음 (각인 검색어 2위)</small>'])
p1 = d.frame(1, '한눈에', f'''<h2>우리 사이트 넷이 서로 다른 검색어를 나눠 잡고 있습니다</h2>
<p class="lead">10월 30일 네이버 웹 검색에서 검색어 {len(ALL)}개를 쳐 보고, 블로그 · 카페 글을 뺀 웹사이트끼리의 자리를 셌습니다.</p>
{table(['사이트', '첫 쪽에 든 검색어', '웹사이트 1위', '1위 검색어 (예)'], st, 'fx sm', left=1)}
{rows([
 ('ok', '결과', f'뜻이 분명한 말은 거의 우리 차지입니다', f'지점 검색어 {len(BRANCH)}개 중 {br_in}개가 첫 쪽에 있고 {br_top1}개가 1위입니다. 「대구전자담배이벤트」 「대구전자담배할인」은 이벤트 사이트, 「전자담배각인」은 홈페이지가 1위입니다.'),
 ('mid', '바뀐 것', '네이버 웹 검색 첫 쪽이 블로그 · 카페와 섞였습니다', '10월 중순부터 첫 쪽 20개에 블로그 · 카페 글이 함께 나옵니다. 「대구전자담배액상」 「대구일회용전자담배」 「대구액상」 「대구전자담배 추천」은 첫 쪽 20개가 전부 블로그 · 카페라 어느 가게 사이트도 보이지 않습니다.'),
 ('bad', '걸리는 것', '각인 검색어를 홈페이지와 각인 갤러리가 나눠 갖습니다', '「전자담배각인」 「대구전자담배각인」에서 홈페이지 각인 안내가 1위, 각인 갤러리가 2위입니다. 같은 말을 두 사이트가 나눠 가지면 한쪽이 더 오르기 어렵습니다.'),
])}''')

def line(q):
    h = D[q]['web_hits']
    if not h:
        why = '첫 쪽이 전부 블로그 · 카페' if ugc(q) else '우리 사이트 넷 모두 첫 쪽 밖'
        return f'<li><span class="q">{q}</span><span class="r none">없음</span><small>{why}</small></li>'
    p, nm = h[0][0], NAME[h[0][1]]
    seen = {h[0][1]}; rest = []
    for pp, x in h[1:]:
        if x not in seen: seen.add(x); rest.append(f'{NAME[x]} {pp}위')
    cls = 'win' if p == 1 else 'hi' if p <= 3 else ''
    sm = f'<small>함께: {" · ".join(rest)}</small>' if rest else ''
    return f'<li><span class="q">{q}</span><span class="r {cls}">{p}위 · {nm}</span>{sm}</li>'
def grp(t, sub, qs):
    w = sum(1 for q in qs if best(q) and best(q)[0] == 1)
    return f'<div class="g"><div class="gt">{t}<span>{w} / {len(qs)} 1위</span></div><div class="gs">{sub}</div><ul>{"".join(line(q) for q in qs)}</ul></div>'
CSS2 = """<style>
.grid4 { display: grid; grid-template-columns: 1fr 1fr; gap: 10pt; margin: 4pt 0 12pt; }
.g { border: 1px solid #DCE6F5; border-radius: 10pt; padding: 10pt 12pt; background: #fff; }
.gt { font-size: 13pt; font-weight: 900; display: flex; justify-content: space-between; align-items: baseline; }
.gt span { font-size: 10pt; font-weight: 800; color: #2F6FE0; }
.gs { font-size: 9pt; color: #6B7280; margin: 1pt 0 6pt; }
.g ul { list-style: none; margin: 0; padding: 0; }
.g li { display: grid; grid-template-columns: 1fr auto; column-gap: 6pt; padding: 4pt 0; border-top: 1px solid #EEF2F7; font-size: 10.5pt; }
.g li:first-child { border-top: 0; }
.g li small { grid-column: 1 / -1; font-size: 8.5pt; color: #6B7280; margin-top: 1pt; }
.g .r { font-weight: 800; font-size: 10pt; padding: 1pt 7pt; border-radius: 99pt; background: #F1F5F9; color: #334155; white-space: nowrap; }
.g .r.win { background: #2F6FE0; color: #fff; } .g .r.hi { background: #EAF2FF; color: #2F6FE0; } .g .r.none { background: #FEF3F2; color: #B42318; }
.sites { font-size: 9.5pt; color: #374151; margin: -4pt 0 8pt; }
</style>"""
p2 = d.frame(2, '사이트별 자리', f'''{CSS2}<h2>이름 · 이벤트 · 각인 · 넓은 말: 누가 몇 위인가</h2>
<p class="lead">검색어마다 우리 사이트 중 가장 높은 자리와 그 사이트를 적었습니다. 파란 알약이 웹사이트 1위입니다.</p>
<p class="sites">홈페이지 vafriend.com · 블로그 모음 대구전자담배.com · 이벤트 대구전자담배이벤트.com · 각인 갤러리 전자담배각인.com</p>
<div class="grid4">
{grp('이름', '가게 이름을 친 사람', NAMEQ)}
{grp('이벤트', '할인 · 증정을 찾는 사람', EVENT)}
{grp('각인', '각인을 찾는 사람', ENGR)}
{grp('넓은 말', '대구에서 전자담배를 찾는 사람', BROAD)}
</div>
<p class="note">이벤트는 이벤트 사이트, 각인은 홈페이지, 블로그는 블로그 모음이 1위입니다. 넓은 말은 첫 쪽이 블로그 · 카페뿐이라 어느 가게 사이트도 없습니다.</p>''')

bt = []
for br, q in BRANCH:
    a, b = RK0.get(q), best(q)
    now = f'<b>{b[0]}위</b><small>{b[1]}</small>' if b else ('<span class="dn">첫 쪽 없음</span>')
    ch = ('–' if a is None else '<span class="dn">첫 쪽 밖</span>' if not b else f'<span class="up">{a - b[0]}계단 오름</span>' if b[0] < a
          else f'<span class="dn">{b[0] - a}계단 내림</span>' if b[0] > a else '그대로')
    bt.append([br, q, f'{a}위' if a else '–', now, ch])
p3 = d.frame(3, '지점 검색어', f'''<h2>지점 검색어 {len(BRANCH)}개: 첫 쪽 {br_in}개 · 1위 {br_top1}개</h2>
<p class="lead">10월 30일 자리는 우리 사이트 넷 중 가장 높은 것과 그 사이트입니다. 10월 9일 값은 날마다 재 온 기록에서 뽑았습니다 (그 기록은 홈페이지 · 블로그 모음만 셌습니다).</p>
{table(['지점', '검색어', '10월 9일', '10월 30일', '변화'], bt, 'xs', left=2)}
<p class="note">「동성로 · 반월당 · 율하 · 상인동전자담배」처럼 「대구」가 없는 말은 첫 쪽이 전화번호 · 업체 안내 사이트(114 · 플레이스뷰)와 블로그로 채워져 있습니다. 날마다 잰 기록을 보면 이 말들이 10월 16일 하루에 함께 빠졌습니다. 웹 검색 화면이 바뀐 무렵입니다.</p>''')

goal = table(['9월 보고에서 정한 것', '10월 1일', '10월 목표', '10월 30일'], [
    ['「베이프렌드」 이름 검색', '4위', '1위', f'<b>{best("베이프렌드")[0]}위</b><small>앞은 유튜브 채널</small>'],
    ['「대구전자담배」', '6위', '3위 안', '<span class="dn">첫 쪽 없음</span><small>첫 쪽 웹사이트는 하나뿐, 나머지는 블로그 · 카페</small>'],
    ['9월에 잰 지점 검색어 8개 중 첫 쪽', '5개', '7개',
     f'<b>{sum(1 for q in ["반월당전자담배","동대구전자담배","율하전자담배","동성로전자담배","수성구전자담배","상인동전자담배","두류동전자담배","현풍전자담배"] if best(q))}개</b><small>우리 사이트 넷 기준</small>'],
    ['홈페이지 제목 · 설명이 다 있는 페이지', '2 / 6', '14 / 14', '<span class="dn">2 / 6</span><small>아직 손대지 않음</small>'],
    ['대표 주소 표시 · 옛 주소 정리', '없음', '전부', '<span class="dn">없음</span><small>옛 .html 주소는 여전히 임시 이동</small>']], 'fx')
p4 = d.frame(4, '9월 목표 · 11월', f'''<h2>9월에 정한 목표와 11월에 할 일</h2>
<p class="lead">9월 보고(10월 1일)는 홈페이지 하나만 셌습니다. 이번에는 우리 사이트 넷을 함께 셌으므로 사이트 손질 목표는 그대로 두고 자리만 새 기준으로 적었습니다.</p>
{goal}
<h3>11월에 할 일</h3>
{rows([('next', '1', '지점마다 웹 페이지 한 장씩 (9월에 정한 것)', '「율하전자담배」를 친 사람에게 율하점 한 장을 보여 줍니다. 「대구」 없는 지점 검색어를 되찾는 길입니다.'),
       ('next', '2', '홈페이지 대표 주소 표시 · 옛 주소 영구 이동 · 제목 채우기', '「베이프렌드」 이름 검색 1위가 목표입니다.'),
       ('next', '3', '각인 검색어는 한 사이트에 모으기', '홈페이지 각인 안내에서 각인 갤러리로 이어 주고, 「전자담배각인」은 갤러리가 받게 합니다.'),
       ('next', '4', '넓은 말은 블로그 글로 받기', '첫 쪽이 블로그 · 카페뿐인 말은 사이트로 못 들어갑니다. 블로그 모음과 연결된 글로 받습니다.')])}
<p class="note">11월 말에 같은 검색어 {len(ALL)}개를 다시 재서 견줍니다. 이벤트 · 각인 · 블로그 검색어는 이번에 처음 쟀습니다.</p>''')

open(sys.argv[1], 'w').write(d.html('베이프렌드 10월 웹사이트 검색', cov, p1, p2, p3, p4))
