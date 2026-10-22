<?php
/**
 * 가입 쿠폰 — 새로 가입한 회원에게 그 사람만 쓰는 쿠폰 한 장.
 *
 * 사장님 (2026-10-22): 「신규가입 고객 최소주문 금액 맞추는 쿠폰 or 적립금」 → 쿠폰으로.
 * 적립금은 가입 때 이미 8,800원이 나가고 최소 주문 조건을 걸 수 없다. 워드커머스 쿠폰은
 * 최소 주문 금액 · 한 번만 · 기한을 다 건다.
 *
 *   1. `user_register` 에서 표시만 하고, 그 요청이 끝날 때(`shutdown`) 쿠폰을 만든다 —
 *      테마가 본인확인 번호를 `wp_insert_user` 뒤에 적으므로 그때라야 번호로 겹침을 본다
 *   2. 쿠폰은 워드커머스 쿠폰 (`fixed_cart`, 최소 금액, 사용 1회, 기한). 쿠폰 메타 `_dhr_wc_uid` 로
 *      **그 회원만** 쓴다 — 이메일 제한을 쓰지 않는 것은 손님이 이메일을 고치면 못 쓰게 되기 때문이다
 *   3. 같은 휴대폰 번호로 이미 받은 사람이 있으면 만들지 않는다 (탈퇴 → 재가입 · 두 번 가입)
 *   4. 장바구니 · 결제 화면에는 front.js 가 「가입 쿠폰」 줄을 띄운다 (`window.DHR.welcome`).
 *      장바구니에서는 누르면 넣어 주고, 결제 화면에서는 코드를 쿠폰 칸에 채워 준다. 마이페이지 첫 화면에도 한 장
 *
 * **켜기 전에는 아무것도 안 만든다** — 마케팅 → 가입 쿠폰 (옵션 `duckhoo_welcome_coupon`).
 * 결제 금액 계산은 워드커머스 쿠폰 그대로다. 이 파일은 쿠폰을 만들고 「누가 쓸 수 있나」만 본다.
 *
 * @package Duckhoo\Redesign
 */

namespace Duckhoo\Redesign\Welcome;

defined( 'ABSPATH' ) || exit;

const OPT      = 'duckhoo_welcome_coupon';
const PENDING  = '_dhr_wc_pending';
const CODE     = '_dhr_wc_code';
const UNTIL    = '_dhr_wc_until';
const PHONE    = '_dhr_wc_phone';
const SKIP     = '_dhr_wc_skip';
const OWNER    = '_dhr_wc_uid';    // 쿠폰 메타: 쓸 수 있는 회원.
const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
const SLUG     = 'duckhoo-welcome-coupon';

/* ── 설정 ───────────────────────────────────────────────────────────── */

/** @return array{on:bool,amount:int,min:int,days:int} */
function defaults(): array {
	return array( 'on' => false, 'amount' => 3000, 'min' => 30000, 'days' => 7 );
}

/** @return array{on:bool,amount:int,min:int,days:int} */
function conf(): array {
	$o = get_option( OPT, array() );
	$c = array_merge( defaults(), is_array( $o ) ? $o : array() );
	$c = array(
		'on'     => (bool) $c['on'],
		'amount' => max( 0, (int) $c['amount'] ),
		'min'    => max( 0, (int) $c['min'] ),
		'days'   => max( 1, min( 90, (int) $c['days'] ) ),
	);
	return (array) apply_filters( 'duckhoo_welcome_coupon', $c );
}

function live( ?array $c = null ): bool {
	$c = $c ?? conf();
	return $c['on'] && $c['amount'] > 0;
}

/* ── 순수 함수 ───────────────────────────────────────────────────────── */

/** 코드 하나 — `DH` + 6자 (헷갈리는 0 O 1 I L 없음). $rand 는 시험용. */
function make_code( ?callable $rand = null ): string {
	$rand = $rand ?? fn() => random_int( 0, strlen( ALPHABET ) - 1 );
	$out  = 'DH';
	for ( $i = 0; $i < 6; $i++ ) {
		$out .= ALPHABET[ (int) $rand() % strlen( ALPHABET ) ];
	}
	return $out;
}

/**
 * 쓸 수 있는 마지막 날 (Y-m-d) — 가입한 날을 첫날로 센다. 7일이면 가입일 + 6.
 *
 * @param string $today 가입한 날 (Y-m-d).
 * @param int    $days  기한(일).
 */
function last_day( string $today, int $days ): string {
	$ts = strtotime( $today . ' +' . max( 0, $days - 1 ) . ' days' );
	return false === $ts ? $today : gmdate( 'Y-m-d', $ts );
}

/** 워드커머스는 만료일 00:00 에 끊는다 — 마지막 날 밤까지 쓰게 하루를 더해 저장한다. */
function stored_expiry( string $last ): string {
	$ts = strtotime( $last . ' +1 day' );
	return false === $ts ? $last : gmdate( 'Y-m-d', $ts );
}

/** `2026-10-29` → `10월 29일`. */
function kdate( string $ymd ): string {
	return preg_match( '/^\d{4}-(\d{2})-(\d{2})$/', $ymd, $m ) ? ( (int) $m[1] ) . '월 ' . ( (int) $m[2] ) . '일' : $ymd;
}

/** 숫자만 · +82 → 0. */
function clean_phone( string $v ): string {
	$d = (string) preg_replace( '/\D+/', '', $v );
	if ( str_starts_with( $d, '82' ) && strlen( $d ) >= 11 ) {
		$d = '0' . substr( $d, 2 );
	}
	return preg_match( '/^01\d{8,9}$/', $d ) ? $d : '';
}

/** 안내 한 줄 — 「3만원 이상 주문 시 3,000원 할인 · 10월 29일까지」. */
function terms_text( int $amount, int $min, string $last ): string {
	$min_t = $min > 0 ? ( 0 === $min % 10000 ? ( $min / 10000 ) . '만원' : number_format( $min ) . '원' ) . ' 이상 주문 시 ' : '';
	return $min_t . number_format( $amount ) . '원 할인' . ( '' !== $last ? ' · ' . kdate( $last ) . '까지' : '' );
}

/* ── 회원 ───────────────────────────────────────────────────────────── */

function is_staff( int $uid ): bool {
	$u = get_userdata( $uid );
	return $u && array_intersect( (array) $u->roles, (array) apply_filters( 'duckhoo_welcome_staff_roles', array( 'administrator', 'shop_manager' ) ) );
}

/** 회원의 휴대폰 번호 (본인확인 번호 먼저). */
function phone_of( int $uid ): string {
	foreach ( array( 'wd_verified_phone', 'wd_phone', 'billing_phone' ) as $k ) {
		$p = clean_phone( (string) get_user_meta( $uid, $k, true ) );
		if ( '' !== $p ) {
			return $p;
		}
	}
	return '';
}

/** 이 번호로 이미 가입 쿠폰을 받은 다른 회원. */
function phone_taken( string $phone, int $uid ): int {
	if ( '' === $phone ) {
		return 0;
	}
	$ids = get_users( array( 'meta_key' => PHONE, 'meta_value' => $phone, 'fields' => 'ID', 'number' => 3 ) );
	foreach ( $ids as $id ) {
		if ( (int) $id !== $uid ) {
			return (int) $id;
		}
	}
	return 0;
}

/* ── 만들기 ─────────────────────────────────────────────────────────── */

/** @var int[] 이번 요청에서 가입한 회원. */
$GLOBALS['dhr_wc_new'] = array();

function on_register( $uid ): void {
	$uid = (int) $uid;
	if ( $uid <= 0 || ! live() ) {
		return;
	}
	update_user_meta( $uid, PENDING, time() );
	$GLOBALS['dhr_wc_new'][] = $uid;
}
add_action( 'user_register', __NAMESPACE__ . '\\on_register', 30 );

function on_shutdown(): void {
	foreach ( array_unique( (array) ( $GLOBALS['dhr_wc_new'] ?? array() ) ) as $uid ) {
		try {
			issue( (int) $uid );
		} catch ( \Throwable $e ) {
			error_log( '[duckhoo welcome] ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
	}
}
add_action( 'shutdown', __NAMESPACE__ . '\\on_shutdown', 1 );

/**
 * 쿠폰 한 장을 만든다. 이미 있거나 · 꺼져 있거나 · 직원이거나 · 같은 번호로 받은 사람이 있으면 만들지 않는다.
 *
 * @return string 만든 코드 (못 만들면 '').
 */
function issue( int $uid ): string {
	if ( $uid <= 0 || ! live() || ! class_exists( '\\WC_Coupon' ) ) {
		return '';
	}
	if ( '' !== (string) get_user_meta( $uid, CODE, true ) || '' !== (string) get_user_meta( $uid, SKIP, true ) ) {
		return '';
	}
	delete_user_meta( $uid, PENDING );
	if ( is_staff( $uid ) ) {
		update_user_meta( $uid, SKIP, 'staff' );
		return '';
	}
	$phone = phone_of( $uid );
	$other = phone_taken( $phone, $uid );
	if ( $other > 0 ) {
		update_user_meta( $uid, SKIP, 'dup:' . $other );
		return '';
	}

	$c    = conf();
	$code = '';
	for ( $i = 0; $i < 8 && '' === $code; $i++ ) {
		$try = make_code();
		if ( ! function_exists( 'wc_get_coupon_id_by_code' ) || 0 === (int) wc_get_coupon_id_by_code( $try ) ) {
			$code = $try;
		}
	}
	if ( '' === $code ) {
		return '';
	}
	$last = last_day( wp_date( 'Y-m-d' ), $c['days'] );

	$cp = new \WC_Coupon();
	$cp->set_code( $code );
	$cp->set_discount_type( 'fixed_cart' );
	$cp->set_amount( (string) $c['amount'] );
	$cp->set_individual_use( false );
	$cp->set_usage_limit( 1 );
	$cp->set_usage_limit_per_user( 1 );
	$cp->set_free_shipping( false );
	if ( $c['min'] > 0 ) {
		$cp->set_minimum_amount( (string) $c['min'] );
	}
	$cp->set_date_expires( stored_expiry( $last ) );
	$cp->set_description( '가입 쿠폰 · 회원 #' . $uid );
	$cp->update_meta_data( OWNER, (string) $uid );
	$cp->save();

	update_user_meta( $uid, CODE, $code );
	update_user_meta( $uid, UNTIL, $last );
	if ( '' !== $phone ) {
		update_user_meta( $uid, PHONE, $phone );
	}
	return $code;
}

/* ── 그 회원만 ─────────────────────────────────────────────────────── */

/**
 * 가입 쿠폰은 받은 회원만 쓴다. 관리자가 주문을 다시 계산할 때는 지나간다.
 *
 * @param bool       $valid  지금까지의 판정.
 * @param \WC_Coupon $coupon 쿠폰.
 * @return bool
 * @throws \Exception 다른 사람이 쓸 때 — 워드커머스가 잡아 안내로 보여 준다.
 */
function only_owner( $valid, $coupon ) {
	if ( ! $valid || ! is_object( $coupon ) || ! method_exists( $coupon, 'get_meta' ) ) {
		return $valid;
	}
	$owner = (int) $coupon->get_meta( OWNER );
	if ( $owner <= 0 || current_user_can( 'manage_woocommerce' ) ) {
		return $valid;
	}
	if ( get_current_user_id() !== $owner ) {
		throw new \Exception( '이 쿠폰은 가입하신 회원님 계정에서만 쓸 수 있습니다.' );
	}
	return $valid;
}
add_filter( 'woocommerce_coupon_is_valid', __NAMESPACE__ . '\\only_owner', 20, 2 );

/* ── 손님에게 보이기 ───────────────────────────────────────────────── */

/**
 * 지금 쓸 수 있는 가입 쿠폰 — 없거나 · 썼거나 · 기한이 지났으면 null.
 *
 * @return array{code:string,amount:int,min:int,last:string,text:string,applied:bool,sub:int}|null
 */
function active( int $uid ): ?array {
	if ( $uid <= 0 ) {
		return null;
	}
	$code = (string) get_user_meta( $uid, CODE, true );
	if ( '' === $code && '' !== (string) get_user_meta( $uid, PENDING, true ) ) {
		$code = issue( $uid ); // 가입 요청이 끝나기 전에 죽었으면 여기서 한 번 더.
	}
	if ( '' === $code || ! class_exists( '\\WC_Coupon' ) ) {
		return null;
	}
	$cp = new \WC_Coupon( $code );
	if ( ! $cp->get_id() || $cp->get_usage_count() >= max( 1, (int) $cp->get_usage_limit() ) ) {
		return null;
	}
	$last = (string) get_user_meta( $uid, UNTIL, true );
	if ( '' !== $last && wp_date( 'Y-m-d' ) > $last ) {
		return null;
	}
	$amt     = (int) $cp->get_amount();
	$min     = (int) $cp->get_minimum_amount();
	$applied = function_exists( 'WC' ) && WC()->cart && WC()->cart->has_discount( $code );
	$sub     = ( function_exists( 'WC' ) && WC()->cart ) ? (int) round( (float) WC()->cart->get_subtotal() ) : 0;
	return array( 'code' => $code, 'amount' => $amt, 'min' => $min, 'last' => $last, 'text' => terms_text( $amt, $min, $last ), 'applied' => (bool) $applied, 'sub' => $sub );
}

/** front.js — 장바구니 · 결제 화면에서만 (다른 화면에선 질의를 안 한다). */
function js( array $cfg ): array {
	$on = is_user_logged_in() && ( ( function_exists( 'is_cart' ) && is_cart() ) || ( function_exists( 'is_checkout' ) && is_checkout() && ! is_wc_endpoint_url( 'order-received' ) ) );
	if ( $on && ( $w = active( get_current_user_id() ) ) ) {
		$cfg['welcome'] = $w;
	}
	return $cfg;
}
add_filter( 'duckhoo_js_config', __NAMESPACE__ . '\\js' );

function card_html( int $uid ): string {
	$w = active( $uid );
	if ( ! $w ) {
		return '';
	}
	return '<section class="dhr-wcard" aria-label="가입 쿠폰">'
		. '<span class="dhr-wcard__eb">가입 쿠폰</span>'
		. '<b class="dhr-wcard__amt">' . esc_html( number_format( $w['amount'] ) ) . '원</b>'
		. '<span class="dhr-wcard__s">' . esc_html( $w['text'] ) . '</span>'
		. '<span class="dhr-wcard__row"><code class="dhr-wcard__code">' . esc_html( $w['code'] ) . '</code>'
		. '<button type="button" class="dhr-wcard__copy dhr-copy" data-copy="' . esc_attr( $w['code'] ) . '">코드 복사</button></span>'
		. '<span class="dhr-wcard__s">장바구니에서 누르면 바로 들어갑니다.</span>'
		. '</section>';
}

function account_card(): void {
	if ( ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url() ) || ! is_user_logged_in() ) {
		return;
	}
	echo card_html( get_current_user_id() ); // phpcs:ignore WordPress.Security.EscapeOutput
}
add_action( 'woocommerce_account_content', __NAMESPACE__ . '\\account_card', 6 );

/* ── 관리자 ─────────────────────────────────────────────────────────── */

function menu(): void {
	if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$cap    = current_user_can( 'manage_woocommerce' ) ? 'manage_woocommerce' : 'manage_options';
	$parent = isset( $GLOBALS['admin_page_hooks']['woocommerce-marketing'] ) ? 'woocommerce-marketing' : 'tools.php';
	add_submenu_page( $parent, '가입 쿠폰', '가입 쿠폰', $cap, SLUG, __NAMESPACE__ . '\\screen' );
}
add_action( 'admin_menu', __NAMESPACE__ . '\\menu', 99 );

function screen(): void {
	if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( isset( $_POST['dhr_wc_save'] ) && check_admin_referer( 'dhr_wc_save' ) ) {
		$in = wp_unslash( $_POST );
		update_option( OPT, array(
			'on'     => ! empty( $in['on'] ),
			'amount' => (int) ( $in['amount'] ?? 0 ),
			'min'    => (int) ( $in['min'] ?? 0 ),
			'days'   => (int) ( $in['days'] ?? 7 ),
		), false );
		echo '<div class="notice notice-success"><p>저장했습니다. 바꾼 값은 이제부터 가입하는 회원에게만 적용됩니다 (이미 나간 쿠폰은 그대로).</p></div>';
	}
	$c   = conf();
	$num = fn( string $k, string $label, string $tail ) => '<tr><th>' . esc_html( $label ) . '</th><td><input type="number" min="0" step="1" name="' . $k . '" value="' . esc_attr( (string) $c[ $k ] ) . '" style="width:120px"> ' . esc_html( $tail ) . '</td></tr>';

	echo '<div class="wrap"><h1>가입 쿠폰</h1>';
	echo '<p>새로 가입한 회원에게 <b>그 사람만 쓰는 쿠폰</b>이 한 장 생깁니다. 장바구니 · 결제 화면 · 마이페이지에 안내가 뜨고, 장바구니에서 누르면 바로 들어갑니다. 가입 적립금(8,800원)과 같이 쓸 수 있습니다.</p>';
	echo '<form method="post">';
	wp_nonce_field( 'dhr_wc_save' );
	echo '<table class="form-table"><tr><th>켜기</th><td><label><input type="checkbox" name="on" value="1"' . checked( $c['on'], true, false ) . '> 가입하면 쿠폰 주기</label></td></tr>';
	echo $num( 'amount', '할인 금액', '원' ); // phpcs:ignore WordPress.Security.EscapeOutput
	echo $num( 'min', '최소 주문 금액', '원 이상 (0 이면 조건 없음)' ); // phpcs:ignore WordPress.Security.EscapeOutput
	echo $num( 'days', '쓸 수 있는 기간', '일 (가입한 날 포함)' ); // phpcs:ignore WordPress.Security.EscapeOutput
	echo '</table><p class="description">같은 휴대폰 번호로 이미 받은 사람(탈퇴 뒤 재가입 · 두 번 가입)에게는 다시 주지 않습니다. 직원 계정도 받지 않습니다.</p>';
	submit_button( '저장', 'primary', 'dhr_wc_save' );
	echo '</form>';

	$users = get_users( array( 'meta_key' => CODE, 'number' => 200, 'orderby' => 'registered', 'order' => 'DESC', 'fields' => array( 'ID', 'display_name', 'user_registered' ) ) );
	echo '<h2>나간 쿠폰 (최근 200장)</h2>';
	if ( ! $users ) {
		echo '<p>아직 없습니다.</p></div>';
		return;
	}
	$used = 0;
	$rows = '';
	foreach ( $users as $u ) {
		$code = (string) get_user_meta( $u->ID, CODE, true );
		$cp   = class_exists( '\\WC_Coupon' ) ? new \WC_Coupon( $code ) : null;
		$n    = $cp && $cp->get_id() ? (int) $cp->get_usage_count() : 0;
		$used += $n > 0 ? 1 : 0;
		$last = (string) get_user_meta( $u->ID, UNTIL, true );
		$st   = $n > 0 ? '✅ 씀' : ( '' !== $last && wp_date( 'Y-m-d' ) > $last ? '기한 지남' : '⏳ 안 씀' );
		$rows .= '<tr><td>' . esc_html( substr( (string) $u->user_registered, 0, 10 ) ) . '</td><td><a href="' . esc_url( admin_url( 'user-edit.php?user_id=' . $u->ID ) ) . '">#' . (int) $u->ID . ' ' . esc_html( $u->display_name ) . '</a></td><td><code>' . esc_html( $code ) . '</code></td><td>' . esc_html( kdate( $last ) ) . '</td><td>' . esc_html( $st ) . '</td></tr>';
	}
	echo '<p>' . count( $users ) . '장 중 <b>' . (int) $used . '장</b> 씀.</p>';
	echo '<table class="widefat striped"><thead><tr><th>가입</th><th>회원</th><th>코드</th><th>마지막 날</th><th>상태</th></tr></thead><tbody>' . $rows . '</tbody></table></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
}
