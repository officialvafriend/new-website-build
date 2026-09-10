<?php
/**
 * 검색 노출 (SEO) — 바닥 다지기.
 *
 * 2026-09-10 프로덕션을 비로그인으로 받아 본 것:
 *
 * - 상품 설명이 **전부 이미지**다 (글자 0). 검색엔진은 이미지 안의 글을 읽지 못하니
 *   「펠릭스 더블라임」을 치면 우리 상품 페이지에 그 말이 제목 한 줄뿐이다.
 *   노보마트는 상품마다 「구수한 타바코 베이스와 청량한 멘솔」 같은 **글** 이 있고,
 *   네이버가 그 글을 그대로 설명으로 보여 준다
 * - 상품 JSON-LD 의 `description` 이 비어 있다 · 폐호흡 분류에 메타 설명이 없다
 * - `naver-site-verification` 이 없다 — 네이버 서치어드바이저에 사이트가 등록돼 있지 않다
 * - **브랜드 페이지가 없다.** 「노보 액상」을 치는 사람에게 줄 주소가 검색 결과
 *   (`?s=노보`) 뿐이다. 검색 결과 주소는 검색엔진이 색인하지 않는다
 *
 * 여기서 하는 일 — **테마 · AIOSEO · 키플 파일은 건드리지 않는다.**
 *
 * 1. 네이버 인증 메타 한 줄 (`도구 → 검색 노출` 에서 코드만 붙인다)
 * 2. 상품마다 **글로 쓴 한 줄 설명** (`_dhr_text`). 상품 편집 화면의 상자에 쓰면
 *    상세 화면(가격 아래) · 메타 설명 · JSON-LD `description` 세 곳에 같은 글이 간다.
 *    안 쓴 상품은 이름 · 가격 · 분류로 **사실만** 엮어 메타 · JSON-LD 를 채우고,
 *    화면에는 그리지 않는다 (보이는 값을 되풀이하는 글이다)
 * 3. 분류 메타 설명이 비어 있으면 채운다 (입호흡 · 폐호흡 · 무니코틴 · 그 밖)
 * 4. **브랜드 페이지** `/brand/novo/` 처럼. 이름 앞 `[노보]` · `[노보 블랙]` 인 상품을
 *    목록 템플릿으로 그린다. 제목 · 설명 · canonical 을 우리가 정하고, 푸터 · 홈의
 *    브랜드 링크가 전부 이 주소를 쓴다 (필터 `duckhoo_brand_url`)
 *
 * **쓰지 않는 말**: 건강 · 금연 · 순하다 · 해롭지 않다. 담배사업법의 광고 제한이다.
 * 글은 맛 · 용량 · 니코틴 · 기기 호환 · 가격 · 배송만 말한다.
 *
 * @package Duckhoo\Redesign
 */

declare( strict_types = 1 );

namespace Duckhoo\Redesign\Seo;

use function Duckhoo\Redesign\Front\{split_name, brands, brand_aliases, per_bottle, products, featured_brands};

defined( 'ABSPATH' ) || exit;

const META      = '_dhr_text';          // 상품 한 줄 설명 (글)
const OPT_NAVER = 'duckhoo_naver_verify';
const OPT_RW    = 'duckhoo_seo_rewrite';
const RW_V      = '3';
const QV        = 'dhr_brand';

/* ── 1. 네이버 인증 ──────────────────────────────────────────────────────── */

/**
 * 네이버 서치어드바이저 인증 코드. 비어 있으면 메타를 찍지 않는다.
 *
 * @return string
 */
function naver_code(): string {
	return clean_code( (string) apply_filters( 'duckhoo_naver_verify', (string) get_option( OPT_NAVER, '' ) ) );
}

/**
 * 붙여 넣은 것에서 코드만 꺼낸다.
 *
 * 2026-09-10 사장님이 `<meta name="naver-site-verification" content="04c1…">` 태그를
 * 통째로 붙였는데 글자만 남기는 정리가 `metanamenaversiteverificationcontent04c1…` 로
 * 이어 붙여 네이버가 「메타태그를 찾을 수 없습니다」라고 했다. 태그든 코드든 받는다.
 *
 * @param string $raw 입력.
 * @return string
 */
function clean_code( string $raw ): string {
	if ( preg_match( '/content\s*=\s*["\']?\s*([A-Za-z0-9]+)/i', $raw, $m ) ) {
		return $m[1];
	}
	$c = preg_replace( '/[^A-Za-z0-9]/', '', $raw );
	// 전에 잘못 저장된 값: 태그 글자가 앞에 붙어 있다
	return (string) preg_replace( '/^metanamenaversiteverificationcontent/i', '', $c );
}

/**
 * `<head>` — 인증 메타. AIOSEO 에도 같은 칸이 있지만 사장님이 어디에 넣었는지
 * 잊지 않게 우리 도구 화면 한 곳에 둔다. 둘 다 있어도 네이버는 하나만 맞으면 된다.
 *
 * @return void
 */
function head(): void {
	$c = naver_code();
	if ( '' !== $c ) {
		echo '<meta name="naver-site-verification" content="' . esc_attr( $c ) . '">' . "\n";
	}
	if ( is_brand_page() ) {
		// AIOSEO 는 이 화면이 무엇인지 모른다 — 상품도 분류도 검색도 아니라서 canonical 만 찍고
		// 설명 · og 는 아예 안 찍는다 (2026-09-10 스테이징에서 확인). 그래서 여기서만 우리가 찍는다.
		$d = brand_intro( current_brand() );
		echo '<meta name="description" content="' . esc_attr( $d ) . '">' . "\n";
		echo '<meta property="og:type" content="website">' . "\n";
		echo '<meta property="og:title" content="' . esc_attr( title( '' ) ) . '">' . "\n";
		echo '<meta property="og:description" content="' . esc_attr( $d ) . '">' . "\n";
		echo '<meta property="og:url" content="' . esc_url( canonical( '' ) ) . '">' . "\n";
		if ( ! has_aioseo() ) {
			echo '<link rel="canonical" href="' . esc_url( canonical( '' ) ) . '">' . "\n";
		}
		return;
	}
	// AIOSEO 가 없을 때만 우리가 설명을 찍는다. 있으면 필터로 그쪽 값을 채운다.
	if ( ! has_aioseo() ) {
		$d = description( '' );
		if ( '' !== $d ) {
			echo '<meta name="description" content="' . esc_attr( $d ) . '">' . "\n";
		}
	}
}
add_action( 'wp_head', __NAMESPACE__ . '\\head', 1 );

/**
 * AIOSEO 가 켜져 있는가.
 *
 * @return bool
 */
function has_aioseo(): bool {
	return defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' );
}

/* ── 2. 상품 한 줄 설명 ──────────────────────────────────────────────────── */

/**
 * 손으로 쓴 설명. 없으면 빈 문자열.
 *
 * @param \WC_Product $p 상품.
 * @return string
 */
function hand_text( \WC_Product $p ): string {
	$t = trim( (string) $p->get_meta( META, true ) );
	return trim( (string) apply_filters( 'duckhoo_product_text', $t, $p ) );
}

/**
 * 상품의 분류 이름 — 검색어가 되는 것(입호흡 · 폐호흡 · 무니코틴 · 기기)을 앞에.
 * 「9월 특가 할인」 · 「적립금 상품」 · 「랭킹」 같은 행사 분류는 설명에 넣지 않는다 —
 * 달이 바뀌면 거짓이 된다.
 *
 * @param \WC_Product $p 상품.
 * @return string[]
 */
function cat_names( \WC_Product $p ): array {
	if ( ! function_exists( 'get_the_terms' ) ) {
		return array();
	}
	$terms = get_the_terms( $p->get_id(), 'product_cat' );
	if ( ! is_array( $terms ) ) {
		return array();
	}
	$out = array();
	foreach ( $terms as $t ) {
		$name = is_object( $t ) ? (string) $t->name : (string) $t;
		if ( preg_match( '/특가|할인|적립|랭킹|이벤트|추천/u', $name ) ) {
			continue;
		}
		$out[] = trim( preg_replace( '/\s*\/\s*/u', ' · ', $name ) );
	}
	usort( $out, fn( $a, $b ) => (int) ! preg_match( '/입호흡|폐호흡|무니코틴/u', $a ) - (int) ! preg_match( '/입호흡|폐호흡|무니코틴/u', $b ) );
	return array_values( array_unique( $out ) );
}

/**
 * 손으로 쓴 글이 없을 때 — 이름 · 가격 · 분류로 **사실만** 엮는다.
 * 메타 설명과 JSON-LD 에만 쓴다. 화면에는 그리지 않는다.
 *
 * @param \WC_Product $p 상품.
 * @return string
 */
function auto_text( \WC_Product $p ): string {
	$n     = split_name( $p );
	$pb    = per_bottle( $p );
	$price = (float) $p->get_price();
	$parts = array();

	$parts[] = trim( $n['brand'] . ' ' . $n['title'] );
	if ( $price > 0 ) {
		$money = number_format( $price ) . '원';
		if ( $pb['qty'] > 1 ) {
			$money .= ' (병당 ' . number_format( floor( $pb['per'] / 10 ) * 10 ) . '원)';
		}
		$parts[] = $money;
	}
	$cats = array_slice( cat_names( $p ), 0, 2 );
	if ( $cats ) {
		$parts[] = implode( ' · ', $cats );
	}
	$parts[] = '액상덕후 — 가입 즉시 ' . number_format( signup_points() ) . '원 적립, ' . number_format( free_ship() ) . '원 이상 무료배송';

	return implode( '. ', $parts ) . '.';
}

/**
 * 설명으로 쓸 글 — 손으로 쓴 것이 먼저, 없으면 사실로 엮은 것.
 *
 * @param \WC_Product $p 상품.
 * @return string
 */
function text( \WC_Product $p ): string {
	$t = hand_text( $p );
	return '' !== $t ? $t : auto_text( $p );
}

/**
 * 상세 화면 — 가격 아래에 손으로 쓴 설명만 그린다. `form.cart` 바깥이다.
 *
 * @param \WC_Product|null $product 상품.
 * @return void
 */
function render_text( $product = null ): void {
	if ( ! $product instanceof \WC_Product ) {
		return;
	}
	$t = hand_text( $product );
	if ( '' === $t ) {
		return;
	}
	echo '<p class="dhp-about">' . esc_html( $t ) . '</p>';
}
add_action( 'duckhoo_product_after_price', __NAMESPACE__ . '\\render_text', 20 );

/**
 * JSON-LD `description` — 비어 있을 때만 채운다.
 *
 * @param array            $data    워드커머스가 만든 상품 데이터.
 * @param \WC_Product|null $product 상품.
 * @return array
 */
function schema_desc( $data, $product = null ): array {
	$data = (array) $data;
	if ( ! $product instanceof \WC_Product || ! empty( trim( (string) ( $data['description'] ?? '' ) ) ) ) {
		return $data;
	}
	$data['description'] = text( $product );
	return $data;
}
add_filter( 'woocommerce_structured_data_product', __NAMESPACE__ . '\\schema_desc', 11, 2 );

/* ── 3. 메타 설명 · 제목 ─────────────────────────────────────────────────── */

/**
 * 가입 적립금 — Front 와 같은 값.
 *
 * @return int
 */
function signup_points(): int {
	return function_exists( 'Duckhoo\\Redesign\\Front\\signup_points' ) ? (int) \Duckhoo\Redesign\Front\signup_points() : 8800;
}

/**
 * 무료배송 기준 — Front 와 같은 값.
 *
 * @return int
 */
function free_ship(): int {
	return (int) apply_filters( 'duckhoo_free_shipping_min', 30000 );
}

/**
 * 분류 설명 — 관리자의 설명이 비어 있을 때 쓰는 글.
 *
 * @param object $term 분류.
 * @return string
 */
function cat_text( $term ): string {
	$name  = (string) ( $term->name ?? '' );
	$count = (int) ( $term->count ?? 0 );
	$kind  = '액상';
	if ( false !== mb_strpos( $name, '입호흡' ) ) {
		$kind = '입호흡(MTL) 기기용 액상';
	} elseif ( false !== mb_strpos( $name, '폐호흡' ) ) {
		$kind = '폐호흡(DL) 기기용 액상';
	} elseif ( false !== mb_strpos( $name, '무니코틴' ) ) {
		$kind = '니코틴 없는 무니코틴 액상';
	} elseif ( preg_match( '/기기|팟|코일/u', $name ) ) {
		$kind = '전자담배 기기 · 팟 · 코일';
	} elseif ( false !== mb_strpos( $name, '노보' ) ) {
		$kind = '노보 · 노보 블랙 액상';
	}
	$t = $kind . ( $count ? ' ' . $count . '종' : '' ) . '. 브랜드별 묶음 할인, 병당 가격 표시. '
		. '액상덕후 — 가입 즉시 ' . number_format( signup_points() ) . '원 적립, ' . number_format( free_ship() ) . '원 이상 무료배송.';
	return (string) apply_filters( 'duckhoo_cat_text', $t, $term );
}

/**
 * 메타 설명. AIOSEO 가 비워 둔 자리만 채운다 — 사장님이 쓴 설명이 있으면 그대로.
 *
 * @param string $d 지금 값.
 * @return string
 */
function description( $d ): string {
	$d = trim( (string) $d );
	if ( '' !== $d ) {
		return $d;
	}
	if ( is_brand_page() ) {
		return brand_intro( current_brand() );
	}
	if ( function_exists( 'is_product' ) && is_product() ) {
		$p = function_exists( 'wc_get_product' ) ? wc_get_product( get_queried_object_id() ) : null;
		return $p instanceof \WC_Product ? text( $p ) : '';
	}
	if ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() ) {
		$t = get_queried_object();
		if ( is_object( $t ) && ! empty( $t->name ) ) {
			$own = trim( wp_strip_all_tags( (string) ( $t->description ?? '' ) ) );
			return '' !== $own ? mb_substr( $own, 0, 160 ) : cat_text( $t );
		}
	}
	return '';
}
add_filter( 'aioseo_description', __NAMESPACE__ . '\\description', 20 );

/**
 * 제목 — 브랜드 페이지만 우리가 정한다. 나머지는 AIOSEO 값 그대로.
 *
 * @param string $t 지금 값.
 * @return string
 */
function title( $t ): string {
	if ( ! is_brand_page() ) {
		return (string) $t;
	}
	$b = current_brand();
	$n = brands()[ $b ] ?? 0;
	return $b . ' 액상' . ( $n ? ' ' . $n . '종' : '' ) . ' | ' . get_bloginfo( 'name' );
}
add_filter( 'aioseo_title', __NAMESPACE__ . '\\title', 20 );
add_filter( 'pre_get_document_title', __NAMESPACE__ . '\\title', 20 );

/**
 * canonical — 브랜드 페이지만.
 *
 * @param string $url 지금 값.
 * @return string
 */
function canonical( $url ): string {
	return is_brand_page() ? brand_url( current_brand() ) : (string) $url;
}
add_filter( 'aioseo_canonical_url', __NAMESPACE__ . '\\canonical', 20 );

/* ── 4. 브랜드 페이지 ────────────────────────────────────────────────────── */

/**
 * 브랜드 → 주소 조각. 영문이 있는 브랜드는 영문으로, 없으면 한글 그대로
 * (워드프레스가 퍼센트 인코딩한다 — 네이버 · 구글 다 읽는다).
 *
 * @return array<string,string>
 */
function brand_slugs(): array {
	return (array) apply_filters( 'duckhoo_brand_slugs', array(
		'노보'       => 'novo',
		'네스티'     => 'nasty',
		'화이트아웃' => 'whiteout',
		'펠릭스'     => 'felix',
		'디오리퀴드' => 'the-o-liquid',
		'액상덕후'   => 'duckhoo',
		'얼려먹구싶오' => 'frozen',
		'제로닉 무무' => 'zeronic-mumu',
		'맥스쿨'     => 'maxcool',
		'심쿵'       => 'simkung',
	) );
}

/**
 * @param string $brand 브랜드 이름.
 * @return string
 */
function brand_slug( string $brand ): string {
	$map = brand_slugs();
	return $map[ $brand ] ?? rawurlencode( $brand );
}

/**
 * 주소 조각 → 브랜드 이름. 모르는 조각은 빈 문자열 (404 가 된다).
 *
 * @param string $slug 조각.
 * @return string
 */
function brand_from_slug( string $slug ): string {
	$slug = trim( rawurldecode( $slug ) );
	if ( '' === $slug ) {
		return '';
	}
	foreach ( brand_slugs() as $b => $s ) {
		if ( 0 === strcasecmp( $s, $slug ) ) {
			return $b;
		}
	}
	foreach ( array_keys( brands() ) as $b ) {
		if ( 0 === strcasecmp( $b, $slug ) ) {
			return $b;
		}
	}
	return '';
}

/**
 * 브랜드 페이지 주소.
 *
 * @param string $brand 브랜드.
 * @return string
 */
function brand_url( string $brand ): string {
	return home_url( '/brand/' . brand_slug( $brand ) . '/' );
}
add_filter( 'duckhoo_brand_url', fn( $url, $brand ) => brand_url( (string) $brand ), 10, 2 );

/**
 * 지금 화면이 브랜드 페이지인가.
 *
 * @return bool
 */
function is_brand_page(): bool {
	return function_exists( 'get_query_var' ) && '' !== (string) get_query_var( QV, '' ) && '' !== current_brand();
}

/**
 * 지금 화면의 브랜드.
 *
 * @return string
 */
function current_brand(): string {
	return function_exists( 'get_query_var' ) ? brand_from_slug( (string) get_query_var( QV, '' ) ) : '';
}

/**
 * 이름 앞에 붙는 대괄호 — 이 브랜드로 묶이는 것 전부 (`[노보]` · `[노보 블랙]`).
 *
 * @param string $brand 브랜드.
 * @return string[]
 */
function brand_prefixes( string $brand ): array {
	$out = array( '[' . $brand . ']' );
	foreach ( brand_aliases() as $from => $to ) {
		if ( $to === $brand ) {
			$out[] = '[' . $from . ']';
		}
	}
	return $out;
}

/**
 * 주소 규칙. 한 번 바뀔 때만 flush 한다 (옵션에 버전을 적어 둔다).
 *
 * @return void
 */
function rewrite(): void {
	add_rewrite_tag( '%' . QV . '%', '([^&]+)' );
	add_rewrite_rule( '^brand/([^/]+)/?$', 'index.php?' . QV . '=$matches[1]', 'top' );
	add_rewrite_tag( '%dhr_sitemap%', '([a-z]+)' );
	add_rewrite_rule( '^brands\.xml$', 'index.php?dhr_sitemap=brand', 'top' );
	if ( RW_V !== (string) get_option( OPT_RW, '' ) && function_exists( 'flush_rewrite_rules' ) ) {
		flush_rewrite_rules( false );
		update_option( OPT_RW, RW_V );
	}
}
add_action( 'init', __NAMESPACE__ . '\\rewrite', 20 );

/**
 * 쿼리 변수.
 *
 * @param array $vars 변수.
 * @return array
 */
function query_vars( $vars ): array {
	$vars   = (array) $vars;
	$vars[] = QV;
	$vars[] = 'dhr_sitemap';
	return $vars;
}

/**
 * 브랜드 페이지 사이트맵 `/brands.xml`. **`…-sitemap.xml` 로 지으면 안 된다** — AIOSEO 가 그 모양을 전부 자기 규칙으로 잡아 모르는 이름에 404 를 낸다 (2026-09-10 확인).
 *
 * AIOSEO 사이트맵은 상품 · 분류 · 글만 안다 — 브랜드 페이지는 워드프레스 글이 아니라
 * 거기 안 실린다. 그래서 따로 낸다. 네이버 · 구글에 한 번 더 제출하면 된다.
 * 상품이 한 개라도 있는 브랜드만 싣는다 (빈 페이지는 404 다).
 *
 * @return string
 */
function brand_sitemap_xml(): string {
	$out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
		. '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
	foreach ( brands() as $b => $n ) {
		if ( $n < 1 ) {
			continue;
		}
		$out .= "\t<url><loc>" . esc_url( brand_url( (string) $b ) ) . "</loc><changefreq>weekly</changefreq></url>\n";
	}
	return $out . '</urlset>' . "\n";
}

/**
 * 사이트맵 요청이면 XML 을 내고 끝낸다.
 *
 * @return void
 */
function serve_sitemap(): void {
	if ( 'brand' !== (string) get_query_var( 'dhr_sitemap', '' ) ) {
		return;
	}
	status_header( 200 );
	header( 'Content-Type: application/xml; charset=UTF-8' );
	header( 'X-Robots-Tag: noindex' );
	echo brand_sitemap_xml(); // phpcs:ignore
	exit;
}
add_action( 'template_redirect', __NAMESPACE__ . '\serve_sitemap', 0 );
add_filter( 'query_vars', __NAMESPACE__ . '\\query_vars' );

/**
 * 브랜드 페이지의 메인 쿼리 — 상품 · 공개 · 한 화면 분량. 이름 조건은 `posts_where` 가 건다.
 * is_home 을 끈다 — 프로덕션 첫 화면이 블로그라 그대로 두면 `is_front_page()` 가 참이 되어
 * 홈 템플릿이 잡힌다.
 *
 * @param \WP_Query $q 쿼리.
 * @return void
 */
function pre_get_posts( $q ): void {
	if ( ! is_object( $q ) || ! method_exists( $q, 'is_main_query' ) || ! $q->is_main_query() ) {
		return;
	}
	$slug = (string) $q->get( QV, '' );
	if ( '' === $slug ) {
		return;
	}
	$q->set( 'post_type', 'product' );
	$q->set( 'post_status', 'publish' );
	$q->set( 'posts_per_page', (int) apply_filters( 'duckhoo_brand_per_page', 48 ) );
	if ( ! $q->get( 'orderby' ) ) {
		$q->set( 'orderby', 'date' );
		$q->set( 'order', 'DESC' );
	}
	$q->is_home    = false;
	$q->is_archive = true;
	$q->is_404     = false;
	if ( '' === brand_from_slug( $slug ) ) {
		$q->set( 'post__in', array( 0 ) ); // 모르는 브랜드 → 빈 결과 → 404
	}
}
add_action( 'pre_get_posts', __NAMESPACE__ . '\\pre_get_posts' );

/**
 * 이름 앞 `[브랜드]` 로 고른다. 제목 LIKE 라 색인은 안 타지만 상품 173개다.
 *
 * @param string    $where WHERE.
 * @param \WP_Query $q     쿼리.
 * @return string
 */
function posts_where( $where, $q = null ): string {
	global $wpdb;
	if ( ! is_object( $q ) || ! method_exists( $q, 'get' ) ) {
		return (string) $where;
	}
	$brand = brand_from_slug( (string) $q->get( QV, '' ) );
	if ( '' === $brand || ! isset( $wpdb ) ) {
		return (string) $where;
	}
	$likes = array();
	$args  = array();
	foreach ( brand_prefixes( $brand ) as $pre ) {
		$likes[] = "{$wpdb->posts}.post_title LIKE %s";
		$args[]  = $wpdb->esc_like( $pre ) . '%';
	}
	return (string) $where . $wpdb->prepare( ' AND (' . implode( ' OR ', $likes ) . ')', $args ); // phpcs:ignore
}
add_filter( 'posts_where', __NAMESPACE__ . '\\posts_where', 10, 2 );

/**
 * 브랜드 소개 한 줄 — 화면 맨 위에 **글자로** 보이고, 메타 설명도 이것이다.
 * 분류 이름은 그 브랜드 상품이 실제로 들어 있는 것만 센다.
 *
 * @param string $brand 브랜드.
 * @return string
 */
function brand_intro( string $brand ): string {
	if ( '' === $brand ) {
		return '';
	}
	$n    = brands()[ $brand ] ?? 0;
	$cats = array();
	foreach ( products( array( 's' => $brand, 'limit' => -1 ) ) as $p ) {
		if ( split_name( $p )['brand'] !== $brand && ( brand_aliases()[ split_name( $p )['brand'] ] ?? '' ) !== $brand ) {
			continue;
		}
		foreach ( cat_names( $p ) as $c ) {
			$cats[ $c ] = ( $cats[ $c ] ?? 0 ) + 1;
		}
	}
	arsort( $cats );
	$cats = array_slice( array_keys( $cats ), 0, 3 );
	$t    = $brand . ' 액상' . ( $n ? ' ' . $n . '종' : '' ) . '을 한자리에 모았습니다.'
		. ( $cats ? ' ' . implode( ' · ', $cats ) . '.' : '' )
		. ' 묶음 할인과 병당 가격을 같이 보여 드려요. 가입 즉시 ' . number_format( signup_points() ) . '원 적립, '
		. number_format( free_ship() ) . '원 이상 무료배송.';
	return (string) apply_filters( 'duckhoo_brand_intro', $t, $brand );
}

/**
 * 화면에 그리는 소개 (목록 템플릿이 부른다).
 *
 * @return string
 */
function brand_intro_html(): string {
	if ( ! is_brand_page() ) {
		return '';
	}
	return '<p class="dhr-brandintro">' . esc_html( brand_intro( current_brand() ) ) . '</p>';
}

/**
 * 브랜드 페이지 제목 (h1).
 *
 * @return string
 */
function brand_title(): string {
	return is_brand_page() ? current_brand() . ' 액상' : '';
}

/**
 * 브랜드 페이지도 우리 목록 템플릿으로.
 *
 * @param bool $take 지금 값.
 * @return bool
 */
function take_archive( $take ): bool {
	return (bool) $take || is_brand_page();
}
add_filter( 'duckhoo_is_archive_page', __NAMESPACE__ . '\\take_archive' );

/**
 * 깔때기 — 브랜드 페이지는 목록 단계다.
 *
 * @param string $stage 지금 값.
 * @return string
 */
function funnel_stage( $stage ): string {
	return ( '' === (string) $stage && is_brand_page() ) ? 'list' : (string) $stage;
}
add_filter( 'duckhoo_funnel_stage', __NAMESPACE__ . '\\funnel_stage' );

/* ── 관리자 ─────────────────────────────────────────────────────────────── */

/**
 * 상품 편집 화면 — 한 줄 설명 상자.
 *
 * @return void
 */
function meta_box(): void {
	add_meta_box( 'dhr_text', '검색에 보이는 한 줄 설명 (글)', __NAMESPACE__ . '\\meta_box_html', 'product', 'normal', 'high' );
}
add_action( 'add_meta_boxes', __NAMESPACE__ . '\\meta_box' );

/**
 * @param \WP_Post $post 글.
 * @return void
 */
function meta_box_html( $post ): void {
	$v = (string) get_post_meta( (int) $post->ID, META, true );
	wp_nonce_field( 'dhr_text_save', 'dhr_text_nonce' );
	echo '<textarea name="dhr_text" rows="3" maxlength="300" style="width:100%;font-size:14px;line-height:1.6" placeholder="예) 라임 두 겹의 상큼함에 멘솔을 살짝. 30ml · 입호흡 기기 권장. 맛 · 용량 · 니코틴 · 기기만 말하고 건강 · 금연 표현은 쓰지 않습니다.">'
		. esc_textarea( $v ) . '</textarea>';
	echo '<p class="description">상세 화면의 가격 아래, 네이버 · 구글 검색 결과의 설명, 상품 구조화 데이터 세 곳에 같은 글이 갑니다. 비워 두면 검색 결과에는 이름 · 가격 · 분류로 엮은 한 줄이 가고 화면에는 아무것도 안 그립니다. 300자까지.</p>';
}

/**
 * 저장.
 *
 * @param int $post_id 글 ID.
 * @return void
 */
function save_meta( $post_id ): void {
	if ( ! isset( $_POST['dhr_text_nonce'] ) || ! wp_verify_nonce( (string) $_POST['dhr_text_nonce'], 'dhr_text_save' ) ) { // phpcs:ignore
		return;
	}
	if ( ! current_user_can( 'edit_post', (int) $post_id ) ) {
		return;
	}
	$v = mb_substr( sanitize_textarea_field( wp_unslash( (string) ( $_POST['dhr_text'] ?? '' ) ) ), 0, 300 ); // phpcs:ignore
	if ( '' === trim( $v ) ) {
		delete_post_meta( (int) $post_id, META );
	} else {
		update_post_meta( (int) $post_id, META, $v );
	}
}
add_action( 'save_post_product', __NAMESPACE__ . '\\save_meta' );

/**
 * 도구 → 검색 노출.
 *
 * @return void
 */
function menu(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	add_management_page( '검색 노출', '검색 노출', 'manage_options', 'duckhoo-seo', __NAMESPACE__ . '\\screen' );
}
add_action( 'admin_menu', __NAMESPACE__ . '\\menu' );

/**
 * 도구 화면.
 *
 * @return void
 */
function screen(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$saved = false;
	if ( isset( $_POST['dhr_seo_nonce'] ) && wp_verify_nonce( (string) $_POST['dhr_seo_nonce'], 'dhr_seo_save' ) ) { // phpcs:ignore
		update_option( OPT_NAVER, clean_code( wp_unslash( (string) ( $_POST['naver'] ?? '' ) ) ) ); // phpcs:ignore
		$saved = true;
	}
	$code = naver_code();

	// 글 없는 상품 수
	$no_text = 0;
	$total   = 0;
	if ( function_exists( 'wc_get_products' ) ) {
		foreach ( products( array( 'limit' => -1 ) ) as $p ) {
			++$total;
			if ( '' === hand_text( $p ) ) {
				++$no_text;
			}
		}
	}
	// 설명 없는 분류
	$empty_cats = array();
	if ( function_exists( 'get_terms' ) ) {
		$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true ) );
		foreach ( is_array( $terms ) ? $terms : array() as $t ) {
			if ( '' === trim( wp_strip_all_tags( (string) $t->description ) ) ) {
				$empty_cats[] = $t->name;
			}
		}
	}

	echo '<div class="wrap"><h1>검색 노출</h1>';
	if ( $saved ) {
		echo '<div class="notice notice-success"><p>저장했습니다.</p></div>';
	}
	echo '<form method="post" style="max-width:720px">';
	wp_nonce_field( 'dhr_seo_save', 'dhr_seo_nonce' );
	echo '<h2>네이버 서치어드바이저</h2>';
	echo '<p><a href="https://searchadvisor.naver.com/" target="_blank" rel="noopener">searchadvisor.naver.com</a> 에서 <code>https://duck-hoo.com</code> 을 등록하고, <b>HTML 태그</b> 방식의 인증 코드를 여기에 붙입니다. 태그 전체를 붙여도 되고 content 값만 붙여도 됩니다. 저장하면 모든 화면 <code>&lt;head&gt;</code> 에 메타가 들어가고, 그 다음 네이버 화면에서 「소유확인」을 누르면 됩니다.</p>';
	echo '<p><input type="text" name="naver" value="' . esc_attr( $code ) . '" class="regular-text" placeholder="예) 3f2a9c…"> ';
	echo '<button class="button button-primary">저장</button></p>';
	echo '<p>' . ( '' !== $code ? '<span style="color:#1b7f3a">● 메타가 나가고 있습니다.</span> 확인 뒤에는 서치어드바이저 → 요청 → 사이트맵 제출에 <code>' . esc_html( home_url( '/sitemap.xml' ) ) . '</code> 을 넣어 주세요.' : '<span style="color:#b45309">● 아직 코드가 없습니다.</span> 네이버는 인증 전에는 사이트맵을 받지 않습니다.' ) . '</p>';
	echo '</form>';

	echo '<h2>플러그인이 채우는 것</h2><table class="widefat striped" style="max-width:720px"><tbody>';
	echo '<tr><th>상품 한 줄 설명 (글)</th><td>' . (int) $total . '종 중 <b>' . (int) $no_text . '종</b>이 비어 있습니다. 상품 편집 화면의 「검색에 보이는 한 줄 설명」 상자에 쓰면 상세 · 검색 결과 · 구조화 데이터에 같이 갑니다. 비어 있는 상품은 이름 · 가격 · 분류로 엮은 한 줄이 검색 결과에 갑니다.</td></tr>';
	echo '<tr><th>분류 설명</th><td>' . ( $empty_cats ? '<b>' . esc_html( implode( ' · ', $empty_cats ) ) . '</b> 은 관리자 설명이 비어 있어 플러그인 글이 검색 결과에 갑니다. 분류 편집의 「설명」을 채우면 그것이 우선입니다 (목록 화면에는 그리지 않습니다).' : '모든 분류에 설명이 있습니다.' ) . '</td></tr>';
	echo '<tr><th>브랜드 페이지</th><td>';
	foreach ( featured_brands( 8 ) as $b ) {
		$u = brand_url( $b );
		echo '<a href="' . esc_url( $u ) . '" target="_blank" rel="noopener">' . esc_html( $u ) . '</a><br>';
	}
	echo '<span class="description">이름 앞 [브랜드] 로 모은 목록입니다. 푸터 · 홈의 브랜드 링크가 이 주소를 씁니다. AIOSEO 사이트맵에는 안 실리므로 <code>' . esc_html( home_url( '/brands.xml' ) ) . '</code> 을 네이버 · 구글에 따로 제출합니다.</span></td></tr>';
	echo '<tr><th>상품 구조화 데이터</th><td>브랜드 · 설명을 채웁니다. 가격 · 재고는 워드커머스 값 그대로.</td></tr>';
	echo '</tbody></table>';
	echo '<p class="description" style="max-width:720px">글에 쓰지 않는 말: 건강 · 금연 · 순하다 · 해롭지 않다 (담배사업법 광고 제한). 맛 · 용량 · 니코틴 · 기기 호환 · 가격 · 배송만 말합니다.</p>';
	echo '</div>';
}
