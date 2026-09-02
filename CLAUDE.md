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
- **주문취소는 `입금전`·`확인필요` 까지만.** 배송이 시작되면 버튼이 잠기고 안내 문구가 뜬다
- **회원탈퇴는 배송 중 주문이 있으면 막힌다**
- 회원가입은 **본인확인이 1단계**, 정보입력이 2단계다. 건너뛸 수 없다

검증: `design/demo-src/demo-test.mjs` (Playwright). 390 / 768 / 1280px 에서
12개 라우트의 가로 넘침·JS 오류를 보고, 로그인 → 담기 → 주문 → 취소,
가입 → 탈퇴 흐름을 끝까지 눌러 본다.
