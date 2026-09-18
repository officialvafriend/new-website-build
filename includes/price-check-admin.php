<?php
/**
 * 도구 → 금액 점검 (관리자 · 읽기 전용).
 *
 * 담아 둔 뒤 값이 바뀐 장바구니가 옛 금액으로 결제되면, 주문에는 그 순간의
 * 기준가(`_wd_base_price`)가 그대로 남는다. 그것을 **지금 상품의 판매가와 견줘**
 * 어긋난 주문을 찾는다.
 *
 * 주문 하나씩 열지 않는다 — 주문 항목 표를 **한 번의 질의**로 읽는다
 * (매출 화면의 `fee_map()` 과 같은 방법. 이 표는 HPOS 를 켜도 그대로다).
 *
 * **읽기만 한다.** 주문 · 상품에 한 글자도 쓰지 않는다 — 옛 주문의 금액은 그때
 * 받은 값 그대로 남아야 맞다. 무엇을 할지는 사장님이 정한다.
 *
 * @package Duckhoo\Redesign
 */

declare( strict_types = 1 );

namespace Duckhoo\Redesign\PriceCheck\Admin;

use function Duckhoo\Redesign\PriceCheck\slack;

defined( 'ABSPATH' ) || exit;

const SLUG = 'duckhoo-pricecheck';

/**
 * 볼 수 있는가.
 *
 * @return bool
 */
function may(): bool {
	return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
}

/**
 * 메뉴.
 *
 * @return void
 */
function menu(): void {
	if ( ! may() ) {
		return;
	}
	add_management_page(
		'금액 점검',
		'금액 점검',
		current_user_can( 'manage_woocommerce' ) ? 'manage_woocommerce' : 'manage_options',
		SLUG,
		__NAMESPACE__ . '\\screen'
	);
}
add_action( 'admin_menu', __NAMESPACE__ . '\\menu' );

/**
 * 기준가가 실린 주문 줄들 — 한 번의 질의로.
 *
 * @param int $limit 몇 줄까지.
 * @return array<int,array<string,mixed>>
 */
function lines( int $limit = 400 ): array {
	global $wpdb;
	if ( ! isset( $wpdb ) ) {
		return array();
	}
	$sql = 'SELECT oi.order_id, oi.order_item_id, oi.order_item_name,
			MAX(CASE WHEN m.meta_key = %s THEN m.meta_value END) AS base,
			MAX(CASE WHEN m.meta_key = %s THEN m.meta_value END) AS pid,
			MAX(CASE WHEN m.meta_key = %s THEN m.meta_value END) AS sub,
			MAX(CASE WHEN m.meta_key = %s THEN m.meta_value END) AS qty
		FROM ' . $wpdb->prefix . 'woocommerce_order_items oi
		JOIN ' . $wpdb->prefix . 'woocommerce_order_itemmeta m ON m.order_item_id = oi.order_item_id
		WHERE oi.order_item_type = %s
		GROUP BY oi.order_item_id
		HAVING base IS NOT NULL AND base <> %s
		ORDER BY oi.order_item_id DESC
		LIMIT %d';

	return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB
		$wpdb->prepare( $sql, '_wd_base_price', '_product_id', '_line_subtotal', '_qty', 'line_item', '', max( 1, min( 2000, $limit ) ) ),
		ARRAY_A
	);
}

/**
 * 상품의 지금 판매가 (한 번 읽고 기억한다).
 *
 * @param int $pid 상품 번호.
 * @return array{price: float, name: string}|null
 */
function product_now( int $pid ): ?array {
	static $memo = array();
	if ( array_key_exists( $pid, $memo ) ) {
		return $memo[ $pid ];
	}
	$p = $pid > 0 && function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : null;
	$memo[ $pid ] = ( is_object( $p ) && method_exists( $p, 'get_price' ) )
		? array( 'price' => (float) $p->get_price(), 'name' => (string) $p->get_name() )
		: null;

	return $memo[ $pid ];
}

/**
 * 화면.
 *
 * @return void
 */
function screen(): void {
	if ( ! may() ) {
		wp_die( '권한이 없습니다.' );
	}

	$limit = isset( $_GET['dhr_n'] ) ? (int) $_GET['dhr_n'] : 400; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$rows  = lines( $limit );
	$bad   = array();
	$sum   = 0.0;

	foreach ( $rows as $r ) {
		$now = product_now( (int) $r['pid'] );
		if ( ! $now || $now['price'] <= 0 ) {
			continue;
		}
		$base = (float) $r['base'];
		$gap  = $now['price'] - $base;
		if ( $base <= 0 || abs( $gap ) < slack() ) {
			continue;
		}
		$qty          = max( 1, (int) $r['qty'] );
		$r['now']     = $now['price'];
		$r['pname']   = $now['name'];
		$r['gap']     = $gap;
		$r['gap_all'] = $gap * $qty;
		$bad[]        = $r;
		$sum         += $gap * $qty;
	}

	echo '<div class="wrap"><h1>금액 점검</h1>';
	echo '<p style="max-width:56em;line-height:1.7">장바구니에 담을 때 굳은 <b>기준가</b>와 <b>지금 상품의 판매가</b>를 견줍니다. '
		. '손님이 담아 둔 뒤 가격을 올리면, 그 장바구니는 <b>옛 금액으로 결제됩니다</b> — 테마의 금액 검증은 '
		. '「옵션 행이 말하는 금액」과 「실제로 물린 금액」만 보기 때문에 둘 다 옛 값이면 그냥 통과합니다. '
		. '<b>이 화면은 읽기만 합니다.</b></p>';

	printf(
		'<p class="description">최근 주문 줄 <b>%s개</b>를 읽었습니다 (기준가가 실린 줄만). 더 보시려면 주소에 <code>&dhr_n=1000</code> 을 붙이세요. 차이가 %s원 미만이면 넘어갑니다.</p>',
		esc_html( number_format( count( $rows ) ) ),
		esc_html( number_format( slack() ) )
	);

	if ( ! $bad ) {
		echo '<div class="notice notice-success"><p><b>어긋난 주문이 없습니다.</b> 읽은 범위 안에서는 전부 지금 판매가와 맞습니다.</p></div></div>';
		return;
	}

	printf(
		'<div class="notice notice-error"><p><b>%d건</b>이 어긋납니다. 덜 받은 금액 합계 <b>%s원</b> (음수는 더 받은 것입니다).</p></div>',
		count( $bad ),
		esc_html( number_format( $sum ) )
	);

	echo '<div style="overflow-x:auto"><table class="widefat striped"><thead><tr>'
		. '<th>주문</th><th>상태</th><th>상품</th><th class="dhr-n">담긴 기준가</th><th class="dhr-n">지금 판매가</th>'
		. '<th class="dhr-n">차액</th><th class="dhr-n">수량</th><th class="dhr-n">줄 차액</th></tr></thead><tbody>';

	foreach ( $bad as $r ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $r['order_id'] ) : null;
		$num   = is_object( $order ) && method_exists( $order, 'get_order_number' ) ? (string) $order->get_order_number() : (string) $r['order_id'];
		$state = is_object( $order ) && function_exists( 'wc_get_order_status_name' ) ? (string) wc_get_order_status_name( (string) $order->get_status() ) : '';
		$url   = is_object( $order ) && method_exists( $order, 'get_edit_order_url' ) ? (string) $order->get_edit_order_url() : '';

		printf(
			'<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td>'
			. '<td style="text-align:right">%4$s원</td><td style="text-align:right">%5$s원</td>'
			. '<td style="text-align:right;color:%6$s"><b>%7$s원</b></td><td style="text-align:right">%8$d</td>'
			. '<td style="text-align:right;color:%6$s"><b>%9$s원</b></td></tr>',
			'' !== $url ? '<a href="' . esc_url( $url ) . '">#' . esc_html( $num ) . '</a>' : '#' . esc_html( $num ),
			esc_html( $state ),
			esc_html( (string) ( $r['pname'] ?: $r['order_item_name'] ) ),
			esc_html( number_format( (float) $r['base'] ) ),
			esc_html( number_format( (float) $r['now'] ) ),
			(float) $r['gap'] > 0 ? '#B32D2E' : '#1E7B34',
			esc_html( number_format( (float) $r['gap'] ) ),
			(int) $r['qty'],
			esc_html( number_format( (float) $r['gap_all'] ) )
		);
	}
	echo '</tbody></table></div>';

	echo '<p style="max-width:56em;line-height:1.7;margin-top:1rem"><b>읽는 법.</b> '
		. '<span style="color:#B32D2E">빨간 차액</span>은 <b>덜 받은 것</b>입니다 (담긴 기준가가 지금 판매가보다 쌌다). '
		. '<span style="color:#1E7B34">초록</span>은 더 받은 것이라 손님에게 돌려줄 몫입니다. '
		. '이미 배송된 주문은 그대로 두시고, <b>아직 입금 전이면</b> 손님에게 안내하고 다시 주문받는 편이 깔끔합니다. '
		. '앞으로 들어올 주문은 이제 결제 단계에서 막습니다 — 손님에게 「장바구니에서 빼고 다시 담아 주세요」라고 안내합니다.</p>';

	echo '</div>';
}
