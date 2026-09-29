<?php
/**
 * SEO 월간 보고서 — 「이렇게 했고 · 이런 결과가 있었고 · 앞으로 이렇게」 한 장. 읽기 전용.
 *
 * 사장님(2026-09-29): 「SEO 작업도 월말 보고서 만들고. 보고서는 1~3분만 훑어도 이해가 가게 —
 * 이렇게 했고, 이런 결과가 있었고, 앞으로는 이렇게 할 예정」.
 *
 * 숫자는 전부 플러그인 자신의 것이다 (서치콘솔 · 서치어드바이저는 여기서 못 읽는다):
 * - **검색 유입**: front.js 가 그날 처음 들어온 사람의 referrer 를 `src_google · src_naver · src_daum · src_other · src_direct` 로
 *   깔때기 표에 보낸다 (사람마다 하루 한 번). 이 달 vs 지난달
 * - **상품별 조회**: `p:<번호>` — 상품 상세를 본 사람 수. 많이 봤는데 글이 없는 상품이 다음 할 일이 된다
 * - **글 · 설명 덮음새**: 상품 글 있는 수 · 분류 설명 빈 수 · 160자 넘는 수 · 브랜드 페이지 수. 매달 1일 스냅샷을 남겨 지난달과 견준다
 * - **작업 일지**: 옵션 `duckhoo_worklog` — 사장님이 도구 → 검색 노출 화면에 적거나, 클로드 세션이
 *   `POST /wp-json/duckhoo/v1/log` (브리핑 열쇠) 로 남긴다. 「이렇게 했고」의 재료
 *
 * @package Duckhoo\Redesign
 */

namespace Duckhoo\Redesign\Seo\Report;

defined( 'ABSPATH' ) || exit;

const OPT_SNAP = 'duckhoo_seo_snap';
const OPT_LOG  = 'duckhoo_worklog';

/* ── 작업 일지 ──────────────────────────────────────────────────────── */

/**
 * 한 줄 적는다. 400자 · 300줄까지.
 */
function log_add( string $area, string $text, string $day = '' ): bool {
	$text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $text ) ) );
	if ( '' === $text ) {
		return false;
	}
	$area = in_array( $area, array( 'seo', 'shop', 'ops', 'etc' ), true ) ? $area : 'etc';
	$all  = (array) get_option( OPT_LOG, array() );
	$all[] = array( 'd' => '' !== $day ? $day : (string) current_time( 'Y-m-d' ), 'a' => $area, 't' => mb_substr( $text, 0, 400 ) );
	update_option( OPT_LOG, array_slice( $all, -300 ), false );
	return true;
}

/**
 * 한 달치 (구역으로 거를 수 있다).
 *
 * @return array<int,array{d:string,a:string,t:string}>
 */
function entries( string $ym, string $area = '' ): array {
	$out = array();
	foreach ( (array) get_option( OPT_LOG, array() ) as $e ) {
		if ( ! is_array( $e ) || 0 !== strpos( (string) ( $e['d'] ?? '' ), $ym ) ) {
			continue;
		}
		if ( '' !== $area && ( $e['a'] ?? '' ) !== $area ) {
			continue;
		}
		$out[] = array( 'd' => (string) $e['d'], 'a' => (string) ( $e['a'] ?? 'etc' ), 't' => (string) ( $e['t'] ?? '' ) );
	}
	usort( $out, fn( $x, $y ) => strcmp( $x['d'], $y['d'] ) );
	return $out;
}

function area_label( string $a ): string {
	return array( 'seo' => '검색 노출', 'shop' => '가게', 'ops' => '운영', 'etc' => '기타' )[ $a ] ?? '기타';
}

/* ── 숫자 읽기 ──────────────────────────────────────────────────────── */

/**
 * 기간의 유입 경로 합 (사람 수).
 *
 * @return array<string,int> src_google … src_direct
 */
function sources( string $from, string $to ): array {
	global $wpdb;
	$keys = function_exists( '\\Duckhoo\\Redesign\\Funnel\\sources' ) ? array_keys( \Duckhoo\Redesign\Funnel\sources() ) : array( 'src_google', 'src_naver', 'src_daum', 'src_other', 'src_direct' );
	$out  = array_fill_keys( $keys, 0 );
	if ( ! isset( $wpdb ) || ! function_exists( '\\Duckhoo\\Redesign\\Funnel\\table' ) ) {
		return $out;
	}
	$rows = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
		'SELECT stage, SUM(n) AS n FROM ' . \Duckhoo\Redesign\Funnel\table() . " WHERE day >= %s AND day <= %s AND stage LIKE 'src\\_%%' GROUP BY stage", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$from,
		$to
	), ARRAY_A );
	foreach ( $rows as $r ) {
		if ( isset( $out[ (string) $r['stage'] ] ) ) {
			$out[ (string) $r['stage'] ] = (int) $r['n'];
		}
	}
	return $out;
}

/**
 * 기간의 상품별 본 사람 수 — 많은 순.
 *
 * @return array<int,int> 상품 번호 => 사람 수
 */
function product_views( string $from, string $to, int $limit = 30 ): array {
	global $wpdb;
	$out = array();
	if ( ! isset( $wpdb ) || ! function_exists( '\\Duckhoo\\Redesign\\Funnel\\table' ) ) {
		return $out;
	}
	$rows = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
		'SELECT stage, SUM(n) AS n FROM ' . \Duckhoo\Redesign\Funnel\table() . " WHERE day >= %s AND day <= %s AND stage LIKE 'p:%%' GROUP BY stage ORDER BY n DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$from,
		$to,
		max( 1, $limit )
	), ARRAY_A );
	foreach ( $rows as $r ) {
		$pid = (int) substr( (string) $r['stage'], 2 );
		if ( $pid > 0 ) {
			$out[ $pid ] = (int) $r['n'];
		}
	}
	return $out;
}

/**
 * 지금 덮음새 — 상품 글 · 분류 설명 · 브랜드 페이지.
 *
 * @return array<string,int>
 */
function snapshot(): array {
	$s = array( 'products' => 0, 'with_text' => 0, 'cats' => 0, 'cats_empty' => 0, 'cats_long' => 0, 'brands' => 0 );
	if ( function_exists( 'wc_get_products' ) && function_exists( '\\Duckhoo\\Redesign\\Seo\\hand_text' ) ) {
		$ps = wc_get_products( array( 'limit' => -1, 'status' => 'publish', 'return' => 'objects' ) );
		foreach ( is_array( $ps ) ? $ps : array() as $p ) {
			++$s['products'];
			if ( '' !== \Duckhoo\Redesign\Seo\hand_text( $p ) ) {
				++$s['with_text'];
			}
		}
	}
	if ( function_exists( 'get_terms' ) ) {
		$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true ) );
		foreach ( is_array( $terms ) ? $terms : array() as $t ) {
			++$s['cats'];
			$d = trim( wp_strip_all_tags( (string) $t->description ) );
			if ( '' === $d ) {
				++$s['cats_empty'];
			} elseif ( mb_strlen( $d ) > 160 ) {
				++$s['cats_long'];
			}
		}
	}
	if ( function_exists( '\\Duckhoo\\Redesign\\Seo\\brand_slugs' ) ) {
		$s['brands'] = count( (array) \Duckhoo\Redesign\Seo\brand_slugs() );
	}
	return $s;
}

/**
 * 달의 스냅샷을 적는다 (같은 달은 덮어쓴다 — 마지막이 「그 달 끝」에 가장 가깝다).
 */
function snap_put( string $ym, array $s ): void {
	$all = (array) get_option( OPT_SNAP, array() );
	$all[ $ym ] = $s;
	krsort( $all );
	update_option( OPT_SNAP, array_slice( $all, 0, 24, true ), false );
}

function snap_get( string $ym ): ?array {
	$all = (array) get_option( OPT_SNAP, array() );
	return isset( $all[ $ym ] ) && is_array( $all[ $ym ] ) ? $all[ $ym ] : null;
}

/**
 * 상품 이름 · 글 유무 (표시용).
 */
function product_info( int $pid ): array {
	$name = function_exists( 'get_the_title' ) ? (string) get_the_title( $pid ) : '#' . $pid;
	$has  = false;
	$out  = false;
	if ( function_exists( 'wc_get_product' ) ) {
		$p = wc_get_product( $pid );
		if ( $p ) {
			$has = function_exists( '\\Duckhoo\\Redesign\\Seo\\hand_text' ) && '' !== \Duckhoo\Redesign\Seo\hand_text( $p );
			$out = method_exists( $p, 'is_in_stock' ) && ! $p->is_in_stock();
		}
	}
	return array( 'name' => preg_replace( '/^\s*\[[^\]]+\]\s*/u', '', $name ), 'text' => $has, 'out' => $out );
}

/* ── 순수 계산 ──────────────────────────────────────────────────────── */

/**
 * 보고서 재료를 한 덩어리로.
 *
 * @param string     $ym       달.
 * @param array      $src      이 달 유입.
 * @param array      $src_prev 지난달 유입.
 * @param array      $views    상품 번호 => 본 사람 (많은 순).
 * @param array      $info     상품 번호 => [name, text, out].
 * @param array|null $snap     이 달 덮음새.
 * @param array|null $snap_prev 지난달 덮음새.
 * @param array      $log      이 달 작업 일지 (seo).
 * @param array      $close    월말 결산 요약 (signups · first_buyers · orders) — 없으면 빈 배열.
 * @return array<string,mixed>
 */
function build( string $ym, array $src, array $src_prev, array $views, array $info, ?array $snap, ?array $snap_prev, array $log, array $close = array() ): array {
	$search      = ( $src['src_google'] ?? 0 ) + ( $src['src_naver'] ?? 0 ) + ( $src['src_daum'] ?? 0 );
	$search_prev = ( $src_prev['src_google'] ?? 0 ) + ( $src_prev['src_naver'] ?? 0 ) + ( $src_prev['src_daum'] ?? 0 );
	$all         = array_sum( $src );
	$all_prev    = array_sum( $src_prev );

	$did = array();
	foreach ( $log as $e ) {
		$did[] = substr( $e['d'], 5 ) . ' ' . $e['t'];
	}
	if ( $snap && $snap_prev ) {
		foreach ( array( 'with_text' => '글 있는 상품', 'brands' => '브랜드 페이지' ) as $k => $l ) {
			if ( (int) $snap[ $k ] !== (int) $snap_prev[ $k ] ) {
				$did[] = "{$l} {$snap_prev[$k]} → {$snap[$k]}";
			}
		}
		foreach ( array( 'cats_empty' => '설명 없는 분류', 'cats_long' => '160자 넘는 분류 설명' ) as $k => $l ) {
			if ( (int) $snap[ $k ] < (int) $snap_prev[ $k ] ) {
				$did[] = "{$l} {$snap_prev[$k]} → {$snap[$k]}";
			}
		}
	}

	$top = array();
	$no_text = array();
	$out_hot = array();
	$i = 0;
	foreach ( $views as $pid => $n ) {
		$pi = $info[ $pid ] ?? array( 'name' => '#' . $pid, 'text' => false, 'out' => false );
		if ( $i < 5 ) {
			$top[] = array( 'name' => $pi['name'], 'n' => $n );
		}
		if ( ! $pi['text'] && count( $no_text ) < 5 ) {
			$no_text[] = array( 'pid' => $pid, 'name' => $pi['name'], 'n' => $n );
		}
		if ( $pi['out'] && count( $out_hot ) < 3 ) {
			$out_hot[] = array( 'pid' => $pid, 'name' => $pi['name'], 'n' => $n );
		}
		++$i;
	}

	$next = array();
	if ( $no_text ) {
		$next[] = '많이 봤는데 글이 없는 상품에 한 줄 설명 붙이기: ' . implode( ' · ', array_map( fn( $x ) => $x['name'] . '(' . $x['n'] . '명)', $no_text ) );
	}
	if ( $out_hot ) {
		$next[] = '품절인데 계속 찾는 상품: ' . implode( ' · ', array_map( fn( $x ) => $x['name'] . '(' . $x['n'] . '명)', $out_hot ) ) . ' — 입고 계획이 없으면 대신 살 상품을 상세에 적기';
	}
	if ( $snap && (int) $snap['cats_empty'] > 0 ) {
		$next[] = '설명 없는 분류 ' . $snap['cats_empty'] . '개 채우기 (분류 편집 → 설명)';
	}
	if ( $snap && (int) $snap['cats_long'] > 0 ) {
		$next[] = '160자 넘는 분류 설명 ' . $snap['cats_long'] . '개는 검색 결과에서 잘려 나간다 — 앞 두 문장에 핵심을';
	}
	if ( $snap && (int) $snap['products'] > 0 && (int) $snap['with_text'] < (int) $snap['products'] ) {
		$next[] = '상품 글 ' . $snap['with_text'] . ' / ' . $snap['products'] . '종 — 나머지는 이름 · 값으로 엮은 한 줄이 나간다';
	}
	if ( $all > 0 && $search === 0 ) {
		$next[] = '검색 유입이 0 이다 — 서치어드바이저 · 서치콘솔에서 색인 상태를 먼저 본다';
	}

	return array(
		'ym'          => $ym,
		'search'      => $search,
		'search_prev' => $search_prev,
		'all'         => $all,
		'all_prev'    => $all_prev,
		'src'         => $src,
		'src_prev'    => $src_prev,
		'did'         => $did,
		'top'         => $top,
		'no_text'     => $no_text,
		'out_hot'     => $out_hot,
		'next'        => $next,
		'snap'        => $snap,
		'close'       => $close,
	);
}

function pct_delta( int $now, int $then ): string {
	if ( $then <= 0 ) {
		return '';
	}
	$p = ( $now - $then ) / $then * 100;
	return sprintf( '%s%.0f%%', $p >= 0 ? '+' : '−', abs( $p ) );
}

/**
 * 글 — 디스코드 · 붙여 넣기 공용. 1~3분에 읽히게: 이렇게 했고 · 결과 · 앞으로.
 */
function text( array $r, bool $discord = true ): string {
	$b  = fn( $s ) => $discord ? "**{$s}**" : $s;
	$km = function_exists( '\\Duckhoo\\Redesign\\Monthly\\kmonth' ) ? \Duckhoo\Redesign\Monthly\kmonth( $r['ym'] ) : $r['ym'];
	$L  = array();
	$L[] = $b( "검색 노출 월간 보고 — {$km}" );
	$L[] = $b( '이렇게 했고' );
	if ( $r['did'] ) {
		foreach ( array_slice( $r['did'], 0, 8 ) as $d ) {
			$L[] = '· ' . $d;
		}
	} else {
		$L[] = '· 이 달에 적힌 작업이 없습니다 (도구 → 검색 노출 화면의 작업 일지에 적으면 여기에 옵니다)';
	}
	$L[] = $b( '이런 결과' );
	$dl  = pct_delta( (int) $r['search'], (int) $r['search_prev'] );
	$L[] = '· 검색에서 들어온 사람 ' . number_format( (int) $r['search'] ) . '명 (구글 ' . (int) ( $r['src']['src_google'] ?? 0 ) . ' · 네이버 ' . (int) ( $r['src']['src_naver'] ?? 0 ) . ' · 다음 ' . (int) ( $r['src']['src_daum'] ?? 0 ) . ')' . ( '' !== $dl ? " — 지난달 대비 {$dl}" : ' — 지난달 숫자 없음' );
	$L[] = '· 들어온 사람 전체 ' . number_format( (int) $r['all'] ) . '명 (직접 ' . (int) ( $r['src']['src_direct'] ?? 0 ) . ' · 다른 곳 ' . (int) ( $r['src']['src_other'] ?? 0 ) . ')';
	if ( ! empty( $r['close'] ) ) {
		$c = $r['close'];
		$L[] = '· 새 가입 ' . (int) ( $c['signups'] ?? 0 ) . '명 · 이 달 처음 산 회원 ' . (int) ( $c['first_buyers'] ?? 0 ) . '명';
	}
	if ( $r['top'] ) {
		$L[] = '· 많이 본 상품: ' . implode( ' · ', array_map( fn( $x ) => $x['name'] . '(' . $x['n'] . '명)', $r['top'] ) );
	}
	$L[] = $b( '앞으로' );
	if ( $r['next'] ) {
		foreach ( array_slice( $r['next'], 0, 4 ) as $n ) {
			$L[] = '· ' . $n;
		}
	} else {
		$L[] = '· 지금 손댈 것이 없습니다 — 다음 달 숫자를 봅니다';
	}
	return implode( "\n", $L );
}

/* ── 한 달치 모아 만들기 ─────────────────────────────────────────────── */

/**
 * 달 보고서 (재료 + 글). 10분 캐시.
 */
function report( string $ym, array $close = array(), bool $fresh = false ): array {
	$key = 'dhr_seo_rep_' . $ym . '_' . (string) current_time( 'Y-m-d' );
	if ( ! $fresh ) {
		$hit = get_transient( $key );
		if ( is_array( $hit ) && isset( $hit['text'] ) ) {
			return $hit;
		}
	}
	$bounds = function_exists( '\\Duckhoo\\Redesign\\Monthly\\bounds' ) ? \Duckhoo\Redesign\Monthly\bounds( $ym ) : array( $ym . '-01', gmdate( 'Y-m-t', strtotime( $ym . '-01' ) ) );
	$pym    = function_exists( '\\Duckhoo\\Redesign\\Monthly\\prev_ym' ) ? \Duckhoo\Redesign\Monthly\prev_ym( $ym ) : gmdate( 'Y-m', strtotime( $ym . '-01 -1 day' ) );
	$pb     = function_exists( '\\Duckhoo\\Redesign\\Monthly\\bounds' ) ? \Duckhoo\Redesign\Monthly\bounds( $pym ) : array( $pym . '-01', gmdate( 'Y-m-t', strtotime( $pym . '-01' ) ) );
	$src    = sources( $bounds[0], $bounds[1] );
	$srcp   = sources( $pb[0], $pb[1] );
	$views  = product_views( $bounds[0], $bounds[1], 30 );
	$info   = array();
	foreach ( array_keys( $views ) as $pid ) {
		$info[ $pid ] = product_info( (int) $pid );
	}
	$today = (string) current_time( 'Y-m' );
	$snap  = snap_get( $ym );
	if ( $ym >= $today || ! $snap ) {
		$snap = snapshot();
		snap_put( $ym, $snap );
	}
	$r = build( $ym, $src, $srcp, $views, $info, $snap, snap_get( $pym ), entries( $ym, 'seo' ), $close );
	$out = array( 'r' => $r, 'text' => text( $r, true ), 'plain' => text( $r, false ) );
	set_transient( $key, $out, 10 * MINUTE_IN_SECONDS );
	return $out;
}

/* ── REST — 클로드 세션이 작업 일지를 남기는 길 (브리핑 열쇠) ───────────────── */

function routes(): void {
	register_rest_route( 'duckhoo/v1', '/log', array(
		'methods'             => 'POST',
		'permission_callback' => fn( $req ) => function_exists( '\\Duckhoo\\Redesign\\Brief\\authorized' ) && \Duckhoo\Redesign\Brief\authorized( $req ),
		'callback'            => function ( $req ) {
			$area = is_object( $req ) && method_exists( $req, 'get_param' ) ? (string) $req->get_param( 'area' ) : 'etc';
			$text = is_object( $req ) && method_exists( $req, 'get_param' ) ? (string) $req->get_param( 'text' ) : '';
			$day  = is_object( $req ) && method_exists( $req, 'get_param' ) ? (string) $req->get_param( 'day' ) : '';
			$day  = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) ? $day : '';
			$ok   = log_add( $area, $text, $day );
			return $ok ? rest_ensure_response( array( 'ok' => true ) ) : new \WP_Error( 'dhr_log_empty', 'text 가 비어 있습니다', array( 'status' => 400 ) );
		},
	) );
}
add_action( 'rest_api_init', __NAMESPACE__ . '\\routes' );

/* ── 관리자 화면 조각 (도구 → 검색 노출 이 부른다) ───────────────────────── */

function handle_post(): string {
	if ( ! isset( $_POST['dhr_wl_nonce'] ) || ! wp_verify_nonce( (string) $_POST['dhr_wl_nonce'], 'dhr_wl' ) || ! current_user_can( 'manage_options' ) ) { // phpcs:ignore
		return '';
	}
	$t = (string) wp_unslash( (string) ( $_POST['dhr_wl_text'] ?? '' ) ); // phpcs:ignore
	$a = sanitize_text_field( wp_unslash( (string) ( $_POST['dhr_wl_area'] ?? 'seo' ) ) ); // phpcs:ignore
	return log_add( $a, $t ) ? '작업 일지에 적었습니다.' : '비어 있어 적지 않았습니다.';
}

function box( string $msg = '' ): void {
	$ym = function_exists( '\\Duckhoo\\Redesign\\Monthly\\default_ym' ) ? \Duckhoo\Redesign\Monthly\default_ym( (string) current_time( 'Y-m-d' ) ) : (string) current_time( 'Y-m' );
	if ( isset( $_GET['dhr_m'] ) && preg_match( '/^\d{4}-\d{2}$/', (string) $_GET['dhr_m'] ) ) { // phpcs:ignore
		$ym = (string) $_GET['dhr_m']; // phpcs:ignore
	}
	echo '<h2 style="margin-top:26px">월간 보고서 — ' . esc_html( function_exists( '\\Duckhoo\\Redesign\\Monthly\\kmonth' ) ? \Duckhoo\Redesign\Monthly\kmonth( $ym ) : $ym ) . '</h2>';
	if ( '' !== $msg ) {
		echo '<div class="notice notice-info inline"><p>' . esc_html( $msg ) . '</p></div>';
	}
	try {
		$rep = report( $ym );
	} catch ( \Throwable $e ) {
		echo '<p style="color:#b45309">읽다 멈췄습니다: ' . esc_html( $e->getMessage() ) . '</p>';
		return;
	}
	echo '<p class="description" style="max-width:720px">검색 유입 · 상품별 조회는 이 사이트가 직접 센 것입니다 (2026-09-29 배포부터). 서치콘솔 · 서치어드바이저의 순위 · 노출은 여기 없습니다 — 그쪽 화면을 보시고 아래 일지에 한 줄 적어 두면 보고서에 같이 실립니다. 매달 1일 월말 결산과 함께 디스코드로 갑니다.</p>';
	echo '<textarea readonly style="width:100%;max-width:720px;min-height:220px;font-family:inherit;font-size:13px" onclick="this.select()">' . esc_textarea( $rep['plain'] ) . '</textarea>';
	echo '<h3>작업 일지</h3><form method="post" style="max-width:720px">';
	wp_nonce_field( 'dhr_wl', 'dhr_wl_nonce' );
	echo '<p><select name="dhr_wl_area"><option value="seo">검색 노출</option><option value="shop">가게</option><option value="ops">운영</option><option value="etc">기타</option></select> <input type="text" name="dhr_wl_text" class="regular-text" style="width:60%" placeholder="예) 노보 15종 제목 · 메타 설명 새로 씀"> <button class="button">적기</button></p>';
	echo '</form>';
	$es = entries( $ym );
	if ( $es ) {
		echo '<ul style="max-width:720px">';
		foreach ( array_reverse( $es ) as $e ) {
			echo '<li>' . esc_html( $e['d'] ) . ' <span style="color:#666">[' . esc_html( area_label( $e['a'] ) ) . ']</span> ' . esc_html( $e['t'] ) . '</li>';
		}
		echo '</ul>';
	} else {
		echo '<p class="description">이 달에 적힌 것이 없습니다.</p>';
	}
	$v = product_views( ( function_exists( '\\Duckhoo\\Redesign\\Monthly\\bounds' ) ? \Duckhoo\Redesign\Monthly\bounds( $ym )[0] : $ym . '-01' ), ( function_exists( '\\Duckhoo\\Redesign\\Monthly\\bounds' ) ? \Duckhoo\Redesign\Monthly\bounds( $ym )[1] : $ym . '-31' ), 20 );
	if ( $v ) {
		echo '<h3>많이 본 상품 (사람 수)</h3><table class="widefat striped" style="max-width:720px"><thead><tr><th>상품</th><th>본 사람</th><th>글</th><th>재고</th></tr></thead><tbody>';
		foreach ( $v as $pid => $n ) {
			$pi = product_info( (int) $pid );
			echo '<tr><td><a href="' . esc_url( get_edit_post_link( (int) $pid ) ?: '#' ) . '">' . esc_html( $pi['name'] ) . '</a></td><td>' . (int) $n . '</td><td>' . ( $pi['text'] ? '있음' : '<b style="color:#b45309">없음</b>' ) . '</td><td>' . ( $pi['out'] ? '<b style="color:#b45309">품절</b>' : '있음' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
}
