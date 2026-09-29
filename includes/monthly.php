<?php
/**
 * 매출 → 월말 결산 (읽기 전용).
 *
 * 한 달을 닫는 한 장 — 접수 · 확정(실입금) · 입금 대기 · 취소를 나누고, 할인 전 금액에서
 * 적립금 · 쿠폰 · 자동 할인을 빼 확정 매출까지 내려가는 뺄셈 장부, 첫 주문 손님과 재구매 손님,
 * 상품 · 브랜드 순위, 일별 표, 지난달 대비. 회계용 CSV(주문 한 줄에 금액 칸 전부)와
 * 붙여 넣기용 글을 뽑고, 디스코드로도 보낸다.
 *
 * 주문 · 회원에 아무것도 쓰지 않는다. 주문은 매출 화면과 같은 `Sales\fetch()`(wc_get_orders +
 * 수수료 줄 한 질의)로 그 달치만 읽고, 상품 줄은 `Anatomy\items()`, 「이 손님이 이 달 전에도
 * 샀는가」는 질의 하나로 본다. 닫힌 달은 하루, 진행 중인 달은 30분 캐시.
 *
 * @package Duckhoo\Redesign
 */

namespace Duckhoo\Redesign\Monthly;

defined( 'ABSPATH' ) || exit;

// 크론(매달 1일)은 관리자 밖에서 도는데 주문 읽기는 매출 화면 · 주문 해부의 함수를 쓴다 — 없으면 여기서 든다.
if ( ! function_exists( '\\Duckhoo\\Redesign\\Sales\\fetch' ) ) {
	require_once __DIR__ . '/sales.php';
}
if ( ! function_exists( '\\Duckhoo\\Redesign\\Anatomy\\items' ) ) {
	require_once __DIR__ . '/anatomy.php';
}

const SLUG  = 'duckhoo-monthly';
const CACHE = 'dhr_monthly';
const CRON  = 'duckhoo_monthly_check'; // 매일 사이트 시간 SEND_AT 에 깨어나 1일이면 지난달 결산을 보낸다
const SEND_AT = '09:00';

function may(): bool {
	return function_exists( '\\Duckhoo\\Redesign\\Sales\\allowed' ) ? \Duckhoo\Redesign\Sales\allowed() : current_user_can( 'manage_options' );
}

function menu(): void {
	if ( ! may() ) {
		return;
	}
	add_submenu_page( 'duckhoo-sales', '월말 결산', '월말 결산', current_user_can( 'manage_woocommerce' ) ? 'manage_woocommerce' : 'manage_options', SLUG, __NAMESPACE__ . '\\screen' );
}
add_action( 'admin_menu', __NAMESPACE__ . '\\menu', 12 );
add_action( 'admin_post_dhr_monthly_csv', __NAMESPACE__ . '\\export' );
add_action( 'admin_post_dhr_monthly_discord', __NAMESPACE__ . '\\to_discord' );
add_action( CRON, __NAMESPACE__ . '\\cron_send' );
add_action( 'admin_init', __NAMESPACE__ . '\\schedule' );

/* ── 달 ─────────────────────────────────────────────────────────────── */

/**
 * `YYYY-MM` 이 맞는 꼴인지.
 */
function valid_ym( string $ym ): bool {
	return (bool) preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', $ym );
}

/**
 * 달의 첫날 · 끝날 (Y-m-d).
 *
 * @return array{0:string,1:string}
 */
function bounds( string $ym ): array {
	$first = $ym . '-01';
	return array( $first, gmdate( 'Y-m-t', strtotime( $first ) ) );
}

/**
 * 기본으로 여는 달. 매달 1~3일에는 **지난달**(닫는 중인 달), 그 뒤엔 이번 달.
 */
function default_ym( string $today ): string {
	$ts = strtotime( $today );
	if ( (int) gmdate( 'j', $ts ) <= 3 ) {
		return gmdate( 'Y-m', strtotime( gmdate( 'Y-m-01', $ts ) . ' -1 day' ) );
	}
	return gmdate( 'Y-m', $ts );
}

/**
 * 한 달 앞.
 */
function prev_ym( string $ym ): string {
	return gmdate( 'Y-m', strtotime( $ym . '-01 -1 day' ) );
}

/**
 * 「2026년 9월」.
 */
function kmonth( string $ym ): string {
	return sprintf( '%d년 %d월', (int) substr( $ym, 0, 4 ), (int) substr( $ym, 5, 2 ) );
}

/* ── 상태 ───────────────────────────────────────────────────────────── */

function confirmed(): array {
	return function_exists( '\\Duckhoo\\Redesign\\Sales\\confirmed_statuses' ) ? \Duckhoo\Redesign\Sales\confirmed_statuses() : array( 'payment-confirmed', 'ready-to-ship', 'shipping', 'delivered', 'completed', 'processing' );
}
function pending(): array {
	return function_exists( '\\Duckhoo\\Redesign\\Sales\\pending_statuses' ) ? \Duckhoo\Redesign\Sales\pending_statuses() : array( 'on-hold', 'pending' );
}
function void_s(): array {
	return function_exists( '\\Duckhoo\\Redesign\\Sales\\void_statuses' ) ? \Duckhoo\Redesign\Sales\void_statuses() : array( 'cancelled', 'refunded', 'failed' );
}
function label( string $s ): string {
	return function_exists( '\\Duckhoo\\Redesign\\Sales\\status_label' ) ? \Duckhoo\Redesign\Sales\status_label( $s ) : $s;
}
function won( float $n ): string {
	return number_format( (float) round( $n ) ) . '원';
}

/* ── 읽기 ───────────────────────────────────────────────────────────── */

/**
 * 이 회원들 중 `$before_local`(Y-m-d 00:00, 사이트 시간) 전에 **돈 들어온 주문**이 있는 회원.
 *
 * @param int[]  $uids         회원 번호.
 * @param string $before_local 달 첫날.
 * @return array<int,true>
 */
function prior_customers( array $uids, string $before_local ): array {
	global $wpdb;
	$uids = array_values( array_unique( array_filter( array_map( 'intval', $uids ) ) ) );
	if ( ! $uids || ! isset( $wpdb ) ) {
		return array();
	}
	$gmt  = function_exists( 'get_gmt_from_date' ) ? get_gmt_from_date( $before_local . ' 00:00:00' ) : $before_local . ' 00:00:00';
	$st   = "'" . implode( "','", array_map( fn( $s ) => 'wc-' . esc_sql( $s ), confirmed() ) ) . "'";
	$out  = array();
	$hpos = function_exists( '\\Duckhoo\\Redesign\\Anatomy\\hpos' ) && \Duckhoo\Redesign\Anatomy\hpos();
	foreach ( array_chunk( $uids, 500 ) as $chunk ) {
		$in = implode( ',', $chunk );
		if ( $hpos ) {
			$rows = (array) $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB
				"SELECT DISTINCT customer_id FROM {$wpdb->prefix}wc_orders
				  WHERE type = 'shop_order' AND customer_id IN ({$in}) AND status IN ({$st}) AND date_created_gmt < %s",
				$gmt
			) );
		} else {
			$rows = (array) $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB
				"SELECT DISTINCT m.meta_value FROM {$wpdb->posts} p
				   JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_customer_user'
				  WHERE p.post_type = 'shop_order' AND p.post_status IN ({$st}) AND m.meta_value IN ({$in}) AND p.post_date_gmt < %s",
				$gmt
			) );
		}
		foreach ( $rows as $u ) {
			$out[ (int) $u ] = true;
		}
	}
	return $out;
}

/**
 * 한 달치 전부 — 캐시에 둔다.
 *
 * @return array{c:array,built:string,took:float,ym:string}
 */
function data( string $ym, bool $fresh = false ): array {
	$today  = (string) current_time( 'Y-m-d' );
	$closed = $ym < substr( $today, 0, 7 );
	$key    = CACHE . '_' . $ym . ( $closed ? '' : '_' . $today );
	if ( ! $fresh ) {
		$hit = get_transient( $key );
		if ( is_array( $hit ) && isset( $hit['c'] ) ) {
			return $hit;
		}
	}
	$t0 = microtime( true );
	list( $from, $to ) = bounds( $ym );
	list( $pfrom, $pto ) = bounds( prev_ym( $ym ) );
	$rows  = \Duckhoo\Redesign\Sales\fetch( array( 'date_created' => $from . '...' . $to ) );
	$prev  = \Duckhoo\Redesign\Sales\fetch( array( 'date_created' => $pfrom . '...' . $pto ) );
	$ids   = array();
	$uids  = array();
	$conf  = confirmed();
	foreach ( $rows as $r ) {
		if ( in_array( (string) $r['s'], $conf, true ) ) {
			$ids[] = (int) $r['id'];
			if ( $r['c'] > 0 ) {
				$uids[] = (int) $r['c'];
			}
		}
	}
	$items = function_exists( '\\Duckhoo\\Redesign\\Anatomy\\items' ) ? \Duckhoo\Redesign\Anatomy\items( $ids ) : array();
	$prior = prior_customers( $uids, $from );
	$regs  = function_exists( '\\Duckhoo\\Redesign\\Sales\\signups' ) ? \Duckhoo\Redesign\Sales\signups( $pfrom, $to ) : array();
	$sn    = function_exists( '\\Duckhoo\\Redesign\\Sales\\signups_between' ) ? \Duckhoo\Redesign\Sales\signups_between( $regs, $from, $to ) : 0;
	$sp    = function_exists( '\\Duckhoo\\Redesign\\Sales\\signups_between' ) ? \Duckhoo\Redesign\Sales\signups_between( $regs, $pfrom, $pto ) : 0;
	$c     = close( $rows, $items, $prior, $prev, $ym, $sn, $sp, $today );
	$out   = array( 'c' => $c, 'ym' => $ym, 'built' => (string) current_time( 'Y-m-d H:i' ), 'took' => round( microtime( true ) - $t0, 1 ), 'rows' => $rows, 'items' => $items );
	set_transient( $key, $out, $closed ? DAY_IN_SECONDS : 30 * MINUTE_IN_SECONDS );
	return $out;
}

/* ── 순수 계산 ──────────────────────────────────────────────────────── */

/**
 * 한 줄에 대해 할인 전 금액. 실입금 + 적립금 + 쿠폰 + 자동 할인.
 */
function before( array $r ): float {
	return (float) $r['t'] + (float) $r['p'] + (float) $r['coup'] + (float) $r['fee'];
}

/**
 * 한 달을 닫는다. 순수 함수.
 *
 * @param array  $rows   Sales\fetch 꼴 (id d ts s t c p coup ship fee).
 * @param array  $items  주문 번호 => 줄들 (Anatomy\items 꼴).
 * @param array  $prior  이 달 전에도 산 회원 (번호 => true).
 * @param array  $prev   지난달 줄.
 * @param string $ym     달.
 * @param int    $signups 이 달 가입.
 * @param int    $signups_prev 지난달 가입.
 * @param string $today  오늘 (Y-m-d).
 * @return array<string,mixed>
 */
function close( array $rows, array $items, array $prior, array $prev, string $ym, int $signups, int $signups_prev, string $today ): array {
	$conf_s = confirmed();
	$pend_s = pending();
	$void_st = void_s();
	$skip   = array( 'checkout-draft', 'auto-draft', 'trash' );
	$rows   = array_values( array_filter( $rows, fn( $r ) => ! in_array( (string) $r['s'], $skip, true ) ) );
	$prev   = array_values( array_filter( $prev, fn( $r ) => ! in_array( (string) $r['s'], $skip, true ) ) );
	usort( $rows, fn( $a, $b ) => ( $a['ts'] <=> $b['ts'] ) ?: ( $a['id'] <=> $b['id'] ) );

	$sum = function ( array $rs ): array {
		$t = array( 'n' => 0, 'sales' => 0.0, 'points' => 0.0, 'coupon' => 0.0, 'fee' => 0.0, 'ship' => 0.0, 'before' => 0.0 );
		foreach ( $rs as $r ) {
			$t['n']++;
			$t['sales']  += (float) $r['t'];
			$t['points'] += (float) $r['p'];
			$t['coupon'] += (float) $r['coup'];
			$t['fee']    += (float) $r['fee'];
			$t['ship']   += (float) $r['ship'];
			$t['before'] += before( $r );
		}
		$t['goods'] = $t['before'] - $t['ship'];
		return $t;
	};
	$conf = array_values( array_filter( $rows, fn( $r ) => in_array( (string) $r['s'], $conf_s, true ) ) );
	$pend = array_values( array_filter( $rows, fn( $r ) => in_array( (string) $r['s'], $pend_s, true ) ) );
	$void = array_values( array_filter( $rows, fn( $r ) => in_array( (string) $r['s'], $void_st, true ) ) );
	$all  = $sum( $rows );
	$c    = $sum( $conf );
	$p    = $sum( $pend );
	$v    = $sum( $void );

	/* 객단가 */
	$tot = array_map( fn( $r ) => (float) $r['t'], $conf );
	$aov = $tot ? round( array_sum( $tot ) / count( $tot ) ) : 0;
	$med = function_exists( '\\Duckhoo\\Redesign\\Anatomy\\median' ) ? round( \Duckhoo\Redesign\Anatomy\median( $tot ) ) : 0;

	/* 손님 — 첫 주문 · 재구매 · 비회원 */
	$seen = array();
	$cust = array( 'first_n' => 0, 'first_sales' => 0.0, 'rep_n' => 0, 'rep_sales' => 0.0, 'guest_n' => 0, 'guest_sales' => 0.0, 'buyers' => 0, 'first_buyers' => 0, 'rep_buyers' => 0 );
	foreach ( $conf as $r ) {
		$u = (int) $r['c'];
		if ( $u <= 0 ) {
			$cust['guest_n']++;
			$cust['guest_sales'] += (float) $r['t'];
			continue;
		}
		if ( ! isset( $seen[ $u ] ) ) {
			$seen[ $u ] = true;
			$cust['buyers']++;
			if ( isset( $prior[ $u ] ) ) {
				$cust['rep_buyers']++;
			} else {
				$cust['first_buyers']++;
				$cust['first_n']++;
				$cust['first_sales'] += (float) $r['t'];
				continue;
			}
		}
		$cust['rep_n']++;
		$cust['rep_sales'] += (float) $r['t'];
	}

	/* 일별 */
	list( $from, $to ) = bounds( $ym );
	$daily = array();
	for ( $d = $from; $d <= $to; $d = gmdate( 'Y-m-d', strtotime( $d . ' +1 day' ) ) ) {
		$daily[ $d ] = array( 'n' => 0, 'sales' => 0.0, 'all' => 0, 'void' => 0 );
	}
	foreach ( $rows as $r ) {
		$d = (string) $r['d'];
		if ( ! isset( $daily[ $d ] ) ) {
			continue;
		}
		$daily[ $d ]['all']++;
		if ( in_array( (string) $r['s'], $conf_s, true ) ) {
			$daily[ $d ]['n']++;
			$daily[ $d ]['sales'] += (float) $r['t'];
		} elseif ( in_array( (string) $r['s'], $void_st, true ) ) {
			$daily[ $d ]['void']++;
		}
	}
	$best_day = '';
	$best_v   = -1.0;
	foreach ( $daily as $d => $x ) {
		if ( $x['sales'] > $best_v ) {
			$best_v   = $x['sales'];
			$best_day = $d;
		}
	}

	/* 상품 · 브랜드 (확정만) */
	$prod   = array();
	$brands = array();
	$bfn    = function_exists( '\\Duckhoo\\Redesign\\Anatomy\\brand' ) ? '\\Duckhoo\\Redesign\\Anatomy\\brand' : fn( $n ) => '기타';
	$sfn    = function_exists( '\\Duckhoo\\Redesign\\Anatomy\\short' ) ? '\\Duckhoo\\Redesign\\Anatomy\\short' : fn( $n ) => mb_substr( $n, 0, 28 );
	$units  = 0;
	foreach ( $conf as $r ) {
		foreach ( $items[ $r['id'] ] ?? array() as $l ) {
			$k = $sfn( (string) $l['name'] );
			$b = $bfn( (string) $l['name'] );
			if ( ! isset( $prod[ $k ] ) ) {
				$prod[ $k ] = array( 'n' => 0, 'qty' => 0, 'sales' => 0.0, 'brand' => $b );
			}
			$prod[ $k ]['n']++;
			$prod[ $k ]['qty']   += (int) $l['qty'];
			$prod[ $k ]['sales'] += (float) $l['total'];
			$brands[ $b ] = ( $brands[ $b ] ?? 0.0 ) + (float) $l['total'];
			$units += (int) $l['qty'];
		}
	}
	uasort( $prod, fn( $a, $b ) => $b['sales'] <=> $a['sales'] );
	arsort( $brands );

	/* 상태별 */
	$by_status = array();
	foreach ( $rows as $r ) {
		$s = (string) $r['s'];
		if ( ! isset( $by_status[ $s ] ) ) {
			$by_status[ $s ] = array( 'n' => 0, 'sales' => 0.0 );
		}
		$by_status[ $s ]['n']++;
		$by_status[ $s ]['sales'] += (float) $r['t'];
	}
	uasort( $by_status, fn( $a, $b ) => $b['n'] <=> $a['n'] );

	/* 지난달 */
	$pc  = $sum( array_values( array_filter( $prev, fn( $r ) => in_array( (string) $r['s'], $conf_s, true ) ) ) );
	$pv  = $sum( array_values( array_filter( $prev, fn( $r ) => in_array( (string) $r['s'], $void_st, true ) ) ) );
	$pa  = $sum( $prev );
	$ptot = array_map( fn( $r ) => (float) $r['t'], array_filter( $prev, fn( $r ) => in_array( (string) $r['s'], $conf_s, true ) ) );

	$days_in   = (int) gmdate( 't', strtotime( $from ) );
	$days_done = $ym < substr( $today, 0, 7 ) ? $days_in : max( 1, min( $days_in, (int) substr( $today, 8, 2 ) ) );

	return array(
		'ym'         => $ym,
		'from'       => $from,
		'to'         => $to,
		'closed'     => $ym < substr( $today, 0, 7 ),
		'days_in'    => $days_in,
		'days_done'  => $days_done,
		'all'        => $all,
		'conf'       => $c,
		'pend'       => $p,
		'void'       => $v,
		'cancel_rate' => $all['n'] > 0 ? (int) round( 100 * $v['n'] / $all['n'] ) : 0,
		'aov'        => $aov,
		'aov_median' => $med,
		'per_day'    => $days_done > 0 ? round( $c['sales'] / $days_done ) : 0,
		'units'      => $units,
		'cust'       => $cust,
		'signups'    => $signups,
		'signups_prev' => $signups_prev,
		'daily'      => $daily,
		'best_day'   => $best_day,
		'products'   => array_slice( $prod, 0, 15, true ),
		'brands'     => $brands,
		'by_status'  => $by_status,
		'prev'       => array(
			'ym'          => prev_ym( $ym ),
			'conf'        => $pc,
			'void'        => $pv,
			'all'         => $pa,
			'cancel_rate' => $pa['n'] > 0 ? (int) round( 100 * $pv['n'] / $pa['n'] ) : 0,
			'aov'         => $ptot ? round( array_sum( $ptot ) / count( $ptot ) ) : 0,
		),
	);
}

/**
 * 지난달 대비 (「+12%」). 지난달이 0 이면 빈 문자열.
 */
function delta( float $now, float $then ): string {
	return function_exists( '\\Duckhoo\\Redesign\\Sales\\delta' ) ? \Duckhoo\Redesign\Sales\delta( $now, $then ) : '';
}

/**
 * 붙여 넣기용 글.
 */
function report( array $c ): string {
	$w  = fn( $n ) => number_format( (float) round( (float) $n ) );
	$m  = $c['conf'];
	$pv = $c['prev'];
	$L  = array();
	$L[] = '액상덕후 ' . kmonth( $c['ym'] ) . ' 결산' . ( $c['closed'] ? '' : " (진행 중 · {$c['days_done']}일까지)" );
	$L[] = "접수 {$c['all']['n']}건 · 확정(실입금) {$m['n']}건 " . $w( $m['sales'] ) . "원 · 입금 대기 {$c['pend']['n']}건 " . $w( $c['pend']['sales'] ) . "원 · 취소·환불 {$c['void']['n']}건 " . $w( $c['void']['sales'] ) . "원 ({$c['cancel_rate']}%)";
	$L[] = '하루 평균 ' . $w( $c['per_day'] ) . "원 · 객단가 평균 " . $w( $c['aov'] ) . '원 · 중앙값 ' . $w( $c['aov_median'] ) . "원 · 상품 수량 {$c['units']}개";
	$dl  = delta( (float) $m['sales'], (float) $pv['conf']['sales'] );
	$L[] = '지난달(' . kmonth( $pv['ym'] ) . ') 확정 ' . $pv['conf']['n'] . '건 ' . $w( $pv['conf']['sales'] ) . '원' . ( '' !== $dl ? " → {$dl}" : '' ) . " · 지난달 취소율 {$pv['cancel_rate']}% · 지난달 객단가 " . $w( $pv['aov'] ) . '원';
	$L[] = '';
	$im = imweb( $c['ym'] );
	if ( function_exists( '\\Duckhoo\\Redesign\\Imweb\\line' ) ) {
		$L[] = '[' . \Duckhoo\Redesign\Imweb\line( $im ) . ']' . ( $im ? ' → 워드프레스 + 아임웹 = ' . $w( (float) $m['sales'] + (float) $im['sales'] ) . '원' : '' );
	}
	$L[] = '[뺄셈 장부] 할인 전 ' . $w( $m['before'] ) . '원 (상품 ' . $w( $m['goods'] ) . ' + 배송비 ' . $w( $m['ship'] ) . ') − 적립금 ' . $w( $m['points'] ) . ' − 쿠폰 ' . $w( $m['coupon'] ) . ' − 자동 할인 ' . $w( $m['fee'] ) . ' = 확정 매출 ' . $w( $m['sales'] ) . '원';
	$pc = parcels( $c['ym'] );
	if ( function_exists( '\\Duckhoo\\Redesign\\Parcels\\line' ) ) {
		$L[] = '[지출] ' . \Duckhoo\Redesign\Parcels\line( $pc['m'], $pc['unit'], (int) ( $pc['pack'] ?? 0 ) ) . ( $pc['cost'] > 0 ? ' → 지출 뺀 실입금 ' . $w( (float) $m['sales'] - $pc['cost'] ) . '원' : '' );
	}
	$cu  = $c['cust'];
	$L[] = "[손님] 산 회원 {$cu['buyers']}명 = 이 달 처음 {$cu['first_buyers']}명 + 전에도 산 {$cu['rep_buyers']}명 · 첫 주문 {$cu['first_n']}건 " . $w( $cu['first_sales'] ) . "원 · 재구매 {$cu['rep_n']}건 " . $w( $cu['rep_sales'] ) . '원' . ( $cu['guest_n'] ? " · 비회원 {$cu['guest_n']}건 " . $w( $cu['guest_sales'] ) . '원' : '' ) . " · 새 가입 {$c['signups']}명 (지난달 {$c['signups_prev']})";
	$L[] = '';
	$L[] = '[상품 상위] 이름 [브랜드]: 주문 · 수량 · 매출';
	foreach ( $c['products'] as $k => $p ) {
		$L[] = "{$k} [{$p['brand']}]: {$p['n']} · {$p['qty']} · " . $w( $p['sales'] ) . '원';
	}
	$L[] = '[브랜드] ' . implode( ' · ', array_map( fn( $k, $v ) => "{$k} " . $w( $v ) . '원', array_keys( array_slice( $c['brands'], 0, 10, true ) ), array_slice( $c['brands'], 0, 10 ) ) );
	$L[] = '[상태별] ' . implode( ' · ', array_map( fn( $k, $v ) => label( (string) $k ) . " {$v['n']}건", array_keys( $c['by_status'] ), $c['by_status'] ) );
	$L[] = '';
	$L[] = '[일별] 날짜: 확정 건수 · 확정 매출 · 접수 · 취소';
	foreach ( $c['daily'] as $d => $x ) {
		if ( ! $c['closed'] && $d > $c['from'] && substr( $d, 8, 2 ) > str_pad( (string) $c['days_done'], 2, '0', STR_PAD_LEFT ) ) {
			break;
		}
		$L[] = substr( $d, 5 ) . ": {$x['n']} · " . $w( $x['sales'] ) . " · {$x['all']} · {$x['void']}";
	}
	return implode( "\n", $L );
}

/**
 * 회계용 CSV — 주문 한 줄에 금액 칸 전부. 접수된 주문 전부(취소 포함, 상태 칸으로 가른다).
 *
 * @param array $rows  Sales\fetch 꼴.
 * @param array $items 주문 번호 => 줄들.
 * @return string
 */
function csv( array $rows, array $items ): string {
	$skip = array( 'checkout-draft', 'auto-draft', 'trash' );
	$q    = function ( $v ): string {
		$v = (string) $v;
		return '"' . str_replace( '"', '""', $v ) . '"';
	};
	$out  = array( implode( ',', array_map( $q, array( '주문번호', '날짜', '상태', '회원번호', '상품', '할인 전', '상품 금액', '배송비', '쿠폰', '적립금', '자동 할인', '실결제' ) ) ) );
	usort( $rows, fn( $a, $b ) => ( $a['ts'] <=> $b['ts'] ) ?: ( $a['id'] <=> $b['id'] ) );
	foreach ( $rows as $r ) {
		if ( in_array( (string) $r['s'], $skip, true ) ) {
			continue;
		}
		$ls   = $items[ $r['id'] ] ?? array();
		$name = $ls ? (string) $ls[0]['name'] : '';
		if ( count( $ls ) > 1 ) {
			$name .= ' 외 ' . ( count( $ls ) - 1 );
		}
		$bf = before( $r );
		$out[] = implode( ',', array_map( $q, array(
			$r['id'],
			$r['d'],
			label( (string) $r['s'] ),
			$r['c'] > 0 ? $r['c'] : '',
			$name,
			(int) round( $bf ),
			(int) round( $bf - (float) $r['ship'] ),
			(int) round( (float) $r['ship'] ),
			(int) round( (float) $r['coup'] ),
			(int) round( (float) $r['p'] ),
			(int) round( (float) $r['fee'] ),
			(int) round( (float) $r['t'] ),
		) ) );
	}
	return "\xEF\xBB\xBF" . implode( "\r\n", $out ) . "\r\n";
}


/* ── 1~3분용 글 · 아임웹 합산 · 매달 1일 발송 ─────────────────────────── */

/**
 * 아임웹 달 요약 (없으면 null).
 */
function imweb( string $ym ): ?array {
	return function_exists( '\\Duckhoo\\Redesign\\Imweb\\month' ) ? \Duckhoo\Redesign\Imweb\month( $ym ) : null;
}

/**
 * 우체국 소포 요약(있으면) 과 지출 — 배송비(상자 × 계약 단가) · 박스비(상자 × 박스 단가) · 합계.
 *
 * @return array{m:?array,unit:int,cost:int}
 */
function parcels( string $ym ): array {
	if ( ! function_exists( '\\Duckhoo\\Redesign\\Parcels\\month' ) ) {
		return array( 'm' => null, 'unit' => 0, 'cost' => 0 );
	}
	$m  = \Duckhoo\Redesign\Parcels\month( $ym );
	$u  = \Duckhoo\Redesign\Parcels\unit();
	$pk = function_exists( '\\Duckhoo\\Redesign\\Parcels\\pack_unit' ) ? \Duckhoo\Redesign\Parcels\pack_unit() : 0;
	$po = \Duckhoo\Redesign\Parcels\cost( $m, $u );
	$pc = function_exists( '\\Duckhoo\\Redesign\\Parcels\\pack_cost' ) ? \Duckhoo\Redesign\Parcels\pack_cost( $m, $pk ) : 0;
	// cost = 지출 합계(배송비 post + 박스비 pack_cost).
	return array( 'm' => $m, 'unit' => $u, 'pack' => $pk, 'post' => $po, 'pack_cost' => $pc, 'cost' => $po + $pc );
}

/**
 * 사장님이 1~3분에 읽는 글 — 「이렇게 했고 · 이런 결과 · 앞으로」. 디스코드 · 메일 공용.
 * 숫자만 나열하지 않는다: 결과는 지난달과 견줘 한 줄씩, 앞으로는 숫자가 가리키는 것만.
 *
 * @param array      $c      close() 결과.
 * @param array|null $im     아임웹 달 요약.
 * @param array      $log    이 달 작업 일지 전부 ([d, a, t]).
 * @param string     $seo    SEO 월간 보고 글 (없으면 빈 문자열).
 * @param bool       $discord 굵은 글씨를 쓸지.
 */
function brief_text( array $c, ?array $im, array $log, string $seo = '', bool $discord = true ): string {
	$b  = fn( $s ) => $discord ? "**{$s}**" : $s;
	$w  = fn( $n ) => number_format( (float) round( (float) $n ) );
	$m  = $c['conf'];
	$pv = $c['prev'];
	$L  = array();
	$L[] = $b( '액상덕후 ' . kmonth( $c['ym'] ) . ' 결산' . ( $c['closed'] ? '' : " (진행 중 · {$c['days_done']}일까지)" ) );

	/* 결과 */
	$L[] = $b( '이런 결과' );
	$dl  = delta( (float) $m['sales'], (float) $pv['conf']['sales'] );
	$L[] = '· 실제 들어온 돈 ' . $w( $m['sales'] ) . '원 (' . $m['n'] . '건)' . ( '' !== $dl ? " — 지난달 {$w( $pv['conf']['sales'] )}원보다 {$dl}" : '' );
	if ( $im ) {
		$tot = (float) $m['sales'] + (float) $im['sales'];
		$L[] = '· 아임웹까지 합치면 ' . $w( $tot ) . '원 (아임웹 ' . (int) $im['n'] . '건 ' . $w( $im['sales'] ) . '원)';
	} else {
		$L[] = '· 아임웹은 아직 안 합쳐짐 — 월말 결산 화면에 API 키를 넣거나 주문 목록을 붙여 넣으면 다음부터 같이 옵니다';
	}
	$da  = delta( (float) $c['aov'], (float) $pv['aov'] );
	$L[] = '· 한 번 살 때 ' . $w( $c['aov'] ) . '원' . ( '' !== $da ? " (지난달보다 {$da})" : '' ) . ' · 하루 평균 ' . $w( $c['per_day'] ) . '원';
	$cu  = $c['cust'];
	$L[] = '· 처음 산 회원 ' . $cu['first_buyers'] . '명 · 다시 산 회원 ' . $cu['rep_buyers'] . '명 · 새 가입 ' . $c['signups'] . '명 (지난달 ' . $c['signups_prev'] . ')';
	$L[] = '· 취소 · 환불 ' . $c['void']['n'] . '건 ' . $w( $c['void']['sales'] ) . '원 = 접수의 ' . $c['cancel_rate'] . '% (지난달 ' . $pv['cancel_rate'] . '%)' . ( $c['pend']['n'] > 0 ? ' · 아직 입금 안 된 주문 ' . $c['pend']['n'] . '건 ' . $w( $c['pend']['sales'] ) . '원' : '' );
	$pc = parcels( (string) $c['ym'] );
	if ( $pc['cost'] > 0 ) {
		$L[] = '· 지출 ' . $w( $pc['cost'] ) . '원 = 배송비 ' . $w( $pc['post'] ) . '원 (우체국 ' . (int) $pc['m']['n'] . '상자 × ' . $w( $pc['unit'] ) . '원)' . ( ! empty( $pc['pack_cost'] ) ? ' + 박스비 ' . $w( $pc['pack_cost'] ) . '원' : ' (박스비 단가 없음)' ) . ' → 지출 뺀 실입금 ' . $w( (float) $m['sales'] - $pc['cost'] ) . '원';
	}
	$top = array_slice( $c['products'], 0, 3, true );
	if ( $top ) {
		$L[] = '· 많이 팔린 것: ' . implode( ' · ', array_map( fn( $k, $p ) => "{$k} " . $w( $p['sales'] ) . '원', array_keys( $top ), $top ) );
	}

	/* 이렇게 했고 */
	$L[] = $b( '이렇게 했고' );
	if ( $log ) {
		foreach ( array_slice( $log, 0, 8 ) as $e ) {
			$L[] = '· ' . substr( $e['d'], 5 ) . ' ' . $e['t'];
		}
	} else {
		$L[] = '· 이 달에 적힌 작업이 없습니다 (도구 → 검색 노출 화면의 작업 일지에 적으면 여기에 옵니다)';
	}

	/* 앞으로 */
	$L[] = $b( '앞으로' );
	$next = array();
	if ( (int) $c['cancel_rate'] >= 20 ) {
		$next[] = '취소가 접수의 ' . $c['cancel_rate'] . '% — 미입금 자동 취소인지 손님 취소인지 주문 메모로 가르고, 미입금이면 주문 직후 안내(계좌 · 기한)를 손본다';
	}
	if ( $pv['conf']['sales'] > 0 && (float) $m['sales'] < (float) $pv['conf']['sales'] * 0.85 && $c['closed'] ) {
		$next[] = '매출이 지난달보다 15% 넘게 줄었다 — 신규 손님 수(가입 · 처음 산 회원)가 먼저 줄었는지 본다';
	}
	if ( $c['pend']['n'] >= 10 ) {
		$next[] = '입금 안 된 주문 ' . $c['pend']['n'] . '건 — 5일 넘은 것은 오늘 할 일에서 정리';
	}
	if ( ! $im ) {
		$next[] = '아임웹 주문을 합치려면 월말 결산 화면에서 API 키 저장 또는 주문 목록 붙여 넣기';
	}
	if ( ! $next ) {
		$next[] = '숫자가 가리키는 급한 일 없음 — 다음 달도 같은 자리에서 본다';
	}
	foreach ( array_slice( $next, 0, 4 ) as $n ) {
		$L[] = '· ' . $n;
	}
	if ( '' !== $seo ) {
		$L[] = '';
		$L[] = $seo;
	}
	return implode( "\n", $L );
}

/**
 * 보낼 글 통째 — 결산 + SEO 보고. `$ym` 달의 것.
 */
function send_text( string $ym, bool $discord = true ): string {
	$d   = data( $ym );
	$log = function_exists( '\\Duckhoo\\Redesign\\Seo\\Report\\entries' ) ? \Duckhoo\Redesign\Seo\Report\entries( $ym ) : array();
	$seo = '';
	if ( function_exists( '\\Duckhoo\\Redesign\\Seo\\Report\\report' ) ) {
		try {
			$rep = \Duckhoo\Redesign\Seo\Report\report( $ym, array( 'signups' => $d['c']['signups'], 'first_buyers' => $d['c']['cust']['first_buyers'] ) );
			$seo = $discord ? (string) $rep['text'] : (string) $rep['plain'];
		} catch ( \Throwable $e ) {
			$seo = '';
		}
	}
	return brief_text( $d['c'], imweb( $ym ), $log, $seo, $discord );
}

/**
 * 보낸다 — 디스코드(오늘 할 일의 웹훅) + 관리자 메일(오늘 할 일 메일이 켜져 있을 때).
 *
 * @return int 디스코드 조각 수 (0 이면 못 보냄) · 메일은 따로 안 센다.
 */
function send( string $ym ): int {
	$text = send_text( $ym, true );
	$n    = function_exists( '\\Duckhoo\\Redesign\\Today\\discord_send' ) ? \Duckhoo\Redesign\Today\discord_send( $text ) : 0;
	if ( function_exists( '\\Duckhoo\\Redesign\\Today\\mail_on' ) && \Duckhoo\Redesign\Today\mail_on() && function_exists( 'wp_mail' ) ) {
		$to = function_exists( '\\Duckhoo\\Redesign\\Today\\mail_to' ) ? \Duckhoo\Redesign\Today\mail_to() : (string) get_option( 'admin_email', '' );
		if ( '' !== $to ) {
			wp_mail( $to, '[액상덕후] ' . kmonth( $ym ) . ' 결산', send_text( $ym, false ) . "\n\n" . page_url( $ym ) );
		}
	}
	return $n;
}

/**
 * 크론 — 매일 SEND_AT 에 깨어나, 1일(놓치면 2 · 3일)이고 지난달을 아직 안 보냈으면 보낸다.
 * 오늘 할 일 크론처럼 「언제 불리든」 여기서 거른다 — 한 달에 한 통.
 */
function cron_send(): void {
	$today = (string) current_time( 'Y-m-d' );
	$day   = (int) substr( $today, 8, 2 );
	if ( $day > 3 ) {
		return;
	}
	$ym = prev_ym( substr( $today, 0, 7 ) );
	if ( (string) get_option( 'duckhoo_monthly_sent', '' ) === $ym ) {
		return;
	}
	$now = (int) current_time( 'H' ) * 60 + (int) current_time( 'i' );
	list( $h, $mi ) = array_map( 'intval', explode( ':', SEND_AT ) );
	$at = $h * 60 + $mi;
	if ( $now < $at - 10 || $now > $at + 120 ) {
		return;
	}
	update_option( 'duckhoo_monthly_sent', $ym, false ); // 보내기 전에 적는다 — 겹쳐 불려도 한 통
	send( $ym );
}

function schedule(): void {
	if ( ! function_exists( 'wp_next_scheduled' ) || wp_next_scheduled( CRON ) ) {
		return;
	}
	$tz    = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'Asia/Seoul' );
	$first = new \DateTime( 'today ' . SEND_AT, $tz );
	if ( $first->getTimestamp() <= time() ) {
		$first->modify( '+1 day' );
	}
	wp_schedule_event( $first->getTimestamp(), 'daily', CRON );
}

/* ── 화면 ───────────────────────────────────────────────────────────── */

function ym_from_request(): string {
	$ym = isset( $_GET['dhr_m'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['dhr_m'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	return valid_ym( $ym ) ? $ym : default_ym( (string) current_time( 'Y-m-d' ) );
}

function page_url( string $ym ): string {
	return add_query_arg( array( 'page' => SLUG, 'dhr_m' => $ym ), admin_url( 'admin.php' ) );
}

function export(): void {
	if ( ! may() || ! check_admin_referer( 'dhr-monthly-csv' ) ) {
		wp_die( '권한이 없습니다.' );
	}
	$ym = isset( $_GET['dhr_m'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['dhr_m'] ) ) : '';
	if ( ! valid_ym( $ym ) ) {
		wp_die( '달이 잘못됐습니다.' );
	}
	$d = data( $ym );
	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="duckhoo-' . $ym . '.csv"' );
	echo csv( (array) $d['rows'], (array) $d['items'] ); // phpcs:ignore WordPress.Security.EscapeOutput
	exit;
}

function to_discord(): void {
	if ( ! may() || ! check_admin_referer( 'dhr-monthly-discord' ) ) {
		wp_die( '권한이 없습니다.' );
	}
	$ym = isset( $_GET['dhr_m'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['dhr_m'] ) ) : '';
	if ( ! valid_ym( $ym ) ) {
		wp_die( '달이 잘못됐습니다.' );
	}
	$n = send( $ym );
	wp_safe_redirect( add_query_arg( 'sent', (string) $n, page_url( $ym ) ) );
	exit;
}

function screen(): void {
	if ( ! may() ) {
		wp_die( '권한이 없습니다.' );
	}
	$ym    = ym_from_request();
	$imsg  = function_exists( '\\Duckhoo\\Redesign\\Imweb\\handle_post' ) ? \Duckhoo\Redesign\Imweb\handle_post() : '';
	$pmsg  = function_exists( '\\Duckhoo\\Redesign\\Parcels\\handle_post' ) ? \Duckhoo\Redesign\Parcels\handle_post() : '';
	$fresh = isset( $_GET['dhr_fresh'] ) && check_admin_referer( 'dhr-monthly-fresh' ); // phpcs:ignore WordPress.Security.NonceVerification
	echo '<div class="wrap dhr-sl">';
	if ( function_exists( '\\Duckhoo\\Redesign\\Sales\\styles' ) ) {
		\Duckhoo\Redesign\Sales\styles();
	}
	echo '<h1 class="dhr-sl-h1">월말 결산 — ' . esc_html( kmonth( $ym ) ) . '</h1>';
	try {
		$d = data( $ym, $fresh );
	} catch ( \Throwable $e ) {
		echo '<div class="notice notice-error"><p>읽다 멈췄습니다: ' . esc_html( $e->getMessage() ) . ' (' . esc_html( basename( $e->getFile() ) . ':' . $e->getLine() ) . ')</p></div></div>';
		return;
	}
	render( $d['c'], $ym, (string) $d['built'], (float) $d['took'], $imsg );
	echo '</div>';
}

/**
 * 몸통 — 데이터를 받아 그린다 (가짜 데이터로도 그릴 수 있게 따로).
 */
function render( array $c, string $ym, string $built, float $took, string $imsg = '', string $pmsg = '' ): void {
	$w    = fn( $n ) => number_format( (float) round( (float) $n ) );
	$card = function ( string $l, string $v, string $n = '', string $tone = '' ) {
		if ( function_exists( '\\Duckhoo\\Redesign\\Sales\\card' ) ) {
			\Duckhoo\Redesign\Sales\card( $l, $v, $n, $tone );
		}
	};
	$m  = $c['conf'];
	$pv = $c['prev'];

	if ( isset( $_GET['sent'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		$n = (int) $_GET['sent']; // phpcs:ignore WordPress.Security.NonceVerification
		echo '<div class="notice notice-' . ( $n > 0 ? 'success' : 'warning' ) . '"><p>' . ( $n > 0 ? '디스코드로 보냈습니다.' : '디스코드로 못 보냈습니다 — 오늘 할 일 화면에 웹훅 주소가 있는지 보세요.' ) . '</p></div>';
	}

	/* 달 고르기 */
	$today = (string) current_time( 'Y-m-d' );
	echo '<p class="dhr-sl-note">';
	$cur = substr( $today, 0, 7 );
	for ( $i = 0; $i < 8; $i++ ) {
		$m_i = gmdate( 'Y-m', strtotime( $cur . '-01 -' . $i . ' month' ) );
		echo $i ? ' · ' : '';
		echo $m_i === $ym ? '<b>' . esc_html( kmonth( $m_i ) ) . '</b>' : '<a href="' . esc_url( page_url( $m_i ) ) . '">' . esc_html( kmonth( $m_i ) ) . '</a>';
	}
	echo '</p>';
	echo '<p class="dhr-sl-note">' . ( $c['closed'] ? '닫힌 달입니다.' : '진행 중인 달입니다 — ' . (int) $c['days_done'] . '일까지 접수된 주문으로 셉니다.' ) . ' 확정 = 입금확인 이후 상태(손님이 실제로 입금한 돈). 읽기만 합니다.</p>';

	/* 카드 */
	echo '<div class="dhr-sl-cards">';
	$dl = delta( (float) $m['sales'], (float) $pv['conf']['sales'] );
	$card( '확정 매출 (실입금)', $w( $m['sales'] ) . '원', $m['n'] . '건 · 지난달 ' . $w( $pv['conf']['sales'] ) . '원' . ( '' !== $dl ? " ({$dl})" : '' ) );
	$card( '하루 평균', $w( $c['per_day'] ) . '원', $c['days_done'] . '일 기준 · 가장 큰 날 ' . ( $c['best_day'] ? substr( $c['best_day'], 5 ) . ' ' . $w( $c['daily'][ $c['best_day'] ]['sales'] ) . '원' : '—' ) );
	$card( '입금 대기', $w( $c['pend']['sales'] ) . '원', $c['pend']['n'] . '건 — 아직 안 들어온 돈', 'wait' );
	$card( '취소 · 환불', $c['void']['n'] . '건 · ' . $c['cancel_rate'] . '%', '접수 ' . $c['all']['n'] . '건 중 · 지난달 ' . $pv['cancel_rate'] . '%', $c['cancel_rate'] >= 25 ? 'warm' : '' );
	$card( '객단가', $w( $c['aov'] ) . '원', '중앙값 ' . $w( $c['aov_median'] ) . '원 · 지난달 ' . $w( $pv['aov'] ) . '원' );
	$cu = $c['cust'];
	$card( '이 달 처음 산 회원', $cu['first_buyers'] . '명', '첫 주문 ' . $cu['first_n'] . '건 ' . $w( $cu['first_sales'] ) . '원' );
	$card( '전에도 산 회원', $cu['rep_buyers'] . '명', '재구매 ' . $cu['rep_n'] . '건 ' . $w( $cu['rep_sales'] ) . '원' );
	$card( '새 가입', $c['signups'] . '명', '지난달 ' . $c['signups_prev'] . '명' );
	echo '</div>';

	/* 뺄셈 장부 */
	echo '<section class="dhr-sl-sec"><h2>뺄셈 장부</h2>';
	echo '<p class="dhr-sl-note">확정 매출은 아래 할인이 <b>이미 빠진</b> 금액입니다. 여기서 또 빼지 마세요. 배송비는 받은 돈에 들어 있어 따로 적어 둡니다.</p>';
	echo '<div class="dhr-sl-scroll"><table class="widefat striped dhr-sl-kv"><tbody>';
	printf( '<tr><td>할인 전 주문 금액</td><td class="dhr-sl-num">%s</td><td class="dhr-sl-mut">상품 %s + 배송비 %s</td></tr>', esc_html( won( (float) $m['before'] ) ), esc_html( won( (float) $m['goods'] ) ), esc_html( won( (float) $m['ship'] ) ) );
	foreach ( array( '− 적립금 사용' => $m['points'], '− 쿠폰 할인' => $m['coupon'], '− 자동 할인 · 기타' => $m['fee'] ) as $label => $v ) {
		printf( '<tr><td>%s</td><td class="dhr-sl-num">%s</td><td class="dhr-sl-num dhr-sl-mut">%s</td></tr>', esc_html( $label ), esc_html( won( (float) $v ) ), esc_html( $m['before'] > 0 ? sprintf( '할인 전의 %.1f%%', (float) $v / $m['before'] * 100 ) : '—' ) );
	}
	printf( '<tr><td><b>= 확정 매출 (실제로 받은 돈)</b></td><td class="dhr-sl-num"><b>%s</b></td><td class="dhr-sl-mut">%d건</td></tr>', esc_html( won( (float) $m['sales'] ) ), (int) $m['n'] );
	$pc = parcels( $ym );
	if ( $pc['cost'] > 0 ) {
		echo '<tr><td colspan="3"><b>지출</b> <span class="dhr-sl-mut">— 배송비와 박스비는 따로 적고 여기서 합칩니다</span></td></tr>';
		printf( '<tr><td>− 배송비 (우체국 요금)</td><td class="dhr-sl-num">%s</td><td class="dhr-sl-mut">%d상자 × %s원 · 손님에게 받은 배송비 %s 는 위 실입금에 들어 있음</td></tr>', esc_html( won( (float) $pc['post'] ) ), (int) $pc['m']['n'], esc_html( number_format( $pc['unit'] ) ), esc_html( won( (float) $m['ship'] ) ) );
		if ( ! empty( $pc['pack_cost'] ) ) {
			printf( '<tr><td>− 박스비</td><td class="dhr-sl-num">%s</td><td class="dhr-sl-mut">%d상자 × %s원</td></tr>', esc_html( won( (float) $pc['pack_cost'] ) ), (int) $pc['m']['n'], esc_html( number_format( (int) $pc['pack'] ) ) );
		} else {
			echo '<tr><td class="dhr-sl-mut" colspan="3">박스비는 아래 상자의 「박스비 단가」를 넣으면 줄이 생깁니다.</td></tr>';
		}
		printf( '<tr><td><b>= 지출 합계</b></td><td class="dhr-sl-num"><b>%s</b></td><td class="dhr-sl-mut">%s</td></tr>', esc_html( won( (float) $pc['cost'] ) ), esc_html( $m['sales'] > 0 ? sprintf( '실입금의 %.1f%%', (float) $pc['cost'] / $m['sales'] * 100 ) : '—' ) );
		printf( '<tr><td><b>= 지출 뺀 실입금</b></td><td class="dhr-sl-num"><b>%s</b></td><td class="dhr-sl-mut">상품 원가는 아직 없음</td></tr>', esc_html( won( (float) $m['sales'] - $pc['cost'] ) ) );
	} else {
		echo '<tr><td class="dhr-sl-mut" colspan="3">지출(배송비 · 박스비)은 아래 「지출」 상자에 발송 내역을 올리면 여기에 줄이 생깁니다.</td></tr>';
	}
	echo '</tbody></table></div></section>';

	/* 일별 */
	echo '<section class="dhr-sl-sec"><h2>일별</h2>';
	$max = 0.0;
	foreach ( $c['daily'] as $x ) {
		$max = max( $max, (float) $x['sales'] );
	}
	echo '<div class="dhr-sl-chart">';
	foreach ( $c['daily'] as $d => $x ) {
		if ( ! $c['closed'] && (int) substr( $d, 8, 2 ) > (int) $c['days_done'] ) {
			break;
		}
		$h = $max > 0 ? max( 2, (int) round( 100 * (float) $x['sales'] / $max ) ) : 2;
		printf( '<div class="dhr-sl-bar"><b>%s · %s원 · %d건</b><i style="height:%d%%"></i><span>%d</span></div>', esc_html( substr( $d, 5 ) ), esc_html( $w( $x['sales'] ) ), (int) $x['n'], $h, (int) substr( $d, 8, 2 ) );
	}
	echo '</div>';
	echo '<details class="dhr-sl-tab"><summary>표로 보기</summary><div class="dhr-sl-scroll"><table class="widefat striped"><thead><tr><th>날짜</th><th>확정</th><th>확정 매출</th><th>접수</th><th>취소</th></tr></thead><tbody>';
	foreach ( $c['daily'] as $d => $x ) {
		if ( ! $c['closed'] && (int) substr( $d, 8, 2 ) > (int) $c['days_done'] ) {
			break;
		}
		printf( '<tr><td>%s</td><td class="dhr-sl-num">%d</td><td class="dhr-sl-num">%s</td><td class="dhr-sl-num">%d</td><td class="dhr-sl-num">%d</td></tr>', esc_html( $d ), (int) $x['n'], esc_html( won( (float) $x['sales'] ) ), (int) $x['all'], (int) $x['void'] );
	}
	echo '</tbody></table></div></details></section>';

	/* 상품 · 브랜드 */
	echo '<section class="dhr-sl-sec"><h2>상품 상위 15</h2><div class="dhr-sl-scroll"><table class="widefat striped"><thead><tr><th>상품</th><th>브랜드</th><th>주문</th><th>수량</th><th>매출</th></tr></thead><tbody>';
	foreach ( $c['products'] as $k => $p ) {
		printf( '<tr><td>%s</td><td>%s</td><td class="dhr-sl-num">%d</td><td class="dhr-sl-num">%d</td><td class="dhr-sl-num">%s</td></tr>', esc_html( $k ), esc_html( $p['brand'] ), (int) $p['n'], (int) $p['qty'], esc_html( won( (float) $p['sales'] ) ) );
	}
	echo '</tbody></table></div>';
	echo '<h2 style="margin-top:14px">브랜드</h2><div class="dhr-sl-scroll"><table class="widefat striped"><thead><tr><th>브랜드</th><th>매출</th><th>몫</th></tr></thead><tbody>';
	$bs = array_sum( $c['brands'] );
	foreach ( array_slice( $c['brands'], 0, 12, true ) as $b => $v ) {
		printf( '<tr><td>%s</td><td class="dhr-sl-num">%s</td><td class="dhr-sl-num dhr-sl-mut">%s</td></tr>', esc_html( $b ), esc_html( won( (float) $v ) ), esc_html( $bs > 0 ? sprintf( '%.0f%%', (float) $v / $bs * 100 ) : '—' ) );
	}
	echo '</tbody></table></div></section>';

	/* 상태별 */
	echo '<section class="dhr-sl-sec"><h2>상태별 (접수 전부)</h2><div class="dhr-sl-scroll"><table class="widefat striped"><thead><tr><th>상태</th><th>건수</th><th>금액</th></tr></thead><tbody>';
	foreach ( $c['by_status'] as $s => $x ) {
		printf( '<tr><td>%s</td><td class="dhr-sl-num">%d</td><td class="dhr-sl-num">%s</td></tr>', esc_html( label( (string) $s ) ), (int) $x['n'], esc_html( won( (float) $x['sales'] ) ) );
	}
	echo '</tbody></table></div></section>';

	/* 아임웹 */
	if ( function_exists( '\\Duckhoo\\Redesign\\Imweb\\box' ) ) {
		\Duckhoo\Redesign\Imweb\box( $ym, $imsg );
	}
	if ( function_exists( '\\Duckhoo\\Redesign\\Parcels\\box' ) ) {
		\Duckhoo\Redesign\Parcels\box( $ym, $pmsg );
	}

	/* 내보내기 · 붙여 넣기 */
	$csv_url = wp_nonce_url( add_query_arg( array( 'action' => 'dhr_monthly_csv', 'dhr_m' => $ym ), admin_url( 'admin-post.php' ) ), 'dhr-monthly-csv' );
	$dc_url  = wp_nonce_url( add_query_arg( array( 'action' => 'dhr_monthly_discord', 'dhr_m' => $ym ), admin_url( 'admin-post.php' ) ), 'dhr-monthly-discord' );
	$re_url  = wp_nonce_url( add_query_arg( 'dhr_fresh', '1', page_url( $ym ) ), 'dhr-monthly-fresh' );
	echo '<section class="dhr-sl-sec"><h2>내보내기</h2>';
	echo '<p><a class="button button-primary" href="' . esc_url( $csv_url ) . '">회계용 CSV 내려받기</a> <a class="button" href="' . esc_url( $dc_url ) . '">디스코드 · 메일로 보내기</a> <a class="button" href="' . esc_url( $re_url ) . '">지금 다시 읽기</a></p>';
	echo '<p class="dhr-sl-note">CSV 는 접수된 주문 한 줄에 상태 · 할인 전 · 상품 금액 · 배송비 · 쿠폰 · 적립금 · 자동 할인 · 실결제. 엑셀에서 바로 열립니다 (취소 주문도 들어 있으니 상태 칸으로 거르세요).</p>';
	echo '<h2 style="margin-top:14px">사장님용 한 장 (디스코드 · 메일로 가는 글)</h2>';
	$im  = imweb( $ym );
	$log = function_exists( '\\Duckhoo\\Redesign\\Seo\\Report\\entries' ) ? \Duckhoo\Redesign\Seo\Report\entries( $ym ) : array();
	echo '<textarea readonly style="width:100%;min-height:200px;font-family:inherit;font-size:13px" onclick="this.select()">' . esc_textarea( brief_text( $c, $im, $log, '', false ) ) . '</textarea>';
	echo '<p class="dhr-sl-note">매달 1일 ' . esc_html( SEND_AT ) . ' 에 지난달 것이 이 글 + 검색 노출 월간 보고로 디스코드(오늘 할 일 웹훅)와 관리자 메일로 갑니다. 「이렇게 했고」는 도구 → 검색 노출 화면의 작업 일지에서 옵니다.</p>';
	echo '<h2 style="margin-top:14px">클로드에게 보내기</h2>';
	echo '<textarea readonly style="width:100%;min-height:260px;font-family:inherit;font-size:13px" onclick="this.select()">' . esc_textarea( report( $c ) ) . '</textarea>';
	echo '</section>';

	echo '<div class="dhr-sl-foot"><p>' . esc_html( $built ) . ' 에 읽음 (' . esc_html( (string) $took ) . '초). 닫힌 달은 하루, 진행 중인 달은 30분 캐시. 확정 = ' . esc_html( implode( ' · ', function_exists( '\\Duckhoo\\Redesign\\Sales\\names' ) ? \Duckhoo\Redesign\Sales\names( confirmed() ) : confirmed() ) ) . ' · 입금 대기 = ' . esc_html( implode( ' · ', function_exists( '\\Duckhoo\\Redesign\\Sales\\names' ) ? \Duckhoo\Redesign\Sales\names( pending() ) : pending() ) ) . '. 「이 달 처음 산 회원」은 이 달 전에 돈 들어온 주문이 하나도 없는 회원입니다. 지출은 우체국 발송 내역의 상자 수 × 계약 단가(배송비) 와 × 박스 단가(박스비)를 따로 적어 합친 것입니다.</p></div>';
}
