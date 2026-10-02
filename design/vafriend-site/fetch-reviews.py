#!/usr/bin/env python3
"""네이버 플레이스 방문자 리뷰를 받아 vfrev/reviews.json 으로 둔다 (베이프렌드 대구 직영 8개점).
읽기만 한다. m.place.naver.com 의 리뷰 탭 HTML 안 __APOLLO_STATE__ 에서 꺼낸다 — 지점마다 글 있는 것 10개 + 점수만 있는 것 10개.
GraphQL(api.place.naver.com) 은 캡차로 막혀 있어 쓰지 않는다. 사용: python3 fetch-reviews.py <출력 폴더>"""
import re,json,subprocess,sys,pathlib
STORES={'동성로점':36410235,'수성점':1130701499,'본점':1652071412,'율하점':1595184581,'반월당점':1445725347,'상인점':1598820567,'광코점':1524699622,'현풍점':2046108329}
UA="Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1"
out_dir=pathlib.Path(sys.argv[1] if len(sys.argv)>1 else 'vfrev'); out_dir.mkdir(exist_ok=True)
out={}
for name,pid in STORES.items():
    html=subprocess.run(['curl','-sS','-A',UA,f'https://m.place.naver.com/place/{pid}/review/visitor'],capture_output=True,text=True).stdout
    (out_dir/f'{pid}.html').write_text(html)
    m=re.search(r'window\.__APOLLO_STATE__\s*=\s*(\{.*?\});\s*</script>',html,re.S)
    if not m: print(name,'상태 없음 — 캡차?',len(html)); continue
    st=json.loads(m.group(1))
    base=next((v for k,v in st.items() if k.startswith('PlaceDetailBase')),{})
    tot=re.search(r'"visitorReviewsTotal":(\d+)',html); sc=re.search(r'visitorReviewsScore":([\d.]+)',html); txt=re.search(r'visitorReviewsTextReviewTotal":(\d+)',html)
    dm=re.search(r'"starDistribution":(\[.*?\])',html); b={'5':0,'4':0,'3':0,'2':0,'1':0}
    for x in (json.loads(dm.group(1)) if dm else []):
        s=float(x['score']); b['5' if s>=5 else '4' if s>=4 else '3' if s>=3 else '2' if s>=2 else '1']+=int(x['count'])
    revs=[]
    for k,v in st.items():
        if k.startswith('VisitorReview:'):
            a=st.get(v['author']['__ref'],{}) if isinstance(v.get('author'),dict) and '__ref' in v['author'] else (v.get('author') or {})
            revs.append({'id':v.get('reviewId') or v.get('id'),'rating':v.get('rating'),'body':(v.get('body') or '').strip(),'nick':a.get('nickname'),'visited':v.get('visited'),'created':v.get('created'),'origin':v.get('originType'),'visitCount':v.get('visitCount'),'photos':len(v.get('media') or [])})
    out[name]={'pid':pid,'name':base.get('name'),'total':int(tot.group(1)) if tot else None,'score':float(sc.group(1)) if sc else None,'text_total':int(txt.group(1)) if txt else None,'dist':b,'scored':sum(b.values()),'reviews':revs}
    print(f"{name:5} 리뷰 {out[name]['total']} · {out[name]['score']} · 글 {sum(1 for r in revs if len(r['body'])>=4)}개")
json.dump(out,open(out_dir/'reviews.json','w'),ensure_ascii=False,indent=1)
print('→',out_dir/'reviews.json')
