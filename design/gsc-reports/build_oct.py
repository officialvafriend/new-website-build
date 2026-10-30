#!/usr/bin/env python3
"""10월 검색 마감 보고 (구글 · 네이버) — 표지 + 6장, 처음 읽는 사람 기준, 연한 파랑. 네이버 보고서(design/naver-reports/build.py)와 같은 틀.
쓰는 법: python3 -I build_oct.py <10월 CSV 폴더> <9월 CSV 폴더> <out.html> [네이버 날짜별 CSV] [순위 폴더]"""
import csv, re, sys, datetime

OCT, SEP, OUT = sys.argv[1], sys.argv[2], sys.argv[3]
NAV = sys.argv[4] if len(sys.argv) > 4 else ''
RANKS = sys.argv[5] if len(sys.argv) > 5 else '../seo-ranks'
BLUE, SKY, PALE, LINE = '#2F6FE0', '#9CC3FF', '#EEF4FF', '#C7DCFF'

def rd(d, f): return list(csv.reader(open(f'{d}/{f}', encoding='utf-8-sig')))[1:]
def f(x):
    x = x.replace('%', '').replace(',', '')
    return float(x) if x else 0.0
def w(n): return f'{n:,.0f}'
def pct(c, i): return c / i * 100 if i else 0

# ── 날짜별 합계 ──
oc, sc = rd(OCT, '차트.csv'), rd(SEP, '차트.csv')
sc = [x for x in sc if int(x[2]) > 0]        # 9/30 은 0 으로 와서 뺀다 (구글이 늦게 채운다)
def tot(r):
    c = sum(int(x[1]) for x in r); i = sum(int(x[2]) for x in r)
    return c, i, pct(c, i), sum(f(x[4]) * int(x[2]) for x in r) / i
O, S = tot(oc), tot(sc)
ND, SD = len(oc), len(sc)

# ── 검색어: 이름 검색 / 그 밖 ──
BRAND = re.compile(r'액상덕후|덕후|duckhoo|duck-hoo|액상 덕후')
COMP = re.compile(r'액상82|액상 82|노보마트|딜리퀴드')
def qs(d):
    q = rd(d, '검색어 수.csv')
    b = [x for x in q if BRAND.search(x[0])]; n = [x for x in q if not BRAND.search(x[0])]
    s = lambda L: (sum(int(x[1]) for x in L), sum(int(x[2]) for x in L))
    return q, s(b), s(n), len(q)
oq, OB, ON, OQN = qs(OCT); sq, SB, SN, SQN = qs(SEP)
OH = (O[0] - OB[0] - ON[0], O[1] - OB[1] - ON[1]); SH = (S[0] - SB[0] - SN[0], S[1] - SB[1] - SN[1])
COMPI = sum(int(x[2]) for x in oq if COMP.search(x[0]))

def bucket(q):
    B = {k: [0, 0] for k in ('1~3위', '3~5위', '5~8위', '8~10위', '10위 밖')}
    for x in q:
        if BRAND.search(x[0]) or COMP.search(x[0]): continue
        p = f(x[4]); k = '1~3위' if p <= 3 else '3~5위' if p <= 5 else '5~8위' if p <= 8 else '8~10위' if p <= 10 else '10위 밖'
        B[k][0] += int(x[1]); B[k][1] += int(x[2])
    return B
OBK = bucket(oq)
MID = (OBK['5~8위'][0] + OBK['8~10위'][0], OBK['5~8위'][1] + OBK['8~10위'][1])
PRICEQ = [x for x in oq if '가격' in x[0] and not BRAND.search(x[0]) and '크래프트' not in x[0]]
PRICE = (sum(int(x[1]) for x in PRICEQ), sum(int(x[2]) for x in PRICEQ))

# ── 페이지 ──
SITELINK = {'/brand/duckhoo/', '/login/', '/product-category/7월-특가-할인/', '/product-category/적립금-사용-가능-상품/', '/about/',
            '/product-category/기기-팟-코일/', '/faq/', '/news/', '/product-category/액상-랭킹/'}
def pages(d):
    out = {}
    for u, c, i, _, p in rd(d, '페이지.csv'):
        out[u.replace('https://duck-hoo.com', '')] = (int(c), int(i), f(p))
    return out
OP, SP = pages(OCT), pages(SEP)
def group(P, pred):
    L = [v for u, v in P.items() if pred(u)]
    c = sum(v[0] for v in L); i = sum(v[1] for v in L)
    return c, i, pct(c, i), sum(v[2] * v[1] for v in L) / i if i else 0, len(L)
NOVO = group(OP, lambda u: u.startswith('/product/') and '노보' in u)
PROD = group(OP, lambda u: u.startswith('/product/') and '노보' not in u)
SL = group(OP, lambda u: u in SITELINK)
P_PRICE = OP.get('/price/', (0, 0, 0))
NOTSL = (O[0] - SL[0], O[1] - SL[1])

def pg(u): return OP.get(u, (0, 0, 0))
FIX = [  # 주소, 보이는 이름, 지금 검색 결과 제목, 왜 덜 눌리나
    ('/product/펠릭스-더블라임-9-8mg-30ml/', '펠릭스 더블라임 주소', '펠릭스 라임 알로에 … 20,000원', '「더블라임」 검색에 뜨던 이 주소가 10월에는 라임 알로에 화면이었습니다 (10월 30일에 바로잡음)'),
    ('/product/디톡스-톡스-알로에베라-모드-액상-3mg-60ml/', '디톡스 알로에베라', '[디톡스] 톡스 알로에베라 모드 액상 (3MG/60ml) - 액상덕후', '제목에 값이 없습니다 (21,000원)'),
    ('/product/젤로-크리스탈-젤로-크리스탈-전자담배-액상-5병-증/', '젤로 크리스탈 5병', '[젤로 크리스탈] 젤로 크리스탈 기기 + 액상 5병 증정 이벤트 ! - 액상덕후', '「젤로 크리스탈 가격」으로 71번 보였는데 제목에 값(69,000원)이 없습니다'),
    ('/product/마르키사-오리지날-9-8mg-30ml/', '마르키사 라즈베리', '기본 제목 (값 없음)', '주소는 「오리지날」인데 상품은 라즈베리입니다'),
    ('/liquid-guide/', '액상 고르는 법 (안내 글)', '전자담배 액상 고르는 법 — 입호흡 · 폐호흡 …', '정보를 찾는 검색이라 원래 덜 누릅니다. 제목에 날짜를 붙여 봅니다'),
]

# ── 그림 ──
def chart():
    W, H, L, R, TOP, B = 640, 230, 34, 40, 16, 30
    cw = (W - L - R) / ND
    cmax, imax = 50, 400
    y = lambda v: TOP + (H - TOP - B) * (1 - v / cmax)
    yi = lambda v: TOP + (H - TOP - B) * (1 - v / imax)
    s = [f'<svg viewBox="0 0 {W} {H}" width="100%">']
    days = [x[0] for x in oc]
    for d0, d1, lab in (('2026-10-03', '2026-10-05', '개천절 연휴'), ('2026-10-09', '2026-10-11', '한글날 연휴')):
        i0, i1 = days.index(d0), days.index(d1)
        s.append(f'<rect x="{L + i0 * cw:.1f}" y="{TOP}" width="{(i1 - i0 + 1) * cw:.1f}" height="{H - TOP - B}" fill="#F1F3F5"/>')
        s.append(f'<text x="{L + (i0 + (i1 - i0 + 1) / 2) * cw:.1f}" y="{TOP + 11}" font-size="9.5" fill="#6B7280" text-anchor="middle">{lab}</text>')
    for g in (0, 20, 40):
        s.append(f'<line x1="{L}" x2="{W - R}" y1="{y(g):.1f}" y2="{y(g):.1f}" stroke="#E5E9EE"/><text x="{L - 5}" y="{y(g) + 3:.1f}" font-size="9" fill="#6B7280" text-anchor="end">{g}번</text>')
    for g in (100, 200, 300):
        s.append(f'<text x="{W - R + 5}" y="{yi(g) + 3:.1f}" font-size="9" fill="#6B7280">{g}</text>')
    for i, x in enumerate(oc):
        c = int(x[1]); last = i == ND - 1
        s.append(f'<rect x="{L + i * cw + cw * .18:.1f}" y="{y(c):.1f}" width="{cw * .64:.1f}" height="{y(0) - y(c):.1f}" rx="1.5" fill="{"#E2E8F0" if last else SKY}"/>')
    pts = ' L'.join(f'{L + (i + .5) * cw:.1f} {yi(int(x[2])):.1f}' for i, x in enumerate(oc))
    s.append(f'<path d="M{pts}" fill="none" stroke="#374151" stroke-width="1.6" stroke-dasharray="4 3"/>')
    for i, x in enumerate(oc):
        if i % 4 == 0:
            d = datetime.date.fromisoformat(x[0])
            s.append(f'<text x="{L + (i + .5) * cw:.1f}" y="{H - B + 14}" font-size="9" fill="#6B7280" text-anchor="middle">{d.month}/{d.day}</text>')
    pk = days.index('2026-10-20'); c = int(oc[pk][1])
    s.append(f'<rect x="{L + pk * cw + cw * .18:.1f}" y="{y(c):.1f}" width="{cw * .64:.1f}" height="{y(0) - y(c):.1f}" rx="1.5" fill="{BLUE}"/>')
    s.append(f'<text x="{L + (pk + .5) * cw:.1f}" y="{y(c) - 6:.1f}" font-size="10" font-weight="800" fill="#111" text-anchor="middle">10/20 · {c}번</text>')
    s.append('</svg>')
    return ''.join(s)

def mix_table():
    rows = [('가게 이름으로 검색', SB, OB), ('이름을 모르고 온 검색', SN, ON), ('구글이 감춘 검색어', SH, OH)]
    h = '<table><thead><tr><th>어떤 검색인가</th><th class="n">9월 보인</th><th class="n">9월 누른 비율</th><th class="n">10월 보인</th><th class="n">10월 들어온</th><th class="n">10월 누른 비율</th></tr></thead><tbody>'
    for lab, s, o in rows:
        h += f'<tr><td>{lab}</td><td class="n g">{w(s[1])}번</td><td class="n g">{pct(*s):.1f}%</td><td class="n">{w(o[1])}번</td><td class="n"><b>{w(o[0])}번</b></td><td class="n"><b>{pct(*o):.1f}%</b></td></tr>'
    h += f'<tr><td><b>전체</b></td><td class="n g">{w(S[1])}번</td><td class="n g">{S[2]:.1f}%</td><td class="n"><b>{w(O[1])}번</b></td><td class="n"><b>{w(O[0])}번</b></td><td class="n"><b>{O[2]:.1f}%</b></td></tr>'
    return h + '</tbody></table>'

def pos_bars():
    mx = max(v[1] for v in OBK.values())
    h = '<div class="pb">'
    for k, (c, i) in OBK.items():
        on = k in ('5~8위', '8~10위')
        h += f'<div class="pbr{" on" if on else ""}"><span>{k}</span><span class="f"><i style="width:{i / mx * 100:.0f}%"></i></span><span class="n">{w(i)}번 보임</span><span class="n"><b>{pct(c, i):.1f}%</b></span></div>'
    return h + '</div>'

def fix_table():
    h = '<table class="fx"><thead><tr><th>화면</th><th class="n">보인</th><th class="n">누른 비율</th><th>왜 덜 눌리나</th></tr></thead><tbody>'
    for u, name, title, why in FIX:
        c, i, p = pg(u)
        h += f'<tr><td><b>{name}</b><small>{title}</small></td><td class="n">{w(i)}번</td><td class="n"><b>{pct(c, i):.1f}%</b></td><td class="why">{why}</td></tr>'
    return h + '</tbody></table>'


STYLE = f"""<style>
@page {{ size: A4; margin: 0; }}
* {{ box-sizing: border-box; }} html, body {{ margin: 0; }}
body {{ font-family: "Pretendard Variable", Pretendard, sans-serif; color: #111; font-size: 12.5pt; line-height: 1.6; word-break: keep-all; }}
.page {{ width: 210mm; height: 297mm; padding: 17mm 18mm 16mm; position: relative; break-after: page; overflow: hidden; }}
.page:last-child {{ break-after: auto; }}
.run, .foot {{ position: absolute; left: 18mm; right: 18mm; display: flex; justify-content: space-between; font-size: 9pt; color: #6B7280; }}
.run {{ top: 8mm; }} .foot {{ bottom: 8mm; border-top: 1px solid #E5E9EE; padding-top: 4pt; }} .foot b {{ color: #111; }}
.eb {{ font-size: 10.5pt; color: {BLUE}; font-weight: 800; letter-spacing: .04em; }}
h1 {{ font-size: 29pt; line-height: 1.25; margin: 0 0 10pt; font-weight: 900; }}
h2 {{ font-size: 22pt; line-height: 1.3; margin: 4pt 0 8pt; font-weight: 900; }}
h3 {{ font-size: 14pt; margin: 0 0 6pt; font-weight: 900; }}
.lead {{ font-size: 12.5pt; color: #374151; margin: 0 0 12pt; }}
.g {{ color: #6B7280; }} .acc {{ color: {BLUE}; }}
.cover {{ background: #0F172A; color: #fff; }}
.cover .eb {{ color: {SKY}; }}
.cover .big {{ font-size: 104pt; font-weight: 900; line-height: 1; margin: 24mm 0 10mm; }}
.cover .big small {{ font-size: 30pt; color: {SKY}; margin-left: 6pt; }}
.cover h1 {{ color: #fff; }} .cover .lead {{ color: #CBD5E1; }}
.facts {{ display: grid; grid-template-columns: repeat(3, 1fr); gap: 12pt; margin-top: 16mm; }}
.fact {{ border-top: 2px solid {SKY}; padding-top: 8pt; }}
.fact .v {{ font-size: 25pt; font-weight: 900; line-height: 1.15; }} .fact .l {{ font-size: 11pt; color: #CBD5E1; }}
.words {{ margin-top: 13mm; background: #1E293B; border-radius: 8pt; padding: 12pt 14pt; font-size: 11.5pt; color: #E2E8F0; }}
.words .t {{ font-weight: 900; color: {SKY}; margin-bottom: 4pt; }} .words p {{ margin: 2pt 0; }} .words b {{ color: #fff; }}
.meta {{ position: absolute; left: 18mm; right: 18mm; bottom: 15mm; font-size: 10pt; color: #94A3B8; display: flex; justify-content: space-between; border-top: 1px solid #334155; padding-top: 8pt; }}
.meta b {{ color: #fff; }}
.rows {{ margin: 6pt 0 14pt; }}
.row {{ display: grid; grid-template-columns: 74pt 1fr; gap: 12pt; padding: 10pt 0; border-bottom: 1px solid #E5E9EE; align-items: baseline; }}
.row .k {{ font-size: 11pt; font-weight: 900; border-radius: 99pt; padding: 2pt 0; text-align: center; }}
.k.ok {{ background: {PALE}; color: {BLUE}; }} .k.mid {{ background: #F1F5F9; color: #334155; }} .k.bad {{ background: #FEF3F2; color: #B42318; }} .k.next {{ background: #111; color: #fff; }}
.row p {{ margin: 0; font-size: 13pt; }} .row p small {{ display: block; font-size: 11pt; color: #6B7280; }}
.bars {{ margin: 4pt 0 6pt; }}
.bar {{ display: grid; grid-template-columns: 120pt 1fr 60pt; align-items: center; gap: 8pt; margin: 5pt 0; font-size: 11.5pt; }}
.bar .f {{ height: 15pt; border-radius: 3pt; background: #CBD5E1; }} .bar.on .f {{ background: {BLUE}; }}
.bar .n {{ text-align: right; font-weight: 900; }}
table {{ width: 100%; border-collapse: collapse; font-size: 11pt; margin: 2pt 0 12pt; }}
th {{ text-align: left; font-size: 9.5pt; color: #6B7280; font-weight: 700; border-bottom: 2px solid #111; padding: 4pt 5pt; }}
td {{ border-bottom: 1px solid #E5E9EE; padding: 6pt 5pt; vertical-align: top; }}
.n {{ text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }}
table.fx td small {{ display: block; font-size: 9pt; color: #6B7280; font-weight: 400; margin-top: 1pt; }}
table.fx td.why {{ font-size: 10.5pt; color: #374151; width: 42%; }}
.chart {{ border: 1px solid #E5E9EE; border-radius: 8pt; padding: 10pt 12pt 6pt; margin: 4pt 0 10pt; }}
.legend {{ display: flex; gap: 16pt; font-size: 10pt; color: #374151; margin-top: 2pt; }}
.legend i {{ display: inline-block; width: 12pt; height: 9pt; border-radius: 2pt; background: {SKY}; margin-right: 4pt; vertical-align: -1pt; }}
.legend i.l {{ height: 0; border-top: 2px dashed #374151; border-radius: 0; background: none; vertical-align: 3pt; }}
.box {{ background: {PALE}; border: 1px solid {LINE}; border-radius: 8pt; padding: 11pt 14pt; font-size: 11.5pt; }}
.box .t {{ font-weight: 900; margin-bottom: 4pt; }} .box p {{ margin: 0 0 4pt; }}
.pb {{ margin: 4pt 0 12pt; }}
.pbr {{ display: grid; grid-template-columns: 52pt 1fr 70pt 46pt; gap: 8pt; align-items: center; margin: 4pt 0; font-size: 11pt; }}
.pbr .f {{ height: 13pt; background: #F1F5F9; border-radius: 3pt; overflow: hidden; }} .pbr .f i {{ display: block; height: 100%; background: #CBD5E1; }}
.pbr.on .f i {{ background: {BLUE}; }} .pbr.on span:first-child {{ font-weight: 900; }}
.vs {{ display: grid; grid-template-columns: 1fr 1fr; gap: 12pt; margin: 4pt 0 14pt; }}
.vsb {{ border-radius: 8pt; padding: 12pt 14pt; border: 1.5px solid #E5E9EE; }}
.vsb.on {{ background: {PALE}; border-color: {LINE}; }}
.vsb .l {{ font-size: 10.5pt; font-weight: 700; color: #374151; }}
.vsb .v {{ font-size: 30pt; font-weight: 900; line-height: 1.15; }} .vsb.on .v {{ color: {BLUE}; }}
.vsb .d {{ font-size: 10.5pt; color: #6B7280; }}
.vsb code {{ font-family: inherit; font-size: 10pt; background: #fff; border: 1px solid #E5E9EE; border-radius: 4pt; padding: 1pt 5pt; display: inline-block; margin-top: 4pt; }}
.todo {{ display: grid; grid-template-columns: 34pt 1fr; gap: 10pt; margin: 0 0 11pt; }}
.todo .no {{ width: 30pt; height: 30pt; border-radius: 50%; background: {BLUE}; color: #fff; font-weight: 900; display: flex; align-items: center; justify-content: center; font-size: 14pt; }}
.tight .row {{ padding: 6pt 0; }} .tight .row p {{ font-size: 12pt; }} table.tight td {{ padding: 4pt 5pt; }}
.todo h3 {{ margin: 2pt 0 1pt; }} .done {{ font-size: 10pt; font-weight: 800; color: {BLUE}; background: {PALE}; border-radius: 99pt; padding: 1pt 8pt; margin-left: 4pt; vertical-align: 2pt; }} .todo p {{ margin: 0; font-size: 11.5pt; color: #374151; }} .todo p b {{ color: #111; }}
.not {{ margin: 2pt 0 12pt; padding: 0; list-style: none; font-size: 11pt; color: #374151; }}
.not li {{ padding-left: 14pt; position: relative; margin: 0 0 3pt; }} .not li::before {{ content: "×"; position: absolute; left: 0; color: #94A3B8; }}
.goals {{ display: grid; grid-template-columns: repeat(3, 1fr); gap: 10pt; margin: 6pt 0 12pt; }}
.goal {{ border: 1.5px solid {LINE}; background: {PALE}; border-radius: 8pt; padding: 9pt 12pt; }}
.goal .l {{ font-size: 10.5pt; font-weight: 700; color: #374151; }}
.goal .v {{ font-size: 21pt; font-weight: 900; line-height: 1.25; }} .goal .v small {{ font-size: 11pt; color: #374151; }}
.goal .d {{ font-size: 10.5pt; color: {BLUE}; font-weight: 800; }}
.src {{ font-size: 9.5pt; color: #6B7280; border-top: 1px solid #E5E9EE; padding-top: 7pt; }} .src p {{ margin: 0 0 2pt; }}
</style>"""

TOTAL = 7 if NAV else 6
def frame(no, title, body):
    return f'''<section class="page">
  <div class="run"><span>10월 검색 마감 보고</span><span>{no} · {title}</span></div>
{body}
  <div class="foot"><span>10월 검색 마감 보고</span><span><b>{no}</b> / {TOTAL - 1}</span></div>
</section>'''

NEWX = ON[0] / SN[0]
cover = f'''<section class="page cover">
  <div class="eb">액상덕후 · 10월 검색 마감 보고 (구글 · 네이버)</div>
  <div class="big">{NEWX:.1f}배</div>
  <h1>이름을 모르고 검색해 들어온 횟수가<br>9월의 {NEWX:.1f}배입니다</h1>
  <p class="lead">대신 누른 비율은 {S[2]:.1f}%에서 {O[2]:.1f}%로 반이 됐습니다.<br>왜 그런지와 11월에 고칠 것을 함께 적었습니다.</p>
  <div class="facts">
    <div class="fact"><div class="v">{w(SN[0])} → {w(ON[0])}번</div><div class="l">이름을 모르고 온 검색에서<br>들어온 횟수 (9월 → 10월)</div></div>
    <div class="fact"><div class="v">{w(S[1])} → {w(O[1])}번</div><div class="l">구글 검색 결과에<br>보인 횟수 ({O[1] / S[1]:.1f}배)</div></div>
    <div class="fact"><div class="v">{S[2]:.1f} → {O[2]:.1f}%</div><div class="l">누른 비율<br>(들어온 횟수 ÷ 보인 횟수)</div></div>
  </div>
  <div class="words"><div class="t">이 보고서에서 쓰는 말은 세 개뿐입니다</div>
    <p><b>보인 횟수</b>는 구글 검색 결과에 우리 사이트가 나온 횟수입니다.</p>
    <p><b>들어온 횟수</b>는 그 결과를 눌러 우리 사이트에 들어온 횟수입니다.</p>
    <p><b>누른 비율</b>은 들어온 횟수를 보인 횟수로 나눈 값입니다. 100번 보여 5번 들어오면 5%입니다.</p></div>
  <div class="meta"><span><b>보고 대상</b> 대표님 · 이사님</span><span><b>작성</b> 온라인몰 운영</span><span><b>2026년 10월 30일</b> · 10월 1일 ~ 29일</span></div>
</section>'''

p1 = frame(1, '한눈에', f'''  <div class="eb">1 · 한눈에</div>
  <h2>한 장으로 보면</h2>
  <p class="lead">10월 한 달 구글 · 네이버 검색 결과를 9월과 견줬습니다.</p>
  <div class="rows">
    <div class="row"><span class="k ok">잘된 것</span><p>이름을 모르고 검색해 들어온 횟수 <b>{w(SN[0])}번 → {w(ON[0])}번</b><small>노보 브랜드 페이지 {w(SP.get('/brand/novo/', (0,))[0])} → {w(pg('/brand/novo/')[0])}번 · 타격감 분류 {w(SP.get('/product-category/타격감/', (0,))[0])} → {w(pg('/product-category/타격감/')[0])}번 · 노보 블랙 엠에스블랜드 {w(SP.get('/product/노보-블랙-엠에스블랜드-9-8mg-30ml/', (0,))[0])} → {w(pg('/product/노보-블랙-엠에스블랜드-9-8mg-30ml/')[0])}번</small></p></div>
    <div class="row"><span class="k mid">그대로</span><p>구글로 들어온 전체 횟수 <b>{w(S[0])}번 → {w(O[0])}번</b> ({(O[0] / S[0] - 1) * 100:+.0f}%)<small>가게 이름(「액상덕후」)으로 검색해 들어온 횟수가 {w(SB[0])} → {w(OB[0])}번으로 줄어 새로 늘어난 몫이 그만큼 가려졌습니다</small></p></div>
    <div class="row"><span class="k bad">아쉬운 것</span><p>누른 비율 <b>{S[2]:.1f}% → {O[2]:.1f}%</b><small>새로 보이기 시작한 검색은 절반 넘게 5~10위 자리라 잘 안 눌립니다 (3쪽)</small></p></div>
    <div class="row"><span class="k next">11월</span><p><b>상품 화면 제목에 값과 「액상」을 넣기</b><small>그렇게 바꾼 노보 상품 화면은 다른 상품보다 {NOVO[2] / PROD[2]:.1f}배 잘 눌립니다 (4쪽)</small></p></div>
  </div>
  <h3>구글로 들어온 횟수, 어떤 검색에서 왔나</h3>
  <div class="bars">
    <div class="bar"><span>가게 이름 · 9월</span><span class="f" style="width:{SB[0] / SB[0] * 100:.0f}%"></span><span class="n">{SB[0]}번</span></div>
    <div class="bar"><span>가게 이름 · 10월</span><span class="f" style="width:{OB[0] / SB[0] * 100:.0f}%"></span><span class="n">{OB[0]}번</span></div>
    <div class="bar on"><span>이름 모르고 · 9월</span><span class="f" style="width:{SN[0] / SB[0] * 100:.0f}%"></span><span class="n">{SN[0]}번</span></div>
    <div class="bar on"><span>이름 모르고 · 10월</span><span class="f" style="width:{ON[0] / SB[0] * 100:.0f}%"></span><span class="n">{ON[0]}번</span></div>
  </div>
  <p class="g" style="font-size:10.5pt;margin:0">구글이 어떤 말로 검색했는지 알려 주는 몫만 셌습니다 (9월 {w(SB[0] + SN[0])}번 · 10월 {w(OB[0] + ON[0])}번). 나머지는 구글이 검색어를 감춥니다.</p>''')

pw = frame(2, '10월에 한 일', f'''  <div class="eb">2 · 10월에 한 일</div>
  <h2>검색 결과에 보이는 제목과 글을<br>손님이 치는 말에 맞췄습니다</h2>
  <p class="lead">상품 · 값 · 주문 과정은 그대로 두고 검색엔진이 읽는 제목 · 설명 · 안내 글만 고쳤습니다. 검색엔진이 고친 페이지를 다시 읽기까지 며칠에서 몇 주가 걸려, 10월 30일 작업은 이 보고서 숫자에 아직 없습니다.</p>
  <div class="box" style="margin:0 0 6pt"><div class="t">먼저 알아 둘 것</div>
    <p>· 10월 노보 단종 소식으로 다른 가게는 품절이 이어졌고 우리는 재고가 있어 10월 작업의 상당 부분이 노보입니다.</p>
    <p>· 9월에 해 둔 두 가지가 10월 숫자에 이어집니다. 9월 21일 노보 상품 5개의 검색 결과 제목을 「맛 + 액상 + 값 + 재고」로 바꿨고, 9월 28일 전 상품 가격표 페이지(/price/)를 만들었습니다.</p></div>
  <div class="rows tight">
    <div class="row"><span class="k mid">10월 1일</span><p>노보 재고가 있다는 것을 검색에 보이게 했습니다<small>노보 상품 제목 끝에 「재고 있음」, 첫 화면 맨 위에 노보 상품 줄, 노보 목록에 자주 묻는 질문</small></p></div>
    <div class="row"><span class="k mid">10월 1일</span><p>「전담 액상」 「전자담배 액상」 같은 넓은 검색어를 노렸습니다<small>첫 화면과 입호흡 · 폐호흡 목록의 제목 · 설명을 손님이 치는 말로 고치고, 안내 글 두 장(액상 고르는 법, 입호흡과 폐호흡의 차이)을 새로 냈습니다</small></p></div>
    <div class="row"><span class="k mid">10월 2일</span><p>브랜드 페이지 30장의 설명을 다시 썼습니다<small>같은 문장에 이름만 바꿔 넣던 것을 그 브랜드의 종류 수 · 규격 · 값으로 바꿨습니다. 상품이 하나뿐인 9장은 검색에서 뺐습니다</small></p></div>
    <div class="row"><span class="k mid">10월 6일</span><p>노보 상품끼리 맛 이름을 늘어놓던 링크를 걷었습니다<small>같은 맛 이름이 여러 페이지에 깔리자 네이버에서 「노보 타박멘솔」이 15위 밖으로 밀렸습니다. 걷고 나서 10월 9일에 2위로 돌아왔습니다</small></p></div>
    <div class="row"><span class="k mid">10월 19일</span><p>제목이 비어 있던 곳을 채웠습니다<small>기본 제목뿐이던 분류 6개에 제목 · 설명을 쓰고, 사진 설명 11장의 니코틴 표기(0.98mg → 9.8mg)를 바로잡았습니다</small></p></div>
    <div class="row"><span class="k mid">10월 20일</span><p>「종류 · 가격」을 묻는 검색에 답하는 표를 넣었습니다<small>노보 목록과 브랜드 페이지마다 종류 · 가격 표와 자주 묻는 질문, 제목에는 기준 날짜를 붙였습니다</small></p></div>
    <div class="row"><span class="k mid">10월 30일</span><p>펠릭스 검색을 바로잡았습니다<small>「펠릭스 더블라임」 검색에 뜨던 주소가 다른 맛(라임 알로에) 화면이어서 두 상품의 주소를 맞바꿨습니다. 펠릭스 검색 제목에는 5병 묶음 값을 넣었습니다</small></p></div>
  </div>''')

p2 = frame(3, '누른 비율', f'''  <div class="eb">3 · 누른 비율이 왜 반이 됐나</div>
  <h2>잘 안 눌리는 자리에<br>많이 보이기 시작했습니다</h2>
  <p class="lead">가게 이름으로 검색한 사람은 절반 가까이 누르지만 처음 보는 가게가 5~10위에 있으면 거의 누르지 않습니다. 10월에는 이름 모르고 온 검색에서 보인 횟수가 {ON[1] / SN[1]:.1f}배로 늘었습니다.</p>
  {mix_table()}
  <div class="cols2" style="display:grid;grid-template-columns:1.1fr 1fr;gap:16pt">
    <div><h3>이름 모르고 온 검색, 우리 자리별 누른 비율</h3>{pos_bars()}
      <p class="g" style="font-size:10.5pt;margin:-6pt 0 0">5~10위에서 <b style="color:#111">{w(MID[1])}번 보였고 {MID[0]}번 들어왔습니다 ({pct(*MID):.1f}%)</b>. 11월에 손볼 곳이 여기입니다.</p></div>
    <div class="box"><div class="t">비율 계산에서 따로 봐야 할 것</div>
      <p>· 가게 이름 검색 밑에 딸려 나오는 링크(로그인 · 적립금 · 특가 · 회사 소개 …)는 {w(SL[1])}번 보여 {SL[0]}번 들어왔습니다. 이름을 친 사람은 맨 위 링크를 누르므로 원래 거의 안 눌립니다.</p>
      <p>· 남의 가게 이름(액상82 · 노보마트 · 딜리퀴드)은 {w(COMPI)}번 보여 한 번도 안 들어왔습니다. 노리지 않습니다.</p>
      <p>· 둘을 빼면 10월 누른 비율은 <b>{pct(NOTSL[0], NOTSL[1] - COMPI):.1f}%</b>입니다.</p></div>
  </div>
  <div class="chart">{chart()}
    <div class="legend"><span><i></i>하루 들어온 횟수 (왼쪽)</span><span><i class="l"></i>하루 보인 횟수 (오른쪽)</span><span class="g">10/29 는 구글이 아직 덜 채움</span></div></div>''')

p3 = frame(4, '덜 눌리는 화면', f'''  <div class="eb">4 · 어느 화면이 덜 눌리나</div>
  <h2>제목에 값이 보이는 상품 화면이<br>{NOVO[2] / PROD[2]:.1f}배 잘 눌립니다</h2>
  <p class="lead">노보 상품 5개는 9월 21일에 검색 결과 제목을 「맛 + 액상 + 값 + 재고」로 바꿨습니다(2쪽). 다른 상품은 기본 제목 그대로입니다. 평균 순위는 둘이 비슷합니다.</p>
  <div class="vs">
    <div class="vsb on"><div class="l">노보 상품 화면 {NOVO[4]}개 · 바꾼 제목</div><div class="v">{NOVO[2]:.1f}%</div><div class="d">{w(NOVO[1])}번 보여 {NOVO[0]}번 들어옴 · 평균 {NOVO[3]:.1f}위</div>
      <code>노보 블랙 타박멘솔 액상 입호흡 9.8mg 30ml 13,500원 재고 있음</code></div>
    <div class="vsb"><div class="l">나머지 상품 화면 {PROD[4]}개 · 기본 제목</div><div class="v">{PROD[2]:.1f}%</div><div class="d">{w(PROD[1])}번 보여 {PROD[0]}번 들어옴 · 평균 {PROD[3]:.1f}위</div>
      <code>[디톡스] 톡스 알로에베라 모드 액상 (3MG/60ml) - 액상덕후</code></div>
  </div>
  <h3>많이 보이는데 거의 안 눌리는 화면</h3>
  {fix_table()}
  <div class="box"><div class="t">값을 묻는 검색도 그냥 지나갑니다</div>
    <p>「액상 가격」 · 「입호흡 액상 가격」 · 「젤로 크리스탈 가격」처럼 <b>값을 묻는 검색이 {w(PRICE[1])}번 보였고 {PRICE[0]}번 들어왔습니다.</b> 「입호흡 액상 가격」은 평균 2.7위인데도 한 번도 안 들어왔습니다.</p>
    <p>9월 28일에 만든 전 상품 가격표 페이지(/price/)는 10월에 {P_PRICE[1]}번만 보였습니다. 구글이 아직 그 화면을 값 검색의 답으로 보지 않습니다.</p></div>''')

# ── 네이버 (서치어드바이저 날짜별 합계 + 순위 측정) ──
pn = ''
if NAV:
    import json, os
    nr = list(csv.reader(open(NAV, encoding='utf-8-sig')))
    nd = nr[0][1:]; ni = list(map(int, nr[1][1:])); nc = list(map(int, nr[2][1:]))
    keep = [k for k in range(len(nd)) if ni[k] > 0]
    gap = [nd[k] for k in range(len(nd)) if ni[k] == 0]
    def nwk(a, b):
        ks = [k for k in range(a, b) if ni[k] > 0]
        return ks, sum(ni[k] for k in ks) / len(ks), sum(nc[k] for k in ks) / len(ks), pct(sum(nc[k] for k in ks), sum(ni[k] for k in ks))
    W = []
    for a in range(0, len(nd), 7):
        b = min(a + 7, len(nd)); ks, i_, c_, r_ = nwk(a, b)
        lab = f"{int(nd[a][:2])}/{int(nd[a][3:])} ~ {int(nd[b-1][:2])}/{int(nd[b-1][3:])}"
        W.append((lab, len(ks), i_, c_, r_))
    NCLK = sum(nc[k] for k in keep); NDAY = NCLK / len(keep)
    GDAY = O[0] / ND
    # 순위
    cats = {'same': [], 'blog': [], 'store': [], 'gone': []}
    seen = set()
    END = {}
    for b in ('2026-10-30-buy.json', '2026-10-30-novo.json'):
        for q, v in json.load(open(os.path.join(RANKS, b)))['ranks'].items():
            if v['n'] and q not in END: END[q] = v
    ENDP = [v['hits'][0][0] if v['hits'] else 99 for v in END.values()]
    NE, N1, N3, NF = len(END), sum(x == 1 for x in ENDP), sum(x <= 3 for x in ENDP), sum(x < 99 for x in ENDP)
    A19 = {q: v for q, v in json.load(open(os.path.join(RANKS, '2026-10-19-all.json')))['ranks'].items() if v['n']}
    COM = [q for q in END if q in A19]
    F19, F30 = sum(bool(A19[q]['hits']) for q in COM), sum(bool(END[q]['hits']) for q in COM)
    for a, b in (('2026-10-19-all.json', '2026-10-30-buy.json'), ('2026-10-19-all.json', '2026-10-30-novo.json')):
        A = json.load(open(os.path.join(RANKS, a)))['ranks']; B = json.load(open(os.path.join(RANKS, b)))['ranks']
        for q, v in B.items():
            if q in seen or not v['n'] or not A.get(q, {}).get('n'):
                continue
            if not A[q]['hits']:
                seen.add(q)
                if v['hits']: cats['same'].append(q)
                continue
            seen.add(q)
            xa = A[q]['hits'][0][0]; y = v['hits']; ya = y[0][0] if y else 99
            xw = (A[q]['web_hits'] or [99])[0]; yw = (v['web_hits'] or [99])[0]
            if ya <= xa: cats['same'].append(q)
            elif not y: cats['gone'].append(q)
            elif yw <= xw: cats['blog'].append(q)
            else: cats['store'].append(q)
    NQ = sum(len(x) for x in cats.values())
    GONE_NOVO = [q for q in cats['gone'] if '노보' in q]
    def wtab():
        h = '<table class="tight"><thead><tr><th>기간</th><th class="n">하루 보인</th><th class="n">하루 들어온</th><th class="n">누른 비율</th></tr></thead><tbody>'
        for lab, n_, i_, c_, r_ in W:
            h += f'<tr><td>{lab}' + (f' <span class="g">({n_}일)</span>' if n_ < 7 else '') + f'</td><td class="n">{w(i_)}번</td><td class="n"><b>{c_:.0f}번</b></td><td class="n">{r_:.1f}%</td></tr>'
        return h + '</tbody></table>'
    def chipsq(qs, cls=''):
        return f'<div class="chips {cls}">' + ''.join(f'<span>{q}</span>' for q in qs) + '</div>'
    pn = frame(5, '네이버', f"""  <div class="eb">5 · 네이버</div>
  <h2>네이버는 순위가 내려갔는데도<br>들어온 횟수는 매주 늘었습니다</h2>
  <p class="lead">네이버 서치어드바이저 날짜별 합계(9월 30일 ~ 10월 29일)로 봤습니다. 네이버로는 하루 {NDAY:.0f}번 들어와 구글(하루 {GDAY:.0f}번)의 {NDAY / GDAY:.1f}배입니다.</p>
  {wtab()}
  <h3>10월 말 네이버 검색 자리</h3>
  <p style="margin:0 0 6pt;font-size:11.5pt">손님이 칠 만한 검색어 {NE}개를 10월 30일 네이버 웹 검색에서 쟀습니다. <b>첫 쪽(20개)에 {NF}개, 3위 안에 {N3}개, 1위는 {N1}개</b>입니다. 10월 19일에도 잰 {len(COM)}개만 견주면 첫 쪽에 든 검색어가 {F19}개에서 {F30}개로 줄었습니다.</p>
  <div class="rows tight" style="margin:0 0 8pt">
    <div class="row"><span class="k mid">블로그 {len(cats['blog'])}</span><p>가게 사이트끼리는 그대로인데 <b>블로그 · 카페 글이 위로</b> 들어왔습니다<small>{' · '.join(cats['blog'][:4])}</small></p></div>
    <div class="row"><span class="k mid">밀림 {len(cats['store'])}</span><p>다른 가게 페이지에 한두 자리 밀렸습니다<small>{' · '.join(cats['store'][:5])} …</small></p></div>
    <div class="row"><span class="k bad">첫 쪽 밖 {len(cats['gone'])}</span><p>첫 쪽(20개)에서 빠졌습니다. <b>{len(GONE_NOVO)}개가 노보 말</b>입니다<small>10월 중순 노보 단종 소식이 퍼진 뒤 노보 블로그 · 카페 글이 몰리고 노보 공식 판매처가 새로 들어와 자리를 채웠습니다</small></p></div>
    <div class="row"><span class="k ok">지킨 것 {len(cats['same'])}</span><p>오르거나 같은 자리를 지켰습니다<small>{' · '.join(cats['same'][:7])}</small></p></div>
  </div>
  <div class="box"><div class="t">읽는 법</div>
    <p>우리 페이지는 네이버에 그대로 잡혀 있어(사이트 검색으로 확인) 벌점이나 수집 문제는 아닙니다. 빠진 것은 대부분 「노보 액상」처럼 노보 이름을 묻는 말입니다. 「노보 10+1 가격」 「액상 가격표」처럼 사려는 말은 1~3위에 있어 순위가 내려간 주에도 들어온 횟수는 늘었습니다.</p>
</div>
  <p class="g" style="font-size:10pt;margin:6pt 0 0">순위는 하루에 잰 값이라 한두 자리씩 흔들립니다. 11월에도 같은 검색어로 잽니다.{' 10월 ' + ', '.join(str(int(g[3:])) + '일' for g in gap) + '은 네이버 자료가 비어 빼고 셌습니다.' if gap else ''}</p>""")

p4 = frame(TOTAL - 1, '11월에 할 일', f'''  <div class="eb">{TOTAL - 1} · 11월에 할 일</div>
  <h2>누른 비율을 올리는 일 셋</h2>
  <div class="todo"><span class="no">1</span><div><h3>모든 상품 제목을 노보처럼 바꾸기</h3><p>「브랜드 맛 + 액상 + 입호흡/폐호흡 + 용량 + <b>값</b> + 재고」. 값은 상품에서 그때그때 읽으므로 값이 바뀌어도 틀리지 않습니다. 디톡스 · 젤로 크리스탈 · 마르키사 · 흑염룡부터.</p></div></div>
  <div class="todo"><span class="no">2</span><div><h3>값 검색은 가격표 한 장으로 받기</h3><p>가격표 제목과 설명 첫머리에 「액상 가격」을 그대로 넣고 기준 날짜를 붙입니다. 첫 화면 · 입호흡/폐호흡 목록에서 가격표로 가는 길을 넓힙니다.</p></div></div>
  <div class="todo"><span class="no">3</span><div><h3>검색 결과 밑 두 줄 설명에 「오늘 출고」</h3><p>상품 설명 첫 문장에 값 · 3만원 이상 무료배송 · 평일 오후 4시 전 입금 시 당일 출고를 넣습니다. 같은 자리의 다른 가게보다 누를 이유가 하나 더 생깁니다.</p></div></div>
  <h3 style="margin-top:2pt">하지 않을 것</h3>
  <ul class="not">
    <li>남의 가게 이름(액상82 · 노보마트 · 딜리퀴드)을 노리는 글</li>
    <li>가게 이름 검색 밑의 링크를 비율 때문에 지우는 일 (이름을 친 손님에게는 쓸모가 있습니다)</li>
    <li>노보 이름 검색에서 블로그를 이기려고 블로그를 따로 여는 일 (담배 광고 제한에 걸립니다)</li>
  </ul>
  <h3>12월 초에 볼 숫자 셋</h3>
  <div class="goals">
    <div class="goal"><div class="l">노보 밖 상품 화면 누른 비율</div><div class="v">{PROD[2]:.1f}<small>% →</small> 6<small>%</small></div><div class="d">노보 수준({NOVO[2]:.1f}%)의 3분의 2</div></div>
    <div class="goal"><div class="l">5~10위 자리 누른 비율</div><div class="v">{pct(*MID):.1f}<small>% →</small> 3<small>%</small></div><div class="d">이름 모르고 온 검색</div></div>
    <div class="goal"><div class="l">이름 모르고 와서 들어온 횟수</div><div class="v">{ON[0]}<small>번 →</small> 150<small>번</small></div><div class="d">11월 한 달</div></div>
  </div>
  <div class="src"><p><b>숫자 출처</b></p>
    <p>· 구글 서치콘솔 「실적」 내보내기. 10월은 1 ~ 29일({ND}일, 29일은 아직 덜 채워짐), 9월은 1 ~ 29일({SD}일, 30일은 0으로 와서 뺌)</p>
    <p>· 가게 이름 검색 = 「액상덕후 · 덕후 · duckhoo」가 든 검색어. 구글이 검색어를 감춘 몫(9월 {w(SH[0])}번 · 10월 {w(OH[0])}번 들어옴)은 어느 쪽에도 넣지 않았습니다</p>
    <p>· 자리 = 구글이 준 평균 게재 순위. 검색 결과 제목은 10월 30일 사이트 화면으로 확인</p></div>''')

html = f'''<!doctype html><html lang="ko"><head><meta charset="utf-8"><title>10월 검색 마감 보고</title>
<link rel="stylesheet" href="../demo-src/pretendard.css">{STYLE}</head><body>
{cover}{p1}{pw}{p2}{p3}{pn}{p4}
</body></html>'''
open(OUT, 'w', encoding='utf-8').write(html)
print('O', O, 'S', S, 'OB', OB, 'ON', ON, 'SB', SB, 'SN', SN, 'MID', MID, 'NOVO', NOVO, 'PROD', PROD, 'SL', SL, 'COMP', COMPI, 'PRICE', PRICE)
