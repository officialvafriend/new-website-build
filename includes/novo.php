<?php
/**
 * 노보 물량 이벤트 — 가격 · 하루 구매 한도 · 남은 수량.
 *
 * 노보 액상은 들어오는 물량이 정해져 있다. 한 사람이 하루에 다 쓸어 가면 다음 손님이
 * 살 것이 없고, 그러면 그 사람은 다시 오지 않는다. 그래서 세 가지를 한다.
 *
 * 1. **가격** — 라인별로 정해진 이벤트 가격을 여기에 적어 둔다. 다만 실제 판매가는
 *    워드커머스가 쥐고 있다. 여기 값은 `도구 → 노보 이벤트` 화면이 대조하고 적용하는
 *    기준일 뿐, 화면에 찍는 가격을 덮어쓰지 않는다. 주문 · 정산이 보는 값과
 *    손님이 보는 값이 갈리면 안 된다.
 * 2. **하루 구매 한도** — 한 사람이 하루에 살 수 있는 병 수를 센다. 담을 때 ·
 *    장바구니에서 · 결제 직전 세 곳에서 본다. **폼 필드 · 데이터 경로는 건드리지 않는다** —
 *    워드커머스가 내준 검증 훅만 쓴다.
 * 3. **남은 수량** — 워드커머스 재고 관리가 켜진 상품에 한해 남은 개수를 그린다.
 *    숫자를 우리가 만들지 않는다. 관리자에서 넣은 재고를 그대로 보여 준다.
 *
 * 병 수는 상품 이름에서 읽는다 (`Front\per_bottle()`). "10+1" 은 11병, "10병" 은 10병,
 * 이름에 수가 없으면 1병이다. 이 가게의 상품 이름 규칙이 그렇게 되어 있다.
 *
 * @package Duckhoo\Redesign
 */

declare( strict_types = 1 );

namespace Duckhoo\Redesign\Novo;

defined( 'ABSPATH' ) || exit;

/**
 * 이벤트 설정. 값을 바꿀 일이 생기면 여기 한 곳이다.
 *
 * lines 의 single · bundle 은 **관리자 화면이 대조 · 적용하는 기준 가격**이다.
 * 화면에 찍히는 가격은 언제나 워드커머스가 쥔 실제 판매가다.
 *
 * @return array{on:bool,cat:string,limit:int,scope:string,set:int,lines:array<string,array{label:string,single:int,bundle:int}>}
 */
function config(): array {
	return (array) apply_filters(
		'duckhoo_novo_event',
		array(
			'on'    => true,
			'cat'   => 'novo-liquid',
			// 한 사람이 하루에 살 수 있는 병 수 — **값을 치르는 병**만 센다.
			// 낱병 10병도, 10+1 묶음 한 세트도 똑같이 10이다 (사은품 1병은 세지 않는다).
			'limit' => 10,
			// 'all' = 노보 전체를 합쳐 10병. 'line' = 일반 10병 · 블랙 10병 따로.
			'scope' => 'all',
			// 묶음 한 세트가 실제로 받는 병 수 (10+1). 가격 대조에 쓴다.
			'set'   => 11,
			'lines' => array(
				// 블랙을 먼저 본다 — "[노보 블랙]" 은 "[노보" 로도 걸리기 때문이다.
				'black' => array( 'label' => '노보 블랙', 'single' => 13500, 'bundle' => 130000 ),
				'plain' => array( 'label' => '노보', 'single' => 13000, 'bundle' => 120000 ),
			),
		)
	);
}

/**
 * 이벤트가 켜져 있는가.
 *
 * @return bool
 */
function on(): bool {
	$c = config();
	return ! empty( $c['on'] );
}

/**
 * 하루 한도 — 값을 치르는 병 수.
 *
 * @return int
 */
function limit(): int {
	return max( 0, (int) config()['limit'] );
}

/**
 * 노보 상품인가. 분류가 먼저고, 분류가 빠진 상품을 위해 이름도 본다.
 *
 * @param \WC_Product|null $p 상품.
 * @return bool
 */
function is_novo( $p ): bool {
	if ( ! $p instanceof \WC_Product ) {
		return false;
	}
	$cat = (string) config()['cat'];
	if ( '' !== $cat && function_exists( 'has_term' ) && has_term( $cat, 'product_cat', $p->get_id() ) ) {
		return true;
	}
	return '' !== line( $p );
}

/**
 * 어느 라인인가 — 'black' · 'plain' · ''.
 *
 * 이름 앞 `[노보 블랙]` · `[노보]` 로 가른다. 이 가게는 브랜드 분류가 없어 이름이 유일한 단서다.
 *
 * @param \WC_Product|null $p 상품.
 * @return string
 */
function line( $p ): string {
	if ( ! $p instanceof \WC_Product ) {
		return '';
	}
	$name = (string) $p->get_name();
	// 블랙을 먼저 본다. 순서가 바뀌면 "노보 블랙" 이 "노보" 로 잡힌다.
	if ( preg_match( '/노보\s*블랙/u', $name ) ) {
		return 'black';
	}
	if ( preg_match( '/노보/u', $name ) ) {
		return 'plain';
	}
	return '';
}

/**
 * 이 상품 한 개가 몇 병인가. 노보가 아니면 0.
 *
 * @param \WC_Product|null $p 상품.
 * @return int
 */
function bottles( $p ): int {
	if ( ! is_novo( $p ) ) {
		return 0;
	}
	$pb = \Duckhoo\Redesign\Front\per_bottle( $p );
	return max( 1, (int) $pb['qty'] );
}

/**
 * 한도를 셀 때의 병 수 — **값을 치르는 병**만 센다.
 *
 * `10+1` 은 10병 값을 내고 11병을 받는다. 사은품 1병까지 세면 낱병 10병을 산 손님과
 * 한 세트를 산 손님의 하루치가 달라진다. 그래서 세는 쪽은 앞의 수만 본다:
 * `10+1` → 10, `10병` → 10, 단품 → 1.
 *
 * 화면에 적는 병 수(총 11병)는 `bottles()` 가 그대로 쓴다. 두 값은 다르다.
 *
 * @param \WC_Product|null $p 상품.
 * @return int
 */
function paid( $p ): int {
	if ( ! is_novo( $p ) ) {
		return 0;
	}
	$name = (string) $p->get_name();
	// 기기 · 증정 구성은 병이 구매 단위가 아니다 — per_bottle() 과 같은 판단.
	if ( preg_match( '/증정|사은품|기기|디바이스|스타터/u', $name ) ) {
		return 1;
	}
	if ( preg_match( '/(\d+)\s*\+\s*\d+/u', $name, $m ) ) {
		return max( 1, (int) $m[1] );
	}
	if ( preg_match( '/(\d+)\s*병/u', $name, $m ) ) {
		return max( 1, (int) $m[1] );
	}
	return 1;
}

/**
 * 테마 옵션 UI 가 적어 둔 「이 줄이 몇 개인가」.
 *
 * **이 사이트에서 수량은 장바구니 수량이 아니다.** 테마의 `wd-option-builder` 는
 * `form.cart` 의 quantity 를 1 로 둔 채 실제 개수를 `wd_option_builder_json` 에 적는다.
 * 확인한 값(단품 2병):
 *
 *     quantity=1, wd_option_builder_json=[{"label":"…","qty":2,"type":"required"}]
 *     장바구니 줄: 수량 1 · 금액 27,000원 (13,500 × 2)
 *
 * 그래서 장바구니 수량만 세면 2병을 1병으로 본다. 12병이 한도를 지나간 길이 이것이다.
 *
 * `type:required` 의 합이 **이 상품의 기본 단위 개수**다 — 단품이면 병 수,
 * 묶음이면 세트 수. 맛 선택 같은 addon 줄은 그 안에 딸린 것이라 세지 않는다.
 *
 * @param string $json 옵션 JSON.
 * @return int 1 이상.
 */
function units_from_json( string $json ): int {
	if ( '' === $json || false === strpos( $json, 'group_key' ) ) {
		return 1;
	}
	$rows = json_decode( $json, true );
	if ( ! is_array( $rows ) ) {
		return 1;
	}
	$n = 0;
	foreach ( $rows as $r ) {
		if ( is_array( $r ) && 'required' === ( $r['type'] ?? '' ) ) {
			$n += (int) ( $r['qty'] ?? 0 );
		}
	}
	return $n > 0 ? $n : 1;
}

/**
 * 배열(장바구니 줄 · POST) 안에서 옵션 JSON 을 찾아 개수를 읽는다.
 *
 * 키 이름을 못 박지 않는다 — 테마가 바꾸면 조용히 틀리기 때문이다. 값의 생김새로 찾는다.
 *
 * @param array<mixed> $bag 장바구니 줄 또는 $_POST.
 * @return int 1 이상.
 */
function units_in( array $bag ): int {
	foreach ( $bag as $v ) {
		if ( is_string( $v ) && false !== strpos( $v, 'group_key' ) ) {
			return units_from_json( $v );
		}
	}
	return 1;
}

/**
 * 주문 줄에서 같은 값을 읽는다. 테마가 주문 아이템 메타로 옮겨 적는다.
 *
 * @param object $item 주문 줄.
 * @return int 1 이상.
 */
function units_in_order_item( $item ): int {
	if ( ! is_object( $item ) || ! method_exists( $item, 'get_meta_data' ) ) {
		return 1;
	}
	foreach ( (array) $item->get_meta_data() as $m ) {
		$d = method_exists( $m, 'get_data' ) ? (array) $m->get_data() : array();
		$v = $d['value'] ?? '';
		if ( is_string( $v ) && false !== strpos( $v, 'group_key' ) ) {
			return units_from_json( $v );
		}
	}
	return 1;
}

/**
 * 오늘의 시작(사이트 시간대 자정) 타임스탬프.
 *
 * @return int
 */
function day_start(): int {
	$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'Asia/Seoul' );
	return ( new \DateTimeImmutable( 'today 00:00:00', $tz ) )->getTimestamp();
}

/**
 * 한도를 세지 않는 주문 상태. 취소 · 환불 · 실패는 물량을 잡아 두지 않는다.
 *
 * @return string[]
 */
function free_statuses(): array {
	return (array) apply_filters( 'duckhoo_novo_free_statuses', array( 'cancelled', 'refunded', 'failed', 'checkout-draft', 'trash' ) );
}

/**
 * 오늘 이 회원이 주문한 노보 병 수 — 라인별.
 *
 * @param int $uid 회원 ID.
 * @return array<string,int>
 */
function tally_orders( int $uid ): array {
	// 한 요청에서 여러 번 묻는다 (안내 · 담기 · 결제). 주문 조회는 한 번이면 된다.
	static $memo = array();
	if ( isset( $memo[ $uid ] ) ) {
		return $memo[ $uid ];
	}

	$out = array( 'plain' => 0, 'black' => 0 );
	if ( $uid <= 0 || ! function_exists( 'wc_get_orders' ) ) {
		return $out;
	}

	// **같은 휴대폰번호를 쓰는 계정은 한 사람으로 본다.** 한도가 계정 기준이면 계정을
	// 하나 더 만드는 것으로 지나갈 수 있다. 번호가 없으면 이 계정만 센다.
	$who = array( $uid );
	if ( apply_filters( 'duckhoo_novo_count_by_phone', true ) && function_exists( 'Duckhoo\\Redesign\\Signup\\phone_of' ) ) {
		$phone = \Duckhoo\Redesign\Signup\phone_of( $uid );
		if ( '' !== $phone ) {
			$group = \Duckhoo\Redesign\Signup\users_with_phone( $phone );
			if ( $group ) {
				$who = array_values( array_unique( array_merge( $who, $group ) ) );
			}
		}
	}

	$statuses = array();
	if ( function_exists( 'wc_get_order_statuses' ) ) {
		$free = free_statuses();
		foreach ( array_keys( wc_get_order_statuses() ) as $s ) {
			$s = 0 === strpos( (string) $s, 'wc-' ) ? substr( (string) $s, 3 ) : (string) $s;
			if ( ! in_array( $s, $free, true ) ) {
				$statuses[] = $s;
			}
		}
	}

	$orders = wc_get_orders(
		array(
			'customer_id'  => 1 === count( $who ) ? $who[0] : $who,
			'limit'        => 60 * count( $who ),
			'status'       => $statuses ? $statuses : 'any',
			'date_created' => '>=' . day_start(),
			'return'       => 'objects',
		)
	);
	if ( ! is_array( $orders ) ) {
		return $out;
	}

	foreach ( $orders as $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) {
			continue;
		}
		foreach ( $order->get_items() as $item ) {
			$p = method_exists( $item, 'get_product' ) ? $item->get_product() : null;
			$n = paid( $p );
			if ( $n > 0 ) {
				$k = line( $p );
				$out[ '' !== $k ? $k : 'plain' ] += $n * units_in_order_item( $item ) * max( 1, (int) $item->get_quantity() );
			}
		}
	}
	$memo[ $uid ] = $out;
	return $out;
}

/**
 * 지금 장바구니에 담긴 노보 병 수 — 라인별.
 *
 * @param string $skip_key 빼고 셀 장바구니 줄 (수량 변경 중인 줄).
 * @return array<string,int>
 */
function tally_cart( string $skip_key = '' ): array {
	$out = array( 'plain' => 0, 'black' => 0 );
	if ( ! function_exists( 'WC' ) ) {
		return $out;
	}
	$wc = WC();
	if ( ! isset( $wc->cart ) || ! is_object( $wc->cart ) || ! method_exists( $wc->cart, 'get_cart' ) ) {
		return $out;
	}
	foreach ( (array) $wc->cart->get_cart() as $ck => $item ) {
		if ( '' !== $skip_key && (string) $ck === $skip_key ) {
			continue; // 수량을 고치는 중인 줄은 빼고 센다 — 자기 자신과 겨루면 안 된다.
		}
		$p = $item['data'] ?? null;
		$n = paid( $p );
		if ( $n > 0 ) {
			$k = line( $p );
			$out[ '' !== $k ? $k : 'plain' ] += $n * units_in( (array) $item ) * max( 1, (int) ( $item['quantity'] ?? 0 ) );
		}
	}
	return $out;
}

/**
 * 두 집계를 더한다.
 *
 * @param array<string,int> $a 하나.
 * @param array<string,int> $b 둘.
 * @return array<string,int>
 */
function add_tally( array $a, array $b ): array {
	foreach ( $b as $k => $v ) {
		$a[ $k ] = ( $a[ $k ] ?? 0 ) + (int) $v;
	}
	return $a;
}

/**
 * 지금까지 쓴 양 — 오늘 주문 + 장바구니. scope 에 따라 합계 또는 라인별.
 *
 * @param string $line     라인.
 * @param string $skip_key 빼고 셀 장바구니 줄.
 * @return int
 */
function used_now( string $line = '', string $skip_key = '' ): int {
	$uid   = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	$tally = add_tally( tally_orders( $uid ), tally_cart( $skip_key ) );
	return 'line' === (string) config()['scope'] && '' !== $line
		? (int) ( $tally[ $line ] ?? 0 )
		: (int) array_sum( $tally );
}

/**
 * 이 상품을 지금 몇 개까지 담을 수 있나. 노보가 아니면 -1 (우리가 정할 것이 없다).
 *
 * @param \WC_Product|null $product  상품.
 * @param string           $skip_key 빼고 셀 장바구니 줄.
 * @return int
 */
function max_units( $product, string $skip_key = '' ): int {
	$each = paid( $product );
	if ( $each <= 0 ) {
		return -1;
	}
	$room = max( 0, limit() - used_now( line( $product ), $skip_key ) );
	return (int) floor( $room / $each );
}

/**
 * 오늘 이 손님이 더 담을 수 있는 병 수.
 *
 * scope 가 'line' 이면 그 라인만 세고, 'all' 이면 노보 전체를 합쳐 센다.
 *
 * @param string   $line  라인 ('plain' · 'black' · '').
 * @param int|null $uid   회원 ID. null 이면 현재 로그인 사용자.
 * @param bool     $cart  장바구니에 담긴 것도 뺄지.
 * @return int
 */
function left( string $line = '', ?int $uid = null, bool $cart = true ): int {
	if ( ! on() ) {
		return PHP_INT_MAX;
	}
	$uid   = null === $uid ? ( function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0 ) : $uid;
	$tally = tally_orders( $uid );
	if ( $cart ) {
		$tally = add_tally( $tally, tally_cart() );
	}
	$used = 'line' === (string) config()['scope'] && '' !== $line
		? (int) ( $tally[ $line ] ?? 0 )
		: (int) array_sum( $tally );

	return max( 0, limit() - $used );
}

/* ── 한도 지키기 ───────────────────────────────────────────────────────────
   워드커머스가 내준 검증 훅만 쓴다. 담기는 값을 바꾸지 않고, 넘칠 때 멈추고 말한다. */

/**
 * 규칙 한 줄. 화면과 거절 안내가 같은 말을 쓴다.
 *
 * @return string
 */
function rule(): string {
	return sprintf(
		'노보 액상은 물량이 넉넉하지 않아 한 분당 하루 %d병까지 살 수 있습니다 (10+1 묶음은 한 세트가 하루치이고, 사은품 1병은 세지 않습니다).',
		limit()
	);
}

/**
 * 한도를 넘었을 때 손님에게 하는 말.
 *
 * @param int $left 더 담을 수 있는 병 수.
 * @param int $need 담으려는 병 수.
 * @return string
 */
function over_message( int $left, int $need = 0 ): string {
	if ( $left <= 0 ) {
		return rule() . ' 오늘 살 수 있는 수량을 이미 다 담으셨습니다. 내일 다시 담아 주세요.';
	}
	return rule() . sprintf(
		' 오늘 %d병까지 더 담을 수 있습니다%s.',
		$left,
		$need > 0 ? sprintf( ' (담으려던 것은 %d병)', $need ) : ''
	);
}

/**
 * 담기 전에 본다.
 *
 * @param bool $passed 여태 판정.
 * @param int  $pid    상품 ID.
 * @param int  $qty    수량.
 * @return bool
 */
function validate_add( $passed, $pid = 0, $qty = 1 ): bool {
	if ( ! $passed || ! on() || ! function_exists( 'wc_get_product' ) ) {
		return (bool) $passed;
	}
	$p    = wc_get_product( (int) $pid );
	$each = paid( $p );
	if ( $each <= 0 ) {
		return true;
	}
	// 담기 요청에도 같은 값이 실려 온다. 장바구니 수량만 보면 12병이 1병으로 보인다.
	// phpcs:ignore WordPress.Security.NonceVerification
	$need = $each * units_in( (array) $_POST ) * max( 1, (int) $qty );
	$left = left( line( $p ) );
	if ( $need > $left ) {
		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( over_message( $left, $need ), 'error' );
		}
		return false;
	}
	return true;
}

/**
 * 지금 한도를 넘었는가. 넘었으면 할 말을 돌려준다.
 *
 * @return string[]
 */
function over_messages(): array {
	if ( ! on() ) {
		return array();
	}
	$out = array();
	$lim = limit();

	if ( 'line' === (string) config()['scope'] ) {
		foreach ( config()['lines'] as $key => $meta ) {
			$used = used_now( (string) $key );
			if ( $used > $lim ) {
				$out[] = sprintf( '%s 액상이 하루 한도(%d병)를 넘었습니다. 지금 %d병입니다. 수량을 줄여 주세요.', (string) $meta['label'], $lim, $used );
			}
		}
		return $out;
	}

	$used = used_now();
	if ( $used > $lim ) {
		$out[] = sprintf( '노보 액상이 하루 한도(%d병)를 넘었습니다. 지금 %d병입니다. 수량을 줄여 주세요.', $lim, $used );
	}
	return $out;
}

/**
 * 장바구니 화면 · 결제 화면에서 다시 본다. 수량을 손으로 고칠 수 있기 때문이다.
 *
 * @return void
 */
function check_cart(): void {
	if ( ! function_exists( 'wc_add_notice' ) ) {
		return;
	}
	foreach ( over_messages() as $m ) {
		wc_add_notice( $m, 'error' );
	}
}

/**
 * **마지막 빗장 — 주문이 만들어지기 직전.**
 *
 * 담기만 막아서는 새지 않을 수가 없다. 이 사이트의 장바구니는 수량을 Store API
 * `update-item` 으로 고치는데, 그 길에는 `woocommerce_add_to_cart_validation` 이
 * 걸리지 않는다 (실제로 10 → 11 이 통과했다). 어느 길로 왔든 주문은 여기를 지난다.
 *
 * 던진 예외는 워드커머스가 잡아 결제 화면의 오류로 보여 준다. **담기는 데이터에는
 * 손대지 않는다** — 무엇이 얼마인지만 말하고 멈춘다.
 *
 * @throws \Exception 한도를 넘었을 때.
 * @return void
 */
function guard_order(): void {
	$over = over_messages();
	if ( $over ) {
		throw new \Exception( esc_html( $over[0] ) );
	}
}

/**
 * **여기서 예외를 던지지 않는다.**
 *
 * `woocommerce_store_api_validate_cart_items` 는 장바구니를 **읽을 때도** 돈다.
 * 한도를 넘은 장바구니에서 예외를 던졌더니 `GET /wc/store/v1/cart` 가 통째로
 * 실패했고, 테마의 결제 요약 스크립트가 금액을 못 읽어 **총 주문금액 0원**을 그렸다
 * (프로덕션에서 사장님이 보셨다). 막는 것은 상한(`store_max`)과 주문 만들기 직전
 * (`guard_order`)이 한다 — 그 둘은 읽기를 깨뜨리지 않는다.
 */

/**
 * Store API 가 이 상품의 최대 수량을 물을 때. 수량 변경(`update-item`)이 여기를 지난다.
 *
 * 지금 고치는 줄은 빼고 세야 자기 자신과 겨루지 않는다. 그 줄을 알 수 없으면
 * 손대지 않는다 — 이미 담긴 수량보다 낮은 상한을 돌려주면 장바구니가 열리지 않는다.
 *
 * @param mixed $max       여태 상한.
 * @param mixed $product   상품.
 * @param mixed $cart_item 장바구니 줄.
 * @return mixed
 */
function store_max( $max, $product = null, $cart_item = null ) {
	if ( ! on() || ! $product instanceof \WC_Product || ! is_array( $cart_item ) || empty( $cart_item['key'] ) ) {
		return $max;
	}
	$mine = max_units( $product, (string) $cart_item['key'] );
	if ( $mine < 0 ) {
		return $max;
	}
	return null === $max || '' === $max ? $mine : min( (int) $max, $mine );
}

if ( function_exists( 'add_filter' ) ) {
	add_filter( 'woocommerce_add_to_cart_validation', __NAMESPACE__ . '\\validate_add', 20, 3 );
	add_action( 'woocommerce_check_cart_items', __NAMESPACE__ . '\\check_cart' );
	add_action( 'woocommerce_checkout_process', __NAMESPACE__ . '\\check_cart' );
	// 수량 변경은 담기 검증을 지나지 않는다 — Store API 쪽에도 같은 상한을 준다.
	add_filter( 'woocommerce_store_api_product_quantity_maximum', __NAMESPACE__ . '\\store_max', 10, 3 );
	// 어느 길로 왔든 주문은 여기를 지난다.
	add_action( 'woocommerce_checkout_create_order', __NAMESPACE__ . '\\guard_order', 5 );
}

/* ── 화면 ─────────────────────────────────────────────────────────────────
   폼 안에는 아무것도 넣지 않는다. 이 사이트의 구매 게이트는 마크업의 글자를 읽어
   칸 이름을 정하기 때문에, form.cart 안에 글자가 늘면 판정이 어긋난다. */

/**
 * 남은 재고. 워드커머스 재고 관리가 켜진 상품만 숫자가 있다.
 *
 * @param \WC_Product|null $p 상품.
 * @return int|null
 */
function stock_left( $p ): ?int {
	if ( ! $p instanceof \WC_Product || ! $p->managing_stock() ) {
		return null;
	}
	$n = $p->get_stock_quantity();
	return null === $n ? null : max( 0, (int) $n );
}

/**
 * 상품 상세 — 가격 아래 한도 · 남은 수량 안내. `form.cart` 바깥이다.
 *
 * @param \WC_Product|null $p 상품.
 * @return void
 */
function product_notice( $p = null ): void {
	if ( ! on() || ! is_novo( $p ) ) {
		return;
	}
	$lim   = limit();
	$stock = stock_left( $p );
	$each  = bottles( $p );
	$cost  = paid( $p );

	echo '<div class="dhp-novo">';
	echo '<b class="dhp-novo__t">노보 하루 구매 한도 <span>' . (int) $lim . '병</span></b>';
	echo '<p class="dhp-novo__p">물량이 넉넉하지 않습니다. 낱병은 하루 ' . (int) $lim . '병까지, 10+1 묶음은 한 세트까지 사실 수 있습니다.</p>';

	echo '<div class="dhp-novo__rows">';
	if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
		echo '<div class="dhp-novo__row"><span>오늘 남은 구매 가능</span><b>' . (int) left( line( $p ) ) . '병</b></div>';
	} else {
		echo '<div class="dhp-novo__row"><span>오늘 남은 구매 가능</span><b>로그인 후 확인</b></div>';
	}
	if ( null !== $stock ) {
		echo '<div class="dhp-novo__row"><span>남은 재고</span><b>' . (int) $stock . '개</b></div>';
	}
	if ( apply_filters( 'duckhoo_novo_exclude_from_discount', true ) ) {
		// 장바구니에 가서야 알면 늦다. 여기서 미리 말한다.
		echo '<div class="dhp-novo__row"><span>금액대별 자동 할인</span><b>제외</b></div>';
	}
	if ( $each > 1 ) {
		// 10+1 은 11병을 받고 10병으로 센다. 손님이 계산기를 두드리지 않게 둘 다 적는다.
		echo '<div class="dhp-novo__row"><span>이 상품 한 세트</span><b>' . (int) $each . '병'
			. ( $cost !== $each ? ' <em>(' . (int) $cost . '병으로 셈)</em>' : '' ) . '</b></div>';
	}
	echo '</div></div>';
}
add_action( 'duckhoo_product_after_price', __NAMESPACE__ . '\\product_notice' );

/**
 * 목록 카드 — 남은 재고가 알려진 노보 상품에만 한 줄.
 *
 * @param string           $extra 여태 붙은 것.
 * @param \WC_Product|null $p     상품.
 * @return string
 */
function card_note( string $extra, $p = null ): string {
	if ( ! on() || ! is_novo( $p ) || ! $p->is_in_stock() ) {
		return $extra;
	}
	$cap   = bottles( $p ) > 1 ? '하루 한 세트' : '하루 ' . limit() . '병까지';
	$stock = stock_left( $p );
	return $extra . '<div class="nlimit">'
		. ( null === $stock ? '' : '<b>남은 수량 ' . (int) $stock . '개</b> · ' )
		. esc_html( $cap ) . '</div>';
}
add_filter( 'duckhoo_card_extra', __NAMESPACE__ . '\\card_note', 10, 2 );

/**
 * 노보 분류 목록 맨 위의 안내 띠.
 *
 * 사장님이 만든 안내 이미지를 글자로 다시 지었다. 이미지로 넣으면 폰에서 글자가
 * 뭉개지고, 문구를 고칠 때마다 다시 그려야 하고, 검색엔진도 읽지 못한다.
 *
 * 문구는 전부 필터 `duckhoo_novo_banner` 로 바꾼다.
 *
 * @return void
 */
function banner(): void {
	if ( ! on() || ! function_exists( 'is_tax' ) || ! is_tax( 'product_cat', (string) config()['cat'] ) ) {
		return;
	}

	$b = (array) apply_filters(
		'duckhoo_novo_banner',
		array(
			'eb'    => '노보 10+1 구매 제한 안내',
			'head'  => array( '1인 1일 최대 ', '1세트' ),
			'set'   => array( '10병 구매 + 1병 증정', '총 11병 구성' ),
			'lead'  => '한정된 재고를 더 많은 고객님께 제공하기 위해 구매 수량을 제한합니다.',
			// **코드가 실제로 세는 방식과 같은 말이어야 한다.** 회원 계정(= 본인확인
			// 한 사람)으로 세고, 주문이 들어온 날로 센다. 입금 전 주문도 자리를 잡고,
			// 취소하면 그 자리가 풀린다.
			'notes' => array( '동일 본인인증 정보 기준', '주문일 기준 (취소 시 복구)', '초과 주문은 확인 후 취소 · 환불될 수 있습니다' ),
		)
	);

	// 사장님이 만든 이미지가 있으면 그것을 그린다. 없으면 아래 글자판이 대신한다.
	$img = (string) apply_filters( 'duckhoo_novo_banner_image', (string) get_option( 'duckhoo_novo_banner_img', '' ) );
	$imm = (string) apply_filters( 'duckhoo_novo_banner_image_mobile', (string) get_option( 'duckhoo_novo_banner_img_m', '' ) );
	if ( '' !== $img ) {
		$alt = trim( (string) $b['eb'] . ' — ' . (string) $b['head'][0] . (string) ( $b['head'][1] ?? '' ) . '. ' . implode( ' · ', (array) $b['set'] ) . '. ' . (string) $b['lead'] );
		echo '<figure class="nvb-img">';
		if ( '' !== $imm ) {
			echo '<picture>'
				. '<source media="(max-width: 899px)" srcset="' . esc_url( $imm ) . '">'
				. '<img src="' . esc_url( $img ) . '" alt="' . esc_attr( $alt ) . '" loading="eager" decoding="async">'
				. '</picture>';
		} else {
			echo '<img src="' . esc_url( $img ) . '" alt="' . esc_attr( $alt ) . '" loading="eager" decoding="async">';
		}
		// 이미지 속 글자는 화면에만 있다. 읽어 주는 기계와 검색엔진을 위해 같은 말을 남긴다.
		echo '<figcaption class="nvb-img__cap">' . esc_html( implode( ' · ', (array) $b['notes'] ) ) . '</figcaption>';
		echo '</figure>';
		return;
	}

	echo '<section class="nvb" aria-labelledby="nvb-h">';
	if ( '' !== (string) $b['eb'] ) {
		echo '<p class="nvb__eb">' . esc_html( (string) $b['eb'] ) . '</p>';
	}
	echo '<h2 class="nvb__h" id="nvb-h">' . esc_html( (string) $b['head'][0] )
		. '<mark>' . esc_html( (string) ( $b['head'][1] ?? '' ) ) . '</mark></h2>';

	if ( $b['set'] ) {
		echo '<p class="nvb__set">';
		foreach ( (array) $b['set'] as $i => $row ) {
			echo ( $i ? '<i aria-hidden="true"></i>' : '' ) . '<span>' . esc_html( (string) $row ) . '</span>';
		}
		echo '</p>';
	}
	if ( '' !== (string) $b['lead'] ) {
		echo '<p class="nvb__p">' . esc_html( (string) $b['lead'] ) . '</p>';
	}
	if ( $b['notes'] ) {
		echo '<ul class="nvb__notes">';
		foreach ( (array) $b['notes'] as $n ) {
			echo '<li>' . esc_html( (string) $n ) . '</li>';
		}
		echo '</ul>';
	}
	echo '</section>';
}
add_action( 'duckhoo_archive_before_grid', __NAMESPACE__ . '\\banner' );

/* ── 금액대별 자동 할인에서 노보를 뺀다 ───────────────────────────────────────
   노보는 물량이 모자라 값을 올린 상품이다. 거기에 10만원↑ 1만원 할인까지 얹히면
   한 사람이 싸게 쓸어 가는 것을 우리가 거들게 된다.

   **할인 자체를 없애지 않는다.** 노보 금액만 기준에서 빼고, 나머지 상품 금액이
   기준을 넘으면 할인은 그대로 붙는다. 노보 한 병 담았다고 다른 상품의 할인까지
   사라지면 그건 손님에게 벌을 주는 것이다.

   할인은 쿠폰 플러그인이 수수료 줄(`🎁 금액 자동 할인`, 음수)로 붙인다. 우리는
   그 줄을 뒤늦게(우선순위 99) 다시 셈해 고치거나 뺀다. 담기는 상품 데이터에는
   손대지 않는다 — 금액 줄 하나만 만진다. */

/**
 * 이 수수료 줄이 금액대별 자동 할인인가.
 *
 * @param object $fee 수수료 줄.
 * @return bool
 */
function is_auto_discount( $fee ): bool {
	$name = (string) ( $fee->name ?? '' );
	$amt  = (float) ( $fee->amount ?? 0 );
	return $amt < 0 && (bool) preg_match( (string) apply_filters( 'duckhoo_auto_discount_fee', '/자동\s*할인/u' ), $name );
}

/**
 * 장바구니의 노보 금액과 전체 금액.
 *
 * 수수료를 셈하는 시점에는 `line_subtotal` 이 아직 없을 수 있어 판매가 × 수량으로 센다.
 *
 * @return array{novo:float,all:float}
 */
function cart_money(): array {
	$novo = 0.0;
	$all  = 0.0;
	if ( ! function_exists( 'WC' ) ) {
		return array( 'novo' => 0.0, 'all' => 0.0 );
	}
	$wc = WC();
	if ( ! isset( $wc->cart ) || ! is_object( $wc->cart ) || ! method_exists( $wc->cart, 'get_cart' ) ) {
		return array( 'novo' => 0.0, 'all' => 0.0 );
	}
	foreach ( (array) $wc->cart->get_cart() as $item ) {
		$p = $item['data'] ?? null;
		if ( ! $p instanceof \WC_Product ) {
			continue;
		}
		$line = (float) $p->get_price() * (int) ( $item['quantity'] ?? 0 );
		$all += $line;
		if ( is_novo( $p ) ) {
			$novo += $line;
		}
	}
	return array( 'novo' => $novo, 'all' => $all );
}

/**
 * 자동 할인을 노보 뺀 금액으로 다시 셈한다.
 *
 * @return void
 */
function adjust_fees(): void {
	if ( ! on() || ! apply_filters( 'duckhoo_novo_exclude_from_discount', true ) || ! function_exists( 'WC' ) ) {
		return;
	}
	$wc = WC();
	if ( ! isset( $wc->cart ) || ! is_object( $wc->cart ) || ! method_exists( $wc->cart, 'fees_api' ) ) {
		return;
	}

	$money = cart_money();
	if ( $money['novo'] <= 0 ) {
		return; // 노보가 없으면 우리가 손댈 것이 없다.
	}

	$api  = $wc->cart->fees_api();
	$fees = $api->get_fees();
	if ( ! $fees ) {
		return;
	}

	$want = \Duckhoo\Redesign\Front\discount_for( max( 0.0, $money['all'] - $money['novo'] ) );

	// **줄을 지웠다 다시 붙이지 않는다.** `remove_all_fees()` + `add_fee()` 는 쿠폰
	// 플러그인이 그 줄에 달아 둔 값을 잃는다. 금액만 제자리에서 고친다 —
	// 워드커머스는 합계를 낼 때 `amount` 를 읽는다.
	foreach ( $fees as $fee ) {
		if ( ! is_auto_discount( $fee ) ) {
			continue;
		}
		$new = -1 * (float) $want;
		if ( (float) $fee->amount !== $new ) {
			$fee->amount = $new;
			if ( property_exists( $fee, 'total' ) ) {
				$fee->total = $new;
			}
		}
	}
}

/**
 * 장바구니 안내 문구에 붙는 예외 표시.
 *
 * @param string $ex 여태 값.
 * @return string
 */
function discount_except( $ex ): string {
	if ( ! on() || ! apply_filters( 'duckhoo_novo_exclude_from_discount', true ) ) {
		return (string) $ex;
	}
	return '' === (string) $ex ? '노보 액상 제외' : $ex . ' · 노보 액상 제외';
}

if ( function_exists( 'add_action' ) ) {
	// 쿠폰 플러그인이 줄을 붙인 뒤에 본다.
	add_action( 'woocommerce_cart_calculate_fees', __NAMESPACE__ . '\\adjust_fees', 99 );
	add_filter( 'duckhoo_auto_discount_except', __NAMESPACE__ . '\\discount_except' );
}
