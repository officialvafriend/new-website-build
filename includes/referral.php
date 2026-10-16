<?php
/**
 * 친구 추천 — 개인 추천 코드 · 추천 링크 · 첫 주문 뒤 두 사람에게 적립금.
 *
 * 사장님 (2026-10-16): 「추천인 적으면 추천인이랑 추천 받은 사람한테 적립금」 · 「개인 추천코드 추천링크 좋은데?」.
 *
 *   1. 회원마다 6자 코드(`_dhr_ref_code`, 헷갈리는 0 O 1 I L 없음). 마이페이지 첫 화면 카드에 코드 · 링크 · 복사
 *   2. 링크 `?ref=코드` 로 들어오면 front.js 가 쿠키 `dhr_ref`(30일)에 적는다 — 비로그인 화면은 페이지 캐시라 서버가 못 본다
 *   3. 가입 3단계 폼에 「추천 코드 (선택)」 칸을 이 파일이 붙인다 (폼 맨 아래 · 가입하기 버튼 위).
 *      코드 대신 휴대폰 번호를 적어도 찾는다. **테마 폼의 칸 · 검증은 그대로** — 칸 하나를 더할 뿐이다
 *   4. `user_register` 에서 추천인만 기록한다 (`_dhr_ref_by`). **이때 적립금은 없다**
 *   5. 추천받은 사람의 주문이 입금확인 이후 상태로 넘어가면 조건을 보고 두 사람에게 `Points\grant()` 로 준다.
 *      그 주문이 취소 · 환불되면 거둔다 (이미 썼으면 0 아래로 안 감) — 다음 주문에서 다시 받을 수 있다
 *
 * 막는 것 (verdict()): 꺼짐 · 금액 0 · 같은 사람(회원 번호 · 이메일 · 본인인증 CI · 휴대폰) · 직원 · 탈퇴한 추천인 ·
 * 실입금 기준 미달 · 가입 뒤 N일이 지남 · 행사 끝 · 추천인 월 상한(넘으면 추천받은 사람만 받는다).
 * 「있는 코드인가」를 바로 알려 주는 자리는 만들지 않는다 — 남의 번호가 회원인지 떠보는 데 쓰인다.
 *
 * 설정은 마케팅 → 친구 추천 (옵션 `duckhoo_referral`). **켜기 전에는 칸 · 카드 · 지급이 모두 꺼져 있다.**
 *
 * @package Duckhoo\Redesign
 */

namespace Duckhoo\Redesign\Referral;

defined( 'ABSPATH' ) || exit;

const OPT      = 'duckhoo_referral';
const CODE     = '_dhr_ref_code';
const BY       = '_dhr_ref_by';      // 추천받은 사람: 추천인 회원 번호.
const RAW      = '_dhr_ref_raw';     // 적은 그대로.
const AT       = '_dhr_ref_at';      // 가입 시각.
const STATE    = '_dhr_ref_state';   // '' 기다림 · paid · blocked:사유.
const PAID_AT  = '_dhr_ref_paid_at'; // 지급 시각 (월 상한 셈).
const ORDER    = '_dhr_ref_grant';   // 주문 메타: 이 주문으로 준 것.
const COOKIE   = 'dhr_ref';
const FIELD    = 'dhr_ref';
const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
const SLUG     = 'duckhoo-referral';

/* ── 설정 ───────────────────────────────────────────────────────────── */

/** @return array{on:bool,ref_amt:int,new_amt:int,min:int,cap:int,days:int,until:string} */
function defaults(): array {
	return array( 'on' => false, 'ref_amt' => 3000, 'new_amt' => 3000, 'min' => 30000, 'cap' => 10, 'days' => 60, 'until' => '' );
}

/** @return array{on:bool,ref_amt:int,new_amt:int,min:int,cap:int,days:int,until:string} */
function conf(): array {
	$o = get_option( OPT, array() );
	$c = array_merge( defaults(), is_array( $o ) ? $o : array() );
	$c = array(
		'on'      => (bool) $c['on'],
		'ref_amt' => max( 0, (int) $c['ref_amt'] ),
		'new_amt' => max( 0, (int) $c['new_amt'] ),
		'min'     => max( 0, (int) $c['min'] ),
		'cap'     => max( 0, (int) $c['cap'] ),
		'days'    => max( 0, (int) $c['days'] ),
		'until'   => preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $c['until'] ) ? (string) $c['until'] : '',
	);
	return (array) apply_filters( 'duckhoo_referral', $c );
}

/** 지금 받는가 — 켜져 있고 · 금액이 있고 · 행사가 안 끝났다. */
function live( ?array $c = null, ?string $today = null ): bool {
	$c     = $c ?? conf();
	$today = $today ?? wp_date( 'Y-m-d' );
	return $c['on'] && ( $c['ref_amt'] > 0 || $c['new_amt'] > 0 ) && ( '' === $c['until'] || $today <= $c['until'] );
}

/** 입금확인 이후로 보는 상태 (슬러그, wc- 없이). */
function paid_statuses(): array {
	return (array) apply_filters( 'duckhoo_referral_paid_statuses', array( 'payment-confirmed', 'ready-to-ship', 'delivered', 'completed', 'processing' ) );
}

/* ── 순수 함수 ───────────────────────────────────────────────────────── */

/** 6자 코드 하나. $rand 는 시험용 (0..30 을 돌려주는 함수). */
function make_code( ?callable $rand = null ): string {
	$rand = $rand ?? fn() => random_int( 0, strlen( ALPHABET ) - 1 );
	$s    = '';
	for ( $i = 0; $i < 6; $i++ ) {
		$s .= ALPHABET[ $rand() % strlen( ALPHABET ) ];
	}
	return $s;
}

/** 적은 것을 코드로 — 빈칸 · 하이픈을 떼고 대문자로. 꼴이 아니면 ''. */
function clean_code( string $v ): string {
	$v = strtoupper( (string) preg_replace( '/[\s\-_.]+/', '', $v ) );
	return preg_match( '/^[' . ALPHABET . ']{6}$/', $v ) ? $v : '';
}

/** 번호로 — 숫자만 (+82 → 0). 꼴이 아니면 ''. */
function clean_phone( string $v ): string {
	$d = (string) preg_replace( '/\D+/', '', $v );
	if ( str_starts_with( $d, '82' ) && strlen( $d ) >= 11 ) {
		$d = '0' . substr( $d, 2 );
	}
	return preg_match( '/^01\d{8,9}$/', $d ) ? $d : '';
}

/**
 * 적은 것이 무엇인가.
 *
 * @return array{kind:string,value:string} kind = code | phone | ''
 */
function parse_input( string $v ): array {
	$v = trim( $v );
	if ( '' !== ( $p = clean_phone( $v ) ) && preg_match( '/\d{3}/', $v ) ) {
		return array( 'kind' => 'phone', 'value' => $p );
	}
	if ( '' !== ( $c = clean_code( $v ) ) ) {
		return array( 'kind' => 'code', 'value' => $c );
	}
	return array( 'kind' => '', 'value' => '' );
}

/**
 * 줄 수 있는가. 순수.
 *
 * $x: ref(추천인 번호) · new(추천받은 사람 번호) · same(같은 사람으로 보이는 이유 '' 이면 아님) · ref_staff · ref_gone ·
 *     total(이 주문 실입금) · since(가입 뒤 지난 날) · today(Y-m-d) · ref_month(이번 달 추천인이 이미 받은 수)
 *
 * @return array{new:int,ref:int,why:string} why 가 '' 이 아니면 둘 다 0 (또는 상한이면 ref 만 0)
 */
function verdict( array $c, array $x ): array {
	$no = fn( string $why ) => array( 'new' => 0, 'ref' => 0, 'why' => $why );
	if ( ! live( $c, (string) $x['today'] ) ) {
		return $no( 'off' );
	}
	if ( (int) $x['ref'] <= 0 || (int) $x['ref'] === (int) $x['new'] ) {
		return $no( 'self' );
	}
	if ( '' !== (string) $x['same'] ) {
		return $no( 'same:' . $x['same'] );
	}
	if ( ! empty( $x['ref_staff'] ) ) {
		return $no( 'staff' );
	}
	if ( ! empty( $x['ref_gone'] ) ) {
		return $no( 'gone' );
	}
	if ( (float) $x['total'] < $c['min'] ) {
		return $no( 'min' );
	}
	if ( $c['days'] > 0 && (float) $x['since'] > $c['days'] ) {
		return $no( 'late' );
	}
	$ref = $c['ref_amt'];
	$why = '';
	if ( $c['cap'] > 0 && (int) $x['ref_month'] >= $c['cap'] ) {
		$ref = 0;
		$why = 'cap';
	}
	return array( 'new' => $c['new_amt'], 'ref' => $ref, 'why' => $why );
}

/** 사유를 사람 말로. */
function why_text( string $why ): string {
	$map = array(
		'off'   => '행사 꺼짐 · 끝남',
		'self'  => '자기 자신',
		'staff' => '추천인이 직원 계정',
		'gone'  => '추천인이 탈퇴',
		'min'   => '첫 주문 금액 미달',
		'late'  => '가입 뒤 기한 지남',
		'cap'   => '추천인 이번 달 상한 (추천받은 분만 지급)',
	);
	if ( str_starts_with( $why, 'same:' ) ) {
		return '같은 사람으로 보임 (' . substr( $why, 5 ) . ')';
	}
	return $map[ $why ] ?? $why;
}

/** 두 회원이 같은 사람으로 보이는 이유 — 이메일 · CI · 휴대폰. 순수 (값을 받는다). */
function same_person( array $a, array $b ): string {
	if ( '' !== $a['email'] && strtolower( $a['email'] ) === strtolower( $b['email'] ) ) {
		return '이메일';
	}
	if ( '' !== $a['ci'] && $a['ci'] === $b['ci'] ) {
		return '본인인증';
	}
	if ( array_intersect( $a['phones'], $b['phones'] ) ) {
		return '휴대폰';
	}
	return '';
}

/** 링크. */
function link_for( string $code ): string {
	return add_query_arg( 'ref', rawurlencode( $code ), home_url( '/' ) );
}

/* ── 회원 · 코드 ─────────────────────────────────────────────────────── */

/** 같은 사람 견주기용 정보. */
function who( int $uid ): array {
	$u      = get_userdata( $uid );
	$phones = array();
	foreach ( array( 'wd_verified_phone', 'wd_phone', 'billing_phone' ) as $k ) {
		$p = clean_phone( (string) get_user_meta( $uid, $k, true ) );
		if ( '' !== $p ) {
			$phones[ $p ] = $p;
		}
	}
	return array(
		'email'  => $u ? (string) $u->user_email : '',
		'ci'     => (string) get_user_meta( $uid, 'wd_verified_ci', true ),
		'phones' => array_values( $phones ),
	);
}

function is_staff( int $uid ): bool {
	$u = get_userdata( $uid );
	return $u && array_intersect( (array) $u->roles, (array) apply_filters( 'duckhoo_referral_staff_roles', array( 'administrator', 'shop_manager' ) ) );
}

/** 회원의 코드 — 없으면 만든다 (겹치지 않게). */
function code_of( int $uid ): string {
	$c = (string) get_user_meta( $uid, CODE, true );
	if ( '' !== clean_code( $c ) ) {
		return $c;
	}
	for ( $i = 0; $i < 8; $i++ ) {
		$c = make_code();
		if ( 0 === by_code( $c ) ) {
			update_user_meta( $uid, CODE, $c );
			return $c;
		}
	}
	return '';
}

/** 코드 → 회원 번호 (없으면 0). */
function by_code( string $code ): int {
	$code = clean_code( $code );
	if ( '' === $code ) {
		return 0;
	}
	$ids = get_users( array( 'meta_key' => CODE, 'meta_value' => $code, 'fields' => 'ID', 'number' => 2 ) );
	return 1 === count( $ids ) ? (int) $ids[0] : 0;
}

/** 번호 → 회원 번호. 여럿이면 본인확인된 한 사람일 때만 (쌍둥이는 같은 이메일). */
function by_phone( string $phone ): int {
	global $wpdb;
	$phone = clean_phone( $phone );
	if ( '' === $phone || ! isset( $wpdb ) ) {
		return 0;
	}
	$like = '%' . $wpdb->esc_like( substr( $phone, -4 ) );
	$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key IN ('wd_verified_phone','wd_phone','billing_phone') AND meta_value LIKE %s", $like ), ARRAY_A ); // phpcs:ignore WordPress.DB
	$ids  = array();
	foreach ( $rows as $r ) {
		if ( clean_phone( (string) $r['meta_value'] ) === $phone ) {
			$ids[ (int) $r['user_id'] ] = (int) $r['user_id'];
		}
	}
	if ( 1 === count( $ids ) ) {
		return (int) reset( $ids );
	}
	$ver = array_values( array_filter( $ids, fn( $id ) => '1' === (string) get_user_meta( $id, 'wd_phone_verified', true ) ) );
	return 1 === count( $ver ) ? (int) $ver[0] : 0;
}

/** 적은 것 → 추천인 번호. */
function resolve( string $raw ): int {
	$p = parse_input( $raw );
	if ( 'code' === $p['kind'] ) {
		return by_code( $p['value'] );
	}
	if ( 'phone' === $p['kind'] ) {
		return by_phone( $p['value'] );
	}
	return 0;
}

/* ── 가입 ───────────────────────────────────────────────────────────── */

/** 가입이 끝나면 추천인을 적는다. 칸이 왔으면 칸(비웠으면 없음), 칸이 없으면(JS 실패) 쿠키. */
function on_register( $uid ): void {
	$uid = (int) $uid;
	if ( $uid <= 0 || ! live() ) {
		return;
	}
	// phpcs:disable WordPress.Security.NonceVerification -- 테마 가입 폼이 자기 논스를 본다.
	if ( isset( $_POST[ FIELD ] ) ) {
		$raw = (string) wp_unslash( $_POST[ FIELD ] );
	} else {
		$raw = isset( $_COOKIE[ COOKIE ] ) ? (string) wp_unslash( $_COOKIE[ COOKIE ] ) : '';
	}
	// phpcs:enable
	$raw = trim( mb_substr( $raw, 0, 40 ) );
	if ( '' === $raw ) {
		return;
	}
	$ref = resolve( $raw );
	update_user_meta( $uid, RAW, sanitize_text_field( $raw ) );
	update_user_meta( $uid, AT, time() );
	if ( $ref > 0 && $ref !== $uid && ! is_staff( $ref ) ) {
		update_user_meta( $uid, BY, $ref );
		update_user_meta( $uid, STATE, '' );
	} else {
		update_user_meta( $uid, STATE, 'blocked:' . ( $ref > 0 ? ( $ref === $uid ? 'self' : 'staff' ) : 'notfound' ) );
	}
	if ( ! headers_sent() ) {
		setcookie( COOKIE, '', array( 'expires' => time() - 86400, 'path' => '/' ) );
	}
}
add_action( 'user_register', __NAMESPACE__ . '\\on_register', 25 );

/** 가입 3단계 폼에 칸 하나. 폼은 테마 템플릿이라 훅이 없다 — 가입하기 버튼 앞에 스크립트로 넣는다. */
function join_field(): void {
	if ( ! live() || is_user_logged_in() || ! function_exists( 'is_page' ) || ! is_page( 'join-form' ) ) {
		return;
	}
	$c    = conf();
	$pre  = isset( $_COOKIE[ COOKIE ] ) ? clean_code( (string) wp_unslash( $_COOKIE[ COOKIE ] ) ) : '';
	$help = sprintf( '친구에게 받은 추천 코드나 친구의 휴대폰 번호를 적어 주세요. 첫 주문 입금이 확인되면 %s원을 드립니다.', number_format( $c['new_amt'] ) );
	$html = '<div class="wd-join-field dhr-ref-field"><label class="wd-join-label" for="dhrRef">추천 코드 <span style="font-weight:500">(선택)</span></label>'
		. '<input type="text" name="' . FIELD . '" id="dhrRef" class="wd-join-input" placeholder="예: DH7K3P 또는 010-0000-0000" maxlength="20" autocomplete="off" autocapitalize="characters" value="' . esc_attr( $pre ) . '">'
		. '<div class="wd-join-help">' . esc_html( $help ) . '</div></div>';
	?>
<script>(function(){function go(){var f=document.querySelector('form.wd-join-form');if(!f||f.querySelector('[name="<?php echo esc_js( FIELD ); ?>"]'))return;var w=f.querySelector('.wd-join-submit-wrap')||f.querySelector('.wd-join-submit');var d=document.createElement('div');d.innerHTML=<?php echo wp_json_encode( $html ); ?>;var n=d.firstChild;if(w&&w.parentNode)w.parentNode.insertBefore(n,w);else f.appendChild(n);}if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',go);else go();})();</script>
	<?php
}
add_action( 'wp_footer', __NAMESPACE__ . '\\join_field', 30 );

/** front.js — `?ref=` 를 쿠키에 적을지. */
function js( array $cfg ): array {
	$cfg['refCookie'] = live() ? COOKIE : '';
	return $cfg;
}
add_filter( 'duckhoo_js_config', __NAMESPACE__ . '\\js' );

/* ── 지급 · 회수 ─────────────────────────────────────────────────────── */

/** 이번 달 이 추천인 몫으로 지급된 수. */
function ref_month( int $ref ): int {
	global $wpdb;
	if ( ! isset( $wpdb ) ) {
		return 0;
	}
	$start = (int) strtotime( wp_date( 'Y-m-01 00:00:00' ) . ' ' . wp_timezone_string() );
	return (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
		"SELECT COUNT(*) FROM {$wpdb->usermeta} b JOIN {$wpdb->usermeta} p ON p.user_id = b.user_id AND p.meta_key = %s
		 WHERE b.meta_key = %s AND b.meta_value = %s AND CAST(p.meta_value AS UNSIGNED) >= %d",
		PAID_AT . '_ref', BY, (string) $ref, $start
	) );
}

/** 주문이 입금확인 이후로 넘어가면. */
function on_status( $order_id, $from, $to, $order = null ): void {
	if ( ! in_array( (string) $to, paid_statuses(), true ) ) {
		return;
	}
	$order = is_object( $order ) ? $order : ( function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null );
	if ( ! is_object( $order ) || ! method_exists( $order, 'get_customer_id' ) || 'shop_order' !== $order->get_type() ) {
		return;
	}
	$new = (int) $order->get_customer_id();
	$ref = $new > 0 ? (int) get_user_meta( $new, BY, true ) : 0;
	if ( $ref <= 0 || '' !== (string) get_user_meta( $new, STATE, true ) || $order->get_meta( ORDER, true ) ) {
		return; // 추천 기록 없음 · 이미 지급(또는 막힘) · 이 주문으로 이미 처리.
	}
	$c   = conf();
	$at  = (int) get_user_meta( $new, AT, true );
	$v   = verdict( $c, array(
		'ref'       => $ref,
		'new'       => $new,
		'same'      => get_userdata( $ref ) ? same_person( who( $ref ), who( $new ) ) : '',
		'ref_staff' => is_staff( $ref ),
		'ref_gone'  => ! get_userdata( $ref ) || '' !== (string) get_user_meta( $ref, '_duckhoo_withdrawn_at', true ),
		'total'     => (float) $order->get_total(),
		'since'     => $at > 0 ? ( time() - $at ) / DAY_IN_SECONDS : 0,
		'today'     => wp_date( 'Y-m-d' ),
		'ref_month' => ref_month( $ref ),
	) );
	if ( 0 === $v['new'] && 0 === $v['ref'] ) {
		// 금액 미달은 다음 주문을 기다린다. 나머지는 끝.
		if ( 'min' !== $v['why'] && 'off' !== $v['why'] ) {
			update_user_meta( $new, STATE, 'blocked:' . $v['why'] );
		}
		$order->add_order_note( '친구 추천 적립금 지급 안 함: ' . why_text( $v['why'] ) );
		return;
	}
	if ( ! function_exists( '\\Duckhoo\\Redesign\\Points\\grant' ) || ! \Duckhoo\Redesign\Points\can_return() ) {
		$order->add_order_note( '친구 추천 적립금을 줄 수 없습니다 (적립금 원장 함수 없음). 손으로 넣어 주세요.' );
		return;
	}
	update_user_meta( $new, STATE, 'paid' ); // 먼저 적어 두 번 안 준다.
	$g_new = $v['new'] > 0 && \Duckhoo\Redesign\Points\grant( $new, $v['new'], '추천 가입 적립금' ) ? $v['new'] : 0;
	$g_ref = $v['ref'] > 0 && \Duckhoo\Redesign\Points\grant( $ref, $v['ref'], '친구 추천 적립금' ) ? $v['ref'] : 0;
	update_user_meta( $new, PAID_AT, time() );
	if ( $g_ref > 0 ) {
		update_user_meta( $new, PAID_AT . '_ref', time() );
	}
	$order->update_meta_data( ORDER, array( 'ref' => $ref, 'ref_amt' => $g_ref, 'new_amt' => $g_new, 'at' => time() ) );
	$order->add_order_note( sprintf( '친구 추천 적립금: 추천받은 분 %s원 · 추천인(회원 #%d) %s원%s', number_format( $g_new ), $ref, number_format( $g_ref ), 'cap' === $v['why'] ? ' — 추천인 이번 달 상한' : '' ) );
	$order->save();
}
add_action( 'woocommerce_order_status_changed', __NAMESPACE__ . '\\on_status', 20, 4 );

/** 그 주문이 취소 · 환불되면 거둔다. 다음 주문에서 다시 받을 수 있게 기다림으로 돌린다. */
function on_void( $order_id, $order = null ): void {
	$order = is_object( $order ) ? $order : ( function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null );
	if ( ! is_object( $order ) ) {
		return;
	}
	$g = $order->get_meta( ORDER, true );
	if ( ! is_array( $g ) || ! empty( $g['void'] ) || ! function_exists( '\\Duckhoo\\Redesign\\Points\\grant' ) ) {
		return;
	}
	$new = (int) $order->get_customer_id();
	if ( (int) $g['new_amt'] > 0 ) {
		\Duckhoo\Redesign\Points\grant( $new, -(int) $g['new_amt'], '추천 가입 적립금 회수 (주문 취소)' );
	}
	if ( (int) $g['ref_amt'] > 0 ) {
		\Duckhoo\Redesign\Points\grant( (int) $g['ref'], -(int) $g['ref_amt'], '친구 추천 적립금 회수 (주문 취소)' );
	}
	$g['void'] = time();
	$order->update_meta_data( ORDER, $g );
	$order->add_order_note( '주문 취소로 친구 추천 적립금을 거뒀습니다.' );
	$order->save();
	update_user_meta( $new, STATE, '' );
	delete_user_meta( $new, PAID_AT );
	delete_user_meta( $new, PAID_AT . '_ref' );
}
add_action( 'woocommerce_order_status_cancelled', __NAMESPACE__ . '\\on_void', 20, 2 );
add_action( 'woocommerce_order_status_refunded', __NAMESPACE__ . '\\on_void', 20, 2 );

/* ── 마이페이지 카드 ────────────────────────────────────────────────── */

function card_html( int $uid ): string {
	if ( ! live() || $uid <= 0 || is_staff( $uid ) ) {
		return '';
	}
	$code = code_of( $uid );
	if ( '' === $code ) {
		return '';
	}
	$c    = conf();
	$link = link_for( $code );
	$sub  = sprintf( '친구가 이 코드로 가입하고 첫 주문(%s원 이상) 입금이 확인되면, 친구에게 %s원 · 나에게 %s원을 드립니다.', number_format( $c['min'] ), number_format( $c['new_amt'] ), number_format( $c['ref_amt'] ) );
	return '<section class="dhr-refcard" aria-labelledby="dhrRefT">'
		. '<h2 id="dhrRefT" class="dhr-refcard__t">친구 추천</h2>'
		. '<p class="dhr-refcard__s">' . esc_html( $sub ) . '</p>'
		. '<div class="dhr-refcard__row"><span class="dhr-refcard__k">내 코드</span><b class="dhr-refcard__code">' . esc_html( $code ) . '</b>'
		. '<button type="button" class="dhr-refcard__copy dhr-copy" data-copy="' . esc_attr( $code ) . '">코드 복사</button></div>'
		. '<div class="dhr-refcard__row"><span class="dhr-refcard__k">링크</span><span class="dhr-refcard__link">' . esc_html( $link ) . '</span>'
		. '<button type="button" class="dhr-refcard__copy dhr-copy" data-copy="' . esc_attr( $link ) . '">링크 복사</button></div>'
		. '</section>';
}

function account_card(): void {
	if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url() ) {
		return; // 첫 화면에서만.
	}
	echo card_html( get_current_user_id() ); // phpcs:ignore WordPress.Security.EscapeOutput
}
add_action( 'woocommerce_account_content', __NAMESPACE__ . '\\account_card', 5 );
add_shortcode( 'duckhoo_referral', fn() => card_html( get_current_user_id() ) );

/* ── 관리자 ─────────────────────────────────────────────────────────── */

function menu(): void {
	if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$cap    = current_user_can( 'manage_woocommerce' ) ? 'manage_woocommerce' : 'manage_options';
	$parent = isset( $GLOBALS['admin_page_hooks']['woocommerce-marketing'] ) ? 'woocommerce-marketing' : 'tools.php';
	add_submenu_page( $parent, '친구 추천', '친구 추천', $cap, SLUG, __NAMESPACE__ . '\\screen' );
}
add_action( 'admin_menu', __NAMESPACE__ . '\\menu', 99 );

function screen(): void {
	if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( isset( $_POST['dhr_ref_save'] ) && check_admin_referer( 'dhr_ref_save' ) ) {
		$in = wp_unslash( $_POST );
		update_option( OPT, array(
			'on'      => ! empty( $in['on'] ),
			'ref_amt' => (int) ( $in['ref_amt'] ?? 0 ),
			'new_amt' => (int) ( $in['new_amt'] ?? 0 ),
			'min'     => (int) ( $in['min'] ?? 0 ),
			'cap'     => (int) ( $in['cap'] ?? 0 ),
			'days'    => (int) ( $in['days'] ?? 0 ),
			'until'   => sanitize_text_field( (string) ( $in['until'] ?? '' ) ),
		), false );
		echo '<div class="notice notice-success"><p>저장했습니다.</p></div>';
	}
	$c   = conf();
	$num = fn( string $k, string $label, string $tail ) => '<tr><th>' . esc_html( $label ) . '</th><td><input type="number" min="0" step="1" name="' . $k . '" value="' . esc_attr( (string) $c[ $k ] ) . '" style="width:120px"> ' . esc_html( $tail ) . '</td></tr>';

	echo '<div class="wrap"><h1>친구 추천</h1>';
	echo '<p>회원마다 추천 코드 · 링크가 생깁니다 (마이페이지 첫 화면). 친구가 가입 때 코드(또는 추천인 휴대폰 번호)를 적고, <b>첫 주문 입금이 확인되면</b> 두 사람에게 적립금이 들어갑니다. 그 주문이 취소 · 환불되면 거둡니다.</p>';
	echo '<form method="post">';
	wp_nonce_field( 'dhr_ref_save' );
	echo '<table class="form-table"><tr><th>켜기</th><td><label><input type="checkbox" name="on" value="1"' . checked( $c['on'], true, false ) . '> 친구 추천 받기 (끄면 가입 칸 · 마이페이지 카드 · 지급이 모두 멈춥니다)</label></td></tr>';
	echo $num( 'ref_amt', '추천인에게', '원' ); // phpcs:ignore WordPress.Security.EscapeOutput
	echo $num( 'new_amt', '추천받은 사람에게', '원' ); // phpcs:ignore WordPress.Security.EscapeOutput
	echo $num( 'min', '첫 주문 최소 실입금', '원 이상 (적립금 · 쿠폰을 뺀 금액)' ); // phpcs:ignore WordPress.Security.EscapeOutput
	echo $num( 'cap', '추천인 한 달 상한', '명 (넘으면 추천받은 사람만 받음 · 0 이면 제한 없음)' ); // phpcs:ignore WordPress.Security.EscapeOutput
	echo $num( 'days', '가입 뒤 기한', '일 안의 주문만 (0 이면 기한 없음)' ); // phpcs:ignore WordPress.Security.EscapeOutput
	echo '<tr><th>행사 끝</th><td><input type="date" name="until" value="' . esc_attr( $c['until'] ) . '"> 비우면 계속</td></tr></table>';
	echo '<p class="description">손님 적립금 내역에는 「친구 추천 적립금」 · 「추천 가입 적립금」으로 보입니다.</p>';
	submit_button( '저장', 'primary', 'dhr_ref_save' );
	echo '</form>';

	// 기록.
	$users = get_users( array( 'meta_key' => RAW, 'number' => 300, 'orderby' => 'registered', 'order' => 'DESC', 'fields' => array( 'ID', 'user_email', 'display_name', 'user_registered' ) ) );
	$by    = array();
	echo '<h2>추천 기록 (최근 300명)</h2>';
	if ( ! $users ) {
		echo '<p>아직 없습니다.</p></div>';
		return;
	}
	echo '<table class="widefat striped"><thead><tr><th>가입</th><th>추천받은 사람</th><th>적은 것</th><th>추천인</th><th>상태</th></tr></thead><tbody>';
	foreach ( $users as $u ) {
		$ref   = (int) get_user_meta( $u->ID, BY, true );
		$state = (string) get_user_meta( $u->ID, STATE, true );
		if ( $ref > 0 ) {
			$by[ $ref ] = ( $by[ $ref ] ?? 0 ) + ( 'paid' === $state ? 1 : 0 );
		}
		$st = 'paid' === $state ? '✅ 지급' : ( '' === $state ? '⏳ 첫 주문 기다림' : '⛔ ' . ( 'blocked:notfound' === $state ? '추천인 못 찾음' : why_text( substr( $state, 8 ) ) ) );
		$rn = $ref > 0 && ( $ru = get_userdata( $ref ) ) ? '<a href="' . esc_url( admin_url( 'user-edit.php?user_id=' . $ref ) ) . '">#' . $ref . ' ' . esc_html( $ru->display_name ) . '</a>' : '—';
		echo '<tr><td>' . esc_html( substr( (string) $u->user_registered, 0, 10 ) ) . '</td><td><a href="' . esc_url( admin_url( 'user-edit.php?user_id=' . $u->ID ) ) . '">#' . (int) $u->ID . ' ' . esc_html( $u->display_name ) . '</a></td><td><code>' . esc_html( (string) get_user_meta( $u->ID, RAW, true ) ) . '</code></td><td>' . $rn . '</td><td>' . esc_html( $st ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo '</tbody></table>';
	if ( $by ) {
		arsort( $by );
		echo '<h2>추천인별 지급 수</h2><table class="widefat striped" style="max-width:480px"><tbody>';
		foreach ( array_slice( $by, 0, 30, true ) as $ref => $n ) {
			$ru = get_userdata( $ref );
			echo '<tr><td>#' . (int) $ref . ' ' . esc_html( $ru ? $ru->display_name : '' ) . '</td><td>' . (int) $n . '명</td></tr>';
		}
		echo '</tbody></table>';
	}
	echo '</div>';
}
