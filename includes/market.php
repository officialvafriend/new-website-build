<?php
/**
 * 도구 → 시장가 대조 (읽기 전용).
 *
 * 사장님(2026-09-24): 「니코틴 액상을 빨리 처분하기 위함」. 2026-04-24 개정 담배사업법으로
 * 합성니코틴 액상은 온라인 판매가 금지됐고 지금 파는 것은 법 시행 전 재고뿐이다 — 재입고가
 * 없고 정부가 재고 판매에 기한을 걸 수 있다. 경쟁 가게 값을 전부 받아 나란히 놓으니 이름이
 * 맞는 47개 중 45개가 **우리가 비쌌다** (노보 낱병 13,000 vs 브이몬스터 11,500 · 겨울마을 9,000).
 * 처분이 느린 이유는 마케팅이 아니라 값이다.
 *
 * 이 화면은 경쟁 가게 두 곳의 값을 긁어 우리 상품 옆에 차이와 **제안 값**을 보여 준다.
 * **값을 바꾸지는 않는다** — 상품 값을 한꺼번에 고쳐 쓰는 코드는 이 작업 환경의 권한에서
 * 막혔다. 사장님이 표를 보고 워드커머스 상품 화면에서 직접 바꾼다.
 *
 * - **소매 손님 값만 본다.** 업자(도매 · 사업자)에게 파는 단은 없다 — 사장님 「절대 없음」.
 *   겨울마을의 50병 단(★대량구매★)은 참고로 적을 뿐 시장가로 세지 않는다
 * - 경쟁 값은 하루 한 번 크론이 받는다 (`duckhoo_market_fetch`, 05:10). 「지금 다시 읽기」는
 *   크론을 바로 깨운다 — 브이몬스터 검색 26쪽을 화면 요청 안에서 받으면 시간이 넘친다
 * - 이름 맞추기는 브랜드 + 맛 + 무니코틴 여부. 못 맞춘 줄은 「—」. 틀리게 맞춘 줄은
 *   「대조 상대 못 박기」로 고정한다 (`duckhoo_market_map`)
 * - 값을 내리면 **이미 담겨 있던 장바구니**는 옛 기준가로 남아 금액 점검(price-check)에
 *   걸린다 — 손님은 비우고 다시 담으면 된다. 화면 아래에 적어 둔다
 *
 * @package Duckhoo\Redesign
 */

declare( strict_types = 1 );

namespace Duckhoo\Redesign\Market;

defined( 'ABSPATH' ) || exit;

const SLUG     = 'duckhoo-market';
const OPT_DATA = 'duckhoo_market_data';
const OPT_MAP  = 'duckhoo_market_map';
const CRON     = 'duckhoo_market_fetch';

/**
 * 경쟁 가게. 필터 `duckhoo_market_sources`.
 *
 * @return array<string,array{label:string,kind:string,url:string,pages:int}>
 */
function sources(): array {
	return (array) apply_filters(
		'duckhoo_market_sources',
		array(
			'vm'  => array(
				'label' => '브이몬스터',
				'kind'  => 'vm',
				'url'   => 'https://www.vmonster.co.kr/shop/search.php?q=30ml&page=%d',
				'pages' => 30,
			),
			'w24' => array(
				'label' => '겨울마을',
				'kind'  => 'w24',
				'url'   => 'https://winter24.kr/wp-json/wc/store/v1/products?per_page=100&page=%d',
				'pages' => 6,
			),
		)
	);
}

/**
 * 브이몬스터 검색 결과 한 쪽.
 *
 * @param string $html 쪽 HTML.
 * @return array<int,array{name:string,price:int,reviews:int,out:bool,bulk:bool,set:int}>
 */
function parse_vm( string $html ): array {
	$out  = array();
	$blks = preg_split( '/<div class="item-list-wrap">/', $html );
	array_shift( $blks );
	foreach ( (array) $blks as $b ) {
		if ( ! preg_match( '/class="product-name">\s*<a[^>]*>\s*(.*?)\s*<\/a>/s', $b, $n ) || ! preg_match( '/class="title-price">([\d,]+)원/', $b, $p ) ) {
			continue;
		}
		$out[] = array(
			'name'    => trim( html_entity_decode( $n[1], ENT_QUOTES, 'UTF-8' ) ),
			'price'   => (int) str_replace( ',', '', $p[1] ),
			'reviews' => preg_match( '/REVIEW <em>(\d+)/', $b, $rv ) ? (int) $rv[1] : 0,
			'out'     => (bool) preg_match( '/soldout|sold-out|품절/i', substr( $b, 0, 6000 ) ),
			'bulk'    => false,
			'set'     => 0,
		);
	}
	return $out;
}

/**
 * 겨울마을 Store API 한 쪽.
 *
 * `★대량구매★` 는 50병 단 — 업자 값이라 **시장가로 세지 않고** 참고로만 둔다. `[10개 세트]` 는
 * 소매 손님도 사는 단이라 병당으로 환산한다 (`set` = 병 수). 「유통기한 이슈」 칸은 뺀다.
 *
 * @param array<int,array<string,mixed>> $items JSON 목록.
 * @return array<int,array{name:string,price:int,reviews:int,out:bool,bulk:bool,set:int}>
 */
function parse_w24( array $items ): array {
	$out = array();
	foreach ( $items as $it ) {
		if ( ! is_array( $it ) || '' === (string) ( $it['name'] ?? '' ) ) {
			continue;
		}
		$name = (string) $it['name'];
		if ( str_contains( $name, '유통기한' ) ) {
			continue;
		}
		$out[] = array(
			'name'    => $name,
			'price'   => (int) ( $it['prices']['price'] ?? 0 ),
			'reviews' => 0,
			'out'     => empty( $it['is_in_stock'] ),
			'bulk'    => str_contains( $name, '대량구매' ),
			'set'     => preg_match( '/\[(\d+)개 세트\]/u', $name, $m ) ? (int) $m[1] : 0,
		);
	}
	return $out;
}

/**
 * 이름을 견주기 위한 낱말 뭉치 — 브랜드 · 맛만 남긴다.
 */
function core( string $name ): string {
	$n = preg_replace( '/\[[^\]]*\]|\([^)]*\)/u', ' ', $name );
	$n = preg_replace( '/30ml|60ml|입호흡|폐호흡|액상|고농도|저농도|리퀴드|★|대량구매|무니코틴|\d+\s*개 세트|\d+\+\d+|묶음|EVENT|이벤트|금액.*원|시리즈|모음|기간한정|맛돌이|할인/iu', ' ', (string) $n );
	$n = mb_strtolower( (string) $n );
	return (string) preg_replace( '/[^0-9a-z가-힣]/u', '', $n );
}

/**
 * 이름 앞 `[브랜드]`. `[노보 리퀴드]` · `[노보 블랙 리퀴드]` 는 노보 · 노보 블랙으로 본다.
 */
function brand_of( string $name ): string {
	if ( ! preg_match( '/^\s*\[([^\]]+)\]/u', $name, $m ) ) {
		return '';
	}
	return trim( (string) preg_replace( '/\s*리퀴드$/u', '', trim( $m[1] ) ) );
}

/**
 * 브랜드 뒤에 남는 맛 이름 (뭉친 꼴). 묶음(10+1 · N병)은 빈 문자열.
 */
function flavor_of( string $name ): string {
	if ( preg_match( '/\d+\s*\+\s*\d+|\d+\s*병/u', $name ) ) {
		return '';
	}
	$c = core( (string) preg_replace( '/^\s*\[[^\]]+\]/u', '', $name ) );
	$b = core( brand_of( $name ) );
	if ( '' !== $b && str_starts_with( $c, $b ) ) {
		$c = substr( $c, strlen( $b ) ); // 「[맥스쿨] 맥스쿨 소다」 — 브랜드가 한 번 더 붙은 꼴
	}
	return (string) $c;
}

/**
 * 받는 병 수. 「10+1」 은 11, 「5병」 은 5, 아니면 1.
 */
function bottles( string $name ): int {
	if ( preg_match( '/(\d+)\s*\+\s*(\d+)/u', $name, $m ) ) {
		return (int) $m[1] + (int) $m[2];
	}
	if ( preg_match( '/(\d+)\s*병/u', $name, $m ) ) {
		return max( 1, (int) $m[1] );
	}
	return 1;
}

/**
 * 무니코틴인가. 니코틴판과 무니코틴판은 이름이 같아도 **다른 상품**이다.
 */
function nicfree( string $name ): bool {
	return str_contains( $name, '무니코틴' );
}

/**
 * 우리 상품 하나를 경쟁 목록(한 가게)에서 찾는다.
 *
 * 1) 못 박은 이름이 있으면 그것 2) 브랜드 + 맛이 다 들어 있는 낱병 3) 묶음이면 그 브랜드
 * 낱병 중 **재고 있는 가장 싼 것**. 무니코틴 여부가 다르면 안 맞춘 것으로 본다.
 * 「노보」를 찾는데 「노보 블랙리퀴드 …」이 걸리면 안 된다 (맛이 블랙멘솔인 것은 예외).
 *
 * @return array{single:?array,set:?array,bulk:?array}
 */
function find_row( string $name, array $rows, string $pin = '' ): array {
	$none = array( 'single' => null, 'set' => null, 'bulk' => null );
	if ( '' !== $pin ) {
		foreach ( $rows as $r ) {
			if ( (string) $r['name'] === $pin ) {
				return array( 'single' => $r, 'set' => null, 'bulk' => null );
			}
		}
		return $none;
	}
	$b = core( brand_of( $name ) );
	if ( '' === $b ) {
		return $none;
	}
	$black = str_contains( $b, '블랙' );
	$fl    = flavor_of( $name );
	$nf    = nicfree( $name );
	$cands = array();
	foreach ( $rows as $r ) {
		$c = core( (string) $r['name'] );
		if ( nicfree( (string) $r['name'] ) !== $nf || ! str_contains( $c, $b ) ) {
			continue;
		}
		if ( ! $black && str_contains( $c, '블랙' ) && ! str_contains( $fl, '블랙' ) ) {
			continue;
		}
		$cands[] = $r;
	}
	$pick = function ( array $list, callable $ok ): ?array {
		$best = null;
		foreach ( $list as $r ) {
			if ( $ok( $r ) && ( null === $best || (int) $r['price'] < (int) $best['price'] ) ) {
				$best = $r;
			}
		}
		return $best;
	};
	$single = null;
	if ( '' !== $fl ) {
		foreach ( $cands as $r ) {
			if ( ! $r['bulk'] && 0 === (int) $r['set'] && str_contains( core( (string) $r['name'] ), $fl ) ) {
				$single = $r;
				break;
			}
		}
		// 겨울마을처럼 맛이 옵션이라 이름에 브랜드만 있는 상품 — 이름이 브랜드와 똑같은 낱병으로 떨어진다
		if ( null === $single ) {
			$single = $pick( $cands, fn( $r ) => ! $r['bulk'] && 0 === (int) $r['set'] && core( (string) $r['name'] ) === $b && ! $r['out'] )
				?? $pick( $cands, fn( $r ) => ! $r['bulk'] && 0 === (int) $r['set'] && core( (string) $r['name'] ) === $b );
		}
	} else {
		$single = $pick( $cands, fn( $r ) => ! $r['bulk'] && 0 === (int) $r['set'] && ! $r['out'] )
			?? $pick( $cands, fn( $r ) => ! $r['bulk'] && 0 === (int) $r['set'] );
	}
	return array(
		'single' => $single,
		'set'    => $pick( $cands, fn( $r ) => (int) $r['set'] > 0 ),
		'bulk'   => $pick( $cands, fn( $r ) => (bool) $r['bulk'] ),
	);
}

/**
 * 우리 상품 목록 (이름 앞에 `[브랜드]` 가 있는 것만). 읽기만 한다.
 *
 * @return array<int,array{id:int,name:string,price:int,bottles:int}>
 */
function products(): array {
	if ( ! function_exists( 'wc_get_products' ) ) {
		return array();
	}
	$out = array();
	foreach ( (array) wc_get_products( array( 'status' => 'publish', 'limit' => -1 ) ) as $p ) {
		if ( ! is_object( $p ) || '' === brand_of( (string) $p->get_name() ) ) {
			continue;
		}
		$out[] = array(
			'id'      => (int) $p->get_id(),
			'name'    => (string) $p->get_name(),
			'price'   => (int) round( (float) $p->get_price() ),
			'bottles' => bottles( (string) $p->get_name() ),
		);
	}
	return $out;
}

/**
 * 대조표. 소매 손님이 사는 값(낱병 · 세트 병당)만 `min` 으로 센다 — 50병 대량 단은 안 센다.
 *
 * @param array<int,array<string,mixed>>               $ours products().
 * @param array<string,array<int,array<string,mixed>>> $data 가게별 parse_* 결과.
 * @param array<int,string>                            $map  상품 id → 못 박은 경쟁 이름 (`vm:이름` 이면 그 가게만).
 * @return array<int,array<string,mixed>>
 */
function compare( array $ours, array $data, array $map = array() ): array {
	$rows = array();
	foreach ( $ours as $o ) {
		$per  = (int) round( $o['price'] / max( 1, (int) $o['bottles'] ) );
		$comp = array();
		$min  = 0;
		$pin  = (string) ( $map[ (int) $o['id'] ] ?? '' );
		foreach ( $data as $sid => $rows_s ) {
			$p_here = $pin;
			if ( '' !== $pin && preg_match( '/^([a-z0-9]+):(.+)$/u', $pin, $pm ) ) {
				$p_here = $pm[1] === (string) $sid ? $pm[2] : '';
			}
			$m = find_row( (string) $o['name'], (array) $rows_s, $p_here );
			$s = $m['single'];
			$comp[ $sid ] = array(
				'single'  => $s ? (int) $s['price'] : 0,
				'reviews' => $s ? (int) $s['reviews'] : 0,
				'out'     => $s ? (bool) $s['out'] : false,
				'name'    => $s ? (string) $s['name'] : '',
				'set'     => $m['set'] ? (int) round( (int) $m['set']['price'] / max( 1, (int) $m['set']['set'] ) ) : 0,
				'bulk'    => $m['bulk'] ? (int) $m['bulk']['price'] : 0,
			);
			foreach ( array( $comp[ $sid ]['single'], $comp[ $sid ]['set'] ) as $v ) {
				if ( $v > 0 && ( 0 === $min || $v < $min ) ) {
					$min = $v;
				}
			}
		}
		$rows[] = $o + array(
			'per'  => $per,
			'comp' => $comp,
			'min'  => $min,
			'gap'  => $min > 0 ? (int) round( ( $per - $min ) / $min * 100 ) : null,
		);
	}
	return $rows;
}

/**
 * 제안 값 (화면에 적기만 한다). 기준: `vm` · `w24` · `min`(둘 중 싼 것).
 * 묶음은 병당 값 × 병 수, 100원 단위로 내린다. 기준 값이 없으면 0.
 */
function suggest( array $row, string $mode ): int {
	if ( 'min' === $mode ) {
		$per = (int) $row['min'];
	} else {
		$c   = (array) ( $row['comp'][ $mode ] ?? array() );
		$per = (int) ( $c['single'] ?? 0 );
		if ( (int) $row['bottles'] > 1 && (int) ( $c['set'] ?? 0 ) > 0 ) {
			$per = (int) $c['set'];
		}
	}
	return $per > 0 ? (int) ( floor( $per * max( 1, (int) $row['bottles'] ) / 100 ) * 100 ) : 0;
}

/**
 * 경쟁 값을 받아 옵션에 둔다. 크론이 부른다. 이번에 못 받은 가게는 지난 값을 둔다.
 *
 * @return array{fetched:array<string,int>,partial:bool}
 */
function fetch( int $budget = 90 ): array {
	$t0      = microtime( true );
	$data    = array();
	$partial = false;
	foreach ( sources() as $sid => $src ) {
		$rows = array();
		for ( $p = 1; $p <= (int) $src['pages']; $p++ ) {
			if ( microtime( true ) - $t0 > $budget ) {
				$partial = true;
				break 2;
			}
			$res = wp_remote_get(
				sprintf( (string) $src['url'], $p ),
				array( 'timeout' => 20, 'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120 Safari/537.36' )
			);
			if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
				break;
			}
			$body = (string) wp_remote_retrieve_body( $res );
			$got  = 'w24' === (string) $src['kind'] ? parse_w24( (array) json_decode( $body, true ) ) : parse_vm( $body );
			if ( ! $got ) {
				break;
			}
			$rows = array_merge( $rows, $got );
		}
		$data[ $sid ] = $rows;
	}
	$old = (array) get_option( OPT_DATA, array() );
	foreach ( $data as $sid => $rows ) {
		if ( ! $rows && ! empty( $old['rows'][ $sid ] ) ) {
			$data[ $sid ] = $old['rows'][ $sid ];
		}
	}
	update_option( OPT_DATA, array( 'rows' => $data, 'at' => (string) current_time( 'mysql' ), 'partial' => $partial ), false );
	return array( 'fetched' => array_map( 'count', $data ), 'partial' => $partial );
}
add_action( CRON, __NAMESPACE__ . '\\fetch' );

/**
 * 하루 한 번 (사이트 시간 05:10).
 */
function schedule(): void {
	if ( ! function_exists( 'wp_next_scheduled' ) || wp_next_scheduled( CRON ) ) {
		return;
	}
	$tz    = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'Asia/Seoul' );
	$first = new \DateTime( 'today 05:10', $tz );
	if ( $first->getTimestamp() <= time() ) {
		$first->modify( '+1 day' );
	}
	wp_schedule_event( $first->getTimestamp(), 'daily', CRON );
}
add_action( 'admin_init', __NAMESPACE__ . '\\schedule' );

/* ------------------------------------------------------------------ 관리자 화면 */

function may(): bool {
	return function_exists( 'current_user_can' ) && ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' ) );
}

function menu(): void {
	if ( ! may() ) {
		return;
	}
	add_management_page( '시장가 대조', '시장가 대조', 'manage_woocommerce', SLUG, __NAMESPACE__ . '\\screen' );
}
add_action( 'admin_menu', __NAMESPACE__ . '\\menu' );

/**
 * POST → 처리 → 같은 화면으로 (새로고침으로 두 번 돌지 않게). 상품에는 아무것도 쓰지 않는다.
 */
function handle(): void {
	if ( ! isset( $_POST['dhr_market'] ) || ! may() ) {
		return;
	}
	check_admin_referer( 'dhr-market' );
	$mode = sanitize_key( wp_unslash( $_POST['dhr_market'] ) );
	$msg  = '';
	if ( 'fetch' === $mode ) {
		if ( function_exists( 'wp_schedule_single_event' ) ) {
			wp_schedule_single_event( time(), CRON );
			if ( function_exists( 'spawn_cron' ) ) {
				spawn_cron();
			}
		}
		$msg = '경쟁 가게 값을 받는 중입니다. 1~2분 뒤 새로고침하세요.';
	} elseif ( 'map' === $mode ) {
		$map = (array) get_option( OPT_MAP, array() );
		$id  = (int) ( $_POST['map_id'] ?? 0 );
		$to  = sanitize_text_field( wp_unslash( $_POST['map_to'] ?? '' ) );
		if ( $id > 0 ) {
			if ( '' === $to ) {
				unset( $map[ $id ] );
			} else {
				$map[ $id ] = $to;
			}
			update_option( OPT_MAP, $map, false );
		}
		$msg = '대조 상대를 적었습니다.';
	}
	set_transient( 'dhr_market_msg_' . get_current_user_id(), $msg, 120 );
	wp_safe_redirect( admin_url( 'tools.php?page=' . SLUG . '&mode=' . rawurlencode( sanitize_key( wp_unslash( $_POST['mode'] ?? 'min' ) ) ) ) );
	exit;
}
add_action( 'admin_init', __NAMESPACE__ . '\\handle' );

function screen(): void {
	if ( ! may() ) {
		wp_die( '권한이 없습니다.' );
	}
	$mode = sanitize_key( wp_unslash( $_GET['mode'] ?? 'min' ) );
	if ( ! in_array( $mode, array( 'min', 'vm', 'w24' ), true ) ) {
		$mode = 'min';
	}
	$msg = (string) get_transient( 'dhr_market_msg_' . get_current_user_id() );
	if ( '' !== $msg ) {
		delete_transient( 'dhr_market_msg_' . get_current_user_id() );
	}
	$data = (array) get_option( OPT_DATA, array() );
	$rows = compare( products(), (array) ( $data['rows'] ?? array() ), (array) get_option( OPT_MAP, array() ) );
	$srcs = sources();

	echo '<div class="wrap"><h1>시장가 대조</h1>';
	echo '<p>경쟁 가게 값을 우리 상품 옆에 놓습니다. <b>소매 손님이 사는 값(낱병 · 10개 세트)만</b> 시장가로 셉니다 — 50병 대량 단은 업자 값이라 참고로만 적습니다. '
		. '이 화면은 <b>보여 주기만</b> 합니다. 값은 「새 값」 칸의 숫자를 보고 상품 화면에서 직접 바꿉니다.</p>';
	if ( '' !== $msg ) {
		echo '<div class="notice notice-info"><p>' . esc_html( $msg ) . '</p></div>';
	}
	echo '<form method="post" style="margin:0 0 12px">';
	wp_nonce_field( 'dhr-market' );
	printf(
		'<input type="hidden" name="mode" value="%s"><button class="button" name="dhr_market" value="fetch">경쟁 값 지금 다시 읽기</button> <span style="color:#4E565F">마지막으로 받은 때: %s%s · %s</span>',
		esc_attr( $mode ),
		esc_html( (string) ( $data['at'] ?? '아직 없음 — 매일 05:10 에 받습니다' ) ),
		! empty( $data['partial'] ) ? ' (시간이 모자라 일부만)' : '',
		esc_html( implode( ' · ', array_map( fn( $sid, $s ) => $s['label'] . ' ' . count( (array) ( $data['rows'][ $sid ] ?? array() ) ) . '개', array_keys( $srcs ), $srcs ) ) )
	);
	echo '</form>';
	echo '<p>제안 값의 기준: ';
	foreach ( array( 'min' => '둘 중 싼 쪽', 'vm' => '브이몬스터', 'w24' => '겨울마을' ) as $k => $l ) {
		printf( '<a href="%s" style="margin-right:10px%s">%s</a>', esc_url( admin_url( 'tools.php?page=' . SLUG . '&mode=' . $k ) ), $k === $mode ? ';font-weight:700' : '', esc_html( $l ) );
	}
	echo '</p>';
	echo '<table class="widefat striped"><thead><tr><th>상품</th><th>우리</th><th>병당</th>';
	foreach ( $srcs as $s ) {
		echo '<th>' . esc_html( (string) $s['label'] ) . '</th>';
	}
	echo '<th>차이</th><th>새 값 (제안)</th></tr></thead><tbody>';
	$hi = 0;
	foreach ( $rows as $r ) {
		$sug = suggest( $r, $mode );
		$gap = $r['gap'];
		if ( null !== $gap && $gap > 2 ) {
			++$hi;
		}
		$tone = null === $gap ? '' : ( $gap > 2 ? 'color:#C2410C;font-weight:700' : ( $gap < -2 ? 'color:#15803D' : '' ) );
		echo '<tr>';
		printf( '<td>%s<br><small style="color:#4E565F">#%d · %d병 · <a href="%s">상품 화면</a></small></td>', esc_html( (string) $r['name'] ), (int) $r['id'], (int) $r['bottles'], esc_url( admin_url( 'post.php?post=' . (int) $r['id'] . '&action=edit' ) ) );
		printf( '<td>%s</td><td>%s</td>', esc_html( number_format( (int) $r['price'] ) ), esc_html( number_format( (int) $r['per'] ) ) );
		foreach ( $srcs as $sid => $s ) {
			$c = (array) ( $r['comp'][ $sid ] ?? array() );
			$t = array();
			if ( ! empty( $c['single'] ) ) {
				$t[] = number_format( (int) $c['single'] ) . ( ! empty( $c['reviews'] ) ? ' <small>(후기 ' . (int) $c['reviews'] . ')</small>' : '' ) . ( ! empty( $c['out'] ) ? ' <small>품절</small>' : '' );
			}
			if ( ! empty( $c['set'] ) ) {
				$t[] = '<small>세트 병당 ' . number_format( (int) $c['set'] ) . '</small>';
			}
			if ( ! empty( $c['bulk'] ) ) {
				$t[] = '<small style="color:#888">대량 ' . number_format( (int) $c['bulk'] ) . ' (업자 · 참고만)</small>';
			}
			echo '<td>' . ( $t ? wp_kses_post( implode( '<br>', $t ) ) : '—' ) . ( ! empty( $c['name'] ) ? '<br><small style="color:#888">' . esc_html( (string) $c['name'] ) . '</small>' : '' ) . '</td>';
		}
		printf( '<td style="%s">%s</td>', esc_attr( $tone ), null === $gap ? '—' : esc_html( ( $gap > 0 ? '+' : '' ) . $gap . '%' ) );
		printf( '<td>%s</td>', $sug > 0 ? esc_html( number_format( $sug ) ) : '—' );
		echo '</tr>';
	}
	echo '</tbody></table>';
	printf( '<p><b>시장보다 비싼 상품 %d개</b> (차이 2%% 초과). 값을 내리면 그 상품을 <b>이미 담아 둔 장바구니</b>는 옛 기준가로 남아 결제에서 「가격이 바뀌었습니다」로 막힙니다 — 손님은 비우고 다시 담으면 됩니다.</p>', $hi );

	echo '<h2 style="margin-top:22px">대조 상대 못 박기</h2><p>이름으로 잘못 맞춘 줄이 있으면 상품 번호와 경쟁 상품 이름(그 가게 화면에 적힌 그대로)을 적습니다. 한 가게만이면 <code>vm:이름</code> · <code>w24:이름</code>. 비우면 다시 이름으로 맞춥니다.</p>';
	echo '<form method="post">';
	wp_nonce_field( 'dhr-market' );
	printf( '<input type="hidden" name="mode" value="%s">상품 번호 <input type="number" name="map_id" style="width:90px"> 경쟁 상품 이름 <input type="text" name="map_to" style="width:360px"> <button class="button" name="dhr_market" value="map">적기</button></form>', esc_attr( $mode ) );
	$map = (array) get_option( OPT_MAP, array() );
	if ( $map ) {
		echo '<ul>';
		foreach ( $map as $id => $to ) {
			printf( '<li>#%d → %s</li>', (int) $id, esc_html( (string) $to ) );
		}
		echo '</ul>';
	}
	echo '</div>';
}
