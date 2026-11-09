<?php
/**
 * 마케팅 → 문자 참여 — 보낸 문자 파일을 여러 개 한 번에 올려 문자마다 참여를 본다.
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

const SAVED = 'duckhoo_crm_sends_saved';

/**
 * 올린 발송 파일을 기억한다 — 다음부터 CRM 기간 성과의 「한눈에」 표에 저절로 나온다.
 * 번호는 숫자만(셈에만 쓴다). 같은 파일 · 같은 날은 하나로. 최근 60개.
 */
function remember( array $sends ): void {
	$all = (array) get_option( SAVED, array() );
	foreach ( $sends as $x ) {
		$all[ md5( $x['name'] . '|' . $x['date'] ) ] = array( 'name' => $x['name'], 'date' => $x['date'], 'at' => (int) $x['at'], 'kw' => (array) $x['kw'], 'phones' => array_values( (array) $x['phones'] ), 'saved' => time() );
	}
	uasort( $all, fn( $a, $b ) => (int) $b['at'] <=> (int) $a['at'] );
	update_option( SAVED, array_slice( $all, 0, 60, true ), false );
}

/** 기억해 둔 발송 파일. */
function saved(): array {
	return array_values( array_filter( (array) get_option( SAVED, array() ), 'is_array' ) );
}

/** 기본 신상품 낱말 (10월 발송분). */
function default_products(): string {
	return (string) apply_filters( 'duckhoo_crm_sends_products', "1014 = 영주사과, 고창수박\n1015 = 크림타바코, 말라이트\n1019 = 환타지아\n1021 = 스피아민트, 아메리카노\n1022 = 금실딸기, 복숭아" );
}

/* ── 화면 ───────────────────────────────────────────────────────────── */

function menu(): void {
	$cap    = current_user_can( 'manage_woocommerce' ) ? 'manage_woocommerce' : 'manage_options';
	$parent = isset( $GLOBALS['admin_page_hooks']['woocommerce-marketing'] ) ? 'woocommerce-marketing' : 'tools.php';
	add_submenu_page( $parent, '문자 참여', '문자 참여', $cap, SLUG, __NAMESPACE__ . '\\screen' );
}
add_action( 'admin_menu', __NAMESPACE__ . '\\menu', 61 );

function screen(): void {
	if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$days  = function_exists( '\\Duckhoo\\Redesign\\Crm\\impact_days' ) ? \Duckhoo\Redesign\Crm\impact_days() : 14;
	$hm    = isset( $_POST['hm'] ) && preg_match( '/^\d{1,2}:\d{2}$/', (string) $_POST['hm'] ) ? (string) $_POST['hm'] : '13:30'; // phpcs:ignore WordPress.Security
	$prods = isset( $_POST['prods'] ) ? (string) wp_unslash( $_POST['prods'] ) : default_products(); // phpcs:ignore WordPress.Security
	echo '<div class="wrap"><h1>문자 참여</h1>';
	new_liquid_box();
	echo '<p style="max-width:780px">보낸 문자 파일(문자라인에 올린 엑셀 · CSV)을 <b>여러 개 한 번에</b> 고르세요. 보낸 날은 파일 이름의 날짜(1013 · 20261013)로 읽고, 받은 뒤 ' . (int) $days . '일 동안 산 사람을 문자마다 셉니다. 올린 파일의 번호는 셈에 쓰려고 사이트에 남겨 두고(다음부터 <b>CRM 기간 성과 「한눈에」 표</b>에 저절로 나옵니다), 화면에는 숫자만 나옵니다.</p>';
	echo '<form method="post" enctype="multipart/form-data" style="background:#fff;border:1px solid #dcdcde;padding:14px;max-width:780px">';
	wp_nonce_field( 'dhr-crm-sends' );
	echo '<p><input type="file" name="files[]" multiple accept=".xlsx,.xls,.csv,.txt"></p>';
	echo '<p>보낸 시각 <input type="text" name="hm" value="' . esc_attr( $hm ) . '" style="width:70px"> (예약 시각 · 파일마다 같다고 봅니다)</p>';
	echo '<p style="margin:10px 0 4px"><b>신상품 문자</b> — 날짜 = 상품 이름 조각 (그 상품을 산 사람 · 판매 수량을 따로 셉니다)</p>';
	echo '<textarea name="prods" rows="5" style="width:100%;font-family:monospace">' . esc_textarea( $prods ) . '</textarea>';
	echo '<p><button class="button button-primary" name="dhr_sends" value="1">세기</button></p></form>';

	if ( empty( $_POST['dhr_sends'] ) || ! check_admin_referer( 'dhr-crm-sends' ) ) {
		echo '</div>';
		return;
	}
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
	try {
		$kwmap = parse_products( $prods );
		$y     = (int) wp_date( 'Y' );
		$mo    = (int) wp_date( 'n' );
		$sends = array();
		$bad   = array();
		$f     = $_FILES['files'] ?? array(); // phpcs:ignore WordPress.Security
		foreach ( (array) ( $f['name'] ?? array() ) as $i => $name ) {
			$name = sanitize_file_name( (string) $name );
			$tmp  = (string) ( $f['tmp_name'][ $i ] ?? '' );
			if ( '' === $tmp || ! is_uploaded_file( $tmp ) || filesize( $tmp ) > 8 * MB_IN_BYTES ) {
				continue;
			}
			$date   = send_date( $name, $y, $mo );
			$phones = file_numbers( $tmp, $name );
			if ( '' === $date || ! $phones ) {
				$bad[] = $name . ( '' === $date ? ' (파일 이름에 날짜가 없음)' : ' (번호를 못 읽음)' );
				continue;
			}
			$mmdd    = substr( $date, 5, 2 ) . substr( $date, 8, 2 );
			$sends[] = array( 'name' => $name, 'date' => $date, 'at' => at_of( $date, $hm ), 'phones' => $phones, 'kw' => $kwmap[ $mmdd ] ?? array() );
		}
		if ( ! $sends ) {
			echo '<p style="color:#b42318">읽은 파일이 없습니다. ' . esc_html( implode( ' · ', $bad ) ) . '</p></div>';
			return;
		}
		usort( $sends, fn( $a, $b ) => $a['at'] <=> $b['at'] ?: strcmp( $a['name'], $b['name'] ) );
		$since = min( array_column( $sends, 'at' ) ) - $days * DAY_IN_SECONDS;
		$d     = \Duckhoo\Redesign\Crm\impact_orders( $since );
		$paid  = \Duckhoo\Redesign\Crm\paid_statuses();
		$dead  = \Duckhoo\Redesign\Crm\dead_statuses();
		$items = array();
		if ( array_filter( array_column( $sends, 'kw' ) ) && function_exists( '\\Duckhoo\\Redesign\\Anatomy\\items' ) ) {
			$ids   = array_map( fn( $o ) => (int) $o['id'], array_filter( $d['orders'], fn( $o ) => (int) $o['ts'] >= min( array_column( $sends, 'at' ) ) && in_array( (string) $o['s'], $paid, true ) ) );
			$items = \Duckhoo\Redesign\Anatomy\items( array_values( $ids ) );
		}
		$rows = array();
		foreach ( $sends as $s ) {
			$im   = \Duckhoo\Redesign\Crm\impact( $s['phones'], $s['at'], $d['orders'], $d['uphone'], $days, time(), $paid, $dead );
			$prod = $s['kw'] ? product_stats( $s['phones'], $s['at'], $days, $d['orders'], $items, $d['uphone'], $s['kw'], $paid ) : array();
			$rows[] = array( 'name' => $s['name'], 'date' => $s['date'], 'kw' => $s['kw'], 'im' => $im, 'prod' => $prod );
		}
		remember( $sends );
		$all = overall( $sends, $d['orders'], $d['uphone'], $days, $paid, $dead );
		render( $rows, $all, $days, $bad );
	} catch ( \Throwable $e ) {
		echo '<p style="color:#b42318">세다 멈췄습니다: ' . esc_html( $e->getMessage() ) . '</p>';
	}
	echo '</div>';
}

function render( array $rows, array $all, int $days, array $bad ): void {
	$w  = fn( $n ) => number_format_i18n( (int) round( (float) $n ) );
	$pc = fn( $x ) => number_format_i18n( $x * 100, 1 ) . '%';
	echo '<div style="background:#fff;border:1px solid #dcdcde;padding:14px;margin:14px 0;max-width:780px">';
	echo '<p style="margin:0;font-size:15px">받은 사람 <b>' . esc_html( $w( $all['people'] ) ) . '명</b> (문자 ' . esc_html( $w( $all['messages'] ) ) . '통) 가운데 <b>' . esc_html( $w( $all['buyers'] ) ) . '명</b>이 받은 뒤 ' . (int) $days . '일 안에 샀습니다 — <b style="font-size:18px">' . esc_html( $pc( $all['rate'] ) ) . '</b></p>';
	echo '<p style="margin:6px 0 0">주문 <b>' . esc_html( $w( $all['orders'] ) ) . '건</b> · 실입금 <b>' . esc_html( $w( $all['sales'] ) ) . '원</b> <span style="color:#646970">(여러 문자를 받은 사람도 한 번만 · 주문도 한 번만)</span></p></div>';
	if ( $bad ) {
		echo '<p style="color:#b42318">못 읽은 파일: ' . esc_html( implode( ' · ', $bad ) ) . '</p>';
	}
	echo '<div class="dhr-sl-scroll" style="overflow-x:auto"><table class="widefat striped" style="min-width:900px"><thead><tr><th>보낸 날</th><th>파일</th><th>받은</th><th>산 사람</th><th>전환율</th><th>주문 · 실입금</th><th>입금 전</th><th>보내기 전 같은 기간</th><th>신상품</th></tr></thead><tbody>';
	foreach ( $rows as $r ) {
		$m    = $r['im'];
		$lift = $m['before']['rate'] > 0 ? ' · ' . number_format_i18n( $m['rate'] / $m['before']['rate'], 1 ) . '배' : '';
		$pr   = $r['prod'] ? esc_html( implode( '·', $r['kw'] ) ) . '<br>산 받은 사람 <b>' . esc_html( $w( $r['prod']['buyers'] ) ) . '명</b> · ' . esc_html( $w( $r['prod']['qty'] ) ) . '개<br><span style="color:#646970">가게 전체 ' . esc_html( $w( $r['prod']['store_qty'] ) ) . '개</span>' : '—';
		echo '<tr><td>' . esc_html( substr( $r['date'], 5 ) ) . ( $m['done'] ? '' : '<br><span style="color:#b26200">' . (int) $m['elapsed'] . '일째</span>' ) . '</td>';
		echo '<td style="max-width:200px;word-break:keep-all">' . esc_html( $r['name'] ) . '</td>';
		echo '<td>' . esc_html( $w( $m['n'] ) ) . '</td><td><b>' . esc_html( $w( $m['buyers'] ) ) . '</b></td><td><b>' . esc_html( $pc( $m['rate'] ) ) . '</b></td>';
		echo '<td>' . esc_html( $w( $m['orders'] ) ) . '건 · ' . esc_html( $w( $m['sales'] ) ) . '원</td><td>' . esc_html( $w( $m['pend'] ) ) . '</td>';
		echo '<td>' . esc_html( $w( $m['before']['buyers'] ) ) . '명 · ' . esc_html( $pc( $m['before']['rate'] ) ) . esc_html( $lift ) . '</td><td>' . $pr . '</td></tr>'; // phpcs:ignore WordPress.Security -- $pr 는 위에서 다 걸렀다
	}
	echo '</tbody></table></div>';
	echo '<p class="dhr-sl-note" style="max-width:780px;color:#646970">「산 사람」은 매출 화면과 같은 상태(입금확인 이후) 주문만, 취소 · 환불은 뺍니다. 「보내기 전 같은 기간」은 같은 사람들이 문자 전 같은 날수 동안 산 것 — 최근에 산 사람을 뽑아 보낸 문자는 이 값이 높게 나오기 쉽습니다. 「아직 N일째」인 줄은 ' . (int) $days . '일이 지나야 끝난 숫자입니다. 모니터(사장님 · 직원) 번호도 들어 있으면 한두 명이 같이 셉니다.</p>';
	echo '<p style="margin-top:16px"><b>붙여 넣기용 (숫자만)</b> — 통째로 복사해 클로드에게 주시면 보고서로 엮습니다.</p>';
	echo '<textarea readonly rows="14" style="width:100%;max-width:980px;font-family:monospace;font-size:12px" onclick="this.select()">' . esc_textarea( text( $rows, $all, $days ) ) . '</textarea>';
}

/* ── 신상품 판매 — 문자를 받은 손님 · 안 받은 손님 (2026-11-09) ─────────────── */

/**
 * 새로 들인 액상마다 올린 날부터 지금까지: 판매 수량 · 산 사람 · 그중 그 상품 문자를 받은 사람(받은 뒤 N일 안 / 그 뒤) ·
 * 신상품 문자를 하나라도 받은 사람 · 안 받은 사람. 순수.
 *
 * 사람은 받은 번호 → 회원 번호 → 주문서 번호 → 주문 번호 순으로 하나로 본다.
 * 묶음(10병 골라 담기)의 맛 칸에서 고른 것은 줄 이름에 안 나와 여기 안 잡힌다.
 *
 * @param array $prods  [ pid => [ 'name' => …, 'at' => 올린 시각 ] ].
 * @param array $sends  [ { at, phones[], kw[] } ] — 기억해 둔 신상품 문자.
 * @param array $items  주문 번호 => [ {pid, name, qty, total} ].
 * @return array{rows:array,orders:int,from:int}
 */
function new_liquid_sales( array $prods, array $sends, array $orders, array $items, array $uphone, array $paid, int $now, int $days ): array {
	$any = array();
	foreach ( $sends as $s ) {
		foreach ( (array) $s['phones'] as $p ) {
			$any[ $p ] = true;
		}
	}
	$from = $prods ? min( array_map( fn( $x ) => (int) $x['at'], $prods ) ) : $now;
	$rows = array();
	foreach ( $prods as $pid => $x ) {
		$mine = array_values( array_filter( $sends, fn( $s ) => ! empty( $s['kw'] ) && name_has( (string) $x['name'], (array) $s['kw'] ) ) );
		$rows[ (int) $pid ] = array( 'pid' => (int) $pid, 'name' => (string) $x['name'], 'at' => (int) $x['at'], 'sent' => count( $mine ), 'qty' => 0, 'sales' => 0.0, 'orders' => 0, 'p' => array(), 'in14' => array(), 'late' => array(), 'anyp' => array(), 'none' => array(), 'mine' => $mine );
	}
	$store = 0;
	foreach ( $orders as $o ) {
		$ts = (int) $o['ts'];
		if ( $ts < $from || ! in_array( (string) $o['s'], $paid, true ) ) {
			continue;
		}
		++$store;
		foreach ( (array) ( $items[ (int) $o['id'] ] ?? array() ) as $it ) {
			$pid = (int) ( $it['pid'] ?? 0 );
			if ( ! isset( $rows[ $pid ] ) || $ts < $rows[ $pid ]['at'] ) {
				continue;
			}
			$r              = &$rows[ $pid ];
			$r['qty']      += (int) $it['qty'];
			$r['sales']    += (float) $it['total'];
			$r['orders']   += 1;
			$ph             = owner( $o, $any, $uphone );
			$key            = '' !== $ph ? 'p' . $ph : ( (int) $o['u'] > 0 ? 'u' . (int) $o['u'] : ( '' !== (string) $o['p'] ? 'p' . $o['p'] : 'o' . (int) $o['id'] ) );
			$r['p'][ $key ] = true;
			if ( '' === $ph ) {
				$r['none'][ $key ] = true;
			} else {
				$hit = '';
				foreach ( $r['mine'] as $s ) {
					if ( in_array( $ph, (array) $s['phones'], true ) && $ts >= (int) $s['at'] ) {
						$hit = $ts < (int) $s['at'] + $days * DAY_IN_SECONDS ? 'in14' : ( '' === $hit ? 'late' : $hit );
						if ( 'in14' === $hit ) {
							break;
						}
					}
				}
				if ( '' !== $hit ) {
					$r[ $hit ][ $key ] = true;
				} else {
					$r['anyp'][ $key ] = true;
				}
			}
			unset( $r );
		}
	}
	$out = array();
	foreach ( $rows as $r ) {
		$d     = max( 1, (int) ceil( ( $now - $r['at'] ) / DAY_IN_SECONDS ) );
		$out[] = array(
			'pid' => $r['pid'], 'name' => $r['name'], 'at' => $r['at'], 'days' => $d, 'sent' => $r['sent'],
			'qty' => $r['qty'], 'sales' => $r['sales'], 'orders' => $r['orders'], 'per_day' => $r['qty'] / $d,
			'buyers' => count( $r['p'] ), 'in14' => count( $r['in14'] ), 'late' => count( $r['late'] ),
			'other_text' => count( $r['anyp'] ), 'none' => count( $r['none'] ),
		);
	}
	usort( $out, fn( $a, $b ) => $b['qty'] <=> $a['qty'] ?: $a['at'] <=> $b['at'] );
	return array( 'rows' => $out, 'orders' => $store, 'from' => $from );
}

/** 붙여 넣기용 글. 순수. */
function new_liquid_text( array $res, int $days, string $asof ): string {
	$w = fn( $n ) => number_format( (int) round( (float) $n ) );
	$L = array( sprintf( '[신상품 판매] %s 집계 · 올린 날부터 지금까지 · 돈 들어온 주문만', $asof ) );
	foreach ( $res['rows'] as $r ) {
		$L[] = sprintf(
			'· %s (올린 날 %s · %d일) — %s개 · %s원 · 산 사람 %s명 [그 상품 문자 받고 %d일 안 %s · 그 뒤 %s · 다른 신상품 문자만 받은 %s · 문자 안 받은 %s] · 하루 %s개%s',
			$r['name'], wp_date( 'n/j', $r['at'] ), $r['days'], $w( $r['qty'] ), $w( $r['sales'] ), $w( $r['buyers'] ), $days, $w( $r['in14'] ), $w( $r['late'] ), $w( $r['other_text'] ), $w( $r['none'] ),
			number_format( $r['per_day'], 1 ), $r['sent'] ? '' : ' · 이 상품 문자 없음'
		);
	}
	$L[] = '(묶음의 맛 칸에서 고른 병은 안 셈)';
	return implode( "\n", $L );
}

/** 새로 들인 액상 — 최근 N일 안에 올린 상품 중 팟 · 기기 · 묶음 · 대량 · 수동 결제를 뺀 것. */
function new_liquids( int $days = 60 ): array {
	$ids = get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'numberposts' => 60, 'fields' => 'ids', 'date_query' => array( array( 'after' => gmdate( 'Y-m-d', time() - $days * DAY_IN_SECONDS ) ) ) ) );
	$out = array();
	foreach ( (array) $ids as $id ) {
		$t = (string) get_the_title( $id );
		if ( preg_match( '/결제|팟|코일|기기|대량|묶음|이벤트|세트/u', $t ) ) {
			continue;
		}
		$out[ (int) $id ] = array( 'name' => $t, 'at' => (int) get_post_time( 'U', true, $id ) );
	}
	return (array) apply_filters( 'duckhoo_new_liquids', $out );
}

/** 문자 참여 화면 아래 — 버튼 하나로 센다 (파일을 올리지 않아도 기억해 둔 문자로). */
function new_liquid_box(): void {
	$days = function_exists( '\\Duckhoo\\Redesign\\Crm\\impact_days' ) ? \Duckhoo\Redesign\Crm\impact_days() : 14;
	echo '<div style="max-width:980px;background:#fff;border:1px solid #dcdcde;padding:14px;margin:0 0 18px"><h2 style="margin-top:0">신상품 판매 — 문자를 받고 산 손님 · 안 받고 산 손님</h2>';
	echo '<p style="max-width:780px">최근 60일 안에 올린 액상마다, 올린 날부터 지금까지 몇 개 팔렸고 누가 샀는지 나눕니다. 문자는 이 화면에 한 번이라도 올린 신상품 문자 파일로 봅니다. 읽기만 합니다.</p>';
	echo '<form method="post">';
	wp_nonce_field( 'dhr-new-liquid' );
	echo '<button class="button button-primary" name="dhr_newliq" value="1">신상품 판매 보기</button></form>';
	if ( empty( $_POST['dhr_newliq'] ) || ! check_admin_referer( 'dhr-new-liquid' ) ) {
		echo '</div>';
		return;
	}
	try {
		$prods = new_liquids();
		if ( ! $prods ) {
			echo '<p>최근 60일 안에 올린 액상이 없습니다.</p></div>';
			return;
		}
		$sends = array_values( array_filter( saved(), fn( $s ) => ! empty( $s['kw'] ) ) );
		$from  = min( array_map( fn( $x ) => (int) $x['at'], $prods ) );
		$d     = \Duckhoo\Redesign\Crm\impact_orders( $from );
		$paid  = \Duckhoo\Redesign\Crm\paid_statuses();
		$ids   = array_map( fn( $o ) => (int) $o['id'], array_filter( $d['orders'], fn( $o ) => (int) $o['ts'] >= $from && in_array( (string) $o['s'], $paid, true ) ) );
		$items = function_exists( '\\Duckhoo\\Redesign\\Anatomy\\items' ) ? \Duckhoo\Redesign\Anatomy\items( array_values( $ids ) ) : array();
		$res   = new_liquid_sales( $prods, $sends, $d['orders'], $items, $d['uphone'], $paid, time(), $days );
		$w     = fn( $n ) => number_format_i18n( (int) round( (float) $n ) );
		echo '<p>신상품 문자 기록 ' . count( $sends ) . '개 · 같은 기간 가게 전체 주문 ' . esc_html( $w( $res['orders'] ) ) . '건</p>';
		echo '<div style="overflow-x:auto"><table class="widefat striped" style="min-width:900px"><thead><tr><th>상품</th><th>올린 날</th><th>판매</th><th>하루</th><th>산 사람</th><th>그 상품 문자 받고 ' . (int) $days . '일 안</th><th>그 뒤</th><th>다른 신상품 문자만</th><th>문자 안 받음</th></tr></thead><tbody>';
		foreach ( $res['rows'] as $r ) {
			echo '<tr><td>' . esc_html( $r['name'] ) . ( $r['sent'] ? '' : '<br><span style="color:#646970">이 상품 문자 없음</span>' ) . '</td><td>' . esc_html( wp_date( 'n/j', $r['at'] ) ) . '</td>';
			echo '<td><b>' . esc_html( $w( $r['qty'] ) ) . '개</b><br>' . esc_html( $w( $r['sales'] ) ) . '원</td><td>' . esc_html( number_format_i18n( $r['per_day'], 1 ) ) . '</td><td>' . esc_html( $w( $r['buyers'] ) ) . '명</td>';
			echo '<td><b>' . esc_html( $w( $r['in14'] ) ) . '</b></td><td>' . esc_html( $w( $r['late'] ) ) . '</td><td>' . esc_html( $w( $r['other_text'] ) ) . '</td><td>' . esc_html( $w( $r['none'] ) ) . '</td></tr>';
		}
		echo '</tbody></table></div><p style="color:#646970">묶음(10병 골라 담기)의 맛 칸에서 고른 병은 상품 줄 이름에 안 나와 여기 안 잡힙니다.</p>';
		echo '<p><b>붙여 넣기용</b></p><textarea readonly rows="12" style="width:100%;font-family:monospace;font-size:12px" onclick="this.select()">' . esc_textarea( new_liquid_text( $res, $days, wp_date( 'n월 j일 H:i' ) ) ) . '</textarea>';
	} catch ( \Throwable $e ) {
		echo '<p style="color:#b42318">세다 멈췄습니다: ' . esc_html( $e->getMessage() ) . '</p>';
	}
	echo '</div>';
}
