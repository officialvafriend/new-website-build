<?php
/**
 * 도구 → 성인인증 점검 (관리자 · 읽기 전용).
 *
 * 가입 흐름의 성인인증은 테마가 두 겹으로 건다 (`wd_is_adult( $birth, 19 )` —
 * 본인인증 결과 확인 AJAX · 가입 폼 제출). **그 판정이 걸리기 전에 가입한 회원**은
 * 인증을 한 번도 안 거쳤을 수 있고, 로그인만 되면 결제까지 간다.
 *
 * 테마가 인증을 마친 회원에게 `wd_phone_verified = 1` 을 적는다 (`functions.php:10252`
 * 「인증 상태 메타 (게이트 판정 기준)」). 그 칸이 없는 회원을 센다.
 *
 * **읽기만 한다.** 회원 · 주문에 한 글자도 쓰지 않는다. 무엇을 할지는 사장님이 정한다.
 *
 * @package Duckhoo\Redesign
 */

declare( strict_types = 1 );

namespace Duckhoo\Redesign\Verify\Admin;

defined( 'ABSPATH' ) || exit;

const SLUG = 'duckhoo-verify';

/**
 * 볼 수 있는가.
 *
 * @return bool
 */
function may(): bool {
	return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
}

/**
 * 메뉴.
 *
 * @return void
 */
function menu(): void {
	if ( ! may() ) {
		return;
	}
	add_management_page(
		'성인인증 점검',
		'성인인증 점검',
		current_user_can( 'manage_woocommerce' ) ? 'manage_woocommerce' : 'manage_options',
		SLUG,
		__NAMESPACE__ . '\\screen'
	);
}
add_action( 'admin_menu', __NAMESPACE__ . '\\menu' );

/**
 * 인증 표시 메타 키 (테마 `wd_phone_verified`).
 *
 * @return string
 */
function meta_key(): string {
	return (string) apply_filters( 'duckhoo_verified_meta_key', 'wd_phone_verified' );
}

/**
 * 세지 않는 역할 — 사는 사람이 아니다.
 *
 * @return string[]
 */
function staff_roles(): array {
	return (array) apply_filters( 'duckhoo_verify_staff_roles', array( 'administrator', 'shop_manager', 'editor', 'author' ) );
}

/**
 * 이름이 두 글자뿐인가 (성이 빠진 옛 가입). 입금자명 불일치의 한 원인.
 *
 * @param string $name 이름.
 * @return bool
 */
function two_char( string $name ): bool {
	$n = preg_replace( '/\s+/u', '', $name );
	return null !== $n && '' !== $n && mb_strlen( $n ) <= 2 && (bool) preg_match( '/^[\x{AC00}-\x{D7A3}]+$/u', $n );
}

/**
 * 회원 한 줄을 갈래짓는다.
 *
 * @param array<string,mixed> $u        id · name · email · registered · verified(bool) · orders(int) · last(string).
 * @param int                 $now      지금 (timestamp).
 * @param int                 $recent_d 최근으로 볼 날수.
 * @return array<string,mixed> 위 값 + two_char · recent(bool).
 */
function classify( array $u, int $now, int $recent_d = 90 ): array {
	$last          = trim( (string) ( $u['last'] ?? '' ) );
	$u['two_char'] = two_char( (string) ( $u['name'] ?? '' ) );
	$vn            = preg_replace( '/\s+/u', '', (string) ( $u['vname'] ?? '' ) );
	$nm            = preg_replace( '/\s+/u', '', (string) ( $u['name'] ?? '' ) );
	$u['differs']  = '' !== $vn && $vn !== $nm; // 회원 이름 ≠ 인증기관이 준 이름 (주문자명은 회원 이름을 쓴다)
	$u['recent']   = '' !== $last && ( $now - (int) strtotime( $last . ' UTC' ) ) <= $recent_d * DAY_IN_SECONDS;
	$u['orders']   = (int) ( $u['orders'] ?? 0 );
	$u['verified'] = (bool) ( $u['verified'] ?? false );
	return $u;
}

/**
 * 요약 숫자.
 *
 * @param array<int,array<string,mixed>> $rows classify() 를 거친 줄들.
 * @return array<string,int>
 */
function summary( array $rows ): array {
	$s = array( 'all' => 0, 'verified' => 0, 'unverified' => 0, 'unv_orders' => 0, 'unv_recent' => 0, 'two_char' => 0, 'two_char_unv' => 0, 'differs' => 0 );
	foreach ( $rows as $r ) {
		++$s['all'];
		if ( ! empty( $r['verified'] ) ) {
			++$s['verified'];
		} else {
			++$s['unverified'];
			if ( (int) $r['orders'] > 0 ) {
				++$s['unv_orders'];
			}
			if ( ! empty( $r['recent'] ) ) {
				++$s['unv_recent'];
			}
			if ( ! empty( $r['two_char'] ) ) {
				++$s['two_char_unv'];
			}
		}
		if ( ! empty( $r['two_char'] ) ) {
			++$s['two_char'];
		}
		if ( ! empty( $r['differs'] ) ) {
			++$s['differs'];
		}
	}
	return $s;
}

/**
 * 안 세는 주문 상태.
 *
 * @return string[]
 */
function dead_statuses(): array {
	return (array) apply_filters( 'duckhoo_verify_dead_statuses', array( 'wc-cancelled', 'wc-refunded', 'wc-failed', 'wc-checkout-draft', 'trash', 'auto-draft' ) );
}

/**
 * 회원별 주문 수 · 마지막 주문 — 한 번의 질의. HPOS 면 `wc_orders`, 아니면 posts + `_customer_user`.
 *
 * @return array<int,array{n:int,last:string}>
 */
function order_map(): array {
	global $wpdb;
	if ( ! isset( $wpdb ) ) {
		return array();
	}
	$dead = dead_statuses();
	$ph   = implode( ',', array_fill( 0, count( $dead ), '%s' ) );
	$hpos = class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' )
		&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	if ( $hpos ) {
		$sql = 'SELECT customer_id AS uid, COUNT(*) AS n, MAX(date_created_gmt) AS last
			FROM ' . $wpdb->prefix . 'wc_orders
			WHERE type = %s AND customer_id > 0 AND status NOT IN (' . $ph . ')
			GROUP BY customer_id';
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( 'shop_order' ), $dead ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
	} else {
		$sql = 'SELECT CAST(m.meta_value AS UNSIGNED) AS uid, COUNT(*) AS n, MAX(p.post_date_gmt) AS last
			FROM ' . $wpdb->posts . ' p
			JOIN ' . $wpdb->postmeta . ' m ON m.post_id = p.ID AND m.meta_key = %s
			WHERE p.post_type = %s AND p.post_status NOT IN (' . $ph . ') AND m.meta_value <> %s AND m.meta_value <> %s
			GROUP BY m.meta_value';
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( '_customer_user', 'shop_order' ), $dead, array( '0', '' ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}
	$out = array();
	foreach ( $rows as $r ) {
		$out[ (int) $r['uid'] ] = array( 'n' => (int) $r['n'], 'last' => (string) $r['last'] );
	}
	return $out;
}

/**
 * 회원 전부 — 인증 표시 · 이름 · 가입일. 직원 역할은 뺀다.
 *
 * @return array<int,array<string,mixed>>
 */
function members(): array {
	global $wpdb;
	if ( ! isset( $wpdb ) ) {
		return array();
	}
	$key = meta_key();
	$sql = 'SELECT u.ID, u.user_email, u.display_name, u.user_registered,
			v.meta_value AS verified, f.meta_value AS fn, l.meta_value AS ln, c.meta_value AS caps, n.meta_value AS vname, p.meta_value AS ph1, q.meta_value AS ph2
		FROM ' . $wpdb->users . ' u
		LEFT JOIN ' . $wpdb->usermeta . ' v ON v.user_id = u.ID AND v.meta_key = %s
		LEFT JOIN ' . $wpdb->usermeta . ' n ON n.user_id = u.ID AND n.meta_key = %s
		LEFT JOIN ' . $wpdb->usermeta . ' p ON p.user_id = u.ID AND p.meta_key = %s
		LEFT JOIN ' . $wpdb->usermeta . ' q ON q.user_id = u.ID AND q.meta_key = %s
		LEFT JOIN ' . $wpdb->usermeta . ' f ON f.user_id = u.ID AND f.meta_key = %s
		LEFT JOIN ' . $wpdb->usermeta . ' l ON l.user_id = u.ID AND l.meta_key = %s
		LEFT JOIN ' . $wpdb->usermeta . ' c ON c.user_id = u.ID AND c.meta_key = %s
		ORDER BY u.ID ASC';
	$rows  = (array) $wpdb->get_results( $wpdb->prepare( $sql, $key, 'wd_verified_name', 'wd_phone', 'billing_phone', 'first_name', 'last_name', $wpdb->prefix . 'capabilities' ), ARRAY_A ); // phpcs:ignore WordPress.DB
	$staff = staff_roles();
	$out   = array();
	foreach ( $rows as $r ) {
		$caps = is_string( $r['caps'] ) ? (array) maybe_unserialize( $r['caps'] ) : array();
		if ( array_intersect( array_keys( $caps ), $staff ) ) {
			continue;
		}
		$name  = trim( (string) $r['ln'] . (string) $r['fn'] );
		$out[] = array(
			'id'         => (int) $r['ID'],
			'name'       => '' !== $name ? $name : (string) $r['display_name'],
			'email'      => (string) $r['user_email'],
			'registered' => (string) $r['user_registered'],
			'verified'   => '1' === (string) $r['verified'] || 'yes' === (string) $r['verified'],
			'vname'      => trim( (string) $r['vname'] ),
			'phone'      => norm_phone( (string) $r['ph1'] ) ?: norm_phone( (string) $r['ph2'] ),
		);
	}
	return $out;
}

/**
 * 전화번호를 숫자만으로 — `010-1234-5678` · `+82 10 1234 5678` · `01012345678` 이 같은 값이 된다.
 *
 * @param string $v 원문.
 * @return string 11자리 휴대폰이면 그것, 아니면 ''.
 */
function norm_phone( string $v ): string {
	$d = preg_replace( '/\D+/', '', $v );
	if ( null === $d ) {
		return '';
	}
	if ( str_starts_with( $d, '82' ) && strlen( $d ) >= 11 ) {
		$d = '0' . substr( $d, 2 );
	}
	return preg_match( '/^01[016789]\d{7,8}$/', $d ) ? $d : '';
}

/**
 * 붙여 넣은 명단(아임웹 내보내기 · CSV · 엑셀 복사)에서 휴대폰 · 이메일을 뽑는다. 저장하지 않는다.
 *
 * @param string $text 원문.
 * @return array{phones:array<string,true>,emails:array<string,true>,lines:int}
 */
function parse_roster( string $text ): array {
	$phones = array();
	$emails = array();
	$lines  = 0;
	foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
		if ( '' === trim( $line ) ) {
			continue;
		}
		++$lines;
		if ( preg_match_all( '/(?:\+?82[\s-]?|0)1[016789][\s-]?\d{3,4}[\s-]?\d{4}\b/u', $line, $m ) ) {
			foreach ( $m[0] as $raw ) {
				$p = norm_phone( $raw );
				if ( '' !== $p ) {
					$phones[ $p ] = true;
				}
			}
		}
		if ( preg_match_all( '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $line, $m ) ) {
			foreach ( $m[0] as $e ) {
				$emails[ strtolower( $e ) ] = true;
			}
		}
	}
	return array( 'phones' => $phones, 'emails' => $emails, 'lines' => $lines );
}

/**
 * 인증 기록 없는 회원을 명단과 대조한다 — 전화번호가 먼저, 없으면 이메일.
 *
 * @param array<int,array<string,mixed>> $rows   classify() 를 거친 줄들.
 * @param array{phones:array<string,true>,emails:array<string,true>} $roster parse_roster().
 * @return array<int,array<string,mixed>> 인증 없는 줄에 `hit`('phone'|'email'|'') 을 붙여 돌려준다.
 */
function cross( array $rows, array $roster ): array {
	$out = array();
	foreach ( $rows as $r ) {
		if ( ! empty( $r['verified'] ) ) {
			continue;
		}
		$hit = '';
		$ph  = (string) ( $r['phone'] ?? '' );
		$em  = strtolower( trim( (string) ( $r['email'] ?? '' ) ) );
		if ( '' !== $ph && isset( $roster['phones'][ $ph ] ) ) {
			$hit = 'phone';
		} elseif ( '' !== $em && isset( $roster['emails'][ $em ] ) ) {
			$hit = 'email';
		}
		$r['hit'] = $hit;
		$out[]    = $r;
	}
	return $out;
}

/**
 * 화면.
 *
 * @return void
 */
function screen(): void {
	if ( ! may() ) {
		wp_die( '권한이 없습니다.' );
	}
	$t0     = microtime( true );
	$now    = (int) current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
	$orders = order_map();
	$rows   = array();
	foreach ( members() as $m ) {
		$o            = $orders[ $m['id'] ] ?? array( 'n' => 0, 'last' => '' );
		$m['orders']  = $o['n'];
		$m['last']    = $o['last'];
		$rows[]       = classify( $m, $now );
	}
	$s = summary( $rows );

	echo '<div class="wrap"><h1>성인인증 점검</h1>';
	echo '<p style="max-width:56em;line-height:1.7">가입할 때 PASS 본인확인 뒤 <b>만 19세 판정</b>을 거친 회원에게는 테마가 <code>' . esc_html( meta_key() ) . '</code> 표시를 남깁니다. '
		. '이 표시가 <b>없는</b> 회원은 그 판정이 걸리기 전에 가입했거나 옛 사이트에서 넘어온 사람이라, 인증 없이 로그인 · 주문이 됩니다. 이 화면은 <b>읽기만</b> 합니다 — 회원 · 주문에 아무것도 쓰지 않습니다.</p>';

	$card = function ( string $label, int $n, string $sub = '', bool $warn = false ): void {
		echo '<div style="flex:1 1 160px;min-width:160px;background:#fff;border:1px solid #dcdcde;border-left:4px solid ' . ( $warn ? '#C2410C' : '#2271b1' ) . ';border-radius:8px;padding:12px 14px">'
			. '<div style="font-size:12px;color:#646970">' . esc_html( $label ) . '</div>'
			. '<div style="font-size:26px;font-weight:700;line-height:1.2;margin-top:2px">' . esc_html( number_format_i18n( $n ) ) . '<span style="font-size:13px;font-weight:400;color:#646970"> 명</span></div>'
			. ( '' !== $sub ? '<div style="font-size:12px;color:#646970;margin-top:4px">' . esc_html( $sub ) . '</div>' : '' )
			. '</div>';
	};
	echo '<div style="display:flex;flex-wrap:wrap;gap:10px;margin:14px 0 18px">';
	$card( '회원 전체 (직원 제외)', $s['all'] );
	$card( '인증 기록 있음', $s['verified'], $s['all'] > 0 ? round( $s['verified'] * 100 / $s['all'] ) . '%' : '' );
	$card( '인증 기록 없음', $s['unverified'], '', $s['unverified'] > 0 );
	$card( '없음 · 주문 이력 있음', $s['unv_orders'], '취소 · 환불 · 실패 제외', $s['unv_orders'] > 0 );
	$card( '없음 · 최근 90일 주문', $s['unv_recent'], '지금도 사는 사람', $s['unv_recent'] > 0 );
	$card( '이름 두 글자 (성 없음?)', $s['two_char'], '인증 없는 쪽 ' . number_format_i18n( $s['two_char_unv'] ) . '명 · 입금자명 불일치의 한 원인' );
	$card( '회원 이름 ≠ 인증기관 이름', $s['differs'], '주문자명은 회원 이름을 쓴다 — 입금자명 불일치의 뿌리', $s['differs'] > 0 );
	echo '</div>';

	$unv = array_values( array_filter( $rows, fn( $r ) => ! $r['verified'] ) );
	usort(
		$unv,
		fn( $a, $b ) => ( $b['orders'] <=> $a['orders'] ) ?: strcmp( (string) $b['last'], (string) $a['last'] ) ?: ( $a['id'] <=> $b['id'] )
	);
	$max = (int) apply_filters( 'duckhoo_verify_list_max', 400 );

	if ( ! $unv ) {
		echo '<p><b>인증 기록이 없는 회원이 없습니다.</b> 모든 회원이 가입 때 판정을 거쳤습니다.</p>';
	} else {
		echo '<h2 style="margin-top:8px">인증 기록 없는 회원 <span style="font-weight:400;color:#646970">— 주문 많은 순 · ' . esc_html( number_format_i18n( min( $max, count( $unv ) ) ) ) . ' / ' . esc_html( number_format_i18n( count( $unv ) ) ) . '명</span></h2>';
		echo '<table class="widefat striped"><thead><tr><th style="width:5rem">회원</th><th>이름</th><th>이메일</th><th style="width:8rem">가입일</th><th style="width:5rem;text-align:right">주문</th><th style="width:9rem">마지막 주문</th><th style="width:7rem">표시</th></tr></thead><tbody>';
		foreach ( array_slice( $unv, 0, $max ) as $r ) {
			$flags = array();
			if ( $r['recent'] ) {
				$flags[] = '<span style="color:#C2410C;font-weight:600">최근 주문</span>';
			}
			if ( $r['two_char'] ) {
				$flags[] = '<span style="color:#646970">두 글자</span>';
			}
			echo '<tr><td><a href="' . esc_url( get_edit_user_link( (int) $r['id'] ) ) . '">#' . (int) $r['id'] . '</a></td>'
				. '<td>' . esc_html( (string) $r['name'] ) . '</td>'
				. '<td>' . esc_html( (string) $r['email'] ) . '</td>'
				. '<td>' . esc_html( substr( (string) $r['registered'], 0, 10 ) ) . '</td>'
				. '<td style="text-align:right">' . (int) $r['orders'] . '</td>'
				. '<td>' . esc_html( '' !== (string) $r['last'] ? substr( (string) $r['last'], 0, 10 ) : '—' ) . '</td>'
				. '<td>' . implode( ' · ', $flags ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	// 옛 사이트(아임웹) 명단 대조 — 붙여 넣은 글은 이 요청 안에서만 쓰고 저장하지 않는다.
	$roster_txt = '';
	if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['dhr_roster'] ) && check_admin_referer( 'dhr_verify_roster' ) ) {
		$roster_txt = (string) wp_unslash( $_POST['dhr_roster'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- 숫자 · 이메일만 뽑아 쓴다
	}
	echo '<h2 style="margin-top:26px">옛 사이트(아임웹) 명단과 대조</h2>';
	echo '<p style="max-width:56em;line-height:1.7;color:#646970;font-size:13px">아임웹 회원 내보내기(CSV · 엑셀)를 열어 <b>전체를 복사해 아래에 붙여 넣고</b> 대조를 누르세요. 줄마다 휴대폰 번호와 이메일만 뽑아 인증 기록 없는 회원과 견줍니다 — <b>전화번호가 같으면 같은 사람</b>으로, 없으면 이메일로 봅니다. 붙여 넣은 글은 저장하지 않습니다.</p>';
	echo '<form method="post" style="max-width:56em">';
	wp_nonce_field( 'dhr_verify_roster' );
	echo '<textarea name="dhr_roster" rows="6" style="width:100%;font-family:monospace;font-size:12px" placeholder="이름,이메일,휴대폰,가입일 … (열 순서는 상관없습니다)">' . esc_textarea( $roster_txt ) . '</textarea>';
	echo '<p><button type="submit" class="button button-primary">대조</button></p></form>';
	if ( '' !== trim( $roster_txt ) ) {
		$ro   = parse_roster( $roster_txt );
		$cx   = cross( $rows, $ro );
		$hitN = count( array_filter( $cx, fn( $r ) => '' !== $r['hit'] ) );
		$miss = array_values( array_filter( $cx, fn( $r ) => '' === $r['hit'] ) );
		usort( $miss, fn( $a, $b ) => ( $b['orders'] <=> $a['orders'] ) ?: ( $a['id'] <=> $b['id'] ) );
		echo '<div style="display:flex;flex-wrap:wrap;gap:10px;margin:8px 0 14px">';
		$card( '명단에서 읽은 번호', count( $ro['phones'] ), number_format_i18n( $ro['lines'] ) . '줄 · 이메일 ' . number_format_i18n( count( $ro['emails'] ) ) . '개' );
		$card( '인증 없음 · 명단에 있음', $hitN, '옛 사이트 회원 — 재인증 대상에서 뺄 후보' );
		$card( '인증 없음 · 명단에 없음', count( $miss ), '재인증이 필요한 사람', count( $miss ) > 0 );
		echo '</div>';
		if ( $miss ) {
			echo '<table class="widefat striped"><thead><tr><th style="width:5rem">회원</th><th>이름</th><th>이메일</th><th style="width:9rem">휴대폰</th><th style="width:8rem">가입일</th><th style="width:5rem;text-align:right">주문</th><th style="width:9rem">마지막 주문</th></tr></thead><tbody>';
			foreach ( $miss as $r ) {
				echo '<tr><td><a href="' . esc_url( get_edit_user_link( (int) $r['id'] ) ) . '">#' . (int) $r['id'] . '</a></td>'
					. '<td>' . esc_html( (string) $r['name'] ) . '</td><td>' . esc_html( (string) $r['email'] ) . '</td>'
					. '<td>' . esc_html( '' !== (string) $r['phone'] ? (string) $r['phone'] : '—' ) . '</td>'
					. '<td>' . esc_html( substr( (string) $r['registered'], 0, 10 ) ) . '</td>'
					. '<td style="text-align:right">' . (int) $r['orders'] . '</td>'
					. '<td>' . esc_html( '' !== (string) $r['last'] ? substr( (string) $r['last'], 0, 10 ) : '—' ) . '</td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p><b>인증 기록 없는 회원이 전부 명단에 있습니다.</b></p>';
		}
		$noPhone = count( array_filter( $cx, fn( $r ) => '' === (string) $r['phone'] ) );
		if ( $noPhone > 0 ) {
			echo '<p style="color:#646970;font-size:13px">휴대폰 번호가 비어 있어 이메일로만 견준 회원 ' . esc_html( number_format_i18n( $noPhone ) ) . '명.</p>';
		}
	}

	$dif = array_values( array_filter( $rows, fn( $r ) => ! empty( $r['differs'] ) ) );
	if ( $dif ) {
		usort( $dif, fn( $a, $b ) => ( $b['orders'] <=> $a['orders'] ) ?: ( $a['id'] <=> $b['id'] ) );
		echo '<h2 style="margin-top:22px">회원 이름이 인증기관 이름과 다른 회원 <span style="font-weight:400;color:#646970">— ' . esc_html( number_format_i18n( min( $max, count( $dif ) ) ) ) . ' / ' . esc_html( number_format_i18n( count( $dif ) ) ) . '명 · 주문 많은 순</span></h2>';
		echo '<p style="max-width:56em;line-height:1.7;color:#646970;font-size:13px">인증기관이 준 이름(<code>wd_verified_name</code>)은 세 글자인데 회원 이름은 옛날에 손으로 적은 것이 남아 있는 경우입니다. 주문자명 · 입금 자동확인은 <b>회원 이름</b>을 쓰므로 은행에서 온 세 글자 이름과 어긋납니다. 이 화면은 고치지 않습니다 — 인증 이름으로 맞출지는 사장님이 정합니다.</p>';
		echo '<table class="widefat striped"><thead><tr><th style="width:5rem">회원</th><th>회원 이름 (주문자명)</th><th>인증기관 이름</th><th>이메일</th><th style="width:5rem;text-align:right">주문</th><th style="width:9rem">마지막 주문</th></tr></thead><tbody>';
		foreach ( array_slice( $dif, 0, $max ) as $r ) {
			echo '<tr><td><a href="' . esc_url( get_edit_user_link( (int) $r['id'] ) ) . '">#' . (int) $r['id'] . '</a></td>'
				. '<td><b>' . esc_html( (string) $r['name'] ) . '</b></td>'
				. '<td>' . esc_html( (string) $r['vname'] ) . '</td>'
				. '<td>' . esc_html( (string) $r['email'] ) . '</td>'
				. '<td style="text-align:right">' . (int) $r['orders'] . '</td>'
				. '<td>' . esc_html( '' !== (string) $r['last'] ? substr( (string) $r['last'], 0, 10 ) : '—' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	echo '<p style="max-width:56em;line-height:1.7;color:#646970;font-size:13px;margin-top:16px">'
		. '읽는 데 ' . esc_html( number_format( microtime( true ) - $t0, 1 ) ) . '초. 「인증 기록」은 <code>' . esc_html( meta_key() ) . '</code> 가 1 인 회원입니다 (필터 <code>duckhoo_verified_meta_key</code>). '
		. '테마는 로그인 직후 「cutoff 이전 가입 + 미인증」 회원에게 안내 팝업을 띄웁니다 (<code>functions.php</code> 10266행 근처) — 결제까지 막는지는 <b>도구 → 코드 찾기</b>에서 <code>wd_phone_verified</code> 를 찾아 보면 나옵니다. '
		. '「이름 두 글자」는 한글 두 글자뿐인 이름 — 새 가입은 인증기관이 준 이름이 그대로 들어가므로 옛 회원에게만 있습니다.</p></div>';
}
