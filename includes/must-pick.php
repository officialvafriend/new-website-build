<?php
/**
 * 옵션을 고르지 않으면 못 담는다 — 서버 쪽 빗장 (2026-09-28).
 *
 * 사장님: 「색상을 꼭 선택해야 구매도 가능하게끔」. 화면은 테마 `wd-option-builder.js` 가 첫 select(본품)를
 * 하나도 안 고르면 담기 · 바로구매를 막는다 (`getRequiredTotalQty() < 1`). 그러나 그것은 브라우저 안 얘기라
 * Store API `add-item` 이나 폼을 손으로 보내면 색 없이 담긴다. 여기서는 **워드커머스가 내준 검증 훅만** 써서
 * 같은 규칙을 서버에서 한 번 더 본다 — 폼 필드 · 담기는 데이터에는 손대지 않는다.
 *
 * - 대상은 필터 `duckhoo_must_pick` 의 상품 번호 (기본: 조바 입호흡 전자담배 #3435)
 * - **옵션 그룹이 이어져 있을 때만 돈다** (`_product_ppom` 이 비어 있으면 아무것도 안 한다) — 사장님이 그룹을
 *   잇기 전에 판매가 막히면 안 된다. 헛발질로 막는 것이 새는 것보다 나쁘다 (2026-09-18 교훈)
 * - 「골랐다」= PPOM 칸(`ppom[fields][*]`) 중 값이 있는 것이 하나라도 있거나, 테마 빌더 JSON 에 `required_main` 줄이 있다.
 *   칸 이름(data_name)을 못 박지 않는다 — 사장님이 무슨 이름으로 만들든 걸린다
 *
 * @package Duckhoo\Redesign
 */

namespace Duckhoo\Redesign\MustPick;

defined( 'ABSPATH' ) || exit;

/**
 * 옵션을 골라야만 담기는 상품 번호들.
 *
 * @return int[]
 */
function products(): array {
	return array_values( array_unique( array_map( 'intval', (array) apply_filters( 'duckhoo_must_pick', array( 3435 ) ) ) ) );
}

/**
 * 안내 문구.
 *
 * @param int $pid 상품 번호.
 * @return string
 */
function message( int $pid ): string {
	return (string) apply_filters( 'duckhoo_must_pick_message', '옵션(색상)을 선택해 주세요. 골라야 담을 수 있습니다.', $pid );
}

/**
 * 이 상품이 지금 옵션 그룹을 갖고 있는가 — 없으면 빗장을 안 건다.
 *
 * @param int $pid 상품 번호.
 * @return bool
 */
function has_group( int $pid ): bool {
	$forced = (bool) apply_filters( 'duckhoo_must_pick_force', false, $pid );
	if ( $forced ) {
		return true;
	}
	// PPOM 자신에게 묻는다 — 상품 메타뿐 아니라 분류 · 태그로 붙인 그룹까지 PPOM 이 안다 (`PPOM_Meta::$is_exists`).
	if ( class_exists( '\\PPOM_Meta' ) ) {
		try {
			$m = new \PPOM_Meta( $pid );
			if ( isset( $m->is_exists ) ) {
				return (bool) $m->is_exists;
			}
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
			// 아래 메타 키로 본다.
		}
	}
	if ( ! function_exists( 'get_post_meta' ) ) {
		return false;
	}
	// PPOM 34: `_product_meta_id` 에 그룹 번호 **배열**을 적는다 (`PPOM_PRODUCT_META_KEY`, `Helpers::attach_fields_to_product`).
	// 처음엔 `_product_ppom` 이라고 짐작해 빗장이 조용히 안 돌았다 (2026-09-28, Store API 201) — 소스에서 확인한 이름만 쓴다.
	foreach ( (array) apply_filters( 'duckhoo_ppom_link_keys', array( '_product_meta_id', '_product_ppom' ) ) as $k ) {
		$v = get_post_meta( $pid, (string) $k, true );
		if ( is_array( $v ) ? array_filter( array_map( 'intval', $v ) ) : '' !== trim( (string) $v ) ) {
			return true;
		}
	}
	return false;
}

/**
 * 담기 요청에 고른 옵션이 있는가 — 순수 함수. 테스트가 이것을 본다.
 *
 * @param array $post 요청 ($_POST).
 * @return bool
 */
function picked( array $post ): bool {
	$fields = isset( $post['ppom']['fields'] ) && is_array( $post['ppom']['fields'] ) ? $post['ppom']['fields'] : array();
	foreach ( $fields as $k => $v ) {
		if ( 'id' === (string) $k ) {
			continue;
		}
		if ( is_array( $v ) ? array_filter( array_map( 'trim', array_map( 'strval', $v ) ) ) : '' !== trim( (string) $v ) ) {
			return true;
		}
	}
	$json = (string) ( $post['wd_option_builder_json'] ?? '' );
	if ( '' !== $json ) {
		$rows = json_decode( wp_unslash( $json ), true );
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				if ( is_array( $r ) && 'required_main' === (string) ( $r['group_key'] ?? '' ) && (int) ( $r['qty'] ?? 0 ) >= 1 ) {
					return true;
				}
			}
		}
	}
	return false;
}

/**
 * 장바구니 줄에 고른 옵션이 있는가 — PPOM 이 줄에 넣는 `ppom` 과 테마 빌더의 `wd_option_builder` 둘 다 본다.
 *
 * @param array $item 장바구니 줄.
 * @return bool
 */
function item_picked( array $item ): bool {
	$f = $item['ppom']['fields'] ?? ( $item['ppom'] ?? array() );
	if ( is_array( $f ) ) {
		foreach ( $f as $k => $v ) {
			if ( 'id' === (string) $k ) {
				continue;
			}
			if ( is_array( $v ) ? array_filter( array_map( 'trim', array_map( 'strval', $v ) ) ) : '' !== trim( (string) $v ) ) {
				return true;
			}
		}
	}
	$b = $item['wd_option_builder'] ?? null;
	if ( is_string( $b ) ) {
		$b = json_decode( $b, true );
	}
	if ( is_array( $b ) ) {
		foreach ( $b as $r ) {
			if ( is_array( $r ) && 'required_main' === (string) ( $r['group_key'] ?? '' ) && (int) ( $r['qty'] ?? 0 ) >= 1 ) {
				return true;
			}
		}
	}
	return false;
}

/**
 * 담기 검증 (폼 · admin-ajax).
 *
 * @param mixed $passed 지금까지의 판정.
 * @param mixed $pid    상품 번호.
 * @return bool
 */
function validate_add( $passed, $pid = 0 ): bool {
	$pid = (int) $pid;
	if ( ! $passed || ! in_array( $pid, products(), true ) || ! has_group( $pid ) ) {
		return (bool) $passed;
	}
	if ( picked( (array) $_POST ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return true;
	}
	if ( function_exists( 'wc_add_notice' ) ) {
		wc_add_notice( message( $pid ), 'error' );
	}
	return false;
}

/**
 * Store API `add-item` — 옵션을 실을 수 없는 길이라 이 상품은 여기로 못 담는다.
 *
 * @param mixed $product 상품.
 * @return void
 */
function store_add( $product ): void {
	if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
		return;
	}
	$pid = (int) $product->get_id();
	if ( ! in_array( $pid, products(), true ) || ! has_group( $pid ) ) {
		return;
	}
	if ( class_exists( '\\Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException' ) ) {
		throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'duckhoo_must_pick', message( $pid ), 400 );
	}
	throw new \RuntimeException( message( $pid ) );
}

/**
 * 마지막 빗장 — 어느 길로 왔든 주문은 여기를 지난다. 옵션 없는 줄이 있으면 주문을 세운다.
 *
 * @return void
 */
function guard_order(): void {
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return;
	}
	foreach ( (array) WC()->cart->get_cart() as $item ) {
		$pid = (int) ( $item['product_id'] ?? 0 );
		if ( in_array( $pid, products(), true ) && has_group( $pid ) && ! item_picked( (array) $item ) ) {
			throw new \Exception( esc_html( message( $pid ) . ' 장바구니에서 그 상품을 빼고 다시 담아 주세요.' ) );
		}
	}
}

if ( function_exists( 'add_filter' ) ) {
	add_filter( 'woocommerce_add_to_cart_validation', __NAMESPACE__ . '\\validate_add', 15, 2 );
	add_action( 'woocommerce_store_api_validate_add_to_cart', __NAMESPACE__ . '\\store_add', 10, 1 );
	add_action( 'woocommerce_checkout_create_order', __NAMESPACE__ . '\\guard_order', 4 );
}
