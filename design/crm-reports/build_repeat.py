#!/usr/bin/env python3
"""재구매 패턴 쉬운 보고서 — 표지 + 3장, CRM 보고서와 같은 옷.

숫자는 브리핑 API 의 `?part=repeat` (집계만 — 회원 번호 · 이름 · 연락처 없음).
  curl -sS -H "X-DHR-Key: $DUCKHOO_BRIEF_KEY" "https://duck-hoo.com/wp-json/duckhoo/v1/brief?part=repeat" > rep.json
쓰는 법: python3 design/crm-reports/build_repeat.py rep.json design/crm-reports/2026-10-repeat.html
"""
import json
import re
import sys
from pathlib import Path

SRC, OUT = sys.argv[1], sys.argv[2]
D = json.load(open(SRC, encoding='utf-8'))['repeat']
R, BUILT = D['r'], D['built']

# CRM 보고서(build.py)의 CSS 를 그대로 빌린다 — 그 파일은 실행하면 보고서를 쓰므로 CSS 부분만 떼어 돌린다
src = (Path(__file__).parent / 'build.py').read_text(encoding='utf-8')
BLUE, SKY, PALE, LINE = '#2F6FE0', '#9CC3FF', '#EEF4FF', '#C7DCFF'
ns = {'BLUE': BLUE, 'SKY': SKY, 'PALE': PALE, 'LINE': LINE}
exec(src[src.index('CSS = f"""'):src.index('def frame(')], ns)
CSS = ns['CSS'].replace('</style>', f'''
.bar.wide {{ grid-template-columns: 150pt 1fr 104pt; margin: 3pt 0; }} .bar .n small {{ font-weight: 600; color: #6B7280; font-size: 10pt; }}
.bar.dim .f {{ background: #E2E8F0; }} .bar .nm small {{ display: block; font-size: 9pt; color: #6B7280; line-height: 1.3; white-space: nowrap; }}
.nx td {{ font-size: 10.5pt; padding: 4pt 5pt; }}
.path {{ display: grid; grid-template-columns: 1fr 18pt 1fr 40pt; align-items: center; gap: 6pt; padding: 6pt 0; border-bottom: 1px solid #E5E9EE; font-size: 11.5pt; }}
.path .to {{ color: #94A3B8; text-align: center; }} .path .c {{ text-align: right; font-weight: 900; }}
.path .b {{ font-weight: 800; }}
</style>''')

NAMES = [  # 상품 줄 이름(브랜드가 떨어진 짧은 이름) → 읽기 쉬운 이름
    (r'크래프트 5병 EVENT\s*!', '크래프트 5병 (재입고 판 · 지금 없음)'),
    (r'크래프트 5병 EVENT', '크래프트 5병 (지금 없음)'),
    (r'^크래프트 포도', '크래프트 포도 (지금 없음)'),
    (r'특별 직원가 공개 ★ 빌런 5병', '빌런 5병 (옛 이름)'),
    (r'빌런 5병 EVENT', '빌런 5병'),
    (r'^3\+1 묶음', '{b} 3+1'),
    (r'^5\+5 묶음', '{b} 5+5'),
    (r'흑염룡 10병 할인 ! \(멘솔 없음\)', '흑염룡 10병 (멘솔 없음)'),
    (r'흑염룡 10병', '흑염룡 10병'),
    (r'^덕후 액상 10병', '덕후 액상 10병'),
    (r'리퀴드랩 액상 10병', '리퀴드랩 10병'),
    (r'화이트아웃 액상 10병', '화이트아웃 10병'),
    (r'얼려먹구싶오 5병', '얼려먹구싶오 5병 (무니코틴)'),
    (r'젤로 크리스탈 기기', '젤로 크리스탈 기기 + 5병'),
    (r'머스케또쨩', '머스케또쨩'),
    (r'소다쨩', '소다쨩'),
]


def nice(n):
    br, n = n.split(' · ', 1) if ' · ' in n else ('디오리퀴드', n)  # 상품 열쇠 「브랜드 · 짧은 이름」 (2026-10-29 부터)
    for pat, to in NAMES:
        if re.search(pat, n):
            return to.replace('{b}', br)
    return n.strip()


def w(n): return f'{n:,.0f}'
def pc(s): return int(str(s).rstrip('%') or 0)


P = R['packs']
pack5, pack1, pack10 = P['2~9병 묶음'], P['낱병'], P['10병 이상 묶음']
BR = {b: v for b, v in R['brands'].items() if v['buyers'] >= 40 and b != '기타'}
SW = R['second_within']


def bar(label, val, cap, note, on=False, sub='', mx=40):
    width = max(2, val / mx * 100)
    return (f'<div class="bar wide{" on" if on else ""}"><span class="nm">{label}{f"<small>{sub}</small>" if sub else ""}</span>'
            f'<span><span class="f" style="display:block;width:{width:.0f}%"></span></span>'
            f'<span class="n">{cap} <small>{note}</small></span></div>')


pack_bars = ''.join([
    bar('5병 · 3+1 묶음', pc(pack5['rate']), pack5['rate'], f'{w(pack5["again"])} / {w(pack5["first_n"])}명', True, f'다시 사기까지 {pack5["gap_median"]}일'),
    bar('낱병', pc(pack1['rate']), pack1['rate'], f'{w(pack1["again"])} / {w(pack1["first_n"])}명', False, f'{pack1["gap_median"]}일'),
    bar('10병 묶음', pc(pack10['rate']), pack10['rate'], f'{w(pack10["again"])} / {w(pack10["first_n"])}명', False, f'{pack10["gap_median"]}일'),
])

brand_rows = sorted(BR.items(), key=lambda kv: -pc(kv[1]['any_rate']))
brand_bars = ''.join(
    bar(b + (' (지금 없음)' if b == '크래프트' else ''), pc(v['any_rate']), v['any_rate'], f'{w(v["buyers"])}명 중',
        b in ('빌런', '디오리퀴드'), f'같은 브랜드 다시 {v["same_rate"]} · {v["gap_median"]}일 만에')
    for b, v in brand_rows)

next_rows = ''.join(
    f'<tr><td><b>{b}</b></td><td>{" · ".join(list(v["next_other"])[:2])}</td></tr>'
    for b, v in brand_rows[:6] if v['next_other'])

prods = sorted([kv for kv in R['products'].items() if kv[1]['buyers'] >= 12], key=lambda kv: -kv[1]['again'])
prod_rows = ''.join(
    f'<tr><td>{nice(p)}</td><td class="n">{w(v["buyers"])}</td><td class="n"><b>{w(v["again"])}</b></td><td class="n">{v["rate"]}</td><td class="n">{v["gap_median"] or "—"}일</td></tr>'
    for p, v in prods[:8])

paths = []
for k, c in R['pairs'].items():
    a, b = (nice(x) for x in k.split(' → '))
    if a.split(' (')[0] == b.split(' (')[0] and '멘솔' not in a + b:
        continue  # 같은 상품의 이름만 바뀐 것
    paths.append((a, b, c))
path_rows = ''.join(
    f'<div class="path"><span class="b">{a}</span><span class="to">→</span><span class="b">{b}</span><span class="c">{c}명</span></div>'
    for a, b, c in paths[:9])

when = ''.join(
    f'<div class="bar{" on" if d in ("30일 안", "60일 안") else ""}"><span>{d.replace(" 안", "")}</span>'
    f'<span><span class="f" style="display:block;width:{pc(v["of_repeaters"])}%"></span></span><span class="n">{v["of_repeaters"]}</span></div>'
    for d, v in SW.items())


C = R.get('check') or {}
CHECK = (f'<p>· 검수: 둘째 주문이 첫 주문 뒤 24시간 안인 사람 {C["same_day"]}명은 나눠 결제했거나 두 번 넣은 주문일 수 있습니다 '
         f'(빼면 재구매율 {C["repeat_rate_no_same_day"]}, 「7일 안」 막대도 그만큼 낮아집니다). 노보만 사러 돌아온 {C["novo_only_back"]}명을 넣으면 {C["repeat_rate_with_novo"]}입니다. '
         f'「고른 길」의 숫자는 사람 수입니다.</p>') if C else ''


def frame(no, title, body):
    return f'''<section class="page">
  <div class="run"><span>재구매 패턴 · 노보 제외</span><span>{no} · {title}</span></div>
{body}
  <div class="foot"><span>재구매 패턴 · 노보 제외</span><span><b>{no}</b> / 3</span></div>
</section>'''


cover = f'''<section class="page cover">
  <div class="eb">액상덕후 · 재구매 패턴 (노보 제외)</div>
  <div class="big">{pack5["rate"]}</div>
  <h1>5병 묶음 손님이<br>가장 잘 돌아옵니다</h1>
  <p class="lead">처음에 5병 · 3+1 묶음을 산 손님 {w(pack5["first_n"])}명 중 {w(pack5["again"])}명이 다시 샀습니다.
  10병 묶음 손님은 {pack10["rate"]}, 낱병 손님은 {pack1["rate"]}입니다.</p>
  <div class="facts">
    <div class="fact"><div class="v">{R["repeat_rate"]}</div><div class="l">노보 말고 산 회원 {w(R["customers"])}명 중<br>한 번 더 산 사람 {w(R["repeaters"])}명</div></div>
    <div class="fact"><div class="v">{R["second_gap_median"]}일</div><div class="l">처음 산 뒤<br>다시 사기까지 (가운데값)</div></div>
    <div class="fact"><div class="v">{SW["60일 안"]["of_repeaters"]}</div><div class="l">다시 산 사람 중<br>60일 안에 돌아온 비율</div></div>
  </div>
  <div class="words"><div class="t">이 보고서에서 쓰는 말</div>
    <p><b>재구매율</b> · 처음 산 손님 중 그 뒤에 한 번이라도 더 산 사람의 비율</p>
    <p><b>다시 사기까지</b> · 첫 주문과 둘째 주문 사이 날수의 가운데값</p>
    <p><b>노보 제외</b> · 노보 상품 줄은 빼고 셉니다. 노보만 산 회원은 들어가지 않습니다</p></div>
  <div class="meta"><span><b>쓰는 곳</b> 다음 CRM 명단 · 문구</span><span><b>작성</b> 온라인몰 운영</span><span><b>{BUILT}</b> 집계 · 돈 들어온 회원 주문 전체</span></div>
</section>'''

p1 = frame(1, '무엇을 산 손님이 돌아오나', f'''  <div class="eb">1 · 무엇을 산 손님이 돌아오나</div>
  <h2>처음에 산 묶음 크기가<br>재구매를 가릅니다</h2>
  <p class="lead">처음 주문의 가장 큰 묶음으로 나눴습니다. 막대 길이가 재구매율입니다.</p>
  <div class="bars">{pack_bars}</div>
  <h3>브랜드별 · 처음 산 뒤 무엇이든 다시 산 비율</h3>
  <p class="g" style="font-size:10.5pt;margin:0 0 4pt">40명 넘게 산 브랜드만. 작은 글씨는 같은 브랜드를 다시 산 비율과 걸린 날수입니다.</p>
  <div class="bars">{brand_bars}</div>
  <div class="box"><div class="t">읽는 법</div>
    <p>· <b>빌런 · 디오리퀴드 손님이 가장 잘 돌아옵니다</b> (34% · 24%). 크래프트(35%)는 상품이 없어졌습니다.</p>
    <p>· <b>액상덕후는 손님이 가장 많지만 같은 브랜드를 다시 사는 비율이 8%</b>입니다. 다시 와도 다른 브랜드를 삽니다.</p></div>''')

p2 = frame(2, '다시 살 때 무엇을 사나', f'''  <div class="eb">2 · 다시 살 때 무엇을 사나</div>
  <h2>다시 산 손님이 고른 길</h2>
  <p class="lead">앞 주문에 없던 상품을 다음 주문에서 산 경우입니다. 같은 상품의 이름만 바뀐 줄은 뺐습니다.</p>
  <div style="margin:0 0 14pt">{path_rows}</div>
  <div class="cols">
    <div><h3>재구매가 많이 일어난 상품</h3>
      <table class="dn"><tr><th>상품</th><th class="n">산</th><th class="n">다시</th><th class="n">비율</th><th class="n">간격</th></tr>{prod_rows}</table></div>
    <div><h3>브랜드 손님이 다음에 가는 곳</h3>
      <table class="dn nx"><tr><th>처음</th><th>다음에 많이 산 브랜드</th></tr>{next_rows}</table></div>
  </div>''')

p3 = frame(3, '다음 CRM에 쓸 것', f'''  <div class="eb">3 · 다음 CRM에 쓸 것</div>
  <h2>언제 · 누구에게 · 무엇을</h2>
  <div class="cols" style="grid-template-columns:1fr 1fr;margin-bottom:6pt">
    <div><h3>다시 산 사람이 돌아온 때</h3><div class="bars">{when}</div></div>
    <div class="box" style="align-self:start"><div class="t">60일이 고비입니다</div>
      <p>다시 산 사람의 74%가 60일 안에 돌아왔습니다. 60일이 지나면 돌아올 사람이 크게 줄어듭니다.</p>
      <p>지금 마지막 주문이 30~120일 전인 회원은 <b>{w(R["lapsed_30_120"]["n"])}명</b>입니다 (마지막 주문 평균 {w(R["lapsed_30_120"]["last_aov"])}원).</p></div>
  </div>
  <div class="todo"><span class="no">1</span><div><h3>5병 · 3+1 묶음 손님 · 25~30일째</h3><p>같은 묶음의 다른 맛을 권합니다. 가장 잘 돌아오는 손님이고, 보통 35일 만에 다시 삽니다.</p></div></div>
  <div class="todo"><span class="no">2</span><div><h3>크래프트를 샀던 손님 · 빌런 5병 · 디오리퀴드 3+1</h3><p>크래프트가 없어진 뒤 실제로 가장 많이 옮겨 간 곳이 이 둘입니다.</p></div></div>
  <div class="todo"><span class="no">3</span><div><h3>흑염룡 10병 손님 · 「멘솔 없음」 판</h3><p>같은 상품의 다른 판으로 옮겨 가는 길이 따로 보입니다. 디오리퀴드 3+1 손님에게는 5+5를 권합니다.</p></div></div>
  <div class="todo"><span class="no">4</span><div><h3>액상덕후 10병 손님 · 45일 뒤 · 다른 브랜드 5병</h3><p>10병 손님은 49일 만에 돌아오고, 같은 상품은 7%만 다시 삽니다. 일찍 보내지 않습니다.</p></div></div>
  <div class="todo"><span class="no">5</span><div><h3>쉬는 손님 · 60~90일 안에 한 번</h3><p>명단: 마케팅 → 고객 세그먼트 → 「다시 살 때가 된 손님」 (브랜드별 칩 · 쉬는 손님 칩).</p></div></div>
  <div class="src"><p><b>숫자 출처 · 주의</b></p>
    <p>· {BUILT} 집계. 돈 들어온 회원 주문만 셉니다 (취소 · 입금전 · 비회원 제외). 노보 상품 줄은 뺐습니다.</p>
    {CHECK}
    <p>· 「3+1 · 5+5 묶음」은 이름에 브랜드가 없어 디오리퀴드 묶음으로 봤습니다. 산 사람이 열 명 안팎인 상품의 비율(하이민트 33% 등)은 믿기 어려워 표에서 뺐습니다.</p></div>''')

html = f'''<!doctype html><html lang="ko"><head><meta charset="utf-8"><title>재구매 패턴</title>
<link rel="stylesheet" href="../demo-src/pretendard.css">{CSS}</head><body>
{cover}{p1}{p2}{p3}
</body></html>'''
open(OUT, 'w', encoding='utf-8').write(html)
print('ok', len(paths), len(brand_rows))
