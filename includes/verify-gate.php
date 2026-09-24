<?php
/**
 * 옛 회원 재인증 문 (2026-09-24).
 *
 * 테마는 가입 때 PASS 본인확인 + 만 19세 판정을 마친 회원에게 `wd_phone_verified = 1` 을 적는다.
 * 그 표시가 없는 회원은 사이트 이전 때 만들어진 계정이다. 아임웹 명단과 대조해
 * 옛 사이트에서 인증한 것이 확인된 회원에게는 `_dhr_legacy_verified` 를 적고(도구 → 성인인증 점검),
 * **둘 다 없는 회원**만 결제 화면에 왔을 때 테마의 재인증 화면(`/profile-edit/`)으로 보낸다.
 * 재인증이 끝나면 테마가 `wd_phone_verified` 를 적으므로 다음부터는 그냥 지나간다.
 * 미성년자로 나오면 테마가 그 자리에서 탈퇴시킨다 — 판정은 테마 것이고 우리는 문만 연다.
 *
 * 다른 회원(인증 기록 있음 · 옛 사이트 확인)에게는 아무 일도 하지 않는다. 끄기: `duckhoo_reverify_gate` → false.
 *
 * @package Duckhoo\Redesign
 */

declare( strict_types = 1 );

namespace Duckhoo\Redesign\Verify;

defined( 'ABSPATH' ) || exit;

const META_VERIFIED = 'wd_phone_verified';
const META_LEGACY   = '_dhr_legacy_verified';
const META_LEGACY_AT = '_dhr_legacy_verified_at';

/**
 * 테마가 가입 때 남기는 인증 표시 키.
 *
 * @return string
 */
function meta_key(): string {
	return (string) apply_filters( 'duckhoo_verified_meta_key', META_VERIFIED );
}

/**
 * 이 사이트에서 인증을 마친 회원인가.
 *
 * @param int $uid 회원.
 * @return bool
 */
function verified( int $uid ): bool {
	$v = (string) get_user_meta( $uid, meta_key(), true );
	return '1' === $v || 'yes' === $v;
}

/**
 * 옛 사이트 명단으로 확인된 회원인가.
 *
 * @param int $uid 회원.
 * @return bool
 */
function legacy( int $uid ): bool {
	return '' !== trim( (string) get_user_meta( $uid, META_LEGACY, true ) );
}

/**
 * 재인증이 필요한가 — 둘 다 없을 때만.
 *
 * @param int $uid 회원.
 * @return bool
 */
function needs_reverify( int $uid ): bool {
	if ( $uid <= 0 ) {
		return false;
	}
	if ( ! (bool) apply_filters( 'duckhoo_reverify_gate', true ) ) {
		return false;
	}
	return ! verified( $uid ) && ! legacy( $uid );
}

/**
 * 옛 사이트 확인 표시를 남긴다 (도구 → 성인인증 점검의 버튼이 부른다).
 *
 * @param int    $uid    회원.
 * @param string $source 어디서 확인했나 (imweb).
 * @return bool 새로 적었으면 true, 이미 있으면 false.
 */
function mark_legacy( int $uid, string $source = 'imweb' ): bool {
	if ( $uid <= 0 || legacy( $uid ) ) {
		return false;
	}
	update_user_meta( $uid, META_LEGACY, $source );
	update_user_meta( $uid, META_LEGACY_AT, (string) current_time( 'mysql', 1 ) );
	return true;
}

/**
 * 재인증 화면 주소 — 테마의 정보 수정 화면. 필터 `duckhoo_reverify_url`.
 *
 * @return string
 */
function reverify_url(): string {
	return (string) apply_filters( 'duckhoo_reverify_url', add_query_arg( 'dhr_reverify', '1', home_url( '/profile-edit/' ) ) );
}

/**
 * 결제 화면에 온 회원이 재인증 대상이면 재인증 화면으로 보낸다.
 *
 * 주문 완료 · 결제 endpoint 는 건드리지 않는다 — 이미 만들어진 주문 화면이다.
 *
 * @return void
 */
function gate(): void {
	if ( is_admin() || ! is_user_logged_in() || ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
		return;
	}
	if ( function_exists( 'is_wc_endpoint_url' ) && ( is_wc_endpoint_url( 'order-received' ) || is_wc_endpoint_url( 'order-pay' ) ) ) {
		return;
	}
	if ( ! needs_reverify( get_current_user_id() ) ) {
		return;
	}
	wp_safe_redirect( reverify_url() );
	exit;
}
add_action( 'template_redirect', __NAMESPACE__ . '\\gate', 5 );

/**
 * 재인증 화면 위 안내 — 왜 여기 왔는지, 끝나면 어디로 가는지.
 *
 * @return void
 */
function notice(): void {
	if ( empty( $_GET['dhr_reverify'] ) || ! is_user_logged_in() || ! needs_reverify( get_current_user_id() ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	echo '<div class="wrap"><div class="dhr-gate" role="status" style="margin-top:1rem"><span>'
		. '<b>주문 전에 본인확인을 한 번만 해 주세요.</b> 옛 사이트에서 옮겨 온 계정이라 이 사이트의 인증 기록이 없습니다. '
		. '아래 <b>재인증</b> 버튼으로 PASS 본인확인을 마치면 다음부터는 묻지 않습니다.</span> '
		. '<a href="' . esc_url( function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/checkout/' ) ) . '">인증을 마쳤으면 결제로 돌아가기</a>'
		. '</div></div>';
}
add_action( 'wp_body_open', __NAMESPACE__ . '\\notice', 7 );
