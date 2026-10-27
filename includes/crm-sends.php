<?php
/**
 * 마케팅 → CRM 성과 — 10/13 부터 오늘까지 보낸 문자 · 적립금 전부를 한 화면에서 본다.
 *
 * 보낸 문자 파일은 **한 번만** 올리면 사이트가 기억한다 (번호는 해시로만 — 원래 번호는 저장하지 않는다).
 * 그 뒤로는 화면을 열 때마다 주문과 맞춰 저절로 센다: 문자마다 · 종류(적립금 · 신제품 · 노보)마다 · 전체(한 사람 한 번),
 * 적립금 묶음(넣은 묶음 기록) · 그 기간 가게 전체(처음 / 다시 산 손님, 바로 앞 같은 길이와).
 *
 * 「발송 성과」(crm-impact.php)는 파일 하나씩, 「CRM 기간 성과」는 사이트에서 내려받은 묶음만 센다.
 * 클로드가 만들어 드린 발송 파일(1013_발송_954명.xlsx …)은 사이트에 기록이 없어 거기 안 잡힌다.
 * 이 화면은 파일을 통째로 받는다 — 보낸 날은 파일 이름(20261013 · 1013_)에서, 번호는 파일 안에서.
 *
 * 문자마다: 받은 사람 · N일 안에 산 사람 · 전환율 · 주문 · 실입금 · 보내기 전 같은 기간,
 * 신상품 문자면 그 상품을 산 받은 사람 · 그 기간 가게 전체 판매 수량.
 * 맨 위 합계는 한 사람을 한 번만 센다 (여러 문자를 받아도).
 *
 * **읽기만 한다. 올린 파일은 저장하지 않는다.** 화면에 이름 · 번호를 적지 않는다.
 *
 * @package DuckhooRedesign
 */

namespace Duckhoo\Redesign\CrmSends;

defined( 'ABSPATH' ) || exit;

const SLUG = 'duckhoo-crm-sends';

/* ── 순수 함수 ───────────────────────────────────────────────────────── */

/**
 * 파일 이름에서 보낸 날. 20261013 → 그대로, 1013_ · _1013_ → 올해(이번 달보다 뒤면 작년). 없으면 ''.
 */
function send_date( string $name, int $year, int $month ): string {
	if ( preg_match( '/(20\d{2})(\d{2})(\d{2})/', $name, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
		return $m[1] . '-' . $m[2] . '-' . $m[3];
	}
	if ( preg_match( '/(?<!\d)(\d{2})(\d{2})(?!\d)/', $name, $m ) && checkdate( (int) $m[1], (int) $m[2], $year ) ) {
		$y = (int) $m[1] > $month ? $year - 1 : $year;
		return sprintf( '%04d-%02d-%02d', $y, (int) $m[1], (int) $m[2] );
	}
	return '';
}

/**
 * 「1014 = 영주사과, 고창수박」 줄들 → [ '1014' => [영주사과, 고창수박] ]. 순수.
 *
 * @return array<string,string[]>
 */
function parse_products( string $text ): array {
	$out = array();
	foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $ln ) {
		if ( ! preg_match( '/^\s*(\d{4})\s*[=:]\s*(.+)$/u', $ln, $m ) ) {
			continue;
		}
		$kw = array_values( array_filter( array_map( fn( $s ) => trim( $s ), preg_split( '/[,，·\/]/u', $m[2] ) ) ) );
		if ( $kw ) {
			$out[ $m[1] ] = $kw;
		}
	}
	return $out;
}

/** 상품 이름에 낱말 하나라도 들어 있나 (빈칸 무시). */
function name_has( string $name, array $kw ): bool {
	$n = preg_replace( '/\s+/u', '', $name );
	foreach ( $kw as $k ) {
		$k = preg_replace( '/\s+/u', '', (string) $k );
		if ( '' !== $k && false !== mb_strpos( $n, $k ) ) {
			return true;
		}
	}
	return false;
}

/**
 * 주문의 주인 번호 — 받은 번호 집합 안에서. 회원 번호 → 주문서 청구 번호.
 */
function owner( array $o, array $set, array $uphone ): string {
	foreach ( (array) ( $uphone[ (int) $o['u'] ] ?? array() ) as $p ) {
		if ( isset( $set[ $p ] ) ) {
			return (string) $p;
		}
	}
	$p = (string) ( $o['p'] ?? '' );
	return '' !== $p && isset( $set[ $p ] ) ? $p : '';
}

/**
 * 신상품 셈 — 받은 사람 중 보낸 뒤 N일 안에 그 상품을 산 사람 · 그 기간 가게 전체 판매 수량. 순수.
 *
 * @param array $items 주문 번호 => [ {name, qty, total} ].
 * @return array{buyers:int,qty:int,store_qty:int,store_sales:float}
 */
function product_stats( array $phones, int $at, int $days, array $orders, array $items, array $uphone, array $kw, array $paid ): array {
	$set = array_fill_keys( $phones, true );
	$end = $at + $days * DAY_IN_SECONDS;
	$r   = array( 'buyers' => array(), 'qty' => 0, 'store_qty' => 0, 'store_sales' => 0.0 );
	foreach ( $orders as $o ) {
		$ts = (int) $o['ts'];
		if ( $ts < $at || $ts >= $end || ! in_array( (string) $o['s'], $paid, true ) ) {
			continue;
		}
		$q = 0;
		foreach ( (array) ( $items[ (int) $o['id'] ] ?? array() ) as $it ) {
			if ( name_has( (string) $it['name'], $kw ) ) {
				$q                 += (int) $it['qty'];
				$r['store_sales']  += (float) $it['total'];
			}
		}
		if ( ! $q ) {
			continue;
		}
		$r['store_qty'] += $q;
		$p = owner( $o, $set, $uphone );
		if ( '' !== $p ) {
			$r['buyers'][ $p ] = true;
			$r['qty']         += $q;
		}
	}
	return array( 'buyers' => count( $r['buyers'] ), 'qty' => $r['qty'], 'store_qty' => $r['store_qty'], 'store_sales' => $r['store_sales'] );
}

/**
 * 합계 — 한 사람은 한 번. 받은 뒤 N일 안의 주문은 그 사람이 받은 문자 중 하나에라도 걸리면 한 번 센다. 순수.
 *
 * @param array $sends [ {phones:string[], at:int} ].
 * @return array{people:int,messages:int,buyers:int,orders:int,sales:float,rate:float}
 */
function overall( array $sends, array $orders, array $uphone, int $days, array $paid, array $dead ): array {
	$got = array(); // 번호 => [보낸 시각…]
	$msg = 0;
	foreach ( $sends as $s ) {
		foreach ( (array) $s['phones'] as $p ) {
			$got[ $p ][] = (int) $s['at'];
			++$msg;
		}
	}
	$win    = $days * DAY_IN_SECONDS;
	$buyers = array();
	$n      = 0;
	$sales  = 0.0;
	foreach ( $orders as $o ) {
		if ( ! in_array( (string) $o['s'], $paid, true ) || in_array( (string) $o['s'], $dead, true ) ) {
			continue;
		}
		$p = owner( $o, $got, $uphone );
		if ( '' === $p ) {
			continue;
		}
		$ts = (int) $o['ts'];
		foreach ( $got[ $p ] as $at ) {
			if ( $ts >= $at && $ts < $at + $win ) {
				$buyers[ $p ] = true;
				++$n;
				$sales += (float) $o['t'];
				break;
			}
		}
	}
	$people = count( $got );
	return array( 'people' => $people, 'messages' => $msg, 'buyers' => count( $buyers ), 'orders' => $n, 'sales' => $sales, 'rate' => $people ? count( $buyers ) / $people : 0.0 );
}

/** 붙여 넣기용 글 — 숫자만. 순수. */
function text( array $rows, array $all, int $days ): string {
	$w  = fn( $n ) => number_format( (int) round( (float) $n ) );
	$pc = fn( $x ) => number_format( $x * 100, 1 ) . '%';
	$L  = array();
	$L[] = sprintf( '[문자 참여] 받은 뒤 %d일 · 문자 %d개', $days, count( $rows ) );
	$L[] = sprintf( '합계 — 받은 사람 %s명(문자 %s통) 중 산 사람 %s명 (%s) · 주문 %s건 · 실입금 %s원', $w( $all['people'] ), $w( $all['messages'] ), $w( $all['buyers'] ), $pc( $all['rate'] ), $w( $all['orders'] ), $w( $all['sales'] ) );
	$L[] = '';
	foreach ( $rows as $r ) {
		$m   = $r['im'];
		$ln  = sprintf( '· %s %s — %s명 중 산 사람 %s명 (%s) · 주문 %s건 · 실입금 %s원 · 보내기 전 같은 기간 %s명%s', substr( $r['date'], 5 ), $r['name'], $w( $m['n'] ), $w( $m['buyers'] ), $pc( $m['rate'] ), $w( $m['orders'] ), $w( $m['sales'] ), $w( $m['before']['buyers'] ), $m['done'] ? '' : ' (아직 ' . (int) $m['elapsed'] . '일째)' );
		if ( ! empty( $r['prod'] ) ) {
			$ln .= sprintf( ' · [%s] 산 받은 사람 %s명 · %s개, 가게 전체 %s개', implode( '·', $r['kw'] ), $w( $r['prod']['buyers'] ), $w( $r['prod']['qty'] ), $w( $r['prod']['store_qty'] ) );
		}
		$L[] = $ln;
	}
	return implode( "\n", $L );
}

/* ── 읽기 ───────────────────────────────────────────────────────────── */

/** 올린 파일 하나에서 번호. */
function file_numbers( string $tmp, string $name ): array {
	$lower = strtolower( $name );
	if ( str_ends_with( $lower, '.xlsx' ) && function_exists( '\\Duckhoo\\Redesign\\Points\\Admin\\xlsx_table' ) ) {
		return \Duckhoo\Redesign\Points\Admin\file_phones( \Duckhoo\Redesign\Points\Admin\xlsx_table( $tmp ) );
	}
	return \Duckhoo\Redesign\Crm\phones_in( (string) file_get_contents( $tmp ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- 읽고 버린다
}

/** 사이트 시간 Y-m-d H:i → timestamp. */
function at_of( string $date, string $hm ): int {
	try {
		$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'Asia/Seoul' );
		return ( new \DateTime( $date . ' ' . $hm, $tz ) )->getTimestamp();
	} catch ( \Throwable $e ) {
		return (int) strtotime( $date . ' ' . $hm );
	}
}

/** 기본 신상품 낱말 (10월 발송분). */
function default_products(): string {
	return (string) apply_filters( 'duckhoo_crm_sends_products', "1014 = 영주사과, 고창수박\n1015 = 크림타바코, 말라이트\n1019 = 환타지아\n1021 = 스피아민트, 아메리카노\n1022 = 금실딸기, 복숭아" );
}

/* ── 저장한 문자 (번호는 해시로만) ─────────────────────────────────── */

const OPT_CAMP = 'duckhoo_crm_campaigns';
const OPT_FROM = 'duckhoo_crm_report_from';

/** 번호 해시 — 같은 사이트 안에서 맞추기만 하면 된다. */
function hp( string $p ): string {
	$k = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : 'dhr';
	return substr( hash_hmac( 'sha256', $p, $k ), 0, 20 );
}

/** 파일 이름으로 종류를 짐작한다. 순수. */
function guess_kind( string $name ): string {
	if ( preg_match( '/신상|신제품/u', $name ) ) {
		return '신제품';
	}
	if ( false !== mb_strpos( $name, '노보' ) ) {
		return '노보';
	}
	if ( preg_match( '/적립|VIP|혼합|성장|이벤트|복귀|이탈|단골/ui', $name ) ) {
		return '적립금';
	}
	return '기타';
}

/** 종류 목록. */
function kinds(): array {
	return array( '적립금', '신제품', '노보', '기타' );
}

function campaigns(): array {
	$c = get_option( OPT_CAMP, array() );
	return is_array( $c ) ? $c : array();
}

function report_from(): string {
	$f = (string) get_option( OPT_FROM, '2026-10-13' );
	return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $f ) ? $f : '2026-10-13';
}

/** 해시로 바꾼 주문 · 회원 번호 (10분 캐시). */
function hashed_data( int $since ): array {
	$key = 'dhr_crm_hd_' . gmdate( 'YmdH', $since ) . substr( gmdate( 'i' ), 0, 1 );
	$c   = get_transient( $key );
	if ( is_array( $c ) ) {
		return $c;
	}
	$d  = \Duckhoo\Redesign\Crm\impact_orders( $since );
	$up = array();
	foreach ( (array) $d['uphone'] as $u => $ps ) {
		$up[ $u ] = array_map( __NAMESPACE__ . '\\hp', (array) $ps );
	}
	$os = array();
	foreach ( (array) $d['orders'] as $o ) {
		$o['p'] = '' !== (string) ( $o['p'] ?? '' ) ? hp( (string) $o['p'] ) : '';
		$os[]   = $o;
	}
	$out = array( 'orders' => $os, 'uphone' => $up );
	set_transient( $key, $out, 10 * MINUTE_IN_SECONDS );
	return $out;
}

/* ── 화면 ───────────────────────────────────────────────────────────── */

function menu(): void {
	$cap    = current_user_can( 'manage_woocommerce' ) ? 'manage_woocommerce' : 'manage_options';
	$parent = isset( $GLOBALS['admin_page_hooks']['woocommerce-marketing'] ) ? 'woocommerce-marketing' : 'tools.php';
	add_submenu_page( $parent, 'CRM 성과', 'CRM 성과', $cap, SLUG, __NAMESPACE__ . '\\screen' );
}
add_action( 'admin_menu', __NAMESPACE__ . '\\menu', 61 );

/** 올리기 · 고치기 · 지우기. 돌려주는 것은 안내 글. */
function handle(): string {
	if ( empty( $_POST['dhr_camp'] ) || ! check_admin_referer( 'dhr-crm-camp' ) ) {
		return '';
	}
	$all = campaigns();
	$act = (string) $_POST['dhr_camp']; // phpcs:ignore WordPress.Security
	if ( 'del' === $act ) {
		$id = sanitize_key( (string) ( $_POST['id'] ?? '' ) ); // phpcs:ignore WordPress.Security
		unset( $all[ $id ] );
		update_option( OPT_CAMP, $all, false );
		return '지웠습니다.';
	}
	if ( 'edit' === $act ) {
		foreach ( (array) ( $_POST['c'] ?? array() ) as $id => $v ) { // phpcs:ignore WordPress.Security
			$id = sanitize_key( (string) $id );
			if ( ! isset( $all[ $id ] ) ) {
				continue;
			}
			$k = (string) wp_unslash( $v['kind'] ?? '' );
			$all[ $id ]['kind']  = in_array( $k, kinds(), true ) ? $k : $all[ $id ]['kind'];
			$all[ $id ]['label'] = sanitize_text_field( wp_unslash( (string) ( $v['label'] ?? $all[ $id ]['label'] ) ) );
			$all[ $id ]['kw']    = array_values( array_filter( array_map( 'trim', preg_split( '/[,，·\/]/u', sanitize_text_field( wp_unslash( (string) ( $v['kw'] ?? '' ) ) ) ) ) ) );
			$d = (string) ( $v['date'] ?? '' );
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ) {
				$all[ $id ]['date'] = $d;
			}
		}
		$from = (string) ( $_POST['from'] ?? '' ); // phpcs:ignore WordPress.Security
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) {
			update_option( OPT_FROM, $from, false );
		}
		update_option( OPT_CAMP, $all, false );
		return '고쳤습니다.';
	}
	// 올리기
	$kwmap = parse_products( default_products() );
	$y     = (int) wp_date( 'Y' );
	$mo    = (int) wp_date( 'n' );
	$f     = $_FILES['files'] ?? array(); // phpcs:ignore WordPress.Security
	$ok    = 0;
	$bad   = array();
	foreach ( (array) ( $f['name'] ?? array() ) as $i => $name ) {
		$name = sanitize_file_name( (string) $name );
		$tmp  = (string) ( $f['tmp_name'][ $i ] ?? '' );
		if ( '' === $tmp || ! is_uploaded_file( $tmp ) || filesize( $tmp ) > 8 * MB_IN_BYTES ) {
			continue;
		}
		$date   = send_date( $name, $y, $mo );
		$phones = file_numbers( $tmp, $name );
		if ( '' === $date || ! $phones ) {
			$bad[] = $name . ( '' === $date ? ' (파일 이름에 날짜 없음)' : ' (번호를 못 읽음)' );
			continue;
		}
		$id          = substr( md5( $name . '|' . $date ), 0, 12 );
		$mmdd        = substr( $date, 5, 2 ) . substr( $date, 8, 2 );
		$kind        = guess_kind( $name );
		$label       = preg_replace( '/\.(xlsx?|csv|txt)$/i', '', $name );
		$all[ $id ]  = array(
			'label' => $label,
			'date'  => $date,
			'kind'  => $kind,
			'kw'    => '신제품' === $kind ? ( $kwmap[ $mmdd ] ?? array() ) : array(),
			'h'     => array_values( array_unique( array_map( __NAMESPACE__ . '\\hp', $phones ) ) ),
			'at'    => time(),
		);
		++$ok;
	}
	update_option( OPT_CAMP, $all, false );
	return sprintf( '%d개를 저장했습니다.', $ok ) . ( $bad ? ' 못 읽은 파일: ' . implode( ' · ', $bad ) : '' );
}

function screen(): void {
	if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$msg  = handle();
	$days = function_exists( '\\Duckhoo\\Redesign\\Crm\\impact_days' ) ? \Duckhoo\Redesign\Crm\impact_days() : 14;
	$from = isset( $_GET['rf'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $_GET['rf'] ) ? (string) $_GET['rf'] : report_from(); // phpcs:ignore WordPress.Security
	$to   = isset( $_GET['rt'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $_GET['rt'] ) ? (string) $_GET['rt'] : wp_date( 'Y-m-d' ); // phpcs:ignore WordPress.Security
	echo '<div class="wrap"><h1>CRM 성과</h1>';
	if ( $msg ) {
		echo '<div class="notice notice-success"><p>' . esc_html( $msg ) . '</p></div>';
	}
	echo '<form method="get" style="margin:8px 0 14px"><input type="hidden" name="page" value="' . esc_attr( SLUG ) . '"> 기간 <input type="date" name="rf" value="' . esc_attr( $from ) . '"> ~ <input type="date" name="rt" value="' . esc_attr( $to ) . '"> <button class="button">보기</button> <span style="color:#646970">문자마다 받은 뒤 ' . (int) $days . '일 동안 산 사람을 셉니다. 읽기만 합니다.</span></form>';
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
	try {
		dashboard( $from, $to, $days );
	} catch ( \Throwable $e ) {
		echo '<p style="color:#b42318">세다 멈췄습니다: ' . esc_html( $e->getMessage() ) . '</p>';
	}
	manage_box( $from );
	echo '</div>';
}

/** 큰 화면 — 가게 전체 · 문자 · 적립금. */
function dashboard( string $from, string $to, int $days ): void {
	$w   = fn( $n ) => number_format_i18n( (int) round( (float) $n ) );
	$pc  = fn( $a, $b ) => $b > 0 ? number_format_i18n( $a / $b * 100, 1 ) . '%' : '—';
	$ch  = fn( $a, $b ) => $b > 0 ? sprintf( '%+.0f%%', ( $a - $b ) / $b * 100 ) : '—';
	$rep = function_exists( '\\Duckhoo\\Redesign\\CrmReport\\period' ) ? \Duckhoo\Redesign\CrmReport\period( $from, $to ) : null;
	$L   = array( sprintf( '[CRM 성과] %s ~ %s (받은 뒤 %d일 기준, 집계 %s)', $from, $to, $days, wp_date( 'Y-m-d H:i' ) ) );

	// 1. 가게 전체
	if ( $rep ) {
		$s = $rep['sales'];
		$b = $rep['base'];
		echo '<h2>1. 가게 전체</h2><p style="color:#646970;margin-top:-6px">바로 앞 같은 길이(' . esc_html( $b['from'] . ' ~ ' . $b['to'] ) . ')와 견줍니다. 문자를 안 받은 손님도 들어 있습니다.</p>';
		echo '<table class="widefat striped" style="max-width:780px"><thead><tr><th></th><th>이번 기간</th><th>앞 기간</th><th>달라진 것</th></tr></thead><tbody>';
		foreach ( array( array( '주문', 'all', 'n' ), array( '실입금', 'all', 'sales' ), array( '처음 산 손님 주문', 'first', 'n' ), array( '다시 산 손님 주문', 'repeat', 'n' ), array( '다시 산 손님 실입금', 'repeat', 'sales' ) ) as $r ) {
			$a = (float) $s[ $r[1] ][ $r[2] ];
			$z = (float) $b[ $r[1] ][ $r[2] ];
			echo '<tr><td>' . esc_html( $r[0] ) . '</td><td><b>' . esc_html( $w( $a ) ) . '</b></td><td>' . esc_html( $w( $z ) ) . '</td><td>' . esc_html( $ch( $a, $z ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
		$L[] = '';
		$L[] = sprintf( '가게 전체 — 주문 %s건 · 실입금 %s원 (앞 기간 %s건 · %s원, %s) · 처음 산 손님 주문 %s건 (앞 %s) · 다시 산 손님 주문 %s건 (앞 %s)', $w( $s['all']['n'] ), $w( $s['all']['sales'] ), $w( $b['all']['n'] ), $w( $b['all']['sales'] ), $ch( $s['all']['sales'], $b['all']['sales'] ), $w( $s['first']['n'] ), $w( $b['first']['n'] ), $w( $s['repeat']['n'] ), $w( $b['repeat']['n'] ) );
	}

	// 2. 문자 (저장한 것)
	$f0   = at_of( $from, '00:00' );
	$t0   = at_of( $to, '23:59' );
	$hm   = '13:30';
	$camp = array_filter( campaigns(), fn( $c ) => ( $a = at_of( (string) $c['date'], $hm ) ) >= $f0 && $a <= $t0 );
	uasort( $camp, fn( $a, $b ) => strcmp( $a['date'], $b['date'] ) ?: strcmp( $a['label'], $b['label'] ) );
	echo '<h2 style="margin-top:22px">2. 보낸 문자</h2>';
	if ( ! $camp ) {
		echo '<p>저장된 문자가 없습니다. 아래 「보낸 문자 파일 올리기」에서 파일을 한 번 올려 주세요.</p>';
	} else {
		$since = min( array_map( fn( $c ) => at_of( (string) $c['date'], $hm ), $camp ) ) - $days * DAY_IN_SECONDS;
		$d     = hashed_data( $since );
		$paid  = \Duckhoo\Redesign\Crm\paid_statuses();
		$dead  = \Duckhoo\Redesign\Crm\dead_statuses();
		$items = array();
		if ( array_filter( array_column( $camp, 'kw' ) ) && function_exists( '\\Duckhoo\\Redesign\\Anatomy\\items' ) ) {
			$ids   = array_map( fn( $o ) => (int) $o['id'], array_filter( $d['orders'], fn( $o ) => (int) $o['ts'] >= $since && in_array( (string) $o['s'], $paid, true ) ) );
			$items = \Duckhoo\Redesign\Anatomy\items( array_values( $ids ) );
		}
		$byk = array();
		echo '<div style="overflow-x:auto"><table class="widefat striped" style="min-width:920px"><thead><tr><th>보낸 날</th><th>종류</th><th>문자</th><th>받은</th><th>산 사람</th><th>전환율</th><th>주문 · 실입금</th><th>보내기 전 같은 기간</th><th>신제품</th></tr></thead><tbody>';
		$L[] = '';
		$L[] = '보낸 문자 (받은 뒤 ' . $days . '일)';
		foreach ( $camp as $c ) {
			$at = at_of( (string) $c['date'], $hm );
			$m  = \Duckhoo\Redesign\Crm\impact( (array) $c['h'], $at, $d['orders'], $d['uphone'], $days, time(), $paid, $dead );
			$pr = $c['kw'] ? product_stats( (array) $c['h'], $at, $days, $d['orders'], $items, $d['uphone'], (array) $c['kw'], $paid ) : array();
			$k  = (string) $c['kind'];
			$byk[ $k ][] = array( 'phones' => (array) $c['h'], 'at' => $at );
			echo '<tr><td>' . esc_html( substr( $c['date'], 5 ) ) . ( $m['done'] ? '' : '<br><span style="color:#b26200">' . (int) $m['elapsed'] . '일째</span>' ) . '</td><td>' . esc_html( $k ) . '</td><td>' . esc_html( $c['label'] ) . '</td>';
			echo '<td>' . esc_html( $w( $m['n'] ) ) . '</td><td><b>' . esc_html( $w( $m['buyers'] ) ) . '</b></td><td><b>' . esc_html( $pc( $m['buyers'], $m['n'] ) ) . '</b></td>';
			echo '<td>' . esc_html( $w( $m['orders'] ) ) . '건 · ' . esc_html( $w( $m['sales'] ) ) . '원</td><td>' . esc_html( $w( $m['before']['buyers'] ) ) . '명 · ' . esc_html( $pc( $m['before']['buyers'], $m['n'] ) ) . '</td>';
			echo '<td>' . ( $pr ? esc_html( implode( '·', $c['kw'] ) ) . '<br>산 받은 사람 <b>' . esc_html( $w( $pr['buyers'] ) ) . '</b> · 가게 전체 ' . esc_html( $w( $pr['store_qty'] ) ) . '개' : '—' ) . '</td></tr>';
			$L[] = sprintf( '· %s [%s] %s — %s명 중 산 사람 %s명 (%s) · 주문 %s건 · 실입금 %s원 · 보내기 전 같은 기간 %s명%s%s', substr( $c['date'], 5 ), $k, $c['label'], $w( $m['n'] ), $w( $m['buyers'] ), $pc( $m['buyers'], $m['n'] ), $w( $m['orders'] ), $w( $m['sales'] ), $w( $m['before']['buyers'] ), $pr ? sprintf( ' · [%s] 산 받은 사람 %s명, 가게 전체 %s개', implode( '·', $c['kw'] ), $w( $pr['buyers'] ), $w( $pr['store_qty'] ) ) : '', $m['done'] ? '' : ' (아직 ' . (int) $m['elapsed'] . '일째)' );
		}
		echo '</tbody></table></div>';
		echo '<h3>종류별 · 전체 (한 사람 한 번 · 주문도 한 번)</h3><table class="widefat striped" style="max-width:780px"><thead><tr><th>종류</th><th>문자</th><th>받은 사람</th><th>산 사람</th><th>전환율</th><th>주문 · 실입금</th></tr></thead><tbody>';
		$L[] = '';
		$L[] = '종류별 (한 사람 한 번)';
		$rows = $byk;
		$rows['전체'] = array_merge( ...array_values( $byk ) );
		foreach ( $rows as $k => $ss ) {
			$o = overall( $ss, $d['orders'], $d['uphone'], $days, $paid, $dead );
			$b = '전체' === $k ? ' style="font-weight:700"' : '';
			echo '<tr' . $b . '><td>' . esc_html( $k ) . '</td><td>' . count( $ss ) . '개 · ' . esc_html( $w( $o['messages'] ) ) . '통</td><td>' . esc_html( $w( $o['people'] ) ) . '</td><td>' . esc_html( $w( $o['buyers'] ) ) . '</td><td>' . esc_html( $pc( $o['buyers'], $o['people'] ) ) . '</td><td>' . esc_html( $w( $o['orders'] ) ) . '건 · ' . esc_html( $w( $o['sales'] ) ) . '원</td></tr>';
			$L[] = sprintf( '· %s — 문자 %d개 · %s통 · 받은 사람 %s명 중 산 사람 %s명 (%s) · 주문 %s건 · 실입금 %s원', $k, count( $ss ), $w( $o['messages'] ), $w( $o['people'] ), $w( $o['buyers'] ), $pc( $o['buyers'], $o['people'] ), $w( $o['orders'] ), $w( $o['sales'] ) );
		}
		echo '</tbody></table><p style="color:#646970">한 사람이 여러 문자를 받았으면 그 사람이 산 것은 한 번만 셉니다 — 그래서 종류별 합이 전체보다 큽니다. 「보내기 전 같은 기간」은 같은 사람들이 문자 전 같은 날수 동안 산 것이라, 그보다 높아야 문자가 움직인 것입니다.</p>';
	}

	// 3. 적립금 묶음
	if ( $rep && $rep['points'] ) {
		echo '<h2 style="margin-top:22px">3. 적립금 묶음</h2><table class="widefat striped" style="max-width:920px"><thead><tr><th>묶음</th><th>넣은 날</th><th>받은</th><th>산 사람</th><th>적립금 쓴 사람</th><th>쓴 적립금 / 넣은 적립금</th><th>실입금</th><th>넣기 전 같은 기간</th></tr></thead><tbody>';
		$L[] = '';
		$L[] = '적립금 묶음';
		foreach ( $rep['points'] as $p ) {
			$t = (array) ( $p['p'][''] ?? array() );
			if ( ! $t ) {
				continue;
			}
			$when = implode( ', ', array_map( fn( $x ) => substr( $x['at'], 5, 5 ), $p['runs'] ) ) ?: substr( $p['created'], 5, 5 );
			echo '<tr><td>' . esc_html( $p['label'] ) . ( $p['revoked'] ? ' <span style="color:#646970">(거둠)</span>' : '' ) . '</td><td>' . esc_html( $when ) . '</td><td>' . esc_html( $w( $t['n'] ) ) . '</td><td><b>' . esc_html( $w( $t['buyers'] ) ) . '</b> · ' . esc_html( $pc( $t['buyers'], $t['n'] ) ) . '</td><td>' . esc_html( $w( $t['users'] ) ) . ' · ' . esc_html( $pc( $t['users'], $t['n'] ) ) . '</td><td>' . esc_html( $w( $t['used'] ) ) . ' / ' . esc_html( $w( $p['sum'] ) ) . '원</td><td>' . esc_html( $w( $t['sales'] ) ) . '원</td><td>' . esc_html( $w( $t['before'] ) ) . '명</td></tr>';
			$L[] = sprintf( '· %s (%s) — 받은 %s명 중 산 사람 %s명 (%s) · 적립금 쓴 사람 %s명 · 쓴 적립금 %s / 넣은 %s원 · 실입금 %s원 · 넣기 전 같은 기간 %s명%s', $p['label'], $when, $w( $t['n'] ), $w( $t['buyers'] ), $pc( $t['buyers'], $t['n'] ), $w( $t['users'] ), $w( $t['used'] ), $w( $p['sum'] ), $w( $t['sales'] ), $w( $t['before'] ), $p['revoked'] ? ' (거둠)' : '' );
		}
		echo '</tbody></table>';
	}

	echo '<p style="margin-top:18px"><b>붙여 넣기용 (숫자만)</b> — 통째로 복사해 클로드에게 주시면 보고서로 엮습니다.</p>';
	echo '<textarea readonly rows="16" style="width:100%;max-width:980px;font-family:monospace;font-size:12px" onclick="this.select()">' . esc_textarea( implode( "\n", $L ) ) . '</textarea>';
}

/** 파일 올리기 · 저장된 문자 고치기. */
function manage_box( string $from ): void {
	$all = campaigns();
	echo '<h2 style="margin-top:28px">보낸 문자 파일 올리기</h2>';
	echo '<form method="post" enctype="multipart/form-data" style="background:#fff;border:1px solid #dcdcde;padding:14px;max-width:780px">';
	wp_nonce_field( 'dhr-crm-camp' );
	echo '<p>문자라인에 올린 엑셀 · CSV 를 <b>여러 개 한 번에</b> 고르세요. 이름 앞 날짜(1013_ · 20261013)로 보낸 날을 읽고, 이름에 「신상품 · 노보 · 적립 …」이 있으면 종류를 짐작합니다. <b>번호는 해시로만 남고 원래 번호 · 이름은 저장하지 않습니다.</b> 같은 파일을 다시 올리면 덮어씁니다.</p>';
	echo '<p><input type="file" name="files[]" multiple accept=".xlsx,.xls,.csv,.txt"> <button class="button button-primary" name="dhr_camp" value="add">저장</button></p></form>';
	if ( ! $all ) {
		return;
	}
	uasort( $all, fn( $a, $b ) => strcmp( $a['date'], $b['date'] ) );
	echo '<form method="post" style="margin-top:12px">';
	wp_nonce_field( 'dhr-crm-camp' );
	echo '<table class="widefat striped" style="max-width:980px"><thead><tr><th>보낸 날</th><th>이름</th><th>종류</th><th>신제품 이름 (쉼표)</th><th>번호</th><th></th></tr></thead><tbody>';
	foreach ( $all as $id => $c ) {
		$opts = '';
		foreach ( kinds() as $k ) {
			$opts .= '<option' . selected( $c['kind'], $k, false ) . '>' . esc_html( $k ) . '</option>';
		}
		echo '<tr><td><input type="date" name="c[' . esc_attr( $id ) . '][date]" value="' . esc_attr( $c['date'] ) . '"></td><td><input type="text" name="c[' . esc_attr( $id ) . '][label]" value="' . esc_attr( $c['label'] ) . '" style="width:100%"></td><td><select name="c[' . esc_attr( $id ) . '][kind]">' . $opts . '</select></td><td><input type="text" name="c[' . esc_attr( $id ) . '][kw]" value="' . esc_attr( implode( ', ', (array) $c['kw'] ) ) . '" style="width:100%"></td><td>' . count( (array) $c['h'] ) . '명</td>'; // phpcs:ignore WordPress.Security
		echo '<td><button class="button-link" style="color:#b42318" name="id" value="' . esc_attr( $id ) . '" formaction="" onclick="this.form.dhr_camp.value=\'del\';return confirm(\'이 문자 기록을 지울까요?\')">지우기</button></td></tr>';
	}
	echo '</tbody></table><p>기본 기간 시작일 <input type="date" name="from" value="' . esc_attr( $from ) . '"> <input type="hidden" name="dhr_camp" value="edit"><button class="button">고친 것 저장</button></p></form>';
}
