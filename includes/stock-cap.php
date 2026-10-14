<?php
/**
 * 재고 수량보다 많이 담기는 것을 막는다 (2026-10-14).
 *
 * 사장님: 노보 그린펀치가 1병뿐이라 재고 관리를 켜고 수량 1 을 넣었는데도 여러 병이 담겼다.
 *
 * **원인은 테마 옵션 빌더다** (`wd-option-builder.js`). 옵션이 없는 낱병도 빌더가 「기본 상품」 줄을
 * 만들고 그 줄의 `+` 로 병 수를 올린다. 값은 `기본가 × 그 줄의 수량`으로 매겨지지만 **워드커머스가 보는
 * 장바구니 수량은 1 그대로**라, 워드커머스의 재고 검사(수량 1 ≤ 재고 1)를 그냥 지나간다.
 *
 * 여기서는 줄마다 **실제로 나가는 개수 = 장바구니 수량 × 빌더 「기본 상품」(필수) 줄 수량 합**을 세어
 * 재고 수량과 견준다. 담기 · 장바구니 · 결제 직전 세 곳. **워드커머스가 내준 검증 훅만 쓴다** —
 * 폼 필드 · 담기는 데이터 · 테마 파일은 그대로다.
 *
 * - 재고 관리가 켜져 있고 · 품절 후 주문을 허용하지 않고 · 수량 숫자가 있는 상품만 본다. 그 밖에는 아무 일도 안 한다
 *   (헛발질로 막는 것이 새는 것보다 나쁘다 — 2026-09-18 교훈)
 * - 묶음 상품 안에서 고르는 「맛」(다른 낱병의 이름)은 세지 않는다 — 그 맛은 낱병 상품의 재고와 이어져 있지 않다
 * - 끄기: add_filter( 'duckhoo_stock_cap', '__return_false' );
 *
 * @package Duckhoo\Redesign
 */

namespace Duckhoo\Redesign\StockCap;

defined( 'ABSPATH' ) || exit;

/**
 * 켜져 있나.
 *
 * @return bool
 */
function on(): bool {
	return (bool) apply_filters( 'duckhoo_stock_cap', true );
}

/**
 * 빌더 줄 중 필수(「기본 상품」 · 구성) 줄의 수량 합 — 순수 함수. 없으면 0.
 *
 * @param mixed $raw 빌더 줄 (배열이나 JSON).
 * @return int
 */
function builder_required( $raw ): int {
	if ( is_string( $raw ) ) {
		$raw = json_decode( function_exists( 'wp_unslash' ) ? wp_unslash( $raw ) : $raw, true );
	}
	$n = 0;
	foreach ( (array) $raw as $r ) {
		if ( ! is_array( $r ) ) {
			continue;
		}
		$g = (string) ( $r['group_key'] ?? '' );
		if ( 'required_main' === $g || 'required' === (string) ( $r['type'] ?? '' ) ) {
			$n += max( 0, (int) ( $r['qty'] ?? 0 ) );
		}
	}
	return $n;
}

/**
 * 한 줄이 실제로 내보내는 개수 — 순수 함수.
 *
 * @param int   $qty     장바구니 수량.
 * @param mixed $builder 빌더 줄.
 * @return int
 */
function units( int $qty, $builder ): int {
	return max( 1, $qty ) * max( 1, builder_required( $builder ) );
}

/**
 * 재고 상한 — 재고 관리가 켜진 상품이면 수량, 아니면 null(보지 않음).
 *
 * @param mixed $p 상품.
 * @return int|null
 */
function cap( $p ): ?int {
	if ( ! is_object( $p ) || ! method_exists( $p, 'managing_stock' ) || ! $p->managing_stock() ) {
		return null;
	}
	if ( method_exists( $p, 'backorders_allowed' ) && $p->backorders_allowed() ) {
		return null;
	}
	$s = method_exists( $p, 'get_stock_quantity' ) ? $p->get_stock_quantity() : null;
	return null === $s ? null : max( 0, (int) $s );
}

/**
 * 재고를 세는 상품 — 변형 상품이면 그 변형, 아니면 상품.
 *
 * @param array $item 장바구니 줄.
 * @return object|null
 */
function item_product( array $item ) {
	$p = $item['data'] ?? null;
	if ( is_object( $p ) && method_exists( $p, 'managing_stock' ) ) {
		return $p;
	}
	$id = (int) ( $item['variation_id'] ?? 0 ) ?: (int) ( $item['product_id'] ?? 0 );
	return $id && function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
}

/**
 * 상품 번호마다 장바구니에 든 개수.
 *
 * @param array $cart 장바구니 줄들.
 * @return array<int,array{units:int,product:object}>
 */
function tally( array $cart ): array {
	$out = array();
	foreach ( $cart as $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}
		$p = item_product( $item );
		if ( ! is_object( $p ) || ! method_exists( $p, 'get_id' ) ) {
			continue;
		}
		$id = (int) $p->get_id();
		if ( ! isset( $out[ $id ] ) ) {
			$out[ $id ] = array( 'units' => 0, 'product' => $p );
		}
		$out[ $id ]['units'] += units( (int) ( $item['quantity'] ?? 1 ), $item['wd_option_builder'] ?? null );
	}
	return $out;
}

/**
 * 안내 문구.
 *
 * @param object $p     상품.
 * @param int    $cap   재고.
 * @param int    $units 담으려는 (또는 담긴) 개수.
 * @return string
 */
function message( $p, int $cap, int $units ): string {
	$name = method_exists( $p, 'get_name' ) ? (string) $p->get_name() : '이 상품';
	$name = trim( (string) preg_replace( '/\s*\(.*?\)\s*$/u', '', $name ) );
	$msg  = $cap < 1
		? sprintf( '「%s」은(는) 지금 품절입니다. 장바구니에서 빼 주세요.', $name )
		: sprintf( '「%s」은(는) 남은 재고가 %d개라 %d개까지만 주문하실 수 있습니다. (지금 %d개)', $name, $cap, $cap, $units );
	return (string) apply_filters( 'duckhoo_stock_cap_message', $msg, $p, $cap, $units );
}

/**
 * 담기 검증 — 이미 장바구니에 든 것 + 지금 담는 것.
 *
 * @param mixed $passed 지금까지의 판정.
 * @param mixed $pid    상품 번호.
 * @param mixed $qty    수량.
 * @param mixed $vid    변형 번호.
 * @return bool
 */
function validate_add( $passed, $pid = 0, $qty = 1, $vid = 0 ): bool {
	if ( ! $passed || ! on() || ! function_exists( 'wc_get_product' ) ) {
		return (bool) $passed;
	}
	$p   = wc_get_product( (int) $vid ?: (int) $pid );
	$cap = cap( $p );
	if ( null === $cap ) {
		return true;
	}
	$adding = units( (int) $qty, (string) ( $_POST['wd_option_builder_json'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
	$have   = 0;
	if ( function_exists( 'WC' ) && WC()->cart ) {
		$t    = tally( (array) WC()->cart->get_cart() );
		$have = (int) ( $t[ (int) $p->get_id() ]['units'] ?? 0 );
	}
	if ( $have + $adding <= $cap ) {
		return true;
	}
	if ( function_exists( 'wc_add_notice' ) ) {
		wc_add_notice( message( $p, $cap, $have + $adding ), 'error' );
	}
	return false;
}

/**
 * 넘친 상품들 — 순수에 가깝게 (장바구니를 받는다).
 *
 * @param array $cart 장바구니 줄들.
 * @return array<int,string> 상품 번호 => 문구.
 */
function over( array $cart ): array {
	$bad = array();
	foreach ( tally( $cart ) as $id => $t ) {
		$cap = cap( $t['product'] );
		if ( null !== $cap && $t['units'] > $cap ) {
			$bad[ $id ] = message( $t['product'], $cap, $t['units'] );
		}
	}
	return $bad;
}

/**
 * 장바구니 · 결제 화면에서 — 넘쳤으면 오류로 알린다 (워드커머스가 결제를 세운다).
 *
 * @return void
 */
function check_cart(): void {
	if ( ! on() || ! function_exists( 'WC' ) || ! WC()->cart || ! function_exists( 'wc_add_notice' ) ) {
		return;
	}
	foreach ( over( (array) WC()->cart->get_cart() ) as $msg ) {
		wc_add_notice( $msg, 'error' );
	}
}

/**
 * 마지막 빗장 — 어느 길로 왔든 주문은 여기를 지난다.
 *
 * @return void
 */
function guard_order(): void {
	if ( ! on() || ! function_exists( 'WC' ) || ! WC()->cart ) {
		return;
	}
	$bad = over( (array) WC()->cart->get_cart() );
	if ( $bad ) {
		throw new \Exception( esc_html( implode( ' ', $bad ) ) );
	}
}

if ( function_exists( 'add_filter' ) ) {
	add_filter( 'woocommerce_add_to_cart_validation', __NAMESPACE__ . '\\validate_add', 25, 4 );
	add_action( 'woocommerce_check_cart_items', __NAMESPACE__ . '\\check_cart' );
	add_action( 'woocommerce_checkout_create_order', __NAMESPACE__ . '\\guard_order', 4 );
}
