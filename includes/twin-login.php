<?php
/**
 * 같은 아이디 · 이메일의 계정이 둘일 때 — 로그인을 **맞는 쪽**으로 (2026-09-28).
 *
 * 도구 → 주문내역 진단 8번: 가게에 아이디 · 이메일이 똑같은 계정 쌍이 **34쌍**, 전부 번호가 붙어 있다
 * (#279209535 · #279209536 처럼 — 한 번의 가입에서 둘이 생긴다). 워드프레스 로그인은 `get_user_by('login')`(LIMIT 1) 이라
 * **먼저 만들어진 쪽**으로 들어가는데, 본인확인 표시(`wd_phone_verified`)와 주문은 **나중 쪽**에 붙는 경우가 많다.
 * 그래서 손님은 빈 주문내역을 보거나(10명) 결제에서 재인증을 요구받는다.
 *
 * 두 갈래로 돕는다 (둘 다 `authenticate` 필터, 워드프레스 기본 검사 20 뒤인 30):
 * ① 워드프레스가 고른 계정의 비밀번호가 맞을 때 — 쌍둥이도 맞으면 더 나은 쪽으로.
 * ② 워드프레스가 「틀린 비밀번호」라고 했을 때(2026-09-28 저녁, 김성욱 손님 로그인 불가) — 같은 아이디 · 이메일의 **다른** 계정 해시로
 *    다시 확인한다. 비밀번호 찾기(키플은 휴대폰으로 찾아 나중 계정을 고칠 수 있다)나 정보 수정이 한쪽 계정에만 닿으면 두 계정의
 *    비밀번호가 갈라지는데, 로그인은 늘 먼저 계정(LIMIT 1)만 보므로 손님이 맞는 비밀번호를 쳐도 잠긴다. 그 계정의 해시가 맞아야만 연다.
 *
 * 어느 갈래든 **비밀번호가 실제로 맞는 계정들 중** 가장 나은 쪽을 고른다:
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
 * 워드프레스가 「틀린 비밀번호」로 돌려준 것인가 — 이때만 쌍둥이 해시를 다시 본다. 순수 함수.
 * 아이디가 없거나(`invalid_username` · `invalid_email`) 칸이 비었으면 쌍둥이도 없거나 볼 이유가 없다.
 *
 * @param mixed $user WP_Error 등.
 * @return bool
 */
function rescuable( $user ): bool {
	return ( $user instanceof \WP_Error ) && in_array( (string) $user->get_error_code(), array( 'incorrect_password' ), true );
}

/**
 * 같은 아이디 · 이메일로 등록된 계정 번호 전부 (워드프레스가 LIMIT 1 로 잡은 것 포함).
 *
 * @param string $username 입력한 아이디 · 이메일.
 * @return int[]
 */
function by_name( string $username ): array {
	global $wpdb;
	$username = trim( $username );
	if ( '' === $username || ! isset( $wpdb ) ) {
		return array();
	}
	$ids = (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE user_login = %s OR (user_email <> '' AND user_email = %s) ORDER BY ID ASC LIMIT 10", $username, $username ) ); // phpcs:ignore
	return array_map( 'intval', $ids );
}

/**
 * 후보 계정들의 해시로 비밀번호를 확인해 점수 재료를 만든다.
 *
 * @param int[]  $ids      후보 번호.
 * @param string $password 입력한 비밀번호.
 * @param int    $trusted  워드프레스가 이미 확인해 준 계정 (0 이면 없음).
 * @return array
 */
function candidates( array $ids, string $password, int $trusted = 0 ): array {
	$cands = array();
	foreach ( $ids as $tid ) {
		$tid = (int) $tid;
		$t   = get_userdata( $tid );
		if ( ! $t || empty( $t->user_pass ) ) {
			continue;
		}
		$f       = facts( $tid );
		$f['ok'] = $tid === $trusted ? true : (bool) wp_check_password( $password, (string) $t->user_pass, $tid );
		$cands[] = $f;
	}
	return $cands;
}

/**
 * `authenticate` — 워드프레스가 고른 계정에 쌍둥이가 있으면 맞는 쪽으로 바꾸고,
 * 틀렸다고 한 비밀번호가 쌍둥이 계정에는 맞으면 그 계정으로 연다.
 *
 * @param mixed  $user     WP_User · WP_Error · null.
 * @param string $username 입력한 아이디 · 이메일.
 * @param string $password 입력한 비밀번호.
 * @return mixed
 */
function pick( $user, $username = '', $password = '' ) {
	if ( ! on() || '' === (string) $password ) {
		return $user;
	}
	if ( $user instanceof \WP_User ) {
		$twins = twins( $user );
		if ( ! $twins ) {
			return $user;
		}
		$cands = candidates( array_merge( array( (int) $user->ID ), $twins ), (string) $password, (int) $user->ID );
		$win   = best( $cands );
		if ( $win > 0 && $win !== (int) $user->ID ) {
			$w = get_userdata( $win );
			if ( $w instanceof \WP_User ) {
				error_log( sprintf( '[duckhoo twin-login] %s → #%d 대신 #%d (쌍둥이 계정)', (string) $username, (int) $user->ID, $win ) ); // phpcs:ignore
				return $w;
			}
		}
		return $user;
	}
	if ( rescuable( $user ) ) {
		$ids = by_name( (string) $username );
		if ( count( $ids ) < 2 ) {
			return $user;   // 쌍둥이가 없다 — 정말 틀린 비밀번호
		}
		$win = best( candidates( $ids, (string) $password ) );
		if ( $win > 0 ) {
			$w = get_userdata( $win );
			if ( $w instanceof \WP_User ) {
				error_log( sprintf( '[duckhoo twin-login] %s → 먼저 계정은 틀렸지만 #%d 의 비밀번호가 맞아 그 계정으로 (쌍둥이 계정)', (string) $username, $win ) ); // phpcs:ignore
				return $w;
			}
		}
	}
	return $user;
}
add_filter( 'authenticate', __NAMESPACE__ . '\\pick', 30, 3 );
