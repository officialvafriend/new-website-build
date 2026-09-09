<?php
/**
 * 9월 금액대별 자동 할인(10만원 이상 10,000원)을 끈다.
 *
 * 사장님 결정 2026-09-09: 이 이벤트를 없앤다.
 *
 * **어디에 있었나.** 스니펫이 아니라 **테마**다 (`도구 → 자동 할인 진단` 으로 찾았다):
 *
 *     themes/키플_액상덕후/functions.php:10694
 *         $cart->add_fee( '🎁 금액 자동 할인', -$discount, false );
 *
 * `woocommerce_cart_calculate_fees` 우선순위 20 의 익명 함수(10669줄)가 붙이고,
 * `page-cart.php` · `functions.php` · `checkout/form-checkout.php` 세 곳이 **음수 fee 를
 * 합산**해 「할인」 줄로 그린다.
 *
 * **그래서 fee 를 나중에 걷어내면 안 된다.** 그렇게 했더니 화면과 계산이 갈라져
 * 「총 주문금액 0원」이 나왔다 (2026-09-08~09, 세 번 시도). 기준에 못 미치는 장바구니는
 * 멀쩡히 그려지므로 **애초에 안 만들어지게** 해야 한다 — 그 함수를 훅에서 뗀다.
 *
 * **테마 파일은 한 글자도 고치지 않는다.** 워드프레스 훅에서 떼는 것뿐이라 테마가
 * 업데이트돼도 파일이 덮어써질 일이 없다.
 *
 * 되살리려면: `add_filter( 'duckhoo_kill_auto_discount', '__return_false' );`
 *
 * @package Duckhoo\Redesign
 */

declare( strict_types = 1 );

namespace Duckhoo\Redesign\Discount;

defined( 'ABSPATH' ) || exit;

/**
 * 자동 할인을 끄는가.
 *
 * @return bool
 */
function killing(): bool {
	return (bool) apply_filters( 'duckhoo_kill_auto_discount', true );
}

/**
 * 이 콜백의 원본 코드에 이 글자가 있는가.
 *
 * **줄 번호로 찾지 않는다.** 테마가 조금만 바뀌어도 줄이 밀린다. 그 함수가 실제로
 * 무엇을 하는지(= `add_fee( '🎁 금액 자동 할인' … )`)로 알아본다. 못 찾으면 아무것도
 * 하지 않는다 — 할인이 되살아날 뿐, 다른 것을 잘못 떼지는 않는다.
 *
 * @param mixed  $cb     콜백.
 * @param string $needle 찾을 글자.
 * @return bool
 */
function source_has( $cb, string $needle ): bool {
	if ( ! $cb instanceof \Closure ) {
		return false;
	}
	try {
		$r    = new \ReflectionFunction( $cb );
		$file = (string) $r->getFileName();
		if ( '' === $file || ! is_readable( $file ) ) {
			return false;
		}
		$from = max( 1, (int) $r->getStartLine() );
		$to   = (int) $r->getEndLine();
		$body = '';
		$fh   = fopen( $file, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $fh ) {
			return false;
		}
		for ( $i = 1; ! feof( $fh ) && $i <= $to; $i++ ) {
			$line = fgets( $fh );
			if ( $i >= $from && false !== $line ) {
				$body .= $line;
			}
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return false !== strpos( $body, $needle );
	} catch ( \Throwable $e ) {
		return false;
	}
}

/**
 * 자동 할인을 붙이는 함수를 훅에서 뗀다.
 *
 * 이름이 붙은 다른 수수료(배송비 · 도서산간 · 적립금 할인)는 건드리지 않는다 —
 * 익명 함수이면서 그 안에 `금액 자동 할인` 을 쓰는 것 하나만 본다.
 *
 * @return void
 */
function kill(): void {
	if ( ! killing() ) {
		return;
	}
	global $wp_filter;
	$hook = $wp_filter['woocommerce_cart_calculate_fees'] ?? null;
	if ( ! $hook || ! isset( $hook->callbacks ) ) {
		return;
	}

	$needle = (string) apply_filters( 'duckhoo_auto_discount_marker', '금액 자동 할인' );
	$drop   = array();
	foreach ( (array) $hook->callbacks as $prio => $list ) {
		foreach ( (array) $list as $one ) {
			$cb = $one['function'] ?? null;
			if ( source_has( $cb, $needle ) ) {
				$drop[] = array( $cb, $prio );
			}
		}
	}
	// 돌면서 떼면 목록이 흔들린다. 다 찾은 뒤에 뗀다.
	foreach ( $drop as $one ) {
		remove_action( 'woocommerce_cart_calculate_fees', $one[0], (int) $one[1] );
	}
}
add_action( 'wp_loaded', __NAMESPACE__ . '\\kill', 99 );

/**
 * 끈 이벤트를 화면이 계속 광고하면 안 된다.
 *
 * 없는 혜택을 읽고 담은 손님은 결제 화면에서 배신당한다. 안내 문구의 규칙을 비운다 —
 * `front.js` 가 이것을 보고 장바구니 안내를 지운다.
 *
 * @param array<int,array<string,int>> $tiers 여태 규칙.
 * @return array<int,array<string,int>>
 */
function no_tiers( $tiers ): array {
	return killing() ? array() : (array) $tiers;
}
add_filter( 'duckhoo_auto_discount', __NAMESPACE__ . '\\no_tiers', 99 );
