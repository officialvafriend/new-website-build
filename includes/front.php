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
		$out[ $b ] = ( $out[ $b ] ?? 0 ) + 1;
	}
	arsort( $out );
	set_transient( 'dhr_brands', $out, 10 * MINUTE_IN_SECONDS );
	return $out;
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
	if ( preg_match( '/(\d+)\s*병/u', $p->get_name(), $m ) ) {
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
		);
	}
	return '<svg viewBox="0 0 24 24" aria-hidden="true">' . ( $icons[ $name ] ?? '' ) . '</svg>';
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
	$sold = (int) $p->get_total_sales();
	$rating = (float) $p->get_average_rating();

	$meta = array();
	if ( $rating > 0 ) {
		$meta[] = '<span class="star">★</span><b>' . esc_html( number_format( $rating, 1 ) ) . '</b>';
	}
	if ( $sold > 0 ) {
		$meta[] = '<span>' . esc_html( number_format_i18n( $sold ) ) . '개 판매</span>';
	}
	if ( '' !== $n['brand'] ) {
		array_unshift( $meta, '<span class="brand">' . esc_html( $n['brand'] ) . '</span>' );
	}

	$price = '<b>' . esc_html( number_format_i18n( $pb['per'] ) ) . '</b>';
	if ( $p->is_on_sale() && $pb['qty'] > 1 ) {
		$price = '<s>' . esc_html( number_format_i18n( (float) $p->get_regular_price() / $pb['qty'] ) ) . '</s>' . $price;
	} elseif ( $p->is_on_sale() ) {
		$price = '<s>' . esc_html( number_format_i18n( (float) $p->get_regular_price() ) ) . '</s>' . $price;
	}
	$unit = $pb['qty'] > 1 ? '원 · 병당 (' . $pb['qty'] . '병)' : '원';

	return '<article class="card' . ( $p->is_in_stock() ? '' : ' is-out' ) . '">'
		. '<a class="fig" href="' . esc_url( $url ) . '" aria-label="' . esc_attr( $n['title'] ) . '">'
		. ( $eb ? '<span class="eb ' . esc_attr( $eb[1] ) . '">' . esc_html( $eb[0] ) . '</span>' : '' )
		. $img . '</a>'
		. '<button type="button" class="wish" data-wish="' . (int) $p->get_id() . '" aria-pressed="false" aria-label="' . esc_attr( $n['title'] ) . ' 찜">' . icon( 'heart' ) . '</button>'
		. '<div class="bd"><a class="nm" href="' . esc_url( $url ) . '">' . esc_html( $n['title'] ) . '</a>'
		. ( $meta ? '<div class="meta">' . implode( '<span class="dot">·</span>', $meta ) . '</div>' : '' )
		. '<div class="pr"><span class="n">' . $price . '</span><small>' . esc_html( $unit ) . '</small></div></div></article>';
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
	?>
	<div class="util"><div class="wrap">
		<a href="<?php echo esc_url( home_url( '/notice/' ) ); ?>">공지사항</a>
		<a href="<?php echo esc_url( home_url( '/tip/' ) ); ?>">Tip</a>
		<a href="<?php echo esc_url( home_url( '/inquiries/' ) ); ?>">1:1 문의</a>
		<a href="<?php echo esc_url( trailingslashit( $account ) . 'orders/' ); ?>">배송조회</a>
		<span class="r">만 19세 이상 · 휴대폰 본인확인 후 구매</span>
	</div></div>
	<header class="gnb"><div class="wrap gnb-in">
		<a class="lg" href="<?php echo esc_url( home_url( '/' ) ); ?>"><span class="g"></span>액상덕후</a>
		<form class="hs" role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
			<input type="search" name="s" placeholder="‘샤인머스캣’ 처럼 찾아보세요" aria-label="상품 검색" autocomplete="off" value="<?php echo esc_attr( get_search_query() ); ?>">
			<input type="hidden" name="post_type" value="product">
			<button type="submit" aria-label="검색"><?php echo icon( 'search' ); // phpcs:ignore ?></button>
		</form>
		<nav class="dnav">
			<a href="<?php echo esc_url( $shop ); ?>">전체 상품</a>
			<?php if ( $nonic ) : ?><a href="<?php echo esc_url( get_term_link( $nonic ) ); ?>">무니코틴</a><?php endif; ?>
			<?php if ( $sale ) : ?><a href="<?php echo esc_url( get_term_link( $sale ) ); ?>"><?php echo esc_html( $sale->name ); ?></a><?php endif; ?>
		</nav>
		<div class="sp">
			<a class="gi msearch-btn" href="<?php echo esc_url( add_query_arg( array( 'post_type' => 'product' ), home_url( '/' ) ) ); ?>#dhr-search" aria-label="상품 검색"><?php echo icon( 'search' ); // phpcs:ignore ?></a>
			<a class="gi wide" href="<?php echo esc_url( $cart ); ?>" aria-label="장바구니"><?php echo icon( 'bag' ); // phpcs:ignore ?><span class="lbl"><?php echo $cart_n ? esc_html( $cart_n . '개' ) : '장바구니'; ?></span><span class="b n" <?php echo $cart_n ? '' : 'style="display:none"'; ?>><?php echo (int) $cart_n; ?></span></a>
			<a class="who <?php echo $initial ? 'in' : ''; ?>" href="<?php echo esc_url( $account ); ?>" aria-label="<?php echo $initial ? '마이페이지' : '로그인'; ?>"><?php echo $initial ? esc_html( $initial ) : icon( 'user' ); // phpcs:ignore ?></a>
		</div>
	</div></header>
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
 * 푸터. 모든 화면에 붙는다.
 *
 * @return void
 */
function footer_html(): void {
	$account = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/my-account/' );
	$cats    = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true, 'orderby' => 'count', 'order' => 'DESC', 'number' => 6 ) );
	$cats    = is_wp_error( $cats ) ? array() : $cats;
	$brands  = array_slice( array_keys( brands() ), 0, 6 );
	?>
	<footer class="foot"><div class="wrap">
		<div class="fgrid">
			<div class="fabout"><a class="lg lg-w" href="<?php echo esc_url( home_url( '/' ) ); ?>"><span class="g"></span>액상덕후</a>
				<p>전자담배 액상 전문몰. 카드결제 없이 계좌이체로만 받고, 입금자명이 주문자명과 같으면 자동으로 확인됩니다.</p>
				<div class="fkakao"><div><b>카카오톡 문의</b><span>입금 확인 · 배송 · 교환은 여기로</span></div><a class="btn btn-p btn-sm" href="<?php echo esc_url( home_url( '/inquiries/' ) ); ?>">문의하기</a></div></div>
			<div class="fcol"><b>상품</b><?php foreach ( $cats as $c ) : ?><a href="<?php echo esc_url( get_term_link( $c ) ); ?>"><?php echo esc_html( $c->name ); ?></a><?php endforeach; ?></div>
			<div class="fcol"><b>브랜드</b><?php foreach ( $brands as $b ) : ?><a href="<?php echo esc_url( add_query_arg( array( 's' => '[' . $b . ']', 'post_type' => 'product' ), home_url( '/' ) ) ); ?>"><?php echo esc_html( $b ); ?></a><?php endforeach; ?></div>
			<div class="fcol"><b>안내</b>
				<a href="<?php echo esc_url( home_url( '/tip/' ) ); ?>">배송 · 교환 · 환불</a>
				<a href="<?php echo esc_url( home_url( '/event/' ) ); ?>">이벤트</a>
				<a href="<?php echo esc_url( home_url( '/register/' ) ); ?>">회원가입</a>
				<a href="<?php echo esc_url( trailingslashit( $account ) . 'orders/' ); ?>">주문조회</a>
				<a href="<?php echo esc_url( home_url( '/membership-cancel/' ) ); ?>">회원탈퇴</a></div>
			<div class="fcol"><b>고객센터</b>
				<a href="tel:010-5133-5852" class="n">010-5133-5852</a>
				<span class="fmuted">평일 10:00–19:00 · 점심 12:00–13:00<br>주말 · 법정 공휴일 휴무</span>
				<a href="<?php echo esc_url( home_url( '/inquiries/' ) ); ?>">1:1 문의</a>
				<a href="<?php echo esc_url( home_url( '/notice/' ) ); ?>">공지사항</a>
				<a href="https://service.epost.go.kr/trace.RetrieveDomRigiTraceList.comm" target="_blank" rel="noopener">우체국택배 조회 (1588-1300)</a></div>
		</div>
		<div class="flegal"><b>19세 미만 청소년에게 판매하지 않습니다.</b> 구매 시 휴대폰 본인확인이 필요합니다 · 니코틴은 중독성이 있는 물질입니다<br>
			상호 투더문 · 대표 백시문 · 대구광역시 중구 경상감영길 21, 3층(동문동) · 사업자등록번호 642-08-02808 · 통신판매업 신고 제 2025-대구중구-0487 호<br>
			<a href="<?php echo esc_url( home_url( '/terms/' ) ); ?>">이용약관</a> · <a href="<?php echo esc_url( home_url( '/privacy/' ) ); ?>">개인정보처리방침</a> · © 액상덕후</div>
	</div></footer>
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
	if ( is_front_page() && ! is_admin() && function_exists( 'wc_get_products' ) ) {
		if ( apply_filters( 'duckhoo_take_front_page', true ) ) {
			return DIR . 'templates/home.php';
		}
	}
	return $template;
}
add_filter( 'template_include', __NAMESPACE__ . '\\take_front_page', 99 );

/**
 * 스타일·스크립트. 홈에서는 테마 스타일을 빼고 우리 것만 싣는다 — 테마의 .dh-* 인라인
 * 규칙은 테마 템플릿이 찍는 것이라 우리 템플릿에서는 애초에 안 나온다.
 *
 * @return void
 */
function assets(): void {
	if ( ! is_front_page() || is_admin() ) {
		return;
	}
	wp_dequeue_style( 'welcome-drink-style' );
	$css = DIR . 'assets/front.css';
	$js  = DIR . 'assets/front.js';
	wp_enqueue_style( 'duckhoo-front', plugins_url( 'assets/front.css', DIR . 'duckhoo-redesign.php' ), array( 'duckhoo-tokens' ), (string) filemtime( $css ) );
	wp_enqueue_script( 'duckhoo-front', plugins_url( 'assets/front.js', DIR . 'duckhoo-redesign.php' ), array(), (string) filemtime( $js ), true );
	// 이번 달 마지막 날 23:59:59 (사이트 시간대) — 특가 마감 카운트다운
	$end = new \DateTime( 'last day of this month 23:59:59', wp_timezone() );
	wp_add_inline_script( 'duckhoo-front', 'window.DHR={saleEnd:' . $end->getTimestamp() . '000};', 'before' );
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\assets', 100 );

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
