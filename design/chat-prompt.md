# chat 에 붙여넣을 첫 메시지

아래를 그대로 복사해 새 대화의 첫 메시지로 보낸다. 그 다음 메시지로 `design/handoff.md` 전문을 붙여넣는다.

---

나는 전자담배 액상 쇼핑몰 **액상덕후**(https://duck-hoo.com, WordPress.com Atomic + WooCommerce)를 운영한다.
UI/UX 리디자인 데모가 완성돼 있고, 이걸 실제 사이트에 옮기는 일을 너와 한다.

- 데모: https://claude.ai/code/artifact/52d3da49-2f39-4934-aa66-1e2708e817b7 — 12개 화면이 전부 눌린다. 이 구조와 규칙 그대로 옮긴다
- 저장소: https://github.com/officialvafriend/new-website-build 브랜치 `claude/ui-ux-design-refresh-p5czcx`
- 작업은 **스테이징**(https://staging-fe60-tothemoone-huwyp.wpcomstaging.com)에서 먼저 한다. 프로덕션의 **동기화 버튼은 절대 쓰지 않는다**

먼저 지킬 것:
1. **UI/UX 만 바꾼다.** 성인인증(dreamsecurity), 주문 상태 · 입금 자동확인 · 고객관리(keyple 플러그인들), 폼 필드의 name/id, 결제·장바구니 데이터 경로는 건드리지 않는다
2. 결제는 무통장입금 전용이다. 입금자명 = 주문자명이어야 자동 확인된다. 주문서에서 이 안내가 잘 보여야 한다
3. 모바일 우선, Pretendard, 캡션 13px 이상, 병당 가격을 총액보다 크게, 반투명 금지

순서:
1. 스테이징에 GitHub Deployments 를 연결하고(브랜치 위와 같음, 대상 `/wp-content/plugins/new-website-build`, 자동 배포) 플러그인 "액상덕후 리디자인"을 활성화한다
2. 주문취소 버튼(입금전 주문)과 `/membership-cancel/` 탈퇴 폼이 뜨는지 확인한다
3. `assets/duckhoo-theme.css` 를 추가 CSS 에 붙여넣고 화면을 확인한다
4. 홈을 데모 순서대로 구성한다 (히어로 2장 → 오늘의 특가 → 필터+그리드 → 브랜드 스토어 → 배너 → 푸터)

다음 메시지로 상세 인수인계 문서를 줄게. 읽고 나서 1번부터 시작하자.
