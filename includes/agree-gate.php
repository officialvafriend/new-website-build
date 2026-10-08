<?php
/**
 * 가입 2단계(약관 동의)를 건너뛰지 못하게 · 약관의 수신 동의를 가입 때 기록.
 *
 * 사장님 (2026-10-08): 「회원가입 누르면 바로 3번으로 가네 — 약관동의를 못 하고 정보입력으로」.
 * 원인: 사장님 스니펫 성인인증 팝업의 「회원가입하고 상품 보기」가 `/join/` 로 가고, 그 주소가 `/join-form/`(3단계)로 301 된다.
 * 우리 링크는 전부 `/register/`(1단계)다. 스니펫 · 테마 · 리다이렉트 규칙은 건드리지 않고 두 겹을 둔다:
 *   ① `/join-form/` 을 GET 으로 열 때 약관 쿠키(`dhr_agree`)가 없으면 `/agree/` 로 보낸다. 한 번 보냈으면(`dhr_ag_seen`) 다시 보내지 않는다 —
 *      쿠키를 못 쓰는 브라우저에서 두 장 사이를 맴돌지 않게. POST(가입 제출) · 로그인 회원은 손대지 않는다
 *   ② front.js 가 `/agree/` 의 「동의하고 가입」 버튼에서 필수 셋이 체크됐을 때 `dhr_agree=s1e0t1`(SMS · 이메일 · 제3자) 쿠키를 1시간 적는다.
 *      `user_register` 에서 그 쿠키를 읽어 회원 메타 `wd_agree_sms · wd_agree_email · wd_agree_third_party` 에 yes/no 로 적는다 —
 *      테마가 49명에게 적어 둔 것과 같은 키 · 같은 값 꼴. 이미 값이 있으면 덮지 않는다
 * 끄기: `add_filter( 'duckhoo_agree_gate', '__return_false' );` (①②를 같이 끈다)
 *
 * @package Duckhoo\Redesign
 */

namespace Duckhoo\Redesign\AgreeGate;

defined( 'ABSPATH' ) || exit;

const COOKIE = 'dhr_agree';
const SEEN   = 'dhr_ag_seen';
const TTL    = 3600;

function on(): bool {
	return (bool) apply_filters( 'duckhoo_agree_gate', true );
}

/* ── 순수 함수 ───────────────────────────────────────────────────────── */

/**
 * 쿠키 값 `s1e0t1` → ['sms' => 'yes', 'email' => 'no', 'third' => 'yes']. 꼴이 아니면 null.
 *
 * @return array{sms:string,email:string,third:string}|null
 */
function parse_agree( string $v ): ?array {
	if ( ! preg_match( '/^s([01])e([01])t([01])$/', trim( $v ), $m ) ) {
		return null;
	}
	$w = fn( string $d ) => '1' === $d ? 'yes' : 'no';
	return array( 'sms' => $w( $m[1] ), 'email' => $w( $m[2] ), 'third' => $w( $m[3] ) );
}

/** 회원 메타로 — 테마가 쓰는 키 · 값 꼴 그대로. */
function agree_meta( array $parsed ): array {
	return array(
		'wd_agree_sms'         => $parsed['sms'],
		'wd_agree_email'       => $parsed['email'],
		'wd_agree_third_party' => $parsed['third'],
	);
}

/**
 * 3단계 GET 을 2단계로 돌려보낼지 — 꺼져 있거나 · 로그인 · POST · 약관 쿠키 있음 · 이미 한 번 보냈음 이면 안 보낸다.
 */
function needs_agree( bool $on, bool $logged_in, string $method, bool $has_agree, bool $seen ): bool {
	return $on && ! $logged_in && 'GET' === strtoupper( $method ) && ! $has_agree && ! $seen;
}

/* ── 훅 ─────────────────────────────────────────────────────────────── */

function gate(): void {
	if ( ! function_exists( 'is_page' ) || ! is_page( 'join-form' ) ) {
		return;
	}
	$has = isset( $_COOKIE[ COOKIE ] ) && null !== parse_agree( (string) $_COOKIE[ COOKIE ] );
	if ( ! needs_agree( on(), is_user_logged_in(), (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ), $has, isset( $_COOKIE[ SEEN ] ) ) ) {
		return;
	}
	if ( ! headers_sent() ) {
		setcookie( SEEN, '1', array( 'expires' => time() + TTL, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
	}
	// 돌아올 곳(상품 페이지 등)은 back.php 쿠키에 — 약관 화면의 버튼이 맨 주소로 넘기기 때문.
	$to = isset( $_GET['redirect_to'] ) ? (string) wp_unslash( $_GET['redirect_to'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	if ( '' !== $to && function_exists( '\\Duckhoo\\Redesign\\Back\\safe' ) && function_exists( '\\Duckhoo\\Redesign\\Back\\set' ) ) {
		$safe = \Duckhoo\Redesign\Back\safe( $to );
		if ( '' !== $safe ) {
			\Duckhoo\Redesign\Back\set( $safe );
		}
	}
	wp_safe_redirect( home_url( '/agree/' ), 302 );
	exit;
}
add_action( 'template_redirect', __NAMESPACE__ . '\\gate', 4 );

/** 가입이 끝나면 약관 쿠키의 동의를 회원 메타에 적는다 (있는 값은 덮지 않음). */
function record( $uid ): void {
	if ( ! on() || empty( $_COOKIE[ COOKIE ] ) ) {
		return;
	}
	$p = parse_agree( (string) $_COOKIE[ COOKIE ] );
	if ( null === $p ) {
		return;
	}
	foreach ( agree_meta( $p ) as $k => $v ) {
		if ( '' === (string) get_user_meta( (int) $uid, $k, true ) ) {
			update_user_meta( (int) $uid, $k, $v );
		}
	}
	update_user_meta( (int) $uid, '_dhr_agree_at', time() );
	if ( ! headers_sent() ) {
		setcookie( COOKIE, '', array( 'expires' => time() - 86400, 'path' => '/' ) );
	}
}
add_action( 'user_register', __NAMESPACE__ . '\\record', 20 );

/** front.js 에 쿠키 이름 · 켜짐을 알린다. */
function js( array $cfg ): array {
	$cfg['agreeCookie'] = on() ? COOKIE : '';
	return $cfg;
}
add_filter( 'duckhoo_js_config', __NAMESPACE__ . '\\js' );
