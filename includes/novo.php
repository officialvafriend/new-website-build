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
			'customer_id' => $uid,
			'limit'       => 60,
			'status'      => $statuses ? $statuses : 'any',
			'date_created' => '>=' . day_start(),
			'return'      => 'objects',
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
				$out[ '' !== $k ? $k : 'plain' ] += $n * (int) $item->get_quantity();
			}
		}
	}
	$memo[ $uid ] = $out;
	return $out;
}

/**
 * 지금 장바구니에 담긴 노보 병 수 — 라인별.
 *
 * @return array<string,int>
 */
function tally_cart(): array {
	$out = array( 'plain' => 0, 'black' => 0 );
	if ( ! function_exists( 'WC' ) ) {
		return $out;
	}
	$wc = WC();
	if ( ! isset( $wc->cart ) || ! is_object( $wc->cart ) || ! method_exists( $wc->cart, 'get_cart' ) ) {
		return $out;
	}
	foreach ( (array) $wc->cart->get_cart() as $item ) {
		$p = $item['data'] ?? null;
		$n = paid( $p );
		if ( $n > 0 ) {
			$k = line( $p );
			$out[ '' !== $k ? $k : 'plain' ] += $n * (int) ( $item['quantity'] ?? 0 );
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
	$need = $each * max( 1, (int) $qty );
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
 * 장바구니 화면 · 결제 화면에서 다시 본다. 수량을 손으로 고칠 수 있기 때문이다.
 *
 * @return void
 */
function check_cart(): void {
	if ( ! on() || ! function_exists( 'wc_add_notice' ) ) {
		return;
	}
	$uid   = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	$tally = add_tally( tally_orders( $uid ), tally_cart() );
	$lim   = limit();

	if ( 'line' === (string) config()['scope'] ) {
		foreach ( config()['lines'] as $key => $meta ) {
			$used = (int) ( $tally[ $key ] ?? 0 );
			if ( $used > $lim ) {
				wc_add_notice(
					sprintf( '%s 액상이 하루 한도(%d병)를 넘었습니다. 지금 %d병입니다. 수량을 줄여 주세요.', (string) $meta['label'], $lim, $used ),
					'error'
				);
			}
		}
		return;
	}

	$used = (int) array_sum( $tally );
	if ( $used > $lim ) {
		wc_add_notice(
			sprintf( '노보 액상이 하루 한도(%d병)를 넘었습니다. 지금 %d병입니다. 수량을 줄여 주세요.', $lim, $used ),
			'error'
		);
	}
}

if ( function_exists( 'add_filter' ) ) {
	add_filter( 'woocommerce_add_to_cart_validation', __NAMESPACE__ . '\\validate_add', 20, 3 );
	add_action( 'woocommerce_check_cart_items', __NAMESPACE__ . '\\check_cart' );
	// 마지막 빗장. 담는 데이터에는 손대지 않고 넘치면 멈춘다.
	add_action( 'woocommerce_checkout_process', __NAMESPACE__ . '\\check_cart' );
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
