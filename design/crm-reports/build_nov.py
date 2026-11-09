"""CRM 기간 성과 쉬운 보고서 — 10월 13일 ~ 11월 9일.
숫자: 사이트 고객 세그먼트 「CRM 기간 성과」 (2026-11-09 11:30 집계).
줄마다 전환율(받은 뒤 14일 안에 산 사람 ÷ 받은 사람)과 받기 전(같은 사람들이 받기 전 같은 날수에 산 비율)을 견준다.
같은 사람이 여러 문자를 받았으므로 줄끼리 더하지 않는다.
python3 build_nov.py 2026-11-crm-period.html"""
import sys
sys.path.insert(0, '../reports-lib')
from easy import *

ASOF = '11월 9일 오전 11시 30분'
STORE = dict(n=729, sales=50080557, first=417, rep=311)
BASE = dict(n=552, sales=33909770, first=383, rep=168)
BIG = 4590000                       # 10/27 큰 주문 한 건 (10월 월마감 보고서)
# (날짜, 종류, 무엇, 받은, 산, 받기 전 산, 14일 합계 입금, 적립금 쓴 사람, 비고)
LINES = [('10/13', '적립금', '10월 감사 적립금', 912, 78, 43, 4848509, 72, ''),
         ('10/14', '문자', '신상품 · 영주사과 · 고창수박', 995, 74, 66, 3814544, None, '알린 상품 산 사람 2명'),
         ('10/14', '문자', '미입금 안내 (정보성)', 17, 1, 2, 165000, None, ''),
         ('10/15', '문자', '신상품 · 크림타바코 · 말라이트', 289, 34, 23, 2396054, None, '알린 상품 산 사람 0명'),
         ('10/16', '적립금', '10월 감사 적립금 추가', 101, 13, 14, 1063493, 11, ''),
         ('10/19', '문자', '신상품 · 환타지아', 383, 28, 31, 1472705, None, '알린 상품 산 사람 1명'),
         ('10/20', '적립금', '노보 단골 지원금 1만 원', 213, 22, 20, 3098643, 22, '노보 산 사람 21명'),
         ('10/20', '적립금', '재구매 감사 적립금', 11, 0, 1, 0, 0, ''),
         ('10/21', '문자', '신상품 · 스피아민트 · 아메리카노', 61, 10, 12, 716750, None, '알린 상품 산 사람 1명'),
         ('10/22', '문자', '노보 지원금 다시 알림', 205, 14, 15, 1656635, None, '노보 산 사람 12명 · 위 지원금 22명 안'),
         ('10/22', '문자', '신상품 · 금실딸기 · 복숭아 (폐호흡)', 21, 0, 2, 0, None, '알린 상품 산 사람 0명'),
         ('10/27', '적립금', '10월 고객 감사 적립금', 112, 9, 8, 371861, 8, '이탈 방지 · 13일째'),
         ('10/27', '적립금', '10월 단골 감사 적립금', 21, 5, 5, 194012, 5, '단골 · 13일째'),
         ('10/30', '적립금', '10월 월말 적립금', 1050, 21, 10, 1239925, 20, '9일째 · 3쪽에 손님 묶음별'),
]  # 11/4 · 11/6 묶음은 14일이 안 차서 뺐다 — 11/18 · 11/20 이후 다음 보고서에
MONTH_END = [('월말 복귀 (한 번도 안 산 손님)', 604, 5, 4), ('월말 복귀 (한동안 쉰 손님)', 446, 16, 6)]
NOV4 = [('신규 2회차', 135, 4, 0), ('이탈 방지 (1회 산 손님)', 703, 27, 2), ('이탈 방지 (단골)', 160, 4, 0), ('첫 구매 유도', 376, 6, 4)]
USED_1013 = 700091

def r(a, n): return a / n * 100 if n else 0
NEW = [x for x in LINES if x[2].startswith('신상품')]
new_n = sum(x[3] for x in NEW); new_items = 4
ch = (STORE['sales'] / BASE['sales'] - 1) * 100
ch_x = ((STORE['sales'] - BIG) / BASE['sales'] - 1) * 100
rp = (STORE['rep'] / BASE['rep'] - 1) * 100
L13 = LINES[0]; d13 = r(L13[4], L13[3]) - r(L13[5], L13[3])


ALL_L = LINES + [('10/23', '적립금', '성장 이벤트 3,000원', 1571, 31, None, 1562402, 36, '')]
def _ex(x): return (x[4] - x[5]) * x[6] / x[4] if x[5] is not None and x[4] > x[5] else 0
EX_ALL = sum(_ex(x) for x in ALL_L)
EX_PTS = sum(_ex(x) for x in ALL_L if x[1] == '적립금')
EX_SMS = EX_ALL - EX_PTS
SUM_ALL = sum(x[6] for x in ALL_L)

d = Doc('액상덕후 CRM 성과 10/13 ~ 11/9', 6)
cov = cover('액상덕후 · CRM 성과 · 10월 13일 ~ 11월 9일', f'+{rp:.0f}<small>%</small>',
    '다시 산 손님 주문이 85% 늘었습니다.<br>효과가 확인된 것은 감사 적립금이었습니다',
    '4주 동안 적립금과 문자를 보냈습니다. 문자마다 받은 손님이 14일 안에 산 비율을, 같은 손님들이 받기 전 같은 날수에 산 비율과 견줬습니다.',
    [(f'{BASE["rep"]}→{STORE["rep"]}건', '다시 산 손님 주문 (앞 4주 → 이번 4주)'), (f'{r(L13[5], L13[3]):.1f}→{r(L13[4], L13[3]):.1f}명', '10월 13일 감사 적립금 · 100명 중 산 손님'), (f'{new_items}명', f'신상품 문자 {w(new_n)}통 → 알린 상품 산 손님')],
    '<span>보고 대상 <b>대표님 · 이사님</b></span><span>작성 <b>온라인몰 운영</b></span><span>2026년 11월 9일</span>')

p1 = d.frame(1, '한눈에', f'''<h2>다시 산 손님이 크게 늘었고,<br>효과가 확인된 것은 감사 적립금이었습니다</h2>
<p class="lead">10월 13일부터 11월 9일까지 4주를 바로 앞 4주(9월 15일 ~ 10월 12일)와 견줬습니다. 처음 산 손님 주문은 {BASE["first"]} → {STORE["first"]}건으로 비슷했고, 다시 산 손님 주문이 늘었습니다.</p>
<div class="vs"><div class="vsb"><div class="l">앞 4주 다시 산 손님 주문</div><div class="v">{BASE["rep"]}건</div><div class="d">처음 산 손님 주문 {BASE["first"]}건</div></div>
<div class="vsb on"><div class="l">이번 4주 다시 산 손님 주문</div><div class="v">{STORE["rep"]}건</div><div class="d">처음 산 손님 주문 {STORE["first"]}건</div></div></div>
{rows([
 ('ok', '결과', f'10월 13일 감사 적립금 · 100명 중 {r(L13[5], L13[3]):.1f}명 → {r(L13[4], L13[3]):.1f}명이 샀습니다', ''),
 ('mid', '결과', '월말 적립금은 한동안 쉰 손님만 더 샀습니다 · 100명 중 1.3명 → 3.6명', ''),
 ('bad', '걸리는 것', f'신상품 문자 {w(new_n)}통 · 알린 상품을 산 손님은 {new_items}명', ''),
])}
<div class="mbox"><div class="ml">CRM으로 늘어난 매출 (어림)</div><div class="mv">약 {EX_ALL/1e4:,.0f}만 원</div>
<div class="md">4주 가게 전체 실입금 {STORE["sales"]/1e4:,.0f}만 원의 {EX_ALL/STORE["sales"]*100:.0f}% · 적립금 {EX_PTS/1e4:,.0f}만 원 · 문자 {EX_SMS/1e4:,.0f}만 원</div></div>
<p class="note">받기 전보다 더 산 손님 수 × 한 명당 실입금을 묶음마다 구해 더했습니다. 자세한 금액은 2쪽에 있습니다.</p>''')

CSS2 = """<style>
table.t { width: 100%; border-collapse: collapse; font-size: 12pt; margin: 4pt 0 10pt; font-variant-numeric: tabular-nums; }
table.t th { text-align: left; font-size: 10.5pt; font-weight: 800; color: #111; background: #EEF4FF; border-bottom: 2px solid #2F6FE0; padding: 6pt 7pt; line-height: 1.3; }
table.t td { padding: 7pt 7pt; border-bottom: 1px solid #E5ECF6; vertical-align: middle; line-height: 1.35; }
table.t th.n, table.t td.n { text-align: right; white-space: nowrap; }
table.t tr.sum td { font-weight: 900; background: #F5F8FF; border-bottom: 2px solid #2F6FE0; }
table.t td.d { color: #6B7280; width: 34pt; }
.gh2 { font-weight: 900; font-size: 13.5pt; margin: 12pt 0 2pt; }
.rs { display: inline-block; font-size: 10.5pt; font-weight: 900; border-radius: 99pt; padding: 1.5pt 9pt; white-space: nowrap; }
.rs.up { background: #E7F6EC; color: #1A7F37; } .rs.eq { background: #F1F5F9; color: #475569; } .rs.dn { background: #FEF3F2; color: #B42318; }
.big { font-weight: 900; color: #2F6FE0; }
.note { font-size: 10.5pt; color: #4B5563; margin: 2pt 0 4pt; line-height: 1.5; }
.kt { list-style: none; padding: 0; margin: 10pt 0 0; }
.kt li { font-size: 13pt; font-weight: 800; padding: 8pt 0 8pt 16pt; border-top: 1px solid #E5E9EE; position: relative; }
.kt li::before { content: ""; position: absolute; left: 0; top: 15pt; width: 7pt; height: 7pt; border-radius: 50%; background: #2F6FE0; }
.kt li span { display: block; font-size: 11.5pt; font-weight: 400; color: #374151; margin-top: 2pt; }
</style>"""
MBOX = """<style>.mbox { background: #2F6FE0; color: #fff; border-radius: 10pt; padding: 14pt 18pt; margin: 10pt 0 6pt; }
.mbox .ml { font-size: 12pt; font-weight: 800; opacity: .9; } .mbox .mv { font-size: 30pt; font-weight: 900; line-height: 1.2; } .mbox .md { font-size: 12pt; }
.note { font-size: 10.5pt; color: #4B5563; }</style>"""
p1 = MBOX + p1

def res(a, b, n):
    if b is None: return '<span class="rs eq">받기 전 없음</span>'
    v = r(a, n) - r(b, n)
    if v >= 1.0: return '<span class="rs up">더 샀음</span>'
    if v <= -1.0: return '<span class="rs dn">덜 샀음</span>'
    return '<span class="rs eq">비슷</span>'

def eff(dt, what, n, a, b):
    bb = '–' if b is None else f'{r(b, n):.1f}명'
    return (f'<tr><td class="d">{dt}</td><td>{what}</td><td class="n">{w(n)}명</td><td class="n">{bb}</td>'
            f'<td class="n"><span class="big">{r(a, n):.1f}명</span></td><td class="n">{res(a, b, n)}</td></tr>')
ETH = ('<thead><tr><th></th><th>무엇</th><th class="n">받은 손님</th><th class="n">받기 전<br>100명 중</th>'
       '<th class="n">받은 뒤<br>100명 중</th><th class="n">결과</th></tr></thead>')

# 적립금 금액 — 사이트 「CRM 기간 성과」 적립금 묶음 자세히 (11/9 11:30, 넣은 뒤 14일)
# (날짜, 이름, 받은 손님, 넣은 적립금, 쓴 적립금, 실입금, 산 손님, 받기 전 산)
MONEY = [('10/13', '감사 적립금', 912, 4822200, 700091, 4848509, 78, 43),
         ('10/16', '감사 적립금 추가', 101, 505000, 97807, 1063493, 13, 14),
         ('10/20', '노보 단골 지원금', 213, 2130000, 281157, 3098643, 22, 20),
         ('10/20', '재구매 감사', 11, 55000, 0, 0, 0, 1),
         ('10/23', '성장 이벤트 (7일)', 1571, 4713000, None, 1562402, 31, None),
         ('10/27', '고객 감사', 112, 560000, 55839, 371861, 9, 8),
         ('10/27', '단골 감사', 21, 181000, 55488, 194012, 5, 5),
         ('10/30', '월말 적립금', 1050, 6944800, 210575, 1239925, 21, 10)]
def exm(x): return (x[6] - x[7]) * x[5] / x[6] if x[7] is not None and x[6] > x[7] else 0
def won(v): return f'{v:,.0f}원'
mrows = ''.join(f'<tr><td class="d">{x[0]}</td><td style="white-space:nowrap">{x[1]}</td><td class="n">{won(x[3])}</td>'
                f'<td class="n">{"–" if x[4] is None else won(x[4])}</td><td class="n"><b>{won(x[5])}</b></td>'
                f'<td class="n">{(won(round(exm(x), -3))) if exm(x) else "–"}</td></tr>' for x in MONEY)
M_PUT = sum(x[3] for x in MONEY); M_USED = sum(x[4] or 0 for x in MONEY); M_SALES = sum(x[5] for x in MONEY); M_EX = sum(exm(x) for x in MONEY)
mrows += f'<tr class="sum"><td></td><td>합계</td><td class="n">{won(M_PUT)}</td><td class="n">{won(M_USED)}</td><td class="n">{won(M_SALES)}</td><td class="n">{won(round(M_EX, -3))}</td></tr>'
p2 = d.frame(2, '적립금 금액', f'''{CSS2}<h2>적립금, 얼마 넣고 얼마 쓰였나</h2>
<p class="lead">넣은 뒤 14일 동안 받은 손님이 쓴 적립금과 낸 돈(실입금)입니다.</p>
<table class="t"><thead><tr><th></th><th>적립금</th><th class="n">넣은 적립금</th><th class="n">쓴 적립금</th><th class="n">실입금</th><th class="n">더 산 몫<br>(어림)</th></tr></thead><tbody>{mrows}</tbody></table>
<ul class="kt">
<li>넣은 적립금 {M_PUT/1e4:,.0f}만 원 중 실제로 쓰인 것은 {M_USED/1e4:,.0f}만 원입니다<span>쓰이지 않은 적립금은 기간이 끝나 거뒀습니다 (성장 이벤트는 쓴 금액이 사이트에 없어 빠져 있습니다).</span></li>
<li>적립금으로 늘어난 매출은 약 {M_EX/1e4:,.0f}만 원입니다<span>10월 13일 감사 적립금이 {exm(MONEY[0])/1e4:,.0f}만 원으로 가장 큽니다.</span></li>
</ul>
<p class="note">실입금은 받은 손님이 14일 안에 낸 돈 전부라 원래 샀을 손님 몫도 들어 있습니다. 더 산 몫 = 받기 전보다 더 산 손님 수 × 한 명당 실입금.<br>성장 이벤트는 운영 엑셀로 7일만 셌습니다. 같은 손님이 두 묶음을 받았으면 합계에 두 번 들어갑니다.</p>''')

erows = ''.join(eff(x[0], x[1].replace(' (7일)', ''), x[2], x[6], x[7]) for x in MONEY)
mend = ''.join(eff('', nm, k, a, b) for nm, k, a, b in MONTH_END)
p2b = d.frame(3, '적립금 효과', f'''{CSS2}<h2>적립금마다, 받은 손님 100명 중 몇 명이 샀나</h2>
<p class="lead">받은 뒤 14일 동안 산 손님을, 같은 손님들이 받기 전 같은 기간에 산 수와 견줬습니다.</p>
<table class="t">{ETH}<tbody>{erows}</tbody></table>
<div class="gh2">10월 30일 월말 적립금 · 손님 묶음별</div>
<table class="t">{ETH}<tbody>{mend}</tbody></table>
<p class="note">「더 샀음 · 덜 샀음」은 100명 중 1명 넘게 차이 날 때입니다. 월말 적립금은 9일째 숫자입니다.</p>''')


# 적립금으로 늘어난 매출 · 남은 이익 — 원가율 48.8% (10월 마감 · 대표 확인 원가)
COST_RATE = 0.488
def gain(x):
    dt, what, n, put, used, sales, a, b = x
    if used is None or b is None:
        return None
    g = (a - b) / a * (sales + used) if a > b else 0
    return (a - b) / a * sales if a > b else 0, g * (1 - COST_RATE) - used
grows = ''; G_SALES = G_PROFIT = G_USED = 0
for x in MONEY:
    r_ = gain(x)
    if r_ is None:
        continue
    ex, pf = r_
    G_SALES += ex; G_PROFIT += pf; G_USED += x[4]
    col = '#1A7F37' if pf > 0 else '#B42318'
    grows += (f'<tr><td class="d">{x[0]}</td><td style="white-space:nowrap">{x[1]}</td><td class="n">{won(x[4])}</td>'
              f'<td class="n">{won(round(ex, -3)) if ex else "0원"}</td><td class="n">{"0원" if round(pf, -3) == 0 else f'<b style="color:{col}">{"+" if pf > 0 else "−"}{won(round(abs(pf), -3))}</b>'}</td></tr>')
grows += (f'<tr class="sum"><td></td><td>합계</td><td class="n">{won(G_USED)}</td><td class="n">{won(round(G_SALES, -3))}</td>'
          f'<td class="n">+{won(round(G_PROFIT, -3))}</td></tr>')
p2c = d.frame(4, '적립금 이익', f'''{CSS2}<h2>적립금으로 매출은 약 {G_SALES/1e4:,.0f}만 원 늘었고,<br>원가와 적립금을 빼면 약 {G_PROFIT/1e4:,.0f}만 원이 남았습니다</h2>
<p class="lead">받기 전보다 더 산 손님만큼만 늘어난 매출로 셌습니다. 원래 사던 손님의 매출은 넣지 않았습니다.</p>
<table class="t"><thead><tr><th></th><th>적립금</th><th class="n">쓴 적립금</th><th class="n">늘어난 매출</th><th class="n">원가 · 적립금<br>뺀 이익</th></tr></thead><tbody>{grows}</tbody></table>
<ul class="kt">
<li>적립금 1원을 써서 매출이 약 {G_SALES/G_USED:.1f}원 늘었습니다<span>4주 가게 전체 매출 {STORE["sales"]/1e4:,.0f}만 원의 {G_SALES/STORE["sales"]*100:.0f}%입니다.</span></li>
<li>이익은 10월 13일 · 30일 두 묶음에서 나왔습니다<span>나머지 네 묶음은 받은 손님이 원래 사던 손님이라 적립금이 할인으로 끝났습니다.</span></li>
<li>가게 전체 매출이 늘어난 것을 적립금 덕으로 볼 수는 없습니다<span>같은 기간에 추석 · 큰 주문 · 문자가 함께 있었습니다. 적립금 몫으로 셀 수 있는 것은 위의 금액입니다.</span></li>
</ul>
<p class="note">원가율 48.8%(10월 마감 기준). 쓴 적립금은 모두 비용으로 봤습니다. 배송비 · 고정비는 넣지 않았고, 배송비(더 산 주문 약 50건)를 빼면 이익은 약 10만 원 줄어듭니다.<br>성장 이벤트는 쓴 금액이 사이트에 없고 받기 전 숫자가 없어 뺐습니다.</p>''')

SMS = [x for x in LINES if x[1] == '문자']
srows = ''.join(eff(x[0], x[2], x[3], x[4], x[5]) for x in SMS)
SMS_SALES = sum(x[6] for x in SMS); SMS_EX = sum(_ex(x) for x in SMS)
p3 = d.frame(5, '문자', f'''{CSS2}<h2>문자만 보낸 것</h2>
<p class="lead">적립금 없이 문자만 보낸 일곱 번입니다. 받은 뒤 14일 기준.</p>
<table class="t">{ETH}<tbody>{srows}</tbody></table>
<ul class="kt">
<li>신상품 문자 다섯 번 · {w(new_n)}통 · 알린 상품을 산 손님 {new_items}명<span>받은 손님이 산 것은 대부분 알린 상품이 아닌 다른 상품입니다 (5쪽).</span></li>
<li>문자로 늘어난 매출은 약 {SMS_EX/1e4:,.0f}만 원입니다<span>받은 손님이 14일 안에 낸 돈은 {SMS_SALES/1e4:,.0f}만 원입니다.</span></li>
</ul>
<p class="note">숫자 출처: 사이트 「CRM 기간 성과」 ({ASOF} 집계) · 입금확인 이후 주문만. 11월 4일 · 6일 묶음은 11월 보고서에 넣습니다.</p>''')

# 신상품 판매 — 사이트 「문자 참여 → 신상품 판매」 (11월 9일 14:45 집계)
# (상품, 판매, 매출, 산 손님, 문자 받고 14일 안, 그 뒤, 다른 신상품 문자만, 문자 안 받음)
NEWS = [('말라이트', 9, 158600, 9, 0, 0, 2, 7), ('복숭아 망고', 8, 116100, 8, 0, 0, 2, 6), ('고창수박', 7, 69300, 7, 2, 1, 0, 4),
        ('크림타바코', 7, 104700, 7, 0, 0, 1, 6), ('환타지아', 6, 89100, 6, 1, 0, 2, 3), ('영주사과', 4, 49500, 4, 0, 1, 0, 3),
        ('스피아민트', 4, 49500, 4, 0, 0, 3, 1), ('금실딸기 폐호흡', 4, 90000, 4, 0, 0, 0, 4)]
QUIET = [('뉴스프라이트포도', 11, 158600), ('백도우롱차', 9, 178200), ('ㅇㅋㄹㅌ', 8, 152300), ('유자녹차', 4, 51600), ('블루스위트', 2, 39800)]
sm = [sum(x[i] for x in NEWS) for i in range(1, 8)]
nrow = ''.join(f'<tr><td>{x[0]}</td><td class="n">{x[1]}개</td><td class="n">{won(x[2])}</td><td class="n">{x[3]}명</td>'
               f'<td class="n">{x[4] + x[5] + x[6]}명</td><td class="n"><b>{x[7]}명</b></td></tr>' for x in NEWS)
nrow += (f'<tr class="sum"><td>8종 합계</td><td class="n">{sm[0]}개</td><td class="n">{won(sm[1])}</td><td class="n">{sm[2]}명</td>'
         f'<td class="n">{sm[3] + sm[4] + sm[5]}명</td><td class="n">{sm[6]}명</td></tr>')
qrow = ''.join(f'<tr><td>{a}</td><td class="n">{b}개</td><td class="n">{won(c)}</td></tr>' for a, b, c in QUIET)
avg_n = sm[0] / len(NEWS); avg_q = sum(x[1] for x in QUIET) / len(QUIET)
p4 = d.frame(6, '신상품 판매', f'''{CSS2}<h2>신상품을 산 손님 {sm[2]}명 중 {sm[6]}명은<br>문자를 받지 않은 손님입니다</h2>
<p class="lead">문자로 알린 리퀴드랩 신상품 8종, 올린 날부터 11월 9일까지.</p>
<table class="t"><thead><tr><th>상품</th><th class="n">판매</th><th class="n">매출</th><th class="n">산 손님</th><th class="n">신상품 문자<br>받은 손님</th><th class="n">문자 안 받은<br>손님</th></tr></thead><tbody>{nrow}</tbody></table>
<div class="gh2">문자로 알리지 않은 신상품 (같은 날 올림)</div>
<table class="t" style="width:70%"><thead><tr><th>상품</th><th class="n">판매</th><th class="n">매출</th></tr></thead><tbody>{qrow}</tbody></table>
<ul class="kt">
<li>그 상품 문자를 받고 14일 안에 산 손님은 {sm[3]}명입니다</li>
<li>알린 상품과 알리지 않은 상품이 비슷하게 팔렸습니다<span>한 종에 평균 {avg_n:.1f}개 · {avg_q:.1f}개.</span></li>
</ul>''')

open(sys.argv[1], 'w').write(d.html('액상덕후 CRM 성과 10/13 ~ 11/9', cov, p1, p2, p2b, p2c, p3, p4))
