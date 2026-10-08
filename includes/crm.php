<?php
/**
 * 마케팅 → 고객 세그먼트 (2026-10-08, 사장님 「CRM 해야 해서 — 장바구니 담고 나간 손님 · 노보/디오 구매 손님 · RFM · 미입금 고객」).
 *
 * 사이트가 실제 주문 · 장바구니 데이터로 명단 네 가지를 뽑고, 문자에 쓸 CSV(이름 · 연락처 · 기준값)를 내려준다.
 * **읽기 전용** — 주문 · 회원 · 장바구니에 아무것도 쓰지 않는다. 문자를 보내는 것은 사장님 몫(이 화면은 명단만).
 *
 *  1. 장바구니 담고 나간 손님 — 회원의 저장 장바구니(`_woocommerce_persistent_cart_N`)가 비어 있지 않은데
 *     최근 N일(기본 7) 안에 살아 있는 주문이 없는 사람. 장바구니에는 시각이 없어 「주문이 없다」로 가른다
 *  2. 노보 · 디오리퀴드 구매 손님 — 최근 N일(기본 180) 돈 들어온 주문에 그 브랜드 상품이 든 회원. 브랜드는 주문 해부의 `brand()`
 *  3. RFM — 돈 들어온 주문으로 회원마다 R(마지막 구매 뒤 며칠) · F(주문 수) · M(누적 금액)을 1~5점(5분위)으로 매기고 일곱 갈래로 묶는다
 *  4. 미입금 고객 — 입금전(on-hold · pending)으로 N일(기본 1) 넘게 서 있는 주문. 비회원도 주문의 청구 연락처로 잡는다
 *
 * 순수 함수(`seg_*` · `rfm()` · `score5()` · `rfm_label()` · `phone_norm()` · `dedupe()`)는 `php design/php-tests/run.php` 가 본다.
 *
 * @package Duckhoo\Redesign
 */

namespace Duckhoo\Redesign\Crm;

defined( 'ABSPATH' ) || exit;

const SLUG  = 'duckhoo-crm';
const CACHE = 'dhr_crm_v1_';
const D     = 86400;

function may(): bool {
	return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
}

function menu(): void {
	if ( ! may() ) {
		return;
	}
	$cap    = current_user_can( 'manage_woocommerce' ) ? 'manage_woocommerce' : 'manage_options';
	$parent = isset( $GLOBALS['admin_page_hooks']['woocommerce-marketing'] ) ? 'woocommerce-marketing' : 'tools.php';
	add_submenu_page( $parent, '고객 세그먼트', '고객 세그먼트', $cap, SLUG, __NAMESPACE__ . '\\screen' );
}
add_action( 'admin_menu', __NAMESPACE__ . '\\menu', 99 );

/* ── 기준 ─────────────────────────────────────────────────────────────── */

/** @return string[] */
function paid_statuses(): array {
	return function_exists( '\\Duckhoo\\Redesign\\Anatomy\\paid_statuses' ) ? \Duckhoo\Redesign\Anatomy\paid_statuses() : array( 'payment-confirmed', 'ready-to-ship', 'shipping', 'delivered', 'completed', 'processing' );
}

/** 입금전 — 아직 돈이 안 들어온 주문. @return string[] */
function pending_statuses(): array {
	return (array) apply_filters( 'duckhoo_crm_pending_statuses', array( 'on-hold', 'pending', '입금전', 'need-check' ) );
}

/** 죽은 주문 — 취소 · 환불 · 실패. 「살아 있는 주문」을 셀 때 뺀다. @return string[] */
function dead_statuses(): array {
	return (array) apply_filters( 'duckhoo_crm_dead_statuses', array( 'cancelled', 'refunded', 'failed', 'checkout-draft', 'trash', 'auto-draft' ) );
}

/** 2번 세그먼트의 브랜드. 필터 `duckhoo_crm_brands`. @return string[] */
function brands(): array {
	return (array) apply_filters( 'duckhoo_crm_brands', array( '노보', '디오리퀴드' ) );
}

/* ── 순수 함수 ───────────────────────────────────────────────────────── */

/** 연락처를 숫자만으로 — `+82 10-1234-5678` → `01012345678`. 못 읽으면 ''. */
function phone_norm( string $p ): string {
	$d = preg_replace( '/\D+/', '', $p );
	if ( '' === $d ) {
		return '';
	}
	if ( str_starts_with( $d, '82' ) && strlen( $d ) >= 11 ) {
		$d = '0' . substr( $d, 2 );
	}
	return strlen( $d ) >= 9 ? $d : '';
}

/**
 * 1. 장바구니 담고 나간 손님.
 *
 * @param array<int,array{n:int,qty:int,total:float,names:string[]}> $carts  uid => 저장 장바구니 요약.
 * @param array<int,array{id:int,ts:int,s:string,t:float,u:int}>      $orders 주문 머리 전부.
 * @return array<int,array{u:int,n:int,qty:int,total:float,names:string[],last:int,paid_n:int}>
 */
function seg_abandon( array $carts, array $orders, int $now, int $days = 7 ): array {
	$since = $now - $days * D;
	$recent = array();
	$last   = array();
	$paidn  = array();
	$dead   = dead_statuses();
	$paid   = paid_statuses();
	foreach ( $orders as $o ) {
		$u = (int) $o['u'];
		if ( $u <= 0 ) {
			continue;
		}
		if ( ! in_array( $o['s'], $dead, true ) ) {
			if ( $o['ts'] >= $since ) {
				$recent[ $u ] = true;
			}
			$last[ $u ] = max( $last[ $u ] ?? 0, (int) $o['ts'] );
		}
		if ( in_array( $o['s'], $paid, true ) ) {
			$paidn[ $u ] = ( $paidn[ $u ] ?? 0 ) + 1;
		}
	}
	$out = array();
	foreach ( $carts as $u => $c ) {
		$u = (int) $u;
		if ( $u <= 0 || empty( $c['n'] ) || isset( $recent[ $u ] ) ) {
			continue;
		}
		$out[] = array(
			'u'      => $u,
			'n'      => (int) $c['n'],
			'qty'    => (int) $c['qty'],
			'total'  => (float) $c['total'],
			'names'  => array_values( (array) ( $c['names'] ?? array() ) ),
			'last'   => (int) ( $last[ $u ] ?? 0 ),
			'paid_n' => (int) ( $paidn[ $u ] ?? 0 ),
		);
	}
	usort( $out, fn( $a, $b ) => $b['total'] <=> $a['total'] ?: $b['qty'] <=> $a['qty'] );
	return $out;
}

/**
 * 2. 브랜드 구매 손님 — 최근 N일 돈 들어온 주문에 그 브랜드가 든 회원.
 *
 * @param array<int,array<int,array{pid:int,name:string,qty:int,total:float}>> $items 주문 번호 => 상품 줄.
 * @return array<int,array{u:int,brands:string[],n:int,last:int,total:float,fav:string,bottles:int}>
 */
function seg_brand( array $orders, array $items, array $brands, int $now, int $days = 180 ): array {
	$since = $now - $days * D;
	$paid  = paid_statuses();
	$acc   = array();
	$bf    = function_exists( '\\Duckhoo\\Redesign\\Anatomy\\brand' ) ? '\\Duckhoo\\Redesign\\Anatomy\\brand' : fn( $n ) => preg_match( '/^\s*\[([^\]]+)\]/u', $n, $m ) ? trim( $m[1] ) : '기타';
	$sf    = function_exists( '\\Duckhoo\\Redesign\\Anatomy\\short' ) ? '\\Duckhoo\\Redesign\\Anatomy\\short' : fn( $n ) => $n;
	foreach ( $orders as $o ) {
		$u = (int) $o['u'];
		if ( $u <= 0 || $o['ts'] < $since || ! in_array( $o['s'], $paid, true ) ) {
			continue;
		}
		$hit = false;
		foreach ( (array) ( $items[ $o['id'] ] ?? array() ) as $it ) {
			$b = $bf( (string) $it['name'] );
			if ( ! in_array( $b, $brands, true ) ) {
				continue;
			}
			$hit = true;
			$acc[ $u ]['brands'][ $b ] = true;
			$k = $b . ' ' . $sf( (string) $it['name'] );
			$acc[ $u ]['prod'][ $k ]   = ( $acc[ $u ]['prod'][ $k ] ?? 0 ) + (int) $it['qty'];
			$acc[ $u ]['bottles']      = ( $acc[ $u ]['bottles'] ?? 0 ) + (int) $it['qty'];
		}
		if ( $hit ) {
			$acc[ $u ]['n']     = ( $acc[ $u ]['n'] ?? 0 ) + 1;
			$acc[ $u ]['total'] = ( $acc[ $u ]['total'] ?? 0 ) + (float) $o['t'];
			$acc[ $u ]['last']  = max( $acc[ $u ]['last'] ?? 0, (int) $o['ts'] );
		}
	}
	$out = array();
	foreach ( $acc as $u => $a ) {
		arsort( $a['prod'] );
		$out[] = array(
			'u'       => (int) $u,
			'brands'  => array_keys( $a['brands'] ),
			'n'       => (int) $a['n'],
			'last'    => (int) $a['last'],
			'total'   => (float) $a['total'],
			'fav'     => (string) array_key_first( $a['prod'] ),
			'bottles' => (int) $a['bottles'],
		);
	}
	usort( $out, fn( $a, $b ) => $b['last'] <=> $a['last'] );
	return $out;
}

/**
 * 5분위 점수 — 값을 1~5 로. `$higher` 가 true 면 큰 값이 5점, false 면 작은 값이 5점(R: 며칠 전일수록 좋다).
 * 같은 값은 같은 점수. 사람이 5명 미만이면 전부 가운데 3점.
 *
 * @param array<int,float|int> $vals key => 값.
 * @return array<int,int>
 */
function score5( array $vals, bool $higher = true ): array {
	$n = count( $vals );
	if ( ! $n ) {
		return array();
	}
	if ( $n < 5 ) {
		return array_map( fn() => 3, $vals );
	}
	$sorted = $vals;
	asort( $sorted, SORT_NUMERIC );
	$keys = array_keys( $sorted );
	$out  = array();
	$prev = null;
	$ps   = 0;
	foreach ( $keys as $i => $k ) {
		$v = $sorted[ $k ];
		if ( null !== $prev && $v == $prev ) { // phpcs:ignore Universal.Operators.StrictComparisons
			$s = $ps;
		} else {
			$s = (int) min( 5, max( 1, (int) ceil( ( $i + 1 ) / $n * 5 ) ) );
		}
		$out[ $k ] = $higher ? $s : 6 - $s;
		$prev      = $v;
		$ps        = $s;
	}
	return $out;
}

/** RFM 점수 → 갈래 이름. */
function rfm_label( int $r, int $f, int $m ): string {
	if ( $r >= 4 && $f >= 4 && $m >= 4 ) {
		return '챔피언';
	}
	if ( $f >= 4 && $r >= 3 ) {
		return '충성';
	}
	if ( $r >= 4 && $f >= 2 ) {
		return '잠재 충성';
	}
	if ( $r >= 4 ) {
		return '신규';
	}
	if ( $r === 3 ) {
		return '관심 필요';
	}
	if ( $f >= 3 || $m >= 4 ) {
		return '이탈 위험';
	}
	return '휴면';
}

/** 갈래마다 「무엇을 보낼까」 한 줄. @return array<string,string> */
function rfm_advice(): array {
	return array(
		'챔피언'   => '자주 · 많이 · 최근에 산 손님. 할인보다 먼저 알려 주기(신상 · 재입고). 쿠폰은 안 줘도 산다',
		'충성'     => '자주 사는 손님. 묶음(10+1 · 5+5) 안내가 맞는다',
		'잠재 충성' => '최근에 두세 번 산 손님. 다음 한 번을 만들면 충성으로 간다 — 재구매 쿠폰 한 장',
		'신규'     => '최근 한 번 산 손님. 첫 재구매가 열쇠 — 받은 상품 후기 · 같은 브랜드 다른 맛 안내',
		'관심 필요' => '한두 달 뜸해진 손님. 「재고 있음」 · 가격 안내 한 통',
		'이탈 위험' => '전에는 잘 샀는데 오래 안 온 손님. 가장 센 쿠폰은 여기에',
		'휴면'     => '오래 안 왔고 많이 사지도 않았던 손님. 문자 비용 대비 돌아올 가능성이 낮다 — 한 번만',
	);
}

/**
 * 3. RFM.
 *
 * @return array{rows:array<int,array{u:int,r_days:int,f:int,m:float,r:int,fs:int,ms:int,seg:string,last:int}>,counts:array<string,int>}
 */
function rfm( array $orders, int $now ): array {
	$paid = paid_statuses();
	$acc  = array();
	foreach ( $orders as $o ) {
		$u = (int) $o['u'];
		if ( $u <= 0 || ! in_array( $o['s'], $paid, true ) ) {
			continue;
		}
		$acc[ $u ]['f']    = ( $acc[ $u ]['f'] ?? 0 ) + 1;
		$acc[ $u ]['m']    = ( $acc[ $u ]['m'] ?? 0 ) + (float) $o['t'];
		$acc[ $u ]['last'] = max( $acc[ $u ]['last'] ?? 0, (int) $o['ts'] );
	}
	$rd = array();
	$fv = array();
	$mv = array();
	foreach ( $acc as $u => $a ) {
		$rd[ $u ] = (int) floor( ( $now - $a['last'] ) / D );
		$fv[ $u ] = $a['f'];
		$mv[ $u ] = $a['m'];
	}
	$rs = score5( $rd, false );
	$fs = score5( $fv, true );
	$ms = score5( $mv, true );
	$rows   = array();
	$counts = array_fill_keys( array_keys( rfm_advice() ), 0 );
	foreach ( $acc as $u => $a ) {
		$seg = rfm_label( $rs[ $u ], $fs[ $u ], $ms[ $u ] );
		$counts[ $seg ] = ( $counts[ $seg ] ?? 0 ) + 1;
		$rows[]         = array(
			'u'      => (int) $u,
			'r_days' => $rd[ $u ],
			'f'      => (int) $a['f'],
			'm'      => (float) $a['m'],
			'r'      => $rs[ $u ],
			'fs'     => $fs[ $u ],
			'ms'     => $ms[ $u ],
			'seg'    => $seg,
			'last'   => (int) $a['last'],
		);
	}
	usort( $rows, fn( $a, $b ) => ( $b['r'] + $b['fs'] + $b['ms'] ) <=> ( $a['r'] + $a['fs'] + $a['ms'] ) ?: $b['m'] <=> $a['m'] );
	return array( 'rows' => $rows, 'counts' => $counts );
}

/**
 * 4. 미입금 — 입금전으로 N일 넘게 서 있는 주문.
 *
 * @return array<int,array{id:int,u:int,days:int,t:float,ts:int}>
 */
function seg_unpaid( array $orders, int $now, int $min_days = 1 ): array {
	$pend = pending_statuses();
	$out  = array();
	foreach ( $orders as $o ) {
		if ( ! in_array( $o['s'], $pend, true ) ) {
			continue;
		}
		$days = (int) floor( ( $now - (int) $o['ts'] ) / D );
		if ( $days < $min_days ) {
			continue;
		}
		$out[] = array( 'id' => (int) $o['id'], 'u' => (int) $o['u'], 'days' => $days, 't' => (float) $o['t'], 'ts' => (int) $o['ts'] );
	}
	usort( $out, fn( $a, $b ) => $a['days'] <=> $b['days'] ?: $b['t'] <=> $a['t'] );
	return $out;
}

/**
 * 같은 연락처는 한 줄만 (쌍둥이 계정 · 같은 사람의 주문 여럿). 앞 줄이 남는다. 연락처가 없는 줄은 그대로.
 *
 * @param array<int,array<string,mixed>> $rows 'phone' 칸이 있는 줄.
 * @return array<int,array<string,mixed>>
 */
function dedupe( array $rows ): array {
	$seen = array();
	$out  = array();
	foreach ( $rows as $r ) {
		$p = phone_norm( (string) ( $r['phone'] ?? '' ) );
		if ( '' !== $p ) {
			if ( isset( $seen[ $p ] ) ) {
				continue;
			}
			$seen[ $p ] = true;
			$r['phone'] = $p;
		}
		$out[] = $r;
	}
	return $out;
}

/** 합칠 때의 우선순위 — 급한 것부터. 필터 `duckhoo_crm_priority`. @return string[] */
function priority(): array {
	return (array) apply_filters( 'duckhoo_crm_priority', array( 'unpaid', 'abandon', 'brand', 'rfm' ) );
}

/**
 * 네 명단을 연락처로 합쳐 **한 사람에 안내 하나**. 같은 사람이 여러 명단에 들면 우선순위가 높은 명단의 줄만 남기고,
 * 나머지 명단 이름은 `also` 에 적는다. 연락처가 없는 줄은 합칠 수 없어 그대로 둔다 (문자도 못 보낸다).
 *
 * @param array<string,array<int,array<string,mixed>>> $lists seg => 줄(phone 칸 포함).
 * @return array<int,array<string,mixed>> 줄에 `seg`(주 명단) · `also`(다른 명단 이름들) 가 붙는다.
 */
function combine( array $lists ): array {
	$order = array_values( array_filter( priority(), fn( $k ) => isset( $lists[ $k ] ) ) );
	foreach ( array_keys( $lists ) as $k ) {
		if ( ! in_array( $k, $order, true ) ) {
			$order[] = $k;
		}
	}
	$by  = array();
	$out = array();
	foreach ( $order as $seg ) {
		foreach ( $lists[ $seg ] as $r ) {
			$p = phone_norm( (string) ( $r['phone'] ?? '' ) );
			if ( '' === $p ) {
				$r['seg'] = $seg; $r['also'] = array(); $out[] = $r;
				continue;
			}
			if ( isset( $by[ $p ] ) ) {
				if ( ! in_array( $seg, $out[ $by[ $p ] ]['also'], true ) && $out[ $by[ $p ] ]['seg'] !== $seg ) {
					$out[ $by[ $p ] ]['also'][] = $seg;
				}
				continue;
			}
			$r['phone'] = $p; $r['seg'] = $seg; $r['also'] = array();
			$by[ $p ] = count( $out );
			$out[]    = $r;
		}
	}
	return $out;
}

/* ── 읽기 (워드프레스 안에서만) ───────────────────────────────────────── */

/**
 * 회원의 저장 장바구니 — uid => {n, qty, total, names}. 비어 있는 것은 뺀다.
 *
 * @return array<int,array{n:int,qty:int,total:float,names:string[]}>
 */
function carts(): array {
	global $wpdb;
	if ( ! isset( $wpdb ) ) {
		return array();
	}
	$key  = '_woocommerce_persistent_cart_' . ( function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 1 );
	$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s", $key ), ARRAY_A ); // phpcs:ignore WordPress.DB
	$out  = array();
	$pids = array();
	foreach ( $rows as $r ) {
		$v = maybe_unserialize( (string) $r['meta_value'] );
		$c = is_array( $v ) && isset( $v['cart'] ) && is_array( $v['cart'] ) ? $v['cart'] : array();
		if ( ! $c ) {
			continue;
		}
		$n = 0; $q = 0; $t = 0.0; $p = array();
		foreach ( $c as $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}
			$n++;
			$q += max( 1, (int) ( $line['quantity'] ?? 1 ) );
			$t += (float) ( $line['line_subtotal'] ?? $line['line_total'] ?? 0 );
			$pid = (int) ( $line['product_id'] ?? 0 );
			if ( $pid ) {
				$p[] = $pid;
				$pids[ $pid ] = true;
			}
		}
		if ( $n ) {
			$out[ (int) $r['user_id'] ] = array( 'n' => $n, 'qty' => $q, 'total' => $t, 'names' => $p );
		}
	}
	$names = product_names( array_keys( $pids ) );
	foreach ( $out as &$c ) {
		$c['names'] = array_values( array_unique( array_map( fn( $pid ) => (string) ( $names[ $pid ] ?? ( '#' . $pid ) ), $c['names'] ) ) );
	}
	unset( $c );
	return $out;
}

/** 상품 번호 => 이름, 한 질의. @return array<int,string> */
function product_names( array $pids ): array {
	global $wpdb;
	$out = array();
	if ( ! $pids || ! isset( $wpdb ) ) {
		return $out;
	}
	$in   = implode( ',', array_map( 'intval', $pids ) );
	$rows = (array) $wpdb->get_results( "SELECT ID, post_title FROM {$wpdb->posts} WHERE ID IN ({$in})", ARRAY_A ); // phpcs:ignore WordPress.DB
	foreach ( $rows as $r ) {
		$out[ (int) $r['ID'] ] = (string) $r['post_title'];
	}
	return $out;
}

/**
 * 회원 연락처 — uid => {name, phone, email}. 이름은 본인확인 이름 → 청구 이름 → 표시 이름, 연락처는 본인확인 번호 → 청구 번호.
 *
 * @param int[] $uids
 * @return array<int,array{name:string,phone:string,email:string}>
 */
function contacts( array $uids ): array {
	global $wpdb;
	$out = array();
	$uids = array_values( array_unique( array_filter( array_map( 'intval', $uids ) ) ) );
	if ( ! $uids || ! isset( $wpdb ) ) {
		return $out;
	}
	foreach ( array_chunk( $uids, 500 ) as $chunk ) {
		$in   = implode( ',', $chunk );
		$rows = (array) $wpdb->get_results( "SELECT ID, display_name, user_email FROM {$wpdb->users} WHERE ID IN ({$in})", ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( $rows as $r ) {
			$out[ (int) $r['ID'] ] = array( 'name' => (string) $r['display_name'], 'phone' => '', 'email' => (string) $r['user_email'] );
		}
		$meta = (array) $wpdb->get_results( "SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id IN ({$in}) AND meta_key IN ('wd_verified_name','first_name','last_name','billing_first_name','billing_last_name','wd_verified_phone','wd_phone','billing_phone')", ARRAY_A ); // phpcs:ignore WordPress.DB
		$m    = array();
		foreach ( $meta as $r ) {
			$m[ (int) $r['user_id'] ][ (string) $r['meta_key'] ] = trim( (string) $r['meta_value'] );
		}
		foreach ( $m as $u => $k ) {
			if ( ! isset( $out[ $u ] ) ) {
				continue;
			}
			$name = $k['wd_verified_name'] ?? '';
			if ( '' === $name ) {
				$name = trim( ( $k['billing_last_name'] ?? $k['last_name'] ?? '' ) . ( $k['billing_first_name'] ?? $k['first_name'] ?? '' ) );
			}
			if ( '' !== $name ) {
				$out[ $u ]['name'] = $name;
			}
			foreach ( array( 'wd_verified_phone', 'wd_phone', 'billing_phone' ) as $pk ) {
				if ( '' !== phone_norm( $k[ $pk ] ?? '' ) ) {
					$out[ $u ]['phone'] = phone_norm( $k[ $pk ] );
					break;
				}
			}
		}
	}
	return $out;
}

/**
 * 주문의 청구 연락처 — 비회원 주문용. oid => {name, phone, email}.
 *
 * @param int[] $oids
 * @return array<int,array{name:string,phone:string,email:string}>
 */
function order_contacts( array $oids ): array {
	global $wpdb;
	$out  = array();
	$oids = array_values( array_unique( array_filter( array_map( 'intval', $oids ) ) ) );
	if ( ! $oids || ! isset( $wpdb ) ) {
		return $out;
	}
	$hpos = function_exists( '\\Duckhoo\\Redesign\\Anatomy\\hpos' ) && \Duckhoo\Redesign\Anatomy\hpos();
	foreach ( array_chunk( $oids, 500 ) as $chunk ) {
		$in = implode( ',', $chunk );
		if ( $hpos ) {
			$rows = (array) $wpdb->get_results( "SELECT order_id AS oid, first_name, last_name, phone, email FROM {$wpdb->prefix}wc_order_addresses WHERE address_type = 'billing' AND order_id IN ({$in})", ARRAY_A ); // phpcs:ignore WordPress.DB
		} else {
			$rows = (array) $wpdb->get_results( "SELECT post_id AS oid, MAX(CASE WHEN meta_key='_billing_first_name' THEN meta_value END) AS first_name, MAX(CASE WHEN meta_key='_billing_last_name' THEN meta_value END) AS last_name, MAX(CASE WHEN meta_key='_billing_phone' THEN meta_value END) AS phone, MAX(CASE WHEN meta_key='_billing_email' THEN meta_value END) AS email FROM {$wpdb->postmeta} WHERE post_id IN ({$in}) AND meta_key IN ('_billing_first_name','_billing_last_name','_billing_phone','_billing_email') GROUP BY post_id", ARRAY_A ); // phpcs:ignore WordPress.DB
		}
		foreach ( $rows as $r ) {
			$out[ (int) $r['oid'] ] = array(
				'name'  => trim( (string) ( $r['last_name'] ?? '' ) . (string) ( $r['first_name'] ?? '' ) ),
				'phone' => phone_norm( (string) ( $r['phone'] ?? '' ) ),
				'email' => (string) ( $r['email'] ?? '' ),
			);
		}
	}
	return $out;
}

/** 화면 · CSV 에 쓰는 줄로 — 세그먼트마다 [이름, 연락처, 기준, 금액, 날짜, 메모]. */
function build( string $seg, array $opt, int $now ): array {
	$orders = function_exists( '\\Duckhoo\\Redesign\\Anatomy\\orders' ) ? \Duckhoo\Redesign\Anatomy\orders() : array();
	$rows   = array();
	$meta   = array();
	if ( 'abandon' === $seg ) {
		$list = seg_abandon( carts(), $orders, $now, (int) $opt['days'] );
		$ct   = contacts( array_column( $list, 'u' ) );
		foreach ( $list as $r ) {
			$c = $ct[ $r['u'] ] ?? array( 'name' => '#' . $r['u'], 'phone' => '', 'email' => '' );
			$rows[] = array( 'u' => $r['u'], 'name' => $c['name'], 'phone' => $c['phone'], 'why' => $r['n'] . '종 ' . $r['qty'] . '개 담아 둠', 'amount' => $r['total'], 'when' => $r['last'], 'note' => implode(' · ', array_slice( $r['names'], 0, 3 ) ) . ( $r['paid_n'] ? ' / 전에 ' . $r['paid_n'] . '번 삼' : ' / 아직 산 적 없음' ) );
		}
		$meta = array( 'carts' => count( $list ) );
	} elseif ( 'brand' === $seg ) {
		$paid = paid_statuses();
		$ids  = array();
		$since = $now - (int) $opt['days'] * D;
		foreach ( $orders as $o ) {
			if ( $o['u'] > 0 && $o['ts'] >= $since && in_array( $o['s'], $paid, true ) ) {
				$ids[] = $o['id'];
			}
		}
		$items = function_exists( '\\Duckhoo\\Redesign\\Anatomy\\items' ) ? \Duckhoo\Redesign\Anatomy\items( $ids ) : array();
		$list  = seg_brand( $orders, $items, brands(), $now, (int) $opt['days'] );
		$ct    = contacts( array_column( $list, 'u' ) );
		foreach ( $list as $r ) {
			$c = $ct[ $r['u'] ] ?? array( 'name' => '#' . $r['u'], 'phone' => '', 'email' => '' );
			$rows[] = array( 'u' => $r['u'], 'name' => $c['name'], 'phone' => $c['phone'], 'why' => implode( ' · ', $r['brands'] ) . ' ' . $r['n'] . '번 · ' . $r['bottles'] . '병', 'amount' => $r['total'], 'when' => $r['last'], 'note' => '자주 산 것: ' . $r['fav'] );
		}
	} elseif ( 'rfm' === $seg ) {
		$x    = rfm( $orders, $now );
		$ct   = contacts( array_column( $x['rows'], 'u' ) );
		$only = (string) ( $opt['only'] ?? '' );
		foreach ( $x['rows'] as $r ) {
			if ( '' !== $only && $r['seg'] !== $only ) {
				continue;
			}
			$c = $ct[ $r['u'] ] ?? array( 'name' => '#' . $r['u'], 'phone' => '', 'email' => '' );
			$rows[] = array( 'u' => $r['u'], 'name' => $c['name'], 'phone' => $c['phone'], 'why' => $r['seg'] . ' (R' . $r['r'] . ' F' . $r['fs'] . ' M' . $r['ms'] . ')', 'amount' => $r['m'], 'when' => $r['last'], 'note' => $r['f'] . '번 · 마지막 ' . $r['r_days'] . '일 전' );
		}
		$meta = array( 'counts' => $x['counts'], 'total' => count( $x['rows'] ) );
	} elseif ( 'unpaid' === $seg ) {
		$list = seg_unpaid( $orders, $now, (int) $opt['days'] );
		$ct   = contacts( array_column( $list, 'u' ) );
		$oc   = order_contacts( array_column( $list, 'id' ) );
		foreach ( $list as $r ) {
			$c = $ct[ $r['u'] ] ?? $oc[ $r['id'] ] ?? array( 'name' => '', 'phone' => '', 'email' => '' );
			if ( '' === $c['phone'] && isset( $oc[ $r['id'] ] ) ) {
				$c['phone'] = $oc[ $r['id'] ]['phone'];
				if ( '' === $c['name'] ) { $c['name'] = $oc[ $r['id'] ]['name']; }
			}
			$rows[] = array( 'u' => $r['u'], 'name' => $c['name'], 'phone' => $c['phone'], 'why' => '입금전 ' . $r['days'] . '일째 · 주문 #' . $r['id'], 'amount' => $r['t'], 'when' => $r['ts'], 'note' => $r['u'] ? '' : '비회원' );
		}
	}
	return array( 'rows' => $rows, 'meta' => $meta, 'at' => $now );
}

function opts( string $seg ): array {
	$d = array( 'abandon' => 7, 'brand' => 180, 'rfm' => 0, 'unpaid' => 1 );
	$days = isset( $_GET['days'] ) ? max( 0, min( 730, (int) $_GET['days'] ) ) : $d[ $seg ]; // phpcs:ignore WordPress.Security.NonceVerification
	$only = isset( $_GET['only'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['only'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	return array( 'days' => $days, 'only' => $only );
}

function data( string $seg, array $opt, bool $fresh = false ): array {
	$key = CACHE . $seg . '_' . (int) $opt['days'];
	if ( ! $fresh ) {
		$c = get_transient( $key );
		if ( is_array( $c ) ) {
			return $c;
		}
	}
	$now = (int) current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
	$d   = build( $seg, array( 'days' => $opt['days'], 'only' => '' ), $now );
	set_transient( $key, $d, 10 * MINUTE_IN_SECONDS );
	return $d;
}

/** 화면 줄에서 RFM 갈래 하나만 남긴다 (캐시는 전체를 들고 있다). */
function filter_only( array $rows, string $only ): array {
	if ( '' === $only ) {
		return $rows;
	}
	return array_values( array_filter( $rows, fn( $r ) => str_starts_with( (string) $r['why'], $only . ' ' ) ) );
}

/* ── 화면 ─────────────────────────────────────────────────────────────── */

function segs(): array {
	return array(
		'abandon' => '장바구니 담고 나간 손님',
		'brand'   => '노보 · 디오리퀴드 구매 손님',
		'rfm'     => 'RFM',
		'unpaid'  => '미입금 고객',
		'all'     => '한 사람에 한 통',
	);
}

/** 합치기 탭에 넣을 수 있는 조각 — 명단 넷 + RFM 갈래. 기본은 미입금 · 담고 나간 · 노보/디오 · RFM 이탈 위험 · 관심 필요. */
function parts(): array {
	$p = array( 'unpaid' => '미입금 고객', 'abandon' => '장바구니 담고 나간 손님', 'brand' => '노보 · 디오리퀴드 구매 손님' );
	foreach ( rfm_advice() as $k => $_ ) {
		$p[ 'rfm:' . $k ] = 'RFM ' . $k;
	}
	return $p;
}
function default_parts(): array {
	return array( 'unpaid', 'abandon', 'brand', 'rfm:이탈 위험', 'rfm:관심 필요' );
}
function chosen_parts(): array {
	if ( ! isset( $_GET['inc'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return default_parts();
	}
	$want = array_map( fn( $v ) => sanitize_text_field( wp_unslash( (string) $v ) ), (array) $_GET['inc'] ); // phpcs:ignore WordPress.Security.NonceVerification
	return array_values( array_intersect( $want, array_keys( parts() ) ) );
}

/** 합치기 탭의 줄 — 고른 조각마다 캐시된 명단을 읽어 combine(). RFM 갈래는 `rfm:갈래` 로 들어오고 seg 는 `rfm` 하나로 합친다. */
function combined( array $inc, bool $fresh = false ): array {
	$lists = array();
	$at    = 0;
	foreach ( array( 'unpaid', 'abandon', 'brand' ) as $k ) {
		if ( in_array( $k, $inc, true ) ) {
			$d = data( $k, opts( $k ), $fresh );
			$lists[ $k ] = array_map( fn( $r ) => $r + array( 'part' => $k ), dedupe( $d['rows'] ) );
			$at = max( $at, (int) $d['at'] );
		}
	}
	$labels = array_values( array_map( fn( $v ) => substr( $v, 4 ), array_filter( $inc, fn( $v ) => str_starts_with( $v, 'rfm:' ) ) ) );
	if ( $labels ) {
		$d    = data( 'rfm', opts( 'rfm' ), $fresh );
		$rows = array();
		foreach ( $labels as $l ) {
			$rows = array_merge( $rows, array_map( fn( $r ) => $r + array( 'part' => 'rfm:' . $l ), filter_only( $d['rows'], $l ) ) );
		}
		$lists['rfm'] = dedupe( $rows );
		$at = max( $at, (int) $d['at'] );
	}
	return array( 'rows' => combine( $lists ), 'at' => $at, 'n' => array_map( 'count', $lists ) );
}

function screen(): void {
	if ( ! may() ) {
		wp_die( '권한이 없습니다.' );
	}
	$seg = isset( $_GET['seg'] ) && isset( segs()[ $_GET['seg'] ] ) ? (string) $_GET['seg'] : 'abandon'; // phpcs:ignore WordPress.Security.NonceVerification
	$opt = opts( $seg );
	$fresh = isset( $_GET['dhr_fresh'] ) && check_admin_referer( 'dhr-crm-fresh' ); // phpcs:ignore WordPress.Security.NonceVerification
	echo '<div class="wrap dhr-sl">';
	if ( function_exists( '\\Duckhoo\\Redesign\\Sales\\styles' ) ) {
		\Duckhoo\Redesign\Sales\styles();
	}
	echo '<style>.dhr-crm-tabs{display:flex;flex-wrap:wrap;gap:6px;margin:10px 0 16px}.dhr-crm-tabs a{display:inline-block;padding:8px 14px;border-radius:999px;background:#fff;border:1px solid #dcdcde;color:#1d2327;text-decoration:none;font-weight:600}.dhr-crm-tabs a.on{background:#1d2327;color:#fff;border-color:#1d2327}.dhr-crm-bar{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin:0 0 14px}.dhr-crm-bar form{display:inline-flex;gap:6px;align-items:center}.dhr-crm-bar input[type=number]{width:70px}.dhr-crm-segs{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 14px}.dhr-crm-segs a{padding:6px 12px;border-radius:999px;border:1px solid #dcdcde;background:#fff;text-decoration:none;color:#1d2327}.dhr-crm-segs a.on{background:#1a5cff;border-color:#1a5cff;color:#fff}.dhr-crm-adv{margin:0 0 12px;padding:10px 14px;background:#f6f7f7;border-radius:10px;font-size:13px;line-height:1.6}</style>';
	echo '<h1 class="dhr-sl-h1">고객 세그먼트</h1>';
	echo '<p class="dhr-sl-note">문자 · 쿠폰을 보낼 명단을 실제 주문 · 장바구니에서 뽑습니다. 읽기만 합니다 — 보내는 것은 따로. CSV 는 이름 · 연락처 · 기준 · 금액 · 날짜 · 메모 여섯 칸이고 같은 연락처는 한 줄만 남습니다. 같은 사람이 여러 명단에 들면 「한 사람에 한 통」 탭으로 합쳐 보내세요. <b>SMS 수신에 동의한 사람만</b> 기본으로 보이고 문자 사이트 양식(.xls)에도 그 사람들만 들어갑니다. 10분 캐시.</p>';
	echo '<div class="dhr-crm-tabs">';
	foreach ( segs() as $k => $label ) {
		echo '<a class="' . ( $k === $seg ? 'on' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=' . SLUG . '&seg=' . $k ) ) . '">' . esc_html( $label ) . '</a>';
	}
	echo '</div>';

	try {
		$inc = 'all' === $seg ? chosen_parts() : array();
		$d   = 'all' === $seg ? combined( $inc, $fresh ) : data( $seg, $opt, $fresh );
	} catch ( \Throwable $e ) {
		echo '<div class="notice notice-error"><p>읽다 멈췄습니다: ' . esc_html( $e->getMessage() ) . ' (' . esc_html( basename( $e->getFile() ) . ':' . $e->getLine() ) . ')</p></div></div>';
		return;
	}
	$rows    = export_rows( $seg, $opt, $inc, false ); // 캐시는 바로 위 data()/combined() 가 채웠다
	$smsmode = isset( $_GET['sms'] ) && 'all' === $_GET['sms'] ? 'all' : 'yes'; // phpcs:ignore WordPress.Security.NonceVerification
	$smsc    = sms_counts( $rows );
	$all_n   = count( $rows );
	if ( 'yes' === $smsmode ) {
		$rows = array_values( array_filter( $rows, fn( $r ) => 'yes' === $r['sms'] ) );
	}
	[ $rows, $skipped ] = apply_skip( $rows ); // 최근 21일 안에 양식으로 내려받은(= 문자 받은) 사람은 뺀다
	[ $rows, $gone ]    = apply_exclude( $rows, $seg ); // 제외 명단 (미입금은 안 뺀다)
	$w    = fn( $n ) => number_format_i18n( (int) round( (float) $n ) );
	$dt   = fn( $ts ) => $ts ? wp_date( 'Y.m.d', (int) $ts ) : '—';
	$name = fn( string $k ) => segs()[ $k ] ?? $k;

	$explain = array(
		'abandon' => '회원의 저장 장바구니에 상품이 있는데 최근 <b>%d일</b> 안에 살아 있는 주문이 없는 사람. 장바구니에는 담은 시각이 없어 「최근 주문이 없다」로 가릅니다. 담은 것이 오래된 것일 수 있으니 문구는 「담아 두신 상품 아직 있어요」 정도로.',
		'brand'   => '최근 <b>%d일</b> 돈 들어온 주문에 노보 · 디오리퀴드 상품이 든 회원. 같은 브랜드 다른 맛 · 10+1 · 5+5 묶음 안내가 맞는 명단. 브랜드는 필터 duckhoo_crm_brands.',
		'rfm'     => '돈 들어온 주문 전체로 회원마다 R(마지막 구매 뒤 며칠 — 가까울수록 5점) · F(주문 수) · M(누적 금액)을 5분위로 매깁니다. 갈래를 누르면 그 명단만 남습니다.',
		'unpaid'  => '입금전으로 <b>%d일</b> 넘게 서 있는 주문. 입금 안내 문자 한 통이 가장 돈이 되는 명단 — 입금자명을 주문자명과 같게 보내 달라는 말을 꼭 넣습니다. 비회원 주문은 주문서의 연락처.',
		'all'     => '네 명단을 연락처로 합쳐 <b>한 사람에 안내 하나</b>. 여러 명단에 든 사람은 급한 순서(미입금 → 담고 나간 → 노보 · 디오 → RFM)로 하나만 남기고, 다른 명단 이름은 메모에 적습니다. 어느 조각을 넣을지 아래에서 고릅니다 — RFM 전체를 넣으면 산 적 있는 회원이 전부 들어오니 갈래를 고르세요.',
	);
	$incq = 'all' === $seg ? '&' . http_build_query( array( 'inc' => $inc ) ) : '';
	echo '<div class="dhr-crm-bar"><form method="get"><input type="hidden" name="page" value="' . esc_attr( SLUG ) . '"><input type="hidden" name="seg" value="' . esc_attr( $seg ) . '">';
	if ( 'all' === $seg ) {
		foreach ( parts() as $k => $label ) {
			echo '<label><input type="checkbox" name="inc[]" value="' . esc_attr( $k ) . '"' . ( in_array( $k, $inc, true ) ? ' checked' : '' ) . '> ' . esc_html( $label ) . '</label> ';
		}
		echo '<button class="button">다시 보기</button>';
	} elseif ( 'rfm' !== $seg ) {
		echo '<label>기준 일수 <input type="number" name="days" min="0" max="730" value="' . (int) $opt['days'] . '"></label> <button class="button">다시 보기</button>';
	}
	echo '</form>';
	echo '<a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . SLUG . '&seg=' . $seg . '&days=' . (int) $opt['days'] . $incq . '&dhr_fresh=1' ), 'dhr-crm-fresh' ) ) . '">지금 다시 읽기</a>';
	echo '<a class="button button-primary" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=dhr_crm_csv&seg=' . $seg . '&days=' . (int) $opt['days'] . '&only=' . rawurlencode( $opt['only'] ) . $incq . ( 'all' === $smsmode ? '&sms=all' : '' ) ), 'dhr-crm-csv' ) ) . '">CSV 내려받기 (' . count( $rows ) . '명)</a>';
	echo '<span class="dhr-sl-note" style="margin:0">읽은 시각 ' . esc_html( wp_date( 'm.d H:i', (int) $d['at'] ) ) . '</span></div>';
	echo '<p class="dhr-sl-note">' . wp_kses( sprintf( $explain[ $seg ], (int) $opt['days'] ), array( 'b' => array() ) ) . '</p>';

	if ( 'rfm' === $seg ) {
		$adv = rfm_advice();
		echo '<div class="dhr-crm-segs"><a class="' . ( '' === $opt['only'] ? 'on' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=' . SLUG . '&seg=rfm' ) ) . '">전체 ' . (int) ( $d['meta']['total'] ?? 0 ) . '</a>';
		foreach ( $adv as $k => $_ ) {
			$n = (int) ( $d['meta']['counts'][ $k ] ?? 0 );
			echo '<a class="' . ( $k === $opt['only'] ? 'on' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=' . SLUG . '&seg=rfm&only=' . rawurlencode( $k ) ) ) . '">' . esc_html( $k ) . ' ' . $n . '</a>';
		}
		echo '</div>';
		if ( '' !== $opt['only'] && isset( $adv[ $opt['only'] ] ) ) {
			echo '<div class="dhr-crm-adv"><b>' . esc_html( $opt['only'] ) . '</b> — ' . esc_html( $adv[ $opt['only'] ] ) . '</div>';
		} else {
			echo '<div class="dhr-crm-adv">';
			foreach ( $adv as $k => $v ) {
				echo '<b>' . esc_html( $k ) . '</b> ' . esc_html( $v ) . '<br>';
			}
			echo '</div>';
		}
	}

	$back  = admin_url( 'admin.php?page=' . SLUG . '&seg=' . $seg . '&days=' . (int) $opt['days'] . ( $opt['only'] ? '&only=' . rawurlencode( $opt['only'] ) : '' ) . $incq . ( 'all' === $smsmode ? '&sms=all' : '' ) . ( skip_on() ? '' : '&skip=0' ) );
	sms_saved_notice();
	sent_box( $back, $skipped );
	exclude_box( $back, $gone, $seg );
	if ( 'all' === $seg ) {
		plan_box( $back, $inc );
	}
	sms_box( $back );
	echo '<div class="dhr-crm-adv">SMS 수신 동의: <b>동의 ' . esc_html( $w( $smsc['yes'] ) ) . '명</b> · 거부 ' . esc_html( $w( $smsc['no'] ) ) . '명 · 기록 없음 ' . esc_html( $w( $smsc['unknown'] ) ) . '명 (명단 ' . esc_html( $w( $all_n ) ) . '명). '
		. ( 'yes' === $smsmode ? '지금은 <b>동의한 사람만</b> 보입니다. <a href="' . esc_url( add_query_arg( 'sms', 'all', $back ) ) . '">전부 보기</a>' : '지금은 <b>전부</b> 보입니다 (거부 · 기록 없음 포함). <a href="' . esc_url( remove_query_arg( 'sms', $back ) ) . '">동의한 사람만</a>' )
		. ' · 문자 사이트 양식 파일에는 <b>동의한 사람만</b> 들어갑니다.' . ( sms_source_ok() ? '' : ' <b>동의 자료가 아직 없습니다</b> — 위 상자에서 키를 고르거나 명단을 붙여 넣으세요.' ) . '</div>';
	echo '<form method="get" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="dhr-crm-bar" style="margin:0 0 14px">';
	echo '<input type="hidden" name="action" value="dhr_crm_xls"><input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( 'dhr-crm-xls' ) ) . '"><input type="hidden" name="seg" value="' . esc_attr( $seg ) . '"><input type="hidden" name="days" value="' . (int) $opt['days'] . '"><input type="hidden" name="only" value="' . esc_attr( $opt['only'] ) . '">' . ( skip_on() ? '' : '<input type="hidden" name="skip" value="0">' ) . ( 'all' === $smsmode ? '<input type="hidden" name="sms" value="all">' : '' );
	foreach ( $inc as $k ) {
		echo '<input type="hidden" name="inc[]" value="' . esc_attr( $k ) . '">';
	}
	if ( 'all' !== $seg ) {
		echo '<label>그룹명 <input type="text" name="grp" value="' . esc_attr( $rows ? (string) $rows[0]['group'] : ( segs()[ $seg ] . ( 'rfm' === $seg && $opt['only'] ? ' ' . $opt['only'] : '' ) ) ) . '" style="width:220px"></label>';
	} else {
		echo '<span class="dhr-sl-note">그룹명 = 그 사람의 주 명단 (한 파일에 여러 그룹)</span>';
	}
	$file_n = count( array_filter( $rows, fn( $r ) => 'yes' === $r['sms'] ) );
	echo '<button class="button button-primary">문자 사이트 양식 (.xls) 내려받기 — 동의 ' . esc_html( $w( $file_n ) ) . '명</button>';
	echo '<span class="dhr-sl-note" style="margin:0">NO · 그룹명 · 이름 · 전화번호 · 메모 다섯 칸, 올려 주신 tothemoon 양식 그대로 (EUC-KR)</span></form>';
	if ( ! $rows ) {
		echo '<p class="dhr-sl-empty">' . ( 'yes' === $smsmode && $all_n ? 'SMS 수신에 동의한 사람이 없습니다 (명단 ' . esc_html( $w( $all_n ) ) . '명).' : '해당하는 사람이 없습니다.' ) . '</p></div>';
		return;
	}
	if ( 'all' === $seg ) {
		$overlap = count( array_filter( $rows, fn( $r ) => ! empty( $r['also'] ) ) );
		$byseg   = array();
		foreach ( $rows as $r ) { $byseg[ $r['seg'] ] = ( $byseg[ $r['seg'] ] ?? 0 ) + 1; }
		echo '<div class="dhr-crm-adv">명단을 따로 보내면 ' . esc_html( $w( array_sum( $d['n'] ) ) ) . '통, 합치면 <b>' . esc_html( $w( count( $rows ) ) ) . '통</b> — 겹친 사람 ' . esc_html( $w( $overlap ) ) . '명 (한 사람에 한 보상). 보낼 안내: ';
		$rs = reward_summary( $rows );
		foreach ( priority() as $k ) {
			foreach ( $rs as $pk => $v ) {
				if ( $pk === $k || str_starts_with( $pk, $k . ':' ) ) {
					echo '<b>' . esc_html( str_starts_with( $pk, 'rfm:' ) ? 'RFM ' . substr( $pk, 4 ) : $name( $k ) ) . '</b> ' . esc_html( $w( $v['n'] ) ) . '명' . ( '' !== $v['reward'] ? ' → ' . esc_html( $v['reward'] ) : ' <span class="dhr-sl-note" style="margin:0">(보상 라벨 없음)</span>' ) . ' · ';
				}
			}
		}
		echo '</div>';
	}
	$sum = array_sum( array_column( $rows, 'amount' ) );
	echo '<div class="dhr-sl-cards">';
	\Duckhoo\Redesign\Sales\card( '명단', $w( count( $rows ) ) . '명', '연락처 있음 ' . $w( count( array_filter( $rows, fn( $r ) => '' !== $r['phone'] ) ) ) . '명' );
	\Duckhoo\Redesign\Sales\card( 'abandon' === $seg ? '담긴 금액 합계' : ( 'unpaid' === $seg ? '미입금 합계' : '누적 금액 합계' ), $w( $sum ) . '원', '1인 평균 ' . $w( $sum / max( 1, count( $rows ) ) ) . '원' );
	echo '</div>';
	echo '<div class="dhr-sl-scroll"><table class="widefat striped"><thead><tr><th>이름</th><th>연락처</th><th>SMS</th><th>그룹명 · 보상</th><th>기준</th><th>금액</th><th>날짜</th><th>메모</th></tr></thead><tbody>';
	$smsw = array( 'yes' => '✓ 동의', 'no' => '✗ 거부', '' => '? 기록 없음' );
	foreach ( array_slice( $rows, 0, 400 ) as $r ) {
		echo '<tr><td>' . esc_html( $r['name'] ) . '</td><td>' . esc_html( $r['phone'] ?: '—' ) . '</td><td>' . esc_html( $smsw[ $r['sms'] ] ?? '?' ) . '</td><td>' . esc_html( (string) ( $r['group'] ?? '' ) ) . '</td><td>' . esc_html( $r['why'] ) . '</td><td class="dhr-sl-num">' . esc_html( $w( $r['amount'] ) ) . '</td><td>' . esc_html( $dt( $r['when'] ) ) . '</td><td>' . esc_html( $r['note'] ) . '</td></tr>';
	}
	echo '</tbody></table></div>';
	if ( count( $rows ) > 400 ) {
		echo '<p class="dhr-sl-note">화면에는 400명까지. 전부는 CSV 에.</p>';
	}
	echo '</div>';
}

/** CSV — admin-post. 이름,연락처,기준,금액,날짜,메모. BOM 을 앞에 둬 엑셀이 한글을 바로 읽는다. */
function csv(): void {
	if ( ! may() || ! check_admin_referer( 'dhr-crm-csv' ) ) {
		wp_die( '권한이 없습니다.' );
	}
	$seg  = isset( $_GET['seg'] ) && isset( segs()[ $_GET['seg'] ] ) ? (string) $_GET['seg'] : 'abandon';
	$opt  = opts( $seg );
	$rows = export_rows( $seg, $opt, 'all' === $seg ? chosen_parts() : array() );
	if ( ! ( isset( $_GET['sms'] ) && 'all' === $_GET['sms'] ) ) {
		$rows = array_values( array_filter( $rows, fn( $r ) => 'yes' === $r['sms'] ) );
	}
	[ $rows ] = apply_skip( $rows );
	[ $rows ] = apply_exclude( $rows, $seg );
	$smsw = array( 'yes' => '동의', 'no' => '거부', '' => '기록 없음' );
	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="duckhoo-' . $seg . ( $opt['only'] ? '-' . rawurlencode( $opt['only'] ) : '' ) . '-' . wp_date( 'Ymd' ) . '.csv"' );
	echo "\xEF\xBB\xBF";
	$o = fopen( 'php://output', 'w' );
	fputcsv( $o, array( '이름', '연락처', 'SMS동의', '그룹명', '기준', '금액', '날짜', '메모' ) );
	foreach ( $rows as $r ) {
		fputcsv( $o, array( $r['name'], $r['phone'], $smsw[ $r['sms'] ] ?? '', $r['group'] ?? '', $r['why'], (int) round( (float) $r['amount'] ), $r['when'] ? wp_date( 'Y-m-d', (int) $r['when'] ) : '', $r['note'] ) );
	}
	fclose( $o );
	exit;
}
add_action( 'admin_post_dhr_crm_csv', __NAMESPACE__ . '\\csv' );
