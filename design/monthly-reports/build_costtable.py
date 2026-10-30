import openpyxl,sys,re,collections,statistics
from openpyxl.styles import Font,PatternFill,Alignment
mx,mm,out=sys.argv[1:4]
wb=openpyxl.load_workbook(mx,data_only=True,read_only=True)
rows=[r for r in wb['전체 주문'].iter_rows(min_row=5,values_only=True) if r[0] and r[10]=='포함']
# master
M=[]
for r in openpyxl.load_workbook(mm,data_only=True,read_only=True)['01_PRODUCT_MASTER'].iter_rows(min_row=2,values_only=True):
    if isinstance(r[8],(int,float)): M.append((str(r[2] or r[1] or ''),str(r[3] or ''),int(r[8])))
def sq(s): return re.sub(r'[\s\(\)\[\]\-_/·,.!★⭐|]+','',s).lower()
def master(n):
    m=re.match(r'\[([^\]]+)\]\s*(.*)',n)
    if not m: return None
    b,rest=m.group(1),m.group(2)
    if any(w in n for w in ('묶음','10+1','EVENT','이벤트','결제','대량')): return None
    fl=re.sub(r'\([^)]*\)','',rest); fl=sq(re.sub(r'\d+(\.\d+)?\s*(mg|ml|옴|ea)','',fl,flags=re.I))
    bk=sq(b.replace('노보 블랙','노보').replace('아이스빌런','빌런').replace('디오리퀴드','디오'))
    fl=fl.replace('아이스빌런','').replace(sq(b),'')
    if len(fl)<2: return None
    c=[x for x in M if bk[:2] in sq(x[0]+x[1]) and fl in sq(x[0])]
    return c[0] if c else None
g=collections.OrderedDict()
for r in rows:
    k=r[3]; q=r[4] or 0
    x=g.setdefault(k,{'q':0,'s':0,'c':0,'u':[],'src':set()})
    x['q']+=q; x['s']+=r[6] or 0; x['c']+=r[7] or 0; x['src'].add(r[9])
    if q: x['u'].append((r[7] or 0)/q)
L=sorted(g.items(),key=lambda t:-t[1]['s'])
o=openpyxl.Workbook(); ws=o.active; ws.title='상품별 원가'
H=['상품명','판매 수량','순매출','상품 원가','원가율','1개 원가(낱개 기준)','원가 근거','마스터 원가(낱병)','마스터 상품명']
ws.append(['액상덕후 2026년 10월 상품 원가표 — 10월 1~29일 매출 집계 주문 기준 · 노보 병당 6,000원']); ws.append([]); ws.append(H)
tot=[0,0,0]
for k,x in L:
    u=min((round(v) for v in x['u'] if v>0),default=0)
    mt=master(k)
    ws.append([k,x['q'],round(x['s']),round(x['c']),x['c']/x['s'] if x['s'] else 0,u,'잠정 포함' if '잠정 원가 포함' in x['src'] else '원가 대조 완료',mt[2] if mt else None,mt[0] if mt else None])
    tot[0]+=x['q'];tot[1]+=x['s'];tot[2]+=x['c']
ws.append(['합계',tot[0],round(tot[1]),round(tot[2]),tot[2]/tot[1]])
for c in ws[3]: c.font=Font(bold=True,color='FFFFFF'); c.fill=PatternFill('solid',fgColor='2F6FE0')
ws['A1'].font=Font(bold=True,size=13)
for row in ws.iter_rows(min_row=4):
    row[2].number_format=row[3].number_format=row[5].number_format=row[7].number_format='#,##0'
    row[4].number_format='0.0%'
for col,w in zip('ABCDEFGHI',[58,9,12,12,8,14,14,13,36]): ws.column_dimensions[col].width=w
ws.freeze_panes='B4'
ws2=o.create_sheet('원가 기준')
for r in [['기준','값'],['노보 · 노보 블랙','병당 6,000원 (대표 확인)'],['그 밖 상품','베이프렌드 본점 마스터(________MASTER____261009) 원가'],
          ['마스터에 없는 상품','기존 워드프레스 원가표 값 — 「원가 근거: 잠정 포함」'],['묶음','들어간 병 수 × 병당 원가 + 기기 · 팟'],
          ['매출','할인 · 환불 뒤 상품 금액, 배송비 제외 · 취소 · 미입금 제외'],['기간','2026-10-01 ~ 10-29 접수 주문 (10/30 조회)']]: ws2.append(r)
ws2.column_dimensions['A'].width=20; ws2.column_dimensions['B'].width=70
for c in ws2[1]: c.font=Font(bold=True)
o.save(out); print(len(L),tot,tot[2]/tot[1], sum(1 for k,_ in L if master(k)))
