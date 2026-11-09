"""CRM 기간 성과 쉬운 보고서 — 10월 13일 ~ 11월 9일.
숫자: 사이트 고객 세그먼트 「CRM 기간 성과」 붙여 넣기 글 (2026-11-09 11:30 집계).
묶음마다 받은 뒤 14일 산 사람 vs 같은 사람들의 받기 전 14일 (14일이 안 찬 묶음은 지난 날수).
python3 build_nov.py 2026-11-crm-period.html"""
import sys
sys.path.insert(0, '../reports-lib')
from easy import *

ASOF = '11월 9일 오전 11시 30분'
STORE = dict(n=729, sales=50080557, first=417, rep=311, aov=68698)
BASE = dict(n=552, sales=33909770, first=383, rep=168, aov=61431)
BIG = 4590000                       # 10/27 큰 주문 한 건 (10월 월마감 보고서)
# (날짜, 무엇, 받은 사람, 받은 뒤 산 사람, 받기 전 산 사람, 실입금, 쓴 적립금, 비고)
PTS = [('10/13', '감사 적립금', 912, 78, 43, 4848509, 700091, ''),
       ('10/16', '감사 적립금 추가', 101, 13, 14, 1063493, 97807, ''),
       ('10/20', '노보 단골 지원금 1만 원', 213, 22, 20, 3098643, 281157, ''),
       ('10/20', '재구매 감사 적립금', 11, 0, 1, 0, 0, ''),
       ('10/27', '고객 감사 적립금', 112, 9, 8, 371861, 55839, '13일'),
       ('10/27', '단골 감사 적립금', 21, 5, 5, 194012, 55488, '13일'),
       ('10/30', '월말 복귀 적립금', 1050, 21, 10, 1239925, 210575, '9일')]
NOW = [('11/4', '11월 첫째 주 감사 적립금', 1374, 41, 6, 1978409, 276076, '4일'),
       ('11/6', '한 번 더 챙겨드리는 적립금', 1251, 30, 21, 1360112, 249673, '3일')]
SMS = [('10/14', '신상품 · 영주사과 · 고창수박', 995, 74, 66, 3814544, '알린 상품 산 사람 2명'),
       ('10/15', '신상품 · 크림타바코 · 말라이트', 289, 34, 23, 2396054, '알린 상품 산 사람 0명'),
       ('10/19', '신상품 · 환타지아', 383, 28, 31, 1472705, '알린 상품 산 사람 1명'),
       ('10/21', '신상품 · 스피아민트 · 아메리카노', 61, 10, 12, 716750, '알린 상품 산 사람 1명'),
       ('10/22', '신상품 · 금실딸기 · 복숭아', 21, 0, 2, 0, '알린 상품 산 사람 0명'),
       ('10/20', '노보 단종 안내', 212, 22, 20, 3098643, '노보 산 사람 21명'),
       ('10/22', '노보 남은 재고 다시 알림', 205, 14, 15, 1656635, '노보 산 사람 12명'),
       ('10/20', '노보 구매 손님 안내', 425, 52, 66, 4635215, ''),
       ('10/14', '미입금 안내', 17, 1, 2, 165000, '')]

pd = sum(a - b for _, _, _, a, b, *_ in PTS)
sd = sum(a - b for _, _, _, a, b, *_ in SMS)
used = sum(x[6] for x in PTS)
up_rev = (78 - 43) * PTS[0][5] / 78
new_n = sum(x[2] for x in SMS[:5]); new_items = 4
ch = (STORE['sales'] / BASE['sales'] - 1) * 100
ch_x = ((STORE['sales'] - BIG) / BASE['sales'] - 1) * 100
rp = (STORE['rep'] / BASE['rep'] - 1) * 100

d = Doc('액상덕후 CRM 성과 10/13 ~ 11/9', 3)
cov = cover('액상덕후 · CRM 성과 · 10월 13일 ~ 11월 9일', f'+{rp:.0f}<small>%</small>',
    '다시 산 손님 주문이 85% 늘었습니다.<br>효과가 뚜렷한 것은 적립금이었습니다',
    f'4주 동안 적립금 9번 · 문자 9번을 보냈습니다. 받은 손님이 산 숫자를 같은 손님들이 받기 전 같은 날수에 산 숫자와 견줬습니다.',
    [(f'{STORE["sales"] / 1e4:,.0f}만 원', f'4주 실입금 (앞 4주 대비 +{ch:.0f}%)'), (f'+{pd}명', '적립금 7번으로 늘어난 손님'), (f'{sd:+d}명', '문자만 9번으로 늘어난 손님')],
    f'<span>보고 대상 <b>대표님 · 이사님</b></span><span>작성 <b>온라인몰 운영</b></span><span>2026년 11월 9일</span>')

p1 = d.frame(1, '한눈에', f'''<h2>다시 산 손님이 크게 늘었고,<br>효과가 보인 것은 적립금이었습니다</h2>
<p class="lead">10월 13일부터 11월 9일까지 4주를 바로 앞 4주(9월 15일 ~ 10월 12일)와 견줬습니다. 처음 산 손님은 비슷했고, 다시 산 손님이 늘었습니다.</p>
<div class="vs"><div class="vsb"><div class="l">앞 4주 다시 산 손님 주문</div><div class="v">{BASE["rep"]}건</div><div class="d">처음 산 손님 주문 {BASE["first"]}건</div></div>
<div class="vsb on"><div class="l">이번 4주 다시 산 손님 주문</div><div class="v">{STORE["rep"]}건</div><div class="d">처음 산 손님 주문 {STORE["first"]}건</div></div></div>
{rows([
 ('ok', '결과', '10월 13일 감사 적립금이 가장 확실했습니다', f'912명이 받았고 14일 동안 78명이 샀습니다. 받기 전 14일에는 43명이었습니다. 늘어난 35명의 매출은 어림잡아 {up_rev / 1e4:,.0f}만 원, 손님이 실제로 쓴 적립금은 70만 원입니다.'),
 ('mid', '결과', '월말 복귀 적립금은 크게 넣고 적게 돌아왔습니다', '1,050명에게 694만 원을 넣었고 9일 동안 21명(받기 전 10명)이 샀습니다. 한 번도 안 산 손님 604명 중 산 사람은 5명(0.8%)이고, 한동안 쉰 손님 446명 중에서는 16명(3.6%)입니다.'),
 ('bad', '걸리는 것', '문자만 보낸 것은 늘지 않았습니다', f'문자 9번을 합치면 받은 뒤 산 사람이 받기 전보다 {abs(sd)}명 적습니다. 신상품 문자는 {w(new_n)}통이 나갔고 알린 상품을 산 사람은 {new_items}명입니다.'),
])}
<div class="box"><div class="t">4주 실입금 +{ch:.0f}% 에는 큰 주문 한 건이 들어 있습니다</div><p>10월 27일 큰 주문(약 {BIG / 1e4:.0f}만 원)을 빼면 +{ch_x:.0f}% 입니다. 앞 4주에는 추석 연휴와 광고가 줄어든 기간이 들어 있어 이번 4주가 더 좋아 보이는 몫도 있습니다.</p></div>''')

CSS2 = """<style>
.sl { list-style: none; margin: 2pt 0 6pt; padding: 0; }
.sl li { display: grid; grid-template-columns: 34pt 1fr 92pt 48pt; gap: 8pt; align-items: center; padding: 2.6pt 0; border-top: 1px solid #EEF2F7; font-size: 10.5pt; }
.sl li:first-child { border-top: 0; }
.sl .dt { color: #6B7280; font-size: 9.5pt; } .sl .w small { display: block; font-size: 8.5pt; color: #6B7280; }
.sl .ba { text-align: right; font-size: 10.5pt; color: #6B7280; } .sl .ba b { color: #111; font-weight: 900; font-size: 11.5pt; }
.pl { text-align: center; font-weight: 900; border-radius: 99pt; padding: 2pt 0; font-size: 10pt; background: #F1F5F9; color: #334155; }
.pl.up { background: #E7F6EC; color: #1A7F37; } .pl.dn { background: #FEF3F2; color: #B42318; }
.gh { font-size: 11.5pt; font-weight: 900; margin: 6pt 0 0; } .gh small { font-weight: 400; color: #6B7280; font-size: 9pt; margin-left: 6pt; }
</style>"""

def li(dt, what, n, a, bb, sub):
    diff = a - bb
    pc = 'up' if diff > 0 else 'dn' if diff < 0 else ''
    return (f'<li><span class="dt">{dt}</span><span class="w">{what}<small>{sub}</small></span>'
            f'<span class="ba">{bb}명 → <b>{a}명</b></span><span class="pl {pc}">{diff:+d}명</span></li>')

def pt_sub(x):
    return f'받은 {w(x[2])}명 · 쓴 적립금 {w(x[6])}원' + (f' · {x[7]}째' if x[7] else '')

p2 = d.frame(2, '묶음별', f'''{CSS2}<h2>적립금 7번은 합쳐 {pd:+d}명,<br>문자만 9번은 합쳐 {sd:+d}명</h2>
<p class="lead">묶음마다 받은 손님 중 받기 전 14일 → <b>받은 뒤 14일</b>에 산 사람 수입니다. 14일이 안 찬 묶음은 지난 날수로 셌습니다. 오른쪽 알약이 그 차이입니다 (초록 = 늘어남).</p>
<div class="gh">적립금 · 지원금<small>{len(PTS)}번 · 쓴 적립금 합계 {w(used)}원</small></div>
<ul class="sl">{"".join(li(x[0], x[1], x[2], x[3], x[4], pt_sub(x)) for x in PTS)}</ul>
<div class="gh">문자만<small>{len(SMS)}번</small></div>
<ul class="sl">{"".join(li(x[0], x[1], x[2], x[3], x[4], f'받은 {w(x[2])}명' + (f' · {x[6]}' if x[6] else '')) for x in SMS)}</ul>
''')

p3 = d.frame(3, '앞으로', f'''<h2>적립금은 사던 손님에게 좁혀 주고,<br>모두에게 가는 문자는 멈춥니다</h2>
<p class="lead">4주 동안 손님이 실제로 쓴 적립금은 끝난 묶음 {w(used)}원, 진행 중 묶음 {w(NOW[0][6] + NOW[1][6])}원입니다. 넣은 금액은 거둬들여 쓰지 않은 몫은 돌아왔습니다.</p>
{CSS2}<div class="gh">아직 진행 중<small>11월에 넣은 적립금 · 14일이 차면 확정</small></div>
<ul class="sl">{"".join(li(x[0], x[1], x[2], x[3], x[4], pt_sub(x)) for x in NOW)}</ul>
<div class="todo"><div class="no">1</div><div><h3>적립금은 「최근에 산 적 있는 손님」에게</h3><p>10월 13일처럼 최근에 산 손님에게 넣은 적립금은 확실히 돌아왔습니다. 한 번도 안 산 손님(0.8%) · 첫 구매 유도(1.6%)에는 금액을 줄이거나 빼고, 한동안 쉰 손님(3.6%)은 남깁니다.</p></div></div>
<div class="todo"><div class="no">2</div><div><h3>모두에게 보내는 신상품 문자를 멈춥니다</h3><p>{w(new_n)}통에 알린 상품을 산 사람은 {new_items}명입니다. 신상품은 비슷한 맛을 산 손님에게만 알립니다.</p></div></div>
<div class="todo"><div class="no">3</div><div><h3>명단의 10%는 일부러 보내지 않습니다</h3><p>받은 손님과 안 받은 손님을 견주어야 적립금 덕분에 산 손님을 정확히 셀 수 있습니다. 지금은 받기 전 기간과 견주는 방법뿐이라 묶음이 겹치면 숫자가 섞입니다.</p></div></div>
<div class="todo"><div class="no">4</div><div><h3>11월 두 묶음은 14일이 차면 다시 봅니다</h3><p>11월 4일 묶음은 11월 18일, 11월 6일 묶음은 11월 20일에 확정합니다. 지금은 4일 · 3일째 숫자입니다.</p></div></div>
<div class="src"><p>숫자 출처: 사이트 고객 세그먼트 「CRM 기간 성과」 ({ASOF} 집계). 입금확인 · 배송준비 · 배송완료 주문만 셉니다.</p>
<p>「받기 전」은 같은 손님들이 받기 전 같은 날수 동안 낸 주문입니다. 같은 명단을 여러 번 내려받은 문자는 한 번으로 셌습니다.</p></div>''')

open(sys.argv[1], 'w').write(d.html('액상덕후 CRM 성과 10/13 ~ 11/9', cov, p1, p2, p3))
