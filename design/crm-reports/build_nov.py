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

d = Doc('액상덕후 CRM 성과 10/13 ~ 11/9', 4)
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
 ('ok', '결과', f'10월 13일 감사 적립금이 가장 잘됐습니다 · 100명 중 {r(L13[5], L13[3]):.1f}명 → {r(L13[4], L13[3]):.1f}명', f'912명 중 78명이 14일 안에 샀습니다. 같은 손님들이 받기 전 같은 기간에는 100명 중 4.7명이 샀습니다. 적립금을 실제로 쓴 사람 72명, 쓴 금액 {w(USED_1013)}원.'),
 ('mid', '결과', '월말 적립금은 한동안 쉰 손님에게만 통했습니다', '「곧 사라집니다」 문구를 처음 쓴 10월 30일 묶음: 한동안 쉰 손님은 100명 중 1.3명 → 3.6명이 샀고, 한 번도 안 산 손님은 0.7명 → 0.8명으로 거의 그대로였습니다.'),
 ('bad', '걸리는 것', '신상품 문자는 알린 상품을 거의 팔지 못했습니다', f'다섯 번에 {w(new_n)}통이 나갔고 알린 상품을 산 사람은 {new_items}명입니다. '),
])}
<div class="box"><div class="t">4주 실입금 +{ch:.0f}% 에는 큰 주문 한 건이 들어 있습니다</div><p>4주 실입금은 {STORE["sales"] / 1e4:,.0f}만 원입니다. 10월 27일 큰 주문(약 {BIG / 1e4:.0f}만 원)을 빼면 +{ch_x:.0f}% 입니다. 앞 4주에는 추석 연휴와 광고가 줄어든 기간이 들어 있어 이번 4주가 더 좋아 보이는 몫도 있습니다. 기간을 11월 9일까지 잡은 것은 10월 30일 적립금의 사용 기간이 11월까지라서이고, 이 가게 전체 숫자에는 11월 4일 · 6일 적립금 효과도 일부 들어 있습니다.</p></div>''')

CSS2 = """<style>
table.cr { width: 100%; border-collapse: collapse; font-size: 10pt; margin: 2pt 0 6pt; }
table.cr th { text-align: left; font-weight: 800; color: #fff; background: #2F6FE0; padding: 3.2pt 6pt; font-size: 9pt; }
table.cr th.n, table.cr td.n { text-align: right; }
table.cr td { padding: 2.6pt 6pt; border-bottom: 1px solid #E5ECF6; vertical-align: middle; }
table.cr td small { display: block; color: #6B7280; font-size: 8pt; margin-top: 1pt; }
.gh2 { font-weight: 900; font-size: 11pt; margin: 6pt 0 2pt; }
.rs { display: inline-block; font-size: 8.6pt; font-weight: 900; border-radius: 99pt; padding: 1pt 7pt; white-space: nowrap; }
.rs.up { background: #E7F6EC; color: #1A7F37; } .rs.eq { background: #F1F5F9; color: #475569; } .rs.dn { background: #FEF3F2; color: #B42318; }
.big { font-weight: 900; color: #2F6FE0; }
</style>"""

def res(a, b, n):
    if b is None: return '<span class="rs eq">받기 전 없음</span>'
    v = r(a, n) - r(b, n)
    if v >= 1.0: return '<span class="rs up">더 샀음</span>'
    if v <= -1.0: return '<span class="rs dn">덜 샀음</span>'
    return '<span class="rs eq">비슷</span>'

def row(dt, what, note, n, a, b):
    bb = '–' if b is None else f'{r(b, n):.1f}명'
    return (f'<tr><td>{dt}</td><td>{what}' + (f'<small>{note}</small>' if note else '') + '</td>'
            f'<td class="n">{w(n)}명</td><td class="n">{bb}</td><td class="n"><span class="big">{r(a, n):.1f}명</span></td><td class="n">{res(a, b, n)}</td></tr>')

TH = ('<thead><tr><th>날짜</th><th>무엇을 보냈나</th><th class="n">받은 손님</th>'
      '<th class="n">받기 전<br>100명 중 산</th><th class="n">받은 뒤<br>100명 중 산</th><th class="n">결과</th></tr></thead>')
G23 = (1570, 31, 1562402, 36)       # 10/23 성장 이벤트 · 원장으로 넣은 묶음 · 운영 엑셀(지급 10/23 11:35 ~ 10/30 주문, 7일)

pts = []
for x in LINES:
    if x[1] != '적립금': continue
    dt, k, what, n, a, b, s_, used, note = x
    nt = '· '.join(t for t in [note, f'적립금 쓴 손님 {used}명' if used else ''] if t)
    pts.append((dt, row(dt, what, nt, n, a, b)))
pts.append(('10/23', row('10/23', '성장 이벤트 3,000원', '7일만 셈 · 3,000원 쓴 손님 36명', G23[0], G23[1], None)))
pts.sort(key=lambda t: tuple(int(v) for v in t[0].split('/')))
sms = ''.join(row(x[0], x[2], x[8], x[3], x[4], x[5]) for x in LINES if x[1] == '문자')

p2 = d.frame(2, '적립금', f'''{CSS2}<h2>적립금마다, 받은 손님 100명 중 몇 명이 샀나</h2>
<p class="lead">받은 뒤 14일 동안 100명 중 몇 명이 샀는지와, 같은 손님들이 받기 전 같은 기간에 산 수를 나란히 놓았습니다. 줄끼리 더하지 않습니다.</p>
<table class="cr">{TH}<tbody>{''.join(h for _, h in pts)}</tbody></table>
<p style="font-size:8.6pt;color:#6B7280">「더 샀음 · 덜 샀음」은 100명 중 1명 넘게 차이 날 때입니다.</p>''')

def sub(rows_):
    return ''.join(row('', nm, '', k, a, b).replace('<td></td>', '', 1) for nm, k, a, b in rows_)
TH2 = ('<thead><tr><th>손님 묶음</th><th class="n">받은 손님</th>'
       '<th class="n">받기 전<br>100명 중 산</th><th class="n">받은 뒤<br>100명 중 산</th><th class="n">결과</th></tr></thead>')
p3 = d.frame(3, '문자 · 손님 묶음', f'''{CSS2}<h2>문자만 보낸 것, 그리고 손님 묶음별</h2>
<div class="gh2">문자만</div>
<table class="cr">{TH}<tbody>{sms}</tbody></table>
<div class="gh2">10월 30일 월말 적립금 (9일째)</div>
<table class="cr">{TH2}<tbody>{sub(MONTH_END)}</tbody></table>
<div class="gh2" style="margin-top:10pt">정리하면</div>
<div class="todo"><div class="no">1</div><div><h3>사던 손님 · 쉰 손님은 적립금을 받고 더 샀습니다</h3><p>10월 13일 감사 적립금은 100명 중 4.7명 → 8.6명, 한동안 쉰 손님은 1.3명 → 3.6명입니다.</p></div></div>
<div class="todo"><div class="no">2</div><div><h3>한 번도 안 산 손님은 거의 그대로였습니다</h3><p>월말 적립금에서 0.7명 → 0.8명입니다. 손님을 가리지 않고 1,570명에게 3,000원을 넣은 10월 23일 묶음은 7일 동안 100명 중 2.0명이 샀습니다.</p></div></div>
<div class="todo"><div class="no">3</div><div><h3>신상품 문자로 알린 상품을 산 손님은 4명입니다</h3><p>다섯 번에 걸쳐 {w(new_n)}통이 나갔습니다.</p></div></div>
<div class="src"><p>숫자 출처: 사이트 고객 세그먼트 「CRM 기간 성과」 ({ASOF} 집계). 입금확인 이후 주문만 셉니다. 10월 23일 성장 이벤트(1,570명 · 471만 원)는 사이트 집계 밖에서 넣어 운영 엑셀로 셌습니다 — 지급 뒤 7일(10/30까지) 기준이고 받기 전 숫자가 없습니다. 3,000원을 쓴 36명은 11월 2일 회수 기록 기준이라 산 손님(입금확인 이후 주문) 31명보다 많습니다. 11월 4일 · 6일에 보낸 적립금은 14일이 아직 차지 않아 넣지 않았습니다. 10월 20일 「노보 구매 손님」 명단(425명)은 실제로 보냈는지 기록으로 알 수 없어 표에서 뺐습니다.</p></div>''')

CSS4 = """<style>
table.fx { width: 100%; border-collapse: collapse; font-size: 9.8pt; margin: 4pt 0 10pt; }
table.fx th { text-align: left; font-weight: 800; color: #fff; background: #2F6FE0; padding: 4pt 6pt; font-size: 9pt; }
table.fx td { padding: 5pt 6pt; border-bottom: 1px solid #E5ECF6; vertical-align: top; }
table.fx td:first-child { font-weight: 800; width: 22%; }
table.fx td:nth-child(2) { color: #4B5563; width: 36%; }
</style>"""
FIX = [
 ('적립금 받을 손님 고르기',
  '한 번도 안 산 손님은 적립금을 받아도 100명 중 0.7명 → 0.8명. 1,570명에게 3,000원을 넣은 10/23 묶음도 2.0명',
  '적립금은 최근에 산 손님 · 한동안 쉰 손님(1.3명 → 3.6명)에게 모으고, 한 번도 안 산 손님에게는 첫 주문 쿠폰처럼 다른 혜택을 따로 시험'),
 ('단골에게 주는 금액',
  '단골 감사 적립금은 받기 전에도 100명 중 23.8명이 샀고 받은 뒤도 23.8명. 노보 지원금 9.4명 → 10.3명 · 10/16 추가분 13.9명 → 12.9명도 비슷',
  '원래 사던 손님에게 준 돈이라 매출이 더 생기지 않았다. 단골은 금액을 줄이거나 적립금 대신 신상품 · 묶음 먼저 알리기로 바꿔 보기'),
 ('신상품 문자',
  f'다섯 번 {w(new_n)}통 → 알린 상품을 산 손님 4명. 다섯 번 중 셋은 받기 전보다 덜 샀음',
  '그 맛과 비슷한 맛을 산 손님에게만 보내고, 문자 안에 상품 주소 · 값 · 혜택을 같이 넣기'),
 ('적립금을 쓰게 만들기',
  '10/23 묶음은 1,570명 중 3,000원을 쓴 손님이 36명(2.3%). 나머지 1,534명분은 11/2 에 거둠',
  '기한 2~3일 전에 「곧 사라집니다」 한 번 더 알리기(10/30 월말 묶음에서 쉰 손님에게 통한 문구)'),
 ('같은 자로 재기',
  '10/23 묶음은 사이트 밖에서 넣어 7일치만 · 받기 전 숫자 없이 셌다. 손님군마다 받기 전 비율이 달라(0.4명~23.8명) 묶음끼리 견주기 어렵다',
  '적립금은 모두 「적립금 한 번에 넣기」 화면으로 넣고, 명단의 10%는 일부러 안 보내 받은 손님과 비교하기'),
]
fix_rows = ''.join(f'<tr><td>{a}</td><td>{b}</td><td>{c}</td></tr>' for a, b, c in FIX)
p4 = d.frame(4, '보완할 점', f'''{CSS4}<h2>다음 CRM 에서 보완할 점</h2>
<p class="lead">이번 4주 숫자에서 보이는 것과, 그에 맞춰 다음 달에 바꿔 볼 것입니다.</p>
<table class="fx"><thead><tr><th>무엇을</th><th>이번에 본 것</th><th>보완</th></tr></thead><tbody>{fix_rows}</tbody></table>
<div class="box"><div class="t">11월 보고서에서 다시 볼 것</div><p>11월 4일 · 6일 적립금(14일이 차는 11월 18일 · 20일 이후), 위 보완을 적용한 묶음이 받기 전보다 얼마나 더 샀는지.</p></div>''')

open(sys.argv[1], 'w').write(d.html('액상덕후 CRM 성과 10/13 ~ 11/9', cov, p1, p2, p3, p4))
