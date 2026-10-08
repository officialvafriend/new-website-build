<?php
/**
 * 고객 세그먼트 — SMS 수신 동의 · 문자 사이트 양식(.xls) 내보내기.
 *
 * 사장님 (2026-10-08): 「sms 수신 동의 안 한 사람들은 보내면 안 되니 체크해서 명단 주고, 각 그룹별로
 * 이 엑셀 양식(NO · 그룹명 · 이름 · 전화번호 · 메모)으로 제목도 변경해서 정리」.
 *
 * 가입 약관 화면(/agree/)의 「SMS 수신 동의」 체크박스는 지금 버튼이 그냥 다음 장으로 넘길 뿐 폼에 실리지 않는다
 * (2026-10-08 확인). 그런데 회원 메타 `wd_agree_sms` 가 49명에게만 있다(동의 12 · 거부 37) — 어느 시기의 가입 흐름이
 * 적은 것이고 나머지 회원은 기록이 없다. 그래서 동의 자료를 두 길에서 읽는다:
 *   ① 회원 메타 — 이름에 sms · market · agree … 가 든 키를 찾아 보여 주고 사장님이 하나를 못 박는다 (옵션 `duckhoo_crm_sms_key`)
 *   ② 붙여 넣은 명단 — 아임웹 · 문자 사이트 내보내기처럼 「전화번호 + 수신동의」 칸이 있는 표 (옵션 `duckhoo_crm_sms_roster`)
 * 둘 다 없으면 전원 「기록 없음」이고 양식 파일은 비어 나간다 — 동의를 모르는 사람에게는 보내지 않는 쪽.
 *
 * 읽기 전용 — 회원 · 주문에 쓰지 않는다. 쓰는 것은 옵션 둘뿐.
 *
 * @package Duckhoo\Redesign
 */

namespace Duckhoo\Redesign\Crm;

defined( 'ABSPATH' ) || exit;

const SMS_KEY_OPT    = 'duckhoo_crm_sms_key';
const SMS_ROSTER_OPT = 'duckhoo_crm_sms_roster';

/* ── 순수 함수 ───────────────────────────────────────────────────────── */

/** 「동의」로 읽는 값들 (소문자 · 앞뒤 빈칸 뺀 뒤). 필터 `duckhoo_crm_sms_yes`. */
function sms_yes_values(): array {
	return (array) apply_filters( 'duckhoo_crm_sms_yes', array( '1', 'y', 'yes', 'true', 'on', 'ok', 'agree', 'agreed', 'subscribed', '동의', '수신동의', '수신 동의', '동의함', '수신', '예', 'o' ) );
}

/** 「거부」로 읽는 값들. 필터 `duckhoo_crm_sms_no`. */
function sms_no_values(): array {
	return (array) apply_filters( 'duckhoo_crm_sms_no', array( '0', 'n', 'no', 'false', 'off', 'disagree', 'unsubscribed', 'none', '거부', '미동의', '수신거부', '수신 거부', '동의안함', '동의 안함', '아니오', 'x', '-' ) );
}

/** 값 하나를 'yes' · 'no' · ''(모름) 으로. */
function consent_word( string $v ): string {
	$v = mb_strtolower( trim( $v ) );
	if ( '' === $v ) {
		return '';
	}
	if ( in_array( $v, sms_yes_values(), true ) ) {
		return 'yes';
	}
	if ( in_array( $v, sms_no_values(), true ) ) {
		return 'no';
	}
	return '';
}

/** `01012345678` → `010-1234-5678` (문자 사이트가 읽는 꼴). 9~10자리는 지역번호 꼴로. */
function phone_dash( string $p ): string {
	$d = preg_replace( '/\D+/', '', $p );
	$n = strlen( $d );
	if ( 11 === $n ) {
		return substr( $d, 0, 3 ) . '-' . substr( $d, 3, 4 ) . '-' . substr( $d, 7 );
	}
	if ( 10 === $n ) {
		return str_starts_with( $d, '02' ) ? substr( $d, 0, 2 ) . '-' . substr( $d, 2, 4 ) . '-' . substr( $d, 6 ) : substr( $d, 0, 3 ) . '-' . substr( $d, 3, 3 ) . '-' . substr( $d, 6 );
	}
	if ( 9 === $n ) {
		return substr( $d, 0, 2 ) . '-' . substr( $d, 2, 3 ) . '-' . substr( $d, 5 );
	}
	return $d;
}

/**
 * 붙여 넣은 표에서 「전화번호 · 수신동의」 두 칸을 찾아 동의/거부 번호를 뽑는다.
 * 머리줄(탭 · 쉼표 · 세미콜론으로 나뉜 첫 줄)에서 칸 이름을 보고, 머리줄이 없으면 줄마다 번호 + 동의/거부 낱말로 가른다.
 *
 * @return array{yes:array<string,true>,no:array<string,true>,rows:int,cols:array{phone:string,sms:string}}
 */
function roster_parse_consent( string $text ): array {
	$yes   = array();
	$no    = array();
	$rows  = 0;
	$cols  = array( 'phone' => '', 'sms' => '' );
	$lines = array_values( array_filter( preg_split( '/\r\n|\r|\n/', $text ), fn( $l ) => '' !== trim( $l ) ) );
	if ( ! $lines ) {
		return array( 'yes' => $yes, 'no' => $no, 'rows' => 0, 'cols' => $cols );
	}
	$split = function ( string $l ): array {
		$sep = str_contains( $l, "\t" ) ? "\t" : ( substr_count( $l, ',' ) >= 2 ? ',' : ( substr_count( $l, ';' ) >= 2 ? ';' : '' ) );
		if ( '' === $sep ) {
			return array( $l );
		}
		return array_map( fn( $c ) => trim( $c, " \t\"'" ), explode( $sep, $l ) );
	};
	$head = $split( $lines[0] );
	$pi   = -1;
	$si   = -1;
	foreach ( $head as $i => $h ) {
		$hl = mb_strtolower( $h );
		if ( $pi < 0 && preg_match( '/휴대|전화|연락|phone|mobile|hp|tel/u', $hl ) ) {
			$pi = $i;
		}
		if ( $si < 0 && preg_match( '/sms|문자|수신|마케팅|광고|marketing|consent|opt/u', $hl ) ) {
			$si = $i;
		}
	}
	if ( $pi >= 0 && $si >= 0 ) {
		$cols = array( 'phone' => $head[ $pi ], 'sms' => $head[ $si ] );
		foreach ( array_slice( $lines, 1 ) as $l ) {
			$c = $split( $l );
			$p = phone_norm( (string) ( $c[ $pi ] ?? '' ) );
			if ( '' === $p ) {
				continue;
			}
			++$rows;
			$w = consent_word( (string) ( $c[ $si ] ?? '' ) );
			if ( 'yes' === $w ) {
				$yes[ $p ] = true;
			} elseif ( 'no' === $w ) {
				$no[ $p ] = true;
			}
		}
		return array( 'yes' => $yes, 'no' => $no, 'rows' => $rows, 'cols' => $cols );
	}
	// 머리줄이 없다 — 줄마다 번호 하나 + 동의/거부 낱말.
	foreach ( $lines as $l ) {
		if ( ! preg_match( '/(?:\+?82[\s-]?|0)1[016789][\s-]?\d{3,4}[\s-]?\d{4}\b/u', $l, $m ) ) {
			continue;
		}
		$p = phone_norm( $m[0] );
		if ( '' === $p ) {
			continue;
		}
		++$rows;
		$rest = mb_strtolower( str_replace( $m[0], ' ', $l ) );
		if ( preg_match( '/수신거부|미동의|동의\s*안|거부|unsubscribed|\bno\b|\bn\b/u', $rest ) ) {
			$no[ $p ] = true;
		} elseif ( preg_match( '/동의|agree|subscribed|\byes\b|\by\b/u', $rest ) ) {
			$yes[ $p ] = true;
		}
	}
	return array( 'yes' => $yes, 'no' => $no, 'rows' => $rows, 'cols' => $cols );
}

/**
 * 한 사람의 동의 판정 — 회원 메타가 먼저, 없으면 붙여 넣은 명단, 둘 다 없으면 ''(기록 없음).
 *
 * @param string $meta_word 회원 메타에서 읽은 'yes' · 'no' · ''.
 * @param array{yes:array<string,true>,no:array<string,true>} $roster
 */
function consent_of( string $phone, string $meta_word, array $roster ): string {
	if ( 'yes' === $meta_word || 'no' === $meta_word ) {
		return $meta_word;
	}
	if ( '' !== $phone ) {
		if ( isset( $roster['yes'][ $phone ] ) ) {
			return 'yes';
		}
		if ( isset( $roster['no'][ $phone ] ) ) {
			return 'no';
		}
	}
	return '';
}

/**
 * 줄마다 `sms`('yes' · 'no' · '') 를 붙인다 — 순수. 비회원(u=0)은 명단으로만 판정된다.
 *
 * @param array<int,array<string,mixed>> $rows
 * @param array<int,string>              $meta uid => 'yes'|'no'|''.
 */
function attach_sms( array $rows, array $meta, array $roster ): array {
	foreach ( $rows as &$r ) {
		$u        = (int) ( $r['u'] ?? 0 );
		$r['sms'] = consent_of( (string) ( $r['phone'] ?? '' ), $u > 0 ? (string) ( $meta[ $u ] ?? '' ) : '', $roster );
	}
	unset( $r );
	return $rows;
}

/** 동의 · 거부 · 기록 없음 수. */
function sms_counts( array $rows ): array {
	$c = array( 'yes' => 0, 'no' => 0, 'unknown' => 0 );
	foreach ( $rows as $r ) {
		$w = (string) ( $r['sms'] ?? '' );
		++$c[ 'yes' === $w ? 'yes' : ( 'no' === $w ? 'no' : 'unknown' ) ];
	}
	return $c;
}

/**
 * 문자 사이트 양식 — 사장님이 올린 `tothemoon_20261008.xls` 그대로: EUC-KR HTML 표, 칸 NO · 그룹명 · 이름 · 전화번호 · 메모.
 * `$group` 이 비어 있으면 줄의 `group` 칸을 쓴다 (한 사람에 한 통 탭 — 줄마다 주 명단이 다르다).
 *
 * @param array<int,array<string,mixed>> $rows 이미 동의한 사람만 걸러 넘긴다.
 * @return string EUC-KR(CP949) 바이트.
 */
function xls_html( array $rows, string $group = '' ): string {
	$e = fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
	$h = '<meta http-equiv="Content-Type" content="application/vnd.ms-excel; charset=euc-kr">' . "\r\n";
	$h .= '<table border="1" cellpadding="1" cellspacing="0">' . "\r\n";
	$h .= "\t<tr><td><b>NO</b></td><td><b>그룹명</b></td><td><b>이름</b></td><td><b>전화번호</b></td><td><b>메모</b></td></tr>\r\n";
	$i = 0;
	foreach ( $rows as $r ) {
		$p = phone_norm( (string) ( $r['phone'] ?? '' ) );
		if ( '' === $p ) {
			continue;
		}
		++$i;
		$g    = '' !== $group ? $group : (string) ( $r['group'] ?? '' );
		$memo = trim( (string) ( $r['why'] ?? '' ) . ( ! empty( $r['note'] ) ? ' / ' . $r['note'] : '' ) );
		$h   .= "\t<tr><td>{$i}</td><td>" . $e( $g ) . '</td><td>' . $e( $r['name'] ?? '' ) . '</td><td>' . $e( phone_dash( $p ) ) . '</td><td>' . $e( $memo ) . "</td></tr>\r\n";
	}
	$h .= "</table>\r\n";
	$out = @iconv( 'UTF-8', 'CP949//TRANSLIT//IGNORE', $h );
	if ( false === $out || '' === $out ) {
		$out = mb_convert_encoding( $h, 'CP949', 'UTF-8' );
	}
	return (string) $out;
}

/* ── 읽기 (워드프레스 안에서만) ───────────────────────────────────────── */

/** 못 박은 회원 메타 키 (없으면 ''). */
function sms_key(): string {
	$k = (string) get_option( SMS_KEY_OPT, '' );
	if ( '' === $k ) {
		$k = auto_sms_key( sms_candidates() );
	}
	return (string) apply_filters( 'duckhoo_crm_sms_key', $k );
}

/** 못 박은 키가 없을 때 — 후보 중 이름에 sms · 문자가 들고 동의/거부 값이 실제로 있는 키가 **하나뿐**이면 그것. 순수. */
function auto_sms_key( array $cands ): string {
	$hit = array();
	foreach ( $cands as $c ) {
		if ( preg_match( '/sms|문자/i', (string) $c['key'] ) && ( (int) $c['yes'] + (int) $c['no'] ) > 0 ) {
			$hit[] = (string) $c['key'];
		}
	}
	return 1 === count( $hit ) ? $hit[0] : '';
}

/** 붙여 넣어 둔 명단 — {yes, no, rows, cols, at, n}. */
function sms_roster(): array {
	$r = get_option( SMS_ROSTER_OPT, array() );
	return is_array( $r ) ? $r + array( 'yes' => array(), 'no' => array(), 'rows' => 0, 'at' => 0 ) : array( 'yes' => array(), 'no' => array(), 'rows' => 0, 'at' => 0 );
}

/**
 * 동의 칸일 수 있는 회원 메타 키 — 이름에 sms · market · agree · consent · 수신 … 이 든 것. 1시간 캐시.
 *
 * @return array<int,array{key:string,users:int,yes:int,no:int,other:int,sample:string[]}>
 */
function sms_candidates( bool $fresh = false ): array {
	global $wpdb;
	if ( ! isset( $wpdb ) ) {
		return array();
	}
	$ck = 'dhr_crm_sms_keys';
	if ( ! $fresh ) {
		$c = get_transient( $ck );
		if ( is_array( $c ) ) {
			return $c;
		}
	}
	$keys = (array) $wpdb->get_results( "SELECT meta_key, COUNT(DISTINCT user_id) AS n FROM {$wpdb->usermeta} WHERE meta_key REGEXP 'sms|market|agree|consent|optin|opt_in|receive|newsletter|promo|advert|수신|광고|마케팅|동의' AND meta_key NOT LIKE '%nonce%' GROUP BY meta_key ORDER BY n DESC LIMIT 40", ARRAY_A ); // phpcs:ignore WordPress.DB
	$out  = array();
	foreach ( $keys as $k ) {
		$key  = (string) $k['meta_key'];
		$vals = (array) $wpdb->get_results( $wpdb->prepare( "SELECT meta_value AS v, COUNT(*) AS n FROM {$wpdb->usermeta} WHERE meta_key = %s GROUP BY meta_value ORDER BY n DESC LIMIT 8", $key ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$yes  = 0;
		$no   = 0;
		$oth  = 0;
		$smp  = array();
		foreach ( $vals as $v ) {
			$w = consent_word( (string) $v['v'] );
			if ( 'yes' === $w ) {
				$yes += (int) $v['n'];
			} elseif ( 'no' === $w ) {
				$no += (int) $v['n'];
			} else {
				$oth += (int) $v['n'];
			}
			$smp[] = mb_substr( (string) $v['v'], 0, 24 ) . '(' . (int) $v['n'] . ')';
		}
		$span  = $wpdb->get_row( $wpdb->prepare( "SELECT MIN(u.user_registered) AS a, MAX(u.user_registered) AS b FROM {$wpdb->users} u INNER JOIN {$wpdb->usermeta} m ON m.user_id = u.ID AND m.meta_key = %s", $key ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$out[] = array( 'key' => $key, 'users' => (int) $k['n'], 'yes' => $yes, 'no' => $no, 'other' => $oth, 'sample' => $smp, 'from' => substr( (string) ( $span['a'] ?? '' ), 0, 10 ), 'to' => substr( (string) ( $span['b'] ?? '' ), 0, 10 ) );
	}
	set_transient( $ck, $out, HOUR_IN_SECONDS );
	return $out;
}

/** 회원마다 못 박은 키의 값을 'yes' · 'no' · '' 로. uid => word. */
function user_consent( array $uids ): array {
	global $wpdb;
	$key  = sms_key();
	$out  = array();
	$uids = array_values( array_unique( array_filter( array_map( 'intval', $uids ) ) ) );
	if ( '' === $key || ! $uids || ! isset( $wpdb ) ) {
		return $out;
	}
	foreach ( array_chunk( $uids, 500 ) as $chunk ) {
		$in   = implode( ',', $chunk );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s AND user_id IN ({$in})", $key ), ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( $rows as $r ) {
			$out[ (int) $r['user_id'] ] = consent_word( (string) $r['meta_value'] );
		}
	}
	return $out;
}

/** 화면 · 내보내기용 — 줄에 `sms` 를 붙인다. */
function with_sms( array $rows ): array {
	return attach_sms( $rows, user_consent( array_column( $rows, 'u' ) ), sms_roster() );
}

/** 동의 자료가 하나라도 있는가 (키 또는 명단). */
function sms_source_ok(): bool {
	return '' !== sms_key() || ! empty( sms_roster()['yes'] ) || ! empty( sms_roster()['no'] );
}

/* ── 설정 저장 (admin-post) ──────────────────────────────────────────── */

function save_sms_key(): void {
	if ( ! may() ) {
		wp_die( '권한이 없습니다.' );
	}
	check_admin_referer( 'dhr-crm-sms' );
	$k = isset( $_POST['sms_key'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['sms_key'] ) ) : '';
	update_option( SMS_KEY_OPT, $k, false );
	$back = isset( $_POST['back'] ) ? esc_url_raw( wp_unslash( (string) $_POST['back'] ) ) : admin_url( 'admin.php?page=' . SLUG );
	wp_safe_redirect( add_query_arg( 'dhr_sms_saved', 'key', $back ) );
	exit;
}
add_action( 'admin_post_dhr_crm_sms_key', __NAMESPACE__ . '\\save_sms_key' );

function save_sms_roster(): void {
	if ( ! may() ) {
		wp_die( '권한이 없습니다.' );
	}
	check_admin_referer( 'dhr-crm-sms' );
	$back = isset( $_POST['back'] ) ? esc_url_raw( wp_unslash( (string) $_POST['back'] ) ) : admin_url( 'admin.php?page=' . SLUG );
	if ( isset( $_POST['clear'] ) ) {
		delete_option( SMS_ROSTER_OPT );
		wp_safe_redirect( add_query_arg( 'dhr_sms_saved', 'cleared', $back ) );
		exit;
	}
	$text = isset( $_POST['roster'] ) ? (string) wp_unslash( $_POST['roster'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- 표 전체, 줄마다 아래서 가른다
	$p    = roster_parse_consent( $text );
	$old  = sms_roster();
	$mode = isset( $_POST['mode'] ) && 'replace' === $_POST['mode'] ? 'replace' : 'merge';
	$yes  = 'merge' === $mode ? ( (array) $old['yes'] + $p['yes'] ) : $p['yes'];
	$no   = 'merge' === $mode ? ( (array) $old['no'] + $p['no'] ) : $p['no'];
	// 같은 번호가 새 표에서 거부면 거부가 이긴다 (최근 뜻이 우선).
	foreach ( $p['no'] as $ph => $_ ) {
		unset( $yes[ $ph ] );
	}
	foreach ( $p['yes'] as $ph => $_ ) {
		unset( $no[ $ph ] );
	}
	update_option( SMS_ROSTER_OPT, array( 'yes' => $yes, 'no' => $no, 'rows' => (int) $p['rows'], 'cols' => $p['cols'], 'at' => time(), 'n' => count( $yes ) + count( $no ) ), false );
	wp_safe_redirect( add_query_arg( array( 'dhr_sms_saved' => 'roster', 'dhr_sms_rows' => (int) $p['rows'], 'dhr_sms_yes' => count( $p['yes'] ), 'dhr_sms_no' => count( $p['no'] ) ), $back ) );
	exit;
}
add_action( 'admin_post_dhr_crm_sms_roster', __NAMESPACE__ . '\\save_sms_roster' );

/* ── 화면 조각 ───────────────────────────────────────────────────────── */

/** 동의 자료 상자 — 후보 키 고르기 · 명단 붙여 넣기. */
function sms_box( string $back ): void {
	$key    = sms_key();
	$roster = sms_roster();
	$cands  = sms_candidates();
	echo '<details class="dhr-crm-adv" style="margin:0 0 14px"' . ( sms_source_ok() ? '' : ' open' ) . '><summary style="cursor:pointer;font-weight:700">SMS 수신 동의 자료 — ' . ( '' !== $key ? '회원 메타 <code>' . esc_html( $key ) . '</code>' : '회원 메타 키 없음' ) . ' · 붙여 넣은 명단 ' . ( $roster['at'] ? esc_html( number_format_i18n( count( (array) $roster['yes'] ) ) . '명 동의 · ' . number_format_i18n( count( (array) $roster['no'] ) ) . '명 거부 (' . wp_date( 'm.d H:i', (int) $roster['at'] ) . ')' ) : '없음' ) . '</summary>';
	echo '<p style="margin:10px 0 6px">가입 약관 화면의 「SMS 수신 동의」 체크박스는 지금은 다음 장으로 넘길 때 실리지 않습니다 (2026-10-08 확인). 회원 메타에 남은 기록은 일부 회원뿐이라, 동의 자료를 아래 둘에서 읽습니다. 둘 다 없으면 전원 「기록 없음」이고 문자 사이트 양식 파일은 비어 나갑니다 — 동의를 모르는 사람에게는 보내지 않는 쪽입니다.</p>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:0 0 12px">';
	wp_nonce_field( 'dhr-crm-sms' );
	echo '<input type="hidden" name="action" value="dhr_crm_sms_key"><input type="hidden" name="back" value="' . esc_attr( $back ) . '">';
	echo '<b>① 회원 메타에서 읽기</b> — 이름에 sms · 수신 · 동의 · marketing 이 든 키를 찾았습니다. 「동의/거부」 값이 갈리는 칸을 고르세요.<br>';
	$pinned = (string) get_option( SMS_KEY_OPT, '' );
	if ( '' === $pinned && '' !== $key ) {
		echo '<span class="dhr-sl-note">아직 고르지 않아 <code>' . esc_html( $key ) . '</code> 를 자동으로 쓰고 있습니다 (이름에 sms 가 든 키가 하나뿐). 저장하면 그것으로 못 박힙니다.</span><br>';
	}
	echo '<label><input type="radio" name="sms_key" value=""' . ( '' === $pinned ? ' checked' : '' ) . '> ' . ( '' !== $key && '' === $pinned ? '자동 (지금 ' . esc_html( $key ) . ')' : '쓰지 않음' ) . '</label><br>';
	if ( ! $cands ) {
		echo '<span class="dhr-sl-note">후보 키가 없습니다 — 회원 메타에 수신 동의 칸이 없습니다.</span><br>';
	}
	foreach ( $cands as $c ) {
		echo '<label><input type="radio" name="sms_key" value="' . esc_attr( $c['key'] ) . '"' . ( $c['key'] === $pinned ? ' checked' : '' ) . '> <code>' . esc_html( $c['key'] ) . '</code> — ' . esc_html( number_format_i18n( $c['users'] ) ) . '명 · 동의로 읽힘 ' . (int) $c['yes'] . ' · 거부 ' . (int) $c['no'] . ' · 모름 ' . (int) $c['other'] . ' <span class="dhr-sl-note">값: ' . esc_html( implode( ', ', $c['sample'] ) ) . ( ! empty( $c['from'] ) ? ' · 이 키가 있는 회원의 가입일 ' . esc_html( $c['from'] ) . ' ~ ' . esc_html( $c['to'] ) : '' ) . '</span></label><br>';
	}
	echo '<button class="button" style="margin-top:6px">이 키로 읽기</button></form>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'dhr-crm-sms' );
	echo '<input type="hidden" name="action" value="dhr_crm_sms_roster"><input type="hidden" name="back" value="' . esc_attr( $back ) . '">';
	echo '<b>② 명단 붙여 넣기</b> — 아임웹 회원 내보내기 · 문자 사이트 주소록처럼 <b>전화번호</b> 칸과 <b>수신동의</b> 칸이 있는 표를 머리줄부터 통째로 (엑셀에서 복사). 번호와 동의/거부만 저장하고 나머지는 버립니다.<br>';
	echo '<textarea name="roster" rows="5" style="width:100%;max-width:760px;font-family:monospace;font-size:12px" placeholder="이름&#9;휴대폰&#9;SMS수신동의&#10;홍길동&#9;010-1234-5678&#9;Y"></textarea><br>';
	echo '<label><input type="radio" name="mode" value="merge" checked> 지금 것에 더하기</label> <label><input type="radio" name="mode" value="replace"> 전부 바꾸기</label> ';
	echo '<button class="button">명단 저장</button> ';
	if ( $roster['at'] ) {
		echo '<button class="button" name="clear" value="1" onclick="return confirm(\'붙여 넣은 명단을 지울까요?\')">명단 지우기</button>';
		if ( ! empty( $roster['cols']['phone'] ) ) {
			echo ' <span class="dhr-sl-note">마지막 표의 칸: 번호 「' . esc_html( $roster['cols']['phone'] ) . '」 · 동의 「' . esc_html( $roster['cols']['sms'] ) . '」 · ' . (int) $roster['rows'] . '줄</span>';
		}
	}
	echo '</form></details>';
}

/** 저장 뒤 알림 한 줄. */
function sms_saved_notice(): void {
	$s = isset( $_GET['dhr_sms_saved'] ) ? (string) $_GET['dhr_sms_saved'] : ''; // phpcs:ignore WordPress.Security.NonceVerification
	if ( '' === $s ) {
		return;
	}
	if ( 'roster' === $s ) {
		echo '<div class="notice notice-success"><p>명단을 읽었습니다 — ' . (int) ( $_GET['dhr_sms_rows'] ?? 0 ) . '줄에서 동의 ' . (int) ( $_GET['dhr_sms_yes'] ?? 0 ) . '명 · 거부 ' . (int) ( $_GET['dhr_sms_no'] ?? 0 ) . '명. 0 · 0 이면 표에 전화번호 칸이나 수신동의 칸을 못 찾은 것입니다 — 머리줄부터 붙였는지 보세요.</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification
	} elseif ( 'key' === $s ) {
		echo '<div class="notice notice-success"><p>동의를 읽을 회원 메타 키를 저장했습니다.</p></div>';
	} elseif ( 'cleared' === $s ) {
		echo '<div class="notice notice-success"><p>붙여 넣은 명단을 지웠습니다.</p></div>';
	} elseif ( 'excl' === $s ) {
		echo '<div class="notice notice-success"><p>제외 명단을 저장했습니다.</p></div>';
	} elseif ( 'sentdel' === $s ) {
		echo '<div class="notice notice-success"><p>보낸 기록 한 묶음을 지웠습니다.</p></div>';
	} elseif ( 'reward' === $s ) {
		echo '<div class="notice notice-success"><p>보상 라벨을 저장했습니다 — 그룹명 뒤에 붙습니다.</p></div>';
	}
}

/* ── 문자 사이트 양식 내보내기 (admin-post) ─────────────────────────── */

/** 지금 탭 · 조각의 줄을 화면 · CSV · xls 가 똑같이 쓰도록 한 곳에서 — 기준 · 메모를 다듬고 `group` 을 붙인다. */
function export_rows( string $seg, array $opt, array $inc = array(), bool $fresh = false ): array {
	$name = fn( string $k ) => segs()[ $k ] ?? $k;
	if ( 'all' === $seg ) {
		$rows = combined( $inc, $fresh )['rows'];
		foreach ( $rows as &$r ) {
			$r['group'] = $name( (string) $r['seg'] ) . ( 'rfm' === $r['seg'] && preg_match( '/^(\S+(?: \S+)?) \(R\d/u', (string) $r['why'], $m ) ? ' ' . $m[1] : '' );
			$r['why']   = $name( (string) $r['seg'] ) . ' — ' . $r['why'];
			if ( ! empty( $r['also'] ) ) {
				$r['note'] = trim( ( ! empty( $r['note'] ) ? $r['note'] . ' / ' : '' ) . '다른 명단: ' . implode( ' · ', array_map( $name, (array) $r['also'] ) ) );
			}
		}
		unset( $r );
		return with_sms( with_reward( $rows, rewards() ) );
	}
	$d    = data( $seg, $opt, $fresh );
	$rows = dedupe( filter_only( $d['rows'], $opt['only'] ) );
	$g    = $name( $seg ) . ( 'rfm' === $seg && '' !== $opt['only'] ? ' ' . $opt['only'] : '' );
	foreach ( $rows as &$r ) {
		$r['group'] = $g;
		$r['part']  = reward_key( $seg, (string) $opt['only'] );
	}
	unset( $r );
	return with_sms( with_reward( $rows, rewards() ) );
}

/** `.xls` 내보내기 — 동의한 사람만 (`sms=all` 이면 전원 · 거부는 그래도 뺀다). 그룹명은 `grp` 로 덮을 수 있다. */
function xls(): void {
	if ( ! may() ) {
		wp_die( '권한이 없습니다.' );
	}
	check_admin_referer( 'dhr-crm-xls' );
	$seg = isset( $_GET['seg'] ) && isset( segs()[ $_GET['seg'] ] ) ? (string) $_GET['seg'] : 'abandon';
	$opt = opts( $seg );
	$inc = 'all' === $seg ? chosen_parts() : array();
	$rows = export_rows( $seg, $opt, $inc );
	$mode = isset( $_GET['sms'] ) && 'all' === $_GET['sms'] ? 'all' : 'yes';
	$rows = array_values( array_filter( $rows, fn( $r ) => 'yes' === $r['sms'] || ( 'all' === $mode && 'no' !== $r['sms'] ) ) );
	[ $rows ] = apply_skip( $rows ); // 최근에 받은 사람은 파일에도 안 들어간다
	[ $rows ] = apply_exclude( $rows, $seg ); // 제외 명단
	$grp  = isset( $_GET['grp'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['grp'] ) ) : '';
	if ( 'all' === $seg ) {
		$grp = ''; // 줄마다 주 명단이 그룹명
	}
	if ( '' !== $grp ) {
		foreach ( $rows as &$r ) {
			$r['group'] = $grp;
		}
		unset( $r );
	}
	record_sent( $rows, $seg, $grp ); // 보낸 기록 — 이 파일에 든 사람
	$body = xls_html( $rows, $grp );
	nocache_headers();
	header( 'Content-Type: application/vnd.ms-excel; charset=euc-kr' );
	header( 'Content-Disposition: attachment; filename="tothemoon_' . $seg . ( $opt['only'] ? '-' . rawurlencode( $opt['only'] ) : '' ) . '_' . wp_date( 'Ymd' ) . '.xls"' );
	header( 'Content-Length: ' . strlen( $body ) );
	echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- 바이너리(EUC-KR) 파일 본문
	exit;
}
add_action( 'admin_post_dhr_crm_xls', __NAMESPACE__ . '\\xls' );
