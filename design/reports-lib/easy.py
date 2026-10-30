"""월간 쉬운 보고서 공통 틀 — 연한 파랑 · A4 · 표지 + 장. 구글/네이버 마감 보고(build_oct.py)와 같은 옷.
쓰는 법: import sys; sys.path.insert(0, '../reports-lib'); from easy import *"""
BLUE, SKY, PALE, LINE = '#2F6FE0', '#9CC3FF', '#EEF4FF', '#C7DCFF'
def w(n): return f'{n:,.0f}'
def pct(c, i): return c / i * 100 if i else 0
STYLE = f"""<style>
@page {{ size: A4; margin: 0; }}
* {{ box-sizing: border-box; }} html, body {{ margin: 0; }}
body {{ font-family: "Pretendard Variable", Pretendard, sans-serif; color: #111; font-size: 12.5pt; line-height: 1.6; word-break: keep-all; }}
.page {{ width: 210mm; height: 297mm; padding: 17mm 18mm 16mm; position: relative; break-after: page; overflow: hidden; }}
.page:last-child {{ break-after: auto; }}
.run, .foot {{ position: absolute; left: 18mm; right: 18mm; display: flex; justify-content: space-between; font-size: 9pt; color: #6B7280; }}
.run {{ top: 8mm; }} .foot {{ bottom: 8mm; border-top: 1px solid #E5E9EE; padding-top: 4pt; }} .foot b {{ color: #111; }}
.eb {{ font-size: 10.5pt; color: {BLUE}; font-weight: 800; letter-spacing: .04em; }}
h1 {{ font-size: 29pt; line-height: 1.25; margin: 0 0 10pt; font-weight: 900; }}
h2 {{ font-size: 22pt; line-height: 1.3; margin: 4pt 0 8pt; font-weight: 900; }}
h3 {{ font-size: 14pt; margin: 0 0 6pt; font-weight: 900; }}
.lead {{ font-size: 12.5pt; color: #374151; margin: 0 0 12pt; }}
.g {{ color: #6B7280; }} .acc {{ color: {BLUE}; }}
.cover {{ background: #0F172A; color: #fff; }}
.cover .eb {{ color: {SKY}; }}
.cover .big {{ font-size: 104pt; font-weight: 900; line-height: 1; margin: 24mm 0 10mm; }}
.cover .big small {{ font-size: 30pt; color: {SKY}; margin-left: 6pt; }}
.cover h1 {{ color: #fff; }} .cover .lead {{ color: #CBD5E1; }}
.facts {{ display: grid; grid-template-columns: repeat(3, 1fr); gap: 12pt; margin-top: 16mm; }}
.fact {{ border-top: 2px solid {SKY}; padding-top: 8pt; }}
.fact .v {{ font-size: 25pt; font-weight: 900; line-height: 1.15; }} .fact .l {{ font-size: 11pt; color: #CBD5E1; }}
.words {{ margin-top: 13mm; background: #1E293B; border-radius: 8pt; padding: 12pt 14pt; font-size: 11.5pt; color: #E2E8F0; }}
.words .t {{ font-weight: 900; color: {SKY}; margin-bottom: 4pt; }} .words p {{ margin: 2pt 0; }} .words b {{ color: #fff; }}
.meta {{ position: absolute; left: 18mm; right: 18mm; bottom: 15mm; font-size: 10pt; color: #94A3B8; display: flex; justify-content: space-between; border-top: 1px solid #334155; padding-top: 8pt; }}
.meta b {{ color: #fff; }}
.rows {{ margin: 6pt 0 14pt; }}
.row {{ display: grid; grid-template-columns: 74pt 1fr; gap: 12pt; padding: 10pt 0; border-bottom: 1px solid #E5E9EE; align-items: baseline; }}
.row .k {{ font-size: 11pt; font-weight: 900; border-radius: 99pt; padding: 2pt 0; text-align: center; }}
.k.ok {{ background: {PALE}; color: {BLUE}; }} .k.mid {{ background: #F1F5F9; color: #334155; }} .k.bad {{ background: #FEF3F2; color: #B42318; }} .k.next {{ background: #111; color: #fff; }}
.row p {{ margin: 0; font-size: 13pt; }} .row p small {{ display: block; font-size: 11pt; color: #6B7280; }}
.bars {{ margin: 4pt 0 6pt; }}
.bar {{ display: grid; grid-template-columns: 120pt 1fr 60pt; align-items: center; gap: 8pt; margin: 5pt 0; font-size: 11.5pt; }}
.bar .f {{ height: 15pt; border-radius: 3pt; background: #CBD5E1; }} .bar.on .f {{ background: {BLUE}; }}
.bar .n {{ text-align: right; font-weight: 900; }}
table {{ width: 100%; border-collapse: collapse; font-size: 11pt; margin: 2pt 0 12pt; }}
th {{ text-align: left; font-size: 9.5pt; color: #6B7280; font-weight: 700; border-bottom: 2px solid #111; padding: 4pt 5pt; }}
td {{ border-bottom: 1px solid #E5E9EE; padding: 6pt 5pt; vertical-align: top; }}
.n {{ text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }}
table.fx td small {{ display: block; font-size: 9pt; color: #6B7280; font-weight: 400; margin-top: 1pt; }}
table.fx td.why {{ font-size: 10.5pt; color: #374151; width: 42%; }}
.chart {{ border: 1px solid #E5E9EE; border-radius: 8pt; padding: 10pt 12pt 6pt; margin: 4pt 0 10pt; }}
.legend {{ display: flex; gap: 16pt; font-size: 10pt; color: #374151; margin-top: 2pt; }}
.legend i {{ display: inline-block; width: 12pt; height: 9pt; border-radius: 2pt; background: {SKY}; margin-right: 4pt; vertical-align: -1pt; }}
.legend i.l {{ height: 0; border-top: 2px dashed #374151; border-radius: 0; background: none; vertical-align: 3pt; }}
.box {{ background: {PALE}; border: 1px solid {LINE}; border-radius: 8pt; padding: 11pt 14pt; font-size: 11.5pt; }}
.box .t {{ font-weight: 900; margin-bottom: 4pt; }} .box p {{ margin: 0 0 4pt; }}
.pb {{ margin: 4pt 0 12pt; }}
.pbr {{ display: grid; grid-template-columns: 52pt 1fr 70pt 46pt; gap: 8pt; align-items: center; margin: 4pt 0; font-size: 11pt; }}
.pbr .f {{ height: 13pt; background: #F1F5F9; border-radius: 3pt; overflow: hidden; }} .pbr .f i {{ display: block; height: 100%; background: #CBD5E1; }}
.pbr.on .f i {{ background: {BLUE}; }} .pbr.on span:first-child {{ font-weight: 900; }}
.vs {{ display: grid; grid-template-columns: 1fr 1fr; gap: 12pt; margin: 4pt 0 14pt; }}
.vsb {{ border-radius: 8pt; padding: 12pt 14pt; border: 1.5px solid #E5E9EE; }}
.vsb.on {{ background: {PALE}; border-color: {LINE}; }}
.vsb .l {{ font-size: 10.5pt; font-weight: 700; color: #374151; }}
.vsb .v {{ font-size: 30pt; font-weight: 900; line-height: 1.15; }} .vsb.on .v {{ color: {BLUE}; }}
.vsb .d {{ font-size: 10.5pt; color: #6B7280; }}
.vsb code {{ font-family: inherit; font-size: 10pt; background: #fff; border: 1px solid #E5E9EE; border-radius: 4pt; padding: 1pt 5pt; display: inline-block; margin-top: 4pt; }}
.todo {{ display: grid; grid-template-columns: 34pt 1fr; gap: 10pt; margin: 0 0 11pt; }}
.todo .no {{ width: 30pt; height: 30pt; border-radius: 50%; background: {BLUE}; color: #fff; font-weight: 900; display: flex; align-items: center; justify-content: center; font-size: 14pt; }}
.tight .row {{ padding: 6pt 0; }} .tight .row p {{ font-size: 12pt; }} table.tight td {{ padding: 4pt 5pt; }}
.todo h3 {{ margin: 2pt 0 1pt; }} .done {{ font-size: 10pt; font-weight: 800; color: {BLUE}; background: {PALE}; border-radius: 99pt; padding: 1pt 8pt; margin-left: 4pt; vertical-align: 2pt; }} .todo p {{ margin: 0; font-size: 11.5pt; color: #374151; }} .todo p b {{ color: #111; }}
.not {{ margin: 2pt 0 12pt; padding: 0; list-style: none; font-size: 11pt; color: #374151; }}
.not li {{ padding-left: 14pt; position: relative; margin: 0 0 3pt; }} .not li::before {{ content: "×"; position: absolute; left: 0; color: #94A3B8; }}
.goals {{ display: grid; grid-template-columns: repeat(3, 1fr); gap: 10pt; margin: 6pt 0 12pt; }}
.goal {{ border: 1.5px solid {LINE}; background: {PALE}; border-radius: 8pt; padding: 9pt 12pt; }}
.goal .l {{ font-size: 10.5pt; font-weight: 700; color: #374151; }}
.goal .v {{ font-size: 21pt; font-weight: 900; line-height: 1.25; }} .goal .v small {{ font-size: 11pt; color: #374151; }}
.goal .d {{ font-size: 10.5pt; color: {BLUE}; font-weight: 800; }}
.src {{ font-size: 9.5pt; color: #6B7280; border-top: 1px solid #E5E9EE; padding-top: 7pt; }} .src p {{ margin: 0 0 2pt; }}
</style>"""
STYLE = STYLE.replace('</style>', """
.kpis { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10pt; margin: 6pt 0 14pt; }
.kpi { border: 1.5px solid #E5E9EE; border-radius: 8pt; padding: 9pt 11pt; }
.kpi.on { background: #EEF4FF; border-color: #C7DCFF; }
.kpi .l { font-size: 10pt; color: #374151; font-weight: 700; }
.kpi .v { font-size: 19pt; font-weight: 900; line-height: 1.2; }
.kpi .d { font-size: 9.5pt; color: #6B7280; }
.up { color: #1A7F37; } .dn { color: #B42318; }
table.sm { font-size: 10pt; } table.sm td { padding: 4pt 5pt; }
.two { display: grid; grid-template-columns: 1fr 1fr; gap: 14pt; }
.note { font-size: 9.5pt; color: #6B7280; margin: 4pt 0 0; }
table.xs { font-size: 9.5pt; margin-bottom: 8pt; } table.xs td { padding: 2.5pt 5pt; }
</style>""")

class Doc:
    def __init__(self, run, total):
        self.run, self.total = run, total
    def frame(self, no, title, body):
        return f'''<section class="page">
  <div class="run"><span>{self.run}</span><span>{no} · {title}</span></div>
{body}
  <div class="foot"><span>{self.run}</span><span><b>{no}</b> / {self.total}</span></div>
</section>'''
    def html(self, title, *pages):
        return f'''<!doctype html><html lang="ko"><head><meta charset="utf-8"><title>{title}</title>
<link rel="stylesheet" href="../demo-src/pretendard.css">{STYLE}</head><body>
{"".join(pages)}
</body></html>'''

def cover(eb, big, h1, lead, facts, meta, words=''):
    fs = ''.join(f'<div class="fact"><div class="v">{v}</div><div class="l">{l}</div></div>' for v, l in facts)
    return f'''<section class="page cover">
  <div class="eb">{eb}</div>
  <div class="big">{big}</div>
  <h1>{h1}</h1>
  <p class="lead">{lead}</p>
  <div class="facts">{fs}</div>
  {words}
  <div class="meta">{meta}</div>
</section>'''

def rows(items):
    """items: (클래스 ok/mid/bad/next, 표지, 굵은 글, 작은 글)"""
    return '<div class="rows">' + ''.join(f'<div class="row"><span class="k {c}">{k}</span><p>{b}<small>{s}</small></p></div>' for c, k, b, s in items) + '</div>'

def table(head, body, cls='', left=1):
    N = ' class="n"'
    h = ''.join(f'<th{N if i >= left else ""}>{x}</th>' for i, x in enumerate(head))
    b = ''.join('<tr>' + ''.join(f'<td{N if i >= left else ""}>{x}</td>' for i, x in enumerate(r)) + '</tr>' for r in body)
    return f'<table class="{cls}"><thead><tr>{h}</tr></thead><tbody>{b}</tbody></table>'

def bars(items, mx=None, unit=''):
    """items: (이름, 값, 강조)"""
    mx = mx or max(v for _, v, _ in items) or 1
    return '<div class="bars">' + ''.join(f'<div class="bar{" on" if on else ""}"><span>{n}</span><span class="f" style="width:{v / mx * 100:.0f}%"></span><span class="n">{w(v)}{unit}</span></div>' for n, v, on in items) + '</div>'

def daybars(items, marks=None, hot=(), h=150, unit=10000, unit_l='만원'):
    """날짜별 막대 SVG. items: (라벨, 값). marks: {라벨: 글}. hot: 진하게 칠할 라벨."""
    marks = marks or {}
    W, n = 640, len(items)
    mx = max(v for _, v in items) or 1
    bw = W / n
    out = []
    for i, (lab, v) in enumerate(items):
        bh = v / mx * (h - 28)
        x, y = i * bw + 1.5, h - 14 - bh
        c = BLUE if lab in hot else SKY
        out.append(f'<rect x="{x:.1f}" y="{y:.1f}" width="{bw - 3:.1f}" height="{bh:.1f}" rx="1.5" fill="{c}"/>')
        if i % 3 == 0 or lab in marks:
            out.append(f'<text x="{x + (bw - 3) / 2:.1f}" y="{h - 2}" font-size="8.5" text-anchor="middle" fill="#6B7280">{lab}</text>')
        if lab in marks:
            out.append(f'<text x="{x + (bw - 3) / 2:.1f}" y="{y - 4:.1f}" font-size="9" font-weight="800" text-anchor="middle" fill="#111">{marks[lab]}</text>')
    return f'<svg viewBox="0 0 {W} {h}" width="100%" style="display:block">{"".join(out)}</svg>'
