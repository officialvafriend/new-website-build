<?php
/**
 * 상품 상세 — 구매 상자를 우리가 그린다.
 *
 * 여태 한 건 키플 화면 위에 색만 얹은 것이었다. 조회수 · 제목 · 가격 · 오늘출발 ·
 * 무료배송 게이지 · 옵션 · 총액 · 버튼이 전부 같은 크기로 쌓여 있어서 무엇을 먼저
 * 봐야 하는지가 없었다.
 *
 * 여기서는 워드커머스 기본 제목 · 가격 훅을 우리 것으로 갈아끼우고, 살 때 필요한
 * 이야기(무통장입금 · 입금자명 · 출고 · 19세)를 버튼 아래에 붙인다.
 *
 * **구매 폼은 건드리지 않는다.** form.cart 는 워드커머스 · PPOM · 키플 옵션 UI 가
 * 그대로 그린다. 우리가 바꾸는 건 그 위아래뿐이다.
 *
 * @package Duckhoo\Redesign
 */

declare( strict_types = 1 );

namespace Duckhoo\Redesign\Product;

use function Duckhoo\Redesign\Front\{split_name, per_bottle, eyebrow, icon};

defined( 'ABSPATH' ) || exit;

/**
 * 우리 껍데기를 쓰는 상품 화면인가.
 *
 * @return bool
 */
function on(): bool {
	return ! is_admin() && function_exists( 'is_product' ) && is_product()
		&& (bool) apply_filters( 'duckhoo_take_product_summary', true );
}

/**
 * 제목 줄 — 브랜드 · 상태 배지 · 상품명.
 *
 * @return void
 */
function head(): void {
	global $product;
	if ( ! $product instanceof \WC_Product ) {
		return;
	}
	$n  = split_name( $product );
	$eb = eyebrow( $product );
	echo '<div class="dhp-eb">';
	if ( '' !== $n['brand'] ) {
		echo '<span class="dhp-brand">' . esc_html( $n['brand'] ) . '</span>';
	}
	if ( $eb ) {
		echo '<span class="dhp-badge dhp-badge--' . esc_attr( $eb[1] ) . '">' . esc_html( $eb[0] ) . '</span>';
	}
	echo '</div>';
	echo '<h1 class="product_title entry-title dhp-title">' . esc_html( $n['title'] ) . '</h1>';
}

/**
 * 가격 줄 — 파는 값이 주인공, 병당은 그 아래.
 *
 * @return void
 */
function price(): void {
	global $product;
	if ( ! $product instanceof \WC_Product ) {
		return;
	}
	$now = (float) $product->get_price();
	$was = (float) $product->get_regular_price();
	$pb  = per_bottle( $product );
	$off = ( $was > $now && $was > 0 ) ? (int) round( ( 1 - $now / $was ) * 100 ) : 0;

	echo '<div class="dhp-price">';
	if ( $off > 0 ) {
		echo '<span class="dhp-off">' . (int) $off . '%</span>';
		echo '<s class="dhp-was">' . esc_html( number_format_i18n( $was ) ) . '원</s>';
	}
	echo '<b class="dhp-now">' . esc_html( number_format_i18n( $now ) ) . '<span>원</span></b>';
	echo '</div>';

	if ( $pb['qty'] > 1 ) {
		echo '<div class="dhp-unit"><b>병당 ' . esc_html( number_format_i18n( $pb['per'] ) ) . '원</b>'
			. '<span>' . (int) $pb['qty'] . '병 묶음</span></div>';
	}
}

/**
 * 버튼 아래 — 이 가게에서 사는 방법. 결제 단계에서 처음 보면 늦는 이야기다.
 *
 * @return void
 */
function trust(): void {
	$rows = apply_filters(
		'duckhoo_product_trust',
		array(
			array( 'bank', '무통장입금 전용', '<b>입금자명을 주문자명과 똑같이</b> 넣어 주세요. 같으면 자동으로 확인됩니다.' ),
			array( 'truck', '평일 16시 이전 입금 확인 시 당일 출고', '30,000원 이상 무료배송 · 우체국택배' ),
			array( 'shield', '19세 미만 판매 금지', '구매 시 휴대폰 본인확인이 필요합니다 · 니코틴은 중독성이 있는 물질입니다' ),
		)
	);
	echo '<ul class="dhp-trust">';
	foreach ( $rows as $r ) {
		echo '<li><span class="dhp-trust__ic">' . icon( $r[0] ) . '</span>' // phpcs:ignore
			. '<span class="dhp-trust__tx"><b>' . esc_html( $r[1] ) . '</b>'
			. '<span>' . wp_kses( $r[2], array( 'b' => array() ) ) . '</span></span></li>';
	}
	echo '</ul>';
	echo '<p class="dhp-links"><a href="' . esc_url( home_url( '/shipping/' ) ) . '">배송 · 교환 · 환불 안내</a>'
		. '<a href="' . esc_url( home_url( '/inquiries/' ) ) . '">1:1 문의</a></p>';
}

/**
 * 훅 갈아끼우기. 워드커머스 기본 제목 · 가격만 빼고 나머지는 그대로 둔다.
 *
 * @return void
 */
function swap(): void {
	if ( ! on() ) {
		return;
	}
	remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_title', 5 );
	remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_price', 10 );
	add_action( 'woocommerce_single_product_summary', __NAMESPACE__ . '\\head', 5 );
	add_action( 'woocommerce_single_product_summary', __NAMESPACE__ . '\\price', 10 );
	add_action( 'woocommerce_single_product_summary', __NAMESPACE__ . '\\trust', 45 );
}
add_action( 'wp', __NAMESPACE__ . '\\swap' );
