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
	// 브랜드 페이지처럼 다른 파일이 아는 화면 (includes/seo.php).
	return (string) apply_filters( 'duckhoo_funnel_stage', '' );
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
 * 두 갈래 길 — 비회원과 회원은 **같은 깔때기를 걷지 않는다.**
 *
 * 이 가게는 비로그인으로 결제 화면에 들어갈 수 없다 (`/checkout/` → 302 → `/register/`).
 * 그래서 비회원의 길은 **가입 완료에서 끝나고**, 회원의 길은 거기서부터 주문까지다.
 * 한 표에 몰아 놓고 위에서 아래로 나눠 「앞 단계 대비 %」를 적으면 뜻이 없는 숫자가 된다 —
 * 실제로 269% · 500% · 284% 가 찍혔다. 손님이 첫 화면을 거치지 않고 상품 상세로 바로
 * 들어오고, 가입 세 장은 곁가지이기 때문이다. **그 칸을 뺐다.**
 *
 * `from` 은 그 줄을 어느 쪽 수로 읽을지다. 「가입 완료」는 서버가 회원으로 세지만
 * (한 순간 전까지 비회원이었다) 비회원의 길에서 읽어야 뜻이 맞는다.
 *
 * @return array<string,array{label:string,note:string,rows:array<string,string>}>
 */
function paths(): array {
	return array(
		'guest'  => array(
			'label' => '비회원 — 가입 벽까지',
			'note'  => '비회원은 결제 화면에 못 들어갑니다 (주소를 쳐도 가입 화면으로 돌려보냅니다). 그래서 이 길은 가입 완료에서 끝납니다. 「가입 완료」는 서버가 회원으로 세므로 회원 쪽 수입니다.',
			'rows'  => array(
				'home' => 'guest', 'list' => 'guest', 'product' => 'guest', 'cart_add' => 'guest',
				'cart' => 'guest', 'register' => 'guest', 'agree' => 'guest', 'join' => 'guest',
				'signup' => 'member',
			),
		),
		'member' => array(
			'label' => '회원 — 주문까지',
			'note'  => '로그인한 손님입니다. 담는 도중에 가입한 사람은 「담기」가 비회원 쪽에, 「결제 화면」이 이쪽에 잡힙니다 — 그때그때의 상태 그대로 셉니다.',
			'rows'  => array(
				'home' => 'member', 'list' => 'member', 'product' => 'member', 'cart_add' => 'member',
				'cart' => 'member', 'checkout' => 'member', 'order' => 'member',
			),
		),
	);
}

/**
 * 뜻이 있는 고리만 골라 이름을 붙인다.
 *
 * 나누는 두 수를 **화면에 그대로 적는다** — 무엇을 무엇으로 나눈 값인지 보이지 않으면
 * 비율은 믿을 수가 없다.
 *
 * @param array $c counts() 결과.
 * @return array<int,array{label:string,top:int,bottom:int,note:string}>
 */
function links( array $c ): array {
	$g = fn( string $s ): int => (int) ( $c[ $s ]['guest'] ?? 0 );
	$m = fn( string $s ): int => (int) ( $c[ $s ]['member'] ?? 0 );
	$a = fn( string $s ): int => $g( $s ) + $m( $s );

	return array(
		array(
			'label'  => '상품을 본 사람 중 담은 사람',
			'top'    => $a( 'cart_add' ),
			'bottom' => $a( 'product' ),
			'note'   => '낮으면 사진 · 가격 · 신뢰의 문제입니다.',
		),
		array(
			'label'  => '담은 비회원 중 가입을 시작한 사람',
			'top'    => $g( 'register' ),
			'bottom' => $g( 'cart_add' ),
			'note'   => '담아 놓고 가입 화면까지 오지 않은 사람이 여기서 빠집니다.',
		),
		array(
			'label'  => '가입을 시작해서 끝낸 사람',
			'top'    => $m( 'signup' ),
			'bottom' => $g( 'register' ),
			'note'   => '낮으면 본인확인 · 약관 · 정보 입력 세 장 중 한 곳입니다.',
		),
		array(
			'label'  => '담은 회원 중 결제 화면까지 간 사람',
			'top'    => $m( 'checkout' ),
			'bottom' => $m( 'cart_add' ),
			'note'   => '회원인데도 결제까지 안 갔다면 장바구니 · 금액 · 배송비를 봅니다.',
		),
		array(
			'label'  => '결제 화면에서 주문을 끝낸 사람',
			'top'    => $m( 'order' ),
			'bottom' => $m( 'checkout' ),
			'note'   => '낮으면 주문서 자체(입력 항목 · 입금 안내)의 문제입니다.',
		),
	);
}

/**
 * 「12 / 25 · 48%」 — 나눈 두 수를 같이 적는다. 나눌 것이 없으면 「—」.
 *
 * @param int $top    위.
 * @param int $bottom 아래.
 * @return string
 */
function ratio_text( int $top, int $bottom ): string {
	if ( $bottom <= 0 ) {
		return '—';
	}
	return sprintf( '%s / %s · %d%%', number_format_i18n( $top ), number_format_i18n( $bottom ), (int) round( $top / $bottom * 100 ) );
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

		echo '<div class="dhr-sl-cards">';
		foreach ( links( $c ) as $l ) {
			$txt = ratio_text( (int) $l['top'], (int) $l['bottom'] );
			if ( function_exists( 'Duckhoo\\Redesign\\Sales\\card' ) ) {
				\Duckhoo\Redesign\Sales\card( (string) $l['label'], $txt, (string) $l['note'] );
			} else {
				printf( '<div class="dhr-sl-card"><div class="dhr-sl-card__l">%s</div><div class="dhr-sl-card__v">%s</div><div class="dhr-sl-card__n">%s</div></div>', esc_html( (string) $l['label'] ), esc_html( $txt ), esc_html( (string) $l['note'] ) );
			}
		}
		echo '</div>';

		foreach ( paths() as $path ) {
			echo '<h4 class="dhr-sl-h4">' . esc_html( (string) $path['label'] ) . '</h4>';
			echo '<p class="dhr-sl-note">' . esc_html( (string) $path['note'] ) . '</p>';
			echo '<div class="dhr-sl-scroll"><table class="widefat striped"><thead><tr><th>단계</th><th>사람</th></tr></thead><tbody>';
			$stages = stages();
			foreach ( (array) $path['rows'] as $key => $who ) {
				$n = (int) ( $c[ $key ][ $who ] ?? 0 );
				printf(
					'<tr><td>%s%s</td><td class="dhr-sl-num">%s</td></tr>',
					esc_html( (string) $stages[ $key ]['label'] ),
					! empty( $stages[ $key ]['event'] ) ? ' <span class="dhr-sl-ev">서버</span>' : '',
					esc_html( number_format_i18n( $n ) )
				);
			}
			echo '</tbody></table></div>';
		}

		$stray = (int) ( $c['checkout']['guest'] ?? 0 );
		if ( $stray > 0 ) {
			printf(
				'<div class="dhr-sl-warn">비회원이 결제 화면에 %d명 잡혔습니다. 이 가게는 비로그인 결제가 막혀 있으니 (302 → 가입) 숫자를 다시 봐야 합니다.</div>',
				$stray
			);
		}
	}
	echo '<p class="dhr-sl-note">읽는 법 — 위 다섯 장이 각각 어디서 새는지를 말합니다. 두 수를 같이 적어 두었으니 사람 수가 적을 때는 비율보다 <b>두 수</b>를 보세요. 여덟 명 중 넷은 50%이지만 아직 아무 뜻도 아닙니다. 주문 뒤의 이야기(입금이 들어왔는가)는 위의 「지금 묶여 있는 돈」이 말해 줍니다.</p>';
	echo '</section>';
}
