<?php
/**
 * 프론트 — 홈 화면을 플러그인이 그린다.
 *
 * 테마 파일은 건드리지 않는다. 첫 화면(is_front_page)에서만 template_include 로
 * 우리 템플릿을 쓰고, 그 안에서 실제 WooCommerce 상품을 읽어 데모(design/demo.html)와
 * 같은 구조로 그린다. wp_head / wp_footer 는 그대로 부르므로 GTM · WooCommerce · PPOM
 * 스크립트는 전부 살아 있다. 상품 목록·상세·장바구니·결제·계정 화면은 테마와 WooCommerce
 * 템플릿이 그대로 그린다 — 폼 필드와 데이터 경로에 손대지 않기 위해서다.
 *
 * @package DuckhooRedesign
 */

declare( strict_types = 1 );

namespace Duckhoo\Redesign\Front;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const DIR = __DIR__ . '/../';

/* ────────────────────────────────────────────────────────────────
 * 데이터
 * ──────────────────────────────────────────────────────────────── */

/**
 * 카테고리를 이름으로 찾는다. "8월 특가 할인" 처럼 달이 바뀌는 이름은 부분 일치로 잡는다.
 *
 * @param string $needle 이름 일부.
 * @return \WP_Term|null
 */
function cat_by_name( string $needle ): ?\WP_Term {
	static $terms = null;
	if ( null === $terms ) {
		$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
		if ( is_wp_error( $terms ) ) {
			$terms = array();
		}
	}
	foreach ( $terms as $t ) {
		if ( false !== mb_strpos( $t->name, $needle ) ) {
			return $t;
		}
	}
	return null;
}

/**
 * 상품 목록. 결과는 10분 캐시한다 — 홈이 한 번에 상품을 예닐곱 번 묻기 때문이다.
 *
 * @param array $args wc_get_products 인자.
 * @return \WC_Product[]
 */
function products( array $args ): array {
	if ( ! function_exists( 'wc_get_products' ) ) {
		return array();
	}
	$args = array_merge( array( 'status' => 'publish', 'limit' => 12, 'return' => 'objects' ), $args );
	if ( isset( $args['include'] ) && ! $args['include'] ) {
		return array(); // include 가 비면 WC 는 "전부" 로 읽는다. 그건 의도가 아니다.
	}
	$key  = 'dhr_p_' . md5( wp_json_encode( $args ) );
	$ids  = get_transient( $key );
	if ( ! is_array( $ids ) ) {
		if ( isset( $args['s'] ) ) {
			// 이름 검색은 WC_Product_Query 가 모른다. WP_Query 로 ID 만 받는다.
			$q   = new \WP_Query( array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				's'              => $args['s'],
				'posts_per_page' => (int) $args['limit'],
				'fields'         => 'ids',
				'orderby'        => 'date',
			) );
			$ids = array_map( 'intval', $q->posts );
		} else {
			$ids = array_map( fn( $p ) => $p->get_id(), wc_get_products( $args ) );
		}
		set_transient( $key, $ids, 10 * MINUTE_IN_SECONDS );
	}
	return array_values( array_filter( array_map( 'wc_get_product', $ids ) ) );
}

/**
 * 이름 앞의 [브랜드] 를 뗀다. 상품명은 "[맥스쿨] 샤인머스캣 무니코틴 액상" 식으로 들어 있다.
 *
 * @param \WC_Product $p 상품.
 * @return array{brand:string,title:string}
 */
function split_name( \WC_Product $p ): array {
	$name = $p->get_name();
	if ( preg_match( '/^\[([^\]]+)\]\s*(.*)$/u', $name, $m ) ) {
		return array( 'brand' => trim( $m[1] ), 'title' => trim( $m[2] ) ?: $name );
	}
	return array( 'brand' => '', 'title' => $name );
}

/**
 * 브랜드 목록 (상품 수 순). 브랜드 분류(taxonomy)가 없어서 이름 앞 [브랜드] 로 센다.
 *
 * @return array<string,int>
 */
function brands(): array {
	$cached = get_transient( 'dhr_brands' );
	if ( is_array( $cached ) ) {
		return $cached;
	}
	$out = array();
	foreach ( products( array( 'limit' => -1 ) ) as $p ) {
		$b = split_name( $p )['brand'];
		// "10병 묶음 할인 이벤트" 같은 행사 접두는 브랜드가 아니다
		if ( '' === $b || preg_match( '/이벤트|할인|특가|초특가/u', $b ) ) {
			continue;
		}
		$b           = brand_aliases()[ $b ] ?? $b;
		$out[ $b ]   = ( $out[ $b ] ?? 0 ) + 1;
	}
	arsort( $out );
	set_transient( 'dhr_brands', $out, 10 * MINUTE_IN_SECONDS );
	return $out;
}

/**
 * 분류 타일 아이콘. 이름으로 고른다 — 분류 이름이 달마다 바뀌어도 따라간다.
 *
 * @param string $name 분류 이름.
 * @return string 아이콘 이름.
 */
function cat_icon( string $name ): string {
	$map = array(
		'특가'     => 'tag',
		'할인'     => 'tag',
		'적립'     => 'coin',
		'입호흡'   => 'pod',
		'폐호흡'   => 'cloud',
		'무니코틴' => 'zero',
		'기기'     => 'device',
		'팟'       => 'device',
		'코일'     => 'device',
		'노보'     => 'drop',
	);
	foreach ( $map as $needle => $ic ) {
		if ( false !== mb_strpos( $name, $needle ) ) {
			return $ic;
		}
	}
	return 'grid';
}

/**
 * 브랜드 이름을 하나로 묶는 표. 시리즈가 갈라져 있어도 손님에게는 한 브랜드다 —
 * "노보 블랙" 은 노보의 시리즈이지 다른 브랜드가 아니다.
 *
 * @return array<string,string> 갈라진 이름 => 묶을 이름.
 */
function brand_aliases(): array {
	return (array) apply_filters( 'duckhoo_brand_aliases', array( '노보 블랙' => '노보' ) );
}

/**
 * 브랜드로 가는 주소. 대괄호 없이 이름으로 찾는다 — 그래야 `[노보]` 와 `[노보 블랙]` 이
 * 함께 나온다. 검색 결과 제목도 "노보" 로 읽힌다.
 *
 * @param string $brand 브랜드.
 * @return string
 */
function brand_url( string $brand ): string {
	return add_query_arg( array( 's' => $brand, 'post_type' => 'product' ), home_url( '/' ) );
}

/**
 * 브랜드의 상품.
 *
 * @param string $brand 브랜드.
 * @param int    $limit 개수.
 * @return \WC_Product[]
 */
function brand_products( string $brand, int $limit = 4 ): array {
	return products( array( 's' => $brand, 'limit' => $limit, 'orderby' => 'popularity', 'stock_status' => 'instock' ) );
}

/**
 * 주력 브랜드. 이 가게가 미는 다섯 곳이 먼저 나오고, 그 뒤를 상품 수 순으로 채운다.
 * 이름 앞 `[브랜드]` 로 세기 때문에 "노보" 는 "노보 블랙" 까지 함께 잡힌다.
 *
 * 사장님이 미는 브랜드가 바뀌면 필터 한 줄이면 된다:
 * `add_filter( 'duckhoo_featured_brands', fn() => array( '노보', '디오리퀴드' ) );`
 *
 * @param int $limit 최대 개수.
 * @return string[] 브랜드 이름.
 */
function featured_brands( int $limit = 6 ): array {
	$want = (array) apply_filters( 'duckhoo_featured_brands', array( '노보', '디오리퀴드', '화이트아웃', '펠릭스' ) );
	$have = array_keys( brands() );
	$out  = array();
	foreach ( $want as $w ) {
		// 이름이 그대로 있으면 그것을 쓴다. "노보" 를 물었는데 "노보 블랙" 이
		// 상품 수에서 앞선다고 그쪽을 내세우면 사장님이 부른 이름과 달라진다.
		$hit = in_array( $w, $have, true ) ? $w : null;
		if ( null === $hit ) {
			foreach ( $have as $b ) {
				if ( str_starts_with( $b, $w ) ) {
					$hit = $b;
					break;
				}
			}
		}
		if ( null !== $hit && ! in_array( $hit, $out, true ) ) {
			$out[] = $hit;
		}
	}
	foreach ( $have as $b ) {
		if ( count( $out ) >= $limit ) {
			break;
		}
		if ( ! in_array( $b, $out, true ) ) {
			$out[] = $b;
		}
	}
	return array_slice( $out, 0, $limit );
}

/**
 * 병당 가격. 이름에 "5병" 이 있으면 총액을 병 수로 나눈다. 없으면 단품이다.
 *
 * @param \WC_Product $p 상품.
 * @return array{per:float,qty:int}
 */
function per_bottle( \WC_Product $p ): array {
	$price = (float) $p->get_price();
	$qty   = 1;
	$name  = $p->get_name();
	// 증정·사은품·기기 구성은 병이 구매 단위가 아니다. "기기 + 액상 5병 증정" 을 5로
	// 나누면 기기값이 액상값처럼 보인다. 이런 상품은 총액만 쓴다.
	if ( preg_match( '/증정|사은품|기기|디바이스|스타터/u', $name ) ) {
		return array( 'per' => $price, 'qty' => 1 );
	}
	if ( preg_match( '/(\d+)\s*\+\s*(\d+)/u', $name, $m ) ) {
		$qty = (int) $m[1] + (int) $m[2]; // "5+5 묶음" = 10병, "3+1" = 4병
	} elseif ( preg_match( '/(\d+)\s*병/u', $name, $m ) ) {
		$qty = max( 1, (int) $m[1] );
	}
	return array( 'per' => $qty > 1 ? $price / $qty : $price, 'qty' => $qty );
}

/**
 * 카드 왼쪽 위 아이브로. 상태가 있을 때만 붙는다 — 장식이 아니라 정보다.
 *
 * @param \WC_Product $p 상품.
 * @return array{0:string,1:string}|null
 */
function eyebrow( \WC_Product $p ): ?array {
	if ( ! $p->is_in_stock() ) {
		return array( '품절', 'out' );
	}
	if ( $p->is_on_sale() ) {
		return array( '특가', 'sale' );
	}
	$created = $p->get_date_created();
	if ( $created && ( time() - $created->getTimestamp() ) < 21 * DAY_IN_SECONDS ) {
		return array( '신상', 'new' );
	}
	$rank = cat_by_name( '랭킹' );
	if ( $rank && has_term( $rank->term_id, 'product_cat', $p->get_id() ) ) {
		return array( '랭킹', 'best' );
	}
	return null;
}

/* ────────────────────────────────────────────────────────────────
 * 조각
 * ──────────────────────────────────────────────────────────────── */

/**
 * 아이콘. 데모와 같은 SVG 다 (24px, 2px 스트로크).
 *
 * @param string $name 이름.
 * @return string
 */
function icon( string $name ): string {
	static $icons = null;
	if ( null === $icons ) {
		$icons = array(
			'home'    => '<path d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5.5v-6h-5v6H4a1 1 0 0 1-1-1z"/>',
			'grid'    => '<rect x="3" y="3" width="8" height="8" rx="2.2"/><rect x="13" y="3" width="8" height="8" rx="2.2"/><rect x="3" y="13" width="8" height="8" rx="2.2"/><rect x="13" y="13" width="8" height="8" rx="2.2"/>',
			'bag'     => '<path d="M5.5 8h13l.9 12.1a1 1 0 0 1-1 1.1H5.6a1 1 0 0 1-1-1.1z"/><path d="M9 8V6.5a3 3 0 0 1 6 0V8"/>',
			'receipt' => '<path d="M5.5 3h13v18l-2.6-1.6L13.3 21l-2.6-1.6L8.1 21l-2.6-1.6z"/><path d="M9 8.5h6M9 12.5h6"/>',
			'user'    => '<circle cx="12" cy="8" r="4"/><path d="M4.5 21a7.5 7.5 0 0 1 15 0"/>',
			'search'  => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.6-3.6"/>',
			'chev'    => '<path d="m9 5 7 7-7 7"/>',
			'check'   => '<path d="m4.5 12.5 5 5 10-11"/>',
			'heart'   => '<path d="M12 20.5s-7.5-4.6-7.5-10A4.5 4.5 0 0 1 12 8a4.5 4.5 0 0 1 7.5 2.5c0 5.4-7.5 10-7.5 10z"/>',
			'arrow'   => '<path d="M5 12h14M13 6l6 6-6 6"/>',
			'bank'    => '<path d="M3 9.5 12 4l9 5.5H3zM5 10v7M9.5 10v7M14.5 10v7M19 10v7M3 20h18"/>',
			'truck'   => '<path d="M3 6h11v10H3zM14 9h4l3 3v4h-7z"/><circle cx="7" cy="18" r="1.8"/><circle cx="17" cy="18" r="1.8"/>',
			'shield'  => '<path d="M12 3.2 19 6v5.6c0 4.8-3.3 7.7-7 8.9-3.7-1.2-7-4.1-7-8.9V6z"/>',
			'plus'    => '<path d="M12 5v14M5 12h14"/>',
			// 분류 타일 — 글자 대신
			'tag'     => '<path d="M11.5 3H4.5a1.5 1.5 0 0 0-1.5 1.5v7l9.4 9.4a1.6 1.6 0 0 0 2.2 0l6.8-6.8a1.6 1.6 0 0 0 0-2.2z"/><circle cx="7.6" cy="7.6" r="1.3"/>',
			'pod'     => '<path d="M10 2.8h4v3.4l1.6 2.2v10.8a2 2 0 0 1-2 2h-3.2a2 2 0 0 1-2-2V8.4z"/><path d="M8.4 12.4h7.2"/>',
			'cloud'   => '<path d="M7.2 18.5h9.4a3.6 3.6 0 0 0 .5-7.2 5.2 5.2 0 0 0-9.9-1.3 3.9 3.9 0 0 0 0 8.5z"/>',
			'zero'    => '<circle cx="12" cy="12" r="8.4"/><path d="m6.6 17.4 10.8-10.8"/>',
			'device'  => '<rect x="7" y="2.6" width="10" height="18.8" rx="3.2"/><path d="M10.4 6.4h3.2"/><circle cx="12" cy="16.8" r="1.4"/>',
			'drop'    => '<path d="M12 3.2c3.6 4.2 6 7.3 6 9.9a6 6 0 0 1-12 0c0-2.6 2.4-5.7 6-9.9z"/>',
			// 적립금 — 특가와 같은 가격표를 쓰면 두 타일이 같은 것으로 보인다
			'coin'    => '<circle cx="12" cy="12" r="8.6"/><path d="M10.2 16.2V7.8h2.9a2.3 2.3 0 0 1 0 4.6h-2.9"/>',
		);
	}
	return '<svg viewBox="0 0 24 24" aria-hidden="true">' . ( $icons[ $name ] ?? '' ) . '</svg>';
}

/**
 * 로고. 사이트에 올려 둔 로고 이미지가 있으면 그걸 쓰고, 없으면 글자로.
 *
 * @return string
 */
function logo_html(): string {
	$id = (int) get_theme_mod( 'custom_logo' );
	if ( $id ) {
		$img = wp_get_attachment_image( $id, 'medium', false, array( 'alt' => get_bloginfo( 'name' ), 'loading' => 'eager' ) );
		if ( $img ) {
			return $img;
		}
	}
	return '<span class="g"></span>' . esc_html( get_bloginfo( 'name' ) );
}

/**
 * 상품 카드. 데모의 .card 와 같은 구조다.
 *
 * @param \WC_Product $p 상품.
 * @return string
 */
function card( \WC_Product $p ): string {
	$n   = split_name( $p );
	$pb  = per_bottle( $p );
	$eb  = eyebrow( $p );
	$url = get_permalink( $p->get_id() );
	$img = $p->get_image( 'woocommerce_thumbnail', array( 'loading' => 'lazy' ) );
	$rating = (float) $p->get_average_rating();

	$meta = array();
	if ( $rating > 0 ) {
		$meta[] = '<span class="star">★</span><b>' . esc_html( number_format( $rating, 1 ) ) . '</b>';
	}
	if ( '' !== $n['brand'] ) {
		array_unshift( $meta, '<span class="brand">' . esc_html( $n['brand'] ) . '</span>' );
	}

	// 파는 값이 먼저다. 정가는 그 위 작은 취소선, 병당 가격은 묶음일 때만 아래 캡션.
	$price = '<b class="n">' . esc_html( number_format_i18n( (float) $p->get_price() ) ) . '<small>원</small></b>';
	$was   = ( $p->is_on_sale() && (float) $p->get_regular_price() > 0 )
		? '<s class="n">' . esc_html( number_format_i18n( (float) $p->get_regular_price() ) ) . '원</s>' : '';
	$per = $pb['qty'] > 1
		? '<div class="perb">병당 ' . esc_html( number_format_i18n( $pb['per'] ) ) . '원 · ' . (int) $pb['qty'] . '병</div>'
		: '';

	$off = '';
	if ( $p->is_on_sale() && (float) $p->get_regular_price() > (float) $p->get_price() ) {
		$off = '<em class="off">' . (int) round( ( 1 - (float) $p->get_price() / (float) $p->get_regular_price() ) * 100 ) . '%</em>';
	}

	return '<article class="card' . ( $p->is_in_stock() ? '' : ' is-out' ) . '">'
		. '<a class="fig" href="' . esc_url( $url ) . '" aria-label="' . esc_attr( $n['title'] ) . '">'
		. ( $eb ? '<span class="eb ' . esc_attr( $eb[1] ) . '">' . esc_html( $eb[0] ) . '</span>' : '' )
		. $img . '</a>'
		. '<div class="bd"><a class="nm" href="' . esc_url( $url ) . '">' . esc_html( $n['title'] ) . '</a>'
		. ( $meta ? '<div class="meta">' . implode( '<span class="dot">·</span>', $meta ) . '</div>' : '' )
		. '<div class="pr">' . $was . $off . $price . '</div>'
		. $per . '</div>'
		// 구매하기 — 이 가게의 상품은 거의 다 옵션(구성 · 맛)이 필수라 상품 페이지로 보낸다.
		// **`#dhp-buy` 같은 조각을 붙이지 않는다** — 브라우저가 그 자리로 뛰어내려서
		// 페이지가 한가운데(폰 940px · 데스크톱 611px)부터 열렸다. 맨 위에서 사진 · 가격을
		// 먼저 보여주는 것이 맞고, 폰은 그 상태에서 아래 고정 구매 줄이 이미 떠 있다.
		. ( $p->is_in_stock()
			? '<a class="buy" href="' . esc_url( $url ) . '" aria-label="' . esc_attr( $n['title'] ) . ' 구매하기">구매하기</a>'
			: '<span class="buy is-out">품절</span>' )
		. '</article>';
}

/**
 * 가로 카루셀 (Swiper). 카드 폭은 CSS 가 정하고, 스크립트가 없으면 가로 스크롤로 남는다.
 *
 * @param \WC_Product[] $items 상품.
 * @param string        $label 접근성 이름.
 * @return void
 */
function carousel( array $items, string $label ): void {
	if ( ! $items ) {
		return;
	}
	echo '<div class="dhs-wrap"><div class="swiper dhs" aria-roledescription="캐러셀" aria-label="' . esc_attr( $label ) . '"><div class="swiper-wrapper">';
	foreach ( $items as $p ) {
		echo '<div class="swiper-slide">' . card( $p ) . '</div>'; // phpcs:ignore
	}
	echo '</div></div>'
		. '<button type="button" class="dhs-nav dhs-prev" aria-label="이전">' . icon( 'chev' ) . '</button>' // phpcs:ignore
		. '<button type="button" class="dhs-nav dhs-next" aria-label="다음">' . icon( 'chev' ) . '</button></div>'; // phpcs:ignore
}

/**
 * 섹션 머리 — 작은 눈썹 · 큰 제목 · 한 줄 설명 · 더 보기.
 *
 * @param string $eyebrow 눈썹.
 * @param string $title   제목.
 * @param string $sub     설명.
 * @param string $url     더 보기 주소 ('' 이면 없음).
 * @return void
 */
function section_head( string $eyebrow, string $title, string $sub = '', string $url = '' ): void {
	echo '<div class="sh"><div>'
		. ( $eyebrow ? '<p class="sh-eb"><i></i>' . esc_html( $eyebrow ) . '</p>' : '' )
		. '<h2>' . esc_html( $title ) . '</h2>'
		. ( $sub ? '<p class="sh-sub">' . esc_html( $sub ) . '</p>' : '' ) . '</div>'
		. ( $url ? '<a class="lk" href="' . esc_url( $url ) . '">더 보기 ' . icon( 'chev' ) . '</a>' : '' ) // phpcs:ignore
		. '</div>';
}

/**
 * 상단 헤더 + 유틸 바.
 *
 * @return void
 */
function header_html(): void {
	$cart_n  = ( function_exists( 'WC' ) && WC()->cart ) ? (int) WC()->cart->get_cart_contents_count() : 0;
	$cart    = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/cart/' );
	$account = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/my-account/' );
	$shop    = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/shop/' );
	$user    = wp_get_current_user();
	$initial = $user->exists() ? mb_substr( $user->display_name ?: $user->user_login, 0, 1 ) : '';
	$nonic   = cat_by_name( '무니코틴' );
	$sale    = cat_by_name( '특가' );
	$in    = is_user_logged_in();
	$pts   = signup_points();
	// 가입하면 8,800원을 주면서 그 돈을 쓸 곳으로 가는 길이 어디에도 없었다.
	$cats  = array_values( array_filter( array( $sale, cat_by_name( '입호흡' ), cat_by_name( '폐호흡' ), $nonic, cat_by_name( '기기' ), cat_by_name( '적립금' ) ) ) );
	?>
	<?php if ( ! $in ) : ?>
	<a class="promo" href="<?php echo esc_url( home_url( '/register/' ) ); ?>" data-promo>
		<b>가입 즉시 <?php echo esc_html( number_format_i18n( $pts ) ); ?>원 적립</b><span>휴대폰 본인확인으로 1분이면 됩니다</span><?php echo icon( 'arrow' ); // phpcs:ignore ?>
	</a>
	<?php else : ?>
	<div class="promo promo--in"><b>30,000원 이상 무료배송</b><span>평일 16시 이전 입금 확인 시 당일 출고</span></div>
	<?php endif; ?>
	<div class="util"><div class="wrap">
		<a href="<?php echo esc_url( home_url( '/notice/' ) ); ?>">공지사항</a>
		<a href="<?php echo esc_url( home_url( '/tip/' ) ); ?>">Tip</a>
		<a href="<?php echo esc_url( home_url( '/inquiries/' ) ); ?>">1:1 문의</a>
		<a href="<?php echo esc_url( trailingslashit( $account ) . 'orders/' ); ?>">배송조회</a>
		<span class="r">19세 미만 판매 금지</span>
	</div></div>
	<header class="gnb"><div class="wrap gnb-in">
		<a class="lg" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo logo_html(); // phpcs:ignore ?></a>
		<form class="hs" role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
			<input type="search" name="s" placeholder="‘샤인머스캣’ 처럼 찾아보세요" aria-label="상품 검색" autocomplete="off" value="<?php echo esc_attr( get_search_query() ); ?>">
			<input type="hidden" name="post_type" value="product">
			<button type="submit" aria-label="검색"><?php echo icon( 'search' ); // phpcs:ignore ?></button>
		</form>
		<nav class="dnav">
			<a href="<?php echo esc_url( $shop ); ?>">전체 상품</a>
			<?php $mtl = cat_by_name( '입호흡' ); if ( $mtl ) : ?><a href="<?php echo esc_url( get_term_link( $mtl ) ); ?>">입호흡</a><?php endif; ?>
			<?php if ( $sale ) : ?><a href="<?php echo esc_url( get_term_link( $sale ) ); ?>"><?php echo esc_html( short_cat( $sale->name ) ); ?></a><?php endif; ?>
		</nav>
		<div class="sp">
			<a class="gi msearch-btn" href="<?php echo esc_url( add_query_arg( array( 'post_type' => 'product' ), home_url( '/' ) ) ); ?>#dhr-search" aria-label="상품 검색"><?php echo icon( 'search' ); // phpcs:ignore ?></a>
			<a class="gi wide" href="<?php echo esc_url( $cart ); ?>" aria-label="장바구니"><?php echo icon( 'bag' ); // phpcs:ignore ?><span class="lbl"><?php echo $cart_n ? esc_html( $cart_n . '개' ) : '장바구니'; ?></span><span class="b n" <?php echo $cart_n ? '' : 'style="display:none"'; ?>><?php echo (int) $cart_n; ?></span></a>
			<a class="who <?php echo $initial ? 'in' : ''; ?>" href="<?php echo esc_url( $account ); ?>" aria-label="<?php echo $initial ? '마이페이지' : '로그인'; ?>"><?php echo $initial ? esc_html( $initial ) : icon( 'user' ); // phpcs:ignore ?></a>
		</div>
	</div>
	<nav class="bnav" aria-label="바로 가기"><div class="wrap">
		<a href="<?php echo esc_url( $shop ); ?>">전체 상품</a>
		<?php foreach ( $cats as $c ) : ?><a href="<?php echo esc_url( get_term_link( $c ) ); ?>"><?php echo esc_html( short_cat( $c->name ) ); ?></a><?php endforeach; ?>
		<?php if ( ! $in ) : ?><a class="bnav__hi" href="<?php echo esc_url( home_url( '/register/' ) ); ?>">첫 가입 <?php echo esc_html( number_format_i18n( $pts ) ); ?>원</a><?php endif; ?>
	</div></nav>
	</header>
	<?php
}

/**
 * 모바일 아래 탭바.
 *
 * @return void
 */
function tabbar_html(): void {
	$cart    = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/cart/' );
	$account = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/my-account/' );
	$shop    = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/shop/' );
	$k       = is_front_page() ? 'home' : ( ( function_exists( 'is_cart' ) && is_cart() ) ? 'cart' : ( ( function_exists( 'is_account_page' ) && is_account_page() ) ? 'my' : 'shop' ) );
	$tabs    = array(
		array( 'home', home_url( '/' ), 'home', '홈' ),
		array( 'shop', $shop, 'grid', '상품' ),
		array( 'cart', $cart, 'bag', '장바구니' ),
		array( 'orders', trailingslashit( $account ) . 'orders/', 'receipt', '주문내역' ),
		array( 'my', $account, 'user', '마이' ),
	);
	echo '<nav class="tabs" aria-label="주요 메뉴">';
	foreach ( $tabs as $t ) {
		echo '<a href="' . esc_url( $t[1] ) . '" class="' . ( $t[0] === $k ? 'on' : '' ) . '">' . icon( $t[2] ) . esc_html( $t[3] ) . '</a>'; // phpcs:ignore
	}
	echo '</nav>';
}

/**
 * 분류 이름을 칩·타일에 들어갈 짧은 꼴로. "9월 특가 할인" → "이달 특가", "입호흡 액상" → "입호흡",
 * "기기 / 팟 / 코일" → "기기·팟". 달이 바뀌어도 이름을 안 고치게 "N월" 은 "이달" 로 읽는다.
 *
 * @param string $name 분류 이름.
 * @return string
 */
function short_cat( string $name ): string {
	$n = preg_replace( '/^\d+월\s*/u', '이달 ', trim( $name ) );
	// "8,800 적립금 상품" — 금액은 사이트 곳곳에 이미 있다. 칩에는 이름만 남긴다.
	$n = preg_replace( '/^[\d,]+\s*(?=적립)/u', '', $n );
	$n = preg_replace( '/\s*할인$/u', '', $n );
	$n = preg_replace( '/^액상\s+/u', '', $n );
	if ( ! preg_match( '/^액상$/u', $n ) ) {
		$n = preg_replace( '/\s+액상$/u', '', $n );
	}
	$parts = preg_split( '/\s*\/\s*/u', $n );
	return implode( '·', array_slice( $parts, 0, 2 ) );
}

/**
 * 무통장입금 계좌. 워드커머스 BACS 설정에서 읽는다 — 주문서에 찍히는 것과 같은 값이다.
 *
 * @return array<int,array{bank:string,number:string,name:string}>
 */
function bank_accounts(): array {
	$out = array();
	foreach ( (array) get_option( 'woocommerce_bacs_accounts', array() ) as $a ) {
		$num = trim( (string) ( $a['account_number'] ?? '' ) );
		if ( '' === $num ) {
			continue;
		}
		$out[] = array(
			'bank'   => trim( (string) ( $a['bank_name'] ?? '' ) ),
			'number' => $num,
			'name'   => trim( (string) ( $a['account_name'] ?? '' ) ),
		);
	}
	return $out;
}

/**
 * 가입 적립금. 사이트가 8,800원을 준다 — 테마 안내 띠와 주문서 안내가 같은 숫자를 쓴다.
 *
 * @return int
 */
function signup_points(): int {
	return (int) apply_filters( 'duckhoo_signup_points', 8800 );
}

/**
 * 카카오톡 문의 주소. 오픈채팅 링크가 생기면 이 필터 한 줄로 바꾼다.
 *
 *     add_filter( 'duckhoo_kakao_url', fn() => 'https://open.kakao.com/o/XXXXXXX' );
 *
 * @return string
 */
function kakao_url(): string {
	return (string) apply_filters( 'duckhoo_kakao_url', home_url( '/inquiries/' ) );
}

/**
 * 푸터. 모든 화면에 붙는다.
 *
 * @return void
 */
function footer_html(): void {
	$account = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/my-account/' );
	// 헤더 칩과 같은 순서 — 특가 · 입호흡 · 폐호흡 · 무니코틴 · 기기.
	$cats    = array_values( array_filter( array(
		cat_by_name( '특가' ), cat_by_name( '입호흡' ), cat_by_name( '폐호흡' ), cat_by_name( '무니코틴' ), cat_by_name( '기기' ), cat_by_name( '적립금' ), cat_by_name( '랭킹' ),
	) ) );
	$brands  = featured_brands( 6 );
	?>
	<footer class="foot"><div class="wrap">
		<div class="fgrid">
			<div class="fabout"><a class="lg lg-w" href="<?php echo esc_url( home_url( '/' ) ); ?>"><span class="g"></span>액상덕후</a>
				<p>전자담배 액상 전문몰. 카드결제 없이 계좌이체로만 받고, 입금자명이 주문자명과 같으면 자동으로 확인됩니다.</p>
				<div class="fkakao"><div><b>카카오톡 문의</b><span>입금 확인 · 배송 · 교환은 여기로</span></div><a class="btn btn-p btn-sm" href="<?php echo esc_url( kakao_url() ); ?>"<?php echo kakao_url() === home_url( '/inquiries/' ) ? '' : ' target="_blank" rel="noopener"'; ?>>문의하기</a></div></div>
			<?php
			// 폰에서 푸터가 화면 두 개를 넘었다. 링크 묶음은 접어 두고 제목만 보인다
			// (front.js 가 여닫는다). 데스크톱은 CSS 가 늘 펼쳐 둔다.
			?>
			<div class="fcol" data-fcol><button type="button" class="fcol__t" aria-expanded="false"><b>상품</b><?php echo icon( 'chev' ); // phpcs:ignore ?></button>
				<div class="fcol__list"><?php foreach ( $cats as $c ) : ?><a href="<?php echo esc_url( get_term_link( $c ) ); ?>"><?php echo esc_html( short_cat( $c->name ) ); ?></a><?php endforeach; ?></div></div>
			<div class="fcol" data-fcol><button type="button" class="fcol__t" aria-expanded="false"><b>브랜드</b><?php echo icon( 'chev' ); // phpcs:ignore ?></button>
				<div class="fcol__list"><?php foreach ( $brands as $b ) : ?><a href="<?php echo esc_url( brand_url( $b ) ); ?>"><?php echo esc_html( $b ); ?></a><?php endforeach; ?></div></div>
			<div class="fcol" data-fcol><button type="button" class="fcol__t" aria-expanded="false"><b>안내</b><?php echo icon( 'chev' ); // phpcs:ignore ?></button>
				<div class="fcol__list">
					<a href="<?php echo esc_url( home_url( '/shipping/' ) ); ?>">배송 · 교환 · 환불</a>
					<a href="<?php echo esc_url( home_url( '/register/' ) ); ?>">회원가입</a>
					<a href="<?php echo esc_url( trailingslashit( $account ) . 'orders/' ); ?>">주문조회</a>
					<a href="<?php echo esc_url( home_url( '/membership-cancel/' ) ); ?>">회원탈퇴</a></div></div>
			<?php // 고객센터는 접지 않는다 — 전화번호와 영업시간은 찾으러 온 사람이 바로 봐야 한다 ?>
			<div class="fcol fcol--open"><b>고객센터</b>
				<a href="tel:010-5133-5852" class="n">010-5133-5852</a>
				<span class="fmuted">평일 10:00–19:00 · 점심 12:00–13:00<br>주말 · 법정 공휴일 휴무</span>
				<?php // 알약 링크만 가로로 흐른다. 전화번호 · 시간은 위에 한 줄씩 — 줄바꿈이 잘리지 않게 한다 ?>
				<div class="fcol__pills">
					<a href="<?php echo esc_url( home_url( '/inquiries/' ) ); ?>">1:1 문의</a>
					<a href="<?php echo esc_url( home_url( '/notice/' ) ); ?>">공지사항</a>
					<a href="https://service.epost.go.kr/trace.RetrieveDomRigiTraceList.comm" target="_blank" rel="noopener">우체국택배 조회</a></div></div>
		</div>
		<?php $banks = bank_accounts(); if ( $banks ) : ?>
		<?php // 이 가게는 계좌이체만 받는다. 계좌 줄은 손님이 옮겨 적는 줄이라 푸터에서 가장 잘 보여야 한다 ?>
		<div class="fbank">
			<p class="fbank__t"><?php echo icon( 'bank' ); // phpcs:ignore ?><b>입금 계좌</b></p>
			<?php foreach ( $banks as $b ) : ?>
			<div class="fbank__row">
				<div class="fbank__acc">
					<span class="n"><?php echo esc_html( $b['number'] ); ?></span>
					<span class="fbank__meta"><?php echo esc_html( $b['bank'] ); ?><?php echo $b['name'] ? ' · 예금주 ' . esc_html( $b['name'] ) : ''; ?></span>
				</div>
				<?php // 열한 자리를 손으로 옮겨 적다 틀리면 입금이 안 맞는다 ?>
				<button type="button" class="fbank__copy" data-copy="<?php echo esc_attr( $b['number'] ); ?>">복사</button>
			</div>
			<?php endforeach; ?>
			<p class="fbank__note">입금자명은 <b>주문자명과 똑같이</b> 넣어 주세요. 같으면 자동으로 확인됩니다.</p>
		</div>
		<?php endif; ?>
		<div class="flegal"><b>19세 미만 청소년에게 판매하지 않습니다.</b> 구매 시 휴대폰 본인확인이 필요합니다 · 니코틴은 중독성이 있는 물질입니다<br>
			상호 투더문 · 대표 백시문 · 대구광역시 중구 경상감영길 21, 3층(동문동) · 사업자등록번호 642-08-02808 · 통신판매업 신고 제 2025-대구중구-0487 호<br>
			<a href="<?php echo esc_url( home_url( '/terms/' ) ); ?>">이용약관</a> · <a href="<?php echo esc_url( home_url( '/privacy/' ) ); ?>">개인정보처리방침</a> · © 액상덕후</div>
	</div></footer>
	<?php cart_drawer_html(); ?>
	<?php
}

/**
 * 장바구니 서랍. 담기 직후와 헤더 장바구니 버튼에서 열린다. 내용은 Store API 로 채운다.
 * 결제로 가는 길을 한 번 더 보여 주는 게 목적이다 — 장바구니 페이지로 보내 흐름을 끊지 않는다.
 *
 * @return void
 */
function cart_drawer_html(): void {
	$cart     = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/cart/' );
	$checkout = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/checkout/' );
	?>
	<div class="dhc" data-cart-drawer hidden>
		<div class="dhc__dim" data-cart-close></div>
		<aside class="dhc__panel" role="dialog" aria-modal="true" aria-labelledby="dhc-title">
			<header class="dhc__head"><h2 id="dhc-title">장바구니 <span class="n" data-cart-count></span></h2>
				<button type="button" class="dhc__x" data-cart-close aria-label="닫기">×</button></header>
			<div class="dhc__ship" data-cart-ship hidden><span data-cart-ship-text></span><span class="dhc__bar"><i data-cart-ship-fill></i></span></div>
			<div class="dhc__list" data-cart-list><p class="dhc__empty">담긴 상품이 없습니다.</p></div>
			<footer class="dhc__foot">
				<div class="dhc__tot"><span>상품 금액</span><b class="n" data-cart-total>0원</b></div>
				<div class="dhc__btns">
					<a class="btn btn-o" href="<?php echo esc_url( $cart ); ?>">장바구니 보기</a>
					<a class="btn btn-d" href="<?php echo esc_url( $checkout ); ?>" data-cart-checkout>결제하기 <?php echo icon( 'arrow' ); // phpcs:ignore ?></a>
				</div>
				<p class="dhc__note">무통장입금 전용 · 입금자명을 주문자명과 똑같이</p>
			</footer>
		</aside>
	</div>
	<?php
}

/* ────────────────────────────────────────────────────────────────
 * 연결
 * ──────────────────────────────────────────────────────────────── */

/**
 * 첫 화면은 우리 템플릿으로.
 *
 * @param string $template 원래 템플릿.
 * @return string
 */
function take_front_page( string $template ): string {
	if ( is_admin() || ! function_exists( 'wc_get_products' ) ) {
		return $template;
	}
	if ( is_front_page() && apply_filters( 'duckhoo_take_front_page', true ) ) {
		return DIR . 'templates/home.php';
	}
	// 회원탈퇴 페이지: 테마의 마이페이지 템플릿이 자기 탈퇴 상자를 그리고 the_content 를
	// 부르지 않는다. 그 상자의 admin-ajax 처리가 동작하지 않아 고객이 탈퇴를 못 했다.
	// 이 페이지도 우리 껍데기로 그리고 includes/membership-cancel.php 의 폼을 넣는다.
	if ( is_page( 'membership-cancel' ) && apply_filters( 'duckhoo_take_membership_cancel', true ) ) {
		return DIR . 'templates/page-membership-cancel.php';
	}
	// 상품 상세 · 목록. 테마 템플릿은 조회수 · 타이머 · 게이지 · 추천을 구매 상자 하나에
	// 쌓고, 목록은 제목 없이 카드만 깐다. 우리 화면으로 그린다. 구매 폼과 쿼리는 그대로다.
	if ( function_exists( 'is_product' ) && is_product() && apply_filters( 'duckhoo_take_product', true ) ) {
		return DIR . 'templates/single-product.php';
	}
	if ( function_exists( 'is_shop' ) && ( is_shop() || is_product_taxonomy() || ( is_search() && 'product' === get_query_var( 'post_type' ) ) )
		&& apply_filters( 'duckhoo_take_archive', true ) ) {
		return DIR . 'templates/archive-product.php';
	}
	return $template;
}

/**
 * 우리 껍데기를 쓰는 화면인가.
 *
 * @return bool
 */
function is_shell_page(): bool {
	return ! is_admin() && ( is_front_page() || is_page( 'membership-cancel' ) );
}
add_filter( 'template_include', __NAMESPACE__ . '\\take_front_page', 99 );

/**
 * 스타일·스크립트. 홈에서는 테마 스타일을 빼고 우리 것만 싣는다 — 테마의 .dh-* 인라인
 * 규칙은 테마 템플릿이 찍는 것이라 우리 템플릿에서는 애초에 안 나온다.
 *
 * @return void
 */
function assets(): void {
	if ( ! is_shell_page() ) {
		return;
	}
	wp_dequeue_style( 'welcome-drink-style' );
	$css = DIR . 'assets/front.css';
	$js  = DIR . 'assets/front.js';
	wp_enqueue_style( 'duckhoo-front', plugins_url( 'assets/front.css', DIR . 'duckhoo-redesign.php' ), array( 'duckhoo-tokens' ), (string) filemtime( $css ) );
	// 회원탈퇴 화면은 자기 스타일시트가 따로 있다 — 안 실으면 버튼이 맨 글자로 나온다.
	if ( is_page( 'membership-cancel' ) ) {
		$leave = DIR . 'assets/membership-cancel.css';
		if ( file_exists( $leave ) ) {
			wp_enqueue_style( 'duckhoo-leave', plugins_url( 'assets/membership-cancel.css', DIR . 'duckhoo-redesign.php' ), array( 'duckhoo-front' ), (string) filemtime( $leave ) );
		}
	}
	libs();
	wp_enqueue_script( 'duckhoo-front', plugins_url( 'assets/front.js', DIR . 'duckhoo-redesign.php' ), array( 'duckhoo-swiper', 'duckhoo-gsap-st' ), (string) filemtime( $js ), true );
	// 이번 달 마지막 날 23:59:59 (사이트 시간대) — 특가 마감 카운트다운
	$end = new \DateTime( 'last day of this month 23:59:59', wp_timezone() );
	wp_add_inline_script( 'duckhoo-front', js_config( array( 'saleEnd' => $end->getTimestamp() * 1000 ) ), 'before' );
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\assets', 100 );

/**
 * 카루셀(Swiper 11) · 등장(GSAP 3 + ScrollTrigger) — cdnjs. front.js 가 있으면 쓰고 없으면 그냥 지나간다.
 * 필터 `duckhoo_front_libs` 로 끌 수 있다 (빈 배열).
 *
 * @return void
 */
function libs(): void {
	$libs = apply_filters( 'duckhoo_front_libs', array(
		'duckhoo-swiper'  => array( 'https://cdnjs.cloudflare.com/ajax/libs/Swiper/11.2.10/swiper-bundle.min.js', array() ),
		'duckhoo-gsap'    => array( 'https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js', array() ),
		'duckhoo-gsap-st' => array( 'https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/ScrollTrigger.min.js', array( 'duckhoo-gsap' ) ),
	) );
	foreach ( (array) $libs as $handle => $l ) {
		wp_enqueue_script( (string) $handle, (string) $l[0], (array) ( $l[1] ?? array() ), null, true ); // phpcs:ignore
	}
}

/**
 * front.js 에 넘기는 값. 홈과 껍데기 화면이 같은 것을 쓴다.
 * Store API 논스는 장바구니 서랍의 지우기에 쓴다 — 응답 헤더의 Nonce 가 오면 그것이 앞선다.
 *
 * @param array<string,mixed> $extra 화면별 추가 값.
 * @return string `window.DHR = {...}` 한 줄.
 */
function js_config( array $extra = array() ): string {
	$cfg = array(
		// 금액대별 자동 할인 — 장바구니 안내 문구가 이 값을 읽는다. 실제 할인은 쿠폰
		// 플러그인이 서버에서 적용한다. 규칙이 바뀌면 이 필터 한 줄이면 된다:
		// add_filter( 'duckhoo_auto_discount', fn() => array( array( 'min' => 100000, 'amount' => 10000 ) ) );
		'discount' => array_values( (array) apply_filters( 'duckhoo_auto_discount', array( array( 'min' => 100000, 'amount' => 10000 ) ) ) ),
		// 사장님 홈 팝업(#pop6)에서 남길 탭 하나. 나머지 칩은 감춘다.
		// 끄려면 빈 문자열: add_filter( 'duckhoo_popup_tab', '__return_empty_string' );
		'popupTab' => (string) apply_filters( 'duckhoo_popup_tab', '고객 안내' ),
		'loggedIn' => is_user_logged_in(),
		'nonce'    => wp_create_nonce( 'wc_store_api' ),
		'freeShip' => (int) apply_filters( 'duckhoo_free_shipping_min', 30000 ),
		'cartUrl'  => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/cart/' ),
		'shopUrl'  => function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/shop/' ),
	);
	return 'window.DHR=' . wp_json_encode( array_merge( $cfg, $extra ) ) . ';';
}

/**
 * 상품이 바뀌면 캐시를 비운다.
 *
 * @return void
 */
function flush_cache(): void {
	global $wpdb;
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_dhr_%' OR option_name LIKE '_transient_timeout_dhr_%'" ); // phpcs:ignore
}
add_action( 'woocommerce_update_product', __NAMESPACE__ . '\\flush_cache' );
add_action( 'woocommerce_new_product', __NAMESPACE__ . '\\flush_cache' );
