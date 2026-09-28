<?php
/**
 * 같은 아이디 · 이메일의 계정이 둘일 때 — 로그인을 **맞는 쪽**으로 (2026-09-28).
 *
 * 도구 → 주문내역 진단 8번: 가게에 아이디 · 이메일이 똑같은 계정 쌍이 **34쌍**, 전부 번호가 붙어 있다
 * (#279209535 · #279209536 처럼 — 한 번의 가입에서 둘이 생긴다). 워드프레스 로그인은 `get_user_by('login')`(LIMIT 1) 이라
 * **먼저 만들어진 쪽**으로 들어가는데, 본인확인 표시(`wd_phone_verified`)와 주문은 **나중 쪽**에 붙는 경우가 많다.
 * 그래서 손님은 빈 주문내역을 보거나(10명) 결제에서 재인증을 요구받는다.
 *
 * 여기서는 `authenticate` 필터(워드프레스 기본 검사 20 뒤, 30)에서 **비밀번호가 실제로 맞는 계정들 중** 가장 나은 쪽을 고른다:
 * 본인확인 있음 > 주문 있음 > 나중에 만들어진 쪽. **비밀번호 검증을 건너뛰지 않는다** — `wp_check_password` 를 그 계정 해시로
 * 다시 돌린다. 맞는 계정이 하나뿐이면 그것, 아무 쌍둥이도 안 맞으면 워드프레스가 고른 계정 그대로.
 * 계정을 지우거나 합치지는 않는다 (그건 사장님이 진단 화면을 보고 한다). 끄기: `duckhoo_twin_login` → false.
 *
 * @package Duckhoo\Redesign
 */

namespace Duckhoo\Redesign\TwinLogin;

defined( 'ABSPATH' ) || exit;

/**
 * 켜져 있는가.
 *
 * @return bool
 */
function on(): bool {
	return (bool) apply_filters( 'duckhoo_twin_login', true );
}

/**
 * 같은 아이디 · 이메일의 다른 계정 번호들.
 *
 * @param object $u WP_User.
 * @return int[]
 */
function twins( $u ): array {
	global $wpdb;
	if ( ! isset( $wpdb ) || ! is_object( $u ) || empty( $u->ID ) ) {
		return array();
	}
	$ids = (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE ID <> %d AND (user_login = %s OR (user_email <> '' AND user_email = %s)) ORDER BY ID ASC LIMIT 10", (int) $u->ID, (string) $u->user_login, (string) $u->user_email ) ); // phpcs:ignore
	return array_map( 'intval', $ids );
}

/**
 * 후보 하나의 점수 재료 — 순수 함수 입력용.
 *
 * @param int $uid 회원 번호.
 * @return array{id:int,verified:bool,orders:int}
 */
function facts( int $uid ): array {
	$orders = 0;
	if ( function_exists( 'wc_get_orders' ) ) {
		$q      = wc_get_orders( array( 'customer' => $uid, 'limit' => 1, 'return' => 'ids', 'status' => array_keys( function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array() ) ) );
		$orders = is_array( $q ) ? count( $q ) : 0;
	}
	return array(
		'id'       => $uid,
		'verified' => '' !== trim( (string) get_user_meta( $uid, 'wd_phone_verified', true ) ) || '' !== trim( (string) get_user_meta( $uid, '_dhr_legacy_verified', true ) ),
		'orders'   => $orders,
	);
}

/**
 * 비밀번호가 맞는 후보들 중 가장 나은 계정 — 순수 함수. 테스트가 이것을 본다.
 * 후보: `['id'=>, 'ok'=>비밀번호 맞음, 'verified'=>, 'orders'=>]`. 맞는 것이 없으면 0.
 *
 * @param array $cands 후보들.
 * @return int
 */
function best( array $cands ): int {
	$ok = array_values( array_filter( $cands, fn( $c ) => ! empty( $c['ok'] ) ) );
	if ( ! $ok ) {
		return 0;
	}
	usort(
		$ok,
		function ( $a, $b ) {
			$sa = ( ! empty( $a['verified'] ) ? 4 : 0 ) + ( (int) ( $a['orders'] ?? 0 ) > 0 ? 2 : 0 );
			$sb = ( ! empty( $b['verified'] ) ? 4 : 0 ) + ( (int) ( $b['orders'] ?? 0 ) > 0 ? 2 : 0 );
			if ( $sa !== $sb ) {
				return $sb - $sa;
			}
			return (int) $b['id'] - (int) $a['id'];   // 같으면 나중에 만들어진 쪽 (본인확인 · 정보가 마지막으로 채워진 계정)
		}
	);
	return (int) $ok[0]['id'];
}

/**
 * `authenticate` — 워드프레스가 고른 계정에 쌍둥이가 있으면 맞는 쪽으로 바꾼다.
 *
 * @param mixed  $user     WP_User · WP_Error · null.
 * @param string $username 입력한 아이디 · 이메일.
 * @param string $password 입력한 비밀번호.
 * @return mixed
 */
function pick( $user, $username = '', $password = '' ) {
	if ( ! on() || ! ( $user instanceof \WP_User ) || '' === (string) $password ) {
		return $user;
	}
	$twins = twins( $user );
	if ( ! $twins ) {
		return $user;
	}
	$cands = array();
	$me    = facts( (int) $user->ID );
	$me['ok'] = true;   // 워드프레스가 이미 이 계정 비밀번호를 확인했다
	$cands[]  = $me;
	foreach ( $twins as $tid ) {
		$t = get_userdata( $tid );
		if ( ! $t || empty( $t->user_pass ) ) {
			continue;
		}
		$f       = facts( $tid );
		$f['ok'] = (bool) wp_check_password( (string) $password, (string) $t->user_pass, $tid );
		$cands[] = $f;
	}
	$win = best( $cands );
	if ( $win > 0 && $win !== (int) $user->ID ) {
		$w = get_userdata( $win );
		if ( $w instanceof \WP_User ) {
			error_log( sprintf( '[duckhoo twin-login] %s → #%d 대신 #%d (쌍둥이 계정)', (string) $username, (int) $user->ID, $win ) ); // phpcs:ignore
			return $w;
		}
	}
	return $user;
}
add_filter( 'authenticate', __NAMESPACE__ . '\\pick', 30, 3 );
