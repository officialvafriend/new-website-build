#!/usr/bin/env python3
"""네이버 검색 쉬운 보고서 둘째 판 — 표지 + 4장, 연한 파랑, 낱말 둘(보인 횟수 · 들어온 횟수)만 쓴다."""
import csv, json, math, random, sys, datetime

CSV, RANKS_DIR, OUT = sys.argv[1], sys.argv[2], sys.argv[3]
G_CLK, G_DAYS = 459, 26          # 구글 서치콘솔 10/1~26 클릭
BLUE, SKY, PALE, LINE = '#2F6FE0', '#9CC3FF', '#EEF4FF', '#C7DCFF'

r = list(csv.reader(open(CSV, encoding='utf-8-sig')))
days = r[0][1:]; imp = list(map(int, r[1][1:])); clk = list(map(int, r[2][1:]))
N = len(days)
dts = [datetime.date(2026, int(x[:2]), int(x[3:])) for x in days]
T_CLK = sum(clk)
N_DAY, G_DAY = T_CLK / N, G_CLK / G_DAYS
def rate(a, b): return sum(a) / sum(b) * 100
LW = (N // 7) * 7 - 7
W1 = dict(c=sum(clk[:7]) / 7, i=sum(imp[:7]) / 7, r=rate(clk[:7], imp[:7]))
WL = dict(c=sum(clk[LW:LW + 7]) / 7, i=sum(imp[LW:LW + 7]) / 7, r=rate(clk[LW:LW + 7], imp[LW:LW + 7]))
IMP_DROP = (1 - WL['i'] / W1['i']) * 100
CLK_DROP = (1 - WL['c'] / W1['c']) * 100
def md(d): return f'{d.month}/{d.day}'
WL_LAB = f'{md(dts[LW])}~{md(dts[LW + 6])}'

def rk(f):
    a = json.load(open(f'{RANKS_DIR}/{f}'))['ranks']
    return {q: (v.get('hits') or [[None]])[0][0] for q, v in a.items()}
r20, r26 = rk('2026-10-20-buy.json'), rk('2026-10-26-buy.json')
NQ = len(r26)
# 노보: 10/19 (전수) + 10/20 (단종 말) vs 10/27
def first_hit(v):
    h = v.get('hits') or []
    return (h[0][0] if isinstance(h[0], list) else h[0]) if h else None
nb = {q: first_hit(v) for q, v in json.load(open(f'{RANKS_DIR}/2026-10-19-all.json'))['ranks'].items() if '노보' in q}
nb.update({q: first_hit(v) for q, v in json.load(open(f'{RANKS_DIR}/2026-10-20-info.json')).items() if '노보' in q})
na = {q: first_hit(v) for q, v in json.load(open(f'{RANKS_DIR}/2026-10-27-novo.json'))['ranks'].items()}
NVQ = list(na)
NV_PAGE1 = (sum(1 for q in NVQ if nb.get(q)), sum(1 for q in NVQ if na[q]))
NV_TOP3 = (sum(1 for q in NVQ if nb.get(q) and nb[q] <= 3), sum(1 for q in NVQ if na[q] and na[q] <= 3))
_n = lambda v: v or 99
NV_UP = sorted([q for q in NVQ if _n(na[q]) < _n(nb.get(q))], key=lambda q: _n(na[q]))
NV_SAME = [q for q in NVQ if _n(na[q]) == _n(nb.get(q))]
NV_DOWN = sorted([q for q in NVQ if _n(na[q]) > _n(nb.get(q))], key=lambda q: _n(na[q]))
G_NOVO_PAGES = 36 + 24 + 17 + 17 + 2 + 1   # 구글 페이지 표 10/1~26, 노보 페이지 클릭 합
one20 = sum(v == 1 for v in r20.values()); one26 = sum(v == 1 for v in r26.values())
top3_20 = sum(1 for v in r20.values() if v and v <= 3); top3_26 = sum(1 for v in r26.values() if v and v <= 3)
ONES = [q for q, v in r26.items() if v == 1]
NONE = [q for q, v in r26.items() if not v]
def num(v): return v or 99
UP = sorted([q for q in r26 if num(r26[q]) < num(r20.get(q))], key=lambda q: (num(r26[q]), -(num(r20[q]) - num(r26[q]))))
DOWN = [q for q in r26 if num(r26[q]) > num(r20.get(q))]
def pos(v): return '첫 쪽 밖' if not v else f'{v}위'
def w(n): return f'{n:,.0f}'

random.seed(7)
def ul(text):
    pts = [(i * 30, 6 + random.uniform(-1.6, 1.6)) for i in range(11)]
    d = 'M' + ' L'.join(f'{x:.1f} {y:.1f}' for x, y in pts)
    return f'<span class="ul">{text}<svg viewBox="0 0 300 12" preserveAspectRatio="none"><path d="{d}" fill="none" stroke="{BLUE}" stroke-width="2.4" stroke-linecap="round"/></svg></span>'
def circle(wd=300, ht=140):
    pts = []
    for i in range(53):
        t = i / 50 * 2 * math.pi + 0.3
        pts.append((wd / 2 + (wd / 2 - 6) * math.cos(t) + random.uniform(-3, 3), ht / 2 + (ht / 2 - 8) * math.sin(t) + random.uniform(-3, 3)))
    return 'M' + ' L'.join(f'{x:.1f} {y:.1f}' for x, y in pts)

def chart():
    W, H, L, R, TOP, B = 640, 240, 34, 40, 18, 30
    cw = (W - L - R) / N
    cmax, imax = 130, 6500
    y = lambda v: TOP + (H - TOP - B) * (1 - v / cmax)
    yi = lambda v: TOP + (H - TOP - B) * (1 - v / imax)
    s = [f'<svg viewBox="0 0 {W} {H}" width="100%">']
    for d0, d1, lab in (('10.03', '10.05', '개천절 연휴'), ('10.09', '10.11', '한글날 연휴')):
        i0, i1 = days.index(d0), days.index(d1)
        s.append(f'<rect x="{L + i0 * cw:.1f}" y="{TOP}" width="{(i1 - i0 + 1) * cw:.1f}" height="{H - TOP - B}" fill="#F1F3F5"/>')
        s.append(f'<text x="{L + (i0 + (i1 - i0 + 1) / 2) * cw:.1f}" y="{TOP + 11}" font-size="9.5" fill="#6B7280" text-anchor="middle">{lab}</text>')
    for g in (0, 50, 100):
        s.append(f'<line x1="{L}" x2="{W - R}" y1="{y(g):.1f}" y2="{y(g):.1f}" stroke="#E5E9EE"/><text x="{L - 5}" y="{y(g) + 3:.1f}" font-size="9" fill="#6B7280" text-anchor="end">{g}번</text>')
    for g in (2000, 4000, 6000):
        s.append(f'<text x="{W - R + 5}" y="{yi(g) + 3:.1f}" font-size="9" fill="#6B7280">{g // 1000}천</text>')
    for i, c in enumerate(clk):
        x = L + i * cw + cw * 0.18
        s.append(f'<rect x="{x:.1f}" y="{y(c):.1f}" width="{cw * 0.64:.1f}" height="{y(0) - y(c):.1f}" rx="1.5" fill="{SKY}"/>')
    pts = ' L'.join(f'{L + (i + .5) * cw:.1f} {yi(v):.1f}' for i, v in enumerate(imp))
    s.append(f'<path d="M{pts}" fill="none" stroke="#374151" stroke-width="1.6" stroke-dasharray="4 3"/>')
    for i, d in enumerate(days):
        if i % 4 == 0:
            s.append(f'<text x="{L + (i + .5) * cw:.1f}" y="{H - B + 14}" font-size="9" fill="#6B7280" text-anchor="middle">{md(dts[i])}</text>')
    pk = days.index('10.20')
    px, py = L + (pk + .5) * cw, y(clk[pk])
    s.append(f'<rect x="{L + pk * cw + cw * .18:.1f}" y="{py:.1f}" width="{cw * .64:.1f}" height="{y(0) - py:.1f}" rx="1.5" fill="{BLUE}"/>')
    s.append(f'<text x="{px:.1f}" y="{py - 6:.1f}" font-size="10" font-weight="800" fill="#111" text-anchor="middle">10/20 · {clk[pk]}번</text>')
    s.append('</svg>')
    return ''.join(s)

def weeks():
    h = '<table><thead><tr><th>기간</th><th class="n">하루 보인 횟수</th><th class="n">하루 들어온 횟수</th><th class="n">누른 비율</th></tr></thead><tbody>'
    for s0 in range(0, N, 7):
        a, b = s0, min(s0 + 7, N); n = b - a
        lab = f'{md(dts[a])} ~ {md(dts[b - 1])}' + (f' <span class="g">({n}일)</span>' if n < 7 else '')
        h += f'<tr><td>{lab}</td><td class="n">{w(sum(imp[a:b]) / n)}번</td><td class="n"><b>{sum(clk[a:b]) / n:.0f}번</b></td><td class="n">{rate(clk[a:b], imp[a:b]):.1f}%</td></tr>'
    return h + '</tbody></table>'

UPT = [q for q in UP if r26[q] <= 3]
UPR = [q for q in UP if r26[q] > 3]
def up_table():
    h = '<table><thead><tr><th>네이버에 친 말</th><th class="n">10/20</th><th></th><th class="n">10/26</th></tr></thead><tbody>'
    for q in UPT:
        a = r26[q]
        h += f'<tr><td>{q}</td><td class="n g">{pos(r20.get(q))}</td><td class="ar">→</td><td class="n"><b class="{"acc" if a == 1 else ""}">{pos(a)}</b></td></tr>'
    return h + '</tbody></table>'
def down_table():
    h = '<table class="dn"><tbody>'
    for q in DOWN:
        h += f'<tr><td>{q}</td><td class="n g">{pos(r20.get(q))}</td><td class="ar">→</td><td class="n"><b>{pos(r26.get(q))}</b></td></tr>'
    return h + '</tbody></table>'
def nv_table(qs, title):
    h = f'<h3 style="margin-top:8pt">{title} {len(qs)}개</h3><table class="dn"><tbody>'
    for q in qs:
        a = na[q]
        note = ' <span class="g" style="font-size:9.5pt">(판매 중지)</span>' if q == '노보 그린펀치' else ''
        h += f'<tr><td>{q}{note}</td><td class="n g">{pos(nb.get(q))}</td><td class="ar">→</td><td class="n"><b class="{"acc" if a == 1 else ""}">{pos(a)}</b></td></tr>'
    return h + '</tbody></table>'
def chips(qs, cls=''):
    return f'<div class="chips {cls}">' + ''.join(f'<span>{q}</span>' for q in qs) + '</div>'

CSS = f"""<style>
@page {{ size: A4; margin: 0; }}
* {{ box-sizing: border-box; }} html, body {{ margin: 0; }}
body {{ font-family: "Pretendard Variable", Pretendard, sans-serif; color: #111; font-size: 12.5pt; line-height: 1.6; word-break: keep-all; }}
.page {{ width: 210mm; height: 297mm; padding: 17mm 18mm 16mm; position: relative; break-after: page; overflow: hidden; }}
.page:last-child {{ break-after: auto; }}
.run, .foot {{ position: absolute; left: 18mm; right: 18mm; display: flex; justify-content: space-between; font-size: 9pt; color: #6B7280; }}
.run {{ top: 8mm; }} .foot {{ bottom: 8mm; border-top: 1px solid #E5E9EE; padding-top: 4pt; }} .foot b {{ color: #111; }}
.eb {{ font-size: 10.5pt; color: {BLUE}; font-weight: 800; letter-spacing: .04em; }}
h1 {{ font-size: 30pt; line-height: 1.25; margin: 0 0 10pt; font-weight: 900; }}
h2 {{ font-size: 23pt; line-height: 1.28; margin: 4pt 0 8pt; font-weight: 900; }}
h3 {{ font-size: 14pt; margin: 0 0 6pt; font-weight: 900; }}
.lead {{ font-size: 13pt; color: #374151; margin: 0 0 14pt; }}
.g {{ color: #6B7280; }} .acc {{ color: {BLUE}; }}
.ul {{ position: relative; display: inline-block; }}
.ul svg {{ position: absolute; left: 0; right: 0; bottom: -4pt; width: 100%; height: 10pt; overflow: visible; }}
/* 표지 */
.cover {{ background: #0F172A; color: #fff; }}
.cover .eb {{ color: {SKY}; }}
.cover .big {{ position: relative; display: inline-block; font-size: 112pt; font-weight: 900; line-height: 1; margin: 22mm 0 12mm; }}
.cover .big svg {{ position: absolute; left: -22pt; top: -12pt; width: 300pt; height: 140pt; overflow: visible; }}
.cover h1 {{ color: #fff; }} .cover .lead {{ color: #CBD5E1; }}
.facts {{ display: grid; grid-template-columns: repeat(3, 1fr); gap: 12pt; margin-top: 18mm; }}
.fact {{ border-top: 2px solid {SKY}; padding-top: 8pt; }}
.fact .v {{ font-size: 26pt; font-weight: 900; line-height: 1.1; }} .fact .l {{ font-size: 11pt; color: #CBD5E1; }}
.words {{ margin-top: 14mm; background: #1E293B; border-radius: 8pt; padding: 12pt 14pt; font-size: 11.5pt; color: #E2E8F0; }}
.words .t {{ font-weight: 900; color: {SKY}; margin-bottom: 4pt; }} .words p {{ margin: 2pt 0; }} .words b {{ color: #fff; }}
.meta {{ position: absolute; left: 18mm; right: 18mm; bottom: 15mm; font-size: 10pt; color: #94A3B8; display: flex; justify-content: space-between; border-top: 1px solid #334155; padding-top: 8pt; }}
.meta b {{ color: #fff; }}
/* 한눈에 */
.rows {{ margin: 6pt 0 16pt; }}
.row {{ display: grid; grid-template-columns: 74pt 1fr; gap: 12pt; padding: 11pt 0; border-bottom: 1px solid #E5E9EE; align-items: baseline; }}
.row .k {{ font-size: 11pt; font-weight: 900; border-radius: 99pt; padding: 2pt 0; text-align: center; }}
.k.ok {{ background: {PALE}; color: {BLUE}; }} .k.mid {{ background: #F1F5F9; color: #334155; }} .k.bad {{ background: #FEF3F2; color: #B42318; }} .k.next {{ background: #111; color: #fff; }}
.row p {{ margin: 0; font-size: 13pt; }} .row p b {{ font-weight: 900; }}
.row p small {{ display: block; font-size: 11pt; color: #6B7280; }}
.bars {{ margin: 4pt 0 16pt; }}
.bar {{ display: grid; grid-template-columns: 52pt 1fr 52pt; align-items: center; gap: 8pt; margin: 5pt 0; font-size: 12pt; }}
.bar .f {{ height: 16pt; border-radius: 3pt; background: #CBD5E1; }} .bar.on .f {{ background: {BLUE}; }}
.bar .n {{ text-align: right; font-weight: 900; }}
.did {{ margin: 0; padding: 0; list-style: none; }}
.did li {{ position: relative; padding-left: 18pt; margin: 0 0 7pt; font-size: 12pt; }}
.did li::before {{ content: ""; position: absolute; left: 2pt; top: 8pt; width: 8pt; height: 8pt; border-radius: 50%; background: {BLUE}; }}
.did li small {{ color: #6B7280; font-size: 10.5pt; }}
/* 표 */
table {{ width: 100%; border-collapse: collapse; font-size: 11.5pt; margin: 2pt 0 12pt; }}
th {{ text-align: left; font-size: 10pt; color: #6B7280; font-weight: 700; border-bottom: 2px solid #111; padding: 4pt 6pt; }}
td {{ border-bottom: 1px solid #E5E9EE; padding: 6pt 6pt; }}
.n {{ text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }}
.ar {{ color: #94A3B8; text-align: center; width: 14pt; padding: 6pt 0; }}
table.dn {{ margin-top: 0; }} table.dn td {{ padding: 4pt 6pt; }}
.chart {{ border: 1px solid #E5E9EE; border-radius: 8pt; padding: 10pt 12pt 6pt; margin: 4pt 0 12pt; }}
.legend {{ display: flex; gap: 16pt; font-size: 10pt; color: #374151; margin-top: 2pt; }}
.legend i {{ display: inline-block; width: 12pt; height: 9pt; border-radius: 2pt; background: {SKY}; margin-right: 4pt; vertical-align: -1pt; }}
.legend i.l {{ height: 0; border-top: 2px dashed #374151; border-radius: 0; background: none; vertical-align: 3pt; }}
.box {{ background: {PALE}; border: 1px solid {LINE}; border-radius: 8pt; padding: 12pt 14pt; font-size: 12pt; }}
.box .t {{ font-weight: 900; margin-bottom: 4pt; }} .box p {{ margin: 0 0 4pt; }}
.chips {{ display: flex; flex-wrap: wrap; gap: 5pt; margin: 2pt 0 12pt; }}
.chips span {{ background: {PALE}; color: #1E3A8A; border-radius: 99pt; padding: 3pt 10pt; font-size: 10.5pt; font-weight: 700; }}
.chips.g span {{ background: #F1F5F9; color: #475569; font-weight: 600; }}
.cols {{ display: grid; grid-template-columns: 1.35fr 1fr; gap: 16pt; }}
.todo {{ display: grid; grid-template-columns: 34pt 1fr; gap: 10pt; margin: 0 0 14pt; }}
.todo .no {{ width: 30pt; height: 30pt; border-radius: 50%; background: {BLUE}; color: #fff; font-weight: 900; display: flex; align-items: center; justify-content: center; font-size: 14pt; }}
.todo h3 {{ margin: 2pt 0 2pt; }} .todo p {{ margin: 0; font-size: 12pt; color: #374151; }}
.goals {{ display: grid; grid-template-columns: repeat(3, 1fr); gap: 10pt; margin: 6pt 0 16pt; }}
.goal {{ border: 1.5px solid {LINE}; background: {PALE}; border-radius: 8pt; padding: 10pt 12pt; }}
.goal .l {{ font-size: 10.5pt; font-weight: 700; color: #374151; }}
.goal .v {{ font-size: 24pt; font-weight: 900; line-height: 1.2; }} .goal .v small {{ font-size: 12pt; color: #374151; }}
.goal .d {{ font-size: 11pt; color: {BLUE}; font-weight: 800; }}
.src {{ font-size: 10pt; color: #6B7280; border-top: 1px solid #E5E9EE; padding-top: 8pt; }} .src p {{ margin: 0 0 3pt; }}
</style>"""

def frame(no, title, body):
    return f'''<section class="page">
  <div class="run"><span>10월 네이버 검색 보고</span><span>{no} · {title}</span></div>
{body}
  <div class="foot"><span>10월 네이버 검색 보고</span><span><b>{no}</b> / 5</span></div>
</section>'''

cover = f'''<section class="page cover">
  <div class="eb">액상덕후 · 10월 네이버 검색 보고</div>
  <div class="big">{one26}개<svg viewBox="0 0 300 140"><path d="{circle()}" fill="none" stroke="{SKY}" stroke-width="3" stroke-linecap="round"/></svg></div>
  <h1>네이버에서 사려고 치는 말 {NQ}개 중<br>{one26}개에서 우리가 맨 위에 나옵니다</h1>
  <p class="lead">일주일 전(10월 20일)에는 {one20}개였습니다.</p>
  <div class="facts">
    <div class="fact"><div class="v">{w(T_CLK)}번</div><div class="l">30일 동안 네이버에서<br>눌러서 들어온 횟수</div></div>
    <div class="fact"><div class="v">{N_DAY / G_DAY:.1f}배</div><div class="l">구글보다 많이 들어옴<br>(하루 {N_DAY:.0f}번 : {G_DAY:.0f}번)</div></div>
    <div class="fact"><div class="v">{one20} → {one26}개</div><div class="l">맨 위(1위)에 나오는 말<br>(10/20 → 10/26)</div></div>
  </div>
  <div class="words"><div class="t">이 보고서에서 쓰는 말은 두 개뿐입니다</div>
    <p><b>보인 횟수</b> · 네이버 검색 결과에 우리 사이트가 나온 횟수</p>
    <p><b>들어온 횟수</b> · 그 결과를 눌러 우리 사이트에 들어온 횟수 (같은 사람이 두 번 들어오면 두 번)</p></div>
  <div class="meta"><span><b>보고 대상</b> 대표님 · 이사님</span><span><b>작성</b> 온라인몰 운영</span><span><b>2026년 10월 27일</b> · 기간 9월 27일 ~ 10월 26일</span></div>
</section>'''

p1 = frame(1, '한눈에', f'''  <div class="eb">1 · 한눈에</div>
  <h2>한 장으로 보면</h2>
  <div class="rows">
    <div class="row"><span class="k ok">잘된 것</span><p>맨 위에 나오는 말이 <b>일주일 만에 {one20}개 → {one26}개</b><small>3위 안에 드는 말은 {top3_20}개 → {top3_26}개 ({NQ}개 중)</small></p></div>
    <div class="row"><span class="k mid">그대로</span><p>네이버로 들어온 횟수 <b>하루 {N_DAY:.0f}번</b><small>구글(하루 {G_DAY:.0f}번)의 {N_DAY / G_DAY:.1f}배. 검색 손님은 대부분 네이버에서 옵니다</small></p></div>
    <div class="row"><span class="k bad">아쉬운 것</span><p>「전담 액상」 같은 <b>넓은 말 {len(NONE)}개는 아직 첫 쪽 밖</b><small>보인 횟수도 첫 주보다 {IMP_DROP:.0f}% 줄었습니다 (2쪽)</small></p></div>
    <div class="row"><span class="k next">다음 달</span><p>「입호흡 액상」을 <b>첫 쪽에 올리기</b><small>10월 26일에 첫 화면 제목을 바꿨고, 11월 9일에 확인합니다</small></p></div>
  </div>
  <h3>검색해서 들어온 횟수, 하루 평균</h3>
  <div class="bars">
    <div class="bar on"><span>네이버</span><span class="f" style="width:100%"></span><span class="n">{N_DAY:.0f}번</span></div>
    <div class="bar"><span>구글</span><span class="f" style="width:{G_DAY / N_DAY * 100:.0f}%"></span><span class="n">{G_DAY:.0f}번</span></div>
  </div>
  <h3>이번 달에 한 일 <span class="g" style="font-size:11pt;font-weight:600">(10월 20일)</span></h3>
  <ul class="did">
    <li>브랜드 페이지마다 <b>종류 · 가격 표</b>와 기준 날짜를 붙였습니다.<br><small>「화이트아웃 액상 종류」 {pos(r20.get('화이트아웃 액상 종류'))} → {pos(r26.get('화이트아웃 액상 종류'))}</small></li>
    <li>노보 분류 제목을 손님이 실제로 치는 말(「노보 액상 종류 · 가격」)로 바꿨습니다.</li>
    <li>상품 화면에 <b>그 브랜드 전체 보기</b> 길을 달았습니다.<br><small>상품 하나로 들어온 손님이 같은 브랜드의 다른 맛까지 봅니다</small></li>
  </ul>''')

p2 = frame(2, '30일 흐름', f'''  <div class="eb">2 · 30일 흐름</div>
  <h2>보인 횟수는 {IMP_DROP:.0f}% 줄었지만,<br>{ul(f'들어온 횟수는 {CLK_DROP:.0f}%만')} 줄었습니다</h2>
  <p class="lead">첫 주와 마지막 온전한 주({WL_LAB})를 견줬습니다. 보일 때 눌러 주는 비율이 {W1['r']:.1f}%에서 {WL['r']:.1f}%로 올랐습니다.</p>
  <div class="chart">{chart()}
    <div class="legend"><span><i></i>하루 들어온 횟수 (왼쪽 눈금)</span><span><i class="l"></i>하루 보인 횟수 (오른쪽 눈금)</span></div></div>
  {weeks()}
  <div class="box"><div class="t">읽는 법</div>
    <p>· 보인 횟수가 준 것은 <b>가게 이름을 치고 찾아오는 사람</b>이 10월에 줄었기 때문으로 보입니다. 구글에서도 같은 흐름이었습니다.</p>
    <p>· 회색 띠(연휴 두 번)에는 크게 줄었다가 평일에 돌아왔습니다.</p>
    <p>· 10월 중 가장 많이 들어온 날은 10월 20일({clk[days.index('10.20')]}번)입니다. 노보 단종 문자를 보낸 날이지만, 문자 때문인지는 이 자료로 알 수 없습니다.</p></div>''')

p3 = frame(3, '검색 순위', f'''  <div class="eb">3 · 검색 순위</div>
  <h2>맨 위에 나오는 말이<br>{ul(f'일주일 만에 {one26 - one20}개 늘었습니다')}</h2>
  <p class="lead">손님이 사려고 칠 만한 말 {NQ}개를 정해 두고, 네이버에서 직접 검색해 우리가 몇 번째에 나오는지 셉니다. (블로그 · 카페 글도 함께 섞인 첫 쪽 20개 기준)</p>
  <div class="cols">
    <div><h3>3위 안으로 오른 말 {len(UPT)}개</h3>{up_table()}
      <p class="g" style="font-size:10.5pt;margin:-4pt 0 14pt">이 밖에도 {len(UPR)}개가 올랐습니다 ({' · '.join(f'{q} {pos(r20.get(q))}→{pos(r26[q])}' for q in UPR[:3])} 등).</p></div>
    <div><h3>내려간 말 {len(DOWN)}개</h3>{down_table()}
      <h3 style="margin-top:10pt">아직 첫 쪽 밖 {len(NONE)}개</h3>{chips(NONE, 'g')}
      <p class="g" style="font-size:10.5pt;margin:0">이름에 「액상」이 들어간 큰 쇼핑몰들이 차지한 말이라 몇 달 단위로 봅니다.</p></div>
  </div>
  <h3>지금 1위인 말 {len(ONES)}개</h3>
  {chips(ONES)}''')

pn = frame(4, '노보', f'''  <div class="eb">4 · 노보</div>
  <h2>노보는 「단종」 검색에서 크게 올랐고,<br>{ul('「노보 액상 재고」는 1위에서 내려왔습니다')}</h2>
  <p class="lead">노보 검색어 {len(NVQ)}개를 10월 19일(단종 말 3개는 20일)과 오늘(27일) 같은 방법으로 쟀습니다.</p>
  <div class="goals" style="margin-top:0">
    <div class="goal"><div class="l">첫 쪽에 나오는 노보 검색어</div><div class="v">{NV_PAGE1[0]} → {NV_PAGE1[1]}<small>개</small></div><div class="d">{len(NVQ)}개 중</div></div>
    <div class="goal"><div class="l">3위 안</div><div class="v">{NV_TOP3[0]} → {NV_TOP3[1]}<small>개</small></div><div class="d">1위는 「노보 액상 품절」 하나</div></div>
    <div class="goal"><div class="l">구글에서 노보 화면으로</div><div class="v">{G_NOVO_PAGES}<small>번</small></div><div class="d">구글 전체의 {G_NOVO_PAGES / G_CLK * 100:.0f}% (10/1~26)</div></div>
  </div>
  <div class="cols" style="grid-template-columns:1fr 1fr">
    <div>{nv_table(NV_UP, '오른 말')}</div>
    <div>{nv_table(NV_DOWN, '내려간 말')}<p class="g" style="font-size:10.5pt;margin:0"><b style="color:#111">그대로 {len(NV_SAME)}개</b> · {' · '.join(f'{q} {pos(na[q])}' for q in NV_SAME)}</p></div>
  </div>
  <div class="box"><div class="t">읽는 법</div>
    <p>· <b>내려간 자리는 대부분 블로그 글이 위로 들어온 것입니다.</b> 블로그 · 카페를 빼고 가게 사이트끼리만 세면 「노보 액상 재고」 1 → 2위, 「노보 쿠바시가」 2 → 4위입니다. 「노보 액상 재고」 위 7개 중 6개가 다른 전자담배 가게들의 블로그 글입니다.</p>
    <p>· 「노보 단종」 {pos(nb.get('노보 단종'))} → {pos(na['노보 단종'])}, 「노보 액상 단종」 {pos(nb.get('노보 액상 단종'))} → {pos(na['노보 액상 단종'])}. 단종 소식을 찾는 사람에게 우리 화면이 보이기 시작했습니다.</p>
    <p>· 맛 이름 검색(블랙멘솔 · 아메리카노 · 엠에스블랜드 · 코코넛커피)은 첫 쪽 밖에서 첫 쪽 안으로 들어왔습니다.</p>
    <p>· 큰 말 「노보 액상」은 폰 첫 화면에서 일곱 화면쯤 내려야 나옵니다 (10/26).</p></div>''')

p4 = frame(5, '다음 달', f'''  <div class="eb">5 · 다음 달</div>
  <h2>11월에 할 일 셋</h2>
  <div class="todo"><span class="no">1</span><div><h3>넓은 말 하나를 첫 쪽으로</h3><p>「입호흡 액상」을 첫 화면 제목에 넣어 두었습니다. 11월 9일에 첫 쪽에 들었는지 봅니다.</p></div></div>
  <div class="todo"><span class="no">2</span><div><h3>자주 보이는데 덜 눌리는 상품에 설명 글</h3><p>디톡스 알로에베라 · 펠릭스 더블라임 · 마르키사 라즈베리부터 붙입니다. (구글 통계에서 찾은 상품)</p></div></div>
  <div class="todo"><span class="no">3</span><div><h3>네이버 「검색어」 자료도 받기</h3><p>이번 자료는 날짜별 합계뿐이라 손님이 무슨 말을 쳤는지 모릅니다. 다음 달엔 검색어 화면까지 받습니다.</p></div></div>
  <h2 style="margin-top:14pt">12월 초에 볼 숫자 셋</h2>
  <div class="goals">
    <div class="goal"><div class="l">하루 들어온 횟수</div><div class="v">{N_DAY:.0f}<small>번</small></div><div class="d">줄지 않으면 성공</div></div>
    <div class="goal"><div class="l">1위인 말</div><div class="v">{one26}<small>개</small></div><div class="d">20개가 목표</div></div>
    <div class="goal"><div class="l">첫 쪽에 든 넓은 말</div><div class="v">0<small>개</small></div><div class="d">1개가 목표</div></div>
  </div>
  <div class="src"><p><b>숫자 출처</b></p>
    <p>· 보인 · 들어온 횟수: 네이버 서치어드바이저 날짜별 합계, 9월 27일 ~ 10월 26일 (30일)</p>
    <p>· 순위: 정해 둔 {NQ}개 말을 10월 20일 · 26일에, 노보 {len(NVQ)}개는 10월 19 · 20일과 27일에 직접 검색 (블로그 · 카페 섞인 첫 쪽 20개 안 자리)</p>
    <p>· 구글: 구글 서치콘솔 10월 1 ~ 26일 클릭 {G_CLK}번 ÷ 26일</p></div>''')

html = f'''<!doctype html><html lang="ko"><head><meta charset="utf-8"><title>10월 네이버 검색 보고</title>
<link rel="stylesheet" href="../demo-src/pretendard.css">{CSS}</head><body>
{cover}{p1}{p2}{p3}{pn}{p4}
</body></html>'''
open(OUT, 'w', encoding='utf-8').write(html)
print('novo', NV_PAGE1, NV_TOP3, len(NV_UP), len(NV_SAME), len(NV_DOWN)); print('ok', one20, one26, top3_20, top3_26, len(UP), len(DOWN), len(NONE), round(IMP_DROP), round(CLK_DROP), W1, WL)
