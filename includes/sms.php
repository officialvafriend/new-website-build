<?php
/**
 * 자동 문자 — 주문이 들어오면 손님에게 입금 안내 한 통.
 *
 * 사장님 (2026-10-22): 「주문(구매) 후 고객에게 문자 발송」. 주문 4건 중 1건이 입금을 안 해 취소되고,
 * 입금자명이 달라 「확인필요」로 빠지는 것을 손으로 처리한다. 주문 직후 계좌 · 금액 · 입금자명을
 * 문자로 받으면 둘 다 줄어든다.
 *
 *   - 보내는 곳은 문자 업체 API (솔라피 · 알리고 중 하나). 키 · 발신번호는 마케팅 → 자동 문자에 넣는다
 *   - **모드 셋**: 끔 · 시험(손님 대신 시험 번호로만 보낸다 — 실제 주문 내용 그대로) · 켬
 *   - 주문 안내는 **정보성 문자**다 (광고 문구를 섞지 않는다) — 수신 동의와 무관하게 보낼 수 있다
 *   - 결제 길을 막지 않으려고 주문 순간에는 예약만 걸고(`wp_schedule_single_event`) 보내기는 그 다음 요청에서 한다
 *   - 한 주문에 한 번 (`_dhr_sms_order`), 결과는 주문 메모와 화면의 발송 기록에 남는다
 *   - 무통장입금(bacs) 주문만, 입금전(on-hold · pending)일 때만
 *
 * 장바구니 알림(2번)의 바탕으로 회원이 담은 시각(`_dhr_cart_at`)도 여기서 적어 둔다 — 보내는 것은 아직 없다.
 *
 * 키 · 비밀값은 옵션에만 있고 화면에는 「있음」만 보인다. 저장소에는 적지 않는다.
 *
 * @package Duckhoo\Redesign
 */

namespace Duckhoo\Redesign\Sms;

defined( 'ABSPATH' ) || exit;

const OPT   = 'duckhoo_sms';
const LOG   = 'duckhoo_sms_log';
const SENT  = '_dhr_sms_order';
const CART  = '_dhr_cart_at';
const HOOK  = 'duckhoo_sms_order';
const SLUG  = 'duckhoo-sms';

/* ── 설정 ───────────────────────────────────────────────────────────── */

function default_order_tpl(): string {
	return "[액상덕후] {이름}님 주문이 접수되었습니다.\n"
		. "주문번호 {주문번호}\n"
		. "입금하실 금액 {금액}\n"
		. "{은행} {계좌} (예금주 {예금주})\n"
		. "입금자명은 꼭 「{입금자명}」으로 넣어 주세요. 이름이 다르면 확인이 늦어집니다.\n"
		. '평일 오후 4시 전 입금 확인분은 당일 출고합니다.';
}

/** @return array{vendor:string,key:string,secret:string,user:string,sender:string,mode:string,test_to:string,order_on:bool,order_tpl:string} */
function defaults(): array {
	return array(
		'vendor'    => '',
		'key'       => '',
		'secret'    => '',
		'user'      => '',
		'sender'    => '',
		'mode'      => 'off',
		'test_to'   => '',
		'order_on'  => true,
		'order_tpl' => default_order_tpl(),
	);
}

function conf(): array {
	$o = get_option( OPT, array() );
	$c = array_merge( defaults(), is_array( $o ) ? $o : array() );
	$c['mode']   = in_array( $c['mode'], array( 'off', 'test', 'on' ), true ) ? $c['mode'] : 'off';
	$c['vendor'] = in_array( $c['vendor'], array( '', 'solapi', 'aligo' ), true ) ? $c['vendor'] : '';
	if ( '' === trim( (string) $c['order_tpl'] ) ) {
		$c['order_tpl'] = default_order_tpl();
	}
	return (array) apply_filters( 'duckhoo_sms', $c );
}

/** 보낼 준비가 됐는가 — 업체 · 키 · 발신번호. */
function ready( ?array $c = null ): bool {
	$c = $c ?? conf();
	if ( '' === $c['vendor'] || '' === clean_phone( $c['sender'], true ) || '' === $c['key'] ) {
		return false;
	}
	return 'solapi' === $c['vendor'] ? '' !== $c['secret'] : '' !== $c['user'];
}

/* ── 순수 함수 ───────────────────────────────────────────────────────── */

/** 숫자만 · +82 → 0. $any 면 휴대폰이 아닌 발신번호(02 · 1588 …)도 받는다. */
function clean_phone( string $v, bool $any = false ): string {
	$d = (string) preg_replace( '/\D+/', '', $v );
	if ( str_starts_with( $d, '82' ) && strlen( $d ) >= 11 ) {
		$d = '0' . substr( $d, 2 );
	}
	if ( $any ) {
		return strlen( $d ) >= 8 && strlen( $d ) <= 12 ? $d : '';
	}
	return preg_match( '/^01\d{8,9}$/', $d ) ? $d : '';
}

/** 시험 번호 목록 — 쉼표 · 줄바꿈으로 나눈다. @return string[] */
function test_numbers( string $raw ): array {
	$out = array();
	foreach ( preg_split( '/[\s,;]+/', $raw ) ?: array() as $p ) {
		$p = clean_phone( $p );
		if ( '' !== $p ) {
			$out[ $p ] = $p;
		}
	}
	return array_values( $out );
}

/** `{이름}` 같은 자리를 채운다. 모르는 자리는 그대로 둔다. */
function fill( string $tpl, array $vars ): string {
	return (string) preg_replace_callback( '/\{([^{}]+)\}/u', fn( $m ) => array_key_exists( $m[1], $vars ) ? (string) $vars[ $m[1] ] : $m[0], $tpl );
}

/** 문자 바이트 수 (EUC-KR 기준 — 한글 2바이트). 90 을 넘으면 장문(LMS). */
function bytes( string $text ): int {
	if ( function_exists( 'iconv' ) ) {
		$e = @iconv( 'UTF-8', 'CP949//IGNORE', $text ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false !== $e ) {
			return strlen( $e );
		}
	}
	$n = 0;
	foreach ( preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY ) ?: array() as $ch ) {
		$n += strlen( $ch ) > 1 ? 2 : 1;
	}
	return $n;
}

function msg_type( string $text ): string {
	return bytes( $text ) > 90 ? 'LMS' : 'SMS';
}

/** 화면용 — 010-****-1234. */
function mask( string $p ): string {
	return strlen( $p ) >= 10 ? substr( $p, 0, 3 ) . '-****-' . substr( $p, -4 ) : $p;
}

/**
 * 주문 안내에 넣을 값. $o 는 주문에서 읽은 값 (시험용으로 바로 넘길 수 있게 배열).
 *
 * @param array{name:string,number:string,total:int,payer:string} $o
 * @param array{bank:string,account:string,holder:string}         $bank
 */
function order_vars( array $o, array $bank ): array {
	$name = '' !== trim( (string) $o['name'] ) ? trim( (string) $o['name'] ) : '고객';
	return array(
		'이름'    => $name,
		'주문번호'  => (string) $o['number'],
		'금액'    => number_format( (int) $o['total'] ) . '원',
		'은행'    => (string) $bank['bank'],
		'계좌'    => (string) $bank['account'],
		'예금주'   => (string) $bank['holder'],
		'입금자명' => '' !== trim( (string) $o['payer'] ) ? trim( (string) $o['payer'] ) : $name,
	);
}

/* ── 가게 정보 ──────────────────────────────────────────────────────── */

/** 무통장입금 계좌 (첫 번째). @return array{bank:string,account:string,holder:string} */
function bank(): array {
	$list = get_option( 'woocommerce_bacs_accounts', array() );
	$a    = is_array( $list ) && $list ? (array) reset( $list ) : array();
	return array(
		'bank'    => (string) ( $a['bank_name'] ?? '' ),
		'account' => (string) ( $a['account_number'] ?? '' ),
		'holder'  => (string) ( $a['account_name'] ?? '' ),
	);
}

/** 주문 → 문자 값. */
function order_info( $order ): array {
	$name  = trim( $order->get_billing_last_name() . $order->get_billing_first_name() );
	$payer = (string) $order->get_meta( '_deposit_payer_name' );
	$phone = clean_phone( (string) $order->get_billing_phone() );
	$uid   = (int) $order->get_customer_id();
	if ( '' === $phone && $uid > 0 ) {
		foreach ( array( 'wd_verified_phone', 'wd_phone', 'billing_phone' ) as $k ) {
			$phone = clean_phone( (string) get_user_meta( $uid, $k, true ) );
			if ( '' !== $phone ) {
				break;
			}
		}
	}
	return array(
		'name'   => $name,
		'number' => (string) $order->get_order_number(),
		'total'  => (int) round( (float) $order->get_total() ),
		'payer'  => $payer,
		'phone'  => $phone,
	);
}

/* ── 보내기 ─────────────────────────────────────────────────────────── */

/**
 * 한 통 보낸다.
 *
 * @return array{ok:bool,msg:string}
 */
function send( string $to, string $text, ?array $c = null ): array {
	$c  = $c ?? conf();
	$to = clean_phone( $to );
	if ( '' === $to ) {
		return array( 'ok' => false, 'msg' => '받는 번호가 없습니다' );
	}
	if ( ! ready( $c ) ) {
		return array( 'ok' => false, 'msg' => '문자 업체 설정이 비어 있습니다' );
	}
	$from = clean_phone( $c['sender'], true );
	$type = msg_type( $text );

	if ( 'solapi' === $c['vendor'] ) {
		$date = gmdate( 'Y-m-d\TH:i:s\Z' );
		$salt = wp_generate_password( 32, false, false );
		$sig  = hash_hmac( 'sha256', $date . $salt, (string) $c['secret'] );
		$msg  = array( 'to' => $to, 'from' => $from, 'text' => $text, 'type' => $type );
		if ( 'LMS' === $type ) {
			$msg['subject'] = '액상덕후';
		}
		$r = wp_remote_post( 'https://api.solapi.com/messages/v4/send', array(
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'HMAC-SHA256 apiKey=' . $c['key'] . ', date=' . $date . ', salt=' . $salt . ', signature=' . $sig,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( array( 'message' => $msg ) ),
		) );
		if ( is_wp_error( $r ) ) {
			return array( 'ok' => false, 'msg' => $r->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $r );
		$j    = json_decode( (string) wp_remote_retrieve_body( $r ), true );
		$ok   = $code >= 200 && $code < 300 && empty( $j['errorCode'] );
		return array( 'ok' => $ok, 'msg' => $ok ? '보냄' : ( (string) ( $j['errorMessage'] ?? $j['message'] ?? ( 'HTTP ' . $code ) ) ) );
	}

	// 알리고.
	$body = array(
		'key'      => $c['key'],
		'user_id'  => $c['user'],
		'sender'   => $from,
		'receiver' => $to,
		'msg'      => $text,
		'msg_type' => $type,
	);
	if ( 'LMS' === $type ) {
		$body['title'] = '액상덕후';
	}
	$r = wp_remote_post( 'https://apis.aligo.in/send/', array( 'timeout' => 15, 'body' => $body ) );
	if ( is_wp_error( $r ) ) {
		return array( 'ok' => false, 'msg' => $r->get_error_message() );
	}
	$j  = json_decode( (string) wp_remote_retrieve_body( $r ), true );
	$ok = is_array( $j ) && (int) ( $j['result_code'] ?? 0 ) > 0;
	return array( 'ok' => $ok, 'msg' => $ok ? '보냄' : (string) ( $j['message'] ?? 'HTTP ' . wp_remote_retrieve_response_code( $r ) ) );
}

function log_it( string $kind, string $to, array $res, string $ref = '' ): void {
	$log   = get_option( LOG, array() );
	$log   = is_array( $log ) ? $log : array();
	array_unshift( $log, array( 't' => time(), 'kind' => $kind, 'to' => mask( $to ), 'ok' => (bool) $res['ok'], 'msg' => mb_substr( (string) $res['msg'], 0, 120 ), 'ref' => $ref ) );
	update_option( LOG, array_slice( $log, 0, 100 ), false );
}

/* ── 주문 안내 ──────────────────────────────────────────────────────── */

/** 주문이 들어오면 예약만 건다 — 결제 요청을 문자 업체 응답에 붙잡히게 하지 않는다. */
function queue( $order_or_id ): void {
	$c = conf();
	if ( 'off' === $c['mode'] || empty( $c['order_on'] ) ) {
		return;
	}
	$id = is_object( $order_or_id ) && method_exists( $order_or_id, 'get_id' ) ? (int) $order_or_id->get_id() : (int) $order_or_id;
	if ( $id > 0 && ! wp_next_scheduled( HOOK, array( $id ) ) ) {
		wp_schedule_single_event( time() + 5, HOOK, array( $id ) );
	}
}
add_action( 'woocommerce_checkout_order_processed', __NAMESPACE__ . '\\queue', 50 );
add_action( 'woocommerce_store_api_checkout_order_processed', __NAMESPACE__ . '\\queue', 50 );

/** 예약이 돌면 보낸다. */
function run_order( $id ): void {
	$c     = conf();
	$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $id ) : null;
	if ( 'off' === $c['mode'] || empty( $c['order_on'] ) || ! $order || '' !== (string) $order->get_meta( SENT ) ) {
		return;
	}
	if ( 'bacs' !== $order->get_payment_method() || ! in_array( $order->get_status(), array( 'on-hold', 'pending' ), true ) ) {
		return;
	}
	$info = order_info( $order );
	$text = fill( $c['order_tpl'], order_vars( $info, bank() ) );

	if ( 'test' === $c['mode'] ) {
		$tos  = test_numbers( $c['test_to'] );
		$text = "[시험 · 손님에게는 안 감]\n" . $text;
	} else {
		$tos = '' !== $info['phone'] ? array( $info['phone'] ) : array();
	}
	$order->update_meta_data( SENT, (string) time() );
	$order->save();

	if ( ! $tos ) {
		$order->add_order_note( '자동 문자: 받는 번호가 없어 안 보냄' );
		return;
	}
	foreach ( $tos as $to ) {
		$res = send( $to, $text, $c );
		log_it( 'test' === $c['mode'] ? '주문 안내(시험)' : '주문 안내', $to, $res, '#' . $info['number'] );
		$order->add_order_note( '자동 문자 ' . ( $res['ok'] ? '보냄' : '실패' ) . ( 'test' === $c['mode'] ? ' (시험 번호)' : '' ) . ' · ' . mask( $to ) . ( $res['ok'] ? '' : ' · ' . $res['msg'] ) );
	}
}
add_action( HOOK, __NAMESPACE__ . '\\run_order' );

/* ── 장바구니 알림(2번)의 바탕 — 담은 시각 ───────────────────────────── */

function mark_cart(): void {
	$uid = get_current_user_id();
	if ( $uid > 0 ) {
		update_user_meta( $uid, CART, time() );
	}
}
add_action( 'woocommerce_add_to_cart', __NAMESPACE__ . '\\mark_cart', 50, 0 );

/* ── 관리자 ─────────────────────────────────────────────────────────── */

function menu(): void {
	if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$cap    = current_user_can( 'manage_woocommerce' ) ? 'manage_woocommerce' : 'manage_options';
	$parent = isset( $GLOBALS['admin_page_hooks']['woocommerce-marketing'] ) ? 'woocommerce-marketing' : 'tools.php';
	add_submenu_page( $parent, '자동 문자', '자동 문자', $cap, SLUG, __NAMESPACE__ . '\\screen' );
}
add_action( 'admin_menu', __NAMESPACE__ . '\\menu', 99 );

/** 시험 문자에 넣을 견본 주문 — 가장 최근 무통장 주문, 없으면 지어낸 값. */
function sample(): array {
	if ( function_exists( 'wc_get_orders' ) ) {
		$o = wc_get_orders( array( 'limit' => 1, 'type' => 'shop_order', 'payment_method' => 'bacs', 'orderby' => 'date', 'order' => 'DESC' ) );
		if ( $o ) {
			return order_info( $o[0] );
		}
	}
	return array( 'name' => '홍길동', 'number' => '202610220000001', 'total' => 39000, 'payer' => '', 'phone' => '' );
}

function screen(): void {
	if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$c   = conf();
	$msg = '';
	if ( isset( $_POST['dhr_sms_save'] ) && check_admin_referer( 'dhr_sms' ) ) {
		$in  = wp_unslash( $_POST );
		$new = array(
			'vendor'    => sanitize_key( (string) ( $in['vendor'] ?? '' ) ),
			'key'       => trim( sanitize_text_field( (string) ( $in['key'] ?? '' ) ) ),
			'user'      => trim( sanitize_text_field( (string) ( $in['user'] ?? '' ) ) ),
			'sender'    => sanitize_text_field( (string) ( $in['sender'] ?? '' ) ),
			'mode'      => sanitize_key( (string) ( $in['mode'] ?? 'off' ) ),
			'test_to'   => sanitize_textarea_field( (string) ( $in['test_to'] ?? '' ) ),
			'order_on'  => ! empty( $in['order_on'] ),
			'order_tpl' => sanitize_textarea_field( (string) ( $in['order_tpl'] ?? '' ) ),
		);
		$sec = trim( (string) ( $in['secret'] ?? '' ) );
		$new['secret'] = '' !== $sec ? sanitize_text_field( $sec ) : (string) ( get_option( OPT, array() )['secret'] ?? '' );   // 비우면 그대로 둔다
		if ( 'on' === $new['mode'] && ! ready( array_merge( $c, $new ) ) ) {
			$new['mode'] = 'test';
			$msg         = '업체 · 키 · 발신번호가 다 차야 켤 수 있어 시험 모드로 두었습니다.';
		}
		update_option( OPT, $new, false );
		$c   = conf();
		$msg = '저장했습니다. ' . $msg;
	}
	if ( isset( $_POST['dhr_sms_test'] ) && check_admin_referer( 'dhr_sms' ) ) {
		$tos = test_numbers( $c['test_to'] );
		if ( ! $tos ) {
			$msg = '시험 번호를 먼저 넣어 주세요.';
		} else {
			$text = "[시험]\n" . fill( $c['order_tpl'], order_vars( sample(), bank() ) );
			$out  = array();
			foreach ( $tos as $to ) {
				$res   = send( $to, $text, $c );
				log_it( '시험 보내기', $to, $res );
				$out[] = mask( $to ) . ' ' . ( $res['ok'] ? '보냄' : '실패 — ' . $res['msg'] );
			}
			$msg = implode( ' · ', $out );
		}
	}

	$opt   = fn( string $v, string $label, string $cur ) => '<option value="' . esc_attr( $v ) . '"' . selected( $cur, $v, false ) . '>' . esc_html( $label ) . '</option>';
	$prev  = fill( $c['order_tpl'], order_vars( sample(), bank() ) );
	$state = array( 'off' => '꺼짐 — 아무것도 안 보냄', 'test' => '시험 — 주문이 들어오면 손님 대신 시험 번호로만', 'on' => '켬 — 손님에게 보냄' );

	echo '<div class="wrap"><h1>자동 문자</h1>';
	if ( '' !== $msg ) {
		echo '<div class="notice notice-info"><p>' . esc_html( $msg ) . '</p></div>';
	}
	echo '<p>무통장입금 주문이 들어오면 손님에게 <b>입금 안내 문자</b>(금액 · 계좌 · 입금자명)를 한 통 보냅니다. 주문에 대한 안내라 광고가 아닙니다 — 할인 · 신상품 같은 광고 문구는 넣지 마세요.</p>';
	echo '<p><b>지금:</b> ' . esc_html( $state[ $c['mode'] ] ) . ( ready( $c ) ? '' : ' · <span style="color:#b42318">업체 설정이 아직 비어 있습니다</span>' ) . '</p>';
	echo '<form method="post">';
	wp_nonce_field( 'dhr_sms' );
	echo '<table class="form-table">';
	echo '<tr><th>문자 업체</th><td><select name="vendor">' . $opt( '', '고르기', $c['vendor'] ) . $opt( 'solapi', '솔라피 (쿨SMS)', $c['vendor'] ) . $opt( 'aligo', '알리고', $c['vendor'] ) . '</select>'; // phpcs:ignore WordPress.Security.EscapeOutput
	echo '<p class="description">알리고는 보내는 서버 IP 를 미리 등록해야 하는데 이 사이트의 IP 는 바뀔 수 있습니다. 솔라피를 권합니다.</p></td></tr>';
	echo '<tr><th>API 키</th><td><input type="text" name="key" value="' . esc_attr( $c['key'] ) . '" style="width:340px" autocomplete="off"></td></tr>';
	echo '<tr><th>API 시크릿 (솔라피)</th><td><input type="password" name="secret" value="" style="width:340px" autocomplete="new-password" placeholder="' . ( '' !== $c['secret'] ? '있음 — 바꿀 때만 넣으세요' : '' ) . '"></td></tr>';
	echo '<tr><th>아이디 (알리고)</th><td><input type="text" name="user" value="' . esc_attr( $c['user'] ) . '" style="width:200px" autocomplete="off"></td></tr>';
	echo '<tr><th>발신번호</th><td><input type="text" name="sender" value="' . esc_attr( $c['sender'] ) . '" style="width:200px" placeholder="업체에 등록한 번호"></td></tr>';
	echo '<tr><th>모드</th><td><select name="mode">' . $opt( 'off', $state['off'], $c['mode'] ) . $opt( 'test', $state['test'], $c['mode'] ) . $opt( 'on', $state['on'], $c['mode'] ) . '</select></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
	echo '<tr><th>시험 번호</th><td><textarea name="test_to" rows="2" style="width:340px" placeholder="010-0000-0000 (여럿이면 쉼표)">' . esc_textarea( $c['test_to'] ) . '</textarea></td></tr>';
	echo '<tr><th>주문 안내</th><td><label><input type="checkbox" name="order_on" value="1"' . checked( ! empty( $c['order_on'] ), true, false ) . '> 무통장입금 주문이 들어오면 보내기</label>';
	echo '<p><textarea name="order_tpl" rows="8" style="width:100%;max-width:560px">' . esc_textarea( $c['order_tpl'] ) . '</textarea></p>';
	echo '<p class="description">넣을 수 있는 자리: {이름} {주문번호} {금액} {은행} {계좌} {예금주} {입금자명}. 계좌는 결제 설정의 무통장입금 첫 계좌입니다.</p></td></tr>';
	echo '</table>';
	echo '<h2>미리 보기 (최근 주문으로)</h2><pre style="max-width:560px;white-space:pre-wrap;background:#fff;border:1px solid #dcdcde;padding:12px;border-radius:8px">' . esc_html( $prev ) . '</pre>';
	echo '<p class="description">' . esc_html( msg_type( $prev ) . ' · ' . bytes( $prev ) . '바이트' ) . ' (90바이트를 넘으면 장문)</p>';
	submit_button( '저장', 'primary', 'dhr_sms_save', false );
	echo ' ';
	submit_button( '시험 번호로 한 통 보내기', 'secondary', 'dhr_sms_test', false );
	echo '</form>';

	$log = get_option( LOG, array() );
	echo '<h2>발송 기록 (최근 100건)</h2>';
	if ( ! is_array( $log ) || ! $log ) {
		echo '<p>아직 없습니다.</p></div>';
		return;
	}
	echo '<table class="widefat striped"><thead><tr><th>시각</th><th>종류</th><th>받는 번호</th><th>주문</th><th>결과</th></tr></thead><tbody>';
	foreach ( $log as $l ) {
		echo '<tr><td>' . esc_html( wp_date( 'm-d H:i', (int) $l['t'] ) ) . '</td><td>' . esc_html( (string) $l['kind'] ) . '</td><td>' . esc_html( (string) $l['to'] ) . '</td><td>' . esc_html( (string) ( $l['ref'] ?? '' ) ) . '</td><td>' . ( $l['ok'] ? '✅ ' : '❌ ' ) . esc_html( (string) $l['msg'] ) . '</td></tr>';
	}
	echo '</tbody></table></div>';
}
