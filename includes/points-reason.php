<?php
/**
 * 적립금 한 번에 넣기 → 「사유로 찾기 · 거두기」.
 *
 * 이 화면 밖(키플 고객관리)에서 손으로 넣은 적립금도 원장에는 사유가 남는다.
 * 사유 앞부분(예: 「261008 이벤트 적립금」)으로 원장을 찾아 손님마다
 * 넣은 금액 · 이미 거둔 금액 · 그 뒤 주문에서 쓴 적립금 · 지금 잔액을 보여 주고,
 * 「거두기」를 눌렀을 때만 **안 쓴 만큼**을 뺀다 (사장님 2026-10-22).
 *
 * - 거둘 금액 = min( 넣음 − 이미 거둠 − 그 뒤 쓴 적립금, 지금 잔액 ), 0 아래 없음
 * - 다른 사유로 그 뒤 적립금이 빠진 기록(회수 · 차감 · 소멸 …)이 있는 손님은
 *   손으로 이미 거뒀을 수 있어 기본으로 건너뛴다 (체크하면 같이 거둔다)
 * - 빼는 길은 `Points\grant()` 하나 (잔액 + 원장). 원장 사유는 「사유 사용기간 종료」 —
 *   그 사유도 같은 앞부분으로 시작하므로 다시 찾으면 「이미 거둠」으로 잡혀 두 번 안 뺀다
 *
 * @package Duckhoo\Redesign
 */

declare( strict_types = 1 );

namespace Duckhoo\Redesign\Points\Admin;

defined( 'ABSPATH' ) || exit;

/** 사유에서 괄호 안 · 끝 공백을 떼어 거둘 때 붙일 이름을 만든다. 순수. */
function reason_base( string $reason ): string {
	$r = (string) preg_replace( '/\s*[\(（][^\)）]*[\)）]\s*/u', ' ', $reason );
	return trim( (string) preg_replace( '/\s+/u', ' ', $r ) );
}

/** 거둔 줄로 보이는 사유인가 (괄호 안 글은 안 본다 — 「(10/15 미사용분 회수)」 같은 넣을 때 메모). 순수. */
function looks_taken( string $reason ): bool {
	return (bool) preg_match( '/회수|사용기간\s*종료|소멸|차감|만료|취소/u', reason_base( $reason ) );
}

/**
 * 손님마다 거둘 금액을 셈한다. 순수.
 *
 * @param array<int,array{user:int,amount:int,reason:string,ts:int}> $rows   사유가 앞부분으로 시작하는 원장 줄.
 * @param array<int,array<int,array{ts:int,used:int}>>              $orders 회원 => 적립금을 쓴 주문.
 * @param array<int,int>                                            $bal    회원 => 지금 잔액.
 * @param array<int,array<int,int>>                                 $other  회원 => 그 사유 말고 적립금이 빠진 줄의 시각들.
 * @return array<int,array{given:int,taken:int,at:int,used:int,bal:int,take:int,other:bool}>
 */
function reason_plan( array $rows, array $orders, array $bal, array $other ): array {
	$signed = false;
	foreach ( $rows as $r ) {
		if ( (int) $r['amount'] < 0 ) {
			$signed = true;
			break;
		}
	}
	$out = array();
	foreach ( $rows as $r ) {
		$u   = (int) $r['user'];
		$amt = (int) $r['amount'];
		$neg = $signed ? $amt < 0 : looks_taken( (string) $r['reason'] );
		$o   = $out[ $u ] ?? array( 'given' => 0, 'taken' => 0, 'at' => 0, 'used' => 0, 'bal' => 0, 'take' => 0, 'other' => false );
		if ( $neg ) {
			$o['taken'] += abs( $amt );
		} else {
			$o['given'] += abs( $amt );
			$ts          = (int) $r['ts'];
			$o['at']     = 0 === $o['at'] ? $ts : min( $o['at'], $ts );
		}
		$out[ $u ] = $o;
	}
	foreach ( $out as $u => &$o ) {
		foreach ( (array) ( $orders[ $u ] ?? array() ) as $od ) {
			if ( $o['at'] > 0 && (int) $od['ts'] >= $o['at'] ) {
				$o['used'] += (int) $od['used'];
			}
		}
		foreach ( (array) ( $other[ $u ] ?? array() ) as $ts ) {
			if ( $o['at'] > 0 && (int) $ts >= $o['at'] ) {
				$o['other'] = true;
			}
		}
		$o['bal']  = max( 0, (int) ( $bal[ $u ] ?? 0 ) );
		$o['take'] = max( 0, min( $o['given'] - $o['taken'] - $o['used'], $o['bal'] ) );
	}
	unset( $o );
	return $out;
}

/** 원장 칸 이름 + 시각 칸. */
function reason_cols(): ?array {
	global $wpdb;
	$table = log_table();
	if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) { // phpcs:ignore WordPress.DB
		return null;
	}
	$cols = array_map( 'strval', (array) $wpdb->get_col( 'SHOW COLUMNS FROM `' . esc_sql( $table ) . '`' ) ); // phpcs:ignore WordPress.DB
	$map  = guess_cols( $cols );
	if ( ! $map ) {
		return array( 'cols' => $cols, 'map' => null );
	}
	$map['ts'] = '';
	foreach ( array( 'created_at', 'created', 'date', 'datetime', 'reg_date', 'regdate', 'logged_at', 'time', 'timestamp', 'date_created' ) as $n ) {
		foreach ( $cols as $c ) {
			if ( strtolower( $c ) === $n ) {
				$map['ts'] = $c;
				break 2;
			}
		}
	}
	return array( 'cols' => $cols, 'map' => $map );
}

/** 원장 시각 글자 → timestamp (사이트 시간대로 본다). */
function reason_ts( $v ): int {
	if ( is_numeric( $v ) && (int) $v > 1000000000 ) {
		return (int) $v;
	}
	try {
		$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'Asia/Seoul' );
		return ( new \DateTime( (string) $v, $tz ) )->getTimestamp();
	} catch ( \Throwable $e ) {
		return 0;
	}
}

/** 회원들의 주문 중 적립금을 쓴 것 (취소 · 환불 · 실패 · 임시글 제외). */
function reason_orders( array $uids, int $since ): array {
	global $wpdb;
	$uids = array_values( array_filter( array_map( 'intval', $uids ) ) );
	$out  = array();
	if ( ! $uids ) {
		return $out;
	}
	$gmt  = gmdate( 'Y-m-d H:i:s', $since - DAY_IN_SECONDS );
	$hpos = function_exists( '\\Duckhoo\\Redesign\\Anatomy\\hpos' ) && \Duckhoo\Redesign\Anatomy\hpos();
	$dead = "('wc-cancelled','wc-refunded','wc-failed','wc-checkout-draft','trash')";
	foreach ( array_chunk( $uids, 500 ) as $chunk ) {
		$in  = implode( ',', $chunk );
		$sql = $hpos
			? $wpdb->prepare( "SELECT o.customer_id AS u, o.date_created_gmt AS d, m.meta_value AS v FROM {$wpdb->prefix}wc_orders o JOIN {$wpdb->prefix}wc_orders_meta m ON m.order_id = o.id AND m.meta_key = '_wd_point_discount' WHERE o.type = 'shop_order' AND o.status NOT IN {$dead} AND o.customer_id IN ({$in}) AND o.date_created_gmt >= %s", $gmt )
			: $wpdb->prepare( "SELECT c.meta_value AS u, p.post_date_gmt AS d, m.meta_value AS v FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} c ON c.post_id = p.ID AND c.meta_key = '_customer_user' JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wd_point_discount' WHERE p.post_type = 'shop_order' AND p.post_status NOT IN {$dead} AND c.meta_value IN ({$in}) AND p.post_date_gmt >= %s", $gmt );
		foreach ( (array) $wpdb->get_results( $sql, ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$v = (int) round( abs( (float) $r['v'] ) );
			if ( $v > 0 ) {
				$out[ (int) $r['u'] ][] = array( 'ts' => (int) strtotime( (string) $r['d'] . ' UTC' ), 'used' => $v );
			}
		}
	}
	return $out;
}

/**
 * 사유 앞부분으로 원장을 읽어 셈까지.
 *
 * @return array{ok:bool,msg:string,plan:array,cols:array}
 */
function reason_scan( string $prefix ): array {
	global $wpdb;
	$prefix = trim( $prefix );
	$res    = array( 'ok' => false, 'msg' => '', 'plan' => array(), 'cols' => array() );
	if ( mb_strlen( $prefix ) < 3 ) {
		$res['msg'] = '사유를 세 글자 이상 적어 주세요.';
		return $res;
	}
	$c = reason_cols();
	if ( ! $c ) {
		$res['msg'] = '적립금 원장 표를 찾지 못했습니다.';
		return $res;
	}
	$res['cols'] = $c['cols'];
	$map         = $c['map'];
	if ( ! $map ) {
		$res['msg'] = '원장 칸을 알아보지 못해 아무것도 하지 않았습니다. 칸: ' . implode( ', ', $c['cols'] );
		return $res;
	}
	$table = log_table();
	$sel   = '`' . esc_sql( $map['user'] ) . '` AS user, `' . esc_sql( $map['amount'] ) . '` AS amount, `' . esc_sql( $map['reason'] ) . '` AS reason' . ( '' !== $map['ts'] ? ', `' . esc_sql( $map['ts'] ) . '` AS ts' : ', 0 AS ts' );
	$raw   = (array) $wpdb->get_results( $wpdb->prepare( "SELECT {$sel} FROM `" . esc_sql( $table ) . '` WHERE `' . esc_sql( $map['reason'] ) . '` LIKE %s', $wpdb->esc_like( $prefix ) . '%' ), ARRAY_A ); // phpcs:ignore WordPress.DB
	if ( ! $raw ) {
		$res['ok']  = true;
		$res['msg'] = '그 사유로 시작하는 원장 줄이 없습니다.';
		return $res;
	}
	$rows = array();
	$uids = array();
	foreach ( $raw as $r ) {
		$rows[] = array( 'user' => (int) $r['user'], 'amount' => (int) round( (float) $r['amount'] ), 'reason' => (string) $r['reason'], 'ts' => reason_ts( $r['ts'] ) );
		$uids[ (int) $r['user'] ] = true;
	}
	$uids  = array_keys( $uids );
	$signed_hint = (bool) array_filter( $rows, fn( $r ) => $r['amount'] < 0 );
	$since = min( array_filter( array_column( $rows, 'ts' ) ) ?: array( time() - 90 * DAY_IN_SECONDS ) );
	// 같은 사람들의 「다른 사유로 빠진」 줄 — 손으로 이미 거뒀을 수 있다.
	$other = array();
	if ( '' !== $map['ts'] ) {
		$in = implode( ',', array_map( 'intval', $uids ) );
		$q  = "SELECT {$sel} FROM `" . esc_sql( $table ) . '` WHERE `' . esc_sql( $map['user'] ) . "` IN ({$in}) AND `" . esc_sql( $map['reason'] ) . '` NOT LIKE %s'; // phpcs:ignore WordPress.DB
		// 우리 화면(적립금 한 번에 넣기)이 다른 묶음을 거둔 줄은 이 적립금과 무관하다 — 그 사유는 뺀다.
		$ours = array_values( array_filter( array_map( fn( $b ) => (string) ( $b['label'] ?? '' ), (array) get_option( OPT, array() ) ) ) );
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( $q, $wpdb->esc_like( $prefix ) . '%' ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$why = (string) $r['reason'];
			if ( ! preg_match( '/회수|종료|소멸|차감|만료/u', reason_base( $why ) ) || ( (float) $r['amount'] > 0 && $signed_hint ) ) {
				continue;
			}
			foreach ( $ours as $l ) {
				if ( str_starts_with( $why, $l ) ) {
					continue 2;
				}
			}
			$other[ (int) $r['user'] ][] = reason_ts( $r['ts'] );
		}
	}
	$bal = array();
	foreach ( $uids as $u ) {
		$bal[ $u ] = (int) get_user_meta( $u, '_keyple_points', true );
	}
	$plan = reason_plan( $rows, reason_orders( $uids, $since ), $bal, $other );
	$names = function_exists( '\\Duckhoo\\Redesign\\Crm\\contacts' ) ? \Duckhoo\Redesign\Crm\contacts( $uids ) : array();
	foreach ( $plan as $u => &$p ) {
		$p['name']  = (string) ( $names[ $u ]['name'] ?? '' );
		$p['phone'] = (string) ( $names[ $u ]['phone'] ?? '' );
	}
	unset( $p );
	$res['ok']   = true;
	$res['plan'] = $plan;
	return $res;
}

/** 거두기 — 시간이 모자라면 멈추고 남은 수를 돌려준다. */
function reason_take( string $prefix, bool $with_other, float $budget = 20.0 ): array {
	$scan = reason_scan( $prefix );
	$out  = array( 'n' => 0, 'sum' => 0, 'left' => 0, 'skip' => 0, 'msg' => $scan['msg'] );
	if ( ! $scan['ok'] || ! $scan['plan'] || ! function_exists( 'Duckhoo\\Redesign\\Points\\grant' ) ) {
		return $out;
	}
	$label = reason_base( $prefix ) . ' 사용기간 종료';
	$t0    = microtime( true );
	foreach ( $scan['plan'] as $u => $p ) {
		if ( $p['take'] <= 0 ) {
			continue;
		}
		if ( $p['other'] && ! $with_other ) {
			++$out['skip'];
			continue;
		}
		if ( microtime( true ) - $t0 > $budget ) {
			++$out['left'];
			continue;
		}
		if ( \Duckhoo\Redesign\Points\grant( (int) $u, - (int) $p['take'], $label ) ) {
			++$out['n'];
			$out['sum'] += (int) $p['take'];
		}
	}
	return $out;
}

/** 화면 상자 (찾기 폼 + 결과). */
function reason_box( string $prefix, string $msg = '' ): string {
	$h  = '<div style="margin:16px 0;padding:16px;border:2px solid #b42318;border-radius:8px;background:#fff">';
	$h .= '<h2 style="margin-top:0">사유로 찾기 · 안 쓴 적립금 거두기</h2>';
	$h .= '<p style="margin-top:0;color:#50575e">고객관리에서 손으로 넣은 적립금도 원장의 사유로 찾습니다. 사유 앞부분을 적고 「찾기」 — 거두는 것은 아래 「거두기」를 눌렀을 때만입니다.</p>';
	$h .= '<form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">' . wp_nonce_field( 'dhr-pts', '_wpnonce', true, false )
		. '<input type="text" name="reason" value="' . esc_attr( $prefix ) . '" placeholder="예: 261008 이벤트 적립금" style="min-width:320px">'
		. '<button class="button button-primary" name="dhr_pts" value="reason_scan">찾기</button></form>';
	if ( '' !== $msg ) {
		$h .= '<p style="font-size:15px"><b>' . esc_html( $msg ) . '</b></p>';
	}
	if ( '' === $prefix ) {
		return $h . '</div>';
	}
	$s = reason_scan( $prefix );
	if ( '' !== $s['msg'] ) {
		$h .= '<p>' . esc_html( $s['msg'] ) . '</p>';
	}
	if ( ! $s['plan'] ) {
		return $h . '</div>';
	}
	$p      = $s['plan'];
	$given  = array_sum( array_column( $p, 'given' ) );
	$taken  = array_sum( array_column( $p, 'taken' ) );
	$used   = array_sum( array_column( $p, 'used' ) );
	$take   = array_sum( array_column( $p, 'take' ) );
	$tn     = count( array_filter( $p, fn( $x ) => $x['take'] > 0 ) );
	$on     = count( array_filter( $p, fn( $x ) => $x['take'] > 0 && $x['other'] ) );
	$done   = count( array_filter( $p, fn( $x ) => $x['taken'] > 0 ) );
	$h .= '<p style="font-size:15px">이 사유로 받은 사람 <b>' . number_format( count( $p ) ) . '명 · ' . number_format( $given ) . '원</b> · 이미 거둔 사람 ' . number_format( $done ) . '명 (' . number_format( $taken ) . '원) · 그 뒤 주문에서 쓴 적립금 ' . number_format( $used ) . '원</p>';
	$h .= '<p style="font-size:16px">지금 거둘 수 있는 것: <b style="color:#b42318">' . number_format( $tn ) . '명 · ' . number_format( $take ) . '원</b>'
		. ( $on ? ' <span style="color:#b45309">(그중 ' . number_format( $on ) . '명은 그 뒤 다른 사유로 적립금이 빠진 기록이 있어 기본으로 건너뜁니다 — 손으로 이미 거뒀을 수 있습니다)</span>' : '' ) . '</p>';
	$h .= '<p style="color:#646970">거둘 금액 = 넣은 금액 − 이미 거둔 금액 − 그 뒤 주문에서 쓴 적립금, 지금 잔액보다 많이는 안 뺍니다. 원장 사유는 「' . esc_html( reason_base( $prefix ) ) . ' 사용기간 종료」 — 손님 내역에 그대로 보입니다.</p>';
	if ( $tn > 0 ) {
		$h .= '<form method="post" style="margin:12px 0">' . wp_nonce_field( 'dhr-pts', '_wpnonce', true, false )
			. '<input type="hidden" name="reason" value="' . esc_attr( $prefix ) . '">'
			. ( $on ? '<label style="display:block;margin-bottom:8px"><input type="checkbox" name="with_other" value="1"> 다른 사유로 빠진 기록이 있는 ' . number_format( $on ) . '명도 거두기</label>' : '' )
			. '<button class="button button-primary button-hero" style="background:#b42318;border-color:#b42318" name="dhr_pts" value="reason_take" onclick="return confirm(\'안 쓴 적립금을 거둘까요? 손님 잔액에서 바로 빠집니다.\')">거두기 (' . number_format( $tn - $on ) . '명 · 기본)</button></form>';
	}
	uasort( $p, fn( $a, $b ) => $b['take'] <=> $a['take'] );
	$h .= '<table class="widefat striped"><thead><tr><th>이름</th><th>번호</th><th>회원</th><th>넣은 날</th><th>넣음</th><th>이미 거둠</th><th>그 뒤 쓴 적립금</th><th>지금 잔액</th><th>거둘 금액</th><th>메모</th></tr></thead><tbody>';
	foreach ( array_slice( $p, 0, 600, true ) as $u => $x ) {
		$h .= '<tr><td>' . esc_html( $x['name'] ) . '</td><td>' . esc_html( mask( $x['phone'] ) ) . '</td><td><a href="' . esc_url( admin_url( 'user-edit.php?user_id=' . (int) $u ) ) . '" target="_blank">#' . (int) $u . '</a></td>'
			. '<td>' . ( $x['at'] ? esc_html( wp_date( 'n/j H:i', $x['at'] ) ) : '—' ) . '</td><td>' . number_format( $x['given'] ) . '</td><td>' . number_format( $x['taken'] ) . '</td><td>' . number_format( $x['used'] ) . '</td><td>' . number_format( $x['bal'] ) . '</td>'
			. '<td><b>' . number_format( $x['take'] ) . '</b></td><td>' . ( $x['other'] ? '다른 사유로 빠진 기록' : '' ) . '</td></tr>';
	}
	$h .= '</tbody></table>';
	return $h . '</div>';
}
