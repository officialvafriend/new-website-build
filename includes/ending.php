<?php
/**
 * 단종 안내 — 재입고되지 않는 라인을 상품 화면 · 카드에서 알린다.
 *
 * 사장님 2026-11-06: 흑염룡이 단종됐다. 남은 재고는 다 팔릴 때까지 판다.
 * 안내 띠(헤더 아래)는 잘 안 보인다고 하셔서, 손님이 실제로 보는 자리 둘에 붙인다:
 *   - 상품 상세 가격 바로 아래 (`duckhoo_product_after_price` 1) — 그 라인 상품 · 그 맛을 담는 묶음
 *   - 목록 · 홈 카드 가격 아래 (`duckhoo_card_extra`) — 「단종 · 재고 한정」 알약
 *
 * 읽기만 한다. 재고 · 판매 여부는 워드커머스가 쥔다 — 재고가 0 이 된 맛은 원래대로 품절이 된다.
 * 안내는 **`form.cart` 바깥**이다 (구매 게이트가 폼 안 글자를 읽는다).
 *
 * 끄거나 바꾸기: `add_filter( 'duckhoo_ending_lines', '__return_empty_array' );`
 *
 * @package Duckhoo\Redesign
 */

namespace Duckhoo\Redesign\Ending;

defined( 'ABSPATH' ) || exit;

/**
 * 단종 라인 표. word 는 상품 이름 · 묶음 맛 칸에서 찾는 낱말.
 *
 * @return array<int,array{word:string,name:string,since:string}>
 */
function lines(): array {
	$v = apply_filters(
		'duckhoo_ending_lines',
		array(
			array(
				'word'  => '흑염룡',
				'name'  => '흑염룡',
				'since' => '2026-11-06',
			),
		)
	);
	return is_array( $v ) ? array_values( array_filter( $v, fn( $l ) => is_array( $l ) && '' !== (string) ( $l['word'] ?? '' ) ) ) : array();
}

/**
 * 글에 단종 라인 낱말이 있으면 그 라인. 빈칸은 보지 않는다. 순수 함수.
 *
 * @param string $text  상품 이름 또는 옵션 글.
 * @param array  $lines lines().
 * @return array|null
 */
function line_in( string $text, array $lines ): ?array {
	$t = preg_replace( '/\s+/u', '', $text );
	foreach ( $lines as $l ) {
		$w = preg_replace( '/\s+/u', '', (string) $l['word'] );
		if ( '' !== $w && false !== mb_strpos( (string) $t, (string) $w ) ) {
			return $l;
		}
	}
	return null;
}

/**
 * 묶음 상품의 맛 칸(PPOM 옵션 글)을 이어 붙인 것.
 *
 * @param int $pid 상품 번호.
 * @return string
 */
function option_text( int $pid ): string {
	$fields = array();
	if ( $pid > 0 && class_exists( '\PPOM_Meta' ) ) {
		try {
			$m      = new \PPOM_Meta( $pid );
			$fields = is_array( $m->fields ?? null ) ? $m->fields : array();
		} catch ( \Throwable $e ) {
			$fields = array();
		}
	}
	$fields = (array) apply_filters( 'duckhoo_ending_option_fields', $fields, $pid );
	$out    = array();
	foreach ( $fields as $f ) {
		foreach ( (array) ( $f['options'] ?? array() ) as $o ) {
			$out[] = is_array( $o ) ? (string) ( $o['option'] ?? '' ) : (string) $o;
		}
	}
	return implode( ' ', $out );
}

/**
 * 이 상품에 걸린 단종 라인과 「그 라인 상품인가 / 그 맛을 담는 묶음인가」.
 *
 * @param mixed $p 상품.
 * @return array{line:array,own:bool}|null
 */
function hit( $p ): ?array {
	if ( ! $p || ! is_object( $p ) || ! method_exists( $p, 'get_name' ) ) {
		return null;
	}
	$ls = lines();
	if ( ! $ls ) {
		return null;
	}
	$own = line_in( (string) $p->get_name(), $ls );
	if ( $own ) {
		return array( 'line' => $own, 'own' => true );
	}
	$opt = line_in( option_text( (int) $p->get_id() ), $ls );
	return $opt ? array( 'line' => $opt, 'own' => false ) : null;
}

/**
 * 상품 상세 안내 글. 순수 함수.
 *
 * @param array $h       hit().
 * @param bool  $instock 이 상품에 재고가 있나.
 * @return array{t:string,tag:string,p:string}
 */
function notice_text( array $h, bool $instock ): array {
	$n = (string) $h['line']['name'];
	if ( ! $h['own'] ) {
		return array(
			't'   => $n . ' 맛',
			'tag' => '단종 · 남은 재고 한정',
			'p'   => $n . ' 시리즈가 단종되어 재입고되지 않습니다. 이 묶음에서 ' . $n . ' 맛은 남은 재고까지만 고르실 수 있고, 다 팔린 맛은 고를 수 없게 됩니다.',
		);
	}
	return array(
		't'   => $n . ' 시리즈',
		'tag' => $instock ? '단종 · 남은 재고 한정' : '단종',
		'p'   => $instock
			? $n . ' 시리즈가 단종되어 재입고되지 않습니다. 지금 남은 재고가 다 팔리면 판매를 마칩니다.'
			: $n . ' 시리즈가 단종되어 재입고되지 않습니다. 이 맛은 재고가 모두 팔렸습니다.',
	);
}

/**
 * 상품 상세 — 가격 바로 아래.
 *
 * @param mixed $p 상품.
 * @return void
 */
function product_notice( $p = null ): void {
	$p = $p ?: ( $GLOBALS['product'] ?? null );
	$h = hit( $p );
	if ( ! $h ) {
		return;
	}
	$t = notice_text( $h, (bool) $p->is_in_stock() );
	echo '<div class="dhr-end" role="note">'
		. '<b class="dhr-end__t">' . esc_html( $t['t'] ) . ' <span>' . esc_html( $t['tag'] ) . '</span></b>'
		. '<p class="dhr-end__p">' . esc_html( $t['p'] ) . '</p>'
		. '</div>';
}
add_action( 'duckhoo_product_after_price', __NAMESPACE__ . '\\product_notice', 1 );

/**
 * 카드 — 그 라인 상품에만 알약 하나 (묶음 카드에는 안 붙인다: 묶음 자체는 계속 판다).
 *
 * @param string $extra 여태 붙은 것.
 * @param mixed  $p     상품.
 * @return string
 */
function card_note( string $extra, $p = null ): string {
	if ( ! $p || ! is_object( $p ) || ! method_exists( $p, 'get_name' ) ) {
		return $extra;
	}
	if ( ! line_in( (string) $p->get_name(), lines() ) ) {
		return $extra;
	}
	return $extra . '<div class="dhr-endtag">' . ( $p->is_in_stock() ? '단종 · 남은 재고 한정' : '단종' ) . '</div>';
}
add_filter( 'duckhoo_card_extra', __NAMESPACE__ . '\\card_note', 5, 2 );
