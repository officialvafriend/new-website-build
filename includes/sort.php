<?php
/**
 * 상품 목록 정렬 — 가격 낮은 순 · 높은 순.
 *
 * 워드커머스는 `?orderby=price` 를 **분류 · 전체 상품 목록에서는 이미 알아듣는다**
 * (`WC_Query::product_query()`). 없던 것은 그것을 누를 자리였다 — 우리 목록
 * 템플릿이 부르는 `woocommerce_catalog_ordering()` 은 루프 설정(`wc_setup_loop()`)
 * 없이는 아무것도 그리지 않아 화면에 한 번도 나온 적이 없다.
 *
 * **검색 결과는 다르다.** `?s=화이트&post_type=product` 는 `is_search()` 라
 * 워드커머스의 `pre_get_posts` 가 일찍 물러난다 — 실측으로 `orderby` 를 붙여도
 * 순서가 한 칸도 안 바뀌는 것을 확인했다 (2026-09-16). 그 화면만 우리가 정렬한다.
 *
 * - **분류 · 전체 목록은 워드커머스가 하던 대로 둔다.** 같은 일을 두 번 하지 않는다
 * - 정렬은 **주소(`?orderby=`)로 간다** — 링크라 JS 없이 되고, 손님이 주소를
 *   복사해 주면 같은 화면이 열린다
 * - 페이지 번호는 떼고 옮긴다. 3쪽을 보다 정렬을 바꾸면 3쪽부터 보여 줄 이유가 없다
 *
 * @package Duckhoo\Redesign
 */

declare( strict_types = 1 );

namespace Duckhoo\Redesign\Sort;

defined( 'ABSPATH' ) || exit;

/**
 * 고를 수 있는 순서 — 열쇠는 워드커머스가 쓰는 것과 같은 말이다.
 *
 * @return array<string,string>
 */
function options(): array {
	return (array) apply_filters(
		'duckhoo_sort_options',
		array(
			''           => '추천순',
			'price'      => '낮은 가격',
			'price-desc' => '높은 가격',
			'date'       => '신상품',
		)
	);
}

/**
 * 지금 고른 순서.
 *
 * @return string
 */
function current(): string {
	$by = isset( $_GET['orderby'] ) ? sanitize_key( (string) wp_unslash( $_GET['orderby'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	return array_key_exists( $by, options() ) ? $by : '';
}

/**
 * 이 화면에 정렬을 보여 줄까 — 상품이 깔리는 목록에서만.
 *
 * @return bool
 */
function showing(): bool {
	if ( is_admin() ) {
		return false;
	}
	$on = is_shop() || is_product_taxonomy() || ( is_search() && 'product' === get_query_var( 'post_type' ) )
		|| (bool) get_query_var( 'dhr_brand' );
	return (bool) apply_filters( 'duckhoo_sort_on', $on );
}

/**
 * 지금 주소에서 페이지 번호만 뗀 것.
 *
 * 3쪽을 보다 정렬을 바꾸면 **1쪽부터** 보여 준다 — 3쪽에 있던 상품은 순서가
 * 바뀌면 이미 다른 자리다.
 *
 * @return string
 */
function base_url(): string {
	$url = home_url( add_query_arg( array() ) );
	$url = (string) preg_replace( '#/page/\d+/?#', '/', $url );
	return remove_query_arg( array( 'paged', 'page', 'product-page' ), $url );
}

/**
 * 그 순서로 가는 주소.
 *
 * @param string $by 순서.
 * @return string
 */
function url( string $by ): string {
	$base = base_url();
	return '' === $by ? remove_query_arg( 'orderby', $base ) : add_query_arg( 'orderby', $by, $base );
}

/**
 * 정렬 줄.
 *
 * @return string
 */
function html(): string {
	if ( ! showing() ) {
		return '';
	}
	$now = current();
	$out = '<nav class="dha-sort" aria-label="정렬">';
	foreach ( options() as $by => $label ) {
		$on   = $by === $now;
		$out .= sprintf(
			'<a class="%s" href="%s"%s>%s</a>',
			$on ? 'on' : '',
			esc_url( url( (string) $by ) ),
			$on ? ' aria-current="true"' : '',
			esc_html( $label )
		);
	}
	return $out . '</nav>';
}

/**
 * **검색 결과만** 우리가 정렬한다 — 워드커머스가 이 쿼리에는 손대지 않는다.
 *
 * 분류 · 전체 목록에서는 아무것도 하지 않는다. 같은 일을 두 번 하면 한쪽이
 * 바뀔 때 두 화면이 갈린다.
 *
 * @param \WP_Query $q 쿼리.
 * @return void
 */
function search_order( $q ): void {
	if ( is_admin() || ! is_object( $q ) || ! method_exists( $q, 'is_main_query' ) || ! $q->is_main_query() ) {
		return;
	}
	if ( ! method_exists( $q, 'is_search' ) || ! $q->is_search() ) {
		return;
	}
	$pt = $q->get( 'post_type' );
	$pt = is_array( $pt ) ? $pt : array( $pt );
	if ( ! in_array( 'product', $pt, true ) ) {
		return;
	}

	switch ( current() ) {
		case 'price':
		case 'price-desc':
			/* 워드커머스가 분류 목록에서 쓰는 것과 같은 칸이다 (`_price`). */
			$q->set( 'meta_key', '_price' ); // phpcs:ignore WordPress.DB.SlowDBQuery
			$q->set( 'orderby', 'meta_value_num' );
			$q->set( 'order', 'price' === current() ? 'ASC' : 'DESC' );
			break;
		case 'date':
			$q->set( 'orderby', 'date' );
			$q->set( 'order', 'DESC' );
			break;
	}
}
add_action( 'pre_get_posts', __NAMESPACE__ . '\\search_order', 20 );
