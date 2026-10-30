"""9월 → 10월 재구매 쉬운 보고서. 숫자: 「9월 10월 상품별 재구매율 분석」 엑셀 · 보고서(10/30 14:28 추출, 두 달 모두 1~29일, 워드프레스 주문).
python3 build_oct_repeat.py 2026-10-repeat-month.html"""
import sys
sys.path.insert(0, '../reports-lib')
from easy import *

SEP_C, SAME, OTHER = 741, 63, 59
RATE = (13.4, 14.7, 956, 128, 1590, 233)   # 이전에 산 손님 중 그달에 산 비율 9월 · 10월, 분모 · 분자
TOP = [('디오리퀴드 3+1', 58, 9), ('빌런 5병', 52, 6), ('흑염룡 10병', 62, 6), ('덕후 액상 10병', 159, 9),
       ('디오리퀴드 5+5', 37, 2), ('소다쨩 낱병', 17, 1), ('리퀴드랩 상어바', 6, 1), ('개꿀포도', 8, 1), ('빌런 자두 낱병', 8, 1)]
ZERO = [('머스케또쨩 낱병', 27), ('스모모쨩 낱병', 20), ('화이트아웃 10병', 17), ('레몬쨩 낱병', 16),
        ('화이트아웃 2+3', 16), ('쟈쿠로쨩 낱병', 13), ('포도 덕후 PICK 5병', 11)]
QTY = [('노보 타박멘솔', 192, 193), ('노보 블랙멘솔', 61, 151), ('노보 엠에스블랜드', 4, 102), ('노보 아메리카노', 0, 100),
       ('덕후 액상 10병 묶음', 159, 84), ('노보 데저트', 4, 66), ('젤로 크리스탈 기기 + 5병', 58, 63),
       ('노보 블랙 쿠바시가', 7, 61), ('디오리퀴드 3+1', 61, 53), ('노보 블랙 블랙멘솔', 42, 52)]
BOTH = (779, 639, 741, 591, 1380, 1675)
NEWP = 46
back = SAME + OTHER
d = Doc('액상덕후 재구매 9월 → 10월', 3)

one_in = round(SEP_C / back)
cov = cover('액상덕후 · 재구매 · 9월 손님이 10월에 다시 왔나', f'1<small> / {one_in}명</small>',
    f'9월에 산 손님 {one_in}명 중 1명이<br>10월에 다시 샀습니다',
    f'9월 손님 {SEP_C}명 중 {back}명({back / SEP_C * 100:.1f}%)이 10월에 다시 샀습니다. 그중 절반은 같은 상품, 절반은 다른 상품을 골랐습니다.',
    [(f'{SAME}명', '같은 상품을 다시 삼'), (f'{OTHER}명', '다른 상품을 삼'), (f'{SEP_C - back}명', '10월에는 안 삼')],
    '<span>보고 대상 <b>대표님 · 이사님</b></span><span>작성 <b>온라인몰 운영</b></span><span>2026년 10월 30일</span>')

CSS = """<style>
.dots { display: grid; grid-template-columns: repeat(20, 1fr); gap: 4pt; margin: 8pt 0 6pt; }
.dots i { aspect-ratio: 1; border-radius: 50%; background: #E2E8F0; }
.dots i.s { background: #2F6FE0; } .dots i.o { background: #9CC3FF; }
.lg { display: flex; gap: 16pt; font-size: 11pt; margin: 4pt 0 14pt; } .lg b { font-weight: 900; }
.lg span::before { content: ''; display: inline-block; width: 9pt; height: 9pt; border-radius: 50%; margin-right: 5pt; vertical-align: -1pt; background: var(--c); }
.say { font-size: 12.5pt; margin: 0 0 10pt; padding-left: 0; list-style: none; }
.say li { padding: 8pt 0; border-top: 1px solid #EEF2F7; } .say li:first-child { border-top: 0; }
.say li b { font-weight: 900; }
.hb { margin: 6pt 0 12pt; } .hr { display: grid; grid-template-columns: 110pt 1fr 92pt; gap: 8pt; align-items: center; margin: 7pt 0; font-size: 11.5pt; }
.hr .f { height: 16pt; background: #F1F5F9; border-radius: 4pt; overflow: hidden; } .hr .f i { display: block; height: 100%; background: #2F6FE0; border-radius: 4pt; }
.hr .n { font-weight: 900; text-align: right; } .hr .n small { display: block; font-weight: 400; font-size: 9pt; color: #6B7280; }
.chips { display: flex; flex-wrap: wrap; gap: 6pt; margin: 4pt 0 10pt; } .chips span { background: #FEF3F2; color: #B42318; border-radius: 99pt; padding: 3pt 10pt; font-size: 10.5pt; font-weight: 700; }
.mv { display: grid; grid-template-columns: 1fr 1fr; gap: 6pt 18pt; margin: 6pt 0 12pt; }
.mv div { display: flex; justify-content: space-between; align-items: baseline; border-bottom: 1px solid #EEF2F7; padding: 5pt 0; font-size: 11pt; }
.mv b { font-weight: 900; } .mv em { font-style: normal; font-size: 10pt; margin-left: 6pt; }
</style>"""
ns, no = round(SAME / SEP_C * 100), round(OTHER / SEP_C * 100)
dots = '<div class="dots">' + '<i class="s"></i>' * ns + '<i class="o"></i>' * no + '<i></i>' * (100 - ns - no) + '</div>'
p1 = d.frame(1, '한눈에', f'''{CSS}<h2>9월 손님 100명 중 {ns + no}명이 10월에 다시 왔습니다</h2>
<p class="lead">점 하나가 9월 손님 1%입니다 (9월 1~29일에 산 {SEP_C}명).</p>
{dots}
<div class="lg"><span style="--c:#2F6FE0">같은 상품을 다시 삼 <b>{SAME}명</b></span><span style="--c:#9CC3FF">다른 상품을 삼 <b>{OTHER}명</b></span><span style="--c:#E2E8F0">안 삼 <b>{SEP_C - back}명</b></span></div>
<h3>세 줄로 보면</h3>
<ul class="say">
<li><b>다시 온 손님의 절반은 다른 상품을 골랐습니다.</b> 같은 상품만 다시 권하면 절반을 놓칩니다.</li>
<li><b>10월은 손님이 줄었는데(741명 → 591명), 산 적 있는 손님은 더 자주 왔습니다.</b> 예전 손님 중 그달에 산 사람이 13.4% → 14.7%로 늘었습니다.</li>
<li><b>멘솔쨩 낱병 손님은 같은 맛을 한 명도 다시 사지 않았습니다.</b> 머스케또쨩 27명 · 스모모쨩 20명 · 레몬쨩 16명.</li>
</ul>''')

def hrow(n, a, b): return f'<div class="hr"><span>{n}</span><span class="f"><i style="width:{b / a * 100 / 16 * 100:.0f}%"></i></span><span class="n">{b / a * 100:.0f}%<small>{a}명 중 {b}명</small></span></div>'
small = sorted(TOP[5:], key=lambda t: -t[2] / t[1])
p2 = d.frame(2, '상품별', f'''<h2>같은 상품을 다시 산 손님은 묶음에서 많았습니다</h2>
<p class="lead">9월에 그 상품을 산 손님 중 10월에 같은 상품을 또 산 비율입니다. 막대가 길수록 잘 돌아오는 상품입니다.</p>
<div class="hb">{"".join(hrow(n, a, b) for n, a, b in TOP[:5])}</div>
<p class="note" style="margin:-4pt 0 14pt">덕후 액상 10병은 다시 산 사람(9명)이 디오리퀴드 3+1과 같지만 9월 손님이 159명으로 많아 비율이 낮습니다. 9월 손님이 20명 아래인 상품({" · ".join(n for n, _, _ in small)})은 한두 명이라 넣지 않았습니다. 노보는 단종 소식으로 흐름이 달라 뺐습니다.</p>
<h3>9월 손님이 한 명도 다시 사지 않은 상품</h3>
<div class="chips">{"".join(f'<span>{n} · {a}명 중 0명</span>' for n, a in ZERO)}</div>
<p class="note">같은 묶음 안에서 맛만 바꿔 산 것도 「같은 상품」으로 셉니다.</p>''')

def mv(n, a, b):
    c = 'up' if b >= a else 'dn'
    return f'<div><span>{n}</span><span>{w(a)} → <b>{w(b)}</b><em class="{c}">{b - a:+d}</em></span></div>'
p3 = d.frame(3, '수량과 11월', f'''<h2>노보 낱병이 늘고, 덕후 10병은 반으로 줄었습니다</h2>
<p class="lead">10월에 많이 팔린 상품 10개의 판매 수량입니다 (9월 → 10월). 10월에 처음 팔린 상품도 {NEWP}가지입니다 (대부분 리퀴드랩 새 맛).</p>
<div class="mv">{"".join(mv(n, a, b) for n, a, b in QTY)}</div>
<h3>11월에 할 일</h3>
<div class="todo"><div class="no">1</div><div><h3>잘 돌아오는 묶음 손님에게 다시 살 때 알리기</h3><p>디오리퀴드 3+1 · 빌런 5병 · 흑염룡 10병. 산 지 한 달쯤 된 손님에게 같은 묶음과 비슷한 맛을 함께 권합니다.</p></div></div>
<div class="todo"><div class="no">2</div><div><h3>낱병 손님에게 10병 묶음 권하기</h3><p>멘솔쨩 낱병 손님이 다시 오지 않았습니다. 다음 주문에서 같은 맛을 묶음으로 담게 안내합니다.</p></div></div>
<div class="todo"><div class="no">3</div><div><h3>노보 손님이 옮겨 갈 자리 만들기</h3><p>노보 재고가 끝나면 노보만 사던 손님이 빠집니다. 맛이 가까운 입호흡 액상을 상품 화면과 문자에서 먼저 보여 줍니다.</p></div></div>
<p class="note">숫자 출처: 워드프레스 주문(10월 30일 오후 2시 28분 추출, 두 달 모두 1~29일). 옛 사이트(아임웹) 주문은 넣지 않았습니다.</p>''')

open(sys.argv[1], 'w').write(d.html('액상덕후 재구매 9월 → 10월', cov, p1, p2, p3))
