<?php
/**
 * 매출 대시보드 (관리자 전용, 읽기만 함).
 *
 * 워드커머스 기본 `분석` 은 매출을 `wc_get_is_paid_statuses()` = processing · completed
 * 로만 센다. 그런데 이 가게 주문은 입금전(on-hold) → 입금확인(payment-confirmed) →
 * 배송준비중(ready-to-ship) → 배송완료(delivered) 로 흐르고 **그 중 어느 것도 그 목록에
 * 없다.** 그래서 기본 분석 화면은 배송완료 천 건이 쌓여 있어도 매출을 0 에 가깝게 본다.
 * (후기의 「구매한 고객」 판정이 한 명도 안 잡혔던 것과 같은 원인이다.)
 *
 * 이 화면은 **이 가게의 진짜 상태로** 센다. 아무것도 바꾸지 않는다 — 주문 · 회원을
 * 읽어서 더할 뿐이다.
 *
 * 매출을 한 숫자로 합치지 않는다. 무통장입금 가게라 `입금전` 은 아직 안 들어온 돈이다:
 *
 *   확정 매출   입금확인 · 배송준비중 · 배송완료 · 완료  (돈이 들어온 주문)
 *   입금 대기   입금전                                   (아직 안 들어온 돈)
 *   뺀 것       취소 · 환불 · 실패 · 임시글
 *
 * 배송비는 아직 매출에서 떼지 않는다 (사장님: 계산이 어려워 추후). 값은 읽어 두므로
 * 나중에 화면에 붙이면 된다.
 *
 * 관리자 메뉴 → 매출.
 *
 * @package Duckhoo\Redesign
 */

namespace Duckhoo\Redesign\Sales;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SLUG  = 'duckhoo-sales';
const CACHE = 'dhr_sales_v1';

/**
 * 볼 수 있는 관리자인가.
 *
 * @return bool
 */
function allowed(): bool {
	return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
}

/**
 * 이 화면에 필요한 권한.
 *
 * @return string
 */
function cap(): string {
	return current_user_can( 'manage_woocommerce' ) ? 'manage_woocommerce' : 'manage_options';
}

/**
 * 관리자 메뉴에 붙입니다.
 *
 * @return void
 */
function menu(): void {
	if ( ! allowed() ) {
		return;
	}
	add_menu_page(
		__( '매출', 'duckhoo-redesign' ),
		__( '매출', 'duckhoo-redesign' ),
		cap(),
		SLUG,
		__NAMESPACE__ . '\\screen',
		'dashicons-chart-bar',
		56
	);
}
add_action( 'admin_menu', __NAMESPACE__ . '\\menu' );

/**
 * 돈이 들어온 주문 상태.
 *
 * 이 가게의 상태 흐름은 키플이 만든 것이라 워드커머스 기본과 겹치지 않는다.
 * `shipping` 은 지금 쓰이지 않지만 플러그인 설명에 있어 함께 둔다 — 없는 상태는
 * 그냥 안 걸린다.
 *
 * @return string[]
 */
function confirmed_statuses(): array {
	return array_values( array_unique( array_map( 'strval', (array) apply_filters(
		'duckhoo_sales_confirmed_statuses',
		array( 'payment-confirmed', 'ready-to-ship', 'shipping', 'delivered', 'completed', 'processing' )
	) ) ) );
}

/**
 * 아직 돈이 안 들어온 주문 상태 (입금 대기).
 *
 * @return string[]
 */
function pending_statuses(): array {
	return array_values( array_unique( array_map( 'strval', (array) apply_filters(
		'duckhoo_sales_pending_statuses',
		array( 'on-hold', 'pending' )
	) ) ) );
}

/**
 * 매출로 세지 않는 상태.
 *
 * @return string[]
 */
function void_statuses(): array {
	return array_values( array_unique( array_map( 'strval', (array) apply_filters(
		'duckhoo_sales_void_statuses',
		array( 'cancelled', 'refunded', 'failed', 'checkout-draft', 'trash' )
	) ) ) );
}

/**
 * 몇 날치를 읽어 둘 것인가. 재구매 판정도 이 범위 안에서 센다.
 *
 * @return int
 */
function scan_days(): int {
	return max( 60, min( 1500, (int) apply_filters( 'duckhoo_sales_scan_days', 400 ) ) );
}

/**
 * 한 번에 읽을 주문 수의 상한. 주문이 계속 쌓여도 화면이 서지 않게 한다.
 *
 * @return int
 */
function max_orders(): int {
	return max( 500, (int) apply_filters( 'duckhoo_sales_max_orders', 8000 ) );
}

/**
 * 캐시를 몇 초 쥘 것인가.
 *
 * @return int
 */
function ttl(): int {
	return max( 0, (int) apply_filters( 'duckhoo_sales_cache_ttl', 10 * MINUTE_IN_SECONDS ) );
}

/**
 * 주문 하나를 화면에 필요한 만큼만 줄인다.
 *
 * 상품 줄(get_items)은 열지 않는다 — 주문 하나당 질의가 한 번 더 나간다.
 * 적립금은 확인된 메타(`_wd_point_discount`)에서 읽고, 수수료 줄은 뒤에서
 * **한 번의 질의로 몰아** 채운다 (fee_map).
 *
 * @param mixed $o 주문.
 * @return array<string,mixed>|null
 */
function row( $o ): ?array {
	if ( ! is_object( $o ) || ! method_exists( $o, 'get_id' ) ) {
		return null;
	}

	$created = method_exists( $o, 'get_date_created' ) ? $o->get_date_created() : null;
	$points  = 0.0;
	if ( method_exists( $o, 'get_meta' ) && function_exists( '\\Duckhoo\\Redesign\\Points\\meta_keys' ) ) {
		foreach ( \Duckhoo\Redesign\Points\meta_keys() as $key ) {
			$v = $o->get_meta( (string) $key, true );
			if ( is_numeric( $v ) && abs( (float) $v ) > 0 ) {
				$points = abs( (float) $v );
				break;
			}
		}
	}

	return array(
		'id'    => (int) $o->get_id(),
		'd'     => $created ? $created->date( 'Y-m-d' ) : '',
		'ts'    => $created ? (int) $created->getTimestamp() : 0,
		's'     => (string) $o->get_status(),
		't'     => (float) $o->get_total(),
		'c'     => (int) $o->get_customer_id(),
		'p'     => $points,
		'coup'  => method_exists( $o, 'get_discount_total' ) ? (float) $o->get_discount_total() : 0.0,
		'ship'  => method_exists( $o, 'get_shipping_total' ) ? (float) $o->get_shipping_total() : 0.0,
		'fee'   => 0.0,
	);
}

/**
 * 여러 주문의 **음수 수수료 줄**을 한 번의 질의로 읽습니다.
 *
 * 「🎁 금액 자동 할인」 · 「적립금 할인」이 여기로 붙는다. 주문마다 get_items('fee')
 * 를 부르면 주문 수만큼 질의가 나가므로 주문 항목 표를 직접 본다 — 이 표는
 * HPOS 를 켜도 그대로다.
 *
 * @param int[] $ids 주문 번호.
 * @return array<int,array{fee:float,points:float}>
 */
function fee_map( array $ids ): array {
	global $wpdb;

	$out = array();
	$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
	if ( ! $ids || ! isset( $wpdb ) ) {
		return $out;
	}

	foreach ( array_chunk( $ids, 500 ) as $chunk ) {
		$in   = implode( ',', array_map( 'intval', $chunk ) );
		$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
			"SELECT oi.order_id AS oid, oi.order_item_name AS name, oim.meta_value AS amt
			   FROM {$wpdb->prefix}woocommerce_order_items oi
			   JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim
			     ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_line_total'
			  WHERE oi.order_item_type = 'fee' AND oi.order_id IN ({$in})",
			ARRAY_A
		);
		foreach ( $rows as $r ) {
			$amt = (float) ( $r['amt'] ?? 0 );
			if ( $amt >= 0 ) {
				continue;
			}
			$oid = (int) ( $r['oid'] ?? 0 );
			if ( ! isset( $out[ $oid ] ) ) {
				$out[ $oid ] = array( 'fee' => 0.0, 'points' => 0.0 );
			}
			$name = (string) ( $r['name'] ?? '' );
			if ( function_exists( '\\Duckhoo\\Redesign\\Points\\is_points_label' )
				&& \Duckhoo\Redesign\Points\is_points_label( $name ) ) {
				$out[ $oid ]['points'] += abs( $amt );
			} else {
				$out[ $oid ]['fee'] += abs( $amt );
			}
		}
	}

	return $out;
}

/**
 * 주문을 읽어 옵니다. 상태로 거를 수 있고, 날짜로 자를 수 있습니다.
 *
 * @param array<string,mixed> $args status · date_created 등.
 * @return array<int,array<string,mixed>>
 */
function fetch( array $args ): array {
	$out = array();
	if ( ! function_exists( 'wc_get_orders' ) ) {
		return $out;
	}

	$page = 1;
	$cap  = max_orders();
	do {
		$batch = wc_get_orders( array_merge( array(
			'limit'   => 200,
			'page'    => $page,
			'status'  => 'any',
			'orderby' => 'date',
			'order'   => 'ASC',
		), $args ) );
		$batch = is_array( $batch ) ? $batch : array();
		foreach ( $batch as $o ) {
			$r = row( $o );
			if ( $r ) {
				$out[] = $r;
			}
		}
		$page++;
	} while ( count( $batch ) === 200 && count( $out ) < $cap );

	$fees = fee_map( wp_list_pluck( $out, 'id' ) );
	foreach ( $out as $i => $r ) {
		$f = $fees[ $r['id' ] ] ?? null;
		if ( ! $f ) {
			continue;
		}
		$out[ $i ]['fee'] = (float) $f['fee'];
		if ( $r['p'] <= 0 && $f['points'] > 0 ) {
			$out[ $i ]['p'] = (float) $f['points'];
		}
	}

	return $out;
}

/**
 * 화면에 필요한 모든 것 — 한 번 읽고 캐시에 둡니다.
 *
 * @param bool $fresh 캐시를 무시할지.
 * @return array{rows:array,open:array,built:int}
 */
function data( bool $fresh = false ): array {
	$key = CACHE . '_' . scan_days() . '_' . current_time( 'Y-m-d' );

	if ( ! $fresh ) {
		$hit = get_transient( $key );
		if ( is_array( $hit ) && isset( $hit['rows'], $hit['open'] ) ) {
			return $hit;
		}
	}

	$today = current_time( 'Y-m-d' );
	$from  = gmdate( 'Y-m-d', strtotime( $today . ' -' . scan_days() . ' days' ) );

	$out = array(
		'rows'  => fetch( array( 'date_created' => $from . '...' . $today ) ),
		// 지금 열려 있는 주문은 날짜로 자르지 않는다 — 오래 묵은 입금전이 진짜 문제다.
		'open'  => fetch( array( 'status' => array_merge( pending_statuses(), array( 'payment-confirmed', 'ready-to-ship', 'shipping' ) ) ) ),
		'built' => (int) current_time( 'timestamp' ), // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
	);

	if ( ttl() > 0 ) {
		set_transient( $key, $out, ttl() );
	}

	return $out;
}

/**
 * 기간 · 상태로 고릅니다.
 *
 * @param array         $rows     주문 줄.
 * @param string        $from     Y-m-d, 빈 값이면 처음부터.
 * @param string        $to       Y-m-d (포함), 빈 값이면 끝까지.
 * @param string[]|null $statuses 상태, null 이면 전부.
 * @return array
 */
function pick( array $rows, string $from = '', string $to = '', ?array $statuses = null ): array {
	$out = array();
	foreach ( $rows as $r ) {
		$d = (string) $r['d'];
		if ( '' === $d ) {
			continue;
		}
		if ( '' !== $from && $d < $from ) {
			continue;
		}
		if ( '' !== $to && $d > $to ) {
			continue;
		}
		if ( null !== $statuses && ! in_array( (string) $r['s'], $statuses, true ) ) {
			continue;
		}
		$out[] = $r;
	}

	return $out;
}

/**
 * 합계.
 *
 * @param array $rows 주문 줄.
 * @return array<string,float|int>
 */
function agg( array $rows ): array {
	$t = array( 'n' => 0, 'sales' => 0.0, 'points' => 0.0, 'coupon' => 0.0, 'fee' => 0.0, 'ship' => 0.0 );
	foreach ( $rows as $r ) {
		$t['n']++;
		$t['sales']  += (float) $r['t'];
		$t['points'] += (float) $r['p'];
		$t['coupon'] += (float) $r['coup'];
		$t['fee']    += (float) $r['fee'];
		$t['ship']   += (float) $r['ship'];
	}

	return $t;
}

/**
 * 손님마다 **처음 주문한 날**. 재구매를 가르는 데 쓴다.
 *
 * 읽어 둔 범위 안에서만 센다 — 그보다 오래전에 한 번 사고 만 손님은 여기서
 * 새 손님으로 잡힌다. 화면에 그 범위를 적어 둔다.
 *
 * @param array $rows 주문 줄.
 * @return array{first:array<int,string>, count:array<int,int>}
 */
function customers( array $rows ): array {
	$first = array();
	$count = array();
	foreach ( $rows as $r ) {
		$uid = (int) $r['c'];
		if ( $uid <= 0 || in_array( (string) $r['s'], void_statuses(), true ) ) {
			continue;
		}
		$d = (string) $r['d'];
		if ( ! isset( $first[ $uid ] ) || $d < $first[ $uid ] ) {
			$first[ $uid ] = $d;
		}
		$count[ $uid ] = ( $count[ $uid ] ?? 0 ) + 1;
	}

	return array( 'first' => $first, 'count' => $count );
}

/**
 * 날짜별 신규 가입자 수.
 *
 * @param string $from Y-m-d.
 * @param string $to   Y-m-d (포함).
 * @return array<string,int>
 */
function signups( string $from, string $to ): array {
	global $wpdb;

	$out = array();
	if ( ! isset( $wpdb ) ) {
		return $out;
	}

	$rows = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		"SELECT DATE(user_registered) d, COUNT(*) c FROM {$wpdb->users}
		  WHERE user_registered >= %s AND user_registered < %s GROUP BY d ORDER BY d",
		$from . ' 00:00:00',
		gmdate( 'Y-m-d', strtotime( $to . ' +1 day' ) ) . ' 00:00:00'
	), ARRAY_A );

	foreach ( $rows as $r ) {
		$out[ (string) $r['d'] ] = (int) $r['c'];
	}

	return $out;
}

/**
 * 기간 안의 가입자 수.
 *
 * @param array<string,int> $regs 날짜 => 수.
 * @param string            $from Y-m-d.
 * @param string            $to   Y-m-d.
 * @return int
 */
function signups_between( array $regs, string $from, string $to ): int {
	$n = 0;
	foreach ( $regs as $d => $c ) {
		if ( (string) $d >= $from && (string) $d <= $to ) {
			$n += (int) $c;
		}
	}

	return $n;
}

/**
 * 원 단위로 씁니다.
 *
 * @param float $n 금액.
 * @return string
 */
function won( float $n ): string {
	return number_format( (float) round( $n ) ) . '원';
}

/**
 * 지난 기간 대비 변화율. 기준이 0 이면 빈 문자열.
 *
 * @param float $now  이번.
 * @param float $then 지난번.
 * @return string
 */
function delta( float $now, float $then ): string {
	if ( $then <= 0 ) {
		return '';
	}
	$pct = ( $now - $then ) / $then * 100;

	return sprintf( '%s%.0f%%', $pct >= 0 ? '+' : '−', abs( $pct ) );
}

/**
 * 상태 이름표 — 가게가 쓰는 그 이름으로.
 *
 * @param string $status 슬러그.
 * @return string
 */
function status_label( string $status ): string {
	if ( function_exists( 'wc_get_order_status_name' ) ) {
		$name = (string) wc_get_order_status_name( $status );
		if ( '' !== $name ) {
			return $name;
		}
	}

	return $status;
}

/**
 * 상태 목록을 이름으로 옮깁니다. **이 가게에 실제로 있는 상태만** 남깁니다 —
 * 없는 슬러그를 그대로 적으면 사장님이 모르는 낱말이 화면에 뜬다.
 *
 * @param string[] $statuses 슬러그.
 * @return string[]
 */
function names( array $statuses ): array {
	$known = function_exists( 'wc_get_order_statuses' ) ? (array) wc_get_order_statuses() : array();
	$out   = array();
	foreach ( $statuses as $s ) {
		if ( $known && ! isset( $known[ 'wc-' . $s ] ) ) {
			continue;
		}
		$out[] = status_label( (string) $s );
	}

	return $out ? $out : array_map( __NAMESPACE__ . '\\status_label', $statuses );
}

/**
 * 지난달의 같은 기간 — 달 끝을 넘지 않게.
 *
 * `-1 month` 를 그냥 쓰면 3월 31일이 3월 3일이 된다.
 *
 * @param string $today Y-m-d.
 * @return array{0:string,1:string} 지난달 1일, 지난달 같은 날.
 */
function last_month_same( string $today ): array {
	$first = gmdate( 'Y-m-01', strtotime( $today ) );
	$lm    = gmdate( 'Y-m-01', strtotime( $first . ' -1 day' ) );
	$day   = min( (int) gmdate( 'j', strtotime( $today ) ), (int) gmdate( 't', strtotime( $lm ) ) );

	return array( $lm, gmdate( 'Y-m-', strtotime( $lm ) ) . str_pad( (string) $day, 2, '0', STR_PAD_LEFT ) );
}

/**
 * 입금전이 며칠 넘으면 손볼 때인가.
 *
 * @return int
 */
function stale_days(): int {
	return max( 1, (int) apply_filters( 'duckhoo_sales_stale_days', 5 ) );
}

/**
 * 카드 한 장.
 *
 * @param string $label 이름.
 * @param string $value 큰 숫자.
 * @param string $note  아래 캡션.
 * @param string $tone  '' | 'warm' | 'wait'.
 * @return void
 */
function card( string $label, string $value, string $note = '', string $tone = '' ): void {
	printf(
		'<div class="dhr-sl-card%1$s"><div class="dhr-sl-card__l">%2$s</div><div class="dhr-sl-card__v">%3$s</div>%4$s</div>',
		$tone ? ' is-' . esc_attr( $tone ) : '',
		esc_html( $label ),
		esc_html( $value ),
		'' !== $note ? '<div class="dhr-sl-card__n">' . esc_html( $note ) . '</div>' : ''
	);
}

/**
 * 화면.
 *
 * @return void
 */
function screen(): void {
	if ( ! allowed() ) {
		wp_die( esc_html__( '권한이 없습니다.', 'duckhoo-redesign' ) );
	}

	echo '<div class="wrap dhr-sl">';
	styles();
	echo '<h1 class="dhr-sl-h1">매출</h1>';

	if ( ! function_exists( 'wc_get_orders' ) ) {
		echo '<div class="notice notice-error"><p>워드커머스가 꺼져 있어 주문을 읽을 수 없습니다.</p></div></div>';
		return;
	}

	$fresh = isset( $_GET['dhr_fresh'] ) && check_admin_referer( 'dhr-sales-fresh' ); // phpcs:ignore WordPress.Security.NonceVerification
	$data  = data( $fresh );
	$rows  = (array) $data['rows'];
	$open  = (array) $data['open'];

	$today   = current_time( 'Y-m-d' );
	$yest    = gmdate( 'Y-m-d', strtotime( $today . ' -1 day' ) );
	$mfirst  = gmdate( 'Y-m-01', strtotime( $today ) );
	$window  = gmdate( 'Y-m-d', strtotime( $today . ' -' . scan_days() . ' days' ) );
	list( $lm_from, $lm_to ) = last_month_same( $today );

	$paid = confirmed_statuses();
	$wait = pending_statuses();

	$t_today = agg( pick( $rows, $today, $today, $paid ) );
	$t_yest  = agg( pick( $rows, $yest, $yest, $paid ) );
	$t_month = agg( pick( $rows, $mfirst, $today, $paid ) );
	$t_lm    = agg( pick( $rows, $lm_from, $lm_to, $paid ) );

	// 지금 열려 있는 주문 — 날짜와 상관없이 「지금」 이다.
	$now      = (int) current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
	$wait_sum = 0.0;
	$wait_n   = 0;
	$stale    = 0;
	$stale_v  = 0.0;
	$ready    = 0;
	$by_state = array();
	foreach ( $open as $r ) {
		$s = (string) $r['s'];
		if ( ! isset( $by_state[ $s ] ) ) {
			$by_state[ $s ] = array( 'n' => 0, 'v' => 0.0, 'old' => '' );
		}
		$by_state[ $s ]['n']++;
		$by_state[ $s ]['v'] += (float) $r['t'];
		if ( '' === $by_state[ $s ]['old'] || (string) $r['d'] < $by_state[ $s ]['old'] ) {
			$by_state[ $s ]['old'] = (string) $r['d'];
		}
		if ( in_array( $s, $wait, true ) ) {
			$wait_n++;
			$wait_sum += (float) $r['t'];
			if ( $r['ts'] > 0 && ( $now - (int) $r['ts'] ) > stale_days() * DAY_IN_SECONDS ) {
				$stale++;
				$stale_v += (float) $r['t'];
			}
		} else {
			$ready++;
		}
	}

	// ── 카드 ────────────────────────────────────────────────
	echo '<div class="dhr-sl-cards">';
	card( '오늘 확정 매출', won( $t_today['sales'] ),
		sprintf( '주문 %d건 · 어제 %s', $t_today['n'], won( $t_yest['sales'] ) ), 'warm' );
	$d = delta( $t_month['sales'], $t_lm['sales'] );
	card( '이번 달 확정 매출', won( $t_month['sales'] ),
		sprintf( '%s ~ 오늘 · 주문 %d건%s', substr( $mfirst, 5 ), $t_month['n'],
			'' !== $d ? ' · 지난달 같은 기간 ' . $d : '' ), 'warm' );
	card( '지금 입금 대기', won( $wait_sum ),
		sprintf( '%d건%s', $wait_n, $stale ? sprintf( ' · %d일 넘은 것 %d건', stale_days(), $stale ) : '' ), 'wait' );
	card( '보낼 준비', (string) $ready . '건',
		'입금확인 · 배송준비중 — 아직 안 보낸 주문' );
	echo '</div>';

	if ( $stale > 0 ) {
		printf(
			'<div class="dhr-sl-warn"><b>입금전으로 %1$d일 넘게 있는 주문이 %2$d건 (%3$s)</b> 있습니다. 입금이 안 됐거나, 입금자명이 달라 자동확인이 못 걸린 주문입니다. <a href="%4$s">주문 목록에서 보기</a></div>',
			(int) stale_days(),
			(int) $stale,
			esc_html( won( $stale_v ) ),
			esc_url( admin_url( 'admin.php?page=wc-orders&status=wc-on-hold' ) )
		);
	}

	// ── 최근 30일 ───────────────────────────────────────────
	$days = array();
	$max  = 0.0;
	for ( $i = 29; $i >= 0; $i-- ) {
		$d           = gmdate( 'Y-m-d', strtotime( $today . ' -' . $i . ' days' ) );
		$a           = agg( pick( $rows, $d, $d, $paid ) );
		$days[ $d ]  = $a;
		$max         = max( $max, (float) $a['sales'] );
	}

	$sum30 = 0.0;
	foreach ( $days as $a ) {
		$sum30 += (float) $a['sales'];
	}
	echo '<section class="dhr-sl-sec"><h2>최근 30일 확정 매출</h2>';
	printf( '<p class="dhr-sl-note">합계 %s · 하루 평균 %s · 가장 많은 날 %s</p>',
		esc_html( won( $sum30 ) ), esc_html( won( $sum30 / 30 ) ), esc_html( won( $max ) ) );
	printf( '<div class="dhr-sl-chart" role="img" aria-label="%s">',
		esc_attr( sprintf( '최근 30일 하루 매출. 가장 많은 날 %s.', won( $max ) ) ) );
	foreach ( $days as $d => $a ) {
		$h = $max > 0 ? max( 2, round( (float) $a['sales'] / $max * 100 ) ) : 2;
		printf(
			'<div class="dhr-sl-bar"><i style="height:%1$s%%"></i><span>%2$s</span><b>%3$s</b></div>',
			esc_attr( (string) $h ),
			esc_html( substr( $d, 8 ) ),
			esc_html( $d . ' · ' . won( $a['sales'] ) . ' · ' . $a['n'] . '건' )
		);
	}
	echo '</div>';
	echo '<details class="dhr-sl-tab"><summary>표로 보기</summary><table class="widefat striped"><thead><tr><th>날짜</th><th>확정 매출</th><th>주문</th></tr></thead><tbody>';
	foreach ( array_reverse( $days, true ) as $d => $a ) {
		printf( '<tr><td>%s</td><td>%s</td><td>%d건</td></tr>',
			esc_html( $d ), esc_html( won( $a['sales'] ) ), (int) $a['n'] );
	}
	echo '</tbody></table></details></section>';

	// ── 주문 상태별 ─────────────────────────────────────────
	echo '<section class="dhr-sl-sec"><h2>지금 묶여 있는 돈</h2>';
	echo '<p class="dhr-sl-note">아직 끝나지 않은 주문입니다. 날짜와 상관없이 지금 그 상태인 것을 전부 셉니다.</p>';
	if ( ! $by_state ) {
		echo '<p class="dhr-sl-empty">열려 있는 주문이 없습니다.</p>';
	} else {
		uasort( $by_state, static fn( $a, $b ) => $b['v'] <=> $a['v'] );
		echo '<div class="dhr-sl-scroll"><table class="widefat striped"><thead><tr><th>상태</th><th>건수</th><th>금액</th><th>오래된 주문</th></tr></thead><tbody>';
		foreach ( $by_state as $s => $v ) {
			printf(
				'<tr><td><a href="%1$s">%2$s</a></td><td class="dhr-sl-num">%3$d건</td><td class="dhr-sl-num">%4$s</td><td class="dhr-sl-num dhr-sl-mut">%5$s</td></tr>',
				esc_url( admin_url( 'admin.php?page=wc-orders&status=wc-' . $s ) ),
				esc_html( status_label( $s ) ),
				(int) $v['n'],
				esc_html( won( (float) $v['v'] ) ),
				esc_html( '' !== $v['old'] ? $v['old'] : '—' )
			);
		}
		echo '</tbody></table></div>';
	}
	echo '</section>';

	// ── 나가는 돈 ───────────────────────────────────────────
	$m_rows = pick( $rows, $mfirst, $today, $paid );
	$m      = agg( $m_rows );
	$out    = (float) $m['points'] + (float) $m['coupon'] + (float) $m['fee'];

	echo '<section class="dhr-sl-sec"><h2>이번 달 나가는 돈</h2>';
	echo '<table class="widefat striped dhr-sl-kv"><tbody>';
	foreach ( array(
		'적립금 사용'     => $m['points'],
		'쿠폰 할인'       => $m['coupon'],
		'자동 할인 · 기타' => $m['fee'],
	) as $label => $v ) {
		printf( '<tr><td>%s</td><td class="dhr-sl-num">%s</td><td class="dhr-sl-num dhr-sl-mut">%s</td></tr>',
			esc_html( $label ),
			esc_html( won( (float) $v ) ),
			esc_html( $m['sales'] > 0 ? sprintf( '매출의 %.1f%%', (float) $v / (float) $m['sales'] * 100 ) : '—' ) );
	}
	printf( '<tr><td><b>합계</b></td><td class="dhr-sl-num"><b>%s</b></td><td class="dhr-sl-num dhr-sl-mut">%s</td></tr>',
		esc_html( won( $out ) ),
		esc_html( $m['sales'] > 0 ? sprintf( '매출의 %.1f%%', $out / (float) $m['sales'] * 100 ) : '—' ) );
	echo '</tbody></table>';
	echo '<p class="dhr-sl-note">배송비는 아직 여기 넣지 않았습니다 (참고: 이번 달 배송비로 받은 돈 ' . esc_html( won( (float) $m['ship'] ) ) . ').</p>';
	echo '</section>';

	// ── 회원 ────────────────────────────────────────────────
	$cust    = customers( $rows );
	$regs    = signups( $window, $today );
	$new_m   = signups_between( $regs, $mfirst, $today );
	$new_t   = signups_between( $regs, $today, $today );
	$buyers  = array();
	$fresh_v = 0.0;
	$back_v  = 0.0;
	$fresh_n = 0;
	$back_n  = 0;
	foreach ( $m_rows as $r ) {
		$uid = (int) $r['c'];
		if ( $uid <= 0 ) {
			continue;
		}
		$buyers[ $uid ] = true;
		if ( ( $cust['first'][ $uid ] ?? '' ) === (string) $r['d'] ) {
			$fresh_n++;
			$fresh_v += (float) $r['t'];
		} else {
			$back_n++;
			$back_v += (float) $r['t'];
		}
	}
	$repeat = 0;
	foreach ( array_keys( $buyers ) as $uid ) {
		if ( ( $cust['count'][ $uid ] ?? 0 ) >= 2 ) {
			$repeat++;
		}
	}
	$n_buyers = count( $buyers );

	echo '<section class="dhr-sl-sec"><h2>이번 달 회원</h2>';
	echo '<table class="widefat striped dhr-sl-kv"><tbody>';
	printf( '<tr><td>신규 가입</td><td class="dhr-sl-num">%d명</td><td class="dhr-sl-mut">오늘 %d명</td></tr>', (int) $new_m, (int) $new_t );
	printf( '<tr><td>주문한 손님</td><td class="dhr-sl-num">%d명</td><td class="dhr-sl-mut">%s</td></tr>',
		(int) $n_buyers,
		esc_html( $n_buyers ? '손님당 평균 ' . won( (float) $m['sales'] / $n_buyers ) : '—' ) );
	printf( '<tr><td>그중 두 번 이상 산 손님</td><td class="dhr-sl-num">%d명</td><td class="dhr-sl-mut">%s</td></tr>',
		(int) $repeat,
		esc_html( $n_buyers ? sprintf( '%.0f%%', $repeat / $n_buyers * 100 ) : '—' ) );
	printf( '<tr><td>첫 주문 매출</td><td class="dhr-sl-num">%s</td><td class="dhr-sl-mut">%d건</td></tr>',
		esc_html( won( $fresh_v ) ), (int) $fresh_n );
	printf( '<tr><td>다시 온 손님 매출</td><td class="dhr-sl-num">%s</td><td class="dhr-sl-mut">%d건%s</td></tr>',
		esc_html( won( $back_v ) ), (int) $back_n,
		esc_html( $m['sales'] > 0 ? sprintf( ' · 매출의 %.0f%%', $back_v / (float) $m['sales'] * 100 ) : '' ) );
	echo '</tbody></table></section>';

	// ── 각주 ────────────────────────────────────────────────
	$capped = count( $rows ) >= max_orders();
	echo '<section class="dhr-sl-foot">';
	printf( '<p><b>확정 매출</b>은 %s 상태의 주문 합계입니다. <b>입금 대기</b>는 %s. 취소 · 환불 · 실패는 어디에도 세지 않습니다.</p>',
		esc_html( implode( ' · ', names( $paid ) ) ),
		esc_html( implode( ' · ', names( $wait ) ) ) );
	printf( '<p>주문 %d건을 읽었습니다 (%s ~ %s). 재구매는 이 범위 안에서 셉니다 — 그 전에 한 번 사고 만 손님은 새 손님으로 잡힙니다.%s</p>',
		count( $rows ), esc_html( $window ), esc_html( $today ),
		$capped ? ' <b>상한(' . (int) max_orders() . '건)에 걸렸습니다 — 기간을 줄여 보세요.</b>' : '' );
	printf( '<p>%s 기준 · <a href="%s">지금 다시 읽기</a></p>',
		esc_html( wp_date( 'Y-m-d H:i', (int) $data['built'] ) ),
		esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . SLUG . '&dhr_fresh=1' ), 'dhr-sales-fresh' ) ) );
	echo '<p class="dhr-sl-mut">이 화면은 주문을 읽기만 합니다. 워드커머스가 기본으로 주는 분석 화면은 <code>processing</code> · <code>completed</code> 두 상태만 매출로 세기 때문에, 이 가게의 주문을 거의 놓칩니다.</p>';
	echo '</section>';

	echo '</div>';
}

/**
 * 화면 스타일.
 *
 * 이 화면 안(`.dhr-sl`)에서만 씁니다. 반투명 · 블러는 쓰지 않고, 폭이 좁은 쪽을
 * 먼저 그린 뒤 min-width 로 넓힙니다 — 사장님이 폰으로도 여시기 때문입니다.
 *
 * @return void
 */
function styles(): void {
	?>
<style>
.dhr-sl{ --ink:#111; --mut:#4E565F; --line:#E1E5E8; --warm:#C2410C; --wait:#B45309; }
.dhr-sl .dhr-sl-h1{ font-size:24px; font-weight:800; margin:8px 0 14px; color:var(--ink); }
.dhr-sl h2{ font-size:16px; font-weight:700; margin:0 0 8px; color:var(--ink); }
.dhr-sl-cards{ display:grid; grid-template-columns:1fr; gap:10px; margin:0 0 14px; }
.dhr-sl-card{ background:#fff; border:1px solid var(--line); border-radius:12px; padding:14px 16px; }
.dhr-sl-card__l{ font-size:13px; color:var(--mut); font-weight:600; }
.dhr-sl-card__v{ font-size:26px; font-weight:800; line-height:1.25; margin:4px 0 0; color:var(--ink);
	font-variant-numeric:tabular-nums; word-break:keep-all; }
.dhr-sl-card__n{ font-size:13px; color:var(--mut); margin:4px 0 0; word-break:keep-all; }
.dhr-sl-card.is-warm .dhr-sl-card__v{ color:var(--warm); }
.dhr-sl-card.is-wait .dhr-sl-card__v{ color:var(--wait); }
.dhr-sl-warn{ background:#fff; border:1px solid var(--wait); border-left:5px solid var(--wait);
	border-radius:10px; padding:12px 14px; margin:0 0 14px; font-size:14px; color:var(--ink); word-break:keep-all; }
.dhr-sl-sec{ background:#fff; border:1px solid var(--line); border-radius:12px; padding:14px 16px; margin:0 0 14px; }
.dhr-sl-sec table{ border:0; }
.dhr-sl-note{ font-size:13px; color:var(--mut); margin:0 0 10px; word-break:keep-all; }
.dhr-sl-empty{ font-size:14px; color:var(--mut); margin:0; }
.dhr-sl-sec th, .dhr-sl-sec td{ word-break:keep-all; }
.dhr-sl-num{ font-variant-numeric:tabular-nums; font-weight:600; white-space:nowrap; }
.dhr-sl-sec td+td, .dhr-sl-sec th+th{ white-space:nowrap; }
.dhr-sl-scroll{ overflow-x:auto; }
.dhr-sl-kv td:not(:last-child){ width:1%; }
.dhr-sl-kv td:first-child{ min-width:120px; }
.dhr-sl-mut{ color:var(--mut); font-weight:400; }
.dhr-sl-chart{ display:flex; align-items:flex-end; gap:3px; height:170px; padding:0 0 24px; margin:4px 0 10px; }
.dhr-sl-bar{ position:relative; flex:1 1 0; min-width:0; height:100%; display:flex; align-items:flex-end; }
.dhr-sl-bar i{ display:block; width:100%; background:var(--warm); border-radius:3px 3px 0 0; }
.dhr-sl-bar span{ display:none; position:absolute; left:0; right:0; bottom:-22px; text-align:center;
	font-size:13px; color:var(--mut); }
.dhr-sl-bar:nth-child(5n+1) span{ display:block; }
.dhr-sl-bar b{ display:none; position:absolute; bottom:calc(100% + 6px); left:50%; transform:translateX(-50%);
	background:var(--ink); color:#fff; font-size:13px; font-weight:600; white-space:nowrap;
	padding:5px 9px; border-radius:7px; z-index:2; }
.dhr-sl-bar:first-child b{ left:0; transform:none; }
.dhr-sl-bar:last-child b{ left:auto; right:0; transform:none; }
.dhr-sl-bar:hover b{ display:block; }
.dhr-sl-bar:hover i{ background:var(--ink); }
.dhr-sl-tab summary{ cursor:pointer; font-size:14px; color:var(--mut); }
.dhr-sl-tab table{ margin-top:10px; }
.dhr-sl-foot{ font-size:13px; color:var(--mut); line-height:1.7; }
.dhr-sl-foot p{ margin:0 0 6px; word-break:keep-all; }
@media (min-width:600px){ .dhr-sl-cards{ grid-template-columns:1fr 1fr; } }
@media (min-width:1100px){ .dhr-sl-cards{ grid-template-columns:repeat(4,1fr); } }
</style>
	<?php
}
