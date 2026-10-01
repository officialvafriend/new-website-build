<?php
/**
 * 넓은 말 검색 — 「전담 액상」 「전자담배 액상」 「입호흡 액상」 「폐호흡 액상」 (2026-10-01).
 *
 * 사장님 목표: 「노보 액상」 다음은 「전담 액상」 같은 넓은 말에서 1페이지 · 상단.
 * 10/1 실측 — 네이버 웹문서 상위 15개는 전부 「액상」이 도메인에 든 카페24 몰이고
 * 제목 · 설명에 검색어를 **글자 그대로** 적는다. 우리 도메인은 못 바꾸므로(쌓인 색인 ·
 * 이름 검색을 버리는 일) 그 자리를 제목 · 설명 · 분류 FAQ · 안내 글로 메운다.
 *
 * 여기서 하는 것 — 전부 글자다. 상품 · 폼 · 주문에는 손대지 않는다.
 *  - 홈 제목 · 설명 · 숨은 h1 에 「전자담배 액상 · 전담 액상 사이트」
 *  - 입호흡 · 폐호흡 분류의 제목 · 설명 (값 · 종수 · 브랜드는 상품에서 그때그때 읽는다)
 *  - 전체 상품 · 입호흡 · 폐호흡 화면 격자 **아래** FAQ + FAQPage 구조화 데이터
 *    (상품 위에 글을 깔지 않는다 — 사장님 2026-09-04)
 *  - 안내 글 두 장(/liquid-guide/ · /mtl-vs-dl/)은 pages.php 가 만든다. 여기서는 링크만
 *
 * 쓰지 않는 말: 건강 · 금연 · 순하다 · 해롭지 않다 (담배사업법). 다른 가게 얘기도 없다.
 *
 * @package Duckhoo\Redesign
 */

namespace Duckhoo\Redesign\Seo\Broad;

use function Duckhoo\Redesign\Front\products;
use function Duckhoo\Redesign\Front\split_name;
use function Duckhoo\Redesign\Front\brand_aliases;
use function Duckhoo\Redesign\Front\cat_by_name;
use function Duckhoo\Redesign\Front\ship_rule_short;
use function Duckhoo\Redesign\Seo\signup_points;
use function Duckhoo\Redesign\Seo\free_ship;
use function Duckhoo\Redesign\Seo\cat_prices;

defined( 'ABSPATH' ) || exit;

/**
 * 분류가 입호흡인가 폐호흡인가 — **이름**으로 본다. 한글 슬러그는 DB 에 퍼센트 인코딩으로
 * 들어가 슬러그 비교가 깨진다.
 *
 * @param mixed $t 분류.
 * @return string 'mtl' | 'dl' | ''
 */
function kind( $t ): string {
	$name = is_object( $t ) ? (string) ( $t->name ?? '' ) : '';
	if ( '' === $name ) {
		return '';
	}
	if ( false !== mb_strpos( $name, '무니코틴' ) ) {
		return '';   // 무니코틴은 따로 — 사장님 방침상 밀지 않는다
	}
	if ( false !== mb_strpos( $name, '입호흡' ) ) {
		return 'mtl';
	}
	if ( false !== mb_strpos( $name, '폐호흡' ) ) {
		return 'dl';
	}
	return '';
}

/**
 * @param string $k 'mtl' | 'dl'.
 * @return array{ko:string,en:string}
 */
function label( string $k ): array {
	return 'dl' === $k ? array( 'ko' => '폐호흡', 'en' => 'DL' ) : array( 'ko' => '입호흡', 'en' => 'MTL' );
}

function site(): string {
	$n = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '';
	return '' !== $n ? $n : '액상덕후';
}

function won( float $v ): string {
	return number_format( $v ) . '원';
}

/**
 * 공개 상품 수.
 */
function count_all(): int {
	if ( function_exists( 'wp_count_posts' ) ) {
		$c = wp_count_posts( 'product' );
		$n = is_object( $c ) ? (int) ( $c->publish ?? 0 ) : 0;
		if ( $n > 0 ) {
			return $n;
		}
	}
	return count( products( array( 'limit' => -1 ) ) );
}

/**
 * 재고 있는 노보 상품이 있나 — 홈 제목의 「노보 재고 있음」은 그때만.
 */
function novo_in_stock(): bool {
	static $memo = null;   // 한 요청에서 제목 · 설명 · og 가 세 번 묻는다
	if ( null !== $memo && empty( $GLOBALS['dhr_test'] ) ) {
		return $memo;
	}
	$memo = false;
	foreach ( products( array( 'limit' => -1 ) ) as $p ) {
		if ( $p->is_in_stock() && preg_match( '/^\s*\[노보/u', (string) $p->get_name() ) ) {
			$memo = true;
			break;
		}
	}
	return $memo;
}

/**
 * 분류(또는 전체) 안의 브랜드 — 상품 수 많은 순, 재고 있는 것만.
 *
 * @param mixed $t 분류 (null 이면 전체).
 * @param int   $n 몇 개.
 * @return string[]
 */
function top_brands( $t = null, int $n = 4 ): array {
	$args = array( 'limit' => -1 );
	if ( is_object( $t ) && ! empty( $t->slug ) ) {
		$args['category'] = array( (string) $t->slug );
	}
	$out = array();
	foreach ( products( $args ) as $p ) {
		if ( ! $p->is_in_stock() ) {
			continue;
		}
		$b = (string) ( split_name( $p )['brand'] ?? '' );
		if ( '' === $b || preg_match( '/이벤트|할인|특가|초특가|한정/u', $b ) ) {
			continue;
		}
		$b = (string) ( brand_aliases()[ $b ] ?? $b );
		if ( $b === site() ) {
			continue;   // 가게 이름이 브랜드로 서면 「액상덕후 · 노보 …」 — 설명에서 이상하다
		}
		$out[ $b ] = ( $out[ $b ] ?? 0 ) + 1;
	}
	arsort( $out );
	return array_slice( array_keys( $out ), 0, max( 1, $n ) );
}

/**
 * 전체 상품의 값 범위 — 낱병 최저가 · 묶음 최저 병당. 상품에서 읽는다.
 *
 * @return array{single:float,per:float}
 */
function all_prices(): array {
	$single = 0.0;
	$per    = 0.0;
	foreach ( products( array( 'limit' => -1 ) ) as $p ) {
		if ( ! $p->is_in_stock() || (float) $p->get_price() <= 0 ) {
			continue;
		}
		$name = (string) $p->get_name();
		if ( preg_match( '/기기|팟|코일|드립팁|첨가제|결제|증정|무니코틴/u', $name ) ) {
			continue;   // 액상 값만
		}
		$price = (float) $p->get_price();
		$pb    = function_exists( '\\Duckhoo\\Redesign\\Front\\per_bottle' ) ? \Duckhoo\Redesign\Front\per_bottle( $p ) : array();
		$qty   = (int) ( $pb['qty'] ?? 1 );
		if ( $qty > 1 ) {
			$each = $price / $qty;
			if ( 0.0 === $per || $each < $per ) {
				$per = $each;
			}
		} elseif ( 0.0 === $single || $price < $single ) {
			$single = $price;
		}
	}
	return array( 'single' => $single, 'per' => $per );
}

/* ───────────────────────────── 홈 ───────────────────────────── */

/**
 * 홈 `<title>` — 「전자담배 액상 · 전담 액상 사이트 액상덕후 | 입호흡 · 폐호흡 184종 · 노보 재고 있음」.
 * 네이버 웹문서는 제목의 글자 일치를 크게 본다. 손님이 치는 말 둘(전자담배 액상 · 전담 액상)을
 * 앞에 두고 가게 이름은 그 뒤.
 */
function home_title(): string {
	$n = count_all();
	$t = '전자담배 액상 · 전담 액상 사이트 ' . site() . ' | 입호흡 · 폐호흡' . ( $n > 0 ? ' ' . $n . '종' : '' )
		. ( novo_in_stock() ? ' · 노보 재고 있음' : '' );
	return (string) apply_filters( 'duckhoo_home_title', $t );
}

/**
 * 홈 · 전체 상품 설명에 쓰는 브랜드 — 주력 브랜드(노보 · 디오리퀴드 · 화이트아웃 · 펠릭스). 상품 수로 세면
 * 무니코틴 브랜드와 가게 이름(액상덕후)이 앞에 선다 — 사장님 방침(무니코틴은 밀지 않는다)과 어긋난다.
 *
 * @return string[]
 */
function lead_brands(): array {
	if ( function_exists( '\\Duckhoo\\Redesign\\Seo\\Pages\\brand_names' ) ) {
		return (array) \Duckhoo\Redesign\Seo\Pages\brand_names();
	}
	return top_brands( null, 4 );
}

function home_desc(): string {
	$n  = count_all();
	$b  = lead_brands();
	$pr = all_prices();
	$t  = '전자담배 액상(전담 액상) 전문 사이트 ' . site() . '. '
		. ( $b ? implode( ' · ', $b ) . ' 등 ' : '' ) . '입호흡 · 폐호흡 액상' . ( $n > 0 ? ' ' . $n . '종' : '' )
		. ( $pr['single'] > 0 ? ', 낱병 ' . won( $pr['single'] ) . '부터' : '' )
		. ( novo_in_stock() ? ', 노보 전 라인 재고 있음' : '' ) . '. '
		. '평일 오후 4시 이전 입금 확인 시 당일 출고, ' . won( (float) free_ship() ) . ' 이상 무료배송, 가입 즉시 '
		. won( (float) signup_points() ) . ' 적립. 19세 이상 본인확인 회원 전용.';
	return (string) apply_filters( 'duckhoo_home_desc', $t );
}

function home_h1(): string {
	return site() . ' — 전자담배 액상 · 전담 액상 사이트';
}
add_filter( 'duckhoo_home_h1', __NAMESPACE__ . '\\home_h1' );

/**
 * 지금 화면이 홈인가. 브랜드 페이지는 `is_home` 을 꺼 두므로 여기 안 걸린다.
 */
function on_home(): bool {
	if ( ! function_exists( 'is_front_page' ) || ! is_front_page() ) {
		return false;
	}
	if ( function_exists( 'is_search' ) && is_search() ) {
		return false;
	}
	if ( function_exists( '\\Duckhoo\\Redesign\\Seo\\is_brand_page' ) && \Duckhoo\Redesign\Seo\is_brand_page() ) {
		return false;
	}
	return true;
}

function title( $t ): string {
	return on_home() ? home_title() : (string) $t;
}
add_filter( 'aioseo_title', __NAMESPACE__ . '\\title', 30 );
add_filter( 'pre_get_document_title', __NAMESPACE__ . '\\title', 30 );

function description( $d ): string {
	if ( ! on_home() ) {
		return (string) $d;
	}
	$t = home_desc();
	return function_exists( '\\Duckhoo\\Redesign\\Seo\\cap' ) ? \Duckhoo\Redesign\Seo\cap( $t ) : $t;
}
add_filter( 'aioseo_description', __NAMESPACE__ . '\\description', 30 );
add_filter( 'aioseo_og_description', __NAMESPACE__ . '\\description', 30 );
add_filter( 'aioseo_twitter_description', __NAMESPACE__ . '\\description', 30 );

/**
 * og:title · twitter:title 도 같이 — 있는 칸만 바꾼다 (seo.php 의 social_title 과 같은 방식).
 */
function social( $tags ) {
	if ( ! is_array( $tags ) || ! on_home() ) {
		return $tags;
	}
	foreach ( array( 'og:title', 'twitter:title' ) as $k ) {
		if ( isset( $tags[ $k ] ) ) {
			$tags[ $k ] = home_title();
		}
	}
	foreach ( array( 'og:description', 'twitter:description' ) as $k ) {
		if ( isset( $tags[ $k ] ) ) {
			$tags[ $k ] = home_desc();
		}
	}
	return $tags;
}
add_filter( 'aioseo_facebook_tags', __NAMESPACE__ . '\\social', 30 );
add_filter( 'aioseo_twitter_tags', __NAMESPACE__ . '\\social', 30 );

/* ───────────────────────────── 입호흡 · 폐호흡 분류 ───────────────────────────── */

/**
 * 「입호흡 액상 94종 가격 8,000원~ | 전자담배 입호흡(MTL) 액상 사이트 액상덕후」.
 * 종수 · 최저가는 재고 있는 상품에서 센다 (`Seo\cat_prices`).
 *
 * @param object $t 분류.
 */
function cat_title( $t ): string {
	$k = kind( $t );
	$l = label( $k );
	$c = cat_prices( $t );
	return $l['ko'] . ' 액상' . ( $c['n'] ? ' ' . $c['n'] . '종' : '' )
		. ( $c['single'] > 0 ? ' 가격 ' . number_format( $c['single'] ) . '원~' : '' )
		. ' | 전자담배 ' . $l['ko'] . '(' . $l['en'] . ') 액상 사이트 ' . site();
}

/**
 * 분류 메타 설명. 값 · 브랜드는 상품에서. 160자 안쪽은 `Seo\cap` 이 자른다.
 *
 * @param object $t 분류.
 */
function cat_text( $t ): string {
	$k = kind( $t );
	$l = label( $k );
	$c = cat_prices( $t );
	$b = top_brands( $t, 4 );
	$price = array();
	if ( $c['single'] > 0 ) {
		$price[] = '낱병 ' . won( $c['single'] ) . '부터';
	}
	if ( $c['bundle'] > 0 && $c['bundle_n'] > 0 ) {
		$price[] = '묶음 병당 약 ' . won( floor( $c['bundle'] / $c['bundle_n'] / 100 ) * 100 );
	}
	$how = 'dl' === $k
		? '고출력 기기에 맞춘 폐호흡(DL) 전자담배 액상'
		: '팟 · 소형 기기에 맞춘 입호흡(MTL) 전자담배 액상';
	$t = $how . ( $c['n'] ? ' ' . $c['n'] . '종' : '' ) . ( $b ? ' — ' . implode( ' · ', $b ) . ' 등' : '' ) . '.'
		. ( $price ? ' ' . implode( ', ', $price ) . '.' : '' )
		. ' 니코틴 농도 · 용량은 상품 이름에 표시. 평일 오후 4시 이전 입금 확인 시 당일 출고, '
		. won( (float) free_ship() ) . ' 이상 무료배송, 가입 즉시 ' . won( (float) signup_points() ) . ' 적립. 19세 이상 본인확인 회원 전용.';
	return (string) apply_filters( 'duckhoo_broad_cat_text', $t, $t );
}

/* ───────────────────────────── FAQ ───────────────────────────── */

/**
 * 지금 화면의 FAQ 문맥 — 'shop' | 'mtl' | 'dl' | ''.
 */
function ctx(): string {
	if ( function_exists( 'is_search' ) && is_search() ) {
		return '';
	}
	if ( function_exists( 'is_shop' ) && is_shop() ) {
		return 'shop';
	}
	if ( function_exists( '\\Duckhoo\\Redesign\\Seo\\noted_cat' ) ) {
		$t = \Duckhoo\Redesign\Seo\noted_cat();
		if ( $t ) {
			return kind( $t );
		}
	}
	return '';
}

function guide_url( string $slug ): string {
	return home_url( '/' . $slug . '/' );
}

function cat_url( string $needle, string $fallback ): string {
	$t = function_exists( '\\Duckhoo\\Redesign\\Front\\cat_by_name' ) ? cat_by_name( $needle ) : null;
	if ( $t && function_exists( 'get_term_link' ) ) {
		$u = get_term_link( $t );
		if ( is_string( $u ) ) {
			return $u;
		}
	}
	return home_url( $fallback );
}

/**
 * FAQ — 질문은 손님이 검색창에 치는 말, 답은 우리 가게의 사실만 (값 · 종수 · 브랜드는 상품에서).
 *
 * @param string $ctx 'shop' | 'mtl' | 'dl'.
 * @param mixed  $t   분류 (mtl · dl 일 때).
 * @return array<int,array{q:string,a:string}>
 */
function faq( string $ctx, $t = null ): array {
	$site  = site();
	$ship  = won( (float) free_ship() );
	$pts   = won( (float) signup_points() );
	$rule  = function_exists( '\\Duckhoo\\Redesign\\Front\\ship_rule_short' ) ? ship_rule_short() : '금요일 16시 이후 · 주말 주문은 월요일 16시 출고';
	$items = array();

	if ( 'shop' === $ctx ) {
		$n  = count_all();
		$b  = lead_brands();
		$pr = all_prices();
		$items[] = array(
			'q' => '전담 액상(전자담배 액상)은 어디서 살 수 있나요?',
			'a' => $site . '에서 입호흡 · 폐호흡 액상' . ( $n ? ' ' . $n . '종' : '' ) . '을 온라인으로 바로 주문하실 수 있습니다. '
				. '19세 이상 휴대폰 본인확인 회원만 구매할 수 있고, 가입 즉시 ' . $pts . '이 적립됩니다.',
		);
		$items[] = array(
			'q' => '전자담배 액상 가격은 얼마인가요?',
			'a' => ( $pr['single'] > 0 ? '낱병은 ' . won( $pr['single'] ) . '부터' : '낱병과 묶음으로 판매합니다' )
				. ( $pr['per'] > 0 ? ', 10병 묶음은 병당 약 ' . won( floor( $pr['per'] / 100 ) * 100 ) . '까지 내려갑니다' : '' )
				. '. 전 상품의 판매가 · 병당 가격은 가격표 페이지에서 한 번에 볼 수 있습니다.',
		);
		$items[] = array(
			'q' => '입호흡 액상과 폐호흡 액상은 어떻게 다른가요?',
			'a' => '입호흡(MTL) 액상은 팟 · 소형 기기처럼 저출력으로 피우는 기기용이고 니코틴 농도가 높은 편(9.8mg 안팎)입니다. '
				. '폐호흡(DL) 액상은 고출력 기기용으로 농도가 낮은 편(3mg 안팎)이고 용량이 큰 제품이 많습니다. 쓰시는 기기에 맞는 쪽을 고르면 됩니다.',
		);
		$items[] = array(
			'q' => '어떤 브랜드 액상이 있나요?',
			'a' => ( $b ? implode( ' · ', $b ) . ' 등 ' : '' ) . '브랜드별 낱병과 묶음을 판매합니다.' . ( novo_in_stock() ? ' 노보는 전 라인 재고가 있습니다.' : '' ),
		);
		$items[] = array(
			'q' => '주문하면 언제 받을 수 있나요?',
			'a' => '평일 오후 4시 이전에 입금이 확인된 주문은 당일 출고하며 보통 다음 날 받으십니다. ' . $rule . '. ' . $ship . ' 이상은 무료배송입니다.',
		);
		$items[] = array(
			'q' => '결제는 어떻게 하나요?',
			'a' => '무통장입금으로만 받습니다. 입금자명을 주문자명과 똑같이 넣어 주시면 입금이 자동으로 확인됩니다.',
		);
	} elseif ( in_array( $ctx, array( 'mtl', 'dl' ), true ) && is_object( $t ) ) {
		$l  = label( $ctx );
		$c  = cat_prices( $t );
		$b  = top_brands( $t, 5 );
		$what = 'dl' === $ctx
			? '고출력 기기로 연기를 바로 들이마시는 폐호흡(DL) 방식에 맞춘 액상입니다. 니코틴 농도가 낮은 편(3mg 안팎)이고 60ml 같은 큰 용량이 많습니다.'
			: '팟 · 소형 기기처럼 저출력으로 입에 머금었다 들이마시는 입호흡(MTL) 방식에 맞춘 액상입니다. 니코틴 농도가 높은 편(9.8mg 안팎)이고 30ml 가 기본입니다.';
		$items[] = array( 'q' => $l['ko'] . ' 액상이란 무엇인가요?', 'a' => $what );
		$items[] = array(
			'q' => $l['ko'] . ' 액상 가격은 얼마인가요?',
			'a' => ( $c['single'] > 0 ? '낱병은 ' . won( $c['single'] ) . '부터' : '낱병과 묶음으로 판매합니다' )
				. ( $c['bundle'] > 0 && $c['bundle_n'] > 0 ? ', 묶음은 병당 약 ' . won( floor( $c['bundle'] / $c['bundle_n'] / 100 ) * 100 ) . '입니다' : '' )
				. '. ' . $site . '는 ' . $l['ko'] . ' 액상' . ( $c['n'] ? ' ' . $c['n'] . '종' : '' ) . '을 판매하며 전 상품 가격표에서 한 번에 비교할 수 있습니다.',
		);
		$items[] = array(
			'q' => '어떤 ' . $l['ko'] . ' 액상 브랜드가 있나요?',
			'a' => ( $b ? implode( ' · ', $b ) . ' 등' : '여러 브랜드' ) . '의 ' . $l['ko'] . ' 액상을 낱병과 묶음으로 판매합니다.',
		);
		$items[] = array(
			'q' => '어떤 기기에 쓰나요?',
			'a' => 'dl' === $ctx
				? '코일 저항이 낮은(서브옴) 고출력 기기 · 탱크에 씁니다. 팟 같은 저출력 기기에 넣으면 맛과 연무가 제대로 나지 않습니다.'
				: '팟 · 코일 저항 1옴 안팎의 소형 기기에 씁니다. 고출력 기기에 넣으면 농도가 높아 맞지 않습니다.',
		);
		$items[] = array(
			'q' => '주문하면 언제 받을 수 있나요?',
			'a' => '평일 오후 4시 이전에 입금이 확인된 주문은 당일 출고합니다. ' . $rule . '. ' . $ship . ' 이상은 무료배송이고 가입 즉시 ' . $pts . '이 적립됩니다.',
		);
		$items[] = array(
			'q' => '누가 살 수 있나요?',
			'a' => '19세 이상 휴대폰 본인확인을 마친 회원만 구매할 수 있습니다. 비로그인 상태에서는 상품 사진이 가려져 보입니다.',
		);
	}
	return (array) apply_filters( 'duckhoo_broad_faq', $items, $ctx, $t );
}

function faq_heading( string $ctx ): string {
	if ( 'shop' === $ctx ) {
		return '전자담배 액상 자주 묻는 질문';
	}
	return label( $ctx )['ko'] . ' 액상 자주 묻는 질문';
}

/**
 * 격자 아래 FAQ. 노보 분류는 novo-seo.php 가 그리므로 여기 안 걸린다 (ctx 가 '').
 */
function faq_html(): void {
	$ctx = ctx();
	if ( '' === $ctx ) {
		return;
	}
	$t     = 'shop' === $ctx ? null : \Duckhoo\Redesign\Seo\noted_cat();
	$items = faq( $ctx, $t );
	if ( ! $items ) {
		return;
	}
	echo '<section class="dha-faq" aria-labelledby="dha-faq-h"><h2 id="dha-faq-h">' . esc_html( faq_heading( $ctx ) ) . '</h2>';
	foreach ( $items as $i => $it ) {
		echo '<details class="dha-faq__i"' . ( 0 === $i ? ' open' : '' ) . '><summary>' . esc_html( (string) $it['q'] ) . '</summary><p>' . esc_html( (string) $it['a'] ) . '</p></details>';
	}
	echo '<p class="dha-faq__more">자세한 안내: <a href="' . esc_url( guide_url( 'liquid-guide' ) ) . '">전자담배 액상 고르는 법</a> · '
		. '<a href="' . esc_url( guide_url( 'mtl-vs-dl' ) ) . '">입호흡 액상과 폐호흡 액상의 차이</a> · '
		. '<a href="' . esc_url( guide_url( 'price' ) ) . '">전 상품 가격표</a></p>';
	echo '</section>';
}
add_action( 'duckhoo_archive_after_grid', __NAMESPACE__ . '\\faq_html' );

function faq_jsonld(): void {
	$ctx = ctx();
	if ( '' === $ctx ) {
		return;
	}
	$t     = 'shop' === $ctx ? null : \Duckhoo\Redesign\Seo\noted_cat();
	$items = faq( $ctx, $t );
	if ( ! $items ) {
		return;
	}
	$data = array(
		'@context'   => 'https://schema.org',
		'@type'      => 'FAQPage',
		'mainEntity' => array_map(
			static fn( $it ) => array(
				'@type'          => 'Question',
				'name'           => (string) $it['q'],
				'acceptedAnswer' => array( '@type' => 'Answer', 'text' => (string) $it['a'] ),
			),
			array_values( $items )
		),
	);
	echo '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
}
add_action( 'wp_head', __NAMESPACE__ . '\\faq_jsonld', 5 );
