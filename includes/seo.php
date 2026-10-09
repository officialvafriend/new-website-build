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
		$d = cap( brand_intro( current_brand() ) );
		if ( thin_brand() ) {
			// AIOSEO 가 이 화면에 robots 를 안 찍으므로 우리가 찍는다 (상품 하나짜리 브랜드 — 2026-10-02)
			echo '<meta name="robots" content="noindex, follow">' . "\n";
		}
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
	$nc = noted_cat();
	if ( $nc ) {
		// 분류 페이지에는 AIOSEO 가 og 를 안 찍는다 (2026-09-21 프로덕션 확인 — og 태그 0개).
		// 카카오톡 · 오픈채팅에 이 주소를 붙이면 미리보기가 여기서 나온다. 설명은 필터로 이미 우리 것.
		$d = cat_note_text( $nc );
		echo '<meta property="og:type" content="website">' . "\n";
		echo '<meta property="og:title" content="' . esc_attr( cat_title( $nc ) ) . '">' . "\n";
		echo '<meta property="og:description" content="' . esc_attr( $d ) . '">' . "\n";
		if ( function_exists( 'get_term_link' ) ) {
			$u = get_term_link( $nc );
			if ( is_string( $u ) ) {
				echo '<meta property="og:url" content="' . esc_url( $u ) . '">' . "\n";
			}
		}
		if ( function_exists( 'get_site_icon_url' ) && '' !== (string) get_site_icon_url( 512 ) ) {
			echo '<meta property="og:image" content="' . esc_url( (string) get_site_icon_url( 512 ) ) . '">' . "\n";
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

	// 이름이 `[맥스쿨] 맥스쿨 소다…` 처럼 브랜드를 한 번 더 쓴 상품이 많다 —
	// 그대로 엮으면 「맥스쿨 맥스쿨 소다」가 된다 (2026-09-11).
	$brand   = trim( $n['brand'] );
	$title   = trim( $n['title'] );
	$parts[] = '' !== $brand && 0 === mb_strpos( $title, $brand ) ? $title : trim( $brand . ' ' . $title );
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
 * 메타 설명 길이를 자른다.
 *
 * 2026-09-11: 입호흡 분류의 설명이 230자였다. 검색 결과는 한글 기준 80자 안팎에서
 * 잘리므로 그 뒤는 아무도 못 읽는데, 검색엔진이 자르면 **문장 한가운데**서 끊긴다.
 * 여기서 **문장 끝**으로 잘라 두면 잘린 티가 안 난다.
 *
 * **원문은 건드리지 않는다** — AIOSEO 관리 화면의 글은 그대로 남고, 검색 결과로
 * 나가는 값만 줄인다. 끄려면 `duckhoo_meta_desc_max` 를 0 으로.
 *
 * @param string $d 설명.
 * @return string
 */
function cap( string $d ): string {
	$d   = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $d ) ) );
	$max = (int) apply_filters( 'duckhoo_meta_desc_max', 160 );
	if ( $max < 1 || mb_strlen( $d ) <= $max ) {
		return $d;
	}
	$head = mb_substr( $d, 0, $max );
	// 문장 끝(마침표 · 물음표 · 느낌표)에서 자른다.
	// 숫자 사이의 점(9.8mg · 0.6옴)은 문장 끝이 아니다 (2026-10-02 — 노보 소개가 「9.」에서 잘렸다)
	if ( preg_match( '/^(.*[.!?](?!\d))(?:[^.!?]|\.(?=\d))*$/u', $head, $m ) && mb_strlen( $m[1] ) >= (int) ( $max * 0.5 ) ) {
		return trim( $m[1] );
	}
	// 문장 끝이 없으면 낱말 경계에서 자르고 말줄임표를 붙인다.
	$cut = mb_strrpos( $head, ' ' );
	return trim( false !== $cut && $cut > $max * 0.5 ? mb_substr( $head, 0, $cut ) : $head ) . '…';
}

/**
 * AIOSEO 가 상품 템플릿으로 **자동으로 채운** 설명인가.
 *
 * 2026-09-11 측정: 상품 175개 중 137개의 메타 설명이 꼬리까지 똑같았다 —
 * 「… 상품입니다. 가입 시 적립금 8,800원 증정 + 3만원 이상 무료배송으로 빠르게 만나보세요.」
 * 이름과 분류만 바뀌는 글이라 맛 · 용량 · 니코틴이 한 글자도 없고, 값이 비어 있을 때만
 * 우리 글을 쓰던 규칙 때문에 **상품 글이 메타 설명에 나간 적이 한 번도 없었다.**
 * 사장님이 손으로 쓴 38개는 이 꼬리가 없어 그대로 남는다.
 *
 * @param string $d 지금 값.
 * @return bool
 */
function templated( string $d ): bool {
	// 2026-09-21: 두 번째 꼬리. 코덱스가 2026-09-11 에 노보 13개에 「노보 X 액상 30ml, 니코틴 9.8mg
	// 입호흡(MTL) 전용. 액상덕후에서 3만원 이상 무료배송, (신규) 가입 시 적립금 8,800원(증정).」
	// 을 규격대로 찍었다 — 맛이 없고, 다섯 개는 남의 맛(데저트 · 타박멘솔)이 붙었다. 전체 상품을
	// 다시 받아 세어 보니 이 꼬리는 노보 13개뿐이다. 펠릭스 · 네스티의 손글(「신규가입 적립금」,
	// 빈칸 없음)은 안 걸린다.
	$marks = array( '상품입니다. 가입 시 적립금', '무료배송, 신규 가입 시 적립금', '무료배송, 가입 시 적립금' );
	foreach ( (array) apply_filters( 'duckhoo_meta_desc_template_marks', $marks ) as $mark ) {
		$mark = (string) $mark;
		if ( '' !== $mark && false !== mb_strpos( $d, $mark ) ) {
			return true;
		}
	}
	return false;
}

/**
 * 검색 결과에서 누를 이유 한 줄. 앞의 상품 글이 먼저고 이것은 꼬리다 —
 * 160자를 넘으면 cap() 이 문장 끝에서 자르므로 이 꼬리부터 떨어진다.
 *
 * @param string $own 우리 상품 글.
 * @return string
 */
function with_shop( string $own ): string {
	$tail = (string) apply_filters( 'duckhoo_meta_desc_tail', '액상덕후 — 가입 즉시 ' . number_format( (float) signup_points() ) . '원 적립.' );
	return '' === trim( $tail ) ? $own : rtrim( $own ) . ' ' . trim( $tail );
}

/**
 * **사장님(·Codex)이 손으로 쓴 설명인데 틀린 상품.** 여기 적힌 주소는 손으로 쓴 글이라도
 * 우리 글로 덮는다 — AIOSEO 의 글은 우리 저장소에 없어 고칠 수가 없고, 그대로 두면
 * 검색 결과에 틀린 말이 계속 나간다 (2026-09-11 확인, 2026-09-14 덮음).
 *
 * | 주소 | 무엇이 틀렸나 |
 * |---|---|
 * | `노보-데저트-9-8mg-30ml` | 일반 라인인데 「노보 **블랙** 데저트」라고 적혀 있었다 |
 * | `초특가-노보-10병-병당-8000원-금액-80000원` | 실제 130,000원인데 「병당 7,000원(총 70,000원)」 |
 * | `펠릭스-더블라임-9-8mg-30ml` (#254) | 상품이 「라임 알로에」로 바뀌었는데 글은 「더블라임 20,000원」 (2026-10-09) — 더블라임은 새 상품 #4701 |
 *
 * 사장님이 AIOSEO 상자를 직접 고치시면 이 목록에서 그 주소만 빼면 된다 (필터 한 줄).
 *
 * @return string[]
 */
function overrides(): array {
	return array_map(
		'rawurldecode',
		(array) apply_filters(
			'duckhoo_meta_desc_override',
			array(
				'노보-데저트-9-8mg-30ml',
				'초특가-노보-10병-병당-8000원-금액-80000원',
				'펠릭스-더블라임-9-8mg-30ml',
			)
		)
	);
}

/**
 * 이 상품의 설명을 덮어야 하는가.
 *
 * @param \WC_Product $p 상품.
 * @return bool
 */
function overridden( \WC_Product $p ): bool {
	if ( ! method_exists( $p, 'get_slug' ) ) {
		return false;
	}
	return in_array( rawurldecode( (string) $p->get_slug() ), overrides(), true );
}

/**
 * 메타 설명. AIOSEO 가 비워 둔 자리만 채운다 — 사장님이 쓴 설명이 있으면 그대로.
 *
 * @param string $d 지금 값.
 * @return string
 */
function description( $d ): string {
	$d = trim( (string) $d );
	$p = function_exists( 'is_product' ) && is_product() && function_exists( 'wc_get_product' )
		? wc_get_product( get_queried_object_id() )
		: null;
	$p    = $p instanceof \WC_Product ? $p : null;
	$ours = $p && ( templated( $d ) || overridden( $p ) );
	$nc   = noted_cat();
	if ( $nc ) {
		return cap( cat_note_text( $nc ) );   // 노보 분류 — 손으로 쓴 글이 틀린 값(병당 7,000원)이라 대신 쓴다
	}
	if ( '' !== $d && ! $ours ) {
		return cap( $d );   // 사장님이 쓴 글. 원문은 그대로, 검색 결과로 나갈 때만 줄인다
	}
	if ( is_brand_page() ) {
		return cap( brand_intro( current_brand() ) );   // 화면에는 전문, 검색 결과에는 문장 끝에서 자른 것
	}
	if ( $p ) {
		$hand = trim( hand_text( $p ) );
		// auto_text() 는 꼬리(가입 적립 · 무료배송)를 이미 달고 있다 — 두 번 붙이지 않는다.
		return cap( '' !== $hand ? with_shop( $hand ) : auto_text( $p ) );
	}
	if ( function_exists( 'is_product' ) && is_product() ) {
		return cap( $d );   // 상품인데 못 읽었다 — 있던 글이라도 둔다. 비우는 것이 더 나쁘다
	}
	if ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() ) {
		$t = get_queried_object();
		if ( is_object( $t ) && ! empty( $t->name ) ) {
			$own = trim( wp_strip_all_tags( (string) ( $t->description ?? '' ) ) );
			return cap( '' !== $own ? $own : cat_text( $t ) );
		}
	}
	return '';
}
add_filter( 'aioseo_description', __NAMESPACE__ . '\\description', 20 );
// og · 트위터 설명도 AIOSEO 가 같은 글로 찍는다 — 한 화면에서 두 말이 갈리면 안 된다.
add_filter( 'aioseo_og_description', __NAMESPACE__ . '\\description', 20 );
add_filter( 'aioseo_twitter_description', __NAMESPACE__ . '\\description', 20 );

/**
 * 제목 — 브랜드 페이지만 우리가 정한다. 나머지는 AIOSEO 값 그대로.
 *
 * @param string $t 지금 값.
 * @return string
 */
function title( $t ): string {
	$nc = noted_cat();
	if ( $nc ) {
		return cat_title( $nc );
	}
	$pt = product_title_here();
	if ( '' !== $pt ) {
		return $pt;
	}
	if ( ! is_brand_page() ) {
		return (string) $t;
	}
	$b    = current_brand();
	$n    = brands()[ $b ] ?? 0;
	$note = (string) ( brand_notes()[ $b ]['title'] ?? '' );
	// 2026-10-02 — 품절이 있어 note 가 「낱병 N종 …」이면 종수를 두 번 적지 않는다 (「노보 액상 15종 낱병 12종」 ✗)
	$cnt = $n && ! str_starts_with( $note, '낱병' ) ? ' ' . $n . '종' : '';
	return $b . ' ' . brand_noun( $b ) . $cnt . ( '' !== $note ? ' ' . $note : '' ) . ' | ' . get_bloginfo( 'name' );
}
add_filter( 'aioseo_title', __NAMESPACE__ . '\\title', 20 );
add_filter( 'pre_get_document_title', __NAMESPACE__ . '\\title', 20 );

/**
 * 제목을 우리가 정하는 브랜드. 기본은 노보뿐 — 검색에서 「노보 액상」으로 들어오는
 * 손님이 지금 가장 많고(2026-09-21), AIOSEO 기본 제목 「[노보] 타박멘솔 (9.8mg / 30ml) - 액상덕후」
 * 에는 「액상」도 「입호흡」도 값도 없다. 다른 브랜드까지 넓히려면 필터에 이름을 더한다.
 *
 * @return string[]
 */
function title_brands(): array {
	return (array) apply_filters( 'duckhoo_product_title_brands', array( '노보' ) );
}

/**
 * 상품 제목 — `노보 타박멘솔 입호흡 액상 9.8mg 30ml 13,000원 | 액상덕후`.
 * 묶음은 `노보 액상 10+1 묶음 11병 120,000원 입호흡 | 액상덕후`.
 * 값 · 병 수는 상품에서 그때그때 읽는다 — 적어 두면 값이 바뀔 때 거짓이 된다 (2026-09-11 의 7,000원).
 * 제목을 정하지 않는 브랜드면 빈 문자열 (AIOSEO 값 그대로).
 *
 * @param \WC_Product $p 상품.
 * @return string
 */
function product_title( \WC_Product $p ): string {
	$n     = split_name( $p );
	$brand = brand_aliases()[ $n['brand'] ] ?? $n['brand'];
	if ( '' === $brand ) {
		return '';
	}
	if ( ! in_array( $brand, title_brands(), true ) ) {
		// 2026-10-09 — 손으로 쓴 제목이 틀린 상품(overrides())은 다른 브랜드라도 우리가 정한다.
		// 「[펠릭스] 더블라임 20,000원」이 이름이 바뀐 라임 알로에(#254)에 남아 새 더블라임(#4701)과 제목이 같았다.
		return overridden( $p ) ? plain_title( $p, $n ) : '';
	}
	$line  = trim( (string) preg_replace( '/\s*리퀴드\s*$/u', '', $n['brand'] ) );   // 노보 · 노보 블랙
	$title = $n['title'];
	$spec  = '';
	if ( preg_match( '/\(([^)]*)\)/u', $title, $m ) ) {
		$spec = trim( (string) preg_replace( '/\s+/u', ' ', str_replace( '/', ' ', $m[1] ) ) );   // (9.8mg / 30ml) → 9.8mg 30ml
	}
	$flavor = trim( (string) preg_replace( '/\s*\([^)]*\)\s*|\s*\|\s*금액.*$/u', '', $title ) );
	$price  = (float) $p->get_price();
	$won    = $price > 0 ? number_format( $price ) . '원' : '';
	$pb     = per_bottle( $p );
	if ( $pb['qty'] > 1 ) {
		$parts = array( $line . ' 액상 ' . $flavor . ( false === mb_strpos( $flavor, '묶음' ) ? ' 묶음' : '' ) . ' ' . $pb['qty'] . '병', $won, '입호흡' );
	} else {
		// 2026-10-06 — 「타박멘솔 액상 · 데저트액상 · 쿠바시가액상」처럼 손님은 맛 이름에 「액상」을 바로 붙여 친다 (네이버 연관 검색어).
		// 그 말이 사는 자리는 그 맛의 상품 페이지 **한 장**이다 — 제목에서 「노보 타박멘솔 액상」으로 붙여 쓴다.
		$parts = array( $line . ' ' . $flavor . ' 액상 입호흡', $spec, $won );
	}
	// 2026-10-01 — 노보를 찾아 돌아다니는 사람에게 제목에서 바로 답한다. 상품에서 읽은 값이라 품절이면 저절로 빠진다.
	if ( $p->is_in_stock() ) {
		$parts[] = '재고 있음';
	}
	return trim( implode( ' ', array_filter( $parts, fn( $x ) => '' !== trim( (string) $x ) ) ) ) . ' | ' . get_bloginfo( 'name' );
}

/**
 * 노보가 아닌 상품의 제목 — `펠릭스 라임 알로에 입호흡 액상 9.8mg 30ml 20,000원 | 액상덕후`.
 * 입호흡 · 폐호흡은 분류에 있을 때만 적는다. 값은 상품에서 읽는다.
 *
 * @param \WC_Product $p 상품.
 * @param array       $n split_name() 결과.
 * @return string
 */
function plain_title( \WC_Product $p, array $n ): string {
	$title = (string) $n['title'];
	$spec  = '';
	if ( preg_match( '/\(([^)]*)\)/u', $title, $m ) ) {
		$spec = trim( (string) preg_replace( '/\s+/u', ' ', str_replace( '/', ' ', $m[1] ) ) );
	}
	$flavor = trim( (string) preg_replace( '/\s*\([^)]*\)\s*/u', ' ', $title ) );
	$kind   = '';
	foreach ( cat_names( $p ) as $c ) {
		if ( preg_match( '/입호흡|폐호흡/u', $c, $k ) ) {
			$kind = $k[0];
			break;
		}
	}
	$price = (float) $p->get_price();
	$parts = array( $n['brand'] . ' ' . $flavor, trim( $kind . ' 액상' ), $spec, $price > 0 ? number_format( $price ) . '원' : '' );
	return trim( implode( ' ', array_filter( $parts, fn( $x ) => '' !== trim( (string) $x ) ) ) ) . ' | ' . get_bloginfo( 'name' );
}

/**
 * 지금 화면이 상품 상세이고 제목을 우리가 정하는 브랜드면 그 제목, 아니면 빈 문자열.
 *
 * @return string
 */
function product_title_here(): string {
	if ( ! function_exists( 'is_product' ) || ! is_product() || ! function_exists( 'wc_get_product' ) ) {
		return '';
	}
	$p = wc_get_product( get_queried_object_id() );
	return $p instanceof \WC_Product ? product_title( $p ) : '';
}

/**
 * og:title · twitter:title 도 같은 제목. AIOSEO 는 소셜 제목을 `aioseo_title` 로 안 거치고
 * 태그 배열째로 내주므로(`aioseo_facebook_tags` · `aioseo_twitter_tags`) 거기서 바꾼다.
 * 있는 칸만 바꾼다 — 없는 칸을 새로 만들지 않는다.
 *
 * @param mixed $tags 태그 배열.
 * @return mixed
 */
function social_title( $tags ) {
	if ( ! is_array( $tags ) ) {
		return $tags;
	}
	$t = product_title_here();
	if ( '' === $t ) {
		return $tags;
	}
	foreach ( array( 'og:title', 'twitter:title' ) as $k ) {
		if ( isset( $tags[ $k ] ) ) {
			$tags[ $k ] = $t;
		}
	}
	return $tags;
}
add_filter( 'aioseo_facebook_tags', __NAMESPACE__ . '\\social_title', 20 );
add_filter( 'aioseo_twitter_tags', __NAMESPACE__ . '\\social_title', 20 );

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
 * 상품이 `brand_min_n()`(2)개 이상인 브랜드만 싣는다 — 하나짜리는 noindex 다 (2026-10-02).
 *
 * @return string
 */
function brand_sitemap_xml(): string {
	$out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
		. '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
	foreach ( brands() as $b => $n ) {
		if ( $n < brand_min_n() ) {
			continue;   // 상품 하나짜리는 noindex 라 사이트맵에도 안 싣는다 (2026-10-02)
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
/**
 * 브랜드별 한 줄 — 지금 이 브랜드에 대해 꼭 해야 할 말.
 *
 * 2026-09-21: 다른 사이트에서 노보 품절. 「노보 액상」을 찾는 검색이 9월에 1.5배 뛰었고
 * (네이버 DataLab), 그 사람이 검색 결과에서 보는 글자가 제목 · 설명이다. 거기에
 * 「재고 있음」이 없으면 우리 페이지는 남들과 같은 줄에 선다. 품절이 풀리면 여기서 뺀다.
 *
 * @return array<string,array{title:string,lead:string}>
 */
function novo_words(): string {
	// 2026-10-02 — 한 맛이 품절이면 「전 라인」이라고 하지 않는다 (novo.php 의 stock_state 가 상품에서 읽는다)
	return function_exists( '\\Duckhoo\\Redesign\\Novo\\stock_phrase' ) ? \Duckhoo\Redesign\Novo\stock_phrase( 'noun' ) : '전 라인';
}

function brand_notes(): array {
	return (array) apply_filters( 'duckhoo_brand_notes', array(
		'노보' => array(
			'title' => novo_words() . ' 재고 보유 · 바로 주문',
			'lead'  => '액상덕후는 노보(NOVO) · 노보 블랙 전자담배 액상 ' . novo_words() . ' 재고를 보유하고 있어 노보 리퀴드를 지금 바로 주문하실 수 있습니다.',
		),
	) );
}

/**
 * 분류 페이지에서 **사장님이 쓴 AIOSEO 글을 우리가 대신하는 곳** — 지금은 노보 하나.
 *
 * 2026-09-21 네이버 「노보 액상」 검색에서 우리 것은 `/product-category/novo-liquid/` 하나가
 * 21번째로 나오는데, 찍힌 제목이 「노보 액상 가격 8종 | 10병 특가 병당7,000원」이었다.
 * 8종이 아니라 15종이고 병당 7,000원은 지금 파는 값이 아니다 (낱병 13,000 · 10+1 120,000).
 * 검색에서 7,000원을 보고 들어온 사람이 13,000원을 보면 나간다. **손으로 쓴 글은 그대로
 * 둔다**는 규칙의 예외다 — 틀린 값이라서. 값은 여기 적지 않고 상품에서 읽는다.
 *
 * 필터 `duckhoo_cat_notes`: slug → true. 비우면 AIOSEO 글로 돌아간다.
 *
 * @return array<string,bool>
 */
function cat_notes(): array {
	return (array) apply_filters( 'duckhoo_cat_notes', array( 'novo-liquid' => true ) );
}

/**
 * 지금 화면이 우리가 제목 · 설명을 대신 쓰는 분류인가. 맞으면 그 term.
 *
 * @return object|null
 */
function noted_cat() {
	if ( ! function_exists( 'is_product_taxonomy' ) || ! is_product_taxonomy() ) {
		return null;
	}
	$t = get_queried_object();
	if ( ! is_object( $t ) || empty( $t->slug ) ) {
		return null;
	}
	if ( ! empty( cat_notes()[ (string) $t->slug ] ) ) {
		return $t;
	}
	// 2026-10-01: 입호흡 · 폐호흡 분류도 우리가 제목 · 설명을 쓴다 (「전담 액상」 목표). 이름으로 가른다 —
	// 한글 슬러그는 DB 에 퍼센트 인코딩으로 들어가 슬러그 비교가 깨진다. 끄기: duckhoo_broad_cats → false
	if ( apply_filters( 'duckhoo_broad_cats', true ) && function_exists( '\\Duckhoo\\Redesign\\Seo\\Broad\\kind' ) && '' !== Broad\kind( $t ) ) {
		return $t;
	}
	return null;
}

/**
 * 분류 안의 값 — 낱병 최저가 · 묶음 최저가 · 종수. **상품에서 읽는다**, 적어 두지 않는다.
 *
 * @param object $t 분류.
 * @return array{n:int,single:float,bundle:float,bundle_n:int}
 */
function cat_prices( $t ): array {
	$out = array( 'n' => 0, 'single' => 0.0, 'bundle' => 0.0, 'bundle_n' => 0 );
	foreach ( products( array( 'category' => array( (string) $t->slug ), 'limit' => -1 ) ) as $p ) {
		if ( ! $p->is_in_stock() ) {
			continue;
		}
		++$out['n'];
		$price = (float) $p->get_price();
		if ( $price <= 0 ) {
			continue;
		}
		$each = (int) ( per_bottle( $p )['qty'] ?? 1 );
		if ( $each > 1 ) {
			if ( 0.0 === $out['bundle'] || $price < $out['bundle'] ) {
				$out['bundle']   = $price;
				$out['bundle_n'] = $each;
			}
		} elseif ( 0.0 === $out['single'] || $price < $out['single'] ) {
			$out['single'] = $price;
		}
	}
	return $out;
}

/**
 * 노보 분류의 제목 — 「노보 액상 15종 전 라인 재고 보유 | 액상덕후」.
 *
 * @param object $t 분류.
 * @return string
 */
function cat_title( $t ): string {
	if ( function_exists( '\\Duckhoo\\Redesign\\Seo\\Broad\\kind' ) && '' !== Broad\kind( $t ) ) {
		return Broad\cat_title( $t );   // 입호흡 · 폐호흡 (broad-seo.php)
	}
	$c = cat_prices( $t );
	return '노보 액상' . ( $c['n'] ? ' ' . $c['n'] . '종' : '' ) . ( '전 라인' === novo_words() ? ' 전 라인' : '' ) . ' 재고 보유 · 바로 주문 | ' . get_bloginfo( 'name' );
}

/**
 * 노보 분류의 검색 설명 — 값은 상품에서 읽은 그대로.
 *
 * @param object $t 분류.
 * @return string
 */
function cat_note_text( $t ): string {
	if ( function_exists( '\\Duckhoo\\Redesign\\Seo\\Broad\\kind' ) && '' !== Broad\kind( $t ) ) {
		return Broad\cat_text( $t );
	}
	$c     = cat_prices( $t );
	$price = array();
	if ( $c['single'] > 0 ) {
		$price[] = '낱병 ' . number_format( $c['single'] ) . '원부터';
	}
	if ( $c['bundle'] > 0 ) {
		$price[] = '10+1 묶음(' . (int) $c['bundle_n'] . '병) ' . number_format( $c['bundle'] ) . '원'
			. ( $c['bundle_n'] > 0 ? ' (병당 약 ' . number_format( floor( $c['bundle'] / $c['bundle_n'] / 100 ) * 100 ) . '원)' : '' );
	}
	$t = '액상덕후는 노보(NOVO) · 노보 블랙 전자담배 액상 ' . novo_words() . ' 재고를 보유하고 있어 노보 리퀴드를 지금 바로 주문하실 수 있습니다.'
		. ( $price ? ' ' . implode( ' · ', $price ) . '.' : '' )
		. ' 30ml · 니코틴 9.8mg · 입호흡(MTL). 평일 오후 4시 이전 입금 확인 시 당일 출고, '
		. number_format( free_ship() ) . '원 이상 무료배송.';
	return (string) apply_filters( 'duckhoo_cat_note_text', $t );
}

/**
 * 브랜드가 액상인가 기기(팟 · 코일)인가. 상품 전부가 「기기 / 팟 / 코일」 분류이거나
 * 이름에 팟 · 코일 · 기기 가 들어가면 기기다 — 「긱베이프 액상 1종」은 틀린 말이었다 (2026-10-02 외부 점검).
 *
 * @param string $brand 브랜드.
 * @return string 'device' | 'liquid'
 */
function brand_kind( string $brand ): string {
	$items = brand_items( $brand );
	if ( ! $items ) {
		return 'liquid';
	}
	foreach ( $items as $p ) {
		$dev = preg_match( '/팟|코일|기기|디바이스/u', $p->get_name() ) || in_array( true, array_map( fn( $c ) => (bool) preg_match( '/기기/u', $c ), cat_names( $p ) ), true );
		if ( ! $dev ) {
			return 'liquid';
		}
	}
	return 'device';
}

/**
 * 브랜드 뒤에 붙는 낱말 — 「액상」 또는 「기기 · 팟」.
 *
 * @param string $brand 브랜드.
 * @return string
 */
function brand_noun( string $brand ): string {
	return 'device' === brand_kind( $brand ) ? '기기 · 팟' : '액상';
}

/**
 * 이 브랜드의 상품 (이름 앞 대괄호 · 별칭으로 고른다). 요청 안에서 한 번만 읽는다.
 *
 * @param string $brand 브랜드.
 * @return \WC_Product[]
 */
function brand_items( string $brand ): array {
	static $memo = array();
	if ( isset( $memo[ $brand ] ) && empty( $GLOBALS['dhr_test'] ) ) {
		return $memo[ $brand ];
	}
	$out = array();
	foreach ( products( array( 's' => $brand, 'limit' => -1 ) ) as $p ) {
		$b = split_name( $p )['brand'];
		if ( $b === $brand || ( brand_aliases()[ $b ] ?? '' ) === $brand ) {
			$out[] = $p;
		}
	}
	return $memo[ $brand ] = $out;
}

/**
 * 상품 이름에서 맛 이름만 — 「[화이트아웃] 크랜베리 애플 (NTSC SALT / 30ml)」 → 「크랜베리 애플」,
 * 「[맥스쿨] 맥스쿨 소다 무니코틴 액상」 → 「소다」. 묶음 · 이벤트 이름은 빈 문자열.
 *
 * @param \WC_Product $p 상품.
 * @param string      $brand 브랜드.
 * @return string
 */
function flavor_name( \WC_Product $p, string $brand ): string {
	$t = split_name( $p )['title'];
	if ( preg_match( '/묶음|이벤트|EVENT|\d+\s*\+\s*\d+|\d+\s*병|증정|세트/iu', $t ) ) {
		return '';
	}
	$t = preg_replace( '/\([^)]*\)|\[[^\]]*\]/u', ' ', $t );                       // 괄호 안 규격
	$t = preg_replace( '/\d+(?:\.\d+)?\s*mg\s*\/\s*\d+\s*ml/iu', ' ', $t );            // 괄호 없는 규격 (3MG/60ML)
	$t = preg_replace( '/^(?:' . preg_quote( $brand, '/' ) . ')\s*/u', '', trim( $t ) ); // 브랜드 되풀이
	$t = preg_replace( '/무니코틴\s*액상|모드\s*액상|입호흡\s*액상|폐호흡\s*액상|기성액상|액상|시리즈|모드|[★!]/u', ' ', $t );
	$t = preg_replace( '/\s+/u', ' ', $t );
	return trim( $t, " ·-" );
}

/**
 * 브랜드 소개에 쓸 사실 — 종수 · 분류 · 규격 · 맛 · 값. **전부 상품에서 읽는다.**
 *
 * @param string $brand 브랜드.
 * @return array{n:int,noun:string,cats:string[],spec:string,flavors:string[],single_min:float,single_max:float,bundles:string[],bundle_per:float,items:string[]}
 */
function brand_facts( string $brand ): array {
	$items   = brand_items( $brand );
	$cats    = array();
	$specs   = array();
	$flavors = array();
	$smin    = 0.0;
	$smax    = 0.0;
	$bundles = array();
	$bper    = 0.0;
	$names   = array();
	foreach ( $items as $p ) {
		foreach ( cat_names( $p ) as $c ) {
			$cats[ $c ] = ( $cats[ $c ] ?? 0 ) + 1;
		}
		$price = (float) $p->get_price();
		$pb    = per_bottle( $p );
		$f     = flavor_name( $p, $brand );
		if ( preg_match( '/\(([^)]*(?:mg|ml|SALT)[^)]*)\)/iu', $p->get_name(), $m ) || preg_match( '/(\d+(?:\.\d+)?\s*mg\s*\/\s*\d+\s*ml)/iu', $p->get_name(), $m ) ) {
			$sp           = trim( preg_replace( '/\s*\/\s*/u', ' · ', $m[1] ) );
			$sp           = preg_replace_callback( '/(?<=[\d\s])(MG|ML|Mg|Ml|mL)\b/u', fn( $x ) => strtolower( $x[1] ), $sp );   // 3MG · 60ML → 3mg · 60ml
			$specs[ $sp ] = ( $specs[ $sp ] ?? 0 ) + 1;
		}
		if ( '' !== $f && $pb['qty'] <= 1 ) {
			$flavors[] = $f;
			if ( $price > 0 && $p->is_in_stock() ) {
				$smin = $smin > 0 ? min( $smin, $price ) : $price;
				$smax = max( $smax, $price );
			}
		} elseif ( $pb['qty'] > 1 ) {
			// 이름이 「10+1」 「5+5」 로 부르면 그대로 — 손님이 아는 이름이다
			$bundles[] = preg_match( '/(\d+)\s*\+\s*(\d+)/u', $p->get_name(), $bm ) ? $bm[1] . '+' . $bm[2] : $pb['qty'] . '병';
			if ( $pb['per'] > 0 && $p->is_in_stock() ) {
				$bper = $bper > 0 ? min( $bper, $pb['per'] ) : $pb['per'];
			}
		}
		$names[] = trim( preg_replace( '/\s+/u', ' ', preg_replace( '/\([^)]*\)/u', '', split_name( $p )['title'] ) ) );
	}
	arsort( $cats );
	arsort( $specs );
	return array(
		'n'          => count( $items ),
		'noun'       => brand_noun( $brand ),
		'cats'       => array_slice( array_keys( $cats ), 0, 3 ),
		// 규격은 둘까지 — 입호흡 30ml 와 폐호흡 60ml 를 같이 파는 브랜드(펠릭스 · 리퀴드랩)가 있다
		'spec'       => implode( ' / ', array_slice( array_keys( array_filter( $specs, fn( $c, $k ) => $c >= 2 || $k === array_key_first( $specs ), ARRAY_FILTER_USE_BOTH ) ), 0, 2 ) ),
		'flavors'    => array_values( array_unique( $flavors ) ),
		'single_min' => $smin,
		'single_max' => $smax,
		'bundles'    => array_values( array_unique( $bundles ) ),
		'bundle_per' => $bper,
		'items'      => $names,
	);
}

/**
 * 브랜드 소개 한 줄 — 화면(`.dhr-brandintro`) · 메타 설명 · og 에 같은 글.
 *
 * 2026-10-02 외부 점검: 30장 중 29장이 「N종을 한자리에 모았습니다 … 무료배송」 틀에 이름만 달랐다.
 * 이제 브랜드마다 **그 브랜드의 사실**(종수 · 입호흡/폐호흡/무니코틴 · 규격 · 맛 이름 · 값 · 묶음)을
 * 상품에서 읽어 엮는다 — 적어 두는 숫자가 없어 값 · 종수가 바뀌어도 거짓이 안 된다.
 * 노보의 재고 첫 문장(`brand_notes`)은 그대로 앞에 선다.
 *
 * @param string $brand 브랜드.
 * @return string
 */
function brand_intro( string $brand ): string {
	if ( '' === $brand ) {
		return '';
	}
	$f    = brand_facts( $brand );
	$n    = brands()[ $brand ] ?? $f['n'];
	$lead = (string) ( brand_notes()[ $brand ]['lead'] ?? '' );
	$kind = $f['noun'];
	$cw = array();
	foreach ( $f['cats'] as $c ) {
		if ( preg_match( '/입호흡|폐호흡|무니코틴/u', $c, $m ) ) {
			$cw[] = $m[0];
		}
	}
	$cat = implode( ' · ', array_unique( $cw ) );   // 「입호흡 · 폐호흡」 — 둘 다 파는 브랜드
	$parts = array();
	if ( 'device' === brand_kind( $brand ) ) {
		// 기기 · 팟 — 상품 이름과 값을 그대로. 「액상」이라고 하지 않는다
		$rows = array();
		foreach ( brand_items( $brand ) as $p ) {
			$nm = trim( preg_replace( '/\s+/u', ' ', preg_replace( '/\([^)]*\)|\[[^\]]*\]|[★!]|이벤트/u', ' ', split_name( $p )['title'] ) ) );
			$nm = trim( preg_replace( '/^(?:' . preg_quote( $brand, '/' ) . ')\s*/u', '', $nm ) );
			$pr = (float) $p->get_price();
			$rows[] = $nm . ( $pr > 0 ? ' ' . number_format( $pr ) . '원' : '' );
		}
		$parts[] = $brand . ' 기기 · 팟 · 코일' . ( $n ? ' ' . $n . '종' : '' ) . ': ' . implode( ' · ', array_slice( $rows, 0, 4 ) ) . '.';
	} else {
		$head = $brand . ( '' !== $cat ? ' ' . $cat : '' ) . ' 액상' . ( $n ? ' ' . $n . '종' : '' );
		// 2026-10-06 — 노보는 맛 이름을 나열하지 않는다: 브랜드 페이지가 「노보 코코넛커피 · 쿠바시가」에서 상품 페이지 자리를 먹었다.
		// 맛 검색은 그 맛의 상품 페이지 한 장이 받아야 한다. 다른 브랜드(상품 페이지가 그 말로 안 뜨는 곳)는 그대로.
		$fl   = in_array( $brand, (array) apply_filters( 'duckhoo_brand_intro_no_flavors', array( '노보' ) ), true ) ? array() : $f['flavors'];
		if ( $fl ) {
			$shown  = array_slice( $fl, 0, 5 );
			$rest   = count( $fl ) - count( $shown );
			$head  .= ' — ' . implode( ' · ', $shown ) . ( $rest > 0 ? ' 외 ' . $rest . '종' : '' );
		}
		$parts[] = $head . '.';
		$spec = array();
		if ( '' !== $f['spec'] ) {
			$spec[] = $f['spec'];
		}
		if ( $f['single_min'] > 0 ) {
			$spec[] = '낱병 ' . number_format( $f['single_min'] ) . '원' . ( $f['single_max'] > $f['single_min'] ? '~' . number_format( $f['single_max'] ) . '원' : '' );
		}
		if ( $spec ) {
			$parts[] = implode( ' · ', $spec ) . '.';
		}
		if ( $f['bundles'] ) {
			$parts[] = implode( ' · ', $f['bundles'] ) . ' 묶음' . ( $f['bundle_per'] > 0 ? ' 병당 ' . number_format( $f['bundle_per'] ) . '원부터' : '' ) . '.';
		}
	}
	$parts[] = '가입 즉시 ' . number_format( signup_points() ) . '원 적립 · ' . number_format( free_ship() ) . '원 이상 무료배송.';
	$t = ( '' !== $lead ? $lead . ' ' : '' ) . implode( ' ', $parts );
	return (string) apply_filters( 'duckhoo_brand_intro', $t, $brand );
}

/**
 * 상품이 이만큼 안 되는 브랜드 페이지는 검색에서 뺀다 (noindex · 사이트맵 제외).
 * 상품 하나짜리 페이지는 그 상품 상세와 같은 내용이라 얇은 중복이다 (2026-10-02 외부 점검 — 30장 중 9장).
 * 페이지 자체는 그대로 있다 — 카드 · 푸터의 브랜드 링크는 살아 있고, 상품이 늘면 저절로 다시 실린다.
 *
 * @return int
 */
function brand_min_n(): int {
	return max( 1, (int) apply_filters( 'duckhoo_brand_min_n', 2 ) );
}

/**
 * 지금 브랜드 페이지가 얇아 색인에서 빼야 하는가.
 *
 * @return bool
 */
function thin_brand(): bool {
	return is_brand_page() && ( brands()[ current_brand() ] ?? 0 ) < brand_min_n();
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
	return is_brand_page() ? current_brand() . ' ' . brand_noun( current_brand() ) : '';
}

/**
 * 워드프레스 robots — 얇은 브랜드 페이지에 noindex (AIOSEO 가 핵심 robots 를 끄지 않았을 때의 뒷받침).
 *
 * @param mixed $r 지금 값.
 * @return array
 */
function robots_thin_brand( $r ) {
	if ( ! thin_brand() ) {
		return $r;
	}
	$r            = is_array( $r ) ? $r : array();
	$r['noindex'] = true;
	$r['follow']  = true;
	return $r;
}
add_filter( 'wp_robots', __NAMESPACE__ . '\\robots_thin_brand', 21 );

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
	$code   = naver_code();
	$wl_msg = function_exists( '\\Duckhoo\\Redesign\\Seo\\Report\\handle_post' ) ? \Duckhoo\Redesign\Seo\Report\handle_post() : '';

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
	$long_cats  = array();
	if ( function_exists( 'get_terms' ) ) {
		$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true ) );
		foreach ( is_array( $terms ) ? $terms : array() as $t ) {
			if ( '' === trim( wp_strip_all_tags( (string) $t->description ) ) ) {
				$empty_cats[] = $t->name;
			} elseif ( mb_strlen( trim( wp_strip_all_tags( (string) $t->description ) ) ) > 160 ) {
				$long_cats[] = $t->name . ' (' . mb_strlen( trim( wp_strip_all_tags( (string) $t->description ) ) ) . '자)';
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
	echo '<tr><th>검색 결과용 길이</th><td>' . ( $long_cats ? '<b>' . esc_html( implode( ' · ', $long_cats ) ) . '</b> 의 설명이 160자를 넘습니다. 원문은 그대로 두고 <b>검색 결과로 나가는 값만</b> 문장 끝에서 잘라 보냅니다 — 검색엔진이 문장 한가운데서 끊는 것을 막기 위해서입니다.' : '모든 설명이 160자 안입니다.' ) . '</td></tr>';
	echo '<tr><th>브랜드 페이지</th><td>';
	foreach ( featured_brands( 8 ) as $b ) {
		$u = brand_url( $b );
		echo '<a href="' . esc_url( $u ) . '" target="_blank" rel="noopener">' . esc_html( $u ) . '</a><br>';
	}
	echo '<span class="description">이름 앞 [브랜드] 로 모은 목록입니다. 푸터 · 홈의 브랜드 링크가 이 주소를 씁니다. AIOSEO 사이트맵에는 안 실리므로 <code>' . esc_html( home_url( '/brands.xml' ) ) . '</code> 을 네이버 · 구글에 따로 제출합니다.</span></td></tr>';
	echo '<tr><th>상품 구조화 데이터</th><td>브랜드 · 설명을 채웁니다. 가격 · 재고는 워드커머스 값 그대로.</td></tr>';
	echo '</tbody></table>';
	echo '<p class="description" style="max-width:720px">글에 쓰지 않는 말: 건강 · 금연 · 순하다 · 해롭지 않다 (담배사업법 광고 제한). 맛 · 용량 · 니코틴 · 기기 호환 · 가격 · 배송만 말합니다.</p>';
	if ( function_exists( '\\Duckhoo\\Redesign\\Seo\\Report\\box' ) ) {
		\Duckhoo\Redesign\Seo\Report\box( $wl_msg );
	}
	echo '</div>';
}
