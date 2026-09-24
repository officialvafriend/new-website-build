<?php
/**
 * 주문 해부 — 이 가게의 주문 1,600여 건을 뜯어 **재구매 · 첫 구매 · 브랜드 이동 · 시간 · 지역 · 객단가 · 이탈**을 숫자로 본다.
 *
 * 사장님 (2026-09-24): 기획이 교과서가 아니라 이 가게 것이 되려면 주문 데이터부터. 「굉장한 시간 소모라 네가 해 주는 게 편함」.
 * 관리자 **매출 → 주문 해부**. 읽기만 한다. 주문 · 회원에 아무것도 쓰지 않는다.
 *
 * - 주문 머리(번호 · 시각 · 상태 · 금액 · 회원 · 지역)는 표를 **직접** 읽는다 (HPOS 면 `wc_orders` + `wc_order_addresses`,
 *   아니면 posts + postmeta). 주문 객체를 1,700개 깨우면 매출 화면처럼 죽는다
 * - 상품 줄은 `woocommerce_order_items` + `itemmeta` 한 번 (수수료 · 배송 줄은 뺀다). 브랜드는 이름 앞 `[브랜드]`
 * - 계산은 전부 **순수 함수** (`analyze()` 이하) — 테스트가 가짜 주문으로 본다
 * - 1시간 transient. 맨 아래 「클로드에게 보내기」 글상자 — 숫자를 통째로 붙여 넣어 기획을 다시 세우는 용도
 *
 * @package Duckhoo\Redesign
 */

declare( strict_types = 1 );

namespace Duckhoo\Redesign\Anatomy;

defined( 'ABSPATH' ) || exit;

const SLUG  = 'duckhoo-anatomy';
const CACHE = 'dhr_anatomy_v1';

function may(): bool {
	return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
}

function menu(): void {
	if ( ! may() ) {
		return;
	}
	add_submenu_page( 'duckhoo-sales', '주문 해부', '주문 해부', current_user_can( 'manage_woocommerce' ) ? 'manage_woocommerce' : 'manage_options', SLUG, __NAMESPACE__ . '\\screen' );
}
add_action( 'admin_menu', __NAMESPACE__ . '\\menu', 20 );

/* ── 읽기 ────────────────────────────────────────────────────────────── */

/**
 * 돈이 들어온 상태 (매출 화면과 같은 목록).
 *
 * @return string[]
 */
function paid_statuses(): array {
	return function_exists( '\\Duckhoo\\Redesign\\Sales\\confirmed_statuses' ) ? \Duckhoo\Redesign\Sales\confirmed_statuses() : array( 'payment-confirmed', 'ready-to-ship', 'shipping', 'delivered', 'completed', 'processing' );
}

function hpos(): bool {
	return class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
}

/**
 * 주문 머리 전부 (임시글 · 환불 기록 제외). 시각은 사이트 시간대의 timestamp.
 *
 * @return array<int,array{id:int,ts:int,s:string,t:float,u:int,city:string,state:string}>
 */
function orders(): array {
	global $wpdb;
	if ( ! isset( $wpdb ) ) {
		return array();
	}
	$off = (int) ( function_exists( 'wp_timezone' ) ? ( new \DateTime( 'now', wp_timezone() ) )->getOffset() : 9 * 3600 );
	$out = array();
	if ( hpos() ) {
		$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB
			"SELECT o.id, o.status, o.date_created_gmt AS d, o.total_amount AS t, o.customer_id AS u, a.city, a.state
			   FROM {$wpdb->prefix}wc_orders o
			   LEFT JOIN {$wpdb->prefix}wc_order_addresses a ON a.order_id = o.id AND a.address_type = 'shipping'
			  WHERE o.type = 'shop_order' AND o.status NOT IN ('wc-checkout-draft','trash','auto-draft')",
			ARRAY_A
		);
	} else {
		$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB
			"SELECT p.ID AS id, p.post_status AS status, p.post_date_gmt AS d,
			        MAX(CASE WHEN m.meta_key='_order_total' THEN m.meta_value END) AS t,
			        MAX(CASE WHEN m.meta_key='_customer_user' THEN m.meta_value END) AS u,
			        MAX(CASE WHEN m.meta_key='_shipping_city' THEN m.meta_value END) AS city,
			        MAX(CASE WHEN m.meta_key='_shipping_state' THEN m.meta_value END) AS state
			   FROM {$wpdb->posts} p
			   LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key IN ('_order_total','_customer_user','_shipping_city','_shipping_state')
			  WHERE p.post_type = 'shop_order' AND p.post_status NOT IN ('wc-checkout-draft','trash','auto-draft')
			  GROUP BY p.ID",
			ARRAY_A
		);
	}
	foreach ( $rows as $r ) {
		$ts = strtotime( (string) ( $r['d'] ?? '' ) . ' UTC' );
		if ( false === $ts ) {
			continue;
		}
		$out[] = array(
			'id'    => (int) $r['id'],
			'ts'    => $ts + $off,
			's'     => preg_replace( '/^wc-/', '', (string) $r['status'] ),
			't'     => (float) ( $r['t'] ?? 0 ),
			'u'     => (int) ( $r['u'] ?? 0 ),
			'city'  => trim( (string) ( $r['city'] ?? '' ) ),
			'state' => trim( (string) ( $r['state'] ?? '' ) ),
		);
	}
	return $out;
}

/**
 * 상품 줄 — 주문 번호 => [ [pid, name, qty, total], … ]. 한 질의.
 *
 * @param int[] $ids 주문 번호.
 * @return array<int,array<int,array{pid:int,name:string,qty:int,total:float}>>
 */
function items( array $ids ): array {
	global $wpdb;
	$out = array();
	if ( ! $ids || ! isset( $wpdb ) ) {
		return $out;
	}
	foreach ( array_chunk( array_map( 'intval', $ids ), 500 ) as $chunk ) {
		$in   = implode( ',', $chunk );
		$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB
			"SELECT oi.order_id AS oid, oi.order_item_name AS name,
			        MAX(CASE WHEN m.meta_key='_product_id' THEN m.meta_value END) AS pid,
			        MAX(CASE WHEN m.meta_key='_qty' THEN m.meta_value END) AS qty,
			        MAX(CASE WHEN m.meta_key='_line_total' THEN m.meta_value END) AS total
			   FROM {$wpdb->prefix}woocommerce_order_items oi
			   LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta m ON m.order_item_id = oi.order_item_id
			  WHERE oi.order_item_type = 'line_item' AND oi.order_id IN ({$in})
			  GROUP BY oi.order_item_id",
			ARRAY_A
		);
		foreach ( $rows as $r ) {
			$out[ (int) $r['oid'] ][] = array(
				'pid'   => (int) ( $r['pid'] ?? 0 ),
				'name'  => (string) ( $r['name'] ?? '' ),
				'qty'   => max( 1, (int) ( $r['qty'] ?? 1 ) ),
				'total' => (float) ( $r['total'] ?? 0 ),
			);
		}
	}
	return $out;
}

/* ── 순수 함수 ───────────────────────────────────────────────────────── */

/**
 * 이름 앞 `[브랜드]` → 묶은 브랜드. 없으면 '기타'.
 */
function brand( string $name ): string {
	if ( ! preg_match( '/^\s*\[([^\]]+)\]/u', $name, $m ) ) {
		return '기타';
	}
	$b = trim( $m[1] );
	if ( preg_match( '/이벤트|할인|특가/u', $b ) ) {
		return '기타';
	}
	$al = function_exists( '\\Duckhoo\\Redesign\\Front\\brand_aliases' ) ? \Duckhoo\Redesign\Front\brand_aliases() : array( '노보 블랙' => '노보', '노보 리퀴드' => '노보', '노보 블랙 리퀴드' => '노보' );
	return (string) ( $al[ $b ] ?? $b );
}

/**
 * 상품 이름에서 브랜드 · 행사 꼬리를 뗀 짧은 이름.
 */
function short( string $name ): string {
	$n = preg_replace( '/^\s*\[[^\]]+\]\s*/u', '', $name );
	$n = preg_replace( '/\s*\((?:[^()]*mg[^()]*|[^()]*ml[^()]*)\)\s*$/u', '', (string) $n );
	return mb_substr( trim( (string) $n ), 0, 28 );
}

function median( array $v ): float {
	if ( ! $v ) {
		return 0.0;
	}
	sort( $v );
	$n = count( $v );
	return $n % 2 ? (float) $v[ intdiv( $n, 2 ) ] : ( (float) $v[ $n / 2 - 1 ] + (float) $v[ $n / 2 ] ) / 2;
}

function pct( int $a, int $b ): string {
	return $b > 0 ? round( 100 * $a / $b ) . '%' : '—';
}

/**
 * 전부 계산한다. 순수 함수.
 *
 * @param array $orders orders() 꼴.
 * @param array $items  items() 꼴 (주문 번호 => 줄들).
 * @param int   $now    지금 (사이트 시간 timestamp).
 * @return array<string,mixed>
 */
function analyze( array $orders, array $items, int $now ): array {
	$paid_s = paid_statuses();
	$paid   = array_values( array_filter( $orders, fn( $o ) => in_array( $o['s'], $paid_s, true ) ) );
	usort( $paid, fn( $a, $b ) => $a['ts'] <=> $b['ts'] );

	/* 회원별 주문 */
	$by = array();
	foreach ( $paid as $o ) {
		if ( $o['u'] > 0 ) {
			$by[ $o['u'] ][] = $o;
		}
	}
	$cust_n    = count( $by );
	$repeaters = 0;
	$gaps      = array();       // 연속 주문 간격(일)
	$first_gap = array();       // 첫 → 둘째
	$ltv       = array();       // 회원별 누적
	$one_first = array();       // 한 번 사고 만 손님의 첫 상품
	$rep_first = array();       // 두 번 이상 산 손님의 첫 상품
	$brand_flow = array();      // 첫 브랜드 => [다음 브랜드 => n]
	$first_brand_rep = array(); // 첫 브랜드 => [n, repeat]
	$cohort    = array();       // 첫 주문 달 => [n, r30, r60, r90]
	foreach ( $by as $uid => $os ) {
		$n = count( $os );
		$ltv[ $uid ] = array_sum( array_column( $os, 't' ) );
		$fi = $items[ $os[0]['id'] ] ?? array();
		$fname = $fi ? short( (string) $fi[0]['name'] ) : '(줄 없음)';
		$fb    = $fi ? brand( (string) $fi[0]['name'] ) : '기타';
		$m0    = gmdate( 'Y-m', $os[0]['ts'] );
		if ( ! isset( $cohort[ $m0 ] ) ) {
			$cohort[ $m0 ] = array( 'n' => 0, 'r30' => 0, 'r60' => 0, 'r90' => 0, 'eligible90' => 0 );
		}
		++$cohort[ $m0 ]['n'];
		if ( $now - $os[0]['ts'] >= 90 * DAY_IN_SECONDS ) {
			++$cohort[ $m0 ]['eligible90'];
		}
		if ( ! isset( $first_brand_rep[ $fb ] ) ) {
			$first_brand_rep[ $fb ] = array( 'n' => 0, 'rep' => 0 );
		}
		++$first_brand_rep[ $fb ]['n'];
		if ( $n >= 2 ) {
			++$repeaters;
			++$first_brand_rep[ $fb ]['rep'];
			$rep_first[ $fname ] = ( $rep_first[ $fname ] ?? 0 ) + 1;
			$g0 = ( $os[1]['ts'] - $os[0]['ts'] ) / DAY_IN_SECONDS;
			$first_gap[] = $g0;
			if ( $g0 <= 30 ) { ++$cohort[ $m0 ]['r30']; }
			if ( $g0 <= 60 ) { ++$cohort[ $m0 ]['r60']; }
			if ( $g0 <= 90 ) { ++$cohort[ $m0 ]['r90']; }
			for ( $i = 1; $i < $n; $i++ ) {
				$gaps[] = ( $os[ $i ]['ts'] - $os[ $i - 1 ]['ts'] ) / DAY_IN_SECONDS;
				$ni = $items[ $os[ $i ]['id'] ] ?? array();
				$nb = $ni ? brand( (string) $ni[0]['name'] ) : '기타';
				$brand_flow[ $fb ][ $nb ] = ( $brand_flow[ $fb ][ $nb ] ?? 0 ) + 1;
			}
		} else {
			$one_first[ $fname ] = ( $one_first[ $fname ] ?? 0 ) + 1;
		}
	}
	arsort( $one_first );
	arsort( $rep_first );
	arsort( $ltv );
	ksort( $cohort );

	/* 상위 손님 집중도 */
	$sum_ltv = array_sum( $ltv );
	$top10   = array_slice( array_values( $ltv ), 0, max( 1, (int) ceil( count( $ltv ) * 0.1 ) ) );
	$top10_share = $sum_ltv > 0 ? round( 100 * array_sum( $top10 ) / $sum_ltv ) : 0;

	/* 요일 · 시간 */
	$dow = array_fill( 0, 7, 0 );
	$hour = array_fill( 0, 24, 0 );
	foreach ( $paid as $o ) {
		++$dow[ (int) gmdate( 'w', $o['ts'] ) ];
		++$hour[ (int) gmdate( 'G', $o['ts'] ) ];
	}

	/* 지역 */
	$region = array();
	foreach ( $paid as $o ) {
		$k = '' !== $o['state'] ? $o['state'] : ( '' !== $o['city'] ? $o['city'] : '(없음)' );
		$k = preg_replace( '/특별시|광역시|특별자치|자치시|자치도|도$/u', '', $k );
		$region[ $k ] = ( $region[ $k ] ?? 0 ) + 1;
	}
	arsort( $region );

	/* 객단가 */
	$buckets = array( '~2만' => 0, '2~4만' => 0, '4~6만' => 0, '6~10만' => 0, '10~15만' => 0, '15만~' => 0 );
	$totals  = array();
	foreach ( $paid as $o ) {
		$t = $o['t'];
		$totals[] = $t;
		if ( $t < 20000 ) { ++$buckets['~2만']; } elseif ( $t < 40000 ) { ++$buckets['2~4만']; } elseif ( $t < 60000 ) { ++$buckets['4~6만']; } elseif ( $t < 100000 ) { ++$buckets['6~10만']; } elseif ( $t < 150000 ) { ++$buckets['10~15만']; } else { ++$buckets['15만~']; }
	}

	/* 상품 · 브랜드 순위 (돈이 들어온 주문만) */
	$prod = array();
	$brands = array();
	$lines_per_order = array();
	foreach ( $paid as $o ) {
		$ls = $items[ $o['id'] ] ?? array();
		$lines_per_order[] = count( $ls );
		foreach ( $ls as $l ) {
			$k = short( (string) $l['name'] );
			if ( ! isset( $prod[ $k ] ) ) {
				$prod[ $k ] = array( 'n' => 0, 'qty' => 0, 'sales' => 0.0, 'brand' => brand( (string) $l['name'] ) );
			}
			++$prod[ $k ]['n'];
			$prod[ $k ]['qty']   += $l['qty'];
			$prod[ $k ]['sales'] += $l['total'];
			$b = brand( (string) $l['name'] );
			$brands[ $b ] = ( $brands[ $b ] ?? 0 ) + $l['total'];
		}
	}
	uasort( $prod, fn( $a, $b ) => $b['sales'] <=> $a['sales'] );
	arsort( $brands );

	/* 입금 이탈 — 달별 (돈 들어옴 vs 입금전에서 끝남/취소) */
	$leak = array();
	foreach ( $orders as $o ) {
		$m = gmdate( 'Y-m', $o['ts'] );
		if ( ! isset( $leak[ $m ] ) ) {
			$leak[ $m ] = array( 'paid' => 0, 'unpaid' => 0, 'cancel' => 0 );
		}
		if ( in_array( $o['s'], $paid_s, true ) ) {
			++$leak[ $m ]['paid'];
		} elseif ( in_array( $o['s'], array( 'on-hold', 'pending' ), true ) ) {
			++$leak[ $m ]['unpaid'];
		} elseif ( in_array( $o['s'], array( 'cancelled', 'failed', 'refunded' ), true ) ) {
			++$leak[ $m ]['cancel'];
		}
	}
	ksort( $leak );

	/* 신규 vs 재구매 매출 (최근 90일) */
	$since = $now - 90 * DAY_IN_SECONDS;
	$new_sales = 0.0; $rep_sales = 0.0; $new_n = 0; $rep_n = 0;
	foreach ( $by as $uid => $os ) {
		foreach ( $os as $i => $o ) {
			if ( $o['ts'] < $since ) { continue; }
			if ( 0 === $i ) { $new_sales += $o['t']; ++$new_n; } else { $rep_sales += $o['t']; ++$rep_n; }
		}
	}

	return array(
		'orders_all'   => count( $orders ),
		'orders_paid'  => count( $paid ),
		'customers'    => $cust_n,
		'repeaters'    => $repeaters,
		'repeat_rate'  => pct( $repeaters, $cust_n ),
		'orders_per_customer' => $cust_n ? round( count( $paid ) / $cust_n, 2 ) : 0,
		'gap_median'   => round( median( $gaps ) ),
		'gap_first_median' => round( median( $first_gap ) ),
		'gap_first_p25'=> $first_gap ? round( ( function ( $v ) { sort( $v ); return $v[ (int) floor( count( $v ) * 0.25 ) ]; } )( $first_gap ) ) : 0,
		'gap_first_p75'=> $first_gap ? round( ( function ( $v ) { sort( $v ); return $v[ min( count( $v ) - 1, (int) floor( count( $v ) * 0.75 ) ) ]; } )( $first_gap ) ) : 0,
		'aov'          => $totals ? round( array_sum( $totals ) / count( $totals ) ) : 0,
		'aov_median'   => round( median( $totals ) ),
		'buckets'      => $buckets,
		'lines_avg'    => $lines_per_order ? round( array_sum( $lines_per_order ) / count( $lines_per_order ), 2 ) : 0,
		'top10_share'  => $top10_share,
		'ltv_top'      => array_slice( $ltv, 0, 10, true ),
		'ltv_median'   => round( median( array_values( $ltv ) ) ),
		'one_first'    => array_slice( $one_first, 0, 12, true ),
		'rep_first'    => array_slice( $rep_first, 0, 12, true ),
		'first_brand'  => $first_brand_rep,
		'brand_flow'   => $brand_flow,
		'cohort'       => $cohort,
		'dow'          => $dow,
		'hour'         => $hour,
		'region'       => array_slice( $region, 0, 12, true ),
		'products'     => array_slice( $prod, 0, 20, true ),
		'brands'       => $brands,
		'leak'         => $leak,
		'recent90'     => array( 'new_n' => $new_n, 'new_sales' => $new_sales, 'rep_n' => $rep_n, 'rep_sales' => $rep_sales ),
	);
}

/**
 * 붙여 넣기용 글 — 사장님이 클로드 대화에 통째로 붙이면 기획을 다시 세울 수 있는 숫자 전부.
 */
function report( array $a, string $today ): string {
	$w = fn( $n ) => number_format_i18n( (int) round( (float) $n ) );
	$L = array();
	$L[] = "액상덕후 주문 해부 ({$today})";
	$L[] = "주문 전체 {$a['orders_all']}건 · 돈 들어온 주문 {$a['orders_paid']}건 · 산 회원 {$a['customers']}명";
	$L[] = "두 번 이상 산 회원 {$a['repeaters']}명 ({$a['repeat_rate']}) · 회원당 주문 {$a['orders_per_customer']}건";
	$L[] = "재구매 간격 중앙값 {$a['gap_median']}일 · 첫→둘째 주문 중앙값 {$a['gap_first_median']}일 (25% {$a['gap_first_p25']}일 · 75% {$a['gap_first_p75']}일)";
	$L[] = "객단가 평균 " . $w( $a['aov'] ) . "원 · 중앙값 " . $w( $a['aov_median'] ) . "원 · 주문당 상품 줄 {$a['lines_avg']}개";
	$L[] = '객단가 분포: ' . implode( ' · ', array_map( fn( $k, $v ) => "{$k} {$v}", array_keys( $a['buckets'] ), $a['buckets'] ) );
	$L[] = "상위 10% 회원이 매출의 {$a['top10_share']}% · 회원당 누적 매출 중앙값 " . $w( $a['ltv_median'] ) . '원';
	$r = $a['recent90'];
	$L[] = '최근 90일: 첫 주문 ' . $r['new_n'] . '건 ' . $w( $r['new_sales'] ) . '원 · 재구매 ' . $r['rep_n'] . '건 ' . $w( $r['rep_sales'] ) . '원';
	$L[] = '';
	$L[] = '[첫 주문 달별 재구매] 달: 손님수 · 30일 안 재구매 · 60일 · 90일 (90일 지난 손님 수)';
	foreach ( $a['cohort'] as $m => $c ) {
		$L[] = "{$m}: {$c['n']} · {$c['r30']} · {$c['r60']} · {$c['r90']} ({$c['eligible90']})";
	}
	$L[] = '';
	$L[] = '[첫 브랜드별 재구매율] 브랜드: 첫 구매 손님 · 그중 재구매';
	uasort( $a['first_brand'], fn( $x, $y ) => $y['n'] <=> $x['n'] );
	foreach ( array_slice( $a['first_brand'], 0, 12, true ) as $b => $c ) {
		$L[] = "{$b}: {$c['n']} · {$c['rep']} (" . pct( $c['rep'], $c['n'] ) . ')';
	}
	$L[] = '';
	$L[] = '[브랜드 이동] 첫 브랜드 → 다음 주문 브랜드 (건수)';
	foreach ( $a['brand_flow'] as $fb => $to ) {
		arsort( $to );
		$L[] = "{$fb} → " . implode( ' · ', array_map( fn( $k, $v ) => "{$k} {$v}", array_keys( array_slice( $to, 0, 5, true ) ), array_slice( $to, 0, 5 ) ) );
	}
	$L[] = '';
	$L[] = '[한 번 사고 만 손님의 첫 상품 상위]';
	foreach ( $a['one_first'] as $k => $v ) { $L[] = "{$k}: {$v}"; }
	$L[] = '';
	$L[] = '[두 번 이상 산 손님의 첫 상품 상위]';
	foreach ( $a['rep_first'] as $k => $v ) { $L[] = "{$k}: {$v}"; }
	$L[] = '';
	$L[] = '[상품 매출 상위 20] 이름: 주문 수 · 수량 · 매출';
	foreach ( $a['products'] as $k => $p ) { $L[] = "{$k} [{$p['brand']}]: {$p['n']} · {$p['qty']} · " . $w( $p['sales'] ) . '원'; }
	$L[] = '';
	$L[] = '[브랜드 매출] ' . implode( ' · ', array_map( fn( $k, $v ) => "{$k} " . $w( $v ) . '원', array_keys( array_slice( $a['brands'], 0, 12, true ) ), array_slice( $a['brands'], 0, 12 ) ) );
	$L[] = '[요일 일~토] ' . implode( ' · ', $a['dow'] );
	$L[] = '[시간대 0~23시] ' . implode( ' · ', $a['hour'] );
	$L[] = '[지역] ' . implode( ' · ', array_map( fn( $k, $v ) => "{$k} {$v}", array_keys( $a['region'] ), $a['region'] ) );
	$L[] = '';
	$L[] = '[달별 입금 이탈] 달: 돈 들어옴 · 입금전으로 남음 · 취소';
	foreach ( $a['leak'] as $m => $c ) { $L[] = "{$m}: {$c['paid']} · {$c['unpaid']} · {$c['cancel']}"; }
	return implode( "\n", $L );
}

/* ── 화면 ────────────────────────────────────────────────────────────── */

function data( bool $fresh = false ): array {
	$key = CACHE . '_' . (string) current_time( 'Y-m-d' );
	if ( ! $fresh ) {
		$hit = get_transient( $key );
		if ( is_array( $hit ) && isset( $hit['a'] ) ) {
			return $hit;
		}
	}
	$t0 = microtime( true );
	$os = orders();
	$paid_s = paid_statuses();
	$ids = array();
	foreach ( $os as $o ) {
		if ( in_array( $o['s'], $paid_s, true ) ) {
			$ids[] = $o['id'];
		}
	}
	$it = items( $ids );
	$a  = analyze( $os, $it, (int) current_time( 'timestamp' ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
	$out = array( 'a' => $a, 'took' => round( microtime( true ) - $t0, 1 ), 'built' => (string) current_time( 'Y-m-d H:i' ) );
	set_transient( $key, $out, HOUR_IN_SECONDS );
	return $out;
}

function screen(): void {
	if ( ! may() ) {
		wp_die( '권한이 없습니다.' );
	}
	$fresh = isset( $_GET['dhr_fresh'] ) && check_admin_referer( 'dhr-anatomy-fresh' ); // phpcs:ignore WordPress.Security.NonceVerification
	echo '<div class="wrap dhr-sl">';
	if ( function_exists( '\\Duckhoo\\Redesign\\Sales\\styles' ) ) {
		\Duckhoo\Redesign\Sales\styles();
	}
	echo '<h1 class="dhr-sl-h1">주문 해부</h1>';
	try {
		$d = data( $fresh );
	} catch ( \Throwable $e ) {
		echo '<div class="notice notice-error"><p>읽다 멈췄습니다: ' . esc_html( $e->getMessage() ) . ' (' . esc_html( basename( $e->getFile() ) . ':' . $e->getLine() ) . ')</p></div></div>';
		return;
	}
	$a = $d['a'];
	$w = fn( $n ) => number_format_i18n( (int) round( (float) $n ) );
	$card = function ( string $l, string $v, string $n = '' ) {
		\Duckhoo\Redesign\Sales\card( $l, $v, $n );
	};
	echo '<p class="dhr-sl-note">돈이 들어온 주문(입금확인 이후) 전부를 뜯어 봅니다. 읽기만 합니다. 맨 아래 「클로드에게 보내기」 상자를 통째로 복사해 대화창에 붙이면 이 숫자로 기획을 다시 세웁니다.</p>';

	echo '<div class="dhr-sl-cards">';
	$card( '산 회원', $w( $a['customers'] ) . '명', '돈 들어온 주문 ' . $w( $a['orders_paid'] ) . '건' );
	$card( '두 번 이상 산 회원', $w( $a['repeaters'] ) . '명 · ' . $a['repeat_rate'], '회원당 주문 ' . $a['orders_per_customer'] . '건' );
	$card( '재구매 간격 (중앙값)', $a['gap_median'] . '일', '첫→둘째 ' . $a['gap_first_median'] . '일 · 25% ' . $a['gap_first_p25'] . '일 · 75% ' . $a['gap_first_p75'] . '일' );
	$card( '객단가', $w( $a['aov'] ) . '원', '중앙값 ' . $w( $a['aov_median'] ) . '원 · 주문당 상품 줄 ' . $a['lines_avg'] . '개' );
	$card( '상위 10% 회원의 매출 몫', $a['top10_share'] . '%', '회원당 누적 매출 중앙값 ' . $w( $a['ltv_median'] ) . '원' );
	$r = $a['recent90'];
	$card( '최근 90일 재구매 매출', $w( $r['rep_sales'] ) . '원 · ' . $r['rep_n'] . '건', '첫 주문 ' . $w( $r['new_sales'] ) . '원 · ' . $r['new_n'] . '건' );
	echo '</div>';

	$table = function ( string $title, array $head, array $rows, string $note = '' ) {
		echo '<div class="dhr-sl-sec"><h2>' . esc_html( $title ) . '</h2>';
		if ( '' !== $note ) { echo '<p class="dhr-sl-note">' . esc_html( $note ) . '</p>'; }
		if ( ! $rows ) { echo '<p class="dhr-sl-empty">자료가 없습니다.</p></div>'; return; }
		echo '<div class="dhr-sl-scroll"><table class="widefat striped"><thead><tr>';
		foreach ( $head as $h ) { echo '<th>' . esc_html( $h ) . '</th>'; }
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr>';
			foreach ( $row as $i => $c ) { echo '<td' . ( $i ? ' class="dhr-sl-num"' : '' ) . '>' . esc_html( (string) $c ) . '</td>'; }
			echo '</tr>';
		}
		echo '</tbody></table></div></div>';
	};

	$rows = array();
	foreach ( $a['cohort'] as $m => $c ) {
		$rows[] = array( $m, $c['n'], $c['r30'] . ' (' . pct( $c['r30'], $c['n'] ) . ')', $c['r60'] . ' (' . pct( $c['r60'], $c['n'] ) . ')', $c['r90'] . ' (' . pct( $c['r90'], $c['eligible90'] ) . ')' );
	}
	$table( '첫 주문 달별 재구매', array( '첫 주문 달', '손님', '30일 안 재구매', '60일 안', '90일 안 (90일 지난 손님 기준)' ), $rows, '이 달에 처음 산 손님 중 며칠 안에 다시 샀는지. 최근 달은 아직 기간이 안 지나 낮게 보입니다.' );

	uasort( $a['first_brand'], fn( $x, $y ) => $y['n'] <=> $x['n'] );
	$rows = array();
	foreach ( array_slice( $a['first_brand'], 0, 12, true ) as $b => $c ) { $rows[] = array( $b, $c['n'], $c['rep'], pct( $c['rep'], $c['n'] ) ); }
	$table( '첫 브랜드별 재구매율', array( '첫 구매 브랜드', '손님', '재구매', '비율' ), $rows, '어느 브랜드로 들어온 손님이 다시 오는지.' );

	$rows = array();
	foreach ( $a['brand_flow'] as $fb => $to ) { arsort( $to ); $rows[] = array( $fb, implode( ' · ', array_map( fn( $k, $v ) => "{$k} {$v}", array_keys( array_slice( $to, 0, 5, true ) ), array_slice( $to, 0, 5 ) ) ) ); }
	$table( '브랜드 이동', array( '첫 브랜드', '그 뒤 주문의 브랜드 (건수)' ), $rows, '같은 브랜드로 계속 사는지, 갈아타는지.' );

	$rows = array();
	foreach ( $a['rep_first'] as $k => $v ) { $rows[] = array( $k, $v, (string) ( $a['one_first'][ $k ] ?? 0 ) ); }
	$table( '첫 상품과 재구매', array( '첫 상품 (재구매 손님 기준 상위)', '재구매 손님', '한 번 사고 만 손님' ), $rows, '첫 상품이 무엇일 때 다시 오는지. 오른쪽 열이 크면 그 상품은 첫 구매로 손님을 붙잡지 못하는 것.' );

	$rows = array();
	foreach ( $a['one_first'] as $k => $v ) { $rows[] = array( $k, $v ); }
	$table( '한 번 사고 만 손님의 첫 상품', array( '상품', '손님' ), $rows );

	$rows = array();
	foreach ( $a['products'] as $k => $p ) { $rows[] = array( $k, $p['brand'], $p['n'], $p['qty'], $w( $p['sales'] ) . '원' ); }
	$table( '상품 매출 상위 20', array( '상품', '브랜드', '주문', '수량', '매출' ), $rows );

	$rows = array();
	foreach ( array_slice( $a['brands'], 0, 12, true ) as $b => $s ) { $rows[] = array( $b, $w( $s ) . '원' ); }
	$table( '브랜드 매출', array( '브랜드', '매출' ), $rows );

	$rows = array();
	foreach ( $a['buckets'] as $k => $v ) { $rows[] = array( $k, $v, pct( $v, $a['orders_paid'] ) ); }
	$table( '객단가 분포', array( '금액대', '주문', '비율' ), $rows );

	$dn = array( '일', '월', '화', '수', '목', '금', '토' );
	$rows = array();
	foreach ( $a['dow'] as $i => $v ) { $rows[] = array( $dn[ $i ], $v ); }
	$table( '요일', array( '요일', '주문' ), $rows );
	$rows = array();
	foreach ( $a['hour'] as $h => $v ) { if ( $v > 0 ) { $rows[] = array( $h . '시', $v ); } }
	$table( '주문 시간대', array( '시', '주문' ), $rows );
	$rows = array();
	foreach ( $a['region'] as $k => $v ) { $rows[] = array( $k, $v, pct( $v, $a['orders_paid'] ) ); }
	$table( '지역 (배송지)', array( '지역', '주문', '비율' ), $rows );

	$rows = array();
	foreach ( $a['leak'] as $m => $c ) { $all = $c['paid'] + $c['unpaid'] + $c['cancel']; $rows[] = array( $m, $c['paid'], $c['unpaid'], $c['cancel'], pct( $c['unpaid'] + $c['cancel'], $all ) ); }
	$table( '달별 입금 이탈', array( '달', '돈 들어옴', '입금전으로 남음', '취소', '이탈률' ), $rows, '주문했는데 돈이 안 들어온 비율. 무통장 가게에서 제일 큰 구멍입니다.' );

	echo '<div class="dhr-sl-sec"><h2>클로드에게 보내기</h2><p class="dhr-sl-note">이 상자를 누르면 전체 선택됩니다. 복사해서 클로드 대화창에 붙여 넣으세요.</p>';
	echo '<textarea readonly onclick="this.select()" style="width:100%;height:280px;font-family:monospace;font-size:12px;line-height:1.5">' . esc_textarea( report( $a, (string) current_time( 'Y-m-d' ) ) ) . '</textarea></div>';

	echo '<p class="dhr-sl-foot">읽는 데 ' . esc_html( (string) $d['took'] ) . '초 · ' . esc_html( (string) $d['built'] ) . ' 기준 · 1시간 캐시 · <a href="' . esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . SLUG . '&dhr_fresh=1' ), 'dhr-anatomy-fresh' ) ) . '">지금 다시 읽기</a>. 회원이 아닌 주문(회원 번호 0)은 재구매 · 손님 셈에서 빠집니다. 이 화면은 읽기만 합니다.</p>';
	echo '</div>';
}
