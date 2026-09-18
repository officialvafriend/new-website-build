<?php
/**
 * 담아 둔 뒤 값이 바뀌면 **옛 값으로 주문된다** — 그것을 막고 찾는다.
 *
 * 사장님 신고(2026-09-18): 주문 `#202609180004850` 이 **120,000원**으로 들어왔는데
 * 같은 구성을 지금 담아 보면 **180,000원**이다. 60,000원이 덜 청구됐다.
 *
 * 주문에 실린 값이 답을 그대로 말한다:
 *
 *     _wd_base_price: 70000            ← 담을 때 굳은 기준가 (옛 값)
 *     팟 말론 0.4옴(2EA) +10,000 × 5    50,000
 *     ────────────────────────────────
 *     120,000원                        ← 실제 청구
 *
 * 지금 그 상품 페이지의 `basePrice` 는 **130,000** 이다 (확인함 — 페이지에 `70000` 은
 * 한 곳도 없다). 즉 **상품은 이미 고쳐졌고, 그 손님의 장바구니에만 옛 기준가가
 * 박혀 있었다.** 이 가게는 회원 장바구니가 오래 남으므로 며칠 전 담은 줄이 그대로
 * 결제될 수 있다.
 *
 * **테마의 `dh-price-guard` 는 이것을 못 잡는다.** 그쪽은 「옵션 행이 말하는 금액」과
 * 「실제로 물린 금액」이 갈리는지만 본다 — 여기서는 둘 다 옛 기준가로 계산돼
 * 120,000 으로 **서로 맞아떨어진다.** 기준가 자체가 낡았는지는 아무도 안 봤다.
 *
 * 그래서 이 파일이 그 한 가지만 본다: **담을 때 굳은 기준가 vs 지금 상품의 판매가.**
 *
 *   · 다르면 장바구니 · 결제에서 안내하고 주문을 막는다 (워드커머스 검증 훅만 쓴다)
 *   · `도구 → 금액 점검` 이 이미 들어온 주문 중 어긋난 것을 찾아 준다 (읽기 전용)
 *
 * **담기는 데이터에 손대지 않는다** — 값을 고쳐서 통과시키지 않는다. 손님이 다시
 * 담으면 지금 값으로 제대로 잡힌다.
 *
 * 끄기: `add_filter( 'duckhoo_price_check', '__return_false' );`
 *
 * @package Duckhoo\Redesign
 */

declare( strict_types = 1 );

namespace Duckhoo\Redesign\PriceCheck;

defined( 'ABSPATH' ) || exit;

/**
 * 볼 것인가.
 *
 * @return bool
 */
function on(): bool {
	return (bool) apply_filters( 'duckhoo_price_check', true );
}

/**
 * **실제로 막을 것인가.** 기본은 **아니오**다.
 *
 * 2026-09-18 18:44 — 배포 직후 사장님이 **지금 값으로 새로 담은** 노보 블랙 10+1 을
 * 결제하려는데 막혔다. 담을 때 굳는 기준가가 「상품 판매가」와 같은 값이 아니라는
 * 뜻이다 — 회원 등급 할인이 얹힌 값이거나 병당 값이거나, 아직 모른다. 모르는
 * 채로 막으면 **정상 주문이 막힌다** — 옛 장바구니 하나가 새는 것보다 훨씬 나쁘다.
 * 그래서 무엇을 견주는지 진단(`diag()`)으로 먼저 보고, 확인된 뒤에 켠다.
 *
 * 켜기: `add_filter( 'duckhoo_price_check_block', '__return_true' );`
 *
 * @return bool
 */
function blocking(): bool {
	return (bool) apply_filters( 'duckhoo_price_check_block', false );
}

/**
 * 몇 원부터 어긋난 것으로 볼 것인가 — 반올림 오차를 넘기기 위해.
 *
 * @return int
 */
function slack(): int {
	return max( 1, (int) apply_filters( 'duckhoo_price_check_slack', 100 ) );
}

/**
 * 장바구니 줄에 굳어 있는 기준가. 못 찾으면 0.
 *
 * 테마가 어디에 넣는지 이 저장소에서는 알 수 없어(테마 PHP 가 없다) **세 군데를
 * 차례로 본다** — 줄의 값 → 옵션 JSON → 이름에 base 가 든 칸. 적립금(`Points\used()`)에서
 * 쓴 것과 같은 방법이다.
 *
 * @param array $item 장바구니 줄.
 * @return float
 */
function stored_base( array $item ): float {
	foreach ( array( 'wd_base_price', '_wd_base_price' ) as $key ) {
		if ( isset( $item[ $key ] ) && is_numeric( $item[ $key ] ) ) {
			return (float) $item[ $key ];
		}
	}

	$bag = $item['wd_option_builder'] ?? null;
	if ( is_string( $bag ) && '' !== $bag ) {
		$bag = json_decode( $bag, true );
	}
	if ( is_array( $bag ) ) {
		foreach ( array( '_wd_base_price', 'base_price', 'basePrice' ) as $key ) {
			if ( isset( $bag[ $key ] ) && is_numeric( $bag[ $key ] ) ) {
				return (float) $bag[ $key ];
			}
		}
	}

	foreach ( $item as $key => $value ) {
		if ( is_numeric( $value ) && preg_match( '/base.?price/i', (string) $key ) ) {
			return (float) $value;
		}
	}

	return 0.0;
}

/**
 * 이 줄이 낡았는가. 낡았으면 무엇이 얼마나 어긋났는지 돌려준다.
 *
 * **기준가를 못 찾으면 아무 말도 하지 않는다** — 옵션이 없는 평범한 상품이 그렇다.
 * 모를 때는 건드리지 않는 쪽이다.
 *
 * @param array $item 장바구니 줄.
 * @return array{name: string, stored: float, now: float, gap: float}|null
 */
function stale( array $item ): ?array {
	$product = $item['data'] ?? null;
	if ( ! is_object( $product ) || ! method_exists( $product, 'get_price' ) ) {
		return null;
	}
	$stored = stored_base( $item );
	if ( $stored <= 0 ) {
		return null;
	}
	$now = (float) $product->get_price();
	if ( $now <= 0 ) {
		return null;
	}

	/* **정가와 같아도 그냥 둔다.** 테마가 기준가로 무엇을 넣는지 우리는 확인할 수 없다 —
	   판매가가 아니라 정가를 넣는 구현이라면, 세일 중인 상품(이 가게는 대부분이 그렇다)이
	   전부 어긋난 것으로 잡혀 **결제가 통째로 막힌다.** 그래서 판매가 · 정가 **어느 쪽과도
	   다를 때만** 낡은 것으로 본다. 실제 사고(#202609180004850)의 70,000 은 판매가
	   130,000 · 정가 187,000 어느 쪽도 아닌 옛 값이라 이 검사에 그대로 걸린다. */
	$regular = method_exists( $product, 'get_regular_price' ) ? (float) $product->get_regular_price() : 0.0;
	if ( $regular > 0 && abs( $regular - $stored ) < slack() ) {
		return null;
	}

	$gap = $now - $stored;
	if ( abs( $gap ) < slack() ) {
		return null;
	}

	return array(
		'name'   => method_exists( $product, 'get_name' ) ? (string) $product->get_name() : '',
		'stored' => $stored,
		'now'    => $now,
		'gap'    => $gap,
	);
}

/**
 * 손님에게 할 말 — **왜 막혔는지가 아니라 무엇을 하면 되는지**.
 *
 * @param string $name 상품 이름.
 * @return string
 */
function notice( string $name ): string {
	return sprintf(
		/* translators: %s: 상품 이름 */
		'“%s” 의 가격이 장바구니에 담으신 뒤에 바뀌었습니다. 이 상품을 장바구니에서 빼고 다시 담아 주세요 — 그래야 지금 금액으로 주문됩니다. 나머지 상품은 그대로 두셔도 됩니다.',
		$name
	);
}

/**
 * 어긋난 줄들.
 *
 * @param \WC_Cart|mixed $cart 장바구니.
 * @return array<int,array{name:string,stored:float,now:float,gap:float}>
 */
function bad_lines( $cart = null ): array {
	$out = array();
	if ( ! on() ) {
		return $out;
	}
	if ( null === $cart && function_exists( 'WC' ) && isset( WC()->cart ) ) {
		$cart = WC()->cart;
	}
	if ( ! is_object( $cart ) || ! method_exists( $cart, 'get_cart' ) ) {
		return $out;
	}
	foreach ( (array) $cart->get_cart() as $item ) {
		$bad = stale( (array) $item );
		if ( $bad ) {
			$out[] = $bad;
		}
	}

	return $out;
}

/**
 * 장바구니 · 결제 화면에서 알린다.
 *
 * @return void
 */
function check_cart(): void {
	if ( ! blocking() ) {
		return;
	}
	foreach ( bad_lines() as $bad ) {
		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( notice( (string) $bad['name'] ), 'error' );
		}
	}
}
add_action( 'woocommerce_check_cart_items', __NAMESPACE__ . '\\check_cart', 20 );

/**
 * **마지막 빗장** — 어느 길로 왔든 주문은 여기를 지난다.
 *
 * 던진 예외를 워드커머스가 잡아 결제 화면의 오류로 보여 준다.
 *
 * @param \WC_Order|mixed $order 주문.
 * @return void
 * @throws \Exception 낡은 줄이 있으면.
 */
function guard_order( $order = null ): void {
	if ( ! blocking() ) {
		return;
	}
	$bad = bad_lines();
	if ( ! $bad ) {
		return;
	}
	throw new \Exception( esc_html( notice( (string) $bad[0]['name'] ) ) );
}
add_action( 'woocommerce_checkout_create_order', __NAMESPACE__ . '\\guard_order', 5 );

/**
 * 진단 — **관리자에게만** 장바구니 · 결제 화면 위에 장바구니 줄마다 무엇을 견줬는지 적는다.
 *
 * 로그인 화면이라 우리가 못 보므로 사장님 캡처 한 장으로 가른다. 줄마다:
 * 찾은 기준가(어느 칸에서) · 장바구니 안 상품 객체의 판매가 · 정가 · **새로 읽은** 상품의
 * 판매가(회원 할인이 장바구니 객체에만 얹혔는지 가른다) · 그 줄에 실린 base 비슷한 칸 전부.
 *
 * @return void
 */
function diag(): void {
	static $done = false;
	if ( $done || ! on() || ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	$done = true;
	if ( ! function_exists( 'WC' ) || ! isset( WC()->cart ) || ! method_exists( WC()->cart, 'get_cart' ) ) {
		return;
	}
	$rows = array();
	foreach ( (array) WC()->cart->get_cart() as $key => $item ) {
		$item = (array) $item;
		$obj  = $item['data'] ?? null;
		$pid  = (int) ( $item['product_id'] ?? 0 );
		$new  = $pid > 0 && function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : null;

		$found_key = '';
		foreach ( array( 'wd_base_price', '_wd_base_price' ) as $k ) {
			if ( isset( $item[ $k ] ) ) {
				$found_key = $k;
				break;
			}
		}
		$bag = $item['wd_option_builder'] ?? null;
		$bag_s = is_string( $bag ) ? $bag : ( is_array( $bag ) ? wp_json_encode( $bag ) : '' );
		$bases = array();
		foreach ( $item as $k => $v ) {
			if ( is_scalar( $v ) && preg_match( '/price|base/i', (string) $k ) ) {
				$bases[] = $k . '=' . $v;
			}
		}
		$rows[] = array(
			'name'   => is_object( $obj ) && method_exists( $obj, 'get_name' ) ? (string) $obj->get_name() : ( 'product ' . $pid ),
			'stored' => stored_base( $item ),
			'skey'   => '' !== $found_key ? $found_key : ( false !== strpos( $bag_s, 'base' ) ? 'wd_option_builder 안' : '(못 찾음/이름 검색)' ),
			'cart_p' => is_object( $obj ) && method_exists( $obj, 'get_price' ) ? (float) $obj->get_price() : 0,
			'cart_r' => is_object( $obj ) && method_exists( $obj, 'get_regular_price' ) ? (float) $obj->get_regular_price() : 0,
			'new_p'  => is_object( $new ) && method_exists( $new, 'get_price' ) ? (float) $new->get_price() : 0,
			'new_r'  => is_object( $new ) && method_exists( $new, 'get_regular_price' ) ? (float) $new->get_regular_price() : 0,
			'sub'    => (float) ( $item['line_subtotal'] ?? 0 ),
			'keys'   => implode( ' · ', $bases ),
			'bag'    => (string) preg_replace( '/\s+/', ' ', mb_substr( $bag_s, 0, 400 ) ),
			'stale'  => stale( $item ) ? '막힘' : '통과',
		);
	}
	if ( ! $rows ) {
		return;
	}
	echo '<div style="margin:12px 0;padding:12px 14px;border:2px dashed #B32D2E;border-radius:10px;background:#fff;font:13px/1.6 -apple-system,sans-serif;color:#111;overflow:auto">';
	echo '<b>금액 점검 진단</b> (관리자에게만 보입니다 · 막기 ' . ( blocking() ? '켜짐' : '<b>꺼짐</b>' ) . ')<br>';
	foreach ( $rows as $r ) {
		printf(
			'<div style="margin:8px 0;padding:8px;border:1px solid #ddd;border-radius:6px"><b>%s</b> → <b style="color:%s">%s</b><br>'
			. '찾은 기준가 <b>%s</b> (%s) · 장바구니 객체 판매가 <b>%s</b> · 정가 %s · 새로 읽은 판매가 <b>%s</b> · 정가 %s · 줄 소계 %s<br>'
			. '<span style="color:#666">가격 비슷한 칸: %s</span><br><span style="color:#666;word-break:break-all">옵션 JSON: %s</span></div>',
			esc_html( $r['name'] ),
			'막힘' === $r['stale'] ? '#B32D2E' : '#1E7B34',
			esc_html( $r['stale'] ),
			esc_html( number_format( $r['stored'] ) ),
			esc_html( $r['skey'] ),
			esc_html( number_format( $r['cart_p'] ) ),
			esc_html( number_format( $r['cart_r'] ) ),
			esc_html( number_format( $r['new_p'] ) ),
			esc_html( number_format( $r['new_r'] ) ),
			esc_html( number_format( $r['sub'] ) ),
			esc_html( '' !== $r['keys'] ? $r['keys'] : '(없음)' ),
			esc_html( '' !== $r['bag'] ? $r['bag'] : '(없음)' )
		);
	}
	echo '</div>';
}
add_action( 'woocommerce_before_cart', __NAMESPACE__ . '\\diag', 1 );
add_action( 'woocommerce_before_checkout_form', __NAMESPACE__ . '\\diag', 1 );
add_action( 'wp_body_open', function () {
	// 키플 장바구니 · 결제 페이지는 워드커머스 훅을 안 쏠 수 있다 — 화면 맨 위에도 한 번.
	if ( ( function_exists( 'is_cart' ) && is_cart() ) || ( function_exists( 'is_checkout' ) && is_checkout() ) ) {
		diag();
	}
}, 99 );
