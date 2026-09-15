<?php
/**
 * 손님에게 가는 메일 · 비밀번호 찾기가 「워드프레스」로 보였다.
 *
 * 사장님 신고 (2026-09-15): 비밀번호를 초기화하려는데 **발신자가 WordPress** 이고,
 * 메일의 링크를 누르면 **워드프레스 관리자 로그인 화면**(`wp-login.php`)이 열린다.
 * 손님 눈에는 가게가 아니라 남의 시스템이다 — 1:1 문의 · 쿠폰 받기에서 고친 것과 같은 문제다.
 *
 * 우리 브랜드 화면은 **이미 있다**: `/my-account/lost-password/` 는 우리 껍데기로
 * 그려지고 폼도 정상이다 (확인함). 그쪽으로 새는 길만 막는다.
 *
 * **메일 주소(From)는 건드리지 않는다.** 보내는 주소를 바꾸면 SPF·DKIM 이 어긋나
 * 메일이 스팸으로 빠진다. 바꾸는 것은 **보이는 이름**뿐이다.
 *
 * @package Duckhoo\Redesign
 */

declare( strict_types = 1 );

namespace Duckhoo\Redesign\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * 가게 이름.
 *
 * @return string
 */
function shop_name(): string {
	$name = trim( (string) apply_filters( 'duckhoo_mail_from_name', (string) get_bloginfo( 'name' ) ) );
	return '' !== $name ? $name : '액상덕후';
}

/**
 * 보내는 사람 이름 — 기본값 `WordPress` 일 때만 가게 이름으로 바꾼다.
 *
 * 다른 플러그인이 이미 제 이름을 넣어 두었으면 그것을 존중한다.
 *
 * @param string $name 지금 값.
 * @return string
 */
function from_name( $name ): string {
	$name = (string) $name;
	return 'WordPress' === $name || '' === trim( $name ) ? shop_name() : $name;
}
add_filter( 'wp_mail_from_name', __NAMESPACE__ . '\\from_name', 20 );

/**
 * 우리 비밀번호 찾기 화면.
 *
 * @return string
 */
function lost_url(): string {
	if ( ! function_exists( 'wc_get_page_permalink' ) ) {
		return home_url( '/my-account/lost-password/' );
	}
	$my = (string) wc_get_page_permalink( 'myaccount' );
	return function_exists( 'wc_get_endpoint_url' ) ? (string) wc_get_endpoint_url( 'lost-password', '', $my ) : $my;
}

/**
 * 「비밀번호를 잊으셨나요?」 링크를 우리 화면으로.
 *
 * @param string $url 지금 값.
 * @return string
 */
function lost_link( $url ): string {
	return lost_url();
}
add_filter( 'lostpassword_url', __NAMESPACE__ . '\\lost_link', 20 );

/**
 * **비밀번호 재설정 메일의 링크를 우리 화면으로 바꾼다.**
 *
 * 워드프레스 본체가 보내는 메일은 `wp-login.php?action=rp&key=…` 로 보낸다.
 * 워드커머스는 같은 열쇠를 `/my-account/lost-password/?key=…&login=…` 로도 받는다 —
 * 그쪽이 우리 껍데기로 그려진다.
 *
 * **원래 주소를 지우지 않는다.** 비밀번호 재설정은 막히면 손님이 로그인을 못 하는
 * 자리라, 우리 주소를 먼저 두고 **원래 주소를 아래에 그대로 남긴다**.
 *
 * @param string $message 메일 본문.
 * @param string $key     재설정 열쇠.
 * @param string $login   로그인 이름.
 * @return string
 */
function reset_message( $message, $key = '', $login = '' ): string {
	$message = (string) $message;
	$key     = (string) $key;
	$login   = (string) $login;
	if ( '' === $key || '' === $login || ! apply_filters( 'duckhoo_brand_reset_link', true ) ) {
		return $message;
	}
	$ours = add_query_arg(
		array(
			'key'   => rawurlencode( $key ),
			'login' => rawurlencode( $login ),
		),
		lost_url()
	);
	return $message . "\r\n" . sprintf(
		/* translators: %s: 우리 비밀번호 재설정 주소 */
		"%s\r\n%s\r\n",
		'액상덕후 화면에서 바로 바꾸시려면 아래 주소로 들어오세요:',
		$ours
	);
}
add_filter( 'retrieve_password_message', __NAMESPACE__ . '\\reset_message', 20, 3 );
