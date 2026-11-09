"""보고서 모음 zip 만들기 — 연말 종합 때 다시 돈다.
python3 design/reports-archive/make_zip.py <out.zip>
목록(REPORTS)에 새 달 보고서를 한 줄씩 더한다. 만든 뒤 모든 PDF 가 열리는지 확인한다 (pymupdf)."""
import os, sys, zipfile
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))  # design/
REPORTS = [  # (zip 안 폴더, zip 안 이름, design/ 아래 원본)
    ('11월', '2026년 11월 CRM 성과 보고서 (10.13~11.9).pdf', 'crm-reports/2026-11-crm-period.pdf'),
    ('10월', '2026년 10월 보고서 합본.pdf', '2026-10-reports/2026년 10월 보고서 합본.pdf'),
    ('10월', '2026년 10월 매출 보고서.pdf', '2026-10-reports/2026년 10월 매출 보고서.pdf'),
    ('10월', '2026년 10월 CRM 전환 보고서.pdf', '2026-10-reports/2026년 10월 CRM 전환 보고서.pdf'),
    ('10월', '2026년 10월 재구매 분석 보고서.pdf', '2026-10-reports/2026년 10월 재구매 분석 보고서.pdf'),
    ('10월', '2026년 10월 액상덕후 SEO 보고서.pdf', '2026-10-reports/2026년 10월 액상덕후 SEO 보고서.pdf'),
    ('10월', '2026년 10월 베이프렌드 웹탭 SEO 보고서.pdf', '2026-10-reports/2026년 10월 베이프렌드 웹탭 SEO 보고서.pdf'),
    ('10월', '2026년 10월 네이버 검색 보고서.pdf', 'naver-reports/2026-10-naver-easy.pdf'),
    ('9월', '2026년 9월 매출 보고서.pdf', 'monthly-reports/2026-09-monthly.pdf'),
    ('9월', '2026년 9월 손익계산서.pdf', 'monthly-reports/2026-09-monthly-pl.pdf'),
    ('9월', '2026년 9월 매출 보고서 (긴 판).pdf', 'monthly-reports/2026-09-monthly-long.pdf'),
    ('9월', '2026년 9월 액상덕후 SEO 보고서.pdf', 'seo-reports/2026-09-seo.pdf'),
    ('9월', '2026년 9월 구글 검색 보고서 (쉬운 판).pdf', 'gsc-reports/2026-09-google-easy.pdf'),
    ('9월', '2026년 9월 구글 검색 보고서 (자세한 판).pdf', 'gsc-reports/2026-09-google.pdf'),
    ('9월', '2026년 9월 작업 성과 보고서.pdf', 'perf-reports/2026-09-work.pdf'),
    ('9월', '2026년 9월 베이프렌드 웹 SEO 보고서.pdf', 'vafriend-reports/2026-09-seo-vafriend.pdf'),
]
out = sys.argv[1]
with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as z:
    for d, name, src in REPORTS:
        z.write(os.path.join(ROOT, src), f'액상덕후 보고서/{d}/{name}')
import pymupdf, io
with zipfile.ZipFile(out) as z:
    assert z.testzip() is None
    for n in z.namelist():
        doc = pymupdf.open(stream=io.BytesIO(z.read(n)), filetype='pdf')
        print(f'{len(doc):>3}쪽  {n}')
print('모두 열림', len(REPORTS), '부 →', out)
