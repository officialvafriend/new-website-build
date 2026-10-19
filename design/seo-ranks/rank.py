import sys, json, re, subprocess, urllib.parse, time, html
UA='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36'
UGC=('blog.naver.com','cafe.naver.com','kin.naver.com','in.naver.com','post.naver.com')
def fetch(q):
    u='https://search.naver.com/search.naver?ssc=tab.ur.all&sm=tab_jum&query='+urllib.parse.quote(q)
    return subprocess.run(['curl','-sS','--max-time','25','-A',UA,'-H','Accept-Language: ko-KR,ko;q=0.9',u],capture_output=True).stdout.decode('utf-8','ignore')
def parse(h):
    raw=[]
    for m in re.finditer(r'<a([^>]+)href="(https?://[^"]+)"([^>]*)>',h):
        a=m.group(1)+m.group(3); t=re.search(r'data-heatmap-target="([^"]*)"',a)
        if t and t.group(1) in ('.link','.title'): raw.append(html.unescape(m.group(2)))
    out=[]
    for i,u in enumerate(raw):
        p=urllib.parse.urlparse(u)
        if out and out[-1]==u: continue
        if p.path in ('','/') and i+1<len(raw):
            q=urllib.parse.urlparse(raw[i+1])
            if q.netloc==p.netloc and q.path not in ('','/'): continue
        if u in out: continue
        out.append(u)
    return out
qs=json.load(open(sys.argv[1])); res={}
for q in qs:
    urls=parse(fetch(q))
    web=[u for u in urls if urllib.parse.urlparse(u).netloc not in UGC]
    hits=[[i+1,urllib.parse.unquote(u)[:110]] for i,u in enumerate(urls) if 'duck-hoo.com' in u]
    whits=[i+1 for i,u in enumerate(web) if 'duck-hoo.com' in u]
    res[q]={'n':len(urls),'hits':hits,'web_hits':whits,'first':urllib.parse.unquote(urls[0])[:90] if urls else ''}
    print(q,'|',len(urls),[x[0] for x in hits],'web',whits,flush=True)
    time.sleep(1.0)
json.dump({'date':sys.argv[3],'where':'naver 웹 탭(ssc=tab.ur.all) — blog/cafe 섞임, n=첫 쪽 결과 수, web_hits=블로그·카페·지식인 뺀 순위','ranks':res},open(sys.argv[2],'w'),ensure_ascii=False,indent=1)
