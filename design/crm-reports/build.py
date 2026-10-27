#!/usr/bin/env python3
"""CRM 쉬운 보고서 — 표지 + 4장, 연한 파랑 (네이버 보고서와 같은 옷).

숫자는 관리자 화면 두 곳에서 옮긴다 (이 파일 맨 위 표):
  · 마케팅 → 고객 세그먼트 → 「CRM 기간 성과」 (적립금 묶음 · 가게 전체 · 일별)
  · 마케팅 → 문자 참여 (신상품 · 노보 문자 파일)
쓰는 법: python3 design/crm-reports/build.py design/crm-reports/2026-10-crm.html
"""
import sys

OUT = sys.argv[1]
BLUE, SKY, PALE, LINE = '#2F6FE0', '#9CC3FF', '#EEF4FF', '#C7DCFF'
ASOF = '2026년 10월 27일 12:06'
PERIOD = '10월 13일 ~ 27일 (15일)'

# 가게 전체 — 이번 15일 vs 바로 앞 15일 (9/28 ~ 10/12)
STORE = dict(n=347, sales=20645592, first=180, again=167, aov=59497,
             p_n=263, p_sales=17710649, p_first=160, p_again=102, p_aov=67341)

# 보낸 것 — (날짜, 종류, 이름, 받은, 산, 보내기 전, 실입금, 쓴 적립금, 비고, 며칠째)
#   종류: pts = 적립금 · new = 신상품 문자 · novo = 노보 문자
SENDS = [
    ('10/13', 'pts',  '10월 감사 적립금',             912, 75, 43, 4618179, 686391, '', 15),
    ('10/14', 'new',  '신상품 · 영주사과 · 고창수박',  995, 70, 60, 3491319, None, '그 상품 2개', 14),
    ('10/15', 'new',  '신상품 · 크림타바코 · 말라이트', 289, 31, 22, 2211944, None, '그 상품 0개', 13),
    ('10/16', 'pts',  '10월 감사 적립금 추가',         101, 10, 14,  926078,  87922, '', 12),
    ('10/19', 'new',  '신상품 · 환타지아',             383, 19, 23,  891190, None, '그 상품 0개', 9),
    ('10/20', 'novo', '노보 단골 지원금 1만원 + 단종 안내', 213, 14, 6, 2305411, 254389, '노보 14개', 8),
    ('10/20', 'pts',  '재구매 감사 적립금',             11,  0,  1,       0,      0, '', 8),
    ('10/21', 'new',  '신상품 · 스피아민트 · 아메리카노', 61,  4,  5,  271655, None, '그 상품 0개', 7),
    ('10/22', 'novo', '노보 지원금 다시 알림',           205,  6,  2,  860753, None, '노보 5개 · 위 14명 안', 6),
    ('10/22', 'new',  '신상품 · 금실딸기 · 복숭아',       21,  0,  1,       0, None, '그 상품 0개', 6),
]
TODAY = [('10/27', '10월 고객 감사 적립금', 112, 560000), ('10/27', '10월 단골 감사 적립금', 21, 181000)]

# 일별 (날짜, 주문, 실입금, 처음 산, 다시 산) — 10/27 은 12시까지
DAYS = [('10/13', 30, 1504837, 5, 25), ('10/14', 18, 1174153, 8, 10), ('10/15', 22, 1267971, 7, 15),
        ('10/16', 30, 1565502, 15, 15), ('10/17', 3, 109640, 2, 1), ('10/18', 16, 822255, 8, 8),
        ('10/19', 19, 908054, 12, 7), ('10/20', 41, 3402336, 12, 29), ('10/21', 31, 1679074, 18, 13),
        ('10/22', 34, 2032703, 23, 11), ('10/23', 28, 2055156, 15, 13), ('10/24', 13, 615270, 11, 2),
        ('10/25', 20, 1103105, 13, 7), ('10/26', 31, 1785036, 23, 8), ('10/27', 11, 620500, 8, 3)]
MARKS = {'10/13': '감사 적립금', '10/16': '추가', '10/20': '노보 지원금', '10/22': '노보 다시 알림'}

MARGIN = .5          # 매출총이익률 어림 (9월 결산 원가율로 본 값)


def w(n): return f'{n:,.0f}'
def man(n): return f'{n / 10000:,.0f}만원' if n >= 100000 else (f'{n / 10000:.1f}만원' if n >= 10000 else f'{n:,.0f}원')
def lift(s): return s[4] - s[5]
def pct(a, b): return f'{a / b * 100:.1f}%' if b else '-'


def by(kind): return [s for s in SENDS if s[1] == kind]


A = SENDS[0]                       # 10/13 감사 적립금
NV = SENDS[5]                      # 노보 지원금
NEW = by('new')
NEW_N, NEW_B = 1388, 104        # 문자 참여 합계 (같은 사람 한 번 · 다섯 파일 1,749통)
NEW_LIFT = sum(lift(s) for s in NEW)
NEW_SALES = sum(s[6] for s in NEW)
USED = sum(s[7] or 0 for s in SENDS)
AGAIN_X = STORE['again'] / STORE['p_again']
SALES_UP = STORE['sales'] / STORE['p_sales'] - 1
AOV_DN = 1 - STORE['aov'] / STORE['p_aov']


def per_won(s): return s[6] / s[7] if s[7] else 0


def gain(s):
    """늘어난 사람 × 산 사람 한 명당 실입금 — 문자 덕에 늘어난 매출 어림."""
    return lift(s) * (s[6] / s[4]) if s[4] else 0


def chart():
    W, H, L, R, T, B = 560, 210, 26, 6, 26, 24
    mx = 45
    n = len(DAYS); cw = (W - L - R) / n; bw = cw * .62
    y = lambda v: T + (H - T - B) * (1 - v / mx)
    s = [f'<svg viewBox="0 0 {W} {H}" width="100%" style="display:block">']
    for g in (0, 15, 30, 45):
        s.append(f'<line x1="{L}" x2="{W - R}" y1="{y(g):.1f}" y2="{y(g):.1f}" stroke="#E5E9EE"/>'
                 f'<text x="{L - 5}" y="{y(g) + 3:.1f}" font-size="9" fill="#6B7280" text-anchor="end">{g}</text>')
    for i, (d, o, _, f, a) in enumerate(DAYS):
        x = L + i * cw + (cw - bw) / 2
        s.append(f'<rect x="{x:.1f}" y="{y(a):.1f}" width="{bw:.1f}" height="{y(0) - y(a):.1f}" fill="{BLUE}" rx="1.5"/>')
        s.append(f'<rect x="{x:.1f}" y="{y(a + f):.1f}" width="{bw:.1f}" height="{y(a) - y(a + f):.1f}" fill="{SKY}" rx="1.5"/>')
        s.append(f'<text x="{x + bw / 2:.1f}" y="{y(o) - 3:.1f}" font-size="8.5" fill="#374151" text-anchor="middle">{o}</text>')
        s.append(f'<text x="{x + bw / 2:.1f}" y="{H - B + 13}" font-size="8.5" fill="#6B7280" text-anchor="middle">{d[3:]}</text>')
        if d in MARKS:
            s.append(f'<text x="{x + bw / 2:.1f}" y="{T - 12}" font-size="8.5" font-weight="800" fill="#111" text-anchor="middle">{MARKS[d]}</text>'
                     f'<line x1="{x + bw / 2:.1f}" x2="{x + bw / 2:.1f}" y1="{T - 9}" y2="{y(o) - 12:.1f}" stroke="#94A3B8" stroke-dasharray="2 2"/>')
    s.append('</svg>')
    return ''.join(s)


def send_table():
    head = ('<table class="st"><tr><th>보낸 날</th><th>무엇을</th><th class="n">받은</th><th class="n">산</th>'
            '<th class="n">받기 전</th><th class="n">늘어난</th><th class="n">실입금</th><th class="n">쓴 적립금</th></tr>')
    rows = []
    for s in SENDS:
        lf = lift(s)
        cls = 'up' if lf > 0 else ('dn' if lf < 0 else '')
        tag = {'pts': '<i class="tg p">적립금</i>', 'new': '<i class="tg n">문자</i>', 'novo': '<i class="tg v">노보</i>'}[s[1]]
        note = f'<small>{s[8]}</small>' if s[8] else ''
        days = f'<small class="g">{s[9]}일째</small>' if s[9] < 14 else ''
        rows.append(f'<tr><td>{s[0]}{days}</td><td>{tag} {s[2]}{note}</td><td class="n">{s[3]}</td>'
                    f'<td class="n"><b>{s[4]}</b> <small>{pct(s[4], s[3])}</small></td><td class="n">{s[5]}</td>'
                    f'<td class="n {cls}"><b>{lf:+d}</b></td><td class="n">{man(s[6]) if s[6] else "-"}</td>'
                    f'<td class="n">{man(s[7]) if s[7] else "-"}</td></tr>')
    for d, nm, n, amt in TODAY:
        rows.append(f'<tr class="td"><td>{d}</td><td><i class="tg p">적립금</i> {nm}<small>오늘 넣음 · 결과는 11월 보고</small></td>'
                    f'<td class="n">{n}</td><td class="n" colspan="5" style="text-align:left;color:#6B7280">{w(amt)}원 넣음</td></tr>')
    return head + ''.join(rows) + '</table>'


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
CSS = CSS.replace('</style>', f'''
.st {{ font-size: 10pt; margin-bottom: 8pt; }} .st td {{ padding: 3.5pt 5pt; vertical-align: top; line-height: 1.45; }} .st th {{ padding: 4pt 5pt; }}
.st small {{ display: block; font-size: 9pt; color: #6B7280; }} .st .n small {{ display: inline; }}
.st td.n small.g, .st td small.g {{ display: block; }}
.st .up b {{ color: #067647; }} .st .dn b {{ color: #B42318; }} .st tr.td td {{ color: #374151; background: #F8FAFC; }}
.tg {{ font-style: normal; font-size: 8.5pt; font-weight: 800; border-radius: 99pt; padding: 1pt 6pt; margin-right: 2pt; white-space: nowrap; }}
.tg.p {{ background: {PALE}; color: {BLUE}; }} .tg.n {{ background: #F1F5F9; color: #475569; }} .tg.v {{ background: #111; color: #fff; }}
.cmp {{ display: grid; grid-template-columns: 1fr 1fr; gap: 12pt; margin: 4pt 0 14pt; }}
.cmp .c {{ border: 1.5px solid {LINE}; border-radius: 8pt; padding: 10pt 12pt; }}
.cmp .c.dim {{ border-color: #E5E9EE; }}
.cmp .t {{ font-size: 11pt; font-weight: 900; margin-bottom: 4pt; }}
.cmp .v {{ font-size: 22pt; font-weight: 900; line-height: 1.15; }} .cmp .v small {{ font-size: 11pt; color: #374151; }}
.cmp p {{ margin: 3pt 0 0; font-size: 11pt; color: #374151; }}
.legend i.f {{ background: {SKY}; }} .legend i.a {{ background: {BLUE}; }}
</style>''')


def frame(no, title, body):
    return f'''<section class="page">
  <div class="run"><span>10월 CRM 보고</span><span>{no} · {title}</span></div>
{body}
  <div class="foot"><span>10월 CRM 보고</span><span><b>{no}</b> / 4</span></div>
</section>'''


cover = f'''<section class="page cover">
  <div class="eb">액상덕후 · 10월 CRM 보고</div>
  <div class="big">{AGAIN_X:.1f}배</div>
  <h1>CRM을 보낸 15일 동안<br>다시 산 손님 주문이 {AGAIN_X:.1f}배가 됐습니다</h1>
  <p class="lead">바로 앞 15일 {STORE['p_again']}건 → 이번 15일 {STORE['again']}건. 처음 산 손님 주문은 {STORE['p_first']} → {STORE['first']}건으로 조금 늘었습니다.</p>
  <div class="facts">
    <div class="fact"><div class="v">{man(STORE['sales'])}</div><div class="l">15일 실입금<br>(앞 15일보다 +{SALES_UP * 100:.0f}%)</div></div>
    <div class="fact"><div class="v">+{lift(A)}명</div><div class="l">10/13 감사 적립금으로<br>받기 전보다 더 산 사람</div></div>
    <div class="fact"><div class="v">{man(USED)}</div><div class="l">손님이 실제로 쓴 적립금<br>(넣은 금액이 아니라 쓴 금액)</div></div>
  </div>
  <div class="words"><div class="t">이 보고서에서 쓰는 말</div>
    <p><b>산 사람</b> · 받은 뒤 14일 안에 돈이 들어온 주문을 낸 사람</p>
    <p><b>받기 전</b> · 같은 사람들이 받기 전 같은 날수 동안 산 사람 수 (원래 샀을 사람)</p>
    <p><b>늘어난</b> · 산 사람 − 받기 전. 문자 · 적립금 덕에 더 산 사람</p></div>
  <div class="meta"><span><b>보고 대상</b> 대표님 · 이사님</span><span><b>작성</b> 온라인몰 운영</span><span><b>{ASOF}</b> 집계 · {PERIOD}</span></div>
</section>'''

p1 = frame(1, '한눈에', f'''  <div class="eb">1 · 한눈에</div>
  <h2>돈이 걸린 문자는 움직였고,<br>소식만 알린 문자는 움직이지 않았습니다</h2>
  <div class="rows">
    <div class="row"><span class="k ok">잘된 것</span><p>10/13 감사 적립금 · <b>912명 중 {A[4]}명이 샀습니다 (받기 전 {A[5]}명)</b><small>더 산 사람 {lift(A)}명 · 실입금 {man(A[6])} · 쓴 적립금 {man(A[7])}. 넣은 날 주문 30건 중 25건이 다시 산 손님이었습니다</small></p></div>
    <div class="row"><span class="k ok">잘된 것</span><p>노보 지원금 1만원 · <b>213명 중 {NV[4]}명이 샀고 노보 14개가 팔렸습니다</b><small>같은 기간 가게 전체 노보 판매 60개의 23%. 쓴 지원금 {man(NV[7])}</small></p></div>
    <div class="row"><span class="k bad">아쉬운 것</span><p>신상품 알림 문자 5번 · <b>{w(NEW_N)}명이 받고 그 신상품은 2개 팔렸습니다</b><small>산 사람 {NEW_B}명은 대부분 원래 사던 손님입니다 (다섯 번 합쳐 받기 전보다 {NEW_LIFT:+d}명). 문자만으로는 새 상품을 사게 하지 못했습니다</small></p></div>
    <div class="row"><span class="k mid">주의</span><p>한 건당 금액이 <b>{w(STORE['p_aov'])}원 → {w(STORE['aov'])}원 (−{AOV_DN * 100:.0f}%)</b><small>적립금을 쓰는 작은 주문이 늘어난 탓으로 보입니다. 건수가 늘어 전체 실입금은 +{SALES_UP * 100:.0f}%</small></p></div>
  </div>
  <h3>손님 주문, 앞 15일과 이번 15일</h3>
  <div class="cmp">
    <div class="c"><div class="t">다시 산 손님 주문</div><div class="v">{STORE['p_again']} → {STORE['again']}<small>건</small></div><p>{AGAIN_X:.1f}배 · CRM이 노린 손님입니다</p></div>
    <div class="c dim"><div class="t">처음 산 손님 주문</div><div class="v">{STORE['p_first']} → {STORE['first']}<small>건</small></div><p>+{STORE['first'] - STORE['p_first']}건 · CRM과는 무관한 손님입니다</p></div>
  </div>''')

p2 = frame(2, '보낸 것마다', f'''  <div class="eb">2 · 보낸 것마다</div>
  <h2>15일 동안 {len(SENDS) + len(TODAY)}번 보냈습니다</h2>
  <p class="lead">「늘어난」이 클수록 효과가 있었던 것입니다. 「N일째」는 14일이 덜 찬 줄입니다.</p>
  {send_table()}
  <div class="box"><div class="t">읽는 법</div>
    <p>· <b>노보 두 줄은 거의 같은 사람입니다.</b> 다시 알림 뒤 산 6명은 위 14명 안에 있어 더하지 않습니다.</p>
    <p>· 10/13 · 10/16 적립금은 기한 뒤 안 쓴 몫을 거뒀습니다. 비용은 「쓴 적립금」뿐입니다.</p>
    <p>· 미입금 안내 문자(10/14 · 17명)는 이 방식으로 잴 수 없어 뺐습니다.</p></div>''')

p3 = frame(3, '날마다', f'''  <div class="eb">3 · 날마다</div>
  <h2>적립금을 넣은 날마다<br>다시 산 손님 주문이 솟았습니다</h2>
  <p class="lead">파란색이 다시 산 손님, 하늘색이 처음 산 손님입니다. 막대 위 숫자는 그날 주문 건수입니다.</p>
  <div class="chart">{chart()}
    <div class="legend"><span><i class="a"></i>다시 산 손님</span><span><i class="f"></i>처음 산 손님</span><span class="g">10/27은 낮 12시까지</span></div></div>
  <div class="box"><div class="t">읽는 법</div>
    <p>· <b>10/13</b> 감사 적립금을 넣은 날, 주문 30건 중 25건이 다시 산 손님이었습니다.</p>
    <p>· <b>10/20</b> 노보 지원금을 넣은 날, 15일 중 가장 많은 41건 · {man(3402336)}. 그중 29건이 다시 산 손님입니다.</p>
    <p>· 10/17(토 · 3건)은 하루 주문이 거의 없던 날입니다. 이유는 이 자료로 알 수 없습니다.</p>
    <p>· 10/22 이후로는 처음 산 손님이 다시 산 손님보다 많습니다. 다시 산 손님은 적립금을 넣은 날과 그다음 이틀에 몰렸습니다.</p></div>''')

p4 = frame(4, '앞으로', f'''  <div class="eb">4 · 앞으로</div>
  <h2>11월에 할 일 셋</h2>
  <div class="todo"><span class="no">1</span><div><h3>적립금은 「상품을 정해서」 보냅니다</h3><p>노보처럼 무엇을 사면 되는지가 분명할 때 산 상품이 따라왔습니다. 쓴 적립금 1원당 실입금이 감사 적립금 {per_won(A):.1f}원 · 노보 지원금 {per_won(NV):.1f}원입니다.</p></div></div>
  <div class="todo"><span class="no">2</span><div><h3>신상품 소식은 문자 하나로 보내지 않습니다</h3><p>1,388명에게 다섯 번 보내 그 상품 2개가 팔렸습니다. 보낸다면 적립금 · 묶음 할인과 같이 보냅니다.</p></div></div>
  <div class="todo"><span class="no">3</span><div><h3>오늘 넣은 두 묶음과 할로윈 쿠폰을 11월 10일에 봅니다</h3><p>고객 감사 112명 · 단골 감사 21명(합계 {man(sum(t[3] for t in TODAY))}), 10/26 할로윈 쿠폰. 노보 · 신상품 문자도 14일이 다 찬 뒤 확정합니다.</p></div></div>
  <h2 style="margin-top:12pt">어림 셈 · 남는 장사였나</h2>
  <div class="goals">
    <div class="goal"><div class="l">감사 적립금 (10/13)</div><div class="v">+{man(gain(A))}</div><div class="d">더 산 {lift(A)}명의 실입금 어림 · 쓴 적립금 {man(A[7])}</div></div>
    <div class="goal"><div class="l">노보 지원금 (10/20)</div><div class="v">+{man(gain(NV))}</div><div class="d">더 산 {lift(NV)}명의 실입금 어림 · 쓴 지원금 {man(NV[7])}</div></div>
    <div class="goal"><div class="l">신상품 문자 5번</div><div class="v">{NEW_LIFT:+d}<small>명</small></div><div class="d">받은 {w(NEW_N)}명 중 · 그 신상품 2개</div></div>
  </div>
  <p class="g" style="font-size:10.5pt;margin:-6pt 0 12pt">매출총이익률을 50%로 보면 감사 적립금은 약 {man(gain(A) * MARGIN)}을 남기고 {man(A[7])}을 썼고, 노보 지원금은 약 {man(gain(NV) * MARGIN)}을 남기고 {man(NV[7])}을 썼습니다. 둘 다 남지만, 노보 쪽이 1원당 더 남았습니다.</p>
  <div class="src"><p><b>숫자 출처</b></p>
    <p>· 가게 전체 · 적립금 묶음 · 날마다: 관리자 「CRM 기간 성과」 {ASOF} 집계 (앞 기간 9월 28일 ~ 10월 12일)</p>
    <p>· 신상품 · 노보 문자: 관리자 「문자 참여」에 발송 파일을 올려 받은 사람의 주문과 맞춘 값</p>
    <p>· 실입금은 적립금 · 쿠폰을 뺀 뒤 손님이 실제로 낸 돈입니다. 늘어난 매출은 「늘어난 사람 × 산 사람 한 명당 실입금」으로 어림한 값입니다</p></div>''')

html = f'''<!doctype html><html lang="ko"><head><meta charset="utf-8"><title>10월 CRM 보고</title>
<link rel="stylesheet" href="../demo-src/pretendard.css">{CSS}</head><body>
{cover}{p1}{p2}{p3}{p4}
</body></html>'''
open(OUT, 'w', encoding='utf-8').write(html)
print('ok', round(AGAIN_X, 2), USED, lift(A), round(gain(A)), round(gain(NV)), round(per_won(A), 1), round(per_won(NV), 1))
