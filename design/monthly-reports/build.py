#!/usr/bin/env python3
"""월말 매출 보고서(HTML, A4 두 장) — 브리핑 API 의 `monthly` 블록 하나로 찍는다.

사용법:
  curl -sS https://duck-hoo.com/wp-json/duckhoo/v1/brief -H "X-DHR-Key: $DUCKHOO_BRIEF_KEY" > /tmp/brief.json
  python3 design/monthly-reports/build.py /tmp/brief.json design/monthly-reports/2026-09-monthly.html \
      --boxes 828 --unit 2150 --prev-sales 19822458 --prev-orders 376 --prev-aov 52719 \
      --manual "노보 타박멘솔2000=18000000" --asof "9월 1일 ~ 29일 기준 (월중 잠정)"
  node scratchpad/pdf.mjs <html 절대경로> <pdf 절대경로>

원칙 (사장님 2026-09-29): 8월 보고서 꼴을 따르되 더 간소하게, 읽고 보기 편한 것이 우선.
표 위주 · 글은 각주 한두 줄 · 없는 숫자는 「자료 대기」 「집계 전」 으로 비운다 · 큰 주문(50만원↑)은 「제외」 값을 나란히.
대표님 · 이사님 제출용: 사장님 · 클로드 · 관리자 도구 이름 · 디스코드를 적지 않는다.
"""
import argparse, json, datetime, html

ap = argparse.ArgumentParser()
ap.add_argument('src'); ap.add_argument('out')
ap.add_argument('--boxes', type=int, default=0, help='우체국 발송 상자 수 (액상덕후 몫)')
ap.add_argument('--unit', type=int, default=2150, help='우체국 계약 단가')
ap.add_argument('--returns', type=int, default=0); ap.add_argument('--waiting', type=int, default=0, help='출고 대기 건수')
ap.add_argument('--prev-sales', type=float, default=0); ap.add_argument('--prev-orders', type=int, default=0); ap.add_argument('--prev-aov', type=float, default=0)
ap.add_argument('--prev-note', default='전월비는 8월 보고서의 자사몰 값 기준입니다.')
ap.add_argument('--manual', action='append', default=[], help='"이름 조각=원가" — 원가 모름 줄에 손으로 원가를 붙인다 (수동 결제 등)')
ap.add_argument('--asof', default=''); ap.add_argument('--date', default='')
ap.add_argument('--imweb', default='집계 전')
a = ap.parse_args()

d = json.load(open(a.src, encoding='utf-8'))
m = d['monthly']
ym = m['ym']; y, mo = int(ym[:4]), int(ym[5:7])
today = a.date or d.get('date', datetime.date.today().isoformat())
fmt = lambda n: f"{int(round(n)):,}"
man = lambda n: f"{int(round(n/10000)):,}만원"
pct = lambda x, y: (f"{(x/y-1)*100:+.1f}%" if y else '')
def arrow(x, y):
    if not y: return ''
    v = (x/y-1)*100
    cls = 'up' if v >= 0 else 'dn'
    return f'<span class="{cls}">{"▲" if v>=0 else "▼"} {abs(v):.1f}%</span> 전월비'

sales = m['sales']; orders = m['orders']; aov = m['aov']
spend = m['spend'] or (a.boxes * a.unit)
boxes = m['boxes'] or a.boxes
cost = m['cost'] or 0.0; unknown = m['unknown'] or 0.0
ulist = dict(m.get('unknown_list') or {})

# 손으로 붙인 원가 (원가 모름 줄 중에서)
manual_cost = 0.0; manual_rows = []
for spec in a.manual:
    key, val = spec.rsplit('=', 1); val = float(val.replace(',', ''))
    hit = [k for k in ulist if key in k]
    if hit:
        k = hit[0]; manual_cost += val; manual_rows.append((k, ulist[k], val)); unknown -= ulist[k]; ulist.pop(k)
cost_all = cost + manual_cost
gross = sales - cost_all
profit = gross - spend
known_sales = sales - unknown

# 큰 주문
big = m.get('big') or []
big_total = sum(b['t'] for b in big)
big_cost = sum(v for k, s_, v in manual_rows if any(k in ln['name'] for b in big for ln in b['lines']))
ex_sales = sales - big_total
ex_orders = orders - len(big)
ex_aov = ex_sales / ex_orders if ex_orders else 0
ex_profit = profit - (big_total - big_cost) if big else profit

days = m['days_done']
daily = m['daily']
# 막대: 큰 주문 날은 최대치로 자르고 빗금
vals = {k: v['sales'] for k, v in daily.items() if k <= f"{ym}-{days:02d}"}
big_days = {b['d'] for b in big}
mx = max([v for k, v in vals.items() if k not in big_days] or [1])
bars = ''
for k, v in vals.items():
    dt = datetime.date.fromisoformat(k); wk = dt.weekday() >= 5
    cls = ' '.join(filter(None, ['g' if wk else '', 'cap' if k in big_days and v > mx else '']))
    h = min(100, round(v / mx * 100)) if mx else 0
    bars += f'<i class="{cls}" style="height:{h}%" title="{dt.month}/{dt.day} {fmt(v)}"></i>'
best = max(((k, v) for k, v in vals.items() if k not in big_days), key=lambda x: x[1]) if vals else ('', 0)
first_k = min(vals) if vals else ''; last_k = max(vals) if vals else ''
kd = lambda k: f"{int(k[5:7])}/{int(k[8:10])}"

prods = list(m['products'].items())[:8]
top = sum(p['sales'] for _, p in prods)
rows = ''.join(f'<tr><td class="num mut">{i+1}</td><td>{html.escape(n)}</td><td class="num">{p["n"]}</td><td class="num">{fmt(p["sales"])}</td><td class="num mut">{p["sales"]/sales*100:.1f}%</td></tr>' for i, (n, p) in enumerate(prods))
brands = list(m['brands'].items())[:4]
brand_txt = ' · '.join(f"{b} {man(v)}({v/sales*100:.0f}%)" for b, v in brands)

cu = m['cust']; pv = m['prev']
unknown_txt = ' · '.join(f"{html.escape(k)} {fmt(v)}" for k, v in list(ulist.items())[:4])
manual_txt = ' · '.join(f"{html.escape(k)} → 원가 {fmt(v)}" for k, s_, v in manual_rows)
kmon = f"{mo}월"
asof = a.asof or (f"{kmon} 1일 ~ {days}일 기준" + ('' if m['closed'] else ' (월중 잠정)'))
dt_today = datetime.date.fromisoformat(today)

page = f'''<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<title>액상덕후 {kmon} 매출 보고</title>
<link rel="stylesheet" href="../demo-src/pretendard.css">
<style>
  @page {{ size: A4; margin: 14mm 13mm 14mm 13mm; }}
  * {{ box-sizing: border-box; }}
  html, body {{ margin: 0; padding: 0; }}
  body {{ font-family: 'Pretendard Variable', Pretendard, -apple-system, 'Apple SD Gothic Neo', 'Malgun Gothic', sans-serif; color: #111; font-size: 10pt; line-height: 1.5; word-break: keep-all; -webkit-print-color-adjust: exact; print-color-adjust: exact; }}
  .page {{ page-break-after: always; }} .page:last-child {{ page-break-after: auto; }}
  .eb {{ font-size: 8.5pt; letter-spacing: .14em; color: #8A8A8F; text-transform: uppercase; }}
  h1 {{ font-size: 22pt; font-weight: 800; margin: 2pt 0 2pt; }}
  .sub {{ color: #4E565F; font-size: 9.5pt; margin-bottom: 10pt; }}
  h2 {{ font-size: 12pt; font-weight: 800; margin: 11pt 0 4pt; padding-bottom: 3pt; border-bottom: 2px solid #111; }}
  .kpis {{ display: grid; grid-template-columns: repeat(4, 1fr); gap: 8pt; margin: 4pt 0 6pt; }}
  .kpi {{ border: 1px solid #D5D9DD; border-radius: 6pt; padding: 8pt 10pt; }}
  .kpi.hi {{ border-color: #111; }}
  .kpi .l {{ font-size: 8.5pt; color: #4E565F; }}
  .kpi .v {{ font-size: 17pt; font-weight: 800; line-height: 1.25; font-variant-numeric: tabular-nums; }}
  .kpi .n {{ font-size: 8.5pt; color: #4E565F; }}
  .up {{ color: #0F7A3D; font-weight: 700; }} .dn {{ color: #B42318; font-weight: 700; }}
  table {{ width: 100%; border-collapse: collapse; font-size: 9.5pt; margin: 2pt 0 4pt; }}
  th {{ text-align: left; font-weight: 700; color: #4E565F; font-size: 8.5pt; background: #F4F6F7; padding: 4pt 6pt; border-bottom: 1px solid #D5D9DD; }}
  td {{ padding: 3.5pt 6pt; border-bottom: 1px solid #ECEEF0; vertical-align: top; }}
  td.num, th.num {{ text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }}
  tr.sum td {{ font-weight: 800; background: #F9FAFB; border-top: 1.5px solid #111; }}
  tr.sub td:first-child {{ padding-left: 16pt; }}
  .mut {{ color: #4E565F; }} .neg {{ color: #B42318; }}
  .note {{ font-size: 8.5pt; color: #4E565F; margin: 0 0 6pt; }}
  .two {{ display: grid; grid-template-columns: 1fr 1fr; gap: 14pt; }}
  .bars {{ display: flex; align-items: flex-end; gap: 2pt; height: 46pt; margin: 3pt 0 1pt; }}
  .bars i {{ flex: 1 1 0; background: #222; border-radius: 1.5pt 1.5pt 0 0; display: block; }}
  .bars i.g {{ background: #C9CED3; }}
  .bars i.cap {{ background: repeating-linear-gradient(135deg,#222 0 3pt,#9AA0A6 3pt 6pt); }}
  .axis {{ display: flex; justify-content: space-between; font-size: 8pt; color: #4E565F; }}
  .foot {{ font-size: 8pt; color: #8A8A8F; border-top: 1px solid #D5D9DD; padding-top: 5pt; margin-top: 10pt; }}
  .kv td:first-child {{ color: #4E565F; }}
</style>
</head>
<body>

<section class="page">
  <div class="eb">액상덕후 · Liquid Deokhu</div>
  <h1>{kmon} 매출 보고</h1>
  <div class="sub">{asof} · 작성 온라인몰 운영 · {dt_today.year}년 {dt_today.month}월 {dt_today.day}일</div>

  <div class="kpis">
    <div class="kpi"><div class="l">총 매출 (실입금)</div><div class="v">{fmt(sales)}</div><div class="n">{arrow(sales, a.prev_sales)}</div></div>
    <div class="kpi hi"><div class="l">순이익</div><div class="v">{fmt(profit)}</div><div class="n">순이익률 {profit/sales*100:.1f}%</div></div>
    <div class="kpi"><div class="l">주문 건수</div><div class="v">{orders}</div><div class="n">{arrow(orders, a.prev_orders)}</div></div>
    <div class="kpi"><div class="l">객단가</div><div class="v">{fmt(aov)}</div><div class="n">{arrow(aov, a.prev_aov)}{' · 큰 주문 제외 ' + fmt(ex_aov) if big else ''}</div></div>
  </div>
  {"<p class='note'>" + f"{kd(big[0]['d'])} 대량 주문 {len(big)}건({man(big_total)})이 매출의 {big_total/sales*100:.0f}%입니다. 빼면 매출 {man(ex_sales)}(전월비 {pct(ex_sales, a.prev_sales)}) · 순이익 {man(ex_profit)} · 객단가 {fmt(ex_aov)}원입니다. " + a.prev_note + "</p>" if big else "<p class='note'>" + a.prev_note + "</p>"}

  <h2>손익</h2>
  <table>
    <tbody>
      <tr><td>할인 전 주문 금액</td><td class="num">{fmt(m['before'])}</td><td class="num mut">100.0%</td></tr>
      <tr class="sub"><td>금액 자동 할인</td><td class="num neg">−{fmt(m['fee'])}</td><td class="num mut">{m['fee']/m['before']*100:.1f}%</td></tr>
      <tr class="sub"><td>적립금 사용</td><td class="num neg">−{fmt(m['points'])}</td><td class="num mut">{m['points']/m['before']*100:.1f}%</td></tr>
      <tr class="sub"><td>쿠폰 할인</td><td class="num neg">−{fmt(m['coupon'])}</td><td class="num mut">{m['coupon']/m['before']*100:.1f}%</td></tr>
      <tr class="sum"><td>매출 (손님이 실제로 입금한 돈)</td><td class="num">{fmt(sales)}</td><td class="num mut">{sales/m['before']*100:.1f}%</td></tr>
      <tr class="sub"><td>상품 원가</td><td class="num neg">−{fmt(cost_all)}</td><td class="num mut">매출의 {cost_all/sales*100:.1f}%</td></tr>
      <tr class="sum"><td>매출총이익</td><td class="num">{fmt(gross)}</td><td class="num mut">{gross/sales*100:.1f}%</td></tr>
      <tr class="sub"><td>배송비 (우체국 계약소포 {boxes:,}상자 × {fmt(a.unit)}원)</td><td class="num neg">−{fmt(spend)}</td><td class="num mut">{spend/sales*100:.1f}%</td></tr>
      <tr class="sum"><td>순이익</td><td class="num">{fmt(profit)}</td><td class="num mut">{profit/sales*100:.1f}%</td></tr>
    </tbody>
  </table>
  <p class="note">매출은 입금이 확인된 주문 기준이며 미입금 · 취소 건은 제외돼 있습니다. 상품 원가는 상품별 매입 단가 × 수량입니다{(' — 원가를 모르는 매출 ' + fmt(unknown) + '원(' + unknown_txt + ')은 원가 0으로 두었으므로 순이익이 그만큼 높게 잡혀 있습니다') if unknown > 0 else ''}.{(' 수동 결제 줄은 따로 붙였습니다: ' + manual_txt + '.') if manual_rows else ''} 박스 · 완충재 같은 포장 자재와 문자 발송비는 넣지 않았습니다.</p>

  <h2>일별 매출 추이</h2>
  <div class="bars">{bars}</div>
  <div class="axis"><span>{kd(first_k)}</span><span>{'빗금 = 대량 주문 (막대 최대치로 자름) · ' if big else ''}최고 {fmt(best[1])} ({kd(best[0])})</span><span>{kd(last_k)}</span></div>
  <p class="note">회색 막대는 주말입니다.</p>
</section>

<section class="page">
  <h2>상위 판매 상품</h2>
  <table>
    <thead><tr><th class="num">#</th><th>상품</th><th class="num">건수</th><th class="num">매출</th><th class="num">비중</th></tr></thead>
    <tbody>{rows}</tbody>
  </table>
  <p class="note">상위 {len(prods)}개 합계 {man(top)} · 전체 매출의 {top/sales*100:.1f}%. 브랜드별: {brand_txt}.</p>

  <div class="two">
    <div>
      <h2>배송</h2>
      <table class="kv"><tbody>
        <tr><td>{kmon} 발송 (우체국 접수)</td><td class="num">{boxes:,}상자 · {fmt(spend)}원</td></tr>
        <tr><td>출고 대기</td><td class="num">{a.waiting}건 (다음 달 반영)</td></tr>
        <tr><td>반품</td><td class="num">{a.returns}건</td></tr>
        <tr><td>매출 대비 배송비</td><td class="num">{spend/sales*100:.1f}%</td></tr>
        <tr><td>상자당 매출</td><td class="num">{fmt(sales/boxes) if boxes else '—'}</td></tr>
      </tbody></table>
      <p class="note">상자당 {fmt(a.unit)}원은 우체국 소포정산내역에서 확인한 계약 단가입니다.</p>
    </div>
    <div>
      <h2>손님</h2>
      <table class="kv"><tbody>
        <tr><td>이 달 처음 산 회원</td><td class="num">{cu['first_buyers']:,}명</td></tr>
        <tr><td>전에도 산 회원</td><td class="num">{cu['rep_buyers']:,}명 · 재구매 매출 {cu['rep_sales']/sales*100:.0f}%</td></tr>
        <tr><td>새 가입 (자사몰)</td><td class="num">{m['signups']:,}명 (전월 {m['signups_prev']:,}명)</td></tr>
        <tr><td>취소 · 환불</td><td class="num">{m['void_n']}건 · 접수의 {m['cancel_rate']}% (전월 {pv['cancel_rate']}%)</td></tr>
        <tr><td>입금 대기</td><td class="num">{m['pend_n']}건 · {fmt(m['pend'])}원</td></tr>
        <tr><td>아임웹 채널</td><td class="num">{a.imweb}</td></tr>
      </tbody></table>
    </div>
  </div>

  <div class="foot">액상덕후 {ym} 월말보고 · {dt_today.year}년 {dt_today.month}월 {dt_today.day}일 생성 · 자사몰(WooCommerce)은 입금확인 이후 상태 기준, 취소 · 미입금 제외 · 아임웹은 API 연결 뒤 합산 · 집계 {m.get('built','')}</div>
</section>
</body>
</html>
'''
open(a.out, 'w', encoding='utf-8').write(page)
print(f"wrote {a.out}: sales {fmt(sales)} · cost {fmt(cost_all)} (unknown {fmt(unknown)}) · spend {fmt(spend)} · profit {fmt(profit)} ({profit/sales*100:.1f}%)")
