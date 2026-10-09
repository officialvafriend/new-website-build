<?php
/**
 * 한 상품 안에서 병 수 고르기 — 세트 수 + 할인 줄 (2026-10-09).
 *
 * 사장님: 「크런틴 · 라라스윗처럼 원페이지에서」. 테마 옵션 빌더는 구성 칸 값을 늘 「기본가 × 수량」으로만
 * 계산하고 (`wd-option-builder.js` `detectUnitPrice`), 맛 검사 다섯 곳은 「구성 수량 × 비율」로 센다.
 * 그래서 **값이 다른 구성(33병 198,000 · 55병 297,000)을 구성 칸에 둘 수는 없다.** 대신
 *
 * - 대량: 구성 = 「10+1 세트」 하나. 33병 = 3세트 · 55병 = 5세트. 비율 11 (상품명의 「10+1」을 테마가 읽는다)
 * - 젤로: 구성 칸에 「기기 + 액상 5병」과 「액상 5병 추가」. 10병 = 두 줄. 비율 5
 *
 * 로 담고, 기본가 × 수량과 사장님 값의 차이를 **장바구니 할인 줄(fee)** 로 맞춘다. 할인 줄은 줄 금액
 * (`line_subtotal`)을 안 건드리므로 테마 금액 검사(`dh-price-guard`)와 부딪히지 않고, 결제 화면은
 * `form-checkout.php` 172–177행의 안전망이 총액을 워드커머스 실제 금액에 맞추며 차이를 「할인」 줄에 적는다.
 *
 * **테마 · 스니펫 · 폼 필드는 그대로다.** 이 파일이 하는 것은 할인 줄 하나와 검사 셋(담기 · 장바구니 ·
 * 결제 직전)뿐이다. 대상 상품은 `config()` 에 번호로 적는다 — 비어 있으면 아무 일도 안 한다.
 *
 * @package Duckhoo\Redesign
 */

namespace Duckhoo\Redesign\BulkSets;

defined( 'ABSPATH' ) || exit;

/**
 * 대상 상품과 고를 수 있는 구성.
 *
 * - `kind => sets`  : 구성 수량(세트 수)이 키. `per_set` = 세트당 병 수, `lot` = 같은 맛을 담는 단위(10),
 *                     서비스 병은 세트마다 1병 · 맛마다 1병까지
 * - `kind => addon` : 구성 줄 수가 키. `extra` = 덧붙는 구성 줄 이름에 든 낱말 (「추가」)
 *
 * `choices[키] = array( label, fee )` — fee 는 깎아 줄 금액(양수).
 *
 * @return array<int,array>
 */
function config(): array {
	$c = (array) apply_filters( 'duckhoo_bulk_sets', array() );
	$out = array();
	foreach ( $c as $pid => $cfg ) {
		if ( (int) $pid > 0 && is_array( $cfg ) && ! empty( $cfg['choices'] ) ) {
			$out[ (int) $pid ] = $cfg;
		}
	}
	return $out;
}

/**
 * 상품 하나의 설정.
 *
 * @param int $pid 상품 번호.
 * @return array|null
 */
function of( int $pid ): ?array {
	$c = config();
	return $c[ $pid ] ?? null;
}

/**
 * 빌더 줄을 고른다 — 배열이든 JSON 이든.
 *
 * @param mixed $raw 줄.
 * @return array<int,array{group:string,type:string,label:string,qty:int}>
 */
function rows( $raw ): array {
	if ( is_string( $raw ) ) {
		$raw = json_decode( function_exists( 'wp_unslash' ) ? wp_unslash( $raw ) : $raw, true );
	}
	$out = array();
	foreach ( (array) $raw as $r ) {
		if ( ! is_array( $r ) ) {
			continue;
		}
		$q = (int) ( $r['qty'] ?? 0 );
		if ( $q < 1 ) {
			continue;
		}
		$g     = (string) ( $r['group_key'] ?? '' );
		$out[] = array(
			'group' => $g,
			'type'  => 'required_main' === $g || 'required' === (string) ( $r['type'] ?? '' ) ? 'required' : 'addon',
			'label' => (string) ( $r['label'] ?? '' ),
			'qty'   => $q,
		);
	}
	return $out;
}

/**
 * 고른 구성의 키 — sets 면 세트 수, addon 이면 구성 줄 수. 모르면 0.
 *
 * @param array $rows 줄.
 * @param array $cfg  설정.
 * @return int
 */
function key_of( array $rows, array $cfg ): int {
	$req = array_values( array_filter( $rows, fn( $r ) => 'required' === $r['type'] ) );
	if ( 'addon' === ( $cfg['kind'] ?? 'sets' ) ) {
		$extra = (string) ( $cfg['extra'] ?? '추가' );
		$main  = 0;
		$add   = 0;
		foreach ( $req as $r ) {
			if ( '' !== $extra && false !== mb_strpos( $r['label'], $extra ) ) {
				$add += $r['qty'];
			} else {
				$main += $r['qty'];
			}
		}
		return 1 === $main ? 1 + $add : 0;
	}
	return (int) array_sum( array_column( $req, 'qty' ) );
}

/**
 * 규칙에 맞는가 — 맞으면 빈 문자열, 아니면 손님에게 보일 말. 순수 함수.
 *
 * @param array $rows 줄.
 * @param array $cfg  설정.
 * @return string
 */
function check( array $rows, array $cfg ): string {
	$choices = (array) ( $cfg['choices'] ?? array() );
	$key     = key_of( $rows, $cfg );
	$names   = implode( ' · ', array_map( fn( $c ) => (string) ( $c['label'] ?? '' ), $choices ) );
	if ( ! isset( $choices[ $key ] ) ) {
		return 'addon' === ( $cfg['kind'] ?? 'sets' )
			? '구성을 다시 골라 주세요 (' . $names . ' 중 하나 · 기기는 한 대).'
			: '구성을 다시 골라 주세요 (' . $names . ' 중 하나).';
	}
	if ( 'sets' !== ( $cfg['kind'] ?? 'sets' ) ) {
		return '';
	}
	$lot  = max( 1, (int) ( $cfg['lot'] ?? 10 ) );
	$tens = 0;
	$ones = 0;
	foreach ( $rows as $r ) {
		if ( 'addon_1' !== $r['group'] ) {
			continue;
		}
		$rest = $r['qty'] % $lot;
		if ( $rest > 1 ) {
			return '같은 맛은 ' . $lot . '병씩 담아 주세요. 서비스 병은 맛마다 1병까지입니다.';
		}
		$tens += intdiv( $r['qty'], $lot );
		$ones += $rest;
	}
	if ( $tens !== $key || $ones !== $key ) {
		$label = (string) ( $choices[ $key ]['label'] ?? '' );
		return $label . ' 구성은 ' . $lot . '병씩 ' . $key . '번 + 서비스 ' . $key . '병(맛마다 1병)입니다. 맛을 다시 맞춰 주세요.';
	}
	return '';
}

/**
 * 깎아 줄 금액 (양수) — 규칙에 안 맞으면 0.
 *
 * @param array $rows 줄.
 * @param array $cfg  설정.
 * @return int
 */
function fee_for( array $rows, array $cfg ): int {
	if ( '' !== check( $rows, $cfg ) ) {
		return 0;
	}
	$c = $cfg['choices'][ key_of( $rows, $cfg ) ] ?? array();
	return max( 0, (int) ( $c['fee'] ?? 0 ) );
}

/**
 * 할인 줄 이름.
 *
 * @param array $rows 줄.
 * @param array $cfg  설정.
 * @return string
 */
function fee_name( array $rows, array $cfg ): string {
	$c = $cfg['choices'][ key_of( $rows, $cfg ) ] ?? array();
	return trim( (string) ( $cfg['name'] ?? '' ) . ' ' . (string) ( $c['label'] ?? '' ) . ' 구성 할인' );
}

/**
 * 장바구니에 할인 줄을 붙인다. 이름이 같으면 합친다 (워드커머스는 같은 이름 fee 를 두 번 못 붙인다).
 *
 * 우선순위 120 — 옛 프론트(duckhoo-front)가 100 에서 잘못 붙은 자동 할인 fee 를 걷어 내므로 그 뒤에 붙인다.
 *
 * @param mixed $cart 장바구니.
 * @return void
 */
function add_fees( $cart ): void {
	if ( ! is_object( $cart ) || ! method_exists( $cart, 'get_cart' ) || ! config() ) {
		return;
	}
	$sum = array();
	foreach ( (array) $cart->get_cart() as $item ) {
		$cfg = of( (int) ( $item['product_id'] ?? 0 ) );
		if ( ! $cfg ) {
			continue;
		}
		$rows = rows( $item['wd_option_builder'] ?? array() );
		$fee  = fee_for( $rows, $cfg ) * max( 1, (int) ( $item['quantity'] ?? 1 ) );
		if ( $fee > 0 ) {
			$n         = fee_name( $rows, $cfg );
			$sum[ $n ] = ( $sum[ $n ] ?? 0 ) + $fee;
		}
	}
	foreach ( $sum as $n => $fee ) {
		$cart->add_fee( $n, -1 * $fee, false );
	}
}

/**
 * 담기 검증 (폼).
 *
 * @param mixed $passed 지금까지의 판정.
 * @param mixed $pid    상품 번호.
 * @return bool
 */
function validate_add( $passed, $pid = 0 ): bool {
	$cfg = of( (int) $pid );
	if ( ! $passed || ! $cfg ) {
		return (bool) $passed;
	}
	$msg = check( rows( (string) ( $_POST['wd_option_builder_json'] ?? '' ) ), $cfg ); // phpcs:ignore WordPress.Security.NonceVerification
	if ( '' === $msg ) {
		return true;
	}
	if ( function_exists( 'wc_add_notice' ) ) {
		wc_add_notice( $msg, 'error' );
	}
	return false;
}

/**
 * Store API `add-item` — 구성 · 맛을 실을 수 없는 길이라 이 상품은 못 담는다.
 *
 * @param mixed $product 상품.
 * @return void
 */
function store_add( $product ): void {
	if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) || ! of( (int) $product->get_id() ) ) {
		return;
	}
	$msg = '상품 화면에서 구성과 맛을 골라 담아 주세요.';
	if ( class_exists( '\\Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException' ) ) {
		throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'duckhoo_bulk_sets', $msg, 400 );
	}
	throw new \RuntimeException( $msg );
}

/**
 * 장바구니에서 규칙에 안 맞는 줄을 알린다 (결제로 못 넘어간다).
 *
 * @return void
 */
function check_cart(): void {
	if ( ! function_exists( 'WC' ) || ! WC()->cart || ! config() ) {
		return;
	}
	foreach ( (array) WC()->cart->get_cart() as $item ) {
		$cfg = of( (int) ( $item['product_id'] ?? 0 ) );
		if ( ! $cfg ) {
			continue;
		}
		$msg = check( rows( $item['wd_option_builder'] ?? array() ), $cfg );
		if ( '' !== $msg && function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( $msg . ' 장바구니에서 그 상품을 빼고 다시 담아 주세요.', 'error' );
		}
	}
}

/**
 * 마지막 빗장 — 어느 길로 왔든 주문은 여기를 지난다.
 *
 * @return void
 */
function guard_order(): void {
	if ( ! function_exists( 'WC' ) || ! WC()->cart || ! config() ) {
		return;
	}
	foreach ( (array) WC()->cart->get_cart() as $item ) {
		$cfg = of( (int) ( $item['product_id'] ?? 0 ) );
		if ( $cfg ) {
			$msg = check( rows( $item['wd_option_builder'] ?? array() ), $cfg );
			if ( '' !== $msg ) {
				throw new \Exception( esc_html( $msg . ' 장바구니에서 그 상품을 빼고 다시 담아 주세요.' ) );
			}
		}
	}
}

if ( function_exists( 'add_filter' ) ) {
	add_action( 'woocommerce_cart_calculate_fees', __NAMESPACE__ . '\\add_fees', 120 );
	add_filter( 'woocommerce_add_to_cart_validation', __NAMESPACE__ . '\\validate_add', 16, 2 );
	add_action( 'woocommerce_store_api_validate_add_to_cart', __NAMESPACE__ . '\\store_add', 10, 1 );
	add_action( 'woocommerce_check_cart_items', __NAMESPACE__ . '\\check_cart', 30 );
	add_action( 'woocommerce_checkout_create_order', __NAMESPACE__ . '\\guard_order', 4 );
}
