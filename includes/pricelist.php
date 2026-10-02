<?php
/**
 * 전자담배 액상 가격표 — `/price/` (2026-09-28).
 *
 * 구글 서치콘솔 28일치를 보니 「액상 가격 · 입호흡 액상 가격 · 폐호흡 액상 가격 · 전담 액상 가격 · 무니코틴 액상 가격」
 * 처럼 **값을 묻는 검색이 합쳐 290번** 떴는데 우리는 7위였다. 그 검색에 답하는 페이지가 없었다 —
 * 값은 상품 카드에 흩어져 있고, 검색엔진이 읽을 「가격표」 라는 글은 없었다.
 *
 * 이 페이지는 전 상품의 **실시간 판매가**를 분류별 표로 늘어놓는다. 값을 적어 두지 않는다 — 워드커머스가
 * 쥔 값을 그때그때 읽으므로 값을 바꿔도 거짓이 되지 않는다. 묶음은 병당 가격을 같이 적는다
 * (사장님 규칙: 파는 값이 먼저, 병당은 캡션). 품절도 「품절」로 적어 표가 늘 완전하게 한다.
 *
 * 페이지 자체는 `pages.php` 가 만들고(`[duckhoo_price_table]` 한 줄), 이 파일이 그 숏코드를 그린다.
 * 10분 캐시(`dhr_pricelist` — 상품 저장 때 `Front\flush_cache()` 가 `dhr_*` 를 통째로 비운다).
 *
 * @package Duckhoo\Redesign
 */

namespace Duckhoo\Redesign\PriceList;

defined( 'ABSPATH' ) || exit;

const SLUG = 'price';

/**
 * 이름에서 니코틴 · 용량을 읽는다. 「(9.8mg / 30ml)」 「3MG/60ML」 「0.98MG / 30ml」 전부.
 *
 * @param string $name 상품 이름.
 * @return array{nic:string,ml:string}
 */
function spec( string $name ): array {
	$nic = preg_match( '/(\d+(?:\.\d+)?)\s*mg/iu', $name, $m ) ? $m[1] . 'mg' : '';
	$ml  = preg_match( '/(\d+)\s*ml/iu', $name, $m ) ? $m[1] . 'ml' : '';
	if ( preg_match( '/무니코틴|nico\s*0|0\s*mg/iu', $name ) ) {
		$nic = '무니코틴';
	}
	// 2026-10-02 — 화이트아웃이 「(NTSC SALT / 30ml)」 로 바뀌었다 (사장님). mg 가 없으니 그 이름을 그대로 적는다
	if ( '' === $nic && preg_match( '/NTSC\s*SALT/iu', $name ) ) {
		$nic = 'NTSC SALT';
	}
	return array( 'nic' => $nic, 'ml' => $ml );
}

/**
 * 분류 묶음 — 검색이 묻는 단위 그대로: 입호흡 · 폐호흡 · 무니코틴 · 기기.
 *
 * @param string[] $cats 상품의 분류 이름들.
 * @param string   $name 상품 이름.
 * @return string
 */
function group_of( array $cats, string $name ): string {
	$all = implode( ' ', $cats ) . ' ' . $name;
	if ( preg_match( '/기기|팟|코일/u', implode( ' ', $cats ) ) ) {
		return 'device';
	}
	if ( preg_match( '/무니코틴/u', $all ) ) {
		return 'nicfree';
	}
	if ( preg_match( '/폐호흡|모드/u', $all ) ) {
		return 'dl';
	}
	return 'mtl';
}

/**
 * 묶음 제목 · 한 줄 설명. 낱말은 분류 메타 설명(`Seo\cat_text`)과 같은 결을 쓴다 — 건강 · 금연 · 순하다 는 안 쓴다.
 *
 * @return array<string,array{h:string,s:string}>
 */
function groups(): array {
	return (array) apply_filters(
		'duckhoo_price_groups',
		array(
			'mtl'     => array( 'h' => '입호흡 액상 가격', 's' => '입호흡(MTL) 기기용 액상. 30ml · 니코틴 9.8mg 이 기본이고, 묶음은 병당 가격을 같이 적었습니다.' ),
			'dl'      => array( 'h' => '폐호흡 액상 가격', 's' => '폐호흡(DL) 모드 기기용 액상. 60ml · 니코틴 3mg 이 기본입니다.' ),
			'nicfree' => array( 'h' => '무니코틴 액상 가격', 's' => '니코틴이 들어 있지 않은 액상. 입호흡 · 폐호흡 기기 모두 쓸 수 있습니다.' ),
			'device'  => array( 'h' => '기기 · 팟 · 코일 가격', 's' => '입호흡 기기와 교체용 팟 · 코일.' ),
		)
	);
}

/**
 * 상품 하나를 표의 한 줄로 — 순수 함수. 테스트가 이것을 본다.
 *
 * @param \WC_Product $p 상품.
 * @return array{group:string,brand:string,name:string,url:string,nic:string,ml:string,price:float,per:float,qty:int,stock:bool}
 */
function row( \WC_Product $p ): array {
	$name  = (string) $p->get_name();
	$split = function_exists( 'Duckhoo\\Redesign\\Front\\split_name' ) ? \Duckhoo\Redesign\Front\split_name( $p ) : array( 'brand' => '', 'title' => $name );
	$pb    = function_exists( 'Duckhoo\\Redesign\\Front\\per_bottle' ) ? \Duckhoo\Redesign\Front\per_bottle( $p ) : array( 'per' => (float) $p->get_price(), 'qty' => 1 );
	$cats  = function_exists( 'Duckhoo\\Redesign\\Seo\\cat_names' ) ? \Duckhoo\Redesign\Seo\cat_names( $p ) : array();
	$spec  = spec( $name );
	$url   = method_exists( $p, 'get_permalink' ) ? (string) $p->get_permalink() : ( function_exists( 'get_permalink' ) ? (string) get_permalink( $p->get_id() ) : '' );
	return array(
		'group' => group_of( $cats, $name ),
		'brand' => (string) ( $split['brand'] ?? '' ),
		'name'  => trim( (string) ( $split['title'] ?? $name ) ),
		'url'   => $url,
		'nic'   => $spec['nic'],
		'ml'    => $spec['ml'],
		'price' => (float) $p->get_price(),
		'per'   => (float) ( $pb['per'] ?? $p->get_price() ),
		'qty'   => (int) ( $pb['qty'] ?? 1 ),
		'stock' => (bool) $p->is_in_stock(),
	);
}

/**
 * 전 상품 → 줄들. 묶음 안에서 브랜드 → 이름 순, 재고 있는 것이 먼저.
 *
 * @param \WC_Product[] $products 상품들.
 * @return array<string,array[]>
 */
function rows( array $products ): array {
	$out = array();
	foreach ( $products as $p ) {
		if ( ! $p instanceof \WC_Product || (float) $p->get_price() <= 0 ) {
			continue;
		}
		$r                  = row( $p );
		$out[ $r['group'] ][] = $r;
	}
	foreach ( $out as &$list ) {
		usort(
			$list,
			function ( $a, $b ) {
				if ( $a['stock'] !== $b['stock'] ) {
					return $a['stock'] ? -1 : 1;
				}
				return strcmp( $a['brand'] . $a['name'], $b['brand'] . $b['name'] );
			}
		);
	}
	unset( $list );
	$ordered = array();
	foreach ( array_keys( groups() ) as $k ) {
		if ( ! empty( $out[ $k ] ) ) {
			$ordered[ $k ] = $out[ $k ];
		}
	}
	return $ordered;
}

/**
 * 원 단위 글자.
 *
 * @param float $n 금액.
 * @return string
 */
function won( float $n ): string {
	return number_format( round( $n ) ) . '원';
}

/**
 * 표 HTML — 순수 함수.
 *
 * @param array<string,array[]> $groups rows() 결과.
 * @return string
 */
function table_html( array $groups ): string {
	$defs = groups();
	$n    = array_sum( array_map( 'count', $groups ) );
	$p    = function_exists( 'Duckhoo\\Redesign\\Seo\\Pages\\perks' ) ? \Duckhoo\Redesign\Seo\Pages\perks() : array( 'points' => '8,800', 'ship' => '30,000' );
	$h    = '<div class="dhr-pl">';
	$h   .= '<p class="dhr-pl__lead">액상덕후에서 파는 전 상품 <b>' . (int) $n . '종</b>의 판매가입니다. 실제 판매가를 그대로 읽어 오므로 이 표와 상품 화면의 값은 같습니다. '
		. '묶음은 병당 가격을 함께 적었고, 품절은 품절로 표시했습니다. ' . esc_html( $p['ship'] ) . '원 이상 무료배송, 가입 즉시 ' . esc_html( $p['points'] ) . '원 적립. 19세 이상 본인확인 회원만 구매할 수 있습니다.</p>';
	$h   .= '<nav class="dhr-pl__nav" aria-label="가격표 묶음">';
	foreach ( $groups as $k => $list ) {
		$h .= '<a href="#pl-' . esc_attr( $k ) . '">' . esc_html( $defs[ $k ]['h'] ?? $k ) . ' <span>' . count( $list ) . '</span></a>';
	}
	$h .= '</nav>';
	foreach ( $groups as $k => $list ) {
		$d  = $defs[ $k ] ?? array( 'h' => $k, 's' => '' );
		$h .= '<section class="dhr-pl__sec" id="pl-' . esc_attr( $k ) . '"><h2>' . esc_html( $d['h'] ) . ' <small>' . count( $list ) . '종</small></h2>';
		if ( '' !== $d['s'] ) {
			$h .= '<p class="dhr-pl__s">' . esc_html( $d['s'] ) . '</p>';
		}
		$h .= '<div class="dhr-pl__scroll"><table class="dhr-pl__t"><thead><tr><th scope="col">상품</th><th scope="col">니코틴 · 용량</th><th scope="col" class="num">판매가</th><th scope="col" class="num">병당</th></tr></thead><tbody>';
		foreach ( $list as $r ) {
			$spec = trim( $r['nic'] . ( '' !== $r['nic'] && '' !== $r['ml'] ? ' · ' : '' ) . $r['ml'] );
			$name = ( '' !== $r['brand'] ? '<b>' . esc_html( $r['brand'] ) . '</b> ' : '' ) . esc_html( $r['name'] );
			$h   .= '<tr' . ( $r['stock'] ? '' : ' class="is-out"' ) . '><td><a href="' . esc_url( $r['url'] ) . '">' . $name . '</a>' . ( $r['stock'] ? '' : ' <em>품절</em>' ) . '</td>'
				. '<td>' . esc_html( $spec ) . '</td>'
				. '<td class="num">' . esc_html( won( $r['price'] ) ) . '</td>'
				. '<td class="num">' . ( $r['qty'] > 1 ? esc_html( won( $r['per'] ) ) . ' <small>× ' . (int) $r['qty'] . '병</small>' : '—' ) . '</td></tr>';
		}
		$h .= '</tbody></table></div></section>';
	}
	$h .= '<p class="dhr-pl__foot">값은 예고 없이 바뀔 수 있습니다. 장바구니에 담아 둔 뒤 값이 바뀐 상품은 결제 전에 비우고 다시 담아 주세요.</p>';
	return $h . '</div>';
}

/**
 * 숏코드 `[duckhoo_price_table]`.
 *
 * @return string
 */
function shortcode(): string {
	$key  = 'dhr_pricelist';
	$html = get_transient( $key );
	if ( is_string( $html ) && '' !== $html ) {
		return $html;
	}
	$products = function_exists( 'Duckhoo\\Redesign\\Front\\products' )
		? \Duckhoo\Redesign\Front\products( array( 'limit' => -1, 'orderby' => 'title', 'order' => 'ASC' ) )
		: array();
	$html     = table_html( rows( $products ) );
	set_transient( $key, $html, 10 * MINUTE_IN_SECONDS );
	return $html;
}
add_shortcode( 'duckhoo_price_table', __NAMESPACE__ . '\\shortcode' );

/**
 * 상품 수 — 제목 · 설명에 쓴다.
 *
 * @return int
 */
function count_products(): int {
	if ( ! function_exists( 'wp_count_posts' ) ) {
		return 0;
	}
	$c = wp_count_posts( 'product' );
	return is_object( $c ) ? (int) ( $c->publish ?? 0 ) : 0;
}

/**
 * 검색 결과 제목 · 설명 — 값을 묻는 검색 그대로 답한다.
 *
 * @param int $n 상품 수.
 * @return array{title:string,desc:string}
 */
function seo( int $n ): array {
	$p = function_exists( 'Duckhoo\\Redesign\\Seo\\Pages\\perks' ) ? \Duckhoo\Redesign\Seo\Pages\perks() : array( 'points' => '8,800', 'ship' => '30,000' );
	return array(
		'title' => '전자담배 액상 가격표 — 입호흡 · 폐호흡 · 무니코틴' . ( $n > 0 ? ' ' . $n . '종' : '' ) . ' | 액상덕후',
		'desc'  => '전자담배 액상 가격을 한 표로. 입호흡 · 폐호흡 · 무니코틴 액상과 기기 · 팟' . ( $n > 0 ? ' ' . $n . '종' : '' ) . '의 판매가와 묶음 병당 가격. '
			. $p['ship'] . '원 이상 무료배송, 가입 즉시 ' . $p['points'] . '원 적립.',
	);
}
