# vafriend.com 리뷰 교체 (2026-10-02)

베이프렌드 웹사이트(클라우드플레어 정적 배포 · `_worker.js` 동봉)의 리뷰 구역을 네이버 플레이스 **실제 방문자 리뷰**로 바꾼 작업.
원본 폴더는 사장님이 가져온 `vafriend_v34.zip`(라이브와 바이트 동일), 결과는 `vafriend_v35_naver-reviews.zip` (저장소에는 안 둔다 — 26MB).

1. `fetch-reviews.py <폴더>` — 8개 지점 플레이스 번호로 리뷰 탭을 받아 `reviews.json` (글 · 닉네임 · 방문일 · 인증 종류 · 점수 분포)
2. `build-reviews.py` — `vfsite/`(원본) + `vfrev/reviews.json` → `vfsite_out/` : 홈 평점 상자 · 슬롯머신 3줄 · SNS 페이지 요약 · 지점 줄 · 카드 12장 · 사이트맵 lastmod
3. zip 으로 묶어 사장님께 — 올리는 쪽은 사장님

규칙: 글은 한 글자도 안 고친다 · 닉네임은 첫 글자만 · 손님 사진은 안 쓴다 · 숫자는 받은 날짜와 함께 적는다 · 액상덕후를 꺼내지 않는다.
