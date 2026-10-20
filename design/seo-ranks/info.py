import sys,json,urllib.parse,time
sys.path.insert(0,'/home/user/new-website-build/design/seo-ranks')
import importlib.util
src=open('/home/user/new-website-build/design/seo-ranks/rank.py').read().split('qs=json.load')[0]
exec(src)
qs=json.load(open(sys.argv[1]));res={}
for q in qs:
    urls=parse(fetch(q))
    doms=[urllib.parse.urlparse(u).netloc for u in urls]
    ugc=sum(1 for d in doms if d in UGC)
    web=[u for u in urls if urllib.parse.urlparse(u).netloc not in UGC]
    wd=[urllib.parse.urlparse(u).netloc for u in web]
    hits=[i+1 for i,u in enumerate(urls) if 'duck-hoo.com' in u]
    whits=[(i+1,urllib.parse.unquote(urllib.parse.urlparse(u).path)[:40]) for i,u in enumerate(web) if 'duck-hoo.com' in u]
    res[q]={'n':len(urls),'ugc':ugc,'hits':hits,'web':whits,'top_web':wd[:5]}
    print(q,'| n',len(urls),'ugc',ugc,'| us',hits,whits,'| web top',wd[:4],flush=True)
    time.sleep(1.2)
json.dump(res,open(sys.argv[2],'w'),ensure_ascii=False,indent=1)
