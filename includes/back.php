<?php
/**
 * 가입 · 로그인 뒤 **보던 상품으로 돌아온다.**
 *
 * 키플 가입 흐름(/register/ → /agree/ → /join-form/)은 `redirect_to` 를 첫 장에서 버린다 —
 * 두 번째 장으로 가는 링크가 맨 주소다. 그래서 손님이 상품 사진을 열려고 가입해도
 * 끝나면 아무 데나 떨어진다. 키플 페이지는 건드리지 않고, 첫 장에서 돌아올 곳을
 * **쿠키에 적어 두었다가** 가입이 끝나 어디론가 보내려는 순간(`wp_redirect`) 그리로 바꾼다.
 *
 * 같은 사이트 주소만 받는다 (`wp_validate_redirect`). 가입 완료 요청(`user_register` 가
 * 돈 요청)에서만 끼어들고, 로그인은 워드커머스의 되돌림 필터에서 기본값일 때만 바꾼다.
 *
 * @package Duckhoo\Redesign
 */

namespace Duckhoo\Redesign\Back;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const COOKIE = 'dhr_back';
const TTL    = 3600;

/**
 * 받은 주소가 우리 사이트 안인지 보고 돌려준다. 아니면 빈 문자열.
 *
 * @param string $url 주소.
 * @return string
 */
function safe( string $url ): string {
	$url = trim( $url );
	if ( '' === $url ) {
		return '';
	}
	if ( function_exists( 'wp_validate_redirect' ) ) {
		return (string) wp_validate_redirect( $url, '' );
	}
	return $url;
}

/**
 * 가입 첫 장에 `redirect_to` 를 들고 오면 기억해 둔다.
 *
 * @return void
 */
function remember(): void {
	if ( ! function_exists( 'is_page' ) || ! is_page( 'register' ) || empty( $_GET['redirect_to'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	$to = safe( (string) wp_unslash( $_GET['redirect_to'] ) ); // phpcs:ignore WordPress.Security
	if ( '' === $to ) {
		return;
	}
	set( $to );
}
add_action( 'template_redirect', __NAMESPACE__ . '\\remember' );

/**
 * 쿠키에 적는다.
 *
 * @param string $to 돌아올 주소.
 * @return void
 */
function set( string $to ): void {
	if ( headers_sent() ) {
		return;
	}
	setcookie( COOKIE, $to, array(
		'expires'  => time() + TTL,
		'path'     => '/',
		'secure'   => function_exists( 'is_ssl' ) && is_ssl(),
		'httponly' => true,
		'samesite' => 'Lax',
	) );
	$_COOKIE[ COOKIE ] = $to;
}

/**
 * 적어 둔 곳 (검증한 뒤).
 *
 * @return string
 */
function stored(): string {
	return isset( $_COOKIE[ COOKIE ] ) ? safe( (string) wp_unslash( $_COOKIE[ COOKIE ] ) ) : ''; // phpcs:ignore WordPress.Security
}

/**
 * 지운다.
 *
 * @return void
 */
function forget(): void {
	unset( $_COOKIE[ COOKIE ] );
	if ( ! headers_sent() ) {
		setcookie( COOKIE, '', array( 'expires' => time() - DAY_IN_SECONDS, 'path' => '/' ) );
	}
}

/**
 * 가입이 끝나 어디론가 보내려는 순간 — 적어 둔 곳으로 바꾼다.
 *
 * @param string $location 원래 가려던 곳.
 * @return string
 */
function after_signup( $location ) {
	$to = stored();
	if ( '' === $to || ! function_exists( 'did_action' ) || ! did_action( 'user_register' ) ) {
		return $location;
	}
	forget();
	return $to;
}
add_filter( 'wp_redirect', __NAMESPACE__ . '\\after_signup', 20 );

/**
 * 로그인 뒤 — 워드커머스가 기본(내 계정)으로 보내려 할 때만 적어 둔 곳으로.
 * 폼에 `redirect` 가 따로 있으면(문의 게시판 등) 그쪽이 먼저다.
 *
 * @param string $redirect 가려던 곳.
 * @return string
 */
function after_login( $redirect ) {
	$to = stored();
	if ( '' === $to ) {
		return $redirect;
	}
	$account = function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'myaccount' ) : '';
	if ( '' !== (string) $redirect && (string) $redirect !== $account && untrailingslashit( (string) $redirect ) !== untrailingslashit( $account ) ) {
		return $redirect;
	}
	forget();
	return $to;
}
add_filter( 'woocommerce_login_redirect', __NAMESPACE__ . '\\after_login', 20 );
