<?php
/**
 * 구매 깔때기 — 첫 화면부터 주문까지, 날마다 몇 명이 어디까지 갔는지 **플러그인이 직접 센다.**
 *
 * 왜 우리가 세는가. GA4 는 GTM 으로 붙어 있지만 이 자리에서는 읽을 수 없고,
 * 「어느 고리에서 손님이 빠지는지」 없이는 다음 손을 추정으로 정하게 된다.
 * 서버에서만 세면 안 된다 — 비로그인 화면은 페이지 캐시가 대신 내주므로 PHP 가 돌지 않는다.
 * 그래서 **화면 단계는 브라우저가 보내는 신호(beacon)** 로, **사건(담기 · 가입 완료 · 주문)은
 * 서버 훅**으로 센다. 봇은 대개 JS 를 돌리지 않아 저절로 걸러진다.
 *
 * 개인정보는 없다 — 날짜 × 단계 × (비회원/회원) 별 사람 수만 쌓는다.
 * 하루에 같은 단계는 한 번만 (브라우저가 localStorage 로 거른다).
 *
 * 표: {prefix}dhr_funnel (day, stage, who, n). 결과는 관리자 매출 화면의 「깔때기」.
 *
 * @package Duckhoo\Redesign
 */

namespace Duckhoo\Redesign\Funnel;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const TABLE = 'dhr_funnel';
const DBVER = '1';

/**
 * 단계 — 손님이 걷는 순서 그대로. 키는 저장 값, 값은 화면 이름.
 * `event` 는 화면이 아니라 서버 사건이라 beacon 으로는 받지 않는다.
 *
 * @return array<string,array{label:string,event:bool}>
 */
function stages(): array {
	return array(
		'home'     => array( 'label' => '첫 화면',   'event' => false ),
		'list'     => array( 'label' => '상품 목록', 'event' => false ),
		'product'  => array( 'label' => '상품 상세', 'event' => false ),
		'cart_add' => array( 'label' => '담기',      'event' => true ),
		'cart'     => array( 'label' => '장바구니',  'event' => false ),
		'register' => array( 'label' => '가입 시작', 'event' => false ),
		'agree'    => array( 'label' => '약관 동의', 'event' => false ),
		'join'     => array( 'label' => '정보 입력', 'event' => false ),
		'signup'   => array( 'label' => '가입 완료', 'event' => true ),
		'checkout' => array( 'label' => '결제 화면', 'event' => false ),
		'order'    => array( 'label' => '주문 완료', 'event' => true ),
	);
}

/**
 * 표 이름.
 *
 * @return string
 */
function table(): string {
	global $wpdb;
	return ( isset( $wpdb ) ? (string) $wpdb->prefix : 'wp_' ) . TABLE;
}

/**
 * 표를 한 번 만든다 (버전이 바뀔 때만 다시).
 *
 * @return void
 */
function ensure(): void {
	global $wpdb;
	if ( ! isset( $wpdb ) || DBVER === (string) get_option( 'duckhoo_funnel_db', '' ) ) {
		return;
	}
	if ( ! function_exists( 'dbDelta' ) ) {
		$f = ABSPATH . 'wp-admin/includes/upgrade.php';
		if ( is_readable( $f ) ) {
			require_once $f;
		}
	}
	if ( ! function_exists( 'dbDelta' ) ) {
		return;
	}
	$collate = method_exists( $wpdb, 'get_charset_collate' ) ? $wpdb->get_charset_collate() : '';
	dbDelta( 'CREATE TABLE ' . table() . " (
	day date NOT NULL,
	stage varchar(20) NOT NULL,
	who varchar(8) NOT NULL,
	n int unsigned NOT NULL DEFAULT 0,
	PRIMARY KEY  (day, stage, who)
	) {$collate};" );
	update_option( 'duckhoo_funnel_db', DBVER, false );
}
add_action( 'admin_init', __NAMESPACE__ . '\\ensure' );
add_action( 'rest_api_init', __NAMESPACE__ . '\\ensure', 5 );
add_action( 'init', function () {
	// 사건 훅이 관리자 밖에서도 표를 찾을 수 있게 — 첫 요청 한 번만 든다.
	if ( '' === (string) get_option( 'duckhoo_funnel_db', '' ) ) {
		ensure();
	}
}, 5 );

/**
 * 오늘 날짜 (사이트 시간).
 *
 * @return string
 */
function today(): string {
	return current_time( 'Y-m-d' );
}

/**
 * 한 번 센다. 같은 (날, 단계, 누구) 줄이 있으면 더한다 — 한 번의 질의라 동시에 와도 안 샌다.
 *
 * @param string $stage  단계.
 * @param bool   $member 로그인한 손님인가.
 * @param int    $n      더할 수.
 * @return bool
 */
function hit( string $stage, bool $member, int $n = 1 ): bool {
	global $wpdb;
	if ( ! isset( $wpdb ) || ! isset( stages()[ $stage ] ) || $n <= 0 ) {
		return false;
	}
	if ( ! apply_filters( 'duckhoo_funnel_on', true ) ) {
		return false;
	}
	$sql = $wpdb->prepare(
		'INSERT INTO ' . table() . ' (day, stage, who, n) VALUES (%s, %s, %s, %d) ON DUPLICATE KEY UPDATE n = n + VALUES(n)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		today(),
		$stage,
		$member ? 'member' : 'guest',
		$n
	);
	return false !== $wpdb->query( $sql ); // phpcs:ignore WordPress.DB
}

/**
 * 지금 그리는 화면이 어느 단계인가. 화면이 아니면 빈 문자열.
 *
 * 주소로 가른 셋(가입 3장)은 키플 페이지라 슬러그로 본다.
 *
 * @return string
 */
function page_stage(): string {
	if ( function_exists( 'is_admin' ) && is_admin() ) {
		return '';
	}
	if ( function_exists( 'is_front_page' ) && is_front_page() ) {
		return 'home';
	}
	if ( function_exists( 'is_product' ) && is_product() ) {
		return 'product';
	}
	if ( ( function_exists( 'is_shop' ) && is_shop() )
		|| ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() )
		|| ( function_exists( 'is_search' ) && is_search() && function_exists( 'is_post_type_archive' ) && is_post_type_archive( 'product' ) ) ) {
		return 'list';
	}
	if ( function_exists( 'is_cart' ) && is_cart() ) {
		return 'cart';
	}
	if ( function_exists( 'is_checkout' ) && is_checkout() ) {
		return 'checkout';
	}
	foreach ( array( 'register' => 'register', 'agree' => 'agree', 'join-form' => 'join' ) as $slug => $stage ) {
		if ( function_exists( 'is_page' ) && is_page( $slug ) ) {
			return $stage;
		}
	}
	return '';
}

/**
 * 봇인가 — UA 로 대충 거른다. JS 를 안 돌리는 봇은 어차피 신호를 안 보낸다.
 *
 * @param string $ua User-Agent.
 * @return bool
 */
function is_bot( string $ua ): bool {
	return '' === $ua || (bool) preg_match( '/bot|crawl|spider|slurp|preview|headless|lighthouse|facebookexternalhit|whatsapp|telegram|kakaotalk-scrap|yeti|daum/i', $ua );
}

/**
 * 브라우저가 보내는 신호를 받는 곳: POST /wp-json/duckhoo/v1/f  (s=단계, m=1|0).
 *
 * @return void
 */
function routes(): void {
	register_rest_route( 'duckhoo/v1', '/f', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => __NAMESPACE__ . '\\beacon',
	) );
}
add_action( 'rest_api_init', __NAMESPACE__ . '\\routes' );

/**
 * 신호 처리. 화면 단계만 받는다 — 사건(담기 · 가입 · 주문)은 서버가 직접 센다.
 *
 * @param mixed $req 요청.
 * @return mixed
 */
function beacon( $req ) {
	$stage  = is_object( $req ) && method_exists( $req, 'get_param' ) ? (string) $req->get_param( 's' ) : '';
	$member = is_object( $req ) && method_exists( $req, 'get_param' ) ? '1' === (string) $req->get_param( 'm' ) : false;
	$ua     = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : ''; // phpcs:ignore

	$ok = accept( $stage, $ua ) && hit( $stage, $member );

	return function_exists( 'rest_ensure_response' )
		? rest_ensure_response( array( 'ok' => (bool) $ok ) )
		: array( 'ok' => (bool) $ok );
}

/**
 * 신호를 받을 것인가 — 아는 화면 단계이고, 봇이 아닐 때만.
 *
 * @param string $stage 단계.
 * @param string $ua    User-Agent.
 * @return bool
 */
function accept( string $stage, string $ua ): bool {
	$s = stages();
	return isset( $s[ $stage ] ) && empty( $s[ $stage ]['event'] ) && ! is_bot( $ua );
}

// 서버 사건 — 캐시와 무관하게 정확하다.
add_action( 'woocommerce_add_to_cart', function () {
	hit( 'cart_add', function_exists( 'is_user_logged_in' ) && is_user_logged_in() );
}, 10, 0 );
add_action( 'user_register', function () {
	hit( 'signup', true );
}, 10, 0 );
add_action( 'woocommerce_checkout_order_processed', function () {
	hit( 'order', true );
}, 10, 0 );
add_action( 'woocommerce_store_api_checkout_order_processed', function () {
	hit( 'order', true );
}, 10, 0 );

/**
 * 화면 쪽에 단계와 신호 주소를 넘긴다 (window.DHR).
 *
 * @param array $cfg 여태 값.
 * @return array
 */
function js_config( array $cfg ): array {
	$stage = page_stage();
	if ( '' !== $stage && apply_filters( 'duckhoo_funnel_on', true ) ) {
		$cfg['stage']  = $stage;
		$cfg['beacon'] = function_exists( 'rest_url' ) ? rest_url( 'duckhoo/v1/f' ) : '/wp-json/duckhoo/v1/f';
	}
	return $cfg;
}
add_filter( 'duckhoo_js_config', __NAMESPACE__ . '\\js_config' );

/**
 * 최근 N일 합계: 단계 → [guest, member].
 *
 * @param int $days 며칠.
 * @return array<string,array{guest:int,member:int}>
 */
function counts( int $days ): array {
	global $wpdb;
	$out = array();
	foreach ( array_keys( stages() ) as $s ) {
		$out[ $s ] = array( 'guest' => 0, 'member' => 0 );
	}
	if ( ! isset( $wpdb ) ) {
		return $out;
	}
	$from = gmdate( 'Y-m-d', strtotime( today() . ' -' . max( 0, $days - 1 ) . ' days' ) );
	$rows = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
		'SELECT stage, who, SUM(n) AS n FROM ' . table() . ' WHERE day >= %s AND day <= %s GROUP BY stage, who', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$from,
		today()
	), ARRAY_A );
	foreach ( $rows as $r ) {
		$s = (string) ( $r['stage'] ?? '' );
		$w = (string) ( $r['who'] ?? '' );
		if ( isset( $out[ $s ] ) && ( 'guest' === $w || 'member' === $w ) ) {
			$out[ $s ][ $w ] = (int) $r['n'];
		}
	}
	return $out;
}

/**
 * 며칠째 세고 있는가 (첫 줄의 날짜).
 *
 * @return string
 */
function since(): string {
	global $wpdb;
	if ( ! isset( $wpdb ) ) {
		return '';
	}
	return (string) $wpdb->get_var( 'SELECT MIN(day) FROM ' . table() ); // phpcs:ignore WordPress.DB
}

/**
 * 매출 화면에 그리는 깔때기.
 *
 * @return void
 */
function render(): void {
	$since = since();
	echo '<section class="dhr-sl-sec dhr-sl-funnel"><h2>깔때기 — 손님이 어디까지 갔나</h2>';
	if ( '' === $since ) {
		echo '<p class="dhr-sl-note">아직 쌓인 것이 없습니다. 이 배포부터 세기 시작합니다 — 하루 이틀 지나면 여기에 숫자가 섭니다.</p></section>';
		return;
	}
	echo '<p class="dhr-sl-note">하루에 한 사람은 단계마다 한 번만 셉니다. 「담기 · 가입 완료 · 주문 완료」는 서버 사건이라 정확하고, 나머지 화면 단계는 브라우저가 보내는 신호라 JS 를 끈 손님과 봇은 빠집니다. '
		. esc_html( $since ) . ' 부터 세었습니다.</p>';

	foreach ( array( 7 => '최근 7일', 30 => '최근 30일' ) as $days => $label ) {
		$c = counts( $days );
		echo '<h3 class="dhr-sl-h3">' . esc_html( $label ) . '</h3>';
		echo '<div class="dhr-sl-scroll"><table class="widefat striped"><thead><tr><th>단계</th><th>사람</th><th>비회원</th><th>회원</th><th>앞 단계 대비</th></tr></thead><tbody>';
		$prev = null;
		foreach ( stages() as $key => $meta ) {
			$g   = (int) $c[ $key ]['guest'];
			$m   = (int) $c[ $key ]['member'];
			$all = $g + $m;
			$pct = ( null !== $prev && $prev > 0 ) ? sprintf( '%.0f%%', $all / $prev * 100 ) : '—';
			printf(
				'<tr><td>%s%s</td><td class="dhr-sl-num">%s</td><td class="dhr-sl-num dhr-sl-mut">%s</td><td class="dhr-sl-num dhr-sl-mut">%s</td><td class="dhr-sl-num dhr-sl-mut">%s</td></tr>',
				esc_html( $meta['label'] ),
				$meta['event'] ? ' <span class="dhr-sl-ev">서버</span>' : '',
				esc_html( number_format_i18n( $all ) ),
				esc_html( number_format_i18n( $g ) ),
				esc_html( number_format_i18n( $m ) ),
				esc_html( $pct )
			);
			$prev = $all;
		}
		echo '</tbody></table></div>';
	}
	echo '<p class="dhr-sl-note">읽는 법 — 「첫 화면 → 상품 상세」가 낮으면 고르기가 문제, 「상품 상세 → 담기」가 낮으면 사진 · 가격 · 신뢰, 「담기 → 가입 완료」가 낮으면 가입 벽, 「결제 화면 → 주문 완료」가 낮으면 주문서, 주문 뒤는 매출 화면의 입금 대기가 말해 줍니다.</p>';
	echo '</section>';
}
