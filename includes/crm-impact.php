<?php
/**
 * 고객 세그먼트 — 발송 성과 (문자를 보낸 뒤 그 사람들이 샀는가).
 *
 * 사장님 2026-10-12 「이거 구매 전환율 한 번 볼래?」 — 1주차 「노보 · 디오리퀴드 구매 손님」 582명 문자 사이트 양식을 올렸다.
 * 번호만 있는 명단이라 누가 샀는지는 주문과 맞춰야 나온다. 주문 · 회원은 관리자 안에서만 읽히므로 이 화면이 센다.
 *
 * 무엇을 세나:
 * - 받은 사람(번호) 가운데 보낸 뒤 N일(기본 14) 안에 **주문한 사람** — 돈 들어온 주문 · 입금 대기 주문을 따로
 * - 그 주문 수 · 실입금 · 객단가 · 첫 주문까지 걸린 날
 * - 같은 사람들의 **보내기 전 같은 길이**(기준선). 명단이 「최근에 산 사람」이면 보내기 전이 높게 나오기 쉽다 — 화면에 적는다
 * 번호는 회원의 본인확인 번호 · 연락처 · 청구 번호와, 주문서의 청구 번호(비회원) 둘 다로 맞춘다.
 *
 * **읽기만 한다.** 올린 파일은 저장하지 않는다 (그 요청 안에서 세고 버린다). 결과도 숫자만 — 이름 · 번호를 화면에 안 적는다.
 *
 * @package Duckhoo\Redesign
 */

namespace Duckhoo\Redesign\Crm;

defined( 'ABSPATH' ) || exit;

/** 보낸 뒤 며칠을 보나. 필터 `duckhoo_crm_impact_days`. */
function impact_days(): int {
	return max( 1, min( 60, (int) apply_filters( 'duckhoo_crm_impact_days', 14 ) ) );
}

/* ── 순수 함수 ───────────────────────────────────────────────────────── */

/**
 * 아무 글(문자 사이트 양식 · CSV · 붙여 넣은 표)에서 휴대폰 번호를 모은다. 같은 번호는 하나.
 *
 * @return string[] 숫자만 (01012345678).
 */
function phones_in( string $text ): array {
	$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	preg_match_all( '/(?<!\d)(?:\+?82[\s.-]?)?0?1[016789][\s.-]?\d{3,4}[\s.-]?\d{4}(?!\d)/', $text, $m );
	$out = array();
	foreach ( $m[0] as $p ) {
		$n = phone_norm( $p );
		if ( strlen( $n ) >= 10 && str_starts_with( $n, '01' ) ) {
			$out[ $n ] = true;
		}
	}
	return array_keys( $out );
}

/** 파일 이름에서 날짜(20261008) — 보낸 날의 기본값. 없으면 ''. */
function date_in_name( string $name ): string {
	return preg_match( '/(20\d{2})(\d{2})(\d{2})/', $name, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? $m[1] . '-' . $m[2] . '-' . $m[3] : '';
}

/** 가운데 값. */
function median( array $xs ): float {
	if ( ! $xs ) {
		return 0.0;
	}
	sort( $xs );
	$n = count( $xs );
	return $n % 2 ? (float) $xs[ intdiv( $n, 2 ) ] : ( $xs[ $n / 2 - 1 ] + $xs[ $n / 2 ] ) / 2;
}

/**
 * 성과를 센다.
 *
 * @param string[] $phones   받은 번호 (숫자만).
 * @param int      $at       보낸 시각 (timestamp).
 * @param array    $orders   [ {id, ts, s, t, u, p} ] — p = 주문서 청구 번호(숫자만, 없으면 '').
 * @param array    $uphone   회원 번호 => [번호, …] (숫자만).
 * @param int      $days     보낸 뒤 며칠.
 * @param int      $now      지금.
 * @param string[] $paid     돈 들어온 상태.
 * @param string[] $dead     죽은 상태(취소 · 환불 · 실패).
 * @return array<string,mixed>
 */
function impact( array $phones, int $at, array $orders, array $uphone, int $days, int $now, array $paid, array $dead ): array {
	$set = array_fill_keys( $phones, true );
	$win = $days * DAY_IN_SECONDS;
	$own = function ( array $o ) use ( $set, $uphone ): string {
		foreach ( (array) ( $uphone[ (int) $o['u'] ] ?? array() ) as $p ) {
			if ( isset( $set[ $p ] ) ) {
				return $p;
			}
		}
		$p = (string) ( $o['p'] ?? '' );
		return '' !== $p && isset( $set[ $p ] ) ? $p : '';
	};
	$side = function ( int $from, int $to ) use ( $orders, $own, $paid, $dead, $at ): array {
		$r = array( 'buyers' => array(), 'pend' => array(), 'orders' => 0, 'sales' => 0.0, 'first' => array() );
		foreach ( $orders as $o ) {
			$ts = (int) $o['ts'];
			if ( $ts < $from || $ts >= $to || in_array( (string) $o['s'], $dead, true ) ) {
				continue;
			}
			$p = $own( $o );
			if ( '' === $p ) {
				continue;
			}
			if ( in_array( (string) $o['s'], $paid, true ) ) {
				$r['buyers'][ $p ] = true;
				++$r['orders'];
				$r['sales'] += (float) $o['t'];
				$d = ( $ts - $at ) / DAY_IN_SECONDS;
				if ( $d >= 0 && ( ! isset( $r['first'][ $p ] ) || $d < $r['first'][ $p ] ) ) {
					$r['first'][ $p ] = $d;
				}
			} else {
				$r['pend'][ $p ] = true;
			}
		}
		foreach ( array_keys( $r['buyers'] ) as $p ) {
			unset( $r['pend'][ $p ] ); // 하나라도 돈이 들어왔으면 산 사람
		}
		return $r;
	};
	$end    = min( $now, $at + $win );
	$after  = $side( $at, $at + $win );
	$before = $side( $at - $win, $at );
	$known  = 0; // 우리 가게 회원 · 주문에 있는 번호
	$all    = array();
	foreach ( $uphone as $ps ) {
		foreach ( (array) $ps as $p ) {
			$all[ $p ] = true;
		}
	}
	foreach ( $orders as $o ) {
		if ( '' !== (string) ( $o['p'] ?? '' ) ) {
			$all[ (string) $o['p'] ] = true;
		}
	}
	foreach ( $phones as $p ) {
		if ( isset( $all[ $p ] ) ) {
			++$known;
		}
	}
	$n  = count( $phones );
	$nb = count( $after['buyers'] );
	return array(
		'n'          => $n,
		'known'      => $known,
		'days'       => $days,
		'elapsed'    => max( 0, (int) floor( ( $end - $at ) / DAY_IN_SECONDS ) ),
		'done'       => $now >= $at + $win,
		'buyers'     => $nb,
		'pend'       => count( $after['pend'] ),
		'orders'     => $after['orders'],
		'sales'      => $after['sales'],
		'aov'        => $after['orders'] ? $after['sales'] / $after['orders'] : 0.0,
		'rate'       => $n ? $nb / $n : 0.0,
		'first_med'  => median( array_values( $after['first'] ) ),
		'before'     => array(
			'buyers' => count( $before['buyers'] ),
			'orders' => $before['orders'],
			'sales'  => $before['sales'],
			'rate'   => $n ? count( $before['buyers'] ) / $n : 0.0,
		),
	);
}

/* ── 읽기 ───────────────────────────────────────────────────────────── */

/** 회원 번호 => [번호…]. 본인확인 번호 · 연락처 · 청구 번호. 한 질의. */
function member_phones(): array {
	global $wpdb;
	$out = array();
	if ( ! isset( $wpdb ) ) {
		return $out;
	}
	$rows = (array) $wpdb->get_results( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key IN ('wd_verified_phone','wd_phone','billing_phone') AND meta_value <> ''", ARRAY_A ); // phpcs:ignore WordPress.DB
	foreach ( $rows as $r ) {
		$p = phone_norm( (string) $r['meta_value'] );
		if ( '' !== $p ) {
			$out[ (int) $r['user_id'] ][ $p ] = $p;
		}
	}
	return array_map( 'array_values', $out );
}

/** 주문 번호 => 청구 번호 (`$since` 이후 주문만). 한 질의. */
function order_phones( int $since ): array {
	global $wpdb;
	$out = array();
	if ( ! isset( $wpdb ) ) {
		return $out;
	}
	$gmt = gmdate( 'Y-m-d H:i:s', $since - DAY_IN_SECONDS );
	if ( function_exists( '\\Duckhoo\\Redesign\\Anatomy\\hpos' ) && \Duckhoo\Redesign\Anatomy\hpos() ) {
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT a.order_id AS id, a.phone AS p FROM {$wpdb->prefix}wc_order_addresses a JOIN {$wpdb->prefix}wc_orders o ON o.id = a.order_id WHERE a.address_type = 'billing' AND o.date_created_gmt >= %s", $gmt ), ARRAY_A ); // phpcs:ignore WordPress.DB
	} else {
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT m.post_id AS id, m.meta_value AS p FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = '_billing_phone' AND p.post_type = 'shop_order' AND p.post_date_gmt >= %s", $gmt ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}
	foreach ( $rows as $r ) {
		$out[ (int) $r['id'] ] = phone_norm( (string) $r['p'] );
	}
	return $out;
}

/** 성과 계산에 쓸 주문 (`$since` 이후) — 청구 번호를 붙인다. 10분 캐시. */
function impact_orders( int $since ): array {
	$key = 'dhr_crm_imp2_' . gmdate( 'YmdH', $since );
	$c   = get_transient( $key );
	if ( is_array( $c ) ) {
		return $c;
	}
	$all = function_exists( '\\Duckhoo\\Redesign\\Anatomy\\orders' ) ? \Duckhoo\Redesign\Anatomy\orders() : array();
	$bp  = order_phones( $since );
	$off = (int) ( function_exists( 'wp_timezone' ) ? ( new \DateTime( 'now', wp_timezone() ) )->getOffset() : 9 * 3600 ); // Anatomy\orders() 의 ts 는 사이트 시간으로 밀려 있다
	$out = array();
	foreach ( $all as $o ) {
		$ts = (int) $o['ts'] - $off;
		if ( $ts < $since - DAY_IN_SECONDS ) {
			continue;
		}
		$out[] = array( 'id' => (int) $o['id'], 'ts' => $ts, 's' => (string) $o['s'], 't' => (float) $o['t'], 'u' => (int) $o['u'], 'p' => (string) ( $bp[ (int) $o['id'] ] ?? '' ) );
	}
	$data = array( 'orders' => $out, 'uphone' => member_phones() );
	set_transient( $key, $data, 10 * MINUTE_IN_SECONDS );
	return $data;
}

/** 번호 명단 하나의 성과 — 읽기까지. */
function impact_for( array $phones, int $at ): array {
	$days = impact_days();
	$d    = impact_orders( $at - $days * DAY_IN_SECONDS );
	return impact( $phones, $at, $d['orders'], $d['uphone'], $days, time(), paid_statuses(), dead_statuses() );
}

/* ── 화면 ───────────────────────────────────────────────────────────── */

/** 결과 한 덩어리 — 숫자만. */
function impact_html( array $r, string $title ): string {
	$w   = fn( $n ) => number_format_i18n( (int) round( (float) $n ) );
	$pc  = fn( $x ) => number_format_i18n( $x * 100, 1 ) . '%';
	$lift = $r['before']['rate'] > 0 ? $r['rate'] / $r['before']['rate'] : 0;
	$h  = '<div class="dhr-crm-adv" style="background:#fff;border:1px solid #dcdcde">';
	$h .= '<p style="margin:0 0 6px;font-weight:700">' . esc_html( $title ) . '</p>';
	$h .= '<p style="margin:0 0 8px;font-size:15px">받은 <b>' . esc_html( $w( $r['n'] ) ) . '명</b> 가운데 <b>' . esc_html( $w( $r['buyers'] ) ) . '명</b>이 ' . (int) $r['days'] . '일 안에 샀습니다 — 구매 전환율 <b style="font-size:18px">' . esc_html( $pc( $r['rate'] ) ) . '</b></p>';
	$h .= '<table class="widefat striped" style="max-width:640px"><tbody>';
	$row = fn( $k, $v ) => '<tr><td style="width:46%">' . esc_html( $k ) . '</td><td><b>' . esc_html( $v ) . '</b></td></tr>';
	$h .= $row( '돈 들어온 주문', $w( $r['orders'] ) . '건 · ' . $w( $r['sales'] ) . '원 (객단가 ' . $w( $r['aov'] ) . '원)' );
	$h .= $row( '주문만 하고 아직 입금 전', $w( $r['pend'] ) . '명' );
	$h .= $row( '받고 첫 주문까지 (가운데 값)', $r['buyers'] ? number_format_i18n( $r['first_med'], 1 ) . '일' : '—' );
	$h .= $row( '보내기 전 같은 ' . (int) $r['days'] . '일 (같은 사람들)', $w( $r['before']['buyers'] ) . '명 · ' . $pc( $r['before']['rate'] ) . ' · ' . $w( $r['before']['sales'] ) . '원' );
	$h .= $row( '보내기 전과 견주면', $r['before']['rate'] > 0 ? number_format_i18n( $lift, 2 ) . '배' : '보내기 전 구매 0명' );
	$h .= $row( '우리 회원 · 주문에서 찾은 번호', $w( $r['known'] ) . ' / ' . $w( $r['n'] ) . '명' );
	$h .= '</tbody></table>';
	$h .= '<p class="dhr-sl-note" style="margin:8px 0 0">' . ( $r['done'] ? (int) $r['days'] . '일이 다 지났습니다.' : '<b>아직 ' . (int) $r['elapsed'] . '일째</b>입니다 — ' . (int) $r['days'] . '일이 지나야 끝난 숫자입니다.' )
		. ' 「돈 들어온 주문」은 매출 화면과 같은 상태(입금확인 이후)만, 취소 · 환불은 뺍니다. 이 명단처럼 <b>최근에 산 사람</b>을 뽑아 보낸 경우 「보내기 전」이 높게 나오기 쉽습니다 — 다음 묶음과 견주는 쪽이 정확합니다.</p>';
	return $h . '</div>';
}

/** 상자 — 기록된 묶음마다 성과 + 파일로 재기. */
function impact_box( string $back ): void {
	$res = '';
	if ( isset( $_POST['dhr_imp'] ) && check_admin_referer( 'dhr-crm-imp' ) ) {
		$name = isset( $_FILES['imp_file']['name'] ) ? (string) $_FILES['imp_file']['name'] : '';
		$text = isset( $_POST['imp_text'] ) ? (string) wp_unslash( $_POST['imp_text'] ) : '';
		$tmp  = isset( $_FILES['imp_file']['tmp_name'] ) ? (string) $_FILES['imp_file']['tmp_name'] : '';
		if ( '' !== $tmp && is_uploaded_file( $tmp ) && filesize( $tmp ) < 5 * MB_IN_BYTES ) {
			$text .= "\n" . (string) file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- 읽고 버린다
		}
		$date = isset( $_POST['imp_date'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['imp_date'] ) ) : '';
		if ( '' === $date ) {
			$date = date_in_name( $name );
		}
		$hour   = isset( $_POST['imp_hour'] ) ? max( 0, min( 23, (int) $_POST['imp_hour'] ) ) : 10;
		$phones = phones_in( $text );
		$ts     = '' !== $date ? strtotime( $date . sprintf( ' %02d:00:00', $hour ) . ' ' . wp_timezone_string() ) : false;
		if ( ! $phones ) {
			$res = '<div class="notice notice-error inline"><p>파일에서 휴대폰 번호를 찾지 못했습니다.</p></div>';
		} elseif ( false === $ts ) {
			$res = '<div class="notice notice-error inline"><p>보낸 날짜를 적어 주세요 (파일 이름에 날짜가 없습니다).</p></div>';
		} else {
			$res = impact_html( impact_for( $phones, (int) $ts ), ( '' !== $name ? $name . ' · ' : '' ) . wp_date( 'Y.m.d H시', (int) $ts ) . ' 보냄' );
		}
	}
	$bs = function_exists( __NAMESPACE__ . '\\sent_batches' ) ? sent_batches() : array();
	echo '<details class="dhr-crm-adv" style="margin:0 0 14px"' . ( '' !== $res ? ' open' : '' ) . '><summary style="cursor:pointer;font-weight:700">발송 성과 — 문자를 받은 사람이 ' . (int) impact_days() . '일 안에 샀는가</summary>';
	echo $res; // phpcs:ignore WordPress.Security.EscapeOutput -- impact_html 안에서 이스케이프
	if ( $bs ) {
		echo '<table class="widefat striped" style="max-width:900px;margin:10px 0"><thead><tr><th>내려받은 때</th><th>그룹명</th><th>받은 사람</th><th>산 사람</th><th>전환율</th><th>실입금</th><th></th></tr></thead><tbody>';
		foreach ( array_slice( $bs, 0, 8 ) as $b ) {
			$ph = array_values( array_unique( array_filter( array_map( fn( $r ) => phone_norm( (string) ( $r['phone'] ?? '' ) ), (array) ( $b['rows'] ?? array() ) ) ) ) );
			try {
				$r = impact_for( $ph, (int) $b['at'] );
			} catch ( \Throwable $e ) {
				echo '<tr><td colspan="7">읽다 멈췄습니다: ' . esc_html( $e->getMessage() ) . '</td></tr>';
				continue;
			}
			echo '<tr><td>' . esc_html( wp_date( 'm.d H:i', (int) $b['at'] ) ) . '</td><td>' . esc_html( (string) $b['group'] ) . '</td><td>' . esc_html( number_format_i18n( $r['n'] ) ) . '명</td><td>' . esc_html( number_format_i18n( $r['buyers'] ) ) . '명' . ( $r['pend'] ? ' (+입금 전 ' . (int) $r['pend'] . ')' : '' ) . '</td><td><b>' . esc_html( number_format_i18n( $r['rate'] * 100, 1 ) ) . '%</b> <span class="dhr-sl-note">전 ' . esc_html( number_format_i18n( $r['before']['rate'] * 100, 1 ) ) . '%</span></td><td>' . esc_html( number_format_i18n( (int) round( $r['sales'] ) ) ) . '원</td><td>' . ( $r['done'] ? '끝' : (int) $r['elapsed'] . '일째' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
	echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( $back ) . '" style="margin:10px 0 0;display:grid;gap:8px;max-width:640px">';
	wp_nonce_field( 'dhr-crm-imp' );
	echo '<input type="hidden" name="dhr_imp" value="1">';
	echo '<p style="margin:0">여기서 내려받지 않은 명단도 됩니다 — 문자 사이트에 올린 파일(.xls · .csv)을 고르거나 번호를 붙여 넣으세요. <b>파일은 저장하지 않습니다.</b></p>';
	echo '<label>파일 <input type="file" name="imp_file" accept=".xls,.csv,.txt,.html"></label>';
	echo '<label>또는 번호 붙여 넣기<br><textarea name="imp_text" rows="3" style="width:100%" placeholder="010-1234-5678 한 줄에 하나"></textarea></label>';
	echo '<label>보낸 날 <input type="date" name="imp_date"> <input type="number" name="imp_hour" min="0" max="23" value="10" style="width:60px">시 <span class="dhr-sl-note">비우면 파일 이름의 날짜 (예: …_20261008.xls)</span></label>';
	echo '<p style="margin:0"><button class="button button-primary">성과 보기</button></p></form>';
	echo '</details>';
}
