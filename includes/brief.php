<?php
/**
 * 클로드 아침 브리핑 — 그날 숫자를 **클로드만 읽을 수 있는 주소**로 내준다.
 *
 * 사장님 (2026-09-24): 「클로드가 나에게 알림 메시지나 정리한 걸 보내줬으면 해 — 어떤 작업이 매출에 도움이
 * 되는지, 어떤 작업을 해야 더 편하게 일하는지, 손님이 더 편하게 주문하는지」. 판단은 클로드가 하고,
 * 숫자는 이 주소가 준다: `GET /wp-json/duckhoo/v1/brief` + 헤더 `X-DHR-Key: <키>`.
 *
 * - **집계 숫자만** 내준다 — 이름 · 연락처 · 주문 번호 · 상품별 판매가 같은 것은 없다
 * - 키는 옵션 `duckhoo_brief_key` (오늘 할 일 화면에서 보고 · 새로 만든다). 비교는 `hash_equals`.
 *   키가 없으면 주소 자체가 닫혀 있다 (403)
 * - 읽기만 한다. 오늘 할 일(`Today\facts`)과 같은 숫자에 7일 · 전 7일 · 깔때기 · 카탈로그를 더한다
 *
 * 매일 아침 8시 30분(KST) 클로드 루틴이 이 주소를 읽어 브리핑을 보낸다 (CLAUDE.md 「클로드 아침 브리핑」).
 *
 * @package Duckhoo\Redesign
 */

declare( strict_types = 1 );

namespace Duckhoo\Redesign\Brief;

defined( 'ABSPATH' ) || exit;

const OPT_KEY = 'duckhoo_brief_key';

/**
 * 키. 없으면 ''.
 */
function key(): string {
	return trim( (string) get_option( OPT_KEY, '' ) );
}

/**
 * 키를 새로 만든다 (32자 16진).
 */
function new_key(): string {
	$k = function_exists( 'wp_generate_password' ) ? (string) wp_generate_password( 40, false, false ) : bin2hex( random_bytes( 20 ) );
	$k = strtolower( (string) preg_replace( '/[^A-Za-z0-9]/', '', $k ) );
	if ( strlen( $k ) < 24 ) {
		$k = bin2hex( random_bytes( 20 ) );
	}
	update_option( OPT_KEY, $k, false );
	return $k;
}

/**
 * 요청에 실린 키가 맞는가. 헤더 `X-DHR-Key` 또는 `Authorization: Bearer …`.
 *
 * @param mixed  $req 요청 (get_header 가 있는 것).
 * @param string $key 비교할 키 (테스트용, 비우면 옵션).
 */
function authorized( $req, string $key = '' ): bool {
	$key = '' !== $key ? $key : key();
	if ( '' === $key || ! is_object( $req ) || ! method_exists( $req, 'get_header' ) ) {
		return false;
	}
	$given = trim( (string) $req->get_header( 'x_dhr_key' ) );
	if ( '' === $given ) {
		$auth = trim( (string) $req->get_header( 'authorization' ) );
		if ( 0 === stripos( $auth, 'Bearer ' ) ) {
			$given = trim( substr( $auth, 7 ) );
		}
	}
	return '' !== $given && hash_equals( $key, $given );
}

/**
 * 오늘 할 일의 사실에서 **숫자만** 남긴다 (번호 목록은 뺀다).
 *
 * @param array<string,mixed> $f Today\facts().
 * @return array<string,mixed>
 */
function strip_facts( array $f ): array {
	$n = fn( $v ) => is_array( $v ) ? (int) ( $v['n'] ?? 0 ) : (int) $v;
	return array(
		'onhold'         => $n( $f['onhold'] ?? 0 ),
		'onhold_stale'   => (int) ( $f['onhold']['stale'] ?? 0 ),
		'need_check'     => $n( $f['check'] ?? 0 ),
		'sms_unmatched'  => (int) ( $f['sms'] ?? 0 ),
		'to_ship'        => $n( $f['to_ship'] ?? 0 ),
		'no_tracking'    => $n( $f['no_track'] ?? 0 ),
		'stuck_ready'    => $n( $f['stuck'] ?? 0 ),
		'inquiries_open' => (int) ( $f['inq']['n'] ?? -1 ),
		'reviews_hold'   => (int) ( $f['reviews'] ?? 0 ),
		'out_of_stock'   => (int) ( $f['stock']['out'] ?? 0 ),
		'low_stock'      => array_values( (array) ( $f['stock']['low'] ?? array() ) ),
		'coupons_expiring' => (int) ( $f['coupons'] ?? 0 ),
		'holiday'        => '' !== (string) ( $f['holiday']['k'] ?? '' ) ? (string) $f['holiday']['eb'] . ' — ' . (string) $f['holiday']['k'] : '',
	);
}

/**
 * 한 기간의 주문 · 매출 · 취소 · 가입.
 *
 * @param string $from Y-m-d.
 * @param string $to   Y-m-d.
 * @return array{orders:int,sales:float,cancelled:int,signups:int,avg:float}
 */
function period( string $from, string $to ): array {
	$r = array( 'orders' => 0, 'sales' => 0.0, 'cancelled' => 0, 'signups' => 0, 'avg' => 0.0 );
	if ( function_exists( 'wc_get_orders' ) ) {
		$void = function_exists( '\\Duckhoo\\Redesign\\Sales\\void_statuses' ) ? \Duckhoo\Redesign\Sales\void_statuses() : array( 'cancelled', 'refunded', 'failed', 'checkout-draft' );
		$paid = function_exists( '\\Duckhoo\\Redesign\\Sales\\confirmed_statuses' ) ? \Duckhoo\Redesign\Sales\confirmed_statuses() : array( 'payment-confirmed', 'ready-to-ship', 'delivered', 'completed' );
		$paid_n = 0;
		foreach ( (array) wc_get_orders( array( 'date_created' => $from . '...' . $to, 'limit' => 1500, 'status' => 'any', 'type' => 'shop_order' ) ) as $o ) {
			if ( ! is_object( $o ) || ! method_exists( $o, 'get_status' ) ) {
				continue;
			}
			$s = (string) $o->get_status();
			if ( in_array( $s, array( 'cancelled', 'refunded' ), true ) ) {
				++$r['cancelled'];
			}
			if ( in_array( $s, $void, true ) ) {
				continue;
			}
			++$r['orders'];
			if ( in_array( $s, $paid, true ) ) {
				$r['sales'] += (float) $o->get_total();
				++$paid_n;
			}
		}
		$r['avg'] = $paid_n > 0 ? round( $r['sales'] / $paid_n ) : 0.0;
	}
	global $wpdb;
	if ( isset( $wpdb ) ) {
		$r['signups'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->users . ' WHERE user_registered >= %s AND user_registered <= %s', $from . ' 00:00:00', $to . ' 23:59:59' ) ); // phpcs:ignore WordPress.DB
	}
	return $r;
}

/**
 * 브리핑에 실을 것 전부. 10분 캐시.
 *
 * @return array<string,mixed>
 */
function payload(): array {
	$today = (string) current_time( 'Y-m-d' );
	$key   = 'dhr_brief_' . $today . '_' . (string) current_time( 'H' ) . substr( (string) current_time( 'i' ), 0, 1 );
	$hit   = get_transient( $key );
	if ( is_array( $hit ) ) {
		return $hit;
	}
	$d = fn( int $back ): string => gmdate( 'Y-m-d', strtotime( $today . ' -' . $back . ' days' ) );

	$facts = function_exists( '\\Duckhoo\\Redesign\\Today\\facts' ) ? \Duckhoo\Redesign\Today\facts() : array();
	$open  = array();
	if ( function_exists( '\\Duckhoo\\Redesign\\Today\\build' ) ) {
		foreach ( \Duckhoo\Redesign\Today\build( $facts, \Duckhoo\Redesign\Today\ctx() ) as $it ) {
			if ( empty( $it['info'] ) && (int) $it['n'] > 0 ) {
				$open[] = array( 'group' => (string) $it['group'], 'title' => (string) $it['title'], 'n' => (int) $it['n'] );
			}
		}
	}

	$funnel = array();
	if ( function_exists( '\\Duckhoo\\Redesign\\Funnel\\counts' ) ) {
		$funnel = array( 'days7' => \Duckhoo\Redesign\Funnel\counts( 7 ), 'days30' => \Duckhoo\Redesign\Funnel\counts( 30 ) );
	}

	$catalog = array();
	if ( function_exists( 'wc_get_products' ) ) {
		$catalog['products'] = count( (array) wc_get_products( array( 'status' => 'publish', 'limit' => -1, 'return' => 'ids' ) ) );
	}
	if ( function_exists( 'get_comments' ) ) {
		$c = get_comments( array( 'type' => 'review', 'status' => 'approve', 'count' => true ) );
		$catalog['reviews'] = is_numeric( $c ) ? (int) $c : 0;
	}

	$site = array();
	if ( function_exists( '\\Duckhoo\\Redesign\\Front\\announce' ) ) {
		$site['announce'] = (string) \Duckhoo\Redesign\Front\announce();
	}
	if ( function_exists( '\\Duckhoo\\Redesign\\Novo\\price_brief' ) ) {
		$site['novo_prices'] = (string) \Duckhoo\Redesign\Novo\price_brief();
	}
	if ( function_exists( '\\Duckhoo\\Redesign\\Front\\notices' ) ) {
		$site['notices'] = array_values( array_map( fn( $n ) => (string) ( $n['eb'] ?? '' ) . ': ' . (string) ( $n['k'] ?? '' ), (array) \Duckhoo\Redesign\Front\notices() ) );
	}

	$out = array(
		'date'      => $today,
		'built'     => (string) current_time( 'Y-m-d H:i' ),
		'today'     => strip_facts( $facts ),
		'open'      => $open,
		'yesterday' => (array) ( $facts['yday'] ?? array() ),
		'week'      => period( $d( 6 ), $today ),
		'prev_week' => period( $d( 13 ), $d( 7 ) ),
		'month'     => period( gmdate( 'Y-m-01', strtotime( $today ) ), $today ),
		'funnel'    => $funnel,
		'catalog'   => $catalog,
		'site'      => $site,
	);
	set_transient( $key, $out, 10 * MINUTE_IN_SECONDS );
	return $out;
}

/**
 * REST.
 */
function routes(): void {
	register_rest_route( 'duckhoo/v1', '/brief', array(
		'methods'             => 'GET',
		'permission_callback' => fn( $req ) => authorized( $req ),
		'callback'            => function () {
			try {
				return rest_ensure_response( payload() );
			} catch ( \Throwable $e ) {
				return new \WP_Error( 'dhr_brief_failed', $e->getMessage(), array( 'status' => 500 ) );
			}
		},
	) );
}
add_action( 'rest_api_init', __NAMESPACE__ . '\\routes' );

/**
 * 오늘 할 일 화면 아래의 키 상자.
 */
function key_box(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['dhr_brief_new'] ) && isset( $_POST['dhr_today_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dhr_today_nonce'] ) ), 'dhr_today' ) ) {
		new_key();
	}
	$k = key();
	if ( '' === $k ) {
		$k = new_key();
	}
	echo '<form method="post" class="dhr-td__mail" style="margin-top:12px"><input type="hidden" name="dhr_today_nonce" value="' . esc_attr( wp_create_nonce( 'dhr_today' ) ) . '">';
	echo '<b>클로드 아침 브리핑 키</b> — 클로드가 매일 아침 이 가게의 숫자(집계만)를 읽는 데 쓰는 열쇠입니다. ';
	echo '클로드 환경 설정의 <b>환경 변수 <code>DUCKHOO_BRIEF_KEY</code></b> 에 한 번 넣어 두면 됩니다. 대화창에는 붙이지 마세요.<br>';
	echo '<input type="text" readonly value="' . esc_attr( $k ) . '" onclick="this.select()" style="width:100%;max-width:440px;font-family:monospace;margin:6px 0"> ';
	echo '<button class="button" name="dhr_brief_new" value="1" onclick="return confirm(\'새 키를 만들면 지금 키는 바로 닫힙니다. 환경 변수도 다시 넣어야 합니다.\')">새 키 만들기</button>';
	echo '<div style="color:#6b7280;margin-top:4px">주소: <code>' . esc_html( rest_url( 'duckhoo/v1/brief' ) ) . '</code> · 헤더 <code>X-DHR-Key</code>. 집계 숫자만 나가고 이름 · 연락처 · 주문 번호는 없습니다.</div>';
	echo '</form>';
}
