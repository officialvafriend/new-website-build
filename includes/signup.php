<?php
/**
 * 같은 사람이 계정을 여러 개 만드는 것을 막는다.
 *
 * 노보처럼 물량이 정해진 상품은 한 사람이 하루에 살 수 있는 양을 제한한다. 그런데
 * 그 한도가 **회원 계정** 기준이면 계정을 하나 더 만드는 것으로 그냥 지나갈 수 있다.
 * 안내 배너에도 「동일 본인인증 정보 기준」이라고 적혀 있다 — 화면의 약속과 실제가
 * 갈리면 안 된다.
 *
 * 가르는 값은 **휴대폰번호**다. 이 가게는 가입할 때 휴대폰 본인확인을 거치므로
 * 번호가 곧 사람이다. 본인확인 절차 자체는 건드리지 않는다 — 폼도, 필드 이름도,
 * 토큰도 그대로다. 우리는 제출된 번호가 이미 쓰이고 있는지만 보고 멈춘다.
 *
 * **막을 때는 길을 함께 준다.** 「이미 가입된 번호입니다」로 끝내면 손님은 갈 곳이
 * 없다. 로그인과 비밀번호 찾기로 보낸다.
 *
 * 끄려면: `add_filter( 'duckhoo_block_duplicate_signup', '__return_false' );`
 *
 * @package Duckhoo\Redesign
 */

declare( strict_types = 1 );

namespace Duckhoo\Redesign\Signup;

defined( 'ABSPATH' ) || exit;

/**
 * 휴대폰번호를 숫자만 남겨 하나의 모양으로 만든다.
 *
 * `010-1234-5678` · `010 1234 5678` · `+82 10-1234-5678` 이 모두 같은 번호다.
 *
 * @param string $phone 입력된 번호.
 * @return string 숫자만. 번호로 볼 수 없으면 빈 문자열.
 */
function normalize( string $phone ): string {
	$d = preg_replace( '/\D+/', '', $phone );
	if ( null === $d || '' === $d ) {
		return '';
	}
	// 국가번호 82 는 앞의 0 으로 되돌린다.
	if ( 0 === strpos( $d, '82' ) && strlen( $d ) >= 11 ) {
		$d = '0' . substr( $d, 2 );
	}
	return strlen( $d ) >= 9 && strlen( $d ) <= 12 ? $d : '';
}

/**
 * 번호가 담길 만한 회원 메타 칸들.
 *
 * @return string[]
 */
function meta_keys(): array {
	return (array) apply_filters(
		'duckhoo_phone_meta_keys',
		array( 'billing_phone', 'shipping_phone', 'wd_join_phone', '_wd_join_phone', 'phone', 'mobile', 'user_phone' )
	);
}

/**
 * 이 번호를 쓰는 회원 ID 들.
 *
 * 저장된 모양(하이픈 · 공백)이 제각각이라 SQL 에서 숫자만 남겨 견준다.
 * 탈퇴 표시가 있는 계정은 세지 않는다 — 다시 가입할 수 있어야 한다.
 *
 * @param string $phone 번호.
 * @return int[]
 */
function users_with_phone( string $phone ): array {
	global $wpdb;
	$n = normalize( $phone );
	if ( '' === $n || ! isset( $wpdb ) ) {
		return array();
	}

	static $memo = array();
	if ( isset( $memo[ $n ] ) ) {
		return $memo[ $n ];
	}

	$keys = meta_keys();
	$in   = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
	$sql  = $wpdb->prepare(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		"SELECT DISTINCT user_id FROM {$wpdb->usermeta}
		 WHERE meta_key IN ( {$in} )
		   AND REPLACE( REPLACE( REPLACE( meta_value, '-', '' ), ' ', '' ), '+82', '0' ) = %s",
		array_merge( $keys, array( $n ) )
	);
	$ids = array_map( 'intval', (array) $wpdb->get_col( $sql ) ); // phpcs:ignore WordPress.DB

	$out = array();
	foreach ( $ids as $id ) {
		if ( $id > 0 && '' === (string) get_user_meta( $id, '_duckhoo_withdrawn_at', true ) ) {
			$out[] = $id;
		}
	}
	$memo[ $n ] = $out;
	return $out;
}

/**
 * 이 회원의 휴대폰번호.
 *
 * @param int $uid 회원 ID.
 * @return string
 */
function phone_of( int $uid ): string {
	if ( $uid <= 0 ) {
		return '';
	}
	foreach ( meta_keys() as $k ) {
		$v = normalize( (string) get_user_meta( $uid, $k, true ) );
		if ( '' !== $v ) {
			return $v;
		}
	}
	return '';
}

/**
 * 중복 가입을 막는가.
 *
 * @return bool
 */
function blocking(): bool {
	return (bool) apply_filters( 'duckhoo_block_duplicate_signup', true );
}

/**
 * 가입 제출을 가로챈다.
 *
 * 키플 가입 폼이 도는 것보다 **먼저** 본다. 이미 쓰이는 번호일 때만 멈추고, 그 밖에는
 * 아무 일도 하지 않는다 — 평소 가입은 이 코드가 없는 것과 똑같이 지나간다.
 *
 * @return void
 */
function guard(): void {
	if ( ! blocking() || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
		return;
	}
	// 키플 가입 폼의 제출만 본다. 논스는 그쪽이 검사한다 — 우리는 막기만 하므로
	// 값을 쓰지 않는다.
	if ( empty( $_POST['wd_join_form_nonce'] ) || empty( $_POST['wd_join_phone'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}

	$phone = normalize( sanitize_text_field( wp_unslash( (string) $_POST['wd_join_phone'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
	if ( '' === $phone || ! users_with_phone( $phone ) ) {
		return;
	}

	$back = wp_get_referer();
	$back = $back ? $back : home_url( '/join-form/' );
	wp_safe_redirect( add_query_arg( 'dhr_dup', '1', remove_query_arg( 'dhr_dup', $back ) ) );
	exit;
}
add_action( 'init', __NAMESPACE__ . '\\guard', 0 );

/**
 * 막힌 이유와 갈 곳을 알려 준다. 가입 화면 머리판 바로 뒤다.
 *
 * @return void
 */
function notice(): void {
	if ( empty( $_GET['dhr_dup'] ) || is_admin() ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	$login = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/my-account/' );
	echo '<div class="dhr-dup" role="alert">'
		. '<b>이미 가입된 휴대폰번호입니다.</b>'
		. '<p>한 분당 계정 하나만 만들 수 있습니다. 예전에 가입하신 적이 있다면 그 계정으로 로그인해 주세요.</p>'
		. '<p class="dhr-dup__go">'
		. '<a class="dhr-dup__btn" href="' . esc_url( $login ) . '">로그인하기</a>'
		. '<a class="dhr-dup__lk" href="' . esc_url( wp_lostpassword_url( $login ) ) . '">비밀번호를 잊으셨나요?</a>'
		. '</p></div>';
}
add_action( 'wp_body_open', __NAMESPACE__ . '\\notice', 7 );
