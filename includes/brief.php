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
 * 매일 11시(KST) 클로드 루틴이 이 주소를 읽어 브리핑을 보낸다 (CLAUDE.md 「클로드 아침 브리핑」).
 *
 * @package Duckhoo\Redesign
 */

declare( strict_types = 1 );

namespace Duckhoo\Redesign\Brief;

defined( 'ABSPATH' ) || exit;

const OPT_KEY  = 'duckhoo_brief_key';
const OPT_LOG  = 'duckhoo_briefs';   // 최근 브리핑 글 (날짜 => 글), 14일치
const KEEP     = 14;

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
 * 브리핑 세션에 주는 **가게의 기억** — 저장소를 못 읽는 세션도 이것만으로 판단할 수 있게.
 *
 * CLAUDE.md 의 결정 가운데 브리핑이 알아야 하는 것만 추린다. 새 결정이 생기면 여기에도 한 줄.
 * 필터 `duckhoo_brief_rules`.
 *
 * @return string[]
 */
function rules(): array {
	return (array) apply_filters( 'duckhoo_brief_rules', array(
		'가게: 전자담배 액상 전문몰 액상덕후(duck-hoo.com). 결제는 무통장입금뿐 — 입금자명이 주문자명과 같아야 자동 입금확인. 19세 미만 판매 금지, 비로그인은 상품 사진이 「19」로 가려지고 결제 화면에 못 들어간다(가입 벽).',
		'주문 흐름: 입금전(on-hold) → 입금확인 → 배송준비중 → 배송완료. 평일 오후 4시 전 입금 확인분 당일 출고, 금요일 16시 뒤 · 주말 주문은 월요일 16시 출고. 고객센터 평일 11:00–18:00.',
		'사장님이 이미 「안 한다」고 정한 것 — 다시 제안하지 말 것: 노보 하루 구매 한도(1인 1세트 · 3세트 모두), 입금자명 불일치 자동 입금확인(수동 처리로 확정), 회원 이름을 인증기관 이름으로 동기화, 카드결제(PG) 도입, 「공식」이라는 낱말, 「다른 곳에서 품절」 같은 경쟁사 얘기.',
		'지금 돌고 있는 것: 노보 전 라인 재고 있음 홍보(홈 띠 · 분류 배너 · 브랜드 페이지), 노보 가격 인상 안내(끝날 때까지 유지), 사진 후기 1,000원 적립(승인 뒤 자동 지급), 쿠폰 한 번에 만들기(마케팅 메뉴), 깔때기 집계(9/10 부터), 네이버 서치어드바이저 수집 요청(9/24 노보 15종).',
		'후기는 배송완료 주문의 구매자만 쓸 수 있고 전 상품 후기가 거의 0 이다. 후기 쓰기 버튼은 주문내역에 이미 있다 — 부족한 것은 손님을 부르는 문자.',
		'아직 안 한 후보(사장님이 고르면 클로드가 만든다): ①마지막 주문 30일 넘은 회원 목록 + 쿠폰 문자(재구매) ②배송완료 문자에 「사진 후기 1,000원」 한 줄 ③배송준비중에 오래 머문 주문을 배송완료로 한 번에 넘기는 버튼(자동은 안 함) ④주문내역 「같은 구성 다시 담기」 ⑤젤로 크리스탈 팟을 색상별 상품 둘로 나누기(사장님 관리자 작업) ⑥얼려먹구싶오 단품 13종에 입호흡 분류 넣기.',
		'말: 건강 · 금연 · 순하다 · 해롭지 않다 를 쓰지 않는다(담배사업법). 사장님께는 짧고 평이하게, 추천은 하나로 못 박는다.',
		'숫자 읽는 법: 확정 매출 = 입금확인 이후 주문의 실제 입금액(적립금 · 쿠폰 · 할인 이미 뺀 값). 입금전은 아직 안 들어온 돈. 깔때기는 비회원 길(첫 화면→상세→담기→가입)과 회원 길(상세→담기→결제→주문)을 따로 본다 — 앞 단계 대비 %는 사람이 적을 때 100%를 넘을 수 있으니 두 수를 같이 적는다.',
	) );
}

/**
 * 저장된 최근 브리핑 (날짜 => 글), 최신이 앞.
 *
 * @return array<string,string>
 */
function recent( int $n = 5 ): array {
	$all = (array) get_option( OPT_LOG, array() );
	krsort( $all );
	return array_slice( array_map( 'strval', $all ), 0, max( 1, $n ), true );
}

/**
 * 오늘 브리핑을 저장한다 (같은 날은 덮어쓴다). 14일치만 남긴다.
 */
function save( string $text ): void {
	$all = (array) get_option( OPT_LOG, array() );
	$all[ (string) current_time( 'Y-m-d' ) ] = $text;
	krsort( $all );
	update_option( OPT_LOG, array_slice( $all, 0, KEEP, true ), false );
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
		'rules'     => rules(),
		'recent'    => recent( 5 ),
		'dow'       => array( '일', '월', '화', '수', '목', '금', '토' )[ (int) current_time( 'w' ) ],
	);
	set_transient( $key, $out, 10 * MINUTE_IN_SECONDS );
	return $out;
}

/**
 * REST.
 */
function routes(): void {
	// 브리핑 세션이 다 쓴 글을 여기로 보내면 사이트가 디스코드(오늘 할 일 화면의 웹훅)로 옮긴다.
	// 세션은 웹훅 주소를 몰라도 되고, 받는 곳을 바꿔도 루틴은 그대로다.
	register_rest_route( 'duckhoo/v1', '/brief', array(
		'methods'             => 'POST',
		'permission_callback' => fn( $req ) => authorized( $req ),
		'callback'            => function ( $req ) {
			$text = is_object( $req ) && method_exists( $req, 'get_param' ) ? (string) $req->get_param( 'text' ) : '';
			$text = trim( wp_strip_all_tags( $text ) );
			if ( '' === $text ) {
				return new \WP_Error( 'dhr_brief_empty', 'text 가 비어 있습니다', array( 'status' => 400 ) );
			}
			if ( mb_strlen( $text ) > 12000 ) {
				$text = mb_substr( $text, 0, 12000 );
			}
			save( $text );
			$n = function_exists( '\\Duckhoo\\Redesign\\Today\\discord_send' ) ? \Duckhoo\Redesign\Today\discord_send( $text ) : 0;
			$configured = function_exists( '\\Duckhoo\\Redesign\\Today\\discord_url' ) && '' !== \Duckhoo\Redesign\Today\discord_url();
			return rest_ensure_response( array( 'ok' => $n > 0, 'chunks' => $n, 'discord' => $configured ) );
		},
	) );
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
	$rc = recent( 7 );
	if ( $rc ) {
		echo '<div class="dhr-td__mail" style="margin-top:12px"><b>지난 브리핑</b> — 디스코드를 놓쳤을 때 여기서 봅니다.';
		foreach ( $rc as $day => $txt ) {
			echo '<details style="margin-top:6px"><summary style="cursor:pointer">' . esc_html( (string) $day ) . '</summary><pre style="white-space:pre-wrap;font-family:inherit;line-height:1.6;margin:6px 0 0;padding:10px;background:#f6f7f7;border-radius:8px">' . esc_html( (string) $txt ) . '</pre></details>';
		}
		echo '</div>';
	}
}
