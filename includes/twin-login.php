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
				$GLOBALS['dhr_twin_how'] = sprintf( '#%d 대신 (둘 다 맞음)', (int) $user->ID );
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
				$GLOBALS['dhr_twin_how'] = '먼저 계정은 안 맞아 이쪽으로';
				return $w;
			}
		}
	}
	return $user;
}
add_filter( 'authenticate', __NAMESPACE__ . '\\pick', 30, 3 );

/**
 * 비밀번호가 바뀌면 쌍둥이 계정에도 같은 해시를 적는다 (2026-09-28 저녁).
 *
 * 김성욱 손님이 휴대폰 비밀번호 찾기로 초기화했더니 #535 만 바뀌어, 새 비밀번호로는 주문 없는 #535 로만 들어갔다.
 * 비밀번호 찾기 · 정보 수정 · 관리자 편집 어느 길로 바뀌든 **같은 아이디의 다른 계정에 같은 해시를 복사**해 두면
 * 두 계정의 비밀번호가 갈라질 일이 없고, 로그인은 늘 `best()` 가 고른 쪽(본인확인 · 주문 있는 계정)으로 간다.
 * 해시를 그대로 복사하므로 평문은 만지지 않는다 (워드프레스 bcrypt 해시는 계정에 묶여 있지 않다). 훅을 안 타는
 * `$wpdb->update` 로 적어 되돌이가 없다. 쌍둥이 = 아이디(user_login)가 같은 계정만 — 이메일만 같은 것은 안 건드린다.
 * 끄기: `duckhoo_twin_password_sync` → false.
 *
 * @param int $uid 비밀번호가 바뀐 계정.
 * @return int 복사한 계정 수.
 */
function sync_hash( int $uid ): int {
	global $wpdb;
	if ( ! apply_filters( 'duckhoo_twin_password_sync', on() ) || ! isset( $wpdb ) || $uid <= 0 ) {
		return 0;
	}
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT user_login, user_pass FROM {$wpdb->users} WHERE ID = %d", $uid ) ); // phpcs:ignore
	if ( ! $row || '' === (string) $row->user_pass || '' === (string) $row->user_login ) {
		return 0;
	}
	$ids = (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE ID <> %d AND user_login = %s AND user_pass <> %s LIMIT 10", $uid, (string) $row->user_login, (string) $row->user_pass ) ); // phpcs:ignore
	$n   = 0;
	foreach ( $ids as $tid ) {
		$tid = (int) $tid;
		if ( false !== $wpdb->update( $wpdb->users, array( 'user_pass' => (string) $row->user_pass ), array( 'ID' => $tid ) ) ) { // phpcs:ignore
			clean_user_cache( $tid );
			++$n;
			error_log( sprintf( '[duckhoo twin-login] #%d 비밀번호가 바뀌어 쌍둥이 #%d 에 같은 해시를 적음', $uid, $tid ) ); // phpcs:ignore
		}
	}
	return $n;
}

/**
 * `wp_set_password` (비밀번호 재설정 · 찾기) 뒤.
 *
 * @param string $password 평문 (안 쓴다).
 * @param int    $uid      계정.
 */
function on_set_password( $password, $uid ): void {
	sync_hash( (int) $uid );
}
add_action( 'wp_set_password', __NAMESPACE__ . '\\on_set_password', 20, 2 );

/**
 * `profile_update` (정보 수정 · 관리자 편집 · `wp_update_user`) 뒤 — 해시가 실제로 바뀐 때만.
 *
 * @param int    $uid 계정.
 * @param object $old 바뀌기 전 WP_User.
 */
function on_profile_update( $uid, $old = null ): void {
	$now = get_userdata( (int) $uid );
	if ( ! $now || ! is_object( $old ) || empty( $old->user_pass ) || (string) $old->user_pass === (string) $now->user_pass ) {
		return;
	}
	sync_hash( (int) $uid );
}
add_action( 'profile_update', __NAMESPACE__ . '\\on_profile_update', 20, 2 );

/**
 * 쌍둥이가 있는 계정의 로그인 기록 — 진단용 (옵션 `duckhoo_twin_log`, 최근 30건, 아이디 · 번호 · 어느 갈래였는지만).
 *
 * @param string $login 아이디.
 * @param object $user  실제로 들어간 WP_User.
 */
function log_login( $login, $user ): void {
	if ( ! ( $user instanceof \WP_User ) || ! twins( $user ) ) {
		return;
	}
	$log   = (array) get_option( 'duckhoo_twin_log', array() );
	$log[] = array( 't' => current_time( 'mysql' ), 'login' => (string) $login, 'id' => (int) $user->ID, 'how' => (string) ( $GLOBALS['dhr_twin_how'] ?? '그대로' ) );
	update_option( 'duckhoo_twin_log', array_slice( $log, -30 ), false );
}
add_action( 'wp_login', __NAMESPACE__ . '\\log_login', 99, 2 );
