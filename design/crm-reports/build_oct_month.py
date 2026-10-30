"""10월 CRM 성과 쉬운 보고서 — 문자 전체 (10/8~10/27).
숫자: 「CRM 성과 정리본」 엑셀(10/30 14:19 추출, 문자 5,726통 · 주문) 을 번호로 맞춘 것.
보낸 뒤 7일 산 사람 vs 같은 사람들의 보내기 전 7일. 적립금 사용 · 14일 숫자는 사이트 집계(build.py SENDS).
python3 build_oct_month.py 2026-10-crm-month.html"""
import sys
sys.path.insert(0, '../reports-lib')
from easy import *

SENT, OK, PEOPLE, COST = 5726, 5624, 2373, 171780
BUYERS, CONN = 164, 12099641          # 받은 뒤 7일 안에 산 사람 · 그 매출 (가장 최근 문자 하나에만 붙임)
# 날짜, 무엇, 받은 사람, 보낸 뒤 7일 산 사람, 보내기 전 7일, 보낸 뒤 매출, 돈이 걸렸나
SENDS = [('10/8', '노보 · 디오리퀴드 구매 손님 안내', 567, 40, 41, 3365800, 0),
         ('10/13', '감사 적립금', 931, 44, 35, 2112402, 1),
         ('10/14', '신상품 · 영주사과 · 고창수박', 973, 47, 43, 2404501, 0),
         ('10/15', '신상품 · 크림타바코 · 말라이트', 284, 19, 21, 1173258, 0),
         ('10/16', '감사 적립금 추가', 100, 11, 10, 992078, 1),
         ('10/19', '신상품 · 환타지아', 371, 18, 20, 859460, 0),
         ('10/20', '노보 단골 지원금 · 단종 안내 외', 436, 35, 37, 3548746, 1),
         ('10/21', '신상품 · 스피아민트 · 아메리카노', 57, 4, 6, 274785, 0),
         ('10/22', '노보 지원금 다시 알림 · 신상품', 214, 7, 4, 980063, 1),
         ('10/23', '단체 발송 (내용 기록 없음)', 1528, 29, 33, 1440872, 0)]
LATE = ('10/27', '고객 · 단골 감사 적립금', 140, 4, 6)
PTS = [('10월 감사 적립금 (10/13)', 912, 75, 43, 686391, 4618179), ('노보 단골 지원금 1만 원 (10/20)', 213, 14, 6, 254389, 2305411)]
NEW_N, NEW_ITEMS = 1388, 2           # 신상품 문자 다섯 번 받은 사람 · 그 상품이 팔린 개수
USED = 686391 + 87922 + 254389       # 넣은 적립금 중 실제로 쓴 것
TODAY = (133, 741000)                # 10/27 넣은 적립금 (결과 11월 10일)

A = sum(s[3] for s in SENDS); B = sum(s[4] for s in SENDS)
d = Doc('액상덕후 10월 CRM 성과', 3)
cov = cover('액상덕후 · 10월 CRM 성과 · 문자 10월 8일 ~ 27일', f'{BUYERS}<small>명</small>',
    '문자를 받은 손님 164명이 7일 안에 샀습니다.<br>다만 보내기 전 7일에도 비슷하게 샀습니다',
    f'10월에 문자 {w(SENT)}통을 {w(PEOPLE)}명에게 보냈습니다. 받은 사람이 산 숫자를, 같은 사람들이 문자를 받기 전 7일에 산 숫자와 견줬습니다.',
    [(f'{w(PEOPLE)}명', '문자를 받은 사람'), (f'{CONN / 1e4:,.0f}만 원', '받은 사람이 7일 안에 산 매출'), (f'{COST / 1e4:.0f}만 원', '문자 비용')],
    '<span>보고 대상 <b>대표님 · 이사님</b></span><span>작성 <b>온라인몰 운영</b></span><span>2026년 10월 30일</span>')

p1 = d.frame(1, '한눈에', f'''<h2>적립금이 함께 간 문자만 뚜렷하게 늘었습니다</h2>
<p class="lead">문자를 받은 사람이 산 매출은 1,210만 원입니다. 그런데 이 사람들은 대부분 원래 사던 단골이라, 문자를 받기 전 7일에도 거의 같은 수가 샀습니다. 그래서 「받고 산 매출」을 문자 덕으로 보지 않고, 보내기 전과 견준 차이만 봅니다.</p>
<div class="vs"><div class="vsb"><div class="l">보내기 전 7일에 산 사람</div><div class="v">{B}명</div><div class="d">문자 열 번 각각의 받은 사람 기준</div></div>
<div class="vsb on"><div class="l">보낸 뒤 7일에 산 사람</div><div class="v">{A}명</div><div class="d">차이 {A - B:+d}명</div></div></div>
{rows([
 ('ok', '결과', '감사 적립금을 넣은 손님은 받기 전보다 더 샀습니다', f'10월 13일 감사 적립금 912명: 14일 동안 75명이 샀습니다. 받기 전 14일에는 43명. 쓴 적립금 69만 원, 그 주문 매출 462만 원.'),
 ('ok', '결과', '노보 단골 지원금도 늘었습니다', '노보 묶음을 샀던 213명에게 1만 원: 14일 동안 14명(받기 전 6명)이 샀고 노보 14개가 나갔습니다.'),
 ('bad', '걸리는 것', f'신상품 문자는 그 상품을 거의 팔지 못했습니다', f'다섯 번에 걸쳐 {w(NEW_N)}명이 받았는데 알린 신상품은 {NEW_ITEMS}개 팔렸습니다. 받은 사람이 산 것은 원래 사던 상품입니다.'),
])}
<div class="box"><div class="t">「문자비의 70배 매출」로 읽지 않습니다</div><p>추출 파일의 효율 값(ROAS 70)은 받은 사람이 7일 안에 산 금액을 전부 문자 덕으로 센 값입니다. 보내기 전과 견주면 문자가 새로 만든 구매는 그보다 훨씬 적습니다.</p></div>''')

CSS2 = """<style>
.sl { list-style: none; margin: 4pt 0 12pt; padding: 0; }
.sl li { display: grid; grid-template-columns: 38pt 1fr 100pt 50pt; gap: 8pt; align-items: center; padding: 4.5pt 0; border-top: 1px solid #EEF2F7; font-size: 11pt; }
.sl li:first-child { border-top: 0; }
.sl .dt { color: #6B7280; font-size: 10pt; } .sl .w small { display: block; font-size: 9pt; color: #6B7280; }
.sl .ba { text-align: right; font-size: 11pt; color: #6B7280; } .sl .ba b { color: #111; font-weight: 900; font-size: 12pt; }
.pl { text-align: center; font-weight: 900; border-radius: 99pt; padding: 2pt 0; font-size: 10.5pt; background: #F1F5F9; color: #334155; }
.pl.up { background: #E7F6EC; color: #1A7F37; } .pl.dn { background: #FEF3F2; color: #B42318; }
.gh { font-size: 12pt; font-weight: 900; margin: 8pt 0 0; } .gh small { font-weight: 400; color: #6B7280; font-size: 9.5pt; margin-left: 6pt; }
.big2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10pt; margin: 6pt 0 8pt; }
.big2 > div { border: 1px solid #DCE6F5; border-radius: 10pt; padding: 10pt 12pt; }
.big2 .t { font-weight: 900; font-size: 11.5pt; } .big2 .v { font-size: 20pt; font-weight: 900; margin: 4pt 0 2pt; } .big2 .v small { font-size: 11pt; color: #6B7280; font-weight: 600; }
.big2 p { margin: 0; font-size: 9.5pt; color: #6B7280; }
</style>"""
MX = max(max(x[3], x[4]) for x in SENDS)
def li(dt, what, n, a, bb):
    diff = a - bb
    pc = 'up' if diff > 0 else 'dn' if diff < 0 else ''
    return (f'<li><span class="dt">{dt}</span><span class="w">{what}<small>받은 사람 {w(n)}명</small></span>'
            f'<span class="ba">{bb}명 → <b>{a}명</b></span>'
            f'<span class="pl {pc}">{diff:+d}명</span></li>')
money = [x for x in SENDS if x[6]]; plain = [x for x in SENDS if not x[6]]
p2 = d.frame(2, '문자별', f'''{CSS2}<h2>적립금이 간 문자는 4번 중 3번 늘었고,<br>알림만 간 문자는 6번 중 5번 줄었습니다</h2>
<p class="lead">문자마다 받은 사람 중 받기 전 7일 → <b>받은 뒤 7일</b>에 산 사람 수입니다. 오른쪽 알약이 그 차이입니다 (초록 = 늘어남, 빨강 = 줄어듦). 10월 27일 문자는 7일이 안 지나 뺐습니다.</p>
<div class="gh">적립금 · 지원금이 함께 간 문자<small>{len(money)}번</small></div>
<ul class="sl">{"".join(li(*x[:5]) for x in money)}</ul>
<div class="gh">알림만 간 문자<small>{len(plain)}번</small></div>
<ul class="sl">{"".join(li(*x[:5]) for x in plain)}</ul>
<div class="gh">적립금 두 번을 14일로 보면</div>
<div class="big2">{"".join(f'<div><div class="t">{n}</div><div class="v">{bb}명 → {a}명</div><p style="margin-bottom:4pt">받기 전 14일 → 받은 뒤 14일에 산 사람</p><p>{w(r)}명이 받음 · 쓴 적립금 {w(u)}원 · 그 주문 매출 {w(m)}원</p></div>' for n, r, a, bb, u, m in PTS)}</div>
''')

p3 = d.frame(3, '11월', f'''<h2>11월에는 보내는 수를 줄이고 재는 법을 바꿉니다</h2>
<p class="lead">10월에 쓴 돈은 문자비 {w(COST)}원과 손님이 실제로 쓴 적립금 {w(USED)}원입니다. 10월 27일 넣은 적립금 {w(TODAY[1])}원({TODAY[0]}명)의 결과는 11월 10일에 봅니다.</p>
<div class="todo"><div class="no">1</div><div><h3>모두에게 보내는 신상품 문자를 멈춥니다</h3><p>{w(NEW_N)}명이 받고 그 상품은 {NEW_ITEMS}개 팔렸습니다. 신상품은 그 맛과 비슷한 상품을 산 사람에게만 알립니다.</p></div></div>
<div class="todo"><div class="no">2</div><div><h3>문자는 「돈이 걸린 것」만, 작은 명단으로</h3><p>크게 늘어난 문자는 적립금 · 지원금이 함께 간 것이었습니다. 다시 살 때가 된 손님처럼 이유가 있는 명단에만 보냅니다.</p></div></div>
<div class="todo"><div class="no">3</div><div><h3>명단의 10%는 일부러 보내지 않습니다</h3><p>받은 사람과 안 받은 사람을 견주어야 문자 덕분에 산 사람을 셀 수 있습니다. 지금은 보내기 전 기간과 견주는 방법뿐이라, 받은 날이 겹치면 숫자가 섞입니다.</p></div></div>
<div class="src"><p>숫자 출처: 문자 발송 기록과 주문을 전화번호로 맞춘 추출 파일(10월 30일 오후 2시 19분). 입금확인 · 배송준비 · 배송완료 주문만, 환불은 뺐습니다.</p>
<p>「보내기 전 7일」은 그 문자를 받은 같은 사람들이 받기 전 7일에 낸 주문입니다. 적립금 사용액 · 14일 숫자는 사이트 적립금 원장(10월 27일).</p></div>''')

open(sys.argv[1], 'w').write(d.html('액상덕후 10월 CRM 성과', cov, p1, p2, p3))
