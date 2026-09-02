# 액상덕후 리디자인

전자담배 액상 전문몰 **액상덕후**의 UI/UX 리디자인 저장소입니다.
원래 키플(keyple)이 제작한 사이트를 인수해 직접 운영하는 상황입니다.

## 사이트

| | |
|---|---|
| 프로덕션 | https://duck-hoo.com (blog_id `253955323`) — **라이브 쇼핑몰** |
| 스테이징 | https://staging-fe60-tothemoone-huwyp.wpcomstaging.com (blog_id `257047909`) |
| 플랫폼 | WordPress.com Atomic · 상거래 요금제 · WordPress 7.1 / PHP 8.3 |
| 테마 | `키플_액상덕후` (표시명 "Welcome Drink") |
| 커머스 | WooCommerce 11.1 · 플러그인 52개 |

## 작업 규칙

### 1. 스테이징에서 작업하고, 프로덕션에는 배포로 옮긴다

**`동기화`(스테이징 → 프로덕션) 버튼을 쓰지 않는다.** 사이트 전체를 DB까지
덮어쓰므로 프로덕션에 쌓인 주문·회원·적립금이 사라진다.

옮길 때는 프로덕션에도 GitHub Deployments 를 따로 연결한다. 같은 저장소,
같은 브랜치, 대상 디렉터리 `/wp-content/plugins/new-website-build`.
그러면 배포되는 것은 이 플러그인 폴더 하나뿐이고 나머지는 그대로 남는다.

### 2. 필수 기능은 건드리지 않는다 — 껍데기만 입힌다

아래는 법적 의무이거나 매출·정산에 직결된다. **로직, 폼 필드 이름, DOM 구조,
데이터 경로를 바꾸지 않는다.** 스타일만 위에 얹는다.

| 기능 | 플러그인 | 왜 건드리면 안 되는가 |
|---|---|---|
| 휴대폰 본인확인 | `dreamsecurity-mobile-auth` | 19세 미만 판매 금지 품목. 성인인증은 법적 의무 |
| 주문 상태 | `keyple-order-status` | 입금전·확인필요·입금확인·배송준비중·배송중·배송완료 |
| 입금 자동확인 | `keyple-bank-auto-confirm` | 안드로이드 헬퍼 앱이 입금 SMS 를 받아 매칭 |
| 고객관리 | `keyple-customer` | 등급·적립금·그룹(승인대기/일반/사업자) |
| 쿠폰 | `keyple-coupon-manager` | |
| 송장·정산 | `keyple-order-excel-tracking`, `keyple-sales-check-v2.7`, `woocommerce-epost-shipping` | |
| 게시판 | `kboard`, `kboard-comments` | |

구체적으로 하지 않을 것:
- 위 플러그인을 비활성화하거나 파일을 수정하지 않는다
- 폼 필드의 `name` / `id` 를 바꾸지 않는다 — 입금 자동매칭이 이름으로 걸려 있다
- 성인인증 단계를 건너뛰거나 숨기지 않는다
- 결제·장바구니의 데이터 전달 경로에 개입하지 않는다

`duckhoo-front` 가 쓰는 방식이 정답이다: 원본 PPOM 필드는 `.dhx-src` 로
시각적으로만 감추고 값은 살려둔 채, 그 위에 새 UI 를 얹는다.

### 3. 결제는 무통장입금 전용이다

카드결제(PG)를 쓰지 않는다. `pgall-for-woocommerce`(심플페이)가 비활성인 것은
의도된 상태다. 활성화하지 않는다.

**입금자명이 주문자명과 일치해야 자동 입금확인이 된다.** 이름이 다르면
`확인필요` 로 넘어가 사람이 손으로 처리한다. 그래서 주문서에서 입금자명 입력을
약하게 만드는 디자인은 곧 운영 비용이다. 이 안내는 결제 단계뿐 아니라 앞단에서도
한 번 보여준다.

## 세션 시작 전 환경 설정 (중요)

이 프로젝트는 **Claude 가 사이트 화면을 직접 봐야** 제대로 굴러간다. 기본 환경은
outbound 접속이 좁은 허용 목록으로 잠겨 있어서 사이트에 접속하지 못한다.
curl 도 브라우저도 똑같이 막힌다 (`ERR_TUNNEL_CONNECTION_FAILED`).

**환경(Environment) 설정에서 네트워크 접근을 열고, 새 세션을 시작한다.**
정책은 세션 시작 시 한 번 읽히므로 기존 세션에서는 반영되지 않는다.
최소한 아래 호스트가 열려야 한다.

```
*.wpcomstaging.com
duck-hoo.com
public-api.wordpress.com
```

문서: https://code.claude.com/docs/en/claude-code-on-the-web

막혔는지 확인하는 법:

```bash
curl -sS -o /dev/null -w '%{http_code}\n' --max-time 20 https://duck-hoo.com
# 000 이면 막힌 것. 프록시 사유는 아래로 확인
curl -sS "$HTTPS_PROXY/__agentproxy/status" | python3 -m json.tool | grep -A3 recentRelayFailures
```

열려 있는지 확인되면 Chromium 이 이미 설치돼 있으므로 바로 스크린샷을 찍을 수 있다
(`/opt/pw-browsers/chromium`, `npm i playwright` 만 하면 됨).

### 네트워크가 열렸을 때의 작업 루프

1. 코드를 고쳐 `claude/ui-ux-design-refresh-p5czcx` 에 push
2. GitHub Deployments 가 스테이징에 자동 반영 (**자동 배포를 켜 둘 것**)
3. Claude 가 Playwright 로 여러 화면폭 스크린샷을 찍어 스스로 검증
4. 사람은 스테이징 URL 을 새로고침해 같은 화면을 실시간으로 확인
5. 문제 있으면 1번으로

이 루프가 돌면 사람이 스크린샷을 찍어 옮겨줄 필요가 없다.

### 네트워크가 막혀 있을 때 (차선)

배포와 콘텐츠 수정은 그대로 된다. 막히는 것은 결과 확인뿐이다.

- 배포: push → WordPress 가 GitHub 에서 당겨가므로 Claude 의 접속이 필요 없다
- 사이트 읽기: WordPress MCP 는 `mcp-proxy.anthropic.com` 으로 붙어 egress 정책을
  타지 않는다. 상품·테마 토큰·플러그인·미디어를 읽을 수 있다
- 확인: 사람이 스크린샷을 찍어 대화창에 올려야 한다
- 아티팩트 시안에는 외부 이미지를 넣을 수 없다 (CSP). 실제 상품 사진을 쓰려면
  대화창에 파일로 올려서 data URI 로 심어야 한다

## 디자인

- 한국어 · 국내 · **모바일 우선** (min-width 미디어쿼리만 사용)
- 폰트 **Pretendard** (테마가 이미 사용) → Noto Sans KR 폴백
- 포인트 컬러 **번트 오렌지 `#C2410C`**, 구조·버튼 **검정 `#111111`**
- **반투명 표면을 쓰지 않는다.** `backdrop-filter` 블러는 상품 목록처럼
  카드가 수십 개 깔리는 화면에서 스크롤을 끊는다. 전부 불투명.
- 색은 흰 카드와 바탕 `#F4F6F7` **양쪽에서** WCAG AA(4.5:1)를 넘겨야 한다.
  한쪽만 보고 정하면 캡션이 바탕 위에서 미달한다
- 상품은 묶음 구성이고 **병당 가격이 고객의 비교 지표다**. 총액보다 크게 쓴다
- 터치 타깃 최소 44px, 주요 CTA 48px
- 한글에 `word-break: break-all` 을 쓰지 않는다 (`keep-all`), 음수 자간도 쓰지 않는다
- `100vh` 대신 `100dvh`

토큰은 `assets/tokens.css`. 요소 규칙까지 포함한 전체 기반은 `design/tokens.css`
(참고용, 배포 안 됨).

## 저장소 구조

```
duckhoo-redesign.php     플러그인 본체 — 배포됨
assets/tokens.css        디자인 토큰 — 배포됨
.deployignore            아래 것들을 배포에서 제외
design/                  시안·기반 문서 (참고)
reference/duckhoo-front/ 기존 프론트 플러그인 소스 (참고)
.claude/skills/          Claude Code 스킬 98개 (13MB)
```

`.deployignore` 덕분에 실제로 사이트에 올라가는 것은 플러그인 본체와 토큰
CSS 뿐이다. 새 파일을 저장소 루트에 추가할 때는 그것이 배포 대상인지 확인한다.

## 라이브 버그 두 개

### 주문취소 — 원인 확인, 고쳐서 넣어 뒀다

WooCommerce 는 기본적으로 `pending`·`failed` 주문에만 취소 링크를 그린다.
이 사이트 주문은 `keyple-order-status` 가 붙인 한국형 상태로 들어가므로 그 목록에
걸리지 않고, 그래서 버튼 자체가 렌더되지 않는다. 플러그인 버그가 아니라 연결이
빠진 것이다.

`duckhoo-redesign.php` 의 `woocommerce_valid_order_statuses_for_cancel` 필터로
**입금전만** 열었다. 슬러그는 keyple 이 정하는 값이라 하드코딩하지 않고
`wc_get_order_statuses()` 의 이름표(`입금전`)로 찾는다.

확인필요를 열지 않은 이유: 그 상태는 입금 문자가 이미 들어온 뒤의 분기다.
고객이 스스로 취소하면 환불이 남는다. 사람이 봐야 한다.

### 회원탈퇴 — 기능이 없었다. 만들어 넣었다

`https://duck-hoo.com/membership-cancel/` (page 521, 제목 "회원탈퇴") 의 내용은
`/` 한 글자뿐인 빈 페이지였다. 메뉴만 있고 뒤가 비어 있었다. 고장난 게 아니라
만들어진 적이 없다.

`includes/membership-cancel.php` 가 그 뒤를 채운다.

- **계정을 지우지 않는다.** 로그인을 끊고(비밀번호 무효화 + `authenticate` 차단)
  이름·연락처·주소만 지운다. 주문은 그대로 남는다 — 전자상거래법상 거래기록 5년 보존
- 확인 절차: 비밀번호 재입력 + 동의 체크 + 논스. 셋 다 서버에서 본다
- 막는 조건: **끝나지 않은 주문이 있을 때**, **적립금이 남아 있을 때**
- 화면은 숏코드 `[duckhoo_membership_cancel]`. 페이지에 직접 넣으면 그쪽이 우선이고,
  슬러그가 `membership-cancel` 이면서 내용이 사실상 비어 있으면 자동으로 대신 그린다

**적립금 읽는 키를 아직 모른다.** keyple-customer 가 어디에 저장하는지 공개돼 있지
않아, 회원 메타에서 이름(`point`/`mileage`/`reward`/`적립`)으로 찾는다. 못 찾으면
적립금으로는 막지 않는다. 정확한 키를 알게 되면 한 줄이면 된다.

```php
add_filter( 'duckhoo_member_points', fn( $v, $id ) => (float) get_user_meta( $id, '<키>', true ), 10, 2 );
```

검증: `php design/php-tests/run.php` — 워드프레스 없이 스텁으로 22개 항목을 본다.

## 알려진 문제

- **`[dh_coverflow]` 숏코드가 동작하지 않는다.** `duckhoo-front` 가
  `dist/dh-front.js` 를 찾는데 그 폴더가 아예 없다. 숏코드를 쓰면 빈 div 만
  출력된다. 되살리려면 바닐라 JS(GSAP Draggable/Observer)로 다시 짜야 한다.
- `duckhoo-front` 의 CSS 에 `!important` 가 270개 있다. 테마(WoodMart 계열,
  옵션 폼이 `wd-option-builder`)와 싸우는 중이라 테마 업데이트에 취약하다.
- `duckhoo-front` 는 설명과 달리 React/Tailwind 가 아니다. 순수 PHP + CSS +
  jQuery 다. 빌드 단계가 없으므로 직접 수정하면 된다.
- `--dhx-ink-3: #94A3AE` 가 11.5px 상태 텍스트에 쓰이는데 흰 배경 2.59:1 로
  AA 에 크게 미달한다.

## 데모 사이트

`design/demo.html` — 12개 화면이 전부 눌리는 한 파일짜리 데모.
`python3 design/demo-src/build.py` 로 다시 만든다 (소스는 `design/demo-src/`).
배포 대상이 아니다 (`.deployignore` 에 `design/` 이 들어 있다).

실제 운영 규칙을 데모에도 그대로 넣어 뒀다. 디자인을 볼 때 이것들이 같이 보여야 한다.

- 주문서에서 **입금자명 ≠ 주문자명** 이면 `확인필요` 로 접수된다 (일치하면 `입금전`)
- **주문취소는 `입금전` 하나뿐이다.** 확인필요부터는 입금 문자가 이미 들어온 상태라
  버튼이 잠기고 안내 문구가 뜬다 (플러그인 쪽 필터도 같은 규칙이다)
- **회원탈퇴는 끝나지 않은 주문이나 남은 적립금이 있으면 막힌다.** 막힌 이유를
  모달에 그대로 적어 준다 (플러그인 쪽도 같은 규칙이다)
- 회원가입은 **본인확인이 1단계**, 정보입력이 2단계다. 건너뛸 수 없다

검증: `design/demo-src/demo-test.mjs` (Playwright). 390 / 768 / 1280px 에서
14개 라우트의 가로 넘침·JS 오류를 보고, 검색 · 로그인 → 담기 → 주문 → 취소,
가입 → 탈퇴 흐름을 끝까지 눌러 본다.

테스트는 데모 파일에 **아티팩트와 같은 뼈대(charset + viewport 메타 + 리셋)를
씌워서** 880px 미만은 `isMobile` 로 본다. viewport 메타가 없으면 모바일
브라우저가 레이아웃을 980px 로 잡아 버려 실제와 전혀 다른 화면을 검사하게 된다.

### 문서는 스크롤하지 않는다 — 앱 껍데기

`body` 는 `height:100dvh; overflow:hidden` 세로 플렉스이고, 스크롤은 `#view`
안에서만 한다. 헤더와 아래 탭바는 흐름 안의 플렉스 아이템이다 — fixed 도
sticky 도 아니다.

이유: 문서가 스크롤하면 모바일 브라우저 주소창이 접혔다 펴지며 뷰포트 높이가
바뀌고, 그때마다 fixed/sticky 탭바가 같이 움직인다 (홈 → 주문내역에서 그랬다).
안에서만 스크롤하면 주소창이 건드려지지 않는다. 그래서:

- 라우트 전환 시 `#view.scrollTo(0,0)` (window 가 아니다)
- ScrollTrigger 는 `scroller:'#view'`
- 테스트가 라우트마다 문서가 스크롤되지 않는지(`docH <= cliH`)와 탭바 위치,
  그리고 홈에서 900px 스크롤한 뒤와 주문내역으로 옮긴 뒤 탭바 `top` 이
  같은지를 본다

### 홈 구성 (레퍼런스: FootWear 스니커즈 몰 랜딩)

사용자가 준 데스크톱 레퍼런스를 그대로 따른다. ui-ux-pro-max 의 landing 도메인
"Marketplace / Directory" 패턴과 같다 — **검색이 첫 CTA**, 그 다음 분류·특가·목록·신뢰·CTA.

1. 데스크톱 상단 유틸 바 (공지 · FAQ · 카톡 문의 · 배송조회 · 19세 안내)
2. 헤더: 로고 · **넓은 검색창**(데스크톱) · 장바구니 알약(병 수) · 아바타. 모바일은 검색 아이콘
3. 히어로 2장: 왼쪽 흰 카드 "신상 입고" + 상품, 오른쪽 하늘색 그라데이션 "9월 특가"
4. **오늘의 특가** 회색 패널 — 마감 카운트다운(9/30 23:59 KST 까지 1초 단위) + 가로 카드
5. **지금 고르세요** — 가운데 큰 제목, 필터 알약 3개(브랜드·니코틴·맛, 진짜 `<select>` 를
   알약으로 감쌈) + 4열 그리드 12개 + 더 보기
6. **브랜드 공식 스토어** — 인증 배지 · 평점 · 종수 · 2×2 썸네일 카드 3장
7. 이번 달 볼 것 — 커버플로우 (사용자가 요청한 21st.dev 컴포넌트)
8. 검은 배너 + 검은 푸터(소개 · 카카오톡 채널 · 링크 4열 · 법적 고지)

상품 카드는 레퍼런스대로: 아이브로(신상·9월 특가·재구매 1위·품절) · 찜 하트(`S.wish`,
`aria-pressed`) · 이름 · ★평점·판매수 · 정가 취소선 + 가격. 평점·판매수는 **예시값**
(id 로 정해진다). id 6 은 품절 예시 — 구매 버튼이 잠긴다.

큰 제목이 헤더 뒤로 들어가면 헤더 가운데에 작은 제목이 켜진다 (`.gnb.small .ttl`).

### 글자·아이콘

- 폰트는 **Pretendard Variable 을 파일 안에 심는다** (`design/demo-src/pretendard.css`,
  data URI). 아티팩트 CSP 가 외부 폰트를 fonts.gstatic.com 으로만 막아 Pretendard 를
  링크로는 못 싣고, Noto Sans KR 은 사용자가 싫다고 했다. KS X 1001 2,350자 + 데모에서
  쓰는 글자, wght 400–800 만 남긴 서브셋이라 310KB 다. 안드로이드 기본 한글 폰트는
  중간 굵기가 없어 600 이 400 으로 그려지므로 웹폰트 없이는 안 된다
- 다시 만들기: `npm i pretendard`, `pip install fonttools brotli` 한 뒤
  `pyftsubset PretendardVariable.woff2 --text-file=<2350자+사용글자> --flavor=woff2 …`,
  `fonttools varLib.instancer … wght=400:800`, base64 로 `pretendard.css` 에 넣는다.
  데모에 새 한자·특수문자를 쓰면 서브셋에 없을 수 있다 — 그 글자만 시스템 폰트로 빠진다
- 숫자에 모노 폰트를 쓰지 않는다. 가격에 IBM Plex Mono 를 썼더니 개발자 화면처럼
  보였다. 같은 글꼴의 `tabular-nums` 로 맞춘다
- 캡션 최소 13px, 본문 15–16px, 큰 제목 34px/900. 11–12px 은 쓰지 않는다
- 아이콘은 유니코드 글리프가 아니라 `design/demo-src/icons.json` 의 인라인 SVG
  (24px, 2px 스트로크). 빌드가 JS 상수 `I` 와 껍데기 HTML 양쪽에 넣는다
