#!/usr/bin/env python3
"""월말 손익계산서 판 — 매출액 → 순매출 → 매출총이익 → 공헌이익 → 영업이익.

`build.py` 와 같은 입력(브리핑 API `monthly` 블록 + 손으로 넣는 값)으로 A4 두 장.
build.py 가 「보기 편한 표」라면 이것은 관리회계 꼴이다 — 변동비 · 고정비를 나누고,
모르는 칸은 「자료 대기」로 비워 둔다 (숫자를 만들지 않는다).

    python3 build_pl.py brief.json out.html --boxes 850 --unit 2500 \
        --manual "노보 타박멘솔2000=18000000" --imweb-n 38 --imweb-sales 2579400 ... \
        --var "PG 수수료=?" --var "문자 발송비=?" --fixed "인건비=?" --fixed "임차료=?"

`--var` / `--fixed` 는 "이름=금액" — 금액이 `?` 면 자료 대기(0 으로 셈, 표에 그렇게 적음).
"""
import argparse, datetime, html, json, re

ap = argparse.ArgumentParser()
ap.add_argument('src'); ap.add_argument('out')
ap.add_argument('--boxes', type=int, default=0); ap.add_argument('--unit', type=int, default=2150)
ap.add_argument('--prev-sales', type=float, default=0); ap.add_argument('--prev-orders', type=int, default=0)
ap.add_argument('--prev-note', default='')
ap.add_argument('--manual', action='append', default=[])
ap.add_argument('--asof', default=''); ap.add_argument('--date', default='')
ap.add_argument('--imweb-n', type=int, default=0); ap.add_argument('--imweb-sales', type=float, default=0)
ap.add_argument('--imweb-cost', type=float, default=-1); ap.add_argument('--imweb-unknown', type=float, default=0)
ap.add_argument('--imweb-gross', type=float, default=0)
ap.add_argument('--big-min', type=float, default=5000000)
ap.add_argument('--var', action='append', default=[], help='변동비 "이름=금액" (금액 ? = 자료 대기)')
ap.add_argument('--fixed', action='append', default=[], help='고정비 "이름=금액" (금액 ? = 자료 대기)')
a = ap.parse_args()

d = json.load(open(a.src, encoding='utf-8'))
m = d['monthly']
ym = m['ym']; y, mo = int(ym[:4]), int(ym[5:7])
today = a.date or d.get('date', datetime.date.today().isoformat())
dt_today = datetime.date.fromisoformat(today)
kmon = f"{mo}월"
fmt = lambda n: f"{int(round(n)):,}"
man = lambda n: f"{int(round(n/10000)):,}만원"
pct = lambda x, y: (f"{(x/y-1)*100:+.1f}%" if y else '')
def arrow(x, y):
    if not y: return ''
    v = (x/y-1)*100
    return f'<span class="{"up" if v>=0 else "dn"}">{"▲" if v>=0 else "▼"} {abs(v):.1f}%</span> 전월비'

# ── build.py 와 같은 셈 ─────────────────────────────────────────
sales = m['sales']; orders = m['orders']
im = m.get('imweb') or None
im_n = a.imweb_n or (int(im['n']) if im else 0)
im_sales = a.imweb_sales or (float(im['sales']) if im else 0.0)
im_on = im_n > 0 or im_sales > 0
spend = m['spend'] or (a.boxes * a.unit)
boxes = m['boxes'] or a.boxes
cost = m['cost'] or 0.0; unknown = m['unknown'] or 0.0
ulist = dict(m.get('unknown_list') or {})
manual_cost = 0.0; manual_rows = []
for spec in a.manual:
    key, val = spec.rsplit('=', 1); val = float(val.replace(',', ''))
    hit = [k for k in ulist if key in k]
    if hit:
        k = hit[0]; manual_cost += val; manual_rows.append((k, ulist[k], val)); unknown -= ulist[k]; ulist.pop(k)
cost_all = cost + manual_cost
im_cost = 0.0; im_est = False
if im_on:
    ic = a.imweb_cost if a.imweb_cost >= 0 else (float(im['cost']) if im and im.get('cost') is not None else -1)
    if ic >= 0:
        im_cost = ic; unknown += a.imweb_unknown or (float(im.get('unknown', 0)) if im else 0.0)
    else:
        im_cost = im_sales * (cost_all / max(1.0, sales - unknown)); im_est = True
im_gross = a.imweb_gross or im_sales
gross_sales = m['before'] + im_gross                      # 매출액 (할인 전 주문 금액)
d_fee = m['fee']; d_pts = m['points'] + (im_gross - im_sales); d_cpn = m['coupon']
disc = d_fee + d_pts + d_cpn
net_sales = sales + im_sales                              # 순매출 (실입금)
assert abs(gross_sales - disc - net_sales) < 1, (gross_sales, disc, net_sales)
cogs = cost_all + im_cost                                 # 매출원가
gross_profit = net_sales - cogs                           # 매출총이익
total_orders = orders + im_n

def parse_items(specs):
    out = []
    for s in specs:
        k, v = s.rsplit('=', 1); v = v.strip()
        out.append((k.strip(), None if v in ('?', '') else float(v.replace(',', ''))))
    return out
var_items = [('배송비 (우체국 요금 · ' + f'{boxes:,}건 × {fmt(a.unit)}원)', float(spend))] + parse_items(a.var)
fixed_items = parse_items(a.fixed)
var_known = sum(v for _, v in var_items if v is not None)
fixed_known = sum(v for _, v in fixed_items if v is not None)
var_pending = [k for k, v in var_items if v is None]
fixed_pending = [k for k, v in fixed_items if v is None]
contrib = gross_profit - var_known                        # 공헌이익
op = contrib - fixed_known                                # 영업이익
cm_ratio = contrib / net_sales if net_sales else 0        # 공헌이익률 (순매출 대비)
bep = fixed_known / cm_ratio if (fixed_known and cm_ratio) else None

# 대량 주문 제외 열
big = [b for b in (m.get('big') or []) if b['t'] >= a.big_min]
big_total = sum(b['t'] for b in big)
big_cost = sum(v for k, s_, v in manual_rows if any(k in ln['name'] for b in big for ln in b['lines']))
ex = None
if big:
    ex = dict(gross=gross_sales - big_total, disc=disc, net=net_sales - big_total, cogs=cogs - big_cost)
    ex['gp'] = ex['net'] - ex['cogs']
    ex['var'] = var_known; ex['contrib'] = ex['gp'] - var_known; ex['op'] = ex['contrib'] - fixed_known
    ex['orders'] = total_orders - len(big)
    ex['cm'] = ex['contrib'] / ex['net'] if ex['net'] else 0

# 주문 1건당
per = lambda v, n: v / n if n else 0
unit_rows = [
    ('순매출 (객단가)', per(net_sales, total_orders), per(ex['net'], ex['orders']) if ex else None),
    ('매출원가', per(cogs, total_orders), per(ex['cogs'], ex['orders']) if ex else None),
    ('매출총이익', per(gross_profit, total_orders), per(ex['gp'], ex['orders']) if ex else None),
    ('변동비 (배송비)', per(var_known, total_orders), per(var_known, ex['orders']) if ex else None),
    ('공헌이익', per(contrib, total_orders), per(ex['contrib'], ex['orders']) if ex else None),
]

def short(k):
    k = re.sub(r'여기서 결제 도와드리겠습니다!?|결제 도와드리겠습니다\.?', '', k); return re.sub(r'\s+', ' ', k).strip(' []')
unknown_txt = ' · '.join(f"{html.escape(short(k))} {man(v)}" for k, v in list(ulist.items())[:3]) + (' 등' if len(ulist) > 3 else '')
asof = a.asof or (f"{kmon} 1일 ~ {m['days_done']}일 기준" + ('' if m['closed'] else ' (월중 잠정)'))

R = lambda v: f"{v/net_sales*100:.1f}%" if net_sales else ''
def row(label, v, cls='', ex_v=None, neg=False, ratio=True, pending=False):
    if pending:
        return f'<tr class="{cls}"><td>{label}</td><td class="num wait">자료 대기</td><td class="num wait">{"자료 대기" if ex else ""}</td><td class="num mut"></td></tr>' if ex else \
               f'<tr class="{cls}"><td>{label}</td><td class="num wait">자료 대기</td><td class="num mut"></td></tr>'
    s = ('−' if neg else '') + fmt(v)
    cells = f'<td class="num{" neg" if neg else ""}">{s}</td>'
    if ex: cells += f'<td class="num{" neg" if neg else ""}">{("−" if neg else "") + fmt(ex_v) if ex_v is not None else ""}</td>'
    cells += f'<td class="num mut">{R(v) if ratio else ""}</td>'
    return f'<tr class="{cls}"><td>{label}</td>{cells}</tr>'

E = (lambda k: ex[k]) if ex else (lambda k: None)
pl = ''
ch = f" · 자사몰 {fmt(m['before'])} + 아임웹 {fmt(im_gross)}" if im_on else ""
pl += row(f'매출액 (할인 전 주문 금액{ch})', gross_sales, 'sum', E('gross'), ratio=False)
pl += row('금액 자동 할인', d_fee, 'sub', d_fee, neg=True)
pl += row('적립금 사용', d_pts, 'sub', d_pts, neg=True)
pl += row('쿠폰 할인', d_cpn, 'sub', d_cpn, neg=True)
ch2 = f" · 자사몰 {fmt(sales)} + 아임웹 {fmt(im_sales)}" if im_on else ""
pl += row(f'순매출 (실입금{ch2})', net_sales, 'sum', E('net'))
pl += row('매출원가 (상품 매입가' + (' · 아임웹은 추정' if im_est else '') + ')', cogs, 'sub', E('cogs'), neg=True)
pl += row('매출총이익', gross_profit, 'sum', E('gp'))
for k, v in var_items:
    pl += row(k, v, 'sub', v, neg=True) if v is not None else row(k, 0, 'sub', pending=True)
pl += row('공헌이익', contrib, 'sum hi', E('contrib'))
if fixed_items:
    for k, v in fixed_items:
        pl += row(k, v, 'sub', v, neg=True) if v is not None else row(k, 0, 'sub', pending=True)
else:
    pl += row('고정비 (인건비 · 임차료 · 플랫폼 이용료 · 광고비 등)', 0, 'sub', pending=True)
pl += row('영업이익', op, 'sum hi', E('op'))

head_cols = '<th></th><th class="num">전체</th>' + ('<th class="num">대량 주문 제외</th>' if ex else '') + '<th class="num">순매출 대비</th>'
unit_html = ''.join(
    f'<tr><td>{k}</td><td class="num">{fmt(v)}</td>' + (f'<td class="num">{fmt(e)}</td>' if ex else '') + '</tr>'
    for k, v, e in unit_rows)

op_note = ('영업이익은 고정비를 아직 받지 못해 공헌이익과 같은 값입니다. 고정비(' + ' · '.join(fixed_pending) + ')가 들어오면 그만큼 내려갑니다.'
           if fixed_pending or not fixed_items else '')
var_note = ('변동비 중 ' + ' · '.join(var_pending) + '는 자료 대기라 0으로 들어가 있습니다. ' if var_pending else '')
big_note = (f"{int(big[0]['d'][5:7])}/{int(big[0]['d'][8:10])} 대량 주문 {len(big)}건({man(big_total)}, 원가 {man(big_cost)})은 「대량 주문 제외」 열에서 뺐습니다 — 평소 달과 견줄 때는 그 열을 봅니다. " if big else '')
bep_line = (f"고정비 {fmt(fixed_known)}원 ÷ 공헌이익률 {cm_ratio*100:.1f}% = <b>{fmt(bep)}원</b>" if bep else
            f"고정비 ÷ 공헌이익률({cm_ratio*100:.1f}%). 고정비가 들어오면 바로 계산됩니다 — 예를 들어 고정비 100만원마다 순매출 {fmt(1000000/cm_ratio) if cm_ratio else '—'}원이 필요합니다.")

page = f'''<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<title>액상덕후 {kmon} 손익계산서</title>
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
  tr.sum.hi td {{ background: #FFF4EC; }}
  tr.sub td:first-child {{ padding-left: 16pt; }}
  .mut {{ color: #4E565F; }} .neg {{ color: #B42318; }} .wait {{ color: #8A8A8F; font-weight: 400; }}
  .note {{ font-size: 8.5pt; color: #4E565F; margin: 0 0 6pt; }}
  .two {{ display: grid; grid-template-columns: 1fr 1fr; gap: 14pt; }}
  .flow {{ display: grid; grid-template-columns: repeat(5, 1fr); gap: 6pt; margin: 6pt 0 4pt; }}
  .step {{ border: 1px solid #D5D9DD; border-radius: 6pt; padding: 6pt 8pt; }}
  .step.hi {{ border-color: #C2410C; }}
  .step .l {{ font-size: 8pt; color: #4E565F; }}
  .step .v {{ font-size: 11.5pt; font-weight: 800; font-variant-numeric: tabular-nums; line-height: 1.3; }}
  .step .n {{ font-size: 8pt; color: #8A8A8F; }}
  dl {{ margin: 2pt 0; font-size: 9.5pt; }}
  dt {{ font-weight: 800; margin-top: 4pt; }} dd {{ margin: 0 0 0 0; color: #333; }}
  .foot {{ font-size: 8pt; color: #8A8A8F; border-top: 1px solid #D5D9DD; padding-top: 5pt; margin-top: 10pt; }}
  .kv td:first-child {{ color: #4E565F; }}
</style>
</head>
<body>

<section class="page">
  <div class="eb">액상덕후 · Liquid Deokhu</div>
  <h1>{kmon} 손익계산서</h1>
  <div class="sub">{asof} · 작성 온라인몰 운영 · {dt_today.year}년 {dt_today.month}월 {dt_today.day}일</div>

  <div class="kpis">
    <div class="kpi"><div class="l">매출액 (할인 전 주문 금액)</div><div class="v">{fmt(gross_sales)}</div><div class="n">{arrow(gross_sales, a.prev_sales)} · 순매출 {fmt(net_sales)}</div></div>
    <div class="kpi"><div class="l">매출총이익</div><div class="v">{fmt(gross_profit)}</div><div class="n">매출총이익률 {gross_profit/net_sales*100:.1f}% (순매출 대비)</div></div>
    <div class="kpi hi"><div class="l">공헌이익</div><div class="v">{fmt(contrib)}</div><div class="n">공헌이익률 {cm_ratio*100:.1f}%{f' · 대량 주문 제외 {ex["cm"]*100:.1f}%' if ex else ''}</div></div>
    <div class="kpi hi"><div class="l">영업이익</div><div class="v">{fmt(op)}</div><div class="n">{'고정비 반영 뒤 확정' if (fixed_pending or not fixed_items) else f'영업이익률 {op/net_sales*100:.1f}%'}</div></div>
  </div>

  <div class="flow">
    <div class="step"><div class="l">매출액</div><div class="v">{man(gross_sales)}</div><div class="n">− 할인 {man(disc)}</div></div>
    <div class="step"><div class="l">순매출</div><div class="v">{man(net_sales)}</div><div class="n">− 매출원가 {man(cogs)}</div></div>
    <div class="step"><div class="l">매출총이익</div><div class="v">{man(gross_profit)}</div><div class="n">− 변동비 {man(var_known)}</div></div>
    <div class="step hi"><div class="l">공헌이익</div><div class="v">{man(contrib)}</div><div class="n">− 고정비 {man(fixed_known) if fixed_known else '자료 대기'}</div></div>
    <div class="step hi"><div class="l">영업이익</div><div class="v">{man(op)}</div><div class="n">{'고정비 반영 전' if (fixed_pending or not fixed_items) else '확정'}</div></div>
  </div>

  <h2>손익계산서</h2>
  <table>
    <thead><tr>{head_cols}</tr></thead>
    <tbody>{pl}</tbody>
  </table>
  <p class="note">{big_note}{var_note}{op_note} 비율은 순매출(실입금) 대비입니다. 매출원가는 상품별 매입 단가 × 수량이며 값이 바뀐 상품은 주문 날짜의 단가로 셉니다.{(' 원가를 모르는 매출 ' + man(unknown) + '(' + unknown_txt + ')은 원가 0으로 들어가 이익이 그만큼 높습니다.') if unknown > 0 else ''} 포장재는 운영 방침에 따라 넣지 않았습니다.</p>
</section>

<section class="page">
  <h2>주문 1건당</h2>
  <table>
    <thead><tr><th></th><th class="num">전체 ({total_orders:,}건)</th>{f'<th class="num">대량 주문 제외 ({ex["orders"]:,}건)</th>' if ex else ''}</tr></thead>
    <tbody>{unit_html}</tbody>
  </table>
  <p class="note">주문 한 건이 남기는 돈입니다. 공헌이익 한 건 = 그 주문이 고정비를 갚는 데 보태는 몫. {f'대량 주문을 빼면 한 건당 공헌이익 {fmt(unit_rows[4][2])}원, 즉 순매출의 {ex["cm"]*100:.0f}%가 남습니다.' if ex else ''}</p>

  <h2>손익분기점</h2>
  <p>손익분기 순매출 = {bep_line}</p>
  <p class="note">손익분기점은 「이 달 고정비를 다 갚으려면 순매출이 얼마여야 하는가」입니다. 공헌이익률이 높을수록 적은 매출로 닿습니다.</p>

  <div class="two">
    <div>
      <h2>낱말 풀이</h2>
      <dl>
        <dt>매출액</dt><dd>손님이 주문서에 적은 금액. 적립금 · 쿠폰 · 자동 할인을 빼기 전 값(8월 보고서의 「총 매출」).</dd>
        <dt>순매출</dt><dd>할인을 뺀 뒤 손님이 실제로 입금한 돈. 아래 모든 비율의 기준.</dd>
        <dt>매출원가</dt><dd>판 상품의 매입가 합계. 값이 바뀐 상품은 주문 날짜의 단가.</dd>
        <dt>매출총이익</dt><dd>순매출 − 매출원가. 상품을 사서 팔아 남는 돈.</dd>
        <dt>변동비</dt><dd>주문이 늘면 같이 느는 비용 — 배송비, 결제 수수료, 문자 발송비.</dd>
        <dt>공헌이익</dt><dd>매출총이익 − 변동비. 주문 하나하나가 고정비를 갚는 데 보태는 돈. 이 값이 0 이면 팔아도 남는 것이 없다.</dd>
        <dt>고정비</dt><dd>주문이 없어도 나가는 비용 — 인건비, 임차료, 플랫폼 이용료, 광고비.</dd>
        <dt>영업이익</dt><dd>공헌이익 − 고정비. 이 달 장사로 실제로 남은 돈(세금 · 이자 전).</dd>
      </dl>
    </div>
    <div>
      <h2>집계 기준</h2>
      <table class="kv"><tbody>
        <tr><td>기간</td><td>{asof}</td></tr>
        <tr><td>주문</td><td>자사몰 입금확인 이후 상태 {orders:,}건{f' + 아임웹 결제완료 {im_n}건' if im_on else ''} · 취소 · 미입금 제외</td></tr>
        <tr><td>매출원가</td><td>상품 원가표 × 수량 · 주문 날짜 단가{' · 아임웹 상품 줄 원가' if im_on and not im_est else ''}</td></tr>
        <tr><td>배송비</td><td>{boxes:,}건 × {fmt(a.unit)}원 (우체국 요금 기준)</td></tr>
        <tr><td>변동비 대기</td><td>{' · '.join(var_pending) if var_pending else '없음'}</td></tr>
        <tr><td>고정비 대기</td><td>{' · '.join(fixed_pending) if fixed_pending else ('없음' if fixed_items else '전체 (아직 받지 못함)')}</td></tr>
        <tr><td>전월비</td><td>{html.escape(a.prev_note) if a.prev_note else '—'}</td></tr>
      </tbody></table>
      <p class="note">고정비 · 변동비 자료가 들어오면 같은 표에 그 줄만 채워 다시 냅니다. 이미 적힌 매출 · 원가 숫자는 바뀌지 않습니다.</p>
    </div>
  </div>

  <div class="foot">액상덕후 {ym} 손익계산서 · {dt_today.year}년 {dt_today.month}월 {dt_today.day}일 생성 · 매출 보고서(같은 날짜)와 같은 집계 · <b>집계 {m.get('built','')}</b> — 입금 확인이 이어지는 동안 숫자는 계속 오릅니다</div>
</section>
</body>
</html>
'''
open(a.out, 'w', encoding='utf-8').write(page)
print(f"wrote {a.out}: gross {fmt(gross_sales)} · net {fmt(net_sales)} · cogs {fmt(cogs)} · GP {fmt(gross_profit)} · var {fmt(var_known)} · CM {fmt(contrib)} ({cm_ratio*100:.1f}%) · fixed {fmt(fixed_known)} · OP {fmt(op)}" + (f" · ex CM {fmt(ex['contrib'])} ({ex['cm']*100:.1f}%)" if ex else ''))
