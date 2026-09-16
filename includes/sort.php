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
 * 이 쿼리가 **우리가 정렬할 상품 검색**인가.
 *
 * @param mixed $q 쿼리.
 * @return bool
 */
function ours( $q ): bool {
	if ( is_admin() || ! is_object( $q ) || ! method_exists( $q, 'is_main_query' ) || ! $q->is_main_query() ) {
		return false;
	}
	if ( ! method_exists( $q, 'is_search' ) || ! $q->is_search() ) {
		return false;
	}
	$pt = $q->get( 'post_type' );
	$pt = is_array( $pt ) ? $pt : array( $pt );
	if ( ! in_array( 'product', $pt, true ) ) {
		return false;
	}
	return '' !== current();
}

/**
 * **검색 결과만** 우리가 정렬한다 — 워드커머스는 이 쿼리에 손대지 않는다.
 *
 * `$q->set( 'orderby', … )` 로 부탁하지 않고 **ORDER BY 를 마지막에 직접 쓴다.**
 * 부탁하는 방식은 그 뒤에 도는 누군가(테마 · 플러그인)가 도로 덮으면 조용히
 * 지고, 화면에는 정렬 줄만 켜진 채 순서는 그대로다 — 실제로 그렇게 한 번 졌다
 * (2026-09-16). 우선순위 999 로 마지막에 쓰면 그 다툼이 없다.
 *
 * 값은 **곁붙임 질의**로 읽는다 — `meta_key` 를 걸면 JOIN 과 WHERE 가 따라붙어
 * 그 칸이 없는 상품이 결과에서 빠질 수 있다. 정렬 때문에 상품이 사라지면 안 된다.
 *
 * @param string $orderby 지금까지의 ORDER BY.
 * @param mixed  $q       쿼리.
 * @return string
 */
function order_sql( $orderby, $q = null ): string {
	global $wpdb;
	if ( ! ours( $q ) ) {
		return (string) $orderby;
	}
	if ( 'date' === current() ) {
		return "{$wpdb->posts}.post_date DESC";
	}
	$dir = 'price-desc' === current() ? 'DESC' : 'ASC';
	return "(SELECT CAST(dhr_pm.meta_value AS DECIMAL(20,4)) FROM {$wpdb->postmeta} dhr_pm"
		. " WHERE dhr_pm.post_id = {$wpdb->posts}.ID AND dhr_pm.meta_key = '_price' LIMIT 1) {$dir}";
}
add_filter( 'posts_orderby', __NAMESPACE__ . '\\order_sql', 999, 2 );

/**
 * 조각 전체(`posts_clauses`)에도 같은 것을 쓴다 — **여기가 마지막 자리다.**
 *
 * 워드프레스는 `posts_orderby` 를 부른 **뒤에** `posts_clauses` 로 조각을 통째로
 * 한 번 더 묻는다. 그래서 `posts_orderby` 에 아무리 마지막 우선순위로 써도
 * 누군가 `posts_clauses` 에서 `orderby` 를 갈아끼우면 조용히 진다 — 실제로
 * 그렇게 한 번 더 졌다 (2026-09-16, 「우리가 정렬=y」인데 순서는 그대로였다).
 *
 * @param array $clauses 조각들.
 * @param mixed $q       쿼리.
 * @return array
 */
function order_clauses( $clauses, $q = null ): array {
	$clauses = (array) $clauses;
	if ( ! ours( $q ) ) {
		return $clauses;
	}
	$GLOBALS['dhr_sort_was'] = (string) ( $clauses['orderby'] ?? '' );
	$clauses['orderby']      = order_sql( '', $q );
	return $clauses;
}
add_filter( 'posts_clauses', __NAMESPACE__ . '\\order_clauses', 999, 2 );

/**
 * `?dhr_sort=1` — 정렬이 안 먹을 때 **한 번 받아 보면 되는** 쪽지.
 *
 * 화면 아래 주석으로 무엇을 보고 무엇을 썼는지 적는다. 사람에게 캡처를
 * 부탁하지 않고 주소 한 번으로 가른다.
 *
 * @return void
 */
function note(): void {
	if ( ! isset( $_GET['dhr_sort'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	$q = $GLOBALS['wp_query'] ?? null;
	printf(
		"\n<!-- dhr-sort: 고른 것=%s · 검색=%s · 메인=%s · post_type=%s · 우리가 정렬=%s · 건수=%d -->\n",
		esc_html( current() ),
		is_object( $q ) && method_exists( $q, 'is_search' ) && $q->is_search() ? 'y' : 'n',
		is_object( $q ) && method_exists( $q, 'is_main_query' ) && $q->is_main_query() ? 'y' : 'n',
		esc_html( wp_json_encode( is_object( $q ) ? $q->get( 'post_type' ) : null ) ),
		ours( $q ) ? 'y' : 'n',
		is_object( $q ) ? (int) $q->found_posts : 0
	);
	printf(
		"<!-- dhr-sort 조각: 원래=%s · 우리가 쓴 것=%s -->\n",
		esc_html( (string) ( $GLOBALS['dhr_sort_was'] ?? '(안 걸림)' ) ),
		esc_html( order_sql( '', $q ) )
	);
}
add_action( 'wp_footer', __NAMESPACE__ . '\\note', 99 );
