import re,sys,json,subprocess,html,urllib.parse
from concurrent.futures import ThreadPoolExecutor
UA='Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1'
def one(u):
    r=subprocess.run(['curl','-sS','--max-time','40','-A',UA,'-o','-','-w','\n__CODE__%{http_code}',u],capture_output=True)
    t=r.stdout.decode('utf-8','ignore'); code=t.rsplit('__CODE__',1)[-1].strip(); h=t.rsplit('__CODE__',1)[0]
    g=lambda p:(re.search(p,h,re.S|re.I) or [None,None])[1]
    title=html.unescape(g(r'<title[^>]*>(.*?)</title>') or '').strip()
    desc=html.unescape(g(r'<meta name="description" content="([^"]*)"') or '')
    canon=g(r'<link rel="canonical" href="([^"]*)"') or ''
    robots=' '.join(re.findall(r'<meta name="robots" content="([^"]*)"',h))
    h1=len(re.findall(r'<h1[\s>]',h))
    imgs=re.findall(r'<img\b[^>]*>',h)
    noalt=sum(1 for i in imgs if not re.search(r'\salt=',i))
    emptyalt=sum(1 for i in imgs if re.search(r'\salt=(""|\'\')',i))
    ld=len(re.findall(r'application/ld\+json',h))
    types=sorted(set(re.findall(r'"@type"\s*:\s*"([^"]+)"',h)))
    return dict(u=u,code=code,size=len(h),title=title,desc=desc,dlen=len(desc),canon=canon,robots=robots,h1=h1,imgs=len(imgs),noalt=noalt,emptyalt=emptyalt,ld=ld,types=types,
      mg098=len(re.findall(r'0\.98\s*mg',h,re.I)),fatal='critical error' in h.lower() or 'fatal error' in h.lower(),
      cut='</html>' not in h[-3000:].lower(), og=bool(re.search(r'property="og:title"',h)))
urls=[l.strip() for l in open(sys.argv[1]) if l.strip()]
with ThreadPoolExecutor(6) as ex: res=list(ex.map(one,urls))
json.dump(res,open(sys.argv[2],'w'),ensure_ascii=False,indent=0)
print(len(res))
