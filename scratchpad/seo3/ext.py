import re,sys
t=sys.stdin.read()
for u in re.findall(r'<loc>\s*(?:<!\[CDATA\[)?\s*([^<\]\s]+)',t): print(u)
