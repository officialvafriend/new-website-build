<?php
/**
 * 팔지 않는 맛 — 어느 길로도 못 사게 한다 (2026-10-19).
 *
 * 사장님: 「그린펀치는 일단 아무도 구매 못하게」. 낱병(#245)은 비공개로 내렸지만 **노보 10+1 묶음의 맛 옵션은 재고를
 * 따로 센다** (PPOM 옵션 재고) — 낱병에 품절을 걸어도 묶음 안의 「노보 그린펀치」는 계속 골라졌다. 이미 묶음에 담아 둔
 * 장바구니도 남아 있을 수 있다.
 *
 * 여기서는 막은 낱말을 **상품 이름 · 손님이 고른 빌더 줄(`label`) · 고른 PPOM 칸 값**에서 찾아 막는다.
 * 담기 · 장바구니 · 결제 직전 · 낱병의 구매 가능 여부 네 곳. **워드커머스가 내준 훅만 쓴다** — 폼 필드 · 담기는
 * 데이터 · 테마 · PPOM 설정은 그대로다. 화면에서는 front.js 가 그 맛 칸을 감춘다 (`window.DHR.blocked`).
 *
 * - 낱말 목록: 필터 `duckhoo_blocked_items` (기본 `그린펀치`). 빈칸은 무시하고 견준다
 * - 다시 팔려면: add_filter( 'duckhoo_blocked_items', '__return_empty_array' );
 *
 * @package Duckhoo\Redesign
 */

namespace Duckhoo\Redesign\Blocked;

defined( 'ABSPATH' ) || exit;

/**
 * 막은 낱말들 (빈칸 뺀 꼴).
 *
 * @return string[]
 */
function words(): array {
	$out = array();
	foreach ( (array) apply_filters( 'duckhoo_blocked_items', array( '그린펀치' ) ) as $w ) {
		$w = squeeze( (string) $w );
		if ( '' !== $w ) {
			$out[] = $w;
		}
	}
	return array_values( array_unique( $out ) );
}

/**
 * 빈칸을 지운다 — 「노보 그린 펀치」처럼 띄어 적어도 걸리게.
 *
 * @param string $s 글.
 * @return string
 */
function squeeze( string $s ): string {
	return (string) preg_replace( '/[\s\x{00A0}\x{3000}]+/u', '', $s );
}

/**
 * 글에 막은 낱말이 들었으면 그 낱말, 아니면 ''. 순수 함수.
 *
 * @param string   $text  글.
 * @param string[] $words 낱말들 (squeeze 된 꼴).
 * @return string
 */
function hit_text( string $text, array $words ): string {
	$t = squeeze( $text );
	if ( '' === $t ) {
		return '';
	}
	foreach ( $words as $w ) {
		if ( '' !== $w && false !== mb_strpos( $t, $w ) ) {
			return $w;
		}
	}
	return '';
}

/**
 * 배열 · JSON 안의 글을 다 훑는다. 순수 함수.
 *
 * @param mixed    $v     값.
 * @param string[] $words 낱말들.
 * @param int      $depth 깊이 (끝없이 내려가지 않게).
 * @return string
 */
function hit_any( $v, array $words, int $depth = 0 ): string {
	if ( $depth > 6 || ! $words ) {
		return '';
	}
	if ( is_string( $v ) ) {
		$h = hit_text( $v, $words );
		if ( '' !== $h ) {
			return $h;
		}
		$t = ltrim( $v );
		if ( '' !== $t && ( '[' === $t[0] || '{' === $t[0] ) ) {
			$j = json_decode( function_exists( 'wp_unslash' ) ? wp_unslash( $v ) : $v, true );
			if ( is_array( $j ) ) {
				return hit_any( $j, $words, $depth + 1 );
			}
		}
		return '';
	}
	if ( is_array( $v ) ) {
		foreach ( $v as $x ) {
			$h = hit_any( $x, $words, $depth + 1 );
			if ( '' !== $h ) {
				return $h;
			}
		}
	}
	return '';
}

/**
 * 장바구니 줄 하나가 막은 것을 담고 있나 — 상품 이름 · 빌더 줄 · PPOM 칸. 순수에 가깝다.
 *
 * @param array    $item  장바구니 줄.
 * @param string[] $words 낱말들.
 * @return string 걸린 낱말 또는 ''.
 */
function hit_item( array $item, array $words ): string {
	$p = $item['data'] ?? null;
	if ( is_object( $p ) && method_exists( $p, 'get_name' ) ) {
		$h = hit_text( (string) $p->get_name(), $words );
		if ( '' !== $h ) {
			return $h;
		}
	}
	return hit_picks( $item['wd_option_builder'] ?? null, $item['ppom']['fields'] ?? null, $words );
}

/**
 * 손님이 **고른 것**만 본다 — 빌더 줄의 `label` 과 PPOM 칸(`fields`)의 값. 순수 함수.
 *
 * PPOM 의 다른 칸(`ppom_option_price` 같은 값 표)이나 옵션 목록 전체를 훑으면 그 맛을 안 고른 묶음까지 막힌다.
 *
 * @param mixed    $builder 빌더 줄 (배열 · JSON).
 * @param mixed    $fields  PPOM 칸 값.
 * @param string[] $words   낱말들.
 * @return string
 */
function hit_picks( $builder, $fields, array $words ): string {
	if ( is_string( $builder ) ) {
		$builder = json_decode( function_exists( 'wp_unslash' ) ? wp_unslash( $builder ) : $builder, true );
	}
	foreach ( (array) $builder as $r ) {
		if ( is_array( $r ) && (int) ( $r['qty'] ?? 1 ) > 0 ) {
			$h = hit_text( (string) ( $r['label'] ?? '' ), $words );
			if ( '' !== $h ) {
				return $h;
			}
		}
	}
	return is_array( $fields ) ? hit_any( $fields, $words ) : '';
}

/**
 * 안내 문구.
 *
 * @param string $word 걸린 낱말.
 * @return string
 */
function message( string $word ): string {
	$msg = sprintf( '%s은(는) 지금 판매하지 않습니다. 다른 맛으로 바꿔 담아 주세요.', $word );
	return (string) apply_filters( 'duckhoo_blocked_message', $msg, $word );
}

/**
 * 장바구니에서 걸린 낱말들.
 *
 * @param array $cart 장바구니 줄들.
 * @return string[]
 */
function hits( array $cart ): array {
	$words = words();
	$out   = array();
	if ( ! $words ) {
		return $out;
	}
	foreach ( $cart as $item ) {
		if ( is_array( $item ) ) {
			$h = hit_item( $item, $words );
			if ( '' !== $h ) {
				$out[ $h ] = $h;
			}
		}
	}
	return array_values( $out );
}

/**
 * 낱병 자체는 구매 불가로.
 *
 * @param mixed $ok 여태 판정.
 * @param mixed $p  상품.
 * @return bool
 */
function purchasable( $ok, $p = null ): bool {
	if ( ! $ok || ! is_object( $p ) || ! method_exists( $p, 'get_name' ) ) {
		return (bool) $ok;
	}
	return '' === hit_text( (string) $p->get_name(), words() );
}

/**
 * 담기 검증 — 상품 이름과 지금 보내는 빌더 줄 · PPOM 칸.
 *
 * @param mixed $passed 여태 판정.
 * @param mixed $pid    상품 번호.
 * @return bool
 */
function validate_add( $passed, $pid = 0 ): bool {
	$words = words();
	if ( ! $passed || ! $words ) {
		return (bool) $passed;
	}
	$h = '';
	$p = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $pid ) : null;
	if ( is_object( $p ) ) {
		$h = hit_text( (string) $p->get_name(), $words );
	}
	if ( '' === $h ) {
		// phpcs:ignore WordPress.Security.NonceVerification
		$h = hit_picks( (string) ( $_POST['wd_option_builder_json'] ?? '' ), $_POST['ppom']['fields'] ?? null, $words );
	}
	if ( '' === $h ) {
		return true;
	}
	if ( function_exists( 'wc_add_notice' ) ) {
		wc_add_notice( message( $h ), 'error' );
	}
	return false;
}

/**
 * 장바구니 · 결제 화면 — 오류로 알리면 워드커머스가 결제를 세운다.
 *
 * @return void
 */
function check_cart(): void {
	if ( ! function_exists( 'WC' ) || ! WC()->cart || ! function_exists( 'wc_add_notice' ) ) {
		return;
	}
	foreach ( hits( (array) WC()->cart->get_cart() ) as $h ) {
		wc_add_notice( message( $h ), 'error' );
	}
}

/**
 * 마지막 빗장 — 어느 길로 왔든 주문은 여기를 지난다.
 *
 * @return void
 */
function guard_order(): void {
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return;
	}
	$h = hits( (array) WC()->cart->get_cart() );
	if ( $h ) {
		throw new \Exception( esc_html( message( $h[0] ) ) );
	}
}

if ( function_exists( 'add_filter' ) ) {
	add_filter( 'woocommerce_is_purchasable', __NAMESPACE__ . '\\purchasable', 20, 2 );
	add_filter( 'woocommerce_add_to_cart_validation', __NAMESPACE__ . '\\validate_add', 26, 2 );
	add_action( 'woocommerce_check_cart_items', __NAMESPACE__ . '\\check_cart' );
	add_action( 'woocommerce_checkout_create_order', __NAMESPACE__ . '\\guard_order', 3 );
}
