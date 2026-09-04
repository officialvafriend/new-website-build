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
- 상품은 묶음 구성이지만 **파는 값이 먼저다**. 병당 가격은 그 아래 캡션으로 붙인다
  (사장님 피드백 2026-09-03 — 병당 가격만 보이면 얼마를 내는지 알 수 없다).
  이름에 증정·사은품·기기·디바이스·스타터가 들어간 상품은 병 수로 나누지 않는다
- 터치 타깃 최소 44px, 주요 CTA 48px
- 한글에 `word-break: break-all` 을 쓰지 않는다 (`keep-all`), 음수 자간도 쓰지 않는다
- `100vh` 대신 `100dvh`

토큰은 `assets/tokens.css`. 요소 규칙까지 포함한 전체 기반은 `design/tokens.css`
(참고용, 배포 안 됨).

## 실제 사이트 연동 (플러그인이 홈을 그린다)

`includes/front.php` + `templates/home.php` + `assets/front.css` + `assets/front.js`.

- `template_include` 로 **첫 화면만** 우리 템플릿으로 바꾼다. `wp_head`/`wp_footer` 는
  그대로 불러 GTM · WooCommerce · PPOM 스크립트가 다 산다. 홈에서만 테마 스타일
  핸들 `welcome-drink-style` 을 뺀다 (테마의 `.dh-*` 인라인 CSS 는 테마 템플릿이
  찍는 거라 우리 템플릿에선 애초에 안 나온다)
- 목록 · 상세 · 장바구니 · 결제 · 계정 · kboard · keyple 마이페이지는 **테마 템플릿이
  그대로 돌고**, `includes/shell.php` 가 `wp_body_open` 에 우리 헤더를, `wp_footer` 에
  푸터·탭바를 넣는다. 테마의 216px 사이드 헤더(`#masthead.dh-side`) · 푸터(`#colophon`) ·
  탭바(`.dh-tabbar`) · 빠른 메뉴(`.dh-quick`) · 모바일 헤더(`.wd-mobile-header-custom`) ·
  마이페이지 상단(`.wd-custom-top-wrap`)은 `assets/shell.css` 가 숨기고, `#page.dh-shell`
  그리드를 푼다. 그 위에 `assets/duckhoo-theme.css` 로 폼·버튼·계정을 정돈한다.
  **폼 필드와 데이터 경로에 손대지 않기 위해서다** — 테마 규칙이 `!important` 라
  shell.css 도 그렇게 쓰고, 테마의 인라인 `<style>` 이 우리 파일보다 뒤에 오므로
  선택자를 전부 `html body.dhr-wrap …` 으로 한 단계 올려 뒀다 (같은 특이도면 진다)
- 상품 목록 카드는 `wc_get_template_part` 필터로 `content-product.php` 만 우리 것
  (`templates/content-product.php` → `Front\card()`)으로 바꾼다. 링크 · 상품 ID 는 같다
- 목록 제목은 테마가 h1 을 1px 로 숨기고 `.woocommerce-products-header` 도 안 찍는다.
  `Shell\archive_title()` 이 `woocommerce_before_shop_loop` 앞(5)에 제목 · 건수 · 분류 설명을
  `.dhr-arch` 로 그린다. 테마 h1 은 그대로 둔다 (접근성)
- **상품 상세 · 목록은 우리 템플릿이다** (`templates/single-product.php` · `templates/archive-product.php`,
  `Front\take_front_page()` 가 `is_product()` · `is_shop()/is_product_taxonomy()/상품 검색` 에서 바꾼다.
  필터 `duckhoo_take_product` · `duckhoo_take_archive`). 구매 폼은
  `woocommerce_template_single_add_to_cart()` 한 줄이 워드커머스 · PPOM · 키플 옵션 UI 를 그대로 그린다.
  제목 · 가격 · 사는 방법은 `includes/product.php` (`head()` · `price()` · `trust()`).
  **비로그인은 갤러리를 열지 않고 `get_image()` 한 장만** 그린다 — `wc_get_gallery_image_html()` 에는
  키플의 19 가림이 안 걸린다 (확인함). 로그인 = 본인확인 회원이라 그때만 갤러리.
- 장바구니(`.wd-cpg`) · 주문서는 키플 마크업 그대로 두고 CSS 로 카드/두 칸으로 편다
  (체크박스 · 수량 · 삭제가 name 으로 걸려 있다). 표는 `table.wd-cpg-table tr.wd-cpg-row` 처럼
  **표 리셋 규칙보다 특이도를 높여** 잡아야 한다 — 한 번 졌다
- 스테이징 배포가 늦어질 때: CSS 는 `last-modified` 헤더, PHP 는 렌더 결과(`/shop/` 의 `<h1>`)로 본다.
  2026-09-03 10:10 커밋이 40분 넘게 안 올라온 적이 있다
- **옛 프론트(duckhoo-front)의 껍데기를 걷어냈다.** `dh-shell` 이 body 에 파스텔 그라데이션을,
  `#page` 에 1240px 둥근 흰 카드와 216px 사이드 내비를 그려 우리 헤더·푸터와 겹쳤다.
  `Shell\drop_legacy_shell()` 이 등록된 핸들 `dh-shell` · `dh-shop-polish` · `dh-front` 를
  `wp_dequeue_style` 한다 (필터 `duckhoo_drop_legacy_styles`). 플러그인 파일은 건드리지 않는다.
  **상품 옵션 UI(`dh-option-ui`)는 남긴다** — 그것이 PPOM select 에 값을 넣는 쪽이다. 색과
  글자 크기만 우리 토큰으로 덮는다 (`.dhx` 안의 `--dhx-*` 를 재정의)
- 사장님 Code Snippets 가 찍는 `<style id="dh-cta-fix">` 는 **핸들이 없어 뺄 수 없다.** 게다가
  `html body.dh-shell-on.dh-shell-on` 처럼 클래스를 두 번 써서 특이도를 올리고 !important 를
  붙인다. 그래서 shell.css 는 클래스를 세 번 쓴다 — `html body.dhr.dhr-wrap.dhr-wrap` (0,3,2).
  이 스니펫을 사장님이 지우면 그 한 단계는 도로 내려도 된다
- 장바구니(`.wd-cpg`)와 주문서는 워드프레스 기본이 아니라 **키플이 만든 페이지**다.
  `duckhoo-theme.css` 의 클래식 장바구니 선택자(`.shop_table` 등)는 여기에 안 걸린다.
  주문하기(`.wd-cpg-order__btn`)·결제하기(`#place_order`)는 shell.css 가 검정 알약으로 만든다
- 가로 스크롤러는 데스크톱에서 휠이 세로로만 가므로 `front.js` 가 양쪽 화살표와
  마우스 드래그를 붙인다
- 데이터: 카테고리는 이름 일부("특가" · "무니코틴" · "랭킹")로 찾는다 — "8월 특가 할인"
  처럼 달이 바뀌는 이름을 따라가기 위해서. 브랜드 분류(taxonomy)가 없어 상품명 앞
  `[브랜드]` 로 센다. 병당 가격은 이름의 "N병" 으로 나눈다. 평점·판매수는 실제 값이
  있을 때만 붙인다. 상품 조회는 10분 transient 캐시 (`dhr_*`), 상품 저장 시 비움
- 실제 상품 173종 (2026-09-02 기준): 카테고리 = 입호흡 액상 69 · 무니코틴 68 ·
  폐호흡 액상 22 · 8,800 적립금 상품 18 · 8월 특가 할인 15 · 액상 랭킹 14 ·
  기기/팟/코일 9 · 노보 액상 9. 리뷰·평점은 0 이다. 브랜드는 이름 앞 [..] 로
  얼려먹구싶오 24 · 액상덕후 18 · 제로닉 무무 13 · 맥스쿨 11 · 심쿵 11 …
- 상품 상세의 옵션은 PPOM `select` 4개 (구성 · 맛 · 팟/코일 추가 · 기기 추가)에
  `wd-option-builder` 가 덧씌워져 있다. **건드리지 않는다**
- **비로그인 방문자에겐 모든 상품 썸네일이 "19" 이미지로 바뀐다** (`img.wd-prelogin-thumb`,
  alt "상품 이미지 (로그인 후 확인 가능)"). 키플의 성인 인증 게이트다. 우회하지 않는다.
  스크린샷에서 19 만 보이면 로그인 상태가 아닌 것이다
- 홈 위에 겹치는 여름 세일 팝업(`#pop6`)과 `vf-redill-hero-js` 는 사장님 Code Snippets 다.
  플러그인이 숨기지 않는다 — 끄는 건 스니펫 쪽에서 한다
- 테마가 `wp_body_open` 으로 찍는 `.wd-mobile-header-custom`/드로어는 홈에서 CSS 로 숨긴다

### 스테이징 배포 확인법

GitHub Deployments **자동 배포가 켜져 있다** (2026-09-02 부터). push 하면 몇 분 안에
스테이징에 반영된다. 그 전에는 첫 연결 때 한 번만 돌아서 손으로 눌러야 했다. 최신인지 보는 법:
파일이 아니라 **렌더 결과**로 본다 — 홈 HTML 의 `<h1>` 이나 카드 수처럼 그 커밋에서
바뀐 것을 curl 로 확인한다 (`?nocache=<난수>` 를 붙여 캐시를 피한다). 새 파일이
생긴 커밋이면 `assets/*.css` 의 200/404 로도 된다.

로그인이 필요한 화면(주문취소 버튼 · `/membership-cancel/` 은 비로그인 시 302)은
스테이징 테스트 계정이 있어야 본다. 스크린샷 브리지(`live/shoot-b.mjs`)는 Node 쪽
쿠키 항아리를 두어 로그인 세션이 요청 사이에 살아남는다.

### 이 환경에서 사이트 보기

네트워크는 열려 있다 (curl 200). **크로미움만 `ERR_CONNECTION_RESET`** 이 난다 —
프록시 상태(`recentRelayFailures`)를 보면 CONNECT 뒤 TLS 단계에서 터널이 6초 만에
닫힌다. curl · Node(undici) 는 된다. 스크린샷은 Playwright `route` 에서 모든 요청을
undici(`EnvHttpProxyAgent` + CA 번들)로 대신 받아 `fulfill` 하는 방식으로 찍는다
(`scratchpad/live/probe-b.mjs` 패턴). 크로미움이 직접 소켓을 열게 두지 않는다.

## 사장님 피드백으로 정해진 것 (2026-09-03)

- **찜하기는 없앴다.** 브라우저에만 저장돼 기기를 바꾸면 사라지는 하트였다. 되살리려면
  테마의 위시리스트(장바구니 페이지 `.wd-cpg-wishlist`)에 붙여야 한다
- **판매 수량(N개 판매)은 카드에서 뺐다**
- **'공식' 이라는 말을 쓰지 않는다** (브랜드 섹션 · 배지 모두)
- 유틸 바 문구는 `19세 미만 판매 금지`
- 헤더 · 푸터에서 **이벤트 페이지 링크를 뺐다** (추후 다시 넣기로)
- 푸터 `배송 · 교환 · 환불` 은 `/tip/` 이 아니라 `/shipping/` 로 간다
- 카카오톡 문의 버튼 주소는 필터 한 줄로 바꾼다:
  `add_filter( 'duckhoo_kakao_url', fn() => 'https://open.kakao.com/o/XXXXXXX' );`
- 관리자로 로그인하면 워드프레스 관리 바가 화면 위에 고정되므로 sticky 헤더에
  `body.admin-bar` 오프셋(783px↑ 32px · 601–782px 46px)을 준다. 없으면 헤더가 잘려 보인다

## 사는 쪽으로 미는 것들 (레퍼런스 cruntin.com 의 구조, 2026-09-03)

레퍼런스의 힘은 색이 아니라 **매 화면이 판다**는 것이다. 우리 데이터로 같은 자리를 채웠다.

- 헤더 위 `.promo` 띠: 비로그인 `가입 즉시 8,800원 적립` → `/register/`, 로그인은 무료배송 조건.
  숫자는 `Front\signup_points()` (필터 `duckhoo_signup_points`), 무료배송 기준은 필터
  `duckhoo_free_shipping_min` (30,000). 고정이 아니라 스크롤하면 같이 올라간다
- 모바일 헤더 아래 `.bnav` 칩 (880px↑ 숨김, 데스크톱은 `.dnav`) · 홈 `.qcats` 둥근 분류 타일.
  분류 이름은 `Front\short_cat()` 으로 줄인다 ("9월 특가 할인" → "이달 특가", "기기 / 팟 / 코일" → "기기·팟")
- 상품 상세: 사진 위 할인율 알약 `.dhp-gal__pill` · 몇 장째 `.dhp-gal__n` · 가격 아래 `Product\benefits()`
  (`ul.dhp-ben`, 필터 `duckhoo_product_benefits`) · **모바일 고정 구매 줄 `.dhp-bar`** — 진짜 버튼은 폼 안의
  `.single_add_to_cart_button` / `.wd-direct-checkout-btn` 이고 구매 줄은 잠김 상태 · 총액
  (`.dhx-sum__total`, 없으면 `data-price` × 수량)을 비추며 누르면 그 버튼을 대신 누른다.
  폼의 버튼이 화면에 보이는 동안은 내려가 있다 (`IntersectionObserver` → `.is-away`). 상세에서는 탭바를 숨긴다
- **장바구니 서랍 `.dhc`** (`Front\cart_drawer_html()`, 모든 껍데기 화면의 푸터 뒤): 헤더 · 탭바의
  장바구니 버튼과 담기 직후(`.woocommerce-message`)에 열린다. 내용은 Store API
  `/wp-json/wc/store/v1/cart` (쿠키 + `Nonce` 헤더, 응답 헤더의 Nonce 를 이어 쓴다). 지우기만 여기서
  (`remove-item`), 수량은 키플 장바구니 페이지에서. **비로그인에는 썸네일을 그리지 않는다** — Store API
  사진은 19 가림을 안 거친다. 불투명 · 데스크톱 오른쪽 420px · 모바일 아래 시트 · 포커스 가둠 · Esc
- 푸터 `.fbank`: `woocommerce_bacs_accounts` 의 계좌 + 입금자명 안내
- JS 설정은 `Front\js_config()` 한 곳 (`window.DHR`): 로그인 여부 · 논스 · 무료배송 기준 · 장바구니/상품 URL
- 사장님 스니펫 두 개가 화면을 덮는다: 비로그인 상품 페이지의 성인인증 안내 `#dh-agegate2`
  (`.dh-ag2-later` 로 닫힘) 와 홈 팝업 `#pop-dim`/`#pop6`. 플러그인은 건드리지 않고, **테스트 스크립트에서만**
  지운다 (`scratchpad/live/nudge-shot.mjs` 의 `later()`)

검증: `scratchpad/live/nudge-shot.mjs` — 비로그인/로그인 × 모바일/데스크톱에서 띠 · 칩 · 타일 · 구매 줄 · 서랍
(담기 → 서랍 열림 → 지우기 → 배지 0) · 푸터 계좌를 재고 찍는다. 담은 것은 스크립트가 도로 지운다.

## 사장님 피드백으로 정해진 것 (2026-09-04)

- **주력 브랜드는 노보 · 디오리퀴드 · 화이트아웃 · 펠릭스 · 액상덕후** 다. 무니코틴이 헤더 칩 ·
  분류 타일 · 푸터 브랜드에서 두 번째로 앞서 있던 것을 내렸다. `Front\featured_brands()` 가
  이 순서를 정하고 (필터 `duckhoo_featured_brands`), 푸터 브랜드 줄 · 홈 브랜드 카드 3장 ·
  티커 · `주력 브랜드` 카루셀이 모두 그것을 쓴다. 이름이 그대로 있으면 그것을 쓴다 —
  "노보" 를 물었는데 상품 수가 많다고 "노보 블랙" 을 내세우지 않는다
- **상품 목록에 분류 설명(term description)을 그리지 않는다.** 배송 · 가격 안내가 상품 위에
  길게 깔리면 사러 온 사람이 먼저 읽어야 할 것이 상품이 아니게 된다. 글은 관리자에 그대로 있다
- **카드의 `+` 대신 `구매하기` 알약**을 넣는다. 품절이면 같은 자리에 `품절`. 묶음은 옵션(구성 · 맛)이
  필수라 담기가 아니라 상품 상세의 구매 상자(`#dhp-buy`)로 보낸다
- 페이지 번호: `ul.page-numbers` 자신도 클래스가 `page-numbers` 라 알약 배경 규칙이 목록 전체에
  걸려 흰 막대가 화면 폭만큼 깔렸다. `li > .page-numbers` 로 좁혔다

## 안내 페이지

`includes/pages.php` 가 없는 페이지를 만든다 — `/terms/`(이용약관) · `/privacy/`(개인정보처리방침) ·
`/shipping/`(배송 · 교환 · 환불). `admin_init` 과 활성화 훅에서 한 번만 돌고
(`duckhoo_pages_version` 옵션), 같은 슬러그가 이미 있으면 손대지 않는다. 만들어진 뒤에는
워드프레스 관리자에서 여느 페이지처럼 고치면 된다. 가게 정보는 `duckhoo_shop_info` 필터.

**이 글은 초안이다.** 법률 검토를 받아야 하고, 개인정보처리방침의 수탁사(본인확인기관 ·
호스팅) 이름은 실제 계약처로 채워야 한다.

## 저장소 구조

```
duckhoo-redesign.php     플러그인 본체 — 배포됨
includes/                front.php(홈·회원탈퇴 페이지) · shell.php(나머지 화면 껍데기) · membership-cancel.php — 배포됨
templates/               home.php · page-membership-cancel.php · content-product.php(목록 카드) — 배포됨
assets/                  tokens · front.css/js(공통) · shell.css(테마 껍데기 걷기) · duckhoo-theme.css(폼·계정) — 배포됨
.deployignore            아래 것들을 배포에서 제외
design/                  시안·기반 문서 (참고)
reference/duckhoo-front/ 기존 프론트 플러그인 소스 (참고)
.claude/skills/          Claude Code 스킬 98개 (13MB)
```

`.deployignore` 덕분에 실제로 사이트에 올라가는 것은 플러그인 본체와 토큰
CSS 뿐이다. 새 파일을 저장소 루트에 추가할 때는 그것이 배포 대상인지 확인한다.

## 라이브 버그 두 개

### 주문취소 — 원인 확인, 고쳐서 넣어 뒀다

**실제 상태 목록 (2026-09-02 스테이징 관리자 주문 화면, 1,652건):**

| 슬러그 | 이름표 | 건수 | 뜻 |
|---|---|---|---|
| `on-hold` | 결제 확인 중 | 293 | **입금 전** (무통장입금 주문이 여기로 들어온다) |
| `payment-confirmed` | 입금확인 | 8 | 입금 매칭 완료 |
| `ready-to-ship` | 배송준비중 | 112 | |
| `delivered` | 배송완료 | 1,083 | |
| `completed` · `cancelled` · `refunded` · `checkout-draft` | 완료됨 · 취소됨 · 환불됨 · 임시글 | | WooCommerce 기본 |

플러그인 설명에 있는 "입금전"·"확인필요" 상태는 **실제로는 없다.** 입금 전 = `on-hold`.

WooCommerce 는 기본적으로 `pending`·`failed` 에만 취소 링크를 그리므로 `on-hold` 주문에
버튼이 없었다. `duckhoo-redesign.php` 의 `woocommerce_valid_order_statuses_for_cancel`
필터로 **`on-hold`** 를 열었다 (혹시 나중에 "입금전" 이름표가 생기면 그것도 잡는다).
`payment-confirmed` 이후는 열지 않는다 — 환불이 남아 사람이 봐야 한다.

주의: `on-hold` 는 "입금 문자가 왔는데 이름이 안 맞는" 경우도 포함할 수 있다
(확인필요 상태가 따로 없으므로). 그때 고객이 취소하면 환불이 남는다. 이건 운영 판단이다.

### 회원탈퇴 — 버튼만 있고 뒤가 없었다. 만들어 넣었다

`/membership-cancel/` (page 521) 은 내용이 `/` 뿐이지만, 테마의 마이페이지 템플릿이
자기 "회원탈퇴 신청" 상자를 그린다 (로그인 시 · 비로그인은 302). 그 버튼은
`admin-ajax.php?action=wd_membership_cancel` 을 부르는데 **서버에 그 액션이 없다** —
로그인 상태로 틀린 논스를 보내면 없는 액션과 똑같이 `400 "0"` 이 온다. 그래서 눌러도
"오류가 발생했습니다" 만 떴다.

`includes/front.php` 가 이 페이지도 `template_include` 로 우리 껍데기에 그리고
(`templates/page-membership-cancel.php`), `includes/membership-cancel.php` 가 폼과
처리를 맡는다. 테마의 상자와 JS 는 그 템플릿에 있어서 아예 안 나온다.

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
- **주문서에서 테마 스크립트가 죽는다.** `assets/js/wd-checkout-custom.js:185` 가 래퍼 밖에서
  `$` 를 쓰는데 워드프레스는 `jQuery` 만 준다 (`$ is not a function`). 그 줄부터 안 걸리므로
  **쿠폰 카드 체크박스가 아무 일도 하지 않는다.** 테마 파일이라 우리가 고치지 않았다.
  플러그인에서 고치려면 테마 스크립트보다 먼저 `window.$ = window.jQuery` 를 넣으면 된다
  (이 사이트에서 `$` 를 쓰는 다른 스크립트는 없다). 사장님 판단이 필요하다

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
3. 히어로 2장: 왼쪽 흰 카드는 **묶음 상품 슬라이드**(최대 5장, 이전/다음 · 점 · 멈춤 버튼,
   손가락 넘김, 5초 자동 넘김 — 마우스·포커스가 오면 서고 `prefers-reduced-motion` 이면 안 돈다),
   오른쪽 하늘색 그라데이션 "9월 특가"
4. **오늘의 특가** 회색 패널 — 마감 카운트다운(9/30 23:59 KST 까지 1초 단위) + 가로 카드
5. **지금 고르세요** — 가운데 큰 제목 + 4열 그리드 12개 + 더 보기 (필터 알약 3개는 걷어냈다)
6. **브랜드로 둘러보기** — 종수 · 2×2 썸네일 카드 3장. **'공식' 이라는 말을 쓰지 않는다**
7. 이번 달 볼 것 — 커버플로우 (사용자가 요청한 21st.dev 컴포넌트)
8. 검은 배너
9. 검은 푸터(소개 · 카카오톡 채널 · 링크 4열 · 법적 고지) — **모든 화면에** 붙는다.
   `route()` 가 어느 화면이든 `html + footer()` 로 그린다. 화면 함수 안에 넣지 않는다

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
