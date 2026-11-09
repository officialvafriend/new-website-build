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
         ('10/30', '적립금', '10월 월말 적립금', 1050, 21, 10, 1239925, 20, '소멸 문구 첫 적용 · 미구매 604 + 휴면 446 · 9일째'),
         ('11/4', '적립금', '11월 첫째 주 감사 적립금', 1374, 41, 6, 1978409, 38, '4일째 · 진행 중'),
         ('11/6', '적립금', '한 번 더 챙겨드리는 적립금', 1251, 30, 21, 1360112, 29, '리마인드 · 3일째 · 진행 중')]
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

d = Doc('액상덕후 CRM 성과 10/13 ~ 11/9', 3)
cov = cover('액상덕후 · CRM 성과 · 10월 13일 ~ 11월 9일', f'+{rp:.0f}<small>%</small>',
    '다시 산 손님 주문이 85% 늘었습니다.<br>효과가 확인된 것은 감사 적립금이었습니다',
    '4주 동안 적립금과 문자를 보냈습니다. 문자마다 받은 손님이 14일 안에 산 비율을, 같은 손님들이 받기 전 같은 날수에 산 비율과 견줬습니다.',
    [(f'{BASE["rep"]} → {STORE["rep"]}건', '다시 산 손님 주문 (앞 4주 → 이번 4주)'), (f'+{d13:.1f}%p', '10월 13일 감사 적립금 (받기 전 대비)'), (f'{w(new_n)}통 → {new_items}명', '신상품 문자 → 알린 상품 산 사람')],
    '<span>보고 대상 <b>대표님 · 이사님</b></span><span>작성 <b>온라인몰 운영</b></span><span>2026년 11월 9일</span>')

p1 = d.frame(1, '한눈에', f'''<h2>다시 산 손님이 크게 늘었고,<br>효과가 확인된 것은 감사 적립금이었습니다</h2>
<p class="lead">10월 13일부터 11월 9일까지 4주를 바로 앞 4주(9월 15일 ~ 10월 12일)와 견줬습니다. 처음 산 손님 주문은 {BASE["first"]} → {STORE["first"]}건으로 비슷했고, 다시 산 손님 주문이 늘었습니다.</p>
<div class="vs"><div class="vsb"><div class="l">앞 4주 다시 산 손님 주문</div><div class="v">{BASE["rep"]}건</div><div class="d">처음 산 손님 주문 {BASE["first"]}건</div></div>
<div class="vsb on"><div class="l">이번 4주 다시 산 손님 주문</div><div class="v">{STORE["rep"]}건</div><div class="d">처음 산 손님 주문 {STORE["first"]}건</div></div></div>
{rows([
 ('ok', '결과', f'10월 13일 감사 적립금이 가장 잘됐습니다 · {r(L13[4], L13[3]):.1f}% (받기 전 {r(L13[5], L13[3]):.1f}%)', f'912명 중 78명이 14일 안에 샀습니다. 같은 손님들이 받기 전 같은 날수에는 4.7%만 샀습니다. 적립금을 실제로 쓴 사람 72명, 쓴 금액 {w(USED_1013)}원.'),
 ('mid', '결과', '월말 적립금은 한동안 쉰 손님에게만 통했습니다', '「곧 사라집니다」 문구를 처음 쓴 10월 30일 묶음: 한동안 쉰 손님 446명은 3.6%(받기 전 1.3%)가 샀고, 한 번도 안 산 손님 604명은 0.8%(받기 전 0.7%)로 거의 그대로였습니다.'),
 ('bad', '걸리는 것', '신상품 문자는 알린 상품을 거의 팔지 못했습니다', f'다섯 번에 {w(new_n)}통이 나갔고 알린 상품을 산 사람은 {new_items}명입니다. 다섯 번 중 셋은 받기 전보다 산 비율이 낮았습니다.'),
])}
<div class="box"><div class="t">4주 실입금 +{ch:.0f}% 에는 큰 주문 한 건이 들어 있습니다</div><p>4주 실입금은 {STORE["sales"] / 1e4:,.0f}만 원입니다. 10월 27일 큰 주문(약 {BIG / 1e4:.0f}만 원)을 빼면 +{ch_x:.0f}% 입니다. 앞 4주에는 추석 연휴와 광고가 줄어든 기간이 들어 있어 이번 4주가 더 좋아 보이는 몫도 있습니다.</p></div>''')

CSS2 = """<style>
table.cr { width: 100%; border-collapse: collapse; font-size: 9.6pt; margin: 4pt 0 6pt; }
table.cr th { text-align: left; font-weight: 800; color: #fff; background: #2F6FE0; padding: 4pt 5pt; font-size: 9pt; }
table.cr th.n, table.cr td.n { text-align: right; }
table.cr td { padding: 3.6pt 5pt; border-bottom: 1px solid #E5ECF6; vertical-align: top; }
table.cr td small { display: block; color: #6B7280; font-size: 8pt; }
.tg { display: inline-block; font-size: 7.8pt; font-weight: 800; border-radius: 99pt; padding: 0 5pt; margin-right: 3pt; background: #E8F0FE; color: #2F6FE0; }
.tg.s { background: #F1F5F9; color: #475569; }
.up { color: #1A7F37; font-weight: 900; } .dn { color: #B42318; font-weight: 900; } .eq { color: #6B7280; font-weight: 800; }
tr.miss td { background: #FEF3F2; color: #B42318; }
</style>"""

def dp(a, b, n):
    v = r(a, n) - r(b, n)
    c = 'up' if v > 0.05 else 'dn' if v < -0.05 else 'eq'
    return f'<span class="{c}">{v:+.1f}%p</span>'

def tr(x):
    dt, k, what, n, a, b, s, used, note = x
    tg = f'<span class="tg{" s" if k == "문자" else ""}">{k}</span>'
    u = '–' if used is None else f'{used}명'
    return (f'<tr><td>{dt}</td><td>{tg}{what}' + (f'<small>{note}</small>' if note else '') + '</td>'
            f'<td class="n">{w(n)}</td><td class="n">{a}</td><td class="n"><b>{r(a, n):.1f}%</b></td><td class="n">{r(b, n):.1f}%</td><td class="n">{dp(a, b, n)}</td><td class="n">{u}</td></tr>')

MISS = '<tr class="miss"><td>10/23</td><td colspan="7"><span class="tg">적립금</span>성장 이벤트 (1,571명 · 3,000원) — 넣은 기록이 집계에 없어 비워 두었습니다</td></tr>'
body = ''.join(tr(x) for x in LINES if x[0] < '10/23') + MISS + ''.join(tr(x) for x in LINES if x[0] >= '10/23' or x[0] in ('11/4', '11/6'))
p2 = d.frame(2, '문자별', f'''{CSS2}<h2>문자마다 받은 뒤와 받기 전을 견줬습니다</h2>
<p class="lead">전환율 = 받은 뒤 14일 안에 산 사람 ÷ 받은 사람. 받기 전 = 같은 사람들이 받기 전 같은 날수에 산 비율. 차이가 +면 받고 더 샀다는 뜻입니다. 같은 손님이 여러 번 받았으므로 <b>줄끼리 더하지 않습니다.</b></p>
<table class="cr"><thead><tr><th>날짜</th><th>무엇</th><th class="n">받은</th><th class="n">산</th><th class="n">전환율</th><th class="n">받기 전</th><th class="n">차이</th><th class="n">적립금 쓴</th></tr></thead>
<tbody>{body}</tbody></table>''')
# 11/4 · 11/6 은 날짜 순으로 끝에 오도록 위에서 한 번만 붙는다 (문자열 비교 '11/4' >= '10/23')

def sub(rows_):
    return ''.join(f'<tr><td>{n}</td><td class="n">{w(k)}</td><td class="n">{a}</td><td class="n"><b>{r(a, k):.1f}%</b></td><td class="n">{r(b, k):.1f}%</td><td class="n">{dp(a, b, k)}</td></tr>' for n, k, a, b in rows_)
TH = '<thead><tr><th>손님 묶음</th><th class="n">받은</th><th class="n">산</th><th class="n">전환율</th><th class="n">받기 전</th><th class="n">차이</th></tr></thead>'
p3 = d.frame(3, '앞으로', f'''{CSS2}<h2>적립금은 사던 손님 · 쉰 손님에게,<br>모두에게 가는 문자는 멈춥니다</h2>
<div class="gh" style="font-weight:900;margin:2pt 0 0">10월 30일 월말 적립금 · 손님 묶음별 (9일째)</div>
<table class="cr">{TH}<tbody>{sub(MONTH_END)}</tbody></table>
<div class="gh" style="font-weight:900;margin:2pt 0 0">11월 4일 첫째 주 감사 적립금 · 손님 묶음별 (4일째 · 진행 중)</div>
<table class="cr">{TH}<tbody>{sub(NOV4)}</tbody></table>
<div class="todo"><div class="no">1</div><div><h3>적립금은 최근에 산 손님과 한동안 쉰 손님에게</h3><p>감사 적립금(받기 전 4.7% → 8.6%)과 쉰 손님(1.3% → 3.6%)은 받고 더 샀습니다. 한 번도 안 산 손님은 0.7% → 0.8%로 거의 그대로라 빼거나 금액을 줄입니다.</p></div></div>
<div class="todo"><div class="no">2</div><div><h3>모두에게 보내는 신상품 문자를 멈춥니다</h3><p>{w(new_n)}통에 알린 상품을 산 사람은 {new_items}명입니다. 신상품은 비슷한 맛을 산 손님에게만 알립니다.</p></div></div>
<div class="todo"><div class="no">3</div><div><h3>명단의 10%는 일부러 보내지 않습니다</h3><p>받은 손님과 안 받은 손님을 견주어야 문자 덕분에 산 손님을 정확히 셀 수 있습니다. 손님군마다 받기 전 비율이 달라(감사 4.7% · 월말 1.0%) 지금은 묶음끼리 견줄 수 없습니다.</p></div></div>
<div class="todo"><div class="no">4</div><div><h3>11월 두 묶음은 11월 18일 · 20일에 확정합니다</h3><p>11월 4일 묶음은 4일째에 3.0%(받기 전 0.4%)로 출발이 좋습니다. 14일이 차면 다시 봅니다.</p></div></div>
<div class="src"><p>숫자 출처: 사이트 고객 세그먼트 「CRM 기간 성과」 ({ASOF} 집계). 입금확인 이후 주문만 셉니다. 10월 23일 성장 이벤트(1,571명 · 471만 원)는 넣은 기록이 집계에 없어 확인이 필요합니다. 10월 20일 「노보 구매 손님」 명단(425명)은 실제로 보냈는지 기록으로 알 수 없어 표에서 뺐습니다.</p></div>''')

open(sys.argv[1], 'w').write(d.html('액상덕후 CRM 성과 10/13 ~ 11/9', cov, p1, p2, p3))
